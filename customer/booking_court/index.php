<?php
session_start();
require_once '../../config/db.php';

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_by      = $_GET['sort'] ?? 'name';
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
                WHERE VN_Status IN ('Active','Maintaining')
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

function get_all_active_venues($pdo, $search_query = '', $sort_by = 'name') {
    try {
        $sql = "SELECT
                    v.VN_ID, v.VN_Name, v.VN_Description, v.VN_Address,
                    v.VN_Image, v.Price_per_hour, v.Open_time, v.Close_time, v.VN_Status,
                    co.Name AS owner_name,
                    (SELECT COUNT(*) FROM Court_data WHERE VN_ID = v.VN_ID) AS total_courts,
                    (SELECT COUNT(*) FROM facilities WHERE VN_ID = v.VN_ID) AS total_facilities
                FROM Venue_data v
                LEFT JOIN court_owner co ON v.CA_ID = co.CA_ID
                WHERE v.VN_Status IN ('Active', 'Maintaining')";
        $params = [];
        if (!empty($search_query)) {
            $sql .= " AND (v.VN_Name LIKE ? OR v.VN_Description LIKE ? OR v.VN_Address LIKE ?)";
            $t = "%{$search_query}%";
            $params[] = $t; $params[] = $t; $params[] = $t;
        }
        switch ($sort_by) {
            case 'price_low':  $sql .= " ORDER BY CAST(REPLACE(REPLACE(v.Price_per_hour,',',''),' ','') AS UNSIGNED) ASC"; break;
            case 'price_high': $sql .= " ORDER BY CAST(REPLACE(REPLACE(v.Price_per_hour,',',''),' ','') AS UNSIGNED) DESC"; break;
            case 'newest':     $sql .= " ORDER BY v.VN_ID DESC"; break;
            default:           $sql .= " ORDER BY v.VN_Name ASC"; break;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return []; }
}

function get_available_slots($pdo, $venue_id) {
    $today = date('Y-m-d');
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM Court_data WHERE VN_ID = ?");
        $stmt->execute([$venue_id]);
        $total_courts = $stmt->fetch()['total'];
        if ($total_courts == 0) return ['total' => 0, 'available' => 0];
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT bd.ID) AS booked
            FROM booking_detail bd
            INNER JOIN booking b ON bd.Book_ID = b.Book_ID
            INNER JOIN Court_data c ON bd.COURT_ID = c.COURT_ID
            WHERE c.VN_ID = ? AND DATE(bd.Start_time) = ?
            AND b.Status_booking = 'Confirmed'
        ");
        $stmt->execute([$venue_id, $today]);
        $booked = $stmt->fetch()['booked'];
        $total  = $total_courts * 8;
        return ['total' => $total, 'available' => max(0, $total - $booked)];
    } catch (PDOException $e) { return ['total' => 0, 'available' => 0]; }
}

