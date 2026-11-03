/* Gruppi del fornitore, SOLA LETTURA (HTML puro, testato in tests/fornitore_gruppi_front_test.mjs).
   Elenco: schede Tutti · Aperti · Conclusi · Annullati con contatori, a griglia, un solo chip di stato per gruppo.
   Dettaglio: scaglioni con il SOLO prezzo del fornitore, riquadro "Da ordinare", consegna alla Sede Centrale.
   Nessun dato dei clienti (nemmeno quanti sono): l'API non li fornisce. Nessuna azione di modifica. */
import { esc } from './utils.js';
import { euro, pezzi, dataGGMMAAAA, oraHHMM } from './format.js';

export const SCHEDE = [['tutti', 'Tutti'], ['aperti', 'Aperti'], ['conclusi', 'Conclusi'], ['annullati', 'Annullati']];
const CHIP = { aperto: 'adm-chip-blu', attivo: 'adm-chip-verde', concluso: 'adm-chip-teal', annullato: 'adm-chip-grigio' };
const SCHEDA_DI = { aperto: 'aperti', attivo: 'aperti', concluso: 'conclusi', annullato: 'annullati' };

export function schedaValida(v) { return SCHEDE.some(([k]) => k === v) ? v : 'tutti'; }

export function filtraGruppi(gruppi, scheda) {
  return scheda === 'tutti' ? gruppi : gruppi.filter(g => SCHEDA_DI[g.stato_fornitore] === scheda);
}

export function chipHtml(g) {
  return `<span class="adm-chip ${CHIP[g.stato_fornitore] || 'adm-chip-grigio'}">${esc(g.stato_etichetta)}</span>`;
}

/** "Scade il …" per i gruppi in corso, "Concluso il …" / "Annullato il …" per quelli finiti. */
export function quandoHtml(g) {
  const ora = `${dataGGMMAAAA(g.data_fine || g.data_limite)}`;
  if (g.stato_fornitore === 'concluso') return `Concluso il ${ora}`;
  if (g.stato_fornitore === 'annullato') return `Annullato il ${ora}`;
  return `Scade il ${dataGGMMAAAA(g.data_limite)} alle ${oraHHMM(g.data_limite)}`;
}

function barraHtml(g) {
  const pct = Math.min(100, Math.max(0, parseInt(g.percentuale_adesione) || 0));
  return `<span class="adm-barra frn-barra" role="img" aria-label="${pct}% del minimo"><span style="width:${pct}%"></span></span>`;
}

export function schedaGruppoHtml(g) {
  return `<article class="frn-gruppo">
    <div class="frn-gruppo-testa"><h2 class="frn-gruppo-titolo"><a class="frn-titolo-link" href="#/fornitore/gruppi/${Number(g.id)}">${esc(g.prodotto)}</a></h2>${chipHtml(g)}</div>
    <p class="frn-gruppo-pezzi">${esc(pezzi(g.quantita_attuale))} su ${esc(g.quantita_minima)} per il minimo</p>
    ${barraHtml(g)}
    <p class="frn-gruppo-scadenza">${esc(quandoHtml(g))}</p>
  </article>`;
}

/** Pagina elenco. `dati` = risposta di GET /fornitore/gruppi ({contatori, gruppi}). */
export function gruppiElencoHtml(dati, scheda) {
  const sc = schedaValida(scheda);
  const lista = filtraGruppi(dati.gruppi || [], sc);
  const pill = ([k, et]) => `<button type="button" class="adm-pill${k === sc ? ' attiva' : ''}" data-scheda="${k}" aria-pressed="${k === sc}">${et} <span class="adm-pill-n">${Number(dati.contatori?.[k]) || 0}</span></button>`;
  return `<div class="content-area adm-pagina">
    <div><h1 class="adm-h1">Gruppi</h1><p class="adm-frase">I gruppi aperti da BuyPool sui tuoi prodotti, in sola lettura.</p></div>
    <div class="adm-pills" role="group" aria-label="Filtra i gruppi">${SCHEDE.map(pill).join('')}</div>
    ${lista.length ? `<div class="frn-gruppi frn-gruppi-elenco">${lista.map(schedaGruppoHtml).join('')}</div>`
      : `<div class="adm-card"><p class="adm-vuoto">${(dati.gruppi || []).length ? 'Nessun gruppo in questa scheda.' : 'Non c\'è ancora nessun gruppo sui tuoi prodotti.'}</p></div>`}
  </div>`;
}

