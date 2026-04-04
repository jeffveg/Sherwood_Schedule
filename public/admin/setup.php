<?php
/**
 * One-time admin user setup.
 * Creates the first admin account when no admin users exist.
 * DELETE THIS FILE after creating your admin account.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';

$db = get_db();

// Block if admin users already exist
$count = (int)$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
if ($count > 0) {
    http_response_code(403);
    die('<h2>Setup already complete.</h2><p>Delete this file from the server.</p>');
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username     = trim($_POST['username'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirm      = $_POST['confirm'] ?? '';

    if (!$username)                     $errors[] = 'Username is required.';
    if (!$display_name)                 $errors[] = 'Display name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
    if (strlen($password) < 8)          $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)         $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare(
            'INSERT INTO admin_users (username, password_hash, display_name, email) VALUES (?, ?, ?, ?)'
        )->execute([$username, $hash, $display_name, $email]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Setup — Sherwood Adventure</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/sherwood.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;">

<div style="width:100%;max-width:480px;padding:1.5rem;">
    <div style="text-align:center;margin-bottom:2rem;">
        <img src="https://sherwoodadventure.com/images/6/logo_466608_print-1--500.png"
             alt="Sherwood Adventure" style="height:60px;display:inline-block;">
        <p style="font-family:var(--font-heading);font-size:1.4rem;color:var(--gold);margin:0.75rem 0 0.25rem;">First-Time Setup</p>
        <p style="color:var(--text-dim);font-size:0.85rem;">Create your admin account</p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        Admin account created! <a href="<?= APP_URL ?>/admin/login.php">Sign in</a> and then
        <strong>delete this file from the server</strong>.
    </div>
    <?php else: ?>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <div class="card" style="cursor:default;">
        <div class="card__body">
            <form method="POST">
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-input"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="display_name">Display Name</label>
                    <input type="text" id="display_name" name="display_name" class="form-input"
                           value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-input"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-input"
                           minlength="8" required>
                    <p class="form-hint">Minimum 8 characters</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm">Confirm Password</label>
                    <input type="password" id="confirm" name="confirm" class="form-input" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block mt-3">Create Admin Account</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

</body>
</html>
