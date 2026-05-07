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

// Ad revenue for selected period
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ar.Price),0)
        FROM advertisement ad
        INNER JOIN advertisement_rate ar ON ad.AD_Rate_ID=ar.AD_Rate_ID
        WHERE ad.Status_AD IN ('Approved','Active','Expired') AND DATE(ad.AD_date)>=?
    ");
    $stmt->execute([$date_from]);
    $ad_revenue = (float)$stmt->fetchColumn();
} catch (PDOException $e) { $ad_revenue = 0; }

// Ad counts for selected period
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(CASE WHEN ad.Status_AD IN ('Approved','Active') THEN 1 END) AS active_count,
            COUNT(CASE WHEN ad.Status_AD = 'Expired' THEN 1 END) AS expired_count
        FROM advertisement ad
        INNER JOIN advertisement_rate ar ON ad.AD_Rate_ID=ar.AD_Rate_ID
        WHERE ad.Status_AD IN ('Approved','Active','Expired') AND DATE(ad.AD_date)>=?
    ");
    $stmt->execute([$date_from]);
    $ad_counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $ad_active  = (int)($ad_counts['active_count'] ?? 0);
    $ad_expired = (int)($ad_counts['expired_count'] ?? 0);
} catch (PDOException $e) { $ad_active = $ad_expired = 0; }

// Monthly ad revenue
try {
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(ad.AD_date,'%Y-%m') AS month,
               DATE_FORMAT(ad.AD_date,'%b %Y') AS month_label,
               SUM(ar.Price) AS revenue
        FROM advertisement ad
        INNER JOIN advertisement_rate ar ON ad.AD_Rate_ID=ar.AD_Rate_ID
        WHERE ad.Status_AD IN ('Approved','Active','Expired')
        GROUP BY DATE_FORMAT(ad.AD_date,'%Y-%m')
        ORDER BY month ASC LIMIT 12
    ");
    $ad_monthly_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $ad_monthly_rows = []; }

