<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id = $_SESSION['ca_id'];

// Get venue
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
$search = trim($_GET['search'] ?? '');

// Fetch ONLY customers who have booked this venue
try {
    $sql = "
        SELECT
            cu.C_ID, cu.Name, cu.Phone, cu.Email, cu.Gender,
            COUNT(DISTINCT b.Book_ID) AS total_bookings,
            SUM(CASE WHEN b.Status_booking = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN b.Status_booking = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN b.Status_booking IN ('Pending','Unpaid') THEN 1 ELSE 0 END) AS pending,
            MAX(b.Booking_date) AS last_booking
        FROM customer cu
        INNER JOIN booking b ON cu.C_ID = b.C_ID
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
        WHERE c.VN_ID = ?
    ";
    $params = [$vn_id];
    if (!empty($search)) {
        $sql .= " AND (cu.Name LIKE ? OR cu.Phone LIKE ? OR cu.Email LIKE ?)";
        $t = "%{$search}%";
        $params = array_merge($params, [$t,$t,$t]);
    }
    $sql .= " GROUP BY cu.C_ID ORDER BY last_booking DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll();
} catch (PDOException $e) { $customers = []; }

// Modal: booking history for one customer at this venue
$modal_customer = null;
$modal_bookings = [];
if (isset($_GET['view'])) {
    $view_id = intval($_GET['view']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM customer WHERE C_ID = ?");
        $stmt->execute([$view_id]);
        $modal_customer = $stmt->fetch();

        if ($modal_customer) {
            $stmt = $pdo->prepare("
                SELECT b.Book_ID, b.Booking_date, b.Status_booking,
                       bd.Start_time, bd.End_time, c.COURT_Name
                FROM booking b
                INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
                INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
                WHERE b.C_ID = ? AND c.VN_ID = ?
                ORDER BY b.Booking_date DESC LIMIT 20
            ");
            $stmt->execute([$view_id, $vn_id]);
            $all_rows = $stmt->fetchAll();
            $grouped  = [];
            foreach ($all_rows as $row) {
                $id = $row['Book_ID'];
                if (!isset($grouped[$id])) { $grouped[$id] = $row; $grouped[$id]['slots'] = []; }
                $grouped[$id]['slots'][] = ['court'=>$row['COURT_Name'],'start'=>$row['Start_time'],'end'=>$row['End_time']];
            }
            $modal_bookings = $grouped;
        }
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລູກຄ້າ - Badminton Booking Court</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .customer-card { transition: all 0.3s ease; }
        .customer-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        .detail-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:100; align-items:center; justify-content:center; }
        .detail-modal.open { display:flex; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">ລູກຄ້າ</h1>
                    <p class="text-sm text-gray-500">ລູກຄ້າທີ່ເຄີຍຈອງ <?= htmlspecialchars($venue['VN_Name']) ?></p>
                </div>
                <div class="bg-yellow-50 border border-yellow-200 px-4 py-2 rounded-xl text-sm">
                    <span class="text-yellow-700 font-bold"><?= count($customers) ?></span>
                    <span class="text-yellow-600 ml-1">ລູກຄ້າ</span>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6">

            <!-- Search -->
            <form method="GET" class="flex gap-2 mb-6">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="ຊອກຫາຕາມຊື່, ໂທ ຫຼື ອີເມລ໌..."
                       class="flex-1 border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500 transition">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                    <i class="fas fa-search"></i>
                </button>
                <?php if ($search): ?>
                    <a href="?" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2.5 rounded-xl text-sm font-semibold transition">ລ້າງ</a>
                <?php endif; ?>
            </form>

            <!-- Customers List -->
            <?php if (!empty($customers)): ?>
                <div class="space-y-3">
                    <?php foreach ($customers as $cu): ?>
                        <div class="customer-card bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                            <div class="flex flex-col md:flex-row justify-between gap-4">
                                <!-- Info -->
                                <div class="flex items-start gap-4">
                                    <div class="w-12 h-12 bg-gradient-to-br from-yellow-400 to-orange-500 rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                                        <?= strtoupper(substr($cu['Name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-gray-800"><?= htmlspecialchars($cu['Name']) ?></h3>
                                        <p class="text-sm text-gray-500"><i class="fas fa-phone mr-1 text-green-400"></i><?= htmlspecialchars($cu['Phone']) ?></p>
                                        <p class="text-sm text-gray-500"><i class="fas fa-envelope mr-1 text-blue-400"></i><?= htmlspecialchars($cu['Email']) ?></p>
                                        <?php if ($cu['Gender']): ?>
                                            <p class="text-sm text-gray-500"><i class="fas fa-venus-mars mr-1 text-purple-400"></i>
                                                <?= $cu['Gender'] === 'Male' ? 'ຊາຍ' : 'ຍິງ' ?>
                                            </p>
                                        <?php endif; ?>
                                        <p class="text-xs text-gray-400 mt-1">
                                            <i class="fas fa-clock mr-1"></i>ຈອງຫຼ້າສຸດ: <?= date('d/m/Y', strtotime($cu['last_booking'])) ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Booking Stats -->
                                <div class="flex gap-3 text-center flex-shrink-0">
                                    <?php foreach ([
                                        ['label'=>'ທັງໝົດ',    'value'=>$cu['total_bookings'],'color'=>'blue'],
                                        ['label'=>'ຢືນຢັນ',    'value'=>$cu['confirmed'],     'color'=>'green'],
                                        ['label'=>'ຍົກເລີກ',   'value'=>$cu['cancelled'],     'color'=>'red'],
                                        ['label'=>'ລໍຖ້າ',     'value'=>$cu['pending'],       'color'=>'yellow'],
                                    ] as $bs): ?>
                                        <div class="bg-<?= $bs['color'] ?>-50 border border-<?= $bs['color'] ?>-100 rounded-xl px-3 py-2 min-w-[55px]">
                                            <p class="text-xl font-extrabold text-<?= $bs['color'] ?>-600"><?= $bs['value'] ?? 0 ?></p>
                                            <p class="text-xs text-<?= $bs['color'] ?>-400"><?= $bs['label'] ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Action -->
                                <div class="flex-shrink-0">
                                    <a href="?<?= $search ? 'search='.urlencode($search).'&' : '' ?>view=<?= $cu['C_ID'] ?>"
                                       class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-4 py-2 rounded-xl text-sm font-semibold transition">
                                        <i class="fas fa-eye mr-1"></i>ເບິ່ງ
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <i class="fas fa-users text-6xl text-gray-200 mb-4 block"></i>
                    <h3 class="text-xl font-bold text-gray-600 mb-2">
                        <?= $search ? 'ບໍ່ພົບລູກຄ້າ' : 'ຍັງບໍ່ມີລູກຄ້າ' ?>
                    </h3>
                    <p class="text-gray-400 text-sm">
                        <?= $search ? 'ບໍ່ມີລູກຄ້າທີ່ກົງກັບການຄົ້ນຫາ.' : 'ລູກຄ້າຈະສະແດງຢູ່ນີ້ເມື່ອມີການຈອງ.' ?>
                    </p>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<!-- Customer Detail Modal -->
<?php if ($modal_customer): ?>
<div class="detail-modal open">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="bg-gradient-to-r from-yellow-400 to-orange-500 p-6 rounded-t-2xl relative">
            <a href="?<?= $search ? 'search='.urlencode($search) : '' ?>"
               class="absolute top-4 right-4 bg-white bg-opacity-20 hover:bg-opacity-30 text-white w-8 h-8 rounded-full flex items-center justify-center transition">
                <i class="fas fa-times"></i>
            </a>
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center text-white text-3xl font-bold">
                    <?= strtoupper(substr($modal_customer['Name'], 0, 1)) ?>
                </div>
                <div class="text-white">
                    <h2 class="text-2xl font-extrabold"><?= htmlspecialchars($modal_customer['Name']) ?></h2>
                    <p class="text-yellow-100 text-sm"><?= htmlspecialchars($modal_customer['Email']) ?></p>
                </div>
            </div>
        </div>
        <div class="p-6">
            <!-- Contact Info -->
            <div class="grid grid-cols-2 gap-3 mb-6 text-sm">
                <?php foreach ([
                    ['icon'=>'fa-phone',     'color'=>'green', 'label'=>'ໂທ',    'value'=>$modal_customer['Phone']],
                    ['icon'=>'fa-envelope',  'color'=>'blue',  'label'=>'ອີເມລ໌','value'=>$modal_customer['Email']],
                    ['icon'=>'fa-venus-mars','color'=>'purple','label'=>'ເພດ',   'value'=>($modal_customer['Gender']==='Male'?'ຊາຍ':($modal_customer['Gender']==='Female'?'ຍິງ':'N/A'))],
                ] as $f): ?>
                    <div class="bg-gray-50 rounded-xl p-3 flex items-center gap-3">
                        <i class="fas <?= $f['icon'] ?> text-<?= $f['color'] ?>-400 w-4"></i>
                        <div>
                            <p class="text-xs text-gray-400"><?= $f['label'] ?></p>
                            <p class="font-semibold text-gray-700"><?= htmlspecialchars($f['value']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Booking History at this venue -->
            <div>
                <h3 class="font-bold text-gray-800 mb-3">
                    <i class="fas fa-calendar-check text-blue-500 mr-2"></i>ປະຫວັດການຈອງທີ່ <?= htmlspecialchars($venue['VN_Name']) ?>
                </h3>
                <?php if (!empty($modal_bookings)): ?>
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        <?php foreach ($modal_bookings as $bk):
                            $bc = match($bk['Status_booking']) { 'Confirmed'=>'green','Cancelled'=>'red', default=>'yellow' };
                            $bl = match($bk['Status_booking']) { 'Confirmed'=>'ຢືນຢັນ','Cancelled'=>'ຍົກເລີກ',default=>'ລໍຖ້າ' };
                        ?>
                            <div class="bg-gray-50 rounded-xl px-4 py-3 text-sm">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="bg-<?= $bc ?>-100 text-<?= $bc ?>-700 text-xs font-bold px-2 py-0.5 rounded-full"><?= $bl ?></span>
                                    <span class="text-xs text-gray-400">#<?= $bk['Book_ID'] ?> · <?= date('d/m/Y', strtotime($bk['Booking_date'])) ?></span>
                                </div>
                                <?php foreach ($bk['slots'] as $slot): ?>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        <i class="fas fa-table-tennis mr-1 text-green-400"></i>
                                        <?= htmlspecialchars($slot['court']) ?> · <?= date('d/m/Y', strtotime($slot['start'])) ?> · <?= date('H:i', strtotime($slot['start'])) ?>–<?= date('H:i', strtotime($slot['end'])) ?>
                                    </p>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 bg-gray-50 rounded-xl">
                        <p class="text-gray-400 text-sm">ບໍ່ມີປະຫວັດການຈອງ</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
</body>
</html>