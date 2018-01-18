<?php

namespace Drupal\workspace;

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

}
