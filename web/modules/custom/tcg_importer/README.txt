📦 Τεχνική Τεκμηρίωση: Module tcg_importer
Αυτή η τεκμηρίωση αναλύει την αρχιτεκτονική, τη ροή εκτέλεσης και τη λογική των εξαρτημάτων του module tcg_importer, χωρίς την παρουσία πηγαίου κώδικα.



1. Αρχιτεκτονική & Ροή Εκτέλεσης (Execution Flow)
Η διαδικασία εισαγωγής ακολουθεί μια αρχιτεκτονική 3 επιπέδων (3-Tier Architecture) για να διασφαλιστεί η σταθερότητα του συστήματος κατά την επεξεργασία μεγάλου όγκου δεδομένων (10.000+ εγγραφές):
Plaintext

[1. CLI Entrypoint]        			[2. Process Manager]           	[3. Business Logic]
TcgImporterCommands   ──►   TcgImporterBatch       ──►   TcgImporterService
(Drush Command)            		 (Batch API Handler)           		(Core Service)
        │                            │                          │
       ├─► Υπολογίζει σύνολο        	├─► Διαχειρίζεται chunks       	├─► Parsing JSON & Hashing
       └─► Δημιουργεί Batch         	└─► Παρακολουθεί πρόοδο     ├─► Commerce Products & Variations
                                                               ├─► Downloader Εικόνων
                                                               └─► Καθαρισμός Memory Cache
Αναλυτικά τα βήματα εκτέλεσης:
1. Έναρξη (Drush Command): Ο χρήστης καλεί την εντολή drush tcgi. Η κλάση Command διαβάζει το αρχείο JSON, υπολογίζει τις συνολικές εγγραφές και τις «σπάει» σε μικρές παρτίδες (chunks των 50 αντικειμένων).
2. Διαχείριση Batch (Batch API Handler): Το Batch API αναλαμβάνει να εκτελέσει κάθε chunk σε ξεχωριστή υπο-διεργασία (sub-process). Αυτό αποτρέπει τα Memory Leaks και τα PHP Timeouts.
3. Εκτέλεση Λογικής (Importer Service): Για κάθε chunk, το Service αναλαμβάνει την επεξεργασία: σύγκριση hashes, δημιουργία/ενημέρωση προϊόντων στο Drupal Commerce, λήψη εικόνων και αποθήκευση στη Βάση Δεδομένων.




2. Δομή Φακέλων & Αρχείων (Directory Structure)
Plaintext

web/modules/custom/tcg_importer/
├── tcg_importer.info.yml          # Δήλωση Module & Dependencies
├── tcg_importer.services.yml      # Δήλωση Services & Commands στο DI Container
├── data/
	│   └── products-huge.json         # Πηγή δεδομένων (JSON)
└── src/

├── Commands/
    │   └── TcgImporterCommands.php # CLI Command Interface (Drush)

├── Batch/
    │   └── TcgImporterBatch.php    # Batch API Handler & Callbacks

└── Services/
        └── TcgImporterService.php  # Κεντρική Λογική (Business Logic)




3. Αναλυτική Ροή & Ρόλος κάθε Αρχείου
1️⃣ tcg_importer.info.yml
* Ρόλος: Το αρχείο ταυτότητας του module στο Drupal.
* Λειτουργία: Δηλώνει το όνομα, την έκδοση Drupal (10/11) και την απαραίτητη εξάρτηση από το commerce_product.
2️⃣ tcg_importer.services.yml
* Ρόλος: Ο καταχωρητής Dependency Injection (DI).
* Λειτουργία:
    * Εγγράφει το TcgImporterService περνώντας του τα απαραίτητα core services (entity_type.manager, file_system, http_client, database).
    * Εγγράφει το TcgImporterCommands συνδέοντάς το με το Drush μέσω του tag drush.command.
3️⃣ Commands/TcgImporterCommands.php (CLI Interface)
* Ρόλος: Το σημείο εισόδου της εντολής από το τερματικό.
* Λογική:
    * Έλεγχος διαθεσιμότητας: Φορτώνει ρητά το αρχείο της Batch κλάσης ώστε να είναι προσβάσιμο σε όλα τα CLI sub-processes.
    * Υπολογισμός μεγέθους: Καλεί το Service για να μάθει το συνολικό πλήθος των καρτών στο JSON.
    * Δημιουργία Operations: Χωρίζει τις εγγραφές σε βήματα (offsets) των 50 αντικειμένων.
    * Εκκίνηση: Αναθέτει τη λίστα των εργασιών στο batch_set() και πυροδοτεί το drush_backend_batch_process().
