// ============================================================
// AVISO — Storage Cleanup
// ------------------------------------------------------------
// Deletes the threshold-gathering logs and the aviso-iot black-box
// recordings so the next batch of sessions has room.
//
// HOW TO USE
//   Only after retrieval-littlefs AND after the saved CSVs passed
//   crash-detection/tools/check_thr_csv.py.
//   1. Upload, open the Serial Monitor at 115200 baud with the
//      line ending set to "Newline".
//   2. Check the list of files it is about to delete.
//   3. Type DELETE and press Enter. Anything else deletes nothing,
//      so uploading this sketch by mistake is harmless: unplug.
//   Calibration and attempt counters (EEPROM) are not touched.
//
// WHAT IS DELETED
//   /thr_*.csv     every session log and the session summary
//   /bb/*          black-box files (the /bb folder itself stays)
//   older v4-v6 log files, if still on the unit
//   If space is used by nothing listed (damaged filesystem),
//   deleting cannot recover it: it says so; run format-littlefs.
//
// BUZZER (passive buzzer on BUZZER_PIN, same sounds as retrieval)
//   1 short beep           started
//   alternating warning    filesystem damaged
//   short tick every 5 s   waiting for DELETE
//   very short high tick   one file deleted
//   3 low beeps            one delete failed
//   2 mid beeps            nothing to delete
//   rising 3-note tune     finished, all deleted, space clean
//   falling 3-note tune    finished, a delete failed or space still damaged
//   repeating low tune     storage could not be mounted
// ============================================================

#include <LittleFS.h>

// ---- Passive buzzer (same block as retrieval-littlefs) ----
const bool BUZZER_ENABLED = true;   // false = silent
const int  BUZZER_PIN     = 4;      // same pin as threshold-gathering
const uint16_t NOTE_GAP_MS = 25;    // short silence between notes

// Arduino-ESP32 core 3.x and 2.x use different PWM tone calls.
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
  #define BUZZ_ATTACH()  ledcAttach(BUZZER_PIN, 2000, 10)
  #define BUZZ_TONE(f)   ledcWriteTone(BUZZER_PIN, (f))
#else
  const int BUZZER_CHANNEL = 0;
  #define BUZZ_ATTACH()  do { ledcSetup(BUZZER_CHANNEL, 2000, 10); ledcAttachPin(BUZZER_PIN, BUZZER_CHANNEL); } while (0)
  #define BUZZ_TONE(f)   ledcWriteTone(BUZZER_CHANNEL, (f))
#endif

struct Note { uint16_t freq; uint16_t ms; };   // freq 0 = rest

const Note TUNE_READY[]     = { {2700, 80} };
const Note TUNE_FILE_ERR[]  = { {1000, 250}, {0, 60}, {1000, 250}, {0, 60}, {1000, 250} };
const Note TUNE_DAMAGED[]   = { {1500, 150}, {2200, 150}, {1500, 150}, {2200, 150} };
const Note TUNE_EMPTY[]     = { {2000, 120}, {0, 80}, {2000, 120} };
const Note TUNE_DONE_OK[]   = { {2200, 90}, {2700, 90}, {3300, 200} };
const Note TUNE_DONE_ERR[]  = { {3300, 150}, {2200, 150}, {1200, 400} };
const Note TUNE_FATAL[]     = { {900, 300}, {0, 80}, {900, 300}, {0, 80}, {600, 600} };
const Note TUNE_COUNTDOWN[] = { {1800, 120} };   // cleanup only
const Note TUNE_DELETED[]   = { {3000, 40} };    // cleanup only

void playTune(const Note* notes, size_t count) {
  if (!BUZZER_ENABLED) return;
  Serial.flush();   // make sure the text is out before the (blocking) sound
  for (size_t i = 0; i < count; i++) {
    BUZZ_TONE(notes[i].freq);
    delay(notes[i].ms);
    BUZZ_TONE(0);
    delay(NOTE_GAP_MS);
  }
}
#define PLAY(t) playTune(t, sizeof(t) / sizeof(t[0]))

