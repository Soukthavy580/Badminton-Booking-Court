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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vn_id          = intval($_POST['vn_id'] ?? 0);
    $action         = $_POST['action'] ?? '';
    $comment        = trim($_POST['comment'] ?? '');
    $flagged_fields = $_POST['flagged_fields'] ?? [];

    if ($vn_id && in_array($action, ['approve', 'reject', 'inactive', 'maintaining'])) {
        try {
            $v = $pdo->prepare("SELECT CA_ID, VN_Name FROM Venue_data WHERE VN_ID=?");
            $v->execute([$vn_id]);
            $venue_row   = $v->fetch();
            $owner_ca_id = $venue_row['CA_ID'] ?? 0;

            switch ($action) {
                case 'approve':
                    $pdo->prepare("UPDATE Venue_data SET VN_Status='Active', Reject_reason=NULL WHERE VN_ID=?")
                        ->execute([$vn_id]);
                    $success = 'ອະນຸມັດສະຖານທີ່ສຳເລັດ! ສະຖານທີ່ໃຊ້ງານໄດ້ແລ້ວ.';
                    break;

                case 'reject':
                    if (empty($comment)) {
                        $error = 'ກະລຸນາຂຽນຂໍ້ຄວາມໄປຫາເຈົ້າຂອງ.';
                    } else {
                        $pdo->prepare("UPDATE Venue_data SET VN_Status='Inactive', Reject_reason=? WHERE VN_ID=?")
                            ->execute([$comment, $vn_id]);
                        $success = 'ປະຕິເສດສະຖານທີ່ສຳເລັດ. ເຈົ້າຂອງຈະເຫັນໃນການແຈ້ງເຕືອນ.';
                    }
                    break;

                case 'inactive':
                    $pdo->prepare("UPDATE Venue_data SET VN_Status='Inactive' WHERE VN_ID=?")->execute([$vn_id]);
                    $success = 'ຕັ້ງສະຖານທີ່ເປັນ "ປິດໃຊ້ງານ" ສຳເລັດ.';
                    break;

                case 'maintaining':
                    $pdo->prepare("UPDATE Venue_data SET VN_Status='Maintaining' WHERE VN_ID=?")->execute([$vn_id]);
                    $success = 'ຕັ້ງສະຖານທີ່ເປັນ "ກຳລັງສ້ອມແປງ" ສຳເລັດ.';
                    break;
            }
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ: ' . $e->getMessage();
        }
    }
}

