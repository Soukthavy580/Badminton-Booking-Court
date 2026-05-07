<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

date_default_timezone_set('Asia/Vientiane');

$ca_id = $_SESSION['ca_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM Venue_data WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $venue = $stmt->fetch();
} catch (PDOException $e) { $venue = null; }

if (!$venue) {
    header('Location: /Badminton_court_Booking/owner/manage_court/index.php');
    exit;
}

$vn_id  = $venue['VN_ID'];
$period = $_GET['period'] ?? 'month';

$date_from = match ($period) {
    'week'  => date('Y-m-d', strtotime('-7 days')),
    'month' => date('Y-m-d', strtotime('-30 days')),
    'year'  => date('Y-m-d', strtotime('-1 year')),
    default => date('Y-m-d', strtotime('-30 days')),
};

$period_label = match($period) { 'week' => '7 ວັນຜ່ານມາ', 'year' => '1 ປີຜ່ານມາ', default => '30 ວັນຜ່ານມາ' };

// Revenue summary
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(
                CASE
                    WHEN b.Status_booking = 'Completed'
                        THEN TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour
                    WHEN b.Status_booking IN ('Confirmed', 'No_Show')
                        THEN TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour * 0.30
                    WHEN b.Status_booking = 'Cancelled'
                         AND b.Slip_payment IS NOT NULL AND b.Slip_payment != ''
                        THEN TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour * 0.30
                    ELSE 0
                END
            ), 0) AS total_revenue,
            COALESCE(SUM(
                CASE
                    WHEN b.Status_booking = 'Completed'
                        THEN TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour * 0.30
                    WHEN b.Status_booking IN ('Confirmed', 'No_Show')
                        THEN TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour * 0.30
                    WHEN b.Status_booking = 'Cancelled'
                         AND b.Slip_payment IS NOT NULL AND b.Slip_payment != ''
                        THEN TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour * 0.30
                    ELSE 0
                END
            ), 0) AS deposit_received,
            COALESCE(SUM(
                CASE
                    WHEN b.Status_booking = 'Completed'
                        THEN TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour * 0.70
                    ELSE 0
                END
            ), 0) AS onsite_received,
            COALESCE(SUM(
                CASE
                    WHEN b.Status_booking = 'Cancelled'
                         AND b.Slip_payment IS NOT NULL AND b.Slip_payment != ''
                        THEN TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour * 0.30
                    ELSE 0
                END
            ), 0) AS cancelled_kept
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v      ON c.VN_ID = v.VN_ID
        WHERE c.VN_ID = ? AND DATE(b.Booking_date) >= ?
    ");
    $stmt->execute([$vn_id, $date_from]);
    $revenue = $stmt->fetch();
} catch (PDOException $e) {
    $revenue = ['total_revenue'=>0,'deposit_received'=>0,'onsite_received'=>0,'cancelled_kept'=>0];
}

// Monthly data
try {
    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(b.Booking_date,'%Y-%m') AS month,
            DATE_FORMAT(b.Booking_date,'%b %Y') AS month_label,
            COUNT(DISTINCT b.Book_ID) AS bookings,
            COUNT(DISTINCT CASE WHEN b.Status_booking='Confirmed' THEN b.Book_ID END) AS confirmed,
            COUNT(DISTINCT CASE WHEN b.Status_booking='Completed' THEN b.Book_ID END) AS completed,
            COUNT(DISTINCT CASE WHEN b.Status_booking='Cancelled' THEN b.Book_ID END) AS cancelled,
            COALESCE(SUM(
                CASE
                    WHEN b.Status_booking = 'Completed'
                        THEN TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)*v.Price_per_hour
                    WHEN b.Status_booking IN ('Confirmed','No_Show')
                        THEN TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)*v.Price_per_hour*0.30
                    WHEN b.Status_booking = 'Cancelled'
                         AND b.Slip_payment IS NOT NULL AND b.Slip_payment != ''
                        THEN TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)*v.Price_per_hour*0.30
                    ELSE 0
                END
            ),0) AS revenue
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v      ON c.VN_ID = v.VN_ID
        WHERE c.VN_ID = ? AND b.Status_booking != 'Unpaid'
        GROUP BY DATE_FORMAT(b.Booking_date,'%Y-%m')
        ORDER BY month ASC LIMIT 12
    ");
    $stmt->execute([$vn_id]);
    $monthly_data = $stmt->fetchAll();
} catch (PDOException $e) { $monthly_data = []; }

