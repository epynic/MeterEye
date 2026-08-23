<?php
require_once __DIR__.'/_lib.php';
$db=eb_db(); $cfg=eb_settings($db); $TZ=eb_tz($cfg);
date_default_timezone_set($cfg['tz']);
$IMP=$cfg['billing_metric']; $EXP=$cfg['export_metric']; $ccy=$cfg['currency'];
$hasTariff=eb_has_tariff($cfg);
$now=new DateTime('now',$TZ);
$curCs=eb_cycle_start($now,(int)$cfg['billing_day'],$TZ);

$cycles=array_reverse(eb_all_cycles($db,$cfg,$TZ));   // newest -> oldest
$prevCs=null;                                          // most recently CLOSED (previous) cycle
foreach($cycles as $c){ if($c->format('Y-m-d')!==$curCs->format('Y-m-d')){ $prevCs=$c; break; } }

$selStr=$_GET['c'] ?? ($prevCs ? $prevCs->format('Y-m-d') : $curCs->format('Y-m-d'));
$sel=$curCs; foreach($cycles as $c) if($c->format('Y-m-d')===$selStr){ $sel=$c; break; }

// ---- auth: admin_key if set, else the upload key (same pattern as settings.php) ----
$expected=$cfg['admin_key']!==''?$cfg['admin_key']:UPLOAD_KEY;
$key=$_REQUEST['key']??'';
$authed=$key!==''&&hash_equals($expected,$key);
$msg='';
if($authed && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='actual_bill'){
  $acs=$_POST['cycle_start']??$sel->format('Y-m-d');
  $amount=(float)($_POST['amount']??0);
  $unitsIn=trim($_POST['units']??''); $units=$unitsIn===''?null:(float)$unitsIn;
  $billDateIn=trim($_POST['bill_date']??''); $billDate=$billDateIn===''?null:$billDateIn;
  $note=trim($_POST['note']??'');
  if($amount>0){ eb_save_actual_bill($db,$acs,$amount,$units,$billDate,$note); $msg='Actual bill saved.'; }
}
$actual=eb_actual_bill($db,$sel->format('Y-m-d'));
$actualEdits=eb_actual_bill_edits($db,$sel->format('Y-m-d'));

[$impUse,$impAnchor,$impManual,$impFirst,$impLast,$impLastT,$cs,$ce]=eb_cycle_usage($db,$IMP,$sel,$cfg,$TZ);
[$expUse,$expAnchor,$expManual,$expFirst,$expLast]=eb_cycle_usage($db,$EXP,$sel,$cfg,$TZ);
$isCurrent=$cs->format('Y-m-d')===$curCs->format('Y-m-d');

$notes=[];
if($r=$db->query("SELECT metric,note FROM cycle_baseline WHERE cycle_start='".$db->real_escape_string($cs->format('Y-m-d'))."'"))
  while($x=$r->fetch_assoc()) $notes[$x['metric']]=$x['note'];

