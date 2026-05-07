<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

// Pending approvals
$pending_venues   = 0;
$pending_packages = 0;
$pending_ads      = 0;
$total_owners     = 0;
$total_customers  = 0;
$total_venues     = 0;
$recent_bookings  = [];
$recent_owners    = [];

try {
    $pending_venues   = (int)$pdo->query("SELECT COUNT(*) FROM Venue_data WHERE VN_Status='Pending'")->fetchColumn();
    $pending_packages = (int)$pdo->query("SELECT COUNT(*) FROM package WHERE Status_Package='Pending'")->fetchColumn();
    $pending_ads      = (int)$pdo->query("SELECT COUNT(*) FROM advertisement WHERE Status_AD='Pending'")->fetchColumn();
    $total_owners     = (int)$pdo->query("SELECT COUNT(*) FROM court_owner WHERE Status='Active'")->fetchColumn();
    $total_customers  = (int)$pdo->query("SELECT COUNT(*) FROM customer")->fetchColumn();
    $total_venues     = (int)$pdo->query("SELECT COUNT(*) FROM Venue_data WHERE VN_Status='Active'")->fetchColumn();

    // Today's bookings
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT b.Book_ID) AS total,
               SUM(CASE WHEN b.Status_booking='Pending' AND b.Slip_payment IS NOT NULL AND b.Slip_payment!='' THEN 1 ELSE 0 END) AS pending,
               SUM(CASE WHEN b.Status_booking='Confirmed' THEN 1 ELSE 0 END) AS confirmed,
               SUM(CASE WHEN b.Status_booking='Completed' THEN 1 ELSE 0 END) AS completed
        FROM booking b WHERE DATE(b.Booking_date) = CURDATE()
    ");
    $today_bookings = $stmt->fetch();

    // Recent 5 owners
    $stmt = $pdo->query("
        SELECT co.CA_ID, co.Name, co.Email, co.Status, v.VN_Name
        FROM court_owner co
        LEFT JOIN Venue_data v ON co.CA_ID = v.CA_ID
        ORDER BY co.CA_ID DESC LIMIT 5
    ");
    $recent_owners = $stmt->fetchAll();

    // Recent 5 bookings
    $stmt = $pdo->query("
        SELECT b.Book_ID, b.Status_booking, b.Booking_date,
               cu.Name AS customer_name, v.VN_Name
        FROM booking b
        INNER JOIN customer cu ON b.C_ID = cu.C_ID
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
        GROUP BY b.Book_ID
        ORDER BY b.Booking_date DESC LIMIT 5
    ");
    $recent_bookings = $stmt->fetchAll();

} catch (PDOException $e) {}

$total_pending = $pending_venues + $pending_packages + $pending_ads;
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ໜ້າຫຼັກ - Admin</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); }
        .action-card { transition: all 0.2s ease; }
        .action-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?> </h1>
                    <p class="text-sm text-gray-500"><?= date('l, d F Y') ?> · ພາບລວມຂອງລະບົບ</p>
                </div>
                <?php if ($total_pending > 0): ?>
                    <div class="flex items-center gap-2 bg-red-50 border border-red-200 px-4 py-2 rounded-xl animate-pulse">
                        <i class="fas fa-exclamation-circle text-red-500"></i>
                        <span class="text-red-700 font-bold text-sm"><?= number_format($total_pending) ?> ລໍຖ້າການອະນຸມັດ</span>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-1 p-6">

            <!-- Platform Stats -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <?php foreach ([
                    ['label'=>'ເຈົ້າຂອງ',    'value'=>$total_owners,   'icon'=>'fa-user-tie',   'color'=>'purple', 'href'=>'/Badminton_court_Booking/admin/owners/'],
                    ['label'=>'ລູກຄ້າ',       'value'=>$total_customers,'icon'=>'fa-users',      'color'=>'blue',   'href'=>null],
                    ['label'=>'ສະຖານທີ່',    'value'=>$total_venues,   'icon'=>'fa-store',      'color'=>'green',  'href'=>'/Badminton_court_Booking/admin/venues/'],
                    ['label'=>'ລໍຖ້າສະຖານທີ່','value'=>$pending_venues, 'icon'=>'fa-store',      'color'=>'yellow', 'href'=>'/Badminton_court_Booking/admin/venues/?filter=pending'],
                    ['label'=>'ລໍຖ້າແພັກ',   'value'=>$pending_packages,'icon'=>'fa-box',        'color'=>'orange', 'href'=>'/Badminton_court_Booking/admin/packages/?filter=pending'],
                    ['label'=>'ລໍຖ້າໂຄສະນາ', 'value'=>$pending_ads,    'icon'=>'fa-bullhorn',   'color'=>'red',    'href'=>'/Badminton_court_Booking/admin/advertisements/?filter=pending'],
                ] as $sc):
                    $tag = $sc['href'] ? 'a' : 'div';
                    $href = $sc['href'] ? "href=\"{$sc['href']}\"" : '';
                    $highlight = ($sc['value'] > 0 && in_array($sc['label'], ['ລໍຖ້າສະຖານທີ່','ລໍຖ້າແພັກ','ລໍຖ້າໂຄສະນາ'])) ? 'ring-2 ring-'.$sc['color'].'-300' : '';
                ?>
                    <<?= $tag ?> <?= $href ?> class="stat-card bg-white rounded-2xl p-5 shadow-sm border border-gray-100 <?= $highlight ?> block">
                        <div class="bg-<?= $sc['color'] ?>-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                            <i class="fas <?= $sc['icon'] ?> text-<?= $sc['color'] ?>-500"></i>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-800"><?= number_format($sc['value']) ?></p>
                        <p class="text-xs text-gray-500 mt-0.5"><?= $sc['label'] ?></p>
                    </<?= $tag ?>>
                <?php endforeach; ?>
            </div>
            <!-- Quick Actions -->
            <?php if ($total_pending > 0): ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h2 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-bolt text-yellow-500 mr-2"></i>ຕ້ອງດຳເນີນການ
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <?php if ($pending_venues > 0): ?>
                        <a href="/Badminton_court_Booking/admin/venues/?filter=pending"
                           class="action-card flex items-center gap-4 bg-yellow-50 border-2 border-yellow-300 rounded-2xl p-4">
                            <div class="bg-yellow-100 w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-store text-yellow-600 text-lg"></i>
                            </div>
                            <div>
                                <p class="font-bold text-yellow-800"><?= number_format($pending_venues) ?> ສະຖານທີ່ລໍຖ້າ</p>
                                <p class="text-xs text-yellow-600">ກວດສອບ ແລະ ອະນຸມັດ →</p>
                            </div>
                        </a>
                    <?php endif; ?>
                    <?php if ($pending_packages > 0): ?>
                        <a href="/Badminton_court_Booking/admin/packages/?filter=pending"
                           class="action-card flex items-center gap-4 bg-orange-50 border-2 border-orange-300 rounded-2xl p-4">
                            <div class="bg-orange-100 w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-box text-orange-600 text-lg"></i>
                            </div>
                            <div>
                                <p class="font-bold text-orange-800"><?= number_format($pending_packages) ?> ແພັກເກດລໍຖ້າ</p>
                                <p class="text-xs text-orange-600">ກວດສອບໃບຮັບເງິນ →</p>
                            </div>
                        </a>
                    <?php endif; ?>
                    <?php if ($pending_ads > 0): ?>
                        <a href="/Badminton_court_Booking/admin/advertisements/?filter=pending"
                           class="action-card flex items-center gap-4 bg-red-50 border-2 border-red-300 rounded-2xl p-4">
                            <div class="bg-red-100 w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-bullhorn text-red-600 text-lg"></i>
                            </div>
                            <div>
                                <p class="font-bold text-red-800"><?= number_format($pending_ads) ?> ໂຄສະນາລໍຖ້າ</p>
                                <p class="text-xs text-red-600">ກວດສອບໃບຮັບເງິນ →</p>
                            </div>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <!-- Recent Owners -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-bold text-gray-800"><i class="fas fa-user-tie text-purple-500 mr-2"></i>ເຈົ້າຂອງລ່າສຸດ</h2>
                        <a href="/Badminton_court_Booking/admin/owners/" class="text-xs text-blue-600 hover:underline">ເບິ່ງທັງໝົດ →</a>
                    </div>
                    <?php if (!empty($recent_owners)): ?>
                        <div class="space-y-3">
                            <?php foreach ($recent_owners as $o):
                                $is_banned = $o['Status'] === 'Banned';
                            ?>
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-gradient-to-br from-purple-400 to-blue-500 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                        <?= strtoupper(substr($o['Name'], 0, 1)) ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($o['Name']) ?></p>
                                        <p class="text-xs text-gray-400 truncate"><?= $o['VN_Name'] ? htmlspecialchars($o['VN_Name']) : 'ຍັງບໍ່ມີສະຖານທີ່' ?></p>
                                    </div>
                                    <span class="text-xs font-bold px-2 py-0.5 rounded-full flex-shrink-0 <?= $is_banned ? 'bg-red-100 text-red-600' : 'bg-green-100 text-green-700' ?>">
                                        <?= $is_banned ? 'ລະງັບ' : 'ໃຊ້ງານ' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-400 text-sm text-center py-6">ຍັງບໍ່ມີເຈົ້າຂອງ</p>
                    <?php endif; ?>
                </div>

                <!-- Recent Bookings -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-bold text-gray-800"><i class="fas fa-calendar-check text-green-500 mr-2"></i>ການຈອງລ່າສຸດ</h2>
                        <a href="/Badminton_court_Booking/admin/reports/" class="text-xs text-blue-600 hover:underline">ລາຍງານ →</a>
                    </div>
                    <?php if (!empty($recent_bookings)): ?>
                        <div class="space-y-3">
                            <?php foreach ($recent_bookings as $b):
                                $sc = match($b['Status_booking']) {
                                    'Confirmed'  => ['bg'=>'bg-green-100',   'text'=>'text-green-700',   'label'=>'ຢືນຢັນ'],
                                    'Completed'  => ['bg'=>'bg-emerald-100', 'text'=>'text-emerald-700', 'label'=>'ສຳເລັດ'],
                                    'Cancelled'  => ['bg'=>'bg-red-100',     'text'=>'text-red-700',     'label'=>'ຍົກເລີກ'],
                                    'No_Show'    => ['bg'=>'bg-orange-100',  'text'=>'text-orange-700',  'label'=>'ບໍ່ໄດ້ມາ'],
                                    'Pending'    => ['bg'=>'bg-yellow-100',  'text'=>'text-yellow-700',  'label'=>'ລໍຖ້າ'],
                                    default      => ['bg'=>'bg-blue-100',    'text'=>'text-blue-700',    'label'=>'ຍັງບໍ່ຈ່າຍ'],
                                };
                            ?>
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-gradient-to-br from-green-400 to-blue-500 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                        <?= strtoupper(substr($b['customer_name'], 0, 1)) ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($b['customer_name']) ?></p>
                                        <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($b['VN_Name']) ?> · <?= date('d/m/Y', strtotime($b['Booking_date'])) ?></p>
                                    </div>
                                    <span class="text-xs font-bold px-2 py-0.5 rounded-full flex-shrink-0 <?= $sc['bg'] ?> <?= $sc['text'] ?>">
                                        <?= $sc['label'] ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-400 text-sm text-center py-6">ຍັງບໍ່ມີການຈອງ</p>
                    <?php endif; ?>
                </div>

            </div>

        </main>
    </div>
</div>
</body>
</html>