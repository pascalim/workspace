<?php

namespace Drupal\Tests\workspace\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\workspace\Entity\Workspace;

/**
 * Tests permission controls on workspaces.
 *
 * @group workspace
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WorkspacePermissionsTest extends BrowserTestBase {
  use WorkspaceTestUtilities;

  /**
   * The modules to enable.
   *
   * @var array
   */
  public static $modules = ['workspace', 'workspace'];

  /**
   * Verifies that a user can create but not edit a workspace.
   *
   * @throws \Behat\Mink\Exception\ElementNotFoundException
   */
  public function testCreateWorkspace() {
    $editor = $this->drupalCreateUser([
      'access administration pages',
      'administer site configuration',
      'create workspace',
    ]);

    // Login as a limited-access user and create a workspace.
    $this->drupalLogin($editor);
    $this->createWorkspaceThroughUi('Bears', 'bears');

    // Now edit that same workspace; We shouldn't be able to do so, since
    // we don't have edit permissions.
    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $etm */
    $etm = \Drupal::service('entity_type.manager');
    /** @var \Drupal\workspace\Entity\WorkspaceInterface $bears */
    $entity_list = $etm->getStorage('workspace')->loadByProperties(['label' => 'Bears']);
    $bears = current($entity_list);

    $this->drupalGet("/admin/config/workflow/workspace/{$bears->id()}/edit");
    $this->assertSession()->statusCodeEquals(403);

    // @todo add Deletion checks once there's a UI for deletion.
  }

  /**
   * Verifies that a user can create and edit only their own workspace.
   */
  public function testEditOwnWorkspace() {
    $permissions = [
      'access administration pages',
      'administer site configuration',
      'create workspace',
      'edit own workspace',
    ];

    $editor1 = $this->drupalCreateUser($permissions);

    // Login as a limited-access user and create a workspace.
    $this->drupalLogin($editor1);

    $this->createWorkspaceThroughUi('Bears', 'bears');

    // Now edit that same workspace; We should be able to do so.
    $bears = Workspace::load('bears');

    $session = $this->getSession();

    $this->drupalGet("/admin/config/workflow/workspace/{$bears->id()}/edit");
    $this->assertEquals(200, $session->getStatusCode());

    $page = $session->getPage();
    $page->fillField('label', 'Bears again');
    $page->fillField('id', 'bears');
    $page->findButton(t('Save'))->click();
    $session->getPage()->hasContent('Bears again (bears)');

    // Now login as a different user and ensure they don't have edit access,
    // and vice versa.
    $editor2 = $this->drupalCreateUser($permissions);

    $this->drupalLogin($editor2);
    $session = $this->getSession();

    $this->createWorkspaceThroughUi('Packers', 'packers');

    $packers = Workspace::load('packers');

    $this->drupalGet("/admin/config/workflow/workspace/{$packers->id()}/edit");
    $this->assertEquals(200, $session->getStatusCode());

    $this->drupalGet("/admin/config/workflow/workspace/{$bears->id()}/edit");
    $this->assertEquals(403, $session->getStatusCode());
  }

  /**
   * Verifies that a user can edit any workspace.
   */
  public function testEditAnyWorkspace() {
    $permissions = [
      'access administration pages',
      'administer site configuration',
      'create workspace',
      'edit own workspace',
    ];

    $editor1 = $this->drupalCreateUser($permissions);

    // Login as a limited-access user and create a workspace.
    $this->drupalLogin($editor1);

    $this->createWorkspaceThroughUi('Bears', 'bears');

    // Now edit that same workspace; We should be able to do so.
    $bears = Workspace::load('bears');

    $session = $this->getSession();

    $this->drupalGet("/admin/config/workflow/workspace/{$bears->id()}/edit");
    $this->assertEquals(200, $session->getStatusCode());

    $page = $session->getPage();
    $page->fillField('label', 'Bears again');
    $page->fillField('id', 'bears');
    $page->findButton(t('Save'))->click();
    $session->getPage()->hasContent('Bears again (bears)');

    // Now login as a different user and ensure they don't have edit access,
    // and vice versa.
    $admin = $this->drupalCreateUser(array_merge($permissions, ['edit any workspace']));

    $this->drupalLogin($admin);
    $session = $this->getSession();

    $this->createWorkspaceThroughUi('Packers', 'packers');

    $packers = Workspace::load('packers');

    $this->drupalGet("/admin/config/workflow/workspace/{$packers->id()}/edit");
    $this->assertEquals(200, $session->getStatusCode());

    $this->drupalGet("/admin/config/workflow/workspace/{$bears->id()}/edit");
    $this->assertEquals(200, $session->getStatusCode());
  }

}
