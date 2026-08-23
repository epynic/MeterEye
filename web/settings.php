<?php
require_once __DIR__.'/_lib.php';
$db=eb_db(); $cfg=eb_settings($db); $TZ=eb_tz($cfg);
date_default_timezone_set($cfg['tz']);

// ---- auth: admin_key setting if set, else the upload key ----
$expected = $cfg['admin_key'] !== '' ? $cfg['admin_key'] : UPLOAD_KEY;
$key = $_REQUEST['key'] ?? '';
$authed = $key !== '' && hash_equals($expected, $key);

$msg='';
if ($authed && $_SERVER['REQUEST_METHOD']==='POST') {
  $act=$_POST['action']??'';
  if ($act==='settings') {
    $allow=['billing_day','rate_per_kwh','fixed_charge','currency','billing_metric','export_metric','tz','tariff_slabs','surcharge_pct','subsidy'];
    $st=$db->prepare("UPDATE settings SET v=? WHERE k=?");
    foreach($allow as $k){ if(isset($_POST[$k])){ $v=trim($_POST[$k]); $st->bind_param('ss',$v,$k); $st->execute(); } }
    $msg='Settings saved.';
    // admin key handled separately: only change if a new value is given (don't blank it)
    $newkey=trim($_POST['admin_key']??'');
    if($newkey!==''){ $kk='admin_key'; $st->bind_param('ss',$newkey,$kk); $st->execute();
      $msg.=' Admin key changed.'; $key=$newkey; $expected=$newkey; }
  } elseif ($act==='baseline') {
    $cstart=$_POST['cycle_start']; $note=trim($_POST['note']??'');
    $st=$db->prepare("INSERT INTO cycle_baseline (cycle_start,metric,value,note) VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE value=VALUES(value),note=VALUES(note)");
    foreach(['import'=>$cfg['billing_metric'],'export'=>$cfg['export_metric']] as $f=>$metric){
      if(isset($_POST[$f]) && $_POST[$f]!==''){ $val=(float)$_POST[$f];
        $st->bind_param('ssds',$cstart,$metric,$val,$note); $st->execute(); }
    }
    $msg='Baseline saved for cycle starting '.$cstart.'.';
  }
  $cfg=eb_settings($db); // reload
}

