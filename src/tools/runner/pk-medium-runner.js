#!/usr/bin/env node
// Runner Medium (daemon) — importe et publie via ego-browser (EgoLite) :
// espace de navigation isolé qui hérite de la session Medium de l'utilisateur.
const fs = require('fs');
const path = require('path');
const { runEgo } = require('./ego-engine');

const CONFIG_PATH = process.env.PK_MEDIUM_RUNNER_CONF || path.join(process.env.HOME || '/root', '.config', 'pk-medium-runner.json');
const LOG_PATH = process.env.PK_MEDIUM_RUNNER_LOG || path.join(process.env.HOME || '/root', '.local', 'log', 'pk-medium-runner.log');
const KILL_SWITCH = path.join(process.env.HOME || '/root', '.config', 'pk-runners.disabled');
const NAMESPACE = 'pksocialsharing/v1';
const POLL_INTERVAL_MS = 30000;
const ERROR_BACKOFF_MS = 60000;
const TASK_SPACE = 'pk-medium-runner';

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

async function release(config, postId) {
	await wpCall(config, 'POST', 'medium-browser/release', { post_id: postId }).catch(() => {});
}

const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

// o2switch-PowerBoost sert les pages hors cache trop lentement au crawler Medium
// ("The server stopped responding"). On pré-charge la page pour chauffer le cache,
// sinon l'import échoue. Retourne le dernier code HTTP (0 si erreur réseau).
async function warmCache(link) {
	let last = 0;
	for (let i = 0; i < 3; i++) {
		try {
			const res = await fetch(link, { headers: { 'User-Agent': UA }, redirect: 'follow', signal: AbortSignal.timeout(30000) });
			last = res.status;
			await res.arrayBuffer().catch(() => {});
			log(`warm cache ${i + 1}/3: HTTP ${res.status}`);
		} catch (e) {
			log(`warm cache ${i + 1}/3 échec: ${e.message}`);
			return 0;
		}
	}
	return last;
}

// Script ego-browser : colle l'URL dans medium.com/p/import, clique Importer,
// puis Publish si autopublish. Retourne { ok, manual?, medium_post_url?, error? }.
function mediumScript(next, opts) {
	return `
const task = await useOrCreateTaskSpace('${TASK_SPACE}')
try {
	await openOrReuseTab(${JSON.stringify(next.import_url)}, { wait: true, timeout: 60 })
	await wait(2)
	const tb = 'div[role="textbox"]'
	await waitForElement(tb, { timeout: 15 })
	await click(tb, { label: 'champ import medium' })
	await js(String.raw\`(() => {
		document.execCommand('selectAll')
		document.execCommand('delete')
	})()\`)
	await typeText(${JSON.stringify(next.link)})
	const typed = await js(String.raw\`(() => {
		const el = document.querySelector('div[role="textbox"]')
		return !!(el && (el.textContent || '').includes(${JSON.stringify(next.link)}))
	})()\`)
	if (!typed) throw new Error('URL non insérée dans le champ Medium')

	if (!${opts.autoclick}) {
		await completeTaskSpace(task.id, { keep: true })
		cliLog('RESULT ' + JSON.stringify({ ok: true, manual: true }))
	} else {
		await wait(${(opts.humanDelayMs / 1000).toFixed(2)})
		const importButton = await js(String.raw\`(() => {
			const button = [...document.querySelectorAll('button, [role="button"]')].find((element) => {
				const text = (element.textContent || '').trim()
				return /^import$/i.test(text) || /^importer$/i.test(text)
			})
			if (!button) return ''
			button.setAttribute('data-pk-medium-import', '1')
			return '[data-pk-medium-import="1"]'
		})()\`)
		if (!importButton) throw new Error('bouton Importer introuvable; Medium a probablement changé son interface')
		await click(importButton, { label: 'importer sur Medium' })

		const deadline = Date.now() + ${opts.importTimeoutMs}
		let editorUrl = ''
		while (Date.now() < deadline) {
			let info
			try { info = await pageInfo() } catch (_) { await wait(1); continue }
			if (/^https:\\/\\/medium\\.com\\/p\\/[a-f0-9]+\\/edit(?:[?#]|$)/i.test(info.url || '')) {
				editorUrl = info.url
				break
			}
			const failed = await js(String.raw\`(() => /import failed/i.test(document.body ? document.body.innerText : ''))()\`)
			if (failed) throw new Error('Medium n’a pas pu importer l’article')
			await wait(1)
		}
		if (!editorUrl) throw new Error('éditeur Medium introuvable après import')
		await wait(3)

		let mediumPostUrl = ''
		if (${opts.autopublish}) {
			await wait(2)
			const publishClicked = await js(String.raw\`(() => {
				const btn = [...document.querySelectorAll('button, [role="button"]')].find((el) => {
					const t = (el.textContent || '').trim()
					return /^publish$/i.test(t) || /^publier$/i.test(t)
				})
				if (!btn) return false
				btn.click()
				return true
			})()\`)
			if (!publishClicked) {
				// brouillon laissé sur Medium
			} else {
				await wait(5)
				const confirmed = await js(String.raw\`(() => {
					const buttons = [...document.querySelectorAll('button, [role="button"]')]
					const wide = buttons.filter((el) => {
						const t = (el.textContent || '').trim()
						if (!/^publish$/i.test(t) && !/^publish now$/i.test(t) && !/^publier$/i.test(t)) return false
						const rect = el.getBoundingClientRect()
						if (rect.width < 150) return false
						const style = window.getComputedStyle(el)
						if (style.display === 'none' || style.visibility === 'hidden') return false
						return true
					})
					if (wide.length === 0) return false
					wide[wide.length - 1].click()
					return true
				})()\`)
				if (!confirmed) {
					// brouillon laissé sur Medium
				} else {
					const deadline2 = Date.now() + 30000
					let info2 = await pageInfo()
					while (info2.url && /\\/(edit|p\\/edit|new)\\b/i.test(info2.url) && Date.now() < deadline2) {
						await wait(1)
						info2 = await pageInfo()
					}
					await wait(2)
					mediumPostUrl = info2.url || ''
				}
			}
		}
		await completeTaskSpace(task.id, { keep: false })
		cliLog('RESULT ' + JSON.stringify({ ok: true, medium_post_url: mediumPostUrl, editor_url: editorUrl }))
	}
} catch (e) {
	cliLog('RESULT ' + JSON.stringify({ ok: false, error: String(e && e.message || e) }))
}
`;
}

