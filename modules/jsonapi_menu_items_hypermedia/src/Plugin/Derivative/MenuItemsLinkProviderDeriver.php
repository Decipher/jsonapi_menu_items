<?php

namespace Drupal\jsonapi_menu_items_hypermedia\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides LinkProvider plugin definitions for custom menus.
 */
class MenuItemsLinkProviderDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs new MenuItemsLinkProvider.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $menu_storage = $this->entityTypeManager->getStorage('menu');
    foreach ($menu_storage->loadMultiple() as $menu => $entity) {
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
