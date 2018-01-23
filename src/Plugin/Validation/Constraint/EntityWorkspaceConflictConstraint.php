<?php

namespace Drupal\workspace\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validation constraint for an entity being edited in multiple workspaces.
 *
 * @Constraint(
 *   id = "EntityWorkspaceConflict",
 *   label = @Translation("Entity workspace conflict", context = "Validation"),
 *   type = {"entity"}
 * )
 */
class EntityWorkspaceConflictConstraint extends Constraint {

  public $message = 'The content is being edited in the %label workspace. As a result, your changes cannot be saved.';

}