const STATO_SCAGLIONE = {
  superato: ['Superato', 'adm-chip-verde'],
  raggiunto: ['Raggiunto', 'adm-chip-blu'],
  non_raggiunto: ['Non raggiunto', 'adm-chip-grigio'],
};

export function scaglioniHtml(scaglioni) {
  if (!scaglioni.length) return '<p class="adm-vuoto">Questo gruppo non ha scaglioni di prezzo.</p>';
  const riga = (s) => {
    const [et, cls] = s.finale ? ['Raggiunto · prezzo finale', 'adm-chip-verde'] : (STATO_SCAGLIONE[s.stato] || STATO_SCAGLIONE.non_raggiunto);
    return `<tr><td data-label="Scaglione">${Number(s.numero)}</td><td data-label="Da (pezzi)">${Number(s.soglia)}</td>
      <td data-label="Tuo prezzo" class="adm-num">${esc(euro(s.prezzo))}</td><td data-label="Stato"><span class="adm-chip ${cls}">${esc(et)}</span></td></tr>`;
  };
  return `<div class="adm-tabella-scroll"><table class="adm-tabella adm-tabella-compatta">
    <thead><tr><th scope="col">Scaglione</th><th scope="col">Da (pezzi)</th><th scope="col" class="adm-num">Tuo prezzo</th><th scope="col">Stato</th></tr></thead>
    <tbody>${scaglioni.map(riga).join('')}</tbody></table></div>`;
}

export function daOrdinareHtml(d) {
  if (!d) return '';
  const nota = d.provvisoria
    ? `<p class="adm-avviso adm-avviso-ambra" role="status"><strong>Quantità provvisoria</strong><br>Alcuni pagamenti non sono ancora completati: il numero di pezzi può ancora cambiare. Diventa definitivo quando tutti i pagamenti sono completati o scaduti.</p>`
    : '';
  const voce = (et, val) => `<div class="adm-indicatore"><span class="adm-ind-label">${esc(et)}</span><strong class="adm-ind-valore">${val}</strong></div>`;
  return `<section class="adm-card adm-sezione" aria-labelledby="frn-ordinare-t"><h2 class="adm-h2" id="frn-ordinare-t">Da ordinare</h2>
    ${nota}
    <div class="adm-indicatori frn-tre">
      ${voce('Pezzi da fornire', esc(pezzi(d.pezzi)))}
      ${voce('Prezzo finale', d.prezzo_finale != null ? esc(euro(d.prezzo_finale)) : '—')}
      ${voce('Importo per te', esc(euro(d.importo)))}
    </div></section>`;
}

export function consegnaHtml(c) {
  if (!c) return 'Sede Centrale di BuyPool';
  return `${esc(c.nome)} · ${esc(c.indirizzo)}, ${esc(c.citta)}${c.orari ? ` · ${esc(c.orari)}` : ''}`;
}

/** Pagina dettaglio. `g` = risposta di GET /fornitore/gruppi/{id}. */
export function gruppoDettaglioHtml(g) {
  return `<div class="content-area adm-pagina">
    <div class="frn-testa">
      <a class="adm-indietro" href="#/fornitore/gruppi">← Tutti i gruppi</a>
      <h1 class="adm-h1">${esc(g.prodotto)}</h1>
      <div class="frn-meta">${chipHtml(g)}<span>${esc(quandoHtml(g))}</span></div>
      <p class="frn-consegna"><strong>Consegna alla Sede Centrale:</strong> ${consegnaHtml(g.consegna)}</p>
      <p class="frn-gruppo-pezzi">${esc(pezzi(g.quantita_attuale))} su ${esc(g.quantita_minima)} per il minimo</p>
      ${barraHtml(g)}
    </div>
    ${daOrdinareHtml(g.da_ordinare)}
    <section class="adm-card adm-sezione" aria-labelledby="frn-scaglioni-t"><h2 class="adm-h2" id="frn-scaglioni-t">Scaglioni</h2>${scaglioniHtml(g.scaglioni || [])}</section>
  </div>`;
}
