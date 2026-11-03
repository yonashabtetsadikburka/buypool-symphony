import { apiGet } from '../api.js';
import { API_URL, ACCONTO_PERCENT } from '../constants.js';
import { comeFunzionaHtml, initComeFunziona } from '../components/hero-come-funziona.js';
import { setPageInterval } from '../page-timers.js';
import { starsMedia } from '../stars.js';
import { euro, recensioni } from '../format.js';
import { accontoStima } from '../saldo-stima.js';
import { scontoMassimo as scontoMax, prezzoMostrato, prezzoBloccoHtml, percorsoHtml, badgeScontoHtml, pillPersoneHtml, iconaOrologio } from '../components/gruppo-card.js';
import { sogliaPrima, isScaduta, statoCard, isTerminato, normalizzaStato, dataFine } from '../stato-card.js';

/* Costanti filtro (facili da modificare) */
const SOGLIA_QUASI_TRAGUARDO = 0.7; // 70% dell'obiettivo corrente
const ORE_SCADENZA = 72;            // finestra "In scadenza"

const PILL_STATO = [
  { id: 'tutti', label: 'Tutti' },
  { id: 'quasi', label: 'Quasi al traguardo' },
  { id: 'scadenza', label: 'In scadenza' },
  { id: 'attive', label: 'Gruppi attivi' },
  { id: 'conclusi', label: 'Conclusi' }
];

const ORDINI = [
  { id: 'traguardo', label: 'Più vicini al traguardo' },
  { id: 'scadenza', label: 'In scadenza' },
  { id: 'recenti', label: 'Più recenti' },
  { id: 'sconto', label: 'Sconto maggiore' }
];

const SENZA_CATEGORIA = '__senza__';

let homeCampaigns = [];
const cardImgs = {};

/* Stato filtri (sincronizzato con la query string della route) */
const filtro = { q: '', stato: 'tutti', categoria: [], fornitore: [], ordina: 'traguardo' };
let homeScaduteGestite = false;
let homeListenerAttivo = false;

function imgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + path.replace(/^\//, '');
}

const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/* ============================ Logica di dominio (solo lettura) ============================ */

function scaglioniDi(c) { return Array.isArray(c.scaglioni) ? c.scaglioni : []; }

/** Prossimo gradino non raggiunto; null quando tutti sono raggiunti. */
function obiettivoCorrente(c) {
  const s = scaglioniDi(c);
  const qty = parseInt(c.quantita_attuale) || 0;
  const lista = s.length ? s : [{ soglia: sogliaPrima(c), prezzo: parseFloat(c.prezzo_corrente) || 0 }];
  for (const sg of lista) if (qty < sg.soglia) return sg;
  return null;
}

/** Avanzamento rispetto ALL'obiettivo corrente (0..1). */
function pctObiettivo(c) {
  const qty = parseInt(c.quantita_attuale) || 0;
  const ob = obiettivoCorrente(c);
  if (!ob) return 1;
  return ob.soglia > 0 ? Math.min(1, qty / ob.soglia) : 0;
}

/** "18g 12h" — null se la data non è valida o la scadenza è passata. */
function formatScade(target, now = Date.now()) {
  const t = new Date(target).getTime();
  if (!Number.isFinite(t)) return null;
  const d = t - now;
  if (d <= 0) return null;
  const g = Math.floor(d / 86400000);
  const h = Math.floor((d % 86400000) / 3600000);
  const m = Math.floor((d % 3600000) / 60000);
  return `${g}g: ${h}h: ${m}m`;
}

/* ============================ Filtri ============================ */

function passaQ(c, q) {
  if (!q) return true;
  const n = q.toLowerCase();
  return (c.prodotto || '').toLowerCase().includes(n) || (c.fornitore || '').toLowerCase().includes(n);
}

function passaStato(c, stato) {
  // Vista predefinita e filtri "aperti": mai i gruppi terminati.
  if (stato === 'conclusi') return isTerminato(c);
  if (isTerminato(c)) return false;
  switch (stato) {
    case 'quasi': return pctObiettivo(c) >= SOGLIA_QUASI_TRAGUARDO;
    case 'scadenza': {
      const d = new Date(c.data_limite).getTime() - Date.now();
      return Number.isFinite(d) && d > 0 && d <= ORE_SCADENZA * 3600 * 1000;
    }
    case 'attive': return (parseInt(c.quantita_attuale) || 0) >= sogliaPrima(c);
    default: return true;
  }
}

