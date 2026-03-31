document.addEventListener('DOMContentLoaded', () => {
  const url = document.body?.dataset?.whatsappUrl?.trim() || '';
  if (!url) {
    return;
  }

  window.setTimeout(() => {
    window.location.replace(url);
  }, 60);
});
