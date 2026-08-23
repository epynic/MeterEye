#pragma once

// Captive-portal setup page, served from flash. No external assets:
// the client may have no internet while connected to the setup AP.
static const char PORTAL_HTML[] PROGMEM = R"HTML(<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>EB-Cam Setup</title>
<style>
  body { font-family: -apple-system, system-ui, sans-serif; margin: 0; background: #f4f5f7; color: #1a1a2e; }
  .wrap { max-width: 420px; margin: 0 auto; padding: 24px 16px; }
  h1 { font-size: 20px; margin: 8px 0 2px; }
  .dev { color: #666; font-size: 13px; margin-bottom: 20px; }
  .card { background: #fff; border-radius: 10px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 4px; }
  input { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 15px; }
  button { width: 100%; padding: 12px; border: 0; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; }
  .primary { background: #2563eb; color: #fff; margin-top: 16px; }
  .ghost { background: #eef1f5; color: #1a1a2e; margin-top: 4px; }
  .net { padding: 10px 6px; border-bottom: 1px solid #eee; cursor: pointer; display: flex; justify-content: space-between; font-size: 14px; }
  .net:hover { background: #f0f4ff; }
  .rssi { color: #999; font-size: 12px; }
  details { margin-top: 14px; }
  summary { font-size: 13px; color: #2563eb; cursor: pointer; }
  #msg { text-align: center; padding: 10px; font-size: 14px; display: none; border-radius: 6px; margin-top: 12px; }
  .ok { background: #dcfce7; color: #166534; }
  .err { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>
<div class="wrap">
  <h1>EB-Cam Setup</h1>
  <div class="dev">Device: <b id="devid">…</b> &middot; Firmware <span id="fw">…</span></div>

  <div class="card">
    <button class="ghost" onclick="scan()" id="scanbtn">Scan for Wi-Fi networks</button>
    <div id="nets"></div>
    <label>Wi-Fi network (SSID)</label>
    <input id="ssid" autocapitalize="off" autocorrect="off">
    <label>Wi-Fi password</label>
    <input id="pass" type="password">
    <details>
      <summary>Advanced (backend settings)</summary>
      <label>Upload URL</label>
      <input id="upurl" placeholder="https://...">
      <label>API key</label>
      <input id="apikey">
      <label>Claim code</label>
      <input id="claim" placeholder="from device label">
      <label>Capture interval (seconds)</label>
      <input id="interval" type="number" min="2" value="7">
    </details>
    <button class="primary" onclick="save()">Save &amp; Connect</button>
    <div id="msg"></div>
  </div>
</div>
<script>
function $(id){ return document.getElementById(id); }
fetch('/api/info').then(function(r){ return r.json(); }).then(function(d){
  $('devid').textContent = d.deviceId;
  $('fw').textContent = d.fw;
  if (d.uploadUrl) $('upurl').value = d.uploadUrl;
  if (d.intervalS) $('interval').value = d.intervalS;
});
function scan(){
  $('scanbtn').textContent = 'Scanning…';
  fetch('/api/scan').then(function(r){ return r.json(); }).then(function(list){
    $('scanbtn').textContent = 'Scan again';
    var h = '';
    list.forEach(function(n){
      h += '<div class="net" onclick="pick(this)"><span>' + n.ssid.replace(/</g,'&lt;') +
           (n.secure ? ' &#128274;' : '') + '</span><span class="rssi">' + n.rssi + ' dBm</span></div>';
    });
    $('nets').innerHTML = h || '<div class="rssi" style="padding:8px">No networks found</div>';
  }).catch(function(){ $('scanbtn').textContent = 'Scan for Wi-Fi networks'; });
}
function pick(el){ $('ssid').value = el.querySelector('span').textContent.replace(' 🔒',''); }
function save(){
  if (!$('ssid').value) { show('Enter a Wi-Fi network name', false); return; }
  var body = new URLSearchParams();
  body.append('ssid', $('ssid').value);
  body.append('pass', $('pass').value);
  body.append('upurl', $('upurl').value);
  body.append('apikey', $('apikey').value);
  body.append('claim', $('claim').value);
  body.append('interval', $('interval').value);
  fetch('/api/save', { method: 'POST', body: body }).then(function(r){
    if (r.ok) show('Saved. The device is rebooting and will join your Wi-Fi. You can close this page.', true);
    else show('Save failed — try again.', false);
  }).catch(function(){ show('Save failed — try again.', false); });
}
function show(t, ok){
  var m = $('msg');
  m.textContent = t;
  m.className = ok ? 'ok' : 'err';
  m.style.display = 'block';
}
</script>
</body>
</html>
)HTML";