function passaCategoria(c, sel) {
  if (!sel.length) return true;
  return sel.some(v => v === SENZA_CATEGORIA ? !c.categoria : (c.categoria || '') === v);
}

function passaFornitore(c, sel) {
  if (!sel.length) return true;
  return sel.some(v => (c.fornitore || '') === v);
}

/** AND tra gruppi diversi, OR dentro lo stesso gruppo. `escludi` = nome gruppo da ignorare. */
function passaFiltri(c, f = filtro, escludi = '') {
  if (!passaQ(c, f.q)) return false;
  if (escludi !== 'stato' && !passaStato(c, f.stato)) return false;
  if (escludi !== 'categoria' && !passaCategoria(c, f.categoria)) return false;
  if (escludi !== 'fornitore' && !passaFornitore(c, f.fornitore)) return false;
  return true;
}

function ordinaCampagne(list, ordina) {
  const a = [...list];
  if (ordina === 'traguardo' && a.length && a.every(isTerminato)) return a.sort((x, y) => dataFine(y) - dataFine(x));
  const data = (c) => new Date(c.data_limite).getTime() || 0;
  if (ordina === 'scadenza') a.sort((x, y) => data(x) - data(y));
  else if (ordina === 'recenti') a.sort((x, y) => (new Date(y.data_inizio).getTime() || 0) - (new Date(x.data_inizio).getTime() || 0));
  else if (ordina === 'sconto') a.sort((x, y) => scontoMax(y) - scontoMax(x));
  else a.sort((x, y) => (pctObiettivo(y) - pctObiettivo(x)) || (data(x) - data(y)));
  return a;
}

/* ============================ Stato/dati dai filtri ============================ */

function filtrate() {
  return homeCampaigns.filter(c => passaFiltri(c));
}

function conteggioPill(stato) {
  return homeCampaigns.filter(c => passaFiltri(c, filtro, 'stato') && passaStato(c, stato)).length;
}

/** Valori distinti del gruppo con conteggio (faceting: esclude il proprio gruppo). */
function valoriConConteggio(gruppo) {
  const chiave = gruppo === 'categoria' ? 'categoria' : 'fornitore';
  const m = new Map();
  homeCampaigns.forEach(c => {
    if (!passaFiltri(c, filtro, gruppo)) return;
    let v = c[chiave] || '';
    if (gruppo === 'categoria' && !v) v = SENZA_CATEGORIA;
    if (gruppo === 'fornitore' && !v) return;
    m.set(v, (m.get(v) || 0) + 1);
  });
  return [...m.entries()]
    .filter(([, n]) => n > 0)
    .sort((a, b) => a[0] === SENZA_CATEGORIA ? 1 : b[0] === SENZA_CATEGORIA ? -1 : a[0].localeCompare(b[0]));
}

/* ============================ URL (stato condivisibile + indietro/avanti) ============================ */

function leggiUrl() {
  const qs = (window.location.hash.split('?')[1] || '').split('#')[0];
  const p = new URLSearchParams(qs);
  filtro.q = p.get('q') || '';
  filtro.stato = normalizzaStato(p.get('stato') || 'tutti');
  filtro.categoria = p.getAll('categoria[]').concat(p.getAll('categoria'));
  filtro.fornitore = p.getAll('fornitore[]').concat(p.getAll('fornitore'));
  const ordina = p.get('ordina') || 'traguardo';
  filtro.ordina = ORDINI.some(x => x.id === ordina) ? ordina : 'traguardo';
}

function urlTarget() {
  const p = new URLSearchParams();
  if (filtro.q) p.set('q', filtro.q);
  if (filtro.stato !== 'tutti') p.set('stato', filtro.stato);
  filtro.categoria.forEach(v => p.append('categoria[]', v));
  filtro.fornitore.forEach(v => p.append('fornitore[]', v));
  if (filtro.ordina !== 'traguardo') p.set('ordina', filtro.ordina);
  const qs = p.toString();
  return '#/' + (qs ? '?' + qs : '');
}

