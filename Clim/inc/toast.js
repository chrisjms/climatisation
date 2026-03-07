/**
 * Toast notification system — Hotel Corintel
 * Usage: showToast('Message', 'success') | showToast('Erreur', 'error')
 * Auto-triggers from ?msg= and ?err= URL params.
 */
(function() {
  'use strict';

  var container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }

  var icons = {
    success: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    error: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/></svg>',
    info: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/></svg>'
  };

  function showToast(msg, type, duration) {
    type = type || 'info';
    duration = duration || 3000;

    var el = document.createElement('div');
    el.className = 'toast toast--' + type;
    el.innerHTML = '<span class="toast__icon">' + (icons[type] || icons.info) + '</span>'
                 + '<span class="toast__msg">' + escapeHtml(msg) + '</span>';

    container.appendChild(el);
    el.offsetHeight; // trigger reflow
    el.classList.add('show');

    var timer = setTimeout(function() { dismiss(el); }, duration);
    el.addEventListener('click', function() { clearTimeout(timer); dismiss(el); });
  }

  function dismiss(el) {
    el.classList.remove('show');
    el.classList.add('hiding');
    setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 400);
  }

  function escapeHtml(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  window.showToast = showToast;

  // Button loading states on form submit
  document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('submit', function(e) {
      var form = e.target;
      if (!form || form.tagName !== 'FORM') return;
      // Defer so any preventDefault() in modal confirmations can fire first
      setTimeout(function() {
        var btn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (btn && btn.classList) {
          btn.classList.add('is-loading');
          btn.disabled = true;
        }
      }, 0);
    });
  });

  // Auto-trigger from URL params
  document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    var msg = params.get('msg');
    var err = params.get('err');

    if (msg) showToast(msg, 'success');
    if (err) showToast(err, 'error');

    if (msg || err) {
      params.delete('msg');
      params.delete('err');
      var url = window.location.pathname;
      var rest = params.toString();
      if (rest) url += '?' + rest;
      if (window.location.hash) url += window.location.hash;
      window.history.replaceState({}, '', url);
    }
  });
})();
