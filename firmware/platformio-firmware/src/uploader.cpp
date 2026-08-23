#include "uploader.h"

#include <Arduino.h>
#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>

#include "config_store.h"
#include "device_identity.h"
#include "esp_camera.h"
#include "version.h"

static bool postFrame(WiFiClient &client, camera_fb_t *fb) {
  HTTPClient http;
  if (!http.begin(client, Config.cfg.uploadUrl)) {
    Serial.println("[upload] http.begin failed");
    return false;
  }
  http.setConnectTimeout(5000);
  http.setTimeout(15000);
  http.addHeader("Content-Type", "application/octet-stream");
  http.addHeader("X-API-Key", Config.cfg.apiKey);
  http.addHeader("X-Device-Id", deviceId());
  http.addHeader("X-Firmware-Version", FW_VERSION);
  int code = http.POST(fb->buf, fb->len);
  Serial.printf("[upload] HTTP %d (%u bytes)\n", code, fb->len);
  http.end();
  return code >= 200 && code < 300;
}

bool uploadImage() {
  if (!Config.cfg.hasUploadTarget()) {
    Serial.println("[upload] no upload URL configured, skipping");
    return false;
  }

  camera_fb_t *fb = esp_camera_fb_get();
  if (fb) esp_camera_fb_return(fb);  // discard stale frame
  fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("[upload] capture failed");
    return false;
  }

  bool ok;
  if (Config.cfg.uploadUrl.startsWith("https")) {
    WiFiClientSecure client;
    // TODO(Phase 4): replace with CA-bundle validation + NTP time sync.
    client.setInsecure();
    ok = postFrame(client, fb);
  } else {
    WiFiClient client;
    ok = postFrame(client, fb);
  }

  esp_camera_fb_return(fb);
  return ok;
}
