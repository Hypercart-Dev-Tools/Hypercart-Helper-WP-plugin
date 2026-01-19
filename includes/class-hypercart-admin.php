<?php
/**
 * Admin interface for Hypercart Helper
 *
 * Provides settings page with self-test functionality.
 *
 * @package Hypercart_Helper
 * @since   1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hypercart_Admin {
	/**
	 * Settings page slug.
	 *
	 * @since 1.1.1
	 * @var string
	 */
	private const PAGE_SLUG = 'hypercart-helper';

	/**
	 * Initialize admin functionality
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_post_hypercart_run_self_test', array( __CLASS__, 'handle_self_test' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_styles' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HYPERCART_HELPER_FILE ), array( __CLASS__, 'add_plugin_action_links' ) );

		// Enqueue chart assets on the settings page (only when needed).
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_chart_assets' ) );
	}

	/**
	 * Add admin menu item
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function add_admin_menu(): void {
		add_options_page(
			sprintf( __( 'Hypercart Helper Settings v%s', 'hypercart-helper' ), HYPERCART_HELPER_VERSION ),
			__( 'Hypercart Helper', 'hypercart-helper' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);

		// Add a hidden page for the Markdown viewer
		add_submenu_page(
			null, // This hides it from the menu
			__( 'Hypercart Security Guide', 'hypercart-helper' ),
			__( 'Hypercart Security Guide', 'hypercart-helper' ),
			'manage_options',
			'hypercart-security-guide',
			array( __CLASS__, 'render_security_guide_page' )
		);
	}

	/**
	 * Add plugin action links on plugins page
	 *
	 * @since 1.0.1
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public static function add_plugin_action_links( array $links ): array {
		$self_test_url = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => 'self-test',
			),
			admin_url( 'options-general.php' )
		);

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $self_test_url ),
			esc_html__( 'Self Test', 'hypercart-helper' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Enqueue admin styles
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_styles( string $hook ): void {
		if ( 'settings_page_hypercart-helper' !== $hook ) {
			return;
		}

		// Enqueue the tab UI helper stylesheet (if available).
		if ( class_exists( 'Hypercart_Admin_Tabs' ) ) {
			Hypercart_Admin_Tabs::enqueue_assets();
		}

		// Inline styles for self-test results
		$custom_css = "
			.hypercart-test-results { margin-top: 20px; }
			.hypercart-test-item { 
				background: #fff; 
				border-left: 4px solid #ddd; 
				padding: 15px 20px; 
				margin-bottom: 15px;
				box-shadow: 0 1px 1px rgba(0,0,0,0.04);
			}
			.hypercart-test-item.pass { border-left-color: #46b450; }
			.hypercart-test-item.fail { border-left-color: #dc3232; }
			.hypercart-test-item h3 { 
				margin: 0 0 10px 0; 
				font-size: 14px;
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.hypercart-test-badge {
				display: inline-block;
				padding: 3px 10px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
			}
			.hypercart-test-badge.pass { background: #46b450; color: #fff; }
			.hypercart-test-badge.fail { background: #dc3232; color: #fff; }
			.hypercart-test-details { 
				margin: 10px 0 0 0; 
				padding: 10px;
				background: #f9f9f9;
				border-radius: 3px;
				font-family: monospace;
				font-size: 12px;
			}
			.hypercart-test-details dt { 
				font-weight: 600; 
				margin-top: 8px;
				color: #555;
			}
			.hypercart-test-details dt:first-child { margin-top: 0; }
			.hypercart-test-details dd { 
				margin: 4px 0 0 20px;
				color: #333;
			}
			.hypercart-error-message {
				color: #dc3232;
				font-weight: 600;
				margin-top: 5px;
			}
			.hypercart-info-box {
				background: #e5f5fa;
				border-left: 4px solid #00a0d2;
				padding: 12px;
				margin: 20px 0;
			}
			.hypercart-info-box p { margin: 0.5em 0; }
			.hypercart-info-box p:first-child { margin-top: 0; }
			.hypercart-info-box p:last-child { margin-bottom: 0; }
		";
		wp_add_inline_style( 'wp-admin', $custom_css );
	}

	/**
	 * Conditionally enqueue chart assets for the Helper settings page.
	 *
	 * @since 1.1.0
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function maybe_enqueue_chart_assets( string $hook ): void {
		if ( 'settings_page_hypercart-helper' !== $hook ) {
			return;
		}

		// Only enqueue when chart helper is available.
		if ( class_exists( 'Hypercart_Charts' ) ) {
			Hypercart_Charts::enqueue( array( 'context' => 'admin' ) );
		}
	}

	/**
	 * Render settings page
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get test results from transient if available
		$test_results = get_transient( 'hypercart_self_test_results' );
		if ( $test_results ) {
			delete_transient( 'hypercart_self_test_results' );
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( self::is_log_dir_insecure() ) : ?>
				<div class="notice notice-warning is-dismissible">
					<h3><?php esc_html_e( 'Security Warning: Log Directory May Be Public', 'hypercart-helper' ); ?></h3>
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: The name of the log directory. 2: The URL to the security guide. */
								__( 'Your log directory <code>%1$s</code> is located within the web root, which means log files could be publicly accessible. For guidance on securing your logs, please review the <strong><a href="%2$s">Hypercart Security Guide</a></strong>.', 'hypercart-helper' ),
								esc_html( basename( Hypercart_Logger::get_log_dir() ) ),
								esc_url( admin_url( 'admin.php?page=hypercart-security-guide' ) )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="hypercart-info-box">
				<p><strong><?php esc_html_e( 'Hypercart Helper v' . HYPERCART_HELPER_VERSION, 'hypercart-helper' ); ?></strong></p>
				<p><?php esc_html_e( 'Shared utilities for the Hypercart plugin suite. Provides centralized time handling (UTC storage, local display) and structured file-based logging.', 'hypercart-helper' ); ?></p>
			</div>

			<?php
			if ( class_exists( 'Hypercart_Admin_Tabs' ) ) {
				Hypercart_Admin_Tabs::render(
					self::PAGE_SLUG,
					array(
						'default_tab'  => 'self-test',
						'tabs'         => self::get_settings_tabs(),
						'test_results' => $test_results,
					)
				);
			} else {
				// Fallback: tabs helper not available for some reason.
				self::render_tab_self_test( array(), array( 'test_results' => $test_results ) );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Get settings page tabs.
	 *
	 * @since 1.1.1
	 * @return array<int, array>
	 */
	private static function get_settings_tabs(): array {
		return array(
			array(
				'id'              => 'settings',
				'label'           => __( 'Settings', 'hypercart-helper' ),
				'icon'            => 'dashicons-admin-generic',
				'capability'      => 'manage_options',
				'render_callback' => array( __CLASS__, 'render_tab_settings' ),
			),
			array(
				'id'              => 'self-test',
				'label'           => __( 'Self Test', 'hypercart-helper' ),
				'icon'            => 'dashicons-yes-alt',
				'capability'      => 'manage_options',
				'render_callback' => array( __CLASS__, 'render_tab_self_test' ),
			),
			array(
				'id'              => 'demo',
				'label'           => __( 'Demo', 'hypercart-helper' ),
				'icon'            => 'dashicons-chart-area',
				'capability'      => 'manage_options',
				'render_callback' => array( __CLASS__, 'render_tab_demo' ),
			),
			array(
				'id'              => 'changelog',
				'label'           => __( 'Changelog', 'hypercart-helper' ),
				'icon'            => 'dashicons-media-text',
				'capability'      => 'manage_options',
				'render_callback' => array( __CLASS__, 'render_tab_changelog' ),
			),
		);
	}

	/**
	 * Render Settings tab.
	 *
	 * @since 1.1.1
	 * @param array $tab Current tab.
	 * @param array $args Render args.
	 * @return void
	 */
	public static function render_tab_settings( array $tab = array(), array $args = array() ): void {
		unset( $tab, $args );
		echo '<h2>' . esc_html__( 'Settings', 'hypercart-helper' ) . '</h2>';
		echo '<p>' . esc_html__( 'Hypercart Helper is primarily a shared utility plugin. There are no user-configurable settings in v1.1.', 'hypercart-helper' ) . '</p>';
		echo '<p>' . esc_html__( 'Use the tabs above to run Self Tests, preview the Demo chart, or review the Changelog.', 'hypercart-helper' ) . '</p>';
	}

	/**
	 * Render Self Tests tab.
	 *
	 * @since 1.1.1
	 * @param array $tab Current tab.
	 * @param array $args Render args.
	 * @return void
	 */
	public static function render_tab_self_test( array $tab = array(), array $args = array() ): void {
		unset( $tab );
		$test_results = isset( $args['test_results'] ) && is_array( $args['test_results'] ) ? $args['test_results'] : null;

		echo '<h2>' . esc_html__( 'Self Test', 'hypercart-helper' ) . '</h2>';
		echo '<p>' . esc_html__( 'Run diagnostic tests to verify that Hypercart Helper is functioning correctly.', 'hypercart-helper' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hypercart_self_test', 'hypercart_self_test_nonce' ); ?>
			<input type="hidden" name="action" value="hypercart_run_self_test">
			<?php submit_button( __( 'Run Self Test', 'hypercart-helper' ), 'primary', 'submit', false ); ?>
		</form>
		<?php

		if ( $test_results ) {
			echo '<div class="hypercart-test-results">';
			self::render_test_results( $test_results );
			echo '</div>';
		}

		if ( ! empty( $test_results['charts'] ) && ! empty( $test_results['charts']['pass'] ) && class_exists( 'Hypercart_Charts' ) ) {
			$demo_chart = self::get_demo_chart_definition();
			if ( is_array( $demo_chart ) ) {
				echo '<h2>' . esc_html__( 'Chart Test', 'hypercart-helper' ) . '</h2>';
				echo '<p>' . esc_html__( 'Demo chart below validates Chart.js loading, dataset overlays, and hover tooltips.', 'hypercart-helper' ) . '</p>';
				echo '<div style="max-width: 920px; height: 320px; background: #fff; padding: 12px; border: 1px solid #ccd0d4;">';
				echo Hypercart_Charts::render_canvas( $demo_chart, array( 'height' => 280 ) );
				echo '</div>';
			}
		}
	}

	/**
	 * Render Demo tab.
	 *
	 * @since 1.1.1
	 * @param array $tab Current tab.
	 * @param array $args Render args.
	 * @return void
	 */
	public static function render_tab_demo( array $tab = array(), array $args = array() ): void {
		unset( $tab, $args );
		echo '<h2>' . esc_html__( 'Demo', 'hypercart-helper' ) . '</h2>';
		echo '<p>' . esc_html__( 'This is a quick demo to validate the chart helper rendering on an admin screen.', 'hypercart-helper' ) . '</p>';

		if ( ! class_exists( 'Hypercart_Charts' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Charts helper is not available in this environment.', 'hypercart-helper' ) . '</p></div>';
			return;
		}

		$demo_chart = self::get_demo_chart_definition();
		if ( ! is_array( $demo_chart ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Unable to load demo chart definition.', 'hypercart-helper' ) . '</p></div>';
			return;
		}

		echo '<div style="max-width: 920px; height: 340px; background: #fff; padding: 12px; border: 1px solid #ccd0d4;">';
		echo Hypercart_Charts::render_canvas( $demo_chart, array( 'height' => 300 ) );
		echo '</div>';
	}

	/**
	 * Render Changelog tab.
	 *
	 * @since 1.1.1
	 * @param array $tab Current tab.
	 * @param array $args Render args.
	 * @return void
	 */
	public static function render_tab_changelog( array $tab = array(), array $args = array() ): void {
		unset( $tab, $args );
		echo '<h2>' . esc_html__( 'Changelog', 'hypercart-helper' ) . '</h2>';

		$file = trailingslashit( HYPERCART_HELPER_DIR ) . 'CHANGELOG.md';
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'CHANGELOG.md is not readable.', 'hypercart-helper' ) . '</p></div>';
			return;
		}

			if ( ! class_exists( 'Hypercart_Markdown_Viewer' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Markdown Viewer is not available to render the changelog.', 'hypercart-helper' ) . '</p></div>';
				return;
			}

			$html = Hypercart_Markdown_Viewer::render_file(
				$file,
				array(
					'class' => array( 'hh-markdown-viewer--changelog' ),
				)
			);
			if ( '' === $html ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Unable to render CHANGELOG.md.', 'hypercart-helper' ) . '</p></div>';
				return;
			}

			echo '<div style="max-height: 520px; overflow: auto; background: #f6f7f7; border: 1px solid #ccd0d4; padding: 12px;">' . $html . '</div>';
	}

	/**
	 * Render the security guide page (README-SECURITY.md).
	 *
	 * @since 1.1.4
	 * @return void
	 */
	public static function render_security_guide_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'hypercart-helper' ) );
		}

		$file = trailingslashit( HYPERCART_HELPER_DIR ) . 'README-SECURITY.md';
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			wp_die( esc_html__( 'README-SECURITY.md is not readable.', 'hypercart-helper' ) );
		}

		if ( ! class_exists( 'Hypercart_Markdown_Viewer' ) ) {
			wp_die( esc_html__( 'Markdown Viewer is not available.', 'hypercart-helper' ) );
		}

		$html = Hypercart_Markdown_Viewer::render_file(
			$file,
			array(
				'class' => array( 'hh-markdown-viewer--security-guide' ),
			)
		);

		if ( '' === $html ) {
			wp_die( esc_html__( 'Unable to render README-SECURITY.md.', 'hypercart-helper' ) );
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Hypercart Helper Security Guide', 'hypercart-helper' ); ?></h1>
			<div style="max-width: 900px; background: #fff; border: 1px solid #ccd0d4; padding: 20px;">
				<?php echo $html; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Load a demo chart definition from assets/demo/chart-test.json with fallback.
	 *
	 * @since 1.1.1
	 * @return array|null
	 */
	private static function get_demo_chart_definition(): ?array {
		$demo_file = HYPERCART_HELPER_DIR . 'assets/demo/chart-test.json';
		if ( file_exists( $demo_file ) && is_readable( $demo_file ) ) {
			$raw = file_get_contents( $demo_file );
			if ( false !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) && ! empty( $decoded['datasets'] ) ) {
					return $decoded;
				}
			}
		}

		// Fallback: generate a small 7-day series.
		$day_seconds = 86400;
		$now         = class_exists( 'Hypercart_Time' ) ? Hypercart_Time::now() : time();
		$base        = $now - ( 6 * $day_seconds );
		$points_a    = array();
		$points_b    = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$ts         = ( $base + ( $i * $day_seconds ) ) * 1000;
			$points_a[] = array( 'x' => $ts, 'y' => 10 + ( $i * 3 ) );
			$points_b[] = array( 'x' => $ts, 'y' => 22 - ( $i * 2 ) );
		}

		return array(
			'id'       => 'hypercart-helper-demo-chart',
			'title'    => 'Chart Demo (Fallback)',
			'datasets' => array(
				array(
					'key'    => 'series_a',
					'label'  => 'Series A',
					'color'  => '#2271b1',
					'points' => $points_a,
				),
				array(
					'key'    => 'series_b',
					'label'  => 'Series B',
					'color'  => '#d63638',
					'points' => $points_b,
				),
			),
		);
	}

	/**
	 * Handle self-test form submission
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function handle_self_test(): void {
		// Verify nonce and permissions
		if ( ! isset( $_POST['hypercart_self_test_nonce'] ) ||
		     ! wp_verify_nonce( $_POST['hypercart_self_test_nonce'], 'hypercart_self_test' ) ) {
			wp_die( esc_html__( 'Security check failed', 'hypercart-helper' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'hypercart-helper' ) );
		}

		// Run all tests
		$results = array(
			'plugin_detection' => self::test_plugin_detection(),
			'time_handling'    => self::test_time_handling(),
			'log_handling'     => self::test_log_handling(),
			'charts'           => self::test_charts(),
				'markdown_viewer'  => self::test_markdown_viewer(),
		);

		// Store results in transient for display
		set_transient( 'hypercart_self_test_results', $results, 60 );

		// Redirect back to settings page
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => 'self-test',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Test 1: Plugin Detection
	 *
	 * @since 1.0.0
	 * @return array Test results.
	 */
	private static function test_plugin_detection(): array {
		$result = array(
			'title'   => __( 'Plugin Detection Test', 'hypercart-helper' ),
			'pass'    => true,
			'message' => '',
			'details' => array(),
		);

		// Check if classes exist
		$classes_to_check = array(
			'Hypercart_Time'   => class_exists( 'Hypercart_Time' ),
			'Hypercart_Logger' => class_exists( 'Hypercart_Logger' ),
			'Hypercart_Charts' => class_exists( 'Hypercart_Charts' ),
				'Hypercart_Markdown_Viewer' => class_exists( 'Hypercart_Markdown_Viewer' ),
		);

		$result['details']['Classes Loaded'] = '';
		foreach ( $classes_to_check as $class => $exists ) {
			$status = $exists ? '✓' : '✗';
			$result['details']['Classes Loaded'] .= "{$status} {$class}\n";
			if ( ! $exists ) {
				$result['pass'] = false;
			}
		}
		$result['details']['Classes Loaded'] = trim( $result['details']['Classes Loaded'] );

		// Check constants
		$constants_to_check = array(
			'HYPERCART_HELPER_VERSION' => defined( 'HYPERCART_HELPER_VERSION' ),
			'HYPERCART_HELPER_DIR'     => defined( 'HYPERCART_HELPER_DIR' ),
			'HYPERCART_HELPER_FILE'    => defined( 'HYPERCART_HELPER_FILE' ),
		);

		$result['details']['Constants Defined'] = '';
		foreach ( $constants_to_check as $constant => $exists ) {
			$status = $exists ? '✓' : '✗';
			$value = $exists ? constant( $constant ) : 'NOT DEFINED';
			$result['details']['Constants Defined'] .= "{$status} {$constant}: {$value}\n";
			if ( ! $exists ) {
				$result['pass'] = false;
			}
		}
		$result['details']['Constants Defined'] = trim( $result['details']['Constants Defined'] );

		// Check plugin version
		$result['details']['Plugin Version'] = HYPERCART_HELPER_VERSION;

		// Check WordPress version
		global $wp_version;
		$result['details']['WordPress Version'] = $wp_version;
		$result['details']['PHP Version'] = PHP_VERSION;

		if ( $result['pass'] ) {
			$result['message'] = __( 'Hypercart Helper plugin detected and all components loaded successfully.', 'hypercart-helper' );
		} else {
			$result['message'] = __( 'Plugin detection failed. Some components are missing.', 'hypercart-helper' );
		}

		return $result;
	}

	/**
	 * Test 2: Time Handling
	 *
	 * @since 1.0.0
	 * @return array Test results.
	 */
	private static function test_time_handling(): array {
		$result = array(
			'title'   => __( 'Time Handling Test', 'hypercart-helper' ),
			'pass'    => true,
			'message' => '',
			'details' => array(),
		);

		try {
			// Test 1: Basic timestamp
			$now = Hypercart_Time::now();
			$result['details']['Current UTC Timestamp'] = $now;

			if ( ! is_int( $now ) || $now <= 0 ) {
				throw new Exception( 'now() did not return valid timestamp' );
			}

			// Test 2: UTC formatting
			$utc_formatted = Hypercart_Time::utc_format( 'Y-m-d H:i:s', $now );
			$result['details']['UTC Format'] = $utc_formatted;

			if ( empty( $utc_formatted ) ) {
				throw new Exception( 'utc_format() returned empty string' );
			}

			// Test 3: Local formatting
			$local_formatted = Hypercart_Time::format( 'Y-m-d H:i:s', $now );
			$result['details']['Local Format'] = $local_formatted;

			if ( empty( $local_formatted ) ) {
				throw new Exception( 'format() returned empty string' );
			}

			// Test 4: ISO 8601
			$iso = Hypercart_Time::iso8601( $now );
			$result['details']['ISO 8601'] = $iso;

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $iso ) ) {
				throw new Exception( 'iso8601() format invalid' );
			}

			// Test 5: Timezone info
			$tz_name = Hypercart_Time::get_timezone_name();
			$tz_offset = Hypercart_Time::get_offset_string( $now );
			$result['details']['Site Timezone'] = "{$tz_name} ({$tz_offset})";

			// Test 6: Weekly slot
			$slot = Hypercart_Time::get_weekly_slot( $now );
			$result['details']['Weekly Slot'] = $slot . ' (0-167)';

			if ( $slot < 0 || $slot > 167 ) {
				throw new Exception( 'get_weekly_slot() returned invalid value: ' . $slot );
			}

			// Test 7: Mock time
			$mock_timestamp = strtotime( '2024-01-15 12:00:00 UTC' );
			Hypercart_Time::set_mock_time( $mock_timestamp );
			$mocked = Hypercart_Time::now();
			Hypercart_Time::reset_mock_time();

			if ( $mocked !== $mock_timestamp ) {
				throw new Exception( 'Mock time test failed' );
			}
			$result['details']['Mock Time Test'] = '✓ Passed';

			$result['message'] = __( 'All time handling functions working correctly.', 'hypercart-helper' );

		} catch ( Exception $e ) {
			$result['pass'] = false;
			$result['message'] = __( 'Time handling test failed.', 'hypercart-helper' );
			$result['details']['Error'] = $e->getMessage();
		}

		return $result;
	}
	/**
	 * Test 3: Log Handling
	 *
	 * @since 1.0.0
	 * @return array Test results.
	 */
	private static function test_log_handling(): array {
		$result = array(
			'title'   => __( 'Log Handling Test', 'hypercart-helper' ),
			'pass'    => true,
			'message' => '',
			'details' => array(),
		);

		try {
			// Test 1: Get log directory
			$log_dir = Hypercart_Logger::get_log_dir();

			if ( false === $log_dir ) {
				throw new Exception( 'Failed to get/create log directory' );
			}

			$result['details']['Log Directory'] = $log_dir;

			if ( ! is_dir( $log_dir ) ) {
				throw new Exception( 'Log directory does not exist: ' . $log_dir );
			}

			if ( ! is_writable( $log_dir ) ) {
				throw new Exception( 'Log directory is not writable: ' . $log_dir );
			}

			// Test 2: Check security files
			$htaccess_exists = file_exists( $log_dir . '/.htaccess' );
			$index_exists = file_exists( $log_dir . '/index.php' );

			$result['details']['Security Files'] =
				( $htaccess_exists ? '✓' : '✗' ) . ' .htaccess | ' .
				( $index_exists ? '✓' : '✗' ) . ' index.php';

			if ( ! $htaccess_exists || ! $index_exists ) {
				throw new Exception( 'Security files missing in log directory' );
			}

			// Test 3: Write test log entry
			$test_message = 'Self-test log entry at ' . Hypercart_Time::utc_format( 'Y-m-d H:i:s' );
			$write_result = Hypercart_Logger::info( 'helper-selftest', $test_message, array(
				'test_id' => uniqid( 'test_' ),
				'user_id' => get_current_user_id(),
			) );

			if ( ! $write_result ) {
				throw new Exception( 'Failed to write test log entry' );
			}

			$result['details']['Write Test'] = '✓ Successfully wrote test log entry';

			// Test 4: Get current log file
			$log_file = Hypercart_Logger::get_log_file();

			if ( false === $log_file ) {
				throw new Exception( 'Failed to get current log file path' );
			}

			$result['details']['Current Log File'] = basename( $log_file );

			if ( ! file_exists( $log_file ) ) {
				throw new Exception( 'Log file does not exist: ' . $log_file );
			}

			// Test 5: Read log file
			$log_content = Hypercart_Logger::read_log( null, -5 );

			if ( false === $log_content ) {
				throw new Exception( 'Failed to read log file' );
			}

			$result['details']['Read Test'] = '✓ Successfully read last 5 log entries';

			// Test 6: Verify test entry exists in log
			if ( strpos( $log_content, $test_message ) === false ) {
				throw new Exception( 'Test log entry not found in log file' );
			}

			$result['details']['Verify Test'] = '✓ Test entry found in log file';

			// Test 7: Redaction check
			$redaction_message = 'Redaction test ' . wp_generate_uuid4();
			Hypercart_Logger::info( 'self-test', $redaction_message, array( 'token' => 'secret-token' ) );
			$redaction_content = Hypercart_Logger::read_log( null, -200 );
			if ( false === $redaction_content ) {
				throw new Exception( 'Failed to read log file for redaction test' );
			}
			if ( false === strpos( $redaction_content, $redaction_message ) ) {
				$redaction_content = Hypercart_Logger::read_log( null, 0 );
				if ( false === $redaction_content ) {
					throw new Exception( 'Failed to read full log file for redaction test' );
				}
			}
			$pattern = '/' . preg_quote( $redaction_message, '/' ) . '.*"token":"\\[REDACTED\\]".*"_hh_redacted_by":"hypercart-helper"/s';
			if ( ! preg_match( $pattern, $redaction_content ) ) {
				throw new Exception( 'Redaction did not mask blacklisted token' );
			}
			$result['details']['Redaction'] = '✓ Redaction masked token and added source marker';

			// Test 8: Get log files list
			$log_files = Hypercart_Logger::get_log_files();
			$file_count = count( $log_files );
			$total_size = array_sum( $log_files );

			$result['details']['Log Files'] = $file_count . ' file(s), ' . size_format( $total_size ) . ' total';

			// Test 9: Test log levels
			$levels_tested = array();
			foreach ( array( 'debug', 'info', 'warning', 'error' ) as $level ) {
				$method = array( 'Hypercart_Logger', $level );
				if ( is_callable( $method ) ) {
					$levels_tested[] = '✓ ' . strtoupper( $level );
				} else {
					$levels_tested[] = '✗ ' . strtoupper( $level );
					throw new Exception( "Log level method not callable: {$level}" );
				}
			}
			$result['details']['Log Levels'] = implode( ' | ', $levels_tested );

			// Add security check result to the details
			if ( self::is_log_dir_insecure() ) {
				$result['details']['Security Status'] = '✗ WARNING: Log directory is in a public web directory and may be accessible. Please review the security notice at the top of this page.';
			} else {
				$result['details']['Security Status'] = '✓ OK: Log directory appears to be in a secure, non-public location.';
			}

			$result['message'] = __( 'All log handling functions working correctly.', 'hypercart-helper' );

		} catch ( Exception $e ) {
			$result['pass'] = false;
			$result['message'] = __( 'Log handling test failed.', 'hypercart-helper' );
			$result['details']['Error'] = $e->getMessage();
		}

		return $result;
	}

	/**
	 * Test 4: Charts
	 *
	 * Validates helper availability and that assets have been registered.
	 *
	 * @since 1.1.0
	 * @return array Test results.
	 */
	private static function test_charts(): array {
		$result = array(
			'title'   => __( 'Charts Test', 'hypercart-helper' ),
			'pass'    => true,
			'message' => '',
			'details' => array(),
		);

		if ( ! class_exists( 'Hypercart_Charts' ) ) {
			$result['pass'] = false;
			$result['message'] = __( 'Hypercart_Charts class not available.', 'hypercart-helper' );
			$result['details']['Class'] = '✗ Hypercart_Charts';
			return $result;
		}

		$result['details']['Class'] = '✓ Hypercart_Charts';

		// Assets should be registered on init.
		$chartjs_registered = wp_script_is( 'hypercart-chartjs', 'registered' );
		$wrapper_registered = wp_script_is( 'hypercart-charts', 'registered' );

		$result['details']['Assets Registered'] =
			( $chartjs_registered ? '✓' : '✗' ) . ' hypercart-chartjs | ' .
			( $wrapper_registered ? '✓' : '✗' ) . ' hypercart-charts';

		if ( ! $chartjs_registered || ! $wrapper_registered ) {
			$result['pass'] = false;
			$result['message'] = __( 'Chart assets are not registered. Ensure init ran.', 'hypercart-helper' );
			return $result;
		}

		// Validate that expected asset files exist on disk.
		$chartjs_rel = defined( 'Hypercart_Charts::CHARTJS_REL_PATH' ) ? Hypercart_Charts::CHARTJS_REL_PATH : 'assets/vendor/chartjs/chart.umd.min.js';
		$wrapper_rel = defined( 'Hypercart_Charts::WRAPPER_REL_PATH' ) ? Hypercart_Charts::WRAPPER_REL_PATH : 'assets/js/hypercart-charts.js';

		$chartjs_path = HYPERCART_HELPER_DIR . ltrim( $chartjs_rel, '/' );
		$wrapper_path = HYPERCART_HELPER_DIR . ltrim( $wrapper_rel, '/' );

		$chartjs_exists = file_exists( $chartjs_path );
		$wrapper_exists = file_exists( $wrapper_path );

		// Enhanced debugging information
		$debug_info = array();

		// Check HYPERCART_HELPER_DIR constant
		$debug_info[] = 'HYPERCART_HELPER_DIR: ' . HYPERCART_HELPER_DIR;
		$debug_info[] = 'HYPERCART_HELPER_DIR exists: ' . ( is_dir( HYPERCART_HELPER_DIR ) ? 'YES' : 'NO' );
		$debug_info[] = 'HYPERCART_HELPER_DIR readable: ' . ( is_readable( HYPERCART_HELPER_DIR ) ? 'YES' : 'NO' );

		// Check assets directory
		$assets_dir = HYPERCART_HELPER_DIR . 'assets/';
		$debug_info[] = 'Assets dir: ' . $assets_dir;
		$debug_info[] = 'Assets dir exists: ' . ( is_dir( $assets_dir ) ? 'YES' : 'NO' );
		$debug_info[] = 'Assets dir readable: ' . ( is_readable( $assets_dir ) ? 'YES' : 'NO' );

		// Check vendor/chartjs directory
		$vendor_dir = HYPERCART_HELPER_DIR . 'assets/vendor/';
		$chartjs_dir = HYPERCART_HELPER_DIR . 'assets/vendor/chartjs/';
		$debug_info[] = 'Vendor dir exists: ' . ( is_dir( $vendor_dir ) ? 'YES' : 'NO' );
		$debug_info[] = 'ChartJS dir: ' . $chartjs_dir;
		$debug_info[] = 'ChartJS dir exists: ' . ( is_dir( $chartjs_dir ) ? 'YES' : 'NO' );

		// Check js directory
		$js_dir = HYPERCART_HELPER_DIR . 'assets/js/';
		$debug_info[] = 'JS dir: ' . $js_dir;
		$debug_info[] = 'JS dir exists: ' . ( is_dir( $js_dir ) ? 'YES' : 'NO' );

		// Detailed file path debugging
		$debug_info[] = '--- ChartJS File ---';
		$debug_info[] = 'Relative path: ' . $chartjs_rel;
		$debug_info[] = 'Full path: ' . $chartjs_path;
		$debug_info[] = 'File exists: ' . ( $chartjs_exists ? 'YES' : 'NO' );
		$debug_info[] = 'File readable: ' . ( is_readable( $chartjs_path ) ? 'YES' : 'NO' );
		$debug_info[] = 'File size: ' . ( $chartjs_exists ? filesize( $chartjs_path ) . ' bytes' : 'N/A' );

		// List files in chartjs directory if it exists
		if ( is_dir( $chartjs_dir ) ) {
			$chartjs_files = scandir( $chartjs_dir );
			$debug_info[] = 'Files in ChartJS dir: ' . implode( ', ', array_diff( $chartjs_files, array( '.', '..' ) ) );
		}

		$debug_info[] = '--- Wrapper File ---';
		$debug_info[] = 'Relative path: ' . $wrapper_rel;
		$debug_info[] = 'Full path: ' . $wrapper_path;
		$debug_info[] = 'File exists: ' . ( $wrapper_exists ? 'YES' : 'NO' );
		$debug_info[] = 'File readable: ' . ( is_readable( $wrapper_path ) ? 'YES' : 'NO' );
		$debug_info[] = 'File size: ' . ( $wrapper_exists ? filesize( $wrapper_path ) . ' bytes' : 'N/A' );

		// List files in js directory if it exists
		if ( is_dir( $js_dir ) ) {
			$js_files = scandir( $js_dir );
			$debug_info[] = 'Files in JS dir: ' . implode( ', ', array_diff( $js_files, array( '.', '..' ) ) );
		}

		// Check for symlinks
		$debug_info[] = '--- Symlink Check ---';
		$debug_info[] = 'ChartJS is symlink: ' . ( is_link( $chartjs_path ) ? 'YES' : 'NO' );
		$debug_info[] = 'Wrapper is symlink: ' . ( is_link( $wrapper_path ) ? 'YES' : 'NO' );

		// Check permissions
		$debug_info[] = '--- Permissions ---';
		if ( $chartjs_exists ) {
			$debug_info[] = 'ChartJS perms: ' . substr( sprintf( '%o', fileperms( $chartjs_path ) ), -4 );
		}
		if ( $wrapper_exists ) {
			$debug_info[] = 'Wrapper perms: ' . substr( sprintf( '%o', fileperms( $wrapper_path ) ), -4 );
		}

		// Add debug info to details
		$result['details']['Debug Info'] = implode( "\n", $debug_info );

		$result['details']['Asset Files'] =
			( $chartjs_exists ? '✓' : '✗' ) . ' ' . $chartjs_rel . "\n" .
			( $wrapper_exists ? '✓' : '✗' ) . ' ' . $wrapper_rel;

		$result['details']['Asset URLs'] =
			HYPERCART_HELPER_URL . ltrim( $chartjs_rel, '/' ) . "\n" .
			HYPERCART_HELPER_URL . ltrim( $wrapper_rel, '/' );

		if ( ! $chartjs_exists || ! $wrapper_exists ) {
			$result['pass'] = false;
			$result['message'] = __( 'Chart asset files are missing. Reinstall/restore the plugin assets directory.', 'hypercart-helper' );
			return $result;
		}

		$result['message'] = __( 'Chart helper available and assets registered/files present.', 'hypercart-helper' );
		return $result;
	}

		/**
		 * Test 5: Markdown Viewer
		 *
		 * Validates helper availability and basic Markdown rendering.
		 *
		 * @since 1.1.3
		 * @return array Test results.
		 */
		private static function test_markdown_viewer(): array {
			$result = array(
				'title'   => __( 'Markdown Viewer Test', 'hypercart-helper' ),
				'pass'    => true,
				'message' => '',
				'details' => array(),
			);

			if ( ! class_exists( 'Hypercart_Markdown_Viewer' ) ) {
				$result['pass'] = false;
				$result['message'] = __( 'Hypercart_Markdown_Viewer class not available.', 'hypercart-helper' );
				$result['details']['Class'] = '✗ Hypercart_Markdown_Viewer';
				return $result;
			}

			$result['details']['Class'] = '✓ Hypercart_Markdown_Viewer';
			$result['details']['Shortcode Registered'] = shortcode_exists( 'hypercart_markdown' ) ? '✓ hypercart_markdown' : '✗ hypercart_markdown';

			$sample = "# Title\n\nA **bold** word and a [link](https://example.com).\n\n- One\n- Two\n\n```\ncode\n```\n";
			$html   = Hypercart_Markdown_Viewer::render_markdown( $sample );

			$checks = array(
				'<h1>'        => ( false !== strpos( $html, '<h1>' ) ),
				'<strong>'    => ( false !== strpos( $html, '<strong>' ) ),
				'<a href='    => ( false !== strpos( $html, '<a href=' ) ),
				'<ul>'        => ( false !== strpos( $html, '<ul>' ) ),
				'<pre><code>' => ( false !== strpos( $html, '<pre><code>' ) ),
			);

			$all_ok = true;
			foreach ( $checks as $label => $ok ) {
				$result['details'][ 'Contains ' . $label ] = $ok ? '✓' : '✗';
				if ( ! $ok ) {
					$all_ok = false;
				}
			}

			if ( ! $all_ok ) {
				$result['pass'] = false;
				$result['message'] = __( 'Markdown rendering did not produce expected HTML.', 'hypercart-helper' );
				return $result;
			}

			$result['message'] = __( 'Markdown Viewer available and rendering basic formatting.', 'hypercart-helper' );
			return $result;
		}

	/**
	 * Render test results
	 *
	 * @since 1.0.0
	 * @param array $results Test results array.
	 * @return void
	 */
	private static function render_test_results( array $results ): void {
		$all_passed = true;

		foreach ( $results as $test ) {
			if ( ! $test['pass'] ) {
				$all_passed = false;
				break;
			}
		}

		// Overall summary
		if ( $all_passed ) {
			echo '<div class="notice notice-success"><p><strong>' .
			     esc_html__( '✓ All Tests Passed', 'hypercart-helper' ) .
			     '</strong> - ' .
			     esc_html__( 'Hypercart Helper is functioning correctly.', 'hypercart-helper' ) .
			     '</p></div>';
		} else {
			echo '<div class="notice notice-error"><p><strong>' .
			     esc_html__( '✗ Some Tests Failed', 'hypercart-helper' ) .
			     '</strong> - ' .
			     esc_html__( 'Please review the details below.', 'hypercart-helper' ) .
			     '</p></div>';
		}

			// Individual test results
			foreach ( $results as $test ) {
			$status_class = $test['pass'] ? 'pass' : 'fail';
			$status_text = $test['pass'] ? __( 'Pass', 'hypercart-helper' ) : __( 'Fail', 'hypercart-helper' );

			?>
			<div class="hypercart-test-item <?php echo esc_attr( $status_class ); ?>">
				<h3>
					<?php echo esc_html( $test['title'] ); ?>
					<span class="hypercart-test-badge <?php echo esc_attr( $status_class ); ?>">
						<?php echo esc_html( $status_text ); ?>
					</span>
				</h3>
				<p><?php echo esc_html( $test['message'] ); ?></p>

				<?php if ( ! empty( $test['details'] ) ) : ?>
					<dl class="hypercart-test-details">
						<?php foreach ( $test['details'] as $label => $value ) : ?>
							<dt><?php echo esc_html( $label ); ?>:</dt>
							<dd><?php echo esc_html( $value ); ?></dd>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Check if the log directory is in a web-accessible location.
	 *
	 * A log directory is considered insecure if its real path is inside the
	 * web root (ABSPATH). This is a best-effort check.
	 *
	 * @since 1.2.0
	 * @return bool True if insecure, false if secure or indeterminable.
	 */
	private static function is_log_dir_insecure(): bool {
		if ( ! class_exists( 'Hypercart_Logger' ) ) {
			return false; // Cannot determine if logger class is missing.
		}
		$log_dir = Hypercart_Logger::get_log_dir();
		if ( ! $log_dir || ! file_exists( $log_dir ) ) {
			return false; // No log directory, no problem.
		}
		// Get canonicalized absolute paths.
		$web_root     = realpath( ABSPATH );
		$log_dir_path = realpath( $log_dir );
		if ( ! $web_root || ! $log_dir_path ) {
			return false; // Cannot determine real paths.
		}
		// If the log directory path starts with the web root path, it's inside.
		// Add a directory separator to avoid matching parts of a directory name.
		// e.g. /var/www-site should not match /var/www
		if ( strpos( $log_dir_path, rtrim( $web_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) === 0 ) {
			return true;
		}
		return false;
	}
}
