<?php
/**
 * Admin Page Template
 *
 * Main admin interface for Royal Backup & Reset plugin.
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php if ( is_multisite() ) : ?>
<div class="notice notice-warning royalbr-multisite-notice" style="margin: 10px 20px 20px 0;">
    <p><strong><?php esc_html_e( 'Multisite Not Supported Yet', 'royal-backup-reset' ); ?></strong></p>
    <p><?php esc_html_e( 'This plugin does not support WordPress Multisite yet, but it is coming soon!', 'royal-backup-reset' ); ?></p>
</div>
<?php endif; ?>

<div class="wrap royalbr-wrap">
    <h1 class="royalbr-main-title"><?php echo esc_html('Royal Backup, Restore & Reset'); ?></h1>

    <div id="royalbr-admin-notices"></div>

    <div class="royalbr-tabs-wrapper">
        <nav class="royalbr-nav-tabs">
            <a href="#backup-website" class="royalbr-nav-tab royalbr-nav-tab-active" data-tab="backup-website">
                <?php esc_html_e('Create Backup', 'royal-backup-reset'); ?>
            </a>
            <a href="#restore-website" class="royalbr-nav-tab" data-tab="restore-website">
                <?php esc_html_e('Restore Site', 'royal-backup-reset'); ?>
            </a>
            <a href="#migration" class="royalbr-nav-tab" data-tab="migration">
                <?php esc_html_e('Migration', 'royal-backup-reset'); ?>
            </a>
            <a href="#reset-database" class="royalbr-nav-tab" data-tab="reset-database">
                <?php esc_html_e('Database Reset', 'royal-backup-reset'); ?>
            </a>
            <a href="#settings" class="royalbr-nav-tab" data-tab="settings">
                <?php esc_html_e('Configuration', 'royal-backup-reset'); ?>
            </a>
            <?php if ( ! ( function_exists( 'royalbr_fs' ) && royalbr_fs()->can_use_premium_code() ) ) : ?>
            <a href="#free-vs-pro" class="royalbr-nav-tab royalbr-nav-tab-premium" data-tab="free-vs-pro">
                <span class="dashicons dashicons-star-filled"></span> <?php esc_html_e('Free vs Pro', 'royal-backup-reset'); ?>
            </a>
            <?php endif; ?>
        </nav>

        <!-- Backup Website Tab -->
        <div id="backup-website" class="royalbr-tab-content royalbr-tab-active">
            <div class="royalbr-card">
                <h2><?php esc_html_e('Create Full Site Backup', 'royal-backup-reset'); ?></h2>
                <p class="royalbr-description"><?php esc_html_e('Generate a full backup of your site including database and files. Backups are saved in the', 'royal-backup-reset'); ?> <code>wp-content/royal-backup-reset</code> <?php esc_html_e('directory.', 'royal-backup-reset'); ?></p>

                <div class="royalbr-backup-options">
                    <?php
					$is_premium = function_exists( 'royalbr_fs' ) && royalbr_fs()->can_use_premium_code();
					$db_disabled_class = $is_premium ? '' : 'royalbr-pro-option-disabled';
					?>
                    <div class="royalbr-checkbox-card <?php echo esc_attr( $db_disabled_class ); ?>" <?php if ( ! $is_premium ) : ?>data-pro-option-name="<?php esc_attr_e( 'Database Content', 'royal-backup-reset' ); ?>"<?php endif; ?>>
                        <label>
                            <input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-backup-database" checked <?php echo $is_premium ? '' : 'disabled'; ?>>
                            <span class="royalbr-checkbox-content">
                                <span class="royalbr-checkbox-title">
									<?php esc_html_e( 'Database Content', 'royal-backup-reset' ); ?>
								</span>
                                <span class="royalbr-checkbox-label"><?php esc_html_e('(your posts, pages, users and settings)', 'royal-backup-reset'); ?></span>
                            </span>
                        </label>
                    </div>
                    <div class="royalbr-checkbox-card">
                        <label>
                            <input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-backup-files" checked>
                            <span class="royalbr-checkbox-content">
                                <span class="royalbr-checkbox-title">
									<?php esc_html_e( 'Include Site Files', 'royal-backup-reset' ); ?>
								</span>
                                <span class="royalbr-checkbox-label"><?php esc_html_e('(themes, plugins, images and uploads )', 'royal-backup-reset'); ?></span>
                            </span>
                        </label>
                    </div>
                    <div class="royalbr-checkbox-card <?php echo esc_attr( $db_disabled_class ); ?>" <?php if ( ! $is_premium ) : ?>data-pro-option-name="<?php esc_attr_e( 'WordPress Core', 'royal-backup-reset' ); ?>"<?php endif; ?>>
                        <label>
                            <input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-backup-wpcore" <?php echo $is_premium ? '' : 'disabled'; ?>>
                            <span class="royalbr-checkbox-content">
                                <span class="royalbr-checkbox-title">
									<?php esc_html_e( 'WordPress Core Files', 'royal-backup-reset' ); ?>
									<?php if ( ! $is_premium ) : ?>
										<a href="#" class="royalbr-pro-badge"><?php esc_html_e( 'PRO', 'royal-backup-reset' ); ?></a>
									<?php endif; ?>
								</span>
                                <span class="royalbr-checkbox-label"><?php echo wp_kses( __( '(Backup WordPress core files to quickly restore them if they are altered by <span style="color:#b8860b;">virus, hackers, or security incidents.</span>)', 'royal-backup-reset' ), array( 'span' => array( 'style' => array() ) ) ); ?></span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="royalbr-backup-actions">
                    <button type="button" id="royalbr-create-backup" class="royalbr-button-primary">
                        <?php esc_html_e('Start Backup Process', 'royal-backup-reset'); ?>
                    </button>
                </div>

                <div id="royalbr-backup-progress" class="royalbr-progress-wrapper" style="display: none;">
                    <div class="royalbr-progress-bar">
                        <div class="royalbr-progress-fill"></div>
                    </div>
                    <div class="royalbr-progress-text">
                        <?php esc_html_e('Initializing backup process...', 'royal-backup-reset'); ?>
                    </div>

                    <!-- Error Message Container (hidden by default, shown on error) -->
                    <div class="royalbr-backup-error-message" style="display: none;">
                        <span class="dashicons dashicons-warning"></span>
                        <div class="royalbr-error-content">
                            <strong class="royalbr-error-title"><?php esc_html_e('Backup Failed', 'royal-backup-reset'); ?></strong>
                            <p class="royalbr-error-text"></p>
                        </div>
                    </div>

                    <?php if ( function_exists( 'royalbr_fs' ) && ! royalbr_fs()->can_use_premium_code() ) : ?>
                        <a id="royalbr-pro-promo-text" class="royalbr-pro-promo-text" href="https://royal-elementor-addons.com/royal-backup-reset/?ref=rbr-backend-menu-modal-pro#purchasepro" target="_blank" style="display: none;"></a>
                    <?php endif; ?>
                    <p class="royalbr-background-note" style="text-align: center; color: #6e6e73; margin: 15px 0 10px;"><?php esc_html_e('Backups run in the background. You can continue working while the backup is being created.', 'royal-backup-reset'); ?></p>
                    <div class="royalbr-progress-actions">
                        <a href="#" id="royalbr-show-log" class="royalbr-button-secondary" style="display: none;"><?php esc_html_e('View Log', 'royal-backup-reset'); ?></a>
                        <a href="#" id="royalbr-stop-backup" class="royalbr-button-primary" style="display: none;" title="<?php esc_attr_e('Note: Progress tracking is stage-based. Only stop if you encounter an actual issue.', 'royal-backup-reset'); ?>"><?php esc_html_e('Stop Backup', 'royal-backup-reset'); ?></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Restore Website Tab -->
        <div id="restore-website" class="royalbr-tab-content">
            <div class="royalbr-card">
                <h2><?php esc_html_e('Saved Backups', 'royal-backup-reset'); ?></h2>
                <p class="royalbr-description"><?php esc_html_e('Choose a backup to restore your site to a previous point in time. This operation will replace your current site data.', 'royal-backup-reset'); ?></p>

                <div id="royalbr-backup-list">
                    <?php $this->display_backup_table(); ?>
                </div>
            </div>

        </div>

        <!-- Migration Tab -->
        <div id="migration" class="royalbr-tab-content">
            <div class="royalbr-card royalbr-migration-card">
                <h2><?php esc_html_e( 'Migration', 'royal-backup-reset' ); ?>
                    <a href="https://www.youtube.com/watch?v=-PR0DspR6BY" target="_blank" class="royalbr-video-tutorial-btn">
                        <span class="dashicons dashicons-video-alt3"></span> <?php esc_html_e( 'Video Tutorial', 'royal-backup-reset' ); ?>
                    </a>
                </h2>
                <p class="royalbr-description"><?php esc_html_e( 'Move your WordPress site from one location to another in 4 simple steps:', 'royal-backup-reset' ); ?></p>
                <p class="royalbr-description" style="margin-left: 1em;">
                    <?php
                    printf(
                        /* translators: 1: opening bold tag, 2: closing bold tag */
                        esc_html__( '1. Create a full backup on %1$sSite A%2$s (your current/old site) using the Create Backup tab.', 'royal-backup-reset' ),
                        '<strong>',
                        '</strong>'
                    );
                    ?>
                    <br>
                    <?php
                    printf(
                        /* translators: 1: opening bold tag, 2: closing bold tag */
                        esc_html__( '2. Download all backup components from %1$sSite A%2$s (Database, Plugins, Themes, Uploads, etc.).', 'royal-backup-reset' ),
                        '<strong>',
                        '</strong>'
                    );
                    ?>
                    <br>
                    <?php
                    printf(
                        /* translators: 1: opening bold tag, 2: closing bold tag */
                        esc_html__( '3. Install this plugin on %1$sSite B%2$s (your new site), then upload the backup files here.', 'royal-backup-reset' ),
                        '<strong>',
                        '</strong>'
                    );
                    ?>
                    <br>
                    <?php
                    printf(
                        /* translators: 1: opening bold tag, 2: closing bold tag */
                        esc_html__( '4. Click Restore on %1$sSite B%2$s — all URLs and database references are updated automatically.', 'royal-backup-reset' ),
                        '<strong>',
                        '</strong>'
                    );
                    ?>
                </p>

                <div class="royalbr-rescan-remote-wrapper">
                    <?php if ( $is_premium ) : ?>
                    <button type="button" id="royalbr-rescan-remote-btn" class="royalbr-button-secondary royalbr-rescan-remote-btn">
                        <span class="dashicons dashicons-cloud"></span>
                        <?php esc_html_e( 'Scan Remote Storage', 'royal-backup-reset' ); ?>
                    </button>
                    <?php endif; ?>
                    <button type="button" id="royalbr-upload-backup-btn" class="royalbr-button-secondary royalbr-rescan-remote-btn">
                        <span class="dashicons dashicons-upload"></span>
                        <?php esc_html_e( 'Upload Backup Files', 'royal-backup-reset' ); ?>
                    </button>
                    <input type="file" id="royalbr-upload-backup-input" multiple accept=".zip,.gz,.sql,.tar" style="display:none;" />
                    <span id="royalbr-rescan-remote-status" class="royalbr-rescan-status"></span>
                </div>

                <?php if ( ! $is_premium ) : ?>
                <p class="royalbr-description royalbr-migration-limit-note">
                    <?php esc_html_e( 'Free version supports backups up to 200 MB.', 'royal-backup-reset' ); ?>
                    <a href="#" class="royalbr-pro-badge" data-pro-option-name="<?php esc_attr_e( 'Migration above 200 MB', 'royal-backup-reset' ); ?>"><?php esc_html_e( 'PRO', 'royal-backup-reset' ); ?></a>
                </p>
                <?php endif; ?>

                <div id="royalbr-migration-list">
                    <?php $this->display_migration_table(); ?>
                </div>
            </div>
        </div>

        <!-- Reset Database Tab -->
        <div id="reset-database" class="royalbr-tab-content">
            <div class="royalbr-card">
                <h2><?php esc_html_e('Database Reset Tool', 'royal-backup-reset'); ?></h2>

                <?php
                // Get current theme and plugins info from main class
                global $royalbr_instance;
                if (isset($royalbr_instance)) {
                    $royalbr_reset_info = $royalbr_instance->get_reset_info();
                    $royalbr_current_theme_name = $royalbr_reset_info['theme_name'];
                    $royalbr_active_plugins = $royalbr_reset_info['active_plugins'];
                } else {
                    $royalbr_current_theme_name = 'Unknown Theme';
                    $royalbr_active_plugins = array();
                }
                ?>

                <div class="royalbr-reset-options">
                    <p class="royalbr-description"><?php esc_html_e('Choose which items to restore and what to clear after resetting:', 'royal-backup-reset'); ?></p>

                    <div class="royalbr-checkbox-card">
                        <label>
                            <input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-reactivate-theme" name="reactivate_theme" value="1">
                            <span class="royalbr-checkbox-content">
                                <span class="royalbr-checkbox-title">
                                    <?php
									echo sprintf(
										/* translators: %s: Active theme name */
										esc_html__( 'Restore Current Theme - %s', 'royal-backup-reset' ),
										'<strong>' . esc_html( $royalbr_current_theme_name ) . '</strong>'
									);
									?>
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="royalbr-checkbox-card">
                        <label>
                            <input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-reactivate-plugins" name="reactivate_plugins" value="1">
                            <span class="royalbr-checkbox-content">
                                <span class="royalbr-checkbox-title"><?php esc_html_e('Reactivate currently Active Plugins', 'royal-backup-reset'); ?></span>
                            </span>
                        </label>
                    </div>

                    <div class="royalbr-checkbox-card">
                        <label>
                            <input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-keep-royalbr-active" name="keep_royalbr_active" value="1" checked>
                            <span class="royalbr-checkbox-content">
                                <span class="royalbr-checkbox-title"><?php esc_html_e('Keep Royal Backup & Reset active', 'royal-backup-reset'); ?></span>
                            </span>
                        </label>
                    </div>

                    <div class="royalbr-checkbox-card">
                        <label>
                            <input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-clear-media" name="clear_media" value="1">
                            <span class="royalbr-checkbox-content">
                                <span class="royalbr-checkbox-title"><?php esc_html_e('Clear Media Files', 'royal-backup-reset'); ?></span>
                                <span class="royalbr-checkbox-label"><?php esc_html_e('(removes year/month media(images, videos, etc...) folders only)', 'royal-backup-reset'); ?></span>
                            </span>
                        </label>
                    </div>

					<?php
					$is_premium_reset = function_exists( 'royalbr_fs' ) && royalbr_fs()->can_use_premium_code();
					$uploads_disabled_class = $is_premium_reset ? '' : 'royalbr-pro-option-disabled';
					?>
                    <div class="royalbr-checkbox-card <?php echo esc_attr( $uploads_disabled_class ); ?>" <?php if ( ! $is_premium_reset ) : ?>data-pro-option-name="<?php esc_attr_e( 'Clear Uploads Directory', 'royal-backup-reset' ); ?>"<?php endif; ?>>
                        <label>
                            <input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-clear-uploads" name="clear_uploads" value="1" <?php echo $is_premium_reset ? '' : 'disabled'; ?>>
                            <span class="royalbr-checkbox-content">
                                <span class="royalbr-checkbox-title">
									<?php esc_html_e('Clear Uploads Directory', 'royal-backup-reset'); ?>
									<?php if ( ! $is_premium_reset ) : ?>
										<a href="#" class="royalbr-pro-badge"><?php esc_html_e( 'PRO', 'royal-backup-reset' ); ?></a>
									<?php endif; ?>
								</span>
                                <span class="royalbr-checkbox-label"><?php esc_html_e('(removes all files in wp-content/uploads)', 'royal-backup-reset'); ?></span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="royalbr-reset-confirmation">
                    <h3><?php esc_html_e('Safety Confirmation', 'royal-backup-reset'); ?></h3>
                    <div class="royalbr-checkbox-card royalbr-checkbox-warning">
                        <label>
                            <input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-confirm-reset" name="confirm_reset" value="1">
                            <span class="royalbr-checkbox-content">
                                <span class="royalbr-checkbox-title"><?php esc_html_e('I acknowledge this is permanent and will erase all my site content', 'royal-backup-reset'); ?></span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="royalbr-reset-actions">
                    <button type="button" id="royalbr-reset-database" class="royalbr-button-danger" disabled>
                        <?php esc_html_e('Perform Database Reset', 'royal-backup-reset'); ?>
                    </button>
                </div>

                <div id="royalbr-reset-progress" class="royalbr-progress-wrapper" style="display: none;">
                    <div class="royalbr-progress-bar">
                        <div class="royalbr-progress-fill"></div>
                    </div>
                    <div class="royalbr-progress-text">
                        <?php esc_html_e('Processing database reset...', 'royal-backup-reset'); ?>
                    </div>
                    <div class="royalbr-progress-actions">
                        <a href="#" id="royalbr-show-reset-log" class="royalbr-button-secondary" style="display: none;"><?php esc_html_e('View Log', 'royal-backup-reset'); ?></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Tab -->
        <div id="settings" class="royalbr-tab-content">
            <div class="royalbr-card">
                <h2><?php esc_html_e('Configuration Options', 'royal-backup-reset'); ?></h2>
                <p class="royalbr-description"><?php esc_html_e('Set default preferences for backup, restore, and reset actions. Your choices here will be automatically applied when you access each feature.', 'royal-backup-reset'); ?></p>

                <form id="royalbr-settings-form" method="post">
                    <?php settings_fields('royalbr-options-group'); ?>

                    <?php
                    // Allow premium features to inject settings before backup preferences.
                    do_action( 'royalbr_before_backup_settings' );
                    ?>

                    <?php
                    // Show disabled scheduled backups for free users (premium users see this via hook above).
                    $is_premium_schedule = function_exists( 'royalbr_fs' ) && royalbr_fs()->can_use_premium_code();
                    if ( ! $is_premium_schedule ) :
                    ?>
                    <div class="royalbr-schedule-section royalbr-schedule-disabled royalbr-pro-option-disabled" data-pro-option-name="<?php esc_attr_e( 'Scheduled Backups', 'royal-backup-reset' ); ?>">
                        <div class="royalbr-schedule-columns">
                            <div class="royalbr-schedule-column">
                                <h3>
                                    <?php esc_html_e( 'Scheduled Files Backup', 'royal-backup-reset' ); ?>
                                    <a href="#" class="royalbr-pro-badge"><?php esc_html_e( 'PRO', 'royal-backup-reset' ); ?></a>
                                </h3>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Files Backup Schedule', 'royal-backup-reset' ); ?></label></th>
                                        <td>
                                            <select disabled>
                                                <option><?php esc_html_e( 'Manual only', 'royal-backup-reset' ); ?></option>
                                            </select>
                                            <p class="description"><?php esc_html_e( 'How often to automatically backup files (plugins, themes, uploads, others)', 'royal-backup-reset' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Keep File Backups', 'royal-backup-reset' ); ?></label></th>
                                        <td>
                                            <input type="number" min="1" value="2" class="small-text" disabled>
                                            <p class="description"><?php esc_html_e( 'Number of file backups to retain (older backups will be automatically deleted)', 'royal-backup-reset' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Next Scheduled', 'royal-backup-reset' ); ?></th>
                                        <td>
                                            <strong><?php esc_html_e( 'No backup scheduled', 'royal-backup-reset' ); ?></strong>
                                            <p class="description"><?php esc_html_e( 'The next automatic files backup will run at this time', 'royal-backup-reset' ); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="royalbr-schedule-column">
                                <h3>
                                    <?php esc_html_e( 'Scheduled Database Backup', 'royal-backup-reset' ); ?>
                                    <a href="#" class="royalbr-pro-badge"><?php esc_html_e( 'PRO', 'royal-backup-reset' ); ?></a>
                                </h3>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Database Backup Schedule', 'royal-backup-reset' ); ?></label></th>
                                        <td>
                                            <select disabled>
                                                <option><?php esc_html_e( 'Manual only', 'royal-backup-reset' ); ?></option>
                                            </select>
                                            <p class="description"><?php esc_html_e( 'How often to automatically backup database', 'royal-backup-reset' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Keep Database Backups', 'royal-backup-reset' ); ?></label></th>
                                        <td>
                                            <input type="number" min="1" value="2" class="small-text" disabled>
                                            <p class="description"><?php esc_html_e( 'Number of database backups to retain (older backups will be automatically deleted)', 'royal-backup-reset' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Next Scheduled', 'royal-backup-reset' ); ?></th>
                                        <td>
                                            <strong><?php esc_html_e( 'No backup scheduled', 'royal-backup-reset' ); ?></strong>
                                            <p class="description"><?php esc_html_e( 'The next automatic database backup will run at this time', 'royal-backup-reset' ); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php
                    // Show disabled backup locations for free users (premium users see this via hook above).
                    $is_premium_locations = function_exists( 'royalbr_fs' ) && royalbr_fs()->can_use_premium_code();
                    if ( ! $is_premium_locations ) :
                    ?>
                    <h3>
                        <?php esc_html_e( 'Backup Locations', 'royal-backup-reset' ); ?>
                        <a href="#" class="royalbr-pro-badge"><?php esc_html_e( 'PRO', 'royal-backup-reset' ); ?></a>
                    </h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Storage Destinations', 'royal-backup-reset' ); ?></th>
                            <td>
                                <fieldset class="royalbr-pro-option-disabled" data-pro-option-name="<?php esc_attr_e( 'Backup Locations', 'royal-backup-reset' ); ?>">
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" value="1" checked disabled>
                                        <?php esc_html_e( 'Local Storage', 'royal-backup-reset' ); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" value="1" disabled>
                                        <?php esc_html_e( 'Google Drive', 'royal-backup-reset' ); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" value="1" disabled>
                                        <?php esc_html_e( 'Dropbox', 'royal-backup-reset' ); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" value="1" disabled>
                                        <?php esc_html_e( 'Amazon S3', 'royal-backup-reset' ); ?>
                                    </label>
                                </fieldset>
                                <p class="description"><?php esc_html_e( 'Select where to store your backups.', 'royal-backup-reset' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php endif; ?>

                    <!-- Backup Defaults -->
                    <?php
					$is_premium_settings = function_exists( 'royalbr_fs' ) && royalbr_fs()->can_use_premium_code();
					?>
                    <h3>
                        <?php esc_html_e('Backup Preferences', 'royal-backup-reset'); ?>
                        <?php if ( ! $is_premium_settings ) : ?>
                            <a href="#" class="royalbr-pro-badge"><?php esc_html_e( 'PRO', 'royal-backup-reset' ); ?></a>
                        <?php endif; ?>
                    </h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Default Backup Content', 'royal-backup-reset'); ?></th>
                            <td>
                                <label class="<?php echo $is_premium_settings ? '' : 'royalbr-pro-option-disabled'; ?>" <?php if ( ! $is_premium_settings ) : ?>data-pro-option-name="<?php esc_attr_e( 'Backup Preferences', 'royal-backup-reset' ); ?>"<?php endif; ?>>
                                    <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_backup_include_db" value="1" <?php checked(ROYALBR_Options::get_royalbr_option('royalbr_backup_include_db', true)); ?> <?php echo $is_premium_settings ? '' : 'disabled'; ?>>
                                    <?php esc_html_e('Include database content (your posts, pages, users and settings) by default', 'royal-backup-reset'); ?>
                                </label>
                                <br>
                                <label class="<?php echo $is_premium_settings ? '' : 'royalbr-pro-option-disabled'; ?>" <?php if ( ! $is_premium_settings ) : ?>data-pro-option-name="<?php esc_attr_e( 'Backup Preferences', 'royal-backup-reset' ); ?>"<?php endif; ?>>
                                    <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_backup_include_files" value="1" <?php checked(ROYALBR_Options::get_royalbr_option('royalbr_backup_include_files', true)); ?> <?php echo $is_premium_settings ? '' : 'disabled'; ?>>
                                    <?php esc_html_e('Include site files (plugins, themes, uploads, others) by default', 'royal-backup-reset'); ?>
                                </label>
                                <br>
                                <label class="<?php echo $is_premium_settings ? '' : 'royalbr-pro-option-disabled'; ?>" <?php if ( ! $is_premium_settings ) : ?>data-pro-option-name="<?php esc_attr_e( 'Backup Preferences', 'royal-backup-reset' ); ?>"<?php endif; ?>>
                                    <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_backup_include_wpcore" value="1" <?php checked(ROYALBR_Options::get_royalbr_option('royalbr_backup_include_wpcore', false)); ?> <?php echo $is_premium_settings ? '' : 'disabled'; ?>>
                                    <?php esc_html_e('Include WordPress core (wp-admin, wp-includes and root files) by default', 'royal-backup-reset'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('These options will be pre-selected when creating a new backup.', 'royal-backup-reset'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <?php
                    // Allow premium features to inject additional settings sections.
                    do_action( 'royalbr_after_backup_settings' );
                    ?>

                    <!-- Restore Defaults -->
                    <h3>
						<?php esc_html_e('Restore Preferences', 'royal-backup-reset'); ?>
						<?php if ( ! $is_premium_settings ) : ?>
							<a href="#" class="royalbr-pro-badge"><?php esc_html_e( 'PRO', 'royal-backup-reset' ); ?></a>
						<?php endif; ?>
					</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Items to Restore', 'royal-backup-reset'); ?></th>
                            <td>
                                <fieldset class="<?php echo $is_premium_settings ? '' : 'royalbr-pro-option-disabled'; ?>" <?php if ( ! $is_premium_settings ) : ?>data-pro-option-name="<?php esc_attr_e( 'Restore Preferences', 'royal-backup-reset' ); ?>"<?php endif; ?>>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_restore_db" value="1" <?php echo $is_premium_settings ? checked(ROYALBR_Options::get_royalbr_option('royalbr_restore_db', true), true, false) : 'checked disabled'; ?>>
                                        <?php esc_html_e('Database Content', 'royal-backup-reset'); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_restore_plugins" value="1" <?php echo $is_premium_settings ? checked(ROYALBR_Options::get_royalbr_option('royalbr_restore_plugins', false), true, false) : 'checked disabled'; ?>>
                                        <?php esc_html_e('Plugin Files', 'royal-backup-reset'); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_restore_themes" value="1" <?php echo $is_premium_settings ? checked(ROYALBR_Options::get_royalbr_option('royalbr_restore_themes', false), true, false) : 'checked disabled'; ?>>
                                        <?php esc_html_e('Theme Files', 'royal-backup-reset'); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_restore_uploads" value="1" <?php echo $is_premium_settings ? checked(ROYALBR_Options::get_royalbr_option('royalbr_restore_uploads', false), true, false) : 'checked disabled'; ?>>
                                        <?php esc_html_e('Media Uploads', 'royal-backup-reset'); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_restore_others" value="1" <?php echo $is_premium_settings ? checked(ROYALBR_Options::get_royalbr_option('royalbr_restore_others', false), true, false) : 'checked disabled'; ?>>
                                        <?php esc_html_e('Other Content', 'royal-backup-reset'); ?>
                                    </label>
                                </fieldset>
                                <p class="description">
									<?php if ( $is_premium_settings ) : ?>
										<?php esc_html_e('These items will be automatically selected during restore operations.', 'royal-backup-reset'); ?>
									<?php else : ?>
										<?php esc_html_e('Free version restores all available components. Upgrade to PRO to customize restore preferences.', 'royal-backup-reset'); ?>
									<?php endif; ?>
								</p>
                            </td>
                        </tr>
                    </table>

                    <!-- Reset Defaults -->
                    <h3>
						<?php esc_html_e('Reset Preferences', 'royal-backup-reset'); ?>
						<?php if ( ! $is_premium_settings ) : ?>
							<a href="#" class="royalbr-pro-badge"><?php esc_html_e( 'PRO', 'royal-backup-reset' ); ?></a>
						<?php endif; ?>
					</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Post-Reset Actions', 'royal-backup-reset'); ?></th>
                            <td>
                                <fieldset class="<?php echo $is_premium_settings ? '' : 'royalbr-pro-option-disabled'; ?>" <?php if ( ! $is_premium_settings ) : ?>data-pro-option-name="<?php esc_attr_e( 'Reset Preferences', 'royal-backup-reset' ); ?>"<?php endif; ?>>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_reactivate_theme" value="1" <?php echo $is_premium_settings ? checked(ROYALBR_Options::get_royalbr_option('royalbr_reactivate_theme', false), true, false) : 'disabled'; ?>>
                                        <?php esc_html_e('Restore Current Theme', 'royal-backup-reset'); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_reactivate_plugins" value="1" <?php echo $is_premium_settings ? checked(ROYALBR_Options::get_royalbr_option('royalbr_reactivate_plugins', false), true, false) : 'disabled'; ?>>
                                        <?php esc_html_e('Reactivate currently Active Plugins', 'royal-backup-reset'); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_keep_royalbr_active" value="1" <?php echo $is_premium_settings ? checked(ROYALBR_Options::get_royalbr_option('royalbr_keep_royalbr_active', true), true, false) : 'checked disabled'; ?>>
                                        <?php esc_html_e('Keep Royal Backup & Reset active', 'royal-backup-reset'); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_clear_media" value="1" <?php echo $is_premium_settings ? checked(ROYALBR_Options::get_royalbr_option('royalbr_clear_media', false), true, false) : 'disabled'; ?>>
                                        <?php esc_html_e('Clear Media Files', 'royal-backup-reset'); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" class="royalbr-custom-checkbox" name="royalbr_clear_uploads" value="1" <?php echo $is_premium_settings ? checked(ROYALBR_Options::get_royalbr_option('royalbr_clear_uploads', false), true, false) : 'disabled'; ?>>
                                        <?php esc_html_e('Clear Uploads Directory', 'royal-backup-reset'); ?>
                                    </label>
                                </fieldset>
                                <p class="description">
									<?php if ( $is_premium_settings ) : ?>
										<?php esc_html_e('These choices will be automatically applied during database reset.', 'royal-backup-reset'); ?>
									<?php else : ?>
										<?php esc_html_e('Upgrade to PRO to customize reset preferences.', 'royal-backup-reset'); ?>
									<?php endif; ?>
								</p>
                            </td>
                        </tr>
                    </table>

					<!-- Backup Reminder -->
					<h3><?php esc_html_e( 'Backup Reminder', 'royal-backup-reset' ); ?></h3>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Backup Reminder Popup', 'royal-backup-reset' ); ?></th>
							<td>
								<select name="royalbr_reminder_popup_mode">
									<option value="allow_dismiss" <?php selected( ROYALBR_Options::get_royalbr_option( 'royalbr_reminder_popup_mode', 'allow_dismiss' ), 'allow_dismiss' ); ?>>
										<?php esc_html_e( 'Allow Dismiss', 'royal-backup-reset' ); ?>
									</option>
									<option value="show_always" <?php selected( ROYALBR_Options::get_royalbr_option( 'royalbr_reminder_popup_mode', 'allow_dismiss' ), 'show_always' ); ?>>
										<?php esc_html_e( 'Show Always', 'royal-backup-reset' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Control how the Backup Reminder Popup behaves.', 'royal-backup-reset' ); ?>
								</p>
							</td>
						</tr>
					</table>

                    <p class="submit">
                        <button type="submit" class="royalbr-button-primary" id="royalbr-save-settings">
                            <?php esc_html_e('Save Configuration', 'royal-backup-reset'); ?>
                        </button>
                    </p>
                </form>

                <div id="royalbr-settings-message" style="display: none;"></div>
            </div>
        </div>

        <?php if ( ! ( function_exists( 'royalbr_fs' ) && royalbr_fs()->can_use_premium_code() ) ) : ?>
        <!-- Free vs Pro Tab -->
        <div id="free-vs-pro" class="royalbr-tab-content">
            <div class="royalbr-premium-section">

                <!-- Video Overview -->
                <div class="royalbr-promo-video">
                    <iframe src="https://www.youtube.com/embed/ALQpJImxt_w" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                </div>

                <!-- Features Comparison Table -->
                <section>
                    <table class="royalbr-feat-table">
                        <tbody>
                            <!-- Header Row -->
                            <tr class="royalbr-feat-table__header">
                                <td><?php esc_html_e( 'Features Comparison', 'royal-backup-reset' ); ?></td>
                                <td>
                                    <?php esc_html_e( 'Free', 'royal-backup-reset' ); ?>
                                </td>
                                <td>
                                    <?php esc_html_e( 'Premium', 'royal-backup-reset' ); ?>
                                </td>
                            </tr>

                            <!-- Status Row -->
                            <tr>
                                <td></td>
                                <td>
                                    <span class="royalbr-installed"><span class="dashicons dashicons-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span> <?php esc_html_e( 'Installed', 'royal-backup-reset' ); ?></span>
                                </td>
                                <td>
                                    <a class="royalbr-button-primary" href="https://royal-elementor-addons.com/royal-backup-reset/?ref=rbr-backend-menu-upgrade-pro#purchasepro" target="_blank"><?php esc_html_e( 'Upgrade Now', 'royal-backup-reset' ); ?></a>
                                </td>
                            </tr>

                            <!-- Feature: Manual Backup (Both) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Manual Backup', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Create on-demand backups of your database and files whenever you need them.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Manual Restore (Both) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Manual Restore', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Restore your site from any saved backup to recover from issues.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Database Reset (Both) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Database Reset', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Reset WordPress database to a fresh install state for testing or cleanup.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Backup Download (Both) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Backup Download', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Download backup files to your computer for safe offline storage.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Scheduled Backups (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Scheduled Backups', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Automatically backup your site on a schedule - daily, weekly, or monthly.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Migration (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Migration', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Restore backups from other sites, scan remote storage for backups, or upload backup files directly.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td><span class="royalbr-limited"><?php esc_html_e( 'Up to 200 MB', 'royal-backup-reset' ); ?></span></td>
                                <td><span class="royalbr-unlimited"><?php esc_html_e( 'Unlimited', 'royal-backup-reset' ); ?></span></td>
                            </tr>

                            <!-- Feature: Google Drive (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Google Drive', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Store backups securely on Google Drive.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Dropbox (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Dropbox', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Store backups securely on Dropbox.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Amazon S3 (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Amazon S3', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Store backups securely on Amazon S3.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: WordPress Viruses, Hackers and Updates Protection (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'WordPress Viruses, Hackers and Updates Protection', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'The Backup will include Core WP files and If something goes wrong after a viruses, Hacker attacks or WP update, you can always revert back and restore Original WP Files.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Backup Retention (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Backup Retention', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Automatically delete old backups to save disk space while keeping recent ones.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Selective Backup (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Selective Backup', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Choose specific components to backup - database, plugins, themes, or uploads individually.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Selective Restore (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Selective Restore', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Choose specific components to restore - database, plugins, themes, or uploads individually.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Save Preferences (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Save Preferences', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Save default settings for backup, restore, and reset operations so they are pre-selected every time.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Clear Uploads Directory (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Clear Uploads Directory', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Full cleanup of the uploads folder during database reset for a completely fresh start.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Backup Rename (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Backup Rename', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Give backups custom names for easy identification and organization.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Priority Support (Pro Only) -->
                            <tr>
                                <td>
                                    <h4><?php esc_html_e( 'Priority Support', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Get direct support from the developers whenever you need help.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes royalbr-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span>
                                </td>
                            </tr>

                            <!-- Feature: Incremental Backups (Coming Soon) -->
                            <tr style="display:none;">
                                <td>
                                    <h4><?php esc_html_e( 'Incremental Backups', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Back up only what changed since your last backup, saving time and storage space.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="royalbr-coming-soon"><?php esc_html_e( 'Coming Soon', 'royal-backup-reset' ); ?></span>
                                </td>
                            </tr>

                            <!-- Feature: Multisite Network Support (Coming Soon) -->
                            <tr style="display:none;">
                                <td>
                                    <h4><?php esc_html_e( 'Multisite Network Support', 'royal-backup-reset' ); ?></h4>
                                    <p><?php esc_html_e( 'Full support for WordPress Multisite networks.', 'royal-backup-reset' ); ?></p>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-no-alt royalbr-no" aria-label="<?php esc_attr_e( 'No', 'royal-backup-reset' ); ?>"></span>
                                </td>
                                <td>
                                    <span class="royalbr-coming-soon"><?php esc_html_e( 'Coming Soon', 'royal-backup-reset' ); ?></span>
                                </td>
                            </tr>

                            <!-- Bottom CTA Row -->
                            <tr>
                                <td></td>
                                <td>
                                    <span class="royalbr-installed"><span class="dashicons dashicons-yes" aria-label="<?php esc_attr_e( 'Yes', 'royal-backup-reset' ); ?>"></span> <?php esc_html_e( 'Installed', 'royal-backup-reset' ); ?></span>
                                </td>
                                <td>
                                    <a class="royalbr-button-primary" href="https://royal-elementor-addons.com/royal-backup-reset/?ref=rbr-backend-menu-upgrade-pro#purchasepro" target="_blank"><?php esc_html_e( 'Upgrade Now', 'royal-backup-reset' ); ?></a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modals will be loaded via AJAX -->