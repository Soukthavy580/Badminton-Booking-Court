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

// Revenue
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
            COUNT(DISTINCT CASE WHEN b.Status_booking='No_Show'   THEN b.Book_ID END) AS no_show,
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
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ພາບລວມລາຍງານ - <?= htmlspecialchars($venue['VN_Name']) ?></title>
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

        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40 no-print">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">ລາຍງານ</h1>
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
                        <h1 class="text-2xl font-bold">ພາບລວມທັງໝົດ</h1>
                        <p class="text-gray-600"><?= htmlspecialchars($venue['VN_Name']) ?> · <?= htmlspecialchars($venue['VN_Address']) ?></p>
                    </div>
                    <div class="text-right text-sm text-gray-500">
                        <p>ໄລຍະ: <?= $period_label ?></p>
                        <p>ວັນທີພິມ: <?= date('d/m/Y H:i') ?></p>
                    </div>
                </div>
            </div>

            <!-- Booking Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <?php foreach ([
                    ['label'=>'ການຈອງທັງໝົດ','value'=>$stats['total'],    'icon'=>'fa-calendar',    'color'=>'blue',   'sub'=>$period_label],
                    ['label'=>'ຢືນຢັນ',        'value'=>$stats['confirmed'],'icon'=>'fa-check-circle','color'=>'green',  'sub'=>'ການຈອງ'],
                    ['label'=>'ຍົກເລີກ',        'value'=>$stats['cancelled'],'icon'=>'fa-times-circle','color'=>'red',    'sub'=>'ການຈອງ'],
                    ['label'=>'ລໍຖ້າ',           'value'=>$stats['pending'],  'icon'=>'fa-clock',       'color'=>'yellow', 'sub'=>'ລໍຖ້າກວດສອບ'],
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
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">
                <i class="fas fa-coins text-yellow-400 mr-1"></i>ລາຍຮັບທີ່ໄດ້ຮັບຈິງ
            </p>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-money-bill-wave text-white"></i>
                    </div>
                    <p class="text-2xl font-extrabold">₭<?= number_format($revenue['total_revenue']) ?></p>
                    <p class="text-green-100 text-sm mt-1">ລາຍຮັບທັງໝົດ</p>
                    <p class="text-green-200 text-xs mt-0.5">ລວມທຸກສະຖານະ</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-mobile-alt text-white"></i>
                    </div>
                    <p class="text-2xl font-extrabold">₭<?= number_format($revenue['deposit_received']) ?></p>
                    <p class="text-blue-100 text-sm mt-1">ຮັບອອນລາຍ (30%)</p>
                    <p class="text-blue-200 text-xs mt-0.5">ທຸກການຈ່າຍ</p>
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

            <!-- Monthly Chart -->
            <?php if (!empty($monthly_data)): ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h2 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-chart-line text-blue-500 mr-2"></i>ການຈອງ ແລະ ລາຍຮັບລາຍເດືອນ
                </h2>
                <canvas id="monthlyChart" height="100"></canvas>
            </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<?php if (!empty($monthly_data)): ?>
<script>
const labels    = <?= json_encode(array_column($monthly_data,'month_label')) ?>;
const confirmed = <?= json_encode(array_column($monthly_data,'confirmed')) ?>;
const cancelled = <?= json_encode(array_column($monthly_data,'cancelled')) ?>;
const revenue   = <?= json_encode(array_column($monthly_data,'revenue')) ?>;

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
            y:  { type:'linear', position:'left',  beginAtZero:true, ticks:{ stepSize:1 }, title:{ display:true, text:'ການຈອງ' } },
            y1: { type:'linear', position:'right', beginAtZero:true, grid:{ drawOnChartArea:false }, ticks:{ callback: val => '₭'+val.toLocaleString() }, title:{ display:true, text:'ລາຍຮັບ (₭)' } }
        }
    }
});
</script>
<?php endif; ?>
</body>
</html>