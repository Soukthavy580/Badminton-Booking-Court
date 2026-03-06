<?php
session_start();
require_once '../config/db.php';

// Capture redirect URL from Book Now button
if (isset($_GET['redirect']) && !isset($_SESSION['redirect_after_login'])) {
    $_SESSION['redirect_after_login'] = $_GET['redirect'];
}

// Only redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_type'] === 'admin')         header('Location: /Badminton_court_Booking/admin/index.php');
    elseif ($_SESSION['user_type'] === 'owner')     header('Location: /Badminton_court_Booking/owner/index.php');
    else                                            header('Location: /Badminton_court_Booking/customer/index.php');
    exit;
}

$error   = '';
$success = '';

if (isset($_GET['logout'])     && $_GET['logout']     === 'success') $success = 'You have been successfully logged out.';
if (isset($_GET['registered']) && $_GET['registered'] === 'success') $success = 'Registration successful! Please login.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $role     = $_POST['role']          ?? '';

    if (empty($role)) {
        $error = 'Please select a role';
    } elseif (empty($login) || empty($password)) {
        $error = 'Please enter email/username and password';
    } else {
        try {
            $table = match ($role) {
                'admin'    => 'admin',
                'owner'    => 'court_owner',
                'customer' => 'customer',
                default    => ''
            };
            $user = $table ? get_user_by_login($login, $table) : null;

            if ($user && verify_password($password, $user['password'])) {

                $status = $user['Status'] ?? 'Active';
                if ($role !== 'admin' && $status === 'Banned') {
                    $error = 'Your account has been suspended. Please contact support.';
                } else {
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_name']  = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_type']  = $role;

                    if ($role === 'owner') {
                        $_SESSION['ca_id'] = $user['CA_ID'];
                        $_SESSION['phone'] = $user['Phone'] ?? '';
                    } elseif ($role === 'customer') {
                        $_SESSION['c_id']  = $user['C_ID'];
                        $_SESSION['phone'] = $user['Phone'] ?? '';
                    } elseif ($role === 'admin') {
                        $_SESSION['admin_id'] = $user['Admin_ID'];
                    }

                    setcookie('remember_user', '', time() - 3600, '/');
                    setcookie('remember_role', '', time() - 3600, '/');

                    $redirect = $_SESSION['redirect_after_login'] ?? '';
                    unset($_SESSION['redirect_after_login']);

                    if ($redirect) {
                        header("Location: $redirect");
                    } elseif ($role === 'admin') {
                        header('Location: /Badminton_court_Booking/admin/index.php');
                    } elseif ($role === 'owner') {
                        header('Location: /Badminton_court_Booking/owner/index.php');
                    } else {
                        header('Location: /Badminton_court_Booking/customer/index.php');
                    }
                    exit;
                }
            } else {
                $error = !$user
                    ? 'No account found with this email/username for the selected role'
                    : 'Incorrect password. Please try again.';
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - CourtBook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .hero-image {
            background: linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)),
                url('https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?w=800') center/cover;
            position: fixed;
            right: 0;
            top: 0;
            width: 50%;
            height: 100vh;
        }
        .password-toggle {
            cursor: pointer;
            transition: color 0.3s ease;
        }
        .password-toggle:hover {
            color: #2563eb;
        }
        @media (max-width: 768px) {
            .hero-image { display: none; }
        }
        .logo-text {
            background: linear-gradient(135deg, #10b981 0%, #2563eb 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .role-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #e5e7eb;
            background: white;
        }
        .role-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            border-color: #22c55e;
        }
        .role-card.selected {
            border-color: #16a34a;
            background: #f0fdf4;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.25);
        }
        .role-card .role-icon {
            color: #22c55e;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .role-card .role-name {
            color: #14532d;
            font-weight: 600;
            font-size: 1rem;
        }
        .role-card.error {
            border-color: #ef4444 !important;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50">
    <div class="flex min-h-screen overflow-hidden">

        <!-- Left Side - Login Form -->
        <div class="w-full md:w-1/2 flex items-start justify-center p-8 overflow-y-auto min-h-screen">
            <div class="w-full max-w-md">

                <!-- Back to Home -->
                <div class="mb-6">
                    <a href="../customer/index.php"
                        class="text-gray-500 hover:text-gray-700 text-sm flex items-center gap-2 transition">
                        <i class="fas fa-arrow-left"></i>Back to Home
                    </a>
                </div>

                <!-- Logo -->
            <div class="flex items-center">
                <a href="/Badminton_court_Booking/customer/index.php" class="flex items-center gap-2">
                    <img src="/Badminton_court_Booking/assets/images/logo/Logo.png"
                         alt="Badminton Booking Court"
                         class="h-14 w-auto object-contain"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                    <span style="display:none" class="items-center gap-2">
                        <i class="fas fa-table-tennis text-green-600 text-2xl"></i>
                    </span>
                    <span class="text-sm font-bold bg-gradient-to-r from-green-600 to-blue-600 bg-clip-text text-transparent leading-tight">
                        Badminton<br>Booking Court
                    </span>
                </a>
            </div>

                <div class="mb-4">
                    <h2 class="text-3xl font-bold text-gray-800 mb-2">
                        Welcome to <span class="text-green-600">CourtBook</span>
                    </h2>
                </div>
                <h3 class="text-2xl font-bold text-gray-800 mb-8">Login</h3>

                <!-- Success Message -->
                <?php if ($success): ?>
                    <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg flex items-center gap-2">
                        <i class="fas fa-check-circle flex-shrink-0"></i>
                        <span><?= htmlspecialchars($success) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Error Message -->
                <?php if ($error): ?>
                    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg flex items-center gap-2">
                        <i class="fas fa-exclamation-circle flex-shrink-0"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" action="" id="loginForm">

                    <!-- Role Selection -->
                    <div class="mb-6">
                        <label class="block text-gray-700 font-medium mb-3 flex items-center">
                            <i class="fas fa-user-tag text-green-600 mr-2"></i>
                            <span>Login As</span>
                        </label>

                        <!-- Role Error — OUTSIDE the grid -->
                        <div id="roleError" class="hidden mb-3 p-3 bg-red-50 border border-red-300 text-red-600 rounded-lg text-sm flex items-center gap-2">
                            <i class="fas fa-exclamation-circle"></i>
                            Please select a role before logging in.
                        </div>

                        <div class="grid grid-cols-3 gap-3">
                            <!-- Customer -->
                            <div class="role-card rounded-lg p-4 text-center"
                                id="card_customer"
                                onclick="selectRole('customer', this)">
                                <input type="radio" name="role" value="customer"
                                    id="role_customer" class="hidden">
                                <label for="role_customer" class="cursor-pointer block">
                                    <i class="fas fa-user role-icon"></i>
                                    <p class="role-name">Customer</p>
                                </label>
                            </div>

                            <!-- Owner -->
                            <div class="role-card rounded-lg p-4 text-center"
                                id="card_owner"
                                onclick="selectRole('owner', this)">
                                <input type="radio" name="role" value="owner"
                                    id="role_owner" class="hidden">
                                <label for="role_owner" class="cursor-pointer block">
                                    <i class="fas fa-building role-icon"></i>
                                    <p class="role-name">Owner</p>
                                </label>
                            </div>

                            <!-- Admin -->
                            <div class="role-card rounded-lg p-4 text-center"
                                id="card_admin"
                                onclick="selectRole('admin', this)">
                                <input type="radio" name="role" value="admin"
                                    id="role_admin" class="hidden">
                                <label for="role_admin" class="cursor-pointer block">
                                    <i class="fas fa-user-shield role-icon"></i>
                                    <p class="role-name">Admin</p>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Email/Username -->
                    <div class="mb-6">
                        <label for="email" class="block text-gray-700 font-medium mb-2">Email or Username</label>
                        <div class="relative">
                            <input type="text" id="email" name="email"
                                placeholder="Enter your email or username"
                                class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-green-500 transition-colors"
                                required>
                            <i class="fas fa-user-circle absolute right-4 top-4 text-gray-400"></i>
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="mb-8">
                        <label for="password" class="block text-gray-700 font-medium mb-2">Password</label>
                        <div class="relative">
                            <input type="password" id="password" name="password"
                                placeholder="Enter your password"
                                class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-green-500 transition-colors"
                                required>
                            <i class="fas fa-eye password-toggle absolute right-4 top-4 text-gray-400"
                                onclick="togglePassword()" id="toggleIcon"></i>
                        </div>
                    </div>

                    <!-- Login Button -->
                    <button type="submit"
                        onclick="return validateRole()"
                        class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold py-3 rounded-lg transition-all transform hover:scale-[1.02] shadow-lg">
                        <i class="fas fa-sign-in-alt mr-2"></i>Login
                    </button>

                    <!-- Sign Up -->
                    <p class="text-center text-gray-600 text-sm mt-6">
                        Don't have an account?
                        <a href="register.php" class="text-green-600 hover:text-green-700 font-bold">Sign Up</a>
                    </p>

                </form>
            </div>
        </div>

        <!-- Right spacer -->
        <div class="hidden md:block md:w-1/2 flex-shrink-0"></div>
    </div>

    <!-- Fixed Hero Image -->
    <div class="hero-image hidden md:block">
        <div class="absolute inset-0 flex items-center justify-center">
            <div class="text-center text-white p-8">
                <h2 class="text-4xl font-bold mb-4">Book Your Court Today</h2>
                <p class="text-xl mb-6">Experience the best badminton venues in town</p>
                <div class="flex justify-center gap-4 text-sm">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i><span>Easy Booking</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i><span>Secure Payment</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i><span>Best Venues</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        function selectRole(role, card) {
            document.querySelectorAll('.role-card').forEach(c => {
                c.classList.remove('selected', 'error');
                c.style.borderColor = '';
            });
            card.classList.add('selected');
            document.getElementById('role_' + role).checked = true;
            document.getElementById('roleError').classList.add('hidden');
        }

        function validateRole() {
            const selected = document.querySelector('input[name="role"]:checked');
            if (!selected) {
                document.querySelectorAll('.role-card').forEach(c => {
                    c.classList.add('error');
                });
                document.getElementById('roleError').classList.remove('hidden');
                document.getElementById('roleError').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
            return true;
        }
    </script>
</body>
</html>