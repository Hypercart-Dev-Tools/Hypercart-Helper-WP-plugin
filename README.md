# Hypercart Helper Plugin: Time/Date & Logger Specifications

**Version:** 1.1.11   
**Author:** Noel @ Neochrome  
**Date:** December 29, 2024  
**Status:** Ready for Implementation  
**Approach:** Helper-First (build Helper as standalone, HPM depends on it)  
**Timebox:** 2-3 hours for Helper v1.0  

---

## Executive Summary

This document specifies two foundational utility classes for the Hypercart Helper WordPress plugin:

1. **Hypercart_Time** - Centralized UTC-based time management with local display
2. **Hypercart_Logger** - File-based logging with UTC-stamped rotating log files

Both are designed as stateless, static utility classes for reuse across the entire Hypercart plugin suite and third-party plugins.

### Build Strategy: Helper-First

We're building Helper as a **standalone plugin first**, then other Hypercart plugins will depend on it. This is slightly riskier than inline-then-extract, but architecturally cleaner.

---

## Part 1: Hypercart_Time Specification

### 1.1 Problem Statement

WordPress/WooCommerce timezone handling is a documented source of persistent bugs:

**Evidence from WooCommerce Core Issues:**
- Order times display incorrectly when timezone differs from UTC
- Log file timestamps mix local time with UTC offset markers
- Date range queries return wrong results in non-UTC timezones
- Order timestamps drift on each save (-3 hours, etc.)

**Root Causes:**
- Scattered API surface (`time()`, `current_time()`, `wp_date()`, `current_datetime()`)
- Easy to misuse deprecated functions (`current_time('timestamp')` discouraged since WP 5.3)
- No enforcement mechanism to prevent direct `time()`/`date()` calls
- Each plugin reinvents timezone handling with varying quality

**WordPress Core Position:**
> "Core does not and never did support changing PHP timezone, it's hardcoded to operate in UTC on PHP level."  
> — Make WordPress Core, Date/Time Improvements WP 5.3

### 1.2 Design Philosophy

**Golden Rule: Store UTC, Display Local**

| Operation | Timezone | Method |
|-----------|----------|--------|
| Internal storage | UTC | `Hypercart_Time::now()` |
| Database writes | UTC | Unix timestamp via `now()` |
| Comparisons | UTC | Unix timestamps only |
| User display | WP Site Timezone | `Hypercart_Time::format()` |
| Log files | UTC | `Hypercart_Time::utc_format()` |
| API responses | UTC (ISO 8601) | `Hypercart_Time::iso8601()` |

