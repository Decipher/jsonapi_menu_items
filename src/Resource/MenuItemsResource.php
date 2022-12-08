<?php

namespace Drupal\jsonapi_menu_items\Resource;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\GeneratedUrl;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\JsonApiResource\ResourceObjectData;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi_resources\Resource\ResourceBase;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\system\MenuInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Processes a request for a collection of featured nodes.
 *
 * @internal
 */
final class MenuItemsResource extends ResourceBase {

  /**
   * A list of menu items.
   *
   * @var array
   */
  protected $menuItems = [];

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

    $menu_tree = \Drupal::menuTree();
    $tree = $menu_tree->load($menu->id(), $parameters);

    if (empty($tree)) {
      $response = $this->createJsonapiResponse(new ResourceObjectData([]), $request, 200, []);
      $response->addCacheableDependency($cacheability);
      return $response;
    }

    $manipulators = [
      // Only show links that are accessible for the current user.
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      // Use the default sorting of menu links.
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $menu_tree->transform($tree, $manipulators);

    $this->getMenuItems($tree, $this->menuItems, $cacheability);

    $data = new ResourceObjectData($this->menuItems);
    $response = $this->createJsonapiResponse($data, $request, 200, [] /* , $pagination_links */);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteResourceTypes(Route $route, string $route_name): array {
    $resource_types = [];

    foreach (['menu_link_config', 'menu_link_content'] as $type) {
      $resource_type = $this->resourceTypeRepository->get($type, $type);
      if ($resource_type) {
        $resource_types[] = $resource_type;
      }
    }
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
   * @param array $tree
   *   The menu tree.
   * @param array $items
   *   The already created items.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache
   *   The cacheable metadata.
   */
  protected function getMenuItems(array $tree, array &$items, CacheableMetadata $cache) {
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
      [$plugin] = explode(':', $id);

      switch ($plugin) {
        case 'menu_link_content':
        case 'menu_link_config':
          $resource_type = $this->resourceTypeRepository->get($plugin, $plugin);
          break;

        default:
          // @todo Use a custom resource type?
          $resource_type = $this->resourceTypeRepository->get('menu_link_content', 'menu_link_content');
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
      $links = new LinkCollection([]);

      $resource_object_cacheability = new CacheableMetadata();
      $resource_object_cacheability->addCacheableDependency($menu_link->access);
      $resource_object_cacheability->addCacheableDependency($cache);
      $items[$id] = new ResourceObject($resource_object_cacheability, $resource_type, $id, NULL, $fields, $links);

      if ($menu_link->subtree) {
        $this->getMenuItems($menu_link->subtree, $items, $cache);
      }
    }
  }

}
