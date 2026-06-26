<?php
/**
 * The link-group manager under Tools → Autolink: a native WP_List_Table plus an
 * Add New button and a shared <dialog> add/edit modal that saves over REST. The
 * page is gated by the manage-link-groups capability, so editors and above
 * reach it; the structural rules live on their own Settings page.
 *
 * The plugin's assets are plain css/ + js/ files (no build step), enqueued only
 * on this screen.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink\Admin;

use Kntnt\Autolink\Capabilities;
use Kntnt\Autolink\Link_Group_Repository;

final class Tools_Page {

	/** @since 1.1.0 */
	private const SLUG = 'kntnt-autolink';

	/** @since 1.1.0 */
	private ?string $hook_suffix = null;

	/**
	 * @since 1.1.0
	 *
	 * @param Link_Group_Repository $groups      Persistence for the list table.
	 * @param string                $plugin_file The main plugin file, for asset URLs.
	 * @param string                $version     Asset version, for cache-busting.
	 */
	public function __construct(
		private readonly Link_Group_Repository $groups,
		private readonly string $plugin_file,
		private readonly string $version,
	) {}

	/**
	 * Register the admin menu entry and the screen-scoped asset enqueue.
	 *
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', $this->add_page( ... ) );
		add_action( 'admin_enqueue_scripts', $this->enqueue( ... ) );
	}

	/**
	 * Add the page under Tools, gated by the manage-link-groups capability, and
	 * remember its hook suffix so assets load only here.
	 *
	 * @since 1.1.0
	 */
	public function add_page(): void {
		$this->hook_suffix = (string) add_management_page(
			__( 'Autolink', 'kntnt-autolink' ),
			__( 'Autolink', 'kntnt-autolink' ),
			Capabilities::MANAGE_LINK_GROUPS,
			self::SLUG,
			$this->render( ... ),
		);
	}

	/**
	 * Enqueue the modal stylesheet, the modal script, and its REST config — only
	 * on the Autolink screen.
	 *
	 * @since 1.1.0
	 */
	public function enqueue( string $hook_suffix ): void {

		if ( $this->hook_suffix === null || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'kntnt-autolink-admin', plugins_url( 'css/admin.css', $this->plugin_file ), [], $this->version );
		wp_enqueue_script( 'kntnt-autolink-admin', plugins_url( 'js/admin.js', $this->plugin_file ), [], $this->version, true );
		wp_localize_script( 'kntnt-autolink-admin', 'kntntAutolink', [
			'rest' => esc_url_raw( rest_url( 'kntnt-autolink/v1/link-groups' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'i18n' => [
				'addNew' => __( 'Add link group', 'kntnt-autolink' ),
				'edit' => __( 'Edit link group', 'kntnt-autolink' ),
				'confirmDelete' => __( 'Delete this link group?', 'kntnt-autolink' ),
				'firstPage' => __( 'First page', 'kntnt-autolink' ),
				'prevPage' => __( 'Previous page', 'kntnt-autolink' ),
				'nextPage' => __( 'Next page', 'kntnt-autolink' ),
				'lastPage' => __( 'Last page', 'kntnt-autolink' ),
				'currentPage' => __( 'Current Page', 'kntnt-autolink' ),
				'of' => _x( 'of', 'paging: current page of total', 'kntnt-autolink' ),
			],
		] );

	}

	/**
	 * Render the page: the title with an Add New button, the link-group table,
	 * and the shared add/edit modal.
	 *
	 * @since 1.1.0
	 */
	public function render(): void {

		if ( ! current_user_can( Capabilities::MANAGE_LINK_GROUPS ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'kntnt-autolink' ) );
		}

		$table = new Link_Groups_List_Table( $this->groups );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Autolink', 'kntnt-autolink' ) . '</h1>';
		echo ' <a href="#" class="page-title-action kntnt-autolink-add">' . esc_html__( 'Add link group', 'kntnt-autolink' ) . '</a>';
		echo '<hr class="wp-header-end">';
		echo '<p>' . esc_html__( 'A link group turns any of its phrases into links to one URL, sharing one group cap.', 'kntnt-autolink' ) . '</p>';

		// The list lives in a GET form so the native search box, sortable column
		// headers and pagination round-trip through the page URL. The hidden page
		// field keeps the request on this admin screen across those reloads.
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		$table->search_box( __( 'Search link groups', 'kntnt-autolink' ), 'kntnt-autolink-search' );
		$table->display();
		echo '</form>';

		$this->render_modal();

		echo '</div>';

	}

	/**
	 * Render the shared add/edit <dialog> modal. Its fields are seeded by the
	 * JavaScript from a row's data-* attributes (edit) or left empty (add).
	 *
	 * @since 1.1.0
	 */
	private function render_modal(): void {
		?>
		<dialog id="kntnt-autolink-modal" class="kntnt-autolink-modal" aria-labelledby="kntnt-autolink-modal-title">
			<form id="kntnt-autolink-form" method="dialog">
				<div class="kntnt-autolink-modal__body">
					<h2 id="kntnt-autolink-modal-title"><?php esc_html_e( 'Add link group', 'kntnt-autolink' ); ?></h2>
					<input type="hidden" name="id" value="">
					<div class="kntnt-autolink-modal__field">
						<label for="kntnt-autolink-field-phrases"><?php esc_html_e( 'Phrases (one per line)', 'kntnt-autolink' ); ?></label>
						<textarea id="kntnt-autolink-field-phrases" name="phrases" rows="4" required></textarea>
					</div>
					<div class="kntnt-autolink-modal__field">
						<label for="kntnt-autolink-field-url"><?php esc_html_e( 'URL', 'kntnt-autolink' ); ?></label>
						<input type="url" id="kntnt-autolink-field-url" name="url" required>
					</div>
					<div class="kntnt-autolink-modal__field">
						<label for="kntnt-autolink-field-cap"><?php esc_html_e( 'Group cap', 'kntnt-autolink' ); ?></label>
						<input type="number" id="kntnt-autolink-field-cap" name="cap" min="1" value="1">
					</div>
					<div class="kntnt-autolink-modal__field">
						<label><input type="checkbox" name="nofollow" value="1"> <?php esc_html_e( 'Add rel="nofollow"', 'kntnt-autolink' ); ?></label>
					</div>
					<div class="kntnt-autolink-modal__field">
						<label><input type="checkbox" name="new_tab" value="1"> <?php esc_html_e( 'Open in a new tab', 'kntnt-autolink' ); ?></label>
					</div>
				</div>
				<div class="kntnt-autolink-modal__footer">
					<button type="button" class="button kntnt-autolink-cancel"><?php esc_html_e( 'Cancel', 'kntnt-autolink' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save link group', 'kntnt-autolink' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

}
