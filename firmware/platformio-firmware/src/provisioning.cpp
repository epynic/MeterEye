#include "provisioning.h"

#include <DNSServer.h>
#include <WebServer.h>
#include <WiFi.h>

#include "config_store.h"
#include "device_identity.h"
#include "portal_html.h"
#include "version.h"

namespace Provisioning {

static WebServer server(80);
static DNSServer dns;
static bool saved = false;

static String jsonEscape(const String &in) {
  String out;
  out.reserve(in.length() + 4);
  for (size_t i = 0; i < in.length(); i++) {
    char c = in[i];
    if (c == '"' || c == '\\') out += '\\';
    if ((uint8_t)c >= 0x20) out += c;
  }
  return out;
}

static void handleRoot() {
  server.send_P(200, "text/html", PORTAL_HTML);
}

static void handleInfo() {
  String json = "{\"deviceId\":\"" + deviceId() + "\",\"fw\":\"" FW_VERSION
                "\",\"uploadUrl\":\"" + jsonEscape(Config.cfg.uploadUrl) +
                "\",\"intervalS\":" + String(Config.cfg.captureIntervalMs / 1000) + "}";
  server.send(200, "application/json", json);
}

static void handleScan() {
  int n = WiFi.scanNetworks();  // blocking (~2s) is fine in setup mode
  String json = "[";
  for (int i = 0; i < n && i < 25; i++) {
    if (i) json += ",";
    json += "{\"ssid\":\"" + jsonEscape(WiFi.SSID(i)) + "\",\"rssi\":" +
            String(WiFi.RSSI(i)) + ",\"secure\":" +
            (WiFi.encryptionType(i) == WIFI_AUTH_OPEN ? "false" : "true") + "}";
  }
  json += "]";
  WiFi.scanDelete();
  server.send(200, "application/json", json);
}

static void handleSave() {
  String ssid = server.arg("ssid");
  if (ssid.length() == 0) {
    server.send(400, "text/plain", "ssid required");
    return;
  }
  Config.cfg.wifiSsid = ssid;
  Config.cfg.wifiPass = server.arg("pass");
  if (server.hasArg("upurl") && server.arg("upurl").length())
    Config.cfg.uploadUrl = server.arg("upurl");
  if (server.hasArg("apikey") && server.arg("apikey").length())
    Config.cfg.apiKey = server.arg("apikey");
  if (server.hasArg("claim") && server.arg("claim").length())
    Config.cfg.claimCode = server.arg("claim");
  long sec = server.arg("interval").toInt();
  if (sec >= 2 && sec <= 24 * 3600) Config.cfg.captureIntervalMs = sec * 1000;
  Config.save();
  saved = true;
  Serial.printf("[setup] config saved (ssid=%s), rebooting\n", ssid.c_str());
  server.send(200, "text/plain", "ok");
}

static void handleCaptive() {
  // Any unknown URL (incl. OS connectivity probes) redirects to the portal,
  // which is what makes phones pop the "sign in to network" sheet.
  server.sendHeader("Location", "http://" + WiFi.softAPIP().toString() + "/");
  server.send(302, "text/plain", "");
}

void start() {
  saved = false;
  WiFi.mode(WIFI_AP_STA);  // AP for the portal, STA so we can scan/retry
  WiFi.softAP(apSsid().c_str());
  dns.start(53, "*", WiFi.softAPIP());

  server.on("/", HTTP_GET, handleRoot);
  server.on("/api/info", HTTP_GET, handleInfo);
  server.on("/api/scan", HTTP_GET, handleScan);
  server.on("/api/save", HTTP_POST, handleSave);
  server.onNotFound(handleCaptive);
  server.begin();

  Serial.printf("[setup] portal up: join Wi-Fi \"%s\", then open http://%s/\n",
                apSsid().c_str(), WiFi.softAPIP().toString().c_str());
}

void loop() {
  dns.processNextRequest();
  server.handleClient();
}

bool credentialsSaved() { return saved; }

}  // namespace Provisioning