4️⃣ Batch/TcgImporterBatch.php (Process Manager)
* Ρόλος: Ο διαχειριστής των σταδίων εκτέλεσης (Batch Operations).
* Λογική:
    * process() callback: Καλείται επαναληπτικά από το Drush για κάθε offset. Καλεί το Service για την επεξεργασία του τρέχοντος chunk και συγκεντρώνει στατιστικά (Create, Update, Skip, Error).
    * finished() callback: Εκτελείται όταν ολοκληρωθούν όλα τα chunks. Εμφανίζει στον χρήστη το τελικό feedback με τα συγκεντρωτικά αποτελέσματα.
5️⃣ Services/TcgImporterService.php (Business Logic)
* Ρόλος: Η «καρδιά» του importer όπου εκτελείται όλη η λογική επεξεργασίας.
* Βασικές Λειτουργίες:
1. MD5 Data Hashing (Βελτιστοποίηση Ταχύτητας):
    * Υπολογίζει ένα μοναδικό hash (MD5) από τα βασικά πεδία της κάθε κάρτας (τίτλος, τιμές, κείμενα, φινιρίσματα).
    * Συγκρίνει το νέο hash με το αποθηκευμένο hash στη βάση δεδομένων.
    * Skip: Αν τα hashes ταυτίζονται, η κάρτα προσπερνάται αμέσως (γλιτώνοντας βαριές λειτουργίες I/O και Database Querying).
2. Διαχείριση Commerce Products & Variations:
    * Product: Δημιουργεί ή ενημερώνει το κύριο προϊόν με τα μεταδεδομένα της κάρτας (Mana Cost, Rarity, Oracle Text, Flavour Text, Color Identity).
    * Variations: Δημιουργεί ξεχωριστές παραλλαγές (Variations) ανάλογα με τα διαθέσιμα φινιρίσματα (nonfoil, foil, etched), αντιστοιχίζοντας την κατάλληλη τιμή.
3. In-Memory Caching (Ταξινόμηση & Attributes):
    * Φορτώνει τους όρους ταξινομίας (Rarity, Colors) και τα attributes (Finishes) μία φορά στη μνήμη RAM κατά την έναρξη του chunk, αποφεύγοντας τα πολλαπλά queries ανά κάρτα.
4. Image Downloader & Deduplication:
    * Ελέγχει αν η εικόνα υπάρχει ήδη στο φάκελο public://cards/ ή στη Βάση Δεδομένων.
    * Αν δεν υπάρχει, την κατεβάζει, δημιουργεί το Managed File Entity στο Drupal και τη συνδέει με το προϊόν.
5. Ασφάλεια & Διαχείριση Μνήμης (Memory & DB Safety):
    * Database Transactions: Κάθε chunk εκτελείται μέσα σε DB Transaction. Αν προκύψει σφάλμα σε μία κάρτα, γίνεται rollback στο συγκεκριμένο chunk χωρίς να καταστραφεί η υπόλοιπη εισαγωγή.
    * Reset Entity Cache: Μετά την ολοκλήρωση κάθε chunk, εκτελεί resetCache() στο entityTypeManager, ελευθερώνοντας τη RAM για το επόμενο chunk.



4. Τεχνικές Βελτιστοποίησης (Performance Highlights)
Τεχνική	Πρόβλημα που λύνει	Τρόπος Υλοποίησης
Drush Batch API	PHP Timeouts & Out of Memory Errors	Διαχωρισμός σε chunks (50 items) και εκτέλεση σε απομονωμένα sub-processes.
MD5 Data Hashing	Αργές επανεισαγωγές (Re-imports)	Παράκαμψη (Skip) εγγραφών που δεν έχουν αλλάξει από την τελευταία εισαγωγή.
Reset Storage Cache	Memory Leaks σε 10.000+ εγγραφές	Καθαρισμός των φορτωμένων Drupal Entities από τη RAM στο τέλος κάθε chunk.
Asset Deduplication	Διπλότυπα αρχεία & υπερχείλιση δίσκου	Έλεγχος ύπαρξης εικόνας στη DB βάσει URI πριν από κάθε λήψη (HTTP request).
DB Transactions	Μισοτελειωμένα / Corrupted δεδομένα	Rollback ολόκληρου του chunk σε περίπτωση απρόσμενου exception.
5. Cheatsheet Εντολών
* Εκτέλεση Εισαγωγής:drush tcgi
* Ενημέρωση Autoloader & Cache (μετά από αλλαγές δομής):composer dump-autoloaddrush cr
* Πλήρης Επανενεργοποίηση Module (Reset DB Paths):drush pm:uninstall tcg_importer -y && drush pm:install tcg_importer -y
