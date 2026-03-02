# UI/UX Redesign Plan — Clim Business Management App

## Status: ANALYSIS & PROPOSAL (no code changes yet)

---

# PHASE 1: PROJECT UNDERSTANDING

## Architecture Overview

### Folder Organization
```
Clim/
├── config.php              # PDO MySQL connection
├── auth.php                # Session-based auth guard
├── login.php               # Login page (standalone styling)
├── logout.php              # Session destroy
├── index.php               # Dashboard (KPIs + activity)
├── clientele.php           # Client CRM (list + detail view)
├── ajout_client.php        # Add new client form
├── update_client.php       # Edit client handler
├── supprimer_client.php    # Delete client handler
├── devis.php               # Quotes/Invoices/PO management + creation form
├── traitement_devis.php    # Quote processing backend
├── save_devis.php          # Quote save handler
├── generer_facture.php     # Invoice generation from quote/PO
├── generer_bdc.php         # Purchase order generation
├── brochures.php           # Document/brochure upload & management
├── ajout_pac.php           # Materials (PAC) CRUD + categories
├── manage_bank.php         # Bank account management
├── analyses.php            # Analytics & charts (Chart.js)
├── gestion_comptes.php     # User account management (admin)
├── style.css               # Single global stylesheet (758 lines)
├── inc/sidebar.php         # Shared sidebar navigation
├── assets/logo.jpeg        # Company logo
├── tfpdf/                  # PDF generation library
├── uploads/brochures/      # Uploaded files
├── devis_pdf/              # Generated quote PDFs
├── facture_pdf/            # Generated invoice PDFs
└── bdc_pdf/                # Generated PO PDFs
```

### Frontend Structure
- **No JS framework** — vanilla JS with inline `<script>` blocks
- **No CSS framework** — custom `style.css` with CSS variables
- **Single stylesheet** — 758 lines, well-organized with section comments
- **Page-specific styles** — inline `<style>` blocks in several pages (index.php, ajout_pac.php, brochures.php, gestion_comptes.php)
- **Chart.js CDN** — used only on analyses.php

### Layout Pattern
Every page follows: `auth.php + config.php → PHP logic → HTML with sidebar.php → inline JS`

### Sidebar Navigation (8 items)
1. Accueil (Dashboard)
2. Clientèle (Client CRM)
3. Devis/Factures/BDC (Quotes/Invoices/POs)
4. Brochures (Document library)
5. Matériels (Equipment/Materials)
6. Banques (Bank accounts)
7. Analyses et rapports (Analytics)
8. Gestion des comptes (User management)

---

## Page-by-Page Analysis

### 1. LOGIN (`login.php`)
**What it does:** Authentication form — username/password with CSRF.
**Current UI:** Standalone inline styles, centered card, basic form. Does NOT use `style.css`.
**User flow:** Login → redirect to index.php

### 2. DASHBOARD (`index.php`)
**What it does:** Executive overview — KPIs (clients, monthly/yearly revenue), quick action buttons, recent activity feed, last 5 quotes & invoices, pipeline view, top materials (90 days), revenue by month (6 months).
**Current UI:** 4-column KPI grid → quick action bar → 2-column layout (activity+tables left, pipeline+stats right). Heavy inline `<style>` block duplicating/overriding style.css classes.
**User flow:** Landing page after login. Quick links to create quotes, clients, manage materials.

### 3. CLIENTELE (`clientele.php`)
**What it does:** Full CRM page — client list with search, client detail view (contact info, phones, emails, notes, documents organized by tabs: Devis/Factures/BDC).
**Current UI:** Left: searchable client list. Selected client shows: header with avatar + quick actions, info grid (phones, emails, address, details), editable notes, tabbed document view.
**User flow:** Search/select client → view/edit info → navigate to associated documents.

