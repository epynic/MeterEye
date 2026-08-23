#!/usr/bin/env python3
"""
EB meter OCR worker.
Processes new frames since the high-water mark, classifies the screen, extracts
energy readings, applies sanity + dedup, and appends to the `readings` table.

Generic by design: add a metric by adding a row to REGISTRY (+ a label template).

Usage:
  python worker.py --dry-run [N]   # preview, no DB writes (N = max frames)
  python worker.py                 # process all new frames, write to DB
  python worker.py --reset         # clear high-water mark (reprocess from start)
"""
import sys, os, glob, re, json, datetime, cv2
import lab, labels

# Metric registry now lives in labels.py (METRIC_META + UNIT_METRIC); use classify_metric().
# kind drives sanity: cumulative (monotonic), md (max-demand), instant (voltage/current).
MAX_RATE_KW = 12.0                  # plausible peak load -> bounds kWh jump per elapsed time
JUMP_BASE   = 1.0                   # small base allowance (kWh) for OCR jitter / rounding
BACK_TOL    = 0.1                   # cumulative may not decrease (tiny tolerance for rounding)

# plausible absolute value window per metric (catches misreads / wrong-screen)
RANGE = {
    "md_import": (1.2, 50.0), "md_export": (1.2, 50.0),   # kW (>1.2 separates from PF; date/time excluded)
    "pf":        (0.0, 1.2),                               # power factor (split from MD by value)
    "voltage":   (50.0, 320.0), "current": (-150.0, 150.0),  # current goes negative on export
}
RANGE_KIND = {"cumulative": (0.01, 1_000_000.0)}
SIGNED = {"current"}                                       # metrics that can be negative (apply '-' sign)

# robustness guards for the cumulative billing screens (added 2026-06-15 after an export misread
# 553.33->653.33 poisoned the register and blocked every correct reading for hours).
ABS_STEP_CAP = 50.0    # kWh: a single step bigger than this is "suspect" and must be confirmed
SIBLING_TOL  = 3.0     # kWh: a reading this close to the OTHER register = a mislabeled IP/EP screen
CONFIRM_TOL  = 3.0     # kWh: a held suspect is confirmed when the next reading lands within this of it
SIBLINGS = {"ip_cu_fd": "ep_cu_fd", "ep_cu_fd": "ip_cu_fd"}  # screens that get confused on dim frames

FNAME_RE = re.compile(r"(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})_")

def captured_at(fn):
    m = FNAME_RE.match(os.path.basename(fn))
    if not m:
        return None
    y, mo, d, h, mi, s = map(int, m.groups())
    return datetime.datetime(y, mo, d, h, mi, s)   # UTC (server filename time)

def db():
    import configparser, subprocess, json
    # read creds via sudo-free path: the worker runs as a user that can read config
    # (in production: run as www-data). Here we shell out through a tiny PHP helper.
    out = subprocess.run(["php", "-r",
        'require "/var/www/prasanha.com/config/app.php";'
        'echo json_encode([DB_USER,DB_PASS,DB_NAME]);'],
        capture_output=True, text=True)
    user, pw, name = json.loads(out.stdout)
    import pymysql
    return pymysql.connect(host="localhost", user=user, password=pw, database=name, autocommit=False)

def all8(raw):
    return bool(raw) and set(raw) == {"8"}

