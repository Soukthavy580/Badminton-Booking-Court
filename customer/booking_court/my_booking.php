<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    $_SESSION['redirect_after_login'] = '/Badminton_court_Booking/customer/booking_court/my_booking.php';
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

date_default_timezone_set('Asia/Vientiane');

$customer_id   = $_SESSION['c_id'];
$customer_name = $_SESSION['user_name'];
$filter        = $_GET['filter'] ?? 'all';

// FIX: Fetch ALL rows then group by Book_ID so multi-slot bookings show as one card
function get_customer_bookings($pdo, $customer_id, $filter = 'all') {
    try {
        $sql = "SELECT 
                    b.Book_ID, b.Booking_date, b.Status_booking, b.Slip_payment,
                    bd.ID AS detail_id, bd.Start_time, bd.End_time,
                    c.COURT_Name, c.COURT_ID,
                    v.VN_ID, v.VN_Name, v.VN_Address, v.Price_per_hour, v.VN_Image
                FROM booking b
                INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
                INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
                INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
                WHERE b.C_ID = ?";
        $params = [$customer_id];
        $now = date('Y-m-d H:i:s');

        if ($filter === 'upcoming') {
            // Booking is upcoming if ANY slot's Start_time is in the future
            $sql .= " AND b.Book_ID IN (
                SELECT DISTINCT bd2.Book_ID FROM booking_detail bd2
                INNER JOIN booking b2 ON bd2.Book_ID = b2.Book_ID
                WHERE b2.C_ID = ? AND bd2.Start_time > ? AND b2.Status_booking != 'Cancelled'
            )";
            $params[] = $customer_id;
            $params[] = $now;
        } elseif ($filter === 'past') {
            // Booking is past if ALL slots' End_time are in the past
            $sql .= " AND b.Book_ID IN (
                SELECT DISTINCT bd2.Book_ID FROM booking_detail bd2
                INNER JOIN booking b2 ON bd2.Book_ID = b2.Book_ID
                WHERE b2.C_ID = ? AND b2.Status_booking != 'Cancelled'
                GROUP BY bd2.Book_ID
                HAVING MAX(bd2.End_time) < ?
            )";
            $params[] = $customer_id;
            $params[] = $now;
        } elseif ($filter === 'cancelled') {
            $sql .= " AND b.Status_booking = 'Cancelled'";
        } elseif ($filter === 'unpaid') {
            $sql .= " AND b.Status_booking = 'Unpaid'";
        }
        $sql .= " ORDER BY b.Book_ID DESC, bd.Start_time ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // FIX: Group rows by Book_ID — one entry per booking, slots as array
        $grouped = [];
        foreach ($rows as $row) {
            $id = $row['Book_ID'];
            if (!isset($grouped[$id])) {
                $grouped[$id] = [
                    'Book_ID'        => $row['Book_ID'],
                    'Booking_date'   => $row['Booking_date'],
                    'Status_booking' => $row['Status_booking'],
                    'Slip_payment'   => $row['Slip_payment'],
                    'VN_ID'          => $row['VN_ID'],
                    'VN_Name'        => $row['VN_Name'],
                    'VN_Address'     => $row['VN_Address'],
                    'Price_per_hour' => $row['Price_per_hour'],
                    'VN_Image'       => $row['VN_Image'],
                    'slots'          => [],
                ];
            }
            $grouped[$id]['slots'][] = [
                'court_name' => $row['COURT_Name'],
                'court_id'   => $row['COURT_ID'],
                'start'      => $row['Start_time'],
                'end'        => $row['End_time'],
            ];
        }
        return array_values($grouped);
    } catch (PDOException $e) {
        return [];
    }
}

function calculate_booking_price($price_per_hour, $slots) {
    $price_clean = floatval(preg_replace('/[^0-9.]/', '', $price_per_hour));
    $total = 0;
    foreach ($slots as $slot) {
        if (!strtotime($slot['start']) || !strtotime($slot['end'])) continue;
        $hours = (strtotime($slot['end']) - strtotime($slot['start'])) / 3600;
        $total += $hours * $price_clean;
    }
    return $total;
}

