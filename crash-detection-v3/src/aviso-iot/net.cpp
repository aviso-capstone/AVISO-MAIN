#include "net.h"
#include "config.h"
#include "shared.h"
#include "blackbox.h"
#include "imu.h"
#include "buzzer.h"
#include <WiFi.h>
#include <WiFiMulti.h>
#include <WebServer.h>
#include <WebSocketsServer.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <LittleFS.h>
#include <esp_task_wdt.h>

namespace {
  // Recreated whenever the saved list changes (WiFiMulti has no "clear").
  WiFiMulti* wifiMulti = nullptr;
  WebServer http(HTTP_PORT);
  WebSocketsServer ws(WS_PORT);

  String savedSsid[MAX_SAVED_NETWORKS];
  String savedPass[MAX_SAVED_NETWORKS];
  String serverUrl;      // e.g. http://192.168.43.50:8000 (no trailing slash)
  String deviceKey;      // from POST /api/iot/claim
  String pendingCode;    // pairing code waiting for internet
  String setupMessage;

  bool apActive = false;
  unsigned long disconnectedSince = 0;
  unsigned long connectedAt = 0;
  unsigned long lastConnectAttempt = 0;
  unsigned long lastHeartbeat = 0;
  unsigned long lastClaimAttempt = 0;
  unsigned long lastTelemetry = 0;
  bool wasConnected = false;
  bool calibrating = false;

  // The crash currently being handled (only one at a time).
  struct PendingCrash {
    bool active = false;
    DetectorEvent ev;
    unsigned long detectedAt = 0;
    unsigned long lastSentAt = 0;
    bool appAcked = false;   // the app received it (countdown running)
    bool resolved = false;   // rider tapped "I'm OK" or the app sent the SOS
    bool backupDone = false; // the server has it, or rejected it for good
    int backupAttempts = 0;
    unsigned long lastBackupAt = 0;
  } crash;

  // ---------------------------------------------------------- settings
  void loadSettings() {
    Preferences p;
    p.begin("aviso", true);
    for (int i = 0; i < MAX_SAVED_NETWORKS; i++) {
      savedSsid[i] = p.getString(("n" + String(i)).c_str(), "");
      savedPass[i] = p.getString(("p" + String(i)).c_str(), "");
    }
    serverUrl = p.getString("srv", "");
    deviceKey = p.getString("key", "");
    pendingCode = p.getString("pcode", "");
    p.end();
  }

  void saveString(const char* k, const String& v) {
    Preferences p;
    p.begin("aviso", false);
    p.putString(k, v);
    p.end();
  }

  void saveNetworks() {
    Preferences p;
    p.begin("aviso", false);
    for (int i = 0; i < MAX_SAVED_NETWORKS; i++) {
      p.putString(("n" + String(i)).c_str(), savedSsid[i]);
      p.putString(("p" + String(i)).c_str(), savedPass[i]);
    }
    p.end();
  }

  int savedCount() {
    int n = 0;
    for (int i = 0; i < MAX_SAVED_NETWORKS; i++) if (savedSsid[i].length()) n++;
    return n;
  }

  void rebuildWifiMulti() {
    delete wifiMulti;
    wifiMulti = new WiFiMulti();
    for (int i = 0; i < MAX_SAVED_NETWORKS; i++)
      if (savedSsid[i].length()) wifiMulti->addAP(savedSsid[i].c_str(), savedPass[i].c_str());
  }

