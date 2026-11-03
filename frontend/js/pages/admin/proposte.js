import { apiGet, apiPost } from '../../api.js';
import { esc } from '../../utils.js';
import { euro, plurale, dataGGMMAAAA } from '../../format.js';
import { apriModale } from '../../components/modal.js';
import { showToast } from '../../components/toast.js';
import { imgUrl } from './gruppi-azioni.js';

/* Coda admin delle proposte: schede, azioni e finestre. Schede, azioni consentite e scadenze arrivano dal server
   (GET /admin/proposte): qui non si ricalcola nulla. */

const SCHEDE = [['da_approvare', 'Da approvare'], ['in_votazione', 'In votazione'], ['in_valutazione', 'In valutazione'], ['gestite', 'Gestite']];
const CHIP = { 'Da approvare': 'adm-chip-ambra', 'In votazione': 'adm-chip-blu', 'In valutazione': 'adm-chip-teal', Pubblicata: 'adm-chip-verde', 'Non accolta': 'adm-chip-rosso', Scaduta: 'adm-chip-grigio' };

let dati = null;
let scheda = '';

const toast = (titolo, descrizione = '', variant = 'success') => showToast({ title: esc(titolo), description: esc(descrizione), variant });

function leggiScheda() {
  const s = new URLSearchParams((location.hash.split('?')[1]) || '').get('scheda');
  return SCHEDE.some(([k]) => k === s) ? s : '';
}

function cardHtml(p) {
  const pct = Math.min(100, Math.round((p.voti / p.soglia) * 100));
  const voti = ['in_votazione', 'in_valutazione'].includes(p.scheda) || p.voti > 0
    ? `<div class="adm-voti"><strong>${plurale(p.voti, 'voto', 'voti')}</strong> <span class="adm-sub">su ${p.soglia} per la valutazione</span>
        <span class="adm-barra" role="img" aria-label="${pct}% dei voti necessari"><span style="width:${pct}%"></span></span></div>` : '';
  const dettagli = [
    p.categoria ? `Categoria: ${esc(p.categoria)}` : '',
    p.fornitore_suggerito ? `Fornitore suggerito: ${esc(p.fornitore_suggerito)}` : '',
    p.prezzo_base ? `Prezzo indicato: ${esc(euro(p.prezzo_base))}` : '',
    p.moq ? `Minimo: ${p.moq}` : ''
  ].filter(Boolean).join(' · ');
  const bott = [];
  if (p.azioni.includes('approva')) bott.push(`<button type="button" class="adm-btn adm-btn-pri" data-azione="approva" data-id="${p.id}">Approva e pubblica</button>`);
  if (p.azioni.includes('crea_gruppo')) bott.push(`<button type="button" class="adm-btn adm-btn-pri" data-azione="crea" data-id="${p.id}">Crea gruppo da questa proposta</button>`);
  if (p.azioni.includes('rifiuta')) bott.push(`<button type="button" class="adm-btn adm-btn-sec" data-azione="rifiuta" data-id="${p.id}">${p.scheda === 'da_approvare' ? 'Rifiuta…' : 'Non accolta…'}</button>`);
  if (p.azioni.includes('chiedi_modifica')) bott.push(`<button type="button" class="adm-btn adm-btn-sec" data-azione="chiedi_modifica" data-id="${p.id}">Chiedi una modifica…</button>`);
  if (p.id_gruppo) bott.push(`<a class="adm-btn adm-btn-sec" href="#/admin/campagne/${p.id_gruppo}">${p.stato_gruppo === 'bozza' ? 'Apri la bozza del gruppo' : 'Apri il gruppo'}</a>`);
  return `<article class="adm-card adm-sezione adm-proposta">
    <div class="adm-proposta-corpo">
      ${p.foto ? `<img class="adm-proposta-foto" src="${esc(imgUrl(p.foto))}" alt="">` : '<div class="adm-proposta-foto adm-proposta-senza" aria-hidden="true"></div>'}
      <div class="adm-proposta-testi">
        <div class="adm-ordine-testa"><h2 class="adm-h3">${esc(p.titolo)}</h2><span class="adm-chip ${CHIP[p.stato_etichetta] || 'adm-chip-grigio'}">${esc(p.stato_etichetta)}</span></div>
        <span class="adm-sub">Proposta di ${esc(p.autore)} (${esc(p.autore_tipo)}) · ${esc(dataGGMMAAAA(p.data_proposta))}${p.scheda === 'da_approvare' && p.giorni > 0 ? ` · in attesa da ${plurale(p.giorni, 'giorno', 'giorni')}` : ''}</span>
        ${p.descrizione ? `<p class="adm-proposta-descr">${esc(p.descrizione)}</p>` : ''}
        ${dettagli ? `<span class="adm-sub">${dettagli}</span>` : ''}
        ${voti}
        ${(p.richieste_fornitore || []).map(r => `<div class="adm-avviso adm-avviso-ambra" role="status"><strong>Il fornitore chiede una modifica</strong> (${esc(dataGGMMAAAA(r.data))}): ${esc(r.testo)}
          <div><button type="button" class="adm-btn adm-btn-sec adm-btn-piccolo" data-azione="richiesta_gestita" data-richiesta="${r.id}">Segna come gestita</button></div></div>`).join('')}
        ${p.modifica_richiesta ? `<p class="adm-nota">In attesa del fornitore: gli hai chiesto «${esc(p.modifica_richiesta.testo)}».</p>` : ''}
        ${p.scheda === 'gestite' && p.motivo ? `<p class="adm-avviso adm-avviso-rosso">Motivo: ${esc(p.motivo)}</p>` : ''}
        ${p.id_gruppo && p.stato_gruppo === 'bozza' && p.scheda !== 'gestite' ? '<p class="adm-nota">È stata creata una bozza del gruppo: completala e pubblicala dalla sua scheda.</p>' : ''}
      </div>
    </div>
    ${bott.length ? `<div class="adm-azioni-scheda adm-azioni-sinistra">${bott.join('')}</div>` : ''}
  </article>`;
}

