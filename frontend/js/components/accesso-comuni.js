// Parti condivise da Accedi e Registrati: icone, riquadro errore, errori per campo, occhio password, Google/Microsoft.
import { API_URL } from '../constants.js';
import { ritornoDaHash } from '../ritorno.js';

export const ICONA_OCCHIO = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
export const ICONA_OCCHIO_BARRATO = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>';
export const ICONA_GOOGLE = '<svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true"><path fill="#4285F4" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#34A853" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#EA4335" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
export const ICONA_MICROSOFT = '<svg width="20" height="20" viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>';

const ID_ERRORE = 'acc-error';

export function mostraErrore(testo) {
  const box = document.getElementById(ID_ERRORE);
  if (!box) return;
  box.textContent = testo;
  box.hidden = false;
}

export function nascondiErrore() {
  const box = document.getElementById(ID_ERRORE);
  if (box) { box.hidden = true; box.textContent = ''; }
}

/** Messaggio sotto il campo (aria-live) e aria-invalid; senza messaggio li toglie. */
export function segnaCampo(input, messaggio) {
  const err = document.getElementById(input.id + '-err');
  if (!err) return;
  if (messaggio) {
    input.setAttribute('aria-invalid', 'true');
    input.setAttribute('aria-describedby', err.id);
    err.textContent = messaggio;
  } else {
    input.removeAttribute('aria-invalid');
    input.removeAttribute('aria-describedby');
    err.textContent = '';
  }
}

/** Pulsante occhio: mostra/nascondi la password con etichetta e aria-pressed aggiornati. */
export function collegaOcchio(btn, input) {
  btn.addEventListener('click', () => {
    const nascosta = input.type === 'password';
    input.type = nascosta ? 'text' : 'password';
    btn.innerHTML = nascosta ? ICONA_OCCHIO_BARRATO : ICONA_OCCHIO;
    btn.setAttribute('aria-label', nascosta ? 'Nascondi password' : 'Mostra password');
    btn.setAttribute('aria-pressed', String(nascosta));
  });
}

/** Messaggi per gli errori del ritorno dal provider (#/login?errore=codice): chiari, senza dettagli tecnici. */
const MESSAGGI_OAUTH = {
  annullato: "Hai annullato l'accesso. Se vuoi, riprova oppure usa email e password.",
  email_mancante: 'Il servizio scelto non ci ha dato un indirizzo email: usa email e password.',
  email_non_verificata: 'Il servizio scelto non ha confermato la tua email, quindi non possiamo usarla. Usa email e password.',
  stato_non_valido: 'La richiesta di accesso è scaduta. Riprova.',
  sospeso: "Account sospeso: non puoi accedere. Contatta l'assistenza.",
  non_collegabile: 'Questa email è già collegata a un altro accesso. Usa email e password.',
  non_configurato: 'Questo tipo di accesso non è disponibile al momento. Usa email e password.',
};
const MESSAGGIO_OAUTH_GENERICO = 'Non è stato possibile accedere. Riprova tra poco o usa email e password.';

/**
 * Legge e toglie dall'hash un parametro "una tantum" (es. ?errore=codice): un refresh della pagina non lo rimostra.
 * Gli altri parametri (es. ?next=) restano; si usa replaceState, quindi nessun hashchange e nessuna voce in più nella cronologia.
 */
export function leggiEToglieParametroDaHash(nome) {
  const hash = window.location.hash;
  const [percorso, resto = ''] = hash.split('?');
  const [query, ...coda] = resto.split('#');
  const params = new URLSearchParams(query);
  const valore = params.get(nome);
  if (valore === null) return null;
  params.delete(nome);
  const nuovaQuery = params.toString();
  const nuovoHash = percorso + (nuovaQuery ? `?${nuovaQuery}` : '') + (coda.length ? `#${coda.join('#')}` : '');
  try { window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + nuovoHash); } catch (e) {}
  return valore;
}

/** Se l'URL ha ?errore=codice mostra il messaggio nel riquadro errore della pagina (una sola volta). */
export function mostraErroreOauthDaHash() {
  const codice = leggiEToglieParametroDaHash('errore');
  if (codice) mostraErrore(MESSAGGI_OAUTH[codice] || MESSAGGIO_OAUTH_GENERICO);
}

/** Va al provider passando dal server. La pagina di ritorno resta nella sessione del server, non nell'URL del provider. */
function avviaOauth(provider) {
  nascondiErrore();
  let ritorno = ritornoDaHash();
  if (!ritorno) { try { ritorno = sessionStorage.getItem('postLoginHash'); } catch (e) {} }
  const query = ritorno ? `?ritorno=${encodeURIComponent(ritorno)}` : '';
  window.location.href = `${API_URL}/auth/${provider}/start${query}`;
}

