#!/usr/bin/env python3
"""
OCR calibration lab v2 — canonical-LCD fixed-grid 7-segment decoder.

Pipeline: rotate -> crop LCD -> resize to canonical CWxCH -> green -> Otsu binary
-> decode 6 fixed digit cells (right-aligned, decimal before last 2).

Usage:
  python lab.py '{"iter":"02", ...params...}'   # run one iteration, publish
  python lab.py tune                             # sweep params, print best
"""
import sys, os, json, cv2, numpy as np, itertools

WEB = os.environ.get("EB_LAB_WEB", os.path.join(os.path.dirname(os.path.abspath(__file__)), "ocr-lab"))
IMG = os.environ.get("EB_IMAGES", os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "storage", "eb_images"))

GT = {
    "2026-06-13_150004_4a0a41.jpg": "870.30",
    "2026-06-13_150011_bb077e.jpg": "547.65",
    "2026-06-13_150018_2d25ee.jpg": "871.89",
    "2026-06-13_150032_0b927b.jpg": "546.62",
    "2026-06-13_150039_7c13b1.jpg": "4.64",
    "2026-06-13_150114_ae8b9e.jpg": "2.52",
}

DEFAULTS = dict(
    iter="00",
    cw=1000, ch=400,
    shear=-0.06,                        # de-slant the italic 7-seg digits
    c1=525.0, pitch=79.0, ncells=6,     # leftmost cell center + pitch (rectified coords)
    cellw=66.0, cy0=139.0, cy1=317.0,   # digit cell width and vertical band
    seg_f=0.15, blank_f=0.06,           # segment-on / cell-blank thresholds
)

# Production: load the active calibration written by calibrate.py. The decoder,
# batch tool, and worker all read from this file — no hardcoded grid in code.
CALIBRATION = None
_CALIB_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "calibration.json")
if os.path.exists(_CALIB_PATH):
    try:
        CALIBRATION = json.load(open(_CALIB_PATH))
        DEFAULTS.update(CALIBRATION.get("grid", {}))
        DEFAULTS.update(CALIBRATION.get("decode", {}))
        if "canonical" in CALIBRATION:
            DEFAULTS["cw"] = CALIBRATION["canonical"]["w"]
            DEFAULTS["ch"] = CALIBRATION["canonical"]["h"]
    except Exception as e:
        print(f"warning: could not load calibration.json: {e}")

SEGMAP = {
    (1,1,1,1,1,1,0):"0",(0,1,1,0,0,0,0):"1",(1,1,0,1,1,0,1):"2",(1,1,1,1,0,0,1):"3",
    (0,1,1,0,0,1,1):"4",(1,0,1,1,0,1,1):"5",(1,0,1,1,1,1,1):"6",(1,1,1,0,0,0,0):"7",
    (1,1,1,1,1,1,1):"8",(1,1,1,1,0,1,1):"9",
}

