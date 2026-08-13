(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.tcgImporter = {
    attach: function (context) {
      once('tcgImporterInit', '#start-import-btn', context).forEach(button => {
        button.addEventListener('click', function () {

          // Κλειδώνουμε το κουμπί και εμφανίζουμε το progress bar
          button.disabled = true;
          document.getElementById('import-status-container').style.display = 'block';

          let totalCards = 0;
          let stats = { create: 0, update: 0, skip: 0, errors: 0 };

          function processNextChunk(offset) {
              fetch('/admin/tcg-importer/process?offset=' + offset, {
                method: 'POST'
              })
              .then(response => {
                if (!response.ok) {
                  throw new Error('Network error');
                }
                return response.json();
              })
              .then(data => {
                totalCards = data.total;

                // Ενημέρωση των αθροιστικών στατιστικών
                stats.create += data.stats.create;
                stats.update += data.stats.update;
                stats.skip += data.stats.skip;
                stats.errors += data.stats.errors;

                // Ενημέρωση των μετρητών στο UI
                document.getElementById('stat-created').innerText = stats.create;
                document.getElementById('stat-updated').innerText = stats.update;
                document.getElementById('stat-skipped').innerText = stats.skip;
                document.getElementById('stat-errors').innerText = stats.errors;

                // Υπολογισμός & Ενημέρωση Progress Bar
                let percent = Math.min(100, Math.round((data.processed / totalCards) * 100));
                let progressBar = document.getElementById('tcg-progress-bar');
                progressBar.style.width = percent + '%';
                progressBar.innerText = percent + '%';

                document.getElementById('progress-text').innerText =
                  `Επεξεργασία ${data.processed} από ${totalCards} κάρτες...`;

                // Ελέγχουμε αν τελείωσε ή συνεχίζουμε στο επόμενο chunk
                if (!data.finished) {
                  processNextChunk(data.next_offset);
                } else {
                  document.getElementById('progress-text').innerText = '🎉 Η εισαγωγή ολοκληρώθηκε επιτυχώς!';
                  button.disabled = false;
                }
              })
              .catch(error => {
                console.error('Import error:', error);
                document.getElementById('progress-text').innerText = '❌ Σφάλμα κατά την επεξεργασία του chunk.';
                button.disabled = false;
              });
          }

          // Ξεκινάμε από το offset 0
          processNextChunk(0);
        });
      });
    }
  };
})(Drupal, once);
