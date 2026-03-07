<?php
/**
 * Toast notification include.
 * Add before </body> on every page.
 * Handles URL params (?msg= / ?err=) automatically via JS.
 * For PHP-variable toasts, set $toastMsg or $toastErr before including.
 */
?>
<div id="toast-container" role="status" aria-live="polite"></div>
<script src="inc/toast.js"></script>
<script src="inc/dates.js"></script>
<?php if (!empty($toastMsg)): ?>
<script>document.addEventListener('DOMContentLoaded',function(){showToast(<?= json_encode($toastMsg, JSON_HEX_TAG | JSON_HEX_AMP) ?>,'success')});</script>
<?php endif; ?>
<?php if (!empty($toastErr)): ?>
<script>document.addEventListener('DOMContentLoaded',function(){showToast(<?= json_encode($toastErr, JSON_HEX_TAG | JSON_HEX_AMP) ?>,'error')});</script>
<?php endif; ?>
