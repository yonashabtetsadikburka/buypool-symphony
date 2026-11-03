import { apiGet, apiPost, apiPostForm } from '../api.js';
import { API_URL } from '../constants.js';
import { esc } from '../utils.js';
import { showToast } from '../components/toast.js';
import { proposteElencoHtml, propostaDettaglioHtml, schedaValida } from '../fornitore-proposte.js';
import {
  statoIniziale, moduloHtml, validaPasso, passoDelCampo, formDataProposta, scontoPct, parseNumero, PASSI, SCAGLIONI_MAX, FOTO_TIPI, FOTO_MAX_BYTES,
} from '../fornitore-proposta-form.js';

/* Pagine Proposte del fornitore: elenco (schede nella query string), dettaglio con "Chiedi una modifica", modulo guidato in 3 passi
   per creare, modificare e riproporre. La logica e l'HTML puri sono in fornitore-proposte.js e fornitore-proposta-form.js. */

const urlFoto = (p) => API_URL.replace(/\/api\/?$/, '') + '/api/' + String(p).replace(/^\//, '');
const esito = (titolo, descrizione, variant = 'success') => showToast({ title: titolo, description: esc(descrizione), variant });

let ascoltoElenco = false;
let datiElenco = null;

function schedaDaUrl() {
  const q = new URLSearchParams((window.location.hash.split('?')[1]) || '');
  return schedaValida(q.get('scheda'));
}

function paramDaUrl(nome) {
  return new URLSearchParams((window.location.hash.split('?')[1]) || '').get(nome);
}

/* ------------------------------ elenco ------------------------------ */

function disegnaElenco() {
  const content = document.getElementById('content-area');
  content.innerHTML = `<div id="frn-pagina">${proposteElencoHtml(datiElenco, schedaDaUrl(), urlFoto)}</div>`;
  // il listener sta sul contenitore della pagina (sostituito a ogni navigazione), non su #content-area: non resta attivo altrove
  document.getElementById('frn-pagina').addEventListener('click', (e) => {
    const pill = e.target.closest('[data-scheda]');
    if (pill) window.location.hash = pill.dataset.scheda === 'tutte' ? '#/fornitore/proposte' : `#/fornitore/proposte?scheda=${pill.dataset.scheda}`;
  });
}

async function elenco() {
  datiElenco = (await apiGet('/fornitore/proposte')).dati;
  disegnaElenco();
  if (!ascoltoElenco) {
    ascoltoElenco = true;
    // Cambio scheda con la pagina gia' aperta: il router non la rimonta
    window.addEventListener('hashchange', () => {
      if (!datiElenco || !document.getElementById('frn-pagina') || !/^#\/fornitore\/proposte(\?|$)/.test(window.location.hash)) return;
      disegnaElenco();
    });
  }
}

/* ------------------------------ dettaglio ------------------------------ */

async function dettaglio(content, id) {
  const p = (await apiGet(`/fornitore/proposte/${encodeURIComponent(id)}`)).dati;
  content.innerHTML = `<div id="frn-pagina">${propostaDettaglioHtml(p, urlFoto)}</div>`;
  const form = document.getElementById('frn-richiesta');
  if (!form) return;
  const area = document.getElementById('frn-richiesta-testo');
  area.addEventListener('input', () => { document.getElementById('frn-richiesta-n').textContent = `${[...area.value].length} / 1000`; });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const testo = area.value.trim();
    const errEl = document.getElementById('frn-richiesta-err');
    if (testo.length < 3) { errEl.textContent = 'Scrivi che cosa vuoi cambiare (almeno 3 caratteri)'; area.focus(); return; }
    const btn = document.getElementById('frn-richiesta-invia');
    btn.disabled = true;
    try {
      await apiPost(`/fornitore/proposte/${encodeURIComponent(id)}/richiesta`, { testo });
      esito('Richiesta inviata', 'BuyPool la vedrà tra le cose da fare.');
      await dettaglio(content, id);
    } catch (err) {
      errEl.textContent = err.message;
      btn.disabled = false;
    }
  });
}

/* ------------------------------ modulo in 3 passi ------------------------------ */

