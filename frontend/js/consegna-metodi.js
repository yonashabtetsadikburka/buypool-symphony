/* Modalita' di consegna offerte nella finestra di adesione (funzione pura, testata in tests/consegna_metodi_test.mjs).
   Entrambe le schede sono SEMPRE visibili: una non disponibile resta visibile ma disattivata con la spiegazione.
   Il server rivalida comunque la scelta (api/endpoints/pagamento.php, pagamento_prepara_acconto). */

export const NON_DISPONIBILE = 'Non disponibile per questo prodotto';

/**
 * @param {{sedeId:number, ritiroDisponibile?:boolean, spedizioneDisponibile?:boolean}} o
 * @returns {{schede:{id:string,attiva:boolean,motivo:string}[], selezionata:?string, messaggio:string}}
 */
export function metodiConsegna({ sedeId, ritiroDisponibile = true, spedizioneDisponibile = true }) {
  // sedeId è l'id (UUID) del punto di ritiro; vuoto o 0 = nessuna sede
  const ritiro = !!ritiroDisponibile && !!sedeId && String(sedeId) !== '0';
  const spedizione = !!spedizioneDisponibile;
  const schede = [
    { id: 'ritiro_sede', attiva: ritiro, motivo: ritiro ? '' : NON_DISPONIBILE },
    { id: 'consegna_domicilio', attiva: spedizione, motivo: spedizione ? '' : NON_DISPONIBILE }
  ];
  if (ritiro && spedizione) return { schede, selezionata: 'ritiro_sede', messaggio: '' };
  if (ritiro) return { schede, selezionata: 'ritiro_sede', messaggio: 'Per questo prodotto è disponibile solo il ritiro in sede' };
  if (spedizione) return { schede, selezionata: 'consegna_domicilio', messaggio: 'Per questo prodotto è disponibile solo la spedizione a domicilio' };
  return { schede, selezionata: null, messaggio: 'Per questo prodotto non è disponibile nessuna modalità di consegna' };
}
