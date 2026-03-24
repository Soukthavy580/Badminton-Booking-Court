<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

date_default_timezone_set('Asia/Vientiane');

$error            = '';
$success          = '';
$current_admin_id = $_SESSION['admin_id'];

// Add new admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    $name     = trim($_POST['name']     ?? '');
    $surname  = trim($_POST['surname']  ?? '');
    $email    = trim($_POST['email']    ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']      ?? '';

    if (!$name || !$username || !$password) {
        $error = 'ກະລຸນາໃສ່ຊື່, Username ແລະ ລະຫັດຜ່ານ.';
    } else {
        try {
            // Check username not already used
            $check = $pdo->prepare("SELECT COUNT(*) FROM admin WHERE Username = ?");
            $check->execute([$username]);
            if ($check->fetchColumn() > 0) {
                $error = 'Username ນີ້ຖືກໃຊ້ງານແລ້ວ.';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("
                    INSERT INTO admin (Name, Surname, Email, Phone, Username, Password)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$name, $surname, $email, $phone, $username, $hashed]);
                $success = 'ເພີ່ມແອດມິນໃໝ່ສຳເລັດ!';
            }
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ: ' . $e->getMessage();
        }
    }
}

// Delete admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_admin'])) {
    $admin_id = intval($_POST['admin_id'] ?? 0);
    if ($admin_id === (int)$current_admin_id) {
        $error = 'ບໍ່ສາມາດລຶບຕົວເອງໄດ້.';
    } elseif ($admin_id) {
        try {
            $pdo->prepare("DELETE FROM admin WHERE Admin_ID = ?")->execute([$admin_id]);
            $success = 'ລຶບແອດມິນສຳເລັດ.';
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ: ' . $e->getMessage();
        }
    }
}

// Fetch all admins
try {
    $admins = $pdo->query("SELECT * FROM admin ORDER BY Admin_ID ASC")->fetchAll();
} catch (PDOException $e) {
    $admins = [];
    $error  = 'ຜິດພາດ: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ຈັດການແອດມິນ - Admin</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">

        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">ຈັດການແອດມິນ</h1>
                    <p class="text-sm text-gray-500">ແອດມິນທັງໝົດໃນລະບົບ (<?= count($admins) ?> ຄົນ)</p>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6 max-w-5xl mx-auto w-full">

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

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Admin List -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                            <h2 class="font-bold text-gray-800">ລາຍຊື່ແອດມິນ</h2>
                            <span class="bg-blue-100 text-blue-700 text-xs font-bold px-3 py-1 rounded-full"><?= count($admins) ?> ຄົນ</span>
                        </div>
                        <div class="divide-y divide-gray-50">
                            <?php foreach ($admins as $admin):
                                $is_me    = (int)$admin['Admin_ID'] === (int)$current_admin_id;
                                $fullname = trim(htmlspecialchars($admin['Name']) . ' ' . htmlspecialchars($admin['Surname'] ?? ''));
                            ?>
                                <div class="flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition">
                                    <div class="flex items-center gap-4">
                                        <div class="w-11 h-11 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                            <?= strtoupper(substr($admin['Name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <p class="font-semibold text-gray-800"><?= $fullname ?></p>
                                                <?php if ($is_me): ?>
                                                    <span class="bg-blue-100 text-blue-600 text-xs font-bold px-2 py-0.5 rounded-full">ຂ້ອຍ</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-xs text-gray-500">
                                                <i class="fas fa-user text-gray-300 mr-1"></i><?= htmlspecialchars($admin['Username']) ?>
                                            </p>
                                            <?php if (!empty($admin['Email'])): ?>
                                                <p class="text-xs text-gray-400">
                                                    <i class="fas fa-envelope text-blue-300 mr-1"></i><?= htmlspecialchars($admin['Email']) ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($admin['Phone'])): ?>
                                                <p class="text-xs text-gray-400">
                                                    <i class="fas fa-phone text-green-300 mr-1"></i><?= htmlspecialchars($admin['Phone']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <span class="text-xs text-gray-400">#<?= $admin['Admin_ID'] ?></span>
                                        <?php if (!$is_me): ?>
                                            <form method="POST" class="inline"
                                                  onsubmit="return confirm('ລຶບແອດມິນ <?= htmlspecialchars(addslashes($admin['Name'])) ?>?')">
                                                <input type="hidden" name="admin_id" value="<?= $admin['Admin_ID'] ?>">
                                                <button type="submit" name="delete_admin"
                                                        class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 px-3 py-1.5 rounded-lg text-xs font-bold transition">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-300 px-3 py-1.5">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($admins)): ?>
                                <div class="text-center py-10 text-gray-400">ຍັງບໍ່ມີແອດມິນ</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Add Admin Form -->
                <div>
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                            <i class="fas fa-user-plus text-blue-500"></i>ເພີ່ມແອດມິນໃໝ່
                        </h2>
                        <form method="POST" class="space-y-3">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5">ຊື່ <span class="text-red-500">*</span></label>
                                <input type="text" name="name" required placeholder="ຊື່"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-400 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5">ນາມສະກຸນ</label>
                                <input type="text" name="surname" placeholder="ນາມສະກຸນ"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-400 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5">Username <span class="text-red-500">*</span></label>
                                <input type="text" name="username" required placeholder="username"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-400 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5">ອີເມລ໌</label>
                                <input type="email" name="email" placeholder="admin@example.com"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-400 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5">ເບີໂທ</label>
                                <input type="text" name="phone" placeholder="020xxxxxxxx"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-400 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5">ລະຫັດຜ່ານ <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" name="password" id="adminPassword" required placeholder="ລະຫັດຜ່ານ"
                                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-2.5 pr-10 text-sm focus:outline-none focus:border-blue-400 transition">
                                    <button type="button" onclick="togglePassword()"
                                            class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                                        <i class="fas fa-eye" id="eyeIcon"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="submit" name="add_admin"
                                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition text-sm mt-2">
                                <i class="fas fa-plus mr-1"></i>ເພີ່ມແອດມິນ
                            </button>
                        </form>
                    </div>

                    <!-- Info -->
                    <div class="mt-4 bg-yellow-50 border border-yellow-200 rounded-2xl p-4">
                        <p class="text-xs font-bold text-yellow-700 mb-1"><i class="fas fa-info-circle mr-1"></i>ໝາຍເຫດ</p>
                        <ul class="text-xs text-yellow-600 space-y-1">
                            <li>• ໃຊ້ Username ເຂົ້າສູ່ລະບົບ</li>
                            <li>• ບໍ່ສາມາດລຶບຕົວເອງໄດ້</li>
                            <li>• Username ຕ້ອງບໍ່ຊ້ຳກັນ</li>
                        </ul>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>
<script>
function togglePassword() {
    const input = document.getElementById('adminPassword');
    const icon  = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>
</body>
</html>