  // Same name replaces its slot; otherwise use a free slot, else shift out slot 0.
  void rememberNetwork(const String& ssid, const String& pass) {
    for (int i = 0; i < MAX_SAVED_NETWORKS; i++) {
      if (savedSsid[i] == ssid) { savedPass[i] = pass; saveNetworks(); rebuildWifiMulti(); return; }
    }
    for (int i = 0; i < MAX_SAVED_NETWORKS; i++) {
      if (!savedSsid[i].length()) { savedSsid[i] = ssid; savedPass[i] = pass; saveNetworks(); rebuildWifiMulti(); return; }
    }
    for (int i = 0; i < MAX_SAVED_NETWORKS - 1; i++) { savedSsid[i] = savedSsid[i + 1]; savedPass[i] = savedPass[i + 1]; }
    savedSsid[MAX_SAVED_NETWORKS - 1] = ssid;
    savedPass[MAX_SAVED_NETWORKS - 1] = pass;
    saveNetworks();
    rebuildWifiMulti();
  }

  void forgetNetworks() {
    for (int i = 0; i < MAX_SAVED_NETWORKS; i++) { savedSsid[i] = ""; savedPass[i] = ""; }
    saveNetworks();
    rebuildWifiMulti();
  }

  // ---------------------------------------------------------- server calls
  // POSTs JSON to the AVISO server. Returns the HTTP status (<= 0 on failure).
  int postJson(const String& path, const String& body, bool withDeviceAuth, String* response = nullptr) {
    if (!serverUrl.length() || WiFi.status() != WL_CONNECTED) return -1;
    String url = serverUrl + path;

    HTTPClient req;
    WiFiClient plain;
    WiFiClientSecure secure;
    bool ok;
    if (url.startsWith("https://")) {
      // No certificate pinning on this prototype: traffic is encrypted, but the
      // server's identity is not verified. Pin the server certificate before
      // production use.
      secure.setInsecure();
      ok = req.begin(secure, url);
    } else {
      ok = req.begin(plain, url);
    }
    if (!ok) return -1;

    req.setTimeout(HTTP_TIMEOUT_MS);
    req.addHeader("Content-Type", "application/json");
    req.addHeader("Accept", "application/json");
    if (withDeviceAuth) {
      req.addHeader("X-Device-Id", deviceUid);
      req.addHeader("X-Device-Key", deviceKey);
    }
    int code = req.POST(body);
    if (response && code > 0) *response = req.getString();
    req.end();
    return code;
  }

  void claimDevice() {
    if (!pendingCode.length() || WiFi.status() != WL_CONNECTED) return;
    JsonDocument doc;
    doc["device_uid"] = deviceUid;
    doc["pairing_code"] = pendingCode;
    doc["firmware_version"] = FIRMWARE_VERSION;
    String body, resp;
    serializeJson(doc, body);

    int code = postJson("/api/iot/claim", body, false, &resp);
    Serial.printf("[PAIR] %s/api/iot/claim -> %d%s\n", serverUrl.c_str(), code,
                  code <= 0 ? "  (server unreachable: check the URL, --host=0.0.0.0, firewall)" : "");
    if (code == 201) {
      JsonDocument out;
      if (!deserializeJson(out, resp) && out["device_key"].is<const char*>()) {
        deviceKey = out["device_key"].as<String>();
        saveString("key", deviceKey);
        setupMessage = "Paired with your AVISO account.";
        Buzzer::play(PATTERN_OK);
      }
      pendingCode = "";
      saveString("pcode", "");
    } else if (code == 422) {
      setupMessage = "Pairing code was wrong or expired. Get a new one in the app.";
      Buzzer::play(PATTERN_ERROR);
      pendingCode = "";
      saveString("pcode", "");
    }
    // Other failures (no internet, server down): keep the code and retry later.
  }

  void sendHeartbeat() {
    JsonDocument doc;
    doc["local_ip"] = WiFi.localIP().toString();
    doc["rssi"] = WiFi.RSSI();
    doc["uptime_seconds"] = (uint32_t)(millis() / 1000);
    doc["firmware_version"] = FIRMWARE_VERSION;
    doc["reset_reason"] = resetReasonLabel;
    String body;
    serializeJson(doc, body);
    int code = postJson("/api/iot/heartbeat", body, true);
    if (code != 200) Serial.printf("[HB] heartbeat -> %d\n", code);
    if (code == 401) {
      // The server no longer knows this key (unpaired / paired again elsewhere).
      deviceKey = "";
      saveString("key", "");
    }
  }

