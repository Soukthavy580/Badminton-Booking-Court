<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$admin_id = $_SESSION['user_id'];
$error    = '';
$success  = '';

// ── Fetch admin data ──
try {
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE Admin_ID = ? LIMIT 1");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
} catch (PDOException $e) { $admin = null; }

// ── UPDATE PROFILE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $name     = trim($_POST['name']     ?? '');
    $surname  = trim($_POST['surname']  ?? '');
    $gender   = $_POST['gender']        ?? '';

    if (empty($name)) {
        $error = 'Name is required.';
    } else {
        try {
            // Handle QR/payment image upload
            $image_pay = $admin['Image_pay'] ?? '';
            if (!empty($_FILES['image_pay']['name'])) {
                $file = $_FILES['image_pay'];
                if (in_array($file['type'], ['image/jpeg','image/png','image/jpg','image/webp']) && $file['size'] <= 5*1024*1024) {
                    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'AdminQrcode_' . time() . '.' . $ext;
                    $dir      = $_SERVER['DOCUMENT_ROOT'] . '/Badminton_court_Booking/assets/images/qr/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                        $image_pay = $filename;
                    }
                } else {
                    $error = 'Image must be JPG/PNG/WEBP under 5MB.';
                }
            }

            if (empty($error)) {
                $pdo->prepare("UPDATE admin SET Name = ?, Surname = ?, Gender = ?, Image_pay = ? WHERE Admin_ID = ?")
                    ->execute([$name, $surname, $gender, $image_pay, $admin_id]);
                $_SESSION['user_name'] = $name;
                $success = 'Profile updated successfully!';
                $stmt = $pdo->prepare("SELECT * FROM admin WHERE Admin_ID = ? LIMIT 1");
                $stmt->execute([$admin_id]);
                $admin = $stmt->fetch();
            }
        } catch (PDOException $e) {
            $error = 'Failed to update profile: ' . $e->getMessage();
        }
    }
}

// ── CHANGE PASSWORD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_pw = $_POST['current_password'] ?? '';
    $new_pw     = $_POST['new_password']     ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';

    if (empty($current_pw) || empty($new_pw) || empty($confirm_pw)) {
        $error = 'All password fields are required.';
    } elseif (strlen($new_pw) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new_pw !== $confirm_pw) {
        $error = 'New passwords do not match.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT Password FROM admin WHERE Admin_ID = ?");
            $stmt->execute([$admin_id]);
            $stored = $stmt->fetchColumn();
            $valid  = password_verify($current_pw, $stored) || ($stored === $current_pw);
            if (!$valid) {
                $error = 'Current password is incorrect.';
            } else {
                $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE admin SET Password = ? WHERE Admin_ID = ?")
                    ->execute([$hashed, $admin_id]);
                $success = 'Password changed successfully!';
            }
        } catch (PDOException $e) {
            $error = 'Failed to change password.';
        }
    }
}

