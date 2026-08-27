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
 *
 * A parallel map, operations_cider.sds_rp_name, records the group name SDS
 * itself reports for each node — the only reliable slug for the SDS website's
 * ?installed_on= parameter.
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
    $rp_names = [];

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
      $rp_name = NULL;
      foreach ($group_nodes as $node) {
        $query_id = $node->get('field_access_global_resource_id')->value;
        $found = $this->fetchRpName($query_id, $api_key);
        if ($found !== NULL) {
          $has_data = TRUE;
          $rp_name = $found;
          break;
        }
      }

      foreach ($group_nodes as $node) {
        $sds_available[(int) $node->id()] = $has_data;
        // Groups where no member returns data get no entry, so the template
        // falls back to the slug derived from the group title.
        if ($rp_name !== NULL) {
          $rp_names[(int) $node->id()] = $rp_name;
        }
      }
    }

    $this->state->set('operations_cider.sds_available', $sds_available);
    // Keyed by node id exactly like sds_available, so the two stay in lockstep.
    $this->state->set('operations_cider.sds_rp_name', $rp_names);
    $count = count(array_filter($sds_available));
    $this->logger->notice('SDS availability check: @count resources have software data.', [
      '@count' => $count,
    ]);
  }

  /**
   * Fetch the SDS group name for a resource.
   *
   * SDS's website can only filter by the group slug, and the slug we would
   * otherwise derive from the group title is wrong on some groups (SDS calls
   * Bridges-2 "bridges-2", not "bridges2"). Every response carries the
   * authoritative name, so read it here rather than guessing.
   *
   * @param string $resource_id
   *   The resource's global CiDeR id.
   * @param string $api_key
   *   The SDS API key.
   *
   * @return string|null
   *   The group name SDS reports, or NULL when the request fails or SDS has
   *   no data for the resource.
   */
  protected function fetchRpName(string $resource_id, string $api_key): ?string {
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
      return NULL;
    }

    $items = $body['data'] ?? [];
    if (!is_array($items) || empty($items)) {
      return NULL;
    }

    foreach ($items as $item) {
      foreach ($item['rps'] ?? [] as $rp) {
        if (!empty($rp['rp_name'])) {
          return (string) $rp['rp_name'];
        }
      }
    }

    return NULL;
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
