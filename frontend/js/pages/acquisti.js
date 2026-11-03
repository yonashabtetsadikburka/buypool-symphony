import { apiGet, apiPost, apiDelete } from '../api.js';
import { apriShare } from '../components/share.js';
import { showToast } from '../components/toast.js';
import { apriRecensioni } from '../components/recensioni-popup.js';
import { apriAumenta } from '../components/aumenta-dialog.js';
import { showQrModal } from '../qr-modal.js';
import { esc } from '../utils.js';
import { API_URL } from '../constants.js';
import { euro, motivoRimborso, gruppi, ritiri, pezzi, dataGGMMAAAA, dataGGMM, oraHHMM, scadenzaDaSecondi } from '../format.js';

/**
 * "I miei acquisti": partecipazioni e ordini del cliente in 4 schede (GET /mie/acquisti).
 * Scheda e ordinamento stanno nella query della rotta (#/acquisti?tab=in-corso&sort=recenti).
 */

const TABS = [
  ['in-corso', 'In corso'],
  ['in-arrivo', 'In arrivo'],
  ['conclusi', 'Conclusi'],
  ['annullati', 'Annullati']
];
const ORDINAMENTI = { scadenza: 'Scadenza più vicina', recenti: 'Più recenti' };
const VUOTO = {
  'in-corso': 'Non hai acquisti in corso.',
  'in-arrivo': 'Non hai acquisti in arrivo.',
  conclusi: 'Non hai ancora ritirato nessun acquisto.',
  annullati: 'Non hai acquisti annullati.'
};

const ICONA = {
  pacco: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>',
  pin: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>',
  freccia: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M13 6l6 6-6 6"/>',
  orologio: '<circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2"/>',
  check: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>',
  qr: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/>',
  stella: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3z"/>',
  condividi: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>',
  lucchetto: '<rect x="5" y="11" width="14" height="10" rx="2" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0v4"/>',
  carta: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>'
};
const svg = (nome, dim = 18) =>
  `<svg width="${dim}" height="${dim}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${ICONA[nome]}</svg>`;

let dati = null;
let tab = '';          // '' = lascia scegliere al server
let sort = '';
let evidenzia = null;  // numero ordine da evidenziare (link da notifiche, redirect, ecc.)
let verifica = null;   // { sessionId, idAcquisto, attesa } dopo il ritorno da Stripe (aumento quantita')
let metodoAggiornato = false;   // ritorno da Stripe dopo "Aggiorna il metodo di pagamento"
let ascoltoAttivo = false;

function leggiParametri() {
  const q = new URLSearchParams((location.hash.split('?')[1]) || '');
  const t = q.get('tab');
  const s = q.get('sort');
  tab = TABS.some(([k]) => k === t) ? t : '';
  sort = s in ORDINAMENTI ? s : '';
  evidenzia = parseInt(q.get('id')) || null;
  metodoAggiornato = q.get('metodo') === 'ok';
  const sessionId = q.get('session_id');
  const attesa = parseInt(q.get('attesa')) || 0;
  verifica = q.get('pagamento') === 'in_verifica' && sessionId && evidenzia && attesa
    ? { sessionId, idAcquisto: evidenzia, attesa } : null;
}

function scriviParametri() {
  const q = new URLSearchParams({ tab: dati.tab });
  if (sort && sort !== sortPredefinito(dati.tab)) q.set('sort', sort);
  history.replaceState(null, '', `#/acquisti?${q}`);
}

const sortPredefinito = (t) => (t === 'in-corso' ? 'scadenza' : 'recenti');

function annuncia(testo) {
  const el = document.getElementById('acq-live');
  if (!el) return;
  el.textContent = '';
  setTimeout(() => { el.textContent = testo; }, 30);
}

