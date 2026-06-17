<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LAT_Api_Client {

    private $settings;

    public function __construct() {
        $this->settings = LAT_Settings::instance();
    }

    /**
     * Translate a batch of strings.
     *
     * @param  array  $strings     Array of source strings to translate.
     * @param  string $target_lang Target language name, e.g. "Bulgarian".
     * @return array|WP_Error      Translated strings array or WP_Error.
     */
    public function translate_batch( array $strings, string $target_lang ) {
        if ( empty( $strings ) ) {
            return [];
        }

        $provider = $this->settings->get( 'provider' );

        switch ( $provider ) {
            case 'ollama':
                return $this->call_ollama( $strings, $target_lang );
            case 'openrouter':
            default:
                return $this->call_openai_compatible( $strings, $target_lang );
        }
    }

    /**
     * OpenRouter / any OpenAI-compatible endpoint.
     */
    private function call_openai_compatible( array $strings, string $target_lang ) {
        $endpoint   = rtrim( $this->settings->get( 'api_endpoint' ), '/' ) . '/chat/completions';
        $api_key    = $this->settings->get( 'api_key' );
        $model      = $this->settings->get( 'model' );
        $temp       = $this->settings->get( 'temperature' );
        $sys_prompt = $this->settings->get( 'system_prompt' );

        if ( empty( $sys_prompt ) ) {
            $sys_prompt = LAT_Settings::default_system_prompt( $target_lang );
        }

        $user_content = 'Translate these strings to ' . $target_lang . ":\n" .
                        wp_json_encode( array_values( $strings ), JSON_UNESCAPED_UNICODE );

        $body = wp_json_encode( [
            'model'       => $model,
            'temperature' => (float) $temp,
            'messages'    => [
                [ 'role' => 'system', 'content' => $sys_prompt ],
                [ 'role' => 'user',   'content' => $user_content ],
            ],
        ] );

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];

        // OpenRouter specific headers (ignored by other providers)
        if ( strpos( $this->settings->get( 'api_endpoint' ), 'openrouter' ) !== false ) {
            $headers['HTTP-Referer'] = home_url();
            $headers['X-Title']      = get_bloginfo( 'name' );
        }

        $response = wp_remote_post( $endpoint, [
            'timeout' => 120,
            'headers' => $headers,
            'body'    => $body,
        ] );

        return $this->parse_openai_response( $response, count( $strings ) );
    }

    /**
     * Ollama (local) — uses /api/chat endpoint.
     */
    private function call_ollama( array $strings, string $target_lang ) {
        $endpoint   = rtrim( $this->settings->get( 'api_endpoint' ), '/' ) . '/api/chat';
        $model      = $this->settings->get( 'model' );
        $temp       = $this->settings->get( 'temperature' );
        $sys_prompt = $this->settings->get( 'system_prompt' );

        if ( empty( $sys_prompt ) ) {
            $sys_prompt = LAT_Settings::default_system_prompt( $target_lang );
        }

        $user_content = 'Translate these strings to ' . $target_lang . ":\n" .
                        wp_json_encode( array_values( $strings ), JSON_UNESCAPED_UNICODE );

        $body = wp_json_encode( [
            'model'  => $model,
            'stream' => false,
            'options' => [ 'temperature' => (float) $temp ],
            'messages' => [
                [ 'role' => 'system', 'content' => $sys_prompt ],
                [ 'role' => 'user',   'content' => $user_content ],
            ],
        ] );

        $response = wp_remote_post( $endpoint, [
            'timeout' => 180,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code !== 200 || empty( $data['message']['content'] ) ) {
            return new WP_Error( 'ollama_error', 'Ollama API error: ' . $raw );
        }

        return $this->extract_json_array( $data['message']['content'], count( $strings ) );
    }

    /**
     * Parse OpenAI-compatible response.
     * Returns translated strings array with an extra '_usage' key for token counts.
     */
    private function parse_openai_response( $response, int $expected_count ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? $raw;
            return new WP_Error( 'api_error', 'API Error ' . $code . ': ' . $msg );
        }

        $content = $data['choices'][0]['message']['content'] ?? '';

        if ( empty( $content ) ) {
            return new WP_Error( 'empty_response', 'The AI returned an empty response.' );
        }

        $translations = $this->extract_json_array( $content, $expected_count );
        if ( is_wp_error( $translations ) ) {
            return $translations;
        }

        // Attach token usage so the AJAX handler can surface it to the UI
        $usage = $data['usage'] ?? [];
        $translations['_usage'] = [
            'prompt'     => (int) ( $usage['prompt_tokens']     ?? 0 ),
            'completion' => (int) ( $usage['completion_tokens'] ?? 0 ),
            'total'      => (int) ( $usage['total_tokens']      ?? 0 ),
        ];

        return $translations;
    }

    /**
     * Extract a JSON array from model output.
     * Handles markdown code fences and stray text.
     */
    private function extract_json_array( string $content, int $expected_count ) {
        // Strip markdown code fences
        $content = preg_replace( '/^```(?:json)?\s*/m', '', $content );
        $content = preg_replace( '/\s*```$/m', '', $content );
        $content = trim( $content );

        // Find the first [ ... ] block
        $start = strpos( $content, '[' );
        $end   = strrpos( $content, ']' );

        if ( $start === false || $end === false ) {
            return new WP_Error( 'parse_error', 'Could not find a JSON array in AI response. Raw: ' . substr( $content, 0, 200 ) );
        }

        $json_str     = substr( $content, $start, $end - $start + 1 );
        $translations = json_decode( $json_str, true );

        if ( ! is_array( $translations ) ) {
            return new WP_Error( 'parse_error', 'AI response is not a valid JSON array. Raw: ' . substr( $content, 0, 200 ) );
        }

        if ( count( $translations ) !== $expected_count ) {
            // Log mismatch but still return what we got — partial is better than nothing
            error_log( sprintf(
                '[LAT] Translation count mismatch: expected %d, got %d',
                $expected_count,
                count( $translations )
            ) );
        }

        return array_values( $translations );
    }

    /**
     * Fetch available models from the endpoint.
     * Works for OpenRouter (/models) and Ollama (/api/tags).
     */
    public function fetch_models() {
        $provider = $this->settings->get( 'provider' );
        $base     = rtrim( $this->settings->get( 'api_endpoint' ), '/' );
        $api_key  = $this->settings->get( 'api_key' );

        if ( $provider === 'ollama' ) {
            $url = $base . '/api/tags';
            $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

            if ( is_wp_error( $response ) ) return $response;

            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            $models = [];
            foreach ( ( $data['models'] ?? [] ) as $m ) {
                $models[] = [ 'id' => $m['name'], 'name' => $m['name'] ];
            }
            return $models;
        }

        // OpenRouter / OpenAI compatible
        $url = $base . '/models';
        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $data   = json_decode( wp_remote_retrieve_body( $response ), true );
        $raw    = $data['data'] ?? [];
        $models = [];

        foreach ( $raw as $m ) {
            $id = $m['id'] ?? '';
            if ( empty( $id ) ) continue;
            // Filter to text/chat-capable models only (OpenRouter specific)
            $arch = $m['architecture']['modality'] ?? '';
            if ( $arch && ! in_array( $arch, [ 'text->text', 'text+image->text', '' ] ) ) continue;
            $models[] = [
                'id'          => $id,
                'name'        => $m['name'] ?? $id,
                'context'     => $m['context_length'] ?? null,
                'pricing_in'  => $m['pricing']['prompt'] ?? null,
                'pricing_out' => $m['pricing']['completion'] ?? null,
            ];
        }

        // Sort by id
        usort( $models, fn( $a, $b ) => strcmp( $a['id'], $b['id'] ) );

        return $models;
    }
}
