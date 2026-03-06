<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    $_SESSION['redirect_after_login'] = '/Badminton_court_Booking/customer/booking_court/my_booking.php';
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$customer_id   = $_SESSION['c_id'];
$customer_name = $_SESSION['user_name'];
$filter        = $_GET['filter'] ?? 'all';

function get_customer_bookings($pdo, $customer_id, $filter = 'all') {
    try {
        $sql = "SELECT 
                    b.Book_ID,
                    b.Booking_date,
                    b.Status_booking,
                    b.Slip_payment,
                    bd.ID AS detail_id,
                    bd.Start_time,
                    bd.End_time,
                    c.COURT_Name,
                    c.COURT_ID,
                    v.VN_ID,
                    v.VN_Name,
                    v.VN_Address,
                    v.Price_per_hour,
                    v.VN_Image
                FROM booking b
                INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
                INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
                INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
                WHERE b.C_ID = ?";

        $params = [$customer_id];
        $now = date('Y-m-d H:i:s');

        if ($filter === 'upcoming') {
            $sql .= " AND bd.Start_time > ? AND b.Status_booking != 'Cancelled'";
            $params[] = $now;
        } elseif ($filter === 'past') {
            $sql .= " AND bd.End_time < ? AND b.Status_booking != 'Cancelled'";
            $params[] = $now;
        } elseif ($filter === 'cancelled') {
            $sql .= " AND b.Status_booking = 'Cancelled'";
        }

        $sql .= " ORDER BY bd.Start_time DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching bookings: " . $e->getMessage());
        return [];
    }
}

function calculate_booking_price($price_per_hour, $start_time, $end_time) {
    $start = new DateTime($start_time);
    $end   = new DateTime($end_time);
    $hours = $start->diff($end)->h + ($start->diff($end)->i / 60);
    $price_clean = preg_replace('/[^0-9.]/', '', $price_per_hour);
    return $hours * floatval($price_clean);
}

function format_duration($start_time, $end_time) {
    $interval = (new DateTime($start_time))->diff(new DateTime($end_time));
    if ($interval->h > 0 && $interval->i > 0) return $interval->h . 'h ' . $interval->i . 'm';
    if ($interval->h > 0) return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '');
    return $interval->i . ' minutes';
}

// Fetch all once, count from memory
$all_bookings      = get_customer_bookings($pdo, $customer_id, 'all');
$upcoming_bookings = get_customer_bookings($pdo, $customer_id, 'upcoming');
$past_bookings     = get_customer_bookings($pdo, $customer_id, 'past');
$cancelled_bookings= get_customer_bookings($pdo, $customer_id, 'cancelled');

$total_bookings    = count($all_bookings);
$upcoming_count    = count($upcoming_bookings);
$past_count        = count($past_bookings);
$cancelled_count   = count($cancelled_bookings);

