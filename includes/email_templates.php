<?php
/**
 * Email template functions for Sherwood Adventure booking system.
 */

/**
 * Send booking confirmation email to customer + BCC admin.
 */
function send_booking_confirmation(array $booking, array $customer, array $addon_lines, string $attraction_name): bool {
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/db.php';

    $ref          = $booking['booking_ref'];
    $event_date   = date('l, F j, Y', strtotime($booking['event_date']));
    $event_time   = date('g:i A', strtotime($booking['start_time']));
    $duration     = $booking['duration_hours'] . ' hour' . ($booking['duration_hours'] != 1 ? 's' : '');
    $payment_opt  = $booking['payment_option'] === 'full' ? 'Paid in Full' : 'Deposit';

    $venue_parts = array_filter([
        $booking['venue_name'],
        $booking['venue_address'],
        $booking['venue_city'] . ', ' . $booking['venue_state'] . ' ' . $booking['venue_zip'],
    ]);
    $venue = implode('<br>', $venue_parts) ?: 'To be confirmed';

    // Build add-on lines
    $addon_html = '';
    foreach ($addon_lines as $line) {
        $addon_html .= email_price_row(htmlspecialchars($line['addon_name']), '$' . number_format($line['total_price'], 2));
    }

    // Travel
    $travel_html = '';
    if ($booking['travel_fee'] > 0) {
        $travel_html = email_price_row('Travel Fee', '$' . number_format($booking['travel_fee'], 2));
    } elseif (!$booking['travel_fee'] && !$booking['travel_miles']) {
        $travel_html = email_price_row('<em>Travel fee</em>', '<em>TBD</em>');
    }

    // Coupon
    $coupon_html = '';
    if ($booking['coupon_discount'] > 0) {
        $coupon_html = email_price_row('Discount (' . htmlspecialchars($booking['coupon_code']) . ')', '&minus;$' . number_format($booking['coupon_discount'], 2), '#7ec89a');
    }

    // Square payment reference
    $square_ref_html = '';
    if (!empty($booking['id'])) {
        $db = get_db();
        $ref_stmt = $db->prepare(
            'SELECT square_payment_id FROM payments
             WHERE booking_id = ? AND square_payment_id IS NOT NULL
             ORDER BY created_at DESC LIMIT 1'
        );
        $ref_stmt->execute([$booking['id']]);
        $square_payment_id = $ref_stmt->fetchColumn();
        if ($square_payment_id) {
            $square_ref_html = email_price_row('Payment Reference', htmlspecialchars($square_payment_id), '#a0a0a0', 'normal');
        }
    }

    // Balance note
    if ($booking['payment_option'] === 'deposit' && $booking['balance_due'] > 0) {
        $balance_html = '
        <tr>
            <td colspan="2" style="padding:12px 0 0; font-size:13px; color:#a0a0a0;">
                Balance of <strong style="color:#fed611;">$' . number_format($booking['balance_due'], 2) . '</strong>
                is due before your event. You\'ll receive a payment link closer to your event date.
            </td>
        </tr>';
    } else {
        $balance_html = '';
    }

    $subject = "Booking Confirmed — {$ref} — {$attraction_name} on {$event_date}";

    $html = email_wrapper("Your booking is confirmed!", "
        <p style='font-size:16px; margin:0 0 20px;'>
            Hi {$customer['first_name']}, your Sherwood Adventure booking is confirmed.
            Your booking reference is <strong style='color:#fed611;'>{$ref}</strong>.
        </p>

        " . email_section("Event Details", "
            " . email_detail_row('Activity', htmlspecialchars($attraction_name)) . "
            " . email_detail_row('Date', $event_date) . "
            " . email_detail_row('Time', $event_time) . "
            " . email_detail_row('Duration', $duration) . "
            " . email_detail_row('Venue', $venue) . "
        ") . "

        " . email_section("Price Summary", "
            <table width='100%' cellpadding='0' cellspacing='0' style='font-size:14px;'>
                " . email_price_row(htmlspecialchars($attraction_name), '$' . number_format($booking['attraction_price'], 2)) . "
                {$addon_html}
                {$travel_html}
                {$coupon_html}
                <tr><td colspan='2' style='padding:6px 0;border-top:1px solid #3a3c3d;'></td></tr>
                " . email_price_row('Tax', '$' . number_format($booking['tax_total'], 2), '#a0a0a0', 'normal') . "
                <tr><td colspan='2' style='padding:6px 0;border-top:1px solid #3a3c3d;'></td></tr>
                " . email_price_row('<strong>Total</strong>', '<strong>$' . number_format($booking['grand_total'], 2) . '</strong>') . "
                " . email_price_row('Paid Today (' . $payment_opt . ')', '$' . number_format($booking['amount_paid'], 2), '#7ec89a') . "
                {$square_ref_html}
                {$balance_html}
            </table>
        ") . "

        <p style='font-size:13px; color:#a0a0a0; margin:24px 0 0;'>
            Questions? Reply to this email or visit
            <a href='https://sherwoodadventure.com/contact-us.html' style='color:#fed611;'>our contact page</a>.
        </p>

        <p style='font-size:13px; color:#a0a0a0; margin:8px 0 0;'>
            A calendar invite is attached — add it to your calendar to save the date!
        </p>
    ");

    $ics = generate_ics($booking, $attraction_name);
    $to  = $customer['first_name'] . ' ' . $customer['last_name'] . ' <' . $customer['email'] . '>';

    return send_email($to, $subject, $html, [
        ['name' => 'Sherwood-Adventure-' . $ref . '.ics', 'mime' => 'text/calendar', 'data' => $ics],
    ], SMTP_USER);
}

/**
 * Send admin-only notification when a new booking is created.
 */
function send_admin_booking_notification(array $booking, array $customer, string $attraction_name): bool {
    require_once __DIR__ . '/mailer.php';

    if (!defined('ADMIN_EMAIL') || !ADMIN_EMAIL) return false;

    $ref        = $booking['booking_ref'];
    $event_date = date('l, F j, Y', strtotime($booking['event_date']));
    $event_time = date('g:i A', strtotime($booking['start_time']));

    $subject = "New Booking: {$ref} — {$customer['first_name']} {$customer['last_name']}";

    $html = email_wrapper("New Booking Received", "
        " . email_section("Booking", "
            " . email_detail_row('Reference', $ref) . "
            " . email_detail_row('Activity', htmlspecialchars($attraction_name)) . "
            " . email_detail_row('Date', $event_date . ' at ' . $event_time) . "
            " . email_detail_row('Duration', $booking['duration_hours'] . ' hrs') . "
            " . email_detail_row('Payment', ucfirst($booking['payment_option']) . ' — $' . number_format($booking['amount_paid'], 2) . ' paid') . "
            " . email_detail_row('Grand Total', '$' . number_format($booking['grand_total'], 2)) . "
        ") . "
        " . email_section("Customer", "
            " . email_detail_row('Name', htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name'])) . "
            " . email_detail_row('Email', htmlspecialchars($customer['email'])) . "
            " . email_detail_row('Phone', htmlspecialchars($customer['phone'])) . "
            " . ($customer['organization'] ? email_detail_row('Organization', htmlspecialchars($customer['organization'])) : '') . "
        ") . "
        " . email_section("Venue", "
            " . email_detail_row('Name', htmlspecialchars($booking['venue_name'] ?: '—')) . "
            " . email_detail_row('Address', htmlspecialchars($booking['venue_address'] . ', ' . $booking['venue_city'] . ', ' . $booking['venue_state'])) . "
            " . ($booking['travel_fee'] > 0 ? email_detail_row('Travel Fee', '$' . number_format($booking['travel_fee'], 2) . ' (' . $booking['travel_miles'] . ' mi)') : '') . "
        ") . "
        " . (($booking['tournament_bracket'] && $booking['tournament_bracket'] !== 'No') || $booking['event_notes'] ? email_section("Event Notes", "
            " . ($booking['tournament_bracket'] ? email_detail_row('Tournament', htmlspecialchars($booking['tournament_bracket'])) : '') . "
            " . ($booking['event_notes'] ? email_detail_row('Notes', htmlspecialchars($booking['event_notes'])) : '') . "
        ") : '') . "
        <p style='margin:20px 0 0;'>
            <a href='" . APP_URL . "/admin/' style='background:#fed611;color:#111;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;'>View in Admin Panel</a>
        </p>
    ");

    return send_email(ADMIN_EMAIL, $subject, $html);
}

// ── Template helpers ───────────────────────────────────────────────────────

function email_wrapper(string $heading, string $content): string {
    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#27292a;font-family:Arial,sans-serif;color:#dfdfdf;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#27292a;padding:30px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

      <!-- Header -->
      <tr>
        <td style="background:#05290b;border-bottom:3px solid #fed611;padding:24px 32px;border-radius:14px 14px 0 0;">
          <p style="margin:0;font-family:Georgia,serif;font-size:26px;color:#fed611;font-weight:bold;">Sherwood Adventure</p>
          <p style="margin:6px 0 0;font-size:13px;color:#a0a0a0;">Archery Tag &amp; Hoverball Events</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="background:#27292a;padding:32px;border-radius:0 0 14px 14px;">
          <h2 style="margin:0 0 20px;font-size:22px;color:#fed611;">' . $heading . '</h2>
          ' . $content . '
          <hr style="border:none;border-top:1px solid #3a3c3d;margin:30px 0;">
          <p style="font-size:11px;color:#555;margin:0;">&copy; ' . date('Y') . ' Sherwood Adventure &mdash; Goodyear, AZ</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body></html>';
}

function email_section(string $title, string $content): string {
    return '
    <div style="background:#3a3c3d;border-radius:10px;padding:18px 20px;margin-bottom:16px;">
        <p style="margin:0 0 12px;font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:#ffa133;font-weight:bold;">' . $title . '</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">' . $content . '</table>
    </div>';
}

function email_detail_row(string $label, string $value): string {
    return '
    <tr>
        <td style="padding:5px 0;color:#a0a0a0;width:130px;vertical-align:top;">' . $label . '</td>
        <td style="padding:5px 0;color:#dfdfdf;">' . $value . '</td>
    </tr>';
}

function email_price_row(string $label, string $value, string $color = '#dfdfdf', string $weight = 'normal'): string {
    return '
    <tr>
        <td style="padding:4px 0;color:#a0a0a0;">' . $label . '</td>
        <td style="padding:4px 0;text-align:right;color:' . $color . ';font-weight:' . $weight . ';">' . $value . '</td>
    </tr>';
}
