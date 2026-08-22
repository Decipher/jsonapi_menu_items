<?php

declare(strict_types=1);

namespace Drupal\jsonapi_menu_items_hypermedia\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides LinkProvider plugin definitions for custom menus.
 *
 * @phpstan-consistent-constructor
 */
class MenuItemsLinkProviderDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Constructs new MenuItemsLinkProvider.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
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
