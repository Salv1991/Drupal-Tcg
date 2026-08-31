<?php

declare(strict_types=1);

namespace Drupal\tcg_importer\Batch;

/**
 * Batch operations handler for TCG Import.
 */
final class TcgImporterBatch {

  /**
   * Process a single chunk via Batch API.
   */
  public static function process(int $offset, int $limit, bool $use_test_file, &$context): void {
    /** @var \Drupal\tcg_importer\Services\TcgImporterService $importer */
    $importer = \Drupal::service('tcg_importer.importer');

    if (!isset($context['results']['stats'])) {
      $context['results']['stats'] = ['create' => 0, 'update' => 0, 'skip' => 0, 'errors' => 0];
    }

    try {
      $result = $importer->processChunk($offset, $limit, $use_test_file);

      if (isset($result['stats'])) {
        $context['results']['stats']['create'] += $result['stats']['create'];
        $context['results']['stats']['update'] += $result['stats']['update'];
        $context['results']['stats']['skip']   += $result['stats']['skip'];
        $context['results']['stats']['errors'] += $result['stats']['errors'];
      }
    }
    catch (\Throwable $e) {
      \Drupal::messenger()->addError(t('Σφάλμα: @msg', ['@msg' => $e->getMessage()]));
      $context['results']['fatal_error'] = $e->getMessage();
    }

    $context['message'] = t('Processing TCG cards from offset @offset...', ['@offset' => $offset]);
  }

  /**
   * Finished callback when the entire batch is complete.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();

    if (empty($results['fatal_error']) && $success) {
      $stats = $results['stats'] ?? ['create' => 0, 'update' => 0, 'skip' => 0, 'errors' => 0];
      $messenger->addStatus(t('TCG Import completed successfully! Created: @create, Updated: @update, Skipped: @skip, Errors: @errors', [
        '@create' => $stats['create'],
        '@update' => $stats['update'],
        '@skip'   => $stats['skip'],
        '@errors' => $stats['errors'],
      ]));
    }
    else {
      $error_msg = $results['fatal_error'] ?? 'Unexpected Error.';
      $messenger->addError(t($error_msg));
    }
  }

}
