<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/config.php';

require_post();
csrf_require();

$configuredKey = (string)cfg('maintenance_admin_key', '');
$providedKey = (string)($_POST['access_key'] ?? '');
$rateKey = 'maintenance-admin-access:' . client_ip();

if ($configuredKey === '' || strlen($configuredKey) < 32 || rate_limit_add($rateKey, 900) > 5
    || $providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit('Administrator access denied.');
}

rate_limit_reset($rateKey);
$_SESSION['maintenance_admin_access_until'] = time() + 600;
session_regenerate_id(true);
redirect('admin_login.php');
