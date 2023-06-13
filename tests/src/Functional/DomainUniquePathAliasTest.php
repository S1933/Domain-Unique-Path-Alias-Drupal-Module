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
  protected static $modules = [
    'domain_access',
    'domain_source',
    'domain_unique_path_alias',
    'domain',
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
        $domains['example_com']->id()
      ],
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $domains['example_com']->id()
      ],
    ]);

    // Create an article on example_com domain.
    $this->drupalCreateNode([
      'type' => 'article',
      'path' => [
        'alias' => '/contact-bis',
      ],
      DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD => [
        $domains['example_com']->id()
      ],
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $domains['example_com']->id()
      ],
    ]);

    // Create an article on domain1_example_com domain.
    $this->drupalCreateNode([
      'type' => 'article',
      'path' => [
        'alias' => '/contact',
      ],
      DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD => [
        $domains['domain1_example_com']->id()
      ],
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $domains['domain1_example_com']->id()
      ],
    ]);
  }

  /**
   * Creates a node and tests the creation of node access rules.
   */
  public function testDomainUniquePathAlias() {
    $this->drupalLogin($this->rootUser);

    $this->drupalGet('admin/content');
    $this->assertSession()->responseContains('<a href="http://example.com/contact" hreflang="en">');
    $this->assertSession()->responseContains('<a href="http://example.com/contact-bis" hreflang="en">');
    $this->assertSession()->responseContains('<a href="http://domain1.example.com/contact" hreflang="en">');
  }

}
