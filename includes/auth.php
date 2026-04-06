<?php
/**
 * Admin authentication and CSRF helpers.
 *
 * All admin session state is stored under the named session 'sa_admin'
 * (separate from the customer booking session 'sa_booking') so the two
 * cannot bleed into each other when both cookies exist in the same browser.
 *
 * Typical usage in every admin page:
 *   require_once __DIR__ . '/../../includes/auth.php';
 *   admin_require_login();   // redirects to login.php if not authenticated
 *
 * CSRF protection:
 *   - Add <?= csrf_field() ?> inside every admin <form method="POST">
 *   - Call csrf_verify() at the top of every POST handler
 */

/**
 * Start (or resume) the admin PHP session with security cookie flags.
 *
 * Uses a custom session name ('sa_admin') to avoid conflicts with the
 * customer-facing booking session ('sa_booking'). Safe to call multiple
 * times — checks session_status() before calling session_start().
 *
 * Cookie flags:
 *   secure   — only sent over HTTPS (no plaintext transmission)
 *   httponly — not accessible to JavaScript (blocks XSS session hijack)
 *   samesite — 'Lax' blocks cross-site POST requests (CSRF mitigation)
 */
function admin_start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,              // session cookie — expires when browser closes
            'path'     => '/',
            'secure'   => true,           // HTTPS only
            'httponly' => true,           // not accessible to JS
            'samesite' => 'Lax',          // blocks cross-site POST (CSRF)
        ]);
        session_name('sa_admin');
        session_start();
    }
}

/**
 * Return true if the current request has an authenticated admin session.
 *
 * Starts the session if not already running so this can be called anywhere
 * without a prior admin_start_session() call.
 */
function admin_is_logged_in(): bool {
    admin_start_session();
    return !empty($_SESSION['admin_id']);
}

/**
 * Gate-keep admin pages — redirect to login if not authenticated.
 *
 * Call this near the top of every admin page, after includes but before
 * any business logic or output. Terminates via exit on redirect so no
 * further code runs for unauthenticated visitors.
 */
function admin_require_login(): void {
    if (!admin_is_logged_in()) {
        header('Location: ' . APP_URL . '/admin/login.php');
        exit;
    }
}

/**
 * Attempt to log in an admin user by username + plain-text password.
 *
 * On success:
 *   - Regenerates the session ID (prevents session-fixation attacks).
 *   - Stores the user's DB id in $_SESSION['admin_id'].
 *   - Updates the admin_users.last_login timestamp.
 *
 * On failure returns false; the caller is responsible for showing the
 * appropriate error message (keep it generic to avoid user enumeration).
 *
 * @param string $username  The admin username (compared against admin_users.username).
 * @param string $password  Plain-text password; verified via password_verify() against
 *                          the bcrypt hash stored in admin_users.password_hash.
 * @return bool             True on successful authentication, false otherwise.
 */
function admin_login(string $username, string $password): bool {
    $db   = get_db();
    $stmt = $db->prepare('SELECT id, password_hash FROM admin_users WHERE username = ? AND active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        admin_start_session();
        session_regenerate_id(true);          // prevent session-fixation
        $_SESSION['admin_id'] = $user['id'];
        $db->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
        return true;
    }
    return false;
}

/**
 * Log out the current admin by destroying their session, then redirect
 * to the login page. Always terminates via exit.
 */
function admin_logout(): void {
    admin_start_session();
    session_destroy();
    header('Location: ' . APP_URL . '/admin/login.php');
    exit;
}

// ── CSRF helpers ──────────────────────────────────────────────────────────────

/**
 * Return (and generate if needed) the CSRF token for the current admin session.
 *
 * The token is a 64-character hex string stored in $_SESSION['csrf_token'].
 * It persists for the lifetime of the admin session (regenerated on login).
 *
 * @return string 64-char hex CSRF token
 */
function csrf_token(): string {
    admin_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Return an HTML hidden input carrying the current CSRF token.
 *
 * Usage — place inside every admin <form method="POST">:
 *   <?= csrf_field() ?>
 */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify the CSRF token submitted with a POST request.
 *
 * Uses hash_equals() for timing-safe comparison to prevent timing attacks.
 * Terminates with HTTP 403 if the token is missing or does not match.
 * Call this at the very top of every admin POST handler.
 */
function csrf_verify(): void {
    $submitted = $_POST['_csrf'] ?? '';
    if (!$submitted || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        error_log('CSRF verification failed — possible cross-site request forgery attempt from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        exit('Request verification failed. Please go back and try again.');
    }
}
