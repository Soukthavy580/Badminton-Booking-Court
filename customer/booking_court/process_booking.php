<?php
session_start();
require_once '../../config/db.php';

date_default_timezone_set('Asia/Vientiane');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
    exit;
}

$customer_id = $_SESSION['c_id'];
$venue_id    = intval($_POST['venue_id'] ?? 0);
$date        = $_POST['date'] ?? '';
$slots_json  = $_POST['slots_json'] ?? '';

if (!$venue_id || !$date || !$slots_json) {
    header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
    exit;
}

$slots = json_decode($slots_json, true);
if (!$slots || count($slots) === 0) {
    header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue_id . '&date=' . $date);
    exit;
}

try {
    $pdo->beginTransaction();

    // Create booking with 'Unpaid' status — NOT sent to owner yet
    // Slot is NOT blocked until owner confirms
    $stmt = $pdo->prepare("
        INSERT INTO booking (Booking_date, Slip_payment, Status_booking, C_ID)
        VALUES (NOW(), '', 'Unpaid', ?)
    ");
    $stmt->execute([$customer_id]);
    $book_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO booking_detail (Start_time, End_time, Book_ID, COURT_ID)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($slots as $slot) {
        $start_datetime = $date . ' ' . $slot['start'] . ':00';
        $end_datetime   = $date . ' ' . $slot['end']   . ':00';
        $court_id       = intval($slot['courtId']);

        // Block if slot is Confirmed OR Pending (Pending = slip uploaded, real booking)
        // Unpaid bookings don't block — customer hasn't committed yet
        $check = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM booking_detail bd
            INNER JOIN booking b ON bd.Book_ID = b.Book_ID
            WHERE bd.COURT_ID = ?
            AND b.Status_booking IN ('Confirmed', 'Pending')
            AND bd.Start_time < ?
            AND bd.End_time > ?
        ");
        $check->execute([$court_id, $end_datetime, $start_datetime]);
        if ($check->fetch()['cnt'] > 0) {
            $pdo->rollBack();
            $_SESSION['booking_error'] = 'ສລັອດທີ່ທ່ານເລືອກຖືກຈອງແລ້ວ. ກະລຸນາເລືອກເວລາໃໝ່.';
            header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue_id . '&date=' . $date);
            exit;
        }

        $stmt->execute([$start_datetime, $end_datetime, $book_id, $court_id]);
    }

    $pdo->commit();

    // Redirect to payment page
    header('Location: /Badminton_court_Booking/customer/payment/index.php?booking_id=' . $book_id);
    exit;

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Booking error: " . $e->getMessage());
    $_SESSION['booking_error'] = 'ການຈອງລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
    header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue_id . '&date=' . $date);
    exit;
}
?>