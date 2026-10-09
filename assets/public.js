document.addEventListener('click', (event) => {
  const button = event.target.closest('.ewp-reset');
  if (!button) return;
  const url = new URL(window.location.href);
  for (const key of [...url.searchParams.keys()]) if (key.startsWith(button.dataset.prefix)) url.searchParams.delete(key);
  window.location.assign(url.href);
});
// Empty date controls must not override the API's relative date presets.
document.addEventListener('submit', (event) => {
  if (!event.target.matches('.ewp-filters, .ewp-search')) return;
  for (const input of event.target.elements) if (input.name && input.value === '') input.disabled = true;
});
document.addEventListener('click', (event) => {
  const opener = event.target.closest('.ewp-advanced-open');
  if (opener) {
    const panel = document.getElementById(opener.getAttribute('aria-controls'));
    if (panel && !panel.open) panel.showModal();
  }
  const closer = event.target.closest('.ewp-advanced-close');
  if (closer) closer.closest('dialog').close();
});
document.addEventListener('change', (event) => {
  const form = event.target.closest('.ewp-filters-compact');
  if (!form) return;
  const period = form.querySelector('select[name$="period"]');
  if (event.target === period && period.value !== 'custom') {
    for (const date of form.querySelectorAll('input[type="date"]')) date.value = '';
  } else if (event.target.matches('input[type="date"]') && event.target.value) {
    period.value = 'custom';
  }
});
