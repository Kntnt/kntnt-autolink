/**
 * Kntnt Autolink — reusable chip input.
 *
 * Progressive enhancement (ADR-0001, no build step) over a server-rendered
 * textarea. Each container marked `[data-kntnt-autolink-chips]` wraps a textarea
 * that, without JavaScript, posts the field's comma/newline string under the
 * option key and is the complete no-JS control. This script upgrades it in
 * place: it hides the textarea and removes its `name` so it stops submitting,
 * renders the current values as removable chips, and keeps one hidden input per
 * chip (`name="<option>[<key>][]"`) so the enhanced path posts an array. The
 * server sanitiser accepts both the array and the string, so the page saves the
 * same either way.
 *
 * Two entry modes:
 *   - `closed` — a fixed-option <select> built from the inline JSON option set;
 *     only listed tokens can be added, so an invalid value is impossible.
 *   - `free` — a text input; Enter or comma commits the typed value.
 *
 * Extension seam (issue #5, term-targeting autocomplete): a widget may carry a
 * `data-suggest` attribute naming an async suggestion source registered through
 * `window.kntntAutolinkChips.registerSource(name, async (query) => [...])`. When
 * present in `free` mode, the script debounces input and offers the resolved
 * suggestions; absent, `free` mode stays plain text. No source ships here.
 */
( () => {
	'use strict';

	// Public registry of async suggestion sources, for later autocomplete (#5).
	const sources = new Map();
	window.kntntAutolinkChips = window.kntntAutolinkChips || {
		registerSource( name, fn ) {
			sources.set( name, fn );
		},
	};

	/**
	 * Split a textarea's raw value into trimmed, de-duplicated tokens.
	 */
	const parseTokens = ( raw ) => {
		const seen = new Set();
		return raw
			.split( /[\r\n,]+/ )
			.map( ( token ) => token.trim() )
			.filter( ( token ) => {
				if ( token === '' || seen.has( token.toLowerCase() ) ) {
					return false;
				}
				seen.add( token.toLowerCase() );
				return true;
			} );
	};

	/**
	 * Read the inline JSON option set (token => label) for a closed widget.
	 */
	const readOptions = ( container ) => {
		const script = container.querySelector( '.kntnt-autolink-chips__options' );
		if ( ! script ) {
			return {};
		}
		try {
			return JSON.parse( script.textContent || '{}' );
		} catch ( e ) {
			return {};
		}
	};

	/**
	 * Enhance one chip container.
	 */
	const enhance = ( container ) => {
		const textarea = container.querySelector( '.kntnt-autolink-chips__input' );
		if ( ! textarea ) {
			return;
		}

		const key = container.dataset.key || '';
		const mode = container.dataset.mode === 'closed' ? 'closed' : 'free';
		const name = container.dataset.name || '';
		const placeholder = container.dataset.placeholder || '';
		const options = mode === 'closed' ? readOptions( container ) : {};

		// The textarea becomes the JS path's silent state: hidden and nameless so
		// only the hidden chip inputs submit.
		const values = parseTokens( textarea.value );
		textarea.hidden = true;
		textarea.removeAttribute( 'name' );
		textarea.setAttribute( 'aria-hidden', 'true' );
		textarea.tabIndex = -1;

		const list = document.createElement( 'ul' );
		list.className = 'kntnt-autolink-chips__list';

		const hidden = document.createElement( 'div' );
		hidden.className = 'kntnt-autolink-chips__hidden';
		hidden.hidden = true;

		// Build the entry control: a <select> of unused options (closed) or a text
		// input (free).
		const entry =
			mode === 'closed'
				? document.createElement( 'select' )
				: document.createElement( 'input' );
		entry.className = 'kntnt-autolink-chips__entry';
		if ( mode === 'free' ) {
			entry.type = 'text';
			entry.placeholder = placeholder;
		}

		const labelFor = ( token ) => options[ token ] || token;

		// Rebuild the hidden inputs that actually submit, one per current value.
		const syncHidden = () => {
			hidden.textContent = '';
			values.forEach( ( token ) => {
				const input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = `${ name }[]`;
				input.value = token;
				hidden.appendChild( input );
			} );
		};

		// In closed mode the entry <select> offers only not-yet-chosen options.
		const refreshEntry = () => {
			if ( mode !== 'closed' ) {
				return;
			}
			entry.textContent = '';
			const placeholderOption = document.createElement( 'option' );
			placeholderOption.value = '';
			placeholderOption.textContent = placeholder;
			entry.appendChild( placeholderOption );
			Object.keys( options ).forEach( ( token ) => {
				if ( values.includes( token ) ) {
					return;
				}
				const option = document.createElement( 'option' );
				option.value = token;
				option.textContent = options[ token ];
				entry.appendChild( option );
			} );
			entry.value = '';
		};

		const renderChips = () => {
			list.textContent = '';
			values.forEach( ( token ) => {
				const chip = document.createElement( 'li' );
				chip.className = 'kntnt-autolink-chips__chip';

				const text = document.createElement( 'span' );
				text.className = 'kntnt-autolink-chips__label';
				text.textContent = labelFor( token );
				chip.appendChild( text );

				const remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'kntnt-autolink-chips__remove';
				remove.setAttribute( 'aria-label', `Remove ${ labelFor( token ) }` );
				remove.textContent = '×';
				remove.addEventListener( 'click', () => removeToken( token ) );
				chip.appendChild( remove );

				list.appendChild( chip );
			} );
		};

		const render = () => {
			renderChips();
			refreshEntry();
			syncHidden();
		};

		const addToken = ( raw ) => {
			const token = raw.trim();
			if ( token === '' ) {
				return;
			}
			if ( values.some( ( existing ) => existing.toLowerCase() === token.toLowerCase() ) ) {
				return;
			}
			// Closed mode admits only listed options; an unknown token is ignored.
			if ( mode === 'closed' && ! Object.prototype.hasOwnProperty.call( options, token ) ) {
				return;
			}
			values.push( token );
			render();
		};

		function removeToken( token ) {
			const index = values.indexOf( token );
			if ( index !== -1 ) {
				values.splice( index, 1 );
				render();
			}
		}

		if ( mode === 'closed' ) {
			entry.addEventListener( 'change', () => {
				if ( entry.value !== '' ) {
					addToken( entry.value );
				}
			} );
		} else {
			entry.addEventListener( 'keydown', ( event ) => {
				if ( event.key === 'Enter' || event.key === ',' ) {
					event.preventDefault();
					addToken( entry.value );
					entry.value = '';
				}
			} );
			entry.addEventListener( 'blur', () => {
				addToken( entry.value );
				entry.value = '';
			} );
		}

		const control = document.createElement( 'div' );
		control.className = 'kntnt-autolink-chips__control';
		control.appendChild( list );
		control.appendChild( entry );

		container.appendChild( control );
		container.appendChild( hidden );
		container.classList.add( 'is-enhanced' );

		render();
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		document
			.querySelectorAll( '[data-kntnt-autolink-chips]' )
			.forEach( ( container ) => enhance( container ) );
	} );
} )();
