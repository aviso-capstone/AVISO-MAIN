// ============================================================
// AVISO — Crash Detection Threshold Data Gathering
// Field-Hardened v6 (No Button, Buzzer-Calibrated, 100 Hz)
// ------------------------------------------------------------
// PURPOSE:
//   Collects labeled sensor data for one of four motion
//   categories (normal / brake / bump / crash) to establish
//   real, evidence-based thresholds for the AVISO IoT unit
//   (crash-detection/aviso-iot/config.h). This sketch performs
//   NO classification itself — it only senses, signals, and logs.
//
// WHAT EACH ROW CONTAINS (100 rows per second):
//   - raw BNO055 readings: linear acceleration (m/s^2, gravity
//     removed), gyroscope (deg/s), gravity vector (m/s^2)
//   - the SAME derived values the field firmware decides with:
//       linearG     total linear acceleration, g (0 at rest)
//       verticalG   up/down part (bumps), g
//       horizontalG forward/back/sideways part (braking), g
//       gyroDps     total rotation speed, deg/s
//       tiltDeg     lean from the upright captured at session start
//   - clipped: 1 when the accelerometer hit its +/-4 g limit
//     (fusion mode range), i.e. the true peak was higher
//   - late: 1 when this sample came late (missed time slots)
//   so the CSV can be compared directly with config.h thresholds.
//
// FILES ON THE UNIT (LittleFS):
//   /threshold_log_v5.csv       every sample of every session
//   /threshold_sessions_v5.csv  one summary row per session
//                               (peaks + when they happened, data
//                               quality, reset reason; a session cut
//                               off by power loss gets an
//                               INTERRUPTED row on the next boot)
//   (File names stay _v5 so retrieval/cleanup work unchanged; the
//   summary's "version" column says which sketch wrote each row.)
//   Retrieve both with the retrieval sketch, then clear them with
//   the storage-cleanup sketch.
//
// WORKFLOW (one session per power-up):
//   1. Set sessionMode below to the category being tested.
//   2. Upload with laptop attached, confirm the beeps:
//      1 beep = normal, 2 = brake, 3 = bump, 4 = crash.
//   3. Power on: 15 s of short ticks (one every 5 s) to get the
//      bike ready — unplugging during the ticks skips the session.
//      Put the bike UPRIGHT and STILL. After the category beeps,
//      the unit captures the upright position (0.5 s), then plays
//      the low "starting" tone. The 30 s window runs from there.
//      If the unit is moving, short chirps repeat until it is
//      still (gives up after ~10 s and flags upright_still=0).
//   4. Unplug laptop, power the unit independently, run the test.
//   5. Three descending tones = session complete, safe to unplug.
//   6. Power-cycle for the next attempt (attempt numbers persist
//      in EEPROM per category and survive storage cleanup).
//
// PLATFORMIO:
//   pio run -e threshold-gathering -t upload
//   Board, core 3.x, libraries and the shared partition table
//   (~2.4 MB storage, about 5 sessions before retrieval) are all
//   set in platformio.ini / partitions.csv. The Arduino IDE copy in
//   crash-detection/threshold-gathering uses the same layout.
//
// AUDIO DESIGN NOTE:
//   2000Hz was confirmed, by direct listening test on this
//   specific buzzer unit, to be its loudest resonant frequency.
//   It is used for signals where audibility matters most
//   (failure/alarm, category confirmation, test completion).
//   700Hz is deliberately kept for the "starting" signal so it
//   remains unmistakably distinct from every alert tone, not
//   because it is louder.
//
// v6 CHANGES (from v5):
//   - Power lost mid-session is detected on the next boot and
//     recorded as an INTERRUPTED summary row (was silent).
//   - Upright reference only captured while still (gyro check).
//   - Summary adds when each peak happened (t_peak_*), duration,
//     effective sample rate and upright_still.
//   - Attempt counter no longer restarts at 1 after 254.
//   - 15 s start delay after power-on (ticks), nothing written during it.
//
// v5 CHANGES (from v4):
//   - Storage checked every 100 rows instead of every row: the
//     per-row check slowed as the file grew and broke 100 Hz.
//   - Boot refuses to start a session unless a FULL session fits,
//     so no session ends half-written.
//   - Timing on micros(); a late sample is flagged and skipped
//     slots are counted, instead of bursting duplicate readings.
//   - I2C at 400 kHz; rows built with snprintf (stable timing).
//   - clipped / late flags, firmware-matching derived values,
//     per-session summary file, calibration status recorded.
//   - Heartbeat LED no longer blocks sampling.
// ============================================================

