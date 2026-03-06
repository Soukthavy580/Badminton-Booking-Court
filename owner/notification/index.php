<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id = $_SESSION['ca_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM owner_notification WHERE CA_ID=? ORDER BY created_at DESC");
    $stmt->execute([$ca_id]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) { $notifications = []; }

$type_config = [
    'package'       => ['icon'=>'fa-box',   'color'=>'purple', 'label'=>'Package',       'link'=>'/Badminton_court_Booking/owner/package_rental/index.php', 'action'=>'Repurchase Package'],
    'advertisement' => ['icon'=>'fa-ad',    'color'=>'blue',   'label'=>'Advertisement', 'link'=>'/Badminton_court_Booking/owner/advertisement/index.php',  'action'=>'Resubmit Ad'],
    'venue'         => ['icon'=>'fa-store', 'color'=>'green',  'label'=>'Venue',         'link'=>'/Badminton_court_Booking/owner/manage_court/index.php',   'action'=>'Fix & Resubmit'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - CourtBook Owner</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notif-card { transition: all 0.3s ease; }
        .notif-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        @keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:0.3} }
        .pulse { animation: pulse-dot 1.8s infinite; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">Notifications</h1>
                    <p class="text-sm text-gray-500">Admin feedback — fix the issue to clear each one</p>
                </div>
                <?php if (!empty($notifications)): ?>
                    <span class="bg-red-500 text-white font-bold px-4 py-2 rounded-full text-sm">
                        <?= count($notifications) ?> pending
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-1 p-6 max-w-3xl mx-auto w-full">

            <?php if (!empty($notifications)): ?>
                <div class="space-y-4">
                    <?php foreach ($notifications as $n):
                        $cfg    = $type_config[$n['type']] ?? $type_config['package'];
                        $flagged = !empty($n['flagged_fields']) ? json_decode($n['flagged_fields'], true) : [];
                    ?>
                        <div class="notif-card bg-red-50 border-l-4 border-red-400 rounded-2xl p-5 shadow-sm relative">
                            <span class="absolute top-5 right-5 w-3 h-3 bg-red-500 rounded-full pulse"></span>
                            <div class="flex items-start gap-4">
                                <div class="bg-red-100 p-3 rounded-full flex-shrink-0">
                                    <i class="fas fa-times-circle text-red-500 text-xl"></i>
                                </div>
                                <div class="flex-1 min-w-0 pr-4">
                                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                                        <h3 class="font-bold text-gray-800"><?= htmlspecialchars($n['title']) ?></h3>
                                        <span class="bg-<?= $cfg['color'] ?>-100 text-<?= $cfg['color'] ?>-700 text-xs font-bold px-2 py-0.5 rounded-full">
                                            <i class="fas <?= $cfg['icon'] ?> mr-1"></i><?= $cfg['label'] ?>
                                        </span>
                                    </div>
                                    <div class="bg-white border border-red-200 rounded-xl p-4 mb-3">
                                        <p class="text-xs font-bold text-red-500 mb-1.5">
                                            <i class="fas fa-user-shield mr-1"></i>Reason from Admin:
                                        </p>
                                        <p class="text-sm text-gray-700 leading-relaxed">
                                            <?= nl2br(htmlspecialchars($n['message'])) ?>
                                        </p>
                                    </div>
                                    <?php if (!empty($flagged)): ?>
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
                                        <i class="fas fa-clock mr-1"></i>Received <?= date('M d, Y \a\t g:i A', strtotime($n['created_at'])) ?>
                                    </p>
                                    <a href="<?= $cfg['link'] ?>"
                                       class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold px-5 py-2.5 rounded-xl transition shadow-sm">
                                        <i class="fas fa-edit"></i><?= $cfg['action'] ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-double text-green-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-700 mb-2">All Clear!</h3>
                    <p class="text-gray-400 text-sm">No pending notifications from admin.</p>
                    <p class="text-gray-300 text-xs mt-1">If admin rejects something, the reason will appear here until you fix it.</p>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>
</body>
</html>