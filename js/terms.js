/**
 * Kntnt Autolink — term-targeting control (issue #5).
 *
 * Two jobs, both on the Settings → Autolink screen. First, it registers the
 * `terms` autocomplete source against the chip widget's documented seam
 * (`window.kntntAutolinkChips.registerSource`): given the typed query and the
 * chip container, it searches the row's taxonomy through the REST term-search
 * route and resolves to `{ value: id, label: name }` suggestions, so each chip's
 * submitted token is a term id while it reads by name. Second, it drives the
 * repeatable taxonomy rows: the Add taxonomy button clones the server's inert
 * row template, and changing a row's taxonomy rebinds (and clears) its chips,
 * since term ids do not carry across taxonomies.
 *
 * Progressive enhancement (ADR-0001, no build step) over the server-rendered
 * rows: without JavaScript each saved taxonomy keeps its plain textarea of ids
 * and still saves; this script never owns the data shape, only the editing UX.
 */
( () => {
	'use strict';

	const cfg = window.kntntAutolinkTerms;
	const chips = window.kntntAutolinkChips;
	if ( ! cfg || ! chips || typeof chips.registerSource !== 'function' ) {
		return;
	}

	// The async suggestion source: search the row's taxonomy for matching terms.
	chips.registerSource( 'terms', async ( query, container ) => {
		const taxonomy = container && container.dataset ? container.dataset.taxonomy || '' : '';
		if ( ! taxonomy ) {
			return [];
		}
		const url = `${ cfg.rest }?taxonomy=${ encodeURIComponent( taxonomy ) }&search=${ encodeURIComponent( query ) }`;
		const response = await fetch( url, { headers: { 'X-WP-Nonce': cfg.nonce } } );
		if ( ! response.ok ) {
			return [];
		}
		const terms = await response.json();
		return Array.isArray( terms ) ? terms.map( ( term ) => ( { value: String( term.id ), label: term.name } ) ) : [];
	} );

	document.addEventListener( 'DOMContentLoaded', () => {
		const root = document.querySelector( '[data-kntnt-autolink-terms]' );
		if ( ! root ) {
			return;
		}
		const rows = root.querySelector( '.kntnt-autolink-terms__rows' );
		const template = root.querySelector( '.kntnt-autolink-terms__template' );
		const addButton = root.querySelector( '.kntnt-autolink-add-taxonomy' );
		if ( ! rows || ! template || ! addButton ) {
			return;
		}

		// The chip widget of a row, ready to receive a fresh binding.
		const chipsOf = ( row ) => row.querySelector( '[data-kntnt-autolink-chips]' );

		// Bind a row's chip widget to a taxonomy: set the submit name, the data the
		// suggestion source reads, and a unique id, all derived from the slug.
		const bind = ( row, taxonomy ) => {
			const select = row.querySelector( '.kntnt-autolink-term-row__taxonomy' );
			const container = chipsOf( row );
			const textarea = container ? container.querySelector( '.kntnt-autolink-chips__input' ) : null;
			if ( ! select || ! container || ! textarea ) {
				return;
			}
			select.value = taxonomy;
			const name = `${ cfg.optionKey }[terms][${ taxonomy }]`;
			container.dataset.name = name;
			container.dataset.taxonomy = taxonomy;
			container.dataset.key = `terms-${ taxonomy }`;
			textarea.name = name;
			textarea.id = `kntnt-autolink-terms-${ taxonomy }`;
		};

		// Build a fresh, empty, detached row for a taxonomy from the template.
		const makeRow = ( taxonomy ) => {
			const fragment = template.content.cloneNode( true );
			const row = fragment.querySelector( '.kntnt-autolink-term-row' );
			bind( row, taxonomy );
			attachHandlers( row );
			return row;
		};

		// Enhance a row's chip widget once it is in the live DOM.
		const enhanceRow = ( row ) => {
			const container = chipsOf( row );
			if ( container && typeof chips.enhance === 'function' ) {
				chips.enhance( container );
			}
		};

		// Replace a row in place when its taxonomy changes: a fresh, empty widget
		// bound to the new taxonomy, since the old term ids no longer apply.
		const changeTaxonomy = ( row, taxonomy ) => {
			const next = makeRow( taxonomy );
			row.replaceWith( next );
			enhanceRow( next );
		};

		// Wire a row's taxonomy selector and its remove button.
		function attachHandlers( row ) {
			const select = row.querySelector( '.kntnt-autolink-term-row__taxonomy' );
			if ( select ) {
				select.addEventListener( 'change', () => changeTaxonomy( row, select.value ) );
			}
			const remove = row.querySelector( '.kntnt-autolink-term-row__remove' );
			if ( remove ) {
				remove.addEventListener( 'click', () => row.remove() );
			}
		}

		// The taxonomy slugs the template offers, in order.
		const taxonomyOptions = () =>
			[ ...template.content.querySelectorAll( '.kntnt-autolink-term-row__taxonomy option' ) ].map( ( option ) => option.value );

		// The taxonomies already bound by a row, so Add can default to an unused one.
		const usedTaxonomies = () =>
			[ ...rows.querySelectorAll( '.kntnt-autolink-term-row__taxonomy' ) ].map( ( select ) => select.value );

		// Server-rendered rows are already chip-enhanced by chips.js; only their row
		// handlers need wiring.
		rows.querySelectorAll( '.kntnt-autolink-term-row' ).forEach( ( row ) => attachHandlers( row ) );

		addButton.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			const used = usedTaxonomies();
			const options = taxonomyOptions();
			const taxonomy = options.find( ( slug ) => ! used.includes( slug ) ) || options[ 0 ] || '';
			if ( ! taxonomy ) {
				return;
			}
			const row = makeRow( taxonomy );
			rows.appendChild( row );
			enhanceRow( row );
		} );
	} );
} )();
