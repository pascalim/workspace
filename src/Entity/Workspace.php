<?php

namespace Drupal\workspace\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;
use Drupal\workspace\RepositoryHandlerInterface;
use Drupal\workspace\WorkspaceInterface;
use Drupal\workspace\WorkspaceManager;

/**
 * The workspace entity class.
 *
 * @ContentEntityType(
 *   id = "workspace",
 *   label = @Translation("Workspace"),
 *   label_collection = @Translation("Workspaces"),
 *   label_singular = @Translation("workspace"),
 *   label_plural = @Translation("workspaces"),
 *   label_count = @PluralTranslation(
 *     singular = "@count workspace",
 *     plural = "@count workspaces"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "list_builder" = "\Drupal\workspace\WorkspaceListBuilder",
 *     "access" = "Drupal\workspace\WorkspaceAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "\Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *     "form" = {
 *       "default" = "\Drupal\workspace\Form\WorkspaceForm",
 *       "add" = "\Drupal\workspace\Form\WorkspaceForm",
 *       "edit" = "\Drupal\workspace\Form\WorkspaceForm",
 *       "delete" = "\Drupal\workspace\Form\WorkspaceDeleteForm",
 *       "activate" = "\Drupal\workspace\Form\WorkspaceActivateForm",
 *       "deploy" = "\Drupal\workspace\Form\WorkspaceDeployForm",
 *     },
 *   },
 *   admin_permission = "administer workspaces",
 *   base_table = "workspace",
 *   revision_table = "workspace_revision",
 *   data_table = "workspace_field_data",
 *   revision_data_table = "workspace_field_revision",
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "revision_id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "uid" = "uid",
 *   },
 *   links = {
 *     "add-form" = "/admin/config/workflow/workspace/add",
 *     "edit-form" = "/admin/config/workflow/workspace/{workspace}/edit",
 *     "delete-form" = "/admin/config/workflow/workspace/{workspace}/delete",
 *     "activate-form" = "/admin/config/workflow/workspace/{workspace}/activate",
 *     "deploy-form" = "/admin/config/workflow/workspace/{workspace}/deploy",
 *     "collection" = "/admin/config/workflow/workspace",
 *   },
 * )
 */
class Workspace extends ContentEntityBase implements WorkspaceInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Workspace ID'))
      ->setDescription(new TranslatableMarkup('The workspace ID.'))
      ->setSetting('max_length', 128)
      ->setRequired(TRUE)
      ->addConstraint('UniqueField')
      ->addConstraint('DeletedWorkspace')
      ->addPropertyConstraints('value', ['Regex' => ['pattern' => '/^[a-z0-9_]*$/']]);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Workspace name'))
      ->setDescription(new TranslatableMarkup('The workspace name.'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 128)
      ->setRequired(TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDescription(new TranslatableMarkup('The workspace owner.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback('Drupal\workspace\Entity\Workspace::getCurrentUserId')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the workspace was last edited.'))
      ->setRevisionable(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The UNIX timestamp of when the workspace has been created.'));

    $fields['target'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target workspace'))
      ->setDescription(new TranslatableMarkup('The workspace to push to and pull from.'))
      ->setRevisionable(TRUE)
      ->setRequired(TRUE)
      ->setDefaultValue('live');

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getRepositoryHandlerPlugin() {
    if (($target = $this->target->value) && $target !== RepositoryHandlerInterface::EMPTY_VALUE) {
      $configuration = [
        'source' => $this->id(),
        'target' => $target,
      ];
      return \Drupal::service('plugin.manager.workspace.repository_handler')->createInstance($target, $configuration);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isDefaultWorkspace() {
    return $this->id() === WorkspaceManager::DEFAULT_WORKSPACE;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($created) {
    $this->set('created', (int) $created);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStartTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('uid', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->get('uid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('uid', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);

    // Add the IDs of the deleted workspaces to the list of workspaces that will
    // be purged on cron.
    $state = \Drupal::state();
    $deleted_workspace_ids = $state->get('workspace.deleted', []);
    unset($entities[WorkspaceManager::DEFAULT_WORKSPACE]);
    $deleted_workspace_ids += array_combine(array_keys($entities), array_keys($entities));
    $state->set('workspace.deleted', $deleted_workspace_ids);

    // Trigger a batch purge to allow empty workspaces to be deleted
    // immediately.
    \Drupal::service('workspace.manager')->purgeDeletedWorkspacesBatch();
  }

  /**
   * Default value callback for 'uid' base field definition.
   *
   * @see ::baseFieldDefinitions()
   *
   * @return int[]
   *   An array containing the ID of the current user.
   */
  public static function getCurrentUserId() {
    return [\Drupal::currentUser()->id()];
  }

}
