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

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) {
    $_SESSION['booking_error'] = 'ວັນທີບໍ່ຖືກຕ້ອງ.';
    header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue_id . '&date=' . $date);
    exit;
}

$slots = json_decode($slots_json, true);
if (!$slots || count($slots) === 0) {
    header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue_id . '&date=' . $date);
    exit;
}

// Deduplicate
$seen  = [];
$slots = array_filter($slots, function ($slot) use (&$seen) {
    $key = ($slot['courtId'] ?? '') . '_' . ($slot['start'] ?? '') . '_' . ($slot['end'] ?? '');
    if (isset($seen[$key])) return false;
    $seen[$key] = true;
    return true;
});
$slots = array_values($slots);

// Validate slots are not already booked
foreach ($slots as $slot) {
    $start_datetime = $date . ' ' . $slot['start'] . ':00';
    $end_datetime   = $date . ' ' . $slot['end']   . ':00';
    $court_id       = intval($slot['courtId']);

    if (strtotime($start_datetime) >= strtotime($end_datetime)) {
        $_SESSION['booking_error'] = 'ເວລາເວລາບໍ່ຖືກຕ້ອງ.';
        header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue_id . '&date=' . $date);
        exit;
    }

    try {
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM booking_detail bd
            INNER JOIN booking b ON bd.Book_ID = b.Book_ID
            WHERE bd.COURT_ID = ?
            AND b.Status_booking IN ('Confirmed', 'Pending')
            AND bd.Start_time < ? AND bd.End_time > ?
        ");
        $check->execute([$court_id, $end_datetime, $start_datetime]);
        if ($check->fetchColumn() > 0) {
            $_SESSION['booking_error'] = 'ເວລາທີ່ທ່ານເລືອກຖືກຈອງແລ້ວ. ກະລຸນາເລືອກເວລາໃໝ່.';
            header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue_id . '&date=' . $date);
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['booking_error'] = 'ການກວດສອບລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
        header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue_id . '&date=' . $date);
        exit;
    }
}

// ── NEW: Create a Pending booking in DB (no slip yet) ──
// This locks the slots so other users see them as "ກຳລັງດຳເນີນການ"
// while this customer is on the payment page. It gets deleted if:
//   (a) 10-min timer fires without payment, or
//   (b) customer cancels/leaves the payment page.
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO booking (Booking_date, Slip_payment, Status_booking, C_ID)
        VALUES (NOW(), NULL, 'Pending', ?)
    ");
    $stmt->execute([$customer_id]);
    $book_id = $pdo->lastInsertId();

    $ins = $pdo->prepare("
        INSERT INTO booking_detail (Start_time, End_time, Book_ID, COURT_ID)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($slots as $slot) {
        $ins->execute([
            $date . ' ' . $slot['start'] . ':00',
            $date . ' ' . $slot['end']   . ':00',
            $book_id,
            intval($slot['courtId']),
        ]);
    }

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    $_SESSION['booking_error'] = 'ການຈອງລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
    header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue_id . '&date=' . $date);
    exit;
}

// Store in session — includes book_id and 10-min expiry
$_SESSION['pending_booking'] = [
    'book_id'     => $book_id,       // NEW: needed to attach slip / clean up on timeout
    'venue_id'    => $venue_id,
    'date'        => $date,
    'slots'       => $slots,
    'customer_id' => $customer_id,
    'ts'          => time(),
    'expires_at'  => time() + 600,   // NEW: 10 minutes from now
];

header('Location: /Badminton_court_Booking/customer/payment/index.php');
exit;