<?php

namespace Drupal\Tests\jsonapi_menu_items\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\jsonapi\Functional\JsonApiRequestTestTrait;
use GuzzleHttp\RequestOptions;

/**
 * Tests JSON:API Menu Items functionality.
 *
 * @group jsonapi_menu_items
 */
class JsonapiMenuItemsTest extends BrowserTestBase {
  use JsonApiRequestTestTrait;

  /**
   * The account to use for authentication.
   *
   * @var null|\Drupal\Core\Session\AccountInterface
   */
  protected $account;

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
  protected function assertCacheContext(array $headers, $expected_cache_context) {
    $cache_contexts = explode(' ', $headers['X-Drupal-Cache-Contexts'][0]);
    $this
      ->assertTrue(in_array($expected_cache_context, $cache_contexts), "'" . $expected_cache_context . "' is present in the X-Drupal-Cache-Contexts header.");
  }

  /**
   * Tests the JSON:API Menu Items resource.
   */
  public function testJsonapiMenuItemsResource() {
    $link_title = $this->randomMachineName();
    $content_link = $this->createMenuLink($link_title, 'jsonapi_menu_test.open');

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
    ]);
    [$content] = $this->getJsonApiMenuItemsResponse($url);
    // There are 5 items in this menu - 4 from
    // jsonapi_menu_items_test.links.menu.yml and the content item created
    // above. One of the four in that file is disabled and should be filtered
    // out, another is not accesible to the current users. This leaves a total
    // of 3 items in the response.
    $this->assertCount(3, $content['data']);

    $expected_items = Json::decode(strtr(file_get_contents(dirname(__DIR__, 2) . '/fixtures/expected-items.json'), [
      '%uuid' => $content_link->uuid(),
      '%title' => $link_title,
      '%base_path' => Url::fromRoute('<front>')->toString(),
    ]));
    $this->assertEqual($expected_items['data'], $content['data']);

    // Assert response is cached with appropriate cacheability metadata such
    // that re-saving the link with a new title yields the new title in a
    // subsequent request.
    $new_title = $this->randomMachineName();
    $content_link->title = $new_title;
    $content_link->save();
    [$content] = $this->getJsonApiMenuItemsResponse($url);
    $match = array_filter($content['data'], function (array $item) use ($content_link) {
      return $item['id'] === 'menu_link_content:' . $content_link->uuid();
    });
    $this->assertEqual($new_title, reset($match)['attributes']['title']);

    // Add another link and ensue cacheability metadata ensures the new item
    // appears in a subsequent request.
    $this->createMenuLink($link_title, 'jsonapi_menu_test.open');
    [$content] = $this->getJsonApiMenuItemsResponse($url);
    $this->assertCount(4, $content['data']);
  }

  /**
   * Tests the JSON:API Menu Items resource with no results.
   */
  public function testParametersNoResults() {
    $this->drupalLogin($this->account);

    $link_title = $this->randomMachineName();
    $content_link = $this->createMenuLink($link_title, 'jsonapi_menu_test.user.login');

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
      'filter' => [
        'parent' => "fake-item",
      ],
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);

    self::assertCount(0, $content['data']);
    self::assertCacheContext($headers, 'url.query_args:filter');
  }

  /**
   * Tests the JSON:API Menu Items resource with the 'parents' filter.
   */
  public function testParametersParents() {
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
    self::assertCacheContext($headers, 'url.query_args:filter');

    $expected_items = Json::decode(strtr(file_get_contents(dirname(__DIR__, 2) . '/fixtures/parents-expected-items.json'), [
      '%uuid' => $content_link->uuid(),
      '%title' => $link_title,
      '%base_path' => Url::fromRoute('<front>')->toString(),
    ]));

    self::assertEquals($expected_items['data'], $content['data']);
  }

  /**
   * Tests the JSON:API Menu Items resource with the 'parent' filter.
   */
  public function testParametersParent() {
    $this->drupalLogin($this->account);

    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
      'filter' => [
        'parent' => "jsonapi_menu_test.open",
      ],
    ]);
    [$content, $headers] = $this->getJsonApiMenuItemsResponse($url);

    self::assertCount(1, $content['data']);
    self::assertCacheContext($headers, 'url.query_args:filter');

    $expected_items = Json::decode(strtr(file_get_contents(dirname(__DIR__, 2) . '/fixtures/parent-expected-items.json'), [
      '%base_path' => Url::fromRoute('<front>')->toString(),
    ]));

    self::assertEquals($expected_items['data'], $content['data']);
  }

  /**
   * Tests the JSON:API Menu Items resource with the 'min_depth' filter.
   */
  public function testParametersMinDepth() {
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
    self::assertCacheContext($headers, 'url.query_args:filter');

    $expected_items = Json::decode(strtr(file_get_contents(dirname(__DIR__, 2) . '/fixtures/min-depth-expected-items.json'), [
      '%uuid' => $content_link->uuid(),
      '%title' => $link_title,
      '%base_path' => Url::fromRoute('<front>')->toString(),
    ]));

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
  public function testParametersMaxDepth() {
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
    self::assertCacheContext($headers, 'url.query_args:filter');

    $expected_items = Json::decode(strtr(file_get_contents(dirname(__DIR__, 2) . '/fixtures/max-depth-expected-items.json'), [
      '%uuid' => $content_link->uuid(),
      '%title' => $link_title,
      '%base_path' => Url::fromRoute('<front>')->toString(),
    ]));

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
  public function testParametersConditions() {
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
    self::assertCacheContext($headers, 'url.query_args:filter');

    $expected_items = Json::decode(strtr(file_get_contents(dirname(__DIR__, 2) . '/fixtures/conditions-expected-items.json'), [
      '%base_path' => Url::fromRoute('<front>')->toString(),
    ]));

    self::assertEquals($expected_items['data'], $content['data']);
  }

  /**
   * Create menu link.
   *
   * @param string $title
   *   The menu link title.
   * @param string $parent
   *   The menu link parent id.
   *
   * @return Drupal\menu_link_content\Entity\MenuLinkContent
   *   The menu link.
   */
  protected function createMenuLink(string $title, string $parent) {
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
   * Get a JSON:API Menu Items resource response document.
   *
   * @param \Drupal\core\Url $url
   *   The url for a JSON:API View.
   *
   * @return array
   *   The response document and headers.
   */
  protected function getJsonApiMenuItemsResponse(Url $url) {
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
