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
$filter        = $_GET['filter'] ?? 'all';

// Only 5 real statuses — Unpaid is hidden (customer hasn't paid yet, not a real booking)
function get_customer_bookings($pdo, $customer_id, $filter = 'all') {
    try {
        $sql = "SELECT
                    b.Book_ID, b.Booking_date, b.Status_booking, b.Slip_payment,
                    bd.ID AS detail_id, bd.Start_time, bd.End_time,
                    c.COURT_Name, c.COURT_ID,
                    v.VN_ID, v.VN_Name, v.VN_Address, v.Price_per_hour, v.VN_Image,
                    cb.Comment AS cancel_comment
                FROM booking b
                INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
                INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
                INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
                LEFT JOIN cancel_booking cb ON b.Book_ID = cb.Book_ID
                WHERE b.C_ID = ?
                AND b.Status_booking != 'Unpaid'";  
        $params = [$customer_id];
        $now = date('Y-m-d H:i:s');

        if ($filter === 'upcoming') {
            $sql .= " AND b.Book_ID IN (
                SELECT DISTINCT bd2.Book_ID FROM booking_detail bd2
                INNER JOIN booking b2 ON bd2.Book_ID = b2.Book_ID
                WHERE b2.C_ID = ? AND bd2.Start_time > ? AND b2.Status_booking IN ('Pending','Confirmed')
            )";
            $params[] = $customer_id;
            $params[] = $now;
        } elseif ($filter === 'past') {
            $sql .= " AND b.Book_ID IN (
                SELECT DISTINCT bd2.Book_ID FROM booking_detail bd2
                INNER JOIN booking b2 ON bd2.Book_ID = b2.Book_ID
                WHERE b2.C_ID = ? AND b2.Status_booking NOT IN ('Cancelled','Unpaid')
                GROUP BY bd2.Book_ID HAVING MAX(bd2.End_time) < ?
            )";
            $params[] = $customer_id;
            $params[] = $now;
        } elseif ($filter === 'cancelled') {
            $sql .= " AND b.Status_booking = 'Cancelled'";
        } elseif ($filter === 'pending') {
            $sql .= " AND b.Status_booking = 'Pending'";
        }

        $sql .= " ORDER BY b.Book_ID DESC, bd.Start_time ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                    'cancel_comment' => $row['cancel_comment'],
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

$all_bookings       = get_customer_bookings($pdo, $customer_id, 'all');
$upcoming_bookings  = get_customer_bookings($pdo, $customer_id, 'upcoming');
$past_bookings      = get_customer_bookings($pdo, $customer_id, 'past');
$cancelled_bookings = get_customer_bookings($pdo, $customer_id, 'cancelled');
$pending_bookings   = get_customer_bookings($pdo, $customer_id, 'pending');

$bookings = match($filter) {
    'upcoming'  => $upcoming_bookings,
    'past'      => $past_bookings,
    'cancelled' => $cancelled_bookings,
    'pending'   => $pending_bookings,
    default     => $all_bookings,
};