**Why This Works:**
- No DST edge cases (UTC doesn't observe DST)
- No timezone conversion bugs in storage
- Consistent behavior across server configurations
- Easy debugging (timestamps are universal)
- Clean separation of concerns (storage vs display)

### 1.3 Class Specification

```php
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
```

### 1.4 Usage Examples

```php
<?php
// ─────────────────────────────────────────────────────────────
// STORING DATA (always use UTC)
// ─────────────────────────────────────────────────────────────

// Store current timestamp
$created_at = Hypercart_Time::now();
update_post_meta( $post_id, '_hpm_created_at', $created_at );

// Store in MySQL DATETIME column (UTC)
$wpdb->insert( 'wp_hpm_events', array(
    'event_time' => Hypercart_Time::mysql_utc(),
    'event_type' => 'test_complete',
) );

// ─────────────────────────────────────────────────────────────
// DISPLAYING TO USERS (always use site timezone)
// ─────────────────────────────────────────────────────────────

// Format for display
$created_at = get_post_meta( $post_id, '_hpm_created_at', true );
echo 'Created: ' . esc_html( Hypercart_Time::format( 'F j, Y g:i A', $created_at ) );
// Output: "Created: December 29, 2024 3:30 PM"

// Show with timezone indicator
echo esc_html(
    Hypercart_Time::format( 'M j, Y g:i A', $created_at ) 
    . ' (' . Hypercart_Time::get_offset_string() . ')'
);
// Output: "Dec 29, 2024 3:30 PM (UTC-8)"

// Human-readable relative time
echo esc_html( Hypercart_Time::human_diff( $created_at ) );
// Output: "2 hours ago"

// ─────────────────────────────────────────────────────────────
// LOG FILES AND DEBUGGING (always use UTC)
// ─────────────────────────────────────────────────────────────

// Log entry timestamp
$log_line = sprintf(
    '[%s] Performance test completed: %dms',
    Hypercart_Time::utc_format( 'Y-m-d H:i:s' ),
    $response_time
);
// Output: "[2024-12-29 23:30:00] Performance test completed: 45ms"

// Log file name with UTC date
$log_file = sprintf(
    'hpm-%s.log',
    Hypercart_Time::utc_format( 'Y-m-d' )
);
// Output: "hpm-2024-12-29.log"

// ─────────────────────────────────────────────────────────────
// API RESPONSES (ISO 8601 UTC)
// ─────────────────────────────────────────────────────────────

return array(
    'status'     => 'healthy',
    'checked_at' => Hypercart_Time::iso8601(),
    'next_check' => Hypercart_Time::iso8601( Hypercart_Time::now() + 3600 ),
);
// Output: { "checked_at": "2024-12-29T23:30:00Z", "next_check": "2024-12-30T00:30:00Z" }

// ─────────────────────────────────────────────────────────────
// PERFORMANCE MONITORING (weekly slots)
// ─────────────────────────────────────────────────────────────

// Get current hourly slot for baseline tracking
$slot = Hypercart_Time::get_weekly_slot();
// On a Wednesday at 3pm UTC: slot = (3 * 24) + 15 = 87

// Store baseline data
update_option( 'hpm_baseline_slot_' . $slot, $metrics, false );

// ─────────────────────────────────────────────────────────────
// TESTING (mock time for deterministic tests)
// ─────────────────────────────────────────────────────────────

// In test setup
Hypercart_Time::set_mock_time( strtotime( '2024-01-15 12:00:00 UTC' ) );

// Run tests - all time functions now return mocked value
$this->assertEquals( 87, Hypercart_Time::get_weekly_slot() );

// In test teardown
Hypercart_Time::reset_mock_time();
```

### 1.5 Enforcement Strategy

**Code Review Checklist:**

```bash
# Check for direct time() calls (should only be in Hypercart_Time)
grep -rn "\btime()" includes/ --include="*.php" | grep -v "Hypercart_Time"

# Check for direct date() calls (should only be in Hypercart_Time)
grep -rn "\bdate(" includes/ --include="*.php" | grep -v "Hypercart_Time"

# Check for deprecated current_time('timestamp')
grep -rn "current_time\s*(" includes/ --include="*.php"

# Check for direct strtotime without timezone
grep -rn "\bstrtotime(" includes/ --include="*.php" | grep -v "Hypercart_Time"
```

**PHPStan Rule (future):**

```neon
# phpstan.neon
parameters:
    customRulesetUsed: true
rules:
    - Hypercart\PHPStan\NoDirectTimeFunctionsRule
```

---

## Part 2: Hypercart_Logger Specification

### 2.1 Problem Statement

WordPress/WooCommerce logging has several issues:

1. **Scattered Approaches**: `error_log()`, WooCommerce logger, custom log tables
2. **Inconsistent Timestamps**: Some use local time, some use UTC, some mix both
3. **No Rotation**: Logs grow unbounded without manual intervention
4. **Poor Context**: Difficult to trace which plugin generated which log entry
5. **Performance**: Database logging adds overhead to every logged event

### 2.2 Design Philosophy

**Key Principles:**

1. **File-Based**: Write to files, not database (no query overhead)
2. **UTC Filenames**: Log files named by UTC date for consistent ordering
3. **UTC Timestamps**: All log entries use UTC for correlation
4. **Plugin Context**: Each entry identifies the source plugin
5. **Automatic Rotation**: One file per day, automatic cleanup of old files
6. **Level Filtering**: Configurable minimum log level
7. **Structured Context**: Optional structured data for debugging

### 2.3 Class Specification

```php
<?php
/**
 * Standardized logging for Hypercart plugin suite
 *
 * DESIGN PRINCIPLES:
 * - File-based logging (no database overhead)
 * - UTC timestamps in filenames and entries
 * - Automatic daily rotation
 * - Plugin context in every entry
 * - Structured context support
 *
 * LOG FORMAT:
 * [2024-12-29 23:30:00 UTC] performance-monitor INFO: Test completed {"duration":45,"type":"db"}
 *
 * FILE NAMING:
 * hypercart-2024-12-29.log (UTC date)
 *
 * @package Hypercart_Helper
 * @since   1.0.0
 */
class Hypercart_Logger {

    /**
     * Log levels (PSR-3 inspired, simplified)
     */
    public const LEVEL_DEBUG   = 0;
    public const LEVEL_INFO    = 1;
    public const LEVEL_WARNING = 2;
    public const LEVEL_ERROR   = 3;

    /**
     * Level names for log output
     *
     * @var array<int, string>
     */
    private static $level_names = array(
        self::LEVEL_DEBUG   => 'DEBUG',
        self::LEVEL_INFO    => 'INFO',
        self::LEVEL_WARNING => 'WARNING',
        self::LEVEL_ERROR   => 'ERROR',
    );

    /**
     * Minimum log level (configurable via filter)
     *
     * @var int|null
     */
    private static $min_level = null;

    /**
     * Log directory path (cached)
     *
     * @var string|null
     */
    private static $log_dir = null;

    /**
     * Log a debug message
     *
     * Use for detailed diagnostic information during development.
     * Typically disabled in production.
     *
     * @since 1.0.0
     * @param string $plugin  Plugin slug (e.g., 'performance-monitor').
     * @param string $message Log message.
     * @param array  $context Optional structured context data.
     * @return bool True if logged, false if filtered or failed.
     */
    public static function debug( string $plugin, string $message, array $context = array() ): bool {
        return self::log( $plugin, self::LEVEL_DEBUG, $message, $context );
    }

    /**
     * Log an info message
     *
     * Use for general operational information.
     * Default level for most logging.
     *
     * @since 1.0.0
     * @param string $plugin  Plugin slug (e.g., 'performance-monitor').
     * @param string $message Log message.
     * @param array  $context Optional structured context data.
     * @return bool True if logged, false if filtered or failed.
     */
    public static function info( string $plugin, string $message, array $context = array() ): bool {
        return self::log( $plugin, self::LEVEL_INFO, $message, $context );
    }

    /**
     * Log a warning message
     *
     * Use for potentially problematic situations that don't prevent operation.
     *
     * @since 1.0.0
     * @param string $plugin  Plugin slug (e.g., 'performance-monitor').
     * @param string $message Log message.
     * @param array  $context Optional structured context data.
     * @return bool True if logged, false if filtered or failed.
     */
    public static function warning( string $plugin, string $message, array $context = array() ): bool {
        return self::log( $plugin, self::LEVEL_WARNING, $message, $context );
    }

    /**
     * Log an error message
     *
     * Use for error conditions that require attention.
     *
     * @since 1.0.0
     * @param string $plugin  Plugin slug (e.g., 'performance-monitor').
     * @param string $message Log message.
     * @param array  $context Optional structured context data.
     * @return bool True if logged, false if filtered or failed.
     */
    public static function error( string $plugin, string $message, array $context = array() ): bool {
        return self::log( $plugin, self::LEVEL_ERROR, $message, $context );
    }

    /**
     * Log a message at specified level
     *
     * Core logging method. All convenience methods route through here.
     *
     * @since 1.0.0
     * @param string $plugin  Plugin slug (e.g., 'performance-monitor').
     * @param int    $level   Log level constant.
     * @param string $message Log message.
     * @param array  $context Optional structured context data.
     * @return bool True if logged, false if filtered or failed.
     */
    public static function log( string $plugin, int $level, string $message, array $context = array() ): bool {
        // Check minimum level
        if ( $level < self::get_min_level() ) {
            return false;
        }

        // Validate level
        if ( ! isset( self::$level_names[ $level ] ) ) {
            $level = self::LEVEL_INFO;
        }

        // Build log entry
        $timestamp  = Hypercart_Time::utc_format( 'Y-m-d H:i:s' );
        $level_name = self::$level_names[ $level ];
        $plugin     = self::sanitize_plugin_slug( $plugin );
        $message    = self::sanitize_message( $message );

        // Format: [2024-12-29 23:30:00 UTC] plugin-slug LEVEL: Message {"context":"data"}
        $log_entry = sprintf(
            '[%s UTC] %s %s: %s',
            $timestamp,
            $plugin,
            $level_name,
            $message
        );

        // Add context if provided
        if ( ! empty( $context ) ) {
            $log_entry .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
        }

        $log_entry .= PHP_EOL;

        // Fire action for external handling (monitoring, alerts, etc.)
        do_action( 'hypercart_log', $plugin, $level, $message, $context, $timestamp );

        // Write to file
        return self::write_to_file( $log_entry );
    }

    /**
     * Get the log directory path
     *
     * Creates directory if it doesn't exist.
     * Default: WP_CONTENT_DIR/hypercart-logs/
     *
     * @since 1.0.0
     * @return string|false Directory path or false on failure.
     */
    public static function get_log_dir() {
        if ( null !== self::$log_dir ) {
            return self::$log_dir;
        }

        /**
         * Filter the Hypercart log directory path
         *
         * @since 1.0.0
         * @param string $log_dir Default log directory path.
         */
        $log_dir = apply_filters(
            'hypercart_log_dir',
            WP_CONTENT_DIR . '/hypercart-logs'
        );

        // Ensure directory exists
        if ( ! file_exists( $log_dir ) ) {
            if ( ! wp_mkdir_p( $log_dir ) ) {
                return false;
            }

            // Create .htaccess to prevent direct access
            $htaccess = $log_dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, 'Deny from all' );
            }

            // Create index.php to prevent directory listing
            $index = $log_dir . '/index.php';
            if ( ! file_exists( $index ) ) {
                file_put_contents( $index, '<?php // Silence is golden.' );
            }
        }

        self::$log_dir = $log_dir;
        return self::$log_dir;
    }

    /**
     * Get current log file path
     *
     * File naming: hypercart-YYYY-MM-DD.log (UTC date)
     *
     * @since 1.0.0
     * @param int|null $timestamp UTC timestamp for date calculation.
     * @return string|false Log file path or false on failure.
     */
    public static function get_log_file( ?int $timestamp = null ): string {
        $log_dir = self::get_log_dir();
        
        if ( false === $log_dir ) {
            return false;
        }

        $date = Hypercart_Time::utc_format( 'Y-m-d', $timestamp );
        
        return $log_dir . '/hypercart-' . $date . '.log';
    }

    /**
     * Get list of all log files
     *
     * Returns array of log files sorted by date (newest first).
     *
     * @since 1.0.0
     * @param bool $include_sizes Whether to include file sizes. Defaults to true.
     * @return array<string, int> Associative array of filename => size in bytes.
     */
    public static function get_log_files( bool $include_sizes = true ): array {
        $log_dir = self::get_log_dir();
        
        if ( false === $log_dir || ! is_dir( $log_dir ) ) {
            return array();
        }

        $files = glob( $log_dir . '/hypercart-*.log' );
        
        if ( false === $files ) {
            return array();
        }

        $result = array();
        
        foreach ( $files as $file ) {
            $result[ basename( $file ) ] = filesize( $file );
        }

        // Sort by filename (date) descending
        krsort( $result );
        
        return $result;
    }

    /**
     * Read log file contents
     *
     * @since 1.0.0
     * @param string|null $filename Log filename (default: today's log).
     * @param int         $lines    Number of lines to return (0 = all, negative = from end).
     * @return string|false Log contents or false on failure.
     */
    public static function read_log( ?string $filename = null, int $lines = 0 ) {
        if ( null === $filename ) {
            $filepath = self::get_log_file();
        } else {
            $log_dir = self::get_log_dir();
            
            if ( false === $log_dir ) {
                return false;
            }
            
            // Sanitize filename to prevent directory traversal
            $filename = basename( $filename );
            
            if ( ! preg_match( '/^hypercart-\d{4}-\d{2}-\d{2}\.log$/', $filename ) ) {
                return false;
            }
            
            $filepath = $log_dir . '/' . $filename;
        }

        if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
            return false;
        }

        if ( 0 === $lines ) {
            return file_get_contents( $filepath );
        }

        // Read specific number of lines
        $file_lines = file( $filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        
        if ( false === $file_lines ) {
            return false;
        }

        if ( $lines < 0 ) {
            // Last N lines
            $file_lines = array_slice( $file_lines, $lines );
        } else {
            // First N lines
            $file_lines = array_slice( $file_lines, 0, $lines );
        }

        return implode( PHP_EOL, $file_lines );
    }

    /**
     * Clean up old log files
     *
     * Removes log files older than specified number of days.
     * Called automatically on 'hypercart_daily_cleanup' cron hook.
     *
     * @since 1.0.0
     * @param int $days_to_keep Number of days of logs to retain (default: 30).
     * @return int Number of files deleted.
     */
    public static function cleanup_old_logs( int $days_to_keep = 30 ): int {
        $log_dir = self::get_log_dir();
        
        if ( false === $log_dir || ! is_dir( $log_dir ) ) {
            return 0;
        }

        $cutoff_date = Hypercart_Time::utc_format(
            'Y-m-d',
            Hypercart_Time::now() - ( $days_to_keep * DAY_IN_SECONDS )
        );

        $files = glob( $log_dir . '/hypercart-*.log' );
        
        if ( false === $files ) {
            return 0;
        }

        $deleted = 0;
        
        foreach ( $files as $file ) {
            // Extract date from filename
            if ( preg_match( '/hypercart-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches ) ) {
                $file_date = $matches[1];
                
                if ( $file_date < $cutoff_date ) {
                    if ( unlink( $file ) ) {
                        $deleted++;
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Clear all logs
     *
     * Removes all log files. Use with caution!
     *
     * @since 1.0.0
     * @return int Number of files deleted.
     */
    public static function clear_all_logs(): int {
        return self::cleanup_old_logs( 0 );
    }

    /**
     * Get minimum log level
     *
     * @since 1.0.0
     * @return int Minimum log level constant.
     */
    public static function get_min_level(): int {
        if ( null !== self::$min_level ) {
            return self::$min_level;
        }

        /**
         * Filter the minimum log level
         *
         * @since 1.0.0
         * @param int $min_level Default minimum level (INFO in production, DEBUG in debug mode).
         */
        $default = defined( 'WP_DEBUG' ) && WP_DEBUG ? self::LEVEL_DEBUG : self::LEVEL_INFO;
        
        self::$min_level = apply_filters( 'hypercart_min_log_level', $default );
        
        return self::$min_level;
    }

    /**
     * Set minimum log level
     *
     * @since 1.0.0
     * @param int $level Minimum log level constant.
     * @return void
     */
    public static function set_min_level( int $level ): void {
        if ( isset( self::$level_names[ $level ] ) ) {
            self::$min_level = $level;
        }
    }

    /**
     * Reset cached values (for testing)
     *
     * @since 1.0.0
     * @return void
     */
    public static function reset(): void {
        self::$min_level = null;
        self::$log_dir   = null;
    }

    /**
     * Write log entry to file
     *
     * @since 1.0.0
     * @param string $entry Log entry string.
     * @return bool True on success, false on failure.
     */
    private static function write_to_file( string $entry ): bool {
        $log_file = self::get_log_file();
        
        if ( false === $log_file ) {
            // Fallback to error_log if file logging fails
            error_log( 'Hypercart: ' . trim( $entry ) );
            return false;
        }

        // Use file locking to prevent race conditions
        $result = file_put_contents(
            $log_file,
            $entry,
            FILE_APPEND | LOCK_EX
        );

        return false !== $result;
    }

    /**
     * Sanitize plugin slug
     *
     * @since 1.0.0
     * @param string $plugin Plugin slug.
     * @return string Sanitized slug.
     */
    private static function sanitize_plugin_slug( string $plugin ): string {
        return sanitize_key( $plugin );
    }

    /**
     * Sanitize log message
     *
     * Removes newlines and limits length to prevent log injection.
     *
     * @since 1.0.0
     * @param string $message Log message.
     * @return string Sanitized message.
     */
    private static function sanitize_message( string $message ): string {
        // Remove newlines to keep single-line log format
        $message = str_replace( array( "\r\n", "\r", "\n" ), ' ', $message );
        
        // Limit length
        if ( strlen( $message ) > 1000 ) {
            $message = substr( $message, 0, 1000 ) . '...';
        }
        
        return $message;
    }
}
```

### 2.4 Usage Examples

```php
<?php
// ─────────────────────────────────────────────────────────────
// BASIC LOGGING
// ─────────────────────────────────────────────────────────────

// Simple info message
Hypercart_Logger::info( 'performance-monitor', 'Hourly test cycle started' );
// Log: [2024-12-29 23:30:00 UTC] performance-monitor INFO: Hourly test cycle started

// Warning with context
Hypercart_Logger::warning( 'performance-monitor', 'Response time exceeds threshold', array(
    'actual_ms'    => 850,
    'threshold_ms' => 500,
    'test_type'    => 'database',
) );
// Log: [2024-12-29 23:30:00 UTC] performance-monitor WARNING: Response time exceeds threshold {"actual_ms":850,"threshold_ms":500,"test_type":"database"}

// Error logging
Hypercart_Logger::error( 'order-monitor', 'Failed to retrieve order count', array(
    'error'    => $wpdb->last_error,
    'query_id' => 'orders_per_hour',
) );

// Debug (only logged when WP_DEBUG is true or level is lowered)
Hypercart_Logger::debug( 'performance-monitor', 'FSM transition', array(
    'from_state' => 'MONITORING',
    'to_state'   => 'ALERT',
    'trigger'    => 'threshold_exceeded',
) );

// Note (v1.1.8+): context values are redacted for known sensitive keys, and a
// _hh_redacted_by marker is added when redaction occurs.

// ─────────────────────────────────────────────────────────────
// LOG FILE MANAGEMENT
// ─────────────────────────────────────────────────────────────

// Get list of all log files (with sizes)
$files = Hypercart_Logger::get_log_files();
// Returns: ['hypercart-2024-12-29.log' => 15420, 'hypercart-2024-12-28.log' => 8730]

// Get list of log files without size calculation
$files = Hypercart_Logger::get_log_files( false );

// Read today's log (last 100 lines)
$recent_logs = Hypercart_Logger::read_log( null, -100 );

// Read specific date's log
$old_logs = Hypercart_Logger::read_log( 'hypercart-2024-12-25.log' );

// Cleanup logs older than 14 days
$deleted = Hypercart_Logger::cleanup_old_logs( 14 );

// ─────────────────────────────────────────────────────────────
// ADMIN UI INTEGRATION
// ─────────────────────────────────────────────────────────────

// In admin page - show log file selector
$files = Hypercart_Logger::get_log_files();

echo '<select name="log_file">';
foreach ( $files as $filename => $size ) {
    $size_kb = round( $size / 1024, 1 );
    echo '<option value="' . esc_attr( $filename ) . '">';
    echo esc_html( $filename ) . ' (' . esc_html( $size_kb ) . ' KB)';
    echo '</option>';
}
echo '</select>';

// Display log contents
$content = Hypercart_Logger::read_log( $_GET['log_file'] ?? null, -200 );
echo '<pre>' . esc_html( $content ) . '</pre>';

// ─────────────────────────────────────────────────────────────
// CRON INTEGRATION
// ─────────────────────────────────────────────────────────────

// Register cleanup cron on activation
register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( 'hypercart_daily_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'hypercart_daily_cleanup' );
    }
} );

// Hook cleanup function
add_action( 'hypercart_daily_cleanup', function() {
    $deleted = Hypercart_Logger::cleanup_old_logs( 30 );
    
    if ( $deleted > 0 ) {
        Hypercart_Logger::info( 'helper', 'Log cleanup completed', array(
            'files_deleted' => $deleted,
        ) );
    }
} );

// ─────────────────────────────────────────────────────────────
// EXTERNAL MONITORING INTEGRATION
// ─────────────────────────────────────────────────────────────

// Hook into log events for external alerting
add_action( 'hypercart_log', function( $plugin, $level, $message, $context, $timestamp ) {
    // Send errors to external monitoring service
    if ( Hypercart_Logger::LEVEL_ERROR === $level ) {
        // Example: Send to Slack, PagerDuty, etc.
        wp_remote_post( 'https://monitoring.example.com/webhook', array(
            'body' => wp_json_encode( array(
                'plugin'    => $plugin,
                'level'     => 'ERROR',
                'message'   => $message,
                'context'   => $context,
                'timestamp' => $timestamp,
                'site'      => get_bloginfo( 'url' ),
            ) ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'blocking' => false, // Non-blocking for performance
        ) );
    }
}, 10, 5 );
```

---

## Chart Rendering (v1.1.0+)

Hypercart Helper includes a lightweight time-series chart helper intended for reuse by other plugins.

### Minimum Version / Version Gate (for consuming plugins)

In a consuming plugin (e.g., Hypercart Performance Monitor), guard against missing chart support:

```php
if ( ! class_exists( 'Hypercart_Charts' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-warning"><p>';
		echo 'Charts require <strong>Hypercart Helper v1.1.0+</strong>';
		echo '</p></div>';
	} );
	return;
}
```

### Basic Usage (admin page)

1) Enqueue assets only on screens that need charts:

```php
Hypercart_Charts::enqueue( array( 'context' => 'admin' ) );
```

2) Render a chart canvas with datasets:

```php
echo Hypercart_Charts::render_canvas(
	array(
		'id' => 'example-chart',
		'title' => 'Example',
		'datasets' => array(
			array(
				'key' => 'series_a',
				'label' => 'Series A',
				'color' => '#2271b1',
				'points' => array(
					array( 'x' => time() - 3600, 'y' => 10 ),
					array( 'x' => time(), 'y' => 13 ),
				),
			),
		),
	)
);
```

Notes:

- Points accept `x` as epoch seconds, epoch milliseconds, or an ISO 8601 string; Helper normalizes to epoch ms.
- Multiple datasets overlay automatically.
- Hover tooltips are enabled by default (Chart.js).

---

## Part 3: Implementation - Helper Plugin

### 3.1 Final Directory Structure

```
hypercart-helper/
├── hypercart-helper.php           # Main plugin file (~50 lines)
├── includes/
│   ├── class-hypercart-time.php   # Time utility class
│   └── class-hypercart-logger.php # Logger utility class
├── uninstall.php                  # Clean removal (~15 lines)
└── README.md                      # Basic docs
```

### 3.2 Main Plugin File

```php
<?php
/**
 * Plugin Name: Hypercart Helper
 * Plugin URI:  https://github.com/neochrome/hypercart-helper
 * Description: Shared utilities for the Hypercart plugin suite. Provides centralized time handling (UTC storage, local display) and structured file-based logging.
 * Version:     1.0.0
 * Author:      Neochrome
 * Author URI:  https://neochrome.dev
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hypercart-helper
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Hypercart_Helper
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'HYPERCART_HELPER_VERSION', '1.0.0' );
define( 'HYPERCART_HELPER_FILE', __FILE__ );
define( 'HYPERCART_HELPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'HYPERCART_HELPER_URL', plugin_dir_url( __FILE__ ) );

// Load utility classes
require_once HYPERCART_HELPER_DIR . 'includes/class-hypercart-time.php';
require_once HYPERCART_HELPER_DIR . 'includes/class-hypercart-logger.php';

/**
 * Activation hook - schedule log cleanup
 */
function hypercart_helper_activate() {
    if ( ! wp_next_scheduled( 'hypercart_daily_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'hypercart_daily_cleanup' );
    }
}
register_activation_hook( __FILE__, 'hypercart_helper_activate' );

/**
 * Deactivation hook - clear scheduled events
 */
function hypercart_helper_deactivate() {
    wp_clear_scheduled_hook( 'hypercart_daily_cleanup' );
}
register_deactivation_hook( __FILE__, 'hypercart_helper_deactivate' );

/**
 * Daily cleanup cron handler
 */
add_action( 'hypercart_daily_cleanup', function() {
    $days_to_keep = apply_filters( 'hypercart_log_retention_days', 30 );
    $deleted = Hypercart_Logger::cleanup_old_logs( $days_to_keep );
    
    if ( $deleted > 0 ) {
        Hypercart_Logger::info( 'helper', 'Log cleanup completed', array(
            'files_deleted' => $deleted,
            'retention_days' => $days_to_keep,
        ) );
    }
} );

/**
 * Log when Helper loads (debug only)
 */
add_action( 'plugins_loaded', function() {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        Hypercart_Logger::debug( 'helper', 'Hypercart Helper loaded', array(
            'version' => HYPERCART_HELPER_VERSION,
        ) );
    }
}, 5 ); // Priority 5 = load early so dependents can use it
```

