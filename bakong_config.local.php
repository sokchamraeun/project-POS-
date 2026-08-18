<?php
// REAL Bakong payment credentials. This file is git-ignored — never commit it.
// Copy bakong_config.local.example.php to create this on a new machine.

use KHQR\Helpers\KHQRData;

return [
    'token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjp7ImlkIjoiYzA2YjdlYzU1NzE2NGUzNiJ9LCJpYXQiOjE3ODY5NDA3NjgsImV4cCI6MTc5NDcxNjc2OH0.u5_3GkBfst6O6xvdY-Ywa29iVNtDLz5jUQwkB7TXvwE',
    'bakong_id' => 'sok_chamraeun@bkrt',
    'merchant_name' => 'Sok Chamraeun',
    'merchantCity' => 'PHNOM PENH',
    'merchant_city' => 'PHNOM PENH',
    'mobile_number' => '974749522',
    'currency' => KHQRData::CURRENCY_USD,
];
