#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <WebServer.h>
#include <SPIFFS.h>
#include <ArduinoJson.h>
#include <time.h>

// Pin Definitions
#define SS_PIN   5
#define RST_PIN  16
#define BUZZER   33
#define LED      25

// WiFi Credentials
const char* WIFI_SSID = "Tenda_E81AE0";
const char* WIFI_PASS = "admin123";

const char* API_URL = "http://192.168.0.123/repoRFID/rfid_api.php";
const char* API_KEY = "SCC-2026-SECURE";

// NTP
const char* NTP_SERVER = "pool.ntp.org";
const long  GMT_OFFSET  = 8L * 3600L;
const int   DST_OFFSET  = 0;

// Timing Constants
#define DEBOUNCE_MS       3000UL
#define ENROLL_TIMEOUT_MS 15000UL
#define NTP_RETRY_MS      60000UL
#define LCD_HOLD_MS       2500UL
#define QUEUE_FLUSH_MS    10000UL
#define MAX_QUEUE         30
#define ENROLL_POLL_MS    2000UL

// SPIFFS Queue File
const char* QUEUE_FILE = "/queue.json";

// Session Tracking (TIME_IN / TIME_OUT per-day in RAM)
#define MAX_SESSIONS 50
struct Session {
  uint8_t uid[4];
  char    date[12];
  bool    timedOut;
};
Session sessions[MAX_SESSIONS];
int     sessionCount = 0;

// Remote Enrollment State
bool          remoteEnrollActive = false;
char          remoteEnrollToken[65] = "";
char          remoteEnrollUser[32]  = "";
unsigned long remoteEnrollExpiry    = 0;
unsigned long lastEnrollPoll        = 0;

// Global Objects and State
MFRC522          rfid(SS_PIN, RST_PIN);
LiquidCrystal_I2C lcd(0x27, 16, 2);
WebServer        server(80);

unsigned long lastTap        = 0;
unsigned long lastNtpRetry   = 0;
unsigned long lastQueueFlush = 0;
unsigned long lcdResetAt     = 0;

bool ntpSynced     = false;
bool enrollMode    = false;
bool lcdNeedsReset = false;
char pendingName[32] = "";
unsigned long enrollStart = 0;

// Forward Declarations
void  connectWiFi();
void  ensureWiFi();
bool  tryNTP();
String getDate();
String getTimestamp();
String uidToString(byte* uid);
String generateEventID(const char* uid, const char* date, const char* time);
bool  isDateValid(const String& d);

Session* findSession(byte* uid, const char* date);
void  createSession(byte* uid, const char* date);

bool  postToAPI(const char* uid, const char* date, const char* time,
                const char* action, const char* eventID, char* resolvedName);
void  enqueueOffline(const char* uid, const char* date, const char* time,
                     const char* action, const char* eventID);
void  flushQueue();
void  clearQueue();

void  handleRoot();
void  handleEnrollStart();
void  handleEnrollStatus();
void  handleStudentList();
void  handleDeleteStudent();
void  handleQueueClear();
void  handleSessionsReset();

void  handleEnrollTap(byte* raw);
void  handleRemoteEnrollTap(byte* raw, const String& uidStr);
void  handleAttendanceTap(byte* raw, const String& uidStr);
void  pollForRemoteEnrollment();

void  beepOK();
void  beepFail();
void  beepQueued();
void  lcdPrint(const char* line1, const char* line2);
void  scheduleLcdReset();

String htmlEscape(const String& input);
bool parseUid4(const String& text, uint8_t outUid[4]);

// Setup
void setup() {
  Serial.begin(115200);
  pinMode(BUZZER, OUTPUT);
  pinMode(LED, OUTPUT);

  SPI.begin();
  rfid.PCD_Init();
  delay(50);
  rfid.PCD_SetAntennaGain(rfid.RxGain_max);

  Wire.begin();
  lcd.init();
  lcd.backlight();
  lcdPrint("TapTime v3.1", "Booting...");

  if (!SPIFFS.begin(true)) {
    Serial.println("[SPIFFS] Mount failed - reformatting");
    SPIFFS.format();
    SPIFFS.begin(true);
  }

  WiFi.setSleep(false);
  WiFi.setAutoReconnect(true);
  connectWiFi();

  configTime(GMT_OFFSET, DST_OFFSET, NTP_SERVER);
  ntpSynced = tryNTP();

  // Web portal routes
  server.on("/",              HTTP_GET,  handleRoot);
  server.on("/enroll/start",  HTTP_POST, handleEnrollStart);
  server.on("/enroll/status", HTTP_GET,  handleEnrollStatus);
  server.on("/students",      HTTP_GET,  handleStudentList);
  server.on("/delete",        HTTP_POST, handleDeleteStudent);
  server.on("/queue/clear",   HTTP_POST, handleQueueClear);
  server.on("/sessions/reset", HTTP_POST, handleSessionsReset);
  server.begin();

  Serial.printf("[Web] Portal -> http://%s\n", WiFi.localIP().toString().c_str());
  lcdPrint("Ready. IP:", WiFi.localIP().toString().c_str());
  delay(2500);

  if (!ntpSynced) {
    lcdPrint("NTP FAILED!", "Time unreliable");
    delay(2000);
  }

  lcdPrint("Tap card...", "");
}

