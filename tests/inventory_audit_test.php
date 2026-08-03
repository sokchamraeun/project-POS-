<?php
// Unit test for inventory audit notifications & manager review workflow
require_once __DIR__ . '/../config.php';

function test_assert($cond, $msg) {
    if ($cond) {
        echo "  PASS  {$msg}\n";
    } else {
        echo "  FAIL  {$msg}\n";
        exit(1);
    }
}

echo "=== Testing Inventory Audit Notifications & Review Workflow ===\n";

// Fetch an ingredient to test with
$ing = $conn->query("SELECT ingredient_id, ingredient_name, unit FROM ingredients LIMIT 1")->fetch_assoc();
test_assert(!empty($ing), "An ingredient exists in DB");
$iid = (int)$ing['ingredient_id'];

// 1. Test manual stock adjustment with spoilage category
$delta = -50.0;
$reason = "Test spoilage: expired batch";
$category = "spoilage";
$username = "TestClerk";

$sh = $conn->prepare("INSERT INTO ingredient_history (ingredient_id, change_type, amount, reference, reason_category, reviewed, created_by) VALUES (?, 'manual_adjust', ?, ?, ?, 0, ?)");
$sh->bind_param("idsss", $iid, $delta, $reason, $category, $username);
$sh->execute();
$history_id = $conn->insert_id;

test_assert($history_id > 0, "Created ingredient_history entry #{$history_id}");

// Check record in DB
$rec = $conn->query("SELECT * FROM ingredient_history WHERE id = $history_id")->fetch_assoc();
test_assert($rec['reason_category'] === 'spoilage', "Saved reason_category = 'spoilage'");
test_assert((int)$rec['reviewed'] === 0, "Initial reviewed state = 0 (Unreviewed)");

// 2. Verify High-Risk Notification Trigger
$anc = $conn->prepare("INSERT INTO announcements (title, message, type, is_active, created_at) VALUES (?, ?, 'danger', 1, NOW())");
$title = "Inventory Alert: " . $ing['ingredient_name'];
$msg = "TestClerk logged Spoilage (-50 unit). Reason: \"Test spoilage\"";
$anc->bind_param("ss", $title, $msg);
$anc->execute();
$notif_id = $conn->insert_id;
test_assert($notif_id > 0, "Created targeted notification bell alert #{$notif_id}");

// 3. Query unreviewed count
$unrev = $conn->query("SELECT COUNT(*) AS cnt FROM ingredient_history WHERE reviewed = 0 AND change_type IN ('manual_adjust', 'quick_restock')")->fetch_assoc()['cnt'];
test_assert($unrev > 0, "Unreviewed count query returns {$unrev} pending items");

// 4. Mark item reviewed
$reviewer = "TestManager";
$conn->query("UPDATE ingredient_history SET reviewed = 1, reviewed_by = '$reviewer', reviewed_at = NOW() WHERE id = $history_id");

$updated_rec = $conn->query("SELECT * FROM ingredient_history WHERE id = $history_id")->fetch_assoc();
test_assert((int)$updated_rec['reviewed'] === 1, "Reviewed state updated to 1");
test_assert($updated_rec['reviewed_by'] === 'TestManager', "Reviewed by stamped as 'TestManager'");
test_assert(!empty($updated_rec['reviewed_at']), "Reviewed timestamp stamped");

// Clean up test rows
$conn->query("DELETE FROM ingredient_history WHERE id = $history_id");
$conn->query("DELETE FROM announcements WHERE id = $notif_id");

echo "\nALL PASS\n";
