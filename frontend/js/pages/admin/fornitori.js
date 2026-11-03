import { apiGet, apiPost, apiPut } from '../../api.js';
import { esc } from '../../utils.js';
import { plurale, euro, dataGGMMAAAA } from '../../format.js';
import { apriModale } from '../../components/modal.js';
import { attivaMenuAzioni, menuAzioniHtml, chiudiMenuAzioni } from '../../components/menu-azioni.js';
import { showToast } from '../../components/toast.js';

/* Fornitori: una sola azione principale per riga ("Modifica" oppure "Reinvia invito") e il menu "⋯".
   Accesso, gruppi e dati incompleti arrivano dal server (GET /admin/fornitori); la validazione (P.IVA, email) e' del server. */

const SCHEDE = [['tutti', 'Tutti'], ['invito_pendente', 'Invito pendente'], ['incompleti', 'Dati incompleti']];

let dati = null;
let scheda = 'tutti';
let pag = null;   // pagamenti ai fornitori (GET /admin/pagamenti-fornitori)

const toast = (titolo, descrizione = '', variant = 'success') => showToast({ title: esc(titolo), description: esc(descrizione), variant });

const filtrati = () => dati.fornitori.filter(f => scheda === 'tutti' || (scheda === 'invito_pendente' ? f.accesso === 'invito_pendente' : f.dati_incompleti));

function accessoHtml(f) {
  if (f.accesso === 'attivo') return `<span class="adm-chip adm-chip-verde">Attivo</span><span class="adm-sub">${esc(f.utente || '')}</span>`;
  if (f.accesso === 'invito_pendente') return '<span class="adm-chip adm-chip-ambra">Invito pendente</span>';
  return '<span class="adm-chip adm-chip-grigio">Nessun accesso</span>';
}

function vociMenu(f) {
  const v = [];
  if (f.invito_link) v.push({ azione: 'copia', etichetta: 'Copia link invito' });
  if (f.accesso !== 'attivo') {
    if (f.accesso === 'nessuno' && f.email_contatto) v.push({ azione: 'invito', etichetta: 'Invia invito…' });
    v.push({ azione: 'collega', etichetta: 'Collega a un utente esistente…' });
  }
  if (f.accesso === 'invito_pendente') v.push({ azione: 'modifica', etichetta: 'Modifica' });
  return v;
}

function rigaHtml(f) {
  const principale = f.accesso === 'invito_pendente'
    ? `<button type="button" class="adm-btn adm-btn-pri adm-btn-piccolo" data-azione="invito" data-id="${f.id}">Reinvia invito</button>`
    : `<button type="button" class="adm-btn adm-btn-sec adm-btn-piccolo" data-azione="modifica" data-id="${f.id}">Modifica</button>`;
  return `<tr>
    <td data-label="Azienda"><button type="button" class="adm-link" data-azione="modifica" data-id="${f.id}">${esc(f.nome_azienda)}</button>${f.dati_incompleti ? `<span class="adm-chip adm-chip-ambra" title="Mancano: ${esc((f.completezza?.mancanti || []).join(', '))}">Dati incompleti${f.completezza ? ` · ${f.completezza.percentuale}%` : ''}</span>` : ''}<span class="adm-sub">${esc(f.email_contatto || 'Email mancante')}</span></td>
    <td data-label="Categoria">${esc(f.categoria || '—')}</td>
    <td data-label="P.IVA">${f.piva ? esc(f.piva) : '<span class="adm-chip adm-chip-ambra">Da completare</span>'}</td>
    <td data-label="Accesso">${accessoHtml(f)}</td>
    <td data-label="Gruppi">${f.gruppi_aperti} aperti · ${f.gruppi_totali} totali</td>
    <td class="adm-azioni-cella">${principale}${menuAzioniHtml(f.id, vociMenu(f), `Altre azioni per ${f.nome_azienda}`)}</td>
  </tr>`;
}

function disegna() {
  const root = document.getElementById('adm-fornitori');
  if (!root) return;
  const elenco = filtrati();
  root.innerHTML = `
    <div class="adm-pills" role="tablist" aria-label="Fornitori">
      ${SCHEDE.map(([k, et]) => `<button type="button" role="tab" id="adm-tab-${k}" aria-selected="${k === scheda}" class="adm-pill${k === scheda ? ' attiva' : ''}" data-scheda="${k}">${et} <span class="adm-pill-n">${dati.contatori[k] ?? 0}</span></button>`).join('')}
    </div>
    <div class="adm-card adm-tabella-box" role="tabpanel" aria-labelledby="adm-tab-${scheda}">
      ${elenco.length ? `<div class="adm-tabella-scroll"><table class="adm-tabella">
        <thead><tr><th scope="col">Azienda</th><th scope="col">Categoria</th><th scope="col">P.IVA</th><th scope="col">Accesso</th><th scope="col">Gruppi</th><th scope="col"><span class="adm-solo-sr">Azioni</span></th></tr></thead>
        <tbody>${elenco.map(rigaHtml).join('')}</tbody></table></div>` : '<p class="adm-vuoto">Nessun fornitore in questa scheda.</p>'}
    </div>`;
}

