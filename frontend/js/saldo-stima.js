/* Stima di acconto e saldo mostrata nella finestra di adesione (funzioni pure, testate in tests/saldo_stima_test.mjs).
   Solo visualizzazione: l'addebito reale e' sempre calcolato dal server (api/lib/prezzi.php, saldo_dettaglio). */

const r2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;

/** Acconto = percentuale del prezzo del PRIMO scaglione x quantita', arrotondato sul totale. */
export function accontoStima(quantita, primoPrezzo, percentuale) {
  return r2(Math.max(1, quantita) * primoPrezzo * percentuale / 100);
}

/** Stessa formula del server: merce + commissione + spedizione (solo se scelta) - acconto. */
export function saldoStima({ quantita, prezzo, commissionePct, spedizione = 0, acconto = 0 }) {
  const merce = r2(Math.max(1, quantita) * prezzo);
  const commissione = r2(merce * commissionePct / 100);
  const sped = r2(Math.max(0, spedizione));
  const totale = r2(merce + commissione + sped);
  return { merce, commissione, spedizione: sped, totale, acconto: r2(acconto), saldo: r2(totale - acconto) };
}
