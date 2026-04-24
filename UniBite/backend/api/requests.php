<?php
/**
 * API: /backend/api/requests.php
 *
 * Ενέργειες:
 *   - create    : POST – Καταναλωτής δεσμεύει μερίδα (Γ2)
 *   - inbox     : GET  – Μάγειρας βλέπει αιτήματα για τις αγγελίες του (Β3)
 *   - outbox    : GET  – Καταναλωτής βλέπει τα αιτήματά του
 *   - approve   : POST – Αποδοχή αιτήματος από μάγειρα (Β3)
 *   - reject    : POST – Απόρριψη αιτήματος από μάγειρα (Β3)
 *   - mark_pickup : POST – Επιβεβαίωση παραλαβής από μάγειρα (Β3)
 *   - mark_no_show : POST – Δήλωση μη-παραλαβής (-1 πόντος στον consumer, Β3)
 *   - cancel    : POST – Ο consumer ακυρώνει το δικό του αίτημα
 */
require_once __DIR__ . '/../includes/bootstrap.php';
apply_missing_rating_penalties();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function load_request(int $id): ?array {
    $stmt = db()->prepare('
        SELECT r.*,
               l.cook_id, l.title AS listing_title,
               l.available_portions, l.total_portions,
               l.pickup_location, l.pickup_time_start, l.pickup_time_end,
               uc.full_name AS consumer_name, uc.username AS consumer_username,
               um.full_name AS cook_name,     um.username AS cook_username
          FROM requests r
          JOIN listings l ON l.id = r.listing_id
          JOIN users uc   ON uc.id = r.consumer_id
          JOIN users um   ON um.id = l.cook_id
         WHERE r.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

switch ($action) {
    // ---------------------------------------------------------------- Γ2
    case 'create':
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $data = input_json();
        $lid = (int)($data['listing_id'] ?? 0);
        $msg = trim((string)($data['message'] ?? ''));
        if ($lid <= 0) fail('Λείπει το listing_id');
        if ((int)$u['points'] < 1) fail('Δεν έχετε αρκετούς πόντους – χρειάζεστε τουλάχιστον 1', 403);

        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM listings WHERE id = ?');
        $stmt->execute([$lid]);
        $listing = $stmt->fetch();
        if (!$listing) fail('Η αγγελία δεν βρέθηκε', 404);
        if ((int)$listing['cook_id'] === (int)$u['id']) fail('Δεν μπορείτε να ζητήσετε από τη δική σας αγγελία');
        if (listing_state($listing) !== 'active') fail('Η αγγελία δεν είναι διαθέσιμη');
        // Μη επιτρέπεται διπλό αίτημα (pending/approved) στην ίδια αγγελία
        $dup = $pdo->prepare("SELECT id FROM requests WHERE listing_id=? AND consumer_id=? AND status IN ('pending','approved') LIMIT 1");
        $dup->execute([$lid, $u['id']]);
        if ($dup->fetch()) fail('Έχετε ήδη ενεργό αίτημα για αυτή την αγγελία', 409);

        $stmt = $pdo->prepare('INSERT INTO requests (listing_id, consumer_id, message, status)
                               VALUES (?, ?, ?, "pending")');
        $stmt->execute([$lid, $u['id'], $msg !== '' ? $msg : null]);
        ok(['request' => load_request((int)$pdo->lastInsertId())]);
        break;

    // ---------------------------------------------------------------- Β3
    case 'inbox':
        $u = require_login();
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT r.*, l.title AS listing_title, l.available_portions, l.total_portions,
                   l.pickup_location, l.pickup_time_start, l.pickup_time_end,
                   uc.full_name AS consumer_name, uc.username AS consumer_username
              FROM requests r
              JOIN listings l ON l.id = r.listing_id
              JOIN users uc   ON uc.id = r.consumer_id
             WHERE l.cook_id = ?
             ORDER BY r.created_at DESC');
        $stmt->execute([$u['id']]);
        ok(['requests' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------------
    case 'outbox':
        $u = require_login();
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT r.*, l.title AS listing_title, l.available_portions, l.total_portions,
                   l.pickup_location, l.pickup_time_start, l.pickup_time_end,
                   l.cook_id,
                   um.full_name AS cook_name, um.username AS cook_username,
                   rt.id AS rating_id, rt.stars AS rating_stars
              FROM requests r
              JOIN listings l ON l.id = r.listing_id
              JOIN users um   ON um.id = l.cook_id
         LEFT JOIN ratings rt ON rt.request_id = r.id
             WHERE r.consumer_id = ?
             ORDER BY r.created_at DESC');
        $stmt->execute([$u['id']]);
        ok(['requests' => $stmt->fetchAll()]);
        break;

    // ---------------------------------------------------------------- Β3
    case 'approve':
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $rid = (int)(input_json()['request_id'] ?? $_GET['request_id'] ?? 0);
        if ($rid <= 0) fail('Λείπει το request_id');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $r = load_request($rid);
            if (!$r) { $pdo->rollBack(); fail('Δεν βρέθηκε το αίτημα', 404); }
            if ((int)$r['cook_id'] !== (int)$u['id']) { $pdo->rollBack(); fail('Δεν επιτρέπεται', 403); }
            if ($r['status'] !== 'pending') { $pdo->rollBack(); fail('Το αίτημα δεν είναι εκκρεμές'); }
            if ((int)$r['available_portions'] <= 0) { $pdo->rollBack(); fail('Δεν υπάρχουν διαθέσιμες μερίδες'); }

            $pdo->prepare("UPDATE requests SET status='approved', approved_at=NOW() WHERE id=?")
                ->execute([$rid]);
            $pdo->prepare('UPDATE listings SET available_portions = GREATEST(0, available_portions - 1) WHERE id = ?')
                ->execute([$r['listing_id']]);
            $pdo->commit();
            ok(['request' => load_request($rid)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            fail('Αποτυχία αποδοχής αιτήματος', 500);
        }
        break;

    // ---------------------------------------------------------------- Β3
    case 'reject':
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $rid = (int)(input_json()['request_id'] ?? $_GET['request_id'] ?? 0);
        if ($rid <= 0) fail('Λείπει το request_id');
        $r = load_request($rid);
        if (!$r) fail('Δεν βρέθηκε το αίτημα', 404);
        if ((int)$r['cook_id'] !== (int)$u['id']) fail('Δεν επιτρέπεται', 403);
        if ($r['status'] !== 'pending') fail('Το αίτημα δεν είναι εκκρεμές');
        db()->prepare("UPDATE requests SET status='rejected' WHERE id=?")->execute([$rid]);
        ok(['request' => load_request($rid)]);
        break;

    // ---------------------------------------------------------------- Β3
    case 'mark_pickup':
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $rid = (int)(input_json()['request_id'] ?? $_GET['request_id'] ?? 0);
        if ($rid <= 0) fail('Λείπει το request_id');
        $r = load_request($rid);
        if (!$r) fail('Δεν βρέθηκε το αίτημα', 404);
        if ((int)$r['cook_id'] !== (int)$u['id']) fail('Δεν επιτρέπεται', 403);
        if ($r['status'] !== 'approved') fail('Το αίτημα πρέπει πρώτα να εγκριθεί');
        db()->prepare("UPDATE requests SET status='picked_up', picked_up_at=NOW() WHERE id=?")->execute([$rid]);
        ok(['request' => load_request($rid)]);
        break;

    // ---------------------------------------------------------------- Β3
    case 'mark_no_show':
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $rid = (int)(input_json()['request_id'] ?? $_GET['request_id'] ?? 0);
        if ($rid <= 0) fail('Λείπει το request_id');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $r = load_request($rid);
            if (!$r) { $pdo->rollBack(); fail('Δεν βρέθηκε το αίτημα', 404); }
            if ((int)$r['cook_id'] !== (int)$u['id']) { $pdo->rollBack(); fail('Δεν επιτρέπεται', 403); }
            if ($r['status'] !== 'approved') { $pdo->rollBack(); fail('Μπορούν να δηλωθούν ως μη-παραλαβή μόνο τα εγκεκριμένα αιτήματα'); }
            $pdo->prepare("UPDATE requests SET status='no_show', no_show_at=NOW() WHERE id=?")->execute([$rid]);
            // Επιστροφή μερίδας στην αγγελία (ώστε να μπορεί να την πάρει άλλος)
            $pdo->prepare('UPDATE listings SET available_portions = available_portions + 1 WHERE id = ?')
                ->execute([$r['listing_id']]);
            // -1 πόντος στον consumer
            adjust_points((int)$r['consumer_id'], -1, 'Μη-παραλαβή δεσμευμένης μερίδας', 'no_show', $rid);
            $pdo->commit();
            ok(['request' => load_request($rid)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            fail('Αποτυχία ενημέρωσης', 500);
        }
        break;

    // ----------------------------------------------------------------
    case 'cancel':
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $rid = (int)(input_json()['request_id'] ?? $_GET['request_id'] ?? 0);
        if ($rid <= 0) fail('Λείπει το request_id');
        $pdo = db();
        $r = load_request($rid);
        if (!$r) fail('Δεν βρέθηκε το αίτημα', 404);
        if ((int)$r['consumer_id'] !== (int)$u['id']) fail('Δεν επιτρέπεται', 403);
        if (!in_array($r['status'], ['pending','approved'], true)) fail('Δεν μπορεί να ακυρωθεί');
        $pdo->beginTransaction();
        try {
            if ($r['status'] === 'approved') {
                $pdo->prepare('UPDATE listings SET available_portions = available_portions + 1 WHERE id = ?')
                    ->execute([$r['listing_id']]);
            }
            $pdo->prepare("UPDATE requests SET status='cancelled' WHERE id=?")->execute([$rid]);
            $pdo->commit();
            ok(['request' => load_request($rid)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            fail('Αποτυχία ακύρωσης', 500);
        }
        break;

    default:
        fail('Άγνωστη ενέργεια', 404);
}