### 4. ADD CLIENT (`ajout_client.php`)
**What it does:** Form to add a new client with multi-phone, multi-email, address, and details.
**Current UI:** 2-column grid layout. Left: phones/emails/date/submit. Right: name/address/details. Dynamic add/remove rows for contacts.
**User flow:** Fill form → submit → redirect to clientele.php

### 5. DEVIS/FACTURES/BDC (`devis.php`)
**What it does:** Central document hub — lists existing quotes, purchase orders, invoices with search. Contains the quote creation form (piece/section builder with PAC material search, quantity, price, TVA).
**Current UI:** Search bar + tabs (Devis/BDC/Factures) at top. Document tables below. Complex quote builder form with pieces, material search autocomplete, totals.
**User flow:** Search documents → view/copy existing → create new quote with piece builder → generate PDF.

### 6. BROCHURES (`brochures.php`)
**What it does:** Upload, filter, view, and manage brochures (PDFs, images, docs).
**Current UI:** 2-column top (upload form + filter form), table listing below with preview thumbnails, inline description editing via `<details>`.
**User flow:** Upload file → filter/search → view/download/delete.

### 7. MATERIALS (`ajout_pac.php`)
**What it does:** CRUD for materials/equipment, organized by categories with drag-and-drop reordering.
**Current UI:** 2-column top (add/edit material form + category management), grouped table below with collapsible categories and drag handles.
**User flow:** Add/edit materials → organize by category → reorder via drag-and-drop.

### 8. BANK ACCOUNTS (`manage_bank.php`)
**What it does:** CRUD for bank accounts used in invoices.
**Current UI:** Simple form (add/edit view) or table (list view). Most basic page — minimal styling.
**User flow:** List accounts → add/edit → toggle active status.

### 9. ANALYTICS (`analyses.php`)
**What it does:** Time-based charts for quotes and invoices (count + TTC amounts), with day/month/year/all views. Revenue summary table.
**Current UI:** View selector dropdown → 4 Chart.js line charts in 2-column grid → revenue summary table with KPI pills.
**User flow:** Select time granularity → view charts and revenue table.

### 10. USER ACCOUNTS (`gestion_comptes.php`)
**What it does:** Admin user management — create accounts, change passwords, manage roles, delete users.
**Current UI:** 2-column top (create account form + change password form), user table below with inline role change and delete actions.
**User flow:** Create user → manage roles → reset passwords.

---

# PHASE 2: UI/UX WEAKNESSES & REDESIGN PROPOSALS

## GLOBAL WEAKNESSES

