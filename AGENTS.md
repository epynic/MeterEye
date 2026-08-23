# AGENTS.md

Guidance for AI coding agents working in this repo. Read this before making
changes — several invariants here exist because violating them silently
corrupted production data in the past.

## What this is

Camera → custom OCR → MariaDB → PHP dashboard, for a physical electricity
meter with no data port. Three independently deployable pieces:

- `firmware/` — ESP32-CAM firmware (C++/Arduino, PlatformIO). Captures a
  frame, POSTs it to `web/upload.php`.
- `worker/` — Python cron daemon. Reads uploaded frames, decodes digits,
  writes clean rows to `readings`.
- `web/` — PHP dashboard/API. Reads from MariaDB, no write path except
  `upload.php` (stores images) and `settings.php` (admin, key-gated).

No build step for `web/` or `worker/` — PHP/Python run directly. `firmware/`
needs PlatformIO (`pio run`) or the Arduino IDE for the legacy sketch.

## Hard invariants — do not break these

1. **`readings` is append-only in application code.** The app DB user should
   have no DELETE grant on it. If you're asked to "fix" bad data by deleting
   rows, that's the wrong layer — use `readings_raw`/consensus tooling
   (`ocr_health.py`) or a one-off admin query, not application code.
2. **A cumulative register (`*_cu`, `*_cu_fd`) never decreases.** Any code
   path that writes to `readings` for these metrics must reject a value
   lower than the last confirmed one. This is what `worker.py`'s guards
   exist for — see `worker/test_guard.py` for the specific incidents that
   produced each guard, before changing guard logic.
3. **Run `worker/test_guard.py` before and after any change to
   `worker.py`, `lab.py`, or `labels.py`.** It replays real historical
   misread scenarios, including a byte-for-byte incident replay. A change
   that makes these tests pass for the wrong reason (e.g. loosening a
   threshold instead of fixing logic) is worse than not fixing the bug.
4. **`worker/calibration.example.json` is a template, not live config.**
   It encodes camera-specific pixel geometry (LCD quad corners, grid
   spacing). Never assume its values apply to a different physical setup —
   if asked to debug OCR accuracy, first ask whether calibration has been
   re-run for the actual camera in question.
5. **Secrets live in `config.php` and the `settings` DB table, never in
   source.** `config.example.php` and `db/schema.sql`'s seed INSERT are the
   only places default/placeholder values belong. Do not hardcode a real
   API key, DB password, or WiFi credential anywhere in `firmware/`,
   `worker/`, or `web/` — this repo has already had one real incident of a
   credential sitting hardcoded in `firmware/CameraWebServer_legacy/` that
   went unnoticed for weeks because that file was informally marked
   "reference only" instead of actually being cleaned.
6. **The billing surcharge formula in `web/_lib.php`'s `eb_cost()` was
   reverse-engineered from real bills, not derived from a public tariff
   sheet.** If you're asked to "fix" or "simplify" it, don't — it's
   `round(10% × (rounded_energy_charge + fixed_charge))`, verified exact
   against four consecutive real bills. Subsidy and rebate are deliberately
   *not* computed (board-set, no formula); don't add a guess for them
   without being asked.

## Where things actually run

`worker.py` is meant to run as whatever OS user owns `storage/eb_images/`
and has DB access — commonly a low-privilege service account, not root and
not the same account that owns the source files. If that account can't
*create* new files in a directory it doesn't own (only write to existing
ones), any new log/state file a cron job writes to must be pre-created and
chowned correctly, or the cron will fail silently on a fresh deploy. This
bit a real deployment for 13 days before being noticed — check for it
when adding any new runtime file.

Do not write a shell pattern that matches `worker.py` for process
management (e.g. `pkill -f worker.py`) without excluding the invoking
shell's own command line — a naive pattern self-matches and kills the
wrong process. Use a pattern like `worker[.]py`.

## Testing

- `worker/test_guard.py` — the guard/decode regression suite. Run with the
  same Python environment `worker.py` runs under (needs `opencv-python-headless`,
  `numpy`, `pillow`, `PyMySQL` from `worker/requirements.txt`).
- No automated tests exist yet for `web/` — changes to `_lib.php`
  (especially `eb_cost()` or the cycle-boundary math) should be spot-checked
  against real bill numbers by hand; see the README's "Billing math" section
  for the verification method used so far.

## Known-incomplete areas (don't "fix" silently — flag instead)

- `web/dashboard.php`, `detail.php`, `health.php` have no authentication.
  This is a known, documented gap (see README), not an oversight to
  silently patch with an assumption about what auth scheme is wanted — ask
  first, since it changes the deployment story (env vars? IP allowlist?
  same key as settings.php?).
- Calendar-month billing-cycle baselines vs. the electricity board's actual
  (earlier, irregular) meter-read date causes a documented, bounded drift
  in monthly totals. This is understood and intentionally not "fixed" by
  guessing at the board's read-date pattern — see README.
