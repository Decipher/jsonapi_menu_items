<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_menu_items\Kernel;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\jsonapi\ResourceType\ResourceTypeRepository;

/**
 * Removes 'excluded_field' and disables 'disabled_field' on resource types.
 *
 * Overriding the repository is the only way to remove a field from a resource
 * type.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class FieldFilteringResourceTypeRepository extends ResourceTypeRepository {

  /**
   * {@inheritdoc}
   */
  protected function getFields(array $field_names, EntityTypeInterface $entity_type, $bundle) {
    $fields = parent::getFields($field_names, $entity_type, $bundle);
    unset($fields['excluded_field']);
    if (isset($fields['disabled_field'])) {
      $fields['disabled_field'] = $fields['disabled_field']->disabled();
    }
    return $fields;
  }

}