function scriviUrl(replace = false) {
  const target = urlTarget();
  if (window.location.hash === target) return;
  try { history[replace ? 'replaceState' : 'pushState'](null, '', target); } catch (e) { window.location.hash = target; }
}

/* Ricerca live nella barra header (debounce): filtra l'elenco mentre si scrive. */
function ricercaLiveApplica(q) {
  const v = (q || '').trim();
  if (v === filtro.q) return false;
  filtro.q = v;
  renderAll();
  renderPannelli();
  scriviUrl(true);
  return true;
}

let bpSearchTimer = null;
window.headerSearchVivo = function() {
  clearTimeout(bpSearchTimer);
  bpSearchTimer = setTimeout(() => {
    if (!document.getElementById('campaigns-grid')) return;
    const input = document.getElementById('header-search-input');
    ricercaLiveApplica(input ? input.value : '');
  }, 250);
};

/* ============================ Card ============================ */

const CLASSE_CHIP = {
  aperto: 'bp-state-aperto', attivo: 'bp-state-attiva', aderito: 'bp-state-aderito',
  concluso: 'bp-state-concluso', non_attivato: 'bp-state-concluso'
};

function statoPillHtml(c) {
  const st = statoCard(c);
  return `<span class="bp-state-pill ${CLASSE_CHIP[st.id]}">${esc(st.chip)}</span>`;
}

/** "GG/MM" da una data ISO/MySQL; '' se non valida. */
function ggmm(v) {
  const d = new Date(v);
  if (!Number.isFinite(d.getTime())) return '';
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
}


/** Corpo della card per i gruppi terminati (sostituisce barra e scala prezzi). */
function terminatoHtml(c, st) {
  if (st.id === 'non_attivato') {
    return `<div class="bp-bar bp-bar-grigia" aria-hidden="true"><div class="bp-bar-fill"></div></div>
            <div class="bp-bar-text">Il gruppo non ha raggiunto il minimo: acconti rimborsati</div>`;
  }
  const pezzi = parseInt(c.quantita_attuale) || 0;
  const persone = parseInt(c.partecipanti) || 0;
  return `<div class="bp-group-esito">
            <span>${pezzi} unità &middot; ${persone} ${persone === 1 ? 'persona' : 'persone'}</span>
            <span>Il gruppo ha raggiunto il minimo</span>
            <strong>Prezzo finale ${euro(c.prezzo_corrente)} per unità</strong>
          </div>`;
}

function ctaHtml(c) {
  const st = statoCard(c);
  if (st.cta === 'vedi_gruppo') {
    return `<span class="btn bp-card-cta bp-card-cta-outline" aria-hidden="true">Vedi il gruppo</span>`;
  }
  if (st.cta === 'vedi_acquisto') {
    return `<a class="btn bp-card-cta bp-card-cta-outline" href="#/acquisti?tab=in-corso" onclick="event.stopPropagation()">Vedi il tuo acquisto</a>`;
  }
  // Acconto per 1 unità sul primo scaglione (stessa stima della finestra di adesione)
  const acconto = accontoStima(1, prezzoMostrato(c).primoPrezzo, ACCONTO_PERCENT);
  return `<span class="btn bp-card-cta" aria-hidden="true">Unisciti al gruppo${acconto > 0 ? ` &middot; acconto ${euro(acconto)}` : ''}</span>`;
}

/** Scadenza a destra della riga prezzo ("Scade tra …"); per i gruppi terminati "Concluso il GG/MM" / "Scaduto il GG/MM". */
function tempoHtml(c, st) {
  if (st.terminato) {
    const testo = st.id === 'concluso'
      ? `Concluso il ${ggmm(c.chiusura_data || c.data_limite)}`
      : `Scaduto il ${ggmm(c.data_limite)}`;
    return `<span class="bp-deadline"><span class="bp-deadline-concluso">${testo}</span></span>`;
  }
  return `<span class="bp-deadline" data-scadenza="${c.data_limite || ''}">
            <span class="bp-deadline-label">${iconaOrologio(14)}Scade tra</span>
            <span class="bp-deadline-live">${formatScade(c.data_limite) || '&mdash;'}</span>
          </span>`;
}