// Status config — only 5 statuses
function status_config($status) {
    return match($status) {
        'Pending'   => ['border'=>'border-yellow-500', 'bg'=>'bg-yellow-100', 'text'=>'text-yellow-800', 'icon'=>'fa-clock',        'label'=>'ລໍຖ້າກວດສອບ'],
        'Confirmed' => ['border'=>'border-green-500',  'bg'=>'bg-green-100',  'text'=>'text-green-800',  'icon'=>'fa-check-circle', 'label'=>'ຢືນຢັນແລ້ວ'],
        'Completed' => ['border'=>'border-emerald-500','bg'=>'bg-emerald-100','text'=>'text-emerald-800','icon'=>'fa-trophy',       'label'=>'ສຳເລັດແລ້ວ'],
        'No_Show'   => ['border'=>'border-orange-500', 'bg'=>'bg-orange-100', 'text'=>'text-orange-800', 'icon'=>'fa-user-slash',   'label'=>'ບໍ່ໄດ້ມາ'],
        'Cancelled' => ['border'=>'border-red-500',    'bg'=>'bg-red-100',    'text'=>'text-red-800',    'icon'=>'fa-times-circle', 'label'=>'ຍົກເລີກ'],
        default     => ['border'=>'border-gray-400',   'bg'=>'bg-gray-100',   'text'=>'text-gray-700',   'icon'=>'fa-question',     'label'=>$status],
    };
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ການຈອງຂອງຂ້ອຍ - ລະບົບຈອງເດີ່ນ</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .booking-card { transition: all 0.3s ease; }
        .booking-card:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>

    <main class="max-w-4xl mx-auto px-4 py-8">

        <div class="mb-8">
            <h1 class="text-3xl font-bold mb-1">ການຈອງຂອງຂ້ອຍ</h1>
            <p class="text-gray-500">ການຈອງເດີ່ນທັງໝົດຂອງທ່ານ</p>
        </div>

        <!-- Filter Tabs — only 5 real statuses -->
        <div class="flex gap-2 mb-6 flex-wrap">
            <?php foreach ([
                'all'       => ['label'=>'ທັງໝົດ',    'count'=>count($all_bookings),       'icon'=>'fa-list'],
                'pending'   => ['label'=>'ກຳລັງດຳເນີນການ',     'count'=>count($pending_bookings),   'icon'=>'fa-clock'],
                'upcoming'  => ['label'=>'ຈອງໄວ້',   'count'=>count($upcoming_bookings),  'icon'=>'fa-calendar-check'],
                'past'      => ['label'=>'ຜ່ານມາ',   'count'=>count($past_bookings),      'icon'=>'fa-history'],
                'cancelled' => ['label'=>'ຍົກເລີກ',  'count'=>count($cancelled_bookings), 'icon'=>'fa-times-circle'],
            ] as $key => $tab): ?>
                <a href="?filter=<?= $key ?>"
                   class="px-4 py-2 rounded-xl font-semibold text-sm transition flex items-center gap-2
                          <?= $filter===$key ? 'bg-blue-600 text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                    <i class="fas <?= $tab['icon'] ?>"></i>
                    <?= $tab['label'] ?>
                    <?php if ($tab['count'] > 0): ?>
                        <span class="px-1.5 py-0.5 rounded-full text-xs font-bold
                                     <?= $filter===$key ? 'bg-white text-blue-600' : 'bg-gray-100 text-gray-500' ?>">
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
                    $status  = $booking['Status_booking'];
                    $cfg     = status_config($status);
                    $slots   = $booking['slots'];
                    $price   = calculate_booking_price($booking['Price_per_hour'], $slots);
                    $deposit = round($price * 0.30);

                    $first_start = $slots[0]['start'];
                    $last_end    = end($slots)['end'];
                    $is_past     = strtotime($last_end) < time();
                    // FIX: upcoming = booking date is today or future, not yet past end time
                    $is_upcoming = !$is_past && !in_array($status, ['Cancelled','Completed','No_Show']);
                    $play_date   = strtotime($first_start) ? date('d/m/Y', strtotime($first_start)) : '-';

                    $venue_img = !empty($booking['VN_Image'])
                        ? '/Badminton_court_Booking/assets/images/venues/'.basename($booking['VN_Image'])
                        : '/Badminton_court_Booking/assets/images/BookingBG.png';
                ?>
                    <div class="booking-card bg-white rounded-2xl shadow-sm border-l-4 <?= $cfg['border'] ?> overflow-hidden <?= ($is_past || $status==='Cancelled') ? 'opacity-80' : '' ?>">
                        <div class="flex flex-col md:flex-row">
                            <div class="hidden md:block w-28 flex-shrink-0">
                                <img src="<?= htmlspecialchars($venue_img) ?>" alt=""
                                     class="w-full h-full object-cover"
                                     onerror="this.src='/Badminton_court_Booking/assets/images/BookingBG.png'">
                            </div>
                            <div class="flex-1 p-5 flex flex-col md:flex-row justify-between gap-4">
                                <div class="flex-1">
                                    <!-- Venue -->
                                    <div class="flex items-center gap-2 mb-3">
                                        <i class="fas fa-store text-blue-500 text-sm"></i>
                                        <div>
                                            <p class="font-bold text-gray-800"><?= htmlspecialchars($booking['VN_Name']) ?></p>
                                            <p class="text-xs text-gray-400"><i class="fas fa-map-marker-alt mr-1 text-red-400"></i><?= htmlspecialchars($booking['VN_Address']) ?></p>
                                        </div>
                                    </div>

                                    <!-- Summary row -->
                                    <div class="grid grid-cols-3 gap-3 mb-3 text-sm">
                                        <div>
                                            <p class="text-xs text-gray-400 uppercase tracking-wide">ວັນທີ</p>
                                            <p class="font-bold text-gray-800"><?= $play_date ?></p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-400 uppercase tracking-wide">ເວລາ</p>
                                            <p class="font-bold text-gray-800"><?= count($slots) ?> ເວລາ</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-400 uppercase tracking-wide">ລາຄາ</p>
                                            <p class="font-bold text-green-600">₭<?= number_format($price) ?></p>
                                        </div>
                                    </div>

                                    <!-- Slots list -->
                                    <div class="space-y-1 mb-3">
                                        <?php foreach ($slots as $slot): ?>
                                            <div class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-2 text-xs">
                                                <i class="fas fa-table-tennis text-green-400 flex-shrink-0"></i>
                                                <span class="font-semibold text-gray-700"><?= htmlspecialchars($slot['court_name']) ?></span>
                                                <span class="text-gray-400">·</span>
                                                <span class="text-gray-600">
                                                    <?= date('H:i', strtotime($slot['start'])) ?> – <?= date('H:i', strtotime($slot['end'])) ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Cancel reason -->
                                    <?php if ($status === 'Cancelled' && !empty($booking['cancel_comment'])): ?>
                                        <div class="bg-red-50 border border-red-200 rounded-xl px-3 py-2 text-xs text-red-600 flex items-start gap-2 mb-2">
                                            <i class="fas fa-comment-alt mt-0.5 flex-shrink-0"></i>
                                            <span><strong>ເຫດຜົນ:</strong> <?= htmlspecialchars($booking['cancel_comment']) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <p class="text-xs text-gray-400">
                                        <i class="fas fa-calendar-plus mr-1"></i>ຈອງ: <?= date('d/m/Y H:i', strtotime($booking['Booking_date'])) ?> · #<?= $booking['Book_ID'] ?>
                                    </p>
                                </div>

                                <!-- Status + actions -->
                                <div class="flex flex-col items-start md:items-end gap-2 flex-shrink-0">
                                    <span class="<?= $cfg['bg'] ?> <?= $cfg['text'] ?> px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1">
                                        <i class="fas <?= $cfg['icon'] ?>"></i><?= $cfg['label'] ?>
                                    </span>

                                    <!-- Deposit info for Pending -->
                                    <?php if ($status === 'Pending'): ?>
                                        <div class="text-xs text-yellow-600 bg-yellow-50 border border-yellow-200 rounded-lg px-2 py-1">
                                            <i class="fas fa-clock mr-1"></i>ລໍຖ້າເຈົ້າຂອງຢືນຢັນ
                                        </div>
                                    <?php endif; ?>

                                    <!-- Remaining for Confirmed -->
                                    <?php if ($status === 'Confirmed' && !$is_past): ?>
                                        <div class="text-xs text-orange-600 bg-orange-50 border border-orange-200 rounded-lg px-2 py-1">
                                            <i class="fas fa-store mr-1"></i>ຈ່າຍ ₭<?= number_format($price - $deposit) ?> ທີ່ສະຖານທີ່
                                        </div>
                                    <?php endif; ?>

                                    <!-- Action links -->
                                    <div class="flex flex-col gap-1 mt-1">
                                        <?php if ($status === 'Pending'): ?>
                                            <a href="/Badminton_court_Booking/customer/payment/index.php?booking_id=<?= $booking['Book_ID'] ?>"
                                               class="text-xs text-green-600 hover:text-green-700 font-semibold">
                                                <i class="fas fa-eye mr-1"></i>ເບິ່ງການຈອງ
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!$is_past && in_array($status, ['Pending','Confirmed'])): ?>
                                            <button onclick="confirmCancel(<?= $booking['Book_ID'] ?>)"
                                                    class="text-xs text-red-500 hover:text-red-600 font-semibold text-left">
                                                <i class="fas fa-times-circle mr-1"></i>ຍົກເລີກ
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($is_past || in_array($status, ['Cancelled','Completed','No_Show'])): ?>
                                            <a href="/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=<?= $booking['VN_ID'] ?>"
                                               class="text-xs text-blue-600 hover:text-blue-700 font-semibold">
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
            <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                <i class="fas fa-calendar-times text-6xl text-gray-200 mb-4 block"></i>
                <h3 class="text-xl font-bold text-gray-700 mb-2">
                    <?= match($filter) {
                        'upcoming'  => 'ບໍ່ມີການຈອງທີ່ຈອງໄວ້',
                        'past'      => 'ບໍ່ມີການຈອງທີ່ຜ່ານມາ',
                        'cancelled' => 'ບໍ່ມີການຈອງທີ່ຍົກເລີກ',
                        'pending'   => 'ບໍ່ມີການຈອງທີ່ລໍຖ້າ',
                        default     => 'ຍັງບໍ່ມີການຈອງ'
                    } ?>
                </h3>
                <a href="/Badminton_court_Booking/customer/booking_court/index.php"
                   class="mt-4 inline-block bg-blue-600 text-white px-6 py-3 rounded-xl font-semibold hover:bg-blue-700 transition">
                    <i class="fas fa-search mr-2"></i>ຊອກຫາເດີ່ນ
                </a>
            </div>
        <?php endif; ?>

    </main>

    <script>
        async function confirmCancel(bookingId) {
            const ok = await (window.BBCAlert && window.BBCAlert.confirm
                ? window.BBCAlert.confirm({
                    icon: 'warning',
                    title: 'ຢືນຢັນ',
                    text: 'ທ່ານແນ່ໃຈບໍ່ວ່າຕ້ອງການຍົກເລີກການຈອງນີ້?',
                    confirmButtonText: 'ຢືນຢັນ',
                    cancelButtonText: 'ຍົກເລີກ'
                })
                : Promise.resolve(confirm('ທ່ານແນ່ໃຈບໍ່ວ່າຕ້ອງການຍົກເລີກການຈອງນີ້?'))
            );
            if (ok) {
                window.location.href = '/Badminton_court_Booking/customer/cancellation/index.php?id=' + bookingId;
            }
        }
    </script>
</body>
</html>