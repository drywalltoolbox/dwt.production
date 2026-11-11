/**
 * Progressive enhancement for the Schematics Workspace admin screen.
 *
 * Intercepts submits on any form carrying [data-dtb-schematics-operation]
 * (register/link/sync/mapping/reconciliation actions) and runs them via
 * admin-ajax.php instead of a full-page admin-post.php submit, replacing
 * #dtb-schematics-workspace-app in place with the response. No confirmation
 * dialog, no page navigation or reload for either preview or commit actions.
 * If this script fails to load, the forms still work as plain admin-post.php
 * submits (see Admin/Workspace/Workspace.php).
 */
( function () {
	'use strict';

	function currentQueryParams() {
		var params = new URLSearchParams( window.location.search );
		return {
			view: params.get( 'view' ) || '',
			s: params.get( 's' ) || '',
			lifecycle: params.get( 'lifecycle' ) || '',
			paged: params.get( 'paged' ) || '',
		};
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value;
		return div.innerHTML;
	}

	function setBusy( form, busy ) {
		var button = form.querySelector( 'button' );
		if ( ! button ) { return; }
		button.disabled = busy;
		form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		if ( busy ) {
			button.dataset.dtbLabel = button.dataset.dtbLabel || button.textContent;
			var operation = form.querySelector( 'input[name="operation"]' );
			var operationName = operation ? operation.value : '';
			if ( operationName === 'migrate_hotspots_all_preview' ) {
				button.textContent = window.dtbSchematicsWorkspace.previewingLabel || 'Previewing all hotspot files…';
			} else if ( operationName === 'migrate_hotspots_all_commit' ) {
				button.textContent = window.dtbSchematicsWorkspace.syncingLabel || 'Synchronizing all hotspot files…';
			} else {
				button.textContent = window.dtbSchematicsWorkspace.workingLabel || 'Working…';
			}
		} else if ( button.dataset.dtbLabel ) {
			button.textContent = button.dataset.dtbLabel;
		}
	}

	function handleSubmit( event ) {
		var form = event.target.closest( 'form[data-dtb-schematics-operation]' );
		if ( ! form || ! window.dtbSchematicsWorkspace ) { return; }
		event.preventDefault();

		var app = document.getElementById( 'dtb-schematics-workspace-app' );
		if ( ! app ) { return; }

		var formData = new FormData( form );
		formData.set( 'action', 'dtb_schematics_workspace_ajax_action' );
		var query = currentQueryParams();
		Object.keys( query ).forEach( function ( key ) {
			if ( query[ key ] ) { formData.set( key, query[ key ] ); }
		} );

		setBusy( form, true );
		app.setAttribute( 'aria-busy', 'true' );

		fetch( window.dtbSchematicsWorkspace.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( payload ) {
				if ( payload && payload.success && payload.data && typeof payload.data.html === 'string' ) {
					app.innerHTML = payload.data.html;
					app.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				} else {
					var message = ( payload && payload.data && payload.data.message ) || window.dtbSchematicsWorkspace.genericErrorLabel;
					app.insertAdjacentHTML( 'afterbegin', '<div class="notice notice-error"><p>' + escapeHtml( message ) + '</p></div>' );
				}
			} )
			.catch( function () {
				app.insertAdjacentHTML( 'afterbegin', '<div class="notice notice-error"><p>' + escapeHtml( window.dtbSchematicsWorkspace.genericErrorLabel ) + '</p></div>' );
			} )
			.finally( function () {
				setBusy( form, false );
				app.removeAttribute( 'aria-busy' );
			} );
	}

	document.addEventListener( 'submit', handleSubmit );
} )();
