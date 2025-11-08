/**
 * SVG Viewer Plugin
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

    // State
    this.currentZoom = this.initialZoom;
    this.svgElement = null;
    this.isLoading = false;
    this.baseDimensions = null;
    this.baseOrigin = { x: 0, y: 0 };
    this.baseCssDimensions = null;
    this.unitsPerCss = { x: 1, y: 1 };

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

    this.init();
  }

  init() {
    this.setupEventListeners();
    this.updateZoomDisplay();
    this.updateViewport();
    this.loadSVG();
  }

  setupEventListeners() {
    // Button clicks
    this.wrapper
      .querySelectorAll('[data-viewer="' + this.viewerId + '"].zoom-in-btn')
      .forEach((btn) => {
        btn.addEventListener("click", () => this.zoomIn());
      });

    this.wrapper
      .querySelectorAll('[data-viewer="' + this.viewerId + '"].zoom-out-btn')
      .forEach((btn) => {
        btn.addEventListener("click", () => this.zoomOut());
      });

    this.wrapper
      .querySelectorAll('[data-viewer="' + this.viewerId + '"].reset-zoom-btn')
      .forEach((btn) => {
        btn.addEventListener("click", () => this.resetZoom());
      });

    this.wrapper
      .querySelectorAll('[data-viewer="' + this.viewerId + '"].center-view-btn')
      .forEach((btn) => {
        btn.addEventListener("click", () => this.centerView());
      });

    if (this.showCoordinates) {
      this.wrapper
        .querySelectorAll(
          '[data-viewer="' + this.viewerId + '"].coord-copy-btn'
        )
        .forEach((btn) => {
          btn.addEventListener("click", () => this.copyCenterCoordinates());
        });
    }

    // Keyboard shortcuts
    document.addEventListener("keydown", (e) => this.handleKeyboard(e));

    // Mouse wheel zoom
    this.container.addEventListener("wheel", (e) => this.handleMouseWheel(e));
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
      console.error("SVG Viewer Error:", error);
      this.viewport.innerHTML =
        '<div style="padding: 20px; color: red;">Error loading SVG. Check the file path and ensure CORS is configured if needed.</div>';
    }

    this.isLoading = false;
  }

  setZoom(newZoom, options = {}) {
    if (!this.container || !this.viewport) {
      this.currentZoom = Math.max(
        this.MIN_ZOOM,
        Math.min(this.MAX_ZOOM, newZoom)
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

    if (!this.MIN_ZOOM) {
      const containerDiag = Math.sqrt(
        this.container.clientWidth ** 2 + this.container.clientHeight ** 2
      );
      const svgDiag = Math.sqrt(
        this.baseCssDimensions.width ** 2 + this.baseCssDimensions.height ** 2
      );
      this.MIN_ZOOM = Math.min(1, containerDiag / svgDiag);
    }

    let focusBaseX;
    let focusBaseY;

    if (
      typeof options.focusX === "number" &&
      typeof options.focusY === "number"
    ) {
      focusBaseX = options.focusX;
      focusBaseY = options.focusY;
    } else {
      const visibleCenterX =
        this.container.scrollLeft + this.container.clientWidth / 2;
      const visibleCenterY =
        this.container.scrollTop + this.container.clientHeight / 2;

      const cssBaseX = visibleCenterX / prevZoom;
      const cssBaseY = visibleCenterY / prevZoom;

      focusBaseX = this.baseOrigin.x + cssBaseX * (this.unitsPerCss.x || 1);
      focusBaseY = this.baseOrigin.y + cssBaseY * (this.unitsPerCss.y || 1);
    }

    this.currentZoom = Math.max(
      this.MIN_ZOOM,
      Math.min(this.MAX_ZOOM, newZoom)
    );

    this.updateViewport(options);
    this.updateZoomDisplay();

    const newScrollWidth = this.container.scrollWidth;
    const newScrollHeight = this.container.scrollHeight;

    if (!options.center && newScrollWidth && newScrollHeight) {
      const cssCenterXAfter =
        ((focusBaseX - this.baseOrigin.x) / (this.unitsPerCss.x || 1)) *
        this.currentZoom;
      const cssCenterYAfter =
        ((focusBaseY - this.baseOrigin.y) / (this.unitsPerCss.y || 1)) *
        this.currentZoom;

      const targetLeft = cssCenterXAfter - this.container.clientWidth / 2;
      const targetTop = cssCenterYAfter - this.container.clientHeight / 2;

      this._debugLastZoom = {
        focusBaseX,
        focusBaseY,
        cssCenterXAfter,
        cssCenterYAfter,
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
  }

  zoomIn() {
    this.setZoom(this.currentZoom + this.ZOOM_STEP);
  }

  zoomOut() {
    this.setZoom(this.currentZoom - this.ZOOM_STEP);
  }

  resetZoom() {
    this.setZoom(this.initialZoom || 1, { center: true });
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

  handleMouseWheel(e) {
    if (e.ctrlKey || e.metaKey) {
      e.preventDefault();
      const direction = e.deltaY > 0 ? -1 : 1;
      if (!this.container) {
        this.setZoom(this.currentZoom + direction * this.ZOOM_STEP);
        return;
      }

      const rect = this.container.getBoundingClientRect();
      const cursorX = e.clientX - rect.left + this.container.scrollLeft;
      const cursorY = e.clientY - rect.top + this.container.scrollTop;

      const focusBaseX =
        this.baseOrigin.x +
        (cursorX / this.currentZoom) * (this.unitsPerCss.x || 1);
      const focusBaseY =
        this.baseOrigin.y +
        (cursorY / this.currentZoom) * (this.unitsPerCss.y || 1);

      this.setZoom(this.currentZoom + direction * this.ZOOM_STEP, {
        focusX: focusBaseX,
        focusY: focusBaseY,
      });
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
}

// Export for global use
window.SVGViewer = SVGViewer;
