// ============================================================
// AVISO — Storage Cleanup (Enhanced)
// ------------------------------------------------------------
// Deletes the threshold-gathering files and the black-box crash
// recordings. Only run this AFTER retrieval-littlefs and after
// checking the saved CSVs (crash-detection/tools/check_thr_csv.py).
//
// If space is used by something that is not listed (damaged
// filesystem), deleting files cannot get it back: it says so, and
// format-littlefs is the fix.
// ============================================================
#include <LittleFS.h>

// threshold-gathering v7 writes /thr_sessions.csv and one
// /thr_<label>_<attempt>.csv per session: every "thr_" file goes.
const char* SESSION_FILE_PREFIX = "thr_";
const char* BLACKBOX_DIR = "/bb";   // aviso-iot crash recordings (the folder itself is kept)

// Older sketch versions, if still on the unit
const char* LEGACY_FILES[] = {
  "/threshold_log_v5.csv",
  "/threshold_sessions_v5.csv",
  "/sensor_stream_log_v2.csv",   // older v4 sessions
};
const int LEGACY_COUNT = sizeof(LEGACY_FILES) / sizeof(LEGACY_FILES[0]);
const unsigned long CONFIRMATION_DELAY_MS = 5000;  // pause before deleting, giving you a chance to abort

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

void setup() {
  Serial.begin(115200);
  while (!Serial) delay(10);
  delay(1000);

  Serial.println("=====================================");
  Serial.println("AVISO Storage Cleanup");
  Serial.println("=====================================");

  bool mounted = false;
  for (int i = 0; i < 3; i++) {
    if (LittleFS.begin(false)) { mounted = true; break; }
    delay(300);
  }
  if (!mounted) {
    Serial.println("[ERROR] LittleFS mount failed after 3 attempts. Run format-littlefs.");
    while (1) delay(1000);
  }

  const bool spaceOk = listAndCheckSpace();
  Serial.println("");

  collectFiles("/", SESSION_FILE_PREFIX);
  collectFiles(BLACKBOX_DIR, "");
  for (int i = 0; i < LEGACY_COUNT && deleteCount < MAX_FILES; i++) {
    if (LittleFS.exists(LEGACY_FILES[i])) toDelete[deleteCount++] = LEGACY_FILES[i];
  }

  if (deleteCount == 0) {
    Serial.println("[INFO] Nothing to delete.");
  } else {
    for (int i = 0; i < deleteCount; i++) Serial.println("[WARNING] About to permanently delete: " + toDelete[i]);
    Serial.println("[WARNING] Make sure you have already retrieved this data before continuing.");
    Serial.println("[WARNING] Deleting in " + String(CONFIRMATION_DELAY_MS / 1000) + " seconds...");
    Serial.println("[WARNING] Reset the board now if you have NOT retrieved this data yet.");
    delay(CONFIRMATION_DELAY_MS);

    for (int i = 0; i < deleteCount; i++) {
      if (LittleFS.remove(toDelete[i])) {
        Serial.println("[DONE] Deleted: " + toDelete[i]);
      } else {
        Serial.println("[ERROR] Delete failed: " + toDelete[i]);
      }
    }
    Serial.println("");
    listAndCheckSpace();
  }

  if (!spaceOk) {
    Serial.println("[ERROR] Deleting files cannot recover space that no file owns.");
    Serial.println("[ERROR] Run format-littlefs (it erases everything; calibration and attempt counters are kept).");
  }
  Serial.println("[INFO] Note: attempt counters in EEPROM are unaffected by this cleanup.");
  Serial.println("[INFO] Your next session will continue numbering from where it left off.");
}

void loop() {}
