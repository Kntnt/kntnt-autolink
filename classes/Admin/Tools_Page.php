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
	private const BULK_NONCE = 'kntnt_autolink_bulk';

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
				'confirmBulkDelete' => __( 'Delete the selected link groups?', 'kntnt-autolink' ),
				'setCapPromptSingular' => __( 'Set the group cap for the %d selected group to:', 'kntnt-autolink' ),
				'setCapPromptPlural' => __( 'Set the group cap for the %d selected groups to:', 'kntnt-autolink' ),
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
	 * Render the page. Capability-gated first; then a confirmed no-JS bulk
	 * submission is applied and redirected, the first step of a no-JS bulk action
	 * shows its native confirmation screen, and otherwise the list itself renders.
	 *
	 * @since 1.1.0
	 */
	public function render(): void {

		if ( ! current_user_can( Capabilities::MANAGE_LINK_GROUPS ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'kntnt-autolink' ) );
		}

		// A confirmed no-JS bulk submission: capability checked above, nonce inside,
		// then apply and redirect so a reload of the result page repeats nothing.
		if ( $this->is_bulk_confirmation() ) {
			$this->apply_bulk_and_redirect();
			return;
		}

		// The first step of a no-JS bulk action: show the native confirmation screen
		// (the set-cap screen carries the number field) instead of the list.
		$pending = $this->pending_bulk_action();
		if ( $pending !== null ) {
			$this->render_bulk_confirmation( $pending['action'], $pending['ids'] );
			return;
		}

		$this->render_list();

	}

	/**
	 * Render the list view: the title with an Add New button, the link-group table
	 * inside its search/bulk-action form, and the shared add/edit and set-cap modals.
	 *
	 * @since 1.1.0
	 */
	private function render_list(): void {

		$table = new Link_Groups_List_Table( $this->groups );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Autolink', 'kntnt-autolink' ) . '</h1>';
		echo ' <a href="#" class="page-title-action kntnt-autolink-add">' . esc_html__( 'Add link group', 'kntnt-autolink' ) . '</a>';
		$this->render_settings_link();
		echo '<hr class="wp-header-end">';
		echo '<p>' . esc_html__( 'A link group turns any of its phrases into links to one URL, sharing one group cap.', 'kntnt-autolink' ) . '</p>';

		// The list lives in a GET form so the native search box, sortable column
		// headers, pagination and the bulk-actions dropdown round-trip through the
		// page URL. The hidden page field keeps the request on this admin screen
		// across those reloads. JS enhances Apply into a REST call; the form is the
		// no-JS fallback that posts the selection back here for confirmation.
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		$table->search_box( __( 'Search link groups', 'kntnt-autolink' ), 'kntnt-autolink-search' );
		$table->display();
		echo '</form>';

		$this->render_modal();
		$this->render_cap_modal();

		echo '</div>';

	}

	/**
	 * Whether the request is a confirmed no-JS bulk submission (the interstitial
	 * was submitted), as opposed to the first step or a normal page load.
	 *
	 * @since 1.1.0
	 */
	private function is_bulk_confirmation(): bool {
		return ( $_REQUEST['kntnt_autolink_bulk_confirm'] ?? '' ) === '1';
	}

	/**
	 * The bulk action awaiting its native confirmation screen, or null when the
	 * request is not the first step of a no-JS bulk action.
	 *
	 * @since 1.1.0
	 *
	 * @return array{action: string, ids: list<string>}|null
	 */
	private function pending_bulk_action(): ?array {
		$action = $this->request_action();
		$ids = $this->request_ids();
		if ( ( $action === 'delete' || $action === 'set-cap' ) && $ids !== [] ) {
			return [ 'action' => $action, 'ids' => $ids ];
		}
		return null;
	}

	/**
	 * Apply a confirmed no-JS bulk action, then redirect back to the clean list.
	 * The capability was checked by render(); the nonce is verified here before any
	 * mutation, and the cap follows the same clamp as the per-group cap. A bulk
	 * delete is irreversible, so neither path mutates before both gates pass.
	 *
	 * @since 1.1.0
	 */
	private function apply_bulk_and_redirect(): void {

		check_admin_referer( self::BULK_NONCE );

		// Mutate only when something is selected; an unknown action is a no-op.
		$ids = $this->request_ids();
		if ( $ids !== [] ) {
			$action = $this->request_string( 'kntnt_autolink_bulk_action' );
			if ( $action === 'delete' ) {
				$this->groups->delete_many( $ids );
			} elseif ( $action === 'set-cap' ) {
				$this->groups->set_cap( $ids, $this->request_cap() );
			}
		}

		wp_safe_redirect( $this->page_url() );
		exit;

	}

	/**
	 * Render the native no-JS confirmation screen for a bulk action. The set-cap
	 * variant carries the same number field the JS modal does; both post back to
	 * this screen with the selected ids and a nonce.
	 *
	 * @since 1.1.0
	 *
	 * @param list<string> $ids
	 */
	private function render_bulk_confirmation( string $action, array $ids ): void {

		$count = count( $ids );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Autolink', 'kntnt-autolink' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		echo '<form method="post" action="' . esc_url( $this->page_url() ) . '">';
		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="ids[]" value="' . esc_attr( $id ) . '">';
		}
		echo '<input type="hidden" name="kntnt_autolink_bulk_action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="kntnt_autolink_bulk_confirm" value="1">';
		wp_nonce_field( self::BULK_NONCE );

		if ( $action === 'set-cap' ) {
			echo '<p>';
			echo '<label for="kntnt-autolink-bulk-cap">' . esc_html( sprintf(
				/* translators: %d: number of selected link groups. */
				_n(
					'Set the group cap for the %d selected group to:',
					'Set the group cap for the %d selected groups to:',
					$count,
					'kntnt-autolink',
				),
				$count,
			) ) . '</label> ';
			echo '<input type="number" id="kntnt-autolink-bulk-cap" name="cap" min="1" value="1" required>';
			echo '</p>';
			$submit = __( 'Set group cap', 'kntnt-autolink' );
		} else {
			echo '<p>' . esc_html( sprintf(
				/* translators: %d: number of selected link groups. */
				_n(
					'You are about to delete %d selected link group. This cannot be undone.',
					'You are about to delete %d selected link groups. This cannot be undone.',
					$count,
					'kntnt-autolink',
				),
				$count,
			) ) . '</p>';
			$submit = __( 'Delete link groups', 'kntnt-autolink' );
		}

		echo '<p class="submit">';
		echo '<button type="submit" class="button button-primary">' . esc_html( $submit ) . '</button> ';
		echo '<a href="' . esc_url( $this->page_url() ) . '" class="button">' . esc_html__( 'Cancel', 'kntnt-autolink' ) . '</a>';
		echo '</p>';

		echo '</form>';
		echo '</div>';

	}

	/**
	 * The chosen bulk action from the list-table form, sanitised. WP_List_Table
	 * submits the top dropdown as `action` and the bottom as `action2`; the
	 * "no action" sentinel "-1" maps to an empty string.
	 *
	 * @since 1.1.0
	 */
	private function request_action(): string {
		$action = $this->request_string( 'action' );
		if ( $action === '' || $action === '-1' ) {
			$action = $this->request_string( 'action2' );
		}
		return $action === '-1' ? '' : $action;
	}

	/**
	 * The selected ids from the request, each sanitised as a key, empties dropped.
	 *
	 * @since 1.1.0
	 *
	 * @return list<string>
	 */
	private function request_ids(): array {
		$raw = $_REQUEST['ids'] ?? [];
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$ids = [];
		foreach ( $raw as $item ) {
			$id = is_scalar( $item ) ? sanitize_key( (string) wp_unslash( (string) $item ) ) : '';
			if ( $id !== '' ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * A single request field, unslashed and sanitised as a key.
	 *
	 * @since 1.1.0
	 */
	private function request_string( string $key ): string {
		$value = $_REQUEST[ $key ] ?? '';
		return is_scalar( $value ) ? sanitize_key( (string) wp_unslash( (string) $value ) ) : '';
	}

	/**
	 * The bulk set-cap value from the request, read numerically with the same
	 * absint() semantics as the REST path — never through sanitize_key(), which
	 * is for keys and would mangle a non-integer (e.g. "5.5" becoming "55"). The
	 * repository clamps the result to at least one.
	 *
	 * @since 1.1.0
	 */
	private function request_cap(): int {
		$value = $_REQUEST['cap'] ?? '';
		return is_scalar( $value ) ? absint( wp_unslash( (string) $value ) ) : 0;
	}

	/**
	 * The admin URL of this Tools screen, for redirects and the cancel link.
	 *
	 * @since 1.1.0
	 */
	private function page_url(): string {
		return self::url();
	}

	/**
	 * The public admin URL of the Tools → Autolink screen, at the real registered
	 * slug. The single authority other surfaces (the Settings cross-link, the
	 * Plugins-screen action links) build the Tools link from.
	 *
	 * @since 1.1.0
	 */
	public static function url(): string {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}

	/**
	 * Render a contextual link to the Settings → Autolink screen, shown only to
	 * administrators. An editor can use this manager (the manage-link-groups
	 * capability) but not the manage_options-gated Settings page, so gating the
	 * link to manage_options keeps it off an editor's screen and never sends them
	 * to a permission wall.
	 *
	 * @since 1.1.0
	 */
	public function render_settings_link(): void {
		if ( current_user_can( 'manage_options' ) ) {
			echo ' <a href="' . esc_url( Settings_Page::url() ) . '" class="page-title-action">' . esc_html__( 'Settings', 'kntnt-autolink' ) . '</a>';
		}
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

	/**
	 * Render the bulk "Set group cap" <dialog> modal. JavaScript fills its message
	 * with the selected count and posts the new cap to the REST bulk route; the
	 * no-JS path uses the server-rendered confirmation screen instead.
	 *
	 * @since 1.1.0
	 */
	private function render_cap_modal(): void {
		?>
		<dialog id="kntnt-autolink-cap-modal" class="kntnt-autolink-modal" aria-labelledby="kntnt-autolink-cap-title">
			<form id="kntnt-autolink-cap-form" method="dialog">
				<div class="kntnt-autolink-modal__body">
					<h2 id="kntnt-autolink-cap-title"><?php esc_html_e( 'Set group cap', 'kntnt-autolink' ); ?></h2>
					<p id="kntnt-autolink-cap-message"></p>
					<div class="kntnt-autolink-modal__field">
						<label for="kntnt-autolink-cap-value"><?php esc_html_e( 'Group cap', 'kntnt-autolink' ); ?></label>
						<input type="number" id="kntnt-autolink-cap-value" name="cap" min="1" value="1" required>
					</div>
				</div>
				<div class="kntnt-autolink-modal__footer">
					<button type="button" class="button kntnt-autolink-cap-cancel"><?php esc_html_e( 'Cancel', 'kntnt-autolink' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Set group cap', 'kntnt-autolink' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

}
