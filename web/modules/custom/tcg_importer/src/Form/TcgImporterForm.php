<?php

declare(strict_types=1);

namespace Drupal\tcg_importer\Form;

use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\tcg_importer\Services\TcgImporterService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class TcgImporterForm extends FormBase {

  public function __construct(
    protected TcgImporterService $tcgImporterService,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('tcg_importer.importer'),
      $container->get('file_system'),
      $container->get('file.repository'),
    );
  }

  public function getFormId(): string {
    return 'tcg_importer_admin_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    // $use_test_file = (bool) $form_state->getValue('use_test_file', FALSE);

    // $total = 0;

    // try {
    //   $total = $this->tcgImporterService->getTotalCardsCount($use_test_file);
    // } catch(\Exception $e) {
    //   $total = 0;
    // }

    // $form['info'] = [
    //   '#markup' => '<div class="messages messages--info"><p>' . $this->t('Συνολικές κάρτες διαθέσιμες στο αρχείο JSON: <strong>@total</strong>', ['@total' => $total]) . '</p></div>',
    //   '#type' => 'container',
    //   '#attributes' => ['id' => 'info-messages']
    // ];

    $form['file'] = [
      '#type' => 'file',
      '#title' => $this->t('Aρχείο Προιόντων'),
      '#description' => $this->t('Ανεβάστε ενα νέο αρχείο με προιόντα προς εισαγωγή.'),
      '#upload_validators' => [
        'file_validate_extensions' => ['json'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="use_test_file"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['use_test_file'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Χρήση δοκιμαστικού αρχείου.'),
      '#description' => $this->t('Ανεβάστε ενα νέο αρχείο με προιόντα προς εισαγωγή με χρήση δοκιμαστικού αρχείου με dummy προιόντα.'),
      '#default_value' => FALSE,
      // '#ajax' => [
      //   'callback' => '::updateFormCallback',
      //   'wrapper' => 'info-messages'
      // ]
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Εκκίνηση Εισαγωγής'),
        '#button_type' => 'primary',
        // '#disabled' => ($total === 0),
      ],
    ];

    return $form;
  }

  // /**
  //  * AJAX callback για την ανανέωση της φόρμας.
  //  */
  // public static function updateFormCallback(array &$form, FormStateInterface $form_state): array {
  //   return $form['info'];
  // }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $use_test_file = (bool) $form_state->getValue('use_test_file');

    if (!$use_test_file) {
      $all_files = $this->getRequest()->files->get('files', []);
      $uploaded_file = $all_files['file'] ?? NULL;

      if ($uploaded_file instanceof UploadedFile && $uploaded_file->isValid()) {

        if (strtolower($uploaded_file->getClientOriginalExtension()) !== 'json') {
          $this->messenger()->addError($this->t('Το αρχείο πρέπει να είναι τύπου JSON.'));
          return;
        }

        $file_contents = file_get_contents($uploaded_file->getPathname());
        json_decode($file_contents);
        if (json_last_error() !== JSON_ERROR_NONE) {
          $this->messenger()->addError($this->t('Το αρχείο δεν περιέχει έγκυρο JSON.'));
          return;
        }

        $data_dir = 'private://tcg_importer_data/imported_files';

        if (!$this->fileSystem->prepareDirectory($data_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
          $this->messenger()->addError($this->t('Αδυναμία πρόσβασης στον φάκελο αποθήκευσης.'));
          return;
        }

        $filename = 'TCG-CARDS-' . date('Y-m-d') . '.json';

        try {
          $file = $this->fileRepository->writeData(
            $file_contents,
            $data_dir . '/' . $filename,
            FileSystemInterface::EXISTS_REPLACE
          );
        }
        catch (FileException $e) {
          $this->messenger()->addError($this->t('Σφάλμα κατά την αποθήκευση του αρχείου: @message', ['@message' => $e->getMessage()]));
          return;
        }

        if (!$file) {
          $this->messenger()->addError($this->t('Σφάλμα κατά την αποθήκευση του αρχείου.'));
          return;
        }

        $existing_files = $this->fileSystem->scanDirectory($data_dir, '/\.json$/');
        foreach ($existing_files as $uri => $object) {
          if ($uri !== $file->getFileUri()) {
            $this->fileSystem->delete($uri);
          }
        }
      }
    }

    // if ($this->tcgImporterService->getTotalCardsCount() === 0) {
    //   $this->messenger()->addError($this->t('Δε βρέθηκαν κάρτες στο αρχείο JSON.'));
    //   return;
    // }

    $this->tcgImporterService->runBatchImport($use_test_file);
  }
}
