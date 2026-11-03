/**
 * Stato ordine unificato (condiviso tra "I miei ordini" e "Le mie partecipazioni").
 * Priorità: fallita > finale > pronto > fornitore > pagato > attesa.
 */
export const STATI_ORDINE = {
  attesa: { label: 'In attesa', variant: 'warning' },
  pagato: { label: 'Pagato', variant: 'info' },
  fornitore: { label: 'Ordine al fornitore', variant: 'info' },
  pronto: { label: 'Pronto per il ritiro', variant: 'default' },
  finale: { label: 'Ritirato', variant: 'success' },
  fallita: { label: 'Non riuscita', variant: 'secondary' },
  azione: { label: 'Azione richiesta', variant: 'destructive' }
};

export const STATI_COLLETTA_AVANZATI = ['ordine_pronto', 'ordine_fornitore', 'consegnata'];

export function deliveryType(p) {
  if (p.consegna_modalita === 'consegna_domicilio') return 'spedizione';
  if (p.consegna_modalita === 'ritiro_sede') return 'ritiro';
  return null;
}

/** Restituisce la chiave di stato unificata per una partecipazione/ordine. */
export function statoOrdine(p) {
  if (['annullata', 'rimborsata'].includes(p.stato)) return 'fallita';
  if (['fallita', 'annullata'].includes(p.stato_colletta)) return 'fallita';
  if (p.stato === 'azione_richiesta') return 'azione';
  if (p.stato_qr === 'scansionato' || ['ritirata', 'consegnata'].includes(p.consegna_stato)) return 'finale';
  if (p.stato !== 'pagata') return 'attesa';
  // Pronto solo quando il fornitore ha evaso il gruppo (non appena addebitato il saldo): stessa regola di /mie/acquisti
  if (deliveryType(p) === 'ritiro' && p.stato_colletta === 'consegnata') return 'pronto';
  if (STATI_COLLETTA_AVANZATI.includes(p.stato_colletta) || ['pronta', 'spedita'].includes(p.consegna_stato)) return 'fornitore';
  return 'pagato';
}

/** Etichetta: stesso stato interno, "Consegnato" per la spedizione al passo finale. */
export function etichettaStato(p) {
  const key = statoOrdine(p);
  if (key === 'finale' && deliveryType(p) === 'spedizione') return 'Consegnato';
  return STATI_ORDINE[key].label;
}

/**
 * Percorso a 6 passi mostrato nella dashboard cliente (SOLO presentazione, i valori nel DB non cambiano):
 * 1 si apre il gruppo, 2 ti unisci/acconto, 3 il prezzo scende, 4 paghi il saldo, 5 il fornitore invia, 6 ricevi/ritiri.
 * Parte da statoOrdine(p) e lo traduce in etichetta + passo corrente + tono dell'etichetta.
 * tono: 'gruppo' | 'blu' | 'ambra' | 'verde' | 'grigio'
 * @returns {{chiave: string, etichetta: string, passo: number, tono: string, concluso: boolean}}
 */
export const PERCORSO_PASSI = 6;

export function percorsoOrdine(p) {
  const chiave = statoOrdine(p);
  switch (chiave) {
    case 'fallita':
      return { chiave, etichetta: 'Gruppo non riuscito', passo: 0, tono: 'grigio', concluso: false };
    case 'azione':
      return { chiave, etichetta: 'Pagamento non riuscito', passo: 4, tono: 'ambra', concluso: false };
    case 'finale':
      return { chiave, etichetta: etichettaStato(p), passo: 6, tono: 'verde', concluso: true };
    case 'pronto':
      return { chiave, etichetta: 'Pronto per il ritiro', passo: 6, tono: 'ambra', concluso: false };
    case 'fornitore':
    case 'pagato':
      return { chiave, etichetta: 'Ordine inviato al fornitore', passo: 5, tono: 'blu', concluso: false };
    default: // 'attesa': acconto versato, gruppo ancora aperto (prenotata / confermata)
      return { chiave, etichetta: 'Gruppo in corso', passo: 3, tono: 'gruppo', concluso: false };
  }
}