function campaignCardHtml(c) {
  const st = statoCard(c);
  const rating = c.fornitore_rating || { media: null, totale: 0 };
  const href = `#/campagne/${c.id}`;

  const imgs = (c.immagini && c.immagini.length ? c.immagini.map(i => i.url) : (c.immagine ? [c.immagine] : []));
  cardImgs[c.id] = imgs;
  const imgBlock = imgs.length === 0
    ? `<div class="bp-card-media bp-card-noimg">Foto prodotto</div>`
    : `<div class="bp-card-media card-carousel">
        <img id="card-img-${c.id}" src="${imgUrl(imgs[0])}" alt="${esc(c.prodotto || '')}" data-idx="0" />
        ${imgs.length > 1 ? `
        <button class="carousel-arrow carousel-prev" onclick="event.stopPropagation();cardCarousel(${c.id}, -1, event)" aria-label="Precedente">&#10094;</button>
        <button class="carousel-arrow carousel-next" onclick="event.stopPropagation();cardCarousel(${c.id}, 1, event)" aria-label="Successiva">&#10095;</button>
        <div class="carousel-dots" id="card-dots-${c.id}">${imgs.map((_, i) => `<span class="carousel-dot${i === 0 ? ' active' : ''}"></span>`).join('')}</div>` : ''}
      </div>`;

  const corpo = st.terminato
    ? `<div class="bp-group-box">${terminatoHtml(c, st)}</div>`
    : `<div class="gp-price-row">
          ${prezzoBloccoHtml(c)}
          ${tempoHtml(c, st)}
        </div>
        ${percorsoHtml(c)}`;

  return `
    <div class="card campaign-card bp-card${st.terminato ? ' bp-card-terminata' : ''}" onclick="window.location.hash='${href}'">
      <div class="bp-card-media-wrap">
        ${imgBlock}
        <span class="bp-card-state">${statoPillHtml(c)}</span>
        ${badgeScontoHtml(scontoMax(c), { spento: st.terminato })}
        ${pillPersoneHtml(c)}
      </div>
      <div class="bp-card-body">
        <div class="bp-card-title"><a class="bp-card-link" href="${href}">${esc(c.prodotto || 'Prodotto')}</a></div>
        <div class="bp-card-supplier">${esc(c.fornitore || 'Fornitore')}${rating.totale > 0
          ? ` <span class="bp-card-rating">${starsMedia(rating.media, 'var(--text-sm)')} <span>(${recensioni(rating.totale)})</span></span>` : ''}</div>
        ${corpo}
        <div class="bp-card-foot">
          ${st.terminato ? tempoHtml(c, st) : ''}
          ${ctaHtml(c)}
        </div>
      </div>
    </div>`;
}

window.cardCarousel = function(cid, dir, event) {
  if (event) event.stopPropagation();
  const imgs = cardImgs[cid] || [];
  if (imgs.length < 2) return;
  const el = document.getElementById('card-img-' + cid);
  if (!el) return;
  let idx = (parseInt(el.dataset.idx) || 0) + dir;
  idx = ((idx % imgs.length) + imgs.length) % imgs.length;
  el.dataset.idx = idx;
  el.src = imgUrl(imgs[idx]);
  document.querySelectorAll('#card-dots-' + cid + ' .carousel-dot').forEach((d, i) =>
    d.classList.toggle('active', i === idx));
};

/* ============================ Barra filtri ============================ */

function pillsHtml() {
  return PILL_STATO.map(p => {
    const attivo = filtro.stato === p.id;
    const n = conteggioPill(p.id);
    const etichetta = (p.id === 'tutti' || p.id === 'conclusi') ? `${p.label} &middot; ${n}` : p.label;
    const sep = p.id === 'conclusi' ? '<span class="bp-pill-sep" aria-hidden="true"></span>' : '';
    return `${sep}<button type="button" class="bp-pill${attivo ? ' bp-pill-active' : ''}" aria-pressed="${attivo}"
              onclick="bpPillStato('${p.id}')">${etichetta}</button>`;
  }).join('');
}

