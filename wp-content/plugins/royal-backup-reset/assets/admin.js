jQuery(document).ready(function($) {
    'use strict';

    // Multisite support check - disable all actions
    if (royalbr_ajax.is_multisite) {
        var multisiteMessage = 'This plugin does not support WordPress Multisite yet, but it is coming soon!';

        // Intercept all action button clicks
        $(document).on('click', '#royalbr-create-backup, #royalbr-reset-database, .royalbr-restore-backup, .royalbr-delete-backup, .royalbr-download-component, .royalbr-rename-backup-btn, #royalbr-confirm-reset, #royalbr-save-settings', function(e) {
            e.preventDefault();
            e.stopPropagation();
            alert(multisiteMessage);
            return false;
        });

        // Visually indicate buttons are disabled
        $('#royalbr-create-backup, #royalbr-reset-database, .royalbr-restore-backup, .royalbr-delete-backup, #royalbr-save-settings').prop('disabled', true).css('opacity', '0.6');
    }

    // Load modals via AJAX on page load
    loadBackupConfirmationModal();
    loadConfirmationModal();
    loadLogViewerModal();
    loadProModal();

    // Load and apply default settings on page load
    loadDefaultSettings();

    // Progress polling timer
    var royalbr_backup_progress_timer = null;

    // Pro promo rotation
    var royalbrProPromoTexts = [
        '<b>Upgrade to Pro</b> to schedule <b>Automatic Backups</b> - daily, weekly, or monthly',
        'Store your backups securely on <b>Google Drive, Dropbox and Amazon S3</b> with <b>Pro Version</b>',
        '<b>Pro Version</b> lets you choose exactly what to backup - <b>Database</b>, <b>Plugins</b>, <b>Themes</b>, <b>Uploads</b>, or <b>WP Core</b>',
        'Customize what to restore with <b>Selective Restore</b> in <b>Pro Version</b>',
        'Protect your site during <b>WordPress Updates</b> with <b>Pro Version</b>',
        'Get <b>Priority Support</b> directly from developers with <b>Pro Version</b>'
    ];
    var royalbr_promo_rotation_timer = null;
    var royalbr_promo_index = 0;

    function royalbrStartPromoRotation() {
        if (royalbr_ajax.is_premium) {
            return;
        }
        var $promo = $('#royalbr-pro-promo-text');
        if (!$promo.length) {
            return;
        }
        royalbr_promo_index = Math.floor(Math.random() * royalbrProPromoTexts.length);
        $promo.html(royalbrProPromoTexts[royalbr_promo_index]).fadeIn(400);
        royalbr_promo_rotation_timer = setInterval(function() {
            var nextIndex;
            do {
                nextIndex = Math.floor(Math.random() * royalbrProPromoTexts.length);
            } while (nextIndex === royalbr_promo_index && royalbrProPromoTexts.length > 1);
            royalbr_promo_index = nextIndex;
            $promo.fadeOut(400, function() {
                $(this).html(royalbrProPromoTexts[royalbr_promo_index]).fadeIn(400);
            });
        }, 5000);
    }

    function royalbrStopPromoRotation() {
        if (royalbr_promo_rotation_timer) {
            clearInterval(royalbr_promo_rotation_timer);
            royalbr_promo_rotation_timer = null;
        }
        $('#royalbr-pro-promo-text').fadeOut(400);
    }

    // Track backup mode for progress calculation (admin page)
    var adminBackupIncludeDb = true;
    var adminBackupIncludeFiles = true;

    // Check for active backup on page load and resume progress display
    if (typeof royalbr_ajax.active_backup !== 'undefined' && royalbr_ajax.active_backup.running) {
        console.log('ROYALBR: Active backup detected on page load, resuming progress display');

        // Set backup mode from stored task data
        adminBackupIncludeDb = royalbr_ajax.active_backup.include_db !== false;
        adminBackupIncludeFiles = royalbr_ajax.active_backup.include_files !== false;

        // Show progress bar
        $('#royalbr-backup-progress').show();

        // Set initial progress from stored state
        var taskstatus = royalbr_ajax.active_backup.taskstatus || 'begun';
        var substatus = null;
        if (taskstatus === 'filescreating' && royalbr_ajax.active_backup.filecreating_substatus) {
            substatus = royalbr_ajax.active_backup.filecreating_substatus;
        } else if (taskstatus === 'dbcreating' && royalbr_ajax.active_backup.dbcreating_substatus) {
            substatus = royalbr_ajax.active_backup.dbcreating_substatus;
        }

        var progress = window.ROYALBR.formatProgressText(taskstatus, substatus, adminBackupIncludeDb, adminBackupIncludeFiles);
        $('#royalbr-backup-progress .royalbr-progress-fill').css('width', progress.percent + '%');
        window.ROYALBR.updateProgressText($('#royalbr-backup-progress .royalbr-progress-text'), progress.text, progress.showDots);

        // Show stop button
        $('#royalbr-stop-backup').show();

        // Disable start backup button
        $('#royalbr-create-backup').prop('disabled', true);

        // Start polling for updates
        royalbr_backup_progress_timer = setInterval(royalbrUpdateBackupProgress, 2000);
    }

    // Poll for backup progress
    function royalbrUpdateBackupProgress() {
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_progress',
                nonce: royalbr_ajax.nonce,
                oneshot: 1  // Tell backend this is a oneshot task
            },
            success: function(response) {
                console.log('ROYALBR: Progress poll response:', response);
                if (response.success && response.data.running) {
                    var taskstatus = response.data.taskstatus || 'begun';
                    var filecreating = response.data.filecreating_substatus;
                    var dbcreating = response.data.dbcreating_substatus;

                    console.log('ROYALBR: Backup running - taskstatus:', taskstatus, 'filecreating:', filecreating, 'dbcreating:', dbcreating);

                    // Determine which substatus to use
                    var substatus = null;
                    if (taskstatus === 'filescreating' && filecreating) {
                        substatus = filecreating;
                    } else if (taskstatus === 'dbcreating' && dbcreating) {
                        substatus = dbcreating;
                    }

                    // Format and update progress (use shared function with backup mode)
                    var progress = window.ROYALBR.formatProgressText(taskstatus, substatus, adminBackupIncludeDb, adminBackupIncludeFiles);
                    console.log('ROYALBR: Progress update:', progress.text, progress.percent + '%');
                    $('#royalbr-backup-progress .royalbr-progress-fill').css('width', progress.percent + '%');
                    window.ROYALBR.updateProgressText($('#royalbr-backup-progress .royalbr-progress-text'), progress.text, progress.showDots);

                    // Show stop button only (log available after completion)
                    console.log('ROYALBR: Showing stop button');
                    $('#royalbr-stop-backup').show();

                } else {
                    console.log('ROYALBR: Backup complete or not running');
                    // Backup complete or not running - stop polling
                    if (royalbr_backup_progress_timer) {
                        clearInterval(royalbr_backup_progress_timer);
                        royalbr_backup_progress_timer = null;

                        // Only run completion UI updates if we actually stopped a running backup
                        // This prevents duplicate notices if polling continues after cleanup

                        // Check if backup failed with error
                        if (response.data && response.data.backup_error) {
                            console.log('ROYALBR: Backup failed with error:', response.data.backup_error);
                            $('#royalbr-backup-progress .royalbr-progress-fill').css('width', '100%').addClass('royalbr-progress-error');
                            $('#royalbr-backup-progress .royalbr-progress-text').text('Backup failed: ' + response.data.backup_error).addClass('royalbr-error-text');
                        } else {
                            // Update to 100% complete - success
                            $('#royalbr-backup-progress .royalbr-progress-fill').css('width', '100%');
                            $('#royalbr-backup-progress .royalbr-progress-text').text('Backup process finished!');
                        }

                        // Mark backup section as finished
                        $('#royalbr-backup-progress').addClass('royalbr-finished');

                        // Hide stop button
                        $('#royalbr-stop-backup').hide();

                        // Show "View Log" button after completion
                        $('#royalbr-show-log').show();

                        // Refresh backup list without reloading page (but keep button disabled)
                        setTimeout(function() {
                            refreshBackupList();
                        }, 1000);
                    }
                    // If timer is already null, polling already stopped - do nothing
                }
            },
            error: function() {
                // On error, stop polling
                if (royalbr_backup_progress_timer) {
                    clearInterval(royalbr_backup_progress_timer);
                    royalbr_backup_progress_timer = null;
                }
            }
        });
    }

    // Tab functionality
    $('.royalbr-nav-tab').on('click', function(e) {
        e.preventDefault();

        var tabId = $(this).data('tab');

        // Remove active class from all tabs and content
        $('.royalbr-nav-tab').removeClass('royalbr-nav-tab-active');
        $('.royalbr-tab-content').removeClass('royalbr-tab-active');

        // Add active class to clicked tab and corresponding content
        $(this).addClass('royalbr-nav-tab-active');
        $('#' + tabId).addClass('royalbr-tab-active');

        // Apply defaults when switching to certain tabs
        if (tabId === 'backup-website') {
            applyBackupDefaults();
        } else if (tabId === 'reset-database') {
            applyResetDefaults();
        }

        // Update URL hash without jumping
        if (history.pushState) {
            history.pushState(null, null, '#' + tabId);
        }
    });

    // Handle initial hash
    if (window.location.hash) {
        var hash = window.location.hash.substring(1);
        var $tab = $('.royalbr-nav-tab[data-tab="' + hash + '"]');
        if ($tab.length) {
            $tab.trigger('click');
            window.scrollTo(0, 0);
        }
    }

    // Show backup confirmation modal when clicking "Start Backup Process"
    $('#royalbr-create-backup').on('click', function() {
        var includeFiles = $('#royalbr-backup-files').is(':checked');
        var includeDb = $('#royalbr-backup-database').is(':checked');
        var includeWpcore = $('#royalbr-backup-wpcore').is(':checked');

        // Validate at least one option is selected
        if (!includeDb && !includeFiles && !includeWpcore) {
            alert('Please select at least one backup option (Database, Files, or WordPress Core).');
            return;
        }

        // Clear input and show modal with temporary placeholder
        $('#royalbr-backup-name').val('').attr('placeholder', '...');

        // Show the modal
        $('#royalbr-backup-confirmation-modal').show();

        // Get the actual backup ID from the backend
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_nonce',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.nonce) {
                    $('#royalbr-backup-name').attr('placeholder', response.data.nonce);
                }
            }
        });
    });

    // Backup modal event handlers are now set up after modal is loaded via AJAX
    // See setupBackupModalHandlers() function

    // Variables to store current log info for download
    var currentLogNonce = null;
    var currentLogFile = null;

    // Show Log button handler
    $('#royalbr-show-log').on('click', function(e) {
        e.preventDefault();

        // Show modal with loading message
        $('#royalbr-log-modal-title').text('Retrieving activity log...');
        $('#royalbr-log-content').html('<em>Please wait...</em>');
        $('#royalbr-log-popup').show();

        // Check if we're viewing a restore log (if #royalbr_restore_log_file exists and has a value)
        var restoreLogFile = $('#royalbr_restore_log_file').val();

        var ajaxData = {
            nonce: royalbr_ajax.nonce
        };

        if (restoreLogFile) {
            // Fetch restore log
            ajaxData.action = 'royalbr_get_restore_log';
            ajaxData.log_file = restoreLogFile;
        } else {
            // Fetch backup log (current behavior)
            ajaxData.action = 'royalbr_get_log';
        }

        // Fetch log via AJAX
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success && response.data && response.data.log) {
                    // Store log info for download functionality
                    currentLogNonce = response.data.nonce || null;
                    currentLogFile = response.data.log_file || null;

                    // Update modal title and filename - use actual filename from server
                    var logFilename = response.data.filename || 'log.txt';
                    $('#royalbr-log-modal-filename').text(logFilename);
                    $('#royalbr-log-modal-title').text('Activity Log');
                    $('#royalbr-log-content').text(response.data.log);

                    // Scroll to bottom of log
                    var logContent = document.getElementById('royalbr-log-content');
                    if (logContent) {
                        logContent.scrollTop = logContent.scrollHeight;
                    }
                } else {
                    // Handle error - extract message from response.data
                    console.log('ROYALBR: Log retrieval failed. response.success:', response.success, 'response.data:', response.data);
                    var errorMsg = 'Unknown issue';
                    if (response.data) {
                        if (typeof response.data === 'string') {
                            errorMsg = response.data;
                        } else if (response.data.message) {
                            errorMsg = response.data.message;
                        } else if (response.data.log) {
                            errorMsg = response.data.log;
                        } else {
                            // Try to stringify the object to see what's in it
                            errorMsg = 'Unknown issue (data: ' + JSON.stringify(response.data) + ')';
                        }
                    }
                    $('#royalbr-log-content').text('Failed to retrieve log: ' + errorMsg);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $('#royalbr-log-content').text('Failed to retrieve log: ' + textStatus + ' (' + errorThrown + ')');
            }
        });

        return false;
    });

    // Log viewer modal event handlers are now set up after modal is loaded via AJAX
    // See setupLogViewerModalHandlers() function

    // Stop Backup button handler
    $('#royalbr-stop-backup').on('click', function(e) {
        e.preventDefault();

        if (!confirm('Do you want to halt the backup process? This could leave an incomplete archive.')) {
            return false;
        }

        // Get current task ID from oneshot nonce
        // We'll send AJAX to backend to set the deleteflag
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_stop_backup',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                // Stop the polling
                if (royalbr_backup_progress_timer) {
                    clearInterval(royalbr_backup_progress_timer);
                    royalbr_backup_progress_timer = null;
                }

                // Reset UI
                $('#royalbr-backup-progress').hide();
                $('#royalbr-create-backup').prop('disabled', false).removeClass('royalbr-loading');
                $('#royalbr-stop-backup').hide();
                royalbrStopPromoRotation();

                if (response.success) {
                    showAdminNotice('Backup process has been halted.', 'warning');
                } else {
                    showAdminNotice(response.data || 'Failed to halt backup process.', 'error');
                }
            },
            error: function() {
                // Stop polling even on error
                if (royalbr_backup_progress_timer) {
                    clearInterval(royalbr_backup_progress_timer);
                    royalbr_backup_progress_timer = null;
                }

                // Reset UI
                $('#royalbr-backup-progress').hide();
                $('#royalbr-create-backup').prop('disabled', false).removeClass('royalbr-loading');
                $('#royalbr-stop-backup').hide();
                royalbrStopPromoRotation();

                showAdminNotice('Backup halted (connection issue).', 'warning');
            }
        });

        return false;
    });

    // Download component functionality - each link downloads a single file
    $(document).on('click', '.royalbr-download-component', function(e) {
        e.preventDefault();

        var $link = $(this);
        var filename = $link.data('filename');

        if (!filename) {
            console.error('ROYALBR: No filename found for download');
            return;
        }

        // Use GET request via location.href for reliable downloads
        var downloadUrl = royalbr_ajax.ajax_url +
            '?action=royalbr_download_component' +
            '&nonce=' + encodeURIComponent(royalbr_ajax.nonce) +
            '&filename=' + encodeURIComponent(filename);

        window.location.href = downloadUrl;
    });

    // Restore backup functionality - use unified split modal system
    $(document).on('click', '.royalbr-restore-backup', function() {
        var $button = $(this);
        var timestamp = $button.data('timestamp');
        var nonce = $button.data('nonce');
        var isRemote = $button.data('is-remote') === 1 || $button.data('is-remote') === '1';
        var backupSiteUrl = $button.data('backup-site-url') || '';

        // Get storage locations from button data attribute
        var storageLocations = [];
        try {
            var storageData = $button.data('storage-locations');
            if (storageData) {
                var parsed = typeof storageData === 'string'
                    ? JSON.parse(storageData)
                    : storageData;
                // Ensure it's always an array (PHP may return object if keys are non-sequential)
                storageLocations = Array.isArray(parsed) ? parsed : Object.values(parsed);
            }
        } catch (e) {
            console.error('ROYALBR: Failed to parse storage locations', e);
        }

        // Get available components from button data attribute
        var availableComponents = [];
        try {
            var componentsData = $button.data('available-components');
            if (componentsData) {
                availableComponents = typeof componentsData === 'string'
                    ? JSON.parse(componentsData)
                    : componentsData;
            }
        } catch (e) {
            console.error('ROYALBR: Failed to parse available components', e);
        }

        // Load and show component selection modal
        window.ROYALBR.loadComponentSelectionModal('admin-page').then(function() {
            // Reset all checkbox states before applying new backup's state
            $('#royalbr-component-selection-form input[name="royalbr_component[]"]').each(function() {
                $(this).prop('checked', false).prop('disabled', false);
                $(this).closest('label').css('opacity', '1').attr('title', '');
            });
            $('#royalbr_component_select_all').prop('checked', false);

            // Apply default restore settings (only for premium users)
            applyRestoreDefaults();

            // Check if premium user
            var isPremium = royalbr_ajax.is_premium;

            // Disable/enable checkboxes based on available components
            // For free users: keep all checkboxes checked + disabled (set by PHP)
            // For premium users: apply availability logic
            $('#royalbr-component-selection-form input[name="royalbr_component[]"]').each(function() {
                var $checkbox = $(this);
                var component = $checkbox.val();
                var isAvailable = availableComponents.indexOf(component) !== -1;

                // Only modify checkbox state for premium users
                if (isPremium) {
                    $checkbox.prop('disabled', !isAvailable);

                    // Uncheck disabled components
                    if (!isAvailable) {
                        $checkbox.prop('checked', false);
                    }
                } else {
                    // Free users: check and disable all available components
                    $checkbox.prop('checked', isAvailable);
                    $checkbox.prop('disabled', true);
                }

                // Add visual indication for disabled components
                var $label = $checkbox.closest('label');
                if (!isAvailable) {
                    $label.css('opacity', '0.5');
                    $label.attr('title', 'Not available in this backup');
                } else {
                    $label.css('opacity', '1');
                    $label.attr('title', '');
                }
            });

            // Migration detection: show checkbox if backup site URL differs from current
            var $migrationOption = $('#royalbr-migration-option');
            var $wpcoreItem = $('#royalbr_component_wpcore').closest('.royalbr-restore-item');
            var isMigrationRestore = $button.closest('#royalbr-migration-list').length > 0;
            if (backupSiteUrl && royalbr_ajax.site_url && backupSiteUrl !== royalbr_ajax.site_url) {
                $('#royalbr-migration-description').text(
                    'All references to the site location in the database will be replaced with your current site URL: ' + royalbr_ajax.site_url
                );
                $migrationOption.show();
            } else {
                $migrationOption.hide();
            }

            if (isMigrationRestore) {
                $wpcoreItem.hide();
                $('#royalbr_component_wpcore').prop('checked', false);
            } else {
                $wpcoreItem.show();
            }

            // Show size info for free users on migration backups and block if over limit
            var $sizeInfo = $('#royalbr-migration-size-info');
            var totalSize = $button.data('total-size');
            var isMigrationOverLimit = false;
            if (!royalbr_ajax.is_premium && totalSize && $button.closest('#royalbr-migration-list').length) {
                var maxSize = parseInt(royalbr_ajax.migration_max_size, 10);
                var sizeMB = (totalSize / (1024 * 1024)).toFixed(0);
                var maxMB = (maxSize / (1024 * 1024)).toFixed(0);
                $sizeInfo.html('<strong>' + sizeMB + ' MB</strong> of <strong>' + maxMB + ' MB</strong>').show();
                if (totalSize > maxSize) {
                    isMigrationOverLimit = true;
                }
            } else {
                $sizeInfo.hide();
            }

            // Block restore if migration backup exceeds size limit
            if (isMigrationOverLimit) {
                var $proModal = $('#royalbr-pro-modal');
                if ($proModal.length) {
                    $('.royalbr-modal:visible').not($proModal).hide();
                    var sizeLimitName = $('.royalbr-migration-limit-note .royalbr-pro-badge').data('pro-option-name') || royalbr_admin.strings.migration_feature;
                    $('#royalbr-pro-modal-message').html(
                        '<strong>' + sizeLimitName + '</strong> ' + royalbr_admin.strings.pro_feature_message
                    );
                    $proModal.show();
                }
                return;
            }

            // Show the modal
            $('#royalbr-component-selection-modal').show();

            // Handle proceed button - one-time handler
            $('#royalbr-component-selection-proceed').off('click.adminrestore').on('click.adminrestore', function() {
                // Gather selected components
                var selectedComponents = [];
                $('#royalbr-component-selection-form input[name="royalbr_component[]"]:checked').each(function() {
                    selectedComponents.push($(this).val());
                });

                // Validate at least one component selected
                if (selectedComponents.length === 0) {
                    showAdminNotice('You must select at least one item to restore.', 'error');
                    return;
                }

                // Hide component selection modal
                $('#royalbr-component-selection-modal').hide();

                // Build component labels for confirmation
                var componentNames = {
                    'db': 'Database',
                    'plugins': 'Plugins',
                    'themes': 'Themes',
                    'uploads': 'Uploads',
                    'others': 'Others',
                    'wpcore': 'WordPress Core'
                };

                var componentLabels = selectedComponents.map(function(comp) {
                    return '<strong>' + (componentNames[comp] || comp) + '</strong>';
                });

                // Show confirmation modal
                showConfirmationModal(
                    royalbr_admin.strings.confirm_restore_title,
                    'Proceed with restoring: ' + componentLabels.join(', ') + '?<br>This operation will replace your current site data.',
                    function() {
                        // Use unified ROYALBR.startRestore from royalbr-core.js
                        window.ROYALBR.startRestore(timestamp, nonce, selectedComponents, 'admin-page', isRemote, storageLocations);

                        // Ensure footer is centered when restore completes (admin page specific)
                        setTimeout(function() {
                            $('#royalbr-progress-modal .royalbr-modal-footer').addClass('royalbr-modal-footer-centered');
                        }, 2000);
                    }
                );

                // Remove the one-time handler
                $('#royalbr-component-selection-proceed').off('click.adminrestore');
            });
        }).catch(function(err) {
            alert(err || 'Failed to load restore dialog. Please refresh the page and try again.');
        });
    });

    // Delete backup functionality
    $(document).on('click', '.royalbr-delete-backup', function() {
        var $button = $(this);
        var timestamp = $button.data('timestamp');
        var nonce = $button.data('nonce');

        showConfirmationModal(
            royalbr_admin.strings.confirm_delete_title,
            royalbr_admin.strings.confirm_delete_message.replace('%s', timestamp),
            function() {
                deleteBackup(nonce, $button);
            }
        );
    });

    // ========================================================================
    // BACKUP RENAME FUNCTIONALITY (Premium Feature)
    // ========================================================================

    // Handle rename button click
    $(document).on('click', '.royalbr-rename-backup-btn', function(e) {
        e.preventDefault();
        var $button = $(this);
        var backupNonce = $button.data('nonce');
        var currentName = $button.data('current-name');

        // Load rename modal
        loadRenameModal(backupNonce, currentName);
    });

    // Load rename modal via AJAX
    function loadRenameModal(backupNonce, currentName) {
        // Remove existing modal if present
        $('#royalbr-rename-modal').remove();

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_rename_modal_html',
                nonce: royalbr_ajax.nonce,
                backup_nonce: backupNonce,
                current_name: currentName
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                    // Show modal
                    $('#royalbr-rename-modal').show();
                    // Focus input
                    $('#royalbr-backup-new-name').focus().select();
                    // Setup handlers
                    setupRenameModalHandlers();
                } else {
                    showAdminNotice(response.data.message || 'Failed to load rename modal.', 'error');
                }
            },
            error: function() {
                showAdminNotice('Failed to load rename modal. Please try again.', 'error');
            }
        });
    }

    // Setup rename modal event handlers
    function setupRenameModalHandlers() {
        var $modal = $('#royalbr-rename-modal');

        // Handle close button
        $modal.find('.royalbr-modal-close, .royalbr-modal-cancel').on('click', function() {
            $modal.hide().remove();
        });

        // Handle click outside modal
        $modal.on('click', function(e) {
            if (e.target === this) {
                $modal.hide().remove();
            }
        });

        // Handle save button
        $modal.find('.royalbr-save-backup-name').on('click', function() {
            saveBackupName();
        });

        // Handle Enter key in input
        $modal.find('#royalbr-backup-new-name').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                saveBackupName();
            }
        });
    }

    // Save backup name via AJAX
    function saveBackupName() {
        var $modal = $('#royalbr-rename-modal');
        var $saveBtn = $modal.find('.royalbr-save-backup-name');
        var $input = $modal.find('#royalbr-backup-new-name');
        var backupNonce = $modal.find('#royalbr-backup-rename-nonce').val();
        var newName = $input.val().trim();

        // Disable button during request
        $saveBtn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_rename_backup',
                nonce: royalbr_ajax.nonce,
                backup_nonce: backupNonce,
                new_name: newName
            },
            success: function(response) {
                if (response.success) {
                    // Show success notification
                    showAdminNotice(response.data.message || 'Backup renamed successfully.', 'success');

                    // Update the UI directly
                    updateBackupNameInTable(backupNonce, newName);

                    // Close and remove modal
                    $modal.hide().remove();
                } else {
                    showAdminNotice(response.data.message || 'Failed to rename backup.', 'error');
                    $saveBtn.prop('disabled', false).text('Save Name');
                }
            },
            error: function() {
                showAdminNotice('Failed to rename backup. Please try again.', 'error');
                $saveBtn.prop('disabled', false).text('Save Name');
            }
        });
    }

    // Update backup name in table without full refresh
    function updateBackupNameInTable(backupNonce, newName) {
        // Find the rename button for this backup
        var $renameBtn = $('.royalbr-rename-backup-btn[data-nonce="' + backupNonce + '"]');
        if ($renameBtn.length === 0) {
            return;
        }

        // Get the wrapper and backup name elements
        var $wrapper = $renameBtn.closest('.royalbr-backup-name-wrapper');
        var $nameElement = $wrapper.find('.royalbr-backup-name');
        var $backupIdElement = $wrapper.parent().find('.royalbr-backup-id');

        if (newName) {
            // Update to show custom name
            var capitalizedName = newName.charAt(0).toUpperCase() + newName.slice(1);
            $nameElement.text(capitalizedName);

            // Update button data attribute
            $renameBtn.attr('data-current-name', newName);

            // If backup ID doesn't exist yet, add it
            if ($backupIdElement.length === 0) {
                $wrapper.after('<br><span class="royalbr-backup-id">' + backupNonce + '</span><br>');
            }
        } else {
            // Remove custom name - show only nonce
            $nameElement.text(backupNonce);

            // Update button data attribute
            $renameBtn.attr('data-current-name', '');

            // Remove the backup ID element if it exists
            if ($backupIdElement.length > 0) {
                $backupIdElement.next('br').remove(); // Remove the <br> after it
                $backupIdElement.remove();
            }
        }
    }

    // Refresh backup table
    function refreshBackupTable() {
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_list',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('#royalbr-backup-table-container').html(response.data.html);
                }
            }
        });
    }

    // ========================================================================
    // END BACKUP RENAME FUNCTIONALITY
    // ========================================================================

    // Modal functionality
    function showConfirmationModal(title, message, confirmCallback) {
        var $modal = $('#royalbr-confirmation-modal');
        if (!$modal.length) {
            // Modal not loaded - try reloading it
            loadConfirmationModal();
            alert(title + '\n\n' + message.replace(/<[^>]*>/g, '') + '\n\nPlease try again.');
            return;
        }
        $('#royalbr-modal-title').text(title);
        $('#royalbr-modal-message').html(message);

        $modal.show();

        // Handle confirm button
        $('#royalbr-modal-confirm').off('click').on('click', function() {
            $modal.hide();
            if (confirmCallback) {
                confirmCallback();
            }
        });

        // Handle cancel button and close
        $('#royalbr-modal-cancel, .royalbr-modal-close').off('click').on('click', function() {
            $modal.hide();
        });

        // Handle click outside modal
        $modal.off('click').on('click', function(e) {
            if (e.target === this) {
                $modal.hide();
            }
        });
    }


    // Handle View Log button in NEW unified progress modal (use event delegation for AJAX-loaded modal)
    $(document).on('click', '#royalbr-progress-view-log', function(e) {
        e.preventDefault();

        var logFile = $('#royalbr_restore_log_file').val();
        if (!logFile) {
            showAdminNotice('Log file not available', 'error');
            return;
        }

        // Fetch log file content via AJAX
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_restore_log',
                log_file: logFile,
                nonce: royalbr_ajax.nonce
            },
            beforeSend: function() {
                $('#royalbr-progress-view-log').prop('disabled', true).text('Loading...');
            },
            success: function(response) {
                if (response.success && response.data.log) {
                    // Store log file path for download
                    window.royalbr_current_restore_log = response.data.log_file || logFile;

                    // Hide progress modal before showing log modal
                    $('#royalbr-progress-modal').hide();

                    // Update log modal with restore log
                    var logFilename = response.data.filename || 'restore-log.txt';
                    $('#royalbr-log-modal-filename').text(logFilename);
                    $('#royalbr-log-modal-title').text('Restore Activity Log');
                    $('#royalbr-log-content').text(response.data.log);
                    $('#royalbr-log-popup').fadeIn();
                } else {
                    showAdminNotice('Failed to load log file', 'error');
                }
            },
            error: function() {
                showAdminNotice('Failed to load log file', 'error');
            },
            complete: function() {
                $('#royalbr-progress-view-log').prop('disabled', false).text('View Activity Log');
            }
        });
    });

    // Handle Done button - close NEW unified progress modal and refresh page (use event delegation for AJAX-loaded modal)
    $(document).on('click', '#royalbr-progress-done', function(e) {
        e.preventDefault();

        // Hide the new progress modal
        var $modal = $('#royalbr-progress-modal');
        $modal.hide();

        // Redirect with auto-login token if available, otherwise reload
        var token = $modal.data('royalbr-auto-login-token');
        if (token) {
            var separator = location.href.indexOf('?') !== -1 ? '&' : '?';
            location.href = location.href.split('#')[0] + separator + 'royalbr_auto_login=' + encodeURIComponent(token);
        } else {
            location.reload();
        }
    });

    // Handle close button on progress modal - only works when restore is complete
    $(document).on('click', '#royalbr-progress-modal .royalbr-modal-close', function(e) {
        e.preventDefault();

        var $modal = $('#royalbr-progress-modal');
        var $doneButton = $modal.find('#royalbr-progress-done');

        // Only allow closing if restore is complete (Done button is visible)
        if ($doneButton.is(':visible')) {
            $modal.hide();
            var token = $modal.data('royalbr-auto-login-token');
            if (token) {
                var separator = location.href.indexOf('?') !== -1 ? '&' : '?';
                location.href = location.href.split('#')[0] + separator + 'royalbr_auto_login=' + encodeURIComponent(token);
            } else {
                location.reload();
            }
        }
    });

    // Handle click outside progress modal - only works when restore is complete
    $(document).on('click', '#royalbr-progress-modal', function(e) {
        if (e.target === this) {
            var $doneButton = $(this).find('#royalbr-progress-done');

            // Only allow closing if restore is complete (Done button is visible)
            if ($doneButton.is(':visible')) {
                $(this).hide();
                var token = $(this).data('royalbr-auto-login-token');
                if (token) {
                    var separator = location.href.indexOf('?') !== -1 ? '&' : '?';
                    location.href = location.href.split('#')[0] + separator + 'royalbr_auto_login=' + encodeURIComponent(token);
                } else {
                    location.reload();
                }
            }
        }
    });

    // Delete backup function
    function deleteBackup(backupNonce, $button) {
        $button.prop('disabled', true).addClass('royalbr-loading');

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_delete_backup',
                nonce: royalbr_ajax.nonce,
                backup_nonce: backupNonce
            },
            success: function(response) {
                if (response.success) {
                    var $row = $button.closest('tr');
                    var $table = $row.closest('table');
                    var isMigration = $table.hasClass('royalbr-migration-table');

                    $row.fadeOut(function() {
                        $(this).remove();

                        // Check if table is now empty
                        if ($table.find('tbody tr').length === 0) {
                            if (isMigration) {
                                $('#royalbr-migration-list').html('<div class="royalbr-no-backups royalbr-migration-empty"><p>' + 'No remote backups found yet. Click "Scan Remote Storage" to discover backups from your connected cloud storage.' + '</p></div>');
                            } else {
                                $('#royalbr-backup-list').html('<div class="royalbr-no-backups"><p>' + royalbr_admin.strings.no_backups + '</p></div>');
                            }
                        }
                    });
                    showAdminNotice(royalbr_admin.strings.delete_success, 'success');
                } else {
                    showAdminNotice(response.data || royalbr_admin.strings.delete_failed, 'error');
                }
            },
            error: function() {
                showAdminNotice(royalbr_admin.strings.ajax_error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).removeClass('royalbr-loading');
            }
        });
    }

    // Rescan remote storage button handler
    $(document).on('click', '#royalbr-rescan-remote-btn', function(e) {
        var $btn = $(this);
        var $status = $('#royalbr-rescan-remote-status');

        if ($btn.prop('disabled')) {
            return;
        }

        $btn.prop('disabled', true).addClass('royalbr-loading');
        $status.html('<span class="spinner is-active"></span> ' + 'Scanning remote storage...').show();

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            timeout: 120000,
            data: {
                action: 'royalbr_rescan_remote',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).removeClass('royalbr-loading');

                if (response.success) {
                    // Update migration table
                    if (response.data.migration_html) {
                        $('#royalbr-migration-list').html(response.data.migration_html);
                    }

                    // Also refresh backup list
                    if (response.data.backup_list_html) {
                        $('#royalbr-backup-list').html(response.data.backup_list_html);
                    }

                    // Display messages from each provider
                    if (response.data.messages && response.data.messages.length > 0) {
                        var messageHtml = '';
                        response.data.messages.forEach(function(msg) {
                            var cssClass = msg.type === 'error' ? 'royalbr-rescan-error' : 'royalbr-rescan-success';
                            messageHtml += '<span class="' + cssClass + '">' + msg.message + '</span> ';
                        });
                        $status.html(messageHtml);
                    } else {
                        $status.html('<span class="royalbr-rescan-success">Scan complete.</span>');
                    }

                    // Auto-hide status after 10 seconds
                    setTimeout(function() {
                        $status.fadeOut();
                    }, 10000);
                } else {
                    $status.html('<span class="royalbr-rescan-error">' + (response.data || 'Scan failed.') + '</span>');
                }
            },
            error: function() {
                $btn.prop('disabled', false).removeClass('royalbr-loading');
                $status.html('<span class="royalbr-rescan-error">Connection error. Please try again.</span>');
            }
        });
    });

    // Upload backup files button handler
    $(document).on('click', '#royalbr-upload-backup-btn', function(e) {
        $('#royalbr-upload-backup-input').trigger('click');
    });

    $(document).on('change', '#royalbr-upload-backup-input', function() {
        var files = Array.prototype.slice.call(this.files);
        if (!files.length) {
            return;
        }
        // Reset input now so the same files can be re-selected later.
        $(this).val('');

        // Free users: enforce file size limit (individual and total).
        if (!royalbr_ajax.is_premium && royalbr_ajax.migration_max_size) {
            var maxSize = parseInt(royalbr_ajax.migration_max_size, 10);
            var totalSize = 0;
            var hasOversized = false;
            for (var i = 0; i < files.length; i++) {
                totalSize += files[i].size;
                if (files[i].size > maxSize) {
                    hasOversized = true;
                }
            }
            if (hasOversized || totalSize > maxSize) {
                var $proModal = $('#royalbr-pro-modal');
                if ($proModal.length) {
                    $('.royalbr-modal:visible').not($proModal).hide();
                    var sizeLimitName = $('.royalbr-migration-limit-note .royalbr-pro-badge').data('pro-option-name') || royalbr_admin.strings.migration_feature;
                    $('#royalbr-pro-modal-message').html(
                        '<strong>' + sizeLimitName + '</strong> ' + royalbr_admin.strings.pro_feature_message
                    );
                    $proModal.show();
                }
                return;
            }
        }

        var $btn = $('#royalbr-upload-backup-btn');
        var $status = $('#royalbr-rescan-remote-status');
        var totalFiles = files.length;
        var uploaded = 0;
        var errors = [];

        $btn.prop('disabled', true).addClass('royalbr-loading');
        $status.html('<span class="spinner is-active"></span> Uploading ' + totalFiles + ' file(s)...').show();

        function uploadNext(index) {
            if (index >= totalFiles) {
                // All files processed - rebuild local history only (no remote scan)
                $status.html('<span class="spinner is-active"></span> Rebuilding backup history...');
                $.ajax({
                    url: royalbr_ajax.ajax_url,
                    type: 'POST',
                    timeout: 30000,
                    data: {
                        action: 'royalbr_rescan_local',
                        nonce: royalbr_ajax.nonce
                    },
                    success: function(response) {
                        $btn.prop('disabled', false).removeClass('royalbr-loading');

                        if (response.success) {
                            if (response.data.migration_html) {
                                $('#royalbr-migration-list').html(response.data.migration_html);
                            }
                            if (response.data.backup_list_html) {
                                $('#royalbr-backup-list').html(response.data.backup_list_html);
                            }
                        }

                        var msg = uploaded + ' of ' + totalFiles + ' file(s) uploaded successfully.';
                        if (errors.length) {
                            msg += ' Errors: ' + errors.join(', ');
                            $status.html('<span class="royalbr-rescan-error">' + msg + '</span>');
                        } else {
                            $status.html('<span class="royalbr-rescan-success">' + msg + '</span>');
                        }

                        setTimeout(function() { $status.fadeOut(); }, 10000);
                    },
                    error: function() {
                        $btn.prop('disabled', false).removeClass('royalbr-loading');
                        var msg = uploaded + ' of ' + totalFiles + ' file(s) uploaded.';
                        if (errors.length) {
                            msg += ' Errors: ' + errors.join(', ');
                        }
                        $status.html('<span class="royalbr-rescan-error">' + msg + ' History rebuild failed.</span>');
                    }
                });
                return;
            }

            var file = files[index];
            $status.html('<span class="spinner is-active"></span> Uploading file ' + (index + 1) + ' of ' + totalFiles + ': ' + file.name);

            var formData = new FormData();
            formData.append('action', 'royalbr_upload_backup');
            formData.append('nonce', royalbr_ajax.nonce);
            formData.append('backup_file', file);

            $.ajax({
                url: royalbr_ajax.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 300000,
                success: function(response) {
                    if (response.success) {
                        uploaded++;
                        uploadNext(index + 1);
                    } else {
                        var errMsg = response.data || 'Unknown error';
                        // Check if this is a size limit error — show PRO modal and stop uploading.
                        if (!royalbr_ajax.is_premium && typeof errMsg === 'string' && errMsg.indexOf('200') !== -1 && errMsg.indexOf('limit') !== -1) {
                            $btn.prop('disabled', false).removeClass('royalbr-loading');
                            $status.hide();
                            var $proModal = $('#royalbr-pro-modal');
                            if ($proModal.length) {
                                $('.royalbr-modal:visible').not($proModal).hide();
                                var sizeLimitName = $('.royalbr-migration-limit-note .royalbr-pro-badge').data('pro-option-name') || royalbr_admin.strings.migration_feature;
                                $('#royalbr-pro-modal-message').html(
                                    '<strong>' + sizeLimitName + '</strong> ' + royalbr_admin.strings.pro_feature_message
                                );
                                $proModal.show();
                            }
                            return;
                        }
                        errors.push(file.name + ': ' + errMsg);
                        uploadNext(index + 1);
                    }
                },
                error: function(jqXHR) {
                    var detail = 'Upload failed';
                    if (jqXHR.status === 413) {
                        detail = 'File too large for server (413). Increase upload_max_filesize and post_max_size in PHP settings';
                    } else if (jqXHR.status === 0) {
                        detail = 'Connection failed - file may be too large or server timed out';
                    } else if (jqXHR.responseJSON && jqXHR.responseJSON.data) {
                        detail = jqXHR.responseJSON.data;
                    } else if (jqXHR.status) {
                        detail = 'Server error (HTTP ' + jqXHR.status + ')';
                    }
                    errors.push(file.name + ': ' + detail);
                    uploadNext(index + 1);
                }
            });
        }

        uploadNext(0);
    });

    // Refresh backup list function
    function refreshBackupList() {
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_list',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('#royalbr-backup-list').html(response.data.html);
                    console.log('ROYALBR: Backup list refreshed');
                } else {
                    console.log('ROYALBR: Failed to refresh backup list');
                }
            },
            error: function() {
                console.log('ROYALBR: Error refreshing backup list');
            }
        });
    }

    // Show admin notice function
    function showAdminNotice(message, type) {
        var noticeClass = 'notice-error'; // Default to error
        if (type === 'success') {
            noticeClass = 'notice-success';
        } else if (type === 'warning') {
            noticeClass = 'notice-warning';
        }

        var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

        var $container = $('#royalbr-admin-notices');

        // For success and error notifications, fade out and replace any existing ones of the same type
        if (type === 'success' || type === 'error') {
            var selectorClass = type === 'success' ? '.notice-success' : '.notice-error';
            var $existingNotices = $container.find(selectorClass);
            if ($existingNotices.length > 0) {
                $existingNotices.fadeOut(200, function() {
                    $(this).remove();
                });

                // Add new notice after fade out
                setTimeout(function() {
                    $notice.hide().appendTo($container).fadeIn(200);
                }, 200);
                return;
            }
        }

        $notice.appendTo($container);
    }

    // Reset database functionality - confirmation checkbox handler
    $('#royalbr-confirm-reset').on('change', function() {
        var isChecked = $(this).is(':checked');
        $('#royalbr-reset-database').prop('disabled', !isChecked);
    });

    // Reset database button click handler
    $('#royalbr-reset-database').on('click', function() {
        var $button = $(this);

        // Get reset options from admin page checkboxes
        var options = {
            reactivate_theme: $('#royalbr-reactivate-theme').is(':checked'),
            reactivate_plugins: $('#royalbr-reactivate-plugins').is(':checked'),
            keep_royalbr_active: $('#royalbr-keep-royalbr-active').is(':checked'),
            clear_uploads: $('#royalbr-clear-uploads').is(':checked'),
            clear_media: $('#royalbr-clear-media').is(':checked')
        };

        // Double confirmation with modal
        showConfirmationModal(
            royalbr_admin.strings.confirm_reset_title,
            royalbr_admin.strings.confirm_reset_message,
            function() {
                // Load and show reset progress modal
                window.ROYALBR.loadResetProgressModal('admin-page').then(function() {
                    var $modal = $('#royalbr-reset-progress-modal');

                    // Show simple "resetting" message
                    $modal.find('.royalbr-reset-subtitle').text('Resetting database, please wait...');
                    $modal.find('.royalbr-restore-components-list').html('<div style="text-align: center; padding: 40px;"><span class="royalbr-reset-spinner"></span></div>');
                    $modal.find('.royalbr-restore-result').hide();
                    $modal.find('.royalbr-modal-footer').hide();

                    // Show modal
                    $modal.show();

                    // Step 1: Call before_reset to save active plugins
                    $.ajax({
                        url: royalbr_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'royalbr_before_reset',
                            nonce: royalbr_ajax.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                // Step 2: Call reset_database AJAX endpoint
                                $.ajax({
                                    url: royalbr_ajax.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'royalbr_reset_database',
                                        nonce: royalbr_ajax.nonce,
                                        reactivate_theme: options.reactivate_theme ? '1' : '0',
                                        reactivate_plugins: options.reactivate_plugins ? '1' : '0',
                                        keep_royalbr_active: options.keep_royalbr_active ? '1' : '0',
                                        clear_uploads: options.clear_uploads ? '1' : '0',
                                        clear_media: options.clear_media ? '1' : '0'
                                    },
                                    success: function(resetResponse) {
                                        if (resetResponse.success) {
                                            // Page will reload, modal will disappear automatically
                                            if (resetResponse.data.redirect_url) {
                                                window.location.href = resetResponse.data.redirect_url;
                                            } else {
                                                location.reload();
                                            }
                                        } else {
                                            $modal.hide();
                                            showAdminNotice(resetResponse.data || royalbr_admin.strings.reset_failed, 'error');
                                        }
                                    },
                                    error: function() {
                                        $modal.hide();
                                        showAdminNotice('Reset failed. Please retry.', 'error');
                                    }
                                });
                            } else {
                                $modal.hide();
                                showAdminNotice('Reset preparation encountered an issue: ' + (response.data || 'Unknown cause'), 'error');
                            }
                        },
                        error: function() {
                            $modal.hide();
                            showAdminNotice('Reset preparation failed. Please retry.', 'error');
                        }
                    });
                });
            }
        );
    });

    // ========================================================================
    // SETTINGS FUNCTIONALITY
    // ========================================================================

    // Global variable to store settings - initialize with defaults immediately (synchronous)
    // This prevents timing issues with async AJAX loading
    var royalbrSettings = {
        backup_include_db: true,
        backup_include_files: true,
        backup_include_wpcore: false,
        restore_db: true,
        restore_plugins: false,
        restore_themes: false,
        restore_uploads: false,
        restore_others: false,
        reactivate_theme: false,
        reactivate_plugins: false,
        keep_royalbr_active: true,
        clear_uploads: false,
        clear_media: false
    };

    // Load default settings from server via AJAX and update the defaults
    function loadDefaultSettings() {
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_settings',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Update settings with server values
                    royalbrSettings = response.data;
                    console.log('ROYALBR: Settings loaded from server:', royalbrSettings);

                    // Re-apply settings to UI after loading from server
                    applyBackupDefaults();
                    applyResetDefaults();
                } else {
                    console.log('ROYALBR: Failed to load settings from server, using hardcoded defaults');
                }
            },
            error: function() {
                console.log('ROYALBR: Error loading settings from server, using hardcoded defaults');
            }
        });
    }

    // Apply backup defaults to Backup tab
    function applyBackupDefaults() {
        if (!royalbrSettings || typeof royalbrSettings !== 'object') {
            console.log('ROYALBR: Settings not loaded yet, skipping backup defaults');
            return;
        }
        // Only apply backup defaults for premium users
        // Free users have checkbox checked + disabled by PHP
        var isPremium = royalbr_ajax.is_premium;
        if (isPremium) {
            if (typeof royalbrSettings.backup_include_db !== 'undefined') {
                $('#royalbr-backup-database').prop('checked', royalbrSettings.backup_include_db === true);
            }
            if (typeof royalbrSettings.backup_include_files !== 'undefined') {
                $('#royalbr-backup-files').prop('checked', royalbrSettings.backup_include_files === true);
            }
            if (typeof royalbrSettings.backup_include_wpcore !== 'undefined') {
                $('#royalbr-backup-wpcore').prop('checked', royalbrSettings.backup_include_wpcore === true);
            }
        }
    }

    // Apply restore defaults to Restore modal (works with both old and new modal systems)
    function applyRestoreDefaults() {
        if (!royalbrSettings || typeof royalbrSettings !== 'object') {
            console.log('ROYALBR: Settings not loaded yet, skipping restore defaults');
            return;
        }

        // Only apply restore defaults for premium users
        // Free users have all checkboxes checked + disabled by PHP (restore everything)
        var isPremium = royalbr_ajax.is_premium;
        if (!isPremium) {
            return;
        }

        // Explicitly handle boolean values from settings
        // Don't use || false because it would override explicit false values

        // For old combined modal (if it exists)
        $('#royalbr_restore_db').prop('checked', royalbrSettings.restore_db === true);
        $('#royalbr_restore_plugins').prop('checked', royalbrSettings.restore_plugins === true);
        $('#royalbr_restore_themes').prop('checked', royalbrSettings.restore_themes === true);
        $('#royalbr_restore_uploads').prop('checked', royalbrSettings.restore_uploads === true);
        $('#royalbr_restore_others').prop('checked', royalbrSettings.restore_others === true);

        // For new component selection modal (unified system)
        $('#royalbr-component-selection-form input[value="db"]').prop('checked', royalbrSettings.restore_db === true);
        $('#royalbr-component-selection-form input[value="plugins"]').prop('checked', royalbrSettings.restore_plugins === true);
        $('#royalbr-component-selection-form input[value="themes"]').prop('checked', royalbrSettings.restore_themes === true);
        $('#royalbr-component-selection-form input[value="uploads"]').prop('checked', royalbrSettings.restore_uploads === true);
        $('#royalbr-component-selection-form input[value="others"]').prop('checked', royalbrSettings.restore_others === true);

        // Update Select All checkbox state (old modal)
        var totalCheckboxes = $('#royalbr-restore-form input[name="royalbr_restore[]"]').length;
        var checkedCheckboxes = $('#royalbr-restore-form input[name="royalbr_restore[]"]:checked').length;
        $('#royalbr_restore_select_all').prop('checked', totalCheckboxes === checkedCheckboxes);

        // Update Select All checkbox state (new modal)
        var totalCheckboxesNew = $('#royalbr-component-selection-form input[name="royalbr_component[]"]').length;
        var checkedCheckboxesNew = $('#royalbr-component-selection-form input[name="royalbr_component[]"]:checked').length;
        $('#royalbr_component_select_all').prop('checked', totalCheckboxesNew === checkedCheckboxesNew);
    }

    // Apply reset defaults to Reset Database tab
    function applyResetDefaults() {
        if (!royalbrSettings || typeof royalbrSettings !== 'object') {
            console.log('ROYALBR: Settings not loaded yet, skipping reset defaults');
            return;
        }
        // Explicitly handle boolean values from settings
        $('#royalbr-reactivate-theme').prop('checked', royalbrSettings.reactivate_theme === true);
        $('#royalbr-reactivate-plugins').prop('checked', royalbrSettings.reactivate_plugins === true);
        $('#royalbr-keep-royalbr-active').prop('checked', royalbrSettings.keep_royalbr_active === true);
        $('#royalbr-clear-media').prop('checked', royalbrSettings.clear_media === true);
        // Only apply clear_uploads for premium users (checkbox is disabled for free users)
        if (royalbr_ajax.is_premium) {
            $('#royalbr-clear-uploads').prop('checked', royalbrSettings.clear_uploads === true);
        }
    }

    // Save settings via AJAX
    $('#royalbr-settings-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $('#royalbr-save-settings');
        var $message = $('#royalbr-settings-message');

        // Disable button
        $button.prop('disabled', true).addClass('royalbr-loading');

        // Gather all settings
        var settings = {};

        // Collect checkboxes (existing free settings)
        $form.find('input[type="checkbox"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).is(':checked') ? '1' : '0';
            }
        });

        // Collect select dropdowns (schedule intervals)
        $form.find('select').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Collect number inputs (retention counts)
        $form.find('input[type="number"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Collect text inputs from remote storage settings only (folder names, etc.)
        $form.find('.royalbr-remote-settings input[type="text"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Save via AJAX
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_save_settings',
                nonce: royalbr_ajax.nonce,
                settings: settings
            },
            success: function(response) {
                if (response.success) {
                    $message.removeClass('notice-error').addClass('notice notice-success is-dismissible');
                    $message.html('<p>' + response.data + '</p>').show();

                    // Reload settings in memory
                    loadDefaultSettings();

                    // Hide message after 3 seconds
                    setTimeout(function() {
                        $message.fadeOut();
                    }, 3000);
                } else {
                    $message.removeClass('notice-success').addClass('notice notice-error is-dismissible');
                    $message.html('<p>' + (response.data || 'Failed to save settings.') + '</p>').show();
                }
            },
            error: function() {
                $message.removeClass('notice-success').addClass('notice notice-error is-dismissible');
                $message.html('<p>An error occurred while saving settings. Please try again.</p>').show();
            },
            complete: function() {
                $button.prop('disabled', false).removeClass('royalbr-loading');
            }
        });
    });

    // Backup Locations: Prevent deselecting all checkboxes - at least one must be selected
    $('input[name^="royalbr_backup_loc_"]').on('change', function() {
        var $locationCheckboxes = $('input[name^="royalbr_backup_loc_"]:not(:disabled)');
        var checkedCount = $locationCheckboxes.filter(':checked').length;

        if (checkedCount === 0) {
            // Re-check this checkbox and show alert
            $(this).prop('checked', true);
            alert('At least one backup location must be selected.');
        }
    });

    // ========================================================================
    // MODAL LOADING FUNCTIONS (Load modals via AJAX)
    // ========================================================================

    // Load backup confirmation modal
    function loadBackupConfirmationModal() {
        if ($('#royalbr-backup-confirmation-modal').length) {
            return;
        }

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_modal_html',
                nonce: royalbr_ajax.nonce,
                context: 'admin-page'
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                    // Set up event handlers after modal is loaded
                    setupBackupModalHandlers();
                }
            }
        });
    }

    // Set up backup modal event handlers (called after modal is loaded)
    function setupBackupModalHandlers() {
        // Handle modal close button
        $('#royalbr-backup-confirmation-modal .royalbr-modal-close, #royalbr-backup-cancel').on('click', function() {
            $('#royalbr-backup-confirmation-modal').hide();
            $('#royalbr-backup-name').val('');
        });

        // Handle click outside modal to close (only when clicking directly on overlay)
        $('#royalbr-backup-confirmation-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).hide();
                $('#royalbr-backup-name').val('');
            }
        });

        // Handle Enter key in backup name input
        $('#royalbr-backup-name').on('keypress', function(e) {
            if (e.which === 13 || e.key === 'Enter') {
                e.preventDefault();
                $('#royalbr-backup-proceed').trigger('click');
            }
        });

        // Handle backup proceed button - this starts the actual backup process
        $('#royalbr-backup-proceed').on('click', function() {
            // Skip if this is a quick actions backup (admin-bar.js handles it)
            if ($('#royalbr-backup-confirmation-modal.royalbr-quick-actions-modal').is(':visible')) {
                return;
            }

            var $button = $('#royalbr-create-backup');
            var $progressWrapper = $('#royalbr-backup-progress');
            var $progressBar = $('.royalbr-progress-fill');
            var $progressText = $('.royalbr-progress-text');

            // Check if checkboxes exist (hidden in admin-page context)
            var $filesCheckbox = $('#royalbr-backup-files');
            var $dbCheckbox = $('#royalbr-backup-database');
            var $wpcoreCheckbox = $('#royalbr-backup-wpcore');
            var includeFiles = $filesCheckbox.length ? $filesCheckbox.is(':checked') : true;
            var includeDb = $dbCheckbox.length ? $dbCheckbox.is(':checked') : true;
            var includeWpcore = $wpcoreCheckbox.length ? $wpcoreCheckbox.is(':checked') : false;
            var backupName = $('#royalbr-backup-name').val().trim();

            // Store backup mode for progress calculation
            adminBackupIncludeDb = includeDb;
            adminBackupIncludeFiles = includeFiles || includeWpcore;

            // Also update ROYALBR namespace for consistency with admin-bar polling
            window.ROYALBR.backupIncludeDb = includeDb;
            window.ROYALBR.backupIncludeFiles = includeFiles || includeWpcore;

            // Reset last known entity for fresh progress tracking
            window.ROYALBR.lastKnownEntity = null;

            // Hide the modal
            $('#royalbr-backup-confirmation-modal').hide();

            // IMMEDIATELY show progress UI on button click
            $button.prop('disabled', true).addClass('royalbr-loading');
            $progressWrapper.show();
            $progressBar.css('width', '5%');
            $progressText.text('Process initiated');

            // IMMEDIATELY show stop button
            $('#royalbr-stop-backup').show();

            // Start pro promo rotation for free users
            royalbrStartPromoRotation();

            console.log('ROYALBR: UI updated, starting backup AJAX...');

            // Start progress polling IMMEDIATELY
            royalbr_backup_progress_timer = setInterval(function() {
                royalbrUpdateBackupProgress();
            }, 1000);

            // Start backup process
            $.ajax({
                url: royalbr_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'royalbr_create_backup',
                    nonce: royalbr_ajax.nonce,
                    include_db: includeDb ? 1 : 0,
                    include_files: includeFiles ? 1 : 0,
                    include_wpcore: includeWpcore ? 1 : 0,
                    backup_name: backupName
                },
                success: function(response) {
                    console.log('ROYALBR: Backup AJAX response:', response);
                    if (!response.success) {
                        console.log('ROYALBR: Backup start failed:', response.data);
                        // Stop polling on failure
                        if (royalbr_backup_progress_timer) {
                            clearInterval(royalbr_backup_progress_timer);
                            royalbr_backup_progress_timer = null;
                        }
                        showAdminNotice(response.data || royalbr_admin.strings.backup_failed, 'error');
                        $button.prop('disabled', false).removeClass('royalbr-loading');
                        $progressWrapper.hide();
                        $('#royalbr-stop-backup').hide();
                        royalbrStopPromoRotation();
                    }
                },
                error: function() {
                    // Stop polling on error
                    if (royalbr_backup_progress_timer) {
                        clearInterval(royalbr_backup_progress_timer);
                        royalbr_backup_progress_timer = null;
                    }
                    showAdminNotice(royalbr_admin.strings.ajax_error, 'error');
                    $button.prop('disabled', false).removeClass('royalbr-loading');
                    $progressWrapper.hide();
                    $('#royalbr-stop-backup').hide();
                    royalbrStopPromoRotation();
                }
            });
        });
    }

    // Load log viewer modal
    function loadLogViewerModal() {
        if ($('#royalbr-log-popup').length) {
            return;
        }

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_log_viewer_modal_html',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                    // Set up event handlers after modal is loaded
                    setupLogViewerModalHandlers();
                }
            }
        });
    }

    // Set up log viewer modal event handlers (called after modal is loaded)
    function setupLogViewerModalHandlers() {
        // Download log button handler
        $('#royalbr-download-log').on('click', function(e) {
            e.preventDefault();

            // Check for log info from multiple sources:
            // 1. currentLogFile/currentLogNonce (set by #royalbr-show-log in admin.js)
            // 2. window.royalbr_current_restore_log (set by #royalbr-restore-view-log)
            var logFile = currentLogFile || window.royalbr_current_restore_log;
            var logNonce = currentLogNonce;

            if (!logNonce && !logFile) {
                showAdminNotice('Log information not available. Please close and reopen the log viewer.', 'error');
                return;
            }

            // Create form and submit for download
            var form = $('<form>', {
                method: 'POST',
                action: royalbr_ajax.ajax_url
            });

            form.append($('<input>', {type: 'hidden', name: 'nonce', value: royalbr_ajax.nonce}));

            // If we have a restore log file, use restore download action
            if (logFile) {
                form.append($('<input>', {type: 'hidden', name: 'action', value: 'royalbr_download_restore_log'}));
                form.append($('<input>', {type: 'hidden', name: 'log_file', value: logFile}));
            } else {
                // Otherwise use backup download action
                form.append($('<input>', {type: 'hidden', name: 'action', value: 'royalbr_download_log'}));
                form.append($('<input>', {type: 'hidden', name: 'backup_nonce', value: logNonce}));
            }

            $('body').append(form);
            form.submit();
            form.remove();
        });

        // Copy log button handler
        $('#royalbr-copy-log').on('click', function(e) {
            e.preventDefault();
            var logContent = $('#royalbr-log-content').text();

            if (!logContent) {
                showAdminNotice('No log content to copy.', 'error');
                return;
            }

            // Copy to clipboard with fallback for non-secure contexts
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(logContent).then(function() {
                    showCopyFeedback();
                }).catch(function() {
                    fallbackCopy();
                });
            } else {
                fallbackCopy();
            }

            function fallbackCopy() {
                var textarea = document.createElement('textarea');
                textarea.value = logContent;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    showCopyFeedback();
                } catch (err) {
                    showAdminNotice('Failed to copy log to clipboard.', 'error');
                }
                document.body.removeChild(textarea);
            }

            function showCopyFeedback() {
                var $btn = $('#royalbr-copy-log');
                var originalText = $btn.html();
                $btn.html('<span class="dashicons dashicons-yes" style="vertical-align: middle; margin-right: 4px;"></span> Copied!');
                setTimeout(function() {
                    $btn.html(originalText);
                }, 2000);
            }
        });

        // Log modal close handlers (use event delegation for AJAX-loaded modal)
        $(document).on('click', '#royalbr-log-close, #royalbr-log-popup .royalbr-modal-close', function() {
            $('#royalbr-log-popup').hide();
            // If this was a restore log, refresh page
            if (window.royalbr_current_restore_log) {
                location.reload();
                return;
            }
            currentLogNonce = null;
            currentLogFile = null;
        });

        // Close log modal on click outside (use event delegation for AJAX-loaded modal)
        $(document).on('click', '#royalbr-log-popup', function(e) {
            if (e.target === this) {
                $(this).hide();
                // If this was a restore log, refresh page
                if (window.royalbr_current_restore_log) {
                    location.reload();
                    return;
                }
                currentLogNonce = null;
                currentLogFile = null;
            }
        });
    }

    // Load confirmation modal (for generic confirmations)
    function loadConfirmationModal() {
        if ($('#royalbr-confirmation-modal').length) {
            return;
        }

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_confirmation_modal_html',
                context: 'admin-page',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                }
            }
        });
    }

    // Load pro feature modal (for free version users clicking on pro options)
    function loadProModal() {
        // Only load for non-premium users
        if (royalbr_ajax.is_premium) {
            return;
        }

        if ($('#royalbr-pro-modal').length) {
            return;
        }

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_pro_modal_html',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                    setupProModalHandlers();
                }
            }
        });
    }

    // Setup pro modal event handlers
    function setupProModalHandlers() {
        var $modal = $('#royalbr-pro-modal');

        // Handle close button
        $modal.find('.royalbr-modal-close').on('click', function() {
            $modal.hide();
        });

        // Handle click outside modal
        $modal.on('click', function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Handle ESC key
        $(document).on('keydown.promodal', function(e) {
            if (e.key === 'Escape' && $modal.is(':visible')) {
                $modal.hide();
            }
        });
    }

    // Pro option click handler - show upgrade modal
    $(document).on('click', '.royalbr-pro-option-disabled', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var optionName = $(this).data('pro-option-name') || royalbr_admin.strings.pro_feature_default;
        var $proModal = $('#royalbr-pro-modal');

        if ($proModal.length) {
            // Hide all other visible modals before showing pro modal
            $('.royalbr-modal:visible').not($proModal).hide();

            $('#royalbr-pro-modal-message').html(
                '<strong>' + optionName + '</strong> ' + royalbr_admin.strings.pro_feature_message
            );
            $proModal.show();
        }
    });

    // Pro badge click handler - show upgrade modal for standalone badges
    $(document).on('click', '.royalbr-pro-badge', function(e) {
        e.preventDefault();
        e.stopPropagation();

        // Get option name from badge data attribute, parent disabled option, or heading text
        var optionName = $(this).data('pro-option-name')
            || $(this).closest('.royalbr-pro-option-disabled').data('pro-option-name')
            || $(this).closest('h3').clone().children().remove().end().text().trim()
            || royalbr_admin.strings.pro_feature_default;

        var $proModal = $('#royalbr-pro-modal');

        if ($proModal.length) {
            $('.royalbr-modal:visible').not($proModal).hide();

            $('#royalbr-pro-modal-message').html(
                '<strong>' + optionName + '</strong> ' + royalbr_admin.strings.pro_feature_message
            );
            $proModal.show();
        }
    });

    // Handle pro modal upgrade button - conditional navigation
    $(document).on('click', '#royalbr-pro-modal-upgrade-btn', function(e) {
        e.preventDefault();

        var activeTab = $('.royalbr-nav-tab-active').data('tab');
        var upgradeUrl = $(this).data('upgrade-url');

        // Close the modal
        $('#royalbr-pro-modal').hide();

        if (activeTab === 'free-vs-pro') {
            // Already on Free vs Pro tab - go to upgrade page
            window.location.href = upgradeUrl;
        } else {
            // Navigate to Free vs Pro tab
            $('.royalbr-nav-tab[data-tab="free-vs-pro"]').trigger('click');
        }
    });

    // ========================================================================
    // GLOBAL ROYALBR NAMESPACE - Shared API for Admin Page and Quick Actions
    // ========================================================================

    /**
     * Global Royal Backup & Reset namespace.
     * Provides shared modal and action functions for both Admin Page and Quick Actions.
     */
    window.ROYALBR = window.ROYALBR || {};

    /**
     * Load backup confirmation modal (custom name input).
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.loadBackupModal = function(context) {
        context = context || 'admin-page';

        if ($('#royalbr-backup-confirmation-modal').length) {
            return Promise.resolve();
        }

        return $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_modal_html',
                context: context,
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                    setupBackupModalHandlers();
                }
            }
        });
    };

    /**
     * Load component selection modal.
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.loadComponentSelectionModal = function(context) {
        context = context || 'admin-page';

        if ($('#royalbr-component-selection-modal').length) {
            return Promise.resolve();
        }

        return new Promise(function(resolve, reject) {
            $.ajax({
                url: royalbr_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'royalbr_get_component_selection_modal_html',
                    context: context,
                    nonce: royalbr_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data.html) {
                        $('body').append(response.data.html);
                        setupComponentSelectionModalHandlers(context);
                        resolve();
                    } else {
                        reject('Failed to load restore modal');
                    }
                },
                error: function() {
                    reject('Failed to load restore modal. Please refresh the page and try again.');
                }
            });
        });
    };

    /**
     * Setup component selection modal handlers.
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    function setupComponentSelectionModalHandlers(context) {
        context = context || 'admin-page';

        // Handle close button
        $('#royalbr-component-selection-modal .royalbr-modal-close, #royalbr-component-selection-cancel').on('click', function() {
            $('#royalbr-component-selection-modal').hide();
        });

        // Handle click outside modal to close (only on overlay)
        $('#royalbr-component-selection-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Handle Select All checkbox (premium only - hidden for free users)
        $('#royalbr_component_select_all').on('change', function() {
            // Only allow for premium users
            if (!royalbr_ajax.is_premium) {
                return;
            }
            var isChecked = $(this).prop('checked');
            $('#royalbr-component-selection-form input[name="royalbr_component[]"]:not(:disabled)').prop('checked', isChecked);
        });

        // Update Select All state when individual checkboxes change (premium only)
        $('#royalbr-component-selection-form input[name="royalbr_component[]"]').on('change', function() {
            // Only for premium users
            if (!royalbr_ajax.is_premium) {
                return;
            }
            var totalCheckboxes = $('#royalbr-component-selection-form input[name="royalbr_component[]"]:not(:disabled)').length;
            var checkedCheckboxes = $('#royalbr-component-selection-form input[name="royalbr_component[]"]:checked').length;
            $('#royalbr_component_select_all').prop('checked', totalCheckboxes === checkedCheckboxes);
        });
    }

    /**
     * Load generic confirmation modal.
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.loadConfirmationModal = function(context) {
        context = context || 'admin-page';

        if ($('#royalbr-confirmation-modal').length) {
            return Promise.resolve();
        }

        return $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_confirmation_modal_html',
                context: context,
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                    // Setup handlers will be added in Phase 4
                }
            }
        });
    };

    /**
     * Load progress modal (for restore operations).
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.loadProgressModal = function(context) {
        context = context || 'admin-page';

        if ($('#royalbr-progress-modal').length) {
            return Promise.resolve();
        }

        return $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_progress_modal_html',
                context: context,
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                    // Setup handlers will be added in Phase 4
                }
            }
        });
    };

    /**
     * Load log viewer modal.
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.loadLogViewerModal = function(context) {
        context = context || 'admin-page';

        if ($('#royalbr-log-popup').length) {
            return Promise.resolve();
        }

        return $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_log_viewer_modal_html',
                context: context,
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                    setupLogViewerModalHandlers();
                }
            }
        });
    };

    /**
     * Show backup modal with custom name input.
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.showBackupModal = function(context) {
        context = context || 'admin-page';

        ROYALBR.loadBackupModal(context).then(function() {
            $('#royalbr-backup-confirmation-modal').show();
        });
    };

    /**
     * Show component selection modal.
     * @param {string} timestamp - Backup timestamp
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.showComponentSelectionModal = function(timestamp, context) {
        context = context || 'admin-page';

        ROYALBR.loadComponentSelectionModal(context).then(function() {
            // Will be implemented in Phase 4
            console.log('ROYALBR.showComponentSelectionModal called with:', timestamp, context);
        });
    };

    /**
     * Show generic confirmation modal.
     * @param {string} title - Modal title
     * @param {string} message - Modal message (HTML allowed)
     * @param {function} callback - Callback on confirm
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.showConfirmationModal = function(title, message, callback, context) {
        context = context || 'admin-page';

        ROYALBR.loadConfirmationModal(context).then(function() {
            // Will be implemented in Phase 4
            console.log('ROYALBR.showConfirmationModal called with:', title, message, context);
        });
    };

    /**
     * Start backup process.
     * @param {string} backupName - Custom backup name (optional)
     * @param {boolean} includeFiles - Include files in backup
     * @param {string} context - 'admin-page' or 'quick-actions'
     * @param {boolean} includeDb - Include database in backup
     * @param {boolean} includeWpcore - Include WordPress core in backup
     */
    ROYALBR.startBackup = function(backupName, includeFiles, context, includeDb, includeWpcore) {
        context = context || 'admin-page';
        includeDb = typeof includeDb !== 'undefined' ? includeDb : true;
        includeWpcore = typeof includeWpcore !== 'undefined' ? includeWpcore : false;

        console.log('ROYALBR.startBackup called with:', backupName, includeFiles, context, includeDb, includeWpcore);

        // Load backup progress modal
        ROYALBR.loadBackupProgressModal(context).then(function() {
            var $modal = $('#royalbr-backup-progress-modal');

            // Reset progress UI
            $modal.find('.royalbr-progress-fill').css('width', '0%');
            $modal.find('.royalbr-progress-text').text('Initializing backup...');
            $modal.find('#royalbr-backup-progress-view-log, #royalbr-backup-progress-done').hide();

            // Show modal
            $modal.show();

            // Start progress polling
            ROYALBR.backupProgressTimer = setInterval(function() {
                ROYALBR.pollBackupProgress(context);
            }, 1000);

            // Initiate backup via AJAX
            $.ajax({
                url: royalbr_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'royalbr_create_backup',
                    nonce: royalbr_ajax.nonce,
                    include_db: includeDb ? 1 : 0,
                    include_files: includeFiles ? 1 : 0,
                    include_wpcore: includeWpcore ? 1 : 0,
                    backup_name: backupName || ''
                },
                success: function(response) {
                    if (!response.success) {
                        console.log('ROYALBR: Backup start failed:', response.data);
                        // Stop polling on failure
                        if (ROYALBR.backupProgressTimer) {
                            clearInterval(ROYALBR.backupProgressTimer);
                            ROYALBR.backupProgressTimer = null;
                        }

                        // Show error in modal
                        $modal.find('.royalbr-progress-text').text('Backup failed: ' + (response.data || 'Unknown error'));
                        $modal.find('#royalbr-backup-progress-done').show();
                    }
                },
                error: function() {
                    // Stop polling on error
                    if (ROYALBR.backupProgressTimer) {
                        clearInterval(ROYALBR.backupProgressTimer);
                        ROYALBR.backupProgressTimer = null;
                    }

                    // Show error in modal
                    $modal.find('.royalbr-progress-text').text('Backup failed: Connection error');
                    $modal.find('#royalbr-backup-progress-done').show();
                }
            });
        });
    };

    /**
     * Poll for backup progress updates.
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.pollBackupProgress = function(context) {
        context = context || 'admin-page';

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_progress',
                nonce: royalbr_ajax.nonce,
                oneshot: 1
            },
            success: function(response) {
                console.log('ROYALBR: Progress poll response:', response);

                var $modal = $('#royalbr-backup-progress-modal');

                if (response.success && response.data.running) {
                    var taskstatus = response.data.taskstatus || 'begun';
                    var filecreating = response.data.filecreating_substatus;
                    var dbcreating = response.data.dbcreating_substatus;

                    var substatus = null;
                    if (taskstatus === 'filescreating' && filecreating) {
                        substatus = filecreating;
                    } else if (taskstatus === 'dbcreating' && dbcreating) {
                        substatus = dbcreating;
                    }

                    // Format and update progress (use ROYALBR namespace for consistency with admin-bar)
                    var progress = window.ROYALBR.formatProgressText(taskstatus, substatus, ROYALBR.backupIncludeDb, ROYALBR.backupIncludeFiles);
                    console.log('ROYALBR: Progress update:', progress.text, progress.percent + '%');

                    $modal.find('.royalbr-progress-fill').css('width', progress.percent + '%');
                    window.ROYALBR.updateProgressText($modal.find('.royalbr-progress-text'), progress.text, progress.showDots);

                } else {
                    console.log('ROYALBR: Backup complete or not running');
                    // Backup complete - stop polling
                    if (ROYALBR.backupProgressTimer) {
                        clearInterval(ROYALBR.backupProgressTimer);
                        ROYALBR.backupProgressTimer = null;

                        // Check if backup failed with error
                        if (response.data && response.data.backup_error) {
                            console.log('ROYALBR: Backup failed with error:', response.data.backup_error);
                            $modal.find('.royalbr-progress-fill').css('width', '100%').addClass('royalbr-progress-error');
                            $modal.find('.royalbr-progress-text').text('Backup failed: ' + response.data.backup_error).addClass('royalbr-error-text');
                        } else {
                            // Update to 100% complete - success
                            $modal.find('.royalbr-progress-fill').css('width', '100%');
                            $modal.find('.royalbr-progress-text').text('Backup process finished!');
                        }

                        // Mark modal as finished
                        $modal.addClass('royalbr-finished');

                        // Show completion buttons
                        $modal.find('#royalbr-backup-progress-view-log, #royalbr-backup-progress-done').show();
                    }
                }
            },
            error: function() {
                // On error, stop polling
                if (ROYALBR.backupProgressTimer) {
                    clearInterval(ROYALBR.backupProgressTimer);
                    ROYALBR.backupProgressTimer = null;
                }
            }
        });
    };

    // Timer for backup progress polling
    ROYALBR.backupProgressTimer = null;

    /**
     * Start restore process.
     * @param {string} timestamp - Backup timestamp
     * @param {string} nonce - Backup nonce
     * @param {array} components - Selected components to restore
     * @param {string} context - 'admin-page' or 'quick-actions'
     * @param {boolean} isRemote - Whether backup has remote storage
     * @param {array} storageLocations - Storage locations array (local, gdrive, dropbox)
     */
    ROYALBR.startRestore = function(timestamp, nonce, components, context, isRemote, storageLocations) {
        context = context || 'admin-page';
        isRemote = isRemote || false;

        // Ensure storageLocations is always an array (PHP may return object if keys are non-sequential)
        if (!storageLocations) {
            storageLocations = [];
        } else if (!Array.isArray(storageLocations)) {
            storageLocations = Object.values(storageLocations);
        }

        console.log('ROYALBR.startRestore called with:', timestamp, nonce, components, context, isRemote, storageLocations);

        // Determine the remote storage provider for display text
        var remoteProvider = '';
        var downloadHelperText = 'Downloading backup files from cloud storage';
        if (storageLocations.indexOf('dropbox') !== -1 && storageLocations.indexOf('gdrive') !== -1) {
            remoteProvider = 'both';
            downloadHelperText = 'Downloading backup files from cloud storage';
        } else if (storageLocations.indexOf('dropbox') !== -1) {
            remoteProvider = 'dropbox';
            downloadHelperText = 'Downloading backup files from Dropbox';
        } else if (storageLocations.indexOf('gdrive') !== -1) {
            remoteProvider = 'gdrive';
            downloadHelperText = 'Downloading backup files from Google Drive';
        } else if (storageLocations.indexOf('s3') !== -1) {
            remoteProvider = 's3';
            downloadHelperText = 'Downloading backup files from Amazon S3';
        }

        // Load progress modal
        ROYALBR.loadProgressModal(context).then(function() {
            var $modal = $('#royalbr-progress-modal');

            // Reset modal state
            $modal.find('li').removeClass('active done error');
            $modal.find('.royalbr-component--progress').html('');
            $modal.find('.royalbr-restore-result').hide().removeClass('restore-success restore-error');
            $modal.find('.royalbr-restore-result .dashicons').removeClass('dashicons-yes dashicons-no-alt');
            $modal.find('#royalbr-progress-view-log, #royalbr-progress-done').hide();
            $modal.find('.royalbr-modal-header').css('justify-content', '');
            $modal.find('.royalbr-modal-header h3').show().text('Restoration in Progress');
            $modal.find('.royalbr-restore-subtitle').show();
            $modal.find('.royalbr-restore-components-list').show();
            $modal.removeClass('royalbr-finished');

            // Build dynamic component list
            var componentDefinitions = {
                'db': { label: 'Database', helper: 'Restoring database tables and content' },
                'plugins': { label: 'Plugins', helper: 'Extracting and installing plugin files' },
                'themes': { label: 'Themes', helper: 'Restoring theme files and configurations' },
                'uploads': { label: 'Uploads', helper: 'Restoring media library and uploaded files' },
                'others': { label: 'Others', helper: 'Restoring additional site content' },
                'wpcore': { label: 'WordPress Core', helper: 'Restoring WordPress core files' }
            };

            var componentsHTML = '';

            // Always show verification first
            componentsHTML += '<li data-component="verifying" class="active">';
            componentsHTML += '<div class="royalbr-component--wrapper">';
            componentsHTML += '<span class="royalbr-component--description">Verification</span>';
            componentsHTML += '<span class="royalbr-component--helper">Checking backup integrity and file availability</span>';
            componentsHTML += '</div>';
            componentsHTML += '<span class="royalbr-component--progress"></span>';
            componentsHTML += '</li>';

            // Add downloading step upfront for remote-only backups (no local storage)
            var hasLocal = storageLocations.indexOf('local') !== -1;
            var hasRemote = storageLocations.indexOf('gdrive') !== -1 ||
                           storageLocations.indexOf('dropbox') !== -1 ||
                           storageLocations.indexOf('s3') !== -1;
            if (!hasLocal && hasRemote) {
                componentsHTML += '<li data-component="downloading">';
                componentsHTML += '<div class="royalbr-component--wrapper">';
                componentsHTML += '<span class="royalbr-component--description">Downloading</span>';
                componentsHTML += '<span class="royalbr-component--helper">' + downloadHelperText + '</span>';
                componentsHTML += '</div>';
                componentsHTML += '<span class="royalbr-component--progress"></span>';
                componentsHTML += '</li>';
            }

            // For migration restores (remote-only), exclude wpcore from display
            var displayComponents = components;
            if (!hasLocal && hasRemote) {
                displayComponents = components.filter(function(c) { return c !== 'wpcore'; });
            }

            // Add selected components
            $.each(displayComponents, function(index, component) {
                if (componentDefinitions[component]) {
                    var def = componentDefinitions[component];
                    componentsHTML += '<li data-component="' + component + '">';
                    componentsHTML += '<div class="royalbr-component--wrapper">';
                    componentsHTML += '<span class="royalbr-component--description">' + def.label + '</span>';
                    componentsHTML += '<span class="royalbr-component--helper">' + def.helper + '</span>';
                    componentsHTML += '</div>';
                    componentsHTML += '<span class="royalbr-component--progress"></span>';
                    componentsHTML += '</li>';
                }
            });

            // Add finished component at the end
            componentsHTML += '<li data-component="finished">';
            componentsHTML += '<div class="royalbr-component--wrapper">';
            componentsHTML += '<span class="royalbr-component--description">Complete</span>';
            componentsHTML += '<span class="royalbr-component--helper">Finalizing restoration and cleaning up</span>';
            componentsHTML += '</div>';
            componentsHTML += '<span class="royalbr-component--progress"></span>';
            componentsHTML += '</li>';

            // Insert components into modal
            $modal.find('.royalbr-restore-components-list').html(componentsHTML);

            // Show modal
            $modal.show();

            // Step 1: Create restore task
            ROYALBR.createRestoreTask(timestamp, nonce, components, $modal);
        });
    };

    /**
     * Create restore task and get task_id.
     * @param {string} timestamp - Backup timestamp
     * @param {string} nonce - Backup nonce
     * @param {array} components - Selected components
     * @param {jQuery} $modal - Progress modal element
     */
    ROYALBR.createRestoreTask = function(timestamp, nonce, components, $modal) {
        var restoreData = {
            action: 'royalbr_ajax_restore',
            royalbr_ajax_restore: 'start_ajax_restore',
            timestamp: timestamp,
            backup_nonce: nonce,
            components: components,
            nonce: royalbr_ajax.nonce
        };

        // Include migration option if checked
        var $migrateCheckbox = $('#royalbr_restorer_replacesiteurl');
        if ($migrateCheckbox.length && $migrateCheckbox.is(':checked')) {
            restoreData.royalbr_restorer_replacesiteurl = 1;
        }

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: restoreData,
            success: function(response) {
                if (response.success && response.data.task_id) {
                    console.log('Restore task created with ID:', response.data.task_id);
                    // Step 2: Start streaming restore
                    ROYALBR.streamRestore(response.data.task_id, $modal);
                } else {
                    alert('Failed to create restore task: ' + (response.data || 'Unknown error'));
                    $modal.hide();
                }
            },
            error: function(xhr, status, error) {
                alert('Error creating restore task: ' + error);
                $modal.hide();
            }
        });
    };

    /**
     * Stream restore progress using XHR and parse RINFO messages.
     * @param {string} task_id - Restore task ID
     * @param {jQuery} $modal - Progress modal element
     */
    ROYALBR.streamRestore = function(task_id, $modal) {
        var xhttp = new XMLHttpRequest();
        var xhttp_data = 'action=royalbr_ajax_restore&royalbr_ajax_restore=do_ajax_restore&task_id=' + encodeURIComponent(task_id) + '&nonce=' + royalbr_ajax.nonce;
        var previous_data_length = 0;
        var show_alert = true;
        var restore_log_file = '';

        xhttp.open("POST", royalbr_ajax.ajax_url, true);

        xhttp.onprogress = function(response) {
            if (response.currentTarget.status >= 200 && response.currentTarget.status < 300) {
                if (-1 !== response.currentTarget.responseText.indexOf('<html')) {
                    if (show_alert) {
                        show_alert = false;
                        alert("ROYALBR: AJAX restore error - Invalid response");
                    }
                    console.log("ROYALBR restore error: HTML detected in response");
                    console.log(response.currentTarget.responseText);
                    return;
                }

                if (previous_data_length == response.currentTarget.responseText.length) return;

                var responseText = response.currentTarget.responseText.substr(previous_data_length);
                previous_data_length = response.currentTarget.responseText.length;

                var i = 0;
                var end_of_json = 0;

                // Check for RINFO messages
                while (i < responseText.length) {
                    var buffer = responseText.substr(i, 7);
                    if ('RINFO:{' == buffer) {
                        // Parse JSON after RINFO:
                        var analyse_it = window.ROYALBR.parseJson(responseText.substr(i + 6), true);

                        if (!analyse_it || !analyse_it.parsed) {
                            console.log('ROYALBR: Failed to parse RINFO, skipping');
                            i++;
                            continue;
                        }

                        console.log('ROYALBR: Processing RINFO:', analyse_it.parsed);
                        ROYALBR.processRestoreData(analyse_it.parsed, $modal);

                        // Move counter to end of JSON
                        end_of_json = i + analyse_it.json_last_pos + 6;
                        i = end_of_json;
                    } else {
                        i++;
                    }
                }
            } else {
                console.log("ROYALBR restore error: " + response.currentTarget.status + ' ' + response.currentTarget.statusText);
            }
        };

        xhttp.onload = function() {
            // Parse response to find result and log file
            var parser = new DOMParser();
            var doc = parser.parseFromString(xhttp.responseText, 'text/html');

            // Get log file path
            var logFileInput = doc.getElementById('royalbr_restore_log_file');
            if (logFileInput) {
                restore_log_file = logFileInput.value;
                $('#royalbr_restore_log_file').val(restore_log_file);
            }

            // Find success/error result
            var $successResult = $(doc).find('.royalbr_restore_successful');
            var $errorResult = $(doc).find('.royalbr_restore_error');
            var $result_output = $modal.find('.royalbr-restore-result');

            // Wait 1 second before showing completion
            setTimeout(function() {
                // Mark all active components as done
                $modal.find('li.active').removeClass('active').addClass('done');

                if ($successResult.length) {
                    // Store auto-login token for use when "Done" is clicked
                    var tokenInput = doc.getElementById('royalbr_auto_login_token');
                    if (tokenInput && tokenInput.value) {
                        $modal.data('royalbr-auto-login-token', tokenInput.value);
                    }

                    // Success
                    $modal.find('.royalbr-restore-components-list').hide();
                    $modal.find('.royalbr-modal-header').css('justify-content', 'center');
                    $modal.find('.royalbr-modal-header h3').text('Restore Finished');
                    $modal.find('.royalbr-restore-subtitle').hide();

                    $result_output.find('.dashicons').addClass('dashicons-yes');
                    $result_output.find('.royalbr-restore-result--text').text('Congratulations, your website has been successfully restored');
                    $result_output.addClass('restore-success');
                    $result_output.fadeIn(400);

                    // Mark modal as finished
                    $modal.addClass('royalbr-finished');

                    // Show buttons
                    $modal.find('#royalbr-progress-view-log, #royalbr-progress-done').fadeIn(400);
                } else if ($errorResult.length) {
                    // Error
                    $result_output.find('.dashicons').addClass('dashicons-no-alt');

                    // Show specific error message if available, otherwise show generic
                    var $errorMessages = $(doc).find('.royalbr_restore_errors');
                    if ($errorMessages.length && $errorMessages.text().trim()) {
                        // Show the specific error (e.g., "No space left on device")
                        $result_output.find('.royalbr-restore-result--text').text($errorMessages.text().trim());
                        $modal.find('.royalbr-restore-error-message').html($errorMessages.html()).show();
                    } else {
                        $result_output.find('.royalbr-restore-result--text').text($errorResult.text());
                    }

                    $result_output.addClass('restore-error');
                    $result_output.fadeIn(400);

                    // Mark modal as finished
                    $modal.addClass('royalbr-finished');

                    $modal.find('#royalbr-progress-view-log, #royalbr-progress-done').fadeIn(400);
                } else {
                    // Unknown state
                    $result_output.find('.dashicons').addClass('dashicons-no-alt');
                    $result_output.find('.royalbr-restore-result--text').text('Restore completed with unknown status');
                    $result_output.addClass('restore-error');
                    $result_output.fadeIn(400);

                    // Mark modal as finished
                    $modal.addClass('royalbr-finished');

                    $modal.find('#royalbr-progress-done').fadeIn(400);
                }
            }, 1000);
        };

        xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhttp.send(xhttp_data);
    };

    /**
     * Process restore data and update progress modal.
     * @param {object} restore_data - Parsed RINFO data
     * @param {jQuery} $modal - Progress modal element
     */
    ROYALBR.processRestoreData = function(restore_data, $modal) {
        if (restore_data && (restore_data.type == 'state' || restore_data.type == 'state_change')) {
            console.log('ROYALBR: Stage update -', restore_data.stage, restore_data.data);

            var current_stage;
            if (restore_data.stage == 'files') {
                current_stage = restore_data.data.entity;
            } else {
                current_stage = restore_data.stage;
            }

            var $current = $modal.find('[data-component="' + current_stage + '"]');

            // Handle downloading stage - add if missing, or update provider text if changed.
            if (restore_data.stage === 'downloading') {
                var provider = restore_data.data && restore_data.data.provider ? restore_data.data.provider : '';
                var helperText = 'Downloading backup files from cloud storage';
                if (provider === 'dropbox') {
                    helperText = 'Downloading backup files from Dropbox';
                } else if (provider === 'gdrive') {
                    helperText = 'Downloading backup files from Google Drive';
                } else if (provider === 's3') {
                    helperText = 'Downloading backup files from Amazon S3';
                }

                if ($current.length === 0) {
                    // Dynamically add downloading stage if it doesn't exist.
                    var downloadHTML = '<li data-component="downloading">';
                    downloadHTML += '<div class="royalbr-component--wrapper">';
                    downloadHTML += '<span class="royalbr-component--description">Downloading</span>';
                    downloadHTML += '<span class="royalbr-component--helper">' + helperText + '</span>';
                    downloadHTML += '</div>';
                    downloadHTML += '<span class="royalbr-component--progress"></span>';
                    downloadHTML += '</li>';

                    // Insert after verification stage.
                    $modal.find('[data-component="verifying"]').after(downloadHTML);
                    $current = $modal.find('[data-component="downloading"]');
                } else {
                    // Update helper text if provider changed (e.g., fallback to different cloud).
                    $current.find('.royalbr-component--helper').text(helperText);
                }
            }

            // Show progress info
            if (restore_data.stage == 'files' && restore_data.data && restore_data.data.fileindex) {
                $current.find('.royalbr-component--progress').html(' — Restoring file <strong>' + restore_data.data.fileindex + '</strong> of <strong>' + restore_data.data.total_files + '</strong>');
            }

            // Check if this is a new stage
            if (!$current.hasClass('active') && !$current.hasClass('done')) {
                // Mark previous stage as done
                $modal.find('li.active').each(function() {
                    $(this).find('.royalbr-component--progress').html('');
                    $(this).removeClass('active').addClass('done');
                });

                // Mark current stage
                if (current_stage === 'finished') {
                    // Mark ALL component stages as done when finished arrives
                    // (The onload handler will detect actual errors via HTML markers)
                    $modal.find('li').each(function() {
                        $(this).removeClass('active').addClass('done');
                    });
                } else {
                    $current.addClass('active');
                }
            }
        }
    };

    // NOTE: Utility functions (formatProgressText, parseJson, escapeHtml) are now in royalbr-utilities.js
    // and loaded globally. They are accessible via window.ROYALBR namespace.

    // ========================================================================
    // END GLOBAL ROYALBR NAMESPACE
    // ========================================================================

    // ========================================================================
    // SCHEDULED BACKUPS TEST HANDLERS (Premium Feature)
    // ========================================================================

    // Handle "Test Now" button for scheduled files backup
    $('#royalbr-test-scheduled-files').on('click', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $status = $('#royalbr-test-files-status');

        // Disable button and show loading state
        $button.prop('disabled', true).text('Testing...');
        $status.html('<span style="color: #0073aa;">Starting test backup...</span>');

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_test_scheduled_files',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');

                    // Clear status after 5 seconds
                    setTimeout(function() {
                        $status.html('');
                    }, 5000);
                } else {
                    var errorMsg = response.data || 'Test backup failed.';
                    $status.html('<span style="color: #dc3232;">✗ ' + errorMsg + '</span>');
                }
            },
            error: function() {
                $status.html('<span style="color: #dc3232;">✗ AJAX error occurred.</span>');
            },
            complete: function() {
                // Re-enable button
                $button.prop('disabled', false).text('Test Now');
            }
        });
    });

    // Handle "Test Now" button for scheduled database backup
    $('#royalbr-test-scheduled-db').on('click', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $status = $('#royalbr-test-db-status');

        // Disable button and show loading state
        $button.prop('disabled', true).text('Testing...');
        $status.html('<span style="color: #0073aa;">Starting test backup...</span>');

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_test_scheduled_database',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');

                    // Clear status after 5 seconds
                    setTimeout(function() {
                        $status.html('');
                    }, 5000);
                } else {
                    var errorMsg = response.data || 'Test backup failed.';
                    $status.html('<span style="color: #dc3232;">✗ ' + errorMsg + '</span>');
                }
            },
            error: function() {
                $status.html('<span style="color: #dc3232;">✗ AJAX error occurred.</span>');
            },
            complete: function() {
                // Re-enable button
                $button.prop('disabled', false).text('Test Now');
            }
        });
    });

    // ========================================================================
    // END SCHEDULED BACKUPS TEST HANDLERS
    // ========================================================================

    // ========================================================================
    // GOOGLE DRIVE SETTINGS TOGGLE
    // ========================================================================

    // Toggle Google Drive settings visibility when checkbox changes
    $('#royalbr_backup_loc_gdrive').on('change', function() {
        if ($(this).is(':checked')) {
            $('#royalbr-gdrive-settings').slideDown();
        } else {
            $('#royalbr-gdrive-settings').slideUp();
        }
    });

    // Sign in with Google button - save settings first, then proceed to auth
    $(document).on('click', '#royalbr-gdrive-signin', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $form = $('#royalbr-settings-form');

        // Disable button and show loading state
        $button.prop('disabled', true).addClass('royalbr-loading');

        // Gather settings (same logic as form submit)
        var settings = {};

        $form.find('input[type="checkbox"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).is(':checked') ? '1' : '0';
            }
        });

        $form.find('select').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        $form.find('input[type="number"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Collect text inputs from remote storage settings only
        $form.find('.royalbr-remote-settings input[type="text"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Save settings first
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_save_settings',
                nonce: royalbr_ajax.nonce,
                settings: settings
            },
            success: function(response) {
                if (response.success) {
                    // Settings saved, now get OAuth URL and redirect
                    $.ajax({
                        url: royalbr_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'royalbr_gdrive_get_auth_url',
                            nonce: royalbr_ajax.nonce
                        },
                        success: function(authResponse) {
                            if (authResponse.success && authResponse.data.auth_url) {
                                window.location.href = authResponse.data.auth_url;
                            } else {
                                alert('Failed to get authentication URL. Please try again.');
                                $button.prop('disabled', false).removeClass('royalbr-loading');
                            }
                        },
                        error: function() {
                            alert('An error occurred getting authentication URL. Please try again.');
                            $button.prop('disabled', false).removeClass('royalbr-loading');
                        }
                    });
                } else {
                    alert('Failed to save settings. Please try again.');
                    $button.prop('disabled', false).removeClass('royalbr-loading');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $button.prop('disabled', false).removeClass('royalbr-loading');
            }
        });
    });

    // Disconnect Google Drive button
    $(document).on('click', '#royalbr-gdrive-disconnect', function(e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to disconnect from Google Drive?')) {
            return;
        }

        var $button = $(this);
        $button.prop('disabled', true);

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_gdrive_disconnect',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Failed to disconnect. Please try again.');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $button.prop('disabled', false);
            }
        });
    });

    // ========================================================================
    // END GOOGLE DRIVE SETTINGS TOGGLE
    // ========================================================================

    // ========================================================================
    // DROPBOX SETTINGS TOGGLE
    // ========================================================================

    // Toggle Dropbox settings visibility when checkbox changes
    $('#royalbr_backup_loc_dropbox').on('change', function() {
        if ($(this).is(':checked')) {
            $('#royalbr-dropbox-settings').slideDown();
        } else {
            $('#royalbr-dropbox-settings').slideUp();
        }
    });

    // Connect to Dropbox button - save settings first, then proceed to auth
    $(document).on('click', '#royalbr-dropbox-signin', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $form = $('#royalbr-settings-form');

        // Disable button and show loading state
        $button.prop('disabled', true).addClass('royalbr-loading');

        // Gather settings (same logic as form submit)
        var settings = {};

        $form.find('input[type="checkbox"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).is(':checked') ? '1' : '0';
            }
        });

        $form.find('select').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        $form.find('input[type="number"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Collect text inputs from remote storage settings only
        $form.find('.royalbr-remote-settings input[type="text"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Save settings first
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_save_settings',
                nonce: royalbr_ajax.nonce,
                settings: settings
            },
            success: function(response) {
                if (response.success) {
                    // Settings saved, now get OAuth URL and redirect
                    $.ajax({
                        url: royalbr_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'royalbr_dropbox_get_auth_url',
                            nonce: royalbr_ajax.nonce
                        },
                        success: function(authResponse) {
                            if (authResponse.success && authResponse.data.auth_url) {
                                window.location.href = authResponse.data.auth_url;
                            } else {
                                alert('Failed to get authentication URL. Please try again.');
                                $button.prop('disabled', false).removeClass('royalbr-loading');
                            }
                        },
                        error: function() {
                            alert('An error occurred getting authentication URL. Please try again.');
                            $button.prop('disabled', false).removeClass('royalbr-loading');
                        }
                    });
                } else {
                    alert('Failed to save settings. Please try again.');
                    $button.prop('disabled', false).removeClass('royalbr-loading');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $button.prop('disabled', false).removeClass('royalbr-loading');
            }
        });
    });

    // Disconnect Dropbox button
    $(document).on('click', '#royalbr-dropbox-disconnect', function(e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to disconnect from Dropbox?')) {
            return;
        }

        var $button = $(this);
        $button.prop('disabled', true);

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_dropbox_disconnect',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Failed to disconnect. Please try again.');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $button.prop('disabled', false);
            }
        });
    });

    // ========================================================================
    // END DROPBOX SETTINGS TOGGLE
    // ========================================================================

    // ========================================================================
    // AMAZON S3 SETTINGS TOGGLE
    // ========================================================================

    // Toggle S3 settings visibility when checkbox changes
    $('#royalbr_backup_loc_s3').on('change', function() {
        if ($(this).is(':checked')) {
            $('#royalbr-s3-settings').slideDown();
        } else {
            $('#royalbr-s3-settings').slideUp();
        }
    });

    // Test S3 Connection button - save settings first, then test
    $(document).on('click', '#royalbr-s3-test', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $form = $('#royalbr-settings-form');
        var originalText = $button.text();

        // Disable button and show loading state
        $button.prop('disabled', true).addClass('royalbr-loading').text('Testing...');

        // Gather all form settings (same logic as form submit)
        var settings = {};

        $form.find('input[type="checkbox"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).is(':checked') ? '1' : '0';
            }
        });

        $form.find('select').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        $form.find('input[type="number"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Collect text inputs from remote storage settings
        $form.find('.royalbr-remote-settings input[type="text"], .royalbr-remote-settings input[type="password"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Save settings first
        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_save_settings',
                nonce: royalbr_ajax.nonce,
                settings: settings
            },
            success: function(response) {
                if (response.success) {
                    // Settings saved, now test connection
                    $.ajax({
                        url: royalbr_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'royalbr_s3_test_connection',
                            nonce: royalbr_ajax.nonce,
                            settings: {
                                royalbr_s3_access_key: $('#royalbr_s3_access_key').val(),
                                royalbr_s3_secret_key: $('#royalbr_s3_secret_key').val(),
                                royalbr_s3_location: $('#royalbr_s3_location').val()
                            }
                        },
                        success: function(testResponse) {
                            if (testResponse.success) {
                                // Reload the page to show connected state
                                location.reload();
                            } else {
                                alert('Connection failed: ' + (testResponse.data || 'Unknown error'));
                                $button.prop('disabled', false).removeClass('royalbr-loading').text(originalText);
                            }
                        },
                        error: function() {
                            alert('An error occurred while testing. Please try again.');
                            $button.prop('disabled', false).removeClass('royalbr-loading').text(originalText);
                        }
                    });
                } else {
                    alert('Failed to save settings: ' + (response.data || 'Unknown error'));
                    $button.prop('disabled', false).removeClass('royalbr-loading').text(originalText);
                }
            },
            error: function() {
                alert('An error occurred while saving. Please try again.');
                $button.prop('disabled', false).removeClass('royalbr-loading').text(originalText);
            }
        });
    });

    // Disconnect S3 button
    $(document).on('click', '#royalbr-s3-disconnect', function(e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to disconnect Amazon S3?')) {
            return;
        }

        var $button = $(this);
        $button.prop('disabled', true).addClass('royalbr-loading');

        $.ajax({
            url: royalbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_s3_disconnect',
                nonce: royalbr_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Reload the page to show disconnected state
                    location.reload();
                } else {
                    alert('Failed to disconnect: ' + (response.data || 'Unknown error'));
                    $button.prop('disabled', false).removeClass('royalbr-loading');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $button.prop('disabled', false).removeClass('royalbr-loading');
            }
        });
    });

    // ========================================================================
    // END AMAZON S3 SETTINGS TOGGLE
    // ========================================================================

    // Default strings (will be overridden by localized script)
    if (typeof royalbr_admin === 'undefined') {
        window.royalbr_admin = {
            strings: {
                preparing: 'Initializing backup process...',
                backup_complete: 'Backup process finished!',
                backup_created: 'Your website backup is complete',
                backup_failed: 'Backup process encountered an error',
                restore_success: 'Site restoration completed',
                restore_failed: 'Restoration process encountered an error',
                delete_success: 'Backup removed successfully',
                delete_failed: 'Failed to remove backup',
                ajax_error: 'Operation failed. Please retry.',
                confirm_restore_title: 'Verify Restoration',
                confirm_restore_message: 'Proceed with restoring from backup "%s"? This will replace your current site data.',
                confirm_delete_title: 'Verify Deletion',
                confirm_delete_message: 'Remove backup "%s"? This operation is permanent.',
                no_backups: 'No backups available yet. Use the "Create Backup" tab to generate your first backup.',
                reset_success: 'Database reset process completed',
                reset_failed: 'Database reset encountered an error',
                confirm_reset_title: 'Verify Database Reset',
                confirm_reset_message: 'Are you certain you want to reset your database? This will permanently erase all content, configurations, and extensions. Only your admin account will remain. This operation cannot be reversed!',
                resetting_database: 'Processing database reset...',
                please_wait: 'Operation in progress. Do not close this window...'
            }
        };
    }
});