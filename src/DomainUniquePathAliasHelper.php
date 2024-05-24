<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides helper functions for the Domain Unique Path Alias.
 */
class DomainUniquePathAliasHelper {

  /**
   * Constructs a DomainUniquePathAliasHelper object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Gets the domain id from the path.
   *
   * Currently works only with nodes and taxonomy terms.
   *
   * The path entity's domain_source field is first checked, the then first
   * value from the domain_access field.
   *
   * @param string $path
   *   The path to get the domain id from.
   *
   * @return string
   *   Domain id if any or empty string.
   */
  public function getPathDomainId(string $path): string {
    $path = ltrim($path, '/');
    $parts = explode('/', $path);
    $id = end($parts);
    switch ($parts[0]) {
      case 'taxonomy':
        /** @var \Drupal\taxonomy\TermInterface $entity */
        $entity = $this->entityTypeManager->getStorage('taxonomy_term')->load($id);
        break;

      case 'node':
        /** @var \Drupal\node\NodeInterface $entity */
        $entity = $this->entityTypeManager->getStorage('node')->load($id);
        break;
    }

    return isset($entity) ? $this->getDomainId($entity) : '';
  }

  /**
   * Get the domain id for a given entity.
   *
   * @param \Drupal\Core\Entity\EntityPublishedInterface $entity
   *   The entity.
   *
   * @return string
   *   Domain id if any or empty string.
   */
  public function getDomainId(EntityPublishedInterface $entity): string {
    // Get domain_id using domain_source or fallback with domain_access field.
    if ($entity->hasField('field_domain_source') && !$entity->get('field_domain_source')->isEmpty()) {
      $domain_id = $entity->get('field_domain_source')->getString();
    }
    elseif ($entity->hasField('field_domain_access') && !$entity->get('field_domain_access')->isEmpty()) {
      $domain_id = $entity->get('field_domain_access')->first()->getString();
    }

    return $domain_id ?? '';
  }

}
