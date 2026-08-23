#include "config_store.h"

#include <Preferences.h>

ConfigStore Config;

static const char *NS = "ebcam";

void ConfigStore::load() {
  Preferences p;
  p.begin(NS, true);
  DeviceConfig d;  // compiled-in defaults
  cfg.wifiSsid = p.getString("ssid", "");
  cfg.wifiPass = p.getString("pass", "");
  cfg.uploadUrl = p.getString("upurl", d.uploadUrl);
  cfg.apiKey = p.getString("apikey", "");
  cfg.claimCode = p.getString("claim", "");
  cfg.captureIntervalMs = p.getUInt("capms", d.captureIntervalMs);
  cfg.framesize = p.getUChar("fs", d.framesize);
  cfg.quality = p.getUChar("q", d.quality);
  cfg.brightness = p.getChar("br", d.brightness);
  cfg.contrast = p.getChar("ct", d.contrast);
  cfg.saturation = p.getChar("sat", d.saturation);
  cfg.sharpness = p.getChar("sh", d.sharpness);
  cfg.aeLevel = p.getChar("ae", d.aeLevel);
  cfg.vflip = p.getBool("vf", d.vflip);
  cfg.hmirror = p.getBool("hm", d.hmirror);
  p.end();
}

void ConfigStore::save() {
  Preferences p;
  p.begin(NS, false);
  p.putString("ssid", cfg.wifiSsid);
  p.putString("pass", cfg.wifiPass);
  p.putString("upurl", cfg.uploadUrl);
  p.putString("apikey", cfg.apiKey);
  p.putString("claim", cfg.claimCode);
  p.putUInt("capms", cfg.captureIntervalMs);
  p.putUChar("fs", cfg.framesize);
  p.putUChar("q", cfg.quality);
  p.putChar("br", cfg.brightness);
  p.putChar("ct", cfg.contrast);
  p.putChar("sat", cfg.saturation);
  p.putChar("sh", cfg.sharpness);
  p.putChar("ae", cfg.aeLevel);
  p.putBool("vf", cfg.vflip);
  p.putBool("hm", cfg.hmirror);
  p.end();
}

void ConfigStore::factoryReset() {
  Preferences p;
  p.begin(NS, false);
  p.clear();
  p.end();
  cfg = DeviceConfig();
}
