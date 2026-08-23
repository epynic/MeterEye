#!/usr/bin/env python3
# Replicates worker.py's cumulative decision logic (importing its REAL constants) and runs the
# actual incident scenarios through it. Proves: bad reading held, correct reading flows, mislabel killed.
import sys, os, datetime
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import worker as W

def decide(metric, v, ts, last, pending):
    """Return ('store'|'label'|'pending'|'dup'|'back', ...) mirroring worker.py exactly."""
    key = (metric, None)
    if metric in W.SIBLINGS:
        sib = last.get((W.SIBLINGS[metric], None)); own = last.get(key)
        if sib and abs(v - sib[0]) <= W.SIBLING_TOL and (not own or abs(v - own[0]) > W.SIBLING_TOL):
            return "label"
    if key in last:
        lv, lts = last[key]
        if v == lv:
            pending.pop(key, None)        # reading agrees with truth -> drop any stale suspect
            return "dup"
        hrs = max(0.0, (ts - lts).total_seconds() / 3600.0)
        allowed = min(W.MAX_RATE_KW * hrs, W.ABS_STEP_CAP) + W.JUMP_BASE
        if v < lv - W.BACK_TOL:
            return "back"                 # cumulative can't decrease: never stored, never confirmed
        phys_max = W.MAX_RATE_KW * hrs + W.JUMP_BASE
        if v - lv > phys_max and any(lv - W.BACK_TOL <= v - k <= lv + phys_max
                                     for k in (10.0, 100.0, 1000.0, 10000.0)):
            return "digit"                # leading-digit inflation while meter moved: can't be confirmed
        if round(v * 100) % 1000 == round(lv * 100) % 1000 and abs(v - lv) >= 5.0:
            return "digit"                # high-digit OCR flip (low 3 digits frozen): can't be confirmed
        if v > lv + allowed:
            p = pending.get(key)
            phrs = max(0.0, (ts - p[1]).total_seconds() / 3600.0) if p else 0.0
            if p and abs(v - p[0]) <= W.CONFIRM_TOL + W.MAX_RATE_KW * phrs:
                pending.pop(key, None)
            else:
                pending[key] = (v, ts); return "pending"
        else:
            pending.pop(key, None)
    last[key] = (v, ts)
    return "store"

t0 = datetime.datetime(2026, 6, 15, 1, 0, 0)
def t(mins): return t0 + datetime.timedelta(minutes=mins)
fails = 0
def check(desc, got, want):
    global fails
    ok = got == want
    fails += (not ok)
    print(f"  [{'PASS' if ok else 'FAIL'}] {desc}: got {got}, want {want}")

print("Scenario 1 — the actual bug: 553.33 seed, 653.33 misread arrives, then real 554.07")
last = {("ep_cu_fd", None): (553.33, t(0))}; pend = {}
# 653.33 is a clean leading-digit flip of 553.33 (low 3 digits '3.33' frozen) -> now rejected OUTRIGHT
# by the rej_digit guard (stronger than the old behaviour, which only HELD it pending a confirm).
check("653.33 misread is REJECTED outright", decide("ep_cu_fd", 653.33, t(30), last, pend), "digit")
check("real 554.07 is STORED",               decide("ep_cu_fd", 554.07, t(60), last, pend), "store")
check("export register now = 554.07",      last[("ep_cu_fd", None)][0], 554.07)
check("no stale suspect left",             pend, {})

print("Scenario 2 — IP-Cu-Fd frame (901) mislabeled as export")
last = {("ip_cu_fd", None): (901.24, t(0)), ("ep_cu_fd", None): (554.07, t(0))}; pend = {}
check("901.16 as export is REJECTED (label)", decide("ep_cu_fd", 901.16, t(10), last, pend), "label")
check("export register untouched",            last[("ep_cu_fd", None)][0], 554.07)

print("Scenario 3 — a GENUINE large jump (e.g. long outage) still gets through after confirmation")
last = {("ep_cu_fd", None): (553.33, t(0))}; pend = {}
check("first 610 (jump 57 > cap) is HELD",  decide("ep_cu_fd", 610.0, t(30), last, pend), "pending")
check("second 610.2 CONFIRMS -> stored",    decide("ep_cu_fd", 610.2, t(40), last, pend), "store")