/* ----------------------------- Pagamenti ai fornitori ----------------------------- */
/** Ordini arrivati in sede e ancora da pagare, per fornitore, con l'IBAN per il bonifico. L'importo e' quello che vede il fornitore (stessa funzione del server). */
function disegnaPagamenti() {
  const el = document.getElementById('adm-pagamenti-forn');
  if (!el || !pag) return;
  const daPagare = pag.fornitori.filter(f => f.ordini.some(o => o.puo_pagare));
  if (!daPagare.length) { el.innerHTML = '<section class="adm-card adm-sezione"><h2 class="adm-h2">Pagamenti ai fornitori</h2><p class="adm-vuoto">Nessun pagamento da fare: gli ordini arrivati sono tutti pagati.</p></section>'; return; }
  el.innerHTML = `<section class="adm-card adm-sezione"><h2 class="adm-h2">Pagamenti ai fornitori</h2>
    <p class="adm-nota">Da pagare ora: <strong>${esc(euro(pag.dovuto_ora))}</strong>. Fai il bonifico e poi segna l'ordine come pagato: il fornitore riceve l'email.</p>
    ${daPagare.map(f => `<div class="adm-pag-fornitore"><h3 class="adm-h3">${esc(f.nome_azienda)} <span class="adm-sub">da pagare ora ${esc(euro(f.dovuto_ora))}</span></h3>
      <p class="adm-nota">${f.iban ? `IBAN: <strong>${esc(f.iban)}</strong>` : '<span class="adm-scaduto">IBAN mancante: chiedi al fornitore di inserirlo nella pagina Azienda.</span>'}</p>
      <div class="adm-tabella-scroll"><table class="adm-tabella adm-tabella-compatta"><thead><tr><th scope="col">Ordine</th><th scope="col">Prodotto</th><th scope="col" class="adm-num">Importo</th><th scope="col"><span class="adm-solo-sr">Azione</span></th></tr></thead>
        <tbody>${f.ordini.filter(o => o.puo_pagare).map(o => `<tr><td data-label="Ordine">${esc(o.numero)}</td><td data-label="Prodotto">${esc(o.prodotto)}</td><td data-label="Importo" class="adm-num">${esc(euro(o.importo))}</td>
          <td class="adm-azioni-cella"><button type="button" class="adm-btn adm-btn-pri adm-btn-piccolo" data-pagato="${o.id}">Segna come pagato</button></td></tr>`).join('')}</tbody></table></div></div>`).join('')}
  </section>`;
}

function apriPagato(idOrdine, aggiornaTutto) {
  const o = pag.fornitori.flatMap(f => f.ordini.map(x => ({ ...x, azienda: f.nome_azienda }))).find(x => x.id === idOrdine);
  if (!o) return;
  const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  const oggi = d.toISOString().slice(0, 10);
  apriModale({
    titolo: 'Segna come pagato',
    introHtml: `<p><strong>${esc(o.azienda)}</strong> · ordine n. ${esc(o.numero)} · ${esc(o.prodotto)}</p><p>Importo: <strong>${esc(euro(o.importo))}</strong></p>`,
    corpoHtml: `<div class="adm-campo"><label for="adm-data-pag">Data del bonifico</label><input id="adm-data-pag" type="date" max="${oggi}" value="${oggi}"></div>
      <p class="adm-nota">Il fornitore vedrà l'ordine come «Pagato» e riceverà l'email «Pagamento effettuato». L'importo è quello calcolato dal sistema (pezzi finali × prezzo del fornitore).</p>`,
    etichettaConferma: 'Segna come pagato',
    onConferma: async (box) => {
      const data = box.querySelector('#adm-data-pag').value;
      if (!data) throw new Error('Indica la data del bonifico.');
      await apiPost(`/admin/ordini-fornitore/${idOrdine}/pagato`, { data });
      toast('Ordine pagato', `${o.azienda} è stato avvisato.`);
      aggiornaTutto();
    }
  });
}

