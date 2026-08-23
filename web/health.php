<?php
// Live system health status board. Open anytime — auto-refreshes.
require_once __DIR__ . '/_lib.php';
$db = eb_db(); $cfg = eb_settings($db); $TZ = eb_tz($cfg);
date_default_timezone_set($cfg['tz']);
$now = new DateTime('now', $TZ);

function ago($dt,$now){ if(!$dt) return '—'; $s=$now->getTimestamp()-$dt->getTimestamp();
  if($s<90) return "{$s}s ago"; if($s<5400) return round($s/60)."m ago"; if($s<172800) return round($s/3600)."h ago"; return round($s/86400)."d ago"; }
function fmtmtime($f,$TZ){ if(!file_exists($f)) return null; $d=new DateTime('@'.filemtime($f)); $d->setTimezone($TZ); return $d; }

$checks = [];

// camera / worker freshness
[$camT,$camAge,$online] = eb_liveness($db,$TZ);
$checks[] = ['Camera + OCR worker', $online?'ok':'bad',
  $camT? "last frame {$camT->format('d M H:i')} · ".ago($camT,$now) : 'no frames',
  $online? 'receiving frames' : 'NO frames for '.($camAge??'?').' min — camera/worker may be down'];

// worker cron heartbeat (worker.log mtime)
$wl = fmtmtime(WORKER_DIR . 'worker.log',$TZ);
$wlOk = $wl && ($now->getTimestamp()-$wl->getTimestamp() < 180);
$checks[] = ['Worker cron (1 min)', $wlOk?'ok':'warn', 'last run '.ago($wl,$now), $wlOk?'running on schedule':'no run in 3+ min'];

// monitor cron heartbeat
$ml = fmtmtime(WORKER_DIR . 'monitor.log',$TZ);
$mlOk = $ml && ($now->getTimestamp()-$ml->getTimestamp() < 600);
$checks[] = ['Monitor cron (5 min)', $mlOk?'ok':'warn', 'last run '.ago($ml,$now), $mlOk?'checking health':'no run in 10+ min'];

// database
$tot = (int)$db->query("SELECT COUNT(*) c FROM readings")->fetch_assoc()['c'];
$lastR = $db->query("SELECT MAX(captured_at) m FROM readings")->fetch_assoc()['m'];
$lastRd = $lastR? eb_ist($lastR,$TZ):null;
$checks[] = ['Database', $tot>0?'ok':'bad', "$tot readings · last value ".ago($lastRd,$now), $tot>0?'storing readings':'empty'];

// disk
$freePct = round(disk_free_space('/')/disk_total_space('/')*100);
$checks[] = ['Disk space', $freePct>15?'ok':($freePct>8?'warn':'bad'), "{$freePct}% free", $freePct>15?'plenty':'getting low'];

// images + cleanup — show OLDEST image age so the rolling 2-day window is visible and a stalled
// cleanup is obvious (glob() returns names sorted ascending, so $imgs[0] is the oldest — cheap).
$imgs = glob(IMAGES_DIR . '*.jpg');
$nimg = count($imgs);
$oldest = $nimg ? eb_ist(date('Y-m-d H:i:s', filemtime($imgs[0])), $TZ) : null;
$oldAge = $oldest ? ($now->getTimestamp()-$oldest->getTimestamp())/86400 : 0;   // days
$imgSt = $oldAge > 2.6 ? 'warn' : 'ok';   // 2-day retention + hourly cleanup -> should stay under ~2.05d
$checks[] = ['Images on disk', $imgSt, "$nimg files · oldest ".number_format($oldAge,1)."d",
             $oldAge > 2.6 ? 'cleanup may be stalled (retention is 2 days)' : 'auto-cleaned hourly · 2-day retention'];

// OCR accuracy + register self-heal (ocr_health.json, refreshed hourly by ocr_health.py)
$ocrF = WORKER_DIR . 'ocr_health.json';
if (file_exists($ocrF) && ($oh = json_decode(file_get_contents($ocrF), true))) {
  $worst = 'ok'; $bits = [];
  foreach (['ip_cu_fd'=>'import','ep_cu_fd'=>'export'] as $m=>$lbl) {
    $r = $oh['metrics'][$m] ?? null;
    if (!$r || empty($r['frames'])) continue;
    $bits[] = "$lbl ".number_format((float)$r['accuracy_pct'],0)."%".($r['status']!=='ok'?" ({$r['status']})":'');
    if ($r['status']==='poison_high') $worst='bad';
    elseif (in_array($r['status'],['healed','behind']) && $worst!=='bad') $worst='warn';
  }
  $ocrAge = !empty($oh['checked_utc']) ? eb_ist($oh['checked_utc'],$TZ) : null;
  $det = ($worst==='ok' ? 'billing registers clean · ' : '').'raw-decode accuracy: '.($bits?implode(' · ',$bits):'no recent energy frames');
  $checks[] = ['OCR self-heal & accuracy', $worst, 'checked '.ago($ocrAge,$now), $det];
}

$allgood = !in_array('bad', array_column($checks,1));
$anywarn = in_array('warn', array_column($checks,1));
$overall = $allgood ? ($anywarn?'warn':'ok') : 'bad';
$OT = ['ok'=>'ALL SYSTEMS OK','warn'=>'MINOR WARNINGS','bad'=>'⚠ ATTENTION NEEDED'];
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>EB — System Health</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}
body{background:#0b0f14;color:#e6edf3;font:15px/1.5 system-ui,sans-serif;margin:0;padding:18px;max-width:640px;margin:auto}
a{color:#58a6ff;text-decoration:none}
.banner{border-radius:14px;padding:18px;text-align:center;font-size:22px;font-weight:800;margin-bottom:16px}
.ok{background:#10331b;color:#3fb950;border:1px solid #1a7f37}
.warn{background:#2a210a;color:#e8c468;border:1px solid #6a5414}
.bad{background:#3a0f15;color:#ff7b72;border:1px solid #a40e26}
.card{display:flex;align-items:center;gap:12px;background:#11161d;border:1px solid #233;border-radius:11px;padding:12px 14px;margin-bottom:9px}
.dot{width:12px;height:12px;border-radius:50%;flex:0 0 auto}
.dot.ok{background:#3fb950}.dot.warn{background:#e8c468}.dot.bad{background:#ff7b72}
.nm{font-weight:700}.det{color:#8b949e;font-size:13px}
.r{margin-left:auto;text-align:right;color:#8b949e;font-size:12px}
.foot{color:#8b949e;font-size:12px;text-align:center;margin-top:16px}
</style></head><body>
<div class="banner <?=$overall?>"><?=$OT[$overall]?></div>
<?php foreach($checks as [$name,$st,$right,$det]): ?>
  <div class="card">
    <span class="dot <?=$st?>"></span>
    <div><div class="nm"><?=$name?></div><div class="det"><?=htmlspecialchars($det)?></div></div>
    <div class="r"><?=htmlspecialchars($right)?></div>
  </div>
<?php endforeach; ?>
<div class="foot">
  auto-refreshes every 60s · <?=$now->format('D d M, H:i:s')?> IST<br>
  <a href="dashboard.php">⚡ Dashboard</a> · <a href="detail.php">📊 Detail</a> · <a href="settings.php">⚙ Settings</a>
</div>
</body></html>
