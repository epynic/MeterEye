# EB-Cam

ESP32-CAM device fleet: periodic image capture uploaded to a self-hosted
backend, with customer self-onboarding, remote config, OTA updates, and fleet
monitoring.

- `PLAN.md` — architecture, phases, production checklist
- `firmware/` — PlatformIO firmware (Phase 1)
- `backend/` — Go API + Docker Compose stack (Phase 2+)
- `CameraWebServer_legacy/` — original prototype sketch, reference only