### 3.3 Uninstall File

```php
<?php
/**
 * Hypercart Helper Uninstall
 *
 * Removes log files and cleans up when plugin is deleted.
 *
 * @package Hypercart_Helper
 */

// Prevent direct access
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove log directory and all contents
$log_dir = WP_CONTENT_DIR . '/hypercart-logs';

if ( is_dir( $log_dir ) ) {
    $files = glob( $log_dir . '/*' );
    
    if ( $files ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file );
            }
        }
    }
    
    rmdir( $log_dir );
}

// Clear any scheduled events (in case deactivation didn't run)
wp_clear_scheduled_hook( 'hypercart_daily_cleanup' );
```

### 3.4 Dependent Plugin Check (For HPM and other plugins)

```php
<?php
/**
 * Check for Hypercart Helper dependency
 *
 * Call this early in your plugin's main file before loading any code
 * that depends on Hypercart_Time or Hypercart_Logger.
 *
 * @return bool True if Helper is available and meets version requirement.
 */
function hpm_check_helper_dependency() {
    $required_version = '1.0.0';
    
    // Check if Helper classes exist
    if ( ! class_exists( 'Hypercart_Time' ) || ! class_exists( 'Hypercart_Logger' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e( 'Hypercart Performance Monitor', 'hypercart-performance-monitor' ); ?></strong>
                    <?php esc_html_e( 'requires', 'hypercart-performance-monitor' ); ?>
                    <strong><?php esc_html_e( 'Hypercart Helper', 'hypercart-performance-monitor' ); ?></strong>
                    <?php esc_html_e( 'plugin to be installed and activated.', 'hypercart-performance-monitor' ); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=hypercart+helper&tab=search' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Install Hypercart Helper', 'hypercart-performance-monitor' ); ?>
                    </a>
                </p>
            </div>
            <?php
        } );
        return false;
    }

    // Check version requirement
    if ( ! defined( 'HYPERCART_HELPER_VERSION' ) || 
         version_compare( HYPERCART_HELPER_VERSION, $required_version, '<' ) ) {
        add_action( 'admin_notices', function() use ( $required_version ) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e( 'Hypercart Performance Monitor', 'hypercart-performance-monitor' ); ?></strong>
                    <?php 
                    printf(
                        /* translators: %s: required version number */
                        esc_html__( 'requires Hypercart Helper version %s or higher. Please update Hypercart Helper.', 'hypercart-performance-monitor' ),
                        esc_html( $required_version )
                    ); 
                    ?>
                </p>
            </div>
            <?php
        } );
        return false;
    }

    return true;
}

// Usage in HPM main file:
// if ( ! hpm_check_helper_dependency() ) {
//     return; // Don't load plugin without Helper
// }
```