// Loop
void loop() {
  server.handleClient();
  ensureWiFi();

  // NTP retry
  if (!ntpSynced && millis() - lastNtpRetry > NTP_RETRY_MS) {
    lastNtpRetry = millis();
    ntpSynced = tryNTP();
    if (ntpSynced) {
      lcdPrint("NTP synced!", "");
      scheduleLcdReset();
    }
  }

  // LCD auto-reset
  if (lcdNeedsReset && millis() >= lcdResetAt) {
    lcdNeedsReset = false;
    lcdPrint("Tap card...", "");
  }

  // Offline queue flush
  if (WiFi.status() == WL_CONNECTED && millis() - lastQueueFlush > QUEUE_FLUSH_MS) {
    lastQueueFlush = millis();
    flushQueue();
  }

  // Remote enrollment polling
  if (WiFi.status() == WL_CONNECTED && !enrollMode) {
    if (!remoteEnrollActive && millis() - lastEnrollPoll > ENROLL_POLL_MS) {
      lastEnrollPoll = millis();
      pollForRemoteEnrollment();
    }

    if (remoteEnrollActive && millis() >= remoteEnrollExpiry) {
      remoteEnrollActive = false;
      memset(remoteEnrollToken, 0, sizeof(remoteEnrollToken));
      lcdPrint("Enroll expired", "Tap card...");
      scheduleLcdReset();
      beepFail();
    }
  }

  // Local enroll timeout
  if (enrollMode && millis() - enrollStart > ENROLL_TIMEOUT_MS) {
    enrollMode = false;
    lcdPrint("Enroll timeout", "");
    scheduleLcdReset();
    beepFail();
  }

  // RFID polling
  if (!rfid.PICC_IsNewCardPresent()) return;
  if (!rfid.PICC_ReadCardSerial())   return;
  delay(50);

  if (millis() - lastTap < DEBOUNCE_MS) {
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
    return;
  }
  lastTap = millis();

  byte*  raw    = rfid.uid.uidByte;
  String uidStr = uidToString(raw);
  Serial.println("[RFID] Card: " + uidStr);

  if (enrollMode) {
    handleEnrollTap(raw);
  } else if (remoteEnrollActive) {
    handleRemoteEnrollTap(raw, uidStr);
  } else {
    handleAttendanceTap(raw, uidStr);
  }

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
}

