<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_BASE . '/admin/login.php');
}

require_csrf();
$_SESSION = [];
session_regenerate_id(true);
session_destroy();
redirect(APP_BASE . '/admin/login.php');
