import { apiPost } from './api.js';
import { API_URL } from './constants.js';
import { saveSession, clearSession, getState } from './state.js';
import { leggiRitorno, consumaRitorno } from './ritorno.js';

export async function login(email, password) {
  const data = await apiPost('/login', { email, password });
  const user = data.dati;
  saveSession(user, 'session_' + user.id);
  return user;
}

export async function register({ nome, cognome, email, password, tipo, privacy, ragione_sociale, partita_iva }) {
  const data = await apiPost('/registrazione', { nome, cognome, email, password, tipo, privacy, ragione_sociale, partita_iva });
  const user = data.dati;
  saveSession(user, 'session_' + user.id);
  return user;
}

export function logout() {
  // La sessione vive sul server (cookie): va chiusa anche lì. keepalive: parte anche se la pagina cambia subito.
  try {
    fetch(`${API_URL}/api/auth/logout`, { method: 'POST', credentials: 'include', keepalive: true });
  } catch (_) { /* il logout locale avviene comunque */ }
  clearSession();
}

export function isAdmin() {
  const { user } = getState();
  return user?.ruolo === 'admin';
}

export function isFornitore() {
  const { user } = getState();
  return user?.ruolo === 'fornitore';
}

export function landingPerRuolo(user) {
  const ritorno = leggiRitorno(user?.ruolo);
  consumaRitorno();
  if (ritorno) return 'app.html#' + ritorno;
  if (user?.ruolo === 'admin') return 'app.html#/admin';
  if (user?.ruolo === 'fornitore') return 'app.html#/fornitore';
  return 'app.html#/';
}

export function isAuthenticated() {
  const { user } = getState();
  return user !== null;
}
