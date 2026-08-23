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
