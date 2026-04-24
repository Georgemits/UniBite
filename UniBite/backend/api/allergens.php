<?php
/**
 * API: /backend/api/allergens.php  (GET)
 * Επιστρέφει τη λίστα των 14 βασικών αλλεργιογόνων κατά EUFIC.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$rows = db()->query('SELECT id, code, name_el, name_en FROM allergens ORDER BY id')->fetchAll();
ok(['allergens' => $rows]);
