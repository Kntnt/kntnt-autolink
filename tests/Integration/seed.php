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

// Link groups. The first deliberately carries a generous group cap (5), well
// above its number of occurrences, so the heading occurrence of its phrase can
// only stay unlinked because the deny-tags rule skips headings — never because a
// cap of one was exhausted by the paragraph occurrence. The second carries its
// own nofollow / new-tab policy, so its link proves the per-group behaviour
// end-to-end. The remaining three (alpha / bravo / charlie) never appear in the
// page content — they exist only to give the Tools list enough rows, with unique
// first phrases and unique caps, to exercise search, sort and pagination over the
// "render rows" route. Charlie's destination carries a unique "zebraurl" token so
// a search can match by URL rather than by phrase.
update_option( 'kntnt_autolink_link_groups', [
	[ 'id' => 'g1', 'phrases' => [ 'autolink' ], 'url' => 'https://example.com/target', 'cap' => 5 ],
	[ 'id' => 'g2', 'phrases' => [ 'nofollowme' ], 'url' => 'https://example.com/nofollow', 'cap' => 1, 'nofollow' => true, 'new_tab' => true ],
	[ 'id' => 'gA', 'phrases' => [ 'alpha' ], 'url' => 'https://example.com/alpha', 'cap' => 2 ],
	[ 'id' => 'gB', 'phrases' => [ 'bravo' ], 'url' => 'https://example.com/bravo', 'cap' => 4 ],
	[ 'id' => 'gC', 'phrases' => [ 'charlie' ], 'url' => 'https://example.com/zebraurl', 'cap' => 3 ],
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
$rest_meta_ok = 0;
try {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	wp_set_current_user( (int) ( $admins[0] ?? 1 ) );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/kntnt-autolink/v1/link-groups/rows' ) );
	$rest_status = (int) $response->get_status();
	$data = $response->get_data();
	$rows = is_array( $data ) && isset( $data['rows'] ) && is_string( $data['rows'] ) ? $data['rows'] : '';
	$rest_rows_ok = $rest_status === 200 && str_contains( $rows, 'https://example.com/target' ) && str_contains( $rows, 'column-cap' ) ? 1 : 0;

	// The response must also carry the pagination metadata the admin JS reads to keep
	// the chrome honest after a mutation: the full match count and the page size.
	$rest_meta_ok = is_array( $data ) && is_int( $data['total'] ?? null ) && $data['total'] === 5 && is_int( $data['per_page'] ?? null ) && $data['per_page'] >= 1 ? 1 : 0;
} catch ( \Throwable $e ) {
	$rest_status = 0;
	$rest_meta_ok = 0;
}
$restcheck = "RESTCHECK status={$rest_status} rows_ok={$rest_rows_ok} meta_ok={$rest_meta_ok} ENDREST";

// Exercise search, sort and pagination end-to-end over the same "render rows"
// route the admin JS calls. Each dispatch goes through the real REST stack with
// an administrator current, and the assertions are reduced to flags embedded in
// the page so the shell runner can grep a single deterministic line. Pagination
// is proven by tightening the page size to one through the per-page filter, so a
// sort plus a page number isolates exactly one group.
$list_search_phrase_ok = 0;
$list_search_url_ok = 0;
$list_sort_page_ok = 0;
$list_phrase_sort_ok = 0;
$list_mutation_ok = 0;
try {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	wp_set_current_user( (int) ( $admins[0] ?? 1 ) );

	$rows_for = static function ( string $method, string $route, array $params ): string {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		$data = $response->get_data();
		return is_array( $data ) && isset( $data['rows'] ) && is_string( $data['rows'] ) ? $data['rows'] : '';
	};

	// Search narrows by phrase (bravo) and, separately, by URL (the zebraurl token
	// that lives only in charlie's destination, not in any phrase).
	$by_phrase = $rows_for( 'GET', '/kntnt-autolink/v1/link-groups/rows', [ 's' => 'bravo' ] );
	$list_search_phrase_ok = str_contains( $by_phrase, 'example.com/bravo' ) && ! str_contains( $by_phrase, 'example.com/alpha' ) ? 1 : 0;
	$by_url = $rows_for( 'GET', '/kntnt-autolink/v1/link-groups/rows', [ 's' => 'zebraurl' ] );
	$list_search_url_ok = str_contains( $by_url, 'example.com/zebraurl' ) && ! str_contains( $by_url, 'example.com/bravo' ) ? 1 : 0;

	// With one group per page, sorting by cap descending must put autolink (cap 5)
	// alone on page 1 and bravo (cap 4) alone on page 2.
	add_filter( 'kntnt_autolink_per_page', static fn (): int => 1 );
	$cap_desc_p1 = $rows_for( 'GET', '/kntnt-autolink/v1/link-groups/rows', [ 'orderby' => 'cap', 'order' => 'desc', 'paged' => '1' ] );
	$cap_desc_p2 = $rows_for( 'GET', '/kntnt-autolink/v1/link-groups/rows', [ 'orderby' => 'cap', 'order' => 'desc', 'paged' => '2' ] );
	$list_sort_page_ok = str_contains( $cap_desc_p1, 'example.com/target' ) && ! str_contains( $cap_desc_p1, 'example.com/bravo' )
		&& str_contains( $cap_desc_p2, 'example.com/bravo' ) && ! str_contains( $cap_desc_p2, 'example.com/target' ) ? 1 : 0;

	// Sorting by first phrase ascending puts alpha first.
	$phrase_asc_p1 = $rows_for( 'GET', '/kntnt-autolink/v1/link-groups/rows', [ 'orderby' => 'phrases', 'order' => 'asc', 'paged' => '1' ] );
	$list_phrase_sort_ok = str_contains( $phrase_asc_p1, 'example.com/alpha' ) && ! str_contains( $phrase_asc_p1, 'example.com/bravo' ) ? 1 : 0;

	// A mutation re-renders the current view: deleting alpha while a bravo search is
	// active returns the rows for that search, not the whole unfiltered list.
	$after_delete = $rows_for( 'DELETE', '/kntnt-autolink/v1/link-groups/gA', [ 's' => 'bravo' ] );
	$list_mutation_ok = str_contains( $after_delete, 'example.com/bravo' ) && ! str_contains( $after_delete, 'example.com/target' ) ? 1 : 0;

	remove_all_filters( 'kntnt_autolink_per_page' );
} catch ( \Throwable $e ) {
	$list_search_phrase_ok = 0;
}
$listcheck = "LISTCHECK searchphrase={$list_search_phrase_ok} searchurl={$list_search_url_ok} sortpage={$list_sort_page_ok} phrasesort={$list_phrase_sort_ok} mutation={$list_mutation_ok} ENDLIST";

// Exercise the bulk REST route end-to-end in this non-admin context. A POST to
// the literal /bulk must resolve to the bulk handler (not update with id="bulk"),
// return 200, and actually mutate: set-cap raises a dedicated group's cap and
// delete removes another. Two throwaway groups (phrases absent from the content)
// keep the front-page scenarios untouched; the option is restored to the original
// two front-page groups afterwards.
$bulk_status = 0;
$bulk_setcap_ok = 0;
$bulk_delete_ok = 0;
try {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	wp_set_current_user( (int) ( $admins[0] ?? 1 ) );

	update_option( 'kntnt_autolink_link_groups', [
		[ 'id' => 'g1', 'phrases' => [ 'autolink' ], 'url' => 'https://example.com/target', 'cap' => 5 ],
		[ 'id' => 'g2', 'phrases' => [ 'nofollowme' ], 'url' => 'https://example.com/nofollow', 'cap' => 1, 'nofollow' => true, 'new_tab' => true ],
		[ 'id' => 'bulkcap', 'phrases' => [ 'zzqqcap' ], 'url' => 'https://example.com/cap', 'cap' => 1 ],
		[ 'id' => 'bulkdel', 'phrases' => [ 'zzqqdel' ], 'url' => 'https://example.com/del', 'cap' => 1 ],
	], false );

	$setcap = new WP_REST_Request( 'POST', '/kntnt-autolink/v1/link-groups/bulk' );
	$setcap->set_param( 'action', 'set-cap' );
	$setcap->set_param( 'ids', [ 'bulkcap' ] );
	$setcap->set_param( 'cap', 9 );
	$setcap_response = rest_do_request( $setcap );

	$del = new WP_REST_Request( 'POST', '/kntnt-autolink/v1/link-groups/bulk' );
	$del->set_param( 'action', 'delete' );
	$del->set_param( 'ids', [ 'bulkdel' ] );
	$del_response = rest_do_request( $del );

	$bulk_status = (int) $setcap_response->get_status();
	$by_id = [];
	$after = get_option( 'kntnt_autolink_link_groups' );
	if ( is_array( $after ) ) {
		foreach ( $after as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'] ) ) {
				$by_id[ (string) $entry['id'] ] = $entry;
			}
		}
	}
	$bulk_setcap_ok = isset( $by_id['bulkcap'] ) && (int) ( $by_id['bulkcap']['cap'] ?? 0 ) === 9 ? 1 : 0;
	$bulk_delete_ok = ! isset( $by_id['bulkdel'] ) && (int) $del_response->get_status() === 200 ? 1 : 0;
} catch ( \Throwable $e ) {
	$bulk_status = 0;
}

// Restore the two groups the front-page scenarios depend on (g1 keeps its
// generous cap of 5, as Scenario 1 asserts the heading is skipped by deny-tags,
// not by an exhausted cap).
update_option( 'kntnt_autolink_link_groups', [
	[ 'id' => 'g1', 'phrases' => [ 'autolink' ], 'url' => 'https://example.com/target', 'cap' => 5 ],
	[ 'id' => 'g2', 'phrases' => [ 'nofollowme' ], 'url' => 'https://example.com/nofollow', 'cap' => 1, 'nofollow' => true, 'new_tab' => true ],
], false );
$bulkcheck = "BULKCHECK status={$bulk_status} setcap={$bulk_setcap_ok} delete={$bulk_delete_ok} ENDBULK";

// Exercise the Settings → Autolink sanitiser (issue #4) end-to-end against a real
// WordPress. The same callback options.php runs on save must: reject a post type
// outside the registered public set, parse the no-JS comma string for deny tags
// into a list, coerce the post cap to a positive integer, and round-trip the rest
// through Settings_Repository. The persisted value is deliberately equivalent to
// the engine defaults (post + page enabled, the full deny-tag set, a generous
// post cap of 10) so the front-page scenarios keep their footing.
$settings_repo = new \Kntnt\Autolink\Settings_Repository();
$settings_repo->save_settings( [
	'post_types' => [ 'post', 'page', 'bogus_type' ],
	'deny_tags' => 'h1, h2, h3, h4, h5, h6, a, code, pre, script, style',
	'skip_class' => 'no-autolink',
	'link_class' => 'kntnt-autolink',
	'deny_xpath' => '',
	'allow_only_xpath' => '',
	'max_links_per_post' => '10',
] );
$reread = new \Kntnt\Autolink\Settings_Repository();
$saved_rules = $reread->get_ruleset();
$set_posttypes_ok = $reread->get_post_types() === [ 'post', 'page' ] ? 1 : 0;
$set_denytags_ok = in_array( 'h2', $saved_rules->deny_tags, true ) ? 1 : 0;
$set_cap_ok = $reread->sanitize_settings( [ 'max_links_per_post' => '0' ] )['max_links_per_post'] === 1 ? 1 : 0;
$settingscheck = "SETTINGSCHECK posttypes={$set_posttypes_ok} denytags={$set_denytags_ok} cap={$set_cap_ok} ENDSETTINGS";

// Exercise the term-targeting feature (issue #5) end-to-end against a real
// WordPress: the REST term-search route (manage_options gate + taxonomy/search
// sanitisation), the settings sanitiser round-trip of the taxonomy => term-ids
// map, and the engine honouring it through the real has_term (any-of the chosen
// terms AND an enabled post type). Each assertion is reduced to a flag embedded
// in the page so the runner can grep one deterministic line. The settings are
// restored to the no-terms defaults at the end so the front-page scenarios keep
// their footing (the front page is a term-less page).
$term_route_ok = 0;
$term_badtax_ok = 0;
$term_gate_ok = 0;
$term_roundtrip_ok = 0;
$term_engine_in = 0;
$term_engine_out = 0;
try {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	$editors = get_users( [ 'role' => 'editor', 'number' => 1, 'fields' => 'ID' ] );
	wp_set_current_user( (int) ( $admins[0] ?? 1 ) );

	// A category term to search for and target.
	$inserted = wp_insert_term( 'Newsroom', 'category' );
	$tid = is_array( $inserted ) ? (int) $inserted['term_id'] : 0;

	// The /terms route returns the matching term for a registered taxonomy (admin).
	$search = new WP_REST_Request( 'GET', '/kntnt-autolink/v1/terms' );
	$search->set_param( 'taxonomy', 'category' );
	$search->set_param( 'search', 'News' );
	$search_response = rest_do_request( $search );
	$search_data = $search_response->get_data();
	$found = false;
	if ( is_array( $search_data ) ) {
		foreach ( $search_data as $row ) {
			if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) === $tid && $tid > 0 ) {
				$found = true;
			}
		}
	}
	$term_route_ok = ( (int) $search_response->get_status() === 200 && $found ) ? 1 : 0;

	// An unregistered taxonomy is a 400, never a silent empty result.
	$bad = new WP_REST_Request( 'GET', '/kntnt-autolink/v1/terms' );
	$bad->set_param( 'taxonomy', 'does_not_exist' );
	$term_badtax_ok = ( (int) rest_do_request( $bad )->get_status() === 400 ) ? 1 : 0;

	// The route is gated by manage_options: an editor (or no user) lacking it is refused.
	wp_set_current_user( (int) ( $editors[0] ?? 0 ) );
	$gate = new WP_REST_Request( 'GET', '/kntnt-autolink/v1/terms' );
	$gate->set_param( 'taxonomy', 'category' );
	$gate_status = (int) rest_do_request( $gate )->get_status();
	$term_gate_ok = ( $gate_status === 401 || $gate_status === 403 ) ? 1 : 0;
	wp_set_current_user( (int) ( $admins[0] ?? 1 ) );

	// Sanitiser round-trip: a mixed/string id input reduces to the taxonomy => list<int>
	// map the engine reads back.
	$term_repo = new \Kntnt\Autolink\Settings_Repository();
	$term_repo->save_settings( [
		'post_types' => [ 'post', 'page' ],
		'terms' => [ 'category' => "{$tid}, abc, 0" ],
	] );
	$term_reread = new \Kntnt\Autolink\Settings_Repository();
	$term_roundtrip_ok = ( $term_reread->get_terms() === [ 'category' => [ $tid ] ] ) ? 1 : 0;

	// Engine targeting end-to-end via the real has_term: a post carrying the targeted
	// category is processed; one outside it is not.
	$post_in = (int) wp_insert_post( [ 'post_title' => 'In', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<p>autolink</p>' ] );
	wp_set_object_terms( $post_in, [ $tid ], 'category' );
	$post_out = (int) wp_insert_post( [ 'post_title' => 'Out', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<p>autolink</p>' ] );

	$GLOBALS['post'] = get_post( $post_in );
	$rendered_in = (string) apply_filters( 'the_content', '<p>autolink</p>' );
	$term_engine_in = str_contains( $rendered_in, 'https://example.com/target' ) ? 1 : 0;

	$GLOBALS['post'] = get_post( $post_out );
	$rendered_out = (string) apply_filters( 'the_content', '<p>autolink</p>' );
	$term_engine_out = ! str_contains( $rendered_out, 'https://example.com/target' ) ? 1 : 0;

	$GLOBALS['post'] = null;
} catch ( \Throwable $e ) {
	$term_route_ok = 0;
}

// Restore the no-terms defaults so the front-page (a term-less page) renders.
( new \Kntnt\Autolink\Settings_Repository() )->save_settings( [
	'post_types' => [ 'post', 'page' ],
	'deny_tags' => 'h1, h2, h3, h4, h5, h6, a, code, pre, script, style',
	'skip_class' => 'no-autolink',
	'link_class' => 'kntnt-autolink',
	'deny_xpath' => '',
	'allow_only_xpath' => '',
	'max_links_per_post' => '10',
] );
$termscheck = "TERMSCHECK route={$term_route_ok} badtax={$term_badtax_ok} gate={$term_gate_ok} roundtrip={$term_roundtrip_ok} enginein={$term_engine_in} engineout={$term_engine_out} ENDTERMS";

// A published page (fixed id 42) set as the front page, so it renders at "/".
if ( get_post( 42 ) === null ) {
	wp_insert_post( [
		'import_id' => 42,
		'post_title' => 'Autolink Test',
		'post_status' => 'publish',
		'post_type' => 'page',
		'post_content' => "<h2>About autolink</h2>\n<p>This is autolink in a paragraph.</p>\n<p>And nofollowme in a paragraph.</p>\n<!-- {$capcheck} -->\n<!-- {$restcheck} -->\n<!-- {$listcheck} -->\n<!-- {$bulkcheck} -->\n<!-- {$settingscheck} -->\n<!-- {$termscheck} -->",
	] );
}
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', 42 );
