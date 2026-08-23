<?php
// Bakong payment config loader.
// Real credentials live in bakong_config.local.php (git-ignored).
// This tracked file only holds safe placeholders as a fallback/template.

use KHQR\Helpers\KHQRData;

$local = __DIR__ . '/bakong_config.local.php';
if (file_exists($local)) {
    return require $local;
}

// Default Bakong configuration for payments
return [
    'token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjp7ImlkIjoiNmI5YjIwZGFjZDMyNDc2MCJ9LCJpYXQiOjE3ODc0MTYwODksImV4cCI6MTc5NTE5MjA4OX0.Leg2h-2mjg0I2-zJ4FGaqUldHDe7xOyESvVfP-3W9Dc',
    'bakong_id' => 'sok_chamraeun@bkrt',
    'merchant_name' => 'Sok Chamraeun',
    'merchantCity' => 'PHNOM PENH',
    'merchant_city' => 'PHNOM PENH',
    'mobile_number' => '974749522',
    'currency' => KHQRData::CURRENCY_USD,
];
