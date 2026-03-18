<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$c_id = $_SESSION['c_id'];

// ── Handle "Mark all as read" ──
if (isset($_GET['mark_read'])) {
    $_SESSION['notif_seen'] = $_SESSION['notif_all_keys'] ?? [];
    header('Location: /Badminton_court_Booking/customer/notification/index.php');
    exit;
}

// ── Handle "Clear single notification" ──
if (isset($_GET['clear']) && is_numeric($_GET['clear'])) {
    $clear_key = $_GET['clear'];
    $seen = $_SESSION['notif_seen'] ?? [];
    // Find all keys matching this book_id and mark as seen
    $all_keys = $_SESSION['notif_all_keys'] ?? [];
    foreach ($all_keys as $k) {
        if (str_starts_with($k, $clear_key . '_')) {
            if (!in_array($k, $seen)) $seen[] = $k;
        }
    }
    $_SESSION['notif_seen'] = $seen;
    header('Location: /Badminton_court_Booking/customer/notification/index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            b.Book_ID, b.Status_booking, b.Booking_date, b.Slip_payment,
            bd.Start_time, bd.End_time,
            c.COURT_Name,
            v.VN_Name, v.VN_Address, v.Price_per_hour, v.VN_ID
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
        WHERE b.C_ID = ?
        ORDER BY b.Booking_date DESC
    ");
    $stmt->execute([$c_id]);
    $all = $stmt->fetchAll();
} catch (PDOException $e) { $all = []; }

// Group by Book_ID
$grouped = [];
foreach ($all as $n) {
    $id = $n['Book_ID'];
    if (!isset($grouped[$id])) {
        $grouped[$id] = $n;
        $grouped[$id]['slots'] = [];
    }
    $grouped[$id]['slots'][] = [
        'start' => $n['Start_time'],
        'end'   => $n['End_time'],
        'court' => $n['COURT_Name'],
    ];
}

// Build current state keys
$current_keys = [];
foreach ($grouped as $b) {
    $current_keys[] = $b['Book_ID'] . '_' . $b['Status_booking'];
}

// Store all keys for reference
$_SESSION['notif_all_keys'] = $current_keys;

$seen_keys = $_SESSION['notif_seen'] ?? [];

// Only show as "new" if status changed (key not in seen)
// Unpaid bookings are NOT shown as alerts — they go straight to "earlier"
$unread_bookings = [];
$read_bookings   = [];
foreach ($grouped as $booking) {
    $key    = $booking['Book_ID'] . '_' . $booking['Status_booking'];
    $status = $booking['Status_booking'];

    // Unpaid = customer created but hasn't paid yet — not an alert, goes to earlier
    if ($status === 'Unpaid') {
        $read_bookings[] = $booking;
    } elseif (in_array($key, $seen_keys)) {
        $read_bookings[] = $booking;
    } else {
        $unread_bookings[] = $booking;
    }
}

// Count unread for display
$unread_count = count($unread_bookings);

function calc_total($slots, $price_per_hour) {
    $price = floatval(preg_replace('/[^0-9.]/', '', $price_per_hour));
    $total = 0;
    foreach ($slots as $slot) {
        $hours = (strtotime($slot['end']) - strtotime($slot['start'])) / 3600;
        $total += $hours * $price;
    }
    return $total;
}

function get_config($status, $slip) {
    return match($status) {
        'Confirmed' => [
            'bg'=>'bg-green-50', 'border'=>'border-green-400',
            'icon_bg'=>'bg-green-100', 'icon'=>'fa-check-circle', 'icon_color'=>'text-green-500',
            'badge_bg'=>'bg-green-100', 'badge_text'=>'text-green-700',
            'title'=>'ການຈອງຢືນຢັນແລ້ວ!',
            'message'=>'ການຊຳລະເງິນຂອງທ່ານໄດ້ຮັບການກວດສອບແລ້ວ. ເດີ່ນຂອງທ່ານຈອງສຳເລັດ!',
        ],
        'Cancelled' => [
            'bg'=>'bg-red-50', 'border'=>'border-red-400',
            'icon_bg'=>'bg-red-100', 'icon'=>'fa-times-circle', 'icon_color'=>'text-red-500',
            'badge_bg'=>'bg-red-100', 'badge_text'=>'text-red-700',
            'title'=>'ການຈອງຖືກຍົກເລີກ',
            'message'=>'ການຈອງຂອງທ່ານໄດ້ຖືກຍົກເລີກແລ້ວ.',
        ],
        'Unpaid' => [
            'bg'=>'bg-blue-50', 'border'=>'border-blue-400',
            'icon_bg'=>'bg-blue-100', 'icon'=>'fa-credit-card', 'icon_color'=>'text-blue-500',
            'badge_bg'=>'bg-blue-100', 'badge_text'=>'text-blue-700',
            'title'=>'ລໍຖ້າການຊຳລະເງິນ',
            'message'=>'ກະລຸນາອັບໂຫລດໃບຮັບເງິນເພື່ອຢືນຢັນການຈອງ.',
        ],
        default => [
            'bg'=>'bg-yellow-50', 'border'=>'border-yellow-400',
            'icon_bg'=>'bg-yellow-100', 'icon'=>'fa-clock', 'icon_color'=>'text-yellow-500',
            'badge_bg'=>'bg-yellow-100', 'badge_text'=>'text-yellow-700',
            'title'=>'ລໍຖ້າການກວດສອບການຊຳລະເງິນ',
            'message'=> !empty($slip)
                ? 'ໃບຮັບເງິນຂອງທ່ານຖືກສົ່ງແລ້ວ. ລໍຖ້າເຈົ້າຂອງຢືນຢັນ.'
                : 'ກະລຸນາອັບໂຫລດໃບຮັບເງິນເພື່ອຈົບການຈອງ.',
        ],
    };
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ການແຈ້ງເຕືອນ - ລະບົບຈອງເດີ່ນ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notif-card { transition: all 0.3s ease; }
        .notif-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>

    <div class="max-w-3xl mx-auto px-4 py-8">

        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-3xl font-extrabold text-gray-800">ການແຈ້ງເຕືອນ</h1>
                <p class="text-gray-500 mt-1">ອັບເດດກ່ຽວກັບການຈອງເດີ່ນຂອງທ່ານ</p>
            </div>
            <span class="bg-gray-100 text-gray-500 font-bold px-3 py-1.5 rounded-full text-sm">
                <?= count($grouped) ?> ການຈອງ
            </span>
        </div>

        <!-- NEW notifications -->
        <?php if (!empty($unread_bookings)): ?>
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">ໃໝ່</p>
            <div class="space-y-4 mb-8">
                <?php foreach ($unread_bookings as $booking):
                    $status    = $booking['Status_booking'];
                    $total     = calc_total($booking['slots'], $booking['Price_per_hour']);
                    $deposit   = round($total * 0.30);
                    $remaining = round($total * 0.70);
                    $is_past   = strtotime($booking['Start_time']) < time();
                    $config    = get_config($status, $booking['Slip_payment']);
                ?>
                    <div class="notif-card <?= $config['bg'] ?> border-l-4 <?= $config['border'] ?> rounded-2xl p-5 shadow-sm relative">
                        <!-- Blue dot indicator -->
                        <span class="absolute top-4 right-12 w-2.5 h-2.5 bg-blue-500 rounded-full"></span>
                        <!-- Clear button -->
                        <a href="?clear=<?= $booking['Book_ID'] ?>"
                           class="absolute top-3 right-3 text-gray-300 hover:text-gray-500 transition text-lg leading-none"
                           title="ຍົກເລີກການແຈ້ງເຕືອນ">
                            <i class="fas fa-times"></i>
                        </a>
                        <div class="flex items-start gap-4">
                            <div class="<?= $config['icon_bg'] ?> p-3 rounded-full flex-shrink-0">
                                <i class="fas <?= $config['icon'] ?> <?= $config['icon_color'] ?> text-xl"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2 mb-1">
                                    <h3 class="font-bold text-gray-800"><?= $config['title'] ?></h3>
                                    <span class="<?= $config['badge_bg'] ?> <?= $config['badge_text'] ?> text-xs font-bold px-2 py-1 rounded-full flex-shrink-0 mr-6">
                                        <?php echo match($status) {
                                            'Confirmed' => 'ຢືນຢັນແລ້ວ',
                                            'Cancelled' => 'ຍົກເລີກແລ້ວ',
                                            'Pending'   => 'ລໍຖ້າ',
                                            'Unpaid'    => 'ຍັງບໍ່ໄດ້ຈ່າຍ',
                                            default     => $status
                                        }; ?>
                                    </span>
                                </div>
                                <p class="text-sm text-gray-600 mb-3"><?= $config['message'] ?></p>
                                <div class="bg-white rounded-xl p-4 mb-3 text-sm space-y-2">
                                    <div class="flex items-center gap-2 text-gray-700">
                                        <i class="fas fa-store text-blue-400 w-4"></i>
                                        <span class="font-semibold"><?= htmlspecialchars($booking['VN_Name']) ?></span>
                                    </div>
                                    <div class="flex items-center gap-2 text-gray-500">
                                        <i class="fas fa-map-marker-alt text-red-400 w-4"></i>
                                        <span><?= htmlspecialchars($booking['VN_Address']) ?></span>
                                    </div>
                                    <?php foreach ($booking['slots'] as $slot): ?>
                                        <div class="flex items-center gap-2 text-gray-600">
                                            <i class="fas fa-table-tennis text-green-400 w-4"></i>
                                            <span>
                                                <?= htmlspecialchars($slot['court']) ?> ·
                                                <?= date('d/m/Y', strtotime($slot['start'])) ?> ·
                                                <?= date('g:i A', strtotime($slot['start'])) ?> -
                                                <?= date('g:i A', strtotime($slot['end'])) ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="border-t border-gray-100 pt-2 mt-2 space-y-1">
                                        <div class="flex justify-between text-gray-500">
                                            <span>ລາຄາລວມ</span>
                                            <span class="font-medium text-gray-700">₭<?= number_format($total, 0) ?></span>
                                        </div>
                                        <?php if ($status !== 'Unpaid'): ?>
                                            <div class="flex justify-between text-green-600">
                                                <span>ມັດຈຳທີ່ຈ່າຍ (30%)</span>
                                                <span class="font-bold">₭<?= number_format($deposit, 0) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <div class="flex justify-between text-blue-600">
                                                <span>ມັດຈຳທີ່ຕ້ອງຈ່າຍ (30%)</span>
                                                <span class="font-bold">₭<?= number_format($deposit, 0) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($status === 'Confirmed' && !$is_past): ?>
                                            <div class="flex justify-between text-orange-600 font-bold">
                                                <span>ຈ່າຍທີ່ສະຖານທີ່ (70%)</span>
                                                <span>₭<?= number_format($remaining, 0) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($status === 'Confirmed' && !$is_past): ?>
                                    <div class="bg-orange-50 border border-orange-200 rounded-xl px-4 py-3 text-sm text-orange-700 flex items-start gap-2 mb-3">
                                        <i class="fas fa-info-circle mt-0.5 flex-shrink-0"></i>
                                        <span>ຢ່າລືມຈ່າຍ <strong>₭<?= number_format($remaining, 0) ?></strong> ເມື່ອທ່ານມາຮອດ.</span>
                                    </div>
                                <?php endif; ?>
                                <div class="flex flex-wrap gap-2 mt-3">
                                    <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php"
                                       class="text-xs bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded-lg font-medium transition">
                                        <i class="fas fa-eye mr-1"></i>ເບິ່ງການຈອງ
                                    </a>
                                    <?php if (in_array($status, ['Unpaid', 'Pending']) && empty($booking['Slip_payment'])): ?>
                                        <a href="/Badminton_court_Booking/customer/payment/index.php?booking_id=<?= $booking['Book_ID'] ?>"
                                           class="text-xs bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg font-medium transition">
                                            <i class="fas fa-upload mr-1"></i>ອັບໂຫລດໃບຮັບເງິນ
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($status === 'Confirmed' && $is_past): ?>
                                        <a href="/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=<?= $booking['VN_ID'] ?>"
                                           class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-medium transition">
                                            <i class="fas fa-redo mr-1"></i>ຈອງໃໝ່
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-gray-400 mt-3">
                                    <i class="fas fa-clock mr-1"></i>
                                    ຈອງວັນທີ <?= date('d/m/Y \ເວລາ g:i A', strtotime($booking['Booking_date'])) ?> · #<?= $booking['Book_ID'] ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- EARLIER (read/seen) -->
        <?php if (!empty($read_bookings)): ?>
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">ກ່ອນໜ້ານີ້</p>
                <a href="?mark_read=1"
                   class="text-xs text-red-400 hover:text-red-600 font-semibold transition flex items-center gap-1">
                    <i class="fas fa-trash-alt text-xs"></i>ລຶບທັງໝົດ
                </a>
            </div>
            <div class="space-y-3">
                <?php foreach ($read_bookings as $booking):
                    $status  = $booking['Status_booking'];
                    $config  = get_config($status, $booking['Slip_payment']);
                    $is_past = strtotime($booking['Start_time']) < time();
                ?>
                    <div class="notif-card bg-white border border-gray-100 rounded-2xl p-4 shadow-sm opacity-60 hover:opacity-100">
                        <div class="flex items-center gap-3">
                            <div class="<?= $config['icon_bg'] ?> p-2 rounded-full flex-shrink-0">
                                <i class="fas <?= $config['icon'] ?> <?= $config['icon_color'] ?> text-sm"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="font-semibold text-gray-700 text-sm"><?= htmlspecialchars($booking['VN_Name']) ?></p>
                                    <span class="<?= $config['badge_bg'] ?> <?= $config['badge_text'] ?> text-xs font-bold px-2 py-0.5 rounded-full flex-shrink-0">
                                        <?php echo match($status) {
                                            'Confirmed' => 'ຢືນຢັນແລ້ວ',
                                            'Cancelled' => 'ຍົກເລີກແລ້ວ',
                                            'Pending'   => 'ລໍຖ້າ',
                                            'Unpaid'    => 'ຍັງບໍ່ໄດ້ຈ່າຍ',
                                            default     => $status
                                        }; ?>
                                    </span>
                                </div>
                                <?php foreach ($booking['slots'] as $slot): ?>
                                    <p class="text-xs text-gray-400 mt-0.5">
                                        <?= htmlspecialchars($slot['court']) ?> ·
                                        <?= date('d/m/Y · g:i A', strtotime($slot['start'])) ?>
                                    </p>
                                <?php endforeach; ?>
                                <div class="flex gap-2 mt-2">
                                    <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php"
                                       class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1.5 rounded-lg font-medium transition">
                                        <i class="fas fa-eye mr-1"></i>ເບິ່ງ
                                    </a>
                                    <?php if (in_array($status, ['Unpaid', 'Pending']) && empty($booking['Slip_payment'])): ?>
                                        <a href="/Badminton_court_Booking/customer/payment/index.php?booking_id=<?= $booking['Book_ID'] ?>"
                                           class="text-xs bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg font-medium transition">
                                            <i class="fas fa-upload mr-1"></i>ຈ່າຍດຽວນີ້
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($status === 'Confirmed' && $is_past): ?>
                                        <a href="/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=<?= $booking['VN_ID'] ?>"
                                           class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-medium transition">
                                            <i class="fas fa-redo mr-1"></i>ຈອງໃໝ່
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Empty state -->
        <?php if (empty($grouped)): ?>
            <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                <i class="fas fa-bell-slash text-6xl text-gray-200 mb-4 block"></i>
                <h3 class="text-xl font-bold text-gray-700 mb-2">ຍັງບໍ່ມີການແຈ້ງເຕືອນ</h3>
                <p class="text-gray-400 mb-6">ທ່ານຈະເຫັນການອັບເດດການຈອງຢູ່ນີ້</p>
                <a href="/Badminton_court_Booking/customer/booking_court/index.php"
                   class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-3 rounded-xl transition">
                    <i class="fas fa-search mr-2"></i>ຊອກຫາເດີ່ນ
                </a>
            </div>
        <?php elseif (empty($unread_bookings)): ?>
            <div class="text-center py-4">
                <i class="fas fa-check-double text-2xl text-green-400 mb-1 block"></i>
                <p class="text-gray-400 text-sm">ທ່ານໄດ້ເຫັນທຸກການແຈ້ງເຕືອນແລ້ວ!</p>
            </div>
        <?php endif; ?>

    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>