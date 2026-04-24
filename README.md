# UniBite – Φοιτητικό Food Sharing

Εργαστηριακή άσκηση 2025/26 για το μάθημα **"Προγραμματισμός και Συστήματα στον Παγκόσμιο Ιστό"**,
Τμήμα Μηχανικών Η/Υ & Πληροφορικής, Πανεπιστήμιο Πατρών.

## Τεχνολογίες

- **Front-End:** HTML5, CSS3 (Flexbox, Grid, Media Queries, Responsive Design), vanilla JavaScript, Leaflet (OpenStreetMap)
- **Back-End:** PHP 8 με PDO
- **Βάση Δεδομένων:** MySQL 5.7+ / 8.x
- **Επικοινωνία Client/Server:** Fetch API με JSON

## Εγκατάσταση

### 1. Προαπαιτούμενα
Χρειάζεστε κάποια στοίβα με Apache + PHP + MySQL:
- **Windows:** XAMPP ή WampServer
- **macOS:** MAMP ή XAMPP
- **Linux:** LAMP (apache2, php, mysql-server)

### 2. Αντιγραφή του κώδικα
Αντιγράψτε όλο τον φάκελο `UniBite/` στον φάκελο εξυπηρέτησης του Apache (π.χ. `htdocs/` σε XAMPP).

Τελικό μονοπάτι: `<htdocs>/UniBite/`

### 3. Βάση Δεδομένων
1. Ξεκινήστε τον MySQL από το control panel του XAMPP/MAMP.
2. Ανοίξτε το phpMyAdmin (`http://localhost/phpmyadmin`).
3. Εκτελέστε το αρχείο `database/schema.sql` για τη δημιουργία των πινάκων.
4. Εκτελέστε το αρχείο `database/seed.sql` για τα δεδομένα επίδειξης.

Εναλλακτικά από γραμμή εντολών:

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql
```

### 4. Ρυθμίσεις Σύνδεσης ΒΔ
Αν χρησιμοποιείτε προεπιλογές XAMPP (root, κενός κωδικός, localhost:3306) δεν
χρειάζεται τίποτα άλλο. Αλλιώς, επεξεργαστείτε το `backend/config/db.php`.

### 5. Εκκίνηση
Ανοίξτε τον browser στο:
```
http://localhost/UniBite/frontend/
```

## Λογαριασμοί επίδειξης

| Όνομα χρήστη | Κωδικός   | Ρόλος                     |
|--------------|-----------|---------------------------|
| `admin`      | admin123  | Διαχειριστής               |
| `maria`      | test123   | Μάγειρας (ενεργές αγγελίες)|
| `giannis`    | test123   | Μάγειρας                   |
| `eleni`      | test123   | Μάγειρας                   |
| `sofia`      | test123   | Νέος καταναλωτής (5 πόντοι)|
| `dimitris`   | test123   | Καταναλωτής με ιστορικό    |
| `kostas`     | test123   | Καταναλωτής                |

## Δομή Έργου

```
UniBite/
├── backend/
│   ├── config/db.php        Σύνδεση με τη ΒΔ (PDO)
│   ├── includes/bootstrap.php Κοινά helpers για API endpoints
│   └── api/
│       ├── auth.php         Εγγραφή / σύνδεση / αποσύνδεση
│       ├── listings.php     CRUD αγγελιών, feed με γεωγραφικό φίλτρο
│       ├── requests.php     Αιτήματα: δημιουργία / έγκριση / απόρριψη / παραλαβή / no-show
│       ├── ratings.php      Βαθμολογίες + πόντοι μάγειρα
│       ├── users.php        Λεπτομέρειες χρήστη & ιστορικό πόντων
│       ├── allergens.php    Λίστα 14 αλλεργιογόνων (EUFIC)
│       └── admin.php        Στατιστικά + Leaderboard
├── frontend/
│   ├── index.html           Landing page
│   ├── pages/
│   │   ├── login.html
│   │   ├── register.html
│   │   ├── feed.html        Δυναμικό feed (λίστα + χάρτης)
│   │   ├── my-listings.html CRUD αγγελιών
│   │   ├── inbox.html       Εισερχόμενα αιτήματα (μάγειρας)
│   │   ├── my-requests.html Τα αιτήματά μου (καταναλωτής) + αξιολόγηση
│   │   ├── profile.html     Πόντοι & ιστορικό
│   │   └── admin.html       Admin dashboard
│   ├── css/style.css
│   ├── js/
│   │   ├── common.js        Shared fetch + UI helpers
│   │   ├── auth.js          Login / Register
│   │   ├── feed.js          Feed + χάρτης
│   │   ├── listings.js      My listings + CRUD modal
│   │   ├── requests.js      Inbox (cook)
│   │   ├── my-requests.js   Outbox + rating modal
│   │   ├── profile.js       Προφίλ
│   │   └── admin.js         Admin dashboard + chart
│   └── assets/logo.svg
├── database/
│   ├── schema.sql
│   └── seed.sql
└── report/
    └── UniBite_Report.docx  Αναλυτική αναφορά
