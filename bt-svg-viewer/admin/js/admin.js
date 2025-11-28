(function ($) {
  function getI18n(key, fallback) {
    if (
      window.btsvviewerAdmin &&
      btsvviewerAdmin.i18n &&
      btsvviewerAdmin.i18n[key]
    ) {
      return btsvviewerAdmin.i18n[key];
    }
    return fallback;
  }

  function updateStatus($status, message) {
    if (!$status || !$status.length) {
      return;
    }
    $status.text(message);
    const existingTimeout = $status.data("svgTimeoutId");
    if (existingTimeout) {
      clearTimeout(existingTimeout);
    }
    const timeoutId = setTimeout(function () {
      $status.text("");
      $status.removeData("svgTimeoutId");
    }, 4000);
    $status.data("svgTimeoutId", timeoutId);
  }

  async function copyShortcode(shortcode, $status, successKey = "copySuccess") {
    if (!shortcode) {
      return;
    }

    const fallbackSuccess =
      successKey === "fullCopySuccess"
        ? "Full shortcode copied to clipboard."
        : "Shortcode copied to clipboard.";

    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(shortcode);
      } else {
        const $temp = $("<textarea>");
        $temp.val(shortcode).css({ position: "absolute", left: "-9999px" });
        $("body").append($temp);
        $temp[0].select();
        document.execCommand("copy");
        $temp.remove();
      }
      updateStatus($status, getI18n(successKey, fallbackSuccess));
    } catch (err) {
      console.warn("Shortcode copy failed", err);
      updateStatus(
        $status,
        getI18n("copyFailed", "Press ⌘/Ctrl+C to copy the shortcode.")
      );
    }
  }

  function getFormDefaults() {
    if (
      window.btsvviewerAdmin &&
      btsvviewerAdmin.formDefaults &&
      typeof btsvviewerAdmin.formDefaults === "object"
    ) {
      return btsvviewerAdmin.formDefaults;
    }
    return {};
  }

  function mapFieldKeyToAttribute(key) {
    if (!key) {
      return null;
    }
    if (key === "preset_nonce") {
      return null;
    }
    if (key === "attachment_id") {
      return null;
    }
    if (key === "initial_zoom") {
      return "zoom";
    }
    return key;
  }

  function getFieldControlValue($field) {
    if (!$field || !$field.length) {
      return "";
    }
    const rawValue = $field.val();
    if (Array.isArray(rawValue)) {
      return rawValue
        .map(function (item) {
          return item == null ? "" : String(item);
        })
        .filter(function (item) {
          return item !== "";
        })
        .join(",");
    }
    if (rawValue == null) {
      return "";
    }
    return typeof rawValue === "string" ? rawValue : String(rawValue);
  }

  function normalizeForComparison(value) {
    if (value == null) {
      return "";
    }
    return String(value).trim();
  }

  function escapeShortcodeValue(value) {
    return String(value)
      .replace(/\\/g, "\\\\")
      .replace(/\r?\n/g, "&#10;")
      .replace(/"/g, '\\"')
      .replace(/\[/g, "\\[")
      .replace(/\]/g, "\\]");
  }

  function buildFullShortcode($metaBox) {
    if (!$metaBox || !$metaBox.length) {
      return "";
    }

    const defaults = getFormDefaults();
    const values = {};
    const order = [];
    const seenKeys = new Set();

    $metaBox.find("[name^='btsvviewer_']").each(function () {
      const $field = $(this);
      const name = $field.attr("name");
      if (!name) {
        return;
      }
      const baseKey = name.replace(/^btsvviewer_/, "");
      if (!baseKey || seenKeys.has(baseKey)) {
        return;
      }
      seenKeys.add(baseKey);

      const attrName = mapFieldKeyToAttribute(baseKey);
      if (!attrName) {
        return;
      }

      const rawValue = getFieldControlValue($field);
      const comparisonValue = normalizeForComparison(rawValue);
      const defaultValue = Object.prototype.hasOwnProperty.call(
        defaults,
        baseKey
      )
        ? normalizeForComparison(defaults[baseKey])
        : undefined;

      if (
        comparisonValue === "" &&
        (typeof defaultValue === "undefined" || defaultValue === "")
      ) {
        if (attrName !== "src") {
          return;
        }
      }

      if (
        typeof defaultValue !== "undefined" &&
        comparisonValue === defaultValue &&
        attrName !== "src"
      ) {
        return;
      }

      if (attrName === "src" && comparisonValue === "") {
        return;
      }

      const finalValue =
        typeof rawValue === "string" ? rawValue.trim() : rawValue;
      values[attrName] = finalValue;
      order.push(attrName);
    });

    if (!values.src) {
      return "";
    }

    const parts = order
      .filter(function (attr) {
        return (
          Object.prototype.hasOwnProperty.call(values, attr) &&
          values[attr] !== "" &&
          values[attr] !== null &&
          typeof values[attr] !== "undefined"
        );
      })
      .map(function (attr) {
        return attr + '="' + escapeShortcodeValue(values[attr]) + '"';
      });

    if (!parts.length) {
      return "";
    }

    return "[btsvviewer " + parts.join(" ") + "]";
  }

  function initTabs($root) {
    $root.find(".bt-svg-viewer-tabs").each(function () {
      const $container = $(this);
      const $buttons = $container.find(".bt-svg-viewer-tab-button");
      const $panels = $container.find(".bt-svg-viewer-tab-panel");

      function activateTab(target) {
        $buttons.each(function () {
          const $button = $(this);
          const isTarget = $button.data("tabTarget") === target;
          $button.toggleClass("is-active", isTarget);
          $button.attr("aria-selected", isTarget ? "true" : "false");
        });

        $panels.each(function () {
          const $panel = $(this);
          const isTarget = $panel.data("tabPanel") === target;
          $panel.toggleClass("is-active", isTarget);
          $panel.attr("aria-hidden", isTarget ? "false" : "true");
        });
      }

      function handleActivation(event) {
        if (
          event.type === "keydown" &&
          event.key !== "Enter" &&
          event.key !== " "
        ) {
          return;
        }

        event.preventDefault();
        const target = $(this).data("tabTarget");
        if (target) {
          activateTab(target);
        }
      }

      $buttons.on("click", handleActivation);
      $buttons.on("keydown", handleActivation);

      const $initial = $buttons.filter(".is-active").first();
      const defaultTarget = $initial.length
        ? $initial.data("tabTarget")
        : $buttons.first().data("tabTarget");
      if (defaultTarget) {
        activateTab(defaultTarget);
      }
    });
  }

  const DEFAULT_BUTTON_DEFS = {
    zoom_in: {
      class: "zoom-in-btn",
      icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM272 176C258.7 176 248 186.7 248 200L248 248L200 248C186.7 248 176 258.7 176 272C176 285.3 186.7 296 200 296L248 296L248 344C248 357.3 258.7 368 272 368C285.3 368 296 357.3 296 344L296 296L344 296C357.3 296 368 285.3 368 272C368 258.7 357.3 248 344 248L296 248L296 200C296 186.7 285.3 176 272 176z"/></svg>',
      text: "Zoom In",
      title: "Zoom In (Ctrl +)",
      requires_show_coords: false,
    },
    zoom_out: {
      class: "zoom-out-btn",
      icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM200 248C186.7 248 176 258.7 176 272C176 285.3 186.7 296 200 296L344 296C357.3 296 368 285.3 368 272C368 258.7 357.3 248 344 248L200 248z"/></svg>',
      text: "Zoom Out",
      title: "Zoom Out (Ctrl -)",
      requires_show_coords: false,
    },
    reset: {
      class: "reset-zoom-btn",
      icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM272 416C351.5 416 416 351.5 416 272C416 192.5 351.5 128 272 128C192.5 128 128 192.5 128 272C128 351.5 192.5 416 272 416z"/></svg>',
      text: "Reset Zoom",
      title: "Reset Zoom",
      requires_show_coords: false,
    },
    center: {
      class: "center-view-btn",
      icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M320 48C337.7 48 352 62.3 352 80L352 98.3C450.1 112.3 527.7 189.9 541.7 288L560 288C577.7 288 592 302.3 592 320C592 337.7 577.7 352 560 352L541.7 352C527.7 450.1 450.1 527.7 352 541.7L352 560C352 577.7 337.7 592 320 592C302.3 592 288 577.7 288 560L288 541.7C189.9 527.7 112.3 450.1 98.3 352L80 352C62.3 352 48 337.7 48 320C48 302.3 62.3 288 80 288L98.3 288C112.3 189.9 189.9 112.3 288 98.3L288 80C288 62.3 302.3 48 320 48zM163.2 352C175.9 414.7 225.3 464.1 288 476.8L288 464C288 446.3 302.3 432 320 432C337.7 432 352 446.3 352 464L352 476.8C414.7 464.1 464.1 414.7 476.8 352L464 352C446.3 352 432 337.7 432 320C432 302.3 446.3 288 464 288L476.8 288C464.1 225.3 414.7 175.9 352 163.2L352 176C352 193.7 337.7 208 320 208C302.3 208 288 193.7 288 176L288 163.2C225.3 175.9 175.9 225.3 163.2 288L176 288C193.7 288 208 302.3 208 320C208 337.7 193.7 352 176 352L163.2 352zM320 272C346.5 272 368 293.5 368 320C368 346.5 346.5 368 320 368C293.5 368 272 346.5 272 320C272 293.5 293.5 272 320 272z"/></svg>',
      text: "Center View",
      title: "Center View",
      requires_show_coords: false,
    },
    coords: {
      class: "coord-copy-btn",
      icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512" aria-hidden="true" focusable="false"><path fill="currentColor" d="M192 0C86 0 0 86 0 192C0 293.5 176.2 499.4 184.8 509.2C188.5 513.6 194 516 200 516C206 516 211.5 513.6 215.2 509.2C223.8 499.4 400 293.5 400 192C400 86 314 0 208 0C146.1 0 92.4 37.7 69.3 94.3C65 104.6 53.7 109.4 43.4 105.1C33.1 100.8 28.3 89.4 32.6 79.1C60.8 10.4 129.8-32 208-32C329.3-32 416 54.7 416 176C416 298.5 215.4 507.7 205.7 518.5C201.2 523.8 194.8 526.8 188 526.8C181.3 526.8 174.9 523.8 170.3 518.5C160.6 507.7-40 298.5-40 176C-40 54.7 46.7-32 168-32C246.3-32 315.2 10.4 343.4 79.1C347.7 89.4 342.9 100.8 332.6 105.1C322.3 109.4 311 104.6 306.7 94.3C283.6 37.7 229.9 0 168 0z"/></svg>',
      text: "Copy Center",
      title: "Copy current center coordinates",
      requires_show_coords: true,
    },
  };

  const BUTTON_DEFS =
    (window.btsvviewerAdmin &&
      btsvviewerAdmin.controls &&
      btsvviewerAdmin.controls.buttons) ||
    DEFAULT_BUTTON_DEFS;

  const MODE_OPTIONS = ["icon", "text", "both"];
  const STYLE_OPTIONS = ["compact", "labels-on-hover"];
  const HIDDEN_OPTIONS = ["hidden", "none"];
  const ALIGNMENT_OPTIONS = ["alignleft", "aligncenter", "alignright"];
  const AVAILABLE_BUTTONS = [
    "zoom_in",
    "zoom_out",
    "reset",
    "center",
    "coords",
  ];

  function getFieldValue($context, selector, fallback) {
    const $field = $context.find(selector);
    if (!$field.length) {
      return fallback;
    }
    const value = $field.val();
    if (value === "" || value === null || typeof value === "undefined") {
      return fallback;
    }
    return value;
  }

  function parsePercentage(value, defaultValue) {
    const num = parseFloat(value);
    if (!isFinite(num)) {
      return defaultValue;
    }
    return Math.max(0.01, num) / 100;
  }

  function parseFloatOrNull(value) {
    if (value === "" || value === null || typeof value === "undefined") {
      return null;
    }
    const num = parseFloat(value);
    return isFinite(num) ? num : null;
  }

  function normalizePanMode(value) {
    const raw = typeof value === "string" ? value.trim().toLowerCase() : "";
    return raw === "drag" ? "drag" : "scroll";
  }

  function normalizeZoomMode(value) {
    const raw = typeof value === "string" ? value.trim().toLowerCase() : "";
    if (raw === "click") {
      return "click";
    }
    if (raw === "scroll") {
      return "scroll";
    }
    return "super_scroll";
  }

  function resolveInteractionConfig(panInput, zoomInput) {
    let panMode = normalizePanMode(panInput);
    const zoomMode = normalizeZoomMode(zoomInput);
    const messages = [];

    if (zoomMode === "scroll" && panMode === "scroll") {
      panMode = "drag";
    }

    if (zoomMode === "click") {
      messages.push("Cmd/Ctrl-click to zoom in, Option/Alt-click to zoom out.");
    } else if (zoomMode === "scroll") {
      messages.push("Scroll up to zoom in, scroll down to zoom out.");
    }

    if (panMode === "drag") {
      if (zoomMode === "scroll") {
        messages.push("Drag to pan around the image while scrolling zooms.");
      } else {
        messages.push("Drag to pan around the image.");
      }
    }

    const deduped = Array.from(new Set(messages.filter(Boolean)));

    return {
      panMode,
      zoomMode,
      messages: deduped,
    };
  }

  function removeInteractionClasses($element) {
    if (!$element || !$element.length) {
      return;
    }
    const classList = ($element.attr("class") || "").split(/\s+/);
    classList.forEach((cls) => {
      if (
        cls &&
        (cls.indexOf("pan-mode-") === 0 || cls.indexOf("zoom-mode-") === 0)
      ) {
        $element.removeClass(cls);
      }
    });
  }

  function expandShorthandHex(hex) {
    if (!hex || hex.length !== 4) {
      return hex;
    }
    return (
      "#" +
      hex[1] +
      hex[1] +
      hex[2] +
      hex[2] +
      hex[3] +
      hex[3]
    ).toLowerCase();
  }

  function sanitizeHexColor(value) {
    if (typeof value !== "string") {
      return "";
    }
    let color = value.trim();
    if (!color) {
      return "";
    }
    if (color[0] !== "#") {
      color = "#" + color;
    }
    if (/^#[0-9a-fA-F]{3}$/.test(color)) {
      return expandShorthandHex(color);
    }
    if (/^#[0-9a-fA-F]{6}$/.test(color)) {
      return color.toLowerCase();
    }
    return "";
  }

  function adjustHexBrightness(hex, percentage) {
    const sanitized = sanitizeHexColor(hex);
    if (!sanitized) {
      return "";
    }
    const clamped = Math.max(-100, Math.min(100, percentage));
    const r = parseInt(sanitized.slice(1, 3), 16);
    const g = parseInt(sanitized.slice(3, 5), 16);
    const b = parseInt(sanitized.slice(5, 7), 16);

    function adjust(component) {
      let value = component;
      if (clamped >= 0) {
        value += (255 - component) * (clamped / 100);
      } else {
        value += component * (clamped / 100);
      }
      return Math.max(0, Math.min(255, Math.round(value)));
    }

    const result =
      "#" +
      adjust(r).toString(16).padStart(2, "0") +
      adjust(g).toString(16).padStart(2, "0") +
      adjust(b).toString(16).padStart(2, "0");

    return result.toLowerCase();
  }

  function applyButtonColors($wrapper, fill, border, foreground) {
    if (!$wrapper || !$wrapper.length) {
      return;
    }
    const props = [
      "--bt-svg-viewer-button-fill",
      "--bt-svg-viewer-button-hover",
      "--bt-svg-viewer-button-border",
      "--bt-svg-viewer-button-text",
    ];
    const sanitizedFill = sanitizeHexColor(fill);
    const sanitizedBorder = sanitizeHexColor(border);
    const sanitizedForeground = sanitizeHexColor(foreground);

    $wrapper.each(function () {
      const element = this;
      if (!element || !element.style) {
        return;
      }

      props.forEach((prop) => {
        element.style.removeProperty(prop);
      });

      if (sanitizedFill) {
        element.style.setProperty("--bt-svg-viewer-button-fill", sanitizedFill);
        const hover = adjustHexBrightness(sanitizedFill, -12);
        if (hover) {
          element.style.setProperty("--bt-svg-viewer-button-hover", hover);
        }
      }

      let effectiveBorder = sanitizedBorder;
      if (!effectiveBorder && sanitizedFill) {
        effectiveBorder = sanitizedFill;
      }

      if (effectiveBorder) {
        element.style.setProperty(
          "--bt-svg-viewer-button-border",
          effectiveBorder
        );
      }

      if (sanitizedForeground) {
        element.style.setProperty(
          "--bt-svg-viewer-button-text",
          sanitizedForeground
        );
      }
    });
  }

  function setViewerText($element, value) {
    if (!value) {
      $element.attr("hidden", true).empty();
      return;
    }
    $element.removeAttr("hidden").html(value);
  }

  function removeControlClasses($element) {
    const classes = ($element.attr("class") || "").split(/\s+/);
    classes.forEach((cls) => {
      if (cls && cls.indexOf("controls-") === 0) {
        $element.removeClass(cls);
      }
    });
  }

  function ensureMainWrapper($wrapper, viewerId) {
    let $main = $wrapper.find(".bt-svg-viewer-main");
    if ($main.length) {
      return $main;
    }

    const $container = $wrapper
      .find('.svg-container[data-viewer="' + viewerId + '"]')
      .first();
    if (!$container.length) {
      return $();
    }

    $container.wrap(
      '<div class="bt-svg-viewer-main controls-position-top controls-mode-both"></div>'
    );
    $main = $wrapper.find(".bt-svg-viewer-main");
    return $main;
  }

  function parseControlsConfig(positionValue, buttonsValue, showCoords) {
    let position = (positionValue || "top").toLowerCase();
    if (!["top", "bottom", "left", "right"].includes(position)) {
      position = "top";
    }

    const rawValue = (buttonsValue || "both").trim();
    const normalized = rawValue.toLowerCase();

    const tokenizedValue = normalized.replace(/:/g, ",");
    const tokens = tokenizedValue
      .split(",")
      .map((token) => token.trim())
      .filter(Boolean);

    let hasSlider = tokens.includes("slider");
    const sliderExplicitZoomIn = tokens.includes("zoom_in");
    const sliderExplicitZoomOut = tokens.includes("zoom_out");

    let mode = "both";
    let styles = [];
    let alignment = "left";
    let buttons = ["zoom_in", "zoom_out", "reset", "center"];
    if (showCoords) {
      buttons.push("coords");
    }
    const defaultButtons = buttons.slice();
    const defaultButtonsWithoutZoom = defaultButtons.filter(
      (button) => button !== "zoom_in" && button !== "zoom_out"
    );

    let processedRawValue = rawValue.replace(/^custom\s*:/i, "custom,");
    let isCustom = false;

    if (HIDDEN_OPTIONS.includes(normalized)) {
      mode = "hidden";
      buttons = [];
    } else if (normalized === "minimal") {
      buttons = ["zoom_in", "zoom_out", "center"];
      if (showCoords) {
        buttons.push("coords");
      }
    } else if (normalized === "slider") {
      hasSlider = true;
      buttons = defaultButtonsWithoutZoom.slice();
    } else if (STYLE_OPTIONS.includes(normalized.replace("_", "-"))) {
      styles.push(normalized.replace("_", "-"));
    } else if (ALIGNMENT_OPTIONS.includes(normalized)) {
      alignment = normalized;
    } else if (MODE_OPTIONS.includes(normalized)) {
      mode = normalized;
    } else if (
      normalized === "custom" ||
      processedRawValue.indexOf(",") !== -1
    ) {
      isCustom = true;
    }

    if (isCustom) {
      let parts = processedRawValue
        .replace(/:/g, ",")
        .split(",")
        .map((part) => part.trim())
        .filter(Boolean);

      if (parts.length && parts[0].toLowerCase() === "custom") {
        parts.shift();
      }

      parts = parts.filter((part) => {
        if (part.toLowerCase() === "slider") {
          hasSlider = true;
          return false;
        }
        return part !== "";
      });

      let customMode = null;
      let customStyles = [];

      if (parts.length) {
        let first = parts[0].toLowerCase();

        if (HIDDEN_OPTIONS.includes(first)) {
          mode = "hidden";
          buttons = [];
          parts = [];
        } else {
          if (MODE_OPTIONS.includes(first)) {
            customMode = first;
            parts.shift();
          } else if (STYLE_OPTIONS.includes(first.replace("_", "-"))) {
            customStyles.push(first.replace("_", "-"));
            parts.shift();
          } else if (ALIGNMENT_OPTIONS.includes(first)) {
            alignment = first;
            parts.shift();
          } else if (first === "minimal") {
            buttons = ["zoom_in", "zoom_out", "center"];
            if (showCoords) {
              buttons.push("coords");
            }
            parts.shift();
          }

          if (parts.length) {
            const maybeMode = parts[0].toLowerCase();
            if (customMode === null && MODE_OPTIONS.includes(maybeMode)) {
              customMode = maybeMode;
              parts.shift();
            } else if (ALIGNMENT_OPTIONS.includes(maybeMode)) {
              alignment = maybeMode;
              parts.shift();
            }
          }

          if (parts.length) {
            const maybeStyle = parts[0].toLowerCase();
            const normalizedStyle = maybeStyle.replace("_", "-");
            if (STYLE_OPTIONS.includes(normalizedStyle)) {
              customStyles.push(normalizedStyle);
              parts.shift();
            }
          }
        }
      }

      if (mode !== "hidden") {
        const customButtons = [];
        parts.forEach((part) => {
          const key = part.toLowerCase();
          if (!key) {
            return;
          }
          if (key === "coords" && !showCoords) {
            return;
          }
          if (
            AVAILABLE_BUTTONS.includes(key) &&
            customButtons.indexOf(key) === -1
          ) {
            customButtons.push(key);
          }
        });

        if (customButtons.length) {
          buttons = customButtons;
        } else if (hasSlider) {
          buttons = defaultButtonsWithoutZoom.slice();
        } else {
          buttons = defaultButtons.slice();
        }
      }

      if (customMode) {
        mode = customMode;
      }

      if (customStyles.length) {
        styles = styles.concat(customStyles);
      }
    }

    if (mode !== "hidden") {
      if (!showCoords) {
        buttons = buttons.filter((button) => button !== "coords");
      } else if (
        tokenizedValue.indexOf(",") === -1 &&
        normalized !== "minimal" &&
        normalized !== "custom" &&
        buttons.indexOf("coords") === -1
      ) {
        buttons.push("coords");
      }

      if (!buttons.length) {
        buttons = hasSlider
          ? defaultButtonsWithoutZoom.slice()
          : defaultButtons.slice();
      }
    } else {
      buttons = [];
    }

    styles = styles
      .map((style) => style.replace("_", "-").toLowerCase())
      .filter(Boolean);
    styles = Array.from(new Set(styles));

    if (hasSlider && !sliderExplicitZoomIn) {
      buttons = buttons.filter((button) => button !== "zoom_in");
    }

    if (hasSlider && !sliderExplicitZoomOut) {
      buttons = buttons.filter((button) => button !== "zoom_out");
    }

    if (hasSlider && !buttons.length) {
      buttons = defaultButtonsWithoutZoom.slice();
    }

    return {
      position,
      mode,
      styles,
      alignment,
      buttons,
      hasSlider,
    };
  }

  function applyControlsConfig(
    $wrapper,
    config,
    viewerId,
    initialZoomPercent,
    showCoords,
    minZoomPercent,
    maxZoomPercent,
    zoomStepPercent
  ) {
    const $main = ensureMainWrapper($wrapper, viewerId);
    if (!$main.length) {
      return;
    }

    const $existingControls = $main.find(".svg-controls");
    $existingControls.remove();

    removeControlClasses($wrapper);
    removeControlClasses($main);

    $wrapper
      .addClass("controls-position-" + config.position)
      .addClass("controls-mode-" + config.mode);
    if (config.alignment) {
      $wrapper.addClass("controls-align-" + config.alignment);
    }
    $main
      .addClass("controls-position-" + config.position)
      .addClass("controls-mode-" + config.mode);
    if (config.alignment) {
      $main.addClass("controls-align-" + config.alignment);
    }

    config.styles.forEach((style) => {
      const className = "controls-style-" + style;
      $wrapper.addClass(className);
      $main.addClass(className);
    });

    if (config.mode === "hidden") {
      $wrapper.addClass("controls-hidden");
      return;
    }

    $wrapper.removeClass("controls-hidden");

    const buttonsToRender = config.buttons.length
      ? config.buttons
      : ["zoom_in", "zoom_out", "reset", "center"];

    const $controls = $("<div/>", {
      class: "svg-controls controls-mode-" + config.mode,
      "data-viewer": viewerId,
    });
    if (config.alignment) {
      $controls.addClass("controls-align-" + config.alignment);
    }

    if (config.position === "left" || config.position === "right") {
      $controls.addClass("controls-vertical");
    }

    config.styles.forEach((style) => {
      $controls.addClass("controls-style-" + style);
    });

    let hasCoords = false;

    if (config.hasSlider) {
      const sliderWrapper = $("<div/>", { class: "zoom-slider-wrapper" });
      const parsedMin = parseInt(minZoomPercent, 10);
      const parsedMax = parseInt(maxZoomPercent, 10);
      const parsedStep = parseInt(zoomStepPercent, 10);
      const sliderMin = Number.isFinite(parsedMin) ? parsedMin : 1;
      const sliderMax = Number.isFinite(parsedMax) ? parsedMax : 800;
      const sliderStep = Math.max(
        1,
        Number.isFinite(parsedStep) ? parsedStep : 1
      );
      const $slider = $("<input/>", {
        type: "range",
        class: "zoom-slider",
        "data-viewer": viewerId,
        min: sliderMin,
        max: sliderMax,
        step: sliderStep,
        value: initialZoomPercent,
        "aria-label": "Zoom level",
        "aria-valuemin": sliderMin,
        "aria-valuemax": sliderMax,
        "aria-valuenow": initialZoomPercent,
      });
      sliderWrapper.append($slider);
      $controls.append(sliderWrapper);
    }

    buttonsToRender.forEach((buttonKey) => {
      const definition = BUTTON_DEFS[buttonKey];
      if (!definition) {
        return;
      }
      if (definition.requires_show_coords && !showCoords) {
        return;
      }

      if (buttonKey === "coords") {
        hasCoords = true;
      }

      const $btn = $("<button/>", {
        type: "button",
        class: "bt-svg-viewer-btn " + definition.class,
        "data-viewer": viewerId,
        title: definition.title,
        "aria-label": definition.text,
      });

      const $iconSpan = $("<span/>", {
        class: "btn-icon",
        "aria-hidden": "true",
      }).html(definition.icon);
      $btn.append($iconSpan);

      $btn.append(
        $("<span/>", {
          class: "btn-text",
          text: definition.text,
        })
      );

      $controls.append($btn);
    });

    if (hasCoords) {
      $controls.append(
        $("<span/>", {
          class: "coord-output",
          "data-viewer": viewerId,
          "aria-live": "polite",
        })
      );
    }

    $controls.append($("<div/>", { class: "divider" }));

    const $zoomDisplay = $("<span/>", { class: "zoom-display" });
    const $zoomPercentage = $("<span/>", {
      class: "zoom-percentage",
      "data-viewer": viewerId,
      text: initialZoomPercent,
    });
    $zoomDisplay.append($zoomPercentage).append("%");
    $controls.append($zoomDisplay);

    $main.prepend($controls);
  }

  function instantiateViewer($metaBox, viewerId) {
    const $wrapper = $("#" + viewerId);
    if (!$wrapper.length) {
      return;
    }

    const buttonFill = getFieldValue(
      $metaBox,
      'input[name="btsvviewer_button_fill"]',
      ""
    );
    const buttonBorder = getFieldValue(
      $metaBox,
      'input[name="btsvviewer_button_border"]',
      ""
    );
    const buttonForeground = getFieldValue(
      $metaBox,
      'input[name="btsvviewer_button_foreground"]',
      ""
    );
    applyButtonColors($wrapper, buttonFill, buttonBorder, buttonForeground);

    const src = getFieldValue(
      $metaBox,
      'input[name="btsvviewer_src"]',
      ""
    ).trim();
    if (!src) {
      alert(
        (window.btsvviewerAdmin && btsvviewerAdmin.i18n.missingSrc) ||
          "Please select an SVG before loading the preview."
      );
      return;
    }

    const height = getFieldValue(
      $metaBox,
      'input[name="btsvviewer_height"]',
      "600px"
    );
    const minZoomPercent = getFieldValue(
      $metaBox,
      'input[name="btsvviewer_min_zoom"]',
      "25"
    );
    const maxZoomPercent = getFieldValue(
      $metaBox,
      'input[name="btsvviewer_max_zoom"]',
      "800"
    );
    const initialZoomPercent = getFieldValue(
      $metaBox,
      'input[name="btsvviewer_initial_zoom"]',
      "100"
    );
    const zoomStepPercent = getFieldValue(
      $metaBox,
      'input[name="btsvviewer_zoom_step"]',
      "10"
    );
    const centerX = parseFloatOrNull(
      getFieldValue($metaBox, 'input[name="btsvviewer_center_x"]', "")
    );
    const centerY = parseFloatOrNull(
      getFieldValue($metaBox, 'input[name="btsvviewer_center_y"]', "")
    );
    const title = getFieldValue($metaBox, 'input[name="btsvviewer_title"]', "");
    const caption = getFieldValue(
      $metaBox,
      'textarea[name="btsvviewer_caption"]',
      ""
    );
    const controlsPositionValue = getFieldValue(
      $metaBox,
      'select[name="btsvviewer_controls_position"]',
      "top"
    );
    const controlsButtonsValue = getFieldValue(
      $metaBox,
      'input[name="btsvviewer_controls_buttons"]',
      "both"
    );
    const panModeRaw = getFieldValue(
      $metaBox,
      'select[name="btsvviewer_pan_mode"]',
      "scroll"
    );
    const zoomModeRaw = getFieldValue(
      $metaBox,
      'select[name="btsvviewer_zoom_mode"]',
      "super_scroll"
    );

    const minZoom = parsePercentage(minZoomPercent, 0.25);
    const maxZoom = parsePercentage(maxZoomPercent, 8);
    const zoomStep = parsePercentage(zoomStepPercent, 0.1);
    let initialZoom = parsePercentage(initialZoomPercent, 1);

    const clampedInitial = Math.max(minZoom, Math.min(maxZoom, initialZoom));
    if (clampedInitial !== initialZoom) {
      initialZoom = clampedInitial;
      const percent = Math.round(initialZoom * 100);
      $metaBox
        .find('input[name="btsvviewer_initial_zoom"]')
        .val(percent.toString());
    }

    const initialZoomRounded = Math.round(initialZoom * 100);
    const controlsConfig = parseControlsConfig(
      controlsPositionValue,
      controlsButtonsValue,
      false
    );

    const interactionConfig = resolveInteractionConfig(panModeRaw, zoomModeRaw);
    applyControlsConfig(
      $wrapper,
      controlsConfig,
      viewerId,
      initialZoomRounded,
      false,
      minZoomPercent,
      maxZoomPercent,
      zoomStepPercent
    );

    const $mainWrapper = ensureMainWrapper($wrapper, viewerId);
    removeInteractionClasses($wrapper);
    removeInteractionClasses($mainWrapper);
    const resolvedPanMode = interactionConfig.panMode;
    const resolvedZoomMode = interactionConfig.zoomMode;
    $wrapper
      .addClass("pan-mode-" + resolvedPanMode)
      .addClass("zoom-mode-" + resolvedZoomMode);
    if ($mainWrapper && $mainWrapper.length) {
      $mainWrapper
        .addClass("pan-mode-" + resolvedPanMode)
        .addClass("zoom-mode-" + resolvedZoomMode);
    }

    const currentInstances = window.btsvviewerInstances || {};
    if (currentInstances[viewerId] && currentInstances[viewerId].destroy) {
      try {
        currentInstances[viewerId].destroy();
      } catch (err) {
        console.warn("SVGViewer destroy error:", err);
      }
    }
    if (currentInstances[viewerId]) {
      delete currentInstances[viewerId];
    }

    $wrapper.find(".svg-viewport").empty();
    $wrapper
      .find(".zoom-percentage[data-viewer='" + viewerId + "']")
      .text(initialZoomRounded);
    $wrapper
      .find('.svg-container[data-viewer="' + viewerId + '"]')
      .css("height", height);

    setViewerText($wrapper.find(".js-admin-title"), title);
    setViewerText($wrapper.find(".js-admin-caption"), caption);
    const $interactionCaption = $wrapper.find(".js-admin-interaction-caption");
    if (interactionConfig.messages.length) {
      $interactionCaption
        .removeAttr("hidden")
        .html(interactionConfig.messages.join("<br />"));
    } else {
      $interactionCaption.attr("hidden", true).empty();
    }

    if (typeof window.btsvviewerInstances === "undefined") {
      window.btsvviewerInstances = {};
    }

    const instance = new SVGViewer({
      viewerId,
      svgUrl: src,
      initialZoom,
      minZoom,
      maxZoom,
      zoomStep,
      centerX,
      centerY,
      showCoordinates: true,
      panMode: resolvedPanMode,
      zoomMode: resolvedZoomMode,
    });
    window.btsvviewerInstances[viewerId] = instance;
  }

  function captureViewerState($metaBox, viewerId, $status) {
    if (
      typeof window.btsvviewerInstances === "undefined" ||
      !window.btsvviewerInstances[viewerId]
    ) {
      $status
        .text(
          (window.btsvviewerAdmin && btsvviewerAdmin.i18n.captureFailed) ||
            "Unable to capture the current state. Refresh the preview and try again."
        )
        .addClass("error");
      setTimeout(function () {
        $status.text("").removeClass("error");
      }, 4000);
      return;
    }

    const viewer = window.btsvviewerInstances[viewerId];
    const center = viewer.getVisibleCenterPoint();
    const zoomPercent = Math.round((viewer.currentZoom || 1) * 100);

    $metaBox
      .find('input[name="btsvviewer_initial_zoom"]')
      .val(zoomPercent.toString());
    setCenterFields($metaBox, center);

    $status
      .removeClass("error")
      .text(
        (window.btsvviewerAdmin && btsvviewerAdmin.i18n.captureSaved) ||
          "Captured viewer state from the preview."
      );
    setTimeout(function () {
      $status.text("").removeClass("error");
    }, 4000);
  }

  function parseSvgDimensionValue(value) {
    if (value == null) {
      return null;
    }
    if (typeof value === "number") {
      return isFinite(value) ? value : null;
    }
    if (typeof value === "string") {
      const trimmed = value.trim();
      if (!trimmed) {
        return null;
      }
      const match = trimmed.match(/^(-?\d+(?:\.\d+)?)/);
      if (match) {
        const parsed = parseFloat(match[1]);
        return isFinite(parsed) ? parsed : null;
      }
    }
    return null;
  }

  function computeCenterFromDimensions(widthValue, heightValue) {
    const width = parseSvgDimensionValue(widthValue);
    const height = parseSvgDimensionValue(heightValue);
    if (
      width == null ||
      height == null ||
      !isFinite(width) ||
      !isFinite(height) ||
      width <= 0 ||
      height <= 0
    ) {
      return null;
    }
    return {
      x: width / 2,
      y: height / 2,
    };
  }

  function computeCenterFromViewBox(viewBoxValue) {
    if (typeof viewBoxValue !== "string") {
      return null;
    }
    const parts = viewBoxValue
      .trim()
      .split(/[\s,]+/)
      .map(function (part) {
        return parseFloat(part);
      });
    if (parts.length !== 4 || parts.some(function (num) { return !isFinite(num); })) {
      return null;
    }
    const minX = parts[0];
    const minY = parts[1];
    const width = parts[2];
    const height = parts[3];
    if (width <= 0 || height <= 0) {
      return null;
    }
    return {
      x: minX + width / 2,
      y: minY + height / 2,
    };
  }

  function setCenterFields($metaBox, center, options = {}) {
    if (
      !center ||
      typeof center.x !== "number" ||
      typeof center.y !== "number" ||
      !isFinite(center.x) ||
      !isFinite(center.y)
    ) {
      return;
    }
    const $centerX = $metaBox.find('input[name="btsvviewer_center_x"]');
    const $centerY = $metaBox.find('input[name="btsvviewer_center_y"]');
    if (!$centerX.length || !$centerY.length) {
      return;
    }
    const formattedX = center.x.toFixed(2);
    const formattedY = center.y.toFixed(2);
    $centerX.val(formattedX);
    $centerY.val(formattedY);

    const viewerId = $metaBox.data("viewerId");
    if (
      typeof viewerId === "string" &&
      viewerId.length &&
      window.btsvviewerInstances &&
      window.btsvviewerInstances[viewerId] &&
      typeof window.btsvviewerInstances[viewerId].setManualCenter === "function"
    ) {
      window.btsvviewerInstances[viewerId].setManualCenter(center.x, center.y, {
        recenter: Boolean(options.recenterViewer),
      });
    }
  }

  function extractCenterFromAttachmentData(attachmentData) {
    if (!attachmentData || typeof attachmentData !== "object") {
      return null;
    }

    const candidates = [];
    candidates.push(attachmentData);
    if (attachmentData.meta && typeof attachmentData.meta === "object") {
      candidates.push(attachmentData.meta);
      if (attachmentData.meta.svg_meta && typeof attachmentData.meta.svg_meta === "object") {
        candidates.push(attachmentData.meta.svg_meta);
      }
      if (attachmentData.meta.svg && typeof attachmentData.meta.svg === "object") {
        candidates.push(attachmentData.meta.svg);
      }
    }

    for (let i = 0; i < candidates.length; i += 1) {
      const candidate = candidates[i];
      if (!candidate || typeof candidate !== "object") {
        continue;
      }
      const viewBoxCenter = computeCenterFromViewBox(
        candidate.viewBox || candidate.viewbox || candidate.view_box
      );
      if (viewBoxCenter) {
        return viewBoxCenter;
      }
      const dimensionCenter = computeCenterFromDimensions(
        candidate.width,
        candidate.height
      );
      if (dimensionCenter) {
        return dimensionCenter;
      }
    }

    return null;
  }

  function fetchSvgCenter(url) {
    if (
      !url ||
      typeof window.fetch !== "function" ||
      typeof window.DOMParser === "undefined"
    ) {
      return Promise.resolve(null);
    }
    let fetchOptions = {};
    try {
      const parsedUrl = new URL(url, window.location.href);
      if (parsedUrl.origin === window.location.origin) {
        fetchOptions = { credentials: "same-origin" };
      } else {
        fetchOptions = { credentials: "include", mode: "cors" };
      }
    } catch (err) {
      fetchOptions = { credentials: "same-origin" };
    }

    return fetch(url, fetchOptions)
      .then(function (response) {
        if (!response.ok) {
          throw new Error("SVG fetch failed");
        }
        return response.text();
      })
      .then(function (svgText) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(svgText, "image/svg+xml");
        if (!doc || !doc.documentElement) {
          return null;
        }
        if (
          doc.getElementsByTagName("parsererror").length &&
          doc.getElementsByTagName("parsererror").length > 0
        ) {
          return null;
        }
        const svgEl = doc.documentElement;
        if (!svgEl || svgEl.nodeName.toLowerCase() !== "svg") {
          return null;
        }
        const viewBoxCenter = computeCenterFromViewBox(
          svgEl.getAttribute("viewBox") || svgEl.getAttribute("viewbox")
        );
        if (viewBoxCenter) {
          return viewBoxCenter;
        }
        return computeCenterFromDimensions(
          svgEl.getAttribute("width"),
          svgEl.getAttribute("height")
        );
      })
      .catch(function () {
        return null;
      });
  }

  function autoPopulateCenterFields($metaBox, attachmentData) {
    const metadataCenter = extractCenterFromAttachmentData(attachmentData);
    if (metadataCenter) {
      setCenterFields($metaBox, metadataCenter);
      return;
    }
    const svgUrl =
      (attachmentData && attachmentData.url) ||
      getFieldValue($metaBox, 'input[name="btsvviewer_src"]', "");
    if (!svgUrl) {
      return;
    }
    fetchSvgCenter(svgUrl).then(function (center) {
      if (center) {
        setCenterFields($metaBox, center);
      }
    });
  }

  function bindMediaSelector($metaBox) {
    let mediaFrame = null;

    $metaBox.on("click", ".bt-svg-viewer-select-media", function (event) {
      event.preventDefault();

      if (mediaFrame) {
        mediaFrame.open();
        return;
      }

      mediaFrame = wp.media({
        title: "Select SVG",
        button: {
          text: "Use this SVG",
        },
        library: {
          type: ["image/svg+xml", "image/svg"],
        },
        multiple: false,
      });

      mediaFrame.on("select", function () {
        const attachment = mediaFrame.state().get("selection").first();
        if (!attachment) {
          return;
        }
        const data = attachment.toJSON();
        $metaBox.find('input[name="btsvviewer_src"]').val(data.url || "");
        if (data.id) {
          $metaBox.find('input[name="btsvviewer_attachment_id"]').val(data.id);
        }
        autoPopulateCenterFields($metaBox, data);
      });

      mediaFrame.open();
    });
  }

  function initColorPickers($metaBox, viewerId) {
    const $fields = $metaBox.find(".bt-svg-viewer-color-field");

    const resolveTargetWrapper = function () {
      if (typeof viewerId === "string" && viewerId.length) {
        return $("#" + viewerId);
      }
      const dataTarget = $metaBox.data("previewTarget");
      if (typeof dataTarget === "string" && dataTarget.length) {
        return $("#" + dataTarget);
      }
      return $();
    };

    const refreshColors = function () {
      const fill = getFieldValue(
        $metaBox,
        'input[name="btsvviewer_button_fill"]',
        ""
      );
      const border = getFieldValue(
        $metaBox,
        'input[name="btsvviewer_button_border"]',
        ""
      );
      const foreground = getFieldValue(
        $metaBox,
        'input[name="btsvviewer_button_foreground"]',
        ""
      );
      const $target = resolveTargetWrapper();
      if ($target && $target.length) {
        applyButtonColors($target, fill, border, foreground);
      }
    };

    if (typeof $.fn.wpColorPicker === "function" && $fields.length) {
      $fields.each(function () {
        const $field = $(this);
        $field.wpColorPicker({
          change: function () {
            refreshColors();
          },
          clear: function () {
            refreshColors();
          },
        });
      });
    }

    $metaBox.on(
      "input",
      'input[name="btsvviewer_button_fill"], input[name="btsvviewer_button_border"], input[name="btsvviewer_button_foreground"]',
      function () {
        refreshColors();
      }
    );

    refreshColors();
  }

  $(document).ready(function () {
    initTabs($(document));

    $(document).on("click", ".svg-shortcode-copy", function (event) {
      event.preventDefault();
      const $button = $(this);
      const shortcode = $button.data("shortcode");
      const $status = $button.siblings(".svg-shortcode-status");
      copyShortcode(shortcode, $status);
    });

    $(document).on("click", ".svg-shortcode-full", function (event) {
      event.preventDefault();
      const $button = $(this);
      const $metaBox = $button.closest(".bt-svg-viewer-admin-meta");
      const $status = $button.siblings(".svg-shortcode-status");
      const shortcode = buildFullShortcode($metaBox);

      if (!shortcode) {
        if ($status && $status.length) {
          $status.addClass("error");
          updateStatus(
            $status,
            getI18n(
              "missingSrc",
              "Please select an SVG before loading the preview."
            )
          );
        }
        return;
      }

      if ($status && $status.length) {
        $status.removeClass("error");
      }

      copyShortcode(shortcode, $status, "fullCopySuccess");
    });

    const $defaultsMeta = $(".bt-svg-viewer-defaults-meta");
    $defaultsMeta.each(function () {
      const $metaBox = $(this);
      const viewerId = $metaBox.data("viewerId") || "";
      bindMediaSelector($metaBox);
      initColorPickers($metaBox, viewerId);
    });

    const $metaBoxes = $(".bt-svg-viewer-admin-meta");
    $metaBoxes.each(function () {
      const $metaBox = $(this);
      const viewerId = $metaBox.data("viewerId");
      const $status = $metaBox.find(".svg-admin-status");

      bindMediaSelector($metaBox);
      initColorPickers($metaBox, viewerId);

      $metaBox.on("click", ".svg-admin-refresh-preview", function () {
        $status.text("").removeClass("error");
        instantiateViewer($metaBox, viewerId);
      });

      $metaBox.on("click", ".svg-admin-capture-state", function () {
        captureViewerState($metaBox, viewerId, $status);
      });

      // Auto-load preview if a source already exists
      const existingSrc = $metaBox
        .find('input[name="btsvviewer_src"]')
        .val()
        .trim();
      if (existingSrc) {
        $status.text("").removeClass("error");
        instantiateViewer($metaBox, viewerId);
      }
    });
  });
})(jQuery);