def lcd_quad(bgr):
    """Detect the 4 corners of the bright LCD glass (TL,TR,BR,BL). None if not found."""
    g = cv2.GaussianBlur(bgr[:, :, 1], (7, 7), 0)
    _, th = cv2.threshold(g, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    th = cv2.morphologyEx(th, cv2.MORPH_CLOSE, np.ones((25, 25), np.uint8))
    cnts = cv2.findContours(th, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)[0]
    if not cnts:
        return None
    c = max(cnts, key=cv2.contourArea)
    area = cv2.contourArea(c)
    frame_area = bgr.shape[0] * bgr.shape[1]
    if area < 0.12 * frame_area or area > 0.75 * frame_area:   # LCD normally ~30% of frame
        return None
    pts = c.reshape(-1, 2).astype(np.float32)
    s = pts.sum(1); d = pts[:, 0] - pts[:, 1]
    return np.array([pts[np.argmin(s)], pts[np.argmax(d)],
                     pts[np.argmax(s)], pts[np.argmin(d)]], np.float32)

def canon_binary(bgr, p, quad=None):
    W, H = int(p["cw"]), int(p["ch"])
    if quad is None:
        quad = lcd_quad(bgr)
    if quad is None:
        return None, None, None
    M = cv2.getPerspectiveTransform(quad,
            np.array([[0,0],[W,0],[W,H],[0,H]], np.float32))
    canon = cv2.warpPerspective(bgr, M, (W, H), flags=cv2.INTER_CUBIC)
    if p.get("shear"):
        k = float(p["shear"]); ym = H/2.0
        S = np.array([[1, k, -k*ym], [0, 1, 0]], np.float32)
        canon = cv2.warpAffine(canon, S, (W, H), flags=cv2.INTER_CUBIC)
    green = canon[:, :, 1]
    # CLAHE (local contrast equalization) before Otsu: the LCD's left-edge glare faintly lit a
    # phantom bottom-left segment on the leading digit (9->8, 5->6 misreads). Equalizing locally
    # lifts the real segments clear of the glare without globally raising seg_f. Validated on 704
    # energy frames (stride-2, 2.5-day span): billing misreads 32.1% (otsu) -> 28.1% (clahe),
    # best of otsu/gamma/clahe/blur. Small 241-frame run earlier read 16% — sample luck. (2026-06-15)
    green = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(green)
    blur = cv2.GaussianBlur(green, (3, 3), 0)
    _, th = cv2.threshold(blur, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    return canon, cv2.bitwise_not(th), quad   # white digits on black

def quad_drift(bgr):
    """Max corner distance (px) between this frame's LCD quad and the calibrated
    baseline. Small = camera steady (homography absorbs it); large = recalibrate."""
    if not CALIBRATION or "baseline_quad" not in CALIBRATION:
        return None
    q = lcd_quad(bgr)
    if q is None:
        return None
    base = np.array(CALIBRATION["baseline_quad"], np.float32)
    return float(np.linalg.norm(q - base, axis=1).max())

def process(bgr, p=None):
    """Full decode of one frame -> metrics dict. Robust to bad frames."""
    p = {**DEFAULTS, **(p or {})}
    bright = float(bgr[:, :, 1].mean())
    quad = lcd_quad(bgr)
    if quad is None:
        return dict(ok=False, reason="no_lcd", bright=round(bright, 1),
                    raw="", value=None, nq=0, ndig=0)
    canon, binary, _ = canon_binary(bgr, p, quad=quad)
    digits, _ = decode(binary, p)
    nq = digits.count("?")
    ndig = sum(1 for d in digits if d != " ")
    value, seq = to_value(digits, p)
    return dict(ok=value is not None, reason="ok" if value is not None else "decode_fail",
                bright=round(bright, 1), raw=seq, value=value, nq=nq, ndig=ndig)

def seg_on(cell, x0,y0,x1,y1, f):
    reg = cell[int(y0):int(y1), int(x0):int(x1)]
    return reg.size > 0 and (reg > 0).mean() > f

def decode_cell(cell, f):
    h, w = cell.shape
    if h == 0 or w == 0: return "?", None
    a = seg_on(cell,0.18*w,0.00*h,0.82*w,0.22*h,f)
    b = seg_on(cell,0.66*w,0.06*h,1.00*w,0.48*h,f)
    c = seg_on(cell,0.66*w,0.52*h,1.00*w,0.94*h,f)
    d = seg_on(cell,0.18*w,0.78*h,0.82*w,1.00*h,f)
    e = seg_on(cell,0.00*w,0.52*h,0.34*w,0.94*h,f)
    fseg=seg_on(cell,0.00*w,0.06*h,0.34*w,0.48*h,f)
    g = seg_on(cell,0.18*w,0.40*h,0.82*w,0.60*h,f)
    pat = tuple(int(v) for v in (a,b,c,d,e,fseg,g))
    return SEGMAP.get(pat, "?"), pat

def decode(binary, p, draw=None):
    digits, boxes = [], []
    cy0, cy1 = int(p["cy0"]), int(p["cy1"])
    hw = p["cellw"] / 2.0
    for i in range(int(p["ncells"])):
        cx = p["c1"] + i * p["pitch"]
        x0, x1 = int(cx - hw), int(cx + hw)
        cell = binary[cy0:cy1, max(0,x0):x1]
        ink = (cell > 0).mean() if cell.size else 0
        if ink < p["blank_f"]:
            ch = " "
        else:
            ch, _ = decode_cell(cell, p["seg_f"])
        digits.append(ch)
        boxes.append((x0, cy0, x1, cy1, ch, ink))
        if draw is not None:
            col = (0,0,255) if ch not in " ?" else (0,140,255) if ch=="?" else (90,90,90)
            cv2.rectangle(draw, (x0,cy0), (x1,cy1), col, 2)
            cv2.putText(draw, ch if ch.strip() else ".", (x0+6, cy0-8),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.9, (0,255,0), 2)
    return digits, boxes

def to_value(digits, p):
    seq = "".join(d for d in digits if d != " ")
    if not seq or "?" in seq:
        return None, seq
    # decimal: always 2 decimals for energy/MD screens
    if len(seq) < 3:
        return None, seq
    return seq[:-2] + "." + seq[-2:], seq

def detect_minus(binary, boxes, p):
    """True if a '-' sign (mid-height horizontal bar) sits just left of the first digit.
    Used for signed instantaneous metrics (current/power go negative when exporting)."""
    reals = [b for b in boxes if b[4] not in (" ", "?")]
    if not reals:
        return False
    fx0 = min(b[0] for b in reals)
    cy0, cy1 = int(p["cy0"]), int(p["cy1"])
    pitch = p["pitch"]
    x0, x1 = int(fx0 - 0.95 * pitch), int(fx0 - 0.10 * pitch)
    if x0 < 0 or x1 <= x0:
        return False
    reg = binary[cy0:cy1, x0:x1]
    if reg.size == 0:
        return False
    h = reg.shape[0]
    top = (reg[:int(h*0.33)] > 0).mean()
    mid = (reg[int(h*0.40):int(h*0.60)] > 0).mean()
    bot = (reg[int(h*0.67):] > 0).mean()
    return mid > 0.18 and mid > 2*top and mid > 2*bot   # ink concentrated in the middle band

def run(p, publish=False):
    p = {**DEFAULTS, **p}
    if publish:
        outdir = os.path.join(WEB, p["iter"]); os.makedirs(outdir, exist_ok=True)
    results, npass = [], 0
    for fn, truth in GT.items():
        bgr = cv2.imread(os.path.join(IMG, fn))
        canon, binary, _ = canon_binary(bgr, p)
        if binary is None:
            results.append(dict(file=fn, truth=truth, got=None, seq="", ok=False)); continue
        vis = cv2.cvtColor(binary, cv2.COLOR_GRAY2BGR)
        digits, boxes = decode(binary, p, draw=vis)
        got, seq = to_value(digits, p)
        ok = (got == truth); npass += ok
        results.append(dict(file=fn, truth=truth, got=got, seq=seq, ok=bool(ok)))
        if publish:
            cv2.imwrite(os.path.join(outdir, f"{fn}_value.png"), vis)
            cv2.imwrite(os.path.join(outdir, f"{fn}_frame.png"), canon)
    summary = dict(iter=p["iter"], params=p, accuracy=f"{npass}/{len(GT)}",
                   npass=npass, total=len(GT), results=results)
    if publish:
        json.dump(summary, open(os.path.join(WEB, p["iter"], "result.json"), "w"), indent=2)
        man = os.path.join(WEB, "manifest.json")
        items = json.load(open(man)) if os.path.exists(man) else []
        items = [x for x in items if x["iter"] != p["iter"]]
        items.append(dict(iter=p["iter"], accuracy=summary["accuracy"], npass=npass,
                          total=len(GT), rotate=p.get("shear", 0.0)))
        items.sort(key=lambda x: x["iter"], reverse=True)
        json.dump(items, open(man, "w"), indent=2)
    return summary

def main(params):
    s = run(params, publish=True)
    print(f"iter {s['params']['iter']}: {s['accuracy']}")
    for r in s["results"]:
        print(f"  {r['file'][11:17]} truth={r['truth']:>7} got={str(r['got']):>7} seq={r['seq']:<8} {'OK' if r['ok'] else 'x'}")

def tune():
    best = None
    grid = dict(
        shear=[-0.08,-0.06,-0.05,-0.04,-0.02],
        c1=[520,525,530,535,540],
        pitch=[77,78,79],
        cellw=[58,62,66,70],
        seg_f=[0.13,0.15,0.17,0.19],
    )
    keys = list(grid)
    combos = list(itertools.product(*[grid[k] for k in keys]))
    print(f"sweeping {len(combos)} combos...")
    for combo in combos:
        p = dict(zip(keys, combo))
        s = run(p, publish=False)
        if best is None or s["npass"] > best[0]:
            best = (s["npass"], p, s["results"])
            print(f"  new best {s['npass']}/{s['total']}: {p}")
            if s["npass"] == s["total"]: break
    print("\nBEST:", best[0], best[1])
    for r in best[2]:
        print(f"  {r['file'][11:17]} truth={r['truth']:>7} got={str(r['got']):>7} seq={r['seq']}")

if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "tune":
        tune()
    else:
        main(json.loads(sys.argv[1]) if len(sys.argv) > 1 else {})
