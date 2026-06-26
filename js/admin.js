/**
 * Kntnt Autolink admin: the link-group add/edit modal and REST plumbing.
 *
 * Progressive enhancement over the server-rendered WP_List_Table — a single
 * <dialog> shared by add and edit talks to the kntnt-autolink/v1 REST API for
 * mutations; on success the table body is replaced with the server-rendered
 * rows returned in the JSON, so there is no second row renderer here.
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

	const render = ( payload ) => {
		if ( payload && typeof payload.rows === 'string' ) {
			tbody.innerHTML = payload.rows;
		}
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
				render( await request( cfg.rest + '/' + encodeURIComponent( del.dataset.id ), 'DELETE' ) );
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
		const url = id ? cfg.rest + '/' + encodeURIComponent( id ) : cfg.rest;
		try {
			render( await request( url, id ? 'PUT' : 'POST', body ) );
			dialog.close();
		} catch ( error ) {
			window.console.error( error );
		}
	} );

	const cancel = dialog.querySelector( '.kntnt-autolink-cancel' );
	if ( cancel ) {
		cancel.addEventListener( 'click', () => dialog.close() );
	}
} )();
