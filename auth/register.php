<?php
session_start();
require_once '../config/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ../customer/index.php');
    exit;
}

$error     = '';
$success   = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data = $_POST;
    $role      = $_POST['role']            ?? '';
    $name      = trim($_POST['name']       ?? '');
    $gender    = $_POST['gender']          ?? '';
    $phone     = trim($_POST['phone']      ?? '');
    $email     = trim($_POST['email']      ?? '');
    $username  = trim($_POST['username']   ?? '');
    $password  = $_POST['password']        ?? '';
    $confirm   = $_POST['confirm_password']?? '';

    if (empty($role)) {
        $error = 'ກະລຸນາເລືອກບົດບາດ';
    } elseif (empty($name)) {
        $error = 'ກະລຸນາໃສ່ຊື່ເຕັມ';
    } elseif ($role === 'customer' && empty($gender)) {
        $error = 'ກະລຸນາເລືອກເພດ';
    } elseif (empty($phone)) {
        $error = 'ກະລຸນາໃສ່ເບີໂທ';
    } elseif (empty($email) || empty($username) || empty($password)) {
        $error = 'ກະລຸນາຕື່ມຂໍ້ມູນໃຫ້ຄົບ';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'ຮູບແບບອີເມວບໍ່ຖືກຕ້ອງ';
    } elseif ($password !== $confirm) {
        $error = 'ລະຫັດຜ່ານບໍ່ກົງກັນ';
    } elseif (strlen($password) < 6) {
        $error = 'ລະຫັດຜ່ານຕ້ອງມີຢ່າງໜ້ອຍ 6 ຕົວອັກສອນ';
    } else {
        try {
            $check_owner    = get_user_by_email($email, 'court_owner');
            $check_customer = get_user_by_email($email, 'customer');

            if ($check_owner || $check_customer) {
                $error = 'ອີເມວນີ້ຖືກໃຊ້ແລ້ວ. ກະລຸນາໃຊ້ອີເມວອື່ນ ຫຼື <a href="login.php" class="underline font-bold">ເຂົ້າສູ່ລະບົບ</a>.';
            } else {
                $check_un_owner    = get_user_by_username($username, 'court_owner');
                $check_un_customer = get_user_by_username($username, 'customer');

                if ($check_un_owner || $check_un_customer) {
                    $error = 'ຊື່ຜູ້ໃຊ້ນີ້ຖືກໃຊ້ໄປແລ້ວ. ກະລຸນາເລືອກຊື່ອື່ນ.';
                } else {
                    $user_id = false;

                    if ($role === 'customer') {
                        // FIX: No surname parameter
                        $user_id = create_customer($name, $gender, $phone, $email, $username, $password);
                        if ($user_id) $success = 'ສ້າງບັນຊີລູກຄ້າສຳເລັດ! ກຳລັງໄປໜ້າເຂົ້າສູ່ລະບົບ...';
                    } elseif ($role === 'owner') {
                        // FIX: No surname parameter
                        $user_id = create_court_owner($name, $phone, $email, $username, $password);
                        if ($user_id) $success = 'ສ້າງບັນຊີເຈົ້າຂອງເດີ່ນສຳເລັດ! ກຳລັງໄປໜ້າເຂົ້າສູ່ລະບົບ...';
                    }

                    if ($user_id) {
                        header('Refresh: 2; url=login.php?registered=success');
                        $form_data = [];
                    } else {
                        $error = 'ການລົງທະບຽນລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            $error = 'ການລົງທະບຽນລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລົງທະບຽນ - ລະບົບຈອງເດີ່ນ</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .hero-image {
            background: linear-gradient(rgba(0,0,0,0.3), rgba(0,0,0,0.3)),
                        url('https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?w=800') center/cover;
            position: fixed; right: 0; top: 0; width: 50%; height: 100vh;
        }
        .password-toggle { cursor: pointer; transition: color 0.3s ease; }
        .password-toggle:hover { color: #2563eb; }
        @media (max-width: 768px) { .hero-image { display: none; } }
        .role-card {
            cursor: pointer; transition: all 0.3s ease;
            border: 2px solid #e5e7eb; background: white;
        }
        .role-card:hover { transform: translateY(-4px); box-shadow: 0 8px 16px rgba(0,0,0,0.1); border-color: #22c55e; }
        .role-card.selected { border-color: #16a34a; background: #f0fdf4; box-shadow: 0 4px 12px rgba(34,197,94,0.25); }
        .role-card .role-icon { color: #22c55e; font-size: 2rem; margin-bottom: 0.5rem; }
        .role-card .role-name { color: #14532d; font-weight: 600; font-size: 1rem; }
    </style>
</head>
<body class="min-h-screen bg-gray-50">
<div class="flex min-h-screen overflow-hidden">

    <div class="w-full md:w-1/2 flex items-start justify-center p-8 overflow-y-auto min-h-screen">
        <div class="w-full max-w-md">

            <div class="mb-6">
                <a href="../customer/index.php"
                   class="text-gray-500 hover:text-gray-700 text-sm flex items-center gap-2 transition">
                    <i class="fas fa-arrow-left"></i>ກັບໄປໜ້າຫຼັກ
                </a>
            </div>

            <!-- Logo -->
            <div class="mb-8 flex items-center gap-3">
                <img src="/Badminton_court_Booking/assets/images/logo/Logo.png"
                     alt="Badminton Booking Court"
                     class="h-14 w-auto object-contain"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                <span style="display:none" class="items-center gap-2">
                    <i class="fas fa-table-tennis text-green-600 text-2xl"></i>
                </span>
                <div>
                    <p class="text-lg font-bold bg-gradient-to-r from-green-600 to-blue-600 bg-clip-text text-transparent leading-tight">
                        Badminton Booking Court
                    </p>
                    <p class="text-gray-500 text-xs">ລະບົບຈອງເດີ່ນແບດມິນຕັນ</p>
                </div>
            </div>

            <h3 class="text-2xl font-bold text-gray-800 mb-8">ສ້າງບັນຊີ</h3>

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg flex items-center gap-2">
                    <i class="fas fa-exclamation-circle flex-shrink-0"></i>
                    <span><?= $error ?></span>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg flex items-center gap-2">
                    <i class="fas fa-check-circle flex-shrink-0"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="signupForm">

                <!-- Role Selection -->
                <div class="mb-6">
                    <label class="block text-gray-700 font-medium mb-3 flex items-center">
                        <i class="fas fa-user-tag text-green-600 mr-2"></i>
                        <span>ລົງທະບຽນເປັນ</span>
                    </label>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="role-card rounded-lg p-4 text-center <?= ($form_data['role'] ?? '') === 'customer' ? 'selected' : '' ?>"
                             onclick="selectRole('customer')">
                            <input type="radio" name="role" value="customer" id="role_customer" class="hidden"
                                   <?= ($form_data['role'] ?? '') === 'customer' ? 'checked' : '' ?> required>
                            <label for="role_customer" class="cursor-pointer block">
                                <i class="fas fa-user role-icon"></i>
                                <p class="role-name">ລູກຄ້າ</p>
                            </label>
                        </div>
                        <div class="role-card rounded-lg p-4 text-center <?= ($form_data['role'] ?? '') === 'owner' ? 'selected' : '' ?>"
                             onclick="selectRole('owner')">
                            <input type="radio" name="role" value="owner" id="role_owner" class="hidden"
                                   <?= ($form_data['role'] ?? '') === 'owner' ? 'checked' : '' ?> required>
                            <label for="role_owner" class="cursor-pointer block">
                                <i class="fas fa-building role-icon"></i>
                                <p class="role-name">ເຈົ້າຂອງເດີ່ນ</p>
                            </label>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2" id="roleDescription"></p>
                </div>

                <!-- Full Name — NO surname field -->
                <div class="mb-6">
                    <label for="name" class="block text-gray-700 font-medium mb-2">ຊື່ເຕັມ</label>
                    <div class="relative">
                        <input type="text" id="name" name="name"
                               placeholder="ໃສ່ຊື່ເຕັມຂອງທ່ານ"
                               value="<?= htmlspecialchars($form_data['name'] ?? '') ?>"
                               class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-green-500 transition-colors" required>
                        <i class="fas fa-user absolute right-4 top-4 text-gray-400"></i>
                    </div>
                </div>

                <!-- Gender (customer only) -->
                <div class="mb-6" id="genderField" style="display:none;">
                    <label for="gender" class="block text-gray-700 font-medium mb-2">ເພດ</label>
                    <select id="gender" name="gender"
                            class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-green-500 transition-colors">
                        <option value="">-- ເລືອກເພດ --</option>
                        <option value="Male"   <?= ($form_data['gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>ຊາຍ</option>
                        <option value="Female" <?= ($form_data['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>ຍິງ</option>
                    </select>
                </div>

                <!-- Phone -->
                <div class="mb-6">
                    <label for="phone" class="block text-gray-700 font-medium mb-2">ເບີໂທ</label>
                    <div class="relative">
                        <input type="tel" id="phone" name="phone"
                               placeholder="020 XXXX XXXX"
                               value="<?= htmlspecialchars($form_data['phone'] ?? '') ?>"
                               class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-green-500 transition-colors" required>
                        <i class="fas fa-phone absolute right-4 top-4 text-gray-400"></i>
                    </div>
                </div>

                <!-- Email -->
                <div class="mb-6">
                    <label for="email" class="block text-gray-700 font-medium mb-2">ອີເມວ</label>
                    <div class="relative">
                        <input type="email" id="email" name="email"
                               placeholder="ໃສ່ອີເມວຂອງທ່ານ"
                               value="<?= htmlspecialchars($form_data['email'] ?? '') ?>"
                               class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-green-500 transition-colors" required>
                        <i class="fas fa-envelope absolute right-4 top-4 text-gray-400"></i>
                    </div>
                </div>

                <!-- Username -->
                <div class="mb-6">
                    <label for="username" class="block text-gray-700 font-medium mb-2">ຊື່ຜູ້ໃຊ້</label>
                    <div class="relative">
                        <input type="text" id="username" name="username"
                               placeholder="ເລືອກຊື່ຜູ້ໃຊ້"
                               value="<?= htmlspecialchars($form_data['username'] ?? '') ?>"
                               class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-green-500 transition-colors" required>
                        <i class="fas fa-at absolute right-4 top-4 text-gray-400"></i>
                    </div>
                </div>

                <!-- Password -->
                <div class="mb-6">
                    <label for="password" class="block text-gray-700 font-medium mb-2">ລະຫັດຜ່ານ</label>
                    <div class="relative">
                        <input type="password" id="password" name="password"
                               placeholder="ລະຫັດຜ່ານ (ຢ່າງໜ້ອຍ 6 ຕົວ)"
                               class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-green-500 transition-colors" required>
                        <i class="fas fa-eye password-toggle absolute right-4 top-4 text-gray-400"
                           onclick="togglePassword('password','toggleIcon1')" id="toggleIcon1"></i>
                    </div>
                </div>

                <!-- Confirm Password -->
                <div class="mb-8">
                    <label for="confirm_password" class="block text-gray-700 font-medium mb-2">ຢືນຢັນລະຫັດຜ່ານ</label>
                    <div class="relative">
                        <input type="password" id="confirm_password" name="confirm_password"
                               placeholder="ກ້ຳລະຫັດຜ່ານ"
                               class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-green-500 transition-colors"
                               oninput="checkPasswordMatch()" required>
                        <i class="fas fa-eye password-toggle absolute right-4 top-4 text-gray-400"
                           onclick="togglePassword('confirm_password','toggleIcon2')" id="toggleIcon2"></i>
                    </div>
                    <p id="matchText" class="text-xs mt-1 hidden"></p>
                </div>

                <button type="submit"
                        class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold py-3 rounded-lg transition-all transform hover:scale-[1.02] shadow-lg">
                    <i class="fas fa-user-plus mr-2"></i>ສ້າງບັນຊີ
                </button>

                <p class="text-center text-gray-600 text-sm mt-6">
                    ມີບັນຊີແລ້ວ?
                    <a href="login.php" class="text-green-600 hover:text-green-700 font-bold">ເຂົ້າສູ່ລະບົບ</a>
                </p>
            </form>
        </div>
    </div>

    <div class="hidden md:block md:w-1/2 flex-shrink-0"></div>
</div>

<div class="hero-image hidden md:block">
    <div class="absolute inset-0 flex items-center justify-center">
        <div class="text-center text-white p-8">
            <h2 class="text-4xl font-bold mb-4">ເຂົ້າຮ່ວມກັບ Badminton Booking Court</h2>
            <p class="text-xl mb-6">ເລີ່ມຈອງເດີ່ນທີ່ທ່ານມັກໄດ້ເລີຍ</p>
            <div class="flex justify-center gap-4 text-sm flex-wrap">
                <div class="flex items-center"><i class="fas fa-check-circle mr-2"></i><span>ລົງທະບຽນຟຣີ</span></div>
                <div class="flex items-center"><i class="fas fa-check-circle mr-2"></i><span>ຈອງໄວ</span></div>
                <div class="flex items-center"><i class="fas fa-check-circle mr-2"></i><span>ລາຄາດີທີ່ສຸດ</span></div>
            </div>
        </div>
    </div>
</div>

<script>
    function togglePassword(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    function checkPasswordMatch() {
        const p1  = document.getElementById('password').value;
        const p2  = document.getElementById('confirm_password').value;
        const txt = document.getElementById('matchText');
        if (p2.length === 0) { txt.classList.add('hidden'); return; }
        if (p1 === p2) {
            txt.textContent = '✓ ລະຫັດຜ່ານກົງກັນ';
            txt.className   = 'text-xs text-green-600 mt-1';
            txt.classList.remove('hidden');
        } else {
            txt.textContent = '✗ ລະຫັດຜ່ານບໍ່ກົງກັນ';
            txt.className   = 'text-xs text-red-500 mt-1';
            txt.classList.remove('hidden');
        }
    }

    function selectRole(role) {
        document.querySelectorAll('.role-card').forEach(c => c.classList.remove('selected'));
        event.currentTarget.classList.add('selected');
        document.getElementById('role_' + role).checked = true;
        updateRoleDescription(role);
        toggleGenderField(role);
    }

    function updateRoleDescription(role) {
        const desc = document.getElementById('roleDescription');
        desc.textContent = role === 'customer'
            ? 'ຈອງເດີ່ນ ແລະ ຈັດການການຈອງຂອງທ່ານ'
            : role === 'owner' ? 'ລົງທະບຽນສະຖານທີ່ຂອງທ່ານຫຼັງຊື້ແພັກເກດ' : '';
    }

    function toggleGenderField(role) {
        const field  = document.getElementById('genderField');
        const select = document.getElementById('gender');
        if (role === 'customer') { field.style.display = 'block'; select.required = true; }
        else { field.style.display = 'none'; select.required = false; select.value = ''; }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const checked = document.querySelector('input[name="role"]:checked');
        if (checked) {
            checked.closest('.role-card').classList.add('selected');
            updateRoleDescription(checked.value);
            toggleGenderField(checked.value);
        }
    });
</script>
</body>
</html>