function pannelloHtml(gruppo) {
  if (gruppo === 'ordina') {
    return `
      <div class="bp-panel-list" role="listbox" aria-label="Ordina">
        ${ORDINI.map(o => `
          <button type="button" role="option" aria-selected="${filtro.ordina === o.id}"
                  class="bp-panel-option${filtro.ordina === o.id ? ' is-sel' : ''}"
                  onclick="bpScegliOrdina('${o.id}')">${o.label}</button>`).join('')}
      </div>`;
  }
  const valori = valoriConConteggio(gruppo);
  const selezionati = filtro[gruppo];
  const ricerca = gruppo === 'fornitore'
    ? `<div class="bp-panel-search"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
         <input type="search" class="bp-panel-search-input" placeholder="Cerca fornitore" aria-label="Cerca fornitore"
                oninput="bpFiltraRighe(this)" /></div>` : '';
  const righe = valori.length
    ? valori.map(([v, n]) => {
        const label = v === SENZA_CATEGORIA ? 'Senza categoria' : v;
        const checked = selezionati.includes(v) ? 'checked' : '';
        return `
          <label class="bp-panel-row">
            <input type="checkbox" value="${esc(v)}" ${checked}
                   onchange="bpToggleFiltro('${gruppo}', this.value, this.checked)" />
            <span class="bp-panel-row-label">${esc(label)}</span>
            <span class="bp-panel-row-count">${n}</span>
          </label>`;
      }).join('')
    : '<p class="bp-panel-empty">Nessun valore disponibile</p>';
  const totale = filtrate().length;
  return `
    ${ricerca}
    <div class="bp-panel-rows">${righe}</div>
    <div class="bp-panel-foot">
      <button type="button" class="bp-panel-reset" onclick="bpAzzeraGruppo('${gruppo}')">Azzera</button>
      <button type="button" class="bp-panel-apply" onclick="bpChiudiPannelli()">Mostra ${totale} ${totale === 1 ? 'gruppo' : 'gruppi'}</button>
    </div>`;
}

function renderPannelli() {
  ['categoria', 'fornitore', 'ordina'].forEach(g => {
    ['panel-', 'mpanel-'].forEach(pref => {
      const el = document.getElementById(pref + g);
      if (!el) return;
      const y = el.scrollTop;
      const q = el.querySelector('.bp-panel-search-input')?.value || '';
      el.innerHTML = pannelloHtml(g);
      el.scrollTop = y;
      const si = el.querySelector('.bp-panel-search-input');
      if (q && si) { si.value = q; window.bpFiltraRighe(si); }
    });
  });
}

function labelOrdina() {
  return (ORDINI.find(o => o.id === filtro.ordina) || ORDINI[0]).label;
}

function renderDropdownLabels() {
  const nCat = filtro.categoria.length;
  const nFor = filtro.fornitore.length;
  const bCat = document.getElementById('dd-btn-categoria');
  const bFor = document.getElementById('dd-btn-fornitore');
  const bOrd = document.getElementById('dd-btn-ordina');
  if (bCat) bCat.innerHTML = nCat ? `Categoria &middot; ${nCat}` : 'Categoria';
  if (bFor) bFor.innerHTML = nFor ? `Fornitore &middot; ${nFor}` : 'Fornitore';
  if (bOrd) bOrd.innerHTML = `Ordina: ${labelOrdina()}`;
  [bCat, bFor, bOrd].forEach(b => b && b.classList.toggle('bp-dd-active',
    (b === bCat && nCat > 0) || (b === bFor && nFor > 0)));
  const sheetCount = document.getElementById('bp-sheet-count');
  if (sheetCount) sheetCount.textContent = `Mostra ${filtrate().length} ${filtrate().length === 1 ? 'gruppo' : 'gruppi'}`;
}

function renderPills() {
  const box = document.getElementById('bp-pills');
  if (box) box.innerHTML = pillsHtml();
}

function renderChips() {
  const box = document.getElementById('bp-chips');
  if (!box) return;
  const chips = [];
  filtro.categoria.forEach(v => chips.push({ g: 'categoria', v, label: `Categoria: ${v === SENZA_CATEGORIA ? 'Senza categoria' : v}` }));
  filtro.fornitore.forEach(v => chips.push({ g: 'fornitore', v, label: `Fornitore: ${v}` }));
  box.innerHTML = chips.length
    ? chips.map(c => `<span class="bp-chip">${esc(c.label)}<button type="button" class="bp-chip-x" data-g="${c.g}" data-v="${esc(c.v)}" aria-label="Rimuovi ${esc(c.label)}">&times;</button></span>`).join('')
      + `<button type="button" class="bp-chip-reset" onclick="bpAzzeraTutti()">Azzera filtri</button>`
    : '';
  box.hidden = !chips.length;
}

