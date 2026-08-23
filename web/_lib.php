<?php
// Shared library for the EB dashboard pages. Cumulative-meter aware + billing cycles.
require_once '/var/www/prasanha.com/config/app.php';

function eb_db() {
  $m = new mysqli('localhost', DB_USER, DB_PASS, DB_NAME);
  if ($m->connect_errno) { http_response_code(500); exit('db error'); }
  return $m;
}
function eb_settings($db) {
  $c = [];
  if ($r = $db->query("SELECT k,v FROM settings")) while ($x = $r->fetch_assoc()) $c[$x['k']] = $x['v'];
  $c += ['billing_day'=>'1','billing_metric'=>'ip_cu_fd','export_metric'=>'ep_cu_fd',
         'rate_per_kwh'=>'0','fixed_charge'=>'0','currency'=>'INR','tz'=>'Asia/Kolkata','admin_key'=>''];
  return $c;
}
function eb_tz($cfg){ return new DateTimeZone($cfg['tz'] ?: 'Asia/Kolkata'); }
function eb_ist($utc, $TZ){ $d=new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone($TZ); return $d; }
function eb_money($v,$ccy){ $s = $ccy==='INR' ? '₹' : ($ccy.' '); return $s.number_format((float)$v,2); }

// slab-based bill estimate for NET units. tariff_slabs = "size:rate,...,*:rate"
function eb_cost($units, $cfg){
  $units=max(0,(float)$units); $energy=0; $rem=$units;
  $slabs=trim($cfg['tariff_slabs'] ?? '');
  if($slabs!==''){
    foreach(explode(',',$slabs) as $part){
      $kv=explode(':',$part); if(count($kv)<2) continue;
      $sz=trim($kv[0]); $rate=(float)$kv[1];
      if($sz==='*'){ $energy+=$rem*$rate; $rem=0; break; }
      $take=min($rem,(float)$sz); $energy+=$take*$rate; $rem-=$take;
      if($rem<=0) break;
    }
  } else { $energy=$units*(float)($cfg['rate_per_kwh'] ?? 0); }
  $fixed=(float)($cfg['fixed_charge'] ?? 0);
  $ccCharges=round($energy); // bill's "CC Charges" line is energy rounded to the rupee
  // Regulatory surcharge = % of (CC Charges + Fixed S.C), not energy alone.
  // Verified exact (round-half-up) against 4 real bills: Apr/May/Jun/Jul 2026.
  $sur=round(($ccCharges+$fixed)*((float)($cfg['surcharge_pct'] ?? 0)/100));
  $subsidy=(float)($cfg['subsidy'] ?? 0);
  return ['energy'=>$ccCharges,'fixed'=>round($fixed,2),'surcharge'=>$sur,
          'subsidy'=>round($subsidy,2),'total'=>round(max(0,$ccCharges+$fixed+$sur-$subsidy),2)];
}
function eb_has_tariff($cfg){ return trim($cfg['tariff_slabs']??'')!=='' || (float)($cfg['rate_per_kwh']??0)>0; }

function eb_cycle_start(DateTime $d, $billingDay, $TZ){
  $y=(int)$d->format('Y'); $mo=(int)$d->format('n');
  if ((int)$d->format('j') < $billingDay){ $mo--; if($mo<1){$mo=12;$y--;} }
  return new DateTime(sprintf('%04d-%02d-%02d 00:00:00',$y,$mo,$billingDay), $TZ);
}

// full time series for a metric: [ [DateTime ist, float val], ... ]
function eb_series($db, $metric, $TZ){
  $out=[]; $q="SELECT captured_at,value FROM readings WHERE metric='".$db->real_escape_string($metric)."' ORDER BY captured_at";
  if($r=$db->query($q)) while($x=$r->fetch_assoc()) $out[]=[eb_ist($x['captured_at'],$TZ),(float)$x['value']];
  return $out;
}

// manual baseline (from cycle_baseline) for a cycle+metric, or null
function eb_baseline($db, $cycleStartDate, $metric){
  $q="SELECT value FROM cycle_baseline WHERE cycle_start='".$db->real_escape_string($cycleStartDate)."'
      AND metric='".$db->real_escape_string($metric)."'";
  $r=$db->query($q); return ($r && $x=$r->fetch_assoc()) ? (float)$x['value'] : null;
}

// consumption within the cycle containing $ref for $metric.
// anchor = manual baseline if set, else first reading seen in the cycle.
// returns [usage, anchor, anchorIsManual, firstInCycleDateTime, lastVal, lastDateTime]
function eb_cycle_usage($db, $metric, DateTime $ref, $cfg, $TZ){
  $bday=(int)$cfg['billing_day']; $cs=eb_cycle_start($ref,$bday,$TZ); $ce=(clone $cs)->modify('+1 month');
  $series=eb_series($db,$metric,$TZ);
  $inCycle=array_values(array_filter($series, fn($p)=>$p[0]>=$cs && $p[0]<$ce));
  if(!$inCycle) return [null,null,false,null,null,null,$cs,$ce];
  $manual=eb_baseline($db,$cs->format('Y-m-d'),$metric);
  $anchor = $manual!==null ? $manual : $inCycle[0][1];
  $last=end($inCycle);
  $usage=round($last[1]-$anchor,2);
  return [$usage,$anchor,$manual!==null,$inCycle[0][0],$last[1],$last[0],$cs,$ce];
}

