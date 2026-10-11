// ============================================================
// AVISO — Threshold Data Gathering  (v8)
// ------------------------------------------------------------
// Records labelled 30-second sessions from the BNO055 at 100 Hz
// so the crash / brake / bump thresholds of the field firmware
// (aviso-iot/config.h) can be set from real data. This sketch
// only senses, signals and logs; it does not classify anything.
//
// CATEGORIES (sessionMode; beeps at start = sessionMode + 1)
//   0 normal    normal riding
//   1 brake     hard braking
//   2 bump      road bumps (put the speed in sessionNote)
//   3 crash     drop rig only, never with a rider
//   4 bench     idle / parked / table tests
//   5 tilt      engine idling, lean ~10 deg left, upright, right
//   6 parking   side stand, parking, getting on and off
//   For crash / tilt / parking: start UPRIGHT, then tip / lean /
//   stand it. The upright capture is the reference for tilt.
//
// ONE SESSION PER POWER-UP
//   1. Set sessionMode and sessionNote below, upload.
//   2. Power on -> 15 s of ticks (one per 5 s) to get ready.
//      Unplugging during the ticks skips the session cleanly.
//   3. Category beeps -> upright capture (0.5 s, bike upright and
//      steady; engine may run) -> low "start" tone.
//      Chirps = bike is tilting; after ~5 s it records anyway and
//      flags upright_still=0.
//   4. 30 s window. A quiet tick every 15 s = still running.
//   5. LED on while saving, then 3 falling tones = safe to unplug.
//   6. Power-cycle for the next attempt. Attempt numbers are kept
//      per category in EEPROM (they survive storage cleanup).
//
// FILES ON THE UNIT (LittleFS)
//   /thr_<label>_<attempt>.csv  every sample of one session
//                               (e.g. /thr_brake_7.csv)
//   /thr_sessions.csv           one summary row per session: peaks
//                               and when they happened, data
//                               quality, mount vector, note.
//                               Power lost mid-session = an
//                               INTERRUPTED row on the next boot.
//   Get them with retrieval-littlefs, then clear them with
//   storage-cleanup.
//
// LOG COLUMNS (per sample)
//   lin / gyro / grav XYZ   raw BNO055 (m/s^2, deg/s, m/s^2)
//   linearG                 total linear acceleration, g (0 at rest)
//   verticalG / horizontalG along / across gravity, g
//   gyroDps                 total rotation speed
//   tiltDeg                 lean from the upright capture
//   clipped                 hit the +/-4 g sensor limit (peak higher)
//   late                    sample taken late (time slots missed)
//   Derived exactly like aviso-iot/imu.cpp, so values compare
//   directly with config.h.
//
// DESIGN NOTES
//   - The window is kept in RAM and written to flash after it ends:
//     flash writes pause the ESP32 and used to cost ~20 % of samples.
//   - A session only starts if all of it fits in storage, and
//     storage is never auto-formatted (format-littlefs does that).
//   - The mount check compares the upright vector of normal / brake
//     / bump sessions: the brake direction depends on the mount.
//   - 2000 Hz is this buzzer's loudest tone (used for alerts); the
//     700 Hz start tone is deliberately different from all alerts.
//
// ARDUINO IDE
//   Board ESP32 Dev Module, esp32 core 3.x.
//   Libraries: Adafruit BNO055, Adafruit Unified Sensor.
//   The partitions.csv in this folder replaces the Partition
//   Scheme menu. Every AVISO sketch folder has the same file, so
//   all of them see the same storage (~2.4 MB, ~7 sessions).
//
// HISTORY
//   v8  tilt + parking categories, sessionNote, mount vector in
//       the summary + "mount moved" warning, gravity-direction
//       upright check (gyro check failed with the engine running)
//   v7  RAM-buffered window, one file per session, bench category,
//       calibration at end, at-rest offset/noise, damaged-storage check
//   v6  INTERRUPTED rows, peak timestamps, 15 s start delay
//   v5  micros() timing with late/clipped flags, full-session space check
// ============================================================

#include <Wire.h>
#include <Adafruit_Sensor.h>
#include <Adafruit_BNO055.h>
#include <utility/imumaths.h>
#include <LittleFS.h>
#include <EEPROM.h>
#include <esp_task_wdt.h>
#include <esp_system.h>

