#!/usr/bin/env python3
"""
Auto-calibration for the EB meter OCR.

Derives the digit-cell grid from a sample of frames (self-supervised: digit cells
sit at fixed positions, so aggregating ink across many frames reveals them) and
writes calibration.json — the single source of truth the worker reads.

Re-run this whenever the camera framing changes a lot. Steps:
  1. sample frames, rectify each (homography on the detected LCD quad)
  2. aggregate ink -> find the digit y-band (row profile) and de-slant shear
  3. column profile -> autocorrelation gives pitch, phase gives cell boundaries
  4. build the right-aligned N-cell grid, write calibration.json
  5. validate on the labelled ground-truth set and report

Usage: python calibrate.py [n_sample]   (default 500)
"""
import sys, os, glob, json, cv2, numpy as np, lab

HERE = os.path.dirname(os.path.abspath(__file__))
CFG  = os.path.join(HERE, "calibration.json")
W, H = 1000, 400
NCELLS = 6
DECIMALS = 2

def rectify(bgr, shear=0.0, quad=None):
    if quad is None:
        quad = lab.lcd_quad(bgr)
    if quad is None:
        return None
    M = cv2.getPerspectiveTransform(quad, np.array([[0,0],[W,0],[W,H],[0,H]], np.float32))
    canon = cv2.warpPerspective(bgr, M, (W, H), flags=cv2.INTER_CUBIC)
    if shear:
        S = np.array([[1, shear, -shear*H/2], [0, 1, 0]], np.float32)
        canon = cv2.warpAffine(canon, S, (W, H), flags=cv2.INTER_CUBIC)
    return canon

