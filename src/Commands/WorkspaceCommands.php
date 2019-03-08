<?php

namespace Drupal\workspace\Commands;

use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Drupal\Core\CronInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\multiversion\Entity\Workspace;
use Drupal\multiversion\Workspace\WorkspaceManagerInterface;

/**
 * Drush commands for workspace.
 */
class WorkspaceCommands extends DrushCommands {

  /**
   * The cron service.
   *
   * @var \Drupal\Core\CronInterface
   */
  protected $cron;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module installer.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * The workspace manager.
   *
   * @var \Drupal\multiversion\Workspace\WorkspaceManagerInterface
   */
  protected $workspaceManager;

  /**
   * WorkspaceCommands constructor.
   *
   * @param \Drupal\Core\CronInterface $cron
   *   The cron service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   The module installer.
   * @param \Drupal\multiversion\Workspace\WorkspaceManagerInterface $workspace_manager
   *   The workspace manager.
   */
  public function __construct(CronInterface $cron, EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_type_manager, ModuleInstallerInterface $module_installer, WorkspaceManagerInterface $workspace_manager) {
    parent::__construct();

    $this->cron = $cron;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleInstaller = $module_installer;
    $this->workspaceManager = $workspace_manager;
  }

  /**
   * Uninstall Workspace.
   *
   * @command workspace:uninstall
   * @aliases wu,workspace-uninstall
   */
  public function uninstall() {
    $extension = 'workspace';
    $uninstall = TRUE;
    $extension_info = system_rebuild_module_data();

    $info = $extension_info[$extension]->info;
    if ($info['required']) {
      $explanation = '';
      if (!empty($info['explanation'])) {
        $explanation = ' ' . dt('Reason: !explanation.', [
          '!explanation' => strip_tags($info['explanation']),
        ]);
      }
      $this->logger()->info(dt('!extension is a required extension and can\'t be uninstalled.', [
        '!extension' => $extension,
      ]) . $explanation);
      $uninstall = FALSE;
    }
    elseif (!$extension_info[$extension]->status) {
      $this->logger()->info(dt('!extension is already uninstalled.', [
        '!extension' => $extension,
      ]));
      $uninstall = FALSE;
    }
    elseif ($extension_info[$extension]->getType() == 'module') {
      $dependents = [];
      foreach (array_keys($extension_info[$extension]->required_by) as $dependent) {
        $dependent_info = $extension_info[$dependent];
        if (!$dependent_info->required && $dependent_info->status) {
          $dependents[] = $dependent;
        }
      }
      if (count($dependents)) {
        $this->logger()->error(dt('To uninstall !extension, the following extensions must be uninstalled first: !required', [
          '!extension' => $extension,
          '!required' => implode(', ', $dependents),
        ]));
        $uninstall = FALSE;
      }
    }

    if ($uninstall) {
      $this->output()->writeln(dt('Workspace will be uninstalled.'));
      if (!$this->io()->confirm(dt('Do you really want to continue?'))) {
        throw new UserAbortException();
      }

      try {
        $entity_type_manager = $this->entityTypeManager;
        $default_workspace_id = \Drupal::getContainer()->getParameter('workspace.default');
        $default_workspace = $entity_type_manager->getStorage('workspace')->load($default_workspace_id);
        $this->workspaceManager->setActiveWorkspace($default_workspace);
        $database = \Drupal::database();
        $database
          ->delete('key_value_expire')
          ->condition('collection', 'user.private_tmepstore.workspace.negotiator.session')
          ->execute();

        // Delete the 'workspace_replication' queue before deleting non-default
        // workspaces.
        \Drupal::queue('workspace_replication')->deleteQueue();

        // Delete all workspaces excluding the default workspace, also delete
        // all content from deleted workspaces.
        $workspaces = Workspace::loadMultiple();
        foreach ($workspaces as $workspace) {
          if (!$workspace->isDefaultWorkspace()) {
            $workspace->delete();
          }
        }
        $this->cron->run();

        // Delete all workspace_pointer entities.
        $storage = $entity_type_manager->getStorage('workspace_pointer');
        $entities = $storage->loadMultiple();
        $storage->delete($entities);

        // Delete all replication entities.
        $storage = $entity_type_manager->getStorage('replication');
        $entities = $storage->loadMultiple();
        $storage->delete($entities);

        // Set values for all fields provided by Workspace to NULL in the
        // database (for workspace entity type), so the module can be
        // uninstalled.
        $entity_field_manager = $this->entityFieldManager;
        $storage = $entity_type_manager->getStorage('workspace');
        $fields = [];
        foreach ($entity_field_manager->getFieldStorageDefinitions('workspace') as $storage_definition) {
          if ($storage_definition->getProvider() === 'workspace') {
            $fields[$storage_definition->getName()] = NULL;
          }
        }
        if (!empty($fields)) {
          $connection = Database::getConnection();
          $connection->update($storage->getEntityType()->getBaseTable())
            ->fields($fields)
            ->execute();
        }

        $this->moduleInstaller->uninstall([$extension]);
      }
      catch (\Exception $e) {
        $this->logger()->error($e->getMessage());
      }

      // Inform the user of final status.
      $this->logger()->info(dt('!extension was successfully uninstalled.', [
        '!extension' => $extension,
      ]));
    }
  }

}
