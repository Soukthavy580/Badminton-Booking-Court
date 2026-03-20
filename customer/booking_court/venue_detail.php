<?php
session_start();
require_once '../../config/db.php';

$venue_id    = isset($_GET['id']) ? intval($_GET['id']) : 0;
$search_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if (!$venue_id) {
    header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT v.*, co.Name AS owner_name, co.Phone AS owner_phone
        FROM Venue_data v
        LEFT JOIN court_owner co ON v.CA_ID = co.CA_ID
        WHERE v.VN_ID = ? AND v.VN_Status = 'Active'
        LIMIT 1
    ");
    $stmt->execute([$venue_id]);
    $venue = $stmt->fetch();
} catch (PDOException $e) { $venue = null; }

if (!$venue) {
    header('Location: /Badminton_court_Booking/customer/booking_court/index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM Court_data WHERE VN_ID = ? AND Court_Status = 'Active' ORDER BY COURT_Name");
    $stmt->execute([$venue_id]);
    $courts = $stmt->fetchAll();
} catch (PDOException $e) { $courts = []; }

try {
    $stmt = $pdo->prepare("SELECT * FROM facilities WHERE VN_ID = ?");
    $stmt->execute([$venue_id]);
    $facilities = $stmt->fetchAll();
} catch (PDOException $e) { $facilities = []; }

function get_booked_slots($pdo, $venue_id, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT bd.COURT_ID, bd.Start_time, bd.End_time, b.Status_booking
            FROM booking_detail bd
            INNER JOIN booking b ON bd.Book_ID = b.Book_ID
            INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
            WHERE c.VN_ID = ?
            AND DATE(bd.Start_time) = ?
            AND b.Status_booking IN ('Confirmed', 'Pending')
        ");
        $stmt->execute([$venue_id, $date]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function generate_time_slots($open_time, $close_time, $interval_minutes = 60) {
    $slots = [];
    $start = strtotime($open_time);
    $end   = strtotime($close_time);
    while ($start < $end) {
        $slot_end = $start + ($interval_minutes * 60);
        if ($slot_end <= $end) {
            $slots[] = [
                'start' => date('H:i', $start),
                'end'   => date('H:i', $slot_end),
                'label' => date('g:i A', $start) . ' - ' . date('g:i A', $slot_end),
            ];
        }
        $start += $interval_minutes * 60;
    }
    return $slots;
}

function is_slot_booked($booked_slots, $court_id, $slot_start, $slot_end, $date) {
    // Returns: false | 'Confirmed' | 'Pending'
    foreach ($booked_slots as $booked) {
        if ($booked['COURT_ID'] != $court_id) continue;
        $booked_start = strtotime($booked['Start_time']);
        $booked_end   = strtotime($booked['End_time']);
        $check_start  = strtotime($date . ' ' . $slot_start);
        $check_end    = strtotime($date . ' ' . $slot_end);
        if ($check_start < $booked_end && $check_end > $booked_start) {
            return $booked['Status_booking'] ?? 'Confirmed';
        }
    }
    return false;
}

$booked_slots = get_booked_slots($pdo, $venue_id, $search_date);
$price_clean  = preg_replace('/[^0-9.]/', '', $venue['Price_per_hour']);
$venue_img    = !empty($venue['VN_Image'])
    ? '/Badminton_court_Booking/assets/images/venues/' . basename($venue['VN_Image'])
    : '/Badminton_court_Booking/assets/images/BookingBG.png';
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($venue['VN_Name']) ?> - ລະບົບຈອງເດີ່ນ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .slot-btn { transition: all 0.2s ease; cursor: pointer; user-select: none; }
        .slot-btn.available:hover { background-color: #bbf7d0; border-color: #16a34a; transform: scale(1.03); }
        .slot-btn.selected { background-color: #16a34a !important; color: white !important; border-color: #15803d !important; transform: scale(1.03); }
        .slot-btn.booked { background-color: #fee2e2; color: #991b1b; cursor: not-allowed; opacity: 0.7; }
        .slot-btn.pending-slot { background-color: #fef9c3; color: #92400e; border-color: #fcd34d; cursor: not-allowed; opacity: 0.8; }
        .slot-btn.past { background-color: #f3f4f6; color: #9ca3af; cursor: not-allowed; opacity: 0.6; }
        .summary-box { position: sticky; top: 80px; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-slide-in { animation: slideIn 0.3s ease forwards; }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 py-8">

        <a href="/Badminton_court_Booking/customer/booking_court/index.php?date=<?= $search_date ?>"
           class="inline-flex items-center gap-2 text-gray-600 hover:text-blue-600 mb-6 transition font-medium">
            <i class="fas fa-arrow-left"></i> ກັບໄປລາຍການເດີ່ນ
        </a>

        <!-- Venue Hero -->
        <div class="relative rounded-2xl overflow-hidden mb-8 h-64 md:h-80 shadow-lg">
            <img src="<?= htmlspecialchars($venue_img) ?>"
                 alt="<?= htmlspecialchars($venue['VN_Name']) ?>"
                 class="w-full h-full object-cover"
                 onerror="this.src='/Badminton_court_Booking/assets/images/BookingBG.png'">
            <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent"></div>
            <div class="absolute bottom-0 left-0 p-6 text-white">
                <h1 class="text-3xl md:text-4xl font-extrabold mb-1"><?= htmlspecialchars($venue['VN_Name']) ?></h1>
                <p class="text-gray-200 flex items-center gap-2">
                    <i class="fas fa-map-marker-alt text-red-400"></i>
                    <?= htmlspecialchars($venue['VN_Address']) ?>
                </p>
            </div>
            <div class="absolute top-4 right-4 bg-white rounded-xl px-4 py-2 shadow-lg">
                <p class="text-xs text-gray-500">ລາຄາຕໍ່ຊົ່ວໂມງ</p>
                <p class="text-xl font-bold text-green-600">₭<?= number_format($price_clean) ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- Left Column -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Venue Info -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="text-xl font-bold mb-4 text-gray-800">ກ່ຽວກັບສະຖານທີ່ນີ້</h2>
                    <p class="text-gray-600 mb-4"><?= htmlspecialchars($venue['VN_Description']) ?></p>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                        <div class="flex items-center gap-2 text-gray-600">
                            <i class="fas fa-clock text-blue-500 w-4"></i>
                            <span><?= date('H:i', strtotime($venue['Open_time'])) ?> - <?= date('H:i', strtotime($venue['Close_time'])) ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-gray-600">
                            <i class="fas fa-table-tennis text-green-500 w-4"></i>
                            <span><?= count($courts) ?> ເດີ່ນທີ່ມີ</span>
                        </div>
                        <div class="flex items-center gap-2 text-gray-600">
                            <i class="fas fa-user text-purple-500 w-4"></i>
                            <span><?= htmlspecialchars($venue['owner_name']) ?></span>
                        </div>
                        <?php if (!empty($venue['VN_MapURL'])): ?>
                        <div class="flex items-center gap-2 col-span-2 md:col-span-3">
                            <i class="fas fa-map text-red-500 w-4"></i>
                            <a href="<?= htmlspecialchars($venue['VN_MapURL']) ?>" target="_blank"
                               class="text-blue-600 hover:underline">ເບິ່ງໃນ Google Maps</a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($facilities)): ?>
                        <div class="mt-4 pt-4 border-t border-gray-100">
                            <h3 class="text-sm font-bold text-gray-700 mb-3">ສິ່ງອຳນວຍຄວາມສະດວກ</h3>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($facilities as $fac): ?>
                                    <span class="bg-blue-50 text-blue-700 px-3 py-1 rounded-full text-xs font-medium">
                                        <i class="fas fa-check mr-1"></i><?= htmlspecialchars($fac['Fac_Name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Date Picker -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="text-xl font-bold mb-4 text-gray-800">
                        <i class="fas fa-calendar-alt text-blue-500 mr-2"></i>ເລືອກວັນທີ
                    </h2>
                    <form method="GET" action="" class="flex gap-3 items-end">
                        <input type="hidden" name="id" value="<?= $venue_id ?>">
                        <div class="flex-1">
                            <input type="date" name="date"
                                   value="<?= htmlspecialchars($search_date) ?>"
                                   min="<?= date('Y-m-d') ?>"
                                   class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-semibold transition">
                            ກວດສອບ
                        </button>
                    </form>
                </div>

                <!-- Court Slot Selection -->
                <?php if (!empty($courts)): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-bold text-gray-800">
                                <i class="fas fa-table-tennis text-green-500 mr-2"></i>
                                ເລືອກເດີ່ນ ແລະແເວລາ
                            </h2>
                            <span class="text-sm text-gray-500 font-medium">
                                <?= date('d/m/Y', strtotime($search_date)) ?>
                            </span>
                        </div>

                        <!-- Legend -->
                        <div class="flex flex-wrap gap-4 mb-6 text-xs font-medium">
                            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-green-100 border border-green-300 inline-block"></span> ວ່າງ</span>
                            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-green-600 inline-block"></span> ເລືອກແລ້ວ</span>
                            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-red-100 border border-red-300 inline-block"></span> ຈອງແລ້ວ</span>
                            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-yellow-100 border border-yellow-300 inline-block"></span> ລໍຖ້າ</span>
                            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-gray-200 inline-block"></span> ຜ່ານແລ້ວ</span>
                        </div>

                        <?php foreach ($courts as $court):
                            $court_open  = !empty($court['Open_time'])  ? $court['Open_time']  : ($venue['Open_time']  ?? null);
                            $court_close = !empty($court['Close_time']) ? $court['Close_time'] : ($venue['Close_time'] ?? null);
                            if (empty($court_open) || empty($court_close)) continue;
                            $court_slots = generate_time_slots($court_open, $court_close);
                        ?>
                            <div class="mb-6 last:mb-0">
                                <h3 class="font-bold text-gray-700 mb-3 flex items-center gap-2 flex-wrap">
                                    <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-lg text-sm">
                                        <?= htmlspecialchars($court['COURT_Name']) ?>
                                    </span>
                                    <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-lg">
                                        <i class="fas fa-clock mr-1"></i>
                                        <?= date('g:i A', strtotime($court_open)) ?> – <?= date('g:i A', strtotime($court_close)) ?>
                                        <?php if (empty($court['Open_time'])): ?>
                                            <span class="text-gray-300 ml-1">(ເວລາສະຖານທີ່)</span>
                                        <?php endif; ?>
                                    </span>
                                </h3>
                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                                    <?php foreach ($court_slots as $slot):
                                        $booked_status = is_slot_booked($booked_slots, $court['COURT_ID'], $slot['start'], $slot['end'], $search_date);
                                        $slot_datetime = strtotime($search_date . ' ' . $slot['start']);
                                        $is_past_slot  = $slot_datetime < time();

                                        if ($is_past_slot) {
                                            $slot_class = 'past';
                                            $disabled   = 'disabled';
                                        } elseif ($booked_status === 'Confirmed') {
                                            $slot_class = 'booked confirmed-slot';
                                            $disabled   = 'disabled';
                                        } elseif ($booked_status === 'Pending') {
                                            $slot_class = 'booked pending-slot';
                                            $disabled   = 'disabled';
                                        } else {
                                            $slot_class = 'available bg-green-50 border border-green-200 text-green-800';
                                            $disabled   = '';
                                        }
                                    ?>
                                        <button type="button"
                                                class="slot-btn <?= $slot_class ?> rounded-lg px-2 py-3 text-xs font-medium text-center"
                                                data-court-id="<?= $court['COURT_ID'] ?>"
                                                data-court-name="<?= htmlspecialchars($court['COURT_Name']) ?>"
                                                data-start="<?= $slot['start'] ?>"
                                                data-end="<?= $slot['end'] ?>"
                                                data-date="<?= $search_date ?>"
                                                data-price="<?= $price_clean ?>"
                                                <?= $disabled ?>
                                                onclick="toggleSlot(this)">
                                            <?= $slot['label'] ?>
                                            <?php if ($booked_status === 'Confirmed'): ?>
                                                <br><span class="text-red-500 text-xs">ຈອງແລ້ວ</span>
                                            <?php elseif ($booked_status === 'Pending'): ?>
                                                <br><span class="text-yellow-600 text-xs">ລໍຖ້າ</span>
                                            <?php elseif ($is_past_slot): ?>
                                                <br><span class="text-gray-400 text-xs">ຜ່ານແລ້ວ</span>
                                            <?php else: ?>
                                                <br><span class="text-green-600 text-xs">₭<?= number_format($price_clean) ?></span>
                                            <?php endif; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-2xl shadow-sm p-10 text-center">
                        <i class="fas fa-exclamation-circle text-4xl text-yellow-400 mb-3"></i>
                        <p class="text-gray-600 font-medium">ຍັງບໍ່ມີເດີ່ນຢູ່ສະຖານທີ່ນີ້.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column - Booking Summary -->
            <div class="lg:col-span-1">
                <div class="summary-box bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="text-xl font-bold mb-4 text-gray-800">
                        <i class="fas fa-receipt text-blue-500 mr-2"></i>ສະຫຼຸບການຈອງ
                    </h2>

                    <div id="summaryEmpty" class="text-center py-8">
                        <i class="fas fa-hand-pointer text-4xl text-gray-200 mb-3 block"></i>
                        <p class="text-gray-400 text-sm">ເລືອກເດີ່ນເວລາເພື່ອເລີ່ມຈອງ</p>
                    </div>

                    <div id="summaryContent" class="hidden">
                        <div class="mb-4 pb-4 border-b border-gray-100">
                            <p class="text-sm text-gray-500 mb-1">ສະຖານທີ່</p>
                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($venue['VN_Name']) ?></p>
                        </div>
                        <div class="mb-4 pb-4 border-b border-gray-100">
                            <p class="text-sm text-gray-500 mb-1">ວັນທີ</p>
                            <p class="font-semibold text-gray-800"><?= date('d/m/Y', strtotime($search_date)) ?></p>
                        </div>

                        <div id="selectedSlotsList" class="mb-4 space-y-2"></div>

                        <div class="bg-green-50 rounded-xl p-4 mb-6">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600 font-medium">ລວມທັງໝົດ</span>
                                <span id="totalPrice" class="text-2xl font-extrabold text-green-600">₭0</span>
                            </div>
                            <p id="totalHours" class="text-xs text-gray-500 mt-1 text-right"></p>
                        </div>

                        <?php if (isset($_SESSION['user_id'])): ?>
                            <button onclick="proceedToBooking()"
                                    class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-xl transition shadow-lg text-lg">
                                <i class="fas fa-calendar-check mr-2"></i>ຢືນຢັນການຈອງ
                            </button>
                        <?php else: ?>
                            <a href="/Badminton_court_Booking/auth/login.php"
                               class="w-full block text-center bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-xl transition shadow-lg text-lg">
                                <i class="fas fa-sign-in-alt mr-2"></i>ເຂົ້າສູ່ລະບົບເພື່ອຈອງ
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Login Modal -->
    <?php if (!isset($_SESSION['user_id'])): ?>
    <div id="loginModal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4" onclick="if(event.target===this)closeLoginModal()">
        <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-sm w-full text-center">
            <div class="bg-blue-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-lock text-blue-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-extrabold text-gray-800 mb-2">ຕ້ອງເຂົ້າສູ່ລະບົບ</h3>
            <p class="text-gray-500 text-sm mb-6">ທ່ານຕ້ອງເຂົ້າສູ່ລະບົບກ່ອນຈຶ່ງຈອງເດີ່ນໄດ້.</p>
            <div class="flex flex-col gap-3">
                <a id="loginRedirectBtn"
                   href="/Badminton_court_Booking/auth/login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
                   class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition shadow">
                    <i class="fas fa-sign-in-alt mr-2"></i>ເຂົ້າສູ່ລະບົບ
                </a>
                <a href="/Badminton_court_Booking/auth/register.php"
                   class="w-full bg-white border-2 border-gray-200 hover:border-blue-400 text-gray-700 hover:text-blue-600 font-bold py-3 rounded-xl transition">
                    <i class="fas fa-user-plus mr-2"></i>ສ້າງບັນຊີ
                </a>
                <button onclick="closeLoginModal()" class="text-sm text-gray-400 hover:text-gray-600 transition mt-1">
                    ພາຍຫຼັງ
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <form id="bookingForm" method="POST" action="/Badminton_court_Booking/customer/booking_court/process_booking.php" class="hidden">
        <input type="hidden" name="venue_id"   value="<?= $venue_id ?>">
        <input type="hidden" name="date"       value="<?= $search_date ?>">
        <input type="hidden" name="slots_json" id="slotsJson">
    </form>

    <?php include '../includes/footer.php'; ?>

    <script>
        const isLoggedIn = <?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>;
        let selectedSlots = [];

        function toggleSlot(btn) {
            if (btn.disabled || btn.classList.contains('booked') || btn.classList.contains('past')) return;
            if (!isLoggedIn) {
                document.getElementById('loginModal').classList.remove('hidden');
                return;
            }
            const courtId   = btn.dataset.courtId;
            const courtName = btn.dataset.courtName;
            const start     = btn.dataset.start;
            const end       = btn.dataset.end;
            const date      = btn.dataset.date;
            const price     = parseFloat(btn.dataset.price);
            const key       = `${courtId}_${start}_${end}`;

            if (btn.classList.contains('selected')) {
                btn.classList.remove('selected');
                btn.classList.add('available', 'bg-green-50', 'border', 'border-green-200', 'text-green-800');
                selectedSlots = selectedSlots.filter(s => s.key !== key);
            } else {
                btn.classList.add('selected');
                btn.classList.remove('available', 'bg-green-50', 'border', 'border-green-200', 'text-green-800');
                selectedSlots.push({ key, courtId, courtName, start, end, date, price });
            }
            updateSummary();
        }

        function closeLoginModal() {
            document.getElementById('loginModal')?.classList.add('hidden');
        }

        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLoginModal(); });

        function updateSummary() {
            const empty   = document.getElementById('summaryEmpty');
            const content = document.getElementById('summaryContent');
            const list    = document.getElementById('selectedSlotsList');
            const total   = document.getElementById('totalPrice');
            const hours   = document.getElementById('totalHours');

            if (selectedSlots.length === 0) {
                empty.classList.remove('hidden');
                content.classList.add('hidden');
                return;
            }

            empty.classList.add('hidden');
            content.classList.remove('hidden');

            let html = '';
            let totalPrice = 0;
            selectedSlots.forEach(slot => {
                totalPrice += slot.price;
                html += `
                    <div class="flex justify-between items-center bg-gray-50 rounded-lg px-3 py-2 text-sm animate-slide-in">
                        <div>
                            <p class="font-semibold text-gray-800">${slot.courtName}</p>
                            <p class="text-gray-500 text-xs">${formatTime(slot.start)} - ${formatTime(slot.end)}</p>
                        </div>
                        <span class="text-green-600 font-bold">₭${numberFormat(slot.price)}</span>
                    </div>`;
            });

            list.innerHTML = html;
            total.textContent = '₭' + numberFormat(totalPrice);
            hours.textContent = 'ເລືອກ ' + selectedSlots.length + ' ເດີ່ນ';
        }

        function formatTime(time) {
            const [h, m] = time.split(':').map(Number);
            const ampm = h >= 12 ? 'PM' : 'AM';
            const hour = h % 12 || 12;
            return `${hour}:${m.toString().padStart(2, '0')} ${ampm}`;
        }

        function numberFormat(n) { return Math.round(n).toLocaleString(); }

        function proceedToBooking() {
            if (selectedSlots.length === 0) {
                alert('ກະລຸນາເລືອກຢ່າງໜ້ອຍໜຶ່ງເດີ່ນ.');
                return;
            }
            document.getElementById('slotsJson').value = JSON.stringify(selectedSlots);
            document.getElementById('bookingForm').submit();
        }
    </script>
</body>
</html>