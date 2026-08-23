#pragma once

#include <Arduino.h>
#include "esp_camera.h"

// All runtime configuration lives here, persisted in NVS.
// Nothing customer-specific is ever compiled into the firmware.
struct DeviceConfig {
  // Wi-Fi (set via provisioning portal)
  String wifiSsid;
  String wifiPass;

  // Backend. The upload URL is platform-wide (not customer-specific), so a
  // compiled-in default is fine; it stays remotely updatable via NVS.
  // The API key is a Phase 1 stopgap: from Phase 2 the device obtains its
  // own per-device key automatically at registration (claim code -> key).
  String uploadUrl = "https://prasanha.com/eb/upload.php";
  String apiKey;
  String claimCode;  // consumed by registration in Phase 2

  // Capture
  uint32_t captureIntervalMs = 7000;

  // Camera tuning (updatable remotely in Phase 3)
  uint8_t framesize = FRAMESIZE_XGA;
  uint8_t quality = 10;
  int8_t brightness = -1;
  int8_t contrast = 1;
  int8_t saturation = -2;
  int8_t sharpness = 2;
  int8_t aeLevel = -2;
  bool vflip = true;
  bool hmirror = false;

  bool hasWifi() const { return wifiSsid.length() > 0; }
  bool hasUploadTarget() const { return uploadUrl.length() > 0; }
};

class ConfigStore {
 public:
  void load();
  void save();
  void factoryReset();  // wipes NVS namespace; device reboots into setup mode

  DeviceConfig cfg;
};

extern ConfigStore Config;
