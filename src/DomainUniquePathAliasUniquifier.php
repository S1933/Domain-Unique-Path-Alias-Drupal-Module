<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\pathauto\AliasStorageHelperInterface;
use Drupal\pathauto\AliasUniquifier;

/**
 * Provides a utility for creating a unique path alias.
 */
class DomainUniquePathAliasUniquifier extends AliasUniquifier {

  /**
   * Active database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AliasStorageHelperInterface $alias_storage_helper,
    ModuleHandlerInterface $module_handler,
    RouteProviderInterface $route_provider,
    AliasManagerInterface $alias_manager,
    Connection $database,
  ) {
    parent::__construct(
      $config_factory,
      $alias_storage_helper,
      $module_handler,
      $route_provider,
      $alias_manager
    );
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public function uniquify(&$alias, $source, $langcode, $domain_id = NULL) {
    $config = $this->configFactory->get('pathauto.settings');

    if (!$this->isReserved($alias, $source, $langcode, $domain_id)) {
      return;
    }

    // If the alias already exists, generate a new, hopefully unique, variant.
    $maxlength = min($config->get('max_length'), $this->aliasStorageHelper->getAliasSchemaMaxlength());
    $separator = $config->get('separator');
    $original_alias = $alias;

    $i = 0;
    do {
      // Append an incrementing numeric suffix until we find a unique alias.
      $unique_suffix = $separator . $i;
      $alias = Unicode::truncate($original_alias, $maxlength - mb_strlen($unique_suffix), TRUE) . $unique_suffix;
      $i++;
    } while ($this->isReserved($alias, $source, $langcode, $domain_id));
  }

  /**
   * {@inheritdoc}
   */
  public function isReserved($alias, $source, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, $domain_id = NULL) {

    // If domain id is not provided, use parent uniquifier.
    if (empty($domain_id)) {
      return parent::isReserved($alias, $source, $langcode);
    }

    // Check if this domain alias already exists.
    $query = $this->database->select('path_alias', 'path_alias')
      ->fields('path_alias', ['langcode', 'path', 'alias'])
      ->condition('domain_id', $domain_id)
      ->condition('alias', $alias);
    $result = $query->execute()->fetchAssoc();

    if (isset($result['path'])) {
      $existing_path = $result['path'];
      if ($existing_path !== $alias) {
        // If it is an alias for the provided source,
        // it is allowed to keep using it. If not, then it is reserved.
        return $existing_path !== $source;
      }
    }

    // Then check if there is a route with the same path.
    if ($this->isRoute($alias)) {
      return TRUE;
    }

    // Finally check if any other modules have reserved the alias.
    $args = [
      $alias,
      $source,
      $langcode,
    ];
    $implementations = $this->moduleHandler->invokeAll('pathauto_is_alias_reserved');
    foreach ($implementations as $module) {
      $result = $this->moduleHandler->invoke($module, 'pathauto_is_alias_reserved', $args);
      if (!empty($result)) {
        // As soon as the first module says that an alias is in fact reserved,
        // then there is no point in checking the rest of the modules.
        return TRUE;
      }
    }

    return FALSE;
  }

}
