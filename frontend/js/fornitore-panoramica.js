/* Panoramica del fornitore (HTML puro, testato in tests/fornitore_panoramica_front_test.mjs).
   I numeri, le righe del "Da fare" e la frase arrivano gia' calcolati dal server (GET /fornitore/panoramica): qui si disegna e basta.
   Nessun dato dei clienti: l'API non li fornisce. Importi in formato italiano, date GG/MM/AAAA, "gruppo" mai "campagna". */
import { esc } from './utils.js';
import { euro, plurale, pezzi, dataGGMMAAAA, oraHHMM } from './format.js';

const CHIP_GRUPPO = { aperto: 'adm-chip-blu', attivo: 'adm-chip-verde', concluso: 'adm-chip-teal', annullato: 'adm-chip-grigio' };

export function voceHtml(v) {
  return `<li class="adm-dafare-riga">
    <span class="adm-dafare-tipo">${esc(v.tipo)}</span>
    <span class="adm-dafare-testo"><strong>${esc(v.titolo)}</strong><span class="adm-sub${v.urgente ? ' adm-scaduto' : ''}">${esc(v.dettaglio)}</span></span>
    <a class="adm-btn adm-btn-pri adm-btn-piccolo" href="${esc(v.link)}">${esc(v.pulsante)}</a>
  </li>`;
}

export function daFareHtml(voci) {
  if (!voci.length) {
    return '<section class="adm-card adm-dafare adm-dafare-ok" aria-labelledby="frn-dafare-t"><h2 class="adm-h2" id="frn-dafare-t">Da fare</h2><p class="adm-vuoto">Non c\'è nulla da fare.</p></section>';
  }
  return `<section class="adm-card adm-dafare" aria-labelledby="frn-dafare-t"><h2 class="adm-h2" id="frn-dafare-t">Da fare</h2>
    <ul class="adm-dafare-lista">${voci.map(voceHtml).join('')}</ul></section>`;
}

export function numeriHtml(n) {
  const k = (et, val, sub = '') => `<div class="adm-indicatore"><span class="adm-ind-label">${esc(et)}</span><strong class="adm-ind-valore">${val}</strong>${sub ? `<span class="adm-sub">${esc(sub)}</span>` : ''}</div>`;
  return `<div class="adm-indicatori">
    ${k('Gruppi aperti', n.gruppi_aperti, n.con_minimo === 1 ? '1 ha già il minimo' : `${n.con_minimo} hanno già il minimo`)}
    ${k('Ordini da evadere', n.ordini_da_evadere, n.da_confermare === 1 ? '1 da confermare' : `${n.da_confermare} da confermare`)}
    ${k('Importo in arrivo', esc(euro(n.importo_in_arrivo)), 'Da ricevere dopo la consegna')}
    ${k('Proposte in corso', n.proposte_in_corso, n.in_votazione === 1 ? '1 in votazione' : `${n.in_votazione} in votazione`)}
  </div>`;
}

export function gruppoCardHtml(g) {
  const pct = Math.min(100, Math.max(0, parseInt(g.percentuale_adesione) || 0));
  return `<article class="frn-gruppo">
    <div class="frn-gruppo-testa"><h3 class="frn-gruppo-titolo">${esc(g.prodotto)}</h3><span class="adm-chip ${CHIP_GRUPPO[g.stato_fornitore] || 'adm-chip-grigio'}">${esc(g.stato_etichetta)}</span></div>
    <p class="frn-gruppo-pezzi">${esc(pezzi(g.quantita_attuale))} su ${esc(g.quantita_minima)} per il minimo</p>
    <span class="adm-barra frn-barra" role="img" aria-label="${pct}% del minimo"><span style="width:${pct}%"></span></span>
    <p class="frn-gruppo-scadenza">Scade il ${esc(dataGGMMAAAA(g.data_limite))} alle ${esc(oraHHMM(g.data_limite))}</p>
  </article>`;
}

export function gruppiAttiviHtml(gruppi) {
  return `<section class="adm-card adm-sezione" aria-labelledby="frn-gruppi-t">
    <div class="frn-sezione-testa"><h2 class="adm-h2" id="frn-gruppi-t">Gruppi attivi</h2><a class="frn-link" href="#/fornitore/gruppi">Tutti i gruppi &rarr;</a></div>
    ${gruppi.length ? `<div class="frn-gruppi">${gruppi.map(gruppoCardHtml).join('')}</div>` : '<p class="adm-vuoto">Nessun gruppo attivo al momento.</p>'}
  </section>`;
}

/** Pagina completa. `d` = risposta di GET /fornitore/panoramica. */
export function panoramicaHtml(d) {
  return `<div class="content-area adm-pagina">
    <div><h1 class="adm-h1">Panoramica</h1><p class="adm-frase" id="frn-frase" role="status">${esc(d.frase)}</p></div>
    ${daFareHtml(d.voci || [])}
    ${numeriHtml(d.numeri)}
    ${gruppiAttiviHtml(d.gruppi_attivi || [])}
  </div>`;
}