$hasData=$impUse!==null;
$imp=max(0,(float)($impUse??0)); $exp=max(0,(float)($expUse??0));
$net=max(0,$imp-$exp); $surplus=max(0,$exp-$imp);
$bd=($hasTariff && $hasData) ? eb_cost($net,$cfg) : null;
function bnz($v){ return rtrim(rtrim(number_format((float)$v,2),'0'),'.'); }
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>🧾 Bills</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{background:#0b0f14;color:#e6edf3;font:15px/1.5 system-ui,sans-serif;margin:0;padding:20px;max-width:760px;margin:auto}
a{color:#58a6ff;text-decoration:none}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
h1{font-size:20px;margin:0}
.back{color:#8b949e;font-size:13px}
label{display:block;font-size:12px;color:#8b949e;letter-spacing:.4px;margin-bottom:6px}
select{width:100%;padding:10px;background:#11161d;border:1px solid #2a3542;border-radius:10px;color:#e6edf3;font-size:15px;margin-bottom:14px}
input{width:100%;padding:9px;background:#0b0f14;border:1px solid #2a3542;border-radius:8px;color:#e6edf3;font-size:15px;margin-bottom:10px}
button{padding:10px 18px;background:#1f6feb;border:none;border-radius:8px;color:#fff;font-weight:700;font-size:14px;cursor:pointer}
th{text-align:left;color:#8b949e;font-size:11px;text-transform:uppercase;padding:6px 0;border-bottom:1px solid #233}
.muted{color:#8b949e;font-size:13px}
.vup{color:#f0a93b}.vdn{color:#3fb950}
.ok{background:#10331b;border:1px solid #1a7f37;color:#7ee2a0;padding:9px 11px;border-radius:8px;margin-bottom:10px;font-size:13px}
.hero{background:linear-gradient(135deg,#11202e,#0f1620);border:1px solid #233;border-radius:16px;padding:22px;margin-bottom:14px}
.hero .lbl{color:#8b949e;font-size:12px;letter-spacing:.5px}
.hero .bd{display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;margin:12px 0 2px}
.hero .bk{color:#8b949e;font-size:12px;margin-bottom:2px}
.hero .bv{font-size:30px;font-weight:800;line-height:1.05}
.hero .bv span{font-size:13px;color:#8b949e;font-weight:400}
.hero .bv.imp{color:#58a6ff}.hero .bv.exp{color:#f0a93b}.hero .bv.net{color:#e6edf3}
.hero .op{font-size:26px;color:#566;font-weight:700;padding-bottom:4px}
.hero .cost{font-size:26px;color:#3fb950;font-weight:800;margin-top:12px}
.badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.4px;padding:3px 9px;border-radius:20px;margin-left:8px;vertical-align:middle}
.badge.cur{background:#1f6feb;color:#fff}.badge.done{background:#233;color:#8b949e}
.card{background:#11161d;border:1px solid #233;border-radius:12px;padding:14px;margin-bottom:14px}
.card h2{font-size:13px;margin:0 0 10px;color:#8b949e;text-transform:uppercase;letter-spacing:.4px}
table{width:100%;border-collapse:collapse;font-size:14px}
td{padding:7px 0;border-bottom:1px solid #1c2530}
td:last-child{text-align:right;font-weight:600}
tr.tot td{border-bottom:none;border-top:1px solid #233;padding-top:10px;font-weight:800;color:#3fb950}
.note{background:#0f1c2a;border:1px solid #1d3a5a;color:#9dc4e8;border-radius:10px;padding:11px 13px;font-size:12.5px;margin-bottom:14px}
.warn{background:#2a210a;border:1px solid #5a4a14;color:#e8d59a;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px}
small{color:#8b949e}
.foot{color:#8b949e;font-size:12px;text-align:center;margin-top:18px}
@media(max-width:560px){body{padding:13px}.hero{padding:16px}.hero .bd{flex-direction:column;gap:0;align-items:stretch}.hero .op{display:none}
  .hero .bcol{display:flex;justify-content:space-between;align-items:baseline;padding:7px 0;border-bottom:1px solid #1c2530}.hero .bk{margin:0}.hero .bv{font-size:24px}}
</style></head><body>
<div class="top">
  <h1>🧾 Bills</h1>
  <a class="back" href="dashboard.php">← Dashboard</a>
</div>

<form method="get" id="cf">
  <label>BILLING CYCLE</label>
  <select name="c" onchange="document.getElementById('cf').submit()">
    <?php foreach($cycles as $c): $v=$c->format('Y-m-d'); $isCur=$v===$curCs->format('Y-m-d'); ?>
    <option value="<?=$v?>" <?=$v===$cs->format('Y-m-d')?'selected':''?>><?=$c->format('F Y')?><?=$isCur?' (in progress)':''?></option>
    <?php endforeach; ?>
  </select>
</form>

<?php if(!$hasData): ?>
<div class="note">No readings were recorded for this billing cycle.</div>
<?php else: ?>

<div class="hero">
  <div class="lbl">
    <?=$cs->format('d M Y')?> → <?=$isCurrent?'today':$ce->format('d M Y')?>
    <span class="badge <?=$isCurrent?'cur':'done'?>"><?=$isCurrent?'IN PROGRESS':'CLOSED'?></span>
  </div>
  <div class="bd">
    <div class="bcol"><div class="bk">⬇ Imported (grid)</div><div class="bv imp"><?=bnz($imp)?> <span>kWh</span></div></div>
    <div class="op">−</div>
    <div class="bcol"><div class="bk">⬆ Exported (solar)</div><div class="bv exp"><?=bnz($exp)?> <span>kWh</span></div></div>
    <div class="op">=</div>
    <div class="bcol"><div class="bk"><?=$surplus>0?'Net surplus':'Net (billed)'?></div>
      <div class="bv net" style="<?=$surplus>0?'color:#3fb950':''?>"><?=$surplus>0?bnz($surplus):bnz($net)?> <span>kWh</span></div></div>
  </div>
  <?php if($surplus>0): ?>
    <div class="cost"><span>in credit ✓</span> <small style="color:#8b949e;font-weight:400">solar &gt; usage · only fixed charges apply</small></div>
  <?php elseif($bd!==null): ?>
    <div class="cost"><?=eb_money($bd['total'],$ccy)?> <small style="color:#8b949e;font-weight:400">est. bill</small></div>
  <?php elseif(!$hasTariff): ?>
    <div class="cost" style="color:#8b949e;font-size:15px">Set a tariff to estimate the bill → <a href="settings.php">Settings</a></div>
  <?php endif; ?>
</div>

<?php if($bd!==null && $surplus<=0): ?>
<div class="card">
  <h2>Bill breakdown</h2>
  <table>
    <tr><td>Energy charge (slab, <?=bnz($net)?> kWh)</td><td><?=eb_money($bd['energy'],$ccy)?></td></tr>
    <tr><td>Regulatory surcharge</td><td><?=eb_money($bd['surcharge'],$ccy)?></td></tr>
    <tr><td>Fixed charge</td><td><?=eb_money($bd['fixed'],$ccy)?></td></tr>
    <tr><td>Subsidy</td><td>−<?=eb_money($bd['subsidy'],$ccy)?></td></tr>
    <tr class="tot"><td>Total</td><td><?=eb_money($bd['total'],$ccy)?></td></tr>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h2>Actual bill (what EB really charged)</h2>
  <p class="muted" style="margin:0 0 12px">Record the real invoice once it arrives, to compare against our estimate above.</p>
  <?php if($msg): ?><div class="ok"><?=htmlspecialchars($msg)?></div><?php endif; ?>

  <?php if($actual):
    $variance = ($bd!==null) ? round((float)$actual['amount']-$bd['total'],2) : null;
    $variancePct = ($variance!==null && $bd['total']>0) ? round(100*$variance/$bd['total'],1) : null;
  ?>
  <table>
    <tr><td>Amount billed</td><td><?=eb_money($actual['amount'],$ccy)?></td></tr>
    <?php if($actual['units']!==null): ?><tr><td>Units billed</td><td><?=bnz($actual['units'])?> kWh</td></tr><?php endif; ?>
    <?php if($actual['bill_date']): ?><tr><td>Bill date</td><td><?=htmlspecialchars($actual['bill_date'])?></td></tr><?php endif; ?>
    <?php if($variance!==null): ?>
    <tr class="tot"><td>Vs. our estimate</td>
      <td class="<?=$variance>0?'vup':'vdn'?>"><?=$variance>0?'+':''?><?=eb_money($variance,$ccy)?><?=$variancePct!==null?" ({$variancePct}%)":''?></td></tr>
    <?php endif; ?>
  </table>
  <?php if($actual['note']): ?><p style="margin:10px 0 0"><small>Note: <?=htmlspecialchars($actual['note'])?></small></p><?php endif; ?>
  <p style="margin:8px 0 0"><small>Last updated <?=htmlspecialchars($actual['updated_at'])?></small></p>
  <?php else: ?>
  <p class="muted">No actual bill recorded yet for this cycle.</p>
  <?php endif; ?>

  <?php if($authed): ?>
  <form method="post" action="?c=<?=$sel->format('Y-m-d')?>" style="margin-top:14px;border-top:1px solid #233;padding-top:14px">
    <input type="hidden" name="key" value="<?=htmlspecialchars($key)?>">
    <input type="hidden" name="action" value="actual_bill">
    <input type="hidden" name="cycle_start" value="<?=$sel->format('Y-m-d')?>">
    <label>Amount billed (<?=htmlspecialchars($ccy)?>)</label>
    <input name="amount" type="number" step="0.01" min="0.01" required value="<?=$actual?htmlspecialchars($actual['amount']):''?>">
    <label>Units billed (kWh, optional)</label>
    <input name="units" type="number" step="0.01" value="<?=($actual&&$actual['units']!==null)?htmlspecialchars($actual['units']):''?>">
    <label>Bill date (optional)</label>
    <input name="bill_date" type="date" value="<?=$actual?htmlspecialchars($actual['bill_date']??''):''?>">
    <label>Note (optional)</label>
    <input name="note" placeholder="e.g. AC left running all month" value="<?=$actual?htmlspecialchars($actual['note']??''):''?>">
    <button type="submit"><?=$actual?'Update':'Save'?> actual bill</button>
  </form>
  <?php else: ?>
  <form method="get" style="margin-top:14px;border-top:1px solid #233;padding-top:14px">
    <input type="hidden" name="c" value="<?=$sel->format('Y-m-d')?>">
    <label>Admin key</label>
    <input type="password" name="key" placeholder="enter key to edit">
    <button type="submit">Unlock to edit</button>
  </form>
  <?php endif; ?>

  <?php if($actualEdits): ?>
  <details style="margin-top:14px">
    <summary style="cursor:pointer;color:#8b949e;font-size:12.5px">Edit history (<?=count($actualEdits)?>)</summary>
    <table style="margin-top:8px">
      <tr><th>When</th><th>Old amount</th><th>New amount</th></tr>
      <?php foreach($actualEdits as $e): ?>
      <tr>
        <td><?=htmlspecialchars($e['edited_at'])?></td>
        <td><?=eb_money($e['old_amount'],$ccy)?></td>
        <td><?=eb_money($e['new_amount'],$ccy)?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </details>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Readings used</h2>
  <table>
    <tr><td>Start (import baseline)</td><td><?=bnz($impAnchor)?> kWh <?=$impManual?'· manual':'· auto (first reading seen)'?></td></tr>
    <tr><td>End (import, <?=$isCurrent?'latest':'last of cycle'?>)</td><td><?=bnz($impLast)?> kWh</td></tr>
    <tr><td>Start (export baseline)</td><td><?=bnz($expAnchor)?> kWh <?=$expManual?'· manual':'· auto (first reading seen)'?></td></tr>
    <tr><td>End (export, <?=$isCurrent?'latest':'last of cycle'?>)</td><td><?=bnz($expLast)?> kWh</td></tr>
  </table>
  <?php if(!empty($notes[$IMP]) || !empty($notes[$EXP])): ?>
    <p style="margin:10px 0 0"><small>Note: <?=htmlspecialchars($notes[$IMP] ?? $notes[$EXP])?></small></p>
  <?php endif; ?>
</div>

<?php if(!$impManual): ?>
<div class="warn">⚙ This cycle's start reading is auto-anchored to the first frame the camera captured (not a real bill reading) — usage may be understated. <a href="settings.php">Enter the actual meter reading for this cycle →</a></div>
<?php endif; ?>

<div class="note">ℹ This is a camera-read <b>estimate</b>, not the utility's official bill. If your actual EB bill reads differently, you can correct the start-of-cycle reading in <a href="settings.php">Settings</a> (it accepts any past cycle's date) — that will recompute this and all later cycles. See the reading table above for exactly what values were used.</div>

<?php endif; ?>

<div class="foot">
  <a href="dashboard.php">⚡ Dashboard</a> &nbsp;·&nbsp;
  <a href="detail.php">📊 Detail</a> &nbsp;·&nbsp;
  <a href="savings.php">☀ Savings</a> &nbsp;·&nbsp;
  <a href="health.php">💚 Health</a>
</div>
</body></html>