// Revenue detail per booking
try {
    $stmt = $pdo->prepare("
        SELECT
            b.Book_ID, b.Booking_date, b.Status_booking,
            cu.Name AS customer_name, cu.Phone AS customer_phone,
            GROUP_CONCAT(DISTINCT c.COURT_Name ORDER BY c.COURT_Name SEPARATOR ', ') AS courts,
            MIN(bd.Start_time) AS first_slot, MAX(bd.End_time) AS last_slot,
            SUM(TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)) AS total_hours,
            CASE
                WHEN b.Status_booking = 'Completed'
                    THEN SUM(TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)) * v.Price_per_hour
                WHEN b.Status_booking IN ('Confirmed','No_Show')
                    THEN SUM(TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)) * v.Price_per_hour * 0.30
                WHEN b.Status_booking = 'Cancelled'
                     AND b.Slip_payment IS NOT NULL AND b.Slip_payment != ''
                    THEN SUM(TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)) * v.Price_per_hour * 0.30
                ELSE 0
            END AS booking_revenue
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v      ON c.VN_ID = v.VN_ID
        INNER JOIN customer cu       ON b.C_ID = cu.C_ID
        WHERE c.VN_ID = ? AND DATE(b.Booking_date) >= ?
          AND b.Status_booking NOT IN ('Unpaid','Pending')
        GROUP BY b.Book_ID, v.Price_per_hour
        HAVING booking_revenue > 0
        ORDER BY b.Booking_date ASC LIMIT 100
    ");
    $stmt->execute([$vn_id, $date_from]);
    $revenue_bookings = $stmt->fetchAll();
} catch (PDOException $e) { $revenue_bookings = []; }
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລາຍງານລາຍຮັບ - <?= htmlspecialchars($venue['VN_Name']) ?></title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); }
        @media print {
            .no-print, aside, header { display: none !important; }
            body { background: white !important; }
            .flex.min-h-screen { display: block !important; }
            main { padding: 0 !important; }
            .print-header { display: block !important; }
            .stat-card { box-shadow: none !important; border: 1px solid #e5e7eb !important; break-inside: avoid; }
            table { break-inside: auto; } tr { break-inside: avoid; }
            canvas { max-height: 200px !important; }
            @page { margin: 1.5cm; size: A4; }
        }
        .print-header { display: none; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">

        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40 no-print">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">ລາຍງານຂໍ້ມູນລາຍຮັບ</h1>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars($venue['VN_Name']) ?></p>
                </div>
                <div class="flex gap-2 items-center flex-wrap">
                    <?php foreach (['week'=>'7 ວັນ','month'=>'30 ວັນ','year'=>'1 ປີ'] as $key=>$label): ?>
                        <a href="?period=<?= $key ?>"
                           class="px-4 py-2 rounded-xl font-semibold text-sm transition
                                  <?= $period===$key ? 'bg-green-600 text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                            <?= $label ?>
                        </a>
                    <?php endforeach; ?>
                    <button onclick="window.print()"
                            class="flex items-center gap-2 bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-xl font-semibold text-sm transition shadow">
                        <i class="fas fa-print"></i>ພິມ
                    </button>
                </div>
            </div>
            <!-- Tabs -->
            <div class="flex gap-1 mt-4 border-b border-gray-100 pb-0 overflow-x-auto">
                <?php
                $tabs = [
                    'index.php'               => ['icon'=>'fa-chart-pie',      'label'=>'ພາບລວມທັງໝົດ'],
                    'booking_report.php'      => ['icon'=>'fa-calendar-check', 'label'=>'ລາຍງານການຈອງ'],
                    'cancellation_report.php' => ['icon'=>'fa-calendar-times', 'label'=>'ລາຍງານການຍົກເລີກ'],
                    'revenue_report.php'      => ['icon'=>'fa-coins',           'label'=>'ລາຍງານຂໍ້ມູນລາຍຮັບ'],
                ];
                $current_file = basename($_SERVER['PHP_SELF']);
                foreach ($tabs as $file => $tab): ?>
                    <a href="<?= $file ?>?period=<?= $period ?>"
                       class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-t-xl transition border-b-2 whitespace-nowrap
                              <?= $current_file === $file ? 'border-green-600 text-green-700 bg-green-50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>">
                        <i class="fas <?= $tab['icon'] ?> text-xs"></i><?= $tab['label'] ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </header>

        <main class="flex-1 p-6">

            <!-- Print header -->
            <div class="print-header mb-6 pb-4 border-b-2 border-gray-800">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">ລາຍງານຂໍ້ມູນລາຍຮັບ</h1>
                        <p class="text-gray-600"><?= htmlspecialchars($venue['VN_Name']) ?> · <?= htmlspecialchars($venue['VN_Address']) ?></p>
                    </div>
                    <div class="text-right text-sm text-gray-500">
                        <p>ໄລຍະ: <?= $period_label ?></p>
                        <p>ວັນທີພິມ: <?= date('d/m/Y H:i') ?></p>
                    </div>
                </div>
            </div>

            <!-- Revenue Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-coins text-white"></i>
                    </div>
                    <p class="text-2xl font-extrabold">₭<?= number_format($revenue['total_revenue']) ?></p>
                    <p class="text-green-100 text-sm mt-1">ລາຍຮັບທັງໝົດ</p>
                    <p class="text-green-200 text-xs mt-0.5">ທຸກສະຖານະລວມກັນ</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-mobile-alt text-white"></i>
                    </div>
                    <p class="text-2xl font-extrabold">₭<?= number_format($revenue['deposit_received']) ?></p>
                    <p class="text-blue-100 text-sm mt-1">ຮັບອອນລາຍ (30%)</p>
                    <p class="text-blue-200 text-xs mt-0.5">ລວມທຸກການຈ່າຍ</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-store text-white"></i>
                    </div>
                    <p class="text-2xl font-extrabold">₭<?= number_format($revenue['onsite_received']) ?></p>
                    <p class="text-purple-100 text-sm mt-1">ຮັບທີ່ສະຖານທີ່ (70%)</p>
                    <p class="text-purple-200 text-xs mt-0.5">ສຳເລັດເທົ່ານັ້ນ</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-red-500 to-red-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-ban text-white"></i>
                    </div>
                    <p class="text-2xl font-extrabold">₭<?= number_format($revenue['cancelled_kept']) ?></p>
                    <p class="text-red-100 text-sm mt-1">ມັດຈຳຍົກເລີກ</p>
                    <p class="text-red-200 text-xs mt-0.5">30% ທີ່ຮັກສາໄວ້</p>
                </div>
            </div>

            <!-- Revenue Chart + Monthly Table -->
            <?php if (!empty($monthly_data)): ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h2 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-chart-bar text-purple-500 mr-2"></i>ລາຍຮັບລາຍເດືອນ
                </h2>
                <canvas id="revenueChart" height="100"></canvas>
            </div>

            <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="font-bold text-gray-800">ລາຍລະອຽດລາຍຮັບລາຍເດືອນ</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50">
                                <th class="text-left py-3 px-4 text-xs font-bold text-gray-500 uppercase">ເດືອນ</th>
                                <th class="text-center py-3 px-4 text-xs font-bold text-gray-500 uppercase">ຈອງທັງໝົດ</th>
                                <th class="text-center py-3 px-4 text-xs font-bold text-gray-500 uppercase">ຢືນຢັນ</th>
                                <th class="text-center py-3 px-4 text-xs font-bold text-gray-500 uppercase">ສຳເລັດ</th>
                                <th class="text-center py-3 px-4 text-xs font-bold text-gray-500 uppercase">ຍົກເລີກ</th>
                                <th class="text-right py-3 px-4 text-xs font-bold text-gray-500 uppercase">ລາຍຮັບ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach (array_reverse($monthly_data) as $m): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="py-3 px-4 font-semibold text-gray-800"><?= htmlspecialchars($m['month_label']) ?></td>
                                    <td class="py-3 px-4 text-center text-gray-600"><?= number_format($m['bookings']) ?></td>
                                    <td class="py-3 px-4 text-center"><span class="bg-green-100 text-green-700 text-xs font-bold px-2 py-0.5 rounded-full"><?= number_format($m['confirmed']) ?></span></td>
                                    <td class="py-3 px-4 text-center"><span class="bg-emerald-100 text-emerald-700 text-xs font-bold px-2 py-0.5 rounded-full"><?= number_format($m['completed']) ?></span></td>
                                    <td class="py-3 px-4 text-center"><span class="bg-red-100 text-red-600 text-xs font-bold px-2 py-0.5 rounded-full"><?= number_format($m['cancelled']) ?></span></td>
                                    <td class="py-3 px-4 text-right font-bold text-green-600">₭<?= number_format($m['revenue']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 bg-gray-50">
                                <td class="py-3 px-4 font-bold text-gray-700">ລວມ</td>
                                <td class="py-3 px-4 text-center font-bold text-gray-700"><?= number_format(array_sum(array_column($monthly_data,'bookings'))) ?></td>
                                <td class="py-3 px-4 text-center font-bold text-green-600"><?= number_format(array_sum(array_column($monthly_data,'confirmed'))) ?></td>
                                <td class="py-3 px-4 text-center font-bold text-emerald-600"><?= number_format(array_sum(array_column($monthly_data,'completed'))) ?></td>
                                <td class="py-3 px-4 text-center font-bold text-red-500"><?= number_format(array_sum(array_column($monthly_data,'cancelled'))) ?></td>
                                <td class="py-3 px-4 text-right font-extrabold text-green-600">₭<?= number_format(array_sum(array_column($monthly_data,'revenue'))) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Per-Booking Revenue List -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-gray-800">
                        <i class="fas fa-list text-green-500 mr-2"></i>ລາຍການລາຍຮັບຕໍ່ການຈອງ
                    </h2>
                    <span class="text-sm text-gray-400"><?= count($revenue_bookings) ?> ລາຍການ</span>
                </div>
                <?php if (!empty($revenue_bookings)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-gray-100 bg-gray-50">
                                <th class="text-left py-3 px-3 text-xs font-bold text-gray-500 uppercase">#</th>
                                <th class="text-left py-3 px-3 text-xs font-bold text-gray-500 uppercase">ລູກຄ້າ</th>
                                <th class="text-left py-3 px-3 text-xs font-bold text-gray-500 uppercase">ເດີ່ນ</th>
                                <th class="text-left py-3 px-3 text-xs font-bold text-gray-500 uppercase">ວັນທີ / ເວລາ</th>
                                <th class="text-center py-3 px-3 text-xs font-bold text-gray-500 uppercase">ຊົ່ວໂມງ</th>
                                <th class="text-center py-3 px-3 text-xs font-bold text-gray-500 uppercase">ສະຖານະ</th>
                                <th class="text-right py-3 px-3 text-xs font-bold text-gray-500 uppercase">ລາຍຮັບ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach ($revenue_bookings as $rb):
                                $rb_color = match($rb['Status_booking']) {
                                    'Completed' => ['bg'=>'bg-emerald-100','text'=>'text-emerald-700','label'=>'ສຳເລັດ'],
                                    'Confirmed' => ['bg'=>'bg-green-100',  'text'=>'text-green-700',  'label'=>'ຢືນຢັນ'],
                                    'No_Show'   => ['bg'=>'bg-orange-100', 'text'=>'text-orange-700', 'label'=>'ບໍ່ໄດ້ມາ'],
                                    'Cancelled' => ['bg'=>'bg-red-100',    'text'=>'text-red-700',    'label'=>'ຍົກເລີກ'],
                                    default     => ['bg'=>'bg-gray-100',   'text'=>'text-gray-600',   'label'=>$rb['Status_booking']],
                                };
                                $rev_note = match($rb['Status_booking']) {
                                    'Completed' => '100% ຊຳລະຄົບ',
                                    'Confirmed' => '30% ມັດຈຳ',
                                    'No_Show'   => '30% ມັດຈຳ',
                                    'Cancelled' => '30% ຮັກສາໄວ້',
                                    default     => '',
                                };
                            ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="py-3 px-3 text-gray-400 text-xs">#<?= $rb['Book_ID'] ?></td>
                                    <td class="py-3 px-3">
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($rb['customer_name']) ?></p>
                                        <p class="text-xs text-gray-400"><?= htmlspecialchars($rb['customer_phone']) ?></p>
                                    </td>
                                    <td class="py-3 px-3 text-gray-600 text-xs"><?= htmlspecialchars($rb['courts']) ?></td>
                                    <td class="py-3 px-3">
                                        <p class="text-gray-700 text-xs"><?= date('d/m/Y', strtotime($rb['first_slot'])) ?></p>
                                        <p class="text-xs text-gray-400"><?= date('H:i',strtotime($rb['first_slot'])) ?> – <?= date('H:i',strtotime($rb['last_slot'])) ?></p>
                                    </td>
                                    <td class="py-3 px-3 text-center text-gray-600"><?= number_format($rb['total_hours']) ?>ຊມ</td>
                                    <td class="py-3 px-3 text-center">
                                        <span class="<?= $rb_color['bg'] ?> <?= $rb_color['text'] ?> text-xs font-bold px-2 py-1 rounded-full"><?= $rb_color['label'] ?></span>
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        <p class="font-extrabold text-green-600 text-xs">₭<?= number_format($rb['booking_revenue']) ?></p>
                                        <p class="text-xs text-gray-400"><?= $rev_note ?></p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 bg-gray-50">
                                <td colspan="6" class="py-3 px-3 font-bold text-gray-700">ລວມທັງໝົດ</td>
                                <td class="py-3 px-3 text-right font-extrabold text-green-600">
                                    ₭<?= number_format(array_sum(array_column($revenue_bookings,'booking_revenue'))) ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-coins text-5xl text-gray-200 mb-3 block"></i>
                        <p class="text-gray-400">ບໍ່ມີລາຍການລາຍຮັບໃນໄລຍະນີ້</p>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<?php if (!empty($monthly_data)): ?>
<script>
const labels  = <?= json_encode(array_column($monthly_data,'month_label')) ?>;
const revenue = <?= json_encode(array_column($monthly_data,'revenue')) ?>;

new Chart(document.getElementById('revenueChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels,
        datasets: [{
            label: 'ລາຍຮັບທັງໝົດ (₭)',
            data: revenue,
            backgroundColor: 'rgba(34,197,94,0.7)',
            borderColor: 'rgba(34,197,94,1)',
            borderWidth: 1,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' },
            tooltip: { callbacks: { label: ctx => ` ₭${ctx.parsed.y.toLocaleString()}` } }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: val => '₭' + val.toLocaleString() },
                title: { display: true, text: 'ລາຍຮັບ (₭)' }
            }
        }
    }
});
</script>
<?php endif; ?>
</body>
</html>