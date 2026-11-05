import { getState } from '../state.js';
import { isAuthenticated, isFornitore, isAdmin } from '../auth.js';
import { apiGet, apiPost, apiDelete } from '../api.js';
import { API_URL, ACCONTO_PERCENT } from '../constants.js';
import { Badge } from '../components/badge.js';
import { apriAumenta } from '../components/aumenta-dialog.js';
import { Card } from '../components/card.js';
import { Avatar } from '../components/dropdown.js';
import { startScadenzaMinuti, scadenzaBlocchiHtml, scadenzaAriaLabel } from '../countdown.js';
import { starsMedia } from '../stars.js';
import { euro, dataGGMMAAAA, pezzi, recensioni } from '../format.js';
import { accontoStima, saldoStima } from '../saldo-stima.js';
import { prezzoMostrato, percorsoHtml, scontoMassimo, badgeScontoHtml, iconaOrologio } from '../components/gruppo-card.js';
import { metodiConsegna } from '../consegna-metodi.js';
import { modelloBannerFine, bannerFineHtml } from '../banner-fine.js';

let currentIdColletta = null;
let currentCampagna = null;
let currentAcquisto = null; // la mia adesione (da /mie/acquisti), solo per i gruppi terminati
let currentFornitore = null;
let currentRecensioni = { media: null, totale: 0, recensioni: [] };

/** Nome privacy: "Marco R." (solo nome se manca l'iniziale). */
function nomeRecensore(r) {
  const nome = (r.autore || 'Utente').trim();
  const ini = (r.cognome_iniziale || '').trim();
  return ini ? `${nome} ${ini}.` : nome;
}

function inizialiRecensore(r) {
  const nome = (r.autore || 'U').trim();
  const ini = (r.cognome_iniziale || '').trim();
  return (nome[0] || 'U') + (ini || '');
}

/** Sezione recensioni del prodotto/campagna full-width: riepilogo + distribuzione + lista. */
function recensioniProdottoHtml() {
  const rd = currentRecensioni;
  const totale = rd.totale || 0;
  if (totale === 0) {
    return Card({ children: `
      <div class="card-content">
        <h3 style="margin-bottom: var(--space-2);">Recensioni sul prodotto</h3>
        <p class="text-secondary">Nessuna recensione ancora per questo prodotto.</p>
      </div>
    ` });
  }
  const lista = rd.recensioni || [];
  const dist = [5, 4, 3, 2, 1].map(v => {
    const n = lista.filter(r => r.voto === v).length;
    const pct = Math.round((n / totale) * 100);
    return { v, n, pct };
  });
  const distHtml = dist.map(d => `
    <div class="rec-dist-row">
      <span class="text-xs text-secondary" style="width:12px;">${d.v}</span>
      <span style="color:var(--color-warning);font-size:var(--text-xs);">★</span>
      <div class="rec-dist-bar"><div class="rec-dist-fill" style="width:${d.pct}%;"></div></div>
      <span class="text-xs text-secondary" style="width:36px;text-align:right;">${d.pct}%</span>
    </div>`).join('');
  const prime = lista.slice(0, 3).map(r => `
    <div class="rec-item">
      ${Avatar({ fallback: inizialiRecensore(r), size: 'sm' })}
      <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:wrap;margin-bottom:var(--space-1);">
          <span class="font-medium text-sm">${nomeRecensore(r)}</span>
          ${starsMedia(r.voto, 'var(--text-sm)')}
          ${r.verificata ? Badge({ variant: 'success', children: 'Partecipante verificato' }) : ''}
        </div>
        ${r.testo ? `<p class="text-sm" style="margin-bottom:var(--space-1);">${r.testo}</p>` : ''}
        <div class="text-xs text-secondary">${new Date(r.data_creazione).toLocaleDateString('it-IT')}</div>
      </div>
    </div>`).join('');
  return Card({ children: `
    <div class="card-content">
      <h3 style="margin-bottom: var(--space-4);">Recensioni sul prodotto</h3>
      <div class="reviews-layout">
        <div class="reviews-summary">
          <div class="reviews-media">${rd.media}</div>
          ${starsMedia(rd.media, 'var(--text-lg)')}
          <div class="text-sm text-secondary" style="margin:var(--space-1) 0 var(--space-3);">${totale} ${totale === 1 ? 'recensione' : 'recensioni'}</div>
          ${distHtml}
        </div>
        <div class="reviews-list">
          ${prime}
        </div>
      </div>
    </div>
  ` });
}

function imgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + path.replace(/^\//, '');
}

window.apriImmagineCampagna = function(src) {
  if (!src) return;
  const modalHtml = `
    <div id="img-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;z-index:1100;padding:var(--space-4);" onclick="if(event.target===this)document.getElementById('img-modal').remove()">
      <div style="position:relative;max-width:90vw;max-height:85vh;">
        <img src="${src}" alt="Immagine prodotto" style="max-width:90vw;max-height:80vh;object-fit:contain;border-radius:var(--radius-md);" />
        <button class="btn btn-outline btn-sm" style="position:absolute;top:8px;right:8px;background:var(--color-white);" onclick="document.getElementById('img-modal').remove()">Chiudi</button>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
};

function galleryImgs(c) {
  if (c.immagini && c.immagini.length) return c.immagini.map(i => i.url);
  return c.immagine ? [c.immagine] : [];
}

function galleryHtml(c, nomeProdotto) {
  const imgs = galleryImgs(c);
  if (imgs.length === 0) {
    return `<div class="bp-gallery-empty" role="img" aria-label="Nessuna immagine disponibile">
      <svg width="96" height="96" fill="none" stroke="var(--bp-brand)" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
    </div>`;
  }
  const thumbs = imgs.length > 1 ? `
    <div class="gallery-thumbs">
      ${imgs.map((u, i) => `<img src="${imgUrl(u)}" alt="Miniatura ${i + 1}" data-gi="${i}" class="gallery-thumb${i === 0 ? ' active' : ''}" onclick="gallerySeleziona(${i})" />`).join('')}
    </div>` : '';
  const arrows = imgs.length > 1 ? `
    <button class="carousel-arrow carousel-prev gallery-arrow" onclick="event.stopPropagation();galleryMuovi(-1)" aria-label="Precedente">&#10094;</button>
    <button class="carousel-arrow carousel-next gallery-arrow" onclick="event.stopPropagation();galleryMuovi(1)" aria-label="Successiva">&#10095;</button>` : '';
  return `
    <div class="detail-gallery bp-gallery">
      <div class="bp-gallery-main" onclick="apriImmagineCampagna(document.getElementById('gallery-main-img').src)">
        <img id="gallery-main-img" src="${imgUrl(imgs[0])}" alt="${nomeProdotto}" data-idx="0" />
        <button class="bp-zoom-btn" onclick="event.stopPropagation();apriImmagineCampagna(document.getElementById('gallery-main-img').src)" aria-label="Ingrandisci immagine">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4"/></svg>
        </button>
        ${arrows}
      </div>
      ${thumbs}
    </div>`;
}

