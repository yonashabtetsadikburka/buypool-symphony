import { apiGet, apiPost } from '../../api.js';
import { esc } from '../../utils.js';
import { plurale, dataGGMMAAAA, oraHHMM } from '../../format.js';
import { apriModale } from '../../components/modal.js';
import { showToast } from '../../components/toast.js';

/* Messaggi (ex "Invio notifiche"): comunicazioni straordinarie. Pagamenti, rimborsi e cambi di stato partono da soli.
   L'anteprima segue le stesse regole del server (messaggio_anteprima): l'avviso importante parte sempre anche per email. */

let dati = null;
let stato = { dest: 'cliente', cliente: null, gruppo: '', tipo: 'informazione', titolo: '', testo: '', email: false };
let gruppi = [];

const toast = (titolo, descrizione = '', variant = 'success') => showToast({ title: esc(titolo), description: esc(descrizione), variant });
const TIPI = { informazione: 'Informazione', importante: 'Avviso importante' };

const inviaEmail = () => stato.tipo === 'importante' || stato.email;

/** Stessa regola del server (messaggio_anteprima). */
function anteprima() {
  const tit = (stato.tipo === 'importante' ? 'Avviso importante: ' : '') + (stato.titolo.trim() || 'Titolo del messaggio');
  const testo = stato.testo.trim() || 'Il testo del messaggio comparirà qui.';
  return { notifica: `${tit} — ${testo.replace(/\s*\n\s*/g, ' ')}`, oggetto: tit, paragrafi: testo.split('\n').map(p => p.trim()).filter(Boolean) };
}

function aggiornaAnteprima() {
  const a = anteprima();
  const el = document.getElementById('adm-anteprima');
  if (!el) return;
  el.innerHTML = `
    <div class="adm-anteprima-box"><h3>Notifica nel sito</h3><div>${esc(a.notifica)}</div></div>
    ${inviaEmail() ? `<div class="adm-anteprima-box"><h3>Email</h3><div class="adm-anteprima-oggetto">Oggetto: ${esc(a.oggetto)}</div>
      ${a.paragrafi.map(p => `<p>${esc(p)}</p>`).join('')}</div>`
      : '<div class="adm-anteprima-box"><h3>Email</h3><div class="adm-sub">Nessuna email: il messaggio arriva solo come notifica.</div></div>'}`;
  document.getElementById('adm-n-titolo').textContent = `${stato.titolo.length} / ${dati.titolo_max}`;
  document.getElementById('adm-n-testo').textContent = `${stato.testo.length} / ${dati.testo_max}`;
  const chk = document.getElementById('adm-email');
  chk.checked = inviaEmail();
  chk.disabled = stato.tipo === 'importante';
  document.getElementById('adm-email-nota').hidden = stato.tipo !== 'importante';
}

function destinatariHtml() {
  if (stato.dest === 'cliente') {
    return `<div class="adm-campo"><label for="adm-cerca-cliente">Cliente</label>
      ${stato.cliente ? `<div class="adm-avviso">${esc(stato.cliente.nome)} · ${esc(stato.cliente.email)} <button type="button" class="adm-link" id="adm-cambia-cliente">Cambia</button></div>`
      : `<input id="adm-cerca-cliente" type="search" autocomplete="off" placeholder="Cerca per nome o email (almeno 2 lettere)"><ul class="adm-risultati" id="adm-risultati" hidden></ul>`}</div>`;
  }
  if (stato.dest === 'gruppo') {
    return `<div class="adm-campo"><label for="adm-gruppo">Gruppo</label><select id="adm-gruppo"><option value="">Scegli il gruppo</option>
      ${gruppi.map(g => `<option value="${g.id}" ${String(g.id) === String(stato.gruppo) ? 'selected' : ''}>${esc(g.prodotto)} (${plurale(g.adesioni, 'adesione', 'adesioni')})</option>`).join('')}</select></div>`;
  }
  return `<p class="adm-nota">Il messaggio arriva a tutti i clienti attivi. Invii «a tutti» di oggi: ${dati.tutti_oggi} su ${dati.tutti_max}.</p>`;
}

