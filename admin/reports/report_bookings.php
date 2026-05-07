<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

date_default_timezone_set('Asia/Vientiane');

$period = $_GET['period'] ?? 'month';

$date_from = match($period) {
    'week'  => date('Y-m-d', strtotime('-7 days')),
    'month' => date('Y-m-d', strtotime('-30 days')),
    'year'  => date('Y-m-d', strtotime('-1 year')),
    default => date('Y-m-d', strtotime('-30 days')),
};

// Booking stats
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT b.Book_ID) AS total_bookings,
            COUNT(DISTINCT CASE WHEN b.Status_booking IN ('Confirmed','Completed','No_Show') THEN b.Book_ID END) AS confirmed,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'Completed' THEN b.Book_ID END) AS completed,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'No_Show'   THEN b.Book_ID END) AS no_show,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'Cancelled' THEN b.Book_ID END) AS cancelled,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'Pending'   THEN b.Book_ID END) AS pending
        FROM booking b WHERE DATE(b.Booking_date) >= ? AND b.Status_booking != 'Unpaid'
    ");
    $stmt->execute([$date_from]);
    $booking_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $booking_stats = ['total_bookings'=>0,'confirmed'=>0,'completed'=>0,'no_show'=>0,'cancelled'=>0,'pending'=>0];
}

// Venue stats for booking table
try {
    $stmt = $pdo->prepare("
        SELECT v.VN_ID, v.VN_Name, v.Price_per_hour,
               co.Name AS owner_name,
               COUNT(DISTINCT c.COURT_ID) AS total_courts,
               COUNT(DISTINCT b.Book_ID) AS total_bookings,
               COUNT(DISTINCT CASE WHEN b.Status_booking IN ('Confirmed','Completed','No_Show') THEN b.Book_ID END) AS confirmed,
               COUNT(DISTINCT CASE WHEN b.Status_booking = 'Cancelled' THEN b.Book_ID END) AS cancelled,
               COALESCE(SUM(CASE WHEN b.Status_booking IN ('Confirmed','Completed','No_Show')
                   THEN TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)*CAST(REPLACE(REPLACE(v.Price_per_hour,',',''),' ','') AS UNSIGNED)
                   ELSE 0 END),0) AS booking_revenue
        FROM Venue_data v
        INNER JOIN court_owner co ON v.CA_ID=co.CA_ID
        LEFT JOIN Court_data c    ON c.VN_ID=v.VN_ID
        LEFT JOIN booking_detail bd ON bd.COURT_ID=c.COURT_ID
        LEFT JOIN booking b ON bd.Book_ID=b.Book_ID AND DATE(b.Booking_date)>=?
        WHERE v.VN_Status='Active'
        GROUP BY v.VN_ID ORDER BY confirmed ASC LIMIT 20
    ");
    $stmt->execute([$date_from]);
    $venue_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $venue_stats = []; }

$max_venue_bookings = !empty($venue_stats) ? max(1, max(array_column($venue_stats,'confirmed'))) : 1;

// All bookings list
try {
    $stmt = $pdo->prepare("
        SELECT b.Book_ID, b.Booking_date, b.Status_booking,
               cu.Name AS customer_name, cu.Phone AS customer_phone,
               v.VN_Name,
               GROUP_CONCAT(DISTINCT c.COURT_Name ORDER BY c.COURT_Name SEPARATOR ', ') AS courts,
               MIN(bd.Start_time) AS first_slot, MAX(bd.End_time) AS last_slot,
               SUM(TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)) AS total_hours
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID=bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID=c.COURT_ID
        INNER JOIN Venue_data v      ON c.VN_ID=v.VN_ID
        INNER JOIN customer cu       ON b.C_ID=cu.C_ID
        WHERE DATE(b.Booking_date)>=? AND b.Status_booking NOT IN ('Unpaid','Cancelled')
        GROUP BY b.Book_ID ORDER BY b.Booking_date ASC LIMIT 100
    ");
    $stmt->execute([$date_from]);
    $all_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $all_bookings = []; }

$period_label = match($period) {
    'week'  => '7 ວັນຜ່ານມາ',
    'year'  => '1 ປີຜ່ານມາ',
    default => '30 ວັນຜ່ານມາ',
};

