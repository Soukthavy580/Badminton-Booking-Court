<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$admin_id = $_SESSION['admin_id'];
$error    = '';
$success  = '';
$filter   = $_GET['filter'] ?? 'active';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rate'])) {
    $rate_id  = intval($_POST['rate_id'] ?? 0);
    $duration = trim($_POST['rate_duration'] ?? '');
    $price    = floatval(preg_replace('/[^0-9.]/', '', $_POST['rate_price'] ?? ''));
    if ($rate_id && $duration && $price > 0) {
        try {
            $pdo->prepare("UPDATE package_rate SET Package_duration=?, Price=?, Is_Popular=?, Is_Best_Value=? WHERE Package_rate_ID=?")
                ->execute([$duration, $price, intval($_POST['is_popular'] ?? 0), intval($_POST['is_best_value'] ?? 0), $rate_id]);
            $success = 'ອັບເດດລາຄາແພັກເກດສຳເລັດ.';
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ: ' . $e->getMessage();
        }
    } else {
        $error = 'ກະລຸນາໃສ່ໄລຍະ ແລະ ລາຄາ.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rate'])) {
    $duration = trim($_POST['rate_duration'] ?? '');
    $price    = floatval(preg_replace('/[^0-9.]/', '', $_POST['rate_price'] ?? ''));
    if ($duration && $price > 0) {
        try {
            $pdo->prepare("INSERT INTO package_rate (Package_duration, Price, Is_Popular, Is_Best_Value) VALUES (?,?,?,?)")
                ->execute([$duration, $price, intval($_POST['is_popular'] ?? 0), intval($_POST['is_best_value'] ?? 0)]);
            $success = 'ເພີ່ມແພລນໃໝ່ສຳເລັດ.';
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ: ' . $e->getMessage();
        }
    } else {
        $error = 'ກະລຸນາໃສ່ໄລຍະ ແລະ ລາຄາ.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rate'])) {
    $rate_id = intval($_POST['rate_id'] ?? 0);
    if ($rate_id) {
        try {
            $in_use = (int)$pdo->query("SELECT COUNT(*) FROM package WHERE Package_rate_ID=$rate_id AND Status_Package IN ('Pending','Active')")->fetchColumn();
            if ($in_use > 0) {
                $error = 'ບໍ່ສາມາດລຶບໄດ້ — ແພລນນີ້ກຳລັງຖືກໃຊ້ງານຢູ່.';
            } else {
                $pdo->prepare("DELETE FROM package_rate WHERE Package_rate_ID=?")->execute([$rate_id]);
                $success = 'ລຶບລາຄາແພັກເກດສຳເລັດ.';
            }
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rejected'])) {
    $pkg_id = intval($_POST['pkg_id'] ?? 0);
    if ($pkg_id) {
        try {
            $pdo->prepare("DELETE FROM package WHERE Package_ID=? AND Status_Package='Rejected'")->execute([$pkg_id]);
            $success = 'ລຶບລາຍການທີ່ຖືກປະຕິເສດສຳເລັດ.';
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['save_rate']) && !isset($_POST['add_rate']) && !isset($_POST['delete_rate']) && !isset($_POST['delete_rejected'])) {
    $pkg_id  = intval($_POST['pkg_id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    if ($pkg_id && in_array($action, ['approve', 'reject'])) {
        try {
            $stmt = $pdo->prepare("SELECT bp.*, pr.Package_duration, co.CA_ID FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID=pr.Package_rate_ID INNER JOIN court_owner co ON bp.CA_ID=co.CA_ID WHERE bp.Package_ID=?");
            $stmt->execute([$pkg_id]);
            $pkg = $stmt->fetch();
            if ($pkg) {
                if ($action === 'approve') {
                    $duration  = $pkg['Package_duration'];
                    $latestEnd = null;
                    try {
                        $es = $pdo->prepare("SELECT MAX(End_time) FROM package WHERE CA_ID=? AND Package_ID!=? AND Status_Package='Active' AND End_time>NOW()");
                        $es->execute([$pkg['CA_ID'], $pkg_id]);
                        $latestEnd = $es->fetchColumn();
                    } catch (PDOException $e) {
                    }
                    $start   = ($latestEnd && $latestEnd > date('Y-m-d H:i:s')) ? $latestEnd : date('Y-m-d H:i:s');
                    $startTs = strtotime($start);
                    if (str_contains($duration, 'Year')) {
                        $end = date('Y-m-d H:i:s', strtotime('+1 year', $startTs));
                    } else {
                        preg_match('/(\d+)/', $duration, $m);
                        $end = date('Y-m-d H:i:s', strtotime('+' . intval($m[1] ?? 1) . ' months', $startTs));
                    }
                    $pdo->prepare("UPDATE package SET Status_Package='Active', Start_time=?, End_time=? WHERE Package_ID=?")
                        ->execute([$start, $end, $pkg_id]);
                    $pdo->prepare("INSERT INTO approve_package (Package_ID, Admin_ID, Action, actioned_at) VALUES (?,?,'Approved',NOW())")
                        ->execute([$pkg_id, $admin_id]);
                    $queued  = $start > date('Y-m-d H:i:s');
                    $success = $queued
                        ? 'ອະນຸມັດ ແລະ ຈັດຄິວສຳເລັດ! ຈະເລີ່ມຫຼັງໝົດໄລຍະເດີມ (' . date('d/m/Y', strtotime($start)) . ').'
                        : 'ອະນຸມັດສຳເລັດ! ແພັກເກດໃຊ້ງານໄດ້ຮອດ ' . date('d/m/Y', strtotime($end)) . '.';
                } else {
                    if (empty($comment)) {
                        $error = 'ກະລຸນາຂຽນເຫດຜົນໄປຫາເຈົ້າຂອງ.';
                    } else {
                        $pdo->prepare("UPDATE package SET Status_Package='Rejected' WHERE Package_ID=?")
                            ->execute([$pkg_id]);
                        $pdo->prepare("INSERT INTO approve_package (Package_ID, Admin_ID, Action, Reject_reason, actioned_at) VALUES (?,?,'Rejected',?,NOW())")
                            ->execute([$pkg_id, $admin_id, $comment]);
                        $success = 'ປະຕິເສດສຳເລັດ. ເຈົ້າຂອງຈະເຫັນໃນການແຈ້ງເຕືອນ.';
                    }
                }
            }
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ: ' . $e->getMessage();
        }
    }
}

function get_packages($pdo, $filter)
{
    $where = match ($filter) {
        'pending'  => "AND bp.Status_Package = 'Pending'",
        'active'   => "AND bp.Status_Package = 'Active' AND bp.End_time > NOW()",
        'expired'  => "AND (bp.Status_Package = 'Expired' OR (bp.Status_Package = 'Active' AND bp.End_time < NOW()))",
        'rejected' => "AND bp.Status_Package = 'Rejected'",
        default    => ''
    };
    $stmt = $pdo->query("
        SELECT bp.*, pr.Package_rate_ID, pr.Package_duration, pr.Price,
               COALESCE(pr.Is_Popular,0) AS rate_popular, COALESCE(pr.Is_Best_Value,0) AS rate_best,
               co.Name AS owner_name, co.Email AS owner_email, co.Phone AS owner_phone,
               (SELECT VN_Name FROM Venue_data WHERE CA_ID=bp.CA_ID AND VN_Status!='Banned' LIMIT 1) AS VN_Name,
               (SELECT VN_ID   FROM Venue_data WHERE CA_ID=bp.CA_ID AND VN_Status!='Banned' LIMIT 1) AS VN_ID,
               (SELECT ap.Reject_reason FROM approve_package ap WHERE ap.Package_ID=bp.Package_ID AND ap.Action='Rejected' ORDER BY ap.actioned_at DESC LIMIT 1) AS reject_reason
        FROM package bp
        INNER JOIN package_rate pr ON bp.Package_rate_ID=pr.Package_rate_ID
        INNER JOIN court_owner co  ON bp.CA_ID=co.CA_ID
        WHERE 1=1 $where ORDER BY bp.Package_date DESC
    ");
    return $stmt->fetchAll();
}
function count_packages($pdo, $filter)
{
    try {
        $where = match ($filter) {
            'pending' => "Status_Package='Pending'",
            'active' => "Status_Package='Active' AND End_time>NOW()",
            'expired' => "Status_Package='Expired' OR (Status_Package='Active' AND End_time<NOW())",
            'rejected' => "Status_Package='Rejected'",
            default => '1=1'
        };
        return (int)$pdo->query("SELECT COUNT(*) FROM package WHERE $where")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

try {
    $packages = get_packages($pdo, $filter);
} catch (PDOException $e) {
    $packages = [];
    $error = 'Query error: ' . $e->getMessage();
}
$counts = ['pending' => count_packages($pdo, 'pending'), 'active' => count_packages($pdo, 'active'), 'expired' => count_packages($pdo, 'expired'), 'rejected' => count_packages($pdo, 'rejected')];
try {
    $pkg_rates = $pdo->query("SELECT * FROM package_rate ORDER BY Price ASC")->fetchAll();
} catch (PDOException $e) {
    $pkg_rates = [];
}
try {
    $revenue = $pdo->query("SELECT COALESCE(SUM(pr.Price),0) FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID=pr.Package_rate_ID WHERE bp.Status_Package='Active'")->fetchColumn();
} catch (PDOException $e) {
    $revenue = 0;
}
?>
<!DOCTYPE html>
<html lang="lo">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ແພັກເກດ - Admin</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .pkg-card {
            transition: all .3s ease
        }

        .pkg-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, .08)
        }

        .slip-modal,
        .reject-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .7);
            z-index: 100;
            align-items: center;
            justify-content: center
        }

        .slip-modal.open,
        .reject-modal.open {
            display: flex
        }

        #ratesChevron {
            transition: transform .2s
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <?php include '../includes/sidebar.php'; ?>
        <div class="flex-1 flex flex-col">
            <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-bold text-gray-800">ຈັດການແພັກເກດ</h1>
                        <p class="text-sm text-gray-500">ກວດສອບ, ອະນຸມັດ ແລະ ຈັດການລາຄາ</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="bg-green-50 border border-green-200 px-4 py-2 rounded-xl text-sm">
                            <span class="text-green-600 font-bold">₭<?= number_format($revenue) ?></span>
                            <span class="text-green-500 ml-1">ລາຍຮັບລວມ</span>
                        </div>
                        <?php if ($counts['pending'] > 0): ?>
                            <div class="flex items-center gap-2 bg-red-50 border border-red-200 px-4 py-2 rounded-xl">
                                <i class="fas fa-exclamation-circle text-red-500"></i>
                                <span class="text-red-700 font-bold text-sm"><?= $counts['pending'] ?> ລໍຖ້າ</span>
                            </div>
                        <?php endif; ?>
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
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <?php foreach (
                        [
                            ['label' => 'ກຳລັງໃຊ້ງານ', 'value' => $counts['active'],  'color' => 'green', 'icon' => 'fa-check-circle', 'filter' => 'active'],
                            ['label' => 'ລໍຖ້າ',      'value' => $counts['pending'], 'color' => 'yellow', 'icon' => 'fa-clock',       'filter' => 'pending'],
                            ['label' => 'ໝົດອາຍຸ',   'value' => $counts['expired'], 'color' => 'gray',  'icon' => 'fa-history',     'filter' => 'expired'],
                            ['label' => 'ຖືກປະຕິເສດ', 'value' => $counts['rejected'], 'color' => 'red',   'icon' => 'fa-times-circle', 'filter' => 'rejected'],
                        ] as $sc
                    ): ?>
                        <a href="?filter=<?= $sc['filter'] ?>"
                            class="bg-white rounded-2xl p-5 shadow-sm border-2 <?= $filter === $sc['filter'] ? 'border-' . $sc['color'] . '-400' : 'border-transparent' ?> hover:shadow-md transition block">
                            <div class="bg-<?= $sc['color'] ?>-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                                <i class="fas <?= $sc['icon'] ?> text-<?= $sc['color'] ?>-500"></i>
                            </div>
                            <p class="text-2xl font-extrabold text-gray-800"><?= $sc['value'] ?></p>
                            <p class="text-xs text-gray-500 mt-0.5"><?= $sc['label'] ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Rate Plans Panel -->
                <div class="bg-white rounded-2xl shadow-sm mb-6 overflow-hidden border border-gray-100">
                    <button onclick="toggleRates()" class="w-full flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition">
                        <div class="flex items-center gap-3">
                            <div class="bg-green-100 w-9 h-9 rounded-xl flex items-center justify-center"><i class="fas fa-box text-green-500"></i></div>
                            <div class="text-left">
                                <h2 class="font-bold text-gray-800">ລາຍການລາຄາແພັກເກດ</h2>
                                <p class="text-xs text-gray-400"><?= count($pkg_rates) ?> ລາຍການ · ຄລິກເພື່ອຈັດການລາຄາ ແລະ ໄລຍະ</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-down text-gray-400" id="ratesChevron"></i>
                    </button>
                    <div id="ratesPanel" class="hidden border-t border-gray-100">
                        <div class="p-6">
                            <?php if (!empty($pkg_rates)): ?>
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-2">
                                    <?php foreach ($pkg_rates as $rate):
                                        $is_pop  = !empty($rate['Is_Popular']);
                                        $is_best = !empty($rate['Is_Best_Value']);
                                        $mo_price = preg_match('/(\d+)/', $rate['Package_duration'], $m) ? round($rate['Price'] / max(1, intval($m[1]))) : null;
                                    ?>
                                        <div class="relative bg-white rounded-2xl border-2 <?= $is_pop ? 'border-blue-500 shadow-lg' : ($is_best ? 'border-yellow-400 shadow-lg' : 'border-gray-200') ?> p-4 flex flex-col items-center text-center pt-6">
                                            <?php if ($is_pop): ?>
                                                <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-blue-600 text-white text-xs font-bold px-3 py-0.5 rounded-full whitespace-nowrap">ນິຍົມ</span>
                                            <?php elseif ($is_best): ?>
                                                <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-yellow-500 text-white text-xs font-bold px-3 py-0.5 rounded-full whitespace-nowrap">ຄຸ້ມທີ່ສຸດ</span>
                                            <?php endif; ?>
                                            <div class="bg-green-100 w-10 h-10 rounded-full flex items-center justify-center mb-2">
                                                <?php if ($is_best): ?><i class="fas fa-crown text-yellow-500 text-sm"></i>
                                                <?php else: ?><i class="fas fa-box text-green-600 text-sm"></i><?php endif; ?>
                                            </div>
                                            <form method="POST" class="w-full space-y-1">
                                                <input type="hidden" name="rate_id" value="<?= $rate['Package_rate_ID'] ?>">
                                                <input type="hidden" name="is_popular" id="pop_pkg_<?= $rate['Package_rate_ID'] ?>" value="<?= $is_pop  ? 1 : 0 ?>">
                                                <input type="hidden" name="is_best_value" id="best_pkg_<?= $rate['Package_rate_ID'] ?>" value="<?= $is_best ? 1 : 0 ?>">
                                                <input type="text" name="rate_duration" value="<?= htmlspecialchars($rate['Package_duration']) ?>"
                                                    class="w-full text-center text-sm font-semibold text-gray-700 bg-transparent border-0 border-b border-transparent hover:border-gray-300 focus:border-blue-400 focus:outline-none rounded px-1 py-0.5 transition">
                                                <input type="text" name="rate_price" value="<?= number_format($rate['Price'], 0, '.', ',') ?>"
                                                    class="w-full text-center text-2xl font-extrabold text-green-600 bg-transparent border-0 border-b border-transparent hover:border-gray-300 focus:border-blue-400 focus:outline-none rounded px-1 py-0.5 transition">

                                                <div class="flex gap-1.5 pt-1">
                                                    <button type="submit" name="save_rate" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-1.5 rounded-xl text-xs transition"><i class="fas fa-save mr-0.5"></i>ບັນທຶກ</button>
                                                    <button type="submit" name="delete_rate" onclick="return confirm('ລຶບແພລນນີ້?')" class="bg-red-50 hover:bg-red-100 text-red-500 border border-red-200 font-bold py-1.5 px-2.5 rounded-xl text-xs transition"><i class="fas fa-trash"></i></button>
                                                </div>
                                                <div class="flex gap-1.5 pt-1">
                                                    <button type="button" onclick="toggleBadge('pop_pkg_<?= $rate['Package_rate_ID'] ?>','best_pkg_<?= $rate['Package_rate_ID'] ?>','popular',this)"
                                                        class="flex-1 text-xs font-semibold py-1 rounded-xl border transition <?= $is_pop ? 'bg-blue-50 text-blue-600 border-blue-200' : 'bg-gray-50 text-gray-400 border-gray-200' ?>">★ ນິຍົມ</button>
                                                    <button type="button" onclick="toggleBadge('best_pkg_<?= $rate['Package_rate_ID'] ?>','pop_pkg_<?= $rate['Package_rate_ID'] ?>','best',this)"
                                                        class="flex-1 text-xs font-semibold py-1 rounded-xl border transition <?= $is_best ? 'bg-yellow-50 text-yellow-600 border-yellow-300' : 'bg-gray-50 text-gray-400 border-gray-200' ?>">♛ ດີທີ່ສຸດ</button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                    <!-- Add new plan -->
                                    <div class="bg-white rounded-2xl border-2 border-dashed border-green-200 p-4 flex flex-col items-center justify-center text-center gap-2 min-h-[260px]">
                                        <div class="bg-green-100 w-10 h-10 rounded-full flex items-center justify-center"><i class="fas fa-plus text-green-600"></i></div>
                                        <p class="text-xs font-bold text-green-600">ແພລນໃໝ່</p>
                                        <form method="POST" class="w-full space-y-1.5">
                                            <input type="hidden" name="is_popular" value="0"><input type="hidden" name="is_best_value" value="0">
                                            <input type="text" name="rate_duration" placeholder="ໄລຍະ (ຕົວຢ່າງ: 3 Months)" class="w-full text-center text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:border-green-400">
                                            <input type="text" name="rate_price" placeholder="ລາຄາ (₭)" class="w-full text-center text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:border-green-400">
                                            <button type="submit" name="add_rate" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 rounded-xl text-xs transition mt-1"><i class="fas fa-plus mr-1"></i>ເພີ່ມແພລນ</button>
                                        </form>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="flex justify-center py-4">
                                    <div class="bg-white rounded-2xl border-2 border-dashed border-green-200 p-6 w-64 text-center space-y-2">
                                        <div class="bg-green-100 w-12 h-12 rounded-full flex items-center justify-center mx-auto"><i class="fas fa-plus text-green-600 text-lg"></i></div>
                                        <p class="text-sm font-bold text-green-600">ເພີ່ມແພລນທຳອິດ</p>
                                        <form method="POST" class="space-y-1.5">
                                            <input type="hidden" name="is_popular" value="0"><input type="hidden" name="is_best_value" value="0">
                                            <input type="text" name="rate_duration" placeholder="ໄລຍະ" class="w-full text-center text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:border-green-400">
                                            <input type="text" name="rate_price" placeholder="ລາຄາ (₭)" class="w-full text-center text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:border-green-400">
                                            <button type="submit" name="add_rate" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 rounded-xl text-xs transition"><i class="fas fa-plus mr-1"></i>ເພີ່ມ</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="flex gap-2 mb-6 flex-wrap">
                    <?php foreach (
                        [
                            'pending' => ['label' => 'ລໍຖ້າກວດສອບ', 'icon' => 'fa-clock',       'color' => 'yellow'],
                            'active'  => ['label' => 'ກຳລັງໃຊ້ງານ',  'icon' => 'fa-check-circle', 'color' => 'green'],
                            'expired' => ['label' => 'ໝົດອາຍຸ',    'icon' => 'fa-history',     'color' => 'gray'],
                            'rejected' => ['label' => 'ຖືກປະຕິເສດ', 'icon' => 'fa-times-circle', 'color' => 'red'],
                        ] as $key => $t
                    ): ?>
                        <a href="?filter=<?= $key ?>"
                            class="px-4 py-2 rounded-xl font-semibold text-sm transition flex items-center gap-2
                              <?= $filter === $key ? 'bg-blue-600 text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                            <i class="fas <?= $t['icon'] ?>"></i><?= $t['label'] ?>
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold <?= $filter === $key ? 'bg-white text-blue-600' : 'bg-' . $t['color'] . '-100 text-' . $t['color'] . '-700' ?>"><?= $counts[$key] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Packages List -->
                <?php if (!empty($packages)): ?>
                    <div class="space-y-4">
                        <?php foreach ($packages as $pkg):
                            $slip_url  = !empty($pkg['Slip_payment']) ? '/Badminton_court_Booking/assets/images/slips/' . basename($pkg['Slip_payment']) : '';
                            $now_str   = date('Y-m-d H:i:s');
                            $is_queued = !empty($pkg['Start_time']) && $pkg['Start_time'] > $now_str && $pkg['Status_Package'] === 'Active';
                            $days_left = $pkg['End_time'] ? ceil((strtotime($pkg['End_time']) - time()) / 86400) : 0;
                            $days_until_start = $is_queued ? ceil((strtotime($pkg['Start_time']) - time()) / 86400) : 0;
                            $status_cfg = match ($pkg['Status_Package']) {
                                'Active'   => $is_queued ? ['border' => 'border-purple-400', 'badge_bg' => 'bg-purple-100', 'badge_text' => 'text-purple-700', 'icon' => 'fa-layer-group'] : ['border' => 'border-green-400', 'badge_bg' => 'bg-green-100', 'badge_text' => 'text-green-700', 'icon' => 'fa-check-circle'],
                                'Rejected' => ['border' => 'border-red-400',   'badge_bg' => 'bg-red-100',   'badge_text' => 'text-red-700',   'icon' => 'fa-times-circle'],
                                'Expired'  => ['border' => 'border-gray-400',  'badge_bg' => 'bg-gray-100',  'badge_text' => 'text-gray-600',  'icon' => 'fa-history'],
                                default    => ['border' => 'border-yellow-400', 'badge_bg' => 'bg-yellow-100', 'badge_text' => 'text-yellow-700', 'icon' => 'fa-clock'],
                            };
                            $display_status = match (true) {
                                $is_queued => 'ຈັດຄິວ',
                                $pkg['Status_Package'] === 'Active'   => 'ກຳລັງໃຊ້ງານ',
                                $pkg['Status_Package'] === 'Rejected' => 'ຖືກປະຕິເສດ',
                                $pkg['Status_Package'] === 'Expired'  => 'ໝົດອາຍຸ',
                                default => 'ລໍຖ້າ',
                            };
                        ?>
                            <div class="pkg-card bg-white rounded-2xl shadow-sm border-l-4 <?= $status_cfg['border'] ?> p-5">
                                <div class="flex flex-col md:flex-row justify-between gap-4">
                                    <div class="flex items-start gap-4 flex-1 min-w-0">
                                        <div class="w-12 h-12 bg-gradient-to-br from-purple-400 to-blue-500 rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                                            <?= strtoupper(substr($pkg['owner_name'], 0, 1)) ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                                <h3 class="font-bold text-gray-800"><?= htmlspecialchars($pkg['owner_name']) ?></h3>
                                                <span class="<?= $status_cfg['badge_bg'] ?> <?= $status_cfg['badge_text'] ?> text-xs font-bold px-2 py-0.5 rounded-full">
                                                    <i class="fas <?= $status_cfg['icon'] ?> mr-1"></i><?= $display_status ?>
                                                </span>
                                            </div>
                                            <p class="text-sm text-gray-500 mb-0.5">
                                                <i class="fas fa-store mr-1 text-blue-400"></i>
                                                <?= !empty($pkg['VN_Name']) ? htmlspecialchars($pkg['VN_Name']) : '<span class="text-gray-400 italic">ຍັງບໍ່ມີສະຖານທີ່</span>' ?>
                                            </p>
                                            <p class="text-sm text-gray-500 mb-0.5"><i class="fas fa-envelope mr-1 text-gray-400"></i><?= htmlspecialchars($pkg['owner_email']) ?></p>
                                            <?php if ($pkg['owner_phone']): ?><p class="text-sm text-gray-500"><i class="fas fa-phone mr-1 text-gray-400"></i><?= htmlspecialchars($pkg['owner_phone']) ?></p><?php endif; ?>
                                            <div class="mt-2"><span class="inline-flex items-center gap-1 bg-gray-50 border border-gray-200 text-gray-600 text-xs font-semibold px-2.5 py-1 rounded-full"><i class="fas fa-clock text-xs text-gray-400"></i><?= htmlspecialchars($pkg['Package_duration']) ?></span></div>
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-end gap-1 text-right flex-shrink-0">
                                        <div class="bg-purple-50 border border-purple-200 rounded-xl px-4 py-2 text-center min-w-[130px]">
                                            <p class="text-sm font-bold text-purple-700"><?= htmlspecialchars($pkg['Package_duration']) ?></p>
                                            <p class="text-2xl font-extrabold text-green-600">₭<?= number_format($pkg['Price']) ?></p>
                                        </div>
                                        <p class="text-xs text-gray-400">ສົ່ງ: <?= date('d/m/Y g:i A', strtotime($pkg['Package_date'])) ?></p>
                                        <?php if ($pkg['Status_Package'] === 'Active' && $pkg['End_time']): ?>
                                            <?php if ($is_queued): ?>
                                                <div class="mt-1 bg-purple-50 border border-purple-200 rounded-xl px-3 py-1.5 text-right">
                                                    <p class="text-xs font-bold text-purple-600"><i class="fas fa-hourglass-half mr-1"></i>ຈັດຄິວ — ເລີ່ມໃນ <?= $days_until_start ?> ວັນ</p>
                                                    <p class="text-xs text-purple-500"><?= date('d/m/Y', strtotime($pkg['Start_time'])) ?> → <?= date('d/m/Y', strtotime($pkg['End_time'])) ?></p>
                                                </div>
                                            <?php else: ?>
                                                <p class="text-xs <?= $days_left <= 14 ? 'text-red-500 font-bold' : 'text-gray-400' ?>">
                                                    <?= $days_left > 0 ? 'ເຫຼືອ ' . $days_left . ' ວັນ' : 'ໝົດອາຍຸ' ?> · ຮອດ <?= date('d/m/Y', strtotime($pkg['End_time'])) ?>
                                                </p>
                                                <?php $total_days = max(1, (strtotime($pkg['End_time']) - strtotime($pkg['Start_time'])) / 86400);
                                                $pct = min(100, max(0, ($days_left / $total_days) * 100)); ?>
                                                <div class="w-32 bg-gray-100 rounded-full h-1.5 mt-1">
                                                    <div class="bg-green-500 h-1.5 rounded-full" style="width:<?= $pct ?>%"></div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($pkg['Status_Package'] === 'Rejected' && !empty($pkg['reject_reason'])): ?>
                                    <div class="mt-3 bg-red-50 border border-red-200 rounded-xl px-4 py-3 flex items-start gap-2">
                                        <i class="fas fa-comment-alt text-red-400 mt-0.5 flex-shrink-0"></i>
                                        <div>
                                            <p class="text-xs font-bold text-red-600 mb-0.5">ເຫດຜົນທີ່ສົ່ງຫາເຈົ້າຂອງ:</p>
                                            <p class="text-sm text-red-700"><?= htmlspecialchars($pkg['reject_reason']) ?></p>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-gray-100">
                                    <?php if ($slip_url): ?>
                                        <button onclick="viewSlip('<?= htmlspecialchars($slip_url) ?>', <?= $pkg['Package_ID'] ?>, '<?= htmlspecialchars($pkg['Status_Package']) ?>')"
                                            class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-2 rounded-xl text-sm font-semibold transition">
                                            <i class="fas fa-receipt mr-1"></i>ເບິ່ງໃບຮັບເງິນ
                                        </button>
                                    <?php else: ?>
                                        <span class="bg-gray-100 text-gray-400 px-3 py-2 rounded-xl text-sm"><i class="fas fa-clock mr-1"></i>ຍັງບໍ່ໄດ້ອັບໂຫລດ</span>
                                    <?php endif; ?>
                                    <?php if ($pkg['Status_Package'] === 'Pending'): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="pkg_id" value="<?= $pkg['Package_ID'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl text-sm font-bold transition shadow">
                                                <i class="fas fa-check mr-1"></i>ອະນຸມັດ
                                            </button>
                                        </form>
                                        <button onclick="openReject(<?= $pkg['Package_ID'] ?>)"
                                            class="bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2 rounded-xl text-sm font-bold transition border border-red-200">
                                            <i class="fas fa-times mr-1"></i>ປະຕິເສດ
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($pkg['Status_Package'] === 'Rejected'): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('ລຶບລາຍການນີ້ຖາວອນ?')">
                                            <input type="hidden" name="pkg_id" value="<?= $pkg['Package_ID'] ?>">
                                            <button type="submit" name="delete_rejected" class="bg-gray-100 hover:bg-red-100 text-gray-500 hover:text-red-600 px-3 py-2 rounded-xl text-sm font-semibold transition border border-gray-200 hover:border-red-200">
                                                <i class="fas fa-trash mr-1"></i>ລຶບລາຍການ
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <i class="fas fa-box text-6xl text-gray-200 mb-4 block"></i>
                        <h3 class="text-xl font-bold text-gray-600 mb-2">
                            <?= match ($filter) {
                                'pending' => 'ບໍ່ມີລາຍການລໍຖ້າ',
                                'active' => 'ບໍ່ມີແພັກເກດທີ່ໃຊ້ງານໄດ້',
                                'expired' => 'ບໍ່ມີແພັກເກດໝົດອາຍຸ',
                                default => 'ບໍ່ມີລາຍການ'
                            } ?>
                        </h3>
                        <p class="text-gray-400 text-sm"><?= $filter === 'pending' ? 'ລາຍການທຸກໃບໄດ້ຮັບການກວດສອບແລ້ວ.' : 'ບໍ່ມີລາຍການໃນໝວດນີ້.' ?></p>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Slip Modal -->
    <div class="slip-modal" id="slipModal">
        <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-lg w-full mx-4 relative">
            <button onclick="closeSlip()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-bold text-gray-800 text-lg mb-4"><i class="fas fa-receipt text-blue-500 mr-2"></i>ໃບຮັບເງິນ — ແພັກເກດ #<span id="modalPkgId"></span></h3>
            <img id="modalSlipImg" src="" alt="Payment Slip" class="w-full max-h-96 object-contain rounded-xl border border-gray-200 mb-4">
            <div class="flex gap-3" id="modalActions"></div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="reject-modal" id="rejectModal">
        <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-md w-full mx-4 relative">
            <button onclick="closeReject()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-bold text-gray-800 text-lg mb-1"><i class="fas fa-times-circle text-red-500 mr-2"></i>ປະຕິເສດແພັກເກດ</h3>
            <p class="text-sm text-gray-500 mb-4">ເຈົ້າຂອງຈະເຫັນເຫດຜົນນີ້ໃນການແຈ້ງເຕືອນ.</p>
            <form method="POST">
                <input type="hidden" name="pkg_id" id="rejectPkgId">
                <input type="hidden" name="action" value="reject">
                <textarea name="comment" rows="4" required placeholder="ຕົວຢ່າງ: ໃບຮັບເງິນບໍ່ຊັດ ກະລຸນາສົ່ງໃໝ່."
                    class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-red-400 transition resize-none text-sm mb-4"></textarea>
                <div class="flex gap-3">
                    <button type="button" onclick="closeReject()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition">ຍົກເລີກ</button>
                    <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl transition"><i class="fas fa-paper-plane mr-1"></i>ສົ່ງ</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleRates() {
            const p = document.getElementById('ratesPanel'),
                c = document.getElementById('ratesChevron');
            p.classList.toggle('hidden');
            c.style.transform = p.classList.contains('hidden') ? '' : 'rotate(180deg)';
        }

        function toggleBadge(tId, oId, type, btn) {
            const t = document.getElementById(tId),
                o = document.getElementById(oId),
                on = t.value === '1';
            t.value = on ? '0' : '1';
            if (!on && o) o.value = '0';
            if (type === 'popular') {
                btn.className = btn.className.replace(/(bg-blue-50 text-blue-600 border-blue-200|bg-gray-50 text-gray-400 border-gray-200)/g, '').trim();
                btn.className += on ? ' bg-gray-50 text-gray-400 border-gray-200' : ' bg-blue-50 text-blue-600 border-blue-200';
            } else {
                btn.className = btn.className.replace(/(bg-yellow-50 text-yellow-600 border-yellow-300|bg-gray-50 text-gray-400 border-gray-200)/g, '').trim();
                btn.className += on ? ' bg-gray-50 text-gray-400 border-gray-200' : ' bg-yellow-50 text-yellow-600 border-yellow-300';
            }
        }

        function viewSlip(url, pkgId, status) {
            document.getElementById('slipModal').classList.add('open');
            document.getElementById('modalSlipImg').src = url;
            document.getElementById('modalPkgId').textContent = pkgId;
            const a = document.getElementById('modalActions');
            if (status === 'Pending') {
                a.innerHTML = `<form method="POST" class="flex-1"><input type="hidden" name="pkg_id" value="${pkgId}"><input type="hidden" name="action" value="approve"><button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition"><i class="fas fa-check mr-2"></i>ອະນຸມັດ</button></form><button onclick="closeSlip();openReject(${pkgId})" class="flex-1 bg-red-50 hover:bg-red-100 text-red-600 font-bold py-3 rounded-xl border border-red-200 transition"><i class="fas fa-times mr-2"></i>ປະຕິເສດ</button>`;
            } else a.innerHTML = '';
        }

        function closeSlip() {
            document.getElementById('slipModal').classList.remove('open');
        }
        document.getElementById('slipModal').addEventListener('click', function(e) {
            if (e.target === this) closeSlip();
        });

        function openReject(id) {
            document.getElementById('rejectPkgId').value = id;
            document.querySelector('#rejectModal textarea').value = '';
            document.getElementById('rejectModal').classList.add('open');
        }

        function closeReject() {
            document.getElementById('rejectModal').classList.remove('open');
        }
        document.getElementById('rejectModal').addEventListener('click', function(e) {
            if (e.target === this) closeReject();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeSlip();
                closeReject();
            }
        });
    </script>
</body>

</html>