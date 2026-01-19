<?php
/**
 * Centralized time management for Hypercart plugin suite
 *
 * DESIGN PRINCIPLES:
 * - All internal operations use UTC timestamps
 * - Display methods convert to WordPress site timezone
 * - Stateless design (no instance properties) enables clean extraction
 * - Mockable for deterministic testing
 *
 * USAGE:
 * - Storage: Hypercart_Time::now() returns Unix timestamp (UTC)
 * - Display: Hypercart_Time::format() returns site-timezone formatted string
 * - Logs:    Hypercart_Time::utc_format() returns UTC formatted string
 * - API:     Hypercart_Time::iso8601() returns ISO 8601 with Z suffix
 *
 * @package Hypercart_Helper
 * @since   1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hypercart_Time {

	/**
	 * Mock timestamp for testing (null = use real time)
	 *
	 * @var int|null
	 */
	private static $mock_time = null;

	/**
	 * Get current UTC Unix timestamp
	 *
	 * This is the ONLY method that should call PHP's time() function.
	 * All other time retrieval in Hypercart plugins MUST use this method.
	 *
	 * @since 1.0.0
	 * @return int Unix timestamp (always UTC)
	 */
	public static function now(): int {
		if ( null !== self::$mock_time ) {
			return self::$mock_time;
		}
		return time();
	}

	/**
	 * Format timestamp for user display (site timezone)
	 *
	 * Converts UTC timestamp to WordPress site timezone for display.
	 * Uses wp_date() which properly handles i18n and timezone conversion.
	 *
	 * @since 1.0.0
	 * @param string   $format    PHP date format string.
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return string Formatted date/time in site timezone.
	 */
	public static function format( string $format, ?int $timestamp = null ): string {
		$timestamp = $timestamp ?? self::now();
		
		// wp_date() handles timezone conversion automatically
		// when no timezone parameter is passed, it uses wp_timezone()
		return wp_date( $format, $timestamp );
	}

	/**
	 * Format timestamp in UTC (for logs, filenames, debugging)
	 *
	 * Returns formatted string in UTC timezone. Use this for:
	 * - Log file entries
	 * - Log file names
	 * - Debug output
	 * - Any context where consistent UTC is needed
	 *
	 * @since 1.0.0
	 * @param string   $format    PHP date format string.
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return string Formatted date/time in UTC.
	 */
	public static function utc_format( string $format, ?int $timestamp = null ): string {
		$timestamp = $timestamp ?? self::now();
		
		// Force UTC timezone for consistent output
		return wp_date( $format, $timestamp, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Get ISO 8601 formatted timestamp (for APIs)
	 *
	 * Returns UTC timestamp in ISO 8601 format with 'Z' suffix.
	 * Standard format for REST APIs and external integrations.
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return string ISO 8601 formatted date (e.g., "2024-12-29T15:30:00Z").
	 */
	public static function iso8601( ?int $timestamp = null ): string {
		$timestamp = $timestamp ?? self::now();
		
		// Use 'c' format but force UTC and replace offset with 'Z'
		return wp_date( 'Y-m-d\TH:i:s\Z', $timestamp, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Get MySQL DATETIME string in UTC
	 *
	 * For database columns that store datetime strings.
	 * Prefer Unix timestamps when possible, but some schemas require DATETIME.
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return string MySQL DATETIME format in UTC (e.g., "2024-12-29 15:30:00").
	 */
	public static function mysql_utc( ?int $timestamp = null ): string {
		return self::utc_format( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Get MySQL DATETIME string in site timezone
	 *
	 * For display purposes when MySQL format is needed.
	 * NOT recommended for storage - use mysql_utc() instead.
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return string MySQL DATETIME format in site timezone.
	 */
	public static function mysql_local( ?int $timestamp = null ): string {
		return self::format( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Convert UTC timestamp to site timezone DateTime object
	 *
	 * For advanced date manipulation when DateTimeImmutable is needed.
	 * Returns immutable object to prevent accidental modification.
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return DateTimeImmutable DateTime object in site timezone.
	 */
	public static function to_datetime( ?int $timestamp = null ): DateTimeImmutable {
		$timestamp = $timestamp ?? self::now();

		$datetime = new DateTimeImmutable( '@' . $timestamp );
		return $datetime->setTimezone( wp_timezone() );
	}

	/**
	 * Convert UTC timestamp to UTC DateTime object
	 *
	 * For advanced date manipulation in UTC context.
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return DateTimeImmutable DateTime object in UTC.
	 */
	public static function to_utc_datetime( ?int $timestamp = null ): DateTimeImmutable {
		$timestamp = $timestamp ?? self::now();

		$datetime = new DateTimeImmutable( '@' . $timestamp );
		return $datetime->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Parse date string to UTC timestamp
	 *
	 * Parses a date string (assumed to be in site timezone unless specified)
	 * and returns a UTC Unix timestamp.
	 *
	 * @since 1.0.0
	 * @param string      $date_string Date string to parse.
	 * @param string|null $timezone    Source timezone (null = site timezone).
	 * @return int|false UTC Unix timestamp, or false on parse failure.
	 */
	public static function parse( string $date_string, ?string $timezone = null ) {
		try {
			$tz = $timezone ? new DateTimeZone( $timezone ) : wp_timezone();
			$datetime = new DateTime( $date_string, $tz );
			return $datetime->getTimestamp();
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get timezone offset string for display
	 *
	 * Returns the site timezone offset as a human-readable string.
	 * Useful for displaying "All times shown in Pacific Time (UTC-8)" etc.
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp Reference timestamp for DST calculation.
	 * @return string Timezone offset string (e.g., "UTC-8" or "UTC+5:30").
	 */
	public static function get_offset_string( ?int $timestamp = null ): string {
		$timestamp = $timestamp ?? self::now();
		$datetime = self::to_datetime( $timestamp );

		$offset_seconds = $datetime->getOffset();
		$hours = intdiv( $offset_seconds, 3600 );
		$minutes = abs( ( $offset_seconds % 3600 ) / 60 );

		if ( 0 === $offset_seconds ) {
			return 'UTC';
		}

		$sign = $hours >= 0 ? '+' : '';

		if ( $minutes > 0 ) {
			return sprintf( 'UTC%s%d:%02d', $sign, $hours, $minutes );
		}

		return sprintf( 'UTC%s%d', $sign, $hours );
	}

	/**
	 * Get timezone name for display
	 *
	 * Returns human-readable timezone name from WordPress settings.
	 *
	 * @since 1.0.0
	 * @return string Timezone name (e.g., "America/Los_Angeles" or "UTC+5").
	 */
	public static function get_timezone_name(): string {
		return wp_timezone_string();
	}

	/**
	 * Check if a timestamp is in the past
	 *
	 * @since 1.0.0
	 * @param int $timestamp UTC Unix timestamp to check.
	 * @return bool True if timestamp is before current time.
	 */
	public static function is_past( int $timestamp ): bool {
		return $timestamp < self::now();
	}

	/**
	 * Check if a timestamp is in the future
	 *
	 * @since 1.0.0
	 * @param int $timestamp UTC Unix timestamp to check.
	 * @return bool True if timestamp is after current time.
	 */
	public static function is_future( int $timestamp ): bool {
		return $timestamp > self::now();
	}

	/**
	 * Get start of day (midnight) in UTC for a given timestamp
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return int UTC Unix timestamp for start of that UTC day.
	 */
	public static function start_of_day_utc( ?int $timestamp = null ): int {
		$timestamp = $timestamp ?? self::now();
		$datetime = self::to_utc_datetime( $timestamp );

		return $datetime->setTime( 0, 0, 0 )->getTimestamp();
	}

	/**
	 * Get start of day (midnight) in site timezone for a given timestamp
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return int UTC Unix timestamp for start of that local day.
	 */
	public static function start_of_day_local( ?int $timestamp = null ): int {
		$timestamp = $timestamp ?? self::now();
		$datetime = self::to_datetime( $timestamp );

		return $datetime->setTime( 0, 0, 0 )->getTimestamp();
	}

	/**
	 * Get current hour slot (0-23) in UTC
	 *
	 * Useful for hourly metrics collection.
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return int Hour of day (0-23) in UTC.
	 */
	public static function get_hour_utc( ?int $timestamp = null ): int {
		return (int) self::utc_format( 'G', $timestamp );
	}

	/**
	 * Get current day of week (0=Sunday, 6=Saturday) in UTC
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return int Day of week (0-6) in UTC.
	 */
	public static function get_day_of_week_utc( ?int $timestamp = null ): int {
		return (int) self::utc_format( 'w', $timestamp );
	}

	/**
	 * Get weekly hour slot (0-167) in UTC
	 *
	 * Maps timestamp to one of 168 hourly slots in a week.
	 * Useful for building weekly baseline patterns.
	 *
	 * Slot calculation: (day_of_week * 24) + hour
	 * - Slot 0:   Sunday 00:00-00:59 UTC
	 * - Slot 23:  Sunday 23:00-23:59 UTC
	 * - Slot 24:  Monday 00:00-00:59 UTC
	 * - Slot 167: Saturday 23:00-23:59 UTC
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp UTC Unix timestamp (default: current time).
	 * @return int Weekly slot (0-167).
	 */
	public static function get_weekly_slot( ?int $timestamp = null ): int {
		$day = self::get_day_of_week_utc( $timestamp );
		$hour = self::get_hour_utc( $timestamp );

		return ( $day * 24 ) + $hour;
	}

	/**
	 * Get human-readable relative time (e.g., "2 hours ago")
	 *
	 * Wrapper around human_time_diff() with proper handling.
	 *
	 * @since 1.0.0
	 * @param int      $timestamp UTC Unix timestamp to compare.
	 * @param int|null $reference Reference timestamp (default: current time).
	 * @return string Human-readable time difference.
	 */
	public static function human_diff( int $timestamp, ?int $reference = null ): string {
		$reference = $reference ?? self::now();

		if ( $timestamp <= $reference ) {
			/* translators: %s: Human-readable time difference */
			return sprintf( __( '%s ago', 'hypercart-helper' ), human_time_diff( $timestamp, $reference ) );
		}

		/* translators: %s: Human-readable time difference */
		return sprintf( __( 'in %s', 'hypercart-helper' ), human_time_diff( $reference, $timestamp ) );
	}

	/**
	 * Parse MySQL DATETIME string (stored in UTC) to Unix timestamp
	 *
	 * Converts a MySQL DATETIME string (assumed to be UTC) back to Unix timestamp.
	 * Use this to read timestamps stored via mysql_utc().
	 *
	 * @since 1.0.1
	 * @param string $mysql_datetime MySQL DATETIME string in UTC (e.g., "2024-12-29 15:30:00").
	 * @return int|false UTC Unix timestamp, or false on parse failure.
	 */
	public static function parse_mysql_utc( string $mysql_datetime ) {
		try {
			$datetime = new DateTime( $mysql_datetime, new DateTimeZone( 'UTC' ) );
			return $datetime->getTimestamp();
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Calculate duration in seconds with pause support
	 *
	 * Calculates total active duration between start and end times,
	 * optionally subtracting paused time periods.
	 *
	 * @since 1.0.1
	 * @param int        $start_time  UTC Unix timestamp when session started.
	 * @param int|null   $end_time    UTC Unix timestamp when session ended (null = ongoing, uses now()).
	 * @param array|null $pause_periods Array of pause periods, each with 'start' and 'end' timestamps.
	 *                                  Format: [['start' => 123, 'end' => 456], ...].
	 *                                  Null or empty array = no pauses.
	 * @return int Total active duration in seconds (never negative).
	 */
	public static function duration_seconds( int $start_time, ?int $end_time = null, ?array $pause_periods = null ): int {
		$end_time = $end_time ?? self::now();

		// Base duration
		$total_duration = max( 0, $end_time - $start_time );

		// Subtract pause periods
		if ( ! empty( $pause_periods ) && is_array( $pause_periods ) ) {
			$total_paused = 0;

			foreach ( $pause_periods as $pause ) {
				if ( ! isset( $pause['start'] ) ) {
					continue;
				}

				$pause_start = (int) $pause['start'];
				$pause_end = isset( $pause['end'] ) ? (int) $pause['end'] : self::now();

				// Only count pauses within the session timeframe
				$pause_start = max( $pause_start, $start_time );
				$pause_end = min( $pause_end, $end_time );

				if ( $pause_end > $pause_start ) {
					$total_paused += ( $pause_end - $pause_start );
				}
			}

			$total_duration = max( 0, $total_duration - $total_paused );
		}

		return $total_duration;
	}

	/**
	 * Format duration in human-readable format
	 *
	 * Converts seconds to readable format like "2h 30m" or "2.5 hours".
	 *
	 * @since 1.0.1
	 * @param int    $seconds Total seconds to format.
	 * @param string $format  Output format: 'short' (2h 30m) or 'decimal' (2.5 hours).
	 * @return string Formatted duration string.
	 */
	public static function format_duration( int $seconds, string $format = 'short' ): string {
		if ( $seconds < 0 ) {
			$seconds = 0;
		}

		if ( 'decimal' === $format ) {
			$hours = $seconds / 3600;

			if ( $hours < 0.1 ) {
				$minutes = round( $seconds / 60 );
				/* translators: %d: number of minutes */
				return sprintf( _n( '%d minute', '%d minutes', $minutes, 'hypercart-helper' ), $minutes );
			}

			/* translators: %s: decimal hours (e.g., "2.5") */
			return sprintf( __( '%s hours', 'hypercart-helper' ), number_format_i18n( $hours, 1 ) );
		}

		// Short format: "2h 30m" or "45m" or "30s"
		$hours = floor( $seconds / 3600 );
		$minutes = floor( ( $seconds % 3600 ) / 60 );
		$secs = $seconds % 60;

		$parts = array();

		if ( $hours > 0 ) {
			/* translators: %d: number of hours */
			$parts[] = sprintf( _n( '%dh', '%dh', $hours, 'hypercart-helper' ), $hours );
		}

		if ( $minutes > 0 ) {
			/* translators: %d: number of minutes */
			$parts[] = sprintf( _n( '%dm', '%dm', $minutes, 'hypercart-helper' ), $minutes );
		}

		if ( empty( $parts ) ) {
			if ( $secs > 0 ) {
				/* translators: %d: number of seconds */
				$parts[] = sprintf( _n( '%ds', '%ds', $secs, 'hypercart-helper' ), $secs );
			} else {
				return __( '0s', 'hypercart-helper' );
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Validate session times are in chronological order
	 *
	 * Checks that start < end and all pause periods are valid.
	 * Returns array with 'valid' boolean and 'errors' array.
	 *
	 * @since 1.0.1
	 * @param int        $start_time    UTC Unix timestamp when session started.
	 * @param int|null   $end_time      UTC Unix timestamp when session ended (null = ongoing).
	 * @param array|null $pause_periods Array of pause periods with 'start' and 'end' timestamps.
	 * @return array {
	 *     Validation result.
	 *
	 *     @type bool  $valid  Whether all times are valid.
	 *     @type array $errors Array of error messages (empty if valid).
	 * }
	 */
	public static function validate_session_times( int $start_time, ?int $end_time = null, ?array $pause_periods = null ): array {
		$errors = array();

		// Validate start time is not in the future
		if ( self::is_future( $start_time ) ) {
			$errors[] = __( 'Start time cannot be in the future.', 'hypercart-helper' );
		}

		// Validate end time if provided
		if ( null !== $end_time ) {
			if ( $end_time < $start_time ) {
				$errors[] = __( 'End time must be after start time.', 'hypercart-helper' );
			}

			if ( self::is_future( $end_time ) ) {
				$errors[] = __( 'End time cannot be in the future.', 'hypercart-helper' );
			}
		}

		// Validate pause periods
		if ( ! empty( $pause_periods ) && is_array( $pause_periods ) ) {
			foreach ( $pause_periods as $index => $pause ) {
				if ( ! isset( $pause['start'] ) ) {
					/* translators: %d: pause period index (1-based) */
					$errors[] = sprintf( __( 'Pause period #%d is missing start time.', 'hypercart-helper' ), $index + 1 );
					continue;
				}

				$pause_start = (int) $pause['start'];
				$pause_end = isset( $pause['end'] ) ? (int) $pause['end'] : null;

				// Pause start must be after session start
				if ( $pause_start < $start_time ) {
					/* translators: %d: pause period index (1-based) */
					$errors[] = sprintf( __( 'Pause period #%d starts before session start.', 'hypercart-helper' ), $index + 1 );
				}

				// If session has ended, pause must be before session end
				if ( null !== $end_time && $pause_start > $end_time ) {
					/* translators: %d: pause period index (1-based) */
					$errors[] = sprintf( __( 'Pause period #%d starts after session end.', 'hypercart-helper' ), $index + 1 );
				}

				// If pause has ended, validate end time
				if ( null !== $pause_end ) {
					if ( $pause_end < $pause_start ) {
						/* translators: %d: pause period index (1-based) */
						$errors[] = sprintf( __( 'Pause period #%d end time is before start time.', 'hypercart-helper' ), $index + 1 );
					}

					if ( null !== $end_time && $pause_end > $end_time ) {
						/* translators: %d: pause period index (1-based) */
						$errors[] = sprintf( __( 'Pause period #%d ends after session end.', 'hypercart-helper' ), $index + 1 );
					}
				}
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Set mock time for testing
	 *
	 * Allows deterministic testing by freezing time.
	 * Pass null to restore real time.
	 *
	 * IMPORTANT: Only use in test environments!
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp Mock UTC timestamp, or null to reset.
	 * @return void
	 */
	public static function set_mock_time( ?int $timestamp ): void {
		self::$mock_time = $timestamp;
	}

	/**
	 * Check if mock time is active
	 *
	 * @since 1.0.0
	 * @return bool True if mock time is set.
	 */
	public static function is_mocked(): bool {
		return null !== self::$mock_time;
	}

	/**
	 * Reset mock time (alias for set_mock_time(null))
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function reset_mock_time(): void {
		self::$mock_time = null;
	}
}
