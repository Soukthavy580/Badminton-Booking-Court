<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$error   = '';
$success = '';
$filter  = $_GET['filter'] ?? 'all';
$search  = trim($_GET['search'] ?? '');

// ── STATUS UPDATE ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vn_id     = intval($_POST['vn_id']  ?? 0);
    $new_status = $_POST['status']       ?? '';
    $allowed_statuses = ['Active', 'Inactive', 'Maintaining', 'Banned'];

    if ($vn_id && in_array($new_status, $allowed_statuses)) {
        try {
            $pdo->prepare("UPDATE Venue_data SET VN_Status = ? WHERE VN_ID = ?")
                ->execute([$new_status, $vn_id]);
            $success = "Venue status updated to \"{$new_status}\" successfully.";
        } catch (PDOException $e) {
            error_log("Venue status update error: " . $e->getMessage());
            $error = 'Failed to update status. Please try again.';
        }
    }
}

// ── FETCH VENUES ─────────────────────────────────────────────
function get_venues($pdo, $filter, $search)
{
    try {
        $where  = [];
        $params = [];

        if ($filter !== 'all') {
            $where[]  = "v.VN_Status = ?";
            $params[] = ucfirst($filter);
        }

        if (!empty($search)) {
            $where[]  = "(v.VN_Name LIKE ? OR v.VN_Address LIKE ? OR co.Name LIKE ?)";
            $t        = "%{$search}%";
            $params[] = $t;
            $params[] = $t;
            $params[] = $t;
        }

        $sql = "
            SELECT
                v.*,
                co.Name      AS owner_name,
                co.Phone     AS owner_phone,
                co.Email     AS owner_email,
                co.CA_ID,
                (SELECT COUNT(*) FROM Court_data WHERE VN_ID = v.VN_ID) AS court_count,
                (SELECT COUNT(*) FROM package
                 WHERE VN_ID = v.VN_ID AND Status_Package = 'Active' AND End_time > NOW()) AS has_active_pkg,
                (SELECT End_time FROM package
                 WHERE VN_ID = v.VN_ID AND Status_Package = 'Active' AND End_time > NOW()
                 ORDER BY End_time DESC LIMIT 1) AS pkg_end_time,
                (SELECT COUNT(*) FROM advertisement
                 WHERE VN_ID = v.VN_ID AND Status_AD IN ('Approved','Active') AND End_time > NOW()) AS has_active_ad
            FROM Venue_data v
            INNER JOIN court_owner co ON v.CA_ID = co.CA_ID
        ";

        if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY v.VN_ID DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("get_venues error: " . $e->getMessage());
        return [];
    }
}

function count_venues_by_status($pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT VN_Status, COUNT(*) as cnt
            FROM Venue_data
            GROUP BY VN_Status
        ");
        $rows   = $stmt->fetchAll();
        $counts = ['all' => 0, 'active' => 0, 'inactive' => 0, 'maintaining' => 0, 'banned' => 0];
        foreach ($rows as $r) {
            $key = strtolower($r['VN_Status']);
            $counts[$key] = (int)$r['cnt'];
            $counts['all'] += (int)$r['cnt'];
        }
        return $counts;
    } catch (PDOException $e) {
        return ['all' => 0, 'active' => 0, 'inactive' => 0, 'maintaining' => 0, 'banned' => 0];
    }
}

$venues = get_venues($pdo, $filter, $search);
$counts = count_venues_by_status($pdo);

// ── VENUE DETAIL MODAL ───────────────────────────────────────
$modal_venue  = null;
$modal_courts = [];

