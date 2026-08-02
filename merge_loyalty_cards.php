<?php
/**
 * Merge one loyalty card into another.
 *
 * Points are added to the target, the source card's history is repointed so the
 * customer keeps a complete record, and the source is deactivated rather than deleted —
 * orders.loyalty_card_id still references it and an audit trail should not vanish.
 */
require 'auth.php';
require 'config.php';
header('Content-Type: application/json');

if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)) {
    echo json_encode(['success' => false, 'message' => 'Only an admin or manager can merge cards']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}
if (empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Security check failed. Please reload and try again.']);
    exit;
}

$source_id = (int)($_POST['source_card_id'] ?? 0);
$target_id = (int)($_POST['target_card_id'] ?? 0);

if ($source_id <= 0 || $target_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Pick both a card to merge and a card to keep']);
    exit;
}
if ($source_id === $target_id) {
    echo json_encode(['success' => false, 'message' => 'A card cannot be merged into itself']);
    exit;
}

$load = $conn->prepare("SELECT card_id, loyalty_id, points, points_progress, total_orders, total_drinks, is_active
                        FROM loyalty_cards WHERE card_id IN (?, ?)");
$load->bind_param("ii", $source_id, $target_id);
$load->execute();
$cards = [];
$res = $load->get_result();
while ($row = $res->fetch_assoc()) $cards[(int)$row['card_id']] = $row;

if (!isset($cards[$source_id], $cards[$target_id])) {
    echo json_encode(['success' => false, 'message' => 'One of those cards no longer exists']);
    exit;
}
// Merging into a retired card would strand the points where nobody can spend them.
if ((int)$cards[$target_id]['is_active'] !== 1) {
    echo json_encode(['success' => false, 'message' => 'The card you keep must be active']);
    exit;
}
if ((int)$cards[$source_id]['is_active'] !== 1) {
    echo json_encode(['success' => false, 'message' => 'That card has already been merged or deactivated']);
    exit;
}

$src = $cards[$source_id];
$tgt = $cards[$target_id];
$moved_points = (int)$src['points'];
// The carry toward the source's next point. Without moving it, a customer one
// drink into their next point loses that drink the moment their card is merged.
$moved_progress = (int)($src['points_progress'] ?? 0);

$conn->begin_transaction();
try {
    /* One-shot claim: the UPDATE only fires while the source is still active, so two
       managers merging the same card at once can't move its points twice. */
    $deact = $conn->prepare(
        "UPDATE loyalty_cards
            SET points = 0, points_progress = 0, is_active = 0, merged_into = ?, merged_at = NOW()
          WHERE card_id = ? AND is_active = 1"
    );
    $deact->bind_param("ii", $target_id, $source_id);
    $deact->execute();
    if ($deact->affected_rows !== 1) {
        throw new Exception('That card was already merged by someone else.');
    }

    $add = $conn->prepare(
        "UPDATE loyalty_cards
            SET points          = points + ?,
                points_progress = points_progress + ?,
                total_orders    = total_orders + ?,
                total_drinks    = total_drinks + ?,
                last_used       = NOW()
          WHERE card_id = ?"
    );
    $so = (int)$src['total_orders'];
    $sd = (int)$src['total_drinks'];
    $add->bind_param("iiiii", $moved_points, $moved_progress, $so, $sd, $target_id);
    $add->execute();

    // Repoint history so the kept card shows the full earning record.
    $hist = $conn->prepare("UPDATE loyalty_history SET card_id = ? WHERE card_id = ?");
    $hist->bind_param("ii", $target_id, $source_id);
    $hist->execute();

    // Log the merge itself on the kept card.
    $note = $conn->prepare(
        "INSERT INTO loyalty_history (card_id, order_id, points_change, type, description)
         VALUES (?, NULL, ?, 'adjusted_add', ?)"
    );
    $desc = 'Merged from ' . $src['loyalty_id'] . ' by ' . ($_SESSION['username'] ?? 'staff');
    $note->bind_param("iis", $target_id, $moved_points, $desc);
    $note->execute();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => "Merged {$src['loyalty_id']} into {$tgt['loyalty_id']} — {$moved_points} points moved."
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
