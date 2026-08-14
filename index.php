<?php
// App root for the POS — no public storefront. Route to the right place.
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: menu.php');
} else {
    header('Location: login.php');
}
exit;