if (isset($_GET['view'])) {
    $view_id = intval($_GET['view']);
    try {
        $stmt = $pdo->prepare("
            SELECT v.*, co.Name AS owner_name, co.Phone AS owner_phone,
                   co.Email AS owner_email, co.Username AS owner_username,
                   (SELECT COUNT(*) FROM Court_data WHERE VN_ID = v.VN_ID) AS court_count,
                   (SELECT End_time FROM package
                    WHERE VN_ID = v.VN_ID AND Status_Package = 'Active' AND End_time > NOW()
                    ORDER BY End_time DESC LIMIT 1) AS pkg_end_time,
                   (SELECT Package_duration FROM package bp
                    INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID
                    WHERE bp.VN_ID = v.VN_ID AND bp.Status_Package = 'Active' AND bp.End_time > NOW()
                    ORDER BY bp.End_time DESC LIMIT 1) AS pkg_duration
            FROM Venue_data v
            INNER JOIN court_owner co ON v.CA_ID = co.CA_ID
            WHERE v.VN_ID = ?
        ");
        $stmt->execute([$view_id]);
        $modal_venue = $stmt->fetch();

        if ($modal_venue) {
            $stmt = $pdo->prepare("SELECT * FROM Court_data WHERE VN_ID = ? ORDER BY COURT_ID ASC");
            $stmt->execute([$view_id]);
            $modal_courts = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Venues - CourtBook Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .venue-card {
            transition: all 0.3s ease;
        }

        .venue-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        }

        .detail-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .detail-modal.open {
            display: flex;
        }

        .status-btn {
            transition: all 0.2s;
        }

        .status-btn:hover {
            transform: scale(1.03);
        }

        .status-btn.current {
            box-shadow: 0 0 0 2px #3b82f6;
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <?php include 'includes/sidebar.php'; ?>

        <div class="flex-1 flex flex-col">
            <!-- Top Bar -->
            <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-bold text-gray-800">Venue Management</h1>
                        <p class="text-sm text-gray-500">Monitor and control venue statuses</p>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 px-4 py-2 rounded-xl text-sm">
                        <span class="text-blue-700 font-bold"><?= $counts['all'] ?></span>
                        <span class="text-blue-500 ml-1">total venues</span>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-6">

                <!-- Alerts -->
                <?php if ($error): ?>
                    <div class="mb-6 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl flex items-center gap-3">
                        <i class="fas fa-exclamation-circle flex-shrink-0"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="mb-6 p-4 bg-green-50 border border-green-300 text-green-700 rounded-xl flex items-center gap-3">
                        <i class="fas fa-check-circle flex-shrink-0"></i>
                        <span><?= htmlspecialchars($success) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Status Stats -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                    <?php
                    $stat_cards = [
                        ['key' => 'all',         'label' => 'All Venues',   'color' => 'blue',   'icon' => 'fa-store'],
                        ['key' => 'active',      'label' => 'Active',       'color' => 'green',  'icon' => 'fa-check-circle'],
                        ['key' => 'inactive',    'label' => 'Inactive',     'color' => 'gray',   'icon' => 'fa-eye-slash'],
                        ['key' => 'maintaining', 'label' => 'Maintaining',  'color' => 'yellow', 'icon' => 'fa-tools'],
                        ['key' => 'banned',      'label' => 'Banned',       'color' => 'red',    'icon' => 'fa-ban'],
                    ];
                    foreach ($stat_cards as $sc):
                    ?>
                        <a href="?filter=<?= $sc['key'] ?>"
                            class="bg-white rounded-2xl p-4 shadow-sm border-2 <?= $filter === $sc['key'] ? 'border-' . $sc['color'] . '-400 bg-' . $sc['color'] . '-50' : 'border-transparent' ?> hover:shadow-md transition block">
                            <div class="bg-<?= $sc['color'] ?>-100 w-9 h-9 rounded-xl flex items-center justify-center mb-2">
                                <i class="fas <?= $sc['icon'] ?> text-<?= $sc['color'] ?>-500 text-sm"></i>
                            </div>
                            <p class="text-2xl font-extrabold text-gray-800"><?= $counts[$sc['key']] ?? 0 ?></p>
                            <p class="text-xs text-gray-500 mt-0.5"><?= $sc['label'] ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Search + Filter -->
                <div class="flex flex-col md:flex-row gap-3 mb-6">
                    <form method="GET" class="flex gap-2 flex-1">
                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                        <input type="text" name="search"
                            value="<?= htmlspecialchars($search) ?>"
                            placeholder="Search by venue name, address or owner..."
                            class="flex-1 border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500 transition">
                        <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                            <i class="fas fa-search"></i>
                        </button>
                        <?php if ($search): ?>
                            <a href="?filter=<?= $filter ?>"
                                class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                                Clear
                            </a>
                        <?php endif; ?>
                    </form>

                    <div class="flex gap-2 flex-wrap">
                        <?php foreach ($stat_cards as $sc): ?>
                            <a href="?filter=<?= $sc['key'] ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                                class="px-3 py-2.5 rounded-xl font-semibold text-xs transition
                                  <?= $filter === $sc['key'] ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                                <?= $sc['label'] ?> (<?= $counts[$sc['key']] ?? 0 ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Venue Status Legend -->
                <div class="bg-white rounded-2xl shadow-sm p-4 mb-6 grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php
                    $legend = [
                        ['color' => 'green',  'icon' => 'fa-check-circle', 'status' => 'Active',      'desc' => 'Visible & bookable by customers'],
                        ['color' => 'gray',   'icon' => 'fa-eye-slash',    'status' => 'Inactive',    'desc' => 'Hidden from customers completely'],
                        ['color' => 'yellow', 'icon' => 'fa-tools',        'status' => 'Maintaining', 'desc' => 'Visible but bookings disabled'],
                        ['color' => 'red',    'icon' => 'fa-ban',          'status' => 'Banned',      'desc' => 'Permanently disabled'],
                    ];
                    foreach ($legend as $l):
                    ?>
                        <div class="flex items-center gap-2 bg-<?= $l['color'] ?>-50 rounded-xl px-3 py-2">
                            <i class="fas <?= $l['icon'] ?> text-<?= $l['color'] ?>-500 flex-shrink-0"></i>
                            <div>
                                <p class="text-xs font-bold text-<?= $l['color'] ?>-700"><?= $l['status'] ?></p>
                                <p class="text-xs text-<?= $l['color'] ?>-500"><?= $l['desc'] ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Venues List -->
                <?php if (!empty($venues)): ?>
                    <div class="space-y-4">
                        <?php foreach ($venues as $venue):
                            $vs = $venue['VN_Status'];
                            $vs_cfg = match ($vs) {
                                'Active'      => ['border' => 'border-green-400',  'badge_bg' => 'bg-green-100',  'badge_text' => 'text-green-700',  'icon' => 'fa-check-circle',  'icon_color' => 'text-green-500'],
                                'Inactive'    => ['border' => 'border-gray-300',   'badge_bg' => 'bg-gray-100',   'badge_text' => 'text-gray-600',   'icon' => 'fa-eye-slash',     'icon_color' => 'text-gray-400'],
                                'Maintaining' => ['border' => 'border-yellow-400', 'badge_bg' => 'bg-yellow-100', 'badge_text' => 'text-yellow-700', 'icon' => 'fa-tools',         'icon_color' => 'text-yellow-500'],
                                'Banned'      => ['border' => 'border-red-400',    'badge_bg' => 'bg-red-100',    'badge_text' => 'text-red-700',    'icon' => 'fa-ban',           'icon_color' => 'text-red-500'],
                                default       => ['border' => 'border-gray-200',   'badge_bg' => 'bg-gray-100',   'badge_text' => 'text-gray-600',   'icon' => 'fa-circle',        'icon_color' => 'text-gray-400'],
                            };
                        ?>
                            <div class="venue-card bg-white rounded-2xl shadow-sm border-l-4 <?= $vs_cfg['border'] ?> p-5">
                                <div class="flex flex-col lg:flex-row gap-4">

                                    <!-- Venue Image + Info -->
                                    <div class="flex items-start gap-4 flex-1">
                                        <!-- Image -->
                                        <div class="w-20 h-20 rounded-xl overflow-hidden flex-shrink-0 bg-gray-100">
                                            <?php if ($venue['VN_Image']): ?>
                                                <img src="/Badminton_court_Booking/assets/images/venues/<?= htmlspecialchars($venue['VN_Image']) ?>"
                                                    class="w-full h-full object-cover"
                                                    onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center\'><i class=\'fas fa-store text-gray-300 text-2xl\'></i></div>'">
                                            <?php else: ?>
                                                <div class="w-full h-full flex items-center justify-center">
                                                    <i class="fas fa-store text-gray-300 text-2xl"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Info -->
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                                <h3 class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($venue['VN_Name']) ?></h3>
                                                <span class="<?= $vs_cfg['badge_bg'] ?> <?= $vs_cfg['badge_text'] ?> text-xs font-bold px-2 py-0.5 rounded-full flex items-center gap-1">
                                                    <i class="fas <?= $vs_cfg['icon'] ?> text-xs"></i>
                                                    <?= $vs ?>
                                                </span>
                                            </div>
                                            <p class="text-sm text-gray-500 mb-1">
                                                <i class="fas fa-map-marker-alt mr-1 text-red-400"></i>
                                                <?= htmlspecialchars($venue['VN_Address']) ?>
                                            </p>
                                            <p class="text-sm text-gray-500 mb-1">
                                                <i class="fas fa-user-tie mr-1 text-purple-400"></i>
                                                <?= htmlspecialchars($venue['owner_name']) ?>
                                                <span class="text-gray-400 ml-2">
                                                    <i class="fas fa-phone mr-1"></i><?= htmlspecialchars($venue['owner_phone']) ?>
                                                </span>
                                            </p>
                                            <div class="flex gap-3 flex-wrap mt-2">
                                                <span class="text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded-lg">
                                                    <i class="fas fa-table-tennis mr-1 text-blue-400"></i>
                                                    <?= $venue['court_count'] ?> courts
                                                </span>
                                                <span class="text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded-lg">
                                                    <i class="fas fa-clock mr-1 text-green-400"></i>
                                                    <?= htmlspecialchars($venue['Open_time']) ?> - <?= htmlspecialchars($venue['Close_time']) ?>
                                                </span>
                                                <span class="text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded-lg">
                                                    <i class="fas fa-tag mr-1 text-yellow-400"></i>
                                                    ₭<?= number_format($venue['Price_per_hour']) ?>/hr
                                                </span>
                                                <?php if ($venue['has_active_pkg']): ?>
                                                    <span class="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded-lg font-semibold">
                                                        <i class="fas fa-box mr-1"></i>
                                                        Pkg: <?= date('M d, Y', strtotime($venue['pkg_end_time'])) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-xs bg-red-100 text-red-600 px-2 py-1 rounded-lg font-semibold">
                                                        <i class="fas fa-exclamation-triangle mr-1"></i>No Package
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($venue['has_active_ad']): ?>
                                                    <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-lg font-semibold">
                                                        <i class="fas fa-bullhorn mr-1"></i>Advertising
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Status Controls + Actions -->
                                    <div class="flex flex-col gap-3 flex-shrink-0 lg:min-w-[260px]">

                                        <!-- Status Buttons -->
                                        <div class="bg-gray-50 rounded-xl p-3">
                                            <p class="text-xs text-gray-400 font-semibold mb-2 uppercase tracking-wide">Set Status</p>
                                            <div class="grid grid-cols-2 gap-2">
                                                <?php
                                                $status_btns = [
                                                    ['status' => 'Active',      'color' => 'green',  'icon' => 'fa-check-circle'],
                                                    ['status' => 'Inactive',    'color' => 'gray',   'icon' => 'fa-eye-slash'],
                                                    ['status' => 'Maintaining', 'color' => 'yellow', 'icon' => 'fa-tools'],
                                                    ['status' => 'Banned',      'color' => 'red',    'icon' => 'fa-ban'],
                                                ];
                                                foreach ($status_btns as $btn):
                                                    $is_current = $vs === $btn['status'];
                                                ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="vn_id" value="<?= $venue['VN_ID'] ?>">
                                                        <input type="hidden" name="status" value="<?= $btn['status'] ?>">
                                                        <button type="submit"
                                                            <?= $is_current ? 'disabled' : '' ?>
                                                            <?= $btn['status'] === 'Banned' && !$is_current ? "onclick=\"return confirm('Ban this venue? It will be permanently disabled.')\"" : '' ?>
                                                            class="status-btn w-full flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold transition
                                                                   <?= $is_current
                                                                        ? 'bg-' . $btn['color'] . '-500 text-white cursor-default ring-2 ring-' . $btn['color'] . '-300 ring-offset-1'
                                                                        : 'bg-' . $btn['color'] . '-50 text-' . $btn['color'] . '-700 hover:bg-' . $btn['color'] . '-100 border border-' . $btn['color'] . '-200' ?>">
                                                            <i class="fas <?= $btn['icon'] ?> text-xs"></i>
                                                            <?= $btn['status'] ?>
                                                            <?php if ($is_current): ?>
                                                                <i class="fas fa-check text-xs ml-0.5"></i>
                                                            <?php endif; ?>
                                                        </button>
                                                    </form>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <!-- View Details -->
                                        <a href="?filter=<?= $filter ?><?= $search ? '&search=' . urlencode($search) : '' ?>&view=<?= $venue['VN_ID'] ?>"
                                            class="flex items-center justify-center gap-2 bg-blue-50 hover:bg-blue-100 text-blue-700 px-4 py-2 rounded-xl text-sm font-semibold transition">
                                            <i class="fas fa-eye"></i> View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <i class="fas fa-store text-6xl text-gray-200 mb-4 block"></i>
                        <h3 class="text-xl font-bold text-gray-600 mb-2">No Venues Found</h3>
                        <p class="text-gray-400 text-sm">
                            <?= $search ? 'No venues match your search.' : 'No venues have been created yet.' ?>
                        </p>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <!-- Venue Detail Modal -->
    <?php if ($modal_venue): ?>
        <div class="detail-modal open">
            <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">

                <!-- Header -->
                <?php
                $ms = $modal_venue['VN_Status'];
                $hdr = match ($ms) {
                    'Active'      => 'from-green-500 to-green-600',
                    'Inactive'    => 'from-gray-400 to-gray-500',
                    'Maintaining' => 'from-yellow-400 to-yellow-500',
                    'Banned'      => 'from-red-500 to-red-600',
                    default       => 'from-blue-500 to-blue-600',
                };
                ?>
                <div class="bg-gradient-to-r <?= $hdr ?> p-6 rounded-t-2xl relative">
                    <a href="?filter=<?= $filter ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                        class="absolute top-4 right-4 bg-white bg-opacity-20 hover:bg-opacity-30 text-white w-8 h-8 rounded-full flex items-center justify-center transition">
                        <i class="fas fa-times"></i>
                    </a>

                    <?php if ($modal_venue['VN_Image']): ?>
                        <img src="/Badminton_court_Booking/assets/images/venues/<?= htmlspecialchars($modal_venue['VN_Image']) ?>"
                            class="w-full h-40 object-cover rounded-xl mb-4 opacity-80"
                            onerror="this.style.display='none'">
                    <?php endif; ?>

                    <h2 class="text-2xl font-extrabold text-white"><?= htmlspecialchars($modal_venue['VN_Name']) ?></h2>
                    <p class="text-white text-opacity-80 text-sm mt-1">
                        <i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($modal_venue['VN_Address']) ?>
                    </p>
                    <span class="inline-block mt-2 bg-white bg-opacity-20 text-white text-xs font-bold px-3 py-1 rounded-full">
                        <?= $ms ?>
                    </span>
                </div>

                <div class="p-6">

                    <!-- Venue Details Grid -->
                    <div class="grid grid-cols-2 gap-3 mb-6">
                        <?php
                        $details = [
                            ['icon' => 'fa-user-tie',       'color' => 'purple', 'label' => 'Owner',       'value' => $modal_venue['owner_name']],
                            ['icon' => 'fa-phone',           'color' => 'green',  'label' => 'Phone',       'value' => $modal_venue['owner_phone']],
                            ['icon' => 'fa-envelope',        'color' => 'blue',   'label' => 'Email',       'value' => $modal_venue['owner_email']],
                            ['icon' => 'fa-clock',           'color' => 'yellow', 'label' => 'Hours',       'value' => $modal_venue['Open_time'] . ' - ' . $modal_venue['Close_time']],
                            ['icon' => 'fa-tag',             'color' => 'orange', 'label' => 'Price/hr',    'value' => '₭' . number_format($modal_venue['Price_per_hour'])],
                            ['icon' => 'fa-table-tennis',    'color' => 'teal',   'label' => 'Courts',      'value' => $modal_venue['court_count'] . ' courts'],
                            [
                                'icon' => 'fa-box',
                                'color' => 'purple',
                                'label' => 'Package',
                                'value' => $modal_venue['pkg_end_time']
                                    ? 'Active until ' . date('M d, Y', strtotime($modal_venue['pkg_end_time']))
                                    : 'No active package'
                            ],
                        ];
                        foreach ($details as $d):
                        ?>
                            <div class="bg-gray-50 rounded-xl p-3 flex items-center gap-3">
                                <i class="fas <?= $d['icon'] ?> text-<?= $d['color'] ?>-400 w-4 flex-shrink-0"></i>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-400"><?= $d['label'] ?></p>
                                    <p class="font-semibold text-gray-700 text-sm truncate"><?= htmlspecialchars($d['value']) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Description -->
                    <?php if ($modal_venue['VN_Description']): ?>
                        <div class="bg-gray-50 rounded-xl p-4 mb-6">
                            <p class="text-xs text-gray-400 mb-1">Description</p>
                            <p class="text-sm text-gray-700"><?= htmlspecialchars($modal_venue['VN_Description']) ?></p>
                        </div>
                    <?php endif; ?>

                    <!-- Courts List -->
                    <?php if (!empty($modal_courts)): ?>
                        <div class="mb-6">
                            <h3 class="font-bold text-gray-800 mb-3">
                                <i class="fas fa-table-tennis text-blue-500 mr-2"></i>Courts
                            </h3>
                            <div class="grid grid-cols-2 gap-2">
                                <?php foreach ($modal_courts as $i => $court): ?>
                                    <div class="bg-blue-50 rounded-xl px-4 py-3 flex items-center gap-3">
                                        <div class="w-8 h-8 bg-blue-200 rounded-full flex items-center justify-center font-bold text-blue-700 text-sm flex-shrink-0">
                                            <?= $i + 1 ?>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($court['COURT_Name']) ?></p>
                                            <?php if (!empty($court['Court_description'] ?? '')): ?>
                                                <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($court['Court_description']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Map Link -->
                    <?php if ($modal_venue['Map_link'] ?? null): ?>
                        <div class="mb-6">
                            <a href="<?= htmlspecialchars($modal_venue['Map_link']) ?>"
                                target="_blank"
                                class="flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-xl transition text-sm">
                                <i class="fas fa-map-marker-alt"></i> View on Google Maps
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Status Controls in Modal -->
                    <div class="pt-4 border-t border-gray-100">
                        <p class="text-sm font-bold text-gray-700 mb-3">Change Venue Status</p>
                        <div class="grid grid-cols-2 gap-3">
                            <?php
                            foreach ($status_btns as $btn):
                                $is_current = $ms === $btn['status'];
                            ?>
                                <form method="POST">
                                    <input type="hidden" name="vn_id" value="<?= $modal_venue['VN_ID'] ?>">
                                    <input type="hidden" name="status" value="<?= $btn['status'] ?>">
                                    <button type="submit"
                                        <?= $is_current ? 'disabled' : '' ?>
                                        <?= $btn['status'] === 'Banned' && !$is_current ? "onclick=\"return confirm('Ban this venue?')\"" : '' ?>
                                        class="w-full flex items-center justify-center gap-2 py-3 rounded-xl text-sm font-bold transition
                                           <?= $is_current
                                                ? 'bg-' . $btn['color'] . '-500 text-white cursor-default'
                                                : 'bg-' . $btn['color'] . '-50 text-' . $btn['color'] . '-700 hover:bg-' . $btn['color'] . '-100 border border-' . $btn['color'] . '-200' ?>">
                                        <i class="fas <?= $btn['icon'] ?>"></i>
                                        <?= $btn['status'] ?>
                                        <?php if ($is_current): ?>
                                            <span class="text-xs">(current)</span>
                                        <?php endif; ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    <?php endif; ?>

</body>

</html>