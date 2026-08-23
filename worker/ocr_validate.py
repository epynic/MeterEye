#!/usr/bin/env python3
"""
Large-batch OCR validation. Decode a time-spanning sample of on-disk frames under the
current pipeline vs candidate settings, and count MISREADS as deviations from the local
consensus (rolling median of nearby same-register readings). Lower misread% = better.

Classification (which register a frame is) is done ONCE under the current pipeline and is
identical across candidates — only the digit-decode changes — so this isolates the fix.

Run: sudo -u www-data ./venv/bin/python ocr_validate.py [stride]
"""
import sys, glob, os, re, statistics, cv2, numpy as np
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import lab, labels

IMG = lab.IMG + "/"
STRIDE = int(sys.argv[1]) if len(sys.argv) > 1 else 6
ENERGY = {"ip_cu", "ep_cu", "ip_cu_fd", "ep_cu_fd", "reactive_1", "reactive_2", "reactive_3", "reactive_4"}
FNAME_RE = re.compile(r"_(\d{6})_")

# candidate decode pipelines: (label, binarize-mode, seg_f)
SETTINGS = [
    ("current(otsu,0.15)", "otsu",     0.15),
    ("gamma1.6,0.20",      "gamma1.6", 0.20),
    ("clahe,0.15",         "clahe",    0.15),
    ("blur5,0.20",         "blur5",    0.20),
]

def binarize(canon, mode):
    g = canon[:, :, 1]
    if mode == "otsu":
        b = cv2.GaussianBlur(g, (3, 3), 0)
        _, th = cv2.threshold(b, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    elif mode == "gamma1.6":
        g2 = (((g / 255.0) ** 1.6) * 255).astype(np.uint8)
        b = cv2.GaussianBlur(g2, (3, 3), 0)
        _, th = cv2.threshold(b, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    elif mode == "clahe":
        cl = cv2.createCLAHE(2.0, (8, 8)).apply(g)
        b = cv2.GaussianBlur(cl, (3, 3), 0)
        _, th = cv2.threshold(b, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    elif mode == "blur5":
        b = cv2.GaussianBlur(g, (5, 5), 0)
        _, th = cv2.threshold(b, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    return cv2.bitwise_not(th)

def decode_value(binary, seg_f):
    p = dict(lab.DEFAULTS); p["seg_f"] = seg_f
    digits, _ = lab.decode(binary, p)
    val, _ = lab.to_value(digits, p)
    return float(val) if val is not None else None

labels.build_templates(); labels.build_unit_templates(); labels.build_phase_templates()

files = sorted(glob.glob(IMG + "*.jpg"))[::STRIDE]
print(f"sampling {len(files)} of frames (stride {STRIDE}) spanning {os.path.basename(files[0])[:17]} .. {os.path.basename(files[-1])[:17]}")

# rec[setting][metric] = list of (sortkey, value)   (sortkey = filename, chronological)
rec = {s[0]: {} for s in SETTINGS}
n_energy = 0
for i, f in enumerate(files):
    if i % 400 == 0:
        print(f"  ...{i}/{len(files)}", file=sys.stderr)
    bgr = cv2.imread(f)
    if bgr is None:
        continue
    canon, _, _ = lab.canon_binary(bgr, lab.DEFAULTS)
    if canon is None:
        continue
    res = labels.classify_metric(canon)          # classify ONCE (current pipeline)
    if res is None or res[0] not in ENERGY:
        continue
    metric = res[0]; n_energy += 1
    base = os.path.basename(f)
    for label, mode, seg_f in SETTINGS:
        v = decode_value(binarize(canon, mode), seg_f)
        rec[label].setdefault(metric, []).append((base, v))

print(f"\nenergy-register frames classified: {n_energy}\n")

def analyse(series, tol=20.0, win=4):
    """series: list of (sortkey, value). Returns (n_read, n_fail, n_misread)."""
    series = sorted(series, key=lambda x: x[0])
    vals = [v for _, v in series]
    n_fail = sum(1 for v in vals if v is None)
    good_idx = [i for i, v in enumerate(vals) if v is not None]
    n_mis = 0
    for k, i in enumerate(good_idx):
        lo = max(0, k - win); hi = min(len(good_idx), k + win + 1)
        neigh = [vals[good_idx[j]] for j in range(lo, hi)]
        med = statistics.median(neigh)
        if abs(vals[i] - med) > tol:
            n_mis += 1
    return len(good_idx), n_fail, n_mis

METRICS = ["ip_cu_fd", "ep_cu_fd", "ip_cu", "ep_cu", "reactive_1", "reactive_2", "reactive_3", "reactive_4"]
print(f"{'metric':12} " + " ".join(f"{s[0]:>20}" for s in SETTINGS))
print("-" * (12 + 21 * len(SETTINGS)))
totals = {s[0]: [0, 0] for s in SETTINGS}   # [reads, misreads]
for m in METRICS:
    cells = []
    for label, _, _ in SETTINGS:
        ser = rec[label].get(m, [])
        if not ser:
            cells.append(f"{'-':>20}"); continue
        nread, nfail, nmis = analyse(ser)
        totals[label][0] += nread; totals[label][1] += nmis
        pct = (100.0 * nmis / nread) if nread else 0.0
        cells.append(f"{nmis:>3}/{nread:<3} ({pct:4.1f}%)   ".rjust(20))
    print(f"{m:12} " + " ".join(cells))

print("-" * (12 + 21 * len(SETTINGS)))
cells = []
for label, _, _ in SETTINGS:
    r, mis = totals[label]
    pct = (100.0 * mis / r) if r else 0.0
    cells.append(f"{mis:>3}/{r:<3} ({pct:4.1f}%)   ".rjust(20))
print(f"{'TOTAL':12} " + " ".join(cells))

print("\nBILLING registers only (ip_cu_fd + ep_cu_fd):")
for label, _, _ in SETTINGS:
    r = mis = 0
    for m in ("ip_cu_fd", "ep_cu_fd"):
        ser = rec[label].get(m, [])
        if ser:
            nread, _, nmis = analyse(ser); r += nread; mis += nmis
    pct = (100.0 * mis / r) if r else 0.0
    print(f"  {label:22} {mis:>3} misreads / {r:<4} reads  ({pct:.1f}%)")