// Tap Handlers
void handleEnrollTap(byte* raw) {
  String uidStr = uidToString(raw);
  lcdPrint("Enrolling...", uidStr.c_str());

  StaticJsonDocument<256> doc;
  doc["action"]  = "enroll";
  doc["api_key"] = API_KEY;
  doc["name"]    = pendingName;
  doc["uid"]     = uidStr;

  String body;
  serializeJson(doc, body);

  WiFiClient client;
  HTTPClient http;
  http.begin(client, API_URL);
  http.setTimeout(10000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  int    code = http.POST(body);
  String resp = (code > 0) ? http.getString() : "";
  http.end();
  Serial.printf("[Enroll] HTTP %d - %s\n", code, resp.c_str());

  StaticJsonDocument<256> res;
  bool ok = (code == 200 && !deserializeJson(res, resp));
  const char* status = ok ? (res["status"] | "error") : "error";

  if (strcmp(status, "ok") == 0) {
    char msg[32];
    snprintf(msg, sizeof(msg), "%.15s", pendingName);
    lcdPrint("Enrolled!", msg);
    beepOK();
  } else if (strcmp(status, "duplicate") == 0) {
    lcdPrint("Card taken!", "Use another");
    beepFail();
  } else {
    lcdPrint("Enroll failed!", "Check server");
    beepFail();
  }

  enrollMode = false;
  scheduleLcdReset();
}

void handleRemoteEnrollTap(byte* raw, const String& uidStr) {
  (void)raw;

  StaticJsonDocument<256> doc;
  doc["action"]  = "submit";
  doc["api_key"] = API_KEY;
  doc["token"]   = remoteEnrollToken;
  doc["uid"]     = uidStr;

  String body;
  serializeJson(doc, body);
  Serial.println("[RemoteEnroll] Submitting UID: " + uidStr);

  WiFiClient client;
  HTTPClient http;
  http.begin(client, API_URL);
  http.setTimeout(8000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  int    code = http.POST(body);
  String resp = (code > 0) ? http.getString() : "";
  http.end();
  Serial.printf("[RemoteEnroll] HTTP %d - %s\n", code, resp.c_str());

  StaticJsonDocument<256> res;
  if (code != 200 || deserializeJson(res, resp)) {
    lcdPrint("Enroll error!", "Try again");
    scheduleLcdReset();
    beepFail();
    return;
  }

  const char* status = res["status"] | "error";

  if (strcmp(status, "ok") == 0) {
    char msg[32];
    snprintf(msg, sizeof(msg), "Welcome %.10s!", remoteEnrollUser);
    lcdPrint("Card linked!", msg);
    beepOK();
    remoteEnrollActive = false;
    memset(remoteEnrollToken, 0, sizeof(remoteEnrollToken));
    memset(remoteEnrollUser,  0, sizeof(remoteEnrollUser));
  } else if (strcmp(status, "duplicate") == 0) {
    lcdPrint("Card taken!", "Tap another");
    beepFail();
    return;
  } else if (strcmp(status, "expired") == 0) {
    lcdPrint("Session expired", "Re-register");
    beepFail();
    remoteEnrollActive = false;
  } else {
    lcdPrint("Enroll failed!", "Try again");
    beepFail();
  }

  scheduleLcdReset();
}

void handleAttendanceTap(byte* raw, const String& uidStr) {
  String dateStr = getDate();
  String timeStr = getTimestamp();

  if (!isDateValid(dateStr)) {
    lcdPrint("Time not set!", "Check NTP...");
    scheduleLcdReset();
    beepFail();
    return;
  }

  String   eventID = generateEventID(uidStr.c_str(), dateStr.c_str(), timeStr.c_str());
  Session* sess    = findSession(raw, dateStr.c_str());
  const char* action;

  if (sess == nullptr) {
    createSession(raw, dateStr.c_str());
    action = "TIME_IN";
  } else if (!sess->timedOut) {
    sess->timedOut = true;
    action = "TIME_OUT";
  } else {
    lcdPrint("Done today!", "");
    scheduleLcdReset();
    beepFail();
    return;
  }

  if (WiFi.status() == WL_CONNECTED) {
    char resolvedName[32] = "";
    bool sent = postToAPI(uidStr.c_str(), dateStr.c_str(), timeStr.c_str(),
                          action, eventID.c_str(), resolvedName);

    if (sent && strlen(resolvedName) > 0) {
      if (strcmp(action, "TIME_IN") == 0) lcdPrint("Time IN:", resolvedName);
      else                                 lcdPrint("Time OUT:", resolvedName);
      Serial.printf("[ATT] %-20s %s %s %s\n",
                    resolvedName, action, dateStr.c_str(), timeStr.c_str());
      beepOK();
    } else if (sent) {
      if (strcmp(action, "TIME_IN") == 0 && sessionCount > 0) {
        sessionCount--;
      } else if (sess != nullptr) {
        sess->timedOut = false;
      }
      lcdPrint("Unknown card!", uidStr.c_str());
      scheduleLcdReset();
      beepFail();
      return;
    } else {
      enqueueOffline(uidStr.c_str(), dateStr.c_str(), timeStr.c_str(),
                     action, eventID.c_str());
      lcdPrint(action, "Queued offline");
      beepQueued();
      Serial.println("[ATT] HTTP failed - queued offline.");
    }
  } else {
    enqueueOffline(uidStr.c_str(), dateStr.c_str(), timeStr.c_str(),
                   action, eventID.c_str());
    lcdPrint(action, "Queued offline");
    beepQueued();
    Serial.println("[WiFi] Offline - tap queued.");
  }

  scheduleLcdReset();
}

// Remote Enrollment Poll
void pollForRemoteEnrollment() {
  WiFiClient client;
  HTTPClient http;

  String url = String(API_URL) + "?action=poll&api_key=" + String(API_KEY);
  http.begin(client, url);
  http.setTimeout(5000);
  int code = http.GET();

  if (code != 200) {
    http.end();
    return;
  }

  String resp = http.getString();
  http.end();

  StaticJsonDocument<256> doc;
  if (deserializeJson(doc, resp)) return;

  bool pending = doc["pending"] | false;
  if (!pending) return;

  const char* token     = doc["token"]      | "";
  const char* username  = doc["username"]   | "Student";
  int         expiresIn = doc["expires_in"] | 60;

  strncpy(remoteEnrollToken, token,    64); remoteEnrollToken[64] = '\0';
  strncpy(remoteEnrollUser,  username, 31); remoteEnrollUser[31]  = '\0';

  remoteEnrollActive = true;
  remoteEnrollExpiry = millis() + (unsigned long)(expiresIn * 1000UL);

  Serial.printf("[RemoteEnroll] Mode ON for: %s (token: %.8s...)\n",
                remoteEnrollUser, remoteEnrollToken);
  lcdPrint("Tap to register:", remoteEnrollUser);
}

// HTTP - POST TO API
bool postToAPI(const char* uid, const char* date, const char* time,
               const char* action, const char* eventID, char* resolvedName) {

  StaticJsonDocument<256> doc;
  doc["api_key"]  = API_KEY;
  doc["uid"]      = uid;
  doc["date"]     = date;
  doc["time"]     = time;
  doc["action"]   = action;
  doc["event_id"] = eventID;
  doc["device"]   = "esp32_rfid_01";

  String body;
  serializeJson(doc, body);
  Serial.println("[HTTP] POST -> " + body);

  WiFiClient client;
  HTTPClient http;
  http.begin(client, API_URL);
  http.setTimeout(10000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  int code = http.POST(body);
  if (code <= 0) {
    Serial.printf("[HTTP] Error: %s\n", http.errorToString(code).c_str());
    http.end();
    return false;
  }

  String resp = http.getString();
  http.end();
  Serial.printf("[HTTP] %d - %s\n", code, resp.c_str());
  if (code != 200) return false;

  StaticJsonDocument<512> res;
  if (deserializeJson(res, resp)) return false;

  const char* status = res["status"] | "error";
  const char* name   = res["name"]   | "";

  if (resolvedName && strlen(name) > 0) {
    strncpy(resolvedName, name, 31);
    resolvedName[31] = '\0';
  }

  return strcmp(status, "ok") == 0
      || strcmp(status, "duplicate") == 0
      || strcmp(status, "already_timed_out") == 0
      || strcmp(status, "already_timed_in")  == 0;
}

// Offline Queue (SPIFFS)
void enqueueOffline(const char* uid, const char* date, const char* time,
                    const char* action, const char* eventID) {
  DynamicJsonDocument doc(8192);

  if (SPIFFS.exists(QUEUE_FILE)) {
    File f = SPIFFS.open(QUEUE_FILE, "r");
    if (deserializeJson(doc, f)) {
      doc.to<JsonArray>();
    }
    f.close();
  } else {
    doc.to<JsonArray>();
  }

  JsonArray arr = doc.as<JsonArray>();

  if ((int)arr.size() >= MAX_QUEUE) {
    DynamicJsonDocument tmp(8192);
    JsonArray newArr = tmp.to<JsonArray>();
    for (size_t i = 1; i < arr.size(); i++) newArr.add(arr[i]);
    doc = tmp;
    arr = doc.as<JsonArray>();
    Serial.println("[Queue] Full - oldest entry dropped");
  }

  JsonObject entry = arr.createNestedObject();
  entry["uid"]      = uid;
  entry["date"]     = date;
  entry["time"]     = time;
  entry["action"]   = action;
  entry["event_id"] = eventID;

  File fw = SPIFFS.open(QUEUE_FILE, "w");
  serializeJson(doc, fw);
  fw.close();

  Serial.printf("[Queue] Stored (%d in queue): %s %s\n", (int)arr.size(), uid, action);
}

void flushQueue() {
  if (!SPIFFS.exists(QUEUE_FILE)) return;

  File f = SPIFFS.open(QUEUE_FILE, "r");
  DynamicJsonDocument doc(8192);
  if (deserializeJson(doc, f)) {
    f.close();
    return;
  }
  f.close();

  JsonArray arr = doc.as<JsonArray>();
  if (arr.size() == 0) return;

  Serial.printf("[Queue] Flushing %d entr(ies)...\n", (int)arr.size());

  DynamicJsonDocument remaining(8192);
  JsonArray leftover  = remaining.to<JsonArray>();
  bool anyFailed = false;

  for (JsonObject entry : arr) {
    char resolvedName[32] = "";
    bool ok = postToAPI(
      entry["uid"]      | "",
      entry["date"]     | "",
      entry["time"]     | "",
      entry["action"]   | "",
      entry["event_id"] | "",
      resolvedName);

    if (!ok) {
      leftover.add(entry);
      anyFailed = true;
    } else {
      Serial.println("[Queue] Flushed: " + String(entry["uid"] | "?")
                     + " " + String(entry["action"] | ""));
    }
  }

  File fw = SPIFFS.open(QUEUE_FILE, "w");
  serializeJson(remaining, fw);
  fw.close();

  if (!anyFailed && (int)arr.size() > 0) {
    lcdPrint("Queue flushed!", "");
    scheduleLcdReset();
    Serial.println("[Queue] All entries sent.");
  }
}

void clearQueue() {
  if (SPIFFS.exists(QUEUE_FILE)) {
    SPIFFS.remove(QUEUE_FILE);
    Serial.println("[Queue] Cleared manually.");
  }
}

// WiFi
void connectWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.disconnect(true);
  delay(500);
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  lcdPrint("WiFi Connecting", WIFI_SSID);
  Serial.println("\n[WiFi] Connecting to: " + String(WIFI_SSID));

  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 20000) {
    delay(500);
    Serial.print(".");
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[WiFi] Connected: " + WiFi.localIP().toString());
    lcdPrint("WiFi Connected", WiFi.localIP().toString().c_str());
    delay(2000);
  } else {
    Serial.println("\n[WiFi] Failed to connect");
    lcdPrint("WiFi FAILED", "Offline mode");
    delay(2000);
  }
}

void ensureWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;
  static unsigned long lastRetry = 0;
  if (millis() - lastRetry < 10000) return;
  lastRetry = millis();
  Serial.println("[WiFi] Reconnecting...");
  WiFi.disconnect();
  delay(300);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
}

