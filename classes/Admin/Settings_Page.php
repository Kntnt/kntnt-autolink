<?php
/**
 * The structural-rules page under Settings → Autolink — administrators only.
 * It realises the menu split (ADR-0002): editor-facing link-group management
 * lives under Tools, while the admin-only structural rules live here.
 *
 * The page is built on the WordPress Settings API (ADR-0001, no build step):
 * register_setting() with a sanitize callback, three add_settings_section()
 * groups, an add_settings_field() per control, and a native save through
 * options.php. The two list-shaped fields — post types and deny tags — render
 * as a reusable chip widget (classes/Admin/Settings_Page.php markup + js/chips.js
 * + css/chips.css). The widget degrades without JavaScript to a plain textarea
 * that posts a comma/newline string under the same option key, so the page saves
 * either way; the sanitiser accepts both shapes.
 *
 * @since 1.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink\Admin;

use Kntnt\Autolink\Settings_Repository;

final class Settings_Page {

	/** Admin page slug and the do_settings_sections() page handle. @since 1.1.0 */
	private const SLUG = 'kntnt-autolink';

	/** The settings group passed to settings_fields()/register_setting(). @since 1.2.0 */
	private const GROUP = 'kntnt_autolink';

	/** The option this page reads and writes, shared with Settings_Repository. @since 1.2.0 */
	private const OPTION = 'kntnt_autolink_settings';

	/** Section ids, in render order. @since 1.2.0 */
	private const SECTION_TARGETING = 'kntnt_autolink_targeting';
	private const SECTION_BEHAVIOUR = 'kntnt_autolink_behaviour';
	private const SECTION_CONTENT = 'kntnt_autolink_content';

	/** The page's hook suffix, so assets load only on this screen. @since 1.2.0 */
	private ?string $hook_suffix = null;

	/**
	 * @since 1.2.0
	 *
	 * @param Settings_Repository $settings    Reads and sanitises the option.
	 * @param string              $plugin_file The main plugin file, for asset URLs.
	 * @param string              $version     Asset version, for cache-busting.
	 */
	public function __construct(
		private readonly Settings_Repository $settings,
		private readonly string $plugin_file,
		private readonly string $version,
	) {}

	/**
	 * Register the menu entry, the Settings-API wiring, and the screen-scoped
	 * asset enqueue.
	 *
	 * @since 1.2.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', $this->add_page( ... ) );
		add_action( 'admin_init', $this->register_settings( ... ) );
		add_action( 'admin_enqueue_scripts', $this->enqueue( ... ) );
	}

	/**
	 * Add the page under Settings, administrators only, and remember its hook
	 * suffix so the chip assets load only here.
	 *
	 * @since 1.1.0
	 */
	public function add_page(): void {
		$this->hook_suffix = (string) add_options_page(
			__( 'Autolink', 'kntnt-autolink' ),
			__( 'Autolink', 'kntnt-autolink' ),
			'manage_options',
			self::SLUG,
			$this->render( ... ),
		);
	}

	/**
	 * Register the option, its sanitize callback, the three sections and every
	 * field. options.php saves the option natively after running the callback.
	 *
	 * @since 1.2.0
	 */
	public function register_settings(): void {

		register_setting( self::GROUP, self::OPTION, [
			'type' => 'array',
			'sanitize_callback' => $this->sanitize( ... ),
			'show_in_rest' => false,
		] );

		// Section 1 — Targeting. Post types only here; term targeting (issue #5)
		// will add its control to this same section later.
		add_settings_section(
			self::SECTION_TARGETING,
			__( 'Targeting', 'kntnt-autolink' ),
			$this->render_targeting_intro( ... ),
			self::SLUG,
		);
		add_settings_field(
			'post_types',
			__( 'Post types', 'kntnt-autolink' ),
			$this->render_post_types_field( ... ),
			self::SLUG,
			self::SECTION_TARGETING,
			[ 'label_for' => 'kntnt-autolink-post_types' ],
		);

		// Section 2 — Link behaviour & limits. nofollow / new-tab are per-group and
		// are deliberately absent here.
		add_settings_section(
			self::SECTION_BEHAVIOUR,
			__( 'Link behaviour & limits', 'kntnt-autolink' ),
			$this->render_behaviour_intro( ... ),
			self::SLUG,
		);
		add_settings_field(
			'link_class',
			__( 'Link CSS class', 'kntnt-autolink' ),
			$this->render_text_field( ... ),
			self::SLUG,
			self::SECTION_BEHAVIOUR,
			[
				'label_for' => 'kntnt-autolink-link_class',
				'key' => 'link_class',
				'description' => __( 'Class added to every generated link, for theming.', 'kntnt-autolink' ),
			],
		);
		add_settings_field(
			'max_links_per_post',
			__( 'Post cap', 'kntnt-autolink' ),
			$this->render_number_field( ... ),
			self::SLUG,
			self::SECTION_BEHAVIOUR,
			[
				'label_for' => 'kntnt-autolink-max_links_per_post',
				'key' => 'max_links_per_post',
				'description' => __( 'Maximum number of autolinks across all link groups in one post.', 'kntnt-autolink' ),
			],
		);

		// Section 3 — Content eligibility (advanced). Where links may go.
		add_settings_section(
			self::SECTION_CONTENT,
			__( 'Content eligibility (advanced)', 'kntnt-autolink' ),
			$this->render_content_intro( ... ),
			self::SLUG,
		);
		add_settings_field(
			'deny_tags',
			__( 'Deny tags', 'kntnt-autolink' ),
			$this->render_deny_tags_field( ... ),
			self::SLUG,
			self::SECTION_CONTENT,
			[ 'label_for' => 'kntnt-autolink-deny_tags' ],
		);
		add_settings_field(
			'skip_class',
			__( 'Skip class', 'kntnt-autolink' ),
			$this->render_text_field( ... ),
			self::SLUG,
			self::SECTION_CONTENT,
			[
				'label_for' => 'kntnt-autolink-skip_class',
				'key' => 'skip_class',
				'description' => __( 'Elements carrying this class, and their descendants, are never linked.', 'kntnt-autolink' ),
			],
		);
		add_settings_field(
			'deny_xpath',
			__( 'Deny XPath', 'kntnt-autolink' ),
			$this->render_text_field( ... ),
			self::SLUG,
			self::SECTION_CONTENT,
			[
				'label_for' => 'kntnt-autolink-deny_xpath',
				'key' => 'deny_xpath',
				'description' => __( 'Optional raw XPath; nodes within its result are excluded from linking.', 'kntnt-autolink' ),
			],
		);
		add_settings_field(
			'allow_only_xpath',
			__( 'Allow-only XPath', 'kntnt-autolink' ),
			$this->render_text_field( ... ),
			self::SLUG,
			self::SECTION_CONTENT,
			[
				'label_for' => 'kntnt-autolink-allow_only_xpath',
				'key' => 'allow_only_xpath',
				'description' => __( 'Optional raw XPath; when set, only nodes within its result are eligible.', 'kntnt-autolink' ),
			],
		);

	}

	/**
	 * The registered sanitize callback. Delegates to the repository — the single
	 * source of the option shape — after defending against a non-array submission.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input The raw option value posted to options.php.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		return $this->settings->sanitize_settings( is_array( $input ) ? $input : [] );
	}

	/**
	 * Enqueue the chip widget's stylesheet and script — only on this screen.
	 *
	 * @since 1.2.0
	 */
	public function enqueue( string $hook_suffix ): void {

		if ( $this->hook_suffix === null || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'kntnt-autolink-chips', plugins_url( 'css/chips.css', $this->plugin_file ), [], $this->version );
		wp_enqueue_script( 'kntnt-autolink-chips', plugins_url( 'js/chips.js', $this->plugin_file ), [], $this->version, true );

	}

	/**
	 * Render the page shell and hand the body to the Settings API. Administrators
	 * only; the field callbacks read only this manage_options-gated screen's data.
	 *
	 * @since 1.2.0
	 */
	public function render(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'kntnt-autolink' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form>';
		echo '</div>';

	}

	/**
	 * The Targeting section's intro line.
	 *
	 * @since 1.2.0
	 */
	public function render_targeting_intro(): void {
		echo '<p>' . esc_html__( 'Limit autolinking to the chosen post types.', 'kntnt-autolink' ) . '</p>';
	}

	/**
	 * The Link behaviour & limits section's intro line.
	 *
	 * @since 1.2.0
	 */
	public function render_behaviour_intro(): void {
		echo '<p>' . esc_html__( 'How generated links look and how many a post may carry.', 'kntnt-autolink' ) . '</p>';
	}

	/**
	 * The Content eligibility section's intro line.
	 *
	 * @since 1.2.0
	 */
	public function render_content_intro(): void {
		echo '<p>' . esc_html__( 'Fine-grained control over which parts of the content may be linked.', 'kntnt-autolink' ) . '</p>';
	}

	/**
	 * Render the post-types control: a closed-list chip widget whose options are
	 * the registered public post types, so an invalid type cannot be chosen in the
	 * JS path and is dropped by the sanitiser in the no-JS path.
	 *
	 * @since 1.2.0
	 */
	public function render_post_types_field(): void {

		$selected = $this->to_string_list( $this->settings->get_settings()['post_types'] ?? [] );

		// The closed option set: slug => human label, from the registered public types.
		$options = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $slug => $type ) {
			$options[ (string) $slug ] = $type->label;
		}

		$this->render_chip_field(
			key: 'post_types',
			mode: 'closed',
			values: $selected,
			options: $options,
			placeholder: __( 'Add a post type…', 'kntnt-autolink' ),
			description: __( 'Only posts of the selected types are processed.', 'kntnt-autolink' ),
		);

	}

	/**
	 * Render the deny-tags control: a free-text chip widget, prefilled with the
	 * default tags whose subtree is never linked.
	 *
	 * @since 1.2.0
	 */
	public function render_deny_tags_field(): void {

		$values = $this->to_string_list( $this->settings->get_settings()['deny_tags'] ?? [] );

		$this->render_chip_field(
			key: 'deny_tags',
			mode: 'free',
			values: $values,
			options: [],
			placeholder: __( 'Add a tag…', 'kntnt-autolink' ),
			description: __( 'Tags whose contents are never linked. Type a tag and press Enter.', 'kntnt-autolink' ),
		);

	}

	/**
	 * Render a single-line text field bound to a settings key.
	 *
	 * @since 1.2.0
	 *
	 * @param array{key: string, description?: string} $args
	 */
	public function render_text_field( array $args ): void {
		$this->render_scalar_field( $args, 'text' );
	}

	/**
	 * Render a positive-integer number field bound to a settings key.
	 *
	 * @since 1.2.0
	 *
	 * @param array{key: string, description?: string} $args
	 */
	public function render_number_field( array $args ): void {
		$this->render_scalar_field( $args, 'number' );
	}

	/**
	 * Render a text or number input bound to a settings key, with its help line.
	 *
	 * @since 1.2.0
	 *
	 * @param array{key: string, description?: string} $args
	 */
	private function render_scalar_field( array $args, string $type ): void {

		$key = $args['key'];
		$value = $this->settings->get_settings()[ $key ] ?? '';
		$value = is_scalar( $value ) ? (string) $value : '';
		$id = 'kntnt-autolink-' . $key;
		$min = $type === 'number' ? ' min="1" step="1"' : '';

		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $id ) . '"' . $min;
		echo ' name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '">';

		if ( isset( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}

	}

	/**
	 * Coerce a stored list value into a list of strings for display.
	 *
	 * @since 1.2.0
	 *
	 * @return list<string>
	 */
	private function to_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$result = [];
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$result[] = (string) $item;
			}
		}
		return $result;
	}

	/**
	 * Render the reusable chip widget. Server-side it emits a labelled textarea
	 * carrying the current values as a comma-separated string and posting under
	 * the option key — this is the complete no-JS control. js/chips.js then
	 * upgrades it in place: it hides the textarea, removes its name so it stops
	 * submitting, and maintains one hidden input per chip
	 * (name="kntnt_autolink_settings[<key>][]"), so the JS path posts an array and
	 * the no-JS path a string. The closed-mode option set rides along in a JSON
	 * <script> the widget reads; issue #5 can add an async suggestion source by
	 * carrying a data-suggest hook the script resolves (see js/chips.js).
	 *
	 * @since 1.2.0
	 *
	 * @param string                $key         Settings key (post_types, deny_tags).
	 * @param string                $mode        'closed' (fixed options) or 'free' (any text).
	 * @param list<string>          $values      Current chip values.
	 * @param array<string, string> $options     Closed-mode option set: token => label.
	 * @param string                $placeholder Entry-field placeholder.
	 * @param string                $description Grey help line.
	 */
	private function render_chip_field(
		string $key,
		string $mode,
		array $values,
		array $options,
		string $placeholder,
		string $description,
	): void {

		$id = 'kntnt-autolink-' . $key;
		$name = self::OPTION . '[' . $key . ']';

		echo '<div class="kntnt-autolink-chips" data-kntnt-autolink-chips data-key="' . esc_attr( $key ) . '"';
		echo ' data-mode="' . esc_attr( $mode ) . '" data-name="' . esc_attr( $name ) . '"';
		echo ' data-placeholder="' . esc_attr( $placeholder ) . '">';

		// The no-JS control and the JS source of initial values: a textarea posting
		// the comma-separated tokens under the option key.
		echo '<textarea class="kntnt-autolink-chips__input large-text" id="' . esc_attr( $id ) . '"';
		echo ' name="' . esc_attr( $name ) . '" rows="2">' . esc_textarea( implode( ', ', $values ) ) . '</textarea>';

		// Closed-mode option set, read by the script to build a fixed-option selector.
		if ( $options !== [] ) {
			echo '<script type="application/json" class="kntnt-autolink-chips__options">';
			echo wp_json_encode( $options );
			echo '</script>';
		}

		echo '<p class="description">' . esc_html( $description ) . '</p>';
		echo '</div>';

	}

}
