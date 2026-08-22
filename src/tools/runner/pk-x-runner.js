#!/usr/bin/env node
// Runner X — publie via ego-browser (EgoLite) : espace de navigation isolé qui
// hérite de la session X de l'utilisateur, sans voler son navigateur ni son focus.
const fs = require('fs');
const path = require('path');
const { runEgo } = require('./ego-engine');

process.on('uncaughtException', e => { log(`UNCAUGHT: ${e.message}`); process.exit(9); });
process.on('unhandledRejection', r => { log(`UNHANDLED REJECTION: ${r}`); process.exit(9); });

const CONFIG_PATH = process.env.PK_RUNNER_CONF || path.join(process.env.HOME || '/root', '.config', 'pk-x-runner.json');
const LOG_PATH = process.env.PK_RUNNER_LOG || path.join(process.env.HOME || '/root', '.local', 'log', 'pk-x-runner.log');
const KILL_SWITCH = path.join(process.env.HOME || '/root', '.config', 'pk-runners.disabled');
const NAMESPACE = 'pksocialsharing/v1';
const TASK_SPACE = 'pk-x-runner';

function loadConfig() {
	if (!fs.existsSync(CONFIG_PATH)) {
		console.error(`Config manquante: ${CONFIG_PATH}`);
		console.error('Copie src/tools/runner/config.example.json vers ~/.config/pk-x-runner.json et remplis-le.');
		process.exit(2);
	}
	try {
		return JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
	} catch (e) {
		console.error(`Config invalide JSON: ${e.message}`);
		process.exit(2);
	}
}

function log(msg) {
	const line = `[${new Date().toISOString()}] ${msg}`;
	fs.mkdirSync(path.dirname(LOG_PATH), { recursive: true });
	fs.appendFileSync(LOG_PATH, line + '\n');
}

async function wpCall(cfg, method, route, body) {
	const url = `${cfg.wp_url.replace(/\/$/, '')}/wp-json/${NAMESPACE}/${route}`;
	const opts = {
		method,
		headers: {
			'X-PK-Runner-Token': cfg.runner_token,
			Accept: 'application/json',
			'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
		},
		signal: AbortSignal.timeout(60000),
	};
	if (body !== undefined) {
		opts.headers['Content-Type'] = 'application/json';
		opts.body = JSON.stringify(body);
	}
	const res = await fetch(url, opts);
	let data = {};
	try { data = await res.json(); } catch (_) {}
	return { ok: res.ok, status: res.status, data };
}

function egoScript(intentUrl, autoclick, opts) {
	return `
const task = await useOrCreateTaskSpace('${TASK_SPACE}')
try {
	await openOrReuseTab(${JSON.stringify(intentUrl)}, { wait: true, timeout: 60 })
	await wait(${opts.humanDelaySec.toFixed(2)})
	if (!${autoclick}) {
		await completeTaskSpace(task.id, { keep: true })
		cliLog('RESULT ' + JSON.stringify({ ok: true, manual: true }))
	} else {
		let clicked = false
		for (const sel of ['[data-testid="tweetButton"]', '[data-testid="tweetButtonInline"]']) {
			try { await click(sel, { label: 'publier le post' }); clicked = true; break } catch (_) {}
		}
		if (!clicked) throw new Error('bouton tweet introuvable')
		const confirm = () => js(String.raw\`(() => {
			const msgs = [...document.querySelectorAll('[role="alert"], [data-testid="toast"]')]
				.map(e => (e.innerText || e.textContent || ''))
			return msgs.some(t => /(?:post|tweet|publication).{0,40}(?:sent|envoy|publi)|(?:sent|envoy|publi).{0,40}(?:post|tweet|publication)/i.test(t))
		})()\`)
		const deadline = Date.now() + ${opts.clickTimeoutMs}
		let confirmed = await confirm()
		while (!confirmed && Date.now() < deadline) { await wait(0.5); confirmed = await confirm() }
		if (!confirmed) throw new Error('confirmation explicite X absente')
		await wait(2)
		await completeTaskSpace(task.id, { keep: false })
		cliLog('RESULT ' + JSON.stringify({ ok: true }))
	}
} catch (e) {
	cliLog('RESULT ' + JSON.stringify({ ok: false, error: String(e && e.message || e) }))
}
`;
}

async function releaseAndExit(cfg, postId, reason, code) {
	log(reason);
	if (postId) await wpCall(cfg, 'POST', 'x-browser/release', { post_id: postId }).catch(() => {});
	process.exit(code);
}

(async () => {
	const cfg = loadConfig();
	for (const k of ['wp_url', 'runner_token']) {
		if (!cfg[k]) { log(`ERREUR: champ "${k}" manquant dans ${CONFIG_PATH}`); process.exit(2); }
	}

	const hour = new Date().getHours();
	const pauseStart = 20;
	const pauseEnd = 9;
	if (hour < pauseEnd || hour >= pauseStart) {
		log(`PAUSE NOCTURNE (${hour}h) — hors fenêtre ${pauseEnd}h-${pauseStart}h.`);
		process.exit(0);
	}

	if (fs.existsSync(KILL_SWITCH)) {
		log(`KILL SWITCH actif (${KILL_SWITCH}) — arrêt.`);
		process.exit(0);
	}

	let next;
	try {
		const r = await wpCall(cfg, 'GET', 'x-browser/next');
		if (!r.ok) { log(`ERREUR /next HTTP ${r.status}: ${JSON.stringify(r.data)}`); process.exit(1); }
		next = r.data;
	} catch (e) {
		log(`ERREUR /next: ${e.message}`);
		process.exit(3);
	}

	if (next.empty) {
		log(`RIEN (${next.reason || 'empty'})`);
		process.exit(0);
	}

	const postId = next.post_id;
	log(`POST #${postId} « ${next.title} » autoclick=${next.autoclick} (moteur ego-browser)`);

	const autoclick = cfg.autoclick_override === null ? !!next.autoclick : !!cfg.autoclick_override;
	const delayMin = cfg.human_delay_ms_min || 1500;
	const delayMax = cfg.human_delay_ms_max || 4000;
	const result = await runEgo(cfg, egoScript(next.intent_url, autoclick, {
		humanDelaySec: (delayMin + Math.random() * (delayMax - delayMin)) / 1000,
		clickTimeoutMs: cfg.click_timeout_ms || 12000,
	}));

	if (!result.ok) {
		await releaseAndExit(cfg, postId, `POST #${postId} ECHEC: ${result.error} — claim libéré.`, 1);
		return;
	}
	if (result.manual) {
		await releaseAndExit(cfg, postId, `POST #${postId} autoclick off — onglet ouvert dans EgoLite, claim libéré (clic manuel).`, 0);
		return;
	}

	let done;
	try {
		done = await wpCall(cfg, 'POST', 'x-browser/done', { post_id: postId });
	} catch (e) {
		log(`POST #${postId} ERREUR /done: ${e.message} — posté mais non marqué, vérifie WP.`);
		process.exit(1);
	}

	log(`POST #${postId} ${done.ok ? 'marque publié' : `ERREUR /done HTTP ${done.status}`}.`);
	process.exit(done.ok ? 0 : 1);
})();
