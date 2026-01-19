# PROJECT-CHARTS: Lightweight Time-Series Chart Helper (Plan)

## Checklist + TOC (working)

- [ ] **Implement in Helper (v1.1.0)**
  - [ ] Add `Hypercart_Charts` PHP helper + asset registration/enqueue
  - [ ] Add JS renderer wrapper (`HypercartCharts`) + auto-render
  - [ ] Bundle Chart.js (pinned) under plugin assets
  - [ ] Add self-test "Chart Test" demo in Helper settings
- [ ] **Version gate for consuming plugins**
  - [ ] Document the `class_exists( 'Hypercart_Charts' )` + minimum version pattern
- [ ] **Documentation**
  - [ ] Add "Chart Rendering" section to `README.md`
  - [ ] Include usage examples + minimum version requirement

### Sections

- [1) Problem Statement](#1-problem-statement)
- [2) Guiding Principles (WordPress Way)](#2-guiding-principles-wordpress-way)
- [3) Proposed Technical Approach](#3-proposed-technical-approach)
- [4) Public API (Draft)](#4-public-api-draft)
- [5) Core Features (v1)](#5-core-features-v1)
- [6) Hooks & Extensibility](#6-hooks--extensibility)
- [7) Data Validation & Security](#7-data-validation--security)
- [8) File/Folder Layout (Proposed)](#8-filefolder-layout-proposed)
- [9) Implementation Plan (Phased)](#9-implementation-plan-phased)
- [10) Usage Examples (Draft)](#10-usage-examples-draft)
- [11) Testing Strategy](#11-testing-strategy)
- [12) Open Questions](#12-open-questions)
- [13) Definition of Done (v1)](#13-definition-of-done-v1)

---

# PROJECT-CHARTS: Lightweight Time-Series Chart Helper (Plan)

**Project:** Hypercart Helper – Charting Utility

**Status:** Planning

**Date:** 2026-01-02

**Goal:** Add a lightweight, reusable, time-based chart helper that can be consumed by this plugin and other plugins/themes via stable PHP/JS APIs.

---

## 1) Problem Statement

Multiple Hypercart-adjacent plugins need small, consistent time-series charts (e.g., daily totals, event counts, durations). Each implementation tends to:

- reinvent data formatting/timezone conversion
- load heavy libraries multiple times
- diverge visually/behaviorally
- lack extensibility hooks

We want a single helper that:

- focuses on **time-series line/area charts**
- supports **multiple overlays (datasets)**
- provides **hoverable tooltips per point**
- fits WordPress best practices (enqueue, dependencies, hooks/filters)
- works as a **shared utility** other plugins can call

Non-goals for v1:

- complex chart types (candlestick, heatmaps, maps)
- bespoke chart builder UI
- persistent storage/reporting (this helper renders data it is given)

---

## 2) Guiding Principles (WordPress Way)

1. **Helper-first, stateless utilities**
   - Match existing architecture (e.g., `Hypercart_Time`, `Hypercart_Logger` are static helpers).

2. **Store UTC, display local**
   - Charts are almost always “display” features; timestamps should be UTC, formatting should respect WordPress site timezone using `wp_date()`.

3. **Enqueue only when needed**
   - Provide a minimal enqueue API and avoid loading chart assets globally.

4. **Extensible by hooks**
   - Filters for chart config, datasets, and library selection.

5. **Single-responsibility + progressive enhancement**
   - PHP prepares validated, normalized series data.
   - JS renders charts and deals with responsive sizing and tooltips.

---

## 3) Proposed Technical Approach

### 3.1 Library selection

**Default:** Chart.js (v4+) with the time scale adapter.

Rationale:

- widely used and well documented
- supports multiple datasets and tooltips out of the box
- responsive and accessible enough with minimal effort
- easy to enqueue in WordPress

Tradeoffs:

- Chart.js isn’t the smallest option, but is still “lightweight enough” for typical WP admin pages.

**Extensibility:** allow swapping the rendering layer via hooks in the future (e.g., lightweight-charts, uPlot), but keep v1 focused on Chart.js.

### 3.2 Data model (time-series)

We standardize on Chart.js’ `{x, y}` point objects:

- `x`: UTC timestamp (milliseconds since epoch) OR ISO 8601 string
- `y`: numeric value

We will normalize in PHP so consuming plugins don’t need to learn Chart.js quirks.

### 3.3 Architecture overview

**PHP**

- `Hypercart_Charts` (new helper class)
  - Responsible for:
    - validating and normalizing datasets
    - formatting timestamps consistently
    - building a safe JSON payload for the frontend
    - registering/enqueueing scripts/styles
    - exposing WordPress filters/actions

**JS**

- `assets/js/hypercart-charts.js`
  - A small wrapper around Chart.js:
    - `window.HypercartCharts.render(el, config)`
    - global registry to avoid double-render
    - lifecycle helpers (`destroy`, `rerender`)

**Assets**

- Chart.js shipped within plugin (pinned version) OR loaded from WordPress core-bundled packages (if available). For v1, prefer bundling to reduce external dependency variability.

---

## 4) Public API (Draft)

### 4.1 PHP API

#### 4.1.1 Enqueue

- `Hypercart_Charts::register_assets()`
  - called on `init`

- `Hypercart_Charts::enqueue( array $args = [] )`
  - called by pages/widgets/shortcodes that need charts
  - ensures Chart.js + wrapper are enqueued

Proposed args:

- `context` (string): `admin` | `frontend` | `auto`
- `use_time_adapter` (bool): enable time scale

#### 4.1.2 Data preparation

- `Hypercart_Charts::build_timeseries_payload( array $chart ) : array`

Where `$chart` includes:

- `id` (string) unique chart id
- `title` (string)
- `datasets` (array)
  - each dataset:
    - `key` (string) identifier
    - `label` (string)
    - `points` (array) of points
    - `color` (string) optional

Return:

- `chart` (sanitized/normalized)
- `config` (Chart.js config subset)

#### 4.1.3 Render helper

- `Hypercart_Charts::render_canvas( array $chart, array $options = [] ) : string`
  - returns a `<canvas>` + inline `data-hypercart-chart` JSON payload (escaped)

This keeps integration simple for PHP-driven admin screens:

- echo `Hypercart_Charts::render_canvas([...])`

### 4.2 JS API

- `window.HypercartCharts.render(canvasElement, payload)`
- auto-render: on DOM ready, find `[data-hypercart-chart]` and render

Payload shape:

- `{ id, data, options, meta }`

Tooltips:

- enabled by default
- `callbacks` can be overridden by filters (see §6)

---

## 5) Core Features (v1)

### 5.1 Time-based scales

- x-axis is time
- supports minute/hour/day/week buckets depending on density
- respects WP site timezone for display formatting

Decision: store `x` as UTC milliseconds, format ticks/tooltip labels in JS using provided timezone offset or preformatted strings.

### 5.2 Multi-dataset overlays

- unlimited datasets
- consistent colors and line styles
- legend toggle (show/hide dataset)

### 5.3 Hoverable tooltips (per point)

Tooltips should show:

- dataset label
- y value (optionally formatted)
- x timestamp formatted in site timezone

### 5.4 Performance basics

- avoid re-rendering if the same chart already exists
- dataset downsampling is out of scope (v1), but we should document recommended max points (e.g., 1k–5k) and leave a hook for downsampling.

---

## 6) Hooks & Extensibility

Provide predictable hooks so other plugins can customize without forking.

### 6.1 Filters

- `hypercart_charts_use_chartjs` (bool)
  - allow disabling/overriding Chart.js usage

- `hypercart_charts_default_config` (array $config, array $chart)
  - default Chart.js config (scales, parsing, plugins)

- `hypercart_charts_datasets` (array $datasets, array $chart)
  - modify datasets before JSON encoding

- `hypercart_charts_tooltip_callbacks` (array $callbacks, array $chart)
  - allow custom tooltip formatting

- `hypercart_charts_point_format` (array $point, array $raw_point, array $dataset, array $chart)
  - normalize/transform points

### 6.2 Actions

- `hypercart_charts_assets_registered`
- `hypercart_charts_assets_enqueued` (array $args)
- `hypercart_charts_rendered` (string $chart_id)

---

## 7) Data Validation & Security

### 7.1 Input validation

- dataset keys/labels: sanitize with `sanitize_key()` / `sanitize_text_field()`
- points:
  - `x` must be numeric timestamp (seconds or ms) or ISO string; normalize to ms
  - `y` must be numeric (float)

### 7.2 Output escaping

- JSON payload embedded in HTML must be escaped:
  - use `wp_json_encode()`
  - embed into `data-*` attribute using `esc_attr()`

### 7.3 Capability boundaries

This helper should not enforce capabilities; the calling plugin’s screen should.

---

## 8) File/Folder Layout (Proposed)

- `includes/`
  - `class-hypercart-charts.php`
- `assets/`
  - `js/hypercart-charts.js`
  - `vendor/chart.js/chart.umd.min.js` (or similar)
  - `css/hypercart-charts.css` (optional, minimal defaults)

- `hypercart-helper.php`
  - load new class
  - register assets on init

---

## 9) Implementation Plan (Phased)

### Phase 0 — Discovery (0.5–1h)

- Inspect existing enqueue patterns in `class-hypercart-admin.php` and `hypercart-helper.php`.
- Decide where admin chart rendering will live (admin-only first vs. shared).
- Confirm plugin’s PHP version target and WordPress minimum.

### Phase 1 — Asset registration (1–2h)

- Add `Hypercart_Charts::register_assets()`
  - `wp_register_script('hypercart-chartjs', ...)`
  - `wp_register_script('hypercart-charts', ...)` depends on chartjs
- Add `Hypercart_Charts::enqueue()`

Acceptance:

- scripts register without warnings
- nothing loads unless `enqueue()` is called

### Phase 2 — PHP payload builder (2–3h)

- Implement `build_timeseries_payload()`
  - normalize timestamps to ms
  - sanitize labels and dataset keys
  - apply filters

Acceptance:

- given mixed point inputs, output is stable and predictable

### Phase 3 — JS renderer (2–4h)

- Implement `HypercartCharts.render()`
- Implement auto-render scanning `data-hypercart-chart`
- Default config:
  - `interaction: { mode: 'nearest', intersect: false }`
  - `plugins.tooltip.enabled = true`
  - time scale on x-axis

Acceptance:

- renders at least 2 datasets overlayed correctly
- tooltips appear per point

### Phase 4 — Reference integration (1–2h)

- Add a small reference admin page section (or a documented snippet) that shows usage.
- Ensure chart helper can be used by external plugins:
  - confirm class exists and doesn’t hard-depend on internal admin pages

Acceptance:

- another plugin can call `Hypercart_Charts::enqueue()` and output canvas markup

### Phase 5 — Documentation + versioning (1h)

- Document public APIs in `README.md`
- Add changelog entry
- Add notes on performance limits and large dataset approach

---

## 10) Usage Examples (Draft)

### 10.1 PHP (admin screen)

- `Hypercart_Charts::enqueue()`
- `echo Hypercart_Charts::render_canvas([...])`

### 10.2 Extending tooltip formatting

- hook into `hypercart_charts_tooltip_callbacks`
- format values as currency/duration

(Examples will be added once the concrete method signatures are implemented.)

---

## 11) Testing Strategy

### 11.1 PHP

- unit-style tests (if test harness exists) for:
  - timestamp normalization (seconds → ms)
  - invalid points drop with warnings (optional)
  - filter application order

### 11.2 JS

- manual smoke test in WP admin:
  - multiple datasets
  - tooltips
  - resize responsiveness

---

## 12) Open Questions

1. **Chart.js bundling**: ship vendor file vs. rely on a build step.
2. **Time adapter**: use built-in `Intl.DateTimeFormat` formatting vs. an adapter library.
3. **Timezone handling**: best approach is:
   - store UTC in data
   - format tooltips/ticks using WP timezone offset passed via localized script data
4. **Accessibility**: do we need a table fallback for screen readers in v1?

---

## 13) Definition of Done (v1)

- A documented PHP+JS helper exists in Hypercart Helper
- Other plugins can:
  - enqueue assets
  - render a chart with multiple datasets
  - see hover tooltips per point
- Data is safely validated/escaped
- Hooks exist for configuration and formatting
- No global asset loading unless used
