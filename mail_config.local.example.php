<?php
// Copy this file to mail_config.local.php to configure custom SMTP credentials
// This file is git-ignored and safe for local credentials.

$smtp_enabled = true;
$smtp_host    = 'smtp.gmail.com';
$smtp_port    = 587;
$smtp_secure  = 'tls'; // 'tls' (port 587) or 'ssl' (port 465)
$smtp_user    = 'your-email@gmail.com';
$smtp_pass    = 'your-16-char-app-password'; // Google Account -> Security -> 2-Step Verification -> App Passwords
$from_email   = 'your-email@gmail.com';
$from_name    = "Bird's Nest Coffee POS";
