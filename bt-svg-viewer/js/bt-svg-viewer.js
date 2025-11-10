/**
 * BT SVG Viewer Plugin
 * Provides zoom, pan, and centering functionality for embedded SVGs
 */

class SVGViewer {
  constructor(options) {
    this.viewerId = options.viewerId;
    this.svgUrl = options.svgUrl;

    // Configuration (with sensible defaults)
    this.ZOOM_STEP = options.zoomStep || 0.1;
    this.MIN_ZOOM =
      typeof options.minZoom === "number" ? options.minZoom : null;
    this.MAX_ZOOM = options.maxZoom || 8;
    this.initialZoom = options.initialZoom || 1;

    const parsedCenterX = Number(options.centerX);
    const parsedCenterY = Number(options.centerY);
    this.manualCenter =
      Number.isFinite(parsedCenterX) && Number.isFinite(parsedCenterY)
        ? { x: parsedCenterX, y: parsedCenterY }
        : null;
    this.showCoordinates = Boolean(options.showCoordinates);
    this.panMode = SVGViewer.normalizePanMode(options.panMode);
    this.zoomMode = SVGViewer.normalizeZoomMode(options.zoomMode);
    if (this.zoomMode === "scroll" && this.panMode === "scroll") {
      this.panMode = "drag";
    }

    // State
    this.currentZoom = this.initialZoom;
    this.svgElement = null;
    this.isLoading = false;
    this.baseDimensions = null;
    this.baseOrigin = { x: 0, y: 0 };
    this.baseCssDimensions = null;
    this.unitsPerCss = { x: 1, y: 1 };
    this.dragState = {
      isActive: false,
      pointerId: null,
      startX: 0,
      startY: 0,
      scrollLeft: 0,
      scrollTop: 0,
      lastClientX: 0,
      lastClientY: 0,
      inputType: null,
      prevScrollBehavior: "",
    };
    this.boundPointerDown = null;
    this.boundPointerMove = null;
    this.boundPointerUp = null;
    this.boundClickHandler = null;
    this.boundWheelHandler = null;
    this.dragListenersAttached = false;
    this.wheelDeltaBuffer = 0;
    this.wheelAnimationFrame = null;
    this.wheelFocusPoint = null;
    this.zoomAnimationFrame = null;
    this.pointerEventsSupported =
      typeof window !== "undefined" && window.PointerEvent;
    this.boundMouseDown = null;
    this.boundMouseMove = null;
    this.boundMouseUp = null;
    this.boundTouchStart = null;
    this.boundTouchMove = null;
    this.boundTouchEnd = null;

    // DOM Elements
    this.wrapper = document.getElementById(this.viewerId);
    this.container = this.wrapper.querySelector(
      '[data-viewer="' + this.viewerId + '"].svg-container'
    );
    this.viewport = this.wrapper.querySelector(
      '[data-viewer="' + this.viewerId + '"].svg-viewport'
    );
    this.zoomPercentageEl = this.wrapper.querySelector(
      '[data-viewer="' + this.viewerId + '"].zoom-percentage'
    );
    this.coordOutputEl = this.wrapper.querySelector(
      '[data-viewer="' + this.viewerId + '"].coord-output'
    );
    this.zoomInButtons = this.wrapper
      ? Array.from(
          this.wrapper.querySelectorAll(
            '[data-viewer="' + this.viewerId + '"].zoom-in-btn'
          )
        )
      : [];
    this.zoomOutButtons = this.wrapper
      ? Array.from(
          this.wrapper.querySelectorAll(
            '[data-viewer="' + this.viewerId + '"].zoom-out-btn'
          )
        )
      : [];
    this.zoomSliderEls = this.wrapper
      ? Array.from(
          this.wrapper.querySelectorAll(
            '[data-viewer="' + this.viewerId + '"].zoom-slider'
          )
        )
      : [];
    this.cleanupHandlers = [];
    this.boundKeydownHandler = null;

    this.init();
  }

  static normalizePanMode(value) {
    const raw =
      typeof value === "string" ? value.trim().toLowerCase() : String("");
    return raw === "drag" ? "drag" : "scroll";
  }

  static normalizeZoomMode(value) {
    const raw =
      typeof value === "string" ? value.trim().toLowerCase() : String("");
    const normalized = raw.replace(/[\s-]+/g, "_");
    if (normalized === "click") {
      return "click";
    }
    if (normalized === "scroll") {
      return "scroll";
    }
    return "super_scroll";
  }

  init() {
    this.setupEventListeners();
    this.updateZoomDisplay();
    this.updateViewport();
    this.loadSVG();
  }

  registerEvent(target, type, handler, options) {
    if (!target || typeof target.addEventListener !== "function") {
      return;
    }
    const listenerOptions = typeof options === "undefined" ? false : options;
    target.addEventListener(type, handler, listenerOptions);
    if (!Array.isArray(this.cleanupHandlers)) {
      this.cleanupHandlers = [];
    }
    this.cleanupHandlers.push(() => {
      if (!target || typeof target.removeEventListener !== "function") {
        return;
      }
      try {
        target.removeEventListener(type, handler, listenerOptions);
      } catch (err) {
        // Ignore listener removal errors
      }
    });
  }

