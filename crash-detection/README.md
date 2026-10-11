# AVISO IoT — crash detection unit

ESP32 + GY-BNO055 + passive buzzer + buck converter, mounted rigidly at the
Honda Click 125 luggage hook. Detects **normal riding, hard braking, road
bumps and crashes**, streams live data to the AVISO rider app over the
rider's **phone hotspot**, and can report a crash to the server by itself if
the app does not respond.

## Folders

Each folder is one Arduino sketch (the Arduino IDE compiles every file in a
folder together, so sketches must not share a folder).

| Folder | Purpose |
|---|---|
| `aviso-iot/` | **Field firmware** — runs on the bike |
| `threshold-gathering/` | Records labelled 30 s sessions at 100 Hz to set the thresholds |
| `retrieval-littlefs/` | Prints the recorded files over USB serial (waits for Enter) |
| `storage-cleanup/` | Deletes the recorded files (only after typing `DELETE`) |
| `format-littlefs/` | Erases damaged storage (only after typing `FORMAT`) |
| `calibration/` | Calibrates the BNO055 once and saves its offsets |
| `testing-buzzer/` | Finds the buzzer's loudest tone (2000 Hz) |
| `crash-detection/` | Early field logger, superseded by `aviso-iot` (reference only) |
| `tools/check_thr_csv.py` | Checks retrieved CSVs before they are used |

`calibration/` saves the offsets to EEPROM (flag `0x55` at address 0, offsets
from address 1); `threshold-gathering` and `aviso-iot` load them at boot.

## Wiring

| Part | ESP32 |
|---|---|
| BNO055 SDA / SCL | GPIO 21 / GPIO 22 |
| BNO055 VIN / GND | 3V3 / GND (address 0x29, as wired now) |
| Passive buzzer | GPIO 4 (other leg to GND) |
| Status LED | GPIO 2 (on-board) |
| Buck converter out (5 V) | 5V/VIN + GND |
| Buck converter in | Motorcycle 12 V switched (key-on) line **through an inline fuse** |

Mounting: **bolt or tightly zip-tie** the box — it must not hang or swing on
the hook. Fix the BNO055 flat to the box (not floating on its wires), and
mount it **the same way every time**: the brake direction depends on it.
Seal the cable opening against rain. A reset reason of `BROWNOUT` means the
power is dipping — check the buck converter and wiring.

## Arduino IDE setup

- Board: **ESP32 Dev Module**, ESP32 core **3.x** (Boards Manager: "esp32" by Espressif)
- Partitions: each tool folder contains the same **`partitions.csv`**
  (1.5 MB app, ~2.4 MB storage). The IDE uses it instead of the Partition
  Scheme menu, so every tool sees the same storage. Keep it next to the `.ino`.
- Libraries (Library Manager):
  - Adafruit BNO055 + Adafruit Unified Sensor
  - WebSockets by Markus Sattler (Links2004) — `aviso-iot` only
  - ArduinoJson by Benoit Blanchon (**v7**) — `aviso-iot` only

`aviso-iot/` has no `partitions.csv` yet. Flash it with PlatformIO
(`crash-detection-v3`, `pio run -e aviso-iot -t upload`, same layout), or copy
the same `partitions.csv` into the folder first. Otherwise its storage layout
differs from the tools and they cannot read each other's files.

## First-time setup

1. In the AVISO app: **Profile → IoT Device → Get pairing code** (valid 10 min).
2. Power the unit. With no saved hotspot it opens Wi-Fi **`AVISO-SETUP-xxxx`**
   (password `aviso1234`). Connect your phone to it and open **http://192.168.4.1**.
