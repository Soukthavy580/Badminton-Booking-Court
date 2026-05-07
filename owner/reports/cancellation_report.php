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

$period_label   = match($period) { 'week' => '7 ວັນຜ່ານມາ', 'year' => '1 ປີຜ່ານມາ', default => '30 ວັນຜ່ານມາ' };
$price_per_hour = floatval(preg_replace('/[^0-9.]/', '', $venue['Price_per_hour']));

// Cancellation count for summary card
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT b.Book_ID) AS cancelled
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        WHERE c.VN_ID = ? AND DATE(b.Booking_date) >= ?
          AND b.Status_booking = 'Cancelled'
    ");
    $stmt->execute([$vn_id, $date_from]);
    $cancel_count = $stmt->fetchColumn();
} catch (PDOException $e) { $cancel_count = 0; }

// Cancellations detail list
try {
    $stmt = $pdo->prepare("
        SELECT
            b.Book_ID, b.Booking_date, b.Status_booking, b.Slip_payment,
            cu.Name AS customer_name, cu.Phone AS customer_phone,
            GROUP_CONCAT(DISTINCT c.COURT_Name ORDER BY c.COURT_Name SEPARATOR ', ') AS courts,
            MIN(bd.Start_time) AS first_slot, MAX(bd.End_time) AS last_slot,
            SUM(TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)) AS total_hours
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        INNER JOIN customer cu       ON b.C_ID = cu.C_ID
        WHERE c.VN_ID = ? AND DATE(b.Booking_date) >= ?
          AND b.Status_booking = 'Cancelled'
        GROUP BY b.Book_ID ORDER BY b.Booking_date ASC LIMIT 100
    ");
    $stmt->execute([$vn_id, $date_from]);
    $cancellations = $stmt->fetchAll();
} catch (PDOException $e) { $cancellations = []; }

// Cancellation monthly summary
try {
    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(b.Booking_date,'%b %Y') AS month_label,
            COUNT(DISTINCT b.Book_ID) AS cancel_count,
            SUM(CASE WHEN b.Slip_payment IS NOT NULL AND b.Slip_payment != '' THEN 1 ELSE 0 END) AS with_slip,
            SUM(CASE WHEN b.Slip_payment IS NULL OR b.Slip_payment = '' THEN 1 ELSE 0 END) AS without_slip,
            COALESCE(SUM(
                CASE WHEN b.Slip_payment IS NOT NULL AND b.Slip_payment != ''
                    THEN TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)*v.Price_per_hour*0.30
                    ELSE 0
                END
            ),0) AS kept_revenue
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v      ON c.VN_ID = v.VN_ID
        WHERE c.VN_ID = ? AND b.Status_booking = 'Cancelled'
        GROUP BY DATE_FORMAT(b.Booking_date,'%Y-%m')
        ORDER BY DATE_FORMAT(b.Booking_date,'%Y-%m') ASC LIMIT 12
    ");
    $stmt->execute([$vn_id]);
    $cancel_monthly = $stmt->fetchAll();
} catch (PDOException $e) { $cancel_monthly = []; }

