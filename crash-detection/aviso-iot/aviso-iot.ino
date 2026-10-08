// ============================================================
// AVISO IoT — field firmware (ESP32 + GY-BNO055 + passive buzzer)
// ------------------------------------------------------------
// Two FreeRTOS tasks:
//   - sensor task  (core 1, high priority): reads the BNO055 at
//     100 Hz, classifies normal / hard braking / bump / crash,
//     keeps the black box and drives the buzzer. Never waits on
//     the network.
//   - network task (core 0): phone-hotspot Wi-Fi, setup page,
//     WebSocket link to the rider app, heartbeats and the backup
//     crash report to the server.
// See README.md for wiring, libraries, setup and bench tests.
// ============================================================
#include <Arduino.h>
#include <Preferences.h>
#include <esp_task_wdt.h>
#include <esp_system.h>
#include "config.h"
#include "shared.h"
#include "imu.h"
#include "detector.h"
#include "blackbox.h"
#include "buzzer.h"
#include "net.h"

// ---- Shared state (declared in shared.h) ----
QueueHandle_t eventQueue;
portMUX_TYPE telemetryMux = portMUX_INITIALIZER_UNLOCKED;
Telemetry latestTelemetry = {};
volatile bool requestCalibrateUpright = false;
volatile bool requestBeep = false;
volatile bool requestAlarmStop = false;
volatile bool rideActive = false;
volatile uint8_t calSys = 0, calGyro = 0, calAccel = 0;
String deviceUid;
uint32_t bootCount = 0;
String resetReasonLabel = "unknown";

const char* motionStateName(MotionState s) {
  switch (s) {
    case STATE_BUMP:         return "bump";
    case STATE_HARD_BRAKING: return "hard_braking";
    case STATE_IMPACT:       return "impact";
    case STATE_FALLEN:       return "fallen";
    case STATE_CRASH:        return "crash";
    default:                 return "normal";
  }
}

const char* eventTypeName(EventType t) {
  switch (t) {
    case EVENT_NORMAL:        return "normal";
    case EVENT_BUMP:          return "road_bump";
    case EVENT_HARD_BRAKING:  return "hard_braking";
    case EVENT_CRASH:         return "crash";
    case EVENT_CRASH_CLEARED: return "crash_cleared";
  }
  return "unknown";
}

static String readResetReason() {
  switch (esp_reset_reason()) {
    case ESP_RST_POWERON:  return "poweron";
    case ESP_RST_BROWNOUT: return "BROWNOUT";   // check the buck converter / wiring
    case ESP_RST_PANIC:    return "PANIC";
    case ESP_RST_TASK_WDT: return "WDT_TIMEOUT";
    case ESP_RST_SW:       return "software";
    default:               return "other_" + String((int)esp_reset_reason());
  }
}

// ------------------------------------------------------------ sensor task
static void sensorTask(void*) {
  esp_task_wdt_add(NULL);

  bool imuReady = Imu::begin();
  unsigned long lastImuRetry = millis();
  Buzzer::play(imuReady ? PATTERN_READY : PATTERN_ERROR);

  Detector detector;
  ImuSample s;
  DetectorEvent ev;
  int failedReads = 0;
  unsigned long lastCalCheck = 0;
  TickType_t lastWake = xTaskGetTickCount();

  for (;;) {
    vTaskDelayUntil(&lastWake, pdMS_TO_TICKS(SAMPLE_INTERVAL_MS));
    esp_task_wdt_reset();
    const unsigned long now = millis();

    if (requestAlarmStop) {
      requestAlarmStop = false;
      if (Buzzer::alarmActive()) Buzzer::stop();
    }
    if (requestBeep) {
      requestBeep = false;
      Buzzer::play(PATTERN_READY);
    }

    if (!imuReady) {
      // Keep retrying: a cold boot from the buck converter can delay the sensor.
      if (now - lastImuRetry >= 5000) {
        lastImuRetry = now;
        imuReady = Imu::begin();
        Buzzer::play(imuReady ? PATTERN_READY : PATTERN_ERROR);
      }
      Buzzer::update(now);
      continue;
    }

    if (requestCalibrateUpright) {
      Imu::calibrateUpright();           // ~1 s, bike still on the center stand
      requestCalibrateUpright = false;
      Buzzer::play(PATTERN_OK);
      lastWake = xTaskGetTickCount();
      continue;
    }

    if (now - lastCalCheck >= 1000) {
      lastCalCheck = now;
      uint8_t sys, gyro, accel;
      Imu::calibrationStatus(sys, gyro, accel);
      calSys = sys; calGyro = gyro; calAccel = accel;
    }

    if (Imu::read(s)) {
      failedReads = 0;
      BlackBox::record(s, now);

      if (detector.update(s, now, ev)) {
        if (ev.type == EVENT_CRASH) {
          Buzzer::play(PATTERN_ALARM);
          BlackBox::startCapture(ev.uid);
        }
        xQueueSend(eventQueue, &ev, 0);
      }

      Telemetry t = { s.linearG, s.verticalG, s.horizontalG, s.gyroDps, s.yawRateDps, s.tiltDeg, detector.state() };
      portENTER_CRITICAL(&telemetryMux);
      latestTelemetry = t;
      portEXIT_CRITICAL(&telemetryMux);
    } else if (++failedReads == 100) {
      Buzzer::play(PATTERN_ERROR);       // 1 s of failed reads: check the I2C wiring
    }

    Buzzer::update(now);
  }
}

// ------------------------------------------------------------ network task
static void networkTask(void*) {
  esp_task_wdt_add(NULL);
  Net::begin();
  for (;;) {
    esp_task_wdt_reset();
    Net::loop(millis());
    vTaskDelay(pdMS_TO_TICKS(2));
  }
}

void setup() {
  Serial.begin(115200);
  delay(300);   // no "while (!Serial)": must run with no laptop attached

  resetReasonLabel = readResetReason();

  uint64_t mac = ESP.getEfuseMac();
  char uid[16];
  snprintf(uid, sizeof(uid), "AVISO-%06X", (uint32_t)((mac >> 24) & 0xFFFFFF));
  deviceUid = uid;

  Preferences prefs;
  prefs.begin("aviso", false);
  bootCount = prefs.getUInt("boots", 0) + 1;   // part of every event id
  prefs.putUInt("boots", bootCount);
  prefs.end();

  esp_task_wdt_config_t wdt = { .timeout_ms = WDT_TIMEOUT_SEC * 1000, .idle_core_mask = 0, .trigger_panic = true };
  // The Arduino core usually starts the watchdog already: adjust it, else start it.
  if (esp_task_wdt_reconfigure(&wdt) != ESP_OK) esp_task_wdt_init(&wdt);

  Buzzer::begin();
  BlackBox::begin();
  eventQueue = xQueueCreate(16, sizeof(DetectorEvent));

  Serial.printf("[AVISO] %s fw %s boot #%lu reset=%s\n", deviceUid.c_str(), FIRMWARE_VERSION,
                (unsigned long)bootCount, resetReasonLabel.c_str());

  xTaskCreatePinnedToCore(sensorTask, "sensor", 8192, nullptr, 5, nullptr, 1);
  xTaskCreatePinnedToCore(networkTask, "network", 12288, nullptr, 2, nullptr, 0);
}

void loop() {
  // All work happens in the two tasks above (each is watched by the watchdog).
  vTaskDelay(pdMS_TO_TICKS(1000));
}
