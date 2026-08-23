# EB-Cam Firmware

Generic, fleet-ready firmware for the AI-Thinker ESP32-CAM. No customer
configuration is compiled in — everything is set at onboarding and stored in NVS.

## Build & flash

Requires [PlatformIO](https://platformio.org/) (`brew install platformio`).

```sh
cd firmware
pio run                    # build
pio run -t upload          # flash (board in download mode: GPIO0->GND, press RST)
pio device monitor         # serial console @ 115200
```

Flashing note: the AI-Thinker board has no USB — use a USB-TTL adapter
(5V->5V, GND->GND, U0R->TX, U0T->RX), jumper GPIO0 to GND, press RST to enter
download mode. Remove the jumper and press RST again after flashing.

## First-boot / onboarding flow

1. Fresh device (empty NVS) boots into **setup mode**: open Wi-Fi AP
   `EBCam-XXXXXX`, LED blinks fast.
2. Join it from a phone — the setup page pops up automatically (captive
   portal), or open `http://192.168.4.1/`.
3. Pick the customer's Wi-Fi and enter the password — that is all a customer
   ever enters. The *Advanced* section (upload URL, API key, claim code,
   interval) is installer/dev-only: the upload URL has a compiled-in platform
   default, and from Phase 2 the API key is obtained automatically at
   registration (claim code → per-device key), so the field disappears.
4. Device saves to NVS, reboots, connects, and starts capturing/uploading.

If Wi-Fi stays down for 5 minutes (e.g. the router password changed), the
device automatically reopens the setup portal while keeping its identity and
config — re-enter Wi-Fi only. It also keeps retrying the stored credentials
every 5 minutes in case the outage was temporary.

## Serial commands (115200 baud)

| Command | Effect |
|---|---|
| `info` | device ID, fw version, mode, Wi-Fi, config summary |
| `setup` | force setup portal |
| `factory-reset` | wipe NVS, reboot into out-of-box state |
| `reboot` | restart |

## LED (onboard red, GPIO33)

Fast blink = setup mode · slow blink = Wi-Fi down · off = running normally.

## Layout

| File | Responsibility |
|---|---|
| `main.cpp` | boot/state machine (SETUP vs RUNNING), serial commands |
| `config_store.*` | NVS-persisted `DeviceConfig` |
| `device_identity.*` | device ID / AP name from chip MAC |
| `provisioning.*` + `portal_html.h` | SoftAP captive portal |
| `wifi_manager.*` | non-blocking STA connect with backoff |
| `camera_ctl.*` | camera init + tuning from config |
| `uploader.*` | capture + HTTPS POST |
| `partitions.csv` | dual OTA app slots (A/B) — OTA-ready from day 1 |

TLS certificate validation is deliberately deferred to Phase 4 (`setInsecure()`
still in `uploader.cpp`); OTA, MQTT, and registration arrive in Phases 2–5 — see
`../PLAN.md`.