$sections = [
    'venues'      => ['icon'=>'fa-store',         'label'=>'ລາຍງານຂໍ້ມູນເດີ່ນທັງໝົດ',    'file'=>'index.php'],
    'bookings'    => ['icon'=>'fa-calendar-check', 'label'=>'ລາຍງານການຈອງເດີ່ນທັງໝົດ',    'file'=>'report_bookings.php'],
    'ad_revenue'  => ['icon'=>'fa-coins',           'label'=>'ລາຍງານລາຍຮັບໂຄສະນາ',         'file'=>'report_ad_revenue.php'],
    'pkg_revenue' => ['icon'=>'fa-box',             'label'=>'ລາຍງານລາຍຮັບແພັກເກດ',         'file'=>'report_pkg_revenue.php'],
];
$section = 'bookings';
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລາຍງານການຈອງ - Admin</title>
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
                    <h1 class="text-xl font-bold text-gray-800">ລາຍງານ ແລະ ສະຖິຕິ</h1>
                    <p class="text-sm text-gray-500">ພາບລວມທົ່ວທັງແພລດຟອມ</p>
                </div>
                <div class="flex gap-2 items-center flex-wrap">
                    <?php foreach (['week'=>'7 ວັນ','month'=>'30 ວັນ','year'=>'1 ປີ'] as $key=>$label): ?>
                        <a href="?period=<?= $key ?>"
                           class="px-4 py-2 rounded-xl font-semibold text-sm transition
                                  <?= $period===$key ? 'bg-blue-600 text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                            <?= $label ?>
                        </a>
                    <?php endforeach; ?>
                    <button onclick="window.print()"
                            class="flex items-center gap-2 bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-xl font-semibold text-sm transition shadow">
                        <i class="fas fa-print"></i>ພິມ
                    </button>
                </div>
            </div>
            <div class="flex gap-1 mt-4 border-b border-gray-100 overflow-x-auto pb-0">
                <?php foreach ($sections as $key => $sec): ?>
                    <a href="<?= $sec['file'] ?>?period=<?= $period ?>"
                       class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-t-xl transition border-b-2 whitespace-nowrap
                              <?= $section===$key ? 'border-blue-600 text-blue-700 bg-blue-50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>">
                        <i class="fas <?= $sec['icon'] ?> text-xs"></i><?= $sec['label'] ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </header>

        <main class="flex-1 p-6">

            <div class="print-header mb-6 pb-4 border-b-2 border-gray-800">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">ລາຍງານການຈອງເດີ່ນທັງໝົດ</h1>
                        <p class="text-gray-600">Badminton Booking Court · Admin</p>
                    </div>
                    <div class="text-right text-sm text-gray-500">
                        <p>ໄລຍະ: <?= $period_label ?></p>
                        <p>ວັນທີພິມ: <?= date('d/m/Y H:i') ?></p>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <?php foreach ([
                    ['label'=>'ຈອງທັງໝົດ','value'=>($booking_stats['total_bookings']-$booking_stats['cancelled']),'icon'=>'fa-calendar-check','color'=>'blue',   'sub'=>'ບໍ່ລວມຍົກເລີກ'],
                    ['label'=>'ຢືນຢັນ',    'value'=>$booking_stats['confirmed'],                                    'icon'=>'fa-check-circle', 'color'=>'green',  'sub'=>'ການຈອງ'],
                    ['label'=>'ສຳເລັດ',    'value'=>$booking_stats['completed'],                                    'icon'=>'fa-trophy',       'color'=>'emerald','sub'=>'ການຈອງ'],
                    ['label'=>'ບໍ່ໄດ້ມາ',  'value'=>$booking_stats['no_show'],                                      'icon'=>'fa-user-times',   'color'=>'orange', 'sub'=>'ການຈອງ'],
                ] as $sc): ?>
                    <div class="stat-card bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                        <div class="bg-<?= $sc['color'] ?>-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                            <i class="fas <?= $sc['icon'] ?> text-<?= $sc['color'] ?>-500"></i>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-800"><?= number_format($sc['value']??0) ?></p>
                        <p class="text-xs font-semibold text-gray-600 mt-0.5"><?= $sc['label'] ?></p>
                        <p class="text-xs text-gray-400"><?= $sc['sub'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Venue Booking Breakdown -->
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h2 class="font-bold text-gray-800 mb-4"><i class="fas fa-store text-blue-400 mr-2"></i>ສະຫຼຸບການຈອງຕໍ່ສະຖານທີ່</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-gray-100 bg-gray-50">
                                <th class="text-left py-3 px-3 text-xs font-bold text-gray-500 uppercase">ສະຖານທີ່</th>
                                <th class="text-left py-3 px-3 text-xs font-bold text-gray-500 uppercase">ເຈົ້າຂອງ</th>
                                <th class="text-center py-3 px-3 text-xs font-bold text-gray-500 uppercase">ເດີ່ນ</th>
                                <th class="text-center py-3 px-3 text-xs font-bold text-gray-500 uppercase">ທັງໝົດ</th>
                                <th class="text-center py-3 px-3 text-xs font-bold text-gray-500 uppercase">ຢືນຢັນ</th>
                                <th class="text-center py-3 px-3 text-xs font-bold text-gray-500 uppercase">ຍົກເລີກ</th>
                                <th class="text-right py-3 px-3 text-xs font-bold text-gray-500 uppercase">ລາຍຮັບ</th>
                                <th class="py-3 px-3 w-24"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach ($venue_stats as $v):
                                $pct = $max_venue_bookings > 0 ? round(($v['confirmed']/$max_venue_bookings)*100) : 0;
                            ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="py-3 px-3 font-semibold text-gray-800"><?= htmlspecialchars($v['VN_Name']) ?></td>
                                    <td class="py-3 px-3 text-gray-500 text-xs"><?= htmlspecialchars($v['owner_name']) ?></td>
                                    <td class="py-3 px-3 text-center text-gray-600"><?= $v['total_courts'] ?></td>
                                    <td class="py-3 px-3 text-center text-gray-600"><?= number_format($v['total_bookings']) ?></td>
                                    <td class="py-3 px-3 text-center"><span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-bold"><?= number_format($v['confirmed']) ?></span></td>
                                    <td class="py-3 px-3 text-center"><span class="bg-red-100 text-red-600 px-2 py-0.5 rounded-full text-xs font-bold"><?= number_format($v['cancelled']) ?></span></td>
                                    <td class="py-3 px-3 text-right font-bold text-green-600"><?= $v['booking_revenue']>0 ? '₭'.number_format($v['booking_revenue']) : '—' ?></td>
                                    <td class="py-3 px-3"><div class="bg-gray-100 rounded-full h-2"><div class="bg-blue-500 h-2 rounded-full" style="width:<?= $pct ?>%"></div></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- All Bookings List -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-gray-800"><i class="fas fa-list text-gray-400 mr-2"></i>ລາຍການຈອງທັງໝົດ</h2>
                    <span class="text-sm text-gray-400"><?= count($all_bookings) ?> ລາຍການ</span>
                </div>
                <?php if (!empty($all_bookings)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-gray-100 bg-gray-50">
                                <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ລຳດັບ</th>
                                <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ລູກຄ້າ</th>
                                <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ສະຖານທີ່ / ເດີ່ນ</th>
                                <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ວັນທີ / ເວລາ</th>
                                <th class="text-center py-3 px-2 text-xs font-bold text-gray-500 uppercase">ຊົ່ວໂມງ</th>
                                <th class="text-center py-3 px-2 text-xs font-bold text-gray-500 uppercase">ສະຖານະ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach ($all_bookings as $b):
                                $bc = match($b['Status_booking']) {
                                    'Confirmed'=>['bg-green-100','text-green-700','ຢືນຢັນ'],
                                    'Completed'=>['bg-emerald-100','text-emerald-700','ສຳເລັດ'],
                                    'No_Show'  =>['bg-orange-100','text-orange-700','ບໍ່ໄດ້ມາ'],
                                    default    =>['bg-yellow-100','text-yellow-700','ລໍຖ້າ']
                                };
                            ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="py-3 px-2 text-gray-400 text-xs"><?= $b['Book_ID'] ?></td>
                                    <td class="py-3 px-2">
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($b['customer_name']) ?></p>
                                        <p class="text-xs text-gray-400"><?= htmlspecialchars($b['customer_phone']) ?></p>
                                    </td>
                                    <td class="py-3 px-2">
                                        <p class="font-semibold text-gray-700 text-xs"><?= htmlspecialchars($b['VN_Name']) ?></p>
                                        <p class="text-xs text-gray-400"><?= htmlspecialchars($b['courts']) ?></p>
                                    </td>
                                    <td class="py-3 px-2">
                                        <p class="text-gray-700 text-xs"><?= date('d/m/Y',strtotime($b['first_slot'])) ?></p>
                                        <p class="text-xs text-gray-400"><?= date('H:i',strtotime($b['first_slot'])) ?> – <?= date('H:i',strtotime($b['last_slot'])) ?></p>
                                    </td>
                                    <td class="py-3 px-2 text-center text-gray-600"><?= number_format($b['total_hours']) ?>ຊມ</td>
                                    <td class="py-3 px-2 text-center">
                                        <span class="<?= $bc[0] ?> <?= $bc[1] ?> text-xs font-bold px-2 py-1 rounded-full"><?= $bc[2] ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-calendar text-5xl text-gray-200 mb-3 block"></i>
                        <p class="text-gray-400">ບໍ່ມີການຈອງໃນໄລຍະນີ້</p>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>
</body>
</html>