<?php
// Auto-expire packages and ads when End_time has passed
try {
    $pdo->exec("
        UPDATE buy_package
        SET Status_Package = 'Expired'
        WHERE Status_Package = 'Active'
        AND End_time < NOW()
    ");
} catch (PDOException $e) {
    error_log("Auto-expire packages error: " . $e->getMessage());
}

try {
    $pdo->exec("
        UPDATE advertisement
        SET Status_AD = 'Expired'
        WHERE Status_AD IN ('Active','Approved')
        AND End_time < NOW()
    ");
} catch (PDOException $e) {
    error_log("Auto-expire ads error: " . $e->getMessage());
}
?>