/* global latAdmin, jQuery */
(function ($) {
    'use strict';

    // ─── Provider Tabs ──────────────────────────────────────────────────────
    $('.lat-provider-tab input[type=radio]').on('change', function () {
        $('.lat-provider-tab').removeClass('active');
        $(this).closest('.lat-provider-tab').addClass('active');

        const provider = $(this).val();

        // Show/hide API key row
        if (provider === 'ollama') {
            $('.lat-row-apikey').hide();
        } else {
            $('.lat-row-apikey').show();
        }
    });

    // ─── Endpoint Presets ───────────────────────────────────────────────────
    $('.lat-preset').on('click', function () {
        $('#lat-api-endpoint').val($(this).data('value'));
    });

    // ─── Default Prompt Preview ─────────────────────────────────────────────
    $('#lat-show-default-prompt').on('click', function () {
        const $pre = $('#lat-default-prompt-preview');
        $pre.is(':visible') ? $pre.slideUp() : $pre.slideDown();
        $(this).text($pre.is(':visible') ? 'Hide default prompt' : 'View default prompt');
    });

    // ─── Load Models ────────────────────────────────────────────────────────
    $('#lat-fetch-models').on('click', function () {
        const $btn = $(this);
        const $select = $('#lat-model-select');
        const $input = $('#lat-model-input');

        $btn.text('Loading…').prop('disabled', true);

        $.post(latAdmin.ajaxUrl, {
            action: 'lat_fetch_models',
            nonce: latAdmin.nonce,
        }, function (res) {
            $btn.text('↻ Load Models').prop('disabled', false);

            if (!res.success) {
                alert('Error: ' + (res.data?.message || 'Unknown error'));
                return;
            }

            const models = res.data.models;
            $select.empty().append('<option value="">— choose a model —</option>');

            models.forEach(function (m) {
                let label = m.id;
                if (m.name && m.name !== m.id) label += ' — ' + m.name;
                if (m.context) label += ' (' + Math.round(m.context / 1000) + 'k ctx)';
                $select.append($('<option>').val(m.id).text(label));
            });

            // Pre-select current model
            $select.val($input.val());
            $select.show();

            $select.off('change').on('change', function () {
                $input.val($(this).val());
            });
        }).fail(function () {
            $btn.text('↻ Load Models').prop('disabled', false);
            alert('Network error while loading models.');
        });
    });

    // ─── Test Connection ────────────────────────────────────────────────────
    $('#lat-test-connection').on('click', function () {
        const $btn = $(this);
        const $result = $('#lat-test-result');

        $btn.text('Testing…').prop('disabled', true);
        $result.removeClass('lat-test-ok lat-test-err').text('');

        $.post(latAdmin.ajaxUrl, {
            action: 'lat_test_connection',
            nonce: latAdmin.nonce,
        }, function (res) {
            $btn.text('🔌 Test Connection').prop('disabled', false);

            if (res.success) {
                $result.addClass('lat-test-ok')
                    .text('✓ ' + res.data.message + '  "Hello" → "' + res.data.test_output + '"');
            } else {
                $result.addClass('lat-test-err')
                    .text('✗ ' + (res.data?.message || 'Unknown error'));
            }
        }).fail(function () {
            $btn.text('🔌 Test Connection').prop('disabled', false);
            $result.addClass('lat-test-err').text('✗ Network error');
        });
    });

    function escHtml(str) {
        return $('<div>').text(str).html();
    }

})(jQuery);
