<?php
session_start();
require_once '../config/db.php';

$search_query = $_GET['search'] ?? '';
$is_searching = !empty($search_query);

// AJAX autocomplete endpoint
if (isset($_GET['autocomplete'])) {
    $q = trim($_GET['autocomplete']);
    $suggestions = [];
    if (strlen($q) >= 1) {
        try {
            $stmt = $pdo->prepare("
                SELECT VN_ID, VN_Name, VN_Address
                FROM Venue_data
                WHERE VN_Status = 'Active'
                AND (VN_Name LIKE ? OR VN_Address LIKE ?)
                LIMIT 6
            ");
            $t = "%{$q}%";
            $stmt->execute([$t, $t]);
            $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }
    header('Content-Type: application/json');
    echo json_encode($suggestions);
    exit;
}

function get_featured_venues($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT v.*, co.Name AS owner_name,
                (SELECT COUNT(*) FROM Court_data WHERE VN_ID = v.VN_ID) AS total_courts
            FROM Venue_data v
            INNER JOIN advertisement ad ON v.VN_ID = ad.VN_ID
            LEFT JOIN court_owner co ON v.CA_ID = co.CA_ID
            WHERE v.VN_Status = 'Active'
            AND ad.Status_AD IN ('Approved','Active')
            AND ad.End_time > NOW()
            ORDER BY ad.AD_date DESC
            LIMIT 6
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function search_venues($pdo, $q) {
    try {
        $t = "%{$q}%";
        $stmt = $pdo->prepare("
            SELECT v.*,
                (SELECT COUNT(*) FROM Court_data WHERE VN_ID = v.VN_ID) AS total_courts
            FROM Venue_data v
            WHERE v.VN_Status = 'Active'
            AND (v.VN_Name LIKE ? OR v.VN_Address LIKE ? OR v.VN_Description LIKE ?)
            ORDER BY v.VN_Name ASC
        ");
        $stmt->execute([$t, $t, $t]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function get_site_stats($pdo) {
    try {
        return [
            'venues'   => $pdo->query("SELECT COUNT(*) FROM Venue_data WHERE VN_Status='Active'")->fetchColumn(),
            'courts'   => $pdo->query("SELECT COUNT(*) FROM Court_data")->fetchColumn(),
            'bookings' => $pdo->query("SELECT COUNT(*) FROM booking WHERE Status_booking='Confirmed'")->fetchColumn(),
        ];
    } catch (PDOException $e) { return ['venues'=>0,'courts'=>0,'bookings'=>0]; }
}

$featured_venues = get_featured_venues($pdo);
$search_results  = $is_searching ? search_venues($pdo, $search_query) : [];
$stats           = get_site_stats($pdo);
$is_logged_in    = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລະບົບຈອງເດີ່ນແບດມິນຕັນ - ຈອງເດີ່ນໄດ້ເລີຍຕອນນີ້</title>
    <link rel="icon" href="../../assets/images/logo/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .hero-overlay { background: linear-gradient(135deg,rgba(0,0,0,0.65) 0%,rgba(0,0,0,0.35) 100%); }
        @keyframes fadeInUp { from{opacity:0;transform:translateY(30px)} to{opacity:1;transform:translateY(0)} }
        .animate-fade-in-up { animation: fadeInUp 0.8s ease-out forwards; }
        .hover-scale { transition: all 0.3s ease; }
        .hover-scale:hover { transform: translateY(-6px); box-shadow: 0 16px 32px rgba(0,0,0,0.12); }
        #suggestions-home {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0; right: 0;
            background: white;
            border-radius: 16px;
            box-shadow: 0 16px 40px rgba(0,0,0,0.18);
            z-index: 100;
            overflow: hidden;
            border: 2px solid #e5e7eb;
        }
        .suggestion-item { display:flex; align-items:center; gap:12px; padding:12px 16px; cursor:pointer; transition:background 0.15s; border-bottom: 1px solid #f3f4f6; }
        .suggestion-item:last-child { border-bottom: none; }
        .suggestion-item:hover, .suggestion-item.active { background:#f0fdf4; }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/header.php'; ?>

    <!-- Hero Section -->
    <section class="relative text-white bg-center bg-cover bg-no-repeat min-h-[90vh] flex items-center"
        style="background-image: url('/Badminton_court_Booking/assets/images/BookingBG.png');">
        <div class="absolute inset-0 hero-overlay"></div>
        <div class="relative z-10 w-full animate-fade-in-up">
            <div class="max-w-4xl mx-auto px-4 text-center">
                <h1 class="text-5xl md:text-7xl font-extrabold mb-4 leading-tight drop-shadow-lg">
                    ອອກກຳລັງກາຍໄປກັບພວກເຮົາ<br>
                    <span class="text-yellow-400">ຈອງເດີ່ນໄດ້ເລີຍຕອນນີ້</span>
                </h1>
                <p class="text-xl text-gray-200 mb-10 max-w-2xl mx-auto">
                    ຄົ້ນຫາ ແລະ ຈອງເດີ່ນທີ່ທ່ານຕ້ອງການໄດ້ງ່າຍໆທີ່ເວັບໄຊ້ຂອງພວກເຮົາ
                </p>

                <!-- Search Box -->
                <form action="index.php" method="GET" onsubmit="closeSuggestions('home')">
                    <div class="bg-white rounded-2xl shadow-2xl p-3 max-w-2xl mx-auto flex items-center gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-lg z-10 pointer-events-none"></i>
                            <input type="text" name="search" id="searchInput-home"
                                   placeholder="ຊອກຫາເດີ່ນ ແລະ ຊື່..."
                                   value="<?= htmlspecialchars($search_query) ?>"
                                   autocomplete="off"
                                   class="w-full pl-12 pr-4 py-3.5 text-gray-800 text-base rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400"
                                   oninput="fetchSuggestions('home', this.value)"
                                   onkeydown="handleKey('home', event)">
                            <div id="suggestions-home"></div>
                        </div>
                        <button type="submit"
                                class="bg-green-600 hover:bg-green-700 text-white font-bold px-7 py-3.5 rounded-xl transition shadow flex-shrink-0">
                            <i class="fas fa-search mr-1"></i> ຄົ້ນຫາ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <!-- Search Results -->
    <?php if ($is_searching): ?>
        <section class="py-12 px-4 bg-gray-50">
            <div class="max-w-7xl mx-auto">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h2 class="text-2xl font-extrabold text-gray-800">
                            <i class="fas fa-search text-blue-500 mr-2"></i>ຜົນການຄົ້ນຫາ
                        </h2>
                        <p class="text-gray-500 text-sm mt-1">ພົບ <?= count($search_results) ?> ສະຖານທີ່</p>
                    </div>
                    <a href="index.php" class="text-sm text-blue-600 hover:underline">ລ້າງການຄົ້ນຫາ</a>
                </div>

                <?php if (!empty($search_results)): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($search_results as $venue):
                            $price_clean = preg_replace('/[^0-9.]/', '', $venue['Price_per_hour']);
                            $venue_img   = !empty($venue['VN_Image'])
                                ? '/Badminton_court_Booking/assets/images/venues/' . basename($venue['VN_Image'])
                                : '/Badminton_court_Booking/assets/images/BookingBG.png';
                        ?>
                            <div class="bg-white rounded-2xl overflow-hidden shadow-md border border-gray-100 hover-scale">
                                <div class="relative h-48">
                                    <img src="<?= htmlspecialchars($venue_img) ?>" alt="<?= htmlspecialchars($venue['VN_Name']) ?>"
                                         class="w-full h-full object-cover"
                                         onerror="this.src='/Badminton_court_Booking/assets/images/BookingBG.png'">
                                </div>
                                <div class="p-5">
                                    <h3 class="font-bold text-lg text-gray-800 mb-1"><?= htmlspecialchars($venue['VN_Name']) ?></h3>
                                    <p class="text-gray-500 text-sm mb-3 flex items-center gap-1">
                                        <i class="fas fa-map-marker-alt text-red-400"></i><?= htmlspecialchars($venue['VN_Address']) ?>
                                    </p>
                                    <div class="flex items-center gap-3 text-xs text-gray-500 mb-4">
                                        <span><i class="fas fa-table-tennis mr-1 text-green-500"></i><?= $venue['total_courts'] ?> ເດີ່ນ</span>
                                        <span><i class="fas fa-clock mr-1 text-blue-500"></i><?= date('H:i', strtotime($venue['Open_time'])) ?> - <?= date('H:i', strtotime($venue['Close_time'])) ?></span>
                                    </div>
                                    <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                                        <p class="text-lg font-extrabold text-green-600">₭<?= number_format($price_clean) ?><span class="text-xs text-gray-400 font-normal">/ຊົ່ວໂມງ</span></p>
                                        <?php
                                            $venue_url = '/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue['VN_ID'];
                                            $book_href = $is_logged_in
                                                ? $venue_url
                                                : '/Badminton_court_Booking/auth/login.php?redirect=' . urlencode($venue_url);
                                        ?>
                                        <a href="<?= $book_href ?>"
                                           class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-xl font-semibold text-sm transition">
                                            <?= $is_logged_in ? 'ຈອງດຽວນີ້' : '<i class="fas fa-sign-in-alt mr-1"></i>ເຂົ້າສູ່ລະບົບເພື່ອຈອງ' ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-16 bg-white rounded-2xl border-2 border-dashed border-gray-200">
                        <i class="fas fa-search text-5xl text-gray-200 mb-4 block"></i>
                        <h3 class="text-xl font-bold text-gray-600 mb-2">ບໍ່ພົບສະຖານທີ່</h3>
                        <p class="text-gray-400 mb-4">ລອງຊອກຫາດ້ວຍຊື່ ຫຼື ທີ່ຢູ່ອື່ນ</p>
                        <a href="index.php" class="inline-block bg-blue-600 text-white px-6 py-2 rounded-xl hover:bg-blue-700 transition">ເບິ່ງເດີ່ນທັງໝົດ</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$is_searching): ?>
        <!-- Featured Venues Carousel -->
        <section class="py-16 px-4 bg-white overflow-hidden">
            <div class="max-w-7xl mx-auto">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h2 class="text-3xl font-extrabold text-gray-800">
                            <i class="fas fa-star text-yellow-500 mr-2"></i>ເດີ່ນແນະນຳ
                        </h2>
                        <p class="text-gray-500 mt-1">ສະຖານທີ່ຊັ້ນນຳທີ່ຄັດສັນມາໃຫ້ທ່ານ</p>
                    </div>
                    <a href="/Badminton_court_Booking/customer/booking_court/index.php"
                       class="text-blue-600 hover:text-blue-700 font-medium text-sm">
                        ເບິ່ງເດີ່ນທັງໝົດ <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>

                <?php if (!empty($featured_venues)): ?>
                    <div class="relative" id="featuredCarousel">
                        <div class="overflow-hidden rounded-2xl">
                            <div id="carouselTrack" class="flex transition-transform duration-700 ease-in-out">
                                <?php foreach ($featured_venues as $idx => $venue):
                                    $price_clean = preg_replace('/[^0-9.]/', '', $venue['Price_per_hour']);
                                    $venue_img   = !empty($venue['VN_Image'])
                                        ? '/Badminton_court_Booking/assets/images/venues/' . basename($venue['VN_Image'])
                                        : '/Badminton_court_Booking/assets/images/BookingBG.png';
                                ?>
                                    <div class="carousel-slide flex-shrink-0 w-full relative h-[480px] md:h-[520px]">
                                        <img src="<?= htmlspecialchars($venue_img) ?>"
                                             alt="<?= htmlspecialchars($venue['VN_Name']) ?>"
                                             class="w-full h-full object-cover"
                                             onerror="this.src='/Badminton_court_Booking/assets/images/BookingBG.png'">
                                        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/30 to-transparent"></div>
                                        <div class="absolute top-5 left-5 bg-yellow-400 text-white text-sm font-bold px-3 py-1.5 rounded-full flex items-center gap-1.5 shadow-lg">
                                            <i class="fas fa-star text-xs"></i> ແນະນຳ
                                        </div>
                                        <div class="absolute top-5 right-5 bg-black/40 text-white text-xs font-semibold px-3 py-1.5 rounded-full backdrop-blur-sm">
                                            <?= $idx+1 ?> / <?= count($featured_venues) ?>
                                        </div>
                                        <div class="absolute bottom-0 left-0 right-0 p-8">
                                            <h3 class="text-3xl md:text-4xl font-extrabold text-white mb-2 drop-shadow-lg">
                                                <?= htmlspecialchars($venue['VN_Name']) ?>
                                            </h3>
                                            <p class="text-gray-300 flex items-center gap-2 mb-3 text-sm">
                                                <i class="fas fa-map-marker-alt text-red-400"></i>
                                                <?= htmlspecialchars($venue['VN_Address']) ?>
                                            </p>
                                            <div class="flex items-center gap-4 mb-5 text-sm text-gray-300 flex-wrap">
                                                <span class="flex items-center gap-1.5 bg-white/10 backdrop-blur px-3 py-1.5 rounded-full">
                                                    <i class="fas fa-table-tennis text-green-400"></i>
                                                    <?= $venue['total_courts'] ?> ເດີ່ນ
                                                </span>
                                                <span class="flex items-center gap-1.5 bg-white/10 backdrop-blur px-3 py-1.5 rounded-full">
                                                    <i class="fas fa-clock text-blue-400"></i>
                                                    <?= date('H:i', strtotime($venue['Open_time'])) ?> – <?= date('H:i', strtotime($venue['Close_time'])) ?>
                                                </span>
                                                <span class="flex items-center gap-1.5 bg-white/10 backdrop-blur px-3 py-1.5 rounded-full">
                                                    <i class="fas fa-tag text-yellow-400"></i>
                                                    ₭<?= number_format($price_clean) ?>/ຊົ່ວໂມງ
                                                </span>
                                            </div>
                                            <?php
                                                $venue_url = '/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=' . $venue['VN_ID'];
                                                $book_href = $is_logged_in
                                                    ? $venue_url
                                                    : '/Badminton_court_Booking/auth/login.php?redirect=' . urlencode($venue_url);
                                            ?>
                                            <a href="<?= $book_href ?>"
                                               class="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white font-bold px-7 py-3 rounded-xl transition shadow-xl text-base">
                                                <?php if ($is_logged_in): ?>
                                                    <i class="fas fa-calendar-check"></i> ຈອງດຽວນີ້
                                                <?php else: ?>
                                                    <i class="fas fa-sign-in-alt"></i> ເຂົ້າສູ່ລະບົບເພື່ອຈອງ
                                                <?php endif; ?>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button onclick="carouselMove(-1)"
                                class="absolute left-4 top-1/2 -translate-y-1/2 bg-black/40 hover:bg-black/70 text-white w-11 h-11 rounded-full flex items-center justify-center transition backdrop-blur-sm z-10">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button onclick="carouselMove(1)"
                                class="absolute right-4 top-1/2 -translate-y-1/2 bg-black/40 hover:bg-black/70 text-white w-11 h-11 rounded-full flex items-center justify-center transition backdrop-blur-sm z-10">
                            <i class="fas fa-chevron-right"></i>
                        </button>

                        <div class="flex justify-center gap-2 mt-5" id="carouselDots">
                            <?php foreach ($featured_venues as $idx => $_): ?>
                                <button onclick="carouselGoTo(<?= $idx ?>)"
                                        class="carousel-dot w-2.5 h-2.5 rounded-full transition-all duration-300 <?= $idx===0 ? 'bg-blue-600 w-6' : 'bg-gray-300' ?>">
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="text-center py-12 bg-gray-50 rounded-2xl border-2 border-dashed border-gray-200">
                        <i class="fas fa-store text-5xl text-gray-200 mb-4 block"></i>
                        <p class="text-gray-400 mb-4">ຍັງບໍ່ມີເດີ່ນແນະນຳ</p>
                        <a href="/Badminton_court_Booking/customer/booking_court/index.php"
                           class="inline-block bg-blue-600 text-white px-6 py-2 rounded-xl hover:bg-blue-700 transition text-sm">ເບິ່ງເດີ່ນທັງໝົດ</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- How It Works -->
        <section class="py-16 px-4 bg-gray-50">
            <div class="max-w-5xl mx-auto text-center">
                <h2 class="text-3xl font-extrabold text-gray-800 mb-2">ວິທີການໃຊ້ງານ</h2>
                <p class="text-gray-500 mb-10">ຈອງເດີ່ນໄດ້ງ່າຍໆ 3 ຂັ້ນຕອນ</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <?php foreach ([
                        ['fa-search',       'blue',   '1', 'ຊອກຫາເດີ່ນ',         'ເບິ່ງສະຖານທີ່ທີ່ມີ ແລະ ເລືອກເວລາທີ່ທ່ານຕ້ອງການ'],
                        ['fa-credit-card',  'green',  '2', 'ຈ່າຍມັດຈຳ 30%',      'ຈອງເດີ່ນຂອງທ່ານດ້ວຍການຈ່າຍມັດຈຳ 30% ຜ່ານ QR Code'],
                        ['fa-table-tennis', 'yellow', '3', 'ຫຼິ້ນ ແລະ ຈ່າຍສ່ວນທີ່ເຫຼືອ', 'ມາຮອດສະຖານທີ່ ແລະ ຈ່າຍສ່ວນທີ່ເຫຼືອ 70% ໂດຍກົງ'],
                    ] as [$icon,$color,$step,$title,$desc]): ?>
                        <div class="bg-white rounded-2xl p-8 shadow-sm hover-scale">
                            <div class="bg-<?= $color ?>-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas <?= $icon ?> text-<?= $color ?>-500 text-2xl"></i>
                            </div>
                            <div class="bg-<?= $color ?>-600 text-white w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold mx-auto mb-3"><?= $step ?></div>
                            <h3 class="font-bold text-gray-800 text-lg mb-2"><?= $title ?></h3>
                            <p class="text-gray-500 text-sm"><?= $desc ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

    <?php endif; ?>

    <?php include 'includes/footer.php'; ?>

    <script>
    const suggestionState = {};

    async function fetchSuggestions(id, val) {
        const box = document.getElementById(`suggestions-${id}`);
        if (!val.trim()) { box.style.display = 'none'; return; }
        const res  = await fetch(`index.php?autocomplete=${encodeURIComponent(val)}`);
        const data = await res.json();
        suggestionState[id] = { data, activeIndex: -1 };
        if (!data.length) { box.style.display = 'none'; return; }
        box.innerHTML = data.map((v, i) => `
            <div class="suggestion-item" data-index="${i}" onmousedown="pickSuggestion('${id}', ${i})">
                <div class="bg-green-100 w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-store text-green-600 text-xs"></i>
                </div>
                <div class="min-w-0">
                    <p class="font-semibold text-gray-800 text-sm truncate">${v.VN_Name}</p>
                    <p class="text-xs text-gray-400 truncate"><i class="fas fa-map-marker-alt mr-1 text-red-400"></i>${v.VN_Address}</p>
                </div>
            </div>
        `).join('');
        box.style.display = 'block';
    }

    function pickSuggestion(id, i) {
        const state = suggestionState[id];
        document.getElementById(`searchInput-${id}`).value = state.data[i].VN_Name;
        closeSuggestions(id);
        document.getElementById(`searchInput-${id}`).closest('form').submit();
    }

    function closeSuggestions(id) {
        const box = document.getElementById(`suggestions-${id}`);
        if (box) box.style.display = 'none';
    }

    function handleKey(id, e) {
        const state = suggestionState[id];
        if (!state || !state.data.length) return;
        const items = document.querySelectorAll(`#suggestions-${id} .suggestion-item`);
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            state.activeIndex = Math.min(state.activeIndex + 1, items.length - 1);
            highlightSuggestion(id, items);
            e.preventDefault();
        } else if (e.key === 'ArrowUp') {
            state.activeIndex = Math.max(state.activeIndex - 1, 0);
            highlightSuggestion(id, items);
            e.preventDefault();
        } else if (e.key === 'Enter' && state.activeIndex >= 0) {
            pickSuggestion(id, state.activeIndex);
            e.preventDefault();
        } else if (e.key === 'Escape') {
            closeSuggestions(id);
        }
    }

    function highlightSuggestion(id, items) {
        const state = suggestionState[id];
        items.forEach((el, i) => el.classList.toggle('active', i === state.activeIndex));
        if (state.activeIndex >= 0) {
            document.getElementById(`searchInput-${id}`).value = state.data[state.activeIndex].VN_Name;
        }
    }

    document.addEventListener('click', e => {
        ['home', 'booking'].forEach(id => {
            const input = document.getElementById(`searchInput-${id}`);
            const box   = document.getElementById(`suggestions-${id}`);
            if (box && input && !input.contains(e.target) && !box.contains(e.target)) {
                closeSuggestions(id);
            }
        });
    });

    // Carousel
    let carouselIndex = 0;
    let carouselTimer = null;
    const track = document.getElementById('carouselTrack');
    const dots  = document.querySelectorAll('.carousel-dot');
    const total = dots.length;

    function carouselGoTo(idx) {
        carouselIndex = (idx + total) % total;
        if (track) track.style.transform = `translateX(-${carouselIndex * 100}%)`;
        dots.forEach((d, i) => {
            d.classList.toggle('bg-blue-600', i === carouselIndex);
            d.classList.toggle('w-6',         i === carouselIndex);
            d.classList.toggle('bg-gray-300', i !== carouselIndex);
            d.classList.toggle('w-2.5',       i !== carouselIndex);
        });
        resetTimer();
    }

    function carouselMove(dir) { carouselGoTo(carouselIndex + dir); }

    function resetTimer() {
        clearInterval(carouselTimer);
        if (total > 1) carouselTimer = setInterval(() => carouselMove(1), 3000);
    }

    const carousel = document.getElementById('featuredCarousel');
    if (carousel) {
        carousel.addEventListener('mouseenter', () => clearInterval(carouselTimer));
        carousel.addEventListener('mouseleave', resetTimer);
    }

    let touchStartX = 0;
    if (track) {
        track.addEventListener('touchstart', e => touchStartX = e.touches[0].clientX, { passive: true });
        track.addEventListener('touchend',   e => {
            const diff = touchStartX - e.changedTouches[0].clientX;
            if (Math.abs(diff) > 50) carouselMove(diff > 0 ? 1 : -1);
        });
    }

    if (total > 1) resetTimer();
    </script>
</body>
</html>