// ============================================================
// AVISO — Log Retrieval (Enhanced)
// ------------------------------------------------------------
// Prints each file below over Serial (115200 baud). Copy each
// block between BEGIN/END into its own .csv file on the laptop,
// then check them with crash-detection/tools/check_thr_csv.py.
// Retrieve the short session summary first: it says which
// sessions exist and whether each one was clean.
//
// Also lists folders (e.g. /bb = black-box crash recordings from
// the aviso-iot firmware, same storage partition) and reports
// used/free space, so space taken by something not listed —
// a damaged filesystem — is visible instead of silent.
// ============================================================
#include <LittleFS.h>

// threshold-gathering v7: summary first (it is small), then every
// per-session log /thr_<label>_<attempt>.csv found on the unit.
const char* SUMMARY_FILE = "/thr_sessions.csv";
const char* SESSION_LOG_PREFIX = "thr_";
const char* BLACKBOX_DIR = "/bb";   // aviso-iot crash recordings

// Older sketch versions, if still on the unit
const char* LEGACY_FILES[] = {
  "/threshold_sessions_v5.csv",
  "/threshold_log_v5.csv",
  "/sensor_stream_log_v2.csv",   // older v4 sessions
};
const int LEGACY_COUNT = sizeof(LEGACY_FILES) / sizeof(LEGACY_FILES[0]);

// ---- Space accounting (same estimate in cleanup + threshold) ----
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
    Serial.println("[ERROR] Retrieve what is listed below, then run format-littlefs to get the space back.");
    return false;
  }
  return true;
}

void dumpFile(const char* path) {
  if (!LittleFS.exists(path)) {
    Serial.println("[SKIP] File not found: " + String(path));
    return;
  }
  File file = LittleFS.open(path, "r");
  const size_t size = file.size();
  Serial.println("[INFO] Retrieving: " + String(path));
  Serial.println("[INFO] File size: " + String(size) + " bytes");
  Serial.println("=== BEGIN CSV DATA " + String(path) + " (copy everything below) ===");

  // Buffered copy: much faster than one byte at a time.
  uint8_t buf[512];
  unsigned long lineCount = 0;
  size_t sent = 0;
  while (file.available()) {
    size_t n = file.read(buf, sizeof(buf));
    if (n == 0) break;   // damaged file: stop instead of looping forever
    for (size_t i = 0; i < n; i++) if (buf[i] == '\n') lineCount++;
    Serial.write(buf, n);
    sent += n;
  }

  Serial.println("\n=== END CSV DATA " + String(path) + " ===");
  Serial.println("[INFO] Total lines (including header): " + String(lineCount));
  Serial.println("[INFO] Bytes sent: " + String(sent) + " of " + String(size));
  if (sent < size) Serial.println("[ERROR] Read stopped early. The file may be damaged after that point.");
  Serial.println("");
  file.close();
}

// Collects the full paths of files in one directory whose name
// starts with prefix ("" = all). Names are collected first so the
// directory is not held open while the files are being read.
int collectFiles(const char* dirPath, const char* prefix, String* out, int maxOut) {
  int count = 0;
  File dir = LittleFS.open(dirPath);
  if (!dir || !dir.isDirectory()) return 0;
  for (File f = dir.openNextFile(); f && count < maxOut; f = dir.openNextFile()) {
    if (!f.isDirectory() && String(f.name()).startsWith(prefix)) out[count++] = f.path();
    f.close();
  }
  dir.close();
  return count;
}

void setup() {
  Serial.begin(115200);
  while (!Serial) delay(10);
  delay(1000);

  Serial.println("=====================================");
  Serial.println("AVISO Log Retrieval");
  Serial.println("=====================================");

  bool mounted = false;
  for (int i = 0; i < 3; i++) {
    if (LittleFS.begin(false)) { mounted = true; break; }
    delay(300);
  }
  if (!mounted) {
    Serial.println("[ERROR] LittleFS mount failed after 3 attempts. Nothing can be read;");
    Serial.println("[ERROR] run format-littlefs to make the storage usable again.");
    while (1) delay(1000);
  }

  listAndCheckSpace();
  Serial.println("");

  dumpFile(SUMMARY_FILE);

  const int MAX_FILES = 64;
  String paths[MAX_FILES];
  int sessionLogs = 0;
  for (int i = 0, n = collectFiles("/", SESSION_LOG_PREFIX, paths, MAX_FILES); i < n; i++) {
    if (paths[i] == SUMMARY_FILE) continue;
    dumpFile(paths[i].c_str());
    sessionLogs++;
  }

  // Black-box crash recordings from the aviso-iot firmware
  const int blackbox = collectFiles(BLACKBOX_DIR, "", paths, MAX_FILES);
  for (int i = 0; i < blackbox; i++) dumpFile(paths[i].c_str());

  for (int i = 0; i < LEGACY_COUNT; i++) {
    if (LittleFS.exists(LEGACY_FILES[i])) dumpFile(LEGACY_FILES[i]);
  }
  Serial.printf("[DONE] Retrieval finished (%d session log(s), %d black-box file(s)).\n",
                sessionLogs, blackbox);
}

void loop() {}
