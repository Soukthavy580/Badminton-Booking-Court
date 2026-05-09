<?php
$current = $_SERVER['REQUEST_URI'];

$is_active = false;
if (isset($pdo) && isset($ca_id)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM package WHERE CA_ID = ? AND Status_Package = 'Active' AND End_time > NOW()");
        $s->execute([$ca_id]);
        $is_active = $s->fetchColumn() > 0;
    } catch (PDOException $e) { $is_active = false; }
}

$pending_count = 0;
if (isset($pdo) && isset($ca_id) && $is_active) {
    try {
        $s = $pdo->prepare("
            SELECT COUNT(DISTINCT b.Book_ID) FROM booking b
            INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
            INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
            INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
            WHERE v.CA_ID = ?
            AND b.Status_booking = 'Pending'
            AND b.Slip_payment IS NOT NULL
            AND b.Slip_payment != ''
        ");
        $s->execute([$ca_id]);
        $pending_count = (int)$s->fetchColumn();
    } catch (PDOException $e) { $pending_count = 0; }
}

$pkg_expiring = false;
if (isset($pdo) && isset($ca_id) && $is_active) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM package WHERE CA_ID = ? AND Status_Package = 'Active' AND End_time > NOW() AND End_time <= DATE_ADD(NOW(), INTERVAL 3 DAY)");
        $s->execute([$ca_id]);
        if ($s->fetchColumn() > 0) {
            $s2 = $pdo->prepare("SELECT COUNT(*) FROM package WHERE CA_ID = ? AND Status_Package = 'Active' AND Start_time > NOW()");
            $s2->execute([$ca_id]);
            $pkg_expiring = $s2->fetchColumn() == 0;
        }
    } catch (PDOException $e) {}
}

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
        if ($s->fetchColumn() > 0) {
            $s2 = $pdo->prepare("
                SELECT COUNT(*) FROM advertisement ad
                INNER JOIN Venue_data v ON ad.VN_ID = v.VN_ID
                WHERE v.CA_ID = ? AND ad.Status_AD IN ('Approved','Active') AND ad.Start_time > NOW()
            ");
            $s2->execute([$ca_id]);
            $ad_expiring = $s2->fetchColumn() == 0;
        }
    } catch (PDOException $e) {}
}

$notif_count = 0;
if (isset($pdo) && isset($ca_id)) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(DISTINCT bp.Package_ID) FROM package bp
                 INNER JOIN approve_package ap ON ap.Package_ID = bp.Package_ID AND ap.Action = 'Rejected'
                 WHERE bp.CA_ID = ? AND bp.Status_Package = 'Rejected')
                +
                (SELECT COUNT(DISTINCT ad.AD_ID) FROM advertisement ad
                 INNER JOIN approve_advertisement ap ON ap.AD_ID = ad.AD_ID AND ap.Action = 'Rejected'
                 INNER JOIN Venue_data v ON ad.VN_ID = v.VN_ID
                 WHERE v.CA_ID = ? AND ad.Status_AD = 'Rejected')
                +
                (SELECT COUNT(*) FROM Venue_data
                 WHERE CA_ID = ? AND VN_Status = 'Inactive' AND Reject_reason IS NOT NULL)
            AS total
        ");
        $stmt->execute([$ca_id, $ca_id, $ca_id]);
        $notif_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) { $notif_count = 0; }
}

function sidebar_class($path) {
    global $current;
    return str_contains($current, $path)
        ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-semibold border-l-4 border-green-600'
        : 'flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 hover:bg-green-50 hover:text-green-700 font-medium transition';
}

function sidebar_locked($label) {
    echo '<div title="ກະລຸນາຊື້ແພັກເກດກ່ອນ"
               class="relative group flex items-center gap-3 px-4 py-3 rounded-xl text-gray-300 cursor-not-allowed select-none">
            <i class="fas fa-lock w-5 text-xs"></i>
            <span>' . $label . '</span>
            <span class="pointer-events-none absolute left-full ml-2 top-1/2 -translate-y-1/2 z-50
                         whitespace-nowrap rounded bg-gray-800 px-2 py-1 text-xs text-white
                         opacity-0 transition-opacity group-hover:opacity-100">
                ຕ້ອງມີແພັກເກດ
            </span>
          </div>';
}
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    window.BBCAlert = window.BBCAlert || {};
    window.BBCAlert.toast = function (icon, title) {
        if (typeof Swal === 'undefined') return;
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon,
            title,
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true
        });
    };
    window.BBCAlert.confirm = function (opts) {
        if (typeof Swal === 'undefined') return Promise.resolve(window.confirm((opts && opts.text) ? opts.text : 'Confirm?'));
        return Swal.fire({
            icon:              (opts && opts.icon)              ? opts.icon              : 'question',
            title:             (opts && opts.title)             ? opts.title             : 'ຢືນຢັນ',
            text:              (opts && opts.text)              ? opts.text              : '',
            showCancelButton:  true,
            confirmButtonText: (opts && opts.confirmButtonText) ? opts.confirmButtonText : 'ຕົກລົງ',
            cancelButtonText:  (opts && opts.cancelButtonText)  ? opts.cancelButtonText  : 'ຍົກເລີກ',
            draggable: true
        }).then(r => !!r.isConfirmed);
    };
</script>

