<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * @group operations_cider
 */
class ResourceDisplayNameTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'node', 'field', 'text', 'key', 'operations_cider'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'Resource'])->save();
    foreach (['field_rp_display_name', 'field_cider_short_name'] as $name) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'string'])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'access_active_resources_from_cid'])->save();
    }
    // Bundle with neither field, to exercise the hasField()-false path.
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  private function makeNode(?string $display, ?string $short, string $title): Node {
    $node = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => $title,
      'field_rp_display_name' => $display,
      'field_cider_short_name' => $short,
    ]);
    $node->save();
    return $node;
  }

  public function testDisplayNameWins(): void {
    $node = $this->makeNode('Editor Name', 'Short', 'Descriptive Title');
    $this->assertSame('Editor Name', operations_cider_resource_display_name($node));
  }

  public function testFallsBackToShortWhenDisplayBlank(): void {
    $node = $this->makeNode('', 'Short', 'Descriptive Title');
    $this->assertSame('Short', operations_cider_resource_display_name($node));
  }

  public function testFallsBackToTitleWhenBothBlank(): void {
    $node = $this->makeNode('', '', 'Descriptive Title');
    $this->assertSame('Descriptive Title', operations_cider_resource_display_name($node));
  }

  public function testWhitespaceOnlyDisplayNameFallsBackToShort(): void {
    $node = $this->makeNode('   ', 'Short', 'Descriptive Title');
    $this->assertSame('Short', operations_cider_resource_display_name($node));
  }

  public function testLiteralZeroDisplayNameIsTreatedAsSet(): void {
    $node = $this->makeNode('0', 'Short', 'Descriptive Title');
    $this->assertSame('0', operations_cider_resource_display_name($node));
  }

  public function testBundleWithoutFieldsFallsBackToTitle(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Plain Page Title']);
    $node->save();
    $this->assertSame('Plain Page Title', operations_cider_resource_display_name($node));
  }

  public function testNodeLoadDoesNotMutateTitle(): void {
    $node = $this->makeNode('', 'Short', 'Descriptive Title');
    $nid = $node->id();
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$nid]);
    $reloaded = Node::load($nid);
    // After the load hook is removed, getTitle() returns the persisted title,
    // not the short name.
    $this->assertSame('Descriptive Title', $reloaded->getTitle());
  }
}
