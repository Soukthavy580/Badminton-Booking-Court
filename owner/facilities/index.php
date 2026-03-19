<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: /Badminton_court_Booking/auth/login.php');
    exit;
}

$ca_id = $_SESSION['ca_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM Venue_data WHERE CA_ID = ? LIMIT 1");
    $stmt->execute([$ca_id]);
    $venue = $stmt->fetch();
    $vn_id = $venue['VN_ID'] ?? null;
} catch (PDOException $e) { $venue = null; $vn_id = null; }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $vn_id) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $fac_name = trim($_POST['fac_name'] ?? '');
        if ($fac_name === '') {
            $error = 'ກະລຸນາໃສ່ຊື່ສິ່ງອຳນວຍຄວາມສະດວກ.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM facilities WHERE VN_ID = ? AND Fac_Name = ?");
                $stmt->execute([$vn_id, $fac_name]);
                if ($stmt->fetchColumn() > 0) {
                    $error = 'ສິ່ງອຳນວຍຄວາມສະດວກນີ້ມີຢູ່ແລ້ວ.';
                } else {
                    $pdo->prepare("INSERT INTO facilities (Fac_Name, VN_ID) VALUES (?, ?)")
                        ->execute([$fac_name, $vn_id]);
                    $success = 'ເພີ່ມສິ່ງອຳນວຍຄວາມສະດວກສຳເລັດ.';
                }
            } catch (PDOException $e) {
                $error = 'ລົ້ມເຫລວ: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete') {
        $fac_id = intval($_POST['fac_id'] ?? 0);
        if ($fac_id) {
            try {
                $pdo->prepare("DELETE FROM facilities WHERE Fac_ID = ? AND VN_ID = ?")
                    ->execute([$fac_id, $vn_id]);
                $success = 'ລຶບສິ່ງອຳນວຍຄວາມສະດວກສຳເລັດ.';
            } catch (PDOException $e) { $error = 'ລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.'; }
        }
    }

    if ($action === 'edit') {
        $fac_id   = intval($_POST['fac_id'] ?? 0);
        $fac_name = trim($_POST['fac_name'] ?? '');
        if ($fac_id && $fac_name !== '') {
            try {
                $pdo->prepare("UPDATE facilities SET Fac_Name = ? WHERE Fac_ID = ? AND VN_ID = ?")
                    ->execute([$fac_name, $fac_id, $vn_id]);
                $success = 'ອັບເດດສຳເລັດ.';
            } catch (PDOException $e) { $error = 'ລົ້ມເຫລວ. ກະລຸນາລອງໃໝ່.'; }
        }
    }
}

$facilities = [];
if ($vn_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM facilities WHERE VN_ID = ? ORDER BY Fac_ID ASC");
        $stmt->execute([$vn_id]);
        $facilities = $stmt->fetchAll();
    } catch (PDOException $e) { $facilities = []; }
}

// Lao suggestions
$all_suggestions = [
    'ທີ່ຈອດລົດ', 'ຫ້ອງອາບນ້ຳ', 'ຫ້ອງເຄື່ອງ', 'WiFi', 'ເຄື່ອງປັບອາກາດ',
    'ຮ້ານອາຫານ', 'ນ້ຳດື່ມ', 'ຕູ້ລັອກເຄີ', 'ເຊົ່າອຸປະກອນ', 'ຫ້ອງພະຍາບານ',
    'ທີ່ນັ່ງຊົມ', 'ຫ້ອງນ້ຳ', 'ກ້ອງວົງຈອນປິດ', 'ໄຟສ່ອງສະຫວ່າງ',
];
$existing_names = array_column($facilities, 'Fac_Name');
$suggestions = array_filter($all_suggestions, fn($s) => !in_array($s, $existing_names));

