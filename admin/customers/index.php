<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$error   = '';
$success = '';
$filter  = $_GET['filter'] ?? 'all';
$search  = trim($_GET['search'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $c_id   = intval($_POST['c_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($c_id && in_array($action, ['ban','unban'])) {
        try {
            $new_status = $action === 'ban' ? 'Banned' : 'Active';
            $pdo->prepare("UPDATE customer SET Status = ? WHERE C_ID = ?")
                ->execute([$new_status, $c_id]);
            $success = $action === 'ban' ? 'Customer account has been banned.' : 'Customer account has been reinstated.';
        } catch (PDOException $e) {
            $error = 'Action failed. Please try again.';
        }
    }
}

function get_customers($pdo, $filter, $search) {
    try {
        $where  = [];
        $params = [];
        if ($filter === 'active')     $where[] = "cu.Status = 'Active'";
        elseif ($filter === 'banned') $where[] = "cu.Status = 'Banned'";
        if (!empty($search)) {
            $where[] = "(cu.Name LIKE ? OR cu.Email LIKE ? OR cu.Phone LIKE ? OR cu.Username LIKE ?)";
            $t = "%{$search}%";
            $params = array_merge($params, [$t,$t,$t,$t]);
        }
        $sql = "
            SELECT cu.*,
                COUNT(DISTINCT b.Book_ID) AS total_bookings,
                SUM(CASE WHEN b.Status_booking = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
                SUM(CASE WHEN b.Status_booking = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings,
                SUM(CASE WHEN b.Status_booking IN ('Pending','Unpaid') THEN 1 ELSE 0 END) AS pending_bookings
            FROM customer cu
            LEFT JOIN booking b ON cu.C_ID = b.C_ID
        ";
        if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " GROUP BY cu.C_ID ORDER BY cu.C_ID DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function count_customers($pdo, $filter) {
    try {
        $where = match($filter) { 'active' => "Status = 'Active'", 'banned' => "Status = 'Banned'", default => '1=1' };
        return (int)$pdo->query("SELECT COUNT(*) FROM customer WHERE $where")->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

$modal_customer      = null;
$modal_bookings      = [];
$modal_cancellations = [];

if (isset($_GET['view'])) {
    $view_id = intval($_GET['view']);
    try {
        $stmt = $pdo->prepare("
            SELECT cu.*,
                COUNT(DISTINCT b.Book_ID) AS total_bookings,
                SUM(CASE WHEN b.Status_booking = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
                SUM(CASE WHEN b.Status_booking = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings,
                SUM(CASE WHEN b.Status_booking IN ('Pending','Unpaid') THEN 1 ELSE 0 END) AS pending_bookings
            FROM customer cu
            LEFT JOIN booking b ON cu.C_ID = b.C_ID
            WHERE cu.C_ID = ?
            GROUP BY cu.C_ID
        ");
        $stmt->execute([$view_id]);
        $modal_customer = $stmt->fetch();

        if ($modal_customer) {
            $stmt = $pdo->prepare("
                SELECT b.Book_ID, b.Booking_date, b.Status_booking,
                       bd.Start_time, bd.End_time, c.COURT_Name, v.VN_Name, v.Price_per_hour
                FROM booking b
                INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
                INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
                INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
                WHERE b.C_ID = ?
                ORDER BY b.Booking_date DESC LIMIT 20
            ");
            $stmt->execute([$view_id]);
            $all_rows = $stmt->fetchAll();
            $grouped  = [];
            foreach ($all_rows as $row) {
                $id = $row['Book_ID'];
                if (!isset($grouped[$id])) { $grouped[$id] = $row; $grouped[$id]['slots'] = []; }
                $grouped[$id]['slots'][] = ['court'=>$row['COURT_Name'],'start'=>$row['Start_time'],'end'=>$row['End_time']];
            }
            $modal_bookings = $grouped;

            $stmt = $pdo->prepare("
                SELECT cb.Comment, b.Book_ID, b.Booking_date, bd.Start_time, v.VN_Name
                FROM cancel_booking cb
                INNER JOIN booking b ON cb.Book_ID = b.Book_ID
                INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
                INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
                INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
                WHERE b.C_ID = ? GROUP BY cb.Book_ID ORDER BY b.Booking_date DESC LIMIT 10
            ");
            $stmt->execute([$view_id]);
            $modal_cancellations = $stmt->fetchAll();
        }
    } catch (PDOException $e) {}
}

$customers = get_customers($pdo, $filter, $search);
$counts    = ['all'=>count_customers($pdo,'all'),'active'=>count_customers($pdo,'active'),'banned'=>count_customers($pdo,'banned')];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - Admin</title>
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
                    <h1 class="text-xl font-bold text-gray-800">Customer Management</h1>
                    <p class="text-sm text-gray-500">View and manage customer accounts</p>
                </div>
                <div class="bg-yellow-50 border border-yellow-200 px-4 py-2 rounded-xl text-sm">
                    <span class="text-yellow-700 font-bold"><?= $counts['all'] ?></span>
                    <span class="text-yellow-600 ml-1">total customers</span>
                </div>
            </div>
        </header>
        <main class="flex-1 p-6">
            <?php if ($error): ?><div class="mb-6 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl flex items-center gap-3"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
            <?php if ($success): ?><div class="mb-6 p-4 bg-green-50 border border-green-300 text-green-700 rounded-xl flex items-center gap-3"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success) ?></span></div><?php endif; ?>

            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <?php foreach ([
                    ['label'=>'All Customers','value'=>$counts['all'],   'color'=>'yellow','icon'=>'fa-users',       'filter'=>'all'],
                    ['label'=>'Active',       'value'=>$counts['active'],'color'=>'green', 'icon'=>'fa-check-circle','filter'=>'active'],
                    ['label'=>'Banned',       'value'=>$counts['banned'],'color'=>'red',   'icon'=>'fa-ban',         'filter'=>'banned'],
                ] as $sc): ?>
                    <a href="?filter=<?= $sc['filter'] ?>"
                       class="bg-white rounded-2xl p-5 shadow-sm border-2 <?= $filter===$sc['filter'] ? 'border-'.$sc['color'].'-400' : 'border-transparent' ?> hover:shadow-md transition block">
                        <div class="bg-<?= $sc['color'] ?>-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                            <i class="fas <?= $sc['icon'] ?> text-<?= $sc['color'] ?>-500"></i>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-800"><?= $sc['value'] ?></p>
                        <p class="text-xs text-gray-500 mt-0.5"><?= $sc['label'] ?></p>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Search + Filter -->
            <div class="flex flex-col md:flex-row gap-3 mb-6">
                <form method="GET" class="flex gap-2 flex-1">
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                           placeholder="Search by name, email, phone or username..."
                           class="flex-1 border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500 transition">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-sm font-semibold transition"><i class="fas fa-search"></i></button>
                    <?php if ($search): ?><a href="?filter=<?= $filter ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2.5 rounded-xl text-sm font-semibold transition">Clear</a><?php endif; ?>
                </form>
                <div class="flex gap-2">
                    <?php foreach (['all'=>'All','active'=>'Active','banned'=>'Banned'] as $key => $label): ?>
                        <a href="?filter=<?= $key ?><?= $search ? '&search='.urlencode($search) : '' ?>"
                           class="px-4 py-2.5 rounded-xl font-semibold text-sm transition <?= $filter===$key ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                            <?= $label ?> <span class="ml-1 text-xs <?= $filter===$key ? 'text-blue-200' : 'text-gray-400' ?>">(<?= $counts[$key] ?>)</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Customers List -->
            <?php if (!empty($customers)): ?>
                <div class="space-y-4">
                    <?php foreach ($customers as $customer):
                        $is_banned  = ($customer['Status'] ?? 'Active') === 'Banned';
                        $status_cfg = $is_banned
                            ? ['border'=>'border-red-400',  'badge_bg'=>'bg-red-100',  'badge_text'=>'text-red-700']
                            : ['border'=>'border-green-400','badge_bg'=>'bg-green-100','badge_text'=>'text-green-700'];
                    ?>
                        <div class="customer-card bg-white rounded-2xl shadow-sm border-l-4 <?= $status_cfg['border'] ?> p-5">
                            <div class="flex flex-col md:flex-row justify-between gap-4">
                                <div class="flex items-start gap-4">
                                    <div class="w-12 h-12 bg-gradient-to-br from-yellow-400 to-orange-500 rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                                        <?= strtoupper(substr($customer['Name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2 mb-1">
                                            <!-- FIX: Removed Surname -->
                                            <h3 class="font-bold text-gray-800"><?= htmlspecialchars($customer['Name']) ?></h3>
                                            <span class="<?= $status_cfg['badge_bg'] ?> <?= $status_cfg['badge_text'] ?> text-xs font-bold px-2 py-0.5 rounded-full">
                                                <?= $is_banned ? '🚫 Banned' : '✓ Active' ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-500"><i class="fas fa-at mr-1 text-gray-400"></i><?= htmlspecialchars($customer['Username']) ?></p>
                                        <p class="text-sm text-gray-500"><i class="fas fa-envelope mr-1 text-blue-400"></i><?= htmlspecialchars($customer['Email']) ?></p>
                                        <p class="text-sm text-gray-500"><i class="fas fa-phone mr-1 text-green-400"></i><?= htmlspecialchars($customer['Phone']) ?></p>
                                        <?php if ($customer['Gender']): ?>
                                            <p class="text-sm text-gray-500"><i class="fas fa-venus-mars mr-1 text-purple-400"></i><?= htmlspecialchars($customer['Gender']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex gap-3 text-center flex-shrink-0">
                                    <?php foreach ([
                                        ['label'=>'Total',     'value'=>$customer['total_bookings'],    'color'=>'blue'],
                                        ['label'=>'Confirmed', 'value'=>$customer['confirmed_bookings'],'color'=>'green'],
                                        ['label'=>'Cancelled', 'value'=>$customer['cancelled_bookings'],'color'=>'red'],
                                        ['label'=>'Pending',   'value'=>$customer['pending_bookings'],  'color'=>'yellow'],
                                    ] as $bs): ?>
                                        <div class="bg-<?= $bs['color'] ?>-50 border border-<?= $bs['color'] ?>-100 rounded-xl px-3 py-2 min-w-[60px]">
                                            <p class="text-xl font-extrabold text-<?= $bs['color'] ?>-600"><?= $bs['value'] ?? 0 ?></p>
                                            <p class="text-xs text-<?= $bs['color'] ?>-400"><?= $bs['label'] ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="flex flex-col gap-2 flex-shrink-0">
                                    <a href="?filter=<?= $filter ?><?= $search ? '&search='.urlencode($search) : '' ?>&view=<?= $customer['C_ID'] ?>"
                                       class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-4 py-2 rounded-xl text-sm font-semibold transition text-center">
                                        <i class="fas fa-eye mr-1"></i>View Details
                                    </a>
                                    <?php if ($is_banned): ?>
                                        <form method="POST"><input type="hidden" name="c_id" value="<?= $customer['C_ID'] ?>"><input type="hidden" name="action" value="unban">
                                            <button type="submit" class="w-full bg-green-50 hover:bg-green-100 text-green-600 px-4 py-2 rounded-xl text-sm font-bold transition border border-green-200">
                                                <i class="fas fa-check-circle mr-1"></i>Unban
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" onsubmit="return confirm('Ban <?= htmlspecialchars(addslashes($customer["Name"])) ?>?')">
                                            <input type="hidden" name="c_id" value="<?= $customer['C_ID'] ?>"><input type="hidden" name="action" value="ban">
                                            <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2 rounded-xl text-sm font-bold transition border border-red-200">
                                                <i class="fas fa-ban mr-1"></i>Ban
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <i class="fas fa-users text-6xl text-gray-200 mb-4 block"></i>
                    <h3 class="text-xl font-bold text-gray-600 mb-2">No Customers Found</h3>
                    <p class="text-gray-400 text-sm"><?= $search ? 'No customers match your search.' : 'No customers registered yet.' ?></p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php if ($modal_customer): ?>
<div class="detail-modal open" id="customerModal">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="bg-gradient-to-r from-yellow-400 to-orange-500 p-6 rounded-t-2xl relative">
            <a href="?filter=<?= $filter ?><?= $search ? '&search='.urlencode($search) : '' ?>"
               class="absolute top-4 right-4 bg-white bg-opacity-20 hover:bg-opacity-30 text-white w-8 h-8 rounded-full flex items-center justify-center transition">
                <i class="fas fa-times"></i>
            </a>
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center text-white text-3xl font-bold">
                    <?= strtoupper(substr($modal_customer['Name'], 0, 1)) ?>
                </div>
                <div class="text-white">
                    <!-- FIX: Removed Surname -->
                    <h2 class="text-2xl font-extrabold"><?= htmlspecialchars($modal_customer['Name']) ?></h2>
                    <p class="text-yellow-100 text-sm">@<?= htmlspecialchars($modal_customer['Username']) ?></p>
                    <span class="inline-block mt-1 bg-white bg-opacity-20 text-white text-xs font-bold px-3 py-0.5 rounded-full">
                        <?= htmlspecialchars($modal_customer['Status'] ?? 'Active') ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-3 mb-6 text-sm">
                <?php foreach ([
                    ['icon'=>'fa-envelope',  'color'=>'blue',  'label'=>'Email', 'value'=>$modal_customer['Email']],
                    ['icon'=>'fa-phone',     'color'=>'green', 'label'=>'Phone', 'value'=>$modal_customer['Phone']],
                    ['icon'=>'fa-venus-mars','color'=>'purple','label'=>'Gender','value'=>$modal_customer['Gender'] ?? 'N/A'],
                ] as $f): ?>
                    <div class="bg-gray-50 rounded-xl p-3 flex items-center gap-3">
                        <i class="fas <?= $f['icon'] ?> text-<?= $f['color'] ?>-400 w-4"></i>
                        <div><p class="text-xs text-gray-400"><?= $f['label'] ?></p><p class="font-semibold text-gray-700"><?= htmlspecialchars($f['value']) ?></p></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="grid grid-cols-4 gap-3 mb-6">
                <?php foreach ([
                    ['label'=>'Total',    'value'=>$modal_customer['total_bookings'],    'color'=>'blue'],
                    ['label'=>'Confirmed','value'=>$modal_customer['confirmed_bookings'],'color'=>'green'],
                    ['label'=>'Cancelled','value'=>$modal_customer['cancelled_bookings'],'color'=>'red'],
                    ['label'=>'Pending',  'value'=>$modal_customer['pending_bookings'],  'color'=>'yellow'],
                ] as $bs): ?>
                    <div class="bg-<?= $bs['color'] ?>-50 border border-<?= $bs['color'] ?>-100 rounded-xl p-3 text-center">
                        <p class="text-2xl font-extrabold text-<?= $bs['color'] ?>-600"><?= $bs['value'] ?? 0 ?></p>
                        <p class="text-xs text-<?= $bs['color'] ?>-400 mt-0.5"><?= $bs['label'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Booking History -->
            <div class="mb-6">
                <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-calendar-check text-blue-500 mr-2"></i>Booking History</h3>
                <?php if (!empty($modal_bookings)): ?>
                    <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                        <?php foreach ($modal_bookings as $booking):
                            $bc = match($booking['Status_booking']) { 'Confirmed'=>'green','Cancelled'=>'red', default=>'yellow' };
                        ?>
                            <div class="bg-gray-50 rounded-xl px-4 py-3 text-sm">
                                <div class="flex items-center justify-between mb-1">
                                    <div class="flex items-center gap-2">
                                        <span class="bg-<?= $bc ?>-100 text-<?= $bc ?>-700 text-xs font-bold px-2 py-0.5 rounded-full"><?= $booking['Status_booking'] ?></span>
                                        <span class="font-semibold text-gray-700"><?= htmlspecialchars($booking['VN_Name']) ?></span>
                                    </div>
                                    <span class="text-xs text-gray-400">#<?= $booking['Book_ID'] ?></span>
                                </div>
                                <?php foreach ($booking['slots'] as $slot): ?>
                                    <p class="text-xs text-gray-500 ml-1">
                                        <i class="fas fa-table-tennis mr-1 text-green-400"></i>
                                        <?= htmlspecialchars($slot['court']) ?> · <?= date('M d, Y', strtotime($slot['start'])) ?> · <?= date('g:i A', strtotime($slot['start'])) ?> - <?= date('g:i A', strtotime($slot['end'])) ?>
                                    </p>
                                <?php endforeach; ?>
                                <p class="text-xs text-gray-400 mt-1">Booked: <?= date('M d, Y g:i A', strtotime($booking['Booking_date'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 bg-gray-50 rounded-xl"><p class="text-gray-400 text-sm">No booking history</p></div>
                <?php endif; ?>
            </div>

            <!-- Cancellation History -->
            <div class="mb-6">
                <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-times-circle text-red-500 mr-2"></i>Cancellation History</h3>
                <?php if (!empty($modal_cancellations)): ?>
                    <div class="space-y-2">
                        <?php foreach ($modal_cancellations as $cancel): ?>
                            <div class="bg-red-50 border border-red-100 rounded-xl px-4 py-3 text-sm">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-semibold text-gray-700"><?= htmlspecialchars($cancel['VN_Name']) ?></span>
                                    <span class="text-xs text-gray-400">#<?= $cancel['Book_ID'] ?></span>
                                </div>
                                <p class="text-xs text-gray-500"><i class="fas fa-calendar mr-1 text-red-400"></i><?= date('M d, Y', strtotime($cancel['Start_time'])) ?></p>
                                <?php if ($cancel['Comment']): ?>
                                    <p class="text-xs text-red-600 mt-1 bg-red-100 rounded-lg px-2 py-1"><i class="fas fa-comment mr-1"></i>"<?= htmlspecialchars($cancel['Comment']) ?>"</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 bg-gray-50 rounded-xl"><p class="text-gray-400 text-sm">No cancellations</p></div>
                <?php endif; ?>
            </div>

            <div class="pt-4 border-t border-gray-100">
                <?php if (($modal_customer['Status'] ?? 'Active') === 'Banned'): ?>
                    <form method="POST"><input type="hidden" name="c_id" value="<?= $modal_customer['C_ID'] ?>"><input type="hidden" name="action" value="unban">
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                            <i class="fas fa-check-circle mr-2"></i>Unban This Customer
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" onsubmit="return confirm('Ban <?= htmlspecialchars(addslashes($modal_customer["Name"])) ?>?')">
                        <input type="hidden" name="c_id" value="<?= $modal_customer['C_ID'] ?>"><input type="hidden" name="action" value="ban">
                        <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-600 font-bold py-3 rounded-xl border border-red-200 transition">
                            <i class="fas fa-ban mr-2"></i>Ban This Customer
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
</body>
</html>