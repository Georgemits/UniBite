<?php
/**
 * Κοινό bootstrap για όλα τα API endpoints.
 *
 * Το αρχείο αυτό:
 *   - ξεκινάει session
 *   - θέτει Content-Type: application/json; charset=utf-8
 *   - επιτρέπει CORS για local development
 *   - συνδέεται στη ΒΔ
 *   - παρέχει κοινά helpers (json_out, require_login, require_admin, input_json)
 */

// Εμφάνιση σφαλμάτων μόνο στα logs (όχι στο response JSON)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// CORS (για ευκολία σε dev – θεωρούμε το frontend στο ίδιο origin, αλλά υποστηρίζουμε και αλλιώς)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Session
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../config/db.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function json_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $status = 400, array $extra = []): void {
    json_out(array_merge(['ok' => false, 'error' => $msg], $extra), $status);
}

function ok(array $data = []): void {
    json_out(array_merge(['ok' => true], $data));
}

/** Διαβάζει JSON body από το request */
function input_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function current_user_id(): ?int {
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
}

function current_user(): ?array {
    $uid = current_user_id();
    if (!$uid) return null;
    $stmt = db()->prepare('SELECT id, username, email, full_name, role, points, default_lat, default_lng, created_at FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) fail('Απαιτείται σύνδεση', 401);
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if (($u['role'] ?? '') !== 'admin') fail('Απαιτούνται δικαιώματα διαχειριστή', 403);
    return $u;
}

/**
 * Υπολογισμός κατάστασης αγγελίας:
 *   - active   : < 48h και available_portions > 0
 *   - inactive : < 48h και available_portions == 0
 *   - deleted  : >= 48h (ανεξαρτήτως διαθεσιμότητας)
 */
function listing_state(array $listing): string {
    $createdAt = strtotime($listing['created_at']);
    if ($createdAt === false) return 'deleted';
    $diffHours = (time() - $createdAt) / 3600.0;
    if ($diffHours >= 48) return 'deleted';
    if ((int)$listing['available_portions'] <= 0) return 'inactive';
    return 'active';
}

/** Υπολογίζει απόσταση σε km με τύπο Haversine */
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371.0; // ακτίνα Γης σε km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

/** Αύξηση/μείωση πόντων με audit trail */
function adjust_points(int $userId, int $delta, string $reason,
                       ?string $relatedType = null, ?int $relatedId = null): void {
    $pdo = db();
    $pdo->prepare('UPDATE users SET points = GREATEST(0, points + ?) WHERE id = ?')
        ->execute([$delta, $userId]);
    $pdo->prepare('INSERT INTO point_transactions (user_id, delta, reason, related_type, related_id)
                   VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $delta, $reason, $relatedType, $relatedId]);
}

/**
 * Εφαρμόζει ποινή σε χρήστες που δεν άφησαν αξιολόγηση εντός 48 ωρών
 * από την παραλαβή της μερίδας. Καλείται σε κάθε API request ώστε να
 * ενημερώνονται οι πόντοι χωρίς ανάγκη cron job.
 */
function apply_missing_rating_penalties(): void {
    $pdo = db();
    // Βρες picked_up requests > 48h χωρίς rating και χωρίς ήδη καταγεγραμμένη ποινή.
    $sql = "
        SELECT r.id, r.consumer_id
          FROM requests r
     LEFT JOIN ratings rt ON rt.request_id = r.id
     LEFT JOIN point_transactions pt
            ON pt.related_type = 'missing_rating' AND pt.related_id = r.id
         WHERE r.status = 'picked_up'
           AND r.picked_up_at IS NOT NULL
           AND TIMESTAMPDIFF(HOUR, r.picked_up_at, NOW()) >= 48
           AND rt.id IS NULL
           AND pt.id IS NULL
    ";
    foreach ($pdo->query($sql) as $row) {
        adjust_points(
            (int)$row['consumer_id'], -1,
            'Δεν αφέθηκε αξιολόγηση εντός 48 ωρών',
            'missing_rating', (int)$row['id']
        );
    }
}
