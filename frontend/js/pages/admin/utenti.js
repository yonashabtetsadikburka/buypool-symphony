import { apiGet, apiPut, apiPost, apiDelete } from '../../api.js';
import { esc } from '../../utils.js';
import { euro, plurale, dataGGMMAAAA } from '../../format.js';
import { apriModale } from '../../components/modal.js';
import { attivaMenuAzioni, menuAzioniHtml, chiudiMenuAzioni } from '../../components/menu-azioni.js';
import { showToast } from '../../components/toast.js';
import { RUOLI_LABELS, STATI_PRENOTAZIONE_LABELS } from '../../constants.js';

/* Utenti: schede per ruolo, ricerca, ruolo e stato come etichette. Cambio ruolo e sospensione solo dal menu "⋯",
   con una finestra che spiega le conseguenze. Le regole (admin non assegnabile, niente auto-sospensione) le applica il server. */

const SCHEDE = [['tutti', 'Tutti'], ['clienti', 'Clienti'], ['fornitori', 'Fornitori'], ['admin', 'Admin'], ['sospesi', 'Sospesi']];
const CHIP_RUOLO = { cliente: 'adm-chip-grigio', fornitore: 'adm-chip-blu', admin: 'adm-chip-ambra' };

let dati = null;
let scheda = 'tutti';
let ricerca = '';

const toast = (titolo, descrizione = '', variant = 'success') => showToast({ title: esc(titolo), description: esc(descrizione), variant });

function filtrati() {
  const q = ricerca.trim().toLowerCase();
  return dati.utenti.filter(u => u.schede.includes(scheda)
    && (!q || u.nome.toLowerCase().includes(q) || u.email.toLowerCase().includes(q) || (u.azienda || '').toLowerCase().includes(q)));
}

function vociMenu(u) {
  if (u.id === dati.io) return [];
  const v = [];
  if (u.ruolo !== 'admin') v.push({ azione: 'ruolo', etichetta: 'Cambia ruolo…' });
  v.push(u.stato === 'sospeso' ? { azione: 'riattiva', etichetta: 'Riattiva account…' } : { azione: 'sospendi', etichetta: 'Sospendi account…', pericolo: true });
  v.push({ azione: 'reset', etichetta: 'Genera link per reimpostare la password…' });
  v.push({ azione: 'elimina', etichetta: 'Elimina utente…', pericolo: true });
  return v;
}

function rigaHtml(u) {
  const ruolo = `<span class="adm-chip ${CHIP_RUOLO[u.ruolo] || 'adm-chip-grigio'}">${esc(RUOLI_LABELS[u.ruolo] || u.ruolo)}</span>${u.azienda ? `<span class="adm-sub">${esc(u.azienda)}</span>` : ''}`;
  const stato = u.stato === 'attivo' ? '<span class="adm-chip adm-chip-verde">Attivo</span>'
    : `<span class="adm-chip adm-chip-rosso">Sospeso</span>${u.motivo_sospensione ? `<span class="adm-sub">Motivo: ${esc(u.motivo_sospensione)}</span>` : ''}`;
  return `<tr>
    <td data-label="Utente"><button type="button" class="adm-link" data-azione="dettagli" data-id="${u.id}">${esc(u.nome)}${u.id === dati.io ? ' (tu)' : ''}</button><span class="adm-sub">${esc(u.email)}</span></td>
    <td data-label="Ruolo">${ruolo}</td>
    <td data-label="Stato">${stato}</td>
    <td data-label="Adesioni" class="adm-num">${u.adesioni}</td>
    <td data-label="Iscrizione">${esc(dataGGMMAAAA(u.iscrizione))}</td>
    <td class="adm-azioni-cella"><button type="button" class="adm-btn adm-btn-sec adm-btn-piccolo" data-azione="dettagli" data-id="${u.id}">Dettagli</button>${menuAzioniHtml(u.id, vociMenu(u), `Altre azioni per ${u.nome}`)}</td>
  </tr>`;
}

function disegnaTabella() {
  const el = document.getElementById('adm-utenti-tab');
  if (!el) return;
  const elenco = filtrati();
  el.innerHTML = elenco.length ? `<div class="adm-tabella-scroll"><table class="adm-tabella">
      <thead><tr><th scope="col">Utente</th><th scope="col">Ruolo</th><th scope="col">Stato</th><th scope="col" class="adm-num">Adesioni</th><th scope="col">Iscrizione</th><th scope="col"><span class="adm-solo-sr">Azioni</span></th></tr></thead>
      <tbody>${elenco.map(rigaHtml).join('')}</tbody></table></div>`
    : `<p class="adm-vuoto">${ricerca ? 'Nessun utente corrisponde alla ricerca.' : 'Nessun utente in questa scheda.'}</p>`;
}

