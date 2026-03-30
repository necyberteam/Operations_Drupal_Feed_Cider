<?php

namespace Drupal\operations_cider\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drush\Commands\DrushCommands;

/**
 * Drush command to scaffold RP documentation test data.
 */
class RpDocsScaffoldCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new RpDocsScaffoldCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Create sample RP documentation data for testing.
   *
   * Creates 3 test resources: Alpha (full), Beta (minimal), Gamma (partial).
   * Idempotent — updates existing nodes if they exist.
   *
   * @command rp-docs:scaffold
   * @aliases rp-scaffold
   */
  public function scaffold() {
    $this->createAlpha();
    $this->createBeta();
    $this->createGamma();
    $this->logger()->success('RP documentation test data scaffolded.');
  }

  /**
   * Remove all test scaffold data.
   *
   * @command rp-docs:scaffold-cleanup
   * @aliases rp-cleanup
   */
  public function cleanup() {
    foreach (['test-alpha-9999', 'test-beta-9998', 'test-gamma-9997'] as $resource_id) {
      $node = $this->findByResourceId($resource_id);
      if ($node) {
        $node->delete();
        $this->logger()->notice("Deleted test resource: $resource_id");
      }
    }
    $this->logger()->success('Test data cleaned up.');
  }

  /**
   * Test Resource Alpha — GPU resource with full documentation.
   */
  private function createAlpha() {
    $resource_id = 'test-alpha-9999';
    $node = $this->findByResourceId($resource_id);

    $ssh_logins = [
      $this->createParagraph('rp_ssh_login', [
        'field_rp_ssh_url' => [
          'uri' => 'https://login01.alpha.test.example.edu',
          'title' => 'login01',
        ],
        'field_rp_recommended' => TRUE,
      ]),
      $this->createParagraph('rp_ssh_login', [
        'field_rp_ssh_url' => [
          'uri' => 'https://login02.alpha.test.example.edu',
          'title' => 'login02',
        ],
        'field_rp_recommended' => FALSE,
      ]),
      $this->createParagraph('rp_ssh_login', [
        'field_rp_ssh_url' => [
          'uri' => 'https://login03.alpha.test.example.edu',
          'title' => 'login03',
        ],
        'field_rp_recommended' => FALSE,
      ]),
    ];

    $file_transfers = [
      $this->createParagraph('rp_file_transfer_method', [
        'field_rp_method' => 'Globus',
        'field_rp_transfer_node' => 'dt.alpha.example.edu',
        'field_rp_transfer_url' => ['uri' => 'https://app.globus.org', 'title' => 'Globus'],
        'field_rp_recommended' => TRUE,
      ]),
      $this->createParagraph('rp_file_transfer_method', [
        'field_rp_method' => 'SCP',
        'field_rp_transfer_node' => 'dt.alpha.example.edu',
        'field_rp_transfer_url' => [],
        'field_rp_recommended' => FALSE,
      ]),
      $this->createParagraph('rp_file_transfer_method', [
        'field_rp_method' => 'RSYNC',
        'field_rp_transfer_node' => 'dt.alpha.example.edu',
        'field_rp_transfer_url' => [],
        'field_rp_recommended' => FALSE,
      ]),
    ];

    $storage = [
      $this->createParagraph('rp_storage_filesystem', [
        'field_rp_directory' => 'Home',
        'field_rp_fs_path' => '/home/<username>',
        'field_rp_quota' => '25 GB',
        'field_rp_purge' => 'None',
        'field_rp_backup' => 'Daily snapshots',
        'field_rp_fs_notes' => 'Default login directory',
      ]),
      $this->createParagraph('rp_storage_filesystem', [
        'field_rp_directory' => 'Project',
        'field_rp_fs_path' => '/project/<username>',
        'field_rp_quota' => '10 TB',
        'field_rp_purge' => '30 days',
        'field_rp_backup' => 'None',
        'field_rp_fs_notes' => '',
      ]),
      $this->createParagraph('rp_storage_filesystem', [
        'field_rp_directory' => 'Scratch',
        'field_rp_fs_path' => '/scratch/<username>',
        'field_rp_quota' => '25 GB',
        'field_rp_purge' => 'None',
        'field_rp_backup' => 'None',
        'field_rp_fs_notes' => '',
      ]),
      $this->createParagraph('rp_storage_filesystem', [
        'field_rp_directory' => 'Temp',
        'field_rp_fs_path' => '/tmp/<username>',
        'field_rp_quota' => '10 TB',
        'field_rp_purge' => '30 days',
        'field_rp_backup' => 'None',
        'field_rp_fs_notes' => '',
      ]),
    ];

    $queues = [
      $this->createParagraph('rp_queue_spec', [
        'field_rp_queue_name' => 'gpu-standard',
        'field_rp_queue_purpose' => 'General purpose GPU jobs with up to 4 A100 GPUs per node.',
        'field_rp_cpus' => '64',
        'field_rp_gpus' => '4',
        'field_rp_ram' => '256 GB',
        'field_rp_queue_nodes' => '100',
      ]),
      $this->createParagraph('rp_queue_spec', [
        'field_rp_queue_name' => 'gpu-large',
        'field_rp_queue_purpose' => 'Large-scale multi-node GPU jobs requiring 8+ GPUs.',
        'field_rp_cpus' => '128',
        'field_rp_gpus' => '8',
        'field_rp_ram' => '512 GB',
        'field_rp_queue_nodes' => '20',
      ]),
      $this->createParagraph('rp_queue_spec', [
        'field_rp_queue_name' => 'cpu-shared',
        'field_rp_queue_purpose' => 'CPU-only shared partition for serial and small parallel jobs.',
        'field_rp_cpus' => '128',
        'field_rp_gpus' => '0',
        'field_rp_ram' => '256 GB',
        'field_rp_queue_nodes' => '200',
      ]),
    ];

    $datasets = [
      $this->createParagraph('rp_dataset', [
        'field_rp_dataset_name' => 'ImageNet-1K',
        'field_rp_dataset_description' => [
          'value' => '<p>Large-scale image classification dataset with 1.2M training images across 1,000 categories. Pre-downloaded and cached on the shared filesystem for fast access.</p>',
          'format' => 'full_html',
        ],
      ]),
      $this->createParagraph('rp_dataset', [
        'field_rp_dataset_name' => 'Common Crawl (2024)',
        'field_rp_dataset_description' => [
          'value' => '<p>Web crawl corpus for NLP and information retrieval research. Updated quarterly. Located at /datasets/common-crawl/.</p>',
          'format' => 'full_html',
        ],
      ]),
    ];

    $top_software = json_encode($this->getAlphaSoftwareList());

    $values = [
      'type' => 'access_active_resources_from_cid',
      'title' => 'Test Resource Alpha',
      'status' => 1,
      'field_cider_resource_id' => $resource_id,
      'field_access_global_resource_id' => 'alpha.test.access-ci.org',
      'field_cider_resource_type' => 'Compute',
      'field_cider_latest_status' => 'production',
      'field_access_org_name' => 'Test University',
      'field_rp_description' => [
        'value' => '<p>Test Resource Alpha is a powerful GPU-accelerated '
        . 'supercomputer funded by a $10 million NSF grant, designed '
        . 'for a broad range of scientific and engineering workloads '
        . 'from traditional HPC simulation to AI and machine learning '
        . 'research.</p><p>Researchers can access Alpha through '
        . 'standard HPC interfaces including Open OnDemand (OOD) and '
        . 'JupyterHub, enabling both command-line and browser-based '
        . 'workflows.</p>',
        'format' => 'full_html',
      ],
      'field_rp_ondemand_url' => [
        'uri' => 'https://ondemand.alpha.test.example.edu',
        'title' => 'ACCESS OnDemand',
      ],
      'field_rp_mfa_required' => TRUE,
      'field_rp_account_required' => TRUE,
      'field_rp_login_help_links' => [
        [
          'uri' => 'https://access-ci.org/guides/ssh-keys',
          'title' => 'Watch video: INTRODUCTION TO SSH KEYS',
        ],
      ],
      'field_rp_account_setup_url' => [
        'uri' => 'https://alpha.test.example.edu/account',
        'title' => 'Set up your Alpha account',
      ],
      'field_rp_support_links' => [
        [
          'uri' => 'https://alpha.test.example.edu/docs',
          'title' => 'User Guide',
        ],
        [
          'uri' => 'https://support.access-ci.org',
          'title' => 'Ticket System',
        ],
        [
          'uri' => 'https://alpha.test.example.edu/slack',
          'title' => 'Slack',
        ],
      ],
      'field_rp_office_hours' => [
        'uri' => 'https://alpha.test.example.edu/office-hours',
        'title' => 'Mon 2-4 PM EST',
      ],
      'field_rp_external_storage' => [
        'value' => '<h4>GETTING MORE STORAGE</h4><p>Users who need '
        . 'additional storage beyond the default quotas can request '
        . 'an allocation supplement through the ACCESS allocations '
        . 'portal.</p><h4>INTEGRATION WITH EXTERNAL STORAGE SYSTEM'
        . '</h4><p>Alpha supports Globus integration with campus '
        . 'storage systems, AWS S3, and Google Cloud Storage for '
        . 'seamless data transfer between institutional and cloud '
        . 'resources.</p>',
        'format' => 'full_html',
      ],
      'field_rp_top_software' => $top_software,
    ];

    if ($node) {
      $this->clearParagraphs($node, [
        'field_rp_ssh_login_nodes',
        'field_rp_file_transfer',
        'field_rp_storage',
        'field_rp_queue_specs',
        'field_rp_datasets',
      ]);
      foreach ($values as $key => $value) {
        if ($key !== 'type') {
          $node->set($key, $value);
        }
      }
      $node->save();
    }
    else {
      $node = Node::create($values);
      $node->save();
    }

    // Attach paragraphs after node exists (so parent info is set correctly).
    $this->attachParagraphs($node, 'field_rp_ssh_login_nodes', $ssh_logins);
    $this->attachParagraphs($node, 'field_rp_file_transfer', $file_transfers);
    $this->attachParagraphs($node, 'field_rp_storage', $storage);
    $this->attachParagraphs($node, 'field_rp_queue_specs', $queues);
    $this->attachParagraphs($node, 'field_rp_datasets', $datasets);
    $node->save();
    $this->logger()->notice(($node->isNew() ? 'Created' : 'Updated') . ' Test Resource Alpha (nid: ' . $node->id() . ')');
  }

  /**
   * Test Resource Beta — CPU resource with minimal data.
   */
  private function createBeta() {
    $resource_id = 'test-beta-9998';
    $node = $this->findByResourceId($resource_id);

    $values = [
      'type' => 'access_active_resources_from_cid',
      'title' => 'Test Resource Beta',
      'status' => 1,
      'field_cider_resource_id' => $resource_id,
      'field_access_global_resource_id' => 'beta.test.access-ci.org',
      'field_cider_resource_type' => 'Compute',
      'field_cider_latest_status' => 'production',
      'field_access_org_name' => 'Beta State University',
      'field_rp_description' => [
        'value' => '<p>Test Resource Beta is a CPU-only cluster for '
        . 'general-purpose computing workloads. This test resource '
        . 'has minimal documentation to verify that the template '
        . 'handles sparse data gracefully.</p>',
        'format' => 'full_html',
      ],
    ];

    if ($node) {
      foreach ($values as $key => $value) {
        if ($key !== 'type') {
          $node->set($key, $value);
        }
      }
      $node->save();
      $this->logger()->notice('Updated Test Resource Beta');
    }
    else {
      $node = Node::create($values);
      $node->save();
      $this->logger()->notice('Created Test Resource Beta (nid: ' . $node->id() . ')');
    }
  }

  /**
   * Test Resource Gamma — mixed resource with partial data.
   */
  private function createGamma() {
    $resource_id = 'test-gamma-9997';
    $node = $this->findByResourceId($resource_id);

    $storage = [
      $this->createParagraph('rp_storage_filesystem', [
        'field_rp_directory' => 'Home',
        'field_rp_fs_path' => '/home/<username>',
        'field_rp_quota' => '50 GB',
        'field_rp_purge' => 'None',
        'field_rp_backup' => 'Weekly',
        'field_rp_fs_notes' => '',
      ]),
      $this->createParagraph('rp_storage_filesystem', [
        'field_rp_directory' => 'Scratch',
        'field_rp_fs_path' => '/scratch/<username>',
        'field_rp_quota' => '5 TB',
        'field_rp_purge' => '60 days',
        'field_rp_backup' => 'None',
        'field_rp_fs_notes' => 'Auto-purge after 60 days of inactivity',
      ]),
    ];

    $values = [
      'type' => 'access_active_resources_from_cid',
      'title' => 'Test Resource Gamma',
      'status' => 1,
      'field_cider_resource_id' => $resource_id,
      'field_access_global_resource_id' => 'gamma.test.access-ci.org',
      'field_cider_resource_type' => 'Compute',
      'field_cider_latest_status' => 'production',
      'field_access_org_name' => 'Gamma Research Institute',
      'field_rp_description' => [
        'value' => '<p>Test Resource Gamma is a mixed-use cluster with '
        . 'partial documentation. It has storage information but no '
        . 'login, file transfer, or queue data — useful for testing '
        . 'how the template renders when some sections are empty.</p>',
        'format' => 'full_html',
      ],
      'field_rp_mfa_required' => TRUE,
      'field_rp_account_required' => FALSE,
      'field_rp_support_links' => [
        [
          'uri' => 'https://gamma.test.example.edu/support',
          'title' => 'Support Portal',
        ],
      ],
      'field_rp_office_hours' => [
        'uri' => 'https://gamma.test.example.edu/office-hours',
        'title' => 'Tue/Thu 10 AM - 12 PM CST',
      ],
    ];

    if ($node) {
      $this->clearParagraphs($node, ['field_rp_storage']);
      foreach ($values as $key => $value) {
        if ($key !== 'type') {
          $node->set($key, $value);
        }
      }
      $node->save();
    }
    else {
      $node = Node::create($values);
      $node->save();
    }

    $this->attachParagraphs($node, 'field_rp_storage', $storage);
    $node->save();
    $this->logger()->notice(($node->isNew() ? 'Created' : 'Updated') . ' Test Resource Gamma (nid: ' . $node->id() . ')');
  }

  /**
   * Return the top software fixture list for Alpha.
   *
   * @return array
   *   Array of software entries with name, version, description, discipline.
   */
  private function getAlphaSoftwareList(): array {
    return [
      [
        'name' => 'AlphaFold',
        'version' => '2.3.2',
        'description' => 'AlphaFold, the state-of-the-art AI '
        . 'system developed by DeepMind, is able to '
        . 'computationally predict protein structures with '
        . 'unprecedented accuracy.',
        'discipline' => 'Bioinformatics, Biological Sciences',
      ],
      [
        'name' => 'GROMACS',
        'version' => '2024.1',
        'description' => 'A versatile package to perform '
        . 'molecular dynamics, i.e. simulate the Newtonian '
        . 'equations of motion for systems with hundreds to '
        . 'millions of particles.',
        'discipline' => 'Chemistry, Molecular Dynamics',
      ],
      [
        'name' => 'PyTorch',
        'version' => '2.2.0',
        'description' => 'An open source machine learning '
        . 'framework that accelerates the path from research '
        . 'prototyping to production deployment.',
        'discipline' => 'Computer Science, Machine Learning',
      ],
      [
        'name' => 'VASP',
        'version' => '6.4.2',
        'description' => 'The Vienna Ab initio Simulation '
        . 'Package for atomic scale materials modelling, e.g. '
        . 'electronic structure calculations and '
        . 'quantum-mechanical molecular dynamics.',
        'discipline' => 'Materials Science, Physics',
      ],
      [
        'name' => 'Jupyter',
        'version' => '4.0.11',
        'description' => 'JupyterLab is the latest web-based '
        . 'interactive development environment for notebooks, '
        . 'code, and data.',
        'discipline' => 'General Purpose',
      ],
    ];
  }

  /**
   * Find a resource node by field_cider_resource_id.
   */
  private function findByResourceId(string $resource_id): ?Node {
    $nids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'access_active_resources_from_cid')
      ->condition('field_cider_resource_id', $resource_id)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (empty($nids)) {
      return NULL;
    }

    return Node::load(reset($nids));
  }

  /**
   * Create a paragraph entity (unsaved).
   */
  private function createParagraph(string $type, array $fields): Paragraph {
    $values = ['type' => $type];
    foreach ($fields as $field_name => $value) {
      $values[$field_name] = $value;
    }
    return Paragraph::create($values);
  }

  /**
   * Attach paragraphs to a node field with proper parent info, then save.
   */
  private function attachParagraphs(Node $node, string $field_name, array $paragraphs): void {
    $refs = [];
    foreach ($paragraphs as $paragraph) {
      $paragraph->setParentEntity($node, $field_name);
      $paragraph->save();
      $refs[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }
    $node->set($field_name, $refs);
  }

  /**
   * Clear paragraph references from a node before updating.
   */
  private function clearParagraphs(Node $node, array $field_names): void {
    foreach ($field_names as $field_name) {
      if (!$node->hasField($field_name)) {
        continue;
      }
      foreach ($node->get($field_name) as $ref) {
        $paragraph = $ref->entity;
        if ($paragraph) {
          $paragraph->delete();
        }
      }
      $node->set($field_name, []);
    }
  }

}
