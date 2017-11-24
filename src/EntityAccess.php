<?php

namespace Drupal\workspace;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\workspace\Entity\WorkspaceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service wrapper for hooks relating to entity access control.
 */
class EntityAccess implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\workspace\WorkspaceManagerInterface
   */
  protected $workspaceManager;

  /**
   * Constructs a new EntityAccess.
   *
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param WorkspaceManagerInterface $workspace_manager
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
   * Hook bridge;
   *
   * @see hook_entity_access()
   *
   * @param EntityInterface $entity
   * @param string $operation
   * @param AccountInterface $account
   *
   * @return AccessResult
   */
  public function entityAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\workspace\WorkspaceManagerInterface $workspace_manager */
    $workspace_manager = \Drupal::service('workspace.manager');

    // Workspaces themselves are handled by their own access handler and we
    // should not try to do any access checks for entity types that can not
    // belong to a workspace.
    if ($entity->getEntityTypeId() === 'workspace' || !$workspace_manager->entityTypeCanBelongToWorkspaces($entity->getEntityType())) {
      return AccessResult::neutral();
    }

    return $this->bypassAccessResult($account);
  }

  /**
   * Hook bridge;
   *
   * @see hook_entity_create_access()
   *
   * @param AccountInterface $account
   * @param array $context
   * @param $entity_bundle
   *
   * @return \Drupal\Core\Access\AccessResult
   */
  public function entityCreateAccess(AccountInterface $account, array $context, $entity_bundle) {
    // Workspaces themselves are handled by another hook. Ignore them here.
    if ($entity_bundle == 'workspace') {
      return AccessResult::neutral();
    }

    return $this->bypassAccessResult($account);
  }

  /**
   * @param AccountInterface $account
   * @return AccessResult
   */
  protected function bypassAccessResult(AccountInterface $account) {
    // This approach assumes that the current "global" active workspace is
    // correct, ie, if you're "in" a given workspace then you get ALL THE PERMS
    // to ALL THE THINGS! That's why this is a dangerous permission.
    $active_workspace = $this->workspaceManager->getActiveWorkspace(TRUE);

    return AccessResult::allowedIfHasPermission($account, 'bypass_entity_access_workspace_' . $active_workspace->id())
      ->orIf(
        AccessResult::allowedIf($active_workspace->getOwnerId() == $account->id())
          ->andIf(AccessResult::allowedIfHasPermission($account, 'bypass entity access own workspace'))
      );
  }

  /**
   * Returns an array of workspace-specific permissions.
   *
   * Note: This approach assumes that a site will have only a small number
   * of workspace entities, under a dozen. If there are many dozens of
   * workspaces defined then this approach will have scaling issues.
   *
   * @return array
   *   The workspace permissions.
   */
  public function workspacePermissions() {
    $perms = [];

    foreach ($this->getAllWorkspaces() as $workspace) {
      $perms += $this->createWorkspaceViewPermission($workspace)
      + $this->createWorkspaceEditPermission($workspace)
      + $this->createWorkspaceDeletePermission($workspace)
      + $this->createWorkspaceBypassPermission($workspace);
    }

    return $perms;
  }

  /**
   * Returns a list of all workspace entities in the system.
   *
   * @return \Drupal\workspace\Entity\WorkspaceInterface[]
   *   An array of workspace entities, keyed by their IDs.
   */
  protected function getAllWorkspaces() {
    return $this->entityTypeManager->getStorage('workspace')->loadMultiple();
  }

  /**
   * Derives the view permission for a specific workspace.
   *
   * @param \Drupal\workspace\Entity\WorkspaceInterface $workspace
   *   The workspace from which to derive the permission.
   * @return array
   *   A single-item array with the permission to define.
   */
  protected function createWorkspaceViewPermission(WorkspaceInterface $workspace) {
    $perms['view_workspace_' . $workspace->id()] = [
      'title' => $this->t('View the %workspace workspace', ['%workspace' => $workspace->label()]),
      'description' => $this->t('View the %workspace workspace and content within it', ['%workspace' => $workspace->label()]),
    ];

    return $perms;
  }

  /**
   * Derives the edit permission for a specific workspace.
   *
   * @param \Drupal\workspace\Entity\WorkspaceInterface $workspace
   *   The workspace from which to derive the permission.
   * @return array
   *   A single-item array with the permission to define.
   */
  protected function createWorkspaceEditPermission(WorkspaceInterface $workspace) {
    $perms['update_workspace_' . $workspace->id()] = [
      'title' => $this->t('Edit the %workspace workspace', ['%workspace' => $workspace->label()]),
      'description' => $this->t('Edit the %workspace workspace itself', ['%workspace' => $workspace->label()]),
    ];

    return $perms;
  }

  /**
   * Derives the delete permission for a specific workspace.
   *
   * @param \Drupal\workspace\Entity\WorkspaceInterface $workspace
   *   The workspace from which to derive the permission.
   * @return array
   *   A single-item array with the permission to define.
   */
  protected function createWorkspaceDeletePermission(WorkspaceInterface $workspace) {
    $perms['delete_workspace_' . $workspace->id()] = [
      'title' => $this->t('Delete the %workspace workspace', ['%workspace' => $workspace->label()]),
      'description' => $this->t('View the %workspace workspace and all content within it', ['%workspace' => $workspace->label()]),
    ];

    return $perms;
  }

  /**
   * Derives the delete permission for a specific workspace.
   *
   * @param \Drupal\workspace\Entity\WorkspaceInterface $workspace
   *   The workspace from which to derive the permission.
   * @return array
   *   A single-item array with the permission to define.
   */
  protected function createWorkspaceBypassPermission(WorkspaceInterface $workspace) {
    $perms['bypass_entity_access_workspace_' . $workspace->id()] = [
      'title' => $this->t('Bypass content entity access in %workspace workspace', ['%workspace' => $workspace->label()]),
      'description' => $this->t('Allow all Edit/Update/Delete permissions for all content in the %workspace workspace', ['%workspace' => $workspace->label()]),
      'restrict access' => TRUE,
    ];

    return $perms;
  }

}
