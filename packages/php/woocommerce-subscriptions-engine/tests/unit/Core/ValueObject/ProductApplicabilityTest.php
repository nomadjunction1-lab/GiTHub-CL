<?php
/**
 * Unit tests for ProductApplicability.
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Tests\Unit\Core\ValueObject;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability
 */
class ProductApplicabilityTest extends TestCase {

	public function test_defaults_are_disable_with_one_time_allowed(): void {
		$applicability = new ProductApplicability( ProductApplicability::DEFAULT_MODE );

		$this->assertSame( ProductApplicability::MODE_DISABLE, $applicability->get_mode() );
		$this->assertSame( array(), $applicability->get_group_ids() );
		$this->assertTrue( $applicability->allows_one_time() );
	}

	public function test_unknown_mode_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProductApplicability( 'inherit_everything' );
	}

	/**
	 * @dataProvider provide_invalid_group_ids
	 *
	 * @param mixed $group_id Invalid group id.
	 */
	public function test_non_positive_group_ids_are_rejected( $group_id ): void {
		$this->expectException( InvalidArgumentException::class );

		new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( $group_id ) );
	}

	/**
	 * Group id values the constructor must reject.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function provide_invalid_group_ids(): array {
		return array(
			'zero'        => array( 0 ),
			'negative'    => array( -3 ),
			'non-numeric' => array( 'abc' ),
			'fractional'  => array( '1.5' ),
			'array'       => array( array( 2 ) ),
		);
	}

	public function test_group_ids_are_coerced_unique_ints(): void {
		$applicability = new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( '3', 5, 3, '5' ) );

		$this->assertSame( array( 3, 5 ), $applicability->get_group_ids() );
	}

	/**
	 * @dataProvider provide_non_select_modes
	 *
	 * @param string $mode Mode that carries no attachment rows.
	 */
	public function test_group_ids_are_dropped_for_non_select_modes( string $mode ): void {
		$applicability = new ProductApplicability( $mode, array( 7, 9 ) );

		$this->assertSame( array(), $applicability->get_group_ids() );
	}

	/**
	 * Modes whose group ids normalize away (all-mode is virtual).
	 *
	 * @return array<string, array<int, string>>
	 */
	public function provide_non_select_modes(): array {
		return array(
			'disable'     => array( ProductApplicability::MODE_DISABLE ),
			'inherit_all' => array( ProductApplicability::MODE_INHERIT_ALL ),
		);
	}

	public function test_empty_selection_under_inherit_select_is_allowed(): void {
		$applicability = new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array() );

		$this->assertSame( ProductApplicability::MODE_INHERIT_SELECT, $applicability->get_mode() );
		$this->assertSame( array(), $applicability->get_group_ids() );
	}

	public function test_from_storage_defaults_for_absent_values(): void {
		$applicability = ProductApplicability::from_storage( array() );

		$this->assertSame( ProductApplicability::MODE_DISABLE, $applicability->get_mode() );
		$this->assertSame( array(), $applicability->get_group_ids() );
		$this->assertTrue( $applicability->allows_one_time() );
	}

	public function test_from_storage_falls_back_to_disable_for_invalid_mode(): void {
		$applicability = ProductApplicability::from_storage(
			array(
				'mode'      => 'bogus',
				'group_ids' => array( '4' ),
			)
		);

		$this->assertSame( ProductApplicability::MODE_DISABLE, $applicability->get_mode() );
		$this->assertSame( array(), $applicability->get_group_ids() );
	}

	public function test_from_storage_coerces_group_id_strings_and_drops_invalid_entries(): void {
		$applicability = ProductApplicability::from_storage(
			array(
				'mode'      => ProductApplicability::MODE_INHERIT_SELECT,
				'group_ids' => array( '4', '0', 'junk', 6, '-2', '4' ),
			)
		);

		$this->assertSame( array( 4, 6 ), $applicability->get_group_ids() );
	}

	public function test_from_storage_maps_yes_no_strings_to_bool(): void {
		$yes = ProductApplicability::from_storage( array( 'allow_one_time' => 'yes' ) );
		$no  = ProductApplicability::from_storage( array( 'allow_one_time' => 'no' ) );

		$this->assertTrue( $yes->allows_one_time() );
		$this->assertFalse( $no->allows_one_time() );
	}

	public function test_to_storage_round_trips_through_from_storage(): void {
		$original = new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, array( 2, 8 ), false );

		$stored = $original->to_storage();

		$this->assertSame(
			array(
				'mode'           => ProductApplicability::MODE_INHERIT_SELECT,
				'group_ids'      => array( 2, 8 ),
				'allow_one_time' => 'no',
			),
			$stored
		);

		$rehydrated = ProductApplicability::from_storage( $stored );

		$this->assertSame( $original->get_mode(), $rehydrated->get_mode() );
		$this->assertSame( $original->get_group_ids(), $rehydrated->get_group_ids() );
		$this->assertSame( $original->allows_one_time(), $rehydrated->allows_one_time() );
	}

	public function test_to_storage_serializes_allow_one_time_as_yes(): void {
		$applicability = new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL );

		$this->assertSame( 'yes', $applicability->to_storage()['allow_one_time'] );
	}
}