window.gallerySeleziona = function(idx) {
  const c = currentCampagna;
  const imgs = galleryImgs(c || {});
  if (!imgs[idx]) return;
  const main = document.getElementById('gallery-main-img');
  if (main) { main.src = imgUrl(imgs[idx]); main.dataset.idx = idx; }
  document.querySelectorAll('.gallery-thumb').forEach((t, i) =>
    t.classList.toggle('active', i === idx));
};

window.galleryMuovi = function(dir) {
  const c = currentCampagna;
  const imgs = galleryImgs(c || {});
  if (imgs.length < 2) return;
  const main = document.getElementById('gallery-main-img');
  const cur = main ? (parseInt(main.dataset.idx) || 0) : 0;
  const idx = ((cur + dir) % imgs.length + imgs.length) % imgs.length;
  gallerySeleziona(idx);
};

function condividiDati() {
  const c = currentCampagna || {};
  const qty = parseInt(c.quantita_attuale) || 0;
  const min = parseInt(c.quantita_minima) || 1;
  const mancanti = Math.max(0, min - qty);
  const curr = parseFloat(c.prezzo_corrente) || 0;
  const base = parseFloat(c.prezzo_base) || 0;
  const url = window.location.origin + window.location.pathname + '#/campagne/' + (c.id || currentIdColletta);
  const testo = `Partecipa a "${c.prodotto || 'questo gruppo'}" su BuyPool: ${euro(curr)} invece di ${euro(base)}! ${mancanti === 1 ? 'Manca' : 'Mancano'} ${pezzi(mancanti)} al minimo.`;
  return { url, testo };
}

window.condividiNativa = async function() {
  const { url, testo } = condividiDati();
  try {
    await navigator.share({ title: 'BuyPool', text: testo, url });
  } catch (e) { /* annullato dall'utente */ }
};

window.copiaLinkCondividi = async function() {
  const { url } = condividiDati();
  try {
    await navigator.clipboard.writeText(url);
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Link copiato!</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
  } catch (e) {
    prompt('Copia il link:', url);
  }
};

/** Icona lucchetto SVG (16px) per la nota "il minimo è stato raggiunto". */
function iconaLucchetto() {
  return `<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>`;
}

/**
 * Azioni del dettaglio. Nascoste per i gruppi terminati.
 * Annulla adesione: solo prima del minimo (come il server, partecipazioni.php); dopo il minimo c'è la nota col lucchetto.
 */
function azioniDettaglioHtml(c, { terminato, partecipato, confermato, minimo, prezzoAcconto }) {
  if (terminato) return '';
  if (partecipato) {
    const mq = c.mia_partecipazione.quantita;
    const annullabile = c.mia_partecipazione.stato === 'prenotata' && !confermato;
    return `
      <div class="gd-joined">
        <span class="bp-chip-joined">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          Hai aderito &middot; ${mq} unità
        </span>
        <a class="gd-link" href="#/acquisti?tab=in-corso">Vai ai miei acquisti &rarr;</a>
      </div>
      ${mq < 99 ? `<button class="btn btn-default bp-cta-joined" onclick="apriAumentaDettaglio()">Compra di pi&ugrave;</button>` : ''}
      ${annullabile ? `<button class="bp-link-muted gd-annulla" onclick="handleAnnullaAdesione(${c.id})">Annulla adesione</button>` : ''}
      ${confermato ? `<p class="gd-lock-note">${iconaLucchetto()}Il minimo &egrave; stato raggiunto: l'adesione non si pu&ograve; pi&ugrave; annullare.</p>` : ''}`;
  }
  return `
    <div class="gd-qty-row">
      <span class="gd-qty-label" id="qty-label">Quante unità?</span>
      <div class="bp-stepper">
        <button class="bp-step" onclick="changeQty(-1)" aria-label="Riduci quantit&agrave;">&minus;</button>
        <input id="qty-input" type="number" value="1" min="1" max="99" oninput="aggiornaAcconto()" aria-labelledby="qty-label" />
        <button class="bp-step" onclick="changeQty(1)" aria-label="Aumenta quantit&agrave;">+</button>
      </div>
    </div>
    <button class="btn btn-default bp-cta" id="acconto-row"
            data-prezzo="${prezzoAcconto.toFixed(2)}" data-perc="${ACCONTO_PERCENT}"
            onclick="handleAderisci()">Unisciti al gruppo &middot; acconto ${euro(accontoStima(1, prezzoAcconto, ACCONTO_PERCENT))}</button>
    <p class="bp-cta-note">Oggi paghi solo l'acconto del ${ACCONTO_PERCENT}%. Il saldo si addebita alla chiusura, solo se il gruppo si completa.</p>`;
}

