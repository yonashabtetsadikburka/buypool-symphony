import { apiGet, apiPost } from '../../api.js';
import { API_URL } from '../../constants.js';
import { getState } from '../../state.js';
import { esc } from '../../utils.js';
import { euro, pezzi, plurale, dataGGMMAAAA, oraHHMM } from '../../format.js';
import { apriModale } from '../../components/modal.js';
import { showToast } from '../../components/toast.js';

/* "Ordini e ritiri": una sola pagina in ordine di fase. Dati e regole arrivano dal server (GET /admin/ordini-ritiri):
   qui si mostrano le schede e si inviano le azioni. I ritiri e i QR compaiono solo dopo "Merce arrivata". */

const SCHEDE = [
  ['da_ordinare', 'Da ordinare'], ['inviati', 'Inviati'], ['pronti', 'Pronti per il ritiro'], ['ritirati', 'Ritirati'], ['scaduti', 'Ritiri scaduti']
];
/* Stato dell'ordine come lo vede il fornitore (calcolato dal server). */
const STATO_FORNITORE = { da_confermare: 'Da confermare dal fornitore', in_preparazione: 'In preparazione', spedito: 'Spedito', completato: 'Completato' };
const CHIP_FORNITORE = { da_confermare: 'adm-chip-ambra', in_preparazione: 'adm-chip-blu', spedito: 'adm-chip-viola', completato: 'adm-chip-verde' };

let dati = null;
let scheda = '';
let ricerca = '';

const toast = (titolo, descrizione = '', variant = 'success') => showToast({ title: esc(titolo), description: esc(descrizione), variant });
const quando = (v) => (v ? `${dataGGMMAAAA(v)} alle ${oraHHMM(v)}` : '—');

function leggiScheda() {
  const q = new URLSearchParams((location.hash.split('?')[1]) || '');
  const s = q.get('scheda');
  return SCHEDE.some(([k]) => k === s) ? s : '';
}

/* ----------------------------- Da ordinare ----------------------------- */
function effettoTesto(g) {
  const e = g.effetto;
  if (!e) return '';
  if (e.tipo === 'sotto_minimo') {
    return `Se non pagano entro il termine, il gruppo scende a ${pezzi(e.pezzi_rimasti)}, sotto il minimo di ${e.minimo}: il gruppo verrebbe annullato e rimborsati tutti i clienti.`;
  }
  return `Se non pagano entro il termine, il gruppo resta sopra il minimo (${pezzi(e.pezzi_rimasti)} su ${e.minimo}) e il prezzo degli altri resta quello fissato alla chiusura.`
    + (e.costo_stimato > 0 ? ` Possibile costo a carico di BuyPool: ${euro(e.costo_stimato)}.` : '');
}

function cardDaOrdinare(g) {
  const bloccato = !g.puo_segnare_inviato;
  const avviso = g.pendenti.length ? `<div class="adm-avviso adm-avviso-ambra" role="status">
      <strong>${esc(g.motivo_blocco)}</strong>
      <ul class="adm-lista-semplice">${g.pendenti.map(p => `<li>${esc(p.cliente)} · ${pezzi(p.quantita)} · ${p.stato === 'azione_richiesta' ? 'saldo non riuscito' : 'saldo in attesa'}${p.termine ? ` · termine ${esc(quando(p.termine))}` : ''}</li>`).join('')}</ul>
      <span>${esc(effettoTesto(g))}</span></div>` : '';
  return `<article class="adm-card adm-sezione adm-ordine" data-gruppo="${g.id}">
    <div class="adm-ordine-testa"><div>
      <h2 class="adm-h3"><a class="adm-titolo-link" href="#/admin/campagne/${g.id}">${esc(g.prodotto)}</a></h2>
      <span class="adm-sub">Fornitore: ${esc(g.fornitore)}</span></div>
    </div>
    <dl class="adm-dati">
      <div><dt>Prezzo finale</dt><dd>${esc(euro(g.prezzo_finale))}</dd></div>
      <div><dt>Pezzi da ordinare</dt><dd>${g.pezzi}</dd></div>
      <div><dt>Clienti</dt><dd>${g.clienti} <span class="adm-sub">${g.clienti_ritiro} in sede · ${g.clienti_domicilio} a domicilio</span></dd></div>
      <div><dt>Saldi pagati</dt><dd>${g.saldi_pagati} su ${g.saldi_totali}</dd></div>
    </dl>
    ${g.costo_buypool > 0 ? `<p class="adm-avviso adm-avviso-ambra"><strong>Costo a carico di BuyPool: ${esc(euro(g.costo_buypool))}</strong></p>` : ''}
    ${avviso}
    <div class="adm-azioni-scheda adm-azioni-sinistra">
      <button type="button" class="adm-btn adm-btn-sec" data-azione="csv" data-id="${g.id}">Scarica riepilogo (CSV)</button>
      <button type="button" class="adm-btn adm-btn-pri" data-azione="invia" data-id="${g.id}" ${bloccato ? 'disabled aria-describedby="adm-blocco-' + g.id + '"' : ''}>Segna ordine inviato</button>
    </div>
    ${bloccato ? `<p class="adm-nota" id="adm-blocco-${g.id}">Il pulsante si attiva quando tutti i saldi sono pagati (o i saldi non riusciti sono scaduti): la quantità da ordinare deve essere definitiva.</p>` : ''}
  </article>`;
}