| Issue | Impact |
|-------|--------|
| **Inconsistent styling** — `index.php` has 25-line inline `<style>` overriding `.card`, `.btn`, `.badge`, `.kpi` from style.css | Visual inconsistency between dashboard and other pages |
| **Emoji-based icons** — sidebar uses emoji (🏠👥➕📄🔥🏦📊👤) instead of proper icon set | Unprofessional, renders differently per OS/browser |
| **No logo in sidebar** — just text "Climatisation" | Weak brand presence |
| **No page header pattern** — some pages use `<h1>`, some use cards, no consistent page header with breadcrumbs or context | Disorienting navigation |
| **Color palette lacks warmth** — pure corporate blue (#1976d2) with no accent differentiation for HVAC brand | Generic, not branded |
| **Dense tables** — thin padding (10px 12px), no row grouping, small action buttons | Hard to scan quickly |
| **Forms lack visual grouping** — fields flow without clear fieldset sections or step indication | Cognitive load |
| **No empty states** — just "Aucun..." text when lists are empty | Missed UX opportunity |
| **No loading feedback** — form submissions have no visual feedback | Users don't know if action succeeded |
| **Mixed button hierarchy** — too many button variants without clear primary/secondary distinction per context | Decision paralysis |

---

## PAGE-BY-PAGE REDESIGN PROPOSALS

### 1. LOGIN PAGE

**Current weaknesses:**
- Completely separate styling from the rest of the app (inline only)
- No branding — no logo, no company name prominently displayed
- Basic form with no visual interest
- "Afficher le mot de passe" checkbox feels outdated

**Proposed redesign:**
- Split-screen layout: left side = branded panel (logo + company name + tagline), right side = login form
- Use the main `style.css` for consistency
- Replace checkbox with eye icon toggle on the password field
- Add subtle background gradient matching the app's palette
- Professional centered card with shadow on mobile (single column)

---

### 2. DASHBOARD (`index.php`)

**Current weaknesses:**
- Inline `<style>` block (25 lines) conflicts with/duplicates style.css — `.card`, `.btn`, `.badge`, `.kpi`, `.muted` redefined
- KPI cards are flat and uniform — no color coding or visual hierarchy
- Quick action buttons look like generic tags, no clear CTAs
- Activity feed uses badges but no icons for type differentiation
- Tables (last devis/factures) have no visual distinction
- Pipeline section is underused (only 1 KPI inside a 2-column grid)
- No greeting personality — "Bienvenue, username" is minimal
- Revenue-by-month table is plain text — should be a chart

**Proposed redesign:**
- **Remove all inline styles** — consolidate into style.css
- **KPI cards:** Add color-coded left border or top accent (blue=clients, green=revenue, orange=unpaid, purple=quotes). Add small trend arrow or sparkline
- **Quick actions:** Convert to icon+text buttons with clear primary style, grouped in a "toolbar" card
- **Activity feed:** Replace emoji badges with colored dot indicators + type label. Add relative time ("il y a 2h")
- **Recent documents:** Merge devis + factures into a single "Recent Documents" table with type column and status pill
- **Pipeline:** Expand to show funnel: Devis created → BDC sent → Facture generated → Paid. Show counts at each stage
- **Revenue chart:** Replace table with small bar chart (reuse Chart.js already loaded on analyses.php)
- **Layout:** Keep 2-column but improve proportions (65/35 split)

---

### 3. CLIENTELE (`clientele.php`)

**Current weaknesses:**
- Client list items are plain cards with no visual distinction (no avatar colors, no status indicators)
- Info grid cards (phones, emails, address) are visually identical — hard to differentiate
- Notes section looks detached from client context
- Document tabs have thin styling — active state is subtle
- Search bar has no filter options (e.g., by city, by date added)
- No client count indicator in the list header
- Edit toolbar icons (pencil, trash) are raw emoji

**Proposed redesign:**
- **Client list:** Add colored initials avatar (already exists but underused). Show phone preview + city in the list item. Add "last activity" date
- **Client header:** Larger avatar with initials, client name prominent, action buttons styled consistently (call, email, edit, delete)
- **Info sections:** Use labeled fieldset-style cards with distinct headers: "Coordonnees", "Adresse", "Notes". Add icons before labels
- **Document tabs:** Bolder active state with colored underline (not just background). Add document count badges on each tab
- **Search:** Add count badge "X clients trouvés". Add alphabetical letter nav for quick jump

---

### 4. ADD CLIENT (`ajout_client.php`)

**Current weaknesses:**
- Column layout is counterintuitive — identity (name) is on the RIGHT, but phones (secondary info) are on the LEFT
- No visual step progression
- Dynamic phone/email rows use emoji trash button (🗑)
- Missing viewport meta tag
- Submit button at bottom-left is easy to miss

**Proposed redesign:**
- **Reorder columns:** Identity (Nom, Prenom) on LEFT (primary info first), Contact details (phones, emails) on RIGHT
- **Group fields visually:** Use subtle section headers — "Identite", "Coordonnees", "Adresse", "Notes"
- **Dynamic rows:** Replace emoji trash with styled icon button. Add transition when adding/removing rows
- **Submit area:** Full-width sticky footer bar with submit button and cancel link. Make it clearly visible
- **Add viewport meta tag** for responsive behavior

---

### 5. DEVIS/FACTURES/BDC (`devis.php`)

**Current weaknesses:**
- The page tries to do too much — document listing AND complex quote creation form on the same page
- Quote builder (piece/PAC editor) is complex but lacks visual clarity between pieces/sections
- PAC search autocomplete dropdown has basic styling
- Tabs for Devis/BDC/Factures are visually weak
- Total display at bottom is scattered — multiple chips without clear hierarchy
- No document status indicators (draft, sent, paid, etc.)

**Proposed redesign:**
- **Clear page sections:** Separate "Mes documents" (top) from "Creer un devis" (bottom) with clear visual break
- **Document tabs:** Bold underline-style tabs with count badges
- **Document tables:** Add status pills (Brouillon, Envoyé, Accepté, Refusé). Add row hover actions
- **Quote builder:**
  - Each "Piece" as a visually distinct card with colored header
  - Material search with better autocomplete (show description + price inline)
  - Clear add/remove buttons with icons
  - Running total per piece, then grand total in a highlighted summary bar
- **Total summary:** Sticky summary bar at bottom showing HT / TVA / TTC in clear columns

---

### 6. BROCHURES (`brochures.php`)

**Current weaknesses:**
- Table has too many columns (8) making it cramped
- Preview thumbnails are small (80x80) and hard to see
- Inline description editing via `<details>` is clever but hidden/non-obvious
- File type shown as raw MIME string (application/pdf) — unfriendly
- Delete action in last column is red button that looks alarming

**Proposed redesign:**
- **Card grid view option** (toggle between table and grid) — grid shows larger preview with title overlay
- **File type:** Replace MIME with friendly labels + icons (PDF icon, Image icon, Document icon)
- **Description editing:** Use inline edit with pencil icon that transforms to edit mode (not hidden in details)
- **Table improvements:** Merge preview + title into one column. Right-align file size. Use dropdown for actions (View, Download, Delete)
- **Upload area:** Drag-and-drop zone with dashed border visual cue

---

### 7. MATERIALS (`ajout_pac.php`)

**Current weaknesses:**
- Category management UI is cramped — add and rename forms stacked inside cards
- Category chips (▲▼🗑) use plain text arrows and emoji
- Material table description column can be very long
- Drag handle (☰) is subtle
- No unit indicator for price (€ HT vs TTC unclear)

**Proposed redesign:**
- **Category management:** Cleaner layout — add category as inline input, rename via double-click or edit icon. Reorder with clear up/down arrow buttons (styled)
- **Material cards:** Show price with "€ HT" label clearly. Truncate description with expand-on-click
- **Drag reorder:** More visible drag handle with grab cursor. Show drop zone highlight more prominently
- **Form improvement:** Price field with "€ HT" suffix inline. Category selector with color dots

---

### 8. BANK ACCOUNTS (`manage_bank.php`)

**Current weaknesses:**
- Most basic page in the app — minimal styling
- Form has no visual grouping
- Table shows raw ID column (unnecessary for users)
- Action icons (✎ and 🗑️) are unstyled emoji
- Submit button has no class — uses default browser styling
- Checkbox for "Actif" is unstyled
- No visual distinction between active and inactive accounts

**Proposed redesign:**
- **Match the layout pattern** used by ajout_pac.php and gestion_comptes.php (2-column top: form + info, list below)
- **Remove ID column** from table
- **Active status:** Use toggle switch or colored status pill (green = active, gray = inactive)
- **Action buttons:** Styled btn-secondary for edit, btn-danger for delete with confirmation
- **Form:** Apply `.bank-form` styles properly with section grouping. Add IBAN formatting hint
- **IBAN display:** Masked/formatted display (FR76 XXXX XXXX ...) for readability

---

### 9. ANALYTICS (`analyses.php`)

**Current weaknesses:**
- Charts are useful but all look identical (same blue line) — hard to differentiate
- View selector is a basic dropdown — feels like an afterthought
- Revenue table at bottom is plain
- No summary KPIs at top (total quotes this period, total invoices, etc.)
- Badge showing "Perimetre: 31 derniers jours" is easy to miss

**Proposed redesign:**
- **Summary KPIs row** at top: Total devis (count + amount), Total factures (count + amount), Conversion rate, Average invoice value
- **View selector:** Styled pill/tab bar instead of dropdown (Day | Month | Year | All)
- **Chart colors:** Different colors per chart — blue for devis, green for factures. Fill area under lines for visual weight
- **Revenue table:** Highlight current year row. Add year-over-year change indicator (↑ +12%)
- **Layout:** KPIs → Charts (2x2 grid) → Revenue summary in a clear vertical flow

---

### 10. USER ACCOUNTS (`gestion_comptes.php`)

**Current weaknesses:**
- Well-structured (follows ajout_pac.php pattern) — fewest issues
- Inline role change form in table cells is functional but visually busy
- Help section at bottom of password card is useful but could be more prominent
- No user avatar/initials in the list

**Proposed redesign:**
- **User table:** Add initials avatar. Show role as colored pill (Admin = blue, User = gray)
- **Role change:** Simplify — click role pill to open a small dropdown, auto-save
- **Help section:** Move to a collapsible info banner at top of page
- **Password form:** Add password strength indicator

---

# PHASE 3: GLOBAL UI GUIDELINE (Design System)

## Color Palette (Refined)

| Token | Current | Proposed | Usage |
|-------|---------|----------|-------|
| `--accent` | `#1976d2` | `#2563eb` | Primary actions, links, active states |
| `--accent-light` | (none) | `#dbeafe` | Primary backgrounds, hover states |
| `--accent-2` | `#2e7d32` | `#16a34a` | Success, payments received, active |
| `--accent-3` | `#2980b9` | remove | Consolidate to --accent |
| `--warning` | `#ffb300` | `#f59e0b` | Warnings, pending states, unpaid |
| `--danger` | `#c62828` | `#dc2626` | Errors, destructive actions |
| `--bg` | `#e8eef6` | `#f8fafc` | Lighter, less blue-tinted |
| `--bg-2` | `#f3f6fb` | `#f1f5f9` | Secondary background |
| `--ink` | `#1f2a37` | `#0f172a` | Slightly darker for contrast |
| NEW `--ink-secondary` | — | `#475569` | Secondary text (replaces various gray hardcodes) |

## Typography

| Element | Current | Proposed |
|---------|---------|----------|
| Font stack | System fonts | `Inter, -apple-system, ...` (add Inter as first choice) |
| H1 | centered, #0f2b5b, weight 800 | Left-aligned (except login), weight 700, --ink |
| Body text | 14px default | 14px (keep), line-height 1.5 (increase from 1.45) |
| Labels | 0.95rem, #0f2b5b, weight 700 | 0.875rem, --ink-secondary, weight 600 |
| Monospace | System monospace | `JetBrains Mono, ui-monospace, ...` |

## Spacing Scale

| Token | Current | Proposed |
|-------|---------|----------|
| `--gap-1` | 6px | 4px |
| `--gap-2` | 10px | 8px |
| `--gap-3` | 16px | 16px (keep) |
| `--gap-4` | 24px | 24px (keep) |
| NEW `--gap-5` | — | 32px |
| NEW `--gap-6` | — | 48px |

## Border Radius

| Token | Current | Proposed |
|-------|---------|----------|
| `--r-xs` | 6px | 6px (keep) |
| `--r-sm` | 8px | 8px (keep) |
| `--r-md` | 12px | 12px (keep) |
| `--r-lg` | 16px | 16px (keep) |
| NEW `--r-full` | — | 9999px (pills) |

## Shadows (Refined)

| Token | Current | Proposed |
|-------|---------|----------|
| `--shadow-1` | `0 2px 8px rgba(2,6,23,.06)` | `0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04)` |
| `--shadow-2` | `0 10px 24px rgba(2,6,23,.10)` | `0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.04)` |
| NEW `--shadow-3` | — | `0 12px 24px rgba(0,0,0,.10)` (elevated modals) |

## Component Standards

### Buttons
- **Primary** (`.btn-primary`): Solid accent color, white text. Used for main page actions (1 per section max)
- **Secondary** (`.btn`): Light background, dark text, subtle border. Used for secondary actions
- **Danger** (`.btn-danger`): Only for destructive actions. Always requires confirmation
- **Ghost** (`.btn-ghost`): No background, just text color. For tertiary/inline actions
- **Size:** Default padding 10px 16px, small variant 6px 12px

### Tables
- Header: `--bg-2` background, weight 600 (not 800), uppercase small text
- Row height: minimum 48px (touch-friendly)
- Hover: subtle `--accent-light` background
- Zebra: alternate rows at 50% opacity of `--bg-2`
- Actions column: icon buttons or dropdown, right-aligned

### Cards
- Background: white
- Border: 1px solid `--bd`
- Shadow: `--shadow-1`
- Radius: `--r-md`
- Padding: 20px (increase from 16px)
- Header: 18px weight 600 with optional icon, bottom border separator

### Forms
- Labels: above input, 600 weight, `--ink-secondary` color
- Inputs: 44px min height, 12px padding, visible focus ring
- Required indicator: red asterisk after label
- Hint text: below input, 12px, muted color
- Fieldsets: grouped with light background card + section title
- Submit area: right-aligned or full-width depending on context

### Status Pills
- Paid/Active: green background (#dcfce7), green text (#166534)
- Pending: yellow background (#fef3c7), yellow text (#92400e)
- Draft: gray background (#f3f4f6), gray text (#374151)
- Overdue/Error: red background (#fee2e2), red text (#991b1b)

### Icons
- Replace all emoji with a consistent icon approach
- Recommendation: **Lucide Icons** (lightweight SVG icon set, no build tools needed — can be loaded via CDN or inline SVG)
- Fallback: CSS-only pseudo-element icons where possible

### Empty States
- Centered illustration/icon + message + CTA button
- Example: "Aucun client trouvé" → search icon + "Aucun résultat" + "Ajouter un client" button

## Reusable Components to Create

1. **Page Header** — title + breadcrumb + primary action button
2. **Stat Card** — icon + label + value + trend indicator (reusable KPI)
3. **Data Table** — standardized table with sort indicators, pagination footer, bulk actions
4. **Status Pill** — colored pill with label (paid, pending, draft, etc.)
5. **Action Dropdown** — "..." menu that shows edit/delete/duplicate options
6. **Section Card** — card with title bar + body, used for form groups
7. **Search Bar** — standardized search input with filter pills
8. **Tab Bar** — consistent tab component with count badges
9. **Empty State** — reusable empty state with icon + message + CTA
10. **Toast Notification** — success/error feedback after actions

---

# PHASE 4: IMPLEMENTATION ORDER (when approved)

- [ ] 1. Consolidate all inline styles into style.css + create design token updates
- [ ] 2. Create reusable CSS component classes (page-header, stat-card, status-pill, etc.)
- [ ] 3. Redesign sidebar (icons, logo, active states, mobile behavior)
- [ ] 4. Redesign login.php (branded, uses style.css)
- [ ] 5. Redesign index.php (dashboard KPIs, remove inline styles, activity feed)
- [ ] 6. Redesign manage_bank.php (simplest page — good test case)
- [ ] 7. Redesign gestion_comptes.php (already well-structured)
- [ ] 8. Redesign brochures.php (upload area, table improvements)
- [ ] 9. Redesign ajout_pac.php (material management, categories)
- [ ] 10. Redesign ajout_client.php (form layout fix)
- [ ] 11. Redesign clientele.php (CRM view, tabs, info cards)
- [ ] 12. Redesign devis.php (most complex page — last)
- [ ] 13. Redesign analyses.php (chart improvements, KPIs)
- [ ] 14. Cross-page QA — verify consistency, responsive behavior, print styles

---

## Review Notes
*(To be filled after implementation)*
