<?php
// One-off CLI helper to get a fresh Bakong token.
// Usage:  php renew_bakong_token.php your-registered@email.com
// Then paste the printed token into bakong_config.local.php ('token' => '...').

if (file_exists(__DIR__ . '/bakong-khqr-php-main/autoload.php')) {
    require_once __DIR__ . '/bakong-khqr-php-main/autoload.php';
} elseif (file_exists(__DIR__ . '/bakong-khqr-php-main/vendor/autoload.php')) {
    require_once __DIR__ . '/bakong-khqr-php-main/vendor/autoload.php';
}

use KHQR\BakongKHQR;

$email = $argv[1] ?? null;
if (!$email) {
    fwrite(STDERR, "Usage: php renew_bakong_token.php your-registered@email.com\n");
    exit(1);
}

try {
    $result = BakongKHQR::renewToken($email);   // POSTs {email} to NBC's renew_token endpoint
    echo "Raw response:\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

    // The new token is usually under data.token (shape may vary slightly).
    $token = $result['data']['token'] ?? null;
    if ($token) {
        echo "NEW TOKEN:\n$token\n";
    } else {
        echo "Couldn't find a token field in the response above — check the message/errorCode.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Failed: " . $e->getMessage() . "\n");
    exit(1);
}