def binary(canon):
    g = cv2.GaussianBlur(canon[:, :, 1], (3, 3), 0)
    _, th = cv2.threshold(g, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    return (cv2.bitwise_not(th) > 0).astype(np.float64)

MARGIN_Y, MARGIN_X = 45, 25   # ignore the dark LCD bezel that warps to the frame edges

def aggregate(canons, shear):
    acc = np.zeros((H, W)); n = 0
    for c in canons:
        if shear:
            S = np.array([[1, shear, -shear*H/2], [0, 1, 0]], np.float32)
            c = cv2.warpAffine(c, S, (W, H), flags=cv2.INTER_CUBIC)
        acc += binary(c); n += 1
    acc /= max(n, 1)
    acc[:MARGIN_Y] = 0; acc[H-MARGIN_Y:] = 0          # kill bezel borders
    acc[:, :MARGIN_X] = 0; acc[:, W-MARGIN_X:] = 0
    return acc

def find_yband(acc):
    # Wide x-range dilutes the small unit/label text; the tall digits form one
    # continuous band. Smooth + gap-tolerant run so sparse vertical rows don't split it.
    rowp = acc[:, 480:950].mean(1)
    rowp = np.convolve(rowp, np.ones(7)/7, mode="same")
    thr = max(0.25 * rowp.max(), 0.07)
    on = rowp > thr
    runs = []; s = None
    for y in range(H):
        if on[y] and s is None: s = y
        elif not on[y] and s is not None: runs.append([s, y]); s = None
    if s is not None: runs.append([s, H])
    if not runs: return 130, 320
    merged = [runs[0]]                              # merge runs separated by <30px
    for a, b in runs[1:]:
        if a - merged[-1][1] < 30: merged[-1][1] = b
        else: merged.append([a, b])
    cy0, cy1 = max(merged, key=lambda r: r[1]-r[0])
    return max(0, cy0 - 6), min(H, cy1 + 4)        # small pad for tapered edges

def periodicity(colp, x0=480, x1=965, lo=70, hi=92):
    """Strength of the digit periodicity — peaks when the grid is cleanly de-slanted."""
    s = colp[x0:x1] - colp[x0:x1].mean()
    if s.std() < 1e-6: return 0.0
    ac = np.correlate(s, s, mode="full")[len(s)-1:]
    ac = ac / ac[0]
    return float(ac[lo:hi].max())

def column_profile(acc, cy0, cy1):
    return acc[cy0:cy1, :].mean(0)

def valley_contrast(colp, x0=480, x1=965):
    seg = cv2.GaussianBlur(colp[x0:x1].astype(np.float32), (1, 1), 0)
    return float(seg.max() - seg.min()) if seg.size else 0.0

def estimate_pitch(colp, x0=480, x1=965, lo=60, hi=100):
    s = colp[x0:x1] - colp[x0:x1].mean()
    ac = np.correlate(s, s, mode="full")[len(s)-1:]
    lags = range(lo, min(hi, len(ac)))
    return max(lags, key=lambda L: ac[L])

def best_phase(colp, pitch, x0=500, x1=965):
    """phase = x of a gridline (valley); pick offset whose gridlines sit on profile minima."""
    xs = np.arange(x0, x1)
    best = None
    for ph in range(pitch):
        lines = [x for x in range(ph, x1, pitch) if x0 <= x < x1]
        score = sum(colp[x] for x in lines) / max(len(lines), 1)  # want LOW (valleys)
        if best is None or score < best[1]:
            best = (ph, score, lines)
    return best[2]  # list of gridline x positions

def rightmost_cell(colp, pitch):
    """Align a cell grid to MAXIMISE ink at centers; return rightmost populated center."""
    best = None
    for off in range(pitch):
        centers = list(range(off, W, pitch))
        score = sum(colp[c] for c in centers if 0 <= c < W)
        if best is None or score > best[0]:
            best = (score, centers)
    centers = best[1]
    ink = [(c, colp[c]) for c in centers if 460 <= c <= 950]
    mx = max(v for _, v in ink) if ink else 0
    pops = [c for c, v in ink if v > 0.45 * mx]
    return max(pops) if pops else 920

def clean_count(bins, p):
    """Label-free quality: how many frames decode to a clean reading (>=3 digits, no '?')."""
    n = 0
    for b in bins:
        digits, _ = lab.decode(b, p)
        ndig = sum(1 for d in digits if d != " ")
        if ndig >= 3 and "?" not in digits:
            n += 1
    return n

def sheared_bins(canons, shear):
    out = []
    for c in canons:
        if shear:
            S = np.array([[1, shear, -shear*H/2], [0, 1, 0]], np.float32)
            c = cv2.warpAffine(c, S, (W, H), flags=cv2.INTER_CUBIC)
        g = cv2.GaussianBlur(c[:, :, 1], (3, 3), 0)
        _, th = cv2.threshold(g, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        out.append(cv2.bitwise_not(th))
    return out

def derive(sample):
    canons, quads = [], []
    for p in sample:
        bgr = cv2.imread(p)
        if bgr is None: continue
        q = lab.lcd_quad(bgr)
        if q is None: continue
        c = rectify(bgr, shear=0.0, quad=q)
        if c is not None:
            canons.append(c); quads.append(q)
    if len(canons) < 20:
        raise SystemExit(f"only {len(canons)} usable frames — need more")

    # 1) geometry from the shear-0 aggregate: y-band, pitch, rough right anchor
    acc0 = aggregate(canons, 0.0)
    cy0, cy1 = find_yband(acc0)
    colp = column_profile(acc0, cy0, cy1)
    pitch = int(estimate_pitch(colp))
    cellw = int(round(pitch * 0.82))
    c_right = rightmost_cell(colp, pitch)
    c1_rough = c_right - (NCELLS - 1) * pitch

    # 2) optimise shear + anchor + seg_f against the label-free clean-decode rate
    base = dict(lab.DEFAULTS, pitch=pitch, cellw=cellw, cy0=cy0, cy1=cy1,
                ncells=NCELLS, cw=W, ch=H, blank_f=0.06)
    best = None
    for shear in [round(x*0.02, 2) for x in range(-5, 1)]:   # 0,-0.02..-0.10
        bins = sheared_bins(canons, shear)
        for c1off in range(-18, 19, 6):
            for seg_f in (0.12, 0.15, 0.18, 0.21):
                p = dict(base, c1=c1_rough + c1off, seg_f=seg_f, shear=shear)
                score = clean_count(bins, p)
                if best is None or score > best[0]:
                    best = (score, shear, c1_rough + c1off, seg_f)
    score, shear, c1, seg_f = best

    acc = aggregate(canons, shear)
    baseline_quad = np.mean(quads, axis=0).astype(int).tolist()
    cfg = dict(
        version=1, created="2026-06-14", canonical=dict(w=W, h=H),
        grid=dict(c1=round(float(c1), 1), pitch=pitch, cellw=cellw,
                  cy0=int(cy0), cy1=int(cy1), ncells=NCELLS, shear=shear),
        decode=dict(seg_f=seg_f, blank_f=0.06), decimals=DECIMALS,
        baseline_quad=baseline_quad, n_calib_frames=len(canons),
        clean_rate=f"{score}/{len(canons)}",
    )
    return cfg, acc

def write_cfg(cfg):
    json.dump(cfg, open(CFG, "w"), indent=2)

def validate(cfg):
    p = {**lab.DEFAULTS, **cfg["grid"], **cfg["decode"],
         "cw": cfg["canonical"]["w"], "ch": cfg["canonical"]["h"]}
    npass = 0
    out = []
    for fn, truth in lab.GT.items():
        bgr = cv2.imread(os.path.join(lab.IMG, fn))
        _, binimg, _ = lab.canon_binary(bgr, p)
        digits, _ = lab.decode(binimg, p)
        got, seq = lab.to_value(digits, p)
        ok = got == truth; npass += ok
        out.append((fn[11:17], truth, got, ok))
    return npass, len(lab.GT), out

if __name__ == "__main__":
    npick = int(sys.argv[1]) if len(sys.argv) > 1 else 500
    files = sorted(glob.glob(lab.IMG + "/*.jpg"))
    sample = files[::max(1, len(files)//npick)][:npick]
    print(f"calibrating from {len(sample)} sampled frames...")
    cfg, acc = derive(sample)
    print("\nDERIVED calibration:")
    print(json.dumps({k: cfg[k] for k in ("grid","decode","baseline_quad","n_calib_frames")}, indent=2))
    np_, tot, out = validate(cfg)
    print(f"\nvalidation on ground truth: {np_}/{tot}")
    for t, truth, got, ok in out:
        print(f"  {t} truth={truth:>7} got={str(got):>7} {'OK' if ok else 'x'}")
    if np_ == tot:
        write_cfg(cfg)
        print(f"\n✅ wrote {CFG}")
    else:
        print(f"\n⚠️  not writing calibration.json (validation {np_}/{tot} < {tot}). Inspect first.")
    # save aggregate heatmap with the derived grid drawn
    hm = cv2.applyColorMap((acc*255).astype(np.uint8), cv2.COLORMAP_JET)
    g = cfg["grid"]
    for i in range(g["ncells"]):
        cx = int(g["c1"] + i*g["pitch"])
        cv2.rectangle(hm, (cx-g["cellw"]//2, g["cy0"]), (cx+g["cellw"]//2, g["cy1"]), (255,255,255), 1)
    cv2.imwrite(os.path.join(HERE, "debug/calib_result.png"), hm)
    print("saved debug/calib_result.png")