async function processNext(config, next) {
	const postId = next.post_id;
	log(`POST #${postId} « ${next.title} » — ${next.link} (moteur ego-browser)`);

	const warm = await warmCache(next.link);
	if (warm !== 200) {
		log(`POST #${postId} ECHEC: article inaccessible (HTTP ${warm}) — claim libéré.`);
		await release(config, postId);
		return false;
	}

	const autoclick = config.autoclick_override === null ? !!next.autoclick : !!config.autoclick_override;
	const result = await runEgo(config, mediumScript(next, {
		autoclick,
		humanDelayMs: config.human_delay_ms || 1500,
		importTimeoutMs: Math.max(config.click_timeout_ms || 0, 60000),
		autopublish: config.autopublish === undefined ? true : !!config.autopublish,
	}), 240000);

	if (!result.ok) {
		log(`POST #${postId} ECHEC: ${result.error} — claim libéré.`);
		await release(config, postId);
		return false;
	}
	if (result.manual) {
		log(`POST #${postId}: URL collée dans EgoLite, validation manuelle requise.`);
		await release(config, postId);
		return false;
	}

	log(`POST #${postId}: import OK, éditeur ouvert — ${result.editor_url || '?'}`);
	const done = await wpCall(config, 'POST', 'medium-browser/done', { post_id: postId, medium_post_url: result.medium_post_url || '' });
	if (!done.ok) {
		log(`POST #${postId} ERREUR /done HTTP ${done.status} — importé mais non marqué, vérifie WP.`);
		return false;
	}
	log(`POST #${postId}: import Medium marqué comme traité${result.medium_post_url ? ' et publié' : ' (brouillon)'}.`);
	return true;
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
	for (const key of ['wp_url', 'runner_token']) {
		if (!config[key]) throw new Error(`Champ "${key}" manquant dans ${CONFIG_PATH}`);
	}

	if (fs.existsSync(KILL_SWITCH)) {
		log(`KILL SWITCH actif (${KILL_SWITCH}) — arrêt.`);
		return;
	}

	log(`Démarrage daemon (poll ${POLL_INTERVAL_MS / 1000}s, wp=${config.wp_url}, moteur ego-browser)`);
	while (!stopping) {
		if (fs.existsSync(KILL_SWITCH)) {
			log(`KILL SWITCH actif (${KILL_SWITCH}) — arrêt daemon.`);
			break;
		}
		try {
			const response = await wpCall(config, 'GET', 'medium-browser/next');
			if (!response.ok) throw new Error(`/next HTTP ${response.status}: ${JSON.stringify(response.data)}`);
			if (response.data.empty) {
				log(`RIEN (${response.data.reason || 'queue_empty'})`);
				await sleep(POLL_INTERVAL_MS);
				continue;
			}
			const processed = await processNext(config, response.data);
			if (!processed) await sleep(ERROR_BACKOFF_MS);
			// dès qu'un post est traité, on reboucle tout de suite au cas où la queue contient plusieurs posts
			continue;
		} catch (error) {
			log(`ERREUR boucle: ${error.message}`);
			log(`Retry dans ${ERROR_BACKOFF_MS / 1000}s.`);
			await sleep(ERROR_BACKOFF_MS);
		}
	}

	log('Daemon arrêté.');
}

daemon().catch((err) => {
	log(`FATAL: ${err.message}`);
	process.exit(1);
});
