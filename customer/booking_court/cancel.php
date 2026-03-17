<?php
session_start();
require_once '../../config/db.php';

// Must be logged in as customer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /Badminton_court_Booking/customer/bookings/index.php');
    exit;
}

$booking_id  = intval($_POST['booking_id'] ?? 0);
$customer_id = $_SESSION['c_id'];

if (!$booking_id) {
    header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
    exit;
}

try {
    // Verify booking belongs to this customer and is still cancellable
    $stmt = $pdo->prepare("
        SELECT Book_ID, Status_booking, Slip_payment
        FROM booking
        WHERE Book_ID = ? AND C_ID = ?
    ");
    $stmt->execute([$booking_id, $customer_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $_SESSION['booking_error'] = 'Booking not found.';
        header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
        exit;
    }

    // Only allow cancel if Pending
    if ($booking['Status_booking'] !== 'Pending') {
        $_SESSION['booking_error'] = 'This booking cannot be cancelled at this stage. Please contact the venue owner.';
        header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
        exit;
    }

    // Delete slip file if exists
    if (!empty($booking['Slip_payment'])) {
        $slip_path = '../../assets/images/slips/' . $booking['Slip_payment'];
        if (file_exists($slip_path)) {
            unlink($slip_path);
        }
    }

    // Delete booking details first (FK constraint)
    $pdo->prepare("DELETE FROM booking_detail WHERE Book_ID = ?")
        ->execute([$booking_id]);

    // Delete booking record
    $pdo->prepare("DELETE FROM booking WHERE Book_ID = ? AND C_ID = ?")
        ->execute([$booking_id, $customer_id]);

    $_SESSION['booking_success'] = 'Booking #' . $booking_id . ' has been cancelled successfully.';
    header('Location: /Badminton_court_Booking/customer/bookings/index.php');
    exit;

} catch (PDOException $e) {
    error_log("Cancel booking error: " . $e->getMessage());
    $_SESSION['booking_error'] = 'Failed to cancel booking. Please try again.';
    header('Location: /Badminton_court_Booking/customer/bookings/index.php');
    exit;
}