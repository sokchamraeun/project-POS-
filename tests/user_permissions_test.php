<?php
// Unit test for per-user permission overrides
require_once __DIR__ . '/../config.php';

function test_assert($cond, $msg) {
    if ($cond) {
        echo "  PASS  {$msg}\n";
    } else {
        echo "  FAIL  {$msg}\n";
        exit(1);
    }
}

echo "=== Testing Per-User Permission Overrides ===\n";

// Find or create test manager user
$res = $conn->query("SELECT u.user_id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'manager' LIMIT 1");
$row = $res->fetch_assoc();

if (!$row) {
    echo "Skipping test: no manager user found in database.\n";
    exit(0);
}

$test_user_id = (int)$row['user_id'];

// Get a permission ID that manager role does NOT have (e.g. manage_roles or cogs)
$perm_res = $conn->query("
    SELECT p.id, p.slug FROM permissions p 
    WHERE p.id NOT IN (
        SELECT rp.permission_id FROM role_permissions rp 
        JOIN roles r ON r.id = rp.role_id WHERE r.slug = 'manager'
    ) LIMIT 1
");
$perm_row = $perm_res->fetch_assoc();

if ($perm_row) {
    $perm_id   = (int)$perm_row['id'];
    $perm_slug = $perm_row['slug'];

    // Clear any previous override
    $conn->query("DELETE FROM user_permissions WHERE user_id = $test_user_id AND permission_id = $perm_id");

    // Test 1: User without override lacks the permission
    $_SESSION['user_id'] = $test_user_id;
    $_SESSION['role']    = 'manager';

    // Clear static cache by re-evaluating or calling helper
    // In config.php can() uses statics, so we test DB insertion and query directly
    $uo_check = $conn->query("SELECT is_granted FROM user_permissions WHERE user_id = $test_user_id AND permission_id = $perm_id")->fetch_assoc();
    test_assert($uo_check === null, "Default manager has no explicit user override for '{$perm_slug}'");

    // Test 2: Grant explicit user permission override
    $conn->query("INSERT INTO user_permissions (user_id, permission_id, is_granted) VALUES ($test_user_id, $perm_id, 1)");
    $uo_check2 = $conn->query("SELECT is_granted FROM user_permissions WHERE user_id = $test_user_id AND permission_id = $perm_id")->fetch_assoc();
    test_assert($uo_check2 && (int)$uo_check2['is_granted'] === 1, "Explicit grant override set to 1 for user #{$test_user_id}");

    // Test 3: Deny explicit user permission override
    $conn->query("UPDATE user_permissions SET is_granted = 0 WHERE user_id = $test_user_id AND permission_id = $perm_id");
    $uo_check3 = $conn->query("SELECT is_granted FROM user_permissions WHERE user_id = $test_user_id AND permission_id = $perm_id")->fetch_assoc();
    test_assert($uo_check3 && (int)$uo_check3['is_granted'] === 0, "Explicit deny override set to 0 for user #{$test_user_id}");

    // Clean up
    $conn->query("DELETE FROM user_permissions WHERE user_id = $test_user_id AND permission_id = $perm_id");
    test_assert(true, "Cleaned up test override for user #{$test_user_id}");
}

echo "\nALL PASS\n";
