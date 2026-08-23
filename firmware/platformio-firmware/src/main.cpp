#include <Arduino.h>
#include <WiFi.h>

#include "camera_ctl.h"
#include "config_store.h"
#include "device_identity.h"
#include "provisioning.h"
#include "status_led.h"
#include "uploader.h"
#include "version.h"
#include "wifi_manager.h"

// After this long without Wi-Fi, drop back into the setup portal so the
// customer can fix credentials (e.g. router password changed). Stored
// config is kept — only Wi-Fi needs re-entering.
static const uint32_t WIFI_FALLBACK_MS = 5UL * 60 * 1000;
// While in the portal with stored credentials, retry them periodically in
// case the outage was temporary (router reboot etc.).
static const uint32_t PORTAL_RETRY_MS = 5UL * 60 * 1000;
// Consecutive upload failures before a defensive reboot.
static const uint32_t MAX_UPLOAD_FAILURES = 20;

enum class Mode { SETUP, RUNNING };

static Mode mode = Mode::SETUP;
static WifiManager wifi;
static uint32_t lastCapture = 0;
static uint32_t lastPortalRetry = 0;
static uint32_t uploadFailures = 0;
static bool cameraReady = false;

static void enterSetupMode() {
  mode = Mode::SETUP;
  lastPortalRetry = millis();
  Provisioning::start();
}

static void printInfo() {
  Serial.println("---- eb-cam info ----");
  Serial.printf("device:   %s\n", deviceId().c_str());
  Serial.printf("firmware: %s\n", FW_VERSION);
  Serial.printf("mode:     %s\n", mode == Mode::SETUP ? "setup" : "running");
  Serial.printf("wifi:     %s", WiFi.status() == WL_CONNECTED ? "connected" : "down");
  if (WiFi.status() == WL_CONNECTED)
    Serial.printf(" ip=%s rssi=%d", WiFi.localIP().toString().c_str(), WiFi.RSSI());
  Serial.println();
  Serial.printf("ssid:     %s\n", Config.cfg.wifiSsid.c_str());
  Serial.printf("upload:   %s\n", Config.cfg.uploadUrl.c_str());
  Serial.printf("interval: %lus\n", (unsigned long)(Config.cfg.captureIntervalMs / 1000));
  Serial.printf("uptime:   %lus  heap: %u  psram: %s\n",
                millis() / 1000, ESP.getFreeHeap(), psramFound() ? "yes" : "no");
  Serial.println("commands: info | setup | factory-reset | reboot");
}

static void runCommand(const String &cmd) {
  if (cmd == "info") {
    printInfo();
  } else if (cmd == "reboot") {
    ESP.restart();
  } else if (cmd == "factory-reset") {
    Serial.println("[cmd] wiping config, rebooting into setup mode");
    Config.factoryReset();
    delay(200);
    ESP.restart();
  } else if (cmd == "setup") {
    Serial.println("[cmd] entering setup mode");
    enterSetupMode();
  } else {
    Serial.printf("[cmd] unknown: %s\n", cmd.c_str());
  }
}

static void handleSerial() {
  static String line;
  while (Serial.available()) {
    char c = Serial.read();
    if (c == '\n' || c == '\r') {
      line.trim();
      if (line.length()) runCommand(line);
      line = "";
    } else if (line.length() < 64) {
      line += c;
    }
  }
}

void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.printf("\neb-cam %s  device=%s\n", FW_VERSION, deviceId().c_str());

  StatusLed::begin();
  Config.load();

  if (!Config.cfg.hasWifi()) {
    Serial.println("[boot] no Wi-Fi config — starting setup portal");
    enterSetupMode();
    return;
  }

  mode = Mode::RUNNING;
  cameraReady = cameraInit();
  if (!cameraReady) {
    Serial.println("[boot] camera init failed, rebooting in 5s");
    delay(5000);
    ESP.restart();
  }
  wifi.begin(Config.cfg.wifiSsid, Config.cfg.wifiPass);
}

static void loopSetup() {
  Provisioning::loop();
  StatusLed::tick(150);

  if (Provisioning::credentialsSaved()) {
    delay(1500);  // let the browser receive the response
    ESP.restart();
  }

  // Fallback case: we still have stored credentials — retry them so a
  // temporary outage recovers without customer action.
  if (Config.cfg.hasWifi() && millis() - lastPortalRetry >= PORTAL_RETRY_MS) {
    lastPortalRetry = millis();
    Serial.println("[setup] retrying stored Wi-Fi credentials");
    WiFi.begin(Config.cfg.wifiSsid.c_str(), Config.cfg.wifiPass.c_str());
  }
  if (Config.cfg.hasWifi() && WiFi.status() == WL_CONNECTED) {
    Serial.println("[setup] stored Wi-Fi is back — resuming normal operation");
    ESP.restart();
  }
}

static void loopRunning() {
  wifi.maintain();

  if (!wifi.connected()) {
    StatusLed::tick(600);
    if (wifi.disconnectedForMs() >= WIFI_FALLBACK_MS) {
      Serial.println("[run] Wi-Fi down too long — opening setup portal");
      enterSetupMode();
    }
    return;
  }

  StatusLed::off();
  if (millis() - lastCapture >= Config.cfg.captureIntervalMs) {
    lastCapture = millis();
    if (uploadImage()) {
      uploadFailures = 0;
    } else if (++uploadFailures >= MAX_UPLOAD_FAILURES) {
      Serial.println("[run] too many upload failures, rebooting");
      ESP.restart();
    }
  }
}

void loop() {
  handleSerial();
  if (mode == Mode::SETUP) {
    loopSetup();
  } else {
    loopRunning();
  }
}
