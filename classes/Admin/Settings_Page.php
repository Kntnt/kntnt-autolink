<?php
/**
 * The structural-rules page under Settings → Autolink — administrators only.
 * This realises the menu split (ADR-0002): editor-facing link-group management
 * lives under Tools, while the admin-only structural rules live here. A plain
 * functional form is intentional for this issue; the redesign is a later one.
 *
 * Every mutation checks the capability first, then the nonce, sanitises every
 * superglobal, and escapes every output. The raw-XPath fields are read only in
 * this manage_options-gated handler.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink\Admin;

use Kntnt\Autolink\Settings_Repository;

final class Settings_Page {

	/** @since 1.1.0 */
	private const SLUG = 'kntnt-autolink';

	/**
	 * @since 1.1.0
	 */
	public function __construct(
		private readonly Settings_Repository $settings,
	) {}

	/**
	 * Register the Settings menu entry and the admin-post handler.
	 *
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', $this->add_page( ... ) );
		add_action( 'admin_post_kntnt_autolink_save_settings', $this->handle_save_settings( ... ) );
	}

	/**
	 * Add the page under Settings, administrators only.
	 *
	 * @since 1.1.0
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Autolink', 'kntnt-autolink' ),
			__( 'Autolink', 'kntnt-autolink' ),
			'manage_options',
			self::SLUG,
			$this->render( ... ),
		);
	}

	/**
	 * Render the structural-rules form.
	 *
	 * @since 1.1.0
	 */
	public function render(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'kntnt-autolink' ) );
		}

		$action_url = esc_url( admin_url( 'admin-post.php' ) );
		$s = $this->settings->get_settings();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Autolink — structural rules', 'kntnt-autolink' ) . '</h1>';
		echo '<form method="post" action="' . $action_url . '">';
		echo '<input type="hidden" name="action" value="kntnt_autolink_save_settings">';
		wp_nonce_field( 'kntnt_autolink_save_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_text_row( 'deny_tags', esc_html__( 'Deny tags', 'kntnt-autolink' ), $this->list_text( $s['deny_tags'] ?? [] ) );
		$this->render_text_row( 'skip_class', esc_html__( 'Skip class', 'kntnt-autolink' ), $this->text( $s['skip_class'] ?? '' ) );
		$this->render_text_row( 'deny_xpath', esc_html__( 'Deny XPath', 'kntnt-autolink' ), $this->text( $s['deny_xpath'] ?? '' ) );
		$this->render_text_row( 'allow_only_xpath', esc_html__( 'Allow-only XPath', 'kntnt-autolink' ), $this->text( $s['allow_only_xpath'] ?? '' ) );
		$this->render_text_row( 'link_class', esc_html__( 'Link class', 'kntnt-autolink' ), $this->text( $s['link_class'] ?? '' ) );
		$this->render_text_row( 'max_links_per_post', esc_html__( 'Post cap', 'kntnt-autolink' ), $this->text( $s['max_links_per_post'] ?? 10 ) );
		$this->render_text_row( 'post_types', esc_html__( 'Post types', 'kntnt-autolink' ), $this->list_text( $s['post_types'] ?? [] ) );
		$this->render_text_row( 'terms', esc_html__( 'Terms (one "taxonomy: id, id" per line)', 'kntnt-autolink' ), $this->terms_text( $s['terms'] ?? [] ) );

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'kntnt-autolink' ) . '</button></p>';
		echo '</form>';
		echo '</div>';

	}

	/**
	 * Persist the structural rules. Administrators only — the raw-XPath fields
	 * are read here and nowhere a lower-privileged user can reach.
	 *
	 * @since 1.1.0
	 */
	public function handle_save_settings(): void {

		// Authorisation first: capability, then CSRF nonce. A nonce is not authz.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'kntnt-autolink' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'kntnt_autolink_save_settings' );

		// XPath is admin-only and stored verbatim (only trimmed) — never
		// tag-stripped, which would corrupt valid expressions like [position() < 3].
		$input = [
			'deny_tags' => isset( $_POST['deny_tags'] ) && is_string( $_POST['deny_tags'] ) ? $this->parse_list( wp_unslash( $_POST['deny_tags'] ) ) : [],
			'skip_class' => isset( $_POST['skip_class'] ) && is_string( $_POST['skip_class'] ) ? sanitize_text_field( wp_unslash( $_POST['skip_class'] ) ) : '',
			'deny_xpath' => isset( $_POST['deny_xpath'] ) && is_string( $_POST['deny_xpath'] ) ? $this->sanitize_xpath( wp_unslash( $_POST['deny_xpath'] ) ) : '',
			'allow_only_xpath' => isset( $_POST['allow_only_xpath'] ) && is_string( $_POST['allow_only_xpath'] ) ? $this->sanitize_xpath( wp_unslash( $_POST['allow_only_xpath'] ) ) : '',
			'link_class' => isset( $_POST['link_class'] ) && is_string( $_POST['link_class'] ) ? sanitize_text_field( wp_unslash( $_POST['link_class'] ) ) : '',
			'max_links_per_post' => isset( $_POST['max_links_per_post'] ) && is_string( $_POST['max_links_per_post'] ) ? absint( wp_unslash( $_POST['max_links_per_post'] ) ) : 10,
			'post_types' => isset( $_POST['post_types'] ) && is_string( $_POST['post_types'] ) ? $this->parse_list( wp_unslash( $_POST['post_types'] ) ) : [],
			'terms' => isset( $_POST['terms'] ) && is_string( $_POST['terms'] ) ? $this->parse_terms( wp_unslash( $_POST['terms'] ) ) : [],
		];
		$this->settings->save_settings( $input );

		wp_safe_redirect( add_query_arg( 'page', self::SLUG, admin_url( 'options-general.php' ) ) );
		exit;

	}

	/**
	 * Render a labelled text input row.
	 *
	 * @since 1.1.0
	 */
	private function render_text_row( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="kntnt-autolink-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="text" class="regular-text" id="kntnt-autolink-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></td></tr>';
	}

	/**
	 * Split a newline/comma-separated string into a sanitised list of strings.
	 *
	 * @since 1.1.0
	 *
	 * @return list<string>
	 */
	private function parse_list( string $raw ): array {
		$parts = preg_split( '/[\r\n,]+/', $raw );
		if ( $parts === false ) {
			return [];
		}
		$result = [];
		foreach ( $parts as $part ) {
			$clean = sanitize_text_field( $part );
			if ( $clean !== '' ) {
				$result[] = $clean;
			}
		}
		return $result;
	}

	/**
	 * Parse a "taxonomy: id, id" per-line block into a targeting map.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, list<int>>
	 */
	private function parse_terms( string $raw ): array {
		$lines = preg_split( '/[\r\n]+/', $raw );
		if ( $lines === false ) {
			return [];
		}
		$result = [];
		foreach ( $lines as $line ) {
			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}
			$parts = explode( ':', $line, 2 );
			$taxonomy = sanitize_key( $parts[0] );
			if ( $taxonomy === '' ) {
				continue;
			}
			$ids = [];
			foreach ( explode( ',', $parts[1] ?? '' ) as $id ) {
				$value = absint( trim( $id ) );
				if ( $value > 0 ) {
					$ids[] = $value;
				}
			}
			if ( $ids !== [] ) {
				$result[ $taxonomy ] = $ids;
			}
		}
		return $result;
	}

	/**
	 * Trim a raw XPath expression. XPath is admin-only and stored verbatim; it is
	 * never tag-stripped or HTML-escaped, which would corrupt valid expressions.
	 *
	 * @since 1.1.0
	 */
	private function sanitize_xpath( string $xpath ): string {
		return trim( $xpath );
	}

	/**
	 * Coerce a scalar value to a string for display.
	 *
	 * @since 1.1.0
	 */
	private function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Render a list of strings as a comma-separated string for display.
	 *
	 * @since 1.1.0
	 */
	private function list_text( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return '';
		}
		$parts = [];
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$parts[] = (string) $item;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Render a targeting map as "taxonomy: id, id" lines for display.
	 *
	 * @since 1.1.0
	 */
	private function terms_text( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return '';
		}
		$lines = [];
		foreach ( $value as $taxonomy => $ids ) {
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$id_parts = [];
			foreach ( $ids as $id ) {
				if ( is_scalar( $id ) ) {
					$id_parts[] = (string) $id;
				}
			}
			$lines[] = (string) $taxonomy . ': ' . implode( ', ', $id_parts );
		}
		return implode( "\n", $lines );
	}

}