  void sendBackupCrash(unsigned long now) {
    crash.backupAttempts++;
    crash.lastBackupAt = now;
    if (!deviceKey.length()) { crash.backupDone = true; return; }

    JsonDocument doc;
    doc["event_uid"] = crash.ev.uid;
    doc["peak_g"] = crash.ev.peakG;
    doc["vertical_g"] = crash.ev.peakVerticalG;
    doc["horizontal_g"] = crash.ev.peakHorizontalG;
    doc["peak_gyro_dps"] = crash.ev.peakGyroDps;
    doc["tilt_deg"] = crash.ev.tiltDeg;
    String body;
    serializeJson(doc, body);

    int code = postJson("/api/iot/crash", body, true);
    // 201 = alert raised (or already raised by the phone). 401/409/422 will
    // not succeed on retry. Anything else (no internet, timeout) retries.
    if (code == 201 || code == 401 || code == 409 || code == 422) crash.backupDone = true;
    if (crash.backupAttempts >= BACKUP_MAX_ATTEMPTS) crash.backupDone = true;
  }

  // ---------------------------------------------------------- WebSocket (rider app)
  String eventJson(const DetectorEvent& e) {
    JsonDocument doc;
    doc["t"] = "evt";
    doc["id"] = e.uid;
    doc["type"] = eventTypeName(e.type);
    doc["peak_g"] = e.peakG;
    doc["vg"] = e.peakVerticalG;
    doc["hg"] = e.peakHorizontalG;
    doc["peak_gyro"] = e.peakGyroDps;
    doc["tilt"] = e.tiltDeg;
    String out;
    serializeJson(doc, out);
    return out;
  }

  String helloJson() {
    JsonDocument doc;
    doc["t"] = "hello";
    doc["uid"] = deviceUid;
    doc["fw"] = FIRMWARE_VERSION;
    doc["reset"] = resetReasonLabel;
    doc["uptime"] = (uint32_t)(millis() / 1000);
    doc["rssi"] = WiFi.RSSI();
    doc["paired"] = deviceKey.length() > 0;
    doc["upright_saved"] = Imu::hasUprightReference();
    doc["offsets_saved"] = Imu::hasSavedCalibration();
    JsonObject cal = doc["cal"].to<JsonObject>();
    cal["sys"] = calSys;
    cal["gyro"] = calGyro;
    cal["accel"] = calAccel;
    String out;
    serializeJson(doc, out);
    return out;
  }

  void onWsEvent(uint8_t client, WStype_t type, uint8_t* payload, size_t length) {
    if (type == WStype_CONNECTED) {
      String hello = helloJson();
      ws.sendTXT(client, hello);
      if (crash.active && !crash.resolved) {
        String ev = eventJson(crash.ev);
        ws.sendTXT(client, ev);
      }
      return;
    }
    if (type != WStype_TEXT) return;

    JsonDocument doc;
    if (deserializeJson(doc, payload, length)) return;
    const char* t = doc["t"] | "";
    const char* id = doc["id"] | "";
    const bool forCurrentCrash = crash.active && strcmp(id, crash.ev.uid) == 0;

    if (!strcmp(t, "ack")) {
      if (forCurrentCrash) crash.appAcked = true;
    } else if (!strcmp(t, "cancel")) {
      // Rider tapped "I'm OK": stop the alarm and the backup report.
      if (forCurrentCrash) { crash.resolved = true; requestAlarmStop = true; }
    } else if (!strcmp(t, "sos_sent")) {
      if (forCurrentCrash) crash.resolved = true;   // the app reached the server
    } else if (!strcmp(t, "ride")) {
      rideActive = doc["active"] | false;
    } else if (!strcmp(t, "calibrate_upright")) {
      calibrating = true;
      requestCalibrateUpright = true;
    } else if (!strcmp(t, "beep")) {
      requestBeep = true;
    }
  }