// Every file threshold-gathering (v7 and later) writes starts with "thr_".
const char* SESSION_FILE_PREFIX = "thr_";
const char* BLACKBOX_DIR = "/bb";   // aviso-iot crash recordings (the folder itself is kept)

// Files from older sketch versions (v4-v6), if still on the unit
const char* LEGACY_FILES[] = {
  "/threshold_log_v5.csv",
  "/threshold_sessions_v5.csv",
  "/sensor_stream_log_v2.csv",   // older v4 sessions
};
const int LEGACY_COUNT = sizeof(LEGACY_FILES) / sizeof(LEGACY_FILES[0]);
const char* CONFIRM_WORD = "DELETE";   // must be typed exactly before anything is removed

const int MAX_FILES = 64;
String toDelete[MAX_FILES];
int deleteCount = 0;

// ---- Space accounting (same estimate in retrieval + threshold) ----
// LittleFS uses 4 KB blocks: 2 for each directory (root included),
// and about size/4000 for each file (a little of every block holds
// links). More space used than the listed files can explain means
// the filesystem is damaged.
const size_t FS_BLOCK = 4096;
const size_t FS_UNEXPLAINED_LIMIT = 64 * 1024;
size_t listedFiles = 0, listedDirs = 0, listedBytes = 0, expectedBytes = 2 * FS_BLOCK;

void listDir(const char* path, int depth) {
  File dir = LittleFS.open(path);
  if (!dir || !dir.isDirectory()) return;
  for (File f = dir.openNextFile(); f; f = dir.openNextFile()) {
    String fullPath = f.path();
    for (int i = 0; i < depth; i++) Serial.print("  ");
    if (f.isDirectory()) {
      Serial.println("  " + fullPath + "/");
      listedDirs++;
      expectedBytes += 2 * FS_BLOCK;
      f.close();
      listDir(fullPath.c_str(), depth + 1);
    } else {
      const size_t size = f.size();
      Serial.println("  " + fullPath + "  (" + String(size) + " bytes)");
      listedFiles++;
      listedBytes += size;
      expectedBytes += ((size + 3999) / 4000) * FS_BLOCK;
      f.close();
    }
  }
  dir.close();
}

// Lists everything and returns false if the space used does not
// match what is listed (damaged filesystem).
bool listAndCheckSpace() {
  listedFiles = listedDirs = listedBytes = 0;
  expectedBytes = 2 * FS_BLOCK;
  Serial.println("[INFO] Files currently on this device:");
  listDir("/", 0);
  if (listedFiles == 0 && listedDirs == 0) Serial.println("  (none found)");

  const size_t total = LittleFS.totalBytes(), used = LittleFS.usedBytes();
  Serial.printf("[STORAGE] Used %u / %u bytes, free %u. Listed: %u file(s), %u folder(s), %u bytes.\n",
                (unsigned)used, (unsigned)total, (unsigned)(total - used),
                (unsigned)listedFiles, (unsigned)listedDirs, (unsigned)listedBytes);
  if (used > expectedBytes + FS_UNEXPLAINED_LIMIT) {
    Serial.printf("[ERROR] Filesystem damaged: ~%u bytes are used by nothing listed.\n",
                  (unsigned)(used - expectedBytes));
    return false;
  }
  return true;
}

// Adds files in one directory whose name starts with prefix ("" = all).
// Collected first, so nothing is removed while the directory is walked.
void collectFiles(const char* dirPath, const char* prefix) {
  File dir = LittleFS.open(dirPath);
  if (!dir || !dir.isDirectory()) return;
  for (File f = dir.openNextFile(); f && deleteCount < MAX_FILES; f = dir.openNextFile()) {
    if (!f.isDirectory() && String(f.name()).startsWith(prefix)) toDelete[deleteCount++] = f.path();
    f.close();
  }
  dir.close();
}

