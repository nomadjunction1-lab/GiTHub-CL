<?php
/**
 * ProductApplicabilityStore - engine-owned product meta I/O for selling-plan
 * applicability.
 *
 * The three meta keys are the engine's data model for "does this product sell
 * on plans": mode and allow-one-time as single-value meta, attached plan ids
 * as one multi-value row per id (only written for 'inherit_select').
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog;

use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability;

defined( 'ABSPATH' ) || exit;

/**
 * Product applicability meta store.
 *
 * Operates on the product id it is given: parent-only enforcement and
 * variation-to-parent resolution live in the resolver and the facade, not here.
 */
final class ProductApplicabilityStore {

	public const META_APPLY_MODE = '_wc_selling_plans_apply_mode';

	public const META_PLAN_IDS = '_wc_selling_plans_plan_ids';

	public const META_ALLOW_ONE_TIME = '_wc_selling_plans_allow_one_time';

	/**
	 * Read a product's applicability. Absent meta yields the defaults
	 * (disable, one-time allowed).
	 *
	 * @param int $product_id Product id.
	 */
	public function get( int $product_id ): ProductApplicability {
		$plan_ids = get_post_meta( $product_id, self::META_PLAN_IDS, false );

		return ProductApplicability::from_storage(
			array(
				'mode'           => get_post_meta( $product_id, self::META_APPLY_MODE, true ),
				'plan_ids'       => is_array( $plan_ids ) ? $plan_ids : array(),
				'allow_one_time' => get_post_meta( $product_id, self::META_ALLOW_ONE_TIME, true ),
			)
		);
	}

	/**
	 * Write a product's applicability.
	 *
	 * Mode and the allow-one-time flag are single-value writes; the plan rows
	 * are reconciled to exactly the value object's plan ids - stale rows are
	 * deleted, missing ones added, one row per id. 'disable' and 'inherit_all'
	 * therefore end with zero attachment rows (all-mode is virtual).
	 *
	 * @param int                  $product_id    Product id.
	 * @param ProductApplicability $applicability Applicability to persist.
	 */
	public function set( int $product_id, ProductApplicability $applicability ): void {
		$data = $applicability->to_storage();

		update_post_meta( $product_id, self::META_APPLY_MODE, $data['mode'] );
		update_post_meta( $product_id, self::META_ALLOW_ONE_TIME, $data['allow_one_time'] );

		$wanted = $data['plan_ids'];

		$existing_rows = get_post_meta( $product_id, self::META_PLAN_IDS, false );
		$existing      = array();
		foreach ( is_array( $existing_rows ) ? $existing_rows : array() as $row ) {
			if ( is_scalar( $row ) ) {
				$existing[] = (int) $row;
			}
		}

		$row_counts = array_count_values( $existing );
		foreach ( $row_counts as $plan_id => $row_count ) {
			if ( ! in_array( $plan_id, $wanted, true ) ) {
				delete_post_meta( $product_id, self::META_PLAN_IDS, $plan_id );
			} elseif ( $row_count > 1 ) {
				// Collapse externally duplicated rows back to one row per id.
				delete_post_meta( $product_id, self::META_PLAN_IDS, $plan_id );
				add_post_meta( $product_id, self::META_PLAN_IDS, $plan_id );
			}
		}

		foreach ( $wanted as $plan_id ) {
			if ( ! isset( $row_counts[ $plan_id ] ) ) {
				add_post_meta( $product_id, self::META_PLAN_IDS, $plan_id );
			}
		}
	}
}
