#!/usr/bin/env python3
"""
OCR diagnostic — why does the LEADING digit misread, and does taming brightness fix it?

Runs the EXACT frames that produced wrong results (plus the 6 calibration-good frames) through:
  A) a per-segment breakdown of the leading digit  -> see which segment is borderline
  B) a sweep over preprocessing + seg_f + leftmost-cell position -> what decodes ALL frames right

Run:  sudo -u www-data ./venv/bin/python ocr_diag.py
"""
import sys, cv2, numpy as np
sys.path.insert(0, "/var/www/prasanha.com/worker")
import lab

IMG = lab.IMG + "/"

# (file, ground-truth VALUE, note).  The first two are the frames that misread.
TESTS = [
    ("2026-06-15_051422_eaeceb.jpg", "901.27", "MISREAD->801.27 (lead 9->8)"),
    ("2026-06-15_054229_53e53b.jpg", "558.05", "MISREAD->658.05 (lead 5->6)"),
] + [(f, v, "calib-good") for f, v in lab.GT.items()]

SEG_NAMES = ["a top", "b t-r", "c b-r", "d bot", "e b-l", "f t-l", "g mid"]
SEG_REGIONS = [  # matches lab.decode_cell exactly (x0f,y0f,x1f,y1f as fractions of the cell)
    (0.18, 0.00, 0.82, 0.22), (0.66, 0.06, 1.00, 0.48), (0.66, 0.52, 1.00, 0.94),
    (0.18, 0.78, 0.82, 1.00), (0.00, 0.52, 0.34, 0.94), (0.00, 0.06, 0.34, 0.48),
    (0.18, 0.40, 0.82, 0.60),
]
# which segments SHOULD be on, per digit (to mark expected vs measured)
TRUTH_SEG = {"0":"abcdef","1":"bc","2":"abdeg","3":"abcdg","4":"bcfg",
             "5":"acdfg","6":"acdefg","7":"abc","8":"abcdefg","9":"abcdfg"}

def seg_fracs(cell):
    h, w = cell.shape
    out = []
    for (x0, y0, x1, y1) in SEG_REGIONS:
        reg = cell[int(y0*h):int(y1*h), int(x0*w):int(x1*w)]
        out.append(float((reg > 0).mean()) if reg.size else 0.0)
    return out

def green_canon(bgr, p):
    """rectified + sheared canon (BGR); reuse lab's geometry, then we re-threshold ourselves."""
    canon, _, _ = lab.canon_binary(bgr, p)
    return canon

