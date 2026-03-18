<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id      = $_SESSION['ca_id'];
$owner_name = $_SESSION['user_name'];

// Active package
try {
    $stmt = $pdo->prepare("
        SELECT bp.*, pr.Package_duration, pr.Price
        FROM package bp
        INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID
        WHERE bp.CA_ID = ? AND bp.Status_Package = 'Active' AND bp.End_time > NOW()
        AND bp.Start_time <= NOW()
        ORDER BY bp.End_time DESC LIMIT 1
    ");
    $stmt->execute([$ca_id]);
    $active_package = $stmt->fetch();
} catch (PDOException $e) { $active_package = null; }

// Pending package
try {
    $stmt = $pdo->prepare("
        SELECT bp.*, pr.Package_duration, pr.Price
        FROM package bp
        INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID
        WHERE bp.CA_ID = ? AND bp.Status_Package = 'Pending'
        ORDER BY bp.Package_date DESC LIMIT 1
    ");
    $stmt->execute([$ca_id]);
    $pending_package = $stmt->fetch();
} catch (PDOException $e) { $pending_package = null; }

// Rejected package
try {
    $stmt = $pdo->prepare("
        SELECT bp.*, pr.Package_duration, pr.Price
        FROM package bp
        INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID
        WHERE bp.CA_ID = ? AND bp.Status_Package = 'Rejected'
        ORDER BY bp.Package_date DESC LIMIT 1
    ");
    $stmt->execute([$ca_id]);
    $rejected_package = $stmt->fetch();
} catch (PDOException $e) { $rejected_package = null; }

// Venue
try {
    $stmt = $pdo->prepare("SELECT * FROM Venue_data WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $venue = $stmt->fetch();
} catch (PDOException $e) { $venue = null; }

// Stats
$stats = ['total'=>0,'pending'=>0,'confirmed'=>0,'today'=>0,'revenue'=>0];
if ($venue) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                -- FIX: include Unpaid in pending count (awaiting action)
                SUM(CASE WHEN b.Status_booking IN ('Pending','Unpaid') THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN b.Status_booking = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed,
                SUM(CASE WHEN DATE(b.Booking_date) = CURDATE() THEN 1 ELSE 0 END) AS today
            FROM booking b
            INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
            INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
            WHERE c.VN_ID = ?
        ");
        $stmt->execute([$venue['VN_ID']]);
        $stats = $stmt->fetch();

        // FIX: Revenue only from Confirmed (Pending has slip, Unpaid has no slip)
        $stmt = $pdo->prepare("
            SELECT SUM(
                TIMESTAMPDIFF(HOUR, bd.Start_time, bd.End_time) *
                CAST(REPLACE(REPLACE(v.Price_per_hour, ',', ''), ' ', '') AS UNSIGNED) * 0.30
            ) AS revenue
            FROM booking b
            INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
            INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
            INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
            WHERE c.VN_ID = ? AND b.Status_booking = 'Confirmed'
        ");
        $stmt->execute([$venue['VN_ID']]);
        $rev = $stmt->fetch();
        $stats['revenue'] = $rev['revenue'] ?? 0;
    } catch (PDOException $e) {}
}

// Recent bookings
$recent_bookings = [];
if ($venue) {
    try {
        $stmt = $pdo->prepare("
            SELECT b.Book_ID, b.Status_booking, b.Booking_date, b.Slip_payment,
                bd.Start_time, bd.End_time, c.COURT_Name,
                cu.Name AS customer_name, cu.Phone AS customer_phone
            FROM booking b
            INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
            INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
            INNER JOIN customer cu ON b.C_ID = cu.C_ID
            WHERE c.VN_ID = ?
            AND b.Status_booking != 'Unpaid'
            ORDER BY b.Booking_date DESC LIMIT 5
        ");
        $stmt->execute([$venue['VN_ID']]);
        $recent_bookings = $stmt->fetchAll();
    } catch (PDOException $e) {}
}

$is_locked = !$active_package;
$days_left = $active_package ? ceil((strtotime($active_package['End_time']) - time()) / 86400) : 0;
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ໜ້າຫຼັກ - Badminton Booking Court</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .locked-overlay { backdrop-filter: blur(4px); background: rgba(255,255,255,0.85); }
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-40">
            <div>
                <h1 class="text-xl font-bold text-gray-800">ໜ້າຫຼັກ</h1>
                <p class="text-sm text-gray-500">ຍິນດີຕ້ອນຮັບ, <?= htmlspecialchars($owner_name) ?>!</p>
            </div>
            <div class="flex items-center gap-3">
                <?php if ($active_package): ?>
                    <div class="hidden md:flex items-center gap-2 bg-green-50 border border-green-200 px-3 py-2 rounded-lg text-sm">
                        <i class="fas fa-check-circle text-green-500"></i>
                        <span class="text-green-700 font-medium">ແພັກເກດໃຊ້ໄດ້ຮອດ <?= date('d/m/Y', strtotime($active_package['End_time'])) ?></span>
                    </div>
                <?php elseif ($pending_package): ?>
                    <div class="hidden md:flex items-center gap-2 bg-yellow-50 border border-yellow-200 px-3 py-2 rounded-lg text-sm">
                        <i class="fas fa-clock text-yellow-500"></i>
                        <span class="text-yellow-700 font-medium">ແພັກເກດລໍຖ້າການອະນຸມັດ</span>
                    </div>
                <?php else: ?>
                    <a href="/Badminton_court_Booking/owner/package_rental/index.php"
                        class="bg-yellow-400 hover:bg-yellow-500 text-yellow-900 font-bold px-4 py-2 rounded-lg text-sm transition">
                        <i class="fas fa-box mr-1"></i>ຊື້ແພັກເກດ
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-1 p-6">

            <?php if ($is_locked): ?>
                <div class="mb-6">
                    <?php if ($pending_package): ?>
                        <div class="bg-yellow-50 border border-yellow-300 rounded-2xl p-6 flex items-start gap-4">
                            <div class="bg-yellow-100 p-3 rounded-full flex-shrink-0">
                                <i class="fas fa-clock text-yellow-500 text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-yellow-800 mb-1">ລໍຖ້າການອະນຸມັດການຊຳລະເງິນ</h2>
                                <p class="text-yellow-700 text-sm mb-2">ໃບຮັບເງິນຂອງທ່ານຖືກສົ່ງແລ້ວ. ແອດມິນຈະອະນຸມັດໃນໄວໆນີ້.</p>
                                <div class="bg-white border border-yellow-200 rounded-lg px-4 py-2 inline-block text-sm">
                                    <span class="text-gray-500">ແພັກເກດ:</span>
                                    <span class="font-bold text-gray-800 ml-1"><?= htmlspecialchars($pending_package['Package_duration']) ?></span>
                                    <span class="mx-2 text-gray-300">|</span>
                                    <span class="text-gray-500">ລາຄາ:</span>
                                    <span class="font-bold text-green-600 ml-1">₭<?= number_format($pending_package['Price']) ?></span>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($rejected_package): ?>
                        <div class="bg-red-50 border border-red-300 rounded-2xl p-6 flex items-start gap-4">
                            <div class="bg-red-100 p-3 rounded-full flex-shrink-0">
                                <i class="fas fa-times-circle text-red-500 text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-red-800 mb-1">ການຊຳລະເງິນຖືກປະຕິເສດ</h2>
                                <p class="text-red-600 text-sm mb-3">ໃບຮັບເງິນຂອງທ່ານຖືກປະຕິເສດ. ກະລຸນາສົ່ງໃໝ່ດ້ວຍໃບຮັບເງິນທີ່ຖືກຕ້ອງ.</p>
                                <a href="/Badminton_court_Booking/owner/package_rental/index.php"
                                   class="inline-block bg-red-600 hover:bg-red-700 text-white font-bold px-5 py-2 rounded-xl transition text-sm">
                                    <i class="fas fa-redo mr-1"></i>ສົ່ງໃໝ່
                                </a>
                            </div>
                        </div>

                    <?php else: ?>
                        <div class="bg-white border-2 border-dashed border-gray-300 rounded-2xl p-10 text-center">
                            <i class="fas fa-lock text-5xl text-gray-300 mb-4 block"></i>
                            <h2 class="text-2xl font-bold text-gray-700 mb-2">ບັນຊີຂອງທ່ານຍັງບໍ່ໄດ້ເປີດໃຊ້ງານ</h2>
                            <p class="text-gray-500 mb-6 max-w-md mx-auto">ເພື່ອເລີ່ມຕັ້ງສະຖານທີ່ ແລະ ຮັບການຈອງ ທ່ານຕ້ອງຊື້ແພັກເກດກ່ອນ.</p>
                            <a href="/Badminton_court_Booking/owner/package_rental/index.php"
                                class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold px-8 py-4 rounded-xl transition shadow-lg text-lg">
                                <i class="fas fa-box mr-2"></i>ເບິ່ງແພັກເກດ ແລະ ເປີດໃຊ້ງານ
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Blurred preview -->
                <div class="relative">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6 select-none pointer-events-none">
                        <?php foreach ([
                            ['icon'=>'fa-calendar-check','label'=>'ການຈອງທັງໝົດ','color'=>'blue'],
                            ['icon'=>'fa-clock',         'label'=>'ລໍຖ້າ',         'color'=>'yellow'],
                            ['icon'=>'fa-check-circle',  'label'=>'ຢືນຢັນແລ້ວ',   'color'=>'green'],
                            ['icon'=>'fa-coins',         'label'=>'ລາຍຮັບ (30%)', 'color'=>'purple'],
                        ] as $card): ?>
                            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 blur-sm">
                                <div class="bg-<?= $card['color'] ?>-100 p-3 rounded-xl w-fit mb-3">
                                    <i class="fas <?= $card['icon'] ?> text-<?= $card['color'] ?>-500 text-xl"></i>
                                </div>
                                <p class="text-2xl font-bold text-gray-800">--</p>
                                <p class="text-sm text-gray-500 mt-1"><?= $card['label'] ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="locked-overlay absolute inset-0 rounded-2xl flex items-center justify-center">
                        <p class="text-gray-400 font-medium text-lg"><i class="fas fa-lock mr-2"></i>ເປີດໃຊ້ງານບັນຊີເພື່ອເບິ່ງສະຖິຕິ</p>
                    </div>
                </div>

            <?php else: ?>

                <!-- Venue setup prompt -->
                <?php if (!$venue): ?>
                    <div class="mb-6 bg-blue-50 border border-blue-200 rounded-2xl p-6 flex items-start gap-4">
                        <div class="bg-blue-100 p-3 rounded-full flex-shrink-0">
                            <i class="fas fa-store text-blue-500 text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <h2 class="text-lg font-bold text-blue-800 mb-1">ຕັ້ງຄ່າສະຖານທີ່ຂອງທ່ານ</h2>
                            <p class="text-blue-600 text-sm mb-3">ແພັກເກດຂອງທ່ານໃຊ້ງານໄດ້ແລ້ວ! ຕອນນີ້ຕັ້ງຄ່າສະຖານທີ່ເພື່ອໃຫ້ລູກຄ້າຊອກຫາ ແລະ ຈອງໄດ້.</p>
                            <a href="/Badminton_court_Booking/owner/manage_court/index.php"
                                class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-2 rounded-lg transition text-sm">
                                <i class="fas fa-plus mr-1"></i>ເພີ່ມສະຖານທີ່
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Package expiry warning -->
                <?php if ($days_left <= 14 && $days_left > 0): ?>
                    <div class="mb-6 bg-orange-50 border border-orange-200 rounded-2xl p-4 flex items-center gap-3">
                        <i class="fas fa-exclamation-triangle text-orange-500 text-xl"></i>
                        <p class="text-orange-700 text-sm font-medium">
                            ແພັກເກດຂອງທ່ານຈະໝົດອາຍຸໃນ <strong><?= $days_left ?> ວັນ</strong> (<?= date('d/m/Y', strtotime($active_package['End_time'])) ?>).
                            <a href="/Badminton_court_Booking/owner/package_rental/index.php" class="underline ml-1">ຕໍ່ອາຍຸດຽວນີ້</a>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                    <?php foreach ([
                        ['icon'=>'fa-calendar-check','label'=>'ການຈອງທັງໝົດ','value'=>$stats['total']??0,    'color'=>'blue',  'fmt'=>false],
                        ['icon'=>'fa-clock',         'label'=>'ລໍຖ້າ',         'value'=>$stats['pending']??0,  'color'=>'yellow','fmt'=>false],
                        ['icon'=>'fa-check-circle',  'label'=>'ຢືນຢັນແລ້ວ',   'value'=>$stats['confirmed']??0,'color'=>'green', 'fmt'=>false],
                        ['icon'=>'fa-coins',         'label'=>'ລາຍຮັບ (30%)', 'value'=>$stats['revenue']??0,  'color'=>'purple','fmt'=>true,'prefix'=>'₭'],
                    ] as $card): ?>
                        <div class="stat-card bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                            <div class="flex items-center justify-between mb-3">
                                <div class="bg-<?= $card['color'] ?>-100 p-3 rounded-xl">
                                    <i class="fas <?= $card['icon'] ?> text-<?= $card['color'] ?>-500 text-xl"></i>
                                </div>
                                <?php if ($card['label'] === 'ລໍຖ້າ' && ($stats['pending']??0) > 0): ?>
                                    <span class="bg-red-100 text-red-600 text-xs font-bold px-2 py-1 rounded-full">ຕ້ອງດຳເນີນການ</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-2xl font-extrabold text-gray-800">
                                <?= isset($card['prefix']) ? $card['prefix'] : '' ?><?= $card['fmt'] ? number_format($card['value'],0) : $card['value'] ?>
                            </p>
                            <p class="text-sm text-gray-500 mt-1"><?= $card['label'] ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Recent Bookings + Quick Actions -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-bold text-gray-800">ການຈອງລ່າສຸດ</h2>
                            <a href="/Badminton_court_Booking/owner/booking_management/index.php"
                                class="text-blue-600 hover:text-blue-700 text-sm font-medium">ເບິ່ງທັງໝົດ</a>
                        </div>
                        <?php if (!empty($recent_bookings)): ?>
                            <div class="space-y-3">
                                <?php foreach ($recent_bookings as $rb):
                                    $status_color = match($rb['Status_booking']) {
                                        'Confirmed'=>'green','Pending'=>'yellow','Cancelled'=>'red',default=>'gray'
                                    };
                                    $status_label = match($rb['Status_booking']) {
                                        'Confirmed'=>'ຢືນຢັນແລ້ວ','Pending'=>'ລໍຖ້າ','Cancelled'=>'ຍົກເລີກ',default=>$rb['Status_booking']
                                    };
                                ?>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold text-sm">
                                                <?= strtoupper(substr($rb['customer_name'],0,1)) ?>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($rb['customer_name']) ?></p>
                                                <p class="text-xs text-gray-500">
                                                    <?= htmlspecialchars($rb['COURT_Name']) ?> · <?= date('d/m', strtotime($rb['Start_time'])) ?> · <?= date('g:i A', strtotime($rb['Start_time'])) ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="bg-<?= $status_color ?>-100 text-<?= $status_color ?>-700 text-xs font-bold px-2 py-1 rounded-full">
                                                <?= $status_label ?>
                                            </span>
                                            <?php if ($rb['Status_booking'] === 'Pending' && $rb['Slip_payment']): ?>
                                                <a href="/Badminton_court_Booking/owner/booking_management/index.php"
                                                    class="text-xs bg-green-600 text-white px-2 py-1 rounded-lg hover:bg-green-700 transition">ກວດສອບ</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <i class="fas fa-calendar-times text-4xl text-gray-200 mb-3 block"></i>
                                <p class="text-gray-400 text-sm">ຍັງບໍ່ມີການຈອງ</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="space-y-4">
                        <div class="bg-white rounded-2xl shadow-sm p-6">
                            <h2 class="text-lg font-bold text-gray-800 mb-4">ການດຳເນີນການດ່ວນ</h2>
                            <div class="space-y-2">
                                <a href="/Badminton_court_Booking/owner/manage_court/index.php"
                                    class="flex items-center gap-3 p-3 rounded-xl hover:bg-green-50 hover:text-green-700 text-gray-700 transition text-sm font-medium">
                                    <i class="fas fa-store w-5 text-green-500"></i><?= $venue ? 'ແກ້ໄຂສະຖານທີ່' : 'ເພີ່ມສະຖານທີ່' ?>
                                </a>
                                <a href="/Badminton_court_Booking/owner/booking_management/index.php"
                                    class="flex items-center gap-3 p-3 rounded-xl hover:bg-blue-50 hover:text-blue-700 text-gray-700 transition text-sm font-medium">
                                    <i class="fas fa-calendar-check w-5 text-blue-500"></i>ຈັດການການຈອງ
                                    <?php if (($stats['pending']??0) > 0): ?>
                                        <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2"><?= $stats['pending'] ?></span>
                                    <?php endif; ?>
                                </a>
                                <a href="/Badminton_court_Booking/owner/facilities/index.php"
                                    class="flex items-center gap-3 p-3 rounded-xl hover:bg-purple-50 hover:text-purple-700 text-gray-700 transition text-sm font-medium">
                                    <i class="fas fa-concierge-bell w-5 text-purple-500"></i>ຈັດການສິ່ງອຳນວຍຄວາມສະດວກ
                                </a>
                                <a href="/Badminton_court_Booking/owner/reports/index.php"
                                    class="flex items-center gap-3 p-3 rounded-xl hover:bg-orange-50 hover:text-orange-700 text-gray-700 transition text-sm font-medium">
                                    <i class="fas fa-chart-bar w-5 text-orange-500"></i>ເບິ່ງລາຍງານ
                                </a>
                            </div>
                        </div>

                        <?php if ($active_package): ?>
                            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-6 text-white">
                                <div class="flex items-center gap-2 mb-3">
                                    <i class="fas fa-box text-green-200"></i>
                                    <h3 class="font-bold">ແພັກເກດທີ່ໃຊ້ຢູ່</h3>
                                </div>
                                <p class="text-2xl font-extrabold mb-1"><?= htmlspecialchars($active_package['Package_duration']) ?></p>
                                <p class="text-green-200 text-sm mb-3">ໝົດອາຍຸ: <?= date('d/m/Y', strtotime($active_package['End_time'])) ?></p>
                                <div class="bg-white bg-opacity-20 rounded-lg p-2 text-center text-sm">
                                    <span class="font-bold"><?= $days_left ?> ວັນ</span> ທີ່ຍັງເຫຼືອ
                                </div>
                                <?php if ($days_left <= 30): ?>
                                    <a href="/Badminton_court_Booking/owner/package_rental/index.php"
                                        class="mt-3 block text-center bg-white text-green-600 font-bold py-2 rounded-lg text-sm hover:bg-green-50 transition">
                                        ຕໍ່ອາຍຸແພັກເກດ
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>
        </main>
    </div>
</div>
</body>
</html>