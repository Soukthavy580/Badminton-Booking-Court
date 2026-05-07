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
$price_per_hour = floatval(preg_replace('/[^0-9.]/', '', $venue['Price_per_hour']));

// Booking stats
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT b.Book_ID) AS total,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'Confirmed'  THEN b.Book_ID END) AS confirmed,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'Completed'  THEN b.Book_ID END) AS completed,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'No_Show'    THEN b.Book_ID END) AS no_show,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'Cancelled'  THEN b.Book_ID END) AS cancelled,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'Pending'    THEN b.Book_ID END) AS pending
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        WHERE c.VN_ID = ? AND DATE(b.Booking_date) >= ?
        AND b.Status_booking != 'Unpaid'
    ");
    $stmt->execute([$vn_id, $date_from]);
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    $stats = ['total'=>0,'confirmed'=>0,'completed'=>0,'no_show'=>0,'cancelled'=>0,'pending'=>0];
}

// Court stats
try {
    $stmt = $pdo->prepare("
        SELECT c.COURT_Name,
               COUNT(DISTINCT b.Book_ID) AS booking_count,
               COALESCE(SUM(TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)),0) AS total_hours
        FROM Court_data c
        LEFT JOIN booking_detail bd ON c.COURT_ID = bd.COURT_ID
        LEFT JOIN booking b ON bd.Book_ID = b.Book_ID
            AND b.Status_booking IN ('Confirmed','Completed','No_Show')
            AND DATE(b.Booking_date) >= ?
        WHERE c.VN_ID = ?
        GROUP BY c.COURT_ID ORDER BY booking_count DESC
    ");
    $stmt->execute([$date_from, $vn_id]);
    $court_stats = $stmt->fetchAll();
} catch (PDOException $e) { $court_stats = []; }

$max_bookings = !empty($court_stats) ? max(1, max(array_column($court_stats,'booking_count'))) : 1;

