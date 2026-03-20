<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$period    = $_GET['period'] ?? 'month';
$date_from = match($period) {
    'week'  => date('Y-m-d', strtotime('-7 days')),
    'month' => date('Y-m-d', strtotime('-30 days')),
    'year'  => date('Y-m-d', strtotime('-1 year')),
    default => date('Y-m-d', strtotime('-30 days')),
};

try {
    $total_venues    = $pdo->query("SELECT COUNT(*) FROM Venue_data WHERE VN_Status = 'Active'")->fetchColumn();
    $total_owners    = $pdo->query("SELECT COUNT(*) FROM court_owner WHERE Status != 'Banned'")->fetchColumn();
    $total_customers = $pdo->query("SELECT COUNT(*) FROM customer WHERE Status != 'Banned'")->fetchColumn();
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT b.Book_ID) AS total_bookings,
               SUM(CASE WHEN b.Status_booking IN ('Confirmed','Completed') THEN 1 ELSE 0 END) AS confirmed,
               SUM(CASE WHEN b.Status_booking='Completed' THEN 1 ELSE 0 END) AS completed,
               SUM(CASE WHEN b.Status_booking='No_Show'   THEN 1 ELSE 0 END) AS no_show,
               SUM(CASE WHEN b.Status_booking='Cancelled' THEN 1 ELSE 0 END) AS cancelled
        FROM booking b WHERE DATE(b.Booking_date) >= ?
    ");
    $stmt->execute([$date_from]);
    $booking_stats = $stmt->fetch();
} catch (PDOException $e) {
    $total_venues = $total_owners = $total_customers = 0;
    $booking_stats = ['total_bookings'=>0,'confirmed'=>0,'completed'=>0,'no_show'=>0,'cancelled'=>0];
}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(pr.Price),0) FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID=pr.Package_rate_ID WHERE bp.Status_Package='Active' AND DATE(bp.Package_date)>=?");
    $stmt->execute([$date_from]);
    $pkg_revenue = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ar.Price),0) FROM advertisement ad INNER JOIN advertisement_rate ar ON ad.AD_Rate_ID=ar.AD_Rate_ID WHERE ad.Status_AD IN('Approved','Active') AND DATE(ad.AD_date)>=?");
    $stmt->execute([$date_from]);
    $ad_revenue = $stmt->fetchColumn();
    $total_revenue = $pkg_revenue + $ad_revenue;
} catch (PDOException $e) { $pkg_revenue = $ad_revenue = $total_revenue = 0; }

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

