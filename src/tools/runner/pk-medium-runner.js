#!/usr/bin/env node
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const CONFIG_PATH = process.env.PK_MEDIUM_RUNNER_CONF || path.join(process.env.HOME || '/root', '.config', 'pk-medium-runner.json');
const LOG_PATH = process.env.PK_MEDIUM_RUNNER_LOG || path.join(process.env.HOME || '/root', '.local', 'log', 'pk-medium-runner.log');
const NAMESPACE = 'pksocialsharing/v1';
const POLL_INTERVAL_MS = 30000;
const ERROR_BACKOFF_MS = 60000;

function log(message) {
	const line = `[${new Date().toISOString()}] ${message}`;
	fs.mkdirSync(path.dirname(LOG_PATH), { recursive: true });
	fs.appendFileSync(LOG_PATH, `${line}\n`);
	console.log(line);
}

function loadConfig() {
	if (!fs.existsSync(CONFIG_PATH)) throw new Error(`Config manquante: ${CONFIG_PATH}`);
	return JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
}

function sleep(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }

async function wpCall(config, method, route, body) {
	const options = {
		method,
		headers: { 'X-PK-Runner-Token': config.runner_token, Accept: 'application/json' },
		signal: AbortSignal.timeout(60000),
	};
	if (body !== undefined) {
		options.headers['Content-Type'] = 'application/json';
		options.body = JSON.stringify(body);
	}
	const response = await fetch(`${config.wp_url.replace(/\/$/, '')}/wp-json/${NAMESPACE}/${route}`, options);
	let data = {};
	try { data = await response.json(); } catch (_) {}
	return { ok: response.ok, status: response.status, data };
}

async function connectBrowser(config) {
	const base = config.browser_url.replace(/\/$/, '');
	try {
		const response = await fetch(`${base}/json/version`, { signal: AbortSignal.timeout(5000) });
		const info = await response.json();
		return puppeteer.connect({ browserWSEndpoint: info.webSocketDebuggerUrl, defaultViewport: null });
	} catch (_) {
		const script = path.join(__dirname, process.platform === 'darwin' ? 'start-chrome-macos.sh' : 'start-chromium-linux.sh');
		if (!fs.existsSync(script)) throw new Error(`Navigateur CDP indisponible et script absent: ${script}`);
		spawn(script, [], { detached: true, stdio: 'ignore', env: process.env }).unref();
		for (let attempt = 0; attempt < 30; attempt++) {
			await sleep(1000);
			try {
				const response = await fetch(`${base}/json/version`, { signal: AbortSignal.timeout(2000) });
				const info = await response.json();
				return puppeteer.connect({ browserWSEndpoint: info.webSocketDebuggerUrl, defaultViewport: null });
			} catch (_) {}
		}
		throw new Error('Chrome CDP inaccessible');
	}
}

async function release(config, postId) {
	await wpCall(config, 'POST', 'medium-browser/release', { post_id: postId }).catch(() => {});
}