function storicoHtml() {
  if (!dati.invii.length) return '<p class="adm-vuoto">Ancora nessun messaggio inviato.</p>';
  return `<div class="adm-tabella-scroll"><table class="adm-tabella adm-tabella-compatta">
    <thead><tr><th scope="col">Data</th><th scope="col">Destinatari</th><th scope="col">Titolo</th><th scope="col">Tipo</th><th scope="col">Email</th></tr></thead>
    <tbody>${dati.invii.map(i => `<tr><td data-label="Data">${esc(dataGGMMAAAA(i.data))} ${esc(oraHHMM(i.data))}</td>
      <td data-label="Destinatari">${esc(i.destinatari)}<span class="adm-sub">${plurale(i.n, 'cliente', 'clienti')}</span></td>
      <td data-label="Titolo">${esc(i.titolo)}</td>
      <td data-label="Tipo"><span class="adm-chip ${i.tipo === 'importante' ? 'adm-chip-ambra' : 'adm-chip-grigio'}">${esc(TIPI[i.tipo] || i.tipo)}</span></td>
      <td data-label="Email">${i.email ? 'Sì' : 'No'}</td></tr>`).join('')}</tbody></table></div>`;
}

function disegna() {
  const root = document.getElementById('adm-messaggi');
  root.innerHTML = `
    <div class="adm-colonne">
      <form class="adm-card adm-sezione adm-form-colonna" id="adm-form-msg" onsubmit="return false" novalidate>
        <fieldset class="adm-opzioni"><legend class="adm-etichetta">Destinatari</legend>
          ${[['cliente', 'Un cliente'], ['gruppo', 'Partecipanti di un gruppo'], ['tutti', 'Tutti i clienti']].map(([k, et]) => `<label class="adm-spunta"><input type="radio" name="dest" value="${k}" ${stato.dest === k ? 'checked' : ''}> ${et}</label>`).join('')}</fieldset>
        <div id="adm-dest-box">${destinatariHtml()}</div>
        <fieldset class="adm-opzioni"><legend class="adm-etichetta">Tipo</legend>
          ${Object.entries(TIPI).map(([k, et]) => `<label class="adm-spunta"><input type="radio" name="tipo" value="${k}" ${stato.tipo === k ? 'checked' : ''}> ${et}</label>`).join('')}</fieldset>
        <div class="adm-campo"><label for="adm-titolo">Titolo</label><input id="adm-titolo" type="text" maxlength="${dati.titolo_max}" value="${esc(stato.titolo)}"><span class="adm-contatore" id="adm-n-titolo"></span></div>
        <div class="adm-campo"><label for="adm-testo">Messaggio (solo testo)</label><textarea id="adm-testo" rows="6" maxlength="${dati.testo_max}">${esc(stato.testo)}</textarea><span class="adm-contatore" id="adm-n-testo"></span></div>
        <div><label class="adm-spunta"><input type="checkbox" id="adm-email"> Invia anche per email</label>
          <p class="adm-nota" id="adm-email-nota" hidden>L'avviso importante parte sempre anche per email.</p></div>
        <div><button type="submit" class="adm-btn adm-btn-pri" id="adm-invia">Invia messaggio</button></div>
      </form>
      <div class="adm-anteprima" aria-live="polite"><h2 class="adm-h2">Anteprima per il cliente</h2><div id="adm-anteprima"></div></div>
    </div>
    <section class="adm-card adm-sezione"><h2 class="adm-h2">Inviati di recente</h2>${storicoHtml()}</section>`;
  aggiornaAnteprima();
}

async function carica() {
  dati = (await apiGet('/admin/messaggi')).dati;
  disegna();
}

function datiDestinatari() {
  return { tipo_destinatari: stato.dest, id_destinatario: stato.dest === 'cliente' ? stato.cliente?.id : (stato.dest === 'gruppo' ? parseInt(stato.gruppo) : undefined) };
}