#include <Arduino.h>
#include <Wire.h>
#include <Adafruit_Sensor.h>
#include <Adafruit_BNO055.h>
#include <utility/imumaths.h>
#include <LittleFS.h>
#include <EEPROM.h>
#include <esp_task_wdt.h>
#include <esp_system.h>

#define GATHER_VERSION "v6"

Adafruit_BNO055 bno = Adafruit_BNO055(55, 0x29);  // I2C address confirmed via scanner on this hardware

// ============================================================
// SESSION CONFIGURATION — set this before each upload
// ============================================================
// 0 = normal riding | 1 = hard brake | 2 = road bump | 3 = crash (drop-rig only)
const int sessionMode = 0;

// ============================================================
// PIN ASSIGNMENTS
// ============================================================
#define LED_PIN 2
#define BUZZER_PIN 4

// ============================================================
// EEPROM LAYOUT
// ============================================================
#define EEPROM_SIZE 512
#define CALIBRATION_FLAG_ADDR 0     // 1 byte: 0x55 = valid calibration saved
#define CALIBRATION_DATA_ADDR 1     // BNO055 offset struct
#define ATTEMPT_COUNT_ADDR 100      // 4 bytes, one counter per category (0-3)
#define IN_PROGRESS_ADDR 110        // 1 byte: category + 1 while a session is logging, 0 = none
#define IN_PROGRESS_ATTEMPT_ADDR 111 // 1 byte: that session's attempt number

// ============================================================
// SYSTEM TIMING / SAFETY LIMITS
// ============================================================
#define WDT_TIMEOUT_SEC 8               // hardware watchdog — force-reboots if loop() ever stalls
#define INIT_RETRY_ATTEMPTS 3           // startup checks get this many tries before declaring failure
#define INIT_RETRY_DELAY_MS 500
#define HEARTBEAT_INTERVAL_MS 15000     // quiet "still alive" tick during the test window
#define SAMPLE_INTERVAL_US 10000UL      // 100 Hz, the BNO055 fusion output rate
#define FLUSH_EVERY_ROWS 100            // flush the open log file once per second
#define STORAGE_CHECK_EVERY_ROWS 100    // free-space check once per second (not per row)
#define UPRIGHT_SAMPLES 50              // 0.5 s average = the session's upright reference
#define UPRIGHT_STILL_DPS 5.0f          // gyro above this during the capture = not still, retry
#define UPRIGHT_MAX_TRIES 20            // ~10 s of retries, then capture anyway (flagged)
#define START_DELAY_MS 15000UL          // wait after power-on: time to get the bike upright and still.
                                        // Nothing is written during it, so unplugging then skips
                                        // the session cleanly (no attempt number used).

const unsigned long TEST_DURATION_MS = 30000;  // 30-second window per session (see rationale: more
                                                 // repetitions across short, clean sessions yields
                                                 // better data than fewer long sessions with more
                                                 // risk of unintended movement during the window)

// Storage budget: a row is ~120 bytes; 140 leaves margin for long
// numbers. A session is only started when all of it fits.
const size_t ROW_BYTES_ESTIMATE = 140;
const size_t SESSION_ROWS = TEST_DURATION_MS / (SAMPLE_INTERVAL_US / 1000);
const size_t SESSION_BYTES_NEEDED = SESSION_ROWS * ROW_BYTES_ESTIMATE + 4096;
const size_t STORAGE_WRITE_HALT_BYTES = 8192;   // mid-session hard stop (should never trigger)

// Physics constants
const float G = 9.80665f;
// In fusion modes the BNO055 accelerometer range is fixed at +/-4 g.
// A raw axis at/above this means the real value was cut off.
const float CLIP_LIMIT_MS2 = 0.98f * 4.0f * G;

const char* LOG_FILE = "/threshold_log_v5.csv";
const char* SESSION_FILE = "/threshold_sessions_v5.csv";
const char* sessionLabels[] = {"normal", "brake", "bump", "crash"};

int attemptNumber = 1;
String resetReasonLabel = "unknown";  // written into the session summary, flags sessions
                                       // that were interrupted by an unexpected reboot
uint8_t calSys = 0, calGyro = 0, calAccel = 0, calMag = 0;

// ---- Session state ----
unsigned long testStartMs = 0;
unsigned long testStartUs = 0;
unsigned long nextSampleUs = 0;
unsigned long lastHeartbeat = 0;
unsigned long ledOffAt = 0;
bool testActive = false;
bool testComplete = false;
bool storageStopped = false;

