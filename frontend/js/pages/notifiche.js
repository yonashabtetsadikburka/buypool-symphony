import { apiGet, apiPost, apiPut } from '../api.js';
import { updateNotificationBadge } from '../components/header.js';
import { esc } from '../utils.js';
import { isAdmin, isFornitore } from '../auth.js';
import {
  CATEGORIE, SCHEDE, presenta, filtraNotifiche, contaSchede, schedaValida, raggruppaPerGiorno, frasePendenti, oraBreve,
} from '../notifiche-presentazione.js';

/* Notifiche del cliente. Filtro nella query string (#/notifiche?filtro=pagamenti): link condivisibile e larghezza della pagina
   invariata fra le schede. Le notifiche si leggono tutte (fino a 200) e si mostrano 20 alla volta. */

const PER_VOLTA = 20;
const ICONE = {
  PAGAMENTO: '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/>',
  RIMBORSO: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/>',
  GRUPPO: '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>',
  RITIRO: '<path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/>',
  PROPOSTA: '<path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"/>',
};

let tutte = [];
let scheda = 'tutte';
let visibili = PER_VOLTA;
let ascoltoAttivo = false;

function leggiScheda() {
  const q = new URLSearchParams((location.hash.split('?')[1]) || '');
  const nuova = schedaValida(q.get('filtro'));
  if (nuova !== scheda) visibili = PER_VOLTA;
  scheda = nuova;
}

function urlScheda(k) { return k === 'tutte' ? '#/notifiche' : `#/notifiche?filtro=${k}`; }

function rigaHtml(n) {
  const p = presenta(n);
  const c = CATEGORIE[p.categoria];
  const id = String(n.id);
  return `<li class="ntf-riga${p.letta ? '' : ' ntf-nonletta'}" data-id="${id}">
    <button type="button" class="ntf-main" data-apri="${id}" data-link="${esc(p.link)}">
      <span class="ntf-icona" style="background:${c.sfondo};color:${c.colore}" aria-hidden="true"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">${ICONE[p.categoria]}</svg></span>
      <span class="ntf-contenuto">
        <span class="ntf-meta"><span class="ntf-tipo" style="color:${c.colore}">${p.letta ? '' : '<span class="ntf-solo-sr">Non letta. </span>'}${c.etichetta}</span><span class="ntf-ora">${esc(oraBreve(n.data_creazione))}</span>${p.letta ? '' : '<span class="ntf-puntino" aria-hidden="true"></span>'}</span>
        <span class="ntf-titolo">${esc(p.titolo)}</span>
        <span class="ntf-dettaglio">${p.dettaglioHtml}</span>
      </span>
    </button>
    ${p.link ? `<a class="ntf-vedi" href="${esc(p.link)}" data-apri="${id}">${esc(p.testoLink)}</a>` : ''}
  </li>`;
}

function listaHtml(lista) {
  if (!lista.length) {
    return `<div class="ntf-vuoto"><p class="ntf-vuoto-titolo">Nessuna notifica</p><p>${scheda === 'da-leggere' ? 'Hai letto tutto.' : 'Quando succede qualcosa su pagamenti, gruppi e ritiri lo trovi qui.'}</p></div>`;
  }
  const mostrate = lista.slice(0, visibili);
  return raggruppaPerGiorno(mostrate).map(g => `<section class="ntf-giorno" aria-label="${esc(g.titolo)}">
      <h2 class="ntf-giorno-titolo">${esc(g.titolo)}</h2><ul class="ntf-elenco">${g.voci.map(rigaHtml).join('')}</ul></section>`).join('')
    + (lista.length > visibili ? `<div class="ntf-altre"><button type="button" class="ntf-btn-sec" id="ntf-altre">Mostra altre notifiche</button></div>` : '');
}

function disegna() {
  const root = document.getElementById('ntf-root');
  if (!root) return;
  const nonLette = tutte.filter(n => !n.letta).length;
  const conteggi = contaSchede(tutte);
  const lista = filtraNotifiche(tutte, scheda);
  root.innerHTML = `
    <div class="ntf-testa">
      <div><h1 class="ntf-h1">Notifiche</h1><p class="ntf-frase" role="status">${esc(frasePendenti(nonLette))}</p></div>
      ${nonLette > 0 ? '<button type="button" class="ntf-btn-sec" id="ntf-tutte-lette">Segna tutte come lette</button>' : ''}
    </div>
    <div class="ntf-schede" role="group" aria-label="Filtra le notifiche">
      ${SCHEDE.map(([k, et]) => `<button type="button" class="ntf-pill${k === scheda ? ' attiva' : ''}" data-scheda="${k}" aria-pressed="${k === scheda}">${et} <span class="ntf-pill-n">${conteggi[k]}</span></button>`).join('')}
    </div>
    <div class="ntf-card">${listaHtml(lista)}</div>`;
}

async function segnaLetta(id) {
  const n = tutte.find(x => String(x.id) === id);
  if (!n || n.letta) return;
  try {
    await apiPut(`/notifiche/${id}/letta`, {});
  } catch (err) {
    console.error('Segna come letta:', err);
    return;
  }
  n.letta = true;
  try { await updateNotificationBadge(); } catch (_) {}
}

function collegaEventi(root) {
  root.addEventListener('click', async (e) => {
    const pill = e.target.closest('[data-scheda]');
    if (pill) { window.location.hash = urlScheda(pill.dataset.scheda); return; }
    if (e.target.closest('#ntf-altre')) { visibili += PER_VOLTA; disegna(); return; }
    if (e.target.closest('#ntf-tutte-lette')) {
      try {
        await apiPost('/notifiche/marca-tutte-lette', {});
        tutte.forEach(n => { n.letta = true; });
        disegna();
        try { await updateNotificationBadge(); } catch (_) {}
      } catch (err) { console.error('Segna tutte come lette:', err); }
      return;
    }
    const apri = e.target.closest('[data-apri]');
    if (!apri) return;
    const id = apri.dataset.apri;
    const link = apri.tagName === 'A' ? '' : (apri.dataset.link || '');
    if (apri.tagName === 'A') {
      segnaLetta(id); // il link naviga da solo
      return;
    }
    await segnaLetta(id);
    if (link) window.location.hash = link; else disegna();
  });
}

export async function NotifichePage() {
  // L'admin non ha un feed di notifiche: la campanella porta alla dashboard ("Da fare")
  if (isAdmin()) { window.location.hash = '#/admin'; return; }
  // Anche il fornitore non ha un feed: la campanella porta alla Panoramica ("Da fare")
  if (isFornitore()) { window.location.hash = '#/fornitore'; return; }
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area ntf-pagina" id="ntf-root"><div class="loading-spinner">Caricamento...</div></div>';
  leggiScheda();
  collegaEventi(document.getElementById('ntf-root'));

  if (!ascoltoAttivo) {
    ascoltoAttivo = true;
    // Cambio scheda con la pagina gia' aperta: il router non la rimonta
    window.addEventListener('hashchange', () => {
      if (!document.getElementById('ntf-root') || !location.hash.startsWith('#/notifiche')) return;
      leggiScheda();
      disegna();
    });
  }

  try {
    const res = await apiGet('/notifiche');
    tutte = res.dati || [];
    disegna();
  } catch (err) {
    document.getElementById('ntf-root').innerHTML = `<div class="empty-state"><h2>Errore</h2><p class="text-secondary">${esc(err.message)}</p></div>`;
  }
}