#define GATHER_VERSION "v8"

Adafruit_BNO055 bno = Adafruit_BNO055(55, 0x29);  // I2C address confirmed via scanner on this hardware

// ============================================================
// SESSION CONFIGURATION — set before each upload
// ============================================================
// 0 normal | 1 brake | 2 bump | 3 crash (drop rig only)
// 4 bench  | 5 tilt  | 6 parking            (see CATEGORIES above)
const int sessionMode = 0;

// Saved with every session of this upload, e.g. "25kmh", "left side".
// Max 24 characters; commas become ';'. "" = no note.
const char* sessionNote = "";

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
#define ATTEMPT_COUNT_ADDR 100      // 7 bytes, one counter per category (0-6)
#define IN_PROGRESS_ADDR 110        // 1 byte: category + 1 while a session is logging, 0 = none
#define IN_PROGRESS_ATTEMPT_ADDR 111 // 1 byte: that session's attempt number
#define MOUNT_REF_ADDR 112          // 12 bytes: upright vector (3 floats) of the last
                                    // normal/brake/bump session = how the unit is mounted
#define MOUNT_REF_FLAG_ADDR 124     // 1 byte: 0xA5 = MOUNT_REF_ADDR holds a valid vector

// ============================================================
// SYSTEM TIMING / SAFETY LIMITS
// ============================================================
#define WDT_TIMEOUT_SEC 8               // hardware watchdog — force-reboots if loop() ever stalls
#define INIT_RETRY_ATTEMPTS 3           // startup checks get this many tries before declaring failure
#define INIT_RETRY_DELAY_MS 500
#define HEARTBEAT_INTERVAL_MS 15000     // quiet "still alive" tick during the test window
#define SAMPLE_INTERVAL_US 10000UL      // 100 Hz, the BNO055 fusion output rate
#define UPRIGHT_SAMPLES 50              // 0.5 s average = the session's upright reference
#define UPRIGHT_STILL_DEG 3.0f          // gravity direction wandering more than this during the
                                        // capture = bike not still, retry. Direction, not gyro:
                                        // a running engine shakes the unit (gyro > 5 dps every
                                        // time in the first field sessions) but does not tilt it.
#define UPRIGHT_MAX_TRIES 10            // ~5 s of retries, then capture anyway (flagged)
#define START_DELAY_MS 15000UL          // wait after power-on: time to get the bike upright and still.
                                        // Nothing is written during it, so unplugging then skips
                                        // the session cleanly (no attempt number used).

// 30 s per session: many short, clean sessions give better data than
// a few long ones with more chance of unplanned movement.
const unsigned long TEST_DURATION_MS = 30000;

// Storage budget: a row is ~97 bytes (measured, bench session #94);
// 110 leaves margin for long numbers. A session is only started
// when all of it fits.
const size_t ROW_BYTES_ESTIMATE = 110;
const size_t SESSION_ROWS = TEST_DURATION_MS / (SAMPLE_INTERVAL_US / 1000);
const size_t SESSION_BYTES_NEEDED = SESSION_ROWS * ROW_BYTES_ESTIMATE + 4096;
const size_t BUFFER_ROWS = SESSION_ROWS + 50;   // RAM buffer for the window (+ slack)

// Physics constants
const float G = 9.80665f;
// In fusion modes the BNO055 accelerometer range is fixed at +/-4 g.
// A raw axis at/above this means the real value was cut off.
const float CLIP_LIMIT_MS2 = 0.98f * 4.0f * G;

const char* SESSION_FILE = "/thr_sessions.csv";
const char* sessionLabels[] = {"normal", "brake", "bump", "crash", "bench", "tilt", "parking"};
const float MOUNT_MOVED_DEG = 10.0f;   // upright vector this far from the last ride session = mount moved
char noteClean[25];                    // sessionNote, max 24 chars, commas replaced
const int CATEGORY_COUNT = sizeof(sessionLabels) / sizeof(sessionLabels[0]);
char logPath[40];   // /thr_<label>_<attempt>.csv, set once the attempt number is known

int attemptNumber = 1;
String resetReasonLabel = "unknown";  // written into the session summary, flags sessions
                                       // that were interrupted by an unexpected reboot
