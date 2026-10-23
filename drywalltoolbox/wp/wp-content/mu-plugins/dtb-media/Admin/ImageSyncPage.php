<?php
/**
 * Image Sync tool page (DTB Admin shell).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', 'dtb_image_sync_page_enqueue' );
add_action( 'rest_api_init', 'dtb_image_sync_page_register_route' );

/**
 * Localize page-specific runtime config.
 */
function dtb_image_sync_page_enqueue( string $hook ): void {
	if ( false === strpos( $hook, 'dtb-image-sync' ) ) {
		return;
	}

	wp_localize_script(
		'dtb-admin',
		'dtbImageSyncPage',
		[
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( 'dtb_image_sync_admin' ),
			'diagnosticsPageUrl'=> admin_url( 'admin.php?page=dtb-system-manager' ),
			'defaultPath'       => dtb_image_sync_page_default_path(),
		]
	);
}

/**
 * Register live-region endpoint.
 */
function dtb_image_sync_page_register_route(): void {
	register_rest_route(
		'dtb/v1',
		'/admin/image-sync',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_image_sync_page_workspace_handler',
			'permission_callback' => static fn() => dtb_image_sync_can_manage(),
		]
	);
}

/**
 * Workspace endpoint callback.
 */
function dtb_image_sync_page_workspace_handler( WP_REST_Request $request ): WP_REST_Response {
	$upload_path = dtb_image_sync_page_resolve_upload_path( (string) $request->get_param( 'upload_path' ) );
	$html        = dtb_image_sync_page_render_workspace_html( $upload_path );

	return new WP_REST_Response(
		[
			'ok'   => true,
			'html' => $html,
		],
		200
	);
}

/**
 * Render callback registered via ToolLibraryMenu.
 */
