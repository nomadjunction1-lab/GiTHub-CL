<?php
/**
 * Integration tests for the SellingPlans facade.
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Tests\Integration\Api;

use EngineIntegrationTestCase;
use InvalidArgumentException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanGroup;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanGroupRepository;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans
 */
class SellingPlansTest extends EngineIntegrationTestCase {

	private const SLUG = 'lite';

	private function make_group( string $merchant_code, string $extension_slug = self::SLUG ): int {
		return ( new PlanGroupRepository() )->insert(
			PlanGroup::create(
				array(
					'name'           => 'Group ' . $merchant_code,
					'merchant_code'  => $merchant_code,
					'extension_slug' => $extension_slug,
				)
			)
		);
	}

	/**
	 * Insert a plan.
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
	 * Create a variable product with one variation.
	 *
	 * @return array{0: int, 1: int} Parent id and variation id.
	 */
	private function make_variable_product(): array {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Coffee box' );
		$parent_id = (int) $parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '30.00' );
		$variation_id = (int) $variation->save();

		return array( $parent_id, $variation_id );
	}

	public function test_set_and_get_round_trip_per_mode(): void {
		$group_id   = $this->make_group( 'round-trip' );
		$product_id = $this->make_product();

		SellingPlans::set_product_applicability( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL, array(), false ), self::SLUG );
		$fetched = SellingPlans::get_product_applicability( $product_id );
		$this->assertSame( ProductApplicability::MODE_INHERIT_ALL, $fetched->get_mode() );
		$this->assertFalse( $fetched->allows_one_time() );

		SellingPlans::set_product_applicability( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( $group_id ) ), self::SLUG );
		$fetched = SellingPlans::get_product_applicability( $product_id );
		$this->assertSame( ProductApplicability::MODE_INHERIT_SELECT, $fetched->get_mode() );
		$this->assertSame( array( $group_id ), $fetched->get_group_ids() );
		$this->assertTrue( $fetched->allows_one_time() );

		SellingPlans::set_product_applicability( $product_id, new ProductApplicability( ProductApplicability::MODE_DISABLE ), self::SLUG );
		$fetched = SellingPlans::get_product_applicability( $product_id );
		$this->assertSame( ProductApplicability::MODE_DISABLE, $fetched->get_mode() );
		$this->assertSame( array(), $fetched->get_group_ids() );
	}

	public function test_set_rejects_unknown_product(): void {
		$this->expectException( InvalidArgumentException::class );

		SellingPlans::set_product_applicability( 999999, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ), self::SLUG );
	}

	public function test_set_rejects_a_variation_id(): void {
		// phpcs:ignore Generic.Arrays.DisallowShortArraySyntax.Found
		[ $parent_id, $variation_id ] = $this->make_variable_product();

		try {
			SellingPlans::set_product_applicability( $variation_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ), self::SLUG );
			$this->fail( 'Expected InvalidArgumentException for a variation id.' );
		} catch ( InvalidArgumentException $e ) {
			// Nothing was written - the parent still reads as default.
			$this->assertSame( ProductApplicability::MODE_DISABLE, SellingPlans::get_product_applicability( $parent_id )->get_mode() );
		}
	}

	public function test_set_rejects_nonexistent_group_id_and_writes_nothing(): void {
		$product_id = $this->make_product();

		try {
			SellingPlans::set_product_applicability( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( 999999 ) ), self::SLUG );
			$this->fail( 'Expected InvalidArgumentException for a nonexistent group id.' );
		} catch ( InvalidArgumentException $e ) {
			$fetched = SellingPlans::get_product_applicability( $product_id );
			$this->assertSame( ProductApplicability::MODE_DISABLE, $fetched->get_mode() );
			$this->assertSame( array(), $fetched->get_group_ids() );
		}
	}

	public function test_set_rejects_group_owned_by_another_slug_and_writes_nothing(): void {
		$foreign_group_id = $this->make_group( 'foreign', 'other-extension' );
		$product_id       = $this->make_product();

		try {
			SellingPlans::set_product_applicability( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( $foreign_group_id ) ), self::SLUG );
			$this->fail( 'Expected InvalidArgumentException for a foreign-slug group id.' );
		} catch ( InvalidArgumentException $e ) {
			$fetched = SellingPlans::get_product_applicability( $product_id );
			$this->assertSame( ProductApplicability::MODE_DISABLE, $fetched->get_mode() );
			$this->assertSame( array(), $fetched->get_group_ids() );
		}
	}

	public function test_get_defaults_for_a_fresh_product(): void {
		$applicability = SellingPlans::get_product_applicability( $this->make_product() );

		$this->assertSame( ProductApplicability::MODE_DISABLE, $applicability->get_mode() );
		$this->assertSame( array(), $applicability->get_group_ids() );
		$this->assertTrue( $applicability->allows_one_time() );
	}

	public function test_get_resolves_a_variation_id_to_its_parent(): void {
		// phpcs:ignore Generic.Arrays.DisallowShortArraySyntax.Found
		[ $parent_id, $variation_id ] = $this->make_variable_product();

		SellingPlans::set_product_applicability( $parent_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ), self::SLUG );

		$this->assertSame( ProductApplicability::MODE_INHERIT_ALL, SellingPlans::get_product_applicability( $variation_id )->get_mode() );
	}

	public function test_list_plans_excludes_archived_and_foreign_slug_plans(): void {
		$group_id = $this->make_group( 'listing' );

		$second_id = $this->make_plan( $group_id, 'Second', array( 'sort_order' => 2 ) );
		$first_id  = $this->make_plan( $group_id, 'First', array( 'sort_order' => 1 ) );
		$this->make_plan( $group_id, 'Archived', array( 'status' => Plan::STATUS_ARCHIVED ) );
		$this->make_plan( $group_id, 'Foreign', array( 'extension_slug' => 'other-extension' ) );

		$plans = SellingPlans::list_plans( self::SLUG );

		$this->assertSame(
			array( $first_id, $second_id ),
			array_map(
				static function ( Plan $plan ): ?int {
					return $plan->get_id();
				},
				$plans
			)
		);
		$this->assertSame( $group_id, $plans[0]->get_group_id() );
	}

	public function test_for_product_resolves_through_the_facade(): void {
		$group_id   = $this->make_group( 'facade-smoke' );
		$product_id = $this->make_product();
		$plan_id    = $this->make_plan( $group_id, 'Monthly' );

		SellingPlans::set_product_applicability( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ), self::SLUG );

		$plans = SellingPlans::for_product( $product_id, self::SLUG );

		$this->assertCount( 1, $plans );
		$this->assertSame( $plan_id, $plans[0]->get_id() );
	}
}
