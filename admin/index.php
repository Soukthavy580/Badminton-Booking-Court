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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vn_id      = intval($_POST['vn_id']  ?? 0);
    $new_status = $_POST['status']        ?? '';
    $allowed    = ['Active','Inactive','Maintaining','Banned'];
    if ($vn_id && in_array($new_status, $allowed)) {
        try {
            $pdo->prepare("UPDATE Venue_data SET VN_Status = ? WHERE VN_ID = ?")
                ->execute([$new_status, $vn_id]);
            $status_label = match($new_status) {
                'Active'=>'ໃຊ້ງານໄດ້','Inactive'=>'ບໍ່ໃຊ້ງານ',
                'Maintaining'=>'ກຳລັງສ້ອມແປງ','Banned'=>'ຖືກລະງັບ', default=>$new_status
            };
            $success = "ອັບເດດສະຖານະສະຖານທີ່ເປັນ \"{$status_label}\" ສຳເລັດ.";
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
        }
    }
}

function get_venues($pdo, $filter, $search) {
    try {
        $where  = [];
        $params = [];
        if ($filter !== 'all') { $where[] = "v.VN_Status = ?"; $params[] = ucfirst($filter); }
        if (!empty($search)) {
            $where[] = "(v.VN_Name LIKE ? OR v.VN_Address LIKE ? OR co.Name LIKE ?)";
            $t = "%{$search}%";
            $params = array_merge($params, [$t,$t,$t]);
        }
        $sql = "
            SELECT v.*, co.Name AS owner_name, co.Phone AS owner_phone,
                   co.Email AS owner_email, co.CA_ID,
                (SELECT COUNT(*) FROM Court_data WHERE VN_ID = v.VN_ID) AS court_count,
                (SELECT COUNT(*) FROM package WHERE CA_ID = v.CA_ID AND Status_Package='Active' AND End_time>NOW()) AS has_active_pkg,
                (SELECT End_time FROM package WHERE CA_ID = v.CA_ID AND Status_Package='Active' AND End_time>NOW() ORDER BY End_time DESC LIMIT 1) AS pkg_end_time,
                (SELECT COUNT(*) FROM advertisement WHERE VN_ID=v.VN_ID AND Status_AD IN('Approved','Active') AND End_time>NOW()) AS has_active_ad
            FROM Venue_data v
            INNER JOIN court_owner co ON v.CA_ID = co.CA_ID
        ";
        if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY v.VN_ID DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function count_venues_by_status($pdo) {
    try {
        $stmt = $pdo->query("SELECT VN_Status, COUNT(*) as cnt FROM Venue_data GROUP BY VN_Status");
        $counts = ['all'=>0,'active'=>0,'inactive'=>0,'maintaining'=>0,'banned'=>0,'pending'=>0];
        foreach ($stmt->fetchAll() as $r) {
            $key = strtolower($r['VN_Status']);
            $counts[$key] = (int)$r['cnt'];
            $counts['all'] += (int)$r['cnt'];
        }
        return $counts;
    } catch (PDOException $e) {
        return ['all'=>0,'active'=>0,'inactive'=>0,'maintaining'=>0,'banned'=>0,'pending'=>0];
    }
}

$venues = get_venues($pdo, $filter, $search);
$counts = count_venues_by_status($pdo);

$modal_venue  = null;
$modal_courts = [];
if (isset($_GET['view'])) {
    $view_id = intval($_GET['view']);
    try {
        $stmt = $pdo->prepare("
            SELECT v.*, co.Name AS owner_name, co.Phone AS owner_phone,
                   co.Email AS owner_email, co.Username AS owner_username,
                (SELECT COUNT(*) FROM Court_data WHERE VN_ID = v.VN_ID) AS court_count,
                (SELECT End_time FROM package WHERE CA_ID=v.CA_ID AND Status_Package='Active' AND End_time>NOW() ORDER BY End_time DESC LIMIT 1) AS pkg_end_time,
                (SELECT pr.Package_duration FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID=pr.Package_rate_ID WHERE bp.CA_ID=v.CA_ID AND bp.Status_Package='Active' AND bp.End_time>NOW() ORDER BY bp.End_time DESC LIMIT 1) AS pkg_duration
            FROM Venue_data v INNER JOIN court_owner co ON v.CA_ID = co.CA_ID
            WHERE v.VN_ID = ?
        ");
        $stmt->execute([$view_id]);
        $modal_venue = $stmt->fetch();
        if ($modal_venue) {
            $stmt = $pdo->prepare("SELECT * FROM Court_data WHERE VN_ID = ? ORDER BY COURT_ID ASC");
            $stmt->execute([$view_id]);
            $modal_courts = $stmt->fetchAll();
        }
    } catch (PDOException $e) {}
}

$status_btns = [
    ['status'=>'Active',      'color'=>'green', 'icon'=>'fa-check-circle','label'=>'ໃຊ້ງານໄດ້'],
    ['status'=>'Inactive',    'color'=>'gray',  'icon'=>'fa-eye-slash',   'label'=>'ບໍ່ໃຊ້ງານ'],
    ['status'=>'Maintaining', 'color'=>'yellow','icon'=>'fa-tools',       'label'=>'ສ້ອມແປງ'],
    ['status'=>'Banned',      'color'=>'red',   'icon'=>'fa-ban',         'label'=>'ລະງັບ'],
];
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ສະຖານທີ່ - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .venue-card { transition: all 0.3s ease; }
        .venue-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        .detail-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:100; align-items:center; justify-content:center; }
        .detail-modal.open { display:flex; }
        .status-btn { transition: all 0.2s; }
        .status-btn:hover { transform: scale(1.03); }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include 'includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">ຈັດການສະຖານທີ່</h1>
                    <p class="text-sm text-gray-500">ຕິດຕາມ ແລະ ຄວບຄຸມສະຖານະສະຖານທີ່</p>
                </div>
                <div class="bg-blue-50 border border-blue-200 px-4 py-2 rounded-xl text-sm">
                    <span class="text-blue-700 font-bold"><?= $counts['all'] ?></span>
                    <span class="text-blue-500 ml-1">ສະຖານທີ່ທັງໝົດ</span>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6">

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl flex items-center gap-3">
                    <i class="fas fa-exclamation-circle flex-shrink-0"></i><span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-300 text-green-700 rounded-xl flex items-center gap-3">
                    <i class="fas fa-check-circle flex-shrink-0"></i><span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <?php foreach ([
                    ['key'=>'all',        'label'=>'ທັງໝົດ',      'color'=>'blue',  'icon'=>'fa-store'],
                    ['key'=>'active',     'label'=>'ໃຊ້ງານໄດ້',  'color'=>'green', 'icon'=>'fa-check-circle'],
                    ['key'=>'inactive',   'label'=>'ບໍ່ໃຊ້ງານ',  'color'=>'gray',  'icon'=>'fa-eye-slash'],
                    ['key'=>'maintaining','label'=>'ສ້ອມແປງ',    'color'=>'yellow','icon'=>'fa-tools'],
                    ['key'=>'banned',     'label'=>'ລະງັບ',       'color'=>'red',   'icon'=>'fa-ban'],
                ] as $sc): ?>
                    <a href="?filter=<?= $sc['key'] ?>"
                       class="bg-white rounded-2xl p-4 shadow-sm border-2 <?= $filter===$sc['key'] ? 'border-'.$sc['color'].'-400 bg-'.$sc['color'].'-50' : 'border-transparent' ?> hover:shadow-md transition block">
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
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                           placeholder="ຊອກຫາຕາມຊື່, ທີ່ຢູ່ ຫຼື ເຈົ້າຂອງ..."
                           class="flex-1 border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500 transition">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                        <i class="fas fa-search"></i>
                    </button>
                    <?php if ($search): ?>
                        <a href="?filter=<?= $filter ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2.5 rounded-xl text-sm font-semibold transition">ລ້າງ</a>
                    <?php endif; ?>
                </form>
                <div class="flex gap-2 flex-wrap">
                    <?php foreach ([
                        ['key'=>'all','label'=>'ທັງໝົດ'],['key'=>'active','label'=>'ໃຊ້ງານໄດ້'],
                        ['key'=>'inactive','label'=>'ບໍ່ໃຊ້ງານ'],['key'=>'maintaining','label'=>'ສ້ອມແປງ'],['key'=>'banned','label'=>'ລະງັບ'],
                    ] as $t): ?>
                        <a href="?filter=<?= $t['key'] ?><?= $search ? '&search='.urlencode($search) : '' ?>"
                           class="px-3 py-2.5 rounded-xl font-semibold text-xs transition <?= $filter===$t['key'] ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                            <?= $t['label'] ?> (<?= $counts[$t['key']] ?? 0 ?>)
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Status Legend -->
            <div class="bg-white rounded-2xl shadow-sm p-4 mb-6 grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php foreach ([
                    ['color'=>'green', 'icon'=>'fa-check-circle','status'=>'ໃຊ້ງານໄດ້',  'desc'=>'ລູກຄ້າເຫັນ ແລະ ຈອງໄດ້'],
                    ['color'=>'gray',  'icon'=>'fa-eye-slash',   'status'=>'ບໍ່ໃຊ້ງານ',  'desc'=>'ລູກຄ້າບໍ່ເຫັນ'],
                    ['color'=>'yellow','icon'=>'fa-tools',       'status'=>'ສ້ອມແປງ',    'desc'=>'ເຫັນໄດ້ ແຕ່ຈອງບໍ່ໄດ້'],
                    ['color'=>'red',   'icon'=>'fa-ban',         'status'=>'ລະງັບ',       'desc'=>'ປິດຖາວອນ'],
                ] as $l): ?>
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
                        $vs_cfg = match($vs) {
                            'Active'      => ['border'=>'border-green-400', 'badge_bg'=>'bg-green-100', 'badge_text'=>'text-green-700', 'icon'=>'fa-check-circle'],
                            'Inactive'    => ['border'=>'border-gray-300',  'badge_bg'=>'bg-gray-100',  'badge_text'=>'text-gray-600',  'icon'=>'fa-eye-slash'],
                            'Maintaining' => ['border'=>'border-yellow-400','badge_bg'=>'bg-yellow-100','badge_text'=>'text-yellow-700','icon'=>'fa-tools'],
                            'Banned'      => ['border'=>'border-red-400',   'badge_bg'=>'bg-red-100',   'badge_text'=>'text-red-700',   'icon'=>'fa-ban'],
                            'Pending'     => ['border'=>'border-blue-400',  'badge_bg'=>'bg-blue-100',  'badge_text'=>'text-blue-700',  'icon'=>'fa-clock'],
                            default       => ['border'=>'border-gray-200',  'badge_bg'=>'bg-gray-100',  'badge_text'=>'text-gray-600',  'icon'=>'fa-circle'],
                        };
                        $vs_label = match($vs) {
                            'Active'=>'ໃຊ້ງານໄດ້','Inactive'=>'ບໍ່ໃຊ້ງານ',
                            'Maintaining'=>'ສ້ອມແປງ','Banned'=>'ລະງັບ','Pending'=>'ລໍຖ້າ', default=>$vs
                        };
                    ?>
                        <div class="venue-card bg-white rounded-2xl shadow-sm border-l-4 <?= $vs_cfg['border'] ?> p-5">
                            <div class="flex flex-col lg:flex-row gap-4">
                                <div class="flex items-start gap-4 flex-1">
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
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                                            <h3 class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($venue['VN_Name']) ?></h3>
                                            <span class="<?= $vs_cfg['badge_bg'] ?> <?= $vs_cfg['badge_text'] ?> text-xs font-bold px-2 py-0.5 rounded-full flex items-center gap-1">
                                                <i class="fas <?= $vs_cfg['icon'] ?> text-xs"></i><?= $vs_label ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-500 mb-1"><i class="fas fa-map-marker-alt mr-1 text-red-400"></i><?= htmlspecialchars($venue['VN_Address']) ?></p>
                                        <p class="text-sm text-gray-500 mb-1">
                                            <i class="fas fa-user-tie mr-1 text-purple-400"></i><?= htmlspecialchars($venue['owner_name']) ?>
                                            <span class="text-gray-400 ml-2"><i class="fas fa-phone mr-1"></i><?= htmlspecialchars($venue['owner_phone']) ?></span>
                                        </p>
                                        <div class="flex gap-2 flex-wrap mt-2">
                                            <span class="text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded-lg"><i class="fas fa-table-tennis mr-1 text-blue-400"></i><?= $venue['court_count'] ?> ເດີ່ນ</span>
                                            <span class="text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded-lg"><i class="fas fa-clock mr-1 text-green-400"></i><?= date('H:i', strtotime($venue['Open_time'])) ?> - <?= date('H:i', strtotime($venue['Close_time'])) ?></span>
                                            <span class="text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded-lg"><i class="fas fa-tag mr-1 text-yellow-400"></i>₭<?= number_format($venue['Price_per_hour']) ?>/ຊມ</span>
                                            <?php if ($venue['has_active_pkg']): ?>
                                                <span class="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded-lg font-semibold"><i class="fas fa-box mr-1"></i>ແພັກ: <?= date('d/m/Y', strtotime($venue['pkg_end_time'])) ?></span>
                                            <?php else: ?>
                                                <span class="text-xs bg-red-100 text-red-600 px-2 py-1 rounded-lg font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i>ບໍ່ມີແພັກ</span>
                                            <?php endif; ?>
                                            <?php if ($venue['has_active_ad']): ?>
                                                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-lg font-semibold"><i class="fas fa-bullhorn mr-1"></i>ໂຄສະນາ</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-3 flex-shrink-0 lg:min-w-[260px]">
                                    <div class="bg-gray-50 rounded-xl p-3">
                                        <p class="text-xs text-gray-400 font-semibold mb-2 uppercase tracking-wide">ຕັ້ງສະຖານະ</p>
                                        <div class="grid grid-cols-2 gap-2">
                                            <?php foreach ($status_btns as $btn):
                                                $is_current = $vs === $btn['status'];
                                            ?>
                                                <form method="POST">
                                                    <input type="hidden" name="vn_id" value="<?= $venue['VN_ID'] ?>">
                                                    <input type="hidden" name="status" value="<?= $btn['status'] ?>">
                                                    <button type="submit" <?= $is_current ? 'disabled' : '' ?>
                                                            <?= $btn['status']==='Banned' && !$is_current ? "onclick=\"return confirm('ລະງັບສະຖານທີ່ນີ້?')\"" : '' ?>
                                                            class="status-btn w-full flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold transition
                                                                   <?= $is_current
                                                                       ? 'bg-'.$btn['color'].'-500 text-white cursor-default ring-2 ring-'.$btn['color'].'-300 ring-offset-1'
                                                                       : 'bg-'.$btn['color'].'-50 text-'.$btn['color'].'-700 hover:bg-'.$btn['color'].'-100 border border-'.$btn['color'].'-200' ?>">
                                                        <i class="fas <?= $btn['icon'] ?> text-xs"></i><?= $btn['label'] ?>
                                                        <?php if ($is_current): ?><i class="fas fa-check text-xs ml-0.5"></i><?php endif; ?>
                                                    </button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <a href="?filter=<?= $filter ?><?= $search ? '&search='.urlencode($search) : '' ?>&view=<?= $venue['VN_ID'] ?>"
                                       class="flex items-center justify-center gap-2 bg-blue-50 hover:bg-blue-100 text-blue-700 px-4 py-2 rounded-xl text-sm font-semibold transition">
                                        <i class="fas fa-eye"></i>ເບິ່ງລາຍລະອຽດ
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <i class="fas fa-store text-6xl text-gray-200 mb-4 block"></i>
                    <h3 class="text-xl font-bold text-gray-600 mb-2">ບໍ່ພົບສະຖານທີ່</h3>
                    <p class="text-gray-400 text-sm"><?= $search ? 'ບໍ່ມີສະຖານທີ່ທີ່ກົງກັບການຄົ້ນຫາ.' : 'ຍັງບໍ່ມີສະຖານທີ່ຖືກສ້າງ.' ?></p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Venue Detail Modal -->
