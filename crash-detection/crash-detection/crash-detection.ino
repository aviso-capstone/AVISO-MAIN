// ============================================================
// AVISO — Early Field Logger  (SUPERSEDED, kept for reference)
// ------------------------------------------------------------
// Replaced by aviso-iot (field firmware) for riding and by
// threshold-gathering for data collection. Not used any more.
//
// What it did: logged the BNO055 continuously with calibration
// loaded from EEPROM, and sounded a local buzzer / black-box
// safety net on extreme motion, independent of the Wi-Fi link.
// ============================================================

#include <Wire.h>
#include <Adafruit_Sensor.h>
#include <Adafruit_BNO055.h>
#include <utility/imumaths.h>
#include <LittleFS.h>
#include <EEPROM.h>
#include <esp_task_wdt.h>
#include <esp_system.h>

Adafruit_BNO055 bno = Adafruit_BNO055(55, 0x29);

#define LED_PIN 2
#define BUZZER_PIN 4
#define EEPROM_SIZE 512
#define CALIBRATION_FLAG_ADDR 0
#define CALIBRATION_DATA_ADDR 1
#define WDT_TIMEOUT_SEC 8
#define BUFFER_SIZE 50
#define LOW_STORAGE_WARNING_BYTES 20000
#define STORAGE_WRITE_HALT_BYTES 5000
#define INIT_RETRY_ATTEMPTS 3
#define INIT_RETRY_DELAY_MS 500

// Local safety-net trigger only (placeholder values, never
// calibrated). The crash decision now lives in aviso-iot.
const float LOCAL_SAFETY_ACCEL_THRESHOLD = 25.0;
const float LOCAL_SAFETY_ORIENT_THRESHOLD = 45.0;
const int CONSECUTIVE_READINGS_REQUIRED = 4;
int consecutiveExtremeReadings = 0;

const char* LOG_FILE = "/deployment_log.csv";
unsigned long readingCount = 0;
int zeroReadingStreak = 0;
const int ZERO_STREAK_FAILURE_LIMIT = 10;

struct Reading { unsigned long timestamp; float ax, ay, az, accelMag, ox, oy, oz; };
Reading blackBox[BUFFER_SIZE];
int bufferIndex = 0;

String resetReasonLabel = "unknown";

void ledBlink(int times, int delayMs) {
  for (int i = 0; i < times; i++) {
    digitalWrite(LED_PIN, HIGH); delay(delayMs);
    digitalWrite(LED_PIN, LOW); delay(delayMs);
  }
}

void signalReady() {
  tone(BUZZER_PIN, 1500, 150); delay(200);
  tone(BUZZER_PIN, 2000, 150); delay(200);
  ledBlink(2, 150);
}

void signalFailure() {
  for (int i = 0; i < 5; i++) {
    tone(BUZZER_PIN, 2000, 150);
    digitalWrite(LED_PIN, HIGH); delay(150); digitalWrite(LED_PIN, LOW);
    tone(BUZZER_PIN, 1700, 150);
    digitalWrite(LED_PIN, HIGH); delay(150); digitalWrite(LED_PIN, LOW);
  }
}

void signalLocalCrashTrigger() {
  tone(BUZZER_PIN, 2000, 3000);
  ledBlink(10, 150);
}

bool loadCalibration() {
  if (EEPROM.read(CALIBRATION_FLAG_ADDR) != 0x55) return false;
  adafruit_bno055_offsets_t offsets;
  EEPROM.get(CALIBRATION_DATA_ADDR, offsets);
  bno.setSensorOffsets(offsets);
  return true;
}

bool sensorSanityCheckOnce() {
  sensors_event_t testOrient, testAccel;
  bno.getEvent(&testOrient, Adafruit_BNO055::VECTOR_EULER);
  bno.getEvent(&testAccel, Adafruit_BNO055::VECTOR_LINEARACCEL);
  float mag = sqrt(sq(testAccel.acceleration.x) + sq(testAccel.acceleration.y) + sq(testAccel.acceleration.z));
  bool allZero = (mag == 0.0 && testOrient.orientation.x == 0.0 &&
                  testOrient.orientation.y == 0.0 && testOrient.orientation.z == 0.0);
  bool implausible = (mag > 50.0);
  return !(allZero || implausible);
}

