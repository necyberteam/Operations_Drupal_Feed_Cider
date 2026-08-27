<?php

namespace Drupal\operations_cider\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Shared SDS metadata enrichment: fetch catalogs, match names, map fields.
 *
 * Extracted from TopSoftwareService so both top-software and OOD-software
 * population can enrich by software name against the SDS ARA API.
 */
class SdsEnrichmentService {

  /**
   * SDS ARA API base URL.
   */
  const SDS_API_BASE = 'https://sds-ara-api.access-ci.org/api/v1';

  /**
   * XDMoD→SDS name mappings for known discrepancies.
   */
  const NAME_ALIASES = [
    'q-espresso' => ['quantum-espresso', 'quantum_espresso'],
    'ncbi-blast' => ['blast-plus', 'blast+'],
    'r' => ['r-base', 'r-project'],
  ];

  protected ClientInterface $httpClient;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected $logger;
  protected KeyRepositoryInterface $keyRepository;

  /**
   * Constructs an SdsEnrichmentService.
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
   * SDS API key, or NULL if not configured.
   */
  protected function apiKey(): ?string {
    return $this->keyRepository->getKey('sds_api')?->getKeyValue();
  }

  /**
   * Catalog keyed by lowercase software name -> raw SDS item array.
   *
   * Queries SDS by software names (not resource-specific). Also includes
   * known aliases in the query.
   *
   * @param array $names
   *   Array of software names to look up.
   *
   * @return array
   *   SDS catalog keyed by lowercase software_name. Empty on failure.
   */
  public function fetchCatalogByNames(array $names): array {
    $api_key = $this->apiKey();
    if (!$api_key) {
      return [];
    }
    // Also include known aliases in the query.
    $query_names = $names;
    foreach ($names as $name) {
      $lower = strtolower($name);
      if (isset(self::NAME_ALIASES[$lower])) {
        $query_names = array_merge($query_names, self::NAME_ALIASES[$lower]);
      }
    }
    try {
      $response = $this->httpClient->request('POST', self::SDS_API_BASE, [
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
        ],
        'json' => ['software' => array_values(array_unique($query_names))],
        'timeout' => 30,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      if ($e->getCode() !== 404) {
        $this->logger->warning(
          'SDS name-based query failed: @message',
          ['@message' => $e->getMessage()]
        );
      }
      return [];
    }
    $catalog = [];
    foreach ($body['data'] ?? [] as $item) {
      $name = strtolower($item['software_name'] ?? '');
      if ($name) {
        $catalog[$name] = $item;
      }
    }
    return $catalog;
  }

  /**
   * Fetch the full SDS software catalog for a resource.
   *
   * @param string $global_resource_id
   *   The CiDeR global resource ID (e.g., "anvil.purdue.access-ci.org").
   *
   * @return array
   *   SDS catalog keyed by lowercase software_name. Empty on failure.
   */
  public function fetchCatalogByResource(string $global_resource_id): array {
    $api_key = $this->apiKey();
    if (!$api_key) {
      return [];
    }
    try {
      $response = $this->httpClient->request('POST', self::SDS_API_BASE, [
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
        ],
        'json' => [
          'rps' => [$global_resource_id],
        ],
        'timeout' => 30,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      // 404 just means SDS has no data for this resource — not an error.
      if ($e->getCode() !== 404) {
        $this->logger->warning(
          'SDS API request failed for @resource: @message',
          ['@resource' => $global_resource_id, '@message' => $e->getMessage()]
        );
      }
      return [];
    }

    // Index by lowercase name for case-insensitive lookup.
    $catalog = [];
    foreach ($body['data'] ?? [] as $item) {
      $name = strtolower($item['software_name'] ?? '');
      if ($name) {
        $catalog[$name] = $item;
      }
    }
    return $catalog;
  }

  /**
   * Fetch the SDS catalog for a resource, distinguishing failure from empty.
   *
   * The array-returning fetchCatalogByResource() gives back [] both when the
   * request fails and when SDS genuinely has no software for the RP. Callers
   * that cache the result need to tell those apart, or one transient outage
   * wipes every cached list.
   *
   * @param string $global_resource_id
   *   The CiDeR global resource ID (e.g., "anvil.purdue.access-ci.org").
   *
   * @return array<string, array<string, mixed>>|null
   *   SDS catalog keyed by lowercase software_name (possibly empty) on a
   *   successful response, or NULL when the request could not be completed.
   */
  public function fetchCatalogByResourceOrNull(string $global_resource_id): ?array {
    $api_key = $this->apiKey();
    if (!$api_key) {
      $this->logger->error('SDS API key not configured (key: sds_api).');
      return NULL;
    }
    try {
      $response = $this->httpClient->request('POST', self::SDS_API_BASE, [
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
        ],
        'json' => [
          'rps' => [$global_resource_id],
        ],
        'timeout' => 15,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      // 404 is a definitive "SDS has nothing for this resource", not a failure.
      if ($e->getCode() === 404) {
        return [];
      }
      $this->logger->warning(
        'SDS API request failed for @resource: @message',
        ['@resource' => $global_resource_id, '@message' => $e->getMessage()]
      );
      return NULL;
    }

    if (!is_array($body)) {
      $this->logger->warning(
        'SDS API returned an unparseable body for @resource.',
        ['@resource' => $global_resource_id]
      );
      return NULL;
    }

    // Index by lowercase name for case-insensitive lookup. Keying this way
    // preserves insertion order but collapses duplicate names.
    $catalog = [];
    foreach ($body['data'] ?? [] as $item) {
      $name = strtolower($item['software_name'] ?? '');
      if ($name) {
        $catalog[$name] = $item;
      }
    }
    return $catalog;
  }

  /**
   * Look up a software name in the SDS catalog.
   *
   * Tries exact match, then known aliases, then hyphen/underscore-normalized
   * match.
   *
   * @param string $name
   *   The software name to look up.
   * @param array $catalog
   *   SDS catalog keyed by lowercase software name.
   *
   * @return array|null
   *   Raw SDS item array, or NULL if not found.
   */
  public function findInSds(string $name, array $catalog): ?array {
    $lower = strtolower($name);

    // Exact match.
    if (isset($catalog[$lower])) {
      return $catalog[$lower];
    }

    // Check known aliases.
    if (isset(self::NAME_ALIASES[$lower])) {
      foreach (self::NAME_ALIASES[$lower] as $alias) {
        if (isset($catalog[$alias])) {
          return $catalog[$alias];
        }
      }
    }

    // Try matching with hyphens/underscores normalized.
    $normalized = str_replace(['-', '_'], '', $lower);
    foreach ($catalog as $sds_name => $sds_entry) {
      if (str_replace(['-', '_'], '', $sds_name) === $normalized) {
        return $sds_entry;
      }
    }

    return NULL;
  }

  /**
   * Map a raw SDS item to the software entry fields.
   *
   * @param array $sds_item
   *   Raw SDS item array.
   *
   * @return array
   *   Mapped fields: description, research_field, web_page, documentation.
   */
  public function mapSdsFields(array $sds_item): array {
    return [
      'description' => ($sds_item['ai_description'] ?? '') ?: ($sds_item['software_description'] ?? '') ?: '',
      'research_field' => $sds_item['ai_research_field'] ?? '',
      'web_page' => self::safeUrl($sds_item['software_web_page'] ?? ''),
      'documentation' => self::safeUrl($sds_item['software_documentation'] ?? ''),
    ];
  }

  /**
   * Keep a feed-supplied URL only when it carries an http(s) scheme.
   *
   * These values are stored and later emitted as link hrefs and over the
   * resources API, so anything that is not plainly an absolute web address --
   * javascript:, data:, a protocol-relative //host, a relative path -- is
   * dropped rather than passed through. Callers treat an empty string as
   * "no link".
   *
   * @param mixed $url
   *   The raw value from the SDS feed, or from editor-authored JSON.
   *
   * @return string
   *   The URL, or an empty string if it is missing or not http(s).
   */
  public static function safeUrl($url): string {
    if (!is_string($url)) {
      return '';
    }
    $url = trim($url);
    if ($url === '') {
      return '';
    }
    // parse_url() returns NULL for a scheme obfuscated with embedded control
    // characters, and for protocol-relative and relative URLs, so both the
    // dangerous and the merely unusable cases fall through to ''.
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return in_array(strtolower((string) $scheme), ['http', 'https'], TRUE) ? $url : '';
  }

}