function renderLista() {
  const grid = document.getElementById('campaigns-grid');
  if (!grid) return;
  const lista = ordinaCampagne(filtrate(), filtro.ordina);
  const filtriAttivi = !!(filtro.q || filtro.stato !== 'tutti' || filtro.categoria.length || filtro.fornitore.length);

  grid.innerHTML = lista.length
    ? lista.map(campaignCardHtml).join('')
    : `<div class="empty-state">
         <p class="text-secondary">Nessun gruppo corrisponde ai filtri</p>
         <div class="empty-state-actions">
           <button type="button" class="btn btn-default" onclick="bpAzzeraTutti()">Azzera filtri</button>
           ${filtro.stato === 'conclusi' ? '' : `<a href="#/proposte" class="btn btn-outline">Proponi prodotto</a>`}
         </div>
       </div>`;

  const countEl = document.getElementById('campaigns-count');
  if (countEl) {
    const conclusi = filtro.stato === 'conclusi';
    const un = lista.length === 1;
    countEl.innerHTML = `<span class="bp-count-n">${lista.length}</span> ${conclusi ? (un ? 'gruppo concluso' : 'gruppi conclusi') : (un ? 'gruppo aperto' : 'gruppi aperti')}`;
  }

  const reset = document.getElementById('filtri-reset');
  if (reset) reset.style.display = filtriAttivi ? '' : 'none';

  homeScaduteGestite = false;
}

function renderAll() {
  renderPills();
  renderChips();
  renderDropdownLabels();
  renderLista();
}

function applicaFiltri() {
  scriviUrl();
  renderAll();
  renderPannelli();
}

/* ============================ Handler globali ============================ */

window.bpPillStato = function(id) {
  filtro.stato = id;
  applicaFiltri();
};

window.bpToggleFiltro = function(gruppo, valore, checked) {
  const arr = filtro[gruppo];
  if (checked) { if (!arr.includes(valore)) arr.push(valore); }
  else filtro[gruppo] = arr.filter(v => v !== valore);
  applicaFiltri();
  const inputs = [...document.querySelectorAll(`.bp-panel input[value="${CSS.escape(valore)}"]`)];
  const visibile = inputs.find(i => i.offsetParent !== null) || inputs[0];
  if (visibile) visibile.focus();
};

window.bpScegliOrdina = function(id) {
  filtro.ordina = id;
  applicaFiltri();
  bpChiudiPannelli();
};

window.bpAzzeraGruppo = function(gruppo) {
  filtro[gruppo] = [];
  applicaFiltri();
};

window.bpAzzeraTutti = function() {
  filtro.q = '';
  filtro.stato = 'tutti';
  filtro.categoria = [];
  filtro.fornitore = [];
  filtro.ordina = 'traguardo';
  const h = document.getElementById('header-search-input');
  if (h) h.value = '';
  applicaFiltri();
};

window.bpRimuoviChip = function(gruppo, valore) {
  filtro[gruppo] = filtro[gruppo].filter(v => v !== valore);
  applicaFiltri();
};

window.bpTogglePannello = function(gruppo) {
  const pannello = document.getElementById('panel-' + gruppo);
  const btn = document.getElementById('dd-btn-' + gruppo);
  const aperto = pannello && pannello.classList.contains('open');
  bpChiudiPannelli();
  if (!aperto && pannello) {
    pannello.innerHTML = pannelloHtml(gruppo);
    pannello.classList.add('open');
    if (btn) btn.setAttribute('aria-expanded', 'true');
    const primo = pannello.querySelector('input, button');
    if (primo) primo.focus();
  }
};

function bpChiudiPannelli() {
  document.querySelectorAll('.bp-panel.open').forEach(p => p.classList.remove('open'));
  document.querySelectorAll('.bp-dd-btn[aria-expanded="true"]').forEach(b => b.setAttribute('aria-expanded', 'false'));
}
window.bpChiudiPannelli = bpChiudiPannelli;