function condivisioneDettaglioHtml() {
  const share = condividiDati();
  const shareEu = encodeURIComponent(share.url);
  const shareEt = encodeURIComponent(share.testo);
  return `
    <div class="gd-sep"></div>
    <div class="bp-share">
      <h2 class="gd-share-title">Invita i tuoi amici: pi&ugrave; siamo, meno si paga</h2>
      <div class="bp-share-grid">
        <a class="bp-share-btn" href="https://wa.me/?text=${shareEt}%20${shareEu}" target="_blank" rel="noopener">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="#25D366" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
          WhatsApp
        </a>
        <a class="bp-share-btn" href="https://t.me/share/url?url=${shareEu}&text=${shareEt}" target="_blank" rel="noopener">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="#26A5E4" aria-hidden="true"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
          Telegram
        </a>
        <button class="bp-share-btn" onclick="copiaLinkCondividi()">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
          Copia link
        </button>
        ${(typeof navigator !== 'undefined' && typeof navigator.share === 'function') ? `
        <button class="bp-share-btn" onclick="condividiNativa()">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
          Altro
        </button>` : ''}
      </div>
    </div>`;
}

/** Garanzie in una riga: icone piccole grigie, nessun riquadro. */
function garanzieDettaglioHtml() {
  const icona = (d) => `<svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${d}"/></svg>`;
  return `
    <div class="gd-sep"></div>
    <ul class="gd-guar">
      <li>${icona('M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z')}Rimborso al 100% se il gruppo non parte</li>
      <li>${icona('M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z')}Pagamento sicuro</li>
      <li>${icona('M4 6V4h4M20 6V4h-4M4 18v2h4m12-2v2h-4M8 8h3v3H8V8zm5 0h3v3h-3V8zM8 13h3v3H8v-3zm5 0h3v3h-3v-3z')}Ritiro con codice QR</li>
    </ul>`;
}

