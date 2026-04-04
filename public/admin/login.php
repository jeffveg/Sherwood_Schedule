<?php
/**
 * Admin login page.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Already logged in → go to dashboard
if (admin_is_logged_in()) {
    header('Location: ' . APP_URL . '/admin/');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password && admin_login($username, $password)) {
        header('Location: ' . APP_URL . '/admin/');
        exit;
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Sherwood Adventure</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/sherwood.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;">

<div style="width:100%;max-width:400px;padding:1.5rem;">
    <div style="text-align:center;margin-bottom:2rem;">
        <img src="https://sherwoodadventure.com/images/6/logo_466608_print-1--500.png"
             alt="Sherwood Adventure" style="height:60px;display:inline-block;">
        <p style="font-family:var(--font-heading);font-size:1.4rem;color:var(--gold);margin:0.75rem 0 0.25rem;">Admin Panel</p>
        <p style="color:var(--text-dim);font-size:0.85rem;">Sign in to manage bookings</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card" style="cursor:default;">
        <div class="card__body">
            <form method="POST" autocomplete="on">
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-input"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           autocomplete="username" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-input"
                           autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block mt-3">Sign In</button>
            </form>
        </div>
    </div>
</div>

</body>
</html>
