<?php

/**
 * @file
 * Deploy hooks for operations_cider.
 *
 * Deploy hooks run after config:import, so they can reindex an index whose
 * fields were just added by a config import.
 */

use Drupal\search_api\Entity\Index;

/**
 * Reindex the default search index to populate field_rp_display_name.
 *
 * D8-2735/D8-2820 adds field_rp_display_name to the default index and removes
 * the node_load setTitle mutation, so the indexed `title` also changes from
 * the short name to the descriptive name. A reindex is required to pick both
 * up. reindex() only marks items for reindexing; cron drains the queue.
 */
function operations_cider_deploy_reindex_resource_display_name() {
  $index = Index::load('default');
  if (!$index) {
    return 'search_api index "default" not found; skipped reindex.';
  }
  $index->reindex();
  return 'Marked search index "default" for reindexing to pick up resource display name.';
}
