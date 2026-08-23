<?php
// Copy this file to config.php (outside version control — see .gitignore)
// and fill in real values. Keep config.php out of your webroot, or make
// sure your web server is configured to never serve .php as plain text.

define('UPLOAD_KEY', 'generate-a-long-random-string-here');
define('IMAGES_DIR', __DIR__ . '/storage/eb_images/');
define('IMAGES_URL', '/eb/images/');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Where the worker writes its logs/state (worker.log, ocr_health.json,
// alert_state.json). Defaults to the repo's worker/ dir.
define('WORKER_DIR', __DIR__ . '/worker/');

// Shown as the page title on index.php.
define('SITE_NAME', 'EB Monitor');

// Optional: public URL of your dashboard, appended to alert messages.
define('DASHBOARD_URL', '');

// From: address for email alerts sent by monitor.php.
define('ALERT_FROM', 'eb-monitor@localhost');
