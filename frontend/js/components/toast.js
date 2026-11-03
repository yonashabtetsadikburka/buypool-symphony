let toastContainer = null;

function ensureContainer() {
  if (!toastContainer) {
    toastContainer = document.createElement('div');
    toastContainer.className = 'toast-container';
    document.body.appendChild(toastContainer);
  }
  return toastContainer;
}

export function showToast({ title = '', description = '', variant = 'default', duration = 5000 }) {
  const container = ensureContainer();

  const toast = document.createElement('div');
  toast.className = `toast toast-${variant}`;

  let closeIcon = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';

  toast.innerHTML = `
    <div class="toast-content">
      ${title ? `<div class="toast-title">${title}</div>` : ''}
      ${description ? `<div class="toast-description">${description}</div>` : ''}
    </div>
    <button class="toast-close" onclick="this.parentElement.remove()">${closeIcon}</button>
  `;

  container.appendChild(toast);

  if (duration > 0) {
    setTimeout(() => {
      if (toast.parentElement) {
        toast.remove();
      }
    }, duration);
  }

  return toast;
}

export function ToastProvider() {
  ensureContainer();
  return '';
}