def main():
    dry = "--dry-run" in sys.argv
    reset = "--reset" in sys.argv
    nmax = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)

    conn = db(); cur = conn.cursor()
    if reset and not dry:
        cur.execute("DELETE FROM worker_state WHERE k='last_processed'"); conn.commit()

    cur.execute("SELECT v FROM worker_state WHERE k='last_processed'")
    row = cur.fetchone()
    hwm = row[0] if row else ""

    # seed last (value, timestamp) per (metric,phase) from DB (for monotonic + dedup)
    last = {}
    cur.execute("SELECT metric, phase, value, captured_at FROM readings r WHERE id = "
                "(SELECT MAX(id) FROM readings WHERE metric=r.metric AND (phase<=>r.phase))")
    for metric, phase, val, ts in cur.fetchall():
        last[(metric, phase)] = (float(val), ts)

    # suspect readings awaiting confirmation by the next frame (persisted across runs)
    pending = {}
    cur.execute("SELECT v FROM worker_state WHERE k='pending'")
    prow = cur.fetchone()
    if prow and prow[0]:
        for k, pv in json.loads(prow[0]).items():
            m, _, ph = k.partition("\t")
            pending[(m, ph or None)] = (float(pv[0]), datetime.datetime.fromisoformat(pv[1]))

    files = sorted(glob.glob(lab.IMG + "/*.jpg"))
    files = [f for f in files if os.path.basename(f) > hwm]
    if nmax:
        files = files[:nmax]
    print(f"high-water='{hwm}'  new frames={len(files)}  dry={dry}")

    labels.build_templates(); labels.build_unit_templates(); labels.build_phase_templates()
    stats = {"metric": 0, "stored": 0, "dup": 0, "rej_sanity": 0, "rej_label": 0, "rej_back": 0, "rej_digit": 0, "pending": 0, "skip_other": 0, "all8": 0, "bad": 0}
    newest = hwm
    for f in files:
        base = os.path.basename(f)
        bgr = cv2.imread(f)
        if bgr is None:
            stats["bad"] += 1; newest = max(newest, base); continue
        canon, binary, _ = lab.canon_binary(bgr, lab.DEFAULTS)
        if canon is None:
            newest = max(newest, base); continue
        res = labels.classify_metric(canon)
        if res is None:
            stats["skip_other"] += 1; newest = max(newest, base); continue
        metric, unit, kind, phase, score = res
        digits, boxes = lab.decode(binary, lab.DEFAULTS)
        val, raw = lab.to_value(digits, lab.DEFAULTS)
        stats["metric"] += 1
        if val is None or all8(raw):
            stats["all8" if all8(raw) else "bad"] += 1; newest = max(newest, base); continue
        v = float(val)
        # PF capture: IP-HO/EP-HO frames with a tiny value are power factor, not max-demand
        if kind == "md" and v <= 1.2:
            metric, unit, kind = "pf", "", "instant"
        # signed metrics (current) go negative when exporting — read the '-' sign
        if metric in SIGNED and lab.detect_minus(binary, boxes, lab.DEFAULTS):
            v = -v
        lo, hi = RANGE.get(metric, RANGE_KIND.get(kind, (0.01, 1e6)))
        if not (lo <= v <= hi):
            stats["rej_sanity"] += 1; newest = max(newest, base); continue
        ts = captured_at(f)
        # Phase-1 staging: log every in-range cumulative/demand decode RAW (pre-guard) to readings_raw.
        # This is the permanent "what the OCR actually claimed" record -- the accuracy audit and the
        # consensus self-heal read from it, and it's the foundation for consensus-promotion. Append-only;
        # it never blocks the clean `readings` write below. Instantaneous V/A/PF are excluded (high-volume,
        # no cumulative/leading-digit poison risk). Joining readings_raw.src against readings.src tells you
        # which raw claims the guard accepted -> OCR accuracy + a full audit trail, for free. (2026-06-17)
        if not dry and kind in ("cumulative", "md"):
            cur.execute("INSERT INTO readings_raw (captured_at,metric,value,unit,phase,src) "
                        "VALUES (%s,%s,%s,%s,%s,%s)",
                        (ts.strftime("%Y-%m-%d %H:%M:%S"), metric, v, unit, phase, base))
            stats["raw"] = stats.get("raw", 0) + 1
        key = (metric, phase)
        # mislabeled-screen guard: on dim frames IP-Cu-Fd <-> EP-Cu-Fd get confused. The tell is the
        # decoded value matches the OTHER register (and is far from this one's own last value).
        if metric in SIBLINGS:
            sib = last.get((SIBLINGS[metric], phase)); own = last.get(key)
            if sib and abs(v - sib[0]) <= SIBLING_TOL and (not own or abs(v - own[0]) > SIBLING_TOL):
                stats["rej_label"] += 1; newest = max(newest, base); continue
        if key in last:
            lv, lts = last[key]
            if v == lv:
                pending.pop(key, None)   # reading agrees with stored truth -> drop any stale suspect
                stats["dup"] += 1; newest = max(newest, base); continue
            if kind == "cumulative":   # monotonic; only FORWARD jumps may be confirmed, never decreases
                hrs = max(0.0, (ts - lts).total_seconds() / 3600.0)
                allowed = min(MAX_RATE_KW * hrs, ABS_STEP_CAP) + JUMP_BASE
                if v < lv - BACK_TOL:
                    # a cumulative register CANNOT decrease. A value below the last stored one is a
                    # misread (typically a leading-digit error, e.g. 901->801, that keeps the decimals).
                    # Never store it and never let it be "confirmed" -- two matching down-misreads must
                    # not seed a backward register (the bug that put 801/658 into ip_cu_fd).
                    stats["rej_back"] += 1; newest = max(newest, base); continue
                # leading-digit INFLATION while the meter is ALSO advancing (incident #4, 2026-06-17):
                # dawn glare flickered a phantom segment on the HUNDREDS digit (5->6 = +100) on ~1/3 of
                # frames while the low digits kept moving normally (585.08 -> '685.53'). The frozen-low-
                # digit check below misses this (the low digits genuinely changed), and the confirm path
                # would commit it because the SAME glare repeats on the next frame -> it confirms itself.
                # Tell: the jump is bigger than PHYSICALLY possible for the elapsed time, yet removing a
                # round 10^k turns it back into a plausible forward step from lv. Reject outright -- a
                # misread must never be storable, repeated or not. phys_max is the UNCAPPED rate ceiling
                # (not ABS_STEP_CAP) so a genuine post-outage catch-up -- within physical limits for its
                # long elapsed time -- never trips this and still flows through the confirm path below.
                phys_max = MAX_RATE_KW * hrs + JUMP_BASE
                if v - lv > phys_max and any(lv - BACK_TOL <= v - k <= lv + phys_max
                                             for k in (10.0, 100.0, 1000.0, 10000.0)):
                    stats["rej_digit"] += 1; newest = max(newest, base); continue
                # leading-digit OCR flip: night glare lights a phantom segment that flips a HIGH digit
                # (5->6, 9->8, ...) while every LOWER digit + the decimals stay frozen. A real cumulative
                # register can never advance with its low digits unchanged (it ticks the 0.01 place
                # continuously), so identical low-3 digits (units + 2 decimals) + a different value = a
                # misread, not movement. Reject outright -- unlike the confirm path below this can't be
                # "confirmed" by a repeat, which is exactly what let 567.51->667.51 commit (2026-06-16,
                # incident #3). Float-safe: compare the low 3 digits as integer hundredths.
                if round(v * 100) % 1000 == round(lv * 100) % 1000 and abs(v - lv) >= 5.0:
                    stats["rej_digit"] += 1; newest = max(newest, base); continue
                if v > lv + allowed:
                    # forward jump too big for one step: a one-off OCR misread is indistinguishable from
                    # a real jump for ONE frame. Hold it; commit only when the NEXT reading confirms the
                    # same new level. A transient misread is never confirmed, so it can't be stored.
                    p = pending.get(key)
                    phrs = max(0.0, (ts - p[1]).total_seconds() / 3600.0) if p else 0.0
                    if p and abs(v - p[0]) <= CONFIRM_TOL + MAX_RATE_KW * phrs:
                        pending.pop(key, None)        # confirmed real jump -> fall through and store
                    else:
                        pending[key] = (v, ts)        # suspect -> wait for next reading, do not store
                        stats["pending"] += 1; newest = max(newest, base); continue
                else:
                    pending.pop(key, None)            # normal forward step -> drop any stale suspect
        if not dry:
            cur.execute("INSERT INTO readings (captured_at,metric,value,unit,phase,src) "
                        "VALUES (%s,%s,%s,%s,%s,%s)",
                        (ts.strftime("%Y-%m-%d %H:%M:%S"), metric, v, unit, phase, base))
        last[key] = (v, ts)
        stats["stored"] += 1
        newest = max(newest, base)
        if dry and stats["stored"] <= 30:
            print(f"  STORE {ts}  {metric:<11} {v:>8.2f} {unit:<5} {phase or '':<2} ({kind})  [{base[11:17]}]")

    if not dry and files:
        cur.execute("INSERT INTO worker_state (k,v) VALUES ('last_processed',%s) "
                    "ON DUPLICATE KEY UPDATE v=VALUES(v)", (newest,))
        pjson = json.dumps({f"{m}\t{ph or ''}": [pv[0], pv[1].isoformat()]
                            for (m, ph), pv in pending.items()})
        cur.execute("INSERT INTO worker_state (k,v) VALUES ('pending',%s) "
                    "ON DUPLICATE KEY UPDATE v=VALUES(v)", (pjson,))
        conn.commit()
    print("stats:", stats)
    if not dry:
        cur.execute("SELECT metric, COUNT(*), MIN(value), MAX(value) FROM readings GROUP BY metric")
        print("readings table now:")
        for m, c, mn, mx in cur.fetchall():
            print(f"  {m:<10} {c:>5} rows  {float(mn):.2f}..{float(mx):.2f}")
    conn.close()

if __name__ == "__main__":
    main()
