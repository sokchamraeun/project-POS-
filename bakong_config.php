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
    'token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjp7ImlkIjoiYzA2YjdlYzU1NzE2NGUzNiJ9LCJpYXQiOjE3ODY5NDA3NjgsImV4cCI6MTc5NDcxNjc2OH0.u5_3GkBfst6O6xvdY-Ywa29iVNtDLz5jUQwkB7TXvwE',
    'bakong_id' => 'sok_chamraeun@bkrt',
    'merchant_name' => 'Sok Chamraeun',
    'merchantCity' => 'PHNOM PENH',
    'merchant_city' => 'PHNOM PENH',
    'mobile_number' => '974749522',
    'currency' => KHQRData::CURRENCY_USD,
];
