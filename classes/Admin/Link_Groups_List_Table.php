<?php
/**
 * The native WP_List_Table that lists link groups under Tools → Autolink, with
 * the columns Phrases · URL · Group cap. The Phrases cell is primary: it opens
 * the edit modal and reveals the Edit | Delete row actions on hover. The actual
 * add/edit/delete go through the REST API; this table only renders.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink\Admin;

use Kntnt\Autolink\Link_Group;
use Kntnt\Autolink\Link_Group_Query;
use Kntnt\Autolink\Link_Group_Repository;

final class Link_Groups_List_Table extends \WP_List_Table {

	/**
	 * @since 1.1.0
	 */
	public function __construct( private readonly Link_Group_Repository $groups ) {
		parent::__construct( [
			'singular' => 'link-group',
			'plural' => 'link-groups',
			'screen' => 'kntnt-autolink',
			'ajax' => false,
		] );
	}

	/**
	 * The columns: a selection checkbox · Phrases (primary) · URL · Group cap. The
	 * checkbox column drives row selection and the header select-all for bulk
	 * actions.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return [
			'cb' => '<input type="checkbox" />',
			'phrases' => __( 'Phrases', 'kntnt-autolink' ),
			'url' => __( 'URL', 'kntnt-autolink' ),
			'cap' => __( 'Group cap', 'kntnt-autolink' ),
		];
	}

	/**
	 * The bulk actions offered above and below the table: delete the selected link
	 * groups, or set the group cap on all of them at once. Public so the unit
	 * suite can pin the contract without rendering the whole table.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions() {
		return [
			'delete' => __( 'Delete', 'kntnt-autolink' ),
			'set-cap' => __( 'Set group cap…', 'kntnt-autolink' ),
		];
	}

	/**
	 * The sortable columns: Phrases (by its first phrase) and Group cap. The URL
	 * column is not sortable. Both sort ascending on the first click.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns() {
		return [
			'phrases' => [ 'phrases', false ],
			'cap' => [ 'cap', false ],
		];
	}

	/**
	 * Build the current view from the request: search, sort and page come from the
	 * native list-table query parameters.
	 *
	 * @since 1.1.0
	 */
	public function prepare_items(): void {
		$this->prepare_for( $this->query_from_request() );
	}

	/**
	 * Build the current view from an explicit query. The REST "render rows" route
	 * uses this with the query it reconstructs from the request, so the table that
	 * re-renders matches the one the user is looking at.
	 *
	 * @since 1.1.0
	 */
	public function prepare_for( Link_Group_Query $query ): void {

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns(), 'phrases' ];

		// Query all groups through the in-memory layer, then page the table and
		// record the full match count so the native pagination controls are right.
		$results = $query->results( $this->groups->all() );
		$this->items = $results['items'];
		$this->set_pagination_args( [
			'total_items' => $results['total'],
			'per_page' => $query->per_page,
		] );

	}

	/**
	 * The query the current request describes, with every parameter sanitised and
	 * the page size taken from the per-page filter.
	 *
	 * @since 1.1.0
	 */
	private function query_from_request(): Link_Group_Query {
		return new Link_Group_Query(
			search: $this->request_param( 's' ),
			orderby: $this->request_param( 'orderby' ),
			order: $this->request_param( 'order' ),
			page: max( 1, absint( $this->request_param( 'paged' ) ) ),
			per_page: $this->per_page(),
		);
	}

	/**
	 * A single request parameter, unslashed and sanitised; empty when absent or
	 * not a string. Reading these GET filters needs no nonce — they only narrow a
	 * read-only listing, and every mutation is gated separately over REST.
	 *
	 * @since 1.1.0
	 */
	private function request_param( string $key ): string {
		if ( ! isset( $_REQUEST[ $key ] ) || ! is_string( $_REQUEST[ $key ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
	}

	/**
	 * The page size, filterable so a site can tune how many groups a page shows. A
	 * filter that returns a non-numeric value falls back to the default rather than
	 * coercing to zero and breaking pagination.
	 *
	 * @since 1.1.0
	 */
	private function per_page(): int {
		$per_page = apply_filters( Link_Group_Query::PER_PAGE_FILTER, Link_Group_Query::DEFAULT_PER_PAGE );
		return max( 1, is_numeric( $per_page ) ? (int) $per_page : Link_Group_Query::DEFAULT_PER_PAGE );
	}

	/**
	 * The primary column, whose cell carries the row actions.
	 *
	 * @since 1.1.0
	 */
	protected function get_primary_column_name() {
		return 'phrases';
	}

	/**
	 * The empty-state message.
	 *
	 * @since 1.1.0
	 */
	public function no_items(): void {
		esc_html_e( 'No link groups yet. Use “Add link group” to create one.', 'kntnt-autolink' );
	}

	/**
	 * Render one <tr> per link group, escaping every value.
	 *
	 * @since 1.1.0
	 */
	public function display_rows(): void {
		foreach ( $this->items as $item ) {
			if ( $item instanceof Link_Group ) {
				$this->render_row( $item );
			}
		}
	}

	/**
	 * The current table body HTML, for the REST "render rows" route.
	 *
	 * @since 1.1.0
	 */
	public function rows_html(): string {
		ob_start();
		$this->display_rows_or_placeholder();
		return (string) ob_get_clean();
	}

	/**
	 * Render a single link group's row. The Phrases cell holds a button that
	 * opens the edit modal (its data-* attributes seed the form) plus the
	 * Edit | Delete row actions.
	 *
	 * @since 1.1.0
	 */
	private function render_row( Link_Group $group ): void {

		$data = sprintf(
			' data-id="%s" data-phrases="%s" data-url="%s" data-cap="%d" data-nofollow="%d" data-new-tab="%d"',
			esc_attr( $group->id ),
			esc_attr( implode( "\n", $group->phrases ) ),
			esc_attr( $group->url ),
			(int) $group->cap,
			$group->nofollow ? 1 : 0,
			$group->new_tab ? 1 : 0,
		);

		$label = implode( ', ', $group->phrases );

		$actions = [
			'edit' => '<a href="#" class="kntnt-autolink-edit"' . $data . '>' . esc_html__( 'Edit', 'kntnt-autolink' ) . '</a>',
			'delete' => '<a href="#" class="kntnt-autolink-delete submitdelete" data-id="' . esc_attr( $group->id ) . '">' . esc_html__( 'Delete', 'kntnt-autolink' ) . '</a>',
		];

		echo '<tr>';
		echo '<th scope="row" class="check-column">';
		echo '<label class="screen-reader-text" for="kntnt-autolink-cb-' . esc_attr( $group->id ) . '">' . esc_html__( 'Select this link group', 'kntnt-autolink' ) . '</label>';
		echo '<input id="kntnt-autolink-cb-' . esc_attr( $group->id ) . '" type="checkbox" name="ids[]" value="' . esc_attr( $group->id ) . '">';
		echo '</th>';
		echo '<td class="phrases column-phrases has-row-actions column-primary" data-colname="' . esc_attr__( 'Phrases', 'kntnt-autolink' ) . '">';
		echo '<button type="button" class="button-link row-title kntnt-autolink-edit"' . $data . '>';
		echo $label !== '' ? esc_html( $label ) : esc_html__( '(no phrases)', 'kntnt-autolink' );
		echo '</button>';

		// Emit the row actions directly rather than through wp_kses_post(): every
		// value was escaped at construction (esc_attr / esc_html__) so the markup is
		// already safe, whereas kses would strip the data-* attributes the admin JS
		// reads to seed the edit modal and to address DELETE /link-groups/{id}.
		echo $this->row_actions( $actions );

		echo '<button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__( 'Show more details', 'kntnt-autolink' ) . '</span></button>';
		echo '</td>';
		echo '<td class="url column-url" data-colname="' . esc_attr__( 'URL', 'kntnt-autolink' ) . '">' . esc_html( $group->url ) . '</td>';
		echo '<td class="cap column-cap" data-colname="' . esc_attr__( 'Group cap', 'kntnt-autolink' ) . '">' . (int) $group->cap . '</td>';
		echo '</tr>';

	}

}
