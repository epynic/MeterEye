#pragma once

// Setup mode: open SoftAP ("EBCam-XXXXXX") + DNS catch-all so phones pop the
// captive portal automatically. Serves the setup page, Wi-Fi scan, and the
// save endpoint. Entered on first boot (no config) or after prolonged
// Wi-Fi failure (existing config is kept so only the password needs re-entry).
namespace Provisioning {

void start();
void loop();            // pump DNS + HTTP; call every loop iteration
bool credentialsSaved();  // true once the user submitted; caller reboots

}  // namespace Provisioning