/* ----------------------------- Inviati ----------------------------- */
function cardInviato(g) {
  return `<article class="adm-card adm-sezione adm-ordine" data-gruppo="${g.id}">
    <div class="adm-ordine-testa"><div>
      <h2 class="adm-h3"><a class="adm-titolo-link" href="#/admin/campagne/${g.id}">${esc(g.prodotto)}</a></h2>
      <span class="adm-sub">Fornitore: ${esc(g.fornitore)}</span></div>
      <span class="adm-chip ${CHIP_FORNITORE[g.stato_fornitore] || 'adm-chip-blu'}">${esc(STATO_FORNITORE[g.stato_fornitore] || 'Inviato al fornitore')}</span>
    </div>
    <dl class="adm-dati">
      <div><dt>Inviato il</dt><dd>${esc(dataGGMMAAAA(g.data_ordine) || '—')}</dd></div>
      <div><dt>Pezzi ordinati</dt><dd>${g.pezzi}</dd></div>
      <div><dt>Prezzo finale</dt><dd>${esc(euro(g.prezzo_finale))}</dd></div>
      <div><dt>Clienti</dt><dd>${g.clienti} <span class="adm-sub">${g.clienti_ritiro} in sede · ${g.clienti_domicilio} a domicilio</span></dd></div>
    </dl>
    ${spedizioneAdminHtml(g)}
    ${(g.problemi || []).map(problemaAdminHtml).join('')}
    <div class="adm-azioni-scheda adm-azioni-sinistra">
      <button type="button" class="adm-btn adm-btn-pri" data-azione="arrivata" data-id="${g.id}">Merce arrivata</button>
    </div>
  </article>`;
}

/** Spedizione del fornitore: corriere, tracking e data (se ha gia' spedito). */
function spedizioneAdminHtml(g) {
  if (g.stato_fornitore === 'in_preparazione' && g.confermato_il) return `<p class="adm-nota">Il fornitore ha confermato l'ordine il ${esc(dataGGMMAAAA(g.confermato_il))}.</p>`;
  if (g.stato_fornitore !== 'spedito') return '';
  return `<p class="adm-nota">Spedito${g.data_spedizione ? ` il ${esc(dataGGMMAAAA(g.data_spedizione))}` : ''}${g.corriere ? ` con ${esc(g.corriere)}` : ''}${g.tracking ? ` · tracking <strong>${esc(g.tracking)}</strong>` : ''}. In attesa di arrivo in sede.</p>`;
}

/** Problema segnalato dal fornitore: se non c'e' ancora risposta, pulsante per rispondere. */
function problemaAdminHtml(p) {
  return p.risposta
    ? `<p class="adm-avviso" role="status"><strong>Problema (${esc(p.motivo_etichetta)}):</strong> ${esc(p.testo || 'nessun dettaglio')}<br><strong>La tua risposta:</strong> ${esc(p.risposta)}</p>`
    : `<div class="adm-avviso adm-avviso-ambra" role="status"><strong>Il fornitore segnala un problema (${esc(p.motivo_etichetta)}):</strong> ${esc(p.testo || 'nessun dettaglio')}
        <div><button type="button" class="adm-btn adm-btn-sec adm-btn-piccolo" data-azione="rispondi" data-problema="${p.id}">Rispondi</button></div></div>`;
}