function format_duration($start_time, $end_time) {
    if (!strtotime($start_time) || !strtotime($end_time)) return '-';
    $interval = (new DateTime($start_time))->diff(new DateTime($end_time));
    if ($interval->h > 0 && $interval->i > 0) return $interval->h . 'ຊມ ' . $interval->i . 'ນທ';
    if ($interval->h > 0) return $interval->h . ' ຊົ່ວໂມງ';
    return $interval->i . ' ນາທີ';
}

$all_bookings       = get_customer_bookings($pdo, $customer_id, 'all');
$upcoming_bookings  = get_customer_bookings($pdo, $customer_id, 'upcoming');
$past_bookings      = get_customer_bookings($pdo, $customer_id, 'past');
$cancelled_bookings = get_customer_bookings($pdo, $customer_id, 'cancelled');
$unpaid_bookings    = get_customer_bookings($pdo, $customer_id, 'unpaid');

$total_bookings   = count($all_bookings);
$upcoming_count   = count($upcoming_bookings);
$past_count       = count($past_bookings);
$cancelled_count  = count($cancelled_bookings);
$unpaid_count     = count($unpaid_bookings);

$bookings = match($filter) {
    'upcoming'  => $upcoming_bookings,
    'past'      => $past_bookings,
    'cancelled' => $cancelled_bookings,
    'unpaid'    => $unpaid_bookings,
    default     => $all_bookings
};
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ການຈອງຂອງຂ້ອຍ - ລະບົບຈອງເດີ່ນ</title>
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

        <div class="mb-8">
            <h1 class="text-3xl font-bold mb-2">ການຈອງຂອງຂ້ອຍ</h1>
            <p class="text-gray-600">ເບິ່ງ ແລະ ຈັດການການຈອງເດີ່ນທັງໝົດຂອງທ່ານ</p>
        </div>

        <!-- Filter Tabs -->
        <div class="flex gap-3 mb-6 flex-wrap">
            <?php
            $tabs = [
                'all'       => ['label' => 'ທັງໝົດ',        'count' => $total_bookings,  'icon' => 'fa-list',          'color' => 'bg-gray-100 text-gray-700'],
                'unpaid'    => ['label' => 'ຍັງບໍ່ໄດ້ຈ່າຍ', 'count' => $unpaid_count,    'icon' => 'fa-credit-card',   'color' => 'bg-blue-100 text-blue-700'],
                'upcoming'  => ['label' => 'ຈອງໄວ້',        'count' => $upcoming_count,  'icon' => 'fa-calendar-check','color' => 'bg-green-100 text-green-700'],
                'past'      => ['label' => 'ຜ່ານມາ',        'count' => $past_count,      'icon' => 'fa-history',       'color' => 'bg-gray-100 text-gray-700'],
                'cancelled' => ['label' => 'ຍົກເລີກແລ້ວ',   'count' => $cancelled_count, 'icon' => 'fa-times-circle',  'color' => 'bg-red-100 text-red-700'],
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
                        'Confirmed' => ['bg'=>'bg-green-100', 'text'=>'text-green-800','icon'=>'fa-check-circle', 'border'=>'border-green-500'],
                        'Pending'   => ['bg'=>'bg-yellow-100','text'=>'text-yellow-800','icon'=>'fa-clock',       'border'=>'border-yellow-500'],
                        'Unpaid'    => ['bg'=>'bg-blue-100',  'text'=>'text-blue-800', 'icon'=>'fa-credit-card', 'border'=>'border-blue-500'],
                        'Cancelled' => ['bg'=>'bg-red-100',   'text'=>'text-red-800',  'icon'=>'fa-times-circle','border'=>'border-red-500'],
                    ];
                    $status = $booking['Status_booking'];
                    $config = $status_config[$status] ?? $status_config['Pending'];
                    $slots  = $booking['slots'];

                    // Use earliest start and latest end across all slots
                    $first_start = $slots[0]['start'];
                    $last_end    = end($slots)['end'];

                    $is_past     = strtotime($last_end)    < time();
                    $is_upcoming = strtotime($first_start) > time();

                    $price        = calculate_booking_price($booking['Price_per_hour'], $slots);
                    $play_date    = strtotime($first_start) ? date('d/m/Y', strtotime($first_start)) : '-';
                    $created_date = date('d/m/Y', strtotime($booking['Booking_date']));

                    $opacity   = ($is_past || $status === 'Cancelled') ? 'opacity-75' : '';
                    $venue_img = !empty($booking['VN_Image'])
                        ? '/Badminton_court_Booking/assets/images/venues/' . basename($booking['VN_Image'])
                        : '/Badminton_court_Booking/assets/images/BookingBG.png';
                ?>
                    <div class="booking-card bg-white rounded-xl shadow-md border-l-4 <?= $config['border'] ?> <?= $opacity ?> overflow-hidden">
                        <div class="flex flex-col md:flex-row">
                            <div class="hidden md:block w-32 flex-shrink-0">
                                <img src="<?= htmlspecialchars($venue_img) ?>"
                                     alt="<?= htmlspecialchars($booking['VN_Name']) ?>"
                                     class="w-full h-full object-cover"
                                     onerror="this.src='/Badminton_court_Booking/assets/images/BookingBG.png'">
                            </div>
                            <div class="flex-1 p-6 flex flex-col md:flex-row justify-between items-start gap-4">
                                <div class="flex-1">
                                    <!-- Venue info -->
                                    <div class="flex items-center gap-3 mb-3">
                                        <div class="bg-blue-100 p-2 rounded-lg">
                                            <i class="fas fa-table-tennis text-blue-600 text-lg"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-600"><?= htmlspecialchars($booking['VN_Name']) ?></p>
                                            <p class="text-xs text-gray-500">
                                                <i class="fas fa-map-marker-alt mr-1 text-red-400"></i>
                                                <?= htmlspecialchars($booking['VN_Address']) ?>
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Date + total price row -->
                                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-3 text-sm">
                                        <div>
                                            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">ວັນທີຫຼິ້ນ</p>
                                            <p class="font-bold text-gray-800"><?= $play_date ?></p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">ຈຳນວນສລັອດ</p>
                                            <p class="font-bold text-gray-800"><?= count($slots) ?> ເດີ່ນ</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">ຈຳນວນເງິນລວມ</p>
                                            <p class="font-bold text-green-600 text-base">₭<?= number_format($price, 0) ?></p>
                                        </div>
                                    </div>

                                    <!-- FIX: Show ALL slots for this booking as a list -->
                                    <div class="space-y-1.5">
                                        <?php foreach ($slots as $slot): ?>
                                            <div class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-2 text-sm">
                                                <i class="fas fa-table-tennis text-green-500 text-xs flex-shrink-0"></i>
                                                <span class="font-semibold text-gray-700 w-6"><?= htmlspecialchars($slot['court_name']) ?></span>
                                                <span class="text-gray-400 mx-1">·</span>
                                                <span class="text-gray-600">
                                                    <?php if (strtotime($slot['start'])): ?>
                                                        <?= date('g:i A', strtotime($slot['start'])) ?> – <?= date('g:i A', strtotime($slot['end'])) ?>
                                                        <span class="text-gray-400 text-xs ml-1">(<?= format_duration($slot['start'], $slot['end']) ?>)</span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <p class="text-xs text-gray-400 mt-2">
                                        <i class="fas fa-calendar-plus mr-1"></i>ຈອງວັນທີ: <?= $created_date ?>
                                    </p>
                                </div>

                                <!-- Status + actions -->
                                <div class="flex flex-col items-start md:items-end gap-2 min-w-max">
                                    <span class="<?= $config['bg'] ?> <?= $config['text'] ?> px-3 py-1 rounded-full text-xs font-bold">
                                        <i class="fas <?= $config['icon'] ?> mr-1"></i>
                                        <?php if ($is_past && $status === 'Confirmed'): ?>ສຳເລັດແລ້ວ
                                        <?php elseif ($status === 'Confirmed'): ?>ຢືນຢັນແລ້ວ
                                        <?php elseif ($status === 'Pending'): ?>ລໍຖ້າການຢືນຢັນ
                                        <?php elseif ($status === 'Unpaid'): ?>ຍັງບໍ່ໄດ້ຈ່າຍ
                                        <?php else: ?>ຍົກເລີກແລ້ວ
                                        <?php endif; ?>
                                    </span>
                                    <p class="text-xs text-gray-400">ການຈອງ #<?= $booking['Book_ID'] ?></p>
                                    <div class="flex flex-col gap-1 mt-1">
                                        <a href="booking_detail.php?id=<?= $booking['Book_ID'] ?>"
                                           class="text-blue-600 hover:text-blue-700 font-medium text-sm">
                                            <i class="fas fa-eye mr-1"></i>ເບິ່ງລາຍລະອຽດ
                                        </a>
                                        <?php if ($status === 'Pending' || $status === 'Unpaid'): ?>
                                            <a href="/Badminton_court_Booking/customer/payment/index.php?booking_id=<?= $booking['Book_ID'] ?>"
                                               class="text-green-600 hover:text-green-700 font-medium text-sm">
                                                <i class="fas fa-credit-card mr-1"></i>ຈ່າຍດຽວນີ້
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($is_upcoming && $status !== 'Cancelled'): ?>
                                            <button onclick="confirmCancel(<?= $booking['Book_ID'] ?>)"
                                                    class="text-red-600 hover:text-red-700 font-medium text-sm text-left">
                                                <i class="fas fa-times-circle mr-1"></i>ຍົກເລີກ
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($is_past || $status === 'Cancelled'): ?>
                                            <a href="/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=<?= $booking['VN_ID'] ?>"
                                               class="text-blue-600 hover:text-blue-700 font-medium text-sm">
                                                <i class="fas fa-redo mr-1"></i>ຈອງໃໝ່
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
                        'upcoming'  => 'ບໍ່ມີການຈອງທີ່ຈອງໄວ້',
                        'past'      => 'ບໍ່ມີການຈອງທີ່ຜ່ານມາ',
                        'cancelled' => 'ບໍ່ມີການຈອງທີ່ຍົກເລີກ',
                        'unpaid'    => 'ບໍ່ມີການຈອງທີ່ຍັງຄ້າງຈ່າຍ',
                        default     => 'ຍັງບໍ່ມີການຈອງ'
                    } ?>
                </h3>
                <p class="text-gray-500 mb-6">
                    <?= $filter === 'all' ? 'ເລີ່ມຕົ້ນດ້ວຍການຊອກຫາ ແລະ ຈອງເດີ່ນທີ່ທ່ານມັກ' : 'ທ່ານບໍ່ມີການຈອງ' ?>
                </p>
                <a href="/Badminton_court_Booking/customer/booking_court/index.php"
                   class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 font-medium inline-block transition">
                    <i class="fas fa-search mr-2"></i>ຊອກຫາເດີ່ນ
                </a>
            </div>
        <?php endif; ?>

    </main>

    <script>
        function confirmCancel(bookingId) {
            if (confirm('ທ່ານແນ່ໃຈບໍ່ວ່າຕ້ອງການຍົກເລີກການຈອງນີ້? ການດຳເນີນການນີ້ບໍ່ສາມາດຍ້ອນກັບໄດ້.')) {
                window.location.href = '/Badminton_court_Booking/customer/cancellation/index.php?id=' + bookingId;
            }
        }
    </script>
</body>
</html>