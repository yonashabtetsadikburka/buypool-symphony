import { getState } from '../state.js';
import { logout, isAdmin, isFornitore } from '../auth.js';
import { apiGet } from '../api.js';
import { Avatar } from './dropdown.js';
import { esc } from '../utils.js';

const NAV_ITEMS = [
  { route: '/', label: 'Gruppi d\'acquisto', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>' },
  { route: '/proposte', label: 'Proposte', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>' },
  { route: '/dashboard', label: 'Dashboard', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>' },
  { route: '/acquisti', label: 'Acquisti', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>' },
  { route: '/notifiche', label: 'Notifiche', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>' }
];

const ADMIN_ITEMS = [
  { route: '/admin', label: 'Dashboard', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>' },
  { route: '/admin/campagne', label: 'Gruppi', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>' },
  { route: '/admin/proposte', label: 'Proposte', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>' },
  { route: '/admin/ordini', label: 'Ordini e ritiri', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>' },
  { route: '/admin/utenti', label: 'Utenti', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>' },
  { route: '/admin/fornitori', label: 'Fornitori', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>' },
  { route: '/admin/messaggi', label: 'Messaggi', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>' }
];

const FORNITORE_ITEMS = [
  { route: '/fornitore', label: 'Panoramica', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>' },
  { route: '/fornitore/gruppi', label: 'Gruppi', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>' },
  { route: '/fornitore/proposte', label: 'Proposte', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>' },
  { route: '/fornitore/ordini', label: 'Ordini', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>' },
  { route: '/fornitore/pagamenti', label: 'Pagamenti', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>' },
  { route: '/fornitore/azienda', label: 'Azienda', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>' },
];

function fornItemActive(hash, route) {
  hash = String(hash).split('?')[0];
  if (hash === route) return true;
  if (route === '/fornitore') return false;
  return hash.startsWith(route + '/');
}

function notifBadge(route) {
  return (route === '/notifiche')
    ? '<span class="notification-badge nav-badge" style="display:none;"></span>' : '';
}

const LENTE_SVG = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>';

/** Barra di ricerca nell'header (condivisa: loggati e ospiti). */
function headerSearchHtml() {
  return `
    <div class="header-search" id="header-search" role="search">
      <button type="button" class="header-search-toggle" onclick="toggleHeaderSearch()"
              aria-label="Cerca" aria-expanded="false">
        ${LENTE_SVG}
      </button>
      <div class="header-search-field">
        ${LENTE_SVG}
        <input type="search" id="header-search-input" class="header-search-input"
               autocomplete="off" placeholder="Cerca un prodotto o un fornitore"
               aria-label="Cerca" oninput="headerSearchVivo&&headerSearchVivo()"
               onkeydown="if(event.key==='Enter')headerSearchInvia()" />
        <button type="button" class="header-search-close" onclick="toggleHeaderSearch()" aria-label="Chiudi ricerca">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>`;
}

/** Invio nella ricerca: porta/aggiorna la pagina elenco con il parametro q. */
window.headerSearchInvia = function() {
  const val = (document.getElementById('header-search-input')?.value || '').trim();
  const hash = window.location.hash.slice(1) || '/';
  const path = hash.split('?')[0];
  const params = new URLSearchParams(hash.split('?')[1] || '');
  if (val) params.set('q', val); else params.delete('q');
  const qs = params.toString();
  const target = '#/' + (qs ? '?' + qs : '');
  if (path === '/' || path === '') {
    if (window.location.hash === target) window.dispatchEvent(new HashChangeEvent('hashchange'));
    else window.location.hash = target;
    document.getElementById('header-search')?.classList.remove('is-open');
  } else {
    window.location.hash = target;
  }
};

/** Apre/chiude il campo ricerca (solo sotto 1200px diventa icona + overlay). */
window.toggleHeaderSearch = function() {
  const box = document.getElementById('header-search');
  if (!box) return;
  const open = box.classList.toggle('is-open');
  const toggle = box.querySelector('.header-search-toggle');
  if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  if (open) document.getElementById('header-search-input')?.focus();
};

function renderFornitoreNav(hash) {
  return FORNITORE_ITEMS.map(item => {
    const isActive = fornItemActive(hash, item.route);
    return `<a class="nav-item ${isActive ? 'active' : ''}" href="#${item.route}">${item.icon}<span>${item.label}</span>${notifBadge(item.route)}</a>`;
  }).join('');
}

function renderNavItems(hash) {
  return NAV_ITEMS.map(item => {
    const isActive = hash === item.route || (item.route !== '/' && hash.startsWith(item.route));
    return `<a class="nav-item ${isActive ? 'active' : ''}" href="#${item.route}">${item.icon}<span>${item.label}</span>${notifBadge(item.route)}</a>`;
  }).join('');
}

function renderAdminNav(hash) {
  const adminIcon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>';
  return ADMIN_ITEMS.map(item => {
    const isActive = hash === item.route || (item.route !== '/admin' && hash.startsWith(item.route));
    return `<a class="nav-item ${isActive ? 'active' : ''}" href="#${item.route}">${adminIcon}<span>${item.label}</span>${notifBadge(item.route)}</a>`;
  }).join('');
}

function renderMobileNavItems(hash) {
  let html = '<div class="mobile-menu-nav">';

  if (isAdmin()) {
    html += '<div class="mobile-menu-section">Admin</div>';
    ADMIN_ITEMS.forEach(item => {
      const isActive = hash === item.route || (item.route !== '/admin' && hash.startsWith(item.route));
      html += `<a class="mobile-menu-item ${isActive ? 'active' : ''}" href="#${item.route}" onclick="closeMobileMenu()"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg><span>${item.label}</span>${notifBadge(item.route)}</a>`;
    });
  } else if (!isFornitore()) {

  html += '<div class="mobile-menu-section">Menu</div>';
  NAV_ITEMS.forEach(item => {
    const isActive = hash === item.route || (item.route !== '/' && hash.startsWith(item.route));
    html += `<a class="mobile-menu-item ${isActive ? 'active' : ''}" href="#${item.route}" onclick="closeMobileMenu()">${item.icon}<span>${item.label}</span>${notifBadge(item.route)}</a>`;
  });

  } else {
    html += '<div class="mobile-menu-section">Menu</div>';
    FORNITORE_ITEMS.forEach(item => {
      const isActive = fornItemActive(hash, item.route);
      html += `<a class="mobile-menu-item ${isActive ? 'active' : ''}" href="#${item.route}" onclick="closeMobileMenu()">${item.icon}<span>${item.label}</span>${notifBadge(item.route)}</a>`;
    });
  }

  html += '</div>';
  return html;
}

export function Header() {
  const { user } = getState();
  const initials = user ? `${user.nome[0]}${user.cognome[0] || ''}` : '';
  const hash = window.location.hash.slice(1) || '/';
  const userIsAdmin = isAdmin();
  const userIsFornitore = !userIsAdmin && isFornitore();

  const desktopNav = userIsAdmin
    ? renderAdminNav(hash)
    : (userIsFornitore ? renderFornitoreNav(hash) : renderNavItems(hash));

  const userMenuId = 'user-menu';
  const userMenuItems = [
    { id: 'profilo', label: 'Profilo', icon: '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>', onClick: "window.location.hash='#/profilo'" },
    { id: 'privacy', label: 'Privacy', icon: '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>', onClick: "window.location.hash='#/privacy'" },
    { separator: true },    { id: 'logout', label: 'Esci', icon: '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>', onClick: "handleLogout()" }
  ];

  const userMenuItemsHtml = userMenuItems.map(item => {
    if (item.separator) {
      return '<div class="dropdown-separator"></div>';
    }
    return `<button class="dropdown-item" onclick="${item.onClick}">${item.icon}<span>${item.label}</span></button>`;
  }).join('');

  return `
    <header class="header">
      <div class="header-left">
        <button class="hamburger-btn" onclick="openMobileMenu()">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <a href="#/" class="header-logo header-logo-desktop"><img src="img/logo_buypool.webp" alt="BuyPool"></a>
        ${(userIsAdmin || userIsFornitore) ? '' : headerSearchHtml()}
      </div>
      <div class="header-center">
        <a href="#/" class="header-logo header-logo-mobile"><img src="img/logo_buypool.webp" alt="BuyPool"></a>
        <nav class="header-nav${(userIsAdmin || userIsFornitore) ? ' header-nav-admin' : ''}">
          ${desktopNav}
        </nav>
      </div>
      <div class="header-right">
        ${(userIsAdmin || userIsFornitore) ? `
        <a href="${userIsAdmin ? '#/admin' : '#/fornitore'}" class="notification-btn" id="adm-campanella" aria-label="Niente da fare" title="Niente da fare">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
          <span class="notification-badge" style="display:none;"></span>
        </a>` : ''}
        <div class="dropdown" id="${userMenuId}">
          <button type="button" class="user-menu-trigger" data-dropdown-toggle="${userMenuId}" aria-haspopup="menu" aria-expanded="false" aria-label="Menu utente: ${esc(`${user?.nome || ''} ${user?.cognome || ''}`.trim())}">
            ${Avatar({ fallback: initials, size: 'sm' })}
            <span class="user-name">${esc(user?.nome || '')} ${esc(user?.cognome || '')}</span>
            <svg class="user-chevron" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.4" d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="dropdown-menu">${userMenuItemsHtml}</div>
        </div>
      </div>
    </header>
    <div class="mobile-menu-overlay" id="mobile-menu-overlay" onclick="closeMobileMenu()"></div>
    <div class="mobile-menu" id="mobile-menu">
      <div class="mobile-menu-header">
        <a href="#/" onclick="closeMobileMenu()" aria-label="BuyPool, Gruppi d'acquisto"><img src="img/logo_buypool.webp" alt="BuyPool" class="mobile-menu-logo"></a>
        <button class="mobile-menu-close" onclick="closeMobileMenu()">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      ${renderMobileNavItems(hash)}
    </div>
  `;
}

export function handleLogout() {
  logout();
  window.location.href = 'index.html';
}

/** Salva la pagina corrente per tornarci dopo login/registrazione. */
function salvaRitorno() {
  try {
    const rawHash = window.location.hash.slice(1) || '/';
    sessionStorage.setItem('postLoginHash', rawHash);
  } catch (e) {}
}

window.guestAccedi = function() {
  salvaRitorno();
  window.location.href = 'index.html#/login';
};

window.guestRegistrati = function() {
  salvaRitorno();
  window.location.href = 'index.html#/register';
};

const GUEST_ICON = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>';

/** Header minimale per ospiti: voce Gruppi d'acquisto + ricerca + Accedi/Registrati. */
export function GuestHeader() {
  const hash = window.location.hash.slice(1) || '/';
  const isActive = hash === '/' || hash.startsWith('/campagne');
  return `
    <header class="header">
      <div class="header-left">
        <a href="#/" class="header-logo header-logo-desktop"><img src="img/logo_buypool.webp" alt="BuyPool"></a>
        ${headerSearchHtml()}
      </div>
      <div class="header-center">
        <a href="#/" class="header-logo header-logo-mobile"><img src="img/logo_buypool.webp" alt="BuyPool"></a>
        <nav class="header-nav">
          <a class="nav-item ${isActive ? 'active' : ''}" href="#/">${GUEST_ICON}<span>Gruppi d'acquisto</span></a>
        </nav>
      </div>
      <div class="header-right">
        <button class="btn btn-ghost btn-sm" onclick="guestAccedi()">Accedi</button>
        <button class="btn btn-default btn-sm" onclick="guestRegistrati()">Registrati</button>
      </div>
    </header>
  `;
}

window.handleLogout = handleLogout;

window.openMobileMenu = function() {
  document.getElementById('mobile-menu').classList.add('open');
  document.getElementById('mobile-menu-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
};

window.closeMobileMenu = function() {
  document.getElementById('mobile-menu').classList.remove('open');
  document.getElementById('mobile-menu-overlay').classList.remove('open');
  document.body.style.overflow = '';
};

/** Campanella dell'admin: numero di cose da fare (stessa fonte della dashboard), non un feed di notifiche. */
export function impostaDaFare(totale) {
  daFareCache = { totale, quando: Date.now() };
  const n = Number(totale) || 0;
  document.querySelectorAll('.header-right .notification-badge').forEach(badge => {
    badge.textContent = n > 99 ? '99+' : n;
    badge.style.display = n > 0 ? '' : 'none';
  });
  const c = document.getElementById('adm-campanella');
  if (c) {
    const testo = n > 0 ? `Cose da fare: ${n}` : 'Niente da fare';
    c.setAttribute('aria-label', testo);
    c.setAttribute('title', testo);
  }
}
let daFareCache = null;

export async function updateNotificationBadge() {
  try {
    if (isAdmin() || isFornitore()) {
      // Admin e fornitore: la campanella conta le cose da fare (stessa funzione della loro Panoramica), non un elenco di notifiche
      if (daFareCache && Date.now() - daFareCache.quando < 30000) { impostaDaFare(daFareCache.totale); return; }
      const r = await apiGet(isAdmin() ? '/admin/da-fare' : '/fornitore/da-fare');
      impostaDaFare(r.dati?.totale || 0);
      return;
    }
    const res = await apiGet('/notifiche/conta');
    const count = res.dati?.non_lette || 0;
    document.querySelectorAll('.nav-badge, .header-right .notification-badge').forEach(badge => {
      if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = '';
      } else {
        badge.style.display = 'none';
      }
    });
  } catch (e) { /* ignore */ }
}
