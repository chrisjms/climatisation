/* =========================================================
   Devis – Logique front (ENERGIA)
   Fichier : /assets/js/devis.js
   ---------------------------------------------------------
   Dépend des variables globales injectées par devis.php :
     - window.pacData : [{id, code, nom, prix, quantite_defaut?}, ...]
     - window.__prefill : (optionnel) données de reprise (copie de devis)
   ========================================================= */

const pacData = Array.isArray(window.pacData) ? window.pacData : [];
let pieceCounter = 0;
const newPieceKey = () => { pieceCounter++; return 'p' + pieceCounter; };
const round2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
const fmt2   = (n) => Number(n).toFixed(2);

/* ---------- Helpers ---------- */
function hl(text, q) {
  if (!q) return text;
  const i = text.toLowerCase().indexOf(q.toLowerCase());
  if (i < 0) return text;
  return (
    text.substring(0, i) +
    '<span class="hl">' +
    text.substring(i, i + q.length) +
    '</span>' +
    text.substring(i + q.length)
  );
}

/* =========================================================
   Construction d’une PIÈCE
   ========================================================= */
function addPiece(defaultName = 'Pièce', prefilledItems = []) {
  const pieceKey     = newPieceKey();
  const piecesHolder = document.getElementById('pieces-holder');

  const card = document.createElement('div');
  card.className = 'piece-card';
  card.dataset.pieceKey = pieceKey;

  // Titre + actions
  const pieceNameInput = document.createElement('input');
  pieceNameInput.type = 'text';
  pieceNameInput.name = `pieces[${pieceKey}][nom]`;
  pieceNameInput.placeholder = "Nom de la pièce (ex. Salon, Cuisine)";
  pieceNameInput.value = defaultName || '';

  const titleWrap = document.createElement('div');
  titleWrap.className = 'piece-title-wrap';
  const titleLabel = document.createElement('label');
  titleLabel.textContent = 'Pièce :';
  titleLabel.style.marginTop = '0';
  titleWrap.append(titleLabel, pieceNameInput);

  const actions = document.createElement('div');
  actions.className = 'piece-actions';

  const addMatBtn = document.createElement('button');
  addMatBtn.type = 'button';
  addMatBtn.className = 'btn-outline';
  addMatBtn.textContent = '➕ Ajouter un matériel';
  addMatBtn.onclick = () => addPacRow(card.querySelector('.lines-container'), pieceKey);

  const removePieceBtn = document.createElement('button');
  removePieceBtn.type = 'button';
  removePieceBtn.className = 'btn-danger';
  removePieceBtn.textContent = '🗑️ Supprimer la pièce';
  removePieceBtn.onclick = () => {
    const countRows = card.querySelectorAll('.pac-group').length;
    if (countRows > 0 && !confirm('Supprimer cette pièce et tous ses matériels ?')) return;
    card.remove();
    updateTotal();
  };

  actions.append(addMatBtn, removePieceBtn);

  const head = document.createElement('div');
  head.className = 'piece-head';
  head.append(titleWrap, actions);

  // En-têtes colonnes (6) : [Recherche] | Nom | Qté | Prix | TVA | X
  const heads = document.createElement('div');
  heads.className = 'pac-heads';
  heads.innerHTML = `
    <span>Code/Matériel (recherche)</span>
    <span>Nom sur devis</span>
    <span>Quantité</span>
    <span>Prix (€ HT)</span>
    <span>TVA</span>
    <span></span>
  `;

  const linesContainer = document.createElement('div');
  linesContainer.className = 'lines-container';

  const pieceTotals = document.createElement('div');
  pieceTotals.className = 'piece-total';
  pieceTotals.innerHTML = 'Sous-total pièce — HT: <span class="subtotal-ht">0.00 €</span> • TTC: <span class="subtotal-ttc">0.00 €</span>';

  card.append(heads, linesContainer, pieceTotals);
  card.insertBefore(head, heads);
  piecesHolder.appendChild(card);

  if (Array.isArray(prefilledItems) && prefilledItems.length) {
    prefilledItems.forEach(it => {
      addPacRow(
        linesContainer, pieceKey,
        it.pac_id ? String(it.pac_id) : '',
        (typeof it.quantite !== 'undefined' ? Number(it.quantite) : null),
        it.libelle || '',
        (typeof it.prix_ht !== 'undefined' ? Number(it.prix_ht) : ''),
        (typeof it.tva !== 'undefined' ? Number(it.tva) : 20)
      );
    });
  } else {
    addPacRow(linesContainer, pieceKey);
  }
  return card;
}

