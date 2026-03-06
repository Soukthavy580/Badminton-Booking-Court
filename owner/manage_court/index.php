<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id      = $_SESSION['ca_id'];
$owner_name = $_SESSION['user_name'];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM package WHERE CA_ID = ? AND Status_Package = 'Active' AND End_time > NOW()");
    $stmt->execute([$ca_id]);
    $is_active = $stmt->fetchColumn() > 0;
} catch (PDOException $e) { $is_active = false; }

try {
    $stmt = $pdo->prepare("SELECT * FROM Venue_data WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $venue = $stmt->fetch();
} catch (PDOException $e) { $venue = null; }

$courts = [];
if ($venue) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM Court_data WHERE VN_ID = ? ORDER BY COURT_Name");
        $stmt->execute([$venue['VN_ID']]);
        $courts = $stmt->fetchAll();
    } catch (PDOException $e) { $courts = []; }
}

// ── Fetch venue rejection notification ──
$rejection = null;
if ($venue) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM owner_notification 
            WHERE CA_ID = ? AND type = 'venue' AND ref_id = ?
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$ca_id, $venue['VN_ID']]);
        $rejection = $stmt->fetch();
    } catch (PDOException $e) { $rejection = null; }
}

$error   = '';
$success = '';
$tab     = $_GET['tab'] ?? 'venue';

// ── RESUBMIT VENUE ──
if (isset($_GET['resubmit']) && $venue) {
    try {
        $pdo->prepare("UPDATE Venue_data SET VN_Status = 'Pending' WHERE VN_ID = ? AND CA_ID = ?")
            ->execute([$venue['VN_ID'], $ca_id]);

        // Clear the rejection notification — issue is being resubmitted
        $pdo->prepare("DELETE FROM owner_notification WHERE CA_ID = ? AND type = 'venue' AND ref_id = ?")
            ->execute([$ca_id, $venue['VN_ID']]);

        $success = 'Venue resubmitted for approval!';
        $venue['VN_Status'] = 'Pending';
        $rejection = null;
    } catch (PDOException $e) { $error = 'Failed to resubmit.'; }
}

// ── COURT STATUS UPDATE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_court_status'])) {
    $court_id   = intval($_POST['court_id'] ?? 0);
    $new_status = $_POST['court_status'] ?? '';
    $tab = 'courts';
    if ($court_id && in_array($new_status, ['Active','Inactive','Maintaining']) && $venue) {
        try {
            $pdo->prepare("UPDATE Court_data SET Court_Status = ? WHERE COURT_ID = ? AND VN_ID = ?")
                ->execute([$new_status, $court_id, $venue['VN_ID']]);
            $success = "Court status updated to \"$new_status\".";
            $stmt = $pdo->prepare("SELECT * FROM Court_data WHERE VN_ID = ? ORDER BY COURT_Name");
            $stmt->execute([$venue['VN_ID']]);
            $courts = $stmt->fetchAll();
        } catch (PDOException $e) { $error = 'Failed to update status.'; }
    }
}