$now=new DateTime('now',$TZ);
$curCycle=eb_cycle_start($now,(int)$cfg['billing_day'],$TZ)->format('Y-m-d');
$baselines=[]; if($r=$db->query("SELECT cycle_start,metric,value,note FROM cycle_baseline ORDER BY cycle_start DESC,metric")) while($x=$r->fetch_assoc()) $baselines[]=$x;
function h($s){return htmlspecialchars((string)$s);}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>EB — Settings</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}
body{background:#0b0f14;color:#e6edf3;font:15px/1.5 system-ui,sans-serif;margin:0;padding:20px;max-width:620px;margin:auto}
a{color:#58a6ff;text-decoration:none}h1{font-size:20px}h2{font-size:15px;color:#8b949e;margin-top:24px}
.card{background:#11161d;border:1px solid #233;border-radius:12px;padding:16px;margin-bottom:14px}
label{display:block;font-size:13px;color:#8b949e;margin:10px 0 3px}
input,select{width:100%;padding:9px;background:#0b0f14;border:1px solid #2a3542;border-radius:8px;color:#e6edf3;font-size:15px}
button{margin-top:14px;padding:10px 18px;background:#1f6feb;border:none;border-radius:8px;color:#fff;font-weight:700;font-size:15px;cursor:pointer}
.ok{background:#10331b;border:1px solid #1a7f37;color:#7ee2a0;padding:10px;border-radius:8px;margin-bottom:14px}
.row{display:flex;gap:10px}.row>div{flex:1}
small{color:#8b949e}table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:5px 8px;border-bottom:1px solid #1c2530;text-align:left}
</style></head><body>
<p><a href="dashboard.php">← Dashboard</a></p>
<h1>⚙ Settings</h1>

<?php if(!$authed): ?>
  <div class="card">
    <form method="get">
      <label>Admin key</label>
      <input type="password" name="key" autofocus placeholder="enter key to edit settings">
      <button>Unlock</button>
      <p><small>Uses your upload key unless an <code>admin_key</code> is set.</small></p>
    </form>
  </div>
<?php else: ?>
  <?php if($msg): ?><div class="ok"><?=h($msg)?></div><?php endif; ?>

  <h2>Billing baseline — feed in last bill's meter reading</h2>
  <div class="card">
    <p><small>Enter the meter reading at the start of a billing cycle (e.g. the value on your last bill).
      Consumption for that cycle is then <b>current reading − this baseline</b>.</small></p>
    <form method="post">
      <input type="hidden" name="key" value="<?=h($key)?>"><input type="hidden" name="action" value="baseline">
      <label>Cycle start date</label>
      <input name="cycle_start" type="date" value="<?=h($curCycle)?>">
      <div class="row">
        <div><label>Import reading (<?=h($cfg['billing_metric'])?>) kWh</label>
          <input name="import" type="number" step="0.01" placeholder="e.g. 869.00"></div>
        <div><label>Export reading (<?=h($cfg['export_metric'])?>) kWh</label>
          <input name="export" type="number" step="0.01" placeholder="e.g. 545.00"></div>
      </div>
      <label>Note (optional)</label><input name="note" placeholder="e.g. May bill, paid 1 Jun">
      <button>Save baseline</button>
    </form>
  </div>

  <?php if($baselines): ?>
  <div class="card"><h2 style="margin-top:0">Saved baselines</h2>
  <table><tr><th>Cycle start</th><th>Metric</th><th>Reading</th><th>Note</th></tr>
  <?php foreach($baselines as $b): ?>
   <tr><td><?=h($b['cycle_start'])?></td><td><?=h($b['metric'])?></td><td><?=h($b['value'])?></td><td><?=h($b['note'])?></td></tr>
  <?php endforeach; ?></table></div>
  <?php endif; ?>

  <h2>Tariff & cycle</h2>
  <div class="card">
    <form method="post">
      <input type="hidden" name="key" value="<?=h($key)?>"><input type="hidden" name="action" value="settings">
      <div class="row">
        <div><label>Billing day of month</label><input name="billing_day" type="number" min="1" max="28" value="<?=h($cfg['billing_day'])?>"></div>
        <div><label>Currency</label><input name="currency" value="<?=h($cfg['currency'])?>"></div>
      </div>
      <label>Slab tariff <small>(size:rate, … last <code>*:rate</code> = remainder; overrides flat rate)</small></label>
      <input name="tariff_slabs" value="<?=h($cfg['tariff_slabs']??'')?>" placeholder="100:2.90,100:4.20,100:6.20,100:7.70,*:7.90">
      <div class="row">
        <div><label>Flat rate per kWh <small>(if no slabs)</small></label><input name="rate_per_kwh" type="number" step="0.01" value="<?=h($cfg['rate_per_kwh'])?>"></div>
        <div><label>Fixed monthly charge</label><input name="fixed_charge" type="number" step="0.01" value="<?=h($cfg['fixed_charge'])?>"></div>
      </div>
      <div class="row">
        <div><label>Regulatory surcharge %</label><input name="surcharge_pct" type="number" step="0.1" value="<?=h($cfg['surcharge_pct']??'0')?>"></div>
        <div><label>Monthly subsidy (−)</label><input name="subsidy" type="number" step="0.01" value="<?=h($cfg['subsidy']??'0')?>"></div>
      </div>
      <div class="row">
        <div><label>Billed import register</label><input name="billing_metric" value="<?=h($cfg['billing_metric'])?>"></div>
        <div><label>Solar export register</label><input name="export_metric" value="<?=h($cfg['export_metric'])?>"></div>
      </div>
      <label>Change admin key <small>(this settings password; blank = keep current)</small></label>
      <input name="admin_key" type="text" autocomplete="off" placeholder="e.g. meter4321">
      <button>Save settings</button>
    </form>
  </div>
<?php endif; ?>
</body></html>
