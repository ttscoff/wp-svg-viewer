
# WP SVG Viewer

Embed large SVG diagrams in WordPress with zoom, pan, center, and authoring tools. Recent releases add a visual preset editor, icon-based controls, deeper shortcode options, and configurable button colors.

---

## Contents

- [Contents](#contents)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Shortcode Reference](#shortcode-reference)
  - [`controls_buttons` Cheat Sheet](#controls_buttons-cheat-sheet)
- [Admin Preset Editor](#admin-preset-editor)
  - [Location](#location)
  - [Fields](#fields)
  - [Preview Pane](#preview-pane)
  - [Preset Shortcodes](#preset-shortcodes)
- [Using Presets in Posts/Pages](#using-presets-in-postspages)
- [Preview Workflow](#preview-workflow)
- [Examples](#examples)
- [Styling (CSS Hooks)](#styling-css-hooks)
- [Tips \& Troubleshooting](#tips--troubleshooting)
  - [SVG Preparation](#svg-preparation)
  - [Common Issues](#common-issues)
  - [Debugging](#debugging)
- [Changelog Highlights (1.0.1)](#changelog-highlights-101)
- [License \& Credits](#license--credits)

---

## Installation

1. **Unzip the plugin archive**
   - Download [wp-svg-viewer.zip](github.com/ttscoff/wp-svg-viewer/releases/latest/download/wp-svg-viewer.zip).
   - Unzip it locally; you will get a folder named `wp-svg-viewer`.
2. **Upload the plugin**
   - Copy the entire `wp-svg-viewer` folder into your WordPress installation at `/wp-content/plugins/`.
3. **Activate**
   - In the WordPress admin, navigate to **Plugins** and click **Activate** on ???SVG Viewer???.

---

## Quick Start

```text
[svg_viewer src="/wp-content/uploads/diagrams/system-map.svg"]
```

- Place the shortcode in a classic editor, Gutenberg shortcode block, or template.
- The SVG renders with default height (600px), zoom controls, pan/scroll behaviour, keyboard shortcuts, and responsive layout. Zoom buttons now gray out at the minimum/maximum zoom to make the limits obvious to visitors.

---

## Shortcode Reference

| Attribute                                          | Type                                | Default                   | Description                                                                                                                                      |
| -------------------------------------------------- | ----------------------------------- | ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| `src`                                              | string (required)                   | ???                         | SVG URL. Supports absolute URLs, `/absolute/path.svg`, or relative to the uploads directory.                                                     |
| `height`                                           | string                              | `600px`                   | CSS height of the viewer. Accepts px, vh, %, etc.                                                                                                |
| `class`                                            | string                              | ???                         | Additional class appended to the wrapper.                                                                                                        |
| `zoom`                                             | number                              | `100`                     | Initial zoom percentage.                                                                                                                         |
| `min_zoom`                                         | number                              | `25`                      | Minimum zoom percentage allowed.                                                                                                                 |
| `max_zoom`                                         | number                              | `800`                     | Maximum zoom percentage allowed.                                                                                                                 |
| `zoom_step`                                        | number                              | `10`                      | Increment used by buttons/keyboard shortcuts.                                                                                                    |
| `initial_zoom`                                     | number                              | ???                         | Alias captured when presets save the preview state. Overrides `zoom` if present.                                                                 |
| `pan` / `pan_mode`                                 | `scroll` or `drag`                  | `scroll`                  | Toggle between scroll-wheel panning and click-drag panning. Drag is enforced when zoom modes require it.                                         |
| `zoom_mode` / `zoom_behavior` / `zoom_interaction` | `super_scroll` / `scroll` / `click` | `super_scroll`            | Choose how wheel and modifier gestures zoom: Cmd/Ctrl + wheel (`super_scroll`), every wheel (`scroll`), or Cmd/Ctrl-click & Alt-click (`click`). |
| `center_x` / `center_y`                            | number                              | ???                         | Manual center point in SVG units. Defaults to viewBox center.                                                                                    |
| `show_coords`                                      | boolean                             | `false`                   | Appends ???Copy Center??? button for debugging coordinate values.                                                                                    |
| `controls_position`                                | `top`/`bottom`/`left`/`right`       | `top`                     | Placement of the entire control group.                                                                                                           |
| `controls_buttons`                                 | string                              | `both`                    | Comma-delimited mode/align/button list. See table below (supports `slider`).                                                                     |
| `title`                                            | string                              | ???                         | Optional heading above the viewer. HTML allowed.                                                                                                 |
| `caption`                                          | string                              | ???                         | Optional caption below the viewer. HTML allowed.                                                                                                 |
| `button_fill` / `button_background` / `button_bg`  | color string                        | theme default (`#0073aa`) | Button background color. Aliases exist for backwards compatibility (all map to `button_fill`).                                                   |
| `button_border`                                    | color string                        | matches fill              | Outline color for buttons. Blank inherits the fill color.                                                                                        |
| `button_foreground` / `button_fg`                  | color string                        | `#ffffff`                 | Text and icon color for buttons. Blank uses the default.                                                                                         |
| `id`                                               | number                              | ???                         | Reference a saved preset (admin). Inline attributes override preset values.                                                                      |

> Changing the interaction defaults automatically inserts a helper caption (e.g. ???Cmd/Ctrl-click to zoom in??????) above your custom caption so visitors know the gesture.

### `controls_buttons` Cheat Sheet

```text
controls_buttons="MODE,ALIGNMENT,BUTTON_1,BUTTON_2,..."
```

Mode keywords (optional):

- `both` (default)
- `icon`
- `text`
- `compact`
- `labels_on_hover`
- `minimal`
- `hidden` / `none`

Alignment keywords (optional, anywhere in list):

- `alignleft`
- `aligncenter`
- `alignright`

Additional keywords:

- `slider` ??? replaces zoom buttons with a live-updating range input. Combine with `icon`/`text`/`both` for layout. Use `custom:slider,zoom_in,zoom_out` to show both slider and buttons.

Button names (pick any order):

- `zoom_in`, `zoom_out`, `reset`, `center`, `coords` *(coords button only renders if `show_coords="true"`)*

Example:

```text
[svg_viewer
  src="/uploads/system-map.svg"
  controls_position="right"
  controls_buttons="slider,icon,aligncenter,reset,center"
]
```

---

## Admin Preset Editor

### Location

`WordPress Dashboard ??? SVG Viewer ??? Presets`

### Fields

| Field                       | Description                                                                    |
| --------------------------- | ------------------------------------------------------------------------------ |
| **SVG Source URL**          | Choose or upload an SVG from the media library. Required before preview loads. |
| **Viewer Height**           | Same as shortcode `height`.                                                    |
| **Zoom Settings**           | Minimum, maximum, step, and initial zoom percentages.                          |
| **Center Coordinates**      | Override auto centering with explicit `center_x`/`center_y`.                   |
| **Controls Position**       | Dropdown for `top`, `bottom`, `left`, `right`.                                 |
| **Controls Buttons/Layout** | Text field following the `MODE,ALIGN,buttons???` pattern described above.        |
| **Button Colors**           | Three color pickers for fill, border, and foreground (text/icon) colors.       |
| **Title & Caption**         | Optional, displayed above/below the viewer wrapper.                            |

### Preview Pane

- **Load / Refresh Preview**: Injects the SVG using the current field values.
- **Use Current View for Initial State**: Captures the visible zoom level and center point from the preview and writes them back into the form (ideal for fine-tuning coordinates visually).
- **Copy Center**: When `show_coords` is enabled, the button copies coordinates to the clipboard.
- Zoom controls in the preview (and front end) now snap to their minimum/maximum, disable the corresponding buttons, and keep your pointer-focused zoom locked to the selected point???what you see in the preview is exactly what site visitors experience.

### Preset Shortcodes

- At the top of the preset editor and in the presets list table you???ll find a copy-ready snippet in the form of `[svg_viewer id="123"]`.
- Click **Copy** to put the shortcode on the clipboard without selecting manually.

### Defaults Tab

- Visit **SVG Viewer ??? Presets ??? Defaults** to seed the fields used when creating a new preset.
- The panel now includes **Enable asset cache busting for debugging**, which appends a time-based suffix to scripts and styles. It is automatically active on hosts that start with `dev.` or `wptest.` and can be toggled manually when you need to defeat browser caching.

---

## Using Presets in Posts/Pages

1. Create or edit a preset as described above.
2. Copy the generated shortcode (`[svg_viewer id="123"]`).
3. Paste into:
   - Classic editor (Visual tab: use Shortcode block or paste directly).
   - Block editor (Shortcode block or HTML block).
   - Template files via `do_shortcode()`.
4. Override on a per-use basis if needed:

   ```text
   [svg_viewer id="123" controls_buttons="icon,alignright,zoom_in,zoom_out"]
   ```

---

## Preview Workflow

1. **Load / Refresh Preview** once the SVG path is entered.
2. Drag or scroll the SVG to the desired default view.
3. **Use Current View for Initial State** to stash those coordinates/zoom.
4. Optionally toggle `show_coords` to display the current x/y values of the viewport center.
5. Save/publish the preset and test the shortcode on the front end.

---

## Examples

```text
[svg_viewer src="/uploads/floorplan.svg"]
```

```text
[svg_viewer
  src="/uploads/system-map.svg"
  height="100vh"
  controls_position="bottom"
  controls_buttons="compact,aligncenter,zoom_in,zoom_out,reset,center"
  zoom="175"
  min_zoom="50"
  max_zoom="400"
]
```

```text
[svg_viewer
  id="42"
  controls_buttons="custom:slider,zoom_in,zoom_out,reset"
]
```

```text
[svg_viewer
  src="/uploads/mind-map.svg"
  show_coords="true"
  controls_buttons="icon,alignleft,zoom_in,zoom_out,reset,center,coords"
]
```

```text
[svg_viewer
  src="/uploads/campus-map.svg"
  button_bg="#2f855a"
  button_border="#22543d"
  button_fg="#ffffff"
  controls_buttons="both,aligncenter,zoom_in,zoom_out,reset,center"
]
```

---

## Styling (CSS Hooks)

Wrapper classes added by the plugin:

- `.svg-viewer-wrapper` ??? outer container
- `.svg-viewer-main` ??? wraps controls and SVG container
- `.svg-controls` ??? control bar
- `.controls-position-{top|bottom|left|right}`
- `.controls-mode-{icon|text|both|compact|labels-on-hover|minimal}`
- `.controls-align-{alignleft|aligncenter|alignright}`
- `.svg-viewer-btn`, `.btn-icon`, `.btn-text`
- `.svg-container`, `.svg-viewport`, `.coord-output`, `.zoom-display`
- `.zoom-slider-wrapper`, `.zoom-slider`

Button colors are powered by CSS custom properties on the wrapper. Shortcode attributes and preset color pickers set these values, but you can override them manually:

```css
.svg-viewer-wrapper {
  --svg-viewer-button-fill: #1d4ed8;
  --svg-viewer-button-border: #1d4ed8;
  --svg-viewer-button-hover: #1e40af;
  --svg-viewer-button-text: #ffffff;
  --svg-viewer-slider-width: 240px;       /* optional: tweak slider width */
}
```

Example overrides:

```css
.svg-viewer-wrapper.custom-map {
  border-radius: 12px;
  overflow: hidden;
}

.svg-viewer-wrapper.custom-map .svg-controls {
  background: #111;
  color: #fff;
  gap: 6px;
}

.svg-viewer-wrapper.custom-map .svg-viewer-btn {
  background: rgba(255,255,255,0.1);
  border: 1px solid rgba(255,255,255,0.3);
}

.svg-viewer-wrapper.custom-map .svg-viewer-btn:hover {
  background: rgba(255,255,255,0.25);
}
```

---

## Tips & Troubleshooting

### SVG Preparation

- Remove hard-coded width/height if possible; rely on `viewBox`.
- Ensure the SVG renders correctly when opened directly in a browser.
- Compress large SVGs to keep loading snappy.

### Common Issues

- **Blank viewer**: check for 404 on the SVG path, confirm the file is accessible without authentication.
- **Scaling looks wrong**: verify the SVG has an accurate `viewBox`.
- **Controls disappear**: check `controls_buttons` for `hidden` or empty button list.
- **Clipboard errors**: Some browsers require user interaction for `Copy Center`; the plugin falls back to a prompt.

### Debugging

- Toggle `show_coords="true"` or inspect `window.svgViewerInstances['viewer-id']` to troubleshoot zoom, center, or scroll behaviour.
- Use the Defaults tab???s **Enable asset cache busting for debugging** switch if your browser clings to stale copies of the viewer script or styles.

---

## Changelog Highlights (1.1.0)

- **Pan/Zoom Interaction Modes**: Shortcode and presets can now request `pan_mode="drag"` or `zoom_mode="scroll"` / `click"`, with the front end auto-explaining gesture hints to visitors.
- **Smooth Cursor-Focused Zoom**: Wheel, slider, and modifier-click zoom animate between stops and keep the point under the pointer locked in place.
- **Responsive Drag Panning**: Dragging now tracks 1:1 with the pointer and ignores stray wheel events so diagonal swipes stay fluid.
- **Dev-Friendly Cache Busting**: The Defaults tab adds an ???Enable asset cache busting??? switch (also auto-enabled for `dev.*` and `wptest.*` hosts) to force fresh JS/CSS while testing.

Full changelog lives in the repository???s `CHANGELOG.md`.

---

## License & Credits

- License: GPL-2.0 (same as WordPress).
- Built to make large interactive diagrams pleasant to navigate inside WordPress.
