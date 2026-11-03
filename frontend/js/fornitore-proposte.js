/* Proposte del fornitore: elenco a schede e dettaglio (HTML puro, testato in tests/fornitore_proposte_front_test.mjs).
   Gli stati, le azioni consentite (modificabile, richiesta dell'admin, motivo di rifiuto) arrivano gia' calcolati dal server.
   Nessun dato dei clienti: delle proposte suggerite da un cliente si mostrano solo nome e stato. Importi in formato italiano. */
import { esc } from './utils.js';
import { euro, plurale, dataGGMMAAAA } from './format.js';

export const SCHEDE = [['tutte', 'Tutte'], ['in_revisione', 'In revisione'], ['in_votazione', 'In votazione'], ['in_valutazione', 'In valutazione'],
  ['gruppo_creato', 'Gruppo creato'], ['non_accolte', 'Non accolte']];
const CHIP = { in_revisione: 'adm-chip-ambra', in_votazione: 'adm-chip-blu', in_valutazione: 'adm-chip-viola', gruppo_creato: 'adm-chip-verde', non_accolta: 'adm-chip-rosso' };

export function schedaValida(v) { return SCHEDE.some(([k]) => k === v) ? v : 'tutte'; }
export function filtraProposte(lista, scheda) { return scheda === 'tutte' ? lista : lista.filter(p => p.scheda === scheda); }
export function chipHtml(p) { return `<span class="adm-chip ${CHIP[p.stato_fornitore] || 'adm-chip-grigio'}">${esc(p.stato_etichetta)}</span>`; }

/** Miniatura 72px (o segnaposto con l'iniziale). `urlFoto` trasforma il percorso relativo dell'API in URL. */
function miniaturaHtml(p, urlFoto) {
  return p.foto ? `<img class="frn-thumb" src="${esc(urlFoto(p.foto))}" alt="" width="72" height="72">`
    : `<span class="frn-thumb frn-thumb-vuota" aria-hidden="true">${esc((p.nome_prodotto || '?').trim().charAt(0).toUpperCase())}</span>`;
}

/** Riga di dettaglio: voti (in votazione), quantita' minima e prezzo da. */
export function dettaglioRiga(p) {
  const parti = [];
  if (p.stato_fornitore === 'in_votazione') parti.push(`${plurale(p.voti_favore, 'voto', 'voti')} su ${p.soglia_voti}`);
  if (p.moq_richiesto) parti.push(`minimo ${plurale(p.moq_richiesto, 'unità', 'unità')}`);
  if (p.prezzo_da != null) parti.push(`da ${euro(p.prezzo_da)}`);
  return parti.join(' · ');
}

function azioniHtml(p) {
  const id = Number(p.id_proposta);
  const b = (href, txt, sec = true) => `<a class="adm-btn ${sec ? 'adm-btn-sec' : 'adm-btn-pri'} adm-btn-piccolo" href="${esc(href)}">${esc(txt)}</a>`;
  if (p.modificabile) return b(`#/fornitore/proposte/${id}/modifica`, 'Modifica proposta', false);
  if (p.stato_fornitore === 'gruppo_creato' && p.campagna_id) return b(`#/fornitore/gruppi/${Number(p.campagna_id)}`, 'Vedi gruppo');
  if (p.stato_fornitore === 'non_accolta') return b(`#/fornitore/proposte/nuova?riproponi=${id}`, 'Proponi di nuovo');
  return b(`#/fornitore/proposte/${id}`, 'Vedi');
}

function riquadriHtml(p) {
  let h = '';
  if (p.stato_fornitore === 'non_accolta' && p.motivo) h += `<p class="adm-avviso adm-avviso-rosso" role="status"><strong>Motivo:</strong> ${esc(p.motivo)}</p>`;
  if (p.richiesta_admin) h += `<p class="adm-avviso adm-avviso-ambra" role="status"><strong>BuyPool ti chiede una modifica:</strong> ${esc(p.richiesta_admin.testo)}</p>`;
  return h;
}

export function rigaProposta(p, urlFoto = (x) => x) {
  if (p.suggerita_da_cliente) {
    return `<li class="frn-prop frn-prop-suggerita"><span class="frn-thumb frn-thumb-vuota" aria-hidden="true">${esc((p.nome_prodotto || '?').charAt(0).toUpperCase())}</span>
      <div class="frn-prop-corpo"><div class="frn-prop-testa"><strong class="frn-prop-titolo">${esc(p.nome_prodotto)}</strong>${chipHtml(p)}</div>
      <p class="frn-prop-sub">Suggerita da un cliente</p></div></li>`;
  }
  return `<li class="frn-prop">${miniaturaHtml(p, urlFoto)}
    <div class="frn-prop-corpo">
      <div class="frn-prop-testa"><a class="frn-titolo-link frn-prop-titolo" href="#/fornitore/proposte/${Number(p.id_proposta)}">${esc(p.nome_prodotto)}</a>${chipHtml(p)}</div>
      <p class="frn-prop-sub">${esc(dettaglioRiga(p))}</p>
      ${riquadriHtml(p)}
    </div>
    <div class="frn-prop-azioni">${azioniHtml(p)}</div></li>`;
}

