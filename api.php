<?php
// api.php - Backend API Tabungan Kelompok
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Mulai Sesi PHP jika diperlukan
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Konfigurasi Database
$db_host = 'localhost'; // Silakan sesuaikan
$db_user = 'root'; // Silakan sesuaikan
$db_pass = 'Pondok@1234';     // Silakan sesuaikan
$db_name = 'kaskelompok';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    // Auto-Migrasi Database: Menambahkan kolom izin
    try { $pdo->exec("ALTER TABLE users ADD COLUMN can_change_password TINYINT(1) NOT NULL DEFAULT 1 AFTER can_input"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER can_change_password"); } catch (PDOException $e) {}
    
    // Auto-Migrasi Database: Membuat tabel Sharing Members jika belum ada
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS shared_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            user_id INT NOT NULL,
            UNIQUE KEY unique_share (member_id, user_id),
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $e) {}
    
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Koneksi database gagal: ' . $e->getMessage()]);
    exit;
}

// Mengambil request body JSON
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

// Helper untuk otentikasi token sederhana
function authenticate($pdo) {
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? $_GET['token'] ?? '';
    if (strpos($token, 'Bearer ') === 0) { $token = substr($token, 7); }
    if (empty($token)) { return false; }
    
    $decoded = base64_decode($token);
    if (!$decoded) return false;
    
    $parts = explode(':', $decoded);
    if (count($parts) < 2) return false;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND username = ? AND is_active = 1");
    $stmt->execute([$parts[0], $parts[1]]);
    return $stmt->fetch();
}

