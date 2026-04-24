-- ============================================================================
-- UniBite – Φοιτητικό Food Sharing
-- Σχήμα Βάσης Δεδομένων (MySQL 5.7+ / 8.x)
-- ============================================================================
-- Δημιουργία βάσης δεδομένων
CREATE DATABASE IF NOT EXISTS unibite
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE unibite;

-- Καθαρισμός υπαρχουσών πινάκων (για επανεγκατάσταση)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS ratings;
DROP TABLE IF EXISTS requests;
DROP TABLE IF EXISTS listing_allergens;
DROP TABLE IF EXISTS listings;
DROP TABLE IF EXISTS allergens;
DROP TABLE IF EXISTS point_transactions;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Χρήστες (Users)
-- Ο κάθε φοιτητής μπορεί να δρα και ως Μάγειρας (Α1) και ως Καταναλωτής (Α2).
-- Ο διαχειριστής (Α3) έχει ειδικό ρόλο.
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username         VARCHAR(50)  NOT NULL UNIQUE,
    email            VARCHAR(150) NOT NULL UNIQUE,
    password_hash    VARCHAR(255) NOT NULL,
    full_name        VARCHAR(150) NOT NULL,
    role             ENUM('student','admin') NOT NULL DEFAULT 'student',
    points           INT NOT NULL DEFAULT 5,         -- Νέοι χρήστες ξεκινούν με 5 πόντους (Γ2)
    default_lat      DECIMAL(10,7) NULL,             -- Προτιμώμενη τοποθεσία (προαιρετικά)
    default_lng      DECIMAL(10,7) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Αλλεργιογόνα (14 κυριότερα κατά EUFIC)
-- ---------------------------------------------------------------------------
CREATE TABLE allergens (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code    VARCHAR(40) NOT NULL UNIQUE,   -- π.χ. 'gluten'
    name_el VARCHAR(100) NOT NULL,
    name_en VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Αγγελίες Φαγητού (Listings) – Β1, Β2
-- Η κατάσταση (Ενεργή / Ανενεργή / Διεγραμμένη) προκύπτει δυναμικά από
-- τα πεδία created_at και available_portions.
-- ---------------------------------------------------------------------------
CREATE TABLE listings (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cook_id            INT UNSIGNED NOT NULL,
    title              VARCHAR(150) NOT NULL,
    notes              TEXT NULL,
    photo_url          VARCHAR(255) NULL,
    total_portions     INT  NOT NULL,
    available_portions INT  NOT NULL,
    pickup_location    VARCHAR(255) NOT NULL,     -- Β2 – υποχρεωτικό
    pickup_lat         DECIMAL(10,7) NOT NULL,
    pickup_lng         DECIMAL(10,7) NOT NULL,
    pickup_time_start  DATETIME NOT NULL,         -- Β2 – ακριβής ώρα παραλαβής
    pickup_time_end    DATETIME NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_listing_cook FOREIGN KEY (cook_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_listing_created (created_at),
    INDEX idx_listing_cook (cook_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Συσχέτιση Αγγελίας ↔ Αλλεργιογόνα (Β1)
-- ---------------------------------------------------------------------------
CREATE TABLE listing_allergens (
    listing_id  INT UNSIGNED NOT NULL,
    allergen_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (listing_id, allergen_id),
    CONSTRAINT fk_la_listing  FOREIGN KEY (listing_id)  REFERENCES listings(id)  ON DELETE CASCADE,
    CONSTRAINT fk_la_allergen FOREIGN KEY (allergen_id) REFERENCES allergens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Αιτήματα Δέσμευσης Μερίδας (Γ2, Β3)
-- ---------------------------------------------------------------------------
CREATE TABLE requests (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id    INT UNSIGNED NOT NULL,
    consumer_id   INT UNSIGNED NOT NULL,
    status        ENUM('pending','approved','rejected','picked_up','no_show','cancelled') NOT NULL DEFAULT 'pending',
    message       VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at   DATETIME NULL,
    picked_up_at  DATETIME NULL,
    no_show_at    DATETIME NULL,
    CONSTRAINT fk_req_listing  FOREIGN KEY (listing_id)  REFERENCES listings(id) ON DELETE CASCADE,
    CONSTRAINT fk_req_consumer FOREIGN KEY (consumer_id) REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_req_listing (listing_id),
    INDEX idx_req_consumer (consumer_id),
    INDEX idx_req_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Αξιολογήσεις Γεύματος (Γ3)
-- 1-5 αστέρια, ένα rating ανά αίτημα
-- ---------------------------------------------------------------------------
CREATE TABLE ratings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id  INT UNSIGNED NOT NULL UNIQUE,
    listing_id  INT UNSIGNED NOT NULL,
    cook_id     INT UNSIGNED NOT NULL,
    consumer_id INT UNSIGNED NOT NULL,
    stars       TINYINT UNSIGNED NOT NULL CHECK (stars BETWEEN 1 AND 5),
    comment     VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rt_request  FOREIGN KEY (request_id)  REFERENCES requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_rt_listing  FOREIGN KEY (listing_id)  REFERENCES listings(id) ON DELETE CASCADE,
    CONSTRAINT fk_rt_cook     FOREIGN KEY (cook_id)     REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_rt_consumer FOREIGN KEY (consumer_id) REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_rt_listing (listing_id),
    INDEX idx_rt_cook (cook_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Ιστορικό κινήσεων πόντων (audit trail) – βοηθά στο debugging / reporting
-- ---------------------------------------------------------------------------
CREATE TABLE point_transactions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    delta        INT NOT NULL,
    reason       VARCHAR(100) NOT NULL,
    related_type VARCHAR(40)  NULL,     -- 'request','rating','no_show','missing_rating'
    related_id   INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_pt_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- View για τις ενεργές αγγελίες (δεν έχουν παρέλθει 48ώρες, έχουν μερίδες)
-- Προαιρετικό, για ευκολία στατιστικών / reporting
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_active_listings AS
SELECT l.*,
       CASE
         WHEN TIMESTAMPDIFF(HOUR, l.created_at, NOW()) >= 48 THEN 'deleted'
         WHEN l.available_portions <= 0 THEN 'inactive'
         ELSE 'active'
       END AS listing_state
FROM listings l;
