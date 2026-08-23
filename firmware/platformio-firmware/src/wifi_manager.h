#pragma once

#include <Arduino.h>

// Non-blocking STA connection with exponential-backoff reconnect.
// Never hangs the device: main loop decides when to fall back to setup mode
// based on disconnectedForMs().
class WifiManager {
 public:
  void begin(const String &ssid, const String &pass);
  void maintain();  // call every loop iteration
  bool connected() const;
  uint32_t disconnectedForMs() const;

 private:
  String _ssid, _pass;
  uint32_t _lastAttempt = 0;
  uint32_t _backoffMs = 5000;
  uint32_t _disconnectedSince = 0;
  bool _wasConnected = false;
};
