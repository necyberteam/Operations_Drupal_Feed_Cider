<?php

namespace Drupal\operations_cider\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Checks which resources have software listed in the SDS.
 *
 * Stores a map of node IDs to booleans in Drupal state, updated by cron.
 * The template reads this to decide whether to link to SDS or show
 * a "not currently reporting" message.
 */
class SdsAvailabilityService {

  const SDS_API_BASE = 'https://sds-ara-api.access-ci.org/api/v1';

  protected ClientInterface $httpClient;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected $logger;
  protected KeyRepositoryInterface $keyRepository;
  protected StateInterface $state;

  public function __construct(
    ClientInterface $http_client,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    KeyRepositoryInterface $key_repository,
    StateInterface $state,
  ) {
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('operations_cider');
    $this->keyRepository = $key_repository;
    $this->state = $state;
  }

  /**
   * Check all resources against SDS and update state.
   */
  public function updateAll(): void {
    $api_key = $this->keyRepository->getKey('sds_api')?->getKeyValue();
    if (!$api_key) {
      $this->logger->error('SDS API key not configured (key: sds_api).');
      return;
    }

    $nodes = $this->loadResourceNodes();
    $sds_available = [];

    // Group by RP group ID (same logic as TopSoftwareService).
    $groups = [];
    foreach ($nodes as $node) {
      $global_id = $node->get('field_access_global_resource_id')->value;
      if (!$global_id) {
        continue;
      }
      $group = explode('.', $global_id)[0];
      $group = preg_replace('/-(?:gpu|cpu|ai|storage|em|rm|lm|ps|ocean)$/', '', $group);
      $groups[$group][] = $node;
    }

    foreach ($groups as $group => $group_nodes) {
      // Try each node's global ID until one returns SDS data.
      // Sub-resources like expanse-ps may not have data while expanse-gpu does.
      $has_data = FALSE;
      foreach ($group_nodes as $node) {
        $query_id = $node->get('field_access_global_resource_id')->value;
        if ($this->checkSdsHasData($query_id, $api_key)) {
          $has_data = TRUE;
          break;
        }
      }

      foreach ($group_nodes as $node) {
        $sds_available[(int) $node->id()] = $has_data;
      }
    }

    $this->state->set('operations_cider.sds_available', $sds_available);
    $count = count(array_filter($sds_available));
    $this->logger->notice('SDS availability check: @count resources have software data.', [
      '@count' => $count,
    ]);
  }

  /**
   * Check if SDS has any software data for a resource.
   */
  protected function checkSdsHasData(string $resource_id, string $api_key): bool {
    try {
      $response = $this->httpClient->request('POST', self::SDS_API_BASE, [
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
        ],
        'json' => [
          'rps' => [$resource_id],
        ],
        'timeout' => 15,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      return FALSE;
    }

    $items = $body['data'] ?? [];
    return is_array($items) && !empty($items);
  }

  /**
   * Load all published CiDeR resource nodes.
   */
  protected function loadResourceNodes(): array {
    $nids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'access_active_resources_from_cid')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    return $nids
      ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids)
      : [];
  }

}
