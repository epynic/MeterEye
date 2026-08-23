#!/usr/bin/env python3
"""
Label classifier for the meter LCD (top-center alphanumeric 14-seg text).
Template matching on the rectified label zone — robust for a fixed, finite set.
Only the energy labels we care about need templates; everything else -> 'other'.
"""
import os, glob, cv2, numpy as np, lab

# label zone in rectified canonical coords (left arrows cropped out)
LABEL_ZONE = (310, 5, 778, 122)

# Reference frames per label live in refs/<key>/*.jpg and are MEDIAN-combined into one template
# at load (robust to per-frame glare/contrast). REBUILT 2026-06-16 from operational frames after
# the original single 2026-06-13 calibration frames were auto-deleted by the 2-day image cleanup
# -- which crashed the worker (cv2.imread None) every run. refs/ lives OUTSIDE storage/eb_images so
# the cleanup can never touch it. To recalibrate, drop fresh frames into these dirs (more frames =
# sharper template). Build is tolerant: a missing/empty dir is skipped, never crashes.
REFS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "refs")
REF = {  # label -> refs/ subdir
    "IP-Cu": "IP-Cu", "EP-Cu": "EP-Cu", "IP-Cu-Fd": "IP-Cu-Fd", "EP-Cu-Fd": "EP-Cu-Fd",
    "EP-HO": "EP-HO",                              # max-demand (kW) / date+time sub-screens
    "Ld-rCt-1": "Ld-rCt-1", "Ld-rCt-2": "Ld-rCt-2",   # reactive energy registers (kVArh)
    "Ld-rCt-3": "Ld-rCt-3", "Ld-rCt-4": "Ld-rCt-4",
}   # IP-HO intentionally omitted: its calibration frame was lost, the screen is ~never shown
    # (1 md_import row ever), and a stray IP-HO frame is caught by the value range/back guards.

def _median_template(subdir, binf):
    """Median-combine the binary feature of every refs/<subdir>/*.jpg into one template.
    Returns None (caller skips the label) if the dir is missing/empty/unreadable -- so a
    deleted reference can never crash the worker the way the hardcoded 06-13 frames did."""
    stack = []
    for f in sorted(glob.glob(os.path.join(REFS, subdir, "*.jpg"))):
        bgr = cv2.imread(f)
        if bgr is None:
            continue
        canon, _, _ = lab.canon_binary(bgr, lab.DEFAULTS)
        if canon is None:
            continue
        stack.append(binf(canon).astype(np.float32))
    if not stack:
        return None
    return (np.median(np.stack(stack), 0) > 127).astype(np.uint8) * 255

# only these get stored as kWh readings
ENERGY_LABELS = {"IP-Cu", "EP-Cu", "IP-Cu-Fd", "EP-Cu-Fd"}

# label -> (metric, unit, kind). kind: cumulative (monotonic), md (max-demand), instant.
# IP-HO/EP-HO carry MD kW but the label also shows date/time/PF sub-screens -> worker
# accepts only plausible kW values (filters those out by range).
METRIC_META = {
    "IP-Cu":    ("ip_cu",      "kWh",   "cumulative"),
    "IP-Cu-Fd": ("ip_cu_fd",   "kWh",   "cumulative"),
    "EP-Cu":    ("ep_cu",      "kWh",   "cumulative"),
    "EP-Cu-Fd": ("ep_cu_fd",   "kWh",   "cumulative"),
    "IP-HO":    ("md_import",  "kW",    "md"),
    "EP-HO":    ("md_export",  "kW",    "md"),
    "Ld-rCt-1": ("reactive_1", "kVArh", "cumulative"),
    "Ld-rCt-2": ("reactive_2", "kVArh", "cumulative"),
    "Ld-rCt-3": ("reactive_3", "kVArh", "cumulative"),
    "Ld-rCt-4": ("reactive_4", "kVArh", "cumulative"),
}
# blank-top screens identified by their unit glyph instead of a label:
UNIT_METRIC = {  # unit -> (metric, unit, kind)
    "V": ("voltage", "V", "instant"),
    "A": ("current", "A", "instant"),
}

