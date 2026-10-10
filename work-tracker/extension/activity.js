// NetConnect Work Tracker: activity level. Counts the seconds in which the
// person used the keyboard or mouse on this page, and sends only that count to
// the extension. It never records which keys, what is typed, mouse positions,
// the page address or any page content — only "there was input in this second".
(() => {
  if (window.__workTrackerActivity) return;
  window.__workTrackerActivity = true;

  const REPORT_MS = 12000;
  const active = new Set();      // which whole seconds had any input
  const mark = () => active.add(Math.floor(Date.now() / 1000));

  // Capture phase so it still counts when the page stops the event; passive so
  // the page's own scrolling and typing are never slowed. No event detail is read.
  const opts = { capture: true, passive: true };
  for (const type of ['keydown', 'mousedown', 'mousemove', 'wheel', 'scroll', 'touchstart', 'pointerdown']) {
    addEventListener(type, mark, opts);
  }

  function report() {
    const seconds = active.size;
    active.clear();
    if (!seconds) return;
    try {
      chrome.runtime.sendMessage({ type: 'input', seconds }).catch(() => {});
    } catch {} // the extension was reloaded; this old content script can't reach it
  }

  setInterval(report, REPORT_MS);
  addEventListener('pagehide', report);
  document.addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && report());
})();
