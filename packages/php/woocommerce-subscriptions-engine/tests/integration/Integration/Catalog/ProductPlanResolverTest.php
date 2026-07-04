<?php
/**
 * Integration tests for ProductPlanResolver.
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Tests\Integration\Integration\Catalog;

use EngineIntegrationTestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanGroup;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog\ProductApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog\ProductPlanResolver;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanGroupRepository;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog\ProductPlanResolver
 */
class ProductPlanResolverTest extends EngineIntegrationTestCase {

	private const SLUG = 'lite';

	public function tear_down(): void {
		remove_all_filters( ProductPlanResolver::PRODUCT_PLANS_FILTER );

		parent::tear_down();
	}

	private function make_group( string $merchant_code ): int {
		return ( new PlanGroupRepository() )->insert(
			PlanGroup::create(
				array(
					'name'           => 'Group ' . $merchant_code,
					'merchant_code'  => $merchant_code,
					'extension_slug' => self::SLUG,
				)
			)
		);
	}

	/**
	 * Insert a plan owned by the test slug.
	 *
	 * @param int                  $group_id  Parent group id.
	 * @param string               $name      Plan name.
	 * @param array<string, mixed> $overrides Attribute overrides.
	 */
	private function make_plan( int $group_id, string $name, array $overrides = array() ): int {
		return ( new PlanRepository() )->insert(
			Plan::create(
				$group_id,
				array_merge(
					array(
						'name'           => $name,
						'billing_policy' => BillingPolicy::from_array(
							array(
								'period'   => 'month',
								'interval' => 1,
							)
						),
						'extension_slug' => self::SLUG,
					),
					$overrides
				)
			)
		);
	}

	private function make_product(): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Coffee beans' );
		$product->set_regular_price( '24.00' );

