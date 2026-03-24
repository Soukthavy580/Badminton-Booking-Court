<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

date_default_timezone_set('Asia/Vientiane');

$period    = $_GET['period'] ?? 'month';
$date_from = match($period) {
    'week'  => date('Y-m-d', strtotime('-7 days')),
    'month' => date('Y-m-d', strtotime('-30 days')),
    'year'  => date('Y-m-d', strtotime('-1 year')),
    default => date('Y-m-d', strtotime('-30 days')),
};

// Platform overview
try {
    $total_venues    = $pdo->query("SELECT COUNT(*) FROM Venue_data WHERE VN_Status = 'Active'")->fetchColumn();
    $total_courts    = $pdo->query("SELECT COUNT(*) FROM Court_data WHERE Court_Status = 'Active'")->fetchColumn();
    $total_owners    = $pdo->query("SELECT COUNT(*) FROM court_owner WHERE Status != 'Banned'")->fetchColumn();
    $total_customers = $pdo->query("SELECT COUNT(*) FROM customer WHERE Status != 'Banned'")->fetchColumn();
} catch (PDOException $e) {
    $total_venues = $total_courts = $total_owners = $total_customers = 0;
}

// Booking stats
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT b.Book_ID) AS total_bookings,
            COUNT(DISTINCT CASE WHEN b.Status_booking IN ('Confirmed','Completed','No_Show') THEN b.Book_ID END) AS confirmed,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'Completed'  THEN b.Book_ID END) AS completed,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'No_Show'    THEN b.Book_ID END) AS no_show,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'Cancelled'  THEN b.Book_ID END) AS cancelled,
            COUNT(DISTINCT CASE WHEN b.Status_booking = 'Pending'    THEN b.Book_ID END) AS pending
        FROM booking b WHERE DATE(b.Booking_date) >= ?
    ");
    $stmt->execute([$date_from]);
    $booking_stats = $stmt->fetch();
} catch (PDOException $e) {
    $booking_stats = ['total_bookings'=>0,'confirmed'=>0,'completed'=>0,'no_show'=>0,'cancelled'=>0,'pending'=>0];
}

// Revenue from packages and ads
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(pr.Price),0) FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID=pr.Package_rate_ID WHERE bp.Status_Package='Active' AND DATE(bp.Package_date)>=?");
    $stmt->execute([$date_from]);
    $pkg_revenue = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ar.Price),0) FROM advertisement ad INNER JOIN advertisement_rate ar ON ad.AD_Rate_ID=ar.AD_Rate_ID WHERE ad.Status_AD IN('Approved','Active') AND DATE(ad.AD_date)>=?");
    $stmt->execute([$date_from]);
    $ad_revenue = $stmt->fetchColumn();
    $total_revenue = $pkg_revenue + $ad_revenue;
} catch (PDOException $e) { $pkg_revenue = $ad_revenue = $total_revenue = 0; }

// Monthly revenue chart data
try {
    $stmt = $pdo->query("SELECT DATE_FORMAT(bp.Package_date,'%Y-%m') AS month, DATE_FORMAT(bp.Package_date,'%b %Y') AS month_label, COUNT(*) AS count, SUM(pr.Price) AS revenue FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID=pr.Package_rate_ID WHERE bp.Status_Package='Active' GROUP BY DATE_FORMAT(bp.Package_date,'%Y-%m') ORDER BY month ASC LIMIT 12");
    $pkg_monthly = $stmt->fetchAll();
    $stmt = $pdo->query("SELECT DATE_FORMAT(ad.AD_date,'%Y-%m') AS month, DATE_FORMAT(ad.AD_date,'%b %Y') AS month_label, COUNT(*) AS count, SUM(ar.Price) AS revenue FROM advertisement ad INNER JOIN advertisement_rate ar ON ad.AD_Rate_ID=ar.AD_Rate_ID WHERE ad.Status_AD IN('Approved','Active') GROUP BY DATE_FORMAT(ad.AD_date,'%Y-%m') ORDER BY month ASC LIMIT 12");
    $ad_monthly = $stmt->fetchAll();
    $all_months = [];
    foreach ($pkg_monthly as $row) $all_months[$row['month']] = ['label'=>$row['month_label'],'pkg_revenue'=>$row['revenue'],'ad_revenue'=>0];
    foreach ($ad_monthly as $row) {
        if (!isset($all_months[$row['month']])) $all_months[$row['month']] = ['label'=>$row['month_label'],'pkg_revenue'=>0,'ad_revenue'=>0];
        $all_months[$row['month']]['ad_revenue'] = $row['revenue'];
    }
    ksort($all_months);
} catch (PDOException $e) { $all_months = []; }