// Routing Aksi API
switch ($action) {
    case 'login':
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Username dan password wajib diisi']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            if ($user['is_active'] == 0) {
                echo json_encode(['status' => 'error', 'message' => 'Akun Anda telah dinonaktifkan oleh Admin']);
                exit;
            }
            $token = base64_encode($user['id'] . ':' . $user['username']);
            unset($user['password']); 
            echo json_encode(['status' => 'success', 'token' => $token, 'user' => $user]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Username atau password salah']);
        }
        break;

    case 'logout':
        echo json_encode(['status' => 'success', 'message' => 'Berhasil logout']);
        break;

    case 'get_all':
        $user = authenticate($pdo);
        if (!$user) {
            header('HTTP/1.0 401 Unauthorized');
            echo json_encode(['status' => 'error', 'message' => 'Sesi tidak valid, silakan login ulang']);
            exit;
        }
        
        // Members: Menampilkan Anggota milik sendiri + Anggota yang dibagikan oleh admin
        $stmtM = $pdo->prepare("
            SELECT m.*, (m.user_id != ?) AS is_shared 
            FROM members m 
            LEFT JOIN shared_members sm ON m.id = sm.member_id 
            WHERE m.user_id = ? OR sm.user_id = ?
            GROUP BY m.id
            ORDER BY m.name ASC
        ");
        $stmtM->execute([$user['id'], $user['id'], $user['id']]);
        $members = $stmtM->fetchAll();
        
        // Transaksi: Menampilkan Transaksi milik sendiri, atau milik anggota yang terkait (dibagikan/dimiliki)
        $stmtT = $pdo->prepare("
            SELECT t.* FROM transactions t
            JOIN members m ON t.member_id = m.id
            LEFT JOIN shared_members sm ON m.id = sm.member_id
            WHERE t.user_id = ? OR m.user_id = ? OR sm.user_id = ?
            GROUP BY t.id
            ORDER BY t.date DESC
        ");
        $stmtT->execute([$user['id'], $user['id'], $user['id']]);
        $transactions = $stmtT->fetchAll();
        
        $stmtN = $pdo->prepare("SELECT * FROM notes WHERE user_id = ? ORDER BY date DESC");
        $stmtN->execute([$user['id']]);
        $notes = $stmtN->fetchAll();
        
        $users = [];
        if ($user['role'] === 'superadmin') {
            $users = $pdo->query("SELECT id, username, role, is_active, can_view, can_input, can_change_password, phone, created_at FROM users ORDER BY username ASC")->fetchAll();
        }
        
        echo json_encode([
            'members' => $members,
            'transactions' => $transactions,
            'notes' => $notes,
            'users' => $users
        ]);
        break;

    case 'add_member':
        $user = authenticate($pdo);
        if (!$user || $user['can_input'] == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Akses ditolak']);
            exit;
        }
        $name = trim($input['name'] ?? '');
        $group_name = trim($input['group_name'] ?? 'Tanpa Grup');
        $address = trim($input['address'] ?? '');
        if (empty($name)) exit;
        
        $stmt = $pdo->prepare("INSERT INTO members (user_id, name, group_name, address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user['id'], $name, $group_name, $address]);
        echo json_encode(['status' => 'success']);
        break;

    case 'update_member':
        $user = authenticate($pdo);
        if (!$user || $user['can_input'] == 0) exit;
        $id = $input['id'] ?? null;
        $name = trim($input['name'] ?? '');
        $group_name = trim($input['group_name'] ?? 'Tanpa Grup');
        $address = trim($input['address'] ?? '');
        if (!$id || empty($name)) exit;
        
        // Proteksi Mutlak: Anggota HANYA bisa diedit oleh pemiliknya
        $check = $pdo->prepare("SELECT id FROM members WHERE id = ? AND user_id = ?");
        $check->execute([$id, $user['id']]);
        if (!$check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Akses ditolak: Anda tidak dapat mengedit anggota yang dibagikan.']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE members SET name = ?, group_name = ?, address = ? WHERE id = ?");
        $stmt->execute([$name, $group_name, $address, $id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'delete_member':
        $user = authenticate($pdo);
        if (!$user || $user['can_input'] == 0) exit;
        $id = $input['id'] ?? null;
        if (!$id) exit;
        
        $check = $pdo->prepare("SELECT id FROM members WHERE id = ? AND user_id = ?");
        $check->execute([$id, $user['id']]);
        if (!$check->fetch()) exit;
        
        $stmt = $pdo->prepare("DELETE FROM members WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'add_transaction':
        $user = authenticate($pdo);
        if (!$user || $user['can_input'] == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Akses ditolak: Anda tidak memiliki izin menginput.']);
            exit;
        }
        $member_id = $input['member_id'] ?? null;
        $type = $input['type'] ?? '';
        $amount = $input['amount'] ?? 0;
        
        $description = trim($input['description'] ?? '');
        $description = str_replace(["\r\n", "\r", "\n"], " ", $description);
        
        if (!$member_id || !in_array($type, ['income', 'expense']) || $amount <= 0 || empty($description)) exit;

        // Pastikan Anggota dimiliki ATAU dibagikan ke user
        $check = $pdo->prepare("
            SELECT m.id 
            FROM members m
            LEFT JOIN shared_members sm ON m.id = sm.member_id
            WHERE m.id = ? AND (m.user_id = ? OR sm.user_id = ?)
        ");
        $check->execute([$member_id, $user['id'], $user['id']]);
        if (!$check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Anggota tidak ditemukan / tidak ada izin']);
            exit;
        }
        
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, member_id, type, amount, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user['id'], $member_id, $type, $amount, $description]);
        echo json_encode(['status' => 'success']);
        break;

    case 'update_transaction':
        $user = authenticate($pdo);
        if (!$user || $user['can_input'] == 0) exit;
        $id = $input['id'] ?? null;
        $type = $input['type'] ?? '';
        $amount = $input['amount'] ?? 0;
        $description = trim($input['description'] ?? '');
        $description = str_replace(["\r\n", "\r", "\n"], " ", $description);
        
        if (!$id || !in_array($type, ['income', 'expense']) || $amount <= 0 || empty($description)) exit;
        
        // Izin Update: Pengguna membuatnya, ATAU ia adalah pemilik member tersebut
        $check = $pdo->prepare("
            SELECT t.id 
            FROM transactions t
            JOIN members m ON t.member_id = m.id
            WHERE t.id = ? AND (t.user_id = ? OR m.user_id = ?)
        ");
        $check->execute([$id, $user['id'], $user['id']]);
        if (!$check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Anda tidak berhak mengedit transaksi ini.']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE transactions SET type = ?, amount = ?, description = ? WHERE id = ?");
        $stmt->execute([$type, $amount, $description, $id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'delete_transaction':
        $user = authenticate($pdo);
        if (!$user || $user['can_input'] == 0) exit;
        $id = $input['id'] ?? null;
        if (!$id) exit;
        
        $check = $pdo->prepare("
            SELECT t.id 
            FROM transactions t
            JOIN members m ON t.member_id = m.id
            WHERE t.id = ? AND (t.user_id = ? OR m.user_id = ?)
        ");
        $check->execute([$id, $user['id'], $user['id']]);
        if (!$check->fetch()) exit;
        
        $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'get_member_shares':
        $user = authenticate($pdo);
        if (!$user || $user['role'] !== 'superadmin') exit;
        $member_id = $input['member_id'] ?? null;
        
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, 
                   (CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END) as is_shared
            FROM users u
            LEFT JOIN shared_members sm ON u.id = sm.user_id AND sm.member_id = ?
            WHERE u.id != ? AND u.is_active = 1
            ORDER BY u.username ASC
        ");
        $stmt->execute([$member_id, $user['id']]);
        echo json_encode(['status' => 'success', 'users' => $stmt->fetchAll()]);
        break;

    case 'save_member_shares':
        $user = authenticate($pdo);
        if (!$user || $user['role'] !== 'superadmin') exit;
        $member_id = $input['member_id'] ?? null;
        $shared_user_ids = $input['shared_user_ids'] ?? [];
        
        if (!$member_id) exit;
        
        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM shared_members WHERE member_id = ?");
        $del->execute([$member_id]);
        
        if (!empty($shared_user_ids)) {
            $ins = $pdo->prepare("INSERT INTO shared_members (member_id, user_id) VALUES (?, ?)");
            foreach($shared_user_ids as $uid) {
                $ins->execute([$member_id, $uid]);
            }
        }
        $pdo->commit();
        echo json_encode(['status' => 'success']);
        break;

    case 'add_note':
        $user = authenticate($pdo);
        if (!$user || $user['can_input'] == 0) exit;
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $completed = $input['completed'] ?? 0;
        $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, description, completed, date) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$user['id'], $title, $description, $completed]);
        echo json_encode(['status' => 'success']);
        break;

    case 'update_note':
        $user = authenticate($pdo);
        if (!$user || $user['can_input'] == 0) exit;
        $id = $input['id'] ?? null;
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $completed = $input['completed'] ?? null;
        if (!$id) exit;
        $check = $pdo->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ?");
        $check->execute([$id, $user['id']]);
        if (!$check->fetch()) exit;
        if ($completed !== null) { $stmt = $pdo->prepare("UPDATE notes SET completed = ? WHERE id = ?"); $stmt->execute([$completed, $id]); } 
        else { $stmt = $pdo->prepare("UPDATE notes SET title = ?, description = ? WHERE id = ?"); $stmt->execute([$title, $description, $id]); }
        echo json_encode(['status' => 'success']);
        break;

    case 'delete_note':
        $user = authenticate($pdo);
        if (!$user || $user['can_input'] == 0) exit;
        $id = $input['id'] ?? null;
        $check = $pdo->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ?");
        $check->execute([$id, $user['id']]);
        if (!$check->fetch()) exit;
        $stmt = $pdo->prepare("DELETE FROM notes WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
        break;

    // --- MANAJEMEN USER ---
    case 'add_user':
        $user = authenticate($pdo);
        if (!$user || $user['role'] !== 'superadmin') exit;
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? 'user';
        $phone = trim($input['phone'] ?? '');
        if (empty($username) || empty($password)) exit;
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) { echo json_encode(['status' => 'error', 'message' => 'Username sudah terdaftar']); exit; }
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role, is_active, can_view, can_input, can_change_password, phone) VALUES (?, ?, ?, 1, 1, 1, 1, ?)");
        $stmt->execute([$username, $hashed, $role, $phone]);
        echo json_encode(['status' => 'success']);
        break;

    case 'update_user_detail_admin':
        $user = authenticate($pdo);
        if (!$user || $user['role'] !== 'superadmin') exit;
        $id = $input['id'] ?? null;
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $phone = trim($input['phone'] ?? '');
        if (!$id || empty($username)) exit;
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->execute([$username, $id]);
        if ($check->fetch()) { echo json_encode(['status' => 'error', 'message' => 'Username telah digunakan user lain']); exit; }
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, phone = ? WHERE id = ?");
            $stmt->execute([$username, $hashed, $phone, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, phone = ? WHERE id = ?");
            $stmt->execute([$username, $phone, $id]);
        }
        echo json_encode(['status' => 'success']);
        break;

    case 'update_user_perm':
        $user = authenticate($pdo);
        if (!$user || $user['role'] !== 'superadmin') exit;
        $id = $input['id'] ?? null;
        $field = $input['field'] ?? '';
        $val = $input['value'] ?? 0;
        if (!$id || !in_array($field, ['is_active', 'can_input', 'can_change_password'])) exit;
        if ($id == $user['id'] && $field === 'is_active' && $val == 0) exit;
        $stmt = $pdo->prepare("UPDATE users SET $field = ? WHERE id = ?");
        $stmt->execute([$val, $id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'delete_user':
        $user = authenticate($pdo);
        if (!$user || $user['role'] !== 'superadmin') exit;
        $id = $input['id'] ?? null;
        if ($id == $user['id']) exit;
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'change_password':
        $user = authenticate($pdo);
        if (!$user) exit;
        if ($user['role'] !== 'superadmin' && isset($user['can_change_password']) && $user['can_change_password'] == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Anda tidak memiliki izin.']); exit;
        }
        $current = $input['current_password'] ?? '';
        $new_pass = $input['new_password'] ?? '';
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $currHash = $stmt->fetchColumn();
        if (!password_verify($current, $currHash)) { echo json_encode(['status' => 'error', 'message' => 'Password lama salah']); exit; }
        $newHash = password_hash($new_pass, PASSWORD_BCRYPT);
        $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->execute([$newHash, $user['id']]);
        echo json_encode(['status' => 'success']);
        break;

    case 'import_db':
        $user = authenticate($pdo);
        if (!$user) exit;
        
        $membersData = $input['members'] ?? [];
        $txsData = $input['transactions'] ?? [];
        $notesData = $input['notes'] ?? [];
        
        try {
            $pdo->beginTransaction();
            $memberMap = [];
            $checkMember = $pdo->prepare("SELECT id FROM members WHERE user_id = ? AND name = ?");
            $insertMember = $pdo->prepare("INSERT INTO members (user_id, name, group_name, address, created_at) VALUES (?, ?, ?, ?, ?)");
            
            foreach($membersData as $m) {
                $name = trim($m['name'] ?? '');
                if(empty($name)) continue;
                $checkMember->execute([$user['id'], $name]);
                $existing = $checkMember->fetch();
                
                if($existing) { $memberMap[$name] = $existing['id']; } 
                else {
                    $insertMember->execute([$user['id'], $name, $m['group_name'] ?? 'Tanpa Grup', $m['address'] ?? '', $m['created_at'] ?? date('Y-m-d H:i:s')]);
                    $memberMap[$name] = $pdo->lastInsertId();
                }
            }
            
            $insertTx = $pdo->prepare("INSERT INTO transactions (user_id, member_id, type, amount, description, date) VALUES (?, ?, ?, ?, ?, ?)");
            foreach($txsData as $t) {
                $mName = trim($t['member_name'] ?? '');
                if(empty($mName)) continue;
                if(!isset($memberMap[$mName])) {
                    $checkMember->execute([$user['id'], $mName]);
                    $existing = $checkMember->fetch();
                    if($existing) { $memberMap[$mName] = $existing['id']; } 
                    else {
                        $insertMember->execute([$user['id'], $mName, 'Tanpa Grup', '', date('Y-m-d H:i:s')]);
                        $memberMap[$mName] = $pdo->lastInsertId();
                    }
                }
                
                $tDesc = str_replace(["\r\n", "\r", "\n"], " ", $t['description'] ?? '');
                $insertTx->execute([$user['id'], $memberMap[$mName], $t['type'] ?? 'income', $t['amount'] ?? 0, $tDesc, $t['date'] ?? date('Y-m-d H:i:s')]);
            }
            
            $insertNote = $pdo->prepare("INSERT INTO notes (user_id, title, description, completed, date) VALUES (?, ?, ?, ?, ?)");
            foreach($notesData as $n) {
                $title = trim($n['title'] ?? '');
                if(empty($title)) continue;
                $insertNote->execute([$user['id'], $title, $n['description'] ?? '', $n['completed'] ?? 0, $n['date'] ?? date('Y-m-d H:i:s')]);
            }
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Database berhasil diimpor']);
        } catch (Exception $err) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Gagal mengimpor database']);
        }
        break;
}