function get_venues($pdo, $filter)
{
    try {
        $where = match ($filter) {
            'pending'    => "WHERE v.VN_Status = 'Pending'",
            'active'     => "WHERE v.VN_Status = 'Active'",
            'inactive'   => "WHERE v.VN_Status = 'Inactive'",
            'maintaining' => "WHERE v.VN_Status = 'Maintaining'",
            default      => ''
        };
        $stmt = $pdo->query("
            SELECT v.*, co.Name AS owner_name, co.Email AS owner_email, co.Phone AS owner_phone, co.CA_ID,
                (SELECT COUNT(*) FROM Court_data WHERE VN_ID = v.VN_ID) AS total_courts
            FROM Venue_data v
            INNER JOIN court_owner co ON v.CA_ID = co.CA_ID
            $where ORDER BY v.VN_ID DESC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function count_venues($pdo, $status)
{
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM Venue_data WHERE VN_Status = '$status'")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

$venues = get_venues($pdo, $filter);
$counts = [
    'pending'    => count_venues($pdo, 'Pending'),
    'active'     => count_venues($pdo, 'Active'),
    'inactive'   => count_venues($pdo, 'Inactive'),
    'maintaining' => count_venues($pdo, 'Maintaining'),
];

$modal_venue = null;
if (isset($_GET['view'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT v.*, co.Name AS owner_name, co.Email AS owner_email, co.Phone AS owner_phone,
                (SELECT COUNT(*) FROM Court_data WHERE VN_ID = v.VN_ID) AS total_courts,
                (SELECT GROUP_CONCAT(Fac_Name SEPARATOR ', ') FROM facilities WHERE VN_ID = v.VN_ID) AS facility_list
            FROM Venue_data v INNER JOIN court_owner co ON v.CA_ID = co.CA_ID
            WHERE v.VN_ID = ?
        ");
        $stmt->execute([intval($_GET['view'])]);
        $modal_venue = $stmt->fetch();
    } catch (PDOException $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="lo">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ການອະນຸມັດສະຖານທີ່ - Admin</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
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

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open {
            display: flex;
        }

        .reject-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 110;
            align-items: center;
            justify-content: center;
        }

        .reject-modal.open {
            display: flex;
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
                        <h1 class="text-xl font-bold text-gray-800">ການອະນຸມັດສະຖານທີ່</h1>
                        <p class="text-sm text-gray-500">ກວດສອບ ແລະ ອະນຸມັດການລົງທະບຽນສະຖານທີ່</p>
                    </div>
                    <?php if ($counts['pending'] > 0): ?>
                        <div class="flex items-center gap-2 bg-red-50 border border-red-200 px-4 py-2 rounded-xl">
                            <i class="fas fa-exclamation-circle text-red-500"></i>
                            <span class="text-red-700 font-bold text-sm"><?= $counts['pending'] ?> ລໍຖ້າກວດສອບ</span>
                        </div>
                    <?php endif; ?>
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
                            ['label' => 'ກຳລັງໃຊ້ງານ',  'value' => $counts['active'],     'color' => 'green', 'icon' => 'fa-check-circle', 'filter' => 'active'],
                            ['label' => 'ປິດໃຊ້ງານ',  'value' => $counts['inactive'],   'color' => 'red',   'icon' => 'fa-ban',         'filter' => 'inactive'],
                            ['label' => 'ສ້ອມແປງ',    'value' => $counts['maintaining'], 'color' => 'orange', 'icon' => 'fa-tools',       'filter' => 'maintaining'],
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

                <!-- Filter Tabs -->
                <div class="flex gap-2 mb-6 flex-wrap">
                    <?php foreach (
                        [
                            'active'     => ['label' => 'ກຳລັງໃຊ້ງານ',   'icon' => 'fa-check-circle', 'color' => 'green'],
                            'inactive'   => ['label' => 'ປິດໃຊ້ງານ',   'icon' => 'fa-ban',         'color' => 'red'],
                            'maintaining' => ['label' => 'ສ້ອມແປງ',     'icon' => 'fa-tools',       'color' => 'orange'],
                        ] as $key => $t
                    ): ?>
                        <a href="?filter=<?= $key ?>"
                            class="px-4 py-2 rounded-xl font-semibold text-sm transition flex items-center gap-2
                              <?= $filter === $key ? 'bg-blue-600 text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' ?>">
                            <i class="fas <?= $t['icon'] ?>"></i><?= $t['label'] ?>
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold
                                     <?= $filter === $key ? 'bg-white text-blue-600' : 'bg-' . $t['color'] . '-100 text-' . $t['color'] . '-700' ?>">
                                <?= $counts[$key] ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Venues List -->
                <?php if (!empty($venues)): ?>
                    <div class="space-y-4">
                        <?php foreach ($venues as $venue):
                            $venue_img  = !empty($venue['VN_Image']) ? '/Badminton_court_Booking/assets/images/venues/' . basename($venue['VN_Image']) : '';
                            $status_cfg = match ($venue['VN_Status']) {
                                'Active'      => ['border' => 'border-green-400', 'badge_bg' => 'bg-green-100', 'badge_text' => 'text-green-700', 'icon' => 'fa-check-circle', 'label' => 'ກຳລັງໃຊ້ງານ'],
                                'Inactive'    => ['border' => 'border-red-400',   'badge_bg' => 'bg-red-100',   'badge_text' => 'text-red-700',   'icon' => 'fa-ban',         'label' => 'ປິດໃຊ້ງານ'],
                                'Maintaining' => ['border' => 'border-orange-400', 'badge_bg' => 'bg-orange-100', 'badge_text' => 'text-orange-700', 'icon' => 'fa-tools',       'label' => 'ສ້ອມແປງ'],
                                default       => ['border' => 'border-yellow-400', 'badge_bg' => 'bg-yellow-100', 'badge_text' => 'text-yellow-700', 'icon' => 'fa-clock',       'label' => 'ລໍຖ້າ'],
                            };
                        ?>
                            <div class="venue-card bg-white rounded-2xl shadow-sm border-l-4 <?= $status_cfg['border'] ?> overflow-hidden">
                                <div class="flex flex-col md:flex-row">
                                    <div class="w-full md:w-40 h-40 flex-shrink-0 bg-gray-100">
                                        <?php if ($venue_img): ?>
                                            <img src="<?= htmlspecialchars($venue_img) ?>" class="w-full h-full object-cover"
                                                onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center\'><i class=\'fas fa-store text-4xl text-gray-300\'></i></div>'">
                                        <?php else: ?>
                                            <div class="w-full h-full flex items-center justify-center"><i class="fas fa-store text-4xl text-gray-300"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1 p-5">
                                        <div class="flex items-center gap-2 mb-1">
                                            <h3 class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($venue['VN_Name']) ?></h3>
                                            <span class="<?= $status_cfg['badge_bg'] ?> <?= $status_cfg['badge_text'] ?> text-xs font-bold px-2 py-0.5 rounded-full">
                                                <i class="fas <?= $status_cfg['icon'] ?> mr-1"></i><?= $status_cfg['label'] ?>
                                            </span>
                                        </div>
                                        <p class="text-gray-500 text-sm mb-2"><i class="fas fa-map-marker-alt mr-1 text-red-400"></i><?= htmlspecialchars($venue['VN_Address']) ?></p>
                                        <div class="flex flex-wrap gap-3 text-xs text-gray-500 mb-3">
                                            <span><i class="fas fa-user mr-1 text-purple-400"></i><?= htmlspecialchars($venue['owner_name']) ?></span>
                                            <span><i class="fas fa-envelope mr-1 text-blue-400"></i><?= htmlspecialchars($venue['owner_email']) ?></span>
                                            <span><i class="fas fa-phone mr-1 text-green-400"></i><?= htmlspecialchars($venue['owner_phone']) ?></span>
                                            <span><i class="fas fa-table-tennis mr-1 text-green-400"></i><?= $venue['total_courts'] ?> ເດີ່ນ</span>
                                            <span><i class="fas fa-clock mr-1 text-blue-400"></i><?= date('H:i', strtotime($venue['Open_time'])) ?> - <?= date('H:i', strtotime($venue['Close_time'])) ?></span>
                                            <span><i class="fas fa-money-bill mr-1 text-green-400"></i>₭<?= number_format(preg_replace('/[^0-9]/', '', $venue['Price_per_hour'])) ?>/ຊມ</span>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <a href="?filter=<?= $filter ?>&view=<?= $venue['VN_ID'] ?>"
                                                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-xl text-sm font-semibold transition">
                                                <i class="fas fa-eye mr-1"></i>ເບິ່ງລາຍລະອຽດ
                                            </a>
                                            <?php if ($venue['VN_Status'] === 'Pending'): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="vn_id" value="<?= $venue['VN_ID'] ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl text-sm font-bold transition shadow">
                                                        <i class="fas fa-check mr-1"></i>ອະນຸມັດ
                                                    </button>
                                                </form>
                                                <button onclick="openReject(<?= $venue['VN_ID'] ?>)"
                                                    class="bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2 rounded-xl text-sm font-bold transition border border-red-200">
                                                    <i class="fas fa-times mr-1"></i>ປະຕິເສດ
                                                </button>
                                            <?php elseif ($venue['VN_Status'] === 'Active'): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="vn_id" value="<?= $venue['VN_ID'] ?>">
                                                    <input type="hidden" name="action" value="maintaining">
                                                    <button type="submit" class="bg-orange-50 hover:bg-orange-100 text-orange-600 px-3 py-2 rounded-xl text-sm font-bold transition border border-orange-200">
                                                        <i class="fas fa-tools mr-1"></i>ສ້ອມແປງ
                                                    </button>
                                                </form>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="vn_id" value="<?= $venue['VN_ID'] ?>">
                                                    <input type="hidden" name="action" value="inactive">
                                                    <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-600 px-3 py-2 rounded-xl text-sm font-bold transition border border-red-200">
                                                        <i class="fas fa-ban mr-1"></i>ປິດໃຊ້ງານ
                                                    </button>
                                                </form>
                                            <?php elseif (in_array($venue['VN_Status'], ['Inactive', 'Maintaining'])): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="vn_id" value="<?= $venue['VN_ID'] ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <button type="submit" class="bg-green-50 hover:bg-green-100 text-green-700 px-4 py-2 rounded-xl text-sm font-bold transition border border-green-200">
                                                        <i class="fas fa-check-circle mr-1"></i>ຕັ້ງເປັນໃຊ້ງານໄດ້
                                                    </button>
                                                </form>
                                                <?php if ($venue['VN_Status'] === 'Inactive'): ?>
                                                    <button onclick="openReject(<?= $venue['VN_ID'] ?>)"
                                                        class="bg-gray-50 hover:bg-gray-100 text-gray-600 px-3 py-2 rounded-xl text-sm font-bold transition border border-gray-200">
                                                        <i class="fas fa-comment-alt mr-1"></i>ສົ່ງຂໍ້ຄວາມ
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <i class="fas fa-store text-6xl text-gray-200 mb-4 block"></i>
                        <h3 class="text-xl font-bold text-gray-600 mb-2">
                            <?= match ($filter) {
                                'pending' => 'ບໍ່ມີສະຖານທີ່ລໍຖ້າ',
                                'active' => 'ບໍ່ມີສະຖານທີ່ໃຊ້ງານໄດ້',
                                'inactive' => 'ບໍ່ມີສະຖານທີ່ທີ່ບໍ່ໃຊ້ງານ',
                                default => 'ບໍ່ມີສະຖານທີ່ໃນໝວດນີ້'
                            } ?>
                        </h3>
                        <p class="text-gray-400 text-sm">
                            <?= $filter === 'pending' ? 'ໃບສະໝັກທຸກໃບໄດ້ຮັບການກວດສອບແລ້ວ.' : 'ບໍ່ມີສະຖານທີ່ໃນໝວດນີ້.' ?>
                        </p>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Venue Detail Modal -->
    <?php if ($modal_venue): ?>
        <div class="modal-overlay open">
            <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
                <div class="relative">
                    <?php $modal_img = !empty($modal_venue['VN_Image']) ? '/Badminton_court_Booking/assets/images/venues/' . basename($modal_venue['VN_Image']) : ''; ?>
                    <?php if ($modal_img): ?>
                        <img src="<?= htmlspecialchars($modal_img) ?>" class="w-full h-48 object-cover rounded-t-2xl" onerror="this.style.display='none'">
                    <?php else: ?>
                        <div class="w-full h-32 bg-gradient-to-r from-blue-500 to-purple-600 rounded-t-2xl flex items-center justify-center">
                            <i class="fas fa-store text-5xl text-white opacity-50"></i>
                        </div>
                    <?php endif; ?>
                    <a href="?filter=<?= $filter ?>"
                        class="absolute top-3 right-3 bg-white text-gray-600 hover:text-gray-800 w-8 h-8 rounded-full flex items-center justify-center shadow-md transition">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
                <div class="p-6">
                    <?php
                    $ms = $modal_venue['VN_Status'];
                    $ms_label = match ($ms) {
                        'Active' => 'ກຳລັງໃຊ້ງານ',
                        'Inactive' => 'ປິດໃຊ້ງານ',
                        'Maintaining' => 'ສ້ອມແປງ',
                        'Pending' => 'ລໍຖ້າ',
                        default => $ms
                    };
                    $ms_class = match ($ms) {
                        'Active' => 'bg-green-100 text-green-700',
                        'Inactive' => 'bg-red-100 text-red-700',
                        'Maintaining' => 'bg-orange-100 text-orange-700',
                        default => 'bg-yellow-100 text-yellow-700'
                    };
                    ?>
                    <div class="flex items-center gap-2 mb-1">
                        <h2 class="text-2xl font-extrabold text-gray-800"><?= htmlspecialchars($modal_venue['VN_Name']) ?></h2>
                        <span class="<?= $ms_class ?> text-xs font-bold px-2 py-0.5 rounded-full"><?= $ms_label ?></span>
                    </div>
                    <p class="text-gray-500 text-sm mb-4"><i class="fas fa-map-marker-alt mr-1 text-red-400"></i><?= htmlspecialchars($modal_venue['VN_Address']) ?></p>

                    <?php if ($modal_venue['VN_Description']): ?>
                        <p class="text-gray-600 text-sm mb-4 bg-gray-50 rounded-xl p-3"><?= htmlspecialchars($modal_venue['VN_Description']) ?></p>
                    <?php endif; ?>

                    <div class="grid grid-cols-2 gap-3 text-sm mb-4">
                        <?php foreach (
                            [
                                ['icon' => 'fa-user',        'color' => 'purple', 'label' => 'ເຈົ້າຂອງ', 'value' => $modal_venue['owner_name']],
                                ['icon' => 'fa-envelope',    'color' => 'blue',  'label' => 'ອີເມລ໌', 'value' => $modal_venue['owner_email']],
                                ['icon' => 'fa-phone',       'color' => 'green', 'label' => 'ໂທ',      'value' => $modal_venue['owner_phone']],
                                ['icon' => 'fa-clock',       'color' => 'blue',  'label' => 'ເວລາ',    'value' => date('H:i', strtotime($modal_venue['Open_time'])) . ' - ' . date('H:i', strtotime($modal_venue['Close_time']))],
                                ['icon' => 'fa-money-bill',  'color' => 'green', 'label' => 'ລາຄາ/ຊມ', 'value' => '₭' . number_format(preg_replace('/[^0-9]/', '', $modal_venue['Price_per_hour']))],
                                ['icon' => 'fa-table-tennis', 'color' => 'green', 'label' => 'ເດີ່ນ',   'value' => $modal_venue['total_courts'] . ' ເດີ່ນ'],
                            ] as $d
                        ): ?>
                            <div class="flex items-center gap-2 bg-gray-50 rounded-xl p-3">
                                <i class="fas <?= $d['icon'] ?> text-<?= $d['color'] ?>-400 w-4"></i>
                                <div>
                                    <p class="text-xs text-gray-400"><?= $d['label'] ?></p>
                                    <p class="font-semibold text-gray-700"><?= htmlspecialchars($d['value']) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($modal_venue['facility_list'])): ?>
                        <div class="mb-4">
                            <p class="text-xs font-bold text-gray-500 uppercase mb-2">ສິ່ງອຳນວຍຄວາມສະດວກ</p>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach (explode(', ', $modal_venue['facility_list']) as $fac): ?>
                                    <span class="bg-yellow-50 border border-yellow-200 text-yellow-700 text-xs px-3 py-1 rounded-full font-medium">
                                        <i class="fas fa-check-circle mr-1 text-yellow-500"></i><?= htmlspecialchars(trim($fac)) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Modal Actions -->
                    <?php if ($ms === 'Pending'): ?>
                        <div class="flex gap-3 pt-4 border-t border-gray-100">
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="vn_id" value="<?= $modal_venue['VN_ID'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                                    <i class="fas fa-check mr-2"></i>ອະນຸມັດສະຖານທີ່
                                </button>
                            </form>
                            <button onclick="openReject(<?= $modal_venue['VN_ID'] ?>)"
                                class="flex-1 bg-red-50 hover:bg-red-100 text-red-600 font-bold py-3 rounded-xl border border-red-200 transition">
                                <i class="fas fa-times mr-2"></i>ປະຕິເສດ
                            </button>
                        </div>
                    <?php elseif ($ms === 'Active'): ?>
                        <div class="flex gap-3 pt-4 border-t border-gray-100">
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="vn_id" value="<?= $modal_venue['VN_ID'] ?>">
                                <input type="hidden" name="action" value="maintaining">
                                <button type="submit" class="w-full bg-orange-50 hover:bg-orange-100 text-orange-700 font-bold py-3 rounded-xl border border-orange-200 transition">
                                    <i class="fas fa-tools mr-2"></i>ສ້ອມແປງ
                                </button>
                            </form>
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="vn_id" value="<?= $modal_venue['VN_ID'] ?>">
                                <input type="hidden" name="action" value="inactive">
                                <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-600 font-bold py-3 rounded-xl border border-red-200 transition">
                                    <i class="fas fa-ban mr-2"></i>ປິດໃຊ້ງານ
                                </button>
                            </form>
                        </div>
                    <?php elseif (in_array($ms, ['Inactive', 'Maintaining'])): ?>
                        <div class="flex gap-3 pt-4 border-t border-gray-100">
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="vn_id" value="<?= $modal_venue['VN_ID'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                                    <i class="fas fa-check-circle mr-2"></i>ຕັ້ງເປັນໃຊ້ງານໄດ້
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Reject Modal -->
    <div class="reject-modal" id="rejectModal">
        <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-lg w-full mx-4 relative" style="max-height:90vh;overflow-y:auto">
            <button onclick="closeReject()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-bold text-gray-800 text-lg mb-1"><i class="fas fa-times-circle text-red-500 mr-2"></i>ປະຕິເສດສະຖານທີ່</h3>
            <p class="text-sm text-gray-500 mb-4">ໝາຍຂໍ້ມູນທີ່ຜິດ ແລະ ຂຽນຂໍ້ຄວາມ. ເຈົ້າຂອງຈະເຫັນໃນການແຈ້ງເຕືອນ.</p>
            <form method="POST">
                <input type="hidden" name="vn_id" id="rejectVnId">
                <input type="hidden" name="action" value="reject">
                <p class="text-sm font-bold text-gray-700 mb-2">ຂໍ້ມູນທີ່ຕ້ອງແກ້ໄຂ <span class="font-normal text-gray-400">(ທາງເລືອກ)</span>:</p>
                <div class="grid grid-cols-2 gap-2 mb-4">
                    <?php foreach (
                        [
                            'ຊື່ສະຖານທີ່',
                            'ທີ່ຢູ່',
                            'ຄຳອະທິບາຍ',
                            'ເວລາເປີດ/ປິດ',
                            'ລາຄາ',
                            'ຮູບສະຖານທີ່',
                            'QR Code',
                            'ລິ້ງ Google Maps'
                        ] as $field
                    ): ?>
                        <label class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 cursor-pointer hover:bg-orange-50 hover:border-orange-300 transition">
                            <input type="checkbox" name="flagged_fields[]" value="<?= $field ?>" class="w-4 h-4 accent-orange-500 rounded">
                            <span class="text-sm text-gray-700"><?= $field ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-sm font-bold text-gray-700 mb-2">ຂໍ້ຄວາມຫາເຈົ້າຂອງ <span class="text-red-500">*</span>:</p>
                <textarea name="comment" rows="3" required
                    placeholder="ຕົວຢ່າງ: ຮູບ QR Code ບໍ່ຊັດເຈນ. ກະລຸນາອັບໂຫລດຮູບທີ່ຊັດຂຶ້ນ."
                    class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-red-400 transition resize-none text-sm mb-4"></textarea>
                <div class="flex gap-3">
                    <button type="button" onclick="closeReject()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition">ຍົກເລີກ</button>
                    <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl transition">
                        <i class="fas fa-paper-plane mr-1"></i>ສົ່ງຫາເຈົ້າຂອງ
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openReject(id) {
            document.getElementById('rejectVnId').value = id;
            document.querySelectorAll('#rejectModal input[type=checkbox]').forEach(cb => cb.checked = false);
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
            if (e.key === 'Escape') closeReject();
        });
    </script>
</body>

</html>