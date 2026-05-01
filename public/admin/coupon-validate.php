<?php
/**
 * Admin — AJAX: Validate a coupon code and return discount details.
 * Called by booking-new.php live price preview when the coupon field changes.
 *
 * GET  /admin/coupon-validate.php?code=SUMMER20
 * Returns JSON:
 *   { "valid": true,  "type": "percent", "value": 20, "label": "20% off" }
 *   { "valid": false, "error": "Coupon not found or expired." }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

admin_require_login();

header('Content-Type: application/json');

$code = strtoupper(trim($_GET['code'] ?? ''));

if (!$code) {
    echo json_encode(['valid' => false, 'error' => 'No code provided.']);
    exit;
}

$db   = get_db();
$stmt = $db->prepare(
    "SELECT * FROM coupons
     WHERE code = ? AND active = 1
       AND (expires_at IS NULL OR expires_at >= CURDATE())
       AND (max_uses IS NULL OR use_count < max_uses)"
);
$stmt->execute([$code]);
$coupon = $stmt->fetch();

if (!$coupon) {
    echo json_encode(['valid' => false, 'error' => "Coupon '{$code}' is invalid or expired."]);
    exit;
}

// Build a human-readable label
$type  = $coupon['discount_type'];   // 'percent' or 'flat'
$value = (float)$coupon['discount_value'];

if ($type === 'percent') {
    $label = number_format($value, 0) . '% off';
} else {
    $label = '$' . number_format($value, 2) . ' off';
}

echo json_encode([
    'valid'  => true,
    'type'   => $type,
    'value'  => $value,
    'label'  => $label,
    'code'   => $coupon['code'],
]);
