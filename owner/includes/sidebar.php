<?php
$current = $_SERVER['REQUEST_URI'];

// Check active package
$is_active = false;
if (isset($pdo) && isset($ca_id)) {
    try {
        $s = $pdo->prepare("
            SELECT COUNT(*) FROM package
            WHERE CA_ID = ? AND Status_Package = 'Active' AND End_time > NOW()
        ");
        $s->execute([$ca_id]);
        $is_active = $s->fetchColumn() > 0;
    } catch (PDOException $e) { $is_active = false; }
}

// Pending bookings count
$pending_count = 0;
if (isset($pdo) && isset($ca_id) && $is_active) {
    try {
        $s = $pdo->prepare("
            SELECT COUNT(*) FROM booking b
            INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
            INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
            INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
            WHERE v.CA_ID = ? AND b.Status_booking = 'Pending' AND b.Slip_payment != ''
        ");
        $s->execute([$ca_id]);
        $pending_count = (int)$s->fetchColumn();
    } catch (PDOException $e) { $pending_count = 0; }
}

// Package expiring within 3 days
$pkg_expiring = false;
if (isset($pdo) && isset($ca_id) && $is_active) {
    try {
        $s = $pdo->prepare("
            SELECT COUNT(*) FROM package
            WHERE CA_ID = ? AND Status_Package = 'Active'
            AND End_time > NOW() AND End_time <= DATE_ADD(NOW(), INTERVAL 3 DAY)
        ");
        $s->execute([$ca_id]);
        $pkg_expiring = $s->fetchColumn() > 0;
    } catch (PDOException $e) {}
}

// Ad expiring within 3 days
$ad_expiring = false;
if (isset($pdo) && isset($ca_id) && $is_active) {
    try {
        $s = $pdo->prepare("
            SELECT COUNT(*) FROM advertisement ad
            INNER JOIN Venue_data v ON ad.VN_ID = v.VN_ID
            WHERE v.CA_ID = ? AND ad.Status_AD IN ('Approved','Active')
            AND ad.End_time > NOW() AND ad.End_time <= DATE_ADD(NOW(), INTERVAL 3 DAY)
        ");
        $s->execute([$ca_id]);
        $ad_expiring = $s->fetchColumn() > 0;
    } catch (PDOException $e) {}
}

// Unread owner notifications count
$notif_count = 0;
if (isset($pdo) && isset($ca_id)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM owner_notification WHERE CA_ID = ?");
        $s->execute([$ca_id]);
        $notif_count = (int)$s->fetchColumn();
    } catch (PDOException $e) { $notif_count = 0; }
}

function sidebar_class($path) {
    global $current;
    return str_contains($current, $path)
        ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-semibold border-l-4 border-green-600'
        : 'flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 hover:bg-green-50 hover:text-green-700 font-medium transition';
}
?>
<aside class="w-64 bg-white shadow-md flex-shrink-0 hidden md:flex flex-col sticky top-0 h-screen">

    <!-- Logo -->
    <div class="p-6 border-b border-gray-100">
        <a href="/Badminton_court_Booking/owner/index.php" class="flex items-center gap-2">
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
        <p class="text-xs text-gray-400 mt-1">Owner Panel</p>
    </div>

    <nav class="flex-1 p-4 space-y-1 overflow-y-auto">

        <!-- Dashboard -->
        <a href="/Badminton_court_Booking/owner/index.php"
           class="<?= sidebar_class('/owner/index.php') ?>">
            <i class="fas fa-home w-5"></i> Dashboard
        </a>

        <!-- ── PACKAGES (always visible) ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">Subscription</p>
        </div>
        <a href="/Badminton_court_Booking/owner/package_rental/index.php"
           class="<?= sidebar_class('/package_rental/') ?>">
            <i class="fas fa-box w-5"></i> Packages
            <?php if (!$is_active): ?>
                <span class="ml-auto bg-yellow-400 text-yellow-900 text-xs font-bold rounded-full px-2 py-0.5">!</span>
            <?php elseif ($pkg_expiring): ?>
                <span class="ml-auto bg-orange-500 text-white text-xs font-bold rounded-full px-2 py-0.5">⚠</span>
            <?php endif; ?>
        </a>

        <!-- ── VENUE (locked if no active package) ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">Venue</p>
        </div>

        <?php if ($is_active): ?>
            <a href="/Badminton_court_Booking/owner/manage_court/index.php"
               class="<?= sidebar_class('/manage_court/') ?>">
                <i class="fas fa-store w-5"></i> My Venue
            </a>
            <a href="/Badminton_court_Booking/owner/booking_management/index.php"
               class="<?= sidebar_class('/booking_management/') ?>">
                <i class="fas fa-calendar-check w-5"></i> Bookings
                <?php if ($pending_count > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2 py-0.5">
                        <?= $pending_count ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="/Badminton_court_Booking/owner/facilities/index.php"
               class="<?= sidebar_class('/facilities/') ?>">
                <i class="fas fa-concierge-bell w-5"></i> Facilities
            </a>
            <a href="/Badminton_court_Booking/owner/advertisement/index.php"
               class="<?= sidebar_class('/advertisement/') ?>">
                <i class="fas fa-bullhorn w-5"></i> Advertisement
                <?php if ($ad_expiring): ?>
                    <span class="ml-auto bg-orange-500 text-white text-xs rounded-full px-2 py-0.5">⚠</span>
                <?php endif; ?>
            </a>
            <a href="/Badminton_court_Booking/owner/reports/index.php"
               class="<?= sidebar_class('/reports/') ?>">
                <i class="fas fa-chart-bar w-5"></i> Reports
            </a>
        <?php else: ?>
            <?php foreach ([
                ['icon' => 'fa-store',          'label' => 'My Venue'],
                ['icon' => 'fa-calendar-check', 'label' => 'Bookings'],
                ['icon' => 'fa-concierge-bell', 'label' => 'Facilities'],
                ['icon' => 'fa-bullhorn',       'label' => 'Advertisement'],
                ['icon' => 'fa-chart-bar',      'label' => 'Reports'],
            ] as $item): ?>
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-300 cursor-not-allowed select-none">
                    <i class="fas fa-lock w-5 text-xs"></i> <?= $item['label'] ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- ── NOTIFICATIONS (always visible) ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">Notifications</p>
        </div>
        <a href="/Badminton_court_Booking/owner/notification/index.php"
           class="<?= sidebar_class('/notification/') ?>">
            <i class="fas fa-bell w-5"></i> Notifications
            <?php if ($notif_count > 0): ?>
                <span class="ml-auto bg-red-500 text-white text-xs font-bold rounded-full px-2 py-0.5">
                    <?= $notif_count ?>
                </span>
            <?php endif; ?>
        </a>

        <!-- ── ACCOUNT ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">Account</p>
        </div>
        <a href="/Badminton_court_Booking/owner/profile/index.php"
           class="<?= sidebar_class('/owner/profile/') ?>">
            <i class="fas fa-user w-5"></i> Profile
        </a>

    </nav>

    <!-- Owner Info -->
    <div class="p-4 border-t border-gray-100">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 bg-gradient-to-br from-green-400 to-blue-500 rounded-full flex items-center justify-center text-white font-bold flex-shrink-0">
                <?= strtoupper(substr($_SESSION['user_name'] ?? 'O', 0, 1)) ?>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></p>
                <p class="text-xs text-gray-400">Court Owner</p>
            </div>
        </div>
        <a href="/Badminton_court_Booking/auth/logout.php"
           class="w-full flex items-center gap-2 text-red-600 hover:bg-red-50 px-3 py-2 rounded-lg text-sm transition">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

</aside>