---

## Part 4: Test Strategy

### 4.1 Hypercart_Time Tests

```php
<?php
class Test_Hypercart_Time extends WP_UnitTestCase {

    public function tearDown(): void {
        Hypercart_Time::reset_mock_time();
        parent::tearDown();
    }

    public function test_now_returns_integer() {
        $this->assertIsInt( Hypercart_Time::now() );
    }

    public function test_mock_time_freezes_time() {
        $mock_timestamp = strtotime( '2024-01-15 12:00:00 UTC' );
        Hypercart_Time::set_mock_time( $mock_timestamp );

        $this->assertEquals( $mock_timestamp, Hypercart_Time::now() );
        
        // Subsequent calls return same value
        sleep( 1 );
        $this->assertEquals( $mock_timestamp, Hypercart_Time::now() );
    }

    public function test_format_uses_site_timezone() {
        update_option( 'timezone_string', 'America/Los_Angeles' );
        
        $utc_noon = strtotime( '2024-06-15 12:00:00 UTC' );
        Hypercart_Time::set_mock_time( $utc_noon );

        // LA is UTC-7 in summer (PDT)
        $formatted = Hypercart_Time::format( 'H:i', $utc_noon );
        $this->assertEquals( '05:00', $formatted );
    }

    public function test_utc_format_ignores_site_timezone() {
        update_option( 'timezone_string', 'America/Los_Angeles' );
        
        $utc_noon = strtotime( '2024-06-15 12:00:00 UTC' );

        $formatted = Hypercart_Time::utc_format( 'H:i', $utc_noon );
        $this->assertEquals( '12:00', $formatted );
    }

    public function test_weekly_slot_calculation() {
        // Sunday 00:00 UTC = slot 0
        $sunday_midnight = strtotime( '2024-01-07 00:00:00 UTC' );
        $this->assertEquals( 0, Hypercart_Time::get_weekly_slot( $sunday_midnight ) );

        // Wednesday 15:00 UTC = slot 87
        $wed_3pm = strtotime( '2024-01-10 15:00:00 UTC' );
        $this->assertEquals( 87, Hypercart_Time::get_weekly_slot( $wed_3pm ) );

        // Saturday 23:00 UTC = slot 167
        $sat_11pm = strtotime( '2024-01-13 23:00:00 UTC' );
        $this->assertEquals( 167, Hypercart_Time::get_weekly_slot( $sat_11pm ) );
    }

    public function test_iso8601_format() {
        $timestamp = strtotime( '2024-12-29 15:30:45 UTC' );
        
        $iso = Hypercart_Time::iso8601( $timestamp );
        
        $this->assertEquals( '2024-12-29T15:30:45Z', $iso );
    }

    public function test_parse_handles_site_timezone() {
        update_option( 'timezone_string', 'America/New_York' );
        
        // Parse a local time string
        $timestamp = Hypercart_Time::parse( '2024-06-15 12:00:00' );
        
        // NY is UTC-4 in summer (EDT), so 12:00 EDT = 16:00 UTC
        $expected = strtotime( '2024-06-15 16:00:00 UTC' );
        
        $this->assertEquals( $expected, $timestamp );
    }
}
```