/* ----------------------------- Pronti, spedizioni, ritirati, scaduti ----------------------------- */
function sedeHtml() {
  const s = dati.sede;
  if (!s) return '';
  return `<div class="adm-box-sede"><strong>${esc(s.nome)}</strong> — ${esc(s.indirizzo)}, ${esc(s.citta)}${s.orari ? `<br><span>Orari: ${esc(s.orari)}</span>` : ''}</div>`;
}

const TOKEN_COMPLETO = /^[0-9a-f]{32,64}$/i;

function filtraPronti() {
  const q = ricerca.trim().toLowerCase();
  if (!q || TOKEN_COMPLETO.test(q)) return dati.pronti;
  return dati.pronti.filter(r => r.cliente.toLowerCase().includes(q) || r.codice.toLowerCase().startsWith(q) || r.prodotto.toLowerCase().includes(q));
}

function prontiHtml() {
  const q = ricerca.trim();
  const elenco = filtraPronti();
  const righe = elenco.map(r => `<tr>
      <td data-label="Cliente">${esc(r.cliente)}</td><td data-label="Prodotto">${esc(r.prodotto)}</td><td data-label="Pezzi">${r.quantita}</td>
      <td data-label="Codice"><code class="adm-codice">${esc(r.codice)}</code></td>
      <td data-label="In attesa da">${plurale(r.giorni_attesa, 'giorno', 'giorni')}</td>
      <td class="adm-azioni-cella"><button type="button" class="adm-btn adm-btn-pri adm-btn-piccolo" data-azione="ritirato" data-id="${r.id}">Segna ritirato</button></td></tr>`).join('');
  const spedizioni = dati.spedizioni.length ? `<h2 class="adm-h2 adm-h2-sez">Spedizioni a domicilio</h2>
    <div class="adm-card adm-tabella-box"><div class="adm-tabella-scroll"><table class="adm-tabella">
      <thead><tr><th scope="col">Cliente</th><th scope="col">Prodotto</th><th scope="col">Pezzi</th><th scope="col">Indirizzo</th><th scope="col">Stato</th><th scope="col"><span class="adm-solo-sr">Azione</span></th></tr></thead>
      <tbody>${dati.spedizioni.map(s => `<tr><td data-label="Cliente">${esc(s.cliente)}</td><td data-label="Prodotto">${esc(s.prodotto)}</td><td data-label="Pezzi">${s.quantita}</td>
        <td data-label="Indirizzo">${esc(s.indirizzo || '—')}</td>
        <td data-label="Stato">${s.stato === 'spedita' ? `<span class="adm-chip adm-chip-verde">Spedito</span>${s.tracking ? `<span class="adm-sub">Tracking: ${esc(s.tracking)}</span>` : ''}` : '<span class="adm-chip adm-chip-ambra">Da spedire</span>'}</td>
        <td class="adm-azioni-cella">${s.stato === 'spedita' ? '' : `<button type="button" class="adm-btn adm-btn-pri adm-btn-piccolo" data-azione="spedito" data-id="${s.id}">Segna spedito</button>`}</td></tr>`).join('')}</tbody></table></div></div>` : '';
  return `${sedeHtml()}
    <div class="adm-ricerca"><label for="adm-cerca" class="adm-etichetta">Cerca per nome o codice</label>
      <input id="adm-cerca" type="search" autocomplete="off" placeholder="Nome del cliente, codice breve o codice QR intero" value="${esc(ricerca)}"></div>
    ${TOKEN_COMPLETO.test(q) ? `<div class="adm-avviso"><button type="button" class="adm-btn adm-btn-pri adm-btn-piccolo" data-azione="ritirato-token">Segna ritirato con questo codice QR</button></div>` : ''}
    ${elenco.length ? `<div class="adm-card adm-tabella-box"><div class="adm-tabella-scroll"><table class="adm-tabella">
      <thead><tr><th scope="col">Cliente</th><th scope="col">Prodotto</th><th scope="col">Pezzi</th><th scope="col">Codice</th><th scope="col">In attesa da</th><th scope="col"><span class="adm-solo-sr">Azione</span></th></tr></thead>
      <tbody>${righe}</tbody></table></div></div>`
      : `<div class="adm-card"><p class="adm-vuoto">${dati.pronti.length ? 'Nessun ritiro corrisponde alla ricerca.' : 'Nessun ritiro pronto. I ritiri compaiono quando segni «Merce arrivata».'}</p></div>`}
    ${spedizioni}`;
}