3. Enter your **phone hotspot name + password**, the **server URL**, and the
   **pairing code**. Up to 10 hotspots are remembered (e.g. each team member's).
4. Turn on that phone hotspot. The unit joins it, pairs (one short beep) and
   from then on joins automatically whenever the hotspot is on.
5. With the bike upright on the **center stand**, tap **Calibrate upright** in
   the app. Tilt is measured from this position.

Server URL before hosting: connect the laptop running Laravel to the **same
phone hotspot** and use its hotspot IP, e.g. `http://192.168.43.50:8000`
(run `php artisan serve --host=0.0.0.0`). The unit can only reach a server
the hotspot can reach.

## How detection works

Detection runs **on the unit at 100 Hz**; the app receives events and 10 Hz
live data. The numbers below are the **first estimates** in
`aviso-iot/config.h`; they are being replaced with values measured by
`threshold-gathering` (keep `aviso-mobile/constants/detections.ts` in sync).

- **G-force** = linear acceleration (gravity removed → **0 g at rest**), split
  into **vertical** (up/down) and **horizontal** (forward/back/sideways).
- **Gyro** = rotation speed in °/s. **Turn rate** = rotation around vertical.
- **Tilt** = angle between gravity now and the saved upright direction (works
  for a fall in any direction; the side stand is only ~10–15°).

| Category | Rule (current values) |
|---|---|
| Normal | none of the below; a **summary every 10 s** (highest values in that window) |
| Bump | vertical ≥ 0.8 g while upright |
| Hard braking | horizontal ≥ 0.45 g for ≥ 200 ms, rotation ≤ 60 °/s |
| **Crash** | **hit** (≥ 3 g or ≥ 250 °/s) → **tilt ≥ 60°** within 3 s → fallen **and still** (≤ 30 °/s) for 3 s |
| Crash (no hit) | tilt ≥ 60° and still for 5 s **during a ride** (slow slide/tip-over) |

Back upright (≤ 30°) before confirmation → nothing happens.

**What the field data shows so far** (Oct 2026: 11 normal, 4 brake, 6 bump sessions):

- **Brake rule works:** 0 false alarms in normal riding, 3–8 detections per
  brake session. Braking always lies along one sensor axis (fixed mount), so a
  direction-aware rule is planned to also rule out cornering.
- **Bump rule fires far too often:** 7–25 times per 30 s of normal riding,
  because single raw samples are mostly engine and road vibration. Planned:
  average over ~100 ms, then ~0.9 g.
- **A hit alone means little:** normal riding reached 3.38 g and bumps hit the
  4 g sensor limit. The **fall (tilt ≥ 60°, then still)** is what separates a
  crash; the highest tilt seen so far is 35°.
- **Known issue:** every hit ≥ 3 g starts a crash check, which pauses bump
  and brake detection for up to 3 s.

**Sensor limit:** in fusion mode the BNO055 accelerometer is fixed at ±4 g,
so harder impacts read as ~4 g (keep the hit threshold below 4 g).

## Crash flow

1. Crash confirmed → **buzzer alarm** (also alerts bystanders), black box
   saves 5 s before + 5 s after, the crash is sent to the app every second
   until the app acknowledges.
2. App shows the **15 s countdown**. "I'm OK" → app sends `cancel` → alarm
   stops, nothing is reported.
3. Countdown ends → the app sends the SOS (with the crash id) and tells the
   unit `sos_sent`.
4. **Backup:** if after 25 s the unit got neither `cancel` nor `sos_sent`
   (app closed, phone busy), it posts the crash to `/api/iot/crash` itself.
   The server uses the rider's last trip position (the unit has no GPS).
5. Both paths carry the same crash id, so the server raises **one** alert.

## App ↔ unit protocol (WebSocket `ws://<unit-ip>:81`, JSON)

| Direction | Message |
|---|---|
| unit → app | `hello` (uid, fw, reset reason, paired, calibration) · `tel` 10 Hz (`g`, `vg`, `hg`, `gy`, `yaw`, `tilt`, `st`) · `evt` (`normal` / `road_bump` / `hard_braking` / `crash`, with `id`, `peak_g`, `vg`, `hg`, `peak_gyro`, `tilt`) · `crash_cleared` · `calibrated` |
| app → unit | `ack` · `cancel` · `sos_sent` · `ride` (`active`) · `calibrate_upright` · `beep` |

The app finds the unit's IP from the server (the unit reports it in its
heartbeat every 30 s) or from a manually entered IP.

Black box files: `http://<unit-ip>/blackbox` (list) and
`http://<unit-ip>/blackbox/<name>` (CSV).

## Crash detection logs

During a ride, every event the unit classifies (all four categories) is
uploaded by the app with the phone's GPS and stored in the server's
`rider_events` table. Events are queued on the phone when there is no signal.

- **Admin web → Crash Detection Logs**: filters (category, barangay, rider,
  dates), a *Threshold reference* table (min / avg / max of every sensor
  value per category) and **Download CSV**.
- **Rider app → Profile → Crash Detection Logs**: the rider's own records,
  per-category ranges and **Download CSV** (share/save).

These logs show whether the thresholds separate the categories in real
riding (e.g. normal riding's max vertical G should stay below
`BUMP_VERTICAL_G`).

## Gathering thresholds (do this before trusting detection)

1. Upload `calibration/` once and follow its steps (still, then 6
   orientations). Three rising tones = saved.
2. Mount the box in its **final** position. The gathering sketch warns
   ("mount moved", 3 beeps) if a ride session sees a different mount.
3. In `threshold-gathering.ino` set `sessionMode` and `sessionNote`
   (e.g. `"25kmh"`), upload, check the beep count (= `sessionMode` + 1).

   | `sessionMode` | Category | Target sessions |
   |---|---|---|
   | 0 | normal riding | 12 |
   | 1 | hard brake | 6 |
   | 2 | road bump (speed in `sessionNote`) | 10 |
   | 3 | crash — **drop rig only, never with a rider** | 6 (incl. 2 slow tip-overs) |
   | 4 | bench / idle | 4 |
   | 5 | tilt (idle, ~10° each side) | 2 |
   | 6 | parking (side stand, park, get on/off) | 3 |

4. Each power-up = one session: 15 s of ticks to get ready, category beeps,
   upright capture (bike upright and steady; the engine may run), low start
   tone, 30 s window, then 3 falling tones = saved. For crash / tilt /
   parking, start upright, then tip / lean / stand it.
5. Every 3–4 sessions: upload `retrieval-littlefs`, press Enter in the Serial
   Monitor, save each BEGIN/END block as its own `.csv`, and check them with
   `python tools/check_thr_csv.py <folder>`. Then upload `storage-cleanup`
   and type `DELETE`.
6. The log's `linearG`, `verticalG`, `horizontalG`, `gyroDps` and `tiltDeg`
   columns are computed exactly like the field firmware, so thresholds come
   straight from the gaps between categories. Rows with `clipped=1` hit the
   4 g sensor limit (the true peak was higher). Keep 2 sessions per category
   aside to test the final thresholds on.

## Bench test (box on a table, app connected)

| Action | Expected |
|---|---|
| Tap the table hard | `road_bump` event |
| Tap, then roll the box 90° and leave it 3 s | alarm + app countdown |
| Tap "I'm OK" | alarm stops |
| Repeat, force-close the app | after ~25 s one SOS on the admin map |
| Turn the box 90° slowly, no tap, no ride | nothing |
| Unplug the hotspot | unit keeps detecting; reconnects when it is back |
