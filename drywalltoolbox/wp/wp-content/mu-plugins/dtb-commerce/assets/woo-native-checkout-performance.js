( function () {
	'use strict';

	const config = window.DTB_CHECKOUT_PERFORMANCE || {};
	const reported = new Set();
	let cls = 0;
	let lcp = 0;
	let sentVitals = false;

	function eventId() {
		return window.crypto?.randomUUID?.().replace( /-/g, '' )
			|| `${ Date.now().toString( 36 ) }${ Math.random().toString( 36 ).slice( 2, 14 ) }`;
	}

	function report( kind, message, source = '', detail = {} ) {
		if ( ! config.telemetryUrl ) return;
		const safeSource = (() => {
			try { const url = new URL( source, location.href ); return `${ url.origin }${ url.pathname }`; }
			catch { return ''; }
		})();
		const signature = `${ kind }|${ message }|${ safeSource }`;
		if ( reported.has( signature ) ) return;
		reported.add( signature );
		fetch( config.telemetryUrl, {
			method: 'POST', credentials: 'same-origin', keepalive: true,
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				event_id: eventId(), kind, message: String( message || kind ).slice( 0, 500 ),
				source: safeSource, viewport_w: Math.round( innerWidth || 0 ), step: 'continuous', detail,
			} ),
		} ).catch( () => undefined );
	}

	function handleError( event ) {
		const target = event.target;
		if ( target instanceof HTMLScriptElement || target instanceof HTMLLinkElement || target instanceof HTMLIFrameElement ) {
			const source = target.src || target.href || '';
			report( 'resource_error', `Checkout resource failed to load: ${ target.tagName.toLowerCase() }`, source );
		} else if ( event instanceof ErrorEvent ) {
			report( 'js_error', event.message || 'Checkout JavaScript error', event.filename );
		}
	}

	function reportVitals() {
		if ( sentVitals ) return;
		sentVitals = true;
		const navigation = performance.getEntriesByType?.( 'navigation' )?.[ 0 ];
		const loadMs = Math.round( navigation?.loadEventEnd || 0 );
		if ( loadMs <= 3000 && lcp <= 2500 && cls <= 0.1 ) return;
		report( 'checkout_vitals', 'Checkout performance thresholds were exceeded.', '', {
			load_ms: loadMs, lcp_ms: Math.round( lcp ), cls: Number( cls.toFixed( 4 ) ),
		} );
	}

	function initialize() {
		addEventListener( 'error', handleError, true );
		addEventListener( 'unhandledrejection', ( event ) => {
			const message = event.reason instanceof Error ? event.reason.message : String( event.reason || 'Checkout promise rejected' );
			report( 'unhandled_rejection', message );
		} );
		addEventListener( 'pagehide', reportVitals, { once: true } );
		if ( typeof PerformanceObserver !== 'function' ) return;
		try {
			new PerformanceObserver( ( list ) => list.getEntries().forEach( ( entry ) => { if ( ! entry.hadRecentInput ) cls += entry.value; } ) )
				.observe( { type: 'layout-shift', buffered: true } );
		} catch {}
		try {
			new PerformanceObserver( ( list ) => { const entries = list.getEntries(); if ( entries.length ) lcp = entries[ entries.length - 1 ].startTime || 0; } )
				.observe( { type: 'largest-contentful-paint', buffered: true } );
		} catch {}
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', initialize, { once: true } );
	else initialize();
} )();
