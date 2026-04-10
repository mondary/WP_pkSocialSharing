<?php
/**
 * Plugin Name: PK LinkedIn Auto Publish
 * Description: Publie automatiquement vos nouveaux articles sur LinkedIn (image mise en avant + extrait + lien).
 * Version: 0.39
 * Author: PK
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
	exit;
}

final class PKLIAP_Plugin {
	const OPT_KEY = 'pkliap_options';
	const OPT_TOKEN = 'pkliap_access_token';
	const OPT_TOKEN_EXP = 'pkliap_access_token_expires_at';
	const OPT_REFRESH = 'pkliap_refresh_token';
	const OPT_REFRESH_EXP = 'pkliap_refresh_token_expires_at';
	const META_SHARED_AT = '_pkliap_shared_at';
	const META_SHARE_URN = '_pkliap_linkedin_urn';
	const SYNC_NAMESPACE = 'pksocialsharing/v1';
	const SYNC_SLUG = 'pk-linkedin-autopublish';

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'admin_menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);

		add_action('admin_post_pkliap_connect', [__CLASS__, 'handle_connect']);
		add_action('admin_post_pkliap_oauth_callback', [__CLASS__, 'handle_oauth_callback']);
		add_action('admin_post_pkliap_disconnect', [__CLASS__, 'handle_disconnect']);
		add_action('admin_post_pkliap_test_post', [__CLASS__, 'handle_test_post']);
		add_action('admin_post_pkliap_detect_author', [__CLASS__, 'handle_detect_author']);

		add_action('transition_post_status', [__CLASS__, 'on_transition_post_status'], 10, 3);
	}

	public static function register_rest_routes(): void {
		register_rest_route(self::SYNC_NAMESPACE, '/sync-plugin/manifest', [
			'methods' => 'GET',
			'permission_callback' => static fn() => current_user_can('manage_options'),
			'callback' => [__CLASS__, 'rest_manifest'],
		]);

		register_rest_route(self::SYNC_NAMESPACE, '/sync-plugin', [
			'methods' => 'POST',
			'permission_callback' => static fn() => current_user_can('manage_options'),
			'callback' => [__CLASS__, 'rest_sync'],
		]);
	}

	public static function rest_manifest(WP_REST_Request $request): WP_REST_Response {
		$base_dir = plugin_dir_path(__FILE__);
		$files = self::sync_collect_files($base_dir);

		return new WP_REST_Response([
			'slug' => self::SYNC_SLUG,
			'version' => self::get_plugin_version(),
			'base' => basename($base_dir),
			'files' => array_values($files),
		], 200);
	}

	public static function rest_sync(WP_REST_Request $request): WP_REST_Response {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			return new WP_REST_Response(['error' => 'JSON invalide'], 400);
		}

		$dry_run = !empty($params['dry_run']);
		$files = isset($params['files']) && is_array($params['files']) ? $params['files'] : [];
		$delete_paths = isset($params['delete_paths']) && is_array($params['delete_paths']) ? $params['delete_paths'] : [];

		$base_dir = plugin_dir_path(__FILE__);
		$written = [];
		$deleted = [];
		$errors = [];

		foreach ($files as $item) {
			$rel = is_array($item) ? (string)($item['path'] ?? '') : '';
			$content_b64 = is_array($item) ? (string)($item['content_b64'] ?? '') : '';
			if (!$rel || !$content_b64) {
				$errors[] = ['type' => 'file', 'message' => 'Entrée fichier invalide'];
				continue;
			}
			$rel = ltrim($rel, '/');
			if (!self::sync_is_safe_rel_path($rel)) {
				$errors[] = ['type' => 'file', 'path' => $rel, 'message' => 'Chemin interdit'];
				continue;
			}

			if (!$dry_run) {
				$abs = $base_dir . $rel;
				$dir = dirname($abs);
				if (!is_dir($dir) && !wp_mkdir_p($dir)) {
					$errors[] = ['type' => 'file', 'path' => $rel, 'message' => 'Impossible de créer le dossier'];
					continue;
				}
				$decoded = base64_decode($content_b64, true);
				if ($decoded === false) {
					$errors[] = ['type' => 'file', 'path' => $rel, 'message' => 'base64 invalide'];
					continue;
				}
				if (file_put_contents($abs, $decoded) === false) {
					$errors[] = ['type' => 'file', 'path' => $rel, 'message' => 'Écriture échouée'];
					continue;
				}
			}

			$written[] = $rel;
		}

		foreach ($delete_paths as $rel) {
			$rel = is_string($rel) ? ltrim($rel, '/') : '';
			if (!$rel) {
				continue;
			}
			if (!self::sync_is_safe_rel_path($rel)) {
				$errors[] = ['type' => 'delete', 'path' => $rel, 'message' => 'Chemin interdit'];
				continue;
			}
			if (!$dry_run) {
				$abs = $base_dir . $rel;
				if (file_exists($abs) && is_file($abs) && !unlink($abs)) {
					$errors[] = ['type' => 'delete', 'path' => $rel, 'message' => 'Suppression échouée'];
					continue;
				}
			}
			$deleted[] = $rel;
		}

		$status = $errors ? 207 : 200;
		return new WP_REST_Response([
			'slug' => self::SYNC_SLUG,
			'dry_run' => $dry_run,
			'written' => $written,
			'deleted' => $deleted,
			'errors' => $errors,
		], $status);
	}

	private static function sync_is_safe_rel_path(string $rel): bool {
		if ($rel === '' || strpos($rel, "\0") !== false) {
			return false;
		}
		// Prevent traversal.
		if (strpos($rel, '..') !== false || strncmp($rel, '.', 1) === 0) {
			return false;
		}
		// Only allow a safe set of characters.
		return (bool)preg_match('#^[A-Za-z0-9/_\.-]+$#', $rel);
	}

	private static function sync_collect_files(string $base_dir): array {
		$base_dir = trailingslashit($base_dir);
		$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS));
		$files = [];
		foreach ($rii as $file) {
			/** @var SplFileInfo $file */
			if (!$file->isFile()) {
				continue;
			}
			$abs = $file->getPathname();
			$rel = str_replace($base_dir, '', $abs);
			$rel = str_replace('\\', '/', $rel);

			// Skip runtime/generated.
			if (strpos($rel, '/.DS_Store') !== false || strpos($rel, '/.git') !== false || strpos($rel, '/node_modules/') !== false) {
				continue;
			}
			if (!self::sync_is_safe_rel_path($rel)) {
				continue;
			}
			$content = file_get_contents($abs);
			if ($content === false) {
				continue;
			}
			$files[$rel] = [
				'path' => $rel,
				'size' => strlen($content),
				'sha1' => sha1($content),
			];
		}
		ksort($files);
		return $files;
	}

	private static function get_plugin_version(): string {
		$data = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
		$ver = is_array($data) ? (string)($data['Version'] ?? '') : '';
		return $ver ?: '0.00';
	}

	public static function defaults(): array {
		return [
			'enabled' => 0,
			'client_id' => '',
			'client_secret' => '',
			'redirect_uri' => '',
			'author_urn' => '',
			// Tokens are stored in separate options to avoid any sanitize/filters wiping array fields.
			'access_token' => '',
			'access_token_expires_at' => 0,
			'refresh_token' => '',
			'refresh_token_expires_at' => 0,
			'linkedin_version' => gmdate('Ym'),
			'visibility' => 'PUBLIC',
			'prefix' => '',
			'suffix' => '',
			'include_title' => 1,
			'include_excerpt' => 1,
			'include_url' => 1,
			'content_order' => 'title,excerpt,url',
			'text_template' => '',
			'use_wp_shortlink' => 1,
			'post_type_whitelist' => ['post'],
			'share_on_update' => 0,
			'only_once' => 1,
			'append_utm' => 0,
			'utm_source' => 'linkedin',
			'utm_medium' => 'social',
			'utm_campaign' => 'autopublish',
			'last_author_detect_error' => '',
			// "opengraph" = pas d'upload image : LinkedIn fait le preview depuis l'URL.
			// "upload" = upload featured image via Images API, puis publication via Posts API.
			'media_mode' => 'opengraph',
			'require_image' => 0,
			'last_assets_error' => '',
			'last_assets_error_at' => 0,
			'log_enabled' => 1,
			'last_share_error' => '',
			'last_share_error_at' => 0,
			'last_oauth_at' => 0,
			'last_oauth_token_len' => 0,
			'last_oauth_error' => '',
		];
	}

	public static function get_options(): array {
		$stored = get_option(self::OPT_KEY, []);
		if (!is_array($stored)) {
			$stored = [];
		}
		$opt = array_merge(self::defaults(), $stored);

		// Inject tokens from dedicated options.
		$opt['access_token'] = (string)get_option(self::OPT_TOKEN, '');
		$opt['access_token_expires_at'] = (int)get_option(self::OPT_TOKEN_EXP, 0);
		$opt['refresh_token'] = (string)get_option(self::OPT_REFRESH, '');
		$opt['refresh_token_expires_at'] = (int)get_option(self::OPT_REFRESH_EXP, 0);

		return $opt;
	}

	public static function update_options(array $new): void {
		update_option(self::OPT_KEY, array_merge(self::get_options(), $new));
	}

	private static function set_tokens(array $data): void {
		if (array_key_exists('access_token', $data)) {
			update_option(self::OPT_TOKEN, (string)$data['access_token'], false);
		}
		if (array_key_exists('access_token_expires_at', $data)) {
			update_option(self::OPT_TOKEN_EXP, (int)$data['access_token_expires_at'], false);
		}
		if (array_key_exists('refresh_token', $data)) {
			update_option(self::OPT_REFRESH, (string)$data['refresh_token'], false);
		}
		if (array_key_exists('refresh_token_expires_at', $data)) {
			update_option(self::OPT_REFRESH_EXP, (int)$data['refresh_token_expires_at'], false);
		}
	}

	public static function admin_menu(): void {
		add_menu_page(
			'WP PK SocialSharing',
			'WP PK SocialSharing',
			'manage_options',
			'pk-socialsharing',
			[__CLASS__, 'render_settings_page']
		);
		add_submenu_page(
			'pk-socialsharing',
			'WP PK SocialSharing',
			'Réglages',
			'manage_options',
			'pk-socialsharing',
			[__CLASS__, 'render_settings_page']
		);
	}

	public static function register_settings(): void {
		register_setting('pkliap', self::OPT_KEY, [
			'type' => 'array',
			'sanitize_callback' => [__CLASS__, 'sanitize_options'],
			'default' => self::defaults(),
		]);
	}

	public static function sanitize_options($value): array {
		$defaults = self::defaults();
		$value = is_array($value) ? $value : [];
		$current = self::get_options();

		$out = [];
		// IMPORTANT: la page contient plusieurs formulaires. Quand un champ n'est pas envoyé, conserver la valeur existante.
		$out['enabled'] = array_key_exists('enabled', $value) ? (empty($value['enabled']) ? 0 : 1) : (int)$current['enabled'];
		$out['client_id'] = array_key_exists('client_id', $value) ? sanitize_text_field((string)$value['client_id']) : (string)$current['client_id'];
		$out['client_secret'] = array_key_exists('client_secret', $value) ? sanitize_text_field((string)$value['client_secret']) : (string)$current['client_secret'];
		$out['redirect_uri'] = array_key_exists('redirect_uri', $value) ? esc_url_raw((string)$value['redirect_uri']) : (string)$current['redirect_uri'];
		$out['author_urn'] = array_key_exists('author_urn', $value) ? sanitize_text_field((string)$value['author_urn']) : (string)$current['author_urn'];

		if (array_key_exists('linkedin_version', $value)) {
			$out['linkedin_version'] = preg_match('/^[0-9]{6}$/', (string)$value['linkedin_version']) ? (string)$value['linkedin_version'] : $defaults['linkedin_version'];
		} else {
			$out['linkedin_version'] = (string)$current['linkedin_version'];
		}

		if (array_key_exists('visibility', $value)) {
			$out['visibility'] = in_array((string)$value['visibility'], ['PUBLIC', 'CONNECTIONS', 'LOGGED_IN'], true) ? (string)$value['visibility'] : $defaults['visibility'];
		} else {
			$out['visibility'] = (string)$current['visibility'];
		}

		$out['prefix'] = array_key_exists('prefix', $value) ? sanitize_text_field((string)$value['prefix']) : (string)$current['prefix'];
		$out['suffix'] = array_key_exists('suffix', $value) ? sanitize_text_field((string)$value['suffix']) : (string)$current['suffix'];

		$out['include_title'] = array_key_exists('include_title', $value) ? (empty($value['include_title']) ? 0 : 1) : (int)$current['include_title'];
		$out['include_excerpt'] = array_key_exists('include_excerpt', $value) ? (empty($value['include_excerpt']) ? 0 : 1) : (int)$current['include_excerpt'];
		$out['include_url'] = array_key_exists('include_url', $value) ? (empty($value['include_url']) ? 0 : 1) : (int)$current['include_url'];
		if (array_key_exists('content_order', $value)) {
			$out['content_order'] = self::normalize_content_order((string)$value['content_order']);
		} else {
			$out['content_order'] = self::normalize_content_order((string)$current['content_order']);
		}
		$out['text_template'] = array_key_exists('text_template', $value) ? sanitize_textarea_field((string)$value['text_template']) : (string)$current['text_template'];

		$out['use_wp_shortlink'] = array_key_exists('use_wp_shortlink', $value) ? (empty($value['use_wp_shortlink']) ? 0 : 1) : (int)$current['use_wp_shortlink'];
		$out['share_on_update'] = array_key_exists('share_on_update', $value) ? (empty($value['share_on_update']) ? 0 : 1) : (int)$current['share_on_update'];
		$out['only_once'] = array_key_exists('only_once', $value) ? (empty($value['only_once']) ? 0 : 1) : (int)$current['only_once'];
		$out['append_utm'] = array_key_exists('append_utm', $value) ? (empty($value['append_utm']) ? 0 : 1) : (int)$current['append_utm'];

		$out['utm_source'] = array_key_exists('utm_source', $value) ? sanitize_text_field((string)$value['utm_source']) : (string)$current['utm_source'];
		$out['utm_medium'] = array_key_exists('utm_medium', $value) ? sanitize_text_field((string)$value['utm_medium']) : (string)$current['utm_medium'];
		$out['utm_campaign'] = array_key_exists('utm_campaign', $value) ? sanitize_text_field((string)$value['utm_campaign']) : (string)$current['utm_campaign'];

		$out['log_enabled'] = array_key_exists('log_enabled', $value) ? (empty($value['log_enabled']) ? 0 : 1) : (int)$current['log_enabled'];
		$out['require_image'] = array_key_exists('require_image', $value) ? (empty($value['require_image']) ? 0 : 1) : (int)$current['require_image'];
		if (array_key_exists('media_mode', $value)) {
			$out['media_mode'] = in_array((string)$value['media_mode'], ['opengraph', 'upload'], true) ? (string)$value['media_mode'] : $defaults['media_mode'];
		} else {
			$out['media_mode'] = (string)$current['media_mode'];
		}

		if (array_key_exists('post_type_whitelist', $value)) {
			$post_types = array_filter(array_map('sanitize_key', (array)$value['post_type_whitelist']));
			$out['post_type_whitelist'] = $post_types ? array_values(array_unique($post_types)) : $defaults['post_type_whitelist'];
		} else {
			$out['post_type_whitelist'] = (array)$current['post_type_whitelist'];
		}

		// Ne pas stocker les tokens dans l'option tableau.
		foreach ([
			'last_author_detect_error',
			'last_assets_error',
			'last_assets_error_at',
			'last_share_error',
			'last_share_error_at',
			'last_oauth_at',
			'last_oauth_token_len',
			'last_oauth_error',
		] as $k) {
			$out[$k] = $current[$k];
		}

		return $out;
	}

	private static function admin_url_action(string $action): string {
		return admin_url('admin-post.php?action=' . rawurlencode($action));
	}

	public static function render_settings_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}

		$opt = self::get_options();
		$access_token_present = !empty($opt['access_token']);
		$expires_at = (int)($opt['access_token_expires_at'] ?? 0);
		$token_not_expired = ($expires_at <= 0) ? true : (time() < $expires_at);
		$has_token = $access_token_present && $token_not_expired;

		$post_types = get_post_types(['public' => true], 'objects');

		$link_apps = 'https://www.linkedin.com/developers/apps';
		$link_docs_oauth = 'https://learn.microsoft.com/linkedin/shared/authentication/authorization-code-flow';
		$link_docs_oidc = 'https://learn.microsoft.com/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2';
		$link_docs_ugc = 'https://learn.microsoft.com/linkedin/marketing/community-management/shares/ugc-post-api';
		$link_docs_posts = 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2025-10';
		$link_docs_images = 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/images-api?view=li-lms-2026-02';

		$recommended_redirect_uri = self::admin_url_action('pkliap_oauth_callback');
		$config_redirect_uri = $opt['redirect_uri'] ?: $recommended_redirect_uri;
		$has_client_id = !empty($opt['client_id']);
		$has_client_secret = !empty($opt['client_secret']);
		$redirect_is_recommended = ($config_redirect_uri === $recommended_redirect_uri);
		$has_author_urn = !empty($opt['author_urn']);

		?>
		<div class="wrap">
			<h1>WP PK SocialSharing <span style="font-size:12px;opacity:.7;font-weight:600;">v<?php echo esc_html(self::get_plugin_version()); ?></span></h1>
			<p>Publication automatique sur LinkedIn lors de la mise en ligne d’un article (image mise en avant + extrait + lien).</p>

			<style>
				.pks-modern{
					--pks-card:#fff;
					--pks-text:#0f172a;
					--pks-muted:#64748b;
					--pks-border:#e5e7eb;
					--pks-bg:#f8fafc;
					--pks-radius:10px;
					color:var(--pks-text);
				}
				.pks-grid{display:grid;grid-template-columns:1fr;gap:16px;max-width:none}
				@media (min-width: 980px){ .pks-grid{grid-template-columns:1fr 1fr} }
				.pks-card{
					background:var(--pks-card);
					border:1px solid var(--pks-border);
					border-radius:var(--pks-radius);
					padding:16px;
					box-shadow:0 1px 2px rgba(0,0,0,.03);
				}
				.pks-card--wide{grid-column:1 / -1}
				.pks-card-title{
					font-size:13px;font-weight:700;margin:0 0 14px;padding:0 0 10px;
					border-bottom:1px solid var(--pks-border);display:flex;justify-content:space-between;align-items:center;
				}
				.pks-info{font-size:13px;line-height:1.55;color:var(--pks-muted)}
				.pks-card code{background:#f3f4f6;padding:1px 6px;border-radius:6px;font-size:12px}
				.pks-card .form-table{width:100%;margin:0;table-layout:fixed}
				.pks-card .form-table th{width:clamp(160px,34%,240px)}
				.pks-card .form-table td{min-width:0}
				.pks-card input.regular-text,
				.pks-card textarea,
				.pks-card input[type="text"],
				.pks-card input[type="url"],
				.pks-card input[type="password"]{width:100%;max-width:100%;box-sizing:border-box}
				.pks-card--accent-blue{border-top:4px solid #3b82f6}
				.pks-card--accent-purple{border-top:4px solid #a855f7}
				.pks-card--accent-ok{border-top:4px solid #10b981}
				.pks-card--accent-warn{border-top:4px solid #f59e0b}
				.pks-card--accent-bad{border-top:4px solid #ef4444}
				.pks-actions-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
				.pks-actions-row form{margin:0}
				.pks-pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;background:rgba(0,0,0,.06);font-size:12px}
				.pks-pill--ok{background:rgba(16,185,129,.14)}
				.pks-pill--warn{background:rgba(245,158,11,.16)}
				.pks-pill--bad{background:rgba(239,68,68,.14)}
				.pks-checksplit{display:grid;grid-template-columns:1fr;gap:10px}
				.pks-checklist{display:flex;flex-direction:column;gap:10px;margin:0}
				.pks-checkrow{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid var(--pks-border);border-radius:var(--pks-radius);background:var(--pks-bg)}
				.pks-checkrow strong{display:block;font-size:13px}
				.pks-checkrow p{margin:2px 0 0;font-size:12px;color:var(--pks-muted)}
				.pks-publication-table{border-collapse:separate;border-spacing:0 12px}
				.pks-publication-table tr{background:var(--pks-bg);border:1px solid var(--pks-border)}
				.pks-publication-table th,
				.pks-publication-table td{display:block;width:100%;box-sizing:border-box}
				.pks-publication-table th{padding:12px 14px 0;font-size:13px}
				.pks-publication-table td{padding:10px 14px 14px}
				.pks-publication-table label{display:block;margin:0 0 8px}
				.pks-publication-table .pks-inline{display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap}
				.pks-publication-grid{display:flex;flex-direction:column;gap:12px}
				.pks-text-grid{display:grid;grid-template-columns:1fr;gap:12px}
				.pks-subbox{background:#fff;border:1px solid var(--pks-border);border-radius:8px;padding:10px}
				.pks-subtitle{margin:0 0 8px;font-weight:600;font-size:12px;color:#111827}
				.pks-subbox label{margin:0 0 6px}
				.pks-utm-grid{display:grid;grid-template-columns:1fr;gap:8px;margin-top:8px}
				.pks-utm-field{display:grid;grid-template-columns:120px minmax(0,1fr);gap:10px;align-items:center}
				.pks-utm-field input{width:100%;max-width:none}
				.pks-publication-table tbody{display:block}
				.pks-publication-table tr{display:block;border-radius:10px}
				@media (min-width: 1200px){
					.pks-text-grid{grid-template-columns:1.1fr .9fr}
				}
				@media (min-width: 1080px){
					.pks-checksplit{grid-template-columns:repeat(2,minmax(0,1fr))}
				}
			</style>

			<?php
			$flash = self::get_flash();
			if (!empty($flash['notice'])) {
				echo '<div class="notice notice-info"><p>' . esc_html((string)$flash['notice']) . '</p></div>';
			}
			if (!empty($flash['error'])) {
				echo '<div class="notice notice-error"><p>' . esc_html((string)$flash['error']) . '</p></div>';
			}
			?>

			<div class="pks-modern">
				<div class="pks-grid">
					<div class="pks-card pks-card--accent-warn pks-card--wide">
						<div class="pks-card-title">Connexion (pas-à-pas)</div>
						<div class="pks-checksplit">
							<div class="pks-checklist">
								<div class="pks-checkrow">
									<?php echo $has_client_id ? '<span class="pks-pill pks-pill--ok">OK</span>' : '<span class="pks-pill pks-pill--bad">NON</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong>1) Client ID</strong>
										<p>À copier depuis <a href="<?php echo esc_url($link_apps); ?>" target="_blank" rel="noopener">LinkedIn Developers</a> → ton app → Auth.</p>
									</div>
								</div>
								<div class="pks-checkrow">
									<?php echo $has_client_secret ? '<span class="pks-pill pks-pill--ok">OK</span>' : '<span class="pks-pill pks-pill--bad">NON</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong>2) Client Secret</strong>
										<p>Même écran que le Client ID (Generate/Regenerate si besoin).</p>
									</div>
								</div>
								<div class="pks-checkrow">
									<?php echo $redirect_is_recommended ? '<span class="pks-pill pks-pill--ok">OK</span>' : '<span class="pks-pill pks-pill--warn">WARN</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong>3) Redirect URI (doit matcher LinkedIn)</strong>
										<p>Dans LinkedIn → Auth → “Authorized redirect URLs” : <code><?php echo esc_html($recommended_redirect_uri); ?></code></p>
										<?php if (!$redirect_is_recommended): ?>
											<p>Actuel côté plugin : <code><?php echo esc_html($config_redirect_uri); ?></code></p>
										<?php endif; ?>
									</div>
								</div>
								<div class="pks-checkrow">
									<?php echo $has_token ? '<span class="pks-pill pks-pill--ok">OK</span>' : '<span class="pks-pill pks-pill--bad">NON</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong>4) OAuth (connexion)</strong>
										<p>Clique “Connecter / Reconnecter”, accepte sur LinkedIn, puis reviens ici. Si tu vois “State OAuth invalide” ou “Bummer”, c’est presque toujours un souci de produit/scopes ou de redirect URI.</p>
										<?php if (!empty($opt['last_oauth_error'])): ?>
											<p style="color:#b32d2e;">Dernière erreur OAuth : <?php echo esc_html($opt['last_oauth_error']); ?></p>
										<?php endif; ?>
									</div>
								</div>
								<div class="pks-checkrow">
									<?php echo $has_author_urn ? '<span class="pks-pill pks-pill--ok">OK</span>' : '<span class="pks-pill pks-pill--warn">À FAIRE</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong>5) Author URN (qui poste)</strong>
										<p>Profil : auto-détection après connexion. Page : à renseigner en <code>urn:li:organization:…</code> (nécessite permissions organisations).</p>
									</div>
								</div>
							</div>
							<div class="pks-checklist">
								<div class="pks-checkrow">
									<span class="pks-pill pks-pill--warn">MANUEL</span>
									<div>
										<strong>Pré-requis LinkedIn (à vérifier côté Developers)</strong>
										<p>Products : activer “Share on LinkedIn” (post) et idéalement “Sign In with LinkedIn using OpenID Connect” (pour identifier le membre via <code>/userinfo</code>). Voir : <a href="<?php echo esc_url($link_docs_oidc); ?>" target="_blank" rel="noopener">doc OIDC</a>.</p>
									</div>
								</div>
								<div class="pks-checkrow">
									<?php echo empty($opt['last_assets_error']) ? '<span class="pks-pill pks-pill--warn">À VALIDER</span>' : '<span class="pks-pill pks-pill--bad">BLOQUÉ</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong>Image obligatoire (Images API)</strong>
										<p>Le mode “upload” utilise désormais <code>/rest/images?action=initializeUpload</code> puis <code>/rest/posts</code>. Avec un profil membre, le scope <code>w_member_social</code> suffit normalement.</p>
										<p>Doc : <a href="<?php echo esc_url($link_docs_images); ?>" target="_blank" rel="noopener">Images API</a> et <a href="<?php echo esc_url($link_docs_posts); ?>" target="_blank" rel="noopener">Posts API</a>.</p>
										<?php if (!empty($opt['last_assets_error'])): ?>
											<p style="color:#b32d2e;margin:6px 0 0;">Dernière erreur image : <?php echo esc_html($opt['last_assets_error']); ?></p>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</div>
					</div>

					<form method="post" action="options.php" class="pks-card pks-card--accent-blue">
						<div class="pks-card-title">LinkedIn Developer (App)</div>
						<?php settings_fields('pkliap'); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">Client ID</th>
								<td>
									<input class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[client_id]" value="<?php echo esc_attr($opt['client_id']); ?>"/>
									<p class="description">
										Dans votre app LinkedIn → “Auth”. Lien direct : <a href="<?php echo esc_url($link_apps); ?>" target="_blank" rel="noopener">LinkedIn Developers – My Apps</a>.
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Client Secret</th>
								<td>
									<input class="regular-text" type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[client_secret]" value="<?php echo esc_attr($opt['client_secret']); ?>"/>
									<p class="description">Dans votre app LinkedIn → “Auth” (Generate/Regenerate si besoin).</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Redirect URI</th>
								<td>
									<input class="regular-text" type="url" name="<?php echo esc_attr(self::OPT_KEY); ?>[redirect_uri]" value="<?php echo esc_attr($opt['redirect_uri']); ?>"/>
									<p class="description">
										À coller dans LinkedIn → “Authorized redirect URLs”.
										Recommandé : <code><?php echo esc_html($recommended_redirect_uri); ?></code>
									</p>
									<p class="description">
										Doc : <a href="<?php echo esc_url($link_docs_oauth); ?>" target="_blank" rel="noopener">OAuth Authorization Code Flow</a>.
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">LinkedIn-Version</th>
								<td>
									<input class="regular-text" style="max-width:140px;" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[linkedin_version]" value="<?php echo esc_attr($opt['linkedin_version']); ?>"/>
									<p class="description">Format <code>YYYYMM</code> pour les APIs LinkedIn versionnées (<code>rest/images</code>, <code>rest/posts</code>).</p>
								</td>
							</tr>
						</table>
						<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
					</form>

					<div class="pks-card pks-card--accent-purple">
						<div class="pks-card-title">Compte LinkedIn (qui poste)</div>
						<div class="pks-actions-row" style="margin:-6px 0 12px;">
							<?php echo $has_token ? '<span class="pks-pill pks-pill--ok">Connecté</span>' : '<span class="pks-pill pks-pill--bad">Non connecté</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $access_token_present ? '<span class="pks-pill pks-pill--ok">Token présent</span>' : '<span class="pks-pill pks-pill--bad">Token absent</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ($access_token_present): ?>
								<?php echo $token_not_expired ? '<span class="pks-pill pks-pill--ok">Token OK</span>' : '<span class="pks-pill pks-pill--bad">Token expiré</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endif; ?>
						</div>
						<div class="pks-actions-row" style="margin:0 0 12px;">
							<a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_connect'), 'pkliap_connect')); ?>">Connecter / Reconnecter</a>
							<a class="button" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_disconnect'), 'pkliap_disconnect')); ?>">Déconnecter</a>
							<?php if ($has_token && empty($opt['author_urn'])): ?>
								<a class="button" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_detect_author'), 'pkliap_detect_author')); ?>">Détecter mon profil</a>
							<?php endif; ?>
						</div>
						<?php if ($access_token_present && $expires_at > 0): ?>
							<p class="pks-info" style="margin:-4px 0 12px;">Expiration token : <?php echo esc_html(wp_date('Y-m-d H:i', $expires_at)); ?></p>
						<?php endif; ?>
						<?php if (!empty($opt['last_oauth_at'])): ?>
							<p class="pks-info" style="margin:-6px 0 12px;">
								Dernière connexion OAuth : <?php echo esc_html(wp_date('Y-m-d H:i', (int)$opt['last_oauth_at'])); ?>
								<?php if (!empty($opt['last_oauth_token_len'])): ?>
									( longueur token : <?php echo (int)$opt['last_oauth_token_len']; ?> )
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">Author URN</th>
								<td>
									<input class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[author_urn]" form="pkliap_save_form_proxy" value="<?php echo esc_attr($opt['author_urn']); ?>"/>
									<p class="description">Profil : <code>urn:li:person:…</code> (auto-détection). Page : <code>urn:li:organization:…</code> (manuel).</p>
									<?php if (!empty($opt['last_author_detect_error'])): ?>
										<p class="description" style="color:#b32d2e;">Dernière erreur détection URN : <?php echo esc_html($opt['last_author_detect_error']); ?></p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row">Visibilité</th>
								<td>
									<select name="<?php echo esc_attr(self::OPT_KEY); ?>[visibility]" form="pkliap_save_form_proxy">
										<option value="PUBLIC" <?php selected('PUBLIC', $opt['visibility']); ?>>Public</option>
										<option value="LOGGED_IN" <?php selected('LOGGED_IN', $opt['visibility']); ?>>Connectés</option>
										<option value="CONNECTIONS" <?php selected('CONNECTIONS', $opt['visibility']); ?>>Relations</option>
									</select>
								</td>
							</tr>
						</table>
						<p class="pks-info" style="margin:0;">
							Pré-requis : activer “Share on LinkedIn” (et idéalement OIDC) dans l’app LinkedIn.
							Doc : <a href="<?php echo esc_url($link_docs_oidc); ?>" target="_blank" rel="noopener">OIDC userinfo</a>.
						</p>
					</div>

					<form method="post" action="options.php" class="pks-card pks-card--accent-ok pks-card--wide" id="pkliap_save_form_proxy">
						<div class="pks-card-title">Publication</div>
						<?php settings_fields('pkliap'); ?>
						<table class="form-table pks-publication-table" role="presentation">
							<tbody class="pks-publication-grid">
							<tr>
								<th scope="row">Activer</th>
								<td>
									<label class="pks-inline"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[enabled]" value="1" <?php checked(1, (int)$opt['enabled']); ?>/> Publier automatiquement</label>
									<p class="description">Déclenchement lors du passage en statut <code>publish</code>.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Types de contenu</th>
								<td>
									<?php foreach ($post_types as $pt): ?>
										<label style="display:block;margin:2px 0;">
											<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[post_type_whitelist][]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $opt['post_type_whitelist'], true)); ?>/>
											<?php echo esc_html($pt->labels->singular_name . ' (' . $pt->name . ')'); ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
							<tr>
								<th scope="row">Lien</th>
								<td>
									<label class="pks-inline"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[use_wp_shortlink]" value="1" <?php checked(1, (int)$opt['use_wp_shortlink']); ?>/> Utiliser le shortlink WP</label>
									<label class="pks-inline"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[append_utm]" value="1" <?php checked(1, (int)$opt['append_utm']); ?>/> Ajouter des UTM</label>
									<div class="pks-utm-grid">
										<div class="pks-utm-field">
											<label for="pkliap_utm_source">UTM source</label>
											<input id="pkliap_utm_source" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[utm_source]" value="<?php echo esc_attr($opt['utm_source']); ?>"/>
										</div>
										<div class="pks-utm-field">
											<label for="pkliap_utm_medium">UTM medium</label>
											<input id="pkliap_utm_medium" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[utm_medium]" value="<?php echo esc_attr($opt['utm_medium']); ?>"/>
										</div>
										<div class="pks-utm-field">
											<label for="pkliap_utm_campaign">UTM campaign</label>
											<input id="pkliap_utm_campaign" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[utm_campaign]" value="<?php echo esc_attr($opt['utm_campaign']); ?>"/>
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row">Texte</th>
								<td>
									<div class="pks-text-grid">
										<div class="pks-subbox">
											<p class="pks-subtitle">Composition</p>
											<label class="pks-inline">
												<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[include_title]" value="1" <?php checked(1, (int)$opt['include_title']); ?>/>
												Inclure le titre
											</label>
											<label class="pks-inline">
												<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[include_excerpt]" value="1" <?php checked(1, (int)$opt['include_excerpt']); ?>/>
												Inclure l’extrait
											</label>
											<label class="pks-inline">
												<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[include_url]" value="1" <?php checked(1, (int)$opt['include_url']); ?>/>
												Inclure l’URL
											</label>
										</div>
										<div class="pks-subbox">
											<p class="pks-subtitle">Ordre & Personnalisation</p>
											<label>Ordre du contenu<br/>
												<select name="<?php echo esc_attr(self::OPT_KEY); ?>[content_order]">
													<option value="title,excerpt,url" <?php selected('title,excerpt,url', self::normalize_content_order((string)$opt['content_order'])); ?>>Titre → Extrait → URL (actuel)</option>
													<option value="title,url,excerpt" <?php selected('title,url,excerpt', self::normalize_content_order((string)$opt['content_order'])); ?>>Titre → URL → Extrait</option>
													<option value="url,title,excerpt" <?php selected('url,title,excerpt', self::normalize_content_order((string)$opt['content_order'])); ?>>URL → Titre → Extrait</option>
												</select>
											</label>
											<label>Préfixe<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[prefix]" value="<?php echo esc_attr($opt['prefix']); ?>"/></label>
											<label>Suffixe<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[suffix]" value="<?php echo esc_attr($opt['suffix']); ?>"/></label>
											<label>Template avancé (optionnel)<br/>
												<textarea class="large-text code" rows="4" name="<?php echo esc_attr(self::OPT_KEY); ?>[text_template]" placeholder="{url}{br}{title}{br2}{excerpt}"><?php echo esc_textarea((string)$opt['text_template']); ?></textarea>
											</label>
											<p class="description" style="margin:4px 0 0;">Variables: <code>{prefix}</code>, <code>{title}</code>, <code>{excerpt}</code>, <code>{url}</code>, <code>{suffix}</code>. Sauts: <code>{br}</code> = nouvelle ligne, <code>{br2}</code> = ligne vide. Compatibilité aussi avec <code>/n</code> et <code>/n/n</code>.</p>
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row">Anti-doublon</th>
								<td>
									<label class="pks-inline"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[only_once]" value="1" <?php checked(1, (int)$opt['only_once']); ?>/> Publier une seule fois</label>
									<label class="pks-inline"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[share_on_update]" value="1" <?php checked(1, (int)$opt['share_on_update']); ?>/> Republier lors d’une mise à jour</label>
								</td>
							</tr>
							<tr>
								<th scope="row">Image</th>
								<td>
									<p style="margin:0 0 6px;"><strong>Mode média</strong></p>
									<label class="pks-inline">
										<input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[media_mode]" value="opengraph" <?php checked('opengraph', (string)$opt['media_mode']); ?>/>
										Preview via URL (OpenGraph) — recommandé
									</label>
									<p class="description" style="margin-top:0;">LinkedIn génère l’image depuis <code>og:image</code> de ton article (pas besoin d’upload API).</p>
									<label class="pks-inline">
										<input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[media_mode]" value="upload" <?php checked('upload', (string)$opt['media_mode']); ?>/>
										Upload image mise en avant (Images API)
									</label>
									<p class="description" style="margin-top:0;">Upload via <code>rest/images</code>, puis création du post via <code>rest/posts</code>.</p>
									<label class="pks-inline">
										<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[require_image]" value="1" <?php checked(1, (int)$opt['require_image']); ?>/>
										Image obligatoire (si pas d’image/si upload refusé → erreur)
									</label>
								</td>
							</tr>
							</tbody>
						</table>
						<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
					</form>

					<div class="pks-card pks-card--wide">
						<div class="pks-card-title">Test</div>
						<p class="pks-info" style="margin:0 0 12px;">Choisissez un article publié pour tenter un partage LinkedIn.</p>
						<?php
						$posts = get_posts([
							'post_type' => $opt['post_type_whitelist'],
							'post_status' => 'publish',
							'numberposts' => 20,
							'orderby' => 'date',
							'order' => 'DESC',
						]);
						?>
						<table class="widefat striped" style="max-width: 980px;">
							<thead>
								<tr>
									<th style="width:60px;">ID</th>
									<th style="width:64px;">Image</th>
									<th>Article</th>
									<th style="width:220px;">Statut LinkedIn</th>
									<th style="width:180px;">Action</th>
								</tr>
							</thead>
							<tbody>
							<?php if (!$posts): ?>
								<tr><td colspan="5">Aucun article publié trouvé.</td></tr>
							<?php else: ?>
								<?php foreach ($posts as $p): ?>
									<?php
									$shared_at = (int)get_post_meta($p->ID, self::META_SHARED_AT, true);
									$share_urn = (string)get_post_meta($p->ID, self::META_SHARE_URN, true);
									$share_url = self::linkedin_share_url_from_urn($share_urn);
									$status = $shared_at ? ('Partagé le ' . esc_html(wp_date('Y-m-d H:i', $shared_at)) . ($share_urn ? '<br/><code style="font-size:11px;">' . esc_html($share_urn) . '</code>' : '') . ($share_url ? '<br/><a href="' . esc_url($share_url) . '" target="_blank" rel="noopener">Voir sur LinkedIn</a>' : '')) : 'Jamais partagé';
									$action_url = wp_nonce_url(self::admin_url_action('pkliap_test_post') . '&post_id=' . (int)$p->ID, 'pkliap_test_post_' . (int)$p->ID);
									$edit_url = get_edit_post_link($p->ID, '');
									$thumb_html = get_the_post_thumbnail($p->ID, [48, 48], ['style' => 'width:48px;height:48px;object-fit:cover;border-radius:4px;']);
									?>
									<tr>
										<td><?php echo (int)$p->ID; ?></td>
										<td><?php echo $thumb_html ?: '<span style="opacity:.6;">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
										<td>
											<strong><?php echo esc_html($p->post_title ?: '(Sans titre)'); ?></strong>
											<div class="row-actions">
												<span><a href="<?php echo esc_url(get_permalink($p->ID)); ?>" target="_blank" rel="noopener">Voir</a> | </span>
												<span><a href="<?php echo esc_url($edit_url ?: '#'); ?>">Modifier</a></span>
											</div>
										</td>
										<td><?php echo $status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
										<td><a class="button button-secondary" href="<?php echo esc_url($action_url); ?>">Publier maintenant</a></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody>
						</table>
					</div>

					<div class="pks-card pks-card--accent-warn pks-card--wide">
						<div class="pks-card-title">Debug / Logs</div>
						<form method="post" action="options.php">
							<?php settings_fields('pkliap'); ?>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">Logs</th>
									<td>
										<label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[log_enabled]" value="1" <?php checked(1, (int)$opt['log_enabled']); ?>/> Activer <code>error_log()</code></label>
										<p class="description">Utile pour diagnostiquer (Wordfence/cache/scopes). Désactive si tu ne veux aucun log côté serveur.</p>
									</td>
								</tr>
								<tr>
									<th scope="row">Dernière erreur partage</th>
									<td>
										<?php if (!empty($opt['last_share_error'])): ?>
											<p class="description" style="color:#b32d2e;margin:0;">
												<?php echo esc_html($opt['last_share_error']); ?>
												<?php if (!empty($opt['last_share_error_at'])): ?>
													<br/>Le <?php echo esc_html(wp_date('Y-m-d H:i', (int)$opt['last_share_error_at'])); ?>
												<?php endif; ?>
											</p>
										<?php else: ?>
											<p class="description" style="margin:0;">—</p>
										<?php endif; ?>
									</td>
								</tr>
							</table>
							<?php submit_button('Enregistrer', 'secondary', 'submit', false); ?>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public static function handle_connect(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_connect');

		$opt = self::get_options();
		if (empty($opt['client_id']) || empty($opt['client_secret'])) {
			self::set_flash('error', 'Renseignez Client ID / Client Secret avant de connecter.');
			wp_safe_redirect(self::settings_url());
			exit;
		}

		$redirect_uri = $opt['redirect_uri'] ?: self::admin_url_action('pkliap_oauth_callback');

		$state = wp_generate_password(24, false, false);
		$user_id = get_current_user_id();
		// Stocker un state par utilisateur avec expiration pour éviter les collisions (multi-tabs / multi-admins / cache).
		set_transient('pkliap_oauth_state_' . $user_id, $state, 10 * MINUTE_IN_SECONDS);

		// LinkedIn migre vers OpenID Connect. Pour éviter les erreurs de scopes non autorisés,
		// on utilise OIDC pour identifier le membre (userinfo) + w_member_social pour poster.
		$scope = [
			'openid',
			'profile',
			'email',
			'w_member_social',
		];

		$args = [
			'response_type' => 'code',
			'client_id' => $opt['client_id'],
			'redirect_uri' => $redirect_uri,
			'state' => $state,
			'scope' => implode(' ', $scope),
		];

		$auth_url = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
		wp_redirect($auth_url);
		exit;
	}

	public static function handle_oauth_callback(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		// Pas de nonce ici (appel externe). On vérifie state.
		$state = isset($_GET['state']) ? sanitize_text_field((string)wp_unslash($_GET['state'])) : '';
		$user_id = get_current_user_id();
		$expected_state = (string)get_transient('pkliap_oauth_state_' . $user_id);
		delete_transient('pkliap_oauth_state_' . $user_id);

		if (!$state || !$expected_state || !hash_equals($expected_state, $state)) {
			self::set_flash('error', 'State OAuth invalide.');
			wp_safe_redirect(self::settings_url());
			exit;
		}

		$code = isset($_GET['code']) ? sanitize_text_field((string)wp_unslash($_GET['code'])) : '';
		if (!$code) {
			self::set_flash('error', 'Code OAuth manquant.');
			wp_safe_redirect(self::settings_url());
			exit;
		}

		$opt = self::get_options();
		$redirect_uri = $opt['redirect_uri'] ?: self::admin_url_action('pkliap_oauth_callback');

		$token = self::linkedin_exchange_code_for_token($opt['client_id'], $opt['client_secret'], $redirect_uri, $code);
		if (is_wp_error($token)) {
			self::update_options([
				'last_oauth_error' => $token->get_error_message(),
			]);
			self::set_flash('error', $token->get_error_message());
			wp_safe_redirect(self::settings_url());
			exit;
		}

		$access_token = (string)($token['access_token'] ?? '');
		$expires_in = (int)($token['expires_in'] ?? 0);
		$refresh_token = (string)($token['refresh_token'] ?? '');
		$refresh_expires_in = (int)($token['refresh_token_expires_in'] ?? 0);

		if (!$access_token) {
			self::update_options([
				'last_oauth_error' => 'Réponse token invalide (access_token manquant).',
			]);
			self::set_flash('error', 'Réponse token invalide (access_token manquant).');
			wp_safe_redirect(self::settings_url());
			exit;
		}

		$expires_at = $expires_in ? (time() + $expires_in - 60) : 0;
		$refresh_expires_at = $refresh_expires_in ? (time() + $refresh_expires_in - 60) : 0;

		self::set_tokens([
			'access_token' => $access_token,
			'access_token_expires_at' => $expires_at,
			'refresh_token' => $refresh_token,
			'refresh_token_expires_at' => $refresh_expires_at,
		]);
		self::update_options([
			'last_author_detect_error' => '',
			'last_oauth_at' => time(),
			'last_oauth_token_len' => strlen($access_token),
			'last_oauth_error' => '',
		]);

		$notice = 'Connecté à LinkedIn.';
		$error = '';

		// Auto-détection de l'URN profil (urn:li:person:...) pour éviter une config manuelle.
		$opt_after = self::get_options();
		// Sanity check: si le token n'est pas stocké, on remonte une erreur explicite.
		if (empty($opt_after['access_token'])) {
			self::update_options([
				'last_oauth_error' => 'Token non persisté après OAuth. Suspect: plugin de sécurité/cache, object-cache, ou restriction base de données.',
			]);
			self::set_flash('error', 'Connecté, mais le token n’a pas été persisté. Désactive temporairement Wordfence/cache, puis reconnecte.');
			wp_safe_redirect(self::settings_url());
			exit;
		}
		if (empty($opt_after['author_urn'])) {
			$me_id = self::linkedin_get_member_id($access_token);
			if (is_wp_error($me_id)) {
				$detect_error = $me_id->get_error_message();
				self::update_options([
					'last_author_detect_error' => $detect_error,
				]);
				$error = 'Connecté, mais impossible de détecter automatiquement le profil LinkedIn (Author URN). ' . $detect_error;
			} else {
				self::update_options([
					'author_urn' => 'urn:li:person:' . $me_id,
					'last_author_detect_error' => '',
				]);
				$notice = 'Connecté à LinkedIn. Author URN détecté automatiquement (profil).';
			}
		}

		if ($error) {
			self::set_flash('error', $error);
		} else {
			self::set_flash('notice', $notice);
		}
		wp_safe_redirect(self::settings_url());
		exit;
	}

	public static function handle_disconnect(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_disconnect');

		self::set_tokens([
			'access_token' => '',
			'access_token_expires_at' => 0,
			'refresh_token' => '',
			'refresh_token_expires_at' => 0,
		]);
		self::update_options([
			'author_urn' => '',
			'last_author_detect_error' => '',
			'last_share_error' => '',
			'last_share_error_at' => 0,
			'last_oauth_error' => '',
		]);

		self::set_flash('notice', 'Déconnecté.');
		wp_safe_redirect(self::settings_url());
		exit;
	}

	public static function handle_detect_author(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_detect_author');

		$opt = self::get_options();
		if (empty($opt['access_token'])) {
			self::set_flash('error', 'Non connecté à LinkedIn (token manquant).');
			wp_safe_redirect(self::settings_url());
			exit;
		}

		$me_id = self::linkedin_get_member_id($opt['access_token']);
		if (is_wp_error($me_id)) {
			self::update_options([
				'last_author_detect_error' => $me_id->get_error_message(),
			]);
			self::set_flash('error', 'Impossible de détecter automatiquement le profil LinkedIn. ' . $me_id->get_error_message());
			wp_safe_redirect(self::settings_url());
			exit;
		}

		self::update_options([
			'author_urn' => 'urn:li:person:' . $me_id,
			'last_author_detect_error' => '',
		]);

		self::set_flash('notice', 'Author URN détecté automatiquement (profil).');
		wp_safe_redirect(self::settings_url());
		exit;
	}

	public static function handle_test_post(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		$post_id = isset($_REQUEST['post_id']) ? (int)$_REQUEST['post_id'] : 0;
		if (!$post_id) {
			self::set_flash('error', 'post_id manquant.');
			wp_safe_redirect(self::settings_url());
			exit;
		}
		check_admin_referer('pkliap_test_post_' . $post_id);

		try {
			$res = self::share_post_to_linkedin($post_id, true);
		} catch (Throwable $e) {
			$msg = 'Exception PHP: ' . get_class($e) . ' - ' . $e->getMessage();
			self::update_options([
				'last_share_error' => $msg,
				'last_share_error_at' => time(),
			]);
			$opt_now = self::get_options();
			if (!empty($opt_now['log_enabled'])) {
				error_log('[pkliap] ' . $msg);
				error_log('[pkliap] ' . $e->getTraceAsString());
			}
			self::set_flash('error', $msg);
			wp_safe_redirect(self::settings_url());
			exit;
		}
		if (is_wp_error($res)) {
			self::update_options([
				'last_share_error' => $res->get_error_message(),
				'last_share_error_at' => time(),
			]);
			self::set_flash('error', $res->get_error_message());
			wp_safe_redirect(self::settings_url());
			exit;
		}

		self::update_options([
			'last_share_error' => '',
			'last_share_error_at' => 0,
		]);
		$notice = 'Post LinkedIn envoyé.';
		if (is_array($res) && !empty($res['warning'])) {
			$notice .= ' ' . (string)$res['warning'];
		}
		self::set_flash('notice', $notice);
		wp_safe_redirect(self::settings_url());
		exit;
	}

	public static function on_transition_post_status(string $new_status, string $old_status, WP_Post $post): void {
		if ($new_status !== 'publish') {
			return;
		}

		$opt = self::get_options();
		if (empty($opt['enabled'])) {
			return;
		}

		if (!in_array($post->post_type, (array)$opt['post_type_whitelist'], true)) {
			return;
		}

		$is_update = ($old_status === 'publish');
		if ($is_update && empty($opt['share_on_update'])) {
			return;
		}

		if (!empty($opt['only_once']) && get_post_meta($post->ID, self::META_SHARED_AT, true) && empty($opt['share_on_update'])) {
			return;
		}

		// Éviter de bloquer la publication : on fait une tentative mais on ne stoppe pas WP.
		try {
			$res = self::share_post_to_linkedin($post->ID, false);
		} catch (Throwable $e) {
			$msg = 'Exception PHP: ' . get_class($e) . ' - ' . $e->getMessage();
			self::update_options([
				'last_share_error' => $msg,
				'last_share_error_at' => time(),
			]);
			if (!empty($opt['log_enabled'])) {
				error_log('[pkliap] ' . $msg);
				error_log('[pkliap] ' . $e->getTraceAsString());
			}
			return;
		}
		if (is_wp_error($res)) {
			self::update_options([
				'last_share_error' => $res->get_error_message(),
				'last_share_error_at' => time(),
			]);
			if (!empty($opt['log_enabled'])) {
				error_log('[pkliap] LinkedIn share failed for post #' . $post->ID . ': ' . $res->get_error_message());
			}
		} else {
			self::update_options([
				'last_share_error' => '',
				'last_share_error_at' => 0,
			]);
			if (is_array($res) && !empty($res['warning']) && !empty($opt['log_enabled'])) {
				error_log('[pkliap] LinkedIn share warning for post #' . $post->ID . ': ' . (string)$res['warning']);
			}
		}
	}

	/** @return array|WP_Error */
	private static function share_post_to_linkedin(int $post_id, bool $force) {
		$post = get_post($post_id);
		if (!$post || $post->post_status !== 'publish') {
			return new WP_Error('pkliap_invalid_post', 'Article non publié.');
		}

		$opt = self::get_options();
		if (empty($opt['access_token'])) {
			return new WP_Error('pkliap_no_token', 'Non connecté à LinkedIn (token manquant).');
		}
		if (empty($opt['author_urn'])) {
			return new WP_Error('pkliap_no_author', 'Author URN manquant (urn:li:person:* ou urn:li:organization:*).');
		}

		if (!$force && !empty($opt['only_once'])) {
			$already = get_post_meta($post_id, self::META_SHARED_AT, true);
			if ($already && empty($opt['share_on_update'])) {
				return ['skipped' => true];
			}
		}

		$link = self::get_post_link($post_id, $opt);
		$text = self::build_linkedin_text($post_id, $opt, $link);

		$warn_no_image = '';
		$media_mode = (string)($opt['media_mode'] ?? 'opengraph');

		$image_urn = '';
		$thumb_id = get_post_thumbnail_id($post_id);

		if ($media_mode === 'upload') {
			if (!empty($opt['require_image']) && !$thumb_id) {
				return new WP_Error('pkliap_image_required', 'Image obligatoire : ajoute une image mise en avant (Featured image) sur cet article.');
			}
			if ($thumb_id) {
				$image_urn_res = self::upload_featured_image($thumb_id, $opt);
				if (is_wp_error($image_urn_res)) {
					$msg = $image_urn_res->get_error_message();
					self::update_options([
						'last_assets_error' => $msg,
						'last_assets_error_at' => time(),
					]);
					if (!empty($opt['require_image'])) {
						return $image_urn_res;
					}
					$warn_no_image = 'Post publié sans image (upload LinkedIn indisponible).';
				} elseif ($image_urn_res) {
					$image_urn = (string)$image_urn_res;
					self::update_options([
						'last_assets_error' => '',
						'last_assets_error_at' => 0,
					]);
				}
			}

			if ($image_urn) {
				$res = self::linkedin_create_image_post($post_id, $text, $image_urn, $opt);
				if (is_wp_error($res)) {
					return $res;
				}

				$urn = '';
				if (isset($res['headers']['x-restli-id'])) {
					$urn = (string)$res['headers']['x-restli-id'];
				}
				if (!$urn && isset($res['body']['id'])) {
					$urn = (string)$res['body']['id'];
				}

				update_post_meta($post_id, self::META_SHARED_AT, time());
				if ($urn) {
					update_post_meta($post_id, self::META_SHARE_URN, $urn);
				}

				return [
					'urn' => $urn,
					'warning' => $warn_no_image,
				];
			}
		} else {
			// mode opengraph: LinkedIn fait l'unfurl de l'URL (og:image).
			if (!empty($opt['require_image'])) {
				// On ne peut pas forcer LinkedIn à afficher le preview, mais on peut au moins exiger une featured image côté WP.
				if (!$thumb_id) {
					return new WP_Error('pkliap_image_required', 'Image obligatoire : ajoute une image mise en avant (Featured image) sur cet article.');
				}
			}
		}

		$payload = [
			'author' => $opt['author_urn'],
			'lifecycleState' => 'PUBLISHED',
			'specificContent' => [
				'com.linkedin.ugc.ShareContent' => [
					'shareCommentary' => [
						'text' => $text,
					],
					'shareMediaCategory' => (($media_mode === 'opengraph') ? 'ARTICLE' : 'NONE'),
				],
			],
			'visibility' => [
				'com.linkedin.ugc.MemberNetworkVisibility' => $opt['visibility'],
			],
		];

		if ($media_mode === 'opengraph') {
			// Laisse LinkedIn générer un aperçu depuis l'URL (OpenGraph).
			$payload['specificContent']['com.linkedin.ugc.ShareContent']['media'] = [[
				'status' => 'READY',
				'originalUrl' => $link,
				'title' => ['text' => get_the_title($post_id)],
				'description' => ['text' => self::safe_excerpt($post_id, 200)],
			]];
		}

		$res = self::linkedin_api_post('/v2/ugcPosts', $payload, $opt['access_token'], [
			'X-Restli-Protocol-Version' => '2.0.0',
		]);

		if (is_wp_error($res)) {
			return $res;
		}

		$urn = '';
		if (isset($res['headers']['x-restli-id'])) {
			$urn = (string)$res['headers']['x-restli-id'];
		}
		if (!$urn && isset($res['body']['id'])) {
			$urn = (string)$res['body']['id'];
		}

		update_post_meta($post_id, self::META_SHARED_AT, time());
		if ($urn) {
			update_post_meta($post_id, self::META_SHARE_URN, $urn);
		}

		return [
			'urn' => $urn,
			'warning' => $warn_no_image,
		];
	}

	private static function settings_url(array $args = []): string {
		$url = admin_url('admin.php?page=pk-socialsharing');
		if ($args) {
			$url = add_query_arg($args, $url);
		}
		return $url;
	}

	private static function linkedin_share_url_from_urn(string $urn): string {
		$urn = trim($urn);
		if ($urn === '' || strpos($urn, 'urn:li:') !== 0) {
			return '';
		}
		return 'https://www.linkedin.com/feed/update/' . rawurlencode($urn) . '/';
	}

	private static function get_post_link(int $post_id, array $opt): string {
		$url = '';
		if (!empty($opt['use_wp_shortlink'])) {
			$url = (string)wp_get_shortlink($post_id);
		}
		if (!$url) {
			$url = get_permalink($post_id);
		}
		if (!empty($opt['append_utm'])) {
			$url = add_query_arg([
				'utm_source' => $opt['utm_source'],
				'utm_medium' => $opt['utm_medium'],
				'utm_campaign' => $opt['utm_campaign'],
			], $url);
		}
		return $url;
	}

	private static function build_linkedin_text(int $post_id, array $opt, string $link): string {
		$title = wp_strip_all_tags(get_the_title($post_id));
		$excerpt = self::safe_excerpt($post_id, 260);
		$template = (string)($opt['text_template'] ?? '');

		if (trim($template) !== '') {
			$normalized_template = str_replace(["\r\n", "\r"], "\n", $template);
			$normalized_template = str_replace(['/n/n', '/n'], ["\n\n", "\n"], $normalized_template);
			$normalized_template = str_replace(['{br2}', '{br}'], ["\n\n", "\n"], $normalized_template);
			$tokens = [
				'{prefix}' => (string)$opt['prefix'],
				'{title}' => !empty($opt['include_title']) ? $title : '',
				'{excerpt}' => !empty($opt['include_excerpt']) ? $excerpt : '',
				'{url}' => !empty($opt['include_url']) ? $link : '',
				'{suffix}' => (string)$opt['suffix'],
			];
			$text = strtr($normalized_template, $tokens);
			$text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
			return self::mb_truncate(trim($text), 2800);
		}

		$parts = [];
		if ($opt['prefix']) {
			$parts[] = $opt['prefix'];
		}

		$ordered = self::normalize_content_order((string)($opt['content_order'] ?? 'title,excerpt,url'));
		$order = array_filter(array_map('trim', explode(',', $ordered)), static fn($v) => $v !== '');
		foreach ($order as $token) {
			if ($token === 'title' && !empty($opt['include_title']) && $title !== '') {
				$parts[] = $title;
			}
			if ($token === 'excerpt' && !empty($opt['include_excerpt']) && $excerpt !== '') {
				$parts[] = $excerpt;
			}
			if ($token === 'url' && !empty($opt['include_url']) && $link !== '') {
				$parts[] = $link;
			}
		}

		if ($opt['suffix']) {
			$parts[] = $opt['suffix'];
		}

		$text = trim(implode("\n\n", array_filter($parts, static fn($p) => (string)$p !== '')));
		return self::mb_truncate($text, 2800);
	}

	private static function normalize_content_order(string $raw): string {
		$allowed = ['title', 'excerpt', 'url'];
		$tokens = array_filter(array_map(static fn($v) => strtolower(trim((string)$v)), explode(',', $raw)), static fn($v) => in_array($v, $allowed, true));
		$unique = [];
		foreach ($tokens as $token) {
			if (!in_array($token, $unique, true)) {
				$unique[] = $token;
			}
		}
		foreach ($allowed as $token) {
			if (!in_array($token, $unique, true)) {
				$unique[] = $token;
			}
		}
		return implode(',', $unique);
	}

	private static function safe_excerpt(int $post_id, int $max_len): string {
		$post = get_post($post_id);
		if (!$post) {
			return '';
		}
		$excerpt = (string)get_the_excerpt($post);
		$excerpt = wp_strip_all_tags($excerpt);
		$excerpt = preg_replace('/\s+/', ' ', $excerpt ?? '') ?? '';
		$excerpt = trim($excerpt);
		return self::mb_truncate($excerpt, $max_len);
	}

	private static function build_linkedin_image_alt_text(int $post_id): string {
		$title = wp_strip_all_tags(get_the_title($post_id));
		$excerpt = self::safe_excerpt($post_id, 90);
		$parts = array_filter([$title, $excerpt], static fn($part) => (string)$part !== '');
		return self::mb_truncate(implode(' - ', $parts), 120);
	}

	private static function mb_truncate(string $s, int $max): string {
		if ($max <= 0) {
			return '';
		}
		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			if (mb_strlen($s) <= $max) {
				return $s;
			}
			return rtrim(mb_substr($s, 0, max(0, $max - 1))) . '…';
		}
		if (strlen($s) <= $max) {
			return $s;
		}
		return rtrim(substr($s, 0, max(0, $max - 1))) . '…';
	}

	/** @return array|WP_Error */
	private static function linkedin_create_image_post(int $post_id, string $text, string $image_urn, array $opt) {
		$payload = [
			'author' => $opt['author_urn'],
			'commentary' => $text,
			'visibility' => $opt['visibility'],
			'distribution' => [
				'feedDistribution' => 'MAIN_FEED',
				'targetEntities' => [],
				'thirdPartyDistributionChannels' => [],
			],
			'content' => [
				'media' => [
					'altText' => self::build_linkedin_image_alt_text($post_id),
					'id' => $image_urn,
				],
			],
			'lifecycleState' => 'PUBLISHED',
			'isReshareDisabledByAuthor' => false,
		];

		return self::linkedin_api_post_versioned('/rest/posts', $payload, $opt['access_token'], (string)$opt['linkedin_version']);
	}

	/** @return string|WP_Error */
	private static function upload_featured_image(int $attachment_id, array $opt) {
		$file = get_attached_file($attachment_id);
		if (!$file || !file_exists($file)) {
			return new WP_Error('pkliap_no_image', 'Image mise en avant introuvable.');
		}

		$mime = get_post_mime_type($attachment_id);
		if (!$mime) {
			$mime = 'image/jpeg';
		}

		$register_payload = [
			'initializeUploadRequest' => [
				'owner' => $opt['author_urn'],
			],
		];

		$register = self::linkedin_api_post_versioned('/rest/images?action=initializeUpload', $register_payload, $opt['access_token'], (string)$opt['linkedin_version']);
		if (is_wp_error($register)) {
			return $register;
		}

		$image_urn = (string)($register['body']['value']['image'] ?? '');
		$upload_url = (string)($register['body']['value']['uploadUrl'] ?? '');
		if (!$image_urn || !$upload_url) {
			return new WP_Error('pkliap_register_upload_failed', 'Impossible de récupérer image/uploadUrl depuis initializeUpload.');
		}

		$upload_headers = [
			'Authorization' => 'Bearer ' . $opt['access_token'],
			'Content-Type' => $mime,
		];

		$put = wp_remote_request($upload_url, [
			'method' => 'PUT',
			'timeout' => 45,
			'headers' => $upload_headers,
			'body' => file_get_contents($file),
		]);

		if (is_wp_error($put)) {
			return $put;
		}

		$code = (int)wp_remote_retrieve_response_code($put);
		if ($code < 200 || $code >= 300) {
			return new WP_Error('pkliap_upload_failed', 'Upload image LinkedIn échoué (HTTP ' . $code . ').');
		}

		$wait = self::wait_for_linkedin_image($image_urn, $opt);
		if (is_wp_error($wait)) {
			return $wait;
		}

		return $image_urn;
	}

	private static function linkedin_version_header(string $v): string {
		$raw = trim($v);
		// LinkedIn attend YYYYMM ou YYYYMM.RR (revision). On normalise ce que l'admin saisit.
		if (preg_match('/^[0-9]{6}\\.[0-9]{2}$/', $raw)) {
			return $raw;
		}
		$digits = preg_replace('/[^0-9]/', '', $raw) ?? '';
		if (preg_match('/^[0-9]{6}$/', $digits)) {
			return $digits; // YYYYMM
		}
		if (preg_match('/^[0-9]{8}$/', $digits)) {
			return substr($digits, 0, 6); // YYYYMM (drop day)
		}
		return gmdate('Ym');
	}

	/** @return array|WP_Error */
	private static function linkedin_api_post_versioned(string $path, array $payload, string $access_token, string $linkedin_version) {
		$version = self::linkedin_version_header($linkedin_version);
		$last_res = null;

		// LinkedIn active parfois les versions avec décalage.
		// On tente la version configurée puis jusqu'à 6 mois en arrière en cas de HTTP 426.
		for ($i = 0; $i < 7; $i++) {
			if (!$version) {
				break;
			}

			$res = self::linkedin_api_post($path, $payload, $access_token, [
				'Linkedin-Version' => $version,
				'X-Restli-Protocol-Version' => '2.0.0',
			]);
			$last_res = $res;

			if (!is_wp_error($res)) {
				if ($version !== self::linkedin_version_header($linkedin_version)) {
					self::update_options([
						'linkedin_version' => substr($version, 0, 6),
					]);
				}
				return $res;
			}

			$is_426 = $res->get_error_code() === 'pkliap_api_error' && strpos($res->get_error_message(), 'HTTP 426') !== false;
			if (!$is_426) {
				return $res;
			}

			$version = self::linkedin_prev_month_version($version);
		}

		return $last_res instanceof WP_Error ? $last_res : new WP_Error('pkliap_api_error', 'Erreur API LinkedIn : aucune version active trouvée.');
	}

	/** @return array|WP_Error */
	private static function linkedin_get_image(string $image_urn, string $access_token, string $linkedin_version, bool $versioned = true) {
		$headers = [
			'X-Restli-Protocol-Version' => '2.0.0',
		];
		if ($versioned) {
			$headers['Linkedin-Version'] = self::linkedin_version_header($linkedin_version);
		}
		return self::linkedin_api_get('/rest/images/' . rawurlencode($image_urn), $access_token, $headers);
	}

	/** @return true|WP_Error */
	private static function wait_for_linkedin_image(string $image_urn, array $opt) {
		$last_error = null;

		for ($attempt = 0; $attempt < 6; $attempt++) {
			$status_res = self::linkedin_get_image($image_urn, (string)$opt['access_token'], (string)$opt['linkedin_version'], true);
			if (
				is_wp_error($status_res)
				&& strpos($status_res->get_error_message(), 'HTTP 403') !== false
			) {
				$status_res = self::linkedin_get_image($image_urn, (string)$opt['access_token'], (string)$opt['linkedin_version'], false);
			}

			if (is_wp_error($status_res)) {
				$last_error = $status_res;
			} else {
				$status = (string)($status_res['body']['status'] ?? '');
				if ($status === 'AVAILABLE') {
					return true;
				}
				if ($status === 'PROCESSING_FAILED') {
					return new WP_Error('pkliap_image_processing_failed', 'LinkedIn a reçu l’image mais son traitement a échoué.');
				}
			}

			if ($attempt < 5) {
				sleep(2);
			}
		}

		if ($last_error instanceof WP_Error) {
			return $last_error;
		}

		return new WP_Error('pkliap_image_processing_timeout', 'LinkedIn a reçu l’image mais elle n’est pas encore disponible. Réessaie dans quelques secondes.');
	}

	private static function linkedin_prev_month_version(string $version_header): string {
		$raw = trim($version_header);
		$digits = preg_replace('/[^0-9]/', '', $raw) ?? '';
		if (!preg_match('/^([0-9]{4})([0-9]{2})/', $digits, $m)) {
			return '';
		}
		$y = (int)$m[1];
		$mo = (int)$m[2];
		$mo--;
		if ($mo <= 0) {
			$mo = 12;
			$y--;
		}
		// Keep simple YYYYMM (most compatible).
		return sprintf('%04d%02d', $y, $mo);
	}

	private static function flash_key(): string {
		return 'pkliap_flash_' . (int)get_current_user_id();
	}

	private static function set_flash(string $type, string $message): void {
		if (!in_array($type, ['notice', 'error'], true)) {
			return;
		}
		$data = get_transient(self::flash_key());
		if (!is_array($data)) {
			$data = [];
		}
		$data[$type] = $message;
		set_transient(self::flash_key(), $data, 60);
	}

	private static function get_flash(): array {
		$data = get_transient(self::flash_key());
		delete_transient(self::flash_key());
		return is_array($data) ? $data : [];
	}

	/** @return array|WP_Error */
	private static function linkedin_exchange_code_for_token(string $client_id, string $client_secret, string $redirect_uri, string $code) {
		$res = wp_remote_post('https://www.linkedin.com/oauth/v2/accessToken', [
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			'body' => [
				'grant_type' => 'authorization_code',
				'code' => $code,
				'redirect_uri' => $redirect_uri,
				'client_id' => $client_id,
				'client_secret' => $client_secret,
			],
		]);

		if (is_wp_error($res)) {
			return $res;
		}

		$code_http = (int)wp_remote_retrieve_response_code($res);
		$body_raw = (string)wp_remote_retrieve_body($res);
		$body = json_decode($body_raw, true);

		if ($code_http < 200 || $code_http >= 300) {
			$msg = 'Erreur token LinkedIn (HTTP ' . $code_http . ').';
			if (is_array($body) && !empty($body['error_description'])) {
				$msg .= ' ' . (string)$body['error_description'];
			}
			return new WP_Error('pkliap_token_failed', $msg);
		}

		if (!is_array($body)) {
			return new WP_Error('pkliap_token_failed', 'Réponse token LinkedIn non-JSON.');
		}

		return $body;
	}

	/** @return array|WP_Error */
	private static function linkedin_api_post(string $path, array $payload, string $access_token, array $extra_headers = []) {
		$url = 'https://api.linkedin.com' . $path;
		$res = wp_remote_post($url, [
			'timeout' => 45,
			'headers' => array_merge([
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type' => 'application/json',
			], $extra_headers),
			'body' => wp_json_encode($payload),
		]);

		if (is_wp_error($res)) {
			return $res;
		}

		$code = (int)wp_remote_retrieve_response_code($res);
		$body_raw = (string)wp_remote_retrieve_body($res);
		$body = json_decode($body_raw, true);

		$headers = [];
		if (function_exists('wp_remote_retrieve_headers')) {
			$hdrs = wp_remote_retrieve_headers($res);
			if ($hdrs) {
				foreach ($hdrs as $k => $v) {
					$headers[strtolower((string)$k)] = is_array($v) ? implode(',', $v) : (string)$v;
				}
			}
		}

		if ($code < 200 || $code >= 300) {
			$msg = 'Erreur API LinkedIn (HTTP ' . $code . ').';
			if (is_array($body) && !empty($body['message'])) {
				$msg .= ' ' . (string)$body['message'];
			} elseif (is_array($body) && !empty($body['error_description'])) {
				$msg .= ' ' . (string)$body['error_description'];
			}
			return new WP_Error('pkliap_api_error', $msg);
		}

		return [
			'code' => $code,
			'headers' => $headers,
			'body' => is_array($body) ? $body : [],
		];
	}

	/** @return array|WP_Error */
	private static function linkedin_api_get(string $path, string $access_token, array $extra_headers = []) {
		$url = 'https://api.linkedin.com' . $path;
		$res = wp_remote_get($url, [
			'timeout' => 45,
			'headers' => array_merge([
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type' => 'application/json',
			], $extra_headers),
		]);

		if (is_wp_error($res)) {
			return $res;
		}

		$code = (int)wp_remote_retrieve_response_code($res);
		$body_raw = (string)wp_remote_retrieve_body($res);
		$body = json_decode($body_raw, true);

		if ($code < 200 || $code >= 300) {
			$msg = 'Erreur API LinkedIn (HTTP ' . $code . ').';
			if (is_array($body) && !empty($body['message'])) {
				$msg .= ' ' . (string)$body['message'];
			} elseif (is_array($body) && !empty($body['error_description'])) {
				$msg .= ' ' . (string)$body['error_description'];
			}
			return new WP_Error('pkliap_api_error', $msg);
		}

		if (!is_array($body)) {
			$body = [];
		}

		return [
			'code' => $code,
			'headers' => [],
			'body' => $body,
		];
	}

	/** @return string|WP_Error */
	private static function linkedin_get_member_id(string $access_token) {
		// 1) OIDC userinfo (recommandé)
		$userinfo = self::linkedin_api_get('/v2/userinfo', $access_token, []);
		if (!is_wp_error($userinfo)) {
			$sub = (string)($userinfo['body']['sub'] ?? '');
			if ($sub) {
				return $sub;
			}
		}

		// 2) Fallback legacy /v2/me (peut nécessiter r_liteprofile selon le compte/app)
		$res = self::linkedin_api_get('/v2/me', $access_token, [
			'X-Restli-Protocol-Version' => '2.0.0',
		]);
		if (is_wp_error($res)) {
			return $res;
		}
		$id = (string)($res['body']['id'] ?? '');
		if (!$id) {
			return new WP_Error('pkliap_me_failed', 'Réponse LinkedIn invalide : /v2/userinfo (sub manquant) et /v2/me (id manquant).');
		}
		return $id;
	}
}

PKLIAP_Plugin::init();
