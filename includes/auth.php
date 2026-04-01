<?php
/**
 * Admin session management
 */
function admin_start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('sa_admin');
        session_start();
    }
}

function admin_is_logged_in(): bool {
    admin_start_session();
    return !empty($_SESSION['admin_id']);
}

function admin_require_login(): void {
    if (!admin_is_logged_in()) {
        header('Location: ' . APP_URL . '/admin/login.php');
        exit;
    }
}

function admin_login(string $username, string $password): bool {
    $db   = get_db();
    $stmt = $db->prepare('SELECT id, password_hash FROM admin_users WHERE username = ? AND active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        admin_start_session();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $user['id'];
        $db->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
        return true;
    }
    return false;
}

function admin_logout(): void {
    admin_start_session();
    session_destroy();
    header('Location: ' . APP_URL . '/admin/login.php');
    exit;
}