/* ----------------------------- Finestre ----------------------------- */
function moduloHtml(f = {}) {
  const c = (id, et, nome, val, extra = '') => `<div class="adm-campo"><label for="${id}">${et}</label><input id="${id}" name="${nome}" value="${esc(val ?? '')}" ${extra}></div>`;
  return `<form class="adm-form" id="adm-form-fornitore" onsubmit="return false">
    <div class="adm-campo adm-campo-pieno"><label for="f-nome">Nome azienda</label><input id="f-nome" name="nome_azienda" required maxlength="200" value="${esc(f.nome_azienda ?? '')}"></div>
    ${c('f-email', 'Email di contatto', 'email_contatto', f.email_contatto, 'type="email" maxlength="255"')}
    ${c('f-tel', 'Telefono', 'telefono', f.telefono, 'maxlength="30"')}
    ${c('f-piva', 'Partita IVA', 'piva', f.piva, 'maxlength="20" inputmode="numeric" placeholder="11 cifre"')}
    ${c('f-cat', 'Categoria', 'categoria', f.categoria, 'maxlength="100"')}
    ${c('f-sito', 'Sito web', 'sito_web', f.sito_web, 'maxlength="500"')}
    ${c('f-ind', 'Indirizzo', 'indirizzo', f.indirizzo, 'maxlength="255"')}
    <div class="adm-campo adm-campo-pieno"><label for="f-descr">Descrizione</label><textarea id="f-descr" name="descrizione" rows="3" maxlength="2000">${esc(f.descrizione ?? '')}</textarea></div>
    <label class="adm-spunta adm-campo-pieno"><input type="checkbox" name="partner_pubblico" ${f.partner_pubblico === false ? '' : 'checked'}> Visibile ai clienti tra i fornitori partner</label>
  </form>`;
}

function leggiModulo(box) {
  const f = box.querySelector('#adm-form-fornitore');
  if (!f.reportValidity()) throw new Error('Controlla i campi evidenziati.');
  const body = {};
  for (const n of ['nome_azienda', 'email_contatto', 'telefono', 'piva', 'categoria', 'sito_web', 'indirizzo', 'descrizione']) body[n] = f[n].value.trim();
  body.partner_pubblico = f.partner_pubblico.checked;
  return body;
}

function apriNuovo(aggiorna) {
  apriModale({
    titolo: 'Nuovo fornitore', corpoHtml: moduloHtml(), etichettaConferma: 'Crea fornitore',
    onConferma: async (box) => { await apiPost('/admin/fornitori', leggiModulo(box)); toast('Fornitore creato'); aggiorna(); }
  });
}

function apriModifica(f, aggiorna) {
  apriModale({
    titolo: 'Modifica fornitore', introHtml: `<p>${esc(f.nome_azienda)}</p>`, corpoHtml: moduloHtml(f), etichettaConferma: 'Salva',
    onConferma: async (box) => { await apiPut(`/admin/fornitori/${f.id}`, leggiModulo(box)); toast('Fornitore aggiornato'); aggiorna(); }
  });
}

function apriInvito(f, aggiorna) {
  if (!f.email_contatto) { toast('Email mancante', 'Inserisci prima l\'email di contatto dell\'azienda.', 'error'); apriModifica(f, aggiorna); return; }
  const m = apriModale({
    titolo: f.accesso === 'invito_pendente' ? 'Reinvia invito' : 'Invia invito',
    introHtml: `<p><strong>${esc(f.nome_azienda)}</strong> · ${esc(f.email_contatto)}</p><p class="adm-nota">Il link vale 7 giorni e si usa una sola volta. Generarlo annulla l'invito precedente.</p>`,
    corpoHtml: `<label class="adm-spunta"><input type="checkbox" id="adm-invito-mail" checked> Invia l'email a ${esc(f.email_contatto)}</label>
      <p><button type="button" class="adm-btn adm-btn-pri" id="adm-invito-genera">${f.accesso === 'invito_pendente' ? 'Reinvia invito' : 'Genera invito'}</button></p><div id="adm-invito-esito" aria-live="polite"></div>`
  });
  const box = m.el;
  box.querySelector('#adm-invito-genera').addEventListener('click', async (e) => {
    e.target.disabled = true;
    try {
      const r = (await apiPost(`/admin/fornitori/${f.id}/invito`, { invia_email: box.querySelector('#adm-invito-mail').checked })).dati;
      box.querySelector('#adm-invito-esito').innerHTML = `<p class="adm-avviso"><strong>Invito creato</strong><br><code class="adm-link-codice">${esc(r.link)}</code><br>
        <span class="adm-sub">${r.email_inviata ? 'Email inviata.' : 'Nessuna email inviata.'}</span></p><button type="button" class="adm-btn adm-btn-sec adm-btn-piccolo" id="adm-copia-nuovo">Copia il link</button>`;
      box.querySelector('#adm-copia-nuovo').addEventListener('click', () => copia(r.link));
      aggiorna();
    } catch (err) {
      e.target.disabled = false;
      toast('Invito non creato', err.message, 'error');
    }
  });
}

