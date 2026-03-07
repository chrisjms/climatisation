/**
 * Custom confirmation modal — Hotel Corintel
 * Usage: confirmAction('Message', function(){ ... }, { title, confirmText, cancelText, danger })
 */
(function() {
  'use strict';

  var overlay = document.getElementById('modal-overlay');
  var titleEl = document.getElementById('modal-title');
  var msgEl = document.getElementById('modal-message');
  var confirmBtn = document.getElementById('modal-confirm');
  var cancelBtn = document.getElementById('modal-cancel');
  var onConfirmCb = null;

  function open(message, onConfirm, opts) {
    opts = opts || {};
    onConfirmCb = onConfirm;

    titleEl.textContent = opts.title || 'Confirmation';
    msgEl.textContent = message;
    confirmBtn.textContent = opts.confirmText || 'Confirmer';
    cancelBtn.textContent = opts.cancelText || 'Annuler';

    if (opts.danger) {
      confirmBtn.className = 'btn btn-danger';
    } else {
      confirmBtn.className = 'btn btn-primary';
    }

    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    confirmBtn.focus();

    // Focus trap
    overlay.addEventListener('keydown', trapFocus);
  }

  function close() {
    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    onConfirmCb = null;
    overlay.removeEventListener('keydown', trapFocus);
  }

  function trapFocus(e) {
    if (e.key === 'Tab') {
      var focusable = [cancelBtn, confirmBtn];
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first) { e.preventDefault(); last.focus(); }
      } else {
        if (document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }
  }

  // Cancel button
  cancelBtn.addEventListener('click', close);

  // Confirm button
  confirmBtn.addEventListener('click', function() {
    var cb = onConfirmCb;
    close();
    if (cb) cb();
  });

  // Backdrop click
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) close();
  });

  // Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && overlay.classList.contains('show')) close();
  });

  window.confirmAction = open;
})();
