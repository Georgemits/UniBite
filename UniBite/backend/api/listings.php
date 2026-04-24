<?php
/**
 * API: /backend/api/listings.php
 *
 * Υποστηριζόμενες ενέργειες (μέσω ?action=...):
 *   - feed   : GET  – ενεργές/ανενεργές αγγελίες για το consumer feed (Γ1)
 *               παράμετροι: lat, lng, max_km (προαιρ.), limit (προαιρ.),
 *                           include_inactive=1|0 (default 1), sort=distance|recent
 *   - mine   : GET  – αγγελίες του συνδεδεμένου μάγειρα
 *   - get    : GET  – λεπτομέρειες μιας αγγελίας (?id=)
 *   - create : POST – δημιουργία αγγελίας (Β1, Β2)
 *   - update : POST/PUT – επεξεργασία αγγελίας (Β1)
 *   - delete : POST/DELETE – διαγραφή αγγελίας (Β1)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
apply_missing_rating_penalties();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function load_listing_full(int $id): ?array {
    $stmt = db()->prepare('SELECT l.*, u.full_name AS cook_name, u.username AS cook_username
                           FROM listings l
                           JOIN users u ON u.id = l.cook_id
                           WHERE l.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    // Allergens
    $a = db()->prepare('SELECT a.id, a.code, a.name_el, a.name_en
                        FROM listing_allergens la
                        JOIN allergens a ON a.id = la.allergen_id
                        WHERE la.listing_id = ? ORDER BY a.id');
    $a->execute([$id]);
    $row['allergens'] = $a->fetchAll();
    // Μέση αξιολόγηση
    $r = db()->prepare('SELECT COUNT(*) c, AVG(stars) avg FROM ratings WHERE listing_id = ?');
    $r->execute([$id]);
    $agg = $r->fetch();
    $row['rating_count'] = (int)$agg['c'];
    $row['rating_avg']   = $agg['c'] > 0 ? round((float)$agg['avg'], 2) : null;
    $row['state']        = listing_state($row);
    return $row;
}

switch ($action) {
    // -----------------------------------------------------------------------
    case 'feed':
        // Δημόσιο – δεν απαιτείται login για να δει κανείς τη λίστα
        $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
        $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
        $maxKm = isset($_GET['max_km']) && $_GET['max_km'] !== '' ? (float)$_GET['max_km'] : null;
        $limit = isset($_GET['limit']) && $_GET['limit'] !== '' ? max(1, min(100, (int)$_GET['limit'])) : 50;
        $includeInactive = !isset($_GET['include_inactive']) || $_GET['include_inactive'] !== '0';
        $sort  = $_GET['sort'] ?? 'recent';

        // Αγγελίες < 48 ωρών (ενεργές + ανενεργές) – οι παλαιότερες (διεγραμμένες) κρύβονται
        $stmt = db()->prepare('
            SELECT l.*, u.full_name AS cook_name, u.username AS cook_username,
                   (SELECT COUNT(*) FROM ratings r WHERE r.listing_id = l.id) AS rating_count,
                   (SELECT ROUND(AVG(stars),2) FROM ratings r WHERE r.listing_id = l.id) AS rating_avg
              FROM listings l
              JOIN users u ON u.id = l.cook_id
             WHERE TIMESTAMPDIFF(HOUR, l.created_at, NOW()) < 48
        ');
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Επισύναψη allergens ομαδικά
        $ids = array_column($rows, 'id');
        $allergMap = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $q = db()->prepare("SELECT la.listing_id, a.id, a.code, a.name_el, a.name_en
                                FROM listing_allergens la
                                JOIN allergens a ON a.id = la.allergen_id
                                WHERE la.listing_id IN ($in)
                                ORDER BY a.id");
            $q->execute($ids);
            foreach ($q->fetchAll() as $r) {
                $allergMap[$r['listing_id']][] = [
                    'id' => (int)$r['id'], 'code' => $r['code'],
                    'name_el' => $r['name_el'], 'name_en' => $r['name_en'],
                ];
            }
        }

        foreach ($rows as &$row) {
            $row['allergens'] = $allergMap[$row['id']] ?? [];
            $row['state'] = listing_state($row);
            if ($lat !== null && $lng !== null) {
                $row['distance_km'] = round(
                    haversine($lat, $lng, (float)$row['pickup_lat'], (float)$row['pickup_lng']), 2);
            } else {
                $row['distance_km'] = null;
            }
        }
        unset($row);

        // Φίλτρο: ανενεργές
        if (!$includeInactive) {
            $rows = array_values(array_filter($rows, fn($r) => $r['state'] === 'active'));
        }
        // Φίλτρο: απόσταση
        if ($maxKm !== null && $lat !== null && $lng !== null) {
            $rows = array_values(array_filter($rows, fn($r) => $r['distance_km'] !== null && $r['distance_km'] <= $maxKm));
        }
        // Ταξινόμηση
        if ($sort === 'distance' && $lat !== null && $lng !== null) {
            usort($rows, fn($a, $b) => ($a['distance_km'] ?? PHP_FLOAT_MAX) <=> ($b['distance_km'] ?? PHP_FLOAT_MAX));
        } else {
            usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        }
        // Limit
        $rows = array_slice($rows, 0, $limit);

        ok(['listings' => $rows]);
        break;

    // -----------------------------------------------------------------------
    case 'mine':
        $u = require_login();
        $stmt = db()->prepare('SELECT * FROM listings WHERE cook_id = ? ORDER BY created_at DESC');
        $stmt->execute([$u['id']]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['state'] = listing_state($row);
            // Αριθμός αιτημάτων ανά status
            $q = db()->prepare("SELECT status, COUNT(*) c FROM requests WHERE listing_id = ? GROUP BY status");
            $q->execute([$row['id']]);
            $row['request_stats'] = [];
            foreach ($q->fetchAll() as $r) $row['request_stats'][$r['status']] = (int)$r['c'];
        }
        unset($row);
        ok(['listings' => $rows]);
        break;

    // -----------------------------------------------------------------------
    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) fail('Λείπει το id αγγελίας');
        $row = load_listing_full($id);
        if (!$row) fail('Δεν βρέθηκε η αγγελία', 404);
        if ($row['state'] === 'deleted') fail('Η αγγελία έχει λήξει', 410);
        ok(['listing' => $row]);
        break;

    // -----------------------------------------------------------------------
    case 'create':
        if ($method !== 'POST') fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $data = input_json();
        $title = trim($data['title'] ?? '');
        $notes = trim($data['notes'] ?? '');
        $photo = trim($data['photo_url'] ?? '');
        $portions = (int)($data['portions'] ?? 0);
        $location = trim($data['pickup_location'] ?? '');
        $pLat = isset($data['pickup_lat']) ? (float)$data['pickup_lat'] : null;
        $pLng = isset($data['pickup_lng']) ? (float)$data['pickup_lng'] : null;
        $t1   = trim($data['pickup_time_start'] ?? '');
        $t2   = trim($data['pickup_time_end'] ?? '');
        $allergens = $data['allergens'] ?? [];

        if ($title === '') fail('Απαιτείται τίτλος');
        if ($portions <= 0 || $portions > 100) fail('Μη έγκυρος αριθμός μερίδων');
        if ($location === '' || $pLat === null || $pLng === null) fail('Απαιτείται σημείο παραλαβής');
        if ($t1 === '' || $t2 === '') fail('Απαιτείται ώρα παραλαβής');
        if (!is_array($allergens)) $allergens = [];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO listings
                (cook_id, title, notes, photo_url, total_portions, available_portions,
                 pickup_location, pickup_lat, pickup_lng, pickup_time_start, pickup_time_end)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $u['id'], $title, $notes ?: null, $photo ?: null,
                $portions, $portions, $location, $pLat, $pLng, $t1, $t2
            ]);
            $lid = (int)$pdo->lastInsertId();
            if ($allergens) {
                $ins = $pdo->prepare('INSERT IGNORE INTO listing_allergens (listing_id, allergen_id) VALUES (?, ?)');
                foreach ($allergens as $aid) {
                    if ((int)$aid > 0) $ins->execute([$lid, (int)$aid]);
                }
            }
            $pdo->commit();
            ok(['listing' => load_listing_full($lid)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            fail('Αποτυχία δημιουργίας αγγελίας', 500);
        }
        break;

    // -----------------------------------------------------------------------
    case 'update':
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $data = input_json();
        $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) fail('Λείπει το id');
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM listings WHERE id = ?');
        $stmt->execute([$id]);
        $listing = $stmt->fetch();
        if (!$listing) fail('Η αγγελία δεν βρέθηκε', 404);
        if ((int)$listing['cook_id'] !== (int)$u['id']) fail('Δεν επιτρέπεται', 403);
        if (listing_state($listing) === 'deleted') fail('Η αγγελία έχει λήξει', 410);

        $fields = [];
        $params = [];
        foreach (['title','notes','photo_url','pickup_location'] as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = ?"; $params[] = trim((string)$data[$f]) ?: null; }
        }
        foreach (['pickup_lat','pickup_lng'] as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = ?"; $params[] = (float)$data[$f]; }
        }
        foreach (['pickup_time_start','pickup_time_end'] as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = ?"; $params[] = (string)$data[$f]; }
        }
        // Προσαρμογή μερίδων: αν αλλάζει το total, υπολόγισε νέο available
        if (array_key_exists('portions', $data)) {
            $newTotal = max(0, (int)$data['portions']);
            $consumed = (int)$listing['total_portions'] - (int)$listing['available_portions'];
            $newAvail = max(0, $newTotal - $consumed);
            $fields[] = 'total_portions = ?';     $params[] = $newTotal;
            $fields[] = 'available_portions = ?'; $params[] = $newAvail;
        }
        if ($fields) {
            $params[] = $id;
            $pdo->prepare('UPDATE listings SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }
        if (isset($data['allergens']) && is_array($data['allergens'])) {
            $pdo->prepare('DELETE FROM listing_allergens WHERE listing_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT IGNORE INTO listing_allergens (listing_id, allergen_id) VALUES (?, ?)');
            foreach ($data['allergens'] as $aid) if ((int)$aid > 0) $ins->execute([$id, (int)$aid]);
        }
        ok(['listing' => load_listing_full($id)]);
        break;

    // -----------------------------------------------------------------------
    case 'delete':
        if (!in_array($method, ['POST', 'DELETE'], true)) fail('Μέθοδος δεν υποστηρίζεται', 405);
        $u = require_login();
        $id = (int)($_GET['id'] ?? input_json()['id'] ?? 0);
        if ($id <= 0) fail('Λείπει το id');
        $stmt = db()->prepare('SELECT cook_id FROM listings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) fail('Η αγγελία δεν βρέθηκε', 404);
        if ((int)$row['cook_id'] !== (int)$u['id'] && $u['role'] !== 'admin') {
            fail('Δεν επιτρέπεται', 403);
        }
        db()->prepare('DELETE FROM listings WHERE id = ?')->execute([$id]);
        ok();
        break;

    default:
        fail('Άγνωστη ενέργεια', 404);
}
