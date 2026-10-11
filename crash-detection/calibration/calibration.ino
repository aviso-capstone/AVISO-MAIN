// ============================================================
// AVISO — BNO055 Calibration  (run once per physical sensor)
// ------------------------------------------------------------
// Saves the sensor's calibration offsets to EEPROM. Both
// threshold-gathering and the field firmware (aviso-iot) load
// them at boot and refuse to run without them.
// Runs in IMU mode (accelerometer + gyroscope, no magnetometer),
// like every other AVISO sketch, so only gyro and accel need
// to reach level 3.
//
// HOW TO CALIBRATE (Serial Monitor at 115200 helps, not required)
//   1. Gyro: leave the unit completely still on a table ~5 s.
//      1 short beep = gyro at 3.
//   2. Accel: hold the box still in 6 orientations, 3-5 s each:
//      flat, upside down and on each of its 4 sides (the faces of
//      a die). Move slowly between them.
//      2 short beeps = accel at 3.
//   3. Both at 3 for 2 s -> saved automatically.
//      3 rising tones = saved, safe to unplug.
//   Alarm after 5 minutes = not calibrated, nothing saved.
//   Reset and try again with steadier holds.
//
// EEPROM
//   [0]      0x55 = calibration saved
//   [1..22]  adafruit_bno055_offsets_t
//   An existing calibration is replaced. Everything else
//   (threshold-gathering counters and flags at 100-124) is kept.
// ============================================================

#include <Wire.h>
#include <Adafruit_Sensor.h>
#include <Adafruit_BNO055.h>
#include <utility/imumaths.h>
#include <EEPROM.h>

Adafruit_BNO055 bno = Adafruit_BNO055(55, 0x29);

#define LED_PIN 2
#define BUZZER_PIN 4

#define EEPROM_SIZE 512
#define CALIBRATION_FLAG_ADDR 0
#define CALIBRATION_DATA_ADDR 1

const unsigned long CALIBRATION_TIMEOUT_MS = 5UL * 60UL * 1000UL;
const unsigned long STABLE_CONFIRM_MS = 2000;  // levels must hold at 3 this long before saving

void beep(int freq, int ms) {
  tone(BUZZER_PIN, freq, ms);
  digitalWrite(LED_PIN, HIGH); delay(ms); digitalWrite(LED_PIN, LOW);
  delay(150);
}

void signalFailure() {
  for (int i = 0; i < 5; i++) { beep(2000, 150); beep(1700, 150); }
}

void printOffsets(const adafruit_bno055_offsets_t& o) {
  Serial.printf("  accel offset: %d %d %d   radius %d\n", o.accel_offset_x, o.accel_offset_y, o.accel_offset_z, o.accel_radius);
  Serial.printf("  gyro offset:  %d %d %d\n", o.gyro_offset_x, o.gyro_offset_y, o.gyro_offset_z);
  Serial.printf("  mag offset:   %d %d %d   radius %d (unused in IMU mode)\n", o.mag_offset_x, o.mag_offset_y, o.mag_offset_z, o.mag_radius);
}

void setup() {
  Serial.begin(115200);
  delay(500);
  pinMode(LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);

  Serial.println("=====================================");
  Serial.println("AVISO BNO055 Calibration");
  Serial.println("=====================================");

  EEPROM.begin(EEPROM_SIZE);

  if (!bno.begin(OPERATION_MODE_IMUPLUS)) {
    Serial.println("[FATAL] BNO055 not detected. Check wiring / I2C address 0x29.");
    while (1) { signalFailure(); delay(1500); }
  }
  bno.setExtCrystalUse(true);
  delay(500);

  if (EEPROM.read(CALIBRATION_FLAG_ADDR) == 0x55) {
    adafruit_bno055_offsets_t old;
    EEPROM.get(CALIBRATION_DATA_ADDR, old);
    Serial.println("[INFO] A calibration is already saved; it will be REPLACED:");
    printOffsets(old);
  }

  Serial.println("[STEP 1] Keep the unit still (gyro).");
  Serial.println("[STEP 2] Then hold 6 orientations, 3-5 s each (accel).");
  beep(700, 900);  // "starting"

  const unsigned long start = millis();
  unsigned long lastPrint = 0;
  unsigned long stableSince = 0;
  bool gyroAnnounced = false, accelAnnounced = false;

  while (true) {
    uint8_t sys, gyro, accel, mag;
    bno.getCalibration(&sys, &gyro, &accel, &mag);

    if (gyro == 3 && !gyroAnnounced) { beep(2000, 120); gyroAnnounced = true; }
    if (accel == 3 && !accelAnnounced) { beep(2000, 120); beep(2000, 120); accelAnnounced = true; }

    if (millis() - lastPrint >= 500) {
      Serial.printf("[CAL] sys=%u gyro=%u accel=%u   (need gyro=3 and accel=3)\n", sys, gyro, accel);
      lastPrint = millis();
    }

    if (gyro == 3 && accel == 3) {
      if (stableSince == 0) stableSince = millis();
      if (millis() - stableSince >= STABLE_CONFIRM_MS) break;
    } else {
      stableSince = 0;
    }

    if (millis() - start > CALIBRATION_TIMEOUT_MS) {
      Serial.println("[FAIL] Not calibrated within 5 minutes. Nothing was saved. Reset and try again.");
      while (1) { signalFailure(); delay(1500); }
    }
    delay(100);
  }

  adafruit_bno055_offsets_t offsets;
  if (!bno.getSensorOffsets(offsets)) {   // switches to CONFIG mode and back internally
    Serial.println("[FAIL] Could not read offsets. Nothing was saved.");
    while (1) { signalFailure(); delay(1500); }
  }

  EEPROM.put(CALIBRATION_DATA_ADDR, offsets);
  EEPROM.write(CALIBRATION_FLAG_ADDR, 0x55);
  EEPROM.commit();

  Serial.println("[DONE] Calibration saved to EEPROM:");
  printOffsets(offsets);
  Serial.println("[DONE] Safe to unplug. Upload threshold-gathering next.");
  beep(1000, 200); beep(1500, 200); beep(2000, 300);
}

void loop() {
  delay(1000);
}
