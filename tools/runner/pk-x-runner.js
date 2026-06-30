#!/usr/bin/env node
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const CONFIG_PATH = process.env.PK_RUNNER_CONF || path.join(process.env.HOME || '/root', '.config', 'pk-x-runner.json');
const LOG_PATH = process.env.PK_RUNNER_LOG || path.join(process.env.HOME || '/root', '.local', 'log', 'pk-x-runner.log');
const NAMESPACE = 'pksocialsharing/v1';

function loadConfig() {
	if (!fs.existsSync(CONFIG_PATH)) {
		console.error(`Config manquante: ${CONFIG_PATH}`);
		console.error('Copie tools/runner/config.example.json vers ~/.config/pk-x-runner.json et remplis-le.');
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

function sleep(ms) {
	return new Promise((resolve) => setTimeout(resolve, ms));
}

function randInt(minMs, maxMs) {
	return Math.floor(minMs + Math.random() * (maxMs - minMs));
}

async function wpCall(cfg, method, route, body) {
	const url = `${cfg.wp_url.replace(/\/$/, '')}/wp-json/${NAMESPACE}/${route}`;
	const opts = {
		method,
		headers: {
			'X-PK-Runner-Token': cfg.runner_token,
			Accept: 'application/json',
		},
		signal: AbortSignal.timeout(20000),
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
	let wsEndpoint;
	try {
		const ver = await fetch(`${base}/json/version`, { signal: AbortSignal.timeout(5000) });
		const info = await ver.json();
		wsEndpoint = info.webSocketDebuggerUrl;
	} catch (e) {
		throw new Error(`Chrome CDP inaccessible sur ${base} — lance-le d'abord (voir start-chrome-*.sh). Détail: ${e.message}`);
	}
	if (!wsEndpoint) throw new Error('webSocketDebuggerUrl absent de /json/version');
	return puppeteer.connect({ browserWSEndpoint: wsEndpoint, defaultViewport: null });
}

async function releaseAndExit(cfg, browser, page, postId, reason, code) {
	log(`POST #${postId} ${reason}`);
	if (postId) await wpCall(cfg, 'POST', 'x-browser/release', { post_id: postId }).catch(() => {});
	if (page) await page.close().catch(() => {});
	if (browser) await browser.disconnect().catch(() => {});
	process.exit(code);
}

(async () => {
	const cfg = loadConfig();
	for (const k of ['wp_url', 'runner_token', 'browser_url']) {
		if (!cfg[k]) { log(`ERREUR: champ "${k}" manquant dans ${CONFIG_PATH}`); process.exit(2); }
	}

	let browser;
	try {
		await ensureCanary(cfg);
		browser = await connectBrowser(cfg);
	} catch (e) {
		log(`ERREUR CDP: ${e.message}`);
		process.exit(3);
	}

	let next;
	try {
		const r = await wpCall(cfg, 'GET', 'x-browser/next');
		if (!r.ok) { log(`ERREUR /next HTTP ${r.status}: ${JSON.stringify(r.data)}`); await browser.disconnect(); process.exit(1); }
		next = r.data;
	} catch (e) {
		log(`ERREUR /next: ${e.message}`); await browser.disconnect(); process.exit(1);
	}

	if (next.empty) {
		log(`RIEN (${next.reason || 'empty'})`);
		await browser.disconnect();
		process.exit(0);
	}

	const postId = next.post_id;
	log(`POST #${postId} « ${next.title} » autoclick=${next.autoclick}`);

	const autoclick = cfg.autoclick_override === null ? !!next.autoclick : !!cfg.autoclick_override;
	if (!autoclick) {
		let page;
		try { page = await browser.newPage(); await page.goto(next.intent_url, { waitUntil: 'domcontentloaded', timeout: 30000 }); } catch (e) { /* tab opening best-effort */ }
		await releaseAndExit(cfg, browser, page, postId, `POST #${postId} autoclick off — onglet ouvert, claim libéré (clic manuel).`, 0);
		return;
	}

	let page;
	try {
		page = await browser.newPage();
		await page.goto(next.intent_url, { waitUntil: 'networkidle2', timeout: 30000 });
	} catch (e) {
		await releaseAndExit(cfg, browser, page, postId, `POST #${postId} ERREUR navigation: ${e.message}`, 1);
		return;
	}

	const selectors = ['[data-testid=tweetButton]', '[data-testid=tweetButtonInline]'];
	const delayMin = cfg.human_delay_ms_min || 1500;
	const delayMax = cfg.human_delay_ms_max || 4000;

	try {
		await sleep(randInt(delayMin, delayMax));
		let clicked = false;
		for (const sel of selectors) {
			const el = await page.$(sel);
			if (el) {
				await el.click();
				clicked = true;
				log(`POST #${postId} clic (${sel})`);
				break;
			}
		}
		if (!clicked) throw new Error('bouton tweet introuvable');

		const clickTimeout = cfg.click_timeout_ms || 12000;
		await page.waitForFunction(
			() => !document.querySelector('[data-testid=tweetButton]') || location.href.includes('/status/'),
			{ timeout: clickTimeout }
		).catch(() => {
			throw new Error(`confirmation post absente apres ${clickTimeout}ms`);
		});
		await sleep(2000);
	} catch (e) {
		await releaseAndExit(cfg, browser, page, postId, `POST #${postId} ECHEC: ${e.message} — claim libéré.`, 1);
		return;
	}

	let done;
	try {
		done = await wpCall(cfg, 'POST', 'x-browser/done', { post_id: postId });
	} catch (e) {
		log(`POST #${postId} ERREUR /done: ${e.message} — posté mais non marqué, vérifie WP.`);
		await page.close().catch(() => {});
		await browser.disconnect();
		process.exit(1);
	}

	log(`POST #${postId} ${done.ok ? 'marque publié' : `ERREUR /done HTTP ${done.status}`}.`);
	await page.close().catch(() => {});
	await browser.disconnect();
	process.exit(done.ok ? 0 : 1);
})();