		return (int) $product->save();
	}

	/**
	 * Map resolved plans to their ids.
	 *
	 * @param array<int, Plan> $plans Resolved plans.
	 * @return array<int, int|null>
	 */
	private static function plan_ids( array $plans ): array {
		return array_map(
			static function ( Plan $plan ): ?int {
				return $plan->get_id();
			},
			$plans
		);
	}

	public function test_unknown_product_resolves_to_no_plans(): void {
		$this->assertSame( array(), ( new ProductPlanResolver() )->for_product( 999999, self::SLUG ) );
	}

	public function test_disable_mode_resolves_to_no_plans(): void {
		$group_id   = $this->make_group( 'disable-group' );
		$product_id = $this->make_product();
		$this->make_plan( $group_id, 'Monthly' );

		// Fresh products default to disable; write it explicitly for the round trip.
		( new ProductApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_DISABLE ) );

		$this->assertSame( array(), ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG ) );
	}

	public function test_inherit_all_resolves_every_active_plan_for_the_slug(): void {
		$group_id   = $this->make_group( 'all-group' );
		$product_id = $this->make_product();

		$first_id  = $this->make_plan( $group_id, 'Monthly', array( 'sort_order' => 1 ) );
		$second_id = $this->make_plan( $group_id, 'Weekly', array( 'sort_order' => 2 ) );

		( new ProductApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$plans = ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG );

		$this->assertSame( array( $first_id, $second_id ), self::plan_ids( $plans ) );
	}

	public function test_inherit_select_resolves_only_attached_active_plans(): void {
		$group_id   = $this->make_group( 'select-group' );
		$product_id = $this->make_product();

		$attached_plan_id = $this->make_plan( $group_id, 'Attached' );
		$this->make_plan( $group_id, 'Unattached' );
		$archived_plan_id = $this->make_plan( $group_id, 'Archived attached', array( 'status' => Plan::STATUS_ARCHIVED ) );

		( new ProductApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( $attached_plan_id, $archived_plan_id ) )
		);

		$plans = ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG );

		$this->assertSame( array( $attached_plan_id ), self::plan_ids( $plans ) );
	}

	public function test_inherit_select_with_empty_selection_resolves_to_no_plans(): void {
		$group_id   = $this->make_group( 'empty-selection-group' );
		$product_id = $this->make_product();
		$this->make_plan( $group_id, 'Monthly' );

		( new ProductApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array() )
		);

		$this->assertSame( array(), ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG ) );
	}

	public function test_empty_selection_short_circuits_without_running_the_filter(): void {
		$product_id = $this->make_product();

		( new ProductApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array() )
		);

		$calls = 0;
		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans ) use ( &$calls ): array {
				++$calls;

				return $plans;
			}
		);

		$this->assertSame( array(), ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG ) );
		$this->assertSame( 0, $calls );
	}

	public function test_disable_mode_runs_the_filter_over_the_empty_set(): void {
		$product_id = $this->make_product();

		( new ProductApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_DISABLE ) );

		$calls = 0;
		$seen  = null;
		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans ) use ( &$calls, &$seen ): array {
				++$calls;
				$seen = $plans;

				return $plans;
			}
		);

		$this->assertSame( array(), ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG ) );
		$this->assertSame( 1, $calls );
		$this->assertSame( array(), $seen );
	}

	public function test_archived_and_foreign_slug_plans_are_excluded(): void {
		$group_id   = $this->make_group( 'exclusions-group' );
		$product_id = $this->make_product();

		$active_id = $this->make_plan( $group_id, 'Active' );
		$this->make_plan( $group_id, 'Archived', array( 'status' => Plan::STATUS_ARCHIVED ) );
		$this->make_plan( $group_id, 'Foreign', array( 'extension_slug' => 'other-extension' ) );

		( new ProductApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$plans = ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG );

		$this->assertSame( array( $active_id ), self::plan_ids( $plans ) );
	}

	public function test_variation_id_resolves_to_the_parent_applicability(): void {
		$group_id = $this->make_group( 'variation-group' );
		$plan_id  = $this->make_plan( $group_id, 'Monthly' );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Coffee subscription box' );
		$parent_id = (int) $parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '30.00' );
		$variation_id = (int) $variation->save();

		( new ProductApplicabilityStore() )->set( $parent_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$plans = ( new ProductPlanResolver() )->for_product( $variation_id, self::SLUG );

		$this->assertSame( array( $plan_id ), self::plan_ids( $plans ) );
	}

	public function test_plans_come_back_in_sort_order(): void {
		$group_id   = $this->make_group( 'sorted-group' );
		$product_id = $this->make_product();

		$last_id  = $this->make_plan( $group_id, 'Last', array( 'sort_order' => 9 ) );
		$first_id = $this->make_plan( $group_id, 'First', array( 'sort_order' => 1 ) );

		( new ProductApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$plans = ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG );

		$this->assertSame( array( $first_id, $last_id ), self::plan_ids( $plans ) );
	}

	public function test_filter_can_remove_and_append_plans(): void {
		$group_id   = $this->make_group( 'filtered-group' );
		$product_id = $this->make_product();

		$removed_id = $this->make_plan( $group_id, 'Removed' );
		$this->make_plan( $group_id, 'Kept' );
		$appended = ( new PlanRepository() )->find( $this->make_plan( $group_id, 'Appended', array( 'status' => Plan::STATUS_ARCHIVED ) ) );
		$this->assertInstanceOf( Plan::class, $appended );

		( new ProductApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans ) use ( $removed_id, $appended ): array {
				$plans = array_filter(
					$plans,
					static function ( Plan $plan ) use ( $removed_id ): bool {
						return $plan->get_id() !== $removed_id;
					}
				);

				$plans[] = $appended;

				return $plans;
			}
		);

		$plans = ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG );
		$names = array_map(
			static function ( Plan $plan ): string {
				return $plan->get_name();
			},
			$plans
		);

		$this->assertSame( array( 'Kept', 'Appended' ), $names );
	}

	public function test_filter_receives_the_original_product_id_and_slug(): void {
		$product_id = $this->make_product();

		( new ProductApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$seen = array();
		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans, int $filtered_product_id, string $filtered_slug ) use ( &$seen ): array {
				$seen = array( $filtered_product_id, $filtered_slug );

				return $plans;
			},
			10,
			3
		);

		( new ProductPlanResolver() )->for_product( $product_id, self::SLUG );

		$this->assertSame( array( $product_id, self::SLUG ), $seen );
	}

	public function test_non_plan_filter_garbage_is_dropped(): void {
		$group_id   = $this->make_group( 'garbage-group' );
		$product_id = $this->make_product();

		$plan_id = $this->make_plan( $group_id, 'Real' );

		( new ProductApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans ): array {
				$plans[] = 'not-a-plan';
				$plans[] = null;
				$plans[] = new \stdClass();

				return $plans;
			}
		);

		$plans = ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG );

		$this->assertSame( array( $plan_id ), self::plan_ids( $plans ) );
	}

	public function test_filter_returning_garbage_yields_no_plans(): void {
		$group_id   = $this->make_group( 'broken-filter-group' );
		$product_id = $this->make_product();
		$this->make_plan( $group_id, 'Real' );

		( new ProductApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		add_filter( ProductPlanResolver::PRODUCT_PLANS_FILTER, '__return_false' );

		$this->assertSame( array(), ( new ProductPlanResolver() )->for_product( $product_id, self::SLUG ) );
	}
}