/** I due pulsanti OAuth, uno sotto l'altro. `verbo`: "Accedi" o "Registrati". */
export function oauthHtml(verbo) {
  return `
    <div class="acc-oauth-stack">
      <button type="button" class="acc-oauth" id="btn-google">${ICONA_GOOGLE}<span>${verbo} con Google</span></button>
      <button type="button" class="acc-oauth" id="btn-microsoft">${ICONA_MICROSOFT}<span>${verbo} con Microsoft</span></button>
    </div>`;
}

export function collegaOauth() {
  document.getElementById('btn-google').addEventListener('click', () => avviaOauth('google'));
  document.getElementById('btn-microsoft').addEventListener('click', () => avviaOauth('microsoft'));
}

/** Campo con etichetta, input e messaggio d'errore (aria-live). `attrs`: attributi dell'input. */
export function campoHtml(id, etichetta, attrs = '') {
  return `
    <div class="acc-field">
      <label class="acc-label" for="${id}">${etichetta}</label>
      <input class="acc-input" id="${id}" ${attrs}>
      <div class="acc-field-error" id="${id}-err" aria-live="polite"></div>
    </div>`;
}

/** Campo password con occhio. `requisiti`: id della lista dei requisiti da collegare con aria-describedby. */
export function campoPasswordHtml(id, etichetta, autocomplete, requisitiId = '') {
  return `
    <div class="acc-field">
      <label class="acc-label" for="${id}">${etichetta}</label>
      <div class="acc-pw">
        <input class="acc-input" id="${id}" type="password" autocomplete="${autocomplete}" maxlength="128"${requisitiId ? ` aria-describedby="${requisitiId}"` : ''}>
        <button type="button" class="acc-eye" id="${id}-eye" aria-label="Mostra password" aria-pressed="false">${ICONA_OCCHIO}</button>
      </div>
      ${requisitiId ? requisitiListaHtml(requisitiId) : ''}
      <div class="acc-field-error" id="${id}-err" aria-live="polite"></div>
    </div>`;
}

const REQUISITI = [['lunghezza', 'Almeno 8 caratteri'], ['lettera', 'Una lettera'], ['numero', 'Un numero']];

export function requisitiListaHtml(id) {
  return `<ul class="acc-req" id="${id}" aria-label="Requisiti della password">
    ${REQUISITI.map(([k, t]) => `<li data-req="${k}"><span class="acc-req-mark" aria-hidden="true"></span>${t}<span class="acc-sr-only" data-stato></span></li>`).join('')}
  </ul>`;
}

/** Aggiorna le spunte dei requisiti. `requisiti` = risultato di passwordRequisiti(). */
export function aggiornaRequisiti(listaId, requisiti) {
  document.querySelectorAll(`#${listaId} li`).forEach(li => {
    const ok = requisiti[li.dataset.req];
    li.classList.toggle('ok', ok);
    li.querySelector('[data-stato]').textContent = ok ? ' (soddisfatto)' : '';
  });
}

/** Evita il doppio invio: disattiva il pulsante durante la chiamata e lo riattiva alla fine. */
export async function conAttesa(btn, testoAttesa, fn) {
  const testo = btn.textContent;
  btn.disabled = true;
  btn.textContent = testoAttesa;
  try {
    return await fn();
  } finally {
    btn.disabled = false;
    btn.textContent = testo;
  }
}

const ICONA_PRIVATO = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>';
const ICONA_BUSINESS = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>';

/** Scelta Privato / Business (radio accessibili) con i campi Ragione sociale e P.IVA, nascosti finché non serve Business. `p`: prefisso degli id. */
export function tipoAccountHtml(p) {
  return `
      <fieldset class="acc-type-group">
        <legend class="acc-label">Tipo di account</legend>
        <div class="acc-type-row">
          <label class="acc-type">
            <input type="radio" name="tipo" value="privato" checked>
            <span class="acc-type-box">${ICONA_PRIVATO}<span class="acc-type-title">Privato</span><span class="acc-type-desc">Acquista per te</span></span>
          </label>
          <label class="acc-type">
            <input type="radio" name="tipo" value="b2b">
            <span class="acc-type-box">${ICONA_BUSINESS}<span class="acc-type-title">Business</span><span class="acc-type-desc">Acquista per la tua azienda</span></span>
          </label>
        </div>
      </fieldset>
      <div class="acc-row2" id="${p}-business" hidden>
        ${campoHtml(`${p}-ragione`, 'Ragione sociale *', 'type="text" name="ragione_sociale" autocomplete="organization" maxlength="190"')}
        ${campoHtml(`${p}-piva`, 'Partita IVA *', 'type="text" name="partita_iva" inputmode="numeric" autocomplete="off" maxlength="16" placeholder="IT01234567890"')}
      </div>`;
}