  setupEventListeners() {
    if (this.zoomInButtons && this.zoomInButtons.length) {
      this.zoomInButtons.forEach((btn) => {
        const handler = () => this.zoomIn();
        this.registerEvent(btn, "click", handler);
      });
    }

    if (this.zoomOutButtons && this.zoomOutButtons.length) {
      this.zoomOutButtons.forEach((btn) => {
        const handler = () => this.zoomOut();
        this.registerEvent(btn, "click", handler);
      });
    }

    this.wrapper
      .querySelectorAll('[data-viewer="' + this.viewerId + '"].reset-zoom-btn')
      .forEach((btn) => {
        const handler = () => this.resetZoom();
        this.registerEvent(btn, "click", handler);
      });

    this.wrapper
      .querySelectorAll('[data-viewer="' + this.viewerId + '"].center-view-btn')
      .forEach((btn) => {
        const handler = () => this.centerView();
        this.registerEvent(btn, "click", handler);
      });

    if (this.showCoordinates) {
      this.wrapper
        .querySelectorAll(
          '[data-viewer="' + this.viewerId + '"].coord-copy-btn'
        )
        .forEach((btn) => {
          const handler = () => this.copyCenterCoordinates();
          this.registerEvent(btn, "click", handler);
        });
    }

    this.boundKeydownHandler =
      this.boundKeydownHandler || ((e) => this.handleKeyboard(e));
    this.registerEvent(document, "keydown", this.boundKeydownHandler);

    if (this.container) {
      this.boundWheelHandler =
        this.boundWheelHandler || ((event) => this.handleMouseWheel(event));
      const wheelOptions = { passive: false };
      this.registerEvent(this.container, "wheel", this.boundWheelHandler, wheelOptions);
      if (this.zoomMode === "click") {
        this.boundClickHandler =
          this.boundClickHandler || ((event) => this.handleContainerClick(event));
        this.registerEvent(this.container, "click", this.boundClickHandler);
      }
    }

    if (this.panMode === "drag") {
      this.enableDragPan();
    }

    if (this.zoomSliderEls && this.zoomSliderEls.length) {
      this.zoomSliderEls.forEach((slider) => {
        const handler = (event) => {
          const percent = parseFloat(event.target.value);
          if (!Number.isFinite(percent)) {
            return;
          }
          this.setZoom(percent / 100);
        };
        this.registerEvent(slider, "input", handler);
      });
    }
  }

  async loadSVG() {
    if (this.isLoading) return;
    this.isLoading = true;

    try {
      console.debug("[SVGViewer]", this.viewerId, "fetching", this.svgUrl);
      const response = await fetch(this.svgUrl);
      if (!response.ok)
        throw new Error(`Failed to load SVG: ${response.status}`);

      const svgText = await response.text();
      this.viewport.innerHTML = svgText;
      this.svgElement = this.viewport.querySelector("svg");

      if (this.svgElement) {
        console.debug("[SVGViewer]", this.viewerId, "SVG loaded");
        this.prepareSvgElement();
        this.captureBaseDimensions();
        this.currentZoom = this.initialZoom;
        this.updateViewport({ immediate: true });
        this.centerView();
      }
    } catch (error) {
      console.error("BT SVG Viewer Error:", error);
      this.viewport.innerHTML =
        '<div style="padding: 20px; color: red;">Error loading SVG. Check the file path and ensure CORS is configured if needed.</div>';
    }

    this.isLoading = false;
  }

  setZoom(newZoom, options = {}) {
    if (options.animate && !options.__animationStep) {
      this.startZoomAnimation(newZoom, options);
      return;
    }

    if (this.zoomAnimationFrame && !options.__animationStep) {
      window.cancelAnimationFrame(this.zoomAnimationFrame);
      this.zoomAnimationFrame = null;
    }

    if (!this.container || !this.viewport) {
      const minZoomFallback =
        Number.isFinite(this.MIN_ZOOM) && this.MIN_ZOOM > 0 ? this.MIN_ZOOM : 0;
      const maxZoomFallback =
        Number.isFinite(this.MAX_ZOOM) && this.MAX_ZOOM > 0
          ? this.MAX_ZOOM
          : newZoom;
      this.currentZoom = Math.max(
        minZoomFallback,
        Math.min(maxZoomFallback, newZoom)
      );
      this.updateZoomDisplay();
      return;
    }

    const prevZoom = this.currentZoom || 1;
    const baseWidth = this.baseDimensions ? this.baseDimensions.width : 1;
    const baseHeight = this.baseDimensions ? this.baseDimensions.height : 1;
    const prevTransform = this.container?.style.transform;
    if (this.container) {
      this.container.style.transform = "translate3d(0,0,0)";
    }

    const resolvedMinZoom = this.resolveMinZoom();
    const effectiveMinZoom =
      Number.isFinite(resolvedMinZoom) && resolvedMinZoom > 0
        ? resolvedMinZoom
        : 0;
    const effectiveMaxZoom =
      Number.isFinite(this.MAX_ZOOM) && this.MAX_ZOOM > 0
        ? this.MAX_ZOOM
        : Math.max(newZoom, 0);

    const focusData =
      options.__focusData && typeof options.__focusData === "object"
        ? options.__focusData
        : this._computeFocusData(prevZoom, options);
    const focusBaseX = focusData.focusBaseX;
    const focusBaseY = focusData.focusBaseY;
    const focusOffsetX = focusData.focusOffsetX;
    const focusOffsetY = focusData.focusOffsetY;

    this.currentZoom = Math.max(
      effectiveMinZoom,
      Math.min(effectiveMaxZoom, newZoom)
    );

    this.updateViewport(options);
    this.updateZoomDisplay();

    const newScrollWidth = this.container.scrollWidth;
    const newScrollHeight = this.container.scrollHeight;

    if (!options.center && newScrollWidth && newScrollHeight) {
      const focusCssXAfter =
        ((focusBaseX - this.baseOrigin.x) / (this.unitsPerCss.x || 1)) *
        this.currentZoom;
      const focusCssYAfter =
        ((focusBaseY - this.baseOrigin.y) / (this.unitsPerCss.y || 1)) *
        this.currentZoom;

      let targetLeft;
      if (typeof focusOffsetX === "number") {
        targetLeft = focusCssXAfter - focusOffsetX;
      } else {
        targetLeft = focusCssXAfter - this.container.clientWidth / 2;
      }

      let targetTop;
      if (typeof focusOffsetY === "number") {
        targetTop = focusCssYAfter - focusOffsetY;
      } else {
        targetTop = focusCssYAfter - this.container.clientHeight / 2;
      }

      this._debugLastZoom = {
        focusBaseX,
        focusBaseY,
        focusCssXAfter,
        focusCssYAfter,
        targetLeft,
        targetTop,
        scrollWidth: newScrollWidth,
        scrollHeight: newScrollHeight,
      };

      const maxLeft = Math.max(0, newScrollWidth - this.container.clientWidth);
      const maxTop = Math.max(0, newScrollHeight - this.container.clientHeight);

      const clampedLeft = Math.min(maxLeft, Math.max(0, targetLeft));
      const clampedTop = Math.min(maxTop, Math.max(0, targetTop));

      const previousBehavior = this.container.style.scrollBehavior;
      this.container.style.scrollBehavior = "auto";
      this.container.scrollLeft = clampedLeft;
      this.container.scrollTop = clampedTop;
      this.container.style.scrollBehavior = previousBehavior;
    }

    if (options.center) {
      const centerPoint =
        typeof options.focusX === "number" && typeof options.focusY === "number"
          ? { x: options.focusX, y: options.focusY }
          : this.getCenterPoint();
      this.centerView({ focusX: centerPoint.x, focusY: centerPoint.y });
    }

    if (this.container) {
      this.container.style.transform = prevTransform || "";
    }
  }