File logFile;                  // kept open for the whole test window
unsigned long readingCount = 0;
unsigned long rowsSinceFlush = 0;
unsigned long lateSamples = 0;      // samples that arrived late
unsigned long missedSlots = 0;      // 10 ms slots skipped entirely
unsigned long clippedSamples = 0;
unsigned long failedReads = 0;
int zeroReadingStreak = 0;
const int ZERO_STREAK_FAILURE_LIMIT = 10;  // consecutive bad reads = likely I2C failure

// Upright reference (unit vector of gravity at session start)
float upX = 0, upY = 0, upZ = 1;
bool uprightStill = true;      // false = captured while moving (tilt values less reliable)

// Session peaks (same quantities as the field firmware) and the
// session time (t_ms, same as the log rows) each one happened at,
// so analysis can jump straight to the event in the big log.
float peakLinearG = 0, peakVerticalG = 0, peakHorizontalG = 0, peakGyroDps = 0, maxTiltDeg = 0;
unsigned long tPeakLinear = 0, tPeakVertical = 0, tPeakHorizontal = 0, tPeakGyro = 0, tMaxTilt = 0;
unsigned long lastRowMs = 0;   // t_ms of the last good sample = logged duration

// ============================================================
// BUZZER + LED SIGNAL LIBRARY
// ------------------------------------------------------------
// Every signal is both audible AND visible, since outdoor
// lighting can hide the LED and road/wind noise can mask the
// buzzer — each channel backs up the other.
// (Blocking signals are only used outside the 30 s window.)
// ============================================================

// Category confirmation: tone count == blink count == category
// number, so audio and visual always agree on what was selected.
void signalCategoryConfirm(int categoryNumber) {
  delay(400);
  for (int i = 0; i < categoryNumber; i++) {
    tone(BUZZER_PIN, 2000, 250);
    digitalWrite(LED_PIN, HIGH); delay(250); digitalWrite(LED_PIN, LOW);
    delay(250);
  }
}

// "Test starting now" — deliberately low (700Hz) and sustained,
// so it can never be confused with a higher-pitched alert tone.
void signalStartingNow() {
  tone(BUZZER_PIN, 700, 900);
  digitalWrite(LED_PIN, HIGH); delay(900); digitalWrite(LED_PIN, LOW);
  delay(200);
}

// "Test complete, safe to unplug" — three descending tones.
void signalTestComplete() {
  int freqs[] = {2000, 1500, 1000};
  for (int i = 0; i < 3; i++) {
    tone(BUZZER_PIN, freqs[i], 220);
    digitalWrite(LED_PIN, HIGH); delay(220); digitalWrite(LED_PIN, LOW);
    delay(180);
  }
}

// Failure / alarm — alternating tones read as "alarm".
void signalFailure() {
  for (int i = 0; i < 5; i++) {
    tone(BUZZER_PIN, 2000, 150);
    digitalWrite(LED_PIN, HIGH); delay(150); digitalWrite(LED_PIN, LOW);
    tone(BUZZER_PIN, 1700, 150);
    digitalWrite(LED_PIN, HIGH); delay(150); digitalWrite(LED_PIN, LOW);
  }
}

// Low storage warning — a "heads up," not an emergency.
void signalLowStorageWarning() {
  for (int i = 0; i < 3; i++) {
    tone(BUZZER_PIN, 1200, 300);
    digitalWrite(LED_PIN, HIGH); delay(300); digitalWrite(LED_PIN, LOW);
    delay(200);
  }
}

// Heartbeat during the window — NON-blocking: tone() with a
// duration plays in the background and the LED is switched off
// later from loop(), so sampling is never paused.
void signalHeartbeat(unsigned long nowMs) {
  tone(BUZZER_PIN, 1000, 40);
  digitalWrite(LED_PIN, HIGH);
  ledOffAt = nowMs + 40;
}

// ============================================================
// CALIBRATION
// ------------------------------------------------------------
// Calibration is performed ONCE per physical sensor via a
// separate, dedicated calibration sketch. This sketch only
// loads the already-saved result — it never recalibrates.
// ============================================================
bool loadCalibration() {
  if (EEPROM.read(CALIBRATION_FLAG_ADDR) != 0x55) return false;
  adafruit_bno055_offsets_t offsets;
  EEPROM.get(CALIBRATION_DATA_ADDR, offsets);
  bno.setSensorOffsets(offsets);   // switches to CONFIG mode and back internally
  return true;
}