// ── VENUE SAVE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_venue'])) {
    $vn_name    = trim($_POST['vn_name']    ?? '');
    $vn_address = trim($_POST['vn_address'] ?? '');
    $vn_desc    = trim($_POST['vn_desc']    ?? '');
    $open_time  = $_POST['open_time']       ?? '';
    $close_time = $_POST['close_time']      ?? '';
    $price      = trim($_POST['price']      ?? '');
    $map_url    = trim($_POST['map_url']    ?? '');

    if (empty($vn_name) || empty($vn_address) || empty($open_time) || empty($close_time) || empty($price)) {
        $error = 'Please fill in all required fields.';
    } else {
        try {
            $vn_image = $venue['VN_Image'] ?? '';
            if (!empty($_FILES['vn_image']['name'])) {
                $file = $_FILES['vn_image'];
                if (in_array($file['type'], ['image/jpeg','image/png','image/jpg','image/webp']) && $file['size'] <= 5*1024*1024) {
                    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'venue_' . $ca_id . '_' . time() . '.' . $ext;
                    $dir      = $_SERVER['DOCUMENT_ROOT'] . '/Badminton_court_Booking/assets/images/venues/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    if (move_uploaded_file($file['tmp_name'], $dir . $filename)) $vn_image = $filename;
                } else { $error = 'Image must be JPG/PNG/WEBP under 5MB.'; }
            }

            $vn_qr = $venue['VN_QR_Payment'] ?? '';
            if (!empty($_FILES['vn_qr']['name'])) {
                $file = $_FILES['vn_qr'];
                if (in_array($file['type'], ['image/jpeg','image/png','image/jpg','image/webp']) && $file['size'] <= 5*1024*1024) {
                    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'qr_' . $ca_id . '_' . time() . '.' . $ext;
                    $dir      = $_SERVER['DOCUMENT_ROOT'] . '/Badminton_court_Booking/assets/images/qr/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    if (move_uploaded_file($file['tmp_name'], $dir . $filename)) $vn_qr = $filename;
                }
            }

            if (empty($error)) {
                if ($venue) {
                    $pdo->prepare("
                        UPDATE Venue_data SET
                            VN_Name=?, VN_Address=?, VN_Description=?,
                            Open_time=?, Close_time=?, Price_per_hour=?,
                            VN_MapURL=?, VN_Image=?, VN_QR_Payment=?
                        WHERE VN_ID=? AND CA_ID=?
                    ")->execute([$vn_name,$vn_address,$vn_desc,$open_time,$close_time,$price,$map_url,$vn_image,$vn_qr,$venue['VN_ID'],$ca_id]);
                    $vn_id = $venue['VN_ID'];
                } else {
                    $pdo->prepare("
                        INSERT INTO Venue_data
                            (VN_Name,VN_Address,VN_Description,Open_time,Close_time,Price_per_hour,VN_MapURL,VN_Image,VN_QR_Payment,VN_Status,CA_ID)
                        VALUES (?,?,?,?,?,?,?,?,?,'Pending',?)
                    ")->execute([$vn_name,$vn_address,$vn_desc,$open_time,$close_time,$price,$map_url,$vn_image,$vn_qr,$ca_id]);
                    $vn_id = $pdo->lastInsertId();
                    $pdo->prepare("UPDATE package SET VN_ID=? WHERE CA_ID=? AND VN_ID IS NULL")
                        ->execute([$vn_id, $ca_id]);
                }

                $stmt = $pdo->prepare("SELECT * FROM Venue_data WHERE CA_ID=? LIMIT 1");
                $stmt->execute([$ca_id]);
                $venue = $stmt->fetch();
                $success = $venue['VN_Status']==='Pending' ? 'Venue saved! Waiting for admin approval.' : 'Venue updated successfully!';
                $tab = 'venue';
            }
        } catch (PDOException $e) {
            $error = 'Failed to save venue: ' . $e->getMessage();
        }
    }
}

// ── ADD / EDIT COURT ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_court'])) {
    $court_id   = intval($_POST['court_id'] ?? 0);
    $court_name = trim($_POST['court_name'] ?? '');
    $tab = 'courts';
    if (!$venue)          { $error = 'Please save your venue details first.'; }
    elseif (!$is_active)  { $error = 'You need an active package to manage courts.'; }
    elseif (!$court_name) { $error = 'Court name is required.'; }
    else {
        $court_open  = $_POST['court_open']  ?? null;
        $court_close = $_POST['court_close'] ?? null;
        try {
            if ($court_id) {
                $pdo->prepare("UPDATE Court_data SET COURT_Name=?, Open_time=?, Close_time=? WHERE COURT_ID=? AND VN_ID=?")
                    ->execute([$court_name, $court_open, $court_close, $court_id, $venue['VN_ID']]);
            } else {
                $pdo->prepare("INSERT INTO Court_data (COURT_Name, VN_ID, Court_Status, Open_time, Close_time) VALUES (?,?,'Active',?,?)")
                    ->execute([$court_name, $venue['VN_ID'], $court_open, $court_close]);
            }
            $success = $court_id ? 'Court updated!' : 'Court added!';
            $stmt = $pdo->prepare("SELECT * FROM Court_data WHERE VN_ID=? ORDER BY COURT_Name");
            $stmt->execute([$venue['VN_ID']]);
            $courts = $stmt->fetchAll();
        } catch (PDOException $e) { $error = 'Failed to save court.'; }
    }
}

