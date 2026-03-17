<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$c_id  = $_SESSION['c_id'];
$error = '';

try {
    $stmt = $pdo->prepare("SELECT * FROM customer WHERE C_ID = ? LIMIT 1");
    $stmt->execute([$c_id]);
    $customer = $stmt->fetch();
} catch (PDOException $e) { $customer = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $gender   = $_POST['gender']        ?? '';
    $phone    = trim($_POST['phone']    ?? '');
    $email    = trim($_POST['email']    ?? '');
    $username = trim($_POST['username'] ?? '');
    $new_pass = $_POST['new_password']     ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($phone) || empty($email) || empty($username)) {
        $error = 'ກະລຸນາຕື່ມຂໍ້ມູນໃຫ້ຄົບ.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'ຮູບແບບອີເມລ໌ບໍ່ຖືກຕ້ອງ.';
    } elseif (!empty($new_pass) && strlen($new_pass) < 6) {
        $error = 'ລະຫັດຜ່ານຕ້ອງມີຢ່າງໜ້ອຍ 6 ຕົວອັກສອນ.';
    } elseif (!empty($new_pass) && $new_pass !== $confirm) {
        $error = 'ລະຫັດຜ່ານບໍ່ກົງກັນ.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT C_ID FROM customer WHERE Email = ? AND C_ID != ?");
            $stmt->execute([$email, $c_id]);
            if ($stmt->fetch()) {
                $error = 'ອີເມລ໌ນີ້ຖືກໃຊ້ໂດຍບັນຊີອື່ນແລ້ວ.';
            } else {
                $stmt = $pdo->prepare("SELECT C_ID FROM customer WHERE Username = ? AND C_ID != ?");
                $stmt->execute([$username, $c_id]);
                if ($stmt->fetch()) {
                    $error = 'ຊື່ຜູ້ໃຊ້ນີ້ຖືກໃຊ້ໄປແລ້ວ.';
                } else {
                    $pdo->prepare("
                        UPDATE customer SET Name=?, Gender=?, Phone=?, Email=?, Username=?
                        WHERE C_ID=?
                    ")->execute([$name, $gender, $phone, $email, $username, $c_id]);

                    if (!empty($new_pass)) {
                        $pdo->prepare("UPDATE customer SET Password=? WHERE C_ID=?")
                            ->execute([password_hash($new_pass, PASSWORD_DEFAULT), $c_id]);
                    }

                    $_SESSION['user_name']  = $name;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['profile_success'] = 'ອັບເດດໂປຣໄຟລ໌ສຳເລັດ!';

                    header('Location: /Badminton_court_Booking/customer/profile/index.php');
                    exit;
                }
            }
        } catch (PDOException $e) {
            error_log("Profile update error: " . $e->getMessage());
            $error = 'ອັບເດດລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
        }
    }

    // Keep form filled on error
    $customer['Name']     = $name;
    $customer['Gender']   = $gender;
    $customer['Phone']    = $phone;
    $customer['Email']    = $email;
    $customer['Username'] = $username;
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ແກ້ໄຂໂປຣໄຟລ໌ - ລະບົບຈອງເດີ່ນ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>

    <div class="max-w-2xl mx-auto px-4 py-8">

        <a href="/Badminton_court_Booking/customer/profile/index.php"
           class="inline-flex items-center gap-2 text-gray-600 hover:text-blue-600 mb-6 font-medium transition">
            <i class="fas fa-arrow-left"></i> ກັບໄປໂປຣໄຟລ໌
        </a>

        <div class="bg-white rounded-2xl shadow-sm p-8">
            <div class="flex items-center gap-4 mb-8">
                <div class="w-16 h-16 bg-gradient-to-br from-green-400 to-blue-500 rounded-full flex items-center justify-center text-white text-2xl font-bold">
                    <?= strtoupper(substr($customer['Name'] ?? 'U', 0, 1)) ?>
                </div>
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-800">ແກ້ໄຂໂປຣໄຟລ໌</h1>
                    <p class="text-gray-500 text-sm">ອັບເດດຂໍ້ມູນສ່ວນຕົວຂອງທ່ານ</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl flex items-center gap-3">
                    <i class="fas fa-exclamation-circle text-xl"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="">

                <!-- Full Name -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2 text-sm">ຊື່ເຕັມ <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="text" name="name"
                               value="<?= htmlspecialchars($customer['Name'] ?? '') ?>"
                               placeholder="ຊື່ເຕັມຂອງທ່ານ"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition" required>
                        <i class="fas fa-user absolute right-4 top-3.5 text-gray-400"></i>
                    </div>
                </div>

                <!-- Gender -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2 text-sm">ເພດ</label>
                    <select name="gender"
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition">
                        <option value="Male"   <?= ($customer['Gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>ຊາຍ</option>
                        <option value="Female" <?= ($customer['Gender'] ?? '') === 'Female' ? 'selected' : '' ?>>ຍິງ</option>
                    </select>
                </div>

                <!-- Phone -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2 text-sm">ເບີໂທ <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="tel" name="phone"
                               value="<?= htmlspecialchars($customer['Phone'] ?? '') ?>"
                               placeholder="020 XXXX XXXX"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition" required>
                        <i class="fas fa-phone absolute right-4 top-3.5 text-gray-400"></i>
                    </div>
                </div>

                <!-- Email -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2 text-sm">ອີເມລ໌ <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="email" name="email"
                               value="<?= htmlspecialchars($customer['Email'] ?? '') ?>"
                               placeholder="example@email.com"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition" required>
                        <i class="fas fa-envelope absolute right-4 top-3.5 text-gray-400"></i>
                    </div>
                </div>

                <!-- Username -->
                <div class="mb-6">
                    <label class="block text-gray-700 font-medium mb-2 text-sm">ຊື່ຜູ້ໃຊ້ <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="text" name="username"
                               value="<?= htmlspecialchars($customer['Username'] ?? '') ?>"
                               placeholder="ຊື່ຜູ້ໃຊ້ຂອງທ່ານ"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition" required>
                        <i class="fas fa-at absolute right-4 top-3.5 text-gray-400"></i>
                    </div>
                </div>

                <!-- Change Password -->
                <div class="border-t border-gray-100 mb-6 pt-6">
                    <h3 class="font-bold text-gray-700 mb-1">ປ່ຽນລະຫັດຜ່ານ</h3>
                    <p class="text-xs text-gray-400 mb-4">ປ່ອຍຫວ່າງເພື່ອຮັກສາລະຫັດຜ່ານເດີມ</p>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 text-sm">ລະຫັດຜ່ານໃໝ່</label>
                        <div class="relative">
                            <input type="password" name="new_password" id="new_password"
                                   placeholder="ຢ່າງໜ້ອຍ 6 ຕົວອັກສອນ"
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition">
                            <i class="fas fa-eye absolute right-4 top-3.5 text-gray-400 cursor-pointer"
                               onclick="togglePass('new_password', this)"></i>
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2 text-sm">ຢືນຢັນລະຫັດຜ່ານໃໝ່</label>
                        <div class="relative">
                            <input type="password" name="confirm_password" id="confirm_password"
                                   placeholder="ກ້ຳລະຫັດຜ່ານໃໝ່"
                                   oninput="checkMatch()"
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition">
                            <i class="fas fa-eye absolute right-4 top-3.5 text-gray-400 cursor-pointer"
                               onclick="togglePass('confirm_password', this)"></i>
                        </div>
                        <p id="matchText" class="text-xs mt-1 hidden"></p>
                    </div>
                </div>

                <!-- Buttons -->
                <div class="flex gap-3">
                    <button type="submit"
                            class="flex-1 bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition shadow-lg">
                        <i class="fas fa-save mr-2"></i>ບັນທຶກການປ່ຽນແປງ
                    </button>
                    <a href="/Badminton_court_Booking/customer/profile/index.php"
                       class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition">
                        ຍົກເລີກ
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function togglePass(id, icon) {
            const input = document.getElementById(id);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        function checkMatch() {
            const p1  = document.getElementById('new_password').value;
            const p2  = document.getElementById('confirm_password').value;
            const txt = document.getElementById('matchText');
            if (!p2) { txt.classList.add('hidden'); return; }
            txt.classList.remove('hidden');
            if (p1 === p2) {
                txt.textContent = '✓ ລະຫັດຜ່ານກົງກັນ';
                txt.className = 'text-xs text-green-600 mt-1';
            } else {
                txt.textContent = '✗ ລະຫັດຜ່ານບໍ່ກົງກັນ';
                txt.className = 'text-xs text-red-500 mt-1';
            }
        }
    </script>
</body>
</html>