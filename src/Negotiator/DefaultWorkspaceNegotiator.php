<?php

namespace Drupal\workspace\Negotiator;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\workspace\WorkspaceInterface;
use Drupal\workspace\WorkspaceManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines the default workspace negotiator.
 */
class DefaultWorkspaceNegotiator implements WorkspaceNegotiatorInterface {

  /**
   * The workspace storage handler.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $workspaceStorage;

  /**
   * The default workspace entity.
   *
   * @var \Drupal\workspace\WorkspaceInterface
   */
  protected $defaultWorkspace;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->workspaceStorage = $entity_type_manager->getStorage('workspace');
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getWorkspace(Request $request) {
    if (!$this->defaultWorkspace) {
      $default_workspace = $this->workspaceStorage->create([
        'id' => WorkspaceManager::DEFAULT_WORKSPACE,
        'label' => Unicode::ucwords(WorkspaceManager::DEFAULT_WORKSPACE),
      ]);
      $default_workspace->enforceIsNew(FALSE);

      $this->defaultWorkspace = $default_workspace;
    }

    return $this->defaultWorkspace;
  }

  /**
   * {@inheritdoc}
   */
  public function setWorkspace(WorkspaceInterface $workspace) {}

}
