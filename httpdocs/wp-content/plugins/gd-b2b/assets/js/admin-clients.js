/* GD-B2B Admin Clients - v2.0.0 */

jQuery(function ($) {
	var L = (typeof gdB2bClientsAdmin !== 'undefined' && gdB2bClientsAdmin)
		? gdB2bClientsAdmin
		: {};

	/* ---------------------------------------------------------------
	   Helpers
	   --------------------------------------------------------------- */

	function txt(s) {
		return s ? String(s) : '';
	}

	function escTxt(s) {
		return $('<span/>').text(txt(s)).html();
	}

	function gdB2bRecipientSuffix(toAddr) {
		var a = txt(toAddr);
		if (!a) { return ''; }
		var pat = txt(L.mailSentToRecipient || '');
		if (pat && pat.indexOf('%s') !== -1) {
			return ' ' + pat.split('%s').join(a);
		}
		return ' ' + a;
	}

	function gdB2bMailOkWithTo(baseMsg, toAddr) {
		return txt(baseMsg) + gdB2bRecipientSuffix(toAddr);
	}

	function mailErrFromXhr(xhr) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
			return txt(xhr.responseJSON.data.message);
		}
		return L.mailFailGeneric || 'Error';
	}

	/* ---------------------------------------------------------------
	   Badge / select helpers
	   --------------------------------------------------------------- */

	function gdB2bUpdateStatoBadge($sel, assignRes) {
		var $st = $sel.closest('tr').find('.column-stato');
		if (!(assignRes && assignRes.success && assignRes.data)) { return; }
		if (assignRes.data.status === 'pending') {
			$st.html(
				'<span class="gd-b2b-badge gd-b2b-badge--pending">' +
				escTxt(L.pendingLbl) + '</span>'
			);
		} else if (assignRes.data.label) {
			$st.html(
				'<span class="gd-b2b-badge gd-b2b-badge--ok">' +
				escTxt(assignRes.data.label) + '</span>'
			);
		}
	}

	function gdB2bApplySelectAfterAssign($sel, newRole) {
		$sel.val(newRole);
		$sel.data('gd-b2b-prev-role', newRole);
	}

	function gdB2bUpdateMailBadgeInRow($sel, sentTotal) {
		var tn  = parseInt(sentTotal, 10);
		var $btn = $sel.closest('tr')
			.find('.column-azioni .gd-b2b-resend-activation').first();
		if ($btn.length) {
			$btn.closest('.gd-b2b-resend-stack')
				.find('.gd-b2b-mail-count').text('[' + tn + ']');
		}
	}

	/* ---------------------------------------------------------------
	   Mail-count UI updater
	   --------------------------------------------------------------- */

	function gd_b2b_update_mail_count_ui($btnOrStack, total) {
		var $st = $($btnOrStack).closest('.gd-b2b-resend-stack');
		if (!$st.length && $($btnOrStack).is('.gd-b2b-resend-stack')) {
			$st = $($btnOrStack);
		} else if (!$st.length) {
			$st = $($btnOrStack);
		}
		$st.find('.gd-b2b-mail-count').text('[' + String(total) + ']');
	}

	/* ---------------------------------------------------------------
	   Modal – create once, reuse
	   --------------------------------------------------------------- */

	function gdB2b_modalResetViews($m) {
		$m.removeClass('is-open').hide();
		$m.find('.gd-b2b-modal-result')
			.hide().removeClass('is-visible is-ok is-err').text('');
		$m.find('.gd-b2b-modal-footer')
			.hide().removeClass('is-visible');
		$m.find('.gd-b2b-modal-step-primary').show();
		$m.find('.gd-b2b-modal-send, .gd-b2b-modal-nosend, .gd-b2b-modal-close-footer')
			.prop('disabled', false);
	}

	function gdB2bEnsureModalOnce() {
		var el = document.getElementById('gd-b2b-role-mail-modal');
		if (el) { return $('#gd-b2b-role-mail-modal'); }

		var h = ''
			+ '<div id="gd-b2b-role-mail-modal" class="gd-b2b-modal-backdrop"'
			+ ' tabindex="-1" role="presentation">'
			+ '<div class="gd-b2b-modal-dialog" role="dialog" aria-modal="true"'
			+ ' aria-labelledby="gd-b2b-modal-title">'
			+ '<h2 id="gd-b2b-modal-title">' + escTxt(L.modalTitle) + '</h2>'
			+ '<p class="gd-b2b-modal-question">' + escTxt(L.modalAsk) + '</p>'
			+ '<div class="gd-b2b-modal-actions gd-b2b-modal-step-primary">'
			+ '<button type="button" class="button button-primary gd-b2b-modal-send">'
			+ escTxt(L.btnSendNow) + '</button>'
			+ '<button type="button" class="button gd-b2b-modal-nosend">'
			+ escTxt(L.btnNoClose) + '</button>'
			+ '</div>'
			+ '<div class="gd-b2b-modal-result" role="alert"></div>'
			+ '<div class="gd-b2b-modal-footer">'
			+ '<button type="button" class="button gd-b2b-modal-close-footer">'
			+ escTxt(L.btnModalClose) + '</button>'
			+ '</div>'
			+ '</div></div>';

		$('body').append(h);

		var $m = $('#gd-b2b-role-mail-modal');

		function canCancelByBackdrop() {
			return !$m.find('.gd-b2b-modal-footer').hasClass('is-visible');
		}

		function closeCancel() {
			gdB2b_modalResetViews($m);
			$m.removeData('ctx');
		}

		$m.on('click.gdBackdrop', function (e) {
			if ($(e.target).is($m) && canCancelByBackdrop()) {
				closeCancel();
			}
		});

		return $m;
	}

	function gdB2bModalFinishWithMessage($m, cssClass, message) {
		var $r = $m.find('.gd-b2b-modal-result');
		$r.removeClass('is-ok is-err')
			.addClass(cssClass === 'err' ? 'is-err' : 'is-ok')
			.text(message).show().addClass('is-visible');
		$m.find('.gd-b2b-modal-step-primary').hide();
		$m.find('.gd-b2b-modal-footer').show().addClass('is-visible');
		$m.find('.gd-b2b-modal-send, .gd-b2b-modal-nosend').prop('disabled', false);
	}

	/* ---------------------------------------------------------------
	   AJAX wrappers
	   --------------------------------------------------------------- */

	function assignRole(uid, nonce, role) {
		return $.post(ajaxurl, {
			action:  'gd_b2b_assign_role',
			nonce:   nonce,
			user_id: uid,
			role:    role
		});
	}

	function sendMail(uid, nonce) {
		return $.post(ajaxurl, {
			action:  'gd_b2b_resend_activation_email',
			nonce:   nonce,
			user_id: uid
		});
	}

	/* ---------------------------------------------------------------
	   Init – modal singleton & role-select tracking
	   --------------------------------------------------------------- */

	var $glob = gdB2bEnsureModalOnce();

	$glob.on('click', '.gd-b2b-modal-close-footer', function () {
		var $m = $('#gd-b2b-role-mail-modal');
		gdB2b_modalResetViews($m);
		$m.removeData('ctx');
	});

	$(document).on('keydown.gd-b2b-modalEsc', function (e) {
		var $m = $('#gd-b2b-role-mail-modal');
		if (e.key !== 'Escape' || !$m.hasClass('is-open')) { return; }
		if (!$m.find('.gd-b2b-modal-footer').hasClass('is-visible')) {
			gdB2b_modalResetViews($m);
			$m.removeData('ctx');
		}
	});

	$(document).on('focusin', '.gd-b2b-role-select', function () {
		var $s = $(this);
		if ($s.data('gd-b2b-prev-role') === undefined) {
			$s.data('gd-b2b-prev-role', $s.val());
		}
	});

	$('.gd-b2b-role-select').each(function () {
		var $s = $(this);
		if ($s.data('gd-b2b-prev-role') === undefined) {
			$s.data('gd-b2b-prev-role', $s.val());
		}
	});

	/* ---------------------------------------------------------------
	   Detail-row toggle
	   --------------------------------------------------------------- */

	$(document).on('click', '.gd-b2b-toggle-detail', function () {
		$('#gd-b2b-detail-' + $(this).data('user-id')).toggle();
	});

	/* ---------------------------------------------------------------
	   Resend activation email (standalone button)
	   --------------------------------------------------------------- */

	$(document).on('click', '.gd-b2b-resend-activation', function () {
		var $b        = $(this);
		var nonce     = $b.data('nonce');
		var $stack    = $b.closest('.gd-b2b-resend-stack');
		var $feedback = $stack.find('.gd-b2b-mail-feedback');

		$feedback.removeClass('is-error').removeClass('is-ok').text('');
		$b.prop('disabled', true);

		sendMail($b.data('user-id'), nonce)
			.always(function () { $b.prop('disabled', false); })
			.done(function (res) {
				if (res && res.success && res.data) {
					var t  = parseInt(res.data.total_sent, 10) || 0;
					var to = res.data.to ? txt(res.data.to) : '';
					gd_b2b_update_mail_count_ui($stack, t);
					$feedback.removeClass('is-error').addClass('is-ok')
						.text('[' + t + '] ' + txt(L.emailSentLbl) + gdB2bRecipientSuffix(to));
				} else if (res && res.data && res.data.message) {
					$feedback.addClass('is-error').removeClass('is-ok')
						.text(txt(res.data.message));
				} else {
					$feedback.addClass('is-error').removeClass('is-ok')
						.text(L.mailFailGeneric || '');
				}
			})
			.fail(function (xhr) {
				$feedback.addClass('is-error').removeClass('is-ok')
					.text(mailErrFromXhr(xhr));
			});
	});

	/* ---------------------------------------------------------------
	   Modal "INVIA ORA" – assign role then send activation email
	   --------------------------------------------------------------- */

	$('#gd-b2b-role-mail-modal').on('click', '.gd-b2b-modal-send', function (ev) {
		ev.preventDefault();
		var $m = $('#gd-b2b-role-mail-modal');
		var cx = $m.data('ctx');
		if (!cx) { return; }

		var $sp = cx.$sp || $();
		$sp.addClass('is-active');
		$m.find('.gd-b2b-modal-send, .gd-b2b-modal-nosend').prop('disabled', true);

		function endSpinner() { $sp.removeClass('is-active'); }

		assignRole(cx.uid, cx.nonce, cx.newRole)
			.done(function (r) {
				if (!r || !r.success) {
					endSpinner();
					$m.find('.gd-b2b-modal-send, .gd-b2b-modal-nosend').prop('disabled', false);
					var em = (r && r.data && r.data.message)
						? txt(r.data.message)
						: (L.roleAssignFail || '');
					gdB2bModalFinishWithMessage($m, 'err', em);
					return;
				}

				gdB2bUpdateStatoBadge(cx.$sel, r);
				gdB2bApplySelectAfterAssign(cx.$sel, cx.newRole);

				sendMail(cx.uid, cx.nonce)
					.always(function () {
						endSpinner();
						$m.find('.gd-b2b-modal-send, .gd-b2b-modal-nosend').prop('disabled', false);
					})
					.done(function (em) {
						var $fed = cx.$sel.closest('tr')
							.find('.column-azioni .gd-b2b-mail-feedback').first();
						if (em && em.success && em.data) {
							var n      = parseInt(em.data.total_sent, 10) || 0;
							var okLine = gdB2bMailOkWithTo(L.mailConfirmedOk, em.data.to);
							gdB2bUpdateMailBadgeInRow(cx.$sel, n);
							if ($fed.length) {
								$fed.removeClass('is-error').addClass('is-ok').text(okLine);
							}
							gdB2bModalFinishWithMessage($m, 'ok', okLine);
						} else {
							var ms = (em && em.data && em.data.message)
								? txt(em.data.message)
								: (L.mailFailGeneric || '');
							if ($fed.length) {
								$fed.addClass('is-error').removeClass('is-ok').text(ms);
							}
							gdB2bModalFinishWithMessage($m, 'err', ms);
						}
					})
					.fail(function (xhr) {
						var mex  = mailErrFromXhr(xhr);
						var $fed = cx.$sel.closest('tr')
							.find('.column-azioni .gd-b2b-mail-feedback').first();
						if ($fed.length) {
							$fed.addClass('is-error').removeClass('is-ok').text(mex);
						}
						gdB2bModalFinishWithMessage($m, 'err', mex);
					});
			})
			.fail(function (xhr) {
				endSpinner();
				$m.find('.gd-b2b-modal-send, .gd-b2b-modal-nosend').prop('disabled', false);
				gdB2bModalFinishWithMessage($m, 'err', mailErrFromXhr(xhr));
			});
	});

	/* ---------------------------------------------------------------
	   Modal "NO, CHIUDI" – assign role without sending email
	   --------------------------------------------------------------- */

	$('#gd-b2b-role-mail-modal').on('click', '.gd-b2b-modal-nosend', function (ev) {
		ev.preventDefault();
		var $m = $('#gd-b2b-role-mail-modal');
		var cx = $m.data('ctx');
		if (!cx) { return; }

		var $sp = cx.$sp || $();
		$sp.addClass('is-active');
		$m.find('.gd-b2b-modal-send, .gd-b2b-modal-nosend').prop('disabled', true);

		assignRole(cx.uid, cx.nonce, cx.newRole)
			.always(function () {
				$sp.removeClass('is-active');
				$m.find('.gd-b2b-modal-send, .gd-b2b-modal-nosend').prop('disabled', false);
			})
			.done(function (r) {
				if (r && r.success) {
					gdB2bUpdateStatoBadge(cx.$sel, r);
					gdB2bApplySelectAfterAssign(cx.$sel, cx.newRole);
					gdB2b_modalResetViews($m);
					$m.removeData('ctx');
				} else {
					var em = (r && r.data && r.data.message)
						? txt(r.data.message)
						: (L.roleAssignFail || '');
					gdB2bModalFinishWithMessage($m, 'err', em);
				}
			})
			.fail(function (xhr) {
				gdB2bModalFinishWithMessage($m, 'err', mailErrFromXhr(xhr));
			});
	});

	/* ---------------------------------------------------------------
	   Role <select> change → open confirmation modal
	   --------------------------------------------------------------- */

	$(document).on('change', '.gd-b2b-role-select', function () {
		var $sel = $(this);
		var nw   = txt($sel.val());
		var pr   = txt($sel.data('gd-b2b-prev-role'));
		if (nw === pr) { return; }

		$sel.val(pr);

		var uid = parseInt($sel.data('user-id'), 10);
		var $m  = gdB2bEnsureModalOnce();

		$m.data('ctx', {
			$sel:    $sel,
			$sp:     $sel.next('.gd-b2b-role-spinner'),
			uid:     uid,
			nonce:   $sel.data('nonce'),
			newRole: nw
		});

		gdB2b_modalResetViews($m);
		$m.addClass('is-open').css('display', 'flex');

		try { $m.find('.gd-b2b-modal-send').trigger('focus'); } catch (er) { /* noop */ }
	});
});
