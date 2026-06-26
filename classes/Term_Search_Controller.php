<?php
/**
 * REST term search behind the Settings → Autolink term-targeting control.
 *
 * A single GET /terms route under the kntnt-autolink/v1 namespace feeds the chip
 * widget's autocomplete: given a taxonomy and a search string it returns the
 * matching terms as id/name pairs. Term targeting is an administrator-only
 * structural rule, so the route is gated by manage_options (the X-WP-Nonce is
 * enforced by core REST authentication). Both inputs are sanitised and the
 * taxonomy is validated against the registry before any query runs.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Term_Search_Controller {

	/** @since 1.1.0 */
	private const REST_NAMESPACE = 'kntnt-autolink/v1';

	/** @since 1.1.0 */
	private const ROUTE = 'terms';

	/** The largest number of suggestions one search returns. @since 1.1.0 */
	private const LIMIT = 20;

	/**
	 * Register the REST route on rest_api_init.
	 *
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', $this->register_routes( ... ) );
	}

	/**
	 * Register the term-search route on the plugin's own namespace.
	 *
	 * @since 1.1.0
	 */
	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/' . self::ROUTE, [
			[
				'methods' => 'GET',
				'callback' => $this->search( ... ),
				'permission_callback' => $this->can_manage_settings( ... ),
			],
		] );
	}

	/**
	 * Whether the current user may configure the structural rules — the gate for
	 * term search, checked before any work is done.
	 *
	 * @since 1.1.0
	 */
	public function can_manage_settings(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return the terms of a registered taxonomy matching the search string, as a
	 * list of id/name pairs. An unknown or missing taxonomy is a 400 — never a
	 * silent empty result that would hide a misconfiguration from the caller.
	 *
	 * @since 1.1.0
	 */
	public function search( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {

		// Validate the taxonomy against the registry before touching the database.
		$taxonomy = sanitize_key( $this->to_string( $request->get_param( 'taxonomy' ) ) );
		if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Unknown taxonomy.', 'kntnt-autolink' ),
				[ 'status' => 400 ],
			);
		}

		// Query a bounded, name-ordered slice; hide_empty is off so a brand-new term
		// is still findable the moment it is created.
		$search = sanitize_text_field( $this->to_string( $request->get_param( 'search' ) ) );
		$terms = get_terms( [
			'taxonomy' => $taxonomy,
			'search' => $search,
			'hide_empty' => false,
			'number' => self::LIMIT,
			'orderby' => 'name',
		] );

		// Reduce each term to the id/name pair the chip widget needs. get_terms can
		// return a WP_Error on failure; that becomes an empty list.
		$result = [];
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$result[] = [ 'id' => $term->term_id, 'name' => $term->name ];
			}
		}

		return new \WP_REST_Response( $result, 200 );

	}

	/**
	 * Coerce a scalar value to string; non-scalars become an empty string.
	 *
	 * @since 1.1.0
	 */
	private function to_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

}