// ============================================================
// ATTEMPT COUNTER (persists across power cycles and cleanups)
// ============================================================
int getAndIncrementAttempt(int mode) {
  int addr = ATTEMPT_COUNT_ADDR + mode;
  byte current = EEPROM.read(addr);
  if (current == 255) current = 0;  // unwritten EEPROM reads as 0xFF
  byte next = (current >= 254) ? 1 : current + 1;  // never store 255 (would read back as "unwritten")
  EEPROM.write(addr, next);
  EEPROM.commit();
  return next;
}

// ============================================================
// SENSOR HELPERS
// ============================================================
float norm3(float x, float y, float z) { return sqrtf(x * x + y * y + z * z); }

// Gravity is always ~9.8 m/s^2 at rest; a near-zero or absurd
// value means an I2C failure, not a real reading.
bool sensorSanityCheckOnce() {
  imu::Vector<3> grav = bno.getVector(Adafruit_BNO055::VECTOR_GRAVITY);
  imu::Vector<3> lin = bno.getVector(Adafruit_BNO055::VECTOR_LINEARACCEL);
  float g = norm3(grav.x(), grav.y(), grav.z());
  float l = norm3(lin.x(), lin.y(), lin.z());
  return g > 8.0f && g < 11.5f && l < 50.0f;
}

bool retryCheck(bool (*checkFn)()) {
  for (int i = 0; i < INIT_RETRY_ATTEMPTS; i++) {
    if (checkFn()) return true;
    delay(INIT_RETRY_DELAY_MS);
  }
  return false;
}

bool tryBnoBegin() { return bno.begin(OPERATION_MODE_IMUPLUS); }
bool tryLittleFsBegin() { return LittleFS.begin(true); }

size_t freeBytes() { return LittleFS.totalBytes() - LittleFS.usedBytes(); }

// Unrecoverable failure: alarm forever rather than fail silently.
void haltWithFailureSignal(const char* reason) {
  Serial.println("[FATAL] " + String(reason));
  while (1) {
    signalFailure();
    delay(1200);
    esp_task_wdt_reset();
  }
}

// Averages gravity for 0.5 s with the bike upright and still: the
// reference every tilt value in this session is measured from.
// If the unit rotates during the 0.5 s it chirps and tries again;
// after UPRIGHT_MAX_TRIES it keeps the last capture and flags it.
void captureUprightReference() {
  for (int attempt = 1; attempt <= UPRIGHT_MAX_TRIES; attempt++) {
    float sx = 0, sy = 0, sz = 0;
    int n = 0;
    bool moved = false;
    for (int i = 0; i < UPRIGHT_SAMPLES; i++) {
      imu::Vector<3> grav = bno.getVector(Adafruit_BNO055::VECTOR_GRAVITY);
      imu::Vector<3> gyr = bno.getVector(Adafruit_BNO055::VECTOR_GYROSCOPE);
      if (norm3(gyr.x(), gyr.y(), gyr.z()) > UPRIGHT_STILL_DPS) moved = true;
      if (norm3(grav.x(), grav.y(), grav.z()) > 8.0f) {
        sx += grav.x(); sy += grav.y(); sz += grav.z(); n++;
      }
      delay(SAMPLE_INTERVAL_US / 1000);
    }
    esp_task_wdt_reset();
    float len = norm3(sx, sy, sz);
    if (n == 0 || len < 1.0f) haltWithFailureSignal("Could not read gravity for the upright reference.");
    upX = sx / len; upY = sy / len; upZ = sz / len;
    uprightStill = !moved;
    if (uprightStill) return;

    Serial.printf("[INIT] Unit moving during upright capture (try %d/%d) — hold the bike still.\n",
                  attempt, UPRIGHT_MAX_TRIES);
    tone(BUZZER_PIN, 1700, 60);
    digitalWrite(LED_PIN, HIGH); delay(60); digitalWrite(LED_PIN, LOW);
  }
  Serial.println("[WARNING] Never still — upright captured while moving (upright_still=0).");
}

// ============================================================
// SESSION SUMMARY (one row per session, written at the end)
// ============================================================
// Columns appended at the END only, so rows written by v5 still
// line up with the first 18 columns of this header.
const char* SESSION_HEADER =
  "version,session_label,attempt,status,rows,late_samples,missed_slots,clipped_samples,"
  "failed_reads,peak_linear_g,peak_vertical_g,peak_horizontal_g,peak_gyro_dps,max_tilt_deg,"
  "cal_sys,cal_gyro,cal_accel,reset_reason,"
  "duration_ms,effective_hz,upright_still,"
  "t_peak_linear_ms,t_peak_vertical_ms,t_peak_horizontal_ms,t_peak_gyro_ms,t_max_tilt_ms";

