/* global DTB_MARKETPLACE, jQuery */
( function ( $, cfg ) {
	'use strict';

	// ---- REST helper ----
	function dtbFetch( method, path, data ) {
		return $.ajax( {
			method:  method,
			url:     cfg.restBase + path,
			headers: { 'X-WP-Nonce': cfg.nonce },
			contentType: 'application/json',
			data: data ? JSON.stringify( data ) : undefined,
		} );
	}

	// ---- Channel sync buttons ----
	$( '[data-dtb-sync-channel]' ).on( 'click', function () {
		var channel = $( this ).data( 'dtb-sync-channel' );
		var $btn    = $( this );
		$btn.text( cfg.i18n.syncing ).prop( 'disabled', true );
		dtbFetch( 'POST', 'orders/sync', { channel: channel } )
			.always( function () { $btn.text( cfg.i18n.syncDone ).prop( 'disabled', false ); } );
	} );

	// ---- Live refresh ----
	$( '[data-dtb-live-refresh]' ).on( 'click', function () {
		location.reload();
	} );

	// ---- Order actions ----
	$( document ).on( 'click', '.dtb-order-action', function () {
		var $btn    = $( this );
		var action  = $btn.data( 'action' );
		var id      = $btn.data( 'id' );
		var mpId    = $btn.data( 'mp-id' );

		if ( 'sync' === action ) {
			$btn.text( cfg.i18n.syncing ).prop( 'disabled', true );
			dtbFetch( 'POST', 'orders/' + id + '/sync', {} )
				.always( function () {
					$btn.text( 'Sync' ).prop( 'disabled', false );
				} );
		} else if ( 'link' === action ) {
			var wooId = window.prompt( 'Enter Woo Order ID to link:' );
			if ( wooId ) {
				dtbFetch( 'POST', 'orders/' + id + '/link', { woo_order_id: parseInt( wooId, 10 ) } )
					.done( function () { location.reload(); } );
			}
		} else if ( 'messages' === action ) {
			window.location.href = 'admin.php?page=dtb-marketplace-messages';
		}
	} );

	// ---- Thread drawer ----
	var $drawer  = $( '#dtb-thread-drawer' );
	var $overlay = $( '#dtb-drawer-overlay' );

	function openDrawer( convId, channel ) {
		$drawer.find( '#dtb-thread-body' ).html( '<p class="dtb-loading-msg">' + cfg.i18n.syncing + '</p>' );
		$drawer.removeClass( 'dtb-drawer--closed' ).attr( 'aria-hidden', 'false' );
		$overlay.removeClass( 'dtb-drawer-overlay--hidden' );

		dtbFetch( 'GET', 'conversations/' + convId + '/messages', null )
			.done( function ( res ) {
				renderThread( convId, channel, res );
			} )
			.fail( function () {
				$drawer.find( '#dtb-thread-body' ).html( '<p class="dtb-text--danger">' + cfg.i18n.error + '</p>' );
			} );
	}

	function closeDrawer() {
		$drawer.addClass( 'dtb-drawer--closed' ).attr( 'aria-hidden', 'true' );
		$overlay.addClass( 'dtb-drawer-overlay--hidden' );
	}

	function renderThread( convId, channel, res ) {
		var $body = $drawer.find( '#dtb-thread-body' );
		$body.empty();

		var messages = res.messages || [];
		if ( ! messages.length ) {
			$body.append( '<p class="dtb-empty-state">No messages yet.</p>' );
		} else {
			messages.forEach( function ( m ) {
				var dir     = m.direction === 'inbound' ? 'buyer' : 'operator';
				var $msg    = $( '<div class="dtb-msg dtb-msg--' + dir + '"></div>' );
				var preview = m.body_preview || m.redacted_preview || '[encrypted]';
				$msg.html(
					'<span class="dtb-msg__sender">' + escHtml( dir ) + '</span>' +
					'<p class="dtb-msg__body">' + escHtml( preview ) + '</p>' +
					'<span class="dtb-msg__ts">' + escHtml( m.created_at || '' ) + '</span>'
				);
				$body.append( $msg );
			} );
		}

		// Wire reply composer (eBay page).
		var $replyConvInput = $( '#dtb-ebay-reply-conversation-id' );
		if ( $replyConvInput.length ) {
			$replyConvInput.val( convId );
		}
	}

	$( document ).on( 'click', '.dtb-thread-open', function () {
		var convId  = $( this ).data( 'id' );
		var channel = $( this ).data( 'channel' );
		openDrawer( convId, channel );
	} );

	$( '.dtb-drawer__close' ).on( 'click', closeDrawer );
	$( '#dtb-drawer-overlay' ).on( 'click', closeDrawer );

	// ---- eBay reply send ----
	$( '#dtb-ebay-send-btn' ).on( 'click', function () {
		var convId = $( '#dtb-ebay-reply-conversation-id' ).val();
		var body   = $( '#dtb-ebay-reply-body' ).val().trim();
		var $status = $( '.dtb-reply-status' );
		if ( ! convId || ! body ) return;

		$( this ).prop( 'disabled', true );
		$status.text( cfg.i18n.sending );

		dtbFetch( 'POST', '../ebay/replies', {
			conversation_id: parseInt( convId, 10 ),
			buyer_username:  'unknown', // populated server-side from conversation row
			body:            body,
		} )
			.done( function ( res ) {
				$status.text( res.ok ? cfg.i18n.sent : cfg.i18n.error + ' ' + ( res.error || '' ) );
				if ( res.ok ) { $( '#dtb-ebay-reply-body' ).val( '' ); }
			} )
			.fail( function () { $status.text( cfg.i18n.error ); } )
			.always( function () { $( '#dtb-ebay-send-btn' ).prop( 'disabled', false ); } );
	} );

	// ---- Amazon: fetch available actions ----
	var $amazonPanel = $( '#dtb-amazon-order-comms' );
	if ( $amazonPanel.length ) {
		var mpOrderId = $amazonPanel.data( 'mp-order-id' );
		dtbFetch( 'GET', '../amazon/messaging/actions?order_id=' + encodeURIComponent( mpOrderId ), null )
			.done( function ( res ) {
				renderAmazonActions( res );
			} )
			.fail( function () {
				$( '#dtb-amazon-actions-list' ).html( '<p class="dtb-text--danger">' + cfg.i18n.error + '</p>' );
			} );
	}

	function renderAmazonActions( res ) {
		var $list = $( '#dtb-amazon-actions-list' ).empty().attr( 'data-loading', 'false' );
		var actions = res.actions || [];
		if ( ! actions.length ) {
			$list.html( '<p class="dtb-loading-msg">' + cfg.i18n.noActions + '</p>' );
			return;
		}
		actions.forEach( function ( a ) {
			var $btn = $( '<button class="dtb-amazon-action-btn button"></button>' )
				.text( a.action_type )
				.attr( 'data-action-type', a.action_type );
			if ( ! a.allowed ) {
				$btn.prop( 'disabled', true ).attr( 'title', a.reason || 'Not allowed' );
			}
			$list.append( $btn );
		} );
	}

	$( document ).on( 'click', '.dtb-amazon-action-btn:not([disabled])', function () {
		$( '.dtb-amazon-action-btn' ).removeClass( 'dtb-amazon-action-btn--active' );
		$( this ).addClass( 'dtb-amazon-action-btn--active' );
		var $composer = $( '#dtb-amazon-reply-composer' );
		$composer.removeClass( 'dtb-reply-composer--hidden' );
		$composer.find( '.dtb-selected-action-label' ).text( 'Action: ' + $( this ).data( 'action-type' ) );
		$( '#dtb-amazon-send-btn' ).data( 'action-type', $( this ).data( 'action-type' ) );
	} );

	$( '#dtb-amazon-cancel-btn' ).on( 'click', function () {
		$( '#dtb-amazon-reply-composer' ).addClass( 'dtb-reply-composer--hidden' );
		$( '.dtb-amazon-action-btn' ).removeClass( 'dtb-amazon-action-btn--active' );
	} );

	$( '#dtb-amazon-send-btn' ).on( 'click', function () {
		var $btn        = $( this );
		var mpOrderId   = $btn.data( 'mp-order-id' );
		var actionType  = $btn.data( 'action-type' );
		var body        = $( '#dtb-amazon-reply-body' ).val().trim();
		var $result     = $( '#dtb-amazon-send-result' );
		if ( ! body ) return;

		$btn.prop( 'disabled', true );
		$result.text( cfg.i18n.sending );

		dtbFetch( 'POST', '../amazon/messaging/send', {
			mp_order_id: mpOrderId,
			action_type: actionType,
			body:        body,
		} )
			.done( function ( res ) {
				$result.text( res.ok ? cfg.i18n.sent : cfg.i18n.error + ' ' + ( res.error || '' ) );
				if ( res.ok ) { $( '#dtb-amazon-reply-body' ).val( '' ); }
			} )
			.fail( function () { $result.text( cfg.i18n.error ); } )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	// ---- Exception actions ----
	$( document ).on( 'click', '.dtb-exc-action', function () {
		var $btn   = $( this );
		var action = $btn.data( 'action' );
		var id     = $btn.data( 'id' );
		var msg    = action === 'retry' ? cfg.i18n.confirmRetry : cfg.i18n.confirmResolve;
		if ( ! window.confirm( msg ) ) return;

		dtbFetch( 'POST', 'exceptions/' + id + '/' + action, {} )
			.done( function () {
				$btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
			} );
	} );

	// ---- Settings forms ----
	$( '.dtb-settings-form' ).on( 'submit', function ( e ) {
		e.preventDefault();
		var $form   = $( this );
		var channel = $form.data( 'channel' );
		var $result = $form.find( '.dtb-settings-result' );
		var data    = { channel: channel };

		$form.serializeArray().forEach( function ( f ) {
			if ( f.value !== '' ) { data[ f.name ] = f.value; }
		} );
		// Checkbox: if unchecked it won't serialize.
		if ( ! data.hasOwnProperty( 'is_sandbox' ) ) { data.is_sandbox = 0; }

		$result.text( cfg.i18n.saving );

		$.ajax( {
			method:  'POST',
			url:     cfg.restBase + 'settings',
			headers: { 'X-WP-Nonce': $form.data( 'nonce' ) },
			contentType: 'application/json',
			data:    JSON.stringify( data ),
		} )
			.done( function ( res ) { $result.text( res.ok ? cfg.i18n.saved : cfg.i18n.error ); } )
			.fail( function () { $result.text( cfg.i18n.error ); } );
	} );

	$( '.dtb-test-connection' ).on( 'click', function () {
		var $btn    = $( this );
		var channel = $btn.data( 'channel' );
		var $result = $btn.next( '.dtb-settings-result' );
		$btn.prop( 'disabled', true );
		$result.text( cfg.i18n.syncing );
		$.ajax( {
			method:  'POST',
			url:     cfg.restBase + 'settings/test',
			headers: { 'X-WP-Nonce': cfg.nonce },
			contentType: 'application/json',
			data:    JSON.stringify( { channel: channel } ),
		} )
			.done( function ( res ) {
				$result.text( ( res.status === 'ok' ? '✓ ' : '✗ ' ) + ( res.detail || res.status ) );
			} )
			.fail( function () { $result.text( cfg.i18n.error ); } )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	// ---- eBay OAuth button ----
	$( '.dtb-ebay-oauth-connect' ).on( 'click', function ( e ) {
		e.preventDefault();
		$.ajax( {
			method:  'GET',
			url:     cfg.restBase.replace( 'marketplace/', '' ) + 'marketplace/ebay/oauth-url',
			headers: { 'X-WP-Nonce': cfg.nonce },
		} )
			.done( function ( res ) {
				if ( res.url ) { window.open( res.url, '_blank', 'width=900,height=700' ); }
			} );
	} );

	// ---- eBay message sync ----
	$( '#dtb-ebay-msg-sync-btn' ).on( 'click', function () {
		var $btn = $( this );
		$btn.text( cfg.i18n.syncing ).prop( 'disabled', true );
		$.ajax( {
			method:  'POST',
			url:     cfg.restBase + 'orders/sync',
			headers: { 'X-WP-Nonce': cfg.nonce },
			contentType: 'application/json',
			data:    JSON.stringify( { channel: 'ebay' } ),
		} )
			.always( function () { $btn.text( 'Sync eBay Messages' ).prop( 'disabled', false ); } );
	} );

	// ---- Utility ----
	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

} )( jQuery, window.DTB_MARKETPLACE || {} );
