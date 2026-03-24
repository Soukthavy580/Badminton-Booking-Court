<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id = $_SESSION['ca_id'];

// FIX: Fetch notifications from the 3 source tables directly — no owner_notification needed
$notifications = [];
try {
    // Rejected packages — read reason from approve_package
    $s = $pdo->prepare("
        SELECT 'package' AS type, bp.Package_ID AS ref_id,
               ap.Reject_reason AS message, ap.actioned_at AS created_at,
               'ການຈ່າຍເງິນແພັກເກດຖືກປະຕິເສດ' AS title
        FROM package bp
        INNER JOIN approve_package ap ON ap.Package_ID = bp.Package_ID AND ap.Action = 'Rejected'
        WHERE bp.CA_ID = ? AND bp.Status_Package = 'Rejected'
        ORDER BY ap.actioned_at DESC
    ");
    $s->execute([$ca_id]);
    $notifications = array_merge($notifications, $s->fetchAll());

    // Rejected advertisements — read reason from approve_advertisement
    $s = $pdo->prepare("
        SELECT 'advertisement' AS type, ad.AD_ID AS ref_id,
               ap.Reject_reason AS message, ap.actioned_at AS created_at,
               'ໂຄສະນາຖືກປະຕິເສດ' AS title
        FROM advertisement ad
        INNER JOIN approve_advertisement ap ON ap.AD_ID = ad.AD_ID AND ap.Action = 'Rejected'
        INNER JOIN Venue_data v ON ad.VN_ID = v.VN_ID
        WHERE v.CA_ID = ? AND ad.Status_AD = 'Rejected'
        ORDER BY ap.actioned_at DESC
    ");
    $s->execute([$ca_id]);
    $notifications = array_merge($notifications, $s->fetchAll());

    // Rejected/inactive venues
    $s = $pdo->prepare("
        SELECT 'venue' AS type, VN_ID AS ref_id,
               Reject_reason AS message, NOW() AS created_at,
               'ໃບສະໝັກສະຖານທີ່ຖືກປະຕິເສດ' AS title
        FROM Venue_data
        WHERE CA_ID = ? AND VN_Status = 'Inactive' AND Reject_reason IS NOT NULL
    ");
    $s->execute([$ca_id]);
    $notifications = array_merge($notifications, $s->fetchAll());
} catch (PDOException $e) { $notifications = []; }

$type_config = [
    'package'       => ['icon'=>'fa-box',   'color'=>'purple','label'=>'ແພັກເກດ',  'link'=>'/Badminton_court_Booking/owner/package_rental/index.php', 'action'=>'ຊື້ແພັກເກດໃໝ່'],
    'advertisement' => ['icon'=>'fa-ad',    'color'=>'blue',  'label'=>'ໂຄສະນາ',   'link'=>'/Badminton_court_Booking/owner/advertisement/index.php',  'action'=>'ສົ່ງໂຄສະນາໃໝ່'],
    'venue'         => ['icon'=>'fa-store', 'color'=>'green', 'label'=>'ສະຖານທີ່', 'link'=>'/Badminton_court_Booking/owner/manage_court/index.php',   'action'=>'ແກ້ໄຂ ແລະ ສົ່ງໃໝ່'],
];
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ການແຈ້ງເຕືອນ - Badminton Booking Court</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notif-card { transition: all 0.3s ease; }
        .notif-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        @keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:0.3} }
        .pulse { animation: pulse-dot 1.8s infinite; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">ການແຈ້ງເຕືອນ</h1>
                    <p class="text-sm text-gray-500">ການແຈ້ງເຕືອນຈາກແອດມິນເພື່ອແກ້ໄຂຂໍ້ມູນທີ່ຜິດພາດ</p>
                </div>
                <?php if (!empty($notifications)): ?>
                    <span class="bg-red-500 text-white font-bold px-4 py-2 rounded-full text-sm">
                        <?= count($notifications) ?> ລາຍການ
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-1 p-6 max-w-3xl mx-auto w-full">

            <?php if (!empty($notifications)): ?>
                <div class="space-y-4">
                    <?php foreach ($notifications as $n):
                        $cfg = $type_config[$n['type']] ?? $type_config['package'];
                    ?>
                        <div class="notif-card bg-red-50 border-l-4 border-red-400 rounded-2xl p-5 shadow-sm relative">
                            <span class="absolute top-5 right-5 w-3 h-3 bg-red-500 rounded-full pulse"></span>
                            <div class="flex items-start gap-4">
                                <div class="bg-red-100 p-3 rounded-full flex-shrink-0">
                                    <i class="fas fa-times-circle text-red-500 text-xl"></i>
                                </div>
                                <div class="flex-1 min-w-0 pr-4">
                                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                                        <h3 class="font-bold text-gray-800"><?= htmlspecialchars($n['title']) ?></h3>
                                        <span class="bg-<?= $cfg['color'] ?>-100 text-<?= $cfg['color'] ?>-700 text-xs font-bold px-2 py-0.5 rounded-full">
                                            <i class="fas <?= $cfg['icon'] ?> mr-1"></i><?= $cfg['label'] ?>
                                        </span>
                                    </div>
                                    <div class="bg-white border border-red-200 rounded-xl p-4 mb-3">
                                        <p class="text-xs font-bold text-red-500 mb-1.5">
                                            <i class="fas fa-user-shield mr-1"></i>ເຫດຜົນຈາກແອດມິນ:
                                        </p>
                                        <p class="text-sm text-gray-700 leading-relaxed">
                                            <?= nl2br(htmlspecialchars($n['message'])) ?>
                                        </p>
                                    </div>
                                    <p class="text-xs text-gray-400 mb-3">
                                        <i class="fas fa-clock mr-1"></i>ໄດ້ຮັບ <?= date('d/m/Y', strtotime($n['created_at'])) ?>
                                    </p>
                                    <a href="<?= $cfg['link'] ?>"
                                       class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold px-5 py-2.5 rounded-xl transition shadow-sm">
                                        <i class="fas fa-edit"></i><?= $cfg['action'] ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-double text-green-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-700 mb-2">ທຸກຢ່າງດີ!</h3>
                    <p class="text-gray-400 text-sm">ບໍ່ມີການແຈ້ງເຕືອນຈາກແອດມິນ.</p>
                    <p class="text-gray-300 text-xs mt-1">ຖ້າແອດມິນປະຕິເສດ ເຫດຜົນຈະສະແດງຢູ່ນີ້ຈົນກວ່າທ່ານຈະສົ່ງໃໝ່.</p>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>
</body>
</html>