function get_icon($name) {
    $n = strtolower($name);
    if (str_contains($n, 'park') || str_contains($n, 'ຈອດ'))            return 'fa-parking';
    if (str_contains($n, 'shower') || str_contains($n, 'ອາບ'))          return 'fa-shower';
    if (str_contains($n, 'wifi'))                                         return 'fa-wifi';
    if (str_contains($n, 'air') || str_contains($n, 'ປັບອາກາດ'))        return 'fa-snowflake';
    if (str_contains($n, 'cafe') || str_contains($n, 'food') || str_contains($n, 'ຮ້ານ')) return 'fa-utensils';
    if (str_contains($n, 'water') || str_contains($n, 'ນ້ຳ'))           return 'fa-tint';
    if (str_contains($n, 'locker') || str_contains($n, 'ລັອກ'))         return 'fa-lock';
    if (str_contains($n, 'equip') || str_contains($n, 'rental') || str_contains($n, 'ເຊົ່າ')) return 'fa-tools';
    if (str_contains($n, 'first') || str_contains($n, 'medical') || str_contains($n, 'ພະຍາບານ')) return 'fa-first-aid';
    if (str_contains($n, 'seat') || str_contains($n, 'ນັ່ງ'))           return 'fa-chair';
    if (str_contains($n, 'toilet') || str_contains($n, 'restroom') || str_contains($n, 'ຫ້ອງນ້ຳ')) return 'fa-restroom';
    if (str_contains($n, 'camera') || str_contains($n, 'security') || str_contains($n, 'ກ້ອງ')) return 'fa-camera';
    if (str_contains($n, 'light') || str_contains($n, 'ໄຟ'))            return 'fa-lightbulb';
    if (str_contains($n, 'changing') || str_contains($n, 'ເຄື່ອງ'))    return 'fa-door-open';
    return 'fa-concierge-bell';
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ສິ່ງອຳນວຍຄວາມສະດວກ - Badminton Booking Court</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .fac-card { transition: all 0.2s ease; }
        .fac-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.08); }
        .edit-form { display: none; }
        .edit-form.open { display: block; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">ສິ່ງອຳນວຍຄວາມສະດວກ</h1>
                    <p class="text-sm text-gray-500">
                        <?= $venue ? 'ຈັດການສິ່ງອຳນວຍຄວາມສະດວກຂອງ ' . htmlspecialchars($venue['VN_Name']) : 'ກະລຸນາສ້າງສະຖານທີ່ກ່ອນ' ?>
                    </p>
                </div>
                <?php if (!empty($facilities)): ?>
                    <div class="bg-green-50 border border-green-200 px-4 py-2 rounded-xl text-sm">
                        <span class="text-green-700 font-bold"><?= count($facilities) ?></span>
                        <span class="text-green-500 ml-1">ລາຍການ</span>
                    </div>
                <?php endif; ?>
            </div>
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

            <?php if (!$vn_id): ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <i class="fas fa-store text-6xl text-gray-200 mb-4 block"></i>
                    <h3 class="text-xl font-bold text-gray-600 mb-2">ຍັງບໍ່ມີສະຖານທີ່</h3>
                    <p class="text-gray-400 text-sm mb-6">ທ່ານຕ້ອງສ້າງສະຖານທີ່ກ່ອນເພີ່ມສິ່ງອຳນວຍຄວາມສະດວກ.</p>
                    <a href="/Badminton_court_Booking/owner/manage_court/index.php"
                       class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold px-6 py-3 rounded-xl transition">
                        <i class="fas fa-plus mr-2"></i>ສ້າງສະຖານທີ່
                    </a>
                </div>

            <?php else: ?>

                <!-- Add Facility -->
                <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-plus-circle text-green-500 mr-2"></i>ເພີ່ມສິ່ງອຳນວຍຄວາມສະດວກ
                    </h2>
                    <form method="POST" class="flex gap-3">
                        <input type="hidden" name="action" value="add">
                        <input type="text" name="fac_name" id="facNameInput"
                               placeholder="ຕົວຢ່າງ: ທີ່ຈອດລົດ, ຫ້ອງອາບນ້ຳ, WiFi..."
                               maxlength="100"
                               class="flex-1 border-2 border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-green-500 transition"
                               required>
                        <button type="submit"
                                class="bg-green-600 hover:bg-green-700 text-white font-bold px-6 py-3 rounded-xl transition shadow-sm">
                            <i class="fas fa-plus mr-2"></i>ເພີ່ມ
                        </button>
                    </form>

                    <?php if (!empty($suggestions)): ?>
                        <div class="mt-4">
                            <p class="text-xs text-gray-400 mb-2">ເພີ່ມດ່ວນ:</p>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach (array_slice(array_values($suggestions), 0, 8) as $sug): ?>
                                    <button type="button"
                                            onclick="document.getElementById('facNameInput').value='<?= htmlspecialchars($sug, ENT_QUOTES) ?>'"
                                            class="bg-gray-100 hover:bg-green-50 hover:text-green-700 hover:border-green-300 text-gray-600 text-xs px-3 py-1.5 rounded-lg border border-gray-200 transition">
                                        <i class="fas fa-plus text-xs mr-1"></i><?= htmlspecialchars($sug) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Facilities List -->
                <?php if (!empty($facilities)): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h2 class="text-lg font-bold text-gray-800 mb-4">
                            <i class="fas fa-list text-blue-500 mr-2"></i>ສິ່ງອຳນວຍຄວາມສະດວກທີ່ມີ
                            <span class="text-sm font-normal text-gray-400 ml-1">(<?= count($facilities) ?> ລາຍການ)</span>
                        </h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <?php foreach ($facilities as $fac):
                                $icon = get_icon($fac['Fac_Name']);
                            ?>
                                <div class="fac-card bg-gray-50 rounded-xl p-4 border border-gray-100">
                                    <div class="flex items-center justify-between" id="view-<?= $fac['Fac_ID'] ?>">
                                        <div class="flex items-center gap-3">
                                            <div class="bg-green-100 w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0">
                                                <i class="fas <?= $icon ?> text-green-600"></i>
                                            </div>
                                            <span class="font-semibold text-gray-800"><?= htmlspecialchars($fac['Fac_Name']) ?></span>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <button onclick="toggleEdit(<?= $fac['Fac_ID'] ?>)"
                                                    class="text-blue-400 hover:text-blue-600 p-2 rounded-lg hover:bg-blue-50 transition">
                                                <i class="fas fa-pen text-xs"></i>
                                            </button>
                                            <form method="POST" class="inline"
                                                  onsubmit="return confirm('ລຶບສິ່ງອຳນວຍຄວາມສະດວກນີ້?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="fac_id" value="<?= $fac['Fac_ID'] ?>">
                                                <button type="submit"
                                                        class="text-red-400 hover:text-red-600 p-2 rounded-lg hover:bg-red-50 transition">
                                                    <i class="fas fa-trash text-xs"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="edit-form mt-3" id="edit-<?= $fac['Fac_ID'] ?>">
                                        <form method="POST" class="flex gap-2">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="fac_id" value="<?= $fac['Fac_ID'] ?>">
                                            <input type="text" name="fac_name"
                                                   value="<?= htmlspecialchars($fac['Fac_Name']) ?>"
                                                   class="flex-1 border-2 border-blue-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
                                                   required>
                                            <button type="submit"
                                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm font-bold transition">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" onclick="toggleEdit(<?= $fac['Fac_ID'] ?>)"
                                                    class="bg-gray-200 hover:bg-gray-300 text-gray-600 px-3 py-2 rounded-lg text-sm transition">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <i class="fas fa-concierge-bell text-6xl text-gray-200 mb-4 block"></i>
                        <h3 class="text-xl font-bold text-gray-600 mb-2">ຍັງບໍ່ໄດ້ເພີ່ມ</h3>
                        <p class="text-gray-400 text-sm">ເພີ່ມສິ່ງອຳນວຍຄວາມສະດວກເພື່ອບອກລູກຄ້າວ່າສະຖານທີ່ຂອງທ່ານມີຫຍັງ.</p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </main>
    </div>
</div>
<script>
function toggleEdit(id) {
    const editEl = document.getElementById('edit-' + id);
    const isOpen = editEl.classList.contains('open');
    document.querySelectorAll('.edit-form').forEach(el => el.classList.remove('open'));
    if (!isOpen) {
        editEl.classList.add('open');
        editEl.querySelector('input[type=text]').focus();
    }
}
</script>
</body>
</html>