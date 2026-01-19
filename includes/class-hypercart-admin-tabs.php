<?php
/**
 * Tabbed navigation helper for wp-admin pages.
 *
 * Provides a lightweight API for registering and rendering a WP-style nav-tab
 * UI with optional Dashicons and configurable colors.
 *
 * @package Hypercart_Helper
 * @since 1.1.1
 */

namespace Hypercart\Helper\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tabbed_Navigation {
	/**
	 * Registered tabs keyed by admin page slug.
	 *
	 * @var array<string, array<int, array>>
	 */
	private static $tabs_by_page = array();

	/**
	 * Style handle for the tab UI.
	 */
	public const STYLE_HANDLE = 'hypercart-helper-admin-tabs';

	/**
	 * Register (do not enqueue) the tab UI stylesheet.
	 *
	 * @since 1.1.1
	 * @return void
	 */
	public static function register_assets(): void {
		if ( \wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			return;
		}

		\wp_register_style(
			self::STYLE_HANDLE,
			defined( 'HYPERCART_HELPER_URL' ) ? HYPERCART_HELPER_URL . 'assets/css/hypercart-admin-tabs.css' : '',
			array( 'dashicons' ),
			defined( 'HYPERCART_HELPER_VERSION' ) ? HYPERCART_HELPER_VERSION : null
		);
	}

