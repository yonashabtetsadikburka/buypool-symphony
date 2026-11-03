export function Card({ class: extraClass = '', children }) {
  return `<div class="card ${extraClass}">${children}</div>`;
}
