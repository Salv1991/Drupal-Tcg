<?php

declare(strict_types=1);

namespace Drupal\tcg_importer\Services;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Exception\FileNotExistsException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\tcg_importer\Batch\TcgImporterBatch;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;

/**
 * Handles TCG cards import logic.
 */
final class TcgImporterService {

  protected array $raritiesMap = [];
  protected array $colorsMap = [];
  private array $totalCardsCount = [];
  private array $allCards = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected ClientInterface $httpClient,
    protected Connection $database,
  ) {}

  public function processChunk(int $offset = 0, int $limit = 50, bool $use_test_file = FALSE): array {
    $all_cards = $this->getAllCards($use_test_file);
    $total_cards = $this->getTotalCardsCount($use_test_file);

    if ($offset >= $total_cards) {
      return ['finished' => TRUE, 'total' => $total_cards];
    }

    $chunk = array_slice($all_cards, $offset, $limit);
    $results = ['create' => 0, 'update' => 0, 'skip' => 0, 'errors' => 0];
    $debug_data = [];

    // Finish Mapping
    $finish_map = [];
    $available_finishes = $this->entityTypeManager->getStorage('commerce_product_attribute_value')->loadByProperties(['attribute' => 'finish']);

    foreach ($available_finishes as $finish) {
      $finish_map[strtolower(trim($finish->label()))] = $finish->id();
    }

    // Prepare directory for images
    $directory = 'public://cards';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Existing cards in chunk
    $imported_cards_ids = array_column($chunk, 'id');
    $existing_cards_ids = $this->entityTypeManager->getStorage('commerce_product')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_id', $imported_cards_ids, 'IN')
      ->execute();

    $existing_cards = $this->entityTypeManager->getStorage('commerce_product')->loadMultiple($existing_cards_ids);
    $all_existing = [];
    foreach ($existing_cards as $card_entity) {
      $all_existing[$card_entity->get('field_id')->value] = [
        'hash' => $card_entity->get('field_data_hash')->value,
        'entity' => $card_entity,
      ];
    }

    $transaction = $this->database->startTransaction('tcg_chunk');

    try {
      foreach ($chunk as $card) {
        $card_id = $card['id'] ?? NULL;

        if (empty($card_id)) {
          $results['errors']++;
          continue;
        }

        $new_hash = md5(serialize([
          $card['name'] ?? '',
          $card['id'] ?? '',
          $card['cmc'] ?? 0,
          $card['flavor_text'] ?? '',
          $card['mana_cost'] ?? '',
          $card['oracle_text'] ?? '',
          $card['rarity'] ?? '',
          $card['color_identity'] ?? [],
          $card['highres_image'] ?? FALSE,
          $card['type_line'] ?? '',
          $card['finishes'] ?? [],
          $card['prices']['usd'] ?? '0.00',
          $card['prices']['usd_foil'] ?? '0.00',
        ]));

        if (!isset($all_existing[$card_id])) {
          $this->createCardProduct($card, $finish_map, $new_hash);
          $results['create']++;
        }
        elseif ($new_hash !== $all_existing[$card_id]['hash']) {
          $this->updateCardProduct($all_existing[$card_id]['entity'], $card, $finish_map, $new_hash);
          $results['update']++;
        }
        else {
          $results['skip']++;
        }
      }
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      \Drupal::logger('tcg_importer')->error('Chunk error: @msg', ['@msg' => $e->getMessage()]);
      $results['errors'] += count($chunk);
    }

    // Clear Cache to save RAM
    $this->entityTypeManager->getStorage('commerce_product')->resetCache($existing_cards_ids);
    $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();

    $next_offset = $offset + count($chunk);

    return [
      'finished' => $next_offset >= $total_cards,
      'total' => $total_cards,
      'processed' => $next_offset,
      'next_offset' => $next_offset,
      'stats' => $results,
      'debug' => $debug_data,
    ];
  }

  public function initTaxonomyCache(): void {
    if (empty($this->raritiesMap)) {
      $rarity_terms = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'rarity']);

      foreach ($rarity_terms as $term) {
        $this->raritiesMap[strtolower(trim($term->label()))] = $term->id();
      }
    }

    if (empty($this->colorsMap)) {
      $color_terms = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'colors']);

      foreach ($color_terms as $term) {
        $this->colorsMap[strtolower(trim($term->label()))] = $term->id();
      }
    }
  }

  public function createCardProduct(array $card, array $finish_map, string $hash): void {
    $this->initTaxonomyCache();

    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $this->entityTypeManager->getStorage('commerce_product')->create([
      'type' => 'default',
    ]);

    $this->populateProductFields($product, $card, $hash);
    $product->save();

    // Variations
    $variation_finishes = $card['finishes'] ?? [];

    if (!count($variation_finishes)) {
      return;
    }

    foreach ($variation_finishes as $variation_finish) {
      $clean_finish_key = strtolower(trim($variation_finish));

      if ($clean_finish_key === 'foil' || $clean_finish_key === 'etched') {
        $price_value = $card['prices']['usd_foil'] ?? '0.00';
      }
      else {
        $price_value = $card['prices']['usd'] ?? '0.00';
      }

      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->create([
        'type' => 'default',
        'sku' => 'MTG-' . $card['id'] . '-' . $clean_finish_key,
        'status' => 1,
        'price' => new Price((string) $price_value, 'USD'),
        'product_id' => $product->id(),
      ]);

      $variation->set('attribute_finish', $finish_map[$clean_finish_key] ?? 0);
      $variation->save();
    }
  }

  public function updateCardProduct(ProductInterface $product, array $card, array $finish_map, string $hash): void {
    $this->initTaxonomyCache();
    $this->populateProductFields($product, $card, $hash);
    $product->save();
  }

  private function populateProductFields(ProductInterface $product, array $card, string $hash): void {
    // 1. Ενημέρωση βασικών πεδίων του Product
    $product->setTitle($card['name'] ?? '');
    $product->set('field_data_hash', $hash);
    $product->set('field_id', $card['id']);
    $product->set('field_has_high_res_image', $card['highres_image'] ?? 0);
    $product->set('field_converted_mana_cost', $card['cmc'] ?? 0);
    $product->set('field_flavour_text', $card['flavor_text'] ?? '');
    $product->set('field_mana_cost', $card['mana_cost'] ?? '');
    $product->set('field_oracle_text', $card['oracle_text'] ?? '');
    $product->set('field_type', $card['type_line'] ?? '');

    // 2. Εικόνες
    $product->set('field_card_art', $this->saveImage($card['image_uris']['large'] ?? '', $card['name'], 'large'));
    $product->set('field_card_art_crop', $this->saveImage($card['image_uris']['art_crop'] ?? '', $card['name'], 'crop'));
    $product->set('field_card_border_crop', $this->saveImage($card['image_uris']['border_crop'] ?? '', $card['name'], 'border-crop'));

    // 3. Taxonomy: Rarity
    $card_rarity_key = strtolower(trim($card['rarity'] ?? ''));
    if (isset($this->raritiesMap[$card_rarity_key])) {
      $product->set('field_rarity', ['target_id' => $this->raritiesMap[$card_rarity_key]]);
    }

    // 4. Taxonomy: Colors
    $card_colors = [];
    foreach ($card['color_identity'] ?? [] as $color) {
      $card_color_key = strtolower(trim($color ?? ''));
      if (isset($this->colorsMap[$card_color_key])) {
        $card_colors[] = $this->colorsMap[$card_color_key];
      }
    }
    $product->set('field_color_identity', $card_colors);
  }

  private function saveImage(string $url, string $name, string $suffix = 'large'): ?array {
    if (empty($url)) {
      return NULL;
    }

    $clean_name = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));
    $filename = $clean_name . '-' . $suffix . '.jpg';
    $destination = 'public://cards/' . $filename;

    // CHECK: Does this file already exist in the database?
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $destination]);
    if ($file = reset($files)) {
      return ['target_id' => $file->id(), 'alt' => $name];
    }

    // Download file
    $data = @file_get_contents($url);
    if (!$data) {
      return NULL;
    }

    try {
      $file = \Drupal::service('file.repository')->writeData($data, $destination, FileSystemInterface::EXISTS_REPLACE);
      return $file ? ['target_id' => $file->id(), 'alt' => $name] : NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  public function runBatchImport(bool $use_test_file = FALSE): void {
    $total_cards = $this->getTotalCardsCount($use_test_file);
    $module_path = \Drupal::service('extension.list.module')->getPath('tcg_importer');

    if ($total_cards == 0){
      return;
    }

    $limit = 50;
    $operations = [];

    // Δημιουργία των operations για το Batch API
    for ($offset = 0; $offset < $total_cards; $offset += $limit) {
      $operations[] = [
        [TcgImporterBatch::class, 'process'],
        [$offset, $limit, $use_test_file],
      ];
    }

    // Ορισμός του Batch
    $batch = [
      'title' => 'Εισαγωγή καρτών TCG...',
      'operations' => $operations,
      'finished' => [TcgImporterBatch::class, 'finished'],
      'file' => $module_path . '/src/Batch/TcgImporterBatch.php',
    ];

    // Εκτέλεση του Batch μέσω Drush
    batch_set($batch);
  }

  public function getTotalCardsCount(bool $use_test_file): int {
    $key = $use_test_file ? 'test' : 'import';

    if(isset($this->totalCardsCount[$key])) {
      return $this->totalCardsCount[$key];
    }

    $all_cards = $this->getAllCards($use_test_file);
    $this->totalCardsCount[$key] = count($all_cards);

    return $this->totalCardsCount[$key];
  }

  public function getAllCards(bool $use_test_file): array {
    $key = $use_test_file ? 'test' : 'import';

    if(isset($this->allCards[$key])) {
      return $this->allCards[$key];
    }

    $json_path = $this->getJsonFilePath($use_test_file);
    $raw_data = file_get_contents($json_path);
    $cards_data = json_decode($raw_data, TRUE);
    $this->allCards[$key] = $cards_data['data'] ?? [];

    return $this->allCards[$key];
  }

  private function getJsonFilePath(bool $use_test_file) {
    $directory = $use_test_file
      ? 'private://tcg_importer_data/test_files'
      : 'private://tcg_importer_data/imported_files';

    // Ελέγχουμε αν υπάρχει ο φάκελος
    if (!file_exists($directory)) {
      throw new DirectoryNotFoundException('No Directory Found.');
    }

    // Σαρώνουμε τον φάκελο μόνο για αρχεία .json
    $files = $this->fileSystem->scanDirectory($directory, '/\.json$/');

    if (empty($files)) {
      throw new FileNotExistsException('File Does Not Exist.');
    }

    $first_file = reset($files);

    return $first_file->uri;
  }
}
