<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id = $_SESSION['ca_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM Venue_data WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $venue = $stmt->fetch();
} catch (PDOException $e) {
    $venue = null;
}

if (!$venue) {
    header('Location: /Badminton_court_Booking/owner/manage_court/index.php');
    exit;
}

$vn_id = $venue['VN_ID'];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM package WHERE CA_ID = ? AND Status_Package = 'Active' AND End_time > NOW()");
    $stmt->execute([$ca_id]);
    $has_package = $stmt->fetchColumn() > 0;
} catch (PDOException $e) {
    $has_package = false;
}

try {
    $stmt = $pdo->query("SELECT *, COALESCE(Is_Popular,0) AS Is_Popular, COALESCE(Is_Best_Value,0) AS Is_Best_Value FROM advertisement_rate ORDER BY Price ASC");
    $rates = $stmt->fetchAll();
} catch (PDOException $e) {
    $rates = [];
}

try {
    $stmt = $pdo->prepare("SELECT ad.*, r.Duration, r.Price FROM advertisement ad INNER JOIN advertisement_rate r ON ad.AD_Rate_ID = r.AD_Rate_ID WHERE ad.VN_ID = ? AND ad.Status_AD IN ('Active','Approved') AND ad.Start_time <= NOW() AND ad.End_time > NOW() ORDER BY ad.End_time ASC");
    $stmt->execute([$vn_id]);
    $active_ads = $stmt->fetchAll();
} catch (PDOException $e) {
    $active_ads = [];
}

try {
    $stmt = $pdo->prepare("SELECT ad.*, r.Duration, r.Price FROM advertisement ad INNER JOIN advertisement_rate r ON ad.AD_Rate_ID = r.AD_Rate_ID WHERE ad.VN_ID = ? AND ad.Status_AD IN ('Active','Approved') AND ad.Start_time > NOW() ORDER BY ad.Start_time ASC");
    $stmt->execute([$vn_id]);
    $queued_ads = $stmt->fetchAll();
} catch (PDOException $e) {
    $queued_ads = [];
}

try {
    $stmt = $pdo->prepare("SELECT ad.*, r.Duration, r.Price FROM advertisement ad INNER JOIN advertisement_rate r ON ad.AD_Rate_ID = r.AD_Rate_ID WHERE ad.VN_ID = ? AND ad.Status_AD = 'Pending' ORDER BY ad.AD_date DESC");
    $stmt->execute([$vn_id]);
    $pending_ads = $stmt->fetchAll();
} catch (PDOException $e) {
    $pending_ads = [];
}

try {
    $stmt = $pdo->prepare("SELECT ad.*, r.Duration, r.Price FROM advertisement ad INNER JOIN advertisement_rate r ON ad.AD_Rate_ID = r.AD_Rate_ID WHERE ad.VN_ID = ? ORDER BY ad.AD_date DESC");
    $stmt->execute([$vn_id]);
    $all_ads = $stmt->fetchAll();
} catch (PDOException $e) {
    $all_ads = [];
}

