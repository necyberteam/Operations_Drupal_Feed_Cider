<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Tests the validate handler that mirrors select choices to the field path.
 *
 * operations_cider_inheritable_boolean_validate() copies each select's
 * effective boolean back to $form_state->getValue([field, 'value']) so other
 * validators reading the field the normal way (e.g. access_misc's account-setup
 * rule) see the value that will actually be stored/rendered. "Inherit" must
 * resolve to the parent group's value, or NULL when there is no group. This is
 * the subtlest logic in the change and is exercised here in isolation (the full
 * node form pulls in unrelated required elements).
 *
 * @group operations_cider
 */
class InheritableBooleanValidateTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'node', 'field', 'text', 'link', 'key', 'operations_cider'];

  private const BOOL_FIELD = 'field_rp_account_required';

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'Resource'])->save();
    NodeType::create(['type' => 'resource_group', 'name' => 'Resource Group'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_cider_resources',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cider_resources',
      'entity_type' => 'node',
      'bundle' => 'resource_group',
      'settings' => ['handler' => 'default'],
    ])->save();

    FieldStorageConfig::create(['field_name' => self::BOOL_FIELD, 'entity_type' => 'node', 'type' => 'boolean'])->save();
    foreach (['access_active_resources_from_cid', 'resource_group'] as $bundle) {
      FieldConfig::create(['field_name' => self::BOOL_FIELD, 'entity_type' => 'node', 'bundle' => $bundle])->save();
    }

    require_once \Drupal::service('extension.list.module')->getPath('operations_cider') . '/operations_cider.module';
  }

  private function makeResource(): Node {
    $node = Node::create(['type' => 'access_active_resources_from_cid', 'title' => 'Res', 'status' => 1]);
    $node->save();
    return $node;
  }

  private function makeGroup(Node $member, $bool): Node {
    Node::create([
      'type' => 'resource_group',
      'title' => 'Group',
      'status' => 1,
      'field_cider_resources' => [['target_id' => $member->id()]],
      self::BOOL_FIELD => $bool,
    ])->save();
    return $member;
  }

  /**
   * Run the validate handler for a given select choice and return the value it
   * mirrored to the standard field path.
   */
  private function mirroredValue(Node $node, string $selection) {
    $form = [];
    $form_state = new FormState();
    $form_object = \Drupal::entityTypeManager()->getFormObject('node', 'edit');
    $form_object->setEntity($node);
    $form_state->setFormObject($form_object);
    $form_state->setValue('operations_cider_bool', [self::BOOL_FIELD => $selection]);
    operations_cider_inheritable_boolean_validate($form, $form_state);
    return $form_state->getValue([self::BOOL_FIELD, 'value']);
  }

  public function testExplicitYesMirrorsOne(): void {
    $node = $this->makeResource();
    $this->assertSame(1, $this->mirroredValue($node, '1'));
  }

  public function testExplicitNoMirrorsZero(): void {
    $node = $this->makeResource();
    $this->assertSame(0, $this->mirroredValue($node, '0'));
  }

  public function testInheritResolvesToGroupTrue(): void {
    $node = $this->makeResource();
    $this->makeGroup($node, TRUE);
    $this->assertSame(1, $this->mirroredValue($node, ''),
      'Inherit mirrors the group\'s TRUE so paired validators see it as required.');
  }

  public function testInheritResolvesToGroupFalse(): void {
    $node = $this->makeResource();
    $this->makeGroup($node, FALSE);
    $this->assertSame(0, $this->mirroredValue($node, ''));
  }

  public function testInheritWithNoGroupMirrorsNull(): void {
    $node = $this->makeResource();
    $this->assertNull($this->mirroredValue($node, ''),
      'With no group there is nothing to inherit, so the effective value is empty.');
  }

}
