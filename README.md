# Hypercart Helper

Shared utilities for the Hypercart plugin suite. Provides centralized time handling (UTC storage, local display), structured file-based logging, a reusable chart helper, and a safe Markdown viewer.

**Version:** 1.1.16
**Author:** Neochrome  
**Requires at least:** WordPress 6.0  
**Requires PHP:** 7.4

---

## Features

- **Hypercart_Time**: UTC-first time storage with site-timezone display helpers.
- **Hypercart_Logger**: Structured, daily-rotated log files with redaction for sensitive structured data and retention cleanup.
- **Hypercart_Charts**: Lightweight Chart.js helper with normalized time-series payloads.
- **Hypercart_Markdown_Viewer**: Safe Markdown rendering with shortcode and file allowlists.
- **Admin Tools**: Settings page with tabs (Self Test, Demo, Changelog) and a Security Guide viewer.

---

## Installation

1. Upload the plugin folder to `wp-content/plugins/`.
2. Activate **Hypercart Helper** in the Plugins screen.
3. Visit **Settings → Hypercart Helper** to run Self Tests or view the demo chart.

---

## Usage

### Hypercart_Time

```php
$now = Hypercart_Time::now(); // UTC timestamp
$utc = Hypercart_Time::utc_format( 'Y-m-d H:i:s', $now );
$local = Hypercart_Time::format( 'F j, Y g:i A', $now );
$iso = Hypercart_Time::iso8601( $now );
```

### Hypercart_Logger

```php
Hypercart_Logger::info( 'my-plugin', 'Worker started', array(
    'job_id' => 123,
    'user_id' => get_current_user_id(),
) );

$files = Hypercart_Logger::get_log_files();
$tail = Hypercart_Logger::read_log( null, -100 );
```

### Hypercart_Charts

```php
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( 'settings_page_hypercart-helper' === $hook ) {
        Hypercart_Charts::enqueue( array( 'context' => 'admin' ) );
    }
} );

$chart = array(
    'id' => 'my-chart',
    'title' => 'Requests',
    'datasets' => array(
        array(
            'key' => 'requests',
            'label' => 'Requests',
            'color' => '#2271b1',
            'points' => array(
                array( 'x' => time() - 3600, 'y' => 12 ),
                array( 'x' => time(), 'y' => 30 ),
            ),
        ),
    ),
);

echo Hypercart_Charts::render_canvas( $chart, array( 'height' => 280 ) );
```

### Hypercart_Markdown_Viewer

```php
// Shortcode
[hypercart_markdown file="plugins/my-plugin/README.md" title="Docs"]

// PHP
echo Hypercart_Markdown_Viewer::render_file( 'plugins/my-plugin/README.md' );
```

### Hypercart_Admin_Tabs

Reusable admin tab UI for settings pages. The helper builds the tab nav using core `.nav-tab` styles and supports icons, labels, and URLs. Use it to render consistent tabs across your admin pages.

```php
$tabs = array(
    array(
        'id'    => 'settings',
        'label' => 'Settings',
        'url'   => admin_url( 'options-general.php?page=my-plugin&tab=settings' ),
        'icon'  => 'dashicons-admin-generic',
    ),
    array(
        'id'    => 'logs',
        'label' => 'Logs',
        'url'   => admin_url( 'options-general.php?page=my-plugin&tab=logs' ),
        'icon'  => 'dashicons-clipboard',
    ),
);

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

echo Hypercart_Admin_Tabs::render( $tabs, $active_tab, array(
    'context' => 'my-plugin',
    'class'   => 'my-plugin-tabs',
    'style'   => array(
        'accent' => '#2271b1',
    ),
) );
```

Notes:
- `id` must be unique and match the `tab` query string.
- `url` should include both `page` and `tab` for correct routing.
- `icon` uses Dashicons slugs (optional).
- `context` scopes tab styles and helps avoid CSS collisions.

---

## Admin Screens

- **Self Test**: Validates time handling, logging, charts, and Markdown rendering.
- **Demo**: Renders a sample chart if Chart.js assets are available.
- **Changelog**: Renders `CHANGELOG.md` via the Markdown Viewer.
- **Security Guide**: Renders `README-SECURITY.md` from a dedicated admin page.

---

## Security Notes

- Log files are stored under `WP_CONTENT_DIR/hypercart-logs` by default. Move them outside the web root if possible by filtering `hypercart_log_dir`.
- The Markdown Viewer only renders `.md`/`.markdown` files under `WP_CONTENT_DIR` by default. Use the allowlist filters if you need to restrict or expand access.
- Logging redaction applies to context and structured non-string message payloads for common sensitive keys (token, password, api_key, etc.).

---

## Hooks and Filters (Highlights)

- `hypercart_log_dir`
- `hypercart_min_log_level`
- `hypercart_log_files_include_sizes`
- `hypercart_log_files_cache_ttl`
- `hypercart_log_read_max_lines`
- `hypercart_log_read_max_bytes`
- `hypercart_log_retention_days`
- `hypercart_charts_use_chartjs`
- `hypercart_charts_datasets`
- `hypercart_charts_default_config`
- `hypercart_charts_tooltip_callbacks`
- `hypercart_markdown_viewer_allowed_base_dirs`
- `hypercart_markdown_viewer_allow_file`
- `hypercart_markdown_viewer_allowed_tags`
- `hypercart_markdown_viewer_markdown`
- `hypercart_markdown_viewer_html`
- Action: `hypercart_log`

---

## Documentation

- `README-SECURITY.md` - Security guidance for log storage and server configuration.
- `CHANGELOG.md` - Version history.
- `HH-INTEGRATION-GUIDE.md` - Integration notes for other Hypercart plugins.

---

## License

GPL-2.0-or-later. See `LICENSE.md`.
