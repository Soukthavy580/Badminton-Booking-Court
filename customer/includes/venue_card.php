<div class="bg-white rounded-2xl overflow-hidden shadow-md border border-gray-100 hover-scale">
    <div class="relative h-48">
        <img src="<?= htmlspecialchars($venue_img) ?>"
             alt="<?= htmlspecialchars($venue['VN_Name']) ?>"
             class="w-full h-full object-cover"
             onerror="this.src='/Badminton_court_Booking/assets/images/BookingBG.png'">
    </div>
    <div class="p-5">
        <h3 class="font-bold text-lg text-gray-800 mb-1"><?= htmlspecialchars($venue['VN_Name']) ?></h3>
        <p class="text-gray-500 text-sm mb-2 flex items-center gap-1">
            <i class="fas fa-map-marker-alt text-red-400"></i>
            <?= htmlspecialchars($venue['VN_Address']) ?>
        </p>
        <p class="text-gray-400 text-xs mb-3 line-clamp-2"><?= htmlspecialchars($venue['VN_Description'] ?? '') ?></p>
        <div class="flex items-center gap-3 text-xs text-gray-500 mb-4">
            <span><i class="fas fa-table-tennis mr-1 text-green-500"></i><?= $venue['total_courts'] ?> courts</span>
            <span><i class="fas fa-clock mr-1 text-blue-500"></i><?= $venue['Open_time'] ?> - <?= $venue['Close_time'] ?></span>
        </div>
        <div class="flex items-center justify-between pt-3 border-t border-gray-100">
            <div>
                <p class="text-xs text-gray-400">From</p>
                <p class="text-lg font-extrabold text-green-600">
                    ₭<?= number_format($price_clean) ?>
                    <span class="text-xs text-gray-400 font-normal">/hr</span>
                </p>
            </div>
            <?php if ($is_logged_in): ?>
                <a href="/Badminton_court_Booking/customer/booking_court/venue_detail.php?id=<?= $venue['VN_ID'] ?>&date=<?= $search_date ?>"
                   class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-xl font-semibold text-sm transition">
                    Book Now
                </a>
            <?php else: ?>
                <a href="/Badminton_court_Booking/auth/login.php?redirect=<?= urlencode('/Badminton_court_Booking/customer/booking_court/venue_detail.php?id='.$venue['VN_ID'].'&date='.$search_date) ?>"
                   class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-xl font-semibold text-sm transition">
                    Book Now
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>