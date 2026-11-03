export function Avatar({ src = '', fallback = '', size = '' }) {
  const sizeClass = size ? `avatar-${size}` : '';
  if (src) {
    return `<div class="avatar ${sizeClass}"><img src="${src}" alt="${fallback}"></div>`;
  }
  return `<div class="avatar ${sizeClass}">${fallback}</div>`;
}
