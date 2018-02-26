<?php

namespace Drupal\Tests\workspace\Kernel;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\rest\RestResourceConfigInterface;

/**
 * Tests REST module with internal workspace entity types.
 *
 * @group workspace
 */
class WorkspaceInternalResourceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = ['user', 'serialization', 'rest', 'workspace'];

  /**
   * Tests enabling workspace associations for REST throws an exception.
   *
   * @see \Drupal\workspace\Entity\WorkspaceAssociation
   */
  public function testCreateWorkspaceAssociationResource() {
    $this->setExpectedException(PluginNotFoundException::class, 'The "entity:workspace_association" plugin does not exist.');
    RestResourceConfig::create([
      'id' => 'entity.workspace_association',
      'granularity' => RestResourceConfigInterface::RESOURCE_GRANULARITY,
      'configuration' => [
        'methods' => ['GET'],
        'formats' => ['json'],
        'authentication' => ['cookie'],
      ],
    ])
      ->enable()
      ->save();
  }

}