// today's usage for a metric (IST day)
function eb_today_usage($db,$metric,$TZ){
  $series=eb_series($db,$metric,$TZ); if(!$series) return [null,null];
  $todayStart=new DateTime((new DateTime('now',$TZ))->format('Y-m-d').' 00:00:00',$TZ);
  $before=null; $todayVals=[];
  foreach($series as $p){ if($p[0]<$todayStart) $before=$p[1]; else $todayVals[]=$p[1]; }
  if(!$todayVals) return [0.0,end($series)[1]];
  $anchor = $before!==null ? $before : $todayVals[0];
  return [round(end($todayVals)-$anchor,2), end($todayVals)];
}

// camera liveness from worker high-water mark (last FRAME processed, not last reading)
function eb_liveness($db,$TZ){
  $r=$db->query("SELECT v FROM worker_state WHERE k='last_processed'");
  if(!$r || !($x=$r->fetch_assoc())) return [null,null,false];
  if(!preg_match('/(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})/',$x['v'],$mm)) return [null,null,false];
  $d=new DateTime(sprintf('%s-%s-%s %s:%s:%s',$mm[1],$mm[2],$mm[3],$mm[4],$mm[5],$mm[6]), new DateTimeZone('UTC'));
  $d->setTimezone($TZ); $age=round((time()-$d->getTimestamp())/60);
  return [$d,$age,$age<5];
}