```

## Κύριες λειτουργίες (σύνδεση με την εκφώνηση)

- **Α1–Α3 (Ρόλοι):** Ρόλοι "student" & "admin" στον πίνακα `users`. Ο κάθε φοιτητής μπορεί
  να δρα είτε ως Μάγειρας (δημιουργώντας αγγελίες) είτε ως Καταναλωτής (ζητώντας μερίδες).
- **Β1 (CRUD αγγελιών):** `listings.php` endpoints (`create`, `update`, `delete`, `mine`).
  Αγγελίες άνω των 48 ωρών εξαιρούνται αυτόματα από το feed (υπολογισμός state).
- **Β2 (Λεπτομέρειες παράδοσης):** Υποχρεωτικά πεδία `pickup_location`, `pickup_lat/lng`,
  `pickup_time_start/end`.
- **Β3 (Διαχείριση αιτημάτων):** `requests.php` (`approve`, `reject`, `mark_pickup`, `mark_no_show`).
  Η αποδοχή μειώνει αυτόματα τις διαθέσιμες μερίδες, ο no-show αφαιρεί 1 πόντο από τον καταναλωτή.
- **Β4 (Πόντοι μάγειρα):** Στο `ratings.php`: +1 πόντος / rating, +1 επιπλέον αν stars > 3/5.
- **Γ1 (Δυναμικό Feed):** Λίστα + χάρτης (Leaflet) με φίλτρο απόστασης & όριο αποτελεσμάτων.
  Οι εξαντλημένες αγγελίες εμφανίζονται γκριζαρισμένες.
- **Γ2 (Αποστολή αιτήματος):** Απαιτούνται ≥1 πόντοι. Νέοι χρήστες ξεκινούν με 5.
- **Γ3 (Αξιολόγηση):** Επιτρέπεται μόνο μετά από picked_up request. Αν δεν γίνει εντός 48 ωρών,
  αφαιρείται 1 πόντος από τον χρήστη (η ποινή ελέγχεται σε κάθε API request,
  δείτε `apply_missing_rating_penalties()`).
- **Δ1 (Στατιστικά):** `admin.php?action=overview` – μερίδες τελευταίου μήνα, ενεργές αγγελίες,
  ιστόγραμμα 30 ημερών, Μ.Ο. αξιολόγησης.
- **Δ2 (Leaderboard):** Top donors, top-rated γεύματα, top receivers.

## Σημειώσεις

- Η εφαρμογή έχει γίνει mobile-first με CSS Grid & Flexbox, και έχει δοκιμαστεί σε desktop,
  tablet (~768px) και mobile (~375px) breakpoints.
- Δεν γίνεται καμία ανανέωση σελίδας (page reload) κατά τη χρήση (Ε1).
  Όλη η επικοινωνία με τον server γίνεται αποκλειστικά μέσω `fetch()` + JSON (Ε2).
- Η σχεδίαση του χάρτη στηρίζεται σε Leaflet + OpenStreetMap (δωρεάν, χωρίς API key).

---

*© 2026 — George Mitsainas*
