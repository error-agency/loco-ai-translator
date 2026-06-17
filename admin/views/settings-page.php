<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) return;

$settings = LAT_Settings::instance()->get();
$provider = $settings['provider'];
?>
<div class="wrap lat-settings-wrap">
    <h1 class="lat-page-title">
        <span class="lat-logo">🤖</span>
        <?php esc_html_e( 'Loco AI Translator', 'loco-ai-translator' ); ?>
        <span class="lat-version">v<?php echo esc_html( LAT_VERSION ); ?></span>
    </h1>

    <?php settings_errors( 'lat_settings_group' ); ?>

    <div class="lat-layout">

        <!-- ── MAIN SETTINGS ── -->
        <div class="lat-main-col">
            <form method="post" action="options.php" id="lat-settings-form">
                <?php settings_fields( 'lat_settings_group' ); ?>

                <!-- Provider Card -->
                <div class="lat-card">
                    <h2 class="lat-card-title">⚡ <?php esc_html_e( 'Provider', 'loco-ai-translator' ); ?></h2>

                    <div class="lat-provider-tabs">
                        <label class="lat-provider-tab <?php echo $provider === 'openrouter' ? 'active' : ''; ?>">
                            <input type="radio" name="lat_settings[provider]" value="openrouter"
                                <?php checked( $provider, 'openrouter' ); ?>>
                            <span class="lat-provider-icon">🌐</span>
                            <strong>OpenRouter</strong>
                            <small><?php esc_html_e( '100+ models', 'loco-ai-translator' ); ?></small>
                        </label>
                        <label class="lat-provider-tab <?php echo $provider === 'ollama' ? 'active' : ''; ?>">
                            <input type="radio" name="lat_settings[provider]" value="ollama"
                                <?php checked( $provider, 'ollama' ); ?>>
                            <span class="lat-provider-icon">🏠</span>
                            <strong>Ollama</strong>
                            <small><?php esc_html_e( 'Local LLM', 'loco-ai-translator' ); ?></small>
                        </label>
                        <label class="lat-provider-tab <?php echo $provider === 'custom' ? 'active' : ''; ?>">
                            <input type="radio" name="lat_settings[provider]" value="custom"
                                <?php checked( $provider, 'custom' ); ?>>
                            <span class="lat-provider-icon">🔧</span>
                            <strong><?php esc_html_e( 'Custom', 'loco-ai-translator' ); ?></strong>
                            <small><?php esc_html_e( 'Any OpenAI API', 'loco-ai-translator' ); ?></small>
                        </label>
                    </div>

                    <table class="form-table lat-form-table">
                        <tr>
                            <th><?php esc_html_e( 'API Endpoint', 'loco-ai-translator' ); ?></th>
                            <td>
                                <input type="url" name="lat_settings[api_endpoint]"
                                    value="<?php echo esc_attr( $settings['api_endpoint'] ); ?>"
                                    class="regular-text" id="lat-api-endpoint"
                                    placeholder="https://openrouter.ai/api/v1">
                                <div class="lat-presets">
                                    <button type="button" class="button button-small lat-preset"
                                        data-value="https://openrouter.ai/api/v1">
                                        OpenRouter
                                    </button>
                                    <button type="button" class="button button-small lat-preset"
                                        data-value="http://localhost:11434">
                                        Ollama local
                                    </button>
                                    <button type="button" class="button button-small lat-preset"
                                        data-value="https://api.openai.com/v1">
                                        OpenAI
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr class="lat-row-apikey" <?php echo $provider === 'ollama' ? 'style="display:none"' : ''; ?>>
                            <th><?php esc_html_e( 'API Key', 'loco-ai-translator' ); ?></th>
                            <td>
                                <input type="password" name="lat_settings[api_key]"
                                    value="<?php echo esc_attr( $settings['api_key'] ); ?>"
                                    class="regular-text" autocomplete="new-password">
                                <p class="description">
                                    <?php esc_html_e( 'Leave empty for Ollama (no key required).', 'loco-ai-translator' ); ?>
                                    <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">
                                        <?php esc_html_e( 'Get OpenRouter key ↗', 'loco-ai-translator' ); ?>
                                    </a>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Model Card -->
                <div class="lat-card">
                    <h2 class="lat-card-title">🧠 <?php esc_html_e( 'Model', 'loco-ai-translator' ); ?></h2>

                    <table class="form-table lat-form-table">
                        <tr>
                            <th><?php esc_html_e( 'Model ID', 'loco-ai-translator' ); ?></th>
                            <td>
                                <div class="lat-model-row">
                                    <input type="text" name="lat_settings[model]" id="lat-model-input"
                                        value="<?php echo esc_attr( $settings['model'] ); ?>"
                                        class="regular-text"
                                        placeholder="openai/gpt-4o-mini">
                                    <button type="button" id="lat-fetch-models" class="button">
                                        <?php esc_html_e( '↻ Load Models', 'loco-ai-translator' ); ?>
                                    </button>
                                </div>
                                <select id="lat-model-select" style="display:none; margin-top:8px; width:100%; max-width:500px;">
                                    <option value=""><?php esc_html_e( '— choose a model —', 'loco-ai-translator' ); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'Type directly or load models from the provider. For OpenRouter: openai/gpt-4o-mini, anthropic/claude-3-haiku, etc.', 'loco-ai-translator' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Temperature', 'loco-ai-translator' ); ?></th>
                            <td>
                                <input type="number" name="lat_settings[temperature]"
                                    value="<?php echo esc_attr( $settings['temperature'] ); ?>"
                                    min="0" max="2" step="0.1" class="small-text">
                                <p class="description">
                                    <?php esc_html_e( '0 = deterministic, 1 = creative. Recommended: 0.1–0.4 for translations.', 'loco-ai-translator' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Batch Size', 'loco-ai-translator' ); ?></th>
                            <td>
                                <input type="number" name="lat_settings[batch_size]"
                                    value="<?php echo esc_attr( $settings['batch_size'] ); ?>"
                                    min="5" max="100" class="small-text">
                                <p class="description">
                                    <?php esc_html_e( 'Strings per API call. Default: 40. Range: 5–100. For large files (10k+ strings) use 40–60 for best balance of speed and reliability.', 'loco-ai-translator' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Max Retries', 'loco-ai-translator' ); ?></th>
                            <td>
                                <input type="number" name="lat_settings[max_retries]"
                                    value="<?php echo esc_attr( $settings['max_retries'] ?? 3 ); ?>"
                                    min="0" max="10" class="small-text">
                                <p class="description">
                                    <?php esc_html_e( 'If a batch fails (API error, timeout, bad JSON), retry this many times before skipping those strings and continuing. Default: 3. Set to 0 to disable retries.', 'loco-ai-translator' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Translation Behaviour -->
                <div class="lat-card">
                    <h2 class="lat-card-title">⚙️ <?php esc_html_e( 'Translation Behaviour', 'loco-ai-translator' ); ?></h2>

                    <table class="form-table lat-form-table">
                        <tr>
                            <th><?php esc_html_e( 'Skip Translated', 'loco-ai-translator' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="lat_settings[skip_translated]" value="1"
                                        <?php checked( $settings['skip_translated'], 1 ); ?>>
                                    <?php esc_html_e( 'Skip strings that already have a translation (recommended)', 'loco-ai-translator' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'System Prompt', 'loco-ai-translator' ); ?></th>
                            <td>
                                <textarea name="lat_settings[system_prompt]" rows="6"
                                    class="large-text" placeholder="<?php esc_attr_e( 'Leave blank to use the built-in translation prompt.', 'loco-ai-translator' ); ?>"
                                ><?php echo esc_textarea( $settings['system_prompt'] ); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e( 'Override the default prompt. Use {target_lang} for the language placeholder. The AI must return a JSON array.', 'loco-ai-translator' ); ?>
                                </p>
                                <button type="button" id="lat-show-default-prompt" class="button button-small">
                                    <?php esc_html_e( 'View default prompt', 'loco-ai-translator' ); ?>
                                </button>
                                <pre id="lat-default-prompt-preview" style="display:none; background:#f6f7f7; padding:12px; border-radius:4px; white-space:pre-wrap; font-size:12px;"><?php echo esc_html( LAT_Settings::default_system_prompt( '{target_lang}' ) ); ?></pre>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="lat-actions">
                    <?php submit_button( __( 'Save Settings', 'loco-ai-translator' ), 'primary large', 'submit', false ); ?>
                    <button type="button" id="lat-test-connection" class="button button-large">
                        🔌 <?php esc_html_e( 'Test Connection', 'loco-ai-translator' ); ?>
                    </button>
                    <span id="lat-test-result" class="lat-test-result"></span>
                </div>

            </form>
        </div>

        <!-- ── SIDEBAR ── -->
        <div class="lat-sidebar">

                <!-- How to Use Card -->
            <div class="lat-card lat-sidebar-card lat-card-info">
                <h2 class="lat-card-title">💡 <?php esc_html_e( 'How to Use in Loco', 'loco-ai-translator' ); ?></h2>
                <ol class="lat-how-to">
                    <li><?php esc_html_e( 'Go to Loco Translate → Plugins or Themes', 'loco-ai-translator' ); ?></li>
                    <li><?php esc_html_e( 'Click Edit on a translation file', 'loco-ai-translator' ); ?></li>
                    <li><?php esc_html_e( 'Click the blue "🤖 AI Translate" button in the toolbar', 'loco-ai-translator' ); ?></li>
                    <li><?php esc_html_e( 'Review the AI translations and press Save', 'loco-ai-translator' ); ?></li>
                </ol>
            </div>

        </div><!-- /.lat-sidebar -->

    </div><!-- /.lat-layout -->
</div>
