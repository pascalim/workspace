<?php

namespace Drupal\workspace\Plugin\RepositoryHandler;

use Drupal\workspace\RepositoryHandlerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\workspace\RepositoryHandlerInterface;
use Drupal\workspace\WorkspaceConflictException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a plugin which replicates content to the default (Live) workspace.
 *
 * @RepositoryHandler(
 *   id = "live",
 *   label = @Translation("Live"),
 *   description = @Translation("The default (Live) workspace."),
 * )
 */
class LiveRepositoryHandler extends RepositoryHandlerBase implements RepositoryHandlerInterface, ContainerFactoryPluginInterface {

  /**
   * The source workspace entity for the repository handler.
   *
   * @var \Drupal\workspace\WorkspaceInterface
   */
  protected $sourceWorkspace;

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
   * The workspace association storage.
   *
   * @var \Drupal\workspace\WorkspaceAssociationStorageInterface
   */
  protected $workspaceAssociationStorage;

  /**
   * Constructs a new DefaultRepositoryHandler.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->workspaceAssociationStorage = $entity_type_manager->getStorage('workspace_association');
    $this->sourceWorkspace = $this->entityTypeManager->getStorage('workspace')->load($this->source);
    $this->targetWorkspace = $this->entityTypeManager->getStorage('workspace')->load($this->target);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $this->dependencies = parent::calculateDependencies();
    $this->addDependency($this->sourceWorkspace->getConfigDependencyKey(), $this->sourceWorkspace->getConfigDependencyName());

    return $this->dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function push() {
    if ($this->checkConflictsOnTarget()) {
      throw new WorkspaceConflictException();
    }

    foreach ($this->getSourceRevisionDifference() as $entity_type_id => $revision_difference) {
      $entity_revisions = $this->entityTypeManager->getStorage($entity_type_id)->loadMultipleRevisions(array_keys($revision_difference));
      /** @var \Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Entity\RevisionableInterface $entity */
      foreach ($entity_revisions as $entity) {
        // When pushing workspace-specific revisions to the default workspace
        // (Live), we simply need to mark them as default revisions.
        $entity->_isReplicating = TRUE;
        $entity->isDefaultRevision(TRUE);
        $entity->save();
      }
    }

    // Mark all the Workspace association entities as pushed.
    $this->workspaceAssociationStorage->markAsPushed($this->sourceWorkspace);
  }

  /**
   * {@inheritdoc}
   */
  public function pull() {
    // Nothing to do for now, pulling in changes can only be implemented when we
    // are able to resolve conflicts.
  }

  /**
   * {@inheritdoc}
   */
  public function checkConflictsOnTarget() {
    // Nothing to do for now, we can not get to a conflicting state because an
    // entity which is being edited in a workspace can not be edited in any
    // other workspace.
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetRevisionDifference() {
    $target_revision_difference = [];

    $tracked_entities = $this->workspaceAssociationStorage->getTrackedEntities($this->source);
    foreach ($tracked_entities as $entity_type_id => $tracked_revisions) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

      // Get the latest revision IDs for all the entities that are tracked by
      // the source workspace.
      $query = $this->entityTypeManager
        ->getStorage($entity_type_id)
        ->getQuery()
        ->condition($entity_type->getKey('id'), $tracked_revisions, 'IN')
        ->latestRevision();
      $result = $query->execute();

      // Now we compare the revision IDs which are tracked by the source
      // workspace to the latest revision IDs of those entities and the
      // difference between these two arrays gives us all the entities which
      // have been modified on the target.
      if ($revision_difference = array_diff_key($result, $tracked_revisions)) {
        $target_revision_difference[$entity_type_id] = $revision_difference;
      }
    }

    return $target_revision_difference;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceRevisionDifference() {
    // Get the Workspace association revisions which haven't been pushed yet.
    return $this->workspaceAssociationStorage->getTrackedEntities($this->source, FALSE, FALSE);
  }

}
