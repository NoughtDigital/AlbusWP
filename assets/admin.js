(function ($) {
  let scanResults = [];
  let previewData = null;
  let upgradeUrl = Albus.upgradeUrl || "#";

  // Make all upgrade links functional
  $(document).on("click", 'a[href="#"]:contains("Upgrade")', function (e) {
    e.preventDefault();
    window.location.href = upgradeUrl;
  });

  // Tab switching
  $(".albus-tab").on("click", function () {
    const tab = $(this).data("tab");
    $(".albus-tab").removeClass("active");
    $(this).addClass("active");
    $(".albus-tab-content").hide();
    $("#tab-" + tab).show();

    if (tab === "backups") {
      loadBackups();
    } else if (tab === "help") {
      updateStats();
    }
  });

  // Update stats
  function updateStats() {
    $.ajax({
      url: Albus.rest + "/backups",
      method: "GET",
      headers: { "X-WP-Nonce": Albus.nonce },
    }).then(function (resp) {
      if (resp.ok) {
        $("#stat-backups").text(resp.count);
        $("#stat-converted").text(resp.count);
      }
    });
  }

  // Row template
  function row(item) {
    var debugBtn =
      '<button class="button debug-raw" data-id="' +
      item.id +
      '" style="background:#ffa500;color:white;">Debug Data</button> ';

    var proBadge = item.requires_pro
      ? ' <span style="background:#ff6b35;color:white;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:bold;margin-left:5px;">PRO</span>'
      : "";

    var targets = ["gutenberg", "wpbakery", "elementor", "bricks"];
    var labels = {
      gutenberg: "Gutenberg",
      wpbakery: "WPBakery",
      elementor: "Elementor",
      bricks: "Bricks",
    };

    // Match albus_is_conversion_allowed() free paths
    var freePaths = {
      wpbakery: ["gutenberg"],
      divi: ["gutenberg"],
      kirki: ["gutenberg"],
      classic: ["gutenberg"],
      gutenberg: ["bricks", "wpbakery"],
    };

    var buttons = "";
    targets.forEach(function (target) {
      if (target === item.source) {
        return; // skip same-source conversion
      }
      var allowedFree =
        freePaths[item.source] &&
        freePaths[item.source].indexOf(target) !== -1;
      var isPro = !Albus.isPro && !allowedFree;
      var label = labels[target];
      var proClass = isPro ? " pro-feature" : "";
      var lock = isPro ? " [PRO]" : "";
      var title = isPro ? ' title="Requires AlbusWP PRO"' : "";
      var primary = target === "bricks" ? " button-primary" : "";

      buttons +=
        '<button class="button preview' +
        proClass +
        '" data-id="' +
        item.id +
        '" data-target="' +
        target +
        '"' +
        title +
        (isPro ? " disabled" : "") +
        ">Preview → " +
        label +
        lock +
        "</button> ";
      buttons +=
        '<button class="button convert' +
        primary +
        proClass +
        '" data-id="' +
        item.id +
        '" data-target="' +
        target +
        '"' +
        title +
        (isPro ? " disabled" : "") +
        ">Draft → " +
        label +
        lock +
        "</button> ";
    });

    return (
      '<div class="albus-card" data-post-id="' +
      item.id +
      '">' +
      "<div><strong>#" +
      item.id +
      "</strong> " +
      item.title +
      ' <span style="opacity:.6">[' +
      item.source +
      "]</span>" +
      proBadge +
      "</div>" +
      '<div class="albus-actions-group">' +
      debugBtn +
      buttons +
      "</div></div>"
    );
  }

  // Scan site
  $("#albus-scan").on("click", function () {
    $("#albus-results").html("<p>Scanning…</p>");
    $("#albus-bulk-actions").hide();

    $.ajax({
      url: Albus.rest + "/scan",
      method: "GET",
      headers: { "X-WP-Nonce": Albus.nonce },
    })
      .then(function (resp) {
        scanResults = resp.items || [];
        var html = '<div class="scan-results">';

        // Show FREE version limits
        if (!resp.is_pro) {
          html +=
            '<div class="albus-info-box" style="margin-bottom:1rem;background:#f0f9ff;border-left:4px solid #3b82f6;">';
          html += '<h3 style="margin-top:0;">AlbusWP FREE Edition</h3>';

          // Scan limit info
          if (resp.is_limited) {
            html +=
              "<p><strong>⚠️ Scan Limit:</strong> Showing first " +
              resp.scan_limit +
              " of " +
              resp.total_posts +
              " total posts.</p>";
          } else {
            html +=
              "<p>Scanned " + resp.scan_limit + " pages (FREE limit).</p>";
          }

          // Conversion limit info
          var remaining = resp.conversions_remaining;
          var used = resp.conversions_used;
          html +=
            "<p><strong>Conversions:</strong> " +
            used +
            " used, <strong>" +
            remaining +
            " remaining</strong></p>";

          if (remaining <= 3 && remaining > 0) {
            html +=
              '<p style="color:#d63638;"><strong>Running low!</strong> Only ' +
              remaining +
              " free conversions left.</p>";
          } else if (remaining === 0) {
            html +=
              '<p style="color:#d63638;"><strong>Limit reached!</strong> Upgrade to PRO for unlimited conversions.</p>';
          }

          // Available conversions
          if (resp.free_count > 0) {
            html +=
              "<p><strong>" +
              resp.free_count +
              "</strong> WPBakery pages available to convert</p>";
          }

          if (resp.pro_count > 0) {
            html +=
              "<p><strong>" +
              resp.pro_count +
              "</strong> pages require PRO (Elementor, Divi, or Bricks output)</p>";
          }

          // PRO features
          html += '<hr style="margin:1rem 0;">';
          html += "<p><strong>Upgrade to PRO for:</strong></p>";
          html += '<ul style="margin:0.5rem 0;">';
          html += "<li><strong>Unlimited</strong> scans & conversions</li>";
          html += "<li>Convert FROM Elementor & Divi</li>";
          html += "<li>Convert TO Bricks Builder</li>";
          html += "<li><strong>Bulk conversion</strong> (one-click)</li>";
          html += "<li>Advanced style mapping</li>";
          html += "<li>Priority support</li>";
          html += "</ul>";
          html +=
            '<p style="margin-top:1rem;"><a href="' +
            upgradeUrl +
            '" class="button button-primary button-large">Upgrade to AlbusWP PRO</a></p>';
          html += "</div>";
        }

        html +=
          "<p>Scanned <strong>" +
          (resp.scanned_count || resp.scan_limit) +
          "</strong> pages. ";
        html += "Found <strong>" + resp.count + "</strong> convertible items";
        if (!resp.is_pro && resp.pro_count > 0) {
          html +=
            " (<strong>" +
            resp.free_count +
            "</strong> free, <strong>" +
            resp.pro_count +
            "</strong> PRO only)";
        }
        html += ".</p>";

        // Show helpful message if nothing found
        if (resp.count === 0) {
          html +=
            '<div class="albus-info-box" style="background:#fff8e5;border-left:4px solid #f0b429;margin-top:1rem;">';
          html +=
            '<h3 style="margin-top:0;">No Page Builder Content Found</h3>';
          html +=
            "<p>The scan didn't find any pages using WPBakery or Elementor. This could mean:</p>";
          html += "<ul>";
          html += "<li>Your pages don't use a page builder</li>";
          html +=
            "<li>Your pages use a different builder (Divi, Oxygen, Beaver Builder, etc.)</li>";
          html += "<li>The pages are using the default WordPress editor</li>";
          html += "</ul>";
          html += "<p><strong>What Albus detects:</strong></p>";
          html += "<ul>";
          html +=
            "<li>✅ <strong>WPBakery</strong> (FREE) - looks for <code>[vc_row]</code> shortcodes</li>";
          html +=
            "<li><strong>Elementor</strong> (PRO) - looks for <code>_elementor_data</code> meta</li>";
          html += "</ul>";
          html +=
            '<p>If you have Elementor pages, <a href="' +
            upgradeUrl +
            '" class="button button-primary">Upgrade to PRO</a> to convert them.</p>';
          html += "</div>";
        }

        resp.items.forEach(function (item) {
          html += row(item);
        });
        html += "</div>";
        $("#albus-results").html(html);

        if (resp.count > 0) {
          $("#albus-bulk-actions").show();

          // Disable bulk buttons for FREE users
          if (!resp.can_bulk) {
            $(
              "#albus-bulk-gutenberg, #albus-bulk-bricks, #albus-bulk-wpbakery, #albus-bulk-elementor"
            )
              .prop("disabled", true)
              .attr("title", "Bulk conversion requires AlbusWP PRO")
              .addClass("pro-feature");
          }
        }
      })
      .fail(function () {
        $("#albus-results").html("<p>Error during scan.</p>");
      });
  });

  // Debug raw builder data
  $("#albus-results").on("click", ".debug-raw", function () {
    var id = $(this).data("id");

    $.ajax({
      url: Albus.rest + "/debug-raw/" + id,
      method: "GET",
      headers: { "X-WP-Nonce": Albus.nonce },
    })
      .then(function (resp) {
        console.log("Raw Debug Data:", resp);

        var infoHtml = "<p><strong>Post ID:</strong> " + resp.post_id + "</p>";
        if (resp.source) {
          infoHtml += "<p><strong>Source:</strong> " + resp.source + "</p>";
        }
        if (typeof resp.meta_exists !== "undefined") {
          infoHtml +=
            "<p><strong>Meta Exists:</strong> " +
            (resp.meta_exists ? "Yes" : "No") +
            "</p>";
        }
        if (resp.data_type) {
          infoHtml +=
            "<p><strong>Data Type:</strong> " + resp.data_type + "</p>";
        }
        if (typeof resp.data_count !== "undefined") {
          infoHtml +=
            "<p><strong>Element Count:</strong> " + resp.data_count + "</p>";
        }
        if (resp.json_error && resp.json_error !== "No error") {
          infoHtml +=
            '<p style="color:red;"><strong>JSON Error:</strong> ' +
            resp.json_error +
            "</p>";
        }

        $(".albus-preview-info").html(infoHtml);
        $(".albus-preview-body code").text(
          JSON.stringify(
            resp.raw_data !== undefined ? resp.raw_data : resp,
            null,
            2
          )
        );
        $("#albus-preview-modal h2").text("Debug: Raw Builder Data");
        $("#albus-confirm-convert").hide();
        $("#albus-preview-modal").fadeIn();
      })
      .fail(function (xhr, status, error) {
        alert("Debug failed: " + error);
      });
  });

  // Handle PRO feature clicks
  $("#albus-results").on("click", ".pro-feature", function (e) {
    e.preventDefault();
    alert(
      "This feature requires AlbusWP PRO.\n\nUnlock all conversion paths between Gutenberg, WPBakery, Elementor, and Bricks.\n\nUpgrade to unlock!"
    );
    return false;
  });

  // Preview conversion
  $("#albus-results").on("click", ".preview:not(.pro-feature)", function () {
    var id = $(this).data("id");
    var target = $(this).data("target");
    var btn = $(this);

    btn.prop("disabled", true).text("Generating preview…");

    $.ajax({
      url: Albus.rest + "/preview",
      method: "POST",
      headers: { "X-WP-Nonce": Albus.nonce },
      data: { post_id: id, target: target },
    })
      .then(function (resp) {
        btn.prop("disabled", false).text("Preview → " + target);

        if (resp.ok) {
          previewData = { post_id: id, target: target };

          var infoHtml =
            "<p><strong>Post ID:</strong> " +
            resp.post_id +
            "</p>" +
            "<p><strong>Source:</strong> " +
            resp.source +
            "</p>" +
            "<p><strong>Target:</strong> " +
            resp.target +
            "</p>" +
            "<p><strong>Elements:</strong> " +
            resp.element_count +
            "</p>";

          $(".albus-preview-info").html(infoHtml);
          $(".albus-preview-body code").text(resp.preview);
          $("#albus-preview-modal").fadeIn();
        } else {
          alert("Preview failed: " + (resp.message || "Unknown error"));
        }
      })
      .fail(function (xhr, status, error) {
        btn.prop("disabled", false).text("Preview → " + target);
        alert("Preview failed: " + error);
      });
  });

  // Close modal
  $(".albus-modal-close, #albus-cancel-preview").on("click", function () {
    $("#albus-preview-modal").fadeOut();
    $("#albus-confirm-convert").show();
    $("#albus-preview-modal h2").text("Preview Conversion");
    previewData = null;
  });

  // Confirm conversion from preview
  $("#albus-confirm-convert").on("click", function () {
    if (!previewData) return;

    $("#albus-preview-modal").fadeOut();
    performConversion(previewData.post_id, previewData.target);
    previewData = null;
  });

  // Direct conversion
  $("#albus-results").on("click", ".convert:not(.pro-feature)", function () {
    var id = $(this).data("id");
    var target = $(this).data("target");
    performConversion(id, target);
  });

  function getConversionMode() {
    if ($("#albus-inplace-mode").is(":checked")) {
      return "inplace";
    }
    return "safe";
  }

  $("#albus-inplace-mode").on("change", function () {
    var inplace = $(this).is(":checked");
    $("#albus-confirm-convert").text(
      inplace ? "Overwrite live page" : "Create safe draft"
    );
    if (inplace) {
      alert(
        "In-place mode is dangerous on live sites.\n\nSafe mode (draft copies) is strongly recommended.\nIn-place requires typing OVERWRITE LIVE for every conversion."
      );
    }
  });

  // Perform single conversion
  function performConversion(id, target) {
    var mode = getConversionMode();
    var confirmInplace = "";

    if (mode === "inplace") {
      confirmInplace = window.prompt(
        "WARNING: This will OVERWRITE the live published page/post.\n\nType OVERWRITE LIVE to continue, or Cancel to abort.\n\nRecommended: uncheck in-place mode and use a safe draft instead."
      );
      if (confirmInplace !== "OVERWRITE LIVE") {
        alert("In-place conversion cancelled. Live page was not changed.");
        return;
      }
    } else {
      if (
        !confirm(
          "Create a DRAFT copy of this page and convert the draft to " +
            target +
            "?\n\nThe live original will NOT be changed."
        )
      ) {
        return;
      }
    }

    var card = $('.albus-card[data-post-id="' + id + '"]');
    var btn = card.find('.convert[data-target="' + target + '"]');

    btn.prop("disabled", true).text("Converting…");

    $.ajax({
      url: Albus.rest + "/convert",
      method: "POST",
      headers: { "X-WP-Nonce": Albus.nonce },
      data: {
        post_id: id,
        target: target,
        mode: mode,
        confirm_inplace: confirmInplace,
      },
    })
      .then(function (resp) {
        console.log("Conversion response:", resp);
        if (resp.ok) {
          btn.text("Done").removeClass("button-primary");

          var successMsg =
            '<div class="albus-success">' +
            "<strong>Success!</strong> " +
            (resp.message || "Conversion complete") +
            "<br>";
          if (resp.details) {
            successMsg += "<small>" + resp.details + "</small><br>";
          }
          if (resp.original_untouched) {
            successMsg +=
              "<small><strong>Live original #" +
              resp.post_id +
              " was not modified.</strong></small><br>";
          }
          if (resp.draft_id) {
            successMsg +=
              "<small>Draft ID: #" + resp.draft_id + "</small><br>";
          }
          if (resp.edit_url) {
            successMsg +=
              '<a href="' +
              resp.edit_url +
              '" target="_blank" style="margin-top:4px;display:inline-block;" class="button button-primary">Edit draft</a> ';
          }
          if (resp.preview_url) {
            successMsg +=
              '<a href="' +
              resp.preview_url +
              '" target="_blank" style="margin-top:4px;display:inline-block;" class="button">Preview draft</a> ';
          }
          if (mode === "inplace") {
            successMsg +=
              '<button class="button restore-post" data-id="' +
              id +
              '" style="margin-top:4px;">Restore live backup</button>';
          }
          successMsg += "</div>";

          card.append(successMsg);

          if (
            resp.conversions_remaining !== undefined &&
            resp.conversions_remaining === 0
          ) {
            var limitMsg =
              '<div class="albus-warning-box" style="margin-top:1rem;">';
            limitMsg += "<strong>Free Limit Reached!</strong>";
            limitMsg +=
              "<p>You've used all free conversions. <a href=\"" +
              upgradeUrl +
              '" class="button button-primary">Upgrade to PRO</a></p>';
            limitMsg += "</div>";
            $("#albus-results").prepend(limitMsg);
          }
        } else {
          btn.text("Failed").css("background", "#dc3545");
          alert("Conversion failed: " + (resp.message || "Unknown error"));
        }
      })
      .fail(function (xhr, status, error) {
        btn.prop("disabled", false).text("Draft → " + target);
        var errorMsg =
          '<div class="albus-error">' +
          "<strong>Network Error</strong><br>" +
          "<small>Check browser console and WordPress debug.log for details.</small>" +
          "</div>";

        card.append(errorMsg);
        alert("Network Error\n\n" + error);
      });
  }

  // Bulk conversion
  function bulkConvert(target) {
    if (scanResults.length === 0) {
      alert("No posts to convert. Please scan first.");
      return;
    }

    // Check if bulk is allowed (will be disabled in UI, but double-check)
    if ($("#albus-bulk-" + target.toLowerCase()).hasClass("pro-feature")) {
      alert(
        "Bulk conversion requires AlbusWP PRO.\n\nUnlimited conversions\nOne-click bulk processing\nPriority support\n\nUpgrade to unlock!"
      );
      return;
    }

    if (
      !confirm(
        "Create DRAFT copies of all " +
          scanResults.length +
          " posts and convert those drafts to " +
          target +
          "?\n\nLive originals will NOT be changed. Bulk never overwrites live pages."
      )
    ) {
      return;
    }

    var postIds = scanResults.map((item) => item.id);
    var total = postIds.length;
    var completed = 0;

    $("#albus-bulk-progress").show();
    $(
      "#albus-bulk-gutenberg, #albus-bulk-bricks, #albus-bulk-wpbakery, #albus-bulk-elementor"
    ).prop("disabled", true);

    function convertNext(index) {
      if (index >= postIds.length) {
        $(".albus-progress-text").text(
          "Complete! Converted " + completed + " of " + total + " posts."
        );
        $(
          "#albus-bulk-gutenberg, #albus-bulk-bricks, #albus-bulk-wpbakery, #albus-bulk-elementor"
        ).prop("disabled", false);
        setTimeout(function () {
          $("#albus-bulk-progress").fadeOut();
        }, 3000);
        return;
      }

      var postId = postIds[index];
      var percent = Math.round((index / total) * 100);

      $(".albus-progress-fill").css("width", percent + "%");
      $(".albus-progress-text").text(
        "Converting post " + (index + 1) + " of " + total + "..."
      );

      $.ajax({
        url: Albus.rest + "/convert",
        method: "POST",
        headers: { "X-WP-Nonce": Albus.nonce },
        data: { post_id: postId, target: target, mode: "safe" },
      }).always(function (resp) {
        if (resp && resp.ok) {
          completed++;
        }

        // Update the card in the UI
        var card = $('.albus-card[data-post-id="' + postId + '"]');
        if (resp && resp.ok) {
          card
            .find('.convert[data-target="' + target + '"]')
            .text("Done")
            .removeClass("button-primary");
        } else {
          card
            .find('.convert[data-target="' + target + '"]')
            .text("Failed")
            .css("background", "#dc3545");
        }

        convertNext(index + 1);
      });
    }

    convertNext(0);
  }

  $("#albus-bulk-gutenberg").on("click", function () {
    bulkConvert("gutenberg");
  });

  $("#albus-bulk-wpbakery").on("click", function () {
    bulkConvert("wpbakery");
  });

  $("#albus-bulk-elementor").on("click", function () {
    bulkConvert("elementor");
  });

  $("#albus-bulk-bricks").on("click", function () {
    bulkConvert("bricks");
  });

  // Restore post
  $(document).on("click", ".restore-post", function () {
    var id = $(this).data("id");

    if (!confirm("Restore post #" + id + " from backup?")) {
      return;
    }

    $(this).prop("disabled", true).text("Restoring…");

    $.ajax({
      url: Albus.rest + "/restore",
      method: "POST",
      headers: { "X-WP-Nonce": Albus.nonce },
      data: { post_id: id },
    })
      .then(function (resp) {
        if (resp.ok) {
          alert("Post restored successfully!");
          location.reload();
        } else {
          alert("Restore failed: " + (resp.message || "Unknown error"));
        }
      })
      .fail(function () {
        alert("Restore failed: Network error");
      });
  });

  // Load backups
  function loadBackups() {
    $("#albus-backups-list").html("<p>Loading backups…</p>");

    $.ajax({
      url: Albus.rest + "/backups",
      method: "GET",
      headers: { "X-WP-Nonce": Albus.nonce },
    })
      .then(function (resp) {
        if (resp.ok && resp.items.length > 0) {
          var html =
            '<div class="backups-header" style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;">';
          html +=
            "<p>Found " +
            resp.count +
            " backup(s). Backups older than 30 days are automatically deleted.</p>";
          html +=
            '<button class="button" id="albus-cleanup-backups">Clean Up Old Backups</button>';
          html += "</div>";

          html += '<div class="backups-list">';
          resp.items.forEach(function (item) {
            html += '<div class="albus-backup-item">';
            html +=
              "<div><strong>#" +
              item.post_id +
              "</strong> " +
              item.title +
              " <span style='opacity:.6'>[" +
              item.post_type +
              "]</span>";

            item.backups.forEach(function (backup) {
              html += ' <span class="albus-backup-badge">' + backup + "</span>";
            });

            if (item.meta) {
              html +=
                "<br><small style='opacity:.7'>Backed up: " +
                item.meta.date +
                " | Source: " +
                (item.meta.source || "unknown") +
                "</small>";
            }

            html += "</div>";
            html +=
              '<button class="button restore-post" data-id="' +
              item.post_id +
              '">Restore</button>';
            html += "</div>";
          });
          html += "</div>";
          $("#albus-backups-list").html(html);
        } else {
          $("#albus-backups-list").html(
            "<p>No backups found. Backups are created automatically when you convert posts.</p>"
          );
        }
      })
      .fail(function () {
        $("#albus-backups-list").html("<p>Error loading backups.</p>");
      });
  }

  $("#albus-refresh-backups").on("click", function () {
    loadBackups();
  });

  // Cleanup old backups
  $(document).on("click", "#albus-cleanup-backups", function () {
    if (!confirm("Delete backups older than 30 days?")) {
      return;
    }

    $(this).prop("disabled", true).text("Cleaning up…");

    $.ajax({
      url: Albus.rest + "/cleanup-backups",
      method: "POST",
      headers: { "X-WP-Nonce": Albus.nonce },
      data: { days: 30 },
    })
      .then(function (resp) {
        alert(resp.message || "Cleanup complete");
        loadBackups();
      })
      .fail(function () {
        alert("Cleanup failed");
      });
  });
})(jQuery);
