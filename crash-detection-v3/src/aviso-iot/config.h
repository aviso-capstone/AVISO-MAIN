// ============================================================
// AVISO IoT — configuration
// ------------------------------------------------------------
// Every tunable number lives here. The DETECTION THRESHOLDS are
// PLACEHOLDERS until the threshold-gathering sessions (normal /
// hard brake / bump / crash) are analysed — replace them with the
// measured values, nothing else needs to change.
// ============================================================
#pragma once

#define FIRMWARE_VERSION "1.0.0"

// ---------------- Hardware ----------------
#define LED_PIN 2
#define BUZZER_PIN 4
#define BNO055_I2C_ADDRESS 0x29   // confirmed via I2C scanner on this unit
#define BNO055_SENSOR_ID 55

// Teammate's calibration sketch stores the BNO055 offsets here (same layout).
#define EEPROM_SIZE 512
#define CALIBRATION_FLAG_ADDR 0   // 0x55 = valid offsets saved
#define CALIBRATION_DATA_ADDR 1

// ---------------- Timing ----------------
#define SAMPLE_INTERVAL_MS 10          // 100 Hz, the BNO055 fusion output rate
#define TELEMETRY_INTERVAL_MS 100      // 10 Hz live data to the app
#define HEARTBEAT_INTERVAL_MS 30000    // tells the server our hotspot IP
#define WDT_TIMEOUT_SEC 20             // above the worst-case HTTPS call + Wi-Fi connect

// ---------------- Detection thresholds (PLACEHOLDERS) ----------------
// Units: g = 9.80665 m/s^2 of LINEAR acceleration (gravity removed, so 0 g
// at rest), gyro in degrees/second, tilt in degrees from the saved upright.
//
// NOTE: in fusion modes the BNO055 accelerometer range is fixed at +/-4 g,
// so impacts above ~4 g read as ~4 g. Keep IMPACT_G below 4.
const float IMPACT_G                   = 3.0f;   // hit strong enough to start a crash check
const float IMPACT_GYRO_DPS            = 250.0f; // or a violent rotation
const float FALL_TILT_DEG              = 60.0f;  // bike lying on its side
const float UPRIGHT_TILT_DEG           = 30.0f;  // back up again (side stand is ~10-15)
const unsigned long IMPACT_TO_FALL_MS  = 3000;   // the fall must follow the hit within this
const unsigned long FALL_CONFIRM_MS    = 3000;   // fallen + still this long = crash
const unsigned long TIPOVER_CONFIRM_MS = 5000;   // fallen with no detected hit (slow slide)
const float STILL_GYRO_DPS             = 30.0f;  // "not moving" while lying down
const unsigned long RESET_UPRIGHT_MS   = 3000;   // upright this long after a crash re-arms detection

const float BUMP_VERTICAL_G            = 0.8f;   // up/down jolt with no fall
const unsigned long BUMP_COOLDOWN_MS   = 1000;

const float BRAKE_HORIZONTAL_G         = 0.45f;  // forward/back force, low rotation
const float BRAKE_MAX_GYRO_DPS         = 60.0f;
const unsigned long BRAKE_MIN_MS       = 200;    // must last this long (not a single jolt)
const unsigned long BRAKE_COOLDOWN_MS  = 2000;

const unsigned long STATE_DISPLAY_MS   = 1000;   // how long bump/brake shows in live data

// Every NORMAL_SUMMARY_MS without any other event, a "normal" record with the
// window's highest values is sent. Those records show how high the readings
// go in ordinary riding, i.e. where each threshold must sit above.
const unsigned long NORMAL_SUMMARY_MS  = 10000;

// ---------------- Crash handling ----------------
const unsigned long CRASH_RESEND_MS     = 1000;   // repeat the crash to the app until it acks
const unsigned long BACKUP_SOS_AFTER_MS = 25000;  // app silent this long -> device reports itself
const unsigned long BACKUP_RETRY_MS     = 10000;
const int BACKUP_MAX_ATTEMPTS           = 6;
const unsigned long ALARM_MAX_MS        = 120000; // buzzer alarm stops by itself after 2 min

// ---------------- Black box ----------------
#define BLACKBOX_SAMPLES 500       // 5 s at 100 Hz, before AND after a crash
#define BLACKBOX_MAX_FILES 10      // oldest deleted first

// ---------------- Network ----------------
#define MAX_SAVED_NETWORKS 10      // team members' phone hotspots; the oldest is replaced when full
#define SETUP_AP_PASSWORD "aviso1234"
#define SETUP_AP_AFTER_MS 60000    // no hotspot found this long -> open the setup network
#define SETUP_AP_LINGER_MS 60000   // keep it open this long after joining a hotspot
#define WIFI_CONNECT_TIMEOUT_MS 10000  // phone hotspots often need 4-8 s (join + DHCP); stays under WDT_TIMEOUT_SEC
#define WS_PORT 81                 // WebSocket for the rider app
#define HTTP_PORT 80               // setup page + black box download
#define HTTP_TIMEOUT_MS 8000
