<?php

namespace Drupal\domain_unique_path_alias\Plugin\Validation\Constraints;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\Plugin\Validation\Constraint\UniquePathAliasConstraintValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;

/**
 * Constraint validator for changing path aliases with domain control.
 */
class DomainUniquePathAliasConstraintValidator extends UniquePathAliasConstraintValidator {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $currentRequest;

  /**
   * Creates a new DomainUniquePathAliasConstraintValidator instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $current_request
   *   The current request.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RequestStack $current_request) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentRequest = $current_request;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('request_stack')
    );
  }

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

    $fieldDomainSource = $this->currentRequest->getCurrentRequest()
      ->get('field_domain_source');
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
        $this->context->buildViolation($constraint->messageDomain, [
          '%alias' => $alias,
          '%domain' => $fieldDomainSource,
        ])->addViolation();
      }
      elseif ($existingAlias->getAlias() !== $alias) {
        $this->context->buildViolation($constraint->differentCapitalizationMessage, [
          '%alias' => $alias,
          '%stored_alias' => $existingAlias->getAlias(),
        ])->addViolation();
      }
      else {
        $this->context->buildViolation($constraint->message, [
          '%alias' => $alias,
        ])->addViolation();
      }
    }
  }

}
