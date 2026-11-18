<?php
/**
 * Admin — GitControlCenterTabs
 *
 * Tab-content renderers for the release-management and GitHub-repository
 * portion of the unified System Manager console (Drywall Toolbox > System
 * Manager). This file owns rendering only — the page shell, tab bar, and
 * live-region wiring belong to dtb-platform/SystemManager/SystemManagerPage.php,
 * which surfaces these functions without owning their behavior (the same
 * pattern dtb-media's admin screen already uses to surface, but not own,
 * dtb-schematics registration).
 *
 * Tabs rendered here: Deployment | Repository | Pull Requests |
 * Workflow Runs | Releases & Tags | Release History | Rollback |
 * Deploy Settings.
 *
 * Production status is read directly from the wp_dtb_release_events log
 * (reported by .github/workflows/release-siteground.yml). Repository,
 * branches, commits, pull requests, workflow runs, and releases/tags are
 * read live from the GitHub API. Deploy/rollback actions dispatch the
 * release workflow; nothing here talks to SiteGround directly and nothing
 * here writes to the repository — every GitHub call is read-only except the
 * two workflow_dispatch actions (Deployment and Rollback tabs).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// ── Tab renderers ───────────────────────────────────────────────────────────

function dtb_deployment_center_render_deployment_tab(): void {
	$overview = dtb_deployment_overview_get();
	$current  = $overview['current_production'];
	$progress = $overview['in_progress'];
	$drift    = $overview['drift'];

	echo dtb_admin_ui_kpi_grid( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		[
			'value' => $current ? substr( (string) $current['commit_sha'], 0, 7 ) : '—',
			'label' => __( 'Deployed Commit', 'drywall-toolbox' ),
			'icon'  => 'dashicons-yes-alt',
			'icon_color' => $current ? 'success' : 'neutral',
		],
		[
			'value' => $current ? dtb_time_ago( $current['updated_at'], true ) : '—',
			'label' => __( 'Deployed', 'drywall-toolbox' ),
			'icon'  => 'dashicons-clock',
		],
		[
			'value' => $drift['ok'] ? (string) $drift['ahead_by'] : '?',
			'label' => __( 'Commits Ahead on GitHub', 'drywall-toolbox' ),
			'icon'  => 'dashicons-migrate',
			'icon_color' => $drift['ok'] && $drift['ahead_by'] > 0 ? 'warning' : 'success',
		],
		[
			'value' => $progress ? dtb_release_status_label( $progress['status'] ) : __( 'Idle', 'drywall-toolbox' ),
			'label' => __( 'Release In Progress', 'drywall-toolbox' ),
			'icon'  => 'dashicons-update',
			'icon_color' => $progress ? 'processing' : 'success',
		],
	] );

	if ( $progress ) {
		ob_start();
		echo dtb_admin_ui_alert( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			sprintf(
				/* translators: 1: release id, 2: status label */
				__( 'Release %1$s is currently %2$s. This panel refreshes automatically.', 'drywall-toolbox' ),
				$progress['release_id'],
				dtb_release_status_label( $progress['status'] )
			),
			'warning',
			__( 'Release In Progress', 'drywall-toolbox' )
		);
		echo dtb_deployment_center_render_timeline( $progress['events'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$body = ob_get_clean();
		echo dtb_admin_ui_card( $body, [ 'title' => __( 'Live Release Progress', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	ob_start();
	if ( $current ) {
		echo dtb_admin_ui_detail_row( __( 'Commit', 'drywall-toolbox' ), '<code>' . esc_html( (string) $current['commit_sha'] ) . '</code>' );
		echo dtb_admin_ui_detail_row( __( 'Ref', 'drywall-toolbox' ), esc_html( (string) $current['ref'] ) );
		echo dtb_admin_ui_detail_row( __( 'Release ID', 'drywall-toolbox' ), '<code>' . esc_html( (string) $current['release_id'] ) . '</code>' );
		echo dtb_admin_ui_detail_row( __( 'Actor', 'drywall-toolbox' ), esc_html( (string) ( $current['actor'] ?: '—' ) ) );
		echo dtb_admin_ui_detail_row( __( 'Kind', 'drywall-toolbox' ), dtb_admin_ui_badge( ucfirst( (string) $current['kind'] ), 'deploy' === $current['kind'] ? 'primary' : 'info' ) );
	} else {
		echo dtb_admin_ui_empty_state( __( 'No Recorded Production Release', 'drywall-toolbox' ), __( 'Dispatch the first release below, or check that DTB_DEPLOYMENT_WEBHOOK_SECRET is configured.', 'drywall-toolbox' ) );
	}
	echo dtb_admin_ui_detail_row( __( 'GitHub Default Branch', 'drywall-toolbox' ), $drift['github_branch'] ? '<code>' . esc_html( $drift['github_branch'] ) . '@' . esc_html( substr( (string) $drift['github_sha'], 0, 7 ) ) . '</code>' : '—' );
	if ( ! $drift['ok'] && $drift['error'] ) {
		echo dtb_admin_ui_alert( esc_html( $drift['error'] ), 'info' );
	}
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Production Status', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	// Deploy action.
	ob_start();
	if ( ! $overview['github_enabled'] || ! $overview['webhook_enabled'] ) {
		echo dtb_admin_ui_alert( __( 'GitHub dispatch and/or webhook reporting is not fully configured yet. See the Deploy Settings tab.', 'drywall-toolbox' ), 'warning' );
	}
	?>
	<div class="dtb-field">
		<label class="dtb-label" for="dtb-deploy-ref"><?php esc_html_e( 'Branch, tag, or commit SHA', 'drywall-toolbox' ); ?></label>
		<?php echo dtb_admin_ui_input( 'ref', 'main', [ 'id' => 'dtb-deploy-ref', 'placeholder' => 'main' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<?php
	echo dtb_admin_ui_button( __( 'Deploy to Production', 'drywall-toolbox' ), [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'type' => 'primary',
		'id'   => 'dtb-deploy-dispatch',
		'icon' => 'dashicons-cloud-upload',
		'disabled' => (bool) $progress,
	] );
	echo '<span id="dtb-deploy-status" class="dtb-cache-status" aria-live="polite"></span>';
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'title'    => __( 'Deploy a Release', 'drywall-toolbox' ),
		'subtitle' => __( 'Runs plan, validate, backup, deploy, and verify against the official SiteGround Git repository.', 'drywall-toolbox' ),
	] );
}

function dtb_deployment_center_render_repository_tab(): void {
	$info     = dtb_git_control_center_repository_info();
	$branches = dtb_git_control_center_branches( 15 );
	$commits  = dtb_git_control_center_commits( '', 15 );

	if ( ! $info['ok'] || ! $branches['ok'] || ! $commits['ok'] ) {
		echo dtb_admin_ui_alert( esc_html( $info['error'] ?: $branches['error'] ?: $commits['error'] ?: __( 'GitHub API is unavailable.', 'drywall-toolbox' ) ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! dtb_deployment_github_enabled() ) {
			return;
		}
	}

	if ( $info['ok'] ) {
		$r = $info['data'];
		ob_start();
		echo dtb_admin_ui_detail_row( __( 'Repository', 'drywall-toolbox' ), '<a href="' . esc_url( $r['html_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $r['full_name'] ) . '</a>' );
		echo dtb_admin_ui_detail_row( __( 'Description', 'drywall-toolbox' ), esc_html( $r['description'] ?: '—' ) );
		echo dtb_admin_ui_detail_row( __( 'Default Branch', 'drywall-toolbox' ), '<code>' . esc_html( $r['default_branch'] ) . '</code>' );
		echo dtb_admin_ui_detail_row( __( 'Visibility', 'drywall-toolbox' ), dtb_admin_ui_badge( ucfirst( $r['visibility'] ), 'private' === $r['visibility'] ? 'neutral' : 'info' ) );
		echo dtb_admin_ui_detail_row( __( 'Open Issues', 'drywall-toolbox' ), esc_html( (string) $r['open_issues_count'] ) );
		echo dtb_admin_ui_detail_row( __( 'Last Push', 'drywall-toolbox' ), $r['pushed_at'] ? esc_html( dtb_time_ago( $r['pushed_at'] ) ) : '—' );
		$body = ob_get_clean();
		echo dtb_admin_ui_card( $body, [ 'title' => __( 'Repository', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	echo '<div class="dtb-grid dtb-grid--two dtb-gcc-columns">';

	ob_start();
	if ( empty( $branches['branches'] ) ) {
		echo dtb_admin_ui_empty_state( __( 'No Branches Found', 'drywall-toolbox' ) );
	} else {
		echo '<ul class="dtb-gcc-branch-list">';
		foreach ( $branches['branches'] as $b ) {
			printf(
				'<li class="dtb-gcc-branch-row"><span class="dtb-gcc-branch-name">%s%s</span><code class="dtb-gcc-sha">%s</code></li>',
				esc_html( $b['name'] ),
				$b['protected'] ? ' ' . dtb_admin_ui_badge( __( 'Protected', 'drywall-toolbox' ), 'info' ) : '',
				esc_html( substr( $b['sha'], 0, 7 ) )
			);
		}
		echo '</ul>';
	}
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Branches', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	ob_start();
	if ( empty( $commits['commits'] ) ) {
		echo dtb_admin_ui_empty_state( __( 'No Commits Found', 'drywall-toolbox' ) );
	} else {
		echo '<ul class="dtb-gcc-commit-list">';
		foreach ( $commits['commits'] as $c ) {
			printf(
				'<li class="dtb-gcc-commit-row"><code class="dtb-gcc-sha"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></code><span class="dtb-gcc-commit-message">%s</span><span class="dtb-gcc-commit-meta">%s &middot; %s</span></li>',
				esc_url( $c['html_url'] ),
				esc_html( substr( $c['sha'], 0, 7 ) ),
				esc_html( $c['message'] ),
				esc_html( $c['author'] ?: '—' ),
				$c['date'] ? esc_html( dtb_time_ago( $c['date'] ) ) : '—'
			);
		}
		echo '</ul>';
	}
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Recent Commits', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	echo '</div>';
}

function dtb_deployment_center_render_pull_requests_tab(): void {
	$open = dtb_git_control_center_pull_requests( 'open', 25 );

	if ( ! $open['ok'] ) {
		echo dtb_admin_ui_alert( esc_html( $open['error'] ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}

	if ( empty( $open['pulls'] ) ) {
		echo dtb_admin_ui_empty_state( __( 'No Open Pull Requests', 'drywall-toolbox' ), __( 'Everything on GitHub is merged or closed.', 'drywall-toolbox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}

	ob_start();
	echo dtb_admin_ui_table_open( [
		[ 'label' => __( '#', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Title', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Branch', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Author', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Status', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Updated', 'drywall-toolbox' ) ],
	], [] );
	foreach ( $open['pulls'] as $pr ) {
		echo '<tr>';
		echo '<td><code>#' . absint( $pr['number'] ) . '</code></td>';
		echo '<td><a href="' . esc_url( $pr['html_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $pr['title'] ) . '</a></td>';
		echo '<td><code class="dtb-gcc-sha">' . esc_html( $pr['head'] ) . '</code> &rarr; <code class="dtb-gcc-sha">' . esc_html( $pr['base'] ) . '</code></td>';
		echo '<td>' . esc_html( $pr['author'] ?: '—' ) . '</td>';
		echo '<td>' . dtb_admin_ui_badge( $pr['draft'] ? __( 'Draft', 'drywall-toolbox' ) : __( 'Open', 'drywall-toolbox' ), $pr['draft'] ? 'neutral' : 'success' ) . '</td>';
		echo '<td>' . ( $pr['updated_at'] ? esc_html( dtb_time_ago( $pr['updated_at'] ) ) : '—' ) . '</td>';
		echo '</tr>';
	}
	echo dtb_admin_ui_table_close();
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Open Pull Requests', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function dtb_deployment_center_render_workflow_runs_tab(): void {
	$runs = dtb_git_control_center_workflow_runs( 20 );

	if ( ! $runs['ok'] ) {
		echo dtb_admin_ui_alert( esc_html( $runs['error'] ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}

	if ( empty( $runs['runs'] ) ) {
		echo dtb_admin_ui_empty_state( __( 'No Workflow Runs Yet', 'drywall-toolbox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}

	ob_start();
	echo dtb_admin_ui_table_open( [
		[ 'label' => __( 'Workflow', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Branch', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Event', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Status', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Actor', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Started', 'drywall-toolbox' ) ],
	], [] );
	foreach ( $runs['runs'] as $run ) {
		$status_label = $run['conclusion'] ? ucfirst( $run['conclusion'] ) : ucfirst( str_replace( '_', ' ', $run['status'] ) );
		$badge_type   = 'success' === $run['conclusion'] ? 'success' : ( in_array( $run['conclusion'], [ 'failure', 'cancelled', 'timed_out' ], true ) ? 'danger' : ( in_array( $run['status'], [ 'in_progress', 'queued' ], true ) ? 'processing' : 'neutral' ) );

		echo '<tr>';
		echo '<td><a href="' . esc_url( $run['html_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $run['name'] ) . '</a></td>';
		echo '<td><code class="dtb-gcc-sha">' . esc_html( $run['branch'] ) . '</code></td>';
		echo '<td>' . esc_html( $run['event'] ) . '</td>';
		echo '<td>' . dtb_admin_ui_badge( $status_label, $badge_type ) . '</td>';
		echo '<td>' . esc_html( $run['actor'] ?: '—' ) . '</td>';
		echo '<td>' . ( $run['created_at'] ? esc_html( dtb_time_ago( $run['created_at'] ) ) : '—' ) . '</td>';
		echo '</tr>';
	}
	echo dtb_admin_ui_table_close();
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Recent Workflow Runs', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function dtb_deployment_center_render_releases_tab(): void {
	$releases = dtb_git_control_center_releases( 10 );
	$tags     = dtb_git_control_center_tags( 100 );

	if ( $releases['ok'] ) {
		ob_start();
		if ( empty( $releases['releases'] ) ) {
			echo dtb_admin_ui_empty_state( __( 'No GitHub Releases Published', 'drywall-toolbox' ) );
		} else {
			echo dtb_admin_ui_table_open( [
				[ 'label' => __( 'Tag', 'drywall-toolbox' ) ],
				[ 'label' => __( 'Name', 'drywall-toolbox' ) ],
				[ 'label' => __( 'Status', 'drywall-toolbox' ) ],
				[ 'label' => __( 'Published', 'drywall-toolbox' ) ],
			], [] );
			foreach ( $releases['releases'] as $r ) {
				echo '<tr>';
				echo '<td><a href="' . esc_url( $r['html_url'] ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $r['tag_name'] ) . '</code></a></td>';
				echo '<td>' . esc_html( $r['name'] ?: '—' ) . '</td>';
				echo '<td>' . dtb_admin_ui_badge( $r['draft'] ? __( 'Draft', 'drywall-toolbox' ) : ( $r['prerelease'] ? __( 'Pre-release', 'drywall-toolbox' ) : __( 'Published', 'drywall-toolbox' ) ), $r['draft'] ? 'neutral' : ( $r['prerelease'] ? 'warning' : 'success' ) ) . '</td>';
				echo '<td>' . ( $r['published_at'] ? esc_html( human_time_diff( strtotime( $r['published_at'] ) ) . ' ago' ) : '—' ) . '</td>';
				echo '</tr>';
			}
			echo dtb_admin_ui_table_close();
		}
		$body = ob_get_clean();
		echo dtb_admin_ui_card( $body, [ 'title' => __( 'GitHub Releases', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	if ( $tags['ok'] ) {
		echo '<div class="dtb-grid dtb-grid--two dtb-gcc-columns">';

		ob_start();
		if ( empty( $tags['release_tags'] ) ) {
			echo dtb_admin_ui_empty_state( __( 'No Release Manifests Yet', 'drywall-toolbox' ), __( 'Created automatically by the plan-and-validate stage of each deploy.', 'drywall-toolbox' ) );
		} else {
			echo '<ul class="dtb-gcc-tag-list">';
			foreach ( array_slice( $tags['release_tags'], 0, 20 ) as $t ) {
				printf( '<li class="dtb-gcc-tag-row"><code>%s</code><code class="dtb-gcc-sha">%s</code></li>', esc_html( $t['name'] ), esc_html( substr( $t['sha'], 0, 7 ) ) );
			}
			echo '</ul>';
		}
		$body = ob_get_clean();
		echo dtb_admin_ui_card( $body, [ 'title' => __( 'Release Manifests (dtb-release/*)', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		ob_start();
		if ( empty( $tags['backup_tags'] ) ) {
			echo dtb_admin_ui_empty_state( __( 'No Backup Tags Yet', 'drywall-toolbox' ), __( 'Created on the SiteGround remote before every deploy/rollback.', 'drywall-toolbox' ) );
		} else {
			echo '<ul class="dtb-gcc-tag-list">';
			foreach ( array_slice( $tags['backup_tags'], 0, 20 ) as $t ) {
				printf( '<li class="dtb-gcc-tag-row"><code>%s</code><code class="dtb-gcc-sha">%s</code></li>', esc_html( $t['name'] ), esc_html( substr( $t['sha'], 0, 7 ) ) );
			}
			echo '</ul>';
		}
		$body = ob_get_clean();
		echo dtb_admin_ui_card( $body, [ 'title' => __( 'Backup Tags (dtb-backup/*, SiteGround remote)', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div>';
	}

	if ( ! $releases['ok'] || ! $tags['ok'] ) {
		echo dtb_admin_ui_alert( esc_html( $releases['error'] ?: $tags['error'] ?: __( 'GitHub API is unavailable.', 'drywall-toolbox' ) ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

function dtb_deployment_center_render_history_tab(): void {
	$releases = dtb_release_history_get( 50 );

	if ( empty( $releases ) ) {
		echo dtb_admin_ui_empty_state( __( 'No Releases Yet', 'drywall-toolbox' ), __( 'Dispatch a release from the Deployment tab to begin building history.', 'drywall-toolbox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}

	ob_start();
	echo dtb_admin_ui_table_open( [
		[ 'label' => __( 'Release', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Kind', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Ref / Commit', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Status', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Actor', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Started', 'drywall-toolbox' ) ],
		[ 'label' => __( 'Updated', 'drywall-toolbox' ) ],
	], [] );

	foreach ( $releases as $release ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $release['release_id'] ) . '</code></td>';
		echo '<td>' . dtb_admin_ui_badge( ucfirst( (string) $release['kind'] ), 'deploy' === $release['kind'] ? 'primary' : 'info' ) . '</td>';
		echo '<td>' . esc_html( (string) $release['ref'] ) . ( $release['commit_sha'] ? ' <code>' . esc_html( substr( (string) $release['commit_sha'], 0, 7 ) ) . '</code>' : '' ) . '</td>';
		echo '<td>' . dtb_admin_ui_badge( (string) $release['status_label'], (string) $release['badge_type'] ) . '</td>';
		echo '<td>' . esc_html( (string) ( $release['actor'] ?: '—' ) ) . '</td>';
		echo '<td>' . esc_html( (string) $release['started_at'] ) . '</td>';
		echo '<td>' . esc_html( (string) $release['updated_at'] ) . '</td>';
		echo '</tr>';
	}
	echo dtb_admin_ui_table_close();
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Release History', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function dtb_deployment_center_render_rollback_tab(): void {
	$restorable_statuses = [ 'validated', 'backed_up', 'deploying', 'deployed', 'verified', 'completed' ];
	$candidates = array_values( array_filter(
		dtb_release_history_get( 50 ),
		static fn( array $r ): bool => 'deploy' === $r['kind'] && in_array( $r['status'], $restorable_statuses, true )
	) );

	if ( empty( $candidates ) ) {
		echo dtb_admin_ui_empty_state( __( 'No Restorable Releases', 'drywall-toolbox' ), __( 'A release must reach at least a validated manifest before it can be restored.', 'drywall-toolbox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}

	$options = [ '' => __( 'Select a release…', 'drywall-toolbox' ) ];
	foreach ( $candidates as $release ) {
		$options[ $release['release_id'] ] = sprintf(
			'%s — %s (%s)',
			$release['release_id'],
			$release['ref'],
			dtb_release_status_label( $release['status'] )
		);
	}

	ob_start();
	echo dtb_admin_ui_alert( __( 'Restoring a release replays its exact GitHub-tagged manifest — production is returned to that release\'s recorded state, not an ad hoc file replacement.', 'drywall-toolbox' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	<div class="dtb-field">
		<label class="dtb-label" for="dtb-rollback-release"><?php esc_html_e( 'Release to restore', 'drywall-toolbox' ); ?></label>
		<?php echo dtb_admin_ui_select( 'release_id', $options, '', [ 'id' => 'dtb-rollback-release' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<?php
	echo dtb_admin_ui_button( __( 'Restore This Release', 'drywall-toolbox' ), [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'type' => 'danger',
		'id'   => 'dtb-rollback-dispatch',
		'icon' => 'dashicons-undo',
	] );
	echo '<span id="dtb-rollback-status" class="dtb-cache-status" aria-live="polite"></span>';
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Restore a Prior Release', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function dtb_deployment_center_render_settings_tab(): void {
	$policy = [
		'owned_paths'     => dtb_deployment_owned_paths(),
		'protected_paths' => dtb_deployment_protected_paths(),
		'github_enabled'  => dtb_deployment_github_enabled(),
		'webhook_enabled' => dtb_deployment_webhook_configured(),
	];

	ob_start();
	echo dtb_admin_ui_detail_row( __( 'GitHub Dispatch & Repository Browsing (wp-admin → GitHub)', 'drywall-toolbox' ), dtb_admin_ui_badge( $policy['github_enabled'] ? __( 'Configured', 'drywall-toolbox' ) : __( 'Not Configured', 'drywall-toolbox' ), $policy['github_enabled'] ? 'success' : 'warning' ) );
	echo dtb_admin_ui_detail_row( __( 'Release Webhook (GitHub Actions → wp-admin)', 'drywall-toolbox' ), dtb_admin_ui_badge( $policy['webhook_enabled'] ? __( 'Configured', 'drywall-toolbox' ) : __( 'Not Configured', 'drywall-toolbox' ), $policy['webhook_enabled'] ? 'success' : 'warning' ) );
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Integration Status', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	ob_start();
	echo '<p><strong>' . esc_html__( 'wp-config.php constants', 'drywall-toolbox' ) . '</strong></p><ul>';
	foreach ( [ 'DTB_DEPLOYMENT_WEBHOOK_SECRET', 'DTB_GITHUB_DEPLOYMENT_TOKEN', 'DTB_GITHUB_REPO_OWNER (optional)', 'DTB_GITHUB_REPO_NAME (optional)' ] as $c ) {
		echo '<li><code>' . esc_html( $c ) . '</code></li>';
	}
	echo '</ul><p><strong>' . esc_html__( 'DTB_GITHUB_DEPLOYMENT_TOKEN required scopes', 'drywall-toolbox' ) . '</strong></p><ul>';
	foreach ( [ 'Contents: Read-only (branches, commits, tags, releases)', 'Actions: Read and write (dispatch releases, list workflow runs)', 'Pull requests: Read-only (Pull Requests tab)' ] as $s ) {
		echo '<li>' . esc_html( $s ) . '</li>';
	}
	echo '</ul><p><strong>' . esc_html__( 'GitHub Actions repository secrets', 'drywall-toolbox' ) . '</strong></p><ul>';
	foreach ( [ 'SITEGROUND_GIT_REMOTE', 'SITEGROUND_GIT_BRANCH', 'SITEGROUND_GIT_SSH_PRIVATE_KEY', 'SITEGROUND_GIT_KNOWN_HOSTS', 'DTB_DEPLOYMENT_WEBHOOK_SECRET' ] as $s ) {
		echo '<li><code>' . esc_html( $s ) . '</code></li>';
	}
	echo '</ul>';
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Required Manual Setup', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	ob_start();
	echo '<p><strong>' . esc_html__( 'Owned (writable) paths', 'drywall-toolbox' ) . '</strong></p><ul>';
	foreach ( $policy['owned_paths'] as $p ) {
		echo '<li><code>' . esc_html( $p ) . '</code></li>';
	}
	echo '</ul><p><strong>' . esc_html__( 'Protected paths (never touched)', 'drywall-toolbox' ) . '</strong></p><ul>';
	foreach ( $policy['protected_paths'] as $p ) {
		echo '<li><code>' . esc_html( $p ) . '</code></li>';
	}
	echo '</ul>';
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Release Boundary Policy', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Render an event timeline for a single release using the shared timeline widget.
 *
 * @param array<int,array<string,mixed>> $events
 */
function dtb_deployment_center_render_timeline( array $events ): string {
	$items = array_map(
		static function ( array $event ): array {
			return [
				'time'  => (string) $event['created_at'],
				'title' => dtb_release_status_label( dtb_release_status_for_events( [ (string) $event['event_type'] ] ) ),
				'desc'  => '<code>' . esc_html( (string) $event['event_type'] ) . '</code>',
				'badge' => str_contains( (string) $event['event_type'], 'failed' ) ? 'danger' : 'primary',
			];
		},
		$events
	);

	return dtb_admin_ui_timeline( $items );
}

/**
 * Inline admin script: wires the Deploy/Rollback buttons to the release
 * dispatch REST API via the shared DtbAdmin.apiFetch() helper.
 *
 * Uses event delegation on `document` rather than binding directly to the
 * buttons — the Deployment/Rollback tab content lives inside System
 * Manager's auto-refreshing live region, so the actual DOM nodes are
 * replaced on every refresh. A delegated listener keeps working across
 * every swap without needing to re-bind after each live-region update.
 */
function dtb_deployment_center_render_script(): void {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;

	// The DEPLOY/ROLLBACK confirmation tokens themselves are a fixed,
	// case-sensitive protocol shared with DeploymentActionValidator.php and
	// must stay in English; only the surrounding operator-facing copy is
	// translated here.
	$i18n = [
		'dispatching'      => __( 'Dispatching…', 'drywall-toolbox' ),
		'dispatched'       => __( 'Dispatched.', 'drywall-toolbox' ),
		'dispatchFailed'   => __( 'Dispatch failed.', 'drywall-toolbox' ),
		'enterRef'         => __( 'Enter a branch, tag, or commit SHA.', 'drywall-toolbox' ),
		/* translators: %s: branch, tag, or commit SHA to deploy */
		'confirmDeploy'    => __( 'Type DEPLOY to confirm releasing "%s" to production:', 'drywall-toolbox' ),
		'selectRelease'    => __( 'Select a release to restore.', 'drywall-toolbox' ),
		/* translators: %s: release ID to restore */
		'confirmRollback'  => __( 'Type ROLLBACK to confirm restoring "%s" to production:', 'drywall-toolbox' ),
	];
	?>
	<script>
	( function () {
		var i18n = <?php echo wp_json_encode( $i18n ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;

		function dispatchAction( button, endpoint, body, statusEl ) {
			button.disabled = true;
			if ( statusEl ) statusEl.textContent = i18n.dispatching;

			window.DtbAdmin.apiFetch( endpoint, {
				method: 'POST',
				body: JSON.stringify( body ),
			} ).then( function ( data ) {
				if ( statusEl ) statusEl.textContent = data.message || i18n.dispatched;
			} ).catch( function ( err ) {
				if ( statusEl ) statusEl.textContent = ( err && err.message ) || i18n.dispatchFailed;
			} ).finally( function () {
				button.disabled = false;
			} );
		}

		document.addEventListener( 'click', function ( e ) {
			const deployBtn = e.target.closest( '#dtb-deploy-dispatch' );
			if ( deployBtn ) {
				const refEl = document.getElementById( 'dtb-deploy-ref' );
				const ref = refEl ? refEl.value.trim() : '';
				if ( ! ref ) { window.alert( i18n.enterRef ); return; }
				const confirmText = window.prompt( i18n.confirmDeploy.replace( '%s', ref ) );
				if ( 'DEPLOY' !== confirmText ) { return; }
				dispatchAction( deployBtn, '/dtb/v1/deployment/dispatch/deploy', { ref: ref, confirm: 'DEPLOY' }, document.getElementById( 'dtb-deploy-status' ) );
				return;
			}

			const rollbackBtn = e.target.closest( '#dtb-rollback-dispatch' );
			if ( rollbackBtn ) {
				const selectEl = document.getElementById( 'dtb-rollback-release' );
				const releaseId = selectEl ? selectEl.value : '';
				if ( ! releaseId ) { window.alert( i18n.selectRelease ); return; }
				const confirmText = window.prompt( i18n.confirmRollback.replace( '%s', releaseId ) );
				if ( 'ROLLBACK' !== confirmText ) { return; }
				dispatchAction( rollbackBtn, '/dtb/v1/deployment/dispatch/rollback', { release_id: releaseId, confirm: 'ROLLBACK' }, document.getElementById( 'dtb-rollback-status' ) );
			}
		} );
	} )();
	</script>
	<?php
}