// latest value per phase for one metric: ['R'=>row,'Y'=>..,'B'=>..]
function eb_latest_phase($db,$metric){
  $out=[]; $mq=$db->real_escape_string($metric);
  $r=$db->query("SELECT t.phase,t.value,t.captured_at FROM readings t
    JOIN (SELECT phase,MAX(id) mid FROM readings WHERE metric='$mq' GROUP BY phase) x ON t.id=x.mid
    ORDER BY t.phase");
  while($r && $row=$r->fetch_assoc()) $out[$row['phase']?:'-']=$row;
  return $out;
}

// latest stored value per metric
function eb_latest($db,$TZ){
  $out=[]; $r=$db->query("SELECT t.metric,t.value,t.unit,t.captured_at FROM readings t
    JOIN (SELECT metric,MAX(id) mid FROM readings GROUP BY metric) x ON t.id=x.mid");
  while($r && $row=$r->fetch_assoc()) $out[$row['metric']]=$row;
  return $out;
}

// Single source of truth for system alerts. Returns ['key'=>['bad'|'warn', 'message'], ...] (empty = healthy).
// Consumed by dashboard.php (the on-page ⓘ indicator), health.php, and monitor.php (push, when enabled),
// so all three always agree. The "stale register" signal is the hourly OCR checker's status, NOT a naive
// timer: a register flat because solar covers the load is fine ('ok'); one stuck because reads are rejected
// is 'poison_high'/'behind'. Cheap (one liveness query + two file reads) — safe to call on every page load.
function eb_alerts($db, $cfg, $TZ){
  $a = [];
  $gapMin = (int)($cfg['alert_gap_min'] ?? 20);
  [$camT,$camAge,$online] = eb_liveness($db,$TZ);
  if ($camT === null)                                   $a['camera'] = ['bad', 'No camera frames on record.'];
  elseif ($camAge !== null && $camAge > $gapMin)        $a['camera'] = ['bad', "No camera frames for {$camAge} min — camera or worker may be down."];
  $wm = @filemtime('/var/www/prasanha.com/worker/worker.log');
  if ($wm && (time()-$wm > 180))                        $a['worker'] = ['warn', 'Worker has not run in 3+ min (cron may be stuck).'];
  $freePct = round(disk_free_space('/')/disk_total_space('/')*100);
  if ($freePct < 10)                                    $a['disk'] = ['bad', "Disk space low: {$freePct}% free."];
  $ocrF = '/var/www/prasanha.com/worker/ocr_health.json';
  if (file_exists($ocrF) && ($oh = json_decode(file_get_contents($ocrF), true))) {
    foreach (['ip_cu_fd'=>'import','ep_cu_fd'=>'export'] as $m=>$lbl) {
      $r = $oh['metrics'][$m] ?? null; if (!$r) continue;
      $st = $r['status'] ?? '';
      if ($st === 'poison_high')   $a["ocr_$m"] = ['bad',  "Billing register '$lbl' looks poisoned: stored {$r['stored_register']} vs OCR consensus {$r['consensus_register']} — auto-heal couldn't fix it. Check the meter."];
      elseif ($st === 'behind')    $a["ocr_$m"] = ['warn', "Billing register '$lbl' may be stuck/lagging: stored {$r['stored_register']} vs OCR consensus {$r['consensus_register']}."];
    }
  }
  return $a;
}

// every billing-cycle start from the first reading through now (oldest→newest)
function eb_all_cycles($db,$cfg,$TZ){
  $bday=(int)$cfg['billing_day'];
  $bm=$db->real_escape_string($cfg['billing_metric']);
  $r=$db->query("SELECT MIN(captured_at) m FROM readings WHERE metric='$bm'");
  $row=$r?$r->fetch_assoc():null;
  if(!$row || empty($row['m'])) return [];
  $first=eb_ist($row['m'],$TZ); $now=new DateTime('now',$TZ);
  $cs=eb_cycle_start($first,$bday,$TZ); $out=[];
  while($cs<=$now){ $out[]=clone $cs; $cs=(clone $cs)->modify('+1 month'); }
  return $out;
}

// Solar savings per billing cycle + totals. Savings = bill on GROSS import − bill on NET (import−export).
// The fixed charge & subsidy are identical in both bills so they cancel — this is purely the slab-energy
// + surcharge saved by exporting surplus solar. It's a CONSERVATIVE floor on true solar benefit: solar
// that powers the house directly (self-consumption) never passes the import meter, so it isn't counted.
// Returns [ rows[], totals ] where each row = cs,ce,import,export,net,with,without,saved.
function eb_savings($db,$cfg,$TZ){
  $IMP=$cfg['billing_metric']; $EXP=$cfg['export_metric'];
  $rows=[]; $tot=['import'=>0,'export'=>0,'with'=>0,'without'=>0,'saved'=>0];
  foreach(eb_all_cycles($db,$cfg,$TZ) as $c){
    [$impUse,,,,,,$cs,$ce]=eb_cycle_usage($db,$IMP,$c,$cfg,$TZ);
    [$expUse]=eb_cycle_usage($db,$EXP,$c,$cfg,$TZ);
    if($impUse===null) continue;
    $imp=max(0,(float)$impUse); $exp=max(0,(float)($expUse??0)); $net=max(0,$imp-$exp);
    $with=eb_cost($net,$cfg)['total']; $without=eb_cost($imp,$cfg)['total'];
    $row=['cs'=>$cs,'ce'=>$ce,'import'=>round($imp,2),'export'=>round($exp,2),
          'net'=>round($imp-$exp,2),'with'=>$with,'without'=>$without,'saved'=>round($without-$with,2)];
    $rows[]=$row;
    foreach(['import','export','with','without','saved'] as $k) $tot[$k]+=$row[$k];
  }
  foreach($tot as $k=>$v) $tot[$k]=round($v,2);
  return [$rows,$tot];
}

// the REAL utility bill for a cycle, as recorded by the user (ground truth, separate from our estimate)
function eb_actual_bill($db,$cycleStartDate){
  $q="SELECT amount,units,bill_date,note,updated_at FROM cycle_actual WHERE cycle_start='".$db->real_escape_string($cycleStartDate)."'";
  $r=$db->query($q); return ($r && $x=$r->fetch_assoc()) ? $x : null;
}

// edit history for a cycle's actual bill, newest first
function eb_actual_bill_edits($db,$cycleStartDate){
  $out=[];
  $q="SELECT old_amount,old_units,old_bill_date,old_note,new_amount,new_units,new_bill_date,new_note,edited_at
      FROM cycle_actual_edits WHERE cycle_start='".$db->real_escape_string($cycleStartDate)."' ORDER BY edited_at DESC";
  if($r=$db->query($q)) while($x=$r->fetch_assoc()) $out[]=$x;
  return $out;
}

// save/overwrite the actual bill for a cycle. If one already exists, its old values are logged to
// cycle_actual_edits first (old->new), so repeated corrections stay traceable without a full audit table.
function eb_save_actual_bill($db,$cycleStartDate,$amount,$units,$billDate,$note){
  $old=eb_actual_bill($db,$cycleStartDate);
  if($old){
    $st=$db->prepare("INSERT INTO cycle_actual_edits
      (cycle_start,old_amount,old_units,old_bill_date,old_note,new_amount,new_units,new_bill_date,new_note)
      VALUES (?,?,?,?,?,?,?,?,?)");
    $st->bind_param('sddssddss',$cycleStartDate,$old['amount'],$old['units'],$old['bill_date'],$old['note'],
                     $amount,$units,$billDate,$note);
    $st->execute();
  }
  $st2=$db->prepare("INSERT INTO cycle_actual (cycle_start,amount,units,bill_date,note) VALUES (?,?,?,?,?)
    ON DUPLICATE KEY UPDATE amount=VALUES(amount),units=VALUES(units),bill_date=VALUES(bill_date),note=VALUES(note)");
  $st2->bind_param('sddss',$cycleStartDate,$amount,$units,$billDate,$note);
  $st2->execute();
}