  _computeFocusData(prevZoom, options = {}) {
    const data = {
      focusBaseX: 0,
      focusBaseY: 0,
      focusOffsetX: null,
      focusOffsetY: null,
    };

    if (!this.container) {
      return data;
    }

    if (
      typeof options.focusX === "number" &&
      typeof options.focusY === "number"
    ) {
      data.focusBaseX = options.focusX;
      data.focusBaseY = options.focusY;
    } else {
      const visibleCenterX =
        this.container.scrollLeft + this.container.clientWidth / 2;
      const visibleCenterY =
        this.container.scrollTop + this.container.clientHeight / 2;

      const cssBaseX = visibleCenterX / prevZoom;
      const cssBaseY = visibleCenterY / prevZoom;

      data.focusBaseX =
        this.baseOrigin.x + cssBaseX * (this.unitsPerCss.x || 1);
      data.focusBaseY =
        this.baseOrigin.y + cssBaseY * (this.unitsPerCss.y || 1);
    }

    if (typeof options.focusOffsetX === "number") {
      data.focusOffsetX = options.focusOffsetX;
    }
    if (typeof options.focusOffsetY === "number") {
      data.focusOffsetY = options.focusOffsetY;
    }

    return data;
  }

  updateViewport({ immediate = false } = {}) {
    if (!this.baseCssDimensions || !this.viewport || !this.svgElement) return;

    const width = this.baseCssDimensions.width * this.currentZoom;
    const height = this.baseCssDimensions.height * this.currentZoom;

    this.viewport.style.width = `${width}px`;
    this.viewport.style.height = `${height}px`;
    this.svgElement.style.width = `${width}px`;
    this.svgElement.style.height = `${height}px`;
    this.viewport.style.transform = "none";
    this.svgElement.style.transform = "none";

    if (immediate) {
      this.viewport.getBoundingClientRect(); // force layout
    }
  }

  updateZoomDisplay() {
    if (this.zoomPercentageEl) {
      this.zoomPercentageEl.textContent = Math.round(this.currentZoom * 100);
    }

    if (this.zoomSliderEls && this.zoomSliderEls.length) {
      const sliderValue = Math.round(this.currentZoom * 100);
      this.zoomSliderEls.forEach((slider) => {
        slider.value = String(sliderValue);
        slider.setAttribute("aria-valuenow", String(sliderValue));
      });
    }

    this.updateZoomButtonState();
  }

  zoomIn() {
    const targetZoom = this.computeZoomTarget("in");
    this.setZoom(targetZoom, { animate: true });
  }

  zoomOut() {
    const targetZoom = this.computeZoomTarget("out");
    this.setZoom(targetZoom, { animate: true });
  }

  resetZoom() {
    this.setZoom(this.initialZoom || 1, { center: true, animate: true });
  }

  startZoomAnimation(targetZoom, options = {}) {
    if (!this.container || !this.viewport) {
      this.setZoom(targetZoom, { ...options, animate: false });
      return;
    }

    if (this.zoomAnimationFrame) {
      window.cancelAnimationFrame(this.zoomAnimationFrame);
      this.zoomAnimationFrame = null;
    }

    const startZoom = this.currentZoom || 1;
    const focusData = this._computeFocusData(startZoom, options);
    const duration =
      typeof options.zoomAnimationDuration === "number"
        ? Math.max(0, options.zoomAnimationDuration)
        : 160;
    const easing =
      typeof options.zoomAnimationEasing === "function"
        ? options.zoomAnimationEasing
        : this._easeOutCubic;

    const frameOptions = {
      ...options,
      animate: false,
      __animationStep: true,
      __focusData: focusData,
    };
    delete frameOptions.center;

    const startTimeRef = { value: null };

    const animateFrame = (timestamp) => {
      if (startTimeRef.value === null) {
        startTimeRef.value = timestamp;
      }
      const elapsed = timestamp - startTimeRef.value;
      const progress =
        duration === 0 ? 1 : Math.min(elapsed / duration, 1);
      const easedProgress = easing(progress);
      const intermediateZoom =
        startZoom + (targetZoom - startZoom) * easedProgress;

      this.setZoom(intermediateZoom, frameOptions);

      if (progress < 1) {
        this.zoomAnimationFrame =
          window.requestAnimationFrame(animateFrame);
      } else {
        this.zoomAnimationFrame = null;
        this.setZoom(targetZoom, {
          ...options,
          animate: false,
          __animationStep: true,
          __focusData: focusData,
        });
      }
    };

    this.zoomAnimationFrame = window.requestAnimationFrame(animateFrame);
  }

  _easeOutCubic(t) {
    return 1 - Math.pow(1 - t, 3);
  }

