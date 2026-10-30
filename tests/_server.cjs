// Wspolny start serwera testowego (prawdziwa wtyczka na stubie WP) dla wszystkich testow.
const { spawn } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');

async function startServer(port, pluginDir) {
  const root = path.resolve(__dirname, '..');
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'perki-'));
  const env = { ...process.env, PERKI_DOCROOT: path.join(tmp, 'docroot'), PERKI_STUB_STATE: path.join(tmp, 'state') };
  if (pluginDir) env.PERKI_PLUGIN_DIR = pluginDir;
  const proc = spawn(path.join(root, 'tests/serve.sh'), [String(port)], { env, stdio: 'ignore' });
  const base = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 50; i++) {
    try { const r = await fetch(base + '/__reset', { method: 'POST' }); await r.text(); if (r.ok) break; } catch (e) {}
    await new Promise(r => setTimeout(r, 100));
  }
  return {
    base,
    stop: () => proc.kill('SIGTERM'),
    reset: () => fetch(base + '/__reset', { method: 'POST' }).then(r => r.text()),
    state: () => fetch(base + '/__state').then(r => r.json()),
  };
}
module.exports = { startServer };
