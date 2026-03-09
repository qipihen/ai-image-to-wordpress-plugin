(function ($) {
  "use strict";

  function toKB(bytes) {
    var n = Number(bytes || 0);
    return (n / 1024).toFixed(1);
  }

  function escapeHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function insertMediaLibraryButton() {
    if (!window.AIWP_DATA || !AIWP_DATA.isUploadPage) {
      return;
    }

    if (document.getElementById("aiiwp-open-generator")) {
      return;
    }

    var anchor = document.querySelector(".wrap .page-title-action");
    if (!anchor) {
      return;
    }

    var link = document.createElement("a");
    link.id = "aiiwp-open-generator";
    link.className = "page-title-action";
    link.href = AIWP_DATA.generatorUrl;
    link.textContent = "AI Generate";
    anchor.parentNode.insertBefore(link, anchor.nextSibling);
  }

  function showResult(html, type) {
    var $result = $("#aiiwp-result");
    if (!$result.length) {
      return;
    }

    $result
      .removeClass("aiiwp-status-info aiiwp-status-success aiiwp-status-error")
      .addClass("aiiwp-status-" + type)
      .html(html);
  }

  function collectFormData($form) {
    var fields = $form.serializeArray();
    var payload = {
      action: "aiiwp_generate_image",
      nonce: AIWP_DATA.nonce
    };

    fields.forEach(function (item) {
      payload[item.name] = item.value;
    });

    return payload;
  }

  function bindGeneratorForm() {
    var $form = $("#aiiwp-generate-form");
    if (!$form.length) {
      return;
    }

    $form.on("submit", function (event) {
      event.preventDefault();

      var payload = collectFormData($form);
      if (!payload.prompt || !payload.prompt.trim()) {
        showResult("Prompt is required.", "error");
        return;
      }

      showResult(escapeHtml(AIWP_DATA.messages.generating), "info");

      $.ajax({
        url: AIWP_DATA.ajaxUrl,
        method: "POST",
        dataType: "json",
        data: payload
      })
        .done(function (response) {
          if (!response || !response.success) {
            var message = (response && response.data && response.data.message)
              ? response.data.message
              : AIWP_DATA.messages.error;
            showResult(escapeHtml(message), "error");
            return;
          }

          var data = response.data || {};
          var html = [
            "<strong>" + escapeHtml(AIWP_DATA.messages.success) + "</strong>",
            "<p><a href=\"" + escapeHtml(data.url) + "\" target=\"_blank\" rel=\"noopener\">" + escapeHtml(data.url) + "</a></p>",
            "<p><code>Mode: " + escapeHtml(data.mode || "text-to-image") + "</code></p>",
            "<p><code>Media ID: " + escapeHtml(data.attachment_id) + "</code></p>",
            "<p><code>Filename: " + escapeHtml(data.filename) + "</code></p>",
            "<p><code>Alt: " + escapeHtml(data.alt_text) + "</code></p>",
            "<p><code>Size: " + toKB(data.bytes_before) + "KB -> " + toKB(data.bytes_after) + "KB (" + escapeHtml(data.reduction_percent) + "%)</code></p>",
            "<p><button type=\"button\" class=\"button button-secondary\" id=\"aiiwp-copy-url\" data-url=\"" + escapeHtml(data.url) + "\">Copy URL</button></p>"
          ].join("");

          showResult(html, "success");
        })
        .fail(function (xhr) {
          var message = AIWP_DATA.messages.error;
          if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            message = xhr.responseJSON.data.message;
          }
          showResult(escapeHtml(message), "error");
        });
    });

    $(document).on("click", "#aiiwp-copy-url", function () {
      var url = $(this).data("url");
      if (!url) {
        return;
      }
      navigator.clipboard.writeText(String(url)).then(function () {
        $("#aiiwp-copy-url").text("Copied");
      });
    });
  }

  function setSourcePreview(url, id) {
    var $url = $("#aiiwp_source_image_url");
    var $id = $("#aiiwp_source_attachment_id");
    var $preview = $("#aiiwp-source-preview");

    if ($url.length) {
      $url.val(url || "");
    }
    if ($id.length) {
      $id.val(id || "");
    }

    if (!$preview.length) {
      return;
    }

    if (!url) {
      $preview.addClass("is-hidden").html("");
      return;
    }

    $preview.removeClass("is-hidden").html(
      "<img src=\"" + escapeHtml(url) + "\" alt=\"\" />"
    );
  }

  function bindSourcePicker() {
    var $btn = $("#aiiwp-choose-source");
    if (!$btn.length || typeof wp === "undefined" || !wp.media) {
      return;
    }

    var frame = null;

    $btn.on("click", function (event) {
      event.preventDefault();

      if (frame) {
        frame.open();
        return;
      }

      frame = wp.media({
        title: (AIWP_DATA.messages && AIWP_DATA.messages.chooseImage) || "Select source image",
        multiple: false,
        library: { type: "image" },
        button: { text: "Use as source image" }
      });

      frame.on("select", function () {
        var selection = frame.state().get("selection");
        var model = selection.first();
        if (!model) {
          return;
        }
        var json = model.toJSON();
        var id = json.id || "";
        var url = json.url || "";
        setSourcePreview(url, id);
      });

      frame.open();
    });

    $("#aiiwp-clear-source").on("click", function (event) {
      event.preventDefault();
      setSourcePreview("", "");
    });
  }

  $(function () {
    insertMediaLibraryButton();
    bindGeneratorForm();
    bindSourcePicker();
  });
})(jQuery);