// NTP / TIME
bool tryNTP() {
  struct tm t;
  Serial.print("[NTP] Syncing");
  for (int i = 0; i < 10 && !getLocalTime(&t); i++) {
    delay(500);
    Serial.print(".");
  }
  bool ok = getLocalTime(&t);
  Serial.println(ok ? "\n[NTP] Synced" : "\n[NTP] Failed");
  return ok;
}

String getDate() {
  struct tm t;
  if (!getLocalTime(&t)) return "0000-00-00";
  char buf[12];
  strftime(buf, sizeof(buf), "%Y-%m-%d", &t);
  return String(buf);
}

String getTimestamp() {
  struct tm t;
  if (!getLocalTime(&t)) return "00:00:00";
  char buf[10];
  strftime(buf, sizeof(buf), "%H:%M:%S", &t);
  return String(buf);
}

bool isDateValid(const String& d) {
  return d.length() == 10
      && d != "0000-00-00"
      && d.charAt(4) == '-'
      && d.charAt(7) == '-'
      && d.substring(0, 4).toInt() >= 2020;
}

// Session Tracking
Session* findSession(byte* uid, const char* date) {
  for (int i = 0; i < sessionCount; i++) {
    bool match = true;
    for (int j = 0; j < 4; j++) {
      if (sessions[i].uid[j] != uid[j]) {
        match = false;
        break;
      }
    }
    if (match && strcmp(sessions[i].date, date) == 0) return &sessions[i];
  }
  return nullptr;
}

