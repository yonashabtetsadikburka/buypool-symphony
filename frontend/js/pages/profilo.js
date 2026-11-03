import { getState, setState, saveSession } from '../state.js';
import { apiGet, apiPut, apiPost } from '../api.js';
import { Card } from '../components/card.js';

const EYE_OFF = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>';
const EYE_ON = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';

window.toggleProfiloPassword = function(inputId, iconId) {
  const input = document.getElementById(inputId);
  const icon = document.getElementById(iconId);
  if (!input || !icon) return;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  icon.innerHTML = show ? EYE_ON : EYE_OFF;
};

export async function ProfiloPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet('/profilo');
    const user = res.dati;
    if (!user) throw new Error('Profilo non trovato');

    const isFornitore = user.ruolo === 'fornitore';
    let forn = null;
    if (isFornitore) {
      try {
        const fres = await apiGet('/fornitore/io');
        forn = fres.dati;
      } catch (_) {}
    }

    content.innerHTML = `
      <div class="content-area">
        <div class="page-header"><h1>Profilo</h1></div>
        <div style="display:flex;flex-direction:column;gap:var(--space-6);max-width:800px;">
          ${Card({ children: `
            <div class="card-content">
              <h3 style="margin-bottom:var(--space-4);">Informazioni Personali</h3>
              <form id="profile-form" style="display:flex;flex-direction:column;gap:var(--space-4);">
                <div class="input-group"><label class="input-label">Nome</label><input type="text" name="nome" class="input" value="${user.nome || ''}"></div>
                <div class="input-group"><label class="input-label">Cognome</label><input type="text" name="cognome" class="input" value="${user.cognome || ''}"></div>
                <div class="input-group"><label class="input-label">Email</label><input type="email" class="input" value="${user.email || ''}" disabled></div>
                <div class="input-group"><label class="input-label">Telefono</label><input type="tel" name="telefono" class="input" value="${user.telefono || ''}"></div>
                <div class="input-group full-width"><label class="input-label">Indirizzo (via e civico)</label><input type="text" name="indirizzo" class="input" value="${user.indirizzo || ''}"></div>
                <div class="input-group"><label class="input-label">CAP</label><input type="text" name="cap" class="input" maxlength="10" value="${user.cap || ''}"></div>
                <div class="input-group"><label class="input-label">Citta</label><input type="text" name="citta" class="input" maxlength="100" value="${user.citta || ''}"></div>
                <div class="input-group"><label class="input-label">Provincia (sigla)</label><input type="text" name="provincia" class="input" maxlength="2" style="text-transform:uppercase;" value="${user.provincia || ''}"></div>
                <div class="input-group"><label class="input-label">Codice Fiscale</label><input type="text" name="codice_fiscale" class="input" maxlength="16" style="text-transform:uppercase;" value="${user.codice_fiscale || ''}"></div>
                <div class="input-group full-width"><label class="input-label">Partita IVA (opzionale)</label><input type="text" name="partita_iva" class="input" maxlength="11" inputmode="numeric" value="${user.partita_iva || ''}"></div>
                ${isFornitore && forn ? `
                <div class="input-group full-width"><label class="input-label">Sito web / catalogo</label><input type="url" name="sito_web" class="input" placeholder="https://..." value="${forn.sito_web || ''}"></div>
                <div class="input-group full-width"><label class="input-label">Descrizione azienda</label><textarea name="descrizione" class="input" rows="2">${forn.descrizione || ''}</textarea></div>
                <div class="text-xs text-secondary full-width">Ragione sociale, P.IVA e categoria sono gestiti dall'admin.</div>` : ''}
                <div class="full-width" style="display:flex;justify-content:flex-end;"><button type="submit" class="btn btn-default">Salva modifiche</button></div>
              </form>
            </div>` })}
          ${Card({ children: `
            <div class="card-content">
              <h3 style="margin-bottom:var(--space-4);">Sicurezza</h3>
              <form id="password-form" style="display:flex;flex-direction:column;gap:var(--space-4);">
                <div class="input-group"><label class="input-label">Password attuale</label>
                  <div class="input-icon-wrapper">
                    <input type="password" name="vecchia_password" id="vecchia-password-input" class="input" placeholder="••••••••" required>
                    <button type="button" class="input-icon-btn" onclick="toggleProfiloPassword('vecchia-password-input', 'vecchia-password-icon')">
                      <span id="vecchia-password-icon">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                      </span>
                    </button>
                  </div>
                </div>
                <div class="input-group"><label class="input-label">Nuova password</label>
                  <div class="input-icon-wrapper">
                    <input type="password" name="nuova_password" id="nuova-password-input" class="input" placeholder="••••••••" required minlength="8">
                    <button type="button" class="input-icon-btn" onclick="toggleProfiloPassword('nuova-password-input', 'nuova-password-icon')">
                      <span id="nuova-password-icon">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                      </span>
                    </button>
                  </div>
                </div>
                <div id="password-msg" style="font-size:var(--text-sm);display:none;margin-bottom:var(--space-3);"></div>
                <div style="display:flex;justify-content:flex-end;"><button type="submit" class="btn btn-outline">Cambia password</button></div>
              </form>
            </div>` })}
          ${Card({ children: `
            <div class="card-content">
              <h3 style="margin-bottom:var(--space-4);">I tuoi dati</h3>
              <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">Scarica una copia di tutti i tuoi dati o richiedi la cancellazione dell'account. Vedi anche l'<a href="#/privacy">informativa privacy</a>.</p>
              <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
                <button id="scarica-dati-btn" class="btn btn-outline">Scarica i miei dati</button>
                <button id="cancella-account-btn" class="btn btn-destructive">Richiedi cancellazione</button>
              </div>
            </div>` })}
        </div>
      </div>`;

    document.getElementById('profile-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      try {
        await apiPut('/profilo', {
          nome: form.nome.value.trim(),
          cognome: form.cognome.value.trim(),
          telefono: form.telefono.value.trim(),
          indirizzo: form.indirizzo.value.trim(),
          cap: form.cap.value.trim(),
          citta: form.citta.value.trim(),
          provincia: form.provincia.value.trim(),
          codice_fiscale: form.codice_fiscale.value.trim(),
          partita_iva: form.partita_iva.value.trim()
        });
        if (form.sito_web || form.descrizione) {
          await apiPut('/fornitore/io', {
            sito_web: form.sito_web ? form.sito_web.value.trim() || null : undefined,
            descrizione: form.descrizione ? form.descrizione.value.trim() || null : undefined
          });
        }
        const updatedUser = { ...getState().user, nome: form.nome.value.trim(), cognome: form.cognome.value.trim() };
        setState({ user: updatedUser });
        saveSession(updatedUser, getState().token);
        const toast = document.createElement('div');
        toast.className = 'toast toast-success';
        toast.innerHTML = '<div class="toast-content"><div class="toast-title">Profilo aggiornato!</div></div>';
        document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
      } catch (err) { alert(err.message); }
    });

    document.getElementById('password-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      const msgEl = document.getElementById('password-msg');
      try {
        await apiPost('/profilo/password', {
          vecchia_password: form.vecchia_password.value,
          nuova_password: form.nuova_password.value
        });
        msgEl.textContent = 'Password aggiornata!'; msgEl.style.color = 'var(--color-success)'; msgEl.style.display = 'block';
        form.reset();
        setTimeout(() => { msgEl.style.display = 'none'; }, 3000);
      } catch (err) {
        msgEl.textContent = err.message; msgEl.style.color = 'var(--color-error)'; msgEl.style.display = 'block';
      }
    });

    document.getElementById('scarica-dati-btn')?.addEventListener('click', async () => {
      try {
        const res = await apiGet('/profilo/export');
        const blob = new Blob([JSON.stringify(res.dati, null, 2)], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'buypool-miei-dati.json';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 5000);
      } catch (err) { alert(err.message); }
    });

    document.getElementById('cancella-account-btn')?.addEventListener('click', async () => {
      if (!confirm('Richiedere la cancellazione del tuo account? L\'amministratore verra\' avvisato e procedera\' alla cancellazione.')) return;
      try {
        await apiPost('/profilo/cancellazione', {});
        const toast = document.createElement('div');
        toast.className = 'toast toast-success';
        toast.innerHTML = '<div class="toast-content"><div class="toast-title">Richiesta inviata all\'amministratore.</div></div>';
        document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
      } catch (err) { alert(err.message); }
    });
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
