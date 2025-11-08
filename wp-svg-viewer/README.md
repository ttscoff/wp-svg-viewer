# WP SVG Viewer

A WordPress plugin that provides an interactive SVG viewer with zoom, pan, and centering controls.

## Installation

1. **Unzip the plugin archive:**
   - Download the `wp-svg-viewer.zip` file.
   - Unzip it locally; you will get a folder named `wp-svg-viewer`.

2. **Install the plugin files:**
   - Upload or move the entire `wp-svg-viewer` folder into your WordPress installation at `/wp-content/plugins/`.

3. **Activate the plugin:**
   - Go to WordPress Admin → Plugins
   - Find "SVG Viewer" and click "Activate"

## Usage

### Basic Usage

Add this shortcode to any page or post:

```text
[svg_viewer src="/path/to/your/file.svg"]
```

### Advanced Usage

You can customize the viewer with additional parameters:

```text
[svg_viewer src="/path/to/file.svg" height="800px" class="custom-class" zoom="150" max_zoom="1200" zoom_step="25" center_x="2750" center_y="35500" show_coords="true" title="System Settings Overview" caption="Scroll and zoom to explore each configuration area."]
```

### Parameters

- **src** (required): Path to your SVG file
  - Absolute URL: `https://example.com/files/chart.svg`
  - Absolute path: `/uploads/2024/chart.svg`
  - Relative to uploads: `2024/chart.svg`

- **height** (optional): Height of the viewer container
  - Default: `600px`
  - Examples: `height="800px"`, `height="100vh"`

- **class** (optional): Additional CSS class for custom styling
  - Example: `class="my-custom-class"`

- **zoom** (optional): Initial zoom level (percentage)
  - Default: `100`
  - Example: `zoom="200"` starts the viewer at 200%

- **min_zoom** (optional): Minimum allowed zoom level (percentage)
  - Default: `25`
  - Example: `min_zoom="10"`

- **max_zoom** (optional): Maximum allowed zoom level (percentage)
  - Default: `800`
  - Example: `max_zoom="1600"`

- **zoom_step** (optional): Increment used when zooming (percentage)
  - Default: `10`
  - Example: `zoom_step="25"`

- **center_x** / **center_y** (optional): Override the point treated as the viewer's "center"
  - Units: SVG user units (usually pixels as defined by the SVG viewBox)
  - Example: `center_x="2750" center_y="35500"`
  - When omitted, the plugin uses the geometric center of the SVG's viewBox

- **show_coords** (optional): Display a helper button that copies the current viewport center coordinates
  - Default: `false`
  - Example: `show_coords="true"`

- **controls_position** (optional): Placement of the zoom controls relative to the viewer
  - Default: `top`
  - Options: `top`, `bottom`, `left`, `right`
  - Example: `controls_position="left"`

- **controls_buttons** (optional): Style, alignment, and ordering of the control buttons
  - Default: `both`
  - Built-in modes: `both`, `icon`, `text`, `compact`, `labels_on_hover`, `minimal`, `hidden` / `none`
- Alignment keywords (`alignleft`, `aligncenter`, `alignright`) can appear anywhere in the list
  - Custom format: `TYPE,ALIGNMENT,BUTTON_1,BUTTON_2,…` where `TYPE` is optional (`icon`, `text`, `both`, etc.) and buttons are any of `zoom_in`, `zoom_out`, `reset`, `center`, `coords`
  - Example: `controls_buttons="icon,centered,zoom_in,zoom_out,center"` shows centered icon-only controls in the specified order
  - The `coords` button only appears when `show_coords="true"`

- **id** (optional): Load saved settings from an admin preset (see “Presets & Admin Workflow” below)
  - Example: `id="25"`
  - Any shortcode attributes you include will override the preset’s stored values for that instance

- **title** (optional): Text displayed above the viewer inside `.svg-viewer-title`
  - Default: *(no title)*
  - Accepts plain text or basic HTML formatting

- **caption** (optional): Text displayed below the viewer inside `.svg-viewer-caption`
  - Default: *(no caption)*
  - Accepts plain text or basic HTML formatting

### Examples

#### Example 1: Basic SVG in uploads folder

```text
[svg_viewer src="2024/system-settings.svg"]
```

#### Example 2: Custom height

```text
[svg_viewer src="/wp-content/uploads/diagrams/chart.svg" height="1000px"]
```

#### Example 3: Full viewport height

```text
[svg_viewer src="my-diagram.svg" height="100vh"]
```

#### Example 4: Deep zoom and custom start

```text
[svg_viewer src="blueprint.svg" height="700px" zoom="250" max_zoom="2000" zoom_step="5"]
```

#### Example 5: Manual center with coordinate helper

```text
[svg_viewer src="mind-map.svg" height="750px" zoom="150" max_zoom="1600" center_x="2750" center_y="35500" show_coords="true"]
```

This loads the SVG zoomed to 150%, centers on the specified node, and adds a "Copy Center" button.

#### Example 6: Title and caption

```text
[svg_viewer src="seating-chart.svg" title="Conference Seating Chart" caption="<em>Tip:</em> Use the zoom controls to inspect each section."]
```

Adds a bold, centered title above the viewer and a caption below it.

#### Example 7: Using a saved preset

```text
[svg_viewer id="42"]
```

Loads the configuration saved in the admin preset with ID `42`. You can still override individual options inline, e.g. `[svg_viewer id="42" zoom="200"]`.

