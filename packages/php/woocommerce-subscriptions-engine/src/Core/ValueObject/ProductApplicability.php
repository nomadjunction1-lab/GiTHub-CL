<?php
/**
 * ProductApplicability - typed value object for a product's selling-plan
 * applicability: mode, attached plan ids, and the allow-one-time flag.
 *
 * Mirrors the engine-owned product meta shape:
 *   mode           'disable' | 'inherit_all' | 'inherit_select'
 *   plan_ids       plan ids, only meaningful for 'inherit_select'
 *   allow_one_time whether one-time purchase stays available alongside plans
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject;

use InvalidArgumentException;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Support\ScalarCoercion;

defined( 'ABSPATH' ) || exit;

/**
 * ProductApplicability value object.
 *
 * Immutable. Plan ids are only retained for the 'inherit_select' mode:
 * 'disable' and 'inherit_all' normalize to an empty list because all-mode is
 * virtual - future plans auto-apply without attachment rows. An empty
 * selection under 'inherit_select' is allowed and resolves to no plans.
 */
final class ProductApplicability {

	public const MODE_DISABLE = 'disable';

	public const MODE_INHERIT_ALL = 'inherit_all';

	public const MODE_INHERIT_SELECT = 'inherit_select';

	public const ALLOWED_MODES = array( self::MODE_DISABLE, self::MODE_INHERIT_ALL, self::MODE_INHERIT_SELECT );

	public const DEFAULT_MODE = self::MODE_DISABLE;

	/**
	 * Applicability mode.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Attached plan ids; empty unless mode is 'inherit_select'.
	 *
	 * @var array<int, int>
	 */
	private $plan_ids;

	/**
	 * Whether one-time purchase stays available alongside plans.
	 *
	 * @var bool
	 */
	private $allow_one_time;

	/**
	 * Build a product applicability.
	 *
	 * @param string       $mode           Applicability mode.
	 * @param array<mixed> $plan_ids       Plan ids; coerced to unique positive ints.
	 * @param bool         $allow_one_time Whether one-time purchase stays available.
	 * @throws InvalidArgumentException If the mode is unknown or a plan id is not a positive integer.
	 */
	public function __construct( string $mode, array $plan_ids = array(), bool $allow_one_time = true ) {
		if ( ! in_array( $mode, self::ALLOWED_MODES, true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'ProductApplicability: invalid mode "%s".', $mode )
			);
		}

		$normalized = array();
		foreach ( $plan_ids as $plan_id ) {
			$id = ScalarCoercion::coerce_int( $plan_id );
			if ( $id <= 0 ) {
				throw new InvalidArgumentException( 'ProductApplicability: plan ids must be positive integers.' );
			}
			$normalized[ $id ] = $id;
		}

		$this->mode           = $mode;
		$this->plan_ids       = self::MODE_INHERIT_SELECT === $mode ? array_values( $normalized ) : array();
		$this->allow_one_time = $allow_one_time;
	}

	/**
	 * Hydrate from the raw meta shape with safe defaults.
	 *
	 * An absent or invalid mode falls back to 'disable'; plan id strings are
	 * coerced and non-positive or non-numeric entries dropped; an absent
	 * allow-one-time defaults to true, with 'yes'/'no' strings mapped to bool.
	 *
	 * @param array<string, mixed> $row Raw meta values: mode, plan_ids, allow_one_time.
	 */
	public static function from_storage( array $row ): self {
		$mode = ScalarCoercion::coerce_string( $row['mode'] ?? null );
		if ( ! in_array( $mode, self::ALLOWED_MODES, true ) ) {
			$mode = self::DEFAULT_MODE;
		}

		$plan_ids = array();
		$raw_ids  = is_array( $row['plan_ids'] ?? null ) ? $row['plan_ids'] : array();
		foreach ( $raw_ids as $raw_id ) {
			$id = ScalarCoercion::coerce_int( $raw_id );
			if ( $id > 0 ) {
				$plan_ids[ $id ] = $id;
			}
		}

		$allow_one_time = true;
		$raw_flag       = $row['allow_one_time'] ?? null;
		if ( is_bool( $raw_flag ) ) {
			$allow_one_time = $raw_flag;
		} elseif ( 'no' === $raw_flag ) {
			$allow_one_time = false;
		}

		return new self( $mode, array_values( $plan_ids ), $allow_one_time );
	}

	/**
	 * Applicability mode.
	 */
	public function get_mode(): string {
		return $this->mode;
	}

	/**
	 * Attached plan ids; empty unless mode is 'inherit_select'.
	 *
	 * @return array<int, int>
	 */
	public function get_plan_ids(): array {
		return $this->plan_ids;
	}

	/**
	 * Whether one-time purchase stays available alongside plans.
	 */
	public function allows_one_time(): bool {
		return $this->allow_one_time;
	}

	/**
	 * Serialize to the meta value shape. Round-trips with from_storage().
	 *
	 * @return array{mode: string, plan_ids: array<int, int>, allow_one_time: string}
	 */
	public function to_storage(): array {
		return array(
			'mode'           => $this->mode,
			'plan_ids'       => $this->plan_ids,
			'allow_one_time' => $this->allow_one_time ? 'yes' : 'no',
		);
	}
}
