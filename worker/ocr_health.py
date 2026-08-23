#!/usr/bin/env python3
"""
OCR health checker + register self-heal.  Runs on a cron every few hours.

Two jobs:
  1) ACCURACY AUDIT — decode the last H hours of RAW frames for each energy register and
     measure the true OCR misread rate (a misread = a decode that deviates from the local
     consensus = rolling median of nearby same-register decodes). The stored `readings`
     table can't show this: the worker already rejected the bad ones, so stored data looks
     ~perfect. Only re-decoding the raw frames reveals how the OCR is actually doing.
  2) SELF-HEAL — recompute the TRUE current register from that raw consensus (folding any
     leading-digit +100/+1000 flips back onto the consensus band) and compare it to what the
     worker actually stored.  If a poisoned HIGH value slipped past the worker guards and is
     blocking correct readings (the register is stuck ABOVE physical truth), correct the
     offending stored rows so the next correct reading flows again. This is the windowed,
     majority-vote version of "even if OCR goes wrong, the next good reading wins": a single
     bad frame can never move the register (worker stays strict); only a consistent consensus
     of many recent frames can, and only DOWNWARD (undo an over-count) — never upward, so the
     bill can never be inflated by this. A stuck-LOW register (would need heal-up) can't occur
     while the worker rejects all backward values, so that case only alerts, never auto-edits.

Writes a JSON report to worker/ocr_health.json (read by health.php + monitor.php).

Run:
  sudo -u www-data ./venv/bin/python ocr_health.py --dry-run     # audit + preview heal, no DB writes
  sudo -u www-data ./venv/bin/python ocr_health.py               # audit + heal + write report
  sudo -u www-data ./venv/bin/python ocr_health.py --selftest    # pure-function unit tests, no DB/frames
"""
import sys, os, glob, re, json, statistics, datetime

HERE = os.path.dirname(os.path.abspath(__file__))
REPORT = os.path.join(HERE, "ocr_health.json")
HEAL_LOG = os.path.join(HERE, "ocr_heal.log")

BILLING = {"ip_cu_fd", "ep_cu_fd"}                 # registers the bill depends on -> eligible for auto-heal
ENERGY  = BILLING | {"ip_cu", "ep_cu", "reactive_1", "reactive_2", "reactive_3", "reactive_4"}
MIS_TOL = 20.0          # kWh: a decode this far from local median = a misread (a +100 flip is way past this)
HEAL_TOL = 3.0          # kWh: corrected value must land this close to the consensus trend to be applied
MIN_CONSENSUS = 0.60    # require >=60% of window frames to agree with consensus before trusting it to heal
FNAME_RE = re.compile(r"(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})_")


# ----------------------------- pure functions (unit-tested) -----------------------------
def fold(v, ref):
    """Fold a leading-digit flip onto ref's band: remove the nearest whole multiple of 100.
    585.53 vs ref 585.6 -> 585.53 (unchanged).  685.53 vs ref 585.6 -> 585.53 (flip removed)."""
    return round(v - round((v - ref) / 100.0) * 100.0, 3)


def consensus_series(values):
    """values: chronological list of raw decoded floats. Returns folded series (flips removed,
    folded onto the running median) — the best estimate of the TRUE reading at each frame."""
    if not values:
        return []
    med = statistics.median(values)               # majority is correct -> median sits in the true band
    return [fold(v, med) for v in values]


def misread_rate(values, tol=MIS_TOL):
    """How many decodes are leading-digit flips, measured against the GLOBAL-median band.
    A local rolling median breaks when misreads cluster (the window tips past 50% and the
    median jumps to the wrong band); the global median is robust while the whole window stays
    majority-correct, which holds at the observed ~32% misread rate."""
    n = len(values)
    if n == 0:
        return 0, 0
    med = statistics.median(values)
    mis = sum(1 for v in values if abs(v - fold(v, med)) > tol)
    return mis, n


def true_register(folded):
    """Latest true cumulative reading = the max of the (monotonic) folded series."""
    return max(folded) if folded else None


def plan_heal(stored, raw_ts, raw_folded, tol=HEAL_TOL):
    """Decide which STORED rows are poisoned-high and how to fix them.
    stored:     list of (id, ts(datetime), value) actually in the DB, chronological.
    raw_ts/raw_folded: the consensus TRUE trend from raw frames (parallel lists, chronological).
    Returns list of (id, old, new) for rows that are a clean +k*100 above the trend (k>=1).
    Only ever lowers a value (undo an over-count); never raises one."""
    if not raw_folded:
        return []
    fixes = []
    for rid, ts, v in stored:
        tau = _interp(raw_ts, raw_folded, ts)     # true value at this row's time
        if tau is None:
            continue
        k = round((v - tau) / 100.0)
        if k >= 1:                                  # stored value is ~k*100 ABOVE truth -> poisoned high
            new = round(v - k * 100.0, 3)
            if abs(new - tau) <= tol and new < v:   # correction lands on the trend, and only lowers
                fixes.append((rid, v, new))
    return fixes


