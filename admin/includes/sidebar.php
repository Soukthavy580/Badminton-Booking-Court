<?php
$current = $_SERVER['REQUEST_URI'];
require_once __DIR__ . '/auto_expire.php';

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

function admin_sidebar_class($path) {
    global $current;
    return str_contains($current, $path)
        ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-blue-50 text-blue-700 font-semibold border-l-4 border-blue-600'
        : 'flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 hover:bg-blue-50 hover:text-blue-700 font-medium transition';
}
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    window.BBCAlert = window.BBCAlert || {};
    window.BBCAlert.modal = function (icon, title, text) {
        if (typeof Swal === 'undefined') return;
        Swal.fire({
            icon,
            title,
            text,
            draggable: true,
            confirmButtonText: "ຕົກລົງ"
        });
    };
</script>
<?php if (empty($swal_flash_handled) && (!empty($error) || !empty($success))): ?>
    <script>
        (function () {
            const errorMsg = <?= json_encode($error ?? '', JSON_UNESCAPED_UNICODE) ?>;
            const successMsg = <?= json_encode($success ?? '', JSON_UNESCAPED_UNICODE) ?>;
            if (errorMsg) return window.BBCAlert.modal('error', 'ລົ້ມເຫລວ', errorMsg);
            if (successMsg) return window.BBCAlert.modal('success', 'ສຳເລັດ', successMsg);
        })();
    </script>
<?php endif; ?>
<aside class="w-64 bg-white shadow-md flex-shrink-0 hidden md:flex flex-col sticky top-0 h-screen">

    <!-- Logo -->
    <div class="p-6 border-b border-gray-100">
        <a href="/Badminton_court_Booking/admin/" class="flex items-center gap-2">
            <img src="/Badminton_court_Booking/assets/images/logo/Logo.png"
                 alt="Badminton Booking Court"
                 class="h-14 w-auto object-contain"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
            <span style="display:none" class="items-center gap-2">
                <i class="fas fa-table-tennis text-blue-600 text-2xl"></i>
            </span>
            <span class="text-sm font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent leading-tight">
                Badminton<br>Booking Court
            </span>
        </a>
        <p class="text-xs text-gray-400 mt-1">ແອດມິນ - ເຈົ້າຂອງເວັບໄຊ້</p>
    </div>

    <nav class="flex-1 p-4 space-y-1 overflow-y-auto">

        <!-- ── ໜ້າຫຼັກ ── -->
        <a href="/Badminton_court_Booking/admin/" class="<?= admin_sidebar_class('/admin/index') ?>">
            <i class="fas fa-home w-5"></i>ໜ້າຫຼັກ
            <?php if ($total_pending > 0): ?>
                <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2 py-0.5"><?= $total_pending ?></span>
            <?php endif; ?>
        </a>

        <!-- ── ຈັດການຜູ້ໃຊ້ ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">ຈັດການຜູ້ໃຊ້</p>
        </div>
        <a href="/Badminton_court_Booking/admin/customers/" class="<?= admin_sidebar_class('/admin/customers/') ?>">
            <i class="fas fa-users w-5"></i>ຈັດການລູກຄ້າ
        </a>
        <a href="/Badminton_court_Booking/admin/owners/" class="<?= admin_sidebar_class('/admin/owners/') ?>">
            <i class="fas fa-user-tie w-5"></i>ຈັດການເຈົ້າຂອງ
        </a>
        <a href="/Badminton_court_Booking/admin/admins/" class="<?= admin_sidebar_class('/admin/admins/') ?>">
            <i class="fas fa-user-shield w-5"></i>ຈັດການແອດມິນ
        </a>

        <!-- ── ຈັດການສະຖານທີ່ ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">ຈັດການສະຖານທີ່</p>
        </div>
        <a href="/Badminton_court_Booking/admin/venues/" class="<?= admin_sidebar_class('/admin/venues/') ?>">
            <i class="fas fa-store w-5"></i>ເດີ່ນ ແລະ ຄອດ
            <?php if ($pending_venues > 0): ?>
                <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2 py-0.5"><?= $pending_venues ?></span>
            <?php endif; ?>
        </a>

        <!-- ── ການອະນຸມັດ ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">ການອະນຸມັດ</p>
        </div>
        <a href="/Badminton_court_Booking/admin/packages/" class="<?= admin_sidebar_class('/admin/packages/') ?>">
            <i class="fas fa-box w-5"></i>ຈັດການແພັກເກດ
            <?php if ($pending_packages > 0): ?>
                <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2 py-0.5"><?= $pending_packages ?></span>
            <?php endif; ?>
        </a>
        <a href="/Badminton_court_Booking/admin/advertisements/" class="<?= admin_sidebar_class('/
        3
        /advertisements/') ?>">
            <i class="fas fa-bullhorn w-5"></i>ຈັດການໂຄສະນາ
            <?php if ($pending_ads > 0): ?>
                <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2 py-0.5"><?= $pending_ads ?></span>
            <?php endif; ?>
        </a>

        <!-- ── ລາຍງານ ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">ລາຍງານ</p>
        </div>
        <a href="/Badminton_court_Booking/admin/reports/" class="<?= admin_sidebar_class('/admin/reports/') ?>">
            <i class="fas fa-chart-bar w-5"></i>ລາຍງານ
        </a>

        <!-- ── ບັນຊີ ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">ບັນຊີ</p>
        </div>
        <a href="/Badminton_court_Booking/admin/profile/" class="<?= admin_sidebar_class('/admin/profile/') ?>">
            <i class="fas fa-user w-5"></i>ໂປຣໄຟລ໌
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
                <p class="text-xs text-gray-400">ຜູ້ດູແລລະບົບ</p>
            </div>
        </div>
        <a href="/Badminton_court_Booking/auth/logout.php"
           class="w-full flex items-center gap-2 text-red-600 hover:bg-red-50 px-3 py-2 rounded-lg text-sm transition">
            <i class="fas fa-sign-out-alt"></i>ອອກຈາກລະບົບ
        </a>
    </div>

</aside>