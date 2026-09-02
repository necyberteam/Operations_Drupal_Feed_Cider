<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Tests the submit handler for the three-state inheritable-boolean control.
 *
 * The Resource edit form replaces an inheritable boolean checkbox with an
 * inherit/Yes/No select; operations_cider_inheritable_boolean_submit() writes
 * the choice back to the node. A wrong mapping silently flips account/MFA
 * flags on save, so the round-trip is locked down here. The full form build is
 * NOT exercised at kernel level (the node form pulls in Domain Access and other
 * required elements that a programmatic submit cannot satisfy); the widget's
 * on-page behaviour is covered by Cypress. This isolates the data-integrity
 * core: given a selection in form state, what gets stored on the entity.
 *
 * @group operations_cider
 */
class InheritableBooleanWidgetTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'node', 'field', 'text', 'link', 'key', 'operations_cider'];

  private const BOOL_FIELD = 'field_rp_account_required';

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'Resource'])->save();

    FieldStorageConfig::create([
      'field_name' => self::BOOL_FIELD,
      'entity_type' => 'node',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => self::BOOL_FIELD,
      'entity_type' => 'node',
      'bundle' => 'access_active_resources_from_cid',
    ])->save();

    require_once \Drupal::service('extension.list.module')->getPath('operations_cider') . '/operations_cider.module';
  }

  /**
   * Build a node and a form state carrying the given select choices, then run
   * the submit handler and return the reloaded... in kernel we just re-read the
   * in-memory entity the handler mutated (getFormObject()->getEntity()).
   */
  private function runSubmit(Node $node, array $selections): Node {
    $form = [];
    $form_state = new FormState();
    $form_object = \Drupal::entityTypeManager()->getFormObject('node', 'edit');
    $form_object->setEntity($node);
    $form_state->setFormObject($form_object);
    $form_state->setValue('operations_cider_bool', $selections);
    operations_cider_inheritable_boolean_submit($form, $form_state);
    return $form_state->getFormObject()->getEntity();
  }

  private function makeNode($bool = NULL): Node {
    $values = ['type' => 'access_active_resources_from_cid', 'title' => 'Res', 'status' => 1];
    if ($bool !== NULL) {
      $values[self::BOOL_FIELD] = $bool;
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  public function testInheritSelectionEmptiesTheField(): void {
    // Start with an explicit value, then choose "inherit" — must go empty so
    // render-time inheritance takes over.
    $node = $this->makeNode(TRUE);
    $result = $this->runSubmit($node, [self::BOOL_FIELD => '']);
    $this->assertTrue($result->get(self::BOOL_FIELD)->isEmpty(), 'Inherit selection clears the field.');
  }

  public function testYesSelectionStoresExplicitTrue(): void {
    $node = $this->makeNode();
    $result = $this->runSubmit($node, [self::BOOL_FIELD => '1']);
    $this->assertFalse($result->get(self::BOOL_FIELD)->isEmpty());
    $this->assertSame(1, (int) $result->get(self::BOOL_FIELD)->value);
  }

  public function testNoSelectionStoresExplicitFalse(): void {
    // The important override case: an explicit "No" must be a stored 0, NOT
    // empty — otherwise it would re-inherit a group's TRUE.
    $node = $this->makeNode();
    $result = $this->runSubmit($node, [self::BOOL_FIELD => '0']);
    $this->assertFalse($result->get(self::BOOL_FIELD)->isEmpty(), 'Explicit No is a stored value, not empty.');
    $this->assertSame(0, (int) $result->get(self::BOOL_FIELD)->value);
  }

  public function testMultipleFieldsRoundTripIndependently(): void {
    // Two booleans in one submit: one explicit, one inherit.
    FieldStorageConfig::create(['field_name' => 'field_rp_mfa_required', 'entity_type' => 'node', 'type' => 'boolean'])->save();
    FieldConfig::create(['field_name' => 'field_rp_mfa_required', 'entity_type' => 'node', 'bundle' => 'access_active_resources_from_cid'])->save();

    $node = $this->makeNode(TRUE);
    $node->set('field_rp_mfa_required', TRUE);
    $node->save();

    $result = $this->runSubmit($node, [
      self::BOOL_FIELD => '0',
      'field_rp_mfa_required' => '',
    ]);
    $this->assertSame(0, (int) $result->get(self::BOOL_FIELD)->value, 'account_required overridden to No.');
    $this->assertTrue($result->get('field_rp_mfa_required')->isEmpty(), 'mfa_required set back to inherit.');
  }

  public function testUnknownFieldInSelectionsIsIgnored(): void {
    $node = $this->makeNode();
    // A stray key that is not a field on the node must not throw.
    $result = $this->runSubmit($node, ['field_does_not_exist' => '1', self::BOOL_FIELD => '1']);
    $this->assertSame(1, (int) $result->get(self::BOOL_FIELD)->value);
  }

}