  centerView(input = {}) {
    if (!this.container || !this.baseDimensions || !this.baseCssDimensions)
      return;

    let focusX;
    let focusY;

    if (typeof input.focusX === "number" && typeof input.focusY === "number") {
      focusX = input.focusX;
      focusY = input.focusY;
    } else if (typeof input.x === "number" && typeof input.y === "number") {
      focusX = input.x;
      focusY = input.y;
    } else {
      const center = this.getCenterPoint();
      focusX = center.x;
      focusY = center.y;
    }

    const cssCenterX =
      ((focusX - this.baseOrigin.x) / (this.unitsPerCss.x || 1)) *
      this.currentZoom;
    const cssCenterY =
      ((focusY - this.baseOrigin.y) / (this.unitsPerCss.y || 1)) *
      this.currentZoom;

    const targetLeft = cssCenterX - this.container.clientWidth / 2;
    const targetTop = cssCenterY - this.container.clientHeight / 2;

    const maxLeft = Math.max(
      0,
      this.container.scrollWidth - this.container.clientWidth
    );
    const maxTop = Math.max(
      0,
      this.container.scrollHeight - this.container.clientHeight
    );

    const clampedLeft = Math.min(maxLeft, Math.max(0, targetLeft));
    const clampedTop = Math.min(maxTop, Math.max(0, targetTop));

    const previousBehavior = this.container.style.scrollBehavior;
    this.container.style.scrollBehavior = "auto";
    this.container.scrollLeft = clampedLeft;
    this.container.scrollTop = clampedTop;
    this.container.style.scrollBehavior = previousBehavior;
  }

  prepareSvgElement() {
    if (!this.svgElement) return;
    this.svgElement.style.maxWidth = "none";
    this.svgElement.style.maxHeight = "none";
    this.svgElement.style.display = "block";
  }

  captureBaseDimensions() {
    if (!this.svgElement || !this.viewport) return;

    const rect = this.svgElement.getBoundingClientRect();
    let cssWidth = rect.width || this.svgElement.clientWidth || 1;
    let cssHeight = rect.height || this.svgElement.clientHeight || 1;

    const viewBox =
      this.svgElement.viewBox && this.svgElement.viewBox.baseVal
        ? this.svgElement.viewBox.baseVal
        : null;

    if (viewBox) {
      this.baseDimensions = {
        width: viewBox.width || 1,
        height: viewBox.height || 1,
      };
      this.baseOrigin = { x: viewBox.x || 0, y: viewBox.y || 0 };
    } else {
      this.baseDimensions = { width: cssWidth, height: cssHeight };
      this.baseOrigin = { x: 0, y: 0 };
    }

    if (cssWidth <= 1 || cssHeight <= 1) {
      if (viewBox && viewBox.width && viewBox.height) {
        cssWidth = viewBox.width;
        cssHeight = viewBox.height;
      } else {
        const fallbackWidth = this.container ? this.container.clientWidth : 0;
        const fallbackHeight = this.container ? this.container.clientHeight : 0;
        cssWidth = fallbackWidth || this.baseDimensions.width || 1;
        cssHeight = fallbackHeight || this.baseDimensions.height || 1;
      }
    }

    this.baseCssDimensions = { width: cssWidth, height: cssHeight };
    this.unitsPerCss = {
      x: this.baseDimensions.width / cssWidth,
      y: this.baseDimensions.height / cssHeight,
    };

    this.viewport.style.width = `${cssWidth}px`;
    this.viewport.style.height = `${cssHeight}px`;
    this.svgElement.style.width = `${cssWidth}px`;
    this.svgElement.style.height = `${cssHeight}px`;
  }

  handleKeyboard(e) {
    // Only handle shortcuts if focused on this viewer or its container
    const isViewerFocused =
      this.wrapper.contains(document.activeElement) ||
      e.target === document ||
      e.target === document.body;

    if (!isViewerFocused) return;

    // Ctrl/Cmd + Plus
    if ((e.ctrlKey || e.metaKey) && (e.key === "+" || e.key === "=")) {
      e.preventDefault();
      this.zoomIn();
    }
    // Ctrl/Cmd + Minus
    if ((e.ctrlKey || e.metaKey) && e.key === "-") {
      e.preventDefault();
      this.zoomOut();
    }
    // Ctrl/Cmd + 0 to reset
    if ((e.ctrlKey || e.metaKey) && e.key === "0") {
      e.preventDefault();
      this.resetZoom();
    }
  }

  handleMouseWheel(event) {
    if (!this.container) {
      return;
    }

    if (this.dragState && this.dragState.isActive && this.panMode === "drag") {
      return;
    }

    const initialScrollLeft = this.container.scrollLeft;
    const hadHorizontalDelta =
      typeof event.deltaX === "number" && event.deltaX !== 0;
    const absDeltaX = Math.abs(event.deltaX || 0);
    const absDeltaY = Math.abs(event.deltaY || 0);
    const verticalDominant =
      absDeltaX === 0 ? absDeltaY > 0 : absDeltaY >= absDeltaX * 1.5;
    const meetsThreshold = absDeltaY >= 4;
    const shouldZoom = verticalDominant && meetsThreshold;
    const panRequiresDrag = this.panMode === "drag";

    if (this.zoomMode === "scroll") {
      if (!shouldZoom) {
        if (panRequiresDrag) {
          event.preventDefault();
          if (hadHorizontalDelta) {
            this.container.scrollLeft = initialScrollLeft;
          }
        }
        return;
      }
      event.preventDefault();
      this.performWheelZoom(event);
      if (hadHorizontalDelta) {
        this.container.scrollLeft = initialScrollLeft;
      }
      return;
    }

    const hasModifier = event.ctrlKey || event.metaKey;

    if (this.zoomMode === "super_scroll") {
      if (!hasModifier) {
        if (panRequiresDrag) {
          event.preventDefault();
          if (hadHorizontalDelta) {
            this.container.scrollLeft = initialScrollLeft;
          }
        }
        return;
      }
      if (!shouldZoom) {
        if (panRequiresDrag) {
          event.preventDefault();
          if (hadHorizontalDelta) {
            this.container.scrollLeft = initialScrollLeft;
          }
        }
        return;
      }
      event.preventDefault();
      this.performWheelZoom(event);
      if (hadHorizontalDelta) {
        this.container.scrollLeft = initialScrollLeft;
      }
      return;
    }

    if (this.zoomMode === "click") {
      if (!hasModifier) {
        if (panRequiresDrag) {
          event.preventDefault();
          if (hadHorizontalDelta) {
            this.container.scrollLeft = initialScrollLeft;
          }
        }
        return;
      }
      if (!shouldZoom) {
        if (panRequiresDrag) {
          event.preventDefault();
          if (hadHorizontalDelta) {
            this.container.scrollLeft = initialScrollLeft;
          }
        }
        return;
      }
      event.preventDefault();
      this.performWheelZoom(event);
      if (hadHorizontalDelta) {
        this.container.scrollLeft = initialScrollLeft;
      }
    }
  }