function renderDettaglio() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const c = currentCampagna;
  const backHref = isFornitore() ? '#/fornitore/gruppi' : '#/';
  if (!c) {
    content.innerHTML = `<div class="empty-state"><h2>Gruppo non trovato</h2><a href="${backHref}" class="btn btn-default">Torna ai gruppi</a></div>`;
    return;
  }

  const qty = parseInt(c.quantita_attuale) || 0;
  const min = parseInt(c.quantita_minima) || 1;
  const scaglioni = Array.isArray(c.scaglioni) ? c.scaglioni : [];
  const minimo = scaglioni.length > 0 ? scaglioni[0].soglia : min;
  const primoPrezzo = scaglioni.length > 0 ? parseFloat(scaglioni[0].prezzo) || 0 : 0;
  const prezzoAcconto = primoPrezzo > 0 ? primoPrezzo : (parseFloat(c.prezzo_corrente) || 0);
  const confermato = qty >= minimo;
  const persone = c.partecipanti ?? (Array.isArray(c.partecipazioni) ? c.partecipazioni.length : 0);
  const sogliaRaggiunta = (qty / min) >= 1;
  const scaduta = Number.isFinite(new Date(c.data_limite).getTime()) && new Date(c.data_limite).getTime() < Date.now();
  // Stato display separato dalle partecipazioni (dal backend; fallback legacy se assente)
  const display = c.stato_campagna || ((['fallita', 'annullata'].includes(c.stato) || (scaduta && !sogliaRaggiunta)) ? 'non_riuscita'
    : ((sogliaRaggiunta || ['riuscita', 'ordine_pronto', 'ordine_fornitore', 'consegnata'].includes(c.stato)) ? 'conclusa' : 'in_corso'));
  const fallita = display === 'non_riuscita';
  const soglia = display === 'conclusa' || display === 'in_chiusura';
  const terminato = fallita || soglia;
  // Prezzo finale = scaglione piu' alto raggiunto (o prezzo corrente senza scaglioni)
  const raggiunti = scaglioni.filter(s => qty >= s.soglia);
  const prezzoFinale = raggiunti.length ? (parseFloat(raggiunti[raggiunti.length - 1].prezzo) || 0) : (parseFloat(c.prezzo_corrente) || 0);
  const dataFineTesto = dataGGMMAAAA(fallita ? c.data_limite : (c.chiusura_data || c.data_limite));
  const partecipato = c.mia_partecipazione !== null && c.mia_partecipazione !== undefined;
  const nomeProdotto = c.prodotto || 'Prodotto';
  const nomeFornitore = c.fornitore || 'Fornitore';
  const f = currentFornitore || {};
  const numCampagne = f.num_campagne || 0;

  // Prezzo in grande e percorso: stessa regola e stesso componente delle card
  const cc = { ...c, partecipanti: persone };
  const pm = prezzoMostrato(cc);
  const badge = badgeScontoHtml(scontoMassimo(c), { spento: terminato, grande: true });
  const etichettaPrezzo = pm.attivo ? 'Prezzo unitario ora' : `Prezzo unitario appena il gruppo arriva a ${pm.sogliaPrima} unità`;
  const esito = fallita ? 'Minimo non raggiunto' : (soglia ? 'Gruppo concluso' : '');
  const rating = c.fornitore_rating && c.fornitore_rating.totale > 0 ? c.fornitore_rating : null;

  const scadenzaHtml = terminato
    ? `<div class="bp-scadenza bp-scadenza-fine"><span class="bp-scadenza-label">${fallita ? 'Scaduto' : 'Concluso'} il ${dataFineTesto}</span></div>`
    : `<div class="bp-scadenza bp-scadenza-box" data-scadenza-box aria-label="${scadenzaAriaLabel(c.data_limite)}">
        <div class="bp-scad-sx" aria-hidden="true">
          <span class="bp-scadenza-timer">${iconaOrologio(30)}</span>
          <span class="bp-scad-txt"><span class="bp-scadenza-label">Scade tra: </span>${dataGGMMAAAA(c.data_limite) ? `<span class="bp-scad-data"></span>` : ''}</span>
        </div>
        <div class="bp-scad-blocchi" data-scadenza-blocchi="${c.data_limite || ''}" aria-hidden="true">${scadenzaBlocchiHtml(c.data_limite)}</div>
      </div>`;

  content.innerHTML = `
    <div class="content-area">
      ${terminato ? bannerFineHtml(modelloBannerFine({
        display, aderente: partecipato, quantita: partecipato ? c.mia_partecipazione.quantita : 0,
        vista: currentAcquisto?.vista, spedizione: currentAcquisto?.spedizione, acconto: currentAcquisto ? currentAcquisto.acconto : 0,
        prezzoFinale
      })) : ''}
      ${!isAuthenticated() ? `
      <div class="guest-banner">
        <span>Stai visualizzando questo gruppo come ospite</span>
        <button class="btn btn-ghost btn-sm" onclick="guestAccedi()">Accedi</button>
        <button class="btn btn-default btn-sm" onclick="guestRegistrati()">Registrati</button>
      </div>` : ''}
      <a href="${backHref}" class="gd-back">&larr; Torna ai gruppi</a>
      <div class="detail-layout detail-layout-shop bp-grid">
        <div class="detail-left-col">
          ${galleryHtml(c, nomeProdotto)}
          <div class="detail-desc-col">
            ${Card({ children: `
              <div class="card-content">
                <h3 style="margin-bottom: var(--space-3);">Descrizione</h3>
                <p class="text-secondary">${c.descrizione_prodotto || 'Nessuna descrizione disponibile.'}</p>
              </div>
            ` })}
          </div>
        </div>
        <div class="detail-panel">
          ${Card({ children: `
            <div class="card-content bp-info">
              <div class="gd-head">
                <h1 class="gd-title">${nomeProdotto}</h1>
                ${badge}
              </div>
              <div class="shop-supplier-row">
                <a href="#/fornitori/${c.fornitore_id}">${nomeFornitore}</a>
                ${rating ? `<span class="gd-rating">${starsMedia(rating.media, 'var(--text-sm)')} <span>(${recensioni(rating.totale)})</span></span>` : ''}
                ${numCampagne ? `<span class="gd-rating">&middot; ${numCampagne} ${numCampagne === 1 ? 'gruppo' : 'gruppi'}</span>` : ''}
              </div>
              <div class="gd-price-row">
                <span class="gd-price">${euro(pm.prezzo)}</span>
                ${pm.listino > 0 ? `<s class="gd-listino">${euro(pm.listino)}</s>` : ''}
              </div>
              <p class="gd-price-note">${etichettaPrezzo}</p>
              ${percorsoHtml(cc, { variante: 'largo', esito })}
              ${terminato ? '' : `<p class="gd-path-note">Il prezzo scende per tutti quando il gruppo supera una soglia. Le unità sono prenotate da persone diverse del gruppo, non da una sola.</p>`}
              ${scadenzaHtml}
              <div class="gd-actions">${azioniDettaglioHtml(c, { terminato, partecipato, confermato, minimo, prezzoAcconto })}</div>
              ${terminato ? '' : condivisioneDettaglioHtml()}
              ${garanzieDettaglioHtml()}
            </div>
          ` })}
        </div>
      </div>
      <div class="detail-bottom bp-bottom">
        <div class="detail-reviews-col">
          ${recensioniProdottoHtml()}
        </div>
        <div class="detail-supplier-col">
          ${Card({ children: `
            <div class="card-content supplier-box supplier-box-clickable" onclick="window.location.hash='#/fornitori/${c.fornitore_id}'" title="Vedi profilo fornitore">
              <div class="font-medium" style="margin-bottom:var(--space-1);color:var(--bp-btn);">${nomeFornitore}</div>
              ${rating
                ? `<div class="text-sm" style="display:flex;align-items:center;gap:4px;margin-bottom:var(--space-3);">${starsMedia(rating.media, 'var(--text-sm)')} <span class="text-secondary">(${rating.totale} ${rating.totale === 1 ? 'recensione' : 'recensioni'})</span></div>`
                : `<div class="text-xs text-secondary" style="margin-bottom:var(--space-3);">Nessuna recensione</div>`}
              <a href="#/fornitori/${c.fornitore_id}" class="btn btn-ghost btn-sm" onclick="event.stopPropagation()">Vedi profilo &rarr;</a>
            </div>
          ` })}
        </div>
      </div>
    </div>
  `;
}