/* =========================================================
   Construction d’une LIGNE (matériel)
   ========================================================= */
function addPacRow(
  containerEl,
  pieceKey,
  defaultId = '',
  defaultQty = null,
  defaultLabel = '',
  defaultPrice = '',
  defaultTva = 20
) {
  if (!containerEl) return;

  const row = document.createElement('div');
  row.className = 'pac-group';

  // Hidden: clé de pièce
  const hiddenPieceKey = document.createElement('input');
  hiddenPieceKey.type = 'hidden';
  hiddenPieceKey.name = 'piece_keys[]';
  hiddenPieceKey.value = pieceKey;
  row.appendChild(hiddenPieceKey);

  /* -------------------- RECHERCHE UNIFIÉE -------------------- */
  const wrapUni = document.createElement('div');
  wrapUni.className = 'pac-select-wrap';

  const searchUniRow = document.createElement('div');
  searchUniRow.className = 'pac-search-row';

  const searchUni = document.createElement('input');
  searchUni.type = 'text';
  searchUni.className = 'pac-search-uni';
  searchUni.placeholder = 'Rechercher par code ou par nom…';

  const listBtnUni = document.createElement('button');
  listBtnUni.type = 'button';
  listBtnUni.className = 'list-btn';
  listBtnUni.title = 'Voir la liste complète';
  listBtnUni.textContent = '📋 Liste';

  searchUniRow.append(searchUni, listBtnUni);

  const suggUni    = document.createElement('div'); suggUni.className = 'pac-suggestions';
  const listAllUni = document.createElement('div'); listAllUni.className = 'pac-list-all';

  wrapUni.append(searchUniRow, suggUni, listAllUni);

  /* -------------------- SELECT canonique + code hidden -------------------- */
  const select = document.createElement('select');
  select.name  = 'pac_ids[]';
  select.required = true;
  select.style.display = 'none';
  select.appendChild(new Option('-- Choisir un matériel --', ''));
  pacData.forEach(pac => {
    const opt = new Option(pac.nom, pac.id);
    opt.dataset.code = pac.code || '';
    opt.dataset.prix = pac.prix;
    opt.dataset.qdef = (pac.quantite_defaut ?? 1);
	opt.dataset.desc = pac.description || '';
    select.appendChild(opt);
  });

  const codeHidden = document.createElement('input');
  codeHidden.type = 'hidden';
  codeHidden.name = 'codes[]';
  codeHidden.className = 'pac-code';
  codeHidden.value = '';

  /* -------------------- LIBELLÉ / QTE / PRIX / TVA / SUPPR -------------------- */
  const libelle = document.createElement('input');
  libelle.type = 'text';
  libelle.name = 'libelles[]';
  libelle.placeholder = 'Nom affiché sur le devis';
  libelle.value = defaultLabel || '';
  if (defaultLabel) libelle.dataset.touched = '1';
  libelle.oninput = () => { libelle.dataset.touched = '1'; };

  const qty = document.createElement('input');
  qty.type = 'number';
  qty.name = 'quantites[]';
  qty.className = 'pac-qty';
  qty.min = '1';
  qty.step = '1';
  qty.value = (defaultQty === null || typeof defaultQty === 'undefined') ? '' : String(parseInt(defaultQty, 10));
  qty.required = true;
  qty.addEventListener('input', updateTotal);
  qty.addEventListener('blur', () => { clampQty(qty); updateTotal(); });

  const prix = document.createElement('input');
  prix.type = 'number';
  prix.name = 'prix[]';
  prix.className = 'pac-price';
  prix.step = '0.01';
  prix.placeholder = 'Prix (€ HT)';
  prix.required = true;
  if (defaultPrice !== '') prix.value = Number(defaultPrice).toFixed(2);
  prix.addEventListener('input', updateTotal);

  const tva = document.createElement('select');
  tva.name = 'tva_taux[]';
  tva.className = 'pac-tva';
  [['20','20 %'], ['10','10 %'], ['0.0','0 %']].forEach(([val, label]) => tva.appendChild(new Option(label, val)));
  const tvaVal = (Number(defaultTva) === 0 ? '0.0' : (Number(defaultTva) === 10 ? '10' : '20'));
  tva.value = tvaVal;
  tva.addEventListener('change', updateTotal);

  const btn = document.createElement('button');
  btn.type  = 'button';
  btn.className = 'remove-btn';
  btn.textContent = '🗑️';
  btn.title = 'Retirer cette ligne';
  btn.onclick = () => { row.remove(); updateTotal(); };

  // Ordre final : [Recherche unifiée] | Nom | Qté | Prix | TVA | X
  row.append(wrapUni, libelle, qty, prix, tva, btn, select, codeHidden);
  containerEl.appendChild(row);

  /* =========================================================
     SUGGESTIONS unifiées : plein texte sur code OU nom
     - Affichage : CODE | Matériel | Prix
     - Tri : priorité aux "startsWith", puis "includes"
     ========================================================= */
  function renderSuggestionsUnified(q) {
    const query = (q || '').trim().toLowerCase();
    suggUni.innerHTML = '';
    if (!query) { suggUni.style.display = 'none'; return; }

    const matches = pacData
      .map(p => ({
        p,
        score:
          (String(p.code || '').toLowerCase().startsWith(query) ? 1000 : 0) +
          (String(p.nom  || '').toLowerCase().startsWith(query) ?  500 : 0) +
          (String(p.code || '').toLowerCase().includes(query)   ?   50 : 0) +
          (String(p.nom  || '').toLowerCase().includes(query)    ?   20 : 0)
      }))
      .filter(x => x.score > 0)
      .sort((a,b) => b.score - a.score)
      .slice(0, 16)
      .map(x => x.p);

    if (matches.length === 0) { suggUni.style.display = 'none'; return; }

    matches.forEach(p => {
      const item = document.createElement('div'); item.className = 'item';
      item.innerHTML = `
        <span class="code">${hl(String(p.code || ''), query)}</span>
        <span class="name">${hl(String(p.nom  || ''),  query)}</span>
        <span class="price">${fmt2(p.prix)} €</span>
      `;
      item.onclick = () => applyPacToRow(row, p);
      suggUni.appendChild(item);
    });
    suggUni.style.display = 'block';
  }

  function renderListAllUnified() {
    const arr = [...pacData].sort((a,b) => String(a.nom || '').localeCompare(String(b.nom || '')));
    listAllUni.innerHTML = '';
    arr.forEach(p => {
      const item = document.createElement('div'); item.className = 'item';
      item.innerHTML = `
        <span class="code">${String(p.code || '')}</span>
        <span class="name">${String(p.nom  || '')}</span>
        <span class="price">${fmt2(p.prix)} €</span>
      `;
      item.onclick = () => { applyPacToRow(row, p); listAllUni.style.display='none'; };
      listAllUni.appendChild(item);
    });
    listAllUni.style.display = 'block';
  }

  /* =========================================================
     Sélection d’un matériel : applique partout
     - Affiche "CODE — Nom" dans le champ de recherche
     - Met à jour select, codeHidden, libellé (si vide), qté/prix
     ========================================================= */
  function applyPacToRow(row, p) {
    // select canonique
    if (!select.querySelector(`option[value="${String(p.id)}"]`)) {
      const o = new Option(p.nom, p.id);
      o.dataset.code = p.code || '';
      o.dataset.prix = p.prix;
      o.dataset.qdef = (p.quantite_defaut ?? 1);
      select.appendChild(o);
    }
    select.value = String(p.id);

    const prixEl = row.querySelector('.pac-price');
    const qtyEl  = row.querySelector('.pac-qty');
    const libEl  = row.querySelector('input[name="libelles[]"]');

    // Affichage champ : "CODE — Nom"
    const _code = String(p.code || '').trim();
    const _nom  = String(p.nom  || '').trim();
    searchUni.value = _code ? `${_code} — ${_nom}` : _nom;

    // code hidden
    codeHidden.value = _code;

    // qté/prix
    const qdef = p.quantite_defaut ? Number(p.quantite_defaut) : 1;
    if (!qtyEl.value || qtyEl.value === '' || qtyEl.value === '0') qtyEl.value = String(qdef);
    prixEl.value = fmt2(p.prix);

	// libellé si non touché → description si dispo, sinon nom
	const fallbackLib = (p.description && p.description.trim() !== '')
		? p.description.trim()
		: _nom;

	if (!libEl.dataset.touched || libEl.value.trim() === '') {
		libEl.value = fallbackLib;
	}

    // fermer suggestions
    suggUni.style.display = 'none'; listAllUni.style.display = 'none';

    updateTotal();
  }

  /* ---------- Écouteurs & init ---------- */
  searchUni.addEventListener('input', (e) => {
    renderSuggestionsUnified(e.target.value);
    listAllUni.style.display = 'none';
  });
  searchUni.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const first = suggUni.querySelector('.item'); if (first) first.click();
    } else if (e.key === 'Escape') {
      suggUni.style.display = 'none'; listAllUni.style.display = 'none';
    }
  });
  listBtnUni.addEventListener('click', (e) => {
    e.preventDefault();
    if (listAllUni.style.display === 'block') listAllUni.style.display = 'none';
    else { suggUni.style.display = 'none'; renderListAllUnified(); }
  });

  document.addEventListener('click', (e) => {
    if (!wrapUni.contains(e.target)) { suggUni.style.display = 'none'; listAllUni.style.display = 'none'; }
  });

  // Pré-sélection si defaultId
  if (defaultId) {
    let p = pacData.find(x => String(x.id) === String(defaultId));
    if (!p) {
      // Matériel archivé : on garde un libellé lisible
      const label = defaultLabel ? defaultLabel : `Matériel #${defaultId} (archivé)`;
      const o = new Option(label + ' (archivé)', String(defaultId));
      if (defaultPrice !== '' && !isNaN(Number(defaultPrice))) o.dataset.prix = String(defaultPrice);
      o.dataset.qdef = '1';
      select.appendChild(o);
      select.value = String(defaultId);
      searchUni.value = label;
      codeHidden.value = '';
    } else {
      applyPacToRow(row, p);
    }
  }

  // Si pas de prix par défaut → init depuis select s’il est déjà positionné
  updatePrixEtQty(row, defaultPrice);
}