// Bookings per venue
try {
    $stmt = $pdo->prepare("
        SELECT v.VN_Name, co.Name AS owner_name,
               COUNT(DISTINCT c.COURT_ID) AS total_courts,
               COUNT(DISTINCT b.Book_ID) AS total_bookings,
               COUNT(DISTINCT CASE WHEN b.Status_booking IN ('Confirmed','Completed','No_Show') THEN b.Book_ID END) AS confirmed,
               COUNT(DISTINCT CASE WHEN b.Status_booking = 'Cancelled' THEN b.Book_ID END) AS cancelled,
               COALESCE(SUM(CASE WHEN b.Status_booking IN ('Confirmed','Completed','No_Show')
                   THEN TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)*CAST(REPLACE(REPLACE(v.Price_per_hour,',',''),' ','') AS UNSIGNED)
                   ELSE 0 END),0) AS booking_revenue
        FROM Venue_data v
        INNER JOIN court_owner co ON v.CA_ID=co.CA_ID
        LEFT JOIN Court_data c ON c.VN_ID=v.VN_ID
        LEFT JOIN booking_detail bd ON bd.COURT_ID=c.COURT_ID
        LEFT JOIN booking b ON bd.Book_ID=b.Book_ID AND DATE(b.Booking_date)>=?
        WHERE v.VN_Status='Active'
        GROUP BY v.VN_ID ORDER BY confirmed DESC LIMIT 10
    ");
    $stmt->execute([$date_from]);
    $venue_stats = $stmt->fetchAll();
} catch (PDOException $e) { $venue_stats = []; }

$max_venue_bookings = !empty($venue_stats) ? max(1, max(array_column($venue_stats, 'confirmed'))) : 1;

