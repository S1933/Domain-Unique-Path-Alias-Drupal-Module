<?php

namespace Drupal\domain_unique_path_alias;

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
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {

  }

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
    $domain_id = '';

    $parts = explode('/', $path);
    $id = end($parts);
    switch ($parts[0]) {
      case 'taxonomy':
        $entity = $this->entityTypeManager->getStorage('taxonomy_term')->load($id);
        break;

      case 'node':
        $entity = $this->entityTypeManager->getStorage('node')->load($id);
        break;
    }

    // Check that the entity behind the path exists.
    if (empty($entity)) {
      return '';
    }

    // Get the domain id using domain_source with fallback to domain_access.
    if ($entity->hasField('field_domain_source')) {
      $item = $entity->get('field_domain_source');
      if (!$item->isEmpty()) {
        $domain_id = $item->get(0)->getString();
      }
    }
    if (empty($domain_id) && $entity->hasField('field_domain_access')) {
      $item = $entity->get('field_domain_access');
      if (!$item->isEmpty()) {
        $domain_id = $item->get(0)->getString();
      }
    }

    return $domain_id;
  }

}
