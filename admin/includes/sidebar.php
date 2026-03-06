<?php
$current = $_SERVER['REQUEST_URI'];
require_once __DIR__ . '/auto_expire.php';

// Count pending items for badges
$pending_venues   = 0;
$pending_packages = 0;
$pending_ads      = 0;

if (isset($pdo)) {
    try {
        $pending_venues   = $pdo->query("SELECT COUNT(*) FROM Venue_data WHERE VN_Status = 'Pending'")->fetchColumn();
        $pending_packages = $pdo->query("SELECT COUNT(*) FROM package WHERE Status_Package = 'Pending'")->fetchColumn();
        $pending_ads      = $pdo->query("SELECT COUNT(*) FROM advertisement WHERE Status_AD = 'Pending'")->fetchColumn();
    } catch (PDOException $e) {}
}

$total_pending = $pending_venues + $pending_packages + $pending_ads;

// Check if any approvals sub-page is active
$approvals_active = str_contains($current, '/admin/venues/')
                 || str_contains($current, '/admin/packages/')
                 || str_contains($current, '/admin/advertisements/');

function admin_sidebar_class($path) {
    global $current;
    $active = str_contains($current, $path);
    return $active
        ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-blue-50 text-blue-700 font-semibold border-l-4 border-blue-600'
        : 'flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 hover:bg-blue-50 hover:text-blue-700 font-medium transition';
}
?>
<aside class="w-64 bg-white shadow-md flex-shrink-0 hidden md:flex flex-col sticky top-0 h-screen">
    <!-- Logo -->
    <div class="p-6 border-b border-gray-100">
        <a href="/Badminton_court_Booking/admin/index.php" class="flex items-center gap-2">
            <i class="fas fa-table-tennis text-blue-600 text-2xl"></i>
            <span class="text-xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
                CourtBook
            </span>
        </a>
        <p class="text-xs text-gray-400 mt-1">Admin Panel</p>
    </div>

    <nav class="flex-1 p-4 space-y-1 overflow-y-auto">
        <!-- Dashboard -->
        <a href="/Badminton_court_Booking/admin/index.php"
           class="<?= admin_sidebar_class('/admin/index.php') ?>">
            <i class="fas fa-home w-5"></i> Dashboard
            <?php if ($total_pending > 0): ?>
                <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2 py-0.5">
                    <?= $total_pending ?>
                </span>
            <?php endif; ?>
        </a>

        <!-- Approvals Dropdown -->
        <div>
            <button onclick="toggleApprovals()"
                    class="w-full flex items-center gap-3 px-4 py-3 rounded-xl font-medium transition
                           <?= $approvals_active ? 'bg-blue-50 text-blue-700 font-semibold border-l-4 border-blue-600' : 'text-gray-600 hover:bg-blue-50 hover:text-blue-700' ?>">
                <i class="fas fa-clipboard-check w-5"></i>
                <span>Approvals</span>
                <?php if ($total_pending > 0): ?>
                    <span class="bg-red-500 text-white text-xs rounded-full px-2 py-0.5">
                        <?= $total_pending ?>
                    </span>
                <?php endif; ?>
                <i class="fas fa-chevron-down ml-auto text-xs transition-transform duration-200" id="approvalsChevron"></i>
            </button>

            <!-- Sub-items -->
            <div id="approvalsMenu"
                 class="ml-4 mt-1 space-y-1 overflow-hidden transition-all duration-300"
                 style="<?= $approvals_active ? '' : 'display:none' ?>">

                <a href="/Badminton_court_Booking/admin/venues/index.php"
                   class="<?= str_contains($current, '/admin/venues/')
                       ? 'flex items-center gap-3 px-4 py-2.5 rounded-xl bg-blue-50 text-blue-700 font-semibold text-sm border-l-4 border-blue-400'
                       : 'flex items-center gap-3 px-4 py-2.5 rounded-xl text-gray-500 hover:bg-blue-50 hover:text-blue-700 text-sm transition' ?>">
                    <i class="fas fa-store w-4 text-sm"></i> Venues
                    <?php if ($pending_venues > 0): ?>
                        <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2 py-0.5">
                            <?= $pending_venues ?>
                        </span>
                    <?php endif; ?>
                </a>

                <a href="/Badminton_court_Booking/admin/packages/index.php"
                   class="<?= str_contains($current, '/admin/packages/')
                       ? 'flex items-center gap-3 px-4 py-2.5 rounded-xl bg-blue-50 text-blue-700 font-semibold text-sm border-l-4 border-blue-400'
                       : 'flex items-center gap-3 px-4 py-2.5 rounded-xl text-gray-500 hover:bg-blue-50 hover:text-blue-700 text-sm transition' ?>">
                    <i class="fas fa-box w-4 text-sm"></i> Packages
                    <?php if ($pending_packages > 0): ?>
                        <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2 py-0.5">
                            <?= $pending_packages ?>
                        </span>
                    <?php endif; ?>
                </a>

                <a href="/Badminton_court_Booking/admin/advertisements/index.php"
                   class="<?= str_contains($current, '/admin/advertisements/')
                       ? 'flex items-center gap-3 px-4 py-2.5 rounded-xl bg-blue-50 text-blue-700 font-semibold text-sm border-l-4 border-blue-400'
                       : 'flex items-center gap-3 px-4 py-2.5 rounded-xl text-gray-500 hover:bg-blue-50 hover:text-blue-700 text-sm transition' ?>">
                    <i class="fas fa-bullhorn w-4 text-sm"></i> Advertisements
                    <?php if ($pending_ads > 0): ?>
                        <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2 py-0.5">
                            <?= $pending_ads ?>
                        </span>
                    <?php endif; ?>
                </a>

            </div>
        </div>

        <div class="pt-2 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">Users</p>
        </div>

        <a href="/Badminton_court_Booking/admin/owners/index.php"
           class="<?= admin_sidebar_class('/admin/owners/') ?>">
            <i class="fas fa-user-tie w-5"></i> Owners
        </a>

        <a href="/Badminton_court_Booking/admin/customers/index.php"
           class="<?= admin_sidebar_class('/admin/customers/') ?>">
            <i class="fas fa-users w-5"></i> Customers
        </a>

        <div class="pt-2 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">Analytics</p>
        </div>

        <a href="/Badminton_court_Booking/admin/reports/index.php"
           class="<?= admin_sidebar_class('/admin/reports/') ?>">
            <i class="fas fa-chart-bar w-5"></i> Reports
        </a>
    </nav>

    <!-- Admin Info -->
    <div class="p-4 border-t border-gray-100">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold flex-shrink-0">
                <?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></p>
                <p class="text-xs text-gray-400">Administrator</p>
            </div>
        </div>
        <a href="/Badminton_court_Booking/auth/logout.php"
           class="w-full flex items-center gap-2 text-red-600 hover:bg-red-50 px-3 py-2 rounded-lg text-sm transition">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</aside>

<script>
    // Keep open if on an approvals page
    const approvalsOpen = <?= $approvals_active ? 'true' : 'false' ?>;
    const menu    = document.getElementById('approvalsMenu');
    const chevron = document.getElementById('approvalsChevron');

    if (approvalsOpen) {
        chevron.style.transform = 'rotate(180deg)';
    }

    function toggleApprovals() {
        const isHidden = menu.style.display === 'none' || menu.style.display === '';
        menu.style.display   = isHidden ? 'block' : 'none';
        chevron.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
    }
</script>