### 4.2 Hypercart_Logger Tests

```php
<?php
class Test_Hypercart_Logger extends WP_UnitTestCase {

    private $test_log_dir;

    public function setUp(): void {
        parent::setUp();
        
        $this->test_log_dir = WP_CONTENT_DIR . '/hypercart-logs-test';
        
        add_filter( 'hypercart_log_dir', function() {
            return $this->test_log_dir;
        } );
        
        Hypercart_Logger::reset();
        Hypercart_Time::set_mock_time( strtotime( '2024-06-15 12:00:00 UTC' ) );
    }

    public function tearDown(): void {
        // Clean up test log directory
        if ( is_dir( $this->test_log_dir ) ) {
            array_map( 'unlink', glob( $this->test_log_dir . '/*' ) );
            rmdir( $this->test_log_dir );
        }
        
        Hypercart_Time::reset_mock_time();
        Hypercart_Logger::reset();
        parent::tearDown();
    }

    public function test_log_creates_file_with_utc_date() {
        Hypercart_Logger::info( 'test-plugin', 'Test message' );

        $expected_file = $this->test_log_dir . '/hypercart-2024-06-15.log';
        $this->assertFileExists( $expected_file );
    }

    public function test_log_format() {
        Hypercart_Logger::info( 'test-plugin', 'Test message', array( 'key' => 'value' ) );

        $content = Hypercart_Logger::read_log();
        
        $this->assertStringContainsString( '[2024-06-15 12:00:00 UTC]', $content );
        $this->assertStringContainsString( 'test-plugin', $content );
        $this->assertStringContainsString( 'INFO:', $content );
        $this->assertStringContainsString( 'Test message', $content );
        $this->assertStringContainsString( '{"key":"value"}', $content );
    }

    public function test_debug_filtered_by_default() {
        Hypercart_Logger::set_min_level( Hypercart_Logger::LEVEL_INFO );
        
        $result = Hypercart_Logger::debug( 'test-plugin', 'Debug message' );
        
        $this->assertFalse( $result );
    }

    public function test_cleanup_removes_old_files() {
        // Create old log file
        $old_file = $this->test_log_dir . '/hypercart-2024-01-01.log';
        wp_mkdir_p( $this->test_log_dir );
        file_put_contents( $old_file, 'old log content' );

        // Current mock time is 2024-06-15, so 30 days keeps back to ~May 16
        $deleted = Hypercart_Logger::cleanup_old_logs( 30 );

        $this->assertEquals( 1, $deleted );
        $this->assertFileDoesNotExist( $old_file );
    }

    public function test_sanitizes_plugin_slug() {
        Hypercart_Logger::info( 'Test Plugin!!', 'Message' );
        
        $content = Hypercart_Logger::read_log();
        
        $this->assertStringContainsString( 'testplugin', $content );
        $this->assertStringNotContainsString( '!!', $content );
    }

    public function test_fires_action_hook() {
        $captured = null;
        
        add_action( 'hypercart_log', function( $plugin, $level, $message ) use ( &$captured ) {
            $captured = compact( 'plugin', 'level', 'message' );
        }, 10, 3 );

        Hypercart_Logger::warning( 'my-plugin', 'Warning message' );

        $this->assertEquals( 'my-plugin', $captured['plugin'] );
        $this->assertEquals( Hypercart_Logger::LEVEL_WARNING, $captured['level'] );
        $this->assertEquals( 'Warning message', $captured['message'] );
    }
}
```