  void broadcastTelemetry() {
    Telemetry t;
    portENTER_CRITICAL(&telemetryMux);
    t = latestTelemetry;
    portEXIT_CRITICAL(&telemetryMux);

    JsonDocument doc;
    doc["t"] = "tel";
    doc["g"] = roundf(t.linearG * 100) / 100;
    doc["vg"] = roundf(t.verticalG * 100) / 100;
    doc["hg"] = roundf(t.horizontalG * 100) / 100;
    doc["gy"] = roundf(t.gyroDps * 10) / 10;
    doc["yaw"] = roundf(t.yawRateDps * 10) / 10;
    doc["tilt"] = roundf(t.tiltDeg * 10) / 10;
    doc["st"] = motionStateName(t.state);
    String out;
    serializeJson(doc, out);
    ws.broadcastTXT(out);
  }

  // ---------------------------------------------------------- setup page + black box
  // "a******3": enough to spot a wrong saved password on the Serial Monitor.
  String maskPassword(const String& p) {
    if (p.length() <= 2) return String(p.length(), '*');
    String m = p.substring(0, 1);
    for (size_t i = 1; i + 1 < p.length(); i++) m += '*';
    return m + p.substring(p.length() - 1);
  }

  String htmlEscape(const String& s) {
    String o;
    for (size_t i = 0; i < s.length(); i++) {
      const char c = s[i];
      if (c == '<') o += "&lt;"; else if (c == '>') o += "&gt;";
      else if (c == '&') o += "&amp;"; else if (c == '"') o += "&quot;"; else o += c;
    }
    return o;
  }

  void handleSetupPage() {
    String networks;
    for (int i = 0; i < MAX_SAVED_NETWORKS; i++)
      if (savedSsid[i].length())
        networks += "<li>" + htmlEscape(savedSsid[i]) + " <small>(" +
                    (savedPass[i].length() ? String(savedPass[i].length()) + "-character password" : String("no password")) +
                    ")</small></li>";
    if (!networks.length()) networks = "<li>none yet</li>";

    String page =
      "<!doctype html><html><head><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'>"
      "<title>AVISO IoT setup</title><style>body{font-family:sans-serif;max-width:420px;margin:24px auto;padding:0 16px}"
      "input{width:100%;padding:10px;margin:4px 0 12px;box-sizing:border-box}button{width:100%;padding:12px;background:#0274DF;color:#fff;border:0;border-radius:8px}"
      ".msg{background:#eef6ff;padding:10px;border-radius:8px}</style></head><body>"
      "<h2>AVISO IoT setup</h2><p>Device: <b>" + deviceUid + "</b><br>Paired: <b>" + String(deviceKey.length() ? "yes" : "no") + "</b></p>"
      + (setupMessage.length() ? "<p class=msg>" + htmlEscape(setupMessage) + "</p>" : String("")) +
      "<p>Saved hotspots:</p><ul>" + networks + "</ul>"
      "<form method=post action=/save>"
      "<label>Phone hotspot name</label><input name=ssid required maxlength=32>"
      "<label>Hotspot password</label><input name=pass type=password maxlength=63>"
      "<label>AVISO server URL</label><input name=srv placeholder='http://192.168.43.50:8000' value='" + htmlEscape(serverUrl) + "'>"
      "<label>Pairing code (from the AVISO app)</label><input name=code inputmode=numeric maxlength=6>"
      "<button>Save</button></form>"
      "<p>After saving, turn on that hotspot. The unit beeps once when paired.</p>"
      "<form method=post action=/forget onsubmit=\"return confirm('Forget all saved hotspots?')\">"
      "<button style='background:#6B7280'>Forget saved hotspots</button></form>"
      "<p>Keeps the pairing and the server URL. Add your hotspot again above.</p></body></html>";
    http.send(200, "text/html", page);
  }

