<?php
/**
 * Minimal SMTP mailer for IONOS shared hosting.
 * Supports STARTTLS, HTML body, and file attachments.
 * No external dependencies.
 */

/**
 * Send an email via SMTP.
 *
 * @param string      $to          Recipient "Name <email>" or just "email"
 * @param string      $subject
 * @param string      $html_body   Full HTML email body
 * @param array       $attachments [['name'=>'file.ics','mime'=>'text/calendar','data'=>'...raw...'], ...]
 * @param string|null $reply_to    Optional reply-to address
 * @return bool
 */
function send_email(string $to, string $subject, string $html_body, array $attachments = [], ?string $reply_to = null): bool {
    $host    = SMTP_HOST;
    $port    = SMTP_PORT;
    $user    = SMTP_USER;
    $pass    = SMTP_PASS;
    $from    = SMTP_USER;
    $from_name = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Sherwood Adventure';

    $boundary = '=_' . md5(uniqid('', true));

    // Build MIME message
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: multipart/alternative; boundary=\"alt_{$boundary}\"\r\n\r\n";
    // Plain text fallback
    $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $html_body));
    $plain = html_entity_decode(preg_replace('/\s+/', ' ', $plain), ENT_QUOTES, 'UTF-8');
    $body .= "--alt_{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($plain) . "\r\n";
    $body .= "--alt_{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html_body) . "\r\n";
    $body .= "--alt_{$boundary}--\r\n";

    foreach ($attachments as $att) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$att['mime']}; name=\"{$att['name']}\"\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$att['name']}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($att['data'])) . "\r\n";
    }
    $body .= "--{$boundary}--";

    // SMTP conversation
    try {
        $socket = fsockopen('tls://' . $host, $port, $errno, $errstr, 15);
        if (!$socket) {
            // Fall back to STARTTLS on port 587
            $socket = fsockopen($host, $port, $errno, $errstr, 15);
            if (!$socket) {
                error_log("SMTP connect failed: {$errstr} ({$errno})");
                return false;
            }
            $use_starttls = true;
        } else {
            $use_starttls = false;
        }

        stream_set_timeout($socket, 15);

        $read = smtp_read($socket);
        if (!str_starts_with($read, '220')) { fclose($socket); return false; }

        smtp_send($socket, "EHLO " . gethostname());
        $read = smtp_read($socket);

        if ($use_starttls && str_contains($read, 'STARTTLS')) {
            smtp_send($socket, "STARTTLS");
            smtp_read($socket);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            smtp_send($socket, "EHLO " . gethostname());
            smtp_read($socket);
        }

        smtp_send($socket, "AUTH LOGIN");
        smtp_read($socket);
        smtp_send($socket, base64_encode($user));
        smtp_read($socket);
        smtp_send($socket, base64_encode($pass));
        $auth = smtp_read($socket);
        if (!str_starts_with($auth, '235')) {
            error_log("SMTP auth failed: {$auth}");
            fclose($socket);
            return false;
        }

        smtp_send($socket, "MAIL FROM:<{$from}>");
        smtp_read($socket);

        // Parse recipient address
        $to_email = preg_match('/<(.+?)>/', $to, $m) ? $m[1] : trim($to);
        smtp_send($socket, "RCPT TO:<{$to_email}>");
        smtp_read($socket);

        // Also BCC admin on customer emails
        if (defined('ADMIN_EMAIL') && ADMIN_EMAIL && strtolower($to_email) !== strtolower(ADMIN_EMAIL)) {
            smtp_send($socket, "RCPT TO:<" . ADMIN_EMAIL . ">");
            smtp_read($socket);
        }

        smtp_send($socket, "DATA");
        smtp_read($socket);

        $from_encoded = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
        $subj_encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $full_headers  = "From: {$from_encoded} <{$from}>\r\n";
        $full_headers .= "To: {$to}\r\n";
        if ($reply_to) $full_headers .= "Reply-To: {$reply_to}\r\n";
        $full_headers .= "Subject: {$subj_encoded}\r\n";
        $full_headers .= "Date: " . date('r') . "\r\n";
        $full_headers .= $headers;

        smtp_send($socket, $full_headers . "\r\n" . $body . "\r\n.");
        $sent = smtp_read($socket);

        smtp_send($socket, "QUIT");
        fclose($socket);

        return str_starts_with($sent, '250');

    } catch (Throwable $e) {
        error_log("SMTP exception: " . $e->getMessage());
        return false;
    }
}

function smtp_send($socket, string $data): void {
    fwrite($socket, $data . "\r\n");
}

function smtp_read($socket): string {
    $out = '';
    while ($line = fgets($socket, 512)) {
        $out .= $line;
        if (isset($line[3]) && $line[3] === ' ') break; // end of multi-line response
    }
    return $out;
}

/**
 * Generate an iCalendar (.ics) string for a booking.
 */
function generate_ics(array $booking, string $attraction_name): string {
    $dtstart  = date('Ymd\THis', strtotime($booking['event_date'] . ' ' . $booking['start_time']));
    $dtend    = date('Ymd\THis', strtotime($booking['event_date'] . ' ' . $booking['end_time']));
    $dtstamp  = date('Ymd\THis\Z');
    $uid      = $booking['booking_ref'] . '@sherwoodadventure.com';
    $summary  = 'Sherwood Adventure — ' . $attraction_name;
    $location = implode(', ', array_filter([
        $booking['venue_name'],
        $booking['venue_address'],
        $booking['venue_city'] . ' ' . $booking['venue_state'],
    ]));
    $description = 'Booking ref: ' . $booking['booking_ref'] . '\nBalance due: $' . number_format($booking['balance_due'], 2);

    return "BEGIN:VCALENDAR\r\n"
         . "VERSION:2.0\r\n"
         . "PRODID:-//Sherwood Adventure//Booking//EN\r\n"
         . "CALSCALE:GREGORIAN\r\n"
         . "METHOD:REQUEST\r\n"
         . "BEGIN:VEVENT\r\n"
         . "UID:{$uid}\r\n"
         . "DTSTAMP:{$dtstamp}\r\n"
         . "DTSTART;TZID=America/Phoenix:{$dtstart}\r\n"
         . "DTEND;TZID=America/Phoenix:{$dtend}\r\n"
         . "SUMMARY:{$summary}\r\n"
         . "LOCATION:{$location}\r\n"
         . "DESCRIPTION:{$description}\r\n"
         . "STATUS:CONFIRMED\r\n"
         . "END:VEVENT\r\n"
         . "END:VCALENDAR\r\n";
}
