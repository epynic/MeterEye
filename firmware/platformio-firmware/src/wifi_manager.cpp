#include "wifi_manager.h"

#include <WiFi.h>

static const uint32_t BACKOFF_START_MS = 5000;
static const uint32_t BACKOFF_MAX_MS = 60000;

void WifiManager::begin(const String &ssid, const String &pass) {
  _ssid = ssid;
  _pass = pass;
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.begin(_ssid.c_str(), _pass.c_str());
  _lastAttempt = millis();
  _disconnectedSince = millis();
  _backoffMs = BACKOFF_START_MS;
}

void WifiManager::maintain() {
  if (WiFi.status() == WL_CONNECTED) {
    if (!_wasConnected) {
      Serial.printf("[wifi] connected: %s rssi=%d\n",
                    WiFi.localIP().toString().c_str(), WiFi.RSSI());
    }
    _wasConnected = true;
    _disconnectedSince = 0;
    _backoffMs = BACKOFF_START_MS;
    return;
  }

  if (_disconnectedSince == 0) {
    _disconnectedSince = millis();
    if (_wasConnected) Serial.println("[wifi] connection lost");
    _wasConnected = false;
  }

  if (millis() - _lastAttempt >= _backoffMs) {
    Serial.printf("[wifi] reconnecting to %s ...\n", _ssid.c_str());
    WiFi.disconnect();
    WiFi.begin(_ssid.c_str(), _pass.c_str());
    _lastAttempt = millis();
    _backoffMs = min(_backoffMs * 2, BACKOFF_MAX_MS);
  }
}

bool WifiManager::connected() const { return WiFi.status() == WL_CONNECTED; }

uint32_t WifiManager::disconnectedForMs() const {
  if (_disconnectedSince == 0) return 0;
  return millis() - _disconnectedSince;
}
