<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

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
$date_from = match($period) {
    'week'  => date('Y-m-d', strtotime('-7 days')),
    'month' => date('Y-m-d', strtotime('-30 days')),
    'year'  => date('Y-m-d', strtotime('-1 year')),
    default => date('Y-m-d', strtotime('-30 days')),
};

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT b.Book_ID) AS total,
            SUM(CASE WHEN b.Status_booking = 'Confirmed'  THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN b.Status_booking = 'Completed'  THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN b.Status_booking = 'No_Show'    THEN 1 ELSE 0 END) AS no_show,
            SUM(CASE WHEN b.Status_booking = 'Cancelled'  THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN b.Status_booking IN ('Pending','Unpaid') THEN 1 ELSE 0 END) AS pending
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        WHERE c.VN_ID = ? AND DATE(b.Booking_date) >= ?
    ");
    $stmt->execute([$vn_id, $date_from]);
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    $stats = ['total'=>0,'confirmed'=>0,'cancelled'=>0,'pending'=>0];
}

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour
        ), 0) AS total_revenue,
        COALESCE(SUM(
            TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour * 0.3
        ), 0) AS deposit_received
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v      ON c.VN_ID = v.VN_ID
        WHERE c.VN_ID = ? AND b.Status_booking IN ('Confirmed','Completed') AND DATE(b.Booking_date) >= ?
    ");
    $stmt->execute([$vn_id, $date_from]);
    $revenue = $stmt->fetch();
} catch (PDOException $e) {
    $revenue = ['total_revenue'=>0,'deposit_received'=>0];
}

try {
    $stmt = $pdo->prepare("
        SELECT c.COURT_Name,
               COUNT(DISTINCT b.Book_ID) AS booking_count,
               SUM(TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time)) AS total_hours
        FROM Court_data c
        LEFT JOIN booking_detail bd ON c.COURT_ID = bd.COURT_ID
        LEFT JOIN booking b ON bd.Book_ID = b.Book_ID
            AND b.Status_booking = 'Confirmed' AND DATE(b.Booking_date) >= ?
        WHERE c.VN_ID = ?
        GROUP BY c.COURT_ID ORDER BY booking_count DESC
    ");
    $stmt->execute([$date_from, $vn_id]);
    $court_stats = $stmt->fetchAll();
} catch (PDOException $e) { $court_stats = []; }

$max_bookings = !empty($court_stats) ? max(array_column($court_stats, 'booking_count')) : 1;

try {
    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(b.Booking_date, '%Y-%m') AS month,
            DATE_FORMAT(b.Booking_date, '%b %Y')  AS month_label,
            COUNT(DISTINCT b.Book_ID) AS bookings,
            SUM(CASE WHEN b.Status_booking = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN b.Status_booking = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled,
            COALESCE(SUM(CASE WHEN b.Status_booking = 'Confirmed'
                THEN TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) * v.Price_per_hour
                ELSE 0 END), 0) AS revenue
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v      ON c.VN_ID = v.VN_ID
        WHERE c.VN_ID = ?
        GROUP BY DATE_FORMAT(b.Booking_date, '%Y-%m')
        ORDER BY month ASC LIMIT 12
    ");
    $stmt->execute([$vn_id]);
    $monthly_data = $stmt->fetchAll();
} catch (PDOException $e) { $monthly_data = []; }

try {
    $stmt = $pdo->prepare("
        SELECT
            b.Book_ID, b.Booking_date, b.Status_booking,
            cu.Name AS customer_name, cu.Phone AS customer_phone,
            GROUP_CONCAT(DISTINCT c.COURT_Name ORDER BY c.COURT_Name SEPARATOR ', ') AS courts,
            MIN(bd.Start_time) AS first_slot, MAX(bd.End_time) AS last_slot,
            COUNT(bd.ID) AS slot_count,
            SUM(TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time)) AS total_hours
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
        INNER JOIN customer cu       ON b.C_ID = cu.C_ID
        WHERE c.VN_ID = ? AND DATE(b.Booking_date) >= ?
        GROUP BY b.Book_ID ORDER BY b.Booking_date DESC LIMIT 50
    ");
    $stmt->execute([$vn_id, $date_from]);
    $bookings = $stmt->fetchAll();
} catch (PDOException $e) { $bookings = []; }

