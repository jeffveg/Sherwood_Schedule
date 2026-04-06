<?php
/**
 * SMS helper via OpenPhone (QUO).
 */

function send_sms(string $to, string $message): bool {
    $to = preg_replace('/\D/', '', $to);
    if (strlen($to) === 10) $to = '+1' . $to;
    elseif (strlen($to) === 11 && $to[0] === '1') $to = '+' . $to;
    else $to = '+' . $to;

    $payload = json_encode([
        'from'    => QUO_FROM_NUMBER,
        'to'      => [$to],
        'content' => $message,
    ]);

    $ch = curl_init(QUO_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . QUO_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT    => 10,
    ]);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err || $http_code < 200 || $http_code >= 300) {
        error_log('OpenPhone SMS error (' . $http_code . '): ' . ($curl_err ?: $response));
        return false;
    }

    return true;
}
