<?php
/**
 * Shared HTML layout helpers.
 * All pages call render_header() at top and render_footer() at bottom.
 */

function render_header(string $title, string $active_nav = ''): void {
    // ── Security headers (must be sent before body output) ────────────────
    // X-Frame-Options: block this page being embedded in an iframe (clickjacking).
    header('X-Frame-Options: SAMEORIGIN');
    // X-Content-Type-Options: prevent browsers from MIME-sniffing the response.
    header('X-Content-Type-Options: nosniff');
    // Referrer-Policy: only send the origin (no path) on cross-origin requests.
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $app_url  = defined('APP_URL') ? APP_URL : '';
    $app_name = defined('APP_NAME') ? APP_NAME : 'Sherwood Adventure';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> — <?= h($app_name) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="https://sherwoodadventure.com/css/brand.css">
    <link rel="stylesheet" href="<?= $app_url ?>/assets/css/sherwood.css">
    <link rel="icon" type="image/png" href="https://sherwoodadventure.com/images/logo.png">
</head>
<body>

<header class="site-header">
    <div class="container">
        <div class="site-header__inner">
            <a href="<?= $app_url ?>/" class="site-logo">
                <img src="https://sherwoodadventure.com/images/logo.png"
                     alt="Sherwood Adventure">
            </a>
            <nav class="site-nav">
                <a href="<?= $app_url ?>/"
                   class="<?= $active_nav === 'book' ? 'active' : '' ?>">Book Now</a>
                <a href="<?= $app_url ?>/booking/my-booking.php"
                   class="<?= $active_nav === 'lookup' ? 'active' : '' ?>">My Booking</a>
                <a href="https://sherwoodadventure.com" target="_blank" rel="noopener">Main Site</a>
            </nav>
        </div>
    </div>
</header>

<main>
<?php
}

function render_footer(): void {
    $year = date('Y');
    ?>
</main>

<footer class="site-footer">
    <div class="container">
        <p>&copy; <?= $year ?> Sherwood Adventure &mdash; All rights reserved.</p>
        <p class="text-xs mt-1">
            Questions? <a href="https://sherwoodadventure.com/contact.html">Contact us</a>
        </p>
    </div>
</footer>

</body>
</html>
<?php
}

/**
 * Render the admin layout header (sidebar nav + content area open tag).
 * Call render_admin_footer() to close.
 */
function render_admin_header(string $title, string $active_nav = ''): void {
    // ── Security headers ──────────────────────────────────────────────────
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $app_url = defined('APP_URL') ? APP_URL : '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> — Sherwood Admin</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="https://sherwoodadventure.com/css/brand.css">
    <link rel="stylesheet" href="<?= $app_url ?>/assets/css/sherwood.css">
</head>
<body>

<header class="site-header">
    <div class="container">
        <div class="site-header__inner">
            <a href="<?= $app_url ?>/admin/" class="site-logo">
                <img src="https://sherwoodadventure.com/images/logo.png"
                     alt="Sherwood Adventure" style="height:40px;">
                <div class="site-logo__tagline" style="font-size:0.75rem;letter-spacing:0.08em;color:var(--gold);text-transform:uppercase;">Admin Panel</div>
            </a>
            <nav class="site-nav">
                <a href="<?= $app_url ?>/" target="_blank">Booking Site</a>
                <a href="<?= $app_url ?>/admin/logout.php">Log Out</a>
            </nav>
        </div>
    </div>
</header>

<div class="admin-layout">
    <aside class="admin-sidebar">
        <div class="admin-sidebar__section">Bookings</div>
        <a href="<?= $app_url ?>/admin/"
           class="<?= $active_nav === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="<?= $app_url ?>/admin/bookings.php"
           class="<?= $active_nav === 'bookings' ? 'active' : '' ?>">All Bookings</a>
        <a href="<?= $app_url ?>/admin/booking-new.php"
           class="<?= $active_nav === 'new-booking' ? 'active' : '' ?>">New Booking</a>
        <a href="<?= $app_url ?>/admin/calendar.php"
           class="<?= $active_nav === 'calendar' ? 'active' : '' ?>">Calendar</a>
        <a href="<?= $app_url ?>/admin/customers.php"
           class="<?= $active_nav === 'customers' ? 'active' : '' ?>">Customers</a>

        <div class="admin-sidebar__section">Catalog</div>
        <a href="<?= $app_url ?>/admin/attractions.php"
           class="<?= $active_nav === 'attractions' ? 'active' : '' ?>">Attractions</a>
        <a href="<?= $app_url ?>/admin/addons.php"
           class="<?= $active_nav === 'addons' ? 'active' : '' ?>">Add-ons</a>
        <a href="<?= $app_url ?>/admin/coupons.php"
           class="<?= $active_nav === 'coupons' ? 'active' : '' ?>">Coupons</a>

        <div class="admin-sidebar__section">System</div>
        <a href="<?= $app_url ?>/admin/availability.php"
           class="<?= $active_nav === 'availability' ? 'active' : '' ?>">Availability</a>
        <a href="<?= $app_url ?>/admin/reports.php"
           class="<?= $active_nav === 'reports' ? 'active' : '' ?>">Reports</a>
        <a href="<?= $app_url ?>/admin/webhooks.php"
           class="<?= $active_nav === 'webhooks' ? 'active' : '' ?>">Webhook Log</a>
        <a href="<?= $app_url ?>/admin/email-log.php"
           class="<?= $active_nav === 'email-log' ? 'active' : '' ?>">Email Log</a>
        <a href="<?= $app_url ?>/admin/settings.php"
           class="<?= $active_nav === 'settings' ? 'active' : '' ?>">Settings</a>
    </aside>

    <div class="admin-content">
        <h1 class="page-title"><?= h($title) ?></h1>
<?php
}

function render_admin_footer(): void {
    $year = date('Y');
    ?>
    </div><!-- /.admin-content -->
</div><!-- /.admin-layout -->

<footer class="site-footer">
    <div class="container">
        <p>&copy; <?= $year ?> Sherwood Adventure &mdash; Admin Panel</p>
    </div>
</footer>

</body>
</html>
<?php
}

/**
 * Render the wizard progress bar.
 *
 * @param int   $current_step  1-based current step number
 * @param array $steps         ['Label', 'Label', ...] — one per step
 */
function render_wizard_progress(int $current_step, array $steps): void {
    ?>
    <div class="wizard-progress" role="list" aria-label="Booking steps">
    <?php foreach ($steps as $i => $label):
        $step_num = $i + 1;
        $class = 'wizard-step';
        if ($step_num < $current_step)  $class .= ' completed';
        if ($step_num === $current_step) $class .= ' active';
        ?>
        <div class="<?= $class ?>" role="listitem">
            <div class="wizard-step__dot" aria-hidden="true">
                <?php if ($step_num < $current_step): ?>&#10003;
                <?php else: ?><?= $step_num ?><?php endif; ?>
            </div>
            <div class="wizard-step__label"><?= h($label) ?></div>
        </div>
    <?php endforeach; ?>
    </div>
<?php
}

/**
 * HTML-escape a value for safe output.
 */
function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
