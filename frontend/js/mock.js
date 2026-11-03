// ============================================================
// UTENTI
// ============================================================
export const UTENTI = [
  {
    id: 1,
    nome: 'Mario',
    cognome: 'Rossi',
    email: 'mario.rossi@email.com',
    telefono: '333 1234567',
    indirizzo: 'Via Roma 15, Perugia',
    tipo: 'privato',
    ruolo: 'cliente',
    stato: 'attivo',
    google_id: null,
    microsoft_id: null,
    stripe_customer_id: 'cus_ABC123',
    partita_iva: null,
    data_iscrizione: '2026-01-15'
  },
  {
    id: 2,
    nome: 'Admin',
    cognome: 'BuyPool',
    email: 'admin@buypool.it',
    telefono: '333 7654321',
    indirizzo: 'Via Milano 1, Perugia',
    tipo: 'privato',
    ruolo: 'admin',
    stato: 'attivo',
    google_id: null,
    microsoft_id: null,
    stripe_customer_id: null,
    partita_iva: null,
    data_iscrizione: '2025-01-01'
  },
  {
    id: 3,
    nome: 'Giulia',
    cognome: 'Bianchi',
    email: 'giulia.bianchi@email.com',
    telefono: '333 9876543',
    indirizzo: 'Via Napoli 22, Perugia',
    tipo: 'privato',
    ruolo: 'cliente',
    stato: 'attivo',
    google_id: null,
    microsoft_id: null,
    stripe_customer_id: null,
    partita_iva: null,
    data_iscrizione: '2026-02-20'
  },
  {
    id: 4,
    nome: 'Luca',
    cognome: 'Verdi',
    email: 'luca.verdi@email.com',
    telefono: '333 4567890',
    indirizzo: 'Via Firenze 8, Spoleto',
    tipo: 'b2b',
    ruolo: 'cliente',
    stato: 'attivo',
    google_id: null,
    microsoft_id: null,
    stripe_customer_id: null,
    partita_iva: 'IT12345678901',
    data_iscrizione: '2026-03-10'
  },
  {
    id: 5,
    nome: 'Anna',
    cognome: 'Neri',
    email: 'anna.neri@email.com',
    telefono: '333 2345678',
    indirizzo: 'Via Torino 5, Perugia',
    tipo: 'privato',
    ruolo: 'cliente',
    stato: 'attivo',
    google_id: null,
    microsoft_id: null,
    stripe_customer_id: null,
    partita_iva: null,
    data_iscrizione: '2026-04-05'
  },
  {
    id: 6,
    nome: 'Marco',
    cognome: 'Gialli',
    email: 'marco.gialli@email.com',
    telefono: '333 3456789',
    indirizzo: 'Via Bologna 12, Foligno',
    tipo: 'privato',
    ruolo: 'cliente',
    stato: 'attivo',
    google_id: null,
    microsoft_id: null,
    stripe_customer_id: null,
    partita_iva: null,
    data_iscrizione: '2026-05-12'
  }
];

// ============================================================
// FORNITORI
// ============================================================
export const FORNITORI = [
  {
    id: 1,
    nome_azienda: 'TechSupply Srl',
    email_contatto: 'info@techsupply.it',
    telefono: '075 1234567',
    indirizzo: 'Via dell\'Industria 10, Perugia',
    descrizione: 'Fornitore specializzato in elettronica e accessori tech di qualita.',
    logo_url: null,
    partner_pubblico: true,
    trust_score: 85,
    num_campagne: 5,
    data_partnership: '2025-06-15'
  },
  {
    id: 2,
    nome_azienda: 'SportMax Spa',
    email_contatto: 'info@sportmax.it',
    telefono: '075 7654321',
    indirizzo: 'Via dello Sport 25, Perugia',
    descrizione: 'Articoli sportivi e fitness per ogni esigenza.',
    logo_url: null,
    partner_pubblico: true,
    trust_score: 78,
    num_campagne: 3,
    data_partnership: '2025-09-20'
  },
  {
    id: 3,
    nome_azienda: 'GreenTech Srl',
    email_contatto: 'info@greentech.it',
    telefono: '074 9876543',
    indirizzo: 'Via Verde 5, Spoleto',
    descrizione: 'Soluzioni ecologiche e rinnovabili per un futuro sostenibile.',
    logo_url: null,
    partner_pubblico: true,
    trust_score: 92,
    num_campagne: 7,
    data_partnership: '2025-03-10'
  }
];