// ── DELETE COURT ──
if (isset($_GET['delete_court']) && $venue) {
    $del_id = intval($_GET['delete_court']);
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM booking_detail bd
            INNER JOIN booking b ON bd.Book_ID = b.Book_ID
            WHERE bd.COURT_ID=? AND b.Status_booking IN ('Confirmed','Pending')
        ");
        $stmt->execute([$del_id]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Cannot delete a court with active bookings.';
        } else {
            $pdo->prepare("DELETE FROM Court_data WHERE COURT_ID=? AND VN_ID=?")
                ->execute([$del_id, $venue['VN_ID']]);
            $success = 'Court deleted.';
            $stmt = $pdo->prepare("SELECT * FROM Court_data WHERE VN_ID=? ORDER BY COURT_Name");
            $stmt->execute([$venue['VN_ID']]);
            $courts = $stmt->fetchAll();
        }
    } catch (PDOException $e) { $error = 'Failed to delete court.'; }
    $tab = 'courts';
}

$venue_img = !empty($venue['VN_Image'])
    ? '/Badminton_court_Booking/assets/images/venues/' . basename($venue['VN_Image']) : '';
$venue_qr = !empty($venue['VN_QR_Payment'])
    ? '/Badminton_court_Booking/assets/images/qr/' . basename($venue['VN_QR_Payment']) : '';