function copia(testo) {
  navigator.clipboard?.writeText(testo).then(() => toast('Link copiato'), () => toast('Copia non riuscita', 'Seleziona e copia il link a mano.', 'error'));
}

async function apriCollega(f, aggiorna) {
  const utenti = (await apiGet('/admin/utenti')).dati.utenti.filter(u => u.ruolo === 'cliente' || (u.ruolo === 'fornitore' && !u.id_fornitore));
  apriModale({
    titolo: 'Collega a un utente esistente',
    introHtml: `<p><strong>${esc(f.nome_azienda)}</strong></p>`,
    corpoHtml: utenti.length ? `<div class="adm-campo"><label for="adm-utente">Utente</label><select id="adm-utente"><option value="">Scegli l'utente</option>
        ${utenti.map(u => `<option value="${u.id}">${esc(u.nome)} (${esc(u.email)})</option>`).join('')}</select></div>
        <p class="adm-nota">L'utente diventa fornitore e vede l'area Fornitore di questa azienda. Un admin non può essere collegato.</p>`
      : '<p class="adm-avviso adm-avviso-ambra">Non ci sono utenti collegabili.</p>',
    etichettaConferma: utenti.length ? 'Collega' : undefined,
    onConferma: async (box) => {
      const id = parseInt(box.querySelector('#adm-utente').value);
      if (!id) throw new Error('Scegli l\'utente.');
      await apiPost(`/admin/fornitori/${f.id}/collega`, { utente_id: id });
      toast('Utente collegato', f.nome_azienda);
      aggiorna();
    }
  });
}

async function carica() {
  dati = (await apiGet('/admin/fornitori')).dati;
  disegna();
}

export async function AdminFornitoriPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  scheda = 'tutti';
  content.innerHTML = `<div class="content-area adm-pagina"><div class="adm-intestazione"><h1 class="adm-h1">Fornitori</h1>
    <button type="button" class="adm-btn adm-btn-pri" id="adm-nuovo-forn">Nuovo fornitore</button></div>
    <div id="adm-pagamenti-forn"></div>
    <div id="adm-fornitori"><div class="loading-spinner">Caricamento...</div></div></div>`;
  const root = document.getElementById('adm-fornitori');
  const caricaPagamenti = async () => { pag = (await apiGet('/admin/pagamenti-fornitori')).dati; disegnaPagamenti(); };
  const aggiorna = () => carica().catch(e => toast('Pagina non aggiornata', e.message, 'error'));
  const aggiornaTutto = () => Promise.all([carica(), caricaPagamenti()]).catch(e => toast('Pagina non aggiornata', e.message, 'error'));
  document.getElementById('adm-pagamenti-forn').addEventListener('click', (e) => {
    const b = e.target.closest('[data-pagato]');
    if (b) apriPagato(parseInt(b.dataset.pagato), aggiornaTutto);
  });
  const errore = (e) => toast('Operazione non riuscita', e.message, 'error');

  document.getElementById('adm-nuovo-forn').addEventListener('click', () => apriNuovo(aggiorna));
  attivaMenuAzioni(root);
  root.addEventListener('click', (e) => {
    const pill = e.target.closest('[data-scheda]');
    if (pill) { scheda = pill.dataset.scheda; disegna(); return; }
    const b = e.target.closest('[data-azione]');
    if (!b) return;
    const f = dati.fornitori.find(x => x.id === parseInt(b.dataset.id));
    if (!f) return;
    chiudiMenuAzioni();
    if (b.dataset.azione === 'modifica') apriModifica(f, aggiorna);
    if (b.dataset.azione === 'invito') apriInvito(f, aggiorna);
    if (b.dataset.azione === 'copia') copia(f.invito_link);
    if (b.dataset.azione === 'collega') apriCollega(f, aggiorna).catch(errore);
  });
  root.addEventListener('keydown', (e) => {
    if (!e.target.closest?.('[role=tab]') || !['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    e.preventDefault();
    const i = SCHEDE.findIndex(([k]) => k === scheda);
    scheda = SCHEDE[(i + (e.key === 'ArrowRight' ? 1 : -1) + SCHEDE.length) % SCHEDE.length][0];
    disegna();
    document.getElementById(`adm-tab-${scheda}`)?.focus();
  });

  caricaPagamenti().catch(e => { document.getElementById('adm-pagamenti-forn').innerHTML = `<p class="adm-avviso adm-avviso-rosso">Pagamenti non disponibili: ${esc(e.message)}</p>`; });
  try {
    await carica();
  } catch (err) {
    root.innerHTML = `<div class="empty-state"><h2>Errore</h2><p class="text-secondary">${esc(err.message)}</p></div>`;
  }
}