// ============================================================
// PRODOTTI
// ============================================================
export const PRODOTTI = [
  {
    id: 1,
    id_fornitore: 1,
    id_proposta_origine: null,
    nome: 'Cuffie Bluetooth Pro',
    descrizione: 'Cuffie wireless con cancellazione del rumore attiva, autonomia 30 ore, certificazione IPX5.',
    image_url: null,
    prezzo_unitario: 29.99,
    quantita_minima: 50,
    stato: 'attivo',
    data_creazione: '2026-07-01'
  },
  {
    id: 2,
    id_fornitore: 2,
    id_proposta_origine: null,
    nome: 'Tappetino Yoga Premium',
    descrizione: 'Tappetino antiscivolo in microfibra, spessore 6mm, incluso cintura di trasporto.',
    image_url: null,
    prezzo_unitario: 22.50,
    quantita_minima: 100,
    stato: 'attivo',
    data_creazione: '2026-07-10'
  },
  {
    id: 3,
    id_fornitore: 3,
    id_proposta_origine: null,
    nome: 'Caricabatterie Solare 20W',
    descrizione: 'Pannello solare pieghevole con 2 porte USB-C e USB-A, ideale per campeggio.',
    image_url: null,
    prezzo_unitario: 24.99,
    quantita_minima: 40,
    stato: 'attivo',
    data_creazione: '2026-07-15'
  }
];

// ============================================================
// SEDI
// ============================================================
export const SEDI = [
  { id_sede: 1, nome: 'Sede Centrale Perugia', indirizzo: 'Via Assisana 10', citta: 'Perugia', telefono: '075 1112233', orari: 'Lun-Ven 9:00-18:00, Sab 9:00-13:00' },
  { id_sede: 2, nome: 'Punto Ritiro Spoleto', indirizzo: 'Via del Ponte 5', citta: 'Spoleto', telefono: '074 4445566', orari: 'Lun-Ven 10:00-17:00' },
  { id_sede: 3, nome: 'Punto Ritiro Foligno', indirizzo: 'Via Mazzini 15', citta: 'Foligno', telefono: '074 7778899', orari: 'Lun-Ven 9:00-18:00, Sab 9:00-13:00' }
];

// ============================================================
// COLLETTE
// ============================================================
export const COLLETTE = [
  {
    id: 1,
    id_prodotto: 1,
    id_aperta_da: 1,
    id_referente: 1,
    id_sede: 1,
    quantita_minima: 50,
    quantita_attuale: 50,
    data_inizio: '2026-07-15',
    data_limite: '2026-08-30',
    stato: 'riuscita',
    data_agg_stato: '2026-08-15 10:00:00',
    regola_arrotondamento: 'difetto',
    prezzo_base: 39.99,
    prezzo_corrente: 29.99,
    percentuale_commissione: 10.00,
    id_admin_conferma: 2,
    data_conferma: '2026-08-16 14:00:00',
    prodotto: null,
    fornitore: null
  },
  {
    id: 2,
    id_prodotto: 2,
    id_aperta_da: 3,
    id_referente: 3,
    id_sede: 2,
    quantita_minima: 100,
    quantita_attuale: 78,
    data_inizio: '2026-07-20',
    data_limite: '2026-09-15',
    stato: 'in_corso',
    data_agg_stato: null,
    regola_arrotondamento: 'difetto',
    prezzo_base: 35.00,
    prezzo_corrente: 22.50,
    percentuale_commissione: 10.00,
    id_admin_conferma: null,
    data_conferma: null,
    prodotto: null,
    fornitore: null
  },
  {
    id: 3,
    id_prodotto: 3,
    id_aperta_da: 5,
    id_referente: null,
    id_sede: 3,
    quantita_minima: 40,
    quantita_attuale: 18,
    data_inizio: '2026-08-01',
    data_limite: '2026-09-10',
    stato: 'in_corso',
    data_agg_stato: null,
    regola_arrotondamento: 'difetto',
    prezzo_base: 29.99,
    prezzo_corrente: 24.99,
    percentuale_commissione: 10.00,
    id_admin_conferma: null,
    data_conferma: null,
    prodotto: null,
    fornitore: null
  }
];