void createSession(byte* uid, const char* date) {
  if (sessionCount >= MAX_SESSIONS) sessionCount = 0;
  memcpy(sessions[sessionCount].uid, uid, 4);
  strncpy(sessions[sessionCount].date, date, 11);
  sessions[sessionCount].date[11] = '\0';
  sessions[sessionCount].timedOut = false;
  sessionCount++;
}

// Web Portal - Shared HTML Head
static const char HTML_HEAD[] PROGMEM = R"HTML(
<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1'>
<title>TapTime RFID Portal</title>
<link rel='preconnect' href='https://fonts.googleapis.com'>
<link href='https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@400;500;600;700&display=swap' rel='stylesheet'>
<style>
:root {
  --gold: #c8a96e;
  --gold-light: #e2c99a;
  --glass-bg: rgba(255,255,255,0.06);
  --glass-bg-md: rgba(255,255,255,0.10);
  --glass-border: rgba(200,169,110,0.22);
  --text-main: #f0ece4;
  --text-sub: rgba(240,236,228,0.74);
  --text-muted: rgba(240,236,228,0.50);
  --ok: #4ade80;
  --warn: #fbbf24;
  --err: #f87171;
  --radius: 12px;
  --radius-lg: 18px;
}

* { box-sizing: border-box; }

body {
  margin: 0;
  font-family: 'DM Sans', sans-serif;
  color: var(--text-main);
  min-height: 100vh;
  overflow-x: hidden;
  background: #0a0e14;
}

.bg-layer {
  position: fixed;
  inset: 0;
  background:
    radial-gradient(1200px 600px at -15% -10%, rgba(200,169,110,0.20), transparent 60%),
    radial-gradient(900px 500px at 115% 110%, rgba(200,169,110,0.12), transparent 60%),
    linear-gradient(155deg, #0b1119 0%, #0a0e14 55%, #101826 100%);
  z-index: -2;
}

.bg-overlay {
  position: fixed;
  inset: 0;
  background: linear-gradient(135deg, rgba(10,14,20,0.95) 0%, rgba(10,14,20,0.88) 100%);
  z-index: -1;
}

.portal {
  max-width: 980px;
  margin: 0 auto;
  padding: 18px 14px 30px;
}

.hero {
  background: var(--glass-bg);
  border: 1px solid var(--glass-border);
  border-radius: var(--radius-lg);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  padding: 18px 18px 16px;
  margin-bottom: 12px;
}

.eyebrow {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--gold);
  margin-bottom: 6px;
}

.hero h1 {
  margin: 0;
  font-family: 'Cormorant Garamond', serif;
  font-size: clamp(1.5rem, 4.8vw, 2.1rem);
  font-weight: 400;
  line-height: 1.2;
}

.hero .sub {
  margin: 8px 0 0;
  color: var(--text-sub);
  font-size: 0.92rem;
}

.badge-row {
  margin-top: 12px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.badge {
  display: inline-flex;
  align-items: center;
  border: 1px solid var(--glass-border);
  border-radius: 999px;
  padding: 5px 10px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.badge.ok {
  color: var(--ok);
  background: rgba(74,222,128,0.12);
  border-color: rgba(74,222,128,0.28);
}

.badge.err {
  color: var(--err);
  background: rgba(248,113,113,0.12);
  border-color: rgba(248,113,113,0.28);
}

.notice {
  border-radius: 10px;
  padding: 10px 12px;
  margin-bottom: 10px;
  font-size: 0.88rem;
  line-height: 1.45;
}

.notice.ok {
  color: var(--ok);
  background: rgba(74,222,128,0.12);
  border: 1px solid rgba(74,222,128,0.30);
}

.notice.warn {
  color: var(--warn);
  background: rgba(251,191,36,0.12);
  border: 1px solid rgba(251,191,36,0.30);
}

.notice.err {
  color: var(--err);
  background: rgba(248,113,113,0.12);
  border: 1px solid rgba(248,113,113,0.30);
}

.grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}

.card {
  background: var(--glass-bg);
  border: 1px solid var(--glass-border);
  border-radius: var(--radius-lg);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  overflow: hidden;
}

.card-head {
  border-bottom: 1px solid var(--glass-border);
  background: rgba(200,169,110,0.12);
  padding: 12px 14px;
}

.card-head h3 {
  margin: 0;
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.2rem;
  font-weight: 600;
  color: var(--gold-light);
}

.card-body {
  padding: 14px;
}

label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 7px;
}

input[type=text] {
  width: 100%;
  border: 1px solid var(--glass-border);
  border-radius: 10px;
  background: rgba(255,255,255,0.05);
  color: var(--text-main);
  padding: 11px 12px;
  font-family: 'DM Sans', sans-serif;
  font-size: 0.90rem;
  margin-bottom: 10px;
}