uint8_t calSys = 0, calGyro = 0, calAccel = 0, calMag = 0;
uint8_t calSysEnd = 0, calGyroEnd = 0, calAccelEnd = 0;   // read again when the window ends

// One sample in the BNO055's own units, so storing it is lossless:
// linear accel and gravity 1 LSB = 0.01 m/s^2, gyro 1 LSB = 1/16 deg/s
// (the Adafruit library divides by exactly these). 24 bytes per row.
struct Sample {
  uint32_t tMs;
  int16_t lin[3], gyr[3], grav[3];
  uint8_t flags;   // bit 0 = clipped, bit 1 = late
};
const uint8_t FLAG_CLIPPED = 1, FLAG_LATE = 2;
Sample* samples = nullptr;   // BUFFER_ROWS long, allocated in setup()

// Values the field firmware decides with (see derive()). Declared up
// here so the Arduino IDE's auto-generated prototypes can see it.
struct Derived { float linearG, verticalG, horizontalG, gyroDps, tiltDeg; };

// ---- Session state ----
unsigned long testStartMs = 0;
unsigned long testStartUs = 0;
unsigned long nextSampleUs = 0;
unsigned long lastHeartbeat = 0;
unsigned long ledOffAt = 0;
bool testActive = false;
bool testComplete = false;

unsigned long readingCount = 0;
unsigned long lateSamples = 0;      // samples that arrived late
unsigned long missedSlots = 0;      // 10 ms slots skipped entirely
unsigned long clippedSamples = 0;
unsigned long failedReads = 0;
int zeroReadingStreak = 0;
const int ZERO_STREAK_FAILURE_LIMIT = 10;  // consecutive bad reads = likely I2C failure

// Upright reference (unit vector of gravity at session start)
float upX = 0, upY = 0, upZ = 1;
bool uprightStill = true;      // false = captured while moving (tilt values less reliable)
float restBiasG = 0;           // mean linear g during the upright capture (sensor offset at rest)
float restNoiseG = 0;          // its standard deviation (noise / vibration at rest)

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

// Derived values, computed exactly like aviso-iot/imu.cpp. Used live
// (session peaks) and again when the buffered window is written out.
Derived derive(float lx, float ly, float lz, float gx, float gy, float gz,
               float vx, float vy, float vz) {
  const float gravNorm = norm3(vx, vy, vz);
  const float ux = vx / gravNorm, uy = vy / gravNorm, uz = vz / gravNorm;
  const float linNorm = norm3(lx, ly, lz);
  const float vertical = lx * ux + ly * uy + lz * uz;
  const float horizontal = sqrtf(fmaxf(0.0f, linNorm * linNorm - vertical * vertical));
  const float dot = fmaxf(-1.0f, fminf(1.0f, ux * upX + uy * upY + uz * upZ));
  return { linNorm / G, fabsf(vertical) / G, horizontal / G, norm3(gx, gy, gz),
           acosf(dot) * 180.0f / PI };
}

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
// No format-on-fail: a mount failure must never silently erase
// sessions that were not retrieved yet (format-littlefs does that
// on purpose, after retrieval).
bool tryLittleFsBegin() { return LittleFS.begin(false); }

size_t freeBytes() { return LittleFS.totalBytes() - LittleFS.usedBytes(); }

// Space the listed files and folders should need (4 KB blocks: 2 per
// directory incl. root, ~size/4000 per file). Same estimate as the
// retrieval / cleanup sketches.
size_t expectedUsedBytes(const char* path) {
  const size_t FS_BLOCK = 4096;
  size_t bytes = 2 * FS_BLOCK;
  File dir = LittleFS.open(path);
  if (!dir || !dir.isDirectory()) return bytes;
  for (File f = dir.openNextFile(); f; f = dir.openNextFile()) {
    if (f.isDirectory()) {
      String sub = f.path();
      f.close();
      bytes += expectedUsedBytes(sub.c_str());
    } else {
      bytes += ((f.size() + 3999) / 4000) * FS_BLOCK;
      f.close();
    }
  }
  dir.close();
  return bytes;
}

