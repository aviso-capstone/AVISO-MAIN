// ============================================================
// AVISO — LittleFS Format
// ------------------------------------------------------------
// Erases EVERY file on the storage partition: session logs,
// black-box recordings, everything. Use it only when storage
// cannot be mounted, or when storage-cleanup reports space used
// by nothing listed (damaged filesystem).
// Calibration and attempt counters (EEPROM) are not affected.
//
// HOW TO USE
//   1. Retrieve first (retrieval-littlefs), if anything is readable.
//   2. Upload, open the Serial Monitor at 115200 baud with the line
//      ending set to "Newline".
//   3. Type FORMAT and press Enter. Anything else erases nothing,
//      so leaving this sketch on the board is harmless.
//   4. After [DONE], upload the next sketch you need.
// ============================================================

#include <LittleFS.h>

void listDir(const char* path, int depth) {
  File dir = LittleFS.open(path);
  if (!dir || !dir.isDirectory()) return;
  for (File f = dir.openNextFile(); f; f = dir.openNextFile()) {
    String fullPath = f.path();
    for (int i = 0; i < depth; i++) Serial.print("  ");
    if (f.isDirectory()) {
      Serial.println("  " + fullPath + "/");
      f.close();
      listDir(fullPath.c_str(), depth + 1);
    } else {
      Serial.println("  " + fullPath + "  (" + String(f.size()) + " bytes)");
      f.close();
    }
  }
  dir.close();
}

void printStatus() {
  const size_t total = LittleFS.totalBytes(), used = LittleFS.usedBytes();
  Serial.printf("[STORAGE] Used %u / %u bytes, free %u\n",
                (unsigned)used, (unsigned)total, (unsigned)(total - used));
  listDir("/", 0);
}

void setup() {
  Serial.begin(115200);
  while (!Serial) delay(10);
  delay(1000);
  Serial.println("=====================================");
  Serial.println("AVISO LittleFS Format");
  Serial.println("=====================================");

  // Mount WITHOUT auto-format so the "before" state is shown as it is.
  if (LittleFS.begin(false)) {
    Serial.println("[BEFORE]");
    printStatus();
    LittleFS.end();
  } else {
    Serial.println("[BEFORE] Mount failed: the storage is unreadable (formatting will fix it).");
  }

  Serial.println("");
  Serial.println("[WARNING] Formatting erases ALL files listed above.");
  Serial.println("[WARNING] Calibration and attempt counters (EEPROM) are not affected.");
  Serial.println("[CONFIRM] Type FORMAT and press Enter to erase. Anything else does nothing.");

  String input;
  while (true) {
    if (Serial.available()) {
      char c = Serial.read();
      if (c == '\n' || c == '\r') {
        input.trim();
        if (input == "FORMAT") break;
        if (input.length() > 0) {
          Serial.println("[INFO] Not erased (got \"" + input + "\"). Type FORMAT to erase.");
        }
        input = "";
      } else if (input.length() < 32) {
        input += c;
      }
    }
    delay(5);
  }

  Serial.println("[INFO] Formatting...");
  if (!LittleFS.format()) {
    Serial.println("[ERROR] Format failed.");
    return;
  }
  if (!LittleFS.begin(false)) {
    Serial.println("[ERROR] Remount after format failed.");
    return;
  }
  Serial.println("[AFTER]");
  printStatus();
  Serial.println("[DONE] Format complete. Upload your next sketch now.");
}

void loop() {}