/* =========================================================
   Calculs, helpers & initialisation
   ========================================================= */
function clampQty(input) {
  const raw = (input.value ?? '').trim();
  const n = parseInt(raw, 10);
  if (raw === '' || isNaN(n) || n < 1) input.value = '1';
  else input.value = String(n);
}

function updatePrixEtQty(row, forcedPrice = null) {
  const select     = row.querySelector('select[name="pac_ids[]"]');
  const opt        = select?.selectedOptions?.[0];
  const prixEl     = row.querySelector('.pac-price');
  const qtyEl      = row.querySelector('.pac-qty');
  const libEl      = row.querySelector('input[name="libelles[]"]');
  const codeHidden = row.querySelector('input.pac-code');
  const searchUni  = row.querySelector('.pac-search-uni');

  if (opt && opt.value) {
    const prix = opt.dataset.prix;
    const qdef = opt.dataset.qdef || '1';
    const code = opt.dataset.code || '';

    if (codeHidden) codeHidden.value = code;

    if (!qtyEl.value || qtyEl.value === '' || qtyEl.value === '0') qtyEl.value = qdef;

    if (forcedPrice !== null && forcedPrice !== '' && !isNaN(Number(forcedPrice))) {
      prixEl.value = fmt2(forcedPrice);
    } else if (prix) {
      prixEl.value = fmt2(prix);
    }

    if (!libEl.dataset.touched || libEl.value.trim() === '') libEl.value = opt.text;

    // Si le champ unifié est vide -> "CODE — Nom"
    if (searchUni && (!searchUni.value || searchUni.value.trim() === '')) {
      const _nom = opt.text.trim();
      const _code = (code || '').trim();
      searchUni.value = _code ? `${_code} — ${_nom}` : _nom;
    }
  } else {
    if (forcedPrice !== null && forcedPrice !== '' && !isNaN(Number(forcedPrice))) prixEl.value = fmt2(forcedPrice);
    else if (!prixEl.value) prixEl.value = '';
    if (!qtyEl.value) qtyEl.value = '';
    if (codeHidden) codeHidden.value = '';
    if (searchUni && !searchUni.value) searchUni.value = '';
  }

  updateTotal();
}

