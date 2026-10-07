const $ = id => document.getElementById(id);
const FIELDS = ['employee', 'serverUrl', 'key'];

chrome.storage.local.get('config').then(({ config = {} }) => {
  for (const field of FIELDS) $(field).value = config[field] || '';
});

$('form').onsubmit = async e => {
  e.preventDefault();
  const config = Object.fromEntries(FIELDS.map(field => [field, $(field).value.trim()]));
  config.serverUrl = config.serverUrl.replace(/\/+$/, '');
  await chrome.storage.local.set({ config });

  const result = $('result');
  result.className = '';
  result.textContent = 'Testing…';
  await chrome.runtime.sendMessage({ type: 'sync' });
  const { lastSync } = await chrome.storage.local.get('lastSync');
  if (lastSync && lastSync.ok) {
    result.className = 'ok';
    result.textContent = `✓ Connected. ${config.employee} will appear on the dashboard within a minute.`;
  } else {
    result.className = 'bad';
    result.textContent = `✗ Saved, but the server could not be reached: ${lastSync ? lastSync.error : 'unknown error'}`;
  }
};
