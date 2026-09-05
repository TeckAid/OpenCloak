<?php

define('APP_RUNTIME_DIR', '/srv/cloaking/runtime');
define('DB_PATH', '/srv/cloaking/runtime/cloaking.sqlite');
define('LOG_PATH', '/srv/cloaking/runtime/logs/');
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
