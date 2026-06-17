# Loco AI Translator

Loco AI Translator is a powerful WordPress plugin that adds AI-powered translation capabilities to [Loco Translate](https://wordpress.org/plugins/loco-translate/). It allows you to automatically translate your plugin and theme string files (.po) using state-of-the-art AI models.

## Features

- **Seamless Loco Integration**: Adds a dedicated "🤖 AI Translate" button directly into the Loco Translate editor.
- **Multi-Provider Support**:
  - **OpenRouter**: Access hundreds of top-tier models like Claude 3.5, GPT-4o, Llama 3, and more.
  - **Ollama**: Run your translations locally for 100% privacy and zero cost.
- **Intelligent Batching**: Translates multiple strings in a single request for faster processing and lower token overhead.
- **Smart Retry Logic**: Automatically handles API rate limits or transient errors with exponential backoff.
- **Customizable Prompts**: Fine-tune how the AI translates your strings by editing the system prompt.
- **Token Usage Tracking**: Monitors your prompt and completion tokens for better cost management.
- **Safe & Progressive**: Only fills in untranslated strings, preserving your existing manual translations.

## Requirements

- **WordPress 6.0+**: The plugin requires WordPress version 6.0 or higher.
- **PHP 7.4+**: The plugin requires PHP version 7.4 or higher.
- **Loco Translate**: This plugin is an add-on and requires the Loco Translate plugin to be active.
- **API Key**: If using OpenRouter, you'll need a free or paid API key.
- **Ollama (Optional)**: If you want to use local models, you must have Ollama running on your server or local machine.

## Installation

1. Upload the `loco-ai-translator` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings > Loco AI Translator** to configure your provider and API key.

## Usage

1. Open any translation file in **Loco Translate > Plugins** or **Loco Translate > Themes**.
2. Click on the **Edit** link for the language you wish to translate.
3. Look for the "🤖 AI Translate" button in the editor toolbar.
4. Click it to start the automatic translation process.
5. Once finished, click **Save** in the Loco Translate editor.

## License

Loco AI Translator is released under the [GPL-2.0+](LICENSE.md).

## Author

Developed by [K2D](https://github.com/K2D).