#### Example 8: Icon-only controls stacked on the right

```text
[svg_viewer src="floorplan.svg" controls_position="right" controls_buttons="icon,zoom_in,zoom_out,reset,center"]
```

Displays a vertical column of icon-only controls on the right edge of the viewer.

## Features

### Zoom Controls

- Zoom In/Out buttons
- Reset Zoom button
- Keyboard shortcuts (Ctrl/Cmd + Plus/Minus/0)
- Mouse wheel zooming (Ctrl/Cmd + scroll)

### Pan Controls

- Scrollable container
- Click and drag to pan (when zoomed)

### Center View

- "Center View" button recenters the SVG without altering the zoom
- Automatically recenters on initial load
- Optional "Copy Center" helper can expose live focus coordinates for authoring presets

### Display

- Shows current zoom percentage (reflects initial zoom setting)
- Responsive design
- Mobile-friendly controls
- Optional title above and caption below the viewer, both centered and bold with hookable classes
- Flexible controls placement (top, bottom, left, right) with text, icon, compact, hover-label, and custom button modes
- Admin presets for reusing viewer settings without repeating shortcode attributes

## Presets & Admin Workflow

1. In the WordPress dashboard, go to **SVG Viewer → Presets** and click **Add New Preset** (or edit an existing one).
2. Use **Select SVG** to choose or upload an SVG from the Media Library, then fill in height, zoom limits, initial zoom, zoom increment, optional title/caption, and pick a controls position/layout.
3. Click **Load / Refresh Preview** to open the interactive viewer directly in the editor. Pan and zoom until the default view looks right.
4. Press **Use Current View for Initial State** to copy the current zoom level and center coordinates into the preset fields automatically.
5. Publish or update the preset. The post ID (visible in the URL or list table) is what you reference in the shortcode via `id="…"`.
6. On the front end, use `[svg_viewer id="123"]` to render the preset. Include additional shortcode attributes to override specific values for that instance when needed.
7. Copy-ready shortcode helpers appear in both the preset editor and the preset list table—click **Copy** to grab `[svg_viewer id="…"]` without highlighting manually.

## Keyboard Shortcuts

- **Ctrl/Cmd + Plus (+)** - Zoom in
- **Ctrl/Cmd + Minus (-)** - Zoom out
- **Ctrl/Cmd + 0** - Reset zoom and center view
- **Mouse Wheel** - Scroll with Ctrl/Cmd held to zoom

## SVG File Preparation

For best results with your SVG:

1. **Remove fixed dimensions** (optional, plugin handles this)
2. **Ensure viewBox is accurate** - The viewBox determines the aspect ratio and scaling
3. **Test in browser** - Check that the SVG displays correctly before adding to WordPress

## Troubleshooting

### SVG Not Loading

1. **Check file path** - Ensure the path is correct and the file exists
2. **CORS issues** - If loading from another domain, ensure CORS headers are set
3. **Browser console** - Check browser console (F12) for error messages

### Zoom Not Working

1. Ensure JavaScript is enabled
2. Check that no other scripts are conflicting
3. Try a different browser

### Plugin Not Appearing in Admin

1. Ensure all files are in the correct directory structure
2. Check that `svg-viewer-plugin.php` is in the plugin root folder
3. Clear WordPress cache if using a cache plugin

## CSS Customization

You can override default styles by adding CSS to your theme's `style.css`:

```css
/* Customize button colors */
.svg-viewer-btn {
    background-color: #your-color;
}

/* Customize container */
.svg-viewer-wrapper {
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Customize controls bar */
.svg-controls {
    background-color: #your-color;
}

/* Adjust icon-only controls */
.controls-mode-icon .svg-viewer-btn {
    background-color: #111;
}

/* Add spacing when controls sit on the right */
.controls-position-right .svg-controls {
    max-width: 220px;
}

/* Customize title/caption */
.svg-viewer-title,
.svg-viewer-caption {
    font-weight: 700;
    color: #123456;
}
```

## Support for Multiple Viewers

You can add multiple SVG viewers on the same page:

```text
[svg_viewer src="file1.svg" height="600px"]

Some text here...

[svg_viewer src="file2.svg" height="600px"]
```

Each viewer works independently with its own zoom and pan state.

## Browser Support

- Chrome/Edge: ✅ Full support
- Firefox: ✅ Full support
- Safari: ✅ Full support
- IE11: ⚠️ Limited support (no fetch API)

## License

GPL2 - Same as WordPress

## Credits

Created for WordPress SVG embedding and interactive viewing.

## Coordinate Helper

Enable the helper by passing `show_coords="true"` to the shortcode. The control bar will display a 📍 **Copy Center** button and a temporary readout.

1. Pan/zoom until the portion of the SVG you want centered is in view.
2. Click **Copy Center**. The plugin copies the current viewport center as `x, y` (in SVG units) to your clipboard and shows the values for a few seconds.
3. Use those numbers in the shortcode: `center_x="…" center_y="…"`.

If clipboard access is blocked, a browser prompt appears so you can copy the values manually.
You can also grab them from the console with `window.svgViewerInstances['your-viewer-id'].getVisibleCenterPoint()` and copy the returned `x` and `y` values into the shortcode attributes to lock the viewer on that focus point.
When you are satisfied with the result, update the shortcode attributes and remove `show_coords="true"` from published content.