def label_binary(canon):
    x0, y0, x1, y1 = LABEL_ZONE
    g = cv2.cvtColor(canon[y0:y1, x0:x1], cv2.COLOR_BGR2GRAY)
    _, th = cv2.threshold(cv2.GaussianBlur(g, (3, 3), 0), 0, 255,
                          cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    return th  # text(dark)=0, bg=255

def _ink(b):
    return b == 0

def iou_shift(B, T, rng=2):
    """Best IoU of ink pixels over small shifts (handles sub-pixel misalignment).
    rng=2 (5x5 shifts) is enough since the homography rectifies to consistent coords."""
    ib = _ink(B); best = 0.0
    H, W = T.shape
    for dy in range(-rng, rng+1):
        for dx in range(-rng, rng+1):
            Ts = np.roll(np.roll(T, dy, 0), dx, 1)
            it = _ink(Ts)
            inter = np.logical_and(ib, it).sum()
            union = np.logical_or(ib, it).sum()
            if union:
                best = max(best, inter / union)
    return best

TEMPLATES = {}

def build_templates(save_dir=None):
    TEMPLATES.clear()
    for lbl, sub in REF.items():
        T = _median_template(sub, label_binary)
        if T is None:
            print(f"warning: no reference frames for label {lbl} (refs/{sub}) -- skipped")
            continue
        TEMPLATES[lbl] = T
        if save_dir:
            cv2.imwrite(f"{save_dir}/tpl_{lbl}.png", T)
    return TEMPLATES

FIRSTCHAR_FRAC = 0.12   # left fraction of label zone = just the first character (I vs E)

def iou_band(B, T, x0f, x1f, rng=2):
    W = T.shape[1]; a, b = int(x0f * W), int(x1f * W)
    return iou_shift(B[:, a:b], T[:, a:b], rng)

def classify(canon, min_iou=0.84, min_margin=0.05, fc_min=0.55):
    """Return (label, score, margin). Rank by full-zone IoU, then disambiguate.

    Confusable SIBLINGS (same text except the FIRST char — IP<->EP — or the LAST char —
    Ld-rCt-1..4) are near-tied on full-zone IoU BY DESIGN, so the plain margin gate would
    wrongly reject the true label (this silently dropped EP-Cu-Fd / Ld-rCt-1, freezing the
    export billing register, 2026-06-16). For those pairs we skip the margin gate and decide
    on the DISTINGUISHING character region instead (pick whichever side matches it better,
    then require that region to clear its own threshold). Non-sibling cases keep the original
    margin + first-char gates that separate Cu vs HO etc."""
    if not TEMPLATES:
        build_templates()
    B = label_binary(canon)
    scores = sorted(((iou_shift(B, T), lbl) for lbl, T in TEMPLATES.items()), reverse=True)
    best_s, best_l = scores[0]
    if best_s < min_iou:
        return "other", round(best_s, 3), 0.0
    second_l = scores[1][1] if len(scores) > 1 else None
    second_s = scores[1][0] if len(scores) > 1 else 0.0
    margin = best_s - second_s
    sib_first = second_l is not None and best_l[1:] == second_l[1:] and best_l[0] != second_l[0]
    sib_last  = second_l is not None and best_l[:-1] == second_l[:-1] and best_l[-1] != second_l[-1]
    if sib_first:   # IP <-> EP: decide on the first character
        if iou_band(B, TEMPLATES[second_l], 0.0, FIRSTCHAR_FRAC) > iou_band(B, TEMPLATES[best_l], 0.0, FIRSTCHAR_FRAC):
            best_l, best_s = second_l, second_s
        if iou_band(B, TEMPLATES[best_l], 0.0, FIRSTCHAR_FRAC) < fc_min:
            return "other", round(best_s, 3), round(margin, 3)
    elif sib_last:  # Ld-rCt-N: decide on the last character (register digit)
        if iou_band(B, TEMPLATES[second_l], 0.75, 1.0) > iou_band(B, TEMPLATES[best_l], 0.75, 1.0):
            best_l, best_s = second_l, second_s
        if iou_band(B, TEMPLATES[best_l], 0.75, 1.0) < 0.40:
            return "other", round(best_s, 3), round(margin, 3)
    else:
        if margin < min_margin:
            return "other", round(best_s, 3), round(margin, 3)
        if iou_band(B, TEMPLATES[best_l], 0.0, FIRSTCHAR_FRAC) < fc_min:
            return "other", round(best_s, 3), round(margin, 3)   # first letter doesn't match
        if best_l.startswith("Ld-rCt") and iou_band(B, TEMPLATES[best_l], 0.75, 1.0) < 0.40:
            return "other", round(best_s, 3), round(margin, 3)   # register digit doesn't match
    return best_l, round(best_s, 3), round(margin, 3)

# ---- per-phase indicator (R/Y/B) for voltage/current screens ----
PHASE_ZONE = (172, 128, 268, 322)
PHASE_REF = {"R": "PH-R", "Y": "PH-Y", "B": "PH-B"}   # refs/ subdirs (median-combined)
PHASE_TPL = {}

def phase_binary(canon):
    x0, y0, x1, y1 = PHASE_ZONE
    g = cv2.cvtColor(canon[y0:y1, x0:x1], cv2.COLOR_BGR2GRAY)
    _, th = cv2.threshold(cv2.GaussianBlur(g, (3, 3), 0), 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    return th

def build_phase_templates():
    PHASE_TPL.clear()
    for p, sub in PHASE_REF.items():
        T = _median_template(sub, phase_binary)
        if T is None:
            print(f"warning: no reference frames for phase {p} (refs/{sub}) -- skipped")
            continue
        PHASE_TPL[p] = T

def detect_phase(canon, min_iou=0.48, min_margin=0.08):
    if not PHASE_TPL:
        build_phase_templates()
    B = phase_binary(canon)
    s = sorted(((iou_shift(B, T), p) for p, T in PHASE_TPL.items()), reverse=True)
    if s[0][0] < min_iou or (s[0][0] - s[1][0]) < min_margin:
        return None
    return s[0][1]

# ---- unit-glyph detection for blank-top screens (voltage V / current A) ----
UNIT_ZONE = (818, 320, 948, 380)
UNIT_REF = {"V": "UN-V", "A": "UN-A"}   # refs/ subdirs (median-combined)
UNIT_TPL = {}

def unit_binary(canon):
    x0, y0, x1, y1 = UNIT_ZONE
    g = cv2.cvtColor(canon[y0:y1, x0:x1], cv2.COLOR_BGR2GRAY)
    _, th = cv2.threshold(cv2.GaussianBlur(g, (3, 3), 0), 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    return th

def build_unit_templates():
    UNIT_TPL.clear()
    for u, sub in UNIT_REF.items():
        T = _median_template(sub, unit_binary)
        if T is None:
            print(f"warning: no reference frames for unit {u} (refs/{sub}) -- skipped")
            continue
        UNIT_TPL[u] = T

def detect_unit(canon, min_iou=0.55, min_margin=0.15):
    if not UNIT_TPL:
        build_unit_templates()
    B = unit_binary(canon)
    scores = sorted(((iou_shift(B, T), u) for u, T in UNIT_TPL.items()), reverse=True)
    best_s, best_u = scores[0]
    margin = best_s - (scores[1][0] if len(scores) > 1 else 0.0)
    if best_s < min_iou or margin < min_margin:
        return None, round(best_s, 3)
    return best_u, round(best_s, 3)

# unified: returns (metric, unit, kind, phase, score) or None
def classify_metric(canon):
    lbl, score, margin = classify(canon)
    if lbl in METRIC_META:
        m, u, k = METRIC_META[lbl]
        return (m, u, k, None, score)
    if lbl == "other":
        u, us = detect_unit(canon)
        if u in UNIT_METRIC:
            m, uu, k = UNIT_METRIC[u]
            return (m, uu, k, detect_phase(canon), us)
    return None

if __name__ == "__main__":
    build_templates(); build_unit_templates(); build_phase_templates()
    print(f"templates built: {len(TEMPLATES)} labels, {len(UNIT_TPL)} units, {len(PHASE_TPL)} phases")
    # self-check: classify one sample frame from each label's refs/ dir
    for lbl, sub in REF.items():
        fs = sorted(glob.glob(os.path.join(REFS, sub, "*.jpg")))
        if not fs:
            print(f"{lbl:<10} -> (no refs)"); continue
        canon, _, _ = lab.canon_binary(cv2.imread(fs[0]), lab.DEFAULTS)
        print(f"{lbl:<10} -> {classify(canon)}")