async function processNext(config, browser) {
	const next = await wpCall(config, 'GET', 'medium-browser/next');
	if (!next.ok) throw new Error(`/next HTTP ${next.status}: ${JSON.stringify(next.data)}`);
	if (next.data.empty) {
		log(`RIEN (${next.data.reason || 'queue_empty'})`);
		return false;
	}

	const postId = next.data.post_id;
	let page;
	try {
		log(`POST #${postId} « ${next.data.title} » — ${next.data.link}`);
		page = await browser.newPage();
		await page.goto(next.data.import_url, { waitUntil: 'networkidle2', timeout: 30000 });
		await sleep(2000);

		const textboxSelector = 'div[role="textbox"]';
		await page.waitForSelector(textboxSelector, { timeout: 15000 });
		await page.click(textboxSelector);
		await sleep(200);
		await page.evaluate(() => { document.execCommand('selectAll', false, null); });
		await page.keyboard.press('Backspace');
		await page.keyboard.type(next.data.link, { delay: 5 });
		await sleep(500);

		const typed = await page.evaluate((selector, expected) => {
			const el = document.querySelector(selector);
			return el && (el.textContent || '').includes(expected);
		}, textboxSelector, next.data.link);
		if (!typed) throw new Error('URL non insérée dans le champ Medium');

		const autoclick = config.autoclick_override === null ? !!next.data.autoclick : !!config.autoclick_override;
		if (!autoclick) {
			log(`POST #${postId}: URL collée, validation manuelle requise.`);
			await release(config, postId);
			return true;
		}

		await sleep(config.human_delay_ms || 1500);
		const clicked = await page.evaluate(() => {
			const button = [...document.querySelectorAll('button, [role="button"]')].find((element) => {
				const text = (element.textContent || '').trim();
				return /^import$/i.test(text) || /^importer$/i.test(text);
			});
			if (!button) return false;
			button.click();
			return true;
		});
		if (!clicked) throw new Error('bouton Importer introuvable; Medium a probablement changé son interface');

		const importTimeout = config.click_timeout_ms || 30000;
		await page.waitForFunction(
			(importUrl) => !window.location.href.startsWith(importUrl),
			{ timeout: importTimeout },
			next.data.import_url
		).catch(() => { throw new Error(`redirection après import absente après ${importTimeout}ms`); });

		await sleep(3000);
		const editorUrl = page.url();
		log(`POST #${postId}: import OK, éditeur ouvert — ${editorUrl}`);

		const autopublish = config.autopublish === undefined ? true : !!config.autopublish;
		let mediumPostUrl = '';
		if (autopublish) {
			await sleep(2000);
			const publishClicked = await page.evaluate(() => {
				const btn = [...document.querySelectorAll('button, [role="button"]')].find((el) => {
					const t = (el.textContent || '').trim();
					return /^publish$/i.test(t) || /^publier$/i.test(t);
				});
				if (!btn) return false;
				btn.click();
				return true;
			});
			if (!publishClicked) {
				log(`POST #${postId}: bouton Publish introuvable — brouillon laissé sur Medium.`);
			} else {
				await sleep(5000);
				const confirmed = await page.evaluate(() => {
					const buttons = [...document.querySelectorAll('button, [role="button"]')].filter((el) => {
						const t = (el.textContent || '').trim();
						if (!/^publish$/i.test(t) && !/^publish now$/i.test(t) && !/^publier$/i.test(t)) return false;
						const rect = el.getBoundingClientRect();
						if (rect.width < 150) return false;
						const style = window.getComputedStyle(el);
						if (style.display === 'none' || style.visibility === 'hidden') return false;
						return true;
					});
					if (buttons.length === 0) return false;
					buttons[buttons.length - 1].click();
					return true;
				});
				if (!confirmed) {
					log(`POST #${postId}: confirmation Publish absente — brouillon laissé sur Medium.`);
				} else {
					await page.waitForFunction(
						() => !/\/(edit|p\/edit|new)\b/i.test(window.location.href),
						{ timeout: 30000 }
					).catch(() => {});
					await sleep(2000);
					mediumPostUrl = page.url();
					log(`POST #${postId}: publié — ${mediumPostUrl}`);
				}
			}
		}

		const done = await wpCall(config, 'POST', 'medium-browser/done', { post_id: postId, medium_post_url: mediumPostUrl });
		if (!done.ok) throw new Error(`/done HTTP ${done.status}`);
		log(`POST #${postId}: import Medium marqué comme traité${mediumPostUrl ? ' et publié' : ' (brouillon)'}.`);
		return true;
	} finally {
		if (page) await page.close().catch(() => {});
	}
}

async function daemon() {
	let stopping = false;
	const stop = (signal) => {
		if (stopping) return;
		stopping = true;
		log(`Arrêt demandé (${signal}), fermeture propre...`);
	};
	process.on('SIGINT', () => stop('SIGINT'));
	process.on('SIGTERM', () => stop('SIGTERM'));

	const config = loadConfig();
	for (const key of ['wp_url', 'runner_token', 'browser_url']) {
		if (!config[key]) throw new Error(`Champ "${key}" manquant dans ${CONFIG_PATH}`);
	}

	log(`Démarrage daemon (poll ${POLL_INTERVAL_MS / 1000}s, wp=${config.wp_url})`);
	let browser = await connectBrowser(config);

	while (!stopping) {
		try {
			const hasWork = await processNext(config, browser);
			if (!hasWork) {
				await sleep(POLL_INTERVAL_MS);
				continue;
			}
			// dès qu'un post est traité, on reboucle tout de suite au cas où la queue contient plusieurs posts
			continue;
		} catch (error) {
			log(`ERREUR boucle: ${error.message}`);
			// Reconnecter le navigateur si la connexion CDP a sauté
			if (browser) await browser.disconnect().catch(() => {});
			browser = null;
			try {
				browser = await connectBrowser(config);
			} catch (connectError) {
				log(`Reconnexion navigateur échouée: ${connectError.message}. Retry dans ${ERROR_BACKOFF_MS / 1000}s.`);
				await sleep(ERROR_BACKOFF_MS);
			}
		}
	}

	if (browser) await browser.disconnect().catch(() => {});
	log('Daemon arrêté.');
}

daemon().catch((err) => {
	log(`FATAL: ${err.message}`);
	process.exit(1);
});
