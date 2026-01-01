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
        // MIGRATION (Upload to CDN) Logic
        var isMigrating = false;
        var totalItems = 0; // Total images
        var totalAssets = 0; // Total assets (images * ~4)
        var processedItems = 0; // Processed images
        var processedAssets = 0; // Processed assets
        var batchSize = (typeof blitzcdn_migration !== 'undefined' && blitzcdn_migration.migration_batch_size) ? parseInt(blitzcdn_migration.migration_batch_size, 10) : 20;
        var itemIds = [];
        var migrationFinalized = false; // When true, keep migration UI in final state and ignore transient updates

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
            migrationFinalized = false; // starting a fresh run
            $('#blitzcdn-migrate-btn').prop('disabled', true);
            $('#blitzcdn-migration-progress').show();
            $('#blitzcdn-migration-log').empty();
            log('Starting migration...', 'info');

            // Get stats
            ajaxPost({
                action: 'blitzcdn_get_migration_stats'
            }, function (response) {
                if (response.success) {
                    totalAssets = response.data.total; // Total assets (original + sizes)
                    totalItems = response.data.total_images || response.data.ids.length; // Total images for reference
                    itemIds = response.data.ids.slice(); // Clone array
                    processedAssets = 0;
                    processedItems = 0;
                    log('Found ' + totalItems + ' images (' + totalAssets + ' assets) to migrate.', 'info');

                    if (totalAssets > 0) {
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

                // Count assets processed in this batch
                if (response.success) {
                    $.each(response.data, function (id, result) {
                        var attachmentLink = '<a href="' + window.location.origin + '/wp-admin/post.php?post=' + id + '&action=edit" target="_blank">#' + id + '</a>';
                        if (result.status === 'success') {
                            // Add assets count for this image (default to 4 if not provided)
                            var assetsInImage = result.assets_count || 4;
                            processedAssets += assetsInImage;
                            log(attachmentLink + ': Success (' + assetsInImage + ' assets)', 'success');
                        } else {
                            // On error, still count as 1 asset to avoid progress issues
                            processedAssets += 1;
                            log(attachmentLink + ': Failed - ' + result.message, 'error');
                        }
                    });
                } else {
                    // On batch failure, count each image as 4 assets (typical)
                    processedAssets += batch.length * 4;
                    log('Batch failed: ' + (response.data || 'Unknown error'), 'error');
                }

                updateProgress();

                // Continue to next batch (with small delay to prevent overwhelming server and allow UI updates)
                setTimeout(function () {
                    processBatch();
                }, 100);
            }).fail(function (xhr, status, error) {
                log('Network error processing batch: ' + error, 'error');
                processedItems += batch.length;
                // On network error, estimate assets (4 per image)
                processedAssets += batch.length * 4;
                updateProgress();

                // Try to continue with remaining items
                setTimeout(function () {
                    processBatch();
                }, 1000); // Longer delay after error
            });
        }

        function updateProgress() {
            // If we've finalized the UI, don't overwrite the completed display
            if (migrationFinalized) return;

            // Use asset count for progress, not image count
            var percent = totalAssets > 0 ? Math.round((processedAssets / totalAssets) * 100) : 0;
            if (percent > 100) percent = 100;
            $('#blitzcdn-progress-bar').css('width', percent + '%');

            // If complete, show a friendly completed state instead of a plain percentage
            if (percent === 100 && totalAssets > 0) {
                $('#blitzcdn-progress-bar').css('background', 'linear-gradient(90deg, #28a745, #4ec9b0)');
                $('#blitzcdn-progress-text').html('✅ <strong>Completed</strong> — ' + processedAssets + '/' + totalAssets + ' assets processed (' + processedItems + '/' + totalItems + ' images)');
            } else {
                // Reset to default color for non-complete states
                $('#blitzcdn-progress-bar').css('background', 'linear-gradient(90deg, #2271b1, #3794ff)');
                $('#blitzcdn-progress-text').text(percent + '% (' + processedAssets + '/' + totalAssets + ' assets processed, ' + processedItems + '/' + totalItems + ' images)');
            }
        }

        function finishMigration() {
            isMigrating = false;
            $('#blitzcdn-migrate-btn').prop('disabled', false);

            // Final summary
            log('', 'info'); // Empty line
            log('----------------------------------------', 'info');
            log('MIGRATION COMPLETE', 'info');
            log('----------------------------------------', 'info');
            log('Processed: ' + processedAssets + ' / ' + totalAssets + ' assets (' + processedItems + ' / ' + totalItems + ' images)', 'success');

            // Force progress to completed state in UI
            migrationFinalized = true; // freeze migration UI from further updates
            $('#blitzcdn-progress-bar').css('width', '100%').css('background', 'linear-gradient(90deg, #28a745, #4ec9b0)');
            $('#blitzcdn-progress-text').html('✅ <strong>Completed</strong> — ' + processedAssets + '/' + totalAssets + ' assets processed (' + processedItems + '/' + totalItems + ' images)');

            // Show completion alert
            alert('Migration complete! Processed ' + processedAssets + ' / ' + totalAssets + ' assets (' + processedItems + ' / ' + totalItems + ' images).');
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

        // Background Migration Logic
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
                // Show assets count (processed/total assets)
                var processedText = status.processed || 0;
                var totalText = status.total || 0;
                if (status.processed_images !== undefined && status.total_images !== undefined) {
                    processedText += ' assets (' + status.processed_images + ' images)';
                    totalText += ' assets (' + status.total_images + ' images)';
                } else {
                    processedText += ' assets';
                    totalText += ' assets';
                }
                $('#blitzcdn-bg-processed').text(processedText);
                $('#blitzcdn-bg-total').text(totalText);

                if (!bgPollInterval) {
                    bgPollInterval = setInterval(checkBackgroundStatus, 1000); // Poll every 1 second for better reactivity
                }
            } else {
                $('#blitzcdn-background-status').hide(); // Hide when not running
                $('#blitzcdn-background-migrate-btn').show();
                $('#blitzcdn-stop-background-migrate-btn').hide();
                if (status.completed_time) {
                    $('#blitzcdn-bg-status-text').text('Completed at ' + new Date(status.completed_time * 1000).toLocaleTimeString());
                    var processedText = status.processed || 0;
                    var totalText = status.total || 0;
                    if (status.processed_images !== undefined && status.total_images !== undefined) {
                        processedText += ' assets (' + status.processed_images + ' images)';
                        totalText += ' assets (' + status.total_images + ' images)';
                    } else {
                        processedText += ' assets';
                        totalText += ' assets';
                    }
                    $('#blitzcdn-bg-processed').text(processedText);
                    $('#blitzcdn-bg-total').text(totalText);
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


        // GOODBYE / REDOWNLOAD Logic

        var isRedownloading = false;
        var redownloadCancelled = false;
        var redownloadTotalItems = 0; // Total images
        var redownloadTotalAssets = 0; // Total assets (images * ~4)
        var redownloadProcessedItems = 0; // Processed images
        var redownloadProcessedAssets = 0; // Processed assets
        var redownloadBatchSize = (typeof blitzcdn_migration !== 'undefined' && blitzcdn_migration.redownload_batch_size) ? parseInt(blitzcdn_migration.redownload_batch_size, 10) : 5; // Smaller batch size for downloads (they're heavier)
        var redownloadItemIds = [];
        var redownloadStats = {
            success: 0,
            skipped: 0,
            errors: 0
        };
        var redownloadFinalized = false; // When true, preserve redownload final UI state

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
            redownloadFinalized = false; // starting a fresh redownload run
            redownloadCancelled = false;
            redownloadProcessedItems = 0;
            redownloadProcessedAssets = 0;
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
                    redownloadTotalAssets = response.data.total; // Total assets (original + sizes)
                    redownloadTotalItems = response.data.total_images || response.data.ids.length; // Total images for reference
                    redownloadItemIds = response.data.ids.slice(); // Clone array

                    if (redownloadTotalAssets === 0) {
                        redownloadLog('No items found with BlitzCDN metadata. Nothing to redownload.', 'success');
                        finishRedownload();
                        return;
                    }

                    redownloadLog('Found ' + redownloadTotalItems + ' images (' + redownloadTotalAssets + ' assets) to process', 'info');
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

                if (response.success) {
                    $.each(response.data, function (id, result) {
                        var attachmentLink = '<a href="' + window.location.origin + '/wp-admin/post.php?post=' + id + '&action=edit" target="_blank">#' + id + '</a>';

                        // Count assets processed for this attachment
                        var assetsInImage = 1; // Original
                        if (result.details && result.details.sizes) {
                            assetsInImage += Object.keys(result.details.sizes).length;
                        }
                        redownloadProcessedAssets += assetsInImage;

                        // Check if original file already existed locally
                        var originalAlreadyExists = result.details && result.details.original && result.details.original.status === 'exists';

                        if (result.status === 'success') {
                            if (originalAlreadyExists) {
                                // File was already local - count as skipped, not success
                                redownloadStats.skipped++;
                                redownloadLog(attachmentLink + ': Already exists locally (metadata cleared) - ' + assetsInImage + ' assets', 'info');
                            } else {
                                redownloadStats.success++;
                                var msg = attachmentLink + ': ' + result.message + ' (' + assetsInImage + ' assets)';
                                if (result.details && result.details.deleted_from_appwrite) {
                                    msg += ' (Deleted ' + result.details.deleted_count + ' from Appwrite)';
                                }
                                redownloadLog(msg, 'success');
                            }
                        } else if (result.status === 'partial') {
                            redownloadStats.errors++;
                            redownloadLog(attachmentLink + ': ' + result.message + ' (' + assetsInImage + ' assets)', 'warning');
                            logDetailedResults(id, result.details);
                        } else if (result.status === 'error') {
                            redownloadStats.errors++;
                            redownloadLog(attachmentLink + ': ' + result.message, 'error');
                        }
                    });
                } else {
                    redownloadLog('Batch failed: ' + (response.data || 'Unknown error'), 'error');
                    redownloadStats.errors += batch.length;
                    // Estimate assets (4 per image) on batch failure
                    redownloadProcessedAssets += batch.length * 4;
                }

                updateRedownloadProgress();
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
                // Estimate assets (4 per image) on network error
                redownloadProcessedAssets += batch.length * 4;
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
            // If we've finalized the redownload UI, don't overwrite the completed display
            if (redownloadFinalized) return;

            // Use asset count for progress, not image count
            var percent = redownloadTotalAssets > 0 ? Math.round((redownloadProcessedAssets / redownloadTotalAssets) * 100) : 0;
            if (percent > 100) percent = 100;

            $('#blitzcdn-redownload-progress-bar').css('width', percent + '%');

            if (percent === 100 && redownloadTotalAssets > 0) {
                $('#blitzcdn-redownload-progress-bar').css('background', 'linear-gradient(90deg, #28a745, #4ec9b0)');
                $('#blitzcdn-redownload-progress-text').html('✅ <strong>Completed</strong> — ' + redownloadProcessedAssets + '/' + redownloadTotalAssets + ' assets processed (' + redownloadProcessedItems + '/' + redownloadTotalItems + ' images)');
            } else {
                $('#blitzcdn-redownload-progress-bar').css('background', 'linear-gradient(90deg, #dc3545, #fd7e14)');
                $('#blitzcdn-redownload-progress-text').text(percent + '% (' + redownloadProcessedAssets + '/' + redownloadTotalAssets + ' assets processed, ' + redownloadProcessedItems + '/' + redownloadTotalItems + ' images)');
            }
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

            // Force progress to completed state in UI
            redownloadFinalized = true; // freeze redownload UI from further updates
            $('#blitzcdn-redownload-progress-bar').css('width', '100%').css('background', 'linear-gradient(90deg, #28a745, #4ec9b0)');
            $('#blitzcdn-redownload-progress-text').html('✅ <strong>Completed</strong> — ' + redownloadProcessedAssets + '/' + redownloadTotalAssets + ' assets processed (' + redownloadProcessedItems + '/' + redownloadTotalItems + ' images)');

            // Final summary
            redownloadLog('', 'info'); // Empty line
            redownloadLog('----------------------------------------', 'info');
            redownloadLog('GOODBYE PROCEDURE COMPLETE', 'info');
            redownloadLog('----------------------------------------', 'info');
            redownloadLog('Processed: ' + redownloadProcessedAssets + ' / ' + redownloadTotalAssets + ' assets (' + redownloadProcessedItems + ' / ' + redownloadTotalItems + ' images)', 'success');
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

        // ZIP-BASED (FAST) MIGRATION Logic
        var zipMigrationStatusPollInterval = null;
        var zipSafeToQuitAlertShown = false;
        var zipMigrationInProgress = false;
        var zipCurrentBatchConfirmed = false; // Track if current batch was confirmed by middleware
        var zipTotalAssets = null; // Total assets (files) to process for current migration
        var zipUploadedFiles = 0; // Accumulate files added to middleware across batches (client-side)
        var zipFinalized = false; // Guard: once true, keep final UI state and ignore transient polls

        // Load initial stats for zip migration
        function loadZipMigrationStats() {
            ajaxPost({
                action: 'blitzcdn_get_zip_migration_stats'
            }, function (response) {
                if (response.success) {
                    $('#blitzcdn-zip-total-attachments').text(response.data.total_attachments);
                    $('#blitzcdn-zip-total-assets').text(response.data.total_assets);
                    zipTotalAssets = parseInt(response.data.total_assets, 10) || null;

                    // If we haven't finalized the migration UI, reset stat cards to the initial state.
                    // If finalized, preserve the final values so subsequent fetches don't clear the completed UI.
                    if (!zipFinalized) {
                        $('#blitzcdn-zip-uploaded-count').text('-');
                        $('#blitzcdn-zip-processed-count').text('-');
                        $('#blitzcdn-zip-failed-count').text('-');
                        $('#blitzcdn-zip-progress-bar').css('width', '0%');
                        $('#blitzcdn-zip-progress-text').text('-');
                    } else {
                        // If finalized and we have a reliable total, ensure the Uploaded card shows it
                        if (zipTotalAssets && zipTotalAssets > 0) {
                            $('#blitzcdn-zip-uploaded-count').text(zipTotalAssets);
                        }
                    }
                }
            });
        }

        // Load zip migration stats on page load if the section exists
        if ($('#blitzcdn-zip-stats').length) {
            loadZipMigrationStats();
            // Also check current status
            checkZipMigrationStatus();
        }

        // Start zip migration with retry logic
        $(document).on('click', '#blitzcdn-zip-migrate-btn', function (e) {
            e.preventDefault();

            var $btn = $(this);
            if ($btn.prop('disabled')) return;

            if (!confirm('Are you sure you want to start the Fast Migration?\n\nThis will package all unmigrated media files into zip batches and upload them to the middleware queue in rapid succession.\n\nYou can close this tab once all batches are uploaded.')) {
                return;
            }

            $btn.prop('disabled', true).text('Starting...');
            $('#blitzcdn-zip-migration-log').show().empty();
            zipSafeToQuitAlertShown = false;
            zipMigrationInProgress = true;
            zipCurrentBatchConfirmed = false;
            zipUploadedFiles = 0; // reset accumulator when starting
            zipFinalized = false; // allow UI updates while a migration runs
            zipLog('Starting migration...', 'info');

            startZipMigrationWithRetry(0);
        });

        // Start migration with retry support
        function startZipMigrationWithRetry(retryAttempt) {
            var maxRetries = 3;
            
            if (retryAttempt > 0) {
                zipLog('Retry attempt ' + retryAttempt + '/' + maxRetries + '...', 'warning');
            }

            ajaxPost({
                action: 'blitzcdn_start_zip_migration'
                // Uses zip_batch_size from WordPress settings
            }, function (response) {
                if (response.success) {
                    handleZipBatchResponse(response.data, true);
                } else {
                    var errorMsg = response.data || 'Unknown error';
                    
                    // Check if error is retryable
                    var isRetryable = errorMsg.indexOf('HTTP 413') === -1 && 
                                     errorMsg.indexOf('Payload Too Large') === -1 &&
                                     errorMsg.indexOf('too large') === -1;
                    
                    if (isRetryable && retryAttempt < maxRetries) {
                        var waitSeconds = Math.pow(2, retryAttempt); // Exponential backoff
                        zipLog('Error: ' + errorMsg, 'error');
                        zipLog('Retrying in ' + waitSeconds + ' seconds...', 'warning');
                        setTimeout(function() {
                            startZipMigrationWithRetry(retryAttempt + 1);
                        }, waitSeconds * 1000);
                    } else {
                        if (!isRetryable) {
                            zipLog('Error: ' + errorMsg, 'error');
                            zipLog('Cannot retry - zip file may be too large. Check middleware MAX_ZIP_SIZE_MB setting.', 'error');
                        } else {
                            zipLog('Error after ' + maxRetries + ' attempts: ' + errorMsg, 'error');
                        }
                        zipMigrationInProgress = false;
                        $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
                    }
                }
            }, function (xhr, status, error) {
                var networkError = error || 'Network error';
                
                if (retryAttempt < maxRetries) {
                    var waitSeconds = Math.pow(2, retryAttempt); // Exponential backoff
                    zipLog('Network error: ' + networkError, 'error');
                    zipLog('Retrying in ' + waitSeconds + ' seconds...', 'warning');
                    setTimeout(function() {
                        startZipMigrationWithRetry(retryAttempt + 1);
                    }, waitSeconds * 1000);
                } else {
                    zipLog('Network error after ' + maxRetries + ' attempts: ' + networkError, 'error');
                    zipMigrationInProgress = false;
                    $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
                }
            });
        }

        // Handle response from starting or continuing a zip batch
        function handleZipBatchResponse(data, isFirstBatch) {
            // Accumulate uploaded files count if middleware returned files_added for this zip
            if (data.files_added !== undefined) {
                zipUploadedFiles += parseInt(data.files_added, 10) || 0;
            }

            if (data.all_zips_uploaded) {
                zipLog('All attachments have been uploaded to middleware!', 'success');
                zipLog('Processing will continue in the background.', 'info');
                zipMigrationInProgress = false;
                $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');

                // Mark final state so subsequent polls don't clear the UI
                zipFinalized = true;

                // Use the total assets count (the denominator in "x / total processed") as the Uploaded value
                // This represents the total files queued for processing once all zips are uploaded
                $('#blitzcdn-zip-uploaded-count').text(zipTotalAssets && zipTotalAssets > 0 ? zipTotalAssets : '-');

                // If we have a total assets number, update progress immediately
                if (zipTotalAssets && zipTotalAssets > 0) {
                    var completed = parseInt($('#blitzcdn-zip-processed-count').text(), 10) || 0;
                    var failed = parseInt($('#blitzcdn-zip-failed-count').text(), 10) || 0;
                    var percent = Math.round(((completed + failed) / zipTotalAssets) * 100);
                    percent = Math.max(0, Math.min(100, percent));
                    $('#blitzcdn-zip-progress-bar').css('width', percent + '%');
                    $('#blitzcdn-zip-progress-text').text(percent + '% — ' + (completed + failed) + ' / ' + zipTotalAssets + ' processed');
                }

                loadZipMigrationStats();
                showAllZipsUploadedNotification();
                return;
            }

            var batchInfo = 'Uploaded zip batch ' + (data.current_zip_batch || 1);
            if (data.total_zip_batches) {
                batchInfo += ' of ' + data.total_zip_batches;
            }
            zipLog(batchInfo, 'info');

            // Start polling for status and update UI
            startZipStatusPolling();
            updateZipStatusUI(data);

            // Continue uploading next zip batch (non-blocking)
            if (data.has_more_batches || data.remaining_attachments > 0) {
                zipLog('Queuing next batch upload...', 'info');
                setTimeout(function () {
                    continueZipMigration(0);
                }, 500);
            } else {
                zipLog('All zip batches have been queued for upload!', 'success');
            }
        }

        // Cancel migration button
        $(document).on('click', '#blitzcdn-zip-cancel-btn', function (e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to cancel the migration?\n\nThis will stop any processing on the middleware. Files already uploaded will remain on Appwrite.')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Cancelling...');

            ajaxPost({
                action: 'blitzcdn_cancel_zip_migration'
            }, function (response) {
                if (response.success) {
                    zipLog('Migration cancelled.', 'warning');
                    zipMigrationInProgress = false;
                    stopZipStatusPolling();
                    $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
                    $btn.prop('disabled', false).text('🛑 Cancel Migration').hide();
                    $('#blitzcdn-zip-migration-status').hide();
                    loadZipMigrationStats();
                } else {
                    zipLog('Error cancelling: ' + response.data, 'error');
                    $btn.prop('disabled', false).text('🛑 Cancel Migration');
                }
            }, function (xhr, status, error) {
                zipLog('Network error cancelling: ' + error, 'error');
                $btn.prop('disabled', false).text('🛑 Cancel Migration');
            });
        });

        // Continue to next zip batch with retry logic (non-blocking queue mode)
        function continueZipMigration(retryAttempt) {
            retryAttempt = retryAttempt || 0;
            var maxRetries = 3;
            
            if (retryAttempt === 0) {
                zipLog('Uploading next zip batch...', 'info');
            } else {
                zipLog('Retry attempt ' + retryAttempt + '/' + maxRetries + '...', 'warning');
            }
            
            $('#blitzcdn-zip-migrate-btn').text('Uploading batches...');

            ajaxPost({
                action: 'blitzcdn_continue_zip_migration'
            }, function (response) {
                if (response.success) {
                    handleZipBatchResponse(response.data, false);
                } else {
                    var errorMsg = response.data || 'Unknown error';
                    
                    // Check if error is retryable
                    var isRetryable = errorMsg.indexOf('HTTP 413') === -1 && 
                                     errorMsg.indexOf('Payload Too Large') === -1 &&
                                     errorMsg.indexOf('too large') === -1;
                    
                    if (isRetryable && retryAttempt < maxRetries) {
                        var waitSeconds = Math.pow(2, retryAttempt); // Exponential backoff: 1s, 2s, 4s
                        zipLog('Error continuing migration: ' + errorMsg, 'error');
                        zipLog('Retrying in ' + waitSeconds + ' seconds...', 'warning');
                        setTimeout(function() {
                            continueZipMigration(retryAttempt + 1);
                        }, waitSeconds * 1000);
                    } else {
                        if (!isRetryable) {
                            zipLog('Error continuing migration: ' + errorMsg, 'error');
                            zipLog('Cannot retry - zip file may be too large. Check middleware MAX_ZIP_SIZE_MB setting.', 'error');
                        } else {
                            zipLog('Error continuing migration after ' + maxRetries + ' attempts: ' + errorMsg, 'error');
                        }
                        zipMigrationInProgress = false;
                        $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
                    }
                }
            }, function (xhr, status, error) {
                var networkError = error || 'Network error';
                
                if (retryAttempt < maxRetries) {
                    var waitSeconds = Math.pow(2, retryAttempt); // Exponential backoff
                    zipLog('Network error: ' + networkError, 'error');
                    zipLog('Retrying in ' + waitSeconds + ' seconds...', 'warning');
                    setTimeout(function() {
                        continueZipMigration(retryAttempt + 1);
                    }, waitSeconds * 1000);
                } else {
                    zipLog('Network error after ' + maxRetries + ' attempts: ' + networkError, 'error');
                    zipMigrationInProgress = false;
                    $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
                }
            });
        }

        // Check status button
        $(document).on('click', '#blitzcdn-zip-check-status-btn', function (e) {
            e.preventDefault();
            checkZipMigrationStatus();
        });

        // Reset migration button
        $(document).on('click', '#blitzcdn-zip-reset-btn', function (e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to reset the migration status?\n\nThis will clear the current status and allow you to start a new migration.')) {
                return;
            }

            ajaxPost({
                action: 'blitzcdn_reset_zip_migration'
            }, function (response) {
                if (response.success) {
                    zipLog('Migration status reset.', 'success');
                    stopZipStatusPolling();
                    zipSafeToQuitAlertShown = false;
                    zipUploadedFiles = 0; // reset client-side accumulator
                    zipFinalized = false; // allow UI to be reset after reset
                    $('#blitzcdn-zip-migration-status').hide();
                    $('#blitzcdn-zip-reset-btn').hide();
                    $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
                    loadZipMigrationStats();
                } else {
                    zipLog('Error resetting: ' + response.data, 'error');
                }
            });
        });

        function checkZipMigrationStatus() {
            ajaxPost({
                action: 'blitzcdn_get_zip_migration_status'
            }, function (response) {
                if (response.success) {
                    updateZipStatusUI(response.data);

                    // Reload stats to update unmigrated counts
                    loadZipMigrationStats();

                    // Decide whether to poll: keep polling when there is an active migration
                    var statusVal = response.data && response.data.status;
                    var hasMigrationFields = response.data && (
                        response.data.migration_id || response.data.attachments_zipped || response.data.total_attachments_to_migrate || response.data.total_files_uploaded
                    );

                    var shouldPoll = false;
                    if (statusVal && statusVal !== 'idle' && statusVal !== 'completed' && statusVal !== 'completed_with_errors' && statusVal !== 'failed') {
                        shouldPoll = true;
                    }

                    // Also poll if we have migration metadata even if status is 'idle' (prevents flicker)
                    if (!shouldPoll && hasMigrationFields) {
                        shouldPoll = true;
                    }

                    if (shouldPoll) {
                        startZipStatusPolling();
                    } else {
                        stopZipStatusPolling();
                    }
                }
            });
        }

        // Show notification when all zips are uploaded
        function showAllZipsUploadedNotification() {
            if (zipSafeToQuitAlertShown) return;
            
            zipSafeToQuitAlertShown = true;
            zipLog('All zip batches uploaded! Processing continues in the background.', 'success');
            $('#blitzcdn-zip-safe-modal').show();
            
            zipMigrationInProgress = false;
            $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
            loadZipMigrationStats();
        }

        // Check for middleware confirmations (for status display only, not blocking)
        function maybeNotifySafeToQuit(data) {
            if (!data) return;

            // Log middleware confirmation when received (informational only, once)
            if (data.safe_to_quit && !zipCurrentBatchConfirmed) {
                zipCurrentBatchConfirmed = true;
                zipLog('Middleware confirmed processing for batch.', 'info');
            }
            
            // If processing is finished, show modal if not already shown
            if (data.all_zips_uploaded && (data.status === 'completed' || data.status === 'completed_with_errors' || data.safe_to_quit)) {
                if (!zipSafeToQuitAlertShown) {
                    showAllZipsUploadedNotification();
                }
            }
        }

        // Modal button handlers
        $(document).on('click', '#blitzcdn-zip-modal-stay', function (e) {
            e.preventDefault();
            $('#blitzcdn-zip-safe-modal').hide();
        });

        $(document).on('click', '#blitzcdn-zip-modal-close', function (e) {
            e.preventDefault();
            $('#blitzcdn-zip-safe-modal').hide();
            // Try to close the window programmatically; if blocked, just inform the user
            try {
                window.close();
                setTimeout(function () {
                    if (!window.closed) {
                        alert('You can safely close this page now.');
                    }
                }, 200);
            } catch (err) {
                alert('You can safely close this page now.');
            }
        });

        function updateZipStatusUI(data) {
            // Consider the state "empty" only when there's no migration information at all.
            var hasMigrationId = data && data.migration_id;
            var hasAnyTotals = data && (
                (data.total_attachments_to_migrate && data.total_attachments_to_migrate > 0) ||
                (data.attachments_zipped && data.attachments_zipped > 0) ||
                (data.total_files_uploaded && data.total_files_uploaded > 0)
            );

            var isTrulyEmpty = !data || (data.status === 'idle' && !hasMigrationId && !hasAnyTotals);
            if (isTrulyEmpty) {
                if (!zipFinalized) {
                    // No active migration data to display — hide the panel
                    $('#blitzcdn-zip-migration-status').hide();
                    $('#blitzcdn-zip-reset-btn').hide();
                    return;
                } else {
                    // If we've finalized, keep the panel visible and show a completed state — do not return
                    $('#blitzcdn-zip-migration-status').show();
                    $('#blitzcdn-zip-reset-btn').show();
                    $('#blitzcdn-zip-progress-bar').css('width','100%').css('background','linear-gradient(90deg, #28a745, #4ec9b0)');
                    $('#blitzcdn-zip-progress-text').html('✅ Completed');
                }
            }

            // Show status panel if we have any migration info (even if status is briefly 'idle')
            $('#blitzcdn-zip-migration-status').show();
            $('#blitzcdn-zip-reset-btn').show();

            // Simple friendly status mapping
            var statusText = data.status || '-';
            var statusColor = '#666';
            switch (data.status) {
                case 'zip_created':
                    statusColor = '#2271b1';
                    statusText = '📦 Zip Created';
                    break;
                case 'uploading':
                    statusColor = '#dba617';
                    statusText = '📤 Uploading to Middleware';
                    break;
                case 'awaiting_confirmation':
                    statusColor = '#17a2b8';
                    statusText = '📤 Queued';
                    break;
                case 'processing':
                case 'processing_remote':
                    statusColor = '#17a2b8';
                    statusText = '🔄 Processing on middleware';
                    break;
                case 'webhook_received':
                    statusColor = '#4ec9b0';
                    statusText = '📥 Results Received';
                    break;
                case 'all_zips_uploaded':
                    statusColor = '#28a745';
                    statusText = '✅ All batches uploaded';
                    zipMigrationInProgress = false;
                    // Ensure the UI shows the uploaded/completed state and preserves it
                    $('#blitzcdn-zip-progress-bar').css('background','linear-gradient(90deg, #28a745, #4ec9b0)');
                    $('#blitzcdn-zip-progress-text').html('✅ All batches uploaded');
                    $('#blitzcdn-zip-cancel-btn').hide();
                    break;
                case 'completed':
                    statusColor = '#28a745';
                    statusText = '✅ Completed';
                    stopZipStatusPolling();
                    zipMigrationInProgress = false;
                    zipFinalized = true; // freeze the UI on success
                    $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
                    $('#blitzcdn-zip-cancel-btn').hide();
                    // Force a stable completed UI state
                    $('#blitzcdn-zip-progress-bar').css('width','100%').css('background','linear-gradient(90deg, #28a745, #4ec9b0)');
                    $('#blitzcdn-zip-progress-text').html('✅ Completed');
                    loadZipMigrationStats();
                    break;
                case 'completed_with_errors':
                    statusColor = '#ffc107';
                    statusText = '⚠️ Completed with errors';
                    stopZipStatusPolling();
                    zipMigrationInProgress = false;
                    zipFinalized = true; // freeze the UI on final-with-errors
                    $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
                    $('#blitzcdn-zip-cancel-btn').hide();
                    // Force a stable completed-with-errors UI state
                    $('#blitzcdn-zip-progress-bar').css('width','100%').css('background','linear-gradient(90deg, #ffc107, #ffdf7e)');
                    $('#blitzcdn-zip-progress-text').html('⚠️ Completed with errors');
                    loadZipMigrationStats();
                    break;
                case 'cancelled':
                    statusColor = '#6c757d';
                    statusText = '🛑 Cancelled';
                    stopZipStatusPolling();
                    zipMigrationInProgress = false;
                    $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
                    $('#blitzcdn-zip-cancel-btn').hide();
                    loadZipMigrationStats();
                    break;
                case 'failed':
                case 'upload_failed':
                case 'webhook_error':
                    statusColor = '#dc3545';
                    statusText = '❌ Error: ' + (data.error || data.message || data.status);
                    stopZipStatusPolling();
                    zipMigrationInProgress = false;
                    $('#blitzcdn-zip-migrate-btn').prop('disabled', false).text('🚀 Start Fast Migration');
                    $('#blitzcdn-zip-cancel-btn').hide();
                    break;
            }

            maybeNotifySafeToQuit(data);

            // Keep status concise (avoid per-batch confusing logs)
            $('#blitzcdn-zip-status-text').html('<span style="color: ' + statusColor + ';">' + statusText + '</span>');
            $('#blitzcdn-zip-migration-id').text(data.migration_id || '-');

            // Compute totals for the centralized progress
            var totalAssets = zipTotalAssets || (parseInt($('#blitzcdn-zip-total-assets').text(), 10) || 0);
            var uploaded = data.total_files_uploaded !== undefined ? parseInt(data.total_files_uploaded, 10) : (data.files_added !== undefined ? parseInt(data.files_added, 10) : 0);
            var processed = data.processed !== undefined ? parseInt(data.processed, 10) : 0;
            var failed = data.failed !== undefined ? parseInt(data.failed, 10) : 0;

            // Always use totalAssets for the Uploaded card (this is the denominator used in the progress bar)
            if (totalAssets && totalAssets > 0) {
                uploaded = totalAssets;
            }

            // Update small stat cards
            $('#blitzcdn-zip-uploaded-count').text(typeof uploaded === 'number' && uploaded >= 0 ? uploaded : '-');
            $('#blitzcdn-zip-processed-count').text(typeof processed === 'number' && processed >= 0 ? processed : '-');
            $('#blitzcdn-zip-failed-count').text(typeof failed === 'number' && failed >= 0 ? failed : '-');

            // Progress is based on processed+failed out of total assets (represents completed work)
            // If we've finalized the migration UI, don't overwrite the final display
            if (!zipFinalized) {
                var completed = processed + failed;
                var percent = 0;
                if (totalAssets && totalAssets > 0) {
                    percent = Math.round((completed / totalAssets) * 100);
                } else if (uploaded && data.total_files_uploaded !== undefined) {
                    // fallback: show uploaded proportion if totalAssets unknown
                    percent = Math.round((uploaded / Math.max(1, uploaded)) * 100);
                }

                percent = Math.max(0, Math.min(100, percent));
                $('#blitzcdn-zip-progress-bar').css('width', percent + '%');

                // If migration is complete (or the percent reached 100%), show a distinct completed state
                if ((data && (data.status === 'completed' || data.status === 'completed_with_errors')) || percent === 100) {
                    var completeText = (data && data.status === 'completed_with_errors') ? '⚠️ Completed with errors' : '✅ Completed';
                    $('#blitzcdn-zip-progress-bar').css('background', 'linear-gradient(90deg, #28a745, #4ec9b0)');
                    $('#blitzcdn-zip-progress-text').html(completeText + ' — ' + completed + ' / ' + (totalAssets || '-') + ' processed');
                } else {
                    // Default non-complete display
                    $('#blitzcdn-zip-progress-bar').css('background', 'linear-gradient(90deg, #2271b1, #4ec9b0)');
                    $('#blitzcdn-zip-progress-text').text(percent + '% — ' + completed + ' / ' + (totalAssets || '-') + ' processed');
                }
            } // if not zipFinalized (skip updates when finalized)

            // Show/hide cancel button based on migration status
            var cancellableStatuses = ['uploading', 'awaiting_confirmation', 'processing', 'processing_remote', 'zip_created', 'all_zips_uploaded'];
            if (cancellableStatuses.indexOf(data.status) !== -1) {
                $('#blitzcdn-zip-cancel-btn').show();
            } else {
                $('#blitzcdn-zip-cancel-btn').hide();
            }

            // Log any file failures (only once)
            if (data.files_failed && data.files_failed.length > 0 && !data._files_failed_logged) {
                data._files_failed_logged = true;
                zipLog('Some files could not be added to zip:', 'warning');
                data.files_failed.forEach(function (f) {
                    zipLog('  - Attachment #' + f.attachment_id + ': ' + f.file + ' (' + f.reason + ')', 'warning');
                });
            }
        }

        function startZipStatusPolling() {
            if (zipMigrationStatusPollInterval) return;

            zipMigrationStatusPollInterval = setInterval(function () {
                ajaxPost({
                    action: 'blitzcdn_get_zip_migration_status'
                }, function (response) {
                    if (response.success) {
                        updateZipStatusUI(response.data);
                    }
                });
            }, 3000); // Poll every 3 seconds
        }

        function stopZipStatusPolling() {
            if (zipMigrationStatusPollInterval) {
                clearInterval(zipMigrationStatusPollInterval);
                zipMigrationStatusPollInterval = null;
            }
        }

        function zipLog(message, type) {
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

            var $log = $('#blitzcdn-zip-migration-log');
            $log.append(logHtml);

            // On warnings or errors, make the log visible so admins can inspect issues
            if (type === 'error' || type === 'warning') {
                $log.show();
            }

            // Auto-scroll to bottom
            var logContainer = $log[0];
            if (logContainer) {
                logContainer.scrollTop = logContainer.scrollHeight;
            }
        }
    });
}
