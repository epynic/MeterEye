<?php
require_once __DIR__.'/_lib.php';
$db=eb_db(); $cfg=eb_settings($db); $TZ=eb_tz($cfg);
date_default_timezone_set($cfg['tz']);
$IMP=$cfg['billing_metric']; $EXP=$cfg['export_metric']; $ccy=$cfg['currency'];
$bday=(int)$cfg['billing_day'];
$now=new DateTime('now',$TZ);

// ---- period filter ----
$period=$_GET['p'] ?? 'cycle';
$labels=['cycle'=>'This cycle','7d'=>'7 days','30d'=>'30 days','all'=>'All'];
if($period==='7d') $pstart=(clone $now)->modify('-6 days')->setTime(0,0);
elseif($period==='30d') $pstart=(clone $now)->modify('-29 days')->setTime(0,0);
elseif($period==='all') $pstart=new DateTime('2000-01-01',$TZ);
else { $period='cycle'; $pstart=eb_cycle_start($now,$bday,$TZ); }
$pstartD=$pstart->format('Y-m-d');

// ---- daily deltas from cumulative series ----
function daily_deltas($series){
  $byDay=[]; foreach($series as [$t,$v]) $byDay[$t->format('Y-m-d')]=$v;  // last value each day
  ksort($byDay); $out=[]; $prev=null;
  foreach($byDay as $d=>$v){ if($prev!==null) $out[$d]=round($v-$prev,2); $prev=$v; }
  return $out;
}
$impS=eb_series($db,$IMP,$TZ); $expS=eb_series($db,$EXP,$TZ);
$impDaily=daily_deltas($impS); $expDaily=daily_deltas($expS);
// restrict to period
$days=[]; foreach($impDaily as $d=>$v) if($d>=$pstartD) $days[$d]=1;
foreach($expDaily as $d=>$v) if($d>=$pstartD) $days[$d]=1;
$days=array_keys($days); sort($days);
$impBars=array_map(fn($d)=>$impDaily[$d]??0,$days);
$expBars=array_map(fn($d)=>$expDaily[$d]??0,$days);
$netBars=array_map(fn($i)=>round($impBars[$i]-$expBars[$i],2),array_keys($days));

// ---- period totals ----
$measuredImp=round(array_sum($impBars),2);
$measuredExp=round(array_sum($expBars),2);
$nDays=max(1,count($days));
if($period==='cycle'){
  // align with the bill: cycle total = current reading − cycle baseline (same as summary)
  $cu=eb_cycle_usage($db,$IMP,$now,$cfg,$TZ); $ceu=eb_cycle_usage($db,$EXP,$now,$cfg,$TZ);
  $tImp=$cu[0]??$measuredImp; $tExp=$ceu[0]??$measuredExp;
  $preGap=round($tImp-$measuredImp,2);   // used before monitoring started this cycle
} else {
  $tImp=$measuredImp; $tExp=$measuredExp; $preGap=0;
}
$tNet=round($tImp-$tExp,2);
$cost=eb_cost(max(0,$tNet),$cfg)['total'];
$avg=round($measuredImp/$nDays,1);       // typical daily usage from measured days
$peak=$impBars?max($impBars):0;

// ---- per billing cycle (month on month) ----
$cyc=[]; $prev=null;
foreach($impS as [$t,$v]){ $cs=eb_cycle_start($t,$bday,$TZ); $k=$cs->format('Y-m-d');
  if(!isset($cyc[$k])) $cyc[$k]=['s'=>$cs,'anchor'=>$prev,'last'=>$v]; $cyc[$k]['last']=$v; $prev=$v; }
$cycChart=[]; foreach($cyc as $k=>$c){ $base=eb_baseline($db,$k,$IMP); if($base===null)$base=$c['anchor']??$c['last']; $cycChart[$c['s']->format('M Y')]=round($c['last']-$base,2); }

