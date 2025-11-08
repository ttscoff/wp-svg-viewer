# WP SVG Viewer

Embed large SVG diagrams in WordPress with zoom, pan, center, and authoring tools. Version 1.0.1 introduces a visual preset editor, icon-based controls, and deeper shortcode options.

---

## Contents

1. [Installation](#installation)
2. [Quick Start](#quick-start)
3. [Shortcode Reference](#shortcode-reference)
4. [Admin Preset Editor](#admin-preset-editor)
5. [Using Presets in Posts/Pages](#using-presets-in-postspages)
6. [Preview Workflow](#preview-workflow)
7. [Examples](#examples)
8. [Styling (CSS Hooks)](#styling-css-hooks)
9. [Tips & Troubleshooting](#tips--troubleshooting)
10. [Changelog Highlights](#changelog-highlights-101)

---

## Installation

1. **Unzip the plugin archive**
   - Download `wp-svg-viewer.zip`.
   - Unzip it locally; you will get a folder named `wp-svg-viewer`.
2. **Upload the plugin**
   - Copy the entire `wp-svg-viewer` folder into your WordPress installation at `/wp-content/plugins/`.
3. **Activate**
   - In the WordPress admin, navigate to **Plugins** and click **Activate** on “SVG Viewer”.

---

## Quick Start

```text
[svg_viewer src="/wp-content/uploads/diagrams/system-map.svg"]
```

- Place the shortcode in a classic editor, Gutenberg shortcode block, or template.
- The SVG renders with default height (600px), zoom controls, pan/scroll behaviour, keyboard shortcuts, and responsive layout.

---

## Shortcode Reference

| Attribute               | Type                          | Default | Description                                                                                  |
| ----------------------- | ----------------------------- | ------- | -------------------------------------------------------------------------------------------- |
| `src`                   | string (required)             | –       | SVG URL. Supports absolute URLs, `/absolute/path.svg`, or relative to the uploads directory. |
| `height`                | string                        | `600px` | CSS height of the viewer. Accepts px, vh, %, etc.                                            |
| `class`                 | string                        | –       | Additional class appended to the wrapper.                                                    |
| `zoom`                  | number                        | `100`   | Initial zoom percentage.                                                                     |
| `min_zoom`              | number                        | `25`    | Minimum zoom percentage allowed.                                                             |
| `max_zoom`              | number                        | `800`   | Maximum zoom percentage allowed.                                                             |
| `zoom_step`             | number                        | `10`    | Increment used by buttons/keyboard shortcuts.                                                |
| `center_x` / `center_y` | number                        | –       | Manual center point in SVG units. Defaults to viewBox center.                                |
| `show_coords`           | boolean                       | `false` | Appends “Copy Center” button for debugging coordinate values.                                |
| `controls_position`     | `top`/`bottom`/`left`/`right` | `top`   | Placement of the entire control group.                                                       |
| `controls_buttons`      | string                        | `both`  | Comma-delimited mode/align/button list. See table below.                                     |
| `title`                 | string                        | –       | Optional heading above the viewer. HTML allowed.                                             |
| `caption`               | string                        | –       | Optional caption below the viewer. HTML allowed.                                             |
| `id`                    | number                        | –       | Reference a saved preset (admin). Inline attributes override preset values.                  |

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

Button names (pick any order):

- `zoom_in`, `zoom_out`, `reset`, `center`, `coords` *(coords button only renders if `show_coords="true"`)*

Example:

```text
[svg_viewer
  src="/uploads/system-map.svg"
  controls_position="right"
  controls_buttons="icon,aligncenter,zoom_in,zoom_out,reset,center"
]
```

---

## Admin Preset Editor

### Location

`WordPress Dashboard → SVG Viewer → Presets`

### Fields

| Field                       | Description                                                                    |
| --------------------------- | ------------------------------------------------------------------------------ |
| **SVG Source URL**          | Choose or upload an SVG from the media library. Required before preview loads. |
| **Viewer Height**           | Same as shortcode `height`.                                                    |
| **Zoom Settings**           | Minimum, maximum, step, and initial zoom percentages.                          |
| **Center Coordinates**      | Override auto centering with explicit `center_x`/`center_y`.                   |
| **Controls Position**       | Dropdown for `top`, `bottom`, `left`, `right`.                                 |
| **Controls Buttons/Layout** | Text field following the `MODE,ALIGN,buttons…` pattern described above.        |
| **Title & Caption**         | Optional, displayed above/below the viewer wrapper.                            |

### Preview Pane

- **Load / Refresh Preview**: Injects the SVG using the current field values.
- **Use Current View for Initial State**: Captures the visible zoom level and center point from the preview and writes them back into the form (ideal for fine-tuning coordinates visually).
- **Copy Center**: When `show_coords` is enabled, the button copies coordinates to the clipboard.

### Preset Shortcodes

- At the top of the preset editor and in the presets list table you’ll find a copy-ready snippet in the form of `[svg_viewer id="123"]`.
- Click **Copy** to put the shortcode on the clipboard without selecting manually.

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
  controls_buttons="icon,alignright,zoom_in,zoom_out"
]
```

```text
[svg_viewer
  src="/uploads/mind-map.svg"
  show_coords="true"
  controls_buttons="icon,alignleft,zoom_in,zoom_out,reset,center,coords"
]
```

---

## Styling (CSS Hooks)

Wrapper classes added by the plugin:

- `.svg-viewer-wrapper` – outer container
- `.svg-viewer-main` – wraps controls and SVG container
- `.svg-controls` – control bar
- `.controls-position-{top|bottom|left|right}`
- `.controls-mode-{icon|text|both|compact|labels-on-hover|minimal}`
- `.controls-align-{alignleft|aligncenter|alignright}`
- `.svg-viewer-btn`, `.btn-icon`, `.btn-text`
- `.svg-container`, `.svg-viewport`, `.coord-output`, `.zoom-display`

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

Toggle `show_coords="true"` or inspect `window.svgViewerInstances['viewer-id']` to troubleshoot zoom, center, or scroll behaviour.

---

## Changelog Highlights (1.0.1)

- **Visual Preset Editor**: Upload SVGs, configure zoom/center, style controls, and copy shortcodes without touching code.
- **Font Awesome Icons**: Control buttons use embeddable SVG glyphs for crisp rendering.
- **Alignment Keywords**: `alignleft`, `aligncenter`, `alignright` control button placement independently from bar position.
- **Vertical Layout Improvements**: Left/right control stacks get proper spacing and alignment.
- **Sanitized Inline SVG**: All icons pass through `wp_kses` to keep output safe.

Full changelog lives in the repository’s `CHANGELOG.md`.

---

## License & Credits

- License: GPL-2.0 (same as WordPress).
- Built to make large interactive diagrams pleasant to navigate inside WordPress.
