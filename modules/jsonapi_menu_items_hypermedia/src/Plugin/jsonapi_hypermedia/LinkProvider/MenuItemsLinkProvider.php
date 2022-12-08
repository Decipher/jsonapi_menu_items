<?php

namespace Drupal\jsonapi_menu_items_hypermedia\Plugin\jsonapi_hypermedia\LinkProvider;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi_hypermedia\AccessRestrictedLink;
use Drupal\jsonapi_hypermedia\Plugin\LinkProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a JSON:API Menu Items LinkProvider.
 *
 * @JsonapiHypermediaLinkProvider(
 *   id = "jsonapi_menu_items.top_level.menu_items",
 *   deriver = "Drupal\jsonapi_menu_items_hypermedia\Plugin\Derivative\MenuItemsLinkProviderDeriver",
 *   link_relation_type = "menu_items",
 * )
 */
final class MenuItemsLinkProvider extends LinkProviderBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getLink($context) {
    assert($context instanceof JsonApiDocumentTopLevel);
    return AccessRestrictedLink::createLink(
      AccessResult::allowed(),
      new CacheableMetadata(),
      new Url('jsonapi_menu_items.menu', ['menu' => $this->pluginDefinition['link_context']['menu_name']]),
      $this->getLinkRelationType()
    );
  }

}
