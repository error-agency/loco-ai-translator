/* global latLoco, jQuery */
(function ($) {
    'use strict';

    /* ═══════════════════════════════════════════════════════════════════
       LOCALE MAP
    ═══════════════════════════════════════════════════════════════════ */
    var LOCALE_MAP = {
        'af':'Afrikaans','ar':'Arabic','az':'Azerbaijani','be_BY':'Belarusian',
        'bg_BG':'Bulgarian','bn_BD':'Bengali','bs_BA':'Bosnian','ca':'Catalan',
        'cs_CZ':'Czech','cy':'Welsh','da_DK':'Danish','de_DE':'German',
        'de_AT':'German (Austria)','de_CH':'German (Switzerland)','el':'Greek',
        'eo':'Esperanto','es_ES':'Spanish','es_AR':'Spanish (Argentina)',
        'es_MX':'Spanish (Mexico)','et':'Estonian','eu':'Basque','fa_IR':'Persian',
        'fi':'Finnish','fr_FR':'French','fr_BE':'French (Belgium)',
        'fr_CA':'French (Canada)','gl_ES':'Galician','gu':'Gujarati',
        'he_IL':'Hebrew','hi_IN':'Hindi','hr':'Croatian','hu_HU':'Hungarian',
        'hy':'Armenian','id_ID':'Indonesian','is_IS':'Icelandic','it_IT':'Italian',
        'ja':'Japanese','ka_GE':'Georgian','kk':'Kazakh','km':'Khmer',
        'ko_KR':'Korean','lt_LT':'Lithuanian','lv':'Latvian','mk_MK':'Macedonian',
        'ml_IN':'Malayalam','mn':'Mongolian','mr':'Marathi','ms_MY':'Malay',
        'my_MM':'Burmese','nb_NO':'Norwegian','nl_NL':'Dutch','nl_BE':'Dutch (Belgium)',
        'nn_NO':'Norwegian (Nynorsk)','pa_IN':'Punjabi','pl_PL':'Polish',
        'pt_BR':'Portuguese (Brazil)','pt_PT':'Portuguese','ro_RO':'Romanian',
        'ru_RU':'Russian','sk_SK':'Slovak','sl_SI':'Slovenian','sq':'Albanian',
        'sr_RS':'Serbian','sv_SE':'Swedish','sw':'Swahili','ta_IN':'Tamil',
        'te':'Telugu','th':'Thai','tl':'Filipino','tr_TR':'Turkish',
        'uk':'Ukrainian','ur':'Urdu','uz_UZ':'Uzbek','vi':'Vietnamese',
        'zh_CN':'Chinese (Simplified)','zh_TW':'Chinese (Traditional)',
        'zh_HK':'Chinese (Hong Kong)',
    };

    function localeToName(code) {
        if (!code) return '';
        if (LOCALE_MAP[code]) return LOCALE_MAP[code];
        var keys = Object.keys(LOCALE_MAP);
        for (var i = 0; i < keys.length; i++) {
            if (keys[i].split('_')[0] === code) return LOCALE_MAP[keys[i]];
        }
        return code;
    }

    /* ═══════════════════════════════════════════════════════════════════
       PATH DETECTION
    ═══════════════════════════════════════════════════════════════════ */
    function detectPoPath() {
        if (latLoco.poPath && latLoco.poPath.length > 4) return latLoco.poPath;
        var selectors = ['input[name="path"]','input[name="po-path"]',
                         'input[name="file"]','form[data-path]','[data-path]'];
        for (var i = 0; i < selectors.length; i++) {
            var $el = $(selectors[i]).first();
            var v   = $el.val ? $el.val() : $el.attr('data-path');
            if (v && v.indexOf('.po') !== -1) return v;
        }
        var urlPath = new URLSearchParams(window.location.search).get('path') || '';
        if (urlPath && urlPath.indexOf('.po') !== -1) return urlPath;
        return '';
    }

    function detectLocale(poPath) {
        if (latLoco.detectedLocale) return latLoco.detectedLocale;
        var src = [poPath, window.location.search, document.title,
                   $('h1,h2,.loco-nav,.loco-title,.loco-lang').text()].join(' ');
        var m = src.match(/[-_]([a-z]{2,3}_[A-Z]{2,3})(?:\.po)?/);
        return m ? m[1] : '';
    }

    /* ═══════════════════════════════════════════════════════════════════
       STATE
    ═══════════════════════════════════════════════════════════════════ */
    var running         = false;
    var currentJobId    = '';
    var cancelPending   = false;
    var _xhrRef         = null;

    // Cumulative stats across all batches in this job
    var stats = {
        totalOriginal    : 0,
        translated       : 0,
        skipped          : 0,
        batchCount       : 0,
        tokensPrompt     : 0,
        tokensCompletion : 0,
        tokensTotal      : 0,
        jobStartMs       : 0,
        batchLog         : [],   // per-batch records for the log table
    };

    /* ═══════════════════════════════════════════════════════════════════
       UNLOAD GUARD
    ═══════════════════════════════════════════════════════════════════ */
    function installUnloadGuard() {
        $(window).on('beforeunload.lat', function () {
            if (!running) return;
            if (currentJobId && navigator.sendBeacon) {
                var fd = new FormData();
                fd.append('action',  'lat_cancel_job');
                fd.append('nonce',   latLoco.nonce);
                fd.append('job_id',  currentJobId);
                navigator.sendBeacon(latLoco.ajaxUrl, fd);
            }
            return 'Translation is in progress. Are you sure you want to leave?';
        });
    }
    function removeUnloadGuard() { $(window).off('beforeunload.lat'); }

    /* ═══════════════════════════════════════════════════════════════════
       PANEL INJECTION
    ═══════════════════════════════════════════════════════════════════ */
    var injected = false;

    function tryInject() {
        if (injected || $('#lat-panel').length) { injected = true; return true; }
        var anchors = [
            '.loco-toolbar','#loco-toolbar','div.loco-toolbar','nav.loco-toolbar',
            '[class*="loco-toolbar"]','#loco-editor','.loco-editor',
            'table#loco-entries','table.loco-table','.loco-wrap > form','.loco-wrap',
            '#wpbody-content .wrap > h2','#wpbody-content .wrap',
        ];
        var $anchor = null;
        for (var i = 0; i < anchors.length; i++) {
            var $el = $(anchors[i]).first();
            if ($el.length) { $anchor = $el; break; }
        }
        if (!$anchor) return false;

        var $panel = buildPanel();
        var tag    = ($anchor.prop('tagName') || '').toLowerCase();
        if (tag === 'table' || $anchor.attr('id') === 'loco-editor' || $anchor.hasClass('loco-editor')) {
            $anchor.before($panel);
        } else {
            $anchor.after($panel);
        }
        injected = true;
        wirePanel();
        return true;
    }

    $(document).ready(function () { tryInject(); });
    $(window).on('load', function () { tryInject(); });
    var _attempts = 0;
    var _poller = setInterval(function () {
        _attempts++;
        if (tryInject() || _attempts >= 20) clearInterval(_poller);
    }, 500);
    if (typeof MutationObserver !== 'undefined') {
        var _mo = new MutationObserver(function () { if (tryInject()) _mo.disconnect(); });
        _mo.observe(document.body, { childList: true, subtree: true });
        setTimeout(function () { _mo.disconnect(); }, 30000);
    }

    /* ═══════════════════════════════════════════════════════════════════
       BUILD PANEL
    ═══════════════════════════════════════════════════════════════════ */
    function buildPanel() {
        var poPath   = detectPoPath();
        var locale   = detectLocale(poPath);
        var langName = localeToName(locale);

        // Language select
        var $langSelect = $('<select>', { id: 'lat-lang-select', class: 'lat-lang-select' });
        var entries = [];
        for (var code in LOCALE_MAP) { entries.push([code, LOCALE_MAP[code]]); }
        entries.sort(function (a, b) { return a[1].localeCompare(b[1]); });
        entries.forEach(function (pair) {
            var $opt = $('<option>', { value: pair[1], text: pair[1] + ' (' + pair[0] + ')' });
            if (pair[0] === locale || pair[1] === langName) $opt.prop('selected', true);
            $langSelect.append($opt);
        });
        if (!$langSelect.val()) $langSelect.val('Bulgarian');

        // Buttons
        var $btn = $('<button>', {
            id: 'lat-ai-btn', type: 'button',
            class: 'button button-primary lat-ai-btn',
            html: latLoco.i18n.btnTranslate,
        });
        var $stopBtn = $('<button>', {
            id: 'lat-stop-btn', type: 'button',
            class: 'button lat-stop-btn', html: '⏹ Stop',
        }).hide();

        // Badges
        var $badge    = $('<span>', { class: 'lat-model-badge',
            text: latLoco.provider + ' · ' + latLoco.model });
        var $pathInfo = poPath ? $('<span>', {
            class: 'lat-path-info', text: basename(poPath), title: poPath }) : null;

        // Manual path row (only shown if auto-detect fails)
        var $pathRow = $('<div>', { id: 'lat-path-row', class: 'lat-path-row' }).hide();
        if (!poPath) {
            $pathRow.append(
                $('<span>', { text: '📂 Enter .po path: ' }),
                $('<input>', { type:'text', id:'lat-manual-path',
                    class:'regular-text lat-manual-path',
                    placeholder:'Absolute path or relative to wp-content…' }),
                ' ',
                $('<button>', { type:'button', class:'button lat-path-verify-btn', text:'Verify' }),
                $('<span>', { id:'lat-path-verify-result', style:'margin-left:8px;font-size:12px;' })
            ).show();
        }

        // ── Progress area ─────────────────────────────────────────────────────
        var $fill    = $('<div>', { id:'lat-progress-fill', class:'lat-progress-bar-fill' });
        var $pct     = $('<span>', { id:'lat-progress-pct', class:'lat-progress-pct', text:'0%' });
        var $cnt     = $('<span>', { id:'lat-progress-cnt', class:'lat-progress-cnt' });
        var $eta     = $('<span>', { id:'lat-progress-eta', class:'lat-progress-eta' });
        var $prog    = $('<div>', { id:'lat-progress-wrap', class:'lat-editor-progress' })
            .append($('<div>', { class:'lat-progress-bar-track' }).append($fill))
            .append($pct, $cnt, $eta)
            .hide();

        // ── Current batch ticker ──────────────────────────────────────────────
        var $ticker = $('<div>', { id:'lat-ticker', class:'lat-ticker' }).hide();

        // ── Per-batch log table ───────────────────────────────────────────────
        var $log = $('<div>', { id:'lat-batch-log', class:'lat-batch-log' }).hide().append(
            $('<div>', { class:'lat-log-header' }).append(
                $('<span>', { text:'Batch' }),
                $('<span>', { text:'Strings' }),
                $('<span>', { text:'Time' }),
                $('<span>', { text:'Tokens (in/out)' }),
                $('<span>', { text:'Preview' })
            ),
            $('<div>', { id:'lat-log-rows', class:'lat-log-rows' })
        );

        // ── Summary stats bar ─────────────────────────────────────────────────
        var $summary = $('<div>', { id:'lat-summary', class:'lat-summary' }).hide();

        // ── Notices ───────────────────────────────────────────────────────────
        var $notices = $('<div>', { id:'lat-editor-notices' });

        return $('<div>', { id:'lat-panel', class:'lat-editor-panel' }).append(
            $('<div>', { class:'lat-panel-controls' }).append(
                $('<span>', { class:'lat-panel-label', text:'🌍 Translate to:' }),
                $langSelect, $btn, $stopBtn, $badge, $pathInfo
            ),
            $pathRow, $prog, $ticker, $log, $summary, $notices
        );
    }

    function basename(p) { return p ? p.replace(/\\/g,'/').split('/').pop() : ''; }

    /* ═══════════════════════════════════════════════════════════════════
       WIRE EVENTS
    ═══════════════════════════════════════════════════════════════════ */
    function wirePanel() {
        $(document).on('click', '.lat-path-verify-btn', function () {
            var path = $('#lat-manual-path').val().trim();
            var $res = $('#lat-path-verify-result');
            if (!path) return;
            $res.text('Checking…').css('color','#787878');
            $.post(latLoco.ajaxUrl, {
                action:'lat_get_po_info', nonce:latLoco.nonce, po_path:path,
            }, function (res) {
                if (res.success) {
                    $res.text('✓ ' + res.data.untranslated + ' untranslated').css('color','#00a32a');
                } else {
                    $res.text('✗ ' + (res.data ? res.data.message : 'Error')).css('color','#d63638');
                }
            }).fail(function () { $res.text('Network error').css('color','#d63638'); });
        });

        $('#lat-ai-btn').on('click', function () {
            if (running) return;
            var poPath = detectPoPath() || $('#lat-manual-path').val().trim();
            startTranslation(poPath, $('#lat-lang-select').val());
        });

        $('#lat-stop-btn').on('click', function () {
            if (!running || !currentJobId) return;
            cancelPending = true;
            $(this).prop('disabled', true).text('Stopping…');
            if (_xhrRef) { _xhrRef.abort(); _xhrRef = null; }
            $.post(latLoco.ajaxUrl, {
                action:'lat_cancel_job', nonce:latLoco.nonce, job_id:currentJobId,
            });
            showNotice('⏸ Stop signal sent — current batch will finish, then stop.', 'info');
        });
    }

    /* ═══════════════════════════════════════════════════════════════════
       TRANSLATION FLOW
    ═══════════════════════════════════════════════════════════════════ */
    function generateJobId() {
        return 'lat_' + Date.now() + '_' + Math.floor(Math.random() * 9999);
    }

    function startTranslation(poPath, targetLang) {
        if (!poPath) {
            showNotice('⚠ Could not detect the .po file path. Enter it manually above.', 'error', true);
            $('#lat-path-row').show();
            return;
        }
        showNotice('🔍 Checking file…', 'info');
        $('#lat-ai-btn').prop('disabled', true);

        $.post(latLoco.ajaxUrl, {
            action:'lat_get_po_info', nonce:latLoco.nonce, po_path:poPath,
        }, function (res) {
            $('#lat-ai-btn').prop('disabled', false);
            clearNotice();

            if (!res.success) {
                showNotice('✗ ' + (res.data ? res.data.message : 'Unknown'), 'error', true);
                return;
            }

            var info = res.data;
            if (info.untranslated === 0) {
                showNotice('✓ All strings are already translated.', 'success');
                return;
            }

            if (!confirm(
                'Translate ' + info.untranslated + ' untranslated string' +
                (info.untranslated !== 1 ? 's' : '') +
                ' out of ' + info.total_entries + ' total?\n\n' +
                'Language : ' + targetLang + '\n' +
                'Model    : ' + latLoco.model + '\n' +
                'File     : ' + basename(poPath) + '\n\n' +
                'The file is saved after each batch.'
            )) return;

            // Reset stats
            stats = {
                totalOriginal    : info.untranslated,
                translated       : 0,
                skipped          : 0,
                batchCount       : 0,
                tokensPrompt     : 0,
                tokensCompletion : 0,
                tokensTotal      : 0,
                jobStartMs       : Date.now(),
                batchLog         : [],
            };

            running       = true;
            cancelPending = false;
            currentJobId  = generateJobId();

            setUiRunning(true);
            updateProgress(0, 0, info.untranslated);
            $('#lat-batch-log').show();
            $('#lat-summary').hide().empty();
            installUnloadGuard();

            doBatch(poPath, targetLang, info.untranslated);

        }).fail(function () {
            $('#lat-ai-btn').prop('disabled', false);
            showNotice('✗ Network error while checking file.', 'error', true);
        });
    }

    // ── Batch loop ───────────────────────────────────────────────────────────

    function doBatch(poPath, targetLang, totalOriginal) {
        if (cancelPending) { finishJob(false); return; }

        var batchStartMs = Date.now();

        _xhrRef = $.post(latLoco.ajaxUrl, {
            action          : 'lat_translate_file',
            nonce           : latLoco.nonce,
            po_path         : poPath,
            target_lang     : targetLang,
            batch_index     : stats.batchCount,   // for display only — not used as offset
            total_original  : totalOriginal,
            job_id          : currentJobId,
        }, function (res) {
            _xhrRef = null;
            var batchMs = Date.now() - batchStartMs;

            if (!res.success) {
                running = false;
                setUiRunning(false);
                removeUnloadGuard();
                showNotice('✗ Error: ' + (res.data ? res.data.message : 'Unknown'), 'error', true);
                return;
            }

            var d = res.data;

            // Accumulate stats
            stats.batchCount++;
            stats.translated       = d.translated  || 0;
            stats.skipped         += (d.skipped     || 0);
            stats.tokensPrompt    += (d.tokens_prompt     || 0);
            stats.tokensCompletion+= (d.tokens_completion || 0);
            stats.tokensTotal     += (d.tokens_total      || 0);

            // Update UI
            updateProgress(d.percent, d.translated, d.total);
            updateTicker(d.batch_preview || []);
            addBatchLogRow(
                stats.batchCount,
                d.batch_count   || 0,
                batchMs,
                d.tokens_prompt || 0,
                d.tokens_completion || 0,
                d.batch_preview || []
            );
            updateSummaryLive();

            if (d.cancelled) { finishJob(false); return; }
            if (d.done)      { finishJob(true);  return; }

            // Continue to next batch — no delay needed, server has already saved
            doBatch(poPath, targetLang, totalOriginal);

        }).fail(function (xhr) {
            _xhrRef = null;
            if (xhr.statusText === 'abort') { finishJob(false); return; }

            // Network-level retry
            if (!doBatch._netRetries) doBatch._netRetries = {};
            var key = stats.batchCount;
            doBatch._netRetries[key] = (doBatch._netRetries[key] || 0) + 1;

            if (doBatch._netRetries[key] <= 2) {
                showNotice('⚠ Network error — retrying in 3 s…', 'warning');
                setTimeout(function () {
                    doBatch(poPath, targetLang, totalOriginal);
                }, 3000);
            } else {
                running = false;
                setUiRunning(false);
                removeUnloadGuard();
                showNotice('✗ Network error after retries — translation stopped.', 'error', true);
            }
        });
    }

    function finishJob(completed) {
        running = false;
        setUiRunning(false);
        removeUnloadGuard();
        hideTicker();

        var elapsed = Math.round((Date.now() - stats.jobStartMs) / 1000);
        var elStr   = elapsed >= 60
            ? Math.floor(elapsed/60) + 'm ' + (elapsed%60) + 's'
            : elapsed + 's';

        var msg;
        if (!completed) {
            msg = '⏹ Stopped. <strong>' + stats.translated + ' strings</strong> saved so far.';
        } else {
            msg = '✓ Done! <strong>' + stats.translated + ' string' +
                  (stats.translated !== 1 ? 's' : '') + '</strong> translated';
            if (stats.skipped > 0) msg += ', <strong>' + stats.skipped + ' skipped</strong>';
            msg += ' in ' + elStr + '.';
        }

        showNotice(msg, completed ? 'success' : 'warning', true);
        showFinalSummary(completed, elStr);

        // Reload button
        var $r = $('<button>', {
            type:'button', class:'button button-small lat-reload-btn', text:'↻ Reload editor',
        }).on('click', function () { removeUnloadGuard(); window.location.reload(); });
        $('#lat-editor-notices .lat-editor-notice').append(' ', $r);
    }

    /* ═══════════════════════════════════════════════════════════════════
       UI — PROGRESS
    ═══════════════════════════════════════════════════════════════════ */
    function updateProgress(pct, done, total) {
        if (pct !== null && pct !== undefined) {
            $('#lat-progress-fill').css('width', pct + '%');
            $('#lat-progress-pct').text(pct + '%');
        }
        if (done !== undefined && total !== undefined) {
            $('#lat-progress-cnt').text(' — ' + done + ' / ' + total + ' strings');
        }
        // ETA
        if (done > 0 && total > 0 && stats.jobStartMs) {
            var elapsed  = (Date.now() - stats.jobStartMs) / 1000;
            var rate     = done / elapsed;           // strings per second
            var remaining = total - done;
            var etaSec   = rate > 0 ? Math.round(remaining / rate) : 0;
            var etaStr   = etaSec > 60
                ? 'ETA ~' + Math.floor(etaSec/60) + 'm ' + (etaSec%60) + 's'
                : (etaSec > 0 ? 'ETA ~' + etaSec + 's' : '');
            $('#lat-progress-eta').text(etaStr ? ' · ' + etaStr : '');
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       UI — TICKER (currently translating)
    ═══════════════════════════════════════════════════════════════════ */
    function updateTicker(strings) {
        if (!strings || !strings.length) return;
        var $t = $('#lat-ticker');
        var parts = strings.map(function (s) {
            var short = s.length > 55 ? s.substring(0, 55) + '…' : s;
            return '<span class="lat-ticker-item">' + escHtml(short) + '</span>';
        });
        $t.html(
            '<span class="lat-ticker-label">Translating:</span> ' +
            parts.join('<span class="lat-ticker-sep"> · </span>')
        ).show();
    }

    function hideTicker() { $('#lat-ticker').hide().empty(); }

    /* ═══════════════════════════════════════════════════════════════════
       UI — PER-BATCH LOG
    ═══════════════════════════════════════════════════════════════════ */
    function addBatchLogRow(batchNum, count, ms, tIn, tOut, preview) {
        var timeStr   = ms >= 1000 ? (ms/1000).toFixed(1) + 's' : ms + 'ms';
        var tokenStr  = tIn || tOut ? (tIn + ' / ' + tOut) : '—';
        var previewStr = (preview || []).map(function (s) {
            return s.length > 30 ? s.substring(0, 30) + '…' : s;
        }).join(', ') || '—';

        var $row = $('<div>', { class: 'lat-log-row' }).append(
            $('<span>', { class: 'lat-log-batch',   text: '#' + batchNum }),
            $('<span>', { class: 'lat-log-count',   text: count + ' str' }),
            $('<span>', { class: 'lat-log-time',    text: timeStr }),
            $('<span>', { class: 'lat-log-tokens',  text: tokenStr }),
            $('<span>', { class: 'lat-log-preview', text: previewStr })
        );

        var $rows = $('#lat-log-rows');
        $rows.prepend($row);   // newest on top

        // Keep max 20 rows visible to avoid DOM bloat
        $rows.find('.lat-log-row').slice(20).remove();
    }

    /* ═══════════════════════════════════════════════════════════════════
       UI — LIVE SUMMARY (updated after every batch)
    ═══════════════════════════════════════════════════════════════════ */
    function updateSummaryLive() {
        var elapsed = Math.round((Date.now() - stats.jobStartMs) / 1000);
        var $s = $('#lat-summary').show();
        $s.html(
            '<span>⏱ ' + fmtTime(elapsed) + '</span>' +
            '<span>✅ ' + stats.translated + ' translated</span>' +
            (stats.skipped > 0 ? '<span>⚠ ' + stats.skipped + ' skipped</span>' : '') +
            '<span>🔢 ' + stats.tokensTotal + ' tokens</span>' +
            '<span>📦 ' + stats.batchCount + ' batches</span>'
        );
    }

    function showFinalSummary(completed, elStr) {
        var $s = $('#lat-summary').show();
        var costEst = stats.tokensTotal > 0
            ? ' (~$' + (stats.tokensTotal * 0.0000015).toFixed(4) + ' est.)'
            : '';
        $s.html(
            '<strong>' + (completed ? '✅ Complete' : '⏹ Stopped') + '</strong>' +
            '<span>⏱ ' + elStr + '</span>' +
            '<span>✅ ' + stats.translated + ' translated</span>' +
            (stats.skipped > 0 ? '<span>⚠ ' + stats.skipped + ' skipped</span>' : '') +
            '<span>🔢 ' + stats.tokensTotal + ' tokens' + costEst + '</span>' +
            '<span>↑ ' + stats.tokensPrompt + ' / ↓ ' + stats.tokensCompletion + '</span>' +
            '<span>📦 ' + stats.batchCount + ' batches</span>'
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
       UI — GENERAL
    ═══════════════════════════════════════════════════════════════════ */
    function setUiRunning(on) {
        var $btn  = $('#lat-ai-btn');
        var $stop = $('#lat-stop-btn');
        var $prog = $('#lat-progress-wrap');
        if (on) {
            $btn.prop('disabled', true).addClass('lat-btn-busy').text(latLoco.i18n.translating);
            $stop.show().prop('disabled', false).text('⏹ Stop');
            $prog.show();
        } else {
            $btn.prop('disabled', false).removeClass('lat-btn-busy').text(latLoco.i18n.btnTranslate);
            $stop.hide().prop('disabled', false).text('⏹ Stop');
        }
    }

    function showNotice(message, type, persistent) {
        var $n = $('<div>', { class:'lat-editor-notice lat-notice-' + type, html:message });
        $('#lat-editor-notices').empty().append($n);
        if (!persistent) setTimeout(function () { $n.fadeOut(400, function () { $n.remove(); }); }, 7000);
    }

    function clearNotice() { $('#lat-editor-notices').empty(); }

    function fmtTime(sec) {
        return sec >= 60 ? Math.floor(sec/60) + 'm ' + (sec%60) + 's' : sec + 's';
    }

    function escHtml(s) {
        return $('<div>').text(s).html();
    }

})(jQuery);
