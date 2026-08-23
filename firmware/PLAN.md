# EB-Cam — Production Plan

Fleet-ready ESP32-CAM system: one generic firmware for all customers, self-hosted backend.

## Locked decisions

| Layer | Choice |
|---|---|
| Board | AI-Thinker ESP32-CAM (4MB flash, PSRAM) |
| Firmware build | PlatformIO + pioarduino platform (Arduino core 3.x). Flip to `arduino, espidf` hybrid at Phase 5 for OTA rollback / encryption sdkconfig. |
| Backend | **Go** API (single static binary, low memory, high concurrency) + Postgres + Mosquitto (MQTT/TLS) + Caddy (auto-HTTPS), all via Docker Compose on one VPS |
| Identity | Device ID from chip MAC + per-device credentials issued at claim |
| Transport | MQTT for status/config/commands; HTTPS for image upload |
| Onboarding | SoftAP captive portal (Wi-Fi) + claim in web dashboard (QR on label) |
| OTA | Dual app partitions (A/B), CI-built signed images, rollback on failed boot, dashboard-driven staged rollout |

## Phases

- [x] **Phase 0 — Housekeeping**: rotate exposed API key + Wi-Fi password (in `CameraWebServer_legacy/`); repo + tooling setup.
- [ ] **Phase 1 — Firmware foundation** *(in progress)*: PlatformIO project; port camera/upload code into modules; NVS config store; SoftAP captive-portal provisioning; Wi-Fi manager with auto-fallback to setup mode after 5 min offline; dual-OTA partition table flashed from day 1. *Done when a fresh board onboards from a phone with zero hardcoded values.*
- [ ] **Phase 2 — Backend core + identity**: VPS Docker Compose (Caddy, Go API, Postgres, Mosquitto); device registry; claim codes; `POST /register` exchanging claim code → per-device credentials.
- [ ] **Phase 3 — MQTT**: heartbeat (fw version, uptime, heap, RSSI, upload stats), Last-Will online/offline, `devices/{id}/cmd` (restart, capture-now, refresh-config), desired/reported config shadow.
- [ ] **Phase 4 — Secure upload**: CA bundle + NTP; remove `setInsecure()`; per-device auth on uploads; image metadata in Postgres.
- [ ] **Phase 5 — OTA**: switch build to IDF-hybrid; generate signing key (CI secret + offline backup — never in repo); GitHub Actions build/sign/version; manifest endpoint; verified download + rollback-on-failed-boot; test a deliberately broken image.
- [ ] **Phase 6 — Dashboard**: device list (online state, last image, fw version), config editor, command buttons, claim-device flow, OTA rollout UI, offline alerts.
- [ ] **Phase 7 — Hardening** (production batches only): NVS + flash encryption, secure boot (irreversible eFuses — only after 1–6 are stable).

## OTA rollout model (dashboard-driven)

Devices never auto-pull "latest". Each device (or group) has an *assigned target version* in the DB; devices poll/receive their own manifest. The dashboard therefore supports:
- **Selective updates** — target one device, a tag/group (e.g. "customer-X", "canary"), or the whole fleet.
- **Rolling updates** — staged percentage rollout (1% → 10% → 100%) with auto-halt if updated devices fail to report healthy.
- **Pinning/rollback** — pin a device to a version; reassign an older version to roll back (plus automatic on-device rollback if new firmware fails to boot).

## Production / handover checklist (per device)

1. Flash latest CI-signed release over USB (production partition table).
2. Factory self-test via serial (camera frame, Wi-Fi scan, PSRAM, LED).
3. Register in backend (MAC → registry row, claim code generated).
4. Print + attach QR label (device ID + claim code).
5. Wipe NVS → fresh out-of-box state.
6. Box with quick-start card (power on → join `EBCam-XXXXXX` → setup page → claim in dashboard).

Fleet ops: signing key custody (CI secret + offline copy), canary-first rollouts, factory-reset support path, credential revocation for lost/RMA devices, never ship a non-CI build.

## Repo layout

```
firmware/   PlatformIO project (Phase 1+)
backend/    Go API + docker-compose (Phase 2+)
CameraWebServer_legacy/   original sketch — reference only, delete after port is proven
```
