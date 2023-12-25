<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\path_alias\AliasManager;
use Drupal\path_alias\AliasWhitelistInterface;
use Drupal\path_alias\PathAliasInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The path alias manager decorator.
 */
class DomainUniquePathAliasManager extends AliasManager {

  use DependencySerializationTrait;

  /**
   * The path alias entity.
   *
   * @var \Drupal\path_alias\Entity\PathAlias
   */
  protected $pathAlias;

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $currentRequest;

  /**
   * Constructs an AliasManager with DomainPathAliasManager.
   *
   * @param \Drupal\path_alias\AliasRepositoryInterface $alias_repository
   *   The path alias repository.
   * @param \Drupal\path_alias\AliasWhitelistInterface $whitelist
   *   The whitelist implementation to use.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache backend.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   The request stack.
   */
  public function __construct(
    $alias_repository,
    AliasWhitelistInterface $whitelist,
    LanguageManagerInterface $language_manager,
    CacheBackendInterface $cache,
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request
  ) {
    parent::__construct($alias_repository, $whitelist, $language_manager, $cache);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentRequest = $request->getCurrentRequest();
  }

  /**
   * Given the alias, return the path it represents.
   *
   * @param string $alias
   *   An alias.
   * @param string $langcode
   *   An optional language code to look up the path in.
   *
   * @return string
   *   The path represented by alias, or the alias if no path was found.
   */
  public function getPathByAlias($alias, $langcode = NULL) {

    // Do not process asset files.
    if ($this->isAssetFile($alias)) {
      return $alias;
    }

    // todo: Investigate if TYPE_CONTENT is the correct type or should be editable in modules settings.
    $langcode = $langcode ?: $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

    $domain_id = $this->currentRequest->request->get('field_domain_source_url') ?? $this->currentRequest->request->get('field_domain_source');
    if ($alias && $domain_id && $langcode) {
      $properties = [
        'alias' => $alias,
        'domain_id' => $domain_id,
        'langcode' => $langcode,
      ];
      $path_aliases = $this->entityTypeManager
        ->getStorage('path_alias')
        ->loadByProperties($properties);

      foreach ($path_aliases as $path_alias) {
        if ($path_alias instanceof PathAliasInterface) {
          $this->pathAlias = $path_alias;
          return $this->pathAlias->getPath();
        }
      }
    }

    return parent::getPathByAlias($alias, $langcode);
  }

  /**
   * Check if a path is an asset by its extension.
   *
   * @param string $alias
   *   An alias.
   *
   * @return bool
   *   Check result.
   */
  private function  isAssetFile($alias) {
    $noAlias = ['svg', 'png', 'jpeg', 'jpg', 'css', 'js', 'gif', 'webp', 'ts'];
    $extension = pathinfo($alias, PATHINFO_EXTENSION);
    $extension = explode('?', $extension);
    if (in_array($extension[0], $noAlias)) {
      return TRUE;
    }
    return FALSE;
  }

}
