export function Badge({ variant = 'default', children }) {
  return `<span class="badge badge-${variant}">${children}</span>`;
}
