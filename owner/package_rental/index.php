<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id = $_SESSION['ca_id'];

try {
    $stmt = $pdo->prepare("SELECT VN_ID FROM Venue_data WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $venue = $stmt->fetch();
    $vn_id = $venue['VN_ID'] ?? null;
} catch (PDOException $e) { $vn_id = null; }

try {
    $stmt = $pdo->prepare("SELECT bp.*, pr.Package_duration, pr.Price FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID WHERE bp.CA_ID = ? AND bp.Status_Package = 'Active' AND bp.Start_time <= NOW() AND bp.End_time > NOW() ORDER BY bp.End_time ASC LIMIT 1");
    $stmt->execute([$ca_id]);
    $active_package = $stmt->fetch();
} catch (PDOException $e) { $active_package = null; }

try {
    $stmt = $pdo->prepare("SELECT bp.*, pr.Package_duration, pr.Price FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID WHERE bp.CA_ID = ? AND bp.Status_Package = 'Active' AND bp.Start_time > NOW() ORDER BY bp.Start_time ASC");
    $stmt->execute([$ca_id]);
    $queued_packages = $stmt->fetchAll();
} catch (PDOException $e) { $queued_packages = []; }

try {
    $stmt = $pdo->prepare("SELECT bp.*, pr.Package_duration, pr.Price FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID WHERE bp.CA_ID = ? AND bp.Status_Package = 'Pending' ORDER BY bp.Package_date DESC LIMIT 1");
    $stmt->execute([$ca_id]);
    $pending_package = $stmt->fetch();
} catch (PDOException $e) { $pending_package = null; }

try {
    $stmt = $pdo->prepare("SELECT bp.*, pr.Package_duration, pr.Price FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID WHERE bp.CA_ID = ? AND bp.Status_Package = 'Active' AND bp.Start_time <= NOW() AND bp.End_time > NOW() AND bp.End_time <= DATE_ADD(NOW(), INTERVAL 3 DAY) LIMIT 1");
    $stmt->execute([$ca_id]);
    $expiring_package = $stmt->fetch();
} catch (PDOException $e) { $expiring_package = null; }

// Read rejection reason from approve_package table
$rejection = null;
try {
    $stmt = $pdo->prepare("
        SELECT ap.Reject_reason AS message, ap.actioned_at AS created_at
        FROM approve_package ap
        INNER JOIN package bp ON ap.Package_ID = bp.Package_ID
        WHERE bp.CA_ID = ? AND ap.Action = 'Rejected'
        AND bp.Status_Package = 'Rejected'   ← added
        ORDER BY ap.actioned_at DESC LIMIT 1
    ");
    $stmt->execute([$ca_id]);
    $rejection = $stmt->fetch() ?: null;
} catch (PDOException $e) { $rejection = null; }

try {
    $stmt = $pdo->query("SELECT *, COALESCE(Is_Popular,0) AS Is_Popular, COALESCE(Is_Best_Value,0) AS Is_Best_Value FROM package_rate ORDER BY Price ASC");
    $packages = $stmt->fetchAll();
} catch (PDOException $e) { $packages = []; }

try {
    $stmt = $pdo->prepare("SELECT bp.*, pr.Package_duration, pr.Price FROM package bp INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID WHERE bp.CA_ID = ? ORDER BY bp.Package_date DESC LIMIT 10");
    $stmt->execute([$ca_id]);
    $history = $stmt->fetchAll();
} catch (PDOException $e) { $history = []; }

$days_left = $active_package ? ceil((strtotime($active_package['End_time']) - time()) / 86400) : 0;
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ແພັກເກດ - Badminton Booking Court</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .pkg-card { transition: all 0.3s ease; cursor: pointer; border: 2px solid #e5e7eb; }
        .pkg-card:hover { border-color: #16a34a; transform: translateY(-4px); box-shadow: 0 12px 24px rgba(22,163,74,0.15); }
        .pkg-card.selected { border-color: #16a34a; background: #f0fdf4; box-shadow: 0 8px 20px rgba(22,163,74,0.2); }
        .pkg-card.popular { border-color: #2563eb; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">ແພັກເກດ</h1>
                    <p class="text-sm text-gray-500">ເປີດໃຊ້ງານບັນຊີເພື່ອຮັບການຈອງ</p>
                </div>
                <?php if ($active_package): ?>
                    <div class="flex items-center gap-2 bg-green-50 border border-green-200 px-4 py-2 rounded-xl text-sm">
                        <i class="fas fa-check-circle text-green-500"></i>
                        <span class="text-green-700 font-medium">ໃຊ້ງານໄດ້ · <?= $days_left ?> ມື້ທີ່ເຫຼືອ<?= !empty($queued_packages) ? ' · ' . count($queued_packages) . ' ລໍຖ້າ' : '' ?></span>
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
                            <p class="font-extrabold text-red-700">ການຊຳລະເງິນຖືກປະຕິເສດ</p>
                            <span class="bg-red-100 text-red-600 text-xs font-bold px-2 py-0.5 rounded-full">ຕ້ອງດຳເນີນການ</span>
                        </div>
                        <div class="bg-white border border-red-200 rounded-xl px-4 py-3 mb-2">
                            <p class="text-xs font-bold text-red-500 mb-1"><i class="fas fa-user-shield mr-1"></i>ເຫດຜົນຈາກແອດມິນ:</p>
                            <p class="text-sm text-gray-700"><?= nl2br(htmlspecialchars($rejection['message'])) ?></p>
                        </div>
                        <p class="text-xs text-gray-400 mb-2"><i class="fas fa-clock mr-1"></i><?= date('d/m/Y \ເວລາ g:i A', strtotime($rejection['created_at'])) ?></p>
                        <p class="text-sm text-red-600 font-medium mb-3"><i class="fas fa-exclamation-circle mr-1"></i>ກະລຸນາສົ່ງຫຼັກຖານການໂອນໃໝ່</p>
                <?php
                // Get the rejected package ID to resubmit
                try {
                    $rej_stmt = $pdo->prepare("SELECT Package_ID, Package_rate_ID FROM package WHERE CA_ID = ? AND Status_Package = 'Rejected' ORDER BY Package_date DESC LIMIT 1");
                    $rej_stmt->execute([$ca_id]);
                    $rej_pkg = $rej_stmt->fetch();
                } catch (PDOException $e) { $rej_pkg = null; }
                ?>
                <?php if ($rej_pkg): ?>
                    <a href="/Badminton_court_Booking/owner/package_rental/payment.php?pkg_id=<?= $rej_pkg['Package_rate_ID'] ?>&resubmit=<?= $rej_pkg['Package_ID'] ?>"
                       class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition shadow">
                        <i class="fas fa-upload"></i>ອັບໂຫລດຫຼັກຖານການໂອນໃໝ່
                    </a>
                <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Expiry Warning -->
            <?php if ($expiring_package):
                $exp_days = ceil((strtotime($expiring_package['End_time']) - time()) / 86400);
            ?>
                <div class="mb-6 bg-red-50 border-2 border-red-300 rounded-2xl p-5 flex items-start gap-4">
                    <div class="bg-red-100 p-3 rounded-full flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                    </div>
                    <div class="flex-1">
                        <p class="font-extrabold text-red-700 text-lg">⚠️ ແພັກເກດຈະໝົດອາຍຸ<?= $exp_days <= 1 ? 'ມື້ອື່ນ!' : "ໃນ {$exp_days} ວັນ!" ?></p>
                        <p class="text-red-600 text-sm mt-1">ແພັກເກດ <strong><?= htmlspecialchars($expiring_package['Package_duration']) ?></strong> ຈະໝົດວັນທີ <strong><?= date('d/m/Y', strtotime($expiring_package['End_time'])) ?></strong>. ຕໍ່ອາຍຸດຽວນີ້.</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Active Package -->
            <?php if ($active_package): ?>
                <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-2xl p-6 text-white mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-green-100 text-sm mb-1">ແພັກເກດທີ່ໃຊ້ຢູ່</p>
                            <h2 class="text-3xl font-extrabold"><?= htmlspecialchars($active_package['Package_duration']) ?></h2>
                            <p class="text-green-100 mt-1"><?= date('d/m/Y', strtotime($active_package['Start_time'])) ?> → <?= date('d/m/Y', strtotime($active_package['End_time'])) ?></p>
                        </div>
                        <div class="text-center bg-white bg-opacity-20 rounded-2xl p-4">
                            <p class="text-4xl font-extrabold"><?= $days_left ?></p>
                            <p class="text-green-100 text-sm">ມື້ທີ່ເຫຼືອ</p>
                        </div>
                    </div>
                </div>
                <?php if (!$vn_id): ?>
                    <div class="bg-blue-50 border-2 border-blue-300 rounded-2xl p-6 mb-6 flex items-start gap-4">
                        <div class="bg-blue-100 p-3 rounded-full flex-shrink-0">
                            <i class="fas fa-store text-blue-500 text-2xl"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-blue-800 mb-1">ແພັກເກດໃຊ້ງານໄດ້ແລ້ວ! ຕັ້ງຄ່າສະຖານທີ່</h3>
                            <p class="text-blue-600 text-sm mb-3">ສ້າງສະຖານທີ່ຂອງທ່ານເພື່ອໃຫ້ລູກຄ້າຊອກຫາ ແລະ ຈອງ.</p>
                            <a href="/Badminton_court_Booking/owner/manage_court/index.php"
                               class="inline-block bg-blue-600 text-white px-5 py-2 rounded-xl text-sm font-bold hover:bg-blue-700 transition">
                                <i class="fas fa-plus mr-1"></i>ສ້າງສະຖານທີ່
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Queued -->
            <?php if (!empty($queued_packages)): ?>
                <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-hourglass-half text-purple-500 mr-2 text-xs"></i>ລໍຖ້າ — ເລີ່ມຫຼັງແພັກເກດປັດຈຸບັນໝົດ
                    </h2>
                    <div class="space-y-3">
                        <?php foreach ($queued_packages as $qpkg):
                            $days_until = ceil((strtotime($qpkg['Start_time']) - time()) / 86400);
                        ?>
                            <div class="bg-purple-50 border border-purple-200 rounded-xl p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-bold text-gray-800"><?= htmlspecialchars($qpkg['Package_duration']) ?></p>
                                        <p class="text-xs text-gray-500">ເລີ່ມ: <?= date('d/m/Y', strtotime($qpkg['Start_time'])) ?> → <?= date('d/m/Y', strtotime($qpkg['End_time'])) ?></p>
                                    </div>
                                    <div class="text-right">
                                        <span class="bg-purple-100 text-purple-700 text-xs font-bold px-3 py-1 rounded-full">
                                            <i class="fas fa-hourglass-half mr-1"></i>ລໍຖ້າ
                                        </span>
                                        <p class="text-xs text-purple-600 mt-1 font-semibold">ເລີ່ມໃນ <?= $days_until ?> ມື້</p>
                                        <p class="text-xs text-gray-400">₭<?= number_format($qpkg['Price']) ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Pending -->
            <?php if ($pending_package): ?>
                <div class="bg-yellow-50 border border-yellow-300 rounded-2xl p-6 mb-6 flex items-start gap-4">
                    <div class="bg-yellow-100 p-3 rounded-full flex-shrink-0">
                        <i class="fas fa-clock text-yellow-500 text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-yellow-800 mb-1">ລໍຖ້າການອະນຸມັດ</h3>
                        <p class="text-yellow-700 text-sm">ແພັກເກດ <strong><?= htmlspecialchars($pending_package['Package_duration']) ?></strong> (₭<?= number_format($pending_package['Price']) ?>) ກຳລັງລໍຖ້າກວດສອບ.</p>
                        <p class="text-yellow-600 text-xs mt-1">ສົ່ງວັນທີ: <?= date('d/m/Y g:i A', strtotime($pending_package['Package_date'])) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Package Selection -->
            <?php if (!$pending_package): ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h2 class="text-xl font-bold text-gray-800 mb-2">
                    <i class="fas fa-box text-green-500 mr-2"></i>
                    <?= $active_package ? 'ຕໍ່ອາຍຸ ຫຼື ອັບເກຣດ' : ($rejection ? 'ສົ່ງໃໝ່' : 'ເລືອກແພັກເກດ') ?>
                </h2>
                <p class="text-gray-500 text-sm mb-6">ເລືອກແພັກເກດ ແລ້ວຄລິກ ດຳເນີນການຈ່າຍ.</p>

                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
                    <?php foreach ($packages as $pkg):
                        $is_popular = !empty($pkg['Is_Popular']);
                        $is_best    = !empty($pkg['Is_Best_Value']);
                        preg_match('/(\d+)/', $pkg['Package_duration'], $m);
                        $months_num      = intval($m[1] ?? 1);
                        $is_year         = str_contains($pkg['Package_duration'], 'Year');
                        $price_per_month = $is_year ? round($pkg['Price'] / 12) : round($pkg['Price'] / $months_num);
                    ?>
                        <div class="pkg-card rounded-2xl p-4 text-center relative <?= $is_popular ? 'popular' : ($is_best ? 'best' : '') ?>"
                             onclick="selectPackage(<?= $pkg['Package_rate_ID'] ?>, '<?= htmlspecialchars($pkg['Package_duration']) ?>', <?= $pkg['Price'] ?>, this)">
                            <?php if ($is_popular): ?><div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-full">ນິຍົມ</div><?php endif; ?>
                            <?php if ($is_best): ?><div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-yellow-500 text-white text-xs font-bold px-3 py-1 rounded-full">ຄຸ້ມທີ່ສຸດ</div><?php endif; ?>
                            <div class="bg-green-100 w-10 h-10 rounded-full flex items-center justify-center mx-auto mb-2">
                                <i class="fas fa-<?= $is_best ? 'crown' : ($is_popular ? 'star' : 'box') ?> text-green-600"></i>
                            </div>
                            <p class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($pkg['Package_duration']) ?></p>
                            <p class="text-2xl font-extrabold text-green-600 mt-1">₭<?= number_format($pkg['Price']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="noSelection" class="text-center py-3 text-gray-400 text-sm">
                    <i class="fas fa-hand-pointer mr-1"></i>ເລືອກແພັກເກດຂ້າງເທິງເພື່ອດຳເນີນການ
                </div>
                <div id="proceedSection" class="hidden">
                    <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <i class="fas fa-check-circle text-green-500 text-xl"></i>
                            <div>
                                <p class="font-bold text-green-800" id="selectedDuration"></p>
                                <p class="text-green-600 text-sm" id="selectedPrice"></p>
                            </div>
                        </div>
                        <button onclick="clearSelection()" class="text-gray-400 hover:text-gray-600 text-xs"><i class="fas fa-times"></i> ປ່ຽນ</button>
                    </div>
                    <a id="proceedBtn" href="#"
                       class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-xl transition shadow-lg text-lg flex items-center justify-center gap-2">
                        <i class="fas fa-arrow-right"></i>ດຳເນີນການຈ່າຍ
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- History -->
            <?php if (!empty($history)): ?>
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-history text-gray-500 mr-2"></i>ປະຫວັດແພັກເກດ</h2>
                    <div class="space-y-3">
                        <?php foreach ($history as $h):
                            $color = match($h['Status_Package']) { 'Active'=>'green','Pending'=>'yellow','Rejected'=>'red',default=>'gray' };
                            $label = match($h['Status_Package']) { 'Active'=>'ໃຊ້ງານໄດ້','Pending'=>'ລໍຖ້າ','Rejected'=>'ຖືກປະຕິເສດ',default=>$h['Status_Package'] };
                        ?>
                            <div class="flex items-center justify-between bg-gray-50 rounded-xl px-4 py-3 text-sm">
                                <div class="flex items-center gap-3">
                                    <div class="bg-<?= $color ?>-100 p-2 rounded-lg"><i class="fas fa-box text-<?= $color ?>-500"></i></div>
                                    <div>
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($h['Package_duration']) ?></p>
                                        <p class="text-gray-400 text-xs"><?= date('d/m/Y', strtotime($h['Package_date'])) ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-gray-700">₭<?= number_format($h['Price']) ?></p>
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
function selectPackage(id, duration, price, card) {
    document.querySelectorAll('.pkg-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    document.getElementById('selectedDuration').textContent = duration + ' ແພັກເກດ';
    document.getElementById('selectedPrice').textContent = '₭' + parseInt(price).toLocaleString() + ' ລວມ';
    document.getElementById('proceedBtn').href = 'payment.php?pkg_id=' + id;
    document.getElementById('noSelection').classList.add('hidden');
    document.getElementById('proceedSection').classList.remove('hidden');
}
function clearSelection() {
    document.querySelectorAll('.pkg-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('noSelection').classList.remove('hidden');
    document.getElementById('proceedSection').classList.add('hidden');
}
</script>
</body>
</html>