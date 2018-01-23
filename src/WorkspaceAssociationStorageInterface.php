<?php

namespace Drupal\workspace;

use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityInterface;

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
  public function postPush(WorkspaceInterface $workspace);

  /**
   * Retrieves the content revisions tracked by a given workspace.
   *
   * @param string $workspace_id
   *   The ID of the workspace.
   * @param bool $all_revisions
   *   (optional) Whether to return all the tracked revisions for each entity or
   *   just the latest tracked revision. Defaults to FALSE.
   * @param bool $group
   *   (optional) Whether to group the results by their entity type ID. Defaults
   *   to TRUE.
   *
   * @return array
   *   Returns an array of entity identifiers which are tracked by a given
   *   workspace. If the $group parameter is TRUE, returns a multidimensional
   *   array where the first level keys are entity type IDs and the values are
   *   an array of entity IDs, keyed by revision IDs. If the $group parameter is
   *   FALSE, returns a single level array containing all the tracked entities.
   */
  public function getTrackedEntities($workspace_id, $all_revisions = FALSE, $group = TRUE);

  /**
   * Checks if a given entity is tracked in one or multiple workspaces.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object.
   *
   * @return string[]
   *   An array of workspace IDs where the given entity is tracked, or an empty
   *   array if it's not tracked anywhere.
   */
  public function isEntityTracked(EntityInterface $entity);

}