// Recent packages
try {
    $stmt = $pdo->prepare("
        SELECT bp.*, pr.Package_duration, pr.Price, co.Name AS owner_name,
               COALESCE(v.VN_Name,'ຍັງບໍ່ມີສະຖານທີ່') AS VN_Name
        FROM package bp
        INNER JOIN package_rate pr ON bp.Package_rate_ID=pr.Package_rate_ID
        INNER JOIN court_owner co ON bp.CA_ID=co.CA_ID
        LEFT JOIN Venue_data v ON bp.VN_ID=v.VN_ID
        WHERE bp.Status_Package='Active' AND DATE(bp.Package_date)>=?
        ORDER BY bp.Package_date DESC LIMIT 10
    ");
    $stmt->execute([$date_from]);
    $recent_packages = $stmt->fetchAll();
} catch (PDOException $e) { $recent_packages = []; }

// Recent ads
try {
    $stmt = $pdo->prepare("
        SELECT ad.*, ar.Duration AS Advertisement_duration, ar.Price,
               co.Name AS owner_name, v.VN_Name
        FROM advertisement ad
        INNER JOIN advertisement_rate ar ON ad.AD_Rate_ID=ar.AD_Rate_ID
        INNER JOIN Venue_data v ON ad.VN_ID=v.VN_ID
        INNER JOIN court_owner co ON v.CA_ID=co.CA_ID
        WHERE ad.Status_AD IN('Approved','Active') AND DATE(ad.AD_date)>=?
        ORDER BY ad.AD_date DESC LIMIT 10
    ");
    $stmt->execute([$date_from]);
    $recent_ads = $stmt->fetchAll();
} catch (PDOException $e) { $recent_ads = []; }

$period_label = match($period) { 'week' => '7 ວັນຜ່ານມາ', 'year' => '1 ປີຜ່ານມາ', default => '30 ວັນຜ່ານມາ' };
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລາຍງານ - Admin</title>
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
            .flex-1.flex.flex-col { display: block !important; }
            main { padding: 0 !important; }
            .print-header { display: block !important; }
            .stat-card { box-shadow: none !important; border: 1px solid #e5e7eb !important; break-inside: avoid; }
            .bg-gradient-to-br { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table { break-inside: auto; }
            tr { break-inside: avoid; }
            .grid { display: grid !important; }
            .md\:grid-cols-4 { grid-template-columns: repeat(4, 1fr) !important; }
            .md\:grid-cols-3 { grid-template-columns: repeat(3, 1fr) !important; }
            .md\:grid-cols-2 { grid-template-columns: repeat(2, 1fr) !important; }
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
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800"></h1>
                    <p class="text-sm text-gray-500">ພາບລວມຂອງລະບົບ</p>
                </div>
                <div class="flex gap-2 items-center">
                    <?php foreach (['week'=>'7 ວັນ','month'=>'30 ວັນ','year'=>'1 ປີ'] as $key => $label): ?>
                        <a href="?period=<?= $key ?>"
                           class="px-4 py-2 rounded-xl font-semibold text-sm transition
                                  <?= $period===$key ? 'bg-blue-600 text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                            <?= $label ?>
                        </a>
                    <?php endforeach; ?>
                    <button onclick="window.print()"
                            class="flex items-center gap-2 bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-xl font-semibold text-sm transition shadow">
                        <i class="fas fa-print"></i>ພິມລາຍງານ
                    </button>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6">

            <!-- Print Header -->
            <div class="print-header mb-6 pb-4 border-b-2 border-gray-800">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">ລາຍງານສະຖິຕິລະບົບ</h1>
                        <p class="text-gray-600">Badminton Booking Court — ພາບລວມຂອງລະບົບ</p>
                    </div>
                    <div class="text-right text-sm text-gray-500">
                        <p>ໄລຍະ: <?= $period_label ?></p>
                        <p>ວັນທີພິມ: <?= date('d/m/Y H:i') ?></p>
                    </div>
                </div>
            </div>


            <!-- Section 3: Revenue -->
            <h2 class="font-bold text-gray-700 text-sm uppercase tracking-wide mb-3 mt-2">
                <i class="fas fa-coins text-yellow-400 mr-1"></i>ລາຍຮັບ (<?= $period_label ?>)
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-coins text-white"></i>
                    </div>
                    <p class="text-3xl font-extrabold">₭<?= number_format($total_revenue) ?></p>
                    <p class="text-green-100 text-sm mt-1">ລາຍຮັບລວມ</p>
                    <p class="text-green-200 text-xs mt-0.5">ແພັກເກດ + ໂຄສະນາ</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-box text-white"></i>
                    </div>
                    <p class="text-3xl font-extrabold">₭<?= number_format($pkg_revenue) ?></p>
                    <p class="text-purple-100 text-sm mt-1">ລາຍຮັບຈາກແພັກເກດ</p>
                    <p class="text-purple-200 text-xs mt-0.5">ຈາກແພັກເກດທີ່ໃຊ້ງານ</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-bullhorn text-white"></i>
                    </div>
                    <p class="text-3xl font-extrabold">₭<?= number_format($ad_revenue) ?></p>
                    <p class="text-blue-100 text-sm mt-1">ລາຍຮັບຈາກໂຄສະນາ</p>
                    <p class="text-blue-200 text-xs mt-0.5">ຈາກໂຄສະນາທີ່ອະນຸມັດ</p>
                </div>
            </div>

            <!-- Section 4: Monthly Revenue Chart -->
            <?php if (!empty($all_months)): ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h2 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-chart-bar text-blue-500 mr-2"></i>ລາຍຮັບລາຍເດືອນ (ແພັກເກດ + ໂຄສະນາ)
                </h2>
                <canvas id="revenueChart" height="80"></canvas>
            </div>
            <?php endif; ?>

            <!-- Section 5: Bookings Per Venue -->
            <h2 class="font-bold text-gray-700 text-sm uppercase tracking-wide mb-3 mt-2">
                <i class="fas fa-store text-blue-400 mr-1"></i>ການຈອງຕໍ່ສະຖານທີ່ (<?= $period_label ?>)
            </h2>
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <?php if (!empty($venue_stats)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b-2 border-gray-100">
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ສະຖານທີ່</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase">ເຈົ້າຂອງ</th>
                                    <th class="text-center py-3 px-2 text-xs font-bold text-gray-500 uppercase">ຄອດ</th>
                                    <th class="text-center py-3 px-2 text-xs font-bold text-gray-500 uppercase">ຈອງທັງໝົດ</th>
                                    <th class="text-center py-3 px-2 text-xs font-bold text-gray-500 uppercase">ຢືນຢັນ</th>
                                    <th class="text-center py-3 px-2 text-xs font-bold text-gray-500 uppercase">ຍົກເລີກ</th>
                                    <th class="text-right py-3 px-2 text-xs font-bold text-gray-500 uppercase">ລາຍຮັບ</th>
                                    <th class="text-left py-3 px-2 text-xs font-bold text-gray-500 uppercase"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php foreach ($venue_stats as $i => $v):
                                    $pct = $max_venue_bookings > 0 ? round(($v['confirmed'] / $max_venue_bookings) * 100) : 0;
                                ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-2 font-semibold text-gray-800"><?= htmlspecialchars($v['VN_Name']) ?></td>
                                        <td class="py-3 px-2 text-gray-500 text-xs"><?= htmlspecialchars($v['owner_name']) ?></td>
                                        <td class="py-3 px-2 text-center text-gray-600"><?= number_format($v['total_courts']) ?></td>
                                        <td class="py-3 px-2 text-center text-gray-600"><?= number_format($v['total_bookings']) ?></td>
                                        <td class="py-3 px-2 text-center">
                                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-bold"><?= number_format($v['confirmed']) ?></span>
                                        </td>
                                        <td class="py-3 px-2 text-center">
                                            <span class="bg-red-100 text-red-600 px-2 py-0.5 rounded-full text-xs font-bold"><?= number_format($v['cancelled']) ?></span>
                                        </td>
                                        <td class="py-3 px-2 text-right font-bold text-green-600">
                                            <?= $v['booking_revenue'] > 0 ? '₭'.number_format($v['booking_revenue']) : '—' ?>
                                        </td>
                                        <td class="py-3 px-2 w-32">
                                            <div class="bg-gray-100 rounded-full h-2">
                                                <div class="bg-blue-500 h-2 rounded-full" style="width:<?= $pct ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-store text-4xl text-gray-200 mb-2 block"></i>
                        <p class="text-gray-400 text-sm">ຍັງບໍ່ມີຂໍ້ມູນການຈອງ</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Section 6: Package + Ad Revenue Tables -->
            <h2 class="font-bold text-gray-700 text-sm uppercase tracking-wide mb-3 mt-2">
                <i class="fas fa-receipt text-purple-400 mr-1"></i>ລາຍຮັບລາຍລະອຽດ (<?= $period_label ?>)
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <!-- Packages -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-box text-purple-500"></i>ແພັກເກດ
                        <span class="text-sm font-normal text-gray-400">(<?= count($recent_packages) ?> ລາຍການ)</span>
                    </h3>
                    <?php if (!empty($recent_packages)): ?>
                        <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                            <?php foreach ($recent_packages as $pkg): ?>
                                <div class="flex items-center justify-between bg-gray-50 rounded-xl px-4 py-3 text-sm">
                                    <div>
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($pkg['VN_Name']) ?></p>
                                        <p class="text-xs text-gray-400">
                                            <?= htmlspecialchars($pkg['owner_name']) ?> · <?= htmlspecialchars($pkg['Package_duration']) ?> · <?= date('d/m/Y', strtotime($pkg['Package_date'])) ?>
                                        </p>
                                    </div>
                                    <p class="font-bold text-purple-600 flex-shrink-0">₭<?= number_format($pkg['Price']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-3 pt-3 border-t border-gray-100 flex justify-between text-sm">
                            <span class="text-gray-500 font-semibold">ລວມທັງໝົດ</span>
                            <span class="font-extrabold text-purple-600">₭<?= number_format($pkg_revenue) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-box text-4xl text-gray-200 mb-2 block"></i>
                            <p class="text-gray-400 text-sm">ບໍ່ມີແພັກເກດໃນໄລຍະນີ້</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Ads -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-bullhorn text-blue-500"></i>ໂຄສະນາ
                        <span class="text-sm font-normal text-gray-400">(<?= count($recent_ads) ?> ລາຍການ)</span>
                    </h3>
                    <?php if (!empty($recent_ads)): ?>
                        <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                            <?php foreach ($recent_ads as $ad): ?>
                                <div class="flex items-center justify-between bg-gray-50 rounded-xl px-4 py-3 text-sm">
                                    <div>
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($ad['VN_Name']) ?></p>
                                        <p class="text-xs text-gray-400">
                                            <?= htmlspecialchars($ad['owner_name']) ?> · <?= htmlspecialchars($ad['Advertisement_duration']) ?> · <?= date('d/m/Y', strtotime($ad['AD_date'])) ?>
                                        </p>
                                    </div>
                                    <p class="font-bold text-blue-600 flex-shrink-0">₭<?= number_format($ad['Price']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-3 pt-3 border-t border-gray-100 flex justify-between text-sm">
                            <span class="text-gray-500 font-semibold">ລວມທັງໝົດ</span>
                            <span class="font-extrabold text-blue-600">₭<?= number_format($ad_revenue) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-bullhorn text-4xl text-gray-200 mb-2 block"></i>
                            <p class="text-gray-400 text-sm">ບໍ່ມີໂຄສະນາໃນໄລຍະນີ້</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>
</div>

<?php if (!empty($all_months)): ?>
<script>
const labels     = <?= json_encode(array_column(array_values($all_months),'label')) ?>;
const pkgRevenue = <?= json_encode(array_column(array_values($all_months),'pkg_revenue')) ?>;
const adRevenue  = <?= json_encode(array_column(array_values($all_months),'ad_revenue')) ?>;

new Chart(document.getElementById('revenueChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels,
        datasets: [
            { label:'ລາຍຮັບແພັກເກດ (₭)', data:pkgRevenue, backgroundColor:'rgba(168,85,247,0.7)', borderColor:'rgba(168,85,247,1)', borderWidth:1, borderRadius:6 },
            { label:'ລາຍຮັບໂຄສະນາ (₭)',  data:adRevenue,  backgroundColor:'rgba(59,130,246,0.7)',  borderColor:'rgba(59,130,246,1)',  borderWidth:1, borderRadius:6 }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode:'index', intersect:false },
        plugins: {
            legend: { position:'top' },
            tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ₭${ctx.parsed.y.toLocaleString()}` }}
        },
        scales: {
            y: { beginAtZero:true, ticks:{ callback: val => '₭'+val.toLocaleString() }, title:{ display:true, text:'ລາຍຮັບ (₭)' }}
        }
    }
});
</script>
<?php endif; ?>
</body>
</html>