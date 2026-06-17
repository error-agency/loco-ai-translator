<?php
if (!defined('ABSPATH'))
    exit;

class LAT_Ajax
{

    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_ajax_lat_get_po_info', [$this, 'get_po_info']);
        add_action('wp_ajax_lat_translate_file', [$this, 'translate_file']);
        add_action('wp_ajax_lat_cancel_job', [$this, 'cancel_job']);
        add_action('wp_ajax_lat_fetch_models', [$this, 'fetch_models']);
        add_action('wp_ajax_lat_test_connection', [$this, 'test_connection']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // lat_get_po_info
    // Validates the .po path and returns total untranslated string count.
    // Called once by JS before starting translation so we show accurate numbers.
    // ─────────────────────────────────────────────────────────────────────────

    public function get_po_info()
    {
        check_ajax_referer('lat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $po_path = $this->validate_po_path(wp_unslash($_POST['po_path'] ?? ''));
        if (is_wp_error($po_path)) {
            wp_send_json_error(['message' => $po_path->get_error_message()]);
        }

        $entries = LAT_Po_Handler::parse($po_path);
        if (is_wp_error($entries)) {
            wp_send_json_error(['message' => $entries->get_error_message()]);
        }

        $settings = LAT_Settings::instance();
        $skip_translated = (bool)$settings->get('skip_translated', true);
        $untranslated = LAT_Po_Handler::get_untranslated($entries, $skip_translated);
        $total = count($entries);
        $total_untrans = count($untranslated);

        // Detect locale from filename
        $basename = pathinfo($po_path, PATHINFO_FILENAME);
        $detected_locale = '';
        if (preg_match('/[-_]([a-z]{2,3}_[A-Z]{2,3})$/', $basename, $m)) {
            $detected_locale = $m[1];
        }

        wp_send_json_success([
            'path' => $po_path,
            'total_entries' => $total,
            'untranslated' => $total_untrans,
            'detected_locale' => $detected_locale,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // lat_translate_file
    // Translates one batch of untranslated strings from a .po file.
    // Client calls this repeatedly (incrementing batch_index) until done=true.
    //
    // Retry logic (server-side):
    //   Each batch is retried up to max_retries times before being skipped.
    //   Skipped strings are left untranslated and reported to the client.
    // ─────────────────────────────────────────────────────────────────────────

    public function translate_file()
    {
        check_ajax_referer('lat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        if (!ini_get('safe_mode')) {
            @set_time_limit(300);
        }

        $po_path = $this->validate_po_path(wp_unslash($_POST['po_path'] ?? ''));
        $target_lang = sanitize_text_field(wp_unslash($_POST['target_lang'] ?? 'Bulgarian'));
        $batch_index = absint($_POST['batch_index'] ?? 0);
        $job_id = sanitize_key($_POST['job_id'] ?? '');

        if (is_wp_error($po_path)) {
            wp_send_json_error(['message' => $po_path->get_error_message()]);
        }

        // ── Cancel check ─────────────────────────────────────────────────────
        // JS calls lat_cancel_job which sets a transient. We check it here so
        // the next batch request returns cancelled=true immediately.
        if ($job_id && get_transient('lat_cancel_' . $job_id)) {
            delete_transient('lat_cancel_' . $job_id);
            wp_send_json_success([
                'done' => true,
                'cancelled' => true,
                'message' => 'Translation cancelled by user.',
            ]);
        }

        $entries = LAT_Po_Handler::parse($po_path);
        if (is_wp_error($entries)) {
            wp_send_json_error(['message' => $entries->get_error_message()]);
        }

        $settings = LAT_Settings::instance();
        $skip_translated = (bool)$settings->get('skip_translated', true);
        $batch_sz = max(1, (int)$settings->get('batch_size', 40));
        $max_retries = max(0, (int)$settings->get('max_retries', 3));

        $untranslated = LAT_Po_Handler::get_untranslated($entries, $skip_translated);
        $remaining_now = count($untranslated);
        $total_original = max(1, absint($_POST['total_original'] ?? $remaining_now));

        if ($remaining_now === 0) {
            wp_send_json_success([
                'done' => true,
                'message' => 'All strings are already translated.',
                'translated' => $total_original,
                'skipped' => 0,
                'total' => $total_original,
                'percent' => 100,
                'remaining' => 0,
            ]);
        }

        // ── THE FIX ──────────────────────────────────────────────────────────
        // We re-parse the file on every request and filter already-translated
        // strings. This means $untranslated always starts from the first
        // NOT-YET-translated entry. We ALWAYS slice from index 0 — never use
        // batch_index as an offset, because that causes every other batch to be
        // skipped (the array shrinks between requests).
        $batch = array_slice($untranslated, 0, $batch_sz, true);

        $source_strings = array_column($batch, 'msgid');
        $client = new LAT_Api_Client();
        $batch_start_ts = microtime(true);

        // ── Retry loop ────────────────────────────────────────────────────────
        $result = null;
        $last_error = '';
        $attempt = 0;
        $token_usage = ['prompt' => 0, 'completion' => 0, 'total' => 0];

        while ($attempt <= $max_retries) {
            if ($attempt > 0) {
                sleep(min((int)pow(2, $attempt - 1), 8));
            }

            $try = $client->translate_batch($source_strings, $target_lang);

            if (!is_wp_error($try)) {
                $result = $try;
                // translate_batch may return an array with a '_usage' key appended
                if (isset($result['_usage'])) {
                    $token_usage = $result['_usage'];
                    unset($result['_usage']);
                    $result = array_values($result);
                }
                break;
            }

            $last_error = $try->get_error_message();
            $attempt++;
        }

        $batch_ms = (int)round((microtime(true) - $batch_start_ts) * 1000);

        // ── Apply or skip ─────────────────────────────────────────────────────
        $skipped_count = 0;
        if ($result === null) {
            error_log(sprintf(
                '[LAT] Batch failed after %d retries. Error: %s. Skipping %d strings.',
                $max_retries, $last_error, count($batch)
            ));
            $skipped_count = count($batch);
            // Mark these strings with a placeholder so they don't block the loop
            $skip_map = [];
            foreach (array_values($batch) as $item) {
                $skip_map[$item['index']] = ''; // empty = still untranslated, but flagged
            }
            // We do NOT save anything — they remain untranslated and will be
            // picked up again on the next run. Just advance past them by marking
            // them with a fuzzy flag so they are no longer "untranslated".
            $entries = LAT_Po_Handler::flag_as_skipped($entries, array_keys($skip_map));
            LAT_Po_Handler::save($po_path, $entries);
        }
        else {
            $translation_map = [];
            foreach (array_values($batch) as $i => $item) {
                $translated = isset($result[$i]) ? (string)$result[$i] : '';
                if ($translated !== '') {
                    $translation_map[$item['index']] = $translated;
                }
                else {
                    $skipped_count++;
                }
            }

            $entries = LAT_Po_Handler::apply_translations($entries, $translation_map);
            $saved = LAT_Po_Handler::save($po_path, $entries);

            if (is_wp_error($saved)) {
                wp_send_json_error(['message' => $saved->get_error_message()]);
            }
        }

        // Re-count remaining after save for accurate progress
        $entries_after = LAT_Po_Handler::parse($po_path);
        $remaining_after = is_wp_error($entries_after)
            ? max(0, $remaining_now - count($batch))
            : count(LAT_Po_Handler::get_untranslated($entries_after, $skip_translated));

        $translated_so_far = $total_original - $remaining_after;
        $percent = min(100, (int)round(($translated_so_far / $total_original) * 100));
        $is_done = ($remaining_after === 0);

        $response = [
            'done' => $is_done,
            'batch_index' => $batch_index + 1, // kept for JS counter display only
            'translated' => $translated_so_far,
            'skipped' => $skipped_count,
            'total' => $total_original,
            'remaining' => $remaining_after,
            'percent' => $percent,
            'batch_count' => count($batch),
            'batch_ms' => $batch_ms,
            'batch_preview' => array_slice($source_strings, 0, 3),
            'tokens_prompt' => $token_usage['prompt'],
            'tokens_completion' => $token_usage['completion'],
            'tokens_total' => $token_usage['total'],
        ];

        if ($skipped_count > 0 && $result === null) {
            $response['skip_reason'] = sprintf(
                'Batch skipped after %d retries: %s', $max_retries, $last_error
            );
        }

        wp_send_json_success($response);
    }
    // ─────────────────────────────────────────────────────────────────────────
    // lat_cancel_job
    // Sets a transient flag that translate_file checks at the top of each batch.
    // ─────────────────────────────────────────────────────────────────────────

    public function cancel_job()
    {
        check_ajax_referer('lat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $job_id = sanitize_key($_POST['job_id'] ?? '');
        if (empty($job_id)) {
            wp_send_json_error(['message' => 'No job_id provided.']);
        }

        // Flag expires in 10 minutes — more than enough for the next batch to pick it up
        set_transient('lat_cancel_' . $job_id, 1, 10 * MINUTE_IN_SECONDS);

        wp_send_json_success(['message' => 'Cancel signal sent.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // lat_fetch_models
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_models()
    {
        check_ajax_referer('lat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $client = new LAT_Api_Client();
        $models = $client->fetch_models();

        if (is_wp_error($models)) {
            wp_send_json_error(['message' => $models->get_error_message()]);
        }

        wp_send_json_success(['models' => $models]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // lat_test_connection
    // ─────────────────────────────────────────────────────────────────────────

    public function test_connection()
    {
        check_ajax_referer('lat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $client = new LAT_Api_Client();
        $result = $client->translate_batch(['Hello'], 'Bulgarian');

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'Connection successful!',
            'test_input' => 'Hello',
            'test_output' => $result[0] ?? '(empty)',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve and validate a .po file path supplied by Loco Translate.
     *
     * Loco can store translation files in three locations and passes the path
     * as a URL query param (?path=…). The param may be:
     *   a) An absolute server path  →  used directly
     *   b) A relative path          →  resolved against each candidate base (see below)
     *
     * Loco's three standard locations (relative to WP_CONTENT_DIR):
     *   Custom  →  languages/loco/plugins/<file>.po
     *   Author  →  plugins/<slug>/languages/<file>.po   (= WP_PLUGIN_DIR/<slug>/languages/)
     *   System  →  languages/plugins/<file>.po
     *
     * We also accept paths relative to ABSPATH for completeness.
     *
     * @param  string $raw  Raw path string from $_POST / $_GET.
     * @return string|WP_Error  Absolute, normalised path on success.
     */
    private function validate_po_path($raw)
    {
        $path = trim($raw);

        if (empty($path)) {
            return new WP_Error('empty_path', 'No .po file path provided.');
        }

        // Must end in .po (check before any resolution to fail fast)
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'po') {
            return new WP_Error('not_po', 'File must have a .po extension.');
        }

        // Normalise slashes
        $path = wp_normalize_path($path);

        // ── 1. Absolute path — try it directly ──────────────────────────────
        if (path_is_absolute($path)) {
            return $this->verify_po_path($path);
        }

        // ── 2. Relative path — strip any leading slashes and try each base ──
        $rel = ltrim($path, '/');

        $bases = [
            // Loco Custom:  wp-content/languages/loco/…
            // Loco System:  wp-content/languages/…
            // Generic:      wp-content/…
            wp_normalize_path(WP_CONTENT_DIR) . '/',

            // Loco Author:  wp-content/plugins/…  (WP_PLUGIN_DIR may differ from WP_CONTENT_DIR/plugins)
            wp_normalize_path(WP_PLUGIN_DIR) . '/',

            // WP root
            wp_normalize_path(ABSPATH),

            // WP root with wp-content stripped  (Loco sometimes passes "wp-content/languages/…")
            wp_normalize_path(ABSPATH),
        ];

        // Also try with explicit Loco sub-dirs prepended in case $rel is just the filename
        $filename = basename($path);
        if ($rel === $filename) {
            // Only filename given — probe all three Loco dirs
            $slug = $this->extract_slug_from_filename($filename);
            $bases[] = wp_normalize_path(WP_CONTENT_DIR) . '/languages/loco/plugins/';
            $bases[] = wp_normalize_path(WP_CONTENT_DIR) . '/languages/loco/themes/';
            $bases[] = wp_normalize_path(WP_CONTENT_DIR) . '/languages/plugins/';
            $bases[] = wp_normalize_path(WP_CONTENT_DIR) . '/languages/themes/';
            if ($slug) {
                $bases[] = wp_normalize_path(WP_PLUGIN_DIR) . '/' . $slug . '/languages/';
            }
        }

        foreach ($bases as $base) {
            $candidate = wp_normalize_path($base . $rel);
            $result = $this->verify_po_path($candidate);
            if (!is_wp_error($result)) {
                return $result;
            }
        }

        // ── 3. Nothing worked — return a helpful error with all tried paths ──
        $tried = array_map(function ($b) use ($rel) {
            return $b . $rel;
        }, array_unique($bases));

        return new WP_Error(
            'not_found',
            sprintf(
            'File not found: %s — checked %d locations. Make sure the file exists and is readable.',
            esc_html(basename($path)),
            count($tried)
        )
            );
    }

    /**
     * Check that a resolved absolute path is a readable .po inside wp-content.
     *
     * @param  string $path  Absolute, normalised path.
     * @return string|WP_Error
     */
    private function verify_po_path($path)
    {
        if (!file_exists($path)) {
            return new WP_Error('not_found', 'File not found: ' . esc_html(basename($path)));
        }

        if (!is_readable($path)) {
            return new WP_Error('not_readable', 'File not readable: ' . esc_html(basename($path)));
        }

        // Security: must be inside wp-content
        $content_dir = wp_normalize_path(WP_CONTENT_DIR);
        $plugin_dir = wp_normalize_path(WP_PLUGIN_DIR);

        $in_content = strpos($path, $content_dir) === 0;
        $in_plugins = strpos($path, $plugin_dir) === 0;

        if (!$in_content && !$in_plugins) {
            return new WP_Error('outside_wp', 'File must be inside wp-content or wp-plugins directory.');
        }

        return $path;
    }

    /**
     * Try to extract a plugin/theme slug from a .po filename.
     * e.g.  "devpulse-bg_BG.po"  →  "devpulse"
     *        "woocommerce-bg_BG.po" → "woocommerce"
     */
    private function extract_slug_from_filename($filename)
    {
        // Remove .po extension
        $base = pathinfo($filename, PATHINFO_FILENAME);
        // Strip locale suffix:  devpulse-bg_BG  →  devpulse
        $base = preg_replace('/-[a-z]{2,3}(?:_[A-Z]{2,3})?$/', '', $base);
        return sanitize_key($base);
    }
}
