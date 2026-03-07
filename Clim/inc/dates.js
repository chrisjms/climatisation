/**
 * Relative date formatting — Hotel Corintel
 * Replaces [data-date] elements with French relative strings.
 */
(function() {
  'use strict';

  function relativeDate(isoStr) {
    if (!isoStr) return '';
    var parts = isoStr.split('-');
    if (parts.length !== 3) return isoStr;
    var target = new Date(parseInt(parts[0],10), parseInt(parts[1],10)-1, parseInt(parts[2],10));
    var now = new Date();
    now.setHours(0,0,0,0);
    target.setHours(0,0,0,0);

    var diffMs = now.getTime() - target.getTime();
    var diffDays = Math.round(diffMs / 86400000);

    if (diffDays === 0) return "aujourd'hui";
    if (diffDays === 1) return 'hier';
    if (diffDays < 0) return formatDmy(target);
    if (diffDays < 7) return 'il y a ' + diffDays + ' jours';
    if (diffDays < 14) return 'il y a 1 semaine';
    if (diffDays < 60) return 'il y a ' + Math.floor(diffDays / 7) + ' semaines';
    return formatDmy(target);
  }

  function formatDmy(d) {
    return ('0'+d.getDate()).slice(-2)+'/'+('0'+(d.getMonth()+1)).slice(-2)+'/'+d.getFullYear();
  }

  function apply() {
    var els = document.querySelectorAll('[data-date]');
    els.forEach(function(el) {
      var iso = el.getAttribute('data-date');
      if (!iso) return;
      var rel = relativeDate(iso);
      var p = iso.split('-');
      if (p.length === 3) {
        el.setAttribute('title', ('0'+parseInt(p[2],10)).slice(-2)+'/'+('0'+parseInt(p[1],10)).slice(-2)+'/'+p[0]);
      }
      el.textContent = rel;
      el.classList.add('relative-date');
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply);
  else apply();
})();