function disegna() {
  const root = document.getElementById('adm-utenti');
  if (!root) return;
  root.innerHTML = `
    <div class="adm-ricerca"><label for="adm-cerca-utenti" class="adm-etichetta">Cerca per nome o email</label>
      <input id="adm-cerca-utenti" type="search" autocomplete="off" value="${esc(ricerca)}"></div>
    <div class="adm-pills" role="tablist" aria-label="Utenti per ruolo">
      ${SCHEDE.map(([k, et]) => `<button type="button" role="tab" id="adm-tab-${k}" aria-selected="${k === scheda}" class="adm-pill${k === scheda ? ' attiva' : ''}" data-scheda="${k}">${et} <span class="adm-pill-n">${dati.contatori[k] ?? 0}</span></button>`).join('')}
    </div>
    <div class="adm-card adm-tabella-box" role="tabpanel" aria-labelledby="adm-tab-${scheda}" id="adm-utenti-tab"></div>`;
  disegnaTabella();
}

/* ----------------------------- Finestre ----------------------------- */
function apriSospendi(u, aggiorna) {
  apriModale({
    titolo: 'Sospendi l\'account',
    introHtml: `<p><strong>${esc(u.nome)}</strong> · ${esc(u.email)}</p>`,
    corpoHtml: `<ul class="adm-lista-semplice">
        <li>L'utente non potrà più accedere: la sessione si chiude subito.</li>
        <li>Le sue adesioni attive restano valide: gli addebiti dei saldi, i rimborsi e le email continuano come sempre.</li>
        <li>Puoi riattivare l'account quando vuoi.</li></ul>
      <div class="adm-campo"><label for="adm-motivo-sosp">Motivo (lo vedono solo gli admin)</label>
        <textarea id="adm-motivo-sosp" rows="3" maxlength="255" required></textarea></div>`,
    etichettaConferma: 'Sospendi',
    variante: 'pericolo',
    onConferma: async (box) => {
      const motivo = box.querySelector('#adm-motivo-sosp').value.trim();
      if (motivo.length < 3) throw new Error('Scrivi il motivo della sospensione.');
      await apiPut(`/utenti/${u.id}/stato`, { stato: 'sospeso', motivo });
      toast('Account sospeso', u.nome);
      aggiorna();
    }
  });
}

function apriRiattiva(u, aggiorna) {
  apriModale({
    titolo: 'Riattiva l\'account',
    introHtml: `<p><strong>${esc(u.nome)}</strong> potrà accedere di nuovo. Il motivo della sospensione viene cancellato.</p>`,
    etichettaConferma: 'Riattiva',
    onConferma: async () => { await apiPut(`/utenti/${u.id}/stato`, { stato: 'attivo' }); toast('Account riattivato', u.nome); aggiorna(); }
  });
}

async function apriRuolo(u, aggiorna) {
  const aFornitore = u.ruolo === 'cliente';
  let corpo;
  if (aFornitore) {
    const forn = ((await apiGet('/admin/fornitori')).dati.fornitori || []).filter(f => !f.id_utente);
    corpo = forn.length ? `<div class="adm-campo"><label for="adm-azienda">Azienda da collegare</label><select id="adm-azienda">
        <option value="">Scegli l'azienda</option>${forn.map(f => `<option value="${f.id}">${esc(f.nome_azienda)}</option>`).join('')}</select></div>
        <ul class="adm-lista-semplice"><li>L'utente diventa fornitore e vede l'area Fornitore dell'azienda scelta (gruppi, ordini, proposte).</li>
        <li>Non potrà più aderire ai gruppi come cliente.</li></ul>`
      : '<p class="adm-avviso adm-avviso-ambra">Non ci sono aziende senza account. Crea prima il fornitore dalla pagina Fornitori.</p>';
  } else {
    corpo = `<ul class="adm-lista-semplice"><li>L'utente diventa cliente e perde l'accesso all'area Fornitore.</li>
      <li>L'azienda <strong>${esc(u.azienda || '')}</strong> resta in elenco, senza account: i suoi gruppi e ordini non cambiano. Potrai collegarla a un altro utente.</li></ul>`;
  }
  apriModale({
    titolo: aFornitore ? 'Rendi fornitore' : 'Rendi cliente',
    introHtml: `<p><strong>${esc(u.nome)}</strong> · ${esc(u.email)}</p>`,
    corpoHtml: corpo + '<p class="adm-nota">Il ruolo di amministratore non si assegna da qui.</p>',
    etichettaConferma: aFornitore ? 'Rendi fornitore' : 'Rendi cliente',
    onConferma: async (box) => {
      let body = { ruolo: aFornitore ? 'fornitore' : 'cliente' };
      if (aFornitore) {
        const idF = parseInt(box.querySelector('#adm-azienda')?.value);
        if (!idF) throw new Error('Scegli l\'azienda da collegare.');
        body.id_fornitore = idF;
      }
      await apiPut(`/utenti/${u.id}/ruolo`, body);
      toast('Ruolo aggiornato', `${u.nome}: ${RUOLI_LABELS[body.ruolo]}`);
      aggiorna();
    }
  });
}

