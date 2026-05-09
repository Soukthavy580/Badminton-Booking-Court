<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$customer_id = $_SESSION['c_id'];
$pending     = $_SESSION['pending_booking'] ?? null;

if (!$pending || $pending['customer_id'] !== $customer_id) {
    header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
    exit;
}

// Check 10-minute expiry (server-side guard)
if (time() > $pending['expires_at']) {
    // Delete the pre-booking
    $book_id = intval($pending['book_id']);
    try {
        $pdo->prepare("DELETE FROM booking_detail WHERE Book_ID = ?")->execute([$book_id]);
        $pdo->prepare("DELETE FROM booking WHERE Book_ID = ? AND (Slip_payment IS NULL OR Slip_payment = '')")->execute([$book_id]);
    } catch (PDOException $e) {}
    unset($_SESSION['pending_booking']);
    $_SESSION['booking_expired'] = true;
    header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $pending['venue_id'] . '&date=' . $pending['date'] . '&expired=1');
    exit;
}

$venue_id        = $pending['venue_id'];
$date            = $pending['date'];
$slots           = $pending['slots'];
$book_id         = $pending['book_id'];
$expires_at      = $pending['expires_at'];
$seconds_left    = max(0, $expires_at - time());

try {
    $stmt = $pdo->prepare("
        SELECT v.*, co.Name AS owner_name, co.Phone AS owner_phone
        FROM Venue_data v
        INNER JOIN court_owner co ON v.CA_ID = co.CA_ID
        WHERE v.VN_ID = ? LIMIT 1
    ");
    $stmt->execute([$venue_id]);
    $venue = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $venue = null; }

if (!$venue) {
    header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
    exit;
}

$price_clean    = intval(preg_replace('/[^0-9]/', '', $venue['Price_per_hour']));
$total_hours    = count($slots);
$total_amount   = $total_hours * $price_clean;
$deposit_amount = $total_amount * 0.30;
$remaining      = $total_amount - $deposit_amount;

$qr_image = !empty($venue['VN_QR_Payment'])
    ? '/Badminton_court_Booking/assets/images/qr/' . basename($venue['VN_QR_Payment'])
    : '';

$error        = '';
$success      = '';
$book_id_done = 0;

// Handle slip upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['slip'])) {
    $file = $_FILES['slip'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'ອັບໂຫລດລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $error = 'ໄຟລ໌ໃຫຍ່ເກີນໄປ. ສູງສຸດ 5MB.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
            $error = 'ປະເພດໄຟລ໌ບໍ່ຖືກຕ້ອງ. ໃຊ້ JPG, PNG, PDF.';
        } else {
            $upload_dir = '../../assets/images/slips/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $filename = 'slip_' . $customer_id . '_' . time() . '.' . $ext;
            $filepath = $upload_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                try {
                    // Re-check expiry
                    if (time() > $expires_at) {
                        @unlink($filepath);
                        $pdo->prepare("DELETE FROM booking_detail WHERE Book_ID = ?")->execute([$book_id]);
                        $pdo->prepare("DELETE FROM booking WHERE Book_ID = ? AND (Slip_payment IS NULL OR Slip_payment = '')")->execute([$book_id]);
                        unset($_SESSION['pending_booking']);
                        header('Location: /Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue_id . '&date=' . $date . '&expired=1');
                        exit;
                    }

                    // Attach slip → booking stays Pending (waiting owner approval)
                    $pdo->prepare("UPDATE booking SET Slip_payment = ? WHERE Book_ID = ? AND C_ID = ?")
                        ->execute([$filename, $book_id, $customer_id]);

                    $book_id_done = $book_id;
                    unset($_SESSION['pending_booking']);
                    $success = 'ອັບໂຫລດສຳເລັດ! ລໍຖ້າເຈົ້າຂອງສະຖານທີ່ຢືນຢັນ.';

                } catch (PDOException $e) {
                    @unlink($filepath);
                    $error = 'ການຈອງລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
                }
            } else {
                $error = 'ອັບໂຫລດໄຟລ໌ລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
            }
        }
    }
}

