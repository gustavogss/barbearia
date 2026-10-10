/**
 * Royal Backup & Reset - Utility Functions
 *
 * Shared utilities used across admin.js and admin-bar.js.
 * This file loads globally on all admin pages to support Quick Actions.
 *
 * @package Royal_Backup_Reset
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Initialize global ROYALBR namespace
    window.ROYALBR = window.ROYALBR || {};

    // Track last known file entity to prevent progress going backwards
    ROYALBR.lastKnownEntity = null;

    // ========================================================================
    // UTILITY FUNCTIONS
    // ========================================================================

    /**
     * Format backup/restore progress status into readable text and percentage.
     * Progress percentages are calculated based on backup mode (DB+Files, DB only, Files only).
     *
     * @param {string} taskstatus - Current task status
     * @param {object} substatus - Optional substatus details
     * @param {boolean} includeDb - Whether database is being backed up
     * @param {boolean} includeFiles - Whether files are being backed up
     * @returns {object} {text: string, percent: number, showDots: boolean}
     */
    ROYALBR.formatProgressText = function(taskstatus, substatus, includeDb, includeFiles) {
        var text = '';
        var percent = 0;
        var showDots = true;

        // Default to both if not specified (backwards compatibility)
        if (typeof includeDb === 'undefined') includeDb = true;
        if (typeof includeFiles === 'undefined') includeFiles = true;

        // Entity names for display
        var entityNames = {
            'plugins': 'Plugin Files',
            'themes': 'Theme Files',
            'uploads': 'Media Files',
            'others': 'Additional Content',
            'wpcore': 'WordPress Core'
        };

        // Progress percentages based on backup mode
        // DB + Files: init=5, db=20, plugins=40, themes=55, uploads=80, others/done=100
        // DB Only: init=5, db/done=100
        // Files Only: init=5, plugins=30, themes=50, uploads=80, others/done=100
        var dbAndFiles = includeDb && includeFiles;
        var dbOnly = includeDb && !includeFiles;
        var filesOnly = !includeDb && includeFiles;

        switch (taskstatus) {
            case 'begun':
                text = 'Preparing backup';
                percent = 5;
                break;

            case 'dbcreating':
                text = 'Exporting database';
                if (substatus && substatus.t) {
                    text += ' (table: ' + substatus.t;
                    if (substatus.r) {
                        text += ', ' + substatus.r.toLocaleString() + ' rows';
                    }
                    text += ')';
                }
                // Midpoint of database stage
                percent = dbOnly ? 50 : 12;
                break;

            case 'dbcreated':
                text = 'Database export complete';
                percent = dbOnly ? 100 : 20;
                showDots = false;
                break;

            case 'filescreating':
                text = 'Archiving files';

                // Use substatus entity if available, otherwise use last known
                var entity = (substatus && substatus.e) ? substatus.e : ROYALBR.lastKnownEntity;

                if (entity) {
                    // Update last known entity
                    ROYALBR.lastKnownEntity = entity;

                    text += ' (' + (entityNames[entity] || entity) + ')';

                    // Calculate percent based on entity and mode
                    if (dbAndFiles) {
                        // DB + Files mode
                        switch (entity) {
                            case 'plugins': percent = 40; break;
                            case 'themes': percent = 55; break;
                            case 'uploads': percent = 70; break;
                            case 'others': percent = 85; break;
                            case 'wpcore': percent = 95; break;
                            default: percent = 40;
                        }
                    } else if (filesOnly) {
                        // Files only mode
                        switch (entity) {
                            case 'plugins': percent = 30; break;
                            case 'themes': percent = 50; break;
                            case 'uploads': percent = 65; break;
                            case 'others': percent = 80; break;
                            case 'wpcore': percent = 95; break;
                            default: percent = 30;
                        }
                    }
                } else {
                    // No entity known yet - show starting percent
                    percent = dbAndFiles ? 25 : 10;
                }
                break;

            case 'filescreated':
                text = 'File archiving complete';
                percent = 100;
                showDots = false;
                break;

            case 'finished':
                text = 'Backup complete!';
                percent = 100;
                showDots = false;
                break;

            default:
                text = 'Initializing';
                percent = 0;
        }

        return {text: text, percent: percent, showDots: showDots};
    };

    /**
     * Parse JSON from streamed restore response with bracket counting fallback.
     *
     * Handles cases where PHP notices or concatenated JSON objects may exist
     * in the response string. Uses a bracket-counting algorithm to find the
     * complete JSON object boundaries.
     *
     * @param {string} str - String containing JSON
     * @param {boolean} analyse - If true, return detailed parse info
     * @returns {object|boolean} Parsed JSON or false on failure
     */
    ROYALBR.parseJson = function(str, analyse) {
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
    };

    /**
     * Escape HTML entities to prevent XSS.
     *
     * @param {string} text - Text to escape
     * @returns {string} Escaped text
     */
    ROYALBR.escapeHtml = function(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    };

    /**
     * Update progress text element with animated dots for in-progress states.
     *
     * @param {jQuery} $element - The progress text element
     * @param {string} text - The progress text
     * @param {boolean} showDots - Whether to show animated dots
     */
    ROYALBR.updateProgressText = function($element, text, showDots) {
        if (showDots) {
            $element.html(
                ROYALBR.escapeHtml(text) +
                ' <span class="royalbr-progress-dots"><span>.</span><span>.</span><span>.</span></span>'
            );
        } else {
            $element.text(text);
        }
    };

})(jQuery);
