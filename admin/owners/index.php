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
    $ca_id  = intval($_POST['ca_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($ca_id && in_array($action, ['ban','unban'])) {
        try {
            $new_status = $action === 'ban' ? 'Banned' : 'Active';
            $pdo->prepare("UPDATE court_owner SET Status = ? WHERE CA_ID = ?")
                ->execute([$new_status, $ca_id]);
            $success = $action === 'ban' ? 'ລະງັບບັນຊີເຈົ້າຂອງສຳເລັດ.' : 'ຍົກເລີກການລະງັບສຳເລັດ.';
        } catch (PDOException $e) {
            $error = 'ດຳເນີນການລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
        }
    }
}

function get_owners($pdo, $filter, $search) {
    try {
        $where  = [];
        $params = [];
        if ($filter === 'active')      $where[] = "co.Status = 'Active'";
        elseif ($filter === 'banned')  $where[] = "co.Status = 'Banned'";
        if (!empty($search)) {
            $where[] = "(co.Name LIKE ? OR co.Email LIKE ? OR co.Phone LIKE ? OR v.VN_Name LIKE ?)";
            $t = "%{$search}%";
            $params = array_merge($params, [$t,$t,$t,$t]);
        }
        $sql = "
            SELECT co.*, v.VN_ID, v.VN_Name, v.VN_Status, v.VN_Address,
                (SELECT COUNT(DISTINCT b.Book_ID)
                 FROM booking b
                 INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
                 INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
                 WHERE c.VN_ID = v.VN_ID AND b.Status_booking IN ('Confirmed','Completed')) AS total_bookings,
                (SELECT COUNT(*) FROM package bp
                 WHERE bp.CA_ID = co.CA_ID
                 AND bp.Status_Package = 'Active' AND bp.End_time > NOW()) AS has_active_package,
                (SELECT COUNT(*) FROM advertisement ad
                 WHERE ad.VN_ID = v.VN_ID
                 AND ad.Status_AD IN ('Approved','Active') AND ad.End_time > NOW()) AS has_active_ad
            FROM court_owner co
            LEFT JOIN Venue_data v ON co.CA_ID = v.CA_ID
        ";
        if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY co.CA_ID DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function count_owners($pdo, $filter) {
    try {
        $where = match($filter) { 'active' => "Status = 'Active'", 'banned' => "Status = 'Banned'", default => '1=1' };
        return (int)$pdo->query("SELECT COUNT(*) FROM court_owner WHERE $where")->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

$modal_owner    = null;
$modal_packages = [];
$modal_ads      = [];

if (isset($_GET['view'])) {
    $view_id = intval($_GET['view']);
    try {
        $stmt = $pdo->prepare("
            SELECT co.*, v.VN_ID, v.VN_Name, v.VN_Status, v.VN_Address, v.Price_per_hour,
                   v.Open_time, v.Close_time,
                (SELECT COUNT(DISTINCT b.Book_ID) FROM booking b
                 INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
                 INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
                 WHERE c.VN_ID = v.VN_ID AND b.Status_booking IN ('Confirmed','Completed')) AS total_bookings,
                (SELECT COUNT(*) FROM Court_data WHERE VN_ID = v.VN_ID) AS total_courts
            FROM court_owner co
            LEFT JOIN Venue_data v ON co.CA_ID = v.CA_ID
            WHERE co.CA_ID = ?
        ");
        $stmt->execute([$view_id]);
        $modal_owner = $stmt->fetch();

        if ($modal_owner) {
            $stmt = $pdo->prepare("
                SELECT bp.*, pr.Package_duration, pr.Price
                FROM package bp
                INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID
                WHERE bp.CA_ID = ?
                ORDER BY bp.Package_date DESC
            ");
            $stmt->execute([$view_id]);
            $modal_packages = $stmt->fetchAll();

            if ($modal_owner['VN_ID']) {
                $stmt = $pdo->prepare("
                    SELECT ad.*, r.Duration, r.Price
                    FROM advertisement ad
                    INNER JOIN advertisement_rate r ON ad.AD_Rate_ID = r.AD_Rate_ID
                    WHERE ad.VN_ID = ?
                    ORDER BY ad.AD_date DESC
                ");
                $stmt->execute([$modal_owner['VN_ID']]);
                $modal_ads = $stmt->fetchAll();
            }
        }
    } catch (PDOException $e) {}
}

$owners = get_owners($pdo, $filter, $search);
$counts = [
    'all'    => count_owners($pdo, 'all'),
    'active' => count_owners($pdo, 'active'),
    'banned' => count_owners($pdo, 'banned'),
];
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ເຈົ້າຂອງ - Admin</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .owner-card { transition: all 0.3s ease; }
        .owner-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
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
                    <h1 class="text-xl font-bold text-gray-800">ຈັດການເຈົ້າຂອງ</h1>
                    <p class="text-sm text-gray-500">ເບິ່ງ ແລະ ຈັດການບັນຊີເຈົ້າຂອງເດີ່ນ</p>
                </div>
                <div class="bg-purple-50 border border-purple-200 px-4 py-2 rounded-xl text-sm">
                    <span class="text-purple-700 font-bold"><?= $counts['all'] ?></span>
                    <span class="text-purple-500 ml-1">ເຈົ້າຂອງທັງໝົດ</span>
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
                    ['label'=>'ທັງໝົດ',    'value'=>$counts['all'],   'color'=>'purple','icon'=>'fa-user-tie','filter'=>'all'],
                    ['label'=>'ກຳລັງໃຊ້ງານ', 'value'=>$counts['active'],'color'=>'green', 'icon'=>'fa-check-circle','filter'=>'active'],
                    ['label'=>'ຖືກລະງັບ', 'value'=>$counts['banned'],'color'=>'red',   'icon'=>'fa-ban','filter'=>'banned'],
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
                           placeholder="ຊອກຫາຕາມຊື່, ອີເມລ໌, ໂທ ຫຼື ສະຖານທີ່..."
                           class="flex-1 border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500 transition">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                        <i class="fas fa-search"></i>
                    </button>
                    <?php if ($search): ?>
                        <a href="?filter=<?= $filter ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2.5 rounded-xl text-sm font-semibold transition">ລ້າງ</a>
                    <?php endif; ?>
                </form>
                <div class="flex gap-2">
                    <?php foreach (['all'=>'ທັງໝົດ','active'=>'ກຳລັງໃຊ້ງານ','banned'=>'ຖືກລະງັບ'] as $key => $label): ?>
                        <a href="?filter=<?= $key ?><?= $search ? '&search='.urlencode($search) : '' ?>"
                           class="px-4 py-2.5 rounded-xl font-semibold text-sm transition <?= $filter===$key ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                            <?= $label ?> <span class="ml-1 text-xs <?= $filter===$key ? 'text-blue-200' : 'text-gray-400' ?>">(<?= $counts[$key] ?>)</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Owners List -->
            <?php if (!empty($owners)): ?>
                <div class="space-y-4">
                    <?php foreach ($owners as $owner):
                        $is_banned  = ($owner['Status'] ?? 'Active') === 'Banned';
                        $status_cfg = $is_banned
                            ? ['border'=>'border-red-400',  'badge_bg'=>'bg-red-100',  'badge_text'=>'text-red-700']
                            : ['border'=>'border-green-400','badge_bg'=>'bg-green-100','badge_text'=>'text-green-700'];
                    ?>
                        <div class="owner-card bg-white rounded-2xl shadow-sm border-l-4 <?= $status_cfg['border'] ?> p-5">
                            <div class="flex flex-col md:flex-row justify-between gap-4">
                                <div class="flex items-start gap-4">
                                    <div class="w-12 h-12 bg-gradient-to-br from-purple-400 to-blue-500 rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                                        <?= strtoupper(substr($owner['Name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2 mb-1">
                                            <h3 class="font-bold text-gray-800"><?= htmlspecialchars($owner['Name']) ?></h3>
                                            <span class="<?= $status_cfg['badge_bg'] ?> <?= $status_cfg['badge_text'] ?> text-xs font-bold px-2 py-0.5 rounded-full">
                                                <?= $is_banned ? '🚫 ຖືກລະງັບ' : '✓ ກຳລັງໃຊ້ງານ' ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-500"><i class="fas fa-at mr-1 text-gray-400"></i><?= htmlspecialchars($owner['Username']) ?></p>
                                        <p class="text-sm text-gray-500"><i class="fas fa-envelope mr-1 text-blue-400"></i><?= htmlspecialchars($owner['Email']) ?></p>
                                        <p class="text-sm text-gray-500"><i class="fas fa-phone mr-1 text-green-400"></i><?= htmlspecialchars($owner['Phone']) ?></p>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-2 text-sm">
                                    <?php if ($owner['VN_Name']): ?>
                                        <div class="bg-gray-50 rounded-xl px-4 py-2">
                                            <p class="font-semibold text-gray-700"><i class="fas fa-store mr-1 text-blue-400"></i><?= htmlspecialchars($owner['VN_Name']) ?></p>
                                            <p class="text-gray-400 text-xs mt-0.5"><i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($owner['VN_Address']) ?></p>
                                            <div class="flex gap-2 mt-1 flex-wrap">
                                                <?php
                                                $vs = $owner['VN_Status'];
                                                $vs_color = match($vs) { 'Active'=>'green','Pending'=>'yellow','Rejected'=>'red', default=>'gray' };
                                                ?>
                                                <span class="bg-<?= $vs_color ?>-100 text-<?= $vs_color ?>-700 text-xs px-2 py-0.5 rounded-full font-semibold"><?= $vs ?></span>
                                                <?php if ($owner['has_active_package']): ?><span class="bg-purple-100 text-purple-700 text-xs px-2 py-0.5 rounded-full font-semibold"><i class="fas fa-box mr-1"></i>ແພັກເກດໃຊ້ງານໄດ້</span><?php endif; ?>
                                                <?php if ($owner['has_active_ad']): ?><span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full font-semibold"><i class="fas fa-bullhorn mr-1"></i>ໂຄສະນາ</span><?php endif; ?>
                                            </div>
                                        </div>
                                        <p class="text-xs text-gray-400 text-right"><i class="fas fa-calendar-check mr-1 text-green-400"></i><?= $owner['total_bookings'] ?> ການຈອງ</p>
                                    <?php else: ?>
                                        <div class="bg-gray-50 rounded-xl px-4 py-2 text-gray-400 text-xs"><i class="fas fa-store mr-1"></i>ຍັງບໍ່ໄດ້ສ້າງສະຖານທີ່</div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-col gap-2 flex-shrink-0">
                                    <a href="?filter=<?= $filter ?><?= $search ? '&search='.urlencode($search) : '' ?>&view=<?= $owner['CA_ID'] ?>"
                                       class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-4 py-2 rounded-xl text-sm font-semibold transition text-center">
                                        <i class="fas fa-eye mr-1"></i>ເບິ່ງລາຍລະອຽດ
                                    </a>
                                    <?php if ($is_banned): ?>
                                        <form method="POST"><input type="hidden" name="ca_id" value="<?= $owner['CA_ID'] ?>"><input type="hidden" name="action" value="unban">
                                            <button type="submit" class="w-full bg-green-50 hover:bg-green-100 text-green-600 px-4 py-2 rounded-xl text-sm font-bold transition border border-green-200">
                                                <i class="fas fa-check-circle mr-1"></i>ຍົກເລີກການລະງັບ
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" onsubmit="return confirm('ລະງັບ <?= htmlspecialchars(addslashes($owner["Name"])) ?>?')">
                                            <input type="hidden" name="ca_id" value="<?= $owner['CA_ID'] ?>"><input type="hidden" name="action" value="ban">
                                            <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2 rounded-xl text-sm font-bold transition border border-red-200">
                                                <i class="fas fa-ban mr-1"></i>ລະງັບ
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
                    <i class="fas fa-user-tie text-6xl text-gray-200 mb-4 block"></i>
                    <h3 class="text-xl font-bold text-gray-600 mb-2">ບໍ່ພົບເຈົ້າຂອງ</h3>
                    <p class="text-gray-400 text-sm"><?= $search ? 'ບໍ່ມີເຈົ້າຂອງທີ່ກົງກັບການຄົ້ນຫາ.' : 'ຍັງບໍ່ມີເຈົ້າຂອງລົງທະບຽນ.' ?></p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php if ($modal_owner): ?>
<div class="detail-modal open" id="ownerModal">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="bg-gradient-to-r from-purple-500 to-blue-600 p-6 rounded-t-2xl relative">
            <a href="?filter=<?= $filter ?><?= $search ? '&search='.urlencode($search) : '' ?>"
               class="absolute top-4 right-4 bg-white bg-opacity-20 hover:bg-opacity-30 text-white w-8 h-8 rounded-full flex items-center justify-center transition">
                <i class="fas fa-times"></i>
            </a>
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center text-white text-3xl font-bold">
                    <?= strtoupper(substr($modal_owner['Name'], 0, 1)) ?>
                </div>
                <div class="text-white">
                    <h2 class="text-2xl font-extrabold"><?= htmlspecialchars($modal_owner['Name']) ?></h2>
                    <p class="text-purple-200 text-sm">@<?= htmlspecialchars($modal_owner['Username']) ?></p>
                    <span class="inline-block mt-1 bg-white bg-opacity-20 text-white text-xs font-bold px-3 py-0.5 rounded-full">
                        <?= $modal_owner['Status']==='Banned' ? 'ຖືກລະງັບ' : 'ກຳລັງໃຊ້ງານ' ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-3 mb-6 text-sm">
                <?php foreach ([
                    ['icon'=>'fa-envelope','color'=>'blue', 'label'=>'ອີເມລ໌','value'=>$modal_owner['Email']],
                    ['icon'=>'fa-phone',   'color'=>'green','label'=>'ໂທ',     'value'=>$modal_owner['Phone']],
                ] as $f): ?>
                    <div class="bg-gray-50 rounded-xl p-3 flex items-center gap-3">
                        <i class="fas <?= $f['icon'] ?> text-<?= $f['color'] ?>-400 w-4"></i>
                        <div><p class="text-xs text-gray-400"><?= $f['label'] ?></p><p class="font-semibold text-gray-700"><?= htmlspecialchars($f['value']) ?></p></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($modal_owner['VN_Name']): ?>
                <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-6">
                    <h3 class="font-bold text-blue-800 mb-3"><i class="fas fa-store mr-2"></i>ຂໍ້ມູນສະຖານທີ່</h3>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <?php foreach ([
                            ['label'=>'ຊື່ສະຖານທີ່','value'=>$modal_owner['VN_Name']],
                            ['label'=>'ສະຖານະ',    'value'=>$modal_owner['VN_Status']],
                            ['label'=>'ທີ່ຢູ່',    'value'=>$modal_owner['VN_Address']],
                            ['label'=>'ເວລາ',      'value'=>date('H:i', strtotime($modal_owner['Open_time'])) . ' - ' . date('H:i', strtotime($modal_owner['Close_time']))],
                            ['label'=>'ລາຄາ/ຊມ',  'value'=>'₭'.number_format(preg_replace('/[^0-9]/', '', $modal_owner['Price_per_hour']))],
                            ['label'=>'ເດີ່ນ',     'value'=>$modal_owner['total_courts'].' ເດີ່ນ'],
                            ['label'=>'ການຈອງ',   'value'=>$modal_owner['total_bookings'].' ຢືນຢັນ'],
                        ] as $f): ?>
                            <div class="bg-white rounded-xl p-2.5">
                                <p class="text-xs text-gray-400"><?= $f['label'] ?></p>
                                <p class="font-semibold text-gray-700 text-sm"><?= htmlspecialchars($f['value']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-6">
                <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-box text-purple-500 mr-2"></i>ປະຫວັດແພັກເກດ</h3>
                <?php if (!empty($modal_packages)): ?>
                    <div class="space-y-2">
                        <?php foreach ($modal_packages as $pkg):
                            $pc = match($pkg['Status_Package']) { 'Active'=>'green','Pending'=>'yellow','Rejected'=>'red', default=>'gray' };
                            $pl = match($pkg['Status_Package']) { 'Active'=>'ກຳລັງໃຊ້ງານ','Pending'=>'ລໍຖ້າ','Rejected'=>'ຖືກປະຕິເສດ', default=>$pkg['Status_Package'] };
                        ?>
                            <div class="flex items-center justify-between bg-gray-50 rounded-xl px-4 py-3 text-sm">
                                <div class="flex items-center gap-3">
                                    <div class="bg-<?= $pc ?>-100 p-1.5 rounded-lg"><i class="fas fa-box text-<?= $pc ?>-500 text-xs"></i></div>
                                    <div>
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($pkg['Package_duration']) ?></p>
                                        <p class="text-gray-400 text-xs"><?= date('d/m/Y', strtotime($pkg['Package_date'])) ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-gray-700">₭<?= number_format($pkg['Price']) ?></p>
                                    <span class="bg-<?= $pc ?>-100 text-<?= $pc ?>-700 text-xs font-bold px-2 py-0.5 rounded-full"><?= $pl ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 bg-gray-50 rounded-xl"><p class="text-gray-400 text-sm">ບໍ່ມີປະຫວັດແພັກເກດ</p></div>
                <?php endif; ?>
            </div>

            <div class="mb-6">
                <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-bullhorn text-blue-500 mr-2"></i>ປະຫວັດໂຄສະນາ</h3>
                <?php if (!empty($modal_ads)): ?>
                    <div class="space-y-2">
                        <?php foreach ($modal_ads as $ad):
                            $ac = match($ad['Status_AD']) { 'Approved','Active'=>'blue','Pending'=>'yellow','Rejected'=>'red', default=>'gray' };
                            $al = match($ad['Status_AD']) { 'Approved','Active'=>'ກຳລັງໃຊ້ງານ','Pending'=>'ລໍຖ້າ','Rejected'=>'ຖືກປະຕິເສດ', default=>$ad['Status_AD'] };
                        ?>
                            <div class="flex items-center justify-between bg-gray-50 rounded-xl px-4 py-3 text-sm">
                                <div class="flex items-center gap-3">
                                    <div class="bg-<?= $ac ?>-100 p-1.5 rounded-lg"><i class="fas fa-bullhorn text-<?= $ac ?>-500 text-xs"></i></div>
                                    <div>
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($ad['Duration']) ?></p>
                                        <p class="text-gray-400 text-xs"><?= date('d/m/Y', strtotime($ad['AD_date'])) ?><?php if ($ad['End_time']): ?> · ຮອດ <?= date('d/m/Y', strtotime($ad['End_time'])) ?><?php endif; ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-gray-700">₭<?= number_format($ad['Price']) ?></p>
                                    <span class="bg-<?= $ac ?>-100 text-<?= $ac ?>-700 text-xs font-bold px-2 py-0.5 rounded-full"><?= $al ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 bg-gray-50 rounded-xl"><p class="text-gray-400 text-sm">ບໍ່ມີປະຫວັດໂຄສະນາ</p></div>
                <?php endif; ?>
            </div>

            <div class="pt-4 border-t border-gray-100">
                <?php if ($modal_owner['Status'] === 'Banned'): ?>
                    <form method="POST"><input type="hidden" name="ca_id" value="<?= $modal_owner['CA_ID'] ?>"><input type="hidden" name="action" value="unban">
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                            <i class="fas fa-check-circle mr-2"></i>ຍົກເລີກການລະງັບ
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" onsubmit="return confirm('ລະງັບ <?= htmlspecialchars(addslashes($modal_owner["Name"])) ?>?')">
                        <input type="hidden" name="ca_id" value="<?= $modal_owner['CA_ID'] ?>"><input type="hidden" name="action" value="ban">
                        <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-600 font-bold py-3 rounded-xl border border-red-200 transition">
                            <i class="fas fa-ban mr-2"></i>ລະງັບເຈົ້າຂອງນີ້
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