def _interp(xs_ts, ys, ts):
    """Linear-interpolate the trend ys (at times xs_ts) to datetime ts. Clamps to ends."""
    if not xs_ts:
        return None
    xs = [t.timestamp() for t in xs_ts]
    x = ts.timestamp()
    if x <= xs[0]:
        return ys[0]
    if x >= xs[-1]:
        return ys[-1]
    for i in range(1, len(xs)):
        if x <= xs[i]:
            x0, x1, y0, y1 = xs[i-1], xs[i], ys[i-1], ys[i]
            f = (x - x0) / (x1 - x0) if x1 > x0 else 0.0
            return y0 + f * (y1 - y0)
    return ys[-1]


# ----------------------------- DB + frame I/O -----------------------------
def db():
    import subprocess, pymysql
    out = subprocess.run(["php", "-r",
        'require "' + os.environ.get("EB_CONFIG", os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "config.php")) + '";'
        'echo json_encode([DB_USER,DB_PASS,DB_NAME]);'],
        capture_output=True, text=True)
    user, pw, name = json.loads(out.stdout)
    return pymysql.connect(host="localhost", user=user, password=pw, database=name, autocommit=False)


def captured_at(fn):
    m = FNAME_RE.match(os.path.basename(fn))
    if not m:
        return None
    y, mo, d, h, mi, s = map(int, m.groups())
    return datetime.datetime(y, mo, d, h, mi, s)


def decode_recent_frames(hours):
    """Decode the last `hours` of raw frames; return {metric: [(ts, value), ...]} chronological."""
    import cv2, lab, labels
    labels.build_templates(); labels.build_unit_templates(); labels.build_phase_templates()
    files = sorted(glob.glob(lab.IMG + "/*.jpg"))
    if not files:
        return {}
    cutoff = captured_at(files[-1]) - datetime.timedelta(hours=hours)
    out = {}
    for f in files:
        ts = captured_at(f)
        if ts is None or ts < cutoff:
            continue
        bgr = cv2.imread(f)
        if bgr is None:
            continue
        canon, binary, _ = lab.canon_binary(bgr, lab.DEFAULTS)
        if canon is None:
            continue
        res = labels.classify_metric(canon)
        if res is None or res[0] not in ENERGY:
            continue
        digits, _ = lab.decode(binary, lab.DEFAULTS)
        val, _ = lab.to_value(digits, lab.DEFAULTS)
        if val is not None:
            out.setdefault(res[0], []).append((ts, float(val)))
    return out


