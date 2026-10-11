#include "detector.h"
#include "config.h"

void Detector::Peaks::add(const ImuSample& s) {
  g = fmaxf(g, s.linearG);
  vertical = fmaxf(vertical, s.verticalG);
  horizontal = fmaxf(horizontal, s.horizontalG);
  gyro = fmaxf(gyro, s.gyroDps);
  tilt = fmaxf(tilt, s.tiltDeg);
}

void Detector::fill(DetectorEvent& e, EventType type, const Peaks& p, unsigned long nowMs) {
  e.type = type;
  snprintf(e.uid, sizeof(e.uid), "%s-%lu-%lu", deviceUid.c_str(), (unsigned long)bootCount, nowMs);
  e.peakG = p.g;
  e.peakVerticalG = p.vertical;
  e.peakHorizontalG = p.horizontal;
  e.peakGyroDps = p.gyro;
  e.tiltDeg = p.tilt;
  e.atMillis = nowMs;
}

// Fills the event and starts a new normal-riding window, so a normal
// summary never includes the jolt that was reported as its own event.
bool Detector::emit(DetectorEvent& e, EventType type, const Peaks& p, unsigned long nowMs) {
  fill(e, type, p, nowMs);
  window.reset();
  windowStart = nowMs;
  return true;
}

void Detector::show(MotionState st, unsigned long nowMs) {
  shownState = st;
  shownUntil = nowMs + STATE_DISPLAY_MS;
}

MotionState Detector::state() const {
  switch (phase) {
    case IMPACT_SEEN: return STATE_IMPACT;
    case FALLEN:      return STATE_FALLEN;
    case CONFIRMED:   return STATE_CRASH;
    default:          break;
  }
  return (millis() < shownUntil) ? shownState : STATE_NORMAL;
}

bool Detector::update(const ImuSample& s, unsigned long now, DetectorEvent& event) {
  const bool hit = s.linearG >= IMPACT_G || s.gyroDps >= IMPACT_GYRO_DPS;
  const bool fallen = s.tiltDeg >= FALL_TILT_DEG;
  const bool upright = s.tiltDeg <= UPRIGHT_TILT_DEG;

  if (windowStart == 0) windowStart = now;
  window.add(s);

  // ---------------- Crash state machine ----------------
  switch (phase) {
    case IDLE:
      if (hit) {
        phase = IMPACT_SEEN;
        impactAt = now;
        crashPeaks.reset();
        crashPeaks.add(s);
      } else if (fallen && rideActive) {
        // Fell over without a detectable hit (e.g. a low-speed slide).
        phase = FALLEN;
        fallHadImpact = false;
        stillSince = now;
        crashPeaks.reset();
        crashPeaks.add(s);
      }
      break;

    case IMPACT_SEEN:
      crashPeaks.add(s);
      if (fallen) {
        phase = FALLEN;
        fallHadImpact = true;
        stillSince = now;
      } else if (now - impactAt > IMPACT_TO_FALL_MS) {
        phase = IDLE;   // a hit without a fall: a pothole or kerb, not a crash
      }
      break;

    case FALLEN: {
      crashPeaks.add(s);
      if (upright) {
        phase = IDLE;   // stood back up before confirmation: no crash
        break;
      }
      if (!fallen || s.gyroDps > STILL_GYRO_DPS) stillSince = now;  // still sliding/rolling
      const unsigned long needed = fallHadImpact ? FALL_CONFIRM_MS : TIPOVER_CONFIRM_MS;
      if (now - stillSince >= needed) {
        phase = CONFIRMED;
        uprightSince = 0;
        return emit(event, EVENT_CRASH, crashPeaks, now);
      }
      break;
    }

    case CONFIRMED:
      // Re-arm only after the bike has been upright for a while.
      if (upright) {
        if (uprightSince == 0) uprightSince = now;
        if (now - uprightSince >= RESET_UPRIGHT_MS) {
          phase = IDLE;
          Peaks none;
          none.tilt = s.tiltDeg;
          return emit(event, EVENT_CRASH_CLEARED, none, now);
        }
      } else {
        uprightSince = 0;
      }
      break;
  }

  // No other classification during a crash check.
  // Known issue (field data, Oct 2026): hard bumps exceed IMPACT_G, so each
  // one pauses bump/brake detection for up to IMPACT_TO_FALL_MS. To be fixed
  // with the next threshold update (only FALLEN / CONFIRMED should block).
  if (phase != IDLE) return false;

  // ---------------- Bump: vertical jolt, bike upright ----------------
  if (s.verticalG >= BUMP_VERTICAL_G && upright && now - lastBumpAt >= BUMP_COOLDOWN_MS) {
    lastBumpAt = now;
    show(STATE_BUMP, now);
    Peaks p;
    p.add(s);
    return emit(event, EVENT_BUMP, p, now);
  }

  // ---------------- Hard braking: sustained horizontal force, little rotation ----------------
  // Direction is not checked yet, so strong acceleration or cornering could
  // also match. Field data shows braking always lies along one sensor axis
  // (for a fixed mount): a signed forward-deceleration rule is planned.
  if (s.horizontalG >= BRAKE_HORIZONTAL_G && s.gyroDps <= BRAKE_MAX_GYRO_DPS && upright) {
    if (brakeSince == 0) { brakeSince = now; brakePeaks.reset(); }
    brakePeaks.add(s);
    if (now - brakeSince >= BRAKE_MIN_MS && now - lastBrakeAt >= BRAKE_COOLDOWN_MS) {
      lastBrakeAt = now;
      brakeSince = 0;
      show(STATE_HARD_BRAKING, now);
      return emit(event, EVENT_HARD_BRAKING, brakePeaks, now);
    }
  } else {
    brakeSince = 0;
  }

  // ---------------- Normal riding summary ----------------
  if (now - windowStart >= NORMAL_SUMMARY_MS) {
    return emit(event, EVENT_NORMAL, window, now);
  }

  return false;
}
