<?php
/**
 * Admin — Attractions management.
 * Edit name, description, pricing, deposit, and active status.
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
$errors = [];

// ── POST Actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Save attraction details + pricing
    if ($action === 'save_attraction') {
        $aid         = (int)($_POST['attraction_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $min_hours   = (float)($_POST['min_hours'] ?? 2.0);
        $hour_inc    = (float)($_POST['hour_increment'] ?? 1.0);
        $deposit     = (float)($_POST['deposit_amount'] ?? 0);
        $sort_order  = (int)($_POST['sort_order'] ?? 0);
        $active      = (int)!empty($_POST['active']);

        if (!$name) $errors[] = 'Name is required.';

        if (!$errors && $aid) {
            $db->prepare(
                'UPDATE attractions SET name=?, description=?, min_hours=?, hour_increment=?,
                 deposit_amount=?, sort_order=?, active=? WHERE id=?'
            )->execute([$name, $description, $min_hours, $hour_inc, $deposit, $sort_order, $active, $aid]);
            $flash = 'Attraction saved.';
        } elseif (!$errors) {
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
            $db->prepare(
                'INSERT INTO attractions (name, slug, description, min_hours, hour_increment, deposit_amount, sort_order, active)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$name, $slug, $description, $min_hours, $hour_inc, $deposit, $sort_order, $active]);
            $aid   = (int)$db->lastInsertId();
            $flash = 'Attraction created.';
        } else {
            $flash_type = 'danger';
            $flash = implode(' ', $errors);
        }

        // Save pricing row
        if (!$errors && $aid) {
            $base_hours  = (float)($_POST['base_hours'] ?? $min_hours);
            $base_price  = (float)($_POST['base_price'] ?? 0);
            $hourly_rate = (float)($_POST['additional_hourly_rate'] ?? 0);
            $pid         = (int)($_POST['pricing_id'] ?? 0);
            if ($pid) {
                $db->prepare(
                    'UPDATE attraction_pricing SET base_hours=?, base_price=?, additional_hourly_rate=? WHERE id=?'
                )->execute([$base_hours, $base_price, $hourly_rate, $pid]);
            } else {
                $db->prepare(
                    'INSERT INTO attraction_pricing (attraction_id, base_hours, base_price, additional_hourly_rate)
                     VALUES (?,?,?,?)'
                )->execute([$aid, $base_hours, $base_price, $hourly_rate]);
            }
        }
    }

    // Toggle active
    elseif ($action === 'toggle') {
        $aid = (int)($_POST['attraction_id'] ?? 0);
        $db->prepare('UPDATE attractions SET active = NOT active WHERE id = ?')->execute([$aid]);
        $flash = 'Attraction updated.';
    }
}

// Load all attractions with their pricing
$attractions = $db->query(
    'SELECT a.*, p.id AS pricing_id, p.base_hours, p.base_price, p.additional_hourly_rate
     FROM attractions a
     LEFT JOIN attraction_pricing p ON p.attraction_id = a.id AND p.active = 1
     ORDER BY a.sort_order ASC, a.id ASC'
)->fetchAll();

// Group by attraction id (in case of multiple pricing rows)
$attr_map = [];
foreach ($attractions as $row) {
    $attr_map[$row['id']] = $row;
}
$attractions = array_values($attr_map);

render_admin_header('Attractions', 'attractions');

// If editing, pre-load the form
$editing = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($attractions as $a) {
        if ($a['id'] === $eid) { $editing = $a; break; }
    }
}
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash_type ?> mb-3"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Edit / Add Form -->
<div class="admin-panel mb-4">
    <div class="admin-panel__header">
        <?= $editing ? 'Edit: ' . htmlspecialchars($editing['name']) : 'Add Attraction' ?>
    </div>
    <div class="admin-panel__body">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_attraction">
            <input type="hidden" name="attraction_id" value="<?= $editing['id'] ?? 0 ?>">
            <input type="hidden" name="pricing_id"    value="<?= $editing['pricing_id'] ?? 0 ?>">

            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-input" required
                           value="<?= htmlspecialchars($editing['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="form-input" min="0"
                           value="<?= htmlspecialchars($editing['sort_order'] ?? 0) ?>">
                </div>
                <div class="form-group" style="align-self:flex-end;padding-bottom:0.6rem;">
                    <label class="check-group">
                        <input type="checkbox" name="active" value="1"
                               <?= ($editing === null || $editing['active']) ? 'checked' : '' ?>>
                        <span>Active</span>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description <span class="text-dim">(shown on booking step 1)</span></label>
                <textarea name="description" class="form-input" rows="3"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Minimum Hours</label>
                    <input type="number" name="min_hours" class="form-input" step="0.5" min="0.5"
                           value="<?= htmlspecialchars($editing['min_hours'] ?? 2.0) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Hour Increment</label>
                    <input type="number" name="hour_increment" class="form-input" step="0.5" min="0.5"
                           value="<?= htmlspecialchars($editing['hour_increment'] ?? 1.0) ?>">
                    <p class="form-hint">e.g. 1.0 = can add 1 hr at a time</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Deposit Amount ($)</label>
                    <input type="number" name="deposit_amount" class="form-input" step="0.01" min="0"
                           value="<?= htmlspecialchars($editing['deposit_amount'] ?? 0) ?>">
                </div>
            </div>

            <p class="text-orange" style="font-size:0.78rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:0.5rem;">
                Pricing
                <span class="text-dim" style="font-size:0.72rem;text-transform:none;letter-spacing:0;font-weight:normal;">
                    — changes apply to new bookings only; existing bookings keep their original prices
                </span>
            </p>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Base Hours</label>
                    <input type="number" name="base_hours" class="form-input" step="0.5" min="0.5"
                           value="<?= htmlspecialchars($editing['base_hours'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Base Price ($)</label>
                    <input type="number" name="base_price" class="form-input" step="0.01" min="0"
                           value="<?= htmlspecialchars($editing['base_price'] ?? '') ?>">
                    <p class="form-hint">Price for base hours</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Additional Hourly Rate ($)</label>
                    <input type="number" name="additional_hourly_rate" class="form-input" step="0.01" min="0"
                           value="<?= htmlspecialchars($editing['additional_hourly_rate'] ?? '') ?>">
                    <p class="form-hint">Per hour beyond base</p>
                </div>
            </div>

            <div class="d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                <?php if ($editing): ?>
                <a href="<?= APP_URL ?>/admin/attractions.php" class="btn btn-ghost btn-sm">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Attraction List -->
<?php if ($attractions): ?>
<div class="card" style="cursor:default;overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Min Hrs</th>
                <th>Deposit</th>
                <th>Base Price</th>
                <th>+/hr</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($attractions as $a): ?>
            <tr <?= !$a['active'] ? 'style="opacity:0.5;"' : '' ?>>
                <td class="text-dim"><?= $a['sort_order'] ?></td>
                <td>
                    <strong><?= htmlspecialchars($a['name']) ?></strong>
                    <span class="text-dim d-block text-xs">slug: <?= htmlspecialchars($a['slug'] ?? '—') ?></span>
                </td>
                <td><?= $a['min_hours'] ?>h (+<?= $a['hour_increment'] ?>h)</td>
                <td>$<?= number_format($a['deposit_amount'], 2) ?></td>
                <td><?= $a['base_price'] !== null ? '$' . number_format($a['base_price'], 2) . ' / ' . $a['base_hours'] . 'h' : '<span class="text-dim">—</span>' ?></td>
                <td><?= $a['additional_hourly_rate'] !== null ? '$' . number_format($a['additional_hourly_rate'], 2) : '<span class="text-dim">—</span>' ?></td>
                <td><?= $a['active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-default">Inactive</span>' ?></td>
                <td class="actions">
                    <a href="?edit=<?= $a['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                    <form method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="attraction_id" value="<?= $a['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">
                            <?= $a['active'] ? 'Disable' : 'Enable' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php render_admin_footer(); ?>