function urlImmagine(percorso) {
  if (!percorso) return '';
  if (/^https?:\/\//i.test(percorso)) return percorso;
  return `${API_URL}/${String(percorso).replace(/^\//, '')}`;
}

// ---------- Testata ----------
function sintesi() {
  const r = dati.riepilogo;
  const totale = Object.values(dati.conteggi).reduce((a, b) => a + b, 0);
  const parti = [];
  if (r.gruppi_in_corso > 0) parti.push(`${gruppi(r.gruppi_in_corso)} in corso`);
  if (r.ritiri_pronti > 0) parti.push(`${ritiri(r.ritiri_pronti)} ${r.ritiri_pronti === 1 ? 'pronto' : 'pronti'}`);
  if (parti.length) return `Hai ${parti.join(' e ')}.`;
  if (totale === 0) return 'Non hai ancora acquisti.';
  return `Hai ${totale} ${totale === 1 ? 'acquisto' : 'acquisti'}.`;
}

function filtriHtml() {
  const pill = ([k, label]) => `<button type="button" class="acq-pill${dati.tab === k ? ' active' : ''}" data-tab="${k}" aria-pressed="${dati.tab === k}">${label} &middot; ${dati.conteggi[k] ?? 0}</button>`;
  const opzioni = dati.tab === 'in-corso' ? ['scadenza', 'recenti'] : ['recenti'];
  return `
    <div class="acq-pills" role="group" aria-label="Filtra gli acquisti">${TABS.map(pill).join('')}</div>
    <div class="acq-ordina">
      <label for="acq-sort">Ordina</label>
      <select id="acq-sort" class="acq-select"${opzioni.length === 1 ? ' disabled' : ''}>
        ${opzioni.map(o => `<option value="${o}"${dati.sort === o ? ' selected' : ''}>${ORDINAMENTI[o]}</option>`).join('')}
      </select>
    </div>`;
}

// ---------- Scheda ----------
function testoStato(a) {
  switch (a.vista) {
    case 'in_corso': return 'Il minimo non è ancora raggiunto';
    case 'attivo': return a.chiusura_in_corso
      ? 'Minimo raggiunto: chiusura del gruppo in corso'
      : `Minimo raggiunto: il gruppo si chiuderà il ${dataGGMM(a.data_limite)}`;
    case 'pagamento': return 'Non siamo riusciti ad addebitare il saldo';
    case 'inviato': return 'In preparazione';
    case 'pronto': return `${svg('pin', 16)} ${a.ritiro?.sede_nome ? `Ritiro ${esc(a.ritiro.sede_nome)}` : 'Ritiro in sede'}`;
    case 'ritirato': {
      const quando = a.ritiro?.data;
      const verbo = a.spedizione ? 'Consegnato' : 'Ritirato';
      return quando ? `${verbo} il ${dataGGMMAAAA(quando)} alle ${oraHHMM(quando)}` : verbo;
    }
    case 'rimborsato': return a.rimborso?.data ? `Rimborsato il ${dataGGMMAAAA(a.rimborso.data)}` : 'Rimborso in elaborazione';
    case 'annullato': return 'Annullato';
    default: return '';
  }
}

function puntiniHtml(a) {
  if (!a.passo) return '';
  const colore = a.concluso ? 'acq-dot-verde' : 'acq-dot-blu';
  let h = '';
  for (let i = 1; i <= 6; i++) h += `<span class="acq-dot${i <= a.passo ? ` ${colore}` : ''}"></span>`;
  return `<span class="acq-dots" aria-hidden="true">${h}</span>`;
}

// ---------- Fascia centrale: gruppo / importi / azioni ----------
function barraHtml(a) {
  const o = a.gruppo.obiettivo;
  const att = a.gruppo.quantita_attuale;
  const pct = Math.max(0, Math.min(100, Math.round((o.pct || 0) * 100)));
  return `<div class="acq-bar" role="progressbar" aria-label="Avanzamento verso il prossimo traguardo"
      aria-valuemin="0" aria-valuemax="${o.soglia}" aria-valuenow="${Math.min(att, o.soglia)}"><div class="acq-bar-fill" style="width:${pct}%"></div></div>`;
}

function riquadroGruppoHtml(a) {
  const o = a.gruppo.obiettivo;
  const att = a.gruppo.quantita_attuale;
  const conteggio = o.tipo === 'massimo' ? `${att} unità` : `${att} / ${o.soglia} unità`;
  let messaggio;
  if (o.tipo === 'attivazione') {
    messaggio = `${o.mancano === 1 ? 'manca 1 unità' : `mancano ${o.mancano} unità`} per attivare il gruppo`;
  } else if (o.tipo === 'prezzo') {
    messaggio = `prezzo di gruppo sbloccato &middot; a ${o.soglia} unità scende ancora`;
  } else {
    messaggio = 'Prezzo più basso raggiunto';
  }
  return `
    <div class="acq-box acq-box-gruppo">
      <div class="acq-gruppo-riga"><strong>${conteggio}</strong><span>${messaggio}</span></div>
      ${barraHtml(a)}
      <div class="acq-scade">${svg('orologio', 16)} <span>${esc(scadenzaDaSecondi(a.gruppo.scade_tra_secondi))}</span></div>
    </div>`;
}

/** Voci separate del saldo (prezzo, commissione, spedizione, acconto), in una riga espandibile. */
function saldoDettaglioHtml(d) {
  if (!d) return '';
  const riga = (et, val) => `<div class="acq-det-riga"><span>${et}</span><span>${val}</span></div>`;
  return `<details class="acq-det">
    <summary>Come si calcola${d.stimato ? ' (stima)' : ''}</summary>
    ${riga('Prezzo del gruppo', euro(d.merce))}
    ${riga('Commissione di servizio', euro(d.commissione))}
    ${d.spedizione_scelta ? riga('Spedizione a domicilio', d.spedizione > 0 ? euro(d.spedizione) : 'gratuita') : ''}
    ${riga('Acconto già versato', '−' + euro(d.acconto))}
    ${riga('<strong>Saldo</strong>', `<strong>${euro(Math.max(0, d.saldo))}</strong>`)}
  </details>`;
}

function riquadroImportiHtml(a) {
  const righe = [];
  if (a.acconto > 0) {
    righe.push(`<div class="acq-imp"><div class="acq-imp-label">Acconto versato</div><div class="acq-imp-valore">${euro(a.acconto)}</div>
      <div class="acq-imp-sub">${esc(String(a.acconto_percent))}% del prezzo di gruppo</div></div>`);
  }
  if (typeof a.saldo_previsto === 'number') {
    righe.push(`<div class="acq-imp"><div class="acq-imp-label">Saldo previsto</div><div class="acq-imp-valore">${euro(a.saldo_previsto)}</div>
      <div class="acq-imp-sub">addebito automatico il ${dataGGMM(a.data_addebito)}, solo se il gruppo raggiunge il minimo</div>
      ${saldoDettaglioHtml(a.saldo_dettaglio)}</div>`);
  }
  return righe.length ? `<div class="acq-box acq-box-importi">${righe.join('')}</div>` : '';
}

function riquadroAzioniHtml(a) {
  const bottoni = [];
  if (a.vista === 'pagamento') {
    if (a.saldo_scadenza) {
      bottoni.push(`<p class="acq-avviso-ambra" role="alert">Pagamento del saldo non riuscito: aggiorna il metodo di pagamento entro il ${esc(dataGGMM(a.saldo_scadenza))} alle ${esc(oraHHMM(a.saldo_scadenza))}.</p>`);
    }
    bottoni.push(`<button type="button" class="acq-btn acq-btn-pri" data-azione="riprova" data-id="${a.id}">${svg('carta')} Aggiorna pagamento</button>`);
  } else {
    if (a.puo_aumentare) {
      bottoni.push(`<button type="button" class="acq-btn acq-btn-pri" data-azione="aumenta" data-id="${a.id}">Compra di più</button>`);
    }
    if (a.puo_aggiornare_metodo) {
      bottoni.push(`<button type="button" class="acq-btn acq-btn-sec2" data-azione="metodo" data-id="${a.id}">${svg('carta')} Aggiorna il metodo di pagamento</button>`);
    }
    bottoni.push(`<button type="button" class="acq-btn acq-btn-sec2" data-azione="invita" data-id="${a.id}" aria-haspopup="dialog">${svg('condividi')} Invita amici</button>`);
    if (a.puo_annullare) {
      bottoni.push(`<button type="button" class="acq-link-annulla" data-azione="annulla" data-id="${a.id}">Annulla adesione</button>`);
    } else if (a.annullo_bloccato) {
      bottoni.push(`<p class="acq-nota-lock">${svg('lucchetto', 16)} <span>Il minimo è raggiunto: l'adesione non si può più annullare.</span></p>`);
    }
  }
  return `<div class="acq-box acq-box-azioni">${bottoni.join('')}</div>`;
}

function riquadroTotaleHtml(a) {
  if (!(a.totale_pagato > 0)) return '';
  const risp = typeof a.risparmiato === 'number'
    ? `<div class="acq-imp"><div class="acq-imp-label">Risparmiato</div><div class="acq-imp-valore acq-risp">${euro(a.risparmiato)}</div>
        <div class="acq-imp-sub">rispetto al listino</div></div>`
    : '';
  return `<div class="acq-box acq-box-importi">
    <div class="acq-imp"><div class="acq-imp-label">Totale pagato</div><div class="acq-imp-valore">${euro(a.totale_pagato)}</div>
      <div class="acq-imp-sub">acconto + saldo, commissione inclusa</div></div>${risp}</div>`;
}

function notaHtml(testo, titolo) {
  return `<div class="acq-box acq-box-nota-chiara">${titolo ? `<p class="acq-nota-titolo">${esc(titolo)}</p>` : ''}<p>${esc(testo)}</p></div>`;
}

function fasciaCentraleAltre(a) {
  switch (a.vista) {
    case 'inviato':
      return `<div class="acq-centro acq-centro-2">${notaHtml("Il gruppo ha raggiunto il minimo e l'ordine è stato inoltrato al fornitore. Ti avvisiamo quando è pronto per il ritiro.")}${riquadroTotaleHtml(a)}</div>`;
    case 'pronto':
      return `<div class="acq-centro">${notaHtml('Il tuo ordine è pronto: mostra il QR code alla sede di ritiro.')}${riquadroTotaleHtml(a)}
        <div class="acq-box acq-box-azioni"><button type="button" class="acq-btn acq-btn-pri" data-azione="qr" data-id="${a.id}">${svg('qr')} Mostra QR</button></div></div>`;
    case 'ritirato': {
      const r = a.recensione || {};
      let azione;
      if (!r.prodotto_fatta) {
        azione = `<button type="button" class="acq-btn acq-btn-sec2" data-azione="recensione" data-id="${a.id}">Lascia una recensione sul prodotto</button>`;
      } else if (!r.fornitore_fatta) {
        azione = `<button type="button" class="acq-btn acq-btn-sec2" data-azione="recensione" data-id="${a.id}">Lascia una recensione sul fornitore</button>`;
      } else {
        azione = `<p class="acq-hai-recensito">${svg('check', 16)} Hai recensito</p>`;
      }
      const intro = a.spedizione ? 'Consegnato.' : 'Ritirato.';
      return `<div class="acq-centro">${notaHtml(`${intro} Com'è andata? La tua recensione aiuta gli altri clienti a scegliere.`)}${riquadroTotaleHtml(a)}
        <div class="acq-box acq-box-azioni">${azione}</div></div>`;
    }
    case 'rimborsato': {
      const rb = a.rimborso || {};
      const label = rb.in_elaborazione ? 'Acconto in rimborso' : 'Acconto rimborsato';
      const sub = rb.in_elaborazione ? 'in elaborazione, sul metodo di pagamento usato' : 'sul metodo di pagamento usato';
      const importi = rb.importo > 0
        ? `<div class="acq-box acq-box-importi"><div class="acq-imp"><div class="acq-imp-label">${label}</div><div class="acq-imp-valore">${euro(rb.importo)}</div><div class="acq-imp-sub">${sub}</div></div></div>`
        : '';
      return `<div class="acq-centro">${notaHtml(motivoRimborso(rb), 'Rimborso automatico')}${importi || '<div></div>'}
        <div class="acq-box acq-box-azioni"><a class="acq-btn acq-btn-sec2" href="#/">Vedi altri gruppi</a></div></div>`;
    }
    case 'annullato':
      return `<div class="acq-centro acq-centro-2">${notaHtml('Hai annullato prima che il gruppo raggiungesse il minimo.', 'Adesione annullata da te')}${riquadroTotaleHtml(a)}</div>`;
    default:
      return '';
  }
}

function fasciaCentraleHtml(a) {
  if (a.vista === 'in_corso' || a.vista === 'attivo') {
    return `<div class="acq-centro">${riquadroGruppoHtml(a)}${riquadroImportiHtml(a)}${riquadroAzioniHtml(a)}</div>`;
  }
  if (a.vista === 'pagamento') {
    return `<div class="acq-centro">
      <div class="acq-box acq-box-nota"><p>Non siamo riusciti ad addebitare il saldo. Aggiorna il pagamento per confermare il tuo acquisto.</p></div>
      ${riquadroImportiHtml(a)}${riquadroAzioniHtml(a)}</div>`;
  }
  return fasciaCentraleAltre(a);
}

function schedaHtml(a) {
  const href = a.id_colletta ? `#/campagne/${a.id_colletta}` : '';
  const foto = a.immagine
    ? `<img src="${esc(urlImmagine(a.immagine))}" alt="" loading="lazy">`
    : svg('pacco', 40);
  const titolo = href
    ? `<a class="acq-titolo-link" href="${href}">${esc(a.prodotto)}</a>`
    : esc(a.prodotto);
  return `
    <article class="acq-card" id="acq-card-${a.id}" data-id="${a.id}" aria-label="${esc(a.prodotto)}">
      <div class="acq-alta">
        ${href ? `<a class="acq-foto" href="${href}" tabindex="-1" aria-hidden="true">${foto}</a>` : `<div class="acq-foto">${foto}</div>`}
        <div class="acq-info">
          <h2 class="acq-titolo">${titolo}</h2>
          <dl class="acq-dati">
            <div><dt>Fornitore</dt><dd>${esc(a.fornitore)}</dd></div>
            <div><dt>Unità</dt><dd>${pezzi(a.quantita)}</dd></div>
            <div><dt>Ordine</dt><dd>#${a.numero_ordine}</dd></div>
            <div><dt>Adesione del</dt><dd>${esc(dataGGMMAAAA(a.data_prenotazione))}</dd></div>
          </dl>
        </div>
      </div>
      ${fasciaCentraleHtml(a)}
      <div class="acq-stato acq-stato-${a.vista}">
        <div class="acq-stato-sx"><span class="acq-etichetta">${esc(a.etichetta)}</span>${puntiniHtml(a)}</div>
        <div class="acq-stato-dx">${testoStato(a)}</div>
      </div>
    </article>`;
}

function listaHtml() {
  if (!dati.acquisti.length) {
    return `<div class="acq-vuoto"><p>${VUOTO[dati.tab]}</p>
      <a href="#/" class="acq-btn-sec">Esplora i gruppi ${svg('freccia')}</a></div>`;
  }
  return dati.acquisti.map(schedaHtml).join('');
}

function paginaHtml() {
  return `
    <header class="acq-head">
      <div>
        <h1>I miei acquisti</h1>
        <p id="acq-sintesi">${sintesi()}</p>
      </div>
      <a href="#/" class="acq-btn-sec">Esplora i gruppi ${svg('freccia')}</a>
    </header>
    <div class="acq-filtri" id="acq-filtri">${filtriHtml()}</div>
    <div class="acq-lista" id="acq-lista">${listaHtml()}</div>
    <div id="acq-live" class="acq-sr" aria-live="polite"></div>`;
}

// ---------- Caricamento ----------
async function carica(focusTab) {
  const q = new URLSearchParams();
  if (tab) q.set('tab', tab);
  if (sort) q.set('sort', sort);
  if (!tab && evidenzia) q.set('id', evidenzia);
  const root = document.getElementById('acq-root');
  if (!root) return;
  try {
    const res = await apiGet(`/mie/acquisti?${q}`);
    dati = res.dati;
    tab = dati.tab;
    sort = dati.sort;
    scriviParametri();
    if (metodoAggiornato) {
      metodoAggiornato = false;
      showToast({ title: 'Metodo di pagamento aggiornato', description: 'Useremo la nuova carta per addebitare il saldo.', variant: 'success' });
    }
    if (!document.getElementById('acq-lista')) {
      root.innerHTML = paginaHtml();
    } else {
      document.getElementById('acq-sintesi').textContent = sintesi();
      document.getElementById('acq-filtri').innerHTML = filtriHtml();
      document.getElementById('acq-lista').innerHTML = listaHtml();
    }
    if (focusTab) document.querySelector(`.acq-pill[data-tab="${dati.tab}"]`)?.focus();
    if (evidenzia) {
      const el = document.getElementById(`acq-card-${evidenzia}`);
      if (el) { el.classList.add('acq-card-evidenza'); el.scrollIntoView({ block: 'center' }); }
      evidenzia = null;
    }
  } catch (err) {
    root.innerHTML = `<div class="acq-vuoto"><h1>I miei acquisti</h1><p>Non riesco a caricare i tuoi acquisti. Riprova tra poco.</p>
      <a href="#/acquisti" class="acq-btn-sec">Riprova</a></div>`;
  }
}

function cambiaTab(nuovo) {
  tab = nuovo;
  sort = '';
  carica(true).then(() => annuncia(`Scheda ${TABS.find(([k]) => k === nuovo)[1]}: ${dati.acquisti.length} ${dati.acquisti.length === 1 ? 'acquisto' : 'acquisti'}.`));
}

function ascoltaHash() {
  if (ascoltoAttivo) return;
  ascoltoAttivo = true;
  // Link a #/acquisti?tab=... mentre la pagina e' gia' aperta: il router non la rimonta, riapplichiamo i parametri
  window.addEventListener('hashchange', () => {
    if (!document.getElementById('acq-root') || !location.hash.startsWith('#/acquisti')) return;
    leggiParametri();
    carica();
  });
}

// ---------- Azioni ----------
function trova(id) {
  return dati?.acquisti.find(a => a.id === id);
}

async function annullaAdesione(a) {
  const rimborso = a.acconto > 0 ? ` L'acconto di ${euro(a.acconto)} ti verrà rimborsato.` : '';
  if (!window.confirm(`Annullare l'adesione a "${a.prodotto}"?${rimborso}`)) return;
  try {
    const res = await apiDelete(`/campagne/${a.id_colletta}/partecipazioni`);
    const n = res.dati?.notificati || 0;
    showToast({
      title: 'Adesione annullata',
      description: res.dati?.soglia_persa
        ? esc(`Il gruppo non ha più il minimo: abbiamo avvisato ${n === 1 ? "l'altro partecipante" : `gli altri ${n} partecipanti`}.`)
        : esc(a.acconto > 0 ? 'Il tuo acconto è stato rimborsato.' : 'La tua adesione è stata annullata.'),
      variant: 'success'
    });
    annuncia('Adesione annullata.');
    await carica();
  } catch (err) {
    showToast({ title: 'Non è stato possibile annullare', description: esc(err.message), variant: 'error' });
  }
}

async function riprovaPagamento(a, bottone) {
  bottone.disabled = true;
  try {
    const res = await apiPost('/pagamento/riprova', { prenotazione_id: a.id });
    if (res.dati?.checkout_url) { window.location.href = res.dati.checkout_url; return; }
    bottone.disabled = false;
  } catch (err) {
    bottone.disabled = false;
    showToast({ title: 'Pagamento non aggiornato', description: esc(err.message), variant: 'error' });
  }
}

async function aggiornaMetodo(bottone) {
  bottone.disabled = true;
  try {
    const res = await apiPost('/pagamento/aggiorna-metodo', {});
    if (res.dati?.checkout_url) { window.location.href = res.dati.checkout_url; return; }
    bottone.disabled = false;
  } catch (err) {
    bottone.disabled = false;
    showToast({ title: 'Metodo di pagamento non aggiornato', description: esc(err.message), variant: 'error' });
  }
}

function invita(a, ancora) {
  apriShare(ancora, {
    url: `${location.origin}${location.pathname}#/campagne/${a.id_colletta}`,
    testo: `Unisciti al gruppo d'acquisto "${a.prodotto}" su BuyPool: più siamo, meno si paga!`,
    etichetta: 'Invita amici al gruppo'
  });
}

// ---------- Ritorno da Stripe dopo l'aumento ----------
const POLL_MAX = 15;
const POLL_MS = 2000;

/** Aspetta che la quantita' dell'acquisto raggiunga il valore atteso (webhook o self-healing di /pagamento/stato). */
async function attendiAumento(v) {
  const root = document.getElementById('acq-root');
  if (!root) return;
  root.innerHTML = `<div class="acq-vuoto" role="status" aria-live="polite">
    <div class="loading-spinner"></div><p>Stiamo confermando il tuo pagamento…</p></div>`;
  const q = (v.idAcquisto ? `id=${v.idAcquisto}` : '');
  for (let i = 0; i < POLL_MAX; i++) {
    try { await apiGet(`/pagamento/stato?session_id=${encodeURIComponent(v.sessionId)}`); } catch (e) { /* si riprova */ }
    try {
      const res = await apiGet(`/mie/acquisti?${q}`);
      const a = res.dati.acquisti.find(x => x.id === v.idAcquisto);
      if (a && a.quantita >= v.attesa) {
        verifica = null;
        tab = '';
        showToast({ title: 'Quantità aggiornata', description: esc(`Ora hai ${pezzi(a.quantita)} in "${a.prodotto}".`), variant: 'success' });
        evidenzia = v.idAcquisto;
        await carica();
        return;
      }
    } catch (e) { /* si riprova */ }
    await new Promise(r => setTimeout(r, POLL_MS));
  }
  verifica = null;
  showToast({ title: 'Conferma in corso', description: 'Il pagamento è stato ricevuto: la quantità si aggiornerà a breve. Ricarica la pagina tra qualche istante.', variant: 'default', duration: 8000 });
  evidenzia = v.idAcquisto;
  await carica();
}

export async function AcquistiPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  leggiParametri();
  dati = null;
  content.innerHTML = `<div class="content-area acq-page" id="acq-root"><div class="loading-spinner">Caricamento...</div></div>`;
  const root = document.getElementById('acq-root');
  root.addEventListener('click', (e) => {
    const pill = e.target.closest('.acq-pill');
    if (pill) { cambiaTab(pill.dataset.tab); return; }
    const b = e.target.closest('[data-azione]');
    if (!b) return;
    const a = trova(parseInt(b.dataset.id));
    if (!a) return;
    if (b.dataset.azione === 'invita') invita(a, b);
    if (b.dataset.azione === 'annulla') annullaAdesione(a);
    if (b.dataset.azione === 'riprova') riprovaPagamento(a, b);
    if (b.dataset.azione === 'metodo') aggiornaMetodo(b);
    if (b.dataset.azione === 'qr') showQrModal(a.id);
    if (b.dataset.azione === 'aumenta') {
      apriAumenta({
        prenotazioneId: a.id, prodotto: a.prodotto, quantitaAttuale: a.quantita, maxAggiuntivi: a.max_aggiuntivi,
        accontoUnitario: a.acconto_unitario, accontoPercent: a.acconto_percent
      });
    }
    if (b.dataset.azione === 'recensione') {
      apriRecensioni({
        fornitoreId: a.fornitore_id, nomeFornitore: a.fornitore, campagnaId: a.id_colletta, nomeProdotto: a.prodotto,
        mancaProdotto: !a.recensione?.prodotto_fatta, mancaFornitore: !a.recensione?.fornitore_fatta,
        dopo: () => carica()
      });
    }
  });
  root.addEventListener('change', (e) => {
    if (e.target.id === 'acq-sort') { sort = e.target.value; carica(); }
  });
  ascoltaHash();
  if (verifica) { attendiAumento(verifica); return; }
  carica();
}
