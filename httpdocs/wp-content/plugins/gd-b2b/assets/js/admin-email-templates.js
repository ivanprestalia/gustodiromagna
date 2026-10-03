/**
 * Email Template Editor — admin JS.
 *
 * Handles placeholder insertion, AJAX save/preview/test/reset.
 */
(function ($) {
    'use strict';

    var slug    = $('#gd-b2b-tpl-slug').val(),
        nonce   = $('#gd_b2b_tpl_nonce').val(),
        $notice = $('#gd-b2b-tpl-notice');

    if (!slug) {
        return;
    }

    function getEditorContent() {
        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('gd_b2b_tpl_editor')) {
            var ed = tinyMCE.get('gd_b2b_tpl_editor');
            if (!ed.isHidden()) {
                return ed.getContent();
            }
        }
        return $('#gd_b2b_tpl_editor').val();
    }

    function setEditorContent(content) {
        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('gd_b2b_tpl_editor')) {
            var ed = tinyMCE.get('gd_b2b_tpl_editor');
            ed.setContent(content);
        }
        $('#gd_b2b_tpl_editor').val(content);
    }

    function showNotice(msg, type) {
        $notice
            .removeClass('success error')
            .addClass(type)
            .html(msg)
            .show();

        if (type === 'success') {
            setTimeout(function () { $notice.fadeOut(300); }, 4000);
        }
    }

    function setButtonLoading($btn, loading) {
        if (loading) {
            $btn.prop('disabled', true).data('origText', $btn.html());
            $btn.html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span>' + gdB2bTpl.saving);
        } else {
            $btn.prop('disabled', false).html($btn.data('origText'));
        }
    }

    // Placeholder click-to-insert
    $(document).on('click', '.gd-b2b-tpl-ph-btn', function (e) {
        e.preventDefault();
        var ph = $(this).data('placeholder');

        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('gd_b2b_tpl_editor')) {
            var ed = tinyMCE.get('gd_b2b_tpl_editor');
            if (!ed.isHidden()) {
                ed.execCommand('mceInsertContent', false, ph);
                return;
            }
        }

        var $ta = $('#gd_b2b_tpl_editor');
        var ta  = $ta[0];
        if (ta) {
            var start = ta.selectionStart,
                end   = ta.selectionEnd,
                val   = $ta.val();
            $ta.val(val.substring(0, start) + ph + val.substring(end));
            ta.selectionStart = ta.selectionEnd = start + ph.length;
            $ta.trigger('focus');
        }
    });

    // Save
    $('#gd-b2b-tpl-save').on('click', function () {
        var $btn = $(this);
        setButtonLoading($btn, true);

        $.post(gdB2bTpl.ajaxUrl, {
            action:  'gd_b2b_save_email_template',
            nonce:   nonce,
            slug:    slug,
            content: getEditorContent()
        })
        .done(function (res) {
            if (res.success) {
                showNotice(res.data.message, 'success');
            } else {
                showNotice(res.data.message || gdB2bTpl.genericError, 'error');
            }
        })
        .fail(function () {
            showNotice(gdB2bTpl.networkError, 'error');
        })
        .always(function () {
            setButtonLoading($btn, false);
        });
    });

    // Preview
    $('#gd-b2b-tpl-preview').on('click', function () {
        var $btn = $(this);
        setButtonLoading($btn, true);

        $.post(gdB2bTpl.ajaxUrl, {
            action:  'gd_b2b_preview_email_template',
            nonce:   nonce,
            slug:    slug,
            content: getEditorContent()
        })
        .done(function (res) {
            setButtonLoading($btn, false);
            if (res.success && res.data.html) {
                var win = window.open('', '_blank', 'width=700,height=600,scrollbars=yes');
                if (win) {
                    win.document.open();
                    win.document.write(res.data.html);
                    win.document.close();
                } else {
                    showNotice(gdB2bTpl.popupBlocked, 'error');
                }
            } else {
                showNotice((res.data && res.data.message) || gdB2bTpl.genericError, 'error');
            }
        })
        .fail(function () {
            setButtonLoading($btn, false);
            showNotice(gdB2bTpl.networkError, 'error');
        });
    });

    // Send test
    $('#gd-b2b-tpl-test').on('click', function () {
        if (!confirm(gdB2bTpl.confirmTest)) {
            return;
        }

        var $btn = $(this);
        setButtonLoading($btn, true);

        $.post(gdB2bTpl.ajaxUrl, {
            action:  'gd_b2b_send_test_email',
            nonce:   nonce,
            slug:    slug,
            content: getEditorContent()
        })
        .done(function (res) {
            if (res.success) {
                showNotice(res.data.message, 'success');
            } else {
                showNotice(res.data.message || gdB2bTpl.genericError, 'error');
            }
        })
        .fail(function () {
            showNotice(gdB2bTpl.networkError, 'error');
        })
        .always(function () {
            setButtonLoading($btn, false);
        });
    });

    // Reset
    $('#gd-b2b-tpl-reset').on('click', function () {
        if (!confirm(gdB2bTpl.confirmReset)) {
            return;
        }

        var $btn = $(this);
        setButtonLoading($btn, true);

        $.post(gdB2bTpl.ajaxUrl, {
            action:  'gd_b2b_reset_email_template',
            nonce:   nonce,
            slug:    slug
        })
        .done(function (res) {
            if (res.success) {
                if (res.data.content !== undefined) {
                    setEditorContent(res.data.content);
                }
                showNotice(res.data.message, 'success');
                $btn.fadeOut(300);
            } else {
                showNotice(res.data.message || gdB2bTpl.genericError, 'error');
            }
        })
        .fail(function () {
            showNotice(gdB2bTpl.networkError, 'error');
        })
        .always(function () {
            setButtonLoading($btn, false);
        });
    });

})(jQuery);
