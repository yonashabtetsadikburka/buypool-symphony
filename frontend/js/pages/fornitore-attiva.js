import { apiGet, apiPost } from '../api.js';
import { saveSession } from '../state.js';
import { accessoSolo } from '../components/accesso-layout.js';
import { mostraErrore, nascondiErrore, segnaCampo, collegaOcchio, campoHtml, campoPasswordHtml, aggiornaRequisiti, conAttesa } from '../components/accesso-comuni.js';
import { passwordRequisiti, passwordValida } from '../validazione.js';

function radice() {
  return document.getElementById('content-area') || document.querySelector('.main-content');
}

function mostraCard(html) {
  const content = radice();
  if (!content) return null;
  content.innerHTML = accessoSolo(html);
  return content;
}

function InvitoNonValidoPage(messaggio) {
  mostraCard(`
    <div class="acc-center">
      <h1 class="acc-title">L'invito non è più valido</h1>
      <p class="acc-sub" id="invito-msg"></p>
    </div>
    <a class="acc-btn acc-btn-link" href="index.html#/login">Vai ad Accedi</a>
  `);
  document.getElementById('invito-msg').textContent = messaggio;
}

export async function FornitoreAttivaPage() {
  if (!radice()) return;

  // Token dal query string (fornitore-attiva.html) o dall'hash (index.html#/fornitore/attiva)
  const token = new URLSearchParams(window.location.search).get('token')
    || new URLSearchParams((window.location.hash.split('?')[1] || '').split('#')[0]).get('token')
    || '';

  if (!token) {
    InvitoNonValidoPage('Il link non è completo. Apri di nuovo il link che hai ricevuto per email, oppure chiedi un nuovo invito a BuyPool.');
    return;
  }

  mostraCard('<p class="acc-sub" role="status">Verifica dell\'invito…</p>');
  let invito;
  try {
    invito = (await apiGet(`/fornitori/invito/${encodeURIComponent(token)}`)).dati;
  } catch (err) {
    InvitoNonValidoPage('Il link è scaduto oppure è già stato usato. Chiedi un nuovo invito a BuyPool.');
    return;
  }

  mostraCard(`
    <span class="acc-badge">INVITO FORNITORE</span>
    <h1 class="acc-title">Imposta la password</h1>
    <p class="acc-sub" id="invito-testo"></p>
    <div id="acc-error" class="acc-alert" role="alert" hidden></div>
    <form class="acc-form" id="attiva-form" novalidate>
      ${campoHtml('att-email', 'Email', 'type="email" name="email" autocomplete="username" readonly')}
      ${campoPasswordHtml('att-password', 'Nuova password', 'new-password', 'att-requisiti')}
      ${campoPasswordHtml('att-password2', 'Ripeti la password', 'new-password')}
      <div class="acc-field">
        <label class="acc-check">
          <input type="checkbox" id="att-privacy">
          <span>Ho letto l'<a href="index.html#/privacy" target="_blank" rel="noopener">informativa privacy</a>.</span>
        </label>
        <div class="acc-field-error" id="att-privacy-err" aria-live="polite"></div>
      </div>
      <button type="submit" class="acc-btn" id="att-submit">Attiva l'account</button>
    </form>
    <p class="acc-note">Il link dell'invito vale 7 giorni. Se è scaduto, chiedi un nuovo invito a BuyPool.</p>
  `);

  const testo = document.getElementById('invito-testo');
  testo.append('BuyPool ti ha invitato come fornitore per ');
  const azienda = document.createElement('strong');
  azienda.textContent = invito.nome_azienda;
  testo.append(azienda, '. Scegli una password per entrare nell\'area fornitore.');
  document.getElementById('att-email').value = invito.email;

  const p1 = document.getElementById('att-password');
  const p2 = document.getElementById('att-password2');
  const privacy = document.getElementById('att-privacy');
  const submit = document.getElementById('att-submit');
  collegaOcchio(document.getElementById('att-password-eye'), p1);
  collegaOcchio(document.getElementById('att-password2-eye'), p2);
  p1.addEventListener('input', () => aggiornaRequisiti('att-requisiti', passwordRequisiti(p1.value)));

  document.getElementById('attiva-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (submit.disabled) return;
    nascondiErrore();
    const ko1 = passwordValida(p1.value) ? '' : 'La password non rispetta i requisiti indicati sopra.';
    const ko2 = !p2.value ? 'Campo obbligatorio.' : (p1.value === p2.value ? '' : 'Le due password non coincidono.');
    const ko3 = privacy.checked ? '' : 'Devi accettare l\'informativa privacy.';
    segnaCampo(p1, ko1);
    segnaCampo(p2, ko2);
    segnaCampo(privacy, ko3);
    const primo = (ko1 && p1) || (ko2 && p2) || (ko3 && privacy);
    if (primo) { primo.focus(); return; }

    try {
      const res = await conAttesa(submit, 'Attivazione…', () => apiPost('/fornitori/attiva', { token, password: p1.value, privacy: 1 }));
      saveSession(res.dati, 'session_' + res.dati.id);
      window.location.href = 'app.html#/fornitore';
    } catch (err) {
      if (err.codice === 'INVITO_NON_VALIDO') {
        InvitoNonValidoPage('Il link è scaduto oppure è già stato usato. Chiedi un nuovo invito a BuyPool.');
      } else if (err.codice === 'EMAIL_ESISTENTE') {
        mostraErrore(err.message);
      } else {
        mostraErrore('Non è stato possibile attivare l\'account. Riprova.');
      }
    }
  });
}
