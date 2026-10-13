/**
 * DTB System Manager — live operations console runtime.
 *
 * The unified System Manager console (platform health + Git-backed release
 * management) polls its active tab automatically: every 60s normally, every
 * 5s while a release is actively deploying or rolling back (server-reported
 * via meta.fast_poll on every response, so the cadence updates in real time
 * as a release's state changes — not just at page load). Operators can pause
 * or force an immediate refresh from the live toolbar at any time.
 *
 * This page manages its own polling loop instead of the generic
 * DtbAdmin auto-refresh poller (dtb-admin.js) so the interval can vary
 * dynamically; suppressGlobalAutoPolling() stops the generic poller from
 * also scheduling a redundant, fixed-interval refresh for this region.
 */
( function () {
	'use strict';

	const cfg = window.dtbAdminConfig || {};
	const DtbAdmin = window.DtbAdmin || null;

	const BASE_INTERVAL_MS = 60000;
	const FAST_INTERVAL_MS = 5000;

	function isSystemManagerPage() {
		return cfg?.page?.slug === 'dtb-system-manager' || document.querySelector( '.dtb-admin[data-dtb-page="dtb-system-manager"]' );
	}

	function suppressGlobalAutoPolling() {
		if ( ! DtbAdmin || ! DtbAdmin.registerLiveRegion || DtbAdmin._dtbSystemManagerObservabilityPatch ) return;

		const originalRegisterLiveRegion = DtbAdmin.registerLiveRegion;
		DtbAdmin.registerLiveRegion = function ( el ) {
			if ( el && el.getAttribute( 'data-dtb-live-module' ) === 'system-manager' ) {
				el.setAttribute( 'data-dtb-refresh-interval', '0' );
			}
			return originalRegisterLiveRegion.call( DtbAdmin, el );
		};
		DtbAdmin._dtbSystemManagerObservabilityPatch = true;
	}

	function endpointWithCurrentState( endpoint ) {
		const url = new URL( endpoint, window.location.origin );
		const params = new URLSearchParams( window.location.search );
		params.forEach( ( value, key ) => {
			if ( key === 'status' || key === 'paged' || key === '_dtb_live' ) return;
			if ( value === '' ) {
				url.searchParams.delete( key );
			} else {
				url.searchParams.set( key, value );
			}
		} );
		url.searchParams.set( '_dtb_live', String( Date.now() ) );
		return url.toString();
	}

	function normalizePayload( payload ) {
		if ( payload && typeof payload === 'object' ) {
			return {
				html: String( payload.html || '' ),
				meta: payload.meta || {},
			};
		}
		return { html: String( payload || '' ), meta: {} };
	}

	function createToolbar( region ) {
		const existing = document.querySelector( '[data-dtb-system-live-toolbar]' );
		if ( existing ) return existing;

		const toolbar = document.createElement( 'div' );
		toolbar.className = 'dtb-system-live-toolbar';
		toolbar.setAttribute( 'data-dtb-system-live-toolbar', '1' );
		toolbar.setAttribute( 'data-state', 'live' );
		toolbar.innerHTML = [
			'<div class="dtb-system-live-toolbar__left">',
				'<span class="dtb-system-live-toolbar__status"><span class="dtb-system-live-toolbar__dot" aria-hidden="true"></span><span data-dtb-live-label></span></span>',
				'<span class="dtb-system-live-toolbar__meta" data-dtb-live-meta></span>',
			'</div>',
			'<div class="dtb-system-live-toolbar__right">',
				'<button type="button" class="dtb-system-live-toolbar__button" data-dtb-system-toggle></button>',
				'<button type="button" class="dtb-system-live-toolbar__button dtb-system-live-toolbar__button--primary" data-dtb-system-refresh>' + 'Refresh now' + '</button>',
			'</div>',
		].join( '' );

		region.parentNode.insertBefore( toolbar, region );
		return toolbar;
	}

	function setToolbarState( toolbar, state, message ) {
		if ( ! toolbar ) return;
		toolbar.setAttribute( 'data-state', state );
		const label  = toolbar.querySelector( '[data-dtb-live-label]' );
		const meta   = toolbar.querySelector( '[data-dtb-live-meta]' );
		const toggle = toolbar.querySelector( '[data-dtb-system-toggle]' );

		if ( label ) {
			const labels = {
				live:       'Live',
				fast:       'Live · release in progress',
				refreshing: 'Refreshing…',
				paused:     'Paused',
				error:      'Refresh failed',
			};
			label.textContent = labels[ state ] || 'Live';
		}
		if ( meta && message ) meta.textContent = message;
		if ( toggle ) toggle.textContent = 'paused' === state ? 'Resume' : 'Pause';
	}

	function signatureForRegion( region ) {
		const firstRow = region.querySelector( 'tbody tr:first-child' );
		const logTail  = region.querySelector( '.dtb-system-log-tail' );
		return ( firstRow ? firstRow.textContent.trim() : '' ) || ( logTail ? logTail.textContent.slice( -280 ) : '' );
	}

	function markNewRows( region, previousSignature ) {
		if ( ! previousSignature ) return;
		const firstRow = region.querySelector( 'tbody tr:first-child' );
		if ( ! firstRow ) return;
		const current = firstRow.textContent.trim();
		if ( current && current !== previousSignature ) {
			region.querySelectorAll( 'tbody tr:nth-child(-n+8)' ).forEach( ( row ) => row.classList.add( 'dtb-system-row--new' ) );
		}
	}

	function scrollLogTailToEnd( region ) {
		const tail = region.querySelector( '.dtb-system-log-tail' );
		if ( tail ) tail.scrollTop = tail.scrollHeight;
	}

	function init() {
		if ( ! isSystemManagerPage() ) return;
		const region = document.querySelector( '[data-dtb-live-region][data-dtb-live-module="system-manager"]' );
		if ( ! region ) return;

		region.classList.add( 'dtb-system-live-region' );
		const toolbar = createToolbar( region );
		const regionId = region.getAttribute( 'data-dtb-live-region' );

		const state = {
			controller: null,
			failures: 0,
			inFlight: false,
			lastHtml: region.innerHTML,
			paused: false,
			fast: region.classList.contains( 'dtb-live-fast' ),
			timer: null,
		};

		function scheduleNext() {
			if ( state.timer ) window.clearTimeout( state.timer );
			if ( state.paused || document.hidden ) return;
			const delay = state.fast ? FAST_INTERVAL_MS : BASE_INTERVAL_MS;
			state.timer = window.setTimeout( () => refresh( 'auto' ), delay );
		}

		function refresh( reason ) {
			if ( state.inFlight || document.hidden ) {
				scheduleNext();
				return;
			}
			// Don't clobber unsaved input (e.g. an in-progress ref/release selection).
			if ( regionId && DtbAdmin && DtbAdmin.canReplace && ! DtbAdmin.canReplace( regionId ) ) {
				scheduleNext();
				return;
			}
			const endpoint = region.getAttribute( 'data-dtb-endpoint' );
			if ( ! endpoint ) return;

			state.inFlight = true;
			if ( reason !== 'auto' ) {
				region.classList.add( 'is-refreshing' );
				setToolbarState( toolbar, 'refreshing', 'Checking for updates…' );
			}

			if ( state.controller ) state.controller.abort();
			state.controller = new AbortController();

			fetch( endpointWithCurrentState( endpoint ), {
				signal: state.controller.signal,
				credentials: 'same-origin',
				headers: {
					'Accept': 'application/json',
					'X-WP-Nonce': cfg.nonce || '',
				},
			} )
				.then( function ( response ) {
					if ( ! response.ok ) throw new Error( 'HTTP ' + response.status );
					return response.json();
				} )
				.then( function ( payload ) {
					const parsed = normalizePayload( payload );
					if ( ! parsed.html ) throw new Error( 'Empty observability payload' );

					state.fast = !! parsed.meta.fast_poll;
					region.classList.toggle( 'dtb-live-fast', state.fast );

					if ( parsed.html !== state.lastHtml ) {
						const previousSignature = signatureForRegion( region );
						state.lastHtml = parsed.html;
						region.innerHTML = parsed.html;
						region.classList.add( 'is-updating' );
						window.setTimeout( () => region.classList.remove( 'is-updating' ), 220 );
						markNewRows( region, previousSignature );
						scrollLogTailToEnd( region );
						if ( DtbAdmin?.rebind ) DtbAdmin.rebind( region );
					}

					state.failures = 0;
					const nowLabel = parsed.meta.updated_at || new Date().toLocaleTimeString();
					setToolbarState(
						toolbar,
						state.fast ? 'fast' : 'live',
						'Checked ' + nowLabel + ' · updates shown only when changed'
					);
				} )
				.catch( function ( error ) {
					if ( error?.name === 'AbortError' ) return;
					state.failures += 1;
					setToolbarState( toolbar, 'error', 'Refresh failed' + ( state.failures > 1 ? ' (' + state.failures + '×)' : '' ) + '. Retrying automatically.' );
					window.console.warn( '[DTB System Manager] live refresh failed:', error, reason );
				} )
				.finally( function () {
					state.inFlight = false;
					region.classList.remove( 'is-refreshing' );
					scheduleNext();
				} );
		}

		const toggleButton = toolbar.querySelector( '[data-dtb-system-toggle]' );
		if ( toggleButton ) {
			toggleButton.addEventListener( 'click', function () {
				state.paused = ! state.paused;
				if ( state.paused ) {
					if ( state.timer ) window.clearTimeout( state.timer );
					setToolbarState( toolbar, 'paused', 'Auto-refresh paused. Content will not update until resumed.' );
				} else {
					refresh( 'resume' );
				}
			} );
		}

		const refreshButton = toolbar.querySelector( '[data-dtb-system-refresh]' );
		if ( refreshButton ) {
			refreshButton.addEventListener( 'click', function () {
				if ( state.timer ) window.clearTimeout( state.timer );
				refresh( 'button' );
			} );
		}

		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				if ( state.timer ) window.clearTimeout( state.timer );
			} else if ( ! state.paused ) {
				refresh( 'visible' );
			}
		} );

		// Tab switches are handled by the generic DtbAdmin.liveNavigate() click
		// binding; just reset our own loop/toolbar once that swap lands.
		region.addEventListener( 'dtb:live:navigated', function () {
			state.lastHtml = region.innerHTML;
			state.failures = 0;
			state.fast = region.classList.contains( 'dtb-live-fast' );
			setToolbarState( toolbar, state.fast ? 'fast' : 'live', 'Loaded.' );
			scheduleNext();
		} );

		setToolbarState( toolbar, state.fast ? 'fast' : 'live', 'Starting live updates…' );
		scheduleNext();
	}

	suppressGlobalAutoPolling();

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
