// Moteur commun: exécute des scripts ego-browser (EgoLite) et récupère leur résultat.
// Chaque script doit se terminer par cliLog('RESULT ' + JSON.stringify({...})).
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');

function egoBin(cfg) {
	if (cfg && cfg.ego_bin) return cfg.ego_bin;
	const local = path.join(os.homedir(), '.local', 'bin', 'ego-browser');
	if (fs.existsSync(local)) return local;
	return 'ego-browser';
}

function runEgo(cfg, scriptBody, timeoutMs = 180000) {
	return new Promise((resolve) => {
		const bin = egoBin(cfg);
		const env = { ...process.env };
		if (path.isAbsolute(bin)) env.PATH = `${path.dirname(bin)}:${env.PATH || ''}`;
		const child = spawn(bin, ['nodejs'], { stdio: ['pipe', 'pipe', 'pipe'], env });
		let out = '';
		const timer = setTimeout(() => {
			child.kill('SIGKILL');
			resolve({ ok: false, error: `timeout ego-browser (${timeoutMs}ms)` });
		}, timeoutMs);
		child.stdout.on('data', (d) => { out += d; });
		child.stderr.on('data', (d) => { out += d; });
		child.on('error', (e) => { clearTimeout(timer); resolve({ ok: false, error: `spawn ego-browser: ${e.message}` }); });
		child.on('close', (code) => {
			clearTimeout(timer);
			const m = [...out.matchAll(/^RESULT (\{.*\})$/gm)].pop();
			if (m) {
				try { resolve(JSON.parse(m[1])); return; } catch (_) {}
			}
			resolve({ ok: false, error: `ego-browser exit=${code}, pas de RESULT. Sortie:\n${out.slice(-2000)}` });
		});
		child.stdin.write(scriptBody);
		child.stdin.end();
	});
}

module.exports = { runEgo, egoBin };
