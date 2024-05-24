<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\pathauto\AliasCleanerInterface;
use Drupal\pathauto\AliasStorageHelperInterface;
use Drupal\pathauto\AliasTypeManager;
use Drupal\pathauto\AliasUniquifierInterface;
use Drupal\pathauto\PathautoGenerator;
use Drupal\pathauto\PathautoGeneratorInterface;
use Drupal\token\Token;
use Drupal\token\TokenEntityMapperInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides methods for generating path aliases.
 *
 * For now, only op "return" is supported.
 */
class DomainUniquePathAliasGenerator extends PathautoGenerator {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $currentRequest;

  /**
   * Constructor DomainUniquePathAliasGenerator class.
   *
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
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\token\TokenEntityMapperInterface $token_entity_mapper
   *   The token entity mapper.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\pathauto\AliasTypeManager $alias_type_manager
   *   Manages pathauto alias type plugins.
   * @param \Symfony\Component\HttpFoundation\RequestStack $current_request
   *   The current request.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, Token $token, AliasCleanerInterface $alias_cleaner, AliasStorageHelperInterface $alias_storage_helper, AliasUniquifierInterface $alias_uniquifier, MessengerInterface $pathauto_messenger, TranslationInterface $string_translation, TokenEntityMapperInterface $token_entity_mapper, EntityTypeManagerInterface $entity_type_manager, AliasTypeManager $alias_type_manager, RequestStack $current_request) {
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->token = $token;
    $this->aliasCleaner = $alias_cleaner;
    $this->aliasStorageHelper = $alias_storage_helper;
    $this->aliasUniquifier = $alias_uniquifier;
    $this->pathautoMessenger = $pathauto_messenger;
    $this->stringTranslation = $string_translation;
    $this->tokenEntityMapper = $token_entity_mapper;
    $this->entityTypeManager = $entity_type_manager;
    $this->aliasTypeManager = $alias_type_manager;
    $this->currentRequest = $current_request;
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

    $domain_id = $this->currentRequest->query->get('field_domain_source');

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
    $this->aliasUniquifier->uniquify($alias, $source, $langcode);
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

}
