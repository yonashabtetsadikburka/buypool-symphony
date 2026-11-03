import { API_URL } from './constants.js';
import { getState, clearSession } from './state.js';
import { riscriviRichiesta, aggiungiEuro, adattaCorpo, formDataInOggetto } from './compat.js';

export async function apiRequest(method, url, body = null, extraHeaders = {}) {
  const { token } = getState();

  // Nuova API: niente Bearer, l'identità è nel cookie di sessione (credentials: 'include').
  const headers = {
    'Content-Type': 'application/json',
    ...extraHeaders
  };

  // Le pagine parlano ancora "legacy": percorso, metodo e importi vengono adattati in compat.js
  const nuovo = riscriviRichiesta(method, url);

  const config = {
    method: nuovo.metodo,
    headers,
    credentials: 'include',
    // Dati sempre freschi: mai risposte dalla cache HTTP del browser
    cache: 'no-store'
  };

  if (body && ['POST', 'PUT', 'PATCH'].includes(nuovo.metodo)) {
    config.body = JSON.stringify(adattaCorpo(nuovo.url, nuovo.metodo, body));
  }

  try {
    const response = await fetch(`${API_URL}${nuovo.url}`, config);

    if (response.status === 401) {
      // 401 su endpoint auth = credenziali errate, non sessione scaduta: niente redirect
      const isAuthEndpoint = ['/login', '/registrazione', '/auth/'].some(p => url.includes(p));
      if (!isAuthEndpoint && token) {
        clearSession();
        window.location.href = 'index.html';
        throw new Error('Sessione scaduta');
      }
    }

    let data;
    try {
      data = aggiungiEuro(await response.json());
    } catch (_) {
      throw new Error(response.status === 404 ? 'Funzione non ancora disponibile nella nuova versione' : 'Risposta del server non valida');
    }

    if (!response.ok) {
      if (data.errore?.codice === 'UTENTE_SOSPESO') {
        const toast = document.createElement('div');
        toast.className = 'toast toast-error';
        toast.innerHTML = `<div class="toast-content"><div class="toast-title">Account sospeso</div><div class="toast-description">${data.errore?.messaggio || ''}</div></div>`;
        document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 5000);
      }
      const err = new Error(data.errore?.messaggio || 'Errore del server');
      err.codice = data.errore?.codice;
      err.campi = data.errore?.campi || null;
      throw err;
    }

    return data;
  } catch (error) {
    if (error.message === 'Sessione scaduta') throw error;
    console.error('API Error:', error);
    throw error;
  }
}

export function apiGet(url) {
  return apiRequest('GET', url);
}

export function apiPost(url, body, extraHeaders) {
  return apiRequest('POST', url, body, extraHeaders);
}

export function apiPut(url, body) {
  return apiRequest('PUT', url, body);
}

export function apiDelete(url, extraHeaders) {
  return apiRequest('DELETE', url, null, extraHeaders);
}

/**
 * Il legacy inviava i moduli come multipart (anche con le foto). La nuova API vuole JSON: i campi del modulo
 * diventano un oggetto e i file (foto) vengono ignorati finché l'upload immagini non è migrato (US-23).
 */
export async function apiPostForm(url, formData, extraHeaders = {}) {
  return apiRequest('POST', url, formDataInOggetto(formData), extraHeaders);
}
