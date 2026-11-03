/* Presentazione delle notifiche del cliente: funzioni pure (testate in tests/notifiche_presentazione_test.mjs).
   Non riscrive i dati salvati: ricava categoria, titolo, dettaglio e link dal tipo di evento e dal testo esistente;
   se un campo non si riconosce, il testo originale diventa il dettaglio. Termini ammessi: "adesione" e "gruppo". */

export const CATEGORIE = {
  PAGAMENTO: { etichetta: 'PAGAMENTO', sfondo: '#E3F0FF', colore: '#0262BB' },
  RIMBORSO: { etichetta: 'RIMBORSO', sfondo: '#DDF4F2', colore: '#0A5550' },
  GRUPPO: { etichetta: 'GRUPPO', sfondo: '#E4F5EA', colore: '#0B5B2A' },
  RITIRO: { etichetta: 'RITIRO', sfondo: '#FFF4E0', colore: '#6B4200' },
  PROPOSTA: { etichetta: 'PROPOSTA', sfondo: '#EDE7FA', colore: '#4B2E9F' },
};

/** Schede filtro: chiave in query string, etichetta, categorie incluse (null = tutte). "da-leggere" filtra per stato. */
export const SCHEDE = [
  ['tutte', 'Tutte'],
  ['da-leggere', 'Da leggere'],
  ['pagamenti', 'Pagamenti'],
  ['gruppi', 'Gruppi'],
  ['ritiri', 'Ritiri'],
  ['proposte', 'Proposte'],
];
const CATEGORIE_SCHEDA = { pagamenti: ['PAGAMENTO', 'RIMBORSO'], gruppi: ['GRUPPO'], ritiri: ['RITIRO'], proposte: ['PROPOSTA'] };

const PAGAMENTO = ['PAGAMENTO_RIUSCITO', 'PAGAMENTO_RICEVUTO', 'PAGAMENTO_FALLITO', 'ORDINE_CONFERMATO'];
const RITIRO = ['MERCE_PRONTA', 'RITIRATO', 'ORDINE_RICEVUTO', 'SPEDITO'];

export function categoriaNotifica(n) {
  const tipo = n && n.tipo;
  if (tipo === 'RIMBORSO') return 'RIMBORSO';
  if (PAGAMENTO.includes(tipo)) return 'PAGAMENTO';
  if (RITIRO.includes(tipo)) return 'RITIRO';
  if (tipo === 'PROPOSTA_APPROVATA' || (n && n.tipo_riferimento === 'proposta')) return 'PROPOSTA';
  return 'GRUPPO'; // tipi non mappabili: Gruppo
}

const MAIUSC = (parola, nuova) => (parola[0] === parola[0].toUpperCase() ? nuova[0].toUpperCase() + nuova.slice(1) : nuova);

/** Rete di sicurezza sui testi gia' salvati: "prenotazione" -> "adesione", "campagna" -> "gruppo", "EUR 1,00" -> "€1,00". */
export function pulisciTesto(testo) {
  const ARTICOLO = { la: 'il', una: 'un', della: 'del', dalla: 'dal', alla: 'al', nella: 'nel', sulla: 'sul' };
  return String(testo ?? '')
    // concordanza di genere: "campagna" (f.) diventa "gruppo" (m.), con articolo e participio
    .replace(/\bNuova campagna pubblicata\b/gi, (m) => MAIUSC(m, 'nuovo gruppo pubblicato'))
    .replace(/\b(la|una|della|dalla|alla|nella|sulla) campagna\b/gi, (m, art) => {
      const nuovo = ARTICOLO[art.toLowerCase()];
      return `${art[0] === art[0].toUpperCase() ? nuovo[0].toUpperCase() + nuovo.slice(1) : nuovo} gruppo`;
    })
    .replace(/\bresta aperta\b/g, 'resta aperto')
    .replace(/\bprenotazion(e|i)\b/gi, (m, f) => MAIUSC(m, f === 'e' ? 'adesione' : 'adesioni'))
    .replace(/\bcampagn(a|e)\b/gi, (m, f) => MAIUSC(m, f === 'a' ? 'gruppo' : 'gruppi'))
    .replace(/\bEUR\s?(\d)/g, '€$1')
    .replace(/\bI miei ordini\b/g, 'I miei acquisti')
    .replace(/\s{2,}/g, ' ')
    .trim();
}

const ESC = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const RE_IMPORTO = /€\s?\d{1,3}(?:\.\d{3})*,\d{2}|€\s?\d+(?:,\d{2})?/g;

/** HTML sicuro: testo con escape e importi in grassetto. */
export function conImportiInGrassetto(testo) {
  return ESC(testo).replace(RE_IMPORTO, (m) => `<strong>${m}</strong>`);
}

const primoImporto = (t) => { const m = String(t).match(RE_IMPORTO); return m ? m[0].replace(/\s/g, '') : ''; };
const pezzi = (n) => `${n} unità`;

