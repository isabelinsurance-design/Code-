// NetConnect Work Tracker: passes the open-call count from phone-hook.js
// (which runs inside Nextiva's page) to the extension. Nothing else is read.
window.addEventListener('message', event => {
  if (event.source !== window || !event.data || event.data.workTrackerCall !== true) return;
  try {
    chrome.runtime.sendMessage({ type: 'phone', open: Number(event.data.open) || 0 }).catch(() => {});
  } catch {} // the extension was reloaded; this old copy of the page can't reach it any more
});