void writeSummaryLine(const char* line) {
  bool isNew = !LittleFS.exists(SESSION_FILE);
  File f = LittleFS.open(SESSION_FILE, "a");
  if (!f) return;
  if (isNew) f.println(SESSION_HEADER);
  f.println(line);
  f.close();
}

void writeSessionSummary(const char* status) {
  const float effectiveHz = lastRowMs > 0 ? readingCount * 1000.0f / lastRowMs : 0.0f;
  char line[400];
  snprintf(line, sizeof(line),
           "%s,%s,%d,%s,%lu,%lu,%lu,%lu,%lu,%.3f,%.3f,%.3f,%.1f,%.1f,%u,%u,%u,%s,"
           "%lu,%.1f,%d,%lu,%lu,%lu,%lu,%lu",
           GATHER_VERSION, sessionLabels[sessionMode], attemptNumber, status,
           readingCount, lateSamples, missedSlots, clippedSamples, failedReads,
           peakLinearG, peakVerticalG, peakHorizontalG, peakGyroDps, maxTiltDeg,
           calSys, calGyro, calAccel, resetReasonLabel.c_str(),
           lastRowMs, effectiveHz, uprightStill ? 1 : 0,
           tPeakLinear, tPeakVertical, tPeakHorizontal, tPeakGyro, tMaxTilt);
  writeSummaryLine(line);
}

// The previous power-up stopped mid-session (power lost / unplugged
// early / crash rig knocked the battery out). Its rows are in the big
// log but it never got a summary row — add one so analysis can find
// and drop it. Peaks are unknown, left empty. Called after LittleFS is up.
void recordInterruptedSession() {
  const byte flag = EEPROM.read(IN_PROGRESS_ADDR);
  if (flag == 0 || flag == 255) return;
  const int category = flag - 1;
  const int attempt = EEPROM.read(IN_PROGRESS_ATTEMPT_ADDR);
  if (category >= 0 && category <= 3) {
    char line[200];
    snprintf(line, sizeof(line), "%s,%s,%d,INTERRUPTED,,,,,,,,,,,,,,%s,,,,,,,,",
             GATHER_VERSION, sessionLabels[category], attempt, resetReasonLabel.c_str());
    writeSummaryLine(line);
    Serial.printf("[WARNING] Previous session (%s #%d) was cut off — recorded as INTERRUPTED.\n",
                  sessionLabels[category], attempt);
  }
  EEPROM.write(IN_PROGRESS_ADDR, 0);
  EEPROM.commit();
}

void setInProgress(bool active) {
  EEPROM.write(IN_PROGRESS_ADDR, active ? sessionMode + 1 : 0);
  if (active) EEPROM.write(IN_PROGRESS_ATTEMPT_ADDR, (byte)attemptNumber);
  EEPROM.commit();
}

void endSession(const char* status) {
  testActive = false;
  testComplete = true;
  if (logFile) logFile.close();
  digitalWrite(LED_PIN, LOW);
  writeSessionSummary(status);
  setInProgress(false);
  signalTestComplete();

  Serial.println("-------------------------------------");
  Serial.printf("[SUMMARY] %s #%d  status=%s\n", sessionLabels[sessionMode], attemptNumber, status);
  Serial.printf("[SUMMARY] rows=%lu in %lu ms (%.1f Hz)  late=%lu  missed_slots=%lu  clipped=%lu  failed_reads=%lu\n",
                readingCount, lastRowMs, lastRowMs > 0 ? readingCount * 1000.0f / lastRowMs : 0.0f,
                lateSamples, missedSlots, clippedSamples, failedReads);
  Serial.printf("[SUMMARY] peak linear %.2f g @%lu ms | vertical %.2f g @%lu ms | horizontal %.2f g @%lu ms\n",
                peakLinearG, tPeakLinear, peakVerticalG, tPeakVertical, peakHorizontalG, tPeakHorizontal);
  Serial.printf("[SUMMARY] peak gyro %.0f deg/s @%lu ms | max tilt %.1f deg @%lu ms | upright_still=%d\n",
                peakGyroDps, tPeakGyro, maxTiltDeg, tMaxTilt, uprightStill ? 1 : 0);
  if (clippedSamples > 0) Serial.println("[NOTE] Some readings hit the 4 g sensor limit: real peaks were higher.");
  if (missedSlots > SESSION_ROWS / 100) Serial.println("[NOTE] More than 1% of samples were missed.");
  if (!uprightStill) Serial.println("[NOTE] Upright was captured while moving: tilt values are less reliable.");
  Serial.println("[SESSION] Complete. Safe to unplug.");
  Serial.println("-------------------------------------");
}

