// ============================================================
// AVISO — Buzzer Loudness Test  (30 s, buzzer only)
// ------------------------------------------------------------
// Finds the frequency at which this passive buzzer sounds
// loudest (its resonance). tone() sets pitch, not volume, so the
// only way to get more sound is to play at that frequency.
// No sensor, storage or calibration is used.
//
//   0-10 s   sweep 300-5000 Hz: listen where it gets louder
//   10-22 s  hold 2000 / 2500 / 3000 / 3500 Hz, 3 s each
//   22-30 s  hold 3000 Hz
//
// Use the loudest Phase 2 frequency for alert tones. Result so
// far: 2000 Hz (used by threshold-gathering and calibration).
// ============================================================

#define BUZZER_PIN 4

const unsigned long TOTAL_TEST_MS = 30000;
unsigned long testStart = 0;
bool testRunning = true;
bool testAnnouncedDone = false;

void setup() {
  Serial.begin(115200);
  delay(500);

  pinMode(BUZZER_PIN, OUTPUT);

  if (Serial) {
    Serial.println("=====================================");
    Serial.println("AVISO Buzzer Max-Volume Diagnostic");
    Serial.println("=====================================");
    Serial.println("[INFO] tone() controls pitch/duration only, not volume.");
    Serial.println("[INFO] This test finds the LOUDEST-SOUNDING frequency by");
    Serial.println("[INFO] sweeping for the buzzer's physical resonant point.");
    Serial.println("[INFO] Listen carefully — note which section sounds loudest.");
    Serial.println("-------------------------------------");
  }

  testStart = millis();

  // --- Phase 1 (0-10s): slow sweep across a wide range, listen
  // for where it noticeably gets louder — that's the resonant zone ---
  if (Serial) Serial.println("[PHASE 1] Sweeping 300Hz - 5000Hz (10 seconds)...");
  for (int f = 300; f <= 5000 && (millis() - testStart) < 10000; f += 40) {
    tone(BUZZER_PIN, f);
    delay(25);
  }
  noTone(BUZZER_PIN);

  // --- Phase 2 (10-22s): hold on the most common resonant sweet
  // spots for small passive buzzers, one at a time, 3s each,
  // with a clear gap so you can tell them apart by ear ---
  int candidateFreqs[] = {2000, 2500, 3000, 3500};
  const char* freqLabels[] = {"2000Hz", "2500Hz", "3000Hz", "3500Hz"};

  for (int i = 0; i < 4 && (millis() - testStart) < 22000; i++) {
    if (Serial) Serial.println("[PHASE 2] Holding " + String(freqLabels[i]) + " for 3 seconds...");
    tone(BUZZER_PIN, candidateFreqs[i]);
    delay(2700);
    noTone(BUZZER_PIN);
    delay(300);  // clear silent gap between candidates
  }

  // --- Phase 3 (remaining time to 30s): sustained continuous
  // tone at the most commonly loudest default (3000Hz) as the
  // final confirmation hold ---
  unsigned long elapsed = millis() - testStart;
  unsigned long remaining = (elapsed < TOTAL_TEST_MS) ? (TOTAL_TEST_MS - elapsed) : 0;

  if (Serial) Serial.println("[PHASE 3] Final sustained hold at 3000Hz for " + String(remaining / 1000) + "s...");
  if (remaining > 0) {
    tone(BUZZER_PIN, 3000);
    delay(remaining);
    noTone(BUZZER_PIN);
  }

  if (Serial) {
    Serial.println("-------------------------------------");
    Serial.println("[DONE] 30-second diagnostic complete.");
    Serial.println("[INFO] Whichever Phase 2 frequency sounded loudest to you");
    Serial.println("[INFO] is your buzzer's real resonant sweet spot — use that");
    Serial.println("[INFO] frequency for your actual alert/failure signals.");
  }

  testRunning = false;
}

void loop() {
  // Test runs entirely in setup() since it's a fixed, one-time
  // 30-second sequence. Nothing repeats here.
  delay(1000);
}