<?php

declare(strict_types=1);

namespace Drupal\tcg_importer\Controller;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\Markup;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Tcg importer routes.
 */
final class TcgImporterController extends ControllerBase {

  protected array $raritiesMap = [];
  protected array $colorsMap = [];

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly ClientInterface $httpClient,
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('file_system'),
      $container->get('http_client'),
      $container->get('database'),
    );
  }

  /**
   * Builds the response.
   */
  public function processChunk(Request $request): JsonResponse {
    $offset = (int) $request->query->get('offset', 0);
    $limit = 50;

    $module_path = \Drupal::service('extension.list.module')->getPath('tcg_importer');
    $json_path = $module_path . '/data/products.json';

    if (!file_exists($json_path)) {
      return new JsonResponse(['error' => 'File not found'], 404);
    }

    $raw_data = file_get_contents($json_path);
    $cards_data = json_decode($raw_data, TRUE);
    $all_cards = $cards_data['data'] ?? [];
    $total_cards = count($all_cards);

    if ($offset >= $total_cards) {
      return new JsonResponse(['finished' => true, 'total' => $total_cards]);
    }

    $chunk = array_slice($all_cards, $offset, $limit);
    $results = ['create' => 0, 'update' => 0, 'skip' => 0, 'errors' => 0];

    // Finish Mapping
    $finish_map = [];
    $available_finishes = $this->entityTypeManager()->getStorage('commerce_product_attribute_value')
      ->loadByProperties(['attribute' => 'finish']);
    foreach ($available_finishes as $finish) {
      $finish_map[strtolower(trim($finish->label()))] = $finish->id();
    }

    // Prepare directory for images
    $directory = 'public://cards';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Existing cards in chunk
    $imported_cards_ids = array_column($chunk, 'id');
    $existing_cards_ids = $this->entityTypeManager()->getStorage('commerce_product')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_id', $imported_cards_ids, 'IN')
      ->execute();

    $existing_cards = $this->entityTypeManager()->getStorage('commerce_product')->loadMultiple($existing_cards_ids);
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
        $card_id = $card['id'] ?? '';
        $new_hash = md5(serialize([
          $card['name'] ?? '',
          $card['id'] ?? '',
          $card['cmc'] ?? 0,
          $card['flavor_text'] ?? '',
          $card['mana_cost'] ?? '',
          $card['oracle_text'] ?? '',
          $card['rarity'] ?? '',
          $card['color_identity'] ?? [],
          $card['highres_image'] ?? false,
          $card['type_line'] ?? '',
          $card['finishes'] ?? [],
          $card['prices']['usd'] ?? '0.00',
          $card['prices']['usd_foil'] ?? '0.00',
        ]));

        if (!isset($all_existing[$card_id])) {
          $this->createCardProduct($card, $finish_map, $new_hash);
          $results['create']++;
        } elseif ($new_hash !== $all_existing[$card_id]['hash']) {
          $this->updateCartProduct($all_existing[$card_id]['entity'], $card, $finish_map, $new_hash);
          $results['update']++;
        } else {
          $results['skip']++;
        }
      }
    } catch (\Exception $e) {
      $transaction->rollBack();
      \Drupal::logger('tcg_importer')->error('Chunk error: @msg', ['@msg' => $e->getMessage()]);
      $results['errors'] += count($chunk);
    }

    // Clear Cache to save RAM
    $this->entityTypeManager()->getStorage('commerce_product')->resetCache($existing_cards_ids);
    $this->entityTypeManager()->getStorage('commerce_product_variation')->resetCache();

    $next_offset = $offset + count($chunk);

    return new JsonResponse([
      'finished' => $next_offset >= $total_cards,
      'total' => $total_cards,
      'processed' => $next_offset,
      'next_offset' => $next_offset,
      'stats' => $results,
    ]);
  }

  public function initTaxonomyCache() {
    if(empty($this->raritiesMap)) {
      $rarity_terms = $this->entityTypeManager()->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'rarity']);

      foreach ($rarity_terms as $term) {
        $this->raritiesMap[strtolower(trim($term->label()))] = $term->id();
      }
    }

    if(empty($this->colorsMap)) {
      $color_terms = $this->entityTypeManager()->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'colors']);

      foreach ($color_terms as $term) {
        $this->colorsMap[strtolower(trim($term->label()))] = $term->id();
      }
    }
  }

  public function createCardProduct(array $card, array $finish_map, string $hash) {
    $this->initTaxonomyCache();

    // 1. Create the base entity with core and primitive fields
    /** @var \Drupal\Core\Entity\EntityInterface $product */
    $product = $this->entityTypeManager()->getStorage('commerce_product')->create([
      'type' => 'default',
      'title' => $card['name'] ?? '',
      'field_data_hash' => $hash ?? '',
      'field_id' => $card['id'] ?? '',
      'field_has_high_res_image' => $card['highres_image'] ?? 0,
      'field_converted_mana_cost' => $card['cmc'] ?? 0,
      'field_card_art' => $this->saveImage($card['image_uris']['large'], $card['name'], 'large'),
      'field_card_art_crop' => $this->saveImage($card['image_uris']['art_crop'], $card['name'], 'crop'),
      'field_card_border_crop' => $this->saveImage($card['image_uris']['border_crop'], $card['name'], 'border-crop'),
    ]);

    // 2. Explicitly set text/string fields using ->set()
    $product->set('field_flavour_text', $card['flavor_text'] ?? '');
    $product->set('field_mana_cost', $card['mana_cost'] ?? '');
    $product->set('field_oracle_text', $card['oracle_text'] ?? '');

    // Card Rarity
    $card_rarity_key = strtolower(trim($card['rarity'] ?? ''));

    if(isset($this->raritiesMap[$card_rarity_key])) {
      $product->set('field_rarity', ['target_id' => $this->raritiesMap[$card_rarity_key]]);
    }

    // Card Colors
    $card_colors = [];
    $raw_colors = $card['color_identity'] ?? [];

    foreach ($raw_colors as $color) {
      $card_color_key = strtolower(trim($color ?? ''));

      if(isset($this->colorsMap[$card_color_key])) {
        $card_colors[] = $this->colorsMap[$card_color_key];
      }
    }

    $product->set('field_color_identity', $card_colors);

    // Card Type Line
    $product->set('field_type', $card['type_line'] ?? '');

    // Save
    $product->save();

    // Variations
    $variation_finishes = $card['finishes'] ?? [];

    if(!count($variation_finishes)) {
      return;
    }

    foreach($variation_finishes as $variation_finish) {
      $clean_finish_key = strtolower(trim($variation_finish));

      $price_value = '0.00';
      if ($clean_finish_key === 'foil' || $clean_finish_key === 'etched') {
         $price_value = $card['prices']['usd_foil'] ?? '0.00';
      } else {
         $price_value = $card['prices']['usd'] ?? '0.00';
      }

      $variation = $this->entityTypeManager()->getStorage('commerce_product_variation')->create([
        'type' => 'default',
        'sku' => 'MTG-' . $card['id'] . '-' . $clean_finish_key,
        'status' => 1,
        'price' => new Price((string) $price_value, 'USD'),
        // 'list_price' => new Price((string) $price_value, 'USD'),
        // 'uid' => 1,
        // 'created' => \Drupal::time()->getRequestTime(),
        // 'author' => 1,
        // 'field_is_high_res' => $card['highres_image'] ?? 0,
        'product_id' => $product->id(),
      ]);

      $variation->set('attribute_finish', isset($finish_map[$clean_finish_key]) ? $finish_map[$clean_finish_key] : 0);
      $variation->save();
    }
  }

  public function updateCartProduct(ProductInterface $product, array $card, array $finish_map, string $hash) {
    $this->initTaxonomyCache();

    // 1. Ενημέρωση βασικών πεδίων του Product
    $product->setTitle($card['name'] ?? '');
    $product->set('field_data_hash', $hash);
    $product->set('field_has_high_res_image', $card['highres_image'] ?? 0);
    $product->set('field_converted_mana_cost', $card['cmc'] ?? 0);
    $product->set('field_flavour_text', $card['flavor_text'] ?? '');
    $product->set('field_mana_cost', $card['mana_cost'] ?? '');
    $product->set('field_oracle_text', $card['oracle_text'] ?? '');
    $product->set('field_type', $card['type_line'] ?? '');

    // 2. Εικόνες (η saveImage που έγραψες επιστρέφει το υπάρχον file target_id αν υπάρχει ήδη)
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

    // Αποθήκευση των αλλαγών στο Product
    $product->save();
  }

  private function saveImage(string $url, string $name, string $suffix = 'large') {
    if (empty($url)) return null;

    // Create a clean, consistent filename: "card-name-large.jpg"
    $clean_name = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));
    $filename = $clean_name . '-' . $suffix . '.jpg';
    $destination = 'public://cards/' . $filename;

    // CHECK: Does this file already exist in the database?
    $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $destination]);
    if ($file = reset($files)) {
      // Found it! Use the existing one.
      return ['target_id' => $file->id(), 'alt' => $name];
    }

    // If not found, download it
    $data = @file_get_contents($url);
    if (!$data) return null;

    try {
      $file = \Drupal::service('file.repository')->writeData($data, $destination, FileSystemInterface::EXISTS_REPLACE);
      return $file ? ['target_id' => $file->id(), 'alt' => $name] : null;
    } catch (\Exception $e) {
      return null;
    }
  }

  public function index(): array {
    return [
      '#markup' => Markup::create('
        <div class="tcg-importer-wrapper" style="max-width: 800px; padding: 20px; background: #f8f9fa; border-radius: 8px; font-family: sans-serif;">
          <h2>TCG Bulk Product Import</h2>
          <p>Πατήστε το κουμπί για να ξεκινήσει η εισαγωγή των προϊόντων.</p>
          <button id="start-import-btn" class="button button--primary" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">Έναρξη Εισαγωγής</button>

          <div id="import-status-container" style="display:none; margin-top: 25px;">
            <div style="background: #e9ecef; border-radius: 20px; overflow: hidden; height: 25px; margin-bottom: 10px;">
              <div id="tcg-progress-bar" style="width: 0%; height: 100%; background: #0d6efd; color: white; text-align: center; line-height: 25px; font-weight: bold; transition: width 0.3s;">0%</div>
            </div>

            <p id="progress-text" style="font-weight: bold; text-align: center;">Προετοιμασία...</p>

            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 15px; text-align: center;">
              <div style="background: #d1e7dd; padding: 10px; border-radius: 5px;">
                <small style="color: #0f5132;">Created</small><br><strong id="stat-created" style="font-size: 18px; color: #0f5132;">0</strong>
              </div>
              <div style="background: #cff4fc; padding: 10px; border-radius: 5px;">
                <small style="color: #055160;">Updated</small><br><strong id="stat-updated" style="font-size: 18px; color: #055160;">0</strong>
              </div>
              <div style="background: #fff3cd; padding: 10px; border-radius: 5px;">
                <small style="color: #664d03;">Skipped</small><br><strong id="stat-skipped" style="font-size: 18px; color: #664d03;">0</strong>
              </div>
              <div style="background: #f8d7da; padding: 10px; border-radius: 5px;">
                <small style="color: #842029;">Errors</small><br><strong id="stat-errors" style="font-size: 18px; color: #842029;">0</strong>
              </div>
            </div>
          </div>
        </div>
      '),
      '#attached' => [
        'library' => ['tcg_importer/importer_ui'],
      ],
    ];
  }
}
