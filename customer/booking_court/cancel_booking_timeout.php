<?php
/**
 * cancel_booking_timeout.php
 * Called via fetch() when the 10-min payment timer expires.
 * Deletes the pre-booking so slots become free again.
 */
session_start();
require_once '../../config/db.php';

header('Content-Type: application/json');

$pending = $_SESSION['pending_booking'] ?? null;
if (!$pending) {
    echo json_encode(['ok' => true, 'msg' => 'no pending']);
    exit;
}

$book_id     = intval($pending['book_id']);
$customer_id = $pending['customer_id'];

try {
    // Only delete if still Pending and no slip uploaded
    $stmt = $pdo->prepare("
        SELECT Book_ID FROM booking
        WHERE Book_ID = ? AND C_ID = ? AND Status_booking = 'Pending'
        AND (Slip_payment IS NULL OR Slip_payment = '')
    ");
    $stmt->execute([$book_id, $customer_id]);
    if ($stmt->fetchColumn()) {
        $pdo->prepare("DELETE FROM booking_detail WHERE Book_ID = ?")->execute([$book_id]);
        $pdo->prepare("DELETE FROM booking WHERE Book_ID = ?")->execute([$book_id]);
    }
} catch (PDOException $e) { /* silent */ }

unset($_SESSION['pending_booking']);
echo json_encode(['ok' => true]);