$venues = get_all_active_venues($pdo, $search_query, $sort_by);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Courts - CourtBook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .hover-scale { transition: all 0.3s ease; }
        .hover-scale:hover { transform: translateY(-8px); box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .hero-overlay { background: linear-gradient(135deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.4) 100%); }
        @keyframes fadeInUp { from{opacity:0;transform:translateY(30px)} to{opacity:1;transform:translateY(0)} }
        .animate-fade-in-up { animation: fadeInUp 0.8s ease-out forwards; }
        .available   { background:#dcfce7; color:#166534; }
        .limited     { background:#fef3c7; color:#92400e; }
        .unavailable { background:#fee2e2; color:#991b1b; }
        .maintaining { background:#fef3c7; color:#92400e; }
        #suggestions-booking {
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
        .suggestion-item:hover, .suggestion-item.active { background:#eff6ff; }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>

    <!-- Hero + Search -->
    <section class="relative text-white bg-center bg-cover bg-no-repeat min-h-[55vh] flex items-center"
        style="background-image: url('../../assets/images/BookingBG.png');">
        <div class="absolute inset-0 hero-overlay"></div>
        <div class="relative z-10 w-full animate-fade-in-up">
            <div class="max-w-3xl mx-auto px-4 text-center">
                <h1 class="text-4xl md:text-6xl font-extrabold mb-4 leading-tight drop-shadow-lg">
                    Find Your <span class="text-yellow-400">Court</span>
                </h1>
                <p class="text-lg text-gray-200 mb-8">Search by venue name or address</p>

                <!-- Single Search -->
                <form action="index.php" method="GET" onsubmit="closeSuggestions('booking')">
                    <div class="bg-white rounded-2xl shadow-2xl p-3 flex items-center gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-base z-10 pointer-events-none"></i>
                            <input type="text" name="search" id="searchInput-booking"
                                   placeholder="e.g. CourtBook Arena, Vientiane..."
                                   value="<?= htmlspecialchars($search_query) ?>"
                                   autocomplete="off"
                                   class="w-full pl-11 pr-4 py-3.5 text-gray-800 text-base rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-400"
                                   oninput="fetchSuggestions('booking', this.value)"
                                   onkeydown="handleKey('booking', event)">
                            <div id="suggestions-booking"></div>
                        </div>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-7 py-3.5 rounded-xl transition shadow flex-shrink-0">
                            <i class="fas fa-search mr-1"></i> Search
                        </button>
                    </div>

                    <!-- Sort (compact, below search) -->
                    <div class="flex items-center justify-end gap-2 mt-3">
                        <span class="text-white text-sm opacity-75">Sort:</span>
                        <select name="sort" onchange="this.form.submit()"
                                class="bg-white/20 hover:bg-white/30 text-white border border-white/30 rounded-lg px-3 py-1.5 text-sm backdrop-blur focus:outline-none">
                            <option value="name"       <?= $sort_by==='name'       ?'selected':'' ?> class="text-gray-800">Name (A–Z)</option>
                            <option value="price_low"  <?= $sort_by==='price_low'  ?'selected':'' ?> class="text-gray-800">Price: Low → High</option>
                            <option value="price_high" <?= $sort_by==='price_high' ?'selected':'' ?> class="text-gray-800">Price: High → Low</option>
                            <option value="newest"     <?= $sort_by==='newest'     ?'selected':'' ?> class="text-gray-800">Newest First</option>
                        </select>
                    </div>
                </form>

                <?php if ($is_searching): ?>
                    <p class="mt-3 text-blue-200 text-sm">
                        Results for "<strong><?= htmlspecialchars($search_query) ?></strong>"
                        — <a href="index.php" class="underline hover:text-white">Clear</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Venues Grid -->
    <section class="px-4 py-12 bg-white">
        <div class="max-w-7xl mx-auto">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h2 class="text-2xl font-extrabold text-gray-800">
                        <?= $is_searching ? 'Search Results' : 'All Courts' ?>
                    </h2>
                    <p class="text-gray-500 text-sm mt-1"><?= count($venues) ?> venue<?= count($venues)!=1?'s':'' ?> found</p>
                </div>
                <?php if ($is_searching): ?>
                    <a href="index.php" class="text-sm text-blue-600 hover:underline">Show all courts</a>
                <?php endif; ?>
            </div>

            <?php if (!empty($venues)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <?php foreach ($venues as $venue):
                        $is_maintaining = $venue['VN_Status'] === 'Maintaining';
                        $price_clean    = preg_replace('/[^0-9]/', '', $venue['Price_per_hour']);
                        $price_display  = !empty($price_clean) ? number_format($price_clean) : 'Contact';
                        $slots          = get_available_slots($pdo, $venue['VN_ID']);
                        $venue_img      = !empty($venue['VN_Image'])
                            ? '../../assets/images/venues/' . basename($venue['VN_Image'])
                            : '../../assets/images/BookingBG.png';

                        $badge_class = $badge_text = $badge_icon = '';
                        if ($is_maintaining) {
                            $badge_class = 'maintaining'; $badge_text = 'Under Maintenance'; $badge_icon = 'fa-tools';
                        } elseif ($slots['total'] > 0) {
                            $pct = ($slots['available'] / $slots['total']) * 100;
                            if ($slots['available'] == 0)  { $badge_class='unavailable'; $badge_text='Fully Booked';              $badge_icon='fa-times-circle'; }
                            elseif ($pct > 50)             { $badge_class='available';   $badge_text=$slots['available'].' slots'; $badge_icon='fa-check-circle'; }
                            else                           { $badge_class='limited';     $badge_text='Only '.$slots['available'].' left'; $badge_icon='fa-exclamation-circle'; }
                        }
                    ?>
                        <div class="bg-white rounded-2xl overflow-hidden shadow-lg border <?= $is_maintaining?'border-yellow-300':'border-gray-100' ?> hover-scale">
                            <div class="relative h-52">
                                <img src="<?= htmlspecialchars($venue_img) ?>"
                                     alt="<?= htmlspecialchars($venue['VN_Name']) ?>"
                                     class="w-full h-full object-cover <?= $is_maintaining?'opacity-75':'' ?>"
                                     onerror="this.src='../../assets/images/BookingBG.png'">
                                <?php if (!empty($badge_class)): ?>
                                    <span class="absolute top-3 right-3 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold <?= $badge_class ?>">
                                        <i class="fas <?= $badge_icon ?>"></i><?= $badge_text ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="p-5">
                                <h3 class="font-bold text-lg text-gray-800 mb-1"><?= htmlspecialchars($venue['VN_Name']) ?></h3>
                                <p class="text-gray-500 text-sm mb-2 flex items-center gap-1">
                                    <i class="fas fa-map-marker-alt text-red-400"></i><?= htmlspecialchars($venue['VN_Address']) ?>
                                </p>
                                <p class="text-gray-400 text-xs mb-3 line-clamp-2"><?= htmlspecialchars($venue['VN_Description']) ?></p>

                                <?php if ($is_maintaining): ?>
                                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-2.5 mb-3">
                                        <p class="text-yellow-700 text-xs font-semibold">
                                            <i class="fas fa-tools mr-1"></i>Temporarily unavailable for booking
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <div class="flex items-center gap-3 text-xs text-gray-500 mb-4">
                                    <span><i class="fas fa-table-tennis mr-1 text-green-500"></i><?= $venue['total_courts'] ?> courts</span>
                                    <span><i class="fas fa-clock mr-1 text-blue-500"></i><?= $venue['Open_time'] ?> - <?= $venue['Close_time'] ?></span>
                                </div>
                                <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                                    <div>
                                        <p class="text-xs text-gray-400">From</p>
                                        <p class="text-xl font-extrabold text-green-600">₭<?= $price_display ?><span class="text-xs text-gray-400 font-normal">/hr</span></p>
                                    </div>
                                    <?php if ($is_maintaining): ?>
                                        <span class="bg-yellow-100 text-yellow-700 px-4 py-2 rounded-xl font-semibold text-sm">
                                            <i class="fas fa-tools mr-1"></i>Maintenance
                                        </span>
                                    <?php elseif (isset($_SESSION['user_id']) && $_SESSION['user_type'] === 'customer'): ?>
                                        <a href="venue_detail.php?id=<?= $venue['VN_ID'] ?>"
                                           class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-xl font-semibold text-sm transition">
                                            Book Now
                                        </a>
                                    <?php else: ?>
                                        <a href="/Badminton_court_Booking/auth/login.php?redirect=<?= urlencode('/Badminton_court_Booking/customer/booking_court/venue_detail.php?id='.$venue['VN_ID']) ?>"
                                           class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-xl font-semibold text-sm transition">
                                            Book Now
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-20 bg-gray-50 rounded-2xl border-2 border-dashed border-gray-200">
                    <i class="fas fa-search text-7xl text-gray-200 mb-5 block"></i>
                    <h3 class="text-xl font-bold text-gray-600 mb-2">
                        <?= $is_searching ? 'No courts match your search' : 'No venues available' ?>
                    </h3>
                    <p class="text-gray-400 mb-5">
                        <?= $is_searching ? 'Try a different name or address' : 'Please check back later' ?>
                    </p>
                    <?php if ($is_searching): ?>
                        <a href="index.php" class="inline-block bg-blue-600 text-white px-6 py-2.5 rounded-xl hover:bg-blue-700 transition font-semibold">
                            Show All Courts
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php include '../includes/footer.php'; ?>

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
                <div class="bg-blue-100 w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-store text-blue-600 text-xs"></i>
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
            highlightSuggestion(id, items); e.preventDefault();
        } else if (e.key === 'ArrowUp') {
            state.activeIndex = Math.max(state.activeIndex - 1, 0);
            highlightSuggestion(id, items); e.preventDefault();
        } else if (e.key === 'Enter' && state.activeIndex >= 0) {
            pickSuggestion(id, state.activeIndex); e.preventDefault();
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
        ['home','booking'].forEach(id => {
            const input = document.getElementById(`searchInput-${id}`);
            const box   = document.getElementById(`suggestions-${id}`);
            if (box && input && !input.contains(e.target) && !box.contains(e.target)) closeSuggestions(id);
        });
    });
    </script>
</body>
</html>