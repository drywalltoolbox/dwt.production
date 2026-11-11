/* global DtbAdminChat, DtbWorkbench, dtbAdminConfig */
/**
 * DTB Repairs — Support chat component adapter.
 *
 * This file intentionally does not implement its own chat UI. It adapts repair
 * conversation payloads into the shared DtbAdminChat component contract so the
 * Repairs conversation panel uses the exact same admin chat component as Support.
 */
( function () {
	'use strict';

	var WB = window.DtbWorkbench || {};
	var CONFIG = window.dtbAdminConfig || {};
	var REST = ( CONFIG.restUrl || '/wp-json' ).replace( /\/$/, '' );
	var state = {
		repairId: 0,
		payload: null,
		loading: false,
		scheduled: false,
	};

	function qs( selector, ctx ) {
		return ( ctx || document ).querySelector( selector );
	}

	function getChat() {
		return window.DtbAdminChat || null;
	}

	function getModal() {
		return qs( '#dtb-repair-modal' );
	}

	function getPanel() {
		var modal = getModal();
		return modal ? qs( '[data-panel="conversation"]', modal ) : null;
	}

	function getOpenRepairId() {
		var params = new URLSearchParams( window.location.search );
		var id = parseInt( params.get( 'open_repair' ) || '', 10 );
		if ( id > 0 ) {
			return id;
		}
		var title = qs( '#dtb-repair-modal-title' );
		var match = title && String( title.textContent || '' ).match( /#(\d+)/ );
		return match ? parseInt( match[ 1 ], 10 ) : 0;
	}

	function apiFetch( url, options ) {
		if ( WB.apiFetch ) {
			return WB.apiFetch( url, options || {} );
		}
		options = options || {};
		options.headers = Object.assign( {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			'X-WP-Nonce': CONFIG.nonce || '',
		}, options.headers || {} );
		options.credentials = 'same-origin';
		return fetch( url, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : 'HTTP ' + response.status );
				}
				return data;
			} );
		} );
	}

	function toast( message, type ) {
		if ( WB.showToast ) {
			WB.showToast( message, type || 'success' );
		}
	}

	function repairMacros( record ) {
		var customer = record.customer_name || 'there';
		return [
			{
				category: 'Repair Updates',
				items: [
					{ label: 'Received / reviewing', text: 'Hi ' + customer + ',\n\nWe received your repair request and are reviewing the details now. We will update you as soon as we complete the initial assessment.\n\nBest,\nDrywall Toolbox Repair Team' },
					{ label: 'Need photos', text: 'Hi ' + customer + ',\n\nCould you please send clear photos of the tool, serial label, and the problem area? This will help us diagnose the repair accurately.\n\nThank you,\nDrywall Toolbox Repair Team' },
					{ label: 'Technician update', text: 'Hi ' + customer + ',\n\nOur technician has reviewed your repair and we are continuing the service process. We will keep you updated as the repair progresses.\n\nBest,\nDrywall Toolbox Repair Team' },
				],
			},
			{
				category: 'Quote & Shipping',
				items: [
					{ label: 'Quote ready', text: 'Hi ' + customer + ',\n\nYour repair quote has been prepared. Please review it and let us know how you would like to proceed.\n\nBest,\nDrywall Toolbox Repair Team' },
					{ label: 'Ready to ship', text: 'Hi ' + customer + ',\n\nYour repair is complete and is being prepared for return shipment. We will provide tracking as soon as it is available.\n\nBest,\nDrywall Toolbox Repair Team' },
				],
			},
		];
	}

	function normalizeConversation( payload ) {
		var messages = Array.isArray( payload.conversation ) ? payload.conversation : [];
		return messages.map( function ( msg ) {
			var type = String( msg.type || msg.event_group || msg.group || '' ).toLowerCase();
			var eventType = String( msg.event_type || '' ).toLowerCase();
			var actorType = '';
			if ( type === 'customer' || type === 'inbound' ) {
				actorType = 'customer';
			} else if ( type === 'staff' || type === 'admin' || type === 'operator' || type === 'reply' ) {
				actorType = 'staff';
			}
			return Object.assign( {}, msg, {
				actor_type: msg.actor_type || actorType,
				event_type: eventType || ( type === 'internal' ? 'repair.internal_note' : ( actorType === 'staff' ? 'repair.reply_staff' : 'repair.customer_reply' ) ),
				body: msg.body || msg.message || msg.summary || '',
				actor_label: msg.actor_label || msg.user_label || msg.author || msg.author_name || '',
			} );
		} );
	}

	function renderConversation( payload ) {
		var Chat = getChat();
		var panel = getPanel();
		if ( ! Chat || ! panel ) {
			return;
		}

		var record = payload.record || {};
		var messages = normalizeConversation( payload );
		var customerName = record.customer_name || record.customer_email || 'Customer';
		var signature = JSON.stringify( {
			id: record.id || state.repairId,
			messages: messages,
			component: 'DtbAdminChat',
		} );

		if ( panel.getAttribute( 'data-dtb-admin-chat-signature' ) === signature ) {
			return;
		}

		panel.setAttribute( 'data-dtb-admin-chat-signature', signature );
		panel.setAttribute( 'data-dtb-admin-chat-instance', 'repair-conversation' );
		panel.classList.add( 'dtb-support-chat-panel', 'dtb-repair-support-chat-panel' );
		panel.innerHTML = Chat.renderConversationPanel( {
			threadId: 'dtb-repair-chat-thread',
			macroPanelId: 'dtb-repair-macro-panel',
			messages: messages,
			customerName: customerName,
			emptyText: 'No repair conversation yet.',
			macros: repairMacros( record ),
			modeAttr: 'data-dtb-repair-compose-mode',
			macroToggleAttr: 'data-dtb-repair-macro-toggle',
			macroAttr: 'data-dtb-repair-macro',
			formClass: 'dtb-repair-support-chat-form',
			replyTypeAttr: 'data-dtb-repair-reply-type',
			textareaName: 'message',
		} );

		var thread = qs( '#dtb-repair-chat-thread', panel );
		if ( thread ) {
			thread.scrollTop = thread.scrollHeight;
		}
	}

	function loadDetail( force ) {
		var modal = getModal();
		if ( ! modal || ! modal.closest( '.dtb-modal-overlay--open' ) ) {
			return;
		}
		var repairId = getOpenRepairId();
		if ( ! repairId || state.loading ) {
			return;
		}
		if ( ! force && state.payload && state.repairId === repairId ) {
			renderConversation( state.payload );
			return;
		}
		state.loading = true;
		state.repairId = repairId;
		apiFetch( REST + '/dtb/v1/admin/repairs/' + repairId + '/detail', { method: 'GET' } )
			.then( function ( payload ) {
				state.payload = payload || {};
				renderConversation( state.payload );
			} )
			.catch( function ( error ) {
				console.warn( 'DTB repair chat failed to load:', error );
			} )
			.finally( function () {
				state.loading = false;
			} );
	}

	function sendRepairMessage( type, body ) {
		var path = type === 'note' ? 'internal-note' : 'customer-message';
		return apiFetch( REST + '/dtb/v1/admin/repairs/' + state.repairId + '/' + path, {
			method: 'POST',
			body: JSON.stringify( { body: body } ),
		} ).then( function ( payload ) {
			state.payload = payload || state.payload;
			renderConversation( state.payload );
			return payload;
		} );
	}

	function scheduleLoad( force ) {
		if ( state.scheduled ) {
			return;
		}
		state.scheduled = true;
		window.requestAnimationFrame( function () {
			state.scheduled = false;
			loadDetail( !! force );
		} );
	}

	function bindEvents() {
		document.addEventListener( 'click', function ( event ) {
			var Chat = getChat();
			var modeButton = event.target.closest( '[data-dtb-repair-compose-mode]' );
			if ( modeButton && modeButton.closest( '#dtb-repair-modal' ) ) {
				if ( Chat && Chat.setComposeMode ) {
					Chat.setComposeMode( modeButton.closest( '.dtb-chat-compose' ), modeButton.getAttribute( 'data-dtb-repair-compose-mode' ) || 'reply', {
						formSelector: '.dtb-repair-support-chat-form',
						replyTypeAttr: 'data-dtb-repair-reply-type',
						modeAttr: 'data-dtb-repair-compose-mode',
					} );
				}
			}

			var toggle = event.target.closest( '[data-dtb-repair-macro-toggle]' );
			if ( toggle && toggle.closest( '#dtb-repair-modal' ) ) {
				var macroPanel = qs( '#dtb-repair-macro-panel' );
				if ( macroPanel ) {
					var isHidden = macroPanel.hasAttribute( 'hidden' );
					macroPanel.toggleAttribute( 'hidden', ! isHidden );
					toggle.setAttribute( 'aria-expanded', isHidden ? 'true' : 'false' );
				}
			}

			var macro = event.target.closest( '[data-dtb-repair-macro]' );
			if ( macro && macro.closest( '#dtb-repair-modal' ) ) {
				var compose = macro.closest( '.dtb-chat-compose' );
				var input = compose ? qs( '.dtb-chat-compose__textarea', compose ) : null;
				if ( input ) {
					input.value = macro.getAttribute( 'data-dtb-repair-macro' ) || '';
					input.focus();
				}
			}
		} );

		document.addEventListener( 'submit', function ( event ) {
			var form = event.target.closest( '.dtb-repair-support-chat-form' );
			if ( ! form || ! form.closest( '#dtb-repair-modal' ) ) {
				return;
			}
			event.preventDefault();
			var textarea = qs( '.dtb-chat-compose__textarea', form );
			var status = qs( '.dtb-support-form-status', form );
			var button = qs( 'button[type="submit"]', form );
			var body = textarea ? textarea.value.trim() : '';
			var type = form.getAttribute( 'data-dtb-repair-reply-type' ) || 'reply';

			if ( ! body ) {
				if ( status ) {
					status.textContent = 'Message body is empty.';
					status.className = 'dtb-support-form-status is-error';
				}
				return;
			}

			if ( button ) {
				button.disabled = true;
				button.textContent = type === 'note' ? 'Saving…' : 'Sending…';
			}
			if ( status ) {
				status.textContent = '';
				status.className = 'dtb-support-form-status';
			}

			sendRepairMessage( type, body )
				.then( function () {
					if ( textarea ) {
						textarea.value = '';
					}
					if ( status ) {
						status.textContent = type === 'note' ? 'Internal note saved.' : 'Reply sent.';
						status.className = 'dtb-support-form-status is-success';
					}
					toast( type === 'note' ? 'Internal note saved.' : 'Reply sent.', 'success' );
					loadDetail( true );
				} )
				.catch( function ( error ) {
					if ( status ) {
						status.textContent = error && error.message ? error.message : 'Message failed.';
						status.className = 'dtb-support-form-status is-error';
					}
					toast( error && error.message ? error.message : 'Message failed.', 'error' );
				} )
				.finally( function () {
					if ( button ) {
						button.disabled = false;
						button.textContent = 'Send';
					}
				} );
		} );
	}

	function boot() {
		bindEvents();
		scheduleLoad( true );
		var observer = new MutationObserver( function () {
			scheduleLoad( false );
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
