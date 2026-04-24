<?php
/**
 * API: /backend/api/admin.php  – Admin Dashboard (Δ)
 *
 * Ενέργειες:
 *   - stats        : GET – Συνολικά στατιστικά (Δ1)
 *   - leaderboard  : GET – Top Donor + κορυφαίες αγγελίες (Δ2)
 *   - overview     : GET – Συγκεντρωτικά δεδομένα (για την κεντρική οθόνη admin)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
apply_missing_rating_penalties();

require_admin();

$action = $_GET['action'] ?? 'overview';

switch ($action) {

    // ---------------------------------------------------------------- Δ1
    case 'stats': {
        $pdo = db();

        // Μερίδες που διαμοιράστηκαν επιτυχώς (picked_up) τον τελευταίο μήνα
        $sharedLastMonth = (int)$pdo->query("
            SELECT COUNT(*)
              FROM requests
             WHERE status='picked_up'
               AND picked_up_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
        ")->fetchColumn();

        // Επιπλέον χρήσιμα νούμερα για το dashboard
        $totalUsers     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
        $totalListings  = (int)$pdo->query("SELECT COUNT(*) FROM listings")->fetchColumn();
        $activeListings = (int)$pdo->query("
            SELECT COUNT(*) FROM listings
             WHERE TIMESTAMPDIFF(HOUR, created_at, NOW()) < 48
               AND available_portions > 0
        ")->fetchColumn();
        $totalRatings   = (int)$pdo->query("SELECT COUNT(*) FROM ratings")->fetchColumn();
        $avgRating      = $pdo->query("SELECT ROUND(AVG(stars),2) FROM ratings")->fetchColumn();
        $avgRating      = $avgRating !== false ? (float)$avgRating : null;

        // Σειρά μερίδων ανά ημέρα – τελευταίες 30 ημέρες (για γράφημα)
        $daily = $pdo->query("
            SELECT DATE(picked_up_at) AS d, COUNT(*) AS c
              FROM requests
             WHERE status='picked_up'
               AND picked_up_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY DATE(picked_up_at)
          ORDER BY d ASC
        ")->fetchAll();

        ok([
            'shared_last_month' => $sharedLastMonth,
            'total_users'       => $totalUsers,
            'total_listings'    => $totalListings,
            'active_listings'   => $activeListings,
            'total_ratings'     => $totalRatings,
            'avg_rating'        => $avgRating,
            'daily_shared'      => $daily,
        ]);
        break;
    }

    // ---------------------------------------------------------------- Δ2
    case 'leaderboard': {
        $pdo = db();

        // Top Donor: αυτός που έχει δώσει τις περισσότερες picked_up μερίδες
        $topDonors = $pdo->query("
            SELECT u.id, u.username, u.full_name, u.points,
                   COUNT(r.id) AS portions_given
              FROM users u
              JOIN listings l ON l.cook_id = u.id
              JOIN requests r ON r.listing_id = l.id AND r.status = 'picked_up'
          GROUP BY u.id
          ORDER BY portions_given DESC, u.points DESC
          LIMIT 10
        ")->fetchAll();

        // Κορυφαίες αγγελίες με βάση το rating
        $topListings = $pdo->query("
            SELECT l.id, l.title, l.photo_url, l.created_at,
                   u.full_name AS cook_name, u.username AS cook_username,
                   COUNT(rt.id) AS rating_count,
                   ROUND(AVG(rt.stars), 2) AS avg_rating
              FROM listings l
              JOIN users u ON u.id = l.cook_id
              JOIN ratings rt ON rt.listing_id = l.id
          GROUP BY l.id
            HAVING rating_count >= 1
          ORDER BY avg_rating DESC, rating_count DESC
          LIMIT 10
        ")->fetchAll();

        // Πιο δημοφιλείς consumers (όσοι έχουν λάβει τις περισσότερες μερίδες)
        $topReceivers = $pdo->query("
            SELECT u.id, u.username, u.full_name,
                   COUNT(r.id) AS portions_received
              FROM users u
              JOIN requests r ON r.consumer_id = u.id AND r.status='picked_up'
          GROUP BY u.id
          ORDER BY portions_received DESC
          LIMIT 5
        ")->fetchAll();

        ok([
            'top_donors'    => $topDonors,
            'top_listings'  => $topListings,
            'top_receivers' => $topReceivers,
        ]);
        break;
    }

    // ---------------------------------------------------------------- Συγκεντρωτικά
    case 'overview':
    default: {
        $pdo = db();
        // -- STATS --
        $sharedLastMonth = (int)$pdo->query("
            SELECT COUNT(*) FROM requests
             WHERE status='picked_up'
               AND picked_up_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
        ")->fetchColumn();
        $totalUsers     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
        $totalListings  = (int)$pdo->query("SELECT COUNT(*) FROM listings")->fetchColumn();
        $activeListings = (int)$pdo->query("
            SELECT COUNT(*) FROM listings
             WHERE TIMESTAMPDIFF(HOUR, created_at, NOW()) < 48 AND available_portions > 0
        ")->fetchColumn();
        $totalRatings   = (int)$pdo->query("SELECT COUNT(*) FROM ratings")->fetchColumn();
        $avgRating      = $pdo->query("SELECT ROUND(AVG(stars),2) FROM ratings")->fetchColumn();
        $avgRating      = ($avgRating !== false && $avgRating !== null) ? (float)$avgRating : null;
        $daily = $pdo->query("
            SELECT DATE(picked_up_at) AS d, COUNT(*) AS c
              FROM requests
             WHERE status='picked_up'
               AND picked_up_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY DATE(picked_up_at) ORDER BY d ASC
        ")->fetchAll();

        // -- LEADERBOARD --
        $topDonors = $pdo->query("
            SELECT u.id, u.username, u.full_name, u.points,
                   COUNT(r.id) AS portions_given
              FROM users u
              JOIN listings l ON l.cook_id = u.id
              JOIN requests r ON r.listing_id = l.id AND r.status='picked_up'
          GROUP BY u.id ORDER BY portions_given DESC, u.points DESC LIMIT 10
        ")->fetchAll();
        $topListings = $pdo->query("
            SELECT l.id, l.title, l.photo_url, l.created_at,
                   u.full_name AS cook_name, u.username AS cook_username,
                   COUNT(rt.id) AS rating_count,
                   ROUND(AVG(rt.stars), 2) AS avg_rating
              FROM listings l
              JOIN users u ON u.id = l.cook_id
              JOIN ratings rt ON rt.listing_id = l.id
          GROUP BY l.id HAVING rating_count >= 1
          ORDER BY avg_rating DESC, rating_count DESC LIMIT 10
        ")->fetchAll();
        $topReceivers = $pdo->query("
            SELECT u.id, u.username, u.full_name,
                   COUNT(r.id) AS portions_received
              FROM users u
              JOIN requests r ON r.consumer_id = u.id AND r.status='picked_up'
          GROUP BY u.id ORDER BY portions_received DESC LIMIT 5
        ")->fetchAll();

        ok([
            'stats' => [
                'shared_last_month' => $sharedLastMonth,
                'total_users'       => $totalUsers,
                'total_listings'    => $totalListings,
                'active_listings'   => $activeListings,
                'total_ratings'     => $totalRatings,
                'avg_rating'        => $avgRating,
                'daily_shared'      => $daily,
            ],
            'leaderboard' => [
                'top_donors'    => $topDonors,
                'top_listings'  => $topListings,
                'top_receivers' => $topReceivers,
            ],
        ]);
        break;
    }
}