// ============================================================
// SCAGLIONI PREZZO
// ============================================================
export const SCAGLIONI_PREZZO = [
  { id: 1, id_colletta: 1, soglia_partecipanti: 10, prezzo_unitario: 29.99 },
  { id: 2, id_colletta: 1, soglia_partecipanti: 25, prezzo_unitario: 26.50 },
  { id: 3, id_colletta: 1, soglia_partecipanti: 50, prezzo_unitario: 22.00 },
  { id: 4, id_colletta: 2, soglia_partecipanti: 20, prezzo_unitario: 22.50 },
  { id: 5, id_colletta: 2, soglia_partecipanti: 50, prezzo_unitario: 19.00 },
  { id: 6, id_colletta: 2, soglia_partecipanti: 100, prezzo_unitario: 15.00 }
];

// ============================================================
// PRENOTAZIONI
// ============================================================
export const PRENOTAZIONI = [
  {
    id: 1,
    id_colletta: 1,
    id_utente: 1,
    quantita: 2,
    importo_acconto: 0,
    importo_saldo: 59.98,
    importo_commissione: 5.998,
    stato: 'confermata',
    data_prenotazione: '2026-07-20',
    data_pagamento: '2026-08-16 14:30:00',
    colletta: null
  },
  {
    id: 2,
    id_colletta: 2,
    id_utente: 1,
    quantita: 1,
    importo_acconto: 0,
    importo_saldo: null,
    importo_commissione: 0,
    stato: 'prenotata',
    data_prenotazione: '2026-08-01',
    data_pagamento: null,
    colletta: null
  },
  {
    id: 3,
    id_colletta: 1,
    id_utente: 3,
    quantita: 1,
    importo_acconto: 0,
    importo_saldo: 29.99,
    importo_commissione: 2.999,
    stato: 'confermata',
    data_prenotazione: '2026-07-25',
    data_pagamento: '2026-08-16 14:30:00',
    colletta: null
  },
  {
    id: 4,
    id_colletta: 2,
    id_utente: 4,
    quantita: 3,
    importo_acconto: 0,
    importo_saldo: null,
    importo_commissione: 0,
    stato: 'prenotata',
    data_prenotazione: '2026-08-05',
    data_pagamento: null,
    colletta: null
  }
];

// ============================================================
// PAGAMENTI
// ============================================================
export const PAGAMENTI = [
  {
    id: 1,
    id_prenotazione: 1,
    tipo_pagamento: 'saldo',
    importo: 59.98,
    valuta: 'EUR',
    commissione_agenzia: 5.998,
    stripe_payment_intent_id: 'pi_ABC123',
    stripe_charge_id: 'ch_ABC123',
    stripe_payment_method: 'pm_card_4242',
    stato_stripe: 'succeeded',
    stato: 'confermato',
    data_pagamento: '2026-08-16 14:30:00',
    data_conferma: '2026-08-16 14:30:05'
  },
  {
    id: 2,
    id_prenotazione: 3,
    tipo_pagamento: 'saldo',
    importo: 29.99,
    valuta: 'EUR',
    commissione_agenzia: 2.999,
    stripe_payment_intent_id: 'pi_DEF456',
    stripe_charge_id: 'ch_DEF456',
    stripe_payment_method: 'pm_card_8888',
    stato_stripe: 'succeeded',
    stato: 'confermato',
    data_pagamento: '2026-08-16 14:30:00',
    data_conferma: '2026-08-16 14:30:05'
  }
];

