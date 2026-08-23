<?php
// REAL Bakong payment credentials. This file is git-ignored — never commit it.
// Copy bakong_config.local.example.php to create this on a new machine.

use KHQR\Helpers\KHQRData;

return [
    'token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjp7ImlkIjoiNmI5YjIwZGFjZDMyNDc2MCJ9LCJpYXQiOjE3ODc0MTYwODksImV4cCI6MTc5NTE5MjA4OX0.Leg2h-2mjg0I2-zJ4FGaqUldHDe7xOyESvVfP-3W9Dc',
    'bakong_id' => 'sok_chamraeun@bkrt',
    'merchant_name' => 'Sok Chamraeun',
    'merchantCity' => 'PHNOM PENH',
    'merchant_city' => 'PHNOM PENH',
    'mobile_number' => '974749522',
    'currency' => KHQRData::CURRENCY_USD,
];
