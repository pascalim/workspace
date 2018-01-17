<?php

namespace Drupal\workspace\Plugin\RepositoryHandler;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\workspace\RepositoryHandlerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\workspace\RepositoryHandlerInterface;
use Drupal\workspace\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a repository handler plugin that provides local content replication.
 *
 * This plugin provides the ability to replicate content between workspaces that
 * are defined in the same Drupal installation.
 *
 * @RepositoryHandler(
 *   id = "local_workspace",
 *   label = @Translation("Local workspace"),
 *   description = @Translation("A workspace that is defined in the local Drupal installation."),
 *   deriver = "Drupal\workspace\Plugin\Deriver\LocalWorkspaceRepositoryHandlerDeriver",
 * )
 */
class LocalWorkspaceRepositoryHandler extends RepositoryHandlerBase implements RepositoryHandlerInterface, ContainerFactoryPluginInterface {

  /**
   * The target workspace entity for the repository handler.
   *
   * @var \Drupal\workspace\WorkspaceInterface
   */
  protected $targetWorkspace;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The workspace manager.
   *
   * @var \Drupal\workspace\WorkspaceManagerInterface
   */
  protected $workspaceManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * Constructs a new LocalWorkspaceRepositoryHandler.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\workspace\WorkspaceManagerInterface $workspace_manager
   *   The workspace manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, WorkspaceManagerInterface $workspace_manager, Connection $database, UuidInterface $uuid_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->workspaceManager = $workspace_manager;
    $this->database = $database;
    $this->uuidService = $uuid_service;
    $this->targetWorkspace = $this->entityTypeManager->getStorage('workspace')->load($this->getDerivativeId());
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('workspace.manager'),
      $container->get('database'),
      $container->get('uuid')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->targetWorkspace->label();
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $this->dependencies = parent::calculateDependencies();
    $this->addDependency($this->targetWorkspace->getConfigDependencyKey(), $this->targetWorkspace->getConfigDependencyName());

    return $this->dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function replicate(RepositoryHandlerInterface $source, RepositoryHandlerInterface $target) {
    // Replicating content from one workspace to another on the same site
    // roughly follows the CouchDB replication protocol.
    // @see http://docs.couchdb.org/en/2.1.0/replication/protocol.html
    /** @var \Drupal\workspace\WorkspaceInterface $source_workspace */
    $source_workspace = $this->entityTypeManager->getStorage('workspace')->load($source->getDerivativeId());
    /** @var \Drupal\workspace\WorkspaceInterface $target_workspace */
    $target_workspace = $this->entityTypeManager->getStorage('workspace')->load($target->getDerivativeId());

    // Get the current active workspace, so we can set it back as the active
    // after the replication has completed.
    $current_active = $this->workspaceManager->getActiveWorkspace();

    // Set the source as the active workspace, so we can fetch all the entities
    // relative to the source workspace.
    $this->workspaceManager->setActiveWorkspace($source_workspace);

    $workspace_association_ids = [];
    foreach ($this->workspaceManager->getSupportedEntityTypes() as $entity_type_id => $entity_type) {
      // Get all entity revision IDs for all entities which are in only one
      // of either the source or the target workspaces. We assume that this
      // means the revision is in the source, but not the target, and the
      // revision has not been replicated yet.
      $select = $this->database
        ->select('workspace_association_revision', 'war')
        ->fields('war', ['content_entity_revision_id']);
      $select->condition('content_entity_type_id', $entity_type_id);
      $select->condition('workspace', [$source_workspace->id(), $target_workspace->id()], 'IN');
      $select->groupBy('content_entity_revision_id');
      $select->having('count(workspace) < :workspaces', [':workspaces' => 2]);
      $revision_difference = $select->execute()->fetchCol();

      if (!empty($revision_difference)) {
        // Get the workspace association IDs for all of the entity revision IDs
        // which are not yet in the target workspace.
        $workspace_association_ids[$entity_type_id] = $this->entityTypeManager
          ->getStorage('workspace_association')
          ->getQuery()
          ->allRevisions()
          ->condition('content_entity_type_id', $entity_type_id)
          ->condition('content_entity_revision_id', $revision_difference, 'IN')
          ->condition('workspace', $source_workspace->id())
          ->execute();
      }
    }

    $entities = [];
    foreach ($workspace_association_ids as $entity_type_id => $ids) {
      foreach ($ids as $revision_id => $entity_id) {
        // Get the workspace association entity for revision that is in the source
        // workspace.
        /** @var \Drupal\Core\Entity\ContentEntityInterface $workspace_association */
        $workspace_association = $this->entityTypeManager->getStorage('workspace_association')->loadRevision($revision_id);
        if ($target_workspace->isDefaultWorkspace()) {
          // If the target workspace is the default workspace, the revision
          // needs to be set to the default revision.
          /** @var \Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Entity\RevisionableInterface $entity */
          $entity = $this->entityTypeManager
            ->getStorage($workspace_association->content_entity_type_id->value)
            ->loadRevision($workspace_association->content_entity_revision_id->value);
          $entity->_isReplicating = TRUE;
          $entity->isDefaultRevision(TRUE);
          $entities[] = $entity;
        }
        else {
          // If the target workspace is not the default workspace, the content
          // workspace link entity can simply be updated with the target
          // workspace.
          $workspace_association->setNewRevision(TRUE);
          $workspace_association->workspace->target_id = $target_workspace->id();
          $workspace_association->save();
        }
      }
    }

    // Only switch to the target workspace and save entities if there are some
    // to save.
    if (!empty($entities)) {
      // Before saving set the active workspace to the target.
      $this->workspaceManager->setActiveWorkspace($target_workspace);
      // Save each revision on the target workspace.
      foreach ($entities as $entity) {
        $entity->save();
      }
    }

    // Switch back to the original active workspace, so that the user performing
    // the replication is back on the workspace they started on.
    $this->workspaceManager->setActiveWorkspace($current_active);

    // Mark all the Workspace association entities as deployed.
    $this->entityTypeManager->getStorage('workspace_association')->markAsDeployed($source_workspace);
  }

}
