<?php
/**
 * Tests for the BT SVG Viewer plugin core behavior.
 *
 * @package Wp_Svg_Viewer
 */

class BT_SVG_Viewer_Tests extends WP_UnitTestCase
{

    /**
     * Plugin instance used across tests.
     *
     * @var BT_SVG_Viewer
     */
    protected static $plugin;

    /**
     * Boot the plugin instance once the WordPress test environment is ready.
     *
     * @param WP_UnitTest_Factory $factory Factory object provided by WP_UnitTestCase.
     */
    public static function wpSetUpBeforeClass($factory)
    {
        self::$plugin = BT_SVG_Viewer::get_instance();
        self::$plugin->register_preset_post_type();

        if (!function_exists('sanitize_hex_color')) {
            require_once ABSPATH . WPINC . '/formatting.php';

            if (!function_exists('sanitize_hex_color')) {
                function sanitize_hex_color($color)
                {
                    $color = is_string($color) ? trim($color) : '';

                    if ($color === '') {
                        return false;
                    }

                    if ($color[0] !== '#') {
                        $color = '#' . $color;
                    }

                    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
                        return strtolower($color);
                    }

                    return false;
                }
            }
        }
    }

    /**
     * Inline shortcode attributes should override preset values.
     */
    public function test_shortcode_inline_overrides_preset_values()
    {
        $uploads_url = 'https://example.com/uploads';

        $uploads_filter = static function ($dirs) use ($uploads_url) {
            $dirs['baseurl'] = $uploads_url;
            return $dirs;
        };
        add_filter('upload_dir', $uploads_filter);

        $preset_id = self::factory()->post->create(
            array(
                'post_type' => 'btsvviewer_preset',
                'post_status' => 'publish',
                'post_title' => 'Override Test Preset',
            )
        );

        update_post_meta($preset_id, '_btsvviewer_src', 'preset-image.svg');
        update_post_meta($preset_id, '_btsvviewer_button_fill', '#123456');

        $output = self::$plugin->render_shortcode(
            array(
                'id' => $preset_id,
                'button_fill' => '#abcdef',
                'height' => '720px',
            )
        );

        $this->assertStringContainsString('--bt-svg-viewer-button-fill: #abcdef', $output);
        $this->assertStringContainsString('style="height: 720px"', $output);

        remove_filter('upload_dir', $uploads_filter);
    }

    /**
     * Missing preset IDs should return an error message.
     */
    public function test_render_shortcode_returns_error_when_preset_missing()
    {
        $output = self::$plugin->render_shortcode(array('id' => 999999));

        $this->assertStringContainsString(
            'Error: SVG preset not found',
            wp_strip_all_tags($output)
        );
    }

    /**
     * Helper should sanitize color declarations and infer defaults.
     */
    public function test_get_button_color_style_declarations_sanitizes_values()
    {
        $method = new ReflectionMethod(BT_SVG_Viewer::class, 'get_button_color_style_declarations');
        $method->setAccessible(true);

        $declarations = $method->invoke(self::$plugin, '#AaCcDd', '', ' #F0F0F0 ');

        $this->assertContains('--bt-svg-viewer-button-fill: #aaccdd', $declarations);
        $this->assertContains('--bt-svg-viewer-button-border: #aaccdd', $declarations);
        $this->assertContains('--bt-svg-viewer-button-text: #f0f0f0', $declarations);
    }

    /**
     * adjust_color_brightness should clamp values inside 0-255 and handle extremes.
     */
    public function test_adjust_color_brightness_handles_extremes()
    {
        $method = new ReflectionMethod(BT_SVG_Viewer::class, 'adjust_color_brightness');
        $method->setAccessible(true);

        $this->assertSame('#ffffff', $method->invoke(self::$plugin, '#ffffff', 15.0));
        $this->assertSame('#000000', $method->invoke(self::$plugin, '#000000', -20.0));
        $this->assertSame('#0d0d0d', $method->invoke(self::$plugin, '#000000', 5.0));
    }

    /**
     * build_style_attribute should trim duplicates and semicolons.
     */
    public function test_build_style_attribute_trims_duplicates()
    {
        $method = new ReflectionMethod(BT_SVG_Viewer::class, 'build_style_attribute');
        $method->setAccessible(true);

        $result = $method->invoke(
            self::$plugin,
            array(
                ' color: red; ',
                'color: red',
                'background: #fff;;',
                '',
            )
        );

        $this->assertSame('color: red; background: #fff', $result);
    }

    /**
     * Ensure the plugin adds SVG support to the mime type list.
     */
    public function test_btsvviewer_mime_type_is_added()
    {
        $existing = array(
            'jpg' => 'image/jpeg',
        );

        $result = self::$plugin->svg_use_mimetypes($existing);

        $this->assertArrayHasKey('svg', $result);
        $this->assertSame('image/svg+xml', $result['svg']);
    }

    /**
     * Rendering without a source should return a descriptive error message.
     */
    public function test_render_shortcode_requires_source()
    {
        $output = self::$plugin->render_shortcode(array());

        $this->assertStringContainsString(
            'Error: SVG source not specified.',
            wp_strip_all_tags($output)
        );
    }

    /**
     * Rendering with button color aliases should normalize and sanitize styles.
     */
    public function test_render_shortcode_generates_button_styles_from_aliases()
    {
        $output = self::$plugin->render_shortcode(
            array(
                'src' => 'https://example.com/test.svg',
                'button_bg' => '#336699',
                'button_border' => 'not-a-color',
                'button_foreground' => '#ffffff',
            )
        );

        $this->assertStringContainsString('--bt-svg-viewer-button-fill: #336699', $output);
        $this->assertStringContainsString('--bt-svg-viewer-button-border: #336699', $output);
        $this->assertStringContainsString('--bt-svg-viewer-button-text: #ffffff', $output);
    }

    /**
     * Interaction configuration should resolve conflicting pan/zoom modes and expose helper messages.
     */
    public function test_resolve_interaction_config_adjusts_pan_mode_and_messages()
    {
        $method = new ReflectionMethod(BT_SVG_Viewer::class, 'resolve_interaction_config');
        $method->setAccessible(true);

        $result = $method->invoke(self::$plugin, 'scroll', 'scroll');

        $this->assertSame('drag', $result['pan_mode']);
        $this->assertSame('scroll', $result['zoom_mode']);
        $this->assertContains(
            'Scroll up to zoom in, scroll down to zoom out.',
            $result['messages']
        );
        $this->assertContains(
            'Drag to pan around the image while scrolling zooms.',
            $result['messages']
        );
    }

    /**
     * Shortcode rendering should pick up preset data when provided via ID.
     */
    public function test_render_shortcode_uses_preset_values()
    {
        $uploads_url = 'https://example.com/uploads';

        $uploads_filter = static function ($dirs) use ($uploads_url) {
            $dirs['baseurl'] = $uploads_url;
            return $dirs;
        };
        add_filter('upload_dir', $uploads_filter);

        $preset_id = self::factory()->post->create(
            array(
                'post_type' => 'btsvviewer_preset',
                'post_status' => 'publish',
                'post_title' => 'Preset',
            )
        );

        update_post_meta($preset_id, '_btsvviewer_src', 'preset-path.svg');
        update_post_meta($preset_id, '_btsvviewer_initial_zoom', 200);
        update_post_meta($preset_id, '_btsvviewer_pan_mode', 'drag');
        update_post_meta($preset_id, '_btsvviewer_zoom_mode', 'click');

        $output = self::$plugin->render_shortcode(
            array(
                'id' => $preset_id,
            )
        );

        $this->assertStringContainsString('bt-svg-viewer-wrapper', $output);
        $this->assertStringContainsString('bt-svg-viewer-main', $output);
        $this->assertStringContainsString($uploads_url . '/preset-path.svg', $output);
        $this->assertStringContainsString('controls-mode', $output);

        remove_filter('upload_dir', $uploads_filter);
    }

    /**
     * Rendering through do_shortcode should enqueue frontend assets.
     */
    public function test_shortcode_enqueue_assets()
    {
        if (wp_script_is('bt-svg-viewer-script')) {
            wp_dequeue_script('bt-svg-viewer-script');
        }
        if (wp_style_is('bt-svg-viewer-style')) {
            wp_dequeue_style('bt-svg-viewer-style');
        }

        $this->assertFalse(wp_style_is('bt-svg-viewer-style', 'enqueued'));
        $this->assertFalse(wp_script_is('bt-svg-viewer-script', 'enqueued'));

        do_action('wp_enqueue_scripts');

        $this->assertTrue(wp_style_is('bt-svg-viewer-style', 'enqueued'));
    }

}