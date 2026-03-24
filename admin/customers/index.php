<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

date_default_timezone_set('Asia/Vientiane');

$error   = '';
$success = '';

// Ban / Unban customer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $c_id   = intval($_POST['c_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($c_id && in_array($action, ['ban', 'unban'])) {
        try {
            $new_status = $action === 'ban' ? 'Banned' : 'Active';
            $pdo->prepare("UPDATE customer SET Status = ? WHERE C_ID = ?")
                ->execute([$new_status, $c_id]);
            $success = $action === 'ban'
                ? 'ແບນລູກຄ້າສຳເລັດ.'
                : 'ປົດແບນລູກຄ້າສຳເລັດ.';
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ: ' . $e->getMessage();
        }
    }
}

// Search & Filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

try {
    $where = "WHERE 1=1";
    $params = [];

    if ($search) {
        $where .= " AND (c.Name LIKE ? OR c.Email LIKE ? OR c.Phone LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($filter === 'active')  $where .= " AND c.Status = 'Active'";
    if ($filter === 'banned')  $where .= " AND c.Status = 'Banned'";

    $stmt = $pdo->prepare("
        SELECT c.*,
               COUNT(DISTINCT b.Book_ID) AS total_bookings,
               COUNT(DISTINCT CASE WHEN b.Status_booking IN ('Confirmed','Completed') THEN b.Book_ID END) AS confirmed_bookings
        FROM customer c
        LEFT JOIN booking b ON c.C_ID = b.C_ID
        $where
        GROUP BY c.C_ID
        ORDER BY c.C_ID DESC
    ");
    $stmt->execute($params);
    $customers = $stmt->fetchAll();
} catch (PDOException $e) {
    $customers = [];
    $error = 'ຜິດພາດ: ' . $e->getMessage();
}

// Counts
try {
    $total_all    = $pdo->query("SELECT COUNT(*) FROM customer")->fetchColumn();
    $total_active = $pdo->query("SELECT COUNT(*) FROM customer WHERE Status = 'Active'")->fetchColumn();
    $total_banned = $pdo->query("SELECT COUNT(*) FROM customer WHERE Status = 'Banned'")->fetchColumn();
} catch (PDOException $e) {
    $total_all = $total_active = $total_banned = 0;
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ຈັດການລູກຄ້າ - Admin</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .customer-card { transition: all 0.3s ease; }
        .customer-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">

        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">ຈັດການລູກຄ້າ</h1>
                    <p class="text-sm text-gray-500">ລາຍການລູກຄ້າທັງໝົດໃນລະບົບ</p>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6">

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl flex items-center gap-3">
                    <i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-300 text-green-700 rounded-xl flex items-center gap-3">
                    <i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <?php foreach ([
                    ['label'=>'ທັງໝົດ',    'value'=>$total_all,    'color'=>'blue',  'icon'=>'fa-users'],
                    ['label'=>'ໃຊ້ງານໄດ້', 'value'=>$total_active, 'color'=>'green', 'icon'=>'fa-user-check'],
                    ['label'=>'ຖືກແບນ',    'value'=>$total_banned, 'color'=>'red',   'icon'=>'fa-user-slash'],
                ] as $s): ?>
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                        <div class="bg-<?= $s['color'] ?>-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                            <i class="fas <?= $s['icon'] ?> text-<?= $s['color'] ?>-500"></i>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-800"><?= number_format($s['value']) ?></p>
                        <p class="text-xs text-gray-500 mt-0.5"><?= $s['label'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Search + Filter -->
            <form method="GET" class="flex gap-3 mb-6 flex-wrap">
                <div class="flex-1 min-w-48">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                           placeholder="ຄົ້ນຫາຊື່, ອີເມລ໌, ເບີໂທ..."
                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-400 transition">
                </div>
                <select name="filter" onchange="this.form.submit()"
                        class="border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-400 transition">
                    <option value="all"    <?= $filter==='all'    ? 'selected' : '' ?>>ທັງໝົດ</option>
                    <option value="active" <?= $filter==='active' ? 'selected' : '' ?>>ໃຊ້ງານໄດ້</option>
                    <option value="banned" <?= $filter==='banned' ? 'selected' : '' ?>>ຖືກແບນ</option>
                </select>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition">
                    <i class="fas fa-search mr-1"></i>ຄົ້ນຫາ
                </button>
                <?php if ($search || $filter !== 'all'): ?>
                    <a href="?" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                        <i class="fas fa-times mr-1"></i>ລ້າງ
                    </a>
                <?php endif; ?>
            </form>

            <!-- Customers Table -->
            <?php if (!empty($customers)): ?>
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                        <h2 class="font-bold text-gray-800">ລາຍການລູກຄ້າ</h2>
                        <span class="text-sm text-gray-400"><?= count($customers) ?> ລາຍການ</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 bg-gray-50">
                                    <th class="text-left py-3 px-4 text-xs font-bold text-gray-500 uppercase">#</th>
                                    <th class="text-left py-3 px-4 text-xs font-bold text-gray-500 uppercase">ລູກຄ້າ</th>
                                    <th class="text-left py-3 px-4 text-xs font-bold text-gray-500 uppercase">ອີເມລ໌</th>
                                    <th class="text-left py-3 px-4 text-xs font-bold text-gray-500 uppercase">ເບີໂທ</th>
                                    <th class="text-center py-3 px-4 text-xs font-bold text-gray-500 uppercase">ການຈອງ</th>
                                    <th class="text-center py-3 px-4 text-xs font-bold text-gray-500 uppercase">ສະຖານະ</th>
                                    <th class="text-center py-3 px-4 text-xs font-bold text-gray-500 uppercase">ດຳເນີນການ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php foreach ($customers as $cu):
                                    $is_banned = $cu['Status'] === 'Banned';
                                ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="py-3 px-4 text-gray-400 text-xs"><?= $cu['C_ID'] ?></td>
                                        <td class="py-3 px-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-9 h-9 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                                    <?= strtoupper(substr($cu['Name'], 0, 1)) ?>
                                                </div>
                                                <p class="font-semibold text-gray-800"><?= htmlspecialchars($cu['Name']) ?></p>
                                            </div>
                                        </td>
                                        <td class="py-3 px-4 text-gray-600"><?= htmlspecialchars($cu['Email']) ?></td>
                                        <td class="py-3 px-4 text-gray-600"><?= htmlspecialchars($cu['Phone'] ?? '—') ?></td>
                                        <td class="py-3 px-4 text-center">
                                            <span class="text-gray-700 font-semibold"><?= number_format($cu['total_bookings']) ?></span>
                                            <span class="text-xs text-gray-400 ml-1">(<?= number_format($cu['confirmed_bookings']) ?> ຢືນຢັນ)</span>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <span class="px-2 py-1 rounded-full text-xs font-bold <?= $is_banned ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' ?>">
                                                <?= $is_banned ? 'ຖືກແບນ' : 'ໃຊ້ງານໄດ້' ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <form method="POST" class="inline" onsubmit="return confirm('<?= $is_banned ? 'ປົດແບນ' : 'ແບນ' ?> ລູກຄ້ານີ້?')">
                                                <input type="hidden" name="c_id" value="<?= $cu['C_ID'] ?>">
                                                <input type="hidden" name="action" value="<?= $is_banned ? 'unban' : 'ban' ?>">
                                                <button type="submit"
                                                        class="<?= $is_banned ? 'bg-green-50 hover:bg-green-100 text-green-700 border border-green-200' : 'bg-red-50 hover:bg-red-100 text-red-600 border border-red-200' ?> px-3 py-1.5 rounded-lg text-xs font-bold transition">
                                                    <i class="fas <?= $is_banned ? 'fa-user-check' : 'fa-user-slash' ?> mr-1"></i>
                                                    <?= $is_banned ? 'ປົດແບນ' : 'ແບນ' ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <i class="fas fa-users text-6xl text-gray-200 mb-4 block"></i>
                    <h3 class="text-xl font-bold text-gray-600 mb-2">ບໍ່ພົບລູກຄ້າ</h3>
                    <p class="text-gray-400 text-sm">ລອງຄົ້ນຫາດ້ວຍຄຳອື່ນ.</p>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>
</body>
</html>