// Space used by nothing listed = damaged filesystem. Writing onto it
// risks the new session too, so the session is refused.
bool storageLooksHealthy() {
  const size_t used = LittleFS.usedBytes(), expected = expectedUsedBytes("/");
  if (used <= expected + 64 * 1024) return true;
  Serial.printf("[ERROR] ~%u bytes of storage are used by nothing listed (damaged filesystem).\n",
                (unsigned)(used - expected));
  return false;
}

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
// The same 0.5 s also gives the at-rest linear reading: its mean is
// the sensor's offset this session (~0.035 g on the bench) and its
// spread is the vibration level (engine idling shows up here).
// If the bike tilts during the 0.5 s (any gravity sample more than
// UPRIGHT_STILL_DEG from the average direction) it chirps and tries
// again; after UPRIGHT_MAX_TRIES it keeps the last capture and flags it.
void captureUprightReference() {
  for (int attempt = 1; attempt <= UPRIGHT_MAX_TRIES; attempt++) {
    float sx = 0, sy = 0, sz = 0;
    float linSum = 0, linSumSq = 0;
    float dirX[UPRIGHT_SAMPLES], dirY[UPRIGHT_SAMPLES], dirZ[UPRIGHT_SAMPLES];
    int n = 0;
    for (int i = 0; i < UPRIGHT_SAMPLES; i++) {
      imu::Vector<3> grav = bno.getVector(Adafruit_BNO055::VECTOR_GRAVITY);
      imu::Vector<3> lin = bno.getVector(Adafruit_BNO055::VECTOR_LINEARACCEL);
      const float gn = norm3(grav.x(), grav.y(), grav.z());
      if (gn > 8.0f) {
        sx += grav.x(); sy += grav.y(); sz += grav.z();
        dirX[n] = grav.x() / gn; dirY[n] = grav.y() / gn; dirZ[n] = grav.z() / gn;
        n++;
        const float linG = norm3(lin.x(), lin.y(), lin.z()) / G;
        linSum += linG; linSumSq += linG * linG;
      }
      delay(SAMPLE_INTERVAL_US / 1000);
    }
    esp_task_wdt_reset();
    float len = norm3(sx, sy, sz);
    if (n == 0 || len < 1.0f) haltWithFailureSignal("Could not read gravity for the upright reference.");
    upX = sx / len; upY = sy / len; upZ = sz / len;

    // Largest angle between any sample and the average direction
    float worstDeg = 0;
    for (int i = 0; i < n; i++) {
      const float dot = fmaxf(-1.0f, fminf(1.0f, dirX[i] * upX + dirY[i] * upY + dirZ[i] * upZ));
      worstDeg = fmaxf(worstDeg, acosf(dot) * 180.0f / PI);
    }
    restBiasG = linSum / n;
    restNoiseG = sqrtf(fmaxf(0.0f, linSumSq / n - restBiasG * restBiasG));
    uprightStill = worstDeg <= UPRIGHT_STILL_DEG;
    if (uprightStill) return;

    Serial.printf("[INIT] Bike tilting during upright capture (%.1f deg, try %d/%d) — hold it upright.\n",
                  worstDeg, attempt, UPRIGHT_MAX_TRIES);
    tone(BUZZER_PIN, 1700, 60);
    digitalWrite(LED_PIN, HIGH); delay(60); digitalWrite(LED_PIN, LOW);
  }
  Serial.println("[WARNING] Never still — upright captured while moving (upright_still=0).");
}

// ============================================================
// SESSION SUMMARY (one row per session, written at the end)
// ============================================================
// New columns are only ever added at the END, so rows from older
// versions still line up with the first columns of this header.
const char* SESSION_HEADER =
  "version,session_label,attempt,status,rows,late_samples,missed_slots,clipped_samples,"
  "failed_reads,peak_linear_g,peak_vertical_g,peak_horizontal_g,peak_gyro_dps,max_tilt_deg,"
  "cal_sys,cal_gyro,cal_accel,reset_reason,"
  "duration_ms,effective_hz,upright_still,"
  "t_peak_linear_ms,t_peak_vertical_ms,t_peak_horizontal_ms,t_peak_gyro_ms,t_max_tilt_ms,"
  "cal_sys_end,cal_gyro_end,cal_accel_end,rest_bias_g,rest_noise_g,"
  "up_x,up_y,up_z,note";

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
  char line[480];
  snprintf(line, sizeof(line),
           "%s,%s,%d,%s,%lu,%lu,%lu,%lu,%lu,%.3f,%.3f,%.3f,%.1f,%.1f,%u,%u,%u,%s,"
           "%lu,%.1f,%d,%lu,%lu,%lu,%lu,%lu,%u,%u,%u,%.4f,%.4f,%.4f,%.4f,%.4f,%s",
           GATHER_VERSION, sessionLabels[sessionMode], attemptNumber, status,
           readingCount, lateSamples, missedSlots, clippedSamples, failedReads,
           peakLinearG, peakVerticalG, peakHorizontalG, peakGyroDps, maxTiltDeg,
           calSys, calGyro, calAccel, resetReasonLabel.c_str(),
           lastRowMs, effectiveHz, uprightStill ? 1 : 0,
           tPeakLinear, tPeakVertical, tPeakHorizontal, tPeakGyro, tMaxTilt,
           calSysEnd, calGyroEnd, calAccelEnd, restBiasG, restNoiseG,
           upX, upY, upZ, noteClean);
  writeSummaryLine(line);
}

