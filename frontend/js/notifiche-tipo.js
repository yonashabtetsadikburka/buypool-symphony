/* Etichetta di tipo delle notifiche (dashboard "Ultime notifiche"): funzione pura, testata in tests/notifiche_tipo_test.mjs. */

const PAGAMENTO = ['PAGAMENTO_RIUSCITO', 'PAGAMENTO_RICEVUTO', 'PAGAMENTO_FALLITO', 'ORDINE_CONFERMATO'];
const GRUPPO = ['MOQ_RAGGIUNTO', 'NUOVO_SCAGLIONE', 'SOGLIA_PERSA', 'SCADENZA', 'ORDINE_DISPONIBILE', 'ORDINE_INVIATO'];
const RITIRO = ['MERCE_PRONTA', 'RITIRATO', 'ORDINE_RICEVUTO'];

/** {testo, classe} per il tipo; PROPOSTA = notifiche SISTEMA collegate a una proposta (etichetta neutra). */
export function etichettaNotifica(n) {
  const tipo = n && n.tipo;
  if (PAGAMENTO.includes(tipo)) return { testo: 'PAGAMENTO', classe: 'dsh-tipo-pagamento' };
  if (tipo === 'RIMBORSO') return { testo: 'RIMBORSO', classe: 'dsh-tipo-rimborso' };
  if (GRUPPO.includes(tipo)) return { testo: 'GRUPPO', classe: 'dsh-tipo-gruppo' };
  if (RITIRO.includes(tipo)) return { testo: 'RITIRO', classe: 'dsh-tipo-ritiro' };
  if (tipo === 'PROPOSTA_APPROVATA' || (n && n.tipo_riferimento === 'proposta')) return { testo: 'PROPOSTA', classe: 'dsh-tipo-neutro' };
  return { testo: 'NOTIFICA', classe: 'dsh-tipo-neutro' };
}