print("Scenario 4 — normal small increment is unaffected")
last = {("ep_cu_fd", None): (553.33, t(0))}; pend = {}
check("554.07 normal increment STORED",     decide("ep_cu_fd", 554.07, t(60), last, pend), "store")

print("Scenario 5 — the NEW bug: a leading-digit DOWN-misread (901->801) can never enter, even repeated")
last = {("ip_cu_fd", None): (901.25, t(0))}; pend = {}
check("first 801.27 (down 100) is REJECTED",  decide("ip_cu_fd", 801.27, t(30), last, pend), "back")
check("second 801.27 still REJECTED (no self-confirm)", decide("ip_cu_fd", 801.27, t(40), last, pend), "back")
check("register stays 901.25 (untouched)",    last[("ip_cu_fd", None)][0], 901.25)
check("no down-misread left lurking in pending", pend, {})

print("Scenario 6 — a dup of the true value clears a stale suspect so it can't later confirm")
last = {("ip_cu_fd", None): (901.25, t(0))}; pend = {}
check("big UP jump 960 is HELD as suspect",    decide("ip_cu_fd", 960.0, t(20), last, pend), "pending")
check("a dup of 901.25 arrives -> clears suspect", decide("ip_cu_fd", 901.25, t(25), last, pend), "dup")
check("960 again is HELD again, NOT confirmed",  decide("ip_cu_fd", 960.0, t(30), last, pend), "pending")
check("register still 901.25 (jump never stored)", last[("ip_cu_fd", None)][0], 901.25)

print("Scenario 7 — incident #3: a leading-digit UP-misread (567.51->667.51) can never enter, even repeated")
last = {("ep_cu_fd", None): (567.51, t(0))}; pend = {}
check("first 667.51 (up 100, low digits frozen) REJECTED",  decide("ep_cu_fd", 667.51, t(30), last, pend), "digit")
check("second 667.51 still REJECTED (no self-confirm)",     decide("ep_cu_fd", 667.51, t(40), last, pend), "digit")
check("export register stays 567.51 (untouched)",           last[("ep_cu_fd", None)][0], 567.51)
check("no misread left lurking in pending",                 pend, {})
check("a real small increment 568.51 still STORES",         decide("ep_cu_fd", 568.51, t(50), last, pend), "store")
# contrast: a genuine big jump whose low digits DIFFER must still use the confirm path, not be killed as a flip
last = {("ep_cu_fd", None): (567.51, t(0))}; pend = {}
check("genuine jump 620.07 (low digits differ) is HELD, not killed", decide("ep_cu_fd", 620.07, t(30), last, pend), "pending")
check("a tens-digit flip 577.51 (low digits frozen) REJECTED",       decide("ep_cu_fd", 577.51, t(35), {("ep_cu_fd", None): (567.51, t(0))}, {}), "digit")

print("Scenario 8 — incident #4: intermittent dawn glare flips the hundreds digit (5->6,+100) on ~1/3")
print("            of frames WHILE the meter advances. Replays the REAL 2026-06-17 OCR sequence.")
# (ts_minute_offset, decoded_value) straight from the camera that morning; true series climbs 584.86->589.04
glare = [(0,584.86),(7,685.08),(7,585.08),(14,685.33),(21,685.53),(35,685.88),(42,586.07),(49,586.29),
         (63,686.61),(70,586.83),(77,687.05),(84,587.26),(91,587.45),(91,587.46),(98,587.62),(98,587.62),
         (110,587.78),(110,587.78),(112,587.96),(119,588.13),(126,588.31),(133,588.43),(133,588.43),
         (140,588.57),(140,688.57),(147,688.8),(152,588.81),(159,589.03),(159,589.04),(166,689.24),(173,689.43)]
last = {("ep_cu_fd", None): (584.05, t(-5))}; pend = {}; stored = []
for mins, v in glare:
    if decide("ep_cu_fd", v, t(mins), last, pend) == "store":
        stored.append(v)
poison = [x for x in stored if x > 600]
check("ZERO poisoned (>600) rows stored across the whole morning", poison, [])
check("final register stays in the true 588-590 band",  585 <= last[("ep_cu_fd", None)][0] <= 590, True)
check("register is monotonic non-decreasing", all(b >= a for a, b in zip(stored, stored[1:])), True)

print(f"\n{'ALL TESTS PASSED' if fails==0 else str(fails)+' TEST(S) FAILED'}")
sys.exit(1 if fails else 0)
