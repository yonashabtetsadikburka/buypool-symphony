import { apiPost, apiGet } from '../api.js';
import { clearSession } from '../state.js';
import { accessoSolo } from '../components/accesso-layout.js';
import { mostraErrore, nascondiErrore, segnaCampo, collegaOcchio, campoHtml, campoPasswordHtml, aggiornaRequisiti, conAttesa } from '../components/accesso-comuni.js';
import { EMAIL_RE, passwordRequisiti, passwordValida } from '../validazione.js';

const ICONA_MAIL = '<svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>';

function radice() {
  return document.getElementById('content-area') || document.querySelector('.main-content');
}

/** Contenuto della card: il testo dell'utente va sempre assegnato con textContent, mai dentro l'HTML. */
function mostraCard(html) {
  const content = radice();
  if (!content) return null;
  content.innerHTML = accessoSolo(html);
  return content;
}

/* ---------- 1. Password dimenticata ---------- */

export function PasswordDimenticataPage() {
  if (!mostraCard(`
    <h1 class="acc-title">Password dimenticata?</h1>
    <p class="acc-sub">Inserisci l'email dell'account: ti mandiamo un link per scegliere una nuova password.</p>
    <div id="acc-error" class="acc-alert" role="alert" hidden></div>
    <form class="acc-form" id="richiesta-form" novalidate>
      ${campoHtml('rich-email', 'Email', 'type="email" name="email" autocomplete="email" inputmode="email" autocapitalize="off"')}
      <button type="submit" class="acc-btn" id="rich-submit">Invia il link</button>
    </form>
    <p class="acc-foot"><a href="#/login">&larr; Torna ad Accedi</a></p>
  `)) return;

  const emailEl = document.getElementById('rich-email');
  const submit = document.getElementById('rich-submit');

  document.getElementById('richiesta-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (submit.disabled) return;
    nascondiErrore();
    const email = emailEl.value.trim();
    const ko = !email ? 'Campo obbligatorio.' : (EMAIL_RE.test(email) ? '' : 'Inserisci un indirizzo email valido.');
    segnaCampo(emailEl, ko);
    if (ko) { emailEl.focus(); return; }

    try {
      await conAttesa(submit, 'Invio in corso…', () => apiPost('/password/richiesta', { email }));
      EmailInviataPage(email);
    } catch (err) {
      mostraErrore('Non è stato possibile inviare la richiesta. Riprova tra poco.');
    }
  });
}

/* ---------- 2. Email inviata (stessa pagina sia che l'account esista o no) ---------- */

function EmailInviataPage(email) {
  if (!mostraCard(`
    <div class="acc-center">
      <div class="acc-mail-ico">${ICONA_MAIL}</div>
      <h1 class="acc-title">Controlla la tua email</h1>
      <p class="acc-sub" id="inviata-testo"></p>
    </div>
    <div id="inviata-esito" class="acc-ok" role="status" hidden></div>
    <div id="acc-error" class="acc-alert" role="alert" hidden></div>
    <div class="acc-stack">
      <button type="button" class="acc-btn-sec" id="inviata-di-nuovo">Invia di nuovo</button>
      <a class="acc-btn acc-btn-link" href="#/login">Torna ad Accedi</a>
    </div>
    <p class="acc-note acc-center">Non la trovi? Controlla anche la cartella spam o posta indesiderata.</p>
  `)) return;

  const testo = document.getElementById('inviata-testo');
  testo.append('Se esiste un account con ');
  const strong = document.createElement('strong');
  strong.textContent = email;
  testo.append(strong, ', troverai un link per reimpostare la password. Vale 60 minuti.');

  const btn = document.getElementById('inviata-di-nuovo');
  btn.addEventListener('click', async () => {
    if (btn.disabled) return;
    nascondiErrore();
    try {
      await conAttesa(btn, 'Invio in corso…', () => apiPost('/password/richiesta', { email }));
      const esito = document.getElementById('inviata-esito');
      esito.textContent = 'Richiesta inviata di nuovo.';
      esito.hidden = false;
    } catch (err) {
      mostraErrore('Non è stato possibile inviare la richiesta. Riprova tra poco.');
    }
  });
}

/* ---------- 3. Link non valido ---------- */

function LinkNonValidoPage() {
  mostraCard(`
    <div class="acc-center">
      <h1 class="acc-title">Il link non è più valido</h1>
      <p class="acc-sub">È scaduto oppure è già stato usato. Puoi chiederne uno nuovo.</p>
    </div>
    <div class="acc-stack">
      <a class="acc-btn acc-btn-link" href="#/password/dimenticata">Chiedi un nuovo link</a>
      <a class="acc-btn-sec acc-btn-link" href="#/login">Torna ad Accedi</a>
    </div>
  `);
}

/* ---------- 4. Scegli una nuova password ---------- */

export async function PasswordResetPage() {
  const token = new URLSearchParams((window.location.hash.split('?')[1] || '').split('#')[0]).get('token') || '';
  if (!token) { LinkNonValidoPage(); return; }

  mostraCard('<p class="acc-sub" role="status">Verifica del link…</p>');
  try {
    await apiGet(`/password/reset/${encodeURIComponent(token)}`);
  } catch (err) {
    LinkNonValidoPage();
    return;
  }

  if (!mostraCard(`
    <h1 class="acc-title">Scegli una nuova password</h1>
    <p class="acc-sub">Sceglila diversa da quelle che usi altrove.</p>
    <div id="acc-error" class="acc-alert" role="alert" hidden></div>
    <form class="acc-form" id="reset-form" novalidate>
      ${campoPasswordHtml('reset-password', 'Nuova password', 'new-password', 'reset-requisiti')}
      ${campoPasswordHtml('reset-password2', 'Ripeti la password', 'new-password')}
      <button type="submit" class="acc-btn" id="reset-submit">Salva la password</button>
    </form>
  `)) return;

  const p1 = document.getElementById('reset-password');
  const p2 = document.getElementById('reset-password2');
  const submit = document.getElementById('reset-submit');
  collegaOcchio(document.getElementById('reset-password-eye'), p1);
  collegaOcchio(document.getElementById('reset-password2-eye'), p2);
  p1.addEventListener('input', () => aggiornaRequisiti('reset-requisiti', passwordRequisiti(p1.value)));

  document.getElementById('reset-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (submit.disabled) return;
    nascondiErrore();
    const ko1 = passwordValida(p1.value) ? '' : 'La password non rispetta i requisiti indicati sopra.';
    const ko2 = !p2.value ? 'Campo obbligatorio.' : (p1.value === p2.value ? '' : 'Le due password non coincidono.');
    segnaCampo(p1, ko1);
    segnaCampo(p2, ko2);
    if (ko1) { p1.focus(); return; }
    if (ko2) { p2.focus(); return; }

    try {
      await conAttesa(submit, 'Salvataggio…', () => apiPost('/password/reset', { token, password: p1.value }));
      clearSession();   // le altre sessioni sono state chiuse dal server
      window.location.hash = '#/login?msg=password-aggiornata';
    } catch (err) {
      if (err.codice === 'LINK_NON_VALIDO') { LinkNonValidoPage(); return; }
      mostraErrore(err.codice === 'PASSWORD_DEBOLE' ? err.message : 'Non è stato possibile salvare la password. Riprova.');
    }
  });
}
