<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$c_id    = $_SESSION['c_id'];
$book_id = intval($_GET['id'] ?? 0);

if (!$book_id) {
    header('Location: /Badminton_court_Booking/customer/booking_court/my_booking.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            b.Book_ID, b.Booking_date, b.Status_booking, b.Slip_payment,
            bd.Start_time, bd.End_time,
            c.COURT_Name, c.COURT_ID,
            v.VN_ID, v.VN_Name, v.VN_Address, v.VN_Description,
            v.Price_per_hour, v.VN_Image, v.Open_time, v.Close_time,
            v.VN_MapURL, v.VN_QR_Payment,
            co.Name AS owner_name, co.Phone AS owner_phone
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
        INNER JOIN court_owner co ON v.CA_ID = co.CA_ID
        WHERE b.Book_ID = ? AND b.C_ID = ?
        ORDER BY bd.Start_time ASC
    ");
    $stmt->execute([$book_id, $c_id]);
    $details = $stmt->fetchAll();
} catch (PDOException $e) {
    $details = [];
}

if (empty($details)) {
    header('Location: /Badminton_court_Booking/customer/booking_court/my_booking.php');
    exit;
}

$booking     = $details[0];
$status      = $booking['Status_booking'];
$price_clean = floatval(preg_replace('/[^0-9.]/', '', $booking['Price_per_hour']));
$is_past     = strtotime($booking['Start_time']) < time();
$is_upcoming = strtotime($booking['Start_time']) > time();

// Calculate totals
$total = 0;
foreach ($details as $d) {
    $hours = (strtotime($d['End_time']) - strtotime($d['Start_time'])) / 3600;
    $total += $hours * $price_clean;
}
$deposit   = round($total * 0.30);
$remaining = round($total * 0.70);

$venue_img = !empty($booking['VN_Image'])
    ? '/Badminton_court_Booking/assets/images/venues/' . basename($booking['VN_Image'])
    : '/Badminton_court_Booking/assets/images/BookingBG.png';

$slip_img = !empty($booking['Slip_payment'])
    ? '/Badminton_court_Booking/assets/images/slips/' . basename($booking['Slip_payment'])
    : '';

$status_config = match($status) {
    'Confirmed' => ['bg'=>'bg-green-100','text'=>'text-green-800','icon'=>'fa-check-circle','border'=>'border-green-400'],
    'Cancelled' => ['bg'=>'bg-red-100',  'text'=>'text-red-800',  'icon'=>'fa-times-circle','border'=>'border-red-400'],
    default     => ['bg'=>'bg-yellow-100','text'=>'text-yellow-800','icon'=>'fa-clock','border'=>'border-yellow-400'],
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking #<?= $book_id ?> - CourtBook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>

    <div class="max-w-3xl mx-auto px-4 py-8">

        <!-- Back -->
        <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php"
           class="inline-flex items-center gap-2 text-gray-600 hover:text-blue-600 mb-6 font-medium transition">
            <i class="fas fa-arrow-left"></i> Back to My Bookings
        </a>

        <!-- Status Banner -->
        <div class="<?= $status_config['bg'] ?> border-l-4 <?= $status_config['border'] ?> rounded-2xl p-5 mb-6 flex items-center gap-4">
            <i class="fas <?= $status_config['icon'] ?> <?= $status_config['text'] ?> text-3xl"></i>
            <div>
                <h2 class="font-extrabold text-gray-800 text-xl">
                    <?php if ($status === 'Confirmed' && $is_past): ?>
                        Booking Completed
                    <?php elseif ($status === 'Confirmed'): ?>
                        Booking Confirmed!
                    <?php elseif ($status === 'Cancelled'): ?>
                        Booking Cancelled
                    <?php else: ?>
                        Awaiting Confirmation
                    <?php endif; ?>
                </h2>
                <p class="text-gray-500 text-sm">Booking #<?= $book_id ?> · <?= date('M d, Y \a\t g:i A', strtotime($booking['Booking_date'])) ?></p>
            </div>
        </div>

        <!-- Venue Card -->
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6">
            <div class="relative h-40">
                <img src="<?= htmlspecialchars($venue_img) ?>"
                     class="w-full h-full object-cover"
                     onerror="this.src='/Badminton_court_Booking/assets/images/BookingBG.png'">
                <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                <div class="absolute bottom-0 left-0 p-4 text-white">
                    <h3 class="font-extrabold text-xl"><?= htmlspecialchars($booking['VN_Name']) ?></h3>
                    <p class="text-gray-200 text-sm">
                        <i class="fas fa-map-marker-alt mr-1 text-red-400"></i>
                        <?= htmlspecialchars($booking['VN_Address']) ?>
                    </p>
                </div>
            </div>

            <div class="p-5">
                <div class="grid grid-cols-2 gap-3 text-sm mb-4">
                    <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-user text-purple-400 w-4"></i>
                        <span><?= htmlspecialchars($booking['owner_name']) ?></span>
                    </div>
                    <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-phone text-green-400 w-4"></i>
                        <span><?= htmlspecialchars($booking['owner_phone']) ?></span>
                    </div>
                    <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-clock text-blue-400 w-4"></i>
                        <span><?= $booking['Open_time'] ?> - <?= $booking['Close_time'] ?></span>
                    </div>
                    <?php if ($booking['VN_MapURL']): ?>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-map text-red-400 w-4"></i>
                            <a href="<?= htmlspecialchars($booking['VN_MapURL']) ?>" target="_blank"
                               class="text-blue-600 hover:underline text-sm">View on Map</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Booked Slots -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <h3 class="font-bold text-gray-800 mb-4">
                <i class="fas fa-calendar-check text-green-500 mr-2"></i>Booked Slots
            </h3>
            <div class="space-y-3">
                <?php foreach ($details as $d):
                    $hours = (strtotime($d['End_time']) - strtotime($d['Start_time'])) / 3600;
                    $slot_price = $hours * $price_clean;
                ?>
                    <div class="flex items-center justify-between bg-gray-50 rounded-xl px-4 py-3 text-sm">
                        <div>
                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($d['COURT_Name']) ?></p>
                            <p class="text-gray-500 text-xs mt-0.5">
                                <?= date('D, M d Y', strtotime($d['Start_time'])) ?> ·
                                <?= date('g:i A', strtotime($d['Start_time'])) ?> -
                                <?= date('g:i A', strtotime($d['End_time'])) ?>
                                (<?= $hours ?>h)
                            </p>
                        </div>
                        <span class="font-bold text-green-600">₭<?= number_format($slot_price, 0) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <!-- Facilities -->
<?php
try {
    $stmt = $pdo->prepare("SELECT Fac_Name FROM facilities WHERE VN_ID = ? ORDER BY Fac_Name");
    $stmt->execute([$booking['VN_ID']]);
    $facilities = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $facilities = []; }
if (!empty($facilities)): ?>
<div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
    <h3 class="font-bold text-gray-800 mb-3">
        <i class="fas fa-concierge-bell text-yellow-500 mr-2"></i>Facilities
    </h3>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($facilities as $fac): ?>
            <span class="bg-green-50 border border-green-200 text-green-700 text-xs font-semibold px-3 py-1.5 rounded-xl">
                <i class="fas fa-check-circle text-green-500 mr-1"></i><?= htmlspecialchars($fac) ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Booked Slots -->

            <!-- Price Breakdown -->
            <div class="border-t border-gray-100 mt-4 pt-4 space-y-2 text-sm">
                <div class="flex justify-between text-gray-600">
                    <span>Full Price</span>
                    <span class="font-semibold">₭<?= number_format($total, 0) ?></span>
                </div>
                <div class="flex justify-between text-green-600 font-semibold">
                    <span>Deposit Paid (30%)</span>
                    <span>₭<?= number_format($deposit, 0) ?></span>
                </div>
                <?php if ($status !== 'Cancelled'): ?>
                    <div class="flex justify-between text-orange-600 font-bold text-base pt-1 border-t border-gray-100">
                        <span>Pay at Venue (70%)</span>
                        <span>₭<?= number_format($remaining, 0) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Slip -->
        <?php if ($slip_img): ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h3 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-receipt text-blue-500 mr-2"></i>Payment Slip
                </h3>
                <img src="<?= htmlspecialchars($slip_img) ?>"
                     class="max-h-48 rounded-xl border border-gray-200 mx-auto block"
                     onerror="this.style.display='none'">
            </div>
        <?php endif; ?>

        <!-- Reminder -->
        <?php if ($status === 'Confirmed' && $is_upcoming): ?>
            <div class="bg-orange-50 border border-orange-200 rounded-2xl p-5 mb-6">
                <div class="flex items-start gap-3">
                    <i class="fas fa-exclamation-circle text-orange-500 text-xl mt-0.5"></i>
                    <div>
                        <h4 class="font-bold text-orange-700 mb-1">Remember!</h4>
                        <p class="text-orange-600 text-sm">
                            Pay <strong>₭<?= number_format($remaining, 0) ?></strong> (70%) when you arrive at the venue.
                            Your booking is on <strong><?= date('D, M d Y \a\t g:i A', strtotime($booking['Start_time'])) ?></strong>.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="flex flex-wrap gap-3">
            <?php if ($status === 'Pending' && empty($booking['Slip_payment'])): ?>
                <a href="/Badminton_court_Booking/customer/payment/index.php?booking_id=<?= $book_id ?>"
                   class="flex-1 text-center bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                    <i class="fas fa-upload mr-2"></i>Upload Payment Slip
                </a>
            <?php endif; ?>
            <?php if ($is_upcoming && $status !== 'Cancelled'): ?>
                <button onclick="confirmCancel(<?= $book_id ?>)"
                        class="flex-1 text-center bg-red-50 hover:bg-red-100 text-red-600 font-bold py-3 rounded-xl border border-red-200 transition">
                    <i class="fas fa-times-circle mr-2"></i>Cancel Booking
                </button>
            <?php endif; ?>
            <?php if ($is_past || $status === 'Cancelled'): ?>
                <a href="/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=<?= $booking['VN_ID'] ?>"
                   class="flex-1 text-center bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition">
                    <i class="fas fa-redo mr-2"></i>Book Again
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function confirmCancel(id) {
            if (confirm('Are you sure you want to cancel this booking?')) {
                window.location.href = '/Badminton_court_Booking/customer/cancellation/index.php?id=' + id;
            }
        }
    </script>
</body>
</html>