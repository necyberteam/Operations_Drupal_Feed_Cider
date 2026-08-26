<?php

namespace Drupal\operations_cider\Entity;

use Drupal\node\Entity\Node;

/**
 * Bundle class for access_active_resources_from_cid resource nodes.
 *
 * Overrides label() to return the resolved display name
 * (field_rp_display_name -> field_cider_short_name -> title) on read, so every
 * consumer that goes through label() (H1, og:title, breadcrumbs, entity-ref
 * autocompletes, entity_reference_label, readmore) shows the display name
 * without mutating the stored title. Read-only: no revert bug.
 */
class CiderResourceNode extends Node {

  /**
   * {@inheritdoc}
   */
  public function label() {
    return operations_cider_resource_display_name($this);
  }

}
