/* global DtbAdmin, dtbAdminConfig */
( function () {
	'use strict';

	var MODAL_ID = 'dtb-support-ticket-modal';
	var state = {
		currentTicketId: 0,
		currentTicketUrl: '',
	};

	function byId( id ) {
		return document.getElementById( id );
	}

	function escHtml( value ) {
		var d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( String( value == null ? '' : value ) ) );
		return d.innerHTML;
	}

	function getWorkbench() {
		return byId( 'dtb-support-workbench' );
	}

	function getLiveRegion() {
		return byId( 'dtb-support-workspace' );
	}

	function getModalElements() {
		var overlay = byId( MODAL_ID );
		if ( ! overlay ) {
			return null;
		}
		return {
			overlay: overlay,
			title: overlay.querySelector( '.dtb-modal__title' ),
			body: overlay.querySelector( '.dtb-modal__body' ),
			viewButton: overlay.querySelector( '[data-dtb-support-modal-action="view"]' ),
		};
	}

	function normalizeStatus( status ) {
		var map = {
			all: '',
			needs_reply: 'needs-reply',
			past_sla: 'past-sla',
		};
		var key = String( status || '' );
		return map[ key ] != null ? map[ key ] : key;
	}

	function resolveActiveQueue( query ) {
		if ( query.queue ) {
			return query.queue;
		}
		var status = normalizeStatus( query.status || query.tab || '' );
		var map = {
			'': 'all_active',
			open: 'all_active',
			'needs-reply': 'needs_reply',
			'past-sla': 'overdue',
			resolved: 'resolved_pending_close',
			closed: 'closed',
		};
		return map[ status ] || 'all_active';
	}

	function currentQueryFromUrl() {
		var params = new URLSearchParams( window.location.search );
		return {
			status: normalizeStatus( params.get( 'status' ) || params.get( 'tab' ) || '' ),
			queue: params.get( 'queue' ) || '',
			search: params.get( 'search' ) || params.get( 's' ) || '',
			type: params.get( 'type' ) || '',
			priority: params.get( 'priority' ) || '',
			paged: params.get( 'paged' ) || '1',
		};
	}

	function updateQueueUi( activeQueue ) {
		document.querySelectorAll( '[data-dtb-support-queue]' ).forEach( function ( el ) {
			el.classList.toggle( 'is-active', el.getAttribute( 'data-dtb-support-queue' ) === activeQueue );
		} );
		var active = document.querySelector( '[data-dtb-support-queue="' + activeQueue + '"] .dtb-support-queue__label' );
		var label = active ? active.textContent : 'All Active';
		document.querySelectorAll( '[data-dtb-support-current-queue]' ).forEach( function ( el ) {
			el.textContent = label;
		} );
	}

	function syncFiltersFromQuery( query ) {
		var search = document.querySelector( '[data-dtb-support-search]' );
		var type = document.querySelector( '[data-dtb-support-filter="type"]' );
		var priority = document.querySelector( '[data-dtb-support-filter="priority"]' );
		if ( search && search.value !== String( query.search || '' ) ) {
			search.value = String( query.search || '' );
		}
		if ( type ) {
			type.value = String( query.type || '' );
		}
		if ( priority ) {
			priority.value = String( query.priority || '' );
		}
	}

	function setTicketParam( ticketId ) {
		if ( ! window.URL || ! window.history || ! window.history.replaceState ) {
			return;
		}
		var url = new URL( window.location.href );
		if ( ticketId ) {
			url.searchParams.set( 'ticket_id', String( ticketId ) );
		} else {
			url.searchParams.delete( 'ticket_id' );
		}
		window.history.replaceState( {}, '', url.toString() );
	}

	function getTicketUrl( ticketId, preferredUrl ) {
		if ( preferredUrl ) {
			return preferredUrl;
		}
		var adminUrl = window.dtbAdminConfig && window.dtbAdminConfig.adminUrl ? window.dtbAdminConfig.adminUrl : '/wp-admin/admin.php';
		var joiner = adminUrl.indexOf( '?' ) === -1 ? '?' : '&';
		return adminUrl + joiner + 'page=dtb-support&ticket_id=' + encodeURIComponent( String( ticketId || '' ) );
	}

	function renderModalLoading( els, ticketRef ) {
		if ( els.title ) {
			els.title.textContent = ticketRef ? 'Ticket ' + ticketRef : 'Support Ticket';
		}
		if ( els.body ) {
			els.body.innerHTML = '<div class="dtb-support-modal-loading">Loading ticket details…</div>';
		}
	}

	function renderModalError( els ) {
		if ( els.body ) {
			els.body.innerHTML = '<div class="dtb-support-modal-error">Unable to load ticket details.</div>';
		}
	}

	function avatarInitials( name ) {
		var parts = String( name || '?' ).trim().split( /\s+/ );
		if ( parts.length >= 2 ) {
			return ( parts[ 0 ][ 0 ] + parts[ parts.length - 1 ][ 0 ] ).toUpperCase();
		}
		return parts[ 0 ].slice( 0, 2 ).toUpperCase();
	}

	/**
	 * Converts an ISO or MySQL datetime string to a human-readable format.
	 * e.g. "2026-06-01 05:16:19" → "Jun 1, 2026 at 5:16 AM"
	 * Falls back to the original value if it cannot be parsed.
	 */
	function formatDate( raw ) {
		if ( ! raw || raw === '—' ) {
			return raw || '—';
		}
		// MySQL datetimes use space; ISO uses T — normalise for Safari/Firefox.
		var normalised = String( raw ).replace( ' ', 'T' );
		var d = new Date( normalised );
		if ( isNaN( d.getTime() ) ) {
			return raw;
		}
		var now   = new Date();
		var diffS = ( now - d ) / 1000;

		// Within the last 60 s
		if ( diffS >= 0 && diffS < 60 ) {
			return 'Just now';
		}
		// Within the last hour
		if ( diffS >= 0 && diffS < 3600 ) {
			var m = Math.floor( diffS / 60 );
			return m + ' minute' + ( m === 1 ? '' : 's' ) + ' ago';
		}
		// Today (same calendar day)
		var isToday = d.toDateString() === now.toDateString();
		if ( isToday ) {
			return 'Today at ' + d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
		}
		// Yesterday
		var yesterday = new Date( now );
		yesterday.setDate( now.getDate() - 1 );
		if ( d.toDateString() === yesterday.toDateString() ) {
			return 'Yesterday at ' + d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
		}
		// Full date
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } )
			+ ' at ' + d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
	}

	/**
	 * Format a date for the "Due" field — shows just the date if it is a future
	 * date (not today), or "Today at HH:MM" / "Overdue since …" if past.
	 */
	function formatDue( raw ) {
		if ( ! raw || raw === '—' ) {
			return raw || '—';
		}
		var normalised = String( raw ).replace( ' ', 'T' );
		var d = new Date( normalised );
		if ( isNaN( d.getTime() ) ) {
			return raw;
		}
		var now = new Date();
		if ( d < now ) {
			var diffS = ( now - d ) / 1000;
			if ( diffS < 3600 ) {
				return 'Overdue ' + Math.floor( diffS / 60 ) + 'm ago';
			}
			if ( diffS < 86400 ) {
				return 'Overdue ' + Math.floor( diffS / 3600 ) + 'h ago';
			}
			return 'Overdue since ' + d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
		}
		// Future — show friendly date
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } )
			+ ' at ' + d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
	}

	function buildTimeline( events, message, customerName ) {
		var html = '';

		// Track event IDs we've rendered to avoid duplicate customer message display
		// (ticket.created events often duplicate the original message)
		var renderedCreatedEvent = false;

		// Original customer message — always first, left bubble
		if ( message ) {
			var initials = avatarInitials( customerName || 'Customer' );
			html += '<div class="dtb-chat-row dtb-chat-row--inbound">'
				+ '<div class="dtb-chat-avatar" aria-hidden="true">' + escHtml( initials ) + '</div>'
				+ '<div class="dtb-chat-bubble-wrap">'
				+ '<div class="dtb-chat-meta"><strong>' + escHtml( customerName || 'Customer' ) + '</strong></div>'
				+ '<div class="dtb-chat-bubble dtb-chat-bubble--inbound">' + escHtml( message ) + '</div>'
				+ '</div>'
				+ '</div>';
		}

		if ( ! Array.isArray( events ) || ! events.length ) {
			if ( ! message ) {
				html += '<p class="dtb-chat-empty">No activity yet.</p>';
			}
			return html;
		}

		events.forEach( function ( ev ) {
			// API sends event_group; fall back to legacy group field.
			var group      = ev.event_group || ev.group || 'system';
			var eventType  = ev.event_type || '';
			if ( eventType === 'ticket.reply_customer' || eventType === 'ticket.reply_staff' ) {
				group = 'message';
			}
			var evLabel    = ev.event_label || eventType || 'Event';
			// Use age_label (human relative time) first, then raw created_at
			var evWhen     = ev.age_label || ev.created_at || '';
			var evBody     = ev.summary || ev.body || '';
			// API sends actor_label; fall back to legacy fields
			var authorName = ev.actor_label || ev.actor_name || ev.author_name || ( ev.actor && ev.actor.label ) || '';
			var actorType  = ev.actor_type || ( ev.actor && ev.actor.type ) || '';
			if ( ! actorType && eventType === 'ticket.reply_customer' ) {
				actorType = 'staff';
			} else if ( ! actorType && eventType === 'ticket.reply_staff' ) {
				actorType = 'customer';
			}

			// Skip ticket.created when the original message was already rendered above
			// to avoid showing the same text twice in the thread
			if ( eventType === 'ticket.created' && message ) {
				renderedCreatedEvent = true;
				return;
			}

			if ( group === 'message' ) {
				// Determine direction: actorType drives alignment.
			// 'ticket.reply_customer' = staff reply SENT TO customer (outbound).
			// Only actorType === 'customer', or known inbound event types, are left-aligned.
			var isCustomerMessage = actorType === 'customer'
				|| ( ! actorType && (
					eventType === 'customer.reply'
					|| eventType === 'ticket.customer_reply'
				) );

				if ( isCustomerMessage ) {
					var cInitials = avatarInitials( customerName || 'Customer' );
					html += '<div class="dtb-chat-row dtb-chat-row--inbound">'
						+ '<div class="dtb-chat-avatar" aria-hidden="true">' + escHtml( cInitials ) + '</div>'
						+ '<div class="dtb-chat-bubble-wrap">'
						+ '<div class="dtb-chat-meta">'
						+ '<strong>' + escHtml( customerName || 'Customer' ) + '</strong>'
						+ ( evWhen ? ' <span class="dtb-chat-meta__time">· ' + escHtml( evWhen ) + '</span>' : '' )
						+ '</div>'
						+ '<div class="dtb-chat-bubble dtb-chat-bubble--inbound">' + escHtml( evBody || '—' ) + '</div>'
						+ '</div>'
						+ '</div>';
				} else {
					// Staff reply — right-aligned outbound bubble
					html += '<div class="dtb-chat-row dtb-chat-row--outbound">'
						+ '<div class="dtb-chat-bubble-wrap">'
						+ '<div class="dtb-chat-meta dtb-chat-meta--right">'
						+ ( authorName ? '<strong>' + escHtml( authorName ) + '</strong>' : '<strong>Staff</strong>' )
						+ ( evWhen ? ' <span class="dtb-chat-meta__time">· ' + escHtml( evWhen ) + '</span>' : '' )
						+ '</div>'
						+ '<div class="dtb-chat-bubble dtb-chat-bubble--outbound">' + escHtml( evBody || '—' ) + '</div>'
						+ '</div>'
						+ '<div class="dtb-chat-avatar dtb-chat-avatar--staff" aria-hidden="true">'
						+ escHtml( avatarInitials( authorName || 'Staff' ) )
						+ '</div>'
						+ '</div>';
				}

			} else if ( group === 'internal' ) {
				// Internal notes — suppressed from conversation view to reduce clutter.
				// Only system-generated internal notes (priority changes,
				// email delivery receipts) are hidden; these are visible in the audit log.
				return;

			} else if ( group === 'delivery' ) {
				// Email delivery events — subtle inline pill with status indicator
				var isFailure = eventType === 'ticket.email_failed';
				html += '<div class="dtb-chat-row dtb-chat-row--system">'
					+ '<span class="dtb-chat-system-event dtb-chat-system-event--' + ( isFailure ? 'danger' : 'muted' ) + '">'
					+ ( isFailure ? '✗ ' : '✉ ' ) + escHtml( evBody || evLabel )
					+ ( evWhen ? ' <span class="dtb-chat-system-time">' + escHtml( evWhen ) + '</span>' : '' )
					+ '</span>'
					+ '</div>';

			} else if ( group === 'workflow' ) {
				// Workflow changes (status and priority) — compact timeline marker
				html += '<div class="dtb-chat-row dtb-chat-row--system">'
					+ '<span class="dtb-chat-system-event">'
					+ escHtml( evBody || evLabel )
					+ ( evWhen ? ' <span class="dtb-chat-system-time">' + escHtml( evWhen ) + '</span>' : '' )
					+ '</span>'
					+ '</div>';

			} else {
				// Catch-all system events
				if ( ! evBody && ! evLabel ) {
					return; // Skip empty/unknown events entirely
				}
				html += '<div class="dtb-chat-row dtb-chat-row--system">'
					+ '<span class="dtb-chat-system-event">'
					+ escHtml( evLabel )
					+ ( evBody && evBody !== evLabel ? ' — ' + escHtml( evBody ) : '' )
					+ ( evWhen ? ' <span class="dtb-chat-system-time">' + escHtml( evWhen ) + '</span>' : '' )
					+ '</span>'
					+ '</div>';
			}
		} );

		return html;
	}

	function renderStatusOptions( current ) {
		return [
			{ slug: 'open',             label: 'Open' },
			{ slug: 'in_progress',      label: 'In Progress' },
			{ slug: 'pending_customer', label: 'Waiting on Customer' },
			{ slug: 'pending_staff',    label: 'Waiting on Staff' },
			{ slug: 'resolved',         label: 'Resolved' },
			{ slug: 'closed',           label: 'Closed' },
			{ slug: 'spam',             label: 'Spam' },
		].map( function ( s ) {
			return '<option value="' + escHtml( s.slug ) + '"' + ( s.slug === current ? ' selected' : '' ) + '>' + escHtml( s.label ) + '</option>';
		} ).join( '' );
	}

	function renderPriorityOptions( current ) {
		return [
			{ slug: 'low',    label: 'Low' },
			{ slug: 'normal', label: 'Normal' },
			{ slug: 'high',   label: 'High' },
			{ slug: 'urgent', label: 'Urgent' },
		].map( function ( p ) {
			return '<option value="' + escHtml( p.slug ) + '"' + ( p.slug === current ? ' selected' : '' ) + '>' + escHtml( p.label ) + '</option>';
		} ).join( '' );
	}

	function reloadModalTicket() {
		if ( ! state.currentTicketId ) {
			return;
		}
		var els = getModalElements();
		if ( ! els ) {
			return;
		}
		var restBase = ( window.dtbAdminConfig && window.dtbAdminConfig.restUrl ? window.dtbAdminConfig.restUrl : '/wp-json/' ).replace( /\/$/, '' );
		var endpoint = restBase + '/dtb/v1/support/tickets/' + encodeURIComponent( String( state.currentTicketId ) );
		var nonce = window.dtbAdminConfig && window.dtbAdminConfig.nonce ? window.dtbAdminConfig.nonce : '';
		fetch( endpoint, {
			headers: { Accept: 'application/json', 'X-WP-Nonce': nonce },
			credentials: 'same-origin',
		} )
			.then( function ( res ) {
				return res.ok ? res.json() : Promise.reject();
			} )
			.then( function ( payload ) {
				renderTicketModal( els, payload );
				refreshSupportRegion();
			} )
			.catch( function () {} );
	}

	function applyMutationResult( payload ) {
		if ( payload && payload.detail ) {
			var els = getModalElements();
			if ( els ) {
				renderTicketModal( els, payload.detail );
			}
			refreshSupportRegion();
			fetchWorkbenchAggregate();
			return;
		}

		reloadModalTicket();
		fetchWorkbenchAggregate();
	}

	function refreshSupportRegion() {
		var region = getLiveRegion();
		if ( region && typeof DtbAdmin !== 'undefined' && DtbAdmin.liveRefresh ) {
			DtbAdmin.liveRefresh( region );
		}
	}

	// ── Smart macros ────────────────────────────────────────────────────────────
	// Tokens: {{customer}} {{ticket}} {{order}}
	var MACROS = [
		{
			category: 'Greetings',
			items: [
				{
					label: 'Welcome greeting',
					text: 'Hi {{customer}},\n\nThank you for reaching out to us! We received your message and our team is looking into it now. We\'ll have an update for you shortly.\n\nBest regards,\nDrywall Toolbox Support',
				},
				{
					label: 'Follow-up check-in',
					text: 'Hi {{customer}},\n\nJust following up on your ticket {{ticket}} to see if everything has been resolved to your satisfaction. Please don\'t hesitate to reply if you need any further assistance.\n\nBest,\nDrywall Toolbox Support',
				},
			],
		},
		{
			category: 'Returns & Warranty',
			items: [
				{
					label: 'Return approved',
					text: 'Hi {{customer}},\n\nWe\'ve reviewed your return request for ticket {{ticket}} and are happy to approve it. Please ship the item back to us using the address on your original packing slip. Once we receive and inspect the item, we\'ll process your refund or replacement within 3–5 business days.\n\nIf you have any questions, just reply here.\n\nBest,\nDrywall Toolbox Support',
				},
				{
					label: 'Warranty claim — need more info',
					text: 'Hi {{customer}},\n\nThank you for submitting your warranty claim. To move forward, could you please provide:\n\n• A photo of the issue\n• Your order number (if not already included)\n• A brief description of when the issue first appeared\n\nOnce we have those details, we can process your claim right away.\n\nThanks,\nDrywall Toolbox Support',
				},
				{
					label: 'Damaged item received',
					text: 'Hi {{customer}},\n\nWe\'re so sorry to hear that your item arrived damaged — that\'s not the experience we want for you. We\'d like to make this right immediately.\n\nCould you share a photo of the damage so we can arrange a replacement or refund? You won\'t need to return the damaged item.\n\nAppreciate your patience,\nDrywall Toolbox Support',
				},
			],
		},
		{
			category: 'Shipping & Orders',
			items: [
				{
					label: 'Order shipped — tracking',
					text: 'Hi {{customer}},\n\nGreat news — your order {{order}} has shipped! You should receive a tracking email shortly with the carrier details. Most shipments arrive within 5–7 business days.\n\nIf you haven\'t received tracking within 24 hours, just reply here and we\'ll look into it.\n\nBest,\nDrywall Toolbox Support',
				},
				{
					label: 'Shipping delay',
					text: 'Hi {{customer}},\n\nWe wanted to proactively let you know that your order {{order}} is experiencing a slight shipping delay. We\'re actively working with the carrier to get it moving and will update you as soon as we have a confirmed delivery estimate.\n\nWe truly appreciate your patience and are sorry for the inconvenience.\n\nBest,\nDrywall Toolbox Support',
				},
				{
					label: 'Order not received',
					text: 'Hi {{customer}},\n\nI\'m sorry to hear your order hasn\'t arrived yet. I\'ve pulled up the tracking on our end and will investigate right away.\n\nIn the meantime, could you confirm that the shipping address on your order {{order}} is correct? This helps us work with the carrier quickly.\n\nWe\'ll follow up with an update within 1 business day.\n\nThank you,\nDrywall Toolbox Support',
				},
			],
		},
		{
			category: 'Closing & Resolution',
			items: [
				{
					label: 'Resolved — closing ticket',
					text: 'Hi {{customer}},\n\nI\'m glad we were able to resolve your issue! We\'ll go ahead and close ticket {{ticket}} now. If anything else comes up, feel free to open a new ticket — we\'re always happy to help.\n\nHave a great day!\nDrywall Toolbox Support',
				},
				{
					label: 'No response — closing',
					text: 'Hi {{customer}},\n\nWe haven\'t heard back from you regarding ticket {{ticket}}, so we\'ll go ahead and mark it resolved. If you still need assistance, simply reply to this message or open a new ticket.\n\nTake care,\nDrywall Toolbox Support',
				},
			],
		},
	];

	function resolveMacroText( text, customerName, ticketLabel, orderId ) {
		return String( text || '' )
			.replace( /\{\{customer\}\}/g, customerName )
			.replace( /\{\{customer_name\}\}/g, customerName )
			.replace( /\{\{ticket\}\}/g, ticketLabel )
			.replace( /\{\{ticket_number\}\}/g, ticketLabel )
			.replace( /\{\{order\}\}/g, orderId !== '—' ? orderId : '' )
			.replace( /\{\{order_id\}\}/g, orderId !== '—' ? orderId : '' );
	}

	function buildMacroHtml( customerName, ticketLabel, orderId, recommendedMacros ) {
		var html = '<div class="dtb-macro-panel" id="dtb-macro-panel" hidden>'
			+ '<div class="dtb-macro-panel__header">'
			+ '<span class="dtb-macro-panel__title">Quick Responses</span>'
			+ '<button type="button" class="dtb-macro-close" aria-label="Close">✕</button>'
			+ '</div>'
			+ '<div class="dtb-macro-panel__body">';

		if ( Array.isArray( recommendedMacros ) && recommendedMacros.length ) {
			html += '<div class="dtb-macro-group dtb-macro-group--recommended">'
				+ '<div class="dtb-macro-group__label">Recommended</div>'
				+ '<div class="dtb-macro-group__items">';
			recommendedMacros.forEach( function ( macro ) {
				var label = macro.label || macro.name || macro.macro_name || 'Recommended response';
				var text = macro.body || macro.text || macro.body_template || '';
				var resolved = resolveMacroText( text, customerName, ticketLabel, orderId );
				if ( ! resolved ) {
					return;
				}
				html += '<button type="button" class="dtb-macro-btn" data-macro-text="' + escHtml( resolved ) + '">'
					+ escHtml( label )
					+ '</button>';
			} );
			html += '</div></div>';
		}

		MACROS.forEach( function ( group ) {
			html += '<div class="dtb-macro-group">'
				+ '<div class="dtb-macro-group__label">' + escHtml( group.category ) + '</div>'
				+ '<div class="dtb-macro-group__items">';
			group.items.forEach( function ( macro ) {
				var resolved = resolveMacroText( macro.text, customerName, ticketLabel, orderId );
				html += '<button type="button" class="dtb-macro-btn" data-macro-text="' + escHtml( resolved ) + '">'
					+ escHtml( macro.label )
					+ '</button>';
			} );
			html += '</div></div>';
		} );

		html += '</div></div>';
		return html;
	}

	function supportNextAction( ticket, intelligence ) {
		intelligence = intelligence || {};
		var next = intelligence.next_action || {};
		if ( typeof next === 'string' ) {
			next = { label: next, reason: '' };
		}
		return {
			label: next.label || ticket.next_action_label || ticket.next_action || 'Review and respond',
			reason: next.reason || ticket.next_action_reason || 'Keep the customer status current and use follow-ups for anything that should return to the queue later.',
		};
	}

	function renderSupportCanonicalRail( ticket, payload, dueAt, dueRaw, created, updated ) {
		var WB = window.DtbWorkbench || {};
		var intelligence = ( payload && payload.intelligence ) || {};
		var communication = ( payload && payload.communication ) || {};
		var delivery = communication.delivery_health || {};
		var customer = ( payload && ( payload.customer || payload.customer_context ) ) || {};
		var linked = ( payload && payload.linked_records ) || {};
		var integrations = ( payload && payload.integrations ) || {};
		var macros = intelligence.recommended_macros || payload.recommended_macros || [];
		var riskFlags = intelligence.risk_flags || [];
		var next = supportNextAction( ticket, intelligence );
		var failCount = parseInt( delivery.fail_count || ticket.notification_fail_count || 0, 10 ) || 0;
		var status = delivery.status || ticket.notification_status || 'unknown';
		var html = '';

		html += '<div class="dtb-support-ticket-card"><h4>Intelligence</h4>';
		html += '<div class="dtb-support-ticket-kv"><span>Priority score</span><strong>' + escHtml( intelligence.priority_score != null ? intelligence.priority_score : ticket.priority_score || 0 ) + '</strong></div>';
		html += '<div class="dtb-support-ticket-kv"><span>Next action</span><strong>' + escHtml( next.label ) + '</strong></div>';
		html += '<div class="dtb-support-ticket-kv"><span>Action due</span><strong' + ( dueRaw && new Date( dueRaw.replace( ' ', 'T' ) ) < new Date() ? ' class="dtb-kv-overdue"' : '' ) + '>' + escHtml( dueAt ) + '</strong></div>';
		html += '<div class="dtb-support-ticket-kv"><span>Delivery</span><strong>' + escHtml( status ) + '</strong></div>';
		if ( failCount > 0 ) {
			html += '<div class="dtb-wb-note dtb-wb-note--danger">Outbox warnings: ' + escHtml( failCount ) + ' failed notification attempt(s).</div>';
		}
		if ( riskFlags.length ) {
			html += '<div class="dtb-wb-note dtb-wb-note--warning">' + riskFlags.map( escHtml ).join( ', ' ) + '</div>';
		}
		html += '</div>';

		html += '<div class="dtb-support-ticket-card"><h4>Ticket Snapshot</h4>';
		html += '<div class="dtb-support-ticket-kv"><span>Status</span><strong>' + escHtml( ticket.status_label || ticket.status || '—' ) + '</strong></div>';
		html += '<div class="dtb-support-ticket-kv"><span>Priority</span><strong>' + escHtml( ticket.priority_label || ticket.priority || '—' ) + '</strong></div>';
		html += '<div class="dtb-support-ticket-kv"><span>Created</span><strong>' + escHtml( created ) + '</strong></div>';
		html += '<div class="dtb-support-ticket-kv"><span>Updated</span><strong>' + escHtml( updated ) + '</strong></div>';
		html += '</div>';

		if ( WB.renderCustomerRail ) {
			html += WB.renderCustomerRail( customer );
		}
		if ( WB.renderLinkedRecords ) {
			html += '<div class="dtb-support-ticket-card"><h4>Linked Records</h4>' + WB.renderLinkedRecords( linked ) + '</div>';
		}
		// Compact record-level issue chips only — full integration diagnostics belong in System Manager.
		if ( WB.renderRecordIssueChips ) {
			var issueChips = WB.renderRecordIssueChips( integrations );
			if ( issueChips ) {
				html += '<div class="dtb-support-ticket-card">' + issueChips + '</div>';
			}
		}
		if ( macros.length ) {
			html += '<div class="dtb-support-ticket-card"><h4>Recommended Macros</h4><ul class="dtb-support-rail-list">';
			macros.forEach( function ( macro ) {
				html += '<li>' + escHtml( macro.name || macro.label || macro.macro_name || 'Macro' ) + '</li>';
			} );
			html += '</ul></div>';
		}

		return html;
	}

	function renderTicketModal( els, payload ) {
		state.currentPayload = payload || {};
		var ticket = payload && ( payload.record || payload.ticket ) ? ( payload.record || payload.ticket ) : {};
		var events = payload && ( payload.events || payload.timeline ) ? ( payload.events || payload.timeline ) : [];
		var intelligence = ( payload && payload.intelligence ) || {};
		var next = supportNextAction( ticket, intelligence );
		var ticketLabel = ticket.ticket_number || ( '#' + ( ticket.id || '' ) );
		var customerName = ticket.customer_name || ticket.customer_email || 'Customer';
		var customerEmail = ticket.customer_email || '';
		var subject = ticket.subject || '—';
		var status = ticket.status_label || ticket.status || '—';
		var statusSlug = ticket.status || '';
		var priority = ticket.priority_label || ticket.priority || '—';
		var prioritySlug = ticket.priority || 'normal';
		var typeLabel = ticket.type_label || ticket.ticket_type || '—';
		var message = ticket.message || '';
		var created = formatDate( ticket.created_at ) || '—';
		var updated = formatDate( ticket.updated_at ) || '—';
		var dueAt = formatDue( ticket.action_due_at ) || '—';
		var dueRaw = ticket.action_due_at || '';
		var orderId = ticket.order_id ? '#' + ticket.order_id : '—';

		if ( els.title ) {
			els.title.textContent = 'Ticket ' + ticketLabel;
		}

		if ( ! els.body ) {
			return;
		}

		els.body.innerHTML =
			'<div class="dtb-support-ticket-modal">'

			// ── Email reading-pane header ──────────────────────────────────────
			+ '<header class="dtb-support-email-header">'

			// Toolbar: pills left, quick-action Reply/Note right
			+ '<div class="dtb-support-email-toolbar">'
			+ '<div class="dtb-support-email-toolbar__pills">'
			+ '<span class="dtb-support-ticket-pill dtb-support-ticket-pill--status dtb-status--' + escHtml( statusSlug ) + '">' + escHtml( status ) + '</span>'
			+ '<span class="dtb-support-ticket-pill dtb-support-ticket-pill--priority dtb-priority--' + escHtml( prioritySlug ) + '">' + escHtml( priority ) + '</span>'
			+ ( typeLabel !== '—' ? '<span class="dtb-support-ticket-pill">' + escHtml( typeLabel ) + '</span>' : '' )
			+ '</div>'
			+ '<div class="dtb-support-email-toolbar__btns">'
			+ '<button type="button" class="dtb-btn dtb-btn--sm dtb-btn--ghost dtb-email-quick-reply" data-dtb-compose-mode="reply" title="Reply to customer">↩ Reply</button>'
			+ '<button type="button" class="dtb-btn dtb-btn--sm dtb-btn--ghost dtb-email-quick-reply" data-dtb-compose-mode="note" title="Add internal note">📋 Note</button>'
			+ '</div>'
			+ '</div>'

			// From row: customer avatar + name + email | ticket ref on right
			+ '<div class="dtb-support-email-from">'
			+ '<div class="dtb-support-email-avatar">' + escHtml( avatarInitials( customerName ) ) + '</div>'
			+ '<div class="dtb-support-email-from__copy">'
			+ '<strong>' + escHtml( customerName ) + '</strong>'
			+ ( customerEmail ? '<span class="dtb-support-email-from__email">' + escHtml( customerEmail ) + '</span>' : '' )
			+ '</div>'
			+ '<span class="dtb-support-email-ticket-ref">' + escHtml( ticketLabel ) + '</span>'
			+ '</div>'

			// Subject
			+ '<h3 class="dtb-support-email-subject">' + escHtml( subject ) + '</h3>'

			// Meta row: order · created · due
			+ '<div class="dtb-support-email-meta">'
			+ ( orderId !== '—' ? '<span>Order ' + escHtml( orderId ) + '</span><span class="dtb-support-email-sep">·</span>' : '' )
			+ '<span>Created ' + escHtml( created ) + '</span>'
			+ ( dueAt !== '—' ? '<span class="dtb-support-email-sep">·</span><span class="dtb-support-email-due' + ( dueRaw && new Date( dueRaw.replace( ' ', 'T' ) ) < new Date() ? ' is-overdue' : '' ) + '">Due ' + escHtml( dueAt ) + '</span>' : '' )
			+ '</div>'

			+ '</header>'

			// ── Two-column body ────────────────────────────────────────────────
			+ '<div class="dtb-support-ticket-modal__body">'

			// ── Left pane: Tab nav + panels ────────────────────────────────────
			+ '<div class="dtb-support-ticket-left">'

			// Tab navigation — Thread (chat) + Actions
			+ '<nav class="dtb-support-modal-tabs" role="tablist">'
			+ '<button class="dtb-support-modal-tab is-active" data-dtb-modal-tab="thread" role="tab" aria-selected="true">Conversation</button>'
			+ '<button class="dtb-support-modal-tab" data-dtb-modal-tab="actions" role="tab" aria-selected="false">Actions</button>'
			+ '</nav>'

			// ── Panel: Conversation (chat + pinned compose) ────────────────────
			+ '<div class="dtb-support-modal-panel is-active dtb-support-chat-panel" data-dtb-modal-panel="thread">'

			// Scrollable chat thread
			+ '<div class="dtb-chat-thread" id="dtb-chat-thread">'
			+ buildTimeline( events, message, customerName )
			+ '</div>'

			// Pinned compose bar
			+ '<div class="dtb-chat-compose">'
			+ buildMacroHtml( customerName, ticketLabel, orderId, ( intelligence.recommended_macros || payload.recommended_macros || [] ) )
			+ '<div class="dtb-chat-compose__toolbar">'
			+ '<button type="button" class="dtb-chat-mode-btn is-active" data-dtb-compose-mode="reply">Reply to Customer</button>'
			+ '<button type="button" class="dtb-chat-mode-btn" data-dtb-compose-mode="note">Internal Note</button>'
			+ '<button type="button" class="dtb-chat-macro-toggle dtb-btn dtb-btn--ghost dtb-btn--sm" title="Quick response macros" aria-expanded="false" aria-controls="dtb-macro-panel">⚡ Quick Responses</button>'
			+ '</div>'
			+ '<form class="dtb-chat-compose__form dtb-support-reply-form" data-dtb-reply-type="reply">'
			+ '<div class="dtb-chat-compose__input-row">'
			+ '<textarea class="dtb-chat-compose__textarea" name="message" placeholder="Write a reply to the customer…" rows="3" autocomplete="off"></textarea>'
			+ '<div class="dtb-chat-compose__actions">'
			+ '<button type="submit" class="dtb-btn dtb-btn--primary dtb-btn--sm">Send</button>'
			+ '<span class="dtb-support-form-status"></span>'
			+ '</div>'
			+ '</div>'
			+ '</form>'
			+ '</div>'

			+ '</div>' // .dtb-support-chat-panel

			// ── Panel: Actions ─────────────────────────────────────────────────
			+ '<div class="dtb-support-modal-panel dtb-support-actions-panel" data-dtb-modal-panel="actions">'
			+ '<div class="dtb-support-action-summary">'
			+ '<div><span class="dtb-support-form-label">Recommended next step</span><strong>' + escHtml( next.label ) + '</strong></div>'
			+ '<p>' + escHtml( next.reason ) + '</p>'
			+ '</div>'
			+ '<form class="dtb-support-actions-form">'
			+ '<div class="dtb-support-actions-grid">'
			+ '<div class="dtb-support-form-group">'
			+ '<label class="dtb-support-form-label">Workflow Status</label>'
			+ '<select class="dtb-select" name="status">' + renderStatusOptions( statusSlug ) + '</select>'
			+ '</div>'
			+ '<div class="dtb-support-form-group">'
			+ '<label class="dtb-support-form-label">Priority</label>'
			+ '<select class="dtb-select" name="priority">' + renderPriorityOptions( prioritySlug ) + '</select>'
			+ '</div>'
			+ '<div class="dtb-support-form-group dtb-support-form-group--full">'
			+ '<label class="dtb-support-form-label">Change note <span class="dtb-support-form-badge">Optional</span></label>'
			+ '<textarea class="dtb-support-reply-textarea dtb-support-reply-textarea--sm" name="note" placeholder="Reason for this change…" rows="3"></textarea>'
			+ '</div>'
			+ '</div>'
			+ '<div class="dtb-support-form-actions">'
			+ '<button type="submit" class="dtb-btn dtb-btn--primary dtb-btn--sm">Save Changes</button>'
			+ '<span class="dtb-support-form-status"></span>'
			+ '</div>'
			+ '</form>'
			+ '<div class="dtb-support-action-quickforms">'
			+ '<form class="dtb-support-followup-form">'
			+ '<label class="dtb-support-form-label">Follow-up</label>'
			+ '<div class="dtb-support-inline-form"><input class="dtb-input" type="datetime-local" name="followup_due_at"><button type="submit" class="dtb-btn dtb-btn--sm">Set</button></div>'
			+ '<span class="dtb-support-form-status"></span>'
			+ '</form>'
			+ '<form class="dtb-support-snooze-form">'
			+ '<label class="dtb-support-form-label">Snooze</label>'
			+ '<div class="dtb-support-inline-form"><input class="dtb-input" type="datetime-local" name="snooze_until"><button type="submit" class="dtb-btn dtb-btn--sm">Pause</button></div>'
			+ '<span class="dtb-support-form-status"></span>'
			+ '</form>'
			+ '</div>'
			+ '</div>'

			+ '</div>' // .dtb-support-ticket-left

			// ── Right sidebar — always-visible context ─────────────────────────
			+ '<aside class="dtb-support-ticket-context">'
			+ renderSupportCanonicalRail( ticket, payload || {}, dueAt, dueRaw, created, updated )
			+ '</aside>'

			+ '</div>' // .dtb-support-ticket-modal__body
			+ '</div>'; // .dtb-support-ticket-modal

		// Auto-scroll thread to bottom
		var thread = document.getElementById( 'dtb-chat-thread' );
		if ( thread ) {
			thread.scrollTop = thread.scrollHeight;
		}
	}

	function fetchWorkbenchAggregate() {
		var wb = getWorkbench();
		if ( ! wb ) {
			return;
		}
		var endpoint = wb.getAttribute( 'data-dtb-support-endpoint' );
		if ( ! endpoint ) {
			return;
		}
		var query = currentQueryFromUrl();
		query.queue = resolveActiveQueue( query );
		var url = new URL( endpoint, window.location.origin );
		Object.keys( query ).forEach( function ( key ) {
			var value = query[ key ];
			if ( value ) {
				url.searchParams.set( key, String( value ) );
			}
		} );
		var nonce = window.dtbAdminConfig && window.dtbAdminConfig.nonce ? window.dtbAdminConfig.nonce : '';

		fetch( url.toString(), {
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': nonce,
			},
			credentials: 'same-origin',
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'HTTP ' + res.status );
				}
				return res.json();
			} )
			.then( function ( data ) {
				var queues = data && data.queues ? data.queues : {};
				Object.keys( queues ).forEach( function ( key ) {
					var value = parseInt( queues[ key ], 10 ) || 0;
					document.querySelectorAll( '[data-dtb-support-queue-count="' + key + '"], [data-dtb-support-summary="' + key + '"]' ).forEach( function ( el ) {
						el.textContent = String( value );
					} );
				} );
				if ( data && data.meta ) {
					document.querySelectorAll( '[data-dtb-support-current-total]' ).forEach( function ( el ) {
						el.textContent = String( parseInt( data.meta.total || 0, 10 ) );
					} );
				}
			} )
			.catch( function () {} );
	}

	function openModalWithTicket( ticketId, ticketRef, ticketUrl ) {
		var els = getModalElements();
		if ( ! els || ! ticketId ) {
			return;
		}

		state.currentTicketId = Number( ticketId ) || 0;
		state.currentTicketUrl = getTicketUrl( ticketId, ticketUrl || '' );
		if ( els.viewButton ) {
			els.viewButton.setAttribute( 'data-dtb-ticket-url', state.currentTicketUrl );
		}
		setTicketParam( ticketId );
		renderModalLoading( els, ticketRef || ( '#' + ticketId ) );

		if ( typeof DtbAdmin !== 'undefined' && DtbAdmin.openModal ) {
			DtbAdmin.openModal( MODAL_ID );
		}

		var restBase = ( window.dtbAdminConfig && window.dtbAdminConfig.restUrl ? window.dtbAdminConfig.restUrl : '/wp-json' ).replace( /\/$/, '' );
		var endpoint = restBase + '/dtb/v1/support/tickets/' + encodeURIComponent( String( ticketId ) );
		var nonce = window.dtbAdminConfig && window.dtbAdminConfig.nonce ? window.dtbAdminConfig.nonce : '';

		fetch( endpoint, {
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': nonce,
			},
			credentials: 'same-origin',
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'HTTP ' + res.status );
				}
				return res.json();
			} )
			.then( function ( payload ) {
				renderTicketModal( els, payload );
			} )
			.catch( function () {
				renderModalError( els );
			} );
	}

	function parseRowContext( row ) {
		if ( ! row || ! row.dataset ) {
			return null;
		}
		return {
			ticketId: row.dataset.dtbTicketId || '',
			ticketRef: row.dataset.dtbTicketRef || '',
			ticketUrl: row.dataset.dtbTicketUrl || '',
		};
	}

	function navigateRegion( patch ) {
		var region = getLiveRegion();
		if ( ! region || typeof DtbAdmin === 'undefined' || ! DtbAdmin.liveNavigate ) {
			return;
		}
		var endpoint = region.getAttribute( 'data-dtb-endpoint' );
		if ( ! endpoint ) {
			return;
		}
		var current = currentQueryFromUrl();
		var query = {
			status: patch && patch.status != null ? patch.status : current.status,
			queue: patch && patch.queue != null ? patch.queue : current.queue,
			search: patch && patch.search != null ? patch.search : current.search,
			type: patch && patch.type != null ? patch.type : current.type,
			priority: patch && patch.priority != null ? patch.priority : current.priority,
			paged: patch && patch.paged != null ? patch.paged : current.paged || '1',
		};

		DtbAdmin.liveNavigate( {
			target: region,
			endpoint: endpoint,
			query: query,
			history: true,
		} );
	}

	function bindQueueAndFilters() {
		var searchTimer;

		// ── Ticket open — capture phase so nothing can stop propagation ───────
		document.addEventListener( 'click', function ( evt ) {
			// Never intercept clicks that originate inside the modal itself.
			if ( evt.target && evt.target.closest && evt.target.closest( '#' + MODAL_ID ) ) {
				return;
			}

			var link = evt.target && evt.target.closest ? evt.target.closest( '.dtb-support-open-ticket[data-dtb-ticket-id]' ) : null;
			var row  = ! link && evt.target && evt.target.closest ? evt.target.closest( '.dtb-support-row[data-dtb-ticket-id]' ) : null;
			var el   = link || row;
			if ( ! el ) {
				return;
			}

			// For bare-row clicks, ignore interactive child elements (except the ticket link itself).
			if ( row && evt.target.closest( 'a, button, input, select, textarea, label' ) && ! link ) {
				return;
			}

			// Allow native browser open for modifier-key combos.
			if ( evt.metaKey || evt.ctrlKey || evt.shiftKey || evt.altKey ) {
				return;
			}

			var ticketId  = el.getAttribute( 'data-dtb-ticket-id' );
			var ticketRef = el.getAttribute( 'data-dtb-ticket-ref' ) || ( '#' + ticketId );
			var ticketUrl = el.getAttribute( 'data-dtb-ticket-url' ) || getTicketUrl( ticketId, '' );
			if ( ! ticketId ) {
				return;
			}

			evt.preventDefault();
			evt.stopPropagation();
			openModalWithTicket( ticketId, ticketRef, ticketUrl );
		}, true ); // capture phase

		// ── Queue rail + other controls — bubble phase ────────────────────────
		document.addEventListener( 'click', function ( evt ) {
			var queueLink = evt.target && evt.target.closest ? evt.target.closest( '[data-dtb-support-queue]' ) : null;
			if ( queueLink ) {
				evt.preventDefault();
				var queue = queueLink.getAttribute( 'data-dtb-support-queue' ) || '';
				var status = queueLink.getAttribute( 'data-dtb-support-status' ) || '';
				navigateRegion( { queue: queue, status: status, paged: '1' } );
				return;
			}
		} );

		document.addEventListener( 'change', function ( evt ) {
			var filter = evt.target && evt.target.matches ? evt.target.matches( '[data-dtb-support-filter]' ) : false;
			if ( ! filter ) {
				return;
			}
			var type = document.querySelector( '[data-dtb-support-filter="type"]' );
			var priority = document.querySelector( '[data-dtb-support-filter="priority"]' );
			navigateRegion( {
				type: type ? type.value : '',
				priority: priority ? priority.value : '',
				paged: '1',
			} );
		} );

		document.addEventListener( 'input', function ( evt ) {
			var isSearch = evt.target && evt.target.matches ? evt.target.matches( '[data-dtb-support-search]' ) : false;
			if ( ! isSearch ) {
				return;
			}
			clearTimeout( searchTimer );
			searchTimer = setTimeout( function () {
				navigateRegion( {
					search: evt.target.value || '',
					paged: '1',
				} );
			}, 260 );
		} );
	}

	function bindRowKeyboardOpen() {
		document.querySelectorAll( '.dtb-support-row' ).forEach( function ( row ) {
			if ( row.dataset.dtbSupportKeyboardBound ) {
				return;
			}
			row.dataset.dtbSupportKeyboardBound = '1';
			row.setAttribute( 'tabindex', '0' );
			row.addEventListener( 'keydown', function ( evt ) {
				if ( evt.key !== 'Enter' && evt.key !== ' ' ) {
					return;
				}
				if ( evt.target && evt.target.closest && evt.target.closest( 'a,button,input,select,textarea,label' ) ) {
					return;
				}
				var context = parseRowContext( row );
				if ( context && context.ticketId ) {
					evt.preventDefault();
					openModalWithTicket( context.ticketId, context.ticketRef, context.ticketUrl );
				}
			} );
		} );
	}

	function bindModalActions() {
		// ── Email toolbar quick-reply shortcuts ────────────────────────────────
		document.addEventListener( 'click', function ( evt ) {
			var quickBtn = evt.target && evt.target.closest ? evt.target.closest( '.dtb-email-quick-reply' ) : null;
			if ( ! quickBtn || ! quickBtn.closest( '#' + MODAL_ID ) ) {
				return;
			}
			var mode = quickBtn.getAttribute( 'data-dtb-compose-mode' ) || 'reply';
			var overlay = byId( MODAL_ID );
			if ( ! overlay ) {
				return;
			}
			// Switch to Conversation tab
			overlay.querySelectorAll( '.dtb-support-modal-tab' ).forEach( function ( t ) {
				var isThread = t.getAttribute( 'data-dtb-modal-tab' ) === 'thread';
				t.classList.toggle( 'is-active', isThread );
				t.setAttribute( 'aria-selected', isThread ? 'true' : 'false' );
			} );
			overlay.querySelectorAll( '.dtb-support-modal-panel' ).forEach( function ( p ) {
				p.classList.toggle( 'is-active', p.getAttribute( 'data-dtb-modal-panel' ) === 'thread' );
			} );
			// Set compose mode
			var compose = overlay.querySelector( '.dtb-chat-compose' );
			if ( ! compose ) {
				return;
			}
			compose.querySelectorAll( '.dtb-chat-mode-btn' ).forEach( function ( b ) {
				b.classList.toggle( 'is-active', b.getAttribute( 'data-dtb-compose-mode' ) === mode );
			} );
			var form = compose.querySelector( '.dtb-support-reply-form' );
			if ( form ) {
				form.setAttribute( 'data-dtb-reply-type', mode );
				compose.classList.toggle( 'dtb-chat-compose--note', mode === 'note' );
				var ta = form.querySelector( 'textarea[name="message"]' );
				if ( ta ) {
					ta.placeholder = mode === 'note'
						? 'Write a private note visible only to staff…'
						: 'Write a reply to the customer…';
					ta.focus();
				}
			}
		} );

		// ── Compose mode toggle (Reply / Internal Note) ────────────────────────
		document.addEventListener( 'click', function ( evt ) {
			var modeBtn = evt.target && evt.target.closest ? evt.target.closest( '.dtb-chat-mode-btn' ) : null;
			if ( ! modeBtn || ! modeBtn.closest( '#' + MODAL_ID ) ) {
				return;
			}
			var mode = modeBtn.getAttribute( 'data-dtb-compose-mode' ) || 'reply';
			var compose = modeBtn.closest( '.dtb-chat-compose' );
			if ( ! compose ) {
				return;
			}
			compose.querySelectorAll( '.dtb-chat-mode-btn' ).forEach( function ( b ) {
				b.classList.toggle( 'is-active', b === modeBtn );
			} );
			var form = compose.querySelector( '.dtb-support-reply-form' );
			if ( form ) {
				form.setAttribute( 'data-dtb-reply-type', mode );
				var ta = form.querySelector( 'textarea[name="message"]' );
				if ( ta ) {
					ta.placeholder = mode === 'note'
						? 'Write a private note visible only to staff…'
						: 'Write a reply to the customer…';
				}
				compose.classList.toggle( 'dtb-chat-compose--note', mode === 'note' );
			}
		} );

		// ── Tab switching ──────────────────────────────────────────────────────
		document.addEventListener( 'click', function ( evt ) {
			var tab = evt.target && evt.target.closest ? evt.target.closest( '.dtb-support-modal-tab' ) : null;
			if ( ! tab || ! tab.closest( '#' + MODAL_ID ) ) {
				return;
			}
			var tabKey = tab.getAttribute( 'data-dtb-modal-tab' ) || '';
			var overlay = byId( MODAL_ID );
			if ( ! overlay ) {
				return;
			}
			overlay.querySelectorAll( '.dtb-support-modal-tab' ).forEach( function ( t ) {
				t.classList.toggle( 'is-active', t === tab );
				t.setAttribute( 'aria-selected', t === tab ? 'true' : 'false' );
			} );
			overlay.querySelectorAll( '.dtb-support-modal-panel' ).forEach( function ( p ) {
				p.classList.toggle( 'is-active', p.getAttribute( 'data-dtb-modal-panel' ) === tabKey );
			} );
		} );

		// ── Reply / Internal Note form submit ──────────────────────────────────
		document.addEventListener( 'submit', function ( evt ) {
			var form = evt.target && evt.target.closest ? evt.target.closest( '.dtb-support-reply-form' ) : null;
			if ( ! form || ! form.closest( '#' + MODAL_ID ) ) {
				return;
			}
			evt.preventDefault();
			var isNote = form.getAttribute( 'data-dtb-reply-type' ) === 'note';
			var messageEl = form.querySelector( '[name="message"]' );
			var message = messageEl ? messageEl.value : '';
			var statusEl = form.querySelector( '.dtb-support-form-status' );
			var submitBtn = form.querySelector( '[type="submit"]' );
			if ( ! message.trim() ) {
				if ( statusEl ) {
					statusEl.textContent = 'Message cannot be empty.';
					statusEl.className = 'dtb-support-form-status is-error';
				}
				return;
			}
			if ( submitBtn ) { submitBtn.disabled = true; }
			if ( statusEl ) {
				statusEl.textContent = 'Sending…';
				statusEl.className = 'dtb-support-form-status';
			}
			var restBase = ( window.dtbAdminConfig && window.dtbAdminConfig.restUrl ? window.dtbAdminConfig.restUrl : '/wp-json/' ).replace( /\/$/, '' );
			var endpoint = restBase + '/dtb/v1/support/tickets/' + encodeURIComponent( String( state.currentTicketId ) ) + '/reply';
			var nonce = window.dtbAdminConfig && window.dtbAdminConfig.nonce ? window.dtbAdminConfig.nonce : '';
			fetch( endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-WP-Nonce': nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( { message: message, is_internal: isNote } ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( d ) {
						return { ok: res.ok, data: d };
					} );
				} )
				.then( function ( r ) {
					if ( r.ok && r.data && r.data.success ) {
						form.reset();
						if ( statusEl ) {
							statusEl.textContent = isNote ? 'Note saved.' : 'Reply sent.';
							statusEl.className = 'dtb-support-form-status is-success';
						}
						applyMutationResult( r.data );
					} else {
						var msg = ( r.data && r.data.message ) ? r.data.message : 'Failed to send.';
						if ( statusEl ) {
							statusEl.textContent = msg;
							statusEl.className = 'dtb-support-form-status is-error';
						}
					}
				} )
				.catch( function () {
					if ( statusEl ) {
						statusEl.textContent = 'Network error. Please try again.';
						statusEl.className = 'dtb-support-form-status is-error';
					}
				} )
				.finally( function () {
					if ( submitBtn ) { submitBtn.disabled = false; }
				} );
		} );

		// ── Actions form submit (status / priority) ────────────────────────────
		document.addEventListener( 'submit', function ( evt ) {
			var form = evt.target && evt.target.closest ? evt.target.closest( '.dtb-support-actions-form' ) : null;
			if ( ! form || ! form.closest( '#' + MODAL_ID ) ) {
				return;
			}
			evt.preventDefault();
			var statusEl = form.querySelector( '.dtb-support-form-status' );
			var submitBtn = form.querySelector( '[type="submit"]' );
			var statusVal = ( form.querySelector( '[name="status"]' ) || {} ).value || '';
			var priorityVal = ( form.querySelector( '[name="priority"]' ) || {} ).value || '';
			var noteVal = ( form.querySelector( '[name="note"]' ) || {} ).value || '';
			var body = {};
			var closing = [ 'resolved', 'resolved_pending_close', 'closed' ].indexOf( statusVal ) !== -1;
			if ( closing && ! noteVal.trim() ) {
				if ( statusEl ) {
					statusEl.textContent = 'Add a resolution note before closing this ticket.';
					statusEl.className = 'dtb-support-form-status is-error';
				}
				return;
			}
			if ( closing ) {
				var linked = ( state.currentPayload && state.currentPayload.linked_records ) || {};
				var hasWarnings = ( Array.isArray( linked.warnings ) && linked.warnings.length ) || ( Array.isArray( linked.mismatches ) && linked.mismatches.length );
				if ( hasWarnings && ! window.confirm( 'Linked record warnings exist. Close this ticket anyway?' ) ) {
					return;
				}
			}
			if ( statusVal ) { body.status = statusVal; }
			if ( priorityVal ) { body.priority = priorityVal; }
			if ( noteVal.trim() ) { body.note = noteVal; }
			if ( Object.keys( body ).length === 0 ) {
				if ( statusEl ) {
					statusEl.textContent = 'No changes to save.';
					statusEl.className = 'dtb-support-form-status is-error';
				}
				return;
			}
			if ( submitBtn ) { submitBtn.disabled = true; }
			if ( statusEl ) {
				statusEl.textContent = 'Saving…';
				statusEl.className = 'dtb-support-form-status';
			}
			var restBase = ( window.dtbAdminConfig && window.dtbAdminConfig.restUrl ? window.dtbAdminConfig.restUrl : '/wp-json/' ).replace( /\/$/, '' );
			var endpoint = restBase + '/dtb/v1/support/tickets/' + encodeURIComponent( String( state.currentTicketId ) );
			var nonce = window.dtbAdminConfig && window.dtbAdminConfig.nonce ? window.dtbAdminConfig.nonce : '';
			fetch( endpoint, {
				method: 'PATCH',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-WP-Nonce': nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( body ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( d ) {
						return { ok: res.ok, data: d };
					} );
				} )
				.then( function ( r ) {
					if ( r.ok && r.data && r.data.success ) {
						if ( statusEl ) {
							statusEl.textContent = 'Changes saved.';
							statusEl.className = 'dtb-support-form-status is-success';
						}
						applyMutationResult( r.data );
					} else {
						var msg = ( r.data && r.data.message ) ? r.data.message : 'Save failed.';
						if ( statusEl ) {
							statusEl.textContent = msg;
							statusEl.className = 'dtb-support-form-status is-error';
						}
					}
				} )
				.catch( function () {
					if ( statusEl ) {
						statusEl.textContent = 'Network error. Please try again.';
						statusEl.className = 'dtb-support-form-status is-error';
					}
				} )
				.finally( function () {
					if ( submitBtn ) { submitBtn.disabled = false; }
				} );
		} );

		// ── Follow-up / snooze quick actions ───────────────────────────────────
		document.addEventListener( 'submit', function ( evt ) {
			var form = evt.target && evt.target.closest ? evt.target.closest( '.dtb-support-followup-form, .dtb-support-snooze-form' ) : null;
			if ( ! form || ! form.closest( '#' + MODAL_ID ) ) {
				return;
			}
			evt.preventDefault();

			var isSnooze = form.classList.contains( 'dtb-support-snooze-form' );
			var fieldName = isSnooze ? 'snooze_until' : 'followup_due_at';
			var value = ( form.querySelector( '[name="' + fieldName + '"]' ) || {} ).value || '';
			var statusEl = form.querySelector( '.dtb-support-form-status' );
			var submitBtn = form.querySelector( '[type="submit"]' );

			if ( ! value ) {
				if ( statusEl ) {
					statusEl.textContent = 'Choose a date and time.';
					statusEl.className = 'dtb-support-form-status is-error';
				}
				return;
			}

			if ( submitBtn ) { submitBtn.disabled = true; }
			if ( statusEl ) {
				statusEl.textContent = 'Saving…';
				statusEl.className = 'dtb-support-form-status';
			}

			var restBase = ( window.dtbAdminConfig && window.dtbAdminConfig.restUrl ? window.dtbAdminConfig.restUrl : '/wp-json/' ).replace( /\/$/, '' );
			var endpoint = restBase + '/dtb/v1/support/tickets/' + encodeURIComponent( String( state.currentTicketId ) ) + '/' + ( isSnooze ? 'snooze' : 'followup' );
			var nonce = window.dtbAdminConfig && window.dtbAdminConfig.nonce ? window.dtbAdminConfig.nonce : '';
			var body = {};
			body[ fieldName ] = value;

			fetch( endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-WP-Nonce': nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( body ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( d ) {
						return { ok: res.ok, data: d };
					} );
				} )
				.then( function ( r ) {
					if ( r.ok && r.data && r.data.success ) {
						if ( statusEl ) {
							statusEl.textContent = isSnooze ? 'Ticket paused.' : 'Follow-up set.';
							statusEl.className = 'dtb-support-form-status is-success';
						}
						applyMutationResult( r.data );
					} else {
						var msg = ( r.data && r.data.message ) ? r.data.message : 'Save failed.';
						if ( statusEl ) {
							statusEl.textContent = msg;
							statusEl.className = 'dtb-support-form-status is-error';
						}
					}
				} )
				.catch( function () {
					if ( statusEl ) {
						statusEl.textContent = 'Network error. Please try again.';
						statusEl.className = 'dtb-support-form-status is-error';
					}
				} )
				.finally( function () {
					if ( submitBtn ) { submitBtn.disabled = false; }
				} );
		} );

		// ── Macro panel toggle ─────────────────────────────────────────────────
		document.addEventListener( 'click', function ( evt ) {
			var toggleBtn = evt.target && evt.target.closest ? evt.target.closest( '.dtb-chat-macro-toggle' ) : null;
			if ( ! toggleBtn || ! toggleBtn.closest( '#' + MODAL_ID ) ) {
				return;
			}
			var compose = toggleBtn.closest( '.dtb-chat-compose' );
			var panel = compose ? compose.querySelector( '#dtb-macro-panel' ) : null;
			if ( ! panel ) {
				return;
			}
			var isOpen = ! panel.hidden;
			panel.hidden = isOpen;
			toggleBtn.setAttribute( 'aria-expanded', isOpen ? 'false' : 'true' );
			toggleBtn.classList.toggle( 'is-active', ! isOpen );
		} );

		// ── Macro close button ─────────────────────────────────────────────────
		document.addEventListener( 'click', function ( evt ) {
			var closeBtn = evt.target && evt.target.closest ? evt.target.closest( '.dtb-macro-close' ) : null;
			if ( ! closeBtn || ! closeBtn.closest( '#' + MODAL_ID ) ) {
				return;
			}
			var panel = closeBtn.closest( '#dtb-macro-panel' );
			if ( panel ) {
				panel.hidden = true;
				var compose = panel.closest( '.dtb-chat-compose' );
				var toggleBtn = compose ? compose.querySelector( '.dtb-chat-macro-toggle' ) : null;
				if ( toggleBtn ) {
					toggleBtn.setAttribute( 'aria-expanded', 'false' );
					toggleBtn.classList.remove( 'is-active' );
				}
			}
		} );

		// ── Macro insert ───────────────────────────────────────────────────────
		document.addEventListener( 'click', function ( evt ) {
			var macroBtn = evt.target && evt.target.closest ? evt.target.closest( '.dtb-macro-btn' ) : null;
			if ( ! macroBtn || ! macroBtn.closest( '#' + MODAL_ID ) ) {
				return;
			}
			var text = macroBtn.getAttribute( 'data-macro-text' ) || '';
			var compose = macroBtn.closest( '.dtb-chat-compose' );
			if ( ! compose ) {
				return;
			}
			var ta = compose.querySelector( 'textarea[name="message"]' );
			if ( ta ) {
				ta.value = text;
				ta.focus();
				// Auto-resize if needed
				ta.style.height = 'auto';
				ta.style.height = Math.min( ta.scrollHeight, 280 ) + 'px';
			}
			// Close the macro panel
			var panel = compose.querySelector( '#dtb-macro-panel' );
			if ( panel ) {
				panel.hidden = true;
			}
			var toggleBtn = compose.querySelector( '.dtb-chat-macro-toggle' );
			if ( toggleBtn ) {
				toggleBtn.setAttribute( 'aria-expanded', 'false' );
				toggleBtn.classList.remove( 'is-active' );
			}
		} );

		// ── "Open Full Ticket" button + modal close ────────────────────────────
		document.addEventListener( 'click', function ( evt ) {
			var viewBtn = evt.target && evt.target.closest ? evt.target.closest( '[data-dtb-support-modal-action="view"]' ) : null;
			if ( viewBtn ) {
				var targetUrl = viewBtn.getAttribute( 'data-dtb-ticket-url' ) || state.currentTicketUrl;
				if ( targetUrl ) {
					window.location.href = targetUrl;
				}
				return;
			}

			var closeBtn = evt.target && evt.target.closest ? evt.target.closest( '.dtb-modal__close, [data-dtb-close-modal]' ) : null;
			if ( closeBtn && closeBtn.closest( '#' + MODAL_ID ) ) {
				state.currentTicketId = 0;
				state.currentTicketUrl = '';
				setTicketParam( '' );
			}
		} );
	}

	function openFromDeepLink() {
		if ( ! window.URLSearchParams ) {
			return;
		}
		var params = new URLSearchParams( window.location.search );
		var ticketId = params.get( 'ticket_id' );
		if ( ! ticketId ) {
			return;
		}

		var row = document.querySelector( '.dtb-support-row[data-dtb-ticket-id="' + String( ticketId ).replace( /"/g, '' ) + '"]' );
		var context = parseRowContext( row ) || {
			ticketId: ticketId,
			ticketRef: '#' + ticketId,
			ticketUrl: getTicketUrl( ticketId, '' ),
		};
		openModalWithTicket( context.ticketId, context.ticketRef, context.ticketUrl );
	}

	function syncWorkbenchFromUrl() {
		var query = currentQueryFromUrl();
		var activeQueue = resolveActiveQueue( query );
		updateQueueUi( activeQueue );
		syncFiltersFromQuery( query );
		bindRowKeyboardOpen();
		fetchWorkbenchAggregate();
	}

	function bindLiveRegionEvents() {
		var region = getLiveRegion();
		if ( ! region ) {
			return;
		}
		region.addEventListener( 'dtb:live:navigated', function () {
			syncWorkbenchFromUrl();
		} );
	}

	function init() {
		if ( ! getWorkbench() || ! getLiveRegion() || ! byId( MODAL_ID ) ) {
			return;
		}

		bindQueueAndFilters();
		bindModalActions();
		bindLiveRegionEvents();
		syncWorkbenchFromUrl();
		openFromDeepLink();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init, { once: true } );
	} else {
		init();
	}
}() );
