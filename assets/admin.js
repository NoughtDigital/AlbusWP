(function ($) {
  let scanResults = [];
  let previewData = null;
  let canBulk = false;
  let upgradeUrl = Albus.upgradeUrl || "#";

  var TARGET_LABELS = {
    gutenberg: "Gutenberg",
    wpbakery: "WPBakery",
    elementor: "Elementor",
    bricks: "Bricks",
  };

  // Match albus_is_conversion_allowed() free paths
  var FREE_PATHS = {
    wpbakery: ["gutenberg"],
    divi: ["gutenberg"],
    kirki: ["gutenberg"],
    classic: ["gutenberg"],
    gutenberg: ["bricks", "wpbakery"],
  };

  function targetAllowed(source, target) {
    if (Albus.isPro) return true;
    return FREE_PATHS[source] && FREE_PATHS[source].indexOf(target) !== -1;
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // Tab switching
  function switchTab(tab) {
    $(".albus-tab").removeClass("active");
    $('.albus-tab[data-tab="' + tab + '"]').addClass("active");
    $(".albus-tab-content").hide();
    $("#tab-" + tab).show();

    if (tab === "backups") {
      loadBackups();
    } else if (tab === "help") {
      updateStats();
    }
  }

  $(".albus-tab").on("click", function () {
    switchTab($(this).data("tab"));
  });

  $(document).on("click", ".albus-tab-jump", function (e) {
    e.preventDefault();
    switchTab($(this).data("tab"));
  });

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

  function availableTargets(item) {
    return ["gutenberg", "wpbakery", "elementor", "bricks"].filter(function (
      target
    ) {
      return target !== item.source;
    });
  }

  function defaultTarget(item) {
    var targets = availableTargets(item);
    var free = targets.filter(function (t) {
      return targetAllowed(item.source, t);
    });
    if (free.indexOf("bricks") !== -1) return "bricks";
    if (free.length) return free[0];
    return targets[0];
  }

  function row(item) {
    var targets = availableTargets(item);
    var selected = defaultTarget(item);
    var options = targets
      .map(function (target) {
        var isPro = !targetAllowed(item.source, target);
        var label = TARGET_LABELS[target] + (isPro ? " (PRO)" : "");
        return (
          '<option value="' +
          target +
          '"' +
          (target === selected ? " selected" : "") +
          (isPro ? ' data-pro="1"' : "") +
          ">" +
          label +
          "</option>"
        );
      })
      .join("");

    var isSelectedPro = !targetAllowed(item.source, selected);
    var proBadge = item.requires_pro
      ? '<span class="albus-badge albus-badge-pro">PRO</span>'
      : "";

    return (
      '<div class="albus-card" data-post-id="' +
      item.id +
      '" data-source="' +
      escapeHtml(item.source) +
      '">' +
      '<div class="albus-card-meta">' +
      "<strong>#" +
      item.id +
      "</strong> " +
      escapeHtml(item.title) +
      ' <span class="albus-source">[' +
      escapeHtml(item.source) +
      "]</span>" +
      proBadge +
      "</div>" +
      '<div class="albus-card-actions">' +
      '<label class="screen-reader-text" for="albus-target-' +
      item.id +
      '">Convert to</label>' +
      '<select class="albus-target-select" id="albus-target-' +
      item.id +
      '" data-id="' +
      item.id +
      '">' +
      options +
      "</select>" +
      '<button type="button" class="button preview" data-id="' +
      item.id +
      '"' +
      (isSelectedPro ? " disabled" : "") +
      ">Preview</button>" +
      '<button type="button" class="button button-primary convert" data-id="' +
      item.id +
      '"' +
      (isSelectedPro ? " disabled" : "") +
      ">Create draft</button>" +
      '<button type="button" class="button-link debug-raw" data-id="' +
      item.id +
      '">Debug</button>' +
      "</div></div>"
    );
  }

  function syncCardActions(card) {
    var select = card.find(".albus-target-select");
    var option = select.find("option:selected");
    var isPro = option.data("pro") === 1 || option.attr("data-pro") === "1";
    card.find(".preview, .convert").prop("disabled", isPro);
    card.find(".albus-pro-hint").remove();
    if (isPro) {
      card
        .find(".albus-card-actions")
        .append(
          '<span class="albus-pro-hint">Requires <a href="' +
            upgradeUrl +
            '">PRO</a></span>'
        );
    }
  }

  function renderStatusBar(resp) {
    var bar = $("#albus-status-bar");
    if (resp.is_pro) {
      bar.hide().empty();
      return;
    }

    var scanned = resp.scanned_count || resp.count || 0;
    var remaining = resp.conversions_remaining;
    var parts = [];

    if (resp.is_limited) {
      parts.push(
        "Showing first " +
          resp.scan_limit +
          " of " +
          resp.total_posts +
          " posts"
      );
    } else {
      parts.push("Scanned " + scanned + " pages");
    }

    parts.push(remaining + " conversions remaining");

    if (resp.pro_count > 0) {
      parts.push(resp.pro_count + " need PRO");
    }

    var html =
      '<div class="albus-status-inner">' +
      "<span>" +
      parts.join(" · ") +
      "</span>" +
      '<a href="' +
      upgradeUrl +
      '" class="button button-small">Upgrade to PRO</a>' +
      "</div>";

    if (remaining === 0) {
      html +=
        '<p class="albus-status-warn">Free conversion limit reached.</p>';
    } else if (remaining > 0 && remaining <= 3) {
      html +=
        '<p class="albus-status-warn">Only ' +
        remaining +
        " free conversions left.</p>";
    }

    bar.html(html).show();
  }

  // Scan site
  $("#albus-scan").on("click", function () {
    var btn = $(this);
    btn.prop("disabled", true);
    $("#albus-results").html(
      '<p class="albus-scanning"><span class="albus-loading"></span> Scanning…</p>'
    );
    $("#albus-bulk-actions").hide();
    $("#albus-status-bar").hide();

    $.ajax({
      url: Albus.rest + "/scan",
      method: "GET",
      headers: { "X-WP-Nonce": Albus.nonce },
    })
      .then(function (resp) {
        btn.prop("disabled", false);
        scanResults = resp.items || [];
        canBulk = !!resp.can_bulk;
        renderStatusBar(resp);

        var scanned = resp.scanned_count || resp.count || 0;
        var html = '<div class="scan-results">';
        html +=
          '<p class="albus-results-summary">Found <strong>' +
          resp.count +
          "</strong> convertible item" +
          (resp.count === 1 ? "" : "s") +
          " from <strong>" +
          scanned +
          "</strong> scanned page" +
          (scanned === 1 ? "" : "s");
        if (!resp.is_pro && resp.pro_count > 0) {
          html +=
            " (<strong>" +
            resp.free_count +
            "</strong> free, <strong>" +
            resp.pro_count +
            "</strong> PRO)";
        }
        html += ".</p>";

        if (resp.count === 0) {
          html +=
            '<div class="albus-empty">' +
            "<h3>No page builder content found</h3>" +
            "<p>Albus looks for Gutenberg, WPBakery, Elementor, Bricks, Divi, and Classic content. Check the Help tab for details.</p>" +
            "</div>";
        } else {
          resp.items.forEach(function (item) {
            html += row(item);
          });
        }

        html += "</div>";
        $("#albus-results").html(html);

        if (resp.count > 0) {
          $("#albus-bulk-actions").show();
          $("#albus-bulk-run").prop("disabled", !canBulk);
          $("#albus-bulk-target").prop("disabled", !canBulk);
          if (canBulk) {
            $("#albus-bulk-lock").hide();
          } else {
            $("#albus-bulk-lock").show();
          }
        }
      })
      .fail(function () {
        btn.prop("disabled", false);
        $("#albus-results").html(
          '<p class="albus-error">Scan failed. Check your connection and try again.</p>'
        );
      });
  });

  $("#albus-results").on("change", ".albus-target-select", function () {
    syncCardActions($(this).closest(".albus-card"));
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
            '<p class="albus-status-warn"><strong>JSON Error:</strong> ' +
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

  function getSelectedTarget(card) {
    return card.find(".albus-target-select").val();
  }

  // Preview conversion
  $("#albus-results").on("click", ".preview", function () {
    var btn = $(this);
    if (btn.prop("disabled")) return;

    var card = btn.closest(".albus-card");
    var id = btn.data("id");
    var target = getSelectedTarget(card);
    var option = card.find(".albus-target-select option:selected");
    if (option.data("pro") === 1 || option.attr("data-pro") === "1") {
      alert(
        "This conversion path requires AlbusWP PRO.\n\nUpgrade to unlock all builders."
      );
      return;
    }

    btn.prop("disabled", true).text("Generating…");

    $.ajax({
      url: Albus.rest + "/preview",
      method: "POST",
      headers: { "X-WP-Nonce": Albus.nonce },
      data: { post_id: id, target: target },
    })
      .then(function (resp) {
        btn.prop("disabled", false).text("Preview");

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
          $("#albus-preview-modal h2").text("Preview Conversion");
          $("#albus-confirm-convert").show();
          $("#albus-preview-modal").fadeIn();
        } else {
          alert("Preview failed: " + (resp.message || "Unknown error"));
        }
      })
      .fail(function (xhr, status, error) {
        btn.prop("disabled", false).text("Preview");
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
  $("#albus-results").on("click", ".convert", function () {
    var btn = $(this);
    if (btn.prop("disabled")) return;

    var card = btn.closest(".albus-card");
    var id = btn.data("id");
    var target = getSelectedTarget(card);
    var option = card.find(".albus-target-select option:selected");
    if (option.data("pro") === 1 || option.attr("data-pro") === "1") {
      alert(
        "This conversion path requires AlbusWP PRO.\n\nUpgrade to unlock all builders."
      );
      return;
    }
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
            (TARGET_LABELS[target] || target) +
            "?\n\nThe live original will NOT be changed."
        )
      ) {
        return;
      }
    }

    var card = $('.albus-card[data-post-id="' + id + '"]');
    var btn = card.find(".convert");

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
              '" target="_blank" class="button button-primary">Edit draft</a> ';
          }
          if (resp.preview_url) {
            successMsg +=
              '<a href="' +
              resp.preview_url +
              '" target="_blank" class="button">Preview draft</a> ';
          }
          if (mode === "inplace") {
            successMsg +=
              '<button class="button restore-post" data-id="' +
              id +
              '">Restore live backup</button>';
          }
          successMsg += "</div>";

          card.append(successMsg);

          if (
            resp.conversions_remaining !== undefined &&
            resp.conversions_remaining === 0
          ) {
            $("#albus-status-bar")
              .html(
                '<div class="albus-status-inner">' +
                  "<span>Free conversion limit reached.</span>" +
                  '<a href="' +
                  upgradeUrl +
                  '" class="button button-small">Upgrade to PRO</a>' +
                  "</div>"
              )
              .show();
          }
        } else {
          btn.prop("disabled", false).text("Create draft");
          alert("Conversion failed: " + (resp.message || "Unknown error"));
        }
      })
      .fail(function (xhr, status, error) {
        btn.prop("disabled", false).text("Create draft");
        card.append(
          '<div class="albus-error"><strong>Network Error</strong><br><small>Check browser console and WordPress debug.log for details.</small></div>'
        );
        alert("Network Error\n\n" + error);
      });
  }

  function bulkConvert(target) {
    if (scanResults.length === 0) {
      alert("No posts to convert. Please scan first.");
      return;
    }

    if (!canBulk) {
      alert(
        "Bulk conversion requires AlbusWP PRO.\n\nUpgrade to unlock unlimited conversions and one-click bulk processing."
      );
      return;
    }

    if (
      !confirm(
        "Create DRAFT copies of all " +
          scanResults.length +
          " posts and convert those drafts to " +
          (TARGET_LABELS[target] || target) +
          "?\n\nLive originals will NOT be changed. Bulk never overwrites live pages."
      )
    ) {
      return;
    }

    var postIds = scanResults.map(function (item) {
      return item.id;
    });
    var total = postIds.length;
    var completed = 0;

    $("#albus-bulk-progress").show();
    $("#albus-bulk-run, #albus-bulk-target").prop("disabled", true);

    function convertNext(index) {
      if (index >= postIds.length) {
        $(".albus-progress-text").text(
          "Complete! Converted " + completed + " of " + total + " posts."
        );
        $("#albus-bulk-run, #albus-bulk-target").prop("disabled", !canBulk);
        setTimeout(function () {
          $("#albus-bulk-progress").fadeOut();
        }, 3000);
        return;
      }

      var postId = postIds[index];
      var percent = Math.round((index / total) * 100);

      $(".albus-progress-fill").css("width", percent + "%");
      $(".albus-progress-text").text(
        "Converting post " + (index + 1) + " of " + total + "…"
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

        var card = $('.albus-card[data-post-id="' + postId + '"]');
        var convertBtn = card.find(".convert");
        if (resp && resp.ok) {
          convertBtn.text("Done").removeClass("button-primary");
        } else {
          convertBtn.text("Failed").css("background", "#dc3545");
        }

        convertNext(index + 1);
      });
    }

    convertNext(0);
  }

  $("#albus-bulk-run").on("click", function () {
    bulkConvert($("#albus-bulk-target").val());
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
            '<div class="backups-header">' +
            "<p>Found " +
            resp.count +
            " backup(s). Backups older than 30 days are automatically deleted.</p>" +
            '<button class="button" id="albus-cleanup-backups">Clean Up Old Backups</button>' +
            "</div>";

          html += '<div class="backups-list">';
          resp.items.forEach(function (item) {
            html += '<div class="albus-backup-item">';
            html +=
              "<div><strong>#" +
              item.post_id +
              "</strong> " +
              escapeHtml(item.title) +
              ' <span class="albus-source">[' +
              escapeHtml(item.post_type) +
              "]</span>";

            item.backups.forEach(function (backup) {
              html +=
                ' <span class="albus-backup-badge">' +
                escapeHtml(backup) +
                "</span>";
            });

            if (item.meta) {
              html +=
                '<br><small class="albus-muted">Backed up: ' +
                escapeHtml(item.meta.date) +
                " | Source: " +
                escapeHtml(item.meta.source || "unknown") +
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
