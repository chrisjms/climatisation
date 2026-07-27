/**
 * inc/submit-once.js — protection double-clic pour formulaires lents.
 * Usage : <form data-submit-once> ... </form>
 * Au submit, désactive le bouton submit et change son libellé en "Patientez…".
 * En cas d'erreur de navigation (le navigateur revient à la page), la touche pageshow réactive.
 */
(function () {
  function restore(btn) {
    btn.disabled = false;
    var txt = btn.dataset.origText;
    if (txt) {
      if (btn.tagName === 'BUTTON') btn.textContent = txt;
      else btn.value = txt;
    }
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM' || !f.hasAttribute('data-submit-once')) return;
    var btn = f.querySelector('button[type=submit], input[type=submit]');
    if (!btn || btn.disabled) return;
    // Désactiver APRÈS le tick courant : sinon le submit n'inclurait pas le bouton.
    setTimeout(function () {
      btn.disabled = true;
      if (btn.tagName === 'BUTTON') {
        btn.dataset.origText = btn.textContent;
        btn.textContent = 'Patientez…';
      } else {
        btn.dataset.origText = btn.value;
        btn.value = 'Patientez…';
      }
    }, 0);
    // Forms qui ouvrent dans un nouvel onglet : la page courante ne navigue pas,
    // donc on réactive le bouton après quelques secondes pour permettre une nouvelle action.
    if (f.target === '_blank') {
      setTimeout(function () { restore(btn); }, 3000);
    }
  });

  // Si l'utilisateur revient via "Précédent" (bfcache), réactiver le bouton.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    document.querySelectorAll('form[data-submit-once] [type=submit][disabled]').forEach(restore);
  });
})();
