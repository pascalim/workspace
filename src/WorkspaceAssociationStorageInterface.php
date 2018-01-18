<?php

namespace Drupal\workspace;

use Drupal\Core\Entity\ContentEntityStorageInterface;

/**
 * Defines an interface for workspace association entity storage classes.
 */
interface WorkspaceAssociationStorageInterface extends ContentEntityStorageInterface {

  /**
   * Marks all workspace association entities pushed for a given workspace.
   *
   * @param \Drupal\workspace\WorkspaceInterface $workspace
   *   A workspace entity.
   */
  public function markAsPushed(WorkspaceInterface $workspace);

}
