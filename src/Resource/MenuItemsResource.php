<?php

namespace Drupal\jsonapi_menu_items\Resource;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\JsonApiResource\ResourceObjectData;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi_resources\Resource\ResourceBase;
use Drupal\menu_link_content\Plugin\Menu\MenuLinkContent;
use Drupal\system\MenuInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Processes a request for a collection of featured nodes.
 *
 * @internal
 */
final class MenuItemsResource extends ResourceBase implements ContainerInjectionInterface {

  /**
   * A list of menu items.
   *
   * @var array
   */
  protected $menuItems = [];

  /**
   * The menu tree.
   *
   * @var \Drupal\system\MenuInterface
   */
  private $menuLinkTree;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  private $entityFieldManager;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $cache;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  private $entityRepository;

  /**
   * Construct a new MenuItemsResource object.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_link_tree
   *   The menu link tree service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager interface.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(MenuLinkTreeInterface $menu_link_tree, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, CacheBackendInterface $cache, EntityRepositoryInterface $entity_repository) {
    $this->menuLinkTree = $menu_link_tree;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->cache = $cache;
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('menu.link_tree'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('cache.discovery'),
      $container->get('entity.repository')
    );
  }

  /**
   * Process the resource request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\system\MenuInterface $menu
   *   The menu.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function process(Request $request, MenuInterface $menu): ResourceResponse {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($menu);

    $parameters = new MenuTreeParameters();
    if ($request->query->has('filter')) {
      $parameters = $this->applyFiltersToParams($request, $parameters);
      $cacheability->addCacheContexts(['url.query_args:filter']);
    }
    $parameters->onlyEnabledLinks();

    $tree = $this->menuLinkTree->load($menu->id(), $parameters);

    if (empty($tree)) {
      $response = $this->createJsonapiResponse(new ResourceObjectData([]), $request, 200, []);
      if ($response instanceof CacheableResponseInterface) {
        $response->addCacheableDependency($cacheability);
      }
      return $response;
    }

    $manipulators = [
      // Only show links that are accessible for the current user.
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      // Use the default sorting of menu links.
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->menuLinkTree->transform($tree, $manipulators);

    $this->getMenuItems($tree, $this->menuItems, $cacheability, $menu);

    $data = new ResourceObjectData($this->menuItems);
    $response = $this->createJsonapiResponse($data, $request, 200, [] /* , $pagination_links */);
    if ($response instanceof CacheableResponseInterface) {
      $response->addCacheableDependency($cacheability);
    }

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteResourceTypes(Route $route, string $route_name): array {
    $map_id = "route_resource_types.resource_type.$route_name";
    $cached = $this->cache->get($map_id);
    if ($cached) {
      return $cached->data;
    }

    $possible_resource_types['menu_link_content'] = ['menu_link_content'];
    // If menu_link_config is enabled, gather those menu links as well.
    if ($this->entityTypeManager->hasDefinition('menu_link_config')) {
      $possible_resource_types['menu_link_config'] = ['menu_link_config'];
    }

    $menu_link_content_definition = $this->entityTypeManager->getDefinition('menu_link_content');
    $menu_link_content_bundle_entity_type = $menu_link_content_definition->get('bundle_entity_type');
    if ($this->entityTypeManager->hasDefinition($menu_link_content_bundle_entity_type)) {
      $bundles = $this->entityTypeManager
        ->getStorage($menu_link_content_bundle_entity_type)
        ->getQuery()
        ->accessCheck(FALSE)
        ->execute();
      $possible_resource_types['menu_link_content'] = $bundles;
    }

    // Now that we've got a list of resource types we care about, go get the
    // resource type for each entity type and bundle.
    $resource_types = [];
    foreach ($possible_resource_types as $entity_type => $bundles) {
      foreach ($bundles as $bundle) {
        $resource_type = $this->resourceTypeRepository->get($entity_type, $bundle);
        if (!is_null($resource_type)) {
          $resource_types[] = $resource_type;
        }
      }
    }

    $this->cache->set($map_id, $resource_types, CacheBackendInterface::CACHE_PERMANENT, [
      'jsonapi_resource_types',
      'entity_field_info',
      'entity_bundles',
      'entity_types',
    ]);

    return $resource_types;
  }

  /**
   * Apply filters to the menu parameters.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\Core\Menu\MenuTreeParameters $parameters
   *   The cache metadata.
   *
   * @return \Drupal\Core\Menu\MenuTreeParameters
   *   The Menu Tree Parameters object.
   */
  protected function applyFiltersToParams(Request $request, MenuTreeParameters $parameters) {
    $filter = $request->query->all('filter');

    if (!empty($filter['min_depth'])) {
      $parameters->setMinDepth((int) $filter['min_depth']);
    }

    if (!empty($filter['max_depth'])) {
      $parameters->setMaxDepth((int) $filter['max_depth']);
    }

    if (!empty($filter['parent'])) {
      $parameters->setRoot($filter['parent']);
      $parameters->excludeRoot();
    }

    if (!empty($filter['parents'])) {
      $parents = explode(',', preg_replace("/\s+/", "", $filter['parents']));
      $parameters->addExpandedParents($parents);
    }

    if (!empty($filter['conditions']) && is_array($filter['conditions'])) {
      $condition_fields = array_keys($filter['conditions']);
      foreach ($condition_fields as $definition_field) {
        $value = !empty($filter['conditions'][$definition_field]['value']) ? $filter['conditions'][$definition_field]['value'] : '';
        $operator = !empty($filter['conditions'][$definition_field]['operator']) ? $filter['conditions'][$definition_field]['operator'] : '=';
        $parameters->addCondition($definition_field, $value, $operator);
      }
    }

    return $parameters;
  }

  /**
   * Generate the menu items.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $tree
   *   The menu tree.
   * @param array $items
   *   The already created items.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache
   *   The cacheable metadata.
   * @param \Drupal\system\MenuInterface $menu
   *   The menu that the links belong to.
   */
  protected function getMenuItems(array $tree, array &$items, CacheableMetadata $cache, MenuInterface $menu) {
    $menu_link_content_storage = $this->entityTypeManager->getStorage('menu_link_content');

    foreach ($tree as $menu_link) {
      if ($menu_link->access !== NULL && !$menu_link->access instanceof AccessResultInterface) {
        throw new \DomainException('MenuLinkTreeElement::access must be either NULL or an AccessResultInterface object.');
      }

      if ($menu_link->access instanceof AccessResultInterface) {
        $cache->merge(CacheableMetadata::createFromObject($menu_link->access));
      }

      // Only return accessible links.
      if ($menu_link->access instanceof AccessResultInterface && !$menu_link->access->isAllowed()) {
        continue;
      }

      $id = $menu_link->link->getPluginId();
      [$plugin] = explode(':', $id, 2);
      if ($plugin === 'menu_link_config') {
        $resource_type = $this->resourceTypeRepository->get('menu_link_config', 'menu_link_config');
      }
      else {
        $resource_type = $this->resourceTypeRepository->get('menu_link_content', $menu_link->link->getMenuName());
        if ($resource_type === NULL) {
          $resource_type = $this->resourceTypeRepository->get('menu_link_content', 'menu_link_content');
        }
      }

      $url = $menu_link->link->getUrlObject()->toString(TRUE);
      assert($url instanceof GeneratedUrl);
      $cache->addCacheableDependency($url);

      $fields = [
        'description' => $menu_link->link->getDescription(),
        'enabled' => $menu_link->link->isEnabled(),
        'expanded' => $menu_link->link->isExpanded(),
        'menu_name' => $menu_link->link->getMenuName(),
        'meta' => $menu_link->link->getMetaData(),
        'options' => $menu_link->link->getOptions(),
        'parent' => $menu_link->link->getParent(),
        'provider' => $menu_link->link->getProvider(),
        'route' => [
          'name' => $menu_link->link->getRouteName(),
          'parameters' => $menu_link->link->getRouteParameters(),
        ],
        'title' => (string) $menu_link->link->getTitle(),
        'url' => $url->getGeneratedUrl(),
        'weight' => (int) $menu_link->link->getWeight(),
      ];

      if ($menu_link->link instanceof MenuLinkContent) {
        // @todo once minimum supported Drupal core version is 10.2, use
        //   \Drupal\menu_link_content\Plugin\Menu\MenuLinkContent::getEntity.
        // $link = $menu_link->link->getEntity();
        $entity_id = $menu_link->link->getMetaData()['entity_id'] ?? NULL;
        if ($entity_id !== NULL) {
          $link = $menu_link_content_storage->load($entity_id);
          if ($link !== NULL) {
            $link = $this->entityRepository->getTranslationFromContext($link);

            $field_definitions = $this->entityFieldManager->getFieldDefinitions($link->getEntityTypeId(), $link->bundle());
            foreach ($field_definitions as $field_name => $field_definition) {
              if ($field_definition instanceof BaseFieldDefinition && $field_definition->getProvider() === 'menu_link_content') {
                continue;
              }
              $fields[$field_name] = $link->{$field_name};
            }
          }
        }
      }

      $links = new LinkCollection([]);

      $resource_object_cacheability = new CacheableMetadata();
      $resource_object_cacheability->addCacheableDependency($menu_link->access);
      $resource_object_cacheability->addCacheableDependency($cache);
      $items[$id] = new ResourceObject($resource_object_cacheability, $resource_type, $id, NULL, $fields, $links);

      if ($menu_link->subtree) {
        $this->getMenuItems($menu_link->subtree, $items, $cache, $menu);
      }
    }
  }

}