/** Pagina elenco. `dati` = risposta di GET /fornitore/proposte ({contatori, proposte}). */
export function proposteElencoHtml(dati, scheda, urlFoto) {
  const sc = schedaValida(scheda);
  const lista = filtraProposte(dati.proposte || [], sc);
  const pill = ([k, et]) => `<button type="button" class="adm-pill${k === sc ? ' attiva' : ''}" data-scheda="${k}" aria-pressed="${k === sc}">${et} <span class="adm-pill-n">${Number(dati.contatori?.[k]) || 0}</span></button>`;
  return `<div class="content-area adm-pagina">
    <div class="adm-intestazione"><div><h1 class="adm-h1">Proposte</h1><p class="adm-frase">I prodotti che proponi a BuyPool: dalla revisione al gruppo.</p></div>
      <a class="adm-btn adm-btn-pri" href="#/fornitore/proposte/nuova">Nuova proposta</a></div>
    <div class="adm-pills" role="group" aria-label="Filtra le proposte">${SCHEDE.map(pill).join('')}</div>
    ${lista.length ? `<ul class="frn-prop-lista">${lista.map(p => rigaProposta(p, urlFoto)).join('')}</ul>`
      : `<div class="adm-card"><p class="adm-vuoto">${(dati.proposte || []).length ? 'Nessuna proposta in questa scheda.' : 'Non hai ancora inviato proposte. Con "Nuova proposta" ne puoi inviare una.'}</p></div>`}
  </div>`;
}

export function scaglioniTabellaHtml(scaglioni) {
  if (!scaglioni.length) return '<p class="adm-vuoto">Nessuno scaglione.</p>';
  return `<div class="adm-tabella-scroll"><table class="adm-tabella adm-tabella-compatta">
    <thead><tr><th scope="col">Scaglione</th><th scope="col">Da (pezzi)</th><th scope="col" class="adm-num">Tuo prezzo</th><th scope="col" class="adm-num">Sconto sul listino</th></tr></thead>
    <tbody>${scaglioni.map((s, i) => `<tr><td>${i + 1}</td><td>${Number(s.soglia)}</td><td class="adm-num">${esc(euro(s.prezzo))}</td><td class="adm-num">${Number(s.sconto) || 0}%</td></tr>`).join('')}</tbody></table></div>`;
}

/** Form "Chiedi una modifica" (solo proposte non modificabili e ancora vive, senza una richiesta gia' inviata). */
export function richiestaFormHtml(p) {
  if (p.richiesta_inviata) return '<p class="adm-avviso" role="status">Hai già chiesto una modifica: BuyPool la sta valutando.</p>';
  if (p.modificabile || !['in_votazione', 'in_valutazione'].includes(p.stato_fornitore)) return '';
  return `<form class="frn-richiesta" id="frn-richiesta" novalidate>
    <h2 class="adm-h2">Chiedi una modifica</h2>
    <p class="adm-nota">La proposta è già stata esaminata e non si modifica direttamente. Scrivi a BuyPool che cosa vuoi cambiare.</p>
    <div class="adm-campo"><label for="frn-richiesta-testo">Cosa vuoi cambiare?</label><textarea id="frn-richiesta-testo" rows="4" maxlength="1000"></textarea>
      <span class="adm-contatore" id="frn-richiesta-n">0 / 1000</span><span class="frn-errore" id="frn-richiesta-err" role="alert"></span></div>
    <div><button type="submit" class="adm-btn adm-btn-pri" id="frn-richiesta-invia">Invia la richiesta</button></div></form>`;
}

/** Pagina "Vedi" (sola lettura). */
export function propostaDettaglioHtml(p, urlFoto = (x) => x) {
  return `<div class="content-area adm-pagina">
    <div class="frn-testa"><a class="adm-indietro" href="#/fornitore/proposte">← Tutte le proposte</a>
      <h1 class="adm-h1">${esc(p.nome_prodotto)}</h1>
      <div class="frn-meta">${chipHtml(p)}<span>Inviata il ${esc(dataGGMMAAAA(p.data_proposta))}</span>${p.categoria ? `<span>${esc(p.categoria)}</span>` : ''}</div>
      ${p.stato_fornitore === 'in_votazione' ? `<p class="frn-gruppo-pezzi">${esc(plurale(p.voti_favore, 'voto', 'voti'))} su ${Number(p.soglia_voti)} per passare in valutazione</p>` : ''}
    </div>
    ${riquadriHtml(p)}
    <section class="adm-card adm-sezione frn-prop-scheda">
      ${p.foto ? `<img class="frn-foto-grande" src="${esc(urlFoto(p.foto))}" alt="">` : ''}
      <div><h2 class="adm-h2">Prodotto</h2><p class="frn-descrizione">${esc(p.descrizione || 'Nessuna descrizione.')}</p>
      <p class="frn-consegna"><strong>Prezzo di listino:</strong> ${p.listino != null ? esc(euro(p.listino)) : '—'} · <strong>Quantità minima per attivare il gruppo:</strong> ${esc(plurale(p.moq_richiesto || 0, 'unità', 'unità'))}</p></div>
    </section>
    <section class="adm-card adm-sezione"><h2 class="adm-h2">Prezzi e scaglioni</h2>${scaglioniTabellaHtml(p.scaglioni || [])}</section>
    ${(p.modificabile || p.stato_fornitore === 'gruppo_creato') ? `<div class="frn-azioni-fondo">${azioniHtml(p)}</div>` : ''}
    ${richiestaFormHtml(p) ? `<section class="adm-card adm-sezione">${richiestaFormHtml(p)}</section>` : ''}
  </div>`;
}