// Read rejection reason from approve_advertisement table
$rejection = null;
try {
    $stmt = $pdo->prepare("
        SELECT ap.Reject_reason AS message, ap.actioned_at AS created_at
        FROM approve_advertisement ap
        INNER JOIN advertisement ad ON ap.AD_ID = ad.AD_ID
        WHERE ad.VN_ID = ? AND ap.Action = 'Rejected'
        AND ad.Status_AD = 'Rejected'
        ORDER BY ap.actioned_at DESC LIMIT 1
    ");
    $stmt->execute([$vn_id]);
    $rejection = $stmt->fetch() ?: null;
} catch (PDOException $e) {
    $rejection = null;
}
?>
<!DOCTYPE html>
<html lang="lo">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ໂຄສະນາ - Badminton Booking Court</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .rate-card {
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid #e5e7eb;
        }

        .rate-card:hover {
            border-color: #2563eb;
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(37, 99, 235, 0.15);
        }

        .rate-card.selected {
            border-color: #2563eb;
            background: #eff6ff;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.2);
        }

        .rate-card.best {
            border-color: #f59e0b;
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
                        <h1 class="text-xl font-bold text-gray-800">ໂຄສະນາ</h1>
                        <p class="text-sm text-gray-500">ໂຄສະນາເດີ່ນຂອງທ່ານໃນໜ້າຫຼັກ</p>
                    </div>
                    <?php if (!empty($active_ads) || !empty($queued_ads)): ?>
                        <div class="flex items-center gap-2 bg-green-50 border border-green-200 px-4 py-2 rounded-xl text-sm">
                            <i class="fas fa-bullhorn text-green-500"></i>
                            <span class="text-green-700 font-semibold"><?= count($active_ads) ?> ກຳລັງໃຊ້ງານຢູ່<?= !empty($queued_ads) ? ', ' . count($queued_ads) . ' ລໍຖ້າ' : '' ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </header>

            <main class="flex-1 p-6 max-w-5xl mx-auto w-full">

                <!-- Rejection -->
                <?php if ($rejection): ?>
                    <div class="mb-6 bg-red-50 border-2 border-red-300 rounded-2xl p-5 flex items-start gap-4">
                        <div class="bg-red-100 p-3 rounded-full flex-shrink-0">
                            <i class="fas fa-times-circle text-red-500 text-xl"></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-2">
                                <p class="font-extrabold text-red-700">ໂຄສະນາຖືກປະຕິເສດ</p>
                                <span class="bg-red-100 text-red-600 text-xs font-bold px-2 py-0.5 rounded-full">ຕ້ອງດຳເນີນການ</span>
                            </div>
                            <div class="bg-white border border-red-200 rounded-xl px-4 py-3 mb-2">
                                <p class="text-xs font-bold text-red-500 mb-1"><i class="fas fa-user-shield mr-1"></i>ເຫດຜົນຈາກແອດມິນ:</p>
                                <p class="text-sm text-gray-700"><?= nl2br(htmlspecialchars($rejection['message'])) ?></p>
                            </div>
                            <p class="text-xs text-gray-400 mb-2"><i class="fas fa-clock mr-1"></i><?= date('d/m/Y \ເວລາ g:i A', strtotime($rejection['created_at'])) ?></p>
                            <p class="text-sm text-red-600 font-medium mb-3"><i class="fas fa-exclamation-circle mr-1"></i>ກະລຸນາສົ່ງຫຼັກຖານການໂອນໃໝ່ສຳລັບຄຳຂໍເດີມ.</p>
                            <?php
                            // Get the rejected ad ID to resubmit
                            try {
                                $rej_stmt = $pdo->prepare("SELECT ad.AD_ID, ad.AD_Rate_ID FROM advertisement ad INNER JOIN Venue_data v ON ad.VN_ID = v.VN_ID WHERE v.CA_ID = ? AND ad.Status_AD = 'Rejected' ORDER BY ad.AD_date DESC LIMIT 1");
                                $rej_stmt->execute([$ca_id]);
                                $rej_ad = $rej_stmt->fetch();
                            } catch (PDOException $e) {
                                $rej_ad = null;
                            }
                            ?>
                            <?php if ($rej_ad): ?>
                                <a href="/Badminton_court_Booking/owner/advertisement/payment.php?rate_id=<?= $rej_ad['AD_Rate_ID'] ?>&resubmit=<?= $rej_ad['AD_ID'] ?>"
                                    class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition shadow">
                                    <i class="fas fa-upload"></i>ອັບໂຫລດຫຼັກຖານການໂອນໃໝ່
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- No package -->
                <?php if (!$has_package): ?>
                    <div class="bg-red-50 border border-red-200 rounded-2xl p-6 mb-6 flex items-start gap-4">
                        <i class="fas fa-lock text-red-400 text-2xl flex-shrink-0 mt-0.5"></i>
                        <div>
                            <h3 class="font-bold text-red-700 mb-1">ຕ້ອງການແພັກເກດທີ່ໃຊ້ງານໄດ້</h3>
                            <p class="text-red-600 text-sm mb-3">ທ່ານຕ້ອງມີແພັກເກດທີ່ໃຊ້ງານໄດ້ກ່ອນຊື້ໂຄສະນາ.</p>
                            <a href="/Badminton_court_Booking/owner/package_rental/index.php"
                                class="inline-block bg-red-600 text-white px-4 py-2 rounded-xl text-sm font-bold hover:bg-red-700 transition">
                                <i class="fas fa-box mr-1"></i>ຊື້ແພັກເກດ
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Currently Running -->
                <?php if (!empty($active_ads)): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                        <h2 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-circle text-green-500 mr-2 text-xs"></i>ກຳລັງໃຊ້ງານຢູ່</h2>
                        <div class="space-y-3">
                            <?php foreach ($active_ads as $ad):
                                $days_left  = ceil((strtotime($ad['End_time']) - time()) / 86400);
                                $total_days = max(1, (strtotime($ad['End_time']) - strtotime($ad['Start_time'])) / 86400);
                                $pct        = min(100, max(2, ($days_left / $total_days) * 100));
                            ?>
                                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <div>
                                            <p class="font-bold text-gray-800"><?= htmlspecialchars($ad['Duration']) ?></p>
                                            <p class="text-xs text-gray-500"><?= date('d/m/Y', strtotime($ad['Start_time'])) ?> → <?= date('d/m/Y', strtotime($ad['End_time'])) ?></p>
                                        </div>
                                        <div class="text-right">
                                            <span class="bg-green-100 text-green-700 text-xs font-bold px-3 py-1 rounded-full">ໃຊ້ງານໄດ້</span>
                                            <p class="text-xs text-gray-500 mt-1">₭<?= number_format($ad['Price']) ?></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3 text-xs">
                                        <div class="flex-1 bg-blue-100 rounded-full h-2">
                                            <div class="bg-blue-500 h-2 rounded-full" style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <span class="font-bold <?= $days_left <= 3 ? 'text-orange-500' : 'text-blue-600' ?>"><?= $days_left ?> ມື້ທີ່ເຫຼືອ</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Queued -->
                <?php if (!empty($queued_ads)): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                        <h2 class="text-lg font-bold text-gray-800 mb-4">
                            <i class="fas fa-hourglass-half text-purple-500 mr-2 text-xs"></i>ໂຄສະນານີ້ຈະເລີ່ມຫຼັງຈາກໂຄສະນາປັດຈຸບັນໝົດ
                        </h2>
                        <div class="space-y-3">
                            <?php foreach ($queued_ads as $ad):
                                $days_until = ceil((strtotime($ad['Start_time']) - time()) / 86400);
                            ?>
                                <div class="bg-purple-50 border border-purple-200 rounded-xl p-4">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="font-bold text-gray-800"><?= htmlspecialchars($ad['Duration']) ?></p>
                                            <p class="text-xs text-gray-500">ເລີ່ມ: <?= date('d/m/Y', strtotime($ad['Start_time'])) ?> → <?= date('d/m/Y', strtotime($ad['End_time'])) ?></p>
                                        </div>
                                        <div class="text-right">
                                            <span class="bg-purple-100 text-purple-700 text-xs font-bold px-3 py-1 rounded-full">
                                                <i class="fas fa-hourglass-half mr-1"></i>ລໍຖ້າ
                                            </span>
                                            <p class="text-xs text-purple-600 mt-1 font-semibold">ເລີ່ມໃນ <?= $days_until ?> ມື້</p>
                                            <p class="text-xs text-gray-400">₭<?= number_format($ad['Price']) ?></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Pending -->
                <?php if (!empty($pending_ads)): ?>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-2xl p-5 mb-6">
                        <h3 class="font-bold text-yellow-800 mb-3"><i class="fas fa-clock text-yellow-500 mr-2"></i>ລໍຖ້າການອະນຸມັດ</h3>
                        <div class="space-y-2">
                            <?php foreach ($pending_ads as $pad): ?>
                                <div class="bg-white rounded-xl px-4 py-3 flex items-center justify-between text-sm">
                                    <div>
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($pad['Duration']) ?></p>
                                        <p class="text-gray-400 text-xs">ສົ່ງວັນທີ: <?= date('d/m/Y g:i A', strtotime($pad['AD_date'])) ?></p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-bold text-gray-700">₭<?= number_format($pad['Price']) ?></p>
                                        <span class="bg-yellow-100 text-yellow-700 text-xs font-bold px-2 py-0.5 rounded-full">ລໍຖ້າ</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Why Advertise -->
                <div class="bg-gradient-to-r from-blue-600 to-indigo-600 rounded-2xl p-6 mb-6 text-white">
                    <h2 class="text-xl font-bold mb-4"><i class="fas fa-rocket mr-2"></i>ເປັນຫຍັງຕ້ອງໂຄສະນາ?</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php foreach (
                            [
                                ['fa-star',       'ໄດ້ຮັບ ປ້າຍ ແນະນຳ',   'ເດີ່ນຂອງທ່ານຈະໄດ້ປ້າຍທີ່ຈະສະແດງໃຫ້ລູກຄ້າທຸກຄົນເຫັນ'],
                                ['fa-home',       'ສະແດງໃນໜ້າຫຼັກ',       'ສະແດງໃນສ່ວນ "ເດີ່ນແນະນຳ" ໃນໜ້າຫຼັກ'],
                                ['fa-chart-line', 'ການຈອງເພີ່ມຂຶ້ນ',      'ເດີ່ນຂອງທ່ານໄດ້ຮັບຄວາມສົນໃຈ ແລະ ການຈອງຫຼາຍຂຶ້ນ'],
                            ] as [$icon, $title, $desc]
                        ): ?>
                            <div class="bg-white bg-opacity-15 rounded-xl p-4">
                                <i class="fas <?= $icon ?> text-yellow-300 text-xl mb-2 block"></i>
                                <p class="font-bold text-sm mb-1"><?= $title ?></p>
                                <p class="text-blue-100 text-xs"><?= $desc ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Plan Selection -->
                <?php if ($has_package): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-2">
                            <i class="fas fa-tags text-blue-500 mr-2"></i>
                            <?= $rejection ? 'ສົ່ງໃໝ່' : 'ເລືອກລາຍການໂຄສະນາ' ?>
                        </h2>
                        <p class="text-gray-500 text-sm mb-6">ເລືອກໄລຍະເວລາ ແລ້ວຄລິກ ດຳເນີນການຈ່າຍ.</p>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
                            <?php foreach ($rates as $rate):
                                $is_popular = !empty($rate['Is_Popular']);
                                $is_best    = !empty($rate['Is_Best_Value']);
                                preg_match('/(\d+)/', $rate['Duration'], $m);
                                $num = intval($m[1] ?? 1);
                                if (str_contains($rate['Duration'], 'ອາທິດ')) {
                                    $days = $num * 7;
                                } elseif (str_contains($rate['Duration'], 'ປີ')) {
                                    $days = $num * 365;
                                } elseif (str_contains($rate['Duration'], 'ເດືອນ')) {
                                    $days = $num * 30;
                                } else {
                                    $days = $num * 7;
                                }
                            ?>
                                <div class="rate-card rounded-2xl p-4 text-center relative <?= $is_popular ? 'popular' : ($is_best ? 'best' : '') ?>"
                                    onclick="selectRate(<?= $rate['AD_Rate_ID'] ?>, '<?= htmlspecialchars($rate['Duration']) ?>', <?= $rate['Price'] ?>, this)">
                                    <?php if ($is_popular): ?><div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-full whitespace-nowrap">ນິຍົມ</div><?php endif; ?>
                                    <?php if ($is_best): ?><div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-yellow-500 text-white text-xs font-bold px-3 py-1 rounded-full whitespace-nowrap">ຄຸ້ມທີ່ສຸດ</div><?php endif; ?>
                                    <div class="bg-blue-100 w-10 h-10 rounded-full flex items-center justify-center mx-auto mb-2">
                                        <i class="fas fa-<?= $is_best ? 'crown' : ($is_popular ? 'star' : 'bullhorn') ?> text-blue-600"></i>
                                    </div>
                                    <p class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($rate['Duration']) ?></p>
                                    <p class="text-2xl font-extrabold text-blue-600 mt-1">₭<?= number_format($rate['Price']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="noSelection" class="text-center py-3 text-gray-400 text-sm">
                            <i class="fas fa-hand-pointer mr-1"></i>ເລືອກລາຍການໂຄສະນາຂ້າງເທິງເພື່ອດຳເນີນການ
                        </div>
                        <div id="proceedSection" class="hidden">
                            <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4 flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <i class="fas fa-check-circle text-blue-500 text-xl"></i>
                                    <div>
                                        <p class="font-bold text-blue-800" id="selectedDuration"></p>
                                        <p class="text-blue-600 text-sm" id="selectedPrice"></p>
                                    </div>
                                </div>
                                <button onclick="clearSelection()" class="text-gray-400 hover:text-gray-600 text-xs">
                                    <i class="fas fa-times"></i> ປ່ຽນ
                                </button>
                            </div>
                            <a id="proceedBtn" href="#"
                                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-xl transition shadow-lg text-lg flex items-center justify-center gap-2">
                                <i class="fas fa-arrow-right"></i>ດຳເນີນການຈ່າຍ
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- History -->
                <?php if (!empty($all_ads)): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h2 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-history text-gray-500 mr-2"></i>ປະຫວັດໂຄສະນາ</h2>
                        <div class="space-y-3">
                            <?php foreach ($all_ads as $ad):
                                $color = match ($ad['Status_AD']) {
                                    'Active', 'Approved' => 'blue',
                                    'Pending' => 'yellow',
                                    'Rejected' => 'red',
                                    default => 'gray'
                                };
                                $label = match ($ad['Status_AD']) {
                                    'Active', 'Approved' => 'ໃຊ້ງານໄດ້',
                                    'Pending' => 'ລໍຖ້າ',
                                    'Rejected' => 'ຖືກປະຕິເສດ',
                                    default => $ad['Status_AD']
                                };
                            ?>
                                <div class="flex items-center justify-between bg-gray-50 rounded-xl px-4 py-3 text-sm">
                                    <div class="flex items-center gap-3">
                                        <div class="bg-<?= $color ?>-100 p-2 rounded-lg">
                                            <i class="fas fa-bullhorn text-<?= $color ?>-500"></i>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($ad['Duration']) ?></p>
                                            <p class="text-gray-400 text-xs">
                                                <?= date('d/m/Y', strtotime($ad['AD_date'])) ?>
                                                <?= $ad['End_time'] ? ' · ຮອດ ' . date('d/m/Y', strtotime($ad['End_time'])) : '' ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-bold text-gray-700">₭<?= number_format($ad['Price']) ?></p>
                                        <span class="bg-<?= $color ?>-100 text-<?= $color ?>-700 text-xs font-bold px-2 py-0.5 rounded-full"><?= $label ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>
    <script>
        function selectRate(id, duration, price, card) {
            document.querySelectorAll('.rate-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            document.getElementById('selectedDuration').textContent = duration + ' ໂຄສະນາ';
            document.getElementById('selectedPrice').textContent = '₭' + parseInt(price).toLocaleString() + ' ລວມ';
            document.getElementById('proceedBtn').href = 'payment.php?rate_id=' + id;
            document.getElementById('noSelection').classList.add('hidden');
            document.getElementById('proceedSection').classList.remove('hidden');
        }

        function clearSelection() {
            document.querySelectorAll('.rate-card').forEach(c => c.classList.remove('selected'));
            document.getElementById('noSelection').classList.remove('hidden');
            document.getElementById('proceedSection').classList.add('hidden');
        }
    </script>
</body>

</html>