function apriGateModal(quantita) {
  document.getElementById('gate-modal')?.remove();
  const modalHtml = `
    <div id="gate-modal" class="modal-overlay" onclick="if(event.target===this)document.getElementById('gate-modal').remove()">
      <div class="modal-content card" style="max-width:420px;width:92%;">
        <div class="card-content" style="text-align:center;">
          <div style="display:flex;justify-content:center;margin-bottom:var(--space-3);">
            <span class="how-circle" style="margin:0;width:48px;height:48px;">
              <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
            </span>
          </div>
          <h3 style="margin-bottom:var(--space-2);">Crea un account per aderire</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-4);">Bastano 30 secondi. Torni subito qui, con la tua scelta di ${quantita} unità gi&agrave; salvata.</p>
          <button class="btn btn-default w-full" onclick="gateRegistrati()">Registrati e continua</button>
          <button class="btn btn-ghost w-full" style="margin-top:var(--space-2);" onclick="gateAccedi()">Hai gi&agrave; un account? Accedi</button>
        </div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
}

window.gateRegistrati = function() {
  document.getElementById('gate-modal')?.remove();
  if (typeof window.guestRegistrati === 'function') window.guestRegistrati();
  else window.location.href = 'index.html#/register';
};

window.gateAccedi = function() {
  document.getElementById('gate-modal')?.remove();
  if (typeof window.guestAccedi === 'function') window.guestAccedi();
  else window.location.href = 'index.html#/login';
};

window.aggiornaAcconto = function() {
  const row = document.getElementById('acconto-row');
  if (!row) return;
  const input = document.getElementById('qty-input');
  const q = input ? Math.max(1, Math.min(99, parseInt(input.value) || 1)) : 1;
  const prezzo = parseFloat(row.dataset.prezzo) || 0;
  const perc = parseFloat(row.dataset.perc) || 20;
  row.innerHTML = `Unisciti al gruppo &middot; acconto ${euro(accontoStima(q, prezzo, perc))}`;
};

window.changeQty = function(delta) {
  const input = document.getElementById('qty-input');
  if (!input) return;
  let val = parseInt(input.value) || 1;
  val = Math.max(1, Math.min(99, val + delta));
  input.value = val;
  window.aggiornaAcconto();
};

window.handleAderisci = async function() {
  if (!currentIdColletta) return;
  const input = document.getElementById('qty-input');
  const quantita = input ? Math.max(1, Math.min(99, parseInt(input.value) || 1)) : 1;
  if (!isAuthenticated()) {
    try {
      sessionStorage.setItem('buypool_pending_adesione', JSON.stringify({ id: currentIdColletta, quantita }));
    } catch (e) {}
    apriGateModal(quantita);
    return;
  }
  apriPrenotaModal(quantita);
};

let prenotaModal = null;

function metodiConsegnaDisponibili() {
  const c = currentCampagna || {};
  const sedeId = c.id_sede ? String(c.id_sede) : 0;   // id UUID (non più un intero)
  const sede = { nome: c.punto_ritiro || '', indirizzo: c.sede_indirizzo || '', citta: c.citta || '' };
  // Regole in consegna-metodi.js: entrambe le schede sempre visibili, ritiro preselezionato se disponibile
  const m = metodiConsegna({ sedeId, ritiroDisponibile: c.ritiro_disponibile !== false, spedizioneDisponibile: c.spedizione_disponibile !== false });
  return { ...m, sedeId, sede };
}

/** "Aumenta la quantità" dalla pagina del gruppo: stessa finestra di "I miei acquisti". */
window.apriAumentaDettaglio = function() {
  const c = currentCampagna || {};
  const m = c.mia_partecipazione;
  if (!m) return;
  const sc = Array.isArray(c.scaglioni) ? c.scaglioni : [];
  const primo = sc.length > 0 ? (parseFloat(sc[0].prezzo) || 0) : (parseFloat(c.prezzo_corrente) || 0);
  apriAumenta({
    prenotazioneId: m.id, prodotto: c.prodotto || 'Prodotto', quantitaAttuale: parseInt(m.quantita) || 0,
    maxAggiuntivi: 99 - (parseInt(m.quantita) || 0), accontoUnitario: primo * ACCONTO_PERCENT / 100, accontoPercent: ACCONTO_PERCENT
  });
};

function pmPrimoPrezzo() {
  const c = currentCampagna || {};
  const sc = Array.isArray(c.scaglioni) ? c.scaglioni : [];
  return sc.length > 0 ? (parseFloat(sc[0].prezzo) || 0) : (parseFloat(c.prezzo_corrente) || 0);
}

function pmAccontoStima(qty) {
  return accontoStima(qty, pmPrimoPrezzo(), ACCONTO_PERCENT);
}

const escHtml = (v) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** Costo reale della spedizione (config del server): 0 = gratuita. */
function pmCostoSpedizione() {
  return Math.max(0, parseFloat((currentCampagna || {}).costo_spedizione) || 0);
}

function pmSedeTesto(sede) {
  // La citta' si aggiunge solo se non e' gia' nel nome della sede (es. "Sede Centrale Perugia")
  const citta = sede.citta && !String(sede.nome).toLowerCase().includes(String(sede.citta).toLowerCase()) ? sede.citta : '';
  return [sede.nome, sede.indirizzo, citta].filter(Boolean).join(' · ');
}

let pmOpener = null;
let pmKeyHandler = null;

window.apriPrenotaModal = function(quantita) {
  document.getElementById('prenota-modal')?.remove();
  pmOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  const disp = metodiConsegnaDisponibili();
  prenotaModal = {
    quantita, tipo: disp.selezionata, sedeId: disp.sedeId,
    via: '', cap: '', citta: '', provincia: '',
    redirecting: false
  };
  const modalHtml = `
    <div id="prenota-modal" class="modal-overlay">
      <div class="modal-content card prenota-modal-card" role="dialog" aria-modal="true" aria-labelledby="pm-titolo">
        <div class="card-content">
          <div class="pm-head">
            <h3 id="pm-titolo" class="pm-titolo">Conferma la tua adesione</h3>
            <button type="button" class="pm-chiudi" onclick="chiudiPrenotaModal()" aria-label="Chiudi">&#10005;</button>
          </div>
          <p id="pm-quesito" class="pm-quesito">Come vuoi ricevere l'articolo?</p>
          <div class="pm-radios" id="pm-metodi" role="radiogroup" aria-labelledby="pm-quesito"></div>
          <p class="pm-msg-metodi" id="pm-msg-metodi" role="status" hidden></p>
          <div id="pm-indirizzo" class="pm-indirizzo" hidden>
            <div class="input-group pm-full"><label class="input-label" for="pm-via">Via e numero civico *</label><input id="pm-via" class="input" autocomplete="street-address" oninput="pmIndirizzoCambiato()" placeholder="Via Roma 1"></div>
            <div class="input-group"><label class="input-label" for="pm-cap">CAP *</label><input id="pm-cap" class="input" inputmode="numeric" autocomplete="postal-code" oninput="pmIndirizzoCambiato()" placeholder="06100"></div>
            <div class="input-group"><label class="input-label" for="pm-citta">Città *</label><input id="pm-citta" class="input" autocomplete="address-level2" oninput="pmIndirizzoCambiato()" placeholder="Perugia"></div>
            <div class="input-group pm-full"><label class="input-label" for="pm-provincia">Provincia</label><input id="pm-provincia" class="input" autocomplete="address-level1" oninput="pmIndirizzoCambiato()" placeholder="PG" maxlength="2"></div>
          </div>
          <div class="pm-riepilogo" id="pm-riepilogo" aria-live="polite"></div>
          <div id="pm-error" class="pm-errore" role="alert" style="display:none;"></div>
          <button id="pm-confirm" type="button" class="btn btn-default w-full pm-continua" disabled onclick="vaiStripeCheckout()">Continua su Stripe</button>
          <p class="pm-nota">Pagamento sicuro gestito da Stripe. Se il gruppo non raggiunge il minimo, l'acconto ti viene rimborsato al 100%.</p>
        </div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
  const overlay = document.getElementById('prenota-modal');
  // Clic fuori dalla finestra = chiusura (solo se il clic parte dall'overlay stesso)
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) window.chiudiPrenotaModal(); });
  pmKeyHandler = pmGestisciTastiera;
  document.addEventListener('keydown', pmKeyHandler);
  renderPmMetodi();
  pmAggiornaIndirizzo();
  pmAggiornaRiepilogo();
  pmAggiornaBottone();
  document.querySelector('#pm-metodi [role="radio"][aria-checked="true"]')?.focus();
  apiGet('/profilo').then(res => {
    const p = res.dati || {};
    if (!prenotaModal) return;
    const set = (id, k, v) => { if (!prenotaModal[k]) prenotaModal[k] = v || ''; const el = document.getElementById(id); if (el && !el.value) el.value = prenotaModal[k]; };
    set('pm-via', 'via', p.indirizzo);
    set('pm-cap', 'cap', p.cap);
    set('pm-citta', 'citta', p.citta);
    set('pm-provincia', 'provincia', p.provincia);
    pmAggiornaRiepilogo();
    pmAggiornaBottone();
  }).catch(() => {});
};

