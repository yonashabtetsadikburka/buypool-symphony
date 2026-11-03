/* Stato di ogni scaglione di prezzo rispetto ai pezzi del gruppo (solo presentazione: i prezzi non si ricalcolano qui).
   - superato: la soglia e' gia' stata oltrepassata da uno scaglione successivo (anche il primo/minimo)
   - attivo: scaglione corrente = ultimo raggiunto (con il solo primo raggiunto e' lui l'"Attivo ora")
   - da_raggiungere: soglia non ancora raggiunta */
export function statoScaglioni(scaglioni, pezzi) {
  const lista = Array.isArray(scaglioni) ? scaglioni : [];
  const qty = parseInt(pezzi) || 0;
  let ultimoRaggiunto = -1;
  lista.forEach((s, i) => { if (qty >= (parseInt(s.soglia) || 0)) ultimoRaggiunto = i; });
  return lista.map((s, i) => {
    if (ultimoRaggiunto < 0 || i > ultimoRaggiunto) return 'da_raggiungere';
    return i === ultimoRaggiunto ? 'attivo' : 'superato';
  });
}

export const ETICHETTA_STATO = { superato: 'Superato', attivo: 'Attivo ora', da_raggiungere: 'Da raggiungere' };
