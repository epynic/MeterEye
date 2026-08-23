<?php
// Auto-lock each billing cycle's starting meter reading as its baseline.
// Run by cron (daily). Idempotent: only sets a baseline that isn't already set,
// so a manually-entered baseline (e.g. from a bill) is never overwritten.
// Baseline = the last reading just BEFORE the cycle start (the meter at boundary).
require_once __DIR__ . '/_lib.php';
$db = eb_db(); $cfg = eb_settings($db); $TZ = eb_tz($cfg);
$bday = (int)$cfg['billing_day'];
$cs = eb_cycle_start(new DateTime('now', $TZ), $bday, $TZ);   // current cycle start (IST)
$csUtc = (clone $cs)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$csDate = $cs->format('Y-m-d');

$done = [];
foreach ([$cfg['billing_metric'], $cfg['export_metric']] as $metric) {
    if (eb_baseline($db, $csDate, $metric) !== null) continue;     // already set (manual or prior run)
    $mq = $db->real_escape_string($metric);
    // last reading strictly before the cycle boundary = the meter value at cycle start
    $r = $db->query("SELECT value FROM readings WHERE metric='$mq' AND captured_at < '$csUtc'
                     ORDER BY captured_at DESC LIMIT 1");
    if (!$r || !($row = $r->fetch_assoc())) continue;              // no prior reading yet -> dashboard falls back to first-in-cycle
    $v = (float)$row['value'];
    $st = $db->prepare("INSERT INTO cycle_baseline (cycle_start,metric,value,note)
                        VALUES (?,?,?,'auto-snapshot at cycle start')
                        ON DUPLICATE KEY UPDATE value=value");
    $st->bind_param('ssd', $csDate, $metric, $v); $st->execute();
    $done[] = "$metric=$v";
}
echo date('c') . " cycle $csDate: " . ($done ? "locked ".implode(', ',$done) : "nothing to set") . "\n";
