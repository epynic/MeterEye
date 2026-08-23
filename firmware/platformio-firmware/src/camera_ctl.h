#pragma once

// Camera bring-up and tuning. Pin mapping comes from board_config.h,
// tuning values from the persisted DeviceConfig.
bool cameraInit();
void cameraApplySettings();
