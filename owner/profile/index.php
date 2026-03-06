<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id = $_SESSION['ca_id'];

// Fetch owner data
try {
    $stmt = $pdo->prepare("SELECT * FROM court_owner WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $owner = $stmt->fetch();
} catch (PDOException $e) { $owner = []; }

// Fetch venue
try {
    $stmt = $pdo->prepare("SELECT * FROM Venue_data WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $venue = $stmt->fetch();
} catch (PDOException $e) { $venue = null; }

// Fetch active package
try {
    $stmt = $pdo->prepare("
        SELECT bp.*, pr.Package_duration
        FROM package bp
        INNER JOIN package_rate pr ON bp.Package_rate_ID = pr.Package_rate_ID
        WHERE bp.VN_ID = ? AND bp.Status_Package = 'Active' AND bp.End_time > NOW()
        ORDER BY bp.End_time DESC LIMIT 1
    ");
    $stmt->execute([$venue['VN_ID'] ?? 0]);
    $active_package = $stmt->fetch();
} catch (PDOException $e) { $active_package = null; }

// Booking stats
$stats = ['total'=>0,'confirmed'=>0,'pending'=>0,'revenue'=>0];
if ($venue) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT b.Book_ID) AS total,
                SUM(CASE WHEN b.Status_booking = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed,
                SUM(CASE WHEN b.Status_booking = 'Pending'   THEN 1 ELSE 0 END) AS pending
            FROM booking b
            INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
            INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
            WHERE c.VN_ID = ?
        ");
        $stmt->execute([$venue['VN_ID']]);
        $row = $stmt->fetch();
        $stats['total']     = $row['total']     ?? 0;
        $stats['confirmed'] = $row['confirmed'] ?? 0;
        $stats['pending']   = $row['pending']   ?? 0;
    } catch (PDOException $e) {}
}

$success = $_SESSION['profile_success'] ?? '';
unset($_SESSION['profile_success']);

$days_left = $active_package
    ? ceil((strtotime($active_package['End_time']) - time()) / 86400)
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - CourtBook Owner</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col">
        <!-- Top Bar -->
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <h1 class="text-xl font-bold text-gray-800">My Profile</h1>
            <p class="text-sm text-gray-500">Manage your account information</p>
        </header>

        <main class="flex-1 p-6 max-w-4xl mx-auto w-full">

            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-300 text-green-700 rounded-xl flex items-center gap-3">
                    <i class="fas fa-check-circle text-xl"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <!-- Profile Header -->
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <div class="flex flex-col md:flex-row items-center md:items-start gap-6">
                    <!-- Avatar -->
                    <div class="flex-shrink-0">
                        <div class="w-24 h-24 bg-gradient-to-br from-green-400 to-blue-500 rounded-full flex items-center justify-center text-white text-4xl font-bold shadow-lg">
                            <?= strtoupper(substr($owner['Name'] ?? 'O', 0, 1)) ?>
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="flex-1 text-center md:text-left">
                        <h2 class="text-2xl font-extrabold text-gray-800">
                            <?= htmlspecialchars(($owner['Name'] ?? '') . ' ' . ($owner['Surname'] ?? '')) ?>
                        </h2>
                        <p class="text-gray-500 mt-1">
                            <i class="fas fa-envelope mr-2 text-blue-400"></i><?= htmlspecialchars($owner['Email'] ?? '') ?>
                        </p>
                        <p class="text-gray-500 mt-1">
                            <i class="fas fa-phone mr-2 text-green-400"></i><?= htmlspecialchars($owner['Phone'] ?? '') ?>
                        </p>
                        <p class="text-gray-500 mt-1">
                            <i class="fas fa-store mr-2 text-purple-400"></i>
                            <?= $venue ? htmlspecialchars($venue['VN_Name']) : 'No venue set up yet' ?>
                        </p>

                        <!-- Package Badge -->
                        <?php if ($active_package): ?>
                            <span class="inline-flex items-center gap-2 mt-2 bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm font-semibold">
                                <i class="fas fa-box"></i>
                                <?= htmlspecialchars($active_package['Package_duration']) ?> · <?= $days_left ?> days left
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-2 mt-2 bg-red-100 text-red-600 px-3 py-1 rounded-full text-sm font-semibold">
                                <i class="fas fa-exclamation-circle"></i> No active package
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Edit Button -->
                    <a href="/Badminton_court_Booking/owner/profile/edit_profile.php"
                       class="flex-shrink-0 inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white font-bold px-5 py-2.5 rounded-xl transition shadow">
                        <i class="fas fa-edit"></i> Edit Profile
                    </a>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <?php
                $stat_cards = [
                    ['label'=>'Total Bookings', 'value'=>$stats['total'],     'icon'=>'fa-calendar-alt', 'color'=>'blue'],
                    ['label'=>'Confirmed',      'value'=>$stats['confirmed'], 'icon'=>'fa-check-circle', 'color'=>'green'],
                    ['label'=>'Pending',        'value'=>$stats['pending'],   'icon'=>'fa-clock',        'color'=>'yellow'],
                    ['label'=>'Courts',         'value'=>$venue ? count($courts ?? []) : 0, 'icon'=>'fa-table-tennis','color'=>'purple'],
                ];
                foreach ($stat_cards as $card):
                ?>
                    <div class="bg-white rounded-2xl shadow-sm p-5 text-center border border-gray-100">
                        <div class="bg-<?= $card['color'] ?>-100 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas <?= $card['icon'] ?> text-<?= $card['color'] ?>-500 text-lg"></i>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-800"><?= $card['value'] ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?= $card['label'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Account Details + Venue Info -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <!-- Account Details -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-id-card text-blue-500 mr-2"></i>Account Details
                    </h3>
                    <div class="space-y-3 text-sm">
                        <?php
                        $fields = [
                            ['label'=>'First Name', 'value'=>$owner['Name']     ?? '', 'icon'=>'fa-user'],
                            ['label'=>'Surname',    'value'=>$owner['Surname']  ?? '', 'icon'=>'fa-user'],
                            ['label'=>'Username',   'value'=>$owner['Username'] ?? '', 'icon'=>'fa-at'],
                            ['label'=>'Email',      'value'=>$owner['Email']    ?? '', 'icon'=>'fa-envelope'],
                            ['label'=>'Phone',      'value'=>$owner['Phone']    ?? '', 'icon'=>'fa-phone'],
                        ];
                        foreach ($fields as $f):
                        ?>
                            <div class="flex items-center gap-3 py-2 border-b border-gray-50 last:border-0">
                                <i class="fas <?= $f['icon'] ?> text-gray-400 w-4"></i>
                                <span class="text-gray-500 w-24 flex-shrink-0"><?= $f['label'] ?></span>
                                <span class="font-semibold text-gray-800"><?= htmlspecialchars($f['value']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Venue Summary -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-store text-green-500 mr-2"></i>Venue Summary
                    </h3>
                    <?php if ($venue): ?>
                        <div class="space-y-3 text-sm">
                            <?php
                            $vfields = [
                                ['label'=>'Name',    'value'=>$venue['VN_Name'],       'icon'=>'fa-store'],
                                ['label'=>'Address', 'value'=>$venue['VN_Address'],    'icon'=>'fa-map-marker-alt'],
                                ['label'=>'Hours',   'value'=>$venue['Open_time'].' - '.$venue['Close_time'], 'icon'=>'fa-clock'],
                                ['label'=>'Price/hr','value'=>'₭'.number_format(preg_replace('/[^0-9]/','',$venue['Price_per_hour'])), 'icon'=>'fa-money-bill'],
                                ['label'=>'Status',  'value'=>$venue['VN_Status'],     'icon'=>'fa-circle'],
                            ];
                            foreach ($vfields as $f):
                            ?>
                                <div class="flex items-center gap-3 py-2 border-b border-gray-50 last:border-0">
                                    <i class="fas <?= $f['icon'] ?> text-gray-400 w-4"></i>
                                    <span class="text-gray-500 w-24 flex-shrink-0"><?= $f['label'] ?></span>
                                    <span class="font-semibold text-gray-800"><?= htmlspecialchars($f['value']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a href="/Badminton_court_Booking/owner/manage_court/index.php"
                           class="mt-4 block text-center bg-blue-50 hover:bg-blue-100 text-blue-600 font-semibold py-2 rounded-xl text-sm transition">
                            <i class="fas fa-edit mr-1"></i>Edit Venue
                        </a>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-store text-4xl text-gray-200 mb-3 block"></i>
                            <p class="text-gray-400 text-sm mb-3">No venue set up yet</p>
                            <a href="/Badminton_court_Booking/owner/manage_court/index.php"
                               class="inline-block bg-green-600 text-white px-4 py-2 rounded-xl text-sm font-semibold hover:bg-green-700 transition">
                                <i class="fas fa-plus mr-1"></i>Set Up Venue
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>
</div>
</body>
</html>