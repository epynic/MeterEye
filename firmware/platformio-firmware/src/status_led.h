#pragma once

#include <Arduino.h>

// Onboard red LED on the AI-Thinker board (GPIO33, active LOW).
// Patterns: fast blink = setup mode, slow blink = Wi-Fi down, off = running.
namespace StatusLed {

constexpr int PIN = 33;

inline void begin() {
  pinMode(PIN, OUTPUT);
  digitalWrite(PIN, HIGH);
}

inline void on() { digitalWrite(PIN, LOW); }
inline void off() { digitalWrite(PIN, HIGH); }

inline void tick(uint32_t intervalMs) {
  digitalWrite(PIN, (millis() / intervalMs) % 2 ? HIGH : LOW);
}

}  // namespace StatusLed