function dtb_image_sync_render_page(): void {
	if ( ! dtb_image_sync_can_manage() ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$requested_path = sanitize_text_field( (string) ( $_GET['upload_path'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$upload_path    = dtb_image_sync_page_resolve_upload_path( $requested_path );

	$directory_options = function_exists( 'dtb_get_upload_subdirectories' )
		? dtb_get_upload_subdirectories()
		: [];
	$fixed_pathways = [ dtb_image_sync_page_default_path() ];
	if ( function_exists( 'dtb_schematics_can_manage' ) && dtb_schematics_can_manage() ) {
		$fixed_pathways[] = '2026/schematics';
	}
	$directory_options = array_values( array_unique( array_merge( $fixed_pathways, $directory_options ) ) );
	if ( ! in_array( $upload_path, $directory_options, true ) ) {
		array_unshift( $directory_options, $upload_path );
	}

	dtb_admin_shell_open(
		[
			'title'    => __( 'Image Sync', 'drywall-toolbox' ),
			'subtitle' => __( 'Choose a controlled pathway to register and link product images or schematic diagrams.', 'drywall-toolbox' ),
			'section'  => 'tools',
			'page'     => 'dtb-image-sync',
			'template' => 'tool',
			'icon'     => 'dashicons-format-image',
		]
	);

	echo dtb_admin_ui_toolbar_open();
	echo '<label for="dtb-image-sync-upload-path"><strong>' . esc_html__( 'Pathway', 'drywall-toolbox' ) . '</strong></label>';
	echo '<select id="dtb-image-sync-upload-path" class="dtb-input dtb-select">';
	foreach ( $directory_options as $directory ) {
		$is_schematic = '2026/schematics' === $directory;
		$option_label = $is_schematic
			? __( 'Schematic diagrams — uploads/2026/schematics', 'drywall-toolbox' )
			: sprintf( __( 'Product images — uploads/%s', 'drywall-toolbox' ), $directory );
		printf(
			'<option value="%s" data-pathway="%s"%s>%s</option>',
			esc_attr( $directory ),
			$is_schematic ? 'schematics' : 'product-images',
			selected( $directory, $upload_path, false ),
			esc_html( $option_label )
		);
	}
	echo '</select>';
	echo '<label class="screen-reader-text" for="dtb-image-sync-limit">' . esc_html__( 'Batch limit', 'drywall-toolbox' ) . '</label>';
	echo '<input id="dtb-image-sync-limit" class="dtb-input" type="number" min="1" max="250" value="25" style="max-width:110px;" />';
	echo '<label class="dtb-checkbox" style="display:inline-flex;align-items:center;gap:6px;">';
	echo '<input id="dtb-image-sync-dry-run" type="checkbox" />';
	echo esc_html__( 'Dry run', 'drywall-toolbox' );
	echo '</label>';
	echo '<label class="dtb-checkbox" style="display:inline-flex;align-items:center;gap:6px;">';
	echo '<input id="dtb-image-sync-force" type="checkbox" />';
	echo esc_html__( 'Force relink', 'drywall-toolbox' );
	echo '</label>';
	echo dtb_admin_ui_toolbar_spacer();
	echo dtb_admin_ui_button( __( 'Refresh Snapshot', 'drywall-toolbox' ), [ 'type' => 'secondary', 'data' => [ 'dtb-image-sync-refresh' => '1' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Release Lock', 'drywall-toolbox' ), [ 'type' => 'ghost', 'data' => [ 'dtb-image-sync-action' => 'release_lock' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Fix Renamed Files', 'drywall-toolbox' ), [ 'type' => 'ghost', 'data' => [ 'dtb-image-sync-action' => 'fix_renamed' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Register Only', 'drywall-toolbox' ), [ 'type' => 'secondary', 'data' => [ 'dtb-image-sync-action' => 'register_only' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Link Only', 'drywall-toolbox' ), [ 'type' => 'secondary', 'data' => [ 'dtb-image-sync-action' => 'link_only' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Register + Link', 'drywall-toolbox' ), [ 'type' => 'primary', 'data' => [ 'dtb-image-sync-action' => 'sync' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_toolbar_close();

	dtb_admin_shell_live_region_open(
		[
			'id'       => 'dtb-image-sync-workspace',
			'module'   => 'image-sync',
			'endpoint' => add_query_arg(
				[ 'upload_path' => $upload_path ],
				rest_url( 'dtb/v1/admin/image-sync' )
			),
			'interval' => 10000,
		]
	);
	echo dtb_image_sync_page_render_workspace_html( $upload_path ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	dtb_admin_shell_live_region_close();
	?>
	<div class="dtb-card dtb-mt-16">
		<div class="dtb-card__header">
			<h3 class="dtb-card__title"><?php esc_html_e( 'Run Status', 'drywall-toolbox' ); ?></h3>
		</div>
		<div class="dtb-card__body">
			<p id="dtb-image-sync-status-line"><?php esc_html_e( 'Idle.', 'drywall-toolbox' ); ?></p>
			<div class="dtb-progress" style="height:10px;background:var(--dtb-surface-soft);border-radius:999px;overflow:hidden;">
				<div id="dtb-image-sync-progress" style="height:100%;width:0;background:var(--dtb-primary);transition:width var(--dtb-motion-base) var(--dtb-ease-standard);"></div>
			</div>
			<pre id="dtb-image-sync-log" style="margin-top:12px;max-height:220px;overflow:auto;background:var(--dtb-surface-soft);padding:12px;border:1px solid var(--dtb-border-soft);border-radius:var(--dtb-radius-md);font-size:12px;"></pre>
			<p class="description" style="margin-top:8px;">
				<?php esc_html_e( 'Detailed backend diagnostics are intentionally hidden here. Use System Manager when deeper traces are required.', 'drywall-toolbox' ); ?>
			</p>
		</div>
	</div>
	<script>
	(function () {
		var cfg = Object.assign({
			ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonce: <?php echo wp_json_encode( wp_create_nonce( 'dtb_image_sync_admin' ) ); ?>,
			diagnosticsPageUrl: <?php echo wp_json_encode( admin_url( 'admin.php?page=dtb-system-manager' ) ); ?>,
			defaultPath: <?php echo wp_json_encode( dtb_image_sync_page_default_path() ); ?>
		}, window.dtbImageSyncPage || {});
		var regionId = 'dtb-image-sync-workspace';
		var region = document.querySelector('[data-dtb-live-region="' + regionId + '"]');
		var pathSelect = document.getElementById('dtb-image-sync-upload-path');
		var limitInput = document.getElementById('dtb-image-sync-limit');
		var dryRunInput = document.getElementById('dtb-image-sync-dry-run');
		var forceInput = document.getElementById('dtb-image-sync-force');
		var forceLabel = forceInput ? forceInput.closest('label') : null;
		var primaryAction = document.querySelector('[data-dtb-image-sync-action="sync"]');
		var statusLine = document.getElementById('dtb-image-sync-status-line');
		var progressBar = document.getElementById('dtb-image-sync-progress');
		var logEl = document.getElementById('dtb-image-sync-log');
		var running = false;
		var pollTimer = null;
		var snapshotTimer = null;
		var progressPollBusy = false;
		var snapshotPollBusy = false;
		var MAX_BATCHES = 2000;
		var schematicConfirmed = false;

		function isSchematicPathway() {
			return getUploadPath() === '2026/schematics';
		}

		function updatePathwayControls() {
			var schematic = isSchematicPathway();
			['release_lock', 'fix_renamed', 'register_only', 'link_only'].forEach(function (action) {
				var control = document.querySelector('[data-dtb-image-sync-action="' + action + '"]');
				if (control) control.hidden = schematic;
			});
			if (forceLabel) forceLabel.hidden = schematic;
			if (primaryAction) primaryAction.textContent = schematic ? 'Register & Link Schematics' : 'Register + Link';
		}

		function appendLog(line) {
			if (!logEl) return;
			logEl.textContent = (logEl.textContent ? logEl.textContent + '\n' : '') + line;
			logEl.scrollTop = logEl.scrollHeight;
		}

		function setStatus(text, isError) {
			if (!statusLine) return;
			statusLine.textContent = text;
			statusLine.style.color = isError ? 'var(--dtb-danger)' : 'var(--dtb-text)';
		}

		function setProgress(ratio) {
			if (!progressBar) return;
			var bounded = Math.max(0, Math.min(1, ratio || 0));
			progressBar.style.width = Math.round(bounded * 100) + '%';
		}

		function getUploadPath() {
			if (!pathSelect) return cfg.defaultPath || '2026/media';
			return (pathSelect.value || cfg.defaultPath || '2026/media').trim();
		}

		function getLimit() {
			var value = parseInt(limitInput && limitInput.value ? limitInput.value : '25', 10);
			if (Number.isNaN(value)) value = 25;
			return Math.max(1, Math.min(250, value));
		}

		function getEndpoint(path) {
			var url = new URL((region && region.getAttribute('data-dtb-endpoint')) || '', window.location.origin);
			url.searchParams.set('upload_path', path);
			return url.toString();
		}

		function refreshWorkspace() {
			if (!region || !window.DtbAdmin || typeof window.DtbAdmin.liveRefresh !== 'function') return;
			var path = getUploadPath();
			region.setAttribute('data-dtb-endpoint', getEndpoint(path));
			window.DtbAdmin.liveRefresh(region);
		}

		function setToolbarDisabled(disabled) {
			document.querySelectorAll('[data-dtb-image-sync-action], [data-dtb-image-sync-refresh], #dtb-image-sync-upload-path, #dtb-image-sync-limit, #dtb-image-sync-dry-run, #dtb-image-sync-force')
				.forEach(function (el) { el.disabled = !!disabled; });
		}

		function formBody(syncAction, extra) {
			var body = new URLSearchParams();
			body.set('action', 'dtb_image_sync');
			body.set('nonce', cfg.nonce || '');
			body.set('sync_action', syncAction);
			body.set('upload_path', getUploadPath());
			body.set('limit', String(getLimit()));
			body.set('offset', String((extra && extra.offset) || 0));
			body.set('dry_run', dryRunInput && dryRunInput.checked ? '1' : '0');
			body.set('force', forceInput && forceInput.checked ? '1' : '0');
			body.set('register_only', syncAction === 'register_only' ? '1' : '0');
			if (syncAction === 'sync_schematics' && schematicConfirmed) body.set('schematic_confirmation', '1');
			return body;
		}

		function sleep(ms) {
			return new Promise(function (resolve) {
				window.setTimeout(resolve, ms);
			});
		}

		function parseRetryAfterMs(res) {
			if (!res || !res.headers) return 0;
			var header = res.headers.get('Retry-After');
			if (!header) return 0;
			var seconds = parseInt(header, 10);
			if (Number.isFinite(seconds) && seconds > 0) return seconds * 1000;
			var retryAt = Date.parse(header);
			return Number.isFinite(retryAt) ? Math.max(0, retryAt - Date.now()) : 0;
		}

		function computeBackoffDelayMs(attempt) {
			var capped = Math.min(6, Math.max(1, attempt));
			return (700 * Math.pow(2, capped - 1)) + Math.floor(Math.random() * 300);
		}

		function shouldRetry(statusCode, attempt, maxRetries, isNetwork) {
			if (attempt >= maxRetries) return false;
			if (isNetwork) return true;
			return [429, 502, 503, 504].indexOf(statusCode) !== -1;
		}

		function post(syncAction, extra) {
			var maxRetries = syncAction === 'sync_schematics' ? 0 : (['progress', 'status', 'status_snapshot'].indexOf(syncAction) !== -1 ? 5 : 3);

			function attemptFetch(attempt) {
				return fetch(cfg.ajaxUrl || window.ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: formBody(syncAction, extra).toString()
				}).then(function (res) {
					return res.text().then(function (text) {
						var payload = null;
						if (text) {
							try {
								payload = JSON.parse(text);
							} catch (err) {
								payload = null;
							}
						}

						if (res.ok && payload && payload.success) {
							return payload.data || {};
						}

						if (shouldRetry(res.status, attempt, maxRetries, false)) {
							var retryAfter = parseRetryAfterMs(res);
							return sleep(retryAfter > 0 ? retryAfter : computeBackoffDelayMs(attempt)).then(function () {
								return attemptFetch(attempt + 1);
							});
						}

						var msg = payload && payload.data && payload.data.message
							? payload.data.message
							: 'Image sync request failed (' + (res.status || 'network') + ').';
						var error = new Error(msg);
						error.dtbNoRetry = true;
						throw error;
					});
				}).catch(function (err) {
					if (err && err.dtbNoRetry) {
						throw err;
					}
					if (shouldRetry(0, attempt, maxRetries, true)) {
						return sleep(computeBackoffDelayMs(attempt)).then(function () {
							return attemptFetch(attempt + 1);
						});
					}
					throw err;
				});
			}

			return attemptFetch(1);
		}

		function readProgress() {
			if (isSchematicPathway()) return Promise.resolve();
			if (progressPollBusy) return Promise.resolve();
			progressPollBusy = true;
			return post('progress', {}).then(function (payload) {
				var p = payload && payload.progress ? payload.progress : null;
				if (!p) return;
				var processed = parseInt(p.processed || 0, 10);
				var total = parseInt(p.batch_total || 0, 10);
				var pct = total > 0 ? processed / total : 0;
				var label = p.last_sku || p.last_item || 'working';
				var throughput = p.throughput_per_min ? p.throughput_per_min + '/min' : 'n/a';
				if (total > 0) setProgress(pct);
				if (running) {
					setStatus('Running... ' + processed + '/' + (total || '?') + ' | ' + label + ' | ' + throughput);
				}
			}).catch(function () {
				/* Keep the active run moving if a progress poll drops. */
			}).finally(function () {
				progressPollBusy = false;
			});
		}

		function startProgressPolling() {
			if (pollTimer) return;
			pollTimer = window.setInterval(readProgress, 1500);
			readProgress();
		}

		function stopProgressPolling() {
			if (pollTimer) {
				window.clearInterval(pollTimer);
				pollTimer = null;
			}
		}

		function readSnapshot() {
			if (isSchematicPathway()) {
				refreshWorkspace();
				return Promise.resolve();
			}
			if (snapshotPollBusy) return Promise.resolve();
			snapshotPollBusy = true;
			return post('status', { upload_path: getUploadPath() }).then(function () {
				refreshWorkspace();
			}).catch(function () {
				/* Keep the existing snapshot rendered if a single poll fails. */
			}).finally(function () {
				snapshotPollBusy = false;
			});
		}

		function startSnapshotPolling() {
			if (snapshotTimer) return;
			snapshotTimer = window.setInterval(readSnapshot, 8000);
			readSnapshot();
		}

		function stopSnapshotPolling() {
			if (snapshotTimer) {
				window.clearInterval(snapshotTimer);
				snapshotTimer = null;
			}
		}

		function runBatched(syncAction) {
			running = true;
			setToolbarDisabled(true);
			setProgress(0);
			setStatus('Starting run...');
			appendLog('Starting ' + syncAction + ' for ' + getUploadPath());
			startProgressPolling();
			startSnapshotPolling();

			var offset = 0;
			var batch = 0;
			var missingFiles = [];
			var schematicTotals = { examined: 0, changed: 0, unchanged: 0, skipped: 0, unresolved: 0 };

			function next() {
				batch += 1;
				if (batch > MAX_BATCHES) {
					throw new Error('Maximum batch limit exceeded.');
				}
				return post(syncAction, { offset: offset }).then(function (data) {
					var scanned = parseInt(data.scanned || 0, 10);
					var total = Math.max(scanned, parseInt(data.total || 0, 10));
					if (total > 0) {
						var complete = Math.min(total, offset + scanned);
						setProgress(complete / total);
					}

					if (data.pathway === 'schematics') {
						var changed = parseInt(data.changed || 0, 10);
						var skipped = parseInt(data.skipped || 0, 10);
						var unresolved = parseInt(data.unresolved || 0, 10);
						var unchanged = typeof data.unchanged !== 'undefined'
							? parseInt(data.unchanged || 0, 10)
							: Math.max(0, scanned - changed - skipped - unresolved);
						var synchronized = changed + unchanged;
						schematicTotals.examined += scanned;
						schematicTotals.changed += changed;
						schematicTotals.unchanged += unchanged;
						schematicTotals.skipped += skipped;
						schematicTotals.unresolved += unresolved;
						appendLog(
							'Batch ' + batch +
							' | examined ' + scanned +
							' | synchronized ' + synchronized +
							' | updated ' + changed +
							' | already current ' + unchanged +
							' | retired/skipped ' + skipped +
							' | unresolved ' + unresolved
						);
						if (data.run_id) appendLog('Run: ' + data.run_id);
					} else {
						appendLog(
							'Batch ' + batch +
							' | scanned ' + (data.scanned || 0) +
							' | registered ' + (data.registered || 0) +
							' | linked ' + (data.linked || 0) +
							' | skipped ' + (data.skipped || 0) +
							' | no_file ' + (data.no_file || 0) +
							' | errors ' + (Array.isArray(data.errors) ? data.errors.length : 0)
						);
					}

					if (data.active_csv) {
						appendLog('Active CSV: ' + data.active_csv);
					}
					if (typeof data.generate_subsizes !== 'undefined') {
						appendLog('Subsizes: ' + (data.generate_subsizes ? 'generated' : 'skipped'));
					}
					if (Array.isArray(data.errors) && data.errors.length) {
						data.errors.slice(0, 10).forEach(function (item) {
							appendLog('  error ' + item);
						});
					}
					if (Array.isArray(data.missing_files) && data.missing_files.length) {
						data.missing_files.forEach(function (item) { missingFiles.push(item); });
						data.missing_files.slice(0, 10).forEach(function (item) {
							var sku = item && item.sku ? item.sku : '(unknown sku)';
							var expected = item && Array.isArray(item.expected) ? item.expected.join(', ') : '';
							appendLog('  no_file ' + sku + ': ' + expected);
						});
					}

					readSnapshot();

					if (typeof data.next_offset === 'undefined' || data.next_offset === null) {
						if (data.pathway === 'schematics') {
							setStatus(
								'Completed. Synchronized ' + (schematicTotals.changed + schematicTotals.unchanged) +
								'; updated ' + schematicTotals.changed +
								'; already current ' + schematicTotals.unchanged +
								'; retired/skipped ' + schematicTotals.skipped +
								'; unresolved ' + schematicTotals.unresolved + '.'
							);
						} else {
							setStatus('Completed.' + (missingFiles.length ? ' Missing file samples were logged.' : ''));
						}
						setProgress(1);
						return;
					}

					offset = Math.max(offset, parseInt(data.next_offset || offset, 10));
					return next();
				});
			}

			return next().catch(function (err) {
				throw new Error(err && err.message ? err.message : 'Run failed. View System Manager for diagnostics.');
			}).finally(function () {
				running = false;
				stopProgressPolling();
				stopSnapshotPolling();
				setToolbarDisabled(false);
				refreshWorkspace();
			});
		}

		if (pathSelect) {
			pathSelect.addEventListener('change', function () {
				updatePathwayControls();
				refreshWorkspace();
			});
		}
		updatePathwayControls();

		document.addEventListener('click', function (event) {
			var refreshBtn = event.target.closest('[data-dtb-image-sync-refresh]');
			if (refreshBtn) {
				event.preventDefault();
				refreshWorkspace();
				return;
			}

			var actionBtn = event.target.closest('[data-dtb-image-sync-action]');
			if (!actionBtn || running) return;

			event.preventDefault();
			var syncAction = actionBtn.getAttribute('data-dtb-image-sync-action') || 'status';
			if (isSchematicPathway()) {
				if (syncAction !== 'sync') return;
				syncAction = 'sync_schematics';
				if (!(dryRunInput && dryRunInput.checked)) {
					schematicConfirmed = window.confirm('Register and link schematic diagrams from uploads/2026/schematics?');
					if (!schematicConfirmed) return;
				}
			}

			if (syncAction === 'release_lock') {
				setToolbarDisabled(true);
				setStatus('Releasing sync lock...');
				post('release_lock', {}).then(function (payload) {
					appendLog(payload && payload.message ? payload.message : 'Sync lock released.');
					setStatus('Sync lock released.');
					refreshWorkspace();
				}).catch(function (err) {
					setStatus(err.message || 'Release lock failed.', true);
					appendLog(err.message || 'Release lock failed.');
				}).finally(function () {
					setToolbarDisabled(false);
				});
				return;
			}

			if (syncAction === 'fix_renamed') {
				setToolbarDisabled(true);
				setStatus('Running rename repair...');
				post('fix_renamed', {}).then(function (payload) {
					appendLog('Renamed files: ' + (payload.renamed || 0));
					setStatus('Rename repair completed.');
					refreshWorkspace();
				}).catch(function () {
					setStatus('Action failed. View System Manager for diagnostics.', true);
					appendLog('fix_renamed failed.');
				}).finally(function () {
					setToolbarDisabled(false);
				});
				return;
			}

			runBatched(syncAction).catch(function (err) {
				setStatus(err.message || 'Run failed.', true);
				appendLog(err.message || 'Run failed.');
			}).finally(function () {
				schematicConfirmed = false;
			});
		});
	})();
	</script>
	<?php
	dtb_admin_shell_close();
}

/**
 * Render live workspace HTML.
 */
function dtb_image_sync_page_render_workspace_html( string $upload_path ): string {
	if ( '2026/schematics' === $upload_path ) {
		return dtb_image_sync_page_render_schematic_pathway_html();
	}
	$snapshot = dtb_build_image_sync_snapshot( $upload_path );
	$health   = is_array( $snapshot['health'] ?? null ) ? $snapshot['health'] : [];
	$catalog  = is_array( $snapshot['catalog'] ?? null ) ? $snapshot['catalog'] : [];
	$disk     = is_array( $snapshot['disk'] ?? null ) ? $snapshot['disk'] : [];
	$media    = is_array( $snapshot['media'] ?? null ) ? $snapshot['media'] : [];
	$links    = is_array( $snapshot['links'] ?? null ) ? $snapshot['links'] : [];
	$run      = is_array( $snapshot['run'] ?? null ) ? $snapshot['run'] : [];
	$reports  = is_array( $snapshot['reports'] ?? null ) ? $snapshot['reports'] : [];
	$primary_issue_count = (int) ( $links['products_missing_primary'] ?? 0 ) + (int) ( $links['variations_missing_primary'] ?? 0 );

	ob_start();
	echo '<div class="dtb-grid dtb-grid--four dtb-mb-16">';
	echo dtb_admin_ui_kpi( number_format_i18n( (int) ( $catalog['expected_skus_total'] ?? 0 ) ), __( 'Catalog SKUs', 'drywall-toolbox' ), [ 'icon' => 'dashicons-products', 'trend' => esc_html__( 'Expected source set', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_kpi( number_format_i18n( (int) ( $disk['expected_present_references'] ?? 0 ) ), __( 'Disk Coverage', 'drywall-toolbox' ), [ 'icon' => 'dashicons-images-alt2', 'trend' => sprintf( esc_html__( '%s missing', 'drywall-toolbox' ), number_format_i18n( (int) ( $disk['expected_missing_references'] ?? 0 ) ) ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_kpi( number_format_i18n( (int) ( $media['registered_attachments_total'] ?? 0 ) ), __( 'Registered Media', 'drywall-toolbox' ), [ 'icon' => 'dashicons-format-gallery', 'trend' => sprintf( esc_html__( '%s missing attachments', 'drywall-toolbox' ), number_format_i18n( (int) ( $media['expected_missing_attachments'] ?? 0 ) ) ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_kpi( number_format_i18n( (int) ( $links['products_with_correct_primary'] ?? 0 ) ), __( 'Link Integrity', 'drywall-toolbox' ), [ 'icon' => 'dashicons-admin-links', 'trend' => sprintf( esc_html__( '%s primary mismatches', 'drywall-toolbox' ), number_format_i18n( $primary_issue_count ) ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div>';

	echo '<div class="dtb-card">';
	echo '<div class="dtb-card__header">';
	echo '<h3 class="dtb-card__title">' . esc_html__( 'Snapshot Summary', 'drywall-toolbox' ) . '</h3>';
	echo '<div class="dtb-card__actions">' . dtb_admin_ui_badge( strtoupper( (string) ( $health['overall'] ?? 'warning' ) ), (string) ( $health['overall'] ?? 'warning' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div>';
	echo '<div class="dtb-card__body">';
	echo '<p><strong>' . esc_html__( 'Directory:', 'drywall-toolbox' ) . '</strong> <code>' . esc_html( (string) ( $snapshot['directory'] ?? '' ) ) . '</code></p>';
	echo '<p><strong>' . esc_html__( 'Active CSV:', 'drywall-toolbox' ) . '</strong> <code>' . esc_html( (string) ( $snapshot['active_csv'] ?? '(none)' ) ) . '</code></p>';
	echo '<p><strong>' . esc_html__( 'Last Run:', 'drywall-toolbox' ) . '</strong> ' . esc_html( (string) ( $run['last_run_at'] ?? __( 'Never', 'drywall-toolbox' ) ) ) . '</p>';
	echo '<p><strong>' . esc_html__( 'Sync Lock:', 'drywall-toolbox' ) . '</strong> ' . esc_html( ! empty( $snapshot['sync_locked'] ) ? __( 'Active', 'drywall-toolbox' ) : __( 'Idle', 'drywall-toolbox' ) ) . '</p>';
	echo '</div>';
	echo '</div>';

	echo '<div class="dtb-card dtb-mt-16">';
	echo '<div class="dtb-card__header"><h3 class="dtb-card__title">' . esc_html__( 'Current Gaps', 'drywall-toolbox' ) . '</h3></div>';
	echo '<div class="dtb-card__body">';
	echo dtb_admin_ui_table_open(
		[
			[ 'label' => __( 'Area', 'drywall-toolbox' ), 'key' => 'area' ],
			[ 'label' => __( 'Value', 'drywall-toolbox' ), 'key' => 'value' ],
			[ 'label' => __( 'Status', 'drywall-toolbox' ), 'key' => 'status' ],
		],
		[]
	); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	$rows = [
		[
			'area'   => __( 'Missing WooCommerce SKUs', 'drywall-toolbox' ),
			'value'  => (int) ( $catalog['expected_missing_wc_products'] ?? 0 ),
			'status' => ( (int) ( $catalog['expected_missing_wc_products'] ?? 0 ) ) > 0 ? 'warning' : 'success',
		],
		[
			'area'   => __( 'Missing Disk Files', 'drywall-toolbox' ),
			'value'  => (int) ( $disk['expected_missing_references'] ?? 0 ),
			'status' => ( (int) ( $disk['expected_missing_references'] ?? 0 ) ) > 0 ? 'warning' : 'success',
		],
		[
			'area'   => __( 'Missing Media Attachments', 'drywall-toolbox' ),
			'value'  => (int) ( $media['expected_missing_attachments'] ?? 0 ),
			'status' => ( (int) ( $media['expected_missing_attachments'] ?? 0 ) ) > 0 ? 'warning' : 'success',
		],
		[
			'area'   => __( 'Primary Image Mismatches', 'drywall-toolbox' ),
			'value'  => $primary_issue_count,
			'status' => $primary_issue_count > 0 ? 'warning' : 'success',
		],
	];

	foreach ( $rows as $row ) {
		echo '<tr>';
		echo '<td>' . esc_html( $row['area'] ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( (int) $row['value'] ) ) . '</td>';
		echo '<td>' . dtb_admin_ui_badge( strtoupper( $row['status'] ), $row['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</tr>';
	}
	echo dtb_admin_ui_table_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div></div>';

	$missing_disk_report = is_array( $reports['missing_disk_files'] ?? null ) ? $reports['missing_disk_files'] : [];
	$primary_report      = is_array( $reports['primary_mismatches'] ?? null ) ? $reports['primary_mismatches'] : [];
	$missing_unique      = (int) ( $reports['missing_disk_unique_count'] ?? 0 );

	echo '<div class="dtb-card dtb-mt-16">';
	echo '<div class="dtb-card__header"><h3 class="dtb-card__title">' . esc_html__( 'Missing Disk Image References', 'drywall-toolbox' ) . '</h3>';
	echo '<span class="dtb-text-muted">' . esc_html( sprintf(
		/* translators: 1: number of missing SKU-to-image references, 2: number of distinct missing filenames. */
		__( '%1$s references · %2$s unique filenames', 'drywall-toolbox' ),
		number_format_i18n( count( $missing_disk_report ) ),
		number_format_i18n( $missing_unique )
	) ) . '</span></div><div class="dtb-card__body">';
	if ( empty( $missing_disk_report ) ) {
		echo '<p>' . esc_html__( 'No catalog image references are missing from this directory.', 'drywall-toolbox' ) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'Each row identifies a catalog SKU and the exact filename that the selected upload directory does not contain. A filename shared by multiple SKUs appears once per SKU reference.', 'drywall-toolbox' ) . '</p>';
		echo dtb_admin_ui_table_open(
			[
				[ 'label' => __( 'SKU', 'drywall-toolbox' ), 'key' => 'sku' ],
				[ 'label' => __( 'Missing filename', 'drywall-toolbox' ), 'key' => 'filename' ],
			],
			[ 'wrapper_id' => 'dtb-image-sync-missing-files' ]
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $missing_disk_report as $issue ) {
			echo '<tr><td><code>' . esc_html( (string) ( $issue['sku'] ?? '' ) ) . '</code></td><td><code>' . esc_html( (string) ( $issue['filename'] ?? '' ) ) . '</code></td></tr>';
		}
		echo dtb_admin_ui_table_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div></div>';

	echo '<div class="dtb-card dtb-mt-16">';
	echo '<div class="dtb-card__header"><h3 class="dtb-card__title">' . esc_html__( 'Primary Image Mismatches', 'drywall-toolbox' ) . '</h3>';
	echo '<span class="dtb-text-muted">' . esc_html( sprintf(
		/* translators: %s: number of product or variation primary-image issues. */
		__( '%s products or variations', 'drywall-toolbox' ),
		number_format_i18n( count( $primary_report ) )
	) ) . '</span></div><div class="dtb-card__body">';
	if ( empty( $primary_report ) ) {
		echo '<p>' . esc_html__( 'All catalog products and variations with available expected media use the expected primary image.', 'drywall-toolbox' ) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'The expected image is taken from the first image in the active catalog CSV that is present in the selected directory. “Current primary file” shows the attachment currently assigned in WooCommerce.', 'drywall-toolbox' ) . '</p>';
		echo dtb_admin_ui_table_open(
			[
				[ 'label' => __( 'SKU', 'drywall-toolbox' ), 'key' => 'sku' ],
				[ 'label' => __( 'Product ID', 'drywall-toolbox' ), 'key' => 'product_id' ],
				[ 'label' => __( 'Current primary file', 'drywall-toolbox' ), 'key' => 'current_thumbnail_file' ],
				[ 'label' => __( 'Expected primary file', 'drywall-toolbox' ), 'key' => 'expected_filename' ],
				[ 'label' => __( 'Reason', 'drywall-toolbox' ), 'key' => 'reason' ],
			],
			[ 'wrapper_id' => 'dtb-image-sync-primary-mismatches' ]
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $primary_report as $issue ) {
			$reason = 'expected_image_unavailable' === ( $issue['reason'] ?? '' )
				? __( 'Expected image is missing from this directory', 'drywall-toolbox' )
				: __( 'WooCommerce primary differs from expected image', 'drywall-toolbox' );
			$current_file = (string) ( $issue['current_thumbnail_file'] ?? '' );
			if ( '' === $current_file ) {
				$current_file = (int) ( $issue['current_thumbnail_id'] ?? 0 ) > 0
					? sprintf( __( 'Attachment #%d; file unavailable', 'drywall-toolbox' ), (int) $issue['current_thumbnail_id'] )
					: __( 'No primary image assigned', 'drywall-toolbox' );
			}
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) ( $issue['sku'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $issue['product_id'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( $current_file ) . '</code></td>';
			echo '<td><code>' . esc_html( (string) ( $issue['expected_filename'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( $reason ) . '</td>';
			echo '</tr>';
		}
		echo dtb_admin_ui_table_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div></div>';

	return (string) ob_get_clean();
}

/**
 * Read-only Schematics pathway summary. All writes remain delegated to the
 * dtb-schematics operation service.
 */
function dtb_image_sync_page_render_schematic_pathway_html(): string {
	$uploads       = wp_upload_dir();
	$schematic_dir = ! empty( $uploads['basedir'] ) ? trailingslashit( (string) $uploads['basedir'] ) . '2026/schematics' : '';
	$detected      = '' !== $schematic_dir && is_dir( $schematic_dir );
	$image_count   = $detected && function_exists( 'dtb_schematics_scan_directory_image_files' ) ? count( dtb_schematics_scan_directory_image_files( $schematic_dir ) ) : 0;
	$records       = function_exists( 'dtb_schematic_record_repo_query' ) ? dtb_schematic_record_repo_query( [ 'per_page' => 1 ] ) : [ 'total' => 0 ];
	$published     = function_exists( 'dtb_schematic_record_repo_query' ) ? dtb_schematic_record_repo_query( [ 'lifecycle' => DTB_Schematic_Lifecycle_Status::PUBLISHED, 'per_page' => 1 ] ) : [ 'total' => 0 ];

	ob_start();
	echo '<div class="dtb-grid dtb-grid--four dtb-mb-16">';
	echo dtb_admin_ui_kpi( number_format_i18n( $image_count ), __( 'Source Diagrams', 'drywall-toolbox' ), [ 'icon' => 'dashicons-format-image', 'trend' => $detected ? __( 'uploads/2026/schematics detected', 'drywall-toolbox' ) : __( 'Source directory missing', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_kpi( number_format_i18n( (int) ( $records['total'] ?? 0 ) ), __( 'Schematic Records', 'drywall-toolbox' ), [ 'icon' => 'dashicons-media-document' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_kpi( number_format_i18n( (int) ( $published['total'] ?? 0 ) ), __( 'Published', 'drywall-toolbox' ), [ 'icon' => 'dashicons-visibility' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_kpi( $detected && $image_count > 0 ? __( 'Ready', 'drywall-toolbox' ) : __( 'Unavailable', 'drywall-toolbox' ), __( 'Registration Pathway', 'drywall-toolbox' ), [ 'icon' => 'dashicons-update', 'trend' => __( 'Owned by DTB Schematics', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div><div class="dtb-card"><div class="dtb-card__header"><h3 class="dtb-card__title">' . esc_html__( 'Schematic registration and linking', 'drywall-toolbox' ) . '</h3></div><div class="dtb-card__body">';
	echo '<p>' . esc_html__( 'Register and link diagrams from uploads/2026/schematics. The Schematics module creates or updates authoritative records, page attachments, hotspot references, and exact product relationships.', 'drywall-toolbox' ) . '</p>';
	echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=dtb-schematics' ) ) . '">' . esc_html__( 'Open Schematics control center', 'drywall-toolbox' ) . '</a></p></div></div>';
	return (string) ob_get_clean();
}

/**
 * Resolve default uploads path.
 */
function dtb_image_sync_page_default_path(): string {
	return defined( 'DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH' )
		? DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH
		: '2026/media';
}

/**
 * Resolve and sanitize selected uploads path.
 */
function dtb_image_sync_page_resolve_upload_path( string $upload_path ): string {
	$upload_path = trim( $upload_path );
	if ( '' === $upload_path ) {
		return dtb_image_sync_page_default_path();
	}

	if ( function_exists( 'dtb_image_sync_validate_upload_path' ) && ! dtb_image_sync_validate_upload_path( $upload_path ) ) {
		return dtb_image_sync_page_default_path();
	}
	if ( '2026/schematics' === $upload_path && ( ! function_exists( 'dtb_schematics_can_manage' ) || ! dtb_schematics_can_manage() ) ) {
		return dtb_image_sync_page_default_path();
	}

	return $upload_path;
}