function disegna() {
  const root = document.getElementById('adm-proposte');
  if (!root) return;
  const elenco = dati.proposte.filter(p => p.scheda === scheda);
  root.innerHTML = `
    <div class="adm-pills" role="tablist" aria-label="Schede delle proposte">
      ${SCHEDE.map(([k, et]) => `<button type="button" role="tab" id="adm-tab-${k}" aria-selected="${k === scheda}" class="adm-pill${k === scheda ? ' attiva' : ''}" data-scheda="${k}">${et} <span class="adm-pill-n">${dati.contatori[k] ?? 0}</span></button>`).join('')}
    </div>
    ${dati.richieste_aperte > 0 ? `<p class="adm-avviso adm-avviso-ambra" role="status">${plurale(dati.richieste_aperte, 'richiesta di modifica', 'richieste di modifica')} dei fornitori da gestire: le trovi nelle proposte con il riquadro giallo.</p>` : ''}
    <div role="tabpanel" aria-labelledby="adm-tab-${scheda}">
      ${elenco.length ? `<div class="adm-pila">${elenco.map(cardHtml).join('')}</div>`
        : `<div class="adm-card"><p class="adm-vuoto">${({ da_approvare: 'Nessuna proposta da approvare.', in_votazione: 'Nessuna proposta in votazione.', in_valutazione: 'Nessuna proposta in valutazione.', gestite: 'Ancora nessuna proposta gestita.' })[scheda]}</p></div>`}
    </div>`;
}

/* ----------------------------- Azioni ----------------------------- */
function apriApprova(p, aggiorna) {
  apriModale({
    titolo: 'Approva e pubblica la proposta',
    introHtml: `<p><strong>${esc(p.titolo)}</strong>, di ${esc(p.autore)}.</p>`,
    corpoHtml: '<p class="adm-nota">La proposta diventa visibile ai clienti e può ricevere voti. Con 5 voti passa a «In valutazione». Il proponente riceve una notifica.</p>',
    etichettaConferma: 'Approva e pubblica',
    onConferma: async () => { await apiPost(`/admin/proposte/${p.id}/approva`, {}); toast('Proposta approvata', 'Ora è in votazione.'); scheda = 'in_votazione'; aggiorna(); }
  });
}