// The previous power-up stopped mid-session (power lost / unplugged
// early / crash rig knocked the battery out). Its samples were only in
// RAM, so at most a partly written /thr_<label>_<attempt>.csv exists.
// Add a summary row so analysis can find and drop it. Peaks are
// unknown, left empty. Called after LittleFS is up.
void recordInterruptedSession() {
  const byte flag = EEPROM.read(IN_PROGRESS_ADDR);
  if (flag == 0 || flag == 255) return;
  const int category = flag - 1;
  const int attempt = EEPROM.read(IN_PROGRESS_ATTEMPT_ADDR);
  if (category >= 0 && category < CATEGORY_COUNT) {
    char line[200];
    snprintf(line, sizeof(line), "%s,%s,%d,INTERRUPTED,,,,,,,,,,,,,,%s,,,,,,,,,,,,,,,,,",
             GATHER_VERSION, sessionLabels[category], attempt, resetReasonLabel.c_str());
    writeSummaryLine(line);
    Serial.printf("[WARNING] Previous session (%s #%d) was cut off — recorded as INTERRUPTED.\n",
                  sessionLabels[category], attempt);
  }
  EEPROM.write(IN_PROGRESS_ADDR, 0);
  EEPROM.commit();
}

// sessionNote -> noteClean: max 24 chars, commas and line breaks
// replaced so the summary CSV keeps its columns.
void prepareNote() {
  size_t n = 0;
  for (const char* c = sessionNote; *c && n < sizeof(noteClean) - 1; c++) {
    noteClean[n++] = (*c == ',' || *c == '\n' || *c == '\r') ? ';' : *c;
  }
  noteClean[n] = '\0';
}

// Ride sessions (normal / brake / bump) are recorded with the unit in
// its normal mount, so their upright vector shows how it is mounted.
// Compares with the last one and warns if the mount moved; tilt,
// parking, crash and bench are skipped (not in the normal position).
bool isRideCategory() { return sessionMode <= 2; }

void checkMountPosition() {
  if (!isRideCategory()) return;
  if (EEPROM.read(MOUNT_REF_FLAG_ADDR) == 0xA5) {
    float ref[3];
    EEPROM.get(MOUNT_REF_ADDR, ref);
    const float dot = fmaxf(-1.0f, fminf(1.0f, ref[0] * upX + ref[1] * upY + ref[2] * upZ));
    const float movedDeg = acosf(dot) * 180.0f / PI;
    if (movedDeg > MOUNT_MOVED_DEG) {
      Serial.printf("[WARNING] Mount moved: upright is %.1f deg from the last ride session.\n", movedDeg);
      Serial.println("[WARNING] Brake direction depends on the mount. Put the unit back the same way,");
      Serial.println("[WARNING] or note it — this session is still recorded (up_x/y/z in the summary).");
      signalLowStorageWarning();   // same 3 heads-up beeps
    } else {
      Serial.printf("[INIT] Mount position matches the last ride session (%.1f deg).\n", movedDeg);
    }
  }
  const float now[3] = {upX, upY, upZ};
  EEPROM.put(MOUNT_REF_ADDR, now);
  EEPROM.write(MOUNT_REF_FLAG_ADDR, 0xA5);
  EEPROM.commit();
}

void setInProgress(bool active) {
  EEPROM.write(IN_PROGRESS_ADDR, active ? sessionMode + 1 : 0);
  if (active) EEPROM.write(IN_PROGRESS_ATTEMPT_ADDR, (byte)attemptNumber);
  EEPROM.commit();
}

