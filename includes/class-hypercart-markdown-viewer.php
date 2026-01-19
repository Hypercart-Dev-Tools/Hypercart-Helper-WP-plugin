<?php
/**
 * Markdown Viewer helper for Hypercart plugin suite.
 *
 * Provides:
 * - A lightweight Markdown-to-HTML renderer (no raw HTML passthrough)
 * - Safe output sanitization via wp_kses()
 * - A shortcode for content editors
 * - Filters/actions for consuming plugins
 *
 * This is intentionally a "good enough" Markdown subset (headings, paragraphs,
 * emphasis, links, lists, blockquotes, inline/code blocks).
 *
 * @package Hypercart_Helper
 * @since   1.1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hypercart_Markdown_Viewer {

	/**
	 * Shortcode tag.
	 *
	 * @since 1.1.3
	 */
	public const SHORTCODE = 'hypercart_markdown';

	/**
	 * Initialize hooks (shortcode registration).
	 *
	 * @since 1.1.3
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		do_action( 'hypercart_markdown_viewer_init' );
	}

	/**
	 * Shortcode handler.
	 *
	 * Usage:
	 * - [hypercart_markdown file="plugins/my-plugin/README.md"]
	 * - [hypercart_markdown]# Title[/hypercart_markdown]
	 *
	 * @since 1.1.3
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Enclosed content (optional markdown).
	 * @return string
	 */
	public static function shortcode( $atts = array(), $content = '' ): string {
		$atts = shortcode_atts(
			array(
				'file'      => '',
				'title'     => '',
				'class'     => '',
				'cache_ttl' => '0',
			),
			(array) $atts,
			self::SHORTCODE
		);

		$args = array(
			'title'     => sanitize_text_field( (string) $atts['title'] ),
			'class'     => self::sanitize_css_classes( (string) $atts['class'] ),
			'cache_ttl' => absint( $atts['cache_ttl'] ),
		);

		$content = (string) $content;
		if ( '' !== trim( $content ) ) {
			return self::render_markdown( $content, $args );
		}

		$file = (string) $atts['file'];
		if ( '' === trim( $file ) ) {
			return '';
		}

		return self::render_file( $file, $args );
	}

	/**
	 * Render a markdown string.
	 *
	 * Filters:
	 * - hypercart_markdown_viewer_markdown (string $markdown, array $args)
	 * - hypercart_markdown_viewer_allowed_tags (array $allowed_tags, array $args)
	 * - hypercart_markdown_viewer_html (string $html, array $args)
	 *
	 * Actions:
	 * - hypercart_markdown_viewer_rendered (string $html, array $args)
	 *
	 * @since 1.1.3
	 * @param string $markdown Markdown content.
	 * @param array  $args     Render args.
	 * @return string HTML.
	 */
	public static function render_markdown( string $markdown, array $args = array() ): string {
		$defaults = array(
			'title' => '',
			'class' => array(),
		);
		$args = wp_parse_args( $args, $defaults );

		$markdown = apply_filters( 'hypercart_markdown_viewer_markdown', $markdown, $args );
		$html     = self::parse_markdown_to_html( (string) $markdown );

		$allowed_tags = apply_filters( 'hypercart_markdown_viewer_allowed_tags', self::get_default_allowed_tags(), $args );
		if ( is_array( $allowed_tags ) ) {
			$html = wp_kses( $html, $allowed_tags );
		}

		$html = apply_filters( 'hypercart_markdown_viewer_html', $html, $args );
		do_action( 'hypercart_markdown_viewer_rendered', $html, $args );

		$classes = array_merge( array( 'hh-markdown-viewer' ), is_array( $args['class'] ) ? $args['class'] : array() );
		$class_attr = implode( ' ', array_map( 'sanitize_html_class', $classes ) );

		$out = '<div class="' . esc_attr( $class_attr ) . '">';
		if ( ! empty( $args['title'] ) ) {
			$out .= '<h2 class="hh-markdown-viewer__title">' . esc_html( (string) $args['title'] ) . '</h2>';
		}
		$out .= $html;
		$out .= '</div>';

		return $out;
	}

	/**
	 * Render a markdown file.
	 *
	 * Security: by default only allows *.md/*.markdown under WP_CONTENT_DIR.
	 * Use filters to expand/override.
	 *
	 * @since 1.1.3
	 * @param string $file File path (relative to WP_CONTENT_DIR by default) or absolute.
	 * @param array  $args Render args. Supports cache_ttl.
	 * @return string HTML (or empty string on failure).
	 */
	public static function render_file( string $file, array $args = array() ): string {
		$defaults = array(
			'cache_ttl' => 0,
			'title'     => '',
			'class'     => array(),
		);
		$args = wp_parse_args( $args, $defaults );

		$resolved = self::resolve_file_path( $file, $args );
		if ( is_wp_error( $resolved ) ) {
			return '';
		}

		$realpath = (string) $resolved;
		$ttl      = absint( $args['cache_ttl'] );
		$mtime    = @filemtime( $realpath );

		if ( $ttl > 0 && is_int( $mtime ) && $mtime > 0 ) {
			$key = 'hh_md_' . md5( $realpath . '|' . $mtime . '|' . wp_json_encode( array( 'title' => $args['title'], 'class' => $args['class'] ) ) );
			$hit = get_transient( $key );
			if ( is_string( $hit ) && '' !== $hit ) {
				return $hit;
			}
		}

		$contents = @file_get_contents( $realpath );
		if ( false === $contents ) {
			return '';
		}

		$html = self::render_markdown( (string) $contents, $args );
		if ( $ttl > 0 && isset( $key ) && '' !== $html ) {
			set_transient( $key, $html, $ttl );
		}

		return $html;
	}

	/**
	 * Resolve and validate a file path.
	 *
	 * Filters:
	 * - hypercart_markdown_viewer_allowed_base_dirs (string[] $dirs, array $args)
	 * - hypercart_markdown_viewer_allow_file (bool $allow, string $realpath, array $args)
	 *
	 * @since 1.1.3
	 * @param string $file Input file.
	 * @param array  $args Args.
	 * @return string|WP_Error
	 */
	private static function resolve_file_path( string $file, array $args ) {
		$file = trim( wp_normalize_path( (string) $file ) );
		if ( '' === $file ) {
			return new WP_Error( 'hh_md_empty', 'Empty file path.' );
		}

		// Default: interpret relative paths as WP_CONTENT_DIR-relative.
		$path = $file;
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . ltrim( $path, '/' ) );
		}

		$real = realpath( $path );
		if ( false === $real ) {
			return new WP_Error( 'hh_md_missing', 'File not found.' );
		}
		$real = wp_normalize_path( (string) $real );

		$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		if ( 'md' !== $ext && 'markdown' !== $ext ) {
			return new WP_Error( 'hh_md_ext', 'Unsupported file extension.' );
		}

		if ( ! is_readable( $real ) ) {
			return new WP_Error( 'hh_md_unreadable', 'File not readable.' );
		}

		$base_dirs = apply_filters( 'hypercart_markdown_viewer_allowed_base_dirs', array( WP_CONTENT_DIR ), $args );
		$base_dirs = is_array( $base_dirs ) ? $base_dirs : array( WP_CONTENT_DIR );

		$allowed = false;
		foreach ( $base_dirs as $dir ) {
			$dir = wp_normalize_path( (string) $dir );
			$dir = rtrim( $dir, '/' ) . '/';
			if ( '' !== $dir && 0 === strpos( $real . '/', $dir ) ) {
				$allowed = true;
				break;
			}
		}

		$allowed = (bool) apply_filters( 'hypercart_markdown_viewer_allow_file', $allowed, $real, $args );
		if ( ! $allowed ) {
			return new WP_Error( 'hh_md_forbidden', 'File path not allowed.' );
		}

		return $real;
	}

	/**
	 * Convert Markdown (subset) into HTML.
	 *
	 * @since 1.1.3
	 * @param string $markdown Markdown.
	 * @return string HTML (unsanitized; sanitize after).
	 */
	private static function parse_markdown_to_html( string $markdown ): string {
		$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
		$lines    = explode( "\n", $markdown );

		$out = '';
		$in_code = false;
		$in_ul   = false;
		$in_ol   = false;
		$in_bq   = false;
		$para    = '';
		$codebuf = '';

		$flush_para = function() use ( &$out, &$para ) {
			if ( '' !== trim( $para ) ) {
				$out .= '<p>' . Hypercart_Markdown_Viewer::parse_inline( $para ) . '</p>';
			}
			$para = '';
		};

		$close_lists = function() use ( &$out, &$in_ul, &$in_ol ) {
			if ( $in_ul ) {
				$out  .= '</ul>';
				$in_ul = false;
			}
			if ( $in_ol ) {
				$out  .= '</ol>';
				$in_ol = false;
			}
		};

		$close_bq = function() use ( &$out, &$in_bq ) {
			if ( $in_bq ) {
				$out  .= '</blockquote>';
				$in_bq = false;
			}
		};

		foreach ( $lines as $line ) {
			$raw = (string) $line;
			$trim = trim( $raw );

			// Fenced code blocks.
			if ( 0 === strpos( $trim, '```' ) ) {
				$flush_para();
				$close_lists();
				$close_bq();

				if ( $in_code ) {
					$out .= '<pre><code>' . esc_html( $codebuf ) . '</code></pre>';
					$codebuf = '';
					$in_code = false;
				} else {
					$in_code = true;
					$codebuf = '';
				}
				continue;
			}

			if ( $in_code ) {
				$codebuf .= $raw . "\n";
				continue;
			}

			// Blank line.
			if ( '' === $trim ) {
				$flush_para();
				$close_lists();
				$close_bq();
				continue;
			}

			// Horizontal rules.
			if ( preg_match( '/^(---|\*\*\*|___)\s*$/', $trim ) ) {
				$flush_para();
				$close_lists();
				$close_bq();
				$out .= '<hr />';
				continue;
			}

			// Headings.
			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $trim, $m ) ) {
				$flush_para();
				$close_lists();
				$close_bq();
				$lvl = strlen( $m[1] );
				$out .= '<h' . (int) $lvl . '>' . self::parse_inline( $m[2] ) . '</h' . (int) $lvl . '>';
				continue;
			}

			// Blockquotes.
			if ( 0 === strpos( $trim, '>' ) ) {
				$flush_para();
				$close_lists();
				if ( ! $in_bq ) {
					$out  .= '<blockquote>';
					$in_bq = true;
				}
				$inner = ltrim( substr( $trim, 1 ) );
				$out  .= '<p>' . self::parse_inline( $inner ) . '</p>';
				continue;
			}
			$close_bq();

			// Unordered lists.
			if ( preg_match( '/^[-\*\+]\s+(.+)$/', $trim, $m ) ) {
				$flush_para();
				if ( $in_ol ) {
					$out  .= '</ol>';
					$in_ol = false;
				}
				if ( ! $in_ul ) {
					$out  .= '<ul>';
					$in_ul = true;
				}
				$out .= '<li>' . self::parse_inline( $m[1] ) . '</li>';
				continue;
			}

			// Ordered lists.
			if ( preg_match( '/^[0-9]+\.\s+(.+)$/', $trim, $m ) ) {
				$flush_para();
				if ( $in_ul ) {
					$out  .= '</ul>';
					$in_ul = false;
				}
				if ( ! $in_ol ) {
					$out  .= '<ol>';
					$in_ol = true;
				}
				$out .= '<li>' . self::parse_inline( $m[1] ) . '</li>';
				continue;
			}

			// Default: paragraph accumulation.
			$close_lists();
			$para .= ( '' === $para ? '' : "\n" ) . $raw;
		}

		// Flush any open buffers.
		if ( $in_code ) {
			$out .= '<pre><code>' . esc_html( $codebuf ) . '</code></pre>';
		}
		if ( '' !== trim( $para ) ) {
			$out .= '<p>' . self::parse_inline( $para ) . '</p>';
		}
		if ( $in_ul ) {
			$out .= '</ul>';
		}
		if ( $in_ol ) {
			$out .= '</ol>';
		}
		if ( $in_bq ) {
			$out .= '</blockquote>';
		}

		return $out;
	}

	/**
	 * Parse inline markdown for a single line/block.
	 *
	 * @since 1.1.3
	 * @param string $text Text.
	 * @return string HTML (unsanitized).
	 */
	private static function parse_inline( string $text ): string {
		$text = esc_html( $text );

		// Protect inline code spans.
		$placeholders = array();
		$i            = 0;
		$text = preg_replace_callback(
			'/`([^`]+)`/',
			function( $m ) use ( &$placeholders, &$i ) {
				$key = '%%HH_CODE_' . $i . '%%';
				$placeholders[ $key ] = '<code>' . $m[1] . '</code>';
				$i++;
				return $key;
			},
			$text
		);

		// Links: [text](url)
		$text = preg_replace_callback(
			'/\[(.+?)\]\((.+?)\)/',
			function( $m ) {
				$label = $m[1];
				$url   = esc_url( html_entity_decode( $m[2], ENT_QUOTES ) );
				if ( '' === $url ) {
					return $label;
				}
				return '<a href="' . esc_url( $url ) . '" rel="nofollow noopener">' . $label . '</a>';
			},
			$text
		);

		// Bold then italic.
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/__(.+?)__/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
		$text = preg_replace( '/_(.+?)_/', '<em>$1</em>', $text );

		// Restore code placeholders.
		if ( ! empty( $placeholders ) ) {
			$text = strtr( $text, $placeholders );
		}

		// Preserve hard line breaks inside paragraphs.
		$text = str_replace( "\n", '<br />', $text );

		return $text;
	}

	/**
	 * Default allowed tags for rendered markdown.
	 *
	 * @since 1.1.3
	 * @return array
	 */
	private static function get_default_allowed_tags(): array {
		return array(
			'a'          => array(
				'href'  => true,
				'rel'   => true,
				'title' => true,
			),
			'br'         => array(),
			'code'       => array(),
			'em'         => array(),
			'hr'         => array(),
			'li'         => array(),
			'ol'         => array(),
			'p'          => array(),
			'pre'        => array(),
			'strong'     => array(),
			'blockquote' => array(),
			'ul'         => array(),
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'h5'         => array(),
			'h6'         => array(),
		);
	}

	/**
	 * Sanitize space-separated CSS classes into an array.
	 *
	 * @since 1.1.3
	 * @param string $classes Classes.
	 * @return array
	 */
	private static function sanitize_css_classes( string $classes ): array {
		$classes = trim( $classes );
		if ( '' === $classes ) {
			return array();
		}
		$parts = preg_split( '/\s+/', $classes );
		$parts = is_array( $parts ) ? $parts : array();
		$out   = array();
		foreach ( $parts as $part ) {
			$part = sanitize_html_class( (string) $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
