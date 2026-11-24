<?php
require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['happypet_user_id'])) {
    $db = HappyPetDB::getInstance();
    $uid = (int)$_SESSION['happypet_user_id'];
    $db->logActivity($uid, 'logout', 'User keluar (logout) dari sesi HappyPet.', 'auth', (string)$uid);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

header('Location: login.php?logged_out=1');
exit;
