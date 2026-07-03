<?php
/**
 * PlanGroupRepository - persistence for {@see PlanGroup} entities.
 *
 * The engine's tables are private API; consumers reach plan groups through the public surface.
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanGroup;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Support\ScalarCoercion;

defined( 'ABSPATH' ) || exit;

/**
 * PlanGroup repository.
 */
final class PlanGroupRepository {

	/**
	 * Insert a new plan group and stamp its id back onto the entity.
	 *
	 * @param PlanGroup $group Group to insert.
	 * @return int The new group id.
	 * @throws \RuntimeException If the insert fails.
	 */
	public function insert( PlanGroup $group ): int {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = $group->to_storage();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			SchemaInstaller::get_table_name( SchemaInstaller::TABLE_PLAN_GROUPS ),
			array(
				'name'             => $data['name'],
				'merchant_code'    => $data['merchant_code'],
				'options_display'  => wp_json_encode( $data['options_display'] ),
				'extension_slug'   => $data['extension_slug'],
				'date_created_gmt' => $now,
				'date_updated_gmt' => $now,
			)
		);

		if ( false === $inserted ) {
			throw new \RuntimeException( 'Failed to insert plan group.' );
		}

		$id = (int) $wpdb->insert_id;
		$group->set_id( $id );

		return $id;
	}

	/**
	 * Fetch a plan group by id.
	 *
	 * @param int $id Group id.
	 * @return PlanGroup|null Hydrated group, or null if not found.
	 */
	public function find( int $id ): ?PlanGroup {
		global $wpdb;

		$table = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_PLAN_GROUPS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( null === $row ) {
			return null;
		}

		$row['options_display'] = self::decode_json( $row['options_display'] );

		return PlanGroup::from_storage( $row );
	}

	/**
	 * Query plan groups, ordered by id.
	 *
	 * Supported args: extension_slug, limit, offset. An invalid extension slug
	 * (empty, non-string, or the reserved 'any') matches no rows.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, PlanGroup>
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$table  = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_PLAN_GROUPS );
		$limit  = max( 1, ScalarCoercion::coerce_int( $args['limit'] ?? null, 50 ) );
		$offset = max( 0, ScalarCoercion::coerce_int( $args['offset'] ?? null, 0 ) );

		$clauses = array();
		$params  = array();

		if ( array_key_exists( 'extension_slug', $args ) ) {
			if ( self::is_valid_extension_slug( $args['extension_slug'] ) ) {
				$clauses[] = 'extension_slug = %s';
				$params[]  = $args['extension_slug'];
			} else {
				$clauses[] = '0 = 1';
			}
		}

		$where_sql = array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses );
		$params    = array( ...$params, $limit, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where_sql} ORDER BY id ASC LIMIT %d OFFSET %d", $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$groups = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row['options_display'] = self::decode_json( $row['options_display'] ?? null );

			$groups[] = PlanGroup::from_storage( $row );
		}

		return $groups;
	}

	/**
	 * Persist changes to an existing plan group.
	 *
	 * @param PlanGroup $group Group to update. Must have an id.
	 * @return bool True on success.
	 * @throws \RuntimeException If the group has no id.
	 */
	public function update( PlanGroup $group ): bool {
		global $wpdb;

		$id = $group->get_id();
		if ( null === $id ) {
			throw new \RuntimeException( 'Cannot update a plan group that has no id.' );
		}

		$data = $group->to_storage();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			SchemaInstaller::get_table_name( SchemaInstaller::TABLE_PLAN_GROUPS ),
			array(
				'name'             => $data['name'],
				'options_display'  => wp_json_encode( $data['options_display'] ),
				'date_updated_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);

		return false !== $updated;
	}

	/**
	 * Delete a plan group by id.
	 *
	 * @param int $id Group id.
	 * @return bool True when a row was removed.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			SchemaInstaller::get_table_name( SchemaInstaller::TABLE_PLAN_GROUPS ),
			array( 'id' => $id )
		);

		return (bool) $deleted;
	}

	/**
	 * Whether a value is a valid concrete extension slug.
	 *
	 * @param mixed $slug Possible extension slug.
	 */
	private static function is_valid_extension_slug( $slug ): bool {
		if ( ! is_string( $slug ) ) {
			return false;
		}
		if ( '' === $slug || 'any' === $slug ) {
			return false;
		}
		return true;
	}

	/**
	 * Decode a JSON column into an array, tolerating null/empty values.
	 *
	 * @param mixed $value Raw column value.
	 * @return array<mixed>
	 */
	private static function decode_json( $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
