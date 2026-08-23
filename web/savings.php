<?php
require_once __DIR__.'/_lib.php';
$db=eb_db(); $cfg=eb_settings($db); $TZ=eb_tz($cfg);
date_default_timezone_set($cfg['tz']);
$ccy=$cfg['currency']; $hasTariff=eb_has_tariff($cfg);
[$rows,$tot]=eb_savings($db,$cfg,$TZ);
$cur = $rows ? end($rows) : null;                                  // latest (current) cycle
$curPct = ($cur && $cur['without']>0) ? round(100*$cur['saved']/$cur['without']) : null;
$totPct = ($tot['without']>0) ? round(100*$tot['saved']/$tot['without']) : null;
$offset = ($cur && $cur['import']>0) ? round(100*$cur['export']/$cur['import']) : null;
function sv_nz($v){ return rtrim(rtrim(number_format((float)$v,2),'0'),'.'); }
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>☀ Solar Savings</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{background:#0b0f14;color:#e6edf3;font:15px/1.5 system-ui,sans-serif;margin:0;padding:20px;max-width:760px;margin:auto}
a{color:#58a6ff;text-decoration:none}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
h1{font-size:20px;margin:0}
.back{color:#8b949e;font-size:13px}
.hero{background:linear-gradient(135deg,#0f2417,#0f1620);border:1px solid #1d4a2e;border-radius:16px;padding:22px;margin-bottom:14px}
.hero .lbl{color:#3fb950;font-size:12px;letter-spacing:.5px;font-weight:700}
.hero .big{font-size:46px;font-weight:800;color:#3fb950;line-height:1.05;margin-top:6px}
.hero .big small{font-size:19px;color:#7ee29a;font-weight:700}
.hero .sub{color:#8b949e;font-size:14px;margin-top:10px}
.hero .sub b{color:#e6edf3;font-weight:700}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.tile{background:#11161d;border:1px solid #233;border-radius:12px;padding:13px}
.tile .k{color:#8b949e;font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.tile .v{font-size:22px;font-weight:700;margin-top:3px}.tile .u{font-size:12px;color:#8b949e}
.imp .v{color:#58a6ff}.exp .v{color:#f0a93b}.off .v{color:#3fb950}
.card{background:#11161d;border:1px solid #233;border-radius:12px;padding:14px;margin-bottom:14px}
.card h2{font-size:13px;margin:0 0 12px;color:#8b949e;text-transform:uppercase;letter-spacing:.4px}
.alltime{font-size:30px;font-weight:800;color:#3fb950;line-height:1.15}
.alltime small{font-size:14px;color:#8b949e;font-weight:400}
table{width:100%;border-collapse:collapse;font-size:14px;margin-top:14px}
th{text-align:right;color:#8b949e;font-size:11px;text-transform:uppercase;letter-spacing:.3px;padding:6px 8px;border-bottom:1px solid #233;font-weight:600}
th:first-child,td:first-child{text-align:left}
td{padding:8px;border-bottom:1px solid #161c24}
td.sav{color:#3fb950;font-weight:700}
tr:last-child td{border-bottom:none}
.note{background:#0f1c14;border:1px solid #1d4a2e;color:#9fd9b0;border-radius:10px;padding:11px 13px;font-size:12.5px;margin-bottom:14px}
.foot{color:#8b949e;font-size:12px;text-align:center;margin-top:18px}
small{color:#8b949e}
</style></head><body>
<div class="top">
  <h1>☀ Solar Savings</h1>
  <a class="back" href="dashboard.php">← Dashboard</a>
</div>

<?php if(!$hasTariff): ?>
<div class="note">⚙ No tariff configured — can't estimate the bill. <a href="settings.php">Set tariff slabs →</a></div>
<?php elseif(!$cur): ?>
<div class="note">No readings captured yet — nothing to estimate.</div>
<?php else: ?>

<div class="hero">
  <div class="lbl">SAVED THIS CYCLE · <?=$cur['cs']->format('d M')?> → <?=$cur['ce']->format('d M')?></div>
  <div class="big"><?=eb_money($cur['saved'],$ccy)?> <?php if($curPct!==null):?><small><?=$curPct?>% lower bill</small><?php endif;?></div>
  <div class="sub">Without solar your bill would be <b><?=eb_money($cur['without'],$ccy)?></b> — instead you pay <b><?=eb_money($cur['with'],$ccy)?></b>.</div>
</div>

<div class="grid">
  <div class="tile imp"><div class="k">Imported (grid)</div><div class="v"><?=sv_nz($cur['import'])?> <span class="u">kWh</span></div></div>
  <div class="tile exp"><div class="k">Exported (solar)</div><div class="v"><?=sv_nz($cur['export'])?> <span class="u">kWh</span></div></div>
  <div class="tile off"><div class="k">Solar offset</div><div class="v"><?=$offset!==null?$offset:'—'?><span class="u">%</span></div></div>
</div>

<div class="card">
  <h2>All-time savings</h2>
  <div class="alltime"><?=eb_money($tot['saved'],$ccy)?> saved
    <small>since <?=$rows[0]['cs']->format('d M Y')?><?php if($totPct!==null):?> · <?=$totPct?>% lower bills<?php endif;?></small></div>
  <?php if(count($rows)>1): ?>
  <table>
    <tr><th>Cycle</th><th>Import</th><th>Export</th><th>Saved</th></tr>
    <?php foreach(array_reverse($rows) as $r): ?>
    <tr>
      <td><?=$r['cs']->format('M Y')?></td>
      <td style="text-align:right"><?=sv_nz($r['import'])?></td>
      <td style="text-align:right"><?=sv_nz($r['export'])?></td>
      <td class="sav" style="text-align:right"><?=eb_money($r['saved'],$ccy)?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="note">☀ This is the saving from <b>exporting</b> surplus solar back to the grid — the part your meter can prove. Your real benefit is higher: solar that powers the house directly never passes through the import meter, so it isn't counted here. Treat this as a conservative floor.</div>

<?php endif; ?>

<div class="foot">
  <a href="dashboard.php">⚡ Dashboard</a> &nbsp;·&nbsp;
  <a href="detail.php">📊 Detail</a> &nbsp;·&nbsp;
  <a href="health.php">💚 Health</a>
</div>
</body></html>
