<?php
require_once __DIR__.'/_lib.php';
$db=eb_db(); $cfg=eb_settings($db); $TZ=eb_tz($cfg);
date_default_timezone_set($cfg['tz']);
$IMP=$cfg['billing_metric']; $EXP=$cfg['export_metric'];
$rate=(float)$cfg['rate_per_kwh']; $fixed=(float)$cfg['fixed_charge']; $ccy=$cfg['currency'];
$now=new DateTime('now',$TZ);
$alerts=eb_alerts($db,$cfg,$TZ);                                   // system health for the on-page ⓘ indicator
$alertWorst=in_array('bad',array_column($alerts,0))?'bad':(in_array('warn',array_column($alerts,0))?'warn':'ok');

[$impUse,$impAnchor,$impManual,$impFirst,$impLast,$impLastT,$cs,$ce]=eb_cycle_usage($db,$IMP,$now,$cfg,$TZ);
[$expUse,$expAnchor,$expManual,$expFirst,$expLast]=eb_cycle_usage($db,$EXP,$now,$cfg,$TZ);
[$todayImp]=eb_today_usage($db,$IMP,$TZ);
[$todayExp]=eb_today_usage($db,$EXP,$TZ);
[$camT,$camAge,$online]=eb_liveness($db,$TZ);
$total=$db->query("SELECT COUNT(*) c FROM readings")->fetch_assoc()['c'];
$vph=eb_latest_phase($db,'voltage'); $aph=eb_latest_phase($db,'current');
$lat=eb_latest($db,$TZ);
$pf = isset($lat['pf']) ? number_format($lat['pf']['value'],2) : null;
function phline($ph){ $o=[]; foreach(['R','Y','B'] as $p) if(isset($ph[$p])) $o[]=$p.' '.rtrim(rtrim(number_format($ph[$p]['value'],1),'0'),'.'); return $o?implode(' · ',$o):'—'; }

$net = ($impUse!==null) ? round($impUse-($expUse??0),2) : null;       // import - export (net metering)
$surplus = ($net!==null && $net < 0) ? round(-$net,2) : 0;            // exported more than imported
$billNet = ($net!==null) ? max(0,$net) : null;                        // slab cost only on net consumed
$cost = ($billNet!==null) ? eb_cost($billNet,$cfg)['total'] : null;
$hasTariff = eb_has_tariff($cfg);

// solar savings this cycle: bill on gross import vs on net (what they actually pay)
$costWithout = ($impUse!==null && $hasTariff) ? eb_cost(max(0,$impUse),$cfg)['total'] : null;
$saved = ($costWithout!==null && $cost!==null) ? round($costWithout-$cost,2) : null;
$savedPct = ($saved!==null && $costWithout>0) ? round(100*$saved/$costWithout) : null;

// projection: rate from OBSERVED data only (not the manual anchor), extrapolate to cycle end
$series=eb_series($db,$IMP,$TZ);
$inCyc=array_values(array_filter($series, fn($p)=>$p[0]>=$cs && $p[0]<$ce));
$proj=null; $daysIn=max(0.04,($now->getTimestamp()-$cs->getTimestamp())/86400);
$cycleLen=max(1,(int)$cs->diff($ce)->format('%a'));
if($impUse!==null && count($inCyc)>=2){
  $firstObs=$inCyc[0]; $obsDays=max(0.04,($now->getTimestamp()-$firstObs[0]->getTimestamp())/86400);
  $rateDay=max(0,($impLast-$firstObs[1])/$obsDays);
  $remain=max(0,($ce->getTimestamp()-$now->getTimestamp())/86400);
  $proj=round($impUse+$rateDay*$remain,1);
}
$projNet = ($impUse>0 && $proj!==null) ? round($net*($proj/$impUse),1) : $proj;
$projCost = ($projNet!==null)?eb_cost($projNet,$cfg)['total']:null;

// last 14 IST days of import usage for mini chart
$byDay=[];
foreach($series as $p) $byDay[$p[0]->format('Y-m-d')]=$p[1];
ksort($byDay); $daily=[]; $prev=null;
foreach($byDay as $d=>$v){ if($prev!==null) $daily[$d]=round($v-$prev,2); $prev=$v; }
$daily=array_slice($daily,-14,null,true);
function nz($v){ return $v===null?'—':rtrim(rtrim(number_format($v,2),'0'),'.'); }

