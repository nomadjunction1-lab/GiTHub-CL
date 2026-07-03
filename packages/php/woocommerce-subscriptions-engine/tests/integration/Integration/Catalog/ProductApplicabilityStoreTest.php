<?php
/**
 * Integration tests for ProductApplicabilityStore.
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Tests\Integration\Integration\Catalog;

use EngineIntegrationTestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog\ProductApplicabilityStore;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsEngine\Integration\Catalog\ProductApplicabilityStore
 */
class ProductApplicabilityStoreTest extends EngineIntegrationTestCase {

	private function make_product(): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Coffee beans' );
		$product->set_regular_price( '24.00' );

		return (int) $product->save();
	}

	/**
	 * Read the raw group-id meta rows as ints.
	 *
	 * @param int $product_id Product id.
	 * @return array<int, int>
	 */
	private function group_id_rows( int $product_id ): array {
		$rows = get_post_meta( $product_id, ProductApplicabilityStore::META_GROUP_IDS, false );

		$ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$ids[] = is_scalar( $row ) ? (int) $row : 0;
		}

		return $ids;
	}

	public function test_fresh_product_returns_defaults(): void {
		$store         = new ProductApplicabilityStore();
		$applicability = $store->get( $this->make_product() );

		$this->assertSame( ProductApplicability::MODE_DISABLE, $applicability->get_mode() );
		$this->assertSame( array(), $applicability->get_group_ids() );
		$this->assertTrue( $applicability->allows_one_time() );
	}

	public function test_disable_round_trips(): void {
		$store      = new ProductApplicabilityStore();
		$product_id = $this->make_product();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_DISABLE, array(), false ) );
		$fetched = $store->get( $product_id );

		$this->assertSame( ProductApplicability::MODE_DISABLE, $fetched->get_mode() );
		$this->assertSame( array(), $fetched->get_group_ids() );
		$this->assertFalse( $fetched->allows_one_time() );
	}

	public function test_inherit_all_round_trips(): void {
		$store      = new ProductApplicabilityStore();
		$product_id = $this->make_product();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );
		$fetched = $store->get( $product_id );

		$this->assertSame( ProductApplicability::MODE_INHERIT_ALL, $fetched->get_mode() );
		$this->assertSame( array(), $fetched->get_group_ids() );
		$this->assertTrue( $fetched->allows_one_time() );
	}

	public function test_inherit_select_round_trips(): void {
		$store      = new ProductApplicabilityStore();
		$product_id = $this->make_product();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( 3, 7 ), false ) );
		$fetched = $store->get( $product_id );

		$this->assertSame( ProductApplicability::MODE_INHERIT_SELECT, $fetched->get_mode() );
		$this->assertSame( array( 3, 7 ), $fetched->get_group_ids() );
		$this->assertFalse( $fetched->allows_one_time() );
	}

	public function test_switching_select_to_all_clears_group_rows(): void {
		$store      = new ProductApplicabilityStore();
		$product_id = $this->make_product();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( 3, 7 ) ) );
		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$this->assertSame( array(), $this->group_id_rows( $product_id ) );
		$this->assertSame( ProductApplicability::MODE_INHERIT_ALL, $store->get( $product_id )->get_mode() );
	}

	public function test_group_ids_are_stored_one_row_each(): void {
		$store      = new ProductApplicabilityStore();
		$product_id = $this->make_product();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( 3, 7, 9 ) ) );

		$this->assertSame( array( 3, 7, 9 ), $this->group_id_rows( $product_id ) );
	}

	public function test_reconcile_removes_stale_rows_and_adds_missing_ones(): void {
		$store      = new ProductApplicabilityStore();
		$product_id = $this->make_product();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( 3, 7 ) ) );
		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( 7, 11 ) ) );

		$this->assertEqualsCanonicalizing( array( 7, 11 ), $this->group_id_rows( $product_id ) );
		$this->assertEqualsCanonicalizing( array( 7, 11 ), $store->get( $product_id )->get_group_ids() );
	}

	public function test_reconcile_collapses_externally_duplicated_rows(): void {
		$store      = new ProductApplicabilityStore();
		$product_id = $this->make_product();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( 3 ) ) );
		add_post_meta( $product_id, ProductApplicabilityStore::META_GROUP_IDS, 3 );

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( 3 ) ) );

		$this->assertSame( array( 3 ), $this->group_id_rows( $product_id ) );
	}
}
