<?php

/**
 * @file
 * Post-update functions for operations_cider.
 *
 * post_update hooks run after `drush deploy`'s config import step, so we can
 * rely on new fields and feed mappings being in place before we touch data.
 */

/**
 * Backfill field_cider_short_name on existing CIDeR resource nodes.
 *
 * Triggers a synchronous re-import of the cider_active_resources_feed so the
 * new short_name mapping populates the new field for every existing node.
 */
function operations_cider_post_update_backfill_short_name(): string {
  $feedStorage = \Drupal::entityTypeManager()->getStorage('feeds_feed');
  $feeds = $feedStorage->loadByProperties(['type' => 'cider_active_resources_feed']);
  if (empty($feeds)) {
    return 'No cider_active_resources_feed feed found — nothing to backfill.';
  }

  $imported = 0;
  foreach ($feeds as $feed) {
    $feed->import();
    $imported++;
  }

  return "Re-imported {$imported} CIDeR feed(s) to backfill field_cider_short_name.";
}
