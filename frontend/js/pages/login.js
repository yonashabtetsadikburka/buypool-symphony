import { login, landingPerRuolo } from '../auth.js';
import { accessoConPannello } from '../components/accesso-layout.js';
import { ICONA_OCCHIO, mostraErrore, nascondiErrore, segnaCampo, collegaOcchio, oauthHtml, collegaOauth, mostraErroreOauthDaHash, leggiEToglieParametroDaHash } from '../components/accesso-comuni.js';
import { EMAIL_RE } from '../validazione.js';

const MSG_CREDENZIALI = 'Email o password non corrette. Controlla i dati e riprova.';

function messaggioDaHash() {
  return leggiEToglieParametroDaHash('msg') === 'password-aggiornata' ? 'Password aggiornata. Ora puoi accedere.' : '';
}

export function LoginPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const msgOk = messaggioDaHash();

  content.innerHTML = accessoConPannello(`
    <h1 class="acc-title">Accedi</h1>
    <p class="acc-sub">Bentornato su BuyPool.</p>
    ${msgOk ? `<div class="acc-ok" role="status">${msgOk}</div>` : ''}
    <div id="acc-error" class="acc-alert" role="alert" hidden></div>
    <form class="acc-form" id="login-form" novalidate>
      <div class="acc-field">
        <label class="acc-label" for="login-email">Email</label>
        <input class="acc-input" id="login-email" type="email" name="email" autocomplete="email" inputmode="email" placeholder="nome@email.it" autocapitalize="off">
        <div class="acc-field-error" id="login-email-err" aria-live="polite"></div>
      </div>
      <div class="acc-field">
        <label class="acc-label" for="login-password">Password</label>
        <div class="acc-pw">
          <input class="acc-input" id="login-password" type="password" name="password" autocomplete="current-password">
          <button type="button" class="acc-eye" id="login-eye" aria-label="Mostra password" aria-pressed="false">${ICONA_OCCHIO}</button>
        </div>
        <div class="acc-field-error" id="login-password-err" aria-live="polite"></div>
      </div>
      <a class="acc-forgot" href="#/password/dimenticata">Password dimenticata?</a>
      <button type="submit" class="acc-btn" id="login-submit">Accedi</button>
    </form>
    <div class="acc-divider">oppure</div>
    ${oauthHtml('Accedi')}
    <p class="acc-foot">Non hai un account? <a href="#/register">Registrati</a></p>
  `);

  const form = document.getElementById('login-form');
  const emailEl = document.getElementById('login-email');
  const passEl = document.getElementById('login-password');
  const submit = document.getElementById('login-submit');
  const eye = document.getElementById('login-eye');

  collegaOcchio(eye, passEl);
  collegaOauth();
  mostraErroreOauthDaHash();

  function valida() {
    const email = emailEl.value.trim();
    const mailKo = !email ? 'Campo obbligatorio.' : (!EMAIL_RE.test(email) ? 'Inserisci un indirizzo email valido.' : '');
    const passKo = passEl.value ? '' : 'Campo obbligatorio.';
    segnaCampo(emailEl, mailKo);
    segnaCampo(passEl, passKo);
    if (mailKo) { emailEl.focus(); return false; }
    if (passKo) { passEl.focus(); return false; }
    return true;
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (submit.disabled) return;
    nascondiErrore();
    if (!valida()) return;

    submit.disabled = true;
    submit.textContent = 'Accesso in corso…';
    try {
      const user = await login(emailEl.value.trim(), passEl.value);
      window.location.href = landingPerRuolo(user);
    } catch (err) {
      // Stesso testo per email inesistente e password sbagliata.
      const sospeso = err.codice === 'UTENTE_SOSPESO';
      const credenziali = err.codice === 'CREDENZIALI_NON_VALIDE';
      mostraErrore(credenziali ? MSG_CREDENZIALI : (sospeso ? err.message : 'Non è stato possibile accedere. Riprova tra poco.'));
      if (credenziali) {
        emailEl.setAttribute('aria-invalid', 'true');
        passEl.setAttribute('aria-invalid', 'true');
      }
      submit.disabled = false;
      submit.textContent = 'Accedi';
    }
  });
}
