<?php
// ── Environment detection ──
// Set to 'local' for development, 'live' for production
$env = 'local';

if ($env === 'local') {
    $host     = 'localhost';
    $dbname   = 'Badminton_booking';
    $username = 'root';
    $password = '';
} else {
    // ── Fill these in when you deploy to hosting ──
    $host     = 'your_live_host';      // e.g. sql200.infinityfree.com
    $dbname   = 'your_live_dbname';    // e.g. if0_12345678_badminton
    $username = 'your_live_username';  // provided by host
    $password = 'your_live_password';  // provided by host
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

function get_user_by_login($login, $table) {
    global $pdo;
    $allowed = ['admin', 'court_owner', 'customer'];
    if (!in_array($table, $allowed)) return false;

    try {
        if ($table === 'admin') {
            $stmt = $pdo->prepare("SELECT * FROM `admin` WHERE LOWER(Username) = LOWER(?) LIMIT 1");
            $stmt->execute([$login]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE LOWER(Email) = LOWER(?) OR LOWER(Username) = LOWER(?) LIMIT 1");
            $stmt->execute([$login, $login]);
        }

        $user = $stmt->fetch();

        if ($user) {
            if ($table === 'admin') {
                $user['email']    = $user['Username'];
                $user['name']     = $user['Name'] . ' ' . $user['Surname'];
                $user['id']       = $user['Admin_ID'];
                $user['password'] = $user['Password'];
                $user['Status']   = 'Active';
            } elseif ($table === 'court_owner') {
                $user['email']    = $user['Email'];
                $user['name']     = $user['Name'];
                $user['id']       = $user['CA_ID'];
                $user['password'] = $user['Password'];
            } elseif ($table === 'customer') {
                $user['email']    = $user['Email'];
                $user['name']     = $user['Name'];
                $user['id']       = $user['C_ID'];
                $user['password'] = $user['Password'];
            }
        }
        return $user;

    } catch (PDOException $e) {
        error_log("get_user_by_login error: " . $e->getMessage());
        return false;
    }
}

function get_user_by_email($email, $table) {
    global $pdo;
    $allowed = ['admin', 'court_owner', 'customer'];
    if (!in_array($table, $allowed)) return false;
    try {
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE LOWER(Email) = LOWER(?) LIMIT 1");
        $stmt->execute([$email]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("get_user_by_email error: " . $e->getMessage());
        return false;
    }
}

function get_user_by_username($username, $table) {
    global $pdo;
    $allowed = ['admin', 'court_owner', 'customer'];
    if (!in_array($table, $allowed)) return false;
    try {
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE Username = ? LIMIT 1");
        $stmt->execute([$username]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("get_user_by_username error: " . $e->getMessage());
        return false;
    }
}

function get_user_by_id($id, $table) {
    global $pdo;
    $allowed = ['admin', 'court_owner', 'customer'];
    if (!in_array($table, $allowed)) return false;
    $id_col = match($table) {
        'admin'       => 'Admin_ID',
        'court_owner' => 'CA_ID',
        'customer'    => 'C_ID',
    };
    try {
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$id_col}` = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("get_user_by_id error: " . $e->getMessage());
        return false;
    }
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// ── Surname removed — only Name is used ──
function create_customer($name, $gender, $phone, $email, $username, $password) {
    global $pdo;
    try {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO customer (Name, Gender, Phone, Email, Username, Password) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $gender, $phone, $email, $username, $hashed]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("create_customer error: " . $e->getMessage());
        return false;
    }
}

function create_court_owner($name, $phone, $email, $username, $password) {
    global $pdo;
    try {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO court_owner (Name, Phone, Email, Username, Password) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $phone, $email, $username, $hashed]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("create_court_owner error: " . $e->getMessage());
        return false;
    }
}

function create_admin($name, $surname, $gender, $image_pay, $username, $password) {
    global $pdo;
    try {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO admin (Name, Surname, Gender, Image_pay, Username, Password) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $surname, $gender, $image_pay, $username, $hashed]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("create_admin error: " . $e->getMessage());
        return false;
    }
}

function update_password($id, $table, $new_password) {
    global $pdo;
    $allowed = ['admin', 'court_owner', 'customer'];
    if (!in_array($table, $allowed)) return false;
    $id_col = match($table) {
        'admin'       => 'Admin_ID',
        'court_owner' => 'CA_ID',
        'customer'    => 'C_ID'
    };
    try {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE `{$table}` SET Password = ? WHERE `{$id_col}` = ?");
        return $stmt->execute([$hashed, $id]);
    } catch (PDOException $e) {
        error_log("update_password error: " . $e->getMessage());
        return false;
    }
}

function update_customer($id, $data) {
    global $pdo;
    $allowed_fields = ['Name', 'Gender', 'Phone', 'Email', 'Username'];
    $fields = []; $values = [];
    foreach ($data as $key => $value) {
        if (in_array($key, $allowed_fields)) { $fields[] = "`{$key}` = ?"; $values[] = $value; }
    }
    if (empty($fields)) return false;
    $values[] = $id;
    try {
        $stmt = $pdo->prepare("UPDATE customer SET " . implode(', ', $fields) . " WHERE C_ID = ?");
        return $stmt->execute($values);
    } catch (PDOException $e) {
        error_log("update_customer error: " . $e->getMessage());
        return false;
    }
}

function get_all_venues() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT v.*, co.Name AS owner_name FROM Venue_data v LEFT JOIN court_owner co ON v.CA_ID = co.CA_ID ORDER BY v.VN_ID DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("get_all_venues error: " . $e->getMessage());
        return [];
    }
}

function get_venue_by_id($id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT v.*, co.Name AS owner_name, co.Phone AS owner_phone FROM Venue_data v LEFT JOIN court_owner co ON v.CA_ID = co.CA_ID WHERE v.VN_ID = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("get_venue_by_id error: " . $e->getMessage());
        return false;
    }
}

function count_users($table) {
    global $pdo;
    $allowed = ['admin', 'court_owner', 'customer'];
    if (!in_array($table, $allowed)) return 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$table}`");
        return (int)$stmt->fetch()['count'];
    } catch (PDOException $e) {
        return 0;
    }
}
?>