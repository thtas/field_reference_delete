<?php
namespace Drupal\field_reference_delete;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\Component\Utility\NestedArray;

class FieldReferenceDelete {

  /**
   * All FieldConfig entities for entity_reference fields
   * @var referenceFieldConfigs[] = \Drupal\Core\Field\FieldConfigInterface
   */
  private $referenceFieldConfigs;

  /**
   * @var $entityTypeManager = \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * @var $fieldTypePluginManager = \Drupal\Core\Field\FieldTypePluginManagerInterface
   */
  private $fieldTypePluginManager;

  /**
   *
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param FieldTypePluginManagerInterface $field_type_plugin_manager
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FieldTypePluginManagerInterface $field_type_plugin_manager) {

    $this->entityTypeManager = $entity_type_manager;
    $this->fieldTypePluginManager = $field_type_plugin_manager;

    // Set up a list of refernce field configs keyed by field id.
    $field_config_storage = $this->entityTypeManager->getStorage('field_config');
    foreach ($field_config_storage->loadMultiple() as $id => $field_config) {
      if ($this->isReferenceField($field_config)) {
        $this->referenceFieldConfigs[$id] = $field_config;
      }
    }
  }

  /**
   * Check if the FieldConfig item is an entity_reference or a derivative.
   * 
   * @param FieldConfigInterface $field_config
   * @return bool
   *   TRUE if the field config is an entity_reference or the plugin class
   *   inherits from EntityReferenceItem.
   */
  public function isReferenceField(FieldConfigInterface $field_config) {

    if ($field_config->getType() == 'entity_reference') {
      return TRUE;
    }

    $plugin_class = $this->fieldTypePluginManager->getPluginClass($field_config->getType());
    if (is_subclass_of($plugin_class, 'Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem')) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Delete all entities which reference this entity
   *
   * @param EntityInterface $entity
   */
  public function delete(EntityInterface $entity) {
    foreach ($this->getDeletableEntities($entity) as $entity_type => $entities) {
      $this->entityTypeManager->getStorage($entity_type)->delete($entities);
    }
  }

  /**
   * Recursively compile an array of references for an Entity.
   *
   * @param EntityInterface $entity
   *   The entity to consider.
   * @param bool $recurse
   *   Whether or not recusively gather deeper references
   * @param array $references
   *   An array of known references, used with recursion
   */
  public function getDeletableEntities(EntityInterface $entity, $recurse = FALSE, array $references = []) {
    foreach ($this->referenceFieldConfigs as $config_id => $field_config) {

      if (!$field_config->getThirdPartySetting('field_reference_delete', 'delete_on_reference_delete', 1)) {
        continue;
      }

      // Ensure the field target entity type and the current entity type match.
      $field_target_type = $field_config->getSetting('target_type');
      if ($field_target_type != $entity->getEntityTypeId()) {
        continue;
      }

      // Find all entities which referencing the incoming entity via this field
      list($field_entity_type) = explode(".", $config_id);
      $entity_storage = $this->entityTypeManager->getStorage($field_entity_type);
      $field_name = $field_config->getName();
      $query = $entity_storage->getQuery()->condition($field_name, $entity->id());

      $ids = $query->execute();

      foreach ($entity_storage->loadMultiple($ids) as $id => $reference) {
        
        // This entity has already been found
        if (!isset($references[$field_entity_type][$id])) {
          $references[$field_entity_type][$id] = $reference;
          if ($recurse) {
            $deeper_references = $this->getDeletableEntities($reference, $recurse, $references);
            $references = NestedArray::mergeDeepArray([$references, $deeper_references], TRUE);
          }
        }
      }
    }

    return $references;
  }

}
