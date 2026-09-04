<?php

define('TESTS_ROOT', __DIR__);
define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set('UTC');

require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/fixtures/DatabaseFixture.php';
require_once __DIR__ . '/fixtures/HttpFixture.php';
require_once APP_ROOT . '/includes/security.php';
require_once APP_ROOT . '/includes/rules.php';
require_once APP_ROOT . '/includes/database.php';
