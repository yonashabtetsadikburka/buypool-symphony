import { apiGet } from '../../api.js';
import { esc } from '../../utils.js';
import { euro, pezzi, plurale, dataGGMMAAAA, oraHHMM } from '../../format.js';
import { attivaMenuAzioni, menuAzioniHtml, chiudiMenuAzioni } from '../../components/menu-azioni.js';
import { showToast } from '../../components/toast.js';
import { chipStato, eseguiAzione, vociMenu, apriNuovo, ETICHETTA_PRINCIPALE } from './gruppi-azioni.js';

/* Lista admin "Gruppi": schede con contatori, UNA etichetta di stato per riga, una sola azione principale e il menu "⋯".
   Stato, azioni consentite e schede arrivano dal server (GET /admin/gruppi): qui non si ricalcola nulla. */

const SCHEDE = [
  ['da_fare', 'Da fare'], ['aperti', 'Aperti'], ['in_arrivo', 'In arrivo'], ['conclusi', 'Conclusi'],
  ['non_attivati', 'Non attivati e annullati'], ['bozze', 'Bozze']
];

let dati = null;
let scheda = '';

function leggiScheda() {
  const q = new URLSearchParams((location.hash.split('?')[1]) || '');
  const s = q.get('scheda');
  return SCHEDE.some(([k]) => k === s) ? s : '';
}

function scadenzaHtml(g) {
  const quando = `${dataGGMMAAAA(g.data_limite)} ${oraHHMM(g.data_limite)}`;
  if (g.scaduto && ['aperto', 'attivo', 'scaduto_da_chiudere'].includes(g.stato)) {
    const n = g.giorni_scaduto;
    return `<span class="adm-scaduto">${esc(quando)}</span><span class="adm-sub adm-scaduto">${n < 1 ? 'scaduto oggi' : `scaduto da ${plurale(n, 'giorno', 'giorni')}`}</span>`;
  }
  return `<span>${esc(quando)}</span>`;
}

function pezziHtml(g) {
  const pct = Math.max(0, Math.min(100, Math.round((g.pezzi / Math.max(1, g.minimo)) * 100)));
  return `<span class="adm-pezzi">${g.pezzi} / ${g.minimo}</span>
    <span class="adm-barra" role="img" aria-label="${pct}% del minimo"><span style="width:${pct}%"></span></span>`;
}

function rigaHtml(g) {
  const principale = g.azione_principale;
  const href = principale === 'apri' ? `href="#/admin/campagne/${g.id}"` : '';
  const bottone = principale === 'apri'
    ? `<a class="adm-btn adm-btn-sec adm-btn-piccolo" ${href}>${ETICHETTA_PRINCIPALE.apri}</a>`
    : `<button type="button" class="adm-btn adm-btn-pri adm-btn-piccolo" data-azione="${principale}" data-id="${g.id}">${ETICHETTA_PRINCIPALE[principale]}</button>`;
  const avvisi = [];
  if (g.saldi_non_riusciti > 0) avvisi.push(`<span class="adm-sub adm-scaduto">${plurale(g.saldi_non_riusciti, 'saldo non riuscito', 'saldi non riusciti')}</span>`);
  if (g.costo_buypool > 0) avvisi.push(`<span class="adm-sub adm-scaduto">Costo a carico di BuyPool: ${esc(euro(g.costo_buypool))}</span>`);
  return `<tr>
    <td data-label="Gruppo"><a class="adm-titolo-link" href="#/admin/campagne/${g.id}">${esc(g.prodotto)}</a><span class="adm-sub">${esc(g.fornitore)}</span>${avvisi.join('')}</td>
    <td data-label="Stato">${chipStato(g.stato, g.stato_etichetta)}</td>
    <td data-label="Pezzi / minimo">${pezziHtml(g)}</td>
    <td data-label="Scadenza">${scadenzaHtml(g)}</td>
    <td data-label="Acconti incassati" class="adm-num">${esc(euro(g.acconti_incassati))}</td>
    <td data-label="Azione" class="adm-azioni-cella">${bottone}${menuAzioniHtml(g.id, vociMenu(g), `Altre azioni per ${g.prodotto}`)}</td>
  </tr>`;
}

