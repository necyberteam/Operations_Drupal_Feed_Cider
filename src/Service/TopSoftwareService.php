<?php

namespace Drupal\operations_cider\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetches top software data from SDS and caches on resource nodes.
 */
class TopSoftwareService {

  /**
   * SDS ARA API base URL.
   */
  const SDS_API_BASE = 'https://sds-ara-api.access-ci.org/api/v1';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * Constructs a TopSoftwareService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   */
  public function __construct(
    ClientInterface $http_client,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    KeyRepositoryInterface $key_repository,
  ) {
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('operations_cider');
    $this->keyRepository = $key_repository;
  }

  /**
   * Fetch and cache top software for all resources.
   */
  public function updateAll(): void {
    $api_key = $this->keyRepository->getKey('sds_api')?->getKeyValue();
    if (!$api_key) {
      $this->logger->error('SDS API key not configured (key: sds_api).');
      return;
    }

    $nodes = $this->loadResourceNodes();

    // Group nodes by their RP group ID (first segment before the dot).
    // SDS works at RP group level, so anvil, anvil-gpu, anvil-ai all share
    // the same software list.
    $groups = [];
    foreach ($nodes as $node) {
      $global_id = $node->get('field_access_global_resource_id')->value;
      if (!$global_id) {
        continue;
      }
      $group = explode('.', $global_id)[0];
      // Remove known sub-resource suffixes (e.g., "anvil-gpu" → "anvil").
      // Update this list when new sub-resource types are added in CiDeR.
      $group = preg_replace('/-(?:gpu|cpu|ai|storage|em|rm|lm|ps|ocean)$/', '', $group);
      $groups[$group][] = $node;
    }

    $updated = 0;
    foreach ($groups as $group => $group_nodes) {
      // Use the first node's global_id to query SDS.
      $query_id = $group_nodes[0]
        ->get('field_access_global_resource_id')->value;
      $software = $this->fetchSoftware($query_id, $api_key);
      if ($software === NULL) {
        continue;
      }

      $json = json_encode($software);
      foreach ($group_nodes as $node) {
        $node->set('field_rp_top_software', $json);
        $node->save();
        $updated++;
      }
    }

    $this->logger->notice('Updated top software for @count resources.', [
      '@count' => $updated,
    ]);
  }

  /**
   * Fetch software list from SDS for a resource.
   *
   * @param string $resource_id
   *   The global resource ID (e.g., "anvil.purdue.access-ci.org").
   * @param string $api_key
   *   The SDS API key.
   *
   * @return array|null
   *   Array of software entries, or NULL on failure/no data.
   */
  protected function fetchSoftware(string $resource_id, string $api_key): ?array {
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
        'timeout' => 30,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      $this->logger->warning(
        'SDS API request failed for @resource: @message',
        ['@resource' => $resource_id, '@message' => $e->getMessage()]
      );
      return NULL;
    }

    $items = $body['data'] ?? [];
    if (!is_array($items) || empty($items)) {
      return NULL;
    }

    // Take the first 10 software packages.
    $software = [];
    foreach (array_slice($items, 0, 10) as $item) {
      $rp_data = $item['rps'][$resource_id] ?? [];
      $software[] = [
        'name' => $item['software_name'] ?? '',
        'versions' => $rp_data['software_versions'] ?? [],
        'description' => $item['ai_description']
          ?: $item['software_description']
          ?: '',
        'research_field' => $item['ai_research_field'] ?? '',
        'software_type' => $item['ai_software_type'] ?? '',
        'web_page' => $item['software_web_page'] ?? '',
        'documentation' => $item['software_documentation'] ?? '',
      ];
    }

    return $software ?: NULL;
  }

  /**
   * Load all published CiDeR resource nodes.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Array of resource nodes.
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
