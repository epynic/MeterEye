<?php
// Health monitor — run by cron every few minutes. Alerts (once) on failure and on recovery.
// Channels: Telegram (settings telegram_token + telegram_chat) and/or email (settings alert_email).
require_once __DIR__ . '/_lib.php';
$db = eb_db(); $cfg = eb_settings($db); $TZ = eb_tz($cfg);
date_default_timezone_set($cfg['tz']);

$gapMin   = (int)($cfg['alert_gap_min'] ?? 20);     // no frames for this many min = camera/worker down
$token    = $cfg['telegram_token'] ?? '';
$chat     = $cfg['telegram_chat']  ?? '';
$email    = $cfg['alert_email']    ?? '';
$stateF   = '/var/www/prasanha.com/worker/alert_state.json';
$state    = file_exists($stateF) ? (json_decode(file_get_contents($stateF), true) ?: []) : [];

$fails = [];   // key => human message

// 1) camera/worker freshness (from high-water mark)
[$camT, $camAge, $online] = eb_liveness($db, $TZ);
if ($camT === null) {
    $fails['camera'] = "No processed frames on record.";
} elseif ($camAge !== null && $camAge > $gapMin) {
    $fails['camera'] = "No new camera frames for {$camAge} min (last {$camT->format('d M H:i')} IST). Camera or worker may be down.";
}

// 2) disk space
$freePct = round(disk_free_space('/') / disk_total_space('/') * 100);
if ($freePct < 10) $fails['disk'] = "Disk space low: {$freePct}% free.";

// 3) OCR billing-register health (from ocr_health.json, refreshed hourly by ocr_health.py).
// Only alerts when a billing register is stuck poisoned and the consensus self-heal could NOT
// fix it (status 'poison_high') — i.e. it needs a human. A successful auto-heal is silent (logged
// to ocr_heal.log + shown on health.php). Raw OCR misreads alone are NOT an alert: the guard makes
// them harmless, so ~32% raw error with clean registers is normal.
$ocrF = '/var/www/prasanha.com/worker/ocr_health.json';
if (file_exists($ocrF) && ($oh = json_decode(file_get_contents($ocrF), true))) {
    foreach (['ip_cu_fd'=>'import', 'ep_cu_fd'=>'export'] as $m => $lbl) {
        $r = $oh['metrics'][$m] ?? null;
        if ($r && ($r['status'] ?? '') === 'poison_high')
            $fails["ocr_$m"] = "Billing register '$lbl' looks poisoned: stored {$r['stored_register']} vs OCR consensus {$r['consensus_register']} — auto-heal couldn't correct it. Check the meter/OCR.";
    }
}

// 4) no readings landing (worker running but storing nothing for a long time is informational only)

// ---- diff against previous state, notify on transitions ----
function notify($title, $body, $token, $chat, $email) {
    $msg = "$title\n$body";
    if ($token && $chat) {
        $url = "https://api.telegram.org/bot$token/sendMessage";
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_POST=>1, CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10,
            CURLOPT_POSTFIELDS=>['chat_id'=>$chat, 'text'=>$msg]]);
        curl_exec($c); curl_close($c);
    }
    if ($email) @mail($email, "EB Meter: $title", $msg, "From: eb@prasanha.com");
}

$prev = $state['fails'] ?? [];
$nowKeys = array_keys($fails);

// new failures
foreach ($fails as $k => $m) if (!in_array($k, $prev))
    notify("🔴 EB alert: $k", $m . "\n— prasanha.com/eb/dashboard.php", $token, $chat, $email);
// recoveries
foreach ($prev as $k) if (!in_array($k, $nowKeys))
    notify("✅ EB recovered: $k", "$k is back to normal.", $token, $chat, $email);

$state['fails'] = $nowKeys;
$state['checked'] = (new DateTime('now',$TZ))->format('c');
file_put_contents($stateF, json_encode($state));

echo date('c') . " checks: " . ($fails ? "FAIL ".implode(',',$nowKeys) : "all OK")
   . " | camAge=" . ($camAge??'?') . "m disk={$freePct}% | telegram=" . ($token?'on':'off') . " email=" . ($email?'on':'off') . "\n";
