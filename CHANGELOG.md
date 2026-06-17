# Changelog — Loco AI Translator

All notable changes to this plugin are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.5.1] — 2026-06-18

### Fixed
- **Plural strings skipped** — Plural entries (with `msgid_plural`) were previously skipped or only partially translated because empty placeholder arrays `[0 => "", 1 => ""]` were incorrectly treated as already translated. Now they are correctly detected and translated.
- **Support for translating plural forms** — AI prompt and API client now request exactly the number of plural forms (`nplurals`) required by the target language. Applied translations are properly merged into `msgstr_plural`.

### Added
- **Performance Caching (Transient Cache)** — Added transient-based caching of the parsed PO entries array during the translation job. This avoids reading and parsing the entire PO file on every single batch request, reducing disk reads and CPU overhead to zero during the loop. Remaining counts are calculated in memory.

---

## [1.5.0] — 2026-03-19

### Fixed — Critical

- **Translation stops at ~50% of strings (main bug)**
  Root cause: `translate_file` re-parses the `.po` file on every request and calls
  `get_untranslated()`, which returns a shrinking array as strings are saved. But the
  batch offset was `batch_index * batch_sz` into this *shrinking* array, causing every
  other batch to be skipped:
  ```
  Batch 0: parse → 430 items.  slice(0×40, 40) = items[0–39]  ← translated ✓
  Batch 1: parse → 390 items.  slice(1×40, 40) = items[40–79] ← skips original[40–79]!
  Batch 2: parse → 350 items.  slice(2×40, 40) = items[80-119]← skips original[80–119]!
  ```
  Fix: **always `array_slice($untranslated, 0, $batch_sz)`** — index 0 is always the
  first untranslated entry since the file is re-parsed each time. `batch_index` is now
  display-only, never used as an offset.

- **Progress bar jumping (0% → 10% → 16%)** — previous percent was
  `(batch_index+1)*batch_sz / total` which overshoots on partial batches and recalculates
  against a shrinking `$total`. Now: server returns `remaining` (re-counted after save),
  and `percent = (totalOriginal - remaining) / totalOriginal * 100`. Smooth, accurate.

- **`flag_as_skipped()` prevents infinite loop on failed batches** — if a batch fails
  after all retries, entries are marked fuzzy so they leave the "untranslated" pool and
  the loop advances instead of retrying the same strings forever.

- **`fn()` arrow function replaced with `function()` closure** for PHP 7.4 compatibility
  in `apply_translations()`.

### Added

- **Token usage per batch** — `parse_openai_response()` now extracts `usage.prompt_tokens`,
  `usage.completion_tokens`, `usage.total_tokens` from the API response and returns them
  as `_usage` key. The AJAX handler strips it, accumulates totals, and sends per-batch
  counts back to JS.

- **Per-batch log table** — every completed batch appends a row showing:
  `#batch | N strings | time (ms/s) | tokens in/out | first 3 source strings`
  Newest batch on top, max 20 rows visible, scrollable.

- **Live summary strip** — a pill bar below the log shows running totals:
  `⏱ elapsed · ✅ translated · ⚠ skipped · 🔢 total tokens · 📦 batches`
  Updated after every batch. On completion, adds estimated cost and token breakdown.

- **ETA calculation** — progress area shows estimated remaining time based on
  strings-per-second rate of the current job, e.g. `ETA ~2m 14s`.

- **`total_original` sent from client** — JS reads the count from `lat_get_po_info` and
  sends it with every `translate_file` request so the server can calculate accurate
  cumulative percent without depending on the shrinking `remaining` count alone.

---

## [1.4.0] — 2026-03-19

### Fixed
- **Fatal error: `writeMo(): Argument #1 ($po) must be of type Loco_gettext_Data`** —
  Loco Translate's `Compiler::writeMo()` API changed in v2.6+: it now expects a
  `Loco_gettext_Data` object as the first argument, not a `Loco_fs_File`. Our code
  was passing a `Loco_fs_File` which caused a `TypeError` on every save.
  Fix: removed the Loco compiler call entirely. We now always use WordPress's own
  built-in `PO`/`MO` classes (`wp-includes/pomo/`) which are always available, never
  change their API, and produce identical `.mo` output.

### Added
- **⏹ Stop button (Задача 1)** — a red Stop button appears next to the Translate button
  as soon as a job starts. Clicking it:
  1. Aborts the in-flight XHR immediately (no more batches sent)
  2. Posts `lat_cancel_job` to set a server-side transient flag
  3. On page unload, sends a cancel beacon via `navigator.sendBeacon` (non-blocking,
     survives the page close)
  Progress made so far is always saved — partially translated files are never lost.

- **`lat_cancel_job` AJAX endpoint** — sets a `lat_cancel_{job_id}` transient for
  10 minutes. `translate_file` checks this flag at the start of every batch and returns
  `{ done: true, cancelled: true }` if it is set, preventing further processing.

