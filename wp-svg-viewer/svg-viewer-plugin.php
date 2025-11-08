<?php
/**
 * Plugin Name: WP SVG Viewer
 * Plugin URI: https://github.com/ttscoff/wp-svg-viewer/
 * Description: Embed interactive SVG files with zoom and pan controls
 * Version: 1.0.5
 * Author: Brett Terpstra
 * Author URI: https://brettterpstra.com
 * License: GPL2
 * Text Domain: svg-viewer
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SVG_Viewer
{
    private static $instance = null;
    private $preset_meta_fields = array(
        'svg_viewer_src' => '_svg_src',
        'svg_viewer_height' => '_svg_height',
        'svg_viewer_min_zoom' => '_svg_min_zoom',
        'svg_viewer_max_zoom' => '_svg_max_zoom',
        'svg_viewer_initial_zoom' => '_svg_initial_zoom',
        'svg_viewer_zoom_step' => '_svg_zoom_step',
        'svg_viewer_center_x' => '_svg_center_x',
        'svg_viewer_center_y' => '_svg_center_y',
        'svg_viewer_title' => '_svg_title',
        'svg_viewer_caption' => '_svg_caption',
        'svg_viewer_attachment_id' => '_svg_attachment_id',
        'svg_viewer_controls_position' => '_svg_controls_position',
        'svg_viewer_controls_buttons' => '_svg_controls_buttons',
    );
    private $current_presets_admin_tab = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_shortcode('svg_viewer', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_filter('upload_mimes', array($this, 'svg_use_mimetypes'));
        add_action('init', array($this, 'register_preset_post_type'));
        add_action('add_meta_boxes_svg_viewer_preset', array($this, 'register_preset_meta_boxes'));
        add_action('save_post_svg_viewer_preset', array($this, 'save_preset_meta'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('manage_svg_viewer_preset_posts_columns', array($this, 'add_shortcode_column'));
        add_action('manage_svg_viewer_preset_posts_custom_column', array($this, 'render_shortcode_column'), 10, 2);
        add_action('current_screen', array($this, 'maybe_setup_presets_screen'));
    }

    /**
     * Load plugin textdomain for translations
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('svg-viewer', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Initialize the presets screen tab navigation.
     *
     * @param WP_Screen $screen
     * @return void
     */
    public function maybe_setup_presets_screen($screen)
    {
        if (!$screen || $screen->id !== 'edit-svg_viewer_preset') {
            return;
        }

        $allowed_tabs = array('presets', 'help', 'changes');
        $requested_tab = isset($_GET['svg_tab']) ? sanitize_key(wp_unslash($_GET['svg_tab'])) : 'presets'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!in_array($requested_tab, $allowed_tabs, true)) {
            $requested_tab = 'presets';
        }

        $this->current_presets_admin_tab = $requested_tab;

        add_action('in_admin_header', array($this, 'render_presets_screen_tabs_nav'));
        add_action('all_admin_notices', array($this, 'render_presets_screen_tab_content'));

        if ($requested_tab !== 'presets') {
            add_action('admin_head', array($this, 'hide_presets_list_ui'));
        }
    }

    /**
     * Render the tab navigation on the presets list screen.
     *
     * @return void
     */
    public function render_presets_screen_tabs_nav()
    {
        if ($this->current_presets_admin_tab === null) {
            return;
        }

        $base_url = add_query_arg('post_type', 'svg_viewer_preset', admin_url('edit.php'));
        $tabs = array(
            'presets' => __('Presets', 'svg-viewer'),
            'help' => __('Help', 'svg-viewer'),
            'changes' => __('Changes', 'svg-viewer'),
        );

        echo '<div class="svg-viewer-admin-screen-tabs nav-tab-wrapper">';

        foreach ($tabs as $tab_key => $label) {
            $url = $base_url;
            if ($tab_key !== 'presets') {
                $url = add_query_arg('svg_tab', $tab_key, $url);
            } else {
                $url = remove_query_arg('svg_tab', $url);
            }

            $is_active = $this->current_presets_admin_tab === $tab_key;
            $classes = $is_active ? 'nav-tab nav-tab-active' : 'nav-tab';

            printf(
                '<a href="%1$s" class="%2$s" role="tab" aria-selected="%3$s">%4$s</a>',
                esc_url($url),
                esc_attr($classes),
                $is_active ? 'true' : 'false',
                esc_html($label)
            );
        }

        echo '</div>';
    }

    /**
     * Render the active tab content on the presets list screen.
     *
     * @return void
     */
    public function render_presets_screen_tab_content()
    {
        if ($this->current_presets_admin_tab === null || $this->current_presets_admin_tab === 'presets') {
            return;
        }

        echo '<div class="svg-viewer-admin-screen-panel">';

        if ($this->current_presets_admin_tab === 'help') {
            $help_markup = $this->get_admin_help_markup();
            if ($help_markup !== '') {
                echo $help_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                printf('<p>%s</p>', esc_html__('Help content is not available. Run the "Render Help/Changelog" build step to regenerate it.', 'svg-viewer'));
            }
        } elseif ($this->current_presets_admin_tab === 'changes') {
            $changelog_markup = $this->get_admin_changelog_markup();
            if ($changelog_markup !== '') {
                echo $changelog_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                printf('<p>%s</p>', esc_html__('Changes content is not available. Run the "Render Help/Changelog" build step to regenerate it.', 'svg-viewer'));
            }
        }

        echo '</div>';
    }

    /**
     * Hide the default list table UI when non-preset tabs are active.
     *
     * @return void
     */
    public function hide_presets_list_ui()
    {
        if ($this->current_presets_admin_tab === null || $this->current_presets_admin_tab === 'presets') {
            return;
        }
        ?>
        <style>
            .post-type-svg_viewer_preset .wrap .tablenav,
            .post-type-svg_viewer_preset .wrap .wp-list-table,
            .post-type-svg_viewer_preset .wrap .subsubsub,
            .post-type-svg_viewer_preset .wrap .search-box,
            .post-type-svg_viewer_preset .wrap .tablenav.bottom,
            .post-type-svg_viewer_preset .wrap .wp-heading-inline,
            .post-type-svg_viewer_preset .wrap .page-title-action,
            .post-type-svg_viewer_preset .wrap .alignleft.actions,
            .post-type-svg_viewer_preset .wrap .tablenav-pages,
            #screen-options-link-wrap,
            #contextual-help-link {
                display: none !important;
            }

            .svg-viewer-admin-screen-panel {
                margin-top: 20px;
                background: #fff;
                padding: 20px;
                border: 1px solid #dcdcde;
                box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
            }

            .svg-viewer-admin-screen-panel table {
                width: 100%;
                border-collapse: collapse;
            }

            .svg-viewer-admin-screen-panel th,
            .svg-viewer-admin-screen-panel td {
                border: 1px solid #dcdcde;
                padding: 8px;
                text-align: left;
            }
        </style>
        <?php
    }

    /**
     * Allow SVG file uploads
     */
    public function svg_use_mimetypes($mimes)
    {
        $mimes['svg'] = 'image/svg+xml';
        return $mimes;
    }

    /**
     * Enqueue CSS and JS
     */
    public function enqueue_assets()
    {
        wp_enqueue_style(
            'svg-viewer-style',
            plugins_url('css/svg-viewer.css', __FILE__),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'svg-viewer-script',
            plugins_url('js/svg-viewer.js', __FILE__),
            array(),
            '1.0.0',
            true
        );

        // Pass plugin URL to JavaScript
        wp_localize_script('svg-viewer-script', 'svgViewerConfig', array(
            'pluginUrl' => plugins_url('', __FILE__),
        ));
    }

    /**
     * Render the shortcode
     *
     * Usage: [svg_viewer src="/path/to/file.svg" height="600px"]
     */
    public function render_shortcode($atts)
    {
        $raw_atts = is_array($atts) ? $atts : array();

        $atts = shortcode_atts(array(
            'src' => '',
            'height' => '600px',
            'class' => '',
            'zoom' => '100',  // percentage
            'min_zoom' => '25',   // percentage
            'max_zoom' => '800',  // percentage
            'zoom_step' => '10',   // percentage
            'center_x' => '',
            'center_y' => '',
            'show_coords' => 'false',
            'title' => '',
            'caption' => '',
            'id' => '',
            'controls_position' => 'top',
            'controls_buttons' => 'both',
        ), $atts, 'svg_viewer');

        if (!empty($atts['id'])) {
            $preset_id = absint($atts['id']);
            $preset_data = $this->get_preset_settings($preset_id);

            if (!$preset_data) {
                $error_message = sprintf(
                    '<div style="color: red; padding: 10px; border: 1px solid red;">%s</div>',
                    esc_html(
                        sprintf(
                            __('Error: SVG preset not found for ID %s.', 'svg-viewer'),
                            $atts['id']
                        )
                    )
                );
                return $error_message;
            }

            foreach ($preset_data as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }

                $raw_value = isset($raw_atts[$key]) ? $raw_atts[$key] : null;
                $should_override = ($raw_value === null || $raw_value === '');

                if ($key === 'src') {
                    if ($should_override) {
                        $atts['src'] = $value;
                    }
                    continue;
                }

                if ($should_override) {
                    $atts[$key] = $value;
                }
            }
        }

        // Validate src
        if (empty($atts['src'])) {
            $error_message = sprintf(
                '<div style="color: red; padding: 10px; border: 1px solid red;">%s</div>',
                esc_html__(
                    'Error: SVG source not specified. Use [svg_viewer src="path/to/file.svg"]',
                    'svg-viewer'
                )
            );
            return $error_message;
        }

        // Convert relative paths to absolute URLs
        $svg_url = $this->get_svg_url($atts['src']);

        if (!$svg_url) {
            $error_message = sprintf(
                '<div style="color: red; padding: 10px; border: 1px solid red;">%s</div>',
                esc_html__('Error: Invalid SVG path.', 'svg-viewer')
            );
            return $error_message;
        }

        // Normalize zoom settings
        $initial_zoom = max(1, floatval($atts['zoom'])) / 100;
        $min_zoom = max(1, floatval($atts['min_zoom'])) / 100;
        $max_zoom = max($initial_zoom, floatval($atts['max_zoom'])) / 100;
        $zoom_step = max(0.1, floatval($atts['zoom_step'])) / 100;

        $center_x = strlen(trim($atts['center_x'])) ? floatval($atts['center_x']) : null;
        $center_y = strlen(trim($atts['center_y'])) ? floatval($atts['center_y']) : null;
        $show_coords = filter_var($atts['show_coords'], FILTER_VALIDATE_BOOLEAN);

        // Ensure consistency
        if ($min_zoom > $max_zoom) {
            $min_zoom = $max_zoom;
        }
        $initial_zoom = max($min_zoom, min($max_zoom, $initial_zoom));

        // Generate unique ID
        $viewer_id = 'svg-viewer-' . uniqid();
        $custom_class = sanitize_html_class($atts['class']);

        $title = trim($atts['title']);
        $caption = trim($atts['caption']);
        $controls_config = $this->parse_controls_config(
            $atts['controls_position'],
            $atts['controls_buttons'],
            $show_coords
        );
        $initial_zoom_percent = (int) round($initial_zoom * 100);
        $controls_markup = $this->render_controls_markup($viewer_id, $controls_config, $initial_zoom_percent);

        $wrapper_classes = array('svg-viewer-wrapper');
        if (!empty($custom_class)) {
            $wrapper_classes[] = $custom_class;
        }
        $wrapper_classes[] = 'controls-position-' . $controls_config['position'];
        $wrapper_classes[] = 'controls-mode-' . $controls_config['mode'];
        foreach ($controls_config['styles'] as $style_class) {
            $wrapper_classes[] = 'controls-style-' . $style_class;
        }
        $wrapper_classes[] = 'controls-align-' . $controls_config['alignment'];
        if ($controls_config['mode'] === 'hidden') {
            $wrapper_classes[] = 'controls-hidden';
        }

        $main_classes = array(
            'svg-viewer-main',
            'controls-position-' . $controls_config['position'],
            'controls-mode-' . $controls_config['mode'],
        );
        foreach ($controls_config['styles'] as $style_class) {
            $main_classes[] = 'controls-style-' . $style_class;
        }
        $main_classes[] = 'controls-align-' . $controls_config['alignment'];

        $wrapper_class_attribute = $this->build_class_attribute($wrapper_classes);
        $main_class_attribute = $this->build_class_attribute($main_classes);

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapper_class_attribute); ?>" id="<?php echo esc_attr($viewer_id); ?>">
            <?php if (!empty($title)): ?>
                <div class="svg-viewer-title"><?php echo wp_kses_post($title); ?></div>
            <?php endif; ?>
            <div class="<?php echo esc_attr($main_class_attribute); ?>" data-viewer="<?php echo esc_attr($viewer_id); ?>">
                <?php if ($controls_markup !== ''): ?>
                    <?php echo $controls_markup; ?>
                <?php endif; ?>
                <div class="svg-container" style="height: <?php echo esc_attr($atts['height']); ?>"
                    data-viewer="<?php echo esc_attr($viewer_id); ?>">
                    <div class="svg-viewport" data-viewer="<?php echo esc_attr($viewer_id); ?>">
                        <!-- SVG will be loaded here -->
                    </div>
                </div>
            </div>
            <?php if (!empty($caption)): ?>
                <div class="svg-viewer-caption"><?php echo wp_kses_post($caption); ?></div>
            <?php endif; ?>
        </div>
        <script>
            (function () {
                if (typeof window.svgViewerInstances === 'undefined') {
                    window.svgViewerInstances = {};
                }

                // Initialize viewer when ready
                function initViewer() {
                    if (typeof SVGViewer !== 'undefined') {
                        window.svgViewerInstances['<?php echo $viewer_id; ?>'] = new SVGViewer({
                            viewerId: '<?php echo $viewer_id; ?>',
                            svgUrl: '<?php echo esc_url($svg_url); ?>',
                            initialZoom: <?php echo json_encode($initial_zoom); ?>,
                            minZoom: <?php echo json_encode($min_zoom); ?>,
                            maxZoom: <?php echo json_encode($max_zoom); ?>,
                            zoomStep: <?php echo json_encode($zoom_step); ?>,
                            centerX: <?php echo $center_x === null ? 'null' : json_encode($center_x); ?>,
                            centerY: <?php echo $center_y === null ? 'null' : json_encode($center_y); ?>,
                            showCoordinates: <?php echo $show_coords ? 'true' : 'false'; ?>
                        });
                    } else {
                        setTimeout(initViewer, 100);
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initViewer);
                } else {
                    initViewer();
                }
            })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Convert SVG path to URL
     */
    private function get_svg_url($path)
    {
        // If it's already a full URL, validate it
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // If it starts with /, make it relative to home URL
        if ($path[0] === '/') {
            return home_url() . $path;
        }

        // Otherwise, assume it's relative to uploads
        $uploads = wp_get_upload_dir();
        return $uploads['baseurl'] . '/' . $path;
    }

    /**
     * Register custom post type for viewer presets
     */
    public function register_preset_post_type()
    {
        $labels = array(
            'name' => __('SVG Viewer Presets', 'svg-viewer'),
            'singular_name' => __('SVG Viewer Preset', 'svg-viewer'),
            'menu_name' => __('SVG Viewer', 'svg-viewer'),
            'add_new' => __('Add New Preset', 'svg-viewer'),
            'add_new_item' => __('Add New SVG Viewer Preset', 'svg-viewer'),
            'edit_item' => __('Edit SVG Viewer Preset', 'svg-viewer'),
            'new_item' => __('New SVG Viewer Preset', 'svg-viewer'),
            'view_item' => __('View SVG Viewer Preset', 'svg-viewer'),
            'search_items' => __('Search SVG Viewer Presets', 'svg-viewer'),
            'not_found' => __('No presets found', 'svg-viewer'),
            'not_found_in_trash' => __('No presets found in trash', 'svg-viewer'),
            'all_items' => __('SVG Viewer Presets', 'svg-viewer'),
        );

        $args = array(
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-location-alt',
            'supports' => array('title'),
            'rewrite' => false,
            'has_archive' => false,
            'capability_type' => 'post',
        );

        register_post_type('svg_viewer_preset', $args);
    }

    /**
     * Enqueue admin assets for the preset editor
     */
    public function enqueue_admin_assets($hook)
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'svg_viewer_preset') {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'svg-viewer-style',
            plugins_url('css/svg-viewer.css', __FILE__),
            array(),
            '1.0.0'
        );

        wp_enqueue_style(
            'svg-viewer-admin',
            plugins_url('admin/css/admin.css', __FILE__),
            array('svg-viewer-style'),
            '1.0.0'
        );

        wp_enqueue_script(
            'svg-viewer-script',
            plugins_url('js/svg-viewer.js', __FILE__),
            array(),
            '1.0.0',
            true
        );

        wp_localize_script('svg-viewer-script', 'svgViewerConfig', array(
            'pluginUrl' => plugins_url('', __FILE__),
        ));

        wp_enqueue_script(
            'svg-viewer-admin',
            plugins_url('admin/js/admin.js', __FILE__),
            array('jquery', 'svg-viewer-script'),
            '1.0.0',
            true
        );

        $button_definitions = $this->get_button_definitions();

        wp_localize_script('svg-viewer-admin', 'svgViewerAdmin', array(
            'i18n' => array(
                'missingSrc' => __('Please select an SVG before loading the preview.', 'svg-viewer'),
                'captureSaved' => __('Captured viewer state from the preview.', 'svg-viewer'),
                'captureFailed' => __('Unable to capture the current state. Refresh the preview and try again.', 'svg-viewer'),
                'copySuccess' => __('Shortcode copied to clipboard.', 'svg-viewer'),
                'copyFailed' => __('Press ⌘/Ctrl+C to copy the shortcode.', 'svg-viewer'),
            ),
            'controls' => array(
                'buttons' => $button_definitions,
            ),
        ));
    }

    /**
     * Register meta boxes for presets
     */
    public function register_preset_meta_boxes($post)
    {
        add_meta_box(
            'svg-viewer-preset-settings',
            __('SVG Viewer Settings', 'svg-viewer'),
            array($this, 'render_preset_meta_box'),
            'svg_viewer_preset',
            'normal',
            'high'
        );
    }

    /**
     * Render the preset meta box UI
     */
    public function render_preset_meta_box($post)
    {
        wp_nonce_field('svg_viewer_preset_meta', 'svg_viewer_preset_nonce');

        $defaults = array(
            'src' => '',
            'height' => '600px',
            'min_zoom' => '25',
            'max_zoom' => '800',
            'initial_zoom' => '100',
            'zoom_step' => '10',
            'center_x' => '',
            'center_y' => '',
            'title' => '',
            'caption' => '',
            'attachment_id' => '',
            'controls_position' => 'top',
            'controls_buttons' => 'both',
        );

        $values = array(
            'src' => get_post_meta($post->ID, '_svg_src', true),
            'height' => get_post_meta($post->ID, '_svg_height', true),
            'min_zoom' => get_post_meta($post->ID, '_svg_min_zoom', true),
            'max_zoom' => get_post_meta($post->ID, '_svg_max_zoom', true),
            'initial_zoom' => get_post_meta($post->ID, '_svg_initial_zoom', true),
            'zoom_step' => get_post_meta($post->ID, '_svg_zoom_step', true),
            'center_x' => get_post_meta($post->ID, '_svg_center_x', true),
            'center_y' => get_post_meta($post->ID, '_svg_center_y', true),
            'title' => get_post_meta($post->ID, '_svg_title', true),
            'caption' => get_post_meta($post->ID, '_svg_caption', true),
            'attachment_id' => get_post_meta($post->ID, '_svg_attachment_id', true),
            'controls_position' => get_post_meta($post->ID, '_svg_controls_position', true),
            'controls_buttons' => get_post_meta($post->ID, '_svg_controls_buttons', true),
        );

        $values = wp_parse_args($values, $defaults);

        $viewer_id = 'svg-viewer-admin-' . uniqid();
        $shortcode = $this->get_preset_shortcode($post->ID);
        $initial_zoom_value = is_numeric($values['initial_zoom']) ? (int) $values['initial_zoom'] : 100;
        $preview_controls_config = $this->parse_controls_config(
            $values['controls_position'],
            $values['controls_buttons'],
            false
        );
        $preview_controls_markup = $this->render_controls_markup($viewer_id, $preview_controls_config, $initial_zoom_value);

        $wrapper_classes = array(
            'svg-viewer-wrapper',
            'svg-viewer-admin-wrapper',
            'controls-position-' . $preview_controls_config['position'],
            'controls-mode-' . $preview_controls_config['mode'],
        );
        foreach ($preview_controls_config['styles'] as $style_class) {
            $wrapper_classes[] = 'controls-style-' . $style_class;
        }
        if ($preview_controls_config['mode'] === 'hidden') {
            $wrapper_classes[] = 'controls-hidden';
        }

        $main_classes = array(
            'svg-viewer-main',
            'controls-position-' . $preview_controls_config['position'],
            'controls-mode-' . $preview_controls_config['mode'],
        );
        foreach ($preview_controls_config['styles'] as $style_class) {
            $main_classes[] = 'controls-style-' . $style_class;
        }

        $wrapper_class_attribute = $this->build_class_attribute($wrapper_classes);
        $main_class_attribute = $this->build_class_attribute($main_classes);
        $settings_panel_id = $viewer_id . '-tab-settings';
        $help_panel_id = $viewer_id . '-tab-help';
        $changes_panel_id = $viewer_id . '-tab-changes';
        ?>
        <div class="svg-viewer-tabs" data-viewer-id="<?php echo esc_attr($viewer_id); ?>">
            <div class="svg-viewer-tab-nav" role="tablist">
                <button type="button" class="svg-viewer-tab-button is-active" role="tab"
                    id="<?php echo esc_attr($settings_panel_id); ?>-tab"
                    aria-controls="<?php echo esc_attr($settings_panel_id); ?>" aria-selected="true" data-tab-target="settings">
                    <?php esc_html_e('Settings', 'svg-viewer'); ?>
                </button>
                <button type="button" class="svg-viewer-tab-button" role="tab" id="<?php echo esc_attr($help_panel_id); ?>-tab"
                    aria-controls="<?php echo esc_attr($help_panel_id); ?>" aria-selected="false" data-tab-target="help">
                    <?php esc_html_e('Help', 'svg-viewer'); ?>
                </button>
                <button type="button" class="svg-viewer-tab-button" role="tab"
                    id="<?php echo esc_attr($changes_panel_id); ?>-tab"
                    aria-controls="<?php echo esc_attr($changes_panel_id); ?>" aria-selected="false" data-tab-target="changes">
                    <?php esc_html_e('Changes', 'svg-viewer'); ?>
                </button>
            </div>
            <div class="svg-viewer-tab-panels">
                <div class="svg-viewer-tab-panel is-active" role="tabpanel" id="<?php echo esc_attr($settings_panel_id); ?>"
                    aria-labelledby="<?php echo esc_attr($settings_panel_id); ?>-tab" data-tab-panel="settings">
                    <div class="svg-viewer-admin-meta" data-viewer-id="<?php echo esc_attr($viewer_id); ?>">
                        <div class="svg-viewer-shortcode-display">
                            <label for="svg-viewer-shortcode"><?php esc_html_e('Preset Shortcode', 'svg-viewer'); ?></label>
                            <div class="svg-shortcode-wrap">
                                <input type="text" id="svg-viewer-shortcode" class="svg-shortcode-input" readonly
                                    value="<?php echo esc_attr($shortcode); ?>">
                                <button type="button" class="button svg-shortcode-copy"
                                    data-shortcode="<?php echo esc_attr($shortcode); ?>">
                                    <?php esc_html_e('Copy', 'svg-viewer'); ?>
                                </button>
                                <span class="svg-shortcode-status" aria-live="polite"></span>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Use this shortcode in pages or posts to embed this preset.', 'svg-viewer'); ?>
                            </p>
                        </div>

                        <div class="svg-viewer-field">
                            <label for="svg-viewer-src"><?php esc_html_e('SVG Source URL', 'svg-viewer'); ?></label>
                            <div class="svg-viewer-media-control">
                                <input type="text" id="svg-viewer-src" name="svg_viewer_src"
                                    value="<?php echo esc_attr($values['src']); ?>"
                                    placeholder="<?php esc_attr_e('https://example.com/my-graphic.svg or uploads/2025/graphic.svg', 'svg-viewer'); ?>" />
                                <button type="button"
                                    class="button svg-viewer-select-media"><?php esc_html_e('Select SVG', 'svg-viewer'); ?></button>
                            </div>
                            <input type="hidden" name="svg_viewer_attachment_id"
                                value="<?php echo esc_attr($values['attachment_id']); ?>" />
                        </div>

                        <div class="svg-viewer-field-group">
                            <div class="svg-viewer-field">
                                <label for="svg-viewer-height"><?php esc_html_e('Viewer Height', 'svg-viewer'); ?></label>
                                <input type="text" id="svg-viewer-height" name="svg_viewer_height"
                                    value="<?php echo esc_attr($values['height']); ?>" placeholder="600px" />
                            </div>
                            <div class="svg-viewer-field">
                                <label for="svg-viewer-min-zoom"><?php esc_html_e('Min Zoom (%)', 'svg-viewer'); ?></label>
                                <input type="number" id="svg-viewer-min-zoom" name="svg_viewer_min_zoom"
                                    value="<?php echo esc_attr($values['min_zoom']); ?>" min="1" step="1" />
                            </div>
                            <div class="svg-viewer-field">
                                <label for="svg-viewer-max-zoom"><?php esc_html_e('Max Zoom (%)', 'svg-viewer'); ?></label>
                                <input type="number" id="svg-viewer-max-zoom" name="svg_viewer_max_zoom"
                                    value="<?php echo esc_attr($values['max_zoom']); ?>" min="1" step="1" />
                            </div>
                            <div class="svg-viewer-field">
                                <label
                                    for="svg-viewer-initial-zoom"><?php esc_html_e('Initial Zoom (%)', 'svg-viewer'); ?></label>
                                <input type="number" id="svg-viewer-initial-zoom" name="svg_viewer_initial_zoom"
                                    value="<?php echo esc_attr($values['initial_zoom']); ?>" min="1" step="1" />
                            </div>
                            <div class="svg-viewer-field">
                                <label
                                    for="svg-viewer-zoom-step"><?php esc_html_e('Zoom Increment (%)', 'svg-viewer'); ?></label>
                                <input type="number" id="svg-viewer-zoom-step" name="svg_viewer_zoom_step"
                                    value="<?php echo esc_attr($values['zoom_step']); ?>" min="0.1" step="0.1" />
                            </div>
                        </div>

                        <div class="svg-viewer-field-group">
                            <div class="svg-viewer-field">
                                <label for="svg-viewer-center-x"><?php esc_html_e('Center X', 'svg-viewer'); ?></label>
                                <input type="number" id="svg-viewer-center-x" name="svg_viewer_center_x"
                                    value="<?php echo esc_attr($values['center_x']); ?>" step="0.01" />
                            </div>
                            <div class="svg-viewer-field">
                                <label for="svg-viewer-center-y"><?php esc_html_e('Center Y', 'svg-viewer'); ?></label>
                                <input type="number" id="svg-viewer-center-y" name="svg_viewer_center_y"
                                    value="<?php echo esc_attr($values['center_y']); ?>" step="0.01" />
                            </div>
                        </div>

                        <div class="svg-viewer-field-group">
                            <div class="svg-viewer-field">
                                <label
                                    for="svg-viewer-controls-position"><?php esc_html_e('Controls Position', 'svg-viewer'); ?></label>
                                <select id="svg-viewer-controls-position" name="svg_viewer_controls_position">
                                    <?php
                                    $positions_options = array(
                                        'top' => __('Top', 'svg-viewer'),
                                        'bottom' => __('Bottom', 'svg-viewer'),
                                        'left' => __('Left', 'svg-viewer'),
                                        'right' => __('Right', 'svg-viewer'),
                                    );
                                    foreach ($positions_options as $pos_value => $label):
                                        ?>
                                        <option value="<?php echo esc_attr($pos_value); ?>" <?php selected($values['controls_position'], $pos_value); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="svg-viewer-field">
                                <label
                                    for="svg-viewer-controls-buttons"><?php esc_html_e('Controls Buttons/Layout', 'svg-viewer'); ?></label>
                                <input type="text" id="svg-viewer-controls-buttons" name="svg_viewer_controls_buttons"
                                    value="<?php echo esc_attr($values['controls_buttons']); ?>" placeholder="both" />
                                <p class="description">
                                    <?php esc_html_e('Combine multiple options with commas. Examples: both, icon, text, compact, labels-on-hover, minimal, alignleft, aligncenter, alignright, custom,both,aligncenter,zoom_in,zoom_out,reset,center,coords', 'svg-viewer'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="svg-viewer-field">
                            <label for="svg-viewer-title"><?php esc_html_e('Title (optional)', 'svg-viewer'); ?></label>
                            <input type="text" id="svg-viewer-title" name="svg_viewer_title"
                                value="<?php echo esc_attr($values['title']); ?>" />
                        </div>

                        <div class="svg-viewer-field">
                            <label for="svg-viewer-caption"><?php esc_html_e('Caption (optional)', 'svg-viewer'); ?></label>
                            <textarea id="svg-viewer-caption" name="svg_viewer_caption" rows="3"
                                class="widefat"><?php echo esc_textarea($values['caption']); ?></textarea>
                            <p class="description"><?php esc_html_e('Supports basic HTML formatting.', 'svg-viewer'); ?></p>
                        </div>

                        <div class="svg-viewer-admin-preview">
                            <div class="svg-viewer-admin-preview-toolbar">
                                <button type="button" class="button svg-admin-refresh-preview"
                                    data-viewer="<?php echo esc_attr($viewer_id); ?>"><?php esc_html_e('Load / Refresh Preview', 'svg-viewer'); ?></button>
                                <button type="button" class="button button-primary svg-admin-capture-state"
                                    data-viewer="<?php echo esc_attr($viewer_id); ?>"><?php esc_html_e('Use Current View for Initial State', 'svg-viewer'); ?></button>
                                <span class="svg-admin-status" aria-live="polite"></span>
                            </div>
                            <div class="<?php echo esc_attr($wrapper_class_attribute); ?>"
                                id="<?php echo esc_attr($viewer_id); ?>">
                                <div class="svg-viewer-title js-admin-title" hidden></div>
                                <div class="<?php echo esc_attr($main_class_attribute); ?>"
                                    data-viewer="<?php echo esc_attr($viewer_id); ?>">
                                    <?php if ($preview_controls_markup !== ''): ?>
                                        <?php echo $preview_controls_markup; ?>
                                    <?php endif; ?>
                                    <div class="svg-container" data-viewer="<?php echo esc_attr($viewer_id); ?>"
                                        style="height: <?php echo esc_attr($values['height']); ?>">
                                        <div class="svg-viewport" data-viewer="<?php echo esc_attr($viewer_id); ?>"></div>
                                    </div>
                                </div>
                                <div class="svg-viewer-caption js-admin-caption" hidden></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="svg-viewer-tab-panel" role="tabpanel" id="<?php echo esc_attr($help_panel_id); ?>"
                    aria-labelledby="<?php echo esc_attr($help_panel_id); ?>-tab" data-tab-panel="help" aria-hidden="true">
                    <div class="svg-viewer-help-content">
                        <?php
                        $help_markup = $this->get_admin_help_markup();
                        if ($help_markup !== '') {
                            echo $help_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        } else {
                            printf('<p>%s</p>', esc_html__('Help content is not available. Run the "Render Help" build step to regenerate it.', 'svg-viewer'));
                        }
                        ?>
                    </div>
                </div>
                <div class="svg-viewer-tab-panel" role="tabpanel" id="<?php echo esc_attr($changes_panel_id); ?>"
                    aria-labelledby="<?php echo esc_attr($changes_panel_id); ?>-tab" data-tab-panel="changes"
                    aria-hidden="true">
                    <div class="svg-viewer-help-content">
                        <?php
                        $changelog_markup = $this->get_admin_changelog_markup();
                        if ($changelog_markup !== '') {
                            echo $changelog_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        } else {
                            printf('<p>%s</p>', esc_html__('Changes content is not available. Run the "Render Help/Changelog" build step to regenerate it.', 'svg-viewer'));
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Retrieve the pre-rendered admin help markup.
     *
     * @return string
     */
    private function get_admin_help_markup()
    {
        static $cached_markup = null;

        if ($cached_markup !== null) {
            return $cached_markup;
        }

        $help_file = plugin_dir_path(__FILE__) . 'admin/help.html';

        if (!file_exists($help_file)) {
            $cached_markup = '';
            return $cached_markup;
        }

        $modified_time = (int) filemtime($help_file);
        $transient_key = 'svg_viewer_help_markup';
        $stored = get_transient($transient_key);

        if (is_array($stored) && isset($stored['mtime'], $stored['html']) && (int) $stored['mtime'] === $modified_time) {
            $cached_markup = $stored['html'];
            return $cached_markup;
        }

        $raw_html = file_get_contents($help_file);

        if ($raw_html === false) {
            $cached_markup = '';
            return $cached_markup;
        }

        $sanitized = wp_kses_post($raw_html);
        $cached_markup = $sanitized;

        set_transient($transient_key, array(
            'mtime' => $modified_time,
            'html' => $sanitized,
        ), DAY_IN_SECONDS);

        return $cached_markup;
    }

    /**
     * Retrieve the pre-rendered admin changelog markup.
     *
     * @return string
     */
    private function get_admin_changelog_markup()
    {
        static $cached_changelog = null;

        if ($cached_changelog !== null) {
            return $cached_changelog;
        }

        $changelog_file = plugin_dir_path(__FILE__) . 'admin/changelog.html';

        if (!file_exists($changelog_file)) {
            $cached_changelog = '';
            return $cached_changelog;
        }

        $modified_time = (int) filemtime($changelog_file);
        $transient_key = 'svg_viewer_changelog_markup';
        $stored = get_transient($transient_key);

        if (is_array($stored) && isset($stored['mtime'], $stored['html']) && (int) $stored['mtime'] === $modified_time) {
            $cached_changelog = $stored['html'];
            return $cached_changelog;
        }

        $raw_html = file_get_contents($changelog_file);

        if ($raw_html === false) {
            $cached_changelog = '';
            return $cached_changelog;
        }

        $sanitized = wp_kses_post($raw_html);
        $cached_changelog = $sanitized;

        set_transient($transient_key, array(
            'mtime' => $modified_time,
            'html' => $sanitized,
        ), DAY_IN_SECONDS);

        return $cached_changelog;
    }

    /**
     * Get the available control button definitions.
     *
     * @return array
     */
    private function get_button_definitions()
    {
        return array(
            'zoom_in' => array(
                'class' => 'zoom-in-btn',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM272 176C258.7 176 248 186.7 248 200L248 248L200 248C186.7 248 176 258.7 176 272C176 285.3 186.7 296 200 296L248 296L248 344C248 357.3 258.7 368 272 368C285.3 368 296 357.3 296 344L296 296L344 296C357.3 296 368 285.3 368 272C368 258.7 357.3 248 344 248L296 248L296 200C296 186.7 285.3 176 272 176z"/></svg>',
                'text' => __('Zoom In', 'svg-viewer'),
                'title' => __('Zoom In (Ctrl +)', 'svg-viewer'),
                'requires_show_coords' => false,
            ),
            'zoom_out' => array(
                'class' => 'zoom-out-btn',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM200 248C186.7 248 176 258.7 176 272C176 285.3 186.7 296 200 296L344 296C357.3 296 368 285.3 368 272C368 258.7 357.3 248 344 248L200 248z"/></svg>',
                'text' => __('Zoom Out', 'svg-viewer'),
                'title' => __('Zoom Out (Ctrl -)', 'svg-viewer'),
                'requires_show_coords' => false,
            ),
            'reset' => array(
                'class' => 'reset-zoom-btn',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM272 416C351.5 416 416 351.5 416 272C416 192.5 351.5 128 272 128C192.5 128 128 192.5 128 272C128 351.5 192.5 416 272 416z"/></svg>',
                'text' => __('Reset Zoom', 'svg-viewer'),
                'title' => __('Reset Zoom', 'svg-viewer'),
                'requires_show_coords' => false,
            ),
            'center' => array(
                'class' => 'center-view-btn',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M320 48C337.7 48 352 62.3 352 80L352 98.3C450.1 112.3 527.7 189.9 541.7 288L560 288C577.7 288 592 302.3 592 320C592 337.7 577.7 352 560 352L541.7 352C527.7 450.1 450.1 527.7 352 541.7L352 560C352 577.7 337.7 592 320 592C302.3 592 288 577.7 288 560L288 541.7C189.9 527.7 112.3 450.1 98.3 352L80 352C62.3 352 48 337.7 48 320C48 302.3 62.3 288 80 288L98.3 288C112.3 189.9 189.9 112.3 288 98.3L288 80C288 62.3 302.3 48 320 48zM163.2 352C175.9 414.7 225.3 464.1 288 476.8L288 464C288 446.3 302.3 432 320 432C337.7 432 352 446.3 352 464L352 476.8C414.7 464.1 464.1 414.7 476.8 352L464 352C446.3 352 432 337.7 432 320C432 302.3 446.3 288 464 288L476.8 288C464.1 225.3 414.7 175.9 352 163.2L352 176C352 193.7 337.7 208 320 208C302.3 208 288 193.7 288 176L288 163.2C225.3 175.9 175.9 225.3 163.2 288L176 288C193.7 288 208 302.3 208 320C208 337.7 193.7 352 176 352L163.2 352zM320 272C346.5 272 368 293.5 368 320C368 346.5 346.5 368 320 368C293.5 368 272 346.5 272 320C272 293.5 293.5 272 320 272z"/></svg>',
                'text' => __('Center View', 'svg-viewer'),
                'title' => __('Center View', 'svg-viewer'),
                'requires_show_coords' => false,
            ),
            'coords' => array(
                'class' => 'coord-copy-btn',
                'icon' => '📍',
                'text' => __('Copy Center', 'svg-viewer'),
                'title' => __('Copy current center coordinates', 'svg-viewer'),
                'requires_show_coords' => true,
            ),
        );
    }

    /**
     * Sanitize inline SVG markup for control icons.
     *
     * @param string $icon_markup
     * @return string
     */
    private function sanitize_svg_icon($icon_markup)
    {
        static $allowed_svg_tags = null;

        if ($allowed_svg_tags === null) {
            $allowed_svg_tags = array(
                'svg' => array(
                    'xmlns' => true,
                    'viewBox' => true,
                    'viewbox' => true,
                    'aria-hidden' => true,
                    'focusable' => true,
                    'role' => true,
                    'width' => true,
                    'height' => true,
                    'class' => true,
                ),
                'path' => array(
                    'd' => true,
                    'fill' => true,
                    'class' => true,
                ),
            );
        }

        return wp_kses($icon_markup, $allowed_svg_tags);
    }

    /**
     * Parse the controls configuration for position and layout.
     *
     * @param string $position
     * @param string $buttons_setting
     * @param bool   $show_coords
     * @return array
     */
    private function parse_controls_config($position, $buttons_setting, $show_coords)
    {
        $positions = array('top', 'bottom', 'left', 'right');
        $position = strtolower((string) $position);
        if (!in_array($position, $positions, true)) {
            $position = 'top';
        }

        $buttons_setting = is_string($buttons_setting) ? trim($buttons_setting) : '';
        if ($buttons_setting === '') {
            $buttons_setting = 'both';
        }
        $normalized_setting = strtolower($buttons_setting);

        $mode_options = array('icon', 'text', 'both');
        $style_options = array('compact', 'labels_on_hover', 'labels-on-hover');
        $hidden_options = array('hidden', 'none');
        $alignment_options = array('alignleft', 'aligncenter', 'alignright');
        $available_buttons = array('zoom_in', 'zoom_out', 'reset', 'center', 'coords');

        $default_buttons = array('zoom_in', 'zoom_out', 'reset', 'center');
        if ($show_coords) {
            $default_buttons[] = 'coords';
        }

        $mode = 'both';
        $styles = array();
        $alignment = 'left';
        $buttons = $default_buttons;
        $is_custom = false;

        if (in_array($normalized_setting, $hidden_options, true)) {
            $mode = 'hidden';
            $buttons = array();
        } elseif ($normalized_setting === 'minimal') {
            $buttons = array('zoom_in', 'zoom_out', 'center');
            if ($show_coords) {
                $buttons[] = 'coords';
            }
        } elseif (in_array($normalized_setting, $style_options, true)) {
            $styles[] = str_replace('_', '-', $normalized_setting);
        } elseif (in_array($normalized_setting, $alignment_options, true)) {
            $alignment = $normalized_setting;
        } elseif (in_array($normalized_setting, $mode_options, true)) {
            $mode = $normalized_setting;
        } elseif ($normalized_setting === 'custom' || strpos($buttons_setting, ',') !== false) {
            $is_custom = true;
        }

        if ($is_custom) {
            $parts = array_map('trim', explode(',', $buttons_setting));

            if (!empty($parts) && strtolower($parts[0]) === 'custom') {
                array_shift($parts);
            }

            $custom_mode = null;
            $custom_styles = array();

            if (!empty($parts)) {
                $first = strtolower($parts[0]);

                if (in_array($first, $hidden_options, true)) {
                    $mode = 'hidden';
                    $buttons = array();
                    $parts = array();
                } else {
                    if (in_array($first, $mode_options, true)) {
                        $custom_mode = $first;
                        array_shift($parts);
                    } elseif (in_array(str_replace('-', '_', $first), $style_options, true) || in_array($first, $style_options, true)) {
                        $custom_styles[] = str_replace('_', '-', $first);
                        array_shift($parts);
                    } elseif ($first === 'minimal') {
                        $buttons = array('zoom_in', 'zoom_out', 'center');
                        if ($show_coords) {
                            $buttons[] = 'coords';
                        }
                        array_shift($parts);
                    }

                    if (!empty($parts)) {
                        $maybe = strtolower($parts[0]);
                        if ($custom_mode === null && in_array($maybe, $mode_options, true)) {
                            $custom_mode = $maybe;
                            array_shift($parts);
                        } elseif (in_array($maybe, $alignment_options, true)) {
                            $alignment = $maybe;
                            array_shift($parts);
                        }
                    }

                    if (!empty($parts)) {
                        $maybe = strtolower($parts[0]);
                        if (in_array(str_replace('-', '_', $maybe), $style_options, true) || in_array($maybe, $style_options, true)) {
                            $custom_styles[] = str_replace('_', '-', $maybe);
                            array_shift($parts);
                        }
                    }
                }
            }

            if ($mode !== 'hidden') {
                $custom_buttons = array();
                foreach ($parts as $part) {
                    $key = strtolower($part);
                    if ($key === '') {
                        continue;
                    }
                    if ($key === 'coords' && !$show_coords) {
                        continue;
                    }
                    if (in_array($key, $available_buttons, true) && !in_array($key, $custom_buttons, true)) {
                        $custom_buttons[] = $key;
                    }
                }

                if (!empty($custom_buttons)) {
                    $buttons = $custom_buttons;
                } else {
                    $buttons = $default_buttons;
                }
            }

            if ($custom_mode !== null) {
                $mode = $custom_mode;
            }

            if (!empty($custom_styles)) {
                $styles = array_merge($styles, $custom_styles);
            }
        }

        if ($mode !== 'hidden') {
            if (!$show_coords) {
                $buttons = array_values(array_filter($buttons, static function ($button) {
                    return $button !== 'coords';
                }));
            } else {
                if (
                    !in_array('coords', $buttons, true)
                    && strpos($buttons_setting, ',') === false
                    && $normalized_setting !== 'minimal'
                    && $normalized_setting !== 'custom'
                ) {
                    $buttons[] = 'coords';
                }
            }

            if (empty($buttons)) {
                $buttons = $default_buttons;
            }
        } else {
            $buttons = array();
        }

        $styles = array_map(static function ($style) {
            return str_replace('_', '-', strtolower($style));
        }, $styles);
        $styles = array_values(array_unique($styles));

        return array(
            'position' => $position,
            'mode' => $mode,
            'styles' => $styles,
            'alignment' => $alignment,
            'buttons' => $buttons,
        );
    }

    /**
     * Build a sanitized class attribute string.
     *
     * @param array $classes
     * @return string
     */
    private function build_class_attribute(array $classes)
    {
        $sanitized = array();

        foreach ($classes as $class) {
            $class = trim((string) $class);
            if ($class === '') {
                continue;
            }
            $sanitized[] = sanitize_html_class($class);
        }

        return implode(' ', array_unique($sanitized));
    }

    /**
     * Render the controls markup based on configuration.
     *
     * @param string $viewer_id
     * @param array  $controls_config
     * @param int    $initial_zoom_percent
     * @return string
     */
    private function render_controls_markup($viewer_id, array $controls_config, $initial_zoom_percent)
    {
        if ($controls_config['mode'] === 'hidden') {
            return '';
        }

        $button_definitions = $this->get_button_definitions();
        $buttons = array();

        foreach ($controls_config['buttons'] as $button_key) {
            if (!isset($button_definitions[$button_key])) {
                continue;
            }
            $buttons[] = $button_key;
        }

        $classes = array(
            'svg-controls',
            'controls-mode-' . $controls_config['mode'],
        );

        $classes[] = 'controls-align-' . $controls_config['alignment'];

        foreach ($controls_config['styles'] as $style) {
            $classes[] = 'controls-style-' . $style;
        }

        if (in_array($controls_config['position'], array('left', 'right'), true)) {
            $classes[] = 'controls-vertical';
        }

        $class_attribute = $this->build_class_attribute($classes);
        $initial_zoom_percent = (int) $initial_zoom_percent;
        $has_coords_button = in_array('coords', $buttons, true);

        ob_start();
        ?>
        <div class="<?php echo esc_attr($class_attribute); ?>" data-viewer="<?php echo esc_attr($viewer_id); ?>">
            <?php foreach ($buttons as $button_key):
                $definition = $button_definitions[$button_key];
                ?>
                <button type="button" class="svg-viewer-btn <?php echo esc_attr($definition['class']); ?>"
                    data-viewer="<?php echo esc_attr($viewer_id); ?>" title="<?php echo esc_attr($definition['title']); ?>"
                    aria-label="<?php echo esc_attr($definition['text']); ?>">
                    <span class="btn-icon" aria-hidden="true"><?php echo $this->sanitize_svg_icon($definition['icon']); ?></span>
                    <span class="btn-text"><?php echo esc_html($definition['text']); ?></span>
                </button>
            <?php endforeach; ?>
            <?php if ($has_coords_button): ?>
                <span class="coord-output" data-viewer="<?php echo esc_attr($viewer_id); ?>" aria-live="polite"></span>
            <?php endif; ?>
            <div class="divider"></div>
            <span class="zoom-display">
                <span class="zoom-percentage"
                    data-viewer="<?php echo esc_attr($viewer_id); ?>"><?php echo $initial_zoom_percent; ?></span>%
            </span>
        </div>
        <?php
        return trim(ob_get_clean());
    }

    /**
     * Save preset meta data
     */
    public function save_preset_meta($post_id, $post)
    {
        if (!isset($_POST['svg_viewer_preset_nonce']) || !wp_verify_nonce($_POST['svg_viewer_preset_nonce'], 'svg_viewer_preset_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if ($post->post_type !== 'svg_viewer_preset') {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $raw_src = isset($_POST['svg_viewer_src']) ? wp_unslash($_POST['svg_viewer_src']) : '';
        $attachment_id = isset($_POST['svg_viewer_attachment_id']) ? absint($_POST['svg_viewer_attachment_id']) : '';
        $height = isset($_POST['svg_viewer_height']) ? sanitize_text_field(wp_unslash($_POST['svg_viewer_height'])) : '';

        $numeric_fields = array(
            '_svg_min_zoom' => 'svg_viewer_min_zoom',
            '_svg_max_zoom' => 'svg_viewer_max_zoom',
            '_svg_initial_zoom' => 'svg_viewer_initial_zoom',
            '_svg_zoom_step' => 'svg_viewer_zoom_step',
            '_svg_center_x' => 'svg_viewer_center_x',
            '_svg_center_y' => 'svg_viewer_center_y',
        );

        $text_fields = array(
            '_svg_title' => 'svg_viewer_title',
            '_svg_caption' => 'svg_viewer_caption',
        );

        if (!empty($raw_src)) {
            update_post_meta($post_id, '_svg_src', esc_url_raw($raw_src));
        } else {
            delete_post_meta($post_id, '_svg_src');
        }

        if ($attachment_id) {
            update_post_meta($post_id, '_svg_attachment_id', $attachment_id);
        } else {
            delete_post_meta($post_id, '_svg_attachment_id');
        }

        if (!empty($height)) {
            update_post_meta($post_id, '_svg_height', $height);
        } else {
            delete_post_meta($post_id, '_svg_height');
        }

        $controls_position = isset($_POST['svg_viewer_controls_position']) ? sanitize_text_field(wp_unslash($_POST['svg_viewer_controls_position'])) : '';
        if (!empty($controls_position)) {
            update_post_meta($post_id, '_svg_controls_position', strtolower($controls_position));
        } else {
            delete_post_meta($post_id, '_svg_controls_position');
        }

        $controls_buttons = isset($_POST['svg_viewer_controls_buttons']) ? sanitize_text_field(wp_unslash($_POST['svg_viewer_controls_buttons'])) : '';
        if ($controls_buttons !== '') {
            update_post_meta($post_id, '_svg_controls_buttons', $controls_buttons);
        } else {
            delete_post_meta($post_id, '_svg_controls_buttons');
        }

        foreach ($numeric_fields as $meta_key => $post_key) {
            if (isset($_POST[$post_key]) && $_POST[$post_key] !== '') {
                $value = floatval(wp_unslash($_POST[$post_key]));
                update_post_meta($post_id, $meta_key, $value);
            } else {
                delete_post_meta($post_id, $meta_key);
            }
        }

        foreach ($text_fields as $meta_key => $post_key) {
            if (isset($_POST[$post_key]) && $_POST[$post_key] !== '') {
                $value = wp_kses_post(wp_unslash($_POST[$post_key]));
                update_post_meta($post_id, $meta_key, $value);
            } else {
                delete_post_meta($post_id, $meta_key);
            }
        }
    }

    /**
     * Retrieve preset settings for shortcode usage
     */
    private function get_preset_settings($preset_id)
    {
        $preset = get_post($preset_id);
        if (!$preset || $preset->post_type !== 'svg_viewer_preset') {
            return null;
        }

        $settings = array(
            'src' => get_post_meta($preset_id, '_svg_src', true),
            'height' => get_post_meta($preset_id, '_svg_height', true),
            'min_zoom' => get_post_meta($preset_id, '_svg_min_zoom', true),
            'max_zoom' => get_post_meta($preset_id, '_svg_max_zoom', true),
            'zoom' => get_post_meta($preset_id, '_svg_initial_zoom', true),
            'zoom_step' => get_post_meta($preset_id, '_svg_zoom_step', true),
            'center_x' => get_post_meta($preset_id, '_svg_center_x', true),
            'center_y' => get_post_meta($preset_id, '_svg_center_y', true),
            'title' => get_post_meta($preset_id, '_svg_title', true),
            'caption' => get_post_meta($preset_id, '_svg_caption', true),
            'controls_position' => get_post_meta($preset_id, '_svg_controls_position', true),
            'controls_buttons' => get_post_meta($preset_id, '_svg_controls_buttons', true),
        );

        if (empty($settings['height'])) {
            $settings['height'] = '600px';
        }

        if (empty($settings['controls_position'])) {
            $settings['controls_position'] = 'top';
        }

        if ($settings['controls_buttons'] === '' || $settings['controls_buttons'] === null) {
            $settings['controls_buttons'] = 'both';
        }

        foreach (array('min_zoom', 'max_zoom', 'zoom', 'zoom_step', 'center_x', 'center_y') as $key) {
            if ($settings[$key] !== '' && $settings[$key] !== null) {
                $settings[$key] = is_numeric($settings[$key]) ? (string) (0 + $settings[$key]) : $settings[$key];
            }
        }

        return $settings;
    }

    /**
     * Add shortcode column to preset list
     */
    public function add_shortcode_column($columns)
    {
        $columns['svg_viewer_shortcode'] = __('Shortcode', 'svg-viewer');
        return $columns;
    }

    /**
     * Render shortcode column content
     */
    public function render_shortcode_column($column, $post_id)
    {
        if ('svg_viewer_shortcode' !== $column) {
            return;
        }

        $shortcode = $this->get_preset_shortcode($post_id);
        ?>
        <div class="svg-shortcode-column">
            <code><?php echo esc_html($shortcode); ?></code>
            <button type="button" class="button button-small svg-shortcode-copy"
                data-shortcode="<?php echo esc_attr($shortcode); ?>">
                <?php esc_html_e('Copy', 'svg-viewer'); ?>
            </button>
            <span class="svg-shortcode-status" aria-live="polite"></span>
        </div>
        <?php
    }

    /**
     * Generate shortcode text for presets
     */
    private function get_preset_shortcode($preset_id)
    {
        $preset_id = absint($preset_id);
        return '[svg_viewer id="' . $preset_id . '"]';
    }
}

// Initialize plugin
SVG_Viewer::get_instance();
