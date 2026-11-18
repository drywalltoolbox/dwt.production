/**
 * DTB Admin Chat Component
 *
 * Shared chat/thread renderer used by admin workbench modals. This formalizes
 * the Support chat UI as a reusable component so Repairs can consume the same
 * markup contract instead of maintaining a separate conversation implementation.
 */
( function ( window, document ) {
	'use strict';

	function esc( value ) {
		var d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( String( value == null ? '' : value ) ) );
		return d.innerHTML;
	}

	function avatarInitials( name ) {
		var parts = String( name || '?' ).trim().split( /\s+/ ).filter( Boolean );
		if ( ! parts.length ) {
			return '?';
		}
		if ( parts.length >= 2 ) {
			return ( parts[ 0 ][ 0 ] + parts[ parts.length - 1 ][ 0 ] ).toUpperCase();
		}
		return parts[ 0 ].slice( 0, 2 ).toUpperCase();
	}

	function formatDate( raw ) {
		if ( ! raw || raw === '—' ) {
			return raw || '—';
		}
		var d = new Date( String( raw ).replace( ' ', 'T' ) );
		if ( isNaN( d.getTime() ) ) {
			return raw;
		}
		var now = new Date();
		var diffS = ( now - d ) / 1000;
		if ( diffS >= 0 && diffS < 60 ) {
			return 'Just now';
		}
		if ( diffS >= 0 && diffS < 3600 ) {
			var m = Math.floor( diffS / 60 );
			return m + ' minute' + ( m === 1 ? '' : 's' ) + ' ago';
		}
		if ( d.toDateString() === now.toDateString() ) {
			return 'Today at ' + d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
		}
		var yesterday = new Date( now );
		yesterday.setDate( now.getDate() - 1 );
		if ( d.toDateString() === yesterday.toDateString() ) {
			return 'Yesterday at ' + d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
		}
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } )
			+ ' at ' + d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
	}

	function normalizeMessage( raw ) {
		var msg = raw || {};
		var type = String( msg.type || msg.event_group || msg.group || '' ).toLowerCase();
		var eventType = String( msg.event_type || '' ).toLowerCase();
		var actorType = String( msg.actor_type || ( msg.actor && msg.actor.type ) || '' ).toLowerCase();
		var body = msg.body || msg.message || msg.summary || msg.comment_content || '';
		var actor = msg.user_label || msg.actor_label || msg.author || msg.author_name || msg.actor_name || ( msg.actor && msg.actor.label ) || '';
		var when = msg.age_label || msg.created_at || msg.date || msg.created_at_utc || '';

		if ( eventType === 'ticket.created' && msg.suppress_duplicate ) {
			return null;
		}

		if ( eventType.indexOf( 'email_failed' ) !== -1 || type === 'delivery' ) {
			return { direction: 'system-danger', body: body || msg.event_label || 'Delivery event', actor: actor, when: when };
		}

		if ( eventType.indexOf( 'internal' ) !== -1 || type === 'internal' || type === 'note' ) {
			return { direction: 'note', body: body, actor: actor || 'Internal Note', when: when };
		}

		if ( actorType === 'customer' || type === 'customer' || type === 'inbound' || eventType.indexOf( 'customer_reply' ) !== -1 ) {
			return { direction: 'inbound', body: body, actor: actor || 'Customer', when: when };
		}

		if ( actorType === 'staff' || type === 'staff' || type === 'admin' || type === 'operator' || type === 'outbound' || type === 'reply' || eventType.indexOf( 'reply' ) !== -1 ) {
			return { direction: 'outbound', body: body, actor: actor || 'Staff', when: when };
		}

		if ( type === 'workflow' || type === 'system' || eventType ) {
			return { direction: 'system', body: body || msg.event_label || eventType, actor: actor, when: when };
		}

		return { direction: 'inbound', body: body, actor: actor || 'Customer', when: when };
	}

	function renderRow( raw, options ) {
		var msg = normalizeMessage( raw );
		if ( ! msg || ! msg.body ) {
			return '';
		}
		options = options || {};
		var customerName = options.customerName || 'Customer';
		var when = msg.when ? formatDate( msg.when ) : '';

		if ( msg.direction === 'inbound' ) {
			return '<div class="dtb-chat-row dtb-chat-row--inbound">'
				+ '<div class="dtb-chat-avatar" aria-hidden="true">' + esc( avatarInitials( customerName || msg.actor || 'Customer' ) ) + '</div>'
				+ '<div class="dtb-chat-bubble-wrap">'
				+ '<div class="dtb-chat-meta"><strong>' + esc( customerName || msg.actor || 'Customer' ) + '</strong>'
				+ ( when ? ' <span class="dtb-chat-meta__time">· ' + esc( when ) + '</span>' : '' ) + '</div>'
				+ '<div class="dtb-chat-bubble dtb-chat-bubble--inbound">' + esc( msg.body ) + '</div>'
				+ '</div></div>';
		}

		if ( msg.direction === 'outbound' ) {
			return '<div class="dtb-chat-row dtb-chat-row--outbound">'
				+ '<div class="dtb-chat-bubble-wrap">'
				+ '<div class="dtb-chat-meta dtb-chat-meta--right"><strong>' + esc( msg.actor || 'Staff' ) + '</strong>'
				+ ( when ? ' <span class="dtb-chat-meta__time">· ' + esc( when ) + '</span>' : '' ) + '</div>'
				+ '<div class="dtb-chat-bubble dtb-chat-bubble--outbound">' + esc( msg.body ) + '</div>'
				+ '</div><div class="dtb-chat-avatar dtb-chat-avatar--staff" aria-hidden="true">' + esc( avatarInitials( msg.actor || 'Staff' ) ) + '</div></div>';
		}

		if ( msg.direction === 'note' ) {
			return '<div class="dtb-chat-row dtb-chat-row--note">'
				+ '<div class="dtb-chat-note">'
				+ '<div class="dtb-chat-note__label">Internal note'
				+ ( msg.actor ? ' <span class="dtb-chat-note__author">· ' + esc( msg.actor ) + '</span>' : '' )
				+ ( when ? ' <span class="dtb-chat-note__time">· ' + esc( when ) + '</span>' : '' ) + '</div>'
				+ '<div class="dtb-chat-note__body">' + esc( msg.body ) + '</div>'
				+ '</div></div>';
		}

		return '<div class="dtb-chat-row dtb-chat-row--system"><span class="dtb-chat-system-event' + ( msg.direction === 'system-danger' ? ' dtb-chat-system-event--danger' : '' ) + '">'
			+ esc( msg.body ) + ( when ? ' <span class="dtb-chat-system-time">' + esc( when ) + '</span>' : '' ) + '</span></div>';
	}

	function renderThread( messages, options ) {
		messages = Array.isArray( messages ) ? messages : [];
		var rows = messages.map( function ( msg ) {
			return renderRow( msg, options || {} );
		} ).filter( Boolean ).join( '' );
		return rows || '<p class="dtb-chat-empty">' + esc( ( options && options.emptyText ) || 'No activity yet.' ) + '</p>';
	}

	function renderMacroPanel( macros, options ) {
		macros = Array.isArray( macros ) ? macros : [];
		options = options || {};
		var panelId = options.panelId || 'dtb-macro-panel';
		if ( ! macros.length ) {
			return '<div class="dtb-macro-panel" id="' + esc( panelId ) + '" hidden></div>';
		}
		var macroAttr = options.macroAttr || 'data-macro-text';
		var html = '<div class="dtb-macro-panel" id="' + esc( panelId ) + '" hidden>';
		macros.forEach( function ( group ) {
			var items = Array.isArray( group.items ) ? group.items : [];
			html += '<div class="dtb-macro-group"><div class="dtb-macro-group__label">' + esc( group.category || group.label || 'Quick Responses' ) + '</div><div class="dtb-macro-group__items">';
			items.forEach( function ( macro ) {
				html += '<button type="button" class="dtb-macro-btn" ' + macroAttr + '="' + esc( macro.text || macro.body || '' ) + '">' + esc( macro.label || macro.name || 'Macro' ) + '</button>';
			} );
			html += '</div></div>';
		} );
		html += '</div>';
		return html;
	}

	function renderConversationPanel( options ) {
		options = options || {};
		var threadId = options.threadId || 'dtb-chat-thread';
		var macroPanelId = options.macroPanelId || 'dtb-macro-panel';
		var modeAttr = options.modeAttr || 'data-dtb-compose-mode';
		var toggleAttr = options.macroToggleAttr || 'data-dtb-chat-macro-toggle';
		var formClass = options.formClass || 'dtb-chat-compose__form';
		var replyTypeAttr = options.replyTypeAttr || 'data-dtb-reply-type';
		var textareaName = options.textareaName || 'message';

		return '<div class="dtb-chat-thread" id="' + esc( threadId ) + '">'
			+ renderThread( options.messages || [], { customerName: options.customerName || 'Customer', emptyText: options.emptyText } )
			+ '</div>'
			+ '<div class="dtb-chat-compose" data-dtb-chat-compose="reply">'
			+ renderMacroPanel( options.macros || [], { panelId: macroPanelId, macroAttr: options.macroAttr || 'data-macro-text' } )
			+ '<div class="dtb-chat-compose__toolbar">'
			+ '<button type="button" class="dtb-chat-mode-btn is-active" ' + modeAttr + '="reply">Reply to Customer</button>'
			+ '<button type="button" class="dtb-chat-mode-btn" ' + modeAttr + '="note">Internal Note</button>'
			+ '<button type="button" class="dtb-chat-macro-toggle dtb-btn dtb-btn--ghost dtb-btn--sm" ' + toggleAttr + ' aria-expanded="false" aria-controls="' + esc( macroPanelId ) + '">⚡ Quick Responses</button>'
			+ '</div>'
			+ '<form class="dtb-chat-compose__form ' + esc( formClass ) + '" ' + replyTypeAttr + '="reply">'
			+ '<div class="dtb-chat-compose__input-row">'
			+ '<textarea class="dtb-chat-compose__textarea" name="' + esc( textareaName ) + '" placeholder="Write a reply to the customer…" rows="3" autocomplete="off"></textarea>'
			+ '<div class="dtb-chat-compose__actions"><button type="submit" class="dtb-btn dtb-btn--primary dtb-btn--sm">Send</button><span class="dtb-support-form-status"></span></div>'
			+ '</div></form></div>';
	}

	function setComposeMode( compose, mode, options ) {
		if ( ! compose ) {
			return;
		}
		options = options || {};
		var formSelector = options.formSelector || '.dtb-chat-compose__form';
		var replyTypeAttr = options.replyTypeAttr || 'data-dtb-reply-type';
		var modeAttr = options.modeAttr || 'data-dtb-compose-mode';
		var textarea = compose.querySelector( '.dtb-chat-compose__textarea' );
		var form = compose.querySelector( formSelector );
		compose.setAttribute( 'data-dtb-chat-compose', mode );
		compose.classList.toggle( 'dtb-chat-compose--note', mode === 'note' );
		compose.querySelectorAll( '[' + modeAttr + ']' ).forEach( function ( btn ) {
			btn.classList.toggle( 'is-active', btn.getAttribute( modeAttr ) === mode );
		} );
		if ( form ) {
			form.setAttribute( replyTypeAttr, mode );
		}
		if ( textarea ) {
			textarea.placeholder = mode === 'note' ? 'Write an internal note…' : 'Write a reply to the customer…';
			textarea.focus();
		}
	}

	window.DtbAdminChat = {
		escapeHtml: esc,
		avatarInitials: avatarInitials,
		formatDate: formatDate,
		normalizeMessage: normalizeMessage,
		renderRow: renderRow,
		renderThread: renderThread,
		renderMacroPanel: renderMacroPanel,
		renderConversationPanel: renderConversationPanel,
		setComposeMode: setComposeMode,
	};
}( window, document ) );
