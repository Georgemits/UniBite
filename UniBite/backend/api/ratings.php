<?php
/**
 * API: /backend/api/ratings.php
 *
 * Ενέργειες:
 *   - create : POST – Ο consumer καταχωρεί rating για αίτημα picked_up (Γ3)
 *              Ταυτόχρονα ο cook κερδίζει πόντους (Β4):
 *                1 πόντος ανά rating
 *                +1 επιπλέον αν stars > 3
 *   - listing : GET – Επιστρέφει τα ratings μιας συγκεκριμένης αγγελίας (?listing_id=)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
apply_missing_rating_penalties();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($action) {
    // ---------------------------------------------------------------- Γ3
    case 'create':
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $data = input_json();
        $rid   = (int)($data['request_id'] ?? 0);
        $stars = (int)($data['stars'] ?? 0);
        $comment = trim((string)($data['comment'] ?? ''));
        if ($rid <= 0) fail('Λείπει το request_id');
        if ($stars < 1 || $stars > 5) fail('Η βαθμολογία πρέπει να είναι 1-5');

        $pdo = db();
        $r = $pdo->prepare('SELECT r.*, l.cook_id FROM requests r JOIN listings l ON l.id = r.listing_id WHERE r.id = ?');
        $r->execute([$rid]);
        $req = $r->fetch();
        if (!$req) fail('Δεν βρέθηκε το αίτημα', 404);
        if ((int)$req['consumer_id'] !== (int)$u['id']) fail('Δεν επιτρέπεται', 403);
        if ($req['status'] !== 'picked_up') fail('Μπορείτε να αξιολογήσετε μόνο παραληφθείσες μερίδες');

        $exists = $pdo->prepare('SELECT id FROM ratings WHERE request_id = ?');
        $exists->execute([$rid]);
        if ($exists->fetch()) fail('Έχετε ήδη αξιολογήσει αυτή τη μερίδα', 409);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO ratings (request_id, listing_id, cook_id, consumer_id, stars, comment)
                           VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $rid, $req['listing_id'], $req['cook_id'], $u['id'], $stars,
                    $comment !== '' ? $comment : null
                ]);
            $ratingId = (int)$pdo->lastInsertId();
            // Β4: 1 πόντος στον cook + 1 επιπλέον αν stars > 3
            $cookPts = 1 + ($stars > 3 ? 1 : 0);
            adjust_points(
                (int)$req['cook_id'], $cookPts,
                "Αξιολόγηση $stars/5 από χρήστη",
                'rating', $ratingId
            );
            $pdo->commit();
            ok([
                'rating_id'        => $ratingId,
                'cook_points_added' => $cookPts,
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            fail('Αποτυχία καταχώρησης αξιολόγησης', 500);
        }
        break;

    // ----------------------------------------------------------------
    case 'listing':
        $lid = (int)($_GET['listing_id'] ?? 0);
        if ($lid <= 0) fail('Λείπει το listing_id');
        $stmt = db()->prepare('SELECT rt.*, u.full_name AS consumer_name, u.username AS consumer_username
                               FROM ratings rt
                               JOIN users u ON u.id = rt.consumer_id
                               WHERE rt.listing_id = ?
                               ORDER BY rt.created_at DESC');
        $stmt->execute([$lid]);
        ok(['ratings' => $stmt->fetchAll()]);
        break;

    default:
        fail('Άγνωστη ενέργεια', 404);
}