  void handleSave() {
    String ssid = http.arg("ssid");
    String pass = http.arg("pass");
    String srv = http.arg("srv");
    String code = http.arg("code");
    ssid.trim(); srv.trim(); code.trim();

    if (srv.length() && !srv.startsWith("http://") && !srv.startsWith("https://")) {
      setupMessage = "Server URL must start with http:// or https:// - nothing was saved.";
      http.sendHeader("Location", "/");
      http.send(303);
      return;
    }

    if (ssid.length()) {
      rememberNetwork(ssid, pass);
      Serial.printf("[SETUP] Saved hotspot \"%s\" password %s (%u characters)\n", ssid.c_str(), maskPassword(pass).c_str(), pass.length());
    }
    if (srv.length()) {
      while (srv.endsWith("/")) srv.remove(srv.length() - 1);
      serverUrl = srv;
      saveString("srv", serverUrl);
    }
    if (code.length() == 6) {
      pendingCode = code;
      saveString("pcode", pendingCode);
    }
    setupMessage = "Saved. Turn on the hotspot \"" + ssid + "\" - the unit will join it and pair automatically.";
    http.sendHeader("Location", "/");
    http.send(303);
    lastConnectAttempt = 0;   // try the new hotspot right away
  }

  void handleForget() {
    forgetNetworks();
    setupMessage = "Saved hotspots cleared. Add your phone hotspot above.";
    http.sendHeader("Location", "/");
    http.send(303);
  }

  bool safeFileName(const String& n) {
    if (!n.length() || n.length() > 60) return false;
    for (size_t i = 0; i < n.length(); i++) {
      const char c = n[i];
      if (!(isalnum((unsigned char)c) || c == '-' || c == '_' || c == '.')) return false;
    }
    return n.indexOf("..") < 0;
  }

  void handleNotFound() {
    String uri = http.uri();
    if (uri == "/blackbox") {
      http.send(200, "application/json", BlackBox::listJson());
      return;
    }
    if (uri.startsWith("/blackbox/")) {
      String name = uri.substring(strlen("/blackbox/"));
      String path = "/bb/" + name;
      if (safeFileName(name) && LittleFS.exists(path)) {
        File f = LittleFS.open(path, "r");
        http.streamFile(f, "text/csv");
        f.close();
        return;
      }
    }
    http.send(404, "text/plain", "Not found");
  }

  // ---------------------------------------------------------- Wi-Fi management
  void startSetupAp() {
    String apName = "AVISO-SETUP-" + deviceUid.substring(deviceUid.length() - 4);
    WiFi.mode(WIFI_AP_STA);
    WiFi.softAP(apName.c_str(), SETUP_AP_PASSWORD);
    apActive = true;
    Serial.printf("[SETUP] Setup Wi-Fi \"%s\" open, page at http://%s\n", apName.c_str(),
                  WiFi.softAPIP().toString().c_str());
  }

  void stopSetupAp() {
    WiFi.softAPdisconnect(true);   // turns only the setup network off; the hotspot link stays
    apActive = false;
    Serial.println("[SETUP] Setup Wi-Fi closed (joined the hotspot).");
  }

  const char* securityName(wifi_auth_mode_t m) {
    switch (m) {
      case WIFI_AUTH_OPEN:          return "open (no password)";
      case WIFI_AUTH_WEP:           return "WEP";
      case WIFI_AUTH_WPA_PSK:       return "WPA";
      case WIFI_AUTH_WPA2_PSK:      return "WPA2";
      case WIFI_AUTH_WPA_WPA2_PSK:  return "WPA/WPA2";
      case WIFI_AUTH_WPA3_PSK:      return "WPA3 only (not supported well - use WPA2)";
      case WIFI_AUTH_WPA2_WPA3_PSK: return "WPA2/WPA3 mixed (try WPA2 only)";
      default:                      return "other";
    }
  }

