import { apiGet } from '../api.js';
import { getState } from '../state.js';
import { esc } from '../utils.js';
import { panoramicaHtml } from '../fornitore-panoramica.js';
import { gruppiElencoHtml, gruppoDettaglioHtml, schedaValida } from '../fornitore-gruppi.js';
import { renderProposte } from './fornitore-proposte-pagina.js';
import { renderOrdini } from './fornitore-ordini-pagina.js';
import { renderPagamenti, renderAzienda } from './fornitore-azienda-pagina.js';
import { impostaDaFare } from '../components/header.js';

/* Area fornitore: punto d'ingresso delle sezioni Panoramica · Gruppi · Proposte · Ordini · Pagamenti · Azienda.
   Ogni sezione ha il suo modulo; qui c'e' solo l'instradamento (e il segnaposto di Pagamenti e Azienda). */

const SEZIONI_VALIDE = ['area', 'gruppi', 'proposte', 'ordini', 'pagamenti', 'azienda'];

/* --- Gruppi (sola lettura): elenco con schede nella query string (#/fornitore/gruppi?scheda=aperti) e dettaglio --- */
let gruppiDati = null;
let gruppiAscolto = false;

function schedaDaUrl() {
  const q = new URLSearchParams((window.location.hash.split('?')[1]) || '');
  return schedaValida(q.get('scheda'));
}

function disegnaElencoGruppi() {
  const content = document.getElementById('content-area');
  content.innerHTML = `<div id="frn-pagina">${gruppiElencoHtml(gruppiDati, schedaDaUrl())}</div>`;
  // il listener sta sul contenitore della pagina (sostituito a ogni navigazione), non su #content-area: non resta attivo altrove
  document.getElementById('frn-pagina').addEventListener('click', (e) => {
    const pill = e.target.closest('[data-scheda]');
    if (pill) window.location.hash = pill.dataset.scheda === 'tutti' ? '#/fornitore/gruppi' : `#/fornitore/gruppi?scheda=${pill.dataset.scheda}`;
  });
}

async function renderGruppi(content, params) {
  if (params.id) {
    try {
      const g = (await apiGet(`/fornitore/gruppi/${encodeURIComponent(params.id)}`)).dati;
      content.innerHTML = gruppoDettaglioHtml(g);
    } catch (err) {
      content.innerHTML = `<div class="content-area adm-pagina"><a class="adm-indietro" href="#/fornitore/gruppi">← Tutti i gruppi</a><div class="adm-card"><p class="adm-vuoto">${esc(err.message)}</p></div></div>`;
    }
    return;
  }
  try {
    gruppiDati = (await apiGet('/fornitore/gruppi')).dati;
    disegnaElencoGruppi();
    if (!gruppiAscolto) {
      gruppiAscolto = true;
      // Cambio scheda con la pagina gia' aperta: il router non la rimonta
      window.addEventListener('hashchange', () => {
        if (!gruppiDati || !document.getElementById('frn-pagina') || !/^#\/fornitore\/gruppi(\?|$)/.test(window.location.hash)) return;
        disegnaElencoGruppi();
      });
    }
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Gruppi non disponibili</h2><p class="text-secondary">${esc(err.message)}</p></div></div>`;
  }
}

export async function FornitorePage(params = {}) {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  const { user } = getState();
  if (!user || user.ruolo !== 'fornitore') {
    content.innerHTML = '<div class="content-area"><div class="empty-state"><h2 class="empty-state-title">Accesso non autorizzato</h2><p class="empty-state-description">Area riservata ai fornitori.</p></div></div>';
    return;
  }

  const sezione = params.sezione || 'area';
  if (!SEZIONI_VALIDE.includes(sezione)) {
    content.innerHTML = '<div class="content-area"><div class="empty-state"><h2 class="empty-state-title">Pagina non trovata</h2><a href="#/fornitore" class="btn btn-default">Torna alla Panoramica</a></div></div>';
    return;
  }

  if (sezione === 'area') {
    // Panoramica: una sola chiamata (stessa funzione del numero sulla campanella)
    try {
      const d = (await apiGet('/fornitore/panoramica')).dati;
      impostaDaFare(d.totale);
      content.innerHTML = panoramicaHtml(d);
    } catch (err) {
      content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Panoramica non disponibile</h2><p class="text-secondary">${esc(err.message)}</p></div></div>`;
    }
    return;
  }
  if (sezione === 'gruppi') { await renderGruppi(content, params); return; }
  if (sezione === 'proposte') { await renderProposte(content, params); return; }
  if (sezione === 'ordini') { await renderOrdini(content); return; }

  if (sezione === 'pagamenti') { await renderPagamenti(content); return; }
  await renderAzienda(content);
}
