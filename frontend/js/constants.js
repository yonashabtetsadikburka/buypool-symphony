// Backend Symfony (php -S localhost:8000 -t public, da backend/). Il frontend gira su un'altra porta (vedi frontend/README.md).
export const API_URL = 'http://localhost:8000';

/** Percentuale di acconto sul prezzo primo scaglione (solo presentazione: la logica di pagamento non la usa). */
export const ACCONTO_PERCENT = 20;

export const STATI_CAMPAGNA_LABELS = {
  in_corso: 'In Corso',
  riuscita: 'Il gruppo raggiunge il minimo',
  fallita: 'Fallita',
  ordine_pronto: 'Ordine Confermato',
  ordine_fornitore: 'Ordine al Fornitore',
  consegnata: 'Consegnata',
  annullata: 'Annullata'
};

export const STATI_CAMPAGNA_BADGES = {
  in_corso: 'badge-default',
  riuscita: 'badge-success',
  fallita: 'badge-destructive',
  ordine_pronto: 'badge-warning',
  ordine_fornitore: 'badge-info',
  consegnata: 'badge-success',
  annullata: 'badge-secondary'
};

export const STATI_PRENOTAZIONE_LABELS = {
  prenotata: 'In Attesa',
  confermata: 'Da Pagare',
  pagata: 'Pagata',
  annullata: 'Annullata',
  rimborsata: 'Rimborsata',
  azione_richiesta: 'Azione richiesta'
};

export const STATI_PRENOTAZIONE_BADGES = {
  prenotata: 'badge-warning',
  confermata: 'badge-info',
  pagata: 'badge-success',
  annullata: 'badge-destructive',
  rimborsata: 'badge-secondary',
  azione_richiesta: 'badge-destructive'
};

export const RUOLI_LABELS = {
  cliente: 'Cliente',
  fornitore: 'Fornitore',
  admin: 'Amministratore'
};

export const RUOLI_BADGES = {
  cliente: 'badge-secondary',
  fornitore: 'badge-info',
  admin: 'badge-default'
};