def binarize(canon, mode):
    """white-digits-on-black under different preprocessing strategies."""
    g = canon[:, :, 1]
    if mode == "otsu":                       # CURRENT production pipeline
        b = cv2.GaussianBlur(g, (3, 3), 0)
        _, th = cv2.threshold(b, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    elif mode == "gamma1.6":                 # tame highlights (user hypothesis: "too bright")
        g2 = (((g / 255.0) ** 1.6) * 255).astype(np.uint8)
        b = cv2.GaussianBlur(g2, (3, 3), 0)
        _, th = cv2.threshold(b, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    elif mode == "clahe":                    # local contrast equalize, then Otsu
        cl = cv2.createCLAHE(2.0, (8, 8)).apply(g)
        b = cv2.GaussianBlur(cl, (3, 3), 0)
        _, th = cv2.threshold(b, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    elif mode == "adaptive":                 # per-region threshold (robust to uneven glare)
        b = cv2.GaussianBlur(g, (5, 5), 0)
        th = cv2.adaptiveThreshold(b, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 41, 6)
    elif mode == "blur5":                    # heavier smoothing (kill speckle that fakes a segment)
        b = cv2.GaussianBlur(g, (5, 5), 0)
        _, th = cv2.threshold(b, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    else:
        raise ValueError(mode)
    return cv2.bitwise_not(th)

def first_active_cell(binary, p):
    cy0, cy1 = int(p["cy0"]), int(p["cy1"]); hw = p["cellw"] / 2.0
    for i in range(int(p["ncells"])):
        cx = p["c1"] + i * p["pitch"]; x0, x1 = int(cx - hw), int(cx + hw)
        cell = binary[cy0:cy1, max(0, x0):x1]
        if cell.size and (cell > 0).mean() >= p["blank_f"]:
            return cell, i
    return None, None

def decode_value(binary, p, seg_f, c1=None, cellw=None):
    pp = dict(p); pp["seg_f"] = seg_f
    if c1 is not None: pp["c1"] = c1
    if cellw is not None: pp["cellw"] = cellw
    digits, _ = lab.decode(binary, pp)
    val, _ = lab.to_value(digits, pp)
    return val

# ---------------------------------------------------------------- Experiment A
print("="*78)
print("EXPERIMENT A — leading-digit segment breakdown on the MISREAD frames")
print("  (production pipeline 'otsu', seg_f threshold = %.2f)" % lab.DEFAULTS["seg_f"])
print("="*78)
P = dict(lab.DEFAULTS)
for fn, gt, note in TESTS[:2]:
    bgr = cv2.imread(IMG + fn)
    canon = green_canon(bgr, P)
    binj = binarize(canon, "otsu")
    cell, idx = first_active_cell(binj, P)
    fr = seg_fracs(cell)
    lead_truth = gt.replace(".", "")[0]
    want = TRUTH_SEG[lead_truth]
    print(f"\n{fn}  ({note})  true leading digit = {lead_truth}")
    print(f"   {'seg':6} {'frac':>6}  {'>thr?':5} {'should-be':9}")
    for i, name in enumerate(SEG_NAMES):
        seg_letter = "abcdefg"[i]
        on = fr[i] > P["seg_f"]
        should = seg_letter in want
        flag = "  <-- PHANTOM" if (on and not should) else ("  <-- MISSING" if (not on and should) else "")
        print(f"   {name:6} {fr[i]:6.3f}  {str(on):5} {('on' if should else 'off'):9}{flag}")
    # also show what gamma (brightness taming) does to the same cell
    cg = binarize(canon, "gamma1.6"); cellg, _ = first_active_cell(cg, P); frg = seg_fracs(cellg)
    print(f"   [brightness-tamed gamma1.6] e b-l frac: {fr[4]:.3f} -> {frg[4]:.3f}   "
          f"top a frac: {fr[0]:.3f} -> {frg[0]:.3f}")

# ---------------------------------------------------------------- Experiment B
print("\n" + "="*78)
print("EXPERIMENT B — sweep: which settings decode ALL 8 frames correctly?")
print("="*78)
MODES = ["otsu", "gamma1.6", "clahe", "adaptive", "blur5"]
SEGFS = [0.15, 0.20, 0.25, 0.30]
C1SHIFTS = [0.0, 4.0, 8.0]            # nudge leftmost cell RIGHT, away from left-edge glare
base_c1 = lab.DEFAULTS["c1"]

# cache canon+binary per (frame,mode)
canons = {fn: green_canon(cv2.imread(IMG + fn), P) for fn, _, _ in TESTS}
results = []
for mode in MODES:
    bins = {fn: binarize(canons[fn], mode) for fn, _, _ in TESTS}
    for seg_f in SEGFS:
        for sh in C1SHIFTS:
            n_ok = 0; lead_ok = 0; detail = []
            for fn, gt, _ in TESTS:
                got = decode_value(bins[fn], P, seg_f, c1=base_c1 + sh)
                ok = (got == gt); n_ok += ok
                # leading-digit correctness specifically
                lg = (got or "").replace(".", "")
                tg = gt.replace(".", "")
                lead_ok += bool(lg) and len(lg) == len(tg) and lg[0] == tg[0]
                detail.append((fn, gt, got, ok))
            results.append((n_ok, lead_ok, mode, seg_f, sh, detail))

results.sort(key=lambda r: (r[0], r[1]), reverse=True)
print(f"\n{'mode':9} {'seg_f':6} {'c1+':4} {'all-ok':7} {'lead-ok':7}")
print("-"*44)
for n_ok, lead_ok, mode, seg_f, sh, _ in results[:12]:
    star = "  <== best" if (n_ok, lead_ok) == (results[0][0], results[0][1]) else ""
    print(f"{mode:9} {seg_f:<6} {sh:<4.0f} {n_ok}/8     {lead_ok}/8{star}")

print(f"\nCURRENT production = (otsu, seg_f=0.15, c1+0):")
cur = next(r for r in results if r[2]=="otsu" and r[3]==0.15 and r[4]==0.0)
print(f"   -> {cur[0]}/8 all-correct, {cur[1]}/8 leading-digit-correct")
for fn, gt, got, ok in cur[5]:
    if not ok: print(f"      MISS {fn[11:17]} want {gt:>7} got {str(got):>7}")

print(f"\nBEST = ({results[0][2]}, seg_f={results[0][3]}, c1+{results[0][4]:.0f}):  "
      f"{results[0][0]}/8 all-correct, {results[0][1]}/8 leading-ok")
for fn, gt, got, ok in results[0][5]:
    mark = "ok " if ok else "MISS"
    print(f"      {mark} {fn[11:17]} want {gt:>7} got {str(got):>7}")
