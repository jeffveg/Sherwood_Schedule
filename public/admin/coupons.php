<?php
/**
 * Admin — Coupons management (list, add, toggle, delete).
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
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $code        = strtoupper(trim($_POST['code'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $dtype       = $_POST['discount_type'] ?? '';
        $amount      = (float)($_POST['discount_amount'] ?? 0);
        $applies_to  = $_POST['applies_to'] ?? 'attraction';
        $nonprofit   = (int)!empty($_POST['nonprofit_only']);
        $max_uses    = $_POST['max_uses'] !== '' ? (int)$_POST['max_uses'] : null;
        $expires_at  = $_POST['expires_at'] ?: null;

        if (!$code)                                    $errors[] = 'Code is required.';
        if (!in_array($dtype, ['percent','flat'], true)) $errors[] = 'Invalid discount type.';
        if ($amount <= 0)                              $errors[] = 'Discount amount must be > 0.';
        if (!in_array($applies_to, ['attraction','addons','both'], true)) $errors[] = 'Invalid applies-to.';

        // Check duplicate
        if (!$errors) {
            $dup = $db->prepare('SELECT id FROM coupons WHERE code = ?');
            $dup->execute([$code]);
            if ($dup->fetch()) $errors[] = "Code '{$code}' already exists.";
        }

        if (!$errors) {
            $db->prepare(
                'INSERT INTO coupons (code, description, discount_type, discount_amount, applies_to,
                 nonprofit_only, max_uses, expires_at) VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$code, $description ?: null, $dtype, $amount, $applies_to, $nonprofit, $max_uses, $expires_at]);
            $flash = "Coupon {$code} created.";
        } else {
            $flash      = implode(' ', $errors);
            $flash_type = 'danger';
        }
    }

    elseif ($action === 'toggle') {
        $cid = (int)($_POST['coupon_id'] ?? 0);
        $db->prepare('UPDATE coupons SET active = NOT active WHERE id = ?')->execute([$cid]);
        $flash = 'Coupon updated.';
    }

    elseif ($action === 'delete') {
        $cid = (int)($_POST['coupon_id'] ?? 0);
        if ($cid) {
            $db->prepare('DELETE FROM coupons WHERE id = ?')->execute([$cid]);
            $flash = 'Coupon deleted.';
        }
    }

    elseif ($action === 'edit') {
        $cid         = (int)($_POST['coupon_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $dtype       = $_POST['discount_type'] ?? '';
        $amount      = (float)($_POST['discount_amount'] ?? 0);
        $applies_to  = $_POST['applies_to'] ?? 'attraction';
        $nonprofit   = (int)!empty($_POST['nonprofit_only']);
        $max_uses    = (($_POST['max_uses'] ?? '') !== '') ? (int)$_POST['max_uses'] : null;
        $expires_at  = $_POST['expires_at'] ?: null;

        if (!in_array($dtype, ['percent','flat'], true)) $errors[] = 'Invalid discount type.';
        if ($amount <= 0)                                $errors[] = 'Discount amount must be > 0.';
        if (!in_array($applies_to, ['attraction','addons','both'], true)) $errors[] = 'Invalid applies-to.';

        if (!$errors && $cid) {
            $db->prepare(
                'UPDATE coupons SET description = ?, discount_type = ?, discount_amount = ?,
                 applies_to = ?, nonprofit_only = ?, max_uses = ?, expires_at = ?
                 WHERE id = ?'
            )->execute([$description ?: null, $dtype, $amount, $applies_to,
                        $nonprofit, $max_uses, $expires_at, $cid]);
            $flash = 'Coupon updated.';
        } else {
            $flash      = implode(' ', $errors);
            $flash_type = 'danger';
        }
    }
}

// Load coupons
$coupons = $db->query(
    'SELECT * FROM coupons ORDER BY active DESC, created_at DESC'
)->fetchAll();

// Load coupon being edited (if any)
$edit_coupon = null;
$edit_id     = (int)($_GET['edit'] ?? 0);
if ($edit_id) {
    $ec = $db->prepare('SELECT * FROM coupons WHERE id = ?');
    $ec->execute([$edit_id]);
    $edit_coupon = $ec->fetch() ?: null;
}

render_admin_header('Coupons', 'coupons');
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash_type ?> mb-3"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php if ($edit_coupon): ?>
<!-- Edit Coupon -->
<div class="admin-panel mb-4" style="border-left:4px solid var(--gold);">
    <div class="admin-panel__header">
        Edit Coupon &mdash; <code class="text-gold"><?= htmlspecialchars($edit_coupon['code']) ?></code>
    </div>
    <div class="admin-panel__body">
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="coupon_id" value="<?= $edit_coupon['id'] ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Code <span class="text-dim">(cannot be changed)</span></label>
                    <input type="text" class="form-input" value="<?= htmlspecialchars($edit_coupon['code']) ?>"
                           disabled style="opacity:0.5;letter-spacing:.1em;">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-input"
                           value="<?= htmlspecialchars($edit_coupon['description'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Discount Type</label>
                    <select name="discount_type" class="form-input">
                        <option value="percent" <?= $edit_coupon['discount_type'] === 'percent' ? 'selected' : '' ?>>Percent (%)</option>
                        <option value="flat"    <?= $edit_coupon['discount_type'] === 'flat'    ? 'selected' : '' ?>>Flat ($)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount</label>
                    <input type="number" name="discount_amount" class="form-input" step="0.01" min="0.01" required
                           value="<?= htmlspecialchars($edit_coupon['discount_amount']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Applies To</label>
                    <select name="applies_to" class="form-input">
                        <option value="attraction" <?= $edit_coupon['applies_to'] === 'attraction' ? 'selected' : '' ?>>Attraction only</option>
                        <option value="addons"     <?= $edit_coupon['applies_to'] === 'addons'     ? 'selected' : '' ?>>Add-ons only</option>
                        <option value="both"       <?= $edit_coupon['applies_to'] === 'both'       ? 'selected' : '' ?>>Both</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Max Uses <span class="text-dim">(blank = unlimited)</span></label>
                    <input type="number" name="max_uses" class="form-input" min="1"
                           value="<?= htmlspecialchars($edit_coupon['max_uses'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Expires <span class="text-dim">(optional)</span></label>
                    <input type="date" name="expires_at" class="form-input"
                           value="<?= htmlspecialchars($edit_coupon['expires_at'] ?? '') ?>">
                </div>
                <div class="form-group" style="justify-content:flex-end;display:flex;align-items:flex-end;">
                    <label class="check-group" style="margin-bottom:0.6rem;">
                        <input type="checkbox" name="nonprofit_only" value="1"
                               <?= $edit_coupon['nonprofit_only'] ? 'checked' : '' ?>>
                        <span>Nonprofit only</span>
                    </label>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                <a href="coupons.php" class="btn btn-ghost btn-sm">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Add Coupon -->
<div class="admin-panel mb-4">
    <div class="admin-panel__header">Add New Coupon</div>
    <div class="admin-panel__body">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Code <span class="text-dim">(e.g. SUMMER20)</span></label>
                    <input type="text" name="code" class="form-input" style="text-transform:uppercase;letter-spacing:.1em;"
                           value="<?= htmlspecialchars($_POST['code'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-input"
                           value="<?= htmlspecialchars($_POST['description'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Discount Type</label>
                    <select name="discount_type" class="form-input">
                        <option value="percent" <?= (($_POST['discount_type'] ?? '') === 'percent') ? 'selected' : '' ?>>Percent (%)</option>
                        <option value="flat"    <?= (($_POST['discount_type'] ?? '') === 'flat')    ? 'selected' : '' ?>>Flat ($)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount</label>
                    <input type="number" name="discount_amount" class="form-input" step="0.01" min="0.01"
                           value="<?= htmlspecialchars($_POST['discount_amount'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Applies To</label>
                    <select name="applies_to" class="form-input">
                        <option value="attraction" <?= (($_POST['applies_to'] ?? 'attraction') === 'attraction') ? 'selected' : '' ?>>Attraction only</option>
                        <option value="addons"     <?= (($_POST['applies_to'] ?? '') === 'addons')     ? 'selected' : '' ?>>Add-ons only</option>
                        <option value="both"       <?= (($_POST['applies_to'] ?? '') === 'both')       ? 'selected' : '' ?>>Both</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Max Uses <span class="text-dim">(blank = unlimited)</span></label>
                    <input type="number" name="max_uses" class="form-input" min="1"
                           value="<?= htmlspecialchars($_POST['max_uses'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Expires <span class="text-dim">(optional)</span></label>
                    <input type="date" name="expires_at" class="form-input"
                           value="<?= htmlspecialchars($_POST['expires_at'] ?? '') ?>">
                </div>
                <div class="form-group" style="justify-content:flex-end;display:flex;align-items:flex-end;">
                    <label class="check-group" style="margin-bottom:0.6rem;">
                        <input type="checkbox" name="nonprofit_only" value="1"
                               <?= !empty($_POST['nonprofit_only']) ? 'checked' : '' ?>>
                        <span>Nonprofit only</span>
                    </label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Create Coupon</button>
        </form>
    </div>
</div>

<!-- Coupon List -->
<?php if ($coupons): ?>
<div class="card" style="cursor:default;overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Description</th>
                <th>Discount</th>
                <th>Applies To</th>
                <th>Uses</th>
                <th>Expires</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($coupons as $c): ?>
            <tr <?= !$c['active'] ? 'style="opacity:0.5;"' : '' ?>>
                <td><code class="text-gold"><?= htmlspecialchars($c['code']) ?></code></td>
                <td class="text-sm"><?= htmlspecialchars($c['description'] ?? '—') ?></td>
                <td>
                    <?php if ($c['discount_type'] === 'percent'): ?>
                        <?= number_format($c['discount_amount'], 0) ?>%
                    <?php else: ?>
                        $<?= number_format($c['discount_amount'], 2) ?> off
                    <?php endif; ?>
                </td>
                <td class="text-sm"><?= ucfirst($c['applies_to']) ?></td>
                <td>
                    <?= $c['use_count'] ?>
                    <?php if ($c['max_uses']): ?>
                        / <?= $c['max_uses'] ?>
                    <?php else: ?>
                        <span class="text-dim">/ ∞</span>
                    <?php endif; ?>
                    <?php if ($c['nonprofit_only']): ?>
                        <span class="badge badge-info" style="margin-left:4px;">nonprofit</span>
                    <?php endif; ?>
                </td>
                <td class="text-sm">
                    <?php if ($c['expires_at']): ?>
                        <?= date('M j, Y', strtotime($c['expires_at'])) ?>
                        <?php if ($c['expires_at'] < date('Y-m-d')): ?>
                            <span class="badge badge-danger">expired</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-dim">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($c['active']): ?>
                        <span class="badge badge-success">Active</span>
                    <?php else: ?>
                        <span class="badge badge-default">Inactive</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <a href="?edit=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="coupon_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">
                            <?= $c['active'] ? 'Disable' : 'Enable' ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('<?= $c['use_count'] > 0
                              ? 'This coupon has been used ' . $c['use_count'] . ' time(s). Deleting it will remove it from the coupon list but existing booking records will retain the discount. Delete anyway?'
                              : 'Delete coupon ' . htmlspecialchars(addslashes($c['code'])) . '?' ?>")">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="coupon_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="alert alert-info">No coupons yet.</div>
<?php endif; ?>

<?php render_admin_footer(); ?>
