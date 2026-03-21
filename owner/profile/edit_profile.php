<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id = $_SESSION['ca_id'];
$error = '';

try {
    $stmt = $pdo->prepare("SELECT * FROM court_owner WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $owner = $stmt->fetch();
} catch (PDOException $e) { $owner = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $surname  = trim($_POST['surname']  ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $email    = trim($_POST['email']    ?? '');
    $username = trim($_POST['username'] ?? '');
    $new_pass = $_POST['new_password']  ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($surname) || empty($phone) || empty($email) || empty($username)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif (!empty($new_pass) && strlen($new_pass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!empty($new_pass) && $new_pass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Check email uniqueness
            $stmt = $pdo->prepare("SELECT CA_ID FROM court_owner WHERE Email = ? AND CA_ID != ?");
            $stmt->execute([$email, $ca_id]);
            if ($stmt->fetch()) {
                $error = 'This email is already used by another account.';
            } else {
                $stmt = $pdo->prepare("SELECT CA_ID FROM court_owner WHERE Username = ? AND CA_ID != ?");
                $stmt->execute([$username, $ca_id]);
                if ($stmt->fetch()) {
                    $error = 'This username is already taken.';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE court_owner
                        SET Name=?, Surname=?, Phone=?, Email=?, Username=?
                        WHERE CA_ID=?
                    ");
                    $stmt->execute([$name, $surname, $phone, $email, $username, $ca_id]);

                    if (!empty($new_pass)) {
                        $pdo->prepare("UPDATE court_owner SET Password=? WHERE CA_ID=?")
                            ->execute([password_hash($new_pass, PASSWORD_DEFAULT), $ca_id]);
                    }

                    $_SESSION['user_name']  = $name;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['profile_success'] = 'Profile updated successfully!';
                    header('Location: /Badminton_court_Booking/owner/profile/index.php');
                    exit;
                }
            }
        } catch (PDOException $e) {
            error_log("Owner profile update error: " . $e->getMessage());
            $error = 'Update failed. Please try again.';
        }
    }

    // Keep form filled on error
    $owner['Name']     = $name;
    $owner['Surname']  = $surname;
    $owner['Phone']    = $phone;
    $owner['Email']    = $email;
    $owner['Username'] = $username;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - CourtBook Owner</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .strength-weak   { background-color: #ef4444; }
        .strength-medium { background-color: #f59e0b; }
        .strength-strong { background-color: #10b981; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col">
        <!-- Top Bar -->
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center gap-3">
                <a href="/Badminton_court_Booking/owner/profile/index.php"
                   class="text-gray-400 hover:text-gray-600 transition">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">Edit Profile</h1>
                    <p class="text-sm text-gray-500">Update your personal information</p>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6 max-w-2xl mx-auto w-full">

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl flex items-center gap-3">
                    <i class="fas fa-exclamation-circle text-xl"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-2xl shadow-sm p-8">

                <!-- Avatar Preview -->
                <div class="flex items-center gap-4 mb-8">
                    <div class="w-16 h-16 bg-gradient-to-br from-green-400 to-blue-500 rounded-full flex items-center justify-center text-white text-2xl font-bold">
                        <?= strtoupper(substr($owner['Name'] ?? 'O', 0, 1)) ?>
                    </div>
                    <div>
                        <h2 class="text-xl font-extrabold text-gray-800">Edit Profile</h2>
                        <p class="text-gray-400 text-sm">Court Owner Account</p>
                    </div>
                </div>

                <form method="POST">

                    <!-- Name Row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">First Name</label>
                            <div class="relative">
                                <input type="text" name="name"
                                       value="<?= htmlspecialchars($owner['Name'] ?? '') ?>"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition"
                                       required>
                                <i class="fas fa-user absolute right-4 top-3.5 text-gray-400"></i>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">Surname</label>
                            <div class="relative">
                                <input type="text" name="surname"
                                       value="<?= htmlspecialchars($owner['Surname'] ?? '') ?>"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition"
                                       required>
                                <i class="fas fa-user absolute right-4 top-3.5 text-gray-400"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Phone -->
                    <div class="mb-4">
                        <label class="block text-sm font-bold text-gray-700 mb-2">Phone Number</label>
                        <div class="relative">
                            <input type="tel" name="phone"
                                   value="<?= htmlspecialchars($owner['Phone'] ?? '') ?>"
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition"
                                   required>
                            <i class="fas fa-phone absolute right-4 top-3.5 text-gray-400"></i>
                        </div>
                    </div>

                    <!-- Email -->
                    <div class="mb-4">
                        <label class="block text-sm font-bold text-gray-700 mb-2">Email</label>
                        <div class="relative">
                            <input type="email" name="email"
                                   value="<?= htmlspecialchars($owner['Email'] ?? '') ?>"
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition"
                                   required>
                            <i class="fas fa-envelope absolute right-4 top-3.5 text-gray-400"></i>
                        </div>
                    </div>

                    <!-- Username -->
                    <div class="mb-6">
                        <label class="block text-sm font-bold text-gray-700 mb-2">Username</label>
                        <div class="relative">
                            <input type="text" name="username"
                                   value="<?= htmlspecialchars($owner['Username'] ?? '') ?>"
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition"
                                   required>
                            <i class="fas fa-at absolute right-4 top-3.5 text-gray-400"></i>
                        </div>
                    </div>

                    <!-- Change Password -->
                    <div class="border-t border-gray-100 pt-6 mb-6">
                        <h3 class="font-bold text-gray-700 mb-1">Change Password</h3>
                        <p class="text-xs text-gray-400 mb-4">Leave blank to keep your current password</p>

                        <div class="mb-4">
                            <label class="block text-sm font-bold text-gray-700 mb-2">New Password</label>
                            <div class="relative">
                                <input type="password" name="new_password" id="new_password"
                                       placeholder="Min 6 characters"
                                       oninput="checkStrength()"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition">
                                <i class="fas fa-eye absolute right-4 top-3.5 text-gray-400 cursor-pointer"
                                   onclick="togglePass('new_password', this)"></i>
                            </div>
                            <div class="flex gap-1 mt-2 h-1">
                                <div id="s1" class="flex-1 bg-gray-200 rounded"></div>
                                <div id="s2" class="flex-1 bg-gray-200 rounded"></div>
                                <div id="s3" class="flex-1 bg-gray-200 rounded"></div>
                            </div>
                            <p id="strengthText" class="text-xs text-gray-400 mt-1">Password strength</p>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">Confirm New Password</label>
                            <div class="relative">
                                <input type="password" name="confirm_password" id="confirm_password"
                                       placeholder="Repeat new password"
                                       oninput="checkMatch()"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition">
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
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                        <a href="/Badminton_court_Booking/owner/profile/index.php"
                           class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<script>
    function togglePass(id, icon) {
        const input = document.getElementById(id);
        input.type = input.type === 'password' ? 'text' : 'password';
        icon.classList.toggle('fa-eye');
        icon.classList.toggle('fa-eye-slash');
    }

    function checkStrength() {
        const p = document.getElementById('new_password').value;
        const bars = ['s1','s2','s3'].map(id => document.getElementById(id));
        const txt  = document.getElementById('strengthText');
        bars.forEach(b => b.className = 'flex-1 bg-gray-200 rounded');
        if (!p) { txt.textContent = 'Password strength'; return; }
        let strength = 0;
        if (p.length >= 6) strength++;
        if (p.match(/[a-z]/) && p.match(/[A-Z]/)) strength++;
        if (p.match(/[0-9]/) && p.match(/[^a-zA-Z0-9]/)) strength++;
        const cfg = [
            null,
            { cls:'strength-weak',   label:'Weak',   color:'text-red-500' },
            { cls:'strength-medium', label:'Medium', color:'text-yellow-500' },
            { cls:'strength-strong', label:'Strong', color:'text-green-500' },
        ];
        if (strength > 0) {
            for (let i = 0; i < strength; i++) bars[i].className = `flex-1 ${cfg[strength].cls} rounded`;
            txt.textContent = cfg[strength].label;
            txt.className = `text-xs mt-1 ${cfg[strength].color}`;
        }
    }

    function checkMatch() {
        const p1  = document.getElementById('new_password').value;
        const p2  = document.getElementById('confirm_password').value;
        const txt = document.getElementById('matchText');
        if (!p2) { txt.classList.add('hidden'); return; }
        txt.classList.remove('hidden');
        txt.textContent = p1 === p2 ? '✓ Passwords match' : '✗ Passwords do not match';
        txt.className   = `text-xs mt-1 ${p1 === p2 ? 'text-green-600' : 'text-red-500'}`;
    }
</script>
</body>
</html>