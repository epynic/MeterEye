#include "camera_ctl.h"

#include <Arduino.h>

#include "board_config.h"
#include "config_store.h"
#include "esp_camera.h"

bool cameraInit() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM;
  config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;
  config.frame_size = (framesize_t)Config.cfg.framesize;
  config.jpeg_quality = Config.cfg.quality;
  config.fb_count = 2;
  config.fb_location = CAMERA_FB_IN_PSRAM;
  config.grab_mode = CAMERA_GRAB_LATEST;

  if (!psramFound()) {
    config.frame_size = FRAMESIZE_SVGA;
    config.fb_location = CAMERA_FB_IN_DRAM;
    config.fb_count = 1;
  }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("[cam] init failed: 0x%x\n", err);
    return false;
  }
  cameraApplySettings();
  return true;
}

void cameraApplySettings() {
  sensor_t *s = esp_camera_sensor_get();
  if (!s) return;
  const DeviceConfig &c = Config.cfg;
  s->set_framesize(s, (framesize_t)c.framesize);
  s->set_quality(s, c.quality);
  s->set_brightness(s, c.brightness);
  s->set_contrast(s, c.contrast);
  s->set_saturation(s, c.saturation);
  s->set_sharpness(s, c.sharpness);
  s->set_vflip(s, c.vflip ? 1 : 0);
  s->set_hmirror(s, c.hmirror ? 1 : 0);
  s->set_whitebal(s, 1);
  s->set_awb_gain(s, 1);
  s->set_exposure_ctrl(s, 1);
  s->set_ae_level(s, c.aeLevel);
  s->set_gain_ctrl(s, 1);
  s->set_raw_gma(s, 1);
  s->set_wb_mode(s, 0);
}
