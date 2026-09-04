<?php
require_once __DIR__ . '/../includes/bootstrap.php';
boot_app(true);
require_once __DIR__ . '/../includes/auth.php';

if (current_user_id() !== null) {
    header('Location: /admin/dashboard.php');
} else {
    header('Location: /admin/login.php');
}
exit;
