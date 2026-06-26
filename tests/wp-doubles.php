<?php
/**
 * Minimal runtime doubles for the WordPress REST classes the unit suite touches.
 *
 * Pest runs without a WordPress bootstrap, so the real WP_REST_Request /
 * WP_REST_Response / WP_Error classes are absent at test runtime (PHPStan sees
 * the official stubs instead). These lightweight stand-ins let the REST
 * controller be unit-tested in isolation: the controller reads request params,
 * returns a response object, and signals errors with a WP_Error — and these
 * doubles model exactly that surface, nothing more.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WP_REST_Request' ) ) {

	/**
	 * Test double for WP_REST_Request: a bag of named parameters.
	 */
	class WP_REST_Request {

		/**
		 * @param array<string, mixed> $params
		 */
		public function __construct( private array $params = [] ) {}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

	}

}

if ( ! class_exists( 'WP_REST_Response' ) ) {

	/**
	 * Test double for WP_REST_Response: data plus an HTTP status.
	 */
	class WP_REST_Response {

		public function __construct( public mixed $data = null, public int $status = 200 ) {}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

	}

}

if ( ! class_exists( 'WP_Error' ) ) {

	/**
	 * Test double for WP_Error: code, message, and a data bag carrying status.
	 */
	class WP_Error {

		/**
		 * @param array<string, mixed> $data
		 */
		public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_error_data(): array {
			return $this->data;
		}

	}

}

if ( ! class_exists( 'WP_List_Table' ) ) {

	/**
	 * Test double for WP_List_Table: just enough of the base class for the
	 * link-group table to render rows in isolation — the items/column-header
	 * properties, the has-items/placeholder branch the "render rows" route walks,
	 * and a minimal row_actions() that reproduces core's hover-actions markup.
	 */
	class WP_List_Table {

		/** @var array<array-key, mixed> */
		public $items = [];

		/** @var array<array-key, mixed> */
		protected $_column_headers = [];

		/** @var array<string, mixed> */
		protected $_pagination_args = [];

		/**
		 * @param array<string, mixed> $args
		 */
		public function __construct( $args = [] ) {}

		public function has_items(): bool {
			return $this->items !== [];
		}

		/**
		 * @param array<string, mixed> $args
		 */
		public function set_pagination_args( $args ): void {
			$this->_pagination_args = $args;
		}

		public function get_pagination_arg( string $key ): mixed {
			return $this->_pagination_args[ $key ] ?? 0;
		}

		public function display_rows_or_placeholder(): void {
			if ( $this->has_items() ) {
				$this->display_rows();
			} else {
				echo '<tr class="no-items"><td class="colspanchange">';
				$this->no_items();
				echo '</td></tr>';
			}
		}

		/**
		 * @param array<string, string> $actions
		 */
		protected function row_actions( $actions, $always_visible = false ): string {
			$out = '<div class="row-actions">';
			$keys = array_keys( $actions );
			$last = $keys === [] ? null : end( $keys );
			foreach ( $actions as $action => $link ) {
				$separator = $action === $last ? '' : ' | ';
				$out .= '<span class="' . $action . '">' . $link . $separator . '</span>';
			}
			$out .= '</div>';
			return $out;
		}

	}

}
