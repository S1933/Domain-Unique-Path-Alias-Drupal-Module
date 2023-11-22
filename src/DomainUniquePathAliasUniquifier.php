<?php

namespace Drupal\domain_unique_path_alias;

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
    Connection $database
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
  public function isReserved($alias, $source, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, $domain_id = '') {
    // Check if this domain alias already exists.
    $query = $this->database->select('path_alias', 'path_alias')
      ->fields('path_alias', ['langcode', 'path', 'alias'])
      ->condition('domain_id', $domain_id)
      ->condition('alias', $alias);
    $result = $query->execute()->fetchAssoc();

    if (isset($result['path'])) {
      $existing_path = $result['path'];
      if ($existing_path !== $alias) {
        // If it is an alias for the provided source, it is allowed to keep using
        // it. If not, then it is reserved.
        return $existing_path !== $source;
      }
    }

    return parent::isReserved($alias, $source, $langcode);
  }
}
