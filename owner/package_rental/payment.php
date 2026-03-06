<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id  = $_SESSION['ca_id'];
$pkg_id = intval($_GET['pkg_id'] ?? 0);

if (!$pkg_id) { header('Location: /Badminton_court_Booking/owner/package_rental/index.php'); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM package_rate WHERE Package_rate_ID = ?");
    $stmt->execute([$pkg_id]);
    $pkg = $stmt->fetch();
} catch (PDOException $e) { $pkg = null; }

if (!$pkg) { header('Location: /Badminton_court_Booking/owner/package_rental/index.php'); exit; }

try {
    $stmt = $pdo->prepare("SELECT VN_ID FROM Venue_data WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $venue = $stmt->fetch();
    $vn_id = $venue['VN_ID'] ?? null;
} catch (PDOException $e) { $vn_id = null; }

try {
    $stmt = $pdo->query("SELECT Image_pay FROM admin LIMIT 1");
    $admin    = $stmt->fetch();
    $admin_qr = $admin['Image_pay'] ?? '';
} catch (PDOException $e) { $admin_qr = ''; }

preg_match('/(\d+)/', $pkg['Package_duration'], $m);
$months_num  = intval($m[1] ?? 1);
$end_preview = date('M d, Y', strtotime("+{$months_num} months"));

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM package WHERE CA_ID = ? AND Status_Package = 'Pending'");
        $stmt->execute([$ca_id]);
        $has_pending = $stmt->fetchColumn() > 0;
    } catch (PDOException $e) { $has_pending = false; }

    if ($has_pending) {
        $error = 'You already have a pending package awaiting approval.';
    } else {
        $file = $_FILES['slip_payment'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload your payment slip.';
        } elseif (!in_array($file['type'], ['image/jpeg','image/png','image/jpg','image/webp'])) {
            $error = 'Only JPG, PNG, WEBP allowed.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error = 'File must be under 5MB.';
        } else {
            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'pkg_' . $ca_id . '_' . time() . '.' . $ext;
            $dir      = $_SERVER['DOCUMENT_ROOT'] . '/Badminton_court_Booking/assets/images/slips/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                try {
                    $start_dt = date('Y-m-d H:i:s');
                    $end_dt   = date('Y-m-d H:i:s', strtotime("+{$months_num} months"));

                    $pdo->prepare("
                        INSERT INTO package (Package_date, Slip_payment, Start_time, End_time, Status_Package, VN_ID, CA_ID, Package_rate_ID)
                        VALUES (NOW(), ?, ?, ?, 'Pending', ?, ?, ?)
                    ")->execute([$filename, $start_dt, $end_dt, $vn_id, $ca_id, $pkg_id]);

                    // ── Delete package notification — owner has resubmitted ──
                    $pdo->prepare("DELETE FROM owner_notification WHERE CA_ID = ? AND type = 'package'")
                        ->execute([$ca_id]);

                    $success = true;
                } catch (PDOException $e) {
                    $error = 'Submission failed: ' . $e->getMessage();
                }
            } else {
                $error = 'Failed to upload file.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Package Payment - CourtBook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .upload-area { border: 2px dashed #d1d5db; transition: all 0.3s ease; }
        .upload-area:hover, .upload-area.dragover { border-color: #16a34a; background: #f0fdf4; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center gap-3">
                <a href="/Badminton_court_Booking/owner/package_rental/index.php" class="text-gray-400 hover:text-gray-600 transition">
                    <i class="fas fa-arrow-left text-lg"></i>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">Package Payment</h1>
                    <p class="text-sm text-gray-500">Complete your payment to activate your package</p>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6 max-w-2xl mx-auto w-full">

            <?php if ($success): ?>
                <div class="bg-white rounded-2xl shadow-sm p-10 text-center">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-circle text-green-500 text-4xl"></i>
                    </div>
                    <h2 class="text-2xl font-extrabold text-gray-800 mb-2">Payment Submitted!</h2>
                    <p class="text-gray-500 mb-2">Your package payment slip has been submitted successfully.</p>
                    <p class="text-gray-400 text-sm mb-6">Admin will review and approve within 24 hours.</p>
                    <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6 text-left">
                        <p class="text-sm font-bold text-green-800 mb-1"><?= htmlspecialchars($pkg['Package_duration']) ?> Package</p>
                        <p class="text-green-600 text-sm">₭<?= number_format($pkg['Price']) ?> · Active until approx. <?= $end_preview ?></p>
                    </div>
                    <a href="/Badminton_court_Booking/owner/package_rental/index.php"
                       class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold px-8 py-3 rounded-xl transition">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Packages
                    </a>
                </div>

            <?php else: ?>

                <?php if ($error): ?>
                    <div class="mb-6 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl flex items-center gap-3">
                        <i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-2xl p-6 mb-6 text-white">
                    <p class="text-green-100 text-sm mb-1">Selected Package</p>
                    <h2 class="text-3xl font-extrabold mb-1"><?= htmlspecialchars($pkg['Package_duration']) ?></h2>
                    <p class="text-2xl font-bold text-yellow-300">₭<?= number_format($pkg['Price']) ?></p>
                    <div class="mt-3 flex items-center gap-4 text-sm text-green-200">
                        <span><i class="fas fa-calendar-check mr-1"></i>Starts: <?= date('M d, Y') ?></span>
                        <span><i class="fas fa-calendar-times mr-1"></i>Ends: ~<?= $end_preview ?></span>
                    </div>
                    <a href="/Badminton_court_Booking/owner/package_rental/index.php"
                       class="inline-block mt-3 text-green-200 hover:text-white text-xs underline">
                        <i class="fas fa-exchange-alt mr-1"></i>Change package
                    </a>
                </div>

                <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                    <h3 class="font-bold text-gray-800 mb-4"><i class="fas fa-qrcode text-green-500 mr-2"></i>Payment Instructions</h3>
                    <div class="flex flex-col md:flex-row gap-6 items-start">
                        <div class="flex-shrink-0 text-center">
                            <?php if ($admin_qr): ?>
                                <img src="/Badminton_court_Booking/assets/adminQR/<?= htmlspecialchars($admin_qr) ?>"
                                     class="w-40 h-40 rounded-xl border-2 border-gray-200 object-contain bg-white p-2"
                                     onerror="this.parentElement.innerHTML='<div class=\'w-40 h-40 bg-gray-100 rounded-xl flex items-center justify-center\'><i class=\'fas fa-qrcode text-5xl text-gray-300\'></i></div>'">
                            <?php else: ?>
                                <div class="w-40 h-40 bg-gray-100 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-qrcode text-5xl text-gray-300"></i>
                                </div>
                            <?php endif; ?>
                            <p class="text-xs text-gray-400 mt-2">Scan to pay</p>
                        </div>
                        <div class="flex-1">
                            <p class="font-semibold text-gray-700 mb-3">How to pay:</p>
                            <ol class="space-y-2">
                                <?php foreach ([
                                    'Open your banking app',
                                    'Scan the QR code on the left',
                                    'Enter the exact amount: <strong class="text-green-600">₭'.number_format($pkg['Price']).'</strong>',
                                    'Complete the transfer',
                                    'Take a screenshot of confirmation',
                                    'Upload the screenshot below',
                                ] as $i => $step): ?>
                                    <li class="flex items-start gap-3 text-sm text-gray-600">
                                        <span class="bg-green-100 text-green-600 font-bold w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 text-xs"><?= $i+1 ?></span>
                                        <span><?= $step ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-bold text-gray-800 mb-4"><i class="fas fa-upload text-green-500 mr-2"></i>Upload Payment Screenshot</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="upload-area rounded-xl p-8 text-center cursor-pointer mb-6" id="uploadArea"
                             onclick="document.getElementById('slipFile').click()"
                             ondragover="e=>{e.preventDefault();document.getElementById('uploadArea').classList.add('dragover')}"
                             ondragleave="document.getElementById('uploadArea').classList.remove('dragover')"
                             ondrop="e=>{e.preventDefault();document.getElementById('uploadArea').classList.remove('dragover');const f=e.dataTransfer.files[0];if(f){const d=new DataTransfer();d.items.add(f);document.getElementById('slipFile').files=d.files;previewFile(document.getElementById('slipFile'))}}">
                            <div id="uploadPlaceholder">
                                <i class="fas fa-cloud-upload-alt text-4xl text-gray-300 mb-3 block"></i>
                                <p class="text-gray-500 font-medium">Click or drag & drop your screenshot here</p>
                                <p class="text-gray-400 text-xs mt-1">JPG, PNG, WEBP · Max 5MB</p>
                            </div>
                            <img id="previewImg" class="max-h-48 mx-auto rounded-lg hidden">
                        </div>
                        <input type="file" id="slipFile" name="slip_payment" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="previewFile(this)" required>
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-xl transition shadow-lg text-lg">
                            <i class="fas fa-paper-plane mr-2"></i>Submit Package Payment
                        </button>
                    </form>
                </div>

            <?php endif; ?>
        </main>
    </div>
</div>
<script>
function previewFile(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('uploadPlaceholder').classList.add('hidden');
        const img = document.getElementById('previewImg');
        img.src = e.target.result;
        img.classList.remove('hidden');
    };
    reader.readAsDataURL(file);
}
</script>
</body>
</html>