  getFocusPointFromEvent(event) {
    if (!this.container) {
      return null;
    }

    let clientX = null;
    let clientY = null;

    if (typeof event.clientX === "number" && typeof event.clientY === "number") {
      clientX = event.clientX;
      clientY = event.clientY;
    } else if (event.touches && event.touches.length) {
      clientX = event.touches[0].clientX;
      clientY = event.touches[0].clientY;
    }

    if (clientX === null || clientY === null) {
      return null;
    }

    const rect = this.container.getBoundingClientRect();
    const cursorX = clientX - rect.left + this.container.scrollLeft;
    const cursorY = clientY - rect.top + this.container.scrollTop;

    const zoom = this.currentZoom || 1;
    const unitsX = this.unitsPerCss.x || 1;
    const unitsY = this.unitsPerCss.y || 1;

    const baseX = this.baseOrigin.x + (cursorX / zoom) * unitsX;
    const baseY = this.baseOrigin.y + (cursorY / zoom) * unitsY;
    const pointerOffsetX =
      typeof clientX === "number" && Number.isFinite(clientX)
        ? clientX - rect.left
        : null;
    const pointerOffsetY =
      typeof clientY === "number" && Number.isFinite(clientY)
        ? clientY - rect.top
        : null;

    return {
      baseX,
      baseY,
      pointerOffsetX,
      pointerOffsetY,
    };
  }

  performWheelZoom(event) {
    const normalizedDelta = this.normalizeWheelDelta(event);
    if (!normalizedDelta) {
      return;
    }

    const focusPoint = this.getFocusPointFromEvent(event);
    this.enqueueWheelDelta(normalizedDelta, focusPoint);
  }

  normalizeWheelDelta(event) {
    if (!event) {
      return 0;
    }

    let delta = Number(event.deltaY);
    if (!Number.isFinite(delta)) {
      return 0;
    }

    switch (event.deltaMode) {
      case 1: // lines
        delta *= 16;
        break;
      case 2: // pages
        delta *= this.getWheelPageDistance();
        break;
      default:
        break;
    }

    return delta;
  }

  enqueueWheelDelta(delta, focusPoint) {
    if (!Number.isFinite(delta) || delta === 0) {
      return;
    }

    this.wheelDeltaBuffer += delta;

    if (focusPoint) {
      this.wheelFocusPoint = focusPoint;
    }

    if (this.wheelAnimationFrame) {
      return;
    }

    this.wheelAnimationFrame = window.requestAnimationFrame(() =>
      this.flushWheelDelta()
    );
  }

  flushWheelDelta() {
    this.wheelAnimationFrame = null;
    const delta = this.wheelDeltaBuffer;
    this.wheelDeltaBuffer = 0;

    if (!Number.isFinite(delta) || delta === 0) {
      this.wheelFocusPoint = null;
      return;
    }

    const zoomRange = (this.MAX_ZOOM || 0) - (this.MIN_ZOOM || 0);
    if (!Number.isFinite(zoomRange) || zoomRange <= 0) {
      const direction = delta > 0 ? -1 : 1;
      this.setZoom(this.currentZoom + direction * this.ZOOM_STEP);
      this.wheelFocusPoint = null;
      return;
    }

    const pageDistance = this.getWheelPageDistance();
    if (!Number.isFinite(pageDistance) || pageDistance <= 0) {
      this.wheelFocusPoint = null;
      return;
    }

    const zoomDelta = (-delta / pageDistance) * zoomRange;
    if (!Number.isFinite(zoomDelta) || zoomDelta === 0) {
      this.wheelFocusPoint = null;
      return;
    }

    const targetZoom = this.currentZoom + zoomDelta;
    const focusPoint = this.wheelFocusPoint;
    this.wheelFocusPoint = null;

    const zoomOptions = {
      animate: true,
    };

    if (focusPoint) {
      zoomOptions.focusX = focusPoint.baseX;
      zoomOptions.focusY = focusPoint.baseY;
      if (typeof focusPoint.pointerOffsetX === "number") {
        zoomOptions.focusOffsetX = focusPoint.pointerOffsetX;
      }
      if (typeof focusPoint.pointerOffsetY === "number") {
        zoomOptions.focusOffsetY = focusPoint.pointerOffsetY;
      }
    }

    this.setZoom(targetZoom, zoomOptions);
  }

  getWheelPageDistance() {
    if (this.container && this.container.clientHeight) {
      return Math.max(200, this.container.clientHeight);
    }
    if (typeof window !== "undefined" && window.innerHeight) {
      return Math.max(200, window.innerHeight);
    }
    return 600;
  }

