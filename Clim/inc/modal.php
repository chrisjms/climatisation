<?php
/**
 * Custom confirmation modal include.
 * Add after toast.php include on pages that use confirm() dialogs.
 */
?>
<div class="modal-overlay" id="modal-overlay" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-icon">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
    </div>
    <h3 class="modal-title" id="modal-title">Confirmation</h3>
    <p class="modal-message" id="modal-message"></p>
    <div class="modal-actions">
      <button type="button" class="btn btn-secondary" id="modal-cancel">Annuler</button>
      <button type="button" class="btn btn-danger" id="modal-confirm">Confirmer</button>
    </div>
  </div>
</div>
<script src="inc/modal.js"></script>
