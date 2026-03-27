<?php

namespace Drupal\operations_cider\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * REST endpoints for resource provider documentation.
 *
 * Public JSON endpoints for content syndication to Elastic search
 * and the UKY RAG pipeline.
 */
class ResourceDocumentationController extends ControllerBase {

  /**
   * GET /api/resources — list all documented resources.
   */
  public function listResources() {
    $query = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'access_active_resources_from_cid')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('title');

    $nids = $query->execute();
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($nids);

    $resources = [];
    foreach ($nodes as $node) {
      $resources[] = [
        'nid' => (int) $node->id(),
        'title' => $node->getTitle(),
        'resource_id' => $node->get('field_cider_resource_id')->value,
        'global_resource_id' => $node->get('field_access_global_resource_id')->value,
        'org_name' => $node->get('field_access_org_name')->value,
        'status' => $node->get('field_cider_latest_status')->value,
        'resource_type' => $node->get('field_cider_resource_type')->value,
        'has_documentation' => !$node->get('field_rp_description')->isEmpty(),
        'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ];
    }

    return new JsonResponse([
      'count' => count($resources),
      'resources' => $resources,
    ]);
  }

  /**
   * GET /api/resources/{resource_id} — full resource detail.
   */
  public function getResource(string $resource_id) {
    $query = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'access_active_resources_from_cid')
      ->condition('field_cider_resource_id', $resource_id)
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->range(0, 1);

    $nids = $query->execute();

    if (empty($nids)) {
      return new JsonResponse(['error' => 'Resource not found'], 404);
    }

    $node = $this->entityTypeManager()->getStorage('node')->load(reset($nids));

    $data = [
      'nid' => (int) $node->id(),
      'title' => $node->getTitle(),
      'resource_id' => $node->get('field_cider_resource_id')->value,
      'global_resource_id' => $node->get('field_access_global_resource_id')->value,
      'resource_type' => $node->get('field_cider_resource_type')->value,
      'org_name' => $node->get('field_access_org_name')->value,
      'org_url' => $this->getLinkValue($node, 'field_access_org_url'),
      'status' => $node->get('field_cider_latest_status')->value,
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'description' => $node->get('field_rp_description')->value,
      'mfa_required' => (bool) $node->get('field_rp_mfa_required')->value,
      'account_required' => (bool) $node->get('field_rp_account_required')->value,
      'ondemand_url' => $this->getLinkValue($node, 'field_rp_ondemand_url'),
      'office_hours' => $node->get('field_rp_office_hours')->value,
      'external_storage' => $node->get('field_rp_external_storage')->value,
      'ssh_logins' => $this->getParagraphData($node, 'field_rp_ssh_login_nodes', [
        'field_rp_ssh_url' => 'url',
        'field_rp_recommended' => 'recommended',
      ]),
      'login_help_links' => $this->getMultiLinkValues($node, 'field_rp_login_help_links'),
      'account_setup_url' => $this->getLinkValue($node, 'field_rp_account_setup_url'),
      'support_links' => $this->getMultiLinkValues($node, 'field_rp_support_links'),
      'useful_links' => $this->getMultiLinkValues($node, 'field_rp_useful_links'),
      'file_transfer' => $this->getParagraphData($node, 'field_rp_file_transfer', [
        'field_rp_method' => 'method',
        'field_rp_transfer_node' => 'transfer_node',
        'field_rp_transfer_url' => 'transfer_url',
        'field_rp_recommended' => 'recommended',
      ]),
      'storage' => $this->getParagraphData($node, 'field_rp_storage', [
        'field_rp_directory' => 'directory',
        'field_rp_fs_path' => 'path',
        'field_rp_quota' => 'quota',
        'field_rp_purge' => 'purge',
        'field_rp_backup' => 'backup',
        'field_rp_fs_notes' => 'notes',
      ]),
      'queue_specs' => $this->getParagraphData($node, 'field_rp_queue_specs', [
        'field_rp_queue_name' => 'name',
        'field_rp_queue_purpose' => 'purpose',
        'field_rp_cpus' => 'cpus',
        'field_rp_gpus' => 'gpus',
        'field_rp_ram' => 'ram',
        'field_rp_queue_nodes' => 'nodes',
      ]),
      'datasets' => $this->getParagraphData($node, 'field_rp_datasets', [
        'field_rp_dataset_name' => 'name',
        'field_rp_dataset_description' => 'description',
      ]),
    ];

    // Include top software if available.
    $top_software = $node->get('field_rp_top_software')->value;
    if ($top_software) {
      $decoded = json_decode($top_software, TRUE);
      $data['top_software'] = is_array($decoded) ? $decoded : [];
    }
    else {
      $data['top_software'] = [];
    }

    return new JsonResponse($data);
  }

  /**
   * Extract a single link field value.
   */
  private function getLinkValue($node, string $field_name): ?string {
    if ($node->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = $node->get($field_name)->first();
    return $value ? $value->getUrl()->toString() : NULL;
  }

  /**
   * Extract multi-value link field values.
   */
  private function getMultiLinkValues($node, string $field_name): array {
    $links = [];
    foreach ($node->get($field_name) as $item) {
      try {
        $url = $item->getUrl()->toString();
      }
      catch (\Exception $e) {
        // Fall back to raw URI if URL generation fails (e.g. external URIs).
        $url = $item->uri;
      }
      $links[] = [
        'title' => $item->title,
        'url' => $url,
      ];
    }
    return $links;
  }

  /**
   * Extract paragraph reference field data into flat arrays.
   */
  private function getParagraphData($node, string $field_name, array $field_map): array {
    $items = [];
    $paragraph_storage = $this->entityTypeManager()->getStorage('paragraph');
    foreach ($node->get($field_name) as $ref) {
      $paragraph = $paragraph_storage->loadRevision($ref->target_revision_id);
      if (!$paragraph) {
        continue;
      }
      $row = [];
      foreach ($field_map as $paragraph_field => $key) {
        if ($paragraph->hasField($paragraph_field)) {
          $field = $paragraph->get($paragraph_field);
          if ($field->getFieldDefinition()->getType() === 'boolean') {
            $row[$key] = (bool) $field->value;
          }
          elseif ($field->getFieldDefinition()->getType() === 'link') {
            $row[$key] = $field->isEmpty() ? NULL : $field->first()->getUrl()->toString();
          }
          else {
            $row[$key] = $field->value;
          }
        }
      }
      if (array_filter($row, fn($v) => $v !== NULL && $v !== '')) {
        $items[] = $row;
      }
    }
    return $items;
  }

}