	/**
	 * Enqueue the tab UI stylesheet.
	 *
	 * @since 1.1.1
	 * @return void
	 */
	public static function enqueue_assets(): void {
		self::register_assets();
		\wp_enqueue_style( 'dashicons' );
		\wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Register a single tab for a given admin page.
	 *
	 * Tab array keys:
	 * - id (string, required)
	 * - label (string, required)
	 * - icon (string, optional) Dashicons class, e.g. "dashicons-admin-generic"
	 * - capability (string, optional) defaults to manage_options
	 * - render_callback (callable, required)
	 * - colors (array, optional) hex colors for CSS vars:
	 *   - tab_bg, tab_fg, hover_bg, hover_fg, active_bg, active_fg
	 *
	 * @since 1.1.1
	 * @param string $page_slug Admin page slug (usually the menu slug).
	 * @param array  $tab       Tab definition.
	 * @return void
	 */
	public static function register_tab( string $page_slug, array $tab ): void {
		$page_slug = \sanitize_key( $page_slug );
		$tab_id    = isset( $tab['id'] ) ? \sanitize_key( (string) $tab['id'] ) : '';

		if ( '' === $page_slug || '' === $tab_id ) {
			return;
		}

		if ( empty( $tab['label'] ) || empty( $tab['render_callback'] ) || ! is_callable( $tab['render_callback'] ) ) {
			return;
		}

		$normalized = array(
			'id'              => $tab_id,
			'label'           => \sanitize_text_field( (string) $tab['label'] ),
			'icon'            => isset( $tab['icon'] ) ? \sanitize_html_class( (string) $tab['icon'] ) : '',
			'capability'      => isset( $tab['capability'] ) ? \sanitize_key( (string) $tab['capability'] ) : 'manage_options',
			'render_callback' => $tab['render_callback'],
			'colors'          => self::sanitize_colors( isset( $tab['colors'] ) && is_array( $tab['colors'] ) ? $tab['colors'] : array() ),
		);

		if ( ! isset( self::$tabs_by_page[ $page_slug ] ) ) {
			self::$tabs_by_page[ $page_slug ] = array();
		}

		self::$tabs_by_page[ $page_slug ][] = $normalized;
	}

	/**
	 * Register multiple tabs.
	 *
	 * @since 1.1.1
	 * @param string $page_slug Admin page slug.
	 * @param array  $tabs      List of tab arrays.
	 * @return void
	 */
	public static function register_tabs( string $page_slug, array $tabs ): void {
		foreach ( $tabs as $tab ) {
			if ( is_array( $tab ) ) {
				self::register_tab( $page_slug, $tab );
			}
		}
	}

	/**
	 * Render a WP-style tabbed navigation and the active tab content.
	 *
	 * Args:
	 * - base_url (string, optional) base URL for tab links (without tab param)
	 * - default_tab (string, optional)
	 * - tabs (array, optional) additional tabs to merge in at render time
	 * - nav_colors (array, optional) global hex colors for CSS vars:
	 *   - tab_bg, tab_fg, hover_bg, hover_fg, active_bg, active_fg
	 * - tab_param (string, optional) query parameter name, default "tab"
	 *
	 * Filter:
	 * - hypercart_helper_admin_tabs (array $tabs, string $page_slug, array $args)
	 *
	 * @since 1.1.1
	 * @param string $page_slug Admin page slug.
	 * @param array  $args      Render args.
	 * @return void
	 */
	public static function render( string $page_slug, array $args = array() ): void {
		$page_slug = \sanitize_key( $page_slug );
		$tab_param = isset( $args['tab_param'] ) ? \sanitize_key( (string) $args['tab_param'] ) : 'tab';
		$base_url  = isset( $args['base_url'] ) ? \esc_url_raw( (string) $args['base_url'] ) : '';

		$tabs = array();
		if ( isset( self::$tabs_by_page[ $page_slug ] ) ) {
			$tabs = self::$tabs_by_page[ $page_slug ];
		}
		if ( ! empty( $args['tabs'] ) && is_array( $args['tabs'] ) ) {
			$tabs = array_merge( $tabs, $args['tabs'] );
		}

		/**
		 * Filter the available tabs for a given page slug.
		 *
		 * @since 1.1.1
		 * @param array  $tabs      Current list of tabs.
		 * @param string $page_slug Admin page slug.
		 * @param array  $args      Render args.
		 */
		$tabs = \apply_filters( 'hypercart_helper_admin_tabs', $tabs, $page_slug, $args );

		$tabs = self::normalize_tabs( $tabs );
		$tabs = self::filter_tabs_by_capability( $tabs );

		if ( empty( $tabs ) ) {
			echo '<div class="notice notice-warning"><p>' . \esc_html__( 'No tabs are registered for this page.', 'hypercart-helper' ) . '</p></div>';
			return;
		}

		if ( '' === $base_url ) {
			$menu_url = \menu_page_url( $page_slug, false );
			if ( is_string( $menu_url ) && '' !== $menu_url ) {
				$base_url = $menu_url;
			}
		}
		if ( '' === $base_url ) {
			// Best-effort fallback.
			$base_url = \admin_url( 'admin.php?page=' . rawurlencode( $page_slug ) );
		}
		$base_url = \remove_query_arg( $tab_param, $base_url );

		$requested_tab = isset( $_GET[ $tab_param ] ) ? \sanitize_key( \wp_unslash( (string) $_GET[ $tab_param ] ) ) : '';
		$default_tab   = isset( $args['default_tab'] ) ? \sanitize_key( (string) $args['default_tab'] ) : '';

		$active_tab = self::pick_active_tab( $tabs, $requested_tab, $default_tab );
		$nav_colors = self::sanitize_colors( isset( $args['nav_colors'] ) && is_array( $args['nav_colors'] ) ? $args['nav_colors'] : array() );

		$wrapper_style = self::build_css_var_style_attr( $nav_colors );
		echo '<h2 class="nav-tab-wrapper hh-nav-tab-wrapper"' . ( '' !== $wrapper_style ? ' style="' . \esc_attr( $wrapper_style ) . '"' : '' ) . '>';
		foreach ( $tabs as $tab ) {
			$is_active = ( $tab['id'] === $active_tab['id'] );
			$url       = \add_query_arg( $tab_param, $tab['id'], $base_url );
			$classes   = 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' );
			$tab_style = self::build_css_var_style_attr( $tab['colors'] );

			echo '<a href="' . \esc_url( $url ) . '" class="' . \esc_attr( $classes ) . '"' .
				( $is_active ? ' aria-current="page"' : '' ) .
				( '' !== $tab_style ? ' style="' . \esc_attr( $tab_style ) . '"' : '' ) .
				'>';

			if ( '' !== $tab['icon'] ) {
				echo '<span class="dashicons ' . \esc_attr( $tab['icon'] ) . '" aria-hidden="true"></span>';
			}
			echo '<span class="hh-tab-label">' . \esc_html( $tab['label'] ) . '</span>';
			echo '</a>';
		}
		echo '</h2>';

		echo '<div class="hh-tab-panel">';
		self::call_render_callback( $active_tab['render_callback'], $active_tab, $args );
		echo '</div>';
	}

	/**
	 * Normalize arbitrary tab arrays (including filter-provided tabs).
	 *
	 * @since 1.1.1
	 * @param array $tabs Tabs.
	 * @return array<int, array>
	 */
	private static function normalize_tabs( array $tabs ): array {
		$normalized = array();
		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) ) {
				continue;
			}

			$tab_id = isset( $tab['id'] ) ? \sanitize_key( (string) $tab['id'] ) : '';
			if ( '' === $tab_id ) {
				continue;
			}
			if ( empty( $tab['label'] ) ) {
				continue;
			}
			if ( empty( $tab['render_callback'] ) || ! is_callable( $tab['render_callback'] ) ) {
				continue;
			}