try {
    $stmt = $pdo->prepare("
        SELECT v.VN_Name, co.Name AS owner_name,
               COUNT(DISTINCT b.Book_ID) AS total_bookings,
               SUM(CASE WHEN b.Status_booking='Confirmed' THEN 1 ELSE 0 END) AS confirmed,
               SUM(CASE WHEN b.Status_booking='Cancelled' THEN 1 ELSE 0 END) AS cancelled,
               COALESCE(SUM(CASE WHEN b.Status_booking='Confirmed' THEN TIMESTAMPDIFF(HOUR,bd.Start_time,bd.End_time)*CAST(REPLACE(REPLACE(v.Price_per_hour,',',''),' ','') AS UNSIGNED) ELSE 0 END),0) AS booking_revenue
        FROM Venue_data v INNER JOIN court_owner co ON v.CA_ID=co.CA_ID
        LEFT JOIN Court_data c ON c.VN_ID=v.VN_ID
        LEFT JOIN booking_detail bd ON bd.COURT_ID=c.COURT_ID
        LEFT JOIN booking b ON bd.Book_ID=b.Book_ID AND DATE(b.Booking_date)>=?
        WHERE v.VN_Status='Active' GROUP BY v.VN_ID ORDER BY confirmed DESC LIMIT 10
    ");
    $stmt->execute([$date_from]);
    $venue_stats = $stmt->fetchAll();
} catch (PDOException $e) { $venue_stats = []; }

$max_venue_bookings = !empty($venue_stats) ? max(1, max(array_column($venue_stats, 'confirmed'))) : 1;

try {
    $stmt = $pdo->prepare("SELECT cu.Name, cu.Email, COUNT(DISTINCT b.Book_ID) AS total_bookings FROM customer cu INNER JOIN booking b ON cu.C_ID=b.C_ID WHERE b.Status_booking='Confirmed' AND DATE(b.Booking_date)>=? GROUP BY cu.C_ID ORDER BY total_bookings DESC LIMIT 5");
    $stmt->execute([$date_from]);
    $top_customers = $stmt->fetchAll();
} catch (PDOException $e) { $top_customers = []; }

try {
    $stmt = $pdo->prepare("SELECT bp.*, pr.Package_duration, pr.Price, co.Name AS owner_name, COALESCE(v.VN_Name,'ຍັງບໍ່ມີສະຖານທີ່') AS VN_Name FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID=pr.Package_rate_ID INNER JOIN court_owner co ON bp.CA_ID=co.CA_ID LEFT JOIN Venue_data v ON bp.VN_ID=v.VN_ID WHERE bp.Status_Package='Active' AND DATE(bp.Package_date)>=? ORDER BY bp.Package_date DESC LIMIT 10");
    $stmt->execute([$date_from]);
    $recent_packages = $stmt->fetchAll();
} catch (PDOException $e) { $recent_packages = []; }

try {
    $stmt = $pdo->prepare("SELECT ad.*, ar.Duration AS Advertisement_duration, ar.Price, co.Name AS owner_name, v.VN_Name FROM advertisement ad INNER JOIN advertisement_rate ar ON ad.AD_Rate_ID=ar.AD_Rate_ID INNER JOIN Venue_data v ON ad.VN_ID=v.VN_ID INNER JOIN court_owner co ON v.CA_ID=co.CA_ID WHERE ad.Status_AD IN('Approved','Active') AND DATE(ad.AD_date)>=? ORDER BY ad.AD_date DESC LIMIT 10");
    $stmt->execute([$date_from]);
    $recent_ads = $stmt->fetchAll();
} catch (PDOException $e) { $recent_ads = []; }
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລາຍງານ - Admin</title>
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
                    <h1 class="text-xl font-bold text-gray-800">ລາຍງານ ແລະ ສະຖິຕິ</h1>
                    <p class="text-sm text-gray-500">ພາບລວມທົ່ວທັງແພລດຟອມ</p>
                </div>
                <div class="flex gap-2">
                    <?php foreach (['week'=>'7 ວັນ','month'=>'30 ວັນ','year'=>'1 ປີ'] as $key => $label): ?>
                        <a href="?period=<?= $key ?>"
                           class="px-4 py-2 rounded-xl font-semibold text-sm transition
                                  <?= $period===$key ? 'bg-blue-600 text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                            <?= $label ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6">

            <!-- Platform Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <?php foreach ([
                    ['label'=>'ສະຖານທີ່ໃຊ້ງານໄດ້','value'=>$total_venues,                   'icon'=>'fa-store',   'color'=>'blue',  'sub'=>'ໃນແພລດຟອມ'],
                    ['label'=>'ເຈົ້າຂອງ',          'value'=>$total_owners,                   'icon'=>'fa-user-tie','color'=>'purple','sub'=>'ລົງທະບຽນ'],
                    ['label'=>'ລູກຄ້າ',             'value'=>$total_customers,                'icon'=>'fa-users',   'color'=>'yellow','sub'=>'ລົງທະບຽນ'],
                    ['label'=>'ການຈອງທັງໝົດ',     'value'=>$booking_stats['total_bookings'],           'icon'=>'fa-calendar',   'color'=>'green',  'sub'=>'ໃນໄລຍະທີ່ເລືອກ'],
                    ['label'=>'ສຳເລັດ',               'value'=>$booking_stats['completed'] ?? 0,           'icon'=>'fa-trophy',     'color'=>'emerald','sub'=>'ລູກຄ້າຈ່າຍຄົບ'],
                    ['label'=>'ບໍ່ໄດ້ມາ',             'value'=>$booking_stats['no_show']   ?? 0,           'icon'=>'fa-user-slash', 'color'=>'orange', 'sub'=>'ບໍ່ໄດ້ມາ'],
                ] as $sc): ?>
                    <div class="stat-card bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                        <div class="bg-<?= $sc['color'] ?>-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                            <i class="fas <?= $sc['icon'] ?> text-<?= $sc['color'] ?>-500"></i>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-800"><?= number_format($sc['value']) ?></p>
                        <p class="text-xs font-semibold text-gray-600 mt-0.5"><?= $sc['label'] ?></p>
                        <p class="text-xs text-gray-400"><?= $sc['sub'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Revenue Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-coins text-white"></i>
                    </div>
                    <p class="text-3xl font-extrabold">₭<?= number_format($total_revenue) ?></p>
                    <p class="text-green-100 text-sm mt-1">ລາຍຮັບລວມແພລດຟອມ</p>
                    <p class="text-green-200 text-xs mt-0.5">ແພັກເກດ + ໂຄສະນາ</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-box text-white"></i>
                    </div>
                    <p class="text-3xl font-extrabold">₭<?= number_format($pkg_revenue) ?></p>
                    <p class="text-purple-100 text-sm mt-1">ລາຍຮັບຈາກແພັກເກດ</p>
                    <p class="text-purple-200 text-xs mt-0.5">ຈາກແພັກເກດທີ່ໃຊ້ງານໄດ້</p>
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

            <!-- Chart + Top Customers -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="md:col-span-2 bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="font-bold text-gray-800 mb-4">
                        <i class="fas fa-chart-bar text-blue-500 mr-2"></i>ລາຍຮັບລາຍເດືອນ
                    </h2>
                    <?php if (!empty($all_months)): ?>
                        <canvas id="revenueChart" height="120"></canvas>
                    <?php else: ?>
                        <div class="flex items-center justify-center h-40 text-center">
                            <div>
                                <i class="fas fa-chart-bar text-4xl text-gray-200 mb-2 block"></i>
                                <p class="text-gray-400 text-sm">ຍັງບໍ່ມີຂໍ້ມູນ</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="font-bold text-gray-800 mb-4">
                        <i class="fas fa-crown text-yellow-500 mr-2"></i>ລູກຄ້າຈອງຫຼາຍທີ່ສຸດ
                    </h2>
                    <?php if (!empty($top_customers)): ?>
                        <div class="space-y-3">
                            <?php foreach ($top_customers as $i => $cu):
                                $medal = match($i) { 0=>'🥇',1=>'🥈',2=>'🥉',default=>'' };
                            ?>
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-gradient-to-br from-yellow-400 to-orange-500 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                        <?= strtoupper(substr($cu['Name'], 0, 1)) ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-gray-800 text-sm truncate"><?= $medal ?> <?= htmlspecialchars($cu['Name']) ?></p>
                                        <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($cu['Email']) ?></p>
                                    </div>
                                    <div class="bg-green-100 text-green-700 text-xs font-bold px-2 py-1 rounded-full flex-shrink-0">
                                        <?= number_format($cu['total_bookings']) ?> ຈອງ
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-users text-4xl text-gray-200 mb-2 block"></i>
                            <p class="text-gray-400 text-sm">ຍັງບໍ່ມີຂໍ້ມູນ</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bookings Per Venue -->
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h2 class="font-bold text-gray-800 mb-5">
                    <i class="fas fa-store text-blue-500 mr-2"></i>ການຈອງຕໍ່ສະຖານທີ່
                </h2>
                <?php if (!empty($venue_stats)): ?>
                    <div class="space-y-4">
                        <?php foreach ($venue_stats as $v):
                            $pct = round(($v['confirmed'] / $max_venue_bookings) * 100);
                        ?>
                            <div>
                                <div class="flex items-center justify-between text-sm mb-1.5">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-gray-800"><?= htmlspecialchars($v['VN_Name']) ?></span>
                                        <span class="text-xs text-gray-400">ໂດຍ <?= htmlspecialchars($v['owner_name']) ?></span>
                                    </div>
                                    <div class="flex items-center gap-3 text-xs">
                                        <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-bold"><?= number_format($v['confirmed']) ?> ຢືນຢັນ</span>
                                        <span class="bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-bold"><?= number_format($v['cancelled']) ?> ຍົກເລີກ</span>
                                        <?php if ($v['booking_revenue'] > 0): ?>
                                            <span class="text-green-600 font-bold">₭<?= number_format($v['booking_revenue']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="bg-gray-100 rounded-full h-2.5">
                                    <div class="bg-gradient-to-r from-blue-500 to-blue-400 h-2.5 rounded-full" style="width:<?= $pct ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-store text-4xl text-gray-200 mb-2 block"></i>
                        <p class="text-gray-400 text-sm">ຍັງບໍ່ມີຂໍ້ມູນການຈອງ</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Package + Ad Tables -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="font-bold text-gray-800 mb-4">
                        <i class="fas fa-box text-purple-500 mr-2"></i>ລາຍຮັບແພັກເກດ
                        <span class="text-sm font-normal text-gray-400 ml-1">(ຫຼ້າສຸດ)</span>
                    </h2>
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
                                    <p class="font-bold text-purple-600">₭<?= number_format($pkg['Price']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-3 pt-3 border-t border-gray-100 flex justify-between text-sm">
                            <span class="text-gray-500 font-semibold">ລວມໄລຍະ</span>
                            <span class="font-extrabold text-purple-600">₭<?= number_format($pkg_revenue) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-box text-4xl text-gray-200 mb-2 block"></i>
                            <p class="text-gray-400 text-sm">ບໍ່ມີແພັກເກດໃນໄລຍະນີ້</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="font-bold text-gray-800 mb-4">
                        <i class="fas fa-bullhorn text-blue-500 mr-2"></i>ລາຍຮັບໂຄສະນາ
                        <span class="text-sm font-normal text-gray-400 ml-1">(ຫຼ້າສຸດ)</span>
                    </h2>
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
                                    <p class="font-bold text-blue-600">₭<?= number_format($ad['Price']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-3 pt-3 border-t border-gray-100 flex justify-between text-sm">
                            <span class="text-gray-500 font-semibold">ລວມໄລຍະ</span>
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
        responsive:true,
        interaction:{mode:'index',intersect:false},
        plugins:{
            legend:{position:'top'},
            tooltip:{callbacks:{label:ctx=>` ${ctx.dataset.label}: ₭${ctx.parsed.y.toLocaleString()}`}}
        },
        scales:{y:{beginAtZero:true,ticks:{callback:val=>'₭'+val.toLocaleString()},title:{display:true,text:'ລາຍຮັບ (₭)'}}}
    }
});
</script>
<?php endif; ?>
</body>
</html>