function ritiratiHtml() {
  if (!dati.ritirati.length) return '<div class="adm-card"><p class="adm-vuoto">Ancora nessun ritiro effettuato.</p></div>';
  return `<div class="adm-card adm-tabella-box"><div class="adm-tabella-scroll"><table class="adm-tabella">
    <thead><tr><th scope="col">Cliente</th><th scope="col">Prodotto</th><th scope="col">Pezzi</th><th scope="col">Ritirato il</th></tr></thead>
    <tbody>${dati.ritirati.map(r => `<tr><td data-label="Cliente">${esc(r.cliente)}</td><td data-label="Prodotto">${esc(r.prodotto)}</td><td data-label="Pezzi">${r.quantita}</td><td data-label="Ritirato il">${esc(quando(r.data_ritiro))}</td></tr>`).join('')}</tbody></table></div></div>`;
}

function scadutiHtml() {
  const intro = `<p class="adm-nota">Ordini pronti da più di ${plurale(dati.ritiro_giorni_max, 'giorno', 'giorni')} e non ritirati. Il cliente riceve un promemoria; non parte nessuna azione automatica.</p>`;
  if (!dati.scaduti.length) return `${intro}<div class="adm-card"><p class="adm-vuoto">Nessun ritiro scaduto.</p></div>`;
  return `${intro}<div class="adm-card adm-tabella-box"><div class="adm-tabella-scroll"><table class="adm-tabella">
    <thead><tr><th scope="col">Cliente</th><th scope="col">Prodotto</th><th scope="col">Pezzi</th><th scope="col">Pronto dal</th><th scope="col">In attesa da</th><th scope="col">Promemoria</th></tr></thead>
    <tbody>${dati.scaduti.map(r => `<tr><td data-label="Cliente">${esc(r.cliente)}</td><td data-label="Prodotto">${esc(r.prodotto)}</td><td data-label="Pezzi">${r.quantita}</td>
      <td data-label="Pronto dal">${esc(dataGGMMAAAA(r.dal))}</td><td data-label="In attesa da"><span class="adm-scaduto">${plurale(r.giorni_attesa, 'giorno', 'giorni')}</span></td>
      <td data-label="Promemoria">${r.promemoria_inviato ? 'Inviato' : 'Da inviare'}</td></tr>`).join('')}</tbody></table></div></div>`;
}

function corpoScheda() {
  switch (scheda) {
    case 'da_ordinare': return dati.da_ordinare.length ? `<div class="adm-pila">${dati.da_ordinare.map(cardDaOrdinare).join('')}</div>` : '<div class="adm-card"><p class="adm-vuoto">Nessun gruppo da ordinare.</p></div>';
    case 'inviati': return dati.inviati.length ? `<div class="adm-pila">${dati.inviati.map(cardInviato).join('')}</div>` : '<div class="adm-card"><p class="adm-vuoto">Nessun ordine in attesa della merce.</p></div>';
    case 'pronti': return prontiHtml();
    case 'ritirati': return ritiratiHtml();
    default: return scadutiHtml();
  }
}

function disegna(mantieniRicerca = false) {
  const root = document.getElementById('adm-ordini');
  if (!root) return;
  const pannello = document.getElementById('adm-pannello');
  if (mantieniRicerca && pannello) { pannello.innerHTML = corpoScheda(); return; }
  root.innerHTML = `
    <div class="adm-pills" role="tablist" aria-label="Fasi di ordini e ritiri">
      ${SCHEDE.map(([k, et]) => `<button type="button" role="tab" id="adm-tab-${k}" aria-selected="${k === scheda}" class="adm-pill${k === scheda ? ' attiva' : ''}" data-scheda="${k}">${et} <span class="adm-pill-n">${dati.contatori[k] ?? 0}</span></button>`).join('')}
    </div>
    <div id="adm-pannello" role="tabpanel" aria-labelledby="adm-tab-${scheda}">${corpoScheda()}</div>`;
}

