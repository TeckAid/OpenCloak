<?php

define('APP_RUNTIME_DIR', '/var/www/html/data');
define('DB_PATH', '/var/www/html/data/cloaking.sqlite');
define('LOG_PATH', '/var/www/html/logs/');
define('APP_BASE_URL', 'https://app.localhost');
define('SYSTEM_HOSTS', [
    'app.localhost',
    'cloaks.localhost',
    'localhost',
    '127.0.0.1',
]);
define('TRUSTED_PROXIES', [
    '172.23.0.2/32',
]);
