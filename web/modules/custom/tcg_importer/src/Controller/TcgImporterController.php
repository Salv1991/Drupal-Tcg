<?php

declare(strict_types=1);

namespace Drupal\tcg_importer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\tcg_importer\Services\TcgImporterService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Tcg importer routes.
 */
final class TcgImporterController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    protected TcgImporterService $tcgImporter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('tcg_importer.importer'),
    );
  }
}
