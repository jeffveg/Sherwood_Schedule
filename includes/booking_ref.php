<?php
/**
 * Generate a unique, human-readable booking reference.
 * Format: SA-YYYY-NNN  (e.g. SA-2025-001)
 */
function generate_booking_ref(): string {
    $db     = get_db();
    $prefix = get_setting('booking_ref_prefix') ?? 'SA';
    $year   = date('Y');

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM bookings WHERE booking_ref LIKE ?"
    );
    $stmt->execute([$prefix . '-' . $year . '-%']);
    $count = (int)$stmt->fetchColumn();

    return sprintf('%s-%s-%03d', $prefix, $year, $count + 1);
}

/**
 * Fetch a single setting value by key.
 */
function get_setting(string $key): ?string {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $stmt = get_db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $cache[$key] = $stmt->fetchColumn() ?: null;
    }
    return $cache[$key];
}
