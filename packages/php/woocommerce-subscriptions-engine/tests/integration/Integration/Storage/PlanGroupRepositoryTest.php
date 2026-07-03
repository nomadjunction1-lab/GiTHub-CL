<?php
/**
 * Integration tests for PlanGroupRepository::query().
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Tests\Integration\Integration\Storage;

use EngineIntegrationTestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanGroup;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanGroupRepository;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanGroupRepository
 */
class PlanGroupRepositoryTest extends EngineIntegrationTestCase {

	private function make_group( PlanGroupRepository $repo, string $merchant_code, string $extension_slug ): int {
		return $repo->insert(
			PlanGroup::create(
				array(
					'name'           => 'Group ' . $merchant_code,
					'merchant_code'  => $merchant_code,
					'extension_slug' => $extension_slug,
				)
			)
		);
	}

	public function test_query_by_extension_slug_returns_owned_groups_in_id_order(): void {
		$repo = new PlanGroupRepository();

		$first_id  = $this->make_group( $repo, 'owned-one', 'lite' );
		$second_id = $this->make_group( $repo, 'owned-two', 'lite' );
		$this->make_group( $repo, 'foreign', 'other-extension' );

		$groups = $repo->query( array( 'extension_slug' => 'lite' ) );

		$this->assertSame( array( $first_id, $second_id ), array_map( static fn ( PlanGroup $group ): ?int => $group->get_id(), $groups ) );
		$this->assertSame( array( 'lite', 'lite' ), array_map( static fn ( PlanGroup $group ): ?string => $group->get_extension_slug(), $groups ) );
	}

	public function test_query_foreign_or_invalid_slug_returns_empty(): void {
		$repo = new PlanGroupRepository();

		$this->make_group( $repo, 'owned', 'lite' );

		$this->assertCount( 0, $repo->query( array( 'extension_slug' => 'nobody' ) ) );
		$this->assertCount( 0, $repo->query( array( 'extension_slug' => '' ) ) );
		$this->assertCount( 0, $repo->query( array( 'extension_slug' => 'any' ) ) );
		$this->assertCount( 0, $repo->query( array( 'extension_slug' => null ) ) );
	}

	public function test_query_without_extension_slug_returns_all_groups(): void {
		$repo = new PlanGroupRepository();

		$lite_id    = $this->make_group( $repo, 'unscoped-lite', 'lite' );
		$foreign_id = $this->make_group( $repo, 'unscoped-foreign', 'other-extension' );

		$groups = $repo->query();

		$this->assertSame( array( $lite_id, $foreign_id ), array_map( static fn ( PlanGroup $group ): ?int => $group->get_id(), $groups ) );
	}

	public function test_query_limit_and_offset_page_through_groups(): void {
		$repo = new PlanGroupRepository();

		$ids = array(
			$this->make_group( $repo, 'page-one', 'lite' ),
			$this->make_group( $repo, 'page-two', 'lite' ),
			$this->make_group( $repo, 'page-three', 'lite' ),
		);

		$first_page = $repo->query(
			array(
				'extension_slug' => 'lite',
				'limit'          => 2,
			)
		);
		$last_page  = $repo->query(
			array(
				'extension_slug' => 'lite',
				'limit'          => 2,
				'offset'         => 2,
			)
		);

		$this->assertSame( array( $ids[0], $ids[1] ), array_map( static fn ( PlanGroup $group ): ?int => $group->get_id(), $first_page ) );
		$this->assertSame( array( $ids[2] ), array_map( static fn ( PlanGroup $group ): ?int => $group->get_id(), $last_page ) );
	}

	public function test_query_hydrates_options_display(): void {
		$repo = new PlanGroupRepository();

		$repo->insert(
			PlanGroup::create(
				array(
					'name'            => 'Displayed',
					'merchant_code'   => 'displayed',
					'options_display' => array( array( 'name' => 'Size' ) ),
					'extension_slug'  => 'lite',
				)
			)
		);

		$groups = $repo->query( array( 'extension_slug' => 'lite' ) );

		$this->assertCount( 1, $groups );
		$this->assertSame( array( array( 'name' => 'Size' ) ), $groups[0]->get_options_display() );
	}
}
