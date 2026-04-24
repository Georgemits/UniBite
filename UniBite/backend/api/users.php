<?php
/**
 * API: /backend/api/users.php
 *
 * Ενέργειες:
 *   - me_details : GET – Λεπτομέρειες χρήστη + σύνοψη δραστηριότητας (Β4 / πόντοι)
 *   - update_location : POST – ενημέρωση προτιμώμενης τοποθεσίας
 */
require_once __DIR__ . '/../includes/bootstrap.php';
apply_missing_rating_penalties();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($action) {

    case 'me_details': {
        $u = require_login();
        $pdo = db();
        // Πόσες μερίδες έδωσε (picked_up σε αγγελίες του)
        $given = (int)$pdo->prepare("
            SELECT COUNT(*) FROM requests r
              JOIN listings l ON l.id = r.listing_id
             WHERE l.cook_id = ? AND r.status='picked_up'
        ")->execute([$u['id']]) + 0; // placeholder, will query below properly
        $q = $pdo->prepare("SELECT COUNT(*) FROM requests r JOIN listings l ON l.id = r.listing_id
                            WHERE l.cook_id = ? AND r.status='picked_up'");
        $q->execute([$u['id']]);
        $given = (int)$q->fetchColumn();

        $q = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE consumer_id = ? AND status='picked_up'");
        $q->execute([$u['id']]);
        $received = (int)$q->fetchColumn();

        $q = $pdo->prepare("SELECT ROUND(AVG(stars),2) FROM ratings WHERE cook_id = ?");
        $q->execute([$u['id']]);
        $avgRating = $q->fetchColumn();
        $avgRating = ($avgRating !== false && $avgRating !== null) ? (float)$avgRating : null;

        $q = $pdo->prepare("SELECT * FROM point_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
        $q->execute([$u['id']]);
        $txns = $q->fetchAll();

        ok([
            'user' => $u,
            'summary' => [
                'portions_given'    => $given,
                'portions_received' => $received,
                'avg_rating'        => $avgRating,
            ],
            'transactions' => $txns,
        ]);
        break;
    }

    case 'update_location': {
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $data = input_json();
        $lat = isset($data['lat']) ? (float)$data['lat'] : null;
        $lng = isset($data['lng']) ? (float)$data['lng'] : null;
        if ($lat === null || $lng === null) fail('Λείπουν οι συντεταγμένες');
        db()->prepare('UPDATE users SET default_lat=?, default_lng=? WHERE id=?')->execute([$lat, $lng, $u['id']]);
        ok();
        break;
    }

    default:
        fail('Άγνωστη ενέργεια', 404);
}