$cancel_kept_total = array_reduce($cancellations, function($carry, $c) use ($price_per_hour) {
    return $carry + (!empty($c['Slip_payment']) ? $c['total_hours'] * $price_per_hour * 0.30 : 0);
}, 0);
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລາຍງານການຍົກເລີກ - <?= htmlspecialchars($venue['VN_Name']) ?></title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <h1 class="text-xl font-bold text-gray-800">ລາຍງານການຍົກເລີກ</h1>
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
                        <h1 class="text-2xl font-bold">ລາຍງານການຍົກເລີກ</h1>
                        <p class="text-gray-600"><?= htmlspecialchars($venue['VN_Name']) ?> · <?= htmlspecialchars($venue['VN_Address']) ?></p>
                    </div>
                    <div class="text-right text-sm text-gray-500">
                        <p>ໄລຍະ: <?= $period_label ?></p>
                        <p>ວັນທີພິມ: <?= date('d/m/Y H:i') ?></p>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="stat-card bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                    <div class="bg-red-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-times-circle text-red-500"></i>
                    </div>
                    <p class="text-2xl font-extrabold text-gray-800"><?= number_format($cancel_count) ?></p>
                    <p class="text-xs font-semibold text-gray-600 mt-0.5">ຍົກເລີກທັງໝົດ</p>
                    <p class="text-xs text-gray-400"><?= $period_label ?></p>
                </div>
                <div class="stat-card bg-gradient-to-br from-red-500 to-red-600 rounded-2xl p-5 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-coins text-white"></i>
                    </div>
                    <p class="text-2xl font-extrabold">₭<?= number_format($cancel_kept_total) ?></p>
                    <p class="text-red-100 text-xs font-semibold mt-0.5">ມັດຈຳທີ່ຮັກສາ</p>
                    <p class="text-red-200 text-xs mt-0.5">30% ທີ່ໄດ້ຮັບ</p>
                </div>
            </div>

            <!-- Monthly Cancellation Summary Table -->
            <?php if (!empty($cancel_monthly)): ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h2 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-chart-bar text-red-400 mr-2"></i>ສະຫຼຸບການຍົກເລີກລາຍເດືອນ
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-gray-100 bg-gray-50">
                                <th class="text-left py-3 px-4 text-xs font-bold text-gray-500 uppercase">ເດືອນ</th>
                                <th class="text-center py-3 px-4 text-xs font-bold text-gray-500 uppercase">ຍົກເລີກທັງໝົດ</th>
                                <th class="text-center py-3 px-4 text-xs font-bold text-gray-500 uppercase">ມີສ່ຽງ</th>
                                <th class="text-center py-3 px-4 text-xs font-bold text-gray-500 uppercase">ບໍ່ມີສ່ຽງ</th>
                                <th class="text-right py-3 px-4 text-xs font-bold text-gray-500 uppercase">ມັດຈຳທີ່ຮັກສາ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach ($cancel_monthly as $cm): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="py-3 px-4 font-semibold text-gray-800"><?= htmlspecialchars($cm['month_label']) ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="bg-red-100 text-red-600 text-xs font-bold px-2 py-0.5 rounded-full"><?= number_format($cm['cancel_count']) ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="bg-orange-100 text-orange-600 text-xs font-bold px-2 py-0.5 rounded-full"><?= number_format($cm['with_slip']) ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="bg-gray-100 text-gray-500 text-xs font-bold px-2 py-0.5 rounded-full"><?= number_format($cm['without_slip']) ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-right font-bold text-red-600">
                                        <?= $cm['kept_revenue'] > 0 ? '₭'.number_format($cm['kept_revenue']) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 bg-gray-50">
                                <td class="py-3 px-4 font-bold text-gray-700">ລວມ</td>
                                <td class="py-3 px-4 text-center font-bold text-red-600"><?= number_format(array_sum(array_column($cancel_monthly,'cancel_count'))) ?></td>
                                <td class="py-3 px-4 text-center font-bold text-orange-500"><?= number_format(array_sum(array_column($cancel_monthly,'with_slip'))) ?></td>
                                <td class="py-3 px-4 text-center font-bold text-gray-500"><?= number_format(array_sum(array_column($cancel_monthly,'without_slip'))) ?></td>
                                <td class="py-3 px-4 text-right font-extrabold text-red-600">₭<?= number_format(array_sum(array_column($cancel_monthly,'kept_revenue'))) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Cancellation Detail List -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-gray-800"><i class="fas fa-list text-red-400 mr-2"></i>ລາຍການຍົກເລີກ</h2>
                    <span class="text-sm text-gray-400"><?= count($cancellations) ?> ລາຍການ</span>
                </div>
                <?php if (!empty($cancellations)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b-2 border-gray-100 bg-gray-50">
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">#</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ລູກຄ້າ</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ເດີ່ນ</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ວັນທີ / ເວລາ</th>
                                    <th class="text-center py-3 px-2 text-xs font-bold text-gray-500 uppercase">ຊົ່ວໂມງ</th>
                                    <th class="text-center py-3 px-2 text-xs font-bold text-gray-500 uppercase">ມີສ່ຽງ</th>
                                    <th class="text-right py-3 px-2 text-xs font-bold text-gray-500 uppercase">ມັດຈຳຮັກສາ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php foreach ($cancellations as $c):
                                    $full_price = $c['total_hours'] * $price_per_hour;
                                    $kept       = !empty($c['Slip_payment']) ? $full_price * 0.30 : 0;
                                    $has_slip   = !empty($c['Slip_payment']);
                                ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="py-3 px-2 text-gray-400 text-xs">#<?= $c['Book_ID'] ?></td>
                                        <td class="py-3 px-2">
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($c['customer_name']) ?></p>
                                            <p class="text-xs text-gray-400"><?= htmlspecialchars($c['customer_phone']) ?></p>
                                        </td>
                                        <td class="py-3 px-2 text-gray-600 text-xs"><?= htmlspecialchars($c['courts']) ?></td>
                                        <td class="py-3 px-2">
                                            <p class="text-gray-700 text-xs"><?= date('d/m/Y', strtotime($c['first_slot'])) ?></p>
                                            <p class="text-xs text-gray-400"><?= date('H:i',strtotime($c['first_slot'])) ?> – <?= date('H:i',strtotime($c['last_slot'])) ?></p>
                                        </td>
                                        <td class="py-3 px-2 text-center text-gray-600"><?= number_format($c['total_hours']) ?>ຊມ</td>
                                        <td class="py-3 px-2 text-center">
                                            <?php if ($has_slip): ?>
                                                <span class="bg-orange-100 text-orange-600 text-xs font-bold px-2 py-1 rounded-full">ມີສ່ຽງ</span>
                                            <?php else: ?>
                                                <span class="bg-gray-100 text-gray-400 text-xs font-bold px-2 py-1 rounded-full">ບໍ່ມີ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-2 text-right">
                                            <?php if ($kept > 0): ?>
                                                <p class="font-bold text-xs text-red-500">₭<?= number_format($kept) ?></p>
                                                <p class="text-xs text-gray-400">30% ຮັກສາ</p>
                                            <?php else: ?>
                                                <p class="text-xs text-gray-300">—</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-gray-200 bg-gray-50">
                                    <td colspan="6" class="py-3 px-2 font-bold text-gray-700">ລວມມັດຈຳທີ່ຮັກສາ</td>
                                    <td class="py-3 px-2 text-right font-extrabold text-red-600">₭<?= number_format($cancel_kept_total) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-calendar-times text-5xl text-gray-200 mb-3 block"></i>
                        <p class="text-gray-400">ບໍ່ມີການຍົກເລີກໃນໄລຍະນີ້</p>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>
</body>
</html>