input[type=text]:focus {
  outline: none;
  border-color: rgba(200,169,110,0.50);
  box-shadow: 0 0 0 3px rgba(200,169,110,0.16);
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border: 1px solid transparent;
  border-radius: 9px;
  padding: 10px 12px;
  font-family: 'DM Sans', sans-serif;
  font-size: 0.84rem;
  font-weight: 600;
  cursor: pointer;
  width: 100%;
}

.btn-primary {
  background: rgba(200,169,110,0.18);
  border-color: rgba(200,169,110,0.34);
  color: var(--gold-light);
}

.btn-primary:hover {
  background: rgba(200,169,110,0.26);
}

.btn-warn {
  background: rgba(251,191,36,0.14);
  border-color: rgba(251,191,36,0.36);
  color: var(--warn);
}

.btn-del {
  width: auto;
  padding: 6px 10px;
  font-size: 0.76rem;
  background: rgba(248,113,113,0.14);
  border-color: rgba(248,113,113,0.35);
  color: var(--err);
}

#msg {
  margin-top: 10px;
}

table {
  width: 100%;
  border-collapse: collapse;
  min-width: 420px;
}

.table-wrap {
  overflow-x: auto;
}

th {
  text-align: left;
  font-size: 0.70rem;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  color: var(--gold);
  padding: 10px 12px;
  border-bottom: 1px solid var(--glass-border);
  background: rgba(200,169,110,0.10);
  white-space: nowrap;
}

td {
  border-bottom: 1px solid rgba(200,169,110,0.10);
  padding: 10px 12px;
  font-size: 0.86rem;
  color: var(--text-sub);
  vertical-align: middle;
}

tbody tr:hover {
  background: rgba(255,255,255,0.04);
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.80rem;
}

.muted {
  color: var(--text-muted);
}

@media (min-width: 880px) {
  .grid {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
</head>
<body>
)HTML";

String htmlEscape(const String& input) {
  String out;
  out.reserve(input.length() + 16);

  for (size_t i = 0; i < input.length(); i++) {
    const char ch = input.charAt(i);
    switch (ch) {
      case '&':
        out += F("&amp;");
        break;
      case '<':
        out += F("&lt;");
        break;
      case '>':
        out += F("&gt;");
        break;
      case '"':
        out += F("&quot;");
        break;
      case '\'':
        out += F("&#39;");
        break;
      default:
        out += ch;
        break;
    }
  }

  return out;
}

// Web Portal - Root
void handleRoot() {
  String wifiBadge = WiFi.status() == WL_CONNECTED
    ? "<span class='badge ok'>WiFi online</span>"
    : "<span class='badge err'>WiFi offline</span>";

  String ntpBadge = ntpSynced
    ? "<span class='badge ok'>NTP synced</span>"
    : "<span class='badge err'>NTP not synced</span>";

  int queueSize = 0;
  if (SPIFFS.exists(QUEUE_FILE)) {
    File f = SPIFFS.open(QUEUE_FILE, "r");
    DynamicJsonDocument qd(8192);
    if (!deserializeJson(qd, f)) {
      queueSize = qd.as<JsonArray>().size();
    }
    f.close();
  }

  String ntpWarn = "";
  if (!ntpSynced) {
    ntpWarn = "<div class='notice err'>NTP is not synced yet. Attendance is blocked until time sync is valid.</div>";
  }

  String queueWarn = "";
  if (queueSize > 0) {
    queueWarn = "<div class='notice warn'>Offline queue contains " + String(queueSize) + " tap(s). It will auto flush when connection is restored.</div>";
  }

  String enrollBanner = "";
  if (enrollMode) {
    enrollBanner = "<div class='notice ok'>Waiting for enrollment tap: <strong>" + htmlEscape(String(pendingName)) + "</strong></div>";
  }

  String remoteBanner = "";
  if (remoteEnrollActive) {
    remoteBanner = "<div class='notice ok'>Remote enrollment active for: <strong>" + htmlEscape(String(remoteEnrollUser)) + "</strong></div>";
  }

  String html = String(HTML_HEAD);

  html += "<div class='bg-layer'></div>";
  html += "<div class='bg-overlay'></div>";
  html += "<main class='portal'>";
  html += "<header class='hero'>";
  html += "<div class='eyebrow'>Control Center</div>";
  html += "<h1>TapTime RFID Portal</h1>";
  html += "<p class='sub'>Enrollment and maintenance with the same visual language as your admin dashboard.</p>";
  html += "<div class='badge-row'>" + wifiBadge + ntpBadge + "</div>";
  html += "</header>";

  html += ntpWarn;
  html += queueWarn;
  html += enrollBanner;
  html += remoteBanner;

  html += R"HTML(
<div class='grid'>
  <section class='card'>
    <div class='card-head'>
      <h3>Register New Student</h3>
    </div>
    <div class='card-body'>
      <label for='nm'>Student Name</label>
      <input type='text' id='nm' maxlength='63' placeholder='Enter full name'>
      <button type='button' class='btn btn-primary' onclick='startEnroll()'>Start Enrollment</button>
      <div id='msg'></div>
    </div>
  </section>

  <section class='card'>
    <div class='card-head'>
      <h3>Maintenance</h3>
    </div>
    <div class='card-body'>
      <button type='button' class='btn btn-warn' onclick='clearQueue()'>Clear Offline Queue</button>
      <p class='muted' style='margin:10px 0 0;font-size:0.80rem;'>This removes unsent tap logs saved in SPIFFS.</p>
    </div>
  </section>
</div>

<section class='card' style='margin-top:12px;'>
  <div class='card-head'>
    <h3>Registered Students</h3>
  </div>
  <div class='card-body'>
    <div class='table-wrap'>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>UID</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id='tbody'>
          <tr><td colspan='4' class='muted'>Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script>
'use strict';

const msgEl = document.getElementById('msg');
const tbody = document.getElementById('tbody');

function esc(value) {
  return String(value).replace(/[&<>"']/g, function (ch) {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    };
    return map[ch] || ch;
  });
}

function setMsg(kind, text) {
  if (!msgEl) return;
  msgEl.innerHTML = "<div class='notice " + kind + "'>" + esc(text) + "</div>";
}

async function startEnroll() {
  const nm = document.getElementById('nm').value.trim();
  if (!nm) {
    alert('Enter a name first.');
    return;
  }

  setMsg('warn', 'Sending enrollment request...');

  try {
    const res = await fetch('/enroll/start', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'name=' + encodeURIComponent(nm)
    });

    const text = await res.text();
    if (!res.ok) {
      setMsg('err', text || 'Enrollment request failed.');
      return;
    }

    setMsg('ok', text || 'Enrollment started.');
  } catch (err) {
    setMsg('err', 'Request failed. Check WiFi and API availability.');
  }
}

async function deleteStudent(name) {
  if (!name) return;
  if (!confirm('Delete this student card?')) return;

  try {
    const res = await fetch('/delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'name=' + encodeURIComponent(name)
    });

    if (!res.ok) {
      setMsg('err', 'Delete failed.');
      return;
    }

    setMsg('ok', 'Student card removed.');
    await loadStudents();
  } catch (err) {
    setMsg('err', 'Delete request failed.');
  }
}