// Show the right set based on filter
$bookings = match($filter) {
    'upcoming'  => $upcoming_bookings,
    'past'      => $past_bookings,
    'cancelled' => $cancelled_bookings,
    default     => $all_bookings
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - CourtBook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .booking-card { transition: all 0.3s ease; }
        .booking-card:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>

    <main class="max-w-7xl mx-auto px-4 py-8">

        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold mb-2">My Bookings</h1>
            <p class="text-gray-600">View and manage all your court bookings</p>
        </div>

        <!-- Filter Tabs -->
        <div class="flex gap-3 mb-6 flex-wrap">
            <?php
            $tabs = [
                'all'       => ['label' => 'All Bookings',  'count' => $total_bookings,   'icon' => 'fa-list',         'color' => 'bg-gray-100 text-gray-700'],
                'upcoming'  => ['label' => 'Upcoming',      'count' => $upcoming_count,   'icon' => 'fa-calendar-check','color' => 'bg-green-100 text-green-700'],
                'past'      => ['label' => 'Past',          'count' => $past_count,       'icon' => 'fa-history',      'color' => 'bg-gray-100 text-gray-700'],
                'cancelled' => ['label' => 'Cancelled',     'count' => $cancelled_count,  'icon' => 'fa-times-circle', 'color' => 'bg-red-100 text-red-700'],
            ];
            foreach ($tabs as $key => $tab):
            ?>
                <a href="?filter=<?= $key ?>"
                   class="px-4 py-2 rounded-lg font-medium transition flex items-center gap-2
                          <?= $filter === $key ? 'bg-blue-600 text-white shadow' : 'border border-gray-300 text-gray-700 hover:bg-gray-50' ?>">
                    <i class="fas <?= $tab['icon'] ?>"></i>
                    <?= $tab['label'] ?>
                    <?php if ($tab['count'] > 0): ?>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold
                                     <?= $filter === $key ? 'bg-white text-blue-600' : $tab['color'] ?>">
                            <?= $tab['count'] ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Bookings List -->
        <?php if (!empty($bookings)): ?>
            <div class="space-y-4">
                <?php foreach ($bookings as $booking):
                    $status_config = [
                        'Confirmed' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'icon' => 'fa-check-circle',  'border' => 'border-green-500'],
                        'Pending'   => ['bg' => 'bg-yellow-100','text' => 'text-yellow-800','icon' => 'fa-clock',          'border' => 'border-yellow-500'],
                        'Cancelled' => ['bg' => 'bg-red-100',   'text' => 'text-red-800',   'icon' => 'fa-times-circle',  'border' => 'border-red-500'],
                    ];
                    $status    = $booking['Status_booking'];
                    $config    = $status_config[$status] ?? $status_config['Pending'];
                    $is_past   = strtotime($booking['End_time'])   < time();
                    $is_upcoming = strtotime($booking['Start_time']) > time();
                    $price     = calculate_booking_price($booking['Price_per_hour'], $booking['Start_time'], $booking['End_time']);
                    $book_date = date('M d, Y', strtotime($booking['Start_time']));
                    $start_t   = date('g:i A', strtotime($booking['Start_time']));
                    $end_t     = date('g:i A', strtotime($booking['End_time']));
                    $duration  = format_duration($booking['Start_time'], $booking['End_time']);
                    $opacity   = ($is_past || $status === 'Cancelled') ? 'opacity-75' : '';
                    $venue_img = !empty($booking['VN_Image'])
                        ? '/Badminton_court_Booking/assets/images/venues/' . basename($booking['VN_Image'])
                        : '/Badminton_court_Booking/assets/images/BookingBG.png';
                ?>
                    <div class="booking-card bg-white rounded-xl shadow-md border-l-4 <?= $config['border'] ?> <?= $opacity ?> overflow-hidden">
                        <div class="flex flex-col md:flex-row">
                            <!-- Venue Image -->
                            <div class="hidden md:block w-32 flex-shrink-0">
                                <img src="<?= htmlspecialchars($venue_img) ?>"
                                     alt="<?= htmlspecialchars($booking['VN_Name']) ?>"
                                     class="w-full h-full object-cover"
                                     onerror="this.src='/Badminton_court_Booking/assets/images/BookingBG.png'">
                            </div>

                            <div class="flex-1 p-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                                <!-- Left Info -->
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-3">
                                        <div class="bg-blue-100 p-2 rounded-lg">
                                            <i class="fas fa-table-tennis text-blue-600 text-lg"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($booking['COURT_Name']) ?></h3>
                                            <p class="text-sm font-medium text-gray-600"><?= htmlspecialchars($booking['VN_Name']) ?></p>
                                            <p class="text-xs text-gray-500">
                                                <i class="fas fa-map-marker-alt mr-1 text-red-400"></i>
                                                <?= htmlspecialchars($booking['VN_Address']) ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-3 text-sm">
                                        <div>
                                            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Date</p>
                                            <p class="font-bold text-gray-800"><?= $book_date ?></p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Time</p>
                                            <p class="font-bold text-gray-800"><?= $start_t ?> - <?= $end_t ?></p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Duration</p>
                                            <p class="font-bold text-gray-800"><?= $duration ?></p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Amount</p>
                                            <p class="font-bold text-green-600 text-base">₭<?= number_format($price, 0) ?></p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right Actions -->
                                <div class="flex flex-col items-start md:items-end gap-2 min-w-max">
                                    <!-- Status -->
                                    <span class="<?= $config['bg'] ?> <?= $config['text'] ?> px-3 py-1 rounded-full text-xs font-bold">
                                        <i class="fas <?= $config['icon'] ?> mr-1"></i>
                                        <?= ($is_past && $status === 'Confirmed') ? 'Completed' : $status ?>
                                    </span>
                                    <p class="text-xs text-gray-400">Booking #<?= $booking['Book_ID'] ?></p>

                                    <!-- Action Buttons -->
                                    <div class="flex flex-col gap-1 mt-1">
                                        <a href="booking_detail.php?id=<?= $booking['Book_ID'] ?>"
                                           class="text-blue-600 hover:text-blue-700 font-medium text-sm">
                                            <i class="fas fa-eye mr-1"></i>View Details
                                        </a>
                                        <?php if ($status === 'Pending'): ?>
                                            <a href="/Badminton_court_Booking/customer/payment/index.php?booking_id=<?= $booking['Book_ID'] ?>"
                                               class="text-green-600 hover:text-green-700 font-medium text-sm">
                                                <i class="fas fa-credit-card mr-1"></i>Pay Now
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($is_upcoming && $status !== 'Cancelled'): ?>
                                            <button onclick="confirmCancel(<?= $booking['Book_ID'] ?>)"
                                                    class="text-red-600 hover:text-red-700 font-medium text-sm text-left">
                                                <i class="fas fa-times-circle mr-1"></i>Cancel
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($is_past || $status === 'Cancelled'): ?>
                                            <a href="/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=<?= $booking['VN_ID'] ?>"
                                               class="text-blue-600 hover:text-blue-700 font-medium text-sm">
                                                <i class="fas fa-redo mr-1"></i>Rebook
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="bg-white rounded-xl shadow-md p-12 text-center">
                <i class="fas fa-calendar-times text-6xl text-gray-200 mb-4 block"></i>
                <h3 class="text-xl font-bold text-gray-800 mb-2">
                    <?= match($filter) {
                        'upcoming'  => 'No Upcoming Bookings',
                        'past'      => 'No Past Bookings',
                        'cancelled' => 'No Cancelled Bookings',
                        default     => 'No Bookings Yet'
                    } ?>
                </h3>
                <p class="text-gray-500 mb-6">
                    <?= $filter === 'all' ? 'Start by browsing and booking your favourite court' : "You don't have any {$filter} bookings" ?>
                </p>
                <a href="/Badminton_court_Booking/customer/booking_court/index.php"
                   class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 font-medium inline-block transition">
                    <i class="fas fa-search mr-2"></i>Browse Courts
                </a>
            </div>
        <?php endif; ?>

    </main>


    <script>
        function confirmCancel(bookingId) {
            if (confirm('Are you sure you want to cancel this booking? This action cannot be undone.')) {
                window.location.href = '/Badminton_court_Booking/customer/cancellation/index.php?id=' + bookingId;
            }
        }
    </script>
</body>
</html>