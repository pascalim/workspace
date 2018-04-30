<?php

namespace Drupal\workspace\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\workspace\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the EntityWorkspaceConflict constraint.
 */
class EntityWorkspaceConflictConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * The entity type manager.
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
   * Constructs an EntityUntranslatableFieldsConstraintValidator object.
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
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint) {
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    if (isset($entity) && !$entity->isNew()) {
      /** @var \Drupal\workspace\WorkspaceAssociationStorageInterface $workspace_association_storage */
      $workspace_association_storage = $this->entityTypeManager->getStorage('workspace_association');
      $workspace_ids = $workspace_association_storage->isEntityTracked($entity);
      $active_workspace = $this->workspaceManager->getActiveWorkspace();

      if ($workspace_ids && !in_array($active_workspace->id(), $workspace_ids, TRUE)) {
        // An entity can only be edited in one workspace.
        $workspace_id = reset($workspace_ids);
        $workspace = $this->entityTypeManager->getStorage('workspace')->load($workspace_id);

        $this->context->buildViolation($constraint->message)
          ->setParameter('%label', $workspace->label())
          ->addViolation();
      }
    }
  }

}
