<?php
if (!isset($_SESSION)) {
    session_start();
}

$customer_name  = $_SESSION['user_name']  ?? 'Guest';
$customer_email = $_SESSION['user_email'] ?? '';
$c_id           = $_SESSION['c_id']       ?? 0;
$is_logged_in   = isset($_SESSION['user_id']);

$notification_count = 0;
if ($c_id && isset($pdo)) {
    try {
        // FIX: Exclude 'Unpaid' — those are not real alerts
        $stmt = $pdo->prepare("
            SELECT CONCAT(b.Book_ID, '_', b.Status_booking)
            FROM booking b
            WHERE b.C_ID = ?
            AND b.Status_booking != 'Unpaid'
            GROUP BY b.Book_ID, b.Status_booking
        ");
        $stmt->execute([$c_id]);
        $current_keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $seen_keys = $_SESSION['notif_seen'] ?? [];
        // Only count keys not yet seen
        $notification_count = count(array_diff($current_keys, $seen_keys));
    } catch (PDOException $e) {
        $notification_count = 0;
    }
}

$current      = $_SERVER['REQUEST_URI'];
$current_path = rtrim(parse_url($current, PHP_URL_PATH), '/');

function nav_class($path) {
    global $current_path;
    $check = rtrim($path, '/');
    // Home: exact match only
    // Others: starts-with match so sub-pages also highlight
    $active = ($check === '/Badminton_court_Booking/customer')
        ? ($current_path === $check || $current_path === $check . '/index.php')
        : str_starts_with($current_path, $check);
    return $active
        ? 'text-green-600 font-semibold flex items-center gap-1 border-b-2 border-green-600 pb-1'
        : 'text-gray-700 hover:text-green-600 font-medium transition flex items-center gap-1';
}
function mobile_class($path) {
    global $current_path;
    $check  = rtrim($path, '/');
    $active = ($check === '/Badminton_court_Booking/customer')
        ? ($current_path === $check || $current_path === $check . '/index.php')
        : str_starts_with($current_path, $check);
    return $active
        ? 'block py-2 px-4 text-green-600 font-semibold bg-green-50 rounded'
        : 'block py-2 px-4 text-gray-700 hover:text-green-600 hover:bg-green-50 rounded transition';
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<nav class="bg-white shadow-md sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">

            <!-- Logo -->
            <div class="flex items-center">
                <a href="/Badminton_court_Booking/customer/" class="flex items-center gap-2">
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

            <!-- Desktop Nav -->
            <div class="hidden md:flex items-center space-x-6">
                <a href="/Badminton_court_Booking/customer/"
                   class="<?= nav_class('/Badminton_court_Booking/customer/') ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="/Badminton_court_Booking/customer/booking_court/"
                   class="<?= nav_class('/Badminton_court_Booking/customer/booking_court/') ?>">
                    <i class="fas fa-table-tennis"></i> Book Court
                </a>
            </div>

            <!-- Right Side -->
            <div class="flex items-center space-x-4">
                <?php if ($is_logged_in): ?>
                    <!-- Notifications Bell -->
                    <a href="/Badminton_court_Booking/customer/notification/"
                       class="relative <?= str_starts_with($current_path, '/Badminton_court_Booking/customer/notification') ? 'text-green-600' : 'text-gray-600 hover:text-green-600' ?> transition">
                        <i class="fas fa-bell text-xl"></i>
                        <?php if ($notification_count > 0): ?>
                            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center font-bold">
                                <?= $notification_count > 9 ? '9+' : $notification_count ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <!-- User Dropdown -->
                    <div class="relative">
                        <button onclick="toggleUserMenu()"
                                class="flex items-center space-x-2 text-gray-700 hover:text-green-600 transition focus:outline-none">
                            <div class="w-10 h-10 bg-gradient-to-br from-green-400 to-blue-500 rounded-full flex items-center justify-center text-white font-bold">
                                <?= strtoupper(substr($customer_name, 0, 1)) ?>
                            </div>
                            <span class="hidden md:block font-medium"><?= htmlspecialchars($customer_name) ?></span>
                            <i class="fas fa-chevron-down text-sm"></i>
                        </button>

                        <div id="userDropdown" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-100 py-2 z-50">
                            <div class="px-4 py-3 border-b border-gray-100">
                                <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($customer_name) ?></p>
                                <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($customer_email) ?></p>
                            </div>
                            <a href="/Badminton_court_Booking/customer/profile/"
                               class="block px-4 py-2 text-sm <?= str_starts_with($current_path, '/Badminton_court_Booking/customer/profile') ? 'text-green-600 bg-green-50 font-semibold' : 'text-gray-700 hover:bg-green-50 hover:text-green-600' ?> transition">
                                <i class="fas fa-user mr-2"></i> My Profile
                            </a>
                            <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php"
                               class="block px-4 py-2 text-sm <?= str_contains($current_path, 'my_booking') ? 'text-green-600 bg-green-50 font-semibold' : 'text-gray-700 hover:bg-green-50 hover:text-green-600' ?> transition">
                                <i class="fas fa-calendar-check mr-2"></i> My Bookings
                            </a>
                            <a href="/Badminton_court_Booking/customer/notification/"
                               class="block px-4 py-2 text-sm <?= str_starts_with($current_path, '/Badminton_court_Booking/customer/notification') ? 'text-green-600 bg-green-50 font-semibold' : 'text-gray-700 hover:bg-green-50 hover:text-green-600' ?> transition">
                                <i class="fas fa-bell mr-2"></i> Notifications
                                <?php if ($notification_count > 0): ?>
                                    <span class="ml-1 bg-red-500 text-white text-xs rounded-full px-1.5 py-0.5 font-bold">
                                        <?= $notification_count ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <a href="/Badminton_court_Booking/auth/logout.php"
                               class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition">
                                <i class="fas fa-sign-out-alt mr-2"></i> Logout
                            </a>
                        </div>
                    </div>

                <?php else: ?>
                    <a href="/Badminton_court_Booking/auth/login.php"
                       class="text-gray-700 hover:text-green-600 font-medium transition">
                        <i class="fas fa-sign-in-alt mr-1"></i> Login
                    </a>
                    <a href="/Badminton_court_Booking/auth/register.php"
                       class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition">
                        Sign Up
                    </a>
                <?php endif; ?>

                <!-- Mobile Toggle -->
                <button onclick="toggleMobileMenu()" class="md:hidden text-gray-600 hover:text-green-600">
                    <i class="fas fa-bars text-xl"></i>
                </button>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div id="mobileMenu" class="hidden md:hidden pb-4 border-t border-gray-100 mt-2">
            <a href="/Badminton_court_Booking/customer/" class="<?= mobile_class('/Badminton_court_Booking/customer/') ?>">
                <i class="fas fa-home mr-2"></i> Home
            </a>
            <a href="/Badminton_court_Booking/customer/booking_court/" class="<?= mobile_class('/Badminton_court_Booking/customer/booking_court/') ?>">
                <i class="fas fa-table-tennis mr-2"></i> Book Court
            </a>
            <?php if ($is_logged_in): ?>
                <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php" class="<?= mobile_class('/Badminton_court_Booking/customer/booking_court/my_booking') ?>">
                    <i class="fas fa-calendar-alt mr-2"></i> My Bookings
                </a>
                <a href="/Badminton_court_Booking/customer/notification/" class="<?= mobile_class('/Badminton_court_Booking/customer/notification/') ?>">
                    <i class="fas fa-bell mr-2"></i> Notifications
                    <?php if ($notification_count > 0): ?>
                        <span class="ml-1 bg-red-500 text-white text-xs rounded-full px-1.5 py-0.5 font-bold"><?= $notification_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="/Badminton_court_Booking/customer/profile/" class="<?= mobile_class('/Badminton_court_Booking/customer/profile/') ?>">
                    <i class="fas fa-user mr-2"></i> My Profile
                </a>
                <a href="/Badminton_court_Booking/auth/logout.php"
                   class="block py-2 px-4 text-red-600 hover:bg-red-50 rounded transition">
                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                </a>
            <?php else: ?>
                <a href="/Badminton_court_Booking/auth/login.php"
                   class="block py-2 px-4 text-gray-700 hover:text-green-600 hover:bg-green-50 rounded transition">
                    <i class="fas fa-sign-in-alt mr-2"></i> Login
                </a>
                <a href="/Badminton_court_Booking/auth/register.php"
                   class="block py-2 px-4 text-green-600 font-semibold hover:bg-green-50 rounded transition">
                    <i class="fas fa-user-plus mr-2"></i> Sign Up
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<script>
function toggleUserMenu() {
    document.getElementById('userDropdown').classList.toggle('hidden');
}
function toggleMobileMenu() {
    document.getElementById('mobileMenu').classList.toggle('hidden');
}
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const button = event.target.closest('button[onclick="toggleUserMenu()"]');
    if (!button && !dropdown?.contains(event.target)) {
        dropdown?.classList.add('hidden');
    }
});
</script>