$price_per_hour = floatval(preg_replace('/[^0-9.]/', '', $venue['Price_per_hour']));
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລາຍງານ - Badminton Booking Court</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">ລາຍງານ</h1>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars($venue['VN_Name']) ?> — ພາບລວມ</p>
                </div>
                <div class="flex gap-2">
                    <?php foreach (['week'=>'7 ວັນ','month'=>'30 ວັນ','year'=>'1 ປີ'] as $key => $label): ?>
                        <a href="?period=<?= $key ?>"
                           class="px-4 py-2 rounded-xl font-semibold text-sm transition
                                  <?= $period === $key ? 'bg-green-600 text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                            <?= $label ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6">

            <!-- Stat Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <?php foreach ([
                    ['label'=>'ການຈອງທັງໝົດ','value'=>$stats['total'],    'icon'=>'fa-calendar',     'color'=>'blue',  'sub'=>'ໃນໄລຍະທີ່ເລືອກ'],
                    ['label'=>'ຢືນຢັນແລ້ວ',  'value'=>$stats['confirmed'],'icon'=>'fa-check-circle', 'color'=>'green', 'sub'=>'ການຈອງ'],
                    ['label'=>'ຍົກເລີກແລ້ວ', 'value'=>$stats['cancelled'],'icon'=>'fa-times-circle', 'color'=>'red',   'sub'=>'ການຈອງ'],
                    ['label'=>'ລໍຖ້າ',        'value'=>$stats['pending'],  'icon'=>'fa-clock',        'color'=>'yellow','sub'=>'ການຈອງ'],
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

            <!-- Revenue Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 rounded-2xl shadow-sm p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-money-bill-wave text-white"></i>
                    </div>
                    <p class="text-3xl font-extrabold">₭<?= number_format($revenue['total_revenue']) ?></p>
                    <p class="text-green-100 text-sm mt-1">ລາຍຮັບລວມ (ລາຄາເຕັມ)</p>
                    <p class="text-green-200 text-xs mt-0.5">ຈາກການຈອງທີ່ຢືນຢັນ</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl shadow-sm p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-hand-holding-usd text-white"></i>
                    </div>
                    <p class="text-3xl font-extrabold">₭<?= number_format($revenue['deposit_received']) ?></p>
                    <p class="text-blue-100 text-sm mt-1">ມັດຈຳທີ່ໄດ້ຮັບ (30%)</p>
                    <p class="text-blue-200 text-xs mt-0.5">ລູກຄ້າຈ່າຍອອນລາຍ</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl shadow-sm p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-store text-white"></i>
                    </div>
                    <p class="text-3xl font-extrabold">₭<?= number_format($revenue['total_revenue'] - $revenue['deposit_received']) ?></p>
                    <p class="text-purple-100 text-sm mt-1">ຈ່າຍທີ່ສະຖານທີ່ (70%)</p>
                    <p class="text-purple-200 text-xs mt-0.5">ເກັບເງິນສົດ</p>
                </div>
            </div>

            <!-- Chart + Court Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="md:col-span-2 bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="font-bold text-gray-800 mb-4">
                        <i class="fas fa-chart-line text-blue-500 mr-2"></i>ການຈອງ ແລະ ລາຍຮັບລາຍເດືອນ
                    </h2>
                    <?php if (!empty($monthly_data)): ?>
                        <canvas id="monthlyChart" height="120"></canvas>
                    <?php else: ?>
                        <div class="flex items-center justify-center h-40">
                            <div class="text-center">
                                <i class="fas fa-chart-line text-4xl text-gray-200 mb-2 block"></i>
                                <p class="text-gray-400 text-sm">ຍັງບໍ່ມີຂໍ້ມູນພຽງພໍ</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="font-bold text-gray-800 mb-4">
                        <i class="fas fa-trophy text-yellow-500 mr-2"></i>ສະຖິຕິເດີ່ນ
                    </h2>
                    <?php if (!empty($court_stats)): ?>
                        <div class="space-y-4">
                            <?php foreach ($court_stats as $i => $court):
                                $pct = $max_bookings > 0 ? round(($court['booking_count'] / $max_bookings) * 100) : 0;
                                $bar_color = match($i) { 0=>'bg-yellow-400',1=>'bg-gray-400',2=>'bg-orange-400',default=>'bg-blue-400' };
                                $medal     = match($i) { 0=>'🥇',1=>'🥈',2=>'🥉',default=>'' };
                            ?>
                                <div>
                                    <div class="flex items-center justify-between text-sm mb-1">
                                        <span class="font-semibold text-gray-700"><?= $medal ?> <?= htmlspecialchars($court['COURT_Name']) ?></span>
                                        <span class="text-gray-500 text-xs"><?= number_format($court['booking_count']) ?> ຈອງ · <?= number_format($court['total_hours'] ?? 0) ?>ຊມ</span>
                                    </div>
                                    <div class="bg-gray-100 rounded-full h-2">
                                        <div class="<?= $bar_color ?> h-2 rounded-full transition-all" style="width:<?= $pct ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-table-tennis text-4xl text-gray-200 mb-2 block"></i>
                            <p class="text-gray-400 text-sm">ຍັງບໍ່ມີຂໍ້ມູນ</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Booking History Table -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-gray-800">
                        <i class="fas fa-list text-gray-500 mr-2"></i>ປະຫວັດການຈອງ
                        <span class="text-sm font-normal text-gray-400 ml-2">(50 ລ່າສຸດ)</span>
                    </h2>
                    <span class="text-sm text-gray-400"><?= count($bookings) ?> ລາຍການ</span>
                </div>

                <?php if (!empty($bookings)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b-2 border-gray-100">
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">#</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ລູກຄ້າ</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ເດີ່ນ</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ວັນທີ</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ຊົ່ວໂມງ</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ລາຍຮັບ</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ສະຖານະ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php foreach ($bookings as $booking):
                                    $b_color = match($booking['Status_booking']) {
                                        'Confirmed' => ['bg'=>'bg-green-100','text'=>'text-green-700'],
                                        'Cancelled' => ['bg'=>'bg-red-100',  'text'=>'text-red-700'],
                                        default     => ['bg'=>'bg-yellow-100','text'=>'text-yellow-700'],
                                    };
                                    $b_label = match($booking['Status_booking']) {
                                        'Confirmed'=>'ຢືນຢັນ','Cancelled'=>'ຍົກເລີກ',default=>'ລໍຖ້າ'
                                    };
                                    $booking_revenue = $booking['Status_booking'] === 'Confirmed'
                                        ? $booking['total_hours'] * $price_per_hour : 0;
                                ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="py-3 px-2 text-gray-400 text-xs">#<?= $booking['Book_ID'] ?></td>
                                        <td class="py-3 px-2">
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($booking['customer_name']) ?></p>
                                            <p class="text-xs text-gray-400"><?= htmlspecialchars($booking['customer_phone']) ?></p>
                                        </td>
                                        <td class="py-3 px-2 text-gray-600"><?= htmlspecialchars($booking['courts']) ?></td>
                                        <td class="py-3 px-2">
                                            <p class="text-gray-700"><?= date('d/m/Y', strtotime($booking['first_slot'])) ?></p>
                                            <p class="text-xs text-gray-400">
                                                <?= date('H:i', strtotime($booking['first_slot'])) ?> - <?= date('H:i', strtotime($booking['last_slot'])) ?>
                                            </p>
                                        </td>
                                        <td class="py-3 px-2 text-gray-600"><?= number_format($booking['total_hours']) ?>ຊມ</td>
                                        <td class="py-3 px-2">
                                            <?php if ($booking_revenue > 0): ?>
                                                <p class="font-bold text-green-600">₭<?= number_format($booking_revenue) ?></p>
                                                <p class="text-xs text-gray-400">₭<?= number_format($booking_revenue * 0.3) ?> ມັດຈຳ</p>
                                            <?php else: ?>
                                                <span class="text-gray-300">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-2">
                                            <span class="<?= $b_color['bg'] ?> <?= $b_color['text'] ?> text-xs font-bold px-2 py-1 rounded-full">
                                                <?= $b_label ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-10">
                        <i class="fas fa-calendar text-5xl text-gray-200 mb-3 block"></i>
                        <p class="text-gray-400">ບໍ່ມີການຈອງໃນໄລຍະນີ້</p>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<?php if (!empty($monthly_data)): ?>
<script>
    const labels    = <?= json_encode(array_column($monthly_data, 'month_label')) ?>;
    const confirmed = <?= json_encode(array_column($monthly_data, 'confirmed')) ?>;
    const cancelled = <?= json_encode(array_column($monthly_data, 'cancelled')) ?>;
    const revenue   = <?= json_encode(array_column($monthly_data, 'revenue')) ?>;

    new Chart(document.getElementById('monthlyChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label:'ຢືນຢັນ', data:confirmed, backgroundColor:'rgba(34,197,94,0.7)', borderColor:'rgba(34,197,94,1)', borderWidth:1, borderRadius:6, yAxisID:'y' },
                { label:'ຍົກເລີກ', data:cancelled, backgroundColor:'rgba(239,68,68,0.5)', borderColor:'rgba(239,68,68,1)', borderWidth:1, borderRadius:6, yAxisID:'y' },
                { label:'ລາຍຮັບ (₭)', data:revenue, type:'line', borderColor:'rgba(59,130,246,1)', backgroundColor:'rgba(59,130,246,0.1)', borderWidth:2, pointBackgroundColor:'rgba(59,130,246,1)', tension:0.4, fill:true, yAxisID:'y1' }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode:'index', intersect:false },
            plugins: {
                legend: { position:'top' },
                tooltip: { callbacks: { label: ctx => ctx.dataset.label === 'ລາຍຮັບ (₭)' ? ` ${ctx.dataset.label}: ₭${ctx.parsed.y.toLocaleString()}` : ` ${ctx.dataset.label}: ${ctx.parsed.y}` } }
            },
            scales: {
                y:  { type:'linear', position:'left',  beginAtZero:true, ticks:{stepSize:1}, title:{display:true,text:'ການຈອງ'} },
                y1: { type:'linear', position:'right', beginAtZero:true, grid:{drawOnChartArea:false}, ticks:{callback:val=>'₭'+val.toLocaleString()}, title:{display:true,text:'ລາຍຮັບ (₭)'} }
            }
        }
    });
</script>
<?php endif; ?>
</body>
</html>