window.chiudiPrenotaModal = function() {
  document.getElementById('prenota-modal')?.remove();
  if (pmKeyHandler) { document.removeEventListener('keydown', pmKeyHandler); pmKeyHandler = null; }
  prenotaModal = null;
  // Il focus torna al pulsante che ha aperto la finestra
  if (pmOpener && document.contains(pmOpener)) pmOpener.focus();
  pmOpener = null;
};

/** Esc chiude; Tab resta dentro la finestra (focus trap); le frecce spostano la scelta tra le schede. */
function pmGestisciTastiera(e) {
  const modal = document.querySelector('#prenota-modal .prenota-modal-card');
  if (!modal || !prenotaModal) return;
  if (e.key === 'Escape') { e.preventDefault(); window.chiudiPrenotaModal(); return; }
  if (e.key === 'Tab') {
    const focusabili = [...modal.querySelectorAll('button:not([disabled]), input:not([disabled])')]
      .filter(el => el.offsetParent !== null && el.tabIndex >= 0);
    if (!focusabili.length) return;
    const primo = focusabili[0];
    const ultimo = focusabili[focusabili.length - 1];
    if (e.shiftKey && (document.activeElement === primo || !modal.contains(document.activeElement))) { e.preventDefault(); ultimo.focus(); }
    else if (!e.shiftKey && (document.activeElement === ultimo || !modal.contains(document.activeElement))) { e.preventDefault(); primo.focus(); }
    return;
  }
  if (['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp'].includes(e.key) && document.activeElement?.getAttribute('role') === 'radio') {
    const metodi = metodiConsegnaDisponibili().schede.filter(s => s.attiva).map(s => s.id);
    if (metodi.length < 2) return;
    e.preventDefault();
    const avanti = e.key === 'ArrowRight' || e.key === 'ArrowDown';
    const i = metodi.indexOf(prenotaModal.tipo);
    window.pmSelezionaMetodo(metodi[(i + (avanti ? 1 : metodi.length - 1)) % metodi.length]);
    document.querySelector('#pm-metodi [role="radio"][aria-checked="true"]')?.focus();
  }
}

function renderPmMetodi() {
  const wrap = document.getElementById('pm-metodi');
  if (!wrap || !prenotaModal) return;
  const disp = metodiConsegnaDisponibili();
  const costo = pmCostoSpedizione();
  const info = {
    ritiro_sede: { titolo: 'Ritiro in sede', sotto: 'Gratuito' + (pmSedeTesto(disp.sede) ? ' · ' + pmSedeTesto(disp.sede) : '') },
    consegna_domicilio: { titolo: 'Spedizione a domicilio', sotto: costo > 0 ? `${euro(costo)} · addebitati nel saldo` : 'Gratuita' }
  };
  wrap.innerHTML = disp.schede.map(({ id: m, attiva, motivo }) => {
    const sel = attiva && prenotaModal.tipo === m;
    const sotto = attiva ? info[m].sotto : motivo;
    return `<button type="button" role="radio" aria-checked="${sel}" ${attiva ? '' : 'aria-disabled="true" disabled'} tabindex="${sel ? 0 : -1}"
        class="pm-radio${sel ? ' is-sel' : ''}${attiva ? '' : ' is-off'}" ${attiva ? `onclick="pmSelezionaMetodo('${m}')"` : ''}>
      <span class="pm-radio-spunta" aria-hidden="true">${sel ? '&#10003;' : ''}</span>
      <span class="pm-radio-testi"><span class="pm-radio-titolo">${info[m].titolo}</span><span class="pm-radio-sotto">${escHtml(sotto)}</span></span>
    </button>`;
  }).join('');
  // Con una sola modalita (o nessuna) lo diciamo in chiaro invece di lasciare una scelta bloccata senza spiegazione
  const msg = document.getElementById('pm-msg-metodi');
  if (msg) { msg.textContent = disp.messaggio; msg.hidden = !disp.messaggio; }
}

