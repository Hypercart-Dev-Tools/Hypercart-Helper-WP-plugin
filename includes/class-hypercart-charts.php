<?php
/**
 * Charting helper for Hypercart plugin suite.
 *
 * Lightweight time-series chart helper intended for reuse by other plugins.
 *
 * @package Hypercart_Helper
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hypercart_Charts {

	/**
	 * Relative path (within plugin) to the vendored Chart.js UMD build.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const CHARTJS_REL_PATH = 'assets/vendor/chartjs/chart.umd.min.js';

	/**
	 * Relative path (within plugin) to the Hypercart chart wrapper.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const WRAPPER_REL_PATH = 'assets/js/hypercart-charts.js';

	/**
	 * Register scripts/styles (no enqueue).
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function register_assets(): void {
		$use_chartjs = (bool) apply_filters( 'hypercart_charts_use_chartjs', true );
		if ( ! $use_chartjs ) {
			do_action( 'hypercart_charts_assets_registered' );
			return;
		}

		wp_register_script(
			'hypercart-chartjs',
			HYPERCART_HELPER_URL . self::CHARTJS_REL_PATH,
			array(),
			HYPERCART_HELPER_VERSION,
			true
		);

		wp_register_script(
			'hypercart-charts',
			HYPERCART_HELPER_URL . self::WRAPPER_REL_PATH,
			array( 'hypercart-chartjs' ),
			HYPERCART_HELPER_VERSION,
			true
		);

		do_action( 'hypercart_charts_assets_registered' );
	}

	/**
	 * Enqueue chart assets.
	 *
	 * Intended to be called by consuming plugins/pages only when charts are needed.
	 *
	 * @since 1.1.0
	 * @param array $args Optional args.
	 * @return void
	 */
	public static function enqueue( array $args = array() ): void {
		$defaults = array(
			'context'          => 'auto',
			'use_time_adapter' => true,
		);
		$args = wp_parse_args( $args, $defaults );

		$use_chartjs = (bool) apply_filters( 'hypercart_charts_use_chartjs', true );
		if ( ! $use_chartjs ) {
			do_action( 'hypercart_charts_assets_enqueued', $args );
			return;
		}

		wp_enqueue_script( 'hypercart-chartjs' );
		wp_enqueue_script( 'hypercart-charts' );

		// Pass minimal runtime context.
		$tz = wp_timezone_string();
		if ( empty( $tz ) ) {
			$tz = 'UTC';
		}

		wp_localize_script(
			'hypercart-charts',
			'HypercartChartsSettings',
			array(
				'tz'    => $tz,
				'loc'   => determine_locale(),
				'v'     => HYPERCART_HELPER_VERSION,
				'args'  => $args,
			)
		);

		do_action( 'hypercart_charts_assets_enqueued', $args );
	}

	/**
	 * Build a normalized payload for time-series charts.
	 *
	 * @since 1.1.0
	 * @param array $chart Chart definition.
	 * @return array Payload for frontend rendering.
	 */
	public static function build_timeseries_payload( array $chart ): array {
		$chart_id = isset( $chart['id'] ) ? sanitize_key( (string) $chart['id'] ) : '';
		if ( '' === $chart_id ) {
			$chart_id = 'hypercart-chart-' . wp_generate_uuid4();
		}

		$title = isset( $chart['title'] ) ? sanitize_text_field( (string) $chart['title'] ) : '';

		$datasets = isset( $chart['datasets'] ) && is_array( $chart['datasets'] ) ? $chart['datasets'] : array();
		$normalized_datasets = array();

		foreach ( $datasets as $dataset ) {
			if ( ! is_array( $dataset ) ) {
				continue;
			}

			$key   = isset( $dataset['key'] ) ? sanitize_key( (string) $dataset['key'] ) : '';
			$label = isset( $dataset['label'] ) ? sanitize_text_field( (string) $dataset['label'] ) : $key;

			$points = isset( $dataset['points'] ) && is_array( $dataset['points'] ) ? $dataset['points'] : array();
			$normalized_points = array();

			foreach ( $points as $raw_point ) {
				$point = self::normalize_point( $raw_point, $dataset, $chart );
				if ( null === $point ) {
					continue;
				}
				$normalized_points[] = $point;
			}

			$color = isset( $dataset['color'] ) ? sanitize_text_field( (string) $dataset['color'] ) : '';

			$normalized_datasets[] = array(
				'key'    => $key,
				'label'  => $label,
				'color'  => $color,
				'points' => $normalized_points,
			);
		}

		$chart_out = array(
			'id'       => $chart_id,
			'title'    => $title,
			'datasets' => $normalized_datasets,
		);

		$chart_out['datasets'] = apply_filters( 'hypercart_charts_datasets', $chart_out['datasets'], $chart_out );

		$config = self::default_chartjs_config( $chart_out );
		$config = apply_filters( 'hypercart_charts_default_config', $config, $chart_out );

		return array(
			'chart'  => $chart_out,
			'config' => $config,
		);
	}

	/**
	 * Render a canvas element with a JSON payload in a data attribute.
	 *
	 * @since 1.1.0
	 * @param array $chart   Chart definition.
	 * @param array $options Render options.
	 * @return string HTML markup.
	 */
	public static function render_canvas( array $chart, array $options = array() ): string {
		$payload = self::build_timeseries_payload( $chart );

		$defaults = array(
			'class'  => 'hypercart-chart',
			'width'  => null,
			'height' => 280,
		);
		$options = wp_parse_args( $options, $defaults );

		$attrs = array();
		$attrs[] = 'class="' . esc_attr( (string) $options['class'] ) . '"';
		if ( ! empty( $options['width'] ) ) {
			$attrs[] = 'width="' . esc_attr( (string) $options['width'] ) . '"';
		}
		if ( ! empty( $options['height'] ) ) {
			$attrs[] = 'height="' . esc_attr( (string) $options['height'] ) . '"';
		}

		$json = wp_json_encode( $payload );
		$attrs[] = 'data-hypercart-chart="' . esc_attr( $json ) . '"';

		do_action( 'hypercart_charts_rendered', $payload['chart']['id'] );

		return '<canvas ' . implode( ' ', $attrs ) . '></canvas>';
	}

	/**
	 * Normalize a raw point to `{x,y}` where x is UTC epoch ms.
	 *
	 * @since 1.1.0
	 * @param mixed $raw_point Raw point (array/object with x/y).
	 * @param array $dataset   Dataset.
	 * @param array $chart     Chart.
	 * @return array|null
	 */
	private static function normalize_point( $raw_point, array $dataset, array $chart ): ?array {
		$point = null;

		if ( is_array( $raw_point ) ) {
			$x = $raw_point['x'] ?? null;
			$y = $raw_point['y'] ?? null;
		} elseif ( is_object( $raw_point ) ) {
			$x = $raw_point->x ?? null;
			$y = $raw_point->y ?? null;
		} else {
			return null;
		}

		$x_ms = self::normalize_timestamp_to_ms( $x );
		if ( null === $x_ms ) {
			return null;
		}

		if ( ! is_numeric( $y ) ) {
			return null;
		}

		$point = array(
			'x' => (int) $x_ms,
			'y' => (float) $y,
		);

		return apply_filters( 'hypercart_charts_point_format', $point, $raw_point, $dataset, $chart );
	}

	/**
	 * Convert a timestamp (seconds/ms) or ISO8601 string to epoch ms.
	 *
	 * @since 1.1.0
	 * @param mixed $x Timestamp input.
	 * @return int|null
	 */
	private static function normalize_timestamp_to_ms( $x ): ?int {
		if ( is_numeric( $x ) ) {
			$val = (float) $x;
			// Heuristic: 10-digit seconds vs 13-digit ms.
			if ( $val < 100000000000 ) {
				$val = $val * 1000;
			}
			return (int) round( $val );
		}

		if ( is_string( $x ) && '' !== trim( $x ) ) {
			$ts = strtotime( $x );
			if ( false === $ts ) {
				return null;
			}
			return (int) ( $ts * 1000 );
		}

		return null;
	}

	/**
	 * Default Chart.js config baseline.
	 *
	 * Returned config is intentionally minimal and safe to override via filter.
	 *
	 * @since 1.1.0
	 * @param array $chart Normalized chart.
	 * @return array
	 */
	private static function default_chartjs_config( array $chart ): array {
		// Default JS-side formatting is handled in assets/js/hypercart-charts.js.
		$tooltip_callbacks = apply_filters( 'hypercart_charts_tooltip_callbacks', array(), $chart );

		$datasets = array();
		foreach ( $chart['datasets'] as $ds ) {
			$ds_out = array(
				'label' => $ds['label'],
				'data'  => $ds['points'],
			);
			if ( ! empty( $ds['color'] ) ) {
				$ds_out['borderColor'] = $ds['color'];
				$ds_out['backgroundColor'] = $ds['color'];
			}
			$datasets[] = $ds_out;
		}

		return array(
			'type' => 'line',
			'data' => array(
				'datasets' => $datasets,
			),
			'options' => array(
				'responsive' => true,
				'maintainAspectRatio' => false,
				'parsing' => false,
				'normalized' => true,
				'interaction' => array(
					'mode' => 'nearest',
					'intersect' => false,
				),
				'plugins' => array(
					'legend' => array(
						'display' => true,
					),
					'tooltip' => array(
						'enabled' => true,
						'callbacks' => $tooltip_callbacks,
					),
				),
				'scales' => array(
					// Use linear x-scale by default so it works without a time adapter.
					'x' => array(
						'type' => 'linear',
					),
					'y' => array(
						'beginAtZero' => true,
					),
				),
			),
		);
	}
}
