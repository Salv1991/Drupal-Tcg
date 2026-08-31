<?php

declare(strict_types=1);

namespace Drupal\tcg_importer\Commands;

use Drush\Commands\DrushCommands;
use Drupal\tcg_importer\Services\TcgImporterService;

final class TcgImporterCommands extends DrushCommands {

  public function __construct(protected TcgImporterService $tcgImporterService) {
    parent::__construct();
  }

  /**
   * Imports TCG cards from JSON file.
   *
   * @command tcg:import
   * @aliases tcgi
   */
  public function import(array $options = ['test' => NULL]): void {
    $use_test_file = $this->io()->confirm('Do you want to use a test file?', TRUE, 'Yes', 'No');

    $total = $this->tcgImporterService->getTotalCardsCount($use_test_file);

    if ($total <= 0) {
      $this->logger()->error('Δε βρέθηκαν κάρτες στο αρχείο JSON.');
      return;
    }

    $this->logger()->notice(dt('Ξεκινάει η εισαγωγή για @total κάρτες...', ['@total' => $total]));

    $this->tcgImporterService->runBatchImport($use_test_file);

    $batch =& batch_get();
    $batch['progressive'] = FALSE;

    drush_backend_batch_process();

    $this->logger()->success('Η εισαγωγή ολοκληρώθηκε!');
  }

}
