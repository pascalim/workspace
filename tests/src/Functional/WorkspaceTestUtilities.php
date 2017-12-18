<?php

namespace Drupal\Tests\workspace\Functional;

use Drupal\node\Entity\NodeType;
use Drupal\workspace\Entity\Workspace;
use Drupal\workspace\Entity\WorkspaceInterface;

/**
 * Utility methods for use in BrowserTestBase tests.
 *
 * This trait will not work if not used in a child of BrowserTestBase.
 */
trait WorkspaceTestUtilities {

  /**
   * Loads a single entity by its label.
   *
   * The UI approach to creating an entity doesn't make it easy to know what
   * the ID is, so this lets us make paths for an entity after it's created.
   *
   * @param string $type
   *   The type of entity to load.
   * @param string $label
   *   The label of the entity to load.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity.
   */
  protected function getOneEntityByLabel($type, $label) {
    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
    $entity_type_manager = \Drupal::service('entity_type.manager');
    $property = $entity_type_manager->getDefinition($type)->getKey('label');
    $entity_list = $entity_type_manager->getStorage($type)->loadByProperties([$property => $label]);
    $entity = current($entity_list);
    if (!$entity) {
      $this->fail("No {$type} entity named {$label} found.");
    }

    return $entity;
  }

  /**
   * Creates a new Workspace through the UI.
   *
   * @param string $label
   *   The label of the workspace to create.
   * @param string $id
   *   The ID of the workspace to create.
   * @param string $upstream
   *   The ID of the replication handler plugin of the workspace.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The workspace that was just created.
   *
   * @throws \Behat\Mink\Exception\ElementNotFoundException
   */
  protected function createWorkspaceThroughUi($label, $id, $upstream = 'local_workspace:live') {
    $this->drupalPostForm('/admin/config/workflow/workspace/add', [
      'id' => $id,
      'label' => $label,
      'upstream[0][value]' => $upstream,
    ], 'Save');

    $this->getSession()->getPage()->hasContent("$label ($id)");

    return Workspace::load($id);
  }

  /**
   * Adds the workspace switcher block to the site.
   *
   * This is necessary for switchToWorkspace() to function correctly.
   */
  protected function setupWorkspaceSwitcherBlock() {
    // Add the block to the sidebar.
    $this->drupalPlaceBlock('workspace_switcher_block', [
      'id' => 'workspaceswitcher',
      'region' => 'sidebar_first',
      'label' => 'Workspace switcher',
    ]);

    // Confirm the block shows on the front page.
    $this->drupalGet('<front>');
    $page = $this->getSession()->getPage();

    $this->assertTrue($page->hasContent('Workspace switcher'));
  }

  /**
   * Sets a given workspace as "active" for subsequent requests.
   *
   * This assumes that the switcher block has already been setup by calling
   * setupWorkspaceSwitcherBlock().
   *
   * @param \Drupal\workspace\Entity\WorkspaceInterface $workspace
   *   The workspace to set active.
   */
  protected function switchToWorkspace(WorkspaceInterface $workspace) {
    /** @var \Drupal\workspace\WorkspaceManager $workspace_manager */
    $workspace_manager = \Drupal::service('workspace.manager');
    if ($workspace_manager->getActiveWorkspace() !== $workspace->id()) {
      // Switch the system under test to the specified workspace.
      /** @var \Drupal\Tests\WebAssert $session */
      $session = $this->assertSession();
      $session->buttonExists('Activate');
      $this->drupalPostForm(NULL, ['workspace_id' => $workspace->id()], t('Activate'));
      $session->pageTextContains($workspace->label() . ' is now the active workspace.');

      // Switch the test runner's context to the specified workspace.
      \Drupal::service('workspace.manager')->setActiveWorkspace($workspace);

      // If we don't do both of those, test runner utility methods will not be
      // run in the same workspace as the system under test, and you'll be left
      // wondering why your test runner cannot find content you just created.
    }
  }

  /**
   * Creates a new node type.
   *
   * @param string $label
   *   The human-readable label of the type to create.
   * @param string $machine_name
   *   The machine name of the type to create.
   */
  protected function createNodeType($label, $machine_name) {
    $node_type = NodeType::create([
      'type' => $machine_name,
      'label' => $label,
    ]);
    $node_type->save();
  }

  /**
   * Creates a node by "clicking" buttons.
   *
   * @param string $label
   *   The label of the Node to create.
   * @param string $bundle
   *   The bundle of the Node to create.
   * @param bool $publish
   *   The publishing status to set.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The Node that was just created.
   *
   * @throws \Behat\Mink\Exception\ElementNotFoundException
   */
  protected function createNodeThroughUi($label, $bundle, $publish = TRUE) {
    $this->drupalGet('/node/add/' . $bundle);

    /** @var \Behat\Mink\Session $session */
    $session = $this->getSession();
    $this->assertSession()->statusCodeEquals(200);

    /** @var \Behat\Mink\Element\DocumentElement $page */
    $page = $session->getPage();
    $page->fillField('Title', $label);
    if ($publish) {
      $page->findButton(t('Save'))->click();
    }
    else {
      $page->uncheckField('Published');
      $page->findButton(t('Save'))->click();
    }

    $session->getPage()->hasContent("{$label} has been created");

    return $this->getOneEntityByLabel('node', $label);
  }

  /**
   * Determine if the content list has an entity's label.
   *
   * This assertion can be used to validate a particular entity exists in the
   * current workspace.
   */
  protected function isLabelInContentOverview($label) {
    $this->drupalGet('/admin/content');
    $session = $this->getSession();
    $this->assertSession()->statusCodeEquals(200);
    $page = $session->getPage();
    return $page->hasContent($label);
  }

}
