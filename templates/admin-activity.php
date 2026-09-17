<?php
/**
 * Admin: Activity tab template.
 *
 * Local variables ($activity_stats, $current_page, $activity_type, etc.) are
 * populated by Admin::render_tab_content() in the calling scope. Suppress the
 * prefix sniff for this template:
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DiluxOneOffload\ConfigManager;

// Variables populated by Admin::render_tab_content() via extract( $template_data ).
// Initialise defensively so static analysis sees a definite type and a stray
// direct include cannot crash on undefined indexes.
$activity_stats = $activity_stats ?? array();

// Read-only filter inputs for the activity-log table. The page is reachable
// only by users with manage_options; these $_GET reads do not change state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters; no state change.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- $per_page is a local template variable, not a WordPress global; the rule false-positives because WP also has a $per_page global of the same name in the admin-list-table context.
$per_page     = 50;
$current_page = isset( $_GET['paged'] ) ? max( 1, intval( wp_unslash( $_GET['paged'] ) ) ) : 1;
$offset       = ( $current_page - 1 ) * $per_page;

// Activity type filter
$activity_type = isset( $_GET['activity_type'] ) ? sanitize_text_field( wp_unslash( $_GET['activity_type'] ) ) : '';

// Date range filter
$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>

<div class="diluxone-offload-activity">
	<div class="diluxone-offload-header">
		<h2><?php esc_html_e( 'Activity Monitoring', 'diluxone-offload' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Monitor cloud storage operations and system activity logs.', 'diluxone-offload' ); ?>
		</p>
	</div>

	<?php if ( ! ConfigManager::is_configured() ) : ?>
		<!-- Configuration Required Notice -->
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Cloud Storage Configuration Required', 'diluxone-offload' ); ?></strong><br>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: URL of the Settings tab */
						__( 'Please configure your cloud storage settings in the <a href="%s">Settings tab</a> before viewing activity logs.', 'diluxone-offload' ),
						esc_url( admin_url( 'admin.php?page=diluxone-offload&tab=settings' ) )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</p>
		</div>
	<?php else : ?>

	<div class="activity-content">
	<div class="activity-filters">
		<h3><?php esc_html_e( 'Activity Filters', 'diluxone-offload' ); ?></h3>
		
		<form method="get" class="filters-form">
			<input type="hidden" name="page" value="diluxone-offload" />
			<input type="hidden" name="tab" value="activity" />
			
			<div class="filter-row">
				<div class="filter-group">
					<label for="activity_type"><?php esc_html_e( 'Activity Type:', 'diluxone-offload' ); ?></label>
					<select name="activity_type" id="activity_type">
						<option value=""><?php esc_html_e( 'All Types', 'diluxone-offload' ); ?></option>
						<option value="upload" <?php selected( $activity_type, 'upload' ); ?>>
							<?php esc_html_e( 'File Upload', 'diluxone-offload' ); ?>
						</option>
						<option value="delete" <?php selected( $activity_type, 'delete' ); ?>>
							<?php esc_html_e( 'File Delete', 'diluxone-offload' ); ?>
						</option>
						<option value="migration" <?php selected( $activity_type, 'migration' ); ?>>
							<?php esc_html_e( 'Migration', 'diluxone-offload' ); ?>
						</option>
						<option value="config" <?php selected( $activity_type, 'config' ); ?>>
							<?php esc_html_e( 'Configuration', 'diluxone-offload' ); ?>
						</option>
						<option value="error" <?php selected( $activity_type, 'error' ); ?>>
							<?php esc_html_e( 'Error', 'diluxone-offload' ); ?>
						</option>
					</select>
				</div>
				
				<div class="filter-group">
					<label for="date_from"><?php esc_html_e( 'From Date:', 'diluxone-offload' ); ?></label>
					<input type="date" name="date_from" id="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				</div>
				
				<div class="filter-group">
					<label for="date_to"><?php esc_html_e( 'To Date:', 'diluxone-offload' ); ?></label>
					<input type="date" name="date_to" id="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				</div>
				
				<div class="filter-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'diluxone-offload' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=diluxone-offload&tab=activity' ) ); ?>"
						class="button"><?php esc_html_e( 'Reset', 'diluxone-offload' ); ?></a>
				</div>
			</div>
		</form>
	</div>

	<div class="activity-statistics">
		<h3><?php esc_html_e( 'Activity Statistics', 'diluxone-offload' ); ?></h3>
		
		<div class="stats-cards">
			<div class="stat-card">
				<div class="stat-number"><?php echo esc_html( number_format( $activity_stats['total_today'] ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Activities Today', 'diluxone-offload' ); ?></div>
				<div class="stat-trend">
					<?php if ( $activity_stats['trend_today'] > 0 ) : ?>
						<span class="trend-up">
							<span class="dashicons dashicons-arrow-up-alt"></span>
							<?php echo esc_html( $activity_stats['trend_today'] ); ?>%
						</span>
					<?php elseif ( $activity_stats['trend_today'] < 0 ) : ?>
						<span class="trend-down">
							<span class="dashicons dashicons-arrow-down-alt"></span>
							<?php echo esc_html( (string) abs( $activity_stats['trend_today'] ) ); ?>%
						</span>
					<?php else : ?>
						<span class="trend-neutral">
							<span class="dashicons dashicons-minus"></span>
							0%
						</span>
					<?php endif; ?>
				</div>
			</div>

			<div class="stat-card">
				<div class="stat-number"><?php echo esc_html( number_format( $activity_stats['total_week'] ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'This Week', 'diluxone-offload' ); ?></div>
				<div class="stat-breakdown">
					<?php
					echo esc_html(
						sprintf(
						/* translators: 1: number of uploads, 2: number of deletions */
							__( '%1$d uploads, %2$d deletions', 'diluxone-offload' ),
							$activity_stats['week_uploads'],
							$activity_stats['week_deletions']
						)
					);
					?>
				</div>
			</div>

			<div class="stat-card">
				<div class="stat-number"><?php echo esc_html( number_format( $activity_stats['total_month'] ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'This Month', 'diluxone-offload' ); ?></div>
				<div class="stat-size"><?php echo esc_html( (string) size_format( $activity_stats['month_size'] ) ); ?></div>
			</div>

			<div class="stat-card">
				<div class="stat-number"><?php echo esc_html( number_format( $activity_stats['errors_count'] ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Errors (24h)', 'diluxone-offload' ); ?></div>
				<div class="stat-status <?php echo esc_attr( $activity_stats['errors_count'] > 0 ? 'has-errors' : 'no-errors' ); ?>">
					<?php
					echo esc_html(
						$activity_stats['errors_count'] > 0
						? __( 'Needs attention', 'diluxone-offload' )
						: __( 'All good', 'diluxone-offload' )
					);
					?>
				</div>
			</div>
		</div>
	</div>

	<div class="activity-actions">
		<div class="bulk-actions">
			<h3><?php esc_html_e( 'Activity Actions', 'diluxone-offload' ); ?></h3>

			<div class="action-buttons">
				<button type="button" id="refresh-activity" class="button button-secondary">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'diluxone-offload' ); ?>
				</button>
			</div>
		</div>
	</div>

	<div class="activity-log">
		<h3><?php esc_html_e( 'Recent Activity', 'diluxone-offload' ); ?></h3>
		
		<?php if ( ! empty( $activity_log ) ) : ?>
			<div class="activity-table-container">
				<table class="wp-list-table widefat fixed striped activity-table">
					<thead>
						<tr>
							<th scope="col" class="column-type"><?php esc_html_e( 'Type', 'diluxone-offload' ); ?></th>
							<th scope="col" class="column-message"><?php esc_html_e( 'Message', 'diluxone-offload' ); ?></th>
							<th scope="col" class="column-file"><?php esc_html_e( 'File', 'diluxone-offload' ); ?></th>
							<th scope="col" class="column-size"><?php esc_html_e( 'Size', 'diluxone-offload' ); ?></th>
							<th scope="col" class="column-user"><?php esc_html_e( 'User', 'diluxone-offload' ); ?></th>
							<th scope="col" class="column-date"><?php esc_html_e( 'Date', 'diluxone-offload' ); ?></th>
							<th scope="col" class="column-details"><?php esc_html_e( 'Details', 'diluxone-offload' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $activity_log as $log ) : ?>
							<tr class="activity-row activity-<?php echo esc_attr( $log['type'] ); ?>">
								<td class="column-type">
									<span class="activity-icon activity-icon-<?php echo esc_attr( $log['type'] ); ?>">
										<?php
										switch ( $log['type'] ) {
											case 'upload':
												echo '<span class="dashicons dashicons-upload"></span>';
												break;
											case 'delete':
												echo '<span class="dashicons dashicons-trash"></span>';
												break;
											case 'migration':
												echo '<span class="dashicons dashicons-cloud"></span>';
												break;
											case 'config':
												echo '<span class="dashicons dashicons-admin-settings"></span>';
												break;
											case 'error':
												echo '<span class="dashicons dashicons-warning"></span>';
												break;
											default:
												echo '<span class="dashicons dashicons-info"></span>';
										}
										?>
									</span>
									<span class="activity-type-text">
										<?php
										switch ( $log['type'] ) {
											case 'upload':
												esc_html_e( 'Upload', 'diluxone-offload' );
												break;
											case 'delete':
												esc_html_e( 'Delete', 'diluxone-offload' );
												break;
											case 'migration':
												esc_html_e( 'Migration', 'diluxone-offload' );
												break;
											case 'config':
												esc_html_e( 'Config', 'diluxone-offload' );
												break;
											case 'error':
												esc_html_e( 'Error', 'diluxone-offload' );
												break;
											default:
												esc_html_e( 'Info', 'diluxone-offload' );
										}
										?>
									</span>
								</td>
								
								<td class="column-message">
									<?php echo esc_html( $log['message'] ); ?>
									<?php if ( ! empty( $log['error_details'] ) ) : ?>
										<button type="button" class="show-error-details button-link">
											<?php esc_html_e( 'Show details', 'diluxone-offload' ); ?>
										</button>
										<div class="error-details" style="display: none;">
											<pre><?php echo esc_html( $log['error_details'] ); ?></pre>
										</div>
									<?php endif; ?>
								</td>
								
								<td class="column-file">
									<?php if ( ! empty( $log['file_path'] ) ) : ?>
										<span class="file-path" title="<?php echo esc_attr( $log['file_path'] ); ?>">
											<?php echo esc_html( basename( $log['file_path'] ) ); ?>
										</span>
										<?php if ( strlen( $log['file_path'] ) > 30 ) : ?>
											<button type="button" class="show-full-path button-link">
												<?php esc_html_e( 'Full path', 'diluxone-offload' ); ?>
											</button>
										<?php endif; ?>
									<?php else : ?>
										<span class="no-file">—</span>
									<?php endif; ?>
								</td>
								
								<td class="column-size">
									<?php if ( ! empty( $log['file_size'] ) && $log['file_size'] > 0 ) : ?>
										<?php echo esc_html( (string) size_format( $log['file_size'] ) ); ?>
									<?php else : ?>
										<span class="no-size">—</span>
									<?php endif; ?>
								</td>

								<td class="column-user">
									<?php if ( ! empty( $log['user_id'] ) ) : ?>
										<?php
										$user = get_userdata( $log['user_id'] );
										if ( $user ) {
											echo esc_html( $user->display_name );
										} else {
											/* translators: %d: user ID */
											echo esc_html( sprintf( __( 'User #%d', 'diluxone-offload' ), $log['user_id'] ) );
										}
										?>
									<?php else : ?>
										<span class="system-user"><?php esc_html_e( 'System', 'diluxone-offload' ); ?></span>
									<?php endif; ?>
								</td>

								<td class="column-date">
									<abbr title="<?php echo esc_attr( (string) mysql2date( 'c', $log['created_at'] ) ); ?>">
										<?php echo esc_html( human_time_diff( (int) strtotime( $log['created_at'] ), time() ) . ' ' . __( 'ago', 'diluxone-offload' ) ); ?>
									</abbr>
								</td>
								
								<td class="column-details">
									<?php if ( ! empty( $log['metadata'] ) ) : ?>
										<button type="button" class="show-metadata button-link">
											<?php esc_html_e( 'View', 'diluxone-offload' ); ?>
										</button>
										<div class="activity-metadata" style="display: none;">
											<?php
											$metadata = json_decode( $log['metadata'], true );
											if ( $metadata ) {
												echo '<pre>' . esc_html( (string) wp_json_encode( $metadata, JSON_PRETTY_PRINT ) ) . '</pre>';
											}
											?>
										</div>
									<?php else : ?>
										<span class="no-metadata">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php
			// Pagination
			$total_pages = (int) ceil( (int) $activity_stats['total_entries'] / $per_page );
			if ( $total_pages > 1 ) :
				?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						$page_links = paginate_links(
							array(
								'base'      => (string) add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => __( '&laquo; Previous', 'diluxone-offload' ),
								'next_text' => __( 'Next &raquo;', 'diluxone-offload' ),
								'total'     => $total_pages,
								'current'   => $current_page,
								'type'      => 'array',
							)
						);

						if ( $page_links ) {
							echo '<span class="pagination-links">';
							// paginate_links() returns a pre-escaped HTML array; safe to echo.
							echo implode( "\n", $page_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo '</span>';
						}
						?>
					</div>
				</div>
			<?php endif; ?>

		<?php else : ?>
			<div class="no-activity">
				<div class="no-activity-icon">
					<span class="dashicons dashicons-info"></span>
				</div>
				<h4><?php esc_html_e( 'No Activity Found', 'diluxone-offload' ); ?></h4>
				<p><?php esc_html_e( 'No activity matches your current filters. Try adjusting your search criteria.', 'diluxone-offload' ); ?></p>
			</div>
		<?php endif; ?>
	</div><!-- end activity-log -->

	</div> <!-- end activity-content -->
	<?php endif; ?>
</div> <!-- end diluxone-offload-activity -->