// ============================================================
// QR CODES
// ============================================================
export const QR_CODES = [
  { id: 1, id_prenotazione: 1, token: 'qr_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4', quantita_assegnata: 2, stato: 'generato', data_generazione: '2026-08-15 10:05:00', data_scansione: null },
  { id: 2, id_prenotazione: 3, token: 'qr_z9y8x7w6v5u4t3s2r1q0p9o8n7m6l5k4j3i2h1g0f1e2d3c4', quantita_assegnata: 1, stato: 'generato', data_generazione: '2026-08-15 10:05:00', data_scansione: null }
];

// ============================================================
// ORDINI FORNITORE
// ============================================================
export const ORDINI_FORNITORE = [
  {
    id: 1,
    id_colletta: 1,
    id_fornitore: 1,
    id_admin: 2,
    quantita_ordinata: 50,
    prezzo_negoziato: 22.00,
    importo_totale: 1100.00,
    stato: 'inviato',
    data_ordine: '2026-08-16 15:00:00',
    data_consegna: null
  }
];

// ============================================================
// NOTIFICHE
// ============================================================
export const NOTIFICHE = [
  { id: 1, id_utente: 1, tipo: 'MOQ_RAGGIUNTO', titolo: 'Soglia Raggiunta', messaggio: 'Cuffie Bluetooth Pro: soglia raggiunta! QR code disponibile.', tipo_riferimento: 'colletta', id_riferimento: 1, letta: true, data_creazione: '2026-08-15', data_lettura: '2026-08-15 12:00:00' },
  { id: 2, id_utente: 1, tipo: 'ORDINE_CONFERMATO', titolo: 'Ordine Confermato', messaggio: 'Ordine Cuffie Bluetooth Pro confermato dall\'admin.', tipo_riferimento: 'colletta', id_riferimento: 1, letta: true, data_creazione: '2026-08-16', data_lettura: '2026-08-16 15:00:00' },
  { id: 3, id_utente: 1, tipo: 'PAGAMENTO_RIUSCITO', titolo: 'Pagamento Ricevuto', messaggio: 'Pagamento di €65.98 per Cuffie Bluetooth Pro ricevuto con successo.', tipo_riferimento: 'prenotazione', id_riferimento: 1, letta: false, data_creazione: '2026-08-16', data_lettura: null },
  { id: 4, id_utente: 1, tipo: 'SCADENZA', titolo: 'Campagna in Scadenza', messaggio: 'Tappetino Yoga Premium scade tra 3 giorni. MOQ al 78%', tipo_riferimento: 'colletta', id_riferimento: 2, letta: false, data_creazione: '2026-08-12', data_lettura: null }
];

// ============================================================
// PROPOSTE PRODOTTI
// ============================================================
export const PROPOSTE_PRODOTTI = [
  {
    id_proposta: 1,
    proponente_tipo: 'cliente',
    proponente_id: 1,
    nome_prodotto: 'Smartwatch XYZ',
    descrizione: 'Smartwatch con GPS integrato, resistenza all\'acqua 5ATM, batteria 7 giorni.',
    image_url: null,
    id_fornitore_suggerito: 1,
    stato: 'in_votazione',
    id_admin_gestione: null,
    tot_voti: 12,
    data_proposta: '2026-08-01',
    data_gestione: null,
    utente: null,
    voti: [],
    votatoDa: []
  },
  {
    id_proposta: 2,
    proponente_tipo: 'cliente',
    proponente_id: 3,
    nome_prodotto: 'Lampada LED Smart',
    descrizione: 'Lampada da scrivania LED con controllo tramite app, 5 livelli di luminosita.',
    image_url: null,
    id_fornitore_suggerito: 3,
    stato: 'in_attesa',
    id_admin_gestione: null,
    tot_voti: 3,
    data_proposta: '2026-08-10',
    data_gestione: null,
    utente: null,
    voti: [],
    votatoDa: []
  },
  {
    id_proposta: 3,
    proponente_tipo: 'admin',
    proponente_id: 2,
    nome_prodotto: 'Pannello Solare 100W',
    descrizione: 'Pannello solare ad alta efficienza per uso domestico.',
    image_url: null,
    id_fornitore_suggerito: 3,
    stato: 'in_votazione',
    id_admin_gestione: 2,
    tot_voti: 8,
    data_proposta: '2026-08-20',
    data_gestione: null,
    utente: null,
    voti: [],
    votatoDa: []
  }
];

