# Hypercart Charts API (Developer Onboarding)

**Minimum Helper Version:** `v1.1.0+`

This document describes how to render lightweight, time-based charts using the `Hypercart_Charts` helper.

---

## 0) Dependencies

- A pinned Chart.js UMD build is vendored at `assets/vendor/chartjs/chart.umd.min.js`.
- v1 uses a **linear x-axis** with **epoch-ms timestamps** by default.
- Tick/tooltip timestamp formatting is handled in JS using `Intl.DateTimeFormat` with the site timezone passed via `HypercartChartsSettings`.

---

## 1) Quick Start

### 1.1 Version Gate (recommended)

In a consuming plugin:

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

### 1.2 Enqueue assets (only when needed)

Call this on the screen where you render charts:

```php
Hypercart_Charts::enqueue( array( 'context' => 'admin' ) );
```

### 1.3 Render a chart

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

---

## 2) Data Model

### 2.1 Chart definition (PHP)

`Hypercart_Charts::render_canvas()` expects a _chart definition_ array:

- `id` (string, required-ish): Used for identification/debugging; will be generated if missing.
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
  - ISO8601 string (e.g. `2026-01-02T00:00:00Z`)
- `y`: numeric

Helper normalizes all points so Chart.js receives `{x: <epoch_ms>, y: <float>}`.

---

## 3) Multi-Dataset Overlays

Overlay is done by providing multiple datasets:

```php
'datasets' => array(
	array( 'key' => 'a', 'label' => 'A', 'points' => $points_a ),
	array( 'key' => 'b', 'label' => 'B', 'points' => $points_b ),
)
```

---

## 4) Hover Tooltips

Tooltips are enabled by default using Chart.js.

To customize tooltip formatting, use the filter:

- `hypercart_charts_tooltip_callbacks`

Example:

```php
add_filter( 'hypercart_charts_tooltip_callbacks', function( $callbacks, $chart ) {
	$callbacks['label'] = new WP_Comment(); // placeholder (callbacks are JS-side)
	return $callbacks;
}, 10, 2 );
```

Notes:

- Chart.js tooltip callbacks execute in JS, not PHP.
- v1 exposes the filter for future extension but does not ship a full PHP->JS callback bridge.

---

## 5) Hooks

### 5.1 Filters

- `hypercart_charts_use_chartjs` (bool)
- `hypercart_charts_default_config` (array $config, array $chart)
- `hypercart_charts_datasets` (array $datasets, array $chart)
- `hypercart_charts_tooltip_callbacks` (array $callbacks, array $chart)
- `hypercart_charts_point_format` (array $point, mixed $raw_point, array $dataset, array $chart)

### 5.2 Actions

- `hypercart_charts_assets_registered`
- `hypercart_charts_assets_enqueued` (array $args)
- `hypercart_charts_rendered` (string $chart_id)

---

## 6) Demo JSON File

Helper ships a demo chart at:

- `assets/demo/chart-test.json`

This is used by the Helper settings page “Chart Test” to render sample datasets.
If the demo file is missing/unreadable, the settings page falls back to generating data.

---

## 7) Implementation Notes / Caveats

- Chart helper registers scripts on `init` and only enqueues when asked.
- Time formatting uses `Intl.DateTimeFormat` (no external Chart.js time adapter required for v1).

