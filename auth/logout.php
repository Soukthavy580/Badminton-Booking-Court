<?php
session_start();
session_unset();
session_destroy();

// Clear remember me cookies
setcookie('remember_user', '', time() - 3600, '/');
setcookie('remember_role', '', time() - 3600, '/');

header('Location: /Badminton_court_Booking/auth/login?logout=success');
exit;
?>