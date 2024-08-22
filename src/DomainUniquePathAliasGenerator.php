<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\pathauto\AliasCleanerInterface;
use Drupal\pathauto\AliasStorageHelperInterface;
use Drupal\pathauto\AliasUniquifierInterface;
use Drupal\pathauto\MessengerInterface as PathAutoMessengerInterface;
use Drupal\pathauto\PathautoGeneratorInterface;
use Drupal\pathauto\PathautoState;
use Drupal\token\TokenEntityMapperInterface;

/**
 * Provides methods for generating path aliases.
 *
 * For now, only op "return" is supported.
 */
class DomainUniquePathAliasGenerator implements PathautoGeneratorInterface {

  use StringTranslationTrait;

  /**
   * The decorated pathauto generator.
   *
   * @var \Drupal\pathauto\PathautoGeneratorInterface
   */
  protected $inner;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * The alias cleaner.
   *
   * @var \Drupal\pathauto\AliasCleanerInterface
   */
  protected $aliasCleaner;

  /**
   * The alias storage helper.
   *
   * @var \Drupal\pathauto\AliasStorageHelperInterface
   */
  protected $aliasStorageHelper;

  /**
   * The alias uniquifier.
   *
   * @var \Drupal\pathauto\AliasUniquifierInterface
   */
  protected $aliasUniquifier;

  /**
   * The messenger service.
   *
   * @var \Drupal\pathauto\MessengerInterface
   */
  protected $pathautoMessenger;

  /**
   * The token entity mapper.
   *
   * @var \Drupal\token\TokenEntityMapperInterface
   */
  protected $tokenEntityMapper;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The helper service.
   *
   * @var \Drupal\domain_unique_path_alias\DomainUniquePathAliasHelper
   */
  protected $helper;

  /**
   * Creates a new Pathauto manager.
   *
   * @param \Drupal\pathauto\PathautoGeneratorInterface $inner
   *   The decorated pathauto generator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Utility\Token $token
   *   The token utility.
   * @param \Drupal\pathauto\AliasCleanerInterface $alias_cleaner
   *   The alias cleaner.
   * @param \Drupal\pathauto\AliasStorageHelperInterface $alias_storage_helper
   *   The alias storage helper.
   * @param \Drupal\pathauto\AliasUniquifierInterface $alias_uniquifier
   *   The alias uniquifier.
   * @param \Drupal\pathauto\MessengerInterface $pathauto_messenger
   *   The messenger service.
   * @param \Drupal\token\TokenEntityMapperInterface $token_entity_mapper
   *   The token entity mapper.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\domain_unique_path_alias\DomainUniquePathAliasHelper $helper
   *   The helper service.
   */
  public function __construct(
    PathautoGeneratorInterface $inner,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    Token $token,
    AliasCleanerInterface $alias_cleaner,
    AliasStorageHelperInterface $alias_storage_helper,
    AliasUniquifierInterface $alias_uniquifier,
    PathAutoMessengerInterface $pathauto_messenger,
    TokenEntityMapperInterface $token_entity_mapper,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    DomainUniquePathAliasHelper $helper,
  ) {
    $this->inner = $inner;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->token = $token;
    $this->aliasCleaner = $alias_cleaner;
    $this->aliasStorageHelper = $alias_storage_helper;
    $this->aliasUniquifier = $alias_uniquifier;
    $this->pathautoMessenger = $pathauto_messenger;
    $this->tokenEntityMapper = $token_entity_mapper;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->helper = $helper;
  }

