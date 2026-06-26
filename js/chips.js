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
	 * Turn a free-mode entry into an autocomplete backed by an async source. The
	 * source receives the typed query and the chip container (so it can read e.g.
	 * `data-taxonomy`) and resolves to a list of `{ value, label }`. Picking a
	 * suggestion records its label so the chip reads by name, and commits its value
	 * — the real id — as the submitted token. Raw typed text is never committed, so
	 * only resolved suggestions become chips.
	 */
	const attachAutocomplete = ( container, control, entry, values, options, addToken, source ) => {
		const box = document.createElement( 'ul' );
		box.className = 'kntnt-autolink-chips__suggestions';
		box.hidden = true;
		control.appendChild( box );

		let items = [];
		let active = -1;
		let timer = null;

		const close = () => {
			box.hidden = true;
			box.textContent = '';
			items = [];
			active = -1;
		};

		const pick = ( item ) => {
			if ( ! item || item.value === undefined || item.value === null ) {
				return;
			}
			const value = String( item.value );
			options[ value ] = item.label === undefined || item.label === null ? value : String( item.label );
			addToken( value );
			entry.value = '';
			close();
		};

		const highlight = () => {
			[ ...box.children ].forEach( ( li, index ) => {
				li.classList.toggle( 'is-active', index === active );
			} );
		};

		const show = ( results ) => {
			items = ( Array.isArray( results ) ? results : [] ).filter(
				( item ) => item && item.value !== undefined && item.value !== null && ! values.includes( String( item.value ) ),
			);
			active = -1;
			box.textContent = '';
			if ( items.length === 0 ) {
				close();
				return;
			}
			items.forEach( ( item ) => {
				const li = document.createElement( 'li' );
				li.className = 'kntnt-autolink-chips__suggestion';
				li.textContent = item.label === undefined || item.label === null ? String( item.value ) : String( item.label );
				// mousedown (not click) so the pick lands before the entry's blur closes the box.
				li.addEventListener( 'mousedown', ( event ) => {
					event.preventDefault();
					pick( item );
				} );
				box.appendChild( li );
			} );
			box.hidden = false;
		};

		const query = async () => {
			const q = entry.value.trim();
			if ( q === '' ) {
				close();
				return;
			}
			try {
				show( await source( q, container ) );
			} catch ( e ) {
				close();
			}
		};

		entry.setAttribute( 'autocomplete', 'off' );
		entry.addEventListener( 'input', () => {
			window.clearTimeout( timer );
			timer = window.setTimeout( query, 200 );
		} );
		entry.addEventListener( 'keydown', ( event ) => {
			if ( box.hidden ) {
				// Never commit raw typed text in suggest mode — only resolved suggestions
				// (real ids) may become chips; swallow Enter so the form is not submitted.
				if ( event.key === 'Enter' ) {
					event.preventDefault();
				}
				return;
			}
			if ( event.key === 'ArrowDown' ) {
				event.preventDefault();
				active = Math.min( active + 1, items.length - 1 );
				highlight();
			} else if ( event.key === 'ArrowUp' ) {
				event.preventDefault();
				active = Math.max( active - 1, 0 );
				highlight();
			} else if ( event.key === 'Enter' ) {
				event.preventDefault();
				pick( items[ active >= 0 ? active : 0 ] );
			} else if ( event.key === 'Escape' ) {
				close();
			}
		} );
		// Close after a tick so a suggestion's mousedown can land first.
		entry.addEventListener( 'blur', () => window.setTimeout( close, 150 ) );
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
		// Always read the inline option set: in closed mode it is the fixed selector,
		// in free mode it is the display labels for the initial tokens (e.g. term names
		// for term ids). Absent, it is simply empty.
		const options = readOptions( container );

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

		// The async suggestion source named by data-suggest, when one is registered.
		// Present in free mode it upgrades the entry to an autocomplete.
		const suggestName = container.dataset.suggest || '';
		const source = mode === 'free' && suggestName && sources.has( suggestName ) ? sources.get( suggestName ) : null;

		const control = document.createElement( 'div' );
		control.className = 'kntnt-autolink-chips__control';
		control.appendChild( list );
		control.appendChild( entry );

		if ( mode === 'closed' ) {
			entry.addEventListener( 'change', () => {
				if ( entry.value !== '' ) {
					addToken( entry.value );
				}
			} );
		} else if ( source ) {
			attachAutocomplete( container, control, entry, values, options, addToken, source );
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

		container.appendChild( control );
		container.appendChild( hidden );
		container.classList.add( 'is-enhanced' );

		render();
	};

	// Expose the in-place enhancer so the term-targeting controller can upgrade the
	// chip widget of a row it adds after the initial DOMContentLoaded sweep.
	window.kntntAutolinkChips.enhance = enhance;

	document.addEventListener( 'DOMContentLoaded', () => {
		document
			.querySelectorAll( '[data-kntnt-autolink-chips]' )
			.forEach( ( container ) => enhance( container ) );
	} );
} )();
