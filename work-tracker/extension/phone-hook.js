// NetConnect Work Tracker: runs inside Nextiva's web app (NextivaONE in Chrome)
// only to notice when a call's audio connection opens and closes. It counts
// open connections and nothing else: no audio, phone numbers, names or page
// content. phone-relay.js passes the count to the extension.
(() => {
  const FLAG = Symbol.for('work-tracker-phone');
  const Native = window.RTCPeerConnection;
  if (!Native || window[FLAG]) return;
  window[FLAG] = true;

  const watched = new Set();
  let reported = -1;
  // "disconnected" is usually a network blip in the middle of a call, so it still counts.
  const isOpen = pc => pc.connectionState === 'connected' || pc.connectionState === 'disconnected';

  function report(force) {
    for (const pc of watched) if (pc.connectionState === 'closed' || pc.connectionState === 'failed') watched.delete(pc);
    let open = 0;
    for (const pc of watched) if (isOpen(pc)) open++;
    if (open === reported && !force) return;
    reported = open;
    window.postMessage({ workTrackerCall: true, open }, '*');
  }

  // A transparent stand-in: the app gets a real RTCPeerConnection; the tracker only listens to its state.
  const Watched = new Proxy(Native, {
    construct(target, args, newTarget) {
      const pc = Reflect.construct(target, args, newTarget);
      try {
        watched.add(pc);
        pc.addEventListener('connectionstatechange', () => report(false));
      } catch {}
      return pc;
    },
  });
  window.RTCPeerConnection = Watched;
  if (window.webkitRTCPeerConnection === Native) window.webkitRTCPeerConnection = Watched;

  // close() doesn't fire a state event, so check every 2 seconds; repeat the
  // count every 20 seconds during a call so the extension knows it's still on.
  let ticks = 0;
  setInterval(() => report(reported > 0 && ++ticks % 10 === 0), 2000);
})();