// Booking list
$status_filter = $_GET['status'] ?? 'all';
try {
    $params_b = [$vn_id, $date_from];
    $where_status_b = "AND b.Status_booking NOT IN ('Unpaid','Cancelled')";
    if ($status_filter !== 'all') {
        $where_status_b = "AND b.Status_booking = ?";
        $params_b[] = $status_filter;
    }
    $stmt = $pdo->prepare("
        SELECT
            b.Book_ID, b.Booking_date, b.Status_booking, b.Slip_payment,
            cu.Name AS customer_name, cu.Phone AS customer_phone,
            GROUP_CONCAT(DISTINCT c.COURT_Name ORDER BY c.COURT_Name SEPARATOR ', ') AS courts,
            MIN(bd.Start_time) AS first_slot, MAX(bd.End_time) AS last_slot,
            COUNT(bd.ID) AS slot_count,
            SUM(TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)) AS total_hours
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        INNER JOIN customer cu       ON b.C_ID = cu.C_ID
        WHERE c.VN_ID = ? AND DATE(b.Booking_date) >= ? $where_status_b
        GROUP BY b.Book_ID ORDER BY b.Booking_date ASC LIMIT 100
    ");
    $stmt->execute($params_b);
    $bookings = $stmt->fetchAll();
} catch (PDOException $e) { $bookings = []; }
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລາຍງານການຈອງ - <?= htmlspecialchars($venue['VN_Name']) ?></title>
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
                    <h1 class="text-xl font-bold text-gray-800">ລາຍງານການຈອງ</h1>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars($venue['VN_Name']) ?></p>
                </div>
                <div class="flex gap-2 items-center flex-wrap">
                    <?php foreach (['week'=>'7 ວັນ','month'=>'30 ວັນ','year'=>'1 ປີ'] as $key=>$label): ?>
                        <a href="?period=<?= $key ?>&status=<?= $status_filter ?>"
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
                        <h1 class="text-2xl font-bold">ລາຍງານການຈອງ</h1>
                        <p class="text-gray-600"><?= htmlspecialchars($venue['VN_Name']) ?> · <?= htmlspecialchars($venue['VN_Address']) ?></p>
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
                    ['label'=>'ຈອງທັງໝົດ', 'value'=>$stats['total'] - $stats['cancelled'], 'icon'=>'fa-calendar-check','color'=>'blue',   'sub'=>'ບໍ່ລວມຍົກເລີກ'],
                    ['label'=>'ຢືນຢັນ',     'value'=>$stats['confirmed'],                   'icon'=>'fa-check-circle', 'color'=>'green',  'sub'=>'ການຈອງ'],
                    ['label'=>'ສຳເລັດ',     'value'=>$stats['completed'],                   'icon'=>'fa-trophy',       'color'=>'emerald','sub'=>'ການຈອງ'],
                    ['label'=>'ບໍ່ໄດ້ມາ',   'value'=>$stats['no_show'],                     'icon'=>'fa-user-times',   'color'=>'orange', 'sub'=>'ການຈອງ'],
                ] as $sc): ?>
                    <div class="stat-card bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                        <div class="bg-<?= $sc['color'] ?>-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                            <i class="fas <?= $sc['icon'] ?> text-<?= $sc['color'] ?>-500"></i>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-800"><?= number_format($sc['value'] ?? 0) ?></p>
                        <p class="text-xs font-semibold text-gray-600 mt-0.5"><?= $sc['label'] ?></p>
                        <p class="text-xs text-gray-400"><?= $sc['sub'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Court Statistics -->
            <?php if (!empty($court_stats)): ?>
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">
                <i class="fas fa-table-tennis text-green-500 mr-1"></i>ສະຖິຕິການນຳໃຊ້ເດີ່ນ
            </p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <?php foreach ($court_stats as $i => $court):
                    $pct    = $max_bookings > 0 ? round(($court['booking_count'] / $max_bookings) * 100) : 0;
                    $medal  = match($i) { 0=>'🥇',1=>'🥈',2=>'🥉',default=>'' };
                    $colors = ['from-yellow-400 to-orange-500','from-gray-400 to-gray-500','from-orange-400 to-red-400'];
                    $grad   = $colors[$i] ?? 'from-blue-400 to-blue-500';
                ?>
                    <div class="stat-card bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-11 h-11 bg-gradient-to-br <?= $grad ?> rounded-2xl flex items-center justify-center text-white text-lg font-bold flex-shrink-0">
                                <?= $medal ?: ($i+1) ?>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800"><?= htmlspecialchars($court['COURT_Name']) ?></h3>
                                <p class="text-xs text-gray-400">ອັນດັບທີ <?= $i+1 ?></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <div class="bg-green-50 rounded-xl p-3 text-center">
                                <p class="text-xl font-extrabold text-green-600"><?= number_format($court['booking_count']) ?></p>
                                <p class="text-xs text-green-600 mt-0.5">ການຈອງ</p>
                            </div>
                            <div class="bg-blue-50 rounded-xl p-3 text-center">
                                <p class="text-xl font-extrabold text-blue-600"><?= number_format($court['total_hours']) ?></p>
                                <p class="text-xs text-blue-600 mt-0.5">ຊົ່ວໂມງ</p>
                            </div>
                        </div>
                        <div class="bg-gray-100 rounded-full h-2.5">
                            <div class="bg-gradient-to-r <?= $grad ?> h-2.5 rounded-full" style="width:<?= $pct ?>%"></div>
                        </div>
                        <p class="text-xs text-gray-400 mt-1.5 text-right"><?= $pct ?>% ຂອງການຈອງທັງໝົດ</p>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Status Filter Buttons -->
            <div class="flex gap-2 mb-4 flex-wrap no-print">
                <?php
                $status_options = [
                    'all'       => 'ທັງໝົດ',
                    'Confirmed' => 'ຢືນຢັນ',
                    'Completed' => 'ສຳເລັດ',
                    'Pending'   => 'ລໍຖ້າ',
                    'No_Show'   => 'ບໍ່ໄດ້ມາ',
                ];
                $count_map = [
                    'all'       => $stats['total'] - $stats['cancelled'],
                    'Confirmed' => $stats['confirmed'],
                    'Completed' => $stats['completed'],
                    'Pending'   => $stats['pending'],
                    'No_Show'   => $stats['no_show'],
                ];
                foreach ($status_options as $sv => $sl): ?>
                    <a href="?period=<?= $period ?>&status=<?= $sv ?>"
                       class="px-4 py-2 rounded-xl text-sm font-semibold transition border
                              <?= $status_filter===$sv ? 'bg-green-600 text-white border-green-600 shadow' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50' ?>">
                        <?= $sl ?>
                        <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full <?= $status_filter===$sv ? 'bg-white text-green-600' : 'bg-gray-100 text-gray-500' ?>"><?= $count_map[$sv] ?? 0 ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Booking Table -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-gray-800"><i class="fas fa-list text-gray-400 mr-2"></i>ລາຍການຈອງ</h2>
                    <span class="text-sm text-gray-400"><?= count($bookings) ?> ລາຍການ</span>
                </div>
                <?php if (!empty($bookings)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b-2 border-gray-100 bg-gray-50">
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">#</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ລູກຄ້າ</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ເດີ່ນ</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ວັນທີ / ເວລາ</th>
                                    <th class="text-center py-3 px-2 text-xs font-bold text-gray-500 uppercase">ຊົ່ວໂມງ</th>
                                    <th class="text-right py-3 px-2 text-xs font-bold text-gray-500 uppercase">ລາຍຮັບ</th>
                                    <th class="text-center py-3 px-2 text-xs font-bold text-gray-500 uppercase">ສະຖານະ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php foreach ($bookings as $b):
                                    $b_color = match($b['Status_booking']) {
                                        'Confirmed' => ['bg'=>'bg-green-100',  'text'=>'text-green-700'],
                                        'Completed' => ['bg'=>'bg-emerald-100','text'=>'text-emerald-700'],
                                        'No_Show'   => ['bg'=>'bg-orange-100', 'text'=>'text-orange-700'],
                                        default     => ['bg'=>'bg-yellow-100', 'text'=>'text-yellow-700'],
                                    };
                                    $b_label = match($b['Status_booking']) {
                                        'Confirmed'=>'ຢືນຢັນ','Completed'=>'ສຳເລັດ','No_Show'=>'ບໍ່ໄດ້ມາ',default=>'ລໍຖ້າ'
                                    };
                                    $full_price = $b['total_hours'] * $price_per_hour;
                                    if ($b['Status_booking'] === 'Completed') {
                                        $b_rev = $full_price; $b_rev_sub = '100% ຊຳລະຄົບ'; $b_rev_color = 'text-green-600';
                                    } elseif (in_array($b['Status_booking'], ['Confirmed','No_Show'])) {
                                        $b_rev = $full_price * 0.30; $b_rev_sub = '30% ມັດຈຳ'; $b_rev_color = 'text-blue-600';
                                    } else {
                                        $b_rev = 0; $b_rev_sub = ''; $b_rev_color = 'text-gray-300';
                                    }
                                ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="py-3 px-2 text-gray-400 text-xs">#<?= $b['Book_ID'] ?></td>
                                        <td class="py-3 px-2">
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($b['customer_name']) ?></p>
                                            <p class="text-xs text-gray-400"><?= htmlspecialchars($b['customer_phone']) ?></p>
                                        </td>
                                        <td class="py-3 px-2 text-gray-600 text-xs"><?= htmlspecialchars($b['courts']) ?></td>
                                        <td class="py-3 px-2">
                                            <p class="text-gray-700 text-xs"><?= date('d/m/Y', strtotime($b['first_slot'])) ?></p>
                                            <p class="text-xs text-gray-400"><?= date('H:i',strtotime($b['first_slot'])) ?> – <?= date('H:i',strtotime($b['last_slot'])) ?></p>
                                        </td>
                                        <td class="py-3 px-2 text-center text-gray-600"><?= number_format($b['total_hours']) ?>ຊມ</td>
                                        <td class="py-3 px-2 text-right">
                                            <p class="font-bold text-xs <?= $b_rev_color ?>"><?= $b_rev > 0 ? '₭'.number_format($b_rev) : '—' ?></p>
                                            <?php if ($b_rev_sub): ?><p class="text-xs text-gray-400"><?= $b_rev_sub ?></p><?php endif; ?>
                                        </td>
                                        <td class="py-3 px-2 text-center">
                                            <span class="<?= $b_color['bg'] ?> <?= $b_color['text'] ?> text-xs font-bold px-2 py-1 rounded-full"><?= $b_label ?></span>
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