const $ = id => document.getElementById(id);

function duration(ms) {
  const minutes = Math.floor((ms || 0) / 60000);
  return minutes >= 60 ? `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m` : `${minutes}m`;
}
const clock = t => new Date(t).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
const ago = t => {
  const minutes = Math.round((Date.now() - t) / 60000);
  return minutes < 1 ? 'just now' : `${minutes} min ago`;
};

function describe(s, phone) {
  if (phone && phone.call) return ['📞 On a call', `Nextiva · since ${clock(phone.call.from)}`];
  if (s.mode === 'off') return ['⏹ Clocked out', s.clockOut ? `at ${clock(s.clockOut)}` : ''];
  if (s.mode === 'lunch') return ['🍴 At lunch', `since ${clock(s.modeSince)}`];
  if (s.mode === 'break') return ['☕ On break', `since ${clock(s.modeSince)}`];
  if (s.mode === 'meeting') return ['👥 Meeting / training', `since ${clock(s.modeSince)}`];
  if (!s.clockIn) return ['Waiting for first activity', ''];
  const started = `Clocked in ${clock(s.clockIn)}`;
  if (s.idle === 'locked') return ['🔒 Computer locked', started];
  if (s.idle === 'idle') return ['🟡 Idle', started];
  const where = s.domain === '(outside chrome)' ? (s.section ? `${s.section} (outside Chrome)` : 'Another app (outside Chrome)')
    : s.section ? `${s.domain} · ${s.section}` : s.domain;
  return ['🟢 Working', `${where} · ${started}`];
}

async function render(s) {
  const { config = {}, lastSync, outbox = [], me, phone } = await chrome.storage.local.get(['config', 'lastSync', 'outbox', 'me', 'phone']);
  $('who').textContent = config.employee || 'Work Tracker';
  $('setup').hidden = Boolean(config.serverUrl && config.employee);

  const [state, site] = describe(s, phone);
  $('state').textContent = state;
  $('site').textContent = site;

  // Prefer the office's count (it includes phone calls); fall back to this computer's own.
  const t = s.totals || {};
  const office = me && me.date === s.day && Date.now() - me.at < 10 * 60000 ? me : null;
  $('active').textContent = duration(office ? office.working * 1000 : (t.active || 0) + (t.meeting || 0));
  $('idle').textContent = duration(office ? office.idleAway * 1000 : (t.idle || 0) + (t.away || 0));
  $('lunch').textContent = duration(office ? office.lunchBreak * 1000 : (t.lunch || 0) + (t.break || 0));
  $('score').textContent = office
    ? [
        office.productivity != null ? `Productive ${Math.round(office.productivity * 100)}%` : '',
        office.activity != null ? `Activity ${Math.round(office.activity * 100)}%` : '',
        office.calls ? `${office.calls} call${office.calls === 1 ? '' : 's'}` : '',
        office.crmActions ? `${office.crmActions} CRM actions` : '',
      ].filter(Boolean).join(' · ')
    : '';

  $('work').hidden = s.mode === 'work';
  $('work').textContent = s.mode === 'off' ? '▶ Clock back in' : '▶ Back to work';
  $('lunchBtn').hidden = s.mode === 'lunch';
  $('breakBtn').hidden = s.mode === 'break';
  $('meetingBtn').hidden = s.mode === 'meeting';
  $('off').hidden = s.mode === 'off';

  const sync = $('sync');
  sync.classList.toggle('bad', Boolean(lastSync && !lastSync.ok));
  if (!lastSync) sync.textContent = '';
  else if (lastSync.ok) sync.textContent = `Synced ${ago(lastSync.at)}`;
  else sync.textContent = `Not synced: ${lastSync.error}${outbox.length ? ` (${outbox.length} saved, will retry)` : ''}`;
}

function setMode(mode) {
  chrome.runtime.sendMessage({ type: 'setMode', mode }, s => s && render(s));
}

$('work').onclick = () => setMode('work');
$('lunchBtn').onclick = () => setMode('lunch');
$('breakBtn').onclick = () => setMode('break');
$('meetingBtn').onclick = () => setMode('meeting');
$('off').onclick = () => setMode('off');
$('settings').onclick = e => {
  e.preventDefault();
  chrome.runtime.openOptionsPage();
};

chrome.runtime.sendMessage({ type: 'status' }, s => s && render(s));
