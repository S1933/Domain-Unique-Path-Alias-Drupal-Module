<?php

namespace Drupal\Tests\domain_unique_path_alias\Functional;

use Drupal\domain_access\DomainAccessManagerInterface;
use Drupal\domain_source\DomainSourceElementManagerInterface;
use Drupal\Tests\domain\Functional\DomainTestBase;

/**
 * Tests path alias on different domains.
 *
 * @group domain_unique_path_alias
 */
class DomainUniquePathAliasTest extends DomainTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'domain',
    'domain_access',
    'domain_source',
    'field',
    'node',
    'path_alias',
    'pathauto',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create 2 domains.
    DomainTestBase::domainCreateTestDomains(2, 'example.com', [
      'domain',
      'domain1',
    ]);

    $domains = \Drupal::entityTypeManager()->getStorage('domain')->loadMultiple();

    // Create an article on example_com domain.
    $this->drupalCreateNode([
      'type' => 'article',
      'path' => [
        'alias' => '/contact',
      ],
      DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD => [
        $domains['example_com']->id(),
      ],
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $domains['example_com']->id(),
      ],
    ]);

    // Create an article on example_com domain.
    $this->drupalCreateNode([
      'type' => 'article',
      'path' => [
        'alias' => '/contact-bis',
      ],
      DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD => [
        $domains['example_com']->id(),
      ],
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $domains['example_com']->id(),
      ],
    ]);

    // Create an article on domain1_example_com domain.
    $this->drupalCreateNode([
      'type' => 'article',
      'path' => [
        'alias' => '/contact',
      ],
      DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD => [
        $domains['domain1_example_com']->id(),
      ],
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $domains['domain1_example_com']->id(),
      ],
    ]);
  }

  /**
   * Creates a node and tests the creation of node access rules.
   */
  public function testDomainUniquePathAlias() {
    $this->drupalLogin($this->rootUser);

    $this->drupalGet('admin/content');
    $this->assertSession()->responseContains('<a href="http://example.com/web/contact" hreflang="en">');
    $this->assertSession()->responseContains('<a href="http://example.com/web/contact-bis" hreflang="en">');
    $this->assertSession()->responseContains('<a href="http://domain1.example.com/web/contact" hreflang="en">');

    $constraint_message = 'The alias /contact is already in use in this language.';

    $edit = [
      'path[0][alias]' => '/contact-bis-bis',
    ];
    $this->drupalGet('node/2/edit');
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextNotContains($constraint_message);

    $edit = [
      'path[0][alias]' => '/contact',
    ];
    $this->drupalGet('node/2/edit');
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains($constraint_message);
  }

}