  // Before each join attempt: what the device can actually see of the saved
  // hotspots. Quotes show stray spaces in names.
  void logSavedHotspots() {
    for (int i = 0; i < MAX_SAVED_NETWORKS; i++)
      if (savedSsid[i].length())
        Serial.printf("[WIFI] Saved: \"%s\" password %s (%u characters)\n", savedSsid[i].c_str(), maskPassword(savedPass[i]).c_str(), savedPass[i].length());
  }

  void logVisibleHotspots() {
    const int n = WiFi.scanNetworks(false, false);
    esp_task_wdt_reset();   // the scan can take a few seconds
    if (n < 0) return;
    for (int s = 0; s < MAX_SAVED_NETWORKS; s++) {
      if (!savedSsid[s].length()) continue;
      bool seen = false;
      for (int i = 0; i < n; i++) {
        if (WiFi.SSID(i) != savedSsid[s]) continue;
        seen = true;
        Serial.printf("[SCAN] \"%s\" seen: channel %ld, signal %ld dBm, security %s\n",
                      savedSsid[s].c_str(), (long)WiFi.channel(i), (long)WiFi.RSSI(i),
                      securityName(WiFi.encryptionType(i)));
        if (WiFi.encryptionType(i) == WIFI_AUTH_OPEN && savedPass[s].length())
          Serial.println("[SCAN]   hotspot has NO password but one is saved - save it again with the password empty");
        if (WiFi.encryptionType(i) != WIFI_AUTH_OPEN && !savedPass[s].length())
          Serial.println("[SCAN]   hotspot needs a password but none is saved");
      }
      if (!seen)
        Serial.printf("[SCAN] \"%s\" not visible (off, 5 GHz only, hidden or out of range); %d networks around\n",
                      savedSsid[s].c_str(), n);
    }
    WiFi.scanDelete();
  }

  void manageWifi(unsigned long now) {
    const bool connected = WiFi.status() == WL_CONNECTED;

    if (connected && !wasConnected) {
      connectedAt = now;
      lastHeartbeat = 0;                 // announce our new IP right away
      Serial.printf("[WIFI] Joined \"%s\"  ip=%s  signal=%d dBm\n", WiFi.SSID().c_str(),
                    WiFi.localIP().toString().c_str(), WiFi.RSSI());
    }
    if (!connected && wasConnected) {
      disconnectedSince = now;
      Serial.println("[WIFI] Lost the hotspot, retrying every 10 s.");
    }
    wasConnected = connected;

    if (connected) {
      if (apActive && now - connectedAt >= SETUP_AP_LINGER_MS) stopSetupAp();
      return;
    }

    // Open the setup network when there is nothing to join, or nothing found for a while.
    if (!apActive && (savedCount() == 0 || now - disconnectedSince >= SETUP_AP_AFTER_MS)) startSetupAp();

    // While the setup network is up, retry less often: joining a hotspot can
    // move the radio channel and briefly drop a phone using the setup page.
    const unsigned long retryEvery = apActive ? 30000 : 10000;
    if (savedCount() > 0 && (lastConnectAttempt == 0 || now - lastConnectAttempt >= retryEvery)) {
      lastConnectAttempt = now;
      logVisibleHotspots();
      // A 3 s limit was too short for phone hotspots: the join was cut off and
      // restarted before it finished, which looked like "connects then drops".
      wifiMulti->run(WIFI_CONNECT_TIMEOUT_MS);
    }
  }
}

