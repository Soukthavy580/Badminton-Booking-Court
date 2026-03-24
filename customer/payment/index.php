<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

// STEP 1: Define variables first
$booking_id  = intval($_GET['booking_id'] ?? 0);
$customer_id = $_SESSION['c_id'];

if (!$booking_id) {
    header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
    exit;
}

// STEP 2: Fetch booking
try {
    $stmt = $pdo->prepare("
        SELECT b.*,
               c.Name AS customer_name, c.Email AS customer_email, c.Phone AS customer_phone
        FROM booking b
        INNER JOIN customer c ON b.C_ID = c.C_ID
        WHERE b.Book_ID = ? AND b.C_ID = ?
    ");
    $stmt->execute([$booking_id, $customer_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $booking = null; }

if (!$booking) {
    header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
    exit;
}

// STEP 3: Only allow Unpaid or Pending
if (!in_array($booking['Status_booking'], ['Unpaid', 'Pending'])) {
    header('Location: /Badminton_court_Booking/customer/booking_court/my_booking.php');
    exit;
}

// STEP 4: Fetch booking details
try {
    $stmt = $pdo->prepare("
        SELECT bd.*, c.COURT_Name, v.VN_Name, v.VN_ID, v.Price_per_hour,
               v.VN_Image, v.VN_QR_Payment, co.Name AS owner_name, co.Phone AS owner_phone
        FROM booking_detail bd
        INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
        INNER JOIN court_owner co ON v.CA_ID = co.CA_ID
        WHERE bd.Book_ID = ?
        ORDER BY bd.Start_time ASC
    ");
    $stmt->execute([$booking_id]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $details = []; }

if (empty($details)) {
    header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
    exit;
}

$venue          = $details[0];
$price_clean    = preg_replace('/[^0-9]/', '', $venue['Price_per_hour']);
$price_per_hour = intval($price_clean);

$total_hours = 0;
foreach ($details as $d) {
    $start = new DateTime($d['Start_time']);
    $end   = new DateTime($d['End_time']);
    $total_hours += ($end->getTimestamp() - $start->getTimestamp()) / 3600;
}

$total_amount   = $total_hours * $price_per_hour;
$deposit_amount = $total_amount * 0.30;
$remaining      = $total_amount - $deposit_amount;

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['slip'])) {
    $file = $_FILES['slip'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed. Please try again.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $error = 'File too large. Maximum 5MB allowed.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
            $error = 'Invalid file type. Only JPG, PNG, PDF allowed.';
        } else {
            $upload_dir = '../../assets/images/slips/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $filename = 'slip_' . $booking_id . '_' . time() . '.' . $ext;
            $filepath = $upload_dir . $filename;
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                try {
                    $pdo->prepare("
                        UPDATE booking SET Slip_payment = ?, Status_booking = 'Pending'
                        WHERE Book_ID = ? AND C_ID = ?
                    ")->execute([$filename, $booking_id, $customer_id]);
                    $success = 'ອັບໂຫລດສຳເລັດ! ລໍຖ້າເຈົ້າຂອງສະຖານທີ່ຢືນຢັນ.';
                    $booking['Slip_payment']   = $filename;
                    $booking['Status_booking'] = 'Pending';
                } catch (PDOException $e) {
                    $error = 'Failed to save slip. Please try again.';
                }
            } else {
                $error = 'Failed to upload file. Please try again.';
            }
        }
    }
}

$qr_image     = !empty($venue['VN_QR_Payment'])
    ? '/Badminton_court_Booking/assets/images/qr/' . basename($venue['VN_QR_Payment'])
    : '';
$booking_date  = date('d/m/Y', strtotime($details[0]['Start_time']));
$slip_uploaded = !empty($booking['Slip_payment']);
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
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <?php include '../includes/header.php'; ?>

    <div class="max-w-4xl mx-auto px-4 py-10">

        <div class="mb-8">
            <a href="/Badminton_court_Booking/customer/booking_court/index.php"
               class="text-gray-500 hover:text-gray-700 text-sm flex items-center gap-2 mb-4 transition">
                <i class="fas fa-arrow-left"></i> ກັບໄປລາຍການເດີ່ນ
            </a>
            <h1 class="text-3xl font-extrabold text-gray-800">ຈົບການຈອງຂອງທ່ານ</h1>
            <p class="text-gray-500 mt-1">ອັບໂຫລດໃບຮັບເງິນມັດຈຳ 30% ເພື່ອຢືນຢັນການຈອງ</p>
        </div>

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

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

            <!-- LEFT: Booking Summary -->
            <div class="lg:col-span-3 space-y-5">

                <!-- Venue Info -->
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <?php if ($venue['VN_Image']): ?>
                        <img src="/Badminton_court_Booking/assets/images/venues/<?= htmlspecialchars($venue['VN_Image']) ?>"
                             class="w-full h-40 object-cover" onerror="this.style.display='none'">
                    <?php endif; ?>
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-xl font-extrabold text-gray-800"><?= htmlspecialchars($venue['VN_Name']) ?></h2>
                                <p class="text-sm text-gray-500 mt-1">
                                    <i class="fas fa-user-tie mr-1 text-purple-400"></i><?= htmlspecialchars($venue['owner_name']) ?>
                                    <span class="ml-2"><i class="fas fa-phone mr-1 text-green-400"></i><?= htmlspecialchars($venue['owner_phone']) ?></span>
                                </p>
                            </div>
                            <span class="bg-blue-100 text-blue-700 text-xs font-bold px-3 py-1.5 rounded-full flex-shrink-0">#<?= $booking_id ?></span>
                        </div>
                    </div>
                </div>

                <!-- Booking Date -->
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="font-bold text-gray-700 mb-3 flex items-center gap-2">
                        <i class="fas fa-calendar text-blue-500"></i> ວັນທີຈອງ
                    </h3>
                    <p class="text-lg font-semibold text-gray-800"><?= $booking_date ?></p>
                </div>

                <!-- Booked Slots -->
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
                        <i class="fas fa-clock text-green-500"></i>
                        ສລັອດທີ່ຈອງ (<?= count($details) ?> ສລັອດ)
                    </h3>
                    <div class="space-y-2">
                        <?php foreach ($details as $d):
                            $s   = new DateTime($d['Start_time']);
                            $e   = new DateTime($d['End_time']);
                            $hrs = ($e->getTimestamp() - $s->getTimestamp()) / 3600;
                        ?>
                            <div class="flex items-center justify-between bg-gray-50 rounded-xl px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-table-tennis text-blue-500 text-xs"></i>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($d['COURT_Name']) ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?= date('H:i', strtotime($d['Start_time'])) ?> – <?= date('H:i', strtotime($d['End_time'])) ?>
                                        </p>
                                    </div>
                                </div>
                                <span class="text-sm font-bold text-gray-700">₭<?= number_format($hrs * $price_per_hour) ?></span>
                            </div>
                        <?php endforeach; ?>
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
                        <p class="text-xs text-gray-400 mt-2">ສະແກນ QR ດ້ານເທິງ ແລ້ວໂອນ ₭<?= number_format($deposit_amount) ?></p>
                    </div>
                <?php endif; ?>

                <!-- Price Breakdown -->
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
                        <i class="fas fa-receipt text-yellow-500"></i> ສະຫຼຸບລາຄາ
                    </h3>
                    <div class="space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?= $total_hours ?>ຊມ × ₭<?= number_format($price_per_hour) ?></span>
                            <span class="font-semibold text-gray-700">₭<?= number_format($total_amount) ?></span>
                        </div>
                        <div class="border-t border-dashed border-gray-200 pt-3">
                            <div class="flex justify-between bg-green-50 rounded-xl px-3 py-2 mb-2">
                                <span class="text-green-700 font-bold text-sm">ມັດຈຳ 30% ດຽວນີ້</span>
                                <span class="text-green-700 font-extrabold">₭<?= number_format($deposit_amount) ?></span>
                            </div>
                            <div class="flex justify-between px-3 py-1">
                                <span class="text-gray-400 text-xs">ຈ່າຍທີ່ສະຖານທີ່</span>
                                <span class="text-gray-500 text-xs font-semibold">₭<?= number_format($remaining) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upload Slip -->
                <?php if ($slip_uploaded): ?>
                    <div class="bg-green-50 border border-green-300 rounded-2xl p-5 text-center">
                        <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-check-circle text-green-500 text-2xl"></i>
                        </div>
                        <h3 class="font-extrabold text-green-700 text-lg mb-1">ອັບໂຫລດສຳເລັດ!</h3>
                        <p class="text-green-600 text-sm mb-4">ໃບຮັບເງິນຂອງທ່ານຖືກສົ່ງແລ້ວ. ລໍຖ້າເຈົ້າຂອງສະຖານທີ່ຢືນຢັນ.</p>
                        <?php
                        $ext = strtolower(pathinfo($booking['Slip_payment'], PATHINFO_EXTENSION));
                        if ($ext !== 'pdf'): ?>
                            <img src="/Badminton_court_Booking/assets/images/slips/<?= htmlspecialchars($booking['Slip_payment']) ?>"
                                 class="w-full rounded-xl border border-green-200 mb-4 max-h-48 object-contain"
                                 onerror="this.style.display='none'">
                        <?php else: ?>
                            <div class="bg-white rounded-xl p-3 mb-4 flex items-center gap-2 border border-green-200">
                                <i class="fas fa-file-pdf text-red-500 text-xl"></i>
                                <span class="text-sm text-gray-600">PDF ໃບຮັບເງິນ</span>
                            </div>
                        <?php endif; ?>
                        <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php"
                           class="w-full flex items-center justify-center gap-2 bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                            <i class="fas fa-list"></i> ເບິ່ງການຈອງຂອງຂ້ອຍ
                        </a>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-2xl shadow-sm p-5">
                        <h3 class="font-bold text-gray-700 mb-1 flex items-center gap-2">
                            <i class="fas fa-upload text-blue-500"></i> ອັບໂຫລດໃບຮັບເງິນ
                        </h3>
                        <p class="text-xs text-gray-400 mb-4">ໂອນ ₭<?= number_format($deposit_amount) ?> ແລ້ວອັບໂຫລດໃບຮັບເງິນ</p>
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <div class="upload-zone rounded-xl p-6 text-center cursor-pointer mb-4" id="dropZone"
                                 onclick="document.getElementById('slipFile').click()">
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
                                <span id="fileName" class="text-sm text-blue-700 font-medium truncate"></span>
                                <button type="button" onclick="clearFile()" class="ml-auto text-gray-400 hover:text-red-500 transition">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <button type="submit" id="submitBtn" disabled
                                    class="w-full flex items-center justify-center gap-2 bg-blue-600 text-white font-bold py-3 rounded-xl transition opacity-50 cursor-not-allowed">
                                <i class="fas fa-paper-plane"></i> ສົ່ງໃບຮັບເງິນ
                            </button>
                        </form>
                        <div class="mt-4 bg-yellow-50 border border-yellow-200 rounded-xl p-3">
                            <p class="text-xs text-yellow-700 font-semibold mb-1">
                                <i class="fas fa-info-circle mr-1"></i>ວິທີການ
                            </p>
                            <ul class="text-xs text-yellow-600 space-y-1">
                                <li>1. ໂອນເງິນມັດຈຳ 30%: <strong>₭<?= number_format($deposit_amount) ?></strong></li>
                                <li>2. ຖ່າຍຮູບ ຫຼື ສະກຣີນໃບຮັບເງິນ</li>
                                <li>3. ອັບໂຫລດຢູ່ນີ້</li>
                                <li>4. ເຈົ້າຂອງຈະຢືນຢັນການຈອງ</li>
                                <li>5. ຈ່າຍທີ່ເຫຼືອ <strong>₭<?= number_format($remaining) ?></strong> ທີ່ສະຖານທີ່</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Cancel Booking -->
                <?php if (!$slip_uploaded): ?>
                    <div class="text-center">
                        <a href="/Badminton_court_Booking/customer/cancellation/index.php?id=<?= $booking_id ?>"
                           onclick="return confirm('ຍົກເລີກການຈອງນີ້? ບໍ່ສາມາດຍ້ອນກັບໄດ້.')"
                           class="text-sm text-red-400 hover:text-red-600 transition underline">
                            <i class="fas fa-times-circle mr-1"></i>ຍົກເລີກການຈອງນີ້
                        </a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function previewFile(input) {
            const file = input.files[0];
            if (!file) return;
            document.getElementById('fileName').textContent = file.name;
            document.getElementById('fileInfo').classList.remove('hidden');
            document.getElementById('submitBtn').disabled = false;
            document.getElementById('submitBtn').classList.remove('opacity-50', 'cursor-not-allowed');
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
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').classList.add('opacity-50', 'cursor-not-allowed');
            document.getElementById('dropZone').classList.remove('has-file');
        }
        const dropZone = document.getElementById('dropZone');
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
    </script>
</body>
</html>