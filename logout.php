<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) catat_aktivitas('LOGOUT', 'login', 'Logout');
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: ' . BASE_URL . '/');
exit;