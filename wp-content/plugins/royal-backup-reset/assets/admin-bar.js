/**
 * Royal Backup & Reset - Admin Bar JavaScript
 *
 * Handles admin bar button clicks for quick backup/restore/reset actions.
 */

jQuery(document).ready(function($) {
    'use strict';

    // Multisite support check - disable popup actions (safety fallback)
    if (typeof royalbr_admin_bar !== 'undefined' && royalbr_admin_bar.is_multisite) {
        var multisiteMessage = 'This plugin does not support WordPress Multisite yet, but it is coming soon!';

        $(document).on('click', '#royalbr-backup-popup-btn, #royalbr-reset-popup-btn, .royalbr-restore-from-popup', function(e) {
            e.preventDefault();
            e.stopPropagation();
            alert(multisiteMessage);
            return false;
        });
    }

    // Variables for backup popup
    var $backupPopup = null;
    var popupHoverTimeout = null;
    var isPopupLoaded = false;
    var popupData = null;

    // Variables for reset popup
    var $resetPopup = null;
    var resetPopupHoverTimeout = null;

    // Variables for backup progress polling
    var backupProgressTimer = null;

    // Pro promo rotation for admin bar modal
    var royalbrModalPromoTexts = [
        '<b>Upgrade to Pro</b> to schedule <b>Automatic Backups</b> - daily, weekly, or monthly',
        'Store your backups securely on <b>Google Drive, Dropbox and Amazon S3</b> with <b>Pro Version</b>',
        '<b>Pro Version</b> lets you choose exactly what to backup - <b>Database</b>, <b>Plugins</b>, <b>Themes</b>, <b>Uploads</b>, or <b>WP Core</b>',
        'Customize what to restore with <b>Selective Restore</b> in <b>Pro Version</b>',
        'Protect your site during <b>WordPress Updates</b> with <b>Pro Version</b>',
        'Get <b>Priority Support</b> directly from developers with <b>Pro Version</b>'
    ];
    var royalbr_modal_promo_timer = null;
    var royalbr_modal_promo_index = 0;

    function royalbrStartModalPromoRotation() {
        if (royalbr_admin_bar.is_premium) {
            return;
        }
        var $promo = $('#royalbr-modal-pro-promo-text');
        if (!$promo.length) {
            return;
        }
        royalbr_modal_promo_index = Math.floor(Math.random() * royalbrModalPromoTexts.length);
        $promo.html(royalbrModalPromoTexts[royalbr_modal_promo_index]).fadeIn(400);
        royalbr_modal_promo_timer = setInterval(function() {
            var nextIndex;
            do {
                nextIndex = Math.floor(Math.random() * royalbrModalPromoTexts.length);
            } while (nextIndex === royalbr_modal_promo_index && royalbrModalPromoTexts.length > 1);
            royalbr_modal_promo_index = nextIndex;
            $promo.fadeOut(400, function() {
                $(this).html(royalbrModalPromoTexts[royalbr_modal_promo_index]).fadeIn(400);
            });
        }, 5000);
    }

    function royalbrStopModalPromoRotation() {
        if (royalbr_modal_promo_timer) {
            clearInterval(royalbr_modal_promo_timer);
            royalbr_modal_promo_timer = null;
        }
        $('#royalbr-modal-pro-promo-text').fadeOut(400);
    }

    // Loading guards to prevent duplicate AJAX calls
    var backupModalLoading = false;
    var backupProgressModalLoading = false;

    // Start backup progress polling
    function startBackupProgressPolling() {
        // Clear any existing timer
        if (backupProgressTimer) {
            clearInterval(backupProgressTimer);
        }

        // Start polling every second
        backupProgressTimer = setInterval(function() {
            updateBackupProgress();
        }, 1000);
    }

    // Stop backup progress polling
    function stopBackupProgressPolling() {
        if (backupProgressTimer) {
            clearInterval(backupProgressTimer);
            backupProgressTimer = null;
        }
    }

    // Update backup progress
    function updateBackupProgress() {
        var $progressModal = $('#royalbr-backup-progress-modal');

        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_progress',
                nonce: royalbr_admin_bar.nonce,
                oneshot: 1
            },
            success: function(response) {
                if (response.success && response.data.running) {
                    var taskstatus = response.data.taskstatus || 'begun';
                    var filecreating = response.data.filecreating_substatus;
                    var dbcreating = response.data.dbcreating_substatus;

                    // Determine which substatus to use
                    var substatus = null;
                    if (taskstatus === 'filescreating' && filecreating) {
                        substatus = filecreating;
                    } else if (taskstatus === 'dbcreating' && dbcreating) {
                        substatus = dbcreating;
                    }

                    // Format and update progress
                    var progress = window.ROYALBR.formatProgressText(taskstatus, substatus, ROYALBR.backupIncludeDb, ROYALBR.backupIncludeFiles);
                    $progressModal.find('.royalbr-progress-fill').css('width', progress.percent + '%');
                    window.ROYALBR.updateProgressText($progressModal.find('.royalbr-progress-text'), progress.text, progress.showDots);

                } else {
                    // Backup complete or not running - stop polling
                    if (backupProgressTimer) {
                        clearInterval(backupProgressTimer);
                        backupProgressTimer = null;

                        // Check if backup failed with error
                        if (response.data && response.data.backup_error) {
                            console.log('ROYALBR: Backup failed with error:', response.data.backup_error);
                            $progressModal.find('.royalbr-progress-fill').css('width', '100%').addClass('royalbr-progress-error');
                            $progressModal.find('.royalbr-progress-text').text('Backup failed: ' + response.data.backup_error).addClass('royalbr-error-text');
                            $progressModal.find('.royalbr-modal-header h3').text('Backup Failed');
                        } else {
                            // Update to 100% complete - success
                            $progressModal.find('.royalbr-progress-fill').css('width', '100%');
                            $progressModal.find('.royalbr-progress-text').text('Backup process finished!');
                            $progressModal.find('.royalbr-modal-header h3').text('Backup Finished');
                        }

                        // Mark modal as finished
                        $progressModal.addClass('royalbr-finished');

                        // Hide "Please wait" text and progress bar, show completion message
                        setTimeout(function() {
                            $progressModal.find('.royalbr-modal-body > p').hide(); // Hide "Please wait" text
                            $progressModal.find('.royalbr-progress-wrapper').hide();
                            $progressModal.find('.royalbr-backup-complete-message').fadeIn();
                            $progressModal.find('#royalbr-backup-progress-view-log').show();
                            $progressModal.find('#royalbr-backup-progress-done').show();
                        }, 500);
                    }
                }
            },
            error: function() {
                // On error, stop polling
                stopBackupProgressPolling();
            }
        });
    }

    // Admin bar backup button hover handler
    $('#wp-admin-bar-royalbr_backup_node').on('mouseenter', function(e) {
        // Don't show hover popup on RBR admin page
        if (royalbr_admin_bar.is_rbr_page) {
            return;
        }

        // Don't show hover popup if pro version without active license
        if (!royalbr_admin_bar.show_quick_actions) {
            return;
        }

        clearTimeout(popupHoverTimeout);

        // Show popup after short delay
        popupHoverTimeout = setTimeout(function() {
            showBackupPopup();
        }, 300);
    }).on('mouseleave', function(e) {
        clearTimeout(popupHoverTimeout);

        // Don't hide if reminder is active
        if (window.ROYALBR && window.ROYALBR.reminderActive) {
            return;
        }

        // Hide popup after short delay
        popupHoverTimeout = setTimeout(function() {
            hideBackupPopup();
        }, 200);
    });

    // Keep popup open when mouse is over it
    $(document).on('mouseenter', '.royalbr-backup-popup', function() {
        clearTimeout(popupHoverTimeout);
    }).on('mouseleave', '.royalbr-backup-popup', function() {
        clearTimeout(popupHoverTimeout);

        // Don't hide if reminder is active
        if (window.ROYALBR && window.ROYALBR.reminderActive) {
            return;
        }

        popupHoverTimeout = setTimeout(function() {
            hideBackupPopup();
        }, 200);
    });

    // Initialize backup popup container
    function initBackupPopup() {
        if ($backupPopup) {
            return;
        }

        $backupPopup = $('<div class="royalbr-backup-popup">' +
            '<div class="royalbr-backup-popup-header">' +
            '<h3>Available Backups</h3>' +
            '<button type="button" class="button button-primary royalbr-popup-backup-btn">Backup Now</button>' +
            '</div>' +
            '<div class="royalbr-backup-popup-content">' +
            '<div class="royalbr-backup-popup-loading">' +
            '<span class="dashicons dashicons-update"></span>' +
            '<p>Loading backups...</p>' +
            '</div>' +
            '</div>' +
            '</div>');

        $('body').append($backupPopup);
    }

    // Show backup popup
    function showBackupPopup() {
        if (!$backupPopup) {
            initBackupPopup();
        }

        // Load backup modals immediately so "Backup Now" always shows UI popup
        loadBackupModal();
        loadBackupProgressModal();

        // Always reset content to loading state and reload backups
        $backupPopup.find('.royalbr-backup-popup-content').html(
            '<div class="royalbr-backup-popup-loading">' +
            '<span class="dashicons dashicons-update"></span>' +
            '<p>Loading backups...</p>' +
            '</div>'
        );

        // Always load fresh backup list
        loadBackupList();

        // Position popup centered below backup icon
        positionBackupPopup();

        $backupPopup.addClass('royalbr-popup-visible');

        // Highlight the Backup Now button with blinking animation
        var $backupBtn = $backupPopup.find('.royalbr-popup-backup-btn');
        $backupBtn.addClass('royalbr-highlight-blink');

        // Remove highlight class after animation completes (3 blinks = ~1.8s)
        setTimeout(function() {
            $backupBtn.removeClass('royalbr-highlight-blink');
        }, 2000);
    }

    // Position popup centered below the backup icon
    function positionBackupPopup() {
        if (!$backupPopup) {
            return;
        }

        var $backupIcon = $('#wp-admin-bar-royalbr_backup_node');
        if (!$backupIcon.length) {
            return;
        }

        var iconOffset = $backupIcon.offset();
        var iconWidth = $backupIcon.outerWidth();
        var popupWidth = $backupPopup.outerWidth() || 400; // Get actual width or use min-width from CSS

        // Calculate centered position
        var leftPos = iconOffset.left + (iconWidth / 2) - (popupWidth / 2);
        // Position directly below admin bar (32px normally, 46px on mobile)
        var adminBarHeight = $('#wpadminbar').outerHeight() || 32;
        var topPos = adminBarHeight;

        // Boundary checks - ensure popup doesn't go off-screen
        var minLeft = 10; // 10px from left edge
        var maxLeft = $(window).width() - popupWidth - 10; // 10px from right edge

        leftPos = Math.max(minLeft, Math.min(leftPos, maxLeft));

        // Apply position
        $backupPopup.css({
            'left': leftPos + 'px',
            'top': topPos + 'px'
        });
    }

    // Hide backup popup
    function hideBackupPopup() {
        if ($backupPopup) {
            $backupPopup.removeClass('royalbr-popup-visible');
            // Reset loaded flag to force fresh AJAX call on next hover
            isPopupLoaded = false;
        }
    }

    // Reposition popup on window resize (debounced)
    var resizeTimeout;
    $(window).on('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            // Only reposition if popup is visible
            if ($backupPopup && $backupPopup.hasClass('royalbr-popup-visible')) {
                positionBackupPopup();
            }
        }, 100); // 100ms debounce
    });

    // Load backup list via AJAX
    function loadBackupList() {
        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_list_for_popup',
                nonce: royalbr_admin_bar.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    popupData = response.data;
                    renderBackupList(response.data);
                    isPopupLoaded = true;

                    // Load backup modal if not already in DOM
                    loadBackupModal();
                    // Load backup progress modal if not already in DOM
                    loadBackupProgressModal();
                    // Load log viewer modal if not already in DOM
                    loadLogViewerModal();
                    // Load component selection modal if not already in DOM
                    loadComponentSelectionModal();
                    // Load restore confirmation modal if not already in DOM
                    loadRestoreConfirmationModal();
                    // Load restore progress modal if not already in DOM
                    loadRestoreProgressModal();
                } else {
                    renderEmptyState();
                }
            },
            error: function() {
                renderErrorState();
            }
        });
    }

    // Load backup confirmation modal via AJAX
    function loadBackupModal() {
        // Skip if already loading or loaded
        if (backupModalLoading || $('#royalbr-backup-confirmation-modal').length) {
            return;
        }

        backupModalLoading = true;

        // Load modal HTML from backend
        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_modal_html',
                context: 'quick-actions',
                nonce: royalbr_admin_bar.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    // Append modal to body
                    $('body').append(response.data.html);

                    // Set up modal event handlers
                    setupBackupModalHandlers();
                }
            },
            complete: function() {
                backupModalLoading = false;
            }
        });
    }

    // Set up backup modal event handlers
    function setupBackupModalHandlers() {
        // Handle modal close button
        $('#royalbr-backup-confirmation-modal .royalbr-modal-close, #royalbr-backup-cancel').on('click', function() {
            $('#royalbr-backup-confirmation-modal').hide();
            $('#royalbr-backup-name').val('');
        });

        // Handle Enter key in backup name input
        $('#royalbr-backup-name').on('keypress', function(e) {
            if (e.which === 13 || e.key === 'Enter') {
                e.preventDefault();
                $('#royalbr-backup-proceed').trigger('click');
            }
        });

        // Handle click outside modal to close
        $('#royalbr-backup-confirmation-modal').on('click', function(e) {
            if ($(e.target).hasClass('royalbr-modal')) {
                $(this).hide();
                $('#royalbr-backup-name').val('');
            }
        });
    }

    // Load backup progress modal via AJAX
    function loadBackupProgressModal() {
        // Skip if already loading or loaded
        if (backupProgressModalLoading || $('#royalbr-backup-progress-modal').length) {
            return;
        }

        backupProgressModalLoading = true;

        // Load modal HTML from backend
        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_progress_modal_html',
                context: 'quick-actions',
                nonce: royalbr_admin_bar.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    // Append modal to body
                    $('body').append(response.data.html);

                    // Set up modal event handlers
                    setupBackupProgressModalHandlers();
                }
            },
            complete: function() {
                backupProgressModalLoading = false;
            }
        });
    }

    // Set up backup progress modal event handlers
    function setupBackupProgressModalHandlers() {
        // Handle close button
        $('#royalbr-backup-progress-modal .royalbr-modal-close').on('click', function() {
            $('#royalbr-backup-progress-modal').hide();
            royalbrStopModalPromoRotation();
            isPopupLoaded = false;
        });

        // Handle Done button - close modal and reload backup list
        $('#royalbr-backup-progress-done').on('click', function() {
            $('#royalbr-backup-progress-modal').hide();
            royalbrStopModalPromoRotation();
            // Reload backup list in popup
            isPopupLoaded = false;
        });

        // Handle View Log button
        $('#royalbr-backup-progress-view-log').on('click', function(e) {
            e.preventDefault();

            // Hide the backup progress modal
            $('#royalbr-backup-progress-modal').hide();
            royalbrStopModalPromoRotation();

            // Fetch log via AJAX and show in log viewer modal
            $.ajax({
                url: royalbr_admin_bar.ajax_url,
                type: 'POST',
                data: {
                    action: 'royalbr_get_log',
                    nonce: royalbr_admin_bar.nonce
                },
                success: function(response) {
                    if (response.success && response.data && response.data.log) {
                        // Update log modal
                        var logFilename = response.data.filename || 'backup-log.txt';
                        $('#royalbr-log-modal-filename').text(logFilename);
                        $('#royalbr-log-modal-title').text('Backup Activity Log');
                        $('#royalbr-log-content').text(response.data.log);
                        $('#royalbr-log-popup').show();

                        // Scroll to bottom of log
                        var logContent = document.getElementById('royalbr-log-content');
                        if (logContent) {
                            logContent.scrollTop = logContent.scrollHeight;
                        }
                    } else {
                        alert('Failed to load activity log.');
                    }
                },
                error: function() {
                    alert('Error loading activity log.');
                }
            });
        });
    }

    // Load log viewer modal via AJAX
    function loadLogViewerModal() {
        // Remove existing modal to force fresh load with correct context
        $('#royalbr-log-popup').remove();

        // Load modal HTML from backend
        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_log_viewer_modal_html',
                context: 'quick-actions',
                nonce: royalbr_admin_bar.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    // Append modal to body
                    $('body').append(response.data.html);

                    // Set up modal event handlers
                    setupLogViewerModalHandlers();
                }
            }
        });
    }

    // Set up log viewer modal event handlers
    function setupLogViewerModalHandlers() {
        // Handle close button
        $('#royalbr-log-popup .royalbr-modal-close').on('click', function() {
            $('#royalbr-log-popup').hide();
            // If this was a restore log, refresh page
            if (window.royalbr_restore_log_opened) {
                location.reload();
                return;
            }
        });

        // Handle click outside modal to close
        $('#royalbr-log-popup').on('click', function(e) {
            if ($(e.target).hasClass('royalbr-modal')) {
                $(this).hide();
                // If this was a restore log, refresh page
                if (window.royalbr_restore_log_opened) {
                    location.reload();
                    return;
                }
            }
        });

        // Handle download log button
        $('#royalbr-download-log').on('click', function(e) {
            e.preventDefault();

            // Get log content
            var logContent = $('#royalbr-log-content').text();
            var logFilename = $('#royalbr-log-modal-filename').text() || 'backup-log.txt';

            if (!logContent) {
                alert('No log content to download.');
                return;
            }

            // Create blob and download
            var blob = new Blob([logContent], { type: 'text/plain' });
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = logFilename;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        });
    }

    // Load component selection modal via AJAX
    function loadComponentSelectionModal() {
        // Remove existing modal to force fresh load with correct context
        $('#royalbr-component-selection-modal').remove();

        // Load modal HTML from backend
        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_component_selection_modal_html',
                context: 'quick-actions',
                nonce: royalbr_admin_bar.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    // Append modal to body
                    $('body').append(response.data.html);

                    // Set up modal event handlers
                    setupComponentSelectionModalHandlers();
                }
            }
        });
    }

    // Set up component selection modal event handlers
    function setupComponentSelectionModalHandlers() {
        // Handle close button
        $('#royalbr-component-selection-modal .royalbr-modal-close, #royalbr-component-selection-cancel').on('click', function() {
            $('#royalbr-component-selection-modal').hide();
        });

        // Handle click outside modal to close
        $('#royalbr-component-selection-modal').on('click', function(e) {
            if ($(e.target).hasClass('royalbr-modal')) {
                $(this).hide();
            }
        });

        // Handle Select All checkbox
        $('#royalbr_component_select_all').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('#royalbr-component-selection-form input[name="royalbr_component[]"]:not(:disabled)').prop('checked', isChecked);
        });

        // Update Select All state when individual checkboxes change
        $('#royalbr-component-selection-form input[name="royalbr_component[]"]').on('change', function() {
            var totalCheckboxes = $('#royalbr-component-selection-form input[name="royalbr_component[]"]:not(:disabled)').length;
            var checkedCheckboxes = $('#royalbr-component-selection-form input[name="royalbr_component[]"]:checked').length;
            $('#royalbr_component_select_all').prop('checked', totalCheckboxes === checkedCheckboxes);
        });
    }

    // Show component selection modal with defaults applied
    function showComponentSelectionModal(timestamp, nonce, availableComponents) {
        var $modal = $('#royalbr-component-selection-modal');
        var isPremium = royalbr_admin_bar.is_premium;

        // Store timestamp and nonce
        $('#royalbr-component-selection-timestamp').val(timestamp);
        $('#royalbr-component-selection-nonce').val(nonce);

        // Reset all checkbox states before applying new backup's state
        $('#royalbr-component-selection-form input[name="royalbr_component[]"]').each(function() {
            $(this).prop('checked', false).prop('disabled', false);
            $(this).closest('label').css('opacity', '1').attr('title', '');
        });
        $('#royalbr_component_select_all').prop('checked', false);

        // For free users: keep all checkboxes checked and disabled (restore everything)
        // For premium users: apply saved settings
        if (isPremium) {
            // Apply default restore settings from configuration
            $('#royalbr_component_db').prop('checked', royalbr_admin_bar.default_restore_db || true);
            $('#royalbr_component_plugins').prop('checked', royalbr_admin_bar.default_restore_plugins || false);
            $('#royalbr_component_themes').prop('checked', royalbr_admin_bar.default_restore_themes || false);
            $('#royalbr_component_uploads').prop('checked', royalbr_admin_bar.default_restore_uploads || false);
            $('#royalbr_component_others').prop('checked', royalbr_admin_bar.default_restore_others || false);

            // Disable/enable checkboxes based on available components (following admin.js pattern)
            $('#royalbr-component-selection-form input[name="royalbr_component[]"]').each(function() {
                var $checkbox = $(this);
                var component = $checkbox.val();
                var isAvailable = availableComponents.indexOf(component) !== -1;

                $checkbox.prop('disabled', !isAvailable);

                // Uncheck disabled components
                if (!isAvailable) {
                    $checkbox.prop('checked', false);
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

            // Update Select All checkbox state
            var totalCheckboxes = $('#royalbr-component-selection-form input[name="royalbr_component[]"]').length;
            var checkedCheckboxes = $('#royalbr-component-selection-form input[name="royalbr_component[]"]:checked').length;
            $('#royalbr_component_select_all').prop('checked', totalCheckboxes === checkedCheckboxes);
        } else {
            // Free users: check and disable all available components
            $('#royalbr-component-selection-form input[name="royalbr_component[]"]').each(function() {
                var $checkbox = $(this);
                var component = $checkbox.val();
                var isAvailable = availableComponents.indexOf(component) !== -1;

                $checkbox.prop('checked', isAvailable);
                $checkbox.prop('disabled', true);

                var $label = $checkbox.closest('label');
                if (!isAvailable) {
                    $label.css('opacity', '0.5');
                    $label.attr('title', 'Not available in this backup');
                }
            });
        }

        // Show the modal
        $modal.show();

        // Handle proceed button click
        $('#royalbr-component-selection-proceed').off('click').on('click', function() {
            // Gather selected components
            var selectedComponents = [];
            $('#royalbr-component-selection-form input[name="royalbr_component[]"]:checked').each(function() {
                selectedComponents.push($(this).val());
            });

            // Validate at least one component selected
            if (selectedComponents.length === 0) {
                alert('You must select at least one item to restore.');
                return;
            }

            // Hide component selection modal
            $modal.hide();

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
                return componentNames[comp] || comp;
            });

            // Show confirmation modal with custom message
            showRestoreConfirmationModal(timestamp, nonce, selectedComponents, componentLabels);
        });
    }

    // Show restore confirmation modal with component details
    function showRestoreConfirmationModal(timestamp, nonce, selectedComponents, componentLabels) {
        var $modal = $('#royalbr-confirmation-modal');

        // Build confirmation message with component list
        var componentList = componentLabels.map(function(label) {
            return '<strong>' + label + '</strong>';
        }).join(', ');

        // Update modal title and message
        $('#royalbr-modal-title').text('Confirm Restore');
        $('#royalbr-modal-message').html('Proceed with restoring: ' + componentList + '?<br>This operation will replace your current site data.');

        // Set up cancel and close handlers for this modal
        $('#royalbr-confirmation-modal .royalbr-modal-close, #royalbr-modal-cancel').off('click.restoreconfirm').on('click.restoreconfirm', function() {
            $modal.hide();
            // Remove one-time handlers
            $('#royalbr-modal-confirm').off('click.restoreconfirm');
            $(this).off('click.restoreconfirm');
        });

        // Handle click outside modal to close
        $modal.off('click.restoreconfirm').on('click.restoreconfirm', function(e) {
            if (e.target === this) {
                $modal.hide();
                // Remove one-time handlers
                $('#royalbr-modal-confirm').off('click.restoreconfirm');
                $(this).off('click.restoreconfirm');
            }
        });

        // Show the confirmation modal
        $modal.show();

        // Handle confirm button - use one-time handler to avoid duplicates
        $('#royalbr-modal-confirm').off('click.restoreconfirm').on('click.restoreconfirm', function() {
            // Hide confirmation modal
            $modal.hide();

            // Start restore using unified ROYALBR function from royalbr-core.js
            window.ROYALBR.startRestore(timestamp, nonce, selectedComponents, 'quick-actions');

            // Remove the one-time handler
            $('#royalbr-modal-confirm').off('click.restoreconfirm');
        });
    }

    // Load restore confirmation modal via AJAX
    function loadRestoreConfirmationModal() {
        // Remove existing modal to force fresh load with correct context
        $('#royalbr-confirmation-modal').remove();

        // Load modal HTML from backend and return promise
        return $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_confirmation_modal_html',
                context: 'quick-actions',
                nonce: royalbr_admin_bar.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    // Append modal to body
                    $('body').append(response.data.html);

                    // Set up modal event handlers
                    setupRestoreConfirmationModalHandlers();
                }
            }
        });
    }

    // Set up restore confirmation modal event handlers
    function setupRestoreConfirmationModalHandlers() {
        console.log('Setting up restore confirmation modal handlers');
        console.log('Proceed button exists:', $('#royalbr-restore-proceed').length);

        // Handle close button
        $('#royalbr-restore-confirmation-modal .royalbr-modal-close').on('click', function() {
            $('#royalbr-restore-confirmation-modal').hide();
        });

        // Handle click outside modal to close
        $('#royalbr-restore-confirmation-modal').on('click', function(e) {
            if ($(e.target).hasClass('royalbr-modal')) {
                $(this).hide();
            }
        });
    }

    // Use event delegation for Proceed button (works even after AJAX load)
    $(document).on('click', '#royalbr-restore-confirmation-proceed', function() {
        console.log('Proceed button clicked');

        var timestamp = $('#royalbr-restore-confirmation-timestamp').val();
        var nonce = $('#royalbr-restore-confirmation-nonce').val();
        var components = $('#royalbr-restore-confirmation-modal').data('components') || ['db'];

        console.log('Timestamp:', timestamp, 'Nonce:', nonce, 'Components:', components);

        // Hide confirmation modal
        $('#royalbr-restore-confirmation-modal').hide();
        console.log('Confirmation modal hidden');

        // Start restore using ROYALBR namespace function
        window.ROYALBR.startRestore(timestamp, nonce, components, 'quick-actions');
    });

    // Load restore progress modal via AJAX
    function loadRestoreProgressModal() {
        // Remove existing modal to force fresh load with correct context
        $('#royalbr-progress-modal').remove();

        // Load modal HTML from backend
        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_progress_modal_html',
                context: 'quick-actions',
                nonce: royalbr_admin_bar.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    // Append modal to body
                    $('body').append(response.data.html);

                    // Set up modal event handlers
                    setupRestoreProgressModalHandlers();
                }
            }
        });
    }

    // Set up restore progress modal event handlers
    function setupRestoreProgressModalHandlers() {
        // Handle Done button
        $('#royalbr-progress-done').on('click', function() {
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

        // Handle View Activity Log button
        $('#royalbr-progress-view-log').on('click', function(e) {
            e.preventDefault();

            // Hide restore progress modal
            $('#royalbr-progress-modal').hide();

            // Get log file from hidden input
            var logFile = $('#royalbr_restore_log_file').val();

            // Fetch restore log via AJAX
            $.ajax({
                url: royalbr_admin_bar.ajax_url,
                type: 'POST',
                data: {
                    action: 'royalbr_get_restore_log',
                    nonce: royalbr_admin_bar.nonce,
                    log_file: logFile
                },
                success: function(response) {
                    if (response.success && response.data && response.data.log) {
                        // Update log modal
                        var logFilename = response.data.filename || 'restore-log.txt';
                        $('#royalbr-log-modal-filename').text(logFilename);
                        $('#royalbr-log-modal-title').text('Restore Activity Log');
                        $('#royalbr-log-content').text(response.data.log);
                        $('#royalbr-log-popup').show();

                        // Mark that we opened restore log (for page refresh on close)
                        window.royalbr_restore_log_opened = true;

                        // Scroll to bottom of log
                        var logContent = document.getElementById('royalbr-log-content');
                        if (logContent) {
                            logContent.scrollTop = logContent.scrollHeight;
                        }
                    } else {
                        alert('Failed to load restore log.');
                    }
                },
                error: function() {
                    alert('Error loading restore log.');
                }
            });
        });
    }

    // Start quick restore with progress modal
    function startQuickRestore(timestamp, nonce, components) {
        var $progressModal = $('#royalbr-progress-modal');

        // Check if modal exists
        if (!$progressModal.length) {
            alert('Restore progress modal not loaded. Please refresh the page and try again.');
            return;
        }

        // Reset modal state
        $progressModal.find('li').removeClass('active done error');
        $progressModal.find('.royalbr-component--progress').html('');
        $progressModal.find('.royalbr-restore-result').hide().removeClass('restore-success restore-error');
        $progressModal.find('.royalbr-restore-result .dashicons').removeClass('dashicons-yes dashicons-no-alt');
        $progressModal.find('#royalbr-progress-view-log, #royalbr-progress-done').hide();
        $progressModal.find('.royalbr-modal-header').css('justify-content', '');
        $progressModal.find('.royalbr-modal-header h3').show().text('Restoration in Progress');
        $progressModal.find('.royalbr-restore-subtitle').show();
        $progressModal.find('.royalbr-restore-components-list').show();
        $progressModal.removeClass('royalbr-finished');

        // Build dynamic component list based on selected components (matching backend structure)
        var componentDefinitions = {
            'verifying': { label: 'Verification', helper: 'Checking backup integrity and file availability' },
            'db': { label: 'Database', helper: 'Restoring database tables and content' },
            'plugins': { label: 'Plugins', helper: 'Extracting and installing plugin files' },
            'themes': { label: 'Themes', helper: 'Restoring theme files and configurations' },
            'uploads': { label: 'Uploads', helper: 'Restoring media library and uploaded files' },
            'others': { label: 'Others', helper: 'Restoring additional site content' },
            'wpcore': { label: 'WordPress Core', helper: 'Restoring WordPress core files' }
        };

        // Build HTML for dynamic components using <li> structure like backend
        var componentsHTML = '';

        // Always show verification first
        componentsHTML += '<li data-component="verifying" class="active">';
        componentsHTML += '<div class="royalbr-component--wrapper">';
        componentsHTML += '<span class="royalbr-component--description">Verification</span>';
        componentsHTML += '<span class="royalbr-component--helper">Checking backup integrity and file availability</span>';
        componentsHTML += '</div>';
        componentsHTML += '<span class="royalbr-component--progress"></span>';
        componentsHTML += '</li>';

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

        // Insert dynamic components into modal
        $progressModal.find('.royalbr-restore-components-list').html(componentsHTML);

        // Show restore progress modal
        $progressModal.show();

        console.log('Starting restore for timestamp:', timestamp, 'with components:', components);

        // Step 1: Create restore task first, then start streaming restore
        createRestoreTask(timestamp, nonce, components, $progressModal);
    }

    // Step 1: Create restore task and get task_id
    function createRestoreTask(timestamp, nonce, components, $progressModal) {
        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_ajax_restore',
                royalbr_ajax_restore: 'start_ajax_restore',
                timestamp: timestamp,
                backup_nonce: nonce,
                components: components,
                nonce: royalbr_admin_bar.nonce
            },
            success: function(response) {
                if (response.success && response.data.task_id) {
                    console.log('Restore task created with ID:', response.data.task_id);
                    // Step 2: Start streaming restore with task_id
                    royalbrQuickRestoreCommand(response.data.task_id, $progressModal);
                } else {
                    alert('Failed to create restore task: ' + (response.data || 'Unknown error'));
                    $progressModal.hide();
                }
            },
            error: function(xhr, status, error) {
                alert('Error creating restore task: ' + error);
                $progressModal.hide();
            }
        });
    }

    // Step 2: Streaming AJAX restore with RINFO parsing (adapted from admin-restore.js)
    function royalbrQuickRestoreCommand(task_id, $progressModal) {
        var xhttp = new XMLHttpRequest();
        var xhttp_data = 'action=royalbr_ajax_restore&royalbr_ajax_restore=do_ajax_restore&task_id=' + encodeURIComponent(task_id) + '&nonce=' + royalbr_admin_bar.nonce;
        var previous_data_length = 0;
        var show_alert = true;
        var restore_log_file = '';

        xhttp.open("POST", royalbr_admin_bar.ajax_url, true);
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

                // Check for RINFO messages for step tracking
                while (i < responseText.length) {
                    var buffer = responseText.substr(i, 7);
                    if ('RINFO:{' == buffer) {
                        // Grab what follows RINFO:
                        var analyse_it = window.ROYALBR.parseJson(responseText.substr(i), true);

                        console.log('ROYALBR: Processing RINFO:', analyse_it);

                        // Safety check: ensure parse was successful before processing
                        if (!analyse_it || !analyse_it.parsed) {
                            console.log('ROYALBR: Failed to parse RINFO, skipping');
                            i++;
                            continue;
                        }

                        royalbrRestoreProcessData(analyse_it.parsed, $progressModal);

                        // move the for loop counter to the end of the json
                        end_of_json = i + analyse_it.json_last_pos - analyse_it.json_start_pos + 6;
                        // When the for loop goes round again, it will start with the end of the JSON
                        i = end_of_json;
                    } else {
                        i++;
                    }
                }
            } else {
                console.log("ROYALBR restore error: " + response.currentTarget.status + ' ' + response.currentTarget.statusText);
                console.log(response.currentTarget);
            }
        }

        xhttp.onload = function() {
            // Parse response to find result and log file path
            var parser = new DOMParser();
            var doc = parser.parseFromString(xhttp.responseText, 'text/html');

            // Get log file path from hidden input
            var logFileInput = doc.getElementById('royalbr_restore_log_file');
            if (logFileInput) {
                restore_log_file = logFileInput.value;
                $('#royalbr_restore_log_file').val(restore_log_file);
            }

            // Find success/error result
            var $successResult = $(doc).find('.royalbr_restore_successful');
            var $errorResult = $(doc).find('.royalbr_restore_error');

            var $result_output = $progressModal.find('.royalbr-restore-result');

            // Wait 1 second before showing completion
            setTimeout(function() {
                // Mark all active components as done
                $progressModal.find('li.active').removeClass('active').addClass('done');

                if ($successResult.length) {
                    // Store auto-login token for use when "Done" is clicked
                    var tokenInput = doc.getElementById('royalbr_auto_login_token');
                    if (tokenInput && tokenInput.value) {
                        $progressModal.data('royalbr-auto-login-token', tokenInput.value);
                    }

                    // Success - hide component list and subtitle, update header to "Restore Finished"
                    $progressModal.find('.royalbr-restore-components-list').hide();
                    $progressModal.find('.royalbr-modal-header').css('justify-content', 'center');
                    $progressModal.find('.royalbr-modal-header h3').text('Restore Finished');
                    $progressModal.find('.royalbr-restore-subtitle').hide();

                    // Show "Congratulations" message (matching backend admin.js line 874)
                    $result_output.find('.dashicons').addClass('dashicons-yes');
                    $result_output.find('.royalbr-restore-result--text').text('Congratulations, your website has been successfully restored');
                    $result_output.addClass('restore-success');
                    $result_output.fadeIn(400);

                    // Mark modal as finished
                    $progressModal.addClass('royalbr-finished');

                    // Show View Log and Done buttons
                    $progressModal.find('#royalbr-progress-view-log, #royalbr-progress-done').fadeIn(400);
                } else if ($errorResult.length) {
                    // Error
                    $result_output.find('.dashicons').addClass('dashicons-no-alt');

                    // Show specific error message if available, otherwise show generic
                    var $errorMessages = $(doc).find('.royalbr_restore_errors');
                    if ($errorMessages.length && $errorMessages.text().trim()) {
                        // Show the specific error (e.g., "No space left on device")
                        $result_output.find('.royalbr-restore-result--text').text($errorMessages.text().trim());
                        $progressModal.find('.royalbr-restore-error-message').html($errorMessages.html()).show();
                    } else {
                        $result_output.find('.royalbr-restore-result--text').text($errorResult.text());
                    }

                    $result_output.addClass('restore-error');
                    $result_output.fadeIn(400);

                    // Mark modal as finished
                    $progressModal.addClass('royalbr-finished');

                    // Show View Log and Done buttons
                    $progressModal.find('#royalbr-progress-view-log, #royalbr-progress-done').fadeIn(400);
                } else {
                    // Unknown state - show error
                    $result_output.find('.dashicons').addClass('dashicons-no-alt');
                    $result_output.find('.royalbr-restore-result--text').text('Restore completed with unknown status');
                    $result_output.addClass('restore-error');
                    $result_output.fadeIn(400);

                    // Mark modal as finished
                    $progressModal.addClass('royalbr-finished');

                    // Show Done button
                    $progressModal.find('#royalbr-progress-done').fadeIn(400);
                }
            }, 1000);
        }

        xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhttp.send(xhttp_data);
    }

    // Process the parsed restore data and make updates to the progress modal
    function royalbrRestoreProcessData(restore_data, $progressModal) {
        if (restore_data) {
            if ('state' == restore_data.type || 'state_change' == restore_data.type) {
                console.log('ROYALBR: Stage update -', restore_data.stage, restore_data.data);

                var current_stage;
                if ('files' == restore_data.stage) {
                    current_stage = restore_data.data.entity;
                } else {
                    current_stage = restore_data.stage;
                }

                var $current = $progressModal.find('[data-component="' + current_stage + '"]');

                // Show simplified activity log next to the component's label
                if ('files' == restore_data.stage) {
                    $current.find('.royalbr-component--progress').html(' — Restoring file <strong>' + (restore_data.data.fileindex) + '</strong> of <strong>' + restore_data.data.total_files + '</strong>');
                }

                // Check if this is a new stage
                if (!$current.hasClass('active') && !$current.hasClass('done')) {
                    // Mark previous stage as done
                    $progressModal.find('li.active').each(function() {
                        $(this).find('.royalbr-component--progress').html('');
                        $(this).removeClass('active').addClass('done');
                    });

                    // Mark current stage based on type
                    if ('finished' === current_stage) {
                        // Mark ALL component stages as done when finished arrives
                        // (The onload handler will detect actual errors via HTML markers)
                        $progressModal.find('li').each(function() {
                            $(this).removeClass('active').addClass('done');
                        });
                    } else {
                        // For all other stages, mark as active
                        $current.addClass('active');
                    }
                }
            }
        }
    }

    // Render backup list in popup
    function renderBackupList(backups) {
        if (!backups || backups.length === 0) {
            renderEmptyState();
            return;
        }

        var html = '<ul class="royalbr-backup-popup-list royalbr-fade-in-once">';

        $.each(backups, function(index, backup) {
            var displayName = backup.display_name || backup.nonce;
            // Capitalize only first letter if it's a custom name (not a backup ID/nonce)
            if (displayName && displayName.length > 0 && displayName !== backup.nonce) {
                displayName = displayName.charAt(0).toUpperCase() + displayName.slice(1);
            }

            // Get available components (default to empty array if not set)
            var availableComponents = backup.available_components || [];
            var availableComponentsJson = JSON.stringify(availableComponents);

            html += '<li class="royalbr-backup-popup-item">';
            html += '<div class="royalbr-backup-popup-item-info">';
            html += '<div class="royalbr-backup-popup-name">';

            // Storage icons (inline before name).
            var storageLocations = backup.storage_locations || ['local'];
            // Ensure storageLocations is always an array (PHP may return object if keys are non-sequential).
            if (!Array.isArray(storageLocations)) {
                storageLocations = Object.values(storageLocations);
            }
            if (storageLocations.indexOf('local') !== -1) {
                html += '<span class="royalbr-storage-icon royalbr-storage-local" title="Stored locally">';
                html += '<span class="dashicons dashicons-desktop"></span>';
                html += '</span>';
            }
            if (storageLocations.indexOf('gdrive') !== -1) {
                html += '<span class="royalbr-storage-icon royalbr-storage-gdrive" title="Stored in Google Drive">';
                html += '<img src="' + royalbr_admin_bar.gdrive_icon_url + '" alt="Google Drive" width="14" height="14" />';
                html += '</span>';
            }
            if (storageLocations.indexOf('dropbox') !== -1) {
                html += '<span class="royalbr-storage-icon royalbr-storage-dropbox" title="Stored in Dropbox">';
                html += '<img src="' + royalbr_admin_bar.dropbox_icon_url + '" alt="Dropbox" width="14" height="14" />';
                html += '</span>';
            }
            if (storageLocations.indexOf('s3') !== -1) {
                html += '<span class="royalbr-storage-icon royalbr-storage-s3" title="Stored in Amazon S3">';
                html += '<img src="' + royalbr_admin_bar.s3_icon_url + '" alt="Amazon S3" width="14" height="14" />';
                html += '</span>';
            }

            html += window.ROYALBR.escapeHtml(displayName) + '</div>';
            html += '<div class="royalbr-backup-popup-id">' + window.ROYALBR.escapeHtml(backup.formatted_date) + '</div>';
            html += '</div>';
            html += '<div class="royalbr-backup-popup-item-action">';
            html += '<button type="button" class="button button-primary royalbr-popup-restore-btn" data-timestamp="' + window.ROYALBR.escapeHtml(backup.timestamp) + '" data-nonce="' + window.ROYALBR.escapeHtml(backup.nonce) + '" data-available-components="' + window.ROYALBR.escapeHtml(availableComponentsJson) + '">Restore</button>';
            html += '</div>';
            html += '</li>';
        });

        html += '</ul>';

        // Smooth transition: fade out loading, then fade in content
        var $loading = $backupPopup.find('.royalbr-backup-popup-loading');
        $loading.fadeOut(200, function() {
            $backupPopup.find('.royalbr-backup-popup-content').html(html);

            // Trigger fade-in after DOM insertion
            setTimeout(function() {
                $backupPopup.find('.royalbr-fade-in-once').addClass('royalbr-fade-in-active');
            }, 50);
        });
    }

    // Render empty state
    function renderEmptyState() {
        var html = '<div class="royalbr-backup-popup-empty royalbr-fade-in-once">';
        html += '<p>No backups available yet.</p>';
        html += '</div>';

        // Smooth transition: fade out loading, then fade in content
        var $loading = $backupPopup.find('.royalbr-backup-popup-loading');
        $loading.fadeOut(200, function() {
            $backupPopup.find('.royalbr-backup-popup-content').html(html);

            // Trigger fade-in after DOM insertion
            setTimeout(function() {
                $backupPopup.find('.royalbr-fade-in-once').addClass('royalbr-fade-in-active');
            }, 50);
        });

        isPopupLoaded = true;
    }

    // Render error state
    function renderErrorState() {
        var html = '<div class="royalbr-backup-popup-empty royalbr-fade-in-once">';
        html += '<p>Error loading backups. Please try again.</p>';
        html += '</div>';

        // Smooth transition: fade out loading, then fade in content
        var $loading = $backupPopup.find('.royalbr-backup-popup-loading');
        $loading.fadeOut(200, function() {
            $backupPopup.find('.royalbr-backup-popup-content').html(html);

            // Trigger fade-in after DOM insertion
            setTimeout(function() {
                $backupPopup.find('.royalbr-fade-in-once').addClass('royalbr-fade-in-active');
            }, 50);
        });
    }

    // Handle backup button click from popup
    $(document).on('click', '.royalbr-popup-backup-btn', function(e) {
        e.preventDefault();

        // First check if a backup is already running
        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_backup_progress',
                nonce: royalbr_admin_bar.nonce,
                oneshot: 1
            },
            success: function(response) {
                if (response.success && response.data.running) {
                    // Backup is already running - show message and abort
                    alert('A backup is already in progress. Please wait for it to complete.');
                    return;
                }

                // No backup running - proceed to show confirmation modal
                proceedWithBackupModal();
            },
            error: function() {
                // On error, proceed anyway (server-side check will catch it)
                proceedWithBackupModal();
            }
        });

        function proceedWithBackupModal() {
            // Hide the popup
            hideBackupPopup();

            // Show the backup confirmation modal (if it exists on the page)
            var $modal = $('#royalbr-backup-confirmation-modal');

            if ($modal.length) {
                // Pre-fill with suggested name from backup reminder (if available) or clear
                var suggestedName = window.ROYALBR.suggestedBackupName || '';
                $('#royalbr-backup-name').val(suggestedName).attr('placeholder', '...');
                window.ROYALBR.suggestedBackupName = null; // Clear after use

                // Show the modal
                $modal.show();

                // Get the actual backup ID from the backend
                $.ajax({
                    url: royalbr_admin_bar.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'royalbr_get_backup_nonce',
                        nonce: royalbr_admin_bar.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.nonce) {
                            $('#royalbr-backup-name').attr('placeholder', response.data.nonce);
                        }
                    }
                });

                // Set up one-time handler for this backup from quick actions
                $('#royalbr-backup-proceed').off('click.quickaction').on('click.quickaction', function() {
                    var backupName = $modal.find('#royalbr-backup-name').val().trim();
                    var includeDb = $modal.find('#royalbr-backup-database').is(':checked');
                    var includeFiles = $modal.find('#royalbr-backup-files').is(':checked');
                    var includeWpcore = $modal.find('#royalbr-backup-wpcore').is(':checked');

                    // Validate at least one option is selected
                    if (!includeDb && !includeFiles && !includeWpcore) {
                        alert('Please select at least one backup option (Database, Files, or WordPress Core).');
                        return;
                    }

                    // Hide the custom name modal
                    $modal.hide();

                    // Start backup using ROYALBR namespace function
                    window.ROYALBR.startBackup(backupName, includeFiles, 'quick-actions', includeDb, includeWpcore);

                    // Start pro promo rotation after modal loads
                    setTimeout(function() {
                        royalbrStartModalPromoRotation();
                    }, 1000);

                    // Force reload backups list
                    isPopupLoaded = false;
                    // Remove the one-time handler
                    $('#royalbr-backup-proceed').off('click.quickaction');
                });
            } else {
                // Fallback: if modal doesn't exist, use the old confirmation method
                if (!confirm('Start a new backup now?\n\nDatabase will be backed up, and files will be included based on your default settings.')) {
                    return;
                }

                var includeFiles = typeof royalbr_admin_bar !== 'undefined' && royalbr_admin_bar.default_include_files ? 1 : 0;
                var includeWpcore = typeof royalbr_admin_bar !== 'undefined' && royalbr_admin_bar.default_include_wpcore ? 1 : 0;

                $.ajax({
                    url: royalbr_admin_bar.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'royalbr_create_backup',
                        nonce: royalbr_admin_bar.nonce,
                        include_files: includeFiles,
                        include_wpcore: includeWpcore
                    },
                    success: function(response) {
                        if (response.success) {
                            showAdminBarNotice('Backup started! You\'ll be notified when complete.', 'success');
                        } else {
                            showAdminBarNotice('Failed to start backup: ' + (response.data || 'Unknown error'), 'error');
                        }
                    },
                    error: function() {
                        showAdminBarNotice('Error starting backup. Please try again.', 'error');
                    },
                    complete: function() {
                        isPopupLoaded = false;
                    }
                });
            }
        }
    });

    // Handle restore button click from popup
    $(document).on('click', '.royalbr-popup-restore-btn', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var timestamp = $btn.data('timestamp');
        var nonce = $btn.data('nonce');

        // Get available components from button data attribute
        var availableComponents = [];
        try {
            var componentsData = $btn.data('available-components');
            if (componentsData) {
                availableComponents = typeof componentsData === 'string'
                    ? JSON.parse(componentsData)
                    : componentsData;
            }
        } catch (e) {
            console.error('ROYALBR: Failed to parse available components', e);
        }

        // Hide popup
        hideBackupPopup();

        // Show component selection modal with available components
        showComponentSelectionModal(timestamp, nonce, availableComponents);
    });

    // Admin bar backup icon click handler - redirect to admin page
    $('#wp-admin-bar-royalbr_backup_node > a.ab-item').on('click', function(e) {
        // Check if href exists, if not, redirect manually
        var href = $(this).attr('href');
        if (!href || href === '#') {
            e.preventDefault();
            window.location.href = royalbr_admin_bar.admin_page_url || 'admin.php?page=royal-backup-reset';
        }
        // Otherwise allow default behavior
    });

    // Admin bar reset icon click handler - redirect to Database Reset tab
    $('#wp-admin-bar-royalbr_reset_node > a.ab-item').on('click', function(e) {
        e.preventDefault();
        var targetUrl = (royalbr_admin_bar.admin_page_url || 'admin.php?page=royal-backup-reset') + '#reset-database';
        if (royalbr_admin_bar.is_rbr_page) {
            window.location.hash = 'reset-database';
            window.location.reload();
        } else {
            window.location.href = targetUrl;
        }
    });

    // Admin bar reset button hover handler - show popup with options
    $('#wp-admin-bar-royalbr_reset_node').on('mouseenter', function(e) {
        // Don't show hover popup on RBR admin page
        if (royalbr_admin_bar.is_rbr_page) {
            return;
        }

        // Don't show hover popup if pro version without active license
        if (!royalbr_admin_bar.show_quick_actions) {
            return;
        }

        clearTimeout(resetPopupHoverTimeout);

        // Show popup after short delay
        resetPopupHoverTimeout = setTimeout(function() {
            showResetPopup();
        }, 300);
    }).on('mouseleave', function(e) {
        clearTimeout(resetPopupHoverTimeout);

        // Hide popup after short delay
        resetPopupHoverTimeout = setTimeout(function() {
            hideResetPopup();
        }, 200);
    });

    // Keep reset popup open when mouse is over it
    $(document).on('mouseenter', '.royalbr-reset-popup', function() {
        clearTimeout(resetPopupHoverTimeout);
    }).on('mouseleave', '.royalbr-reset-popup', function() {
        clearTimeout(resetPopupHoverTimeout);
        resetPopupHoverTimeout = setTimeout(function() {
            hideResetPopup();
        }, 200);
    });

    // Initialize reset popup container
    function initResetPopup() {
        if ($resetPopup) {
            return;
        }

        $resetPopup = $('<div class="royalbr-reset-popup">' +
            '<div class="royalbr-reset-popup-header">' +
            '<h3>Reset Database</h3>' +
            '</div>' +
            '<div class="royalbr-reset-popup-content">' +
            '<div class="royalbr-reset-popup-options">' +
            '<div class="royalbr-reset-popup-option">' +
            '<label>' +
            '<input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-popup-reactivate-theme" />' +
            '<div class="royalbr-reset-popup-option-content">' +
            '<span class="royalbr-reset-popup-option-title">Reactivate Current Theme</span>' +
            '<span class="royalbr-reset-popup-option-desc">Keep your current theme active after reset</span>' +
            '</div>' +
            '</label>' +
            '</div>' +
            '<div class="royalbr-reset-popup-option">' +
            '<label>' +
            '<input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-popup-reactivate-plugins" />' +
            '<div class="royalbr-reset-popup-option-content">' +
            '<span class="royalbr-reset-popup-option-title">Reactivate Current Plugins</span>' +
            '<span class="royalbr-reset-popup-option-desc">Activate all active plugins after reset</span>' +
            '</div>' +
            '</label>' +
            '</div>' +
            '<div class="royalbr-reset-popup-option">' +
            '<label>' +
            '<input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-popup-keep-royalbr-active" />' +
            '<div class="royalbr-reset-popup-option-content">' +
            '<span class="royalbr-reset-popup-option-title">Keep This Plugin Active</span>' +
            '<span class="royalbr-reset-popup-option-desc">Ensure Royal Backup & Reset remains active</span>' +
            '</div>' +
            '</label>' +
            '</div>' +
            '<div class="royalbr-reset-popup-option">' +
            '<label>' +
            '<input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-popup-clear-media" />' +
            '<div class="royalbr-reset-popup-option-content">' +
            '<span class="royalbr-reset-popup-option-title">Clear Media Files</span>' +
            '<span class="royalbr-reset-popup-option-desc">Delete year/month media folders only</span>' +
            '</div>' +
            '</label>' +
            '</div>' +
            '<div class="royalbr-reset-popup-option' + (royalbr_admin_bar.is_premium ? '' : ' royalbr-pro-option-disabled') + '">' +
            '<label>' +
            '<input type="checkbox" class="royalbr-custom-checkbox" id="royalbr-popup-clear-uploads"' + (royalbr_admin_bar.is_premium ? '' : ' disabled') + ' />' +
            '<div class="royalbr-reset-popup-option-content">' +
            '<span class="royalbr-reset-popup-option-title">Clear Uploads Directory' + (royalbr_admin_bar.is_premium ? '' : ' <span class="royalbr-pro-badge">PRO</span>') + '</span>' +
            '<span class="royalbr-reset-popup-option-desc">Delete all files in wp-content/uploads</span>' +
            '</div>' +
            '</label>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '<div class="royalbr-reset-popup-footer">' +
            '<div class="royalbr-reset-popup-button-wrapper">' +
            '<button type="button" class="button royalbr-popup-reset-btn">Reset Database</button>' +
            '</div>' +
            '</div>' +
            '</div>');

        $('body').append($resetPopup);
    }

    // Show reset popup
    function showResetPopup() {
        if (!$resetPopup) {
            initResetPopup();
        }

        // Apply default reset settings from configuration
        $('#royalbr-popup-reactivate-theme').prop('checked', royalbr_admin_bar.default_reactivate_theme || false);
        $('#royalbr-popup-reactivate-plugins').prop('checked', royalbr_admin_bar.default_reactivate_plugins || false);
        $('#royalbr-popup-keep-royalbr-active').prop('checked', royalbr_admin_bar.default_keep_royalbr_active !== false);
        $('#royalbr-popup-clear-media').prop('checked', royalbr_admin_bar.default_clear_media || false);
        if (royalbr_admin_bar.is_premium) {
            $('#royalbr-popup-clear-uploads').prop('checked', royalbr_admin_bar.default_clear_uploads || false);
        }

        // Position popup centered below reset icon
        positionResetPopup();

        $resetPopup.addClass('royalbr-popup-visible');
    }

    // Position popup centered below the reset icon
    function positionResetPopup() {
        if (!$resetPopup) {
            return;
        }

        var $resetIcon = $('#wp-admin-bar-royalbr_reset_node');
        if (!$resetIcon.length) {
            return;
        }

        var iconOffset = $resetIcon.offset();
        var iconWidth = $resetIcon.outerWidth();
        var popupWidth = $resetPopup.outerWidth() || 400; // Get actual width or use min-width from CSS

        // Calculate centered position
        var leftPos = iconOffset.left + (iconWidth / 2) - (popupWidth / 2);
        // Position directly below admin bar (32px normally, 46px on mobile)
        var adminBarHeight = $('#wpadminbar').outerHeight() || 32;
        var topPos = adminBarHeight;

        // Boundary checks - ensure popup doesn't go off-screen
        var minLeft = 10; // 10px from left edge
        var maxLeft = $(window).width() - popupWidth - 10; // 10px from right edge

        leftPos = Math.max(minLeft, Math.min(leftPos, maxLeft));

        // Apply position
        $resetPopup.css({
            'left': leftPos + 'px',
            'top': topPos + 'px'
        });
    }

    // Hide reset popup
    function hideResetPopup() {
        if ($resetPopup) {
            $resetPopup.removeClass('royalbr-popup-visible');
        }
    }

    // Reposition reset popup on window resize (debounced)
    var resetResizeTimeout;
    $(window).on('resize', function() {
        clearTimeout(resetResizeTimeout);
        resetResizeTimeout = setTimeout(function() {
            // Only reposition if popup is visible
            if ($resetPopup && $resetPopup.hasClass('royalbr-popup-visible')) {
                positionResetPopup();
            }
        }, 100); // 100ms debounce
    });

    // Handle reset button click from popup
    $(document).on('click', '.royalbr-popup-reset-btn', function(e) {
        e.preventDefault();

        // Gather selected options from popup
        var options = {
            reactivate_theme: $('#royalbr-popup-reactivate-theme').is(':checked'),
            reactivate_plugins: $('#royalbr-popup-reactivate-plugins').is(':checked'),
            keep_royalbr_active: $('#royalbr-popup-keep-royalbr-active').is(':checked'),
            clear_uploads: $('#royalbr-popup-clear-uploads').is(':checked'),
            clear_media: $('#royalbr-popup-clear-media').is(':checked')
        };

        // Hide the popup
        hideResetPopup();

        // Show confirmation modal
        showResetConfirmationModal(options);
    });

    // Show reset confirmation modal
    function showResetConfirmationModal(options) {
        // Load confirmation modal if not already loaded
        if (!$('#royalbr-confirmation-modal').length) {
            loadRestoreConfirmationModal().then(function() {
                proceedWithConfirmation(options);
            });
        } else {
            proceedWithConfirmation(options);
        }
    }

    // Proceed with showing confirmation
    function proceedWithConfirmation(options) {
        var $modal = $('#royalbr-confirmation-modal');

        if (!$modal.length) {
            alert('Confirmation modal not loaded. Please try again.');
            return;
        }

        // Update modal title and message
        $('#royalbr-modal-title').text('Confirm Database Reset');
        $('#royalbr-modal-message').html('Are you absolutely sure you want to reset your WordPress database? <br>It will delete all your Content and Settings.. <br><br><strong>This action cannot be undone!</strong>');

        // Set up cancel and close handlers for this modal
        $('#royalbr-confirmation-modal .royalbr-modal-close, #royalbr-modal-cancel').off('click.resetmodal').on('click.resetmodal', function() {
            $modal.hide();
            // Remove one-time handlers
            $('#royalbr-modal-confirm').off('click.resetconfirm');
            $(this).off('click.resetmodal');
        });

        // Handle click outside modal to close
        $modal.off('click.resetmodal').on('click.resetmodal', function(e) {
            if (e.target === this) {
                $modal.hide();
                // Remove one-time handlers
                $('#royalbr-modal-confirm').off('click.resetconfirm');
                $(this).off('click.resetmodal');
            }
        });

        // Show the confirmation modal
        $modal.show();

        // Handle confirm button - use one-time handler to avoid duplicates
        $('#royalbr-modal-confirm').off('click.resetconfirm').on('click.resetconfirm', function() {
            // Hide confirmation modal
            $modal.hide();

            // Load and show reset progress modal
            window.ROYALBR.loadResetProgressModal('quick-actions').then(function() {
                var $resetModal = $('#royalbr-reset-progress-modal');

                // Show simple "resetting" message
                $resetModal.find('.royalbr-reset-subtitle').text('Resetting database, please wait...');
                $resetModal.find('.royalbr-restore-components-list').html('<div style="text-align: center; padding: 40px;"><span class="royalbr-reset-spinner"></span></div>');
                $resetModal.find('.royalbr-restore-result').hide();
                $resetModal.find('.royalbr-modal-footer').hide();

                // Show modal
                $resetModal.show();

                // Step 1: Call before_reset to save active plugins
                $.ajax({
                    url: royalbr_admin_bar.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'royalbr_before_reset',
                        nonce: royalbr_admin_bar.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Step 2: Call reset_database AJAX endpoint
                            $.ajax({
                                url: royalbr_admin_bar.ajax_url,
                                type: 'POST',
                                data: {
                                    action: 'royalbr_reset_database',
                                    nonce: royalbr_admin_bar.nonce,
                                    reactivate_theme: options.reactivate_theme ? '1' : '0',
                                    reactivate_plugins: options.reactivate_plugins ? '1' : '0',
                                    keep_royalbr_active: options.keep_royalbr_active ? '1' : '0',
                                    clear_uploads: options.clear_uploads ? '1' : '0',
                                    clear_media: options.clear_media ? '1' : '0'
                                },
                                success: function(resetResponse) {
                                    if (resetResponse.success) {
                                        // Page will reload, modal will disappear automatically
                                        location.reload();
                                    } else {
                                        $resetModal.hide();
                                        alert('Reset failed: ' + (resetResponse.data || 'Unknown error'));
                                    }
                                },
                                error: function() {
                                    $resetModal.hide();
                                    alert('Reset failed. Please try again.');
                                }
                            });
                        } else {
                            $resetModal.hide();
                            alert('Failed to prepare reset: ' + (response.data || 'Unknown error'));
                        }
                    },
                    error: function() {
                        $resetModal.hide();
                        alert('Failed to prepare reset. Please try again.');
                    }
                });
            });

            // Remove the one-time handler
            $('#royalbr-modal-confirm').off('click.resetconfirm');
        });
    }

    // Load reset progress modal via AJAX
    function loadResetProgressModal() {
        // Remove existing modal to force fresh load
        $('#royalbr-reset-progress-modal').remove();

        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_reset_progress_modal_html',
                context: 'quick-actions',
                nonce: royalbr_admin_bar.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('body').append(response.data.html);
                }
            }
        });
    }

    // Show admin notice in WordPress admin
    function showAdminBarNotice(message, type) {
        var noticeClass = 'notice-error';
        if (type === 'success') {
            noticeClass = 'notice-success';
        } else if (type === 'warning') {
            noticeClass = 'notice-warning';
        } else if (type === 'info') {
            noticeClass = 'notice-info';
        }

        // Create notice element
        var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

        // Insert after first heading or at top of content
        if ($('.wrap > h1, .wrap > h2').first().length) {
            $notice.insertAfter($('.wrap > h1, .wrap > h2').first());
        } else if ($('#wpbody-content .wrap').length) {
            $notice.prependTo('#wpbody-content .wrap');
        } else {
            $notice.prependTo('#wpbody-content');
        }

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    // Expose popup functions for use by backup-reminder.js
    window.ROYALBR = window.ROYALBR || {};
    window.ROYALBR.showBackupPopup = showBackupPopup;
    window.ROYALBR.hideBackupPopup = hideBackupPopup;
    window.ROYALBR.positionBackupPopup = positionBackupPopup;
    window.ROYALBR.reminderActive = false;
    window.ROYALBR.suggestedBackupName = null;

    // Global keyboard event handler for modal shortcuts
    // ESC key closes/cancels modals, Enter key confirms/proceeds
    $(document).on('keydown', function(e) {
        // ESC key (keyCode 27) - Cancel/close modal
        if (e.keyCode === 27) {
            // Find visible confirmation modals and trigger close
            var $visibleModal = $('.royalbr-modal:visible');
            if ($visibleModal.length) {
                // Trigger close button or cancel button
                var $closeBtn = $visibleModal.find('.royalbr-modal-close').first();
                var $cancelBtn = $visibleModal.find('[id$="-cancel"]').first();

                if ($closeBtn.length) {
                    $closeBtn.trigger('click');
                } else if ($cancelBtn.length) {
                    $cancelBtn.trigger('click');
                }
            }
        }

        // Enter key (keyCode 13) - Confirm/proceed
        // Only trigger if not in a textarea or text input to avoid interfering with data entry
        if (e.keyCode === 13 && !$(e.target).is('textarea, input[type="text"]')) {
            // Find visible confirmation modals and trigger confirm/proceed button
            var $visibleModal = $('.royalbr-modal:visible');
            if ($visibleModal.length) {
                // Find proceed or confirm button
                var $proceedBtn = $visibleModal.find('[id$="-proceed"], [id$="-confirm"]').first();

                if ($proceedBtn.length) {
                    $proceedBtn.trigger('click');
                }
            }
        }
    });

    // Load pro feature modal (for free version users clicking on pro options)
    function loadProModal() {
        // Only load for non-premium users
        if (royalbr_admin_bar.is_premium) {
            return;
        }

        if ($('#royalbr-pro-modal').length) {
            return;
        }

        $.ajax({
            url: royalbr_admin_bar.ajax_url,
            type: 'POST',
            data: {
                action: 'royalbr_get_pro_modal_html',
                nonce: royalbr_admin_bar.nonce
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

        // Handle upgrade button - go to Free vs Pro tab
        $('#royalbr-pro-modal-upgrade-btn').on('click', function(e) {
            e.preventDefault();
            $modal.hide();

            // If already on the plugin page, switch tab directly
            if ($('.royalbr-nav-tab[data-tab="free-vs-pro"]').length) {
                e.stopImmediatePropagation();
                $('.royalbr-nav-tab[data-tab="free-vs-pro"]').trigger('click');
                return;
            }

            if (typeof royalbr_admin_bar !== 'undefined' && royalbr_admin_bar.admin_page_url) {
                window.location.href = royalbr_admin_bar.admin_page_url + '#free-vs-pro';
            }
        });
    }

    // Pro option click handler - show pro modal popup
    $(document).on('click', '.royalbr-pro-option-disabled, .royalbr-pro-badge', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var optionName = $(this).data('pro-option-name')
            || $(this).closest('.royalbr-pro-option-disabled').data('pro-option-name')
            || 'This feature';

        var $proModal = $('#royalbr-pro-modal');

        // Add "Exclude/Include" prefix only for Database Content
        var prefix = (optionName === 'Database Content') ? 'Exclude/Include ' : '';
        var message = prefix + '<strong>' + optionName + '</strong> is a premium feature. Upgrade to unlock this and other powerful features.';

        if ($proModal.length) {
            // Hide all other visible modals before showing pro modal
            $('.royalbr-modal:visible').not($proModal).hide();

            $('#royalbr-pro-modal-message').html(message);
            $proModal.show();
        } else {
            // Modal not loaded yet, load it first then show
            loadProModal();
            // Small delay to allow modal to load
            setTimeout(function() {
                var $modal = $('#royalbr-pro-modal');
                if ($modal.length) {
                    $('#royalbr-pro-modal-message').html(message);
                    $modal.show();
                }
            }, 500);
        }
    });

    // Preload pro modal for non-premium users
    if (!royalbr_admin_bar.is_premium) {
        loadProModal();
    }
});
