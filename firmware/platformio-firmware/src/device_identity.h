#pragma once

#include <Arduino.h>

// Stable identity derived from the chip's factory-burned MAC.
String deviceId();  // e.g. "EBCAM-3C71BF"
String apSsid();    // e.g. "EBCam-3C71BF" (setup hotspot name)