// Paid ads list for selected period
try {
    $stmt = $pdo->prepare("
        SELECT ad.AD_ID, ad.AD_date, ad.Status_AD,
               ar.Duration AS ad_duration, ar.Price,
               COALESCE(v.VN_Name,'—')   AS VN_Name,
               COALESCE(v.VN_Address,'') AS VN_Address,
               COALESCE(co.Name,'—')     AS owner_name,
               COALESCE(co.Phone,'')     AS owner_phone
        FROM advertisement ad
        INNER JOIN advertisement_rate ar ON ad.AD_Rate_ID=ar.AD_Rate_ID
        LEFT JOIN  Venue_data v          ON ad.VN_ID=v.VN_ID
        LEFT JOIN  court_owner co        ON v.CA_ID=co.CA_ID
        WHERE ad.Status_AD IN ('Approved','Active','Expired')
          AND DATE(ad.AD_date) >= ?
        ORDER BY ad.AD_date ASC LIMIT 100
    ");
    $stmt->execute([$date_from]);
    $paid_ads_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $paid_ads_list = []; }

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
$section = 'ad_revenue';
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລາຍງານລາຍຮັບໂຄສະນາ - Admin</title>
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
                        <h1 class="text-2xl font-bold">ລາຍງານລາຍຮັບໂຄສະນາ</h1>
                        <p class="text-gray-600">Badminton Booking Court · Admin</p>
                    </div>
                    <div class="text-right text-sm text-gray-500">
                        <p>ໄລຍະ: <?= $period_label ?></p>
                        <p>ວັນທີພິມ: <?= date('d/m/Y H:i') ?></p>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-6 text-white">
                    <div class="bg-white bg-opacity-20 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-coins text-white"></i>
                    </div>
                    <p class="text-3xl font-extrabold">₭<?= number_format($ad_revenue) ?></p>
                    <p class="text-blue-100 text-sm mt-1">ລາຍຮັບໂຄສະນາລວມ</p>
                    <p class="text-blue-200 text-xs"><?= $period_label ?></p>
                </div>
                <div class="stat-card bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                    <div class="bg-green-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-check-circle text-green-500"></i>
                    </div>
                    <p class="text-2xl font-extrabold text-gray-800"><?= $ad_active ?></p>
                    <p class="text-xs font-semibold text-gray-600 mt-0.5">ໂຄສະນາ Active</p>
                    <p class="text-xs text-gray-400">Approved / Active</p>
                </div>
                <div class="stat-card bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                    <div class="bg-gray-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                        <i class="fas fa-history text-gray-500"></i>
                    </div>
                    <p class="text-2xl font-extrabold text-gray-800"><?= $ad_expired ?></p>
                    <p class="text-xs font-semibold text-gray-600 mt-0.5">ໂຄສະນາໝົດອາຍຸ</p>
                    <p class="text-xs text-gray-400">Expired</p>
                </div>
            </div>

            <!-- Monthly Chart -->
            <?php if (!empty($ad_monthly_rows)): ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h2 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-chart-bar text-blue-500 mr-2"></i>ລາຍຮັບໂຄສະນາລາຍເດືອນ
                </h2>
                <canvas id="adRevenueChart" height="90"></canvas>
            </div>
            <?php endif; ?>

            <!-- Paid Ads Table -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-gray-800"><i class="fas fa-user-tie text-blue-400 mr-2"></i>ລາຍຮັບຕໍ່ເຈົ້າຂອງເດີ່ນ (ໂຄສະນາ)</h2>
                    <span class="text-sm text-gray-400"><?= count($paid_ads_list) ?> ລາຍການ</span>
                </div>
                <?php if (!empty($paid_ads_list)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-gray-100 bg-gray-50">
                                <th class="text-left py-3 px-3 text-xs font-bold text-gray-500 uppercase">ລຳດັບ</th>
                                <th class="text-left py-3 px-3 text-xs font-bold text-gray-500 uppercase">ເຈົ້າຂອງ</th>
                                <th class="text-left py-3 px-3 text-xs font-bold text-gray-500 uppercase">ສະຖານທີ່</th>
                                <th class="text-center py-3 px-3 text-xs font-bold text-gray-500 uppercase">ໄລຍະ</th>
                                <th class="text-center py-3 px-3 text-xs font-bold text-gray-500 uppercase">ວັນທີ</th>
                                <th class="text-right py-3 px-3 text-xs font-bold text-gray-500 uppercase">ລາຄາ</th>
                                <th class="text-center py-3 px-3 text-xs font-bold text-gray-500 uppercase">ສະຖານະ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach ($paid_ads_list as $ad):
                                $sc = match($ad['Status_AD']) {
                                    'Active'   => ['bg-green-100',  'text-green-700',  'ໃຊ້ງານ'],
                                    'Approved' => ['bg-emerald-100','text-emerald-700','ອະນຸມັດ'],
                                    'Expired'  => ['bg-gray-100',   'text-gray-500',   'ໝົດອາຍຸ'],
                                    default    => ['bg-gray-100',   'text-gray-500',   $ad['Status_AD']],
                                };
                            ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="py-3 px-3 text-gray-400 text-xs"><?= $ad['AD_ID'] ?></td>
                                    <td class="py-3 px-3">
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($ad['owner_name']) ?></p>
                                        <p class="text-xs text-gray-400"><?= htmlspecialchars($ad['owner_phone'] ?? '') ?></p>
                                    </td>
                                    <td class="py-3 px-3 text-gray-600 text-xs"><?= htmlspecialchars($ad['VN_Name']) ?></td>
                                    <td class="py-3 px-3 text-center text-gray-500 text-xs"><?= htmlspecialchars($ad['ad_duration']) ?></td>
                                    <td class="py-3 px-3 text-center text-gray-500 text-xs"><?= date('d/m/Y', strtotime($ad['AD_date'])) ?></td>
                                    <td class="py-3 px-3 text-right font-extrabold text-blue-600">₭<?= number_format($ad['Price']) ?></td>
                                    <td class="py-3 px-3 text-center">
                                        <span class="<?= $sc[0] ?> <?= $sc[1] ?> text-xs font-bold px-2 py-1 rounded-full"><?= $sc[2] ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 bg-gray-50">
                                <td colspan="5" class="py-3 px-3 font-bold text-gray-700">ລວມທັງໝົດ</td>
                                <td class="py-3 px-3 text-right font-extrabold text-blue-600">₭<?= number_format(array_sum(array_column($paid_ads_list, 'Price'))) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-bullhorn text-5xl text-gray-200 mb-3 block"></i>
                        <p class="text-gray-400">ບໍ່ມີລາຍຮັບໂຄສະນາໃນໄລຍະນີ້</p>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<?php if (!empty($ad_monthly_rows)): ?>
<script>
const adLabels  = <?= json_encode(array_column($ad_monthly_rows, 'month_label')) ?>;
const adRevenue = <?= json_encode(array_column($ad_monthly_rows, 'revenue')) ?>;

new Chart(document.getElementById('adRevenueChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: adLabels,
        datasets: [{
            label: 'ລາຍຮັບໂຄສະນາ (₭)',
            data: adRevenue,
            backgroundColor: 'rgba(59,130,246,0.7)',
            borderColor: 'rgba(59,130,246,1)',
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
                ticks: { callback: v => '₭' + v.toLocaleString() },
                title: { display: true, text: 'ລາຍຮັບ (₭)' }
            }
        }
    }
});
</script>
<?php endif; ?>
</body>
</html>