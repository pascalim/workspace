<?php

namespace Drupal\workspace\Negotiator;

use Drupal\workspace\WorkspaceInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Workspace negotiators provide a way to get the active workspace.
 *
 * \Drupal\workspace\WorkspaceManager acts as the service collector for
 * Workspace negotiators.
 */
interface WorkspaceNegotiatorInterface {

  /**
   * Checks whether the negotiator applies to the current request or not.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return bool
   *   TRUE if the negotiator applies for the current request, FALSE otherwise.
   */
  public function applies(Request $request);

  /**
   * Gets the negotiated workspace, if any.
   *
   * Note that it is the responsibility of each implementation to check whether
   * the negotiated workspace actually exists in the storage.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Drupal\workspace\WorkspaceInterface|null
   *   The negotiated workspace or NULL if the negotiator could not determine a
   *   valid workspace.
   */
  public function getWorkspace(Request $request);

  /**
   * Sets the negotiated workspace.
   *
   * @param \Drupal\workspace\WorkspaceInterface $workspace
   *   The workspace entity.
   */
  public function setWorkspace(WorkspaceInterface $workspace);

}