bool retryCheck(bool (*checkFn)()) {
  for (int i = 0; i < INIT_RETRY_ATTEMPTS; i++) {
    if (checkFn()) return true;
    delay(INIT_RETRY_DELAY_MS);
  }
  return false;
}

bool tryBnoBegin() { return bno.begin(); }
bool tryLittleFsBegin() { return LittleFS.begin(true); }

bool hasStorageHeadroom() {
  size_t freeBytes = LittleFS.totalBytes() - LittleFS.usedBytes();
  return freeBytes >= STORAGE_WRITE_HALT_BYTES;
}

void haltWithFailureSignal(const char* reason) {
  if (Serial) Serial.println("[FATAL] " + String(reason));
  while (1) {
    signalFailure();
    delay(1200);
    esp_task_wdt_reset();
  }
}

void setup() {
  Serial.begin(115200);
  delay(500);  // No "while (!Serial)" — must boot fully with no laptop attached.

  pinMode(LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);

  if (Serial) {
    Serial.println("=================================");
    Serial.println("AVISO Deployment Logger — Boot");
    Serial.println("=================================");
  }

  esp_reset_reason_t reason = esp_reset_reason();
  switch (reason) {
    case ESP_RST_POWERON:  resetReasonLabel = "poweron"; break;
    case ESP_RST_BROWNOUT: resetReasonLabel = "BROWNOUT"; break;
    case ESP_RST_PANIC:    resetReasonLabel = "PANIC"; break;
    case ESP_RST_TASK_WDT: resetReasonLabel = "WDT_TIMEOUT"; break;
    case ESP_RST_SW:       resetReasonLabel = "software"; break;
    default:               resetReasonLabel = "other_" + String((int)reason); break;
  }
  if (Serial) Serial.println("[INIT] Reset reason: " + resetReasonLabel);

  esp_task_wdt_config_t wdt_config = { .timeout_ms = WDT_TIMEOUT_SEC * 1000, .idle_core_mask = 0, .trigger_panic = true };
  esp_task_wdt_init(&wdt_config);
  esp_task_wdt_add(NULL);

  EEPROM.begin(EEPROM_SIZE);

  if (!retryCheck(tryBnoBegin)) haltWithFailureSignal("BNO055 not detected after retries.");
  bno.setExtCrystalUse(true);

  // --- Load already-saved calibration instantly. No manual wait. ---
  if (!loadCalibration()) haltWithFailureSignal("No saved calibration found. Run the calibration sketch first.");
  if (Serial) Serial.println("[INIT] Calibration loaded from flash.");

  if (!retryCheck(tryLittleFsBegin)) haltWithFailureSignal("LittleFS mount failed after retries.");

  size_t freeBytesAtBoot = LittleFS.totalBytes() - LittleFS.usedBytes();
  if (!hasStorageHeadroom()) haltWithFailureSignal("Storage critically low. Retrieve and clear data first.");
  if (freeBytesAtBoot < LOW_STORAGE_WARNING_BYTES) {
    if (Serial) Serial.println("[WARNING] Storage running low.");
  }

  // --- Append-only: never wipes prior sessions ---
  if (!LittleFS.exists(LOG_FILE)) {
    File file = LittleFS.open(LOG_FILE, "w");
    file.println("reading,millis,accelX,accelY,accelZ,accelMag,orientX,orientY,orientZ,event,resetReason");
    file.close();
    if (Serial) Serial.println("[INIT] New log file created.");
  } else {
    if (Serial) Serial.println("[INIT] Existing log file found — appending.");
  }

  bool sanityOk = false;
  for (int i = 0; i < INIT_RETRY_ATTEMPTS; i++) {
    if (sensorSanityCheckOnce()) { sanityOk = true; break; }
    delay(INIT_RETRY_DELAY_MS);
  }
  if (!sanityOk) haltWithFailureSignal("Sensor sanity check failed after retries.");

  signalReady();
  if (Serial) Serial.println("[READY] Logging started.");
}