$courts_locked = !$is_active;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Venue - CourtBook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .upload-box { border: 2px dashed #d1d5db; transition: all 0.3s; cursor: pointer; }
        .upload-box:hover { border-color: #16a34a; background: #f0fdf4; }
        .tab-btn { transition: all 0.2s; }
        .tab-btn.active { background: #16a34a; color: white; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <h1 class="text-xl font-bold text-gray-800">My Venue</h1>
            <p class="text-sm text-gray-500">
                <?= $venue ? 'Edit your venue details and manage courts' : 'Set up your venue to start receiving bookings' ?>
            </p>
        </header>

        <main class="flex-1 p-6 max-w-4xl mx-auto w-full">

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl flex items-center gap-3">
                    <i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-300 text-green-700 rounded-xl flex items-center gap-3">
                    <i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <!-- ── VENUE STATUS BANNER ── -->
            <?php if ($venue): ?>

                <?php if ($venue['VN_Status'] === 'Pending'): ?>
                    <div class="bg-yellow-50 border border-yellow-300 rounded-2xl p-4 mb-6 flex items-center gap-3">
                        <i class="fas fa-clock text-yellow-500 text-xl flex-shrink-0"></i>
                        <div>
                            <p class="font-bold text-yellow-800">Venue Pending Approval</p>
                            <p class="text-yellow-600 text-sm">Your venue is under review by admin.</p>
                        </div>
                    </div>

                <?php elseif ($venue['VN_Status'] === 'Active'): ?>
                    <div class="bg-green-50 border border-green-300 rounded-2xl p-4 mb-6 flex items-center gap-3">
                        <i class="fas fa-check-circle text-green-500 text-xl flex-shrink-0"></i>
                        <div>
                            <p class="font-bold text-green-800">Venue is Live!</p>
                            <p class="text-green-600 text-sm">Your venue is approved and visible to customers.</p>
                        </div>
                    </div>

                <?php elseif (in_array($venue['VN_Status'], ['Rejected','Inactive'])): ?>
                    <div class="bg-red-50 border-2 border-red-300 rounded-2xl p-5 mb-6 flex items-start gap-4">
                        <div class="bg-red-100 p-3 rounded-full flex-shrink-0">
                            <i class="fas fa-times-circle text-red-500 text-xl"></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-2">
                                <p class="font-extrabold text-red-700">Venue Rejected</p>
                                <span class="bg-red-100 text-red-600 text-xs font-bold px-2 py-0.5 rounded-full">Action Required</span>
                            </div>

                            <?php if ($rejection): ?>
                                <!-- Rejection reason from admin -->
                                <div class="bg-white border border-red-200 rounded-xl px-4 py-3 mb-3">
                                    <p class="text-xs font-bold text-red-500 mb-1">
                                        <i class="fas fa-user-shield mr-1"></i>Reason from Admin:
                                    </p>
                                    <p class="text-sm text-gray-700"><?= nl2br(htmlspecialchars($rejection['message'])) ?></p>
                                </div>

                                <!-- Flagged fields -->
                                <?php
                                $flagged = !empty($rejection['flagged_fields']) ? json_decode($rejection['flagged_fields'], true) : [];
                                if (!empty($flagged)):
                                ?>
                                    <div class="bg-orange-50 border border-orange-200 rounded-xl p-3 mb-3">
                                        <p class="text-xs font-bold text-orange-600 mb-2">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Fields that need fixing:
                                        </p>
                                        <div class="flex flex-wrap gap-2">
                                            <?php foreach ($flagged as $field): ?>
                                                <span class="bg-white border border-orange-300 text-orange-700 text-xs font-semibold px-3 py-1.5 rounded-lg">
                                                    <i class="fas fa-times-circle text-orange-400 mr-1"></i><?= htmlspecialchars($field) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <p class="text-xs text-gray-400 mb-3">
                                    <i class="fas fa-clock mr-1"></i><?= date('M d, Y \a\t g:i A', strtotime($rejection['created_at'])) ?>
                                </p>
                            <?php endif; ?>

                            <p class="text-sm text-red-600 mb-3">Fix the issues above, then resubmit for approval.</p>
                            <a href="?resubmit=1" onclick="return confirm('Resubmit venue for approval?')"
                               class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl text-sm font-bold transition">
                                <i class="fas fa-paper-plane"></i>Resubmit for Approval
                            </a>
                        </div>
                    </div>

                <?php elseif ($venue['VN_Status'] === 'Maintaining'): ?>
                    <div class="bg-yellow-50 border border-yellow-300 rounded-2xl p-4 mb-6 flex items-center gap-3">
                        <i class="fas fa-tools text-yellow-500 text-xl flex-shrink-0"></i>
                        <div>
                            <p class="font-bold text-yellow-800">Venue Under Maintenance</p>
                            <p class="text-yellow-600 text-sm">Visible to customers but bookings are disabled.</p>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

            <!-- Tabs -->
            <div class="flex gap-2 mb-6 bg-white rounded-2xl p-2 shadow-sm">
                <button onclick="switchTab('venue')" id="tab_venue"
                        class="tab-btn flex-1 py-2.5 px-4 rounded-xl font-semibold text-sm text-gray-600 <?= $tab==='venue' ? 'active' : '' ?>">
                    <i class="fas fa-store mr-2"></i>Venue Details
                </button>
                <button onclick="<?= $courts_locked ? 'showLockedMsg()' : "switchTab('courts')" ?>"
                        id="tab_courts"
                        class="tab-btn flex-1 py-2.5 px-4 rounded-xl font-semibold text-sm
                               <?= $courts_locked ? 'text-gray-300 cursor-not-allowed' : 'text-gray-600' ?>
                               <?= (!$courts_locked && $tab==='courts') ? 'active' : '' ?>">
                    <i class="fas fa-<?= $courts_locked ? 'lock' : 'table-tennis' ?> mr-2"></i>
                    Courts
                    <?php if (!$courts_locked && count($courts) > 0): ?>
                        <span class="ml-1 bg-green-100 text-green-700 text-xs rounded-full px-2"><?= count($courts) ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- ═══ VENUE DETAILS TAB ═══ -->
            <div id="pane_venue" class="<?= $tab!=='venue' ? 'hidden' : '' ?>">
                <form method="POST" enctype="multipart/form-data">
                    <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                        <h2 class="font-bold text-gray-800 text-lg mb-5">
                            <i class="fas fa-info-circle text-blue-500 mr-2"></i>Basic Information
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-bold text-gray-700 mb-2">Venue Name <span class="text-red-500">*</span></label>
                                <input type="text" name="vn_name" value="<?= htmlspecialchars($venue['VN_Name'] ?? '') ?>"
                                       placeholder="e.g. Champion Badminton Center"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition" required>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-bold text-gray-700 mb-2">Address <span class="text-red-500">*</span></label>
                                <input type="text" name="vn_address" value="<?= htmlspecialchars($venue['VN_Address'] ?? '') ?>"
                                       placeholder="e.g. 123 Main Road, Vientiane"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition" required>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-bold text-gray-700 mb-2">Description</label>
                                <textarea name="vn_desc" rows="3" placeholder="Tell customers about your venue..."
                                          class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition resize-none"><?= htmlspecialchars($venue['VN_Description'] ?? '') ?></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Opening Time <span class="text-red-500">*</span></label>
                                <input type="time" name="open_time" value="<?= htmlspecialchars($venue['Open_time'] ?? '07:00') ?>"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition" required>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Closing Time <span class="text-red-500">*</span></label>
                                <input type="time" name="close_time" value="<?= htmlspecialchars($venue['Close_time'] ?? '22:00') ?>"
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition" required>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Price per Hour (₭) <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <span class="absolute left-4 top-3.5 text-gray-400 font-bold">₭</span>
                                    <input type="text" name="price" value="<?= htmlspecialchars($venue['Price_per_hour'] ?? '') ?>"
                                           placeholder="e.g. 50000"
                                           class="w-full border-2 border-gray-200 rounded-xl pl-8 pr-4 py-3 focus:outline-none focus:border-green-500 transition" required>
                                </div>
                                <p class="text-xs text-gray-400 mt-1">Customers pay 30% deposit, 70% at venue</p>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Google Maps Link</label>
                                <input type="url" name="map_url" value="<?= htmlspecialchars($venue['VN_MapURL'] ?? '') ?>"
                                       placeholder="https://maps.google.com/..."
                                       class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition">
                            </div>
                        </div>
                    </div>

                    <!-- Images -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                        <h2 class="font-bold text-gray-800 text-lg mb-5">
                            <i class="fas fa-images text-purple-500 mr-2"></i>Images
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Venue Photo</label>
                                <div class="upload-box rounded-xl p-4 text-center" onclick="document.getElementById('venueImg').click()">
                                    <?php if ($venue_img): ?>
                                        <img src="<?= htmlspecialchars($venue_img) ?>" id="venueImgPreview" class="w-full h-32 object-cover rounded-lg mb-2">
                                        <p class="text-xs text-gray-400">Click to change photo</p>
                                    <?php else: ?>
                                        <div id="venueImgPreviewWrap">
                                            <i class="fas fa-image text-3xl text-gray-300 mb-2 block"></i>
                                            <p class="text-gray-400 text-sm">Click to upload venue photo</p>
                                        </div>
                                        <img id="venueImgPreview" class="w-full h-32 object-cover rounded-lg mb-2 hidden">
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="venueImg" name="vn_image" accept="image/*" class="hidden"
                                       onchange="previewImg(this,'venueImgPreview','venueImgPreviewWrap')">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">QR Code for Payment</label>
                                <div class="upload-box rounded-xl p-4 text-center" onclick="document.getElementById('qrImg').click()">
                                    <?php if ($venue_qr): ?>
                                        <img src="<?= htmlspecialchars($venue_qr) ?>" id="qrImgPreview" class="w-32 h-32 object-contain rounded-lg mx-auto mb-2">
                                        <p class="text-xs text-gray-400">Click to change QR</p>
                                    <?php else: ?>
                                        <div id="qrImgPreviewWrap">
                                            <i class="fas fa-qrcode text-3xl text-gray-300 mb-2 block"></i>
                                            <p class="text-gray-400 text-sm">Upload your QR code</p>
                                        </div>
                                        <img id="qrImgPreview" class="w-32 h-32 object-contain rounded-lg mx-auto mb-2 hidden">
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="qrImg" name="vn_qr" accept="image/*" class="hidden"
                                       onchange="previewImg(this,'qrImgPreview','qrImgPreviewWrap')">
                            </div>
                        </div>
                    </div>

                    <!-- Facilities -->
                    <?php
                    $facilities = [];
                    if ($venue) {
                        try {
                            $stmt = $pdo->prepare("SELECT * FROM facilities WHERE VN_ID = ? ORDER BY Fac_Name");
                            $stmt->execute([$venue['VN_ID']]);
                            $facilities = $stmt->fetchAll();
                        } catch (PDOException $e) { $facilities = []; }
                    }
                    if (!empty($facilities)): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                            <h2 class="font-bold text-gray-800 text-lg mb-4">
                                <i class="fas fa-concierge-bell text-yellow-500 mr-2"></i>Facilities
                                <span class="text-sm font-normal text-gray-400 ml-1">(<?= count($facilities) ?> listed)</span>
                            </h2>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($facilities as $fac): ?>
                                    <span class="bg-green-50 border border-green-200 text-green-700 text-sm font-semibold px-3 py-1.5 rounded-xl">
                                        <i class="fas fa-check-circle text-green-500 mr-1"></i><?= htmlspecialchars($fac['Fac_Name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-xs text-gray-400 mt-3">
                                <a href="/Badminton_court_Booking/owner/facilities/index.php" class="text-blue-500 hover:underline">
                                    <i class="fas fa-edit mr-1"></i>Manage facilities
                                </a>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-4 mb-6 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <i class="fas fa-concierge-bell text-gray-300 text-2xl"></i>
                                <div>
                                    <p class="text-sm font-semibold text-gray-500">No facilities added yet</p>
                                    <p class="text-xs text-gray-400">Let customers know what amenities you offer</p>
                                </div>
                            </div>
                            <a href="/Badminton_court_Booking/owner/facilities/index.php"
                               class="bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-4 py-2 rounded-xl transition flex-shrink-0">
                                <i class="fas fa-plus mr-1"></i>Add Facilities
                            </a>
                        </div>
                    <?php endif; ?>

                    <button type="submit" name="save_venue"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-xl transition shadow-lg text-lg">
                        <i class="fas fa-save mr-2"></i><?= $venue ? 'Save Changes' : 'Create Venue' ?>
                    </button>
                </form>
            </div>

            <!-- ═══ COURTS TAB ═══ -->
            <div id="pane_courts" class="<?= $tab!=='courts' ? 'hidden' : '' ?>">

                <?php if ($courts_locked): ?>
                    <div class="bg-white border-2 border-dashed border-gray-200 rounded-2xl p-10 text-center">
                        <i class="fas fa-lock text-4xl text-gray-300 mb-4 block"></i>
                        <p class="text-gray-500 font-bold mb-1">Active Package Required</p>
                        <p class="text-gray-400 text-sm mb-4">You need an active package to manage courts.</p>
                        <a href="/Badminton_court_Booking/owner/package_rental/index.php"
                           class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold px-6 py-2 rounded-xl transition text-sm">
                            <i class="fas fa-box mr-1"></i>Get a Package
                        </a>
                    </div>

                <?php elseif (!$venue): ?>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-2xl p-8 text-center">
                        <i class="fas fa-exclamation-circle text-yellow-400 text-4xl mb-3 block"></i>
                        <p class="text-yellow-700 font-semibold mb-1">Save Your Venue First</p>
                        <button onclick="switchTab('venue')"
                                class="mt-4 bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-2 rounded-xl font-semibold transition text-sm">
                            Go to Venue Details
                        </button>
                    </div>

                <?php else: ?>
                    <!-- Status Legend -->
                    <div class="bg-white rounded-2xl shadow-sm p-4 mb-5 grid grid-cols-3 gap-3">
                        <?php foreach ([
                            ['icon'=>'fa-check-circle', 'color'=>'green',  'label'=>'Active',      'desc'=>'Bookable by customers'],
                            ['icon'=>'fa-eye-slash',    'color'=>'gray',   'label'=>'Inactive',    'desc'=>'Hidden from customers'],
                            ['icon'=>'fa-tools',        'color'=>'yellow', 'label'=>'Maintaining', 'desc'=>'Visible, not bookable'],
                        ] as $leg): ?>
                            <div class="flex items-center gap-2 bg-<?= $leg['color'] ?>-50 rounded-xl px-3 py-2">
                                <i class="fas <?= $leg['icon'] ?> text-<?= $leg['color'] ?>-500"></i>
                                <div>
                                    <p class="text-xs font-bold text-<?= $leg['color'] ?>-700"><?= $leg['label'] ?></p>
                                    <p class="text-xs text-<?= $leg['color'] ?>-500"><?= $leg['desc'] ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Add/Edit Court Form -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                        <h2 class="font-bold text-gray-800 text-lg mb-4">
                            <i class="fas fa-plus-circle text-green-500 mr-2"></i>
                            <span id="courtFormTitle">Add New Court</span>
                        </h2>
                        <form method="POST">
                            <input type="hidden" name="court_id" id="courtIdInput" value="0">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div class="md:col-span-3">
                                    <label class="block text-sm font-bold text-gray-700 mb-2">Court Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="court_name" id="courtNameInput"
                                           placeholder="e.g. Court A, Court 1"
                                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-door-open text-green-500 mr-1"></i>Court Opens
                                    </label>
                                    <input type="time" name="court_open" id="courtOpenInput"
                                           value="""
                                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition">
                                    <p class="text-xs text-gray-400 mt-1">When this court is available</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-door-closed text-red-500 mr-1"></i>Court Closes
                                    </label>
                                    <input type="time" name="court_close" id="courtCloseInput"
                                           value="""
                                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500 transition">
                                    <p class="text-xs text-gray-400 mt-1">When this court stops taking bookings</p>
                                </div>
                                <div class="flex items-end">
                                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 w-full">
                                        <p class="text-xs font-bold text-blue-600 mb-1"><i class="fas fa-info-circle mr-1"></i>Note</p>
                                        <p class="text-xs text-blue-500">Court hours can differ from venue hours. Venue hours show on the venue page.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <button type="submit" name="save_court"
                                        class="bg-green-600 hover:bg-green-700 text-white font-bold px-6 py-3 rounded-xl transition">
                                    <i class="fas fa-save mr-1"></i><span id="courtBtnText">Add Court</span>
                                </button>
                                <button type="button" onclick="resetCourtForm()" id="cancelCourtBtn"
                                        class="hidden bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-4 py-3 rounded-xl transition">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Courts List -->
                    <?php if (!empty($courts)): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-6">
                            <h2 class="font-bold text-gray-800 text-lg mb-4">
                                <i class="fas fa-list text-blue-500 mr-2"></i>Your Courts (<?= count($courts) ?>)
                            </h2>
                            <div class="space-y-3">
                                <?php foreach ($courts as $court):
                                    $cs = $court['Court_Status'] ?? 'Active';
                                    $cs_cfg = match($cs) {
                                        'Active'      => ['bg'=>'bg-green-100',  'text'=>'text-green-700',  'icon'=>'fa-check-circle', 'border'=>'border-green-300'],
                                        'Inactive'    => ['bg'=>'bg-gray-100',   'text'=>'text-gray-500',   'icon'=>'fa-eye-slash',    'border'=>'border-gray-300'],
                                        'Maintaining' => ['bg'=>'bg-yellow-100', 'text'=>'text-yellow-700', 'icon'=>'fa-tools',        'border'=>'border-yellow-300'],
                                        default       => ['bg'=>'bg-green-100',  'text'=>'text-green-700',  'icon'=>'fa-check-circle', 'border'=>'border-green-300'],
                                    };
                                ?>
                                    <div class="bg-gray-50 rounded-xl border border-gray-100 p-4">
                                        <div class="flex items-center justify-between gap-4 flex-wrap">
                                            <div class="flex items-center gap-3">
                                                <div class="<?= $cs_cfg['bg'] ?> w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0">
                                                    <i class="fas fa-table-tennis <?= $cs_cfg['text'] ?>"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-gray-800"><?= htmlspecialchars($court['COURT_Name']) ?></p>
                                                    <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                                        <span class="inline-flex items-center gap-1 text-xs font-semibold <?= $cs_cfg['bg'] ?> <?= $cs_cfg['text'] ?> px-2 py-0.5 rounded-full">
                                                            <i class="fas <?= $cs_cfg['icon'] ?> text-xs"></i><?= $cs ?>
                                                        </span>
                                                        <?php if (!empty($court['Open_time']) && !empty($court['Close_time'])): ?>
                                                            <span class="inline-flex items-center gap-1 text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">
                                                                <i class="fas fa-clock text-xs text-blue-400"></i>
                                                                <?= date('g:i A', strtotime($court['Open_time'])) ?> – <?= date('g:i A', strtotime($court['Close_time'])) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-xs text-gray-400 font-semibold">Set status:</span>
                                                <?php foreach ([
                                                    ['status'=>'Active',      'color'=>'green',  'icon'=>'fa-check-circle'],
                                                    ['status'=>'Inactive',    'color'=>'gray',   'icon'=>'fa-eye-slash'],
                                                    ['status'=>'Maintaining', 'color'=>'yellow', 'icon'=>'fa-tools'],
                                                ] as $btn):
                                                    $is_current = $cs === $btn['status'];
                                                ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="update_court_status" value="1">
                                                        <input type="hidden" name="court_id" value="<?= $court['COURT_ID'] ?>">
                                                        <input type="hidden" name="court_status" value="<?= $btn['status'] ?>">
                                                        <button type="submit" <?= $is_current ? 'disabled' : '' ?>
                                                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition
                                                                       <?= $is_current
                                                                           ? 'bg-'.$btn['color'].'-500 text-white cursor-default ring-2 ring-'.$btn['color'].'-300'
                                                                           : 'bg-'.$btn['color'].'-50 text-'.$btn['color'].'-700 hover:bg-'.$btn['color'].'-100 border border-'.$btn['color'].'-200' ?>">
                                                            <i class="fas <?= $btn['icon'] ?> text-xs"></i>
                                                            <?= $btn['status'] ?>
                                                            <?php if ($is_current): ?><i class="fas fa-check text-xs"></i><?php endif; ?>
                                                        </button>
                                                    </form>
                                                <?php endforeach; ?>
                                                <button onclick="editCourt(<?= $court['COURT_ID'] ?>, '<?= htmlspecialchars(addslashes($court['COURT_Name'])) ?>', '<?= htmlspecialchars($court['Open_time'] ?? '07:00') ?>', '<?= htmlspecialchars($court['Close_time'] ?? '22:00') ?>')"
                                                        class="bg-blue-50 hover:bg-blue-100 text-blue-600 px-3 py-1.5 rounded-lg text-xs font-bold transition border border-blue-200">
                                                    <i class="fas fa-edit mr-1"></i>Rename
                                                </button>
                                                <a href="?delete_court=<?= $court['COURT_ID'] ?>&tab=courts"
                                                   onclick="return confirm('Delete <?= htmlspecialchars(addslashes($court['COURT_Name'])) ?>?')"
                                                   class="bg-red-50 hover:bg-red-100 text-red-600 px-3 py-1.5 rounded-lg text-xs font-bold transition border border-red-200">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="bg-white rounded-2xl shadow-sm p-10 text-center">
                            <i class="fas fa-table-tennis text-5xl text-gray-200 mb-4 block"></i>
                            <p class="text-gray-400 font-medium">No courts added yet</p>
                            <p class="text-gray-300 text-sm mt-1">Add your first court above</p>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script>
function switchTab(tab) {
    ['venue','courts'].forEach(t => {
        document.getElementById('pane_'+t).classList.toggle('hidden', t!==tab);
        document.getElementById('tab_'+t).classList.toggle('active', t===tab);
    });
}
function showLockedMsg() { alert('You need an active package to manage courts.'); }
function previewImg(input, previewId, wrapId) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById(previewId);
        img.src = e.target.result;
        img.classList.remove('hidden');
        const wrap = document.getElementById(wrapId);
        if (wrap) wrap.classList.add('hidden');
    };
    reader.readAsDataURL(file);
}
function editCourt(id, name, open_time, close_time) {
    document.getElementById('courtIdInput').value    = id;
    document.getElementById('courtNameInput').value  = name;
    document.getElementById('courtOpenInput').value  = open_time  || '';
    document.getElementById('courtCloseInput').value = close_time || '';
    document.getElementById('courtFormTitle').textContent = 'Edit Court';
    document.getElementById('courtBtnText').textContent   = 'Update Court';
    document.getElementById('cancelCourtBtn').classList.remove('hidden');
    document.getElementById('courtNameInput').focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function resetCourtForm() {
    document.getElementById('courtIdInput').value    = '0';
    document.getElementById('courtNameInput').value  = '';
    document.getElementById('courtOpenInput').value  = '';
    document.getElementById('courtCloseInput').value = '';
    document.getElementById('courtFormTitle').textContent = 'Add New Court';
    document.getElementById('courtBtnText').textContent   = 'Add Court';
    document.getElementById('cancelCourtBtn').classList.add('hidden');
}
</script>
</body>
</html>