function updateTotal() {
  let totalHT = 0;
  let totalTTC = 0;
  const globalHTByRate = {};

  document.querySelectorAll('.piece-card').forEach(card => {
    const pieceHTByRate = {};
    let pieceHT = 0;
    let pieceTTC = 0;

    card.querySelectorAll('.pac-group').forEach(row => {
      const prix = parseFloat(row.querySelector('.pac-price')?.value || '0');
      const qtyRaw = (row.querySelector('.pac-qty')?.value ?? '').trim();
      let qty = (qtyRaw === '' ? 1 : parseInt(qtyRaw, 10));
      if (isNaN(qty) || qty < 1) qty = 1;
      const tvaV = parseFloat(row.querySelector('.pac-tva')?.value || '20');
      const lineHT = round2((isNaN(prix) ? 0 : prix) * qty);
      const rKey = String(isNaN(tvaV) ? 20 : tvaV);
      pieceHTByRate[rKey] = round2((pieceHTByRate[rKey] || 0) + lineHT);
      globalHTByRate[rKey] = round2((globalHTByRate[rKey] || 0) + lineHT);
    });

    Object.keys(pieceHTByRate).forEach(key => {
      const rate = parseFloat(key);
      const ht   = pieceHTByRate[key];
      pieceHT = round2(pieceHT + ht);
      pieceTTC = round2(pieceTTC + round2(ht * (1 + (isNaN(rate) ? 0.20 : (rate / 100)))));
    });

    totalHT = round2(totalHT + pieceHT);
    const subHTEl  = card.querySelector('.subtotal-ht');
    const subTTCEl = card.querySelector('.subtotal-ttc');
    if (subHTEl)  subHTEl.textContent  = fmt2(pieceHT) + ' €';
    if (subTTCEl) subTTCEl.textContent = fmt2(pieceTTC) + ' €';
  });

  Object.keys(globalHTByRate).forEach(key => {
    const rate = parseFloat(key);
    const ht   = globalHTByRate[key];
    totalTTC = round2(totalTTC + round2(ht * (1 + (isNaN(rate) ? 0.20 : (rate / 100)))));
  });

  const totalEl = document.getElementById('total');
  if (totalEl) totalEl.textContent = fmt2(totalHT) + ' €';
  const totalTTCEl = document.getElementById('total_ttc');
  if (totalTTCEl) totalTTCEl.textContent = fmt2(totalTTC) + ' €';

  const m1 = document.getElementById('montant_paiement_1');
  if (m1) m1.value = fmt2(totalTTC);

  const acompteEl = document.getElementById('acompte');
  if (acompteEl && acompteEl.dataset.touched !== '1') acompteEl.value = fmt2(totalTTC * 0.40);

  return { totalHT, totalTTC };
}