// last bill: the most recently CLOSED cycle (not the one in progress), for the dashboard summary card
$allCycles=eb_all_cycles($db,$cfg,$TZ);                            // oldest -> newest
$lastBill=null;
for($i=count($allCycles)-1;$i>=0;$i--){
  if($allCycles[$i]->format('Y-m-d')===$cs->format('Y-m-d')) continue;   // skip the in-progress cycle
  [$pImp,,,,,,$pcs,$pce]=eb_cycle_usage($db,$IMP,$allCycles[$i],$cfg,$TZ);
  if($pImp===null) continue;
  [$pExp]=eb_cycle_usage($db,$EXP,$allCycles[$i],$cfg,$TZ);
  $pImp=max(0,(float)$pImp); $pExp=max(0,(float)($pExp??0)); $pNet=max(0,$pImp-$pExp);
  $pEst=$hasTariff?eb_cost($pNet,$cfg)['total']:null;
  $lastBill=['cs'=>$pcs,'ce'=>$pce,'imp'=>$pImp,'exp'=>$pExp,'net'=>$pNet,'est'=>$pEst,
             'actual'=>eb_actual_bill($db,$pcs->format('Y-m-d'))];
  break;
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>⚡ Electricity</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{background:#0b0f14;color:#e6edf3;font:15px/1.5 system-ui,sans-serif;margin:0;padding:20px;max-width:760px;margin:auto}
a{color:#58a6ff;text-decoration:none}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
h1{font-size:20px;margin:0}
.status{padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.on{background:#1a7f37;color:#fff}.off{background:#a40e26;color:#fff}
.hero{background:linear-gradient(135deg,#11202e,#0f1620);border:1px solid #233;border-radius:16px;padding:22px;margin-bottom:14px}
.hero .lbl{color:#8b949e;font-size:12px;letter-spacing:.5px}
.hero .big{font-size:52px;font-weight:800;color:#58a6ff;line-height:1.05}
.hero .cost{font-size:20px;color:#3fb950;font-weight:700;margin-top:10px}
.hero .bd{display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;margin:12px 0 2px}
.hero .bk{color:#8b949e;font-size:12px;margin-bottom:2px}
.hero .bv{font-size:30px;font-weight:800;line-height:1.05}
.hero .bv span{font-size:13px;color:#8b949e;font-weight:400}
.hero .bv.imp{color:#58a6ff}.hero .bv.exp{color:#f0a93b}.hero .bv.net{color:#e6edf3}
.hero .op{font-size:26px;color:#566;font-weight:700;padding-bottom:4px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px}
@media(min-width:560px){.grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:560px){
  body{padding:13px}
  .hero{padding:16px}
  .hero .big{font-size:40px}
  .hero .bd{flex-direction:column;gap:0;align-items:stretch;margin:10px 0 4px}
  .hero .op{display:none}
  .hero .bcol{display:flex;justify-content:space-between;align-items:baseline;padding:7px 0;border-bottom:1px solid #1c2530}
  .hero .bk{margin:0}
  .hero .bv{font-size:24px}
}
.tile{background:#11161d;border:1px solid #233;border-radius:12px;padding:13px}
.tile .k{color:#8b949e;font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.tile .v{font-size:22px;font-weight:700;margin-top:3px}.tile .u{font-size:12px;color:#8b949e}
.solar .v{color:#f0a93b}.net .v{color:#e6edf3}
.card{background:#11161d;border:1px solid #233;border-radius:12px;padding:14px;margin-bottom:14px}
.card h2{font-size:13px;margin:0 0 10px;color:#8b949e;text-transform:uppercase;letter-spacing:.4px}
.note{background:#2a210a;border:1px solid #5a4a14;color:#e8d59a;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px}
.savings{display:flex;justify-content:space-between;align-items:center;gap:12px;background:linear-gradient(135deg,#0f2417,#0f1620);border:1px solid #1d4a2e;border-radius:14px;padding:15px 18px;margin-bottom:14px}
.savings .k{color:#3fb950;font-size:11px;letter-spacing:.4px;font-weight:700}
.savings .sv{font-size:24px;font-weight:800;color:#3fb950;line-height:1.15;margin:5px 0 3px}
.savings .sv small{font-size:14px;color:#7ee29a;font-weight:600}
.savings .arrow{color:#3fb950;font-size:22px;font-weight:700;flex:0 0 auto}
.lastbill{display:flex;justify-content:space-between;align-items:center;gap:12px;background:linear-gradient(135deg,#111d2e,#0f1620);border:1px solid #1d3a5a;border-radius:14px;padding:15px 18px;margin-bottom:14px}
.lastbill .k{color:#58a6ff;font-size:11px;letter-spacing:.4px;font-weight:700}
.lastbill .sv{font-size:24px;font-weight:800;color:#e6edf3;line-height:1.15;margin:5px 0 3px}
.lastbill .sv small{font-size:14px;color:#8b949e;font-weight:600}
.lastbill .sv .vup{color:#f0a93b}.lastbill .sv .vdn{color:#3fb950}
.lastbill .arrow{color:#58a6ff;font-size:22px;font-weight:700;flex:0 0 auto}
.sysbanner{display:flex;gap:10px;align-items:flex-start;border-radius:10px;padding:10px 12px;margin-bottom:14px;text-decoration:none;font-size:13px;font-weight:600}
.sysbanner.bad{background:#3a0f15;border:1px solid #a40e26;color:#ff9b94}
.sysbanner.warn{background:#2a210a;border:1px solid #6a5414;color:#e8c468}
.sysbanner .ic{font-size:16px;line-height:1.25;flex:0 0 auto}
.sysbanner .msgs{display:flex;flex-direction:column;gap:3px;flex:1}
.sysbanner .more{color:#8b949e;font-weight:400;white-space:nowrap}
.sysok{display:inline-flex;gap:6px;align-items:center;color:#8b949e;font-size:12px;text-decoration:none;margin-bottom:14px}
.sysok .ic{color:#3fb950;font-size:14px}
.foot{color:#8b949e;font-size:12px;text-align:center;margin-top:18px}
small{color:#8b949e}
a.clk{cursor:pointer;text-decoration:none;color:inherit;display:block;-webkit-tap-highlight-color:rgba(88,166,255,.25)}
a.clk:active{opacity:.6}a.clk:hover .bv,a.clk:hover .v{filter:brightness(1.15)}
</style></head><body>
<div class="top">
  <h1>⚡ Electricity</h1>
  <div><span class="status <?=$online?'on':'off'?>"><?=$online?'● live':'● offline'?></span></div>
</div>
<div style="color:#8b949e;font-size:12px;margin-bottom:14px">
  camera <?=$camT?$camT->format('D d M, H:i'):'—'?> <?=$camAge!==null?"($camAge min ago)":''?>
  · cycle <?=$cs->format('d M')?>–<?=$ce->format('d M')?> · <?=$total?> readings
</div>

<?php if($alerts): ?>
<a href="health.php" class="sysbanner <?=$alertWorst?>">
  <span class="ic">&#9432;</span>
  <span class="msgs"><?php foreach($alerts as [$lvl,$msg]): ?><span><?=($lvl==='bad'?'⚠ ':'• ').htmlspecialchars($msg)?></span><?php endforeach; ?></span>
  <span class="more">health →</span>
</a>
<?php else: ?>
<a href="health.php" class="sysok"><span class="ic">&#9432;</span> all systems healthy · view health →</a>
<?php endif; ?>

<?php if(!$impManual): ?>
<div class="note">⚙ No billing baseline set — usage is counted from the first reading we captured (<?=$impFirst?$impFirst->format('d M H:i'):'n/a'?>), not your bill date.
<a href="settings.php">Enter last bill's meter reading →</a></div>
<?php endif; ?>

<div class="hero">
  <div class="lbl">THIS BILLING CYCLE · <?=$cs->format('d M')?> → <?=$ce->format('d M')?></div>
  <div class="bd">
    <a class="bcol clk" href="detail.php#c-daily"><div class="bk">⬇ Imported (grid)</div><div class="bv imp"><?=nz($impUse)?> <span>kWh</span></div></a>
    <div class="op">−</div>
    <a class="bcol clk" href="detail.php#c-daily"><div class="bk">⬆ Exported (solar)</div><div class="bv exp"><?=nz($expUse)?> <span>kWh</span></div></a>
    <div class="op">=</div>
    <a class="bcol clk" href="detail.php#c-net"><div class="bk"><?=$surplus>0?'Net surplus':'Net (billed)'?></div>
      <div class="bv net" style="<?=$surplus>0?'color:#3fb950':''?>"><?=$surplus>0?$surplus:nz($net)?> <span>kWh</span></div></a>
  </div>
  <?php if($surplus>0): ?>
    <a class="cost clk" href="detail.php"><span>in credit ✓</span> <small style="color:#8b949e;font-weight:400">solar &gt; usage · only fixed charges apply</small></a>
  <?php elseif($cost!==null): ?>
    <a class="cost clk" href="detail.php"><?=eb_money($cost,$ccy)?> <small style="color:#8b949e;font-weight:400">est. bill<?php if(!$hasTariff):?> · set tariff<?php endif;?></small></a>
  <?php endif; ?>
</div>

<?php if($lastBill): ?>
<a class="lastbill clk" href="bills.php?c=<?=$lastBill['cs']->format('Y-m-d')?>">
  <div>
    <div class="k">🧾 LAST BILL · <?=$lastBill['cs']->format('M Y')?></div>
    <div class="sv">
      <?php if($lastBill['actual']):
        $lbVar=($lastBill['est']!==null)?round((float)$lastBill['actual']['amount']-$lastBill['est'],2):null; ?>
        <?=eb_money($lastBill['actual']['amount'],$ccy)?> <small>paid</small>
        <?php if($lbVar!==null && abs($lbVar)>=1): ?>
          <small class="<?=$lbVar>0?'vup':'vdn'?>"><?=$lbVar>0?'+':''?><?=eb_money($lbVar,$ccy)?> vs est.</small>
        <?php endif; ?>
      <?php elseif($lastBill['est']!==null): ?>
        <?=eb_money($lastBill['est'],$ccy)?> <small>estimated</small>
      <?php else: ?>
        <small>set a tariff to estimate →</small>
      <?php endif; ?>
    </div>
    <small>Import <?=nz($lastBill['imp'])?> · Export <?=nz($lastBill['exp'])?> · Net <?=nz($lastBill['net'])?> kWh</small>
  </div>
  <span class="arrow">→</span>
</a>
<?php endif; ?>

<?php if($saved!==null && $saved>0): ?>
<a class="savings clk" href="savings.php">
  <div>
    <div class="k">☀ SOLAR SAVINGS · THIS CYCLE</div>
    <div class="sv">You saved <?=eb_money($saved,$ccy)?> <?php if($savedPct!==null):?><small>(<?=$savedPct?>% off)</small><?php endif;?></div>
    <small>Without solar this bill would be <?=eb_money($costWithout,$ccy)?> · you pay <?=eb_money($cost,$ccy)?></small>
  </div>
  <span class="arrow">→</span>
</a>
<?php endif; ?>

<div class="grid">
  <a class="tile clk" href="detail.php?p=7d#c-daily"><div class="k">Today imported</div><div class="v"><?=nz($todayImp)?> <span class="u">kWh</span></div></a>
  <a class="tile solar clk" href="detail.php?p=7d#c-daily"><div class="k">Today exported</div><div class="v"><?=nz($todayExp)?> <span class="u">kWh</span></div></a>
  <a class="tile clk" href="detail.php#c-daily"><div class="k">Projected import (cycle)</div><div class="v"><?=nz($proj)?> <span class="u">kWh</span></div><?php if($projCost!==null&&$hasTariff):?><small>~<?=eb_money($projCost,$ccy)?></small><?php endif;?></a>
  <a class="tile clk" href="detail.php#c-cyc"><div class="k">Day of cycle</div><div class="v"><?=ceil($daysIn)?> <span class="u">/ <?=$cycleLen?></span></div></a>
</div>

<?php if($vph||$aph||$pf!==null): ?>
<div class="card">
  <div style="display:flex;gap:32px;flex-wrap:wrap;align-items:center">
    <a class="clk" href="detail.php#c-volt" style="text-decoration:none"><span style="color:#8b949e;font-size:11px">⚡ VOLTAGE (V) · R/Y/B</span>
      <div style="font-size:18px;font-weight:700;color:#a371f7"><?=phline($vph)?></div></a>
    <a class="clk" href="detail.php#c-current" style="text-decoration:none"><span style="color:#8b949e;font-size:11px">CURRENT (A) · R/Y/B</span>
      <div style="font-size:18px;font-weight:700;color:#f0a93b"><?=phline($aph)?></div></a>
    <?php if($pf!==null): ?><a class="clk" href="detail.php" style="text-decoration:none"><span style="color:#8b949e;font-size:11px">POWER FACTOR</span>
      <div style="font-size:18px;font-weight:700;color:#3fb950"><?=$pf?></div></a><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div style="text-align:center;color:#566;font-size:12px;margin:6px 0 0">tap any number to see its trend →</div>
<div class="foot">
  <a href="detail.php">📊 Detail</a> &nbsp;·&nbsp;
  <a href="bills.php">🧾 Bills</a> &nbsp;·&nbsp;
  <a href="savings.php">☀ Savings</a> &nbsp;·&nbsp;
  <a href="health.php">💚 Health</a> &nbsp;·&nbsp;
  <a href="settings.php">⚙ Settings</a> &nbsp;·&nbsp;
  Day <?=ceil($daysIn)?> of <?=$cycleLen?>
</div>
</body></html>
