<?php
/**
 * Plugin Name: BT SVG Viewer
 * Plugin URI: https://github.com/ttscoff/bt-svg-viewer/
 * Description: Embed interactive SVG files with zoom and pan controls
 * Version: 1.0.17
 * Author: Brett Terpstra
 * Author URI: https://brettterpstra.com
 * License: GPLv2 or later
 * Text Domain: bt-svg-viewer
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BTSVVI_Viewer
{
    private static $instance = null;
    private $plugin_version = '1.0.17';
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
        'svg_viewer_button_fill' => '_svg_button_fill',
        'svg_viewer_button_border' => '_svg_button_border',
        'svg_viewer_button_foreground' => '_svg_button_foreground',
        'svg_viewer_pan_mode' => '_svg_pan_mode',
        'svg_viewer_zoom_mode' => '_svg_zoom_mode',
    );
    private $current_presets_admin_tab = null;
    private $asset_version = null;
    private $defaults_option_key = 'svg_viewer_preset_defaults';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->plugin_version = $this->read_plugin_version();
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
        add_action('admin_post_svg_viewer_save_defaults', array($this, 'handle_save_default_options'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_settings_link'));
    }

    /**
     * Retrieve the default shortcode attributes.
     *
     * @return array
     */
    private function get_shortcode_default_attributes()
    {
        return array(
            'src' => '',
            'height' => '600px',
            'class' => '',
            'zoom' => '100',
            'min_zoom' => '25',
            'max_zoom' => '800',
            'zoom_step' => '10',
            'center_x' => '',
            'center_y' => '',
            'show_coords' => 'false',
            'title' => '',
            'caption' => '',
            'id' => '',
            'controls_position' => 'top',
            'controls_buttons' => 'both',
            'button_fill' => '',
            'button_border' => '',
            'button_background' => '',
            'button_bg' => '',
            'button_foreground' => '',
            'button_fg' => '',
            'pan' => '',
            'pan_mode' => '',
            'zoom_mode' => '',
            'zoom_behavior' => '',
            'zoom_interaction' => '',
            'initial_zoom' => '',
        );
    }

    /**
     * Base preset editor form defaults.
     *
     * @return array
     */
    private function get_base_preset_form_defaults()
    {
        return array(
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
            'button_fill' => '',
            'button_border' => '',
            'button_foreground' => '',
            'pan_mode' => 'scroll',
            'zoom_mode' => 'super_scroll',
            'debug_cache_bust' => '',
        );
    }

    /**
     * Default values for the preset editor form, including saved defaults.
     *
     * @return array
     */
    private function get_preset_form_defaults()
    {
        $base_defaults = $this->get_base_preset_form_defaults();
        $saved_defaults = $this->get_saved_default_options();

        $defaults = wp_parse_args($saved_defaults, $base_defaults);
        $defaults['center_x'] = '';
        $defaults['center_y'] = '';

        return $defaults;
    }

    /**
     * Retrieve the saved default options for new presets.
     *
     * @return array
     */
    private function get_saved_default_options()
    {
        $stored_defaults = get_option($this->defaults_option_key, array());

        if (!is_array($stored_defaults)) {
            return array();
        }

        return $this->sanitize_default_options($stored_defaults);
    }

    /**
     * Sanitize a preset default options payload.
     *
     * @param mixed $data
     * @return array
     */
    private function sanitize_default_options($data)
    {
        $data = is_array($data) ? $data : array();
        $sanitized = array();

        $field_map = array(
            'src' => array('svg_viewer_src', 'src'),
            'attachment_id' => array('svg_viewer_attachment_id', 'attachment_id'),
            'height' => array('svg_viewer_height', 'height'),
            'min_zoom' => array('svg_viewer_min_zoom', 'min_zoom'),
            'max_zoom' => array('svg_viewer_max_zoom', 'max_zoom'),
            'initial_zoom' => array('svg_viewer_initial_zoom', 'initial_zoom'),
            'zoom_step' => array('svg_viewer_zoom_step', 'zoom_step'),
            'title' => array('svg_viewer_title', 'title'),
            'caption' => array('svg_viewer_caption', 'caption'),
            'controls_position' => array('svg_viewer_controls_position', 'controls_position'),
            'controls_buttons' => array('svg_viewer_controls_buttons', 'controls_buttons'),
            'button_fill' => array('svg_viewer_button_fill', 'button_fill'),
            'button_border' => array('svg_viewer_button_border', 'button_border'),
            'button_foreground' => array('svg_viewer_button_foreground', 'button_foreground'),
            'pan_mode' => array('svg_viewer_pan_mode', 'pan_mode'),
            'zoom_mode' => array('svg_viewer_zoom_mode', 'zoom_mode'),
            'debug_cache_bust' => array('svg_viewer_debug_cache_bust', 'debug_cache_bust'),
        );

        foreach ($field_map as $output_key => $input_keys) {
            $raw_value = null;
            $has_value = false;

            foreach ($input_keys as $candidate_key) {
                if (array_key_exists($candidate_key, $data)) {
                    $raw_value = $data[$candidate_key];
                    $has_value = true;
                    break;
                }
            }

            if (!$has_value) {
                continue;
            }

            switch ($output_key) {
                case 'src':
                    if (is_string($raw_value)) {
                        $src = trim($raw_value);
                        if ($src !== '') {
                            $sanitized['src'] = esc_url_raw($src);
                        }
                    }
                    break;

                case 'attachment_id':
                    $attachment_id = absint($raw_value);
                    if ($attachment_id > 0) {
                        $sanitized['attachment_id'] = $attachment_id;
                    }
                    break;

                case 'height':
                    if (is_string($raw_value)) {
                        $height = sanitize_text_field($raw_value);
                        if ($height !== '') {
                            $sanitized['height'] = $height;
                        }
                    }
                    break;

                case 'min_zoom':
                case 'max_zoom':
                case 'initial_zoom':
                case 'zoom_step':
                    $value_str = '';
                    if (is_string($raw_value)) {
                        $value_str = trim($raw_value);
                    } elseif (is_numeric($raw_value)) {
                        $value_str = (string) $raw_value;
                    }
                    if ($value_str === '' || !is_numeric($value_str)) {
                        break;
                    }
                    $sanitized[$output_key] = sanitize_text_field($value_str);
                    break;

                case 'title':
                    if (is_string($raw_value)) {
                        $title = sanitize_text_field($raw_value);
                        if ($title !== '') {
                            $sanitized['title'] = $title;
                        }
                    }
                    break;

                case 'caption':
                    if (is_string($raw_value)) {
                        $caption = wp_kses_post($raw_value);
                        if ($caption !== '') {
                            $sanitized['caption'] = $caption;
                        }
                    }
                    break;

                case 'controls_position':
                    if (is_string($raw_value)) {
                        $position = strtolower(sanitize_text_field($raw_value));
                        $allowed_positions = array('top', 'bottom', 'left', 'right');
                        if (!in_array($position, $allowed_positions, true)) {
                            $position = 'top';
                        }
                        $sanitized['controls_position'] = $position;
                    }
                    break;

                case 'controls_buttons':
                    if (is_string($raw_value)) {
                        $buttons = sanitize_text_field($raw_value);
                        if ($buttons !== '') {
                            $sanitized['controls_buttons'] = $buttons;
                        }
                    }
                    break;

                case 'button_fill':
                case 'button_border':
                case 'button_foreground':
                    $color = $this->sanitize_color_value(is_string($raw_value) ? $raw_value : (string) $raw_value);
                    if ($color !== '') {
                        $sanitized[$output_key] = $color;
                    }
                    break;

                case 'pan_mode':
                    $pan_mode = $this->normalize_pan_mode($raw_value);
                    if ($pan_mode !== '') {
                        $sanitized['pan_mode'] = $pan_mode;
                    }
                    break;

                case 'zoom_mode':
                    $zoom_mode = $this->normalize_zoom_mode($raw_value);
                    if ($zoom_mode !== '') {
                        $sanitized['zoom_mode'] = $zoom_mode;
                    }
                    break;

                case 'debug_cache_bust':
                    $is_enabled = false;
                    if (is_bool($raw_value)) {
                        $is_enabled = $raw_value;
                    } elseif (is_string($raw_value)) {
                        $is_enabled = in_array(strtolower($raw_value), array('1', 'true', 'yes', 'on'), true);
                    } elseif (is_numeric($raw_value)) {
                        $is_enabled = ((int) $raw_value) === 1;
                    }
                    if ($is_enabled) {
                        $sanitized['debug_cache_bust'] = '1';
                    }
                    break;
            }
        }

        return $sanitized;
    }
    /**
     * Determine whether assets should use a cache-busting version.
     *
     * @return bool
     */
    private function should_cache_bust_assets()
    {
        static $should_bust = null;

        if ($should_bust !== null) {
            return $should_bust;
        }

        $should_bust = false;
        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        if (is_string($host) && preg_match('/^(dev|wptest)\./i', $host)) {
            $should_bust = true;
        } else {
            $defaults = $this->get_saved_default_options();
            if (!empty($defaults['debug_cache_bust'])) {
                $should_bust = true;
            }
        }

        /**
         * Filters whether BT SVG Viewer assets should be cache busted.
         *
         * @param bool       $should_bust Whether to append a time-based suffix.
         * @param BTSVVI_Viewer $viewer      The plugin instance.
         */
        return (bool) apply_filters('svg_viewer_should_cache_bust_assets', $should_bust, $this);
    }

    /**
     * Retrieve the asset version string for scripts and styles.
     *
     * @param string $context Asset context (frontend|admin).
     * @return string
     */
    private function get_asset_version($context = 'frontend')
    {
        if ($this->asset_version === null) {
            $version = $this->plugin_version;

            if ($this->should_cache_bust_assets()) {
                $version .= '-' . gmdate('YmdHis');
            }

            $this->asset_version = $version;
        }

        /**
         * Filters the asset version string used by BT SVG Viewer.
         *
         * @param string     $version The computed version string.
         * @param string     $context The asset context.
         * @param BTSVVI_Viewer $viewer  The plugin instance.
         */
        return (string) apply_filters('svg_viewer_asset_version', $this->asset_version, $context, $this);
    }

    /**
     * Determine the plugin version from the file header.
     *
     * @return string
     */
    private function read_plugin_version()
    {
        $headers = get_file_data(__FILE__, array('Version' => 'Version'));
        $version = isset($headers['Version']) ? trim((string) $headers['Version']) : '';

        if ($version === '') {
            $version = '1.0.0';
        }

        return $version;
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

        $allowed_tabs = array('presets', 'defaults', 'help', 'changes');
        $requested_tab = isset($_GET['svg_tab']) ? sanitize_key(wp_unslash($_GET['svg_tab'])) : 'presets'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!in_array($requested_tab, $allowed_tabs, true)) {
            $requested_tab = 'presets';
        }

        $this->current_presets_admin_tab = $requested_tab;

        add_action('in_admin_header', array($this, 'render_presets_screen_tabs_nav'));
        add_action('all_admin_notices', array($this, 'render_presets_screen_tab_content'));

        if ($requested_tab !== 'presets') {
            add_action('admin_enqueue_scripts', array($this, 'enqueue_presets_screen_styles'), 20);
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
            'presets' => __('Presets', 'bt-svg-viewer'),
            'defaults' => __('Default Options', 'bt-svg-viewer'),
            'help' => __('Help', 'bt-svg-viewer'),
            'changes' => __('Changes', 'bt-svg-viewer'),
        );

        echo '<div class="bt-svg-viewer-admin-screen-tabs nav-tab-wrapper">';

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

        echo '<div class="bt-svg-viewer-admin-screen-panel">';

        if ($this->current_presets_admin_tab === 'help') {
            $help_markup = $this->get_admin_help_markup();
            if ($help_markup !== '') {
                echo $help_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                printf('<p>%s</p>', esc_html__('Help content is not available. Run the "Render Help/Changelog" build step to regenerate it.', 'bt-svg-viewer'));
            }
        } elseif ($this->current_presets_admin_tab === 'defaults') {
            $this->render_presets_default_options_panel();
        } elseif ($this->current_presets_admin_tab === 'changes') {
            $changelog_markup = $this->get_admin_changelog_markup();
            if ($changelog_markup !== '') {
                echo $changelog_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                printf('<p>%s</p>', esc_html__('Changes content is not available. Run the "Render Help/Changelog" build step to regenerate it.', 'bt-svg-viewer'));
            }
        }

        echo '</div>';
    }

    /**
     * Render the defaults management panel.
     *
     * @return void
     */
    private function render_presets_default_options_panel()
    {
        if (!current_user_can('manage_options')) {
            printf('<p>%s</p>', esc_html__('You do not have permission to modify the default options.', 'bt-svg-viewer'));
            return;
        }

        $defaults = $this->get_preset_form_defaults();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only query arg for status messaging.
        $status = isset($_GET['svg_defaults_status']) ? sanitize_key(wp_unslash($_GET['svg_defaults_status'])) : '';

        if ($status === 'updated') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Default options updated.', 'bt-svg-viewer') . '</p></div>';
        } elseif ($status === 'error') {
            echo '<div class="notice notice-error"><p>' . esc_html__('Unable to update the default options. Please try again.', 'bt-svg-viewer') . '</p></div>';
        }

        $form_action = admin_url('admin-post.php');
        ?>
        <p><?php esc_html_e('Adjust the defaults that populate new BT SVG Viewer presets. Existing presets are not affected.', 'bt-svg-viewer'); ?>
        </p>
        <p><?php esc_html_e('Center X and Center Y automatically default to the middle of the SVG and are not configurable here. Set preset-specific SVG sources while editing each preset.', 'bt-svg-viewer'); ?>
        </p>
        <form method="post" action="<?php echo esc_url($form_action); ?>" class="bt-svg-viewer-defaults-form">
            <?php wp_nonce_field('svg_viewer_save_defaults', 'svg_viewer_defaults_nonce'); ?>
            <input type="hidden" name="action" value="svg_viewer_save_defaults" />
            <div class="bt-svg-viewer-defaults-meta">
                <div class="bt-svg-viewer-field-group">
                    <div class="bt-svg-viewer-field">
                        <label for="bt-svg-viewer-default-height"><?php esc_html_e('Viewer Height', 'bt-svg-viewer'); ?></label>
                        <input type="text" id="bt-svg-viewer-default-height" name="svg_viewer_height"
                            value="<?php echo esc_attr($defaults['height']); ?>" placeholder="600px" />
                    </div>
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-min-zoom"><?php esc_html_e('Min Zoom (%)', 'bt-svg-viewer'); ?></label>
                        <input type="number" id="bt-svg-viewer-default-min-zoom" name="svg_viewer_min_zoom"
                            value="<?php echo esc_attr($defaults['min_zoom']); ?>" min="1" step="1" />
                    </div>
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-max-zoom"><?php esc_html_e('Max Zoom (%)', 'bt-svg-viewer'); ?></label>
                        <input type="number" id="bt-svg-viewer-default-max-zoom" name="svg_viewer_max_zoom"
                            value="<?php echo esc_attr($defaults['max_zoom']); ?>" min="1" step="1" />
                    </div>
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-initial-zoom"><?php esc_html_e('Initial Zoom (%)', 'bt-svg-viewer'); ?></label>
                        <input type="number" id="bt-svg-viewer-default-initial-zoom" name="svg_viewer_initial_zoom"
                            value="<?php echo esc_attr($defaults['initial_zoom']); ?>" min="1" step="1" />
                    </div>
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-zoom-step"><?php esc_html_e('Zoom Increment (%)', 'bt-svg-viewer'); ?></label>
                        <input type="number" id="bt-svg-viewer-default-zoom-step" name="svg_viewer_zoom_step"
                            value="<?php echo esc_attr($defaults['zoom_step']); ?>" min="0.1" step="0.1" />
                    </div>
                </div>

                <div class="bt-svg-viewer-field-group">
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-controls-position"><?php esc_html_e('Controls Position', 'bt-svg-viewer'); ?></label>
                        <select id="bt-svg-viewer-default-controls-position" name="svg_viewer_controls_position">
                            <?php
                            $positions_options = array(
                                'top' => __('Top', 'bt-svg-viewer'),
                                'bottom' => __('Bottom', 'bt-svg-viewer'),
                                'left' => __('Left', 'bt-svg-viewer'),
                                'right' => __('Right', 'bt-svg-viewer'),
                            );
                            foreach ($positions_options as $pos_value => $label):
                                ?>
                                <option value="<?php echo esc_attr($pos_value); ?>" <?php selected($defaults['controls_position'], $pos_value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-controls-buttons"><?php esc_html_e('Controls Buttons/Layout', 'bt-svg-viewer'); ?></label>
                        <input type="text" id="bt-svg-viewer-default-controls-buttons" name="svg_viewer_controls_buttons"
                            value="<?php echo esc_attr($defaults['controls_buttons']); ?>" placeholder="both" />
                        <p class="description">
                            <?php esc_html_e('Combine multiple options with commas. Examples: both, icon, text, compact, labels-on-hover, minimal, alignleft, aligncenter, alignright, custom,both,aligncenter,zoom_in,zoom_out,reset,center,coords', 'bt-svg-viewer'); ?>
                        </p>
                    </div>
                </div>

                <div class="bt-svg-viewer-field-group">
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-pan-mode"><?php esc_html_e('Pan Interaction', 'bt-svg-viewer'); ?></label>
                        <select id="bt-svg-viewer-default-pan-mode" name="svg_viewer_pan_mode">
                            <?php
                            $pan_options = array(
                                'scroll' => __('Scroll (default)', 'bt-svg-viewer'),
                                'drag' => __('Drag to pan', 'bt-svg-viewer'),
                            );
                            foreach ($pan_options as $pan_value => $pan_label):
                                ?>
                                <option value="<?php echo esc_attr($pan_value); ?>" <?php selected($defaults['pan_mode'], $pan_value); ?>>
                                    <?php echo esc_html($pan_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Choose how visitors move around the SVG. Drag temporarily replaces scroll when required by other settings.', 'bt-svg-viewer'); ?>
                        </p>
                    </div>
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-zoom-mode"><?php esc_html_e('Zoom Interaction', 'bt-svg-viewer'); ?></label>
                        <select id="bt-svg-viewer-default-zoom-mode" name="svg_viewer_zoom_mode">
                            <?php
                            $zoom_options = array(
                                'super_scroll' => __('Cmd/Ctrl-scroll (default)', 'bt-svg-viewer'),
                                'scroll' => __('Scroll wheel (no modifier)', 'bt-svg-viewer'),
                                'click' => __('Modifier click', 'bt-svg-viewer'),
                            );
                            foreach ($zoom_options as $zoom_value => $zoom_label):
                                ?>
                                <option value="<?php echo esc_attr($zoom_value); ?>" <?php selected($defaults['zoom_mode'], $zoom_value); ?>>
                                    <?php echo esc_html($zoom_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Scroll wheel zoom overrides pan-on-scroll. Cmd/Ctrl-click zooms in and Option/Alt-click zooms out when using modifier click.', 'bt-svg-viewer'); ?>
                        </p>
                    </div>
                    <div class="bt-svg-viewer-field bt-svg-viewer-field-checkbox">
                        <label for="bt-svg-viewer-debug-cache-bust">
                            <input type="checkbox" id="bt-svg-viewer-debug-cache-bust" name="svg_viewer_debug_cache_bust"
                                value="1" <?php checked(!empty($defaults['debug_cache_bust'])); ?> />
                            <?php esc_html_e('Enable asset cache busting for debugging', 'bt-svg-viewer'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Adds a unique suffix to script and style versions so browsers always fetch the latest assets. Useful for local testing; disable for production.', 'bt-svg-viewer'); ?>
                        </p>
                    </div>
                </div>

                <div class="bt-svg-viewer-field-group">
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-button-fill"><?php esc_html_e('Button Fill Color', 'bt-svg-viewer'); ?></label>
                        <input type="text" id="bt-svg-viewer-default-button-fill" name="svg_viewer_button_fill"
                            class="bt-svg-viewer-color-field" value="<?php echo esc_attr($defaults['button_fill']); ?>"
                            data-default-color="#0073aa" />
                        <p class="description">
                            <?php esc_html_e('Choose the primary color for the control buttons. Leave blank to use the default.', 'bt-svg-viewer'); ?>
                        </p>
                    </div>
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-button-border"><?php esc_html_e('Button Border Color', 'bt-svg-viewer'); ?></label>
                        <input type="text" id="bt-svg-viewer-default-button-border" name="svg_viewer_button_border"
                            class="bt-svg-viewer-color-field" value="<?php echo esc_attr($defaults['button_border']); ?>"
                            data-default-color="#0073aa" />
                        <p class="description">
                            <?php esc_html_e('Set the border color for the control buttons. Leave blank to match the fill color.', 'bt-svg-viewer'); ?>
                        </p>
                    </div>
                    <div class="bt-svg-viewer-field">
                        <label
                            for="bt-svg-viewer-default-button-foreground"><?php esc_html_e('Button Foreground Color', 'bt-svg-viewer'); ?></label>
                        <input type="text" id="bt-svg-viewer-default-button-foreground" name="svg_viewer_button_foreground"
                            class="bt-svg-viewer-color-field" value="<?php echo esc_attr($defaults['button_foreground']); ?>"
                            data-default-color="#ffffff" />
                        <p class="description">
                            <?php esc_html_e('Set the icon and text color for the control buttons. Leave blank to use the default.', 'bt-svg-viewer'); ?>
                        </p>
                    </div>
                </div>

                <div class="bt-svg-viewer-field">
                    <label for="bt-svg-viewer-default-title"><?php esc_html_e('Title (optional)', 'bt-svg-viewer'); ?></label>
                    <input type="text" id="bt-svg-viewer-default-title" name="svg_viewer_title"
                        value="<?php echo esc_attr($defaults['title']); ?>" />
                </div>

                <div class="bt-svg-viewer-field">
                    <label
                        for="bt-svg-viewer-default-caption"><?php esc_html_e('Caption (optional)', 'bt-svg-viewer'); ?></label>
                    <textarea id="bt-svg-viewer-default-caption" name="svg_viewer_caption" rows="3"
                        class="widefat"><?php echo esc_textarea($defaults['caption']); ?></textarea>
                    <p class="description"><?php esc_html_e('Supports basic HTML formatting.', 'bt-svg-viewer'); ?></p>
                </div>
            </div>
            <?php submit_button(__('Save Default Options', 'bt-svg-viewer')); ?>
        </form>
        <?php
    }

    /**
     * Hide the default list table UI when non-preset tabs are active.
     *
     * @return void
     */
    public function enqueue_presets_screen_styles()
    {
        if ($this->current_presets_admin_tab === null || $this->current_presets_admin_tab === 'presets') {
            return;
        }

        $css_rules = array(
            '.post-type-svg_viewer_preset .wrap .tablenav,' .
            '.post-type-svg_viewer_preset .wrap .wp-list-table,' .
            '.post-type-svg_viewer_preset .wrap .subsubsub,' .
            '.post-type-svg_viewer_preset .wrap .search-box,' .
            '.post-type-svg_viewer_preset .wrap .tablenav.bottom,' .
            '.post-type-svg_viewer_preset .wrap .wp-heading-inline,' .
            '.post-type-svg_viewer_preset .wrap .page-title-action,' .
            '.post-type-svg_viewer_preset .wrap .alignleft.actions,' .
            '.post-type-svg_viewer_preset .wrap .tablenav-pages,' .
            '#screen-options-link-wrap,' .
            '#contextual-help-link { display: none !important; }',
            '.bt-svg-viewer-admin-screen-panel { margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #dcdcde; box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04); }',
            '.bt-svg-viewer-admin-screen-panel table { width: 100%; border-collapse: collapse; }',
            '.bt-svg-viewer-admin-screen-panel th, .bt-svg-viewer-admin-screen-panel td { border: 1px solid #dcdcde; padding: 8px; text-align: left; }',
        );

        wp_add_inline_style('bt-svg-viewer-admin', implode("\n", $css_rules));
    }

    /**
     * Handle saving default options from the presets list screen.
     *
     * @return void
     */
    public function handle_save_default_options()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'bt-svg-viewer'));
        }

        $defaults_nonce = isset($_POST['svg_viewer_defaults_nonce']) ? sanitize_text_field(wp_unslash($_POST['svg_viewer_defaults_nonce'])) : '';

        if ($defaults_nonce === '' || !wp_verify_nonce($defaults_nonce, 'svg_viewer_save_defaults')) {
            wp_die(esc_html__('Security check failed. Please try again.', 'bt-svg-viewer'));
        }

        $input_keys = array(
            'svg_viewer_src',
            'svg_viewer_attachment_id',
            'svg_viewer_height',
            'svg_viewer_min_zoom',
            'svg_viewer_max_zoom',
            'svg_viewer_initial_zoom',
            'svg_viewer_zoom_step',
            'svg_viewer_title',
            'svg_viewer_caption',
            'svg_viewer_controls_position',
            'svg_viewer_controls_buttons',
            'svg_viewer_button_fill',
            'svg_viewer_button_border',
            'svg_viewer_button_foreground',
            'svg_viewer_pan_mode',
            'svg_viewer_zoom_mode',
            'svg_viewer_debug_cache_bust',
        );

        $raw_input = array();
        foreach ($input_keys as $key) {
            if (isset($_POST[$key])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in sanitize_default_options().
                $raw_input[$key] = wp_unslash($_POST[$key]);
            }
        }

        $sanitized_defaults = $this->sanitize_default_options($raw_input);
        update_option($this->defaults_option_key, $sanitized_defaults);

        $redirect_url = add_query_arg(
            array(
                'post_type' => 'svg_viewer_preset',
                'svg_tab' => 'defaults',
                'svg_defaults_status' => 'updated',
            ),
            admin_url('edit.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
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
        $asset_version = $this->get_asset_version('frontend');

        wp_enqueue_style(
            'bt-svg-viewer-style',
            plugins_url('css/bt-svg-viewer.css', __FILE__),
            array(),
            $asset_version
        );

        wp_enqueue_script(
            'bt-svg-viewer-script',
            plugins_url('js/bt-svg-viewer.js', __FILE__),
            array(),
            $asset_version,
            true
        );

        // Pass plugin URL to JavaScript
        wp_localize_script('bt-svg-viewer-script', 'svgViewerConfig', array(
            'pluginUrl' => plugins_url('', __FILE__),
            'assetVersion' => $asset_version,
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

        $atts = shortcode_atts($this->get_shortcode_default_attributes(), $atts, 'svg_viewer');

        if (!array_key_exists('button_fill', $raw_atts)) {
            if (array_key_exists('button_background', $raw_atts)) {
                $raw_atts['button_fill'] = $raw_atts['button_background'];
            } elseif (array_key_exists('button_bg', $raw_atts)) {
                $raw_atts['button_fill'] = $raw_atts['button_bg'];
            }
        }

        if (!array_key_exists('button_foreground', $raw_atts)) {
            if (array_key_exists('button_fg', $raw_atts)) {
                $raw_atts['button_foreground'] = $raw_atts['button_fg'];
            }
        }

        $button_background = $atts['button_background'];
        if ($button_background === '') {
            $button_background = $atts['button_bg'];
        }
        if ($button_background === '') {
            $button_background = $atts['button_fill'];
        }
        $atts['button_fill'] = $button_background;

        $button_foreground = $atts['button_foreground'];
        if ($button_foreground === '') {
            $button_foreground = $atts['button_fg'];
        }
        $atts['button_foreground'] = $button_foreground;

        if ($atts['initial_zoom'] !== '') {
            $atts['zoom'] = $atts['initial_zoom'];
        }

        $zoom_mode_from_zoom = '';
        if (isset($raw_atts['zoom']) && !is_numeric($raw_atts['zoom'])) {
            $zoom_mode_from_zoom = $raw_atts['zoom'];
            $atts['zoom'] = '100';
        }

        if (!empty($atts['id'])) {
            $preset_id = absint($atts['id']);
            $preset_data = $this->get_preset_settings($preset_id);

            if (!$preset_data) {
                $error_message = sprintf(
                    '<div style="color: red; padding: 10px; border: 1px solid red;">%s</div>',
                    esc_html(
                        sprintf(
                            /* translators: %s: Requested preset ID. */
                            __('Error: SVG preset not found for ID %s.', 'bt-svg-viewer'),
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

        $pan_mode_input = $atts['pan_mode'] !== '' ? $atts['pan_mode'] : $atts['pan'];
        $zoom_mode_input = '';
        if ($atts['zoom_mode'] !== '') {
            $zoom_mode_input = $atts['zoom_mode'];
        } elseif ($atts['zoom_behavior'] !== '') {
            $zoom_mode_input = $atts['zoom_behavior'];
        } elseif ($atts['zoom_interaction'] !== '') {
            $zoom_mode_input = $atts['zoom_interaction'];
        } elseif ($zoom_mode_from_zoom !== '') {
            $zoom_mode_input = $zoom_mode_from_zoom;
        }

        $interaction_config = $this->resolve_interaction_config($pan_mode_input, $zoom_mode_input);
        $pan_mode = $interaction_config['pan_mode'];
        $zoom_mode = $interaction_config['zoom_mode'];
        $interaction_messages = $interaction_config['messages'];

        // Validate src
        if (empty($atts['src'])) {
            $error_message = sprintf(
                '<div style="color: red; padding: 10px; border: 1px solid red;">%s</div>',
                esc_html__(
                    'Error: SVG source not specified. Use [svg_viewer src="path/to/file.svg"]',
                    'bt-svg-viewer'
                )
            );
            return $error_message;
        }

        // Convert relative paths to absolute URLs
        $svg_url = $this->get_svg_url($atts['src']);

        if (!$svg_url) {
            $error_message = sprintf(
                '<div style="color: red; padding: 10px; border: 1px solid red;">%s</div>',
                esc_html__('Error: Invalid SVG path.', 'bt-svg-viewer')
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
        $viewer_id = 'bt-svg-viewer-' . uniqid();
        $custom_class = sanitize_html_class($atts['class']);

        $title = trim($atts['title']);
        $caption = trim($atts['caption']);
        $controls_config = $this->parse_controls_config(
            $atts['controls_position'],
            $atts['controls_buttons'],
            $show_coords
        );
        $initial_zoom_percent = (int) round($initial_zoom * 100);
        $min_zoom_percent_value = (int) round(max(1, floatval($atts['min_zoom'])));
        $max_zoom_percent_value = (int) round(max($min_zoom_percent_value, floatval($atts['max_zoom'])));
        $zoom_step_percent_value = (int) max(1, round(max(0.1, floatval($atts['zoom_step']))));

        $controls_markup = $this->render_controls_markup(
            $viewer_id,
            $controls_config,
            $initial_zoom_percent,
            $min_zoom_percent_value,
            $max_zoom_percent_value,
            $zoom_step_percent_value
        );

        $button_style_declarations = $this->get_button_color_style_declarations(
            $atts['button_fill'],
            $atts['button_border'],
            $atts['button_foreground']
        );
        $wrapper_style_attribute_value = $this->build_style_attribute($button_style_declarations);

        $wrapper_classes = array('bt-svg-viewer-wrapper');
        if (!empty($custom_class)) {
            $wrapper_classes[] = $custom_class;
        }
        $wrapper_classes[] = 'controls-position-' . $controls_config['position'];
        $wrapper_classes[] = 'controls-mode-' . $controls_config['mode'];
        foreach ($controls_config['styles'] as $style_class) {
            $wrapper_classes[] = 'controls-style-' . $style_class;
        }
        $wrapper_classes[] = 'controls-align-' . $controls_config['alignment'];
        $wrapper_classes[] = 'pan-mode-' . $pan_mode;
        $wrapper_classes[] = 'zoom-mode-' . $zoom_mode;
        if ($controls_config['mode'] === 'hidden') {
            $wrapper_classes[] = 'controls-hidden';
        }

        $atts['button_fill'] = $this->sanitize_color_value($atts['button_fill']);
        $atts['button_border'] = $this->sanitize_color_value($atts['button_border']);
        $atts['button_foreground'] = $this->sanitize_color_value($atts['button_foreground']);

        $main_classes = array(
            'bt-svg-viewer-main',
            'controls-position-' . $controls_config['position'],
            'controls-mode-' . $controls_config['mode'],
        );
        foreach ($controls_config['styles'] as $style_class) {
            $main_classes[] = 'controls-style-' . $style_class;
        }
        $main_classes[] = 'controls-align-' . $controls_config['alignment'];
        $main_classes[] = 'pan-mode-' . $pan_mode;
        $main_classes[] = 'zoom-mode-' . $zoom_mode;

        $wrapper_class_attribute = $this->build_class_attribute($wrapper_classes);
        $main_class_attribute = $this->build_class_attribute($main_classes);

        $viewer_config = array(
            'viewerId' => $viewer_id,
            'svgUrl' => $svg_url,
            'initialZoom' => $initial_zoom,
            'minZoom' => $min_zoom,
            'maxZoom' => $max_zoom,
            'zoomStep' => $zoom_step,
            'centerX' => $center_x,
            'centerY' => $center_y,
            'showCoordinates' => $show_coords,
            'panMode' => $pan_mode,
            'zoomMode' => $zoom_mode,
        );

        $this->queue_viewer_initialization($viewer_config);

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapper_class_attribute); ?>" id="<?php echo esc_attr($viewer_id); ?>" <?php
              if ($wrapper_style_attribute_value !== '') {
                  echo ' style="' . esc_attr($wrapper_style_attribute_value) . '"';
              }
              ?>>
            <?php if (!empty($title)): ?>
                <div class="bt-svg-viewer-title"><?php echo wp_kses_post($title); ?></div>
            <?php endif; ?>
            <div class="<?php echo esc_attr($main_class_attribute); ?>" data-viewer="<?php echo esc_attr($viewer_id); ?>">
                <?php if ($controls_markup !== ''): ?>
                    <?php echo wp_kses($controls_markup, $this->get_controls_allowed_html()); ?>
                <?php endif; ?>
                <div class="svg-container" style="height: <?php echo esc_attr($atts['height']); ?>"
                    data-viewer="<?php echo esc_attr($viewer_id); ?>">
                    <div class="svg-viewport" data-viewer="<?php echo esc_attr($viewer_id); ?>">
                        <!-- SVG will be loaded here -->
                    </div>
                </div>
            </div>
            <?php if (!empty($interaction_messages)): ?>
                <div class="bt-svg-viewer-caption bt-svg-viewer-interaction-caption">
                    <?php echo implode('<br />', array_map('esc_html', $interaction_messages)); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($caption)): ?>
                <div class="bt-svg-viewer-caption"><?php echo wp_kses_post($caption); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Queue inline viewer initialization data for JavaScript consumption.
     *
     * @param array $config Viewer configuration.
     * @return void
     */
    private function queue_viewer_initialization(array $config)
    {
        if (empty($config['viewerId']) || empty($config['svgUrl'])) {
            return;
        }

        wp_enqueue_script('bt-svg-viewer-script');

        $config_json = wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $config_json) {
            return;
        }

        $inline = 'window.BTSVVI_VIEWER_QUEUE = window.BTSVVI_VIEWER_QUEUE || [];'
            . 'window.BTSVVI_VIEWER_QUEUE.push(' . $config_json . ');';

        wp_add_inline_script('bt-svg-viewer-script', $inline, 'after');
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
            'name' => __('BT SVG Viewer Presets', 'bt-svg-viewer'),
            'singular_name' => __('BT SVG Viewer Preset', 'bt-svg-viewer'),
            'menu_name' => __('BT SVG Viewer', 'bt-svg-viewer'),
            'add_new' => __('Add New Preset', 'bt-svg-viewer'),
            'add_new_item' => __('Add New BT SVG Viewer Preset', 'bt-svg-viewer'),
            'edit_item' => __('Edit BT SVG Viewer Preset', 'bt-svg-viewer'),
            'new_item' => __('New BT SVG Viewer Preset', 'bt-svg-viewer'),
            'view_item' => __('View BT SVG Viewer Preset', 'bt-svg-viewer'),
            'search_items' => __('Search BT SVG Viewer Presets', 'bt-svg-viewer'),
            'not_found' => __('No presets found', 'bt-svg-viewer'),
            'not_found_in_trash' => __('No presets found in trash', 'bt-svg-viewer'),
            'all_items' => __('BT SVG Viewer Presets', 'bt-svg-viewer'),
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
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        $asset_version = $this->get_asset_version('admin');

        wp_enqueue_style(
            'bt-svg-viewer-style',
            plugins_url('css/bt-svg-viewer.css', __FILE__),
            array(),
            $asset_version
        );

        wp_enqueue_style(
            'bt-svg-viewer-admin',
            plugins_url('admin/css/admin.css', __FILE__),
            array('bt-svg-viewer-style'),
            $asset_version
        );

        wp_enqueue_script(
            'bt-svg-viewer-script',
            plugins_url('js/bt-svg-viewer.js', __FILE__),
            array(),
            $asset_version,
            true
        );

        wp_localize_script('bt-svg-viewer-script', 'svgViewerConfig', array(
            'pluginUrl' => plugins_url('', __FILE__),
            'assetVersion' => $asset_version,
        ));

        wp_enqueue_script(
            'bt-svg-viewer-admin',
            plugins_url('admin/js/admin.js', __FILE__),
            array('jquery', 'bt-svg-viewer-script', 'wp-color-picker'),
            $asset_version,
            true
        );

        $button_definitions = $this->get_button_definitions();

        wp_localize_script('bt-svg-viewer-admin', 'svgViewerAdmin', array(
            'i18n' => array(
                'missingSrc' => __('Please select an SVG before loading the preview.', 'bt-svg-viewer'),
                'captureSaved' => __('Captured viewer state from the preview.', 'bt-svg-viewer'),
                'captureFailed' => __('Unable to capture the current state. Refresh the preview and try again.', 'bt-svg-viewer'),
                'copySuccess' => __('Shortcode copied to clipboard.', 'bt-svg-viewer'),
                'copyFailed' => __('Press ⌘/Ctrl+C to copy the shortcode.', 'bt-svg-viewer'),
                'fullCopySuccess' => __('Full shortcode copied to clipboard.', 'bt-svg-viewer'),
            ),
            'controls' => array(
                'buttons' => $button_definitions,
            ),
            'formDefaults' => $this->get_preset_form_defaults(),
            'assetVersion' => $asset_version,
        ));
    }

    /**
     * Register meta boxes for presets
     */
    public function register_preset_meta_boxes($post)
    {
        add_meta_box(
            'bt-svg-viewer-preset-settings',
            __('BT SVG Viewer Settings', 'bt-svg-viewer'),
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

        $defaults = $this->get_preset_form_defaults();

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
            'button_fill' => get_post_meta($post->ID, '_svg_button_fill', true),
            'button_border' => get_post_meta($post->ID, '_svg_button_border', true),
            'button_foreground' => get_post_meta($post->ID, '_svg_button_foreground', true),
            'pan_mode' => get_post_meta($post->ID, '_svg_pan_mode', true),
            'zoom_mode' => get_post_meta($post->ID, '_svg_zoom_mode', true),
        );

        foreach ($values as $key => $value) {
            if ($value === '' || $value === null) {
                unset($values[$key]);
            }
        }

        $values = wp_parse_args($values, $defaults);
        $values['pan_mode'] = $this->normalize_pan_mode($values['pan_mode']);
        $values['zoom_mode'] = $this->normalize_zoom_mode($values['zoom_mode']);

        $viewer_id = 'bt-svg-viewer-admin-' . uniqid();
        $shortcode = $this->get_preset_shortcode($post->ID);
        $initial_zoom_value = is_numeric($values['initial_zoom']) ? (int) $values['initial_zoom'] : 100;
        $min_zoom_value = is_numeric($values['min_zoom']) ? (float) $values['min_zoom'] : 25.0;
        $max_zoom_value = is_numeric($values['max_zoom']) ? (float) $values['max_zoom'] : 800.0;
        $zoom_step_value = is_numeric($values['zoom_step']) ? (float) $values['zoom_step'] : 10.0;
        $min_zoom_percent_value = (int) round(max(1, $min_zoom_value));
        $max_zoom_percent_value = (int) round(max($min_zoom_percent_value, $max_zoom_value));
        $zoom_step_percent_value = (int) max(1, round(max(0.1, $zoom_step_value)));
        $preview_controls_config = $this->parse_controls_config(
            $values['controls_position'],
            $values['controls_buttons'],
            false
        );
        $preview_controls_markup = $this->render_controls_markup(
            $viewer_id,
            $preview_controls_config,
            $initial_zoom_value,
            $min_zoom_percent_value,
            $max_zoom_percent_value,
            $zoom_step_percent_value
        );
        $preview_interactions = $this->resolve_interaction_config($values['pan_mode'], $values['zoom_mode']);
        $preview_pan_mode = $preview_interactions['pan_mode'];
        $preview_zoom_mode = $preview_interactions['zoom_mode'];

        $wrapper_classes = array(
            'bt-svg-viewer-wrapper',
            'bt-svg-viewer-admin-wrapper',
            'controls-position-' . $preview_controls_config['position'],
            'controls-mode-' . $preview_controls_config['mode'],
            'pan-mode-' . $preview_pan_mode,
            'zoom-mode-' . $preview_zoom_mode,
        );
        foreach ($preview_controls_config['styles'] as $style_class) {
            $wrapper_classes[] = 'controls-style-' . $style_class;
        }
        if ($preview_controls_config['mode'] === 'hidden') {
            $wrapper_classes[] = 'controls-hidden';
        }

        $main_classes = array(
            'bt-svg-viewer-main',
            'controls-position-' . $preview_controls_config['position'],
            'controls-mode-' . $preview_controls_config['mode'],
            'pan-mode-' . $preview_pan_mode,
            'zoom-mode-' . $preview_zoom_mode,
        );
        foreach ($preview_controls_config['styles'] as $style_class) {
            $main_classes[] = 'controls-style-' . $style_class;
        }

        $wrapper_class_attribute = $this->build_class_attribute($wrapper_classes);
        $main_class_attribute = $this->build_class_attribute($main_classes);
        $wrapper_style_attribute_value = $this->build_style_attribute(
            $this->get_button_color_style_declarations(
                $values['button_fill'],
                $values['button_border'],
                $values['button_foreground']
            )
        );
        $settings_panel_id = $viewer_id . '-tab-settings';
        $help_panel_id = $viewer_id . '-tab-help';
        $changes_panel_id = $viewer_id . '-tab-changes';
        ?>
        <div class="bt-svg-viewer-tabs" data-viewer-id="<?php echo esc_attr($viewer_id); ?>">
            <div class="bt-svg-viewer-tab-nav" role="tablist">
                <button type="button" class="bt-svg-viewer-tab-button is-active" role="tab"
                    id="<?php echo esc_attr($settings_panel_id); ?>-tab"
                    aria-controls="<?php echo esc_attr($settings_panel_id); ?>" aria-selected="true" data-tab-target="settings">
                    <?php esc_html_e('Settings', 'bt-svg-viewer'); ?>
                </button>
                <button type="button" class="bt-svg-viewer-tab-button" role="tab"
                    id="<?php echo esc_attr($help_panel_id); ?>-tab" aria-controls="<?php echo esc_attr($help_panel_id); ?>"
                    aria-selected="false" data-tab-target="help">
                    <?php esc_html_e('Help', 'bt-svg-viewer'); ?>
                </button>
                <button type="button" class="bt-svg-viewer-tab-button" role="tab"
                    id="<?php echo esc_attr($changes_panel_id); ?>-tab"
                    aria-controls="<?php echo esc_attr($changes_panel_id); ?>" aria-selected="false" data-tab-target="changes">
                    <?php esc_html_e('Changes', 'bt-svg-viewer'); ?>
                </button>
            </div>
            <div class="bt-svg-viewer-tab-panels">
                <div class="bt-svg-viewer-tab-panel is-active" role="tabpanel" id="<?php echo esc_attr($settings_panel_id); ?>"
                    aria-labelledby="<?php echo esc_attr($settings_panel_id); ?>-tab" data-tab-panel="settings">
                    <div class="bt-svg-viewer-admin-meta" data-viewer-id="<?php echo esc_attr($viewer_id); ?>"
                        data-preset-id="<?php echo esc_attr($post->ID); ?>">
                        <div class="bt-svg-viewer-shortcode-display">
                            <label
                                for="bt-svg-viewer-shortcode"><?php esc_html_e('Preset Shortcode', 'bt-svg-viewer'); ?></label>
                            <div class="svg-shortcode-wrap">
                                <input type="text" id="bt-svg-viewer-shortcode" class="svg-shortcode-input" readonly
                                    value="<?php echo esc_attr($shortcode); ?>">
                                <button type="button" class="button svg-shortcode-copy"
                                    data-shortcode="<?php echo esc_attr($shortcode); ?>">
                                    <?php esc_html_e('Copy', 'bt-svg-viewer'); ?>
                                </button>
                                <button type="button" class="button svg-shortcode-full">
                                    <?php esc_html_e('Full Shortcode', 'bt-svg-viewer'); ?>
                                </button>
                                <span class="svg-shortcode-status" aria-live="polite"></span>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Use this shortcode in pages or posts to embed this preset.', 'bt-svg-viewer'); ?>
                            </p>
                        </div>

                        <div class="bt-svg-viewer-field">
                            <label for="bt-svg-viewer-src"><?php esc_html_e('SVG Source URL', 'bt-svg-viewer'); ?></label>
                            <div class="bt-svg-viewer-media-control">
                                <input type="text" id="bt-svg-viewer-src" name="svg_viewer_src"
                                    value="<?php echo esc_attr($values['src']); ?>"
                                    placeholder="<?php esc_attr_e('https://example.com/my-graphic.svg or uploads/2025/graphic.svg', 'bt-svg-viewer'); ?>" />
                                <button type="button"
                                    class="button bt-svg-viewer-select-media"><?php esc_html_e('Select SVG', 'bt-svg-viewer'); ?></button>
                            </div>
                            <input type="hidden" name="svg_viewer_attachment_id"
                                value="<?php echo esc_attr($values['attachment_id']); ?>" />
                        </div>

                        <div class="bt-svg-viewer-field-group">
                            <div class="bt-svg-viewer-field">
                                <label for="bt-svg-viewer-height"><?php esc_html_e('Viewer Height', 'bt-svg-viewer'); ?></label>
                                <input type="text" id="bt-svg-viewer-height" name="svg_viewer_height"
                                    value="<?php echo esc_attr($values['height']); ?>" placeholder="600px" />
                            </div>
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-min-zoom"><?php esc_html_e('Min Zoom (%)', 'bt-svg-viewer'); ?></label>
                                <input type="number" id="bt-svg-viewer-min-zoom" name="svg_viewer_min_zoom"
                                    value="<?php echo esc_attr($values['min_zoom']); ?>" min="1" step="1" />
                            </div>
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-max-zoom"><?php esc_html_e('Max Zoom (%)', 'bt-svg-viewer'); ?></label>
                                <input type="number" id="bt-svg-viewer-max-zoom" name="svg_viewer_max_zoom"
                                    value="<?php echo esc_attr($values['max_zoom']); ?>" min="1" step="1" />
                            </div>
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-initial-zoom"><?php esc_html_e('Initial Zoom (%)', 'bt-svg-viewer'); ?></label>
                                <input type="number" id="bt-svg-viewer-initial-zoom" name="svg_viewer_initial_zoom"
                                    value="<?php echo esc_attr($values['initial_zoom']); ?>" min="1" step="1" />
                            </div>
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-zoom-step"><?php esc_html_e('Zoom Increment (%)', 'bt-svg-viewer'); ?></label>
                                <input type="number" id="bt-svg-viewer-zoom-step" name="svg_viewer_zoom_step"
                                    value="<?php echo esc_attr($values['zoom_step']); ?>" min="0.1" step="0.1" />
                            </div>
                        </div>

                        <div class="bt-svg-viewer-field-group">
                            <div class="bt-svg-viewer-field">
                                <label for="bt-svg-viewer-center-x"><?php esc_html_e('Center X', 'bt-svg-viewer'); ?></label>
                                <input type="number" id="bt-svg-viewer-center-x" name="svg_viewer_center_x"
                                    value="<?php echo esc_attr($values['center_x']); ?>" step="0.01" />
                            </div>
                            <div class="bt-svg-viewer-field">
                                <label for="bt-svg-viewer-center-y"><?php esc_html_e('Center Y', 'bt-svg-viewer'); ?></label>
                                <input type="number" id="bt-svg-viewer-center-y" name="svg_viewer_center_y"
                                    value="<?php echo esc_attr($values['center_y']); ?>" step="0.01" />
                            </div>
                        </div>

                        <div class="bt-svg-viewer-field-group">
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-controls-position"><?php esc_html_e('Controls Position', 'bt-svg-viewer'); ?></label>
                                <select id="bt-svg-viewer-controls-position" name="svg_viewer_controls_position">
                                    <?php
                                    $positions_options = array(
                                        'top' => __('Top', 'bt-svg-viewer'),
                                        'bottom' => __('Bottom', 'bt-svg-viewer'),
                                        'left' => __('Left', 'bt-svg-viewer'),
                                        'right' => __('Right', 'bt-svg-viewer'),
                                    );
                                    foreach ($positions_options as $pos_value => $label):
                                        ?>
                                        <option value="<?php echo esc_attr($pos_value); ?>" <?php selected($values['controls_position'], $pos_value); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-controls-buttons"><?php esc_html_e('Controls Buttons/Layout', 'bt-svg-viewer'); ?></label>
                                <input type="text" id="bt-svg-viewer-controls-buttons" name="svg_viewer_controls_buttons"
                                    value="<?php echo esc_attr($values['controls_buttons']); ?>" placeholder="both" />
                                <p class="description">
                                    <?php esc_html_e('Combine multiple options with commas. Examples: both, icon, text, compact, labels-on-hover, minimal, alignleft, aligncenter, alignright, custom,both,aligncenter,zoom_in,zoom_out,reset,center,coords', 'bt-svg-viewer'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="bt-svg-viewer-field-group">
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-pan-mode"><?php esc_html_e('Pan Interaction', 'bt-svg-viewer'); ?></label>
                                <select id="bt-svg-viewer-pan-mode" name="svg_viewer_pan_mode">
                                    <?php
                                    $pan_options = array(
                                        'scroll' => __('Scroll (default)', 'bt-svg-viewer'),
                                        'drag' => __('Drag to pan', 'bt-svg-viewer'),
                                    );
                                    foreach ($pan_options as $pan_value => $pan_label):
                                        ?>
                                        <option value="<?php echo esc_attr($pan_value); ?>" <?php selected($values['pan_mode'], $pan_value); ?>>
                                            <?php echo esc_html($pan_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Choose how visitors move around the SVG. Drag temporarily replaces scroll when required by other settings.', 'bt-svg-viewer'); ?>
                                </p>
                            </div>
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-zoom-mode"><?php esc_html_e('Zoom Interaction', 'bt-svg-viewer'); ?></label>
                                <select id="bt-svg-viewer-zoom-mode" name="svg_viewer_zoom_mode">
                                    <?php
                                    $zoom_options = array(
                                        'super_scroll' => __('Cmd/Ctrl-scroll (default)', 'bt-svg-viewer'),
                                        'scroll' => __('Scroll wheel (no modifier)', 'bt-svg-viewer'),
                                        'click' => __('Modifier click', 'bt-svg-viewer'),
                                    );
                                    foreach ($zoom_options as $zoom_value => $zoom_label):
                                        ?>
                                        <option value="<?php echo esc_attr($zoom_value); ?>" <?php selected($values['zoom_mode'], $zoom_value); ?>>
                                            <?php echo esc_html($zoom_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Scroll wheel zoom overrides pan-on-scroll. Cmd/Ctrl-click zooms in and Option/Alt-click zooms out when using modifier click.', 'bt-svg-viewer'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="bt-svg-viewer-field-group">
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-button-fill"><?php esc_html_e('Button Fill Color', 'bt-svg-viewer'); ?></label>
                                <input type="text" id="bt-svg-viewer-button-fill" name="svg_viewer_button_fill"
                                    class="bt-svg-viewer-color-field" value="<?php echo esc_attr($values['button_fill']); ?>"
                                    data-default-color="#0073aa" />
                                <p class="description">
                                    <?php esc_html_e('Choose the primary color for the control buttons. Leave blank to use the default.', 'bt-svg-viewer'); ?>
                                </p>
                            </div>
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-button-border"><?php esc_html_e('Button Border Color', 'bt-svg-viewer'); ?></label>
                                <input type="text" id="bt-svg-viewer-button-border" name="svg_viewer_button_border"
                                    class="bt-svg-viewer-color-field" value="<?php echo esc_attr($values['button_border']); ?>"
                                    data-default-color="#0073aa" />
                                <p class="description">
                                    <?php esc_html_e('Set the border color for the control buttons. Leave blank to match the fill color.', 'bt-svg-viewer'); ?>
                                </p>
                            </div>
                            <div class="bt-svg-viewer-field">
                                <label
                                    for="bt-svg-viewer-button-foreground"><?php esc_html_e('Button Foreground Color', 'bt-svg-viewer'); ?></label>
                                <input type="text" id="bt-svg-viewer-button-foreground" name="svg_viewer_button_foreground"
                                    class="bt-svg-viewer-color-field"
                                    value="<?php echo esc_attr($values['button_foreground']); ?>"
                                    data-default-color="#ffffff" />
                                <p class="description">
                                    <?php esc_html_e('Set the icon and text color for the control buttons. Leave blank to use the default.', 'bt-svg-viewer'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="bt-svg-viewer-field">
                            <label for="bt-svg-viewer-title"><?php esc_html_e('Title (optional)', 'bt-svg-viewer'); ?></label>
                            <input type="text" id="bt-svg-viewer-title" name="svg_viewer_title"
                                value="<?php echo esc_attr($values['title']); ?>" />
                        </div>

                        <div class="bt-svg-viewer-field">
                            <label
                                for="bt-svg-viewer-caption"><?php esc_html_e('Caption (optional)', 'bt-svg-viewer'); ?></label>
                            <textarea id="bt-svg-viewer-caption" name="svg_viewer_caption" rows="3"
                                class="widefat"><?php echo esc_textarea($values['caption']); ?></textarea>
                            <p class="description"><?php esc_html_e('Supports basic HTML formatting.', 'bt-svg-viewer'); ?></p>
                        </div>

                        <div class="bt-svg-viewer-admin-preview">
                            <div class="bt-svg-viewer-admin-preview-toolbar">
                                <button type="button" class="button svg-admin-refresh-preview"
                                    data-viewer="<?php echo esc_attr($viewer_id); ?>"><?php esc_html_e('Load / Refresh Preview', 'bt-svg-viewer'); ?></button>
                                <button type="button" class="button button-primary svg-admin-capture-state"
                                    data-viewer="<?php echo esc_attr($viewer_id); ?>"><?php esc_html_e('Use Current View for Initial State', 'bt-svg-viewer'); ?></button>
                                <span class="svg-admin-status" aria-live="polite"></span>
                            </div>
                            <div class="<?php echo esc_attr($wrapper_class_attribute); ?>"
                                id="<?php echo esc_attr($viewer_id); ?>" <?php
                                   if ($wrapper_style_attribute_value !== '') {
                                       echo ' style="' . esc_attr($wrapper_style_attribute_value) . '"';
                                   }
                                   ?>>
                                <div class="bt-svg-viewer-title js-admin-title" hidden></div>
                                <div class="<?php echo esc_attr($main_class_attribute); ?>"
                                    data-viewer="<?php echo esc_attr($viewer_id); ?>">
                                    <?php if ($preview_controls_markup !== ''): ?>
                                        <?php echo wp_kses($preview_controls_markup, $this->get_controls_allowed_html()); ?>
                                    <?php endif; ?>
                                    <div class="svg-container" data-viewer="<?php echo esc_attr($viewer_id); ?>"
                                        style="height: <?php echo esc_attr($values['height']); ?>">
                                        <div class="svg-viewport" data-viewer="<?php echo esc_attr($viewer_id); ?>"></div>
                                    </div>
                                </div>
                                <div class="bt-svg-viewer-caption bt-svg-viewer-interaction-caption js-admin-interaction-caption"
                                    hidden></div>
                                <div class="bt-svg-viewer-caption js-admin-caption" hidden></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bt-svg-viewer-tab-panel" role="tabpanel" id="<?php echo esc_attr($help_panel_id); ?>"
                    aria-labelledby="<?php echo esc_attr($help_panel_id); ?>-tab" data-tab-panel="help" aria-hidden="true">
                    <div class="bt-svg-viewer-help-content">
                        <?php
                        $help_markup = $this->get_admin_help_markup();
                        if ($help_markup !== '') {
                            echo $help_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        } else {
                            printf('<p>%s</p>', esc_html__('Help content is not available. Run the "Render Help" build step to regenerate it.', 'bt-svg-viewer'));
                        }
                        ?>
                    </div>
                </div>
                <div class="bt-svg-viewer-tab-panel" role="tabpanel" id="<?php echo esc_attr($changes_panel_id); ?>"
                    aria-labelledby="<?php echo esc_attr($changes_panel_id); ?>-tab" data-tab-panel="changes"
                    aria-hidden="true">
                    <div class="bt-svg-viewer-help-content">
                        <?php
                        $changelog_markup = $this->get_admin_changelog_markup();
                        if ($changelog_markup !== '') {
                            echo $changelog_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        } else {
                            printf('<p>%s</p>', esc_html__('Changes content is not available. Run the "Render Help/Changelog" build step to regenerate it.', 'bt-svg-viewer'));
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

        $cache_ttl = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

        set_transient($transient_key, array(
            'mtime' => $modified_time,
            'html' => $sanitized,
        ), $cache_ttl);

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

        $cache_ttl = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

        set_transient($transient_key, array(
            'mtime' => $modified_time,
            'html' => $sanitized,
        ), $cache_ttl);

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
                'text' => __('Zoom In', 'bt-svg-viewer'),
                'title' => __('Zoom In (Ctrl +)', 'bt-svg-viewer'),
                'requires_show_coords' => false,
            ),
            'zoom_out' => array(
                'class' => 'zoom-out-btn',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM200 248C186.7 248 176 258.7 176 272C176 285.3 186.7 296 200 296L344 296C357.3 296 368 285.3 368 272C368 258.7 357.3 248 344 248L200 248z"/></svg>',
                'text' => __('Zoom Out', 'bt-svg-viewer'),
                'title' => __('Zoom Out (Ctrl -)', 'bt-svg-viewer'),
                'requires_show_coords' => false,
            ),
            'reset' => array(
                'class' => 'reset-zoom-btn',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM272 416C351.5 416 416 351.5 416 272C416 192.5 351.5 128 272 128C192.5 128 128 192.5 128 272C128 351.5 192.5 416 272 416z"/></svg>',
                'text' => __('Reset Zoom', 'bt-svg-viewer'),
                'title' => __('Reset Zoom', 'bt-svg-viewer'),
                'requires_show_coords' => false,
            ),
            'center' => array(
                'class' => 'center-view-btn',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M320 48C337.7 48 352 62.3 352 80L352 98.3C450.1 112.3 527.7 189.9 541.7 288L560 288C577.7 288 592 302.3 592 320C592 337.7 577.7 352 560 352L541.7 352C527.7 450.1 450.1 527.7 352 541.7L352 560C352 577.7 337.7 592 320 592C302.3 592 288 577.7 288 560L288 541.7C189.9 527.7 112.3 450.1 98.3 352L80 352C62.3 352 48 337.7 48 320C48 302.3 62.3 288 80 288L98.3 288C112.3 189.9 189.9 112.3 288 98.3L288 80C288 62.3 302.3 48 320 48zM163.2 352C175.9 414.7 225.3 464.1 288 476.8L288 464C288 446.3 302.3 432 320 432C337.7 432 352 446.3 352 464L352 476.8C414.7 464.1 464.1 414.7 476.8 352L464 352C446.3 352 432 337.7 432 320C432 302.3 446.3 288 464 288L476.8 288C464.1 225.3 414.7 175.9 352 163.2L352 176C352 193.7 337.7 208 320 208C302.3 208 288 193.7 288 176L288 163.2C225.3 175.9 175.9 225.3 163.2 288L176 288C193.7 288 208 302.3 208 320C208 337.7 193.7 352 176 352L163.2 352zM320 272C346.5 272 368 293.5 368 320C368 346.5 346.5 368 320 368C293.5 368 272 346.5 272 320C272 293.5 293.5 272 320 272z"/></svg>',
                'text' => __('Center View', 'bt-svg-viewer'),
                'title' => __('Center View', 'bt-svg-viewer'),
                'requires_show_coords' => false,
            ),
            'coords' => array(
                'class' => 'coord-copy-btn',
                'icon' => '📍',
                'text' => __('Copy Center', 'bt-svg-viewer'),
                'title' => __('Copy current center coordinates', 'bt-svg-viewer'),
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
     * Allowed HTML tags and attributes for rendered controls markup.
     *
     * @return array<string, array<string, bool>>
     */
    private function get_controls_allowed_html()
    {
        static $allowed = null;

        if ($allowed !== null) {
            return $allowed;
        }

        $allowed = array(
            'div' => array(
                'class' => true,
                'data-viewer' => true,
                'style' => true,
            ),
            'span' => array(
                'class' => true,
                'data-viewer' => true,
                'aria-hidden' => true,
                'aria-live' => true,
            ),
            'input' => array(
                'type' => true,
                'class' => true,
                'data-viewer' => true,
                'min' => true,
                'max' => true,
                'step' => true,
                'value' => true,
                'aria-label' => true,
                'aria-valuemin' => true,
                'aria-valuemax' => true,
                'aria-valuenow' => true,
            ),
            'button' => array(
                'type' => true,
                'class' => true,
                'data-viewer' => true,
                'title' => true,
                'aria-label' => true,
            ),
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

        return $allowed;
    }

    /**
     * Add a Settings link to the plugin actions list.
     *
     * @param string[] $links Existing action links.
     * @return string[]
     */
    public function add_plugin_settings_link($links)
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('edit.php?post_type=svg_viewer_preset')),
            esc_html__('Settings', 'bt-svg-viewer')
        );

        array_unshift($links, $settings_link);

        return $links;
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

        $tokenized_setting = strtolower(str_replace(':', ',', $buttons_setting));
        $tokens = array_filter(array_map('trim', explode(',', $tokenized_setting)), static function ($token) {
            return $token !== '';
        });

        $has_slider = in_array('slider', $tokens, true);
        $slider_explicit_zoom_in = in_array('zoom_in', $tokens, true);
        $slider_explicit_zoom_out = in_array('zoom_out', $tokens, true);

        $mode_options = array('icon', 'text', 'both');
        $style_options = array('compact', 'labels_on_hover', 'labels-on-hover');
        $hidden_options = array('hidden', 'none');
        $alignment_options = array('alignleft', 'aligncenter', 'alignright');
        $available_buttons = array('zoom_in', 'zoom_out', 'reset', 'center', 'coords');

        $default_buttons = array('zoom_in', 'zoom_out', 'reset', 'center');
        if ($show_coords) {
            $default_buttons[] = 'coords';
        }
        $default_buttons_without_zoom = array_values(array_filter($default_buttons, static function ($button) {
            return $button !== 'zoom_in' && $button !== 'zoom_out';
        }));

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
        } elseif ($normalized_setting === 'slider') {
            $has_slider = true;
            $buttons = $default_buttons_without_zoom;
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
            $parts = array_map('trim', explode(',', str_replace(':', ',', $buttons_setting)));

            if (!empty($parts) && strtolower($parts[0]) === 'custom') {
                array_shift($parts);
            }

            $custom_mode = null;
            $custom_styles = array();

            if (!empty($parts)) {
                $first = strtolower($parts[0]);

                if ($first === 'slider') {
                    $has_slider = true;
                    array_shift($parts);
                } elseif (in_array($first, $hidden_options, true)) {
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
                        if ($maybe === 'slider') {
                            $has_slider = true;
                            array_shift($parts);
                        } elseif ($custom_mode === null && in_array($maybe, $mode_options, true)) {
                            $custom_mode = $maybe;
                            array_shift($parts);
                        } elseif (in_array($maybe, $alignment_options, true)) {
                            $alignment = $maybe;
                            array_shift($parts);
                        }
                    }

                    if (!empty($parts)) {
                        $maybe = strtolower($parts[0]);
                        if ($maybe === 'slider') {
                            $has_slider = true;
                            array_shift($parts);
                        } elseif (in_array(str_replace('-', '_', $maybe), $style_options, true) || in_array($maybe, $style_options, true)) {
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
                    if ($key === 'slider') {
                        $has_slider = true;
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
                } elseif ($has_slider) {
                    $buttons = $default_buttons_without_zoom;
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

        if ($has_slider && !$slider_explicit_zoom_in) {
            $buttons = array_values(array_filter($buttons, static function ($button) {
                return $button !== 'zoom_in';
            }));
        }

        if ($has_slider && !$slider_explicit_zoom_out) {
            $buttons = array_values(array_filter($buttons, static function ($button) {
                return $button !== 'zoom_out';
            }));
        }

        return array(
            'position' => $position,
            'mode' => $mode,
            'styles' => $styles,
            'alignment' => $alignment,
            'buttons' => $buttons,
            'has_slider' => $has_slider,
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
     * Build an inline style attribute string from declarations.
     *
     * @param array $declarations
     * @return string
     */
    private function build_style_attribute(array $declarations)
    {
        $sanitized = array();

        foreach ($declarations as $declaration) {
            $declaration = trim((string) $declaration);
            if ($declaration === '') {
                continue;
            }
            // Remove trailing semicolons to avoid duplication.
            $sanitized[] = rtrim($declaration, ';');
        }

        if (empty($sanitized)) {
            return '';
        }

        return implode('; ', array_unique($sanitized));
    }

    /**
     * Sanitize a color value to a valid hex string.
     *
     * @param string $color
     * @return string
     */
    private function sanitize_color_value($color)
    {
        if (!function_exists('sanitize_hex_color')) {
            return '';
        }

        $color = is_string($color) ? trim($color) : '';

        if ($color === '') {
            return '';
        }

        $sanitized = sanitize_hex_color($color);

        if (!$sanitized) {
            return '';
        }

        return strtolower($sanitized);
    }

    /**
     * Adjust the brightness of a hex color by a percentage.
     *
     * @param string $hex_color
     * @param float  $percentage Positive to lighten, negative to darken.
     * @return string
     */
    private function adjust_color_brightness($hex_color, $percentage)
    {
        $hex = ltrim($hex_color, '#');

        if ($hex === '') {
            return '';
        }

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        } elseif (strlen($hex) !== 6) {
            return '';
        }

        $percentage = max(-100, min(100, (float) $percentage));

        $components = array(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );

        foreach ($components as $index => $component) {
            if ($percentage >= 0) {
                $component += (255 - $component) * ($percentage / 100);
            } else {
                $component += $component * ($percentage / 100);
            }
            $components[$index] = max(0, min(255, (int) round($component)));
        }

        return sprintf('#%02x%02x%02x', $components[0], $components[1], $components[2]);
    }

    /**
     * Generate CSS custom property declarations for button colors.
     *
     * @param string $fill
     * @param string $border
     * @param string $foreground
     * @return array
     */
    private function get_button_color_style_declarations($fill, $border, $foreground = '')
    {
        $declarations = array();

        $fill_color = $this->sanitize_color_value($fill);
        $border_color = $this->sanitize_color_value($border);
        $foreground_color = $this->sanitize_color_value($foreground);

        if ($fill_color !== '') {
            $declarations[] = '--bt-svg-viewer-button-fill: ' . $fill_color;
            $hover_color = $this->adjust_color_brightness($fill_color, -12);
            if ($hover_color !== '') {
                $declarations[] = '--bt-svg-viewer-button-hover: ' . $hover_color;
            }
        }

        if ($border_color === '' && $fill_color !== '') {
            $border_color = $fill_color;
        }

        if ($border_color !== '') {
            $declarations[] = '--bt-svg-viewer-button-border: ' . $border_color;
        }

        if ($foreground_color !== '') {
            $declarations[] = '--bt-svg-viewer-button-text: ' . $foreground_color;
        }

        return $declarations;
    }

    /**
     * Render the controls markup based on configuration.
     *
     * @param string $viewer_id
     * @param array  $controls_config
     * @param int    $initial_zoom_percent
     * @return string
     */
    private function render_controls_markup(
        $viewer_id,
        array $controls_config,
        $initial_zoom_percent,
        $min_zoom_percent,
        $max_zoom_percent,
        $zoom_step_percent
    ) {
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
        $has_slider = !empty($controls_config['has_slider']);
        $min_zoom_percent = (int) $min_zoom_percent;
        $max_zoom_percent = (int) $max_zoom_percent;
        $zoom_step_percent = (int) max(1, $zoom_step_percent);

        ob_start();
        ?>
        <div class="<?php echo esc_attr($class_attribute); ?>" data-viewer="<?php echo esc_attr($viewer_id); ?>">
            <?php if ($has_slider): ?>
                <div class="zoom-slider-wrapper">
                    <input type="range" class="zoom-slider" data-viewer="<?php echo esc_attr($viewer_id); ?>"
                        min="<?php echo esc_attr($min_zoom_percent); ?>" max="<?php echo esc_attr($max_zoom_percent); ?>"
                        step="<?php echo esc_attr($zoom_step_percent); ?>" value="<?php echo esc_attr($initial_zoom_percent); ?>"
                        aria-label="<?php esc_attr_e('Zoom level', 'bt-svg-viewer'); ?>"
                        aria-valuemin="<?php echo esc_attr($min_zoom_percent); ?>"
                        aria-valuemax="<?php echo esc_attr($max_zoom_percent); ?>"
                        aria-valuenow="<?php echo esc_attr($initial_zoom_percent); ?>" />
                </div>
            <?php endif; ?>
            <?php foreach ($buttons as $button_key):
                $definition = $button_definitions[$button_key];
                ?>
                <button type="button" class="bt-svg-viewer-btn <?php echo esc_attr($definition['class']); ?>"
                    data-viewer="<?php echo esc_attr($viewer_id); ?>" title="<?php echo esc_attr($definition['title']); ?>"
                    aria-label="<?php echo esc_attr($definition['text']); ?>">
                    <span class="btn-icon"
                        aria-hidden="true"><?php echo $this->sanitize_svg_icon($definition['icon']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses in sanitize_svg_icon() ?></span>
                    <span class="btn-text"><?php echo esc_html($definition['text']); ?></span>
                </button>
            <?php endforeach; ?>
            <?php if ($has_coords_button): ?>
                <span class="coord-output" data-viewer="<?php echo esc_attr($viewer_id); ?>" aria-live="polite"></span>
            <?php endif; ?>
            <div class="divider"></div>
            <span class="zoom-display">
                <span class="zoom-percentage"
                    data-viewer="<?php echo esc_attr($viewer_id); ?>"><?php echo esc_html($initial_zoom_percent); ?></span>%
            </span>
        </div>
        <?php
        return trim(ob_get_clean());
    }

    /**
     * Normalize pan mode value.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private function normalize_pan_mode($value)
    {
        $pan_mode = is_string($value) ? strtolower(trim($value)) : '';
        return $pan_mode === 'drag' ? 'drag' : 'scroll';
    }

    /**
     * Normalize zoom mode value.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private function normalize_zoom_mode($value)
    {
        $zoom_mode = is_string($value) ? strtolower(trim($value)) : '';
        $zoom_mode = str_replace(array(' ', '-'), '_', $zoom_mode);
        if ($zoom_mode === 'click') {
            return 'click';
        }
        if ($zoom_mode === 'scroll') {
            return 'scroll';
        }
        return 'super_scroll';
    }

    /**
     * Resolve pan/zoom interaction configuration including helper messages.
     *
     * @param mixed $pan_value
     * @param mixed $zoom_value
     * @return array{pan_mode:string,zoom_mode:string,messages:array}
     */
    private function resolve_interaction_config($pan_value, $zoom_value)
    {
        $pan_mode = $this->normalize_pan_mode($pan_value);
        $zoom_mode = $this->normalize_zoom_mode($zoom_value);
        $messages = array();

        if ($zoom_mode === 'scroll' && $pan_mode === 'scroll') {
            $pan_mode = 'drag';
        }

        if ($zoom_mode === 'click') {
            $messages[] = __('Cmd/Ctrl-click to zoom in, Option/Alt-click to zoom out.', 'bt-svg-viewer');
        } elseif ($zoom_mode === 'scroll') {
            $messages[] = __('Scroll up to zoom in, scroll down to zoom out.', 'bt-svg-viewer');
        }

        if ($pan_mode === 'drag') {
            if ($zoom_mode === 'scroll') {
                $messages[] = __('Drag to pan around the image while scrolling zooms.', 'bt-svg-viewer');
            } else {
                $messages[] = __('Drag to pan around the image.', 'bt-svg-viewer');
            }
        }

        $messages = array_values(array_unique(array_filter($messages)));

        return array(
            'pan_mode' => $pan_mode,
            'zoom_mode' => $zoom_mode,
            'messages' => $messages,
        );
    }

    /**
     * Save preset meta data
     */
    public function save_preset_meta($post_id, $post)
    {
        $preset_nonce = isset($_POST['svg_viewer_preset_nonce']) ? sanitize_text_field(wp_unslash($_POST['svg_viewer_preset_nonce'])) : '';

        if ($preset_nonce === '' || !wp_verify_nonce($preset_nonce, 'svg_viewer_preset_meta')) {
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

        $raw_src = isset($_POST['svg_viewer_src']) ? sanitize_text_field(wp_unslash($_POST['svg_viewer_src'])) : '';
        $attachment_id = isset($_POST['svg_viewer_attachment_id']) ? absint(wp_unslash($_POST['svg_viewer_attachment_id'])) : '';
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

        $pan_mode_input = isset($_POST['svg_viewer_pan_mode']) ? sanitize_text_field(wp_unslash($_POST['svg_viewer_pan_mode'])) : '';
        if ($pan_mode_input === '') {
            delete_post_meta($post_id, '_svg_pan_mode');
        } else {
            $pan_mode_value = $this->normalize_pan_mode($pan_mode_input);
            update_post_meta($post_id, '_svg_pan_mode', $pan_mode_value);
        }

        $zoom_mode_input = isset($_POST['svg_viewer_zoom_mode']) ? sanitize_text_field(wp_unslash($_POST['svg_viewer_zoom_mode'])) : '';
        $zoom_mode_value = $this->normalize_zoom_mode($zoom_mode_input);
        if ($zoom_mode_value === 'super_scroll') {
            delete_post_meta($post_id, '_svg_zoom_mode');
        } else {
            update_post_meta($post_id, '_svg_zoom_mode', $zoom_mode_value);
        }

        foreach ($numeric_fields as $meta_key => $post_key) {
            $raw_value = filter_input(INPUT_POST, $post_key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
            if (is_string($raw_value)) {
                $raw_value = sanitize_text_field(wp_unslash($raw_value));
            } elseif ($raw_value === null) {
                $raw_value = '';
            }

            if ($raw_value !== '') {
                $value = floatval($raw_value);
                update_post_meta($post_id, $meta_key, $value);
            } else {
                delete_post_meta($post_id, $meta_key);
            }
        }

        foreach ($text_fields as $meta_key => $post_key) {
            $raw_value = filter_input(INPUT_POST, $post_key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
            if (is_string($raw_value)) {
                $raw_value = wp_unslash($raw_value);
            } elseif ($raw_value === null) {
                $raw_value = '';
            }

            if ($raw_value !== '') {
                $value = wp_kses_post($raw_value);
                update_post_meta($post_id, $meta_key, $value);
            } else {
                delete_post_meta($post_id, $meta_key);
            }
        }

        $color_fields = array(
            '_svg_button_fill' => 'svg_viewer_button_fill',
            '_svg_button_border' => 'svg_viewer_button_border',
            '_svg_button_foreground' => 'svg_viewer_button_foreground',
        );

        foreach ($color_fields as $meta_key => $post_key) {
            $raw_color = filter_input(INPUT_POST, $post_key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
            if (is_string($raw_color)) {
                $raw_color = wp_unslash($raw_color);
            } elseif ($raw_color === null) {
                $raw_color = '';
            }

            if ($raw_color !== '') {
                $sanitized_color = $this->sanitize_color_value($raw_color);

                if ($sanitized_color !== '') {
                    update_post_meta($post_id, $meta_key, $sanitized_color);
                } else {
                    delete_post_meta($post_id, $meta_key);
                }
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
            'button_fill' => get_post_meta($preset_id, '_svg_button_fill', true),
            'button_border' => get_post_meta($preset_id, '_svg_button_border', true),
            'button_foreground' => get_post_meta($preset_id, '_svg_button_foreground', true),
            'pan_mode' => get_post_meta($preset_id, '_svg_pan_mode', true),
            'zoom_mode' => get_post_meta($preset_id, '_svg_zoom_mode', true),
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

        $settings['button_fill'] = $this->sanitize_color_value($settings['button_fill']);
        $settings['button_border'] = $this->sanitize_color_value($settings['button_border']);
        $settings['button_foreground'] = $this->sanitize_color_value($settings['button_foreground']);
        $settings['pan_mode'] = $this->normalize_pan_mode($settings['pan_mode']);
        $settings['zoom_mode'] = $this->normalize_zoom_mode($settings['zoom_mode']);
        $settings['pan'] = $settings['pan_mode'];
        $settings['zoom_behavior'] = $settings['zoom_mode'];
        $settings['zoom_interaction'] = $settings['zoom_mode'];

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
        $columns['svg_viewer_shortcode'] = __('Shortcode', 'bt-svg-viewer');
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
                <?php esc_html_e('Copy', 'bt-svg-viewer'); ?>
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
BTSVVI_Viewer::get_instance();
