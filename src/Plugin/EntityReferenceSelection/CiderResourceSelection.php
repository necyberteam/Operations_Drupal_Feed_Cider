<?php

namespace Drupal\operations_cider\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\Plugin\EntityReferenceSelection\NodeSelection;

/**
 * Provides display-name-aware selection for CiDeR resource nodes.
 *
 * Entity-reference autocompletes on the CiDeR resource fields DISPLAY the
 * resolved display name (via the CiderResourceNode bundle-class label()
 * override: field_rp_display_name -> field_cider_short_name -> title), but
 * the core 'default:node' handler SEARCHES only the raw title column. An
 * editor typing the display name they see in the widget gets zero results.
 *
 * This handler matches on the same three columns the label falls back
 * through, so a search term matching any of the display name, short name,
 * or title returns the resource. It is only assigned to reference fields
 * that target the access_active_resources_from_cid bundle exclusively.
 */
#[EntityReferenceSelection(
  id: 'cider_resource',
  label: new TranslatableMarkup('CiDeR resource selection (display-name aware)'),
  group: 'cider_resource',
  weight: 1,
  entity_types: ['node'],
)]
class CiderResourceSelection extends NodeSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    // Pass NULL so the parent (and DefaultSelection::buildEntityQuery())
    // does not add its own single 'title' label condition; we add our own
    // OR condition group across all three name columns below.
    $query = parent::buildEntityQuery(NULL, $match_operator);

    if ($match !== NULL && $match !== '') {
      $group = $query->orConditionGroup()
        ->condition('title', $match, $match_operator)
        ->condition('field_cider_short_name', $match, $match_operator)
        ->condition('field_rp_display_name', $match, $match_operator);
      $query->condition($group);
    }

    return $query;
  }

}