---

## Part 5: Summary & Next Steps

### 5.1 What We're Building

| Component | Purpose | Key Feature |
|-----------|---------|-------------|
| Hypercart_Time | UTC-based time management | Store UTC, display local |
| Hypercart_Logger | File-based structured logging | UTC filenames, auto-rotation |

### 5.2 Build Order

1. **Now: Hypercart Helper v1.0** (this spec) - 2-3 hour timebox
2. **Next: Hypercart Performance Monitor v1.0** - depends on Helper
3. **Future: Additional Hypercart plugins** - all depend on Helper

### 5.3 Definition of Done - Helper v1.0

- [ ] `hypercart-helper.php` main plugin file created
- [ ] `class-hypercart-time.php` implemented per spec
- [ ] `class-hypercart-logger.php` implemented per spec
- [ ] `uninstall.php` cleans up logs on delete
- [ ] Plugin activates without errors
- [ ] Plugin deactivates cleanly
- [ ] Basic smoke test: log entry appears in file with correct UTC timestamp
- [ ] Basic smoke test: `Hypercart_Time::format()` shows site timezone

### 5.4 Abort Criteria

If any of these happen within the 3-hour timebox, abort and inline into HPM:

- Can't get basic plugin structure working
- Unexpected WordPress dependency issues
- Scope creeping beyond the two utility classes
- Spending more than 30 min on any single issue

---

## Part 6: For Developers - HPM Integration Guide

> **Copy this entire section into your VS Code AI agent context when building Hypercart Performance Monitor.**

### HPM Dependency on Hypercart Helper

Hypercart Performance Monitor (HPM) depends on the **Hypercart Helper** plugin for time handling and logging. Helper must be installed and activated before HPM will load.

### Available Utilities from Hypercart Helper

#### Hypercart_Time - Centralized Time Management

**Golden Rule: Store UTC, Display Local**

```php
<?php
// ═══════════════════════════════════════════════════════════════
// STORING DATA - Always use UTC timestamps
// ═══════════════════════════════════════════════════════════════

// Get current UTC timestamp (this is the ONLY way to get current time)
$now = Hypercart_Time::now();

// Store in database
update_option( 'hpm_last_run', Hypercart_Time::now(), false );
update_post_meta( $post_id, '_hpm_created_at', Hypercart_Time::now() );

// For MySQL DATETIME columns (still UTC)
$wpdb->insert( 'wp_hpm_metrics', array(
    'recorded_at' => Hypercart_Time::mysql_utc(),
    'value'       => $metric_value,
) );

// ═══════════════════════════════════════════════════════════════
// DISPLAYING TO USERS - Always use site timezone
// ═══════════════════════════════════════════════════════════════

// Format for display (automatically converts to site timezone)
echo esc_html( Hypercart_Time::format( 'F j, Y g:i A', $timestamp ) );
// Output: "December 29, 2024 3:30 PM" (in site timezone)

// Human-readable relative time
echo esc_html( Hypercart_Time::human_diff( $timestamp ) );
// Output: "2 hours ago"

// Show timezone indicator
echo esc_html( Hypercart_Time::format( 'M j, g:i A', $timestamp ) );
echo ' (' . esc_html( Hypercart_Time::get_offset_string() ) . ')';
// Output: "Dec 29, 3:30 PM (UTC-8)"

// ═══════════════════════════════════════════════════════════════
// LOGS AND DEBUGGING - Always use UTC
// ═══════════════════════════════════════════════════════════════

// UTC formatted string for logs
$log_timestamp = Hypercart_Time::utc_format( 'Y-m-d H:i:s' );
// Output: "2024-12-29 23:30:00" (always UTC)

// ═══════════════════════════════════════════════════════════════
// API RESPONSES - Use ISO 8601 with Z suffix
// ═══════════════════════════════════════════════════════════════

return array(
    'status'     => 'healthy',
    'checked_at' => Hypercart_Time::iso8601(),
    // Output: "2024-12-29T23:30:00Z"
);

// ═══════════════════════════════════════════════════════════════
// WEEKLY SLOT CALCULATION (for 168-hour baseline tracking)
// ═══════════════════════════════════════════════════════════════

// Get current hourly slot (0-167)
$slot = Hypercart_Time::get_weekly_slot();
// Sunday 00:00 UTC = 0, Saturday 23:00 UTC = 167

// Store/retrieve baseline data by slot
$baseline_key = 'hpm_baseline_slot_' . $slot;

// ═══════════════════════════════════════════════════════════════
// TESTING - Mock time for deterministic tests
// ═══════════════════════════════════════════════════════════════

// In test setup
Hypercart_Time::set_mock_time( strtotime( '2024-06-15 12:00:00 UTC' ) );

// All time functions now return mocked value
$this->assertEquals( 87, Hypercart_Time::get_weekly_slot() );

// In test teardown
Hypercart_Time::reset_mock_time();
```

**Available Methods:**