function apriRifiuta(p, aggiorna) {
  const motivi = Object.entries(dati.motivi);
  const nonAccolta = p.scheda !== 'da_approvare';
  const m = apriModale({
    titolo: nonAccolta ? 'Proposta non accolta' : 'Rifiuta la proposta',
    introHtml: `<p><strong>${esc(p.titolo)}</strong>, di ${esc(p.autore)}.</p>`,
    corpoHtml: `<fieldset class="adm-motivi"><legend class="adm-etichetta">Motivo (lo vedrà il proponente)</legend>
        ${motivi.map(([k, et], i) => `<label class="adm-spunta"><input type="radio" name="motivo" value="${esc(k)}" ${i === 0 ? '' : ''}> ${esc(et)}</label>`).join('')}
      </fieldset>
      <div class="adm-campo"><label for="adm-motivo-testo" id="adm-motivo-et">Dettaglio (facoltativo)</label>
        <textarea id="adm-motivo-testo" rows="3" maxlength="500"></textarea><span class="adm-contatore" id="adm-motivo-n">0 / 500</span></div>
      <p class="adm-nota">Il proponente riceve il motivo per email e notifica.</p>`,
    etichettaConferma: nonAccolta ? 'Segna come non accolta' : 'Rifiuta',
    variante: 'pericolo',
    onConferma: async (box) => {
      const motivo = box.querySelector('input[name=motivo]:checked')?.value;
      if (!motivo) throw new Error('Scegli il motivo dall\'elenco.');
      const testo = box.querySelector('#adm-motivo-testo').value.trim();
      if (motivo === 'altro' && testo.length < 3) throw new Error('Con «Altro» scrivi il motivo (almeno 3 caratteri).');
      await apiPost(`/admin/proposte/${p.id}/rifiuta`, { motivo, testo });
      toast(nonAccolta ? 'Proposta non accolta' : 'Proposta rifiutata', 'Il proponente è stato avvisato.');
      scheda = 'gestite';
      aggiorna();
    }
  });
  const box = m.el;
  const aggiornaEtichetta = () => {
    const altro = box.querySelector('input[name=motivo]:checked')?.value === 'altro';
    box.querySelector('#adm-motivo-et').textContent = altro ? 'Scrivi il motivo (obbligatorio)' : 'Dettaglio (facoltativo)';
  };
  box.querySelectorAll('input[name=motivo]').forEach(r => r.addEventListener('change', aggiornaEtichetta));
  box.querySelector('#adm-motivo-testo').addEventListener('input', (e) => { box.querySelector('#adm-motivo-n').textContent = `${e.target.value.length} / 500`; });
}

function apriChiediModifica(p, aggiorna) {
  apriModale({
    titolo: 'Chiedi una modifica al fornitore',
    introHtml: `<p><strong>${esc(p.titolo)}</strong>, di ${esc(p.autore)}.</p>`,
    corpoHtml: `<div class="adm-campo"><label for="adm-mod-testo">Che cosa deve cambiare?</label>
        <textarea id="adm-mod-testo" rows="4" maxlength="1000"></textarea><span class="adm-contatore" id="adm-mod-n">0 / 1000</span></div>
      <p class="adm-nota">Il fornitore riceve il messaggio per email e può modificare di nuovo la proposta finché non la reinvia. Lo stato della proposta non cambia.</p>`,
    etichettaConferma: 'Invia la richiesta',
    onConferma: async (box) => {
      const testo = box.querySelector('#adm-mod-testo').value.trim();
      if (testo.length < 3) throw new Error('Scrivi che cosa deve cambiare (almeno 3 caratteri).');
      await apiPost(`/admin/proposte/${p.id}/chiedi-modifica`, { testo });
      toast('Richiesta inviata', 'Il fornitore è stato avvisato.');
      aggiorna();
    }
  }).el.querySelector('#adm-mod-testo').addEventListener('input', (e) => { document.getElementById('adm-mod-n').textContent = `${e.target.value.length} / 1000`; });
}

