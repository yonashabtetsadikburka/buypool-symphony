import { apiGet, apiPost } from '../api.js';
import { esc } from '../utils.js';
import { apriModale } from '../components/modal.js';
import { showToast } from '../components/toast.js';
import { updateNotificationBadge } from '../components/header.js';
import { ordiniElencoHtml, schedaValida, problemaFormHtml, validaProblema, spedizioneFormHtml, validaSpedizione } from '../fornitore-ordini.js';

/* Pagina Ordini del fornitore: elenco a schede (nella query string) e azioni con finestra di conferma. */

const toast = (titolo, descrizione = '', variant = 'success') => showToast({ title: esc(titolo), description: esc(descrizione), variant });
let dati = null;
let ascolto = false;

const oggiIso = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };
const schedaDaUrl = () => schedaValida(new URLSearchParams((window.location.hash.split('?')[1]) || '').get('scheda'));

function disegna() {
  const content = document.getElementById('content-area');
  content.innerHTML = `<div id="frn-pagina">${ordiniElencoHtml(dati, schedaDaUrl(), new Date())}</div>`;
  // il listener sta sul contenitore della pagina (sostituito a ogni navigazione), non su #content-area
  document.getElementById('frn-pagina').addEventListener('click', (e) => {
    const pill = e.target.closest('[data-scheda]');
    if (pill) { window.location.hash = `#/fornitore/ordini?scheda=${pill.dataset.scheda}`; return; }
    const b = e.target.closest('[data-azione]');
    if (!b) return;
    const o = dati.ordini.find(x => x.id === b.dataset.id);
    if (!o) return;
    if (b.dataset.azione === 'conferma') apriConferma(o);
    if (b.dataset.azione === 'spedisci') apriSpedizione(o);
    if (b.dataset.azione === 'problema') apriProblema(o);
  });
}

async function carica() {
  dati = (await apiGet('/fornitore/ordini')).dati;
  disegna();
  try { await updateNotificationBadge(); } catch (_) {}
}

const dopo = (scheda) => { window.location.hash = `#/fornitore/ordini?scheda=${scheda}`; return carica(); };

function apriConferma(o) {
  apriModale({
    titolo: 'Conferma l\'ordine',
    introHtml: `<p><strong>${esc(o.prodotto)}</strong> · ordine n. ${esc(o.numero)}</p>`,
    corpoHtml: `<p class="adm-nota">Confermi di poter evadere ${esc(String(o.quantita_ordinata))} ${o.quantita_ordinata === 1 ? 'pezzo' : 'pezzi'}? La quantità è definitiva. L'ordine passa a «In preparazione». Se non puoi, chiudi questa finestra e segnala un problema.</p>`,
    etichettaConferma: 'Conferma ordine',
    onConferma: async () => { await apiPost(`/fornitore/ordini/${o.id}/conferma`, {}); toast('Ordine confermato', 'Ora è in preparazione.'); await dopo('in_preparazione'); }
  });
}

function apriProblema(o) {
  const m = apriModale({
    titolo: 'Segnala un problema',
    introHtml: `<p><strong>${esc(o.prodotto)}</strong> · ordine n. ${esc(o.numero)}</p>`,
    corpoHtml: problemaFormHtml(dati.motivi),
    etichettaConferma: 'Invia la segnalazione',
    onConferma: async (box) => {
      const motivo = box.querySelector('input[name=motivo]:checked')?.value || '';
      const testo = box.querySelector('#frn-prob-testo').value;
      const errore = validaProblema(motivo, testo, dati.motivi);
      if (errore) throw new Error(errore);
      await apiPost(`/fornitore/ordini/${o.id}/problema`, { motivo, testo });
      toast('Segnalazione inviata', 'BuyPool la vedrà tra le cose da fare.');
      await carica();
    }
  });
  const box = m.el;
  box.querySelectorAll('input[name=motivo]').forEach(r => r.addEventListener('change', () => {
    box.querySelector('#frn-prob-et').textContent = box.querySelector('input[name=motivo]:checked')?.value === 'altro' ? 'Descrivi il problema (obbligatorio)' : 'Dettagli (facoltativo)';
  }));
}

function apriSpedizione(o) {
  const oggi = oggiIso();
  const m = apriModale({
    titolo: 'Segna come spedito',
    introHtml: `<p><strong>${esc(o.prodotto)}</strong> · ordine n. ${esc(o.numero)}</p>`,
    corpoHtml: spedizioneFormHtml(dati.corrieri, oggi),
    etichettaConferma: 'Segna come spedito',
    onConferma: async (box) => {
      const v = { corriere: box.querySelector('#frn-corriere').value, corriere_altro: box.querySelector('#frn-corriere-altro').value,
        tracking: box.querySelector('#frn-tracking').value.trim(), data_spedizione: box.querySelector('#frn-data-sped').value };
      const errore = validaSpedizione(v, dati.corrieri, oggi, o.data_ordine);
      if (errore) throw new Error(errore);
      await apiPost(`/fornitore/ordini/${o.id}/spedisci`, v);
      toast('Ordine spedito', 'BuyPool segnerà l\'arrivo in sede.');
      await dopo('spediti');
    }
  });
  const box = m.el;
  box.querySelector('#frn-corriere').addEventListener('change', (e) => { box.querySelector('#frn-altro-box').hidden = e.target.value !== 'Altro'; });
}

export async function renderOrdini(content) {
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';
  try {
    await carica();
    if (!ascolto) {
      ascolto = true;
      // Cambio scheda con la pagina gia' aperta: il router non la rimonta
      window.addEventListener('hashchange', () => {
        if (!dati || !document.getElementById('frn-pagina') || !/^#\/fornitore\/ordini(\?|$)/.test(window.location.hash)) return;
        disegna();
      });
    }
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Ordini non disponibili</h2><p class="text-secondary">${esc(err.message)}</p></div></div>`;
  }
}