// ============================================================
// SETUP
// ============================================================
void setup() {
  Serial.begin(115200);
  delay(500);
  // Deliberately NO "while (!Serial) delay(10);" here — this
  // unit must boot and run fully correctly with no laptop
  // attached at all, since field testing has no Serial Monitor.

  pinMode(LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);

  Serial.println("=====================================");
  Serial.println("AVISO Threshold Data Gathering " GATHER_VERSION " — Boot");
  Serial.println("=====================================");

  // Record why the board booted — distinguishes a clean power-on
  // from a mid-session brownout or watchdog reset.
  esp_reset_reason_t reason = esp_reset_reason();
  switch (reason) {
    case ESP_RST_POWERON:  resetReasonLabel = "poweron"; break;
    case ESP_RST_BROWNOUT: resetReasonLabel = "BROWNOUT"; break;
    case ESP_RST_PANIC:    resetReasonLabel = "PANIC"; break;
    case ESP_RST_TASK_WDT: resetReasonLabel = "WDT_TIMEOUT"; break;
    case ESP_RST_SW:       resetReasonLabel = "software"; break;
    default:               resetReasonLabel = "other_" + String((int)reason); break;
  }
  Serial.println("[INIT] Reset reason: " + resetReasonLabel);

  // Hardware watchdog. On esp32 core 3.x the watchdog is already
  // running at boot, so init returns an error — reconfigure then.
  esp_task_wdt_config_t wdt_config = {
    .timeout_ms = WDT_TIMEOUT_SEC * 1000,
    .idle_core_mask = 0,
    .trigger_panic = true
  };
  if (esp_task_wdt_init(&wdt_config) != ESP_OK) esp_task_wdt_reconfigure(&wdt_config);
  esp_task_wdt_add(NULL);

  // --- Start delay: nothing written to storage or EEPROM yet ---
  Serial.printf("[INIT] Starting in %lu s — unplug now to skip this session.\n", START_DELAY_MS / 1000);
  for (unsigned long waited = 0; waited < START_DELAY_MS; waited += 1000) {
    if (waited % 5000 == 0) {   // short tick every 5 s
      tone(BUZZER_PIN, 1000, 40);
      digitalWrite(LED_PIN, HIGH); delay(40); digitalWrite(LED_PIN, LOW);
      delay(960);
    } else {
      delay(1000);
    }
    esp_task_wdt_reset();
  }

  EEPROM.begin(EEPROM_SIZE);

  // --- Sensor presence check (IMU mode: accel + gyro fusion,
  //     no magnetometer — same as the field firmware) ---
  if (!retryCheck(tryBnoBegin)) haltWithFailureSignal("BNO055 not detected after retries.");
  Wire.setClock(400000);   // fast I2C (set after begin, which starts the bus): 4 reads fit easily in 10 ms
  bno.setExtCrystalUse(true);
  Serial.println("[INIT] BNO055 detected (IMU mode).");

  // --- Calibration check — this sketch never calibrates itself ---
  if (!loadCalibration()) haltWithFailureSignal("No saved calibration found. Run the calibration sketch first.");
  Serial.println("[INIT] Calibration loaded from flash.");
  delay(50);

  // --- Storage: a session only starts if ALL of it fits ---
  if (!retryCheck(tryLittleFsBegin)) haltWithFailureSignal("LittleFS mount failed after retries.");
  recordInterruptedSession();
  size_t freeAtBoot = freeBytes();
  Serial.printf("[STORAGE] Free: %u / %u bytes. One session needs ~%u bytes -> %u session(s) fit.\n",
                (unsigned)freeAtBoot, (unsigned)LittleFS.totalBytes(),
                (unsigned)SESSION_BYTES_NEEDED, (unsigned)(freeAtBoot / SESSION_BYTES_NEEDED));
  if (freeAtBoot < SESSION_BYTES_NEEDED) {
    haltWithFailureSignal("Not enough storage for a full session. Retrieve and clear data first.");
  }
  if (freeAtBoot < 2 * SESSION_BYTES_NEEDED) {
    Serial.println("[WARNING] Only one more session fits. Retrieve data after this one.");
    signalLowStorageWarning();
  }

  // --- Log file setup (append-only — never overwrites prior sessions) ---
  if (!LittleFS.exists(LOG_FILE)) {
    File file = LittleFS.open(LOG_FILE, "w");
    if (!file) haltWithFailureSignal("Could not create the log file.");
    file.println("session_label,attempt,reading,t_ms,linX,linY,linZ,gyroX,gyroY,gyroZ,gravX,gravY,gravZ,"
                 "linearG,verticalG,horizontalG,gyroDps,tiltDeg,clipped,late");
    file.close();
    Serial.println("[INIT] New log file created.");
  } else {
    Serial.println("[INIT] Existing log file found — appending.");
  }

  // --- Pre-test sensor sanity check ---
  delay(300);
  if (!retryCheck(sensorSanityCheckOnce)) haltWithFailureSignal("Sensor sanity check failed after retries.");
  Serial.println("[INIT] Sensor sanity check passed.");

  bno.getCalibration(&calSys, &calGyro, &calAccel, &calMag);
  Serial.printf("[INIT] Calibration status: sys=%u gyro=%u accel=%u (0-3)\n", calSys, calGyro, calAccel);

  // --- Attempt numbering, persists across power cycles ---
  attemptNumber = getAndIncrementAttempt(sessionMode);
  Serial.println("-------------------------------------");
  Serial.printf("[SESSION] Category: %s  |  Attempt #%d\n", sessionLabels[sessionMode], attemptNumber);
  Serial.printf("[SESSION] Confirming via %d tone(s)/blink(s)...\n", sessionMode + 1);

  // --- Signal: category confirmed, audio and visual in agreement ---
  signalCategoryConfirm(sessionMode + 1);

  // --- Upright reference: bike must be upright and still here ---
  delay(300);
  captureUprightReference();
  Serial.printf("[INIT] Upright reference captured (%.3f, %.3f, %.3f).\n", upX, upY, upZ);

  // --- Signal: test window starting ---
  signalStartingNow();

  logFile = LittleFS.open(LOG_FILE, "a");
  if (!logFile) haltWithFailureSignal("Could not open the log file for writing.");
  setInProgress(true);   // cleared in endSession(); still set on next boot = power was lost

  testStartMs = millis();
  testStartUs = micros();
  nextSampleUs = testStartUs;
  lastHeartbeat = testStartMs;
  testActive = true;
  testComplete = false;

  Serial.println("[SESSION] Logging started. 30-second window running.");
  Serial.println("-------------------------------------");
}