async function invia() {
  const errori = [];
  if (stato.dest === 'cliente' && !stato.cliente) errori.push('Scegli il cliente.');
  if (stato.dest === 'gruppo' && !stato.gruppo) errori.push('Scegli il gruppo.');
  if (stato.titolo.trim().length < 3) errori.push('Scrivi un titolo (almeno 3 caratteri).');
  if (stato.testo.trim().length < 3) errori.push('Scrivi il messaggio (almeno 3 caratteri).');
  if (stato.dest === 'tutti' && dati.tutti_oggi >= dati.tutti_max) errori.push(`Hai già inviato ${dati.tutti_max} messaggi a tutti i clienti oggi.`);
  if (errori.length) { toast('Controlla il messaggio', errori.join(' '), 'error'); return; }

  const q = new URLSearchParams({ tipo_destinatari: stato.dest });
  const dd = datiDestinatari();
  if (dd.id_destinatario) q.set('id_destinatario', dd.id_destinatario);
  const conta = (await apiGet(`/admin/messaggi/conta?${q}`)).dati;
  if (conta.n === 0) { toast('Nessun destinatario', 'Non c\'è nessun cliente da raggiungere.', 'error'); return; }
  const a = anteprima();
  apriModale({
    titolo: `Inviare a ${plurale(conta.n, 'cliente', 'clienti')}?`,
    introHtml: `<p><strong>${esc(conta.etichetta)}</strong> · ${esc(TIPI[stato.tipo])} · ${inviaEmail() ? 'anche per email' : 'solo notifica'}</p>`,
    corpoHtml: `<div class="adm-anteprima-box"><h3>Notifica nel sito</h3><div>${esc(a.notifica)}</div></div><p class="adm-nota">Il messaggio non si può richiamare dopo l'invio.</p>`,
    etichettaConferma: `Invia a ${plurale(conta.n, 'cliente', 'clienti')}`,
    onConferma: async () => {
      const r = (await apiPost('/admin/messaggi', { ...dd, tipo: stato.tipo, titolo: stato.titolo, messaggio: stato.testo, invia_email: stato.email, conferma_n: conta.n })).dati;
      toast('Messaggio inviato', `${plurale(r.inviati, 'cliente raggiunto', 'clienti raggiunti')}${r.email ? ', anche per email' : ''}.`);
      stato = { ...stato, cliente: null, gruppo: '', titolo: '', testo: '', email: false };
      await carica();
    }
  });
}

export async function AdminMessaggiPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  stato = { dest: 'cliente', cliente: null, gruppo: '', tipo: 'informazione', titolo: '', testo: '', email: false };
  content.innerHTML = `<div class="content-area adm-pagina"><div class="adm-intestazione"><h1 class="adm-h1">Messaggi</h1></div>
    <p class="adm-nota">Per comunicazioni straordinarie. Pagamenti, rimborsi e cambi di stato partono da soli: non servono messaggi a mano.</p>
    <div id="adm-messaggi"><div class="loading-spinner">Caricamento...</div></div></div>`;
  const root = document.getElementById('adm-messaggi');
  let timer = null;

  root.addEventListener('input', (e) => {
    const t = e.target;
    if (t.id === 'adm-titolo') { stato.titolo = t.value; aggiornaAnteprima(); }
    else if (t.id === 'adm-testo') { stato.testo = t.value; aggiornaAnteprima(); }
    else if (t.id === 'adm-email') { stato.email = t.checked; aggiornaAnteprima(); }
    else if (t.id === 'adm-cerca-cliente') {
      clearTimeout(timer);
      const q = t.value.trim();
      const ul = document.getElementById('adm-risultati');
      if (q.length < 2) { ul.hidden = true; return; }
      timer = setTimeout(async () => {
        try {
          const r = (await apiGet(`/admin/messaggi/clienti?q=${encodeURIComponent(q)}`)).dati;
          ul.innerHTML = r.length ? r.map(u => `<li><button type="button" data-cliente='${esc(JSON.stringify(u))}'>${esc(u.nome)} · ${esc(u.email)}</button></li>`).join('') : '<li class="adm-vuoto">Nessun cliente trovato</li>';
          ul.hidden = false;
        } catch (err) { toast('Ricerca non riuscita', err.message, 'error'); }
      }, 250);
    }
  });
  root.addEventListener('change', (e) => {
    const t = e.target;
    if (t.name === 'dest') { stato.dest = t.value; document.getElementById('adm-dest-box').innerHTML = destinatariHtml(); }
    else if (t.name === 'tipo') { stato.tipo = t.value; aggiornaAnteprima(); }
    else if (t.id === 'adm-gruppo') stato.gruppo = t.value;
  });
  root.addEventListener('click', (e) => {
    const b = e.target.closest('[data-cliente]');
    if (b) { stato.cliente = JSON.parse(b.dataset.cliente); document.getElementById('adm-dest-box').innerHTML = destinatariHtml(); return; }
    if (e.target.id === 'adm-cambia-cliente') { stato.cliente = null; document.getElementById('adm-dest-box').innerHTML = destinatariHtml(); document.getElementById('adm-cerca-cliente')?.focus(); }
  });
  root.addEventListener('submit', (e) => {
    e.preventDefault();
    invia().catch(err => toast('Invio non riuscito', err.message, 'error'));
  });

  try {
    const g = (await apiGet('/admin/gruppi')).dati.gruppi;
    gruppi = g.filter(x => x.stato !== 'bozza' && x.adesioni > 0);
    await carica();
  } catch (err) {
    root.innerHTML = `<div class="empty-state"><h2>Errore</h2><p class="text-secondary">${esc(err.message)}</p></div>`;
  }
}