$booking_date_display = date('d/m/Y', strtotime($date));
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ຈ່າຍເງິນ - ລະບົບຈອງເດີ່ນ</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .upload-zone { border: 2px dashed #d1d5db; transition: all 0.3s; }
        .upload-zone:hover, .upload-zone.dragover { border-color: #3b82f6; background: #eff6ff; }
        .upload-zone.has-file { border-color: #22c55e; background: #f0fdf4; }

        /* Timer ring */
        #timerRing { transition: stroke-dashoffset 1s linear; }

        @keyframes pulse-red {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); }
            50%       { box-shadow: 0 0 0 8px rgba(239,68,68,0); }
        }
        .pulse-red { animation: pulse-red 1s infinite; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <?php include '../includes/header.php'; ?>

    <div class="max-w-4xl mx-auto px-4 py-10">

        <div class="mb-8">
            <a href="/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=<?= $venue_id ?>&date=<?= $date ?>"
               onclick="return confirmCancel(event)"
               class="text-gray-500 hover:text-gray-700 text-sm flex items-center gap-2 mb-4 transition">
                <i class="fas fa-arrow-left"></i> ກັບໄປເລືອກເວລາ
            </a>
            <h1 class="text-3xl font-extrabold text-gray-800">ຈົບການຈອງຂອງທ່ານ</h1>
            <p class="text-gray-500 mt-1">ອັບໂຫລດຫຼັກຖານການໂອນມັດຈຳ 30% ເພື່ອຢືນຢັນການຈອງ</p>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl flex items-center gap-3">
                <i class="fas fa-exclamation-circle flex-shrink-0"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success && $book_id_done): ?>
            <!-- Success State -->
            <div class="bg-green-50 border border-green-300 rounded-2xl p-8 text-center shadow-sm">
                <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-check-circle text-green-500 text-4xl"></i>
                </div>
                <h2 class="text-2xl font-extrabold text-green-700 mb-2">ອັບໂຫລດສຳເລັດ!</h2>
                <p class="text-green-600 mb-2">ຫຼັກຖານການໂອນຂອງທ່ານຖືກສົ່ງແລ້ວ.</p>
                <p class="text-green-600 mb-6">ລໍຖ້າເຈົ້າຂອງສະຖານທີ່ຢືນຢັນການຈອງ.</p>
                <div class="bg-white rounded-xl p-4 mb-6 inline-block text-left shadow-sm">
                    <p class="text-xs text-gray-400 mb-1">ເລກການຈອງ</p>
                    <p class="text-xl font-extrabold text-gray-800">#<?= $book_id_done ?></p>
                </div>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php"
                       class="bg-green-600 hover:bg-green-700 text-white font-bold px-6 py-3 rounded-xl transition flex items-center justify-center gap-2">
                        <i class="fas fa-list"></i> ເບິ່ງການຈອງຂອງຂ້ອຍ
                    </a>
                    <a href="/Badminton_court_Booking/customer/booking_court/index.php"
                       class="bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-bold px-6 py-3 rounded-xl transition flex items-center justify-center gap-2">
                        <i class="fas fa-search"></i> ຊອກຫາເດີ່ນເພີ່ມ
                    </a>
                </div>
            </div>

        <?php else: ?>

            <!-- ══ COUNTDOWN TIMER BANNER ══ -->
            <div id="timerBanner"
                 class="mb-6 bg-amber-50 border-2 border-amber-300 rounded-2xl px-5 py-4 flex items-center gap-4">
                <!-- SVG ring timer -->
                <div class="relative flex-shrink-0 w-16 h-16">
                    <svg class="w-16 h-16 -rotate-90" viewBox="0 0 64 64">
                        <circle cx="32" cy="32" r="28" fill="none" stroke="#fde68a" stroke-width="6"/>
                        <circle id="timerRing" cx="32" cy="32" r="28" fill="none"
                                stroke="#f59e0b" stroke-width="6"
                                stroke-dasharray="175.93"
                                stroke-dashoffset="0"
                                stroke-linecap="round"/>
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span id="timerDisplay" class="text-sm font-extrabold text-amber-700">10:00</span>
                    </div>
                </div>
                <div class="flex-1">
                    <p class="font-bold text-amber-800">ກະລຸນາຈ່າຍພາຍໃນ <span id="timerText">10 ນາທີ</span></p>
                    <p class="text-sm text-amber-600 mt-0.5">ເວລາທີ່ຈອງໄວ້ຈະຖືກຍົກເລີກໂດຍອັດຕະໂນມັດຫາກໝົດເວລາ</p>
                </div>
            </div>

            <!-- Booking + Payment Form -->
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

                <!-- LEFT: Booking Summary -->
                <div class="lg:col-span-3 space-y-5">

                    <!-- Venue Info -->
                    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                        <?php if (!empty($venue['VN_Image'])): ?>
                            <img src="/Badminton_court_Booking/assets/images/venues/<?= htmlspecialchars($venue['VN_Image']) ?>"
                                 class="w-full h-40 object-cover" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <div class="p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="text-xl font-extrabold text-gray-800"><?= htmlspecialchars($venue['VN_Name']) ?></h2>
                                    <p class="text-sm text-gray-500 mt-1">
                                        <i class="fas fa-user-tie mr-1 text-purple-400"></i><?= htmlspecialchars($venue['owner_name']) ?>
                                        <span class="ml-2">
                                            <i class="fas fa-phone mr-1 text-green-400"></i><?= htmlspecialchars($venue['owner_phone']) ?>
                                        </span>
                                    </p>
                                </div>
                                <span class="bg-blue-50 text-blue-600 text-xs font-bold px-3 py-1.5 rounded-full flex-shrink-0 border border-blue-100">
                                    <i class="fas fa-calendar mr-1"></i><?= $booking_date_display ?>
                                </span>
                            </div>
                            <!-- Pending badge -->
                            <div class="mt-3 flex items-center gap-2 bg-yellow-50 border border-yellow-200 rounded-xl px-3 py-2">
                                <i class="fas fa-clock text-yellow-500"></i>
                                <span class="text-xs font-bold text-yellow-700">ສະຖານະ: ກຳລັງລໍຖ້າການຈ່າຍເງິນ</span>
                                <span class="ml-auto text-xs text-yellow-600">ເລກ #<?= $book_id ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Booked Slots -->
                    <div class="bg-white rounded-2xl shadow-sm p-5">
                        <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
                            <i class="fas fa-clock text-green-500"></i>
                            ເວລາທີ່ຈອງ (<?= count($slots) ?> ເວລາ · <?= $total_hours ?> ຊົ່ວໂມງ)
                        </h3>
                        <div class="space-y-2">
                            <?php foreach ($slots as $slot): ?>
                                <div class="flex items-center justify-between bg-yellow-50 border border-yellow-200 rounded-xl px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 bg-yellow-100 rounded-full flex items-center justify-center flex-shrink-0">
                                            <i class="fas fa-table-tennis text-yellow-600 text-xs"></i>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($slot['courtName']) ?></p>
                                            <p class="text-xs text-gray-500">
                                                <?= htmlspecialchars($slot['start']) ?> – <?= htmlspecialchars($slot['end']) ?>
                                                <span class="ml-2 text-yellow-600 font-semibold">ກຳລັງດຳເນີນການ</span>
                                            </p>
                                        </div>
                                    </div>
                                    <span class="text-sm font-bold text-gray-700">₭<?= number_format($price_clean) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Price Breakdown mobile -->
                    <div class="bg-white rounded-2xl shadow-sm p-5 lg:hidden">
                        <div class="space-y-3">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500"><?= $total_hours ?> ຊມ × ₭<?= number_format($price_clean) ?></span>
                                <span class="font-semibold text-gray-700">₭<?= number_format($total_amount) ?></span>
                            </div>
                            <div class="border-t border-dashed border-gray-200 pt-3">
                                <div class="flex justify-between bg-green-50 rounded-xl px-3 py-2 mb-2">
                                    <span class="text-green-700 font-bold text-sm">ມັດຈຳ 30% ດຽວນີ້</span>
                                    <span class="text-green-700 font-extrabold">₭<?= number_format($deposit_amount) ?></span>
                                </div>
                                <div class="flex justify-between px-3 py-1">
                                    <span class="text-gray-400 text-xs">ຈ່າຍທີ່ສະຖານທີ່ (70%)</span>
                                    <span class="text-gray-500 text-xs font-semibold">₭<?= number_format($remaining) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Payment Panel -->
                <div class="lg:col-span-2 space-y-5">

                    <!-- QR Code -->
                    <?php if ($qr_image): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-5 text-center">
                            <h3 class="font-bold text-gray-700 mb-3 flex items-center justify-center gap-2">
                                <i class="fas fa-qrcode text-blue-500"></i> ສະແກນ QR ຈ່າຍເງິນ
                            </h3>
                            <img src="<?= htmlspecialchars($qr_image) ?>"
                                 class="w-48 h-48 object-contain mx-auto rounded-xl border border-gray-200"
                                 onerror="this.style.display='none'">
                            <p class="text-xs text-gray-400 mt-2">ໂອນ <strong class="text-green-600">₭<?= number_format($deposit_amount) ?></strong> ແລ້ວອັບໂຫລດຫຼັກຖານການໂອນ</p>
                        </div>
                    <?php endif; ?>

                    <!-- Price Breakdown desktop -->
                    <div class="bg-white rounded-2xl shadow-sm p-5 hidden lg:block">
                        <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
                            <i class="fas fa-receipt text-yellow-500"></i> ສະຫຼຸບລາຄາ
                        </h3>
                        <div class="space-y-3">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500"><?= $total_hours ?> ຊມ × ₭<?= number_format($price_clean) ?></span>
                                <span class="font-semibold text-gray-700">₭<?= number_format($total_amount) ?></span>
                            </div>
                            <div class="border-t border-dashed border-gray-200 pt-3">
                                <div class="flex justify-between bg-green-50 rounded-xl px-3 py-2 mb-2">
                                    <span class="text-green-700 font-bold text-sm">ມັດຈຳ 30% ດຽວນີ້</span>
                                    <span class="text-green-700 font-extrabold">₭<?= number_format($deposit_amount) ?></span>
                                </div>
                                <div class="flex justify-between px-3 py-1">
                                    <span class="text-gray-400 text-xs">ຈ່າຍທີ່ສະຖານທີ່ (70%)</span>
                                    <span class="text-gray-500 text-xs font-semibold">₭<?= number_format($remaining) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Upload Slip -->
                    <div class="bg-white rounded-2xl shadow-sm p-5">
                        <h3 class="font-bold text-gray-700 mb-1 flex items-center gap-2">
                            <i class="fas fa-upload text-blue-500"></i> ອັບໂຫລດຫຼັກຖານການໂອນ
                        </h3>
                        <p class="text-xs text-gray-400 mb-4">ໂອນ ₭<?= number_format($deposit_amount) ?> ແລ້ວອັບໂຫລດຫຼັກຖານການໂອນຢູ່ນີ້</p>

                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <div class="upload-zone rounded-xl p-6 text-center cursor-pointer mb-4"
                                 id="dropZone" onclick="document.getElementById('slipFile').click()">
                                <div id="uploadPreview" class="hidden mb-3">
                                    <img id="previewImg" class="max-h-40 mx-auto rounded-lg object-contain">
                                </div>
                                <div id="uploadPrompt">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-300 mb-2 block"></i>
                                    <p class="text-sm font-semibold text-gray-500">ຄລິກ ຫຼື ລາກໄຟລ໌ມາໃສ່ນີ້</p>
                                    <p class="text-xs text-gray-400 mt-1">JPG, PNG, PDF — ສູງສຸດ 5MB</p>
                                </div>
                                <input type="file" id="slipFile" name="slip" class="hidden"
                                       accept=".jpg,.jpeg,.png,.pdf" onchange="previewFile(this)">
                            </div>

                            <div id="fileInfo" class="hidden bg-blue-50 rounded-xl px-4 py-2 mb-4 flex items-center gap-2">
                                <i class="fas fa-file-image text-blue-500"></i>
                                <span id="fileName" class="text-sm text-blue-700 font-medium truncate flex-1"></span>
                                <button type="button" onclick="clearFile()"
                                        class="text-gray-400 hover:text-red-500 transition flex-shrink-0">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>

                            <button type="submit" id="submitBtn" disabled
                                    class="w-full flex items-center justify-center gap-2 bg-blue-600 text-white font-bold py-3 rounded-xl transition opacity-50 cursor-not-allowed">
                                <i class="fas fa-paper-plane"></i> ສົ່ງຫຼັກຖານການໂອນ
                            </button>
                        </form>

                        <div class="mt-4 bg-yellow-50 border border-yellow-200 rounded-xl p-3">
                            <p class="text-xs text-yellow-700 font-semibold mb-1">
                                <i class="fas fa-info-circle mr-1"></i>ວິທີການ
                            </p>
                            <ol class="text-xs text-yellow-600 space-y-1">
                                <li>1. ໂອນເງິນມັດຈຳ 30%: <strong>₭<?= number_format($deposit_amount) ?></strong></li>
                                <li>2. ຖ່າຍຮູບ ຫຼື ສະກຣີນຫຼັກຖານການໂອນ</li>
                                <li>3. ອັບໂຫລດຢູ່ນີ້</li>
                                <li>4. ເຈົ້າຂອງຈະຢືນຢັນການຈອງ</li>
                                <li>5. ຈ່າຍທີ່ເຫຼືອ <strong>₭<?= number_format($remaining) ?></strong> ທີ່ສະຖານທີ່</li>
                            </ol>
                        </div>
                    </div>

                    <!-- Cancel -->
                    <div class="text-center">
                        <a href="/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=<?= $venue_id ?>&date=<?= $date ?>"
                           onclick="return confirmCancel(event)"
                           class="text-sm text-red-400 hover:text-red-600 transition underline">
                            <i class="fas fa-times-circle mr-1"></i>ຍົກເລີກ ແລະ ກັບໄປເລືອກໃໝ່
                        </a>
                    </div>
                </div>
            </div>

            <!-- TIMEOUT OVERLAY (hidden until timer fires) -->
            <div id="timeoutOverlay"
                 class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-sm w-full text-center">
                    <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-clock text-red-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-extrabold text-red-700 mb-3">ໝົດເວລາການຈ່າຍ!</h3>
                    <p class="text-gray-600 mb-6">ທ່ານໄດ້ຢູ່ໜ້ານີ້ກາຍ 10 ນາທີ ແລ້ວຍັງບໍ່ຈ່າຍເງິນ ກາລູນາຈອງໃຫມ່</p>
                    <a id="timeoutRedirectBtn"
                       href="/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=<?= $venue_id ?>&date=<?= $date ?>&expired=1"
                       class="w-full block bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl transition">
                        <i class="fas fa-redo mr-2"></i>ຈອງໃໝ່
                    </a>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        // ── FILE UPLOAD ──
        function previewFile(input) {
            const file = input.files[0];
            if (!file) return;
            document.getElementById('fileName').textContent = file.name;
            document.getElementById('fileInfo').classList.remove('hidden');
            const btn = document.getElementById('submitBtn');
            btn.disabled = false;
            btn.classList.remove('opacity-50', 'cursor-not-allowed');
            document.getElementById('dropZone').classList.add('has-file');
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = e => {
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('uploadPreview').classList.remove('hidden');
                    document.getElementById('uploadPrompt').classList.add('hidden');
                };
                reader.readAsDataURL(file);
            }
        }

        function clearFile() {
            document.getElementById('slipFile').value = '';
            document.getElementById('fileInfo').classList.add('hidden');
            document.getElementById('uploadPreview').classList.add('hidden');
            document.getElementById('uploadPrompt').classList.remove('hidden');
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
            document.getElementById('dropZone').classList.remove('has-file');
        }

        // ── CANCEL ──
        async function confirmCancel(e) {
            e.preventDefault();
            const dest = e.currentTarget.href;
            const ok = await (window.BBCAlert && window.BBCAlert.confirm
                ? window.BBCAlert.confirm({
                    icon: 'warning',
                    title: 'ຢືນຢັນ',
                    text: 'ຍົກເລີກການຈ່າຍ? ການຈອງນີ້ຈະຖືກລຶບ ແລະ ເວລາຈະກາຍເປັນຫວ່າງ.',
                    confirmButtonText: 'ຍົກເລີກການຈ່າຍ',
                    cancelButtonText: 'ຢູ່ຕໍ່'
                })
                : Promise.resolve(confirm('ຍົກເລີກການຈ່າຍ?'))
            );
            if (ok) {
                // Cancel the pre-booking then redirect
                cancelAndLeave(dest);
            }
            return false;
        }

        function cancelAndLeave(dest) {
            fetch('/Badminton_court_Booking/customer/payment/cancel_booking_timeout.php', {
                method: 'POST', credentials: 'same-origin'
            }).finally(() => { window.location.href = dest; });
        }

        // ── DRAG & DROP ──
        const dropZone = document.getElementById('dropZone');
        if (dropZone) {
            dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
            dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
            dropZone.addEventListener('drop', e => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                const file = e.dataTransfer.files[0];
                if (file) {
                    const input = document.getElementById('slipFile');
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    input.files = dt.files;
                    previewFile(input);
                }
            });
        }

        // ── 10-MINUTE COUNTDOWN TIMER ──
        <?php if (!$success): ?>
        (function () {
            const TOTAL     = 600; // 10 minutes in seconds
            const expiresAt = <?= $expires_at ?>; // unix timestamp from PHP
            const CIRCUMFERENCE = 175.93; // 2 * π * 28

            const ring      = document.getElementById('timerRing');
            const display   = document.getElementById('timerDisplay');
            const textEl    = document.getElementById('timerText');
            const banner    = document.getElementById('timerBanner');
            const overlay   = document.getElementById('timeoutOverlay');

            function tick() {
                const now  = Math.floor(Date.now() / 1000);
                const left = expiresAt - now;

                if (left <= 0) {
                    // Timer expired
                    if (ring)    ring.style.strokeDashoffset = CIRCUMFERENCE;
                    if (display) display.textContent = '00:00';
                    fireTimeout();
                    return;
                }

                const mins = Math.floor(left / 60);
                const secs = left % 60;
                const mm   = String(mins).padStart(2, '0');
                const ss   = String(secs).padStart(2, '0');

                if (display) display.textContent = `${mm}:${ss}`;
                if (textEl)  textEl.textContent  = `${mins} ນາທີ ${secs} ວິນາທີ`;

                // Ring progress
                const fraction = left / TOTAL;
                if (ring) ring.style.strokeDashoffset = CIRCUMFERENCE * (1 - fraction);

                // Red pulsing when < 2 min
                if (left <= 120 && banner) {
                    banner.classList.remove('bg-amber-50', 'border-amber-300');
                    banner.classList.add('bg-red-50', 'border-red-400', 'pulse-red');
                    if (ring) ring.setAttribute('stroke', '#ef4444');
                    if (display) display.classList.replace('text-amber-700', 'text-red-700');
                }

                setTimeout(tick, 1000);
            }

            function fireTimeout() {
                // Tell server to delete the booking
                fetch('/Badminton_court_Booking/customer/payment/cancel_booking_timeout.php', {
                    method: 'POST', credentials: 'same-origin'
                }).finally(() => {
                    // Show overlay
                    if (overlay) overlay.classList.remove('hidden');
                });
            }

            tick();
        })();
        <?php endif; ?>
    </script>
</body>
</html>