/* ----------------------------- Azioni ----------------------------- */
async function scaricaCsv(id) {
  const { token } = getState();
  const r = await fetch(`${API_URL}/admin/gruppi/${id}/riepilogo-csv`, { headers: token ? { Authorization: `Bearer ${token}` } : {}, cache: 'no-store' });
  if (!r.ok) {
    const j = await r.json().catch(() => null);
    throw new Error(j?.errore?.messaggio || 'Download non riuscito');
  }
  const url = URL.createObjectURL(await r.blob());
  const a = document.createElement('a');
  a.href = url; a.download = `riepilogo-gruppo-${id}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}

function adesso() {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
}

function eseguiAzione(azione, id, aggiorna) {
  const errore = (e) => toast('Operazione non riuscita', e.message, 'error');
  if (azione === 'csv') { scaricaCsv(id).catch(errore); return; }
  if (azione === 'invia') {
    const g = dati.da_ordinare.find(x => x.id === id);
    apriModale({
      titolo: 'Segna ordine inviato',
      introHtml: `<p><strong>${esc(g.prodotto)}</strong>: ${pezzi(g.pezzi)} a ${esc(euro(g.prezzo_finale))}, fornitore ${esc(g.fornitore)}.</p>`,
      corpoHtml: `<p class="adm-nota">Confermi di aver inviato l'ordine al fornitore? I ${g.clienti} clienti riceveranno email e notifica «Ordine inviato al fornitore».</p>`,
      etichettaConferma: 'Segna come inviato',
      onConferma: async () => { await apiPost(`/campagne/${id}/invia-fornitore`, {}); toast('Ordine segnato come inviato', `Avvisati ${plurale(g.clienti, 'cliente', 'clienti')}.`); aggiorna(); }
    });
  } else if (azione === 'arrivata') {
    const g = dati.inviati.find(x => x.id === id);
    apriModale({
      titolo: 'Merce arrivata',
      introHtml: `<p><strong>${esc(g.prodotto)}</strong> (${pezzi(g.pezzi)})</p>`,
      corpoHtml: `<p class="adm-nota">I ${plurale(g.clienti_ritiro, 'cliente', 'clienti')} con ritiro in sede riceveranno email e notifica «Pronto per il ritiro» con sede, orari e codice QR.${g.clienti_domicilio ? ` Le ${plurale(g.clienti_domicilio, 'spedizione', 'spedizioni')} a domicilio restano da spedire dalla scheda «Pronti per il ritiro».` : ''}</p>`,
      etichettaConferma: 'Conferma arrivo',
      onConferma: async () => {
        const r = (await apiPost(`/admin/gruppi/${id}/merce-arrivata`, {})).dati;
        toast('Merce arrivata', `Ritiri pronti: ${r.ritiri}. Spedizioni da fare: ${r.spedizioni}.`);
        scheda = 'pronti';
        history.replaceState(null, '', '#/admin/ordini?scheda=pronti');
        aggiorna();
      }
    });
  } else if (azione === 'ritirato') {
    const r = dati.pronti.find(x => x.id === id);
    apriModale({
      titolo: 'Segna ritirato',
      introHtml: `<p>${esc(r.cliente)} · ${esc(r.prodotto)} · ${pezzi(r.quantita)}</p>`,
      corpoHtml: `<div class="adm-campo"><label for="adm-data-ritiro">Data e ora del ritiro</label><input id="adm-data-ritiro" type="datetime-local" max="${adesso()}" value="${adesso()}"></div>`,
      etichettaConferma: 'Conferma ritiro',
      onConferma: async (box) => {
        const v = box.querySelector('#adm-data-ritiro').value;
        if (!v) throw new Error('Indica data e ora del ritiro.');
        await apiPost(`/admin/ritiri/${id}/ritirato`, { data_ora: v.replace('T', ' ') + ':00' });
        toast('Ritiro registrato', `${r.cliente} è stato avvisato.`);
        aggiorna();
      }
    });
  } else if (azione === 'spedito') {
    const s = dati.spedizioni.find(x => x.id === id);
    apriModale({
      titolo: 'Segna spedito',
      introHtml: `<p>${esc(s.cliente)} · ${esc(s.prodotto)} · ${pezzi(s.quantita)}</p><p class="adm-sub">${esc(s.indirizzo || '')}</p>`,
      corpoHtml: `<div class="adm-campo"><label for="adm-tracking">Codice di tracking (facoltativo)</label><input id="adm-tracking" type="text" maxlength="120" autocomplete="off"></div>
        <p class="adm-nota">Il cliente riceverà email e notifica «Ordine spedito» con il tracking.</p>`,
      etichettaConferma: 'Segna spedito',
      onConferma: async (box) => {
        await apiPost(`/consegne/${id}/spedisci`, { tracking: box.querySelector('#adm-tracking').value.trim() });
        toast('Spedizione registrata', `${s.cliente} è stato avvisato.`);
        aggiorna();
      }
    });
  } else if (azione === 'ritirato-token') {
    apriModale({
      titolo: 'Segna ritirato con il codice QR',
      introHtml: '<p>Il ritiro corrispondente al codice inserito verrà segnato come effettuato adesso.</p>',
      etichettaConferma: 'Conferma ritiro',
      onConferma: async () => {
        const r = (await apiPost(`/ritiro/${encodeURIComponent(ricerca.trim())}`, {})).dati;
        toast('Ritiro registrato', `${r.utente} · ${pezzi(r.quantita)}`);
        ricerca = '';
        aggiorna();
      }
    });
  }
}