function montaModulo(content, { categorie, st, modifica, idProposta, riproponiDa }) {
  let errori = {};
  const titolo = modifica ? 'Modifica proposta' : 'Nuova proposta';
  const disegna = () => {
    content.innerHTML = `<div id="frn-pagina"><div class="content-area adm-pagina"><div class="frn-testa"><a class="adm-indietro" href="#/fornitore/proposte">← Tutte le proposte</a>
      <h1 class="adm-h1">${titolo}</h1></div>${moduloHtml(st, categorie, { modifica, urlFoto, errori })}</div></div>`;
    const primo = document.querySelector('#frn-form .frn-errore:not(:empty)');
    if (primo) primo.closest('.adm-campo, tr, form')?.querySelector('input, textarea, select')?.focus();
    collega();
  };

  const aggiornaSconti = () => {
    st.scaglioni.forEach((s, i) => {
      const c = document.getElementById(`frn-sconto-${i}`);
      const pct = scontoPct(st.listino, s.prezzo);
      if (c) c.textContent = pct !== null ? `${pct}%` : '—';
    });
  };

  const avanza = (delta) => {
    if (delta > 0) {
      errori = validaPasso(st.passo, st);
      if (Object.keys(errori).length) { disegna(); return; }
    }
    errori = {};
    st.passo = Math.min(PASSI.length, Math.max(1, st.passo + delta));
    disegna();
    window.scrollTo({ top: 0 });
  };

  const invia = async () => {
    for (let p = 1; p <= PASSI.length; p++) {
      const e = validaPasso(p, st);
      if (Object.keys(e).length) { errori = e; st.passo = p; disegna(); return; }
    }
    const btn = document.getElementById('frn-invia');
    const gen = document.getElementById('frn-errore-generale');
    if (btn) btn.disabled = true;
    try {
      await apiPostForm(modifica ? `/fornitore/proposte/${encodeURIComponent(idProposta)}` : '/fornitore/proposte', formDataProposta(st, riproponiDa));
      esito(modifica ? 'Proposta aggiornata' : 'Proposta inviata', modifica ? 'Le modifiche sono salvate.' : 'BuyPool la esaminerà: la trovi in "In revisione".');
      window.location.hash = '#/fornitore/proposte';
    } catch (err) {
      if (err.campi && Object.keys(err.campi).length) {
        errori = err.campi;
        st.passo = Math.min(...Object.keys(err.campi).map(passoDelCampo));
        disegna();
        const g = document.getElementById('frn-errore-generale');
        if (g) g.textContent = err.message;
      } else if (gen) {
        gen.textContent = err.message;
        if (btn) btn.disabled = false;
      }
    }
  };

  function collega() {
    const wrap = document.getElementById('frn-pagina');
    wrap.addEventListener('input', (e) => {
      const t = e.target;
      if (t.dataset.campo === 'nome') { st.nome = t.value; document.getElementById('frn-nome-n').textContent = `${[...t.value].length} / 80`; }
      else if (t.dataset.campo === 'descrizione') { st.descrizione = t.value; document.getElementById('frn-descr-n').textContent = `${[...t.value].length} / 1000`; }
      else if (t.dataset.campo === 'listino') { st.listino = t.value; aggiornaSconti(); }
      else if (t.dataset.campo === 'minimo') {
        st.minimo = t.value;
        const primo = wrap.querySelector('[data-scaglione="0"][data-k="soglia"]');
        if (primo) primo.value = t.value;
      } else if (t.dataset.scaglione !== undefined) {
        st.scaglioni[parseInt(t.dataset.scaglione, 10)][t.dataset.k] = t.value;
        aggiornaSconti();
      }
    });
    wrap.addEventListener('change', (e) => {
      const t = e.target;
      if (t.dataset.campo === 'id_categoria') st.id_categoria = t.value;
      if (t.dataset.campo === 'foto') {
        st.foto = t.files && t.files[0] ? t.files[0] : null;
        const box = document.getElementById('frn-anteprima');
        const er = wrap.querySelector('[data-err="foto"]');
        const problema = st.foto ? (!FOTO_TIPI.includes(st.foto.type) ? 'Formato non valido: ammessi JPG, PNG e WebP' : (st.foto.size > FOTO_MAX_BYTES ? 'La foto può pesare al massimo 5 MB' : '')) : '';
        if (er) er.textContent = problema;
        if (box) {
          box.innerHTML = '';
          if (st.foto && !problema) { const img = document.createElement('img'); img.alt = 'Anteprima della foto'; img.src = URL.createObjectURL(st.foto); box.appendChild(img); }
        }
        if (problema) { st.foto = null; t.value = ''; }
      }
    });
    wrap.addEventListener('click', (e) => {
      if (e.target.closest('#frn-avanti')) avanza(1);
      else if (e.target.closest('#frn-indietro')) avanza(-1);
      else if (e.target.closest('#frn-aggiungi')) { if (st.scaglioni.length < SCAGLIONI_MAX) { st.scaglioni.push({ soglia: '', prezzo: '' }); errori = {}; disegna(); } }
      else {
        const r = e.target.closest('[data-rimuovi]');
        if (r) { st.scaglioni.splice(parseInt(r.dataset.rimuovi, 10), 1); errori = {}; disegna(); }
      }
    });
    wrap.addEventListener('submit', (e) => {
      e.preventDefault();
      if (st.passo < PASSI.length) avanza(1); else invia();
    });
  }
  disegna();
}

async function modulo(content, { id = null, modifica = false }) {
  const riproponiDa = !modifica ? parseInt(paramDaUrl('riproponi'), 10) || null : null;
  const [cat, base] = await Promise.all([
    apiGet('/categorie').catch(() => ({ dati: [] })),
    (modifica || riproponiDa) ? apiGet(`/fornitore/proposte/${encodeURIComponent(modifica ? id : riproponiDa)}`) : Promise.resolve(null),
  ]);
  if (modifica && base && !base.dati.modificabile) {
    content.innerHTML = `<div id="frn-pagina"><div class="content-area adm-pagina"><a class="adm-indietro" href="#/fornitore/proposte/${Number(id)}">← Torna alla proposta</a>
      <div class="adm-card"><p class="adm-vuoto">Questa proposta è stata esaminata e non si può più modificare. Dalla sua pagina puoi chiedere una modifica a BuyPool.</p></div></div></div>`;
    return;
  }
  const st = statoIniziale(base ? base.dati : null);
  if (!modifica && base) st.fotoEsistente = base.dati.foto || null;
  montaModulo(content, { categorie: cat.dati || [], st, modifica, idProposta: id, riproponiDa });
}

/** Punto d'ingresso: vista = undefined (elenco) | 'nuova' | 'modifica' | 'dettaglio'. */
export async function renderProposte(content, params = {}) {
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';
  try {
    if (params.vista === 'nuova') await modulo(content, {});
    else if (params.vista === 'modifica') await modulo(content, { id: params.id, modifica: true });
    else if (params.vista === 'dettaglio') await dettaglio(content, params.id);
    else await elenco();
  } catch (err) {
    content.innerHTML = `<div class="content-area adm-pagina"><a class="adm-indietro" href="#/fornitore/proposte">← Tutte le proposte</a><div class="adm-card"><p class="adm-vuoto">${esc(err.message)}</p></div></div>`;
  }
}