def main():
    dry = "--dry-run" in sys.argv
    hours = next((float(a.split("=")[1]) for a in sys.argv if a.startswith("--hours=")), 4.0)

    raw = decode_recent_frames(hours)
    conn = db(); cur = conn.cursor()
    report = {"checked_utc": None, "window_hours": hours, "metrics": {}}
    # stamp time from the newest frame (Date.now() not used; keep deterministic-ish)
    newest = max((ts for s in raw.values() for ts, _ in s), default=None)
    report["checked_utc"] = (newest or datetime.datetime(1970,1,1)).strftime("%Y-%m-%d %H:%M:%S")

    total_fixes = []
    for metric in sorted(ENERGY):
        series = sorted(raw.get(metric, []), key=lambda x: x[0])
        vals = [v for _, v in series]
        mis, n = misread_rate(vals)
        folded = consensus_series(vals)
        cons = true_register(folded)
        acc = round(100.0 * (n - mis) / n, 1) if n else None

        # what the worker actually stored, over the same window
        stored = []
        if series:
            cutoff = series[0][0].strftime("%Y-%m-%d %H:%M:%S")
            cur.execute("SELECT id, captured_at, value FROM readings WHERE metric=%s AND captured_at>=%s "
                        "ORDER BY captured_at", (metric, cutoff))
            stored = [(rid, ts, float(v)) for rid, ts, v in cur.fetchall()]
        stored_reg = stored[-1][2] if stored else None

        status = "ok"
        fixes = []
        if n and cons is not None and stored_reg is not None:
            consensus_frac = (n - mis) / n
            raw_ts = [t for t, _ in series]
            if stored_reg > cons + HEAL_TOL:                  # register stuck ABOVE physical truth
                if metric in BILLING and consensus_frac >= MIN_CONSENSUS:
                    fixes = plan_heal(stored, raw_ts, folded)
                    status = "healed" if fixes else "poison_high"
                else:
                    status = "poison_high"                    # detected but not auto-healed
            elif cons > stored_reg + HEAL_TOL + MIS_TOL:
                status = "behind"                             # worker lagging real movement (not poison)

        report["metrics"][metric] = dict(
            frames=n, misreads=mis, accuracy_pct=acc,
            consensus_register=cons, stored_register=stored_reg,
            status=status, fixes=len(fixes))
        total_fixes += [(metric, *fx) for fx in fixes]

    # apply heals (billing only, already vetted by plan_heal)
    if total_fixes and not dry:
        with open(HEAL_LOG, "a") as lg:
            for metric, rid, old, new in total_fixes:
                cur.execute("UPDATE readings SET value=%s WHERE id=%s AND metric=%s", (new, rid, metric))
                lg.write(f"{report['checked_utc']}  heal {metric} id={rid}  {old} -> {new}\n")
        conn.commit()

    # retention: readings_raw is append-only and higher-volume than `readings`; keep ~30 days.
    if not dry:
        cur.execute("DELETE FROM readings_raw WHERE created_at < (NOW() - INTERVAL 30 DAY)")
        conn.commit()

    if not dry:
        with open(REPORT, "w") as fp:
            json.dump(report, fp, indent=2)
    conn.close()

    # console summary
    print(f"OCR health  window={hours}h  (newest frame {report['checked_utc']} UTC)  dry={dry}")
    for m, r in report["metrics"].items():
        if r["frames"]:
            tag = {"ok":"OK","healed":"HEALED","poison_high":"POISON!","behind":"behind"}.get(r["status"], r["status"])
            print(f"  {m:12} {r['accuracy_pct']:>5}% acc  ({r['misreads']}/{r['frames']} misread)  "
                  f"stored={r['stored_register']} consensus={r['consensus_register']}  [{tag}]  fixes={r['fixes']}")
    if total_fixes:
        print(f"\n{'WOULD HEAL' if dry else 'HEALED'} {len(total_fixes)} row(s):")
        for metric, rid, old, new in total_fixes:
            print(f"  {metric} id={rid}: {old} -> {new}")


# ----------------------------- self-test -----------------------------
def selftest():
    f = 0
    def ck(d, got, want):
        nonlocal f
        ok = got == want; f += (not ok)
        print(f"  [{'PASS' if ok else 'FAIL'}] {d}: got {got}, want {want}")

    ck("fold flip 685.53 onto 585 band", fold(685.53, 585.6), 585.53)
    ck("fold keeps correct value",       fold(585.53, 585.6), 585.53)
    ck("fold +1000 flip",                fold(1585.5, 585.6), 585.5)

    # today's real intermittent sequence: 32% are +100 flips
    seq = [584.86,685.08,585.08,685.33,685.53,685.88,586.07,586.29,686.61,586.83,687.05,587.26,587.45]
    mis, n = misread_rate(seq)
    ck("misread_rate flags the +100 flips", mis >= 4 and mis <= 6, True)
    ck("consensus register is the true ~587, not 687", true_register(consensus_series(seq)) < 600, True)

    # heal plan: stored tail poisoned +100, raw trend is clean
    t0 = datetime.datetime(2026, 6, 17, 6, 0, 0)
    def t(m): return t0 + datetime.timedelta(minutes=m)
    raw_ts = [t(0), t(20), t(40), t(60)]
    raw_folded = [585.0, 585.5, 586.0, 586.5]                       # true trend
    stored = [(1, t(10), 585.2), (2, t(30), 685.7), (3, t(50), 686.2)]   # rows 2,3 poisoned +100
    fixes = plan_heal(stored, raw_ts, raw_folded)
    ck("heal targets exactly the 2 poisoned rows", len(fixes), 2)
    ck("heal lowers id2 685.7 -> ~585.7", abs(dict((i,n) for i,_,n in fixes)[2] - 585.75) < 0.3, True)
    ck("heal never touches the correct row", 1 not in [i for i,_,_ in fixes], True)

    # a correct register must NOT be healed (consensus == stored)
    stored_ok = [(1, t(10), 585.2), (2, t(30), 585.7), (3, t(50), 586.2)]
    ck("clean register yields no fixes", plan_heal(stored_ok, raw_ts, raw_folded), [])

    print(f"\n{'ALL SELFTESTS PASSED' if f==0 else str(f)+' FAILED'}")
    sys.exit(1 if f else 0)


if __name__ == "__main__":
    sys.path.insert(0, HERE)
    if "--selftest" in sys.argv:
        selftest()
    else:
        main()