  enableDragPan() {
    if (!this.container || this.dragListenersAttached) {
      return;
    }

    this.boundPointerDown =
      this.boundPointerDown || ((event) => this.handlePointerDown(event));
    this.boundPointerMove =
      this.boundPointerMove || ((event) => this.handlePointerMove(event));
    this.boundPointerUp =
      this.boundPointerUp || ((event) => this.handlePointerUp(event));
    this.boundMouseDown =
      this.boundMouseDown || ((event) => this.handleMouseDown(event));
    this.boundMouseMove =
      this.boundMouseMove || ((event) => this.handleMouseMove(event));
    this.boundMouseUp =
      this.boundMouseUp || ((event) => this.handleMouseUp(event));
    this.boundTouchStart =
      this.boundTouchStart || ((event) => this.handleTouchStart(event));
    this.boundTouchMove =
      this.boundTouchMove || ((event) => this.handleTouchMove(event));
    this.boundTouchEnd =
      this.boundTouchEnd || ((event) => this.handleTouchEnd(event));

    this.registerEvent(this.container, "pointerdown", this.boundPointerDown);
    this.registerEvent(window, "pointermove", this.boundPointerMove);
    this.registerEvent(window, "pointerup", this.boundPointerUp);
    this.registerEvent(window, "pointercancel", this.boundPointerUp);

    this.registerEvent(this.container, "mousedown", this.boundMouseDown);
    this.registerEvent(window, "mousemove", this.boundMouseMove);
    this.registerEvent(window, "mouseup", this.boundMouseUp);

    const touchStartOptions = { passive: false };
    const touchMoveOptions = { passive: false };
    this.registerEvent(this.container, "touchstart", this.boundTouchStart, touchStartOptions);
    this.registerEvent(window, "touchmove", this.boundTouchMove, touchMoveOptions);
    this.registerEvent(window, "touchend", this.boundTouchEnd);
    this.registerEvent(window, "touchcancel", this.boundTouchEnd);

    if (this.container.style) {
      this.container.style.touchAction = this.pointerEventsSupported
        ? "pan-x pan-y"
        : "none";
    }
    this.dragListenersAttached = true;
  }

  handlePointerDown(event) {
    if (this.panMode !== "drag" || !this.container) {
      return;
    }
    if (!event.isPrimary) {
      return;
    }
    if (event.pointerType === "mouse" && event.button !== 0) {
      return;
    }
    if (
      this.zoomMode === "click" &&
      (event.metaKey || event.ctrlKey || event.altKey)
    ) {
      return;
    }

    event.preventDefault();
    this.beginDrag({
      clientX: event.clientX,
      clientY: event.clientY,
      pointerId:
        typeof event.pointerId === "number" ? event.pointerId : event.pointerId,
      inputType: event.pointerType || "pointer",
      sourceEvent: event,
    });
  }

  handlePointerMove(event) {
    if (
      !this.dragState.isActive ||
      !this.container ||
      (this.dragState.pointerId !== null &&
        typeof event.pointerId === "number" &&
        event.pointerId !== this.dragState.pointerId)
    ) {
      return;
    }

    event.preventDefault();
    this.updateDrag({
      clientX: event.clientX,
      clientY: event.clientY,
    });
  }

  handlePointerUp(event) {
    if (
      !this.dragState.isActive ||
      (this.dragState.pointerId !== null &&
        typeof event.pointerId === "number" &&
        event.pointerId !== this.dragState.pointerId)
    ) {
      return;
    }

    this.endDrag({
      pointerId:
        typeof event.pointerId === "number" ? event.pointerId : null,
      sourceEvent: event,
    });
  }

  handleMouseDown(event) {
    if (this.panMode !== "drag" || !this.container) {
      return;
    }
    if (this.pointerEventsSupported) {
      return;
    }
    if (event.button !== 0) {
      return;
    }

    event.preventDefault();
    this.beginDrag({
      clientX: event.clientX,
      clientY: event.clientY,
      pointerId: "mouse",
      inputType: "mouse",
    });
  }

  handleMouseMove(event) {
    if (
      !this.dragState.isActive ||
      this.dragState.inputType !== "mouse" ||
      !this.container
    ) {
      return;
    }
    if (this.pointerEventsSupported) {
      return;
    }

    event.preventDefault();
    this.updateDrag({
      clientX: event.clientX,
      clientY: event.clientY,
    });
  }

  handleMouseUp() {
    if (!this.dragState.isActive || this.dragState.inputType !== "mouse") {
      return;
    }
    if (this.pointerEventsSupported) {
      return;
    }

    this.endDrag({ pointerId: "mouse" });
  }

  handleTouchStart(event) {
    if (this.panMode !== "drag" || !this.container) {
      return;
    }
    if (this.pointerEventsSupported) {
      return;
    }
    if (!event.touches || !event.touches.length) {
      return;
    }

    const touch = event.touches[0];
    event.preventDefault();
    this.beginDrag({
      clientX: touch.clientX,
      clientY: touch.clientY,
      pointerId: touch.identifier,
      inputType: "touch",
    });
  }

  handleTouchMove(event) {
    if (
      !this.dragState.isActive ||
      this.dragState.inputType !== "touch" ||
      !this.container
    ) {
      return;
    }
    if (this.pointerEventsSupported) {
      return;
    }

    const touch = this.getTrackedTouch(
      event.touches,
      this.dragState.pointerId
    );
    if (!touch) {
      return;
    }

    event.preventDefault();
    this.updateDrag({
      clientX: touch.clientX,
      clientY: touch.clientY,
    });
  }

  handleTouchEnd(event) {
    if (
      !this.dragState.isActive ||
      this.dragState.inputType !== "touch"
    ) {
      return;
    }
    if (this.pointerEventsSupported) {
      return;
    }

    const remainingTouch = this.getTrackedTouch(
      event.touches,
      this.dragState.pointerId
    );
    if (!remainingTouch) {
      this.endDrag({ pointerId: this.dragState.pointerId });
    }
  }

  beginDrag({
    clientX,
    clientY,
    pointerId = null,
    inputType = null,
    sourceEvent = null,
  }) {
    if (!this.container) {
      return;
    }

    if (this.dragState.isActive) {
      this.endDrag();
    }

    this.dragState.isActive = true;
    this.dragState.pointerId = pointerId;
    this.dragState.inputType = inputType || null;
    this.dragState.startX = clientX;
    this.dragState.startY = clientY;
    this.dragState.scrollLeft = this.container.scrollLeft;
    this.dragState.scrollTop = this.container.scrollTop;
    this.dragState.lastClientX = clientX;
    this.dragState.lastClientY = clientY;
    this.dragState.prevScrollBehavior =
      typeof this.container.style.scrollBehavior === "string"
        ? this.container.style.scrollBehavior
        : "";

    if (this.container && this.container.style) {
      this.container.style.scrollBehavior = "auto";
    }

    if (this.container.classList) {
      this.container.classList.add("is-dragging");
    }

    if (
      sourceEvent &&
      typeof sourceEvent.pointerId === "number" &&
      typeof this.container.setPointerCapture === "function"
    ) {
      try {
        this.container.setPointerCapture(sourceEvent.pointerId);
      } catch (err) {
        // Ignore pointer capture errors
      }
    }
  }

