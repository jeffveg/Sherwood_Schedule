<?php
/**
 * Admin — Add-ons management.
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

// Load attractions for the "applicable to" selector
$all_attractions = $db->query('SELECT id, name FROM attractions WHERE active = 1 ORDER BY sort_order')->fetchAll();

// ── POST Actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_addon') {
        $aid         = (int)($_POST['addon_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $p_type      = $_POST['pricing_type'] ?? 'flat';
        $price       = (float)($_POST['price'] ?? 0);
        $min_charge  = $_POST['min_charge'] !== '' ? (float)$_POST['min_charge'] : null;
        $is_taxable  = (int)!empty($_POST['is_taxable']);
        $sort_order  = (int)($_POST['sort_order'] ?? 0);
        $active      = (int)!empty($_POST['active']);

        // applicable_attractions: null means all, otherwise JSON array of IDs
        $applies_ids = $_POST['applicable_ids'] ?? [];
        $applicable  = null;
        if (!empty($applies_ids) && !in_array('all', $applies_ids, true)) {
            $applicable = json_encode(array_map('intval', $applies_ids));
        }

        if (!$name)                                          $errors[] = 'Name is required.';
        if (!in_array($p_type, ['flat','per_hour'], true))   $errors[] = 'Invalid pricing type.';
        if ($price <= 0)                                     $errors[] = 'Price must be > 0.';

        if (!$errors) {
            if ($aid) {
                $db->prepare(
                    'UPDATE addons SET name=?, description=?, pricing_type=?, price=?, min_charge=?,
                     is_taxable=?, applicable_attractions=?, sort_order=?, active=? WHERE id=?'
                )->execute([$name, $description ?: null, $p_type, $price, $min_charge,
                             $is_taxable, $applicable, $sort_order, $active, $aid]);
                $flash = 'Add-on updated.';
            } else {
                $db->prepare(
                    'INSERT INTO addons (name, description, pricing_type, price, min_charge,
                     is_taxable, applicable_attractions, sort_order, active)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$name, $description ?: null, $p_type, $price, $min_charge,
                             $is_taxable, $applicable, $sort_order, $active]);
                $flash = 'Add-on created.';
            }
        } else {
            $flash_type = 'danger';
            $flash = implode(' ', $errors);
        }
    }

    elseif ($action === 'toggle') {
        $aid = (int)($_POST['addon_id'] ?? 0);
        $db->prepare('UPDATE addons SET active = NOT active WHERE id = ?')->execute([$aid]);
        $flash = 'Add-on updated.';
    }
}

// Load addons
$addons = $db->query(
    'SELECT * FROM addons ORDER BY sort_order ASC, id ASC'
)->fetchAll();

// Pre-load editing addon
$editing = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($addons as $a) {
        if ($a['id'] === $eid) { $editing = $a; break; }
    }
}

// Decode applicable_attractions for editing
$editing_applies = [];
if ($editing && $editing['applicable_attractions']) {
    $editing_applies = json_decode($editing['applicable_attractions'], true) ?? [];
}

render_admin_header('Add-ons', 'addons');
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash_type ?> mb-3"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Form -->
<div class="admin-panel mb-4">
    <div class="admin-panel__header">
        <?= $editing ? 'Edit: ' . htmlspecialchars($editing['name']) : 'Add New Add-on' ?>
    </div>
    <div class="admin-panel__body">
        <form method="POST">
            <input type="hidden" name="action" value="save_addon">
            <input type="hidden" name="addon_id" value="<?= $editing['id'] ?? 0 ?>">

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
                <label class="form-label">Description</label>
                <textarea name="description" class="form-input" rows="2"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Pricing Type</label>
                    <select name="pricing_type" class="form-input" id="pricingType">
                        <option value="flat"     <?= ($editing['pricing_type'] ?? '') === 'flat'     ? 'selected' : '' ?>>Flat fee</option>
                        <option value="per_hour" <?= ($editing['pricing_type'] ?? '') === 'per_hour' ? 'selected' : '' ?>>Per hour</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Price ($)</label>
                    <input type="number" name="price" class="form-input" step="0.01" min="0" required
                           value="<?= htmlspecialchars($editing['price'] ?? '') ?>">
                </div>
                <div class="form-group" id="minChargeGroup" style="<?= ($editing['pricing_type'] ?? 'flat') !== 'per_hour' ? 'display:none;' : '' ?>">
                    <label class="form-label">Minimum Charge ($) <span class="text-dim">optional</span></label>
                    <input type="number" name="min_charge" class="form-input" step="0.01" min="0"
                           value="<?= htmlspecialchars($editing['min_charge'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="check-group">
                        <input type="checkbox" name="is_taxable" value="1"
                               <?= ($editing === null || $editing['is_taxable']) ? 'checked' : '' ?>>
                        <span>Taxable</span>
                    </label>
                    <p class="form-hint">Uncheck for non-taxable items (e.g. services, not merchandise)</p>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Available For</label>
                <div class="radio-pill-group" style="flex-direction:column;align-items:flex-start;">
                    <label class="radio-pill" style="border-radius:var(--radius-sm);">
                        <input type="checkbox" name="applicable_ids[]" value="all"
                               <?= empty($editing_applies) ? 'checked' : '' ?>
                               onchange="toggleAttrCheckboxes(this)">
                        All Attractions
                    </label>
                    <?php foreach ($all_attractions as $attr): ?>
                    <label class="radio-pill" style="border-radius:var(--radius-sm);">
                        <input type="checkbox" name="applicable_ids[]" value="<?= $attr['id'] ?>"
                               class="attr-check"
                               <?= in_array($attr['id'], $editing_applies, false) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($attr['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="form-hint">If only certain attractions should offer this add-on, uncheck "All" and pick specific ones.</p>
            </div>

            <div class="d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                <?php if ($editing): ?>
                <a href="<?= APP_URL ?>/admin/addons.php" class="btn btn-ghost btn-sm">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Add-on List -->
<?php if ($addons): ?>
<div class="card" style="cursor:default;overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Type</th>
                <th>Price</th>
                <th>Taxable</th>
                <th>Applies To</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($addons as $addon): ?>
            <?php
            $applies_label = 'All';
            if ($addon['applicable_attractions']) {
                $ids = json_decode($addon['applicable_attractions'], true) ?? [];
                $names = [];
                foreach ($all_attractions as $attr) {
                    if (in_array($attr['id'], $ids, false)) $names[] = $attr['name'];
                }
                $applies_label = $names ? implode(', ', $names) : 'Custom';
            }
            ?>
            <tr <?= !$addon['active'] ? 'style="opacity:0.5;"' : '' ?>>
                <td class="text-dim"><?= $addon['sort_order'] ?></td>
                <td>
                    <strong><?= htmlspecialchars($addon['name']) ?></strong>
                    <?php if ($addon['description']): ?>
                    <span class="text-dim d-block text-xs"><?= htmlspecialchars(mb_strimwidth($addon['description'], 0, 60, '…')) ?></span>
                    <?php endif; ?>
                </td>
                <td class="text-sm"><?= $addon['pricing_type'] === 'per_hour' ? 'Per Hour' : 'Flat' ?></td>
                <td>
                    $<?= number_format($addon['price'], 2) ?>
                    <?= $addon['pricing_type'] === 'per_hour' ? '/hr' : '' ?>
                    <?php if ($addon['min_charge']): ?>
                    <span class="text-dim text-xs d-block">min $<?= number_format($addon['min_charge'], 2) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $addon['is_taxable'] ? '<span class="badge badge-info">Yes</span>' : '<span class="badge badge-default">No</span>' ?></td>
                <td class="text-sm text-dim"><?= htmlspecialchars($applies_label) ?></td>
                <td><?= $addon['active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-default">Inactive</span>' ?></td>
                <td class="actions">
                    <a href="?edit=<?= $addon['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="addon_id" value="<?= $addon['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">
                            <?= $addon['active'] ? 'Disable' : 'Enable' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="alert alert-info">No add-ons yet.</div>
<?php endif; ?>

<script>
const pricingType   = document.getElementById('pricingType');
const minChargeGroup = document.getElementById('minChargeGroup');

pricingType.addEventListener('change', function () {
    minChargeGroup.style.display = this.value === 'per_hour' ? '' : 'none';
});

function toggleAttrCheckboxes(allCheckbox) {
    const checks = document.querySelectorAll('.attr-check');
    if (allCheckbox.checked) {
        checks.forEach(c => { c.checked = false; c.disabled = true; });
    } else {
        checks.forEach(c => { c.disabled = false; });
    }
}

// Init on load
const allCheck = document.querySelector('input[value="all"]');
if (allCheck && allCheck.checked) {
    document.querySelectorAll('.attr-check').forEach(c => { c.disabled = true; });
}
</script>

<?php render_admin_footer(); ?>
