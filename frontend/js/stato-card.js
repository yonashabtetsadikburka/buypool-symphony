/* Stato unico di un gruppo nell'elenco "Gruppi d'acquisto" (funzioni pure, testate in tests/home_stato_test.mjs). */

export const FILTRI_STATO = ['tutti', 'quasi', 'scadenza', 'attive', 'conclusi'];

/** Vecchi valori ?stato= ancora validi come alias. */
export const ALIAS_STATO = { concluse: 'conclusi' };

/** Whitelist del parametro ?stato=: valore sconosciuto → 'tutti'. */
export function normalizzaStato(valore) {
  const v = ALIAS_STATO[valore] || valore;
  return FILTRI_STATO.includes(v) ? v : 'tutti';
}

function scaglioniDi(c) { return Array.isArray(c.scaglioni) ? c.scaglioni : []; }

/** Primo gradino (o MOQ se il gruppo non ha scaglioni). */
export function sogliaPrima(c) {
  const s = scaglioniDi(c);
  return s.length ? s[0].soglia : (parseInt(c.quantita_minima) || 1);
}

export function isScaduta(c) {
  const t = new Date(c.data_limite).getTime();
  return Number.isFinite(t) && t < Date.now();
}

/**
 * Stato calcolato dal backend (stato_campagna_display); fallback client con la stessa regola.
 * Valori: in_corso | in_chiusura | conclusa | non_riuscita.
 */
export function displayCampagna(c) {
  if (c.stato_campagna) return c.stato_campagna;
  if (['ordine_pronto', 'ordine_fornitore', 'consegnata'].includes(c.stato)) return 'conclusa';
  if (['fallita', 'annullata'].includes(c.stato)) return 'non_riuscita';
  if (!isScaduta(c)) return 'in_corso';
  if ((parseInt(c.quantita_attuale) || 0) < sogliaPrima(c)) return 'non_riuscita';
  return c.chiusura_data ? 'conclusa' : 'in_chiusura';
}

/**
 * Un solo stato per gruppo.
 * id: aderito | attivo | aperto | concluso | non_attivato
 * cta: unisciti | vedi_acquisto | vedi_gruppo
 * (in_chiusura = scaduto col minimo raggiunto, in attesa dell'addebito dei saldi → "Concluso")
 */
export function statoCard(c) {
  const d = displayCampagna(c);
  if (d === 'non_riuscita') return { id: 'non_attivato', chip: 'Non attivato', terminato: true, cta: 'vedi_gruppo' };
  if (d === 'conclusa' || d === 'in_chiusura') return { id: 'concluso', chip: 'Concluso', terminato: true, cta: 'vedi_gruppo' };
  const mia = c.mia_partecipazione;
  if (mia) {
    const q = parseInt(mia.quantita) || 0;
    return { id: 'aderito', chip: `Hai aderito · ${q} unità`, terminato: false, cta: 'vedi_acquisto' };
  }
  if ((parseInt(c.quantita_attuale) || 0) >= sogliaPrima(c)) {
    return { id: 'attivo', chip: 'Gruppo attivo ✓', terminato: false, cta: 'unisciti' };
  }
  return { id: 'aperto', chip: 'Aperto', terminato: false, cta: 'unisciti' };
}

export const isTerminato = (c) => statoCard(c).terminato;

/** Chiave di ordinamento dei conclusi: dal più recente. */
export function dataFine(c) {
  return new Date(c.chiusura_data || c.data_limite).getTime() || 0;
}