$image_pay_url = !empty($admin['Image_pay'])
    ? '/Badminton_court_Booking/assets/images/qr/' . basename($admin['Image_pay'])
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - Badminton Booking Court</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .tab-btn { transition: all 0.2s; }
        .tab-btn.active { background: #2563eb; color: white; }
        .upload-box { border: 2px dashed #d1d5db; transition: all 0.3s; cursor: pointer; }
        .upload-box:hover { border-color: #2563eb; background: #eff6ff; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <h1 class="text-xl font-bold text-gray-800">My Profile</h1>
            <p class="text-sm text-gray-500">Manage your admin account details</p>
        </header>

        <main class="flex-1 p-6 max-w-3xl mx-auto w-full">

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

            <!-- Avatar card -->
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6 flex items-center gap-5">
                <div class="w-20 h-20 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-extrabold text-3xl flex-shrink-0">
                    <?= strtoupper(substr($admin['Name'] ?? 'A', 0, 1)) ?>
                </div>
                <div>
                    <p class="text-xl font-bold text-gray-800">
                        <?= htmlspecialchars(($admin['Name'] ?? '') . ' ' . ($admin['Surname'] ?? '')) ?>
                    </p>
                    <p class="text-sm text-gray-500">@<?= htmlspecialchars($admin['Username'] ?? '') ?></p>
                    <span class="inline-flex items-center gap-1 mt-1 bg-blue-100 text-blue-700 text-xs font-bold px-3 py-1 rounded-full">
                        <i class="fas fa-shield-alt text-xs"></i> Administrator
                    </span>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-2 mb-6 bg-white rounded-2xl p-2 shadow-sm">
                <button onclick="switchTab('info')" id="tab_info"
                        class="tab-btn active flex-1 py-2.5 px-4 rounded-xl font-semibold text-sm">
                    <i class="fas fa-user mr-2"></i>Edit Profile
                </button>
                <button onclick="switchTab('password')" id="tab_password"
                        class="tab-btn flex-1 py-2.5 px-4 rounded-xl font-semibold text-sm text-gray-600">
                    <i class="fas fa-lock mr-2"></i>Change Password
                </button>
            </div>

            <!-- ── EDIT PROFILE TAB ── -->
            <div id="pane_info">
                <form method="POST" enctype="multipart/form-data">
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h2 class="font-bold text-gray-800 text-lg mb-5">
                            <i class="fas fa-info-circle text-blue-500 mr-2"></i>Account Information
                        </h2>
                        <div class="space-y-4">

                            <!-- Name -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="name"
                                           value="<?= htmlspecialchars($admin['Name'] ?? '') ?>"
                                           placeholder="First name"
                                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-blue-500 transition" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">Surname</label>
                                    <input type="text" name="surname"
                                           value="<?= htmlspecialchars($admin['Surname'] ?? '') ?>"
                                           placeholder="Last name"
                                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-blue-500 transition">
                                </div>
                            </div>

                            <!-- Gender -->
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Gender</label>
                                <select name="gender"
                                        class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-blue-500 transition">
                                    <option value="">-- Select Gender --</option>
                                    <option value="Male"   <?= ($admin['Gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= ($admin['Gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>

                            <!-- Username (read-only) -->
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Username</label>
                                <input type="text"
                                       value="<?= htmlspecialchars($admin['Username'] ?? '') ?>"
                                       class="w-full border-2 border-gray-100 bg-gray-50 rounded-xl px-4 py-3 text-gray-400 cursor-not-allowed" disabled>
                                <p class="text-xs text-gray-400 mt-1">Username cannot be changed.</p>
                            </div>

                            <!-- Payment QR Image -->
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">
                                    <i class="fas fa-qrcode text-blue-500 mr-1"></i>Payment QR Code (Image_pay)
                                </label>
                                <div class="upload-box rounded-xl p-4 text-center"
                                     onclick="document.getElementById('qrInput').click()">
                                    <?php if ($image_pay_url): ?>
                                        <img src="<?= htmlspecialchars($image_pay_url) ?>"
                                             id="qrPreview"
                                             class="w-32 h-32 object-contain rounded-lg mx-auto mb-2">
                                        <p class="text-xs text-gray-400">Click to change QR image</p>
                                    <?php else: ?>
                                        <div id="qrPreviewWrap">
                                            <i class="fas fa-qrcode text-4xl text-gray-300 mb-2 block"></i>
                                            <p class="text-gray-400 text-sm">Click to upload QR code</p>
                                        </div>
                                        <img id="qrPreview" class="w-32 h-32 object-contain rounded-lg mx-auto mb-2 hidden">
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="qrInput" name="image_pay" accept="image/*" class="hidden"
                                       onchange="previewQR(this)">
                                <p class="text-xs text-gray-400 mt-1">Shown to owners when they pay for packages/ads. Max 5MB.</p>
                            </div>

                        </div>
                        <button type="submit" name="save_profile"
                                class="mt-6 w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition shadow-md">
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- ── CHANGE PASSWORD TAB ── -->
            <div id="pane_password" class="hidden">
                <form method="POST">
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h2 class="font-bold text-gray-800 text-lg mb-5">
                            <i class="fas fa-lock text-blue-500 mr-2"></i>Change Password
                        </h2>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Current Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" name="current_password" id="cur_pw"
                                           placeholder="Enter current password"
                                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 pr-12 focus:outline-none focus:border-blue-500 transition" required>
                                    <i class="fas fa-eye absolute right-4 top-3.5 text-gray-400 cursor-pointer"
                                       onclick="togglePw('cur_pw', this)"></i>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">New Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" name="new_password" id="new_pw"
                                           placeholder="Min 6 characters"
                                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 pr-12 focus:outline-none focus:border-blue-500 transition" required>
                                    <i class="fas fa-eye absolute right-4 top-3.5 text-gray-400 cursor-pointer"
                                       onclick="togglePw('new_pw', this)"></i>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Confirm New Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" name="confirm_password" id="con_pw"
                                           placeholder="Repeat new password"
                                           oninput="checkMatch()"
                                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 pr-12 focus:outline-none focus:border-blue-500 transition" required>
                                    <i class="fas fa-eye absolute right-4 top-3.5 text-gray-400 cursor-pointer"
                                       onclick="togglePw('con_pw', this)"></i>
                                </div>
                                <p id="matchText" class="text-xs mt-1 hidden"></p>
                            </div>
                        </div>
                        <button type="submit" name="change_password"
                                class="mt-6 w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition shadow-md">
                            <i class="fas fa-key mr-2"></i>Update Password
                        </button>
                    </div>
                </form>
            </div>

        </main>
    </div>
</div>

<script>
function switchTab(tab) {
    ['info','password'].forEach(t => {
        document.getElementById('pane_' + t).classList.toggle('hidden', t !== tab);
        document.getElementById('tab_' + t).classList.toggle('active', t === tab);
        if (t !== tab) document.getElementById('tab_' + t).classList.add('text-gray-600');
        else document.getElementById('tab_' + t).classList.remove('text-gray-600');
    });
}
function togglePw(id, icon) {
    const input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}
function checkMatch() {
    const np = document.getElementById('new_pw').value;
    const cp = document.getElementById('con_pw').value;
    const el = document.getElementById('matchText');
    if (!cp.length) { el.classList.add('hidden'); return; }
    el.classList.remove('hidden');
    if (np === cp) {
        el.textContent = '✓ Passwords match';
        el.className = 'text-xs mt-1 text-green-600';
    } else {
        el.textContent = '✗ Passwords do not match';
        el.className = 'text-xs mt-1 text-red-500';
    }
}
function previewQR(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const img  = document.getElementById('qrPreview');
        const wrap = document.getElementById('qrPreviewWrap');
        img.src = e.target.result;
        img.classList.remove('hidden');
        if (wrap) wrap.classList.add('hidden');
    };
    reader.readAsDataURL(file);
}
</script>
</body>
</html>