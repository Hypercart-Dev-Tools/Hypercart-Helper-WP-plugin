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
 *
 * @warning SECURITY WARNING: Log files can become a significant security risk if they contain sensitive
 *          information. It is the developer's responsibility to sanitize all logged data and to ensure
 *          the log directory is not publicly accessible via the web, especially if logging anything
 *          other than benign operational data. Use of this feature is at your own risk.
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hypercart_Logger {

	/**
	 * Log levels (PSR-3 inspired, simplified)
	 */
	public const LEVEL_DEBUG   = 0;
	public const LEVEL_INFO    = 1;
	public const LEVEL_WARNING = 2;
	public const LEVEL_ERROR   = 3;

	/**
	 * Maximum recursion depth when normalizing/redacting structured values.
	 *
	 * Bounds the recursion in redact_context()/normalize_log_value() so a
	 * circular (self-referencing) or pathologically deep array/object graph
	 * cannot exhaust memory and fatal the request. See issue #8.
	 */
	private const MAX_NORMALIZE_DEPTH = 8;

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
	 * @param mixed  $message Log message. Non-strings are coerced; see log().
	 * @param array        $context Optional structured context data.
	 * @return bool True if logged, false if filtered or failed.
	 */
	public static function debug( string $plugin, $message, array $context = array() ): bool {
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
	 * @param mixed  $message Log message. Non-strings are coerced; see log().
	 * @param array        $context Optional structured context data.
	 * @return bool True if logged, false if filtered or failed.
	 */
	public static function info( string $plugin, $message, array $context = array() ): bool {
		return self::log( $plugin, self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Log a warning message
	 *
	 * Use for potentially problematic situations that don't prevent operation.
	 *
	 * @since 1.0.0
	 * @param string $plugin  Plugin slug (e.g., 'performance-monitor').
	 * @param mixed  $message Log message. Non-strings are coerced; see log().
	 * @param array        $context Optional structured context data.
	 * @return bool True if logged, false if filtered or failed.
	 */
	public static function warning( string $plugin, $message, array $context = array() ): bool {
		return self::log( $plugin, self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * Log an error message
	 *
	 * Use for error conditions that require attention.
	 *
	 * @since 1.0.0
	 * @param string $plugin  Plugin slug (e.g., 'performance-monitor').
	 * @param mixed  $message Log message. Non-strings are coerced; see log().
	 * @param array        $context Optional structured context data.
	 * @return bool True if logged, false if filtered or failed.
	 */
	public static function error( string $plugin, $message, array $context = array() ): bool {
		return self::log( $plugin, self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Log a message at specified level
	 *
	 * Core logging method. All convenience methods route through here.
	 *
	 * If $message is not a string (e.g. an array was passed by mistake) it is
	 * JSON-encoded and a '_hh_coerced_type' key is added to $context so the
	 * caller can be identified in the log. This prevents a fatal TypeError from
	 * crashing the site when a caller passes a non-string value.
	 *
	 * @since 1.0.0
	 *
	 * @warning Do not pass sensitive information (e.g., API keys, passwords, PII) in the `$context`
	 *          array without sanitizing it first. This data is written directly to the log file.
	 *          Use of this feature is at your own risk.
	 *
	 * @param string $plugin  Plugin slug (e.g., 'performance-monitor').
	 * @param int    $level   Log level constant.
	 * @param mixed  $message Log message. Non-strings are coerced to JSON after redaction-aware normalization.
	 * @param array        $context Optional structured context data.
	 * @return bool True if logged, false if filtered or failed.
	 */
	public static function log( string $plugin, int $level, $message, array $context = array() ): bool {
		// Validate level
		if ( ! isset( self::$level_names[ $level ] ) ) {
			$level = self::LEVEL_INFO;
		}

		// Check minimum level before doing message normalization work.
		if ( $level < self::get_min_level() ) {
			return false;
		}

		$context_redacted = false;

		// Coerce non-string $message to prevent a fatal TypeError from crashing
		// the site when a caller (e.g. another plugin) passes the wrong type.
		if ( ! is_string( $message ) ) {
			$context['_hh_coerced_type'] = gettype( $message );
			$message                     = self::normalize_message( $message, $context_redacted );
		}

		// Build log entry
		$timestamp  = Hypercart_Time::utc_format( 'Y-m-d H:i:s' );
		$level_name = self::$level_names[ $level ];
		$plugin     = self::sanitize_plugin_slug( $plugin );
		$message    = self::sanitize_message( $message );

		// Redact sensitive context values (comment out this block to disable redaction).
		if ( ! empty( $context ) ) {
			$context = self::redact_context( $context, $context_redacted );
			if ( $context_redacted && ! isset( $context['_hh_redacted_by'] ) ) {
				$context['_hh_redacted_by'] = 'hypercart-helper';
			}
		}

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
	 * Redact sensitive context values based on known keys.
	 *
	 * @since 1.1.8
	 * @param array $context Context array.
	 * @param bool  $redacted Set to true if any redaction occurred.
	 * @param int   $depth   Current recursion depth (internal).
	 * @return array Redacted context.
	 */
	private static function redact_context( array $context, bool &$redacted = false, int $depth = 0 ): array {
		$keywords = array(
			'password',
			'passwd',
			'passphrase',
			'token',
			'secret',
			'authorization',
			'api_key',
			'apikey',
			'access_token',
			'refresh_token',
		);

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) ) {
				$key_lc = strtolower( $key );
				foreach ( $keywords as $keyword ) {
					if ( false !== strpos( $key_lc, $keyword ) ) {
						$context[ $key ] = '[REDACTED]';
						$redacted = true;
						continue 2;
					}
				}
			}


			$context[ $key ] = self::normalize_log_value( $value, $redacted, $depth );
		}

		return $context;
	}

	/**
	 * Normalize non-string log messages into a safe single-line string.
	 *
	 * @since 1.1.16
	 * @param mixed $message Raw message value.
	 * @param bool  $redacted Set to true if any redaction occurred.
	 * @return string Normalized log message.
	 */
	private static function normalize_message( $message, bool &$redacted = false ): string {
		$normalized = self::normalize_log_value( $message, $redacted );
		$encoded    = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES );

		return ( false !== $encoded )
			? $encoded
			: '[unserializable ' . gettype( $message ) . ']';
	}

	/**
	 * Normalize log values while preserving redaction for structured data.
	 *
	 * @since 1.1.16
	 * @param mixed $value Raw log value.
	 * @param bool  $redacted Set to true if any redaction occurred.
	 * @param int   $depth   Current recursion depth (internal).
	 * @return mixed Normalized log value.
	 */
	private static function normalize_log_value( $value, bool &$redacted = false, int $depth = 0 ) {
		// Guard against circular references and pathologically deep graphs so a
		// bad caller value cannot recurse until memory is exhausted. See issue #8.
		if ( $depth >= self::MAX_NORMALIZE_DEPTH ) {
			return ( is_array( $value ) || is_object( $value ) )
				? '[max depth exceeded]'
				: $value;
		}

		if ( is_array( $value ) ) {
			return self::redact_context( $value, $redacted, $depth + 1 );
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof JsonSerializable ) {
				return self::normalize_log_value( $value->jsonSerialize(), $redacted, $depth + 1 );
			}

			$public_properties = get_object_vars( $value );

			if ( ! empty( $public_properties ) ) {
				return self::redact_context( $public_properties, $redacted, $depth + 1 );
			}
		}

		return $value;
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
		 * @warning Developers using this filter are responsible for ensuring the specified directory
		 *          is properly secured against public web access.
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
	public static function get_log_file( ?int $timestamp = null ) {
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

		$include_sizes = (bool) apply_filters( 'hypercart_log_files_include_sizes', $include_sizes );
		$cache_ttl     = (int) apply_filters( 'hypercart_log_files_cache_ttl', 60 );

		if ( $cache_ttl > 0 && is_admin() ) {
			$cache_key = 'hh_log_files_' . md5( $log_dir . '|' . ( $include_sizes ? '1' : '0' ) );
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$files = glob( $log_dir . '/hypercart-*.log' );

		if ( false === $files ) {
			return array();
		}

		$result = array();

		foreach ( $files as $file ) {
			$filename = basename( $file );
			if ( $include_sizes ) {
				$size = filesize( $file );
				$result[ $filename ] = is_int( $size ) ? $size : 0;
			} else {
				$result[ $filename ] = 0;
			}
		}

		// Sort by filename (date) descending
		krsort( $result );

		if ( $cache_ttl > 0 && is_admin() && isset( $cache_key ) ) {
			set_transient( $cache_key, $result, $cache_ttl );
		}

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

		$max_lines = (int) apply_filters( 'hypercart_log_read_max_lines', 5000 );
		if ( $max_lines < 1 ) {
			$max_lines = 5000;
		}

		if ( 0 !== $lines ) {
			$abs_lines = abs( $lines );
			if ( $abs_lines > $max_lines ) {
				$lines = ( $lines < 0 ) ? ( -1 * $max_lines ) : $max_lines;
			}
		}

		$default_max_bytes = defined( 'MB_IN_BYTES' ) ? ( 5 * MB_IN_BYTES ) : ( 5 * 1024 * 1024 );
		$max_bytes         = (int) apply_filters( 'hypercart_log_read_max_bytes', $default_max_bytes );
		if ( $max_bytes < 1 ) {
			$max_bytes = $default_max_bytes;
		}

		$filesize = filesize( $filepath );
		if ( is_admin() && 0 === $lines && is_int( $filesize ) && $filesize > $max_bytes ) {
			return false;
		}

		if ( 0 === $lines ) {
			return file_get_contents( $filepath );
		}

		if ( $lines < 0 ) {
			return self::read_last_lines( $filepath, abs( $lines ), $max_bytes );
		}

		return self::read_first_lines( $filepath, $lines, $max_bytes );
	}

	/**
	 * Read the first N non-empty lines from a file.
	 *
	 * @since 1.1.6
	 * @param string $filepath  Absolute file path.
	 * @param int    $lines     Number of lines to read.
	 * @param int    $max_bytes Maximum bytes to scan before stopping.
	 * @return string|false Log contents or false on failure.
	 */
	private static function read_first_lines( string $filepath, int $lines, int $max_bytes = 0 ) {
		$handle = fopen( $filepath, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		$output     = array();
		$bytes_read = 0;

		while ( ! feof( $handle ) && count( $output ) < $lines ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}

			$bytes_read += strlen( $line );
			if ( $max_bytes > 0 && $bytes_read > $max_bytes ) {
				break;
			}

			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}

			$output[] = $line;
		}

		fclose( $handle );

		return implode( PHP_EOL, $output );
	}

	/**
	 * Read the last N non-empty lines from a file without loading it all into memory.
	 *
	 * @since 1.1.6
	 * @param string $filepath  Absolute file path.
	 * @param int    $lines     Number of lines to read.
	 * @param int    $max_bytes Maximum bytes to scan from the end.
	 * @return string|false Log contents or false on failure.
	 */
	private static function read_last_lines( string $filepath, int $lines, int $max_bytes = 0 ) {
		$handle = fopen( $filepath, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		$filesize = filesize( $filepath );
		if ( ! is_int( $filesize ) || $filesize <= 0 ) {
			fclose( $handle );
			return '';
		}

		$chunk_size = 8192;
		$buffer     = '';
		$bytes_read = 0;
		$limit      = ( $max_bytes > 0 ) ? min( $max_bytes, $filesize ) : $filesize;

		while ( $bytes_read < $limit && substr_count( $buffer, "\n" ) <= $lines ) {
			$read_size = min( $chunk_size, $limit - $bytes_read );
			$seek      = $filesize - $bytes_read - $read_size;

			if ( $seek < 0 ) {
				$read_size += $seek;
				$seek = 0;
			}

			if ( fseek( $handle, $seek ) !== 0 ) {
				break;
			}

			$chunk = fread( $handle, $read_size );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$buffer     = $chunk . $buffer;
			$bytes_read += $read_size;

			if ( 0 === $seek ) {
				break;
			}
		}

		fclose( $handle );

		$parts = preg_split( "/\r?\n/", $buffer );
		$lines_out = array();
		foreach ( $parts as $part ) {
			$part = rtrim( $part, "\r\n" );
			if ( '' === $part ) {
				continue;
			}
			$lines_out[] = $part;
		}

		if ( count( $lines_out ) > $lines ) {
			$lines_out = array_slice( $lines_out, -1 * $lines );
		}

		return implode( PHP_EOL, $lines_out );
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
