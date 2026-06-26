/**
 * Kntnt Autolink admin: the link-group add/edit modal and REST plumbing.
 *
 * Progressive enhancement over the server-rendered WP_List_Table — a single
 * <dialog> shared by add and edit talks to the kntnt-autolink/v1 REST API for
 * mutations; on success the table body is replaced with the server-rendered
 * rows returned in the JSON, so there is no second row renderer here. The same
 * response carries the total match count and page size, from which the native
 * pagination chrome is reconciled and a mutation that strands the user past the
 * last page is redirected to the new last page.
 */
( () => {
	'use strict';

	const cfg = window.kntntAutolink;
	if ( ! cfg ) {
		return;
	}

	const dialog = document.getElementById( 'kntnt-autolink-modal' );
	const form = document.getElementById( 'kntnt-autolink-form' );
	const tbody = document.querySelector( '.wp-list-table tbody' );
	if ( ! dialog || ! form || ! tbody ) {
		return;
	}

	const fields = {
		id: form.querySelector( '[name="id"]' ),
		phrases: form.querySelector( '[name="phrases"]' ),
		url: form.querySelector( '[name="url"]' ),
		cap: form.querySelector( '[name="cap"]' ),
		nofollow: form.querySelector( '[name="nofollow"]' ),
		newTab: form.querySelector( '[name="new_tab"]' ),
	};
	const title = document.getElementById( 'kntnt-autolink-modal-title' );

	const open = ( data ) => {
		fields.id.value = data.id || '';
		fields.phrases.value = data.phrases || '';
		fields.url.value = data.url || '';
		fields.cap.value = data.cap || '1';
		fields.nofollow.checked = data.nofollow === '1';
		fields.newTab.checked = data.newTab === '1';
		if ( title ) {
			title.textContent = data.id ? cfg.i18n.edit : cfg.i18n.addNew;
		}
		dialog.showModal();
	};

	const request = async ( url, method, body ) => {
		const response = await fetch( url, {
			method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
		} );
		if ( ! response.ok ) {
			throw new Error( 'Kntnt Autolink: request failed' );
		}
		return response.json();
	};

	// The single-glyph button faces WordPress draws for the four page-reach
	// controls; reused verbatim so the rebuilt chrome matches the native one.
	const navGlyphs = {
		first: '«',
		prev: '‹',
		next: '›',
		last: '»',
	};

	// The one-based page the user is currently viewing, read from the same `paged`
	// URL parameter the native list table round-trips through; absent or junk means
	// the first page.
	const currentPage = () => {
		const paged = Number.parseInt( new URLSearchParams( window.location.search ).get( 'paged' ), 10 );
		return Number.isNaN( paged ) || paged < 1 ? 1 : paged;
	};

	// A list-table URL for the current view with `paged` set to a given page. Built
	// from the admin page's own location, so the rebuilt navigation links point back
	// at this screen — not at the REST endpoint the rows came from.
	const pageUrl = ( page ) => {
		const url = new URL( window.location.href );
		url.searchParams.set( 'paged', String( page ) );
		return url.pathname + url.search;
	};

	// One page-reach control: a live link when a page exists in that direction,
	// otherwise the inert, greyed-out span WordPress renders in its place.
	const navControl = ( kind, page, label, enabled ) => {
		if ( ! enabled ) {
			return `<span class="tablenav-pages-navspan button disabled" aria-hidden="true">${ navGlyphs[ kind ] }</span>`;
		}
		return `<a class="${ kind }-page button" href="${ pageUrl( page ) }"><span class="screen-reader-text">${ label }</span><span aria-hidden="true">${ navGlyphs[ kind ] }</span></a>`;
	};

	// The inner markup of a `.pagination-links` span — first / prev, the current-page
	// input, then next / last — mirroring the native WP_List_Table chrome so the
	// reach controls reflect where the current page sits in the new total.
	const paginationLinks = ( current, lastPage ) => {
		const i18n = cfg.i18n;
		const input = `<span class="paging-input"><label for="current-page-selector" class="screen-reader-text">${ i18n.currentPage }</label><input class="current-page" id="current-page-selector" type="text" name="paged" value="${ current }" size="${ String( lastPage ).length }" aria-describedby="table-paging"><span class="tablenav-paging-text"> ${ i18n.of } <span class="total-pages">${ lastPage }</span></span></span>`;
		return (
			navControl( 'first', 1, i18n.firstPage, current > 1 ) +
			navControl( 'prev', current - 1, i18n.prevPage, current > 1 ) +
			input +
			navControl( 'next', current + 1, i18n.nextPage, current < lastPage ) +
			navControl( 'last', lastPage, i18n.lastPage, current < lastPage )
		);
	};

	// Bring the native pagination chrome back in step with the server's authoritative
	// total after an in-place mutation: refresh the item count and rebuild the page
	// reach so a stale "N items", a wrong page count or a now-impossible next/prev
	// link never lingers. A single page drops the reach controls entirely, matching
	// how WordPress renders a one-page table.
	const updatePagination = ( total, perPage ) => {
		const lastPage = Math.max( 1, Math.ceil( total / perPage ) );
		const current = Math.min( currentPage(), lastPage );
		for ( const pages of document.querySelectorAll( '.tablenav-pages' ) ) {
			const count = pages.querySelector( '.displaying-num' );
			if ( count ) {
				count.textContent = count.textContent.replace( /[\d,]+/, String( total ) );
			}
			let links = pages.querySelector( '.pagination-links' );
			if ( lastPage < 2 ) {
				links?.remove();
				pages.classList.add( 'one-page' );
				continue;
			}
			if ( ! links ) {
				links = document.createElement( 'span' );
				links.className = 'pagination-links';
				pages.append( links );
			}
			links.innerHTML = paginationLinks( current, lastPage );
			pages.classList.remove( 'one-page' );
		}
	};

	// Swap in the server-rendered rows and reconcile the pagination chrome with the
	// total the same response reported.
	const apply = ( payload ) => {
		if ( ! payload || typeof payload.rows !== 'string' ) {
			return;
		}
		tbody.innerHTML = payload.rows;
		const perPage = Number.parseInt( payload.per_page, 10 ) || 0;
		if ( perPage > 0 ) {
			updatePagination( Number.parseInt( payload.total, 10 ) || 0, perPage );
		}
	};

	// Move to a corrected page by re-rendering it over the rows route. Reflect the
	// page in the URL first so the rebuilt links and any later mutation target it
	// too, then forward the current view's search and sort with it.
	const showPage = async ( page ) => {
		const url = new URL( window.location.href );
		url.searchParams.set( 'paged', String( page ) );
		window.history.replaceState( {}, '', url );
		apply( await request( withListQuery( cfg.rest + '/rows' ), 'GET' ) );
	};

	// Render a mutation's response. A mutation can leave the user past the last page
	// — deleting the only row on it is the canonical case — for which the server
	// returns an empty body; rather than show an empty table, fetch and show the new
	// last page instead.
	const render = async ( payload ) => {
		if ( ! payload || typeof payload.rows !== 'string' ) {
			return;
		}
		const total = Number.parseInt( payload.total, 10 ) || 0;
		const perPage = Number.parseInt( payload.per_page, 10 ) || 0;
		if ( perPage > 0 && total > 0 && currentPage() > Math.ceil( total / perPage ) ) {
			await showPage( Math.ceil( total / perPage ) );
			return;
		}
		apply( payload );
	};

	// Carry the list's current search, sort and page — the native query parameters
	// already in the page URL — onto a REST request, so the server re-renders the
	// very view the user is looking at instead of the default unfiltered first page.
	const withListQuery = ( url ) => {
		const current = new URLSearchParams( window.location.search );
		const forwarded = new URLSearchParams();
		for ( const key of [ 's', 'orderby', 'order', 'paged' ] ) {
			const value = current.get( key );
			if ( value ) {
				forwarded.set( key, value );
			}
		}
		const query = forwarded.toString();
		if ( ! query ) {
			return url;
		}
		return url + ( url.includes( '?' ) ? '&' : '?' ) + query;
	};

	const addButton = document.querySelector( '.kntnt-autolink-add' );
	if ( addButton ) {
		addButton.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			open( {} );
		} );
	}

	tbody.addEventListener( 'click', async ( event ) => {
		const edit = event.target.closest( '.kntnt-autolink-edit' );
		if ( edit ) {
			event.preventDefault();
			open( { ...edit.dataset } );
			return;
		}
		const del = event.target.closest( '.kntnt-autolink-delete' );
		if ( del ) {
			event.preventDefault();
			if ( ! window.confirm( cfg.i18n.confirmDelete ) ) {
				return;
			}
			try {
				await render( await request( withListQuery( cfg.rest + '/' + encodeURIComponent( del.dataset.id ) ), 'DELETE' ) );
			} catch ( error ) {
				window.console.error( error );
			}
		}
	} );

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const id = fields.id.value;
		const body = {
			phrases: fields.phrases.value,
			url: fields.url.value,
			cap: parseInt( fields.cap.value, 10 ) || 1,
			nofollow: fields.nofollow.checked,
			new_tab: fields.newTab.checked,
		};
		const url = withListQuery( id ? cfg.rest + '/' + encodeURIComponent( id ) : cfg.rest );
		try {
			await render( await request( url, id ? 'PUT' : 'POST', body ) );
			dialog.close();
		} catch ( error ) {
			window.console.error( error );
		}
	} );

	const cancel = dialog.querySelector( '.kntnt-autolink-cancel' );
	if ( cancel ) {
		cancel.addEventListener( 'click', () => dialog.close() );
	}

	// Bulk actions: enhance the native dropdown + Apply into REST calls so the
	// table re-renders without a full reload. Delete confirms inline; Set group
	// cap opens a small value modal that posts the new cap. Each bulk POST carries
	// the list's current search, sort and page, so the re-render reflects the very
	// view the user is looking at — exactly as a single-row mutation does.
	const capDialog = document.getElementById( 'kntnt-autolink-cap-modal' );
	const capForm = document.getElementById( 'kntnt-autolink-cap-form' );
	const capInput = capForm ? capForm.querySelector( '[name="cap"]' ) : null;
	const capMessage = document.getElementById( 'kntnt-autolink-cap-message' );
	let capIds = [];

	const selectedIds = () =>
		[ ...tbody.querySelectorAll( 'input[name="ids[]"]:checked' ) ].map( ( cb ) => cb.value );

	const bulkRequest = async ( body ) => {
		try {
			await render( await request( withListQuery( cfg.rest + '/bulk' ), 'POST', body ) );
		} catch ( error ) {
			window.console.error( error );
		}
	};

	const openCapModal = ( ids ) => {
		capIds = ids;
		if ( capInput ) {
			capInput.value = '1';
		}
		if ( capMessage ) {
			// Pick the singular or plural prompt so a single selection reads
			// "1 selected group", matching the no-JS _n() path.
			const template = ids.length === 1 ? cfg.i18n.setCapPromptSingular : cfg.i18n.setCapPromptPlural;
			capMessage.textContent = ( template || '' ).replace( '%d', String( ids.length ) );
		}
		if ( capDialog ) {
			capDialog.showModal();
		}
	};

	const runBulk = ( action ) => {
		const ids = selectedIds();
		if ( ids.length === 0 ) {
			return;
		}
		if ( action === 'delete' ) {
			if ( ! window.confirm( cfg.i18n.confirmBulkDelete ) ) {
				return;
			}
			bulkRequest( { action: 'delete', ids } );
		} else if ( action === 'set-cap' ) {
			openCapModal( ids );
		}
	};

	document.querySelectorAll( '#doaction, #doaction2' ).forEach( ( button ) => {
		button.addEventListener( 'click', ( event ) => {
			const selector = button.id === 'doaction' ? '#bulk-action-selector-top' : '#bulk-action-selector-bottom';
			const select = document.querySelector( selector );
			const action = select ? select.value : '-1';
			if ( action === 'delete' || action === 'set-cap' ) {
				event.preventDefault();
				runBulk( action );
			}
		} );
	} );

	if ( capForm ) {
		capForm.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			const cap = parseInt( capInput.value, 10 ) || 1;
			bulkRequest( { action: 'set-cap', ids: capIds, cap } );
			if ( capDialog ) {
				capDialog.close();
			}
		} );
	}

	const capCancel = capDialog ? capDialog.querySelector( '.kntnt-autolink-cap-cancel' ) : null;
	if ( capCancel ) {
		capCancel.addEventListener( 'click', () => capDialog.close() );
	}
} )();
