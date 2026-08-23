#include <Arduino.h>
#include "esp_camera.h"
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include "board_config.h"   // CAMERA_MODEL_AI_THINKER must be active in this tab

// ===== WiFi =====
// Fill these in, or better: don't commit real values here at all — see README.
const char *ssid     = "YOUR_WIFI_SSID";
const char *password = "YOUR_WIFI_PASSWORD";

// ===== Server =====
const char* uploadUrl = "https://your-server.example/eb/upload.php";
const char* apiKey    = "YOUR_UPLOAD_API_KEY";

// ===== Capture interval =====
const unsigned long CAPTURE_INTERVAL_MS = 7000;  // every 7 sec (display changes ~10s)

// ===========================
// Tuned image settings (from your web UI; OV3660 ranges)
// brightness/contrast/sharpness: -3..3   saturation: -4..4
// ===========================
#define IMG_FRAMESIZE   FRAMESIZE_XGA  // 1024x768 (do NOT use VGA - too low for OCR)
#define IMG_QUALITY     10             // lower = better/bigger; 10 = clean upload
#define IMG_BRIGHTNESS  -1
#define IMG_CONTRAST    1
#define IMG_SATURATION  -2
#define IMG_SHARPNESS   2
#define IMG_EXPOSURE    -2             // exposure level
#define IMG_VFLIP       1
#define IMG_HMIRROR     0

void applySensorSettings() {
  sensor_t *s = esp_camera_sensor_get();
  if (!s) return;
  s->set_framesize(s, IMG_FRAMESIZE);
  s->set_quality(s, IMG_QUALITY);
  s->set_brightness(s, IMG_BRIGHTNESS);
  s->set_contrast(s, IMG_CONTRAST);
  s->set_saturation(s, IMG_SATURATION);
  s->set_sharpness(s, IMG_SHARPNESS);
  s->set_vflip(s, IMG_VFLIP);
  s->set_hmirror(s, IMG_HMIRROR);
  // matches your toggles: AWB on, advanced AWB on, AEC on, AGC on, GMA on
  s->set_whitebal(s, 1);
  s->set_awb_gain(s, 1);
  s->set_exposure_ctrl(s, 1);
  s->set_ae_level(s, IMG_EXPOSURE);
  s->set_gain_ctrl(s, 1);
  s->set_raw_gma(s, 1);
  // manual AWB off, night mode off
  s->set_wb_mode(s, 0);
}

void initCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer   = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk  = XCLK_GPIO_NUM;   config.pin_pclk  = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;  config.pin_href  = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM; config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn  = PWDN_GPIO_NUM;   config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;
  config.frame_size   = IMG_FRAMESIZE;
  config.jpeg_quality = IMG_QUALITY;
  config.fb_count     = 2;
  config.fb_location  = CAMERA_FB_IN_PSRAM;
  config.grab_mode    = CAMERA_GRAB_LATEST;

  if (!psramFound()) {
    config.frame_size  = FRAMESIZE_SVGA;
    config.fb_location = CAMERA_FB_IN_DRAM;
    config.fb_count    = 1;
  }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("Camera init failed: 0x%x\n", err);
    delay(3000);
    ESP.restart();
  }
  applySensorSettings();
}

void setup() {
  Serial.begin(115200);
  Serial.println();
  initCamera();

  WiFi.begin(ssid, password);
  WiFi.setSleep(false);
  Serial.print("WiFi connecting");
  while (WiFi.status() != WL_CONNECTED) { delay(500); Serial.print("."); }
  Serial.println();
  Serial.println("WiFi connected: " + WiFi.localIP().toString());
}

void uploadImage() {
  camera_fb_t *fb = esp_camera_fb_get();
  if (fb) esp_camera_fb_return(fb);   // discard stale frame
  fb = esp_camera_fb_get();
  if (!fb) { Serial.println("Capture failed"); return; }

  WiFiClientSecure client;
  client.setInsecure();

  HTTPClient http;
  if (http.begin(client, uploadUrl)) {
    http.addHeader("Content-Type", "application/octet-stream");
    http.addHeader("X-API-Key", apiKey);
    int code = http.POST(fb->buf, fb->len);
    Serial.printf("Upload HTTP %d, %u bytes\n", code, fb->len);
    if (code > 0) Serial.println(http.getString());
    http.end();
  } else {
    Serial.println("http.begin failed");
  }
  esp_camera_fb_return(fb);
}

void loop() {
  static unsigned long last = 0;
  if (millis() - last >= CAPTURE_INTERVAL_MS) {
    last = millis();
    if (WiFi.status() == WL_CONNECTED) {
      uploadImage();
    } else {
      Serial.println("WiFi lost, reconnecting...");
      WiFi.reconnect();
      delay(2000);
    }
  }
}