namespace Net {

void begin() {
  loadSettings();
  WiFi.persistent(false);
  WiFi.setHostname(("aviso-" + deviceUid.substring(deviceUid.length() - 4)).c_str());
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);   // lower latency for live telemetry
  // Why the hotspot dropped us, on the Serial Monitor (e.g. AUTH_FAIL = wrong
  // password, NO_AP_FOUND = hotspot off / 5 GHz, BEACON_TIMEOUT = weak signal).
  WiFi.onEvent([](WiFiEvent_t, WiFiEventInfo_t info) {
    const uint8_t reason = info.wifi_sta_disconnected.reason;
    Serial.printf("[WIFI] Disconnected, reason %u (%s)\n", reason,
                  WiFi.disconnectReasonName((wifi_err_reason_t)reason));
  }, ARDUINO_EVENT_WIFI_STA_DISCONNECTED);
  rebuildWifiMulti();
  logSavedHotspots();
  Serial.printf("[WIFI] %d saved hotspot(s), server %s, %s\n", savedCount(),
                serverUrl.length() ? serverUrl.c_str() : "(not set)",
                deviceKey.length() ? "paired" : (pendingCode.length() ? "pairing code waiting" : "not paired"));
  disconnectedSince = millis();

  http.on("/", HTTP_GET, handleSetupPage);
  http.on("/save", HTTP_POST, handleSave);
  http.on("/forget", HTTP_POST, handleForget);
  http.onNotFound(handleNotFound);
  http.begin();

  ws.begin();
  ws.onEvent(onWsEvent);
}

void loop(unsigned long now) {
  manageWifi(now);
  http.handleClient();
  ws.loop();

  const bool online = WiFi.status() == WL_CONNECTED;

  if (online && pendingCode.length() && (lastClaimAttempt == 0 || now - lastClaimAttempt >= 15000)) {
    lastClaimAttempt = now;
    claimDevice();
  }

  if (online && deviceKey.length() && (lastHeartbeat == 0 || now - lastHeartbeat >= HEARTBEAT_INTERVAL_MS)) {
    lastHeartbeat = now;
    sendHeartbeat();
  }

  // Events from the sensor task.
  DetectorEvent ev;
  while (xQueueReceive(eventQueue, &ev, 0) == pdTRUE) {
    if (ev.type == EVENT_CRASH) {
      crash = PendingCrash();
      crash.active = true;
      crash.ev = ev;
      crash.detectedAt = now;
      crash.lastSentAt = now;
    }
    if (ev.type == EVENT_CRASH_CLEARED) {
      // The bike is upright again. The backup SOS still goes out unless the
      // rider confirms in the app: someone else may have lifted the bike.
      JsonDocument doc;
      doc["t"] = "crash_cleared";
      doc["id"] = crash.active ? crash.ev.uid : "";
      String out;
      serializeJson(doc, out);
      ws.broadcastTXT(out);
      continue;
    }
    String msg = eventJson(ev);
    ws.broadcastTXT(msg);
  }

  if (crash.active && !crash.resolved) {
    // Repeat the crash to the app until it confirms receipt.
    if (!crash.appAcked && now - crash.lastSentAt >= CRASH_RESEND_MS) {
      crash.lastSentAt = now;
      String msg = eventJson(crash.ev);
      ws.broadcastTXT(msg);
    }
    // Backup: the app never sent the SOS nor "I'm OK" -> report it ourselves.
    // Attempts only count while online, so a late hotspot still gets the report.
    if (online && !crash.backupDone && now - crash.detectedAt >= BACKUP_SOS_AFTER_MS &&
        (crash.backupAttempts == 0 || now - crash.lastBackupAt >= BACKUP_RETRY_MS)) {
      sendBackupCrash(now);
    }
  }

  if (calibrating && !requestCalibrateUpright) {
    calibrating = false;
    ws.broadcastTXT("{\"t\":\"calibrated\"}");
  }

  if (ws.connectedClients() > 0 && now - lastTelemetry >= TELEMETRY_INTERVAL_MS) {
    lastTelemetry = now;
    broadcastTelemetry();
  }

  if (BlackBox::pendingWrite()) BlackBox::writePending();
}

} // namespace Net