// Writes the buffered window to this session's own CSV (same columns
// as every earlier version). Returns false if the write failed
// part-way (storage full or damaged).
bool saveSessionLog() {
  const bool isNew = !LittleFS.exists(logPath);
  File file = LittleFS.open(logPath, "a");
  if (!file) return false;
  if (isNew) {
    file.println("session_label,attempt,reading,t_ms,linX,linY,linZ,gyroX,gyroY,gyroZ,gravX,gravY,gravZ,"
                 "linearG,verticalG,horizontalG,gyroDps,tiltDeg,clipped,late");
  }
  bool ok = true;
  char row[256];
  for (unsigned long i = 0; i < readingCount; i++) {
    const Sample& s = samples[i];
    const float lx = s.lin[0] / 100.0f, ly = s.lin[1] / 100.0f, lz = s.lin[2] / 100.0f;
    const float gx = s.gyr[0] / 16.0f, gy = s.gyr[1] / 16.0f, gz = s.gyr[2] / 16.0f;
    const float vx = s.grav[0] / 100.0f, vy = s.grav[1] / 100.0f, vz = s.grav[2] / 100.0f;
    const Derived d = derive(lx, ly, lz, gx, gy, gz, vx, vy, vz);
    snprintf(row, sizeof(row),
             "%s,%d,%lu,%lu,%.2f,%.2f,%.2f,%.1f,%.1f,%.1f,%.2f,%.2f,%.2f,%.3f,%.3f,%.3f,%.1f,%.1f,%d,%d",
             sessionLabels[sessionMode], attemptNumber, i + 1, (unsigned long)s.tMs,
             lx, ly, lz, gx, gy, gz, vx, vy, vz,
             d.linearG, d.verticalG, d.horizontalG, d.gyroDps, d.tiltDeg,
             (s.flags & FLAG_CLIPPED) ? 1 : 0, (s.flags & FLAG_LATE) ? 1 : 0);
    if (file.println(row) == 0) { ok = false; break; }
    if (i % 100 == 0) esp_task_wdt_reset();
  }
  file.close();
  return ok;
}

void endSession(const char* status) {
  testActive = false;
  testComplete = true;

  // Calibration status after 30 s of motion — the start value is
  // always 0 because loading the offsets resets the status registers.
  uint8_t calMagEnd = 0;
  bno.getCalibration(&calSysEnd, &calGyroEnd, &calAccelEnd, &calMagEnd);

  // LED on solid while saving = "not yet safe to unplug".
  digitalWrite(LED_PIN, HIGH);
  Serial.printf("[SESSION] Window done. Saving %lu rows to %s ...\n", readingCount, logPath);
  const unsigned long saveStartMs = millis();
  if (!saveSessionLog()) {
    Serial.println("[ERROR] Writing the session log failed part-way. Session marked INCOMPLETE_STORAGE.");
    status = "INCOMPLETE_STORAGE";
  }
  Serial.printf("[SESSION] Saved in %lu ms.\n", millis() - saveStartMs);
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
  Serial.printf("[SUMMARY] calibration at end: sys=%u gyro=%u accel=%u (0-3)\n", calSysEnd, calGyroEnd, calAccelEnd);
  Serial.printf("[SUMMARY] at rest before the window: offset %.4f g, spread %.4f g\n", restBiasG, restNoiseG);
  Serial.println("[SESSION] Complete. Safe to unplug.");
  Serial.println("-------------------------------------");
}