window.pmSelezionaMetodo = function(tipo) {
  if (!prenotaModal || prenotaModal.redirecting) return;
  if (!metodiConsegnaDisponibili().schede.some(s => s.id === tipo && s.attiva)) return;
  prenotaModal.tipo = tipo;
  renderPmMetodi();
  pmAggiornaIndirizzo();
  pmAggiornaRiepilogo();
  pmAggiornaBottone();
};

/** Campi indirizzo: visibili e obbligatori SOLO con la spedizione (required/aria-required attivati e disattivati). */
function pmAggiornaIndirizzo() {
  const box = document.getElementById('pm-indirizzo');
  if (!box || !prenotaModal) return;
  const domicilio = prenotaModal.tipo === 'consegna_domicilio';
  box.hidden = !domicilio;
  ['pm-via', 'pm-cap', 'pm-citta'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.required = domicilio;
    if (domicilio) el.setAttribute('aria-required', 'true'); else el.removeAttribute('aria-required');
  });
  const campi = { 'pm-via': prenotaModal.via, 'pm-cap': prenotaModal.cap, 'pm-citta': prenotaModal.citta, 'pm-provincia': prenotaModal.provincia };
  Object.entries(campi).forEach(([id, v]) => { const el = document.getElementById(id); if (el && el.value !== v) el.value = v; });
}

window.pmIndirizzoCambiato = function() {
  if (!prenotaModal || prenotaModal.redirecting) return;
  prenotaModal.via = document.getElementById('pm-via')?.value || '';
  prenotaModal.cap = document.getElementById('pm-cap')?.value || '';
  prenotaModal.citta = document.getElementById('pm-citta')?.value || '';
  prenotaModal.provincia = document.getElementById('pm-provincia')?.value || '';
  pmAggiornaRiepilogo();
  pmAggiornaBottone();
};

function pmSceltaCompleta() {
  if (!prenotaModal) return false;
  if (!prenotaModal.tipo) return false;
  if (prenotaModal.tipo === 'ritiro_sede') return !!metodiConsegnaDisponibili().sedeId;
  return (prenotaModal.via.trim() !== '' && prenotaModal.cap.trim() !== '' && prenotaModal.citta.trim() !== '');
}

function pmAggiornaRiepilogo() {
  const box = document.getElementById('pm-riepilogo');
  if (!box || !prenotaModal) return;
  const c = currentCampagna || {};
  const nomeProd = c.prodotto || 'Prodotto';
  const domicilio = prenotaModal.tipo === 'consegna_domicilio';
  const acconto = pmAccontoStima(prenotaModal.quantita);
  // Stima del saldo sul prezzo del PRIMO scaglione: il prezzo finale dipende da quanti aderiscono al gruppo
  const st = saldoStima({
    quantita: prenotaModal.quantita, prezzo: pmPrimoPrezzo(),
    commissionePct: parseFloat(c.percentuale_commissione) || 0,
    spedizione: domicilio ? pmCostoSpedizione() : 0, acconto
  });
  const comprende = [`commissione di servizio ${euro(st.commissione)}`];
  if (domicilio && st.spedizione > 0) comprende.push(`spedizione ${euro(st.spedizione)}`);
  box.innerHTML = `
    <div class="pm-riep-prod">${escHtml(nomeProd)} &times; ${prenotaModal.quantita}</div>
    <div class="pm-riep-modo">${domicilio ? 'Spedizione a domicilio' : 'Ritiro in sede'}</div>
    <div class="pm-acconto">Acconto da pagare ora: <strong>${euro(acconto)}</strong></div>
    <p class="pm-saldo">Saldo previsto: ${euro(Math.max(0, st.saldo))} (stimato), addebitato solo se il gruppo raggiunge il minimo.
      Comprende ${comprende.join(' e ')}.</p>`;
}

function pmAggiornaBottone() {
  const btn = document.getElementById('pm-confirm');
  if (!btn || !prenotaModal) return;
  btn.disabled = !pmSceltaCompleta() || prenotaModal.redirecting;
  btn.textContent = prenotaModal.redirecting ? 'Reindirizzamento...' : 'Continua su Stripe';
}

/** Click su "Continua su Stripe": crea la Checkout Session e reindirizza a pagina intera. */
window.vaiStripeCheckout = async function() {
  if (!prenotaModal || prenotaModal.redirecting || !pmSceltaCompleta()) return;
  prenotaModal.redirecting = true;
  pmMostraErrore('');
  pmAggiornaBottone();
  try {
    const delivery = prenotaModal.tipo === 'ritiro_sede'
      ? { tipo: 'ritiro_sede', id_sede: metodiConsegnaDisponibili().sedeId }
      : { tipo: 'consegna_domicilio', via: prenotaModal.via.trim(), cap: prenotaModal.cap.trim(), citta: prenotaModal.citta.trim(), provincia: prenotaModal.provincia.trim() };
    const res = await apiPost('/pagamento/acconto-checkout', {
      colletta_id: currentIdColletta,
      quantita: prenotaModal.quantita,
      delivery
    });
    if (!res.dati?.checkout_url) throw new Error('URL di pagamento non ricevuto');
    window.location.href = res.dati.checkout_url;
  } catch (err) {
    pmMostraErrore(err.message || 'Errore nella creazione del pagamento.');
    if (prenotaModal) prenotaModal.redirecting = false;
    pmAggiornaBottone();
  }
};

function pmMostraErrore(msg) {
  const el = document.getElementById('pm-error');
  if (!el) return;
  if (!msg) { el.style.display = 'none'; el.textContent = ''; return; }
  el.textContent = msg;
  el.style.display = 'block';
}

function showToastOk(title, desc) {
  const toast = document.createElement('div');
  toast.className = 'toast toast-success';
  toast.innerHTML = `<div class="toast-content"><div class="toast-title">${title}</div>${desc ? `<div class="toast-description">${desc}</div>` : ''}</div>`;
  document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 4000);
}

/** Overlay di attesa post-pagamento: polling finché il webhook crea la prenotazione. */
function mostraAttesaPagamento() {
  if (document.getElementById('pagamento-attesa')) return;
  const overlay = document.createElement('div');
  overlay.id = 'pagamento-attesa';
  overlay.className = 'modal-overlay';
  overlay.innerHTML = `
    <div class="modal-content card" style="max-width:380px;width:92%;">
      <div class="card-content" style="text-align:center;padding:var(--space-8) var(--space-6);">
        <div class="animate-spin" style="display:inline-flex;margin-bottom:var(--space-4);color:var(--color-primary);">
          <svg width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        </div>
        <h3 style="margin-bottom:var(--space-2);">Stiamo confermando il tuo pagamento...</h3>
        <p class="text-sm text-secondary" id="pagamento-attesa-testo">Attendi qualche secondo.</p>
      </div>
    </div>`;
  document.body.appendChild(overlay);
}

function nascondiAttesaPagamento() {
  document.getElementById('pagamento-attesa')?.remove();
}

async function pollInVerifica(tentativi, sessionId) {
  if (tentativi >= 15) {
    const t = document.getElementById('pagamento-attesa-testo');
    if (t) t.textContent = 'Il pagamento è in elaborazione, riceverai una conferma via email a breve.';
    const spin = document.querySelector('#pagamento-attesa .animate-spin');
    if (spin) spin.classList.remove('animate-spin');
    try { history.replaceState(null, '', window.location.pathname + '#/campagne/' + currentIdColletta); } catch (e) {}
    return;
  }
  await new Promise(r => setTimeout(r, 2000));
  try {
    if (sessionId) {
      try {
        await apiGet(`/pagamento/stato?session_id=${encodeURIComponent(sessionId)}`);
      } catch (_) {}
    }
    const res = await apiGet(`/campagne/${currentIdColletta}`);
    currentCampagna = res.dati;
    if (currentCampagna.mia_partecipazione) {
      nascondiAttesaPagamento();
      showToastOk('Adesione confermata!', 'Trovi la tua adesione in "I miei acquisti".');
      renderDettaglio();
      window.aggiornaAcconto();
      startScadenzaMinuti();
      try { history.replaceState(null, '', window.location.pathname + '#/campagne/' + currentIdColletta); } catch (e) {}
      return;
    }
  } catch (_) {}
  pollInVerifica(tentativi + 1, sessionId);
}

window.handleAnnullaAdesione = async function(campagnaId) {
  if (!confirm('Vuoi davvero annullare la tua adesione a questo gruppo?')) return;
  try {
    await apiDelete(`/campagne/${campagnaId}/partecipazioni`);
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Adesione annullata</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
    const dettaglio = await apiGet(`/campagne/${campagnaId}`);
    currentCampagna = dettaglio.dati;
    renderDettaglio();
  } catch (err) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore</div><div class="toast-description">${err.message}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
};

/**
 * Per un gruppo terminato a cui l'utente ha aderito: la sua adesione reale (stato dell'ordine, acconto) da /mie/acquisti,
 * filtrata sull'utente della sessione dal server. Se non disponibile il banner usa solo i dati del gruppo.
 */
async function caricaMiaAdesione(c) {
  const terminato = ['conclusa', 'in_chiusura', 'non_riuscita'].includes(c.stato_campagna);
  const mia = c.mia_partecipazione;
  if (!terminato || !mia || !mia.id) return null;
  try {
    const res = await apiGet(`/mie/acquisti?id=${encodeURIComponent(mia.id)}`);
    return (res.dati?.acquisti || []).find(a => String(a.id) === String(mia.id)) || null;
  } catch (_) {
    return null;
  }
}

export async function DettaglioPage(params) {
  currentIdColletta = params.id;
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet(`/campagne/${params.id}`);
    currentCampagna = res.dati;
    currentFornitore = currentCampagna.fornitore_info || null;
    currentAcquisto = await caricaMiaAdesione(currentCampagna);
    try {
      const recRes = await apiGet(`/campagne/${params.id}/recensioni`);
      currentRecensioni = recRes.dati || { media: null, totale: 0, recensioni: [] };
    } catch (_) {
      currentRecensioni = { media: null, totale: 0, recensioni: [] };
    }
    renderDettaglio();
    let riapriAdesione = 0;
    try {
      const pend = JSON.parse(sessionStorage.getItem('buypool_pending_adesione') || 'null');
      if (pend && String(pend.id) === String(params.id)) {
        const qi = document.getElementById('qty-input');
        if (qi) qi.value = Math.max(1, Math.min(99, parseInt(pend.quantita) || 1));
        sessionStorage.removeItem('buypool_pending_adesione');
        riapriAdesione = isAuthenticated() && !isFornitore() && !isAdmin() ? Math.max(1, Math.min(99, parseInt(pend.quantita) || 1)) : 0;
      }
    } catch (_) {}
    window.aggiornaAcconto();
    if (riapriAdesione) window.handleAderisci();
    startScadenzaMinuti();
    try {
      const q = new URLSearchParams((window.location.hash.split('?')[1] || '').split('#')[0]);
      if (q.get('pagamento') === 'in_verifica') {
        mostraAttesaPagamento();
        pollInVerifica(0, q.get('session_id') || '');
      }
    } catch (_) {}
  } catch (err) {
    const backHref = isFornitore() ? '#/fornitore/gruppi' : '#/';
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p><a href="${backHref}" class="btn btn-default">Torna ai gruppi</a></div></div>`;
  }
}
