<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

date_default_timezone_set('Asia/Vientiane');

$ca_id = $_SESSION['ca_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM Venue_data WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $venue = $stmt->fetch();
} catch (PDOException $e) {
    $venue = null;
}

if (!$venue) {
    header('Location: /Badminton_court_Booking/owner/manage_court/index.php');
    exit;
}

$vn_id   = $venue['VN_ID'];
$filter  = $_GET['filter'] ?? 'schedule';
$error   = '';
$success = '';

// ── POST handler ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $book_id = intval($_POST['book_id'] ?? 0);
    $action  = $_POST['action'] ?? '';

    $allowed = ['approve', 'reject', 'complete', 'no_show'];
    if ($book_id && in_array($action, $allowed)) {
        try {
            // Verify this booking belongs to this owner's venue
            $stmt = $pdo->prepare("
                SELECT b.Book_ID, b.Status_booking FROM booking b
                INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
                INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
                WHERE b.Book_ID = ? AND c.VN_ID = ? LIMIT 1
            ");
            $stmt->execute([$book_id, $vn_id]);
            $brow = $stmt->fetch();

            if ($brow) {
                $new_status = match ($action) {
                    'approve'  => 'Confirmed',
                    'reject'   => 'Cancelled',
                    'complete' => 'Completed',
                    'no_show'  => 'No_Show',
                };

                // Guard: only allow valid transitions
                $current = $brow['Status_booking'];
                $valid = match ($action) {
                    'approve'  => $current === 'Pending',
                    'reject'   => $current === 'Pending',
                    'complete' => $current === 'Confirmed',
                    'no_show'  => $current === 'Confirmed',
                };

                if ($valid) {
                    $pdo->prepare("UPDATE booking SET Status_booking = ? WHERE Book_ID = ?")
                        ->execute([$new_status, $book_id]);

                    // FIX: Log approval/rejection in approve_booking table
                    if (in_array($action, ['approve', 'reject'])) {
                        try {
                            $pdo->prepare("
                INSERT INTO approve_booking (Book_ID, CA_ID)
                VALUES (?, ?)
            ")->execute([$book_id, $ca_id]);
                        } catch (PDOException $e) {
                        }
                    }

                    $msg = match ($action) {
                        'approve'  => 'ຢືນຢັນການຈອງ #' . $book_id . ' ສຳເລັດ!',
                        'reject'   => 'ປະຕິເສດການຈອງ #' . $book_id . ' ແລ້ວ.',
                        'complete' => 'ທຳເຄື່ອງໝາຍ #' . $book_id . ' ເປັນ "ສຳເລັດ" — ລູກຄ້າຈ່າຍຄົບ 100% ແລ້ວ.',
                        'no_show'  => 'ທຳເຄື່ອງໝາຍ #' . $book_id . ' ເປັນ "ບໍ່ໄດ້ມາ".',
                    };
                    header('Location: ?filter=' . urlencode($filter) . '&msg=' . urlencode($msg));
                    exit;
                } else {
                    $error = 'ບໍ່ສາມາດດຳເນີນການໄດ້ — ສະຖານະປັດຈຸບັນບໍ່ອະນຸຍາດ.';
                }
            } else {
                $error = 'ບໍ່ພົບການຈອງ ຫຼື ບໍ່ມີສິດເຂົ້າເຖິງ.';
            }
        } catch (PDOException $e) {
            $error = 'ລົ້ມເຫລວ: ' . $e->getMessage();
        }
    }
}

if (!empty($_GET['msg'])) $success = $_GET['msg'];

function count_bookings($pdo, $vn_id, $filter)
{
    try {
        $where = match ($filter) {
            'pending'   => "AND b.Status_booking = 'Pending' AND b.Slip_payment IS NOT NULL AND b.Slip_payment != ''",
            'confirmed' => "AND b.Status_booking = 'Confirmed'",
            'completed' => "AND b.Status_booking = 'Completed'",
            'no_show'   => "AND b.Status_booking = 'No_Show'",
            'cancelled' => "AND b.Status_booking = 'Cancelled'",
            default     => ''
        };
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT b.Book_ID)
            FROM booking b
            INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
            INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
            WHERE c.VN_ID = ? $where
        ");
        $stmt->execute([$vn_id]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function get_bookings($pdo, $vn_id, $filter)
{
    try {
        $sql = "
            SELECT
                b.Book_ID, b.Booking_date, b.Status_booking, b.Slip_payment,
                bd.Start_time, bd.End_time,
                c.COURT_ID, c.COURT_Name, c.Court_Status,
                c.Open_time AS court_open, c.Close_time AS court_close,
                cu.C_ID, cu.Name AS customer_name,
                cu.Phone AS customer_phone, cu.Email AS customer_email,
                v.Price_per_hour
            FROM booking b
            INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
            INNER JOIN Court_data c      ON bd.COURT_ID = c.COURT_ID
            INNER JOIN Venue_data v      ON c.VN_ID = v.VN_ID
            INNER JOIN customer cu       ON b.C_ID = cu.C_ID
            WHERE c.VN_ID = ?
        ";
        $params = [$vn_id];
        if ($filter === 'pending')        $sql .= " AND b.Status_booking = 'Pending' AND b.Slip_payment IS NOT NULL AND b.Slip_payment != ''";
        elseif ($filter === 'confirmed')  $sql .= " AND b.Status_booking = 'Confirmed'";
        elseif ($filter === 'completed')  $sql .= " AND b.Status_booking = 'Completed'";
        elseif ($filter === 'no_show')    $sql .= " AND b.Status_booking = 'No_Show'";
        elseif ($filter === 'cancelled')  $sql .= " AND b.Status_booking = 'Cancelled'";
        $sql .= " ORDER BY b.Booking_date DESC, bd.Start_time ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $grouped = [];
        foreach ($rows as $b) {
            $id = $b['Book_ID'];
            if (!isset($grouped[$id])) {
                $grouped[$id] = $b;
                $grouped[$id]['slots'] = [];
            }
            $grouped[$id]['slots'][] = [
                'court'        => $b['COURT_Name'],
                'start'        => $b['Start_time'],
                'end'          => $b['End_time'],
                'court_open'   => $b['court_open']   ?? null,
                'court_close'  => $b['court_close']  ?? null,
                'court_status' => $b['Court_Status'] ?? 'Active',
            ];
        }
        return array_values($grouped);
    } catch (PDOException $e) {
        return [];
    }
}

function calc_total($slots, $price_per_hour)
{
    $price = floatval(preg_replace('/[^0-9.]/', '', $price_per_hour));
    $total = 0;
    foreach ($slots as $s) {
        if (!strtotime($s['start']) || !strtotime($s['end'])) continue;
        $hours = (strtotime($s['end']) - strtotime($s['start'])) / 3600;
        $total += $hours * $price;
    }
    return $total;
}

$bookings = get_bookings($pdo, $vn_id, $filter);
$counts = [
    'pending'   => count_bookings($pdo, $vn_id, 'pending'),
    'confirmed' => count_bookings($pdo, $vn_id, 'confirmed'),
    'completed' => count_bookings($pdo, $vn_id, 'completed'),
    'no_show'   => count_bookings($pdo, $vn_id, 'no_show'),
    'cancelled' => count_bookings($pdo, $vn_id, 'cancelled'),
];

try {
    $stmt = $pdo->prepare("SELECT * FROM Court_data WHERE VN_ID = ? ORDER BY COURT_Name");
    $stmt->execute([$vn_id]);
    $all_courts = $stmt->fetchAll();
} catch (PDOException $e) {
    $all_courts = [];
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="lo">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ຈັດການການຈອງ - Badminton Booking Court</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .booking-card {
            transition: all 0.3s ease;
        }

        .booking-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        }

        .slip-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .slip-modal.open {
            display: flex;
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <?php include '../includes/sidebar.php'; ?>

        <div class="flex-1 flex flex-col">
            <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-bold text-gray-800">ຈັດການການຈອງ</h1>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($venue['VN_Name']) ?></p>
                    </div>
                    <?php if ($counts['pending'] > 0): ?>
                        <div class="flex items-center gap-2 bg-red-50 border border-red-200 px-4 py-2 rounded-xl">
                            <i class="fas fa-exclamation-circle text-red-500"></i>
                            <span class="text-red-700 font-bold text-sm"><?= $counts['pending'] ?> ການຈອງລໍຖ້າກວດສອບ</span>
                        </div>
                    <?php endif; ?>
                </div>
            </header>

            <main class="flex-1 p-6">

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

                <!-- Stats Row -->
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                    <?php foreach (
                        [
                            ['label' => 'ຕາຕະລາງ',     'value' => null,                  'icon' => 'fa-calendar-day',  'color' => 'blue',   'filter' => 'schedule'],
                            ['label' => 'ລໍຖ້າ',        'value' => $counts['pending'],   'icon' => 'fa-clock',         'color' => 'yellow', 'filter' => 'pending'],
                            ['label' => 'ຢືນຢັນ',       'value' => $counts['confirmed'], 'icon' => 'fa-check-circle',  'color' => 'green',  'filter' => 'confirmed'],
                            ['label' => 'ສຳເລັດ',       'value' => $counts['completed'], 'icon' => 'fa-trophy',        'color' => 'emerald', 'filter' => 'completed'],
                            ['label' => 'ບໍ່ໄດ້ມາ',    'value' => $counts['no_show'],   'icon' => 'fa-user-slash',    'color' => 'orange', 'filter' => 'no_show'],
                            ['label' => 'ຍົກເລີກ',      'value' => $counts['cancelled'], 'icon' => 'fa-times-circle',  'color' => 'red',    'filter' => 'cancelled'],
                        ] as $sc
                    ): ?>
                        <a href="?filter=<?= $sc['filter'] ?>"
                            class="bg-white rounded-2xl p-4 shadow-sm border-2 <?= $filter === $sc['filter'] ? 'border-' . $sc['color'] . '-400' : 'border-transparent' ?> hover:shadow-md transition block">
                            <div class="flex items-center justify-between mb-2">
                                <div class="bg-<?= $sc['color'] ?>-100 p-2 rounded-xl">
                                    <i class="fas <?= $sc['icon'] ?> text-<?= $sc['color'] ?>-500 text-sm"></i>
                                </div>
                                <?php if ($sc['filter'] === 'pending' && ($sc['value'] ?? 0) > 0): ?>
                                    <span class="bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center font-bold"><?= $sc['value'] ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($sc['value'] !== null): ?>
                                <p class="text-xl font-extrabold text-gray-800"><?= $sc['value'] ?></p>
                            <?php else: ?>
                                <p class="text-base font-extrabold text-gray-800">ເບິ່ງ</p>
                            <?php endif; ?>
                            <p class="text-xs text-gray-500 mt-0.5"><?= $sc['label'] ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- SCHEDULE TAB -->
                <?php if ($filter === 'schedule'): ?>
                    <?php
                    $view_date     = $_GET['view_date'] ?? $today;
                    $view_date_fmt = date('Y-m-d', strtotime($view_date));
                    $booked_slots_view = [];
                    try {
                        $stmt = $pdo->prepare("
                    SELECT bd.COURT_ID,
                           DATE_FORMAT(bd.Start_time, '%H:%i') AS slot_start,
                           DATE_FORMAT(bd.End_time,   '%H:%i') AS slot_end,
                           b.Book_ID, b.Status_booking, cu.Name AS customer_name
                    FROM booking_detail bd
                    INNER JOIN booking b   ON bd.Book_ID = b.Book_ID
                    INNER JOIN customer cu ON b.C_ID = cu.C_ID
                    WHERE b.Status_booking IN ('Confirmed','Pending')
                    AND DATE(bd.Start_time) = ?
                    AND bd.COURT_ID IN (SELECT COURT_ID FROM Court_data WHERE VN_ID = ?)
                ");
                        $stmt->execute([$view_date_fmt, $vn_id]);
                        foreach ($stmt->fetchAll() as $row) {
                            $key = $row['COURT_ID'] . '_' . $row['slot_start'];
                            $booked_slots_view[$key] = $row;
                        }
                    } catch (PDOException $e) {
                    }
                    ?>
                    <?php if (!empty($all_courts)): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-5 mb-6">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                                <h2 class="font-bold text-gray-800 flex items-center gap-2">
                                    <i class="fas fa-calendar-day text-blue-500"></i>ຕາຕະລາງການຈອງ
                                </h2>
                                <div class="flex items-center gap-3 flex-wrap">
                                    <div class="hidden md:flex items-center gap-3 text-xs text-gray-500 flex-wrap">
                                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-yellow-100 border border-yellow-300 inline-block"></span>ລໍຖ້າ</span>
                                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-red-100 border border-red-300 inline-block"></span>ຢືນຢັນ</span>
                                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-emerald-100 border border-emerald-300 inline-block"></span>ສຳເລັດ</span>
                                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-orange-100 border border-orange-300 inline-block"></span>ບໍ່ໄດ້ມາ</span>
                                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-green-50 border border-green-200 inline-block"></span>ຫວ່າງ</span>
                                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-gray-100 inline-block"></span>ຜ່ານແລ້ວ</span>
                                    </div>
                                    <form method="GET" class="flex items-center gap-2">
                                        <input type="hidden" name="filter" value="schedule">
                                        <input type="date" name="view_date" value="<?= htmlspecialchars($view_date_fmt) ?>"
                                            class="border-2 border-gray-200 rounded-xl px-3 py-1.5 text-sm focus:outline-none focus:border-blue-400 transition"
                                            onchange="this.form.submit()">
                                    </form>
                                </div>
                            </div>
                            <?php foreach ($all_courts as $court):
                                $cs       = $court['Court_Status'] ?? 'Active';
                                $has_sched = !empty($court['Open_time']) && !empty($court['Close_time']);
                                $s_color  = match ($cs) {
                                    'Active' => 'green',
                                    'Inactive' => 'gray',
                                    'Maintaining' => 'yellow',
                                    default => 'gray'
                                };
                                $s_icon   = match ($cs) {
                                    'Active' => 'fa-check-circle',
                                    'Inactive' => 'fa-eye-slash',
                                    'Maintaining' => 'fa-tools',
                                    default => 'fa-circle'
                                };
                                $s_label  = match ($cs) {
                                    'Active' => 'ໃຊ້ງານໄດ້',
                                    'Inactive' => 'ບໍ່ໃຊ້ງານ',
                                    'Maintaining' => 'ກຳລັງສ້ອມແປງ',
                                    default => $cs
                                };
                            ?>
                                <div class="mb-7 last:mb-0">
                                    <div class="flex items-center gap-3 mb-3">
                                        <div class="bg-<?= $s_color ?>-100 text-<?= $s_color ?>-700 font-extrabold w-9 h-9 rounded-xl flex items-center justify-center text-sm flex-shrink-0">
                                            <?= strtoupper(substr($court['COURT_Name'], 0, 1)) ?>
                                        </div>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-bold text-gray-800"><?= htmlspecialchars($court['COURT_Name']) ?></span>
                                            <?php if ($has_sched): ?>
                                                <span class="text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">
                                                    <i class="fas fa-clock text-blue-400 mr-1"></i>
                                                    <?= date('H:i', strtotime($court['Open_time'])) ?> – <?= date('H:i', strtotime($court['Close_time'])) ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-<?= $s_color ?>-100 text-<?= $s_color ?>-700">
                                                <i class="fas <?= $s_icon ?> text-xs mr-0.5"></i><?= $s_label ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if (!$has_sched): ?>
                                        <div class="flex items-center gap-2 bg-orange-50 border border-orange-200 rounded-xl px-4 py-3 text-sm text-orange-600">
                                            <i class="fas fa-exclamation-triangle"></i>ຍັງບໍ່ໄດ້ຕັ້ງເວລາ.
                                            <a href="/Badminton_court_Booking/owner/manage_court/index.php?tab=courts" class="underline text-blue-500 ml-1">ຕັ້ງດຽວນີ້ →</a>
                                        </div>
                                    <?php elseif ($cs !== 'Active'): ?>
                                        <div class="bg-<?= $s_color ?>-50 border border-<?= $s_color ?>-200 rounded-xl px-4 py-3 text-sm text-<?= $s_color ?>-600">
                                            <i class="fas <?= $s_icon ?> mr-1"></i>
                                            <?= $cs === 'Maintaining' ? 'ກຳລັງສ້ອມແປງ.' : 'ບໍ່ໃຊ້ງານ.' ?>
                                        </div>
                                    <?php else: ?>
                                        <?php
                                        $now = time();
                                        $cur = strtotime($view_date_fmt . ' ' . $court['Open_time']);
                                        $end = strtotime($view_date_fmt . ' ' . $court['Close_time']);
                                        ?>
                                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                                            <?php while ($cur < $end):
                                                $next    = $cur + 3600;
                                                $skey    = date('H:i', $cur);
                                                $bkey    = $court['COURT_ID'] . '_' . $skey;
                                                $is_past = $next <= $now && $view_date_fmt === $today;
                                                $brow    = $booked_slots_view[$bkey] ?? null;
                                                $bstatus = $brow['Status_booking'] ?? null;

                                                if ($bstatus === 'Confirmed') {
                                                    $cc = 'bg-red-50 border-2 border-red-300';
                                                    $tc = 'text-red-700';
                                                    $lbl = 'ຢືນຢັນ';
                                                    $sub = htmlspecialchars($brow['customer_name']);
                                                    $ico = 'fa-check-circle text-red-400';
                                                } elseif ($bstatus === 'Pending') {
                                                    $cc = 'bg-yellow-50 border-2 border-yellow-300';
                                                    $tc = 'text-yellow-700';
                                                    $lbl = 'ລໍຖ້າ';
                                                    $sub = htmlspecialchars($brow['customer_name']);
                                                    $ico = 'fa-clock text-yellow-400';
                                                } elseif ($bstatus === 'Completed') {
                                                    $cc = 'bg-emerald-50 border-2 border-emerald-300';
                                                    $tc = 'text-emerald-700';
                                                    $lbl = 'ສຳເລັດ';
                                                    $sub = htmlspecialchars($brow['customer_name']);
                                                    $ico = 'fa-trophy text-emerald-400';
                                                } elseif ($bstatus === 'No_Show') {
                                                    $cc = 'bg-orange-50 border-2 border-orange-300';
                                                    $tc = 'text-orange-700';
                                                    $lbl = 'ບໍ່ໄດ້ມາ';
                                                    $sub = htmlspecialchars($brow['customer_name']);
                                                    $ico = 'fa-user-slash text-orange-400';
                                                } elseif ($is_past) {
                                                    $cc = 'bg-gray-50 border border-gray-200';
                                                    $tc = 'text-gray-400';
                                                    $lbl = 'ຜ່ານແລ້ວ';
                                                    $sub = '';
                                                    $ico = '';
                                                } else {
                                                    $cc = 'bg-green-50 border border-green-200';
                                                    $tc = 'text-green-700';
                                                    $lbl = 'ຫວ່າງ';
                                                    $sub = '';
                                                    $ico = '';
                                                }
                                            ?>
                                                <div class="<?= $cc ?> <?= $tc ?> rounded-xl px-3 py-2.5 text-center">
                                                    <p class="text-sm font-bold mb-0.5"><?= date('H:i', $cur) ?> – <?= date('H:i', $next) ?></p>
                                                    <p class="text-xs font-semibold flex items-center justify-center gap-1">
                                                        <?php if ($ico): ?><i class="fas <?= $ico ?> text-xs"></i><?php endif; ?>
                                                        <?= $lbl ?>
                                                    </p>
                                                    <?php if ($sub): ?><p class="text-xs mt-0.5 truncate opacity-70"><?= $sub ?></p><?php endif; ?>
                                                </div>
                                            <?php $cur = $next;
                                            endwhile; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- BOOKINGS LIST -->
                <?php if ($filter !== 'schedule' && !empty($bookings)): ?>
                    <div class="space-y-4">
                        <?php foreach ($bookings as $booking):
                            $total     = calc_total($booking['slots'], $booking['Price_per_hour']);
                            $deposit   = round($total * 0.30);
                            $remaining = round($total * 0.70);
                            $first_start = $booking['slots'][0]['start'] ?? '';
                            $is_past   = $first_start && strtotime($first_start) < time();
                            $slip_url  = !empty($booking['Slip_payment'])
                                ? '/Badminton_court_Booking/assets/images/slips/' . basename($booking['Slip_payment'])
                                : '';

                            $status_cfg = match ($booking['Status_booking']) {
                                'Confirmed'  => ['border' => 'border-green-400',   'badge_bg' => 'bg-green-100',   'badge_text' => 'text-green-700',   'icon' => 'fa-check-circle'],
                                'Completed'  => ['border' => 'border-emerald-400', 'badge_bg' => 'bg-emerald-100', 'badge_text' => 'text-emerald-700', 'icon' => 'fa-trophy'],
                                'No_Show'    => ['border' => 'border-orange-400',  'badge_bg' => 'bg-orange-100',  'badge_text' => 'text-orange-700',  'icon' => 'fa-user-slash'],
                                'Cancelled'  => ['border' => 'border-red-400',     'badge_bg' => 'bg-red-100',     'badge_text' => 'text-red-700',     'icon' => 'fa-times-circle'],
                                default      => ['border' => 'border-yellow-400',  'badge_bg' => 'bg-yellow-100',  'badge_text' => 'text-yellow-700',  'icon' => 'fa-clock'],
                            };
                            $status_label = match ($booking['Status_booking']) {
                                'Confirmed'  => 'ຢືນຢັນແລ້ວ',
                                'Completed'  => 'ສຳເລັດ — ຈ່າຍຄົບ',
                                'No_Show'    => 'ບໍ່ໄດ້ມາ',
                                'Cancelled'  => 'ຍົກເລີກ',
                                default      => 'ລໍຖ້າ',
                            };
                        ?>
                            <div class="booking-card bg-white rounded-2xl shadow-sm border-l-4 <?= $status_cfg['border'] ?> overflow-hidden">
                                <div class="p-5">
                                    <!-- Customer + status -->
                                    <div class="flex flex-col md:flex-row justify-between items-start gap-4 mb-4">
                                        <div class="flex items-start gap-3">
                                            <div class="w-12 h-12 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                                                <?= strtoupper(substr($booking['customer_name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <h3 class="font-bold text-gray-800"><?= htmlspecialchars($booking['customer_name']) ?></h3>
                                                <p class="text-sm text-gray-500"><i class="fas fa-phone mr-1 text-green-400"></i><?= htmlspecialchars($booking['customer_phone']) ?></p>
                                                <p class="text-sm text-gray-500"><i class="fas fa-envelope mr-1 text-blue-400"></i><?= htmlspecialchars($booking['customer_email']) ?></p>
                                            </div>
                                        </div>
                                        <div class="flex flex-col items-end gap-1">
                                            <span class="<?= $status_cfg['badge_bg'] ?> <?= $status_cfg['badge_text'] ?> text-xs font-bold px-3 py-1 rounded-full">
                                                <i class="fas <?= $status_cfg['icon'] ?> mr-1"></i><?= $status_label ?>
                                            </span>
                                            <span class="text-xs text-gray-400">ການຈອງ #<?= $booking['Book_ID'] ?></span>
                                            <span class="text-xs text-gray-400"><?= date('d/m/Y g:i A', strtotime($booking['Booking_date'])) ?></span>
                                        </div>
                                    </div>

                                    <!-- Slots -->
                                    <div class="space-y-2 mb-4">
                                        <?php foreach ($booking['slots'] as $slot):
                                            $slot_color = match ($slot['court_status'] ?? 'Active') {
                                                'Active' => 'green',
                                                'Maintaining' => 'yellow',
                                                default => 'gray'
                                            };
                                        ?>
                                            <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-gray-100 flex items-center gap-3 flex-wrap">
                                                <i class="fas fa-table-tennis text-<?= $slot_color ?>-500 text-xs flex-shrink-0"></i>
                                                <span class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($slot['court']) ?></span>
                                                <span class="ml-auto text-xs text-gray-500">
                                                    <?php if (strtotime($slot['start'])): ?>
                                                        <?= date('d/m', strtotime($slot['start'])) ?> &nbsp;·&nbsp;
                                                        <span class="font-semibold text-gray-700">
                                                            <?= date('H:i', strtotime($slot['start'])) ?> – <?= date('H:i', strtotime($slot['end'])) ?>
                                                        </span>
                                                        <?php else: ?>-<?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Price summary -->
                                    <div class="grid grid-cols-3 gap-3 mb-4 bg-gray-50 rounded-xl p-3">
                                        <div class="text-center">
                                            <p class="text-xs text-gray-400">ລາຄາລວມ</p>
                                            <p class="font-bold text-gray-700 text-sm">₭<?= number_format($total, 0) ?></p>
                                        </div>
                                        <div class="text-center border-x border-gray-200">
                                            <p class="text-xs text-gray-400">ມັດຈຳ 30%</p>
                                            <p class="font-bold text-green-600 text-sm">₭<?= number_format($deposit, 0) ?></p>
                                            <p class="text-xs text-green-500">ຈ່າຍອອນລາຍ</p>
                                        </div>
                                        <div class="text-center">
                                            <p class="text-xs text-gray-400">ທີ່ສະຖານທີ່ 70%</p>
                                            <p class="font-bold text-orange-500 text-sm">₭<?= number_format($remaining, 0) ?></p>
                                            <?php if ($booking['Status_booking'] === 'Completed'): ?>
                                                <p class="text-xs text-emerald-600 font-semibold">✓ ຈ່າຍແລ້ວ</p>
                                            <?php elseif ($booking['Status_booking'] === 'No_Show'): ?>
                                                <p class="text-xs text-orange-500">ບໍ່ໄດ້ຈ່າຍ</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex flex-wrap gap-2 pt-3 border-t border-gray-100">
                                        <?php if ($slip_url): ?>
                                            <button onclick="viewSlip('<?= htmlspecialchars($slip_url) ?>', <?= $booking['Book_ID'] ?>, '<?= $booking['Status_booking'] ?>')"
                                                class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-2 rounded-xl text-sm font-semibold transition">
                                                <i class="fas fa-receipt mr-1"></i>ຫຼັກຖານການໂອນ
                                            </button>
                                        <?php else: ?>
                                            <span class="bg-gray-100 text-gray-400 px-3 py-2 rounded-xl text-sm">
                                                <i class="fas fa-clock mr-1"></i>ຍັງບໍ່ມີຫຼັກຖານການໂອນ
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($booking['Status_booking'] === 'Pending' && $slip_url): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="book_id" value="<?= $booking['Book_ID'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl text-sm font-bold transition shadow">
                                                    <i class="fas fa-check mr-1"></i>ອະນຸມັດ
                                                </button>
                                            </form>
                                            <form method="POST" class="inline" onsubmit="return confirm('ປະຕິເສດການຈອງ #<?= $booking['Book_ID'] ?>?')">
                                                <input type="hidden" name="book_id" value="<?= $booking['Book_ID'] ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2 rounded-xl text-sm font-bold transition border border-red-200">
                                                    <i class="fas fa-times mr-1"></i>ປະຕິເສດ
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($booking['Status_booking'] === 'Confirmed'): ?>
                                            <!-- Completed: customer came and paid 70% -->
                                            <form method="POST" class="inline" onsubmit="return confirm('ຢືນຢັນລູກຄ້າໄດ້ມາ ແລະ ຈ່າຍ 70% ຄົບແລ້ວ?')">
                                                <input type="hidden" name="book_id" value="<?= $booking['Book_ID'] ?>">
                                                <input type="hidden" name="action" value="complete">
                                                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm font-bold transition shadow flex items-center gap-1">
                                                    <i class="fas fa-trophy"></i>ມາ + ຈ່າຍຄົບ
                                                </button>
                                            </form>
                                            <!-- No show: customer didn't come -->
                                            <form method="POST" class="inline" id="noShowForm_<?= $booking['Book_ID'] ?>">
                                                <input type="hidden" name="book_id" value="<?= $booking['Book_ID'] ?>">
                                                <input type="hidden" name="action" value="no_show">
                                                <button type="button" onclick="confirmNoShow(<?= $booking['Book_ID'] ?>)" class="bg-orange-50 hover:bg-orange-100 text-orange-700 px-4 py-2 rounded-xl text-sm font-bold transition border border-orange-200 flex items-center gap-1">
                                                    <i class="fas fa-user-slash"></i>ບໍ່ໄດ້ມາ
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($booking['Status_booking'] === 'Completed'): ?>
                                            <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 px-3 py-2 rounded-xl text-sm font-semibold flex items-center gap-1">
                                                <i class="fas fa-check-double"></i>ຈ່າຍຄົບ 100% ແລ້ວ
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($booking['Status_booking'] === 'No_Show'): ?>
                                            <span class="bg-orange-50 text-orange-600 border border-orange-200 px-3 py-2 rounded-xl text-sm font-semibold flex items-center gap-1">
                                                <i class="fas fa-user-slash"></i>ເງິນມັດຈຳ 30% ຕົກເປັນຂອງທ່ານ
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($filter !== 'schedule'): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <i class="fas fa-calendar-times text-6xl text-gray-200 mb-4 block"></i>
                        <h3 class="text-xl font-bold text-gray-600 mb-2">
                            <?= match ($filter) {
                                'pending'   => 'ບໍ່ມີໃບຮັບເງິນທີ່ລໍຖ້າກວດສອບ',
                                'confirmed' => 'ບໍ່ມີການຈອງທີ່ຢືນຢັນແລ້ວ',
                                'completed' => 'ບໍ່ມີການຈອງທີ່ສຳເລັດ',
                                'no_show'   => 'ບໍ່ມີການຈອງທີ່ບໍ່ໄດ້ມາ',
                                'cancelled' => 'ບໍ່ມີການຈອງທີ່ຍົກເລີກ',
                                default     => 'ບໍ່ມີການຈອງ'
                            } ?>
                        </h3>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Slip Modal -->
    <div class="slip-modal" id="slipModal">
        <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-lg w-full mx-4 relative">
            <button onclick="closeSlip()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-bold text-gray-800 text-lg mb-4"><i class="fas fa-receipt text-blue-500 mr-2"></i>ຫຼັກຖານການໂອນ — ການຈອງ #<span id="modalBookingId"></span></h3>
            <img id="modalSlipImg" src="" alt="Payment Slip" class="w-full max-h-96 object-contain rounded-xl border border-gray-200">
            <div class="flex gap-3 mt-4" id="modalActions"></div>
        </div>
    </div>

    <script>
        function viewSlip(url, bookId, status) {
            document.getElementById('slipModal').classList.add('open');
            document.getElementById('modalSlipImg').src = url;
            document.getElementById('modalBookingId').textContent = bookId;
            const actions = document.getElementById('modalActions');
            if (status === 'Pending') {
                actions.innerHTML = `
            <form method="POST" class="flex-1">
                <input type="hidden" name="book_id" value="${bookId}">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                    <i class="fas fa-check mr-2"></i>ອະນຸມັດ
                </button>
            </form>
            <form method="POST" class="flex-1" onsubmit="return confirm('ປະຕິເສດ?')">
                <input type="hidden" name="book_id" value="${bookId}">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-600 font-bold py-3 rounded-xl border border-red-200 transition">
                    <i class="fas fa-times mr-2"></i>ປະຕິເສດ
                </button>
            </form>`;
            } else {
                actions.innerHTML = '';
            }
        }

        function confirmNoShow(bookId) {
            if (confirm('ທຳເຄື່ອງໝາຍ #' + bookId + ' ວ່າ "ບໍ່ໄດ້ມາ"?\nລູກຄ້າລ່າຊ້າເກີນ 30 ນາທີ ຫຼື ບໍ່ໄດ້ມາ — ສລັອດຈະຖືກປ່ອຍໃຫ້ຜູ້ອື່ນ ແລະ ເງິນມັດຈຳ 30% ຈະບໍ່ຖືກຄືນ.')) {
                document.getElementById('noShowForm_' + bookId).submit();
            }
        }

        function closeSlip() {
            document.getElementById('slipModal').classList.remove('open');
        }
        document.getElementById('slipModal').addEventListener('click', function(e) {
            if (e.target === this) closeSlip();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeSlip();
        });
    </script>
</body>

</html>