window.bpFiltraRighe = function(input) {
  const q = (input.value || '').toLowerCase();
  const rows = input.closest('.bp-panel')?.querySelectorAll('.bp-panel-row') || [];
  rows.forEach(r => {
    const testo = (r.querySelector('.bp-panel-row-label')?.textContent || '').toLowerCase();
    r.style.display = testo.includes(q) ? '' : 'none';
  });
};

/* Bottom sheet mobile */
window.bpApriSheet = function() {
  const sheet = document.getElementById('bp-sheet');
  if (!sheet) return;
  renderPannelli();
  sheet.classList.add('open');
  const overlay = document.getElementById('bp-sheet-overlay');
  if (overlay) overlay.classList.add('open');
  const btn = document.getElementById('bp-filter-btn');
  if (btn) btn.setAttribute('aria-expanded', 'true');
  document.body.style.overflow = 'hidden';
  sheet.querySelector('input, button')?.focus();
};

window.bpChiudiSheet = function() {
  document.getElementById('bp-sheet')?.classList.remove('open');
  document.getElementById('bp-sheet-overlay')?.classList.remove('open');
  const btn = document.getElementById('bp-filter-btn');
  if (btn) {
    btn.setAttribute('aria-expanded', 'false');
    btn.focus();
  }
  document.body.style.overflow = '';
};

/* ============================ Ticker scadenze (aggiornamento ogni minuto) ============================ */

function tickScadenzeCard() {
  document.querySelectorAll('.bp-deadline-live').forEach(el => {
    const wrap = el.closest('.bp-deadline');
    const txt = formatScade(wrap?.dataset.scadenza || '');
    if (txt) el.textContent = txt;
  });
  if (!homeScaduteGestite) {
    const scadutaOra = [...document.querySelectorAll('.bp-deadline')]
      .some(el => !el.querySelector('.bp-deadline-concluso') && isScaduta({ data_limite: el.dataset.scadenza }));
    if (scadutaOra) {
      homeScaduteGestite = true;
      renderAll();
    }
  }
}

/* ============================ Ascolto della query string (back/forward + header) ============================ */

function ascoltaHash() {
  if (homeListenerAttivo) return;
  homeListenerAttivo = true;

  // Rimozione chip (delegato: i valori arrivano da data-*)
  document.addEventListener('click', (e) => {
    const x = e.target.closest && e.target.closest('.bp-chip-x');
    if (x) {
      window.bpRimuoviChip(x.dataset.g, x.dataset.v);
      return;
    }
    // Click fuori dai dropdown: chiude i pannelli aperti
    if (!(e.target.closest && e.target.closest('.bp-dd'))) bpChiudiPannelli();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('bp-sheet')?.classList.contains('open')) window.bpChiudiSheet();
    else bpChiudiPannelli();
  });

  window.addEventListener('hashchange', () => {
    if (!document.getElementById('campaigns-grid')) return;
    const qs = (window.location.hash.split('?')[1] || '').split('#')[0];
    const p = new URLSearchParams(qs);
    const nuovoQ = p.get('q') || '';
    const header = document.getElementById('header-search-input');
    if (header && header.value !== nuovoQ) header.value = nuovoQ;
    const stato = p.get('stato') || 'tutti';
    const nuovoStato = PILL_STATO.some(x => x.id === stato) ? stato : 'tutti';
    const nuoveCat = p.getAll('categoria[]').concat(p.getAll('categoria'));
    const nuoviFor = p.getAll('fornitore[]').concat(p.getAll('fornitore'));
    const ordina = p.get('ordina') || 'traguardo';
    const nuovoOrdina = ORDINI.some(x => x.id === ordina) ? ordina : 'traguardo';
    const cambiato = nuovoQ !== filtro.q || nuovoStato !== filtro.stato ||
      nuovoOrdina !== filtro.ordina ||
      nuoveCat.join('|') !== filtro.categoria.join('|') ||
      nuoviFor.join('|') !== filtro.fornitore.join('|');
    if (!cambiato) return;
    filtro.q = nuovoQ;
    filtro.stato = nuovoStato;
    filtro.categoria = nuoveCat;
    filtro.fornitore = nuoviFor;
    filtro.ordina = nuovoOrdina;
    renderAll();
    renderPannelli();
  });
  window.addEventListener('popstate', () => {
    if (!document.getElementById('campaigns-grid')) return;
    window.dispatchEvent(new HashChangeEvent('hashchange'));
  });
}

