<?php

namespace Drupal\operations_cider\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Caches the SDS software catalog for resources with no XDMoD software data.
 *
 * TopSoftwareService ranks applications by XDMoD job count; most resources
 * report nothing to XDMoD, so their Software section is a dead end. For those,
 * this service stores the SDS-reported catalog instead. XDMoD always wins where
 * both exist — it carries usage ranking, which SDS does not.
 *
 * Stored shape on field_rp_sds_software:
 * @code
 * {"total": 1104, "items": [{"name": …, "description": …, …}]}
 * @endcode
 * The separate "total" lets the template distinguish a list truncated at the
 * cap from one that happens to have exactly ITEM_CAP entries.
 */
class SdsSoftwareService {

  /**
   * Maximum number of software entries stored per resource.
   *
   * ACES reports 1104 entries (~434KB of trimmed JSON); the cap keeps the
   * worst-case payload around 80KB.
   */
  const ITEM_CAP = 200;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The SDS enrichment service.
   *
   * @var \Drupal\operations_cider\Service\SdsEnrichmentService
   */
  protected SdsEnrichmentService $sds;

  /**
   * Constructs an SdsSoftwareService.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    SdsEnrichmentService $sds,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('operations_cider');
    $this->sds = $sds;
  }

  /**
   * Build the stored payload from a raw SDS catalog.
   *
   * Preserves the catalog's order — SDS returns entries alphabetically by
   * software_name, and there is no usage signal to re-rank by.
   *
   * @param array<string, array<string, mixed>> $catalog
   *   SDS catalog as returned by
   *   SdsEnrichmentService::fetchCatalogByResourceOrNull(): raw SDS items,
   *   keyed by lowercase software name.
   *
   * @return array{total: int, items: array<int, array<string, string>>}
   *   The stored payload; total is the pre-cap catalog count.
   */
  public function buildPayload(array $catalog): array {
    $rows = [];
    foreach ($catalog as $item) {
      if (count($rows) >= self::ITEM_CAP) {
        break;
      }
      $rows[] = ['name' => $item['software_name'] ?? ''] + $this->sds->mapSdsFields($item);
    }
    return [
      'total' => count($catalog),
      'items' => $rows,
    ];
  }

  /**
   * Refresh the cached SDS software list on every published resource node.
   *
   * Idempotent from any prior state: a resource that gains XDMoD data has its
   * stale SDS payload cleared, and a resource that loses XDMoD data picks the
   * SDS list back up on the next run.
   */
  public function updateAll(): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'access_active_resources_from_cid')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();
    if (!$nids) {
      return;
    }

    $updated = 0;
    foreach ($storage->loadMultiple($nids) as $node) {
      if (!$node->hasField('field_rp_sds_software')) {
        continue;
      }

      // XDMoD wins where it has data. Drop any payload cached earlier so the
      // node does not keep a stale hidden list.
      if ($node->hasField('field_rp_top_software') && !$node->get('field_rp_top_software')->isEmpty()) {
        if ($this->saveIfChanged($node, NULL)) {
          $updated++;
        }
        continue;
      }

      if (!$node->hasField('field_access_global_resource_id') || $node->get('field_access_global_resource_id')->isEmpty()) {
        continue;
      }
      $global_id = $node->get('field_access_global_resource_id')->value;

      // Query per node: the group-level rollup in SdsAvailabilityService marks
      // whole resource families available when only one member has data.
      $catalog = $this->sds->fetchCatalogByResourceOrNull($global_id);
      if ($catalog === NULL) {
        // Request failed — leave whatever is stored alone rather than letting
        // one transient outage wipe the list until the next weekly run.
        continue;
      }

      $value = $catalog ? json_encode($this->buildPayload($catalog)) : NULL;
      if ($this->saveIfChanged($node, $value)) {
        $updated++;
      }
    }

    $this->logger->notice('SDS software cache: updated @count resources.', [
      '@count' => $updated,
    ]);
  }

  /**
   * Write the field only when the value actually changes.
   *
   * The bundle is not revisioned, so this guards against pointless saves,
   * re-indexing and cache invalidation rather than revision churn.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $node
   *   The resource node.
   * @param string|null $value
   *   Encoded payload, or NULL to clear the field.
   *
   * @return bool
   *   TRUE if the node was saved.
   */
  protected function saveIfChanged(FieldableEntityInterface $node, ?string $value): bool {
    $current = $node->get('field_rp_sds_software')->value;
    if (($current ?: NULL) === ($value ?: NULL)) {
      return FALSE;
    }
    $node->set('field_rp_sds_software', $value);
    $node->save();
    return TRUE;
  }

}
