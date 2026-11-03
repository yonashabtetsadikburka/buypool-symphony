/**
 * Le vecchie pagine "Partecipazioni" e "Ordini" sono state unite in "I miei acquisti".
 * Restituisce la nuova destinazione (senza "#") per la vecchia rotta, oppure null se la rotta non e' una di quelle.
 * Se il vecchio link indicava un acquisto (?id=<numero ordine>) si lascia scegliere la scheda al server.
 */
const SCHEDA_DI_DEFAULT = { '/partecipazioni': 'in-corso', '/ordini': 'in-arrivo' };

export function destinazioneAcquisti(vecchiaRotta, query) {
  const scheda = SCHEDA_DI_DEFAULT[vecchiaRotta];
  if (!scheda) return null;
  const q = new URLSearchParams(query);
  const id = parseInt(q.get('id'));
  const nuova = new URLSearchParams(id > 0 ? { id: String(id) } : { tab: scheda });
  return `/acquisti?${nuova}`;
}
