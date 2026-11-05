/* Ordini del fornitore (HTML puro e regole, testati in tests/fornitore_ordini_front_test.mjs).
   Un ordine = un gruppo concluso. Schede: Da confermare · In preparazione · Spediti · Completati. Il fornitore non puo' rifiutare:
   conferma, spedisce alla Sede Centrale o segnala un problema (decide l'admin). Nessun dato dei clienti, nessuna commissione. */
import { esc } from './utils.js';
import { euro, pezzi, dataGGMMAAAA, oraHHMM } from './format.js';

export const SCHEDE = [['da_confermare', 'Da confermare'], ['in_preparazione', 'In preparazione'], ['spediti', 'Spediti'], ['completati', 'Completati']];
const CHIP = { da_confermare: 'adm-chip-ambra', in_preparazione: 'adm-chip-blu', spedito: 'adm-chip-viola', completato: 'adm-chip-verde' };

export function schedaValida(v) { return SCHEDE.some(([k]) => k === v) ? v : 'da_confermare'; }
export function filtraOrdini(lista, scheda) { return lista.filter(o => o.scheda === scheda); }
export function chipHtml(o) { return `<span class="adm-chip ${CHIP[o.stato_fornitore] || 'adm-chip-grigio'}">${esc(o.stato_etichetta)}</span>`; }

const quando = (v) => `${dataGGMMAAAA(v)} alle ${oraHHMM(v)}`;
const giorno = (v) => dataGGMMAAAA(v);

export function consegnaHtml(c) {
  if (!c) return 'Sede Centrale di BuyPool';
  return `${esc(c.nome)} · ${esc(c.indirizzo)}, ${esc(c.citta)}${c.orari ? ` · ${esc(c.orari)}` : ''}`;
}

/** Riga di stato dell'ordine: scadenza di conferma, spedizione o arrivo. */
export function statoRigaHtml(o, ora = new Date()) {
  if (o.stato_fornitore === 'da_confermare') {
    const scad = o.scadenza_conferma ? new Date(String(o.scadenza_conferma).replace(' ', 'T')) : null;
    const scaduta = scad && scad <= ora;
    return `<p class="frn-ord-stato${scaduta ? ' frn-ord-urgente' : ''}">${scad ? (scaduta ? `Il termine per confermare era il ${esc(quando(o.scadenza_conferma))}: conferma l'ordine o segnala un problema.` : `Conferma entro il ${esc(quando(o.scadenza_conferma))}.`) : 'Conferma l\'ordine o segnala un problema.'}</p>`;
  }
  if (o.stato_fornitore === 'in_preparazione') return `<p class="frn-ord-stato">${o.confermato_il ? `Confermato il ${esc(giorno(o.confermato_il))}. ` : ''}Quando lo spedisci, segnalo qui.</p>`;
  if (o.stato_fornitore === 'spedito') {
    return `<p class="frn-ord-stato">Spedito${o.data_spedizione ? ` il ${esc(giorno(o.data_spedizione))}` : ''}${o.corriere ? ` con ${esc(o.corriere)}` : ''}${o.tracking ? ` · tracking <strong>${esc(o.tracking)}</strong>` : ''}.
      <span class="adm-sub">In attesa di arrivo in sede.</span></p>`;
  }
  if (o.stato_fornitore === 'completato') return `<p class="frn-ord-stato">${o.data_arrivo ? `Arrivato in sede il ${esc(giorno(o.data_arrivo))}.` : 'Arrivato in sede.'}</p>`;
  return '';
}

function problemiHtml(o) {
  let h = '';
  if (o.problema_aperto) {
    h += `<p class="adm-avviso adm-avviso-ambra" role="status"><strong>Problema segnalato (${esc(o.problema_aperto.motivo_etichetta)}):</strong> ${esc(o.problema_aperto.testo || 'nessun dettaglio')}<br>In attesa di risposta di BuyPool.</p>`;
  }
  if (o.risposta_admin) {
    h += `<p class="adm-avviso" role="status"><strong>Risposta di BuyPool</strong> (${esc(giorno(o.risposta_admin.risposto_il))}): ${esc(o.risposta_admin.risposta)}</p>`;
  }
  return h;
}

function azioniHtml(o) {
  const id = esc(o.id);
  const b = [];
  if (o.azioni.includes('conferma')) b.push(`<button type="button" class="adm-btn adm-btn-pri" data-azione="conferma" data-id="${id}">Conferma ordine</button>`);
  if (o.azioni.includes('spedisci')) b.push(`<button type="button" class="adm-btn adm-btn-pri" data-azione="spedisci" data-id="${id}">Segna come spedito</button>`);
  if (o.azioni.includes('problema') && !o.problema_aperto) b.push(`<button type="button" class="adm-btn adm-btn-sec" data-azione="problema" data-id="${id}">Segnala un problema</button>`);
  return b.length ? `<div class="adm-azioni-scheda adm-azioni-sinistra">${b.join('')}</div>` : '';
}

export function ordineCardHtml(o, consegna, ora) {
  return `<article class="adm-card adm-sezione frn-ordine" id="ord-${esc(o.id)}" data-ordine="${esc(o.id)}">
    <div class="adm-ordine-testa"><div><h2 class="adm-h3"><a class="frn-titolo-link" href="#/fornitore/gruppi/${esc(o.id_colletta)}">${esc(o.prodotto)}</a></h2>
      <span class="adm-sub">Ordine n. ${esc(o.numero)}${o.data_ordine ? ` · arrivato il ${esc(giorno(o.data_ordine))}` : ''}</span></div>${chipHtml(o)}</div>
    <dl class="adm-dati">
      <div><dt>Pezzi</dt><dd>${esc(pezzi(o.quantita_ordinata))}</dd></div>
      <div><dt>Tuo prezzo unitario</dt><dd>${esc(euro(o.prezzo_unitario))}</dd></div>
      <div><dt>Importo per te</dt><dd>${esc(euro(o.importo))}</dd></div>
      <div><dt>Consegna alla Sede Centrale</dt><dd>${consegnaHtml(consegna)}</dd></div>
    </dl>
    ${statoRigaHtml(o, ora)}
    ${problemiHtml(o)}
    ${azioniHtml(o)}
  </article>`;
}

/** Pagina elenco. `dati` = risposta di GET /fornitore/ordini. */
export function ordiniElencoHtml(dati, scheda, ora) {
  const sc = schedaValida(scheda);
  const lista = filtraOrdini(dati.ordini || [], sc);
  const pill = ([k, et]) => `<button type="button" class="adm-pill${k === sc ? ' attiva' : ''}" data-scheda="${k}" aria-pressed="${k === sc}">${et} <span class="adm-pill-n">${Number(dati.contatori?.[k]) || 0}</span></button>`;
  const vuoto = { da_confermare: 'Nessun ordine da confermare.', in_preparazione: 'Nessun ordine in preparazione.', spediti: 'Nessun ordine spedito in attesa di arrivo.', completati: 'Nessun ordine completato.' }[sc];
  return `<div class="content-area adm-pagina">
    <div><h1 class="adm-h1">Ordini</h1><p class="adm-frase">Gli ordini dei gruppi conclusi: li confermi, li spedisci alla Sede Centrale di BuyPool e l'arrivo lo segna BuyPool.</p></div>
    <div class="adm-pills" role="group" aria-label="Filtra gli ordini">${SCHEDE.map(pill).join('')}</div>
    ${lista.length ? `<div class="adm-pila">${lista.map(o => ordineCardHtml(o, dati.consegna, ora)).join('')}</div>` : `<div class="adm-card"><p class="adm-vuoto">${vuoto}</p></div>`}
  </div>`;
}

/* ------------------------------ finestre ------------------------------ */

export function problemaFormHtml(motivi) {
  return `<fieldset class="adm-motivi"><legend class="adm-etichetta">Che cosa succede?</legend>
      ${Object.entries(motivi).map(([k, et]) => `<label class="adm-spunta"><input type="radio" name="motivo" value="${esc(k)}"> ${esc(et)}</label>`).join('')}</fieldset>
    <div class="adm-campo"><label for="frn-prob-testo" id="frn-prob-et">Dettagli (facoltativo)</label><textarea id="frn-prob-testo" rows="3" maxlength="1000"></textarea></div>
    <p class="adm-nota">L'ordine resta da confermare. BuyPool riceve la segnalazione e decide come procedere.</p>`;
}