// Waits until CONFIRM_WORD is typed and Enter pressed (same pattern
// as format-littlefs). Anything else is answered and ignored. A
// short tick every 5 s says the unit is waiting.
void waitForConfirmation() {
  Serial.println("[CONFIRM] Type " + String(CONFIRM_WORD) + " and press Enter to delete the files above.");
  Serial.println("[CONFIRM] Anything else deletes nothing. Not retrieved yet? Just unplug.");
  String input;
  unsigned long lastTick = millis();
  while (true) {
    if (Serial.available()) {
      char c = Serial.read();
      if (c == '\n' || c == '\r') {
        input.trim();
        if (input == CONFIRM_WORD) return;
        if (input.length() > 0) {
          Serial.println("[INFO] Nothing deleted (got \"" + input + "\"). Type " + String(CONFIRM_WORD) + " to delete.");
        }
        input = "";
      } else if (input.length() < 32) {
        input += c;
      }
    }
    if (millis() - lastTick >= 5000) { PLAY(TUNE_COUNTDOWN); lastTick = millis(); }
    delay(5);
  }
}

void setup() {
  Serial.begin(115200);
  delay(1000);

  if (BUZZER_ENABLED) {
    BUZZ_ATTACH();
    BUZZ_TONE(0);
  }

  Serial.println("=====================================");
  Serial.println("AVISO Storage Cleanup");
  Serial.println("=====================================");
  PLAY(TUNE_READY);

  bool mounted = false;
  for (int i = 0; i < 3; i++) {
    if (LittleFS.begin(false)) { mounted = true; break; }
    delay(300);
  }
  if (!mounted) {
    Serial.println("[ERROR] LittleFS mount failed after 3 attempts. Run format-littlefs.");
    while (1) {
      PLAY(TUNE_FATAL);
      delay(3000);
    }
  }

  bool spaceOk = listAndCheckSpace();
  Serial.println("");
  if (!spaceOk) PLAY(TUNE_DAMAGED);

  collectFiles("/", SESSION_FILE_PREFIX);
  collectFiles(BLACKBOX_DIR, "");
  for (int i = 0; i < LEGACY_COUNT && deleteCount < MAX_FILES; i++) {
    if (LittleFS.exists(LEGACY_FILES[i])) toDelete[deleteCount++] = LEGACY_FILES[i];
  }

  int failed = 0;
  if (deleteCount == 0) {
    Serial.println("[INFO] Nothing to delete.");
  } else {
    for (int i = 0; i < deleteCount; i++) Serial.println("[WARNING] About to permanently delete: " + toDelete[i]);
    Serial.println("[WARNING] Make sure you have already retrieved this data AND opened the CSVs.");
    waitForConfirmation();

    for (int i = 0; i < deleteCount; i++) {
      if (LittleFS.remove(toDelete[i])) {
        Serial.println("[DONE] Deleted: " + toDelete[i]);
        PLAY(TUNE_DELETED);
      } else {
        Serial.println("[ERROR] Delete failed: " + toDelete[i]);
        failed++;
        PLAY(TUNE_FILE_ERR);
      }
    }
    Serial.println("");
    spaceOk = listAndCheckSpace();   // judge the state AFTER deleting
  }

  if (!spaceOk) {
    Serial.println("[ERROR] Deleting files cannot recover space that no file owns.");
    Serial.println("[ERROR] Run format-littlefs (it erases everything; calibration and attempt counters are kept).");
  }
  Serial.println("[INFO] Note: attempt counters in EEPROM are unaffected by this cleanup.");
  Serial.println("[INFO] Your next session will continue numbering from where it left off.");
  Serial.printf("[DONE] Cleanup finished (%d deleted, %d failed).\n", deleteCount - failed, failed);

  if (failed > 0 || !spaceOk) PLAY(TUNE_DONE_ERR);
  else if (deleteCount == 0)  PLAY(TUNE_EMPTY);
  else                        PLAY(TUNE_DONE_OK);
}

void loop() {}