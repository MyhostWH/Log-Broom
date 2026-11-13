<?php
/**
 * Admin page markup (Tools > Log Broom). Behaviour and styling are added by the admin script and stylesheet.
 *
 * @package Log_Broom
 *
 * @var array $data View data from PLCLEAN_Admin::get_view_data().
 */

defined( 'ABSPATH' ) || exit;

$plclean_available         = array();
$plclean_missing           = array();
$plclean_network_only      = array();
$plclean_max_count         = 0;
$plclean_has_plugin_source = false;
foreach ( $data['sources'] as $plclean_source ) {
	if ( $plclean_source['available'] ) {
		$plclean_available[] = $plclean_source;
		if ( ! $plclean_source['core'] ) {
			$plclean_has_plugin_source = true;
		}
		$plclean_max_count = max( $plclean_max_count, $plclean_source['count'] );
	} elseif ( ! empty( $plclean_source['network_only'] ) ) {
		// Detected on this site, but only a network administrator may clean it.
		$plclean_network_only[]    = $plclean_source;
		$plclean_has_plugin_source = true;
	} else {
		$plclean_missing[] = $plclean_source;
	}
}
$plclean_settings = $data['settings'];
$plclean_schedule = $plclean_settings['schedule'];
$plclean_last_run = $data['last_run'];
$plclean_has_data = $data['totals']['count'] > 0;
?>
<div class="wrap plclean-app no-js<?php echo 'off' === $plclean_schedule ? ' plclean-schedule-off' : ''; ?>" id="plclean-app">
	<h1><?php esc_html_e( 'Log Broom', 'log-broom' ); ?></h1>

	<div class="plclean-banner" id="plclean-banner">
		<span class="dashicons dashicons-warning" aria-hidden="true"></span>
		<p>
			<strong><?php esc_html_e( 'Back up your database first.', 'log-broom' ); ?></strong>
			<?php esc_html_e( "Cleaning permanently deletes data and can't be undone.", 'log-broom' ); ?>
			<a href="<?php echo esc_url( 'https://wordpress.org/documentation/article/wordpress-backups/' ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'How to back up WordPress', 'log-broom' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'log-broom' ); ?></span>
				<span class="dashicons dashicons-external" aria-hidden="true"></span>
			</a>
		</p>
	</div>

	<section class="plclean-summary" id="plclean-summary" data-count="<?php echo esc_attr( $data['totals']['count'] ); ?>" data-size="<?php echo esc_attr( $data['totals']['size'] ); ?>">
		<p class="plclean-summary-size">
			<?php
			if ( '' !== $data['totals']['size_human'] ) {
				/* translators: %s: data size, e.g. "1.4 GB". */
				echo wp_kses( sprintf( __( '%s of log data found', 'log-broom' ), '<strong class="plclean-summary-size-value">' . esc_html( $data['totals']['size_human'] ) . '</strong>' ), array( 'strong' => array( 'class' => array() ) ) );
			} else {
				echo '<strong>' . esc_html__( 'Log data', 'log-broom' ) . '</strong>';
			}
			?>
		</p>
		<p class="plclean-summary-count">
			<?php
			/* translators: %s: number of entries. */
			echo wp_kses( sprintf( _n( '%s entry', '%s entries', $data['totals']['count'], 'log-broom' ), '<strong class="plclean-summary-count-value">' . esc_html( number_format_i18n( $data['totals']['count'] ) ) . '</strong>' ), array( 'strong' => array( 'class' => array() ) ) );
			?>
		</p>
		<p class="plclean-summary-sources">
			<?php
			/* translators: %s: number of sources. */
			echo wp_kses( sprintf( _n( '%s source', '%s sources', count( $plclean_available ), 'log-broom' ), '<strong>' . esc_html( number_format_i18n( count( $plclean_available ) ) ) . '</strong>' ), array( 'strong' => array() ) );
			?>
		</p>
	</section>

	<?php if ( ! $plclean_has_data ) : ?>
		<p class="plclean-empty" id="plclean-empty">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<?php esc_html_e( 'Your database is tidy. None of the supported sources have anything to clean right now.', 'log-broom' ); ?>
		</p>
	<?php endif; ?>

	<form id="plclean-form" method="post" action="" onsubmit="return false;">
		<h2 class="screen-reader-text"><?php esc_html_e( 'Sources', 'log-broom' ); ?></h2>
		<div class="plclean-cards" id="plclean-cards">
			<?php
			foreach ( $plclean_available as $plclean_source ) :
				$plclean_pct     = PLCLEAN_Admin::bar_percent( $plclean_source['count'], $plclean_max_count );
				$plclean_clean   = 0 === $plclean_source['count'];
				$plclean_enabled = in_array( $plclean_source['id'], $plclean_settings['enabled_rules'], true );
				?>
				<div class="plclean-source<?php echo $plclean_clean ? ' is-clean' : ''; ?>" data-rule="<?php echo esc_attr( $plclean_source['id'] ); ?>">
					<label class="plclean-card" data-rule="<?php echo esc_attr( $plclean_source['id'] ); ?>" data-count="<?php echo esc_attr( $plclean_source['count'] ); ?>" data-size="<?php echo esc_attr( $plclean_source['size'] ); ?>">
						<input type="checkbox" class="plclean-card-select" name="rules[]" value="<?php echo esc_attr( $plclean_source['id'] ); ?>"<?php disabled( 0, $plclean_source['count'] ); ?> />
						<span class="plclean-card-body">
							<span class="plclean-card-title" id="<?php echo esc_attr( 'plclean-title-' . $plclean_source['id'] ); ?>"><?php echo esc_html( $plclean_source['label'] ); ?></span>
							<span class="plclean-card-desc"><?php echo esc_html( $plclean_source['description'] ); ?></span>
						</span>
						<span class="plclean-card-stats">
							<span class="plclean-card-count"><?php echo esc_html( number_format_i18n( $plclean_source['count'] ) ); ?></span>
							<?php if ( ! $plclean_clean ) : ?>
								<span class="plclean-card-size"><?php echo esc_html( '' !== $plclean_source['size_human'] ? $plclean_source['size_human'] : '—' ); ?></span>
							<?php endif; ?>
							<span class="plclean-card-clean"><?php esc_html_e( 'Nothing to clean', 'log-broom' ); ?></span>
						</span>
						<span class="plclean-bar" aria-hidden="true"><span class="plclean-bar-fill" style="width: <?php echo esc_attr( $plclean_pct ); ?>%"></span></span>
					</label>
					<div class="plclean-card-actions plclean-js-only">
						<label class="plclean-switch">
							<input type="checkbox" class="plclean-auto-toggle" name="enabled_rules[]" value="<?php echo esc_attr( $plclean_source['id'] ); ?>"<?php checked( $plclean_enabled ); ?> />
							<span><?php esc_html_e( 'Include in automatic cleaning', 'log-broom' ); ?></span>
						</label>
						<button type="button" class="button plclean-clean-one" data-rule="<?php echo esc_attr( $plclean_source['id'] ); ?>" aria-describedby="<?php echo esc_attr( 'plclean-title-' . $plclean_source['id'] ); ?>"<?php disabled( 0, $plclean_source['count'] ); ?>>
							<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							<span class="plclean-button-label"><?php esc_html_e( 'Clean…', 'log-broom' ); ?></span>
						</button>
					</div>
					<details class="plclean-details" id="<?php echo esc_attr( 'plclean-details-' . $plclean_source['id'] ); ?>">
						<summary class="plclean-details-toggle" aria-describedby="<?php echo esc_attr( 'plclean-title-' . $plclean_source['id'] ); ?>">
							<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
							<span class="plclean-details-label"><?php esc_html_e( 'Details', 'log-broom' ); ?></span>
						</summary>
						<div class="plclean-details-body">
							<div class="plclean-details-section">
								<h3 class="plclean-details-heading"><?php esc_html_e( 'Database tables', 'log-broom' ); ?></h3>
								<ul class="plclean-tables">
									<?php if ( ! empty( array_filter( wp_list_pluck( $plclean_source['tables'], 'size_human' ) ) ) ) : ?>
										<li class="plclean-tables-head">
											<span><?php esc_html_e( 'Table', 'log-broom' ); ?></span>
											<span><?php esc_html_e( 'Size on disk', 'log-broom' ); ?></span>
										</li>
									<?php endif; ?>
									<?php foreach ( $plclean_source['tables'] as $plclean_table ) : ?>
										<li>
											<code><?php echo esc_html( $plclean_table['name'] ); ?></code>
											<?php if ( '' !== $plclean_table['size_human'] ) : ?>
												<span class="plclean-table-size"><?php echo esc_html( $plclean_table['size_human'] ); ?></span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
								<p class="plclean-details-note plclean-cleaned-note"><?php echo esc_html( $plclean_source['cleaned_note'] ); ?></p>
								<?php if ( '' !== $plclean_source['tables_note'] ) : ?>
									<p class="plclean-details-note"><?php echo esc_html( $plclean_source['tables_note'] ); ?></p>
								<?php endif; ?>
							</div>
							<div class="plclean-details-section">
								<h3 class="plclean-details-heading"><?php esc_html_e( "What's deleted", 'log-broom' ); ?></h3>
								<p><?php echo esc_html( $plclean_source['description'] ); ?></p>
								<?php if ( '' !== $plclean_source['details_note'] ) : ?>
									<p class="plclean-details-note plclean-deleted-note"><?php echo esc_html( $plclean_source['details_note'] ); ?></p>
								<?php endif; ?>
							</div>
							<?php if ( '' !== $plclean_source['kept'] ) : ?>
								<div class="plclean-details-section">
									<h3 class="plclean-details-heading"><?php esc_html_e( "What's kept", 'log-broom' ); ?></h3>
									<p class="plclean-card-kept"><?php echo esc_html( $plclean_source['kept'] ); ?></p>
								</div>
							<?php endif; ?>
						</div>
					</details>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( ! $plclean_has_plugin_source ) : ?>
			<p class="plclean-empty" id="plclean-nothing-detected"><?php esc_html_e( 'None of the supported plugins were found on this site. Post revisions and expired transients can still be cleaned.', 'log-broom' ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $plclean_network_only ) ) : ?>
			<details class="plclean-undetected" id="plclean-network-only" open>
				<summary>
					<?php
					/* translators: %s: number of sources. */
					echo esc_html( sprintf( __( 'Network administrator only (%s)', 'log-broom' ), number_format_i18n( count( $plclean_network_only ) ) ) );
					?>
				</summary>
				<ul>
					<?php foreach ( $plclean_network_only as $plclean_source ) : ?>
						<li data-rule="<?php echo esc_attr( $plclean_source['id'] ); ?>">
							<strong><?php echo esc_html( $plclean_source['label'] ); ?></strong>
							<span><?php echo esc_html( PLCLEAN_Admin::network_locked_message() ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>

		<?php if ( ! empty( $plclean_missing ) ) : ?>
			<details class="plclean-undetected" id="plclean-undetected">
				<summary>
					<?php
					/* translators: %s: number of sources. */
					echo esc_html( sprintf( __( 'Not detected on this site (%s)', 'log-broom' ), number_format_i18n( count( $plclean_missing ) ) ) );
					?>
				</summary>
				<ul>
					<?php foreach ( $plclean_missing as $plclean_source ) : ?>
						<li data-rule="<?php echo esc_attr( $plclean_source['id'] ); ?>">
							<strong><?php echo esc_html( $plclean_source['label'] ); ?></strong>
							<span><?php echo esc_html( $plclean_source['description'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>

		<fieldset class="plclean-schedule" id="plclean-schedule" data-schedule="<?php echo esc_attr( $plclean_schedule ); ?>">
			<legend><span class="dashicons dashicons-clock" aria-hidden="true"></span> <?php esc_html_e( 'Automatic cleaning', 'log-broom' ); ?></legend>
			<p class="plclean-no-js-only plclean-schedule-nojs">
				<?php
				if ( 'daily' === $plclean_schedule ) {
					esc_html_e( 'Automatic cleaning: Daily', 'log-broom' );
				} elseif ( 'weekly' === $plclean_schedule ) {
					esc_html_e( 'Automatic cleaning: Weekly', 'log-broom' );
				} else {
					esc_html_e( 'Automatic cleaning: Off', 'log-broom' );
				}
				?>
			</p>
			<div class="plclean-schedule-options plclean-js-only">
				<label><input type="radio" name="schedule" value="off"<?php checked( 'off', $plclean_schedule ); ?> /> <?php esc_html_e( 'Off', 'log-broom' ); ?></label>
				<label><input type="radio" name="schedule" value="daily"<?php checked( 'daily', $plclean_schedule ); ?> /> <?php esc_html_e( 'Daily', 'log-broom' ); ?></label>
				<label><input type="radio" name="schedule" value="weekly"<?php checked( 'weekly', $plclean_schedule ); ?> /> <?php esc_html_e( 'Weekly', 'log-broom' ); ?></label>
				<label><input type="checkbox" name="optimize" value="1" aria-describedby="plclean-optimize-help"<?php checked( $plclean_settings['optimize'] ); ?> /> <?php esc_html_e( 'Optimize tables', 'log-broom' ); ?></label>
			</div>
			<p class="description plclean-js-only">
				<?php esc_html_e( 'Only the sources switched on under "Include in automatic cleaning" are cleaned.', 'log-broom' ); ?>
			</p>
			<p class="description plclean-js-only" id="plclean-optimize-help">
				<?php esc_html_e( 'Optimize tables only rebuilds tables where entries were removed. Automatic cleaning never rebuilds WordPress core tables.', 'log-broom' ); ?>
			</p>
			<p class="plclean-schedule-status" id="plclean-schedule-status">
				<?php
				if ( 'off' !== $plclean_schedule ) {
					if ( 'daily' === $plclean_schedule ) {
						esc_html_e( 'Automatic cleaning is on. It runs daily.', 'log-broom' );
					} else {
						esc_html_e( 'Automatic cleaning is on. It runs weekly.', 'log-broom' );
					}
					if ( $plclean_last_run ) {
						/* translators: 1: date, 2: number of entries removed. */
						echo ' ' . esc_html( sprintf( _n( 'Last run: %1$s, removed %2$s entry.', 'Last run: %1$s, removed %2$s entries.', $plclean_last_run['deleted'], 'log-broom' ), wp_date( get_option( 'date_format' ), $plclean_last_run['time'] ), number_format_i18n( $plclean_last_run['deleted'] ) ) );
					}
				}
				?>
			</p>
		</fieldset>

		<details class="plclean-advanced plclean-js-only" id="plclean-advanced">
			<summary><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> <?php esc_html_e( 'Advanced', 'log-broom' ); ?></summary>
			<fieldset class="plclean-batch" id="plclean-batch" data-mode="<?php echo esc_attr( $plclean_settings['batch_mode'] ); ?>" data-size="<?php echo esc_attr( $plclean_settings['batch_size'] ); ?>">
				<legend><?php esc_html_e( 'Entries per batch', 'log-broom' ); ?></legend>
				<div class="plclean-batch-options">
					<label><input type="radio" name="batch_mode" value="auto"<?php checked( 'auto', $plclean_settings['batch_mode'] ); ?> aria-describedby="plclean-batch-help" /> <?php esc_html_e( 'Automatic (recommended)', 'log-broom' ); ?></label>
					<span class="plclean-batch-fixed">
						<label><input type="radio" name="batch_mode" value="fixed"<?php checked( 'fixed', $plclean_settings['batch_mode'] ); ?> aria-describedby="plclean-batch-help" /> <?php esc_html_e( 'Fixed:', 'log-broom' ); ?></label>
						<label class="screen-reader-text" for="plclean-batch-size"><?php esc_html_e( 'Fixed number of entries per batch', 'log-broom' ); ?></label>
						<input type="number" id="plclean-batch-size" name="batch_size" class="small-text" inputmode="numeric" min="1000" max="100000" step="1000" value="<?php echo esc_attr( $plclean_settings['batch_size'] ); ?>" aria-describedby="plclean-batch-error plclean-batch-help"<?php disabled( 'auto', $plclean_settings['batch_mode'] ); ?> />
					</span>
				</div>
				<p class="plclean-field-error" id="plclean-batch-error" hidden><?php esc_html_e( 'Use a number from 1,000 to 100,000. Other values are adjusted to fit.', 'log-broom' ); ?></p>
				<p class="description" id="plclean-batch-help"><?php esc_html_e( 'Larger batches clean faster but can time out on slower hosting. Applies to plugin log tables; post revisions and transients always use safe sizes.', 'log-broom' ); ?></p>
			</fieldset>
		</details>

		<div class="plclean-actionbar plclean-js-only" id="plclean-actionbar">
			<span class="plclean-selection" id="plclean-selection">
				<?php
				// The script rewrites this live; the server renders the empty selection (count 0).
				/* translators: 1: number of selected sources, 2: number of entries. */
				echo esc_html( sprintf( _n( '%1$s selected · %2$s entry', '%1$s selected · %2$s entries', 0, 'log-broom' ), '0', '0' ) );
				?>
			</span>
			<button type="button" class="button button-primary" id="plclean-clean-selected" disabled>
				<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				<span class="plclean-button-label"><?php esc_html_e( 'Clean selected…', 'log-broom' ); ?></span>
			</button>
		</div>
	</form>

	<dialog id="plclean-dialog" class="plclean-dialog" aria-labelledby="plclean-dialog-title"></dialog>

	<div id="plclean-live" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>
	<div id="plclean-toast" class="plclean-toast" role="status" hidden></div>
</div>