			$normalized[] = array(
				'id'              => $tab_id,
				'label'           => \sanitize_text_field( (string) $tab['label'] ),
				'icon'            => isset( $tab['icon'] ) ? \sanitize_html_class( (string) $tab['icon'] ) : '',
				'capability'      => isset( $tab['capability'] ) ? \sanitize_key( (string) $tab['capability'] ) : 'manage_options',
				'render_callback' => $tab['render_callback'],
				'colors'          => self::sanitize_colors( isset( $tab['colors'] ) && is_array( $tab['colors'] ) ? $tab['colors'] : array() ),
			);
		}

		return $normalized;
	}

	/**
	 * Filter out tabs the current user cannot access.
	 *
	 * @since 1.1.1
	 * @param array<int, array> $tabs Tabs.
	 * @return array<int, array>
	 */
	private static function filter_tabs_by_capability( array $tabs ): array {
		$out = array();
		foreach ( $tabs as $tab ) {
			$cap = isset( $tab['capability'] ) && '' !== $tab['capability'] ? (string) $tab['capability'] : 'manage_options';
			if ( \current_user_can( $cap ) ) {
				$out[] = $tab;
			}
		}
		return $out;
	}

	/**
	 * Pick active tab based on request/default/first.
	 *
	 * @since 1.1.1
	 * @param array<int, array> $tabs          Tabs.
	 * @param string            $requested_tab Requested id.
	 * @param string            $default_tab   Default id.
	 * @return array Active tab.
	 */
	private static function pick_active_tab( array $tabs, string $requested_tab, string $default_tab ): array {
		if ( '' !== $requested_tab ) {
			foreach ( $tabs as $tab ) {
				if ( $tab['id'] === $requested_tab ) {
					return $tab;
				}
			}
		}
		if ( '' !== $default_tab ) {
			foreach ( $tabs as $tab ) {
				if ( $tab['id'] === $default_tab ) {
					return $tab;
				}
			}
		}
		return $tabs[0];
	}

	/**
	 * Sanitize hex color options.
	 *
	 * @since 1.1.1
	 * @param array $colors Raw colors.
	 * @return array<string, string>
	 */
	private static function sanitize_colors( array $colors ): array {
		$keys = array( 'tab_bg', 'tab_fg', 'hover_bg', 'hover_fg', 'active_bg', 'active_fg' );
		$out  = array();
		foreach ( $keys as $key ) {
			if ( empty( $colors[ $key ] ) ) {
				continue;
			}
			$val = \sanitize_hex_color( (string) $colors[ $key ] );
			if ( $val ) {
				$out[ $key ] = $val;
			}
		}
		return $out;
	}

	/**
	 * Build a style attribute string with CSS variables.
	 *
	 * @since 1.1.1
	 * @param array<string, string> $colors Sanitized colors.
	 * @return string
	 */
	private static function build_css_var_style_attr( array $colors ): string {
		$map = array(
			'tab_bg'    => '--hh-tab-bg',
			'tab_fg'    => '--hh-tab-fg',
			'hover_bg'  => '--hh-tab-hover-bg',
			'hover_fg'  => '--hh-tab-hover-fg',
			'active_bg' => '--hh-tab-active-bg',
			'active_fg' => '--hh-tab-active-fg',
		);

		$parts = array();
		foreach ( $map as $key => $var ) {
			if ( isset( $colors[ $key ] ) && '' !== $colors[ $key ] ) {
				$parts[] = $var . ':' . $colors[ $key ];
			}
		}
		return implode( ';', $parts );
	}

	/**
	 * Call the render callback, supporting 0/1/2 args.
	 *
	 * @since 1.1.1
	 * @param callable $callback Callback.
	 * @param array    $tab      Active tab.
	 * @param array    $args     Render args.
	 * @return void
	 */
	private static function call_render_callback( $callback, array $tab, array $args ): void {
		$param_count = 0;
		try {
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$ref = new \ReflectionMethod( $callback[0], (string) $callback[1] );
				$param_count = $ref->getNumberOfParameters();
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$ref = new \ReflectionMethod( $callback );
				$param_count = $ref->getNumberOfParameters();
			} else {
				$ref = new \ReflectionFunction( $callback );
				$param_count = $ref->getNumberOfParameters();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			$param_count = 0;
		}

		if ( $param_count >= 2 ) {
			call_user_func( $callback, $tab, $args );
			return;
		}
		if ( 1 === $param_count ) {
			call_user_func( $callback, $tab );
			return;
		}
		call_user_func( $callback );
	}
}

// Optional global alias for convenience in non-namespaced consumers.
if ( ! class_exists( 'Hypercart_Admin_Tabs', false ) ) {
	class_alias( __NAMESPACE__ . '\\Tabbed_Navigation', 'Hypercart_Admin_Tabs' );
}
