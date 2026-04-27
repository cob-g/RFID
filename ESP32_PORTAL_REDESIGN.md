# ESP32 RFID Portal Redesign Patch

This patch updates the embedded web portal design so it visually matches your admin dashboard (dark glass + gold accents), while keeping the same endpoints and behavior.

## 1) Add this helper function before `handleRoot()`

```cpp
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
```

## 2) Replace your existing `HTML_HEAD` with this

```cpp
static const char HTML_HEAD[] PROGMEM = R"HTML(
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TapTime RFID Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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
```

## 3) Replace your existing `handleRoot()` with this

```cpp
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
```

## 4) Optional: add `/sessions/reset` endpoint support

Your admin dashboard already calls `/sessions/reset` through the proxy. Add this if you want that button to fully work on firmware side.

### 4.1 Add forward declarations

Add these near your other declarations:

```cpp
void handleSessionsReset();
bool parseUid4(const String& text, uint8_t outUid[4]);
```

### 4.2 Register route in `setup()`

Add this with your other `server.on(...)` lines:

```cpp
server.on("/sessions/reset", HTTP_POST, handleSessionsReset);
```

### 4.3 Add helpers and handler (place near other web route handlers)

```cpp
bool parseUid4(const String& text, uint8_t outUid[4]) {
  String uid = text;
  uid.trim();
  uid.toUpperCase();

  if (uid.length() != 11) return false; // AA:BB:CC:DD

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
```

## 5) Compile + upload

After replacing those sections, compile and upload to your ESP32 as usual.

If you want, I can also generate a fully merged single-file firmware output with all these changes already stitched into your original sketch.
