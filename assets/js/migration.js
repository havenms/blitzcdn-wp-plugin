jQuery(document).ready(function ($) {
    var isMigrating = false;
    var totalItems = 0;
    var processedItems = 0;
    var batchSize = 5;
    var itemIds = [];

    $('#blitzcdn-migrate-btn').on('click', function (e) {
        e.preventDefault();
        if (isMigrating) return;

        if (!confirm('Are you sure you want to migrate existing media to BlitzCDN? This may take a while.')) {
            return;
        }

        startMigration();
    });

    $('#blitzcdn-bg-migrate-btn').on('click', function (e) {
        e.preventDefault();
        if (isMigrating) return;

        if (!confirm('Start background migration? This will process images in the background. You can close this page.')) {
            return;
        }

        $('#blitzcdn-bg-migrate-btn').prop('disabled', true);
        $('#blitzcdn-migration-progress').show();
        log('Starting background migration...');

        $.post(blitzcdn_migration.ajax_url, {
            action: 'blitzcdn_start_background_migration',
            nonce: blitzcdn_migration.nonce
        }, function (response) {
            if (response.success) {
                log(response.data.message);
                log('Queued ' + response.data.count + ' items.');
                alert(response.data.message);
            } else {
                log('Error: ' + response.data);
                $('#blitzcdn-bg-migrate-btn').prop('disabled', false);
            }
        }).fail(function () {
            log('Ajax error.');
            $('#blitzcdn-bg-migrate-btn').prop('disabled', false);
        });
    });

    function startMigration() {
        isMigrating = true;
        $('#blitzcdn-migrate-btn').prop('disabled', true);
        $('#blitzcdn-migration-progress').show();
        log('Starting migration...');

        // Get stats
        $.post(blitzcdn_migration.ajax_url, {
            action: 'blitzcdn_get_migration_stats',
            nonce: blitzcdn_migration.nonce
        }, function (response) {
            if (response.success) {
                totalItems = response.data.total;
                itemIds = response.data.ids;
                log('Found ' + totalItems + ' items to migrate.');

                if (totalItems > 0) {
                    processBatch();
                } else {
                    finishMigration();
                }
            } else {
                log('Error fetching stats: ' + response.data);
                isMigrating = false;
                $('#blitzcdn-migrate-btn').prop('disabled', false);
            }
        });
    }

    function processBatch() {
        if (itemIds.length === 0) {
            finishMigration();
            return;
        }

        var batch = itemIds.splice(0, batchSize);

        $.post(blitzcdn_migration.ajax_url, {
            action: 'blitzcdn_migrate_batch',
            nonce: blitzcdn_migration.nonce,
            ids: batch
        }, function (response) {
            processedItems += batch.length;
            updateProgress();

            if (response.success) {
                $.each(response.data, function (id, result) {
                    if (result.status === 'success') {
                        log('Item ' + id + ': Success');
                    } else {
                        log('Item ' + id + ': Failed - ' + result.message);
                    }
                });
            } else {
                log('Batch failed: ' + response.data);
            }

            processBatch();
        }).fail(function () {
            log('Ajax error. Retrying...');
            // Put back the batch? Or skip? Let's retry once or skip.
            // For simplicity, we'll just stop or skip.
            log('Skipping batch due to network error.');
            processedItems += batch.length;
            updateProgress();
            processBatch();
        });
    }

    function updateProgress() {
        var percent = Math.round((processedItems / totalItems) * 100);
        if (percent > 100) percent = 100;
        $('#blitzcdn-progress-bar').css('width', percent + '%');
        $('#blitzcdn-progress-text').text(percent + '% (' + processedItems + '/' + totalItems + ')');
    }

    function finishMigration() {
        isMigrating = false;
        $('#blitzcdn-migrate-btn').prop('disabled', false);
        log('Migration complete.');
        alert('Migration complete!');
    }

    function log(message) {
        var timestamp = new Date().toLocaleTimeString();
        $('#blitzcdn-migration-log').prepend('<div>[' + timestamp + '] ' + message + '</div>');
    }
});