// ---- voltage/current per phase: averaged into time buckets (hourly, or daily for long windows) ----
$bucket = in_array($period,['30d','all']) ? 'day' : 'hour';
function phase_series($db,$metric,$ph,$pstartD,$TZ,$bucket){
  $mq=$db->real_escape_string($metric);
  $agg=[]; // key => [sum,count]
  $r=$db->query("SELECT captured_at,value FROM readings WHERE metric='$mq' AND phase='$ph' AND captured_at>='".$db->real_escape_string($pstartD)." 00:00:00' ORDER BY captured_at");
  while($r&&$x=$r->fetch_assoc()){
    $t=eb_ist($x['captured_at'],$TZ);
    $key=$t->format($bucket==='day' ? 'Y-m-d\T00:00:00P' : 'Y-m-d\TH:00:00P');
    if(!isset($agg[$key])) $agg[$key]=[0,0];
    $agg[$key][0]+=(float)$x['value']; $agg[$key][1]++;
  }
  $out=[]; foreach($agg as $k=>$v) $out[]=[$k, round($v[0]/$v[1],1)];
  return $out;
}
$volt=['R'=>phase_series($db,'voltage','R',$pstartD,$TZ,$bucket),'Y'=>phase_series($db,'voltage','Y',$pstartD,$TZ,$bucket),'B'=>phase_series($db,'voltage','B',$pstartD,$TZ,$bucket)];
$haveVolt=$volt['R']||$volt['Y']||$volt['B'];
$curr=['R'=>phase_series($db,'current','R',$pstartD,$TZ,$bucket),'Y'=>phase_series($db,'current','Y',$pstartD,$TZ,$bucket),'B'=>phase_series($db,'current','B',$pstartD,$TZ,$bucket)];
$haveCurr=$curr['R']||$curr['Y']||$curr['B'];
$bucketLbl = $bucket==='day' ? 'daily avg' : 'hourly avg';
$dlabels=array_map(fn($d)=>substr($d,5),$days);  // MM-DD
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>EB — Analytics</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script src="https://cdn.jsdelivr.net/npm/luxon@3"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1"></script>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}
body{background:#0b0f14;color:#e6edf3;font:14px/1.5 system-ui,sans-serif;margin:0;padding:18px;max-width:880px;margin:auto}
a{color:#58a6ff;text-decoration:none}h1{font-size:19px;margin:6px 0 12px}
.tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:16px}
.tab{padding:6px 14px;border:1px solid #30363d;border-radius:18px;color:#c9d1d9;font-size:13px}
.tab.on{background:#1f6feb;border-color:#1f6feb;color:#fff}
.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:18px}
@media(min-width:620px){.stats{grid-template-columns:repeat(4,1fr)}}
.stat{background:#11161d;border:1px solid #233;border-radius:11px;padding:12px}
.stat .k{color:#8b949e;font-size:11px;text-transform:uppercase}
.stat .v{font-size:23px;font-weight:800;margin-top:3px}.stat .u{font-size:12px;color:#8b949e}
.imp{color:#58a6ff}.exp{color:#f0a93b}.net{color:#e6edf3}.cost{color:#3fb950}
.card{background:#11161d;border:1px solid #233;border-radius:12px;padding:14px;margin-bottom:16px;scroll-margin-top:14px}
.card.flash{animation:fl 2.2s ease}
@keyframes fl{0%,55%{box-shadow:0 0 0 2px #1f6feb;border-color:#1f6feb}100%{box-shadow:none}}
.card h2{font-size:13px;margin:0 0 10px;color:#8b949e;text-transform:uppercase}
.muted{color:#8b949e;font-size:12px}
.ch{position:relative;height:230px}
details summary{cursor:pointer;color:#8b949e}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}td,th{padding:4px 8px;border-bottom:1px solid #1c2530;text-align:left}
@media(max-width:560px){
  body{padding:12px}
  h1{font-size:18px}
  .tab{padding:8px 14px;font-size:14px}
  .stats{gap:8px}.stat{padding:10px}.stat .v{font-size:20px}
  .ch{height:200px}
  details.card{overflow-x:auto}
}
</style></head><body>
<p><a href="dashboard.php">← Summary</a> · <a href="health.php">💚 Health</a> · <a href="settings.php">⚙ Settings</a> · <span class="muted">all times IST</span></p>
<h1>📊 Analytics</h1>
<div class="tabs">
<?php foreach($labels as $k=>$l): ?><a class="tab <?=$k===$period?'on':''?>" href="?p=<?=$k?>"><?=$l?></a><?php endforeach; ?>
</div>

<div class="stats">
  <div class="stat"><div class="k">Imported (grid)</div><div class="v imp"><?=$tImp?> <span class="u">kWh</span></div></div>
  <div class="stat"><div class="k">☀ Solar export</div><div class="v exp"><?=$tExp?> <span class="u">kWh</span></div></div>
  <div class="stat"><div class="k">Net <?=$tNet<0?'surplus':'(billed)'?></div><div class="v net"><?=$tNet<0?-$tNet:$tNet?> <span class="u">kWh</span></div></div>
  <div class="stat"><div class="k">Est. cost</div><div class="v cost"><?=$tNet<0?'₹0':eb_money($cost,$ccy)?></div></div>
  <div class="stat"><div class="k">Avg / day</div><div class="v"><?=$avg?> <span class="u">kWh</span></div></div>
  <div class="stat"><div class="k">Peak day</div><div class="v"><?=$peak?> <span class="u">kWh</span></div></div>
  <div class="stat"><div class="k">Days</div><div class="v"><?=count($days)?></div></div>
  <div class="stat"><div class="k">Period</div><div class="v" style="font-size:16px"><?=$labels[$period]?></div></div>
</div>

<?php if($period==='cycle' && $preGap>1): ?>
<div class="card" style="background:#1c1605;border-color:#5a4a14;color:#e8d59a;font-size:13px">
  ℹ︎ Totals above are measured from your bill baseline (counted from the last bill reading).
  ≈ <b><?=$preGap?> kWh</b> was used before the camera started monitoring this cycle, so the
  daily bars below (which only cover monitored days) won't add up to the cycle total yet.
  From next cycle the camera covers the full month and they'll match.
</div>
<?php endif; ?>

<div class="card" id="c-daily"><h2>Daily energy — grid import vs solar export (kWh)</h2><div class="ch"><canvas id="daily"></canvas></div></div>
<div class="card" id="c-net"><h2>Net daily (what you're billed, kWh)</h2><div class="ch"><canvas id="net"></canvas></div></div>
<div class="card" id="c-cyc"><h2>Per billing cycle (month on month, kWh)</h2><div class="ch"><canvas id="cyc"></canvas></div></div>
<?php if($haveVolt): ?><div class="card" id="c-volt"><h2>Voltage by phase (V) — <?=$bucketLbl?></h2><div class="ch"><canvas id="volt"></canvas></div></div><?php endif; ?>
<?php if($haveCurr): ?><div class="card" id="c-current"><h2>Current by phase (A) — <?=$bucketLbl?></h2><div class="ch"><canvas id="curr"></canvas></div></div>
<?php else: ?><div class="card" id="c-current"><h2>Current by phase (A)</h2><p class="muted">No current readings in this period yet — they’ll appear as data builds up.</p></div><?php endif; ?>

<details class="card"><summary>Raw readings (latest 40)</summary>
<table><tr><th>Time IST</th><th>Metric</th><th>Val</th><th>Ph</th></tr>
<?php $r=$db->query("SELECT captured_at,metric,value,phase FROM readings ORDER BY id DESC LIMIT 40");
while($r&&$x=$r->fetch_assoc()): ?><tr><td><?=eb_ist($x['captured_at'],$TZ)->format('d M H:i')?></td><td><?=$x['metric']?></td><td><?=rtrim(rtrim(number_format($x['value'],2),'0'),'.')?></td><td><?=$x['phase']?:''?></td></tr><?php endwhile; ?>
</table></details>

<script>
Chart.defaults.color='#8b949e';
Chart.defaults.font.family='system-ui,-apple-system,sans-serif';
const dl=<?=json_encode($dlabels)?>;
const GRID={color:'rgba(255,255,255,.06)'};
const barOpt=(lg)=>({maintainAspectRatio:false,plugins:{legend:{display:lg,labels:{usePointStyle:true,boxWidth:8,padding:14}},tooltip:{mode:'index',intersect:false}},
  scales:{x:{grid:{display:false},ticks:{autoSkip:true,maxTicksLimit:8,maxRotation:0}},y:{grid:GRID,border:{display:false},beginAtZero:true}}});
new Chart(document.getElementById('daily'),{type:'bar',data:{labels:dl,datasets:[
  {label:'Import',data:<?=json_encode($impBars)?>,backgroundColor:'#58a6ff',borderRadius:4,maxBarThickness:22},
  {label:'Solar export',data:<?=json_encode($expBars)?>,backgroundColor:'#f0a93b',borderRadius:4,maxBarThickness:22}]},options:barOpt(true)});
new Chart(document.getElementById('net'),{type:'bar',data:{labels:dl,datasets:[
  {data:<?=json_encode($netBars)?>,backgroundColor:<?=json_encode(array_map(fn($v)=>$v<0?'#3fb950':'#1f6feb',$netBars))?>,borderRadius:4,maxBarThickness:22}]},options:barOpt(false)});
const cyc=<?=json_encode($cycChart)?>;
new Chart(document.getElementById('cyc'),{type:'bar',data:{labels:Object.keys(cyc),datasets:[{data:Object.values(cyc),backgroundColor:'#3fb950',borderRadius:4,maxBarThickness:48}]},options:barOpt(false)});

// line charts: visible point markers (readings are irregular snapshots, not a continuous signal)
function lineDS(l,d,c){return{label:l,data:d,borderColor:c,backgroundColor:c,pointBackgroundColor:c,
  borderWidth:1.8,pointRadius:d.length>50?0:2.5,pointHoverRadius:6,tension:.35,spanGaps:true,fill:false};}
const lineOpt=(y)=>({maintainAspectRatio:false,interaction:{mode:'nearest',intersect:false},
  plugins:{legend:{labels:{usePointStyle:true,boxWidth:8,padding:14}},tooltip:{mode:'nearest',intersect:false}},
  scales:{x:{type:'time',adapters:{date:{zone:'<?=$cfg['tz']?>'}},grid:{display:false},ticks:{autoSkip:true,maxTicksLimit:6,maxRotation:0}},y:Object.assign({grid:GRID,border:{display:false}},y)}});
<?php if($haveVolt): ?>
new Chart(document.getElementById('volt'),{type:'line',data:{datasets:[
  lineDS('R',<?=json_encode(array_map(fn($p)=>['x'=>$p[0],'y'=>$p[1]],$volt['R']))?>,'#f85149'),
  lineDS('Y',<?=json_encode(array_map(fn($p)=>['x'=>$p[0],'y'=>$p[1]],$volt['Y']))?>,'#e3b341'),
  lineDS('B',<?=json_encode(array_map(fn($p)=>['x'=>$p[0],'y'=>$p[1]],$volt['B']))?>,'#58a6ff')]},
  options:lineOpt({suggestedMin:200,suggestedMax:255,title:{display:true,text:'Volts',color:'#8b949e'}})});
<?php endif; ?>
<?php if($haveCurr): ?>
new Chart(document.getElementById('curr'),{type:'line',data:{datasets:[
  lineDS('R',<?=json_encode(array_map(fn($p)=>['x'=>$p[0],'y'=>$p[1]],$curr['R']))?>,'#f85149'),
  lineDS('Y',<?=json_encode(array_map(fn($p)=>['x'=>$p[0],'y'=>$p[1]],$curr['Y']))?>,'#e3b341'),
  lineDS('B',<?=json_encode(array_map(fn($p)=>['x'=>$p[0],'y'=>$p[1]],$curr['B']))?>,'#58a6ff')]},
  options:lineOpt({beginAtZero:true,title:{display:true,text:'Amps',color:'#8b949e'}})});
<?php endif; ?>
if(location.hash){const el=document.querySelector(location.hash); if(el){el.scrollIntoView(); el.classList.add('flash');}}
</script>
</body></html>
