<?php

namespace Drupal\Tests\operations_cider\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\operations_cider\Service\SdsEnrichmentService;
use Drupal\operations_cider\Service\SdsSoftwareService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests SdsSoftwareService::buildPayload() field mapping, order and capping.
 *
 * Like OodSoftwareServiceTest, this stays on the pure method; the node loop in
 * updateAll() is covered by the manual pass rather than mocked entity storage.
 *
 * @group operations_cider
 */
class SdsSoftwareServiceTest extends UnitTestCase {

  /**
   * Build a service instance with mocked dependencies.
   *
   * The SDS double maps any item to its ai_* fields, matching what the real
   * SdsEnrichmentService::mapSdsFields() does.
   */
  protected function makeService(): SdsSoftwareService {
    $etm = $this->prophesize(EntityTypeManagerInterface::class)->reveal();
    $channel = $this->prophesize(LoggerChannelInterface::class)->reveal();
    $lf = $this->prophesize(LoggerChannelFactoryInterface::class);
    $lf->get('operations_cider')->willReturn($channel);

    $sds = $this->createMock(SdsEnrichmentService::class);
    $sds->method('mapSdsFields')->willReturnCallback(fn(array $item) => [
      'description' => $item['ai_description'] ?? '',
      'research_field' => $item['ai_research_field'] ?? '',
      'web_page' => $item['software_web_page'] ?? '',
      'documentation' => $item['software_documentation'] ?? '',
    ]);

    return new SdsSoftwareService($etm, $lf->reveal(), $sds);
  }

  /**
   * Build a catalog of $count entries keyed the way SDS responses are.
   */
  protected function catalog(int $count): array {
    $catalog = [];
    for ($i = 1; $i <= $count; $i++) {
      $catalog['app' . $i] = ['software_name' => 'App' . $i];
    }
    return $catalog;
  }

  /**
   * Tests that SDS fields are mapped and software_name becomes name.
   */
  public function testMapsSdsFieldsAndKeepsName(): void {
    $payload = $this->makeService()->buildPayload([
      'gromacs' => [
        'software_name' => 'GROMACS',
        'ai_description' => 'Molecular dynamics',
        'ai_research_field' => 'Chemistry',
        'software_web_page' => 'https://example.org/gromacs',
        'software_documentation' => 'https://example.org/gromacs/docs',
      ],
    ]);

    $this->assertSame([
      'name' => 'GROMACS',
      'description' => 'Molecular dynamics',
      'research_field' => 'Chemistry',
      'web_page' => 'https://example.org/gromacs',
      'documentation' => 'https://example.org/gromacs/docs',
    ], $payload['items'][0]);
  }

  /**
   * Tests that the SDS response order is preserved.
   */
  public function testPreservesCatalogOrder(): void {
    $payload = $this->makeService()->buildPayload([
      'zebra' => ['software_name' => 'Zebra'],
      'alpha' => ['software_name' => 'Alpha'],
      'middle' => ['software_name' => 'Middle'],
    ]);

    $this->assertSame(
      ['Zebra', 'Alpha', 'Middle'],
      array_column($payload['items'], 'name')
    );
  }

  /**
   * Tests that items are capped while total reports the pre-cap count.
   */
  public function testCapsItemsAndReportsPreCapTotal(): void {
    $payload = $this->makeService()->buildPayload($this->catalog(1104));

    $this->assertSame(1104, $payload['total']);
    $this->assertCount(SdsSoftwareService::ITEM_CAP, $payload['items']);
    $this->assertSame('App1', $payload['items'][0]['name']);
    $this->assertSame('App200', $payload['items'][SdsSoftwareService::ITEM_CAP - 1]['name']);
  }

  /**
   * Tests that a catalog at the cap is not reported as truncated.
   */
  public function testCatalogExactlyAtCapIsNotTruncated(): void {
    $payload = $this->makeService()->buildPayload($this->catalog(SdsSoftwareService::ITEM_CAP));

    $this->assertSame(SdsSoftwareService::ITEM_CAP, $payload['total']);
    $this->assertCount(SdsSoftwareService::ITEM_CAP, $payload['items']);
  }

  /**
   * Tests that an empty catalog produces an empty payload.
   */
  public function testEmptyCatalogProducesEmptyPayload(): void {
    $this->assertSame(
      ['total' => 0, 'items' => []],
      $this->makeService()->buildPayload([])
    );
  }

}