export function validaProblema(motivo, testo, motivi) {
  const t = String(testo || '').trim().replace(/\s+/g, ' ');
  if (!motivo || !motivi[motivo]) return 'Scegli il motivo dall\'elenco.';
  if (motivo === 'altro' && t.length < 3) return 'Con «Altro» descrivi il problema (almeno 3 caratteri).';
  if (t.length > 1000) return 'Il testo può avere al massimo 1000 caratteri.';
  return '';
}

export function spedizioneFormHtml(corrieri, oggi) {
  return `<div class="adm-campo"><label for="frn-corriere">Corriere</label>
      <select id="frn-corriere"><option value="">Scegli il corriere</option>${corrieri.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('')}</select></div>
    <div class="adm-campo" id="frn-altro-box" hidden><label for="frn-corriere-altro">Nome del corriere</label><input id="frn-corriere-altro" type="text" maxlength="40"></div>
    <div class="adm-campo"><label for="frn-tracking">Numero di tracking</label><input id="frn-tracking" type="text" maxlength="40" autocomplete="off"></div>
    <div class="adm-campo"><label for="frn-data-sped">Data di spedizione</label><input id="frn-data-sped" type="date" max="${esc(oggi)}" value="${esc(oggi)}"></div>
    <p class="adm-nota">La merce va consegnata alla Sede Centrale di BuyPool. BuyPool segna l'arrivo e l'ordine diventa «Completato».</p>`;
}

/** Errore (testo) oppure '' se la spedizione e' valida. Ripete le regole del server. */
export function validaSpedizione(v, corrieri, oggi, dataOrdine = null) {
  if (!corrieri.includes(v.corriere)) return 'Scegli il corriere.';
  if (v.corriere === 'Altro' && String(v.corriere_altro || '').trim().length < 2) return 'Scrivi il nome del corriere.';
  if (!/^[A-Za-z0-9][A-Za-z0-9 .\/-]{3,38}[A-Za-z0-9]$/.test(String(v.tracking || '').trim())) return 'Inserisci il numero di tracking (da 5 a 40 tra lettere, numeri e trattini).';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(v.data_spedizione || '')) return 'Indica la data di spedizione.';
  if (v.data_spedizione > oggi) return 'La data di spedizione non può essere futura.';
  if (dataOrdine && v.data_spedizione < String(dataOrdine).slice(0, 10)) return 'La data di spedizione non può essere precedente all\'ordine.';
  return '';
}
