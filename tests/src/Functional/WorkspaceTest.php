<?php

namespace Drupal\Tests\workspace\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Test the workspace entity.
 *
 * @group workspace
 */
class WorkspaceTest extends BrowserTestBase {
  use WorkspaceTestUtilities;

  public static $modules = ['workspace'];

  /**
   * A test user.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $editor1;

  /**
   * A test user.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $editor2;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $permissions = [
      'access administration pages',
      'administer site configuration',
      'create workspace',
      'edit own workspace',
    ];

    $this->editor1 = $this->drupalCreateUser($permissions);
    $this->editor2 = $this->drupalCreateUser($permissions);
  }

  /**
   * Test creating a workspace with special characters.
   */
  public function testSpecialCharacters() {
    $this->drupalLogin($this->editor1);

    // Test a valid workspace name
    $this->createWorkspaceThroughUI('Workspace 1', 'a0_$()+-/');

    // Test and invaid workspace name
    $this->drupalGet('/admin/config/workflow/workspace/add');
    $session = $this->getSession();
    $this->assertEquals(200, $session->getStatusCode());
    $page = $session->getPage();
    $page->fillField('label', 'workspace2');
    $page->fillField('id', 'A!"£%^&*{}#~@?');
    $page->findButton(t('Save'))->click();
    $session->getPage()->hasContent("This value is not valid");
  }

  /**
   * Test changing the owner of a workspace.
   */
  public function testWorkspaceOwner() {
    $this->drupalLogin($this->editor1);

    $this->drupalPostForm('/admin/config/workflow/workspace/add', [
      'id' => 'test_workspace',
      'label' => 'Test workspace',
    ], 'Save');

    $storage = \Drupal::entityTypeManager()->getStorage('workspace');
    $test_workspace = $storage->load('test_workspace');
    $this->assertEquals($this->editor1->id(), $test_workspace->getOwnerId());

    $this->drupalPostForm('/admin/config/workflow/workspace/test_workspace/edit', [
      'uid[0][target_id]' => $this->editor2->getUsername(),
    ], 'Save');

    $test_workspace = $storage->loadUnchanged('test_workspace');
    $this->assertEquals($this->editor2->id(), $test_workspace->getOwnerId());
  }

}
