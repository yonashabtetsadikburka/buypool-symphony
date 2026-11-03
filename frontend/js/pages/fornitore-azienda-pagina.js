import { apiGet, apiPut } from '../api.js';
import { esc } from '../utils.js';
import { showToast } from '../components/toast.js';
import { impostaDaFare } from '../components/header.js';
import { pagamentiHtml } from '../fornitore-pagamenti.js';
import { aziendaHtml, barraHtml, completezza, validaAzienda, ibanFormattato, CAMPI } from '../fornitore-azienda.js';

/* Pagine Pagamenti (sola lettura) e Azienda (modulo con validazione) del fornitore. */

const toast = (titolo, descrizione = '', variant = 'success') => showToast({ title: esc(titolo), description: esc(descrizione), variant });

export async function renderPagamenti(content) {
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';
  try {
    const d = (await apiGet('/fornitore/pagamenti')).dati;
    content.innerHTML = `<div id="frn-pagina">${pagamentiHtml(d)}</div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Pagamenti non disponibili</h2><p class="text-secondary">${esc(err.message)}</p></div></div>`;
  }
}

const valori = (form) => Object.fromEntries(CAMPI.map(([k]) => [k, form.elements[k] ? form.elements[k].value : '']));

export async function renderAzienda(content) {
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';
  let f;
  try {
    f = (await apiGet('/fornitore/io')).dati;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Azienda non disponibile</h2><p class="text-secondary">${esc(err.message)}</p></div></div>`;
    return;
  }
  content.innerHTML = `<div id="frn-pagina">${aziendaHtml(f)}</div>`;
  const wrap = document.getElementById('frn-pagina');
  const form = document.getElementById('frn-azienda');

  const mostraErrori = (e) => form.querySelectorAll('[data-err]').forEach(s => { s.textContent = e[s.dataset.err] || ''; });
  // La barra si aggiorna mentre scrivi (stesse regole del server)
  form.addEventListener('input', () => { document.getElementById('frn-compl').outerHTML = barraHtml(completezza(valori(form))); });
  form.elements.iban.addEventListener('blur', (e) => { if (e.target.value.trim()) e.target.value = ibanFormattato(e.target.value); });

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const v = valori(form);
    const errori = validaAzienda(v);
    mostraErrori(errori);
    const gen = document.getElementById('frn-errore-generale');
    gen.textContent = '';
    if (Object.keys(errori).length) { form.querySelector(`[name="${Object.keys(errori)[0]}"]`)?.focus(); return; }
    const btn = document.getElementById('frn-az-salva');
    btn.disabled = true;
    try {
      await apiPut('/fornitore/io', v);
      toast('Dati salvati', 'Il profilo è aggiornato.');
      const f2 = (await apiGet('/fornitore/io')).dati;
      document.getElementById('frn-compl').outerHTML = barraHtml(f2.completezza);
      try { impostaDaFare((await apiGet('/fornitore/da-fare')).dati.totale); } catch (_) {}   // il profilo incompleto e' una voce del Da fare
    } catch (err) {
      if (err.campi) mostraErrori(err.campi);
      gen.textContent = err.message;
    } finally {
      btn.disabled = false;
    }
  });
  return wrap;
}