<?php if ($modal_venue): ?>
<div class="detail-modal open">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <?php
        $ms  = $modal_venue['VN_Status'];
        $hdr = match($ms) {
            'Active'=>'from-green-500 to-green-600','Inactive'=>'from-gray-400 to-gray-500',
            'Maintaining'=>'from-yellow-400 to-yellow-500','Banned'=>'from-red-500 to-red-600',
            default=>'from-blue-500 to-blue-600'
        };
        $ms_label = match($ms) {
            'Active'=>'ໃຊ້ງານໄດ້','Inactive'=>'ບໍ່ໃຊ້ງານ',
            'Maintaining'=>'ສ້ອມແປງ','Banned'=>'ລະງັບ','Pending'=>'ລໍຖ້າ', default=>$ms
        };
        ?>
        <div class="bg-gradient-to-r <?= $hdr ?> p-6 rounded-t-2xl relative">
            <a href="?filter=<?= $filter ?><?= $search ? '&search='.urlencode($search) : '' ?>"
               class="absolute top-4 right-4 bg-white bg-opacity-20 hover:bg-opacity-30 text-white w-8 h-8 rounded-full flex items-center justify-center transition">
                <i class="fas fa-times"></i>
            </a>
            <?php if ($modal_venue['VN_Image']): ?>
                <img src="/Badminton_court_Booking/assets/images/venues/<?= htmlspecialchars($modal_venue['VN_Image']) ?>"
                     class="w-full h-40 object-cover rounded-xl mb-4 opacity-80" onerror="this.style.display='none'">
            <?php endif; ?>
            <h2 class="text-2xl font-extrabold text-white"><?= htmlspecialchars($modal_venue['VN_Name']) ?></h2>
            <p class="text-white text-opacity-80 text-sm mt-1"><i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($modal_venue['VN_Address']) ?></p>
            <span class="inline-block mt-2 bg-white bg-opacity-20 text-white text-xs font-bold px-3 py-1 rounded-full"><?= $ms_label ?></span>
        </div>

        <div class="p-6">
            <div class="grid grid-cols-2 gap-3 mb-6">
                <?php foreach ([
                    ['icon'=>'fa-user-tie',   'color'=>'purple','label'=>'ເຈົ້າຂອງ',   'value'=>$modal_venue['owner_name']],
                    ['icon'=>'fa-phone',      'color'=>'green', 'label'=>'ໂທ',          'value'=>$modal_venue['owner_phone']],
                    ['icon'=>'fa-envelope',   'color'=>'blue',  'label'=>'ອີເມລ໌',      'value'=>$modal_venue['owner_email']],
                    ['icon'=>'fa-clock',      'color'=>'yellow','label'=>'ເວລາ',        'value'=>date('H:i',strtotime($modal_venue['Open_time'])).' - '.date('H:i',strtotime($modal_venue['Close_time']))],
                    ['icon'=>'fa-tag',        'color'=>'orange','label'=>'ລາຄາ/ຊມ',    'value'=>'₭'.number_format($modal_venue['Price_per_hour'])],
                    ['icon'=>'fa-table-tennis','color'=>'teal', 'label'=>'ເດີ່ນ',       'value'=>$modal_venue['court_count'].' ເດີ່ນ'],
                    ['icon'=>'fa-box',        'color'=>'purple','label'=>'ແພັກເກດ',    'value'=>$modal_venue['pkg_end_time'] ? 'ໃຊ້ງານໄດ້ຮອດ '.date('d/m/Y',strtotime($modal_venue['pkg_end_time'])) : 'ບໍ່ມີແພັກ'],
                ] as $d): ?>
                    <div class="bg-gray-50 rounded-xl p-3 flex items-center gap-3">
                        <i class="fas <?= $d['icon'] ?> text-<?= $d['color'] ?>-400 w-4 flex-shrink-0"></i>
                        <div class="min-w-0">
                            <p class="text-xs text-gray-400"><?= $d['label'] ?></p>
                            <p class="font-semibold text-gray-700 text-sm truncate"><?= htmlspecialchars($d['value']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($modal_venue['VN_Description']): ?>
                <div class="bg-gray-50 rounded-xl p-4 mb-6">
                    <p class="text-xs text-gray-400 mb-1">ຄຳອະທິບາຍ</p>
                    <p class="text-sm text-gray-700"><?= htmlspecialchars($modal_venue['VN_Description']) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($modal_courts)): ?>
                <div class="mb-6">
                    <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-table-tennis text-blue-500 mr-2"></i>ເດີ່ນ</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ($modal_courts as $i => $court): ?>
                            <div class="bg-blue-50 rounded-xl px-4 py-3 flex items-center gap-3">
                                <div class="w-8 h-8 bg-blue-200 rounded-full flex items-center justify-center font-bold text-blue-700 text-sm flex-shrink-0"><?= $i+1 ?></div>
                                <p class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($court['COURT_Name']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- FIX: Changed Map_link to VN_MapURL -->
            <?php if (!empty($modal_venue['VN_MapURL'])): ?>
                <div class="mb-6">
                    <a href="<?= htmlspecialchars($modal_venue['VN_MapURL']) ?>" target="_blank"
                       class="flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-xl transition text-sm">
                        <i class="fas fa-map-marker-alt"></i>ເບິ່ງໃນ Google Maps
                    </a>
                </div>
            <?php endif; ?>

            <div class="pt-4 border-t border-gray-100">
                <p class="text-sm font-bold text-gray-700 mb-3">ປ່ຽນສະຖານະສະຖານທີ່</p>
                <div class="grid grid-cols-2 gap-3">
                    <?php foreach ($status_btns as $btn):
                        $is_current = $ms === $btn['status'];
                    ?>
                        <form method="POST">
                            <input type="hidden" name="vn_id" value="<?= $modal_venue['VN_ID'] ?>">
                            <input type="hidden" name="status" value="<?= $btn['status'] ?>">
                            <button type="submit" <?= $is_current ? 'disabled' : '' ?>
                                    <?= $btn['status']==='Banned' && !$is_current ? "onclick=\"return confirm('ລະງັບສະຖານທີ່ນີ້?')\"" : '' ?>
                                    class="w-full flex items-center justify-center gap-2 py-3 rounded-xl text-sm font-bold transition
                                           <?= $is_current
                                               ? 'bg-'.$btn['color'].'-500 text-white cursor-default'
                                               : 'bg-'.$btn['color'].'-50 text-'.$btn['color'].'-700 hover:bg-'.$btn['color'].'-100 border border-'.$btn['color'].'-200' ?>">
                                <i class="fas <?= $btn['icon'] ?>"></i><?= $btn['label'] ?>
                                <?php if ($is_current): ?><span class="text-xs">(ປັດຈຸບັນ)</span><?php endif; ?>
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