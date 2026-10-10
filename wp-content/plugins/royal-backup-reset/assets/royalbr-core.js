/**
 * Royal Backup & Reset - Core Functions
 *
 * Shared backup/restore functions used across admin.js and admin-bar.js.
 * This file loads globally on all admin pages to support Quick Actions.
 *
 * @package Royal_Backup_Reset
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Ensure ROYALBR namespace exists (created by royalbr-utilities.js)
    window.ROYALBR = window.ROYALBR || {};

    // Get AJAX config based on context
    function getAjaxConfig() {
        // Use royalbr_ajax on admin page, royalbr_admin_bar on other pages
        return typeof royalbr_ajax !== 'undefined' ? royalbr_ajax : royalbr_admin_bar;
    }

    // Timer for backup progress polling (using setTimeout for adaptive intervals)
    ROYALBR.backupProgressTimer = null;

    // Adaptive polling configuration
    ROYALBR.pollRetryCount = 0;
    ROYALBR.maxPollRetries = 5;
    ROYALBR.pollInterval = 2000; // Start at 2 seconds
    ROYALBR.lastProgressData = null;
    ROYALBR.backupContext = 'admin-page';

    // Track backup mode for progress calculation
    ROYALBR.backupIncludeDb = true;
    ROYALBR.backupIncludeFiles = true;

    /**
     * Schedule the next progress poll with adaptive interval.
     */
    ROYALBR.scheduleNextPoll = function() {
        if (ROYALBR.backupProgressTimer) {
            clearTimeout(ROYALBR.backupProgressTimer);
        }
        ROYALBR.backupProgressTimer = setTimeout(function() {
            ROYALBR.pollBackupProgress(ROYALBR.backupContext);
        }, ROYALBR.pollInterval);
    };

    /**
     * Stop polling and clean up.
     */
    ROYALBR.stopPolling = function() {
        if (ROYALBR.backupProgressTimer) {
            clearTimeout(ROYALBR.backupProgressTimer);
            ROYALBR.backupProgressTimer = null;
        }
        ROYALBR.pollRetryCount = 0;
        ROYALBR.pollInterval = 2000;
        ROYALBR.lastProgressData = null;
    };

    /**
     * Load backup progress modal.
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.loadBackupProgressModal = function(context) {
        context = context || 'admin-page';

        if ($('#royalbr-backup-progress-modal').length) {
            return Promise.resolve();
        }

        var config = getAjaxConfig();

        return $.ajax({
            url: config.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_progress_modal_html',
                context: context,
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                }
            }
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

        // Store backup mode for progress calculation
        ROYALBR.backupIncludeDb = includeDb;
        ROYALBR.backupIncludeFiles = includeFiles || includeWpcore;

        // Reset last known entity for fresh progress tracking
        ROYALBR.lastKnownEntity = null;

        console.log('ROYALBR.startBackup called with:', backupName, includeFiles, context, includeDb, includeWpcore);

        var config = getAjaxConfig();

        // Load backup progress modal
        ROYALBR.loadBackupProgressModal(context).then(function() {
            var $modal = $('#royalbr-backup-progress-modal');

            // Reset progress UI
            $modal.find('.royalbr-progress-fill').css('width', '0%');
            $modal.find('.royalbr-progress-text').text('Initializing backup...');
            $modal.find('#royalbr-backup-progress-view-log, #royalbr-backup-progress-done').hide();
            $modal.removeClass('royalbr-finished');

            // Show modal
            $modal.show();

            // Reset polling state and start adaptive polling
            ROYALBR.stopPolling();
            ROYALBR.backupContext = context;
            ROYALBR.scheduleNextPoll();

            // Initiate backup via AJAX
            $.ajax({
                url: config.ajax_url,
                type: 'POST',
                data: {
                    action: 'royalbr_create_backup',
                    nonce: config.nonce,
                    include_db: includeDb ? 1 : 0,
                    include_files: includeFiles ? 1 : 0,
                    include_wpcore: includeWpcore ? 1 : 0,
                    backup_name: backupName || ''
                },
                success: function(response) {
                    if (!response.success) {
                        console.log('ROYALBR: Backup start failed:', response.data);
                        ROYALBR.stopPolling();

                        // Show error in modal
                        $modal.find('.royalbr-progress-text').text('Backup failed: ' + (response.data || 'Unknown error'));
                        $modal.find('#royalbr-backup-progress-done').show();
                    }
                },
                error: function() {
                    ROYALBR.stopPolling();

                    // Show error in modal
                    $modal.find('.royalbr-progress-text').text('Backup failed: Connection error');
                    $modal.find('#royalbr-backup-progress-done').show();
                }
            });
        });
    };

    /**
     * Poll for backup progress updates with adaptive interval and retry logic.
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.pollBackupProgress = function(context) {
        context = context || 'admin-page';

        var config = getAjaxConfig();

        $.ajax({
            url: config.ajax_url,
            type: 'POST',
            timeout: 30000, // 30 second timeout to prevent hung requests
            data: {
                action: 'royalbr_get_backup_progress',
                nonce: config.nonce,
                oneshot: 1
            },
            success: function(response) {
                console.log('ROYALBR: Progress poll response:', response);

                // Reset retry count on successful response
                ROYALBR.pollRetryCount = 0;

                var $modal = $('#royalbr-backup-progress-modal');

                if (response.success && response.data.running) {
                    // Check for backup error - display and stop polling
                    if (response.data.backup_error) {
                        console.log('ROYALBR: Backup error detected:', response.data.backup_error);
                        ROYALBR.stopPolling();

                        // Show error state with full error message
                        $modal.find('.royalbr-progress-fill').css('width', '100%').addClass('royalbr-progress-error');
                        $modal.find('.royalbr-progress-text').text('Backup failed: ' + response.data.backup_error).addClass('royalbr-error-text');

                        // Mark modal as finished and show buttons
                        $modal.addClass('royalbr-finished');
                        $modal.find('#royalbr-backup-progress-view-log, #royalbr-backup-progress-done').show();
                        return;
                    }

                    var taskstatus = response.data.taskstatus || 'begun';
                    var filecreating = response.data.filecreating_substatus;
                    var dbcreating = response.data.dbcreating_substatus;

                    var substatus = null;
                    if (taskstatus === 'filescreating' && filecreating) {
                        substatus = filecreating;
                    } else if (taskstatus === 'dbcreating' && dbcreating) {
                        substatus = dbcreating;
                    }

                    // Format and update progress (pass backup mode for correct percentages)
                    var progress = window.ROYALBR.formatProgressText(taskstatus, substatus, ROYALBR.backupIncludeDb, ROYALBR.backupIncludeFiles);
                    console.log('ROYALBR: Progress update:', progress.text, progress.percent + '%');

                    $modal.find('.royalbr-progress-fill').css('width', progress.percent + '%');
                    window.ROYALBR.updateProgressText($modal.find('.royalbr-progress-text'), progress.text, progress.showDots);

                    // Adaptive polling: adjust interval based on whether data changed
                    var currentData = JSON.stringify(response.data);
                    if (currentData === ROYALBR.lastProgressData) {
                        // No change - slow down polling (max 7.5 seconds)
                        ROYALBR.pollInterval = Math.min(ROYALBR.pollInterval + 1000, 7500);
                    } else {
                        // Change detected - poll faster (2 seconds)
                        ROYALBR.pollInterval = 2000;
                    }
                    ROYALBR.lastProgressData = currentData;

                    // Schedule next poll
                    ROYALBR.scheduleNextPoll();

                } else {
                    console.log('ROYALBR: Backup stopped');
                    // Backup stopped - stop polling
                    ROYALBR.stopPolling();

                    // Check if backup failed with error
                    if (response.data.backup_error) {
                        console.log('ROYALBR: Backup failed with error:', response.data.backup_error);
                        // Show error state with full error message
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
            },
            error: function(xhr, status, error) {
                console.log('ROYALBR: Progress poll error:', status, error);

                ROYALBR.pollRetryCount++;
                var $modal = $('#royalbr-backup-progress-modal');

                if (ROYALBR.pollRetryCount >= ROYALBR.maxPollRetries) {
                    // Max retries reached - stop polling but don't assume backup failed
                    console.log('ROYALBR: Max retries reached, stopping polling');
                    ROYALBR.stopPolling();

                    // Show informative message - backup may still be running
                    $modal.find('.royalbr-progress-text').text('Connection lost. Backup may still be running in background. Refresh the page to check status.');
                    $modal.find('#royalbr-backup-progress-done').show();
                } else {
                    // Retry with exponential backoff
                    console.log('ROYALBR: Retrying poll (' + ROYALBR.pollRetryCount + '/' + ROYALBR.maxPollRetries + ')');
                    ROYALBR.pollInterval = Math.min(ROYALBR.pollInterval * 1.5, 10000);
                    ROYALBR.scheduleNextPoll();
                }
            }
        });
    };

    /**
     * Load component selection modal (for restore operations).
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.loadComponentSelectionModal = function(context) {
        context = context || 'admin-page';

        if ($('#royalbr-component-selection-modal').length) {
            return Promise.resolve();
        }

        var config = getAjaxConfig();

        return $.ajax({
            url: config.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_component_selection_modal_html',
                context: context,
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
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

        var config = getAjaxConfig();

        return $.ajax({
            url: config.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_progress_modal_html',
                context: context,
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                }
            }
        });
    };

    /**
     * Start restore process.
     * @param {string} timestamp - Backup timestamp
     * @param {string} nonce - Backup nonce
     * @param {array} components - Selected components to restore
     * @param {string} context - 'admin-page' or 'quick-actions'
     * @param {boolean} isRemote - Whether this is a remote-only backup
     */
    ROYALBR.startRestore = function(timestamp, nonce, components, context, isRemote) {
        context = context || 'admin-page';
        isRemote = isRemote || false;

        console.log('ROYALBR.startRestore called with:', timestamp, nonce, components, context, isRemote);

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
            $modal.find('.royalbr-modal-footer').removeClass('royalbr-modal-footer-centered');
            $modal.removeClass('royalbr-finished');

            // Build dynamic component list
            var componentDefinitions = {
                'db': { label: 'Database', helper: 'Restoring database tables and content' },
                'plugins': { label: 'Plugins', helper: 'Extracting and installing plugin files' },
                'themes': { label: 'Themes', helper: 'Restoring theme files and configurations' },
                'uploads': { label: 'Uploads', helper: 'Restoring media library and uploaded files' },
                'others': { label: 'Others', helper: 'Restoring additional site content' }
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

            // Add downloading stage for remote backups
            if (isRemote) {
                componentsHTML += '<li data-component="downloading">';
                componentsHTML += '<div class="royalbr-component--wrapper">';
                componentsHTML += '<span class="royalbr-component--description">Downloading</span>';
                componentsHTML += '<span class="royalbr-component--helper">Downloading backup files from Google Drive</span>';
                componentsHTML += '</div>';
                componentsHTML += '<span class="royalbr-component--progress"></span>';
                componentsHTML += '</li>';
            }

            // Add selected components
            $.each(components, function(index, component) {
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
        var config = getAjaxConfig();

        $.ajax({
            url: config.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_ajax_restore',
                royalbr_ajax_restore: 'start_ajax_restore',
                timestamp: timestamp,
                backup_nonce: nonce,
                components: components,
                nonce: config.nonce
            },
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
        var config = getAjaxConfig();

        var xhttp = new XMLHttpRequest();
        var xhttp_data = 'action=royalbr_ajax_restore&royalbr_ajax_restore=do_ajax_restore&task_id=' + encodeURIComponent(task_id) + '&nonce=' + config.nonce;
        var previous_data_length = 0;
        var show_alert = true;
        var restore_log_file = '';

        xhttp.open("POST", config.ajax_url, true);

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

                    // Center buttons in footer - use direct selector and log for debugging
                    console.log('ROYALBR: Adding centered class to footer');
                    var $footer = $modal.find('.royalbr-modal-footer');
                    console.log('ROYALBR: Footer element found:', $footer.length);
                    $footer.addClass('royalbr-modal-footer-centered');
                    console.log('ROYALBR: Footer classes after add:', $footer.attr('class'));

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

                    // Center buttons in footer
                    $modal.find('.royalbr-modal-footer').addClass('royalbr-modal-footer-centered');

                    $modal.find('#royalbr-progress-view-log, #royalbr-progress-done').fadeIn(400);
                } else {
                    // Unknown state
                    $result_output.find('.dashicons').addClass('dashicons-no-alt');
                    $result_output.find('.royalbr-restore-result--text').text('Restore completed with unknown status');
                    $result_output.addClass('restore-error');
                    $result_output.fadeIn(400);

                    // Mark modal as finished
                    $modal.addClass('royalbr-finished');

                    // Center button in footer
                    $modal.find('.royalbr-modal-footer').addClass('royalbr-modal-footer-centered');

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

            // Show progress info for files stage
            if (restore_data.stage == 'files' && restore_data.data && restore_data.data.fileindex) {
                $current.find('.royalbr-component--progress').html(' — Restoring file <strong>' + restore_data.data.fileindex + '</strong> of <strong>' + restore_data.data.total_files + '</strong>');
            }

            // Show progress info for downloading stage
            if (restore_data.stage == 'downloading' && restore_data.data) {
                if (restore_data.data.current && restore_data.data.total_files) {
                    $current.find('.royalbr-component--progress').html(' — Downloading file <strong>' + restore_data.data.current + '</strong> of <strong>' + restore_data.data.total_files + '</strong>');
                }
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

    /**
     * Load reset progress modal.
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.loadResetProgressModal = function(context) {
        context = context || 'admin-page';

        if ($('#royalbr-reset-progress-modal').length) {
            return Promise.resolve();
        }

        var config = getAjaxConfig();

        return $.ajax({
            url: config.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_reset_progress_modal_html',
                context: context,
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                }
            }
        });
    };

    /**
     * Start reset process with live progress.
     * @param {object} options - Reset options {reactivate_theme, reactivate_plugins, keep_royalbr_active, clear_uploads, clear_media}
     * @param {string} context - 'admin-page' or 'quick-actions'
     */
    ROYALBR.startReset = function(options, context) {
        context = context || 'admin-page';

        console.log('ROYALBR.startReset called with:', options, context);

        var config = getAjaxConfig();

        // Load reset progress modal
        ROYALBR.loadResetProgressModal(context).then(function() {
            var $modal = $('#royalbr-reset-progress-modal');

            // Reset modal state
            $modal.find('li').removeClass('active done error');
            $modal.find('.royalbr-component--progress').html('');
            $modal.find('.royalbr-restore-result').hide().removeClass('restore-success restore-error');
            $modal.find('.royalbr-restore-result .dashicons').removeClass('dashicons-yes dashicons-no-alt');
            $modal.find('#royalbr-reset-progress-done').hide();
            $modal.find('.royalbr-modal-header').css('justify-content', '');
            $modal.find('.royalbr-modal-header h3').text('Reset in Progress');
            $modal.find('.royalbr-reset-subtitle').show();
            $modal.find('.royalbr-restore-components-list').show();
            $modal.find('.royalbr-modal-footer').removeClass('royalbr-modal-footer-centered');
            $modal.removeClass('royalbr-finished');

            // Build dynamic component list based on options
            var componentsHTML = '';

            // Always show: Preparing → Dropping → Creating → Finished
            componentsHTML += '<li data-component="reset_preparing" class="active">';
            componentsHTML += '<div class="royalbr-component--wrapper">';
            componentsHTML += '<span class="royalbr-component--description">Preparing</span>';
            componentsHTML += '<span class="royalbr-component--helper">Saving current settings</span>';
            componentsHTML += '</div>';
            componentsHTML += '<span class="royalbr-component--progress"></span>';
            componentsHTML += '</li>';

            componentsHTML += '<li data-component="reset_dropping">';
            componentsHTML += '<div class="royalbr-component--wrapper">';
            componentsHTML += '<span class="royalbr-component--description">Dropping Tables</span>';
            componentsHTML += '<span class="royalbr-component--helper">Removing existing database tables</span>';
            componentsHTML += '</div>';
            componentsHTML += '<span class="royalbr-component--progress"></span>';
            componentsHTML += '</li>';

            componentsHTML += '<li data-component="reset_creating">';
            componentsHTML += '<div class="royalbr-component--wrapper">';
            componentsHTML += '<span class="royalbr-component--description">Creating Database</span>';
            componentsHTML += '<span class="royalbr-component--helper">Installing fresh WordPress database</span>';
            componentsHTML += '</div>';
            componentsHTML += '<span class="royalbr-component--progress"></span>';
            componentsHTML += '</li>';

            // Conditionally show reactivating step if theme OR plugins are being reactivated
            if (options.reactivate_theme || options.reactivate_plugins) {
                componentsHTML += '<li data-component="reset_reactivating">';
                componentsHTML += '<div class="royalbr-component--wrapper">';
                componentsHTML += '<span class="royalbr-component--description">Reactivating</span>';
                componentsHTML += '<span class="royalbr-component--helper">Restoring theme and plugins</span>';
                componentsHTML += '</div>';
                componentsHTML += '<span class="royalbr-component--progress"></span>';
                componentsHTML += '</li>';
            }

            // Conditionally show cleaning step if clear_uploads or clear_media is enabled
            if (options.clear_uploads || options.clear_media) {
                componentsHTML += '<li data-component="reset_cleaning">';
                componentsHTML += '<div class="royalbr-component--wrapper">';
                componentsHTML += '<span class="royalbr-component--description">' + (options.clear_uploads ? 'Cleaning Uploads' : 'Clearing Media Files') + '</span>';
                componentsHTML += '<span class="royalbr-component--helper">' + (options.clear_uploads ? 'Removing upload directory contents' : 'Removing media folders') + '</span>';
                componentsHTML += '</div>';
                componentsHTML += '<span class="royalbr-component--progress"></span>';
                componentsHTML += '</li>';
            }

            // Always show finished
            componentsHTML += '<li data-component="reset_finished">';
            componentsHTML += '<div class="royalbr-component--wrapper">';
            componentsHTML += '<span class="royalbr-component--description">Complete</span>';
            componentsHTML += '<span class="royalbr-component--helper">Reset complete!</span>';
            componentsHTML += '</div>';
            componentsHTML += '<span class="royalbr-component--progress"></span>';
            componentsHTML += '</li>';

            // Insert components into modal
            $modal.find('.royalbr-restore-components-list').html(componentsHTML);

            // Show modal
            $modal.show();

            // Start reset with streaming RINFO
            ROYALBR.streamReset(options, $modal);
        });
    };

    /**
     * Stream reset progress using XHR and parse RINFO messages.
     * @param {object} options - Reset options
     * @param {jQuery} $modal - Progress modal element
     */
    ROYALBR.streamReset = function(options, $modal) {
        var config = getAjaxConfig();

        var xhttp = new XMLHttpRequest();
        var xhttp_data = 'action=royalbr_reset_database&nonce=' + config.nonce;
        xhttp_data += '&reactivate_theme=' + (options.reactivate_theme ? '1' : '0');
        xhttp_data += '&reactivate_plugins=' + (options.reactivate_plugins ? '1' : '0');
        xhttp_data += '&keep_royalbr_active=' + (options.keep_royalbr_active ? '1' : '0');
        xhttp_data += '&clear_uploads=' + (options.clear_uploads ? '1' : '0');
        xhttp_data += '&clear_media=' + (options.clear_media ? '1' : '0');

        var previous_data_length = 0;
        var show_alert = true;

        xhttp.open("POST", config.ajax_url, true);

        xhttp.onprogress = function(response) {
            if (response.currentTarget.status >= 200 && response.currentTarget.status < 300) {
                if (-1 !== response.currentTarget.responseText.indexOf('<html')) {
                    if (show_alert) {
                        show_alert = false;
                        alert("ROYALBR: AJAX reset error - Invalid response");
                    }
                    console.log("ROYALBR reset error: HTML detected in response");
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

                        console.log('ROYALBR: Processing reset RINFO:', analyse_it.parsed);
                        ROYALBR.processResetData(analyse_it.parsed, $modal);

                        // Move counter to end of JSON
                        end_of_json = i + analyse_it.json_last_pos + 6;
                        i = end_of_json;
                    } else {
                        i++;
                    }
                }
            } else {
                console.log("ROYALBR reset error: " + response.currentTarget.status + ' ' + response.currentTarget.statusText);
            }
        };

        xhttp.onload = function() {
            // Parse response to check for success/error
            var responseText = xhttp.responseText;

            // Simple check for success/error in response
            var isSuccess = responseText.indexOf('"success":true') !== -1;
            var $result_output = $modal.find('.royalbr-restore-result');

            // Re-authenticate user to restore session after database reset
            if (isSuccess) {
                // Parse JSON response to extract reauth token and user_id
                var jsonMatch = responseText.match(/\{[^}]*"success"\s*:\s*true[^}]*"reauth_token"\s*:\s*"([^"]+)"[^}]*"user_id"\s*:\s*(\d+)[^}]*\}/);

                if (jsonMatch && jsonMatch[1] && jsonMatch[2]) {
                    var reauthToken = jsonMatch[1];
                    var userId = jsonMatch[2];

                    console.log('ROYALBR: Extracted reauth token and user_id:', reauthToken, userId);

                    $.ajax({
                        url: config.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'royalbr_reauth_after_reset',
                            token: reauthToken,
                            user_id: userId
                        },
                        success: function(response) {
                            console.log('ROYALBR: Re-authentication result:', response);
                        },
                        error: function(xhr, status, error) {
                            console.log('ROYALBR: Re-authentication failed:', error);
                        }
                    });
                } else {
                    console.log('ROYALBR: Failed to extract reauth token from response');
                }
            }

            // Wait 1 second before showing completion
            setTimeout(function() {
                // Mark all active components as done
                $modal.find('li.active').removeClass('active').addClass('done');

                // Hide components list and subtitle
                $modal.find('.royalbr-restore-components-list').hide();
                $modal.find('.royalbr-reset-subtitle').hide();
                $modal.find('.royalbr-modal-header').css('justify-content', 'center');
                $modal.find('.royalbr-modal-header h3').text('Reset Complete');

                if (isSuccess) {
                    // Success
                    $result_output.find('.dashicons').addClass('dashicons-yes');
                    $result_output.find('.royalbr-restore-result--text').text('Database reset completed successfully. You are still logged in as the administrator.');
                    $result_output.addClass('restore-success');
                    $result_output.fadeIn(400);
                } else {
                    // Error
                    $result_output.find('.dashicons').addClass('dashicons-no-alt');
                    $result_output.find('.royalbr-restore-result--text').text('Reset failed. Please check the error message and try again.');
                    $result_output.addClass('restore-error');
                    $result_output.fadeIn(400);
                }

                // Mark modal as finished
                $modal.addClass('royalbr-finished');

                // Center button in footer
                $modal.find('.royalbr-modal-footer').addClass('royalbr-modal-footer-centered');

                // Show Done button
                $modal.find('#royalbr-reset-progress-done').fadeIn(400);
            }, 1000);
        };

        xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhttp.send(xhttp_data);
    };

    /**
     * Process reset data and update progress modal.
     * @param {object} reset_data - Parsed RINFO data
     * @param {jQuery} $modal - Progress modal element
     */
    ROYALBR.processResetData = function(reset_data, $modal) {
        if (reset_data && (reset_data.type == 'state' || reset_data.type == 'state_change')) {
            console.log('ROYALBR: Reset stage update -', reset_data.stage, reset_data.data);

            var current_stage = reset_data.stage;
            var $current = $modal.find('[data-component="' + current_stage + '"]');

            // Check if this is a new stage
            if (!$current.hasClass('active') && !$current.hasClass('done')) {
                // Mark previous stage as done
                $modal.find('li.active').each(function() {
                    $(this).find('.royalbr-component--progress').html('');
                    $(this).removeClass('active').addClass('done');
                });

                // Mark current stage
                if (current_stage === 'reset_finished') {
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

})(jQuery);