  updateDrag({ clientX, clientY }) {
    if (!this.dragState.isActive || !this.container) {
      return;
    }

    const deltaX = clientX - this.dragState.lastClientX;
    const deltaY = clientY - this.dragState.lastClientY;

    if (deltaX) {
      this.container.scrollLeft -= deltaX;
    }
    if (deltaY) {
      this.container.scrollTop -= deltaY;
    }

    this.dragState.lastClientX = clientX;
    this.dragState.lastClientY = clientY;
    this.dragState.scrollLeft = this.container.scrollLeft;
    this.dragState.scrollTop = this.container.scrollTop;
  }

  endDrag({ pointerId = null, sourceEvent = null } = {}) {
    if (!this.dragState.isActive) {
      return;
    }

    if (
      this.dragState.pointerId !== null &&
      pointerId !== null &&
      pointerId !== this.dragState.pointerId
    ) {
      return;
    }

    const capturedPointer = this.dragState.pointerId;

    this.dragState.isActive = false;
    this.dragState.pointerId = null;
    this.dragState.inputType = null;
    this.dragState.lastClientX = 0;
    this.dragState.lastClientY = 0;
    const previousScrollBehavior = this.dragState.prevScrollBehavior;
    this.dragState.prevScrollBehavior = "";

    if (this.container && this.container.classList) {
      this.container.classList.remove("is-dragging");
    }

    if (
      sourceEvent &&
      typeof sourceEvent.pointerId === "number" &&
      typeof this.container.releasePointerCapture === "function"
    ) {
      try {
        this.container.releasePointerCapture(sourceEvent.pointerId);
      } catch (err) {
        // Ignore release errors
      }
    } else if (
      typeof capturedPointer === "number" &&
      typeof this.container.releasePointerCapture === "function"
    ) {
      try {
        this.container.releasePointerCapture(capturedPointer);
      } catch (err) {
        // Ignore release errors
      }
    }

    if (this.container && this.container.style) {
      this.container.style.scrollBehavior = previousScrollBehavior || "";
    }
  }

  getTrackedTouch(touchList, identifier) {
    if (!touchList || identifier === null || typeof identifier === "undefined") {
      return null;
    }
    for (let i = 0; i < touchList.length; i += 1) {
      const touch = touchList[i];
      if (touch.identifier === identifier) {
        return touch;
      }
    }
    return null;
  }

  handleContainerClick(event) {
    if (this.zoomMode !== "click" || !this.container) {
      return;
    }

    if (typeof event.button === "number" && event.button !== 0) {
      return;
    }

    const isZoomOut = event.altKey;
    const hasZoomInModifier = event.metaKey || event.ctrlKey;

    if (!isZoomOut && !hasZoomInModifier) {
      return;
    }

    const focusPoint = this.getFocusPointFromEvent(event);

    const zoomOptions = { animate: true };
    if (focusPoint) {
      zoomOptions.focusX = focusPoint.baseX;
      zoomOptions.focusY = focusPoint.baseY;
      if (typeof focusPoint.pointerOffsetX === "number") {
        zoomOptions.focusOffsetX = focusPoint.pointerOffsetX;
      }
      if (typeof focusPoint.pointerOffsetY === "number") {
        zoomOptions.focusOffsetY = focusPoint.pointerOffsetY;
      }
    }

    if (isZoomOut) {
      event.preventDefault();
      event.stopPropagation();
      const targetZoom = this.currentZoom - this.ZOOM_STEP;
      this.setZoom(targetZoom, zoomOptions);
      return;
    }

    if (hasZoomInModifier) {
      event.preventDefault();
      event.stopPropagation();
      const targetZoom = this.currentZoom + this.ZOOM_STEP;
      this.setZoom(targetZoom, zoomOptions);
    }
  }

  getCenterPoint() {
    if (
      this.manualCenter &&
      Number.isFinite(this.manualCenter.x) &&
      Number.isFinite(this.manualCenter.y)
    ) {
      return this.manualCenter;
    }

    if (this.baseDimensions) {
      return {
        x: this.baseOrigin.x + this.baseDimensions.width / 2,
        y: this.baseOrigin.y + this.baseDimensions.height / 2,
      };
    }

    return { x: 0, y: 0 };
  }

  getVisibleCenterPoint() {
    if (!this.container || !this.baseDimensions || !this.unitsPerCss) {
      return this.getCenterPoint();
    }

    const visibleCenterX =
      this.container.scrollLeft + this.container.clientWidth / 2;
    const visibleCenterY =
      this.container.scrollTop + this.container.clientHeight / 2;

    const cssBaseX = visibleCenterX / (this.currentZoom || 1);
    const cssBaseY = visibleCenterY / (this.currentZoom || 1);

    return {
      x: this.baseOrigin.x + cssBaseX * (this.unitsPerCss.x || 1),
      y: this.baseOrigin.y + cssBaseY * (this.unitsPerCss.y || 1),
    };
  }

  async copyCenterCoordinates() {
    const point = this.getVisibleCenterPoint();
    const message = `${point.x.toFixed(2)}, ${point.y.toFixed(2)}`;

    if (navigator.clipboard && navigator.clipboard.writeText) {
      try {
        await navigator.clipboard.writeText(message);
        this.updateCoordOutput(`Copied: ${message}`);
        return;
      } catch (err) {
        console.warn("Clipboard copy failed", err);
      }
    }

    this.updateCoordOutput(message);
    this.fallbackPrompt(message);
  }

  updateCoordOutput(text) {
    if (!this.coordOutputEl) return;
    this.coordOutputEl.textContent = text;
    clearTimeout(this._coordTimeout);
    this._coordTimeout = setTimeout(() => {
      this.coordOutputEl.textContent = "";
    }, 4000);
  }

  fallbackPrompt(message) {
    if (typeof window !== "undefined" && window.prompt) {
      window.prompt("Copy coordinates", message);
    }
  }