  /**
   * {@inheritdoc}
   */
  public function createEntityAlias(EntityInterface $entity, $op) {
    // Retrieve and apply the pattern for this content type.
    $pattern = $this->getPatternByEntity($entity);
    if (empty($pattern)) {
      // No pattern? Do nothing (otherwise we may blow away existing aliases...)
      return NULL;
    }

    try {
      $internalPath = $entity->toUrl()->getInternalPath();
    }
    // @todo convert to multi-exception handling in PHP 7.1.
    catch (EntityMalformedException $exception) {
      return NULL;
    }

    $source = '/' . $internalPath;
    $config = $this->configFactory->get('pathauto.settings');
    $langcode = $entity->language()->getId();

    // Core does not handle aliases with language Not Applicable.
    if ($langcode === LanguageInterface::LANGCODE_NOT_APPLICABLE) {
      $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED;
    }

    // Build token data.
    $data = [
      $this->tokenEntityMapper->getTokenTypeForEntityType($entity->getEntityTypeId()) => $entity,
    ];

    // Allow other modules to alter the pattern.
    $context = [
      'module' => $entity->getEntityType()->getProvider(),
      'op' => $op,
      'source' => $source,
      'data' => $data,
      'bundle' => $entity->bundle(),
      'language' => &$langcode,
    ];
    $pattern_original = $pattern->getPattern();
    $this->moduleHandler->alter('pathauto_pattern', $pattern, $context);
    $pattern_altered = $pattern->getPattern();

    // Special handling when updating an item which is already aliased.
    $existing_alias = NULL;
    if ($op === 'update' || $op === 'bulkupdate') {
      if ($existing_alias = $this->aliasStorageHelper->loadBySource($source, $langcode)) {
        switch ($config->get('update_action')) {
          case PathautoGeneratorInterface::UPDATE_ACTION_NO_NEW:
            // If an alias already exists,
            // and the update action is set to do nothing, do nothing.
            return NULL;
        }
      }
    }

    // Replace any tokens in the pattern.
    // Uses a callback option to clean replacements. No sanitization.
    // Pass empty BubbleableMetadata object to explicitly ignore cacheablity,
    // as the result is never rendered.
    $alias = $this->token->replace($pattern->getPattern(), $data, [
      'clear' => TRUE,
      'callback' => [$this->aliasCleaner, 'cleanTokenValues'],
      'langcode' => $langcode,
      'pathauto' => TRUE,
    ], new BubbleableMetadata());

    // Check if the token replacement has not actually replaced any values. If
    // that is the case, then stop because we should not generate an alias.
    // @see token_scan()
    $pattern_tokens_removed = preg_replace('/\[[^\s\]:]*:[^\s\]]*\]/', '', $pattern->getPattern());
    if ($alias === $pattern_tokens_removed) {
      return NULL;
    }

    $alias = $this->aliasCleaner->cleanAlias($alias);

    // Allow other modules to alter the alias.
    $context['source'] = &$source;
    $context['pattern'] = $pattern;
    $this->moduleHandler->alter('pathauto_alias', $alias, $context);

    // If we have arrived at an empty string, discontinue.
    if (!mb_strlen($alias)) {
      return NULL;
    }

    $domain_id = $this->helper->getDomainIdByRequest();
    // Do not generate a unique path alias if it already exists.
    if ($this->aliasUniquifier->isReserved($alias, $source, $langcode, $domain_id)) {
      $this->pathautoMessenger->addMessage($this->t('Path alias should be unique for, source: %source, langcode: %langcode, domain_id: %domain_id', [
        '%source' => $source,
        '%langcode' => $langcode,
        '%domain_id' => $domain_id,
      ]), $op);
    }

    // If the alias already exists, generate a new, hopefully unique, variant.
    $original_alias = $alias;
    $this->aliasUniquifier->uniquify($alias, $source, $langcode, $domain_id);
    if ($original_alias !== $alias) {
      // Alert the user why this happened.
      $this->pathautoMessenger->addMessage($this->t('The automatically generated alias %original_alias conflicted with an existing alias. Alias changed to %alias.', [
        '%original_alias' => $original_alias,
        '%alias' => $alias,
      ]), $op);
    }

    // Return the generated alias if requested.
    if ($op === 'return') {
      return $alias;
    }

    // Build the new path alias array and send it off to be created.
    $path = [
      'source' => $source,
      'alias' => $alias,
      'language' => $langcode,
    ];

    $return = $this->aliasStorageHelper->save($path, $existing_alias, $op);

    // Because there is no way to set an altered pattern to not be cached,
    // change it back to the original value.
    if ($pattern_altered !== $pattern_original) {
      $pattern->setPattern($pattern_original);
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function updateEntityAlias(EntityInterface $entity, $op, array $options = []) {
    // Skip if the entity does not have the path field.
    if (!($entity instanceof ContentEntityInterface) || !$entity->hasField('path')) {
      return NULL;
    }

    // Skip if pathauto processing is disabled.
    if ($entity->path->pathauto != PathautoState::CREATE && empty($options['force'])) {
      return NULL;
    }

    // Only act if this is the default revision.
    if ($entity instanceof RevisionableInterface && !$entity->isDefaultRevision()) {
      return NULL;
    }

    $options += ['language' => $entity->language()->getId()];
    $type = $entity->getEntityTypeId();

    // Skip processing if the entity has no pattern.
    if (!$this->getPatternByEntity($entity)) {
      return NULL;
    }

    // Deal with taxonomy specific logic.
    // @todo Update and test forum related code.
    if ($type == 'taxonomy_term') {
      $config_forum = $this->configFactory->get('forum.settings');
      if ($entity->bundle() == $config_forum->get('vocabulary')) {
        $type = 'forum';
      }
    }

    try {
      $result = $this->createEntityAlias($entity, $op);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger->addError($e->getMessage());
      return NULL;
    }

    // @todo Move this to a method on the pattern plugin.
    if ($type == 'taxonomy_term') {
      $subterms = $this->entityTypeManager->getStorage('taxonomy_term')->loadChildren($entity->id());
      foreach ($subterms as $subterm) {
        $this->updateEntityAlias($subterm, $op, $options);
      }
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function resetCaches() {
    $this->inner->resetCaches();
  }

  /**
   * {@inheritdoc}
   */
  public function getPatternByEntity(EntityInterface $entity) {
    return $this->inner->getPatternByEntity($entity);
  }

}