- **Real-time string ticker (Задача 3)** — a ticker bar below the progress bar shows the
  first 3 source strings of the batch currently being sent to the LLM, e.g.:
  `Translating: Add to cart · Out of stock · My account`
  Updates after every batch response. This gives immediate visual feedback that work is
  happening, eliminating the "looks frozen" experience.

- **Improved progress display** — progress bar now shows percentage (`42%`) and string
  counter (`420 / 1 000 strings`) as separate styled elements, updating live.

- **Unload guard (Задача 4)** — while a job is running, navigating away or refreshing
  the page shows a browser confirmation dialog ("Translation is in progress. Are you sure
  you want to leave?"). The guard is removed automatically when the job finishes or is
  stopped. A `↻ Reload editor` button is shown in the completion notice so the user
  can reload safely after the job finishes.

- **`job_id` passed with every batch request** — a unique `job_id` is generated client-
  side at job start and sent with every `lat_translate_file` request. This ties each
  batch to the cancel transient and enables future per-job tracking.

---

## [1.3.0] — 2026-03-19

### Fixed
- **"File not found: devpulse-bg_BG.po"** — Loco Translate stores `.po` files in three
  different locations depending on which storage type the user chose when creating the
  translation. The old `validate_po_path()` only tried `ABSPATH` as the base for relative
  paths, so it always failed. The method is now a multi-location resolver that probes
  every candidate automatically.

### Changed
- `validate_po_path()` completely rewritten as a two-method pair:
  - `validate_po_path($raw)` — accepts absolute or relative input, tries all candidate
    base directories in order, returns the first path that resolves to a readable file
  - `verify_po_path($path)` — checks existence, readability, and security boundary
    (must be inside `wp-content` or `wp-plugins`)
- Resolution order for relative paths:
  1. `WP_CONTENT_DIR/` + relative  (covers Custom `languages/loco/plugins/` and System `languages/plugins/`)
  2. `WP_PLUGIN_DIR/` + relative   (covers Author `plugins/<slug>/languages/`)
  3. `ABSPATH/` + relative         (generic fallback)
  4. If only a bare filename is given: all six Loco sub-directories are probed
     (`languages/loco/plugins/`, `languages/loco/themes/`, `languages/plugins/`,
     `languages/themes/`, `<slug>/languages/` for plugins, themes equivalent)
- `get_loco_js_data()` in `class-lat-admin.php` now passes the **raw** `?path=` value
  directly to JS without pre-validating with `realpath()`. The AJAX handler resolves it,
  so symlinks and shared-hosting path quirks are handled server-side only once.
- Error message when resolution fails now states how many locations were checked.
- Slug extraction helper `extract_slug_from_filename()` added — strips locale suffix from
  filename to derive the plugin slug for Author-location probing.

---

## [1.2.0] — 2026-03-19

### Fixed
- **Critical: "No untranslated strings found" on files with untranslated content** — root
  cause was that the plugin fell back to DOM-scraping (Mode B) whenever `poPath` was
  empty, and Loco's virtual/paged editor only renders ~20 rows in the DOM at a time.
  The fix: DOM scraping is completely removed from the main translation flow. All
  translation is now server-side, reading the full `.po` file from disk.
- **`poPath` empty on many hosting setups** — PHP's `realpath()` was used to validate the
  path, but it fails silently on symlinked directories, some shared-hosting environments,
  and newly created files. Replaced with `file_exists()` + `wp_normalize_path()` +
  explicit `strpos(WP_CONTENT_DIR)` security check, which works reliably everywhere.
- **`lat_translate_strings` AJAX endpoint removed** — this endpoint was only used by the
  now-removed DOM-scraping mode and caused confusion. Replaced entirely by
  `lat_get_po_info` + `lat_translate_file`.

### Added
- **`lat_get_po_info` AJAX endpoint** — validates the `.po` path server-side, counts
  untranslated strings in the full file, and returns the detected locale. Called once
  before translation starts so the confirm dialog shows accurate numbers (e.g.
  "3 847 untranslated strings") regardless of how many rows Loco renders in the DOM.
- **Server-side retry logic per batch** — each batch is retried up to `max_retries`
  times (default 3) with exponential back-off (1 s, 2 s, 4 s…) before being skipped.
  Skipped batches are logged to the PHP error log and reported to the user at the end
  ("X translated, Y skipped after retries"). Translation never aborts mid-file.
- **Client-side network retry** — if `$.post` itself fails (timeout, 502, etc.), the same
  batch is automatically retried up to 2 times with a 3-second delay before the job stops.
- **Max Retries setting** — new field in Settings → Translation Behaviour (default: 3,
  range: 0–10).
- **Manual path input fallback** — if the `.po` path cannot be auto-detected (edge case:
  custom Loco configurations), a text input appears in the panel so the user can paste
  the absolute server path and verify it with a "Verify" button before translating.
- **Path info badge** — when auto-detection succeeds, the filename is shown next to the
  Translate button so the user can confirm they're translating the correct file.
- **Post-translation Reload button** — after completion, a "↻ Reload page" button
  appears so the user can immediately see the updated translations in the Loco editor.
- **Supports 10k+ string files** — batch size default raised from 20 to 40, max raised
  to 100. The server re-reads and re-parses the `.po` file on each batch request
  (stateless design), which keeps memory usage per request low regardless of file size.

### Changed
- Batch size default: 20 → 40, max: 50 → 100
- Settings page batch size description updated to reflect large-file guidance
- Path validation now uses `wp_normalize_path()` + `file_exists()` instead of `realpath()`
- `set_time_limit(300)` called in `translate_file` to prevent server timeouts on large files

---

## [1.1.0] — 2026-03-19

### Fixed
- **Critical: AI Translate panel not appearing in Loco editor** — the panel now reliably
  injects itself using a four-layer boot strategy:
  1. `$(document).ready()` — fires as soon as HTML is parsed
  2. `$(window).on('load')` — fires after Loco's own scripts have run
  3. Interval poller (every 500 ms, up to 20 attempts) — catches async Loco renders
  4. `MutationObserver` — instantly reacts the moment `.loco-toolbar` appears in the DOM
- **PHP 7.4 compatibility** — replaced all `str_starts_with()` calls (PHP 8.0+) with
  `strpos() === 0`, ensuring the plugin works on hosts still running PHP 7.4
- **Wrong hook names** — the `$loco_pages` array contained hook names that Loco never
  actually registers. Corrected to `loco-translate_page_loco-plugin` and
  `loco-translate_page_loco-theme`, plus added broad URL-param fallbacks so the script
  loads even on future Loco versions with renamed hooks

### Changed
- Enqueue condition is now triple-checked: WP hook name, `?page=loco*&action=file-edit`,
  and `?page=loco*&path=*` (file editor URLs always contain `?path=`)
- JS rewritten in ES5-compatible syntax for maximum browser/environment compatibility
- Anchor discovery now tries 14 selectors in priority order — toolbar → editor container
  → translation table → generic Loco wrap → last-resort `#wpbody-content`
- `MutationObserver` auto-disconnects after 30 seconds to avoid memory leaks

---

## [1.0.0] — 2026-03-18

### Added
- **Initial release** — MVP of Loco AI Translator

#### Core features
- Full `.po` file parsing and serialization (no external libraries)
- Automatic `.mo` compilation after save — uses Loco Translate's own compiler when
  available, falls back to WordPress's built-in `PO`/`MO` classes
- Batch translation — strings are grouped into configurable batches to stay within
  LLM context limits and show incremental progress

#### Provider support
- **OpenRouter** — access to 100+ models (GPT-4o, Claude 3, Mistral, Gemma, etc.)
  via a single API key; sends `HTTP-Referer` and `X-Title` headers as required
- **Ollama** — local LLM support via `/api/chat` endpoint, no API key needed
- **Custom** — any OpenAI-compatible endpoint (e.g. LM Studio, Together AI, Groq)

#### Settings page (`Settings → Loco AI Translator`)
- Provider selector with visual tab cards (OpenRouter / Ollama / Custom)
- API Endpoint field with one-click preset buttons
- API Key field (password input)
- Model field with **Load Models** button — dynamically fetches the model list from the
  provider and populates a searchable dropdown
- Temperature control (0–2, recommended 0.1–0.4 for translation)
- Batch Size control (1–50 strings per API call)
- Skip Translated toggle — skips strings that already have a translation
- Custom System Prompt — override the built-in translation prompt; supports
  `{target_lang}` placeholder; "View default prompt" preview button included
- **Test Connection** button — sends "Hello" and shows the translated result
- Plugin action link on the Plugins list page

#### Loco editor integration
- **AI Translate panel** injected directly below the Loco toolbar on file-edit pages
- Language dropdown with 60+ locales, auto-selected from the `.po` filename
  (e.g. `my-plugin-bg_BG.po` → Bulgarian pre-selected)
- Model badge showing active provider and model name
- Progress bar + string counter updated in real time
- When `.po` path is known (server-provided): translates the full file on disk,
  saves `.po`, and compiles `.mo` — no manual save needed
- When path is unknown (edge case): fills visible editor fields; user saves via Loco

#### Security
- All AJAX endpoints protected with nonces (`lat_nonce`)
- `manage_options` capability check on every AJAX handler
- `.po` path validated against `WP_CONTENT_DIR` to prevent path traversal
- All inputs sanitized; all outputs escaped
- `uninstall.php` cleans up `lat_settings` option on plugin deletion
