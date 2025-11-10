<?php
/**
 * Tests for the BT SVG Viewer plugin core behavior.
 *
 * @package Wp_Svg_Viewer
 */

class SVG_Viewer_Tests extends WP_UnitTestCase
{

	/**
	 * Plugin instance used across tests.
	 *
	 * @var SVG_Viewer
	 */
	protected static $plugin;

	/**
	 * Boot the plugin instance once the WordPress test environment is ready.
	 *
	 * @param WP_UnitTest_Factory $factory Factory object provided by WP_UnitTestCase.
	 */
	public static function wpSetUpBeforeClass($factory)
	{
		self::$plugin = SVG_Viewer::get_instance();
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
	 * Ensure the plugin adds SVG support to the mime type list.
	 */
	public function test_svg_mime_type_is_added()
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
		$method = new ReflectionMethod(SVG_Viewer::class, 'resolve_interaction_config');
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
				'post_type' => 'svg_viewer_preset',
				'post_status' => 'publish',
				'post_title' => 'Preset',
			)
		);

		update_post_meta($preset_id, '_svg_src', 'preset-path.svg');
		update_post_meta($preset_id, '_svg_initial_zoom', 200);
		update_post_meta($preset_id, '_svg_pan_mode', 'drag');
		update_post_meta($preset_id, '_svg_zoom_mode', 'click');

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
		$this->assertTrue(wp_script_is('bt-svg-viewer-script', 'enqueued'));
	}

	/**
	 * get_svg_url should resolve absolute, root-relative, and uploads-relative paths.
	 */
	public function test_get_svg_url_variants()
	{
		$method = new ReflectionMethod(SVG_Viewer::class, 'get_svg_url');
		$method->setAccessible(true);

		update_option('siteurl', 'https://example.org');
		update_option('home', 'https://example.org');

		$upload_filter = static function ($dirs) {
			$dirs['baseurl'] = 'https://example.org/wp-content/uploads';
			return $dirs;
		};
		add_filter('upload_dir', $upload_filter);

		$this->assertSame(
			'https://external.test/image.svg',
			$method->invoke(self::$plugin, 'https://external.test/image.svg')
		);

		$this->assertSame(
			'https://example.org/path/to/file.svg',
			$method->invoke(self::$plugin, '/path/to/file.svg')
		);

		$this->assertSame(
			'https://example.org/wp-content/uploads/local/file.svg',
			$method->invoke(self::$plugin, 'local/file.svg')
		);

		remove_filter('upload_dir', $upload_filter);
	}

	/**
	 * parse_controls_config should normalize buttons, styles, and slider state.
	 */
	public function test_parse_controls_config_outputs_expected_structure()
	{
		$method = new ReflectionMethod(SVG_Viewer::class, 'parse_controls_config');
		$method->setAccessible(true);

		$config = $method->invoke(
			self::$plugin,
			'left',
			'both',
			true
		);

		$this->assertSame('left', $config['position']);
		$this->assertContains('zoom_in', $config['buttons'], 'Default controls should include zoom_in.');
		$this->assertContains('zoom_out', $config['buttons'], 'Default controls should include zoom_out.');
		$this->assertFalse($config['has_slider'], 'Default controls should not include the slider unless specified.');
		$this->assertSame('both', $config['mode']);
		$this->assertContains('coords', $config['buttons']);

		$slider_config = $method->invoke(
			self::$plugin,
			'right',
			'slider, custom, coords',
			false
		);

		$this->assertTrue($slider_config['has_slider'], 'Explicit slider should mark has_slider true.');
		$this->assertNotContains('zoom_in', $slider_config['buttons'], 'Slider-only mode removes discrete zoom buttons.');
		$this->assertNotContains('zoom_out', $slider_config['buttons'], 'Slider-only mode removes discrete zoom buttons.');
		$this->assertContains('center', $slider_config['buttons'], 'Slider mode should retain center button.');
		$this->assertNotContains('coords', $slider_config['buttons'], 'Coords button should be absent when show_coords is false.');
	}

	/**
	 * Saving preset meta should sanitize input and persist expected values.
	 */
	public function test_save_preset_meta_sanitizes_values()
	{
		$user_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user($user_id);

		$post_id = self::factory()->post->create(
			array(
				'post_type' => 'svg_viewer_preset',
				'post_status' => 'draft',
				'post_title' => 'Preset Meta Test',
			)
		);

		update_post_meta($post_id, '_svg_pan_mode', 'scroll');
		update_post_meta($post_id, '_svg_zoom_mode', 'super_scroll');

		$original_post = $_POST;

		$_POST = array(
			'svg_viewer_preset_nonce' => wp_create_nonce('svg_viewer_preset_meta'),
			'svg_viewer_height' => ' 600px ',
			'svg_viewer_min_zoom' => '25',
			'svg_viewer_max_zoom' => '400',
			'svg_viewer_initial_zoom' => '150',
			'svg_viewer_zoom_step' => '5',
			'svg_viewer_controls_buttons' => 'zoom, pan ',
			'svg_viewer_pan_mode' => ' Drag ',
			'svg_viewer_zoom_mode' => ' Scroll ',
			'svg_viewer_button_fill' => '#336699',
			'svg_viewer_button_border' => '',
			'svg_viewer_button_foreground' => '#ffffff',
		);

		self::$plugin->save_preset_meta(
			$post_id,
			get_post($post_id)
		);

		$this->assertSame('600px', get_post_meta($post_id, '_svg_height', true));
		$this->assertSame('25', (string) get_post_meta($post_id, '_svg_min_zoom', true));
		$this->assertSame('400', (string) get_post_meta($post_id, '_svg_max_zoom', true));
		$this->assertSame('150', (string) get_post_meta($post_id, '_svg_initial_zoom', true));
		$this->assertSame('5', (string) get_post_meta($post_id, '_svg_zoom_step', true));
		$this->assertSame('drag', get_post_meta($post_id, '_svg_pan_mode', true));
		$this->assertSame('scroll', get_post_meta($post_id, '_svg_zoom_mode', true));
		$this->assertSame('#336699', get_post_meta($post_id, '_svg_button_fill', true));
		$this->assertSame('#336699', get_post_meta($post_id, '_svg_button_border', true));
		$this->assertSame('#ffffff', get_post_meta($post_id, '_svg_button_foreground', true));

		$_POST = $original_post;
	}
}
