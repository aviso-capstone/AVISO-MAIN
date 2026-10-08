"""
AVISO — check retrieved threshold-gathering CSVs (v7).

Retrieval copies each file over Serial, and one glitch there corrupts
the laptop copy without any warning. Run this on every retrieved file
before using it for thresholds.

Usage:
    python check_thr_csv.py <file or folder> [...]

Checks a per-session log (thr_<label>_<attempt>.csv):
  - exact 20-column header, every row 20 numeric fields
  - no garbage characters, no stray "=== BEGIN/END" lines
  - session label / attempt match the file name on every row
  - reading runs 1..N with no gaps or repeats, t_ms strictly increasing
  - N close to 3000 (a full 30 s window at 100 Hz)
and prints the numbers that matter for thresholds (percentiles + max).

Checks the summary (thr_sessions.csv): field counts per row, and, when
the matching log is in the same folder, that the row count and peak
linear g agree with the log.

Exit code 0 = everything passed, 1 = at least one problem.
Python 3.8+, standard library only.
"""

import csv
import math
import re
import sys
from pathlib import Path

LOG_HEADER = ("session_label,attempt,reading,t_ms,linX,linY,linZ,gyroX,gyroY,gyroZ,"
              "gravX,gravY,gravZ,linearG,verticalG,horizontalG,gyroDps,tiltDeg,clipped,late").split(",")
FULL_SESSION_ROWS = 3000
MIN_OK_ROWS = 2990            # a few rows short of 3000 is still a complete window
LOG_NAME = re.compile(r"^thr_([a-z]+)_(\d+)\.csv$")
SUMMARY_NAME = "thr_sessions.csv"
SUMMARY_MIN_FIELDS = 26       # v6 rows; v7 adds cal_*_end and rest_* at the end
GARBAGE = re.compile(r"[^\x20-\x7E]")   # anything that is not printable ASCII


def percentile(sorted_vals, p):
    if not sorted_vals:
        return float("nan")
    k = (len(sorted_vals) - 1) * p / 100.0
    lo, hi = math.floor(k), math.ceil(k)
    return sorted_vals[lo] + (sorted_vals[hi] - sorted_vals[lo]) * (k - lo)


def read_lines(path):
    """Raw lines with line endings stripped; garbage bytes become U+FFFD."""
    text = path.read_bytes().decode("utf-8", errors="replace")
    return [line.rstrip("\r") for line in text.split("\n")]


class Report:
    def __init__(self, path):
        self.path = path
        self.errors = []
        self.warnings = []
        self.info = []

    def error(self, msg):
        self.errors.append(msg)

    def warn(self, msg):
        self.warnings.append(msg)

    def show(self):
        status = "FAIL" if self.errors else ("OK (with warnings)" if self.warnings else "OK")
        print(f"\n=== {self.path.name}: {status}")
        for m in self.errors[:20]:
            print(f"  [ERROR] {m}")
        if len(self.errors) > 20:
            print(f"  [ERROR] ... and {len(self.errors) - 20} more")
        for m in self.warnings:
            print(f"  [WARN]  {m}")
        for m in self.info:
            print(f"  {m}")
        return not self.errors


def check_log(path):
    rep = Report(path)
    m = LOG_NAME.match(path.name)
    label, attempt = (m.group(1), m.group(2)) if m else (None, None)
    if not m:
        rep.warn("file name is not thr_<label>_<attempt>.csv; label/attempt not cross-checked")

    lines = read_lines(path)
    while lines and lines[-1] == "":
        lines.pop()
    if not lines:
        rep.error("file is empty")
        return rep, None

    if lines[0].split(",") != LOG_HEADER:
        rep.error(f"header is wrong or damaged: {lines[0][:80]!a}")

    readings, t_prev, n_rows = [], None, 0
    late = clipped = 0
    cols = {name: [] for name in ("linearG", "verticalG", "horizontalG", "gyroDps", "tiltDeg")}

    for lineno, line in enumerate(lines[1:], start=2):
        if line.startswith("==="):
            rep.error(f"line {lineno}: retrieval marker copied into the file: {line[:60]!a}")
            continue
        if GARBAGE.search(line):
            rep.error(f"line {lineno}: garbage characters (Serial glitch): {line[:60]!a}")
            continue
        fields = next(csv.reader([line]))
        if len(fields) != len(LOG_HEADER):
            rep.error(f"line {lineno}: {len(fields)} fields instead of {len(LOG_HEADER)}: {line[:60]!a}")
            continue
        row = dict(zip(LOG_HEADER, fields))
        try:
            reading = int(row["reading"])
            t_ms = int(row["t_ms"])
            vals = {k: float(row[k]) for k in cols}
            late += int(row["late"])
            clipped += int(row["clipped"])
        except ValueError:
            rep.error(f"line {lineno}: a value is not a number: {line[:60]!a}")
            continue
        if label and (row["session_label"] != label or row["attempt"] != attempt):
            rep.error(f"line {lineno}: row says {row['session_label']} #{row['attempt']}, "
                      f"file name says {label} #{attempt}")
        if t_prev is not None and t_ms <= t_prev:
            rep.error(f"line {lineno}: t_ms {t_ms} is not after the previous row ({t_prev})")
        t_prev = t_ms
        readings.append(reading)
        for k, v in vals.items():
            cols[k].append(v)
        n_rows += 1

    # Continuity of the reading counter: catches dropped or duplicated lines
    expected = 1
    for r in readings:
        if r != expected:
            kind = "missing" if r > expected else "repeated/out of order"
            rep.error(f"reading jumps from {expected - 1} to {r} ({kind} rows)")
            expected = r
        expected += 1

    if n_rows < MIN_OK_ROWS:
        rep.error(f"only {n_rows} good rows (a full window is {FULL_SESSION_ROWS})")
    elif n_rows != FULL_SESSION_ROWS:
        rep.warn(f"{n_rows} rows (expected {FULL_SESSION_ROWS})")
    if late:
        rep.warn(f"{late} late samples (v7 should have 0)")
    if clipped:
        rep.warn(f"{clipped} clipped samples: the true peak was above the 4 g sensor limit")

    if n_rows:
        duration_s = (t_prev or 0) / 1000.0
        rate = n_rows / duration_s if duration_s > 0 else float("nan")
        rep.info.append(f"rows {n_rows}, duration {duration_s:.2f} s, {rate:.1f} Hz, "
                        f"late {late}, clipped {clipped}")
        rep.info.append(f"{'':12}{'p50':>8}{'p95':>8}{'p99':>8}{'max':>8}")
        for k, v in cols.items():
            s = sorted(v)
            rep.info.append(f"{k:12}" + "".join(f"{percentile(s, p):8.3f}" for p in (50, 95, 99))
                            + f"{s[-1]:8.3f}")
    stats = {"rows": n_rows, "peak_linear_g": max(cols["linearG"]) if cols["linearG"] else None}
    return rep, stats


