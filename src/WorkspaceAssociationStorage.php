<?php

namespace Drupal\workspace;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Defines the storage handler class for the Workspace association entity type.
 */
class WorkspaceAssociationStorage extends SqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  public function markAsDeployed(WorkspaceInterface $workspace) {
    $this->database
      ->update($this->entityType->getBaseTable())
      ->fields(['deployed' => TRUE])
      ->condition('workspace', $workspace->id())
      ->execute();
    $this->database
      ->update($this->entityType->getRevisionTable())
      ->fields(['deployed' => TRUE])
      ->condition('workspace', $workspace->id())
      ->execute();
  }

}