async function apriCreaGruppo(p) {
  const forn = (await apiGet('/admin/fornitori')).dati?.fornitori || [];
  const scad = new Date(Date.now() + 30 * 86400000);
  scad.setMinutes(scad.getMinutes() - scad.getTimezoneOffset());
  apriModale({
    titolo: 'Crea gruppo da questa proposta',
    introHtml: `<p><strong>${esc(p.titolo)}</strong></p>
      <p class="adm-nota">Verrà creata una <strong>bozza</strong> con titolo, descrizione, foto${p.categoria ? ` e categoria (${esc(p.categoria)})` : ' e categoria'} della proposta. Non sarà visibile ai clienti finché non la pubblichi.</p>`,
    corpoHtml: `<form class="adm-form" onsubmit="return false" id="adm-form-gruppo">
      <div class="adm-campo adm-campo-pieno"><label for="g-forn">Fornitore</label><select id="g-forn" name="id_fornitore" required>
        ${p.id_fornitore_suggerito ? '' : '<option value="">Scegli il fornitore</option>'}
        ${forn.map(f => `<option value="${f.id}" ${f.id === p.id_fornitore_suggerito ? 'selected' : ''}>${esc(f.nome_azienda)}</option>`).join('')}</select></div>
      <div class="adm-campo"><label for="g-listino">Prezzo di listino (€)</label><input id="g-listino" type="number" name="prezzo_base" min="0.01" step="0.01" value="${p.prezzo_base ?? ''}"></div>
      <div class="adm-campo"><label for="g-pc">Prezzo scontato (€)</label><input id="g-pc" type="number" name="prezzo_corrente" min="0.01" step="0.01" value="${p.prezzo_corrente ?? p.prezzo_base ?? ''}"></div>
      <div class="adm-campo"><label for="g-moq">Minimo (se non ci sono scaglioni)</label><input id="g-moq" type="number" name="moq" min="1" value="${p.moq ?? 1}"></div>
      <div class="adm-campo"><label for="g-comm">Commissione (%)</label><input id="g-comm" type="number" name="commissione" min="0" max="100" step="0.5" value="10"></div>
      <div class="adm-campo adm-campo-pieno"><label for="g-scad">Scadenza provvisoria</label><input id="g-scad" type="datetime-local" name="scadenza" value="${scad.toISOString().slice(0, 16)}"></div>
    </form>`,
    etichettaConferma: 'Crea la bozza',
    onConferma: async (box) => {
      const f = box.querySelector('#adm-form-gruppo');
      if (!f.id_fornitore.value) throw new Error('Scegli il fornitore.');
      const body = { id_fornitore: parseInt(f.id_fornitore.value), prezzo_base: f.prezzo_base.value, prezzo_corrente: f.prezzo_corrente.value,
        moq: f.moq.value, commissione: f.commissione.value, scadenza: f.scadenza.value.replace('T', ' ') + ':00' };
      const r = (await apiPost(`/admin/proposte/${p.id}/crea-gruppo`, body)).dati;
      toast('Bozza creata', 'Completa gli scaglioni e pubblica il gruppo.');
      window.location.hash = `#/admin/campagne/${r.id_colletta}`;
    }
  });
}

async function carica() {
  dati = (await apiGet('/admin/proposte')).dati;
  if (!scheda) scheda = SCHEDE.find(([k]) => k !== 'gestite' && dati.contatori[k] > 0)?.[0] || 'da_approvare';
  disegna();
}

export async function AdminPropostePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  scheda = leggiScheda();
  content.innerHTML = `<div class="content-area adm-pagina"><div class="adm-intestazione"><h1 class="adm-h1">Proposte</h1></div>
    <div id="adm-proposte"><div class="loading-spinner">Caricamento...</div></div></div>`;
  const root = document.getElementById('adm-proposte');
  const aggiorna = () => carica().catch(e => toast('Pagina non aggiornata', e.message, 'error'));

  root.addEventListener('click', (e) => {
    const pill = e.target.closest('[data-scheda]');
    if (pill) { scheda = pill.dataset.scheda; history.replaceState(null, '', `#/admin/proposte?scheda=${scheda}`); disegna(); return; }
    const b = e.target.closest('[data-azione]');
    if (!b) return;
    if (b.dataset.azione === 'richiesta_gestita') {
      b.disabled = true;
      apiPost(`/admin/proposte/richieste/${parseInt(b.dataset.richiesta)}/gestita`, {}).then(() => { toast('Richiesta gestita', 'È uscita dal «Da fare».'); aggiorna(); })
        .catch(err => { b.disabled = false; toast('Operazione non riuscita', err.message, 'error'); });
      return;
    }
    const p = dati.proposte.find(x => x.id === parseInt(b.dataset.id));
    if (!p) return;
    if (b.dataset.azione === 'approva') apriApprova(p, aggiorna);
    if (b.dataset.azione === 'rifiuta') apriRifiuta(p, aggiorna);
    if (b.dataset.azione === 'chiedi_modifica') apriChiediModifica(p, aggiorna);
    if (b.dataset.azione === 'crea') apriCreaGruppo(p).catch(err => toast('Operazione non riuscita', err.message, 'error'));
  });
  root.addEventListener('keydown', (e) => {
    if (!e.target.closest?.('[role=tab]') || !['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    e.preventDefault();
    const i = SCHEDE.findIndex(([k]) => k === scheda);
    scheda = SCHEDE[(i + (e.key === 'ArrowRight' ? 1 : -1) + SCHEDE.length) % SCHEDE.length][0];
    history.replaceState(null, '', `#/admin/proposte?scheda=${scheda}`);
    disegna();
    document.getElementById(`adm-tab-${scheda}`)?.focus();
  });

  try {
    await carica();
  } catch (err) {
    root.innerHTML = `<div class="empty-state"><h2>Errore</h2><p class="text-secondary">${esc(err.message)}</p></div>`;
  }
}