/* ============================ Pagina ============================ */

function filtriHtml() {
  return `
    <div class="bp-filterbar">
      <div class="bp-pills" id="bp-pills" role="group" aria-label="Filtri di stato">${pillsHtml()}</div>
      <div class="bp-dropdowns">
        <div class="bp-dd" id="dd-categoria">
          <button type="button" class="bp-dd-btn" id="dd-btn-categoria" aria-expanded="false" aria-haspopup="true"
                  onclick="bpTogglePannello('categoria')">Categoria</button>
          <div class="bp-panel" id="panel-categoria" role="dialog" aria-label="Filtra per categoria"></div>
        </div>
        <div class="bp-dd" id="dd-fornitore">
          <button type="button" class="bp-dd-btn" id="dd-btn-fornitore" aria-expanded="false" aria-haspopup="true"
                  onclick="bpTogglePannello('fornitore')">Fornitore</button>
          <div class="bp-panel" id="panel-fornitore" role="dialog" aria-label="Filtra per fornitore"></div>
        </div>
        <div class="bp-dd" id="dd-ordina">
          <button type="button" class="bp-dd-btn" id="dd-btn-ordina" aria-expanded="false" aria-haspopup="true"
                  onclick="bpTogglePannello('ordina')">Ordina</button>
          <div class="bp-panel bp-panel-ordina" id="panel-ordina" role="dialog" aria-label="Ordina l'elenco"></div>
        </div>
        <button type="button" class="bp-dd-btn bp-filter-btn" id="bp-filter-btn" aria-expanded="false"
                onclick="bpApriSheet()">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
          Filtri
        </button>
      </div>
    </div>
    <div class="bp-chips" id="bp-chips" hidden></div>`;
}

function sheetHtml() {
  return `
    <div class="bp-sheet-overlay" id="bp-sheet-overlay" onclick="bpChiudiSheet()"></div>
    <div class="bp-sheet" id="bp-sheet" role="dialog" aria-label="Filtri" aria-modal="true">
      <div class="bp-sheet-head">
        <strong>Filtri</strong>
        <button type="button" class="bp-sheet-close" onclick="bpChiudiSheet()" aria-label="Chiudi filtri">
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="bp-sheet-body">
        <section class="bp-sheet-section"><h3>Categoria</h3><div class="bp-panel bp-panel-in" id="mpanel-categoria"></div></section>
        <section class="bp-sheet-section"><h3>Fornitore</h3><div class="bp-panel bp-panel-in" id="mpanel-fornitore"></div></section>
        <section class="bp-sheet-section"><h3>Ordina</h3><div class="bp-panel bp-panel-in" id="mpanel-ordina"></div></section>
      </div>
      <div class="bp-sheet-foot">
        <button type="button" class="btn btn-outline" onclick="bpAzzeraTutti()">Azzera</button>
        <button type="button" class="btn btn-default" id="bp-sheet-count" onclick="bpChiudiSheet()">Mostra gruppi</button>
      </div>
    </div>`;
}

export async function HomePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet('/campagne');
    homeCampaigns = res.dati || [];
    leggiUrl();

    const header = document.getElementById('header-search-input');
    if (header) header.value = filtro.q;

    content.innerHTML = `
      <div class="content-area bp-home">
        ${comeFunzionaHtml()}
        ${filtriHtml()}
        <div class="bp-list-head">
          <h2 class="bp-count" id="campaigns-count" aria-live="polite"></h2>
          <button type="button" class="btn btn-ghost btn-sm" id="filtri-reset" onclick="bpAzzeraTutti()" style="display:none;">Azzera</button>
        </div>
        <div class="homepage-grid" id="campaigns-grid"></div>
        ${sheetHtml()}
      </div>`;

    renderAll();
    renderPannelli();
    ascoltaHash();
    initComeFunziona(content);
    setPageInterval(tickScadenzeCard, 60000);
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore di caricamento</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
