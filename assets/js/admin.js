/**
 * Log Broom: Tools > Log Broom screen.
 *
 * Selection, the backup confirmation dialog, batched cleaning with progress, and automatic cleaning settings.
 * Plain jQuery + wp.i18n, no build step.
 *
 * @package Log_Broom
 */

/* global jQuery, wp, plcleanAdmin */
( function ( $, wp ) {
	'use strict';

	const { __, _n, _x, sprintf } = wp.i18n;
	const data = window.plcleanAdmin || {};

	const $app = $( '#plclean-app' );
	if ( ! $app.length ) {
		return;
	}
	$app.removeClass( 'no-js' );

	const $selection = $( '#plclean-selection' );
	const $cleanSelected = $( '#plclean-clean-selected' );
	const $schedule = $( '#plclean-schedule' );
	const $status = $( '#plclean-schedule-status' );
	const $live = $( '#plclean-live' );
	const $toast = $( '#plclean-toast' );
	const dialog = document.getElementById( 'plclean-dialog' );
	const $dialog = $( dialog );

	const motion = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : { matches: false };

	/** Saved batch settings: { mode: 'auto'|'fixed', size }. */
	const batch = $.extend( { mode: 'auto', size: 5000 }, data.batch || {} );

	/** Sources by id: { id, label, kept, count, size, ... }. `count` and `size` are null until the source is counted. */
	const sources = {};
	( data.sources || [] ).forEach( ( source ) => {
		sources[ source.id ] = $.extend( {}, source );
	} );

	/* ---------------------------------------------------------------------------------------------
	 * Formatting
	 * ------------------------------------------------------------------------------------------- */

	const locale = document.documentElement.lang || undefined;
	let numberFormat;
	try {
		numberFormat = new Intl.NumberFormat( locale );
	} catch ( e ) {
		numberFormat = new Intl.NumberFormat();
	}

	/**
	 * Format an integer for display ("2,713,447").
	 *
	 * @param {number} n Number.
	 * @return {string} Formatted number.
	 */
	function fmt( n ) {
		return numberFormat.format( Math.max( 0, Math.round( n ) ) );
	}

	/**
	 * Format bytes like core's size_format() ("180 MB").
	 *
	 * @param {number} bytes Bytes.
	 * @return {string} Human-readable size.
	 */
	function formatBytes( bytes ) {
		const units = [
			_x( 'B', 'unit symbol: bytes', 'log-broom' ),
			_x( 'KB', 'unit symbol: kilobytes', 'log-broom' ),
			_x( 'MB', 'unit symbol: megabytes', 'log-broom' ),
			_x( 'GB', 'unit symbol: gigabytes', 'log-broom' ),
			_x( 'TB', 'unit symbol: terabytes', 'log-broom' ),
		];
		let value = Math.max( 0, bytes );
		let unit = 0;
		while ( value >= 1024 && unit < units.length - 1 ) {
			value /= 1024;
			unit++;
		}
		/* translators: 1: number, 2: unit symbol, e.g. "180 MB". */
		return sprintf( __( '%1$s %2$s', 'log-broom' ), fmt( value ), units[ unit ] );
	}

	/**
	 * A Dashicon element (decorative).
	 *
	 * @param {string} name Icon name without the "dashicons-" prefix.
	 * @return {jQuery} Icon.
	 */
	function icon( name ) {
		return $( '<span class="dashicons" aria-hidden="true"></span>' ).addClass( 'dashicons-' + name );
	}

	/**
	 * Icon and label for a button: the label is wrapped so the pair can be centred on one line.
	 *
	 * @param {string} name Icon name without the "dashicons-" prefix.
	 * @param {string} text Label.
	 * @return {jQuery[]} Icon and label.
	 */
	function iconLabel( name, text ) {
		return [ icon( name ), $( '<span class="plclean-button-label"></span>' ).text( text ) ];
	}

	/* ---------------------------------------------------------------------------------------------
	 * Number animation (static under prefers-reduced-motion)
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Animate an element's number from its current value to `to`.
	 *
	 * @param {jQuery}   $el      Element whose text is a number.
	 * @param {number}   to       Target value.
	 * @param {number}   duration Milliseconds.
	 * @param {number}   [from]   Start value (defaults to the element's stored value).
	 * @param {Function} [render] Called with ( el, value ) for every value shown, instead of setting the text, so
	 *                            the words around the number can follow it (singular / plural).
	 */
	function animateNumber( $el, to, duration, from, render ) {
		$el.each( function () {
			const el = this;
			const show = ( value ) => {
				if ( render ) {
					render( el, Math.max( 0, Math.round( value ) ) );
				} else {
					el.textContent = fmt( value );
				}
			};
			if ( el.plcleanFrame ) {
				window.cancelAnimationFrame( el.plcleanFrame );
			}
			const start = undefined !== from ? from : ( undefined !== el.plcleanValue ? el.plcleanValue : to );
			el.plcleanValue = to;
			if ( motion.matches || start === to || ! window.requestAnimationFrame ) {
				show( to );
				return;
			}
			const began = window.performance.now();
			const step = ( now ) => {
				const t = Math.min( 1, ( now - began ) / duration );
				const eased = 1 - Math.pow( 1 - t, 3 ); // ease-out
				show( start + ( to - start ) * eased );
				el.plcleanFrame = t < 1 ? window.requestAnimationFrame( step ) : 0;
			};
			el.plcleanFrame = window.requestAnimationFrame( step );
		} );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Live region and toast
	 * ------------------------------------------------------------------------------------------- */

	let lastAnnounce = 0;

	/**
	 * Announce through the aria-live region, at most about every 2 seconds unless forced.
	 *
	 * @param {string}  text  Message.
	 * @param {boolean} force Announce now.
	 */
	function announce( text, force ) {
		const now = Date.now();
		if ( ! force && now - lastAnnounce < 2000 ) {
			return;
		}
		lastAnnounce = now;
		$live.text( text );
	}

	let toastTimer = 0;

	/**
	 * Small inline toast that dismisses itself after 4 seconds.
	 *
	 * @param {string}  text    Message.
	 * @param {boolean} isError Error style.
	 */
	function toast( text, isError ) {
		window.clearTimeout( toastTimer );
		$toast
			.empty()
			.toggleClass( 'is-error', !! isError )
			.append( icon( isError ? 'warning' : 'yes-alt' ), $( '<span>' ).text( text ) )
			.prop( 'hidden', false )
			.removeClass( 'is-leaving' );
		toastTimer = window.setTimeout( () => {
			$toast.addClass( 'is-leaving' );
			toastTimer = window.setTimeout( () => $toast.prop( 'hidden', true ).removeClass( 'is-leaving' ), motion.matches ? 0 : 150 );
		}, 4000 );
	}

	/* ---------------------------------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Classify a failed request.
	 *
	 * `kind` is one of:
	 * - 'session': the nonce check failed (core answers `-1`, HTTP 403), or the user is no longer logged in
	 *   (admin-ajax.php answers `0`, HTTP 400): the page must be reloaded;
	 * - 'unavailable': the source can't be cleaned here: it is no longer on this site (HTTP 400, code
	 *   `unavailable`), or it is network-wide and only a network administrator may clean it (HTTP 403, code
	 *   `network_only`);
	 * - 'message': the server explained what went wrong (JSON message);
	 * - 'timeout': no answer, or an HTTP 5xx error without a JSON message;
	 * - 'unknown': anything else.
	 *
	 * @param {number} status HTTP status (0 when there was no response).
	 * @param {*}      json   Parsed response body, if any.
	 * @param {string} text   Raw response body, if any.
	 * @return {Error} Error with `kind` and `code`; `message` is the text to show.
	 */
	function requestError( status, json, text ) {
		const payload = json && 'object' === typeof json && json.data && 'object' === typeof json.data ? json.data : {};
		const message = payload.message ? String( payload.message ) : '';
		const code = payload.code ? String( payload.code ) : '';
		const body = 'string' === typeof text ? text.trim() : '';
		let kind = 'unknown';
		if ( -1 === json || '-1' === body || 0 === json || '0' === body || ( 403 === status && ! message ) ) {
			kind = 'session';
		} else if ( 'unavailable' === code || 'network_only' === code ) {
			kind = 'unavailable';
		} else if ( message ) {
			kind = 'message';
		} else if ( 0 === status || status >= 500 ) {
			kind = 'timeout';
		}
		const error = new Error( 'session' === kind ? __( 'Your session expired. Reload the page and try again.', 'log-broom' ) : message );
		error.kind = kind;
		error.code = code;
		return error;
	}

	/**
	 * POST to admin-ajax.php. Resolves with `data` on success, rejects with an Error from requestError() otherwise.
	 *
	 * @param {string} action AJAX action.
	 * @param {Object} fields Extra fields.
	 * @return {Promise<Object>} Response data.
	 */
	function post( action, fields ) {
		return new Promise( ( resolve, reject ) => {
			$.ajax( {
				url: data.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				timeout: 120000,
				data: $.extend( { action, nonce: data.nonce }, fields ),
			} )
				.done( ( response, textStatus, xhr ) => {
					if ( response && response.success ) {
						resolve( response.data || {} );
					} else {
						reject( requestError( xhr.status, response, xhr.responseText ) );
					}
				} )
				.fail( ( xhr ) => {
					let json = xhr.responseJSON;
					if ( undefined === json ) {
						try {
							json = JSON.parse( xhr.responseText );
						} catch ( e ) {
							json = null;
						}
					}
					reject( requestError( xhr.status, json, xhr.responseText ) );
				} );
		} );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Cards, selection, summary
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Whether a source's count is known.
	 *
	 * @param {string} id Rule id.
	 * @return {boolean} Counted.
	 */
	function isCounted( id ) {
		return !! sources[ id ] && null !== sources[ id ].count && undefined !== sources[ id ].count;
	}

	/**
	 * Ids of the selected sources, in page order.
	 *
	 * @return {string[]} Rule ids.
	 */
	function selectedIds() {
		return $app.find( '.plclean-card-select:checked' ).map( ( i, el ) => el.value ).get();
	}

	/**
	 * Total entries of the given sources.
	 *
	 * @param {string[]} ids Rule ids.
	 * @return {number} Entries.
	 */
	function entriesOf( ids ) {
		return ids.reduce( ( sum, id ) => sum + ( sources[ id ] && isCounted( id ) ? sources[ id ].count : 0 ), 0 );
	}

	/** Refresh the sticky bar and the selected state of the cards. */
	function updateSelection() {
		const ids = selectedIds();
		const entries = entriesOf( ids );
		$selection.text(
			sprintf(
				/* translators: 1: number of selected sources, 2: number of entries. */
				_n( '%1$s selected · %2$s entry', '%1$s selected · %2$s entries', entries, 'log-broom' ),
				fmt( ids.length ),
				fmt( entries )
			)
		);
		$cleanSelected.prop( 'disabled', 0 === ids.length );
		$app.find( '.plclean-card-select' ).each( ( i, el ) => {
			$( el ).closest( '.plclean-source' ).toggleClass( 'is-selected', el.checked );
		} );
		$app.find( '.plclean-actionbar' ).toggleClass( 'has-selection', ids.length > 0 );
	}

	/**
	 * The DOM parts of one source card.
	 *
	 * @param {string} id Rule id.
	 * @return {Object} jQuery elements.
	 */
	function cardParts( id ) {
		const $source = $app.find( '.plclean-source[data-rule="' + id + '"]' );
		return {
			$source,
			$card: $source.find( '.plclean-card' ),
			$select: $source.find( '.plclean-card-select' ),
			$count: $source.find( '.plclean-card-count' ),
			$size: $source.find( '.plclean-card-size' ),
			$one: $source.find( '.plclean-clean-one' ),
		};
	}

	/**
	 * Reflect a source's current count (animated) and clean state on its card.
	 *
	 * @param {string} id Rule id.
	 */
	function renderCard( id ) {
		const source = sources[ id ];
		const parts = cardParts( id );
		if ( source.parts ) {
			showCountParts( parts.$count, source );
		} else {
			animateNumber( parts.$count, source.count, 400 );
		}
		parts.$card.attr( 'data-count', source.count );
		if ( 0 === source.count ) {
			parts.$source.addClass( 'is-clean' );
			parts.$select.prop( { checked: false, disabled: true } );
			parts.$one.prop( 'disabled', true );
		}
	}

	/**
	 * Read the parts of a count from a server answer (`parts`: { tasks, logs }), or null.
	 *
	 * @param {*} value Answer field.
	 * @return {?Object} Parts.
	 */
	function readParts( value ) {
		if ( ! value || 'object' !== typeof value || undefined === value.tasks || undefined === value.logs ) {
			return null;
		}
		return {
			tasks: Math.max( 0, parseInt( value.tasks, 10 ) || 0 ),
			logs: Math.max( 0, parseInt( value.logs, 10 ) || 0 ),
		};
	}

	/**
	 * Show a count in its parts on the card face: "2,000,000 tasks + 4,000,000 log entries".
	 *
	 * @param {jQuery} $count The card's count element.
	 * @param {Object} source Source with `parts`.
	 */
	function showCountParts( $count, source ) {
		$count.each( function () {
			if ( this.plcleanFrame ) {
				window.cancelAnimationFrame( this.plcleanFrame );
			}
			this.plcleanValue = source.count;
		} );
		$count.addClass( 'has-parts' ).text(
			sprintf(
				/* translators: 1: number of tasks, e.g. "2,000,000 tasks", 2: number of log entries, e.g. "4,000,000 log entries". */
				__( '%1$s + %2$s', 'log-broom' ),
				/* translators: %s: number of scheduled tasks. */
				sprintf( _n( '%s task', '%s tasks', source.parts.tasks, 'log-broom' ), fmt( source.parts.tasks ) ),
				/* translators: %s: number of log entries. */
				sprintf( _n( '%s log entry', '%s log entries', source.parts.logs, 'log-broom' ), fmt( source.parts.logs ) )
			)
		);
	}

	/**
	 * Bar width (0-100) for a source: its entries to clean relative to the largest source. Same formula as
	 * PLCLEAN_Admin::bar_percent() on the server.
	 *
	 * @param {number} count    Entries in the source.
	 * @param {number} maxCount Entries in the largest available source.
	 * @return {number} Percent.
	 */
	function barPercent( count, maxCount ) {
		return count > 0 && maxCount > 0 ? Math.round( ( 100 * count ) / maxCount ) : 0;
	}

	/** Recompute the entry bars (and the size hints) on all cards from the entries left to clean. */
	function renderSizes() {
		const maxCount = Object.keys( sources ).reduce( ( m, id ) => Math.max( m, sources[ id ].available && isCounted( id ) ? sources[ id ].count : 0 ), 0 );
		Object.keys( sources ).forEach( ( id ) => {
			const parts = cardParts( id );
			if ( ! parts.$source.length ) {
				return;
			}
			if ( ! isCounted( id ) ) {
				parts.$size.text( '' );
				parts.$source.find( '.plclean-bar-fill' ).css( 'width', '0%' );
				return;
			}
			// A cleaned table keeps its disk space until it is optimized: its card shows no size, only "Nothing to clean".
			parts.$size.text( sources[ id ].size > 0 ? formatBytes( sources[ id ].size ) : '—' );
			parts.$source.find( '.plclean-bar-fill' ).css( 'width', barPercent( sources[ id ].count, maxCount ) + '%' );
		} );
	}

	/**
	 * Show `n` in the summary's "N entries" line, with the whole text from _n() so singular and plural always
	 * match the number shown. The number element is kept (it holds the animation state); only the text around it
	 * is replaced.
	 *
	 * @param {HTMLElement} strong The `.plclean-summary-count-value` element.
	 * @param {number}      n      Entries.
	 */
	function renderSummaryCount( strong, n ) {
		const line = strong.parentNode;
		if ( ! line ) {
			return;
		}
		/* translators: %s: number of entries. */
		const parts = _n( '%s entry', '%s entries', n, 'log-broom' ).split( /%(?:1\$)?s/ );
		Array.prototype.slice.call( line.childNodes ).forEach( ( node ) => {
			if ( node !== strong ) {
				line.removeChild( node );
			}
		} );
		strong.textContent = fmt( n );
		line.insertBefore( document.createTextNode( parts[ 0 ] ), strong );
		line.appendChild( document.createTextNode( parts.slice( 1 ).join( '' ) ) );
	}

	/**
	 * Show `size` in the summary's "X of log data found" line, or just "Log data" when there is nothing to clean.
	 *
	 * @param {number} size Bytes.
	 */
	function renderSummarySize( size ) {
		const $line = $app.find( '.plclean-summary-size' );
		if ( size <= 0 ) {
			// Nothing (known) to clean: no "X of log data found" claim.
			$line.empty().append( $( '<strong>' ).text( __( 'Log data', 'log-broom' ) ) );
			return;
		}
		/* translators: %s: data size, e.g. "1.4 GB". */
		const parts = __( '%s of log data found', 'log-broom' ).split( /%(?:1\$)?s/ );
		$line.empty().append(
			document.createTextNode( parts[ 0 ] ),
			$( '<strong class="plclean-summary-size-value"></strong>' ).text( formatBytes( size ) ),
			document.createTextNode( parts.slice( 1 ).join( '' ) )
		);
	}

	/**
	 * Refresh the summary card from the sources counted so far. The totals grow as counts arrive; the empty state
	 * appears only once every source is counted and none has anything to clean.
	 */
	function renderSummary() {
		let count = 0;
		let size = 0;
		let counted = 0;
		Object.keys( sources ).forEach( ( id ) => {
			if ( ! sources[ id ].available || ! isCounted( id ) ) {
				return;
			}
			counted++;
			if ( sources[ id ].count > 0 ) {
				count += sources[ id ].count;
				size += sources[ id ].size;
			}
		} );
		const busy = counting.pending() > 0;
		const $summary = $app.find( '.plclean-summary' );
		$summary.attr( 'aria-busy', busy ? 'true' : 'false' );
		if ( counted > 0 ) {
			animateNumber( $app.find( '.plclean-summary-count-value' ), count, 600, undefined, renderSummaryCount );
		} else if ( ! busy ) {
			// No source could be counted.
			$app.find( '.plclean-summary-count-value' ).text( '—' );
		}
		renderSummarySize( size );
		$summary.attr( { 'data-count': count, 'data-size': size } );

		const allCounted = ! busy && counted === Object.keys( sources ).filter( ( id ) => sources[ id ].available ).length;
		if ( allCounted && 0 === count ) {
			if ( ! $( '#plclean-empty' ).length ) {
				$( '<p class="plclean-empty" id="plclean-empty"></p>' )
					.append( icon( 'yes-alt' ), ' ', document.createTextNode( __( 'Your database is tidy. None of the supported sources have anything to clean right now.', 'log-broom' ) ) )
					.insertAfter( $summary );
			}
		} else if ( count > 0 ) {
			$( '#plclean-empty' ).remove();
		}
	}

	/**
	 * Show a short inline result on the page (kept after the dialog closes).
	 *
	 * @param {string} text Result text.
	 */
	function pageResult( text ) {
		let $result = $( '#plclean-result' );
		if ( ! $result.length ) {
			$result = $( '<div class="plclean-result" id="plclean-result"></div>' ).insertAfter( $app.find( '.plclean-summary' ) );
		}
		$result.empty().append( icon( 'yes-alt' ), $( '<p>' ).text( text ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Counting: each source's count and size are asked for after the page opened, at most two at a time
	 * ------------------------------------------------------------------------------------------- */

	const COUNT_CONCURRENCY = 2;

	const counting = {
		queue: [], // Rule ids waiting for a request.
		active: 0, // Requests in flight.
		pending() {
			return this.queue.length + this.active;
		},
	};

	/**
	 * Put a card in its "Counting…" state.
	 *
	 * @param {string} id Rule id.
	 */
	function showCounting( id ) {
		const parts = cardParts( id );
		parts.$source.removeClass( 'is-count-error is-clean' ).addClass( 'is-counting' );
		parts.$source.find( '.plclean-card-stats' ).attr( 'aria-busy', 'true' );
		// Retry pressed: keep the keyboard focus on the card while the button is replaced by "Counting…".
		const refocus = parts.$source.find( '.plclean-count-retry' ).is( document.activeElement );
		parts.$source.find( '.plclean-count-error' ).remove();
		parts.$count.removeClass( 'has-parts' ).text( __( 'Counting…', 'log-broom' ) ).prop( 'hidden', false );
		if ( refocus ) {
			parts.$count.attr( 'tabindex', '-1' ).trigger( 'focus' );
		}
		parts.$select.prop( { checked: false, disabled: true } );
		parts.$one.prop( 'disabled', true );
	}

	/**
	 * Fill in a source's Details table sizes.
	 *
	 * @param {string} id     Rule id.
	 * @param {Array}  tables Tables from plclean_stats: { name, size, size_human }.
	 */
	function renderTables( id, tables ) {
		const $list = cardParts( id ).$source.find( '.plclean-tables' );
		let sized = false;
		( Array.isArray( tables ) ? tables : [] ).forEach( ( table ) => {
			const human = table && 'string' === typeof table.size_human ? table.size_human : '';
			$list.children( '.plclean-table' ).each( function () {
				if ( ! table || this.getAttribute( 'data-table' ) !== String( table.name ) ) {
					return;
				}
				// Shared core tables have no size of their own to show (only some of their rows are cleaned).
				$( this ).find( '.plclean-table-size' ).remove();
				if ( '' !== human ) {
					$( '<span class="plclean-table-size"></span>' ).text( human ).appendTo( this );
				}
			} );
			sized = sized || '' !== human;
		} );
		$list.children( '.plclean-tables-head' ).prop( 'hidden', ! sized );
	}

	/**
	 * A source was counted: show its numbers and let it be selected (when it has anything to clean).
	 *
	 * @param {string} id    Rule id.
	 * @param {Object} stats Response of plclean_stats.
	 */
	function showCounted( id, stats ) {
		const source = sources[ id ];
		const parts = cardParts( id );
		const hadFocus = parts.$count.is( document.activeElement );
		parts.$count.removeAttr( 'tabindex' );
		source.count = Math.max( 0, parseInt( stats.count, 10 ) || 0 );
		source.size = Math.max( 0, parseInt( stats.size, 10 ) || 0 );
		parts.$source.removeClass( 'is-counting is-count-error' ).toggleClass( 'is-clean', 0 === source.count );
		parts.$source.find( '.plclean-card-stats' ).attr( 'aria-busy', 'false' );
		parts.$source.find( '.plclean-count-error' ).remove();
		parts.$card.attr( { 'data-count': source.count, 'data-size': source.size } );
		source.parts = readParts( stats.parts );
		parts.$count.prop( 'hidden', false );
		if ( source.parts ) {
			showCountParts( parts.$count, source );
		} else {
			parts.$count.each( function () {
				if ( this.plcleanFrame ) {
					window.cancelAnimationFrame( this.plcleanFrame );
				}
				this.plcleanValue = source.count;
				this.textContent = fmt( source.count );
			} );
		}
		parts.$select.prop( 'disabled', 0 === source.count );
		parts.$one.prop( 'disabled', 0 === source.count );
		if ( 0 === source.count ) {
			// Nothing to clean: no size on the card's face (Details still lists the tables' sizes).
			parts.$size.remove();
		}
		renderTables( id, stats.tables );
		if ( hadFocus ) {
			( parts.$select.prop( 'disabled' ) ? parts.$source.find( '.plclean-details-toggle' ) : parts.$select ).trigger( 'focus' );
		}
	}

	/**
	 * A count failed: "Couldn't count — Retry". The source stays unselectable until it is counted.
	 *
	 * @param {string} id Rule id.
	 */
	function showCountError( id ) {
		const parts = cardParts( id );
		const hadFocus = parts.$count.is( document.activeElement );
		parts.$count.removeAttr( 'tabindex' );
		parts.$source.removeClass( 'is-counting' ).addClass( 'is-count-error' );
		parts.$source.find( '.plclean-card-stats' ).attr( 'aria-busy', 'false' );
		parts.$count.text( '' ).prop( 'hidden', true );
		parts.$source.find( '.plclean-count-error' ).remove();
		const $retry = $( '<button type="button" class="button-link plclean-count-retry"></button>' )
			.attr( 'data-rule', id )
			.attr( 'aria-describedby', 'plclean-title-' + id )
			.text( __( 'Retry', 'log-broom' ) );
		$( '<span class="plclean-count-error"></span>' )
			.append( icon( 'warning' ), $( '<span>' ).text( __( "Couldn't count", 'log-broom' ) ), document.createTextNode( ' — ' ), $retry )
			.insertBefore( parts.$source.find( '.plclean-card-clean' ) );
		if ( hadFocus ) {
			$retry.trigger( 'focus' );
		}
	}

	/** Send stats requests while fewer than COUNT_CONCURRENCY are in flight. */
	function pumpCounts() {
		while ( counting.active < COUNT_CONCURRENCY && counting.queue.length ) {
			const id = counting.queue.shift();
			counting.active++;
			post( 'plclean_stats', { rule: id } )
				.then( ( stats ) => showCounted( id, stats ) )
				.catch( () => showCountError( id ) )
				.catch( () => {} )
				.then( () => {
					// Always: free the slot and go on, even if showing a result failed.
					counting.active--;
					try {
						renderSizes();
						renderSummary();
						updateSelection();
					} finally {
						pumpCounts();
					}
				} );
		}
	}

	/**
	 * Ask for the count of the given sources (queued behind any requests already waiting).
	 *
	 * @param {string[]} ids Rule ids.
	 */
	function countSources( ids ) {
		ids.forEach( ( id ) => {
			if ( counting.queue.indexOf( id ) < 0 ) {
				showCounting( id );
				counting.queue.push( id );
			}
		} );
		renderSummary();
		pumpCounts();
	}

	/* ---------------------------------------------------------------------------------------------
	 * Dialog shell
	 * ------------------------------------------------------------------------------------------- */

	const ui = {
		returnFocus: null, // Element focused again after the dialog closes.
		busy: false, // True while a batch run is in progress: Esc and close are blocked.
		onClose: null, // Callback after the dialog closed.
		run: null, // Current cleaning run.
	};

	/**
	 * Replace the dialog content and open it.
	 *
	 * @param {Object}  view          View parts.
	 * @param {jQuery}  view.title    Title contents (text or nodes).
	 * @param {jQuery}  view.body     Body nodes.
	 * @param {jQuery}  view.footer   Footer nodes.
	 * @param {string}  view.tone     '', 'danger', 'success', 'warning'.
	 * @param {boolean} view.closable Show the close button.
	 */
	function renderDialog( view ) {
		const $title = $( '<h2 id="plclean-dialog-title" class="plclean-dialog-title"></h2>' ).append( view.title );
		const $close = $( '<button type="button" class="plclean-dialog-close"></button>' )
			.attr( 'aria-label', __( 'Close', 'log-broom' ) )
			.append( icon( 'no-alt' ) )
			.prop( 'hidden', ! view.closable );
		const $inner = $( '<div class="plclean-dialog-inner plclean-view"></div>' )
			.attr( 'data-tone', view.tone || '' )
			.append(
				$( '<div class="plclean-dialog-header"></div>' ).append( view.headerIcon ? icon( view.headerIcon ).addClass( 'plclean-dialog-icon' ) : null, $title, $close ),
				$( '<div class="plclean-dialog-body"></div>' ).append( view.body ),
				view.footer ? $( '<div class="plclean-dialog-footer"></div>' ).append( view.footer ) : null
			);
		$dialog.empty().append( $inner );
		$close.on( 'click', () => dialog.close() );
		if ( ! dialog.open ) {
			dialog.showModal();
		}
		if ( view.focus ) {
			view.focus.trigger( 'focus' );
		}
	}

	dialog.addEventListener( 'cancel', ( event ) => {
		if ( ui.busy ) {
			event.preventDefault();
		}
	} );

	dialog.addEventListener( 'close', () => {
		if ( ui.busy ) {
			// Never leave a running clean without its progress view.
			dialog.showModal();
			return;
		}
		const onClose = ui.onClose;
		ui.onClose = null;
		ui.run = null;
		$dialog.empty();
		// onClose runs first and may choose where focus goes (ui.returnFocus); only then is the target read.
		if ( onClose ) {
			onClose();
		}
		const target = ui.returnFocus;
		ui.returnFocus = null;
		let focusTarget = target && document.body.contains( target ) && ! target.disabled ? target : null;
		if ( ! focusTarget ) {
			focusTarget = document.getElementById( 'plclean-summary' );
			focusTarget.setAttribute( 'tabindex', '-1' );
		}
		focusTarget.focus();
	} );

	/**
	 * A required confirmation checkbox that enables `$button` only while ticked.
	 *
	 * @param {string} text    Label text.
	 * @param {jQuery} $button Button to enable.
	 * @return {jQuery} Label.
	 */
	function confirmBox( text, $button ) {
		const $input = $( '<input type="checkbox" id="plclean-backup-confirm" />' );
		$input.on( 'change', () => $button.prop( 'disabled', ! $input.prop( 'checked' ) ) );
		return $( '<label class="plclean-confirm" for="plclean-backup-confirm"></label>' ).append( $input, $( '<span>' ).text( text ) );
	}

	/**
	 * The "can't be undone" warning box.
	 *
	 * @return {jQuery} Warning.
	 */
	function undoWarning() {
		return $( '<div class="plclean-dialog-warning"></div>' ).append(
			icon( 'warning' ),
			$( '<p>' ).text( __( "This can't be undone. The only way to get this data back is from a database backup.", 'log-broom' ) )
		);
	}

	/* ---------------------------------------------------------------------------------------------
	 * Clean: confirm, progress, paused, success
	 * ------------------------------------------------------------------------------------------- */

	/** Sources that hold security or activity history, which some sites keep for audits. */
	const AUDIT_SOURCES = [ 'wp_activity_log', 'wordfence', 'solid_security' ];

	/**
	 * The dialog line shown when the selection includes security or activity history, or null. For Solid Security
	 * it also says that its unread alerts and last site-scan result are cleared.
	 *
	 * @param {string[]} ids Selected rule ids.
	 * @return {?jQuery} Line.
	 */
	function auditNote( ids ) {
		if ( ! ids.some( ( id ) => AUDIT_SOURCES.indexOf( id ) >= 0 ) ) {
			return null;
		}
		const parts = [ __( 'Includes security or activity history. Some sites keep this for audits.', 'log-broom' ) ];
		if ( ids.indexOf( 'solid_security' ) >= 0 && sources.solid_security && sources.solid_security.details_note ) {
			parts.push( String( sources.solid_security.details_note ) );
		}
		return $( '<p class="plclean-dialog-note plclean-dialog-audit"></p>' ).append( icon( 'info-outline' ), $( '<span>' ).text( parts.join( ' ' ) ) );
	}

	/**
	 * Open the backup confirmation dialog for the given sources.
	 *
	 * @param {string[]}    ids     Rule ids.
	 * @param {HTMLElement} trigger Button that opened the dialog.
	 */
	function openCleanDialog( ids, trigger ) {
		ids = ids.filter( ( id ) => sources[ id ] && sources[ id ].count > 0 );
		if ( ! ids.length ) {
			return;
		}
		const total = entriesOf( ids );
		const $list = $( '<ul class="plclean-dialog-list"></ul>' );
		const kept = [];
		ids.forEach( ( id ) => {
			const source = sources[ id ];
			const text = source.size > 0
				? sprintf(
					/* translators: 1: source name, 2: number of entries, 3: data size. */
					_n( '%1$s: %2$s entry (%3$s)', '%1$s: %2$s entries (%3$s)', source.count, 'log-broom' ),
					source.label,
					fmt( source.count ),
					formatBytes( source.size )
				)
				: sprintf(
					/* translators: 1: source name, 2: number of entries. */
					_n( '%1$s: %2$s entry', '%1$s: %2$s entries', source.count, 'log-broom' ),
					source.label,
					fmt( source.count )
				);
			$list.append( $( '<li>' ).text( text ) );
			if ( source.kept && kept.indexOf( source.kept ) < 0 ) {
				kept.push( source.kept );
			}
		} );

		const $delete = $( '<button type="button" class="button plclean-button-danger plclean-delete" disabled></button>' )
			.append( iconLabel( 'trash', __( 'Delete log data', 'log-broom' ) ) );
		const $cancel = $( '<button type="button" class="button plclean-cancel"></button>' ).text( __( 'Cancel, keep data', 'log-broom' ) );

		const body = [ $( '<p class="plclean-dialog-lead"></p>' ).text( __( 'This will delete:', 'log-broom' ) ), $list ];
		if ( kept.length ) {
			body.push(
				$( '<p class="plclean-dialog-kept"></p>' ).append(
					icon( 'shield' ),
					$( '<span>' ).text(
						/* translators: %s: what is kept, e.g. "pending tasks". */
						sprintf( __( 'Kept: %s', 'log-broom' ), kept.join( _x( ', ', 'list separator', 'log-broom' ) ) )
					)
				)
			);
		}
		const audit = auditNote( ids );
		if ( audit ) {
			body.push( audit );
		}
		body.push( undoWarning(), confirmBox( __( 'I have a recent backup of my database and know how to restore it.', 'log-broom' ), $delete ) );

		ui.returnFocus = trigger;
		ui.onClose = null;
		renderDialog( {
			title: document.createTextNode(
				/* translators: %s: number of entries. */
				sprintf( _n( 'Permanently delete %s log entry?', 'Permanently delete %s log entries?', total, 'log-broom' ), fmt( total ) )
			),
			headerIcon: 'trash',
			tone: 'danger',
			closable: true,
			body,
			footer: [ $cancel, $delete ],
		} );

		$cancel.on( 'click', () => dialog.close() );
		$delete.on( 'click', () => {
			if ( ! $dialog.find( '#plclean-backup-confirm' ).prop( 'checked' ) ) {
				return;
			}
			startRun( ids );
		} );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Batch sizing
	 * ------------------------------------------------------------------------------------------- */

	const MIN_BATCH = 1000;
	const MAX_FIXED_BATCH = 100000;
	const FAST_BATCH = 1.0; // Seconds: a full batch faster than this doubles the next one.
	const SLOW_BATCH = 3.0; // Seconds: a batch slower than this halves the next one.

	/**
	 * First batch size for a source: its default size. Null for sources whose batch size never changes
	 * (post revisions, single-pass transients): the server then uses the rule's own size.
	 *
	 * @param {string} id Rule id.
	 * @return {?number} Limit.
	 */
	function firstLimit( id ) {
		const source = sources[ id ];
		if ( ! source.adaptive ) {
			return null;
		}
		if ( 'fixed' === batch.mode ) {
			// The user's size, sent unchanged on every batch (the server clamps it to what the rule accepts).
			return Math.min( MAX_FIXED_BATCH, Math.max( MIN_BATCH, batch.size ) );
		}
		return Math.min( source.max_batch_size, Math.max( MIN_BATCH, source.batch_size ) );
	}

	/**
	 * Next automatic batch size (same rule as the server's scheduled cleaning): double after a full batch that
	 * took under a second, halve after one that took over three seconds, otherwise keep it. A batch is full when it
	 * deleted at least its limit (an Action Scheduler batch counts actions and also deletes their log entries).
	 *
	 * @param {string} id      Rule id.
	 * @param {number} limit   Limit used.
	 * @param {number} deleted Entries deleted.
	 * @param {number} elapsed Seconds the server spent deleting.
	 * @return {number} Next limit.
	 */
	function nextLimit( id, limit, deleted, elapsed ) {
		if ( elapsed < FAST_BATCH && deleted >= limit ) {
			return Math.min( sources[ id ].max_batch_size, limit * 2 );
		}
		if ( elapsed > SLOW_BATCH ) {
			return Math.max( Math.min( MIN_BATCH, limit ), Math.floor( limit / 2 ) );
		}
		return limit;
	}

	/**
	 * Halve a source's batch size after a failed or timed-out request, before Resume.
	 *
	 * @param {Object} run Run state.
	 * @param {string} id  Rule id.
	 */
	function shrinkAfterFailure( run, id ) {
		// A fixed size is the user's choice: never change it silently.
		if ( 'auto' === run.mode && run.limit[ id ] ) {
			run.limit[ id ] = Math.max( Math.min( MIN_BATCH, run.limit[ id ] ), Math.floor( run.limit[ id ] / 2 ) );
		}
	}

	/**
	 * Start cleaning the given sources, one after another.
	 *
	 * @param {string[]} ids Rule ids.
	 */
	function startRun( ids ) {
		const run = {
			queue: ids.slice(),
			index: 0,
			deleted: 0,
			remaining: {},
			startSize: {},
			stalled: [],
			skipped: [],
			skippedWhy: {}, // Error code per skipped source: unavailable | network_only.
			stalledWhy: {}, // Plain-text reason per stalled source, when the server gave one.
			cleaned: [],
			touched: {}, // Per source: tables its batches deleted from (sent to plclean_optimize).
			limit: {},
			mode: batch.mode,
			removed: {}, // Entries removed per source.
			state: {}, // Per source: waiting | active | done | stalled | skipped.
			batches: 0, // Batches answered so far.
			started: 0, // performance.now() when the first batch was sent.
			speed: 0, // Entries per second: moving average over recent batches.
			speedShown: 0, // performance.now() of the last speed text update.
		};
		ids.forEach( ( id ) => {
			run.remaining[ id ] = sources[ id ].count;
			run.startSize[ id ] = sources[ id ].size;
			run.limit[ id ] = firstLimit( id );
			run.removed[ id ] = 0;
			run.touched[ id ] = [];
			run.state[ id ] = 'waiting';
		} );
		ui.run = run;
		runLoop();
	}

	/**
	 * Entries still to go, from the server's latest numbers (rows can appear mid-run, e.g. log entries of deleted
	 * actions), so the denominator is "removed so far + remaining", never the initial count.
	 *
	 * @param {Object} run Run state.
	 * @return {number} Total.
	 */
	function runTotal( run ) {
		let left = 0;
		for ( let i = run.index; i < run.queue.length; i++ ) {
			left += run.remaining[ run.queue[ i ] ];
		}
		return run.deleted + left;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Detailed progress: per-source checklist, speed and time left, batch size
	 * ------------------------------------------------------------------------------------------- */

	const CHECK_ICONS = {
		done: 'yes-alt',
		active: 'update',
		stalled: 'warning',
		skipped: 'minus',
	};

	/**
	 * Text of one checklist row. Every state has its own words, so it never relies on the icon or colour.
	 *
	 * @param {Object} run Run state.
	 * @param {string} id  Rule id.
	 * @return {string} Text.
	 */
	function checkText( run, id ) {
		const label = sources[ id ].label;
		switch ( run.state[ id ] ) {
			case 'done':
				/* translators: 1: source name, 2: number of entries removed. */
				return sprintf( __( '%1$s — %2$s removed', 'log-broom' ), label, fmt( run.removed[ id ] ) );
			case 'active':
				/* translators: 1: source name, 2: entries removed so far, 3: total entries for this source. */
				return sprintf( __( '%1$s — %2$s of %3$s', 'log-broom' ), label, fmt( run.removed[ id ] ), fmt( run.removed[ id ] + run.remaining[ id ] ) );
			case 'stalled':
				/* translators: %s: source name. */
				return sprintf( __( "%s — some entries couldn't be deleted", 'log-broom' ), label );
			case 'skipped':
				/* translators: %s: source name. */
				return sprintf( __( '%s — skipped (not available)', 'log-broom' ), label );
			default:
				/* translators: %s: source name. */
				return sprintf( __( '%s — waiting', 'log-broom' ), label );
		}
	}

	/**
	 * The checklist: one row per selected source. Not a live region; the overall progress is announced instead.
	 *
	 * @param {Object} run Run state.
	 * @return {jQuery} List.
	 */
	function checklist( run ) {
		const $list = $( '<ul class="plclean-checklist"></ul>' ).attr( 'aria-label', __( 'Sources', 'log-broom' ) );
		if ( run.queue.length > 6 ) {
			// Scrolls: keep it reachable from the keyboard.
			$list.attr( 'tabindex', '0' );
		}
		run.queue.forEach( ( id ) => {
			$list.append( $( '<li class="plclean-check"></li>' ).attr( 'data-rule', id ) );
		} );
		updateChecklist( run, $list );
		return $list;
	}

	/**
	 * Bring the checklist rows up to date (only the rows whose text or state changed are touched).
	 *
	 * @param {Object} run   Run state.
	 * @param {jQuery} $list List.
	 */
	function updateChecklist( run, $list ) {
		$list.children( 'li' ).each( function () {
			const id = this.getAttribute( 'data-rule' );
			const state = run.state[ id ];
			const text = checkText( run, id );
			if ( this.getAttribute( 'data-state' ) === state && this.plcleanText === text ) {
				return;
			}
			this.plcleanText = text;
			const $mark = CHECK_ICONS[ state ] ? icon( CHECK_ICONS[ state ] ) : $( '<span class="plclean-check-dot" aria-hidden="true"></span>' );
			$( this )
				.attr( 'data-state', state )
				.empty()
				.append( $mark.addClass( 'plclean-check-icon' ), $( '<span class="plclean-check-text"></span>' ).text( text ) );
		} );
		const active = $list.children( 'li[data-state="active"]' )[ 0 ];
		if ( active && $list[ 0 ].scrollHeight > $list[ 0 ].clientHeight ) {
			const top = active.offsetTop - $list[ 0 ].offsetTop;
			if ( top < $list[ 0 ].scrollTop || top + active.offsetHeight > $list[ 0 ].scrollTop + $list[ 0 ].clientHeight ) {
				$list[ 0 ].scrollTop = Math.max( 0, top - active.offsetHeight );
			}
		}
	}

	/**
	 * "less than a minute left", "about 2 min left", "about 1 h 5 min left".
	 *
	 * @param {number} seconds Seconds left.
	 * @return {string} Text.
	 */
	function timeLeft( seconds ) {
		if ( seconds < 60 ) {
			return __( 'less than a minute left', 'log-broom' );
		}
		const minutes = Math.round( seconds / 60 );
		if ( minutes < 60 ) {
			return sprintf(
				/* translators: %s: number of minutes. */
				_n( 'about %s min left', 'about %s min left', minutes, 'log-broom' ),
				fmt( minutes )
			);
		}
		const hours = Math.floor( minutes / 60 );
		return sprintf(
			/* translators: 1: number of hours, 2: number of minutes. */
			_n( 'about %1$s h %2$s min left', 'about %1$s h %2$s min left', hours, 'log-broom' ),
			fmt( hours ),
			fmt( minutes % 60 )
		);
	}

	/**
	 * Speed and time left, e.g. "≈ 12,000 entries/sec · about 2 min left". Shown after 2 batches or 3 seconds,
	 * and rewritten about once a second.
	 *
	 * @param {Object} run Run state.
	 */
	function updateSpeed( run ) {
		const $speed = $dialog.find( '.plclean-speed' );
		const now = window.performance.now();
		const ready = run.speed > 0 && ( run.batches >= 2 || ( run.started && now - run.started >= 3000 ) );
		if ( ! ready ) {
			if ( ! run.speedShown ) {
				$speed.text( __( 'Estimating time left…', 'log-broom' ) );
			}
			return;
		}
		if ( run.speedShown && now - run.speedShown < 1000 ) {
			return;
		}
		run.speedShown = now;
		const left = Math.max( 0, runTotal( run ) - run.deleted );
		$speed.text(
			sprintf(
				/* translators: 1: entries removed per second, 2: time left, e.g. "about 2 min left". */
				__( '≈ %1$s entries/sec · %2$s', 'log-broom' ),
				fmt( run.speed ),
				timeLeft( left / run.speed )
			)
		);
	}

	/**
	 * "Batch: 20,000 entries (automatic)" or "(fixed)" for the source being cleaned. Hidden for sources that
	 * always use their own size (post revisions, expired transients).
	 *
	 * @param {Object} run Run state.
	 * @param {string} id  Rule id being cleaned, if any.
	 */
	function updateBatchLine( run, id ) {
		const $line = $dialog.find( '.plclean-batch-line' );
		const limit = id ? run.limit[ id ] : null;
		if ( ! limit ) {
			$line.prop( 'hidden', true ).text( '' );
			return;
		}
		let text;
		if ( 'tasks' === sources[ id ].batch_unit ) {
			text = 'fixed' === run.mode
				? sprintf(
					/* translators: %s: number of scheduled tasks per batch (their log entries are removed with them). */
					_n( 'Batch: %s task (fixed)', 'Batch: %s tasks (fixed)', limit, 'log-broom' ),
					fmt( limit )
				)
				: sprintf(
					/* translators: %s: number of scheduled tasks per batch (their log entries are removed with them). */
					_n( 'Batch: %s task (automatic)', 'Batch: %s tasks (automatic)', limit, 'log-broom' ),
					fmt( limit )
				);
		} else if ( 'fixed' === run.mode ) {
			text = sprintf(
				/* translators: %s: number of entries per batch. */
				_n( 'Batch: %s entry (fixed)', 'Batch: %s entries (fixed)', limit, 'log-broom' ),
				fmt( limit )
			);
		} else {
			text = sprintf(
				/* translators: %s: number of entries per batch. */
				_n( 'Batch: %s entry (automatic)', 'Batch: %s entries (automatic)', limit, 'log-broom' ),
				fmt( limit )
			);
		}
		$line.prop( 'hidden', false ).text( text );
	}

	/**
	 * Record a batch's speed: deleted entries over the request's round trip, as a moving average.
	 *
	 * @param {Object} run     Run state.
	 * @param {number} deleted Entries deleted.
	 * @param {number} seconds Round trip in seconds.
	 */
	function recordSpeed( run, deleted, seconds ) {
		run.batches++;
		if ( seconds <= 0 ) {
			return;
		}
		const current = deleted / seconds;
		run.speed = run.speed > 0 ? ( 0.4 * current ) + ( 0.6 * run.speed ) : current;
	}

	/**
	 * Render or update the progress view.
	 *
	 * @param {Object} run  Run state.
	 * @param {string} note Current step text.
	 * @param {string} [id] Rule being cleaned.
	 */
	function renderProgress( run, note, id ) {
		const total = runTotal( run );
		/* translators: 1: entries removed so far, 2: total entries. */
		const title = sprintf( __( 'Cleaning… %1$s of %2$s removed', 'log-broom' ), fmt( run.deleted ), fmt( total ) );

		if ( ! $dialog.find( '.plclean-progress' ).length ) {
			renderDialog( {
				title: document.createTextNode( title ),
				headerIcon: 'update',
				tone: 'progress',
				closable: false,
				body: [
					$( '<progress class="plclean-progress"></progress>' ).attr( 'aria-label', __( 'Cleaning progress', 'log-broom' ) ),
					$( '<p class="plclean-now"></p>' ),
					$( '<div class="plclean-progress-meta"></div>' ).append(
						$( '<p class="plclean-speed"></p>' ),
						$( '<p class="plclean-batch-line" hidden></p>' )
					),
					checklist( run ),
					$( '<p class="plclean-dialog-note"></p>' ).append(
						icon( 'clock' ),
						$( '<span>' ).text( __( 'Keep this tab open. Large logs can take a few minutes.', 'log-broom' ) )
					),
				],
				footer: null,
			} );
			// Keep focus inside the modal on a non-interactive but focusable element.
			$dialog.find( '.plclean-dialog-title' ).attr( 'tabindex', '-1' ).trigger( 'focus' );
		}
		$dialog.find( '#plclean-dialog-title' ).text( title );
		$dialog.find( '.plclean-progress' ).attr( { max: Math.max( 1, total ), value: Math.min( run.deleted, total ) } );
		$dialog.find( '.plclean-now' ).text( note );
		updateChecklist( run, $dialog.find( '.plclean-checklist' ) );
		updateSpeed( run );
		updateBatchLine( run, id );
		announce( title + ' ' + note, false );
	}

	/** Run (or resume) the batch loop for the current run. */
	async function runLoop() {
		const run = ui.run;
		ui.busy = true;
		lastAnnounce = 0;

		while ( run.index < run.queue.length ) {
			const id = run.queue[ run.index ];
			const source = sources[ id ];
			run.state[ id ] = 'active';
			/* translators: %s: source name. */
			renderProgress( run, sprintf( __( 'Now cleaning: %s', 'log-broom' ), source.label ), id );

			const fields = { rule: id, backup_confirmed: 1 };
			if ( run.limit[ id ] ) {
				fields.limit = run.limit[ id ];
			}
			const sent = window.performance.now();
			if ( ! run.started ) {
				run.started = sent;
			}
			let result;
			try {
				result = await post( 'plclean_clean', fields );
			} catch ( error ) {
				if ( 'unavailable' === error.kind ) {
					// The source went away mid-run (e.g. its plugin was uninstalled): skip it and carry on.
					run.skipped.push( id );
					run.skippedWhy[ id ] = error.code;
					run.state[ id ] = 'skipped';
					run.remaining[ id ] = 0;
					run.index++;
					continue;
				}
				ui.busy = false;
				if ( 'session' !== error.kind ) {
					shrinkAfterFailure( run, id );
				}
				renderPaused( run, id, error );
				return;
			}

			const deleted = parseInt( result.deleted, 10 ) || 0;
			const remaining = parseInt( result.remaining, 10 ) || 0;
			recordSpeed( run, deleted, ( window.performance.now() - sent ) / 1000 );
			run.deleted += deleted;
			run.removed[ id ] += deleted;
			run.remaining[ id ] = remaining;
			source.count = remaining;
			if ( source.parts ) {
				source.parts = readParts( result.parts ) || { tasks: 0, logs: remaining };
			}
			if ( undefined !== result.size ) {
				source.size = parseInt( result.size, 10 ) || 0;
			}
			if ( run.limit[ id ] && 'auto' === run.mode ) {
				const used = parseInt( result.limit, 10 ) || run.limit[ id ];
				run.limit[ id ] = nextLimit( id, used, deleted, parseFloat( result.elapsed ) || 0 );
			}
			if ( deleted > 0 && run.cleaned.indexOf( id ) < 0 ) {
				run.cleaned.push( id );
			}
			( Array.isArray( result.tables_touched ) ? result.tables_touched : [] ).forEach( ( table ) => {
				if ( 'string' === typeof table && run.touched[ id ].indexOf( table ) < 0 ) {
					run.touched[ id ].push( table );
				}
			} );
			renderCard( id );

			if ( result.stalled ) {
				run.stalled.push( id );
				if ( result.stall_reason ) {
					run.stalledWhy[ id ] = String( result.stall_reason );
				}
			}
			if ( result.done || result.stalled ) {
				run.state[ id ] = result.stalled ? 'stalled' : 'done';
				run.index++;
			}
		}

		if ( $schedule.find( 'input[name="optimize"]' ).prop( 'checked' ) ) {
			for ( const id of run.cleaned ) {
				// Only the tables entries were removed from; the server ignores any that are not this source's.
				if ( ! run.touched[ id ].length ) {
					continue;
				}
				renderProgress( run, __( 'Optimizing tables…', 'log-broom' ) );
				try {
					const result = await post( 'plclean_optimize', { rule: id, tables: run.touched[ id ] } );
					sources[ id ].size = parseInt( result.size, 10 ) || 0;
				} catch ( error ) {
					// Optimizing is a bonus; the entries are already removed.
				}
			}
		}

		ui.busy = false;
		renderSuccess( run );
	}

	/**
	 * Paused view after an HTTP error or `success: false`.
	 *
	 * Only a missing answer or a 5xx error without a message is called "probably a timeout". A server message
	 * leads when there is one. An expired session offers a reload instead of resuming.
	 *
	 * @param {Object} run   Run state.
	 * @param {string} id    Rule being cleaned.
	 * @param {Error}  error Error from requestError().
	 */
	function renderPaused( run, id, error ) {
		renderSizes();
		renderSummary();
		updateSelection();

		const label = sources[ id ].label;
		const kind = error && error.kind ? error.kind : 'timeout';
		const $close = $( '<button type="button" class="button"></button>' ).text( __( 'Close', 'log-broom' ) );
		const body = [];
		let $primary;

		if ( 'session' === kind ) {
			body.push(
				$( '<p>' ).text( error.message ),
				$( '<p class="plclean-dialog-detail"></p>' ).text( __( 'Anything already removed stays removed.', 'log-broom' ) )
			);
			$primary = $( '<button type="button" class="button button-primary plclean-reload"></button>' )
				.append( iconLabel( 'update', __( 'Reload page', 'log-broom' ) ) );
			$primary.on( 'click', () => window.location.reload() );
		} else {
			if ( 'timeout' === kind ) {
				body.push(
					$( '<p>' ).text(
						/* translators: %s: source name. */
						sprintf( __( 'The server stopped responding while cleaning %s, probably a timeout. Anything already removed stays removed.', 'log-broom' ), label )
					)
				);
			} else if ( 'message' === kind ) {
				body.push(
					$( '<p class="plclean-dialog-message"></p>' ).text( error.message ),
					$( '<p class="plclean-dialog-detail"></p>' ).text(
						/* translators: %s: source name. */
						sprintf( __( 'Cleaning %s stopped. Anything already removed stays removed.', 'log-broom' ), label )
					)
				);
			} else {
				body.push(
					$( '<p>' ).text(
						/* translators: %s: source name. */
						sprintf( __( 'The server sent an unexpected answer while cleaning %s. Anything already removed stays removed.', 'log-broom' ), label )
					)
				);
			}
			if ( 'fixed' === run.mode && run.limit[ id ] && ( 'timeout' === kind || 'unknown' === kind ) ) {
				body.push(
					$( '<p class="plclean-dialog-hint"></p>' ).append(
						icon( 'lightbulb' ),
						$( '<span>' ).text( __( 'Try a smaller batch size in Advanced settings.', 'log-broom' ) )
					)
				);
			}
			$primary = $( '<button type="button" class="button button-primary plclean-resume"></button>' )
				.append( iconLabel( 'controls-play', __( 'Resume cleaning', 'log-broom' ) ) );
			$primary.on( 'click', () => {
				ui.run = run;
				runLoop();
			} );
		}

		renderDialog( {
			title: document.createTextNode( __( 'Cleaning paused', 'log-broom' ) ),
			headerIcon: 'warning',
			tone: 'warning',
			closable: true,
			body,
			footer: [ $close, $primary ],
			focus: $primary,
		} );
		announce( __( 'Cleaning paused', 'log-broom' ), true );

		ui.run = run;
		$close.on( 'click', () => dialog.close() );
	}

	/**
	 * Success view, plus the page-level result.
	 *
	 * @param {Object} run Run state.
	 */
	function renderSuccess( run ) {
		renderSizes();
		renderSummary();
		updateSelection();

		const freed = run.queue.reduce( ( sum, id ) => sum + Math.max( 0, run.startSize[ id ] - sources[ id ].size ), 0 );
		const n = run.deleted;
		const title = freed > 0
			? sprintf(
				/* translators: 1: number of entries, 2: data size. */
				_n( 'Done! Removed %1$s entry and freed about %2$s.', 'Done! Removed %1$s entries and freed about %2$s.', n, 'log-broom' ),
				fmt( n ),
				formatBytes( freed )
			)
			: sprintf(
				/* translators: %s: number of entries. */
				_n( 'Done! Removed %s entry.', 'Done! Removed %s entries.', n, 'log-broom' ),
				fmt( n )
			);

		const body = [ checklist( run ) ];
		if ( run.skipped.length ) {
			const $skipped = $( '<ul class="plclean-dialog-stalled plclean-dialog-skipped"></ul>' );
			run.skipped.forEach( ( id ) => {
				$skipped.append(
					$( '<li>' ).append(
						icon( 'warning' ),
						$( '<span>' ).append(
							$( '<strong>' ).text( sources[ id ].label ),
							' ',
							document.createTextNode(
								'network_only' === run.skippedWhy[ id ]
									? __( 'Not cleaned: only a network administrator can clean network-wide logs.', 'log-broom' )
									: __( 'Not cleaned: this source is no longer on this site.', 'log-broom' )
							)
						)
					)
				);
			} );
			body.push( $skipped );
		}
		if ( run.stalled.length ) {
			const $list = $( '<ul class="plclean-dialog-stalled"></ul>' );
			run.stalled.forEach( ( id ) => {
				$list.append(
					$( '<li>' ).append(
						icon( 'warning' ),
						$( '<span>' ).append(
							$( '<strong>' ).text( sources[ id ].label ),
							' ',
							document.createTextNode( run.stalledWhy[ id ] || __( "Some entries couldn't be deleted. Try again later.", 'log-broom' ) )
						)
					)
				);
			} );
			body.push( $list );
		}
		if ( ! $schedule.find( 'input[name="optimize"]' ).prop( 'checked' ) ) {
			body.push(
				$( '<p class="plclean-tip"></p>' ).append(
					icon( 'lightbulb' ),
					$( '<span>' ).text( __( "Tip: turn on 'Optimize tables' to give the freed space back to your server.", 'log-broom' ) )
				)
			);
		}
		const $done = $( '<button type="button" class="button button-primary"></button>' ).text( __( 'Close', 'log-broom' ) );
		renderDialog( {
			title: document.createTextNode( title ),
			headerIcon: 'yes-alt',
			tone: 'success',
			closable: true,
			body,
			footer: [ $done ],
			focus: $done,
		} );
		$done.on( 'click', () => dialog.close() );
		announce( title, true );
		pageResult( title );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Automatic cleaning settings
	 * ------------------------------------------------------------------------------------------- */

	let currentSchedule = String( $schedule.attr( 'data-schedule' ) || 'off' );

	/**
	 * Schedule status sentence.
	 *
	 * @param {string} schedule off|daily|weekly.
	 * @return {string} Text.
	 */
	function scheduleStatus( schedule ) {
		if ( 'off' === schedule ) {
			return '';
		}
		let text = 'daily' === schedule ? __( 'Automatic cleaning is on. It runs daily.', 'log-broom' ) : __( 'Automatic cleaning is on. It runs weekly.', 'log-broom' );
		const last = data.lastRun;
		if ( last ) {
			text += ' ' + sprintf(
				/* translators: 1: date, 2: number of entries removed. */
				_n( 'Last run: %1$s, removed %2$s entry.', 'Last run: %1$s, removed %2$s entries.', last.deleted, 'log-broom' ),
				last.date,
				fmt( last.deleted )
			);
		}
		return text;
	}

	/** Reflect the saved schedule: status text, switches. */
	function applySchedule() {
		$app.toggleClass( 'plclean-schedule-off', 'off' === currentSchedule );
		$schedule.attr( 'data-schedule', currentSchedule );
		$schedule.find( 'input[name="schedule"][value="' + currentSchedule + '"]' ).prop( 'checked', true );
		$status.text( scheduleStatus( currentSchedule ) ).toggleClass( 'is-on', 'off' !== currentSchedule );
		$status.prepend( 'off' !== currentSchedule ? icon( 'yes-alt' ) : null );
		if ( 'off' === currentSchedule ) {
			// Turning automatic cleaning off forgets the last run's error on the server too (sync( 'off' )).
			$( '#plclean-last-error' ).remove();
		}
	}

	let saving = false;

	/**
	 * Lock (or unlock) every settings control while a save is in flight, so a newer choice can never race an
	 * older save (e.g. Off then Daily before the Off save returns, which must still ask for the backup).
	 *
	 * @param {boolean} lock Lock.
	 */
	function lockSettings( lock ) {
		const $controls = $schedule.find( 'input' ).add( $app.find( '.plclean-auto-toggle' ) ).add( $batch.find( 'input' ) );
		const focused = document.activeElement;
		saving = lock;
		$schedule.toggleClass( 'is-saving', lock ).attr( 'aria-busy', lock ? 'true' : null );
		$controls.prop( 'disabled', lock );
		if ( lock && $controls.is( focused ) ) {
			lockSettings.focused = focused;
		}
		if ( ! lock ) {
			applyBatch( false );
			if ( lockSettings.focused ) {
				if ( document.activeElement === document.body || ! document.activeElement ) {
					lockSettings.focused.focus();
				}
				lockSettings.focused = null;
			}
		}
	}

	/**
	 * Save settings. Sends the backup acknowledgement only for schedules that were confirmed in the dialog.
	 *
	 * @param {string} schedule Schedule to save.
	 * @return {Promise<Object>} Saved settings.
	 */
	function saveSettings( schedule ) {
		const fields = {
			schedule,
			enabled_rules: $app.find( '.plclean-auto-toggle:checked' ).map( ( i, el ) => el.value ).get(),
		};
		if ( $schedule.find( 'input[name="optimize"]' ).prop( 'checked' ) ) {
			fields.optimize = 1;
		}
		if ( 'off' !== schedule ) {
			fields.backup_ack = 1;
		}
		return saveFields( fields ).then( ( saved ) => {
			currentSchedule = saved.schedule || schedule;
			applySchedule();
			return saved;
		} );
	}

	/**
	 * Post settings with the save lock and the "Saved" toast.
	 *
	 * @param {Object} fields Fields for plclean_save_settings.
	 * @return {Promise<Object>} Saved settings.
	 */
	function saveFields( fields ) {
		lockSettings( true );
		return post( 'plclean_save_settings', fields ).then(
			( saved ) => {
				if ( saved.batch_mode ) {
					batch.mode = saved.batch_mode;
					batch.size = parseInt( saved.batch_size, 10 ) || batch.size;
				}
				lockSettings( false );
				toast( __( 'Saved', 'log-broom' ), false );
				return saved;
			},
			( error ) => {
				lockSettings( false );
				toast( error.message || __( 'Could not save. Please try again.', 'log-broom' ), true );
				throw error;
			}
		);
	}

	/* ---------------------------------------------------------------------------------------------
	 * Advanced: entries per batch (no backup acknowledgement: it changes how fast, never what, is deleted)
	 * ------------------------------------------------------------------------------------------- */

	const $batch = $( '#plclean-batch' );
	const $batchSize = $( '#plclean-batch-size' );
	const $batchError = $( '#plclean-batch-error' );
	let batchTimer = 0;

	/**
	 * Whether a typed batch size is a whole number within [1000, 100000].
	 *
	 * @param {string} value Raw input value.
	 * @return {boolean} Valid.
	 */
	function validBatchSize( value ) {
		const n = Number( value );
		return '' !== String( value ).trim() && Number.isInteger( n ) && n >= MIN_BATCH && n <= MAX_FIXED_BATCH;
	}

	/**
	 * Clamp a typed batch size to [1000, 100000] (empty or not a number: the saved size).
	 *
	 * @param {string} value Raw input value.
	 * @return {number} Size.
	 */
	function clampBatchSize( value ) {
		const n = Math.round( Number( value ) );
		if ( '' === String( value ).trim() || ! Number.isFinite( n ) ) {
			return batch.size;
		}
		return Math.min( MAX_FIXED_BATCH, Math.max( MIN_BATCH, n ) );
	}

	/**
	 * Reflect the saved batch settings on the controls.
	 *
	 * @param {boolean} resetValue Also put the saved size back into the number input.
	 */
	function applyBatch( resetValue ) {
		$batch.attr( { 'data-mode': batch.mode, 'data-size': batch.size } );
		$batch.find( 'input[name="batch_mode"][value="' + batch.mode + '"]' ).prop( 'checked', true );
		$batchSize.prop( 'disabled', saving || 'fixed' !== batch.mode );
		if ( resetValue ) {
			$batchSize.val( batch.size );
		}
	}

	/**
	 * Show or hide the inline help for an out-of-range batch size.
	 *
	 * @param {boolean} show Show.
	 */
	function batchHelp( show ) {
		$batchError.prop( 'hidden', ! show );
		$batchSize.attr( 'aria-invalid', show ? 'true' : null );
	}

	/**
	 * Save the batch settings when they differ from the saved ones.
	 *
	 * @param {string} mode auto|fixed.
	 * @param {number} size Fixed size.
	 */
	function saveBatch( mode, size ) {
		window.clearTimeout( batchTimer );
		if ( saving ) {
			// Another save is in flight; try again once it is done.
			batchTimer = window.setTimeout( () => saveBatch( mode, size ), 300 );
			return;
		}
		if ( mode === batch.mode && size === batch.size ) {
			applyBatch( false );
			return;
		}
		saveFields( { batch_mode: mode, batch_size: size } ).catch( () => applyBatch( true ) );
	}

	/**
	 * True from a pointer press on a batch mode radio (or its label) until that click is handled. The press blurs
	 * the number field first; its `change` must then not start a save of its own, so the radio's handler can save
	 * the final mode and size once.
	 */
	let pickingMode = false;
	let pickTimer = 0;

	/**
	 * How long after the press is released a click may still arrive. On touch screens the compatibility mouse
	 * events, the focus change and the click come after pointerup, possibly in a later task.
	 */
	const PICK_CLICK_WAIT = 500;

	/** Clamp the typed size, show it, and save it (on `change`, Enter or blur; never while typing). */
	function commitBatchSize() {
		window.clearTimeout( batchTimer );
		if ( $batchSize.prop( 'disabled' ) || pickingMode ) {
			return;
		}
		const raw = $batchSize.val();
		const size = clampBatchSize( raw );
		if ( String( size ) !== String( raw ) ) {
			// Adjusted to fit: the inline help stays visible to explain why the number changed.
			$batchSize.val( size );
		}
		$batchSize.attr( 'aria-invalid', null );
		saveBatch( 'fixed', size );
	}

	/**
	 * End a mode pick: remove its listeners and, when no mode change handled it (released elsewhere, or a tap on the
	 * mode already chosen), commit the typed size.
	 */
	function endPick() {
		window.clearTimeout( pickTimer );
		document.removeEventListener( 'click', onPickClick, true );
		document.removeEventListener( 'pointerup', onPickRelease, true );
		document.removeEventListener( 'pointercancel', onPickRelease, true );
		document.removeEventListener( 'mouseup', onPickRelease, true );
		if ( pickingMode ) {
			pickingMode = false;
			commitBatchSize();
		}
	}

	/** The pick's click: end the pick once the click (and the radio's `change`, if any) has been handled. */
	function onPickClick() {
		window.clearTimeout( pickTimer );
		pickTimer = window.setTimeout( endPick, 0 );
	}

	/** Press released: end the pick only if no click follows in time. */
	function onPickRelease() {
		window.clearTimeout( pickTimer );
		pickTimer = window.setTimeout( endPick, PICK_CLICK_WAIT );
	}

	$batch.on( 'pointerdown mousedown', '.plclean-batch-options label, input[name="batch_mode"]', function ( event ) {
		const radio = 'batch_mode' === event.target.name ? event.target : $( this ).find( 'input[name="batch_mode"]' )[ 0 ];
		if ( ! radio || pickingMode ) {
			return;
		}
		pickingMode = true;
		window.clearTimeout( pickTimer );
		document.addEventListener( 'click', onPickClick, true );
		document.addEventListener( 'pointerup', onPickRelease, true );
		document.addEventListener( 'pointercancel', onPickRelease, true );
		document.addEventListener( 'mouseup', onPickRelease, true );
	} );

	$batch.on( 'change', 'input[name="batch_mode"]', function () {
		pickingMode = false;
		if ( saving ) {
			applyBatch( false );
			return;
		}
		// Decide the final mode and size from the controls, show the size that is saved, then save once.
		const mode = 'fixed' === this.value ? 'fixed' : 'auto';
		const size = clampBatchSize( $batchSize.val() );
		$batchSize.val( size ).attr( 'aria-invalid', null );
		$batchSize.prop( 'disabled', 'fixed' !== mode );
		batchHelp( false );
		saveBatch( mode, size );
	} );

	$batchSize.on( 'input', function () {
		// Only the inline help reacts while typing; the number is committed on change, Enter or blur.
		batchHelp( ! validBatchSize( this.value ) );
	} );

	$batchSize.on( 'change blur', commitBatchSize );

	$batchSize.on( 'keydown', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			commitBatchSize();
		}
	} );

	/**
	 * Dialog variant: turning on automatic cleaning needs a backup acknowledgement.
	 *
	 * @param {string}      schedule daily|weekly.
	 * @param {HTMLElement} trigger  The radio that was picked.
	 */
	function openScheduleDialog( schedule, trigger ) {
		const $turnOn = $( '<button type="button" class="button button-primary plclean-turn-on" disabled></button>' )
			.append( iconLabel( 'clock', __( 'Turn on automatic cleaning', 'log-broom' ) ) );
		const $cancel = $( '<button type="button" class="button"></button>' ).text( __( 'Cancel', 'log-broom' ) );
		let confirmed = false;

		ui.returnFocus = trigger;
		ui.onClose = () => {
			if ( ! confirmed ) {
				applySchedule(); // Back to the previous value.
				// Focus the radio that is checked now (the previous schedule), not the one that was picked.
				ui.returnFocus = $schedule.find( 'input[name="schedule"]:checked' )[ 0 ] || null;
			}
		};
		renderDialog( {
			title: document.createTextNode( __( 'Turn on automatic cleaning?', 'log-broom' ) ),
			headerIcon: 'clock',
			tone: 'danger',
			closable: true,
			body: [
				$( '<p class="plclean-dialog-lead"></p>' ).text(
					'daily' === schedule
						? __( 'Every day, the sources you include are cleaned without asking again.', 'log-broom' )
						: __( 'Every week, the sources you include are cleaned without asking again.', 'log-broom' )
				),
				undoWarning(),
				confirmBox( __( 'I have regular automatic backups of my database.', 'log-broom' ), $turnOn ),
				$( '<p class="plclean-dialog-error" role="alert" hidden></p>' ),
			],
			footer: [ $cancel, $turnOn ],
		} );

		$cancel.on( 'click', () => dialog.close() );
		$turnOn.on( 'click', () => {
			// Block Esc / Cancel / close until the server answers, so the dialog and the saved state agree.
			ui.busy = true;
			$turnOn.prop( 'disabled', true );
			$cancel.prop( 'disabled', true );
			$dialog.find( '.plclean-dialog-close, #plclean-backup-confirm' ).prop( 'disabled', true );
			saveSettings( schedule ).then(
				() => {
					ui.busy = false;
					confirmed = true;
					const radio = $schedule.find( 'input[name="schedule"][value="' + schedule + '"]' )[ 0 ];
					ui.onClose = null;
					ui.returnFocus = radio;
					dialog.close();
				},
				( error ) => {
					ui.busy = false;
					$cancel.prop( 'disabled', false );
					$dialog.find( '.plclean-dialog-close, #plclean-backup-confirm' ).prop( 'disabled', false );
					$turnOn.prop( 'disabled', false );
					$dialog.find( '.plclean-dialog-error' ).text( error.message || __( 'Could not save. Please try again.', 'log-broom' ) ).prop( 'hidden', false );
				}
			);
		} );
	}

	$schedule.on( 'change', 'input[name="schedule"]', function () {
		const value = this.value;
		if ( saving ) {
			applySchedule(); // Controls are locked while saving; never act on a stale state.
			return;
		}
		if ( 'off' !== value && 'off' === currentSchedule ) {
			openScheduleDialog( value, this );
			return;
		}
		saveSettings( value ).catch( applySchedule );
	} );

	$schedule.on( 'change', 'input[name="optimize"]', function () {
		const input = this;
		saveSettings( currentSchedule ).catch( () => {
			input.checked = ! input.checked;
		} );
	} );

	$app.on( 'change', '.plclean-auto-toggle', function () {
		const input = this;
		saveSettings( currentSchedule ).catch( () => {
			input.checked = ! input.checked;
		} );
	} );

	/* ---------------------------------------------------------------------------------------------
	 * Source details (native <details>; height and opacity animate unless reduced motion is on)
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Open or close a source's details with a short height and opacity transition.
	 *
	 * @param {HTMLDetailsElement} details Details element.
	 * @param {boolean}            open    Open (true) or close (false).
	 */
	function toggleDetails( details, open ) {
		const body = details.querySelector( '.plclean-details-body' );
		if ( details.plcleanAnimation ) {
			details.plcleanAnimation.cancel();
			details.plcleanAnimation = null;
		}
		if ( motion.matches || ! body || ! body.animate ) {
			details.open = open;
			return;
		}
		details.open = true;
		const height = body.scrollHeight;
		const frames = [ { height: '0px', opacity: 0 }, { height: height + 'px', opacity: 1 } ];
		const animation = body.animate( open ? frames : frames.reverse(), {
			duration: open ? 220 : 150,
			easing: open ? 'cubic-bezier(0.2, 0.8, 0.2, 1)' : 'ease-in',
		} );
		details.plcleanAnimation = animation;
		animation.onfinish = () => {
			details.plcleanAnimation = null;
			details.open = open;
		};
	}

	$app.on( 'click', '.plclean-details-toggle', function ( event ) {
		// Never let a click (or Enter / Space, which click the summary) reach the card or its checkbox.
		event.stopPropagation();
		event.preventDefault();
		const details = this.parentElement;
		// While a close animation runs the element is still open: a click then means "open again".
		const closing = !! details.plcleanAnimation && details.plcleanClosing;
		const open = ! details.open || closing;
		details.plcleanClosing = ! open;
		toggleDetails( details, open );
	} );

	$app.find( '.plclean-details' ).each( function () {
		this.addEventListener( 'toggle', () => {
			$( this ).find( '.plclean-details-label' ).text( this.open ? __( 'Hide details', 'log-broom' ) : __( 'Details', 'log-broom' ) );
		} );
	} );

	/* ---------------------------------------------------------------------------------------------
	 * Wiring
	 * ------------------------------------------------------------------------------------------- */

	$app.on( 'change', '.plclean-card-select', updateSelection );

	$app.on( 'click', '.plclean-count-retry', function ( event ) {
		// The button sits inside the card's <label>: never let the click reach the checkbox.
		event.preventDefault();
		event.stopPropagation();
		countSources( [ String( $( this ).attr( 'data-rule' ) ) ] );
	} );

	$cleanSelected.on( 'click', function () {
		openCleanDialog( selectedIds(), this );
	} );

	$app.on( 'click', '.plclean-clean-one', function () {
		openCleanDialog( [ String( $( this ).data( 'rule' ) ) ], this );
	} );

	window.addEventListener( 'beforeunload', ( event ) => {
		if ( ui.busy ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	// Initial state: the page opens at once; each source's count follows.
	applySchedule();
	updateSelection();
	countSources( $app.find( '.plclean-source' ).map( ( i, el ) => el.getAttribute( 'data-rule' ) ).get().filter( ( id ) => sources[ id ] && sources[ id ].available ) );
}( jQuery, window.wp ) );