def check_summary(path, log_stats):
    rep = Report(path)
    lines = read_lines(path)
    while lines and lines[-1] == "":
        lines.pop()
    if not lines:
        rep.error("file is empty")
        return rep
    header = lines[0].split(",")
    if header[:2] != ["version", "session_label"]:
        rep.error(f"header is wrong or damaged: {lines[0][:80]!a}")
        return rep

    for lineno, line in enumerate(lines[1:], start=2):
        if GARBAGE.search(line) or line.startswith("==="):
            rep.error(f"line {lineno}: garbage or retrieval marker: {line[:60]!a}")
            continue
        fields = next(csv.reader([line]))
        if len(fields) < SUMMARY_MIN_FIELDS:
            rep.error(f"line {lineno}: only {len(fields)} fields: {line[:60]!a}")
            continue
        if len(fields) > len(header):
            rep.warn(f"line {lineno}: {len(fields)} fields but the header has {len(header)} "
                     "(rows from a newer sketch under an older header; extra columns: "
                     "cal_*_end, rest_bias_g, rest_noise_g)")
        version, label, attempt, status = fields[0], fields[1], fields[2], fields[3]
        line_info = f"{label} #{attempt}: {status}"
        if status not in ("INTERRUPTED",):
            try:
                rows, peak = int(fields[4]), float(fields[9])
                line_info += f", rows {rows}, late {fields[5]}, peak linear {peak:.3f} g, {fields[19]} Hz"
                if len(fields) >= 31:
                    line_info += f", rest offset {float(fields[29]):.4f} g +/- {float(fields[30]):.4f}"
                key = f"thr_{label}_{attempt}.csv"
                if key in log_stats:
                    ls = log_stats[key]
                    errors_before = len(rep.errors)
                    if ls["rows"] != rows:
                        rep.error(f"{label} #{attempt}: summary says {rows} rows, log has {ls['rows']}")
                    if ls["peak_linear_g"] is not None and abs(ls["peak_linear_g"] - peak) > 0.002:
                        rep.error(f"{label} #{attempt}: summary peak {peak:.3f} g, "
                                  f"log peak {ls['peak_linear_g']:.3f} g")
                    if len(rep.errors) == errors_before:
                        line_info += "  [matches log]"
            except (ValueError, IndexError):
                rep.error(f"line {lineno}: values are not numbers: {line[:60]!a}")
                continue
        rep.info.append(line_info)
    return rep


def main(argv):
    if not argv:
        print(__doc__)
        return 1
    files = []
    for arg in argv:
        p = Path(arg)
        if p.is_dir():
            files += sorted(p.glob("thr_*.csv"))
        elif p.is_file():
            files.append(p)
        else:
            print(f"[ERROR] not found: {arg}")
            return 1
    if not files:
        print("[ERROR] no thr_*.csv files found")
        return 1

    all_ok = True
    log_stats = {}
    for f in files:
        if f.name == SUMMARY_NAME:
            continue
        rep, stats = check_log(f)
        all_ok &= rep.show()
        if stats:
            log_stats[f.name] = stats
    for f in files:
        if f.name == SUMMARY_NAME:
            all_ok &= check_summary(f, log_stats).show()

    print("\nRESULT:", "all files passed" if all_ok else "PROBLEMS FOUND - re-retrieve the failed files")
    return 0 if all_ok else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
