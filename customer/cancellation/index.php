<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'customer') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$c_id    = $_SESSION['c_id'];
$book_id = intval($_GET['id'] ?? 0);

if (!$book_id) {
    header('Location: /Badminton_court_Booking/customer/booking_court/my_booking.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT b.*, bd.Start_time, v.VN_Name
        FROM booking b
        INNER JOIN booking_detail bd ON b.Book_ID = bd.Book_ID
        INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
        INNER JOIN Venue_data v ON c.VN_ID = v.VN_ID
        WHERE b.Book_ID = ? AND b.C_ID = ?
        LIMIT 1
    ");
    $stmt->execute([$book_id, $c_id]);
    $booking = $stmt->fetch();
} catch (PDOException $e) { $booking = null; }

if (!$booking) {
    header('Location: /Badminton_court_Booking/customer/booking_court/my_booking.php');
    exit;
}

// Block only: already Cancelled, or booking time has already passed
if (in_array($booking['Status_booking'], ['Cancelled', 'Completed', 'No_Show'])) {
    header('Location: /Badminton_court_Booking/customer/booking_court/my_booking.php');
    exit;
}

// Block past bookings (except Unpaid — those can always be cancelled since no real slot is blocked)
if ($booking['Status_booking'] !== 'Unpaid' && strtotime($booking['Start_time']) < time()) {
    $_SESSION['booking_error'] = 'ບໍ່ສາມາດຍົກເລີກການຈອງທີ່ຜ່ານມາໄດ້.';
    header('Location: /Badminton_court_Booking/customer/booking_court/my_booking.php');
    exit;
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comment = trim($_POST['comment'] ?? '');
    if (empty($comment)) {
        $error = 'ກະລຸນາໃສ່ເຫດຜົນໃນການຍົກເລີກ.';
    } else {
        try {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE booking SET Status_booking = 'Cancelled' WHERE Book_ID = ? AND C_ID = ?")
                ->execute([$book_id, $c_id]);

            $pdo->prepare("INSERT INTO cancel_booking (Comment, Book_ID) VALUES (?, ?)")
                ->execute([$comment, $book_id]);

            $pdo->commit();
            $success = true;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Cancellation error: " . $e->getMessage());
            $error = 'ການຍົກເລີກລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ຍົກເລີກການຈອງ - ລະບົບຈອງເດີ່ນ</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>

    <div class="max-w-lg mx-auto px-4 py-8">

        <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php"
           class="inline-flex items-center gap-2 text-gray-600 hover:text-blue-600 mb-6 font-medium transition">
            <i class="fas fa-arrow-left"></i> ກັບໄປລາຍການຈອງ
        </a>

        <?php if ($success): ?>
            <div class="bg-white rounded-2xl shadow-sm p-10 text-center">
                <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-times-circle text-red-500 text-4xl"></i>
                </div>
                <h2 class="text-2xl font-extrabold text-gray-800 mb-2">ຍົກເລີກການຈອງສຳເລັດ</h2>
                <p class="text-gray-500 mb-6">ການຈອງ #<?= $book_id ?> ຂອງທ່ານໄດ້ຖືກຍົກເລີກແລ້ວ.</p>
                <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php"
                   class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-bold px-8 py-3 rounded-xl transition">
                    ກັບໄປລາຍການຈອງ
                </a>
            </div>

        <?php else: ?>
            <div class="bg-white rounded-2xl shadow-sm p-8">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i>
                    </div>
                    <h2 class="text-2xl font-extrabold text-gray-800">ຍົກເລີກການຈອງ</h2>
                    <p class="text-gray-500 text-sm mt-1">ການຈອງ #<?= $book_id ?> · <?= htmlspecialchars($booking['VN_Name']) ?></p>
                </div>

                <div class="bg-red-50 border-2 border-red-300 rounded-xl p-4 mb-6 text-sm text-red-700 space-y-2">
                    <p class="font-bold flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle"></i>ກ່ອນຍົກເລີກ ກະລຸນາອ່ານ:
                    </p>
                    <p><i class="fas fa-times-circle mr-1 text-red-500"></i>ເງິນມັດຈຳ <strong>30%</strong> ທີ່ທ່ານຊຳລະໄວ້ <strong>ຈະບໍ່ຖືກຄືນໄດ້</strong>.</p>
                    <p><i class="fas fa-times-circle mr-1 text-red-500"></i>ເມື່ອຍົກເລີກແລ້ວ ບໍ່ສາມາດຍ້ອນກັບໄດ້.</p>
                    <p><i class="fas fa-check-circle mr-1 text-green-500"></i>ສລັອດຂອງທ່ານຈະຖືກປ່ອຍໃຫ້ຜູ້ອື່ນຈອງໄດ້.</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-4 p-4 bg-red-50 border border-red-300 text-red-700 rounded-xl text-sm">
                        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-6">
                        <label class="block text-gray-700 font-bold mb-2 text-sm">
                            ເຫດຜົນໃນການຍົກເລີກ <span class="text-red-500">*</span>
                        </label>
                        <textarea name="comment" rows="4" required
                                  placeholder="ກະລຸນາບອກເຫດຜົນທີ່ທ່ານຍົກເລີກ..."
                                  class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-gray-700 focus:outline-none focus:border-red-400 transition resize-none"></textarea>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit"
                                class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl transition">
                            <i class="fas fa-times-circle mr-2"></i>ຢືນຢັນການຍົກເລີກ
                        </button>
                        <a href="/Badminton_court_Booking/customer/booking_court/my_booking.php"
                           class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition">
                            ຮັກສາການຈອງ
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>