function vuotoTesto() {
  return ({
    da_fare: 'Niente da fare: tutto è in ordine.', aperti: 'Nessun gruppo aperto.', in_arrivo: 'Nessun gruppo in arrivo.',
    conclusi: 'Nessun gruppo concluso.', non_attivati: 'Nessun gruppo non attivato o annullato.', bozze: 'Nessuna bozza.'
  })[scheda];
}

function disegna() {
  const root = document.getElementById('adm-gruppi');
  if (!root) return;
  const elenco = dati.gruppi.filter(g => g.schede.includes(scheda));
  root.innerHTML = `
    <div class="adm-pills" role="tablist" aria-label="Schede dei gruppi">
      ${SCHEDE.map(([k, et]) => `<button type="button" role="tab" id="adm-tab-${k}" aria-selected="${k === scheda}" class="adm-pill${k === scheda ? ' attiva' : ''}" data-scheda="${k}">${et} <span class="adm-pill-n">${dati.contatori[k] ?? 0}</span></button>`).join('')}
    </div>
    <div class="adm-card adm-tabella-box" role="tabpanel" aria-labelledby="adm-tab-${scheda}">
      ${elenco.length ? `<div class="adm-tabella-scroll"><table class="adm-tabella">
        <thead><tr><th scope="col">Gruppo</th><th scope="col">Stato</th><th scope="col">Pezzi / minimo</th><th scope="col">Scadenza</th><th scope="col" class="adm-num">Acconti incassati</th><th scope="col"><span class="adm-solo-sr">Azione</span></th></tr></thead>
        <tbody>${elenco.map(rigaHtml).join('')}</tbody></table></div>`
      : `<p class="adm-vuoto">${esc(vuotoTesto())}</p>`}
    </div>`;
}

function cambiaScheda(k, focus = false) {
  scheda = k;
  history.replaceState(null, '', `#/admin/campagne?scheda=${k}`);
  disegna();
  if (focus) document.getElementById(`adm-tab-${k}`)?.focus();
}

async function carica() {
  dati = (await apiGet('/admin/gruppi')).dati;
  if (!scheda) scheda = (dati.contatori.da_fare > 0) ? 'da_fare' : 'aperti';
  disegna();
}

export async function AdminCampagnePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  scheda = leggiScheda();
  content.innerHTML = `<div class="content-area adm-pagina">
    <div class="adm-intestazione"><h1 class="adm-h1">Gruppi</h1>
      <button type="button" class="adm-btn adm-btn-pri" id="adm-nuovo">Nuovo gruppo</button></div>
    <div id="adm-gruppi"><div class="loading-spinner">Caricamento...</div></div></div>`;
  const root = document.getElementById('adm-gruppi');
  const aggiorna = () => carica().catch(e => showToast({ title: 'Elenco non aggiornato', description: esc(e.message), variant: 'error' }));

  document.getElementById('adm-nuovo').addEventListener('click', () => {
    apriNuovo((r) => { if (r?.bozza) scheda = 'bozze'; aggiorna(); }).catch(e => showToast({ title: 'Impossibile aprire il modulo', description: esc(e.message), variant: 'error' }));
  });
  attivaMenuAzioni(root);
  root.addEventListener('click', (e) => {
    const pill = e.target.closest('[data-scheda]');
    if (pill) { cambiaScheda(pill.dataset.scheda); return; }
    const b = e.target.closest('[data-azione]');
    if (!b) return;
    const g = dati.gruppi.find(x => x.id === parseInt(b.dataset.id));
    if (!g) return;
    chiudiMenuAzioni();
    eseguiAzione(b.dataset.azione, g, aggiorna);
  });
  root.addEventListener('keydown', (e) => {
    const tab = e.target.closest?.('[role=tab]');
    if (!tab || !['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    e.preventDefault();
    const i = SCHEDE.findIndex(([k]) => k === scheda);
    const n = SCHEDE[(i + (e.key === 'ArrowRight' ? 1 : -1) + SCHEDE.length) % SCHEDE.length][0];
    cambiaScheda(n, true);
  });

  try {
    await carica();
  } catch (err) {
    root.innerHTML = `<div class="empty-state"><h2>Errore</h2><p class="text-secondary">${esc(err.message)}</p></div>`;
  }
}
