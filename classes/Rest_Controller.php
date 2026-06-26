<?php
/**
 * The REST API for link-group management, under the kntnt-autolink/v1 namespace.
 * Create, update, delete, and a "render rows" route — each gated, capability
 * first, by the manage-link-groups capability (the X-WP-Nonce is enforced by
 * core REST authentication). On every change the table body is re-rendered
 * server-side and returned as HTML, so there is no second row renderer in JS.
 *
 * Sanitises every input and never trusts the request; the repository sanitises
 * again on write (defence in depth).
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Rest_Controller {

	/** @since 1.1.0 */
	private const REST_NAMESPACE = 'kntnt-autolink/v1';

	/** @since 1.1.0 */
	private const ROUTE = 'link-groups';

	/**
	 * @since 1.1.0
	 *
	 * @param Link_Group_Repository $groups      Persistence for link groups.
	 * @param \Closure              $render_rows fn(): string returning the current table body HTML.
	 */
	public function __construct(
		private readonly Link_Group_Repository $groups,
		private readonly \Closure $render_rows,
	) {}

	/**
	 * Register the REST routes on rest_api_init.
	 *
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', $this->register_routes( ... ) );
	}

	/**
	 * Register create / rows / update / delete on the link-groups namespace.
	 *
	 * @since 1.1.0
	 */
	public function register_routes(): void {

		register_rest_route( self::REST_NAMESPACE, '/' . self::ROUTE, [
			[
				'methods' => 'POST',
				'callback' => $this->create( ... ),
				'permission_callback' => $this->can_manage( ... ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/' . self::ROUTE . '/rows', [
			[
				'methods' => 'GET',
				'callback' => $this->rows( ... ),
				'permission_callback' => $this->can_manage( ... ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/' . self::ROUTE . '/(?P<id>[A-Za-z0-9_\-]+)', [
			[
				'methods' => 'POST, PUT, PATCH',
				'callback' => $this->update( ... ),
				'permission_callback' => $this->can_manage( ... ),
			],
			[
				'methods' => 'DELETE',
				'callback' => $this->delete( ... ),
				'permission_callback' => $this->can_manage( ... ),
			],
		] );

	}

	/**
	 * Whether the current user may manage link groups. The permission callback
	 * for every mutating route — capability is checked before any work is done.
	 *
	 * @since 1.1.0
	 */
	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE_LINK_GROUPS );
	}

	/**
	 * Create a link group from the request, then re-render the table body.
	 *
	 * @since 1.1.0
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$phrases = $this->parse_phrases( $request->get_param( 'phrases' ) );
		$url = esc_url_raw( $this->to_string( $request->get_param( 'url' ) ) );
		if ( $phrases === [] || $url === '' ) {
			return $this->invalid();
		}
		$this->groups->save( $this->group_from( '', $request, $phrases, $url ) );
		return $this->rows_response();
	}

	/**
	 * Update the link group named by the route id, then re-render the table body.
	 *
	 * @since 1.1.0
	 */
	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = sanitize_key( $this->to_string( $request->get_param( 'id' ) ) );
		if ( $id === '' ) {
			return $this->invalid();
		}

		// A PUT/PATCH targets an existing group; a missing id is a 404, never a
		// silent create — that is the POST collection route's job.
		if ( $this->groups->find( $id ) === null ) {
			return $this->not_found();
		}

		$phrases = $this->parse_phrases( $request->get_param( 'phrases' ) );
		$url = esc_url_raw( $this->to_string( $request->get_param( 'url' ) ) );
		if ( $phrases === [] || $url === '' ) {
			return $this->invalid();
		}
		$this->groups->save( $this->group_from( $id, $request, $phrases, $url ) );
		return $this->rows_response();
	}

	/**
	 * Delete the link group named by the route id, then re-render the table body.
	 *
	 * @since 1.1.0
	 */
	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = sanitize_key( $this->to_string( $request->get_param( 'id' ) ) );
		if ( $id === '' ) {
			return $this->invalid();
		}
		$this->groups->delete( $id );
		return $this->rows_response();
	}

	/**
	 * Return the current table body HTML for the rows route.
	 *
	 * @since 1.1.0
	 */
	public function rows( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->rows_response();
	}

	/**
	 * Build a Link_Group from the request's sanitised fields.
	 *
	 * @since 1.1.0
	 *
	 * @param list<string> $phrases
	 */
	private function group_from( string $id, \WP_REST_Request $request, array $phrases, string $url ): Link_Group {
		return new Link_Group(
			id: $id,
			phrases: $phrases,
			url: $url,
			cap: max( 1, absint( $this->to_string( $request->get_param( 'cap' ) ) ) ),
			nofollow: $this->to_bool( $request->get_param( 'nofollow' ) ),
			new_tab: $this->to_bool( $request->get_param( 'new_tab' ) ),
		);
	}

	/**
	 * A 200 response carrying the freshly re-rendered table body.
	 *
	 * @since 1.1.0
	 */
	private function rows_response(): \WP_REST_Response {
		return new \WP_REST_Response( [ 'rows' => ( $this->render_rows )() ], 200 );
	}

	/**
	 * The error returned when a group lacks a phrase or a URL.
	 *
	 * @since 1.1.0
	 */
	private function invalid(): \WP_Error {
		return new \WP_Error(
			'rest_invalid_param',
			__( 'A link group needs at least one phrase and a destination URL.', 'kntnt-autolink' ),
			[ 'status' => 400 ],
		);
	}

	/**
	 * The error returned when an update targets an id that does not exist.
	 *
	 * @since 1.1.0
	 */
	private function not_found(): \WP_Error {
		return new \WP_Error(
			'rest_link_group_not_found',
			__( 'No link group has that id.', 'kntnt-autolink' ),
			[ 'status' => 404 ],
		);
	}

	/**
	 * Parse phrases from either an array or a newline-separated string, sanitising
	 * each and dropping empties.
	 *
	 * @since 1.1.0
	 *
	 * @return list<string>
	 */
	private function parse_phrases( mixed $value ): array {
		$items = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', $this->to_string( $value ) );
		if ( ! is_array( $items ) ) {
			return [];
		}
		$result = [];
		foreach ( $items as $item ) {
			$clean = sanitize_text_field( $this->to_string( $item ) );
			if ( $clean !== '' ) {
				$result[] = $clean;
			}
		}
		return $result;
	}

	/**
	 * Coerce a request value into a boolean without a WordPress call.
	 *
	 * @since 1.1.0
	 */
	private function to_bool( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), [ '1', 'true', 'yes', 'on' ], true );
		}
		return (bool) $value;
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
