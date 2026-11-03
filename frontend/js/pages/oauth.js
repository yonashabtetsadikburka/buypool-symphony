// Ritorno dall'accesso con Google/Microsoft: accesso completato, "Un ultimo passo" o collegamento con la password.
import { apiGet, apiPost } from '../api.js';
import { saveSession } from '../state.js';
import { landingPerRuolo } from '../auth.js';
import { accessoSolo } from '../components/accesso-layout.js';
import { mostraErrore, nascondiErrore, segnaCampo, collegaOcchio, campoPasswordHtml, tipoAccountHtml, conAttesa } from '../components/accesso-comuni.js';
import { pivaNormalizza } from '../validazione.js';

function radice() {
  return document.getElementById('content-area') || document.querySelector('.main-content');
}

function mostraCard(html) {
  const content = radice();
  if (content) content.innerHTML = accessoSolo(html);
}

/** Salva la sessione e va alla pagina di ritorno (validata di nuovo) o alla pagina iniziale del ruolo. */
function entra(utente, ritorno) {
  saveSession(utente, 'oauth_' + utente.id);
  try {
    if (ritorno) sessionStorage.setItem('postLoginHash', ritorno);
  } catch (e) {}
  window.location.replace(landingPerRuolo(utente));
}

function vaiAccedi(errore) {
  window.location.replace('index.html#/login' + (errore ? `?errore=${errore}` : ''));
}

export async function OAuthEsitoPage() {
  mostraCard('<p class="acc-sub" role="status">Un attimo…</p>');
  let esito;
  try {
    esito = (await apiGet('/auth/oauth/esito')).dati;
  } catch (err) {
    vaiAccedi('stato_non_valido');
    return;
  }
  if (esito.stato === 'ok') return entra(esito.utente, esito.ritorno);
  if (esito.stato === 'collega') return CollegaPage(esito);
  return UltimoPassoPage(esito);
}

/* ---------- Un ultimo passo ---------- */

function UltimoPassoPage(esito) {
  const fornitore = !!esito.fornitore;
  mostraCard(`
    ${fornitore ? '<span class="acc-badge">INVITO FORNITORE</span>' : ''}
    <h1 class="acc-title">Un ultimo passo</h1>
    <p class="acc-sub" id="ultimo-testo"></p>
    <div id="acc-error" class="acc-alert" role="alert" hidden></div>
    <form class="acc-form" id="ultimo-form" novalidate>
      ${fornitore ? '' : tipoAccountHtml('ult')}
      <div class="acc-field">
        <label class="acc-check">
          <input type="checkbox" id="ult-privacy" name="privacy">
          <span>Ho letto l'<a href="index.html#/privacy" target="_blank" rel="noopener">informativa privacy</a> e acconsento al trattamento dei dati.</span>
        </label>
        <div class="acc-field-error" id="ult-privacy-err" aria-live="polite"></div>
      </div>
      <button type="submit" class="acc-btn" id="ult-submit" disabled>${fornitore ? 'Attiva l\'account' : 'Crea il mio account'}</button>
    </form>
    <p class="acc-foot"><a href="index.html#/login">Annulla</a></p>
  `);

  const testo = document.getElementById('ultimo-testo');
  testo.append(`Stai entrando con ${esito.provider} come `);
  const mail = document.createElement('strong');
  mail.textContent = esito.email;
  testo.append(mail, fornitore ? '. Attiverai l\'account fornitore di ' : '. Ti serve solo confermare questi dati per creare il tuo account.');
  if (fornitore) {
    const az = document.createElement('strong');
    az.textContent = esito.fornitore;
    testo.append(az, '.');
  }

  const form = document.getElementById('ultimo-form');
  const privacy = document.getElementById('ult-privacy');
  const submit = document.getElementById('ult-submit');
  const business = document.getElementById('ult-business');
  const ragione = document.getElementById('ult-ragione');
  const piva = document.getElementById('ult-piva');
  const tipo = () => (fornitore ? 'privato' : form.querySelector('input[name="tipo"]:checked').value);

  const completo = () => privacy.checked
    && (tipo() !== 'b2b' || (ragione.value.trim() !== '' && pivaNormalizza(piva.value) !== null));
  const aggiorna = () => { submit.disabled = !completo(); };
  form.addEventListener('input', aggiorna);
  form.addEventListener('change', () => { if (business) business.hidden = tipo() !== 'b2b'; aggiorna(); });
  if (piva) piva.addEventListener('blur', () => {
    const v = piva.value.trim();
    segnaCampo(piva, tipo() !== 'b2b' || !v || pivaNormalizza(v) ? '' : 'Partita IVA non valida: servono 11 cifre (con eventuale prefisso IT) e la cifra di controllo corretta.');
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (submit.disabled) return;
    nascondiErrore();
    const dati = { privacy: 1, tipo: tipo() };
    if (tipo() === 'b2b') { dati.ragione_sociale = ragione.value.trim(); dati.partita_iva = piva.value.trim(); }
    try {
      const res = await conAttesa(submit, 'Creazione account…', () => apiPost('/auth/oauth/completa', dati));
      entra(res.dati.utente, res.dati.ritorno);
    } catch (err) {
      if (err.codice === 'EMAIL_GIA_USATA') { vaiAccedi(); return; }
      if (err.codice === 'NESSUN_ESITO' || err.codice === 'INVITO_NON_VALIDO') { vaiAccedi('stato_non_valido'); return; }
      if (err.campi?.partita_iva) { segnaCampo(piva, err.campi.partita_iva); return; }
      if (err.campi?.ragione_sociale) { segnaCampo(ragione, err.campi.ragione_sociale); return; }
      mostraErrore('Non è stato possibile creare l\'account. Riprova.');
    }
  });
}