<?php if (empty($swal_flash_handled) && (!empty($error) || !empty($success))): ?>
<script>
    (function () {
        const errorMsg   = <?= json_encode($error   ?? '', JSON_UNESCAPED_UNICODE) ?>;
        const successMsg = <?= json_encode($success ?? '', JSON_UNESCAPED_UNICODE) ?>;
        if (errorMsg)   return window.BBCAlert.toast('error',   errorMsg);
        if (successMsg) return window.BBCAlert.toast('success', successMsg);
    })();
</script>
<?php endif; ?>

<aside class="w-64 bg-white shadow-md flex-shrink-0 hidden md:flex flex-col sticky top-0 h-screen">

    <!-- Logo -->
    <div class="p-6 border-b border-gray-100">
        <a href="/Badminton_court_Booking/owner/" class="flex items-center gap-2">
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
        <p class="text-xs text-gray-400 mt-1">ເຈົ້າຂອງເດີ່ນ</p>
    </div>

    <nav class="flex-1 p-4 space-y-1 overflow-y-auto">

        <!-- ── ການຈັດການ ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">ການຈັດການ</p>
        </div>

        <?php if ($is_active): ?>
            <a href="/Badminton_court_Booking/owner/" class="<?= sidebar_class('/owner/index') ?>">
                <i class="fas fa-home w-5"></i>ໜ້າຫຼັກ
            </a>
        <?php else: ?>
            <?php sidebar_locked('ໜ້າຫຼັກ'); ?>
        <?php endif; ?>

        <!-- ── ແພັກເກດ (always accessible) ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">ການສະໝັກການໃຊ້ງານ</p>
        </div>
        <a href="/Badminton_court_Booking/owner/package_rental/" class="<?= sidebar_class('/package_rental/') ?>">
            <i class="fas fa-box w-5"></i>ແພັກເກດ
            <?php if (!$is_active): ?>
                <span class="ml-auto bg-yellow-400 text-yellow-900 text-xs font-bold rounded-full px-2 py-0.5">!</span>
            <?php elseif ($pkg_expiring): ?>
                <span class="ml-auto bg-orange-500 text-white text-xs font-bold rounded-full px-2 py-0.5">⚠</span>
            <?php endif; ?>
        </a>

        <!-- ── ສະຖານທີ່ ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">ການຈັດການສະຖານທີ່</p>
        </div>

        <?php if ($is_active): ?>
            <a href="/Badminton_court_Booking/owner/manage_court/" class="<?= sidebar_class('/manage_court/') ?>">
                <i class="fas fa-store w-5"></i>ສະຖານທີ່ ແລະ ຄອດ
            </a>
            <a href="/Badminton_court_Booking/owner/booking_management/" class="<?= sidebar_class('/booking_management/') ?>">
                <i class="fas fa-calendar-check w-5"></i>ການຈອງ
                <?php if ($pending_count > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-xs rounded-full px-2 py-0.5"><?= $pending_count ?></span>
                <?php endif; ?>
            </a>
            <a href="/Badminton_court_Booking/owner/facilities/" class="<?= sidebar_class('/facilities/') ?>">
                <i class="fas fa-concierge-bell w-5"></i>ສິ່ງອຳນວຍຄວາມສະດວກ
            </a>
            <a href="/Badminton_court_Booking/owner/advertisement/" class="<?= sidebar_class('/advertisement/') ?>">
                <i class="fas fa-bullhorn w-5"></i>ໂຄສະນາ
                <?php if ($ad_expiring): ?>
                    <span class="ml-auto bg-orange-500 text-white text-xs rounded-full px-2 py-0.5">⚠</span>
                <?php endif; ?>
            </a>
            <a href="/Badminton_court_Booking/owner/customers/" class="<?= sidebar_class('/owner/customers/') ?>">
                <i class="fas fa-users w-5"></i>ລູກຄ້າ
            </a>
        <?php else: ?>
            <?php sidebar_locked('ສະຖານທີ່ ແລະ ຄອດ'); ?>
            <?php sidebar_locked('ການຈອງ'); ?>
            <?php sidebar_locked('ສິ່ງອຳນວຍຄວາມສະດວກ'); ?>
            <?php sidebar_locked('ໂຄສະນາ'); ?>
            <?php sidebar_locked('ລູກຄ້າ'); ?>
        <?php endif; ?>

        <!-- ── ລາຍງານ ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">ລາຍງານ</p>
        </div>

        <?php if ($is_active): ?>
            <a href="/Badminton_court_Booking/owner/reports/" class="<?= sidebar_class('/reports/') ?>">
                <i class="fas fa-chart-bar w-5"></i>ລາຍງານ
            </a>
        <?php else: ?>
            <?php sidebar_locked('ລາຍງານ'); ?>
        <?php endif; ?>

        <!-- ── ບັນຊີ (Profile always accessible) ── -->
        <div class="pt-3 pb-1">
            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider px-4">ບັນຊີ</p>
        </div>
        <a href="/Badminton_court_Booking/owner/profile/" class="<?= sidebar_class('/owner/profile/') ?>">
            <i class="fas fa-user w-5"></i>ໂປຣໄຟລ໌
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
                <p class="text-xs text-gray-400">ເຈົ້າຂອງເດີ່ນ</p>
            </div>
        </div>
        <a href="/Badminton_court_Booking/auth/logout.php"
           class="w-full flex items-center gap-2 text-red-600 hover:bg-red-50 px-3 py-2 rounded-lg text-sm transition">
            <i class="fas fa-sign-out-alt"></i>ອອກຈາກລະບົບ
        </a>
    </div>

</aside>