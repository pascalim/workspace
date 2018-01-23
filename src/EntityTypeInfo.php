<?php

namespace Drupal\workspace;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manipulates entity type information.
 *
 * This class contains primarily bridged hooks for compile-time or
 * cache-clear-time hooks. Runtime hooks should be placed in EntityOperations.
 *
 * @internal
 */
class EntityTypeInfo implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The workspace manager service.
   *
   * @var \Drupal\workspace\WorkspaceManagerInterface
   */
  protected $workspaceManager;

  /**
   * Constructs a new EntityTypeInfo instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\workspace\WorkspaceManagerInterface $workspace_manager
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
   * Adds the "EntityWorkspaceConflict" constraint to eligible entity types.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface[] $entity_types
   *   An associative array of all entity type definitions, keyed by the entity
   *   type name. Passed by reference.
   *
   * @see hook_entity_type_build()
   */
  public function entityTypeBuild(array &$entity_types) {
    foreach ($entity_types as $entity_type) {
      if ($this->workspaceManager->entityTypeCanBelongToWorkspaces($entity_type)) {
        $entity_type->addConstraint('EntityWorkspaceConflict');
      }
    }
  }

  /**
   * Alters entity forms to disallow concurrent editing in multiple workspaces.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string $form_id
   *   The form ID.
   *
   * @see hook_form_alter()
   */
  public function formAlter(array &$form, FormStateInterface $form_state, $form_id) {
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityFormInterface) {
      $entity = $form_object->getEntity();

      if ($this->workspaceManager->entityTypeCanBelongToWorkspaces($entity->getEntityType())) {
        /** @var \Drupal\workspace\WorkspaceAssociationStorageInterface $workspace_association_storage */
        $workspace_association_storage = $this->entityTypeManager->getStorage('workspace_association');
        if ($workspace_ids = $workspace_association_storage->isEntityTracked($entity)) {
          // An entity can only be edited in one workspace.
          $workspace_id = reset($workspace_ids);

          if ($workspace_id != $this->workspaceManager->getActiveWorkspace()->id()) {
            $workspace = $this->entityTypeManager->getStorage('workspace')->load($workspace_id);

            $form['#markup'] = $this->t('The content is being edited in the %label workspace.', ['%label' => $workspace->label()]);
            $form['#access'] = FALSE;
          }
        }
      }
    }
  }

}