// ============================================================
// LOOP
// ============================================================
void loop() {
  esp_task_wdt_reset();

  if (testComplete) { delay(1000); return; }

  const unsigned long nowMs = millis();

  // --- Non-blocking LED off for the heartbeat ---
  if (ledOffAt && (long)(nowMs - ledOffAt) >= 0) { digitalWrite(LED_PIN, LOW); ledOffAt = 0; }

  // --- Quiet heartbeat, confirms the unit hasn't silently hung ---
  if (testActive && nowMs - lastHeartbeat >= HEARTBEAT_INTERVAL_MS) {
    signalHeartbeat(nowMs);
    lastHeartbeat = nowMs;
  }

  // --- End of test window ---
  if (testActive && nowMs - testStartMs >= TEST_DURATION_MS) {
    endSession(storageStopped ? "INCOMPLETE_STORAGE" : (failedReads > 0 ? "OK_WITH_READ_ERRORS" : "OK"));
    return;
  }

  // --- Fixed 100 Hz schedule on micros() ---
  const unsigned long nowUs = micros();
  if ((long)(nowUs - nextSampleUs) < 0) return;

  // Late by a whole slot or more: count the skipped slots and
  // re-align, rather than bursting back-to-back reads (the sensor
  // only produces new data every 10 ms, so a burst = duplicates).
  bool late = false;
  unsigned long behind = nowUs - nextSampleUs;
  if (behind >= SAMPLE_INTERVAL_US) {
    missedSlots += behind / SAMPLE_INTERVAL_US;
    lateSamples++;
    late = true;
    nextSampleUs = nowUs;
  }
  nextSampleUs += SAMPLE_INTERVAL_US;

  // --- Read sensor ---
  imu::Vector<3> lin = bno.getVector(Adafruit_BNO055::VECTOR_LINEARACCEL);   // m/s^2, gravity removed
  imu::Vector<3> gyr = bno.getVector(Adafruit_BNO055::VECTOR_GYROSCOPE);     // deg/s
  imu::Vector<3> grav = bno.getVector(Adafruit_BNO055::VECTOR_GRAVITY);      // m/s^2
  imu::Vector<3> raw = bno.getVector(Adafruit_BNO055::VECTOR_ACCELEROMETER); // m/s^2, for the clip check

  const float gravNorm = norm3(grav.x(), grav.y(), grav.z());

  // --- Mid-test I2C failure detection: gravity is never ~0 ---
  if (gravNorm < 1.0f) {
    failedReads++;
    if (++zeroReadingStreak == ZERO_STREAK_FAILURE_LIMIT) {
      Serial.println("[ERROR] Sensor failure mid-test — readings invalid. Session will be marked.");
      tone(BUZZER_PIN, 1700, 300);   // short, non-blocking warning
    }
    return;   // never log a garbage row
  }
  zeroReadingStreak = 0;
  readingCount++;

  // --- Derived values, computed exactly like aviso-iot/imu.cpp ---
  const float ux = grav.x() / gravNorm, uy = grav.y() / gravNorm, uz = grav.z() / gravNorm;
  const float linNorm = norm3(lin.x(), lin.y(), lin.z());
  const float vertical = lin.x() * ux + lin.y() * uy + lin.z() * uz;
  const float horizontal = sqrtf(fmaxf(0.0f, linNorm * linNorm - vertical * vertical));
  const float linearG = linNorm / G;
  const float verticalG = fabsf(vertical) / G;
  const float horizontalG = horizontal / G;
  const float gyroDps = norm3(gyr.x(), gyr.y(), gyr.z());
  const float dot = fmaxf(-1.0f, fminf(1.0f, ux * upX + uy * upY + uz * upZ));
  const float tiltDeg = acosf(dot) * 180.0f / PI;

  const bool clipped = fabsf(raw.x()) >= CLIP_LIMIT_MS2 || fabsf(raw.y()) >= CLIP_LIMIT_MS2 ||
                       fabsf(raw.z()) >= CLIP_LIMIT_MS2;
  if (clipped) clippedSamples++;

  // --- Session peaks + when they happened (same t_ms as the log row) ---
  const unsigned long tMs = (nowUs - testStartUs) / 1000UL;
  lastRowMs = tMs;
  if (linearG > peakLinearG)         { peakLinearG = linearG;         tPeakLinear = tMs; }
  if (verticalG > peakVerticalG)     { peakVerticalG = verticalG;     tPeakVertical = tMs; }
  if (horizontalG > peakHorizontalG) { peakHorizontalG = horizontalG; tPeakHorizontal = tMs; }
  if (gyroDps > peakGyroDps)         { peakGyroDps = gyroDps;         tPeakGyro = tMs; }
  if (tiltDeg > maxTiltDeg)          { maxTiltDeg = tiltDeg;          tMaxTilt = tMs; }

  // --- Live telemetry (Serial Monitor only), 10 Hz so it never slows sampling ---
  if (readingCount % 10 == 0) {
    Serial.printf("[%s #%d] g=%.2f vert=%.2f horiz=%.2f gyro=%.0f tilt=%.1f%s\n",
                  sessionLabels[sessionMode], attemptNumber, linearG, verticalG, horizontalG,
                  gyroDps, tiltDeg, clipped ? " CLIPPED" : "");
  }

  // --- Storage check once per second; stop cleanly if ever full ---
  if (!storageStopped && readingCount % STORAGE_CHECK_EVERY_ROWS == 0 && freeBytes() < STORAGE_WRITE_HALT_BYTES) {
    storageStopped = true;
    if (logFile) logFile.close();
    Serial.println("[ERROR] Storage critically low — logging stopped. Session marked INCOMPLETE.");
    tone(BUZZER_PIN, 1200, 500);
  }
  if (storageStopped || !logFile) return;

  // --- Persistent log ---
  char row[256];
  snprintf(row, sizeof(row),
           "%s,%d,%lu,%lu,%.2f,%.2f,%.2f,%.1f,%.1f,%.1f,%.2f,%.2f,%.2f,%.3f,%.3f,%.3f,%.1f,%.1f,%d,%d",
           sessionLabels[sessionMode], attemptNumber, readingCount, tMs,
           lin.x(), lin.y(), lin.z(), gyr.x(), gyr.y(), gyr.z(), grav.x(), grav.y(), grav.z(),
           linearG, verticalG, horizontalG, gyroDps, tiltDeg, clipped ? 1 : 0, late ? 1 : 0);
  logFile.println(row);
  if (++rowsSinceFlush >= FLUSH_EVERY_ROWS) { logFile.flush(); rowsSinceFlush = 0; }
}
