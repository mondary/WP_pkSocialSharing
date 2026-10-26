#!/usr/bin/env node
// Check quotidien des partages WordPress (LinkedIn, X, Medium).
// Version Node : contrairement à zsh sous launchd, node peut lire le script
// dans ~/Documents (même accès TCC que les runners X/Medium).
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const CONFIG = process.env.PK_X_RUNNER_CONF || path.join(os.homedir(), '.config', 'pk-x-runner.json');
const LOG_PATH = process.env.PK_SHARECHECK_LOG || path.join(os.homedir(), '.local', 'log', 'pk-sharecheck.log');
const NAMESPACE = 'pksocialsharing/v1';
const NETS = [['linkedin', 'LinkedIn'], ['x', 'X'], ['medium', 'Medium']];

function appendLog(text) {
	fs.mkdirSync(path.dirname(LOG_PATH), { recursive: true });
	fs.appendFileSync(LOG_PATH, text);
}

async function main() {
	const cfg = JSON.parse(fs.readFileSync(CONFIG, 'utf8'));
	const res = await fetch(`${cfg.wp_url.replace(/\/$/, '')}/wp-json/${NAMESPACE}/shares?limit=15`, {
		headers: {
			'X-PK-Runner-Token': cfg.runner_token,
			'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
			Accept: 'application/json',
		},
		signal: AbortSignal.timeout(60000),
	});
	if (!res.ok) throw new Error(`HTTP ${res.status}`);
	const data = await res.json();
	const now = new Date();
	const problems = [];
	let count = 0;

	for (const item of data.items || []) {
		const date = new Date(item.date);
		if (now.getTime() - date.getTime() > 3 * 86400 * 1000) continue;
		count++;
		const missing = NETS.filter(([key]) => !(item.shares[key] && item.shares[key].shared_at)).map(([, label]) => label);
		if (!missing.length) continue;
		const sameDay = date.toISOString().slice(0, 10) === now.toISOString().slice(0, 10);
		if (sameDay && now.getHours() < 17) continue; // encore dans la fenêtre de publication
		const day = date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
		problems.push(`  - ${String(item.title).slice(0, 60)} (${day}) : ${missing.join(', ')}`);
	}

	let out = `Articles verifies (3 derniers jours) : ${count}\n`;
	if (count === 0) {
		out += 'PROBLEMES (1) :\n  - Aucun article publie depuis 3 jours (verifier la programmation WordPress)\nRESULT: PROBLEMES\n';
	} else if (problems.length) {
		out += `PROBLEMES (${problems.length}) :\n${problems.join('\n')}\nRESULT: PROBLEMES\n`;
	} else {
		out += 'Aucun manque sur LinkedIn, X et Medium.\nRESULT: CHECK OK\n';
	}

	appendLog(`[${now.toISOString()}] ${out}---\n`);
	console.log(out);
	if (problems.length && process.env.PK_SHARECHECK_NO_NOTIFY !== '1') {
		const msg = problems.slice(0, 3).join(' ').replace(/"/g, "'").slice(0, 180);
		try {
			execFileSync('osascript', ['-e', `display notification "${msg}" with title "PK SocialSharing" subtitle "Partages manquants"`]);
		} catch (_) {
			// notification best-effort
		}
	}
}

main().catch((e) => {
	const line = `[${new Date().toISOString()}] ERREUR: ${e.message}\n---\n`;
	try { appendLog(line); } catch (_) {}
	console.error(e);
	process.exit(1);
});
