# EB-Cam Backend (Phase 2 — not started)

Self-hosted, single-VPS stack (see `../PLAN.md`):

- **Go API** — device registry, claim/registration, image ingest, config shadow, OTA manifests
- **Postgres** — devices, credentials, config, image metadata, firmware releases
- **Mosquitto** — MQTT/TLS broker: heartbeat, online/offline (LWT), commands, config push
- **Caddy** — TLS termination / reverse proxy with automatic certificates
- **Docker Compose** — one file, one VPS
