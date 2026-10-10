/**
 * Royal Backup & Reset - Backup Reminder Notifications
 *
 * Intercepts theme/plugin activation to prompt users to create a backup first.
 *
 * @package Royal_Backup_Reset
 */

(function($) {
    'use strict';

    // Module configuration
    var config = {
        pendingActivationUrl: null,
        pendingThemeSlug: null,
        pendingPluginFile: null,
        pendingPluginSlug: null,
        pendingAction: null,
        pendingBulkForm: null,
        pendingItemName: null,
        $notificationPopup: null,
        initialized: false
    };

    /**
     * Initialize the backup reminder functionality.
     */
    function init() {
        if (config.initialized) {
            return;
        }

        // Only initialize on themes.php, theme-install.php, or plugins.php
        if (typeof royalbr_admin_bar === 'undefined' || !royalbr_admin_bar.is_reminder_page) {
            return;
        }

        // Skip if permanently dismissed (unless mode is 'show_always')
        if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
            return;
        }

        // Initialize notification popup
        initNotificationPopup();

        // Attach click handlers to theme activation and update links
        interceptThemeActivation();
        interceptThemeUpdate();

        // Attach click handlers to plugin activation and update links
        interceptPluginActivation();
        interceptPluginUpdate();

        // Attach handlers for bulk plugin actions
        interceptBulkPluginActions();
        interceptBulkPluginUpdatesCore();
        interceptBulkThemeUpdatesCore();

        // Attach handler for WordPress core updates
        // interceptCoreUpdate(); // Temporarily disabled

        // Attach handler for WordPress import
        interceptWpImport();

        config.initialized = true;

        // Check for pending template edit from Royal Elementor Addons
        if (royalbr_admin_bar.pending_template_edit) {
            config.pendingActivationUrl = royalbr_admin_bar.pending_template_edit;
            config.pendingAction = 'template-edit';
            config.pendingItemName = royalbr_admin_bar.pending_template_name || 'Template';
            showBackupReminder('template-edit');

            // Clear the transient via AJAX
            $.post(royalbr_admin_bar.ajax_url, {
                action: 'royalbr_clear_pending_template_edit',
                nonce: royalbr_admin_bar.nonce
            });

            // Remove wpr_pending_template and wpr_template_name from URL to prevent
            // backup reminder showing again when user uses browser back button
            if (window.history && window.history.replaceState) {
                var cleanUrl = window.location.href
                    .replace(/([?&])wpr_pending_template=[^&]*(&|$)/, '$1')
                    .replace(/([?&])wpr_template_name=[^&]*(&|$)/, '$1')
                    .replace(/[?&]$/, '')
                    .replace(/&$/, '');
                window.history.replaceState(null, '', cleanUrl);
            }
        }
    }

    /**
     * Create and append the notification popup to DOM.
     */
    function initNotificationPopup() {
        if (config.$notificationPopup) {
            return;
        }

        var strings = getStrings();

        // Conditionally show permanent dismiss link based on popup mode
        var dismissPermanentHtml = royalbr_admin_bar.reminder_popup_mode === 'show_always'
            ? ''
            : '<a href="#" class="royalbr-reminder-dismiss-permanent">' + escapeHtml(strings.dismiss_permanent) + '</a>';

        var html = '<div class="royalbr-backup-reminder-notification">' +
            '<button type="button" class="royalbr-reminder-dismiss-temp" title="' + escapeHtml(strings.proceed_without_backup) + '">' +
            '<span class="dashicons dashicons-no-alt"></span>' +
            '</button>' +
            '<div class="royalbr-reminder-content">' +
            '<span class="royalbr-reminder-icon dashicons dashicons-backup"></span>' +
            '<div class="royalbr-reminder-text">' +
            '<strong>' + escapeHtml(strings.title) + '</strong>' +
            '<p class="royalbr-reminder-description"></p>' +
            '<a href="https://youtu.be/4SZ9r8mOt1M?t=26" target="_blank" class="royalbr-reminder-video-guide">Video Guide <span class="dashicons dashicons-video-alt3"></span></a>' +
            '</div>' +
            '</div>' +
            '<div class="royalbr-reminder-actions">' +
            dismissPermanentHtml +
            '<a href="#" class="royalbr-reminder-skip">' + escapeHtml(strings.skip_now) + ' &rarr;</a>' +
            '</div>' +
            '</div>';

        config.$notificationPopup = $(html);
        $('body').append(config.$notificationPopup);

        // Event handlers
        config.$notificationPopup.on('click', '.royalbr-reminder-dismiss-temp', handleDismissTemporary);
        config.$notificationPopup.on('click', '.royalbr-reminder-dismiss-permanent', handleDismissPermanent);
        config.$notificationPopup.on('click', '.royalbr-reminder-skip', handleDismissTemporary);
    }

    /**
     * Get localized strings with fallbacks.
     */
    function getStrings() {
        var defaults = {
            title: 'Create a backup first?',
            theme_activation: 'Consider backing up before activating themes.',
            theme_update: 'Consider backing up before updating themes.',
            plugin_activation: 'Consider backing up before activating plugins. Takes less than a minute!',
            plugin_update: 'Consider backing up before updating plugins.',
            bulk_plugin_activation: 'Consider backing up before activating multiple plugins.',
            bulk_plugin_deactivation: 'Consider backing up before deactivating multiple plugins.',
            bulk_plugin_update: 'Consider backing up before updating multiple plugins.',
            bulk_theme_update: 'Consider backing up before updating multiple themes.',
            core_update: 'Consider backing up before updating WordPress.',
            wp_import: 'Consider backing up before importing content.',
            template_edit: 'Consider backing up before editing this template.',
            dismiss_permanent: "Don't show again",
            skip_now: 'Skip Now',
            proceed_without_backup: 'Proceed without backup'
        };

        if (typeof royalbr_admin_bar !== 'undefined' && royalbr_admin_bar.reminder_strings) {
            return $.extend({}, defaults, royalbr_admin_bar.reminder_strings);
        }

        return defaults;
    }

    /**
     * Generate suggested backup name based on action type and item name.
     */
    function generateSuggestedBackupName(actionType) {
        var name = config.pendingItemName || '';

        switch (actionType) {
            case 'theme-activation':
                return name ? 'Before activating ' + name : 'Before theme activation';
            case 'theme-update':
                return name ? 'Before updating ' + name : 'Before theme update';
            case 'plugin-activation':
                return name ? 'Before activating ' + name : 'Before plugin activation';
            case 'plugin-update':
                return name ? 'Before updating ' + name : 'Before plugin update';
            case 'bulk-plugin-activation':
                return 'Before activating multiple plugins';
            case 'bulk-plugin-deactivation':
                return 'Before deactivating multiple plugins';
            case 'bulk-plugin-update':
                return 'Before updating multiple plugins';
            case 'bulk-theme-update':
                return 'Before updating multiple themes';
            case 'core-update':
                return 'Before updating WordPress';
            case 'wp-import':
                return 'Before WordPress import';
            case 'template-edit':
                return name ? 'Before editing template: ' + name : 'Before editing template';
            default:
                return 'Before changes';
        }
    }

    /**
     * Get reminder description text based on action type.
     */
    function getReminderText(actionType) {
        var strings = getStrings();

        switch (actionType) {
            case 'theme-update':
                return strings.theme_update;
            case 'plugin-update':
                return strings.plugin_update;
            case 'plugin-activation':
                return strings.plugin_activation;
            case 'bulk-plugin-activation':
                return strings.bulk_plugin_activation;
            case 'bulk-plugin-deactivation':
                return strings.bulk_plugin_deactivation;
            case 'bulk-plugin-update':
                return strings.bulk_plugin_update;
            case 'bulk-theme-update':
                return strings.bulk_theme_update;
            case 'core-update':
                return strings.core_update;
            case 'wp-import':
                return strings.wp_import;
            case 'template-edit':
                return strings.template_edit || strings.theme_activation;
            case 'theme-activation':
            default:
                return strings.theme_activation;
        }
    }

    /**
     * Intercept theme activation link clicks.
     */
    function interceptThemeActivation() {
        // Use event delegation for theme activation links
        $(document).on('click', 'a[href*="action=activate"]', function(e) {
            // Only intercept theme activation links on themes.php
            var href = $(this).attr('href');
            if (!href || href.indexOf('themes.php') === -1) {
                return; // Not a theme activation link
            }

            // Skip if already dismissed permanently (unless mode is 'show_always')
            if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
                return; // Allow default behavior
            }

            e.preventDefault();
            e.stopPropagation();

            // Store the activation URL
            config.pendingActivationUrl = href;

            // Extract theme name from DOM
            var $themeCard = $(this).closest('.theme');
            var themeName = $themeCard.find('.theme-name').first().text().trim();
            // Modal view: theme name is in overlay
            if (!themeName) {
                themeName = $('.theme-overlay .theme-name').first().text().trim();
            }
            config.pendingItemName = themeName || null;

            // Show the backup reminder
            showBackupReminder('theme-activation');
        });
    }

    /**
     * Intercept theme update button clicks.
     */
    function interceptThemeUpdate() {
        // Use capture phase to intercept before WordPress Backbone handles it
        document.addEventListener('click', function(e) {
            var $target = $(e.target);
            var slug = null;

            // Check for modal update button (#update-theme)
            var $updateBtn = $target.closest('#update-theme');
            if ($updateBtn.length) {
                slug = $updateBtn.data('slug');
            }

            // Check for grid view update link (.update-message)
            if (!slug) {
                var $updateMessage = $target.closest('.update-message');
                if ($updateMessage.length) {
                    // Get slug from parent .theme element
                    slug = $updateMessage.closest('.theme').data('slug');
                }
            }

            if (!slug) {
                return; // Not an update button
            }

            // Skip if already dismissed permanently (unless mode is 'show_always')
            if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            // Store the theme slug for later
            config.pendingThemeSlug = slug;
            config.pendingAction = 'update';
            config.pendingActivationUrl = null;

            // Extract theme name from DOM
            var themeName = '';
            var $modal = $target.closest('.theme-overlay');
            if ($modal.length) {
                themeName = $modal.find('.theme-name').first().text().trim();
            }
            if (!themeName) {
                var $themeCard = $target.closest('.theme');
                if ($themeCard.length) {
                    themeName = $themeCard.find('.theme-name').first().text().trim();
                }
            }
            config.pendingItemName = themeName || null;

            // Show the backup reminder
            showBackupReminder('theme-update');
        }, true); // true = capture phase
    }

    /**
     * Intercept plugin activation link clicks.
     */
    function interceptPluginActivation() {
        // Intercept plugin activation on plugins.php (table view)
        $(document).on('click', 'a[href*="action=activate"]', function(e) {
            var href = $(this).attr('href');

            // Only intercept plugin activation links on plugins.php
            if (!href || href.indexOf('plugins.php') === -1) {
                return;
            }

            // Skip if already dismissed permanently (unless mode is 'show_always')
            if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            config.pendingActivationUrl = href;
            config.pendingAction = 'plugin-activation';

            // Extract plugin name from DOM (plugins.php table view)
            var $row = $(this).closest('tr');
            var pluginName = $row.find('.plugin-title strong').first().text().trim();
            config.pendingItemName = pluginName || null;

            showBackupReminder('plugin-activation');
        });

        // Intercept plugin activation on plugin-install.php (card view)
        $(document).on('click', '.activate-now', function(e) {
            var href = $(this).attr('href');

            if (!href) {
                return;
            }

            // Skip if already dismissed permanently (unless mode is 'show_always')
            if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            config.pendingActivationUrl = href;
            config.pendingAction = 'plugin-activation';

            // Extract plugin name from card (plugin-install.php card view)
            var $card = $(this).closest('.plugin-card');
            var pluginName = $card.find('.plugin-card-top strong').first().text().trim();

            // Fallback: try to get name from button's aria-label
            if (!pluginName) {
                var ariaLabel = $(this).attr('aria-label') || '';
                // aria-label format is typically "Activate Plugin Name"
                if (ariaLabel.indexOf('Activate ') === 0) {
                    pluginName = ariaLabel.substring(9);
                }
            }

            // Fallback: check for plugin name in the update message area
            if (!pluginName) {
                var $updateMessage = $(this).closest('.plugin-card-update-failed, .update-message, .plugin-card-bottom');
                if ($updateMessage.length) {
                    $card = $updateMessage.closest('.plugin-card');
                    pluginName = $card.find('.plugin-card-top strong, .name h3, .name a').first().text().trim();
                }
            }

            config.pendingItemName = pluginName || null;

            showBackupReminder('plugin-activation');
        });
    }

    /**
     * Intercept plugin update button clicks.
     */
    function interceptPluginUpdate() {
        // Use capture phase to intercept before WordPress handles it
        document.addEventListener('click', function(e) {
            var $target = $(e.target);
            var pluginFile = null;
            var pluginSlug = null;
            var $row = null;

            // Check for inline update link (selector: [data-plugin] .update-link)
            var $updateLink = $target.closest('.update-link');
            if ($updateLink.length) {
                // Get plugin data from parent row with data-plugin attribute
                $row = $updateLink.closest('[data-plugin]');
                if ($row.length) {
                    pluginFile = $row.data('plugin');
                    pluginSlug = $row.data('slug');
                }
            }

            // Note: Bulk updates are too complex to intercept - let WordPress handle

            if (!pluginFile) {
                return;
            }

            // Skip if already dismissed permanently (unless mode is 'show_always')
            if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            config.pendingPluginFile = pluginFile;
            config.pendingPluginSlug = pluginSlug;
            config.pendingAction = 'plugin-update';
            config.pendingActivationUrl = null;

            // Extract plugin name from DOM
            // Update link is in a separate row (plugin-update-tr) from the main plugin row
            // Navigate to previous sibling row which contains the plugin name
            var $updateRow = $updateLink.closest('tr');
            var $pluginRow = $updateRow.prev('tr[data-plugin]');
            var pluginName = '';
            if ($pluginRow.length) {
                pluginName = $pluginRow.find('.plugin-title strong').first().text().trim();
            }
            config.pendingItemName = pluginName || null;

            showBackupReminder('plugin-update');
        }, true); // true = capture phase
    }

    /**
     * Intercept bulk plugin actions on plugins.php.
     */
    function interceptBulkPluginActions() {
        document.addEventListener('click', function(e) {
            var $target = $(e.target);

            // Check if clicking Apply button
            var $applyBtn = $target.closest('#doaction, #doaction2');
            if (!$applyBtn.length) {
                return;
            }

            var $form = $applyBtn.closest('form');
            var action = $form.find('#bulk-action-selector-top').val();

            if (action === '-1') {
                action = $form.find('#bulk-action-selector-bottom').val();
            }

            // Only intercept activate, update, and deactivate bulk actions
            if (action !== 'activate-selected' && action !== 'update-selected' && action !== 'deactivate-selected') {
                return;
            }

            // Check if any plugins are selected
            var $checked = $form.find('input[name="checked[]"]:checked');
            if ($checked.length === 0) {
                return;
            }

            // Skip if already dismissed permanently (unless mode is 'show_always')
            if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            config.pendingBulkForm = $form;
            if (action === 'activate-selected') {
                config.pendingAction = 'bulk-plugin-activation';
            } else if (action === 'deactivate-selected') {
                config.pendingAction = 'bulk-plugin-deactivation';
            } else {
                config.pendingAction = 'bulk-plugin-update';
            }

            showBackupReminder(config.pendingAction);
        }, true); // capture phase
    }

    /**
     * Intercept bulk plugin updates on update-core.php.
     */
    function interceptBulkPluginUpdatesCore() {
        $('form[name="upgrade-plugins"]').on('submit', function(e) {
            var $form = $(this);

            // Check if any plugins are selected
            var $checked = $form.find('input[name="checked[]"]:checked');
            if ($checked.length === 0) {
                return;
            }

            // Skip if already dismissed permanently (unless mode is 'show_always')
            if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            config.pendingBulkForm = $form;
            config.pendingAction = 'bulk-plugin-update';

            showBackupReminder('bulk-plugin-update');
        });
    }

    /**
     * Intercept bulk theme updates on update-core.php.
     */
    function interceptBulkThemeUpdatesCore() {
        $('form[name="upgrade-themes"]').on('submit', function(e) {
            var $form = $(this);

            // Check if any themes are selected
            var $checked = $form.find('input[name="checked[]"]:checked');
            if ($checked.length === 0) {
                return;
            }

            // Skip if already dismissed permanently (unless mode is 'show_always')
            if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            config.pendingBulkForm = $form;
            config.pendingAction = 'bulk-theme-update';

            showBackupReminder('bulk-theme-update');
        });
    }

    /**
     * Intercept WordPress core updates on update-core.php.
     */
    function interceptCoreUpdate() {
        // WordPress core update form (form name="upgrade" class="upgrade")
        $('form[name="upgrade"].upgrade').on('submit', function(e) {
            // Skip if already dismissed permanently (unless mode is 'show_always')
            if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            config.pendingBulkForm = $(this);
            config.pendingAction = 'core-update';

            showBackupReminder('core-update');
        });
    }

    /**
     * Intercept WordPress import form submission.
     */
    function interceptWpImport() {
        // Target ONLY the WordPress importer step 2 form (author mapping/options)
        // Step 2 form action contains: admin.php?import=wordpress&step=2
        // Step 1 (file upload) has different action, which we should NOT intercept
        $('form[action*="import=wordpress"][action*="step=2"]').on('submit', function(e) {
            // Skip if already dismissed permanently (unless mode is 'show_always')
            if (royalbr_admin_bar.reminder_dismissed && royalbr_admin_bar.reminder_popup_mode !== 'show_always') {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            config.pendingBulkForm = $(this);
            config.pendingAction = 'wp-import';

            showBackupReminder('wp-import');
        });
    }

    /**
     * Show the backup reminder notification alongside backup popup.
     *
     * @param {string} actionType The type of action triggering the reminder.
     */
    function showBackupReminder(actionType) {
        // Update the description text based on action type
        if (config.$notificationPopup) {
            var text = getReminderText(actionType);
            config.$notificationPopup.find('.royalbr-reminder-description').text(text);
        }

        // Set reminder active flag to prevent popup from hiding on hover
        if (typeof window.ROYALBR !== 'undefined') {
            window.ROYALBR.reminderActive = true;
            // Set suggested backup name for the modal
            window.ROYALBR.suggestedBackupName = generateSuggestedBackupName(actionType);
        }

        // First, trigger the backup popup to show
        if (typeof window.ROYALBR !== 'undefined' && typeof window.ROYALBR.showBackupPopup === 'function') {
            window.ROYALBR.showBackupPopup();
        } else {
            // Fallback: trigger hover on backup icon
            $('#wp-admin-bar-royalbr_backup_node').trigger('mouseenter');
        }

        // Wait for backup popup to be visible, then position notification
        setTimeout(function() {
            positionNotificationPopup();
            config.$notificationPopup.addClass('royalbr-popup-visible');
        }, 350); // Slight delay to allow backup popup animation
    }

    /**
     * Position notification popup to the left of backup popup.
     */
    function positionNotificationPopup() {
        var $backupPopup = $('.royalbr-backup-popup');

        if (!$backupPopup.length || !$backupPopup.hasClass('royalbr-popup-visible')) {
            // If backup popup isn't visible yet, try again shortly
            setTimeout(positionNotificationPopup, 100);
            return;
        }

        var notificationWidth = config.$notificationPopup.outerWidth() || 280;
        var gap = 12;

        // Get backup popup's CSS left position (both are position: fixed)
        var backupLeft = parseInt($backupPopup.css('left'), 10) || 0;
        var backupTop = parseInt($backupPopup.css('top'), 10) || 42;

        // Position to the LEFT of backup popup
        var leftPos = backupLeft - notificationWidth - gap;

        // Boundary check - minimum 10px from left edge
        if (leftPos < 10) {
            leftPos = 10;
        }

        config.$notificationPopup.css({
            'left': leftPos + 'px',
            'top': backupTop + 'px'
        });
    }

    /**
     * Handle temporary dismiss (X button) - proceed with activation.
     */
    function handleDismissTemporary(e) {
        e.preventDefault();
        hideNotification();
        hideBackupPopup();
        proceedWithActivation();
    }

    /**
     * Handle permanent dismiss - save preference via AJAX, then proceed.
     */
    function handleDismissPermanent(e) {
        e.preventDefault();

        // Save preference via AJAX
        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_dismiss_backup_reminder',
                nonce: royalbr_admin_bar.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update local state
                    royalbr_admin_bar.reminder_dismissed = true;
                }
            }
        });

        // Don't wait for AJAX - proceed immediately
        hideNotification();
        hideBackupPopup();
        proceedWithActivation();
    }

    /**
     * Hide the notification popup.
     */
    function hideNotification() {
        if (config.$notificationPopup) {
            config.$notificationPopup.removeClass('royalbr-popup-visible');
        }

        // Clear reminder active flag
        if (typeof window.ROYALBR !== 'undefined') {
            window.ROYALBR.reminderActive = false;
        }
    }

    /**
     * Hide the backup popup.
     */
    function hideBackupPopup() {
        if (typeof window.ROYALBR !== 'undefined' && typeof window.ROYALBR.hideBackupPopup === 'function') {
            window.ROYALBR.hideBackupPopup();
        }
    }

    /**
     * Proceed with the original theme/plugin activation or update.
     */
    function proceedWithActivation() {
        if (config.pendingActivationUrl) {
            window.location.href = config.pendingActivationUrl;
        } else if (config.pendingAction === 'update' && config.pendingThemeSlug) {
            // Hide backup progress modal before triggering update
            $('#royalbr-backup-progress-modal').hide();

            // Trigger WordPress theme update via AJAX
            if (typeof wp !== 'undefined' && wp.updates && wp.updates.updateTheme) {
                wp.updates.updateTheme({ slug: config.pendingThemeSlug });
            }
            config.pendingThemeSlug = null;
            config.pendingAction = null;
        } else if (config.pendingAction === 'plugin-update' && config.pendingPluginFile) {
            // Hide backup progress modal before triggering update
            $('#royalbr-backup-progress-modal').hide();

            // Trigger WordPress plugin update via AJAX
            if (typeof wp !== 'undefined' && wp.updates && wp.updates.updatePlugin) {
                wp.updates.updatePlugin({
                    plugin: config.pendingPluginFile,
                    slug: config.pendingPluginSlug
                });
            }
            config.pendingPluginFile = null;
            config.pendingPluginSlug = null;
            config.pendingAction = null;
        } else if (config.pendingBulkForm) {
            var $form = config.pendingBulkForm;
            var action = config.pendingAction;
            config.pendingBulkForm = null;
            config.pendingAction = null;

            if (action === 'bulk-plugin-update') {
                // Check if this is update-core.php form (submit form) or plugins.php (use AJAX)
                if ($form.attr('name') === 'upgrade-plugins') {
                    // update-core.php - submit the form
                    $form.off('submit').submit();
                } else {
                    // plugins.php - trigger each plugin update individually via AJAX
                    $form.find('input[name="checked[]"]:checked').each(function() {
                        var pluginFile = $(this).val();
                        var $row = $form.find('tr[data-plugin="' + pluginFile + '"]');
                        var pluginSlug = $row.data('slug');

                        if (typeof wp !== 'undefined' && wp.updates && wp.updates.updatePlugin) {
                            wp.updates.updatePlugin({
                                plugin: pluginFile,
                                slug: pluginSlug
                            });
                        }
                    });
                }
            } else if (action === 'bulk-theme-update') {
                // update-core.php - submit the theme update form
                $form.append('<input type="hidden" name="upgrade" value="1" />');
                $form.off('submit')[0].submit();
            } else if (action === 'core-update') {
                // WordPress core update - add hidden input to simulate submit button click
                // WordPress checks $_POST['upgrade'] to proceed with update
                $form.append('<input type="hidden" name="upgrade" value="1" />');
                $form.off('submit')[0].submit();
            } else {
                // For activate, submit the form
                $form.off('submit')[0].submit();
            }
        }
    }

    /**
     * HTML escape utility.
     */
    function escapeHtml(str) {
        if (typeof window.ROYALBR !== 'undefined' && typeof window.ROYALBR.escapeHtml === 'function') {
            return window.ROYALBR.escapeHtml(str);
        }
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Listen for backup completion to show post-backup confirmation.
     */
    function setupBackupCompleteListener() {
        // Monitor for backup progress modal completion
        var checkInterval = setInterval(function() {
            var $progressModal = $('#royalbr-backup-progress-modal');

            // Check if backup finished and we have a pending action
            var hasPendingAction = config.pendingActivationUrl ||
                (config.pendingAction === 'update' && config.pendingThemeSlug) ||
                (config.pendingAction === 'plugin-update' && config.pendingPluginFile) ||
                config.pendingBulkForm;

            if ($progressModal.hasClass('royalbr-finished') && hasPendingAction) {
                clearInterval(checkInterval);

                // Check if backup failed with error
                var hasError = $progressModal.find('.royalbr-progress-error').length > 0 ||
                               $progressModal.find('.royalbr-modal-header h3').text() === 'Backup Failed';

                // Wait a moment, then show post-backup confirmation with error state
                setTimeout(function() {
                    showPostBackupConfirmation(hasError);
                }, 500);
            }
        }, 500);

        // Clear interval after 5 minutes to prevent memory leak
        setTimeout(function() {
            clearInterval(checkInterval);
        }, 300000);
    }

    /**
     * Show confirmation modal after backup completes.
     *
     * @param {boolean} hasError Whether the backup failed with an error.
     */
    function showPostBackupConfirmation(hasError) {
        var hasPendingAction = config.pendingActivationUrl ||
            (config.pendingAction === 'update' && config.pendingThemeSlug) ||
            (config.pendingAction === 'plugin-update' && config.pendingPluginFile) ||
            config.pendingBulkForm;

        if (!hasPendingAction) {
            return;
        }

        // Hide backup popup and progress modal before showing confirmation
        hideBackupPopup();
        $('#royalbr-backup-progress-modal').hide();

        // Use the existing confirmation modal pattern
        var $modal = $('#royalbr-confirmation-modal');

        if (!$modal.length) {
            // Modal not loaded, just proceed
            proceedWithActivation();
            return;
        }

        // Update modal content based on action type
        var actionText;
        if (config.pendingAction === 'update') {
            actionText = 'theme update';
        } else if (config.pendingAction === 'plugin-update') {
            actionText = 'plugin update';
        } else if (config.pendingAction === 'plugin-activation') {
            actionText = 'plugin activation';
        } else if (config.pendingAction === 'bulk-plugin-activation') {
            actionText = 'plugin activation';
        } else if (config.pendingAction === 'bulk-plugin-deactivation') {
            actionText = 'plugin deactivation';
        } else if (config.pendingAction === 'bulk-plugin-update') {
            actionText = 'plugin update';
        } else if (config.pendingAction === 'bulk-theme-update') {
            actionText = 'theme update';
        } else if (config.pendingAction === 'core-update') {
            actionText = 'WordPress update';
        } else if (config.pendingAction === 'wp-import') {
            actionText = 'content import';
        } else if (config.pendingAction === 'template-edit') {
            actionText = 'template editing';
        } else {
            actionText = 'theme activation';
        }

        // Show different message based on backup result
        if (hasError) {
            $('#royalbr-modal-title').text('Backup Failed');
            $('#royalbr-modal-message').html('The backup could not be completed.<br><br>Would you like to proceed with the ' + actionText + ' anyway?');
        } else {
            $('#royalbr-modal-title').text('Backup Complete!');
            $('#royalbr-modal-message').html('Your backup was created successfully.<br><br>Would you like to proceed with the ' + actionText + '?');
        }

        // Helper to clear all pending state
        function clearPendingState() {
            config.pendingActivationUrl = null;
            config.pendingThemeSlug = null;
            config.pendingPluginFile = null;
            config.pendingPluginSlug = null;
            config.pendingBulkForm = null;
            config.pendingAction = null;
            config.pendingItemName = null;
        }

        // Set up handlers
        $('#royalbr-confirmation-modal .royalbr-modal-close, #royalbr-modal-cancel').off('click.postbackup').on('click.postbackup', function() {
            $modal.hide();
            clearPendingState();
            $('#royalbr-modal-confirm').off('click.postbackup');
        });

        $modal.off('click.postbackup').on('click.postbackup', function(e) {
            if (e.target === this) {
                $modal.hide();
                clearPendingState();
                $('#royalbr-modal-confirm').off('click.postbackup');
            }
        });

        // Show modal
        $modal.show();

        // Handle confirm - proceed with activation
        $('#royalbr-modal-confirm').off('click.postbackup').on('click.postbackup', function() {
            $modal.hide();
            proceedWithActivation();
            $('#royalbr-modal-confirm').off('click.postbackup');
        });
    }

    /**
     * Override backup button click to set up completion listener.
     */
    function setupBackupButtonOverride() {
        $(document).on('click', '.royalbr-popup-backup-btn', function() {
            // If we have a pending action, set up listener for backup completion
            var hasPendingAction = config.pendingActivationUrl ||
                (config.pendingAction === 'update' && config.pendingThemeSlug) ||
                (config.pendingAction === 'plugin-update' && config.pendingPluginFile) ||
                config.pendingBulkForm;

            if (hasPendingAction) {
                // Hide the notification popup
                hideNotification();

                // Set up listener for backup completion
                setupBackupCompleteListener();
            }
        });
    }

    // Initialize on document ready
    $(document).ready(function() {
        // Slight delay to ensure admin-bar.js is fully initialized
        setTimeout(function() {
            init();
            setupBackupButtonOverride();
        }, 100);
    });

})(jQuery);
