<?php
/**
 * SellingPlans - the engine's public catalog facade.
 *
 * The one surface consumers import for selling-plan applicability: resolve
 * which plans apply to a product, read and write a product's applicability,
 * and list an extension's active plans for selection UIs. It hides the
 * internal `Core\` / `Integration\` collaborators (the applicability store,
 * the resolver, the repositories) behind a stable boundary, so the internals
 * stay refactorable. Strictly additive-only, like every `Api\` surface.
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine\Api
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Api;

use InvalidArgumentException;
use WC_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog\ProductApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog\ProductPlanResolver;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Public selling-plans facade.
 *
 * Final and static-only: a stateless entry point, not an extension seam.
 * Applicability lives on parent products; the product types that may carry it
 * are simple and variable, and variation ids resolve to their parent on reads.
 */
final class SellingPlans {

	/**
	 * Product types that may carry applicability.
	 *
	 * @var array<int, string>
	 */
	private const APPLICABLE_PRODUCT_TYPES = array( 'simple', 'variable' );

	/**
	 * Query limit for plan lookups; high enough that a plan catalog is never
	 * truncated by the repository's default of 50.
	 *
	 * @var int
	 */
	private const PLAN_QUERY_LIMIT = 200;

	/**
	 * Resolve the active plans applying to a product for one extension.
	 *
	 * A variation id resolves to its parent's applicability. The result has
	 * passed the `woocommerce_subscriptions_engine_product_plans` filter.
	 *
	 * @param int    $product_id     Product (or variation) id.
	 * @param string $extension_slug Extension slug scope.
	 * @return array<int, Plan> Plans in display order.
	 */
	public static function for_product( int $product_id, string $extension_slug ): array {
		return ( new ProductPlanResolver() )->for_product( $product_id, $extension_slug );
	}

	/**
	 * Read a product's applicability. A variation id resolves to its parent;
	 * absent state yields the defaults (disable, one-time allowed).
	 *
	 * @param int $product_id Product (or variation) id.
	 */
	public static function get_product_applicability( int $product_id ): ProductApplicability {
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product && (int) $product->get_parent_id() > 0 ) {
			$product_id = (int) $product->get_parent_id();
		}

		return ( new ProductApplicabilityStore() )->get( $product_id );
	}

	/**
	 * Write a product's applicability.
	 *
	 * Validates before writing: the product must exist and be a simple or
	 * variable parent (variations and other product types are rejected), and
	 * under 'inherit_select' every plan id must exist and belong to the
	 * caller's extension slug. Nothing is written when validation fails.
	 *
	 * @param int                  $product_id     Parent product id.
	 * @param ProductApplicability $applicability  Applicability to persist.
	 * @param string               $extension_slug Extension slug of the caller.
	 * @throws InvalidArgumentException If the product is missing or not a simple/variable parent, or a plan id does not exist or belongs to another extension.
	 */
	public static function set_product_applicability( int $product_id, ProductApplicability $applicability, string $extension_slug ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			throw new InvalidArgumentException(
				esc_html( sprintf( 'SellingPlans: product %d does not exist.', $product_id ) )
			);
		}

		if ( ! in_array( $product->get_type(), self::APPLICABLE_PRODUCT_TYPES, true ) ) {
			throw new InvalidArgumentException(
				esc_html( sprintf( 'SellingPlans: product %d must be a simple or variable parent product, got "%s".', $product_id, $product->get_type() ) )
			);
		}

		if ( ProductApplicability::MODE_INHERIT_SELECT === $applicability->get_mode() ) {
			$plan_repository = new PlanRepository();
			foreach ( $applicability->get_plan_ids() as $plan_id ) {
				if ( null === $plan_repository->find( $plan_id, $extension_slug ) ) {
					throw new InvalidArgumentException(
						esc_html( sprintf( 'SellingPlans: plan %d does not exist for extension "%s".', $plan_id, $extension_slug ) )
					);
				}
			}
		}

		( new ProductApplicabilityStore() )->set( $product_id, $applicability );
	}

	/**
	 * List an extension's active plans in display order - the read behind a
	 * plan-selection UI.
	 *
	 * @param string $extension_slug Extension slug scope.
	 * @return array<int, Plan> Plans in display order.
	 */
	public static function list_plans( string $extension_slug ): array {
		return ( new PlanRepository() )->query(
			array(
				'status'         => Plan::STATUS_ACTIVE,
				'extension_slug' => $extension_slug,
				'limit'          => self::PLAN_QUERY_LIMIT,
			)
		);
	}
}
