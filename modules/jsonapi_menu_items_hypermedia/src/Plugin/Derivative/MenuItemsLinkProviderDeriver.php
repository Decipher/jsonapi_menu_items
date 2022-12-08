<?php

namespace Drupal\jsonapi_menu_items_hypermedia\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides LinkProvider plugin definitions for custom menus.
 */
class MenuItemsLinkProviderDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The menu storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $menuStorage;

  /**
   * Constructs new MenuItemsLinkProvider.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $menu_storage
   *   The menu storage.
   */
  public function __construct(EntityStorageInterface $menu_storage) {
    $this->menuStorage = $menu_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static($container
      ->get('entity_type.manager')
      ->getStorage('menu'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->menuStorage->loadMultiple() as $menu => $entity) {
      $this->derivatives[$menu] = array_merge($base_plugin_definition, [
        'link_key' => "menu_items--{$menu}",
        'link_context' => [
          'top_level_object' => 'entrypoint',
          'menu_name' => $menu,
        ],
      ]);
    }
    return $this->derivatives;
  }

}
