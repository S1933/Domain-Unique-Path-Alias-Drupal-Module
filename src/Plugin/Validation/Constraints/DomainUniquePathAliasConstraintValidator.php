<?php

namespace Drupal\domain_unique_path_alias\Plugin\Validation\Constraints;

use Drupal\Core\Path\Plugin\Validation\Constraint\UniquePathAliasConstraintValidator;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Constraint validator for changing path aliases with domain control.
 */
class DomainUniquePathAliasConstraintValidator extends UniquePathAliasConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint) {
    /** @var \Drupal\path_alias\PathAliasInterface $entity */
    $path = $entity->getPath();
    $alias = $entity->getAlias();
    $langcode = $entity->language()->getId();

    $storage = $this->entityTypeManager->getStorage('path_alias');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', $alias, '=')
      ->condition('langcode', $langcode, '=');

    if (!$entity->isNew()) {
      $query->condition('id', $entity->id(), '<>');
    }
    if ($path) {
      $query->condition('path', $path, '<>');
    }

    $fieldDomainSource = \Drupal::request()->request->get('field_domain_source');
    if (!empty($fieldDomainSource)) {
      $query->condition('domain_id', $fieldDomainSource, '=');
    }

    if ($result = $query->range(0, 1)->execute()) {
      $existing_alias_id = reset($result);
      /** @var \Drupal\path_alias\PathAliasInterface $existingAlias */
      $existingAlias = $storage->load($existing_alias_id);
      if (
        !empty($fieldDomainSource)
        && $existingAlias->get('domain_id')->getString() === $fieldDomainSource
      ) {
        $this->context->buildViolation($constraint->message_domain, [
          '%alias' => $alias,
          '%domain' => $fieldDomainSource,
        ])->addViolation();
      } else if ($existingAlias->getAlias() !== $alias) {
        $this->context->buildViolation($constraint->differentCapitalizationMessage, [
          '%alias' => $alias,
          '%stored_alias' => $existingAlias->getAlias(),
        ])->addViolation();
      } else {
        $this->context->buildViolation($constraint->message, [
          '%alias' => $alias,
        ])->addViolation();
      }
    }
  }
}
