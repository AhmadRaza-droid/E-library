<?php
require_once __DIR__ . '/session_init.php';
include 'config.php';

require_admin($conn);

// Keep the legacy URL, but use the single inbox with reply and status controls.
header('Location: admin_dashboard.php?page=messages');
exit;