// ============================================================
// VOTI PROPOSTE
// ============================================================
export const VOTI_PROPOSTE = [
  { id_voto: 1, id_proposta: 1, id_utente: 3, valore_voto: 'favore', data_voto: '2026-08-02' },
  { id_voto: 2, id_proposta: 1, id_utente: 4, valore_voto: 'favore', data_voto: '2026-08-03' },
  { id_voto: 3, id_proposta: 1, id_utente: 5, valore_voto: 'favore', data_voto: '2026-08-04' },
  { id_voto: 4, id_proposta: 2, id_utente: 1, valore_voto: 'favore', data_voto: '2026-08-11' },
  { id_voto: 5, id_proposta: 3, id_utente: 1, valore_voto: 'favore', data_voto: '2026-08-21' },
  { id_voto: 6, id_proposta: 3, id_utente: 3, valore_voto: 'favore', data_voto: '2026-08-22' }
];

// ============================================================
// HELPER FUNCTIONS
// ============================================================

export function getCollettaById(id) {
  const colletta = COLLETTE.find(c => c.id === parseInt(id));
  if (!colletta) return null;

  const prodotto = PRODOTTI.find(p => p.id === colletta.id_prodotto);
  const fornitore = FORNITORI.find(f => f.id === prodotto?.id_fornitore);

  return {
    ...colletta,
    prodotto: prodotto || null,
    fornitore: fornitore || null
  };
}

export function getFornitoreById(id) {
  const fornitore = FORNITORI.find(f => f.id === parseInt(id));
  if (!fornitore) return null;

  const prodotti = PRODOTTI.filter(p => p.id_fornitore === fornitore.id);
  const campagne = COLLETTE.filter(c => {
    const prodotto = PRODOTTI.find(p => p.id === c.id_prodotto);
    return prodotto?.id_fornitore === fornitore.id;
  });

  return {
    ...fornitore,
    prodotti: prodotti,
    num_campagne: campagne.length
  };
}

export function getUtenteById(id) {
  return UTENTI.find(u => u.id === parseInt(id)) || null;
}

export function getNotificheByUtente(idUtente) {
  return NOTIFICHE.filter(n => n.id_utente === parseInt(idUtente));
}

export function getPrenotazioniByUtente(idUtente) {
  return PRENOTAZIONI.filter(p => p.id_utente === parseInt(idUtente));
}

export function getQrByPrenotazione(idPrenotazione) {
  return QR_CODES.find(q => q.id_prenotazione === parseInt(idPrenotazione)) || null;
}

export function getQrByToken(token) {
  return QR_CODES.find(q => q.token === token) || null;
}

export function getOrdineByColletta(idColletta) {
  return ORDINI_FORNITORE.find(o => o.id_colletta === parseInt(idColletta)) || null;
}

export function getProposteByCliente(idUtente) {
  return PROPOSTE_PRODOTTI.filter(p => p.proponente_tipo === 'cliente' && p.proponente_id === parseInt(idUtente));
}

export function getVotiByProposta(idProposta) {
  return VOTI_PROPOSTE.filter(v => v.id_proposta === parseInt(idProposta));
}
