// ============================================================
// AVISO — Log Retrieval
// ------------------------------------------------------------
// Prints every log on the unit over Serial so it can be saved
// on the laptop. Reads only; nothing on the unit is changed.
//
// HOW TO USE
//   1. Upload, open the Serial Monitor at 115200 baud, clear it.
//   2. Type any key and press Enter. The dump starts only then,
//      so the monitor is ready before anything important prints.
//   3. Copy each block between BEGIN and END into its own .csv
//      (name it after the file, e.g. thr_brake_7.csv).
//   4. Check the files with crash-detection/tools/check_thr_csv.py.
//   5. Copy went wrong? Press Enter again to print everything
//      again. No re-upload needed.
//   Only after the CSVs check out: run storage-cleanup.
//
// WHAT IS PRINTED, IN ORDER
//   /thr_sessions.csv            session summary (small, first)
//   /thr_<label>_<attempt>.csv   one log per session
//   /bb/...                      aviso-iot black-box crash recordings
//   older v4-v6 files            only if still on the unit
//   /thr_sessions.csv            the summary once more, in case the
//                                top of the output was lost
//   It also lists all files with used / free space and warns when
//   space is used by nothing listed (damaged filesystem: retrieve
//   what you can, then run format-littlefs).
//
// BUZZER (passive buzzer on BUZZER_PIN)
//   1 short beep           started
//   rising 2-note chirp    one file copied completely
//   3 low beeps            one file failed or was cut short
//   alternating warning    filesystem damaged
//   2 mid beeps            no files on the unit
//   rising 3-note tune     finished, everything copied
//   falling 3-note tune    finished, at least one error
//   repeating low tune     storage could not be mounted
//   Sounds play only between files, never during a transfer.
// ============================================================

#include <LittleFS.h>

// ---- Passive buzzer ----
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

// Small passive buzzers are usually loudest around 2-4 kHz, so the
// tunes stay in that range (except the low "error" notes).
const Note TUNE_READY[]    = { {2700, 80} };
const Note TUNE_FILE_OK[]  = { {2700, 70}, {3300, 110} };
const Note TUNE_FILE_ERR[] = { {1000, 250}, {0, 60}, {1000, 250}, {0, 60}, {1000, 250} };
const Note TUNE_DAMAGED[]  = { {1500, 150}, {2200, 150}, {1500, 150}, {2200, 150} };
const Note TUNE_EMPTY[]    = { {2000, 120}, {0, 80}, {2000, 120} };
const Note TUNE_DONE_OK[]  = { {2200, 90}, {2700, 90}, {3300, 200} };
const Note TUNE_DONE_ERR[] = { {3300, 150}, {2200, 150}, {1200, 400} };
const Note TUNE_FATAL[]    = { {900, 300}, {0, 80}, {900, 300}, {0, 80}, {600, 600} };

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

// Files written by threshold-gathering v7 and later
const char* SUMMARY_FILE = "/thr_sessions.csv";
const char* SESSION_LOG_PREFIX = "thr_";
const char* BLACKBOX_DIR = "/bb";   // aviso-iot crash recordings

// Files from older sketch versions (v4-v6), if still on the unit
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

// Returns 1 = copied completely, 0 = skipped (not found),
// -1 = could not open or stopped early (error).
int dumpFile(const char* path) {
  if (!LittleFS.exists(path)) {
    Serial.println("[SKIP] File not found: " + String(path));
    return 0;
  }
  File file = LittleFS.open(path, "r");
  if (!file) {
    Serial.println("[ERROR] Could not open: " + String(path));
    PLAY(TUNE_FILE_ERR);
    return -1;
  }
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
  const bool complete = (sent >= size);
  if (!complete) Serial.println("[ERROR] Read stopped early. The file may be damaged after that point.");
  Serial.println("");
  file.close();

  // Sound only after the whole file and its END marker are out.
  if (complete) PLAY(TUNE_FILE_OK);
  else          PLAY(TUNE_FILE_ERR);
  return complete ? 1 : -1;
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

bool spaceOk = true;

// Waits until something arrives over Serial (any key + Enter in the
// Serial Monitor), then throws the typed characters away.
void waitForKey(const char* prompt) {
  Serial.println("");
  Serial.println(prompt);
  while (!Serial.available()) delay(20);
  delay(50);
  while (Serial.available()) Serial.read();
}

// One complete dump: summary, every session log, black box, legacy
// files, then the summary once more (not counted twice).
void dumpAll() {
  int copied = 0, errors = 0;
  auto tally = [&](int r) { if (r > 0) copied++; else if (r < 0) errors++; };

  tally(dumpFile(SUMMARY_FILE));

  const int MAX_FILES = 64;
  String paths[MAX_FILES];
  int sessionLogs = 0;
  for (int i = 0, n = collectFiles("/", SESSION_LOG_PREFIX, paths, MAX_FILES); i < n; i++) {
    if (paths[i] == SUMMARY_FILE) continue;
    tally(dumpFile(paths[i].c_str()));
    sessionLogs++;
  }

  // Black-box crash recordings from the aviso-iot firmware
  const int blackbox = collectFiles(BLACKBOX_DIR, "", paths, MAX_FILES);
  for (int i = 0; i < blackbox; i++) tally(dumpFile(paths[i].c_str()));

  for (int i = 0; i < LEGACY_COUNT; i++) {
    if (LittleFS.exists(LEGACY_FILES[i])) tally(dumpFile(LEGACY_FILES[i]));
  }

  // Summary again, so it survives even if the top of the output was lost.
  if (LittleFS.exists(SUMMARY_FILE)) {
    Serial.println("[INFO] Session summary again (same file as at the start):");
    dumpFile(SUMMARY_FILE);
  }

  Serial.printf("[DONE] Retrieval finished (%d session log(s), %d black-box file(s)).\n",
                sessionLogs, blackbox);
  Serial.printf("[DONE] Copied OK: %d   Errors: %d\n", copied, errors);

  if (copied == 0 && errors == 0) PLAY(TUNE_EMPTY);
  else if (errors > 0 || !spaceOk) PLAY(TUNE_DONE_ERR);
  else PLAY(TUNE_DONE_OK);
}

void setup() {
  Serial.begin(115200);
  delay(1000);

  if (BUZZER_ENABLED) {
    BUZZ_ATTACH();
    BUZZ_TONE(0);
  }

  Serial.println("=====================================");
  Serial.println("AVISO Log Retrieval");
  Serial.println("=====================================");
  PLAY(TUNE_READY);

  bool mounted = false;
  for (int i = 0; i < 3; i++) {
    if (LittleFS.begin(false)) { mounted = true; break; }
    delay(300);
  }
  if (!mounted) {
    Serial.println("[ERROR] LittleFS mount failed after 3 attempts. Nothing can be read;");
    Serial.println("[ERROR] run format-littlefs to make the storage usable again.");
    while (1) {
      PLAY(TUNE_FATAL);
      delay(3000);
    }
  }

  spaceOk = listAndCheckSpace();
  if (!spaceOk) PLAY(TUNE_DAMAGED);
}

// Each Enter in the Serial Monitor = one full dump.
void loop() {
  waitForKey(">>> Clear the Serial Monitor, then type any key and press Enter to print all files"
             " (again, if a copy failed).");
  dumpAll();
}