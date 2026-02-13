/* eslint-disable no-console */
console.debug("BlitzCDN migration script loaded");

if (typeof jQuery === "undefined") {
  console.error(
    "BlitzCDN: jQuery is required for the migration script but not found.",
  );
} else {
  jQuery(document).ready(function ($) {
    // Fallbacks if localization failed
    var _ajax_url =
      typeof blitzcdn_migration !== "undefined" && blitzcdn_migration.ajax_url
        ? blitzcdn_migration.ajax_url
        : null;
    var _nonce =
      typeof blitzcdn_migration !== "undefined" && blitzcdn_migration.nonce
        ? blitzcdn_migration.nonce
        : null;
    // Try to read from button data attributes as a fallback
    if (!_ajax_url) {
      var elButton =
        document.querySelector("#blitzcdn-redownload-btn") ||
        document.querySelector("#blitzcdn-migrate-btn") ||
        document.querySelector("#blitzcdn-background-migrate-btn");
      if (elButton && elButton.dataset && elButton.dataset.ajaxUrl) {
        _ajax_url = elButton.dataset.ajaxUrl;
      }
    }
    if (!_nonce) {
      var elNonceBtn =
        document.querySelector("#blitzcdn-redownload-btn") ||
        document.querySelector("#blitzcdn-migrate-btn");
      if (elNonceBtn && elNonceBtn.dataset && elNonceBtn.dataset.nonce) {
        _nonce = elNonceBtn.dataset.nonce;
      }
    }

    // Helper for making AJAX posts with fallback
    function ajaxPost(payload, successCallback, errorCallback) {
      if (!_ajax_url || !_nonce) {
        console.error(
          "BlitzCDN: Missing ajax_url or nonce; cannot make AJAX call.",
        );
        if (typeof errorCallback === "function")
          errorCallback({ error: "Missing ajax_url or nonce" });
        return;
      }

      payload.nonce = payload.nonce || _nonce;

      $.post(_ajax_url, payload, function (response) {
        console.debug(
          "BlitzCDN AJAX success for action=" + payload.action,
          response,
        );
        if (typeof successCallback === "function") successCallback(response);
      }).fail(function (xhr, status, err) {
        console.error(
          "BlitzCDN AJAX error for action=" + payload.action + ":",
          status,
          err,
          xhr,
        );
        if (typeof errorCallback === "function")
          errorCallback(xhr, status, err);
      });
    }
    // MIGRATION (Upload to CDN) Logic
    var isMigrating = false;
    var totalItems = 0; // Total images
    var totalAssets = 0; // Total assets (images * ~4)
    var processedItems = 0; // Processed images
    var processedAssets = 0; // Processed assets
    var batchSize =
      typeof blitzcdn_migration !== "undefined" &&
      blitzcdn_migration.migration_batch_size
        ? parseInt(blitzcdn_migration.migration_batch_size, 10)
        : 20;
    var itemIds = [];
    var migrationFinalized = false; // When true, keep migration UI in final state and ignore transient updates

    $(document).on("click", "#blitzcdn-migrate-btn", function (e) {
      e.preventDefault();
      if (isMigrating) return;

      if (
        !confirm(
          "Are you sure you want to migrate existing media to BlitzCDN? This may take a while.",
        )
      ) {
        return;
      }

      startMigration();
    });

    function startMigration() {
      isMigrating = true;
      migrationFinalized = false; // starting a fresh run
      $("#blitzcdn-migrate-btn").prop("disabled", true);
      $("#blitzcdn-migration-progress").show();
      $("#blitzcdn-migration-log").empty();
      log("Starting migration...", "info");

      // Get stats
      ajaxPost(
        {
          action: "blitzcdn_get_migration_stats",
        },
        function (response) {
          if (response.success) {
            totalAssets = response.data.total; // Total assets (original + sizes)
            totalItems = response.data.total_images || response.data.ids.length; // Total images for reference
            itemIds = response.data.ids.slice(); // Clone array
            processedAssets = 0;
            processedItems = 0;
            log(
              "Found " +
                totalItems +
                " images (" +
                totalAssets +
                " assets) to migrate.",
              "info",
            );

            if (totalAssets > 0) {
              processBatch();
            } else {
              finishMigration();
            }
          } else {
            log(
              "Error fetching stats: " + (response.data || "Unknown error"),
              "error",
            );
            isMigrating = false;
            $("#blitzcdn-migrate-btn").prop("disabled", false);
          }
        },
        function (xhr, status, error) {
          log("Network error fetching stats: " + error, "error");
          isMigrating = false;
          $("#blitzcdn-migrate-btn").prop("disabled", false);
        },
      );
    }

    function processBatch() {
      if (itemIds.length === 0) {
        finishMigration();
        return;
      }

      var batch = itemIds.splice(0, batchSize);
      log("Processing batch of " + batch.length + " items...", "info");

      ajaxPost(
        {
          action: "blitzcdn_migrate_batch",
          ids: batch,
        },
        function (response) {
          processedItems += batch.length;

          // Count assets processed in this batch
          if (response.success) {
            $.each(response.data, function (id, result) {
              var attachmentLink =
                '<a href="' +
                window.location.origin +
                "/wp-admin/post.php?post=" +
                id +
                '&action=edit" target="_blank">#' +
                id +
                "</a>";
              if (result.status === "success") {
                // Add assets count for this image (default to 4 if not provided)
                var assetsInImage = result.assets_count || 4;
                processedAssets += assetsInImage;
                log(
                  attachmentLink + ": Success (" + assetsInImage + " assets)",
                  "success",
                );
              } else {
                // On error, still count as 1 asset to avoid progress issues
                processedAssets += 1;
                log(attachmentLink + ": Failed - " + result.message, "error");
              }
            });
          } else {
            // On batch failure, count each image as 4 assets (typical)
            processedAssets += batch.length * 4;
            log("Batch failed: " + (response.data || "Unknown error"), "error");
          }

          updateProgress();

          // Continue to next batch (with small delay to prevent overwhelming server and allow UI updates)
          setTimeout(function () {
            processBatch();
          }, 100);
        },
      ).fail(function (xhr, status, error) {
        log("Network error processing batch: " + error, "error");
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
      var percent =
        totalAssets > 0 ? Math.round((processedAssets / totalAssets) * 100) : 0;
      if (percent > 100) percent = 100;
      $("#blitzcdn-progress-bar").css("width", percent + "%");

      // If complete, show a friendly completed state instead of a plain percentage
      if (percent === 100 && totalAssets > 0) {
        $("#blitzcdn-progress-bar").css(
          "background",
          "linear-gradient(90deg, #28a745, #4ec9b0)",
        );
        $("#blitzcdn-progress-text").html(
          "✅ <strong>Completed</strong> — " +
            processedAssets +
            "/" +
            totalAssets +
            " assets processed (" +
            processedItems +
            "/" +
            totalItems +
            " images)",
        );
      } else {
        // Reset to default color for non-complete states
        $("#blitzcdn-progress-bar").css(
          "background",
          "linear-gradient(90deg, #2271b1, #3794ff)",
        );
        $("#blitzcdn-progress-text").text(
          percent +
            "% (" +
            processedAssets +
            "/" +
            totalAssets +
            " assets processed, " +
            processedItems +
            "/" +
            totalItems +
            " images)",
        );
      }
    }

    function finishMigration() {
      isMigrating = false;
      $("#blitzcdn-migrate-btn").prop("disabled", false);

      // Final summary
      log("", "info"); // Empty line
      log("----------------------------------------", "info");
      log("MIGRATION COMPLETE", "info");
      log("----------------------------------------", "info");
      log(
        "Processed: " +
          processedAssets +
          " / " +
          totalAssets +
          " assets (" +
          processedItems +
          " / " +
          totalItems +
          " images)",
        "success",
      );

      // Force progress to completed state in UI
      migrationFinalized = true; // freeze migration UI from further updates
      $("#blitzcdn-progress-bar")
        .css("width", "100%")
        .css("background", "linear-gradient(90deg, #28a745, #4ec9b0)");
      $("#blitzcdn-progress-text").html(
        "✅ <strong>Completed</strong> — " +
          processedAssets +
          "/" +
          totalAssets +
          " assets processed (" +
          processedItems +
          "/" +
          totalItems +
          " images)",
      );

      // Show completion alert
      alert(
        "Migration complete! Processed " +
          processedAssets +
          " / " +
          totalAssets +
          " assets (" +
          processedItems +
          " / " +
          totalItems +
          " images).",
      );
    }

    function log(message, type) {
      var timestamp = new Date().toLocaleTimeString();
      var color = "#d4d4d4"; // Default gray

      switch (type) {
        case "success":
          color = "#4ec9b0";
          break;
        case "error":
          color = "#f14c4c";
          break;
        case "warning":
          color = "#cca700";
          break;
        case "info":
          color = "#3794ff";
          break;
      }

      var logHtml = '<div style="color: ' + color + '; margin-bottom: 4px;">';
      if (message) {
        logHtml +=
          '<span style="color: #6a9955;">[' + timestamp + "]</span> " + message;
      }
      logHtml += "</div>";

      $("#blitzcdn-migration-log").append(logHtml);

      // Auto-scroll to bottom
      var logContainer = document.getElementById("blitzcdn-migration-log");
      if (logContainer) {
        logContainer.scrollTop = logContainer.scrollHeight;
      }
    }

    // Background Migration Logic
    var bgPollInterval;

    function checkBackgroundStatus() {
      ajaxPost(
        {
          action: "blitzcdn_get_background_status",
        },
        function (response) {
          if (response.success) {
            var status = response.data;
            updateBackgroundUI(status);
          }
        },
      );
    }

    function updateBackgroundUI(status) {
      $("#blitzcdn-bg-status-text").text(status.status);

      if (status.status === "running") {
        $("#blitzcdn-background-status").show();
        $("#blitzcdn-background-migrate-btn").hide();
        $("#blitzcdn-stop-background-migrate-btn").show();
        // Show assets count (processed/total assets)
        var processedText = status.processed || 0;
        var totalText = status.total || 0;
        if (
          status.processed_images !== undefined &&
          status.total_images !== undefined
        ) {
          processedText += " assets (" + status.processed_images + " images)";
          totalText += " assets (" + status.total_images + " images)";
        } else {
          processedText += " assets";
          totalText += " assets";
        }
        $("#blitzcdn-bg-processed").text(processedText);
        $("#blitzcdn-bg-total").text(totalText);

        if (!bgPollInterval) {
          bgPollInterval = setInterval(checkBackgroundStatus, 1000); // Poll every 1 second for better reactivity
        }
      } else {
        $("#blitzcdn-background-status").hide(); // Hide when not running
        $("#blitzcdn-background-migrate-btn").show();
        $("#blitzcdn-stop-background-migrate-btn").hide();
        if (status.completed_time) {
          $("#blitzcdn-bg-status-text").text(
            "Completed at " +
              new Date(status.completed_time * 1000).toLocaleTimeString(),
          );
          var processedText = status.processed || 0;
          var totalText = status.total || 0;
          if (
            status.processed_images !== undefined &&
            status.total_images !== undefined
          ) {
            processedText += " assets (" + status.processed_images + " images)";
            totalText += " assets (" + status.total_images + " images)";
          } else {
            processedText += " assets";
            totalText += " assets";
          }
          $("#blitzcdn-bg-processed").text(processedText);
          $("#blitzcdn-bg-total").text(totalText);
        }

        if (bgPollInterval) {
          clearInterval(bgPollInterval);
          bgPollInterval = null;
        }
      }
    }

    $(document).on("click", "#blitzcdn-background-migrate-btn", function (e) {
      e.preventDefault();
      if (!confirm("Start background migration? This will run on the server."))
        return;

      ajaxPost(
        {
          action: "blitzcdn_start_background_migration",
        },
        function (response) {
          if (response.success) {
            checkBackgroundStatus();
          } else {
            alert("Failed to start: " + response.data);
          }
        },
      );
    });

    $(document).on(
      "click",
      "#blitzcdn-stop-background-migrate-btn",
      function (e) {
        e.preventDefault();
        ajaxPost(
          {
            action: "blitzcdn_stop_background_migration",
          },
          function (response) {
            checkBackgroundStatus();
          },
        );
      },
    );

    // Initial check
    checkBackgroundStatus();

    // GOODBYE / REDOWNLOAD Logic

    var isRedownloading = false;
    var redownloadCancelled = false;
    var redownloadTotalItems = 0; // Total images
    var redownloadTotalAssets = 0; // Total assets (images * ~4)
    var redownloadProcessedItems = 0; // Processed images
    var redownloadProcessedAssets = 0; // Processed assets
    var redownloadBatchSize =
      typeof blitzcdn_migration !== "undefined" &&
      blitzcdn_migration.redownload_batch_size
        ? parseInt(blitzcdn_migration.redownload_batch_size, 10)
        : 5; // Smaller batch size for downloads (they're heavier)
    var redownloadItemIds = [];
    var redownloadStats = {
      success: 0,
      skipped: 0,
      errors: 0,
    };
    var redownloadFinalized = false; // When true, preserve redownload final UI state

    $(document).on("click", "#blitzcdn-redownload-btn", function (e) {
      console.debug("BlitzCDN: Redownload button clicked");
      e.preventDefault();
      if (isRedownloading) {
        console.warn(
          "BlitzCDN: Redownload already in progress, ignoring click",
        );
        return;
      }

      var deleteFromAppwrite = $("#blitzcdn-delete-after-redownload").is(
        ":checked",
      );
      var warningMessage =
        "Are you sure you want to start the Goodbye Procedure?\n\n";
      warningMessage += "This will:\n";
      warningMessage +=
        "• Download all media files from Appwrite back to WordPress\n";
      warningMessage += "• Clear CDN metadata so URLs point to local files\n";

      if (deleteFromAppwrite) {
        warningMessage +=
          "• DELETE files from Appwrite after successful download\n";
        warningMessage +=
          "\nWARNING: Files will be permanently deleted from Appwrite!";
      }

      warningMessage +=
        "\n\nThis process may take a while. Keep this tab open.";

      console.debug("BlitzCDN: Showing confirmation dialog");
      if (!confirm(warningMessage)) {
        console.debug("BlitzCDN: User cancelled the procedure");
        return;
      }

      console.debug("BlitzCDN: User confirmed, starting redownload procedure");
      startRedownload(deleteFromAppwrite);
    });

    $(document).on("click", "#blitzcdn-cancel-redownload-btn", function (e) {
      e.preventDefault();
      if (!isRedownloading) return;

      if (
        confirm(
          "Are you sure you want to cancel the Goodbye Procedure?\n\nFiles already processed will remain in their current state.",
        )
      ) {
        redownloadCancelled = true;
        redownloadLog(
          "Cancellation requested. Stopping after current batch...",
          "warning",
        );
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
      $("#blitzcdn-redownload-btn").prop("disabled", true).hide();
      $("#blitzcdn-cancel-redownload-btn").show();
      $("#blitzcdn-redownload-progress").show();
      $("#blitzcdn-delete-after-redownload").prop("disabled", true);

      // Reset stats display
      updateRedownloadStats();
      $("#blitzcdn-redownload-log").empty();

      redownloadLog("Starting Goodbye Procedure...", "info");
      if (deleteFromAppwrite) {
        redownloadLog(
          "Delete from Appwrite is ENABLED - files will be removed after download",
          "warning",
        );
      }

      // Get stats
      console.debug("BlitzCDN: Fetching redownload stats...");
      ajaxPost(
        {
          action: "blitzcdn_get_redownload_stats",
        },
        function (response) {
          console.debug("BlitzCDN: Redownload stats response:", response);
          if (response.success) {
            redownloadTotalAssets = response.data.total; // Total assets (original + sizes)
            redownloadTotalItems =
              response.data.total_images || response.data.ids.length; // Total images for reference
            redownloadItemIds = response.data.ids.slice(); // Clone array

            if (redownloadTotalAssets === 0) {
              redownloadLog(
                "No items found with BlitzCDN metadata. Nothing to redownload.",
                "success",
              );
              finishRedownload();
              return;
            }

            redownloadLog(
              "Found " +
                redownloadTotalItems +
                " images (" +
                redownloadTotalAssets +
                " assets) to process",
              "info",
            );
            processRedownloadBatch(deleteFromAppwrite);
          } else {
            console.error(
              "BlitzCDN: Failed to get redownload stats:",
              response,
            );
            redownloadLog(
              "Error fetching stats: " + (response.data || "Unknown error"),
              "error",
            );
            finishRedownload();
          }
        },
        function (xhr, status, error) {
          console.error("BlitzCDN: Network error fetching stats:", error);
          redownloadLog("Network error fetching stats: " + error, "error");
          finishRedownload();
        },
      );
    }

    function processRedownloadBatch(deleteFromAppwrite) {
      // Check for cancellation
      if (redownloadCancelled) {
        redownloadLog("Process cancelled by user", "warning");
        finishRedownload();
        return;
      }

      if (redownloadItemIds.length === 0) {
        finishRedownload();
        return;
      }

      var batch = redownloadItemIds.splice(0, redownloadBatchSize);
      redownloadLog(
        "Processing batch of " + batch.length + " items...",
        "info",
      );

      ajaxPost(
        {
          action: "blitzcdn_redownload_batch",
          ids: batch,
          delete_from_appwrite: deleteFromAppwrite ? "true" : "false",
        },
        function (response) {
          redownloadProcessedItems += batch.length;

          if (response.success) {
            $.each(response.data, function (id, result) {
              var attachmentLink =
                '<a href="' +
                window.location.origin +
                "/wp-admin/post.php?post=" +
                id +
                '&action=edit" target="_blank">#' +
                id +
                "</a>";

              // Count assets processed for this attachment
              var assetsInImage = 1; // Original
              if (result.details && result.details.sizes) {
                assetsInImage += Object.keys(result.details.sizes).length;
              }
              redownloadProcessedAssets += assetsInImage;

              // Check if original file already existed locally
              var originalAlreadyExists =
                result.details &&
                result.details.original &&
                result.details.original.status === "exists";

              if (result.status === "success") {
                if (originalAlreadyExists) {
                  // File was already local - count as skipped, not success
                  redownloadStats.skipped++;
                  redownloadLog(
                    attachmentLink +
                      ": Already exists locally (metadata cleared) - " +
                      assetsInImage +
                      " assets",
                    "info",
                  );
                } else {
                  redownloadStats.success++;
                  var msg =
                    attachmentLink +
                    ": " +
                    result.message +
                    " (" +
                    assetsInImage +
                    " assets)";
                  if (result.details && result.details.deleted_from_appwrite) {
                    msg +=
                      " (Deleted " +
                      result.details.deleted_count +
                      " from Appwrite)";
                  }
                  redownloadLog(msg, "success");
                }
              } else if (result.status === "partial") {
                redownloadStats.errors++;
                redownloadLog(
                  attachmentLink +
                    ": " +
                    result.message +
                    " (" +
                    assetsInImage +
                    " assets)",
                  "warning",
                );
                logDetailedResults(id, result.details);
              } else if (result.status === "error") {
                redownloadStats.errors++;
                redownloadLog(attachmentLink + ": " + result.message, "error");
              }
            });
          } else {
            redownloadLog(
              "Batch failed: " + (response.data || "Unknown error"),
              "error",
            );
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
        },
      ).fail(function (xhr, status, error) {
        redownloadLog("Network error processing batch: " + error, "error");
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
        var origMsg = details.original.message || "";
        if (origStatus === "error") {
          redownloadLog("   Original: " + origMsg, "error");
        } else if (origStatus === "exists") {
          redownloadLog("   Original: " + origMsg, "info");
        }
      }

      if (details.sizes && typeof details.sizes === "object") {
        $.each(details.sizes, function (sizeName, sizeResult) {
          if (sizeResult.status === "error") {
            redownloadLog(
              '   Size "' + sizeName + '": ' + (sizeResult.message || ""),
              "error",
            );
          } else if (sizeResult.status === "skipped") {
            redownloadLog(
              '   Size "' + sizeName + '": ' + (sizeResult.message || ""),
              "info",
            );
          }
        });
      }
    }

    function updateRedownloadProgress() {
      // If we've finalized the redownload UI, don't overwrite the completed display
      if (redownloadFinalized) return;

      // Use asset count for progress, not image count
      var percent =
        redownloadTotalAssets > 0
          ? Math.round(
              (redownloadProcessedAssets / redownloadTotalAssets) * 100,
            )
          : 0;
      if (percent > 100) percent = 100;

      $("#blitzcdn-redownload-progress-bar").css("width", percent + "%");

      if (percent === 100 && redownloadTotalAssets > 0) {
        $("#blitzcdn-redownload-progress-bar").css(
          "background",
          "linear-gradient(90deg, #28a745, #4ec9b0)",
        );
        $("#blitzcdn-redownload-progress-text").html(
          "✅ <strong>Completed</strong> — " +
            redownloadProcessedAssets +
            "/" +
            redownloadTotalAssets +
            " assets processed (" +
            redownloadProcessedItems +
            "/" +
            redownloadTotalItems +
            " images)",
        );
      } else {
        $("#blitzcdn-redownload-progress-bar").css(
          "background",
          "linear-gradient(90deg, #dc3545, #fd7e14)",
        );
        $("#blitzcdn-redownload-progress-text").text(
          percent +
            "% (" +
            redownloadProcessedAssets +
            "/" +
            redownloadTotalAssets +
            " assets processed, " +
            redownloadProcessedItems +
            "/" +
            redownloadTotalItems +
            " images)",
        );
      }
    }

    function updateRedownloadStats() {
      $("#blitzcdn-success-count").text(redownloadStats.success);
      $("#blitzcdn-skipped-count").text(redownloadStats.skipped);
      $("#blitzcdn-error-count").text(redownloadStats.errors);
    }

    function finishRedownload() {
      isRedownloading = false;

      // Update UI
      $("#blitzcdn-redownload-btn").prop("disabled", false).show();
      $("#blitzcdn-cancel-redownload-btn").hide();
      $("#blitzcdn-delete-after-redownload").prop("disabled", false);

      // Force progress to completed state in UI
      redownloadFinalized = true; // freeze redownload UI from further updates
      $("#blitzcdn-redownload-progress-bar")
        .css("width", "100%")
        .css("background", "linear-gradient(90deg, #28a745, #4ec9b0)");
      $("#blitzcdn-redownload-progress-text").html(
        "✅ <strong>Completed</strong> — " +
          redownloadProcessedAssets +
          "/" +
          redownloadTotalAssets +
          " assets processed (" +
          redownloadProcessedItems +
          "/" +
          redownloadTotalItems +
          " images)",
      );

      // Final summary
      redownloadLog("", "info"); // Empty line
      redownloadLog("----------------------------------------", "info");
      redownloadLog("GOODBYE PROCEDURE COMPLETE", "info");
      redownloadLog("----------------------------------------", "info");
      redownloadLog(
        "Processed: " +
          redownloadProcessedAssets +
          " / " +
          redownloadTotalAssets +
          " assets (" +
          redownloadProcessedItems +
          " / " +
          redownloadTotalItems +
          " images)",
        "success",
      );
      redownloadLog(
        "Downloaded: " + redownloadStats.success + " attachments",
        "success",
      );
      redownloadLog(
        "Already Local: " + redownloadStats.skipped + " attachments",
        "info",
      );
      redownloadLog(
        "Errors: " + redownloadStats.errors + " attachments",
        redownloadStats.errors > 0 ? "error" : "info",
      );

      if (redownloadStats.errors > 0) {
        redownloadLog("", "info");
        redownloadLog(
          "Some files had errors. Review the log above and try again for failed items.",
          "warning",
        );
      }

      if (redownloadCancelled) {
        redownloadLog("", "info");
        redownloadLog(
          "Process was cancelled. Some items may not have been processed.",
          "warning",
        );
      }

      // Show completion alert
      var alertMessage = "Goodbye Procedure Complete!\n\n";
      alertMessage += "Downloaded: " + redownloadStats.success + "\n";
      alertMessage += "Already Local: " + redownloadStats.skipped + "\n";
      alertMessage += "Errors: " + redownloadStats.errors;

      if (redownloadStats.errors > 0) {
        alertMessage += "\n\nSome files had errors. Check the log for details.";
      }

      alert(alertMessage);
    }

    function redownloadLog(message, type) {
      var timestamp = new Date().toLocaleTimeString();
      var color = "#d4d4d4"; // Default gray

      switch (type) {
        case "success":
          color = "#4ec9b0";
          break;
        case "error":
          color = "#f14c4c";
          break;
        case "warning":
          color = "#cca700";
          break;
        case "info":
          color = "#3794ff";
          break;
      }

      var logHtml = '<div style="color: ' + color + '; margin-bottom: 4px;">';
      if (message) {
        logHtml +=
          '<span style="color: #6a9955;">[' + timestamp + "]</span> " + message;
      }
      logHtml += "</div>";

      $("#blitzcdn-redownload-log").append(logHtml);

      // Auto-scroll to bottom
      var logContainer = document.getElementById("blitzcdn-redownload-log");
      if (logContainer) {
        logContainer.scrollTop = logContainer.scrollHeight;
      }
    }

    // ZIP-BASED (FAST) MIGRATION Logic
    var zipMigrationStatusPollInterval = null;
    var zipSafeToQuitAlertShown = false;
    var zipMigrationInProgress = false;
    var zipCurrentBatchConfirmed = false; // Track if current batch was confirmed by middleware
    var zipTotalAssets = null; // Locked total assets - set once from backend's total_assets_to_migrate
    var zipHasActiveStatus = false; // True once a migration is active (uploading or processing)
    var zipFinalized = false; // Guard: once true, keep final UI state and ignore transient polls
    // UI high-water marks - prevent flicker from stale AJAX responses
    var zipDisplayedUploaded = 0;
    var zipDisplayedProcessed = 0;
    var zipDisplayedFailed = 0;

    // Load initial stats for zip migration
    function loadZipMigrationStats() {
      ajaxPost(
        {
          action: "blitzcdn_get_zip_migration_stats",
        },
        function (response) {
          if (response.success) {
            $("#blitzcdn-zip-total-attachments").text(
              response.data.total_attachments,
            );
            $("#blitzcdn-zip-total-assets").text(response.data.total_assets);
            $("#blitzcdn-zip-total-assets-inline").text(
              response.data.total_assets,
            );

            // IMPORTANT: total assets is used as a denominator for progress.
            // Do not overwrite it during an active migration, otherwise the UI will fluctuate.
            var statsTotal = parseInt(response.data.total_assets, 10);
            if (!zipHasActiveStatus && !zipFinalized) {
              zipTotalAssets = isNaN(statsTotal) ? null : statsTotal;
            } else if (!zipTotalAssets || zipTotalAssets <= 0) {
              // If we haven't captured a stable total yet, take the first valid value we see.
              zipTotalAssets = isNaN(statsTotal) ? zipTotalAssets : statsTotal;
            }

            // Only reset stat cards when NOT in an active migration and not finalized.
            // During active migration, let updateZipStatusUI handle the card updates.
            if (
              !zipFinalized &&
              !zipHasActiveStatus &&
              !zipMigrationInProgress
            ) {
              $("#blitzcdn-zip-uploaded-count").text("-");
              $("#blitzcdn-zip-processed-count").text("-");
              $("#blitzcdn-zip-failed-count").text("-");
              $("#blitzcdn-zip-progress-bar").css("width", "0%");
              $("#blitzcdn-zip-progress-text").text("-");
            } else if (zipFinalized) {
              // If finalized and we have a reliable total, ensure the Uploaded card shows it
              if (zipTotalAssets && zipTotalAssets > 0) {
                $("#blitzcdn-zip-uploaded-count").text(zipTotalAssets);
              }
            }
            // During active migration: do NOT touch the stat cards, let status polling handle them

            // Ensure Start button state matches the latest attachment count
            updateZipStartButtonByCount();
          }
        },
      );
    }

    // Load zip migration stats on page load if the section exists
    if ($("#blitzcdn-zip-stats").length) {
      loadZipMigrationStats();
      // Also check current status
      checkZipMigrationStatus();
    }

    // Update start button enabled/disabled state based on the current attachment count
    function updateZipStartButtonByCount() {
      var attachments = parseInt(
        $("#blitzcdn-zip-total-attachments").text(),
        10,
      );
      if (isNaN(attachments) || attachments <= 0) {
        $("#blitzcdn-zip-migrate-btn")
          .prop("disabled", true)
          .text("🚫 No attachments to migrate")
          .attr("title", "No attachments to migrate");
      } else {
        $("#blitzcdn-zip-migrate-btn")
          .prop("disabled", false)
          .text("🚀 Start Fast Migration")
          .removeAttr("title");
      }
    }

    // Start zip migration with retry logic
    $(document).on("click", "#blitzcdn-zip-migrate-btn", function (e) {
      e.preventDefault();

      var $btn = $(this);
      if ($btn.prop("disabled")) return;

      if (
        !confirm(
          "Are you sure you want to start the Fast Migration?\n\nThis will package all unmigrated media files into zip batches and upload them to the middleware queue in rapid succession.\n\nYou can close this tab once all batches are uploaded.",
        )
      ) {
        return;
      }

      $btn.prop("disabled", true).text("Starting...");
      $("#blitzcdn-zip-migration-log").show().empty();
      zipSafeToQuitAlertShown = false;
      zipMigrationInProgress = true;
      zipHasActiveStatus = true;
      zipCurrentBatchConfirmed = false;
      // Reset UI high-water marks for fresh start
      zipDisplayedUploaded = 0;
      zipDisplayedProcessed = 0;
      zipDisplayedFailed = 0;
      // zipTotalAssets will be locked from first status response containing total_assets_to_migrate
      zipFinalized = false; // allow UI updates while a migration runs
      zipLog("Starting migration...", "info");

      startZipMigrationWithRetry(0);
    });

    // Start migration with retry support
    function startZipMigrationWithRetry(retryAttempt) {
      var maxRetries = 3;

      if (retryAttempt > 0) {
        zipLog(
          "Retry attempt " + retryAttempt + "/" + maxRetries + "...",
          "warning",
        );
      }

      ajaxPost(
        {
          action: "blitzcdn_start_zip_migration",
          // Uses zip_batch_size from WordPress settings
        },
        function (response) {
          if (response.success) {
            handleZipBatchResponse(response.data, true);
          } else {
            var errorMsg = response.data || "Unknown error";

            // Check if error is retryable
            var isRetryable =
              errorMsg.indexOf("HTTP 413") === -1 &&
              errorMsg.indexOf("Payload Too Large") === -1 &&
              errorMsg.indexOf("too large") === -1;

            if (isRetryable && retryAttempt < maxRetries) {
              var waitSeconds = Math.pow(2, retryAttempt); // Exponential backoff
              zipLog("Error: " + errorMsg, "error");
              zipLog("Retrying in " + waitSeconds + " seconds...", "warning");
              setTimeout(function () {
                startZipMigrationWithRetry(retryAttempt + 1);
              }, waitSeconds * 1000);
            } else {
              if (!isRetryable) {
                zipLog("Error: " + errorMsg, "error");
                zipLog(
                  "Cannot retry - zip file may be too large. Check middleware MAX_ZIP_SIZE_MB setting.",
                  "error",
                );
              } else {
                zipLog(
                  "Error after " + maxRetries + " attempts: " + errorMsg,
                  "error",
                );
              }
              zipMigrationInProgress = false;
              updateZipStartButtonByCount();
            }
          }
        },
        function (xhr, status, error) {
          var networkError = error || "Network error";

          if (retryAttempt < maxRetries) {
            var waitSeconds = Math.pow(2, retryAttempt); // Exponential backoff
            zipLog("Network error: " + networkError, "error");
            zipLog("Retrying in " + waitSeconds + " seconds...", "warning");
            setTimeout(function () {
              startZipMigrationWithRetry(retryAttempt + 1);
            }, waitSeconds * 1000);
          } else {
            zipLog(
              "Network error after " +
                maxRetries +
                " attempts: " +
                networkError,
              "error",
            );
            zipMigrationInProgress = false;
            updateZipStartButtonByCount();
          }
        },
      );
    }

    // Handle response from starting or continuing a zip batch
    function handleZipBatchResponse(data, isFirstBatch) {
      // Lock total assets from backend if not already locked
      if (
        data.total_assets_to_migrate &&
        (!zipTotalAssets || zipTotalAssets <= 0)
      ) {
        zipTotalAssets = parseInt(data.total_assets_to_migrate, 10);
      }

      if (data.all_zips_uploaded) {
        zipLog("All attachments have been uploaded to middleware!", "success");
        zipLog("Processing will continue in the background.", "info");
        zipMigrationInProgress = false;
        zipHasActiveStatus = true;
        updateZipStartButtonByCount();

        // Do not finalize the UI here; processing still continues and status polling should keep updating.
        // Just show the safe-to-quit modal and keep the progress tied to processed/failed.
        loadZipMigrationStats();
        showAllZipsUploadedNotification();
        return;
      }

      var batchInfo = "Uploaded zip batch " + (data.current_zip_batch || 1);
      if (data.total_zip_batches) {
        batchInfo += " of " + data.total_zip_batches;
      }
      zipLog(batchInfo, "info");

      // Start polling for status and update UI
      startZipStatusPolling();
      updateZipStatusUI(data);

      // Continue uploading next zip batch (non-blocking)
      if (data.has_more_batches || data.remaining_attachments > 0) {
        zipLog("Queuing next batch upload...", "info");
        setTimeout(function () {
          continueZipMigration(0);
        }, 500);
      } else {
        zipLog("All zip batches have been queued for upload!", "success");
      }
    }

    // Cancel migration button
    $(document).on("click", "#blitzcdn-zip-cancel-btn", function (e) {
      e.preventDefault();

      if (
        !confirm(
          "Are you sure you want to cancel the migration?\n\nThis will stop any processing on the middleware. Files already uploaded will remain on Appwrite.",
        )
      ) {
        return;
      }

      var $btn = $(this);
      $btn.prop("disabled", true).text("Cancelling...");

      ajaxPost(
        {
          action: "blitzcdn_cancel_zip_migration",
        },
        function (response) {
          if (response.success) {
            zipLog("Migration cancelled.", "warning");
            zipMigrationInProgress = false;
            stopZipStatusPolling();
            updateZipStartButtonByCount();
            $btn.prop("disabled", false).text("🛑 Cancel Migration").hide();
            $("#blitzcdn-zip-migration-status").hide();
            loadZipMigrationStats();
          } else {
            zipLog("Error cancelling: " + response.data, "error");
            $btn.prop("disabled", false).text("🛑 Cancel Migration");
          }
        },
        function (xhr, status, error) {
          zipLog("Network error cancelling: " + error, "error");
          $btn.prop("disabled", false).text("🛑 Cancel Migration");
        },
      );
    });

    // Continue to next zip batch with retry logic (non-blocking queue mode)
    function continueZipMigration(retryAttempt) {
      retryAttempt = retryAttempt || 0;
      var maxRetries = 3;

      if (retryAttempt === 0) {
        zipLog("Uploading next zip batch...", "info");
      } else {
        zipLog(
          "Retry attempt " + retryAttempt + "/" + maxRetries + "...",
          "warning",
        );
      }

      $("#blitzcdn-zip-migrate-btn").text("Uploading batches...");

      ajaxPost(
        {
          action: "blitzcdn_continue_zip_migration",
        },
        function (response) {
          if (response.success) {
            handleZipBatchResponse(response.data, false);
          } else {
            var errorMsg = response.data || "Unknown error";

            // Check if error is retryable
            var isRetryable =
              errorMsg.indexOf("HTTP 413") === -1 &&
              errorMsg.indexOf("Payload Too Large") === -1 &&
              errorMsg.indexOf("too large") === -1;

            if (isRetryable && retryAttempt < maxRetries) {
              var waitSeconds = Math.pow(2, retryAttempt); // Exponential backoff: 1s, 2s, 4s
              zipLog("Error continuing migration: " + errorMsg, "error");
              zipLog("Retrying in " + waitSeconds + " seconds...", "warning");
              setTimeout(function () {
                continueZipMigration(retryAttempt + 1);
              }, waitSeconds * 1000);
            } else {
              if (!isRetryable) {
                zipLog("Error continuing migration: " + errorMsg, "error");
                zipLog(
                  "Cannot retry - zip file may be too large. Check middleware MAX_ZIP_SIZE_MB setting.",
                  "error",
                );
              } else {
                zipLog(
                  "Error continuing migration after " +
                    maxRetries +
                    " attempts: " +
                    errorMsg,
                  "error",
                );
              }
              zipMigrationInProgress = false;
              updateZipStartButtonByCount();
            }
          }
        },
        function (xhr, status, error) {
          var networkError = error || "Network error";

          if (retryAttempt < maxRetries) {
            var waitSeconds = Math.pow(2, retryAttempt); // Exponential backoff
            zipLog("Network error: " + networkError, "error");
            zipLog("Retrying in " + waitSeconds + " seconds...", "warning");
            setTimeout(function () {
              continueZipMigration(retryAttempt + 1);
            }, waitSeconds * 1000);
          } else {
            zipLog(
              "Network error after " +
                maxRetries +
                " attempts: " +
                networkError,
              "error",
            );
            zipMigrationInProgress = false;
            updateZipStartButtonByCount();
          }
        },
      );
    }

    // Check status button
    $(document).on("click", "#blitzcdn-zip-check-status-btn", function (e) {
      e.preventDefault();
      checkZipMigrationStatus();
    });

    // Reset migration button
    $(document).on("click", "#blitzcdn-zip-reset-btn", function (e) {
      e.preventDefault();

      if (
        !confirm(
          "Are you sure you want to reset the migration status?\n\nThis will clear the current status and allow you to start a new migration.",
        )
      ) {
        return;
      }

      ajaxPost(
        {
          action: "blitzcdn_reset_zip_migration",
        },
        function (response) {
          if (response.success) {
            zipLog("Migration status reset.", "success");
            stopZipStatusPolling();
            zipSafeToQuitAlertShown = false;
            zipTotalAssets = null; // reset locked denominator
            // Reset UI high-water marks
            zipDisplayedUploaded = 0;
            zipDisplayedProcessed = 0;
            zipDisplayedFailed = 0;
            zipFinalized = false; // allow UI to be reset after reset
            $("#blitzcdn-zip-migration-status").hide();
            $("#blitzcdn-zip-reset-btn").hide();
            updateZipStartButtonByCount();
            loadZipMigrationStats();
          } else {
            zipLog("Error resetting: " + response.data, "error");
          }
        },
      );
    });

    function checkZipMigrationStatus() {
      ajaxPost(
        {
          action: "blitzcdn_get_zip_migration_status",
        },
        function (response) {
          if (response.success) {
            updateZipStatusUI(response.data);

            // Reload stats to update unmigrated counts.
            // Avoid overwriting the locked denominator during an active run.
            loadZipMigrationStats();

            // Decide whether to poll: keep polling when there is an active migration
            var statusVal = response.data && response.data.status;
            var hasMigrationFields =
              response.data &&
              (response.data.migration_id ||
                response.data.attachments_zipped ||
                response.data.total_attachments_to_migrate ||
                response.data.total_files_uploaded);
            var allZipsUploaded =
              response.data && response.data.all_zips_uploaded;

            var shouldPoll = false;
            // Keep polling while anything is in-flight.
            // Do not stop polling on a transient 'completed' unless all_zips_uploaded is true.
            if (
              statusVal &&
              statusVal !== "idle" &&
              statusVal !== "failed" &&
              statusVal !== "cancelled"
            ) {
              if (
                (statusVal === "completed" ||
                  statusVal === "completed_with_errors") &&
                allZipsUploaded
              ) {
                shouldPoll = false;
              } else {
                shouldPoll = true;
              }
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
        },
      );
    }

    // Show notification when all zips are uploaded
    function showAllZipsUploadedNotification() {
      if (zipSafeToQuitAlertShown) return;

      zipSafeToQuitAlertShown = true;
      zipLog(
        "All zip batches uploaded! Processing continues in the background.",
        "success",
      );
      $("#blitzcdn-zip-safe-modal").show();

      zipMigrationInProgress = false;
      updateZipStartButtonByCount();
      loadZipMigrationStats();
    }

    // Check for middleware confirmations (for status display only, not blocking)
    function maybeNotifySafeToQuit(data) {
      if (!data) return;

      // Log middleware confirmation when received (informational only, once)
      if (data.safe_to_quit && !zipCurrentBatchConfirmed) {
        zipCurrentBatchConfirmed = true;
        zipLog("Middleware confirmed processing for batch.", "info");
      }

      // If processing is finished, show modal if not already shown
      if (
        data.all_zips_uploaded &&
        (data.status === "completed" ||
          data.status === "completed_with_errors" ||
          data.safe_to_quit)
      ) {
        if (!zipSafeToQuitAlertShown) {
          showAllZipsUploadedNotification();
        }
      }
    }

    // Modal button handlers
    $(document).on("click", "#blitzcdn-zip-modal-stay", function (e) {
      e.preventDefault();
      $("#blitzcdn-zip-safe-modal").hide();
    });

    $(document).on("click", "#blitzcdn-zip-modal-close", function (e) {
      e.preventDefault();
      $("#blitzcdn-zip-safe-modal").hide();
      // Try to close the window programmatically; if blocked, just inform the user
      try {
        window.close();
        setTimeout(function () {
          if (!window.closed) {
            alert("You can safely close this page now.");
          }
        }, 200);
      } catch (err) {
        alert("You can safely close this page now.");
      }
    });

    function updateZipStatusUI(data) {
      // Consider the state "empty" only when there's no migration information at all.
      var hasMigrationId = data && data.migration_id;
      var hasAnyTotals =
        data &&
        ((data.total_attachments_to_migrate &&
          data.total_attachments_to_migrate > 0) ||
          (data.attachments_zipped && data.attachments_zipped > 0) ||
          (data.total_files_uploaded && data.total_files_uploaded > 0));

      // If status returns to idle mid-run (common when another zip batch overwrites status),
      // keep the panel visible once we've seen an active run.
      if (
        (data && data.status && data.status !== "idle") ||
        hasMigrationId ||
        hasAnyTotals
      ) {
        zipHasActiveStatus = true;
      }

      var isTrulyEmpty =
        !data || (data.status === "idle" && !hasMigrationId && !hasAnyTotals);
      if (isTrulyEmpty) {
        if (!zipFinalized && !zipHasActiveStatus) {
          // No active migration data to display — hide the panel
          $("#blitzcdn-zip-migration-status").hide();
          $("#blitzcdn-zip-reset-btn").hide();
          return;
        }

        // Keep panel visible during an active run, but do not stamp "Completed".
        $("#blitzcdn-zip-migration-status").show();
        $("#blitzcdn-zip-reset-btn").show();
        $("#blitzcdn-zip-progress-text").text("Working…");
      }

      // Show status panel if we have any migration info (even if status is briefly 'idle')
      $("#blitzcdn-zip-migration-status").show();
      $("#blitzcdn-zip-reset-btn").show();

      // Simple friendly status mapping
      var statusText = data.status || "-";
      var statusColor = "#666";
      switch (data.status) {
        case "zip_created":
          statusColor = "#2271b1";
          statusText = "📦 Zip Created";
          break;
        case "uploading":
          statusColor = "#dba617";
          statusText = "📤 Uploading to Middleware";
          break;
        case "awaiting_confirmation":
          statusColor = "#17a2b8";
          statusText = "📤 Queued";
          break;
        case "processing":
        case "processing_remote":
          statusColor = "#17a2b8";
          statusText = "🔄 Processing on middleware";
          break;
        case "webhook_received":
          statusColor = "#4ec9b0";
          statusText = "📥 Results Received";
          break;
        case "all_zips_uploaded":
          statusColor = "#28a745";
          statusText = "✅ All batches uploaded";
          zipMigrationInProgress = false;
          zipHasActiveStatus = true;
          // Upload phase is complete, but processing continues on the middleware.
          // Do NOT present this as "Completed"; keep progress tied to processed/failed.
          $("#blitzcdn-zip-progress-bar").css(
            "background",
            "linear-gradient(90deg, #2271b1, #4ec9b0)",
          );
          $("#blitzcdn-zip-progress-text").text(
            "All batches uploaded — processing continues on middleware",
          );
          $("#blitzcdn-zip-cancel-btn").hide();
          break;
        case "completed":
          statusColor = "#28a745";
          statusText = "✅ Completed";
          // Only finalize if all zips have been uploaded (don't mark done if more batches are pending)
          if (data.all_zips_uploaded) {
            stopZipStatusPolling();
            zipMigrationInProgress = false;
            zipHasActiveStatus = true;
            zipFinalized = true; // freeze the UI on success only when all zips are done
            updateZipStartButtonByCount();
            $("#blitzcdn-zip-cancel-btn").hide();
            // Force a stable completed UI state
            $("#blitzcdn-zip-progress-bar")
              .css("width", "100%")
              .css("background", "linear-gradient(90deg, #28a745, #4ec9b0)");
            $("#blitzcdn-zip-progress-text").html("✅ Completed");
            loadZipMigrationStats();
          } else {
            // Still processing more batches, don't finalize - just update status text
            statusColor = "#17a2b8";
            statusText = "🔄 Processing on middleware (more batches pending)";
          }
          break;
        case "completed_with_errors":
          statusColor = "#ffc107";
          statusText = "⚠️ Completed with errors";
          // Only finalize if all zips have been uploaded
          if (data.all_zips_uploaded) {
            stopZipStatusPolling();
            zipMigrationInProgress = false;
            zipHasActiveStatus = true;
            zipFinalized = true; // freeze the UI on final-with-errors only when all zips are done
            updateZipStartButtonByCount();
            $("#blitzcdn-zip-cancel-btn").hide();
            // Force a stable completed-with-errors UI state
            $("#blitzcdn-zip-progress-bar")
              .css("width", "100%")
              .css("background", "linear-gradient(90deg, #ffc107, #ffdf7e)");
            $("#blitzcdn-zip-progress-text").html("⚠️ Completed with errors");
            loadZipMigrationStats();
          } else {
            // Still processing more batches, don't finalize - treat as still processing
            statusColor = "#17a2b8";
            statusText = "🔄 Processing on middleware (more batches pending)";
          }
          break;
        case "cancelled":
          statusColor = "#6c757d";
          statusText = "🛑 Cancelled";
          stopZipStatusPolling();
          zipMigrationInProgress = false;
          zipHasActiveStatus = false;
          updateZipStartButtonByCount();
          $("#blitzcdn-zip-cancel-btn").hide();
          loadZipMigrationStats();
          break;
        case "failed":
        case "upload_failed":
        case "webhook_error":
          statusColor = "#dc3545";
          statusText =
            "❌ Error: " + (data.error || data.message || data.status);
          stopZipStatusPolling();
          zipMigrationInProgress = false;
          zipHasActiveStatus = false;
          updateZipStartButtonByCount();
          $("#blitzcdn-zip-cancel-btn").hide();
          break;
      }

      maybeNotifySafeToQuit(data);

      // Keep status concise (avoid per-batch confusing logs)
      $("#blitzcdn-zip-status-text").html(
        '<span style="color: ' + statusColor + ';">' + statusText + "</span>",
      );
      $("#blitzcdn-zip-migration-id").text(data.migration_id || "-");

      // Lock total assets from backend's total_assets_to_migrate (set once at migration start)
      if (
        data.total_assets_to_migrate &&
        (!zipTotalAssets || zipTotalAssets <= 0)
      ) {
        zipTotalAssets = parseInt(data.total_assets_to_migrate, 10);
      }

      // Fallback: if we still don't have it, try from DOM (loaded from stats endpoint)
      var totalAssets = zipTotalAssets || 0;
      if (!totalAssets || totalAssets <= 0) {
        var totalAssetsFromDom = parseInt(
          $("#blitzcdn-zip-total-assets").text(),
          10,
        );
        if (!isNaN(totalAssetsFromDom) && totalAssetsFromDom > 0) {
          totalAssets = totalAssetsFromDom;
          zipTotalAssets = totalAssets;
        }
      }

      // Use backend values directly - they are now cumulative and reliable
      var uploaded =
        data.total_files_uploaded !== undefined
          ? parseInt(data.total_files_uploaded, 10) || 0
          : 0;
      var processed =
        data.processed !== undefined ? parseInt(data.processed, 10) || 0 : 0;
      var failed =
        data.failed !== undefined ? parseInt(data.failed, 10) || 0 : 0;

      // Apply high-water-mark guards to prevent flicker from stale AJAX responses
      // Never display a value lower than what we've already shown
      if (uploaded >= zipDisplayedUploaded) {
        zipDisplayedUploaded = uploaded;
      }
      if (processed >= zipDisplayedProcessed) {
        zipDisplayedProcessed = processed;
      }
      if (failed >= zipDisplayedFailed) {
        zipDisplayedFailed = failed;
      }

      // Update stat cards with guarded values (never decrease)
      $("#blitzcdn-zip-uploaded-count").text(
        zipDisplayedUploaded > 0 ? zipDisplayedUploaded : "-",
      );
      $("#blitzcdn-zip-processed-count").text(
        zipDisplayedProcessed > 0 ? zipDisplayedProcessed : "-",
      );
      $("#blitzcdn-zip-failed-count").text(
        zipDisplayedFailed > 0 ? zipDisplayedFailed : "-",
      );

      // Progress is based on processed+failed out of total assets (represents completed work)
      // If we've finalized the migration UI, don't overwrite the final display
      if (!zipFinalized) {
        // Use guarded values for progress to prevent jumps
        var completed = zipDisplayedProcessed + zipDisplayedFailed;
        var percent = 0;

        if (zipTotalAssets && zipTotalAssets > 0) {
          percent = Math.round((completed / zipTotalAssets) * 100);
        } else {
          // No denominator yet; show 0% instead of jumping to 100%.
          percent = 0;
        }

        percent = Math.max(0, Math.min(100, percent));
        $("#blitzcdn-zip-progress-bar").css("width", percent + "%");

        // Completed state should only display when all zips are uploaded AND middleware finished processing
        var isCompleted =
          data &&
          data.all_zips_uploaded &&
          (data.status === "completed" ||
            data.status === "completed_with_errors");
        if (isCompleted) {
          var completeText =
            data.status === "completed_with_errors"
              ? "⚠️ Completed with errors"
              : "✅ Completed";
          $("#blitzcdn-zip-progress-bar").css(
            "background",
            "linear-gradient(90deg, #28a745, #4ec9b0)",
          );
          $("#blitzcdn-zip-progress-text").html(
            completeText +
              " — " +
              completed +
              " / " +
              (zipTotalAssets || "-") +
              " processed",
          );
        } else {
          $("#blitzcdn-zip-progress-bar").css(
            "background",
            "linear-gradient(90deg, #2271b1, #4ec9b0)",
          );

          // While we're still uploading zip batches, reflect that in the UI (no premature "Completed").
          var uploadPhase =
            !data.all_zips_uploaded &&
            (data.current_zip_batch || data.total_zip_batches);
          if (uploadPhase && data.total_zip_batches) {
            $("#blitzcdn-zip-progress-text").text(
              "Uploading batches — " +
                (data.current_zip_batch || 0) +
                " / " +
                data.total_zip_batches +
                " uploaded",
            );
          } else {
            $("#blitzcdn-zip-progress-text").text(
              percent +
                "% — " +
                completed +
                " / " +
                (zipTotalAssets || "-") +
                " processed",
            );
          }
        }
      } // if not zipFinalized (skip updates when finalized)

      // Show/hide cancel button based on migration status
      var cancellableStatuses = [
        "uploading",
        "awaiting_confirmation",
        "processing",
        "processing_remote",
        "zip_created",
        "all_zips_uploaded",
      ];
      if (cancellableStatuses.indexOf(data.status) !== -1) {
        $("#blitzcdn-zip-cancel-btn").show();
      } else {
        $("#blitzcdn-zip-cancel-btn").hide();
      }

      // Log any file failures (only once)
      if (
        data.files_failed &&
        data.files_failed.length > 0 &&
        !data._files_failed_logged
      ) {
        data._files_failed_logged = true;
        zipLog("Some files could not be added to zip:", "warning");
        data.files_failed.forEach(function (f) {
          zipLog(
            "  - Attachment #" +
              f.attachment_id +
              ": " +
              f.file +
              " (" +
              f.reason +
              ")",
            "warning",
          );
        });
      }
    }

    function startZipStatusPolling() {
      if (zipMigrationStatusPollInterval) return;

      zipMigrationStatusPollInterval = setInterval(function () {
        ajaxPost(
          {
            action: "blitzcdn_get_zip_migration_status",
          },
          function (response) {
            if (response.success) {
              updateZipStatusUI(response.data);
            }
          },
        );
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
      var color = "#d4d4d4"; // Default gray

      switch (type) {
        case "success":
          color = "#4ec9b0";
          break;
        case "error":
          color = "#f14c4c";
          break;
        case "warning":
          color = "#cca700";
          break;
        case "info":
          color = "#3794ff";
          break;
      }

      var logHtml = '<div style="color: ' + color + '; margin-bottom: 4px;">';
      if (message) {
        logHtml +=
          '<span style="color: #6a9955;">[' + timestamp + "]</span> " + message;
      }
      logHtml += "</div>";

      var $log = $("#blitzcdn-zip-migration-log");
      $log.append(logHtml);

      // On warnings or errors, make the log visible so admins can inspect issues
      if (type === "error" || type === "warning") {
        $log.show();
      }

      // Auto-scroll to bottom
      var logContainer = $log[0];
      if (logContainer) {
        logContainer.scrollTop = logContainer.scrollHeight;
      }
    }

    // ========================================
    // EMAIL REDOWNLOAD Logic
    // ========================================
    var isEmailRedownloading = false;
    var emailTotalFiles = 0;
    var emailProcessedFiles = 0;
    var emailFileIds = [];
    var emailBatchSize = 20; // Process 20 files at a time
    var emailSuccessCount = 0;
    var emailSkippedCount = 0;
    var emailErrorCount = 0;

    // Check files for email
    $(document).on("click", "#blitzcdn-check-email-btn", function (e) {
      e.preventDefault();

      var email = $("#blitzcdn-email-input").val().trim();
      if (!email) {
        alert("Please enter an email address");
        return;
      }

      // Email validation
      var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email)) {
        alert("Please enter a valid email address");
        return;
      }

      $(this).prop("disabled", true).text("Checking...");
      $("#blitzcdn-email-stats").hide();
      $("#blitzcdn-email-redownload-btn").hide();

      ajaxPost(
        {
          action: "blitzcdn_get_email_stats",
          email: email,
        },
        function (response) {
          $("#blitzcdn-check-email-btn")
            .prop("disabled", false)
            .text("Check Files for Email");

          if (response.status === "success") {
            emailTotalFiles = response.total;
            emailFileIds = response.file_ids || [];

            $("#blitzcdn-email-files-count").text(emailTotalFiles);
            $("#blitzcdn-email-stats").show();

            if (emailTotalFiles > 0) {
              $("#blitzcdn-email-redownload-btn").show();
            }
          } else {
            alert("Error: " + response.message);
          }
        },
        function (xhr, status, error) {
          $("#blitzcdn-check-email-btn")
            .prop("disabled", false)
            .text("Check Files for Email");
          alert("Network error: " + error);
        },
      );
    });

    // Start email redownload
    $(document).on("click", "#blitzcdn-email-redownload-btn", function (e) {
      e.preventDefault();

      if (isEmailRedownloading) return;

      var email = $("#blitzcdn-email-input").val().trim();
      if (!email || emailFileIds.length === 0) {
        alert("No files to process");
        return;
      }

      var mode = $('input[name="blitzcdn-email-mode"]:checked').val();

      // Handle background mode separately
      if (mode === "link-background") {
        if (
          confirm(
            "Start background linking for " +
              emailTotalFiles +
              " file(s)?\n\n" +
              "This will run in the background using WP-Cron. You can close your browser and check back later.",
          )
        ) {
          startBackgroundLinking(email);
        }
        return;
      }

      // Browser-based processing
      var modeText = mode === "link" ? "linking" : "downloading";
      var actionText = mode === "link" ? "Link" : "Download";

      if (
        !confirm(
          actionText +
            " " +
            emailTotalFiles +
            " file(s) from Appwrite?\n\n" +
            (mode === "link"
              ? "This will create WordPress attachments pointing to CDN URLs (fast, files stay on CDN)."
              : "This will download files and create WordPress attachments (slower, files saved locally)."),
        )
      ) {
        return;
      }

      startEmailRedownload(mode);
    });

    // Cancel email redownload
    $(document).on(
      "click",
      "#blitzcdn-cancel-email-redownload-btn",
      function (e) {
        e.preventDefault();

        if (!isEmailRedownloading) return;

        if (confirm("Are you sure you want to cancel the process?")) {
          isEmailRedownloading = false;
          emailFileIds = [];
          emailLog("Process cancelled by user", "warning");
          $("#blitzcdn-email-redownload-btn").prop("disabled", false).show();
          $("#blitzcdn-cancel-email-redownload-btn").hide();
        }
      },
    );

    function startEmailRedownload(mode) {
      mode = mode || "link"; // Default to link mode
      isEmailRedownloading = true;
      emailProcessedFiles = 0;
      emailSuccessCount = 0;
      emailSkippedCount = 0;
      emailErrorCount = 0;

      $("#blitzcdn-email-redownload-btn").prop("disabled", true).hide();
      $("#blitzcdn-cancel-email-redownload-btn").show();
      $("#blitzcdn-check-email-btn").prop("disabled", true);
      $("#blitzcdn-email-input").prop("disabled", true);
      $('input[name="blitzcdn-email-mode"]').prop("disabled", true);
      $("#blitzcdn-email-redownload-progress").show();
      $("#blitzcdn-email-redownload-log").empty();

      var modeText = mode === "link" ? "linking" : "downloading";
      emailLog(
        "Starting " + modeText + " of " + emailTotalFiles + " file(s)...",
        "info",
      );
      processEmailBatch(mode);
    }

    function processEmailBatch(mode) {
      if (!isEmailRedownloading || emailFileIds.length === 0) {
        finishEmailRedownload();
        return;
      }

      // Dynamic batch size based on mode
      // Link mode is much faster (no downloads), so use larger batches
      var batchSize = mode === "link" ? 200 : 20;
      var batch = emailFileIds.splice(0, batchSize);
      var action =
        mode === "link"
          ? "blitzcdn_email_link_batch"
          : "blitzcdn_email_redownload_batch";

      emailLog("Processing batch of " + batch.length + " file(s)...", "info");

      var payload = {
        action: action,
        file_ids: batch,
      };

      // Only pass create_attachments for download mode
      if (mode === "download") {
        payload.create_attachments = "true";
      }

      ajaxPost(
        payload,
        function (response) {
          if (response.success) {
            $.each(response.data, function (fileId, result) {
              emailProcessedFiles++;

              if (result.status === "success") {
                emailSuccessCount++;
                var msg =
                  fileId + ": " + result.file_name + " - " + result.message;
                if (result.attachment_id) {
                  msg += " (Attachment #" + result.attachment_id + ")";
                }
                emailLog(msg, "success");
              } else if (result.status === "skipped") {
                emailSkippedCount++;
                emailLog(
                  fileId + ": " + result.file_name + " - " + result.message,
                  "warning",
                );
              } else {
                emailErrorCount++;
                emailLog(
                  fileId + ": " + result.file_name + " - " + result.message,
                  "error",
                );
              }
            });
          } else {
            // Batch failed
            emailErrorCount += batch.length;
            emailProcessedFiles += batch.length;
            emailLog(
              "Batch failed: " + (response.data || "Unknown error"),
              "error",
            );
          }

          updateEmailProgress();

          // Continue to next batch
          setTimeout(function () {
            processEmailBatch(mode);
          }, 100);
        },
        function (xhr, status, error) {
          emailLog("Network error processing batch: " + error, "error");
          emailErrorCount += batch.length;
          emailProcessedFiles += batch.length;
          updateEmailProgress();

          // Try to continue with remaining items
          setTimeout(function () {
            processEmailBatch(mode);
          }, 1000);
        },
      );
    }

    function updateEmailProgress() {
      var percent =
        emailTotalFiles > 0
          ? Math.round((emailProcessedFiles / emailTotalFiles) * 100)
          : 0;
      $("#blitzcdn-email-redownload-progress-bar").css("width", percent + "%");
      $("#blitzcdn-email-redownload-progress-text").text(
        percent + "% (" + emailProcessedFiles + " / " + emailTotalFiles + ")",
      );

      $("#blitzcdn-email-success-count").text(emailSuccessCount);
      $("#blitzcdn-email-skipped-count").text(emailSkippedCount);
      $("#blitzcdn-email-error-count").text(emailErrorCount);
    }

    function finishEmailRedownload() {
      isEmailRedownloading = false;

      $("#blitzcdn-email-redownload-btn").prop("disabled", false).show();
      $("#blitzcdn-cancel-email-redownload-btn").hide();
      $("#blitzcdn-check-email-btn").prop("disabled", false);
      $("#blitzcdn-email-input").prop("disabled", false);
      $('input[name="blitzcdn-email-mode"]').prop("disabled", false);

      var summary = "Process complete! ";
      summary += emailSuccessCount + " processed, ";
      summary += emailSkippedCount + " skipped, ";
      summary += emailErrorCount + " errors.";

      emailLog(summary, emailErrorCount > 0 ? "warning" : "success");

      if (emailErrorCount === 0 && emailSuccessCount > 0) {
        emailLog("All files processed successfully!", "success");
      }
    }

    function emailLog(message, type) {
      var timestamp = new Date().toLocaleTimeString();
      var color = "#d4d4d4"; // Default gray

      switch (type) {
        case "success":
          color = "#4ec9b0";
          break;
        case "error":
          color = "#f14c4c";
          break;
        case "warning":
          color = "#cca700";
          break;
        case "info":
          color = "#3794ff";
          break;
      }

      var logHtml = '<div style="color: ' + color + '; margin-bottom: 4px;">';
      logHtml +=
        '<span style="color: #6a9955;">[' + timestamp + "]</span> " + message;
      logHtml += "</div>";

      $("#blitzcdn-email-redownload-log").append(logHtml);

      // Auto-scroll to bottom
      var logContainer = $("#blitzcdn-email-redownload-log")[0];
      if (logContainer) {
        logContainer.scrollTop = logContainer.scrollHeight;
      }
    }

    // ===============================
    // Background Email Linking (WP-Cron)
    // ===============================

    var backgroundLinkingStatusInterval = null;

    // Check for existing background linking on page load
    checkBackgroundLinkStatus();

    function checkBackgroundLinkStatus() {
      ajaxPost(
        {
          action: "blitzcdn_get_background_link_status",
        },
        function (response) {
          if (response.success && response.data) {
            updateBackgroundLinkUI(response.data);
          }
        },
        function (error) {
          console.error("Failed to check background link status:", error);
        },
      );
    }

    function updateBackgroundLinkUI(status) {
      if (status.status === "running") {
        // Show the status section
        $("#blitzcdn-background-link-status").show();
        $("#blitzcdn-bg-link-status-text").text("Running");
        $("#blitzcdn-bg-link-email").text(status.email || "-");

        // Update counters
        $("#blitzcdn-bg-link-processed").text(status.processed || 0);
        $("#blitzcdn-bg-link-successful").text(status.successful || 0);
        $("#blitzcdn-bg-link-skipped").text(status.skipped || 0);
        $("#blitzcdn-bg-link-failed").text(status.failed || 0);

        // Update progress bar
        var percentage = status.percentage || 0;
        $("#blitzcdn-bg-link-progress-bar").css("width", percentage + "%");
        $("#blitzcdn-bg-link-progress-text").text(
          status.processed +
            " / " +
            status.total +
            " (" +
            percentage.toFixed(1) +
            "%)",
        );

        // Start polling if not already polling
        if (!backgroundLinkingStatusInterval) {
          backgroundLinkingStatusInterval = setInterval(function () {
            checkBackgroundLinkStatus();
          }, 3000); // Poll every 3 seconds
        }
      } else if (status.status === "completed") {
        $("#blitzcdn-background-link-status").show();
        $("#blitzcdn-bg-link-status-text")
          .text("Completed ✓")
          .css("color", "#155724");

        // Update final counts
        $("#blitzcdn-bg-link-processed").text(status.processed || 0);
        $("#blitzcdn-bg-link-successful").text(status.successful || 0);
        $("#blitzcdn-bg-link-skipped").text(status.skipped || 0);
        $("#blitzcdn-bg-link-failed").text(status.failed || 0);

        // Set progress to 100%
        $("#blitzcdn-bg-link-progress-bar").css("width", "100%");
        $("#blitzcdn-bg-link-progress-text").text("100% Complete");

        // Stop polling
        if (backgroundLinkingStatusInterval) {
          clearInterval(backgroundLinkingStatusInterval);
          backgroundLinkingStatusInterval = null;
        }
      } else if (status.status === "stopped") {
        $("#blitzcdn-background-link-status").show();
        $("#blitzcdn-bg-link-status-text")
          .text("Stopped")
          .css("color", "#856404");

        // Stop polling
        if (backgroundLinkingStatusInterval) {
          clearInterval(backgroundLinkingStatusInterval);
          backgroundLinkingStatusInterval = null;
        }
      } else {
        // Idle or unknown status
        $("#blitzcdn-background-link-status").hide();

        // Stop polling
        if (backgroundLinkingStatusInterval) {
          clearInterval(backgroundLinkingStatusInterval);
          backgroundLinkingStatusInterval = null;
        }
      }
    }

    function startBackgroundLinking(email) {
      ajaxPost(
        {
          action: "blitzcdn_start_background_link",
          email: email,
        },
        function (response) {
          if (response.success) {
            alert(
              "Background linking started! Check the status section below.",
            );

            // Hide the email stats and show background status
            $("#blitzcdn-email-stats").hide();
            $("#blitzcdn-email-redownload-btn").hide();
            $("#blitzcdn-background-link-status").show();
            $("#blitzcdn-bg-link-email").text(email);
            $("#blitzcdn-bg-link-status-text").text("Running");

            // Start polling for status
            if (!backgroundLinkingStatusInterval) {
              backgroundLinkingStatusInterval = setInterval(function () {
                checkBackgroundLinkStatus();
              }, 3000);
            }
          } else {
            alert(
              "Failed to start background linking: " +
                (response.data || "Unknown error"),
            );
          }
        },
        function (error) {
          alert(
            "Error starting background linking: " +
              (error.message || "Unknown error"),
          );
        },
      );
    }

    // Stop background linking
    $(document).on("click", "#blitzcdn-stop-background-link-btn", function (e) {
      e.preventDefault();

      if (!confirm("Are you sure you want to stop the background linking?")) {
        return;
      }

      ajaxPost(
        {
          action: "blitzcdn_stop_background_link",
        },
        function (response) {
          if (response.success) {
            alert("Background linking stopped");
            checkBackgroundLinkStatus();
          } else {
            alert("Failed to stop background linking");
          }
        },
        function (error) {
          alert(
            "Error stopping background linking: " +
              (error.message || "Unknown error"),
          );
        },
      );
    });

    // Refresh background linking status
    $(document).on(
      "click",
      "#blitzcdn-refresh-background-link-btn",
      function (e) {
        e.preventDefault();
        checkBackgroundLinkStatus();
      },
    );

    // WooCommerce Image Reconnection
    $(document).on(
      "click",
      "#blitzcdn-reconnect-woocommerce-btn",
      function (e) {
        e.preventDefault();

        var $btn = $(this);
        var $progress = $("#blitzcdn-reconnect-woocommerce-progress");
        var $progressBar = $("#blitzcdn-reconnect-woocommerce-progress-bar");
        var $progressText = $("#blitzcdn-reconnect-woocommerce-progress-text");
        var $log = $("#blitzcdn-reconnect-woocommerce-log");
        var $status = $("#blitzcdn-reconnect-woocommerce-status");
        var $error = $("#blitzcdn-reconnect-woocommerce-error");
        var $message = $("#blitzcdn-reconnect-woocommerce-message");
        var $stats = $("#blitzcdn-reconnect-woocommerce-stats");
        var $errorMessage = $("#blitzcdn-reconnect-woocommerce-error-message");

        // Accumulated statistics across all batches
        var totalStats = {
          attachments_scanned: 0,
          products_updated: 0,
          variations_updated: 0,
          galleries_updated: 0,
          total_attachments: 0,
        };

        // Hide previous results
        $status.hide();
        $error.hide();
        $log.empty();

        // Show progress
        $progress.show();
        $progressBar.css("width", "5%");
        $progressText.text("Initializing...");

        function addLog(msg, type) {
          var color = "#d4d4d4"; // default
          if (type === "success") color = "#4ec9b0";
          else if (type === "error") color = "#f48771";
          else if (type === "warning") color = "#ce9178";
          else if (type === "info") color = "#569cd6";

          var timestamp = new Date().toLocaleTimeString();
          $log.append(
            '<div style="color:' +
              color +
              '; margin-bottom: 4px;">[' +
              timestamp +
              "] " +
              msg +
              "</div>",
          );
          $log.scrollTop($log[0].scrollHeight);
        }

        function processBatch(offset) {
          ajaxPost(
            {
              action: "blitzcdn_reconnect_woocommerce",
              offset: offset,
            },
            function (response) {
              if (response.success && response.data) {
                var data = response.data;

                // Update total attachments count on first batch
                if (offset === 0 && data.total_attachments) {
                  totalStats.total_attachments = data.total_attachments;
                  addLog(
                    "Found " +
                      data.total_attachments +
                      " CDN attachments to scan",
                    "info",
                  );
                }

                // Accumulate statistics
                totalStats.attachments_scanned += data.attachments_scanned || 0;
                totalStats.products_updated += data.products_updated || 0;
                totalStats.variations_updated += data.variations_updated || 0;
                totalStats.galleries_updated += data.galleries_updated || 0;

                // Calculate and update progress
                var progressPercent = 10;
                if (totalStats.total_attachments > 0) {
                  progressPercent =
                    10 +
                    Math.floor(
                      (totalStats.attachments_scanned /
                        totalStats.total_attachments) *
                        90,
                    );
                }
                $progressBar.css("width", progressPercent + "%");
                $progressText.text(
                  "Processed " +
                    totalStats.attachments_scanned +
                    " of " +
                    totalStats.total_attachments +
                    " attachments...",
                );

                // Log batch results if any updates were made
                if (
                  data.products_updated > 0 ||
                  data.variations_updated > 0 ||
                  data.galleries_updated > 0
                ) {
                  addLog(
                    "Batch " +
                      Math.floor(offset / 50 + 1) +
                      ": Updated " +
                      data.products_updated +
                      " products, " +
                      data.variations_updated +
                      " variations, " +
                      data.galleries_updated +
                      " galleries",
                    "success",
                  );
                }
                
                // Log URL fixes if available (first batch only)
                if (data.url_fixes && data.url_fixes.length > 0) {
                  addLog("─────────────────────────────", "info");
                  addLog("🔧 Broken URL Fixes:", "info");
                  data.url_fixes.forEach(function(fix) {
                    var logType = fix.type || "info";
                    addLog(fix.message, logType);
                  });
                  addLog("─────────────────────────────", "info");
                }

                // Check if there are more batches to process
                if (data.has_more) {
                  // Continue with next batch
                  processBatch(data.next_offset);
                } else {
                  // All batches complete
                  $progressBar.css("width", "100%");
                  $progressText.text("Complete!");

                  addLog("✓ All batches complete!", "success");
                  addLog(
                    "Total attachments scanned: " +
                      totalStats.attachments_scanned,
                    "info",
                  );
                  addLog(
                    "Total products updated: " + totalStats.products_updated,
                    "success",
                  );
                  addLog(
                    "Total variations updated: " +
                      totalStats.variations_updated,
                    "success",
                  );
                  addLog(
                    "Total galleries updated: " + totalStats.galleries_updated,
                    "success",
                  );
                  
                  // Show URL fixes count if available
                  if (data.attachments_with_fixed_urls) {
                    addLog(
                      "Attachments with fixed URLs: " + data.attachments_with_fixed_urls,
                      "success",
                    );
                  }

                  $message.text(
                    "Processing complete - scanned " +
                      totalStats.attachments_scanned +
                      " attachments",
                  );

                  var statsHtml = "";
                  statsHtml +=
                    "<strong>Attachments scanned:</strong> " +
                    totalStats.attachments_scanned +
                    "<br>";
                  statsHtml +=
                    "<strong>Products updated:</strong> " +
                    totalStats.products_updated +
                    "<br>";
                  statsHtml +=
                    "<strong>Variations updated:</strong> " +
                    totalStats.variations_updated +
                    "<br>";
                  statsHtml +=
                    "<strong>Galleries updated:</strong> " +
                    totalStats.galleries_updated;
                  
                  if (data.attachments_with_fixed_urls) {
                    statsHtml +=
                      "<br><strong>Attachments with fixed URLs:</strong> " +
                      data.attachments_with_fixed_urls;
                  }

                  $stats.html(statsHtml);
                  $status.fadeIn();

                  setTimeout(function () {
                    $btn
                      .prop("disabled", false)
                      .text("Reconnect WooCommerce Images");
                  }, 1000);
                }
              } else {
                addLog(
                  "✗ Error: " +
                    (response.data?.message || "Unknown error occurred"),
                  "error",
                );
                $errorMessage.text(
                  response.data?.message || "Unknown error occurred",
                );
                $error.fadeIn();
                $btn
                  .prop("disabled", false)
                  .text("Reconnect WooCommerce Images");
              }
            },
            function (xhr, status, error) {
              $progressBar.css("width", "100%");
              $progressText.text("Failed!");

              var errorMsg = "Request failed";

              // Try to get detailed error message
              if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                if (xhr.responseJSON.data.message) {
                  errorMsg = xhr.responseJSON.data.message;
                } else if (typeof xhr.responseJSON.data === "string") {
                  errorMsg = xhr.responseJSON.data;
                }
              } else if (xhr && xhr.responseText) {
                // Try to parse responseText
                try {
                  var parsed = JSON.parse(xhr.responseText);
                  if (parsed.data && parsed.data.message) {
                    errorMsg = parsed.data.message;
                  }
                } catch (e) {
                  // If not JSON, might be a PHP error
                  if (xhr.responseText.length < 500) {
                    errorMsg = xhr.responseText;
                  } else {
                    errorMsg = "Server error occurred (check PHP error log)";
                  }
                }
              } else if (status) {
                errorMsg = "Request failed: " + status;
              }

              addLog("✗ " + errorMsg, "error");
              console.error("WooCommerce reconnect error:", xhr, status, error);

              $errorMessage.text(errorMsg);
              $error.fadeIn();
              $btn.prop("disabled", false).text("Reconnect WooCommerce Images");
            },
          );
        }

        // Disable button
        $btn.prop("disabled", true).text("Processing...");

        addLog("Starting WooCommerce image reconnection...", "info");
        $progressBar.css("width", "10%");
        $progressText.text("Scanning CDN attachments...");

        // Start processing from offset 0
        processBatch(0);
      },
    );

    // Fix CDN URLs button
    $(document).on("click", "#blitzcdn-fix-urls-btn", function (e) {
      e.preventDefault();

      var $btn = $(this);
      var $progress = $("#blitzcdn-fix-urls-progress");
      var $progressBar = $("#blitzcdn-fix-urls-progress-bar");
      var $progressText = $("#blitzcdn-fix-urls-progress-text");
      var $log = $("#blitzcdn-fix-urls-log");
      var $status = $("#blitzcdn-fix-urls-status");
      var $error = $("#blitzcdn-fix-urls-error");
      var $message = $("#blitzcdn-fix-urls-message");
      var $stats = $("#blitzcdn-fix-urls-stats");
      var $errorMessage = $("#blitzcdn-fix-urls-error-message");

      // Accumulated statistics across all batches
      var totalStats = {
        fixed: 0,
        reconstructed: 0,
        already_correct: 0,
        processed: 0,
        total_attachments: 0,
      };

      // Hide previous results
      $status.hide();
      $error.hide();
      $log.empty();

      // Show progress
      $progress.show();
      $progressBar.css("width", "5%");
      $progressText.text("Initializing...");

      function addLog(msg, type, skipTimestamp) {
        var color = "#d4d4d4"; // default
        if (type === "success") color = "#4ec9b0";
        else if (type === "error") color = "#f48771";
        else if (type === "warning") color = "#ce9178";
        else if (type === "info") color = "#569cd6";
        else if (type === "debug") color = "#808080";

        var timestamp = skipTimestamp ? "" : "[" + new Date().toLocaleTimeString() + "] ";
        var fontSize = msg.startsWith("  ") ? "11px" : "12px";
        var opacity = msg.startsWith("  Debug:") ? "0.7" : "1";
        
        $log.append(
          '<div style="color:' +
            color +
            '; margin-bottom: 2px; font-size: ' +
            fontSize +
            '; opacity: ' +
            opacity +
            '; line-height: 1.4;">' +
            timestamp +
            msg +
            "</div>",
        );
        
        // Keep only last 100 log entries for performance
        var logEntries = $log.children();
        if (logEntries.length > 100) {
          logEntries.slice(0, logEntries.length - 100).remove();
        }
        
        $log.scrollTop($log[0].scrollHeight);
      }

      function processBatch(offset) {
        ajaxPost(
          {
            action: "blitzcdn_fix_url_structure",
            offset: offset,
          },
          function (response) {
            if (response.success && response.data) {
              var data = response.data;

              // Update total attachments count on first batch
              if (offset === 0 && data.total_attachments) {
                totalStats.total_attachments = data.total_attachments;
                addLog(
                  "Found " +
                    data.total_attachments +
                    " CDN attachments to process",
                  "info",
                );
                
                // Display settings check on first batch
                if (data.settings_check) {
                  addLog("Settings Check:", "info");
                  addLog("  Endpoint: " + data.settings_check.endpoint, 
                         data.settings_check.endpoint === "NOT SET" ? "error" : "success");
                  addLog("  Bucket ID: " + data.settings_check.bucket_id,
                         data.settings_check.bucket_id === "NOT SET" ? "error" : "success");
                  addLog("  Project ID: " + data.settings_check.project_id,
                         data.settings_check.project_id === "NOT SET" ? "error" : "success");
                  addLog("", "info", true);
                }
              }

              // Accumulate statistics
              totalStats.fixed += data.fixed || 0;
              totalStats.reconstructed += data.reconstructed || 0;
              totalStats.already_correct += data.already_correct || 0;
              totalStats.processed += data.processed || 0;

              // Log batch header
              addLog(
                "━━━ Batch " +
                  Math.floor(offset / 50 + 1) +
                  " (Offset: " +
                  offset +
                  ") ━━━",
                "info",
              );

              // Log each processed URL with detailed information
              if (data.processed_urls && data.processed_urls.length > 0) {
                data.processed_urls.forEach(function (urlData) {
                  var logMsg = "";
                  var logType = "info";

                  // Main action message
                  if (urlData.action === "fixed") {
                    logMsg = "🔧 FIXED ID " + urlData.id;
                    logType = "success";
                    addLog(logMsg, logType);
                    addLog("  Old: " + urlData.old_url, "debug", true);
                    addLog("  New: " + urlData.new_url, "success", true);
                  } else if (urlData.action === "reconstructed") {
                    logMsg = "🔧 RECONSTRUCTED ID " + urlData.id;
                    logType = "warning";
                    addLog(logMsg, logType);
                    addLog("  Old: " + urlData.old_url, "debug", true);
                    addLog("  New: " + urlData.new_url, "warning", true);
                  } else if (urlData.action === "correct") {
                    logMsg = "✓ ID " + urlData.id + " - Already correct";
                    logType = "info";
                    addLog(logMsg, logType);
                    if (urlData.url) {
                      addLog("  URL: " + urlData.url, "debug", true);
                    }
                  } else {
                    logMsg = "⚠ ID " + urlData.id + " - Skipped";
                    logType = "warning";
                    addLog(logMsg, logType);
                  }

                  // Add detailed diagnostic log if available
                  if (urlData.log) {
                    addLog("  Debug: " + urlData.log, "debug", true);
                  }
                  
                  // Add spacing between entries (skip timestamp for blank line)
                  addLog("", "info", true);
                });
              }

              // Calculate and update progress
              var progressPercent = 10;
              if (totalStats.total_attachments > 0) {
                progressPercent =
                  10 +
                  Math.floor(
                    (totalStats.processed / totalStats.total_attachments) * 90,
                  );
              }
              $progressBar.css("width", progressPercent + "%");
              $progressText.text(
                "Processed " +
                  totalStats.processed +
                  " of " +
                  totalStats.total_attachments +
                  " attachments...",
              );

              // Check if there are more batches to process
              if (data.has_more) {
                // Continue with next batch
                processBatch(data.next_offset);
              } else {
                // All batches complete
                $progressBar.css("width", "100%");
                $progressText.text("Complete!");

                addLog("─────────────────────────────", "info");
                addLog("✓ All batches complete!", "success");
                addLog(
                  "Total processed: " + totalStats.processed,
                  "info",
                );
                addLog(
                  "Fixed: " + totalStats.fixed +
                  " (Reconstructed: " + totalStats.reconstructed + ")",
                  "success",
                );
                addLog(
                  "Already correct: " + totalStats.already_correct,
                  "info",
                );

                $message.text(
                  "Processing complete - fixed " +
                    totalStats.fixed +
                    " URLs (" +
                    totalStats.reconstructed +
                    " reconstructed)",
                );

                var statsHtml = "";
                statsHtml +=
                  "<strong>Total processed:</strong> " +
                  totalStats.processed +
                  "<br>";
                statsHtml +=
                  "<strong>Fixed:</strong> " + totalStats.fixed + "<br>";
                statsHtml +=
                  "<strong>Reconstructed:</strong> " +
                  totalStats.reconstructed +
                  "<br>";
                statsHtml +=
                  "<strong>Already correct:</strong> " +
                  totalStats.already_correct;

                $stats.html(statsHtml);
                $status.fadeIn();

                setTimeout(function () {
                  $btn.prop("disabled", false).text("Fix CDN URL Structure");
                }, 1000);
              }
            } else {
              addLog(
                "✗ Error: " +
                  (response.data?.message || "Unknown error occurred"),
                "error",
              );
              $errorMessage.text(
                response.data?.message || "Unknown error occurred",
              );
              $error.fadeIn();
              $btn.prop("disabled", false).text("Fix CDN URL Structure");
            }
          },
          function (xhr, status, error) {
            $progressBar.css("width", "100%");
            $progressText.text("Failed!");

            var errorMsg = "Request failed";

            // Try to get detailed error message
            if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
              if (xhr.responseJSON.data.message) {
                errorMsg = xhr.responseJSON.data.message;
              } else if (typeof xhr.responseJSON.data === "string") {
                errorMsg = xhr.responseJSON.data;
              }
            } else if (xhr && xhr.responseText) {
              // Try to parse responseText
              try {
                var parsed = JSON.parse(xhr.responseText);
                if (parsed.data && parsed.data.message) {
                  errorMsg = parsed.data.message;
                }
              } catch (e) {
                // If not JSON, might be a PHP error
                if (xhr.responseText.length < 500) {
                  errorMsg = xhr.responseText;
                } else {
                  errorMsg = "Server error occurred (check PHP error log)";
                }
              }
            } else if (status) {
              errorMsg = "Request failed: " + status;
            }

            addLog("✗ " + errorMsg, "error");
            console.error("URL fix error:", xhr, status, error);

            $errorMessage.text(errorMsg);
            $error.fadeIn();
            $btn.prop("disabled", false).text("Fix CDN URL Structure");
          },
        );
      }

      // Disable button
      $btn.prop("disabled", true).text("Processing...");

      addLog("Starting CDN URL structure fix...", "info");
      $progressBar.css("width", "10%");
      $progressText.text("Scanning attachments...");

      // Start processing from offset 0
      processBatch(0);
    });
  });
}
