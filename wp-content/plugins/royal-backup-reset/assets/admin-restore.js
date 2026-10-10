/**
 * Royal Backup & Reset - AJAX Restore Screen Handler
 *
 * Handles streaming AJAX restore with real-time progress updates.
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

var royalbr_restore_screen = true;
jQuery(function($) {

	var task_id = $('#royalbr_ajax_restore_task_id').val();
	var action = $('#royalbr_ajax_restore_action').val();
	var $steps_list = $('.royalbr-restore-components-list');
	var previous_stage;
	var current_stage;
	var restore_log_file = '';

	$('#royalbr-restore-hidethis').remove();

	royalbr_restore_command(task_id, action);

	/**
	 * Start the restore over AJAX for the passed in task_id.
	 *
	 * @param {string}  task_id - the restore task id
	 * @param {string}  action - the restore action
	 */
	function royalbr_restore_command(task_id, action) {

		var xhttp = new XMLHttpRequest();
		var xhttp_data = 'action=' + action + '&royalbr_ajax_restore=do_ajax_restore&task_id=' + task_id + '&nonce=' + royalbr_restore.nonce;
		var previous_data_length = 0;
		var show_alert = true;

		xhttp.open("POST", royalbr_restore.ajax_url, true);
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

				// Check for RINFO messages for step tracking only
				while (i < responseText.length) {
					var buffer = responseText.substr(i, 7);
					if ('RINFO:{' == buffer) {
						// Grab what follows RINFO:
						var analyse_it = royalbr_parse_json(responseText.substr(i), true);

						console.log('ROYALBR: Processing RINFO:', analyse_it);

						// Safety check: ensure parse was successful before processing
						if (!analyse_it || !analyse_it.parsed) {
							console.log('ROYALBR: Failed to parse RINFO, skipping');
							i++;
							continue;
						}

						royalbr_restore_process_data(analyse_it.parsed);

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
			}

		// Find success/error result
		var $successResult = $(doc).find('.royalbr_restore_successful');
		var $errorResult = $(doc).find('.royalbr_restore_error');

		var $result_output = $('.royalbr-restore-result');
		var $completion = $('.royalbr-restore-completion');

		// Wait 1 second before showing completion
		setTimeout(function() {
			// Hide steps list
			$steps_list.hide();
			$steps_list.siblings('h2').hide();

			if ($successResult.length) {
				// Append auto-login token to "Return to Admin Page" link
				var tokenInput = doc.getElementById('royalbr_auto_login_token');
				if (tokenInput && tokenInput.value) {
					var $returnLink = $completion.find('a.button-primary');
					if ($returnLink.length) {
						var href = $returnLink.attr('href');
						var separator = href.indexOf('?') !== -1 ? '&' : '?';
						$returnLink.attr('href', href + separator + 'royalbr_auto_login=' + encodeURIComponent(tokenInput.value));
					}
				}
				// Success
				$result_output.find('.dashicons').addClass('dashicons-yes');
				$result_output.find('.royalbr-restore-result--text').text($successResult.text());
				$result_output.addClass('restore-success');
				$result_output.fadeIn(400);
				$completion.fadeIn(400);
			} else if ($errorResult.length) {
				// Error
				$result_output.find('.dashicons').addClass('dashicons-no-alt');

				// Show specific error message if available, otherwise show generic
				var $errorMessages = $(doc).find('.royalbr_restore_errors');
				if ($errorMessages.length && $errorMessages.text().trim()) {
					// Show the specific error (e.g., "No space left on device")
					$result_output.find('.royalbr-restore-result--text').text($errorMessages.text().trim());
					$('.royalbr-restore-error-message').html($errorMessages.html()).show();
				} else {
					$result_output.find('.royalbr-restore-result--text').text($errorResult.text());
				}

				$result_output.addClass('restore-error');
				$result_output.fadeIn(400);
				$completion.fadeIn(400);
			} else {
				// Unknown state - show error
				$result_output.find('.dashicons').addClass('dashicons-no-alt');
				$result_output.find('.royalbr-restore-result--text').text('Restore completed with unknown status');
				$result_output.addClass('restore-error');
				$result_output.fadeIn(400);
				$completion.fadeIn(400);
			}
		}, 1000);
		}
		xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
		xhttp.send(xhttp_data);
	}

	/**
	 * Process the parsed restore data and make updates to the front end
	 *
	 * @param {object} restore_data - the restore data object contains information on the restore progress to update the front end
	 */
	function royalbr_restore_process_data(restore_data) {

		if (restore_data) {
			if ('state' == restore_data.type || 'state_change' == restore_data.type) {
				console.log('ROYALBR: Stage update -', restore_data.stage, restore_data.data);
				if ('files' == restore_data.stage) {
					current_stage = restore_data.data.entity;
				} else {
					current_stage = restore_data.stage;
				}

				var $current = $steps_list.find('[data-component='+current_stage+']');

				// show simplified activity log next to the component's label
				if ('files' == restore_data.stage) {
					$current.find('.royalbr-component--progress').html(' — Restoring file <strong>'+(restore_data.data.fileindex)+'</strong> of <strong>'+restore_data.data.total_files+'</strong>');
				}

				// Database progress messages removed - no live updates shown

				// Handle error flag - mark current stage as error and stop
				if (restore_data.error) {
					$current.removeClass('active').addClass('error');
					if (restore_data.data && restore_data.data.message) {
						$current.find('.royalbr-component--progress').html(' — <span style="color:#d63638">' + restore_data.data.message + '</span>');
					}
					return; // Stop processing - don't update previous_stage
				}

				if (previous_stage !== current_stage) {
					if (previous_stage) {
						var $prev = $steps_list.find('[data-component='+previous_stage+']');
						// empty the line's status
						$prev.find('.royalbr-component--progress').html('');
						$prev.removeClass('active').addClass('done');
					}
					if ('finished' == current_stage) {
						// Mark ALL component stages as done when finished arrives
						// (The onload handler will detect actual errors via HTML markers)
						$steps_list.find('[data-component]').each(function(index, el) {
							$(el).removeClass('active').addClass('done');
						});
					} else {
						$current.addClass('active');
					}
				}
				previous_stage = current_stage;
			}
		}

	}

	/**
	 * Parse JSON from response string
	 *
	 * @param {string} str - Response string containing RINFO:{json}
	 * @param {boolean} analyse - Whether to analyse JSON structure
	 * @return {object} Parsed result with .parsed containing the JSON object
	 */
	function royalbr_parse_json(str, analyse) {
		analyse = ('undefined' === typeof analyse) ? false : true;

		var json_start_pos = str.indexOf('{');
		var json_last_pos = str.lastIndexOf('}');

		// Case where some PHP notice may be added after or before JSON string
		if (json_start_pos > -1 && json_last_pos > -1) {
			var json_str = str.slice(json_start_pos, json_last_pos + 1);
			try {
				var parsed = JSON.parse(json_str);
				return analyse ? { parsed: parsed, json_start_pos: json_start_pos, json_last_pos: json_last_pos + 1 } : parsed;
			} catch (e) {
				console.log('ROYALBR: JSON parse failed with simple method, attempting bracket counting...');

				// Bracket-counting algorithm to handle concatenated JSON objects
				var cursor = json_start_pos;
				var open_count = 0;
				var last_character = '';
				var inside_string = false;

				// Don't mistake this for a real JSON parser. Its aim is to improve the odds in real-world cases.
				while ((open_count > 0 || cursor == json_start_pos) && cursor <= json_last_pos) {

					var current_character = str.charAt(cursor);

					if (!inside_string && '{' == current_character) {
						open_count++;
					} else if (!inside_string && '}' == current_character) {
						open_count--;
					} else if ('"' == current_character && '\\' != last_character) {
						inside_string = inside_string ? false : true;
					}

					last_character = current_character;
					cursor++;
				}

				console.log('ROYALBR: Bracket counting - started at position ' + json_start_pos + ', ended at position ' + cursor);

				try {
					json_str = str.substring(json_start_pos, cursor);
					console.log('ROYALBR: Attempting to parse: ' + json_str);
					parsed = JSON.parse(json_str);
					console.log('ROYALBR: JSON re-parse successful with bracket counting');
					return analyse ? { parsed: parsed, json_start_pos: json_start_pos, json_last_pos: cursor } : parsed;
				} catch (e2) {
					console.log('ROYALBR: JSON parse error (bracket counting also failed)');
					console.log(e2);
					console.log('ROYALBR: Failed string: ' + json_str);
					return false;
				}
			}
		}

		console.log('ROYALBR: No JSON found in string');
		return false;
	}

	/**
	 * View Log button click handler
	 */
	$(document).on('click', '#royalbr-view-restore-log', function(e) {
		e.preventDefault();

		if (!restore_log_file) {
			alert('Log file not available');
			return;
		}

		// Fetch log file content via AJAX
		$.ajax({
			url: royalbr_restore.ajax_url,
			type: 'POST',
			data: {
				action: 'royalbr_get_restore_log',
				log_file: restore_log_file,
				nonce: royalbr_restore.nonce
			},
			beforeSend: function() {
				$('#royalbr-view-restore-log').prop('disabled', true).text('Loading...');
			},
			success: function(response) {
				if (response.success && response.data.log) {
					// Store log file path in window global for download functionality
					window.royalbr_current_restore_log = response.data.log_file || restore_log_file;

					// Update modal with log data
					var logFilename = response.data.filename || 'restore-log.txt';
					$('#royalbr-log-modal-filename').text(logFilename);
					$('#royalbr-log-modal-title').text('Restore Activity Log');
					$('#royalbr-log-content').text(response.data.log);
					$('#royalbr-log-popup').fadeIn();
				} else {
					alert('Failed to load log file: ' + (response.data || 'Unknown error'));
				}
			},
			error: function() {
				alert('Failed to load log file');
			},
			complete: function() {
				$('#royalbr-view-restore-log').prop('disabled', false).text('View Log');
			}
		});
	});

	// Close log modal
	$(document).on('click', '#royalbr-log-close, #royalbr-log-popup .royalbr-modal-close', function(e) {
		e.preventDefault();
		$('#royalbr-log-popup').fadeOut();
	});

});
