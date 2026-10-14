( function () {
	'use strict';

	const config = window.DTB_CHECKOUT_PERFORMANCE || {};
	const checkoutRootSelector = '.wc-block-checkout';
	const paymentBlockSelector = '.wp-block-woocommerce-checkout-payment-block, .wc-block-checkout__payment-method';
	const expressBlockSelector = '.wp-block-woocommerce-checkout-express-payment-block, .wc-block-components-express-payment';
	const providerFrameSelector = [
		'iframe[name^="__privateStripeFrame"]',
		'.wc-stripe-upe-element iframe',
		'.StripeElement iframe',
		'iframe[src*="stripe.com"]',
		'iframe[src*="stripe.network"]',
	].join( ',' );
	const allowedPaymentHosts = [ 'stripe.com', 'stripe.network', 'pay.google.com', 'payments.google.com', 'apple.com' ];
	const reportedSignatures = new Set();

	let runtimeObserver = null;
	let observedRoot = null;
	let maintenanceQueued = false;
	let paymentWatchTimer = 0;
	let vitalsReported = false;
	let clsValue = 0;
	let lcpValue = 0;

	function checkoutRoot() {
		return document.querySelector( checkoutRootSelector );
	}

	function eventId() {
		if ( window.crypto?.randomUUID ) {
			return window.crypto.randomUUID().replace( /-/g, '' );
		}
		return `${ Date.now().toString( 36 ) }${ Math.random().toString( 36 ).slice( 2, 14 ) }`;
	}

	function safeSource( value ) {
		if ( ! value ) return '';
		try {
			const url = new URL( String( value ), window.location.href );
			return `${ url.origin }${ url.pathname }`;
		} catch {
			return String( value ).slice( 0, 240 );
		}
	}

	function report( kind, message, detail = {}, location = {} ) {
		if ( ! config.telemetryUrl || ! config.telemetryNonce ) return;
		const normalizedMessage = String( message || kind || 'Checkout runtime event' ).slice( 0, 500 );
		const source = safeSource( location.source || '' );
		const signature = `${ kind }|${ normalizedMessage }|${ source }`;
		if ( reportedSignatures.has( signature ) ) return;
		reportedSignatures.add( signature );

		fetch( config.telemetryUrl, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: {
				'Content-Type': 'application/json',
				// Preserve cookie-authenticated user context; the DTB nonce separately authorizes telemetry.
				'X-WP-Nonce': config.wpRestNonce,
				'X-DTB-Checkout-Telemetry': config.telemetryNonce,
			},
			body: JSON.stringify( {
				event_id: eventId(),
				kind,
				message: normalizedMessage,
				source,
				line: Number( location.line || 0 ),
				column: Number( location.column || 0 ),
				viewport_w: Math.round( window.innerWidth || 0 ),
				step: 'continuous',
				detail,
			} ),
		} ).catch( () => undefined );
	}

	function resourceTargetSource( target ) {
		if ( target instanceof HTMLScriptElement ) return target.src;
		if ( target instanceof HTMLLinkElement ) return target.href;
		if ( target instanceof HTMLIFrameElement ) return target.src;
		return '';
	}

	function handleWindowError( event ) {
		const target = event.target;
		if ( target instanceof HTMLScriptElement || target instanceof HTMLLinkElement || target instanceof HTMLIFrameElement ) {
			report( 'resource_error', `Checkout resource failed to load: ${ target.tagName.toLowerCase() }`, { tag: target.tagName.toLowerCase() }, { source: resourceTargetSource( target ) } );
			return;
		}
		if ( event instanceof ErrorEvent ) {
			report( 'js_error', event.message || event.error?.message || 'Unhandled checkout JavaScript error', {}, { source: event.filename, line: event.lineno, column: event.colno } );
		}
	}

	function handleUnhandledRejection( event ) {
		const reason = event.reason;
		const message = reason instanceof Error ? reason.message : typeof reason === 'string' ? reason : 'Unhandled checkout promise rejection';
		report( 'unhandled_rejection', message );
	}

	function isProviderFrameReady() {
		const paymentBlock = document.querySelector( paymentBlockSelector );
		return Boolean( paymentBlock?.querySelector( providerFrameSelector ) );
	}

	function expressSurface() {
		const block = document.querySelector( expressBlockSelector );
		if ( ! block ) return null;
		return block.querySelector( 'iframe, button:not([disabled]), [role="button"]' ) ? block : null;
	}

	function fallbackNotice() {
		return document.querySelector( '[data-dtb-checkout-runtime-fallback="1"]' );
	}

	function removeFallbackNoticeIfRecovered() {
		if ( isProviderFrameReady() ) fallbackNotice()?.remove();
	}

	function showPaymentFallback() {
		const paymentBlock = document.querySelector( paymentBlockSelector );
		if ( ! paymentBlock || fallbackNotice() || isProviderFrameReady() ) return;

		const express = expressSurface();
		const notice = document.createElement( 'div' );
		notice.className = 'wc-block-components-notice-banner is-error';
		notice.setAttribute( 'role', 'alert' );
		notice.setAttribute( 'data-dtb-checkout-runtime-fallback', '1' );

		const content = document.createElement( 'div' );
		content.className = 'wc-block-components-notice-banner__content';
		const message = document.createElement( 'p' );
		message.textContent = express
			? 'Secure card payment is taking longer than expected. You can try an available express payment option or reload the payment surface.'
			: 'Secure payment options are taking longer than expected. Reload the payment surface to try again. Your cart is saved.';
		content.append( message );

		if ( express ) {
			const expressButton = document.createElement( 'button' );
			expressButton.type = 'button';
			expressButton.className = 'wc-block-components-button';
			expressButton.textContent = 'Try express checkout';
			expressButton.addEventListener( 'click', () => {
				express.scrollIntoView( { behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth', block: 'center' } );
				const focusTarget = express.querySelector( 'button:not([disabled]), [role="button"]' );
				if ( focusTarget instanceof HTMLElement ) focusTarget.focus( { preventScroll: true } );
			} );
			content.append( expressButton );
		}

		const reloadButton = document.createElement( 'button' );
		reloadButton.type = 'button';
		reloadButton.className = 'wc-block-components-button';
		reloadButton.textContent = 'Reload payment options';
		reloadButton.addEventListener( 'click', () => window.location.reload() );
		content.append( reloadButton );
		notice.append( content );
		paymentBlock.prepend( notice );
	}

	function checkPaymentSurface() {
		paymentWatchTimer = 0;
		if ( isProviderFrameReady() ) {
			removeFallbackNoticeIfRecovered();
			return;
		}
		report(
			'payment_surface_timeout',
			'Official Stripe payment surface did not become ready within the checkout timeout.',
			{ timeout_ms: Number( config.paymentSurfaceTimeoutMs || 15000 ), express_available: Boolean( expressSurface() ) }
		);
		showPaymentFallback();
	}

	function armPaymentSurfaceWatch() {
		if ( paymentWatchTimer || isProviderFrameReady() ) return;
		const timeout = Math.max( 5000, Math.min( 30000, Number( config.paymentSurfaceTimeoutMs || 15000 ) ) );
		paymentWatchTimer = window.setTimeout( checkPaymentSurface, timeout );
	}

	function optimizeOrderSummaryImages( root = document ) {
		root.querySelectorAll?.( '.wc-block-components-order-summary img, .wc-block-cart-item__image img' ).forEach( ( image ) => {
			if ( ! ( image instanceof HTMLImageElement ) || image.dataset.dtbCheckoutImagePolicy === '1' ) return;
			image.dataset.dtbCheckoutImagePolicy = '1';
			image.decoding = 'async';
			const rect = image.getBoundingClientRect();
			if ( rect.top > window.innerHeight * 1.05 ) {
				image.loading = 'lazy';
				image.setAttribute( 'fetchpriority', 'low' );
			}
		} );
	}

	function countFilledControls( root ) {
		if ( ! ( root instanceof Element ) ) return 0;
		return Array.from( root.querySelectorAll( 'input:not([type="hidden"]):not([type="password"]), select, textarea' ) ).reduce( ( count, control ) => {
			if ( control instanceof HTMLInputElement && ( control.type === 'checkbox' || control.type === 'radio' ) ) return count + ( control.checked ? 1 : 0 );
			return count + ( 'value' in control && String( control.value || '' ).trim() !== '' ? 1 : 0 );
		}, 0 );
	}

	function bindRuntimeObserver( root ) {
		runtimeObserver?.disconnect();
		const previousRoot = observedRoot;
		observedRoot = root;
		if ( previousRoot && root && previousRoot !== root ) {
			const filledBefore = countFilledControls( previousRoot );
			const filledAfter = countFilledControls( root );
			report( 'checkout_root_replaced', 'WooCommerce checkout root was replaced during the active checkout session.', {
				filled_before: filledBefore,
				filled_after: filledAfter,
				state_loss_suspected: filledBefore > 0 && filledAfter < filledBefore,
			} );
		}
		if ( ! root ) return;
		runtimeObserver = new MutationObserver( queueMaintenance );
		runtimeObserver.observe( root, { childList: true, subtree: true } );
	}

	function queueMaintenance() {
		if ( maintenanceQueued ) return;
		maintenanceQueued = true;
		window.requestAnimationFrame( () => {
			maintenanceQueued = false;
			const root = checkoutRoot();
			if ( root && root !== observedRoot ) bindRuntimeObserver( root );
			optimizeOrderSummaryImages( root || document );
			removeFallbackNoticeIfRecovered();
			armPaymentSurfaceWatch();
		} );
	}

	function hostAllowedForPayment( host ) {
		return allowedPaymentHosts.some( ( allowed ) => host === allowed || host.endsWith( `.${ allowed }` ) );
	}

	function auditThirdPartyResources() {
		if ( ! window.performance?.getEntriesByType ) return;
		const unexpected = new Set();
		window.performance.getEntriesByType( 'resource' ).forEach( ( entry ) => {
			try {
				const url = new URL( entry.name, window.location.href );
				if ( url.origin === window.location.origin || hostAllowedForPayment( url.hostname.toLowerCase() ) ) return;
				unexpected.add( url.hostname.toLowerCase() );
			} catch {
				// Ignore malformed performance entries.
			}
		} );
		if ( unexpected.size > 0 ) {
			report( 'third_party_budget', 'Unexpected third-party resources loaded during checkout.', { hosts: Array.from( unexpected ).slice( 0, 12 ), count: unexpected.size } );
		}
	}

	function observeVitals() {
		if ( typeof PerformanceObserver !== 'function' ) return;
		try {
			const clsObserver = new PerformanceObserver( ( list ) => {
				list.getEntries().forEach( ( entry ) => {
					if ( ! entry.hadRecentInput ) clsValue += entry.value;
				} );
			} );
			clsObserver.observe( { type: 'layout-shift', buffered: true } );
		} catch {}
		try {
			const lcpObserver = new PerformanceObserver( ( list ) => {
				const entries = list.getEntries();
				const latest = entries[ entries.length - 1 ];
				if ( latest ) lcpValue = latest.startTime || 0;
			} );
			lcpObserver.observe( { type: 'largest-contentful-paint', buffered: true } );
		} catch {}
	}

	function reportVitalsIfNeeded() {
		if ( vitalsReported ) return;
		vitalsReported = true;
		const navigation = window.performance?.getEntriesByType?.( 'navigation' )?.[ 0 ];
		const loadMs = Number( navigation?.loadEventEnd || 0 );
		const domContentLoadedMs = Number( navigation?.domContentLoadedEventEnd || 0 );
		const lcpMs = Math.round( lcpValue );
		const cls = Number( clsValue.toFixed( 4 ) );
		if ( loadMs <= 3000 && lcpMs <= 2500 && cls <= 0.1 ) return;
		report( 'checkout_vitals', 'Checkout performance thresholds were exceeded.', {
			load_ms: Math.round( loadMs ),
			dom_content_loaded_ms: Math.round( domContentLoadedMs ),
			lcp_ms: lcpMs,
			cls,
		} );
	}

	function cleanup() {
		runtimeObserver?.disconnect();
		if ( paymentWatchTimer ) window.clearTimeout( paymentWatchTimer );
		reportVitalsIfNeeded();
	}

	function initialize() {
		window.addEventListener( 'error', handleWindowError, true );
		window.addEventListener( 'unhandledrejection', handleUnhandledRejection );
		window.addEventListener( 'pagehide', cleanup, { once: true } );
		document.addEventListener( 'visibilitychange', () => {
			if ( document.visibilityState === 'hidden' ) reportVitalsIfNeeded();
		} );

		observeVitals();
		bindRuntimeObserver( checkoutRoot() );
		queueMaintenance();
		window.setTimeout( queueMaintenance, 1000 );
		window.setTimeout( queueMaintenance, 5000 );
		window.setTimeout( auditThirdPartyResources, 2500 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initialize, { once: true } );
	} else {
		initialize();
	}
} )();
