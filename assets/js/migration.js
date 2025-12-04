/* eslint-disable no-console */
console.debug('BlitzCDN migration script loaded');

if (typeof jQuery === 'undefined') {
    console.error('BlitzCDN: jQuery is required for the migration script but not found.');
} else {
    jQuery(document).ready(function ($) {
        // Fallbacks if localization failed
        var _ajax_url = (typeof blitzcdn_migration !== 'undefined' && blitzcdn_migration.ajax_url) ? blitzcdn_migration.ajax_url : null;
        var _nonce = (typeof blitzcdn_migration !== 'undefined' && blitzcdn_migration.nonce) ? blitzcdn_migration.nonce : null;
        // Try to read from button data attributes as a fallback
        if (!_ajax_url) {
            var elButton = document.querySelector('#blitzcdn-redownload-btn') || document.querySelector('#blitzcdn-migrate-btn') || document.querySelector('#blitzcdn-background-migrate-btn');
            if (elButton && elButton.dataset && elButton.dataset.ajaxUrl) {
                _ajax_url = elButton.dataset.ajaxUrl;
            }
        }
        if (!_nonce) {
            var elNonceBtn = document.querySelector('#blitzcdn-redownload-btn') || document.querySelector('#blitzcdn-migrate-btn');
            if (elNonceBtn && elNonceBtn.dataset && elNonceBtn.dataset.nonce) {
                _nonce = elNonceBtn.dataset.nonce;
            }
        }

        // Helper for making AJAX posts with fallback
        function ajaxPost(payload, successCallback, errorCallback) {
            if (!_ajax_url || !_nonce) {
                console.error('BlitzCDN: Missing ajax_url or nonce; cannot make AJAX call.');
                if (typeof errorCallback === 'function') errorCallback({ error: 'Missing ajax_url or nonce' });
                return;
            }

            payload.nonce = payload.nonce || _nonce;

            $.post(_ajax_url, payload, function (response) {
                console.debug('BlitzCDN AJAX success for action=' + payload.action, response);
                if (typeof successCallback === 'function') successCallback(response);
            }).fail(function (xhr, status, err) {
                console.error('BlitzCDN AJAX error for action=' + payload.action + ':', status, err, xhr);
                if (typeof errorCallback === 'function') errorCallback(xhr, status, err);
            });
        }
        // ============================================
        // MIGRATION (Upload to CDN) Logic
        // ============================================
        var isMigrating = false;
        var totalItems = 0;
        var processedItems = 0;
        var batchSize = (typeof blitzcdn_migration !== 'undefined' && blitzcdn_migration.migration_batch_size) ? parseInt(blitzcdn_migration.migration_batch_size, 10) : 20;
        var itemIds = [];

        $(document).on('click', '#blitzcdn-migrate-btn', function (e) {
            e.preventDefault();
            if (isMigrating) return;

            if (!confirm('Are you sure you want to migrate existing media to BlitzCDN? This may take a while.')) {
                return;
            }

            startMigration();
        });

        function startMigration() {
            isMigrating = true;
            $('#blitzcdn-migrate-btn').prop('disabled', true);
            $('#blitzcdn-migration-progress').show();
            $('#blitzcdn-migration-log').empty();
            log('Starting migration...', 'info');

            // Get stats
            ajaxPost({
                action: 'blitzcdn_get_migration_stats'
            }, function (response) {
                if (response.success) {
                    totalItems = response.data.total;
                    itemIds = response.data.ids.slice(); // Clone array
                    log('Found ' + totalItems + ' items to migrate.', 'info');

                    if (totalItems > 0) {
                        processBatch();
                    } else {
                        finishMigration();
                    }
                } else {
                    log('Error fetching stats: ' + (response.data || 'Unknown error'), 'error');
                    isMigrating = false;
                    $('#blitzcdn-migrate-btn').prop('disabled', false);
                }
            }, function (xhr, status, error) {
                log('Network error fetching stats: ' + error, 'error');
                isMigrating = false;
                $('#blitzcdn-migrate-btn').prop('disabled', false);
            });
        }

        function processBatch() {
            if (itemIds.length === 0) {
                finishMigration();
                return;
            }

            var batch = itemIds.splice(0, batchSize);
            log('Processing batch of ' + batch.length + ' items...', 'info');

            ajaxPost({
                action: 'blitzcdn_migrate_batch',
                ids: batch
            }, function (response) {
                processedItems += batch.length;
                updateProgress();

                if (response.success) {
                    $.each(response.data, function (id, result) {
                        var attachmentLink = '<a href="' + window.location.origin + '/wp-admin/post.php?post=' + id + '&action=edit" target="_blank">#' + id + '</a>';
                        if (result.status === 'success') {
                            log(attachmentLink + ': Success', 'success');
                        } else {
                            log(attachmentLink + ': Failed - ' + result.message, 'error');
                        }
                    });
                } else {
                    log('Batch failed: ' + (response.data || 'Unknown error'), 'error');
                }

                // Continue to next batch (with small delay to prevent overwhelming server and allow UI updates)
                setTimeout(function () {
                    processBatch();
                }, 100);
            }).fail(function (xhr, status, error) {
                log('Network error processing batch: ' + error, 'error');
                processedItems += batch.length;
                updateProgress();

                // Try to continue with remaining items
                setTimeout(function () {
                    processBatch();
                }, 1000); // Longer delay after error
            });
        }

        function updateProgress() {
            var percent = totalItems > 0 ? Math.round((processedItems / totalItems) * 100) : 0;
            if (percent > 100) percent = 100;
            $('#blitzcdn-progress-bar').css('width', percent + '%');
            $('#blitzcdn-progress-text').text(percent + '% (' + processedItems + '/' + totalItems + ' items processed)');
        }

        function finishMigration() {
            isMigrating = false;
            $('#blitzcdn-migrate-btn').prop('disabled', false);

            // Final summary
            log('', 'info'); // Empty line
            log('----------------------------------------', 'info');
            log('MIGRATION COMPLETE', 'info');
            log('----------------------------------------', 'info');
            log('Processed: ' + processedItems + ' / ' + totalItems + ' items', 'success');

            // Show completion alert
            alert('Migration complete! Processed ' + processedItems + ' / ' + totalItems + ' items.');
        }

        function log(message, type) {
            var timestamp = new Date().toLocaleTimeString();
            var color = '#d4d4d4'; // Default gray

            switch (type) {
                case 'success':
                    color = '#4ec9b0';
                    break;
                case 'error':
                    color = '#f14c4c';
                    break;
                case 'warning':
                    color = '#cca700';
                    break;
                case 'info':
                    color = '#3794ff';
                    break;
            }

            var logHtml = '<div style="color: ' + color + '; margin-bottom: 4px;">';
            if (message) {
                logHtml += '<span style="color: #6a9955;">[' + timestamp + ']</span> ' + message;
            }
            logHtml += '</div>';

            $('#blitzcdn-migration-log').append(logHtml);

            // Auto-scroll to bottom
            var logContainer = document.getElementById('blitzcdn-migration-log');
            if (logContainer) {
                logContainer.scrollTop = logContainer.scrollHeight;
            }
        }

        // ============================================
        // Background Migration Logic
        // ============================================
        var bgPollInterval;

        function checkBackgroundStatus() {
            ajaxPost({
                action: 'blitzcdn_get_background_status'
            }, function (response) {
                if (response.success) {
                    var status = response.data;
                    updateBackgroundUI(status);
                }
            });
        }

        function updateBackgroundUI(status) {
            $('#blitzcdn-bg-status-text').text(status.status);

            if (status.status === 'running') {
                $('#blitzcdn-background-status').show();
                $('#blitzcdn-background-migrate-btn').hide();
                $('#blitzcdn-stop-background-migrate-btn').show();
                $('#blitzcdn-bg-processed').text(status.processed);
                $('#blitzcdn-bg-total').text(status.total);

                if (!bgPollInterval) {
                    bgPollInterval = setInterval(checkBackgroundStatus, 1000); // Poll every 1 second for better reactivity
                }
            } else {
                $('#blitzcdn-background-status').hide(); // Hide when not running
                $('#blitzcdn-background-migrate-btn').show();
                $('#blitzcdn-stop-background-migrate-btn').hide();
                if (status.completed_time) {
                    $('#blitzcdn-bg-status-text').text('Completed at ' + new Date(status.completed_time * 1000).toLocaleTimeString());
                    $('#blitzcdn-bg-processed').text(status.processed);
                    $('#blitzcdn-bg-total').text(status.total);
                }

                if (bgPollInterval) {
                    clearInterval(bgPollInterval);
                    bgPollInterval = null;
                }
            }
        }

        $(document).on('click', '#blitzcdn-background-migrate-btn', function (e) {
            e.preventDefault();
            if (!confirm('Start background migration? This will run on the server.')) return;

            ajaxPost({
                action: 'blitzcdn_start_background_migration'
            }, function (response) {
                if (response.success) {
                    checkBackgroundStatus();
                } else {
                    alert('Failed to start: ' + response.data);
                }
            });
        });

        $(document).on('click', '#blitzcdn-stop-background-migrate-btn', function (e) {
            e.preventDefault();
            ajaxPost({
                action: 'blitzcdn_stop_background_migration'
            }, function (response) {
                checkBackgroundStatus();
            });
        });

        // Initial check
        checkBackgroundStatus();

        // ============================================
        // GOODBYE / REDOWNLOAD Logic
        // ============================================
    var isRedownloading = false;
    var redownloadCancelled = false;
    var redownloadTotalItems = 0;
    var redownloadProcessedItems = 0;
    var redownloadBatchSize = (typeof blitzcdn_migration !== 'undefined' && blitzcdn_migration.redownload_batch_size) ? parseInt(blitzcdn_migration.redownload_batch_size, 10) : 5; // Smaller batch size for downloads (they're heavier)
    var redownloadItemIds = [];
        var redownloadStats = {
            success: 0,
            skipped: 0,
            errors: 0
        };

        $(document).on('click', '#blitzcdn-redownload-btn', function (e) {
            console.debug('BlitzCDN: Redownload button clicked');
            e.preventDefault();
            if (isRedownloading) {
                console.warn('BlitzCDN: Redownload already in progress, ignoring click');
                return;
            }

            var deleteFromAppwrite = $('#blitzcdn-delete-after-redownload').is(':checked');
            var warningMessage = 'Are you sure you want to start the Goodbye Procedure?\n\n';
            warningMessage += 'This will:\n';
            warningMessage += '• Download all media files from Appwrite back to WordPress\n';
            warningMessage += '• Clear CDN metadata so URLs point to local files\n';

            if (deleteFromAppwrite) {
                warningMessage += '• DELETE files from Appwrite after successful download\n';
                warningMessage += '\nWARNING: Files will be permanently deleted from Appwrite!';
            }

            warningMessage += '\n\nThis process may take a while. Keep this tab open.';

            console.debug('BlitzCDN: Showing confirmation dialog');
            if (!confirm(warningMessage)) {
                console.debug('BlitzCDN: User cancelled the procedure');
                return;
            }

            console.debug('BlitzCDN: User confirmed, starting redownload procedure');
            startRedownload(deleteFromAppwrite);
        });

        $(document).on('click', '#blitzcdn-cancel-redownload-btn', function (e) {
            e.preventDefault();
            if (!isRedownloading) return;

            if (confirm('Are you sure you want to cancel the Goodbye Procedure?\n\nFiles already processed will remain in their current state.')) {
                redownloadCancelled = true;
                redownloadLog('Cancellation requested. Stopping after current batch...', 'warning');
            }
        });

        function startRedownload(deleteFromAppwrite) {
            isRedownloading = true;
            redownloadCancelled = false;
            redownloadProcessedItems = 0;
            redownloadStats = { success: 0, skipped: 0, errors: 0 };

            // Update UI
            $('#blitzcdn-redownload-btn').prop('disabled', true).hide();
            $('#blitzcdn-cancel-redownload-btn').show();
            $('#blitzcdn-redownload-progress').show();
            $('#blitzcdn-delete-after-redownload').prop('disabled', true);

            // Reset stats display
            updateRedownloadStats();
            $('#blitzcdn-redownload-log').empty();

            redownloadLog('Starting Goodbye Procedure...', 'info');
            if (deleteFromAppwrite) {
                redownloadLog('Delete from Appwrite is ENABLED - files will be removed after download', 'warning');
            }

            // Get stats
            console.debug('BlitzCDN: Fetching redownload stats...');
            ajaxPost({
                action: 'blitzcdn_get_redownload_stats'
            }, function (response) {
                console.debug('BlitzCDN: Redownload stats response:', response);
                if (response.success) {
                    redownloadTotalItems = response.data.total;
                    redownloadItemIds = response.data.ids.slice(); // Clone array

                    if (redownloadTotalItems === 0) {
                        redownloadLog('No items found with BlitzCDN metadata. Nothing to redownload.', 'success');
                        finishRedownload();
                        return;
                    }

                    redownloadLog('Found ' + redownloadTotalItems + ' attachments to process', 'info');
                    processRedownloadBatch(deleteFromAppwrite);
                } else {
                    console.error('BlitzCDN: Failed to get redownload stats:', response);
                    redownloadLog('Error fetching stats: ' + (response.data || 'Unknown error'), 'error');
                    finishRedownload();
                }
            }, function (xhr, status, error) {
                console.error('BlitzCDN: Network error fetching stats:', error);
                redownloadLog('Network error fetching stats: ' + error, 'error');
                finishRedownload();
            });
        }

        function processRedownloadBatch(deleteFromAppwrite) {
            // Check for cancellation
            if (redownloadCancelled) {
                redownloadLog('Process cancelled by user', 'warning');
                finishRedownload();
                return;
            }

            if (redownloadItemIds.length === 0) {
                finishRedownload();
                return;
            }

            var batch = redownloadItemIds.splice(0, redownloadBatchSize);
            redownloadLog('Processing batch of ' + batch.length + ' items...', 'info');

            ajaxPost({
                action: 'blitzcdn_redownload_batch',
                ids: batch,
                delete_from_appwrite: deleteFromAppwrite ? 'true' : 'false'
            }, function (response) {
                redownloadProcessedItems += batch.length;
                updateRedownloadProgress();

                if (response.success) {
                    $.each(response.data, function (id, result) {
                        var attachmentLink = '<a href="' + window.location.origin + '/wp-admin/post.php?post=' + id + '&action=edit" target="_blank">#' + id + '</a>';

                        // Check if original file already existed locally
                        var originalAlreadyExists = result.details && result.details.original && result.details.original.status === 'exists';

                        if (result.status === 'success') {
                            if (originalAlreadyExists) {
                                // File was already local - count as skipped, not success
                                redownloadStats.skipped++;
                                redownloadLog(attachmentLink + ': Already exists locally (metadata cleared)', 'info');
                            } else {
                                redownloadStats.success++;
                                var msg = attachmentLink + ': ' + result.message;
                                if (result.details && result.details.deleted_from_appwrite) {
                                    msg += ' (Deleted ' + result.details.deleted_count + ' from Appwrite)';
                                }
                                redownloadLog(msg, 'success');
                            }
                        } else if (result.status === 'partial') {
                            redownloadStats.errors++;
                            redownloadLog(attachmentLink + ': ' + result.message, 'warning');
                            logDetailedResults(id, result.details);
                        } else if (result.status === 'error') {
                            redownloadStats.errors++;
                            redownloadLog(attachmentLink + ': ' + result.message, 'error');
                        }
                    });
                } else {
                    redownloadLog('Batch failed: ' + (response.data || 'Unknown error'), 'error');
                    redownloadStats.errors += batch.length;
                }

                updateRedownloadStats();

                // Continue to next batch (with small delay to prevent overwhelming server)
                setTimeout(function () {
                    processRedownloadBatch(deleteFromAppwrite);
                }, 100);

            }).fail(function (xhr, status, error) {
                redownloadLog('Network error processing batch: ' + error, 'error');
                redownloadStats.errors += batch.length;
                updateRedownloadStats();

                // Try to continue with remaining items
                redownloadProcessedItems += batch.length;
                updateRedownloadProgress();

                setTimeout(function () {
                    processRedownloadBatch(deleteFromAppwrite);
                }, 1000); // Longer delay after error
            });
        }

        function logDetailedResults(attachmentId, details) {
            if (!details) return;

            if (details.original) {
                var origStatus = details.original.status;
                var origMsg = details.original.message || '';
                if (origStatus === 'error') {
                    redownloadLog('   Original: ' + origMsg, 'error');
                } else if (origStatus === 'exists') {
                    redownloadLog('   Original: ' + origMsg, 'info');
                }
            }

            if (details.sizes && typeof details.sizes === 'object') {
                $.each(details.sizes, function (sizeName, sizeResult) {
                    if (sizeResult.status === 'error') {
                        redownloadLog('   Size "' + sizeName + '": ' + (sizeResult.message || ''), 'error');
                    } else if (sizeResult.status === 'skipped') {
                        redownloadLog('   Size "' + sizeName + '": ' + (sizeResult.message || ''), 'info');
                    }
                });
            }
        }

        function updateRedownloadProgress() {
            var percent = redownloadTotalItems > 0 ? Math.round((redownloadProcessedItems / redownloadTotalItems) * 100) : 0;
            if (percent > 100) percent = 100;

            $('#blitzcdn-redownload-progress-bar').css('width', percent + '%');
            $('#blitzcdn-redownload-progress-text').text(percent + '% (' + redownloadProcessedItems + '/' + redownloadTotalItems + ' attachments processed)');
        }

        function updateRedownloadStats() {
            $('#blitzcdn-success-count').text(redownloadStats.success);
            $('#blitzcdn-skipped-count').text(redownloadStats.skipped);
            $('#blitzcdn-error-count').text(redownloadStats.errors);
        }

        function finishRedownload() {
            isRedownloading = false;

            // Update UI
            $('#blitzcdn-redownload-btn').prop('disabled', false).show();
            $('#blitzcdn-cancel-redownload-btn').hide();
            $('#blitzcdn-delete-after-redownload').prop('disabled', false);

            // Final summary
            redownloadLog('', 'info'); // Empty line
            redownloadLog('----------------------------------------', 'info');
            redownloadLog('GOODBYE PROCEDURE COMPLETE', 'info');
            redownloadLog('----------------------------------------', 'info');
            redownloadLog('Downloaded: ' + redownloadStats.success + ' attachments', 'success');
            redownloadLog('Already Local: ' + redownloadStats.skipped + ' attachments', 'info');
            redownloadLog('Errors: ' + redownloadStats.errors + ' attachments', redownloadStats.errors > 0 ? 'error' : 'info');

            if (redownloadStats.errors > 0) {
                redownloadLog('', 'info');
                redownloadLog('Some files had errors. Review the log above and try again for failed items.', 'warning');
            }

            if (redownloadCancelled) {
                redownloadLog('', 'info');
                redownloadLog('Process was cancelled. Some items may not have been processed.', 'warning');
            }

            // Show completion alert
            var alertMessage = 'Goodbye Procedure Complete!\n\n';
            alertMessage += 'Downloaded: ' + redownloadStats.success + '\n';
            alertMessage += 'Already Local: ' + redownloadStats.skipped + '\n';
            alertMessage += 'Errors: ' + redownloadStats.errors;

            if (redownloadStats.errors > 0) {
                alertMessage += '\n\nSome files had errors. Check the log for details.';
            }

            alert(alertMessage);
        }

        function redownloadLog(message, type) {
            var timestamp = new Date().toLocaleTimeString();
            var color = '#d4d4d4'; // Default gray

            switch (type) {
                case 'success':
                    color = '#4ec9b0';
                    break;
                case 'error':
                    color = '#f14c4c';
                    break;
                case 'warning':
                    color = '#cca700';
                    break;
                case 'info':
                    color = '#3794ff';
                    break;
            }

            var logHtml = '<div style="color: ' + color + '; margin-bottom: 4px;">';
            if (message) {
                logHtml += '<span style="color: #6a9955;">[' + timestamp + ']</span> ' + message;
            }
            logHtml += '</div>';

            $('#blitzcdn-redownload-log').append(logHtml);

            // Auto-scroll to bottom
            var logContainer = document.getElementById('blitzcdn-redownload-log');
            if (logContainer) {
                logContainer.scrollTop = logContainer.scrollHeight;
            }
        }
    });
}
