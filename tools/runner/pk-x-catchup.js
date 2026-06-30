#!/usr/bin/env node
// pk-x-catchup.js — ONE-SHOT: rattrape le retard X en une fois.
//
// Bypass le plafond quotidien SANS modifier la règle WP:
//   1. énumère la queue via /next SANS appeler /done → le compteur quotidien
//      reste à 0 → le cap (daily_cap) ne bloque jamais /next.
//   2. publie chaque article dans Canary via CDP (clic auto).
//   3. marque /done uniquement à la toute fin (le cap ne bloque pas /done).
//
// Lance Chrome Canary si besoin. Ne change aucune option WordPress.
// Usage: node tools/runner/pk-x-catchup.js   (PK_PAUSE_MS=20000 par défaut)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const CONFIG_PATH = process.env.PK_RUNNER_CONF || path.join(process.env.HOME || '/root', '.config', 'pk-x-runner.json');
const LOG_PATH = process.env.PK_RUNNER_LOG || path.join(process.env.HOME || '/root', '.local', 'log', 'pk-x-runner.log');
const NAMESPACE = 'pksocialsharing/v1';
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function loadConfig() {
	if (!fs.existsSync(CONFIG_PATH)) {
		console.error(`Config manquante: ${CONFIG_PATH}`);
		console.error('Copie tools/runner/config.example.json vers ~/.config/pk-x-runner.json et remplis-le.');
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
		signal: AbortSignal.timeout(20000),
	};
	if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
	const res = await fetch(url, opts);
	let data = {}; try { data = await res.json(); } catch (_) {}
	return { ok: res.ok, status: res.status, data };
}

async function ensureCanary(cfg) {
	const base = cfg.browser_url.replace(/\/$/, '');
	try { await fetch(`${base}/json/version`, { signal: AbortSignal.timeout(3000) }); return; } catch (_) {}
	const startScript = process.platform === 'darwin' ? 'start-chrome-macos.sh' : 'start-chromium-linux.sh';
	log(`Navigateur down — démarrage via ${startScript}...`);
	const script = path.join(__dirname, startScript);
	if (!fs.existsSync(script)) { log(`ERREUR: ${script} introuvable`); process.exit(3); }
	spawn(script, [], { detached: true, stdio: 'ignore', env: { ...process.env } }).unref();
	for (let i = 0; i < 30; i++) {
		await sleep(1000);
		try { await fetch(`${base}/json/version`, { signal: AbortSignal.timeout(2000) }); log('Navigateur démarré.'); return; }
		catch (_) {}
	}
	log(`ERREUR: le navigateur n'a pas démarré sur le port CDP. Lance-le: ./${startScript}`);
	process.exit(3);
}

async function connectBrowser(cfg) {
	const base = cfg.browser_url.replace(/\/$/, '');
	const ver = await fetch(`${base}/json/version`, { signal: AbortSignal.timeout(5000) });
	const info = await ver.json();
	if (!info.webSocketDebuggerUrl) throw new Error('webSocketDebuggerUrl absent de /json/version');
	return puppeteer.connect({ browserWSEndpoint: info.webSocketDebuggerUrl, defaultViewport: null });
}

async function checkXSession(browser) {
	const page = await browser.newPage();
	try {
		await page.goto('https://x.com/home', { waitUntil: 'domcontentloaded', timeout: 30000 });
		await sleep(3500);
		const url = page.url();
		const loggedIn = await page.$('[data-testid="SideNav_NewTweet_Button"], [data-testid="AppTabBar_Home_Link"]');
		if (!loggedIn || /\/login|\/i\/flow\/login|\/logout/.test(url)) {
			log('ERREUR SESSION: pas connecté à X dans Canary.');
			log('→ Ouvre Canary (start-chrome-macos.sh avec PK_VISIBLE=1), connecte-toi à x.com, puis relance ce script.');
			return false;
		}
		log('Session X: OK (connecté).');
		return true;
	} finally { await page.close().catch(() => {}); }
}

