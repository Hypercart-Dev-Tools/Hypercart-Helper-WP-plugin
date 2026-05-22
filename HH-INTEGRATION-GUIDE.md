# Hypercart Helper API Integration Guide

**Version:** 1.1.16
**Last Updated:** 2026-05-21
**Audience:** Plugin developers and LLMs consuming Hypercart Helper utilities

---

## Table of Contents

- [Quick Start](#quick-start)
- [Dependency Check](#dependency-check)
- [Hypercart_Time API](#hypercart_time-api)
  - [Core Methods](#time-core-methods)
  - [Formatting Methods](#time-formatting-methods)
  - [Parsing Methods](#time-parsing-methods)
  - [Utility Methods](#time-utility-methods)
  - [Testing Methods](#time-testing-methods)
- [Hypercart_Logger API](#hypercart_logger-api)
  - [Logging Methods](#logger-logging-methods)
  - [File Management](#logger-file-management)
  - [Configuration](#logger-configuration)
  - [Security & Disclaimer](#logger-security--disclaimer)
- [Hypercart_Charts API](#hypercart_charts-api)
  - [Dependencies / Design Notes](#charts-dependencies-design-notes)
  - [Asset Management](#charts-asset-management)
  - [Data Model](#charts-data-model)
  - [Data Preparation](#charts-data-preparation)
  - [Multi-Dataset Overlays](#charts-multi-dataset-overlays)
  - [Rendering](#charts-rendering)
  - [Hover Tooltips](#charts-hover-tooltips)
  - [Hooks & Filters](#charts-hooks-filters)
  - [Demo JSON File](#charts-demo-json-file)
  - [Implementation Notes / Caveats](#charts-implementation-notes-caveats)
- [Hypercart_Markdown_Viewer API](#hypercart_markdown_viewer-api)
  - [Shortcode](#markdown-shortcode)
  - [PHP Usage](#markdown-php-usage)
  - [Security Model](#markdown-security-model)
  - [Hooks & Filters](#markdown-hooks-filters)
- [Admin UI Helpers](#admin-ui-helpers)
  - [Tabbed Navigation (Hypercart_Admin_Tabs)](#tabbed-navigation-hypercart_admin_tabs)
- [WordPress Hooks](#wordpress-hooks)
- [Best Practices](#best-practices)
- [Examples](#examples)

---

## Admin UI Helpers

### Tabbed Navigation (`Hypercart_Admin_Tabs`)

Use this helper to render a WordPress-style tab strip (`nav-tab-wrapper`) with Dashicons, active tab highlighting, and optional color accents.

**Key behaviors**

- Active tab is read from `$_GET['tab']` (sanitized) with a configurable default.
- Each tab supports: `id`, `label`, `icon`, `capability`, and `render_callback`.
- Additional tabs can be injected via filters.

**Usage**

1) Enqueue assets (on the relevant admin screen):

```php
Hypercart_Admin_Tabs::enqueue_assets();
```

2) Render tabs + content:

```php
Hypercart_Admin_Tabs::render( 'my-plugin-page-slug', array(
    'default_tab' => 'settings',
    'tabs' => array(
        array(
            'id' => 'settings',
            'label' => __( 'Settings', 'my-plugin' ),
            'icon' => 'dashicons-admin-generic',
            'capability' => 'manage_options',
            'render_callback' => array( __CLASS__, 'render_tab_settings' ),
        ),
        // ... more tabs
    ),
) );
```

3) Optionally add tabs via filters:

- Filter name: `hypercart_admin_tabs_{page_slug}_tabs`

---

## Hypercart_Markdown_Viewer API

**Since:** 1.1.3

**Purpose:** Safely render a subset of Markdown to HTML (no raw HTML passthrough) and provide a shortcode for editors.

**Minimum Helper Version:** `v1.1.3+`

### Markdown Shortcode

Render a markdown file under `WP_CONTENT_DIR`:

```text
[hypercart_markdown file="plugins/hypercart-helper/README.md" title="Docs" cache_ttl="300"]
```

Or render inline markdown:

```text
[hypercart_markdown]# Title

A **bold** word.[/hypercart_markdown]
```

### Markdown PHP Usage

```php
echo Hypercart_Markdown_Viewer::render_markdown( "# Title\n\nHello **world**." );
echo Hypercart_Markdown_Viewer::render_file( 'plugins/my-plugin/docs/help.md', array( 'cache_ttl' => 300 ) );
```

### Markdown Security Model

- Relative `file` paths are interpreted under `WP_CONTENT_DIR`.
- Only `.md` and `.markdown` files are allowed.
- Allowed base directories can be customized via:
  - Filter: `hypercart_markdown_viewer_allowed_base_dirs`
- Final allow/deny can be overridden via:
  - Filter: `hypercart_markdown_viewer_allow_file`

### Markdown Hooks & Filters

- Action: `hypercart_markdown_viewer_init`
- Filter: `hypercart_markdown_viewer_markdown`
- Filter: `hypercart_markdown_viewer_allowed_tags`
- Filter: `hypercart_markdown_viewer_html`
- Action: `hypercart_markdown_viewer_rendered`

---

## Quick Start

### Installation Check

```php
<?php
// Check if Hypercart Helper is active
if ( ! class_exists( 'Hypercart_Time' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>My Plugin</strong> requires <strong>Hypercart Helper v1.0.0+</strong>';
        echo '</p></div>';
    } );
    return; // Don't load your plugin
}
```

### Version Check

```php
<?php
// Check for specific version (e.g., Charts API requires 1.1.0+)
if ( defined( 'HYPERCART_HELPER_VERSION' ) && 
     version_compare( HYPERCART_HELPER_VERSION, '1.1.0', '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-warning"><p>';
        echo 'Chart features require <strong>Hypercart Helper v1.1.0+</strong>';
        echo '</p></div>';
    } );
}
```

---

## Dependency Check

### Recommended Pattern

```php
<?php
/**
 * Check Hypercart Helper dependency
 *
 * @return bool True if dependency met, false otherwise.
 */
function my_plugin_check_helper_dependency(): bool {
    if ( ! class_exists( 'Hypercart_Time' ) || 
         ! class_exists( 'Hypercart_Logger' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong>My Plugin</strong> requires 
                    <strong>Hypercart Helper v1.0.0</strong> or higher.
                    <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
                        Manage Plugins
                    </a>
                </p>
            </div>
            <?php
        } );
        return false;
    }

    // Check minimum version
    if ( defined( 'HYPERCART_HELPER_VERSION' ) && version_compare( HYPERCART_HELPER_VERSION, '1.0.0', '<' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong>My Plugin</strong> requires 
                    <strong>Hypercart Helper v1.0.0+</strong> 
                    (found v<?php echo esc_html( HYPERCART_HELPER_VERSION ); ?>).
                </p>
            </div>
            <?php
        } );
        return false;
    }

    return true;
}

// Early in main plugin file, before any other code:
if ( ! my_plugin_check_helper_dependency() ) {
    return; // Don't load plugin
}
```

---

## Hypercart_Time API

**Purpose:** Centralized UTC-based time management with local timezone display.

**Design Principle:** Store in UTC, display in site timezone.

### Time Core Methods

#### `Hypercart_Time::now()`

Get current UTC Unix timestamp.

**Signature:**
```php
public static function now(): int
```

**Returns:** `int` - Unix timestamp (always UTC)

**Usage:**
```php
// Store current timestamp
$created_at = Hypercart_Time::now();
update_post_meta( $post_id, '_created_at', $created_at );

// Store in options
update_option( 'my_plugin_last_run', Hypercart_Time::now(), false );
```

**Important:** This is the ONLY method you should use to get current time. Never use `time()`, `current_time()`, or `date()` directly.

### Time Formatting Methods

#### `Hypercart_Time::format()`

Format a UTC timestamp for user display in the WordPress *site timezone*.

**Signature:**
```php
public static function format( string $format, ?int $timestamp = null ): string
```

**Usage:**
```php
$created_at = (int) get_post_meta( $post_id, '_created_at', true );
echo esc_html( Hypercart_Time::format( 'Y-m-d H:i', $created_at ) );
```

#### `Hypercart_Time::utc_format()`

Format a UTC timestamp in UTC (recommended for logs, filenames, debugging).

**Signature:**
```php
public static function utc_format( string $format, ?int $timestamp = null ): string
```

**Usage:**
```php
$ts = Hypercart_Time::now();
$stamp = Hypercart_Time::utc_format( 'Y-m-d H:i:s', $ts );
Hypercart_Logger::info( 'my-plugin', 'Generated report', array( 'utc' => $stamp ) );
```

#### `Hypercart_Time::iso8601()`

ISO 8601 representation in UTC with `Z` suffix (ideal for REST / external APIs).

**Signature:**
```php
public static function iso8601( ?int $timestamp = null ): string
```

**Usage:**
```php
$payload = array(
    'generated_at' => Hypercart_Time::iso8601(),
);
```

#### `Hypercart_Time::mysql_utc()` / `Hypercart_Time::mysql_local()`

Convenience helpers to output MySQL DATETIME strings.

**Signatures:**
```php
public static function mysql_utc( ?int $timestamp = null ): string
public static function mysql_local( ?int $timestamp = null ): string
```

**Guidance:** Prefer Unix timestamps for storage. Use `mysql_utc()` only when a schema forces DATETIME.

### Time Parsing Methods

#### `Hypercart_Time::parse()`

Parse a date string to a UTC Unix timestamp.

- If `$timezone` is `null`, the input is assumed to be in the WordPress *site timezone*.
- If `$timezone` is a valid timezone string, the input is treated as being in that timezone.

**Signature:**
```php
public static function parse( string $date_string, ?string $timezone = null )
```

**Returns:** `int|false`

**Usage:**
```php
$ts = Hypercart_Time::parse( '2026-01-10 09:30:00' );
if ( false === $ts ) {
    Hypercart_Logger::warning( 'my-plugin', 'Failed to parse date', array( 'input' => '2026-01-10 09:30:00' ) );
}
```

### Time Utility Methods

#### `Hypercart_Time::to_datetime()` / `Hypercart_Time::to_utc_datetime()`

Convert a UTC timestamp to an immutable DateTime object.

**Signatures:**
```php
public static function to_datetime( ?int $timestamp = null ): DateTimeImmutable
public static function to_utc_datetime( ?int $timestamp = null ): DateTimeImmutable
```

#### `Hypercart_Time::get_timezone_name()` / `Hypercart_Time::get_offset_string()`

Useful for UI copy such as “All times shown in America/Los_Angeles (UTC-8)”.

**Signatures:**
```php
public static function get_timezone_name(): string
public static function get_offset_string( ?int $timestamp = null ): string
```

#### `Hypercart_Time::is_past()` / `Hypercart_Time::is_future()`

**Signatures:**
```php
public static function is_past( int $timestamp ): bool
public static function is_future( int $timestamp ): bool
```

### Time Testing Methods

`Hypercart_Time` supports mocking “now” for deterministic tests. See the class source for the mock APIs used by the test suite.

---

## Hypercart_Logger API

**Purpose:** File-based logging with daily rotation and predictable formatting.

**Log format (single line):**
```
[YYYY-MM-DD HH:MM:SS UTC] plugin-slug LEVEL: Message {"optional":"context"}
```

### Logger Logging Methods

#### `Hypercart_Logger::debug()` / `info()` / `warning()` / `error()`

**Signatures:**
```php
public static function debug( string $plugin, $message, array $context = array() ): bool
public static function info( string $plugin, $message, array $context = array() ): bool
public static function warning( string $plugin, $message, array $context = array() ): bool
public static function error( string $plugin, $message, array $context = array() ): bool
```

Pass strings during normal operation. Non-string values are only accepted as a crash guard and are normalized to JSON before logging.

**Usage:**
```php
Hypercart_Logger::info( 'my-plugin', 'Sync started' );

Hypercart_Logger::warning( 'my-plugin', 'API returned unexpected status', array(
    'status' => 202,
    'endpoint' => '/v1/jobs',
) );

try {
    // ...
} catch ( Exception $e ) {
    Hypercart_Logger::error( 'my-plugin', 'Sync failed', array(
        'error' => $e->getMessage(),
    ) );
}
```

### Logger File Management

#### `Hypercart_Logger::get_log_dir()`

Default directory (filterable): `WP_CONTENT_DIR/hypercart-logs`

**Signature:**
```php
public static function get_log_dir()
```

#### `Hypercart_Logger::get_log_file()`

Daily file naming in **UTC**: `hypercart-YYYY-MM-DD.log`

**Signature:**
```php
public static function get_log_file( ?int $timestamp = null )
```

#### `Hypercart_Logger::get_log_files()`

Returns available log files (newest first) keyed by filename.

**Signature:**
```php
public static function get_log_files( bool $include_sizes = true ): array
```

**Notes (v1.1.7+)**
- Pass `false` to skip size calculation (sizes return as `0`).
- Admin contexts cache the file list for short durations (filterable).

#### `Hypercart_Logger::read_log()`

Read full log or a subset of lines.

**Signature:**
```php
public static function read_log( ?string $filename = null, int $lines = 0 )
```

**Notes (v1.1.6+)**
- Negative `$lines` uses a tail-style reader (reads from the end without loading the full file).
- Admin reads enforce max line and max size guards via filters.

### Logger Configuration

#### Filter: `hypercart_log_dir`

Override the log directory.

```php
add_filter( 'hypercart_log_dir', function( string $dir ) {
    return WP_CONTENT_DIR . '/my-plugin-logs';
} );
```

#### Filter: log level

The logger supports a minimum log level (for example to suppress DEBUG in production) via its internal configuration. See `Hypercart_Logger::get_min_level()` in the class source for the exact filter name and behavior.

#### Filter: `hypercart_log_files_cache_ttl`

Adjust the admin cache TTL (seconds) for `get_log_files()` results (default: 60). Set to `0` to disable.

```php
add_filter( 'hypercart_log_files_cache_ttl', function() {
    return 0;
} );
```

#### Filter: `hypercart_log_files_include_sizes`

Force size calculation on/off regardless of the `get_log_files()` argument.

```php
add_filter( 'hypercart_log_files_include_sizes', function() {
    return false;
} );
```

#### Filter: `hypercart_log_read_max_lines`

Caps the number of lines returned by `read_log()` (default: 5000). Applies to both positive and negative values.

```php
add_filter( 'hypercart_log_read_max_lines', function() {
    return 10000;
} );
```

#### Filter: `hypercart_log_read_max_bytes`

Caps the bytes scanned or read by `read_log()` in admin contexts (default: 5 MB). When `read_log()` is called with `$lines = 0` in admin and the file exceeds the cap, it returns `false`.

```php
add_filter( 'hypercart_log_read_max_bytes', function() {
    return 10 * MB_IN_BYTES;
} );
```

#### Action: `hypercart_log`

Fired whenever a log entry is recorded.

**Action signature:**
```php
/**
 * @param string $plugin
 * @param int    $level
 * @param string $message
 * @param array  $context
 * @param string $timestamp UTC string "Y-m-d H:i:s"
 */
do_action( 'hypercart_log', $plugin, $level, $message, $context, $timestamp );
```

### Logger Security & Disclaimer

**⚠️ Use the logging feature at your own risk.** Hypercart Helper provides file-based logging as a convenience utility, but **you are responsible for securing your log directory and its contents**. Log files may contain sensitive information (user IDs, timestamps, context data, error messages) and must be protected from unauthorized access.

**Redaction (v1.1.8+)** Context values and structured non-string message payloads are automatically redacted for known sensitive keys (e.g., `password`, `token`, `secret`, `authorization`, `api_key`). When redaction occurs, the context includes `_hh_redacted_by = "hypercart-helper"` to indicate the source.

**For System Administrators & Developers:** Your log directory must be placed **outside the web root** (outside `ABSPATH`) to prevent public access via HTTP requests. By default, Hypercart Helper attempts to create logs in `WP_CONTENT_DIR/hypercart-logs/`, which is often web-accessible. **You must override the log directory** using the `hypercart_log_dir` filter to point to a non-public location (e.g., `/var/log/wordpress/` or a directory above the web root). Additionally, ensure proper file permissions (e.g., `chmod 700` or `chmod 750`) so only the web server user and administrators can read logs. The plugin includes a security check in the Self Test that warns if logs are detected in a web-accessible location—if you see this warning, immediately move your logs to a secure directory. Hypercart Helper provides `.htaccess` and `index.php` protection files as a fallback, but these are **not a substitute for proper directory placement** and can be bypassed by misconfiguration or server settings.

---

## Hypercart_Charts API

**Since:** 1.1.0

**Purpose:** Provide a lightweight, reusable time-series chart helper.

**Minimum Helper Version:** `v1.1.0+`

### Dependencies / Design Notes

- A pinned Chart.js UMD build is vendored at `assets/vendor/chartjs/chart.umd.min.js`.
- v1 uses a **linear x-axis** with **epoch-ms timestamps** by default.
- Tick/tooltip timestamp formatting is handled in JS using `Intl.DateTimeFormat` with the site timezone passed via `HypercartChartsSettings` (localized by `Hypercart_Charts::enqueue()`).

### Charts Asset Management

#### `Hypercart_Charts::register_assets()`

Registers (does not enqueue) the Chart.js vendor script and the Hypercart wrapper.

**Signature:**
```php
public static function register_assets(): void
```

**Recommended usage:** call on `admin_init` / `wp_enqueue_scripts` early, then enqueue only where needed.

#### `Hypercart_Charts::enqueue()`

Enqueues required scripts and localizes runtime settings.

**Signature:**
```php
public static function enqueue( array $args = array() ): void
```

**Common args:**
- `context` (`auto` by default): consuming plugin hint/context.
- `use_time_adapter` (`true` by default): used by the wrapper.

**Usage:**
```php
add_action( 'admin_enqueue_scripts', function( $hook ) {
    // Only enqueue on your plugin page.
    if ( 'toplevel_page_my-plugin' !== $hook ) {
        return;
    }

    Hypercart_Charts::register_assets();
    Hypercart_Charts::enqueue( array( 'context' => 'my-plugin-admin' ) );
} );
```

### Charts Data Model

#### Chart definition (PHP)

`Hypercart_Charts::render_canvas()` expects a chart definition array:

- `id` (string, required-ish): used for identification/debugging; will be generated if missing.
- `title` (string, optional)
- `datasets` (array, required)

Each dataset:

- `key` (string): stable identifier (`sanitize_key()` compatible)
- `label` (string): legend/tooltip label
- `color` (string, optional): hex/rgb/css string
- `points` (array): list of points

Point object shape:

- `x`:
  - epoch **seconds** (int)
  - epoch **milliseconds** (int)
  - date string parseable by `strtotime()` (ISO8601 recommended, e.g. `2026-01-02T00:00:00Z`)
- `y`: numeric

The helper normalizes all points so Chart.js receives `{x: <epoch_ms>, y: <float>}`.

### Charts Data Preparation

#### `Hypercart_Charts::build_timeseries_payload()`

Normalizes chart definitions into a frontend-friendly payload.

- Normalizes points to `{ x, y }` where `x` is **UTC epoch milliseconds**.
- Accepts `x` as seconds, milliseconds, or parsable date string.

**Signature:**
```php
public static function build_timeseries_payload( array $chart ): array
```

### Multi-Dataset Overlays

Overlay multiple series by providing multiple datasets:

```php
'datasets' => array(
    array( 'key' => 'a', 'label' => 'A', 'points' => $points_a ),
    array( 'key' => 'b', 'label' => 'B', 'points' => $points_b ),
)
```

### Charts Rendering

#### `Hypercart_Charts::render_canvas()`

Returns a `<canvas>` element with a JSON payload stored in `data-hypercart-chart`.

**Signature:**
```php
public static function render_canvas( array $chart, array $options = array() ): string
```

**Quick start example:**
```php
echo Hypercart_Charts::render_canvas(
    array(
        'id' => 'my-chart',
        'title' => 'My Chart',
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

### Hover Tooltips

Tooltips are enabled by default using Chart.js.

To customize tooltip formatting, use the filter:

- `hypercart_charts_tooltip_callbacks`

**Important notes:**

- Chart.js tooltip callbacks execute in **JavaScript**, not PHP.
- The filter exists to allow overriding the config array (and for future extension), but there is **no PHP→JS callback bridge** shipped.

### Charts Hooks & Filters

#### Filter: `hypercart_charts_use_chartjs`

Return `false` to prevent Chart.js + wrapper from registering/enqueuing.

#### Actions
- `hypercart_charts_assets_registered`
- `hypercart_charts_assets_enqueued` (receives `$args`)
- `hypercart_charts_rendered` (receives chart id)

#### Filters
- `hypercart_charts_datasets` (modify normalized datasets)
- `hypercart_charts_default_config` (modify default Chart.js config)
- `hypercart_charts_point_format` (modify individual normalized points)
- `hypercart_charts_tooltip_callbacks` (provide Tooltip callback config array)

### Demo JSON File

Helper ships a demo chart definition at:

- `assets/demo/chart-test.json`

This is used by the Helper settings page “Chart Test” to render sample datasets.
If the demo file is missing/unreadable, the settings page falls back to generating data.

### Implementation Notes / Caveats

- Chart helper registers scripts (via `register_assets()`) and only enqueues when asked.
- Time formatting uses `Intl.DateTimeFormat` (no external Chart.js time adapter required for v1).

---

## WordPress Hooks

This plugin is intentionally small and exposes integration points primarily through:

- The logger action: `hypercart_log`
- Chart helper hooks/filters: `hypercart_charts_*`
- Markdown Viewer hooks/filters: `hypercart_markdown_viewer_*`

Additional hooks may exist in other helper classes; search the source for `apply_filters( 'hypercart_` and `do_action( 'hypercart_`.

---

## Best Practices

- **Store timestamps as UTC integers (seconds)** in options/meta. Use `Hypercart_Time::now()`.
- **Display using `Hypercart_Time::format()`** to respect WP timezone & locale.
- **Log with a stable plugin slug** (e.g., `my-plugin`) so log entries group cleanly.
- **Keep context structured**: pass arrays to logger context instead of concatenated strings.
- **Enqueue chart assets only where needed** (admin page, specific shortcode render, etc.).
- **Prefer filters over forks**: use the provided `hypercart_*` filters/actions to customize behavior.

---

## Examples

### Example: dependency + logging + time

```php
<?php
if ( ! class_exists( 'Hypercart_Time' ) || ! class_exists( 'Hypercart_Logger' ) ) {
    return;
}

add_action( 'init', function() {
    Hypercart_Logger::info( 'my-plugin', 'Boot', array(
        'at' => Hypercart_Time::iso8601(),
        'tz' => Hypercart_Time::get_timezone_name(),
    ) );
} );
```

### Example: render a chart on an admin page

```php
<?php
add_action( 'admin_menu', function() {
    add_menu_page(
        'My Plugin',
        'My Plugin',
        'manage_options',
        'my-plugin',
        function() {
            // Build chart definition.
            $chart = array(
                'id' => 'my-plugin-metric',
                'title' => 'My Metric',
                'datasets' => array(
                    array(
                        'key' => 'count',
                        'label' => 'Count',
                        'color' => '#16a34a',
                        'points' => array(
                            array( 'x' => Hypercart_Time::now() - DAY_IN_SECONDS * 2, 'y' => 10 ),
                            array( 'x' => Hypercart_Time::now() - DAY_IN_SECONDS * 1, 'y' => 14 ),
                            array( 'x' => Hypercart_Time::now(), 'y' => 9 ),
                        ),
                    ),
                ),
            );

            // Print canvas.
            echo '<div class="wrap">';
            echo '<h1>My Plugin</h1>';
            echo Hypercart_Charts::render_canvas( $chart, array( 'height' => 320 ) );
            echo '</div>';
        },
        'dashicons-chart-area'
    );
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( 'toplevel_page_my-plugin' !== $hook ) {
        return;
    }
    Hypercart_Charts::register_assets();
    Hypercart_Charts::enqueue( array( 'context' => 'my-plugin-admin' ) );
} );
```
