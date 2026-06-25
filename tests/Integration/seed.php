<?php
/**
 * Integration-test seed, run inside WordPress Playground via the blueprint.
 *
 * Configures one keyword and publishes a page (used as the site's front page so
 * it renders deterministically at "/") that contains the keyword both inside a
 * heading — which must NOT be linked — and inside a paragraph — which must be.
 * It also embeds the result of the capability split as an HTML comment so the
 * runner can assert the authority model in a real role context (scenario 2).
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

require_once '/wordpress/wp-load.php';

// One keyword pointing at a known URL.
update_option( 'kntnt_autolink_keywords', [
	[ 'id' => 'k1', 'base' => 'autolink', 'variants' => [], 'url' => 'https://example.com/target', 'max' => 1 ],
], false );

// Capability gating (scenario 2): activation grants keyword management to the
// editor role but never manage_options; the administrator role holds both.
$editor = get_role( 'editor' );
$administrator = get_role( 'administrator' );
$ek = $editor !== null && $editor->has_cap( 'kntnt_autolink_manage_keywords' ) ? 1 : 0;
$eo = $editor !== null && $editor->has_cap( 'manage_options' ) ? 1 : 0;
$ak = $administrator !== null && $administrator->has_cap( 'kntnt_autolink_manage_keywords' ) ? 1 : 0;
$ao = $administrator !== null && $administrator->has_cap( 'manage_options' ) ? 1 : 0;
$capcheck = "CAPCHECK ek={$ek} eo={$eo} ak={$ak} ao={$ao} ENDCAP";

// A published page (fixed id 42) set as the front page, so it renders at "/".
if ( get_post( 42 ) === null ) {
	wp_insert_post( [
		'import_id' => 42,
		'post_title' => 'Autolink Test',
		'post_status' => 'publish',
		'post_type' => 'page',
		'post_content' => "<h2>About autolink</h2>\n<p>This is autolink in a paragraph.</p>\n<!-- {$capcheck} -->",
	] );
}
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', 42 );
