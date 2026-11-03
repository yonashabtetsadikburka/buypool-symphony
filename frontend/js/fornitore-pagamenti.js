/* Pagamenti del fornitore, versione 1 (HTML puro, testato in tests/fornitore_azienda_front_test.mjs).
   Solo importo e stato (Da ricevere / Ricevuto) dei SUOI ordini: lo stato lo imposta l'admin dopo il bonifico. Nessun dato dei clienti,
   nessuna commissione. Importi in formato italiano, date GG/MM/AAAA. */
import { esc } from './utils.js';
import { euro, dataGGMMAAAA } from './format.js';

const CHIP = { da_pagare: 'adm-chip-ambra', pagato: 'adm-chip-verde' };

export function numeriHtml(n) {
  const k = (et, v) => `<div class="adm-indicatore"><span class="adm-ind-label">${esc(et)}</span><strong class="adm-ind-valore">${esc(euro(v))}</strong></div>`;
  return `<div class="adm-indicatori frn-tre">${k('Da ricevere', n.da_pagare)}${k('Ricevuto questo mese', n.pagato_mese)}${k('Ricevuto in totale', n.pagato_totale)}</div>`;
}

export function notaBonificoHtml(d) {
  return d.iban_presente
    ? `<p class="adm-avviso" role="status">Ti paghiamo con bonifico sull'IBAN della tua azienda (<strong>${esc(d.iban_maschera)}</strong>). Lo stato diventa «Ricevuto» quando BuyPool ha fatto il bonifico. <a class="frn-link" href="#/fornitore/azienda">Controlla l'IBAN</a></p>`
    : '<p class="adm-avviso adm-avviso-ambra" role="status"><strong>Manca l\'IBAN.</strong> Ti paghiamo con bonifico: <a class="frn-link" href="#/fornitore/azienda">aggiungilo nella pagina Azienda</a>.</p>';
}

export function tabellaHtml(righe) {
  if (!righe.length) return '<p class="adm-vuoto">Non ci sono ancora ordini da pagare.</p>';
  return `<div class="adm-tabella-scroll"><table class="adm-tabella">
    <thead><tr><th scope="col">Ordine</th><th scope="col">Prodotto</th><th scope="col" class="adm-num">Importo</th><th scope="col">Stato</th><th scope="col">Data</th></tr></thead>
    <tbody>${righe.map(r => `<tr><td data-label="Ordine">${esc(r.numero)}</td><td data-label="Prodotto">${esc(r.prodotto)}</td><td data-label="Importo" class="adm-num">${esc(euro(r.importo))}</td>
      <td data-label="Stato"><span class="adm-chip ${CHIP[r.stato] || 'adm-chip-grigio'}">${esc(r.stato_etichetta)}</span></td>
      <td data-label="Data">${r.stato === 'pagato' && r.data ? esc(dataGGMMAAAA(r.data)) : 'Dopo la consegna'}</td></tr>`).join('')}</tbody></table></div>`;
}

/** Pagina completa. `d` = risposta di GET /fornitore/pagamenti. */
export function pagamentiHtml(d) {
  return `<div class="content-area adm-pagina">
    <div><h1 class="adm-h1">Pagamenti</h1><p class="adm-frase">Quanto ti dobbiamo per i tuoi ordini.</p></div>
    ${numeriHtml(d.numeri)}
    ${notaBonificoHtml(d)}
    <section class="adm-card adm-sezione" aria-labelledby="frn-pag-t"><h2 class="adm-h2" id="frn-pag-t">Ordini</h2>${tabellaHtml(d.righe || [])}</section>
  </div>`;
}