  resolveMinZoom() {
    if (Number.isFinite(this.MIN_ZOOM) && this.MIN_ZOOM > 0) {
      return this.MIN_ZOOM;
    }
    if (
      !this.container ||
      !this.baseCssDimensions ||
      !Number.isFinite(this.baseCssDimensions.width) ||
      !Number.isFinite(this.baseCssDimensions.height) ||
      this.baseCssDimensions.width <= 0 ||
      this.baseCssDimensions.height <= 0
    ) {
      return null;
    }
    const containerWidth = this.container.clientWidth || 0;
    const containerHeight = this.container.clientHeight || 0;
    if (containerWidth <= 0 || containerHeight <= 0) {
      return null;
    }
    const containerDiag = Math.sqrt(
      containerWidth ** 2 + containerHeight ** 2
    );
    const svgDiag = Math.sqrt(
      this.baseCssDimensions.width ** 2 + this.baseCssDimensions.height ** 2
    );
    if (!Number.isFinite(containerDiag) || !Number.isFinite(svgDiag)) {
      return null;
    }
    if (svgDiag <= 0) {
      return null;
    }
    const computedMin = Math.min(1, containerDiag / svgDiag);
    if (Number.isFinite(computedMin) && computedMin > 0) {
      this.MIN_ZOOM = computedMin;
      return this.MIN_ZOOM;
    }
    return null;
  }

  getEffectiveMinZoom() {
    const resolved = this.resolveMinZoom();
    if (Number.isFinite(resolved) && resolved > 0) {
      return resolved;
    }
    if (Number.isFinite(this.MIN_ZOOM) && this.MIN_ZOOM > 0) {
      return this.MIN_ZOOM;
    }
    return null;
  }

  getEffectiveMaxZoom() {
    if (Number.isFinite(this.MAX_ZOOM) && this.MAX_ZOOM > 0) {
      return this.MAX_ZOOM;
    }
    return this.currentZoom || 1;
  }

  getZoomStep() {
    return Number.isFinite(this.ZOOM_STEP) && this.ZOOM_STEP > 0
      ? this.ZOOM_STEP
      : 0.1;
  }

  getZoomTolerance() {
    const step = this.getZoomStep();
    return Math.max(1e-4, step / 1000);
  }

  setManualCenter(x, y, options = {}) {
    const shouldRecenter = Boolean(options.recenter);
    if (Number.isFinite(x) && Number.isFinite(y)) {
      this.manualCenter = { x, y };
      if (shouldRecenter && this.container && this.baseDimensions) {
        this.centerView({ focusX: x, focusY: y });
      }
      return;
    }

    this.manualCenter = null;
    if (shouldRecenter && this.container && this.baseDimensions) {
      this.centerView();
    }
  }

  destroy() {
    this.endDrag();
    if (Array.isArray(this.cleanupHandlers)) {
      while (this.cleanupHandlers.length) {
        const cleanup = this.cleanupHandlers.pop();
        try {
          cleanup();
        } catch (err) {
          // Ignore cleanup errors
        }
      }
      this.cleanupHandlers = [];
    }
    if (this.container) {
      if (this.container.classList) {
        this.container.classList.remove("is-dragging");
      }
      if (this.container.style) {
        this.container.style.touchAction = "";
      }
    }
    if (
      typeof window !== "undefined" &&
      this.wheelAnimationFrame &&
      typeof window.cancelAnimationFrame === "function"
    ) {
      window.cancelAnimationFrame(this.wheelAnimationFrame);
      this.wheelAnimationFrame = null;
    }
    if (
      typeof window !== "undefined" &&
      this.zoomAnimationFrame &&
      typeof window.cancelAnimationFrame === "function"
    ) {
      window.cancelAnimationFrame(this.zoomAnimationFrame);
      this.zoomAnimationFrame = null;
    }
    this.wheelDeltaBuffer = 0;
    this.wheelFocusPoint = null;
    this.dragListenersAttached = false;
  }

  computeZoomTarget(direction) {
    const step = this.getZoomStep();
    const tolerance = this.getZoomTolerance();
    const maxZoom = this.getEffectiveMaxZoom();
    const minZoom =
      this.getEffectiveMinZoom() ?? Math.max(0, this.currentZoom - step);

    if (direction === "in") {
      const remaining = maxZoom - this.currentZoom;
      if (remaining <= step + tolerance) {
        return maxZoom;
      }
      return Math.min(maxZoom, this.currentZoom + step);
    }

    const available = this.currentZoom - minZoom;
    if (available <= step + tolerance) {
      return minZoom;
    }
    return Math.max(minZoom, this.currentZoom - step);
  }

  updateZoomButtonState() {
    if (!Array.isArray(this.zoomInButtons) || !Array.isArray(this.zoomOutButtons)) {
      return;
    }
    const tolerance = this.getZoomTolerance();
    const maxZoom = this.getEffectiveMaxZoom();
    const minZoom = this.getEffectiveMinZoom();

    const canZoomIn =
      maxZoom - this.currentZoom > tolerance && maxZoom > 0;
    const canZoomOut =
      minZoom === null
        ? true
        : this.currentZoom - minZoom > tolerance;

    this.toggleButtonState(this.zoomInButtons, canZoomIn);
    this.toggleButtonState(this.zoomOutButtons, canZoomOut);
  }

  toggleButtonState(buttons, enabled) {
    if (!Array.isArray(buttons)) {
      return;
    }
    buttons.forEach((button) => {
      if (!button) {
        return;
      }
      if (enabled) {
        button.disabled = false;
        button.classList.remove("is-disabled");
        button.removeAttribute("aria-disabled");
      } else {
        button.disabled = true;
        button.classList.add("is-disabled");
        button.setAttribute("aria-disabled", "true");
      }
    });
  }
}
// Export for global use
window.SVGViewer = SVGViewer;

// Support CommonJS/ESM consumers (e.g., unit tests).
if (typeof module !== "undefined" && module.exports) {
  module.exports = SVGViewer;
}
