<?php
/**
 * ProductPlanResolver - resolves which selling plans apply to a product and
 * bridges the result through the engine's product-plans filter.
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog;

use WC_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Product plan resolver.
 *
 * Applicability lives on parent products: a variation id resolves to its
 * parent before the meta is read. Plans come back in the repository's default
 * order (sort_order, oldest id as tiebreaker).
 */
final class ProductPlanResolver {

	/**
	 * Filter over the plans resolved for a product, with
	 * `( $plans, $product_id, $extension_slug )`. Consumers may remove or
	 * append plans; non-Plan entries are discarded after the filter runs.
	 */
	public const PRODUCT_PLANS_FILTER = 'woocommerce_subscriptions_engine_product_plans';

	/**
	 * Query limit for plan lookups; high enough that a plan catalog is never
	 * truncated by the repository's default of 50.
	 *
	 * @var int
	 */
	private const PLAN_QUERY_LIMIT = 200;

	/**
	 * Applicability meta store.
	 *
	 * @var ProductApplicabilityStore
	 */
	private $store;

	/**
	 * Plans repository.
	 *
	 * @var PlanRepository
	 */
	private $plan_repository;

	/**
	 * Construct the resolver.
	 *
	 * @param ProductApplicabilityStore|null $store           Applicability meta store.
	 * @param PlanRepository|null            $plan_repository Plans repository.
	 */
	public function __construct( ?ProductApplicabilityStore $store = null, ?PlanRepository $plan_repository = null ) {
		$this->store           = $store ?? new ProductApplicabilityStore();
		$this->plan_repository = $plan_repository ?? new PlanRepository();
	}

	/**
	 * Resolve the active plans applying to a product for one extension.
	 *
	 * An unknown product resolves to no plans. Otherwise the parent product's
	 * applicability mode drives the lookup: 'disable' yields no plans,
	 * 'inherit_all' every active plan for the slug, 'inherit_select' the
	 * active plans in the attached groups (an empty selection short-circuits
	 * to no plans without running the filter).
	 *
	 * @param int    $product_id     Product (or variation) id.
	 * @param string $extension_slug Extension slug scope.
	 * @return array<int, Plan>
	 */
	public function for_product( int $product_id, string $extension_slug ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$parent_id     = (int) $product->get_parent_id();
		$applicability = $this->store->get( $parent_id > 0 ? $parent_id : $product_id );

		$plans = array();
		if ( ProductApplicability::MODE_INHERIT_ALL === $applicability->get_mode() ) {
			$plans = $this->plan_repository->query(
				array(
					'status'         => Plan::STATUS_ACTIVE,
					'extension_slug' => $extension_slug,
					'limit'          => self::PLAN_QUERY_LIMIT,
				)
			);
		} elseif ( ProductApplicability::MODE_INHERIT_SELECT === $applicability->get_mode() ) {
			$group_ids = $applicability->get_group_ids();
			if ( array() === $group_ids ) {
				return array();
			}

			$plans = $this->plan_repository->query(
				array(
					'status'         => Plan::STATUS_ACTIVE,
					'extension_slug' => $extension_slug,
					'group_ids'      => $group_ids,
					'limit'          => self::PLAN_QUERY_LIMIT,
				)
			);
		}

		/**
		 * Filters the plans resolved for a product.
		 *
		 * The eligibility extension point over the resolved set: consumers may
		 * remove or append plans. Entries that are not Plan instances are
		 * discarded after the filter runs.
		 *
		 * @param array<int, Plan> $plans          Resolved plans, in display order.
		 * @param int              $product_id     The id the caller asked about (a variation id is passed as-is).
		 * @param string           $extension_slug Extension slug scope.
		 */
		$plans = apply_filters( self::PRODUCT_PLANS_FILTER, $plans, $product_id, $extension_slug );

		return array_values(
			array_filter(
				is_array( $plans ) ? $plans : array(),
				static function ( $plan ): bool {
					return $plan instanceof Plan;
				}
			)
		);
	}
}
