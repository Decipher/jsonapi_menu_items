<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_menu_items\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\DeprecationHelper;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\menu_link_config\Entity\MenuLinkConfig;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\jsonapi\Functional\JsonApiRequestTestTrait;
use GuzzleHttp\RequestOptions;

/**
 * Tests JSON:API Menu Items functionality.
 */
#[Group('jsonapi_menu_items')]
#[RunTestsInSeparateProcesses]
class JsonapiMenuItemsTest extends BrowserTestBase {
  use JsonApiRequestTestTrait;

  /**
   * The account to use for authentication.
   */
  protected ?AccountInterface $account;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'jsonapi_menu_items',
    'menu_test',
    'jsonapi_menu_items_test',
    'menu_link_config',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->account = $this->createUser();
  }

  /**
   * Asserts whether an expected cache context was present in the last response.
   *
   * @param array $headers
   *   An array of HTTP headers.
   * @param string $expected_cache_context
   *   The expected cache context.
   */
  protected function assertCacheContext(array $headers, string $expected_cache_context): void {
    $cache_contexts = explode(' ', $headers['X-Drupal-Cache-Contexts'][0]);
    $this->assertContains($expected_cache_context, $cache_contexts, "'$expected_cache_context' is present in the X-Drupal-Cache-Contexts header.");
  }

  /**
   * Tests the JSON:API Menu Items resource.
   */
  public function testJsonapiMenuItemsResource(): void {
    $link_title = $this->randomMachineName();
    $content_link = $this->createMenuLink($link_title, 'jsonapi_menu_test.open');

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
    ]);
    [$content] = $this->getJsonApiMenuItemsResponse($url);
    // There are 5 items in this menu - 4 from
    // jsonapi_menu_items_test.links.menu.yml and the content item created
    // above. One of the four in that file is disabled and should be filtered
    // out, another is not accessible to the current users. This leaves a total
    // of 3 items in the response.
    $this->assertCount(3, $content['data']);

    $expected_items = $this->loadFixture('expected-items.json', [
      '%uuid' => $content_link->uuid(),
      '%title' => $link_title,
      '%base_path' => Url::fromRoute('<front>')->toString(),
      '%langcode' => 'en',
    ]);
    $this->assertEquals($expected_items['data'], $content['data']);

    // Assert response is cached with appropriate cacheability metadata such
    // that re-saving the link with a new title yields the new title in a
    // subsequent request.
    $new_title = $this->randomMachineName();
    $content_link->set('title', $new_title);
    $content_link->save();
    [$content] = $this->getJsonApiMenuItemsResponse($url);
    $match = array_filter($content['data'], fn(array $item): bool => $item['id'] === 'menu_link_content:' . $content_link->uuid());
    $this->assertEquals($new_title, reset($match)['attributes']['title']);

    // Add another link and ensue cacheability metadata ensures the new item
    // appears in a subsequent request.
    $this->createMenuLink($link_title, 'jsonapi_menu_test.open');
    [$content] = $this->getJsonApiMenuItemsResponse($url);
    $this->assertCount(4, $content['data']);
  }

  /**
   * Tests that a menu_link_config link is returned.
   *
   * See #3171186. The resource previously only had coverage for
   * developer-defined and menu_link_content menu links.
   */
  public function testJsonapiMenuItemsResourceMenuLinkConfig(): void {
    $config_link = $this->createConfigMenuLink('Llama Gabilondo', 'menu_test.menu_name_test');

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
    ]);
    [$content] = $this->getJsonApiMenuItemsResponse($url);

    $match = array_values(array_filter(
      $content['data'],
      fn(array $item): bool => $item['id'] === 'menu_link_config:' . $config_link->id()
    ));
    $this->assertCount(1, $match, 'The menu_link_config link is in the response.');
    $this->assertSame('menu_link_config--menu_link_config', $match[0]['type']);
    $this->assertSame('Llama Gabilondo', $match[0]['attributes']['title']);
    $this->assertSame('menu_test.menu_name_test', $match[0]['attributes']['route']['name']);
  }

  /**
   * Tests the JSON:API Menu Items resource with no results.
   */
  public function testParametersNoResults(): void {
    $this->drupalLogin($this->account);

    $link_title = $this->randomMachineName();
    $this->createMenuLink($link_title, 'jsonapi_menu_test.user.login');

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
      'filter' => [
        'parents' => "fake_item",
      ],
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);

    self::assertCount(0, $content['data']);
    DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '10.4',
      fn() => self::assertCacheContext($headers, 'url.query_args'),
      fn() => self::assertCacheContext($headers, 'url.query_args:filter')
    );

  }

  /**
   * Tests the JSON:API Menu Items resource with the 'parents' filter.
   */
  public function testParametersParents(): void {
    $this->drupalLogin($this->account);

    $link_title = $this->randomMachineName();
    $content_link = $this->createMenuLink($link_title, 'jsonapi_menu_test.user.login');

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
      'filter' => [
        'parents' => "jsonapi_menu_test.open,jsonapi_menu_test.user.login",
      ],
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);

    self::assertCount(2, $content['data']);
    DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '10.4',
      fn() => self::assertCacheContext($headers, 'url.query_args'),
      fn() => self::assertCacheContext($headers, 'url.query_args:filter')
    );

    $expected_items = $this->loadFixture('parents-expected-items.json', [
      '%uuid' => $content_link->uuid(),
      '%title' => $link_title,
      '%base_path' => Url::fromRoute('<front>')->toString(),
      '%langcode' => 'en',
    ]);

    $content = $this->cleanUrlForTest($content);

    self::assertEquals($expected_items['data'], $content['data']);
  }

  /**
   * Clear the token from the URL.
   */
  public function cleanUrlForTest(array $content): array {
    // Remove token from URL since it varies per session, using a default
    // token value would result in test failures.
    $content['data'] = array_map(fn (array $value): array => ['attributes' => ['url' => parse_url($value['attributes']['url'] ?? '', \PHP_URL_PATH)] + $value['attributes']] + $value, $content['data']);

    return $content;
  }

  /**
   * Tests the JSON:API Menu Items resource with the 'parent' filter.
   */
  public function testParametersParent(): void {
    $this->drupalLogin($this->account);

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
      'filter' => [
        'parent' => "jsonapi_menu_test.open",
      ],
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);

    self::assertCount(1, $content['data']);
    DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '10.4',
      fn() => self::assertCacheContext($headers, 'url.query_args'),
      fn() => self::assertCacheContext($headers, 'url.query_args:filter')
    );

    $expected_items = $this->loadFixture('parent-expected-items.json', [
      '%base_path' => Url::fromRoute('<front>')->toString(),
    ]);

    $content = $this->cleanUrlForTest($content);

    self::assertEquals($expected_items['data'], $content['data']);
  }

  /**
   * Tests the JSON:API Menu Items resource with the 'min_depth' filter.
   */
  public function testParametersMinDepth(): void {
    $this->drupalLogin($this->account);

    $link_title = $this->randomMachineName();
    $content_link = $this->createMenuLink($link_title, 'jsonapi_menu_test.open');

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
      'filter' => [
        'min_depth' => 2,
      ],
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);

    self::assertCount(2, $content['data']);
    DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '10.4',
      fn() => self::assertCacheContext($headers, 'url.query_args'),
      fn() => self::assertCacheContext($headers, 'url.query_args:filter')
    );

    $expected_items = $this->loadFixture('min-depth-expected-items.json', [
      '%uuid' => $content_link->uuid(),
      '%title' => $link_title,
      '%base_path' => Url::fromRoute('<front>')->toString(),
      '%langcode' => 'en',
    ]);

    $content = $this->cleanUrlForTest($content);

    self::assertEquals($expected_items['data'], $content['data']);

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
      'filter' => [
        'min_depth' => 1,
      ],
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);

    self::assertCount(3, $content['data']);
  }

  /**
   * Tests the JSON:API Menu Items resource with the 'max_depth' filter.
   */
  public function testParametersMaxDepth(): void {
    $link_title = $this->randomMachineName();
    $content_link = $this->createMenuLink($link_title, 'jsonapi_menu_test.open');

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
      'filter' => [
        'max_depth' => 2,
      ],
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);

    self::assertCount(3, $content['data']);
    DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '10.4',
      fn() => self::assertCacheContext($headers, 'url.query_args'),
      fn() => self::assertCacheContext($headers, 'url.query_args:filter')
    );

    $expected_items = $this->loadFixture('max-depth-expected-items.json', [
      '%uuid' => $content_link->uuid(),
      '%title' => $link_title,
      '%base_path' => Url::fromRoute('<front>')->toString(),
      '%langcode' => 'en',
    ]);

    self::assertEquals($expected_items['data'], $content['data']);

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
      'filter' => [
        'max_depth' => 1,
      ],
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);

    self::assertCount(2, $content['data']);
  }

  /**
   * Tests the JSON:API Menu Items resource with the 'conditions' filter.
   */
  public function testParametersConditions(): void {
    // ?filter[conditions][provider][value]=jsonapi_menu_items_test.
    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
      'filter' => [
        'conditions' => [
          'provider' => [
            'value' => 'jsonapi_menu_items_test',
          ],
        ],
      ],
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);

    self::assertCount(2, $content['data']);
    DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '10.4',
      fn() => self::assertCacheContext($headers, 'url.query_args'),
      fn() => self::assertCacheContext($headers, 'url.query_args:filter')
    );

    $expected_items = $this->loadFixture('conditions-expected-items.json', [
      '%base_path' => Url::fromRoute('<front>')->toString(),
    ]);

    self::assertEquals($expected_items['data'], $content['data']);
  }

  /**
   * Tests the JSON:API Menu Items resource.
   */
  public function testJsonapiMenuItemsResourceCacheabilityBubbling(): void {
    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test2',
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);
    // There are 0 items in this menu because the anonymous user does not have
    // access to logout.
    $this->assertCount(0, $content['data']);
    $this->assertCacheContext($headers, 'user.roles:authenticated');

    $this->drupalLogin($this->account);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);
    // There is 1 item in this menu because a user does have access to logout.
    $this->assertCount(1, $content['data']);
    $this->assertCacheContext($headers, 'user.roles:authenticated');
  }

  /**
   * Create menu link.
   *
   * @param string $title
   *   The menu link title.
   * @param string $parent
   *   The menu link parent id.
   *
   * @return \Drupal\menu_link_content\Entity\MenuLinkContent
   *   The menu link.
   */
  protected function createMenuLink(string $title, string $parent): MenuLinkContent {
    $content_link = MenuLinkContent::create([
      'link' => ['uri' => 'route:menu_test.menu_callback_title'],
      'langcode' => 'en',
      'enabled' => 1,
      'title' => $title,
      'menu_name' => 'jsonapi-menu-items-test',
      'parent' => $parent,
      'weight' => 0,
    ]);
    $content_link->save();

    return $content_link;
  }

  /**
   * Create a menu_link_config menu link.
   *
   * @param string $title
   *   The menu link title.
   * @param string $route_name
   *   The route the link points to.
   *
   * @return \Drupal\menu_link_config\Entity\MenuLinkConfig
   *   The menu link.
   */
  protected function createConfigMenuLink(string $title, string $route_name): MenuLinkConfig {
    $config_link = MenuLinkConfig::create([
      'id' => $this->randomMachineName(),
      'title' => $title,
      'menu_name' => 'jsonapi-menu-items-test',
      'route_name' => $route_name,
      'route_parameters' => [],
      'options' => [],
      'weight' => 0,
      'enabled' => TRUE,
    ]);
    $config_link->save();

    return $config_link;
  }

  /**
   * Read a fixture file and put the test values into it.
   *
   * @param string $name
   *   The file name of the fixture.
   * @param array $replacements
   *   The placeholder values to put into the fixture.
   *
   * @return array
   *   The decoded fixture.
   */
  protected function loadFixture(string $name, array $replacements): array {
    $path = dirname(__DIR__, 2) . '/fixtures/' . $name;
    $contents = file_get_contents($path);
    self::assertIsString($contents, "Unable to read the fixture at $path.");
    $decoded = Json::decode(strtr($contents, $replacements));
    self::assertIsArray($decoded, "The fixture at $path is not a JSON object.");
    return $decoded;
  }

  /**
   * Get a JSON:API Menu Items resource response document.
   *
   * @param \Drupal\Core\Url $url
   *   The url for a JSON:API View.
   *
   * @return array
   *   The response document and headers.
   */
  protected function getJsonApiMenuItemsResponse(Url $url): array {
    $request_options = [];
    $request_options[RequestOptions::HEADERS]['Accept'] = 'application/vnd.api+json';

    $response = $this->request('GET', $url, $request_options);

    $this->assertSame(200, $response->getStatusCode(), var_export(Json::decode((string) $response->getBody()), TRUE));

    $response_document = Json::decode((string) $response->getBody());

    $this->assertIsArray($response_document['data']);
    $this->assertArrayNotHasKey('errors', $response_document);

    return [$response_document, $response->getHeaders()];
  }

}
