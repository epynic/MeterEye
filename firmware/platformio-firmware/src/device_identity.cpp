#include "device_identity.h"

#include <esp_mac.h>

static String macSuffix() {
  uint8_t mac[6];
  esp_read_mac(mac, ESP_MAC_WIFI_STA);
  char buf[7];
  snprintf(buf, sizeof(buf), "%02X%02X%02X", mac[3], mac[4], mac[5]);
  return String(buf);
}

String deviceId() { return "EBCAM-" + macSuffix(); }

String apSsid() { return "EBCam-" + macSuffix(); }
