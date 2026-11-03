import { register, landingPerRuolo } from '../auth.js';
import { accessoConPannello } from '../components/accesso-layout.js';
import { mostraErrore, nascondiErrore, segnaCampo, collegaOcchio, oauthHtml, collegaOauth, campoHtml, campoPasswordHtml, aggiornaRequisiti, tipoAccountHtml, mostraErroreOauthDaHash } from '../components/accesso-comuni.js';
import { EMAIL_RE, passwordRequisiti, passwordValida, pivaNormalizza } from '../validazione.js';

export function RegisterPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = accessoConPannello(`
    <h1 class="acc-title">Crea il tuo account</h1>
    <p class="acc-sub">Registrarsi è gratis: paghi solo quando aderisci a un gruppo.</p>
    <div id="acc-error" class="acc-alert" role="alert" hidden></div>
    ${oauthHtml('Registrati')}
    <div class="acc-divider">oppure</div>
    <form class="acc-form" id="register-form" novalidate>
      <div class="acc-row2">
        ${campoHtml('reg-nome', 'Nome *', 'type="text" name="nome" autocomplete="given-name" maxlength="100"')}
        ${campoHtml('reg-cognome', 'Cognome *', 'type="text" name="cognome" autocomplete="family-name" maxlength="100"')}
      </div>
      ${campoHtml('reg-email', 'Email *', 'type="email" name="email" autocomplete="email" inputmode="email" autocapitalize="off" maxlength="190"')}
      ${campoPasswordHtml('reg-password', 'Password *', 'new-password', 'reg-requisiti')}

      ${tipoAccountHtml('reg')}

      <div class="acc-field">
        <label class="acc-check">
          <input type="checkbox" id="reg-privacy" name="privacy">
          <span>Ho letto l'<a href="#/privacy" target="_blank" rel="noopener">informativa privacy</a> e acconsento al trattamento dei dati.</span>
        </label>
        <div class="acc-field-error" id="reg-privacy-err" aria-live="polite"></div>
      </div>

      <button type="submit" class="acc-btn" id="reg-submit" disabled>Registrati</button>
    </form>
    <p class="acc-foot">Hai già un account? <a href="#/login">Accedi</a></p>
  `);

  const form = document.getElementById('register-form');
  const el = {
    nome: document.getElementById('reg-nome'),
    cognome: document.getElementById('reg-cognome'),
    email: document.getElementById('reg-email'),
    password: document.getElementById('reg-password'),
    ragione: document.getElementById('reg-ragione'),
    piva: document.getElementById('reg-piva'),
    privacy: document.getElementById('reg-privacy'),
    business: document.getElementById('reg-business'),
    submit: document.getElementById('reg-submit'),
  };
  const tipo = () => form.querySelector('input[name="tipo"]:checked').value;

  collegaOcchio(document.getElementById('reg-password-eye'), el.password);
  collegaOauth();
  mostraErroreOauthDaHash();

  /** Stato del modulo senza mostrare errori: abilita o disabilita "Registrati". */
  function completo() {
    const business = tipo() === 'b2b';
    return el.nome.value.trim() !== '' && el.cognome.value.trim() !== ''
      && EMAIL_RE.test(el.email.value.trim())
      && passwordValida(el.password.value)
      && (!business || (el.ragione.value.trim() !== '' && pivaNormalizza(el.piva.value) !== null))
      && el.privacy.checked;
  }
  function aggiornaPulsante() { el.submit.disabled = !completo(); }

  form.addEventListener('input', () => { aggiornaRequisiti('reg-requisiti', passwordRequisiti(el.password.value)); aggiornaPulsante(); });
  form.addEventListener('change', () => {
    el.business.hidden = tipo() !== 'b2b';
    aggiornaPulsante();
  });

  // Messaggi per campo quando si esce dal campo (non mentre si scrive).
  const controlli = [
    [el.nome, () => el.nome.value.trim() ? '' : 'Campo obbligatorio.'],
    [el.cognome, () => el.cognome.value.trim() ? '' : 'Campo obbligatorio.'],
    [el.email, () => { const v = el.email.value.trim(); return !v ? 'Campo obbligatorio.' : (EMAIL_RE.test(v) ? '' : 'Inserisci un indirizzo email valido.'); }],
    [el.password, () => passwordValida(el.password.value) ? '' : 'La password non rispetta i requisiti indicati sopra.'],
    [el.ragione, () => tipo() !== 'b2b' || el.ragione.value.trim() ? '' : 'Campo obbligatorio.'],
    [el.piva, () => { if (tipo() !== 'b2b') return ''; const v = el.piva.value.trim(); return !v ? 'Campo obbligatorio.' : (pivaNormalizza(v) ? '' : 'Partita IVA non valida: servono 11 cifre (con eventuale prefisso IT) e la cifra di controllo corretta.'); }],
  ];
  controlli.forEach(([input, f]) => input.addEventListener('blur', () => segnaCampo(input, f())));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (el.submit.disabled) return;
    nascondiErrore();

    // Rivalidazione completa: segna il primo campo errato.
    let primo = null;
    controlli.forEach(([input, f]) => { const m = f(); segnaCampo(input, m); if (m && !primo) primo = input; });
    if (!el.privacy.checked) { segnaCampo(el.privacy, 'Devi accettare l\'informativa privacy.'); primo = primo || el.privacy; }
    else segnaCampo(el.privacy, '');
    if (primo) { primo.focus(); return; }

    el.submit.disabled = true;
    el.submit.textContent = 'Creazione account…';
    const business = tipo() === 'b2b';
    try {
      const user = await register({
        nome: el.nome.value.trim(),
        cognome: el.cognome.value.trim(),
        email: el.email.value.trim(),
        password: el.password.value,
        tipo: tipo(),
        privacy: 1,
        ...(business ? { ragione_sociale: el.ragione.value.trim(), partita_iva: el.piva.value.trim() } : {}),
      });
      window.location.href = landingPerRuolo(user);
    } catch (err) {
      if (err.codice === 'EMAIL_GIA_USATA') {
        segnaCampo(el.email, err.message);
        el.email.focus();
      } else if (err.campi?.partita_iva) {
        segnaCampo(el.piva, err.campi.partita_iva);
        el.piva.focus();
      } else if (err.campi?.ragione_sociale) {
        segnaCampo(el.ragione, err.campi.ragione_sociale);
        el.ragione.focus();
      } else {
        mostraErrore(err.message || 'Non è stato possibile creare l\'account. Riprova.');
      }
      el.submit.textContent = 'Registrati';
      aggiornaPulsante();
    }
  });
}
