<?php
/**
 * API: /backend/api/auth.php
 * Υποστηριζόμενες ενέργειες (μέσω ?action=...):
 *   - register : POST    – εγγραφή νέου φοιτητή
 *   - login    : POST    – σύνδεση
 *   - logout   : POST    – αποσύνδεση
 *   - me       : GET     – επιστροφή στοιχείων συνδεδεμένου χρήστη
 */
require_once __DIR__ . '/../includes/bootstrap.php';
apply_missing_rating_penalties();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($action) {
    // -----------------------------------------------------------------------
    case 'register':
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $data = input_json();
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');
        $fullName = trim($data['full_name'] ?? '');
        $lat = isset($data['default_lat']) ? (float)$data['default_lat'] : null;
        $lng = isset($data['default_lng']) ? (float)$data['default_lng'] : null;

        if ($username === '' || strlen($username) < 3 || strlen($username) > 50) {
            fail('Μη έγκυρο username (3-50 χαρακτήρες)');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Μη έγκυρο email');
        if (strlen($password) < 6) fail('Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες');
        if ($fullName === '') fail('Παρακαλώ δηλώστε το ονοματεπώνυμό σας');

        try {
            $stmt = db()->prepare('INSERT INTO users
                (username, email, password_hash, full_name, role, points, default_lat, default_lng)
                VALUES (?, ?, ?, ?, "student", 5, ?, ?)');
            $stmt->execute([
                $username, $email,
                password_hash($password, PASSWORD_BCRYPT),
                $fullName, $lat, $lng,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                fail('Το username ή το email χρησιμοποιείται ήδη', 409);
            }
            fail('Σφάλμα εγγραφής', 500);
        }
        $uid = (int)db()->lastInsertId();
        adjust_points($uid, 0, 'Αρχικοί πόντοι εγγραφής (5)', 'signup', null);
        // Στην πραγματικότητα έχουμε ήδη θέσει points=5 στο INSERT, απλώς
        // καταγράφουμε transaction audit για λόγους διαφάνειας.
        $_SESSION['uid'] = $uid;
        ok(['user' => current_user()]);
        break;

    // -----------------------------------------------------------------------
    case 'login':
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $data = input_json();
        $id = trim($data['identifier'] ?? $data['username'] ?? '');
        $pw = (string)($data['password'] ?? '');
        if ($id === '' || $pw === '') fail('Συμπληρώστε όλα τα πεδία');
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$id, $id]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pw, $user['password_hash'])) {
            fail('Λάθος στοιχεία σύνδεσης', 401);
        }
        $_SESSION['uid'] = (int)$user['id'];
        unset($user['password_hash']);
        ok(['user' => $user]);
        break;

    // -----------------------------------------------------------------------
    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        ok();
        break;

    // -----------------------------------------------------------------------
    case 'me':
        $u = current_user();
        if (!$u) fail('Μη συνδεδεμένος', 401);
        ok(['user' => $u]);
        break;

    default:
        fail('Άγνωστη ενέργεια', 404);
}