| Method | Returns | Use For |
|--------|---------|---------|
| `now()` | `int` | Current UTC timestamp (storage, comparisons) |
| `format($format, $timestamp)` | `string` | User display (site timezone) |
| `utc_format($format, $timestamp)` | `string` | Logs, debugging (UTC) |
| `iso8601($timestamp)` | `string` | API responses |
| `mysql_utc($timestamp)` | `string` | Database DATETIME columns |
| `mysql_local($timestamp)` | `string` | Display in MySQL format |
| `to_datetime($timestamp)` | `DateTimeImmutable` | Advanced manipulation (site TZ) |
| `to_utc_datetime($timestamp)` | `DateTimeImmutable` | Advanced manipulation (UTC) |
| `parse($date_string, $timezone)` | `int\|false` | Parse string to UTC timestamp |
| `get_offset_string($timestamp)` | `string` | Display like "UTC-8" |
| `get_timezone_name()` | `string` | Display like "America/Los_Angeles" |
| `is_past($timestamp)` | `bool` | Check if before now |
| `is_future($timestamp)` | `bool` | Check if after now |
| `start_of_day_utc($timestamp)` | `int` | Midnight UTC |
| `start_of_day_local($timestamp)` | `int` | Midnight site timezone |
| `get_hour_utc($timestamp)` | `int` | Hour 0-23 in UTC |
| `get_day_of_week_utc($timestamp)` | `int` | Day 0-6 (Sun-Sat) in UTC |
| `get_weekly_slot($timestamp)` | `int` | Slot 0-167 for baseline tracking |
| `human_diff($timestamp, $reference)` | `string` | "2 hours ago" / "in 3 days" |
| `set_mock_time($timestamp)` | `void` | Testing only |
| `reset_mock_time()` | `void` | Testing only |

#### Hypercart_Logger - Structured File Logging

```php
<?php
// ═══════════════════════════════════════════════════════════════
// BASIC LOGGING
// ═══════════════════════════════════════════════════════════════

// Info level (default for operational messages)
Hypercart_Logger::info( 'performance-monitor', 'Hourly test cycle started' );
// Log: [2024-12-29 23:30:00 UTC] performance-monitor INFO: Hourly test cycle started

// Warning level (potential issues)
Hypercart_Logger::warning( 'performance-monitor', 'Response time elevated', array(
    'actual_ms'    => 450,
    'threshold_ms' => 500,
) );
// Log: [2024-12-29 23:30:00 UTC] performance-monitor WARNING: Response time elevated {"actual_ms":450,"threshold_ms":500}

// Error level (requires attention)
Hypercart_Logger::error( 'performance-monitor', 'Database test failed', array(
    'error' => $wpdb->last_error,
) );

// Debug level (only logged when WP_DEBUG is true)
Hypercart_Logger::debug( 'performance-monitor', 'FSM state transition', array(
    'from' => 'MONITORING',
    'to'   => 'ALERT',
) );

// ═══════════════════════════════════════════════════════════════
// LOG FILE MANAGEMENT (for admin UI)
// ═══════════════════════════════════════════════════════════════

// Get list of log files
$files = Hypercart_Logger::get_log_files();
// Returns: ['hypercart-2024-12-29.log' => 15420, 'hypercart-2024-12-28.log' => 8730]

// Read log contents (last 100 lines)
$content = Hypercart_Logger::read_log( null, -100 );

// Read specific file
$content = Hypercart_Logger::read_log( 'hypercart-2024-12-25.log' );
```

**Available Methods:**

| Method | Returns | Use For |
|--------|---------|---------|
| `debug($plugin, $message, $context)` | `bool` | Detailed diagnostics (dev only) |
| `info($plugin, $message, $context)` | `bool` | Normal operations |
| `warning($plugin, $message, $context)` | `bool` | Potential issues |
| `error($plugin, $message, $context)` | `bool` | Errors requiring attention |
| `log($plugin, $level, $message, $context)` | `bool` | Generic with level constant |
| `get_log_files($include_sizes)` | `array` | List files (optionally include sizes) |
| `read_log($filename, $lines)` | `string\|false` | Read log contents |
| `cleanup_old_logs($days)` | `int` | Remove old files |
| `get_log_dir()` | `string\|false` | Log directory path |
| `get_log_file($timestamp)` | `string\|false` | Specific log file path |

### HPM Dependency Check

Add this to the top of `hypercart-performance-monitor.php`:

```php
<?php
/**
 * Check for Hypercart Helper dependency
 */
function hpm_check_helper_dependency() {
    if ( ! class_exists( 'Hypercart_Time' ) || ! class_exists( 'Hypercart_Logger' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong>Hypercart Performance Monitor</strong> requires 
                    <strong>Hypercart Helper</strong> plugin to be installed and activated.
                </p>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=hypercart+helper&tab=search' ) ); ?>" class="button button-primary">
                        Install Hypercart Helper
                    </a>
                </p>
            </div>
            <?php
        } );
        return false;
    }

    if ( version_compare( HYPERCART_HELPER_VERSION, '1.0.0', '<' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong>Hypercart Performance Monitor</strong> requires 
                    <strong>Hypercart Helper v1.0.0</strong> or higher.
                </p>
            </div>
            <?php
        } );
        return false;
    }

    return true;
}

// Early in main plugin file, before any other code:
if ( ! hpm_check_helper_dependency() ) {
    return; // Don't load plugin
}
```

### Code Review Enforcement

HPM code must NOT use direct time functions. Run these checks before committing:

```bash
# Should return ZERO results (all time calls go through Hypercart_Time)
grep -rn "\btime()" includes/ --include="*.php"
grep -rn "\bdate(" includes/ --include="*.php"
grep -rn "current_time(" includes/ --include="*.php"
grep -rn "\bstrtotime(" includes/ --include="*.php"

# Should return ZERO results (all logging goes through Hypercart_Logger)
grep -rn "error_log(" includes/ --include="*.php"
```

### Key Integration Points in HPM

1. **FSM State Changes** → Log with `Hypercart_Logger::info()`
2. **Test Results Storage** → Timestamp with `Hypercart_Time::now()`
3. **Baseline Slot Lookup** → Use `Hypercart_Time::get_weekly_slot()`
4. **Admin Display** → Format with `Hypercart_Time::format()`
5. **API Responses** → Use `Hypercart_Time::iso8601()`
6. **Error Conditions** → Log with `Hypercart_Logger::error()`

---

## Document History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2024-12-29 | Noel @ Neochrome | Initial specification |
| 1.0.1 | 2024-12-29 | Noel @ Neochrome | Updated to Helper-first approach, added For Developers section |

---

**Ready for Implementation**

Build Hypercart Helper first (2-3 hour timebox), then proceed to HPM with Helper as a dependency.