function apriReset(u) {
  const m = apriModale({
    titolo: 'Link per reimpostare la password',
    introHtml: `<p><strong>${esc(u.nome)}</strong> · ${esc(u.email)}</p><p class="adm-nota">Il link vale 60 minuti e si usa una volta sola. Generarlo annulla i link precedenti.</p>`,
    corpoHtml: `<label class="adm-spunta"><input type="checkbox" id="adm-reset-mail"> Invia anche per email all'utente</label>
      <p><button type="button" class="adm-btn adm-btn-pri" id="adm-reset-genera">Genera il link</button></p><div id="adm-reset-esito" aria-live="polite"></div>`
  });
  const box = m.el;
  box.querySelector('#adm-reset-genera').addEventListener('click', async (e) => {
    e.target.disabled = true;
    try {
      const r = (await apiPost(`/admin/utenti/${u.id}/reset-link`, { invia_email: box.querySelector('#adm-reset-mail').checked })).dati;
      box.querySelector('#adm-reset-esito').innerHTML = `<p class="adm-avviso"><strong>Link generato</strong><br><code class="adm-link-codice">${esc(r.link)}</code><br>
        <span class="adm-sub">${r.email_inviata ? "Email inviata all'utente." : 'Nessuna email inviata.'}</span></p>
        <button type="button" class="adm-btn adm-btn-sec adm-btn-piccolo" id="adm-copia">Copia il link</button>`;
      box.querySelector('#adm-copia').addEventListener('click', () => navigator.clipboard?.writeText(r.link).then(() => toast('Link copiato'), () => toast('Copia non riuscita', '', 'error')));
    } catch (err) {
      e.target.disabled = false;
      toast('Link non generato', err.message, 'error');
    }
  });
}

function apriElimina(u, aggiorna) {
  apriModale({
    titolo: 'Elimina utente',
    introHtml: `<p>Eliminare definitivamente <strong>${esc(u.nome)}</strong> (${esc(u.email)})? Si può fare solo se non ha adesioni né pagamenti.</p>`,
    etichettaConferma: 'Elimina',
    variante: 'pericolo',
    onConferma: async () => { await apiDelete(`/utenti/${u.id}`); toast('Utente eliminato'); aggiorna(); }
  });
}

