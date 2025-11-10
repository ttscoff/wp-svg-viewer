import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import SVGViewer from "../../bt-svg-viewer/js/bt-svg-viewer.js";

describe("SVGViewer core behavior", () => {
	beforeEach(() => {
		document.body.innerHTML = `
			<div id="viewer-1" class="bt-svg-viewer-wrapper">
				<div class="bt-svg-viewer-main" data-viewer="viewer-1">
					<div class="svg-controls controls-mode-expanded" data-viewer="viewer-1">
						<button class="bt-svg-viewer-btn zoom-in-btn" data-viewer="viewer-1"></button>
						<button class="bt-svg-viewer-btn zoom-out-btn" data-viewer="viewer-1"></button>
						<button class="bt-svg-viewer-btn reset-zoom-btn" data-viewer="viewer-1"></button>
						<button class="bt-svg-viewer-btn center-view-btn" data-viewer="viewer-1"></button>
						<span class="zoom-percentage" data-viewer="viewer-1"></span>
					</div>
					<div class="svg-container" data-viewer="viewer-1">
						<div class="svg-viewport" data-viewer="viewer-1"></div>
					</div>
				</div>
			</div>
		`;
	});

	afterEach(() => {
		vi.restoreAllMocks();
		document.body.innerHTML = "";
	});

	it("normalizes interaction modes", () => {
		expect(SVGViewer.normalizePanMode("Drag")).toBe("drag");
		expect(SVGViewer.normalizePanMode("scroll")).toBe("scroll");
		expect(SVGViewer.normalizeZoomMode("CLICK")).toBe("click");
		expect(SVGViewer.normalizeZoomMode("super scroll")).toBe("super_scroll");
	});

	it("initializes with provided options and loads SVG", async () => {
		const viewer = new SVGViewer({
			viewerId: "viewer-1",
			svgUrl: "https://example.com/test.svg",
			initialZoom: 1,
			minZoom: 0.5,
			maxZoom: 4,
			zoomStep: 0.25,
			showCoordinates: false,
			panMode: "drag",
			zoomMode: "scroll",
		});

		await Promise.resolve();

		expect(global.fetch).toHaveBeenCalledWith("https://example.com/test.svg");
		expect(viewer.currentZoom).toBe(1);
		expect(
			document
				.querySelector('[data-viewer="viewer-1"].zoom-percentage')
				.textContent.trim()
		).toContain("100");
	});

	it("enforces zoom bounds through zoomIn/zoomOut helpers", () => {
		const viewer = new SVGViewer({
			viewerId: "viewer-1",
			svgUrl: "https://example.com/test.svg",
			initialZoom: 1,
			minZoom: 0.5,
			maxZoom: 1.5,
			zoomStep: 0.5,
			showCoordinates: false,
			panMode: "drag",
			zoomMode: "scroll",
		});

		viewer.zoomIn();
		expect(viewer.currentZoom).toBeCloseTo(1.5, 5);
		viewer.zoomIn();
		expect(viewer.currentZoom).toBeCloseTo(1.5, 5);

		viewer.zoomOut();
		expect(viewer.currentZoom).toBeCloseTo(1.0, 5);
		viewer.zoomOut();
		expect(viewer.currentZoom).toBeCloseTo(0.5, 5);
	});
});

