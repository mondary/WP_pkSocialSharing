#!/usr/bin/env node
// pk-x-catchup.js — ONE-SHOT: rattrape le retard X en une fois (moteur ego-browser).
//
// Utilise uniquement /next pour l'énumération (texte traduit, templates respectés).
// Si le plafond quotidien est atteint, il attend et réessaie.
//
// Usage: node src/tools/runner/pk-x-catchup.js
const fs = require('fs');
const path = require('path');
const { runEgo } = require('./ego-engine');

process.on('uncaughtException', e => { log(`UNCAUGHT: ${e.message}`); process.exit(9); });
process.on('unhandledRejection', r => { log(`UNHANDLED REJECTION: ${r}`); process.exit(9); });

const CONFIG_PATH = process.env.PK_RUNNER_CONF || path.join(process.env.HOME || '/root', '.config', 'pk-x-runner.json');
const LOG_PATH = process.env.PK_RUNNER_LOG || path.join(process.env.HOME || '/root', '.local', 'log', 'pk-x-runner.log');
const NAMESPACE = 'pksocialsharing/v1';
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
const TASK_SPACE = 'pk-x-catchup';

function loadConfig() {
	if (!fs.existsSync(CONFIG_PATH)) {
		console.error(`Config manquante: ${CONFIG_PATH}`);
		console.error('Copie src/tools/runner/config.example.json vers ~/.config/pk-x-runner.json et remplis-le.');
		process.exit(2);
	}
	try { return JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8')); }
	catch (e) { console.error(`Config invalide JSON: ${e.message}`); process.exit(2); }
}

function log(msg) {
	const line = `[${new Date().toISOString()}] ${msg}`;
	fs.mkdirSync(path.dirname(LOG_PATH), { recursive: true });
	fs.appendFileSync(LOG_PATH, line + '\n');
	console.log(line);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const randInt = (a, b) => Math.floor(a + Math.random() * (b - a));

async function wpCall(cfg, method, route, body) {
	const url = `${cfg.wp_url.replace(/\/$/, '')}/wp-json/${NAMESPACE}/${route}`;
	const opts = {
		method,
		headers: { 'X-PK-Runner-Token': cfg.runner_token, Accept: 'application/json', 'User-Agent': UA },
		signal: AbortSignal.timeout(60000),
	};
	if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
	const res = await fetch(url, opts);
	let data = {}; try { data = await res.json(); } catch (_) {}
	return { ok: res.ok, status: res.status, data };
}

async function checkXSession(cfg) {
	const r = await runEgo(cfg, `
const task = await useOrCreateTaskSpace('${TASK_SPACE}')
try {
	await openOrReuseTab('https://x.com/home', { wait: true, timeout: 60 })
	await wait(3.5)
	const info = await pageInfo()
	const loggedIn = await js(String.raw\`(() => !!document.querySelector('[data-testid="SideNav_NewTweet_Button"], [data-testid="AppTabBar_Home_Link"]'))()\`)
	if (!loggedIn || /\\/login|\\/i\\/flow\\/login|\\/logout/.test(info.url || '')) {
		cliLog('RESULT ' + JSON.stringify({ ok: true, loggedIn: false }))
	} else {
		await completeTaskSpace(task.id, { keep: false })
		cliLog('RESULT ' + JSON.stringify({ ok: true, loggedIn: true }))
	}
} catch (e) {
	cliLog('RESULT ' + JSON.stringify({ ok: false, error: String(e && e.message || e) }))
}
`);
	if (!r.ok) { log(`ERREUR SESSION (ego): ${r.error}`); return false; }
	if (!r.loggedIn) {
		log('ERREUR SESSION: pas connecté à X dans EgoLite.');
		log('→ Ouvre ego-browser (EgoLite), connecte-toi à x.com, puis relance.');
		return false;
	}
	log('Session X: OK (connecté).');
	return true;
}

async function enumerateViaNext(cfg) {
	const items = [];
	for (let i = 0; i < 100; i++) {
		const r = await wpCall(cfg, 'GET', 'x-browser/next');
		if (!r.ok) { log(`ERREUR /next HTTP ${r.status}: ${JSON.stringify(r.data)}`); break; }
		if (r.data.empty) {
			log(`Queue: ${r.data.reason} — ${items.length} article(s) à publier.`);
			break;
		}
		items.push({ post_id: r.data.post_id, title: r.data.title, intent_url: r.data.intent_url });
	}
	return items;
}

async function postOne(cfg, item) {
	const delayMin = cfg.human_delay_ms_min || 1500;
	const delayMax = cfg.human_delay_ms_max || 4000;
	const r = await runEgo(cfg, `
const task = await useOrCreateTaskSpace('${TASK_SPACE}')
try {
	await openOrReuseTab(${JSON.stringify(item.intent_url)}, { wait: true, timeout: 60 })
	await wait(${(randInt(delayMin, delayMax) / 1000).toFixed(2)})
	let clicked = false
	for (const sel of ['[data-testid="tweetButton"]', '[data-testid="tweetButtonInline"]']) {
		try { await click(sel, { label: 'publier le post' }); clicked = true; break } catch (_) {}
	}
	if (!clicked) throw new Error('bouton tweet introuvable (captcha ou DOM changé)')
	const check = () => js(String.raw\`(() => !document.querySelector('[data-testid="tweetButton"]') || location.href.includes('/status/'))()\`)
	const deadline = Date.now() + ${cfg.click_timeout_ms || 12000}
	let ok = await check()
	while (!ok && Date.now() < deadline) { await wait(0.5); ok = await check() }
	if (!ok) throw new Error('confirmation absente')
	await wait(2)
	await completeTaskSpace(task.id, { keep: false })
	cliLog('RESULT ' + JSON.stringify({ ok: true }))
} catch (e) {
	cliLog('RESULT ' + JSON.stringify({ ok: false, error: String(e && e.message || e) }))
}
`, 120000);
	if (r.ok) return { ok: true };
	return { ok: false, reason: r.error || 'échec ego-browser' };
}

(async () => {
	const cfg = loadConfig();
	for (const k of ['wp_url', 'runner_token']) {
		if (!cfg[k]) { log(`ERREUR: champ "${k}" manquant dans ${CONFIG_PATH}`); process.exit(2); }
	}

	const betweenMs = parseInt(process.env.PK_PAUSE_MS || '20000', 10);

	if (!(await checkXSession(cfg))) process.exit(4);

	log('=== CATCH-UP X — énumération via /next (texte traduit) ===');
	const items = await enumerateViaNext(cfg);
	if (items.length === 0) { log('Rien à rattraper.'); process.exit(0); }

	log(`=== ${items.length} article(s) à publier — pause ${betweenMs}ms entre chaque (moteur ego-browser) ===`);
	const done = [];
	const failed = [];
	let consecFail = 0;

	for (const item of items) {
		log(`>> #${item.post_id} « ${String(item.title).slice(0, 50)} »`);
		const res = await postOne(cfg, item);
		if (res.ok) {
			done.push(item.post_id);
			log(`  #${item.post_id} PUBLIÉ (${done.length}/${items.length})`);
			consecFail = 0;
		} else {
			failed.push({ id: item.post_id, reason: res.reason });
			log(`  #${item.post_id} ÉCHEC: ${res.reason}`);
			await wpCall(cfg, 'POST', 'x-browser/release', { post_id: item.post_id }).catch(() => {});
			consecFail++;
			if (consecFail >= 3) {
				log('3 échecs consécutifs — X bloque probablement (captcha/limite). ABORT.');
				break;
			}
		}
		if (betweenMs > 0) await sleep(betweenMs);
	}

	log(`=== Marquage /done pour ${done.length} article(s) publié(s) ===`);
	for (const pid of done) {
		const r = await wpCall(cfg, 'POST', 'x-browser/done', { post_id: pid });
		log(`  #${pid} ${r.ok ? 'marqué publié' : `ERREUR /done HTTP ${r.status}`}`);
	}

	log(`=== BILAN: ${done.length} publié(s), ${failed.length} échec(s) ===`);
	for (const f of failed) log(`  ÉCHEC #${f.id}: ${f.reason}`);
	process.exit(failed.length ? 1 : 0);
})();
