<?php

namespace Drupal\workspace;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\workspace\Entity\WorkspaceAssociation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a class for reacting to entity events.
 *
 * @internal
 */
class EntityOperations implements ContainerInjectionInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The workspace manager service.
   *
   * @var \Drupal\workspace\WorkspaceManagerInterface
   */
  protected $workspaceManager;

  /**
   * Constructs a new EntityOperations instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\workspace\WorkspaceManagerInterface $workspace_manager
   *   The workspace manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WorkspaceManagerInterface $workspace_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->workspaceManager = $workspace_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('workspace.manager')
    );
  }

  /**
   * Acts on entities when loaded.
   *
   * @see hook_entity_load()
   */
  public function entityLoad(array &$entities, $entity_type_id) {
    // Only run if the entity type can belong to a workspace and we are in a
    // non-default workspace.
    if (!$this->workspaceManager->entityTypeCanBelongToWorkspaces($this->entityTypeManager->getDefinition($entity_type_id))
       || (($active_workspace = $this->workspaceManager->getActiveWorkspace()) && $active_workspace->isDefaultWorkspace())) {
      return;
    }

    // Get a list of revision IDs for entities that have a revision set for the
    // current active workspace. If an entity has multiple revisions set for a
    // workspace, only the one with the highest ID is returned.
    $entity_ids = array_keys($entities);
    $max_revision_id = 'max_content_entity_revision_id';
    $results = $this->entityTypeManager
      ->getStorage('workspace_association')
      ->getAggregateQuery()
      ->allRevisions()
      ->aggregate('content_entity_revision_id', 'MAX', NULL, $max_revision_id)
      ->groupBy('content_entity_id')
      ->condition('content_entity_type_id', $entity_type_id)
      ->condition('content_entity_id', $entity_ids, 'IN')
      ->condition('workspace', $active_workspace->id(), '=')
      ->execute();

    // Since hook_entity_load() is called on both regular entity load as well as
    // entity revision load, we need to prevent infinite recursion by checking
    // whether the default revisions were already swapped with the workspace
    // revision.
    // @todo This recursion protection should be removed when
    //   https://www.drupal.org/project/drupal/issues/2928888 is resolved.
    if ($results) {
      foreach ($results as $key => $result) {
        if ($entities[$result['content_entity_id']]->getRevisionId() == $result[$max_revision_id]) {
          unset($results[$key]);
        }
      }
    }

    if ($results) {
      /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage($entity_type_id);

      // Swap out every entity which has a revision set for the current active
      // workspace.
      $swap_revision_ids = array_column($results, $max_revision_id);
      foreach ($storage->loadMultipleRevisions($swap_revision_ids) as $revision) {
        $entities[$revision->id()] = $revision;
      }
    }
  }

  /**
   * Acts on an entity before it is created or updated.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @see hook_entity_presave()
   */
  public function entityPresave(EntityInterface $entity) {
    /** @var \Drupal\Core\Entity\RevisionableInterface|\Drupal\Core\Entity\EntityPublishedInterface $entity */
    // Only run if the entity type can belong to a workspace and we are in a
    // non-default workspace.
    if (!$this->workspaceManager->entityTypeCanBelongToWorkspaces($entity->getEntityType())
       || $this->workspaceManager->getActiveWorkspace()->isDefaultWorkspace()) {
      return;
    }

    // Force a new revision if the entity is not replicating.
    if (!$entity->isNew() && !isset($entity->_isReplicating)) {
      $entity->setNewRevision(TRUE);

      // All entities in the non-default workspace are pending revisions,
      // regardless of their publishing status. This means that when creating
      // a published pending revision in a non-default workspace it will also be
      // a published pending revision in the default workspace, however, it will
      // become the default revision only when it is replicated to the default
      // workspace.
      $entity->isDefaultRevision(FALSE);
    }

    // When a new published entity is inserted in a non-default workspace, we
    // actually want two revisions to be saved:
    // - An unpublished default revision in the default ('live') workspace.
    // - A published pending revision in the current workspace.
    if ($entity->isNew() && $entity->isPublished()) {
      // Keep track of the publishing status for workspace_entity_insert() and
      // unpublish the default revision.
      $entity->_initialPublished = TRUE;
      $entity->setUnpublished();
    }
  }

  /**
   * Responds to the creation of a new entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was just saved.
   *
   * @see hook_entity_insert()
   */
  public function entityInsert(EntityInterface $entity) {
    /** @var \Drupal\Core\Entity\RevisionableInterface|\Drupal\Core\Entity\EntityPublishedInterface $entity */
    // Only run if the entity type can belong to a workspace and we are in a
    // non-default workspace.
    if (!$this->workspaceManager->entityTypeCanBelongToWorkspaces($entity->getEntityType())
       || $this->workspaceManager->getActiveWorkspace()->isDefaultWorkspace()) {
      return;
    }

    $this->trackEntity($entity);

    // Handle the case when a new published entity was created in a non-default
    // workspace and create a published pending revision for it.
    if (isset($entity->_initialPublished)) {
      // Operate on a clone to avoid changing the entity prior to subsequent
      // hook_entity_insert() implementations.
      $pending_revision = clone $entity;
      $pending_revision->setPublished();
      $pending_revision->isDefaultRevision(FALSE);
      $pending_revision->save();
    }
  }

  /**
   * Responds to updates to an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was just saved.
   *
   * @see hook_entity_update()
   */
  public function entityUpdate(EntityInterface $entity) {
    // Only run if the entity type can belong to a workspace and we are in a
    // non-default workspace.
    if (!$this->workspaceManager->entityTypeCanBelongToWorkspaces($entity->getEntityType())
       || $this->workspaceManager->getActiveWorkspace()->isDefaultWorkspace()) {
      return;
    }

    // Only track new revisions.
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    if ($entity->getLoadedRevisionId() != $entity->getRevisionId()) {
      $this->trackEntity($entity);
    }
  }

  /**
   * Updates or creates a WorkspaceAssociation entity for a given entity.
   *
   * If the passed-in entity can belong to a workspace and already has a
   * WorkspaceAssociation entity, then a new revision of this will be created with
   * the new information. Otherwise, a new WorkspaceAssociation entity is created to
   * store the passed-in entity's information.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to update or create from.
   */
  protected function trackEntity(EntityInterface $entity) {
    /** @var \Drupal\Core\Entity\RevisionableInterface|\Drupal\Core\Entity\EntityPublishedInterface $entity */
    // If the entity is not new, check if there's an existing
    // WorkspaceAssociation entity for it.
    if (!$entity->isNew()) {
      $workspace_associations = $this->entityTypeManager
        ->getStorage('workspace_association')
        ->loadByProperties([
          'content_entity_type_id' => $entity->getEntityTypeId(),
          'content_entity_id' => $entity->id(),
        ]);

      /** @var \Drupal\Core\Entity\ContentEntityInterface $workspace_association */
      $workspace_association = reset($workspace_associations);
    }

    // If there was a WorkspaceAssociation entry create a new revision,
    // otherwise create a new entity with the type and ID.
    if (!empty($workspace_association)) {
      $workspace_association->setNewRevision(TRUE);
    }
    else {
      $workspace_association = WorkspaceAssociation::create([
        'content_entity_type_id' => $entity->getEntityTypeId(),
        'content_entity_id' => $entity->id(),
      ]);
    }

    // Add the revision ID and the workspace ID.
    $workspace_association->set('content_entity_revision_id', $entity->getRevisionId());
    $workspace_association->set('workspace', $this->workspaceManager->getActiveWorkspace()->id());

    // Save without updating the tracked content entity.
    $workspace_association->save();
  }

}
