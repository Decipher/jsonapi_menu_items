<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_menu_items\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\jsonapi\JsonApiResource\Data;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi_menu_items\Resource\MenuItemsResource;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_config\Entity\MenuLinkConfig;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests MenuItemsResource.
 */
#[Group('jsonapi_menu_items')]
#[RunTestsInSeparateProcesses]
final class MenuItemsResourceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'file',
    'link',
    'serialization',
    'jsonapi',
    'jsonapi_resources',
    'menu_link_content',
    'jsonapi_menu_items',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('menu_link_content');
  }

  /**
   * Tests getRouteResourceTypes.
   *
   * @param string[] $extra_modules
   *   Any additional modules to install.
   * @param string[] $expected_resource_types
   *   The expected resource types.
   *
   * @covers \Drupal\jsonapi_menu_items\Resource\MenuItemsResource::getRouteResourceTypes
   *
   * @dataProvider dataGetRouteResourceTypes
   */
  #[DataProvider('dataGetRouteResourceTypes')]
  public function testGetRouteResourceTypes(array $extra_modules, array $expected_resource_types): void {
    if (count($extra_modules) > 0) {
      $this->container->get('module_installer')->install($extra_modules);
    }
    Menu::create([
      'id' => 'menu-test',
      'label' => 'Test menu',
      'description' => 'Description text',
    ])->save();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();

    $sut = $this->getSut();
    $resource_types = $sut->getRouteResourceTypes(new Route('/'), 'foo');
    $resource_type_ids = array_map(
      static fn (ResourceType $type) => $type->getTypeName(),
      $resource_types
    );
    self::assertEquals($resource_type_ids, $expected_resource_types);
  }

  /**
   * Data for testGetRouteResourceTypes.
   *
   * @return array[]
   *   The test cases.
   */
  public static function dataGetRouteResourceTypes(): array {
    return [
      'menu_link_content only' => [
        [],
        ['menu_link_content--menu_link_content'],
      ],
      'menu_link_config' => [
        ['menu_link_config'],
        ['menu_link_content--menu_link_content', 'menu_link_config--menu_link_config'],
      ],
      'menu_item_extras' => [
        ['menu_item_extras'],
        ['menu_link_content--menu-test'],
      ],
      'menu_link_config and menu_item_extras' => [
        ['menu_link_config', 'menu_item_extras'],
        ['menu_link_content--menu-test', 'menu_link_config--menu_link_config'],
      ],
    ];
  }

  /**
   * Tests that getRouteResourceTypes stores lightweight identifiers in cache.
   *
   * Ensures the cache entry holds ['entity_type', 'bundle'] arrays rather than
   * serialized ResourceType objects, preventing large cache payloads.
   *
   * @covers \Drupal\jsonapi_menu_items\Resource\MenuItemsResource::getRouteResourceTypes
   */
  public function testGetRouteResourceTypesCacheFormat(): void {
    Menu::create([
      'id' => 'menu-test',
      'label' => 'Test menu',
      'description' => 'Description text',
    ])->save();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();

    $this->getSut()->getRouteResourceTypes(new Route('/'), 'foo');

    $cached = $this->container->get('cache.discovery')->get('route_resource_types.resource_type.foo');
    self::assertNotFalse($cached);
    self::assertIsArray($cached->data);
    self::assertNotEmpty($cached->data);
    foreach ($cached->data as $item) {
      self::assertIsArray($item);
      self::assertArrayHasKey('entity_type', $item);
      self::assertArrayHasKey('bundle', $item);
      self::assertIsString($item['entity_type']);
      self::assertIsString($item['bundle']);
    }
  }

  /**
   * Tests process.
   *
   * @covers \Drupal\jsonapi_menu_items\Resource\MenuItemsResource::process
   *
   * @dataProvider dataProcess
   */
  #[DataProvider('dataProcess')]
  public function testProcess(array $extra_modules, array $expected_resource_objects): void {
    $this->container->get('module_installer')->install(
      array_merge(['menu_test', 'jsonapi_menu_items_test'], $extra_modules)
    );
    MenuLinkContent::create([
      'uuid' => '5d0a9864-d151-4e8f-9f72-b573446ba1d6',
      'id' => 'llama',
      'title' => 'Llama Gabilondo',
      'description' => 'Llama Gabilondo',
      'link' => 'https://nl.wikipedia.org/wiki/Llama',
      'weight' => 0,
      'menu_name' => 'jsonapi-menu-items-test',
    ])->save();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();

    $sut = $this->getSut();
    $request = Request::create('/jsonapi/menu_items/jsonapi-menu-items-test');
    $menu = Menu::load('jsonapi-menu-items-test');
    self::assertNotNull($menu);

    $response = $sut->process($request, $menu);
    $top_level = $response->getResponseData();
    self::assertInstanceOf(JsonApiDocumentTopLevel::class, $top_level);
    $document_data = $top_level->getData();
    self::assertInstanceOf(Data::class, $document_data);
    $resource_objects = array_map(
      static fn ($object): array => [
        'resource_type' => $object->getTypeName(),
        'id' => $object->getId(),
      ],
      $document_data->toArray()
    );
    self::assertEquals($expected_resource_objects, $resource_objects);
  }

  /**
   * Data for testProcess.
   *
   * @return array[]
   *   The test data.
   */
  public static function dataProcess(): array {
    return [
      'menu_link_content' => [
        [],
        [
          [
            'resource_type' => 'menu_link_content--menu_link_content',
            'id' => 'jsonapi_menu_test.open',
          ],
          [
            'resource_type' => 'menu_link_content--menu_link_content',
            'id' => 'menu_link_content:5d0a9864-d151-4e8f-9f72-b573446ba1d6',
          ],
          [
            'resource_type' => 'menu_link_content--menu_link_content',
            'id' => 'jsonapi_menu_test.user.login',
          ],
        ],
      ],
      'menu_item_extras' => [
        ['menu_item_extras'],
        [
          [
            'resource_type' => 'menu_link_content--jsonapi-menu-items-test',
            'id' => 'jsonapi_menu_test.open',
          ],
          [
            'resource_type' => 'menu_link_content--jsonapi-menu-items-test',
            'id' => 'menu_link_content:5d0a9864-d151-4e8f-9f72-b573446ba1d6',
          ],
          [
            'resource_type' => 'menu_link_content--jsonapi-menu-items-test',
            'id' => 'jsonapi_menu_test.user.login',
          ],
        ],
      ],
    ];
  }

  /**
   * Tests that a menu_link_config link is returned with the right type.
   *
   * The functional test suite only ever created menu_link_content items
   * (developer-defined via YAML, or content entities). This adds a real
   * menu_link_config entity and checks it comes back too.
   *
   * @covers \Drupal\jsonapi_menu_items\Resource\MenuItemsResource::process
   */
  public function testProcessWithMenuLinkConfig(): void {
    $this->container->get('module_installer')->install([
      'menu_test',
      'jsonapi_menu_items_test',
      'menu_link_config',
    ]);
    MenuLinkConfig::create([
      'id' => 'llama',
      'title' => 'Llama Gabilondo',
      'description' => 'Llama Gabilondo',
      'menu_name' => 'jsonapi-menu-items-test',
      'route_name' => 'menu_test.menu_name_test',
      'route_parameters' => [],
      'options' => [],
      'weight' => 0,
      'enabled' => TRUE,
    ])->save();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();

    $sut = $this->getSut();
    $request = Request::create('/jsonapi/menu_items/jsonapi-menu-items-test');
    $menu = Menu::load('jsonapi-menu-items-test');
    self::assertNotNull($menu);

    $response = $sut->process($request, $menu);
    $top_level = $response->getResponseData();
    self::assertInstanceOf(JsonApiDocumentTopLevel::class, $top_level);
    $document_data = $top_level->getData();
    self::assertInstanceOf(Data::class, $document_data);

    $config_links = array_values(array_filter(
      $document_data->toArray(),
      static fn ($object): bool => $object->getId() === 'menu_link_config:llama'
    ));
    self::assertCount(1, $config_links, 'The menu_link_config link is in the response.');
    $config_link = $config_links[0];
    self::assertInstanceOf(ResourceObject::class, $config_link);
    self::assertSame('menu_link_config--menu_link_config', $config_link->getTypeName());

    $fields = $config_link->getFields();
    self::assertSame('Llama Gabilondo', $fields['title']);
    self::assertSame('menu_test.menu_name_test', $fields['route']['name']);
  }

  /**
   * Tests that a second call to getRouteResourceTypes uses the cache.
   *
   * @covers \Drupal\jsonapi_menu_items\Resource\MenuItemsResource::getRouteResourceTypes
   */
  public function testGetRouteResourceTypesUsesCachedResults(): void {
    Menu::create([
      'id' => 'menu-test',
      'label' => 'Test menu',
      'description' => 'Description text',
    ])->save();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();

    $sut = $this->getSut();
    $route = new Route('/');
    $first = $sut->getRouteResourceTypes($route, 'foo');
    // The cache entry from the first call is now in place. This second call
    // takes the cached branch instead of resolving resource types again.
    $second = $sut->getRouteResourceTypes($route, 'foo');

    $to_type_names = static fn (array $types): array => array_map(
      static fn (ResourceType $type): string => $type->getTypeName(),
      $types
    );
    self::assertNotEmpty($first);
    self::assertSame($to_type_names($first), $to_type_names($second));
  }

  /**
   * Tests that an empty tree returns an empty, cacheable response.
   *
   * @covers \Drupal\jsonapi_menu_items\Resource\MenuItemsResource::process
   */
  public function testProcessWithEmptyTree(): void {
    $this->container->get('module_installer')->install(['menu_test', 'jsonapi_menu_items_test']);
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();

    $sut = $this->getSut();
    // A 'parents' value that matches no menu link makes menu.link_tree
    // return an empty tree, taking process()'s empty-tree early return.
    $request = Request::create('/jsonapi/menu_items/jsonapi-menu-items-test', 'GET', [
      'filter' => ['parents' => 'not_a_real_menu_link'],
    ]);
    $menu = Menu::load('jsonapi-menu-items-test');
    self::assertNotNull($menu);

    $response = $sut->process($request, $menu);
    self::assertSame(200, $response->getStatusCode());
    $top_level = $response->getResponseData();
    self::assertInstanceOf(JsonApiDocumentTopLevel::class, $top_level);
    $document_data = $top_level->getData();
    self::assertInstanceOf(Data::class, $document_data);
    self::assertSame([], $document_data->toArray());
    self::assertInstanceOf(CacheableResponseInterface::class, $response);
    self::assertContains('url.query_args:filter', $response->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Tests each filter branch of applyFiltersToParams via process().
   *
   * Sets up a two-level tree that does not depend on an authenticated
   * user (unlike jsonapi_menu_test.user.logout, which requires one),
   * so this stays independent of user/permission setup.
   *
   * The 'parents' filter is not covered here. It relies on the active
   * trail, which comes from Drupal's real request stack - not the
   * Request instance passed directly to process() - so it behaves
   * differently in a Kernel test than in a real routed request.
   * tests/src/Functional/JsonapiMenuItemsTest.php::testParametersParents
   * covers it instead.
   *
   * @covers \Drupal\jsonapi_menu_items\Resource\MenuItemsResource::applyFiltersToParams
   *
   * @dataProvider dataProcessWithFilters
   */
  #[DataProvider('dataProcessWithFilters')]
  public function testProcessWithFilters(array $filter, array $expected_ids): void {
    $this->container->get('module_installer')->install(['menu_test', 'jsonapi_menu_items_test']);
    MenuLinkContent::create([
      'uuid' => 'aa4ae0fb-b0d9-4fca-9e69-90cf12c93a90',
      'id' => 'llama',
      'title' => 'Llama Gabilondo',
      'link' => 'https://nl.wikipedia.org/wiki/Llama',
      'weight' => 0,
      'menu_name' => 'jsonapi-menu-items-test',
      // A child of the always-accessible 'open' link, giving a second
      // depth level without needing an authenticated user.
      'parent' => 'jsonapi_menu_test.open',
    ])->save();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();

    $sut = $this->getSut();
    $request = Request::create('/jsonapi/menu_items/jsonapi-menu-items-test', 'GET', [
      'filter' => $filter,
    ]);
    $menu = Menu::load('jsonapi-menu-items-test');
    self::assertNotNull($menu);

    $response = $sut->process($request, $menu);
    $top_level = $response->getResponseData();
    self::assertInstanceOf(JsonApiDocumentTopLevel::class, $top_level);
    $document_data = $top_level->getData();
    self::assertInstanceOf(Data::class, $document_data);
    $ids = array_map(static fn ($object): string => $object->getId(), $document_data->toArray());
    sort($ids);
    sort($expected_ids);
    self::assertSame($expected_ids, $ids);
  }

  /**
   * Data for testProcessWithFilters.
   *
   * @return array[]
   *   The test cases.
   */
  public static function dataProcessWithFilters(): array {
    return [
      'min_depth 1: everything' => [
        ['min_depth' => 1],
        ['jsonapi_menu_test.open', 'jsonapi_menu_test.user.login', 'menu_link_content:aa4ae0fb-b0d9-4fca-9e69-90cf12c93a90'],
      ],
      'min_depth 2: only the child link' => [
        ['min_depth' => 2],
        ['menu_link_content:aa4ae0fb-b0d9-4fca-9e69-90cf12c93a90'],
      ],
      'max_depth 1: only the top-level links' => [
        ['max_depth' => 1],
        ['jsonapi_menu_test.open', 'jsonapi_menu_test.user.login'],
      ],
      'parent: only the child of jsonapi_menu_test.open' => [
        ['parent' => 'jsonapi_menu_test.open'],
        ['menu_link_content:aa4ae0fb-b0d9-4fca-9e69-90cf12c93a90'],
      ],
      'conditions: only links provided by jsonapi_menu_items_test' => [
        ['conditions' => ['provider' => ['value' => 'jsonapi_menu_items_test']]],
        ['jsonapi_menu_test.open', 'jsonapi_menu_test.user.login'],
      ],
    ];
  }

  /**
   * Tests with menu_item_extras and fields added to the menu.
   */
  public function testMenuItemExtrasFields(): void {
    $this->container->get('module_installer')->install([
      'menu_test',
      'jsonapi_menu_items_test',
      'menu_item_extras',
    ]);
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
    FieldStorageConfig::create([
      'field_name' => 'test_field',
      'type' => 'string',
      'entity_type' => 'menu_link_content',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'menu_link_content',
      'field_name' => 'test_field',
      'bundle' => 'jsonapi-menu-items-test',
      'label' => 'Test field',
    ])->save();

    MenuLinkContent::create([
      'uuid' => '5d0a9864-d151-4e8f-9f72-b573446ba1d6',
      'id' => 'llama',
      'title' => 'Llama Gabilondo',
      'description' => 'Llama Gabilondo',
      'link' => 'https://nl.wikipedia.org/wiki/Llama',
      'weight' => 0,
      'menu_name' => 'jsonapi-menu-items-test',
      'test_field' => 'foo bar baz',
      'view_mode' => 'default',
    ])->save();
    $sut = $this->getSut();
    $request = Request::create('/jsonapi/menu_items/jsonapi-menu-items-test');
    $menu = Menu::load('jsonapi-menu-items-test');
    self::assertNotNull($menu);

    $response = $sut->process($request, $menu);
    $normalized = $this->container->get('jsonapi.serializer')->normalize(
      $response->getResponseData(),
      'api_json',
      [
        'account' => NULL,
        'sparse_fieldset' => NULL,
      ]
    );
    self::assertInstanceOf(CacheableNormalization::class, $normalized);
    $data = $normalized->getNormalization();
    self::assertIsArray($data);
    self::assertEquals([
      [
        'type' => 'menu_link_content--jsonapi-menu-items-test',
        'id' => 'jsonapi_menu_test.open',
        'attributes' => [
          'description' => 'Home.',
          'enabled' => TRUE,
          'expanded' => FALSE,
          'menu_name' => 'jsonapi-menu-items-test',
          'meta' => [],
          'options' => [],
          'parent' => '',
          'provider' => 'jsonapi_menu_items_test',
          'route' => [
            'name' => 'menu_test.menu_name_test',
            'parameters' => [],
          ],
          'title' => 'Home',
          'url' => '/menu_name_test',
          'weight' => -10,
        ],
      ],
      [
        'type' => 'menu_link_content--jsonapi-menu-items-test',
        'id' => 'menu_link_content:5d0a9864-d151-4e8f-9f72-b573446ba1d6',
        'attributes' => [
          'description' => 'Llama Gabilondo',
          'enabled' => TRUE,
          'expanded' => FALSE,
          'menu_name' => 'jsonapi-menu-items-test',
          'meta' => [
            'entity_id' => '1',
          ],
          'options' => [
            'external' => TRUE,
          ],
          'parent' => '',
          'provider' => 'menu_link_content',
          'route' => [
            'name' => '',
            'parameters' => [],
          ],
          'title' => 'Llama Gabilondo',
          'url' => 'https://nl.wikipedia.org/wiki/Llama',
          'weight' => 0,
          'langcode' => 'en',
          'test_field' => 'foo bar baz',
          'view_mode' => 'default',
        ],
      ],
      [
        'type' => 'menu_link_content--jsonapi-menu-items-test',
        'id' => 'jsonapi_menu_test.user.login',
        'attributes' => [
          'description' => 'Login.',
          'enabled' => TRUE,
          'expanded' => FALSE,
          'menu_name' => 'jsonapi-menu-items-test',
          'meta' => [],
          'options' => [],
          'parent' => '',
          'provider' => 'jsonapi_menu_items_test',
          'route' => [
            'name' => 'user.login',
            'parameters' => [],
          ],
          'title' => 'Login',
          'url' => '/user/login',
          'weight' => 0,
        ],
      ],
    ], $data['data']);
  }

  /**
   * Gets the subject under test.
   *
   * @return \Drupal\jsonapi_menu_items\Resource\MenuItemsResource
   *   The subject under test.
   */
  private function getSut(): MenuItemsResource {
    $sut = MenuItemsResource::create($this->container);
    $sut->setResourceTypeRepository($this->container->get('jsonapi.resource_type.repository'));
    $sut->setResourceResponseFactory($this->container->get('jsonapi_resources.resource_response_factory'));
    return $sut;
  }

}
