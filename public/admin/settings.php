<?php
/**
 * Admin — Settings
 * Edit key-value settings, tax rates, and travel fee config.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

admin_require_login();
date_default_timezone_set(APP_TIMEZONE);

$db    = get_db();
$flash = '';
$flash_type = 'success';

// ── POST Actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // General settings
    if ($action === 'settings') {
        $keys = ['balance_reminder_days', 'booking_ref_prefix',
                 'terms_travel_fee', 'terms_cancellation',
                 'terms_rescheduling', 'terms_park_fees'];
        foreach ($keys as $key) {
            $val = trim($_POST[$key] ?? '');
            $db->prepare(
                'INSERT INTO settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            )->execute([$key, $val]);
        }
        $flash = 'Settings saved.';
    }

    // Tax rates
    elseif ($action === 'tax') {
        $ids    = $_POST['tax_id']    ?? [];
        $labels = $_POST['tax_label'] ?? [];
        $rates  = $_POST['tax_rate']  ?? [];

        foreach ($ids as $i => $tid) {
            $label = trim($labels[$i] ?? '');
            $rate  = (float)($rates[$i] ?? 0);
            if ($tid && $label) {
                $db->prepare('UPDATE tax_config SET label = ?, rate = ? WHERE id = ?')
                   ->execute([$label, $rate, (int)$tid]);
            }
        }

        // New row
        $new_label = trim($_POST['new_tax_label'] ?? '');
        $new_rate  = (float)($_POST['new_tax_rate'] ?? 0);
        if ($new_label && $new_rate > 0) {
            $max_order = $db->query('SELECT MAX(sort_order) FROM tax_config')->fetchColumn();
            $db->prepare('INSERT INTO tax_config (label, rate, sort_order) VALUES (?, ?, ?)')
               ->execute([$new_label, $new_rate, (int)$max_order + 1]);
        }
        $flash = 'Tax rates saved.';
    }

    // Delete tax row
    elseif ($action === 'delete_tax') {
        $tid = (int)($_POST['tax_id'] ?? 0);
        $db->prepare('DELETE FROM tax_config WHERE id = ?')->execute([$tid]);
        $flash = 'Tax rate deleted.';
    }

    // Travel fee config
    elseif ($action === 'travel') {
        $db->prepare(
            'UPDATE travel_fee_config SET base_address = ?, free_miles_threshold = ?, rate_per_mile = ? WHERE id = 1'
        )->execute([
            trim($_POST['base_address'] ?? ''),
            (int)($_POST['free_miles_threshold'] ?? 50),
            (float)($_POST['rate_per_mile'] ?? 1.00),
        ]);
        $flash = 'Travel fee config saved.';
    }
}

// Load data
$settings_rows = $db->query('SELECT * FROM settings ORDER BY setting_key')->fetchAll();
$settings = [];
foreach ($settings_rows as $row) { $settings[$row['setting_key']] = $row['setting_value']; }

$tax_rates = $db->query('SELECT * FROM tax_config ORDER BY sort_order')->fetchAll();
$travel    = $db->query('SELECT * FROM travel_fee_config WHERE id = 1')->fetch();

$total_tax = array_sum(array_column($tax_rates, 'rate')) * 100;

render_admin_header('Settings', 'settings');
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash_type ?> mb-3"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- General Settings -->
<div class="admin-panel mb-4">
    <div class="admin-panel__header">General Settings</div>
    <div class="admin-panel__body">
        <form method="POST">
            <input type="hidden" name="action" value="settings">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Booking Ref Prefix</label>
                    <input type="text" name="booking_ref_prefix" class="form-input"
                           value="<?= htmlspecialchars($settings['booking_ref_prefix'] ?? 'SA') ?>">
                    <p class="form-hint">e.g. SA → SA-2025-001</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Balance Reminder (days before event)</label>
                    <input type="number" name="balance_reminder_days" class="form-input" min="1"
                           value="<?= htmlspecialchars($settings['balance_reminder_days'] ?? '7') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Travel Fee Terms</label>
                <textarea name="terms_travel_fee" class="form-input" rows="3"><?= htmlspecialchars($settings['terms_travel_fee'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Cancellation Policy</label>
                <textarea name="terms_cancellation" class="form-input" rows="4"><?= htmlspecialchars($settings['terms_cancellation'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Rescheduling Policy</label>
                <textarea name="terms_rescheduling" class="form-input" rows="4"><?= htmlspecialchars($settings['terms_rescheduling'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Park Fees Disclaimer</label>
                <textarea name="terms_park_fees" class="form-input" rows="3"><?= htmlspecialchars($settings['terms_park_fees'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Save Settings</button>
        </form>
    </div>
</div>

<!-- Tax Rates -->
<div class="admin-panel mb-4">
    <div class="admin-panel__header">
        Tax Rates
        <span class="text-dim" style="font-size:0.8rem;font-weight:400;float:right;">
            Total: <?= number_format($total_tax, 4) ?>%
        </span>
    </div>
    <div class="admin-panel__body">
        <form method="POST">
            <input type="hidden" name="action" value="tax">
            <table class="detail-table" style="margin-bottom:1rem;">
                <thead>
                    <tr><th>Label</th><th>Rate (%)</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($tax_rates as $t): ?>
                <tr>
                    <td>
                        <input type="hidden" name="tax_id[]" value="<?= $t['id'] ?>">
                        <input type="text" name="tax_label[]" class="form-input form-input--sm"
                               value="<?= htmlspecialchars($t['label']) ?>">
                    </td>
                    <td>
                        <input type="number" name="tax_rate[]" class="form-input form-input--sm"
                               step="0.0001" min="0" max="1"
                               value="<?= number_format($t['rate'], 4) ?>">
                        <span class="text-dim text-xs">(e.g. 0.0560 = 5.60%)</span>
                    </td>
                    <td>
                        <button type="submit" form="deleteTax<?= $t['id'] ?>" class="btn btn-danger btn-sm"
                                onclick="return confirm('Delete this tax rate?')">×</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <!-- New row -->
                <tr>
                    <td>
                        <input type="hidden" name="tax_id[]" value="">
                        <input type="text" name="new_tax_label" class="form-input form-input--sm"
                               placeholder="New rate label">
                    </td>
                    <td>
                        <input type="number" name="new_tax_rate" class="form-input form-input--sm"
                               step="0.0001" min="0" max="1" placeholder="0.0000">
                    </td>
                    <td></td>
                </tr>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary btn-sm">Save Tax Rates</button>
        </form>

        <!-- Separate delete forms (outside the main form) -->
        <?php foreach ($tax_rates as $t): ?>
        <form id="deleteTax<?= $t['id'] ?>" method="POST" style="display:none;">
            <input type="hidden" name="action" value="delete_tax">
            <input type="hidden" name="tax_id" value="<?= $t['id'] ?>">
        </form>
        <?php endforeach; ?>
    </div>
</div>

<!-- Travel Fee Config -->
<div class="admin-panel mb-4">
    <div class="admin-panel__header">Travel Fee Configuration</div>
    <div class="admin-panel__body">
        <form method="POST">
            <input type="hidden" name="action" value="travel">
            <div class="form-group">
                <label class="form-label">Base Address <span class="text-dim">(your Goodyear address)</span></label>
                <input type="text" name="base_address" class="form-input"
                       value="<?= htmlspecialchars($travel['base_address'] ?? '') ?>">
                <p class="form-hint">Used as the origin for distance calculations via Google Maps.</p>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Free Miles Threshold</label>
                    <input type="number" name="free_miles_threshold" class="form-input" min="0"
                           value="<?= htmlspecialchars($travel['free_miles_threshold'] ?? 50) ?>">
                    <p class="form-hint">No travel fee within this distance (one-way miles).</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Rate per Mile ($)</label>
                    <input type="number" name="rate_per_mile" class="form-input" step="0.01" min="0"
                           value="<?= htmlspecialchars($travel['rate_per_mile'] ?? '1.00') ?>">
                    <p class="form-hint">Applied to miles beyond the free threshold.</p>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Save Travel Config</button>
        </form>
    </div>
</div>

<?php render_admin_footer(); ?>