async function apriDettagli(u) {
  const [det, sto] = await Promise.all([apiGet(`/utenti/${u.id}`), apiGet(`/utenti/${u.id}/storico`).catch(() => ({ dati: {} }))]);
  const d = det.dati, s = sto.dati || {};
  const dato = (k, v) => `<div><dt>${esc(k)}</dt><dd>${esc(v || '—')}</dd></div>`;
  const lista = (voci, vuoto) => voci.length ? `<ul class="adm-lista-semplice">${voci.join('')}</ul>` : `<p class="adm-nota">${vuoto}</p>`;
  apriModale({
    titolo: u.nome,
    introHtml: `<p>${esc(d.email)} · ${esc(RUOLI_LABELS[d.ruolo] || d.ruolo)}${d.azienda ? ` di ${esc(d.azienda)}` : ''} · ${d.stato === 'attivo' ? 'Attivo' : 'Sospeso'}</p>
      ${d.stato === 'sospeso' && d.motivo_sospensione ? `<p class="adm-avviso adm-avviso-rosso">Sospeso${d.data_sospensione ? ` il ${esc(dataGGMMAAAA(d.data_sospensione))}` : ''}. Motivo: ${esc(d.motivo_sospensione)}</p>` : ''}`,
    corpoHtml: `<dl class="adm-dati adm-dati-compatti">${dato('Iscrizione', dataGGMMAAAA(d.data_iscrizione))}${dato('Tipo', d.tipo)}${dato('Telefono', d.telefono)}
        ${dato('Indirizzo', [d.indirizzo, [d.cap, d.citta].filter(Boolean).join(' '), d.provincia ? `(${d.provincia})` : ''].filter(Boolean).join(', '))}
        ${dato('Codice fiscale', d.codice_fiscale)}${dato('Partita IVA', d.partita_iva)}</dl>
      <h3 class="adm-h4">Adesioni (${(s.partecipazioni || []).length})</h3>
      ${lista((s.partecipazioni || []).map(p => `<li>${esc(p.prodotto || 'Gruppo #' + p.id_colletta)} · ${plurale(p.quantita, 'pezzo', 'pezzi')} · ${esc(STATI_PRENOTAZIONE_LABELS[p.stato] || p.stato)}</li>`), 'Nessuna adesione.')}
      <h3 class="adm-h4">Pagamenti (${(s.pagamenti || []).length})</h3>
      ${lista((s.pagamenti || []).map(p => `<li>${esc(euro(p.importo))} · ${esc(p.tipo_pagamento)} · ${esc(p.stato)} · ${esc(p.prodotto || '')}</li>`), 'Nessun pagamento.')}
      <h3 class="adm-h4">Proposte (${(s.proposte || []).length})</h3>
      ${lista((s.proposte || []).map(p => `<li>${esc(p.nome_prodotto)} · ${plurale(p.tot_voti, 'voto', 'voti')} · ${esc(p.stato)}</li>`), 'Nessuna proposta.')}`
  });
}

async function carica() {
  dati = (await apiGet('/admin/utenti')).dati;
  disegna();
}

export async function AdminUtentiPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  scheda = 'tutti';
  ricerca = '';
  content.innerHTML = `<div class="content-area adm-pagina"><div class="adm-intestazione"><h1 class="adm-h1">Utenti</h1></div>
    <div id="adm-utenti"><div class="loading-spinner">Caricamento...</div></div></div>`;
  const root = document.getElementById('adm-utenti');
  const aggiorna = () => carica().catch(e => toast('Pagina non aggiornata', e.message, 'error'));
  const errore = (e) => toast('Operazione non riuscita', e.message, 'error');

  attivaMenuAzioni(root);
  root.addEventListener('click', (e) => {
    const pill = e.target.closest('[data-scheda]');
    if (pill) { scheda = pill.dataset.scheda; disegna(); return; }
    const b = e.target.closest('[data-azione]');
    if (!b) return;
    const u = dati.utenti.find(x => x.id === parseInt(b.dataset.id));
    if (!u) return;
    chiudiMenuAzioni();
    switch (b.dataset.azione) {
      case 'dettagli': apriDettagli(u).catch(errore); break;
      case 'ruolo': apriRuolo(u, aggiorna).catch(errore); break;
      case 'sospendi': apriSospendi(u, aggiorna); break;
      case 'riattiva': apriRiattiva(u, aggiorna); break;
      case 'reset': apriReset(u); break;
      case 'elimina': apriElimina(u, aggiorna); break;
    }
  });
  root.addEventListener('input', (e) => {
    if (e.target.id !== 'adm-cerca-utenti') return;
    ricerca = e.target.value;
    disegnaTabella();
  });
  root.addEventListener('keydown', (e) => {
    if (!e.target.closest?.('[role=tab]') || !['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    e.preventDefault();
    const i = SCHEDE.findIndex(([k]) => k === scheda);
    scheda = SCHEDE[(i + (e.key === 'ArrowRight' ? 1 : -1) + SCHEDE.length) % SCHEDE.length][0];
    disegna();
    document.getElementById(`adm-tab-${scheda}`)?.focus();
  });

  try {
    await carica();
  } catch (err) {
    root.innerHTML = `<div class="empty-state"><h2>Errore</h2><p class="text-secondary">${esc(err.message)}</p></div>`;
  }
}
