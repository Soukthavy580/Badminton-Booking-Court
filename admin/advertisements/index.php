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

// ── RATE MANAGEMENT ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rate'])) {
    $rate_id  = intval($_POST['rate_id'] ?? 0);
    $duration = trim($_POST['rate_duration'] ?? '');
    $price    = floatval(preg_replace('/[^0-9.]/', '', $_POST['rate_price'] ?? ''));
    if ($rate_id && $duration && $price > 0) {
        try {
            $pdo->prepare("UPDATE advertisement_rate SET Duration=?, Price=?, Is_Popular=?, Is_Best_Value=? WHERE AD_Rate_ID=?")
                ->execute([$duration, $price, intval($_POST['is_popular'] ?? 0), intval($_POST['is_best_value'] ?? 0), $rate_id]);
            $success = 'Advertisement rate updated successfully.';
        } catch (PDOException $e) { $error = 'Failed to update rate: ' . $e->getMessage(); }
    } else { $error = 'Duration and price are required.'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rate'])) {
    $duration = trim($_POST['rate_duration'] ?? '');
    $price    = floatval(preg_replace('/[^0-9.]/', '', $_POST['rate_price'] ?? ''));
    if ($duration && $price > 0) {
        try {
            $pdo->prepare("INSERT INTO advertisement_rate (Duration, Price, Is_Popular, Is_Best_Value) VALUES (?,?,?,?)")
                ->execute([$duration, $price, intval($_POST['is_popular'] ?? 0), intval($_POST['is_best_value'] ?? 0)]);
            $success = 'New advertisement rate added.';
        } catch (PDOException $e) { $error = 'Failed to add rate: ' . $e->getMessage(); }
    } else { $error = 'Duration and price are required.'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rate'])) {
    $rate_id = intval($_POST['rate_id'] ?? 0);
    if ($rate_id) {
        try {
            $in_use = (int)$pdo->prepare("SELECT COUNT(*) FROM advertisement WHERE AD_Rate_ID=? AND Status_AD IN ('Pending','Approved','Active')")
                ->execute([$rate_id]) ? $pdo->query("SELECT COUNT(*) FROM advertisement WHERE AD_Rate_ID=$rate_id AND Status_AD IN ('Pending','Approved','Active')")->fetchColumn() : 0;
            if ($in_use > 0) {
                $error = 'Cannot delete a rate that is currently in use by active or pending ads.';
            } else {
                $pdo->prepare("DELETE FROM advertisement_rate WHERE AD_Rate_ID=?")->execute([$rate_id]);
                $success = 'Rate deleted successfully.';
            }
        } catch (PDOException $e) { $error = 'Failed to delete rate.'; }
    }
}

// ── APPROVE / REJECT ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rejected'])) {
    $ad_id = intval($_POST['ad_id'] ?? 0);
    if ($ad_id) {
        try {
            $pdo->prepare("DELETE FROM advertisement WHERE AD_ID=? AND Status_AD='Rejected'")->execute([$ad_id]);
            $success = 'Rejected advertisement deleted.';
        } catch (PDOException $e) { $error = 'Failed to delete.'; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['save_rate']) && !isset($_POST['add_rate']) && !isset($_POST['delete_rate']) && !isset($_POST['delete_rejected'])) {
    $ad_id   = intval($_POST['ad_id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    if ($ad_id && in_array($action, ['approve', 'reject'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT ad.*, r.Duration, v.CA_ID, v.VN_ID
                FROM advertisement ad
                INNER JOIN advertisement_rate r ON ad.AD_Rate_ID = r.AD_Rate_ID
                INNER JOIN Venue_data v          ON ad.VN_ID = v.VN_ID
                WHERE ad.AD_ID = ?
            ");
            $stmt->execute([$ad_id]);
            $ad = $stmt->fetch();

            if ($ad) {
                if ($action === 'approve') {
                    $duration = $ad['Duration'];

                    // ── STACKING: find the latest End_time for this venue's approved/active ads ──
                    $latestEnd = null;
                    try {
                        $es = $pdo->prepare("
                            SELECT MAX(End_time) FROM advertisement
                            WHERE VN_ID = ? AND AD_ID != ? AND Status_AD IN ('Approved','Active') AND End_time > NOW()
                        ");
                        $es->execute([$ad['VN_ID'], $ad_id]);
                        $latestEnd = $es->fetchColumn();
                    } catch (PDOException $e) {}

                    // Start from today OR from the end of the latest active period (whichever is later)
                    $start = ($latestEnd && $latestEnd > date('Y-m-d H:i:s'))
                           ? $latestEnd
                           : date('Y-m-d H:i:s');
                    $startTs = strtotime($start);

                    if (str_contains($duration, 'Week')) {
                        preg_match('/(\d+)/', $duration, $m);
                        $end = date('Y-m-d H:i:s', strtotime('+' . intval($m[1] ?? 1) . ' weeks', $startTs));
                    } elseif (str_contains($duration, 'Year')) {
                        $end = date('Y-m-d H:i:s', strtotime('+1 year', $startTs));
                    } else {
                        preg_match('/(\d+)/', $duration, $m);
                        $end = date('Y-m-d H:i:s', strtotime('+' . intval($m[1] ?? 1) . ' months', $startTs));
                    }

                    $pdo->prepare("UPDATE advertisement SET Status_AD='Approved', Start_time=?, End_time=? WHERE AD_ID=?")
                        ->execute([$start, $end, $ad_id]);

                    $pdo->prepare("INSERT INTO approve_advertisement (AD_ID, Admin_ID) VALUES (?,?) ON DUPLICATE KEY UPDATE Admin_ID=VALUES(Admin_ID)")
                        ->execute([$ad_id, $admin_id]);

                    $pdo->prepare("DELETE FROM owner_notification WHERE CA_ID=? AND type='advertisement' AND ref_id=?")
                        ->execute([$ad['CA_ID'], $ad_id]);

                    $queued = $start > date('Y-m-d H:i:s');
                    $success = $queued
                        ? 'Advertisement approved and queued! Will start after current period ends (' . date('M d, Y', strtotime($start)) . ').'
                        : 'Advertisement approved! Venue is now featured until ' . date('M d, Y', strtotime($end)) . '.';

                } else {
                    if (empty($comment)) {
                        $error = 'Please provide a rejection reason for the owner.';
                    } else {
                        $pdo->prepare("UPDATE advertisement SET Status_AD='Rejected' WHERE AD_ID=?")->execute([$ad_id]);
                        $pdo->prepare("DELETE FROM owner_notification WHERE CA_ID=? AND type='advertisement' AND ref_id=?")->execute([$ad['CA_ID'], $ad_id]);
                        $pdo->prepare("INSERT INTO owner_notification (CA_ID, type, ref_id, title, message) VALUES (?, 'advertisement', ?, 'Advertisement Rejected', ?)")
                            ->execute([$ad['CA_ID'], $ad_id, $comment]);
                        $success = 'Advertisement rejected. Owner has been notified.';
                    }
                }
            }
        } catch (PDOException $e) {
            $error = 'Action failed: ' . $e->getMessage();
        }
    }
}

// ── QUERIES ───────────────────────────────────────────────────────────────────
function get_ads($pdo, $filter) {
    try {
        $where = match($filter) {
            'pending'  => "AND ad.Status_AD = 'Pending'",
            'active'   => "AND ad.Status_AD IN ('Approved','Active') AND ad.End_time > NOW()",
            'expired'  => "AND (ad.Status_AD = 'Expired' OR (ad.Status_AD IN ('Approved','Active') AND ad.End_time < NOW()))",
            'rejected' => "AND ad.Status_AD = 'Rejected'",
            default    => ''
        };
        $stmt = $pdo->query("
            SELECT
                ad.*,
                r.AD_Rate_ID, r.Duration, r.Price, COALESCE(r.Is_Popular, 0) AS rate_popular, COALESCE(r.Is_Best_Value, 0) AS rate_best,
                v.VN_Name, v.VN_Address, v.VN_Image, v.CA_ID, v.VN_ID,
                co.Name  AS owner_name,
                co.Email AS owner_email,
                co.Phone AS owner_phone,
                n.message AS reject_reason
            FROM advertisement ad
            INNER JOIN advertisement_rate r ON ad.AD_Rate_ID = r.AD_Rate_ID
            INNER JOIN Venue_data v          ON ad.VN_ID = v.VN_ID
            INNER JOIN court_owner co        ON v.CA_ID = co.CA_ID
            LEFT JOIN  owner_notification n  ON n.CA_ID = v.CA_ID AND n.type = 'advertisement' AND n.ref_id = ad.AD_ID
            WHERE 1=1 $where
            ORDER BY ad.AD_date DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return []; }
}

function count_ads($pdo, $filter) {
    try {
        $where = match($filter) {
            'pending'  => "Status_AD = 'Pending'",
            'active'   => "Status_AD IN ('Approved','Active') AND End_time > NOW()",
            'expired'  => "Status_AD = 'Expired' OR (Status_AD IN ('Approved','Active') AND End_time < NOW())",
            'rejected' => "Status_AD = 'Rejected'",
            default    => '1=1'
        };
        return (int)$pdo->query("SELECT COUNT(*) FROM advertisement WHERE $where")->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

$ads    = get_ads($pdo, $filter);
$counts = [
    'pending'  => count_ads($pdo, 'pending'),
    'active'   => count_ads($pdo, 'active'),
    'expired'  => count_ads($pdo, 'expired'),
    'rejected' => count_ads($pdo, 'rejected'),
];

try {
    $ad_rates = $pdo->query("SELECT * FROM advertisement_rate ORDER BY Price ASC")->fetchAll();
} catch (PDOException $e) { $ad_rates = []; }

try {
    $revenue = $pdo->query("
        SELECT COALESCE(SUM(r.Price), 0)
        FROM advertisement ad
        INNER JOIN advertisement_rate r ON ad.AD_Rate_ID = r.AD_Rate_ID
        WHERE ad.Status_AD IN ('Approved','Active')
    ")->fetchColumn();
} catch (PDOException $e) { $revenue = 0; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advertisements - CourtBook Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .ad-card { transition: all 0.3s ease; }
        .ad-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        .slip-modal   { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:100; align-items:center; justify-content:center; }
        .slip-modal.open   { display:flex; }
        .reject-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:100; align-items:center; justify-content:center; }
        .reject-modal.open { display:flex; }
        #ratesChevron { transition: transform 0.2s; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">Advertisement Management</h1>
                    <p class="text-sm text-gray-500">Review, approve, and manage ad rates</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="bg-blue-50 border border-blue-200 px-4 py-2 rounded-xl text-sm">
                        <span class="text-blue-600 font-bold">₭<?= number_format($revenue) ?></span>
                        <span class="text-blue-500 ml-1">ad revenue</span>
                    </div>
                    <?php if ($counts['pending'] > 0): ?>
                        <div class="flex items-center gap-2 bg-red-50 border border-red-200 px-4 py-2 rounded-xl">
                            <i class="fas fa-exclamation-circle text-red-500"></i>
                            <span class="text-red-700 font-bold text-sm"><?= $counts['pending'] ?> pending</span>
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
                <?php foreach ([
                    ['label'=>'Pending',  'value'=>$counts['pending'],  'color'=>'yellow', 'icon'=>'fa-clock',        'filter'=>'pending'],
                    ['label'=>'Active',   'value'=>$counts['active'],   'color'=>'blue',   'icon'=>'fa-bullhorn',     'filter'=>'active'],
                    ['label'=>'Expired',  'value'=>$counts['expired'],  'color'=>'gray',   'icon'=>'fa-history',      'filter'=>'expired'],
                    ['label'=>'Rejected', 'value'=>$counts['rejected'], 'color'=>'red',    'icon'=>'fa-times-circle', 'filter'=>'rejected'],
                ] as $sc): ?>
                    <a href="?filter=<?= $sc['filter'] ?>"
                       class="bg-white rounded-2xl p-5 shadow-sm border-2 <?= $filter===$sc['filter'] ? 'border-'.$sc['color'].'-400' : 'border-transparent' ?> hover:shadow-md transition block">
                        <div class="bg-<?= $sc['color'] ?>-100 w-10 h-10 rounded-xl flex items-center justify-center mb-3">
                            <i class="fas <?= $sc['icon'] ?> text-<?= $sc['color'] ?>-500"></i>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-800"><?= $sc['value'] ?></p>
                        <p class="text-xs text-gray-500 mt-0.5"><?= $sc['label'] ?> Ads</p>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- ══ AD RATES MANAGEMENT PANEL ══ -->
            <div class="bg-white rounded-2xl shadow-sm mb-6 overflow-hidden border border-gray-100">
                <button onclick="toggleRates()" class="w-full flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition">
                    <div class="flex items-center gap-3">
                        <div class="bg-blue-100 w-9 h-9 rounded-xl flex items-center justify-center">
                            <i class="fas fa-tags text-blue-500"></i>
                        </div>
                        <div class="text-left">
                            <h2 class="font-bold text-gray-800">Advertisement Rate Plans</h2>
                            <p class="text-xs text-gray-400"><?= count($ad_rates) ?> plan<?= count($ad_rates) != 1 ? 's' : '' ?> · Click to manage prices, durations and descriptions</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-down text-gray-400" id="ratesChevron"></i>
                </button>

                <div id="ratesPanel" class="hidden border-t border-gray-100">
                    <div class="p-6">
                        <?php if (!empty($ad_rates)): ?>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-6">
                            <?php foreach ($ad_rates as $rate):
                                $is_pop  = !empty($rate['Is_Popular']);
                                $is_best = !empty($rate['Is_Best_Value']);
                            ?>
                            <div class="relative bg-white rounded-2xl border-2 <?= $is_pop ? 'border-blue-500 shadow-lg' : ($is_best ? 'border-yellow-400 shadow-lg' : 'border-gray-200') ?> p-4 flex flex-col items-center text-center pt-6">
                                <!-- Badge -->
                                <?php if ($is_pop): ?>
                                    <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-blue-600 text-white text-xs font-bold px-3 py-0.5 rounded-full whitespace-nowrap">Popular</span>
                                <?php elseif ($is_best): ?>
                                    <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-yellow-500 text-white text-xs font-bold px-3 py-0.5 rounded-full whitespace-nowrap">Best Value</span>
                                <?php endif; ?>

                                <div class="bg-blue-100 w-10 h-10 rounded-full flex items-center justify-center mb-2">
                                    <i class="fas fa-bullhorn text-blue-600 text-sm"></i>
                                </div>

                                <form method="POST" class="w-full space-y-1">
                                    <input type="hidden" name="rate_id" value="<?= $rate['AD_Rate_ID'] ?>">
                                    <input type="hidden" name="is_popular"   id="pop_ad_<?= $rate['AD_Rate_ID'] ?>"  value="<?= $is_pop ? 1 : 0 ?>">
                                    <input type="hidden" name="is_best_value" id="best_ad_<?= $rate['AD_Rate_ID'] ?>" value="<?= $is_best ? 1 : 0 ?>">

                                    <!-- Duration (editable, shown as title) -->
                                    <input type="text" name="rate_duration"
                                           value="<?= htmlspecialchars($rate['Duration']) ?>"
                                           class="w-full text-center text-sm font-semibold text-gray-700 bg-transparent border-0 border-b border-transparent hover:border-gray-300 focus:border-blue-400 focus:outline-none rounded px-1 py-0.5 transition"
                                           title="Duration">

                                    <!-- Price (large, editable) -->
                                    <input type="text" name="rate_price"
                                           value="<?= number_format($rate['Price'], 0, '.', '') ?>"
                                           class="w-full text-center text-2xl font-extrabold text-green-600 bg-transparent border-0 border-b border-transparent hover:border-gray-300 focus:border-blue-400 focus:outline-none rounded px-1 py-0.5 transition"
                                           title="Price (₭)">

                                    <!-- Save + Delete -->
                                    <div class="flex gap-1.5 pt-1">
                                        <button type="submit" name="save_rate"
                                                class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-1.5 rounded-xl text-xs transition">
                                            <i class="fas fa-save mr-0.5"></i>Save
                                        </button>
                                        <button type="submit" name="delete_rate"
                                                onclick="return confirm('Delete this plan?')"
                                                class="bg-red-50 hover:bg-red-100 text-red-500 border border-red-200 font-bold py-1.5 px-2.5 rounded-xl text-xs transition">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>

                                    <!-- Popular / Best Value toggles -->
                                    <div class="flex gap-1.5 pt-1">
                                        <button type="button"
                                                onclick="toggleBadge('pop_ad_<?= $rate['AD_Rate_ID'] ?>', 'best_ad_<?= $rate['AD_Rate_ID'] ?>', 'popular', this)"
                                                class="flex-1 text-xs font-semibold py-1 rounded-xl border transition <?= $is_pop ? 'bg-blue-50 text-blue-600 border-blue-200' : 'bg-gray-50 text-gray-400 border-gray-200' ?>">
                                            ★ Popular
                                        </button>
                                        <button type="button"
                                                onclick="toggleBadge('best_ad_<?= $rate['AD_Rate_ID'] ?>', 'pop_ad_<?= $rate['AD_Rate_ID'] ?>', 'best', this)"
                                                class="flex-1 text-xs font-semibold py-1 rounded-xl border transition <?= $is_best ? 'bg-yellow-50 text-yellow-600 border-yellow-300' : 'bg-gray-50 text-gray-400 border-gray-200' ?>">
                                            ♛ Best
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <?php endforeach; ?>

                            <!-- Add new plan card -->
                            <div class="bg-white rounded-2xl border-2 border-dashed border-blue-200 p-4 flex flex-col items-center justify-center text-center gap-2 min-h-[260px]">
                                <div class="bg-blue-100 w-10 h-10 rounded-full flex items-center justify-center">
                                    <i class="fas fa-plus text-blue-600"></i>
                                </div>
                                <p class="text-xs font-bold text-blue-600">New Plan</p>
                                <form method="POST" class="w-full space-y-1.5">
                                    <input type="hidden" name="is_popular" value="0">
                                    <input type="hidden" name="is_best_value" value="0">
                                    <input type="text" name="rate_duration" placeholder="Duration (e.g. 1 Month)"
                                           class="w-full text-center text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:border-blue-400">
                                    <input type="text" name="rate_price" placeholder="Price (₭)"
                                           class="w-full text-center text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:border-blue-400">
                                    <button type="submit" name="add_rate"
                                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded-xl text-xs transition mt-1">
                                        <i class="fas fa-plus mr-1"></i>Add Plan
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="flex justify-center py-4">
                            <div class="bg-white rounded-2xl border-2 border-dashed border-blue-200 p-6 w-64 text-center space-y-2">
                                <div class="bg-blue-100 w-12 h-12 rounded-full flex items-center justify-center mx-auto">
                                    <i class="fas fa-plus text-blue-600 text-lg"></i>
                                </div>
                                <p class="text-sm font-bold text-blue-600">Add First Plan</p>
                                <form method="POST" class="space-y-1.5">
                                    <input type="hidden" name="is_popular" value="0">
                                    <input type="hidden" name="is_best_value" value="0">
                                    <input type="text" name="rate_duration" placeholder="Duration"
                                           class="w-full text-center text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:border-blue-400">
                                    <input type="text" name="rate_price" placeholder="Price (₭)"
                                           class="w-full text-center text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:border-blue-400">
                                    <button type="submit" name="add_rate"
                                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded-xl text-xs transition">
                                        <i class="fas fa-plus mr-1"></i>Add Plan
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="flex gap-2 mb-6 flex-wrap">
                <?php foreach ([
                    'pending'  => ['label'=>'Pending Review', 'icon'=>'fa-clock',        'color'=>'yellow'],
                    'active'   => ['label'=>'Active',         'icon'=>'fa-bullhorn',     'color'=>'blue'],
                    'expired'  => ['label'=>'Expired',        'icon'=>'fa-history',      'color'=>'gray'],
                    'rejected' => ['label'=>'Rejected',       'icon'=>'fa-times-circle', 'color'=>'red'],
                ] as $key => $t): ?>
                    <a href="?filter=<?= $key ?>"
                       class="px-4 py-2 rounded-xl font-semibold text-sm transition flex items-center gap-2
                              <?= $filter===$key ? 'bg-blue-600 text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                        <i class="fas <?= $t['icon'] ?>"></i><?= $t['label'] ?>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold
                                     <?= $filter===$key ? 'bg-white text-blue-600' : 'bg-'.$t['color'].'-100 text-'.$t['color'].'-700' ?>">
                            <?= $counts[$key] ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Ads List -->
            <?php if (!empty($ads)): ?>
                <div class="space-y-4">
                    <?php foreach ($ads as $ad):
                        $slip_url  = !empty($ad['Slip_payment'])
                            ? '/Badminton_court_Booking/assets/images/slips/' . basename($ad['Slip_payment']) : '';
                        $venue_img = !empty($ad['VN_Image'])
                            ? '/Badminton_court_Booking/assets/images/venues/' . basename($ad['VN_Image']) : '';
                        $now_str   = date('Y-m-d H:i:s');
                        $is_queued = !empty($ad['Start_time']) && $ad['Start_time'] > $now_str;
                        $days_left = $ad['End_time'] ? ceil((strtotime($ad['End_time']) - time()) / 86400) : 0;
                        $days_until_start = $is_queued ? ceil((strtotime($ad['Start_time']) - time()) / 86400) : 0;
                        $status_cfg = match($ad['Status_AD']) {
                            'Approved','Active' => $is_queued
                                ? ['border'=>'border-purple-400', 'badge_bg'=>'bg-purple-100', 'badge_text'=>'text-purple-700', 'icon'=>'fa-layer-group']
                                : ['border'=>'border-blue-400',   'badge_bg'=>'bg-blue-100',   'badge_text'=>'text-blue-700',   'icon'=>'fa-bullhorn'],
                            'Rejected' => ['border'=>'border-red-400',  'badge_bg'=>'bg-red-100',  'badge_text'=>'text-red-700',  'icon'=>'fa-times-circle'],
                            'Expired'  => ['border'=>'border-gray-400', 'badge_bg'=>'bg-gray-100', 'badge_text'=>'text-gray-600', 'icon'=>'fa-history'],
                            default    => ['border'=>'border-yellow-400','badge_bg'=>'bg-yellow-100','badge_text'=>'text-yellow-700','icon'=>'fa-clock'],
                        };
                        $display_status = $is_queued ? 'Queued' : $ad['Status_AD'];
                    ?>
                        <div class="ad-card bg-white rounded-2xl shadow-sm border-l-4 <?= $status_cfg['border'] ?> overflow-hidden">
                            <div class="flex flex-col md:flex-row">

                                <!-- Venue Thumbnail -->
                                <div class="w-full md:w-36 h-36 flex-shrink-0 bg-gray-100">
                                    <?php if ($venue_img): ?>
                                        <img src="<?= htmlspecialchars($venue_img) ?>"
                                             class="w-full h-full object-cover"
                                             onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center\'><i class=\'fas fa-store text-3xl text-gray-300\'></i></div>'">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center">
                                            <i class="fas fa-store text-3xl text-gray-300"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Ad Info -->
                                <div class="flex-1 p-5">
                                    <div class="flex flex-col md:flex-row justify-between gap-3">
                                        <!-- Left: venue + owner -->
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                                <h3 class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($ad['VN_Name']) ?></h3>
                                                <span class="<?= $status_cfg['badge_bg'] ?> <?= $status_cfg['badge_text'] ?> text-xs font-bold px-2 py-0.5 rounded-full">
                                                    <i class="fas <?= $status_cfg['icon'] ?> mr-1"></i><?= $display_status ?>
                                                </span>
                                            </div>
                                            <p class="text-gray-500 text-sm mb-1">
                                                <i class="fas fa-map-marker-alt mr-1 text-red-400"></i><?= htmlspecialchars($ad['VN_Address']) ?>
                                            </p>
                                            <p class="text-gray-500 text-sm mb-1">
                                                <i class="fas fa-user mr-1 text-purple-400"></i><?= htmlspecialchars($ad['owner_name']) ?>
                                                <span class="mx-1 text-gray-300">·</span>
                                                <i class="fas fa-envelope mr-1 text-blue-400"></i><?= htmlspecialchars($ad['owner_email']) ?>
                                            </p>
                                            <p class="text-gray-500 text-sm">
                                                <i class="fas fa-phone mr-1 text-green-400"></i><?= htmlspecialchars($ad['owner_phone']) ?>
                                            </p>

                                            <!-- Plan detail row -->
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <span class="inline-flex items-center gap-1 bg-blue-50 border border-blue-100 text-blue-700 text-xs font-semibold px-2.5 py-1 rounded-full">
                                                    <i class="fas fa-clock text-xs"></i>
                                                    <?= htmlspecialchars($ad['Duration']) ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Right: price + timeline -->
                                        <div class="flex flex-col items-end gap-1 text-right flex-shrink-0">
                                            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-2 text-center min-w-[120px]">
                                                <p class="text-sm font-bold text-blue-700"><?= htmlspecialchars($ad['Duration']) ?></p>
                                                <p class="text-xl font-extrabold text-green-600">₭<?= number_format($ad['Price']) ?></p>
                                            </div>
                                            <p class="text-xs text-gray-400">Submitted: <?= date('M d, Y', strtotime($ad['AD_date'])) ?></p>

                                            <?php if (in_array($ad['Status_AD'], ['Approved','Active']) && $ad['End_time']): ?>
                                                <?php if ($is_queued): ?>
                                                    <!-- Queued: waiting for previous to expire -->
                                                    <div class="mt-1 bg-purple-50 border border-purple-200 rounded-xl px-3 py-1.5 text-right">
                                                        <p class="text-xs font-bold text-purple-600">
                                                            <i class="fas fa-hourglass-half mr-1"></i>Queued — starts in <?= $days_until_start ?> day<?= $days_until_start != 1 ? 's' : '' ?>
                                                        </p>
                                                        <p class="text-xs text-purple-500">
                                                            <?= date('M d, Y', strtotime($ad['Start_time'])) ?> → <?= date('M d, Y', strtotime($ad['End_time'])) ?>
                                                        </p>
                                                    </div>
                                                <?php else: ?>
                                                    <!-- Active: running now -->
                                                    <p class="text-xs <?= $days_left <= 7 ? 'text-red-500 font-bold' : 'text-gray-400' ?>">
                                                        <?= $days_left > 0 ? $days_left . ' days left' : 'Expired' ?> · Until <?= date('M d, Y', strtotime($ad['End_time'])) ?>
                                                    </p>
                                                    <?php
                                                        $total_days = max(1, (strtotime($ad['End_time']) - strtotime($ad['Start_time'])) / 86400);
                                                        $pct = min(100, max(0, ($days_left / $total_days) * 100));
                                                    ?>
                                                    <div class="w-32 bg-gray-100 rounded-full h-1.5 mt-1">
                                                        <div class="bg-blue-500 h-1.5 rounded-full" style="width:<?= $pct ?>%"></div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Rejection reason -->
                                    <?php if ($ad['Status_AD'] === 'Rejected' && !empty($ad['reject_reason'])): ?>
                                        <div class="mt-3 bg-red-50 border border-red-200 rounded-xl px-4 py-2 flex items-start gap-2">
                                            <i class="fas fa-comment-alt text-red-400 mt-0.5 flex-shrink-0 text-sm"></i>
                                            <div>
                                                <p class="text-xs font-bold text-red-600">Reason sent to owner:</p>
                                                <p class="text-sm text-red-700"><?= htmlspecialchars($ad['reject_reason']) ?></p>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Actions -->
                                    <div class="flex flex-wrap gap-2 mt-4 pt-3 border-t border-gray-100">
                                        <?php if ($slip_url): ?>
                                            <button onclick="viewSlip('<?= htmlspecialchars($slip_url) ?>', <?= $ad['AD_ID'] ?>, '<?= htmlspecialchars($ad['Status_AD']) ?>')"
                                                    class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-2 rounded-xl text-sm font-semibold transition">
                                                <i class="fas fa-receipt mr-1"></i>View Slip
                                            </button>
                                        <?php else: ?>
                                            <span class="bg-gray-100 text-gray-400 px-3 py-2 rounded-xl text-sm">
                                                <i class="fas fa-clock mr-1"></i>No slip uploaded
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($ad['Status_AD'] === 'Pending'): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="ad_id" value="<?= $ad['AD_ID'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl text-sm font-bold transition shadow">
                                                    <i class="fas fa-check mr-1"></i>Approve
                                                </button>
                                            </form>
                                            <button onclick="openReject(<?= $ad['AD_ID'] ?>)"
                                                    class="bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2 rounded-xl text-sm font-bold transition border border-red-200">
                                                <i class="fas fa-times mr-1"></i>Reject
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($ad['Status_AD'] === 'Rejected'): ?>
                                            <form method="POST" class="inline"
                                                  onsubmit="return confirm('Permanently delete this rejected ad record?')">
                                                <input type="hidden" name="ad_id" value="<?= $ad['AD_ID'] ?>">
                                                <button type="submit" name="delete_rejected"
                                                        class="bg-gray-100 hover:bg-red-100 text-gray-500 hover:text-red-600 px-3 py-2 rounded-xl text-sm font-semibold transition border border-gray-200 hover:border-red-200">
                                                    <i class="fas fa-trash mr-1"></i>Delete Record
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <i class="fas fa-bullhorn text-6xl text-gray-200 mb-4 block"></i>
                    <h3 class="text-xl font-bold text-gray-600 mb-2">No <?= ucfirst($filter) ?> Advertisements</h3>
                    <p class="text-gray-400 text-sm">
                        <?= $filter === 'pending' ? 'All advertisement payments have been reviewed.' : 'No advertisements in this category.' ?>
                    </p>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<!-- Slip Modal -->
<div class="slip-modal" id="slipModal">
    <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-lg w-full mx-4 relative max-h-screen overflow-y-auto">
        <button onclick="closeSlip()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl"><i class="fas fa-times"></i></button>
        <h3 class="font-bold text-gray-800 text-lg mb-4">
            <i class="fas fa-receipt text-blue-500 mr-2"></i>Payment Slip — Ad #<span id="modalAdId"></span>
        </h3>
        <img id="modalSlipImg" src="" alt="Payment Slip"
             class="w-full max-h-96 object-contain rounded-xl border border-gray-200 mb-4">
        <div class="flex gap-3" id="modalActions"></div>
    </div>
</div>

<!-- Reject Modal -->
<div class="reject-modal" id="rejectModal">
    <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-md w-full mx-4 relative">
        <button onclick="closeReject()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl"><i class="fas fa-times"></i></button>
        <h3 class="font-bold text-gray-800 text-lg mb-1">
            <i class="fas fa-times-circle text-red-500 mr-2"></i>Reject Advertisement
        </h3>
        <p class="text-sm text-gray-500 mb-4">The owner will see this reason in their notifications until they fix it.</p>
        <form method="POST">
            <input type="hidden" name="ad_id" id="rejectAdId">
            <input type="hidden" name="action" value="reject">
            <textarea name="comment" rows="4" required
                      placeholder="e.g. Payment slip is unclear. Please resubmit with a clearer image."
                      class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-red-400 transition resize-none text-sm mb-4"></textarea>
            <div class="flex gap-3">
                <button type="button" onclick="closeReject()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition">Cancel</button>
                <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl transition">
                    <i class="fas fa-paper-plane mr-1"></i>Send Rejection
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleRates() {
    const panel   = document.getElementById('ratesPanel');
    const chevron = document.getElementById('ratesChevron');
    panel.classList.toggle('hidden');
    chevron.style.transform = panel.classList.contains('hidden') ? '' : 'rotate(180deg)';
}
function toggleBadge(targetId, otherSetId, type, btn) {
    const target = document.getElementById(targetId);
    const other  = document.getElementById(otherSetId);
    const isOn   = target.value === '1';
    // Toggle target off or on
    target.value = isOn ? '0' : '1';
    // If turning on, turn the other off
    if (!isOn && other) other.value = '0';
    // Update button visuals by re-rendering the card via submit or just reload after save
    if (type === 'popular') {
        btn.className = btn.className.replace(/(bg-blue-50 text-blue-600 border-blue-200|bg-gray-50 text-gray-400 border-gray-200)/g, '');
        btn.className += isOn ? ' bg-gray-50 text-gray-400 border-gray-200' : ' bg-blue-50 text-blue-600 border-blue-200';
    } else {
        btn.className = btn.className.replace(/(bg-yellow-50 text-yellow-600 border-yellow-300|bg-gray-50 text-gray-400 border-gray-200)/g, '');
        btn.className += isOn ? ' bg-gray-50 text-gray-400 border-gray-200' : ' bg-yellow-50 text-yellow-600 border-yellow-300';
    }
}
function viewSlip(url, adId, status) {
    document.getElementById('slipModal').classList.add('open');
    document.getElementById('modalSlipImg').src = url;
    document.getElementById('modalAdId').textContent = adId;
    const actions = document.getElementById('modalActions');
    if (status === 'Pending') {
        actions.innerHTML = `
            <form method="POST" class="flex-1">
                <input type="hidden" name="ad_id" value="${adId}">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                    <i class="fas fa-check mr-2"></i>Approve
                </button>
            </form>
            <button onclick="closeSlip(); openReject(${adId})"
                    class="flex-1 bg-red-50 hover:bg-red-100 text-red-600 font-bold py-3 rounded-xl border border-red-200 transition">
                <i class="fas fa-times mr-2"></i>Reject
            </button>`;
    } else { actions.innerHTML = ''; }
}
function closeSlip() { document.getElementById('slipModal').classList.remove('open'); }
document.getElementById('slipModal').addEventListener('click', function(e) { if(e.target===this) closeSlip(); });
document.addEventListener('keydown', e => { if(e.key==='Escape'){closeSlip();closeReject();} });
function openReject(id) {
    document.getElementById('rejectAdId').value = id;
    document.querySelector('#rejectModal textarea').value = '';
    document.getElementById('rejectModal').classList.add('open');
}
function closeReject() { document.getElementById('rejectModal').classList.remove('open'); }
document.getElementById('rejectModal').addEventListener('click', function(e) { if(e.target===this) closeReject(); });
</script>
</body>
</html>