function syncDateCreation() {
  const inputDate = document.getElementById('date_creation_date');
  const hidden    = document.getElementById('date_creation_hidden');
  if (!inputDate || !hidden) return;
  const d = (inputDate.value || '').trim();
  hidden.value = d ? (d + 'T00:00') : '';
}

function prefillFromData(data) {
  if (!data) return;
  const selClient = document.getElementById('client_id');
  if (selClient && data.client_id) selClient.value = String(data.client_id);
  const desc = document.getElementById('description');
  if (desc) desc.value = data.description || '';

  const holder = document.getElementById('pieces-holder');
  holder.innerHTML = '';
  if (Array.isArray(data.pieces) && data.pieces.length > 0) {
    data.pieces.forEach((p, i) => {
      const nm = (p && typeof p.nom === 'string' && p.nom.trim() !== '') ? p.nom : ('Pièce ' + (i+1));
      const items = Array.isArray(p.items) ? p.items : [];
      addPiece(nm, items);
    });
  } else {
    addPiece('Pièce', Array.isArray(data.items) ? data.items : []);
  }
  const copiedFrom = document.getElementById('copied_from_id');
  if (copiedFrom && data.copy_from_id) copiedFrom.value = String(data.copy_from_id);
  updateTotal();
  if (data.source) {
    const b = document.getElementById('source-badge');
    if (b) b.textContent = data.source;
  }
}

function normalizeZeroTvaBeforeSubmit() {
  document.querySelectorAll('select.pac-tva').forEach(sel => {
    if (sel && (sel.value === '0' || sel.value === 0)) sel.value = '0.0';
  });
}

/* =========================================================
   Boot
   ========================================================= */
document.addEventListener('DOMContentLoaded', () => {
  const addBtn = document.getElementById('add-piece-btn');
  if (addBtn) addBtn.addEventListener('click', (e) => { e.preventDefault(); addPiece('Pièce'); });

  const pre = window.__prefill || null;
  if (pre) prefillFromData(pre);
  else addPiece('');

  const acompteEl = document.getElementById('acompte');
  if (acompteEl) acompteEl.addEventListener('input', () => { acompteEl.dataset.touched = '1'; });

  const inputDate = document.getElementById('date_creation_date');
  if (inputDate) { inputDate.addEventListener('change', syncDateCreation); syncDateCreation(); }

  const form = document.getElementById('form-devis');
  if (form) {
    form.addEventListener('submit', () => {
      // Quantités min
      document.querySelectorAll('.pac-qty').forEach(clampQty);
      // Supprimer pièces vides
      document.querySelectorAll('.piece-card').forEach(card => {
        if (card.querySelectorAll('.pac-group').length === 0) card.remove();
      });
      // Normaliser TVA 0 → "0.0"
      normalizeZeroTvaBeforeSubmit();
      // Sync date
      syncDateCreation();
      // Pousser le TTC vers le montant du paiement 1
      const totals = updateTotal();
      const m1 = document.getElementById('montant_paiement_1');
      if (m1) m1.value = fmt2(totals.totalTTC);
      try { localStorage.setItem('devis_created', '1'); } catch(e){}
	// Rafraichir après submit  
    localStorage.setItem('devis_created', '1');
    setTimeout(() => location.reload(), 50)
    });
  }

  // Rafraîchissement après création (onglet PDF)
  (function () {
    const KEY = 'devis_created';
    function reloadIfFlag() {
      try {
        if (localStorage.getItem(KEY) === '1') {
          localStorage.removeItem(KEY);
          location.reload();
        }
      } catch(e) {}
    }
    window.addEventListener('focus', reloadIfFlag);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) reloadIfFlag(); });
    window.addEventListener('storage', (e) => {
      if (e.key === KEY && e.newValue === '1') reloadIfFlag();
    });
  })();
});