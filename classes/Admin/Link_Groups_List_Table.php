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
	 * The columns: Phrases (primary) · URL · Group cap.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return [
			'phrases' => __( 'Phrases', 'kntnt-autolink' ),
			'url' => __( 'URL', 'kntnt-autolink' ),
			'cap' => __( 'Group cap', 'kntnt-autolink' ),
		];
	}

	/**
	 * Load every link group into the table; this issue ships no search, sort or
	 * pagination, so the whole list is the current view.
	 *
	 * @since 1.1.0
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [], 'phrases' ];
		$this->items = $this->groups->all();
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