void loop() {
  esp_task_wdt_reset();

  sensors_event_t orientationData, linearAccelData;
  bno.getEvent(&orientationData, Adafruit_BNO055::VECTOR_EULER);
  bno.getEvent(&linearAccelData, Adafruit_BNO055::VECTOR_LINEARACCEL);

  float ax = linearAccelData.acceleration.x;
  float ay = linearAccelData.acceleration.y;
  float az = linearAccelData.acceleration.z;
  float accelMag = sqrt(ax * ax + ay * ay + az * az);
  float ox = orientationData.orientation.x;
  float oy = orientationData.orientation.y;
  float oz = orientationData.orientation.z;

  readingCount++;

  if (accelMag == 0.0 && ox == 0.0 && oy == 0.0 && oz == 0.0) zeroReadingStreak++;
  else zeroReadingStreak = 0;
  if (zeroReadingStreak >= ZERO_STREAK_FAILURE_LIMIT) {
    if (Serial) Serial.println("[ERROR] Sensor failure — zero readings persisting.");
    signalFailure();
  }

  blackBox[bufferIndex] = { millis(), ax, ay, az, accelMag, ox, oy, oz };
  bufferIndex = (bufferIndex + 1) % BUFFER_SIZE;

  bool accelExceeded = accelMag > LOCAL_SAFETY_ACCEL_THRESHOLD;
  bool orientExceeded = abs(oz) > LOCAL_SAFETY_ORIENT_THRESHOLD;
  if (accelExceeded && orientExceeded) consecutiveExtremeReadings++;
  else consecutiveExtremeReadings = 0;

  if (!hasStorageHeadroom()) {
    if (Serial) Serial.println("[ERROR] Storage critically low — logging stopped.");
    signalFailure();
  } else if (consecutiveExtremeReadings >= CONSECUTIVE_READINGS_REQUIRED) {
    if (Serial) Serial.println(">>> LOCAL SAFETY-NET TRIGGER <<<");
    signalLocalCrashTrigger();
    File file = LittleFS.open(LOG_FILE, "a");
    if (file) {
      file.println(String(readingCount) + "," + String(millis()) + "," + String(ax,2) + "," + String(ay,2) + "," +
                    String(az,2) + "," + String(accelMag,2) + "," + String(ox,2) + "," + String(oy,2) + "," +
                    String(oz,2) + ",LOCAL_SAFETY_TRIGGER," + resetReasonLabel);
      for (int i = 0; i < BUFFER_SIZE; i++) {
        int idx = (bufferIndex + i) % BUFFER_SIZE;
        Reading r = blackBox[idx];
        file.println("blackbox," + String(r.timestamp) + "," + String(r.ax,2) + "," + String(r.ay,2) + "," +
                      String(r.az,2) + "," + String(r.accelMag,2) + "," + String(r.ox,2) + "," + String(r.oy,2) + "," + String(r.oz,2));
      }
      file.close();
    }
    consecutiveExtremeReadings = 0;
  } else {
    String line = String(readingCount) + "," + String(millis()) + "," + String(ax,2) + "," + String(ay,2) + "," +
                  String(az,2) + "," + String(accelMag,2) + "," + String(ox,2) + "," + String(oy,2) + "," +
                  String(oz,2) + ",normal," + resetReasonLabel;
    File file = LittleFS.open(LOG_FILE, "a");
    if (file) { file.println(line); file.close(); }
  }

  if (Serial) {
    Serial.println("ax:" + String(ax, 2) + " ay:" + String(ay, 2) + " az:" + String(az, 2) +
                   " accelMag:" + String(accelMag, 2));
  }

  delay(100);
}