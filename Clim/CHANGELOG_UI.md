# Changelog — UI Improvements

All UI enhancements added to the Climatisation dashboard, organized by batch.

---

## Batch 1 — Core UX Foundation

| Feature | Description | Files |
|---------|-------------|-------|
| **Toast notifications** | Global `showToast(msg, type, duration)` system for success/error/info feedback | `inc/toast.js`, `inc/toast.php`, `style.css` |
| **Dark mode** | Full dark theme with CSS variable overrides, toggle in sidebar, persisted to localStorage | `style.css` (`:root` + `.theme-dark`), `inc/sidebar.php` |
| **Status pills** | Color-coded `.pill` badges for document statuses | `style.css` |
| **Empty states** | Friendly "no data" placeholders with icons when tables/lists are empty | Multiple pages |
| **Sparklines** | Inline mini-charts on the dashboard KPI cards | `index.php`, `style.css` |

---

## Batch 2 — Polish & Accessibility

| Feature | Description | Files |
|---------|-------------|-------|
| **Spacing overhaul** | Tokenized all hard-coded spacing to 4px grid (`--gap-1` through `--gap-8`, `--card-pad: 28px`) | `style.css` (~16 rules) |
| **Button loading states** | `@keyframes spin` + `.btn.is-loading` spinner, auto-attached on form submit | `style.css`, `inc/toast.js` |
| **Accessibility** | `aria-current="page"` on active nav, `aria-label` on buttons/links, `role="navigation"`, `aria-live="polite"` on toast | `inc/sidebar.php`, `inc/toast.php` |
| **Breadcrumb navigation** | Contextual breadcrumbs on sub-pages (Clientele > Client, Nouveau client, Banques) | `style.css`, `clientele.php`, `ajout_client.php`, `manage_bank.php` |
| **Mobile-responsive tables** | `@media` card-view transform with `data-label` attributes on `<td>` elements | `style.css`, `manage_bank.php`, `gestion_comptes.php` |
| **Custom confirmation modal** | `confirmAction(message, onConfirm, opts)` replacing all native `confirm()` calls, with focus trap + Escape key + backdrop click | `inc/modal.js` (NEW), `inc/modal.php` (NEW), `style.css`, 6 pages updated |
| **Drag-and-drop upload** | Drop zone on brochures page for file uploads with visual feedback | `brochures.php`, `style.css` |

---

## Batch 3 — Data & Interaction

| Feature | Description | Files |
|---------|-------------|-------|
| **Animated KPI counters** | Numbers count up from 0 on page load (800ms ease-out cubic) with French locale formatting | `index.php` (6 stat-values + script) |
| **Relative dates** | "aujourd'hui", "hier", "il y a X jours/semaines" with full date tooltip | `inc/dates.js` (NEW), `inc/toast.php`, `index.php`, `clientele.php` |
| **Client search UX** | 150ms debounce, clear button, live result counter, no-results empty state | `clientele.php`, `style.css` |
| **Table sort indicators** | Clickable column headers with ascending/descending arrows on brochures table | `brochures.php` (`sort_link()` helper), `style.css` |
| **Inline form validation** | Client-side field-level validation with red border + error message, clears on typing | `login.php`, `devis.php`, `style.css` |
| **Copy button feedback** | Green flash with "Copie !" text for 1.5s after clipboard copy | `clientele.php`, `style.css` |
| **Pill-style tabs** | `.tabs--pill` modifier class with filled pill style and shadow on active tab | `clientele.php`, `style.css` |
| **MIME type badges** | Color-coded badges: PDF (red), Image (green), DOCX (accent), PPTX (orange) | `brochures.php` (`mime_badge()` helper), `style.css` |
| **Pagination** | Prev/next controls on devis, BDC, and factures tables with independent page state per table | `devis.php` (3 fetch + 3 count functions + `pagination_html()`), `style.css` |

---

## Batch 4 — Advanced Features

| Feature | Description | Files |
|---------|-------------|-------|
| **CSV export** | Download devis, BDC, factures, or client lists as CSV files with UTF-8 BOM for Excel | `export_devis_csv.php` (NEW), `export_clients_csv.php` (NEW), `devis.php`, `clientele.php` |
| **Date range filter** | Filter documents by start/end date, combined with text search, preserved across pagination | `devis.php` (6 functions + search form + pagination) |
| **Sticky floating save bar** | Total HT/TTC + submit button pinned to bottom of viewport while scrolling devis form | `devis.php` (`#devis-sticky-bar`), `style.css` |
| **Unsaved changes warning** | Browser `beforeunload` prompt when navigating away from a modified form | `devis.php`, `ajout_client.php` |
| **Login gradient animation** | Slow 12s CSS gradient shift animation on login page background | `style.css` (`@keyframes login-gradient-shift`) |
| **Card hover lift** | Enhanced stat-card hover with deeper `--shadow-3` shadow | `style.css` |
| **Modal accessibility** | `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, `aria-hidden` toggling | `inc/modal.php`, `inc/modal.js` |
| **`aria-live` on totals** | Screen reader announcements when devis totals change | `devis.php` (`#total-container`) |
| **Mobile dark mode toggle** | Separate theme toggle button visible on mobile (sidebar footer hidden on mobile) | `inc/sidebar.php`, `style.css` |
| **Pipeline click-through** | Dashboard pipeline stat cards are clickable links to filtered devis page | `index.php`, `style.css` |
| **Auto-save draft** | Devis form state auto-saved to localStorage every 1.5s, restore prompt on return | `devis.php` (localStorage `devis_draft_v1`) |

---

## Technical Summary

- **Total new files created:** 6 (`inc/modal.js`, `inc/modal.php`, `inc/dates.js`, `export_devis_csv.php`, `export_clients_csv.php`, `CHANGELOG_UI.md`)
- **Total files modified:** 14+ (`style.css`, `index.php`, `devis.php`, `clientele.php`, `brochures.php`, `login.php`, `ajout_client.php`, `ajout_pac.php`, `manage_bank.php`, `gestion_comptes.php`, `inc/sidebar.php`, `inc/toast.php`, `inc/toast.js`, `inc/modal.js`)
- **CSS additions:** ~250+ lines of new rules across all batches
- **Zero external dependencies added** — everything is vanilla PHP/JS/CSS
- **All features work in both light and dark mode**