async function loadStudents() {
  if (!tbody) return;

  try {
    const res = await fetch('/students');
    const data = await res.json();

    if (!res.ok || !data || !Array.isArray(data.cards)) {
      tbody.innerHTML = "<tr><td colspan='4' class='muted'>Student list unavailable.</td></tr>";
      return;
    }

    if (data.cards.length === 0) {
      tbody.innerHTML = "<tr><td colspan='4' class='muted'>No students registered yet.</td></tr>";
      return;
    }

    let rows = '';
    data.cards.forEach((card, idx) => {
      const safeName = esc(card.name || 'Unknown');
      const safeUid = esc(card.uid || '-');
      rows += "<tr>"
        + "<td>" + (idx + 1) + "</td>"
        + "<td><strong>" + safeName + "</strong></td>"
        + "<td class='mono'>" + safeUid + "</td>"
        + "<td><button type='button' class='btn btn-del js-del' data-name='" + safeName + "'>Delete</button></td>"
        + "</tr>";
    });

    tbody.innerHTML = rows;

    Array.prototype.forEach.call(document.querySelectorAll('.js-del'), function (button) {
      button.addEventListener('click', function () {
        deleteStudent(button.getAttribute('data-name') || '');
      });
    });
  } catch (err) {
    tbody.innerHTML = "<tr><td colspan='4' class='muted'>WiFi offline. Cannot load list.</td></tr>";
  }
}

async function clearQueue() {
  if (!confirm('Clear all offline queued taps?')) return;

  try {
    const res = await fetch('/queue/clear', { method: 'POST' });
    const text = await res.text();
    alert(text || 'Offline queue cleared.');
    location.reload();
  } catch (err) {
    alert('Failed to clear queue.');
  }
}

loadStudents();
</script>
</main>
</body>
</html>
)HTML";

  server.send(200, "text/html", html);
}

void handleEnrollStart() {
  if (!server.hasArg("name") || server.arg("name").isEmpty()) {
    server.send(400, "text/plain", "Name required");
    return;
  }

  String nm = server.arg("name");
  nm.trim();
  strncpy(pendingName, nm.c_str(), 31);
  pendingName[31] = '\0';

  enrollMode  = true;
  enrollStart = millis();
  lcdPrint("Enroll:", pendingName);
  Serial.println("[Enroll] Started for: " + nm);

  server.send(200, "text/plain", "Tap RFID card for: " + nm + " (15 s window)");
}

