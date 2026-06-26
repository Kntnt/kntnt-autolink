<?php
/**
 * Integration-test seed, run inside WordPress Playground via the blueprint.
 *
 * Configures two link groups and publishes a page (used as the site's front page
 * so it renders deterministically at "/"). The first group's phrase appears both
 * inside a heading — which must NOT be linked — and inside a paragraph — which
 * must be. The second group carries its own nofollow / new-tab policy, so its
 * paragraph occurrence proves the per-group behaviour end-to-end. It also embeds
 * the result of the capability split as an HTML comment so the runner can assert
 * the authority model in a real role context.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

require_once '/wordpress/wp-load.php';

// Two link groups. The first deliberately carries a generous group cap (5), well
// above its number of occurrences, so the heading occurrence of its phrase can
// only stay unlinked because the deny-tags rule skips headings — never because a
// cap of one was exhausted by the paragraph occurrence. The second carries its
// own nofollow / new-tab policy, so its link proves the per-group behaviour
// end-to-end.
update_option( 'kntnt_autolink_link_groups', [
	[ 'id' => 'g1', 'phrases' => [ 'autolink' ], 'url' => 'https://example.com/target', 'cap' => 5 ],
	[ 'id' => 'g2', 'phrases' => [ 'nofollowme' ], 'url' => 'https://example.com/nofollow', 'cap' => 1, 'nofollow' => true, 'new_tab' => true ],
], false );

// Capability gating: activation grants link-group management to the editor role
// but never manage_options; the administrator role holds both.
$editor = get_role( 'editor' );
$administrator = get_role( 'administrator' );
$ek = $editor !== null && $editor->has_cap( 'kntnt_autolink_manage_link_groups' ) ? 1 : 0;
$eo = $editor !== null && $editor->has_cap( 'manage_options' ) ? 1 : 0;
$ak = $administrator !== null && $administrator->has_cap( 'kntnt_autolink_manage_link_groups' ) ? 1 : 0;
$ao = $administrator !== null && $administrator->has_cap( 'manage_options' ) ? 1 : 0;
$capcheck = "CAPCHECK ek={$ek} eo={$eo} ak={$ak} ao={$ao} ENDCAP";

// Exercise the production "render rows" REST route in this non-admin request
// context — the very context that builds the WP_List_Table without wp-admin
// loaded, where the table re-render fataled on the undefined convert_to_screen()
// before the fix. A 200 carrying the real row HTML proves the table re-renders
// server-side; the try/catch turns any fatal into an observable status=0 instead
// of aborting the seed, so the runner can assert the difference either way.
$rest_status = 0;
$rest_rows_ok = 0;
try {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	wp_set_current_user( (int) ( $admins[0] ?? 1 ) );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/kntnt-autolink/v1/link-groups/rows' ) );
	$rest_status = (int) $response->get_status();
	$data = $response->get_data();
	$rows = is_array( $data ) && isset( $data['rows'] ) && is_string( $data['rows'] ) ? $data['rows'] : '';
	$rest_rows_ok = $rest_status === 200 && str_contains( $rows, 'https://example.com/target' ) && str_contains( $rows, 'column-cap' ) ? 1 : 0;
} catch ( \Throwable $e ) {
	$rest_status = 0;
}
$restcheck = "RESTCHECK status={$rest_status} rows_ok={$rest_rows_ok} ENDREST";

// A published page (fixed id 42) set as the front page, so it renders at "/".
if ( get_post( 42 ) === null ) {
	wp_insert_post( [
		'import_id' => 42,
		'post_title' => 'Autolink Test',
		'post_status' => 'publish',
		'post_type' => 'page',
		'post_content' => "<h2>About autolink</h2>\n<p>This is autolink in a paragraph.</p>\n<p>And nofollowme in a paragraph.</p>\n<!-- {$capcheck} -->\n<!-- {$restcheck} -->",
	] );
}
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', 42 );
