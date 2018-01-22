<?php

namespace Drupal\workspace;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Defines the storage handler class for the Workspace association entity type.
 */
class WorkspaceAssociationStorage extends SqlContentEntityStorage implements WorkspaceAssociationStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function markAsPushed(WorkspaceInterface $workspace) {
    $this->database
      ->update($this->entityType->getBaseTable())
      ->fields(['pushed' => TRUE])
      ->condition('workspace', $workspace->id())
      ->execute();
    $this->database
      ->update($this->entityType->getRevisionTable())
      ->fields(['pushed' => TRUE])
      ->condition('workspace', $workspace->id())
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getTrackedEntities($workspace_id, $all_revisions = FALSE, $pushed = NULL, $group = TRUE) {
    $table = $all_revisions ? $this->getRevisionTable() : $this->getBaseTable();
    $query = $this->database->select($table, 'base_table');
    $query
      ->fields('base_table', ['content_entity_type_id', 'content_entity_id', 'content_entity_revision_id'])
      ->condition('workspace', $workspace_id);

    if ($pushed !== NULL) {
      $query->condition('pushed', $pushed);
    }

    $tracked_revisions = [];
    foreach ($query->execute() as $record) {
      if ($group) {
        $tracked_revisions[$record->content_entity_type_id][$record->content_entity_revision_id] = $record->content_entity_id;
      }
      else {
        $tracked_revisions[] = [
          'entity_type_id' => $record->content_entity_type_id,
          'revision_id' => $record->content_entity_revision_id,
          'entity_id' => $record->content_entity_id,
        ];
      }
    }

    return $tracked_revisions;
  }

  /**
   * {@inheritdoc}
   */
  public function isEntityTracked(EntityInterface $entity, $pushed = NULL) {
    $query = $this->database->select($this->getBaseTable(), 'base_table');
    $query
      ->fields('base_table', ['workspace'])
      ->condition('content_entity_type_id', $entity->getEntityTypeId())
      ->condition('content_entity_id', $entity->id());

    if ($pushed !== NULL) {
      $query->condition('pushed', $pushed);
    }

    return $query->execute()->fetchCol();
  }

}
