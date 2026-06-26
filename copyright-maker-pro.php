<?php
/**
 * Plugin Name: Copyright Maker Pro
 * Plugin URI:  https://example.com/copyright-maker-pro
 * Description: Dynamic copyright shortcode with admin live preview, styling controls, symbol options, separator options, and Legal Link Support.
 * Version:     1.0.0
 * Author:      Copyright Maker Pro
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: copyright-maker-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CMP_VERSION', '1.0.0' );
define( 'CMP_FILE', __FILE__ );
define( 'CMP_OPTION', 'CMP_settings' );

final class CMP_Plugin {
	private static $instance = null;

	/**
	 * Per-request cache of parsed settings to avoid repeated get_option()
	 * + wp_parse_args() work when several methods read settings in one request.
	 *
	 * @var array|null
	 */
	private $settings_cache = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_styles' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CMP_FILE ), array( $this, 'plugin_action_links' ) );
		add_shortcode( 'cmp_copyright', array( $this, 'render_shortcode' ) );
		add_shortcode( 'copyright_maker', array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_cmp_dismiss_tips', array( $this, 'ajax_dismiss_tips' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'copyright-maker-pro',
			false,
			dirname( plugin_basename( CMP_FILE ) ) . '/languages'
		);
	}

	public static function activate() {
		if ( false === get_option( CMP_OPTION ) ) {
			add_option( CMP_OPTION, self::default_settings() );
		}
	}

	public static function uninstall() {
		delete_option( CMP_OPTION );
		delete_metadata( 'user', 0, 'cmp_tips_dismissed', '', true );
	}

	/**
	 * AJAX: remember that the current user dismissed the admin tips banner.
	 * Persisted per-user so it stays hidden after a refresh.
	 */
	public function ajax_dismiss_tips() {
		check_ajax_referer( 'cmp_tips', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '', 403 );
		}
		update_user_meta( get_current_user_id(), 'cmp_tips_dismissed', '1' );
		wp_send_json_success();
	}

	public static function default_settings() {
		return array(
			'company_name'              => get_bloginfo( 'name' ),
			'start_year'                => '',
			'link_enabled'              => '0',
			'rights_text'               => '',
			'symbol'                    => 'copyright',
			'custom_symbol'             => '',
			'separator_option'          => 'pipe',
			'separator'                 => '|',
			'font_family'               => '',
			'font_size'                 => '16',
			'font_size_tablet'          => '',
			'font_size_mobile'          => '',
			'font_weight'               => 'normal',
			'text_color'                => '#1d2327',
			'link_color'                => '#2271b1',
			'hover_color'               => '#135e96',
			'letter_spacing'            => '0',
			'text_transform'            => 'none',
			'legal_links_enabled'       => '0',
			'legal_link_divider'        => 'use_output',
			'legal_link_custom_divider' => '',
			'layout_direction'          => 'row',
			'layout_alignment'          => 'left',
			'layout_justify'            => 'default',
			'layout_gap'                => '',
			'layout_direction_tablet'   => 'row',
			'layout_alignment_tablet'   => 'left',
			'layout_justify_tablet'     => 'default',
			'layout_gap_tablet'         => '',
			'layout_direction_mobile'   => 'row',
			'layout_alignment_mobile'   => 'left',
			'layout_justify_mobile'     => 'default',
			'layout_gap_mobile'         => '',
			'legal_links'               => array(),
		);
	}

	public function get_settings() {
		if ( null !== $this->settings_cache ) {
			return $this->settings_cache;
		}
		$saved    = get_option( CMP_OPTION, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
		if ( isset( $settings['rights_text'] ) && 'All Rights Reserved' === $settings['rights_text'] ) {
			$settings['rights_text'] = '';
		}
		$this->settings_cache = $settings;
		return $settings;
	}

	public function add_admin_menu() {
		add_menu_page(
			__( 'Copyright Maker Pro', 'copyright-maker-pro' ),
			__( 'Copyright Maker', 'copyright-maker-pro' ),
			'manage_options',
			'copyright-maker-pro',
			array( $this, 'settings_page' ),
			'dashicons-editor-code',
			80
		);
	}

	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=copyright-maker-pro' ) ) . '">' . esc_html__( 'Settings', 'copyright-maker-pro' ) . '</a>' );
		return $links;
	}

	public function register_settings() {
		register_setting(
			'CMP_settings_group',
			CMP_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::default_settings(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$input    = is_array( $input ) ? $input : array();

		$font_weight_options = array( 'normal', 'bold', '100', '200', '300', '400', '500', '600', '700', '800', '900' );
		$symbol_options      = array( 'copyright', 'registered', 'trademark', 'custom' );
		$separator_options   = array( 'pipe', 'dash', 'emdash', 'bullet', 'slash', 'space', 'custom' );
		$transform_options   = array( 'none', 'uppercase', 'lowercase', 'capitalize' );
		$legal_dividers      = array( 'use_output', 'pipe', 'dot', 'bullet', 'dash', 'slash', 'custom' );
		$layout_directions   = array( 'row', 'column', 'row-reverse', 'column-reverse' );
		$layout_alignments   = array( 'left', 'center', 'right' );
		$layout_justifies    = array( 'default', 'space-between', 'space-around', 'space-evenly' );

		$settings = array();
		$settings['company_name']              = sanitize_text_field( $input['company_name'] ?? $defaults['company_name'] );
		$settings['start_year']                = sanitize_text_field( $input['start_year'] ?? $defaults['start_year'] );
		$settings['link_enabled']              = ! empty( $input['link_enabled'] ) ? '1' : '0';
		$settings['rights_text']               = sanitize_text_field( $input['rights_text'] ?? $defaults['rights_text'] );
		$settings['symbol']                    = in_array( $input['symbol'] ?? '', $symbol_options, true ) ? $input['symbol'] : $defaults['symbol'];
		$settings['custom_symbol']             = sanitize_text_field( $input['custom_symbol'] ?? $defaults['custom_symbol'] );
		$settings['separator_option']          = in_array( $input['separator_option'] ?? '', $separator_options, true ) ? $input['separator_option'] : $defaults['separator_option'];
		$settings['separator']                 = sanitize_text_field( $input['separator'] ?? $defaults['separator'] );
		$settings['font_family']               = sanitize_text_field( $input['font_family'] ?? $defaults['font_family'] );
		$settings['font_size']                 = isset( $input['font_size'] ) && absint( $input['font_size'] ) > 0 ? (string) absint( $input['font_size'] ) : $defaults['font_size'];
		$settings['font_size_tablet']          = ( isset( $input['font_size_tablet'] ) && '' !== trim( (string) $input['font_size_tablet'] ) && absint( $input['font_size_tablet'] ) > 0 ) ? (string) absint( $input['font_size_tablet'] ) : '';
		$settings['font_size_mobile']          = ( isset( $input['font_size_mobile'] ) && '' !== trim( (string) $input['font_size_mobile'] ) && absint( $input['font_size_mobile'] ) > 0 ) ? (string) absint( $input['font_size_mobile'] ) : '';
		$settings['font_weight']               = in_array( (string) ( $input['font_weight'] ?? '' ), $font_weight_options, true ) ? (string) $input['font_weight'] : $defaults['font_weight'];
		$settings['text_color']                = sanitize_hex_color( $input['text_color'] ?? $defaults['text_color'] ) ?: $defaults['text_color'];
		$settings['link_color']                = sanitize_hex_color( $input['link_color'] ?? $defaults['link_color'] ) ?: $defaults['link_color'];
		$settings['hover_color']               = sanitize_hex_color( $input['hover_color'] ?? $defaults['hover_color'] ) ?: $defaults['hover_color'];
		$settings['letter_spacing']            = is_numeric( $input['letter_spacing'] ?? null ) ? (string) floatval( $input['letter_spacing'] ) : $defaults['letter_spacing'];
		$settings['text_transform']            = in_array( $input['text_transform'] ?? '', $transform_options, true ) ? $input['text_transform'] : $defaults['text_transform'];
		$settings['legal_links_enabled']       = ! empty( $input['legal_links_enabled'] ) ? '1' : '0';
		$settings['legal_link_divider']        = in_array( $input['legal_link_divider'] ?? '', $legal_dividers, true ) ? $input['legal_link_divider'] : $defaults['legal_link_divider'];
		$settings['legal_link_custom_divider'] = sanitize_text_field( $input['legal_link_custom_divider'] ?? $defaults['legal_link_custom_divider'] );
		$settings['layout_direction']          = in_array( $input['layout_direction'] ?? '', $layout_directions, true ) ? $input['layout_direction'] : $defaults['layout_direction'];
		$settings['layout_alignment']          = in_array( $input['layout_alignment'] ?? '', $layout_alignments, true ) ? $input['layout_alignment'] : $defaults['layout_alignment'];
		$settings['layout_justify']            = in_array( $input['layout_justify'] ?? '', $layout_justifies, true ) ? $input['layout_justify'] : $defaults['layout_justify'];
		$settings['layout_gap']                = ( '' === trim( (string) ( $input['layout_gap'] ?? '' ) ) ) ? '' : (string) absint( $input['layout_gap'] );
		foreach ( array( '_tablet', '_mobile' ) as $bp ) {
			$settings[ 'layout_direction' . $bp ] = in_array( $input[ 'layout_direction' . $bp ] ?? '', $layout_directions, true ) ? $input[ 'layout_direction' . $bp ] : $defaults[ 'layout_direction' . $bp ];
			$settings[ 'layout_alignment' . $bp ] = in_array( $input[ 'layout_alignment' . $bp ] ?? '', $layout_alignments, true ) ? $input[ 'layout_alignment' . $bp ] : $defaults[ 'layout_alignment' . $bp ];
			$settings[ 'layout_justify' . $bp ]   = in_array( $input[ 'layout_justify' . $bp ] ?? '', $layout_justifies, true ) ? $input[ 'layout_justify' . $bp ] : $defaults[ 'layout_justify' . $bp ];
			$settings[ 'layout_gap' . $bp ]       = ( '' === trim( (string) ( $input[ 'layout_gap' . $bp ] ?? '' ) ) ) ? '' : (string) absint( $input[ 'layout_gap' . $bp ] );
		}
		$settings['legal_links']               = array();

		if ( ! empty( $input['legal_links'] ) && is_array( $input['legal_links'] ) ) {
			foreach ( $input['legal_links'] as $link ) {
				if ( ! is_array( $link ) ) {
					continue;
				}

				$name = sanitize_text_field( $link['name'] ?? '' );
				$url  = esc_url_raw( $link['url'] ?? '' );

				if ( '' === $name && '' === $url ) {
					continue;
				}

				$settings['legal_links'][] = array(
					'name'    => $name,
					'url'     => $url,
					'new_tab' => ! empty( $link['new_tab'] ) ? '1' : '0',
				);
			}
		}

		return $settings;
	}

	/**
	 * Sanitize a font-family value for safe use inside an inline CSS declaration.
	 * esc_attr() is meant for HTML attributes and mangles quoted font names while
	 * still allowing characters that could break out of a CSS rule, so we strip to
	 * a conservative whitelist instead.
	 *
	 * @param string $value Raw font-family value.
	 * @return string CSS-safe font-family, or '' if nothing usable remains.
	 */
	private function sanitize_css_font_family( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( '/[^A-Za-z0-9 ,\.\'"\-]/', '', $value );
		return trim( (string) $value );
	}

	private function get_google_font_name( $font_family ) {
		$font_family = trim( wp_strip_all_tags( (string) $font_family ) );
		if ( '' === $font_family ) {
			return '';
		}

		$parts = explode( ',', $font_family );
		$font  = trim( $parts[0], " \t\n\r\0\x0B'\"" );

		$generic_families = array( 'arial', 'georgia', 'times new roman', 'times', 'courier new', 'courier', 'verdana', 'tahoma', 'trebuchet ms', 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'inherit', 'initial', 'unset' );
		if ( '' === $font || in_array( strtolower( $font ), $generic_families, true ) ) {
			return '';
		}

		return preg_match( '/^[A-Za-z0-9 \-]+$/', $font ) ? $font : '';
	}

	private function get_google_font_url( $font_family ) {
		$font = $this->get_google_font_name( $font_family );
		if ( '' === $font ) {
			return '';
		}

		$family = str_replace( '%20', '+', rawurlencode( $font ) );
		return esc_url_raw( 'https://fonts.googleapis.com/css2?family=' . $family . ':wght@400;500;600;700;800&display=swap' );
	}

	public function admin_assets( $hook ) {
		if ( 'toplevel_page_copyright-maker-pro' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		$settings        = $this->get_settings();
		$google_font_url = $this->get_google_font_url( $settings['font_family'] );
		if ( '' !== $google_font_url ) {
			wp_enqueue_style( 'cmp-google-font', $google_font_url, array(), CMP_VERSION );
		}
	}

	private function get_symbol_output( $settings ) {
		switch ( $settings['symbol'] ) {
			case 'registered':
				return '&reg;';
			case 'trademark':
				return '&trade;';
			case 'custom':
				return esc_html( trim( $settings['custom_symbol'] ) );
			case 'copyright':
			default:
				return '&copy;';
		}
	}

	private function get_separator_output( $settings ) {
		switch ( $settings['separator_option'] ) {
			case 'dash':
				return '-';
			case 'emdash':
				return html_entity_decode( '&mdash;', ENT_QUOTES, 'UTF-8' );
			case 'bullet':
				return html_entity_decode( '&bull;', ENT_QUOTES, 'UTF-8' );
			case 'slash':
				return '/';
			case 'space':
				return html_entity_decode( '&nbsp;', ENT_QUOTES, 'UTF-8' );
			case 'custom':
				return trim( $settings['separator'] );
			case 'pipe':
			default:
				return '|';
		}
	}

	private function get_legal_link_divider_output( $settings, $output_separator = '' ) {
		switch ( $settings['legal_link_divider'] ) {
			case 'pipe':
				return '|';
			case 'dot':
				return html_entity_decode( '&middot;', ENT_QUOTES, 'UTF-8' );
			case 'bullet':
				return html_entity_decode( '&bull;', ENT_QUOTES, 'UTF-8' );
			case 'dash':
				return '-';
			case 'slash':
				return '/';
			case 'custom':
				return trim( $settings['legal_link_custom_divider'] );
			case 'use_output':
			default:
				return $output_separator;
		}
	}

	private function get_layout_props( $settings, $suffix = '' ) {
		$dir      = isset( $settings[ 'layout_direction' . $suffix ] ) ? $settings[ 'layout_direction' . $suffix ] : 'row';
		$align    = isset( $settings[ 'layout_alignment' . $suffix ] ) ? $settings[ 'layout_alignment' . $suffix ] : 'left';
		$is_row   = ( 'row' === $dir || 'row-reverse' === $dir );
		$reversed = ( 'row-reverse' === $dir || 'column-reverse' === $dir );
		$map      = array(
			'left'   => 'flex-start',
			'center' => 'center',
			'right'  => 'flex-end',
		);

		if ( $is_row ) {
			$justify     = isset( $map[ $align ] ) ? $map[ $align ] : 'flex-start';
			$align_items = 'center';
		} else {
			$justify     = 'flex-start';
			$align_items = isset( $map[ $align ] ) ? $map[ $align ] : 'flex-start';
		}

		$just_key = 'layout_justify' . $suffix;
		if ( isset( $settings[ $just_key ] ) && 'default' !== $settings[ $just_key ] ) {
			$justify = $settings[ $just_key ];
		}

		$gap = isset( $settings[ 'layout_gap' . $suffix ] ) ? $settings[ 'layout_gap' . $suffix ] : '';

		return array(
			'direction'   => $is_row ? 'row' : 'column',
			'justify'     => $justify,
			'align_items' => $align_items,
			'text_align'  => $align,
			'gap'         => ( '' === $gap ) ? '10' : $gap,
			'is_row'      => $is_row,
			'reverse'     => $reversed,
		);
	}

	private function layout_decls( $p ) {
		$d  = 'flex-direction:' . $p['direction'] . ';';
		$d .= 'justify-content:' . $p['justify'] . ';';
		$d .= 'align-items:' . $p['align_items'] . ';';
		$d .= 'text-align:' . $p['text_align'] . ';';
		$d .= 'gap:' . absint( $p['gap'] ) . 'px;';
		return $d;
	}

	public function frontend_styles() {
		$settings = $this->get_settings();
		$layout   = $this->get_layout_props( $settings );
		$css  = '.cmp-copyright{line-height:1.6;';
		$css .= 'width:100%;box-sizing:border-box;';
		$css .= 'display:flex;flex-wrap:wrap;';
		$css .= 'flex-direction:' . $layout['direction'] . ';';
		$css .= 'justify-content:' . $layout['justify'] . ';';
		$css .= 'align-items:' . $layout['align_items'] . ';';
		$css .= 'text-align:' . $layout['text_align'] . ';';
		if ( '' !== $layout['gap'] ) {
			$css .= 'gap:' . absint( $layout['gap'] ) . 'px;';
		}
		$css .= 'font-size:' . absint( $settings['font_size'] ) . 'px;';
		$css .= 'font-weight:' . esc_attr( $settings['font_weight'] ) . ';';
		$css .= 'color:' . esc_attr( $settings['text_color'] ) . ';';
		$css .= 'letter-spacing:' . (float) $settings['letter_spacing'] . 'px;';
		if ( 'none' !== $settings['text_transform'] ) {
			$css .= 'text-transform:' . esc_attr( $settings['text_transform'] ) . ';';
		}
		$font_family = $this->sanitize_css_font_family( $settings['font_family'] );
		if ( '' !== $font_family ) {
			$css .= 'font-family:' . $font_family . ';';
		}
		$css .= '}';
		$css .= '.cmp-copyright-group{display:inline-flex;flex-wrap:wrap;align-items:center;order:0}';
		$css .= '.cmp-copyright .cmp-legal-links{order:' . ( $layout['reverse'] ? '-1' : '0' ) . '}';
		$tablet      = $this->get_layout_props( $settings, '_tablet' );
		$tablet_decl = $this->layout_decls( $tablet );
		if ( '' !== $settings['font_size_tablet'] ) {
			$tablet_decl .= 'font-size:' . absint( $settings['font_size_tablet'] ) . 'px;';
		}
		$css .= '@media (max-width:1024px){.cmp-copyright{' . $tablet_decl . '}.cmp-copyright .cmp-legal-links{order:' . ( $tablet['reverse'] ? '-1' : '0' ) . '}}';
		$mobile      = $this->get_layout_props( $settings, '_mobile' );
		$mobile_decl = $this->layout_decls( $mobile );
		if ( '' !== $settings['font_size_mobile'] ) {
			$mobile_decl .= 'font-size:' . absint( $settings['font_size_mobile'] ) . 'px;';
		}
		$css .= '@media (max-width:767px){.cmp-copyright{' . $mobile_decl . '}.cmp-copyright .cmp-legal-links{order:' . ( $mobile['reverse'] ? '-1' : '0' ) . '}}';
		$css .= '.cmp-separator,.cmp-legal-divider{display:inline-block;margin:0 .45em;white-space:nowrap}';
		$css .= '.cmp-separator-space,.cmp-legal-divider-space{margin:0}';
		$css .= '.cmp-company-link,.cmp-legal-link{text-decoration:none;color:' . esc_attr( $settings['link_color'] ) . '}';
		$css .= '.cmp-company-link:hover,.cmp-legal-link:hover{text-decoration:none;color:' . esc_attr( $settings['hover_color'] ) . '}';

		$google_font_url = $this->get_google_font_url( $settings['font_family'] );
		if ( '' !== $google_font_url ) {
			wp_enqueue_style( 'cmp-google-font', $google_font_url, array(), CMP_VERSION );
		}

		wp_register_style( 'cmp-frontend', false, array(), CMP_VERSION );
		wp_enqueue_style( 'cmp-frontend' );
		wp_add_inline_style( 'cmp-frontend', $css );
	}

	public function render_shortcode( $atts ) {
		$settings = $this->get_settings();

		// Stored settings are already sanitized on save (sanitize_callback), so the
		// common no-attribute render path skips re-sanitizing. Only sanitize when the
		// shortcode actually overrides values via attributes.
		if ( ! empty( $atts ) && is_array( $atts ) ) {
			$settings = $this->sanitize_settings( shortcode_atts( $settings, $atts, 'cmp_copyright' ) );
		}

		$current_year = current_time( 'Y' );
		$start_year   = trim( $settings['start_year'] );
		$year         = ( '' !== $start_year && $start_year !== $current_year ) ? $start_year . '-' . $current_year : $current_year;
		$company_name = trim( $settings['company_name'] );
		$rights_text  = trim( $settings['rights_text'] );
		$separator    = $this->get_separator_output( $settings );
		$symbol       = $this->get_symbol_output( $settings );
		$company_html = esc_html( $company_name );

		if ( '1' === $settings['link_enabled'] && '' !== $company_name ) {
			$company_html = '<a class="cmp-company-link" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $company_name ) . '</a>';
		}

		$main_parts = array_filter(
			array( $symbol, esc_html( $year ), $company_html ),
			function ( $part ) {
				return '' !== trim( wp_strip_all_tags( $part ) );
			}
		);

		$copyright_inner = '<span class="cmp-main">' . implode( ' ', $main_parts ) . '</span>';
		if ( '' !== $rights_text ) {
			$copyright_inner .= ( '' !== $separator ) ? '<span class="cmp-separator' . ( 'space' === $settings['separator_option'] ? ' cmp-separator-space' : '' ) . '" aria-hidden="true">' . ( 'space' === $settings['separator_option'] ? '&nbsp;' : esc_html( $separator ) ) . '</span>' : ' ';
			$copyright_inner .= '<span class="cmp-rights">' . esc_html( $rights_text ) . '</span>';
		}
		$output = '<span class="cmp-copyright-group">' . $copyright_inner . '</span>';

		if ( '1' === $settings['legal_links_enabled'] && ! empty( $settings['legal_links'] ) && is_array( $settings['legal_links'] ) ) {
			$legal_links_html = array();
			foreach ( $settings['legal_links'] as $legal_link ) {
				$name = trim( $legal_link['name'] ?? '' );
				$url  = trim( $legal_link['url'] ?? '' );
				if ( '' === $name || '' === $url ) {
					continue;
				}
				$target = ( ! empty( $legal_link['new_tab'] ) && '1' === $legal_link['new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';
				$legal_links_html[] = '<a class="cmp-legal-link" href="' . esc_url( $url ) . '"' . $target . '>' . esc_html( $name ) . '</a>';
			}
			if ( ! empty( $legal_links_html ) ) {
				$legal_divider = $this->get_legal_link_divider_output( $settings, $separator );
				$is_space_divider = ( 'space' === $settings['legal_link_divider'] || ( 'use_output' === $settings['legal_link_divider'] && 'space' === $settings['separator_option'] ) );
				$between_html      = ( '' !== $legal_divider ) ? '<span class="cmp-legal-divider' . ( $is_space_divider ? ' cmp-legal-divider-space' : '' ) . '" aria-hidden="true">' . ( $is_space_divider ? '&nbsp;' : esc_html( $legal_divider ) ) . '</span>' : ' ';
				$output       .= '<span class="cmp-legal-links">' . implode( $between_html, $legal_links_html ) . '</span>';
			}
		}

		return '<div class="cmp-copyright">' . $output . '</div>';
	}

	private function font_weight_options_markup( $current ) {
		$options = array(
			'normal' => __( 'Normal', 'copyright-maker-pro' ),
			'bold'   => __( 'Bold', 'copyright-maker-pro' ),
			'100'    => '100 - Thin',
			'200'    => '200 - Extra Light',
			'300'    => '300 - Light',
			'400'    => '400 - Regular',
			'500'    => '500 - Medium',
			'600'    => '600 - Semi Bold',
			'700'    => '700 - Bold',
			'800'    => '800 - Extra Bold',
			'900'    => '900 - Black',
		);
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
	}

	private function legal_link_divider_options_markup( $current ) {
		$options = array(
			'use_output' => __( 'Use Output Separator', 'copyright-maker-pro' ),
			'pipe'       => 'Pipe (|)',
			'dot'        => 'Dot (&middot;)',
			'bullet'     => 'Bullet (&bull;)',
			'dash'       => 'Dash (-)',
			'slash'      => 'Slash (/)',
			'custom'     => __( 'Custom', 'copyright-maker-pro' ),
		);
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . wp_kses_post( $label ) . '</option>';
		}
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->get_settings();
		?>
		<div class="wrap cmp-admin">
			<div class="cmp-hero">
				<div class="cmp-hero-left">
					<div class="cmp-hero-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9.2"/><path d="M14.9 9.3a3.4 3.4 0 1 0 0 5.4"/></svg></div>
					<div>
						<h1 class="cmp-hero-title"><?php esc_html_e( 'Copyright Maker Pro', 'copyright-maker-pro' ); ?> <span class="cmp-hero-pill">v<?php echo esc_html( CMP_VERSION ); ?></span></h1>
						<p class="cmp-hero-sub"><?php esc_html_e( 'Build a polished, dynamic copyright line with live preview, styling controls, and legal links - then drop it anywhere with one shortcode.', 'copyright-maker-pro' ); ?></p>
					</div>
				</div>
				<div class="cmp-hero-actions">
					<button type="button" class="cmp-hero-chip" id="cmp-hero-copy" data-cmp-copy="[cmp_copyright]"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg> <span class="cmp-hero-chip-label"><?php esc_html_e( 'Copy', 'copyright-maker-pro' ); ?></span> <code>[cmp_copyright]</code></button>
				</div>
			</div>
			<div id="cmp-toast" class="cmp-toast" role="status" aria-live="polite"></div>
			<style>
				@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');.cmp-admin-grid{display:grid;grid-template-columns:minmax(0,720px) minmax(340px,1fr);gap:22px;align-items:start;max-width:1240px}.cmp-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.cmp-form-card{padding:0}.cmp-section{padding:22px 24px;border-bottom:1px solid #eef0f2}.cmp-section h2{margin:0 0 16px;font-size:16px}.cmp-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 16px}.cmp-section-head h2{margin:0}.cmp-bp-toggle{display:inline-flex;gap:4px}.cmp-bp-toggle button{cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:34px;height:30px;border:1px solid #c3c4c7;background:#fff;color:#50575e;border-radius:6px;padding:0}.cmp-bp-toggle button svg{width:17px;height:17px;fill:currentColor}.cmp-bp-toggle button:hover{border-color:#2271b1;color:#2271b1}.cmp-bp-toggle button.is-active{background:#2271b1;border-color:#2271b1;color:#fff}.cmp-field{display:grid;grid-template-columns:180px minmax(0,1fr);gap:14px;align-items:center;margin:14px 0}.cmp-field-label{font-weight:600}.cmp-field input.regular-text,.cmp-field select{width:100%;max-width:520px}.cmp-short-input{width:96px!important;max-width:96px!important}.cmp-help{margin:.35em 0 0;color:#646970}.cmp-inline-option-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(160px,1fr);align-items:center;gap:8px;max-width:520px}.cmp-inline-option-row select,.cmp-inline-option-row .cmp-short-input{width:100%!important;max-width:100%!important;height:38px!important;box-sizing:border-box}.cmp-custom-symbol-wrap,.cmp-custom-separator-wrap,.cmp-legal-custom-divider-wrap{display:block;width:100%;min-width:0}.cmp-sublabel{display:block;margin-top:5px;color:#8c8f94;font-size:12px;line-height:1.35}.cmp-sticky-save{position:sticky;top:32px;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:16px;background:#fff;border-bottom:1px solid #dcdcde;border-radius:10px 10px 0 0;padding:12px 24px;box-shadow:0 2px 8px rgba(0,0,0,.06)}.cmp-side-column{position:sticky;top:32px;display:flex;flex-direction:column;gap:18px}.cmp-side-card,.cmp-preview-container{padding:24px}.cmp-preview-box{border:1px dashed #b9b9b9;border-radius:8px;background:#fafafa;padding:28px;text-align:center;font-size:16px;line-height:1.6;min-height:46px}.cmp-preview-hint{margin-top:10px;font-size:12px;line-height:1.45;color:#646970}.cmp-preview-hint.is-warning{color:#8a6d1f;background:#fcf9e8;border:1px solid #f0e6b8;border-radius:6px;padding:9px 11px}.cmp-legal-link-pending{opacity:.55}.cmp-separator,.cmp-legal-divider{display:inline-block;margin:0 .45em;white-space:nowrap}.cmp-separator-space,.cmp-legal-divider-space{margin:0}.cmp-shortcode{margin-top:18px}.cmp-shortcode-row{display:flex;gap:8px;align-items:center}.cmp-shortcode-row input{flex:1;min-width:0}.cmp-copy-status{color:#008a20;font-weight:600;margin-left:4px}.cmp-bottom-submit{padding:22px 24px;margin:0}.cmp-styling-controls{margin:0;padding:0}.cmp-style-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.cmp-style-field label{display:block;font-weight:600;margin-bottom:6px}.cmp-style-field input,.cmp-style-field select{width:100%;max-width:100%}.cmp-color-style-field{grid-column:1/-1;position:relative;overflow:visible}.cmp-color-style-field .wp-picker-container.wp-picker-active .wp-picker-holder{display:block!important;position:static!important;margin:10px 0 0!important;padding:0!important;border:0!important;box-shadow:none!important;background:transparent!important;visibility:visible!important;opacity:1!important}.cmp-color-style-field .wp-picker-container.wp-picker-active .wp-picker-input-wrap{display:inline-flex!important;align-items:center!important;gap:6px!important;vertical-align:top!important}.cmp-color-style-field .wp-picker-container.wp-picker-active .wp-picker-clear{display:inline-block!important;height:34px!important;line-height:32px!important;margin:0!important;vertical-align:top!important}.cmp-color-style-field input.wp-color-picker{width:96px!important;max-width:96px!important;height:34px!important;line-height:32px!important}.cmp-custom-option-hidden{visibility:hidden!important;pointer-events:none!important}.cmp-admin input[type=text],.cmp-admin input[type=url],.cmp-admin input[type=number],.cmp-admin select{height:38px!important;min-height:38px!important;box-sizing:border-box!important;line-height:36px!important}.cmp-admin input[type=text],.cmp-admin input[type=url],.cmp-admin input[type=number]{padding:6px 10px!important}.cmp-admin select{padding-top:0!important;padding-bottom:0!important}.cmp-legal-links-list{display:flex;flex-direction:column;gap:10px;max-width:680px}.cmp-legal-link-row{display:grid;grid-template-columns:minmax(130px,1fr) minmax(180px,1.5fr) auto auto;gap:8px;align-items:center;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px}.cmp-legal-link-row input[type=text],.cmp-legal-link-row input[type=url]{width:100%}.cmp-legal-new-tab{white-space:nowrap}.cmp-legal-empty{color:#646970;font-style:italic;margin:0 0 10px}.cmp-legal-actions{margin-top:10px}.cmp-legal-section .cmp-field{align-items:start}.cmp-toggle-row{display:flex;align-items:center;gap:12px}.cmp-switch{position:relative;display:inline-flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;color:#1d2327}.cmp-switch input{position:absolute;opacity:0;width:1px;height:1px}.cmp-switch-slider{width:46px;height:24px;border-radius:999px;background:#646970;position:relative;transition:.2s ease}.cmp-switch-slider:before{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s ease;box-shadow:0 1px 3px rgba(0,0,0,.25)}.cmp-switch input:checked+.cmp-switch-slider{background:#2271b1}.cmp-switch input:checked+.cmp-switch-slider:before{transform:translateX(22px)}.cmp-switch input:focus+.cmp-switch-slider{outline:2px solid #2271b1;outline-offset:2px}.cmp-legal-controls.is-disabled{opacity:.48;filter:grayscale(1);pointer-events:none}.cmp-legal-divider-preview{display:block;margin-top:8px;padding:9px 11px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:6px;color:#1d2327;font-size:13px;line-height:1.4}.cmp-legal-links-list{max-width:none!important}.cmp-legal-link-row{grid-template-columns:minmax(0,1fr) minmax(0,1.6fr) auto auto!important;gap:12px!important;background:#fff!important;border:1px solid #dcdcde!important;border-radius:10px!important;box-shadow:0 1px 3px rgba(0,0,0,.08)!important;padding:14px!important}.cmp-legal-link-field{min-width:0}.cmp-legal-link-field label{display:block;margin:0 0 5px;font-weight:600;color:#1d2327}.cmp-legal-link-row input[type=text],.cmp-legal-link-row input[type=url]{width:100%!important;max-width:100%!important;min-width:0}.cmp-legal-new-tab{display:inline-flex!important;align-items:center;gap:6px;margin-top:24px;color:#1d2327;white-space:nowrap}.cmp-legal-new-tab input{margin:0}.cmp-tooltip{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#e7f0f7;color:#135e96;font-size:12px;font-weight:700;cursor:help;position:relative}.cmp-tooltip:hover:after,.cmp-tooltip:focus:after{content:attr(data-tooltip);position:absolute;bottom:125%;left:50%;transform:translateX(-50%);width:210px;background:#1d2327;color:#fff;padding:8px 10px;border-radius:6px;font-weight:400;font-size:12px;line-height:1.35;z-index:99999;box-shadow:0 4px 12px rgba(0,0,0,.2)}.cmp-delete-legal-link{margin-top:22px;border:0!important;background:transparent!important;box-shadow:none!important;color:#b32d2e!important;padding:4px!important;min-width:34px;height:34px;border-radius:6px;display:inline-flex!important;align-items:center;justify-content:center}.cmp-delete-legal-link:hover,.cmp-delete-legal-link:focus{background:#fcf0f1!important;color:#8a1f20!important}.cmp-delete-legal-link svg{width:18px;height:18px;fill:currentColor}.cmp-delete-confirm{display:block;grid-column:1/-1;color:#b32d2e;font-size:12px;font-weight:600;margin-top:-4px}.cmp-add-legal-link{display:inline-flex!important;align-items:center;gap:6px;background:#f0f6fc!important;border-color:#2271b1!important;color:#0a4b78!important;font-weight:600!important}.cmp-add-legal-link:hover,.cmp-add-legal-link:focus{background:#d8ecfb!important;color:#043959!important}.cmp-add-icon{font-size:16px;line-height:1}.cmp-toast{position:fixed;right:24px;bottom:24px;z-index:100000;padding:12px 16px;border-radius:8px;color:#fff;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.22);opacity:0;transform:translateY(10px);pointer-events:none;transition:.2s ease}.cmp-toast.is-visible{opacity:1;transform:translateY(0)}.cmp-toast.is-success{background:#008a20}.cmp-toast.is-error{background:#b32d2e}@media(max-width:1100px){.cmp-legal-link-row{grid-template-columns:1fr 1fr!important}.cmp-legal-new-tab,.cmp-delete-legal-link{margin-top:0}}@media(max-width:782px){.cmp-legal-link-row{grid-template-columns:1fr!important}}/* Always show color input and clear button. */.cmp-color-style-field .wp-picker-input-wrap{display:inline-flex!important;align-items:center!important;gap:6px!important;margin-left:8px!important;vertical-align:top!important;width:auto!important;max-width:none!important}.cmp-color-style-field .wp-picker-input-wrap label{display:inline-block!important;margin:0!important}.cmp-color-style-field .wp-picker-input-wrap input.wp-color-picker{display:inline-block!important;width:96px!important;max-width:96px!important;height:34px!important;min-height:34px!important;line-height:32px!important;padding:0 8px!important;box-sizing:border-box!important}.cmp-color-style-field .wp-picker-clear{display:inline-block!important;width:auto!important;height:34px!important;min-height:34px!important;line-height:32px!important;margin:0!important;padding:0 10px!important;vertical-align:top!important;box-sizing:border-box!important}.cmp-color-style-field .wp-color-result.button{vertical-align:top!important}.cmp-color-style-field .wp-picker-container:not(.wp-picker-active) .wp-picker-holder{display:none!important}@media(max-width:960px){.cmp-admin-grid{grid-template-columns:1fr}.cmp-side-column,.cmp-sticky-save{position:static}.cmp-field{grid-template-columns:1fr}.cmp-style-grid{grid-template-columns:1fr}.cmp-legal-link-row{grid-template-columns:1fr}.cmp-inline-option-row{grid-template-columns:1fr}}
			.cmp-admin{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif!important;color:#1d2327}.cmp-admin>h1{font-size:24px;font-weight:800;letter-spacing:-.02em;padding:0}.cmp-page-title{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.cmp-version-badge{display:inline-flex;align-items:center;border-radius:999px;background:#eaf6ed;color:#1b5e20;border:1px solid #bfe5c8;padding:3px 9px;font-size:12px;font-weight:700;letter-spacing:0}.cmp-admin>p{color:#6b7280;font-size:13px;margin:2px 0 18px}.cmp-admin .cmp-admin-grid{grid-template-columns:minmax(0,1fr) 480px!important;gap:22px!important;max-width:1320px}.cmp-admin .cmp-form-card{background:transparent!important;border:0!important;box-shadow:none!important;padding:0!important}.cmp-admin .cmp-section{background:#fff!important;border:1px solid #e5e7eb!important;border-radius:14px!important;box-shadow:0 1px 3px rgba(16,24,40,.06)!important;margin:0 0 18px!important;padding:18px 20px!important}.cmp-rd-tabs{display:flex;gap:6px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:6px;margin:0 0 18px;width:fit-content;box-shadow:0 1px 2px rgba(16,24,40,.05);flex-wrap:wrap}.cmp-rd-tabs button{border:0;background:transparent;color:#6b7280;font:600 13px Inter,sans-serif;padding:9px 16px;border-radius:8px;cursor:pointer;display:flex;align-items:center;gap:7px}.cmp-rd-tabs button:hover{color:#1d2327}.cmp-rd-tabs button.is-active{background:#2e7d32;color:#fff;box-shadow:0 1px 3px rgba(46,125,50,.4)}.cmp-rd-tabs button svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.cmp-rd-head{display:flex;align-items:center;gap:12px;margin:0 0 16px}.cmp-rd-head h2{margin:0!important;font-size:15px!important;font-weight:700}.cmp-rd-head-grp{display:flex;align-items:center;gap:12px}.cmp-admin .cmp-rd-section-head{display:flex!important;align-items:center;justify-content:space-between;margin:0 0 16px!important}.cmp-rd-ic{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;flex:0 0 auto}.cmp-rd-ic svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.cmp-rd-ic.blue{background:linear-gradient(135deg,#3b82f6,#2563eb)}.cmp-rd-ic.purple{background:linear-gradient(135deg,#a855f7,#7c3aed)}.cmp-rd-ic.green{background:linear-gradient(135deg,#22c55e,#16a34a)}.cmp-rd-ic.orange{background:linear-gradient(135deg,#f59e0b,#ea580c)}.cmp-rd-ic.teal{background:linear-gradient(135deg,#14b8a6,#0d9488)}.cmp-admin .cmp-card.cmp-side-card,.cmp-admin .cmp-card.cmp-preview-container{border:1px solid #e5e7eb!important;border-radius:14px!important;box-shadow:0 1px 3px rgba(16,24,40,.06)!important}.cmp-admin .cmp-preview-box{border:2px dashed #c7cdd6!important;background:linear-gradient(135deg,#f9fafb 0%,#eaf1ea 100%)!important;border-radius:12px!important}.cmp-admin .cmp-switch input:checked+.cmp-switch-slider{background:#2e7d32!important}.cmp-admin .cmp-bp-toggle button.is-active{background:#2e7d32!important;border-color:#2e7d32!important}.cmp-admin .button-primary{background:#2e7d32!important;border-color:#1b5e20!important;color:#fff!important;border-radius:8px!important;box-shadow:none!important;text-shadow:none!important}.cmp-admin .button-primary:hover{background:#1b5e20!important}.cmp-admin .cmp-sticky-save{border:1px solid #e5e7eb!important;border-radius:14px!important;box-shadow:0 1px 3px rgba(16,24,40,.06)!important;margin:0 0 18px!important;padding:14px 18px!important}.cmp-admin input[type=text],.cmp-admin input[type=url],.cmp-admin input[type=number],.cmp-admin select{border-radius:9px!important;border:1px solid #e5e7eb!important}.cmp-admin .cmp-bottom-submit{position:sticky!important;bottom:0;background:rgba(255,255,255,.94);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);border:1px solid #e5e7eb;border-radius:14px;display:flex;align-items:center;justify-content:space-between;gap:16px;box-shadow:0 -2px 10px rgba(16,24,40,.06);padding:13px 20px!important;margin-top:6px!important}.cmp-admin .cmp-bottom-submit .submit{margin:0!important;padding:0!important}.cmp-rd-status{display:flex;align-items:center;gap:9px;font-weight:600;font-size:13px;color:#16a34a}.cmp-rd-dot{width:9px;height:9px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.18)}.cmp-rd-status.dirty{color:#b45309}.cmp-rd-status.dirty .cmp-rd-dot{background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.18)}.cmp-admin .cmp-styling-controls>h2,.cmp-admin .cmp-preview-container>h2,.cmp-admin .cmp-shortcode>h2{font-size:15px!important;font-weight:700!important;margin:0!important}
			.cmp-hero{position:relative;overflow:hidden;border-radius:16px;margin:0 0 18px;padding:30px 32px;color:#fff;background:linear-gradient(120deg,#163d1c 0%,#2e7d32 46%,#0f9488 100%);box-shadow:0 12px 30px rgba(16,40,24,.22);display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;max-width:1320px}.cmp-hero:before{content:"";position:absolute;inset:0;background:radial-gradient(140px 140px at 90% 12%,rgba(255,255,255,.20),transparent 60%),radial-gradient(180px 180px at 72% 130%,rgba(255,255,255,.10),transparent 60%);pointer-events:none}.cmp-hero-left{display:flex;align-items:center;gap:18px;position:relative;z-index:1;min-width:0}.cmp-hero-icon{flex:0 0 auto;width:58px;height:58px;border-radius:15px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.30);display:flex;align-items:center;justify-content:center}.cmp-hero-icon svg{width:30px;height:30px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.cmp-admin h1.cmp-hero-title{margin:0;padding:0;font-size:25px;font-weight:800;letter-spacing:-.02em;line-height:1.12;display:flex;align-items:center;gap:11px;flex-wrap:wrap;color:#fff}.cmp-hero-pill{display:inline-flex;align-items:center;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;background:rgba(255,255,255,.20);border:1px solid rgba(255,255,255,.32)}.cmp-hero-sub{margin:7px 0 0;font-size:13.5px;line-height:1.5;color:rgba(255,255,255,.92);max-width:580px}.cmp-hero-actions{display:flex;gap:10px;position:relative;z-index:1;flex-wrap:wrap}.cmp-hero-chip{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#fff;text-decoration:none;padding:10px 15px;border-radius:11px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.30);cursor:pointer;transition:.15s}.cmp-hero-chip:hover{background:rgba(255,255,255,.26)}.cmp-hero-chip:focus{outline:2px solid #fff;outline-offset:2px}.cmp-hero-chip svg{width:16px;height:16px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.cmp-hero-chip code{font-family:ui-monospace,Menlo,Consolas,monospace;background:rgba(0,0,0,.20);padding:2px 7px;border-radius:6px;font-size:12px}.cmp-tips{position:relative;max-width:1320px;margin:0 0 18px;border-radius:14px;padding:15px 16px;background:linear-gradient(180deg,#fffdf4,#fff);border:1px solid #f0e3ab;box-shadow:0 1px 3px rgba(16,24,40,.06);display:flex;gap:14px;align-items:flex-start}.cmp-tips-ic{flex:0 0 auto;width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#f59e0b,#ea580c);display:flex;align-items:center;justify-content:center}.cmp-tips-ic svg{width:19px;height:19px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.cmp-tips-body{flex:1 1 auto;min-width:0}.cmp-tips-body h3{margin:0 0 6px;font-size:14px;font-weight:700;color:#1d2327}.cmp-tips-list{margin:0;padding:0;list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:6px 22px}.cmp-tips-list li{font-size:13px;line-height:1.45;color:#50575e;padding-left:18px;position:relative}.cmp-tips-list li:before{content:"";position:absolute;left:2px;top:7px;width:6px;height:6px;border-radius:50%;background:#2e7d32}.cmp-tips-list code{font-family:ui-monospace,Menlo,Consolas,monospace;background:#eef2ee;color:#1b5e20;padding:1px 6px;border-radius:5px;font-size:12px}.cmp-tips-close{flex:0 0 auto;border:0;background:transparent;color:#8c8f94;cursor:pointer;font-size:19px;line-height:1;padding:3px 7px;border-radius:6px}.cmp-tips-close:hover{background:#f0f0f1;color:#1d2327}@media(max-width:960px){.cmp-hero{padding:24px}.cmp-tips-list{grid-template-columns:1fr}}</style>

			<?php if ( ! get_user_meta( get_current_user_id(), 'cmp_tips_dismissed', true ) ) : ?>
			<div id="cmp-tips" class="cmp-tips" data-cmp-tips-nonce="<?php echo esc_attr( wp_create_nonce( 'cmp_tips' ) ); ?>">
				<div class="cmp-tips-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1V17h6v-.2c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z"/></svg></div>
				<div class="cmp-tips-body">
					<h3><?php esc_html_e( 'Quick tips', 'copyright-maker-pro' ); ?></h3>
					<ul class="cmp-tips-list">
						<li><?php printf( wp_kses( __( 'Place %s in your footer widget or theme footer to show it site-wide.', 'copyright-maker-pro' ), array( 'code' => array() ) ), '<code>[cmp_copyright]</code>' ); ?></li>
						<li><?php printf( wp_kses( __( 'Set a %1$sStart Year%2$s for a range like 2018-2026; leave blank for the current year only.', 'copyright-maker-pro' ), array( 'strong' => array() ) ), '<strong>', '</strong>' ); ?></li>
						<li><?php printf( wp_kses( __( 'Enable %1$sLegal Links%2$s to append Privacy Policy &amp; Terms, each with its own new-tab toggle.', 'copyright-maker-pro' ), array( 'strong' => array() ) ), '<strong>', '</strong>' ); ?></li>
						<li><?php printf( wp_kses( __( 'Use the %1$sStyling%2$s tab for fonts, colors, and responsive sizes; watch the Live Preview update.', 'copyright-maker-pro' ), array( 'strong' => array() ) ), '<strong>', '</strong>' ); ?></li>
					</ul>
				</div>
				<button type="button" class="cmp-tips-close" id="cmp-tips-close" aria-label="<?php esc_attr_e( 'Dismiss tips', 'copyright-maker-pro' ); ?>">&times;</button>
			</div>
			<?php endif; ?>

			<div class="cmp-admin-grid">
				<form id="cmp-settings-form" method="post" action="options.php" class="cmp-card cmp-form-card">
					<?php settings_fields( 'CMP_settings_group' ); ?>
					<div class="cmp-sticky-save"><strong><?php esc_html_e( 'Copyright Maker Settings', 'copyright-maker-pro' ); ?></strong><?php submit_button( __( 'Save Settings', 'copyright-maker-pro' ), 'primary', 'submit', false ); ?></div>

					<section class="cmp-section">
						<h2><?php esc_html_e( 'Basic Info', 'copyright-maker-pro' ); ?></h2>
						<div class="cmp-field"><label class="cmp-field-label" for="cmp_company_name"><?php esc_html_e( 'Company Name', 'copyright-maker-pro' ); ?></label><div><input type="text" id="cmp_company_name" data-cmp-preview="company" name="<?php echo esc_attr( CMP_OPTION ); ?>[company_name]" value="<?php echo esc_attr( $settings['company_name'] ); ?>" class="regular-text"></div></div>
						<div class="cmp-field"><label class="cmp-field-label" for="cmp_start_year"><?php esc_html_e( 'Start Year', 'copyright-maker-pro' ); ?></label><div><input type="text" id="cmp_start_year" data-cmp-preview="start_year" name="<?php echo esc_attr( CMP_OPTION ); ?>[start_year]" value="<?php echo esc_attr( $settings['start_year'] ); ?>" class="cmp-short-input" placeholder="<?php echo esc_attr( (string) ( (int) current_time( 'Y' ) - 1 ) ); ?>"><p class="cmp-help"><?php esc_html_e( 'Optional. Leave blank to show only the current year.', 'copyright-maker-pro' ); ?></p></div></div>
						<div class="cmp-field"><span class="cmp-field-label"><?php esc_html_e( 'Apply Link', 'copyright-maker-pro' ); ?></span><div><label><input type="checkbox" data-cmp-preview="link_enabled" name="<?php echo esc_attr( CMP_OPTION ); ?>[link_enabled]" value="1" <?php checked( $settings['link_enabled'], '1' ); ?>> <?php esc_html_e( 'Link company name to homepage', 'copyright-maker-pro' ); ?></label></div></div>
					</section>

					<section class="cmp-section">
						<h2><?php esc_html_e( 'Output Formatting', 'copyright-maker-pro' ); ?></h2>
						<div class="cmp-field"><label class="cmp-field-label" for="cmp_symbol"><?php esc_html_e( 'Symbol Options', 'copyright-maker-pro' ); ?></label><div><div class="cmp-inline-option-row"><select id="cmp_symbol" data-cmp-preview="symbol" name="<?php echo esc_attr( CMP_OPTION ); ?>[symbol]"><option value="copyright" <?php selected( $settings['symbol'], 'copyright' ); ?>>Copyright (&copy;)</option><option value="registered" <?php selected( $settings['symbol'], 'registered' ); ?>>Registered (&reg;)</option><option value="trademark" <?php selected( $settings['symbol'], 'trademark' ); ?>>Trademark (&trade;)</option><option value="custom" <?php selected( $settings['symbol'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'copyright-maker-pro' ); ?></option></select><span class="cmp-custom-symbol-wrap"><input type="text" id="cmp_custom_symbol" data-cmp-preview="custom_symbol" name="<?php echo esc_attr( CMP_OPTION ); ?>[custom_symbol]" value="<?php echo esc_attr( $settings['custom_symbol'] ); ?>" class="cmp-short-input" placeholder="<?php esc_attr_e( 'Enter custom symbol...', 'copyright-maker-pro' ); ?>"></span></div><span class="cmp-sublabel"><?php esc_html_e( 'Custom field appears inline when Custom is selected - no layout shift.', 'copyright-maker-pro' ); ?></span></div></div>
						<div class="cmp-field"><label class="cmp-field-label" for="cmp_separator_option"><?php esc_html_e( 'Separator Options', 'copyright-maker-pro' ); ?></label><div><div class="cmp-inline-option-row"><select id="cmp_separator_option" data-cmp-preview="separator_option" name="<?php echo esc_attr( CMP_OPTION ); ?>[separator_option]"><option value="pipe" <?php selected( $settings['separator_option'], 'pipe' ); ?>>Pipe (|)</option><option value="dash" <?php selected( $settings['separator_option'], 'dash' ); ?>>Dash (-)</option><option value="emdash" <?php selected( $settings['separator_option'], 'emdash' ); ?>>Em Dash (&mdash;)</option><option value="bullet" <?php selected( $settings['separator_option'], 'bullet' ); ?>>Bullet (&bull;)</option><option value="slash" <?php selected( $settings['separator_option'], 'slash' ); ?>>Slash (/)</option><option value="space" <?php selected( $settings['separator_option'], 'space' ); ?>><?php esc_html_e( 'Space', 'copyright-maker-pro' ); ?></option><option value="custom" <?php selected( $settings['separator_option'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'copyright-maker-pro' ); ?></option></select><span class="cmp-custom-separator-wrap"><input type="text" id="cmp_separator" data-cmp-preview="separator" name="<?php echo esc_attr( CMP_OPTION ); ?>[separator]" value="<?php echo esc_attr( $settings['separator'] ); ?>" class="cmp-short-input" placeholder="<?php esc_attr_e( 'Enter separator...', 'copyright-maker-pro' ); ?>"></span></div><span class="cmp-sublabel"><?php esc_html_e( 'Custom field appears inline when Custom is selected - no layout shift.', 'copyright-maker-pro' ); ?></span></div></div>
						<div class="cmp-field"><label class="cmp-field-label" for="cmp_rights_text"><?php esc_html_e( 'Custom Text', 'copyright-maker-pro' ); ?></label><div><input type="text" id="cmp_rights_text" data-cmp-preview="rights" name="<?php echo esc_attr( CMP_OPTION ); ?>[rights_text]" value="<?php echo esc_attr( $settings['rights_text'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional custom text', 'copyright-maker-pro' ); ?>"></div></div>
					</section>

					<section class="cmp-section cmp-legal-section">
						<h2><?php esc_html_e( 'Legal Link Support', 'copyright-maker-pro' ); ?></h2>
						<div class="cmp-field"><span class="cmp-field-label"><?php esc_html_e( 'Enable Legal Links', 'copyright-maker-pro' ); ?></span><div class="cmp-toggle-row"><label class="cmp-switch" for="cmp_legal_links_enabled"><input type="checkbox" id="cmp_legal_links_enabled" data-cmp-preview="legal_links_enabled" name="<?php echo esc_attr( CMP_OPTION ); ?>[legal_links_enabled]" value="1" aria-label="<?php esc_attr_e( 'Enable Legal Links', 'copyright-maker-pro' ); ?>" <?php checked( $settings['legal_links_enabled'], '1' ); ?>><span class="cmp-switch-slider" aria-hidden="true"></span><span><?php esc_html_e( 'Display legal/footer links', 'copyright-maker-pro' ); ?></span></label></div></div>
						<div id="cmp-legal-controls" class="cmp-legal-controls">
							<div class="cmp-field"><label class="cmp-field-label" for="cmp_legal_link_divider"><?php esc_html_e( 'Legal Link Divider', 'copyright-maker-pro' ); ?></label><div><div class="cmp-inline-option-row"><select id="cmp_legal_link_divider" data-cmp-preview="legal_link_divider" name="<?php echo esc_attr( CMP_OPTION ); ?>[legal_link_divider]"><?php $this->legal_link_divider_options_markup( $settings['legal_link_divider'] ); ?></select><span class="cmp-legal-custom-divider-wrap"><input type="text" id="cmp_legal_link_custom_divider" data-cmp-preview="legal_link_custom_divider" name="<?php echo esc_attr( CMP_OPTION ); ?>[legal_link_custom_divider]" value="<?php echo esc_attr( $settings['legal_link_custom_divider'] ); ?>" placeholder="<?php esc_attr_e( 'Enter divider...', 'copyright-maker-pro' ); ?>"></span></div><span class="cmp-sublabel"><?php esc_html_e( 'Choose how legal links are separated.', 'copyright-maker-pro' ); ?></span><span id="cmp-legal-divider-preview" class="cmp-legal-divider-preview" aria-live="polite"></span></div></div>
							<div class="cmp-field"><span class="cmp-field-label"><?php esc_html_e( 'Legal Links', 'copyright-maker-pro' ); ?></span><div><div id="cmp-legal-links-list" class="cmp-legal-links-list"><?php if ( empty( $settings['legal_links'] ) ) : ?><p class="cmp-legal-empty" id="cmp-legal-empty"><?php esc_html_e( 'No legal links added yet.', 'copyright-maker-pro' ); ?></p><?php endif; ?><?php foreach ( $settings['legal_links'] as $index => $legal_link ) : ?><div class="cmp-legal-link-row" data-legal-row><div class="cmp-legal-link-field"><label for="cmp_legal_name_<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Label', 'copyright-maker-pro' ); ?></label><input type="text" id="cmp_legal_name_<?php echo esc_attr( $index ); ?>" data-cmp-legal-name name="<?php echo esc_attr( CMP_OPTION ); ?>[legal_links][<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $legal_link['name'] ?? '' ); ?>"></div><div class="cmp-legal-link-field"><label for="cmp_legal_url_<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'URL', 'copyright-maker-pro' ); ?></label><input type="url" id="cmp_legal_url_<?php echo esc_attr( $index ); ?>" data-cmp-legal-url name="<?php echo esc_attr( CMP_OPTION ); ?>[legal_links][<?php echo esc_attr( $index ); ?>][url]" value="<?php echo esc_url( $legal_link['url'] ?? '' ); ?>" placeholder="https://example.com/privacy-policy"></div><label class="cmp-legal-new-tab" for="cmp_legal_new_tab_<?php echo esc_attr( $index ); ?>"><input type="checkbox" id="cmp_legal_new_tab_<?php echo esc_attr( $index ); ?>" data-cmp-legal-new-tab aria-label="<?php esc_attr_e( 'Open this legal link in a new browser tab', 'copyright-maker-pro' ); ?>" name="<?php echo esc_attr( CMP_OPTION ); ?>[legal_links][<?php echo esc_attr( $index ); ?>][new_tab]" value="1" <?php checked( $legal_link['new_tab'] ?? '', '1' ); ?>> <?php esc_html_e( 'New Tab', 'copyright-maker-pro' ); ?> <span class="cmp-tooltip" tabindex="0" data-tooltip="<?php esc_attr_e( 'Opens this link in a new browser tab.', 'copyright-maker-pro' ); ?>">i</span></label><button type="button" class="cmp-delete-legal-link" aria-label="<?php esc_attr_e( 'Delete legal link', 'copyright-maker-pro' ); ?>"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M7 2h6l1 2h4v2H2V4h4l1-2zm1 6h2v8H8V8zm4 0h2v8h-2V8zM5 8h2v8H5V8z"/></svg></button></div><?php endforeach; ?></div><div class="cmp-legal-actions"><button type="button" class="button button-secondary cmp-add-legal-link" id="cmp-add-legal-link"><span class="cmp-add-icon" aria-hidden="true">+</span><?php esc_html_e( 'Add Legal Link', 'copyright-maker-pro' ); ?></button></div></div></div>
						</div>
					</section>

					<section class="cmp-section">
						<div class="cmp-section-head"><h2><?php esc_html_e( 'Layout', 'copyright-maker-pro' ); ?></h2><div class="cmp-bp-toggle" role="group" aria-label="<?php esc_attr_e( 'Editing breakpoint', 'copyright-maker-pro' ); ?>"><button type="button" data-cmp-device="desktop" class="is-active" title="<?php esc_attr_e( 'Desktop', 'copyright-maker-pro' ); ?>" aria-label="<?php esc_attr_e( 'Desktop', 'copyright-maker-pro' ); ?>"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M2 3h16a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1h-6v2h3v1H5v-1h3v-2H2a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm1 2v7h14V5H3z"/></svg></button><button type="button" data-cmp-device="tablet" title="<?php esc_attr_e( 'Tablet', 'copyright-maker-pro' ); ?>" aria-label="<?php esc_attr_e( 'Tablet', 'copyright-maker-pro' ); ?>"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 1h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2zm0 2v12h10V3H5zm5 13a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></svg></button><button type="button" data-cmp-device="mobile" title="<?php esc_attr_e( 'Mobile', 'copyright-maker-pro' ); ?>" aria-label="<?php esc_attr_e( 'Mobile', 'copyright-maker-pro' ); ?>"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 1h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2zm0 2v12h8V3H6zm4 13a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></svg></button></div></div>
						<div class="cmp-field"><label class="cmp-field-label" for="cmp_layout_direction"><?php esc_html_e( 'Direction', 'copyright-maker-pro' ); ?></label><div><select id="cmp_layout_direction" data-cmp-preview="layout_direction"><option value="row" <?php selected( $settings['layout_direction'], 'row' ); ?>><?php esc_html_e( 'Row', 'copyright-maker-pro' ); ?></option><option value="column" <?php selected( $settings['layout_direction'], 'column' ); ?>><?php esc_html_e( 'Column', 'copyright-maker-pro' ); ?></option><option value="row-reverse" <?php selected( $settings['layout_direction'], 'row-reverse' ); ?>><?php esc_html_e( 'Row Reverse', 'copyright-maker-pro' ); ?></option><option value="column-reverse" <?php selected( $settings['layout_direction'], 'column-reverse' ); ?>><?php esc_html_e( 'Column Reverse', 'copyright-maker-pro' ); ?></option></select><span class="cmp-sublabel"><?php esc_html_e( 'How the copyright text and legal links are arranged.', 'copyright-maker-pro' ); ?></span></div></div>
						<div class="cmp-field"><label class="cmp-field-label" for="cmp_layout_alignment"><?php esc_html_e( 'Alignment', 'copyright-maker-pro' ); ?></label><div><select id="cmp_layout_alignment" data-cmp-preview="layout_alignment"><option value="left" <?php selected( $settings['layout_alignment'], 'left' ); ?>><?php esc_html_e( 'Left', 'copyright-maker-pro' ); ?></option><option value="center" <?php selected( $settings['layout_alignment'], 'center' ); ?>><?php esc_html_e( 'Center', 'copyright-maker-pro' ); ?></option><option value="right" <?php selected( $settings['layout_alignment'], 'right' ); ?>><?php esc_html_e( 'Right', 'copyright-maker-pro' ); ?></option></select></div></div>
						<div class="cmp-field"><label class="cmp-field-label" for="cmp_layout_justify"><?php esc_html_e( 'Distribution', 'copyright-maker-pro' ); ?></label><div><select id="cmp_layout_justify" data-cmp-preview="layout_justify"><option value="default" <?php selected( $settings['layout_justify'], 'default' ); ?>><?php esc_html_e( 'Default (follow Alignment)', 'copyright-maker-pro' ); ?></option><option value="space-between" <?php selected( $settings['layout_justify'], 'space-between' ); ?>><?php esc_html_e( 'Space Between', 'copyright-maker-pro' ); ?></option><option value="space-around" <?php selected( $settings['layout_justify'], 'space-around' ); ?>><?php esc_html_e( 'Space Around', 'copyright-maker-pro' ); ?></option><option value="space-evenly" <?php selected( $settings['layout_justify'], 'space-evenly' ); ?>><?php esc_html_e( 'Space Evenly', 'copyright-maker-pro' ); ?></option></select><span class="cmp-sublabel"><?php esc_html_e( 'justify-content for the row. Space Between pushes legal links to the opposite side. Best with Row direction.', 'copyright-maker-pro' ); ?></span></div></div>
						<div class="cmp-field"><label class="cmp-field-label" for="cmp_layout_gap"><?php esc_html_e( 'Gap', 'copyright-maker-pro' ); ?></label><div><div class="cmp-inline-option-row" style="grid-template-columns:96px auto;max-width:200px"><input type="number" id="cmp_layout_gap" data-cmp-preview="layout_gap" value="<?php echo esc_attr( $settings['layout_gap'] ); ?>" min="0" step="1" class="cmp-short-input" placeholder="<?php esc_attr_e( 'Auto', 'copyright-maker-pro' ); ?>"><span>px</span></div><span class="cmp-sublabel"><?php esc_html_e( 'Optional. Spacing between the copyright text and legal links.', 'copyright-maker-pro' ); ?></span></div></div>
						<?php foreach ( array( 'desktop' => '', 'tablet' => '_tablet', 'mobile' => '_mobile' ) as $cmp_bp => $cmp_sfx ) : ?>
							<?php foreach ( array( 'layout_direction', 'layout_alignment', 'layout_justify', 'layout_gap' ) as $cmp_ctrl ) : ?>
								<input type="hidden" data-cmp-store="<?php echo esc_attr( $cmp_ctrl ); ?>" data-cmp-bp="<?php echo esc_attr( $cmp_bp ); ?>" name="<?php echo esc_attr( CMP_OPTION ); ?>[<?php echo esc_attr( $cmp_ctrl . $cmp_sfx ); ?>]" value="<?php echo esc_attr( $settings[ $cmp_ctrl . $cmp_sfx ] ); ?>">
							<?php endforeach; ?>
						<?php endforeach; ?>
					</section>

					<div class="cmp-bottom-submit"><?php submit_button( __( 'Save Settings', 'copyright-maker-pro' ) ); ?></div>
				</form>

				<div class="cmp-side-column"><div class="cmp-card cmp-side-card"><div class="cmp-styling-controls"><h2><?php esc_html_e( 'Styling Controls', 'copyright-maker-pro' ); ?></h2><div class="cmp-style-grid">
					<div class="cmp-style-field"><label for="cmp_font_family"><?php esc_html_e( 'Font Family', 'copyright-maker-pro' ); ?></label><input type="text" id="cmp_font_family" data-cmp-preview="font_family" form="cmp-settings-form" name="<?php echo esc_attr( CMP_OPTION ); ?>[font_family]" value="<?php echo esc_attr( $settings['font_family'] ); ?>" placeholder="Arial, sans-serif"></div>
					<div class="cmp-style-field"><label for="cmp_font_weight"><?php esc_html_e( 'Font Weight', 'copyright-maker-pro' ); ?></label><select id="cmp_font_weight" data-cmp-preview="font_weight" form="cmp-settings-form" name="<?php echo esc_attr( CMP_OPTION ); ?>[font_weight]"><?php $this->font_weight_options_markup( $settings['font_weight'] ); ?></select></div>
					<div class="cmp-style-field"><label for="cmp_font_size"><?php esc_html_e( 'Desktop Font Size', 'copyright-maker-pro' ); ?></label><input type="number" id="cmp_font_size" data-cmp-preview="font_size" form="cmp-settings-form" name="<?php echo esc_attr( CMP_OPTION ); ?>[font_size]" value="<?php echo esc_attr( $settings['font_size'] ); ?>" min="1" step="1"></div>
					<div class="cmp-style-field"><label for="cmp_font_size_tablet"><?php esc_html_e( 'Tablet Font Size', 'copyright-maker-pro' ); ?></label><input type="number" id="cmp_font_size_tablet" data-cmp-preview="font_size_tablet" form="cmp-settings-form" name="<?php echo esc_attr( CMP_OPTION ); ?>[font_size_tablet]" value="<?php echo esc_attr( $settings['font_size_tablet'] ); ?>" min="1" step="1" placeholder="<?php esc_attr_e( 'Inherit', 'copyright-maker-pro' ); ?>"></div>
					<div class="cmp-style-field"><label for="cmp_font_size_mobile"><?php esc_html_e( 'Mobile Font Size', 'copyright-maker-pro' ); ?></label><input type="number" id="cmp_font_size_mobile" data-cmp-preview="font_size_mobile" form="cmp-settings-form" name="<?php echo esc_attr( CMP_OPTION ); ?>[font_size_mobile]" value="<?php echo esc_attr( $settings['font_size_mobile'] ); ?>" min="1" step="1" placeholder="<?php esc_attr_e( 'Inherit', 'copyright-maker-pro' ); ?>"></div>
					<div class="cmp-style-field cmp-color-style-field"><label for="cmp_text_color"><?php esc_html_e( 'Text Color', 'copyright-maker-pro' ); ?></label><input type="text" id="cmp_text_color" class="cmp-color-field" data-cmp-preview="text_color" form="cmp-settings-form" name="<?php echo esc_attr( CMP_OPTION ); ?>[text_color]" value="<?php echo esc_attr( $settings['text_color'] ); ?>"></div>
					<div class="cmp-style-field cmp-color-style-field"><label for="cmp_link_color"><?php esc_html_e( 'Link Color', 'copyright-maker-pro' ); ?></label><input type="text" id="cmp_link_color" class="cmp-color-field" data-cmp-preview="link_color" form="cmp-settings-form" name="<?php echo esc_attr( CMP_OPTION ); ?>[link_color]" value="<?php echo esc_attr( $settings['link_color'] ); ?>"></div>
					<div class="cmp-style-field cmp-color-style-field"><label for="cmp_hover_color"><?php esc_html_e( 'Hover Color', 'copyright-maker-pro' ); ?></label><input type="text" id="cmp_hover_color" class="cmp-color-field" data-cmp-preview="hover_color" form="cmp-settings-form" name="<?php echo esc_attr( CMP_OPTION ); ?>[hover_color]" value="<?php echo esc_attr( $settings['hover_color'] ); ?>"></div>
					<div class="cmp-style-field"><label for="cmp_letter_spacing"><?php esc_html_e( 'Letter Spacing', 'copyright-maker-pro' ); ?></label><input type="number" id="cmp_letter_spacing" data-cmp-preview="letter_spacing" form="cmp-settings-form" name="<?php echo esc_attr( CMP_OPTION ); ?>[letter_spacing]" value="<?php echo esc_attr( $settings['letter_spacing'] ); ?>" step="0.1"> <span>px</span></div>
					<div class="cmp-style-field"><label for="cmp_text_transform"><?php esc_html_e( 'Text Transform', 'copyright-maker-pro' ); ?></label><select id="cmp_text_transform" data-cmp-preview="text_transform" form="cmp-settings-form" name="<?php echo esc_attr( CMP_OPTION ); ?>[text_transform]"><option value="none" <?php selected( $settings['text_transform'], 'none' ); ?>><?php esc_html_e( 'Default', 'copyright-maker-pro' ); ?></option><option value="uppercase" <?php selected( $settings['text_transform'], 'uppercase' ); ?>><?php esc_html_e( 'Uppercase', 'copyright-maker-pro' ); ?></option><option value="lowercase" <?php selected( $settings['text_transform'], 'lowercase' ); ?>><?php esc_html_e( 'Lowercase', 'copyright-maker-pro' ); ?></option><option value="capitalize" <?php selected( $settings['text_transform'], 'capitalize' ); ?>><?php esc_html_e( 'Capitalize', 'copyright-maker-pro' ); ?></option></select></div>
				</div></div></div><div class="cmp-card cmp-preview-container"><h2><?php esc_html_e( 'Live Preview', 'copyright-maker-pro' ); ?></h2><div id="cmp-live-preview" class="cmp-preview-box"></div><div id="cmp-preview-hint" class="cmp-preview-hint" role="status" aria-live="polite" style="display:none"></div><div id="cmp-preview-scale-note" class="cmp-preview-hint" style="display:none"></div><div class="cmp-shortcode"><h2><?php esc_html_e( 'Shortcode', 'copyright-maker-pro' ); ?></h2><p><?php esc_html_e( 'Use this shortcode anywhere:', 'copyright-maker-pro' ); ?></p><div class="cmp-shortcode-row"><input id="cmp-shortcode-input" type="text" readonly class="regular-text code" value="[cmp_copyright]" onclick="this.select();"><button type="button" class="button button-secondary" id="cmp-copy-shortcode"><?php esc_html_e( 'Copy to Clipboard', 'copyright-maker-pro' ); ?></button></div><span class="cmp-copy-status" id="cmp-copy-status" aria-live="polite"></span></div></div></div>
			</div>
			<script>
			(function($){var previewDevice='desktop';var LAYOUT_KEYS=['layout_direction','layout_alignment','layout_justify','layout_gap'];
				function field(key){return document.querySelector('[data-cmp-preview="'+key+'"]');}
				function value(key){var el=field(key); if(!el){return '';} return el.type==='checkbox' ? (el.checked ? '1' : '0') : el.value;}
				function googleFontName(fontFamily){fontFamily=String(fontFamily||'').trim();if(!fontFamily){return '';}var font=fontFamily.split(',')[0].replace(/^[\s'\"]+|[\s'\"]+$/g,'');var generic=['arial','georgia','times new roman','times','courier new','courier','verdana','tahoma','trebuchet ms','serif','sans-serif','monospace','cursive','fantasy','system-ui','inherit','initial','unset'];if(!font||generic.indexOf(font.toLowerCase())!==-1||!/^[A-Za-z0-9 \-]+$/.test(font)){return '';}return font;}
				function loadGoogleFontPreview(fontFamily){var id='cmp-google-font-preview';var old=document.getElementById(id);var font=googleFontName(fontFamily);if(!font){if(old){old.parentNode.removeChild(old);}return;}var href='https://fonts.googleapis.com/css2?family='+encodeURIComponent(font).replace(/%20/g,'+')+':wght@400;500;600;700;800&display=swap';if(old&&old.getAttribute('href')===href){return;}if(!old){old=document.createElement('link');old.id=id;old.rel='stylesheet';document.head.appendChild(old);}old.href=href;}
				function esc(text){return String(text).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];});}
				function symbolOutput(){var s=value('symbol'); if(s==='registered'){return '&reg;';} if(s==='trademark'){return '&trade;';} if(s==='custom'){return esc(value('custom_symbol').trim());} return '&copy;';}
				function separatorOutput(){var s=value('separator_option'); if(s==='dash'){return '-';} if(s==='emdash'){return '&mdash;';} if(s==='bullet'){return '&bull;';} if(s==='slash'){return '/';} if(s==='space'){return '&nbsp;';} if(s==='custom'){return esc(value('separator').trim());} return '|';}
				function legalDividerOutput(base){var s=value('legal_link_divider'); if(s==='pipe'){return '|';} if(s==='dot'){return '&middot;';} if(s==='bullet'){return '&bull;';} if(s==='dash'){return '-';} if(s==='slash'){return '/';} if(s==='custom'){return esc(value('legal_link_custom_divider').trim());} return base;}
				function updateLegalEnabledState(){var controls=document.getElementById('cmp-legal-controls');if(controls){controls.classList.toggle('is-disabled',value('legal_links_enabled')!=='1');}}function updateLegalDividerPreview(){var target=document.getElementById('cmp-legal-divider-preview');if(!target){return;}var d=legalDividerOutput(separatorOutput());var sep=d?(' '+d+' '):' ';target.innerHTML='<strong>Preview:</strong> Terms &amp; Conditions'+sep+'Privacy Policy';}function showToast(message,type){var toast=document.getElementById('cmp-toast');if(!toast){return;}toast.textContent=message;toast.className='cmp-toast is-visible '+(type==='error'?'is-error':'is-success');setTimeout(function(){toast.classList.remove('is-visible');},3000);}function toggleCustomOptionFields(){var sw=document.querySelector('.cmp-custom-symbol-wrap');var sepw=document.querySelector('.cmp-custom-separator-wrap');var ldw=document.querySelector('.cmp-legal-custom-divider-wrap');if(sw){sw.classList.toggle('cmp-custom-option-hidden',value('symbol')!=='custom');}if(sepw){sepw.classList.toggle('cmp-custom-option-hidden',value('separator_option')!=='custom');}if(ldw){ldw.classList.toggle('cmp-custom-option-hidden',value('legal_link_divider')!=='custom');}updateLegalEnabledState();updateLegalDividerPreview();}
				function legalLinksHtml(divider){if(value('legal_links_enabled')!=='1'){return '';}var rows=document.querySelectorAll('[data-legal-row]');var links=[];rows.forEach(function(row){var name=(row.querySelector('[data-cmp-legal-name]')||{}).value||'';var url=(row.querySelector('[data-cmp-legal-url]')||{}).value||'';var nt=row.querySelector('[data-cmp-legal-new-tab]');name=name.trim();url=url.trim();if(!name){return;}var lc=esc(value('link_color')||'#2271b1');if(url){links.push('<a class="cmp-legal-link" href="'+esc(url)+'" style="color:'+lc+';text-decoration:none;" '+(nt&&nt.checked?'target="_blank" rel="noopener noreferrer"':'')+'>'+esc(name)+'</a>');}else{links.push('<span class="cmp-legal-link-pending" style="color:'+lc+';">'+esc(name)+'</span>');}});if(!links.length){return '';}var d=divider?'<span class="cmp-legal-divider'+(divider==='&nbsp;'?' cmp-legal-divider-space':'')+'" aria-hidden="true">'+divider+'</span>':' ';return '<span class="cmp-legal-links">'+links.join(d)+'</span>';}
				function legalRowStats(){var s={labeled:0,urlless:0,complete:0};document.querySelectorAll('[data-legal-row]').forEach(function(row){var name=((row.querySelector('[data-cmp-legal-name]')||{}).value||'').trim();var url=((row.querySelector('[data-cmp-legal-url]')||{}).value||'').trim();if(name){s.labeled++;if(url){s.complete++;}else{s.urlless++;}}});return s;}
				function updatePreviewHint(){var hint=document.getElementById('cmp-preview-hint');if(!hint){return;}var enabled=value('legal_links_enabled')==='1';var s=legalRowStats();var msg='';var warn=false;if(!enabled&&s.labeled>0){msg='<?php echo esc_js( __( 'You added legal links but they are hidden. Turn on "Enable Legal Links" to show them here and on your site.', 'copyright-maker-pro' ) ); ?>';warn=true;}else if(enabled&&s.urlless>0){msg='<?php echo esc_js( __( 'Links without a URL appear in the preview but will not show on your site until you add a URL.', 'copyright-maker-pro' ) ); ?>';}else if(enabled&&s.labeled===0){msg='<?php echo esc_js( __( 'Type a Label in a legal link row to preview it.', 'copyright-maker-pro' ) ); ?>';}hint.innerHTML=msg;hint.className='cmp-preview-hint'+(warn?' is-warning':'');hint.style.display=msg?'':'none';}
				function updatePreviewScaleNote(actual){var n=document.getElementById('cmp-preview-scale-note');if(!n){return;}if(actual&&actual>0){n.textContent='<?php echo esc_js( __( 'Preview text is scaled down to keep the layout visible. Actual size:', 'copyright-maker-pro' ) ); ?> '+actual+'px';n.style.display='';}else{n.style.display='none';}}
				function cmpStoreEl(key,bp){return document.querySelector('[data-cmp-store="'+key+'"][data-cmp-bp="'+bp+'"]');}
				function cmpVisEl(key){return document.querySelector('[data-cmp-preview="'+key+'"]');}
				function saveLayoutToStore(bp){LAYOUT_KEYS.forEach(function(k){var v=cmpVisEl(k),s=cmpStoreEl(k,bp);if(v&&s){s.value=v.value;}});}
				function loadLayoutFromStore(bp){LAYOUT_KEYS.forEach(function(k){var v=cmpVisEl(k),s=cmpStoreEl(k,bp);if(v&&s){v.value=s.value;}});}
				function setBreakpoint(bp){saveLayoutToStore(previewDevice);previewDevice=bp;loadLayoutFromStore(bp);var btns=document.querySelectorAll('[data-cmp-device]');for(var i=0;i<btns.length;i++){btns[i].classList.toggle('is-active',btns[i].getAttribute('data-cmp-device')===bp);}updatePreview();}
				function applyLayoutStyles(el,scale){var dir=value('layout_direction')||'row';var align=value('layout_alignment')||'left';var gap=value('layout_gap');var isRow=dir.indexOf('row')===0;var isReverse=(dir==='row-reverse'||dir==='column-reverse');var map={left:'flex-start',center:'center',right:'flex-end'};var jc,ai;if(isRow){jc=map[align]||'flex-start';ai='center';}else{jc='flex-start';ai=map[align]||'flex-start';}var dist=value('layout_justify')||'default';if(dist&&dist!=='default'){jc=dist;}el.style.display='flex';el.style.flexWrap='wrap';el.style.flexDirection=isRow?'row':'column';el.style.justifyContent=jc;el.style.alignItems=ai;el.style.textAlign=align;var gpx=(gap===''||gap==null)?10:(parseInt(gap,10)||0);el.style.gap=(gpx*(scale||1))+'px';var lg=el.querySelector('.cmp-legal-links');if(lg){lg.style.order=isReverse?'-1':'0';}var cg=el.querySelector('.cmp-copyright-group');if(cg){cg.style.order='0';}}
				function updatePreview(){toggleCustomOptionFields();var currentYear=String(new Date().getFullYear());var startYear=value('start_year').trim();var year=(startYear&&startYear!==currentYear)?startYear+'-'+currentYear:currentYear;var company=value('company').trim();var rights=value('rights').trim();var separator=separatorOutput();var linkEnabled=value('link_enabled')==='1';var linkColor=value('link_color')||'#2271b1';var hoverColor=value('hover_color')||'#135e96';var fsDesktop=parseInt(value('font_size'),10)||16;var fsTablet=parseInt(value('font_size_tablet'),10)||0;var fsMobile=parseInt(value('font_size_mobile'),10)||0;var fontSize=(previewDevice==='mobile')?(fsMobile||fsTablet||fsDesktop):((previewDevice==='tablet')?(fsTablet||fsDesktop):fsDesktop);var fontWeight=value('font_weight')||'normal';var fontFamily=value('font_family').trim();loadGoogleFontPreview(fontFamily);var textColor=value('text_color')||'#1d2327';var letterSpacing=parseFloat(value('letter_spacing'))||0;var textTransform=value('text_transform')||'none';var preview=document.getElementById('cmp-live-preview');var companyHtml=esc(company);if(linkEnabled&&company){companyHtml='<a class="cmp-company-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:'+esc(linkColor)+';text-decoration:none;">'+esc(company)+'</a>';}var parts=[symbolOutput(),year];if(company){parts.push(companyHtml);}var copyrightInner='<span class="cmp-main">'+parts.filter(Boolean).join(' ')+'</span>';if(rights){if(separator){copyrightInner+='<span class="cmp-separator'+(separator==='&nbsp;'?' cmp-separator-space':'')+'" aria-hidden="true">'+separator+'</span>';}else{copyrightInner+=' ';}copyrightInner+='<span class="cmp-rights">'+esc(rights)+'</span>';}var html='<span class="cmp-copyright-group">'+copyrightInner+'</span>';var legalDiv=legalDividerOutput(separator);var legalGroup=legalLinksHtml(legalDiv);if(legalGroup){html+=legalGroup;}preview.innerHTML=html;preview.querySelectorAll('.cmp-company-link,.cmp-legal-link').forEach(function(link){link.style.textDecoration='none';link.addEventListener('mouseenter',function(){this.style.color=hoverColor;this.style.textDecoration='none';});link.addEventListener('mouseleave',function(){this.style.color=linkColor;this.style.textDecoration='none';});});var previewCap=20;var previewScale=fontSize>previewCap?previewCap/fontSize:1;preview.style.fontSize=(fontSize*previewScale)+'px';preview.style.fontWeight=fontWeight;preview.style.fontFamily=fontFamily||'';preview.style.color=textColor;preview.style.letterSpacing=(letterSpacing*previewScale)+'px';preview.style.textTransform=(textTransform==='none')?'':textTransform;applyLayoutStyles(preview,previewScale);preview.style.maxWidth=(previewDevice==='mobile')?'380px':((previewDevice==='tablet')?'768px':'none');preview.style.marginLeft='auto';preview.style.marginRight='auto';updatePreviewScaleNote(previewScale<1?fontSize:0);updatePreviewHint();}
				function copyShortcode(){var input=document.getElementById('cmp-shortcode-input');var status=document.getElementById('cmp-copy-status');function done(){status.textContent='<?php echo esc_js( __( 'Copied!', 'copyright-maker-pro' ) ); ?>';setTimeout(function(){status.textContent='';},1600);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(input.value).then(done);}else{input.select();document.execCommand('copy');done();}}
				function cmpApplyRedesign(){if(document.body.getAttribute('data-cmp-redesigned')==='1'){return;}var I={info:'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>',sliders:'<svg viewBox="0 0 24 24"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg>',link:'<svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>',layout:'<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>',type:'<svg viewBox="0 0 24 24"><path d="M4 7V5h16v2M9 19h6M12 5v14"/></svg>',eye:'<svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>',code:'<svg viewBox="0 0 24 24"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></svg>'};function addIcon(h2,color,svg){if(!h2){return;}var ex=h2.closest('.cmp-section-head');var ic=document.createElement('div');ic.className='cmp-rd-ic '+color;ic.innerHTML=svg;if(ex){var g=document.createElement('div');g.className='cmp-rd-head-grp';ex.insertBefore(g,ex.firstChild);g.appendChild(ic);g.appendChild(h2);ex.classList.add('cmp-rd-section-head');}else{var hd=document.createElement('div');hd.className='cmp-rd-head';h2.parentNode.insertBefore(hd,h2);hd.appendChild(ic);hd.appendChild(h2);}}var form=document.getElementById('cmp-settings-form');var side=document.querySelector('.cmp-side-column');if(!form||!side){return;}var sections=form.querySelectorAll('.cmp-section');var stylingCard=side.querySelector('.cmp-side-card');var previewCard=side.querySelector('.cmp-preview-container');if(sections.length<4||!stylingCard||!previewCard){return;}addIcon(sections[0].querySelector('h2'),'blue',I.info);addIcon(sections[1].querySelector('h2'),'purple',I.sliders);addIcon(sections[2].querySelector('h2'),'green',I.link);addIcon(sections[3].querySelector('h2'),'orange',I.layout);addIcon(stylingCard.querySelector('h2'),'teal',I.type);addIcon(previewCard.querySelector('h2'),'blue',I.eye);var scH=previewCard.querySelector('.cmp-shortcode h2');addIcon(scH,'purple',I.code);sections[0].setAttribute('data-rd-tab','settings');sections[1].setAttribute('data-rd-tab','settings');sections[2].setAttribute('data-rd-tab','legal');sections[3].setAttribute('data-rd-tab','layout');stylingCard.setAttribute('data-rd-tab','styling');stylingCard.classList.add('cmp-section');form.insertBefore(stylingCard,sections[2]);var tabs=document.createElement('div');tabs.className='cmp-rd-tabs';tabs.innerHTML='<button type="button" data-t="settings" class="is-active">'+I.info+'<?php echo esc_js( __( 'Settings', 'copyright-maker-pro' ) ); ?></button><button type="button" data-t="styling">'+I.type+'<?php echo esc_js( __( 'Styling', 'copyright-maker-pro' ) ); ?></button><button type="button" data-t="legal">'+I.link+'<?php echo esc_js( __( 'Legal Links', 'copyright-maker-pro' ) ); ?></button><button type="button" data-t="layout">'+I.layout+'<?php echo esc_js( __( 'Layout', 'copyright-maker-pro' ) ); ?></button>';var sticky=form.querySelector('.cmp-sticky-save');form.insertBefore(tabs,sticky?sticky.nextSibling:form.firstChild);function showTab(t){form.querySelectorAll('[data-rd-tab]').forEach(function(el){el.style.display=el.getAttribute('data-rd-tab')===t?'':'none';});tabs.querySelectorAll('button').forEach(function(b){b.classList.toggle('is-active',b.getAttribute('data-t')===t);});}tabs.querySelectorAll('button').forEach(function(b){b.addEventListener('click',function(){showTab(b.getAttribute('data-t'));});});showTab('settings');var bottom=form.querySelector('.cmp-bottom-submit');if(bottom){var stt=document.createElement('span');stt.className='cmp-rd-status';stt.innerHTML='<span class="cmp-rd-dot"></span><span class="cmp-rd-status-txt"><?php echo esc_js( __( 'All changes saved', 'copyright-maker-pro' ) ); ?></span>';bottom.insertBefore(stt,bottom.firstChild);form.addEventListener('input',function(){stt.classList.add('dirty');var tx=stt.querySelector('.cmp-rd-status-txt');if(tx){tx.textContent='<?php echo esc_js( __( 'Unsaved changes', 'copyright-maker-pro' ) ); ?>';}});}document.body.setAttribute('data-cmp-redesigned','1');}
				$(function(){var colorPickerOptions={defaultColor:false,palettes:true,change:function(){setTimeout(updatePreview,50);},clear:function(){setTimeout(updatePreview,50);}};$('.cmp-color-field').each(function(){var $field=$(this);$field.wpColorPicker(colorPickerOptions);var $container=$field.closest('.wp-picker-container');$container.find('.wp-color-result, .wp-picker-input-wrap, .wp-picker-holder').on('click mousedown',function(event){event.stopPropagation();});$container.find('.wp-color-result').attr('aria-label','<?php echo esc_js( __( 'Select color', 'copyright-maker-pro' ) ); ?>');});$(document).on('click','.cmp-color-style-field .wp-color-result',function(event){event.preventDefault();event.stopPropagation();var $container=$(this).closest('.wp-picker-container');$('.cmp-color-style-field .wp-picker-container').not($container).removeClass('wp-picker-active');$container.addClass('wp-picker-active');});document.querySelectorAll('[data-cmp-preview]').forEach(function(el){el.addEventListener('input',updatePreview);el.addEventListener('change',updatePreview);});$(document).on('click','[data-cmp-device]',function(){setBreakpoint(this.getAttribute('data-cmp-device')||'desktop');});$(document).on('input change','[data-cmp-preview="layout_direction"],[data-cmp-preview="layout_alignment"],[data-cmp-preview="layout_justify"],[data-cmp-preview="layout_gap"]',function(){var k=this.getAttribute('data-cmp-preview');var s=cmpStoreEl(k,previewDevice);if(s){s.value=this.value;}});var separatorSelect=document.getElementById('cmp_separator_option');var separatorInput=document.getElementById('cmp_separator');if(separatorSelect&&separatorInput){separatorSelect.addEventListener('change',function(){if(this.value==='custom'){separatorInput.value='';updatePreview();}});}var legalIndex=document.querySelectorAll('[data-legal-row]').length;function refreshLegalEmpty(){var empty=document.getElementById('cmp-legal-empty');if(empty){empty.style.display=document.querySelectorAll('[data-legal-row]').length?'none':'';}}function addLegalRow(){var list=document.getElementById('cmp-legal-links-list');if(!list){return;}var row=document.createElement('div');row.className='cmp-legal-link-row';row.setAttribute('data-legal-row','');var idx=legalIndex;var base='<?php echo esc_js( CMP_OPTION ); ?>[legal_links]['+idx+']';row.innerHTML='<div class="cmp-legal-link-field"><label for="cmp_legal_name_'+idx+'"><?php echo esc_js( __( 'Label', 'copyright-maker-pro' ) ); ?></label><input type="text" id="cmp_legal_name_'+idx+'" data-cmp-legal-name name="'+base+'[name]"></div><div class="cmp-legal-link-field"><label for="cmp_legal_url_'+idx+'"><?php echo esc_js( __( 'URL', 'copyright-maker-pro' ) ); ?></label><input type="url" id="cmp_legal_url_'+idx+'" data-cmp-legal-url name="'+base+'[url]" placeholder="https://example.com/privacy-policy"></div><label class="cmp-legal-new-tab" for="cmp_legal_new_tab_'+idx+'"><input type="checkbox" id="cmp_legal_new_tab_'+idx+'" data-cmp-legal-new-tab aria-label="<?php echo esc_js( __( 'Open this legal link in a new browser tab', 'copyright-maker-pro' ) ); ?>" name="'+base+'[new_tab]" value="1"> <?php echo esc_js( __( 'New Tab', 'copyright-maker-pro' ) ); ?> <span class="cmp-tooltip" tabindex="0" data-tooltip="<?php echo esc_js( __( 'Opens this link in a new browser tab.', 'copyright-maker-pro' ) ); ?>">i</span></label><button type="button" class="cmp-delete-legal-link" aria-label="<?php echo esc_js( __( 'Delete legal link', 'copyright-maker-pro' ) ); ?>"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M7 2h6l1 2h4v2H2V4h4l1-2zm1 6h2v8H8V8zm4 0h2v8h-2V8zM5 8h2v8H5V8z"/></svg></button>';list.appendChild(row);legalIndex++;refreshLegalEmpty();updatePreview();}$(document).on('click','#cmp-add-legal-link',addLegalRow);$(document).on('click','.cmp-delete-legal-link',function(){var row=this.closest('[data-legal-row]');if(!row){return;}if(row.classList.contains('is-confirming-delete')){row.remove();refreshLegalEmpty();updatePreview();return;}row.classList.add('is-confirming-delete');var msg=document.createElement('span');msg.className='cmp-delete-confirm';msg.textContent='<?php echo esc_js( __( 'Are you sure? Click trash again to delete.', 'copyright-maker-pro' ) ); ?>';row.appendChild(msg);setTimeout(function(){if(row&&row.parentNode){row.classList.remove('is-confirming-delete');if(msg.parentNode){msg.parentNode.removeChild(msg);}}},3500);});$(document).on('input change','[data-cmp-legal-name],[data-cmp-legal-url],[data-cmp-legal-new-tab]',updatePreview);$('#cmp-settings-form').on('submit',function(){saveLayoutToStore(previewDevice);try{sessionStorage.setItem('CMP_saved','1');}catch(e){}showToast('<?php echo esc_js( __( 'Saving settings...', 'copyright-maker-pro' ) ); ?>', 'success');});try{if(sessionStorage.getItem('CMP_saved')==='1'){sessionStorage.removeItem('CMP_saved');if($('.notice-error,.error').length){showToast('<?php echo esc_js( __( 'Settings could not be saved. Please review the page.', 'copyright-maker-pro' ) ); ?>', 'error');}else{showToast('<?php echo esc_js( __( 'Settings saved successfully.', 'copyright-maker-pro' ) ); ?>', 'success');}}}catch(e){}$('#cmp-copy-shortcode').on('click',copyShortcode);refreshLegalEmpty();updatePreview();cmpApplyRedesign();});
			})(jQuery);
			</script>
			<script>
			(function(){
				var copy=document.getElementById('cmp-hero-copy');
				if(copy){copy.addEventListener('click',function(){
					var sc=copy.getAttribute('data-cmp-copy')||'[cmp_copyright]';
					var label=copy.querySelector('.cmp-hero-chip-label');
					var done=function(){if(label){var t=label.textContent;label.textContent='<?php echo esc_js( __( 'Copied!', 'copyright-maker-pro' ) ); ?>';setTimeout(function(){label.textContent=t;},1300);}};
					if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(sc).then(done,done);}else{done();}
				});}
				var tips=document.getElementById('cmp-tips');
				var close=document.getElementById('cmp-tips-close');
				if(tips&&close){close.addEventListener('click',function(){
					tips.parentNode&&tips.parentNode.removeChild(tips);
					var nonce=tips.getAttribute('data-cmp-tips-nonce')||'';
					try{
						if(window.fetch&&typeof ajaxurl!=='undefined'){
							fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=cmp_dismiss_tips&nonce='+encodeURIComponent(nonce)});
						}
					}catch(e){}
				});}
			})();
			</script>
		</div>
		<?php
	}
}

register_activation_hook( CMP_FILE, array( 'CMP_Plugin', 'activate' ) );
register_uninstall_hook( CMP_FILE, array( 'CMP_Plugin', 'uninstall' ) );
CMP_Plugin::instance();