async function carica() {
  dati = (await apiGet('/admin/ordini-ritiri')).dati;
  if (!scheda) scheda = SCHEDE.find(([k]) => k !== 'ritirati' && k !== 'scaduti' && dati.contatori[k] > 0)?.[0] || 'da_ordinare';
  disegna();
}

/** Risposta dell'admin a un problema segnalato dal fornitore: il fornitore la trova nella sua pagina Ordini e nel «Da fare». */
function apriRisposta(idProblema, aggiorna) {
  const p = dati.inviati.flatMap(g => (g.problemi || []).map(x => ({ ...x, prodotto: g.prodotto }))).find(x => x.id === idProblema);
  if (!p) return;
  apriModale({
    titolo: 'Rispondi al fornitore',
    introHtml: `<p><strong>${esc(p.prodotto)}</strong> · ${esc(p.motivo_etichetta)}${p.testo ? `: ${esc(p.testo)}` : ''}</p>`,
    corpoHtml: `<div class="adm-campo"><label for="adm-risposta-testo">La tua risposta</label><textarea id="adm-risposta-testo" rows="4" maxlength="1000"></textarea></div>
      <p class="adm-nota">Il fornitore la vedrà nella pagina Ordini e tra le cose da fare. La segnalazione esce dal tuo «Da fare».</p>`,
    etichettaConferma: 'Invia la risposta',
    onConferma: async (box) => {
      const risposta = box.querySelector('#adm-risposta-testo').value.trim();
      if (risposta.length < 3) throw new Error('Scrivi la risposta (almeno 3 caratteri).');
      await apiPost(`/admin/ordini-problemi/${idProblema}/risposta`, { risposta });
      toast('Risposta inviata', 'Il fornitore la troverà tra le cose da fare.');
      aggiorna();
    }
  });
}

export async function AdminOrdiniRitiriPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  scheda = leggiScheda();
  ricerca = '';
  content.innerHTML = `<div class="content-area adm-pagina"><div class="adm-intestazione"><h1 class="adm-h1">Ordini e ritiri</h1></div>
    <div id="adm-ordini"><div class="loading-spinner">Caricamento...</div></div></div>`;
  const root = document.getElementById('adm-ordini');
  const aggiorna = () => carica().catch(e => toast('Pagina non aggiornata', e.message, 'error'));

  root.addEventListener('click', (e) => {
    const pill = e.target.closest('[data-scheda]');
    if (pill) {
      scheda = pill.dataset.scheda;
      history.replaceState(null, '', `#/admin/ordini?scheda=${scheda}`);
      disegna();
      return;
    }
    const b = e.target.closest('[data-azione]');
    if (b && !b.disabled && b.dataset.azione === 'rispondi') { apriRisposta(parseInt(b.dataset.problema), aggiorna); return; }
    if (b && !b.disabled) eseguiAzione(b.dataset.azione, parseInt(b.dataset.id), aggiorna);
  });
  root.addEventListener('input', (e) => {
    if (e.target.id !== 'adm-cerca') return;
    ricerca = e.target.value;
    const pos = e.target.selectionStart;
    disegna(true);
    const campo = document.getElementById('adm-cerca');
    campo?.focus();
    campo?.setSelectionRange(pos, pos);
  });
  root.addEventListener('keydown', (e) => {
    const tab = e.target.closest?.('[role=tab]');
    if (!tab || !['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    e.preventDefault();
    const i = SCHEDE.findIndex(([k]) => k === scheda);
    scheda = SCHEDE[(i + (e.key === 'ArrowRight' ? 1 : -1) + SCHEDE.length) % SCHEDE.length][0];
    history.replaceState(null, '', `#/admin/ordini?scheda=${scheda}`);
    disegna();
    document.getElementById(`adm-tab-${scheda}`)?.focus();
  });

  try {
    await carica();
  } catch (err) {
    root.innerHTML = `<div class="empty-state"><h2>Errore</h2><p class="text-secondary">${esc(err.message)}</p></div>`;
  }
}
