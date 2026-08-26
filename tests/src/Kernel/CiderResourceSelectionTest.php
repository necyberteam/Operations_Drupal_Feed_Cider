<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Tests the display-name-aware entity reference selection handler.
 *
 * Entity-reference autocompletes on the CiDeR resource fields display the
 * resolved display name (via the bundle-class label() override in
 * CiderResourceNode), but with the core 'default:node' handler they search
 * only the raw title column. An editor typing the display name they see
 * (e.g. a resource's display name) gets zero results. This handler matches
 * on display name, then short name, then title.
 *
 * @group operations_cider
 */
class CiderResourceSelectionTest extends KernelTestBase {

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
  }

  private function makeResourceNode(string $display, string $short, string $title): Node {
    $node = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => $title,
      'field_rp_display_name' => $display,
      'field_cider_short_name' => $short,
      'status' => 1,
    ]);
    $node->save();
    return $node;
  }

  private function getHandler() {
    return \Drupal::service('plugin.manager.entity_reference_selection')->getInstance([
      'target_type' => 'node',
      'handler' => 'cider_resource',
      'handler_settings' => [
        'target_bundles' => [
          'access_active_resources_from_cid' => 'access_active_resources_from_cid',
        ],
      ],
    ]);
  }

  private function referencedNids($handler, string $match): array {
    $result = $handler->getReferenceableEntities($match, 'CONTAINS', 10);
    $nids = [];
    foreach ($result as $bundle => $items) {
      foreach ($items as $nid => $label) {
        $nids[] = (int) $nid;
      }
    }
    return $nids;
  }

  public function testMatchesOnDisplayName(): void {
    $node = $this->makeResourceNode('DispName', 'ShortName', 'Raw Descriptive Title');
    $handler = $this->getHandler();
    $this->assertSame([(int) $node->id()], $this->referencedNids($handler, 'DispName'));
  }

  public function testMatchesOnShortName(): void {
    $node = $this->makeResourceNode('DispName', 'ShortName', 'Raw Descriptive Title');
    $handler = $this->getHandler();
    $this->assertSame([(int) $node->id()], $this->referencedNids($handler, 'ShortName'));
  }

  public function testMatchesOnTitle(): void {
    $node = $this->makeResourceNode('DispName', 'ShortName', 'Raw Descriptive Title');
    $handler = $this->getHandler();
    $this->assertSame([(int) $node->id()], $this->referencedNids($handler, 'Raw Descript'));
  }

  public function testNonMatchingStringReturnsEmpty(): void {
    $this->makeResourceNode('DispName', 'ShortName', 'Raw Descriptive Title');
    $handler = $this->getHandler();
    $this->assertSame([], $this->referencedNids($handler, 'NoSuchStringAnywhere'));
  }

}