/* ---------- Collega il tuo account ---------- */

function CollegaPage(esito) {
  mostraCard(`
    <h1 class="acc-title">Collega il tuo account</h1>
    <p class="acc-sub" id="collega-testo"></p>
    <div id="acc-error" class="acc-alert" role="alert" hidden></div>
    <form class="acc-form" id="collega-form" novalidate>
      ${campoPasswordHtml('col-password', 'Password BuyPool', 'current-password')}
      <button type="submit" class="acc-btn" id="col-submit">Collega e accedi</button>
    </form>
    <p class="acc-foot"><a href="index.html#/login">Annulla</a></p>
  `);

  const testo = document.getElementById('collega-testo');
  testo.append('Esiste già un account BuyPool con ');
  const mail = document.createElement('strong');
  mail.textContent = esito.email;
  testo.append(mail, `. ${esito.provider} non ci ha confermato questa email: per collegarlo inserisci la password di BuyPool.`);

  const pass = document.getElementById('col-password');
  const submit = document.getElementById('col-submit');
  collegaOcchio(document.getElementById('col-password-eye'), pass);

  document.getElementById('collega-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (submit.disabled) return;
    nascondiErrore();
    if (!pass.value) { segnaCampo(pass, 'Campo obbligatorio.'); pass.focus(); return; }
    segnaCampo(pass, '');
    try {
      const res = await conAttesa(submit, 'Collegamento…', () => apiPost('/auth/oauth/collega', { password: pass.value }));
      entra(res.dati.utente, res.dati.ritorno);
    } catch (err) {
      if (err.codice === 'PASSWORD_NON_CORRETTA') { mostraErrore('Password non corretta. Controlla e riprova.'); pass.focus(); return; }
      if (err.codice === 'NESSUN_ESITO' || err.codice === 'NON_COLLEGABILE') { vaiAccedi(err.codice === 'NESSUN_ESITO' ? 'stato_non_valido' : 'non_collegabile'); return; }
      mostraErrore('Non è stato possibile collegare l\'account. Riprova.');
    }
  });
}
