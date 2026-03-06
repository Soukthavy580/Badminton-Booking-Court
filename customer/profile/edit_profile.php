<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$c_id = $_SESSION['c_id'];
$error = '';

// Fetch current data
try {
    $stmt = $pdo->prepare("SELECT * FROM customer WHERE C_ID = ? LIMIT 1");
    $stmt->execute([$c_id]);
    $customer = $stmt->fetch();
} catch (PDOException $e) {
    $customer = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $surname  = trim($_POST['surname']  ?? '');
    $gender   = $_POST['gender']        ?? '';
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
            // Check email not taken by another customer
            $stmt = $pdo->prepare("SELECT C_ID FROM customer WHERE Email = ? AND C_ID != ?");
            $stmt->execute([$email, $c_id]);
            if ($stmt->fetch()) {
                $error = 'This email is already used by another account.';
            } else {
                // Check username not taken
                $stmt = $pdo->prepare("SELECT C_ID FROM customer WHERE Username = ? AND C_ID != ?");
                $stmt->execute([$username, $c_id]);
                if ($stmt->fetch()) {
                    $error = 'This username is already taken.';
                } else {
                    // Update profile
                    $stmt = $pdo->prepare("
                        UPDATE customer
                        SET Name = ?, Surname = ?, Gender = ?, Phone = ?, Email = ?, Username = ?
                        WHERE C_ID = ?
                    ");
                    $stmt->execute([$name, $surname, $gender, $phone, $email, $username, $c_id]);

                    // Update password if provided
                    if (!empty($new_pass)) {
                        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE customer SET Password = ? WHERE C_ID = ?");
                        $stmt->execute([$hashed, $c_id]);
                    }

                    // Update session
                    $_SESSION['user_name']  = $name;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['profile_success'] = 'Profile updated successfully!';

                    header('Location: /Badminton_court_Booking/customer/profile/index.php');
                    exit;
                }
            }
        } catch (PDOException $e) {
            error_log("Profile update error: " . $e->getMessage());
            $error = 'Update failed. Please try again.';
        }
    }

    // Keep form filled on error
    $customer['Name']     = $name;
    $customer['Surname']  = $surname;
    $customer['Gender']   = $gender;
    $customer['Phone']    = $phone;
    $customer['Email']    = $email;
    $customer['Username'] = $username;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - CourtBook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .strength-weak   { background-color: #ef4444; }
        .strength-medium { background-color: #f59e0b; }
        .strength-strong { background-color: #10b981; }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>

    <div class="max-w-2xl mx-auto px-4 py-8">

        <!-- Back -->
        <a href="/Badminton_court_Booking/customer/profile/index.php"
           class="inline-flex items-center gap-2 text-gray-600 hover:text-blue-600 mb-6 font-medium transition">
            <i class="fas fa-arrow-left"></i> Back to Profile
        </a>

        <div class="bg-white rounded-2xl shadow-sm p-8">
            <div class="flex items-center gap-4 mb-8">
                <div class="w-16 h-16 bg-gradient-to-br from-green-400 to-blue-500 rounded-full flex items-center justify-center text-white text-2xl font-bold">
                    <?= strtoupper(substr($customer['Name'], 0, 1)) ?>
                </div>
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-800">Edit Profile</h1>
                    <p class="text-gray-500 text-sm">Update your personal information</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl flex items-center gap-3">
                    <i class="fas fa-exclamation-circle text-xl"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="">

                <!-- Name Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2 text-sm">First Name</label>
                        <div class="relative">
                            <input type="text" name="name"
                                   value="<?= htmlspecialchars($customer['Name']) ?>"
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition"
                                   required>
                            <i class="fas fa-user absolute right-4 top-3.5 text-gray-400"></i>
                        </div>
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2 text-sm">Surname</label>
                        <div class="relative">
                            <input type="text" name="surname"
                                   value="<?= htmlspecialchars($customer['Surname']) ?>"
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition"
                                   required>
                            <i class="fas fa-user absolute right-4 top-3.5 text-gray-400"></i>
                        </div>
                    </div>
                </div>

                <!-- Gender -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2 text-sm">Gender</label>
                    <select name="gender"
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition">
                        <option value="Male"   <?= $customer['Gender'] === 'Male'   ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= $customer['Gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                    </select>
                </div>

                <!-- Phone -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2 text-sm">Phone Number</label>
                    <div class="relative">
                        <input type="tel" name="phone"
                               value="<?= htmlspecialchars($customer['Phone']) ?>"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition"
                               required>
                        <i class="fas fa-phone absolute right-4 top-3.5 text-gray-400"></i>
                    </div>
                </div>

                <!-- Email -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2 text-sm">Email</label>
                    <div class="relative">
                        <input type="email" name="email"
                               value="<?= htmlspecialchars($customer['Email']) ?>"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition"
                               required>
                        <i class="fas fa-envelope absolute right-4 top-3.5 text-gray-400"></i>
                    </div>
                </div>

                <!-- Username -->
                <div class="mb-6">
                    <label class="block text-gray-700 font-medium mb-2 text-sm">Username</label>
                    <div class="relative">
                        <input type="text" name="username"
                               value="<?= htmlspecialchars($customer['Username']) ?>"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition"
                               required>
                        <i class="fas fa-at absolute right-4 top-3.5 text-gray-400"></i>
                    </div>
                </div>

                <!-- Divider -->
                <div class="border-t border-gray-100 mb-6 pt-6">
                    <h3 class="font-bold text-gray-700 mb-1">Change Password</h3>
                    <p class="text-xs text-gray-400 mb-4">Leave blank to keep your current password</p>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 text-sm">New Password</label>
                        <div class="relative">
                            <input type="password" name="new_password" id="new_password"
                                   placeholder="Min 6 characters"
                                   oninput="checkStrength()"
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-green-500 transition">
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
                        <label class="block text-gray-700 font-medium mb-2 text-sm">Confirm New Password</label>
                        <div class="relative">
                            <input type="password" name="confirm_password" id="confirm_password"
                                   placeholder="Repeat new password"
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
                        <i class="fas fa-save mr-2"></i>Save Changes
                    </button>
                    <a href="/Badminton_court_Booking/customer/profile/index.php"
                       class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition">
                        Cancel
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

        function checkStrength() {
            const p = document.getElementById('new_password').value;
            const s1 = document.getElementById('s1');
            const s2 = document.getElementById('s2');
            const s3 = document.getElementById('s3');
            const txt = document.getElementById('strengthText');

            s1.className = 'flex-1 bg-gray-200 rounded';
            s2.className = 'flex-1 bg-gray-200 rounded';
            s3.className = 'flex-1 bg-gray-200 rounded';

            if (!p) { txt.textContent = 'Password strength'; return; }

            let strength = 0;
            if (p.length >= 6) strength++;
            if (p.match(/[a-z]/) && p.match(/[A-Z]/)) strength++;
            if (p.match(/[0-9]/) && p.match(/[^a-zA-Z0-9]/)) strength++;

            if (strength === 1) {
                s1.className = 'flex-1 strength-weak rounded';
                txt.textContent = 'Weak'; txt.className = 'text-xs text-red-500 mt-1';
            } else if (strength === 2) {
                s1.className = 'flex-1 strength-medium rounded';
                s2.className = 'flex-1 strength-medium rounded';
                txt.textContent = 'Medium'; txt.className = 'text-xs text-yellow-500 mt-1';
            } else if (strength === 3) {
                s1.className = 'flex-1 strength-strong rounded';
                s2.className = 'flex-1 strength-strong rounded';
                s3.className = 'flex-1 strength-strong rounded';
                txt.textContent = 'Strong'; txt.className = 'text-xs text-green-500 mt-1';
            }
        }

        function checkMatch() {
            const p1  = document.getElementById('new_password').value;
            const p2  = document.getElementById('confirm_password').value;
            const txt = document.getElementById('matchText');
            if (!p2) { txt.classList.add('hidden'); return; }
            txt.classList.remove('hidden');
            if (p1 === p2) {
                txt.textContent = '✓ Passwords match';
                txt.className = 'text-xs text-green-600 mt-1';
            } else {
                txt.textContent = '✗ Passwords do not match';
                txt.className = 'text-xs text-red-500 mt-1';
            }
        }
    </script>
</body>
</html>