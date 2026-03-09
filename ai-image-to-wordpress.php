<?php
/**
 * Plugin Name: AI Image to WordPress
 * Plugin URI: https://github.com/qipihen/ai-image-to-wordpress-plugin
 * Description: Generate images with OpenRouter from the WordPress media area, optimize locally, apply SEO filename and readable alt text, then upload directly to Media Library.
 * Version: 0.2.0
 * Author: qipihen
 * Author URI: https://github.com/qipihen
 * License: GPL-2.0+
 * Text Domain: ai-image-to-wordpress
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AIWP_Image_Generator_Plugin
{
    private const OPTION_KEY = 'aiiwp_settings';
    private const NONCE_ACTION = 'aiiwp_generate_image';
    private const MENU_SLUG = 'aiiwp-generator';
    private const VERSION = '0.2.0';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('add_meta_boxes', [$this, 'register_editor_metabox']);
        add_action('admin_bar_menu', [$this, 'register_admin_bar_link'], 90);
        add_action('wp_ajax_aiiwp_generate_image', [$this, 'handle_ajax_generate_image']);
        add_filter('media_row_actions', [$this, 'add_media_row_actions'], 10, 3);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);
    }

    public function add_plugin_action_links(array $links): array
    {
        $url = admin_url('upload.php?page=' . self::MENU_SLUG);
        array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
        return $links;
    }

    public function add_media_row_actions(array $actions, WP_Post $post, bool $detached): array
    {
        if (!current_user_can('upload_files') || !wp_attachment_is_image($post->ID)) {
            return $actions;
        }

        $url = add_query_arg(
            [
                'page' => self::MENU_SLUG,
                'source_attachment_id' => (int) $post->ID,
            ],
            admin_url('upload.php')
        );

        $actions['aiiwp_edit'] = '<a href="' . esc_url($url) . '">AI Edit</a>';
        return $actions;
    }

    public function register_admin_menu(): void
    {
        add_submenu_page(
            'upload.php',
            'AI Image Generator',
            'AI Generate',
            'upload_files',
            self::MENU_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function register_admin_bar_link(WP_Admin_Bar $wp_admin_bar): void
    {
        if (!is_admin() || !current_user_can('upload_files')) {
            return;
        }

        $wp_admin_bar->add_node([
            'id' => 'aiiwp-generator',
            'title' => 'AI Generate',
            'href' => admin_url('upload.php?page=' . self::MENU_SLUG),
            'meta' => [
                'class' => 'aiiwp-adminbar-link',
            ],
        ]);
    }

    public function register_editor_metabox(): void
    {
        if (!current_user_can('upload_files')) {
            return;
        }

        $post_types = get_post_types(['show_ui' => true], 'names');
        foreach ($post_types as $post_type) {
            if ($post_type === 'attachment') {
                continue;
            }
            add_meta_box(
                'aiiwp_editor_box',
                'AI Image Generator',
                [$this, 'render_editor_metabox'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function render_editor_metabox(WP_Post $post): void
    {
        $url = add_query_arg(
            [
                'page' => self::MENU_SLUG,
                'from_post_id' => (int) $post->ID,
            ],
            admin_url('upload.php')
        );
        ?>
        <p>Generate and upload images in Media Library, then insert them into this post/page.</p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">
                Open AI Generate
            </a>
        </p>
        <?php
    }

    public function enqueue_admin_assets(string $hook): void
    {
        $allowed_hooks = ['upload.php', 'media_page_' . self::MENU_SLUG];
        if (!in_array($hook, $allowed_hooks, true)) {
            return;
        }

        $is_generator_page = ('media_page_' . self::MENU_SLUG === $hook);
        if ($is_generator_page) {
            wp_enqueue_media();
        }

        wp_enqueue_style(
            'aiiwp-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'aiiwp-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            ['jquery'],
            self::VERSION,
            true
        );

        wp_localize_script('aiiwp-admin', 'AIWP_DATA', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'generatorUrl' => admin_url('upload.php?page=' . self::MENU_SLUG),
            'isUploadPage' => ('upload.php' === $hook),
            'isGeneratorPage' => $is_generator_page,
            'messages' => [
                'generating' => 'Generating and uploading image...',
                'success' => 'Image generated and uploaded successfully.',
                'error' => 'Failed to generate image. Please check settings and try again.',
                'chooseImage' => 'Select source image',
            ],
        ]);
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('upload_files')) {
            wp_die('You do not have permission to access this page.');
        }

        $notice = '';
        $notice_type = 'updated';

        if (isset($_POST['aiiwp_save_settings'])) {
            check_admin_referer('aiiwp_save_settings', 'aiiwp_settings_nonce');
            $raw = isset($_POST['aiiwp']) ? wp_unslash($_POST['aiiwp']) : [];
            $settings = $this->sanitize_settings(is_array($raw) ? $raw : []);
            update_option(self::OPTION_KEY, $settings);
            $notice = 'Settings saved.';
        }

        $settings = $this->get_settings();
        $profiles = $this->get_usage_profiles();
        $prefill_source_id = isset($_GET['source_attachment_id']) ? absint(wp_unslash($_GET['source_attachment_id'])) : 0;
        $prefill_source_url = $prefill_source_id > 0 ? (string) wp_get_attachment_url($prefill_source_id) : '';
        $prefill_source_thumb = $prefill_source_id > 0 ? (string) wp_get_attachment_image_url($prefill_source_id, 'medium') : '';
        ?>
        <div class="wrap aiiwp-wrap">
            <h1>AI Image Generator</h1>

            <?php if ($notice) : ?>
                <div class="notice <?php echo esc_attr($notice_type); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <div class="aiiwp-grid">
                <section class="aiiwp-card">
                    <h2>Generate and Upload</h2>
                    <p>One flow: generate image with OpenRouter, optimize locally, upload to Media Library, return URL.</p>

                    <form id="aiiwp-generate-form" autocomplete="off">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="aiiwp_prompt">Prompt</label></th>
                                <td><textarea id="aiiwp_prompt" name="prompt" class="large-text" rows="4" required></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="aiiwp_batch_count">Batch Count</label></th>
                                <td>
                                    <input id="aiiwp_batch_count" name="batch_count" type="number" min="1" max="6" value="1" />
                                    <p class="description">Generate multiple new images in one run (1-6). In batch mode, cache/dedupe reuse is auto-disabled for this run.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="aiiwp_source_attachment_id">Source Image (optional)</label></th>
                                <td>
                                    <input id="aiiwp_source_attachment_id" name="source_attachment_id" type="number" min="0" value="<?php echo esc_attr((string) $prefill_source_id); ?>" />
                                    <input id="aiiwp_source_image_url" name="source_image_url" type="url" class="regular-text" placeholder="https://example.com/reference.jpg" value="<?php echo esc_attr($prefill_source_url); ?>" />
                                    <button type="button" class="button" id="aiiwp-choose-source">Choose from Media</button>
                                    <button type="button" class="button-link-delete" id="aiiwp-clear-source">Clear</button>
                                    <p class="description">Leave empty for text-to-image. Supports Media Library image or external image URL for image-to-image editing.</p>
                                    <div id="aiiwp-source-preview" class="aiiwp-source-preview<?php echo $prefill_source_thumb ? '' : ' is-hidden'; ?>">
                                        <?php if ($prefill_source_thumb) : ?>
                                            <img src="<?php echo esc_url($prefill_source_thumb); ?>" alt="" />
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="aiiwp_alt">Alt Text (optional)</label></th>
                                <td><input id="aiiwp_alt" name="alt" type="text" class="regular-text" placeholder="Auto-generated if empty" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="aiiwp_usage">Usage Profile</label></th>
                                <td>
                                    <select id="aiiwp_usage" name="usage">
                                        <?php foreach ($profiles as $profile_name => $profile_data) : ?>
                                            <option value="<?php echo esc_attr($profile_name); ?>" <?php selected($settings['usage'], $profile_name); ?>>
                                                <?php echo esc_html($profile_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="aiiwp_aspect_ratio">Aspect Ratio (optional)</label></th>
                                <td><input id="aiiwp_aspect_ratio" name="aspect_ratio" type="text" class="regular-text" placeholder="16:9 / 4:3 / 1:1" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="aiiwp_model">Model Override (optional)</label></th>
                                <td>
                                    <input id="aiiwp_model" name="model" type="text" class="regular-text" value="<?php echo esc_attr($settings['model']); ?>" />
                                    <p class="description">Default: <code>google/gemini-3.1-flash-image-preview</code></p>
                                </td>
                            </tr>
                        </table>

                        <details>
                            <summary>Advanced Optimization</summary>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="aiiwp_target_kb">Target KB</label></th>
                                    <td><input id="aiiwp_target_kb" name="target_kb" type="number" min="80" max="5000" value="<?php echo esc_attr((string) $settings['target_kb']); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="aiiwp_max_width">Max Width</label></th>
                                    <td><input id="aiiwp_max_width" name="max_width" type="number" min="960" max="4096" value="<?php echo esc_attr((string) $settings['max_width']); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="aiiwp_min_width">Min Width Floor</label></th>
                                    <td><input id="aiiwp_min_width" name="min_width" type="number" min="900" max="3000" value="<?php echo esc_attr((string) $settings['min_width']); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="aiiwp_quality">JPEG Quality</label></th>
                                    <td><input id="aiiwp_quality" name="quality" type="number" min="70" max="100" value="<?php echo esc_attr((string) $settings['quality']); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="aiiwp_min_quality">Min JPEG Quality</label></th>
                                    <td><input id="aiiwp_min_quality" name="min_quality" type="number" min="60" max="100" value="<?php echo esc_attr((string) $settings['min_quality']); ?>" /></td>
                                </tr>
                            </table>
                        </details>

                        <p>
                            <button type="submit" class="button button-primary">Generate and Upload</button>
                        </p>
                    </form>

                    <div id="aiiwp-result" class="aiiwp-result" aria-live="polite"></div>
                </section>

                <section class="aiiwp-card">
                    <h2>OpenRouter Settings</h2>
                    <form method="post">
                        <?php wp_nonce_field('aiiwp_save_settings', 'aiiwp_settings_nonce'); ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="aiiwp_api_key">OpenRouter API Key</label></th>
                                <td>
                                    <input id="aiiwp_api_key" name="aiiwp[api_key]" type="password" class="regular-text" value="<?php echo esc_attr($settings['api_key']); ?>" autocomplete="new-password" />
                                    <p class="description">Required. Stored in WordPress options table.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="aiiwp_default_model">Default Model</label></th>
                                <td>
                                    <input id="aiiwp_default_model" name="aiiwp[model]" type="text" class="regular-text" value="<?php echo esc_attr($settings['model']); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="aiiwp_metadata_model">Metadata Model</label></th>
                                <td>
                                    <input id="aiiwp_metadata_model" name="aiiwp[metadata_model]" type="text" class="regular-text" value="<?php echo esc_attr($settings['metadata_model']); ?>" />
                                    <p class="description">Cheaper text model for natural filename/title/alt generation. Default: <code>google/gemini-2.5-flash-lite-preview-09-2025</code></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Cost & Deduplication</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="aiiwp[enable_ai_metadata]" value="1" <?php checked((int) $settings['enable_ai_metadata'], 1); ?> />
                                        Enable AI metadata rewriting (filename/title/alt)
                                    </label>
                                    <br />
                                    <label>
                                        <input type="checkbox" name="aiiwp[enable_cache]" value="1" <?php checked((int) $settings['enable_cache'], 1); ?> />
                                        Enable prompt cache (reuse existing media for same inputs)
                                    </label>
                                    <br />
                                    <label>
                                        <input type="checkbox" name="aiiwp[enable_dedupe]" value="1" <?php checked((int) $settings['enable_dedupe'], 1); ?> />
                                        Enable similar-image dedupe before upload
                                    </label>
                                    <p class="description">
                                        Similar-image threshold:
                                        <input id="aiiwp_dedupe_distance" name="aiiwp[dedupe_distance]" type="number" min="1" max="20" value="<?php echo esc_attr((string) $settings['dedupe_distance']); ?>" />
                                        (lower = stricter, default 5)
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="aiiwp_default_usage">Default Usage</label></th>
                                <td>
                                    <select id="aiiwp_default_usage" name="aiiwp[usage]">
                                        <?php foreach (array_keys($profiles) as $profile_name) : ?>
                                            <option value="<?php echo esc_attr($profile_name); ?>" <?php selected($settings['usage'], $profile_name); ?>>
                                                <?php echo esc_html($profile_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <p><button type="submit" name="aiiwp_save_settings" class="button">Save Settings</button></p>
                    </form>
                </section>
            </div>
        </div>
        <?php
    }

    public function handle_ajax_generate_image(): void
    {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field(wp_unslash($_POST['prompt'])) : '';
        if ($prompt === '') {
            wp_send_json_error(['message' => 'Prompt is required.'], 400);
        }

        $settings = $this->get_settings();
        $profiles = $this->get_usage_profiles();

        $usage = isset($_POST['usage']) ? sanitize_key(wp_unslash($_POST['usage'])) : $settings['usage'];
        if (!isset($profiles[$usage])) {
            $usage = $settings['usage'];
        }

        $profile = $profiles[$usage];
        $model = isset($_POST['model']) && $_POST['model'] !== ''
            ? sanitize_text_field(wp_unslash($_POST['model']))
            : $settings['model'];
        $batch_count = $this->sanitize_int_option($_POST['batch_count'] ?? 1, 1, 6, 1);
        $cache_enabled_for_run = ((int) $settings['enable_cache'] === 1) && ($batch_count === 1);
        $dedupe_enabled_for_run = ((int) $settings['enable_dedupe'] === 1) && ($batch_count === 1);

        $aspect_ratio = isset($_POST['aspect_ratio']) ? sanitize_text_field(wp_unslash($_POST['aspect_ratio'])) : '';
        if ($aspect_ratio !== '' && !preg_match('/^\d+(\.\d+)?:\d+(\.\d+)?$/', $aspect_ratio)) {
            wp_send_json_error(['message' => 'Invalid aspect ratio. Use format like 16:9.'], 400);
        }

        $target_kb = $this->sanitize_int_option($_POST['target_kb'] ?? $profile['target_kb'], 80, 5000, $profile['target_kb']);
        $max_width = $this->sanitize_int_option($_POST['max_width'] ?? $profile['max_width'], 960, 4096, $profile['max_width']);
        $min_width = $this->sanitize_int_option($_POST['min_width'] ?? $profile['min_width'], 900, 3000, $profile['min_width']);
        $quality = $this->sanitize_int_option($_POST['quality'] ?? $profile['quality'], 70, 100, $profile['quality']);
        $min_quality = $this->sanitize_int_option($_POST['min_quality'] ?? $profile['min_quality'], 60, 100, $profile['min_quality']);

        if ($min_width > $max_width) {
            wp_send_json_error(['message' => 'Min width cannot be greater than max width.'], 400);
        }

        if ($min_quality > $quality) {
            $quality = $min_quality;
        }

        $api_key = trim((string) $settings['api_key']);
        if ($api_key === '') {
            wp_send_json_error(['message' => 'OpenRouter API key is not configured.'], 400);
        }

        $provided_alt = isset($_POST['alt']) ? sanitize_textarea_field(wp_unslash($_POST['alt'])) : '';

        $source_attachment_id = isset($_POST['source_attachment_id']) ? absint(wp_unslash($_POST['source_attachment_id'])) : 0;
        $source_image_url = isset($_POST['source_image_url']) ? esc_url_raw(wp_unslash($_POST['source_image_url'])) : '';
        if ($source_attachment_id > 0) {
            $attachment_url = wp_get_attachment_url($source_attachment_id);
            if ($attachment_url && is_string($attachment_url)) {
                $source_image_url = $attachment_url;
            }
        }

        if ($source_image_url !== '' && !wp_http_validate_url($source_image_url)) {
            wp_send_json_error(['message' => 'Invalid source image URL.'], 400);
        }

        $site_brand = $this->get_site_brand();
        $generation_mode = $source_image_url !== '' ? 'image-to-image' : 'text-to-image';
        $metadata = null;
        $metadata_source = 'not-called';
        $results = [];

        for ($variant_index = 1; $variant_index <= $batch_count; $variant_index++) {
            $effective_prompt = $this->build_effective_prompt($prompt, $variant_index, $batch_count);
            $cache_key = $this->build_prompt_cache_key([
                'prompt' => $effective_prompt,
                'model' => $model,
                'aspect_ratio' => $aspect_ratio,
                'source_image_url' => $source_image_url,
                'usage' => $usage,
                'max_width' => $max_width,
                'min_width' => $min_width,
                'quality' => $quality,
                'min_quality' => $min_quality,
                'target_kb' => $target_kb,
            ]);

            if ($cache_enabled_for_run) {
                $cached_attachment_id = (int) get_transient($cache_key);
                if ($cached_attachment_id > 0) {
                    $cached_payload = $this->get_attachment_payload($cached_attachment_id);
                    if (!is_wp_error($cached_payload)) {
                        $cached_payload['status'] = 'cache-hit';
                        $cached_payload['mode'] = $generation_mode;
                        $cached_payload['usage'] = $usage;
                        $cached_payload['bytes_before'] = 0;
                        $cached_payload['bytes_after'] = 0;
                        $cached_payload['reduction_percent'] = 0;
                        $cached_payload['dimension'] = 0;
                        $cached_payload['quality'] = 0;
                        $cached_payload['variant'] = $variant_index;
                        $results[] = $cached_payload;
                        continue;
                    }
                }
            }

            $image_result = $this->generate_image_from_openrouter(
                $effective_prompt,
                $api_key,
                $model,
                $aspect_ratio,
                $source_image_url
            );
            if (is_wp_error($image_result)) {
                wp_send_json_error(['message' => $image_result->get_error_message()], 500);
            }

            $optimized = $this->optimize_image_for_web($image_result['binary'], $image_result['mime_type'], [
                'target_bytes' => $target_kb * 1024,
                'max_width' => $max_width,
                'min_width' => $min_width,
                'quality' => $quality,
                'min_quality' => $min_quality,
            ]);

            if (is_wp_error($optimized)) {
                wp_send_json_error(['message' => $optimized->get_error_message()], 500);
            }

            $hashes = $this->build_image_hashes($optimized['binary']);

            if ($dedupe_enabled_for_run && $hashes['exact'] !== '') {
                $similar_attachment_id = $this->find_similar_attachment_id(
                    $hashes['exact'],
                    $hashes['ahash'],
                    (int) $settings['dedupe_distance']
                );
                if ($similar_attachment_id > 0) {
                    if ($cache_enabled_for_run) {
                        set_transient($cache_key, $similar_attachment_id, 30 * DAY_IN_SECONDS);
                    }
                    $dedupe_payload = $this->get_attachment_payload($similar_attachment_id);
                    if (!is_wp_error($dedupe_payload)) {
                        $reduction = 0;
                        if ((int) $optimized['bytes_before'] > 0) {
                            $reduction = round((1 - (((int) $optimized['bytes_after']) / ((int) $optimized['bytes_before']))) * 100, 1);
                        }
                        $dedupe_payload['status'] = 'dedupe-hit';
                        $dedupe_payload['mode'] = $generation_mode;
                        $dedupe_payload['usage'] = $usage;
                        $dedupe_payload['bytes_before'] = (int) $optimized['bytes_before'];
                        $dedupe_payload['bytes_after'] = (int) $optimized['bytes_after'];
                        $dedupe_payload['reduction_percent'] = $reduction;
                        $dedupe_payload['dimension'] = (int) $optimized['dimension'];
                        $dedupe_payload['quality'] = (int) $optimized['quality'];
                        $dedupe_payload['variant'] = $variant_index;
                        $results[] = $dedupe_payload;
                        continue;
                    }
                }
            }

            if (!is_array($metadata)) {
                $metadata = $this->build_semantic_metadata(
                    $prompt,
                    $provided_alt,
                    $api_key,
                    (string) $settings['metadata_model'],
                    (int) $settings['enable_ai_metadata'] === 1
                );
                $metadata_source = (string) ($metadata['source'] ?? 'fallback');
            }

            $filename = $this->build_upload_filename($site_brand, $metadata['filename_keywords'], $variant_index, $batch_count);
            $title = $this->build_upload_title($metadata['title'], $variant_index, $batch_count);
            $alt_text = $this->generate_readable_alt($prompt, $metadata['alt']);

            $upload = $this->upload_to_media_library($optimized['binary'], $filename, $alt_text, $title);
            if (is_wp_error($upload)) {
                wp_send_json_error(['message' => $upload->get_error_message()], 500);
            }

            if ($hashes['exact'] !== '') {
                update_post_meta((int) $upload['attachment_id'], '_aiiwp_exact_hash', $hashes['exact']);
            }
            if ($hashes['ahash'] !== '') {
                update_post_meta((int) $upload['attachment_id'], '_aiiwp_ahash', $hashes['ahash']);
            }
            update_post_meta((int) $upload['attachment_id'], '_aiiwp_prompt_cache_key', $cache_key);

            if ($cache_enabled_for_run) {
                set_transient($cache_key, (int) $upload['attachment_id'], 30 * DAY_IN_SECONDS);
            }

            $reduction = 0;
            if ((int) $optimized['bytes_before'] > 0) {
                $reduction = round((1 - (((int) $optimized['bytes_after']) / ((int) $optimized['bytes_before']))) * 100, 1);
            }

            $results[] = [
                'attachment_id' => (int) $upload['attachment_id'],
                'url' => (string) $upload['url'],
                'title' => $title,
                'alt_text' => $alt_text,
                'filename' => $filename,
                'usage' => $usage,
                'bytes_before' => (int) $optimized['bytes_before'],
                'bytes_after' => (int) $optimized['bytes_after'],
                'reduction_percent' => $reduction,
                'dimension' => (int) $optimized['dimension'],
                'quality' => (int) $optimized['quality'],
                'mode' => $generation_mode,
                'status' => 'generated',
                'variant' => $variant_index,
            ];
        }

        if (empty($results)) {
            wp_send_json_error(['message' => 'No image generated.'], 500);
        }

        $primary = $results[0];
        $primary['items'] = $results;
        $primary['batch_count'] = count($results);
        $primary['batch_mode'] = $batch_count > 1 ? 'force-generate' : 'normal';
        $primary['cache_enabled_for_run'] = $cache_enabled_for_run ? 1 : 0;
        $primary['dedupe_enabled_for_run'] = $dedupe_enabled_for_run ? 1 : 0;
        $primary['metadata_model'] = (string) $settings['metadata_model'];
        $primary['metadata_source'] = is_array($metadata) ? $metadata_source : 'cache-or-dedupe-hit';
        $primary['source_attachment_id'] = $source_attachment_id;
        $primary['source_image_url'] = $source_image_url;

        wp_send_json_success($primary);
    }

    private function sanitize_settings(array $input): array
    {
        $defaults = $this->get_default_settings();
        $profiles = $this->get_usage_profiles();

        $usage = isset($input['usage']) ? sanitize_key((string) $input['usage']) : $defaults['usage'];
        if (!isset($profiles[$usage])) {
            $usage = $defaults['usage'];
        }

        $settings = [
            'api_key' => isset($input['api_key']) ? trim((string) $input['api_key']) : $defaults['api_key'],
            'model' => isset($input['model']) && trim((string) $input['model']) !== ''
                ? sanitize_text_field((string) $input['model'])
                : $defaults['model'],
            'metadata_model' => isset($input['metadata_model']) && trim((string) $input['metadata_model']) !== ''
                ? sanitize_text_field((string) $input['metadata_model'])
                : $defaults['metadata_model'],
            'usage' => $usage,
            'target_kb' => $this->sanitize_int_option($input['target_kb'] ?? $defaults['target_kb'], 80, 5000, $defaults['target_kb']),
            'max_width' => $this->sanitize_int_option($input['max_width'] ?? $defaults['max_width'], 960, 4096, $defaults['max_width']),
            'min_width' => $this->sanitize_int_option($input['min_width'] ?? $defaults['min_width'], 900, 3000, $defaults['min_width']),
            'quality' => $this->sanitize_int_option($input['quality'] ?? $defaults['quality'], 70, 100, $defaults['quality']),
            'min_quality' => $this->sanitize_int_option($input['min_quality'] ?? $defaults['min_quality'], 60, 100, $defaults['min_quality']),
            'enable_ai_metadata' => array_key_exists('enable_ai_metadata', $input)
                ? $this->sanitize_bool_option($input['enable_ai_metadata'], (bool) $defaults['enable_ai_metadata'])
                : 0,
            'enable_cache' => array_key_exists('enable_cache', $input)
                ? $this->sanitize_bool_option($input['enable_cache'], (bool) $defaults['enable_cache'])
                : 0,
            'enable_dedupe' => array_key_exists('enable_dedupe', $input)
                ? $this->sanitize_bool_option($input['enable_dedupe'], (bool) $defaults['enable_dedupe'])
                : 0,
            'dedupe_distance' => $this->sanitize_int_option($input['dedupe_distance'] ?? $defaults['dedupe_distance'], 1, 20, $defaults['dedupe_distance']),
        ];

        if ($settings['min_width'] > $settings['max_width']) {
            $settings['min_width'] = $settings['max_width'];
        }
        if ($settings['min_quality'] > $settings['quality']) {
            $settings['min_quality'] = $settings['quality'];
        }

        return $settings;
    }

    private function get_settings(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return $this->sanitize_settings(array_merge($this->get_default_settings(), $stored));
    }

    private function get_default_settings(): array
    {
        return [
            'api_key' => '',
            'model' => 'google/gemini-3.1-flash-image-preview',
            'metadata_model' => 'google/gemini-2.5-flash-lite-preview-09-2025',
            'usage' => 'content',
            'target_kb' => 350,
            'max_width' => 1920,
            'min_width' => 1200,
            'quality' => 88,
            'min_quality' => 78,
            'enable_ai_metadata' => 1,
            'enable_cache' => 1,
            'enable_dedupe' => 1,
            'dedupe_distance' => 5,
        ];
    }

    private function get_usage_profiles(): array
    {
        return [
            'content' => [
                'target_kb' => 350,
                'max_width' => 1920,
                'min_width' => 1200,
                'quality' => 88,
                'min_quality' => 78,
            ],
            'featured' => [
                'target_kb' => 500,
                'max_width' => 2400,
                'min_width' => 1600,
                'quality' => 90,
                'min_quality' => 80,
            ],
            'hero' => [
                'target_kb' => 700,
                'max_width' => 2560,
                'min_width' => 1920,
                'quality' => 90,
                'min_quality' => 82,
            ],
        ];
    }

    private function sanitize_int_option($value, int $min, int $max, int $fallback): int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        $int_value = (int) $value;
        if ($int_value < $min || $int_value > $max) {
            return $fallback;
        }
        return $int_value;
    }

    private function sanitize_bool_option($value, bool $fallback): int
    {
        if ($value === null) {
            return $fallback ? 1 : 0;
        }
        return (int) (!empty($value));
    }

    private function generate_image_from_openrouter(
        string $prompt,
        string $api_key,
        string $model,
        string $aspect_ratio,
        string $source_image_url = ''
    )
    {
        $message_content = $prompt;
        if ($source_image_url !== '') {
            $message_content = [
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $source_image_url,
                    ],
                ],
            ];
        }

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $message_content,
                ],
            ],
            'modalities' => ['image', 'text'],
        ];

        if ($aspect_ratio !== '') {
            $payload['image_config'] = ['aspect_ratio' => $aspect_ratio];
        }

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => home_url('/'),
                'X-Title' => get_bloginfo('name') ?: 'WordPress AI Image Generator',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('aiiwp_openrouter_request_failed', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            return new WP_Error('aiiwp_openrouter_http_error', 'OpenRouter error ' . $status . ': ' . $body);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new WP_Error('aiiwp_openrouter_decode_error', 'Invalid OpenRouter response format.');
        }

        $image_url = $this->extract_image_url_from_response($decoded);
        if ($image_url === '') {
            return new WP_Error('aiiwp_openrouter_image_missing', 'No image found in OpenRouter response.');
        }

        return $this->image_url_to_binary($image_url);
    }

    private function extract_image_url_from_response(array $data): string
    {
        $message = $data['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            return '';
        }

        if (isset($message['images']) && is_array($message['images'])) {
            foreach ($message['images'] as $image) {
                if (!is_array($image)) {
                    continue;
                }
                $candidate = '';
                if (isset($image['image_url']) && is_array($image['image_url']) && isset($image['image_url']['url'])) {
                    $candidate = (string) $image['image_url']['url'];
                } elseif (isset($image['image_url']) && is_string($image['image_url'])) {
                    $candidate = $image['image_url'];
                } elseif (isset($image['url']) && is_string($image['url'])) {
                    $candidate = $image['url'];
                }
                if ($candidate !== '') {
                    return trim($candidate);
                }
            }
        }

        if (isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (isset($part['image_url']) && is_array($part['image_url']) && isset($part['image_url']['url'])) {
                    return trim((string) $part['image_url']['url']);
                }
                if (isset($part['image_url']) && is_string($part['image_url'])) {
                    return trim($part['image_url']);
                }
            }
        }

        if (isset($message['content']) && is_string($message['content'])) {
            $raw = trim($message['content']);
            if (preg_match('/^(data:image\/|https?:\/\/)/i', $raw) === 1) {
                return $raw;
            }
            if (preg_match('/\((data:image\/[^)]+|https?:\/\/[^)\s]+)\)/i', $raw, $matches) === 1) {
                return (string) $matches[1];
            }
        }

        return '';
    }

    private function image_url_to_binary(string $image_url)
    {
        if (strpos($image_url, 'data:') === 0) {
            if (preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $image_url, $matches) !== 1) {
                return new WP_Error('aiiwp_data_url_invalid', 'Invalid data URL image format.');
            }
            $binary = base64_decode($matches[2], true);
            if ($binary === false) {
                return new WP_Error('aiiwp_data_url_decode', 'Failed to decode image data from OpenRouter response.');
            }
            return [
                'binary' => $binary,
                'mime_type' => $matches[1],
            ];
        }

        $response = wp_remote_get($image_url, ['timeout' => 120]);
        if (is_wp_error($response)) {
            return new WP_Error('aiiwp_image_download_failed', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return new WP_Error('aiiwp_image_download_http', 'Failed to download generated image. HTTP ' . $status);
        }

        $binary = (string) wp_remote_retrieve_body($response);
        $mime_type = (string) wp_remote_retrieve_header($response, 'content-type');
        if (strpos($mime_type, ';') !== false) {
            $mime_type = trim((string) explode(';', $mime_type)[0]);
        }
        if ($mime_type === '') {
            $mime_type = 'image/jpeg';
        }

        return [
            'binary' => $binary,
            'mime_type' => $mime_type,
        ];
    }

    private function optimize_image_for_web(string $binary, string $mime_type, array $options)
    {
        $tmp_dir = trailingslashit(wp_get_upload_dir()['basedir']) . 'aiiwp-temp';
        wp_mkdir_p($tmp_dir);

        $source_path = trailingslashit($tmp_dir) . 'source-' . wp_generate_uuid4() . $this->mime_to_extension($mime_type);
        $paths_to_cleanup = [$source_path];

        if (@file_put_contents($source_path, $binary) === false) {
            return new WP_Error('aiiwp_temp_write_failed', 'Unable to create temporary source image.');
        }

        $bytes_before = (int) filesize($source_path);
        $source_dimension = $this->get_image_max_dimension($source_path);
        if ($source_dimension <= 0) {
            $this->cleanup_paths($paths_to_cleanup);
            return new WP_Error('aiiwp_dimension_failed', 'Could not read image dimensions.');
        }

        $max_width = (int) $options['max_width'];
        $min_width = (int) $options['min_width'];
        $target_bytes = (int) $options['target_bytes'];
        $quality = (int) $options['quality'];
        $min_quality = (int) $options['min_quality'];

        $current_dimension = min($source_dimension, $max_width);
        $best_path = '';
        $best_size = PHP_INT_MAX;
        $best_quality = $quality;
        $best_dimension = $current_dimension;

        while (true) {
            for ($q = $quality; $q >= $min_quality; $q -= 3) {
                $candidate_path = trailingslashit($tmp_dir) . 'cand-' . wp_generate_uuid4() . '.jpg';
                $paths_to_cleanup[] = $candidate_path;

                $rendered = $this->render_candidate_jpeg($source_path, $candidate_path, $current_dimension, $q);
                if (is_wp_error($rendered)) {
                    continue;
                }

                $candidate_size = (int) filesize($candidate_path);
                if ($candidate_size < $best_size) {
                    $best_size = $candidate_size;
                    $best_path = $candidate_path;
                    $best_quality = $q;
                    $best_dimension = $current_dimension;
                }

                if ($candidate_size <= $target_bytes) {
                    $best_path = $candidate_path;
                    $best_size = $candidate_size;
                    $best_quality = $q;
                    $best_dimension = $current_dimension;
                    break 2;
                }
            }

            if ($current_dimension <= $min_width) {
                break;
            }

            $next_dimension = (int) floor($current_dimension * 0.9);
            if ($next_dimension < $min_width) {
                $next_dimension = $min_width;
            }
            if ($next_dimension === $current_dimension) {
                break;
            }
            $current_dimension = $next_dimension;
        }

        if ($best_path === '' || !file_exists($best_path)) {
            $this->cleanup_paths($paths_to_cleanup);
            return new WP_Error('aiiwp_optimize_failed', 'Image optimization failed.');
        }

        $optimized_binary = (string) file_get_contents($best_path);
        $bytes_after = strlen($optimized_binary);

        $this->cleanup_paths($paths_to_cleanup);

        return [
            'binary' => $optimized_binary,
            'bytes_before' => $bytes_before,
            'bytes_after' => $bytes_after,
            'quality' => $best_quality,
            'dimension' => $best_dimension,
        ];
    }

    private function render_candidate_jpeg(string $source_path, string $target_path, int $max_dimension, int $quality)
    {
        $editor = wp_get_image_editor($source_path);
        if (is_wp_error($editor)) {
            return $editor;
        }

        $size = $editor->get_size();
        $width = isset($size['width']) ? (int) $size['width'] : 0;
        $height = isset($size['height']) ? (int) $size['height'] : 0;

        if ($width > 0 && $height > 0) {
            $largest = max($width, $height);
            if ($largest > $max_dimension) {
                if ($width >= $height) {
                    $editor->resize($max_dimension, null, false);
                } else {
                    $editor->resize(null, $max_dimension, false);
                }
            }
        }

        $editor->set_quality($quality);
        $saved = $editor->save($target_path, 'image/jpeg');
        return is_wp_error($saved) ? $saved : true;
    }

    private function get_image_max_dimension(string $file_path): int
    {
        $size = @getimagesize($file_path);
        if (!is_array($size)) {
            return 0;
        }
        return max((int) $size[0], (int) $size[1]);
    }

    private function mime_to_extension(string $mime_type): string
    {
        if ($mime_type === 'image/png') {
            return '.png';
        }
        if ($mime_type === 'image/webp') {
            return '.webp';
        }
        if ($mime_type === 'image/gif') {
            return '.gif';
        }
        if ($mime_type === 'image/jpeg' || $mime_type === 'image/jpg') {
            return '.jpg';
        }
        return '.img';
    }

    private function get_site_brand(): string
    {
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = preg_replace('/^www\./', '', strtolower($host)) ?? '';
        $labels = array_values(array_filter(explode('.', $host)));
        if (empty($labels)) {
            return 'site';
        }
        return sanitize_title((string) $labels[0]) ?: 'site';
    }

    private function text_to_slug(string $text): string
    {
        $slug = sanitize_title($text);
        $parts = array_values(array_filter(explode('-', $slug), static function ($part) {
            return $part !== '';
        }));
        if (empty($parts)) {
            return '';
        }
        return implode('-', array_slice($parts, 0, 8));
    }

    private function slug_to_title(string $slug): string
    {
        $parts = array_values(array_filter(explode('-', sanitize_title($slug))));
        if (empty($parts)) {
            return 'Generated Image';
        }
        $parts = array_slice($parts, 0, 10);
        $title = implode(' ', $parts);
        $title = ucwords($title);
        return trim($title) !== '' ? trim($title) : 'Generated Image';
    }

    private function build_upload_filename(string $site_brand, string $filename_keywords, int $variant_index, int $batch_count): string
    {
        $keyword_slug = $this->text_to_slug($filename_keywords);
        if ($keyword_slug === '') {
            $keyword_slug = 'generated-image';
        }
        $base = sanitize_title($site_brand . '-' . $keyword_slug);
        if ($base === '') {
            $base = 'generated-image';
        }
        if ($batch_count > 1) {
            $base .= '-v' . $variant_index;
        }
        return $base . '.jpg';
    }

    private function build_upload_title(string $title, int $variant_index, int $batch_count): string
    {
        $clean_title = trim(sanitize_text_field($title));
        if ($clean_title === '') {
            $clean_title = 'Generated Image';
        }
        if ($batch_count > 1) {
            return $clean_title . ' (Variant ' . $variant_index . ')';
        }
        return $clean_title;
    }

    private function build_effective_prompt(string $prompt, int $variant_index, int $batch_count): string
    {
        if ($batch_count <= 1) {
            return $prompt;
        }
        return $prompt . "\n\nCreate a distinct variation #" . $variant_index . ' of ' . $batch_count . ' while keeping the same core concept.';
    }

    private function build_prompt_cache_key(array $input): string
    {
        $json = wp_json_encode($input);
        $digest = hash('sha256', is_string($json) ? $json : serialize($input));
        return 'aiiwp_cache_' . substr($digest, 0, 40);
    }

    private function build_semantic_metadata(
        string $prompt,
        string $provided_alt,
        string $api_key,
        string $metadata_model,
        bool $use_ai
    ): array {
        $fallback = [
            'filename_keywords' => $this->text_to_slug($prompt) ?: 'generated-image',
            'title' => $this->slug_to_title($this->text_to_slug($prompt)),
            'alt' => $this->generate_readable_alt($prompt, $provided_alt),
            'source' => 'fallback',
        ];

        if (!$use_ai || trim($metadata_model) === '') {
            return $fallback;
        }

        $instruction = "Generate SEO metadata for a WordPress image.\n"
            . "Return STRICT JSON only with keys: filename_keywords, title, alt.\n"
            . "Rules:\n"
            . "- filename_keywords: 3-6 lowercase English words, hyphen-separated, no numbers, no timestamps, no domain suffixes.\n"
            . "- title: natural human-readable title, 5-12 words, no trailing numbers.\n"
            . "- alt: concise accessible alt text, 8-18 words, no ellipsis, no prompt instructions.\n";
        $user_input = "Prompt:\n" . $prompt;
        if (trim($provided_alt) !== '') {
            $user_input .= "\nPreferred alt hint:\n" . $provided_alt;
        }

        $payload = [
            'model' => trim($metadata_model),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $instruction,
                ],
                [
                    'role' => 'user',
                    'content' => $user_input,
                ],
            ],
            'temperature' => 0.2,
            'max_tokens' => 180,
        ];

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => home_url('/'),
                'X-Title' => get_bloginfo('name') ?: 'WordPress AI Image Generator',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return $fallback;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status < 200 || $status >= 300) {
            return $fallback;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $fallback;
        }
        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            return $fallback;
        }

        $json_candidate = $this->extract_json_object($content);
        if ($json_candidate === '') {
            return $fallback;
        }
        $meta = json_decode($json_candidate, true);
        if (!is_array($meta)) {
            return $fallback;
        }

        $filename_keywords = $this->text_to_slug((string) ($meta['filename_keywords'] ?? ''));
        $title = sanitize_text_field((string) ($meta['title'] ?? ''));
        $title = trim((string) preg_replace('/\\s*[-#]?\\d+\\s*$/', '', $title));
        $alt = $this->sanitize_alt_text((string) ($meta['alt'] ?? ''));

        if ($filename_keywords === '') {
            $filename_keywords = $fallback['filename_keywords'];
        }
        if ($title === '') {
            $title = $fallback['title'];
        }
        if ($alt === '') {
            $alt = $fallback['alt'];
        }

        return [
            'filename_keywords' => $filename_keywords,
            'title' => $title,
            'alt' => $alt,
            'source' => 'ai-model',
        ];
    }

    private function extract_json_object(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }
        if ($trimmed[0] === '{' && str_ends_with($trimmed, '}')) {
            return $trimmed;
        }
        if (preg_match('/\{[\s\S]*\}/', $trimmed, $matches) === 1) {
            return (string) $matches[0];
        }
        return '';
    }

    private function get_attachment_payload(int $attachment_id)
    {
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return new WP_Error('aiiwp_attachment_missing', 'Attachment URL not found.');
        }

        $file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        $filename = $file !== '' ? wp_basename($file) : '';
        $title = get_the_title($attachment_id);
        $alt_text = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

        return [
            'attachment_id' => $attachment_id,
            'url' => (string) $url,
            'title' => $title ? (string) $title : '',
            'alt_text' => $alt_text,
            'filename' => $filename,
        ];
    }

    private function build_image_hashes(string $binary): array
    {
        return [
            'exact' => hash('sha256', $binary),
            'ahash' => $this->compute_ahash($binary),
        ];
    }

    private function compute_ahash(string $binary): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) {
            return '';
        }

        $source = @imagecreatefromstring($binary);
        if (!$source) {
            return '';
        }

        $scaled = @imagescale($source, 8, 8, IMG_BILINEAR_FIXED);
        imagedestroy($source);
        if (!$scaled) {
            return '';
        }

        $luma = [];
        $sum = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($scaled, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $v = (int) round((0.299 * $r) + (0.587 * $g) + (0.114 * $b));
                $luma[] = $v;
                $sum += $v;
            }
        }
        imagedestroy($scaled);

        if (count($luma) !== 64) {
            return '';
        }

        $avg = $sum / 64;
        $bits = '';
        foreach ($luma as $v) {
            $bits .= ($v >= $avg) ? '1' : '0';
        }
        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex(bindec(substr($bits, $i, 4)));
        }
        return strtolower($hex);
    }

    private function hamming_distance(string $a, string $b): int
    {
        if ($a === '' || $b === '' || strlen($a) !== strlen($b)) {
            return PHP_INT_MAX;
        }
        $distance = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $xor = hexdec($a[$i]) ^ hexdec($b[$i]);
            $distance += substr_count(decbin($xor), '1');
        }
        return $distance;
    }

    private function find_similar_attachment_id(string $exact_hash, string $ahash, int $threshold): int
    {
        if ($exact_hash !== '') {
            $exact = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => '_aiiwp_exact_hash',
                'meta_value' => $exact_hash,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            if (!empty($exact)) {
                return (int) $exact[0];
            }
        }

        if ($ahash === '') {
            return 0;
        }

        $candidates = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 150,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_aiiwp_ahash',
                    'compare' => 'EXISTS',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $best_id = 0;
        $best_distance = PHP_INT_MAX;
        foreach ($candidates as $candidate_id) {
            $candidate_hash = (string) get_post_meta((int) $candidate_id, '_aiiwp_ahash', true);
            if ($candidate_hash === '') {
                continue;
            }
            $distance = $this->hamming_distance($ahash, $candidate_hash);
            if ($distance < $best_distance) {
                $best_distance = $distance;
                $best_id = (int) $candidate_id;
            }
        }

        if ($best_id > 0 && $best_distance <= $threshold) {
            return $best_id;
        }
        return 0;
    }

    private function generate_readable_alt(string $prompt, string $provided_alt): string
    {
        $candidate = $this->sanitize_alt_text($provided_alt);
        if ($candidate !== '') {
            return $candidate;
        }

        $candidate = $this->sanitize_alt_text($prompt);
        if ($candidate !== '') {
            return $candidate;
        }

        return 'Generated image';
    }

    private function sanitize_alt_text(string $text): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)) ?? '');
        if ($clean === '') {
            return '';
        }

        $sentences = preg_split('/[.!?。！？]/u', $clean);
        $first = '';
        if (is_array($sentences) && isset($sentences[0])) {
            $first = trim((string) $sentences[0]);
        }
        if ($first === '') {
            $first = $clean;
        }

        $words = preg_split('/\s+/', $first);
        if (is_array($words) && count($words) > 18) {
            $first = implode(' ', array_slice($words, 0, 18));
        }

        if (function_exists('mb_strlen') && mb_strlen($first) > 120) {
            $first = rtrim((string) mb_substr($first, 0, 120));
        } elseif (strlen($first) > 120) {
            $first = rtrim(substr($first, 0, 120));
        }

        return $first;
    }

    private function upload_to_media_library(string $binary, string $filename, string $alt_text, string $title)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam($filename);
        if (!$tmp) {
            return new WP_Error('aiiwp_tmp_failed', 'Could not create temporary upload file.');
        }

        if (@file_put_contents($tmp, $binary) === false) {
            @unlink($tmp);
            return new WP_Error('aiiwp_tmp_write_failed', 'Could not write temporary upload file.');
        }

        $file_array = [
            'name' => $filename,
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload(
            $file_array,
            0,
            $alt_text,
            [
                'post_title' => sanitize_text_field($title),
                'post_status' => 'inherit',
            ]
        );

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', $alt_text);

        $url = wp_get_attachment_url((int) $attachment_id);
        if (!$url) {
            return new WP_Error('aiiwp_url_failed', 'Image uploaded but URL could not be retrieved.');
        }

        return [
            'attachment_id' => (int) $attachment_id,
            'url' => $url,
        ];
    }

    private function cleanup_paths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }
    }
}

new AIWP_Image_Generator_Plugin();
