<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$secret = config_value('FRICTIONSCAN_STRIPE_WEBHOOK_SECRET');
$payload = file_get_contents('php://input') ?: '';
$signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

if ($secret === '') {
    http_response_code(501);
    echo 'Webhook secret not configured';
    exit;
}

if (!verify_stripe_signature($payload, $signature, $secret)) {
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

if (($event['type'] ?? '') === 'checkout.session.completed' && !empty($event['data']['object']['id'])) {
    try {
        reconcile_checkout_session((string) $event['data']['object']['id']);
    } catch (Throwable) {
        http_response_code(500);
        echo 'Reconcile failed';
        exit;
    }
}

echo 'ok';

function verify_stripe_signature(string $payload, string $header, string $secret): bool
{
    $timestamp = '';
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key === 't') {
            $timestamp = $value;
        }
        if ($key === 'v1') {
            $signatures[] = $value;
        }
    }
    if ($timestamp === '' || !$signatures || abs(time() - (int) $timestamp) > 300) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}