// ============================================================
// SETUP
// ============================================================
void setup() {
  Serial.begin(115200);
  delay(500);
  // No "wait for Serial": in the field there is no laptop attached.

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
  if (!retryCheck(tryLittleFsBegin)) {
    haltWithFailureSignal("LittleFS mount failed. Retrieve what you can, then run format-littlefs.");
  }
  if (!storageLooksHealthy()) {
    haltWithFailureSignal("Storage damaged. Run retrieval-littlefs, then format-littlefs.");
  }
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

  // --- RAM buffer for the whole window (no flash I/O while sampling) ---
  samples = (Sample*)malloc(BUFFER_ROWS * sizeof(Sample));
  if (!samples) haltWithFailureSignal("Could not allocate the RAM sample buffer.");
  Serial.printf("[INIT] Sample buffer: %u rows, %u bytes.\n",
                (unsigned)BUFFER_ROWS, (unsigned)(BUFFER_ROWS * sizeof(Sample)));

  // --- Pre-test sensor sanity check ---
  delay(300);
  if (!retryCheck(sensorSanityCheckOnce)) haltWithFailureSignal("Sensor sanity check failed after retries.");
  Serial.println("[INIT] Sensor sanity check passed.");

  bno.getCalibration(&calSys, &calGyro, &calAccel, &calMag);
  Serial.printf("[INIT] Calibration status: sys=%u gyro=%u accel=%u (0-3)\n", calSys, calGyro, calAccel);

  // --- Attempt numbering, persists across power cycles ---
  attemptNumber = getAndIncrementAttempt(sessionMode);
  snprintf(logPath, sizeof(logPath), "/thr_%s_%d.csv", sessionLabels[sessionMode], attemptNumber);
  Serial.println("-------------------------------------");
  prepareNote();
  Serial.printf("[SESSION] Category: %s  |  Attempt #%d  |  Note: %s\n", sessionLabels[sessionMode],
                attemptNumber, noteClean[0] ? noteClean : "(none)");
  Serial.printf("[SESSION] Confirming via %d tone(s)/blink(s)...\n", sessionMode + 1);

  // --- Signal: category confirmed, audio and visual in agreement ---
  signalCategoryConfirm(sessionMode + 1);

  // --- Upright reference: bike upright and steady (engine may run) ---
  delay(300);
  captureUprightReference();
  Serial.printf("[INIT] Upright reference captured (%.3f, %.3f, %.3f).\n", upX, upY, upZ);
  Serial.printf("[INIT] At rest: linear %.4f g (offset), spread %.4f g (noise/vibration).\n",
                restBiasG, restNoiseG);
  checkMountPosition();

  // --- Signal: test window starting ---
  signalStartingNow();

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
    endSession(failedReads > 0 ? "OK_WITH_READ_ERRORS" : "OK");
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

  // Buffer sized for the full window; only reachable if timing goes
  // badly wrong, but never write past the end.
  if (readingCount >= BUFFER_ROWS) {
    endSession(failedReads > 0 ? "OK_WITH_READ_ERRORS" : "OK");
    return;
  }

  // --- Derived values (session peaks + live telemetry) ---
  const Derived d = derive(lin.x(), lin.y(), lin.z(), gyr.x(), gyr.y(), gyr.z(),
                           grav.x(), grav.y(), grav.z());
  const float linearG = d.linearG, verticalG = d.verticalG, horizontalG = d.horizontalG;
  const float gyroDps = d.gyroDps, tiltDeg = d.tiltDeg;

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

  // --- Buffer the sample (RAM only; written to flash after the window) ---
  Sample& s = samples[readingCount];
  s.tMs = tMs;
  s.lin[0] = (int16_t)lroundf(lin.x() * 100.0f);
  s.lin[1] = (int16_t)lroundf(lin.y() * 100.0f);
  s.lin[2] = (int16_t)lroundf(lin.z() * 100.0f);
  s.gyr[0] = (int16_t)lroundf(gyr.x() * 16.0f);
  s.gyr[1] = (int16_t)lroundf(gyr.y() * 16.0f);
  s.gyr[2] = (int16_t)lroundf(gyr.z() * 16.0f);
  s.grav[0] = (int16_t)lroundf(grav.x() * 100.0f);
  s.grav[1] = (int16_t)lroundf(grav.y() * 100.0f);
  s.grav[2] = (int16_t)lroundf(grav.z() * 100.0f);
  s.flags = (clipped ? FLAG_CLIPPED : 0) | (late ? FLAG_LATE : 0);
  readingCount++;

  // --- Live telemetry (Serial Monitor only), 10 Hz so it never slows sampling ---
  if (readingCount % 10 == 0) {
    Serial.printf("[%s #%d] g=%.2f vert=%.2f horiz=%.2f gyro=%.0f tilt=%.1f%s\n",
                  sessionLabels[sessionMode], attemptNumber, linearG, verticalG, horizontalG,
                  gyroDps, tiltDeg, clipped ? " CLIPPED" : "");
  }
}