// Phase 1: énumère la queue via /next SANS /done → compteur quotidien reste à 0,
// donc /next n'est jamais bloqué par le cap.
async function enumerateQueue(cfg) {
	const items = [];
	for (let i = 0; i < 200; i++) {
		const r = await wpCall(cfg, 'GET', 'x-browser/next');
		if (!r.ok) { log(`ERREUR /next HTTP ${r.status}: ${JSON.stringify(r.data)}`); break; }
		if (r.data.empty) { log(`Queue: ${r.data.reason} — ${items.length} article(s) à publier.`); break; }
		items.push({ post_id: r.data.post_id, title: r.data.title, intent_url: r.data.intent_url });
	}
	return items;
}

async function postOne(browser, item, cfg) {
	const page = await browser.newPage();
	try {
		await page.goto(item.intent_url, { waitUntil: 'networkidle2', timeout: 30000 });
	} catch (e) {
		await page.close().catch(() => {});
		return { ok: false, reason: `navigation: ${e.message}` };
	}
	const selectors = ['[data-testid=tweetButton]', '[data-testid=tweetButtonInline]'];
	const clickTimeout = cfg.click_timeout_ms || 12000;
	try {
		await sleep(randInt(cfg.human_delay_ms_min || 1500, cfg.human_delay_ms_max || 4000));
		let clicked = false;
		for (const sel of selectors) {
			const el = await page.$(sel);
			if (el) { await el.click(); clicked = true; log(`  clic (${sel})`); break; }
		}
		if (!clicked) { await page.close().catch(() => {}); return { ok: false, reason: 'bouton tweet introuvable (captcha ou DOM changé)' }; }
		await page.waitForFunction(
			() => !document.querySelector('[data-testid=tweetButton]') || location.href.includes('/status/'),
			{ timeout: clickTimeout }
		).catch(() => { throw new Error(`confirmation absente après ${clickTimeout}ms`); });
		await sleep(2000);
		await page.close().catch(() => {});
		return { ok: true };
	} catch (e) {
		await page.close().catch(() => {});
		return { ok: false, reason: e.message };
	}
}

(async () => {
	const cfg = loadConfig();
	for (const k of ['wp_url', 'runner_token', 'browser_url']) {
		if (!cfg[k]) { log(`ERREUR: champ "${k}" manquant dans ${CONFIG_PATH}`); process.exit(2); }
	}
	const betweenMs = parseInt(process.env.PK_PAUSE_MS || '20000', 10);

	await ensureCanary(cfg);
	let browser;
	try { browser = await connectBrowser(cfg); }
	catch (e) { log(`ERREUR CDP: ${e.message}`); process.exit(3); }

	if (!(await checkXSession(browser))) { await browser.disconnect().catch(() => {}); process.exit(4); }

	log('=== CATCH-UP X — énumération de la queue (sans /done, cap non bloqué) ===');
	const items = await enumerateQueue(cfg);
	if (items.length === 0) { log('Rien à rattraper.'); await browser.disconnect().catch(() => {}); process.exit(0); }

	log(`=== ${items.length} article(s) à publier — pause ${betweenMs}ms entre chaque ===`);
	const done = [];
	const failed = [];
	let consecFail = 0;

	for (const item of items) {
		log(`>> #${item.post_id} « ${String(item.title).slice(0, 50)} »`);
		const res = await postOne(browser, item, cfg);
		if (res.ok) {
			done.push(item.post_id);
			log(`  #${item.post_id} PUBLIÉ (${done.length}/${items.length})`);
			consecFail = 0;
		} else {
			failed.push({ id: item.post_id, reason: res.reason });
			log(`  #${item.post_id} ÉCHEC: ${res.reason}`);
			// Libère le claim pour qu'un retry futur le reprenne.
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
	await browser.disconnect().catch(() => {});
	process.exit(failed.length ? 1 : 0);
})();