void handleEnrollStatus() {
  StaticJsonDocument<128> doc;
  doc["enrollMode"] = enrollMode;
  doc["waiting"]    = enrollMode;
  doc["message"]    = enrollMode
    ? String("Tap card for: ") + pendingName
    : "Idle";

  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

void handleStudentList() {
  if (WiFi.status() != WL_CONNECTED) {
    server.send(503, "application/json", "{\"status\":\"error\",\"msg\":\"WiFi offline\"}");
    return;
  }

  WiFiClient client;
  HTTPClient http;
  String url = String(API_URL) + "?action=list&api_key=" + String(API_KEY);

  http.begin(client, url);
  http.setTimeout(8000);
  int code = http.GET();
  String body = (code > 0) ? http.getString() : "";
  http.end();

  if (code == 200) {
    server.send(200, "application/json", body);
  } else {
    server.send(500, "application/json", "{\"status\":\"error\",\"msg\":\"Server unreachable\"}");
  }
}

void handleDeleteStudent() {
  if (!server.hasArg("name")) {
    server.send(400, "text/plain", "Name required");
    return;
  }

  if (WiFi.status() != WL_CONNECTED) {
    server.send(503, "text/plain", "WiFi offline - cannot delete");
    return;
  }

  StaticJsonDocument<128> doc;
  doc["action"] = "delete";
  doc["name"]   = server.arg("name");

  String body;
  serializeJson(doc, body);

  WiFiClient client;
  HTTPClient http;
  http.begin(client, API_URL);
  http.setTimeout(8000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  int code = http.POST(body);
  String resp = (code > 0) ? http.getString() : "";
  http.end();

  Serial.println("[DELETE RESP] " + resp);

  if (code == 200) {
    server.sendHeader("Location", "/");
    server.send(303);
  } else {
    server.send(500, "text/plain", "Delete failed");
  }
}

void handleQueueClear() {
  clearQueue();
  server.send(200, "text/plain", "Offline queue cleared.");
}

bool parseUid4(const String& text, uint8_t outUid[4]) {
  String uid = text;
  uid.trim();
  uid.toUpperCase();

  if (uid.length() != 11) return false;

  for (int i = 0; i < 4; i++) {
    int pos = i * 3;
    if (i < 3 && uid.charAt(pos + 2) != ':') return false;

    String part = uid.substring(pos, pos + 2);
    char* endPtr = nullptr;
    long value = strtol(part.c_str(), &endPtr, 16);
    if (endPtr == nullptr || *endPtr != '\0' || value < 0 || value > 255) {
      return false;
    }

    outUid[i] = static_cast<uint8_t>(value);
  }

  return true;
}

void handleSessionsReset() {
  String uidArg = server.arg("uid");
  uidArg.trim();

  if (uidArg.length() == 0) {
    memset(sessions, 0, sizeof(sessions));
    sessionCount = 0;
    Serial.println("[Sessions] Reset all in-memory sessions.");
    server.send(200, "text/plain", "All offline sessions cleared.");
    return;
  }

  uint8_t targetUid[4] = {0, 0, 0, 0};
  if (!parseUid4(uidArg, targetUid)) {
    server.send(400, "text/plain", "Invalid UID format. Use AA:BB:CC:DD");
    return;
  }

  int kept = 0;
  int removed = 0;

  for (int i = 0; i < sessionCount; i++) {
    bool isMatch = true;
    for (int j = 0; j < 4; j++) {
      if (sessions[i].uid[j] != targetUid[j]) {
        isMatch = false;
        break;
      }
    }

    if (isMatch) {
      removed++;
      continue;
    }

    if (kept != i) {
      sessions[kept] = sessions[i];
    }
    kept++;
  }

  sessionCount = kept;

  char message[96];
  snprintf(message, sizeof(message), "Cleared %d session(s) for UID %s", removed, uidArg.c_str());
  Serial.println(String("[Sessions] ") + message);
  server.send(200, "text/plain", message);
}

// Buzzer and LED
void beepOK() {
  for (int i = 0; i < 2; i++) {
    digitalWrite(LED, HIGH);
    digitalWrite(BUZZER, HIGH);
    delay(80);

    digitalWrite(BUZZER, LOW);
    digitalWrite(LED, LOW);
    delay(50);
  }
}

void beepFail() {
  for (int i = 0; i < 3; i++) {
    digitalWrite(BUZZER, HIGH);
    delay(70);
    digitalWrite(BUZZER, LOW);
    delay(70);
  }
}

void beepQueued() {
  digitalWrite(BUZZER, HIGH);
  delay(200);
  digitalWrite(BUZZER, LOW);
  delay(80);
  digitalWrite(BUZZER, HIGH);
  delay(80);
  digitalWrite(BUZZER, LOW);
}

// LCD
void lcdPrint(const char* line1, const char* line2) {
  lcd.clear();
  delay(5);
  lcd.setCursor(0, 0);
  lcd.print(String(line1).substring(0, 16));
  lcd.setCursor(0, 1);
  lcd.print(String(line2).substring(0, 16));
}

void scheduleLcdReset() {
  lcdNeedsReset = true;
  lcdResetAt    = millis() + LCD_HOLD_MS;
}

// Utilities
String uidToString(byte* uid) {
  String s;
  for (int i = 0; i < 4; i++) {
    if (uid[i] < 0x10) s += "0";
    s += String(uid[i], HEX);
    if (i < 3) s += ":";
  }
  s.toUpperCase();
  return s;
}

// FNV-1a 32-bit hash of uid+date+time -> hex string (8 chars)
String generateEventID(const char* uid, const char* date, const char* time) {
  String raw = String(uid) + date + time;
  uint32_t h = 2166136261u;
  for (unsigned int i = 0; i < raw.length(); i++) {
    h ^= (uint8_t)raw[i];
    h *= 16777619u;
  }
  return String(h, HEX);
}
