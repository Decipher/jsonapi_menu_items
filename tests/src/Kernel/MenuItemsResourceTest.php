<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_menu_items\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi_menu_items\Resource\MenuItemsResource;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests MenuItemsResource.
 *
 * @group jsonapi_menu_items
 * @coversDefaultClass \Drupal\jsonapi_menu_items\Resource\MenuItemsResource
 */
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
   * @dataProvider dataGetRouteResourceTypes
   *
   * @covers ::getRouteResourceTypes
   */
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
   * Tests process.
   *
   * @dataProvider dataProcess
   * @covers ::process
   */
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
    $resource_objects = array_map(
      static fn (ResourceObject $object) => [
        'resource_type' => $object->getTypeName(),
        'id' => $object->getId(),
      ],
      $top_level->getData()->toArray()
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