/** Titolo breve e dettaglio (una riga) per la notifica; `null` per i campi non riconosciuti. */
function riscrivi(n) {
  const titolo = String(n.titolo || '');
  const msg = pulisciTesto(n.messaggio);
  const importo = primoImporto(msg);
  let m;

  if (n.tipo === 'RIMBORSO') {
    if (/annullato l'adesione/i.test(msg)) {
      return { titolo: 'Adesione annullata · rimborso in corso', dettaglio: `${importo || 'Il rimborso'} in arrivo entro 5–10 giorni lavorativi.` };
    }
    if ((m = msg.match(/^"(.+)" non ha raggiunto il minimo/))) {
      return { titolo: 'Gruppo non attivato · rimborso in corso', dettaglio: `${m[1]}: ${importo ? `${importo} in arrivo` : 'rimborso in arrivo'} entro 5–10 giorni lavorativi.` };
    }
    return { titolo: titolo || 'Rimborso', dettaglio: msg };
  }
  if (n.tipo === 'ORDINE_CONFERMATO') {
    if (/^Acconto addebitato/i.test(titolo) && (m = msg.match(/^(€\S+) per (.+) \((\d+) (?:pezz[oi]|unità)\)\.\s*(.*)$/))) {
      return { titolo: `Acconto addebitato · ${pezzi(m[3])}`, dettaglio: `${m[2]} · ${m[1]}. ${m[4]}`.trim() };
    }
    if ((m = msg.match(/^Hai aggiunto (\d+) (?:pezz[oi]|unità) a "?(.+?)"?: ora ne hai (\d+)\.\s*(?:Acconto aggiuntivo addebitato: (€\S+?)\.?)?$/))) {
      return { titolo: `Hai aggiunto ${pezzi(m[1])} · ora ne hai ${m[3]}`, dettaglio: `${m[2]}${m[4] ? ` · acconto aggiuntivo ${m[4]}` : ''}.` };
    }
    if ((m = msg.match(/^La tua adesione per "?(.+?)"? \((\d+) (?:pezz[oi]|unità)\) è confermata\.\s*Acconto pagato: (€\S+?)\.?$/))) {
      return { titolo: `Adesione registrata · ${pezzi(m[2])}`, dettaglio: `${m[1]} · acconto ${m[3]}.` };
    }
    return { titolo: pulisciTesto(titolo) || 'Adesione', dettaglio: msg };
  }
  if (n.tipo === 'PAGAMENTO_FALLITO') {
    return { titolo: 'Saldo non addebitato · serve la tua azione', dettaglio: msg.replace(/^Azione richiesta\.?\s*/i, '') };
  }
  if (n.tipo === 'PAGAMENTO_RIUSCITO' || n.tipo === 'PAGAMENTO_RICEVUTO') {
    return { titolo: /saldo/i.test(titolo) ? 'Saldo addebitato' : 'Pagamento ricevuto', dettaglio: msg };
  }
  if (n.tipo === 'ORDINE_DISPONIBILE') {
    return { titolo: 'Nuovo gruppo disponibile', dettaglio: msg.replace(/^Nuovo gruppo pubblicato: /i, '') };
  }
  if (n.tipo === 'MOQ_RAGGIUNTO' || /Soglia Raggiunta/i.test(titolo)) {
    return { titolo: 'Minimo raggiunto', dettaglio: msg };
  }
  return null;
}

/** Elemento pronto per la pagina: categoria, titolo, dettaglio (testo e html), link e testo del pulsante. */
export function presenta(n) {
  const categoria = categoriaNotifica(n);
  const r = riscrivi(n);
  const titolo = (r && r.titolo) || pulisciTesto(n.titolo) || 'Notifica';
  const dettaglio = (r && r.dettaglio) || pulisciTesto(n.messaggio);
  let link = '';
  let testoLink = '';
  const id = n.id_riferimento;
  if (id) {
    if (n.tipo_riferimento === 'prenotazione') { link = `#/acquisti?id=${id}`; testoLink = 'Vedi acquisto'; }
    else if (n.tipo_riferimento === 'colletta') { link = `#/campagne/${id}`; testoLink = 'Vedi gruppo'; }
    else if (n.tipo_riferimento === 'proposta') { link = '#/proposte'; testoLink = 'Vedi proposta'; }
  }
  return { categoria, titolo, dettaglio, dettaglioHtml: conImportiInGrassetto(dettaglio), link, testoLink, letta: !!n.letta };
}

/* ------------------------------ filtri, conteggi, giorni ------------------------------ */

export function filtraNotifiche(lista, scheda) {
  if (scheda === 'da-leggere') return lista.filter(n => !n.letta);
  const cat = CATEGORIE_SCHEDA[scheda];
  return cat ? lista.filter(n => cat.includes(categoriaNotifica(n))) : lista;
}

export function contaSchede(lista) {
  const out = {};
  SCHEDE.forEach(([k]) => { out[k] = filtraNotifiche(lista, k).length; });
  return out;
}

export function schedaValida(v) {
  return SCHEDE.some(([k]) => k === v) ? v : 'tutte';
}

/** "Oggi" / "Ieri" / "Questa settimana" (ultimi 7 giorni) / "Più vecchie". Date locali. */
export function gruppoGiorno(dataStr, ora = new Date()) {
  const d = new Date(String(dataStr).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return 'Più vecchie';
  const g0 = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const giorni = Math.round((g0(ora) - g0(d)) / 86400000);
  if (giorni <= 0) return 'Oggi';
  if (giorni === 1) return 'Ieri';
  if (giorni < 7) return 'Questa settimana';
  return 'Più vecchie';
}

export const ORDINE_GRUPPI = ['Oggi', 'Ieri', 'Questa settimana', 'Più vecchie'];

export function raggruppaPerGiorno(lista, ora = new Date()) {
  const mappa = new Map(ORDINE_GRUPPI.map(g => [g, []]));
  lista.forEach(n => mappa.get(gruppoGiorno(n.data_creazione, ora)).push(n));
  return ORDINE_GRUPPI.filter(g => mappa.get(g).length).map(g => ({ titolo: g, voci: mappa.get(g) }));
}

export function frasePendenti(nonLette) {
  if (nonLette <= 0) return 'Sei in pari.';
  return nonLette === 1 ? 'Hai 1 notifica da leggere.' : `Hai ${nonLette} notifiche da leggere.`;
}

export function oraBreve(dataStr) {
  const d = new Date(String(dataStr).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
}
