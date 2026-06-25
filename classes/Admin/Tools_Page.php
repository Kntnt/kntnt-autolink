<?php
/**
 * The admin page under Tools: keyword CRUD for editors-and-above, and an
 * admin-only structural-rules section. This is the plugin's main security
 * boundary — every mutating action checks the capability first, then the nonce,
 * sanitises every superglobal, and escapes every output.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink\Admin;

use Kntnt\Autolink\Capabilities;
use Kntnt\Autolink\Keyword;
use Kntnt\Autolink\Keyword_Repository;
use Kntnt\Autolink\Settings_Repository;

final class Tools_Page {

	/** @since 1.0.0 */
	private const SLUG = 'kntnt-autolink';

	/**
	 * @since 1.0.0
	 */
	public function __construct(
		private readonly Settings_Repository $settings,
		private readonly Keyword_Repository $keywords,
	) {}

	/**
	 * Register the admin menu entry and the admin-post action handlers.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', $this->add_page( ... ) );
		add_action( 'admin_post_kntnt_autolink_save_keyword', $this->handle_save_keyword( ... ) );
		add_action( 'admin_post_kntnt_autolink_delete_keyword', $this->handle_delete_keyword( ... ) );
		add_action( 'admin_post_kntnt_autolink_save_settings', $this->handle_save_settings( ... ) );
	}

	/**
	 * Add the page under Tools, gated by the keyword-management capability.
	 *
	 * @since 1.0.0
	 */
	public function add_page(): void {
		add_management_page(
			__( 'Autolink', 'kntnt-autolink' ),
			__( 'Autolink', 'kntnt-autolink' ),
			Capabilities::MANAGE_KEYWORDS,
			self::SLUG,
			$this->render( ... ),
		);
	}

	/**
	 * Render the page: the keyword table always, the structural rules only for
	 * administrators.
	 *
	 * @since 1.0.0
	 */
	public function render(): void {

		if ( ! current_user_can( Capabilities::MANAGE_KEYWORDS ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'kntnt-autolink' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Autolink', 'kntnt-autolink' ) . '</h1>';

		$this->render_keywords();

		// The structural rules can break matching with a bad XPath, so they stay
		// administrator-only and are absent entirely for lower-privileged editors.
		if ( current_user_can( 'manage_options' ) ) {
			$this->render_settings();
		}

		echo '</div>';

	}

	/**
	 * Persist a keyword from the admin form. Editor-and-above only.
	 *
	 * @since 1.0.0
	 */
	public function handle_save_keyword(): void {

		// Authorisation first: capability, then CSRF nonce. A nonce is not authz.
		if ( ! current_user_can( Capabilities::MANAGE_KEYWORDS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage keywords.', 'kntnt-autolink' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'kntnt_autolink_save_keyword' );

		// Sanitise every field before building the value object.
		$id = isset( $_POST['id'] ) && is_string( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$base = isset( $_POST['base'] ) && is_string( $_POST['base'] ) ? sanitize_text_field( wp_unslash( $_POST['base'] ) ) : '';
		$variants = isset( $_POST['variants'] ) && is_string( $_POST['variants'] ) ? $this->parse_list( wp_unslash( $_POST['variants'] ) ) : [];
		$url = isset( $_POST['url'] ) && is_string( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$max = isset( $_POST['max'] ) && is_string( $_POST['max'] ) ? max( 1, absint( wp_unslash( $_POST['max'] ) ) ) : 1;

		// Persist via the repository (which sanitises again — defence in depth).
		if ( $base !== '' && $url !== '' ) {
			$this->keywords->save( new Keyword(
				id: $id,
				base: $base,
				variants: $variants,
				url: $url,
				max: $max,
			) );
		}

		$this->redirect();

	}

	/**
	 * Delete a keyword from the admin form. Editor-and-above only.
	 *
	 * @since 1.0.0
	 */
	public function handle_delete_keyword(): void {

		if ( ! current_user_can( Capabilities::MANAGE_KEYWORDS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage keywords.', 'kntnt-autolink' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'kntnt_autolink_delete_keyword' );

		$id = isset( $_POST['id'] ) && is_string( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		if ( $id !== '' ) {
			$this->keywords->delete( $id );
		}

		$this->redirect();

	}

	/**
	 * Persist the structural rules. Administrators only — the raw-XPath fields
	 * are read here and nowhere a lower-privileged user can reach.
	 *
	 * @since 1.0.0
	 */
	public function handle_save_settings(): void {

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
			'nofollow' => ! empty( $_POST['nofollow'] ),
			'new_tab' => ! empty( $_POST['new_tab'] ),
			'max_links_per_post' => isset( $_POST['max_links_per_post'] ) && is_string( $_POST['max_links_per_post'] ) ? absint( wp_unslash( $_POST['max_links_per_post'] ) ) : 10,
			'post_types' => isset( $_POST['post_types'] ) && is_string( $_POST['post_types'] ) ? $this->parse_list( wp_unslash( $_POST['post_types'] ) ) : [],
			'terms' => isset( $_POST['terms'] ) && is_string( $_POST['terms'] ) ? $this->parse_terms( wp_unslash( $_POST['terms'] ) ) : [],
		];
		$this->settings->save_settings( $input );

		$this->redirect();

	}

	/**
	 * Render the keyword table: one save form and one delete form per keyword,
	 * plus an empty add row.
	 *
	 * @since 1.0.0
	 */
	private function render_keywords(): void {

		$action_url = esc_url( admin_url( 'admin-post.php' ) );

		echo '<h2>' . esc_html__( 'Keywords', 'kntnt-autolink' ) . '</h2>';
		echo '<p>' . esc_html__( 'The base form is the canonical surface form; variants are additional equal-weight forms, one per line or comma-separated. All forms link to the same URL.', 'kntnt-autolink' ) . '</p>';

		foreach ( $this->keywords->all() as $keyword ) {
			$this->render_keyword_form( $action_url, $keyword );
		}

		echo '<h3>' . esc_html__( 'Add keyword', 'kntnt-autolink' ) . '</h3>';
		$this->render_keyword_form( $action_url, null );

	}

	/**
	 * Render one keyword's save form (and, for an existing keyword, a delete form).
	 *
	 * @since 1.0.0
	 */
	private function render_keyword_form( string $action_url, ?Keyword $keyword ): void {

		$id = $keyword->id ?? '';
		$base = $keyword->base ?? '';
		$variants = $keyword !== null ? implode( "\n", $keyword->variants ) : '';
		$url = $keyword->url ?? '';
		$max = (string) ( $keyword->max ?? 1 );

		echo '<form method="post" action="' . $action_url . '" style="margin:0 0 1em;padding:1em;border:1px solid #c3c4c7;background:#fff;">';
		echo '<input type="hidden" name="action" value="kntnt_autolink_save_keyword">';
		echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'kntnt_autolink_save_keyword' );

		echo '<p><label>' . esc_html__( 'Base form', 'kntnt-autolink' ) . '<br><input type="text" name="base" class="regular-text" value="' . esc_attr( $base ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Variants', 'kntnt-autolink' ) . '<br><textarea name="variants" rows="3" class="large-text">' . esc_textarea( $variants ) . '</textarea></label></p>';
		echo '<p><label>' . esc_html__( 'URL', 'kntnt-autolink' ) . '<br><input type="url" name="url" class="regular-text" value="' . esc_attr( $url ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Max links per post', 'kntnt-autolink' ) . '<br><input type="number" name="max" min="1" value="' . esc_attr( $max ) . '"></label></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save keyword', 'kntnt-autolink' ) . '</button></p>';
		echo '</form>';

		if ( $keyword !== null ) {
			echo '<form method="post" action="' . $action_url . '" style="margin:-1em 0 1em;">';
			echo '<input type="hidden" name="action" value="kntnt_autolink_delete_keyword">';
			echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'kntnt_autolink_delete_keyword' );
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Delete', 'kntnt-autolink' ) . '</button>';
			echo '</form>';
		}

	}

	/**
	 * Render the administrator-only structural rules form.
	 *
	 * @since 1.0.0
	 */
	private function render_settings(): void {

		$action_url = esc_url( admin_url( 'admin-post.php' ) );
		$s = $this->settings->get_settings();

		echo '<hr><h2>' . esc_html__( 'Structural rules (administrators only)', 'kntnt-autolink' ) . '</h2>';
		echo '<form method="post" action="' . $action_url . '">';
		echo '<input type="hidden" name="action" value="kntnt_autolink_save_settings">';
		wp_nonce_field( 'kntnt_autolink_save_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_text_row( 'deny_tags', esc_html__( 'Deny tags', 'kntnt-autolink' ), $this->list_text( $s['deny_tags'] ?? [] ) );
		$this->render_text_row( 'skip_class', esc_html__( 'Skip class', 'kntnt-autolink' ), $this->text( $s['skip_class'] ?? '' ) );
		$this->render_text_row( 'deny_xpath', esc_html__( 'Deny XPath', 'kntnt-autolink' ), $this->text( $s['deny_xpath'] ?? '' ) );
		$this->render_text_row( 'allow_only_xpath', esc_html__( 'Allow-only XPath', 'kntnt-autolink' ), $this->text( $s['allow_only_xpath'] ?? '' ) );
		$this->render_text_row( 'link_class', esc_html__( 'Link class', 'kntnt-autolink' ), $this->text( $s['link_class'] ?? '' ) );
		$this->render_checkbox_row( 'nofollow', esc_html__( 'Add rel="nofollow"', 'kntnt-autolink' ), ! empty( $s['nofollow'] ) );
		$this->render_checkbox_row( 'new_tab', esc_html__( 'Open in a new tab', 'kntnt-autolink' ), ! empty( $s['new_tab'] ) );
		$this->render_text_row( 'max_links_per_post', esc_html__( 'Max links per post', 'kntnt-autolink' ), $this->text( $s['max_links_per_post'] ?? 10 ) );
		$this->render_text_row( 'post_types', esc_html__( 'Post types', 'kntnt-autolink' ), $this->list_text( $s['post_types'] ?? [] ) );
		$this->render_text_row( 'terms', esc_html__( 'Terms (one "taxonomy: id, id" per line)', 'kntnt-autolink' ), $this->terms_text( $s['terms'] ?? [] ) );

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'kntnt-autolink' ) . '</button></p>';
		echo '</form>';

	}

	/**
	 * Render a labelled text input row.
	 *
	 * @since 1.0.0
	 */
	private function render_text_row( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="kntnt-autolink-' . esc_attr( $name ) . '">' . $label . '</label></th>';
		echo '<td><input type="text" class="regular-text" id="kntnt-autolink-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></td></tr>';
	}

	/**
	 * Render a labelled checkbox row.
	 *
	 * @since 1.0.0
	 */
	private function render_checkbox_row( string $name, string $label, bool $checked ): void {
		echo '<tr><th scope="row">' . $label . '</th>';
		echo '<td><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . '></td></tr>';
	}

	/**
	 * Redirect back to the page (Post/Redirect/Get).
	 *
	 * @since 1.0.0
	 */
	private function redirect(): void {
		wp_safe_redirect( add_query_arg( 'page', self::SLUG, admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Split a newline/comma-separated string into a sanitised list of strings.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 */
	private function sanitize_xpath( string $xpath ): string {
		return trim( $xpath );
	}

	/**
	 * Coerce a scalar value to a string for display.
	 *
	 * @since 1.0.0
	 */
	private function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Render a list of strings as a comma-separated string for display.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
