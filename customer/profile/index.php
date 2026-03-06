<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$c_id = $_SESSION['c_id'];

// Fetch latest customer data
try {
    $stmt = $pdo->prepare("SELECT * FROM customer WHERE C_ID = ? LIMIT 1");
    $stmt->execute([$c_id]);
    $customer = $stmt->fetch();
} catch (PDOException $e) {
    $customer = [];
}

if (!$customer) {
    header('Location: /Badminton_court_Booking/auth/logout.php');
    exit;
}

// Booking stats
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN Status_booking = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN Status_booking = 'Pending'   THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN Status_booking = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled
        FROM booking WHERE C_ID = ?
    ");
    $stmt->execute([$c_id]);
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    $stats = ['total'=>0,'confirmed'=>0,'pending'=>0,'cancelled'=>0];
}

// Recent bookings
try {
    $stmt = $pdo->prepare("
        SELECT b.Book_ID, b.Status_booking, bd.Start_time, bd.End_time,
               c.COURT_Name, v.VN_Name
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
        WHERE b.C_ID = ?
        ORDER BY bd.Start_time DESC
        LIMIT 3
    ");
    $stmt->execute([$c_id]);
    $recent = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent = [];
}

$success = $_SESSION['profile_success'] ?? '';
unset($_SESSION['profile_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - CourtBook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>

    <div class="max-w-4xl mx-auto px-4 py-8">

        <?php if ($success): ?>
            <div class="mb-6 p-4 bg-green-50 border border-green-300 text-green-700 rounded-xl flex items-center gap-3">
                <i class="fas fa-check-circle text-xl"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- Profile Header Card -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <div class="flex flex-col md:flex-row items-center md:items-start gap-6">
                <!-- Avatar -->
                <div class="flex-shrink-0">
                    <div class="w-24 h-24 bg-gradient-to-br from-green-400 to-blue-500 rounded-full flex items-center justify-center text-white text-4xl font-bold shadow-lg">
                        <?= strtoupper(substr($customer['Name'], 0, 1)) ?>
                    </div>
                </div>

                <!-- Info -->
                <div class="flex-1 text-center md:text-left">
                    <h1 class="text-2xl font-extrabold text-gray-800">
                        <?= htmlspecialchars($customer['Name'] . ' ' . $customer['Surname']) ?>
                    </h1>
                    <p class="text-gray-500 mt-1">
                        <i class="fas fa-envelope mr-2 text-blue-400"></i><?= htmlspecialchars($customer['Email']) ?>
                    </p>
                    <p class="text-gray-500 mt-1">
                        <i class="fas fa-phone mr-2 text-green-400"></i><?= htmlspecialchars($customer['Phone']) ?>
                    </p>
                    <p class="text-gray-500 mt-1">
                        <i class="fas fa-<?= $customer['Gender'] === 'Male' ? 'mars' : 'venus' ?> mr-2 text-purple-400"></i>
                        <?= htmlspecialchars($customer['Gender']) ?>
                    </p>
                </div>

                <!-- Edit Button -->
                <div class="flex-shrink-0">
                    <a href="/Badminton_court_Booking/customer/profile/edit_profile.php"
                       class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white font-bold px-5 py-2.5 rounded-xl transition shadow">
                        <i class="fas fa-edit"></i> Edit Profile
                    </a>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <?php
            $stat_cards = [
                ['label'=>'Total Bookings', 'value'=>$stats['total'],     'icon'=>'fa-calendar-alt',  'color'=>'blue'],
                ['label'=>'Confirmed',      'value'=>$stats['confirmed'], 'icon'=>'fa-check-circle',  'color'=>'green'],
                ['label'=>'Pending',        'value'=>$stats['pending'],   'icon'=>'fa-clock',         'color'=>'yellow'],
                ['label'=>'Cancelled',      'value'=>$stats['cancelled'], 'icon'=>'fa-times-circle',  'color'=>'red'],
            ];
            foreach ($stat_cards as $card):
            ?>
                <div class="bg-white rounded-2xl shadow-sm p-5 text-center border border-gray-100">
                    <div class="bg-<?= $card['color'] ?>-100 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3">
                        <i class="fas <?= $card['icon'] ?> text-<?= $card['color'] ?>-500 text-lg"></i>
                    </div>
                    <p class="text-2xl font-extrabold text-gray-800"><?= $card['value'] ?? 0 ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?= $card['label'] ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Account Details + Recent Bookings -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- Account Details -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-id-card text-blue-500 mr-2"></i>Account Details
                </h2>
                <div class="space-y-3 text-sm">
                    <?php
                    $fields = [
                        ['label'=>'First Name',  'value'=>$customer['Name'],     'icon'=>'fa-user'],
                        ['label'=>'Surname',     'value'=>$customer['Surname'],  'icon'=>'fa-user'],
                        ['label'=>'Username',    'value'=>$customer['Username'], 'icon'=>'fa-at'],
                        ['label'=>'Email',       'value'=>$customer['Email'],    'icon'=>'fa-envelope'],
                        ['label'=>'Phone',       'value'=>$customer['Phone'],    'icon'=>'fa-phone'],
                        ['label'=>'Gender',      'value'=>$customer['Gender'],   'icon'=>'fa-venus-mars'],
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

            <!-- Recent Bookings -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-history text-green-500 mr-2"></i>Recent Bookings
                    </h2>
                    <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php"
                       class="text-blue-600 hover:text-blue-700 text-sm font-medium">View all</a>
                </div>

                <?php if (!empty($recent)): ?>
                    <div class="space-y-3">
                        <?php foreach ($recent as $rb):
                            $color = match($rb['Status_booking']) {
                                'Confirmed' => 'green',
                                'Pending'   => 'yellow',
                                'Cancelled' => 'red',
                                default     => 'gray'
                            };
                        ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl">
                                <div>
                                    <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($rb['COURT_Name']) ?></p>
                                    <p class="text-xs text-gray-500">
                                        <?= htmlspecialchars($rb['VN_Name']) ?> ·
                                        <?= date('M d, Y', strtotime($rb['Start_time'])) ?>
                                    </p>
                                </div>
                                <span class="bg-<?= $color ?>-100 text-<?= $color ?>-700 text-xs font-bold px-2 py-1 rounded-full">
                                    <?= $rb['Status_booking'] ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-calendar-times text-4xl text-gray-200 mb-3 block"></i>
                        <p class="text-gray-400 text-sm">No bookings yet</p>
                        <a href="/Badminton_court_Booking/customer/booking_court/index.php"
                           class="text-blue-600 text-sm hover:underline mt-1 inline-block">Browse courts</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>