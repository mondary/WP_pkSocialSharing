<?php
if (function_exists('opcache_invalidate')) {
	opcache_invalidate(__FILE__, true);
}
/**
 * Plugin Name: WP PK SocialSharing
 * Description: Publie automatiquement vos nouveaux articles sur LinkedIn, X, Facebook, Instagram, Threads et Medium.
 * Version: 1.1.1
 * Author: cmondary
 * Author URI: https://github.com/mondary
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
	const META_X_SHARED_AT = '_pkliap_x_shared_at';
	const META_X_POST_ID = '_pkliap_x_post_id';
	const META_FB_SHARED_AT = '_pkliap_fb_shared_at';
	const META_FB_POST_ID = '_pkliap_fb_post_id';
	const META_IG_SHARED_AT = '_pkliap_ig_shared_at';
	const META_IG_MEDIA_ID = '_pkliap_ig_media_id';
	const META_IG_PERMALINK = '_pkliap_ig_permalink';
	const META_THREADS_SHARED_AT = '_pkliap_threads_shared_at';
	const META_THREADS_POST_ID = '_pkliap_threads_post_id';
	const META_THREADS_PERMALINK = '_pkliap_threads_permalink';
	const META_MEDIUM_SHARED_AT = '_pkliap_medium_shared_at';
	const META_MEDIUM_POST_ID = '_pkliap_medium_post_id';
	const META_MEDIUM_POST_URL = '_pkliap_medium_post_url';
	const CRON_RETRY_HOOK = 'pkliap_retry_pending_shares';
	const SYNC_NAMESPACE = 'pksocialsharing/v1';
	const SYNC_SLUG = 'pk-linkedin-autopublish';

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'admin_menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		add_action('admin_init', [__CLASS__, 'register_admin_columns']);
		add_action('admin_notices', [__CLASS__, 'admin_notices']);
		add_action('admin_bar_menu', [__CLASS__, 'admin_bar_menu'], 100);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_plugins_icon']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_list_styles']);
		add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
		add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
		add_action('init', [__CLASS__, 'maybe_schedule_retry_cron']);

		add_action('admin_post_pkliap_connect', [__CLASS__, 'handle_connect']);
		add_action('admin_post_pkliap_oauth_callback', [__CLASS__, 'handle_oauth_callback']);
		add_action('admin_post_pkliap_disconnect', [__CLASS__, 'handle_disconnect']);
		add_action('admin_post_pkliap_test_post', [__CLASS__, 'handle_test_post']);
		add_action('admin_post_pkliap_linkedin_step1', [__CLASS__, 'handle_linkedin_step1']);
		add_action('admin_post_pkliap_linkedin_step3', [__CLASS__, 'handle_linkedin_step3']);
		add_action('admin_post_pkliap_detect_author', [__CLASS__, 'handle_detect_author']);
		add_action('admin_post_pkliap_recheck_connections', [__CLASS__, 'handle_recheck_connections']);
		add_action('admin_post_pkliap_dry_run_connections', [__CLASS__, 'handle_dry_run_connections']);
		add_action('admin_post_pkliap_x_connect', [__CLASS__, 'handle_x_connect']);
		add_action('admin_post_pkliap_x_oauth_callback', [__CLASS__, 'handle_x_oauth_callback']);
		add_action('admin_post_pkliap_x_disconnect', [__CLASS__, 'handle_x_disconnect']);
		add_action('admin_post_pkliap_x_check', [__CLASS__, 'handle_x_check']);
		add_action('admin_post_pkliap_clear_network_error', [__CLASS__, 'handle_clear_network_error']);
		add_action('admin_post_pkliap_meta_connect', [__CLASS__, 'handle_meta_connect']);
		add_action('admin_post_pkliap_meta_oauth_callback', [__CLASS__, 'handle_meta_oauth_callback']);

		add_action('transition_post_status', [__CLASS__, 'on_transition_post_status'], 10, 3);
		add_action('pkliap_async_share_task', [__CLASS__, 'do_async_share'], 10, 1);
		add_action(self::CRON_RETRY_HOOK, [__CLASS__, 'do_retry_pending_shares']);

		if (defined('WP_CLI') && WP_CLI) {
			WP_CLI::add_command('pksocialsharing retry', [__CLASS__, 'cli_retry_pending_shares']);
		}
	}

	public static function cron_schedules(array $schedules): array {
		if (!isset($schedules['pkliap_five_minutes'])) {
			$schedules['pkliap_five_minutes'] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display' => 'Every 5 minutes (PK SocialSharing)',
			];
		}
		return $schedules;
	}

	public static function maybe_schedule_retry_cron(): void {
		if (!wp_next_scheduled(self::CRON_RETRY_HOOK)) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, 'pkliap_five_minutes', self::CRON_RETRY_HOOK);
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook(self::CRON_RETRY_HOOK);
	}

	public static function enqueue_plugins_icon(string $hook): void {
		if ($hook !== 'plugins.php') {
			return;
		}

		$icon_rel = is_readable(plugin_dir_path(__FILE__) . 'icon.png') ? 'icon.png' : '';
		if ($icon_rel === '') {
			return;
		}

		$icon_url = plugins_url($icon_rel, __FILE__);
		$plugin_basename = plugin_basename(__FILE__);
		$handle = 'pkliap-plugins';

		wp_register_style($handle, false, [], self::get_plugin_version());
		wp_enqueue_style($handle);

		$row_sel = 'tr[data-plugin="' . esc_attr($plugin_basename) . '"]';
		$css = $row_sel . ' .plugin-icon{'
			. 'background-image:url("' . esc_url($icon_url) . '") !important;'
			. 'background-repeat:no-repeat !important;'
			. 'background-position:center !important;'
			. 'background-size:contain !important;'
			. 'color:transparent !important;}'
			. $row_sel . ' .plugin-icon img{opacity:0 !important;}'
			. $row_sel . ' .plugin-icon svg{opacity:0 !important;}';

		wp_add_inline_style($handle, $css);
	}

	public static function enqueue_admin_list_styles(string $hook): void {
		if ($hook !== 'edit.php') {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$post_type = $screen && !empty($screen->post_type) ? (string)$screen->post_type : 'post';
		$opt = self::get_options();
		if (!in_array($post_type, (array)($opt['post_type_whitelist'] ?? ['post']), true)) {
			return;
		}

		$handle = 'pkliap-admin-list';
		wp_register_style($handle, false, [], self::get_plugin_version());
		wp_enqueue_style($handle);
		wp_add_inline_style($handle, '
			.column-pkliap_share_status{width:178px;min-width:80px}
			.pkliap-share-list{display:flex;gap:6px;align-items:center;justify-content:flex-start;max-width:178px;flex-wrap:wrap}
			.pkliap-share-icon{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #dcdcde;border-radius:50%;background:#f6f7f7;color:#8c8f94;text-decoration:none;box-sizing:border-box}
			.pkliap-share-icon svg{width:13px;height:13px;display:block;fill:currentColor}
			.pkliap-share-icon.is-shared{color:var(--pkliap-share-color);border-color:color-mix(in srgb,var(--pkliap-share-color) 42%,#dcdcde);background:color-mix(in srgb,var(--pkliap-share-color) 10%,#fff)}
			.pkliap-share-icon.is-missing{filter:grayscale(1);opacity:.52}
			a.pkliap-share-icon:hover{transform:translateY(-1px);box-shadow:0 1px 3px rgba(0,0,0,.14)}
			.pkliap-share-icon code{display:none}
			@media screen and (max-width:782px){.column-pkliap_share_status{width:auto}.pkliap-share-list{max-width:none}}
		');
	}

	public static function register_admin_columns(): void {
		$opt = self::get_options();
		$post_types = array_values(array_filter((array)($opt['post_type_whitelist'] ?? ['post'])));
		if (!$post_types) {
			$post_types = ['post'];
		}

		foreach ($post_types as $post_type) {
			$post_type = sanitize_key((string)$post_type);
			if ($post_type === '') {
				continue;
			}
			add_filter("manage_{$post_type}_posts_columns", [__CLASS__, 'add_sharing_status_column']);
			add_action("manage_{$post_type}_posts_custom_column", [__CLASS__, 'render_sharing_status_column'], 10, 2);
		}
	}

	public static function add_sharing_status_column(array $columns): array {
		$new_columns = [];
		foreach ($columns as $key => $label) {
			if ($key === 'pkliap_share_status') {
				continue;
			}
			$new_columns[$key] = $label;
			if ($key === 'date') {
				$new_columns['pkliap_share_status'] = 'Partages';
			}
		}
		if (!isset($new_columns['pkliap_share_status'])) {
			$new_columns['pkliap_share_status'] = 'Partages';
		}
		return $new_columns;
	}

	public static function render_sharing_status_column(string $column, int $post_id): void {
		if ($column !== 'pkliap_share_status') {
			return;
		}

		echo self::render_share_icon_list($post_id, 'pkliap-share'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function render_meta_token_card(array $opt, string $link_meta_graph_explorer): void {
		$expires_at = (int)($opt['meta_user_access_token_expires_at'] ?? 0);
		$expires_label = $expires_at > 0 ? wp_date('Y-m-d H:i', $expires_at) : '';
		$link_meta_developer = 'https://developers.facebook.com/apps/';
		$has_app_id = !empty($opt['meta_app_id']);
		$has_app_secret = !empty($opt['meta_app_secret']);
		$has_long_token = !empty($opt['meta_user_access_token']);
		$days_left = ($expires_at > 0) ? (int)ceil(($expires_at - time()) / DAY_IN_SECONDS) : -1;
		$redirect_uri = self::admin_url_action('pkliap_meta_oauth_callback');
		$fb_connected = !empty($opt['fb_page_id']) && !empty($opt['fb_access_token']);
		$ig_connected = !empty($opt['ig_user_id']) && !empty($opt['ig_access_token']);
		?>
		<div class="pks-card pks-card--accent-warn pks-card--wide">
			<div class="pks-card-title">Meta: connexion Facebook & Instagram</div>
			<p class="pks-info" style="margin:-4px 0 12px;">Connecte ton compte Meta sans recoller un token toutes les heures. Le plugin utilise ton app Meta pour récupérer un token longue durée, puis détecte automatiquement ta Page Facebook et ton compte Instagram.</p>

			<form method="post" action="options.php">
				<?php settings_fields('pkliap'); ?>

				<div class="pks-checkrow" style="margin-bottom:14px;">
					<span class="pks-pill pks-pill--warn">1</span>
					<div>
						<strong>Récupérer App ID et App Secret</strong>
						<p class="description" style="margin:4px 0 8px;">
							Clique ici : <a href="<?php echo esc_url($link_meta_developer); ?>" target="_blank" rel="noopener">https://developers.facebook.com/apps/</a>
						</p>
						<p class="description" style="margin:4px 0 8px;">
							Dans Meta, ouvre ton app <strong>WPSocialSharing</strong>, puis va dans <strong>Paramètres &gt; Général</strong>. Copie ici <strong>ID de l’app</strong> et <strong>Clé secrète de l’app</strong>.
						</p>
						<p class="description" style="margin:4px 0 8px;">
							Important : ces deux valeurs ne sont pas renvoyées par Graph Explorer. Graph Explorer sert à tester les permissions, pas à récupérer l’App Secret.
						</p>
						<table class="form-table" role="presentation" style="margin:0;">
							<tr>
								<th scope="row" style="width:140px;">Meta App ID</th>
								<td>
									<input class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[meta_app_id]" value="<?php echo esc_attr((string)$opt['meta_app_id']); ?>"/>
									<?php echo $has_app_id ? '<span class="pks-pill pks-pill--ok" style="margin-left:6px;">OK</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</td>
							</tr>
							<tr>
								<th scope="row">Meta App Secret</th>
								<td>
									<input class="regular-text" type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[meta_app_secret]" value="<?php echo esc_attr((string)$opt['meta_app_secret']); ?>"/>
									<?php echo $has_app_secret ? '<span class="pks-pill pks-pill--ok" style="margin-left:6px;">OK</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<div class="pks-checkrow" style="margin-bottom:14px;">
					<span class="pks-pill pks-pill--warn">2</span>
					<div>
						<strong>URI de redirection OAuth</strong>
						<p class="description" style="margin:4px 0 6px;">Copie cette URL et ajoute-la dans ton app Meta → <strong>Facebook Login for Business &gt; Settings</strong> → champ <em>"URI de redirection OAuth valides"</em>, puis enregistre.</p>
						<div style="display:flex;gap:6px;align-items:center;margin:6px 0 0;">
							<input class="large-text" type="text" value="<?php echo esc_attr($redirect_uri); ?>" readonly onclick="this.select()" style="flex:1;font-size:12px;"/>
							<button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value).then(function(){})">Copier</button>
						</div>
					</div>
				</div>

				<?php submit_button('Enregistrer', 'secondary', 'submit', false); ?>
			</form>

			<div class="pks-checkrow" style="margin:16px 0 14px;">
				<span class="pks-pill pks-pill--ok">3</span>
				<div>
					<strong>Connecter Facebook & Instagram</strong>
					<p class="description" style="margin:4px 0 8px;">Une fois App ID, App Secret et URI de redirection configurés, clique ci-dessous. Facebook te demandera d'accepter les permissions et de choisir ta Page.</p>
					<p class="description" style="margin:4px 0 8px;">Le plugin lancera ensuite automatiquement l’équivalent de cette requête : <code>me/accounts?fields=id,name,access_token,instagram_business_account{id,username}</code>. Il remplira Page ID, Page Access Token et IG User ID si Meta les renvoie.</p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="pkliap_meta_connect"/>
						<?php wp_nonce_field('pkliap_meta_connect'); ?>
						<?php submit_button('Connecter Facebook & Instagram', 'primary', 'submit', false); ?>
					</form>
				</div>
			</div>

			<?php if ($has_long_token): ?>
				<div style="margin-top:12px;padding:10px 14px;background:#f0f6fc;border:1px solid #72aee6;border-radius:4px;">
					<strong>Token Meta actif</strong>
					<?php if ($expires_label !== ''): ?>
						— expire le <strong><?php echo esc_html($expires_label); ?></strong>
						<?php if ($days_left >= 0): ?>
							(<?php echo $days_left > 0 ? esc_html($days_left . ' jour' . ($days_left > 1 ? 's' : '')) : 'expire aujourd\'hui'; ?>)
						<?php endif; ?>
						<?php if ($days_left >= 0 && $days_left <= 7): ?>
							<strong style="color:#b32d2e;"> — Renouvelle bientôt.</strong>
						<?php elseif ($days_left >= 0 && $days_left <= 14): ?>
							<span style="color:#996800;"> — Moins de 2 semaines.</span>
						<?php endif; ?>
					<?php endif; ?>
					<div style="margin-top:6px;">
						<?php echo $fb_connected ? '<span class="pks-pill pks-pill--ok">FB</span> Page : <code>' . esc_html((string)$opt['fb_page_id']) . '</code>' : '<span class="pks-pill pks-pill--bad">FB</span> Non connectée'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						&nbsp;&nbsp;
						<?php echo $ig_connected ? '<span class="pks-pill pks-pill--ok">IG</span> User : <code>' . esc_html((string)$opt['ig_user_id']) . '</code>' : '<span class="pks-pill pks-pill--bad">IG</span> Non connecté'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if (!empty($opt['last_meta_token_message'])): ?>
				<p class="description" style="margin:8px 0 0;color:#0a6f24;"><?php echo esc_html((string)$opt['last_meta_token_message']); ?></p>
			<?php endif; ?>
			<?php if (!empty($opt['last_meta_token_error'])): ?>
				<p class="description" style="margin:8px 0 0;color:#b32d2e;"><?php echo esc_html((string)$opt['last_meta_token_error']); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_share_icon_list(int $post_id, string $class_prefix): string {
		$items = self::build_post_share_status_items($post_id);
		$html = '<div class="' . esc_attr($class_prefix . '-list') . '">';
		foreach ($items as $item) {
			$label = (string)$item['label'];
			$key = (string)$item['key'];
			$url = (string)$item['url'];
			$id = (string)$item['id'];
			$color = (string)$item['color'];
			$shared_at = (int)$item['shared_at'];
			$class = $shared_at ? ($class_prefix . '-icon is-shared') : ($class_prefix . '-icon is-missing');
			$style = $shared_at ? ('--pkliap-share-color:' . $color . ';') : '';
			$title = $shared_at ? ($label . ' partagé le ' . wp_date('Y-m-d H:i', $shared_at)) : ($label . ' non partagé');
			$icon = self::social_icon_svg($key);
			if ($url !== '') {
				$html .= '<a class="' . esc_attr($class) . '" style="' . esc_attr($style) . '" href="' . esc_url($url) . '" target="_blank" rel="noopener" title="' . esc_attr($title) . '" aria-label="' . esc_attr($title) . '">' . $icon . '</a>';
			} elseif ($shared_at && $id !== '') {
				$html .= '<span class="' . esc_attr($class) . '" style="' . esc_attr($style) . '" title="' . esc_attr($title . ' - ID: ' . $id) . '" aria-label="' . esc_attr($title) . '">' . $icon . '<code>' . esc_html($id) . '</code></span>';
			} else {
				$html .= '<span class="' . esc_attr($class) . '" title="' . esc_attr($title) . '" aria-label="' . esc_attr($title) . '">' . $icon . '</span>';
			}
		}
		$html .= '</div>';
		return $html;
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
			'debug_log' => [],
			'last_oauth_at' => 0,
			'last_oauth_token_len' => 0,
			'last_oauth_error' => '',
			'last_linkedin_dry_run_ok' => 0,
			'last_linkedin_dry_run_at' => 0,
			'last_linkedin_dry_run_message' => '',
			'last_admin_alert_at' => 0,
			'last_admin_alert_hash' => '',
			'x_enabled' => 0,
			'x_api_key' => '',
			'x_api_secret' => '',
			'x_access_token' => '',
			'x_access_token_secret' => '',
			'x_user_id' => '',
			'x_screen_name' => '',
			'x_prefix' => '',
			'x_suffix' => '',
			'x_include_title' => 1,
			'x_include_excerpt' => 1,
			'x_include_url' => 1,
			'x_content_order' => '',
			'x_text_template' => '',
			'last_x_error' => '',
			'last_x_error_at' => 0,
			'last_x_check_message' => '',
			'last_x_check_at' => 0,
			'meta_app_id' => '',
			'meta_app_secret' => '',
			'meta_user_access_token' => '',
			'meta_user_access_token_expires_at' => 0,
			'last_meta_token_message' => '',
			'last_meta_token_at' => 0,
			'last_meta_token_error' => '',
			'fb_enabled' => 0,
			'fb_page_id' => '',
			'fb_access_token' => '',
			'fb_prefix' => '',
			'fb_suffix' => '',
			'fb_include_title' => 1,
			'fb_include_excerpt' => 1,
			'fb_include_url' => 1,
			'fb_content_order' => '',
			'fb_text_template' => '',
			'last_fb_error' => '',
			'last_fb_error_at' => 0,
			'ig_enabled' => 0,
			'ig_user_id' => '',
			'ig_access_token' => '',
			'last_ig_error' => '',
			'last_ig_error_at' => 0,
			'threads_enabled' => 0,
			'threads_user_id' => '',
			'threads_access_token' => '',
			'last_threads_error' => '',
			'last_threads_error_at' => 0,
			'medium_enabled' => 0,
			'medium_user_id' => '',
			'medium_access_token' => '',
			'medium_publish_status' => 'public',
			'last_medium_error' => '',
			'last_medium_error_at' => 0,
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
		$out['x_enabled'] = array_key_exists('x_enabled', $value) ? (empty($value['x_enabled']) ? 0 : 1) : (int)$current['x_enabled'];
		$out['x_api_key'] = array_key_exists('x_api_key', $value) ? sanitize_text_field((string)$value['x_api_key']) : (string)$current['x_api_key'];
		$out['x_api_secret'] = array_key_exists('x_api_secret', $value) ? sanitize_text_field((string)$value['x_api_secret']) : (string)$current['x_api_secret'];
		$out['x_access_token'] = array_key_exists('x_access_token', $value) ? sanitize_text_field((string)$value['x_access_token']) : (string)$current['x_access_token'];
		$out['x_access_token_secret'] = array_key_exists('x_access_token_secret', $value) ? sanitize_text_field((string)$value['x_access_token_secret']) : (string)$current['x_access_token_secret'];
		$out['x_user_id'] = array_key_exists('x_user_id', $value) ? sanitize_text_field((string)$value['x_user_id']) : (string)$current['x_user_id'];
		$out['x_screen_name'] = array_key_exists('x_screen_name', $value) ? sanitize_text_field((string)$value['x_screen_name']) : (string)$current['x_screen_name'];
		$out['x_prefix'] = array_key_exists('x_prefix', $value) ? sanitize_text_field((string)$value['x_prefix']) : (string)$current['x_prefix'];
		$out['x_suffix'] = array_key_exists('x_suffix', $value) ? sanitize_text_field((string)$value['x_suffix']) : (string)$current['x_suffix'];
		$out['x_include_title'] = array_key_exists('x_include_title', $value) ? (empty($value['x_include_title']) ? 0 : 1) : (int)$current['x_include_title'];
		$out['x_include_excerpt'] = array_key_exists('x_include_excerpt', $value) ? (empty($value['x_include_excerpt']) ? 0 : 1) : (int)$current['x_include_excerpt'];
		$out['x_include_url'] = array_key_exists('x_include_url', $value) ? (empty($value['x_include_url']) ? 0 : 1) : (int)$current['x_include_url'];
		if (array_key_exists('x_content_order', $value)) {
			$x_order = trim((string)$value['x_content_order']);
			$out['x_content_order'] = ($x_order === '') ? '' : self::normalize_content_order($x_order);
		} else {
			$current_x_order = trim((string)$current['x_content_order']);
			$out['x_content_order'] = ($current_x_order === '') ? '' : self::normalize_content_order($current_x_order);
		}
		$out['x_text_template'] = array_key_exists('x_text_template', $value) ? sanitize_textarea_field((string)$value['x_text_template']) : (string)$current['x_text_template'];
		$out['meta_app_id'] = array_key_exists('meta_app_id', $value) ? sanitize_text_field((string)$value['meta_app_id']) : (string)$current['meta_app_id'];
		$out['meta_app_secret'] = array_key_exists('meta_app_secret', $value) ? sanitize_text_field((string)$value['meta_app_secret']) : (string)$current['meta_app_secret'];
		$out['meta_user_access_token'] = array_key_exists('meta_user_access_token', $value) ? sanitize_text_field((string)$value['meta_user_access_token']) : (string)$current['meta_user_access_token'];
		$out['meta_user_access_token_expires_at'] = array_key_exists('meta_user_access_token_expires_at', $value) ? (int)$value['meta_user_access_token_expires_at'] : (int)$current['meta_user_access_token_expires_at'];
		$out['fb_enabled'] = array_key_exists('fb_enabled', $value) ? (empty($value['fb_enabled']) ? 0 : 1) : (int)$current['fb_enabled'];
		$out['fb_page_id'] = array_key_exists('fb_page_id', $value) ? sanitize_text_field((string)$value['fb_page_id']) : (string)$current['fb_page_id'];
		$out['fb_access_token'] = array_key_exists('fb_access_token', $value) ? sanitize_text_field((string)$value['fb_access_token']) : (string)$current['fb_access_token'];
		$out['fb_prefix'] = array_key_exists('fb_prefix', $value) ? sanitize_text_field((string)$value['fb_prefix']) : (string)$current['fb_prefix'];
		$out['fb_suffix'] = array_key_exists('fb_suffix', $value) ? sanitize_text_field((string)$value['fb_suffix']) : (string)$current['fb_suffix'];
		$out['fb_include_title'] = array_key_exists('fb_include_title', $value) ? (empty($value['fb_include_title']) ? 0 : 1) : (int)$current['fb_include_title'];
		$out['fb_include_excerpt'] = array_key_exists('fb_include_excerpt', $value) ? (empty($value['fb_include_excerpt']) ? 0 : 1) : (int)$current['fb_include_excerpt'];
		$out['fb_include_url'] = array_key_exists('fb_include_url', $value) ? (empty($value['fb_include_url']) ? 0 : 1) : (int)$current['fb_include_url'];
		if (array_key_exists('fb_content_order', $value)) {
			$fb_order = trim((string)$value['fb_content_order']);
			$out['fb_content_order'] = ($fb_order === '') ? '' : self::normalize_content_order($fb_order);
		} else {
			$current_fb_order = trim((string)$current['fb_content_order']);
			$out['fb_content_order'] = ($current_fb_order === '') ? '' : self::normalize_content_order($current_fb_order);
		}
		$out['fb_text_template'] = array_key_exists('fb_text_template', $value) ? sanitize_textarea_field((string)$value['fb_text_template']) : (string)$current['fb_text_template'];
		$out['ig_enabled'] = array_key_exists('ig_enabled', $value) ? (empty($value['ig_enabled']) ? 0 : 1) : (int)$current['ig_enabled'];
		$out['ig_user_id'] = array_key_exists('ig_user_id', $value) ? sanitize_text_field((string)$value['ig_user_id']) : (string)$current['ig_user_id'];
		$out['ig_access_token'] = array_key_exists('ig_access_token', $value) ? sanitize_text_field((string)$value['ig_access_token']) : (string)$current['ig_access_token'];

		$ig_credentials_changed = ((string)$out['ig_user_id'] !== (string)$current['ig_user_id']) || ((string)$out['ig_access_token'] !== (string)$current['ig_access_token']);
		$out['threads_enabled'] = array_key_exists('threads_enabled', $value) ? (empty($value['threads_enabled']) ? 0 : 1) : (int)$current['threads_enabled'];
		$out['threads_user_id'] = array_key_exists('threads_user_id', $value) ? sanitize_text_field((string)$value['threads_user_id']) : (string)$current['threads_user_id'];
		$out['threads_access_token'] = array_key_exists('threads_access_token', $value) ? sanitize_text_field((string)$value['threads_access_token']) : (string)$current['threads_access_token'];
		$threads_credentials_changed = ((string)$out['threads_user_id'] !== (string)$current['threads_user_id']) || ((string)$out['threads_access_token'] !== (string)$current['threads_access_token']);
		$out['medium_enabled'] = array_key_exists('medium_enabled', $value) ? (empty($value['medium_enabled']) ? 0 : 1) : (int)$current['medium_enabled'];
		$out['medium_user_id'] = array_key_exists('medium_user_id', $value) ? sanitize_text_field((string)$value['medium_user_id']) : (string)$current['medium_user_id'];
		$out['medium_access_token'] = array_key_exists('medium_access_token', $value) ? sanitize_text_field((string)$value['medium_access_token']) : (string)$current['medium_access_token'];
		$out['medium_publish_status'] = array_key_exists('medium_publish_status', $value) && in_array((string)$value['medium_publish_status'], ['draft', 'public', 'unlisted'], true) ? (string)$value['medium_publish_status'] : (string)$current['medium_publish_status'];
		$medium_credentials_changed = ((string)$out['medium_user_id'] !== (string)$current['medium_user_id']) || ((string)$out['medium_access_token'] !== (string)$current['medium_access_token']);
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
			'debug_log',
			'last_oauth_at',
			'last_oauth_token_len',
			'last_oauth_error',
			'last_linkedin_dry_run_ok',
			'last_linkedin_dry_run_at',
			'last_linkedin_dry_run_message',
			'last_admin_alert_at',
			'last_admin_alert_hash',
			'last_x_error',
			'last_x_error_at',
			'last_x_check_message',
			'last_x_check_at',
			'last_fb_error',
			'last_fb_error_at',
			'last_ig_error',
			'last_ig_error_at',
			'last_threads_error',
			'last_threads_error_at',
			'last_medium_error',
			'last_medium_error_at',
		] as $k) {
			$out[$k] = $current[$k];
		}
		if ($ig_credentials_changed) {
			$out['last_ig_error'] = '';
			$out['last_ig_error_at'] = 0;
		}
		if ($threads_credentials_changed) {
			$out['last_threads_error'] = '';
			$out['last_threads_error_at'] = 0;
		}
		if ($medium_credentials_changed) {
			$out['last_medium_error'] = '';
			$out['last_medium_error_at'] = 0;
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
		$pending_alerts = self::collect_pending_alerts($opt);

		$post_types = get_post_types(['public' => true], 'objects');

		$link_apps = 'https://www.linkedin.com/developers/apps';
		$link_docs_oauth = 'https://learn.microsoft.com/linkedin/shared/authentication/authorization-code-flow';
		$link_docs_oidc = 'https://learn.microsoft.com/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2';
		$link_docs_ugc = 'https://learn.microsoft.com/linkedin/marketing/community-management/shares/ugc-post-api';
		$link_docs_posts = 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2025-10';
		$link_docs_images = 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/images-api?view=li-lms-2026-02';
		$link_x_developer = 'https://developer.x.com/en/portal/dashboard';
		$link_x_projects = 'https://developer.x.com/en/portal/projects-and-apps';
		$link_x_oauth_docs = 'https://developer.x.com/en/docs/authentication/oauth-1-0a/obtaining-user-access-tokens';
		$link_meta_developer = 'https://developers.facebook.com/apps/';
		$link_meta_graph_explorer = 'https://developers.facebook.com/tools/explorer/';
		$link_meta_fb_pages_docs = 'https://developers.facebook.com/docs/pages-api/posts/';
		$link_meta_ig_publish_docs = 'https://developers.facebook.com/docs/instagram-platform/content-publishing/';
		$link_meta_threads_docs = 'https://developers.facebook.com/docs/threads/posts/';
		$link_meta_threads_get_started = 'https://developers.facebook.com/docs/threads/get-started/';
		$link_meta_threads_tokens = 'https://developers.facebook.com/docs/threads/get-started/get-access-tokens-and-permissions/';
		$link_medium_docs = 'https://github.com/Medium/medium-api-docs';
		$link_medium_settings = 'https://medium.com/me/settings';

		$recommended_redirect_uri = self::admin_url_action('pkliap_oauth_callback');
		$config_redirect_uri = $opt['redirect_uri'] ?: $recommended_redirect_uri;
		$has_client_id = !empty($opt['client_id']);
		$has_client_secret = !empty($opt['client_secret']);
		$redirect_is_recommended = ($config_redirect_uri === $recommended_redirect_uri);
		$has_author_urn = !empty($opt['author_urn']);
		$active_network = isset($_GET['network']) ? sanitize_key((string)wp_unslash($_GET['network'])) : 'dashboard';
		if (!in_array($active_network, ['dashboard', 'linkedin', 'x', 'facebook', 'instagram', 'threads', 'medium'], true)) {
			$active_network = 'dashboard';
		}
		self::maybe_recheck_network_errors($active_network, $opt);
		$opt = self::get_options();
		$pending_alerts = self::collect_pending_alerts($opt);
		$settings_base_url = menu_page_url('pk-socialsharing', false);
		$link_tab_dashboard = remove_query_arg('network', $settings_base_url);
		$link_tab_linkedin = add_query_arg('network', 'linkedin', $settings_base_url);
		$link_tab_x = add_query_arg('network', 'x', $settings_base_url);
		$link_tab_facebook = add_query_arg('network', 'facebook', $settings_base_url);
		$link_tab_instagram = add_query_arg('network', 'instagram', $settings_base_url);
		$link_tab_threads = add_query_arg('network', 'threads', $settings_base_url);
		$link_tab_medium = add_query_arg('network', 'medium', $settings_base_url);
		$x_callback_uri = self::admin_url_action('pkliap_x_oauth_callback');
		$x_connected = (!empty($opt['x_access_token']) && !empty($opt['x_access_token_secret']));

		$linkedin_connected = ($has_token && $has_author_urn);
		$fb_connected = (!empty($opt['fb_page_id']) && !empty($opt['fb_access_token']));
		$ig_connected = (!empty($opt['ig_user_id']) && !empty($opt['ig_access_token']));
		$threads_connected = (!empty($opt['threads_user_id']) && !empty($opt['threads_access_token']));
		$medium_connected = (!empty($opt['medium_user_id']) && !empty($opt['medium_access_token']));
		$health = self::build_connection_health($opt, $has_token, $token_not_expired, $has_author_urn, $x_connected, $fb_connected, $ig_connected, $threads_connected, $medium_connected);

		$tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
		$today_start = (new DateTimeImmutable('today', $tz))->getTimestamp();
		$today_end = (new DateTimeImmutable('tomorrow', $tz))->getTimestamp() - 1;
		$today_label = wp_date('Y-m-d', $today_start);

		$whitelist = array_values(array_filter((array)($opt['post_type_whitelist'] ?? [])));
		$planned_post_ids = [];
		if ($whitelist) {
			$planned_q = new WP_Query([
				'post_type' => $whitelist,
				'post_status' => ['publish', 'future'],
				'fields' => 'ids',
				'posts_per_page' => -1,
				'no_found_rows' => true,
				'orderby' => 'date',
				'order' => 'ASC',
				'date_query' => [[
					'after' => wp_date('Y-m-d 00:00:00', $today_start),
					'before' => wp_date('Y-m-d 23:59:59', $today_start),
					'inclusive' => true,
				]],
			]);
			$planned_post_ids = is_array($planned_q->posts) ? array_map('intval', $planned_q->posts) : [];
		}

		$net_cfg = [
			'linkedin' => ['label' => 'LinkedIn',  'enabled' => !empty($opt['enabled']),    'connected' => $linkedin_connected, 'meta' => self::META_SHARED_AT,    'err_key' => 'last_share_error'],
			'x'        => ['label' => 'X',         'enabled' => !empty($opt['x_enabled']),  'connected' => $x_connected,        'meta' => self::META_X_SHARED_AT,  'err_key' => 'last_x_error'],
			'facebook' => ['label' => 'Facebook',  'enabled' => !empty($opt['fb_enabled']), 'connected' => $fb_connected,       'meta' => self::META_FB_SHARED_AT, 'err_key' => 'last_fb_error'],
			'instagram'=> ['label' => 'Instagram', 'enabled' => !empty($opt['ig_enabled']), 'connected' => $ig_connected,       'meta' => self::META_IG_SHARED_AT, 'err_key' => 'last_ig_error'],
			'threads'  => ['label' => 'Threads',   'enabled' => !empty($opt['threads_enabled']), 'connected' => $threads_connected, 'meta' => self::META_THREADS_SHARED_AT, 'err_key' => 'last_threads_error'],
			'medium'   => ['label' => 'Medium',    'enabled' => !empty($opt['medium_enabled']), 'connected' => $medium_connected, 'meta' => self::META_MEDIUM_SHARED_AT, 'err_key' => 'last_medium_error'],
		];
		$planned_by_net = [];
		$done_by_net = [];
		$failed_by_net = [];
		foreach ($net_cfg as $net => $cfg) {
			$planned_by_net[$net] = $cfg['enabled'] ? count($planned_post_ids) : 0;

			$done_q = new WP_Query([
				'post_type' => 'any',
				'post_status' => 'publish',
				'fields' => 'ids',
				'posts_per_page' => -1,
				'no_found_rows' => true,
				'meta_query' => [[
					'key' => $cfg['meta'],
					'value' => [$today_start, $today_end],
					'compare' => 'BETWEEN',
					'type' => 'NUMERIC',
				]],
			]);
			$done_by_net[$net] = is_array($done_q->posts) ? count($done_q->posts) : 0;
			$failed_by_net[$net] = 0;
		}

		$debug_log = is_array($opt['debug_log']) ? $opt['debug_log'] : [];
		foreach ($debug_log as $entry) {
			$ts = (int)($entry['ts'] ?? 0);
			if ($ts < $today_start || $ts > $today_end) {
				continue;
			}
			$msg = (string)($entry['message'] ?? '');
			if (strpos($msg, 'Auto share') === false || strpos($msg, ' failed ') === false) {
				continue;
			}
			if (preg_match('/Auto share ([a-z]+) failed /', $msg, $m)) {
				$net = (string)$m[1];
				if (array_key_exists($net, $failed_by_net)) {
					$failed_by_net[$net]++;
				}
			}
		}
		$tab_status = [
			'linkedin' => self::network_tab_status('linkedin', !empty($opt['enabled']), $linkedin_connected, (string)($opt['last_share_error'] ?? '')),
			'x' => self::network_tab_status('x', !empty($opt['x_enabled']), $x_connected, (string)($opt['last_x_error'] ?? '')),
			'facebook' => self::network_tab_status('facebook', !empty($opt['fb_enabled']), $fb_connected, (string)($opt['last_fb_error'] ?? '')),
			'instagram' => self::network_tab_status('instagram', !empty($opt['ig_enabled']), $ig_connected, (string)($opt['last_ig_error'] ?? '')),
			'threads' => self::network_tab_status('threads', !empty($opt['threads_enabled']), $threads_connected, (string)($opt['last_threads_error'] ?? '')),
			'medium' => self::network_tab_status('medium', !empty($opt['medium_enabled']), $medium_connected, (string)($opt['last_medium_error'] ?? '')),
		];

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
				.pks-publication-split{display:grid;grid-template-columns:1fr;gap:10px}
				.pks-network-tabs{display:flex;gap:8px;align-items:center;margin:0 0 14px}
				.pks-network-tab{
					display:inline-flex;align-items:center;gap:6px;
					padding:8px 12px;border-radius:999px;text-decoration:none;
					border:1px solid var(--pks-border);background:#fff;color:#0f172a;font-weight:600;
				}
				.pks-network-tab.is-active{background:#0f172a;color:#fff;border-color:#0f172a}
				.pks-network-pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;opacity:.9}
				.pks-network-dot{width:8px;height:8px;border-radius:50%;display:inline-block;background:#94a3b8;box-shadow:0 0 0 2px rgba(148,163,184,.16)}
				.pks-network-dot--ok{background:#22c55e;box-shadow:0 0 0 2px rgba(34,197,94,.18)}
				.pks-network-dot--warn{background:#f59e0b;box-shadow:0 0 0 2px rgba(245,158,11,.2)}
				.pks-network-dot--bad{background:#ef4444;box-shadow:0 0 0 2px rgba(239,68,68,.18)}
				.pks-network-tab.is-active .pks-network-dot{box-shadow:0 0 0 2px rgba(255,255,255,.22)}
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
				.pks-dashboard-share-list{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
				.pks-dashboard-share-icon{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #dcdcde;border-radius:50%;background:#f6f7f7;color:#8c8f94;text-decoration:none;box-sizing:border-box}
				.pks-dashboard-share-icon svg{width:16px;height:16px;display:block;fill:currentColor}
				.pks-dashboard-share-icon.is-shared{color:var(--pkliap-share-color);border-color:color-mix(in srgb,var(--pkliap-share-color) 42%,#dcdcde);background:color-mix(in srgb,var(--pkliap-share-color) 10%,#fff)}
				.pks-dashboard-share-icon.is-missing{filter:grayscale(1);opacity:.5}
				a.pks-dashboard-share-icon:hover{transform:translateY(-1px);box-shadow:0 1px 3px rgba(0,0,0,.14)}
				.pks-dashboard-share-icon code{display:none}
				.pks-recap-network{display:flex;align-items:center;gap:8px}
				.pks-recap-icon{width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#f6f7f7;color:var(--pkliap-share-color);border:1px solid color-mix(in srgb,var(--pkliap-share-color) 35%,#dcdcde)}
				.pks-recap-icon svg{width:14px;height:14px;fill:currentColor;display:block}
				.pks-publication-table tbody{display:block}
				.pks-publication-table tr{display:block;border-radius:10px}
				@media (min-width: 1200px){
					.pks-text-grid{grid-template-columns:1.1fr .9fr}
				}
				@media (min-width: 1080px){
					.pks-checksplit{grid-template-columns:repeat(2,minmax(0,1fr))}
					.pks-publication-split{grid-template-columns:repeat(3,minmax(0,1fr))}
					.pks-publication-split > .pks-checklist{display:contents}
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
			if (!empty($pending_alerts)) {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>Attention :</strong> ' . esc_html(implode(' ', $pending_alerts)) . '</p></div>';
			}
			?>

			<div class="pks-modern">
				<div class="pks-network-tabs" role="tablist" aria-label="Réseaux sociaux">
					<a class="pks-network-tab <?php echo $active_network === 'dashboard' ? 'is-active' : ''; ?>" href="<?php echo esc_url($link_tab_dashboard); ?>" role="tab" aria-selected="<?php echo $active_network === 'dashboard' ? 'true' : 'false'; ?>">
						Dashboard
					</a>
					<a class="pks-network-tab <?php echo $active_network === 'linkedin' ? 'is-active' : ''; ?>" href="<?php echo esc_url($link_tab_linkedin); ?>" role="tab" aria-selected="<?php echo $active_network === 'linkedin' ? 'true' : 'false'; ?>">
						LinkedIn
						<span class="pks-network-pill"><span class="pks-network-dot pks-network-dot--<?php echo esc_attr($tab_status['linkedin']['tone']); ?>"></span><?php echo esc_html($tab_status['linkedin']['label']); ?></span>
					</a>
					<a class="pks-network-tab <?php echo $active_network === 'x' ? 'is-active' : ''; ?>" href="<?php echo esc_url($link_tab_x); ?>" role="tab" aria-selected="<?php echo $active_network === 'x' ? 'true' : 'false'; ?>">
						X (Twitter)
						<span class="pks-network-pill"><span class="pks-network-dot pks-network-dot--<?php echo esc_attr($tab_status['x']['tone']); ?>"></span><?php echo esc_html($tab_status['x']['label']); ?></span>
					</a>
					<a class="pks-network-tab <?php echo $active_network === 'facebook' ? 'is-active' : ''; ?>" href="<?php echo esc_url($link_tab_facebook); ?>" role="tab" aria-selected="<?php echo $active_network === 'facebook' ? 'true' : 'false'; ?>">
						Facebook
						<span class="pks-network-pill"><span class="pks-network-dot pks-network-dot--<?php echo esc_attr($tab_status['facebook']['tone']); ?>"></span><?php echo esc_html($tab_status['facebook']['label']); ?></span>
					</a>
					<a class="pks-network-tab <?php echo $active_network === 'instagram' ? 'is-active' : ''; ?>" href="<?php echo esc_url($link_tab_instagram); ?>" role="tab" aria-selected="<?php echo $active_network === 'instagram' ? 'true' : 'false'; ?>">
						Instagram
						<span class="pks-network-pill"><span class="pks-network-dot pks-network-dot--<?php echo esc_attr($tab_status['instagram']['tone']); ?>"></span><?php echo esc_html($tab_status['instagram']['label']); ?></span>
					</a>
					<a class="pks-network-tab <?php echo $active_network === 'threads' ? 'is-active' : ''; ?>" href="<?php echo esc_url($link_tab_threads); ?>" role="tab" aria-selected="<?php echo $active_network === 'threads' ? 'true' : 'false'; ?>">
						Threads
						<span class="pks-network-pill"><span class="pks-network-dot pks-network-dot--<?php echo esc_attr($tab_status['threads']['tone']); ?>"></span><?php echo esc_html($tab_status['threads']['label']); ?></span>
					</a>
					<a class="pks-network-tab <?php echo $active_network === 'medium' ? 'is-active' : ''; ?>" href="<?php echo esc_url($link_tab_medium); ?>" role="tab" aria-selected="<?php echo $active_network === 'medium' ? 'true' : 'false'; ?>">
						Medium
						<span class="pks-network-pill"><span class="pks-network-dot pks-network-dot--<?php echo esc_attr($tab_status['medium']['tone']); ?>"></span><?php echo esc_html($tab_status['medium']['label']); ?></span>
					</a>
				</div>

				<?php if ($active_network === 'dashboard'): ?>
					<div class="pks-grid">
						<div class="pks-card pks-card--accent-blue pks-card--wide">
							<div class="pks-card-title">Récap du <?php echo esc_html($today_label); ?></div>
							<p class="pks-info" style="margin:-4px 0 12px;">
								Planifié = articles publiés ou programmés aujourd’hui sur les types autorisés. Effectué = partages réalisés aujourd’hui (selon les metas <code>_pkliap_*_shared_at</code>).
							</p>
							<table class="widefat striped" style="width:100%;border-collapse:collapse;">
								<thead>
									<tr>
										<th>Réseau</th>
										<th>Planifié</th>
										<th>Effectué</th>
										<th>Échecs (log)</th>
										<th>Connexion</th>
										<th>Mode</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($net_cfg as $net => $cfg): ?>
										<?php
										$planned = (int)($planned_by_net[$net] ?? 0);
										$done = (int)($done_by_net[$net] ?? 0);
										$failed = (int)($failed_by_net[$net] ?? 0);
										$error = (string)($opt[(string)$cfg['err_key']] ?? '');
										$conn_status = self::network_connection_status($net, !empty($cfg['connected']), $error);
										$mode_status = self::network_mode_status($net, !empty($cfg['enabled']), !empty($cfg['connected']), $error);
										$conn_class = $conn_status['tone'] === 'ok' ? 'pks-pill--ok' : ($conn_status['tone'] === 'warn' ? 'pks-pill--warn' : 'pks-pill--bad');
										$mode_class = $mode_status['tone'] === 'ok' ? 'pks-pill--ok' : ($mode_status['tone'] === 'warn' ? 'pks-pill--warn' : 'pks-pill--bad');
										$icon_color = (string)(self::network_icon_color($net));
										?>
										<tr>
											<td>
												<span class="pks-recap-network">
													<span class="pks-recap-icon" style="<?php echo esc_attr('--pkliap-share-color:' . $icon_color . ';'); ?>"><?php echo self::social_icon_svg($net); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
													<strong><?php echo esc_html((string)$cfg['label']); ?></strong>
												</span>
											</td>
											<td><?php echo esc_html((string)$planned); ?></td>
											<td><?php echo esc_html((string)$done); ?></td>
											<td><?php echo esc_html((string)$failed); ?></td>
											<td><span class="pks-pill <?php echo esc_attr($conn_class); ?>"><?php echo esc_html((string)$conn_status['label']); ?></span></td>
											<td><span class="pks-pill <?php echo esc_attr($mode_class); ?>"><?php echo esc_html((string)$mode_status['label']); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="description" style="margin:10px 0 0;">
								Types autorisés aujourd’hui: <code><?php echo esc_html($whitelist ? implode(',', $whitelist) : '—'); ?></code> — articles trouvés: <strong><?php echo esc_html((string)count($planned_post_ids)); ?></strong>.
							</p>
						</div>

						<div class="pks-card pks-card--accent-ok pks-card--wide">
							<div class="pks-card-title">Articles du jour</div>
							<?php if (!$planned_post_ids): ?>
								<p class="pks-info" style="margin:0;">Aucun article publié ou planifié aujourd’hui sur les types autorisés.</p>
							<?php else: ?>
								<table class="widefat striped" style="width:100%;border-collapse:collapse;">
									<thead>
										<tr>
											<th style="width:70px;">Image</th>
											<th>Article</th>
											<th style="width:110px;">Statut</th>
											<th style="width:90px;">Heure</th>
											<th style="width:230px;">Partages</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($planned_post_ids as $planned_post_id): ?>
											<?php
											$planned_post = get_post($planned_post_id);
											if (!$planned_post) {
												continue;
											}
											$edit_url = get_edit_post_link($planned_post_id, '');
											$view_url = get_permalink($planned_post_id);
											$is_future = $planned_post->post_status === 'future';
											$thumb_html = get_the_post_thumbnail($planned_post_id, [56, 56], ['style' => 'width:56px;height:56px;object-fit:cover;border-radius:6px;background:#f3f4f6;']);
											?>
											<tr>
												<td><?php echo $thumb_html ?: '<span style="display:inline-flex;width:56px;height:56px;align-items:center;justify-content:center;border-radius:6px;background:#f3f4f6;color:#94a3b8;">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
												<td>
													<strong><?php echo esc_html(get_the_title($planned_post_id) ?: '(Sans titre)'); ?></strong>
													<div class="row-actions">
														<?php if ($edit_url): ?><span><a href="<?php echo esc_url($edit_url); ?>">Modifier</a> | </span><?php endif; ?>
														<?php if (!$is_future): ?><span><a href="<?php echo esc_url($view_url); ?>" target="_blank" rel="noopener">Voir</a></span><?php endif; ?>
													</div>
												</td>
												<td><?php echo $is_future ? '<span class="pks-pill pks-pill--warn">Planifié</span>' : '<span class="pks-pill pks-pill--ok">Publié</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
												<td><?php echo esc_html(wp_date('H:i', (int)get_post_time('U', true, $planned_post))); ?></td>
												<td><?php echo self::render_share_icon_list($planned_post_id, 'pks-dashboard-share'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif ($active_network === 'x'): ?>
					<div class="pks-grid">
						<form method="post" action="options.php" class="pks-card pks-card--accent-blue">
							<div class="pks-card-title">X Developer (App)</div>
							<?php settings_fields('pkliap'); ?>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">API Key</th>
									<td>
										<input class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_api_key]" value="<?php echo esc_attr((string)$opt['x_api_key']); ?>"/>
										<p class="description" style="margin-top:6px;">
											Mettre ici la <strong>Consumer Key</strong> visible dans <a href="<?php echo esc_url($link_x_projects); ?>" target="_blank" rel="noopener">Projects &amp; Apps</a> → ton app → <strong>OAuth 1.0 Keys</strong>. Ne pas utiliser le Bearer Token ni le Client ID OAuth 2.0.
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">API Key Secret</th>
									<td>
										<input class="regular-text" type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_api_secret]" value="<?php echo esc_attr((string)$opt['x_api_secret']); ?>"/>
										<p class="description" style="margin-top:6px;">
											Mettre ici la <strong>Consumer Secret</strong> du bloc <strong>OAuth 1.0 Keys</strong>. Si le secret n’est plus affiché, régénérer la clé côté X puis la recoller ici.
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">Callback URL</th>
									<td>
										<input class="regular-text" type="text" readonly value="<?php echo esc_attr($x_callback_uri); ?>"/>
										<p class="description">À copier dans X Developer → ton app → <strong>Edit settings</strong> :</p>
										<p class="description" style="margin-top:6px;">
											- <strong>App permissions</strong> : <code>Read and write</code><br/>
											- <strong>Type of App</strong> : <code>Web App, Automated App or Bot</code><br/>
											- <strong>Callback URI / Redirect URL</strong> : cette URL exacte<br/>
											- <strong>Website URL</strong> : l’URL publique de ton site
										</p>
										<p class="description" style="margin-top:6px;">
											Erreur fréquente : si l’app est en <code>Native App</code>, X renvoie <code>Desktop applications only support the oauth_callback value 'oob'</code>. Il faut impérativement choisir <code>Web App, Automated App or Bot</code>.
										</p>
										<p class="description" style="margin-top:6px;">
											Docs OAuth: <a href="<?php echo esc_url($link_x_oauth_docs); ?>" target="_blank" rel="noopener">Obtaining user access tokens</a>.<br/>
											Portail dev: <a href="<?php echo esc_url($link_x_developer); ?>" target="_blank" rel="noopener">Developer Dashboard</a>.
										</p>
									</td>
								</tr>
							</table>
							<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
						</form>

						<div class="pks-card pks-card--accent-purple">
							<div class="pks-card-title">Compte X (qui poste)</div>
							<p class="pks-info" style="margin:-4px 0 12px;">
								Après enregistrement des clés, cliquer sur <strong>Connecter / Reconnecter</strong> pour autoriser le compte X qui publiera. Le plugin récupère ensuite automatiquement le token utilisateur OAuth 1.0a.
							</p>
							<div class="pks-actions-row" style="margin:-6px 0 12px;">
								<?php echo $x_connected ? '<span class="pks-pill pks-pill--ok">Connecté</span>' : '<span class="pks-pill pks-pill--bad">Non connecté</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php if (!empty($opt['x_screen_name'])): ?>
									<span class="pks-pill pks-pill--ok">@<?php echo esc_html((string)$opt['x_screen_name']); ?></span>
								<?php endif; ?>
							</div>
							<div class="pks-actions-row" style="margin:0 0 12px;">
								<a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_x_connect'), 'pkliap_x_connect')); ?>">Connecter / Reconnecter</a>
								<a class="button" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_x_check'), 'pkliap_x_check')); ?>">Tester X maintenant</a>
								<a class="button" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_x_disconnect'), 'pkliap_x_disconnect')); ?>">Déconnecter</a>
							</div>
							<?php if (!empty($opt['last_x_check_message'])): ?>
								<p class="description" style="color:#2271b1;">
									Dernier test X: <?php echo esc_html((string)$opt['last_x_check_message']); ?>
									<?php if (!empty($opt['last_x_check_at'])): ?>
										<br/>Le <?php echo esc_html(wp_date('Y-m-d H:i', (int)$opt['last_x_check_at'])); ?>
									<?php endif; ?>
								</p>
							<?php endif; ?>
							<?php if (!empty($opt['last_x_error'])): ?>
								<p class="description" style="color:#b32d2e;">
									Dernière erreur X: <?php echo esc_html((string)$opt['last_x_error']); ?>
									<?php if (!empty($opt['last_x_error_at'])): ?>
										<br/>Le <?php echo esc_html(wp_date('Y-m-d H:i', (int)$opt['last_x_error_at'])); ?>
									<?php endif; ?>
								</p>
								<?php if (self::is_x_credits_error((string)$opt['last_x_error'])): ?>
									<div class="notice notice-error inline" style="margin:10px 0 0;">
										<p><strong>X bloque la publication côté facturation.</strong> Le compte développeur n'a pas assez de crédits pour créer un post via l'API. Solution officielle: va dans <a href="<?php echo esc_url($link_x_developer); ?>" target="_blank" rel="noopener">X Developer Portal</a> → Billing / Credits, ajoute des crédits, puis reviens ici et clique sur <strong>Publier maintenant</strong>. Solution immédiate sans API: utilise <strong>Publier via navigateur</strong> dans le bloc Test X.</p>
									</div>
								<?php endif; ?>
							<?php endif; ?>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">User ID</th>
									<td><input class="regular-text" type="text" readonly value="<?php echo esc_attr((string)$opt['x_user_id']); ?>"/></td>
								</tr>
								<tr>
									<th scope="row">Screen Name</th>
									<td><input class="regular-text" type="text" readonly value="<?php echo esc_attr((string)$opt['x_screen_name']); ?>"/></td>
								</tr>
							</table>
						</div>

						<form method="post" action="options.php" class="pks-card pks-card--accent-ok pks-card--wide">
							<div class="pks-card-title">Publication X</div>
							<?php settings_fields('pkliap'); ?>
							<div class="pks-checkrow">
								<span class="pks-pill pks-pill--ok">AUTO</span>
								<div>
									<strong>Activer</strong>
									<label class="pks-inline"><input type="hidden" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_enabled]" value="0"/><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_enabled]" value="1" <?php checked(1, (int)$opt['x_enabled']); ?>/> Publier automatiquement sur X</label>
									<p>Le texte peut utiliser les réglages dédiés X ci-dessous, avec fallback sur LinkedIn si tu laisses les champs vides.</p>
									<p>Automatisation: tentative immédiate à la publication, retry WP-Cron toutes les 5 minutes, et fallback serveur possible avec <code>wp pksocialsharing retry --network=x --limit=20</code>.</p>
								</div>
							</div>
							<div class="pks-checkrow" style="margin-top:12px;">
								<span class="pks-pill pks-pill--ok">TEXTE</span>
								<div style="width:100%;">
									<strong>Personnalisation X</strong>
									<p class="pks-info" style="margin:4px 0 10px;">Laisse vide pour reprendre les réglages LinkedIn. Tu peux définir un ordre différent, par exemple sans URL en premier.</p>
									<div class="pks-subbox">
										<p class="pks-subtitle">Composition</p>
										<label class="pks-inline">
											<input type="hidden" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_include_title]" value="0"/>
											<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_include_title]" value="1" <?php checked(1, (int)$opt['x_include_title']); ?>/>
											Inclure le titre
										</label>
										<label class="pks-inline">
											<input type="hidden" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_include_excerpt]" value="0"/>
											<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_include_excerpt]" value="1" <?php checked(1, (int)$opt['x_include_excerpt']); ?>/>
											Inclure l’extrait
										</label>
										<label class="pks-inline">
											<input type="hidden" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_include_url]" value="0"/>
											<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_include_url]" value="1" <?php checked(1, (int)$opt['x_include_url']); ?>/>
											Inclure l’URL
										</label>
										<label>Ordre du contenu<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_content_order]" value="<?php echo esc_attr((string)$opt['x_content_order']); ?>" placeholder="title,excerpt,url"/></label>
										<p class="description" style="margin:4px 0 0;">Exemples: <code>title,excerpt,url</code> ou <code>title,url</code>. Laisser vide = ordre LinkedIn.</p>
										<p class="pks-subtitle" style="margin-top:12px;">Personnalisation</p>
										<label>Préfixe<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_prefix]" value="<?php echo esc_attr((string)$opt['x_prefix']); ?>"/></label>
										<label>Suffixe<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_suffix]" value="<?php echo esc_attr((string)$opt['x_suffix']); ?>"/></label>
										<label>Template avancé (optionnel)<br/>
											<textarea class="large-text code" rows="4" name="<?php echo esc_attr(self::OPT_KEY); ?>[x_text_template]" placeholder="{title}{br2}{excerpt}{br2}{url}"><?php echo esc_textarea((string)$opt['x_text_template']); ?></textarea>
										</label>
										<p class="description" style="margin:4px 0 0;">Variables: <code>{prefix}</code>, <code>{title}</code>, <code>{excerpt}</code>, <code>{url}</code>, <code>{suffix}</code>. Sauts: <code>{br}</code>, <code>{br2}</code>. Laisser vide = template LinkedIn.</p>
									</div>
								</div>
							</div>
							<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
						</form>

						<div class="pks-card pks-card--wide">
							<div class="pks-card-title">Test X</div>
							<p class="pks-info" style="margin:0 0 12px;">Choisissez un article publié. <strong>Publier maintenant</strong> utilise l’API X et nécessite des crédits. <strong>Publier via navigateur</strong> ouvre X avec le texte prérempli et ne consomme pas de crédits API.</p>
							<?php
							$x_test_limit = max(20, absint($_GET[‘test_limit_x’] ?? 0) ?: 20);
							$posts = get_posts([
								‘post_type’ => $opt[‘post_type_whitelist’],
								‘post_status’ => ‘publish’,
								‘numberposts’ => $x_test_limit,
								‘orderby’ => ‘date’,
								‘order’ => ‘DESC’,
							]);
							?>
							<table class="widefat striped" style="width:100%;">
								<thead>
									<tr>
										<th style="width:60px;">ID</th>
										<th style="width:64px;">Image</th>
										<th>Article</th>
										<th style="width:220px;">Statut X</th>
										<th style="width:180px;">Action</th>
									</tr>
								</thead>
								<tbody>
								<?php if (!$posts): ?>
									<tr><td colspan="5">Aucun article publié trouvé.</td></tr>
								<?php else: ?>
									<?php foreach ($posts as $p): ?>
										<?php
										$x_shared_at = (int)get_post_meta($p->ID, self::META_X_SHARED_AT, true);
										$x_post_id = (string)get_post_meta($p->ID, self::META_X_POST_ID, true);
										$x_post_url = $x_post_id ? ('https://x.com/i/web/status/' . rawurlencode($x_post_id)) : '';
										$x_status = $x_shared_at ? ('Partagé le ' . esc_html(wp_date('Y-m-d H:i', $x_shared_at)) . ($x_post_id ? '<br/><code style="font-size:11px;">' . esc_html($x_post_id) . '</code>' : '') . ($x_post_url ? '<br/><a href="' . esc_url($x_post_url) . '" target="_blank" rel="noopener">Voir sur X</a>' : '')) : 'Jamais partagé';
										$x_action_url = wp_nonce_url(self::admin_url_action('pkliap_test_post') . '&post_id=' . (int)$p->ID . '&network=x', 'pkliap_test_post_' . (int)$p->ID);
										$x_intent_url = self::build_x_intent_url((int)$p->ID, $opt);
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
											<td><?php echo $x_status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											<td>
												<a class="button button-secondary" href="<?php echo esc_url($x_action_url); ?>">Publier maintenant</a>
												<a class="button" style="margin-top:6px;" href="<?php echo esc_url($x_intent_url); ?>" target="_blank" rel="noopener">Publier via navigateur</a>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
								</tbody>
							</table>
							<?php if (count($posts) >= $x_test_limit): ?>
								<p style="text-align:center;margin:12px 0 0;">
									<a class="button button-secondary" href="<?php echo esc_url(add_query_arg('test_limit_x', $x_test_limit + 20, $link_tab_x)); ?>">Charger plus d'articles</a>
								</p>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif ($active_network === 'facebook'): ?>
					<div class="pks-grid">
						<?php self::render_meta_token_card($opt, $link_meta_graph_explorer); ?>
						<form method="post" action="options.php" class="pks-card pks-card--accent-blue">
							<div class="pks-card-title">Facebook: connexion</div>
							<?php settings_fields('pkliap'); ?>
							<p class="pks-info" style="margin:-4px 0 12px;">Facebook publie sur une Page: il faut un <strong>Page ID</strong> et un <strong>Page Access Token</strong>.</p>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">Page ID</th>
									<td>
										<input class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_page_id]" value="<?php echo esc_attr((string)$opt['fb_page_id']); ?>"/>
										<p class="description" style="margin-top:6px;">Clique ici : <a href="<?php echo esc_url($link_meta_graph_explorer); ?>" target="_blank" rel="noopener">https://developers.facebook.com/tools/explorer/</a></p>
										<p class="description" style="margin-top:6px;">Dans Meta, lance cette requete : <code>me/accounts?fields=id,name,access_token</code></p>
										<p class="description" style="margin-top:6px;">Dans la reponse, colle ici la valeur : <code>id</code> de la Page qui publiera.</p>
									</td>
								</tr>
								<tr>
									<th scope="row">Page Access Token</th>
									<td>
										<input class="regular-text" type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_access_token]" value="<?php echo esc_attr((string)$opt['fb_access_token']); ?>"/>
										<p class="description" style="margin-top:6px;">Clique ici : <a href="<?php echo esc_url($link_meta_graph_explorer); ?>" target="_blank" rel="noopener">https://developers.facebook.com/tools/explorer/</a></p>
										<p class="description" style="margin-top:6px;">Dans Meta, clique sur <strong>Generate Access Token</strong>, coche les permissions ci-dessous, puis lance <code>me/accounts?fields=id,name,access_token</code>.</p>
										<p class="description" style="margin-top:6px;">Dans la reponse, colle ici la valeur : <code>access_token</code> de la Page qui publiera.</p>
										<p class="description" style="margin-top:6px;">Permissions minimales a demander : <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>pages_manage_posts</code>.</p>
									</td>
								</tr>
							</table>
							<div class="pks-checkrow" style="margin-top:12px;">
								<span class="pks-pill pks-pill--ok">3</span>
								<div>
									<strong>Tester et valider</strong>
									<p>Quand les deux champs sont remplis, enregistre puis clique sur <strong>Publier maintenant</strong> dans le bloc de test Facebook.</p>
								</div>
							</div>
							<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
						</form>

						<form method="post" action="options.php" class="pks-card pks-card--accent-ok">
							<div class="pks-card-title">Publication Facebook</div>
							<?php settings_fields('pkliap'); ?>
							<label class="pks-inline"><input type="hidden" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_enabled]" value="0"/><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_enabled]" value="1" <?php checked(1, (int)$opt['fb_enabled']); ?>/> Publier automatiquement sur Facebook</label>
							<p class="pks-info" style="margin:8px 0 0;">Publication via <code>/{page-id}/feed</code> (message + lien).</p>
							<div class="pks-checkrow" style="margin-top:12px;">
								<span class="pks-pill pks-pill--ok">TEXTE</span>
								<div style="width:100%;">
									<strong>Personnalisation Facebook</strong>
									<p class="pks-info" style="margin:4px 0 10px;">Réglages dédiés à Facebook. Laisse vide pour reprendre la configuration LinkedIn.</p>
									<div class="pks-subbox">
										<p class="pks-subtitle">Composition</p>
										<label class="pks-inline">
											<input type="hidden" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_include_title]" value="0"/>
											<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_include_title]" value="1" <?php checked(1, (int)$opt['fb_include_title']); ?>/>
											Inclure le titre
										</label>
										<label class="pks-inline">
											<input type="hidden" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_include_excerpt]" value="0"/>
											<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_include_excerpt]" value="1" <?php checked(1, (int)$opt['fb_include_excerpt']); ?>/>
											Inclure l’extrait
										</label>
										<label class="pks-inline">
											<input type="hidden" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_include_url]" value="0"/>
											<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_include_url]" value="1" <?php checked(1, (int)$opt['fb_include_url']); ?>/>
											Inclure l’URL
										</label>
										<label>Ordre du contenu<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_content_order]" value="<?php echo esc_attr((string)$opt['fb_content_order']); ?>" placeholder="title,excerpt,url"/></label>
										<p class="description" style="margin:4px 0 0;">Exemples: <code>title,excerpt,url</code> ou <code>title,excerpt</code>. Laisser vide = ordre LinkedIn.</p>
										<p class="pks-subtitle" style="margin-top:12px;">Personnalisation</p>
										<label>Préfixe<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_prefix]" value="<?php echo esc_attr((string)$opt['fb_prefix']); ?>"/></label>
										<label>Suffixe<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_suffix]" value="<?php echo esc_attr((string)$opt['fb_suffix']); ?>"/></label>
										<label>Template avancé (optionnel)<br/>
											<textarea class="large-text code" rows="4" name="<?php echo esc_attr(self::OPT_KEY); ?>[fb_text_template]" placeholder="{title}{br2}{excerpt}{br2}{url}"><?php echo esc_textarea((string)$opt['fb_text_template']); ?></textarea>
										</label>
										<p class="description" style="margin:4px 0 0;">Variables: <code>{prefix}</code>, <code>{title}</code>, <code>{excerpt}</code>, <code>{url}</code>, <code>{suffix}</code>. Sauts: <code>{br}</code>, <code>{br2}</code>. Laisser vide = template LinkedIn.</p>
									</div>
								</div>
							</div>
							<?php if (!empty($opt['last_fb_error'])): ?>
								<p class="description" style="color:#b32d2e;">Dernière erreur Facebook: <?php echo esc_html((string)$opt['last_fb_error']); ?></p>
							<?php endif; ?>
							<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
						</form>

						<div class="pks-card pks-card--wide">
							<div class="pks-card-title">Test Facebook</div>
							<p class="pks-info" style="margin:0 0 12px;">Docs: <a href="<?php echo esc_url($link_meta_fb_pages_docs); ?>" target="_blank" rel="noopener">Pages API Posts</a></p>
							<?php
							$fb_test_limit = max(20, absint($_GET['test_limit_fb'] ?? 0) ?: 20);
							$posts = get_posts([
								'post_type' => $opt['post_type_whitelist'],
								'post_status' => 'publish',
								'numberposts' => $fb_test_limit,
								'orderby' => 'date',
								'order' => 'DESC',
							]);
							?>
							<table class="widefat striped" style="width:100%;">
								<thead>
									<tr>
										<th style="width:60px;">ID</th>
										<th style="width:64px;">Image</th>
										<th>Article</th>
										<th style="width:220px;">Statut Facebook</th>
										<th style="width:180px;">Action</th>
									</tr>
								</thead>
								<tbody>
								<?php if (!$posts): ?>
									<tr><td colspan="5">Aucun article publié trouvé.</td></tr>
								<?php else: ?>
									<?php foreach ($posts as $p): ?>
										<?php
										$fb_shared_at = (int)get_post_meta($p->ID, self::META_FB_SHARED_AT, true);
										$fb_post_id = (string)get_post_meta($p->ID, self::META_FB_POST_ID, true);
										$fb_post_url = $fb_post_id ? ('https://www.facebook.com/' . rawurlencode($fb_post_id)) : '';
										$fb_status = $fb_shared_at ? ('Partagé le ' . esc_html(wp_date('Y-m-d H:i', $fb_shared_at)) . ($fb_post_id ? '<br/><code style="font-size:11px;">' . esc_html($fb_post_id) . '</code>' : '') . ($fb_post_url ? '<br/><a href="' . esc_url($fb_post_url) . '" target="_blank" rel="noopener">Voir sur Facebook</a>' : '')) : 'Jamais partagé';
										$fb_action_url = wp_nonce_url(self::admin_url_action('pkliap_test_post') . '&post_id=' . (int)$p->ID . '&network=facebook', 'pkliap_test_post_' . (int)$p->ID);
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
											<td><?php echo $fb_status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											<td><a class="button button-secondary" href="<?php echo esc_url($fb_action_url); ?>">Publier maintenant</a></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
								</tbody>
							</table>
							<?php if (count($posts) >= $fb_test_limit): ?>
								<p style="text-align:center;margin:12px 0 0;">
									<a class="button button-secondary" href="<?php echo esc_url(add_query_arg('test_limit_fb', $fb_test_limit + 20, $link_tab_facebook)); ?>">Charger plus d'articles</a>
								</p>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif ($active_network === 'instagram'): ?>
					<div class="pks-grid">
						<?php self::render_meta_token_card($opt, $link_meta_graph_explorer); ?>
						<form method="post" action="options.php" class="pks-card pks-card--accent-blue">
							<div class="pks-card-title">Instagram: connexion en 3 étapes</div>
							<?php settings_fields('pkliap'); ?>
							<p class="pks-info" style="margin:-4px 0 12px;">Instagram publie sur un compte professionnel lié à une Page Facebook: l'identifiant attendu ici est <strong>instagram_business_account.id</strong>. Le token est stocké séparément de Facebook.</p>
							<div class="pks-checkrow" style="margin-bottom:12px;">
								<span class="pks-pill pks-pill--warn">1</span>
								<div>
									<strong>Ouvre Meta et récupère un token</strong>
									<p>Utilise le bouton ci-dessous pour ouvrir l’outil Meta, puis génère un token avec les permissions Instagram et Pages.</p>
									<p style="margin:8px 0 0;">
										<a class="button button-primary" href="<?php echo esc_url($link_meta_graph_explorer); ?>" target="_blank" rel="noopener">1. Ouvrir Meta Graph Explorer</a>
									</p>
								</div>
							</div>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">IG User ID</th>
									<td>
										<input class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[ig_user_id]" value="<?php echo esc_attr((string)$opt['ig_user_id']); ?>"/>
										<p class="description" style="margin-top:6px;">
											Clique ici : <a href="<?php echo esc_url($link_meta_graph_explorer); ?>" target="_blank" rel="noopener">https://developers.facebook.com/tools/explorer/</a>
										</p>
										<p class="description" style="margin-top:6px;">
											Dans Meta, lance cette requête : <code>me/accounts?fields=id,name,instagram_business_account{id,username}</code>
										</p>
										<p class="description" style="margin-top:6px;">
											Dans la réponse, colle ici la valeur : <code>instagram_business_account.id</code>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">Access Token</th>
									<td>
										<input class="regular-text" type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[ig_access_token]" value="<?php echo esc_attr((string)$opt['ig_access_token']); ?>"/>
										<p class="description">Clique ici : <a href="<?php echo esc_url($link_meta_graph_explorer); ?>" target="_blank" rel="noopener">https://developers.facebook.com/tools/explorer/</a></p>
										<p class="description" style="margin-top:6px;">
											Dans Meta, clique sur <strong>Generate Access Token</strong>, coche les permissions ci-dessous, puis colle ici le token généré.
										</p>
										<p class="description" style="margin-top:6px;">
											Permissions minimales à demander : <code>instagram_basic</code>, <code>instagram_content_publish</code>, <code>pages_show_list</code>, <code>pages_read_engagement</code>. Selon la config Meta, <code>pages_manage_metadata</code> peut aussi être nécessaire pour remonter correctement le compte Instagram lié à la Page.
										</p>
									</td>
								</tr>
							</table>
							<div class="pks-checkrow" style="margin-top:12px;">
								<span class="pks-pill pks-pill--ok">3</span>
								<div>
									<strong>Tester et valider</strong>
									<p>Quand les deux champs sont remplis, enregistre puis clique sur <strong>Publier maintenant</strong> dans le bloc de test Instagram.</p>
								</div>
							</div>
							<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
						</form>

						<form method="post" action="options.php" class="pks-card pks-card--accent-ok">
							<div class="pks-card-title">Publication Instagram</div>
							<?php settings_fields('pkliap'); ?>
							<label class="pks-inline"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[ig_enabled]" value="1" <?php checked(1, (int)$opt['ig_enabled']); ?>/> Publier automatiquement sur Instagram</label>
							<p class="pks-info" style="margin:8px 0 0;">Nécessite une image mise en avant publique (featured image).</p>
							<?php if (!empty($opt['last_ig_error'])): ?>
								<p class="description" style="color:#b32d2e;">Dernière erreur Instagram: <?php echo esc_html((string)$opt['last_ig_error']); ?></p>
								<p style="margin:8px 0 0;">
									<a class="button" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_clear_network_error') . '&network=instagram', 'pkliap_clear_network_error_instagram')); ?>">Effacer cette ancienne erreur</a>
								</p>
							<?php endif; ?>
							<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
						</form>

						<div class="pks-card pks-card--wide">
							<div class="pks-card-title">Test Instagram</div>
							<p class="pks-info" style="margin:0 0 12px;">Docs: <a href="<?php echo esc_url($link_meta_ig_publish_docs); ?>" target="_blank" rel="noopener">Instagram Content Publishing</a></p>
							<?php
							$ig_test_limit = max(20, absint($_GET['test_limit_ig'] ?? 0) ?: 20);
							$posts = get_posts([
								'post_type' => $opt['post_type_whitelist'],
								'post_status' => 'publish',
								'numberposts' => $ig_test_limit,
								'orderby' => 'date',
								'order' => 'DESC',
							]);
							?>
							<table class="widefat striped" style="width:100%;">
								<thead>
									<tr>
										<th style="width:60px;">ID</th>
										<th style="width:64px;">Image</th>
										<th>Article</th>
										<th style="width:220px;">Statut Instagram</th>
										<th style="width:180px;">Action</th>
									</tr>
								</thead>
								<tbody>
								<?php if (!$posts): ?>
									<tr><td colspan="5">Aucun article publié trouvé.</td></tr>
								<?php else: ?>
									<?php foreach ($posts as $p): ?>
										<?php
										$ig_shared_at = (int)get_post_meta($p->ID, self::META_IG_SHARED_AT, true);
										$ig_media_id = (string)get_post_meta($p->ID, self::META_IG_MEDIA_ID, true);
										$ig_permalink = (string)get_post_meta($p->ID, self::META_IG_PERMALINK, true);
										$ig_status = $ig_shared_at ? ('Partagé le ' . esc_html(wp_date('Y-m-d H:i', $ig_shared_at)) . ($ig_media_id ? '<br/><code style="font-size:11px;">' . esc_html($ig_media_id) . '</code>' : '') . ($ig_permalink ? '<br/><a href="' . esc_url($ig_permalink) . '" target="_blank" rel="noopener">Voir sur Instagram</a>' : '')) : 'Jamais partagé';
										$ig_action_url = wp_nonce_url(self::admin_url_action('pkliap_test_post') . '&post_id=' . (int)$p->ID . '&network=instagram', 'pkliap_test_post_' . (int)$p->ID);
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
											<td><?php echo $ig_status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											<td><a class="button button-secondary" href="<?php echo esc_url($ig_action_url); ?>">Publier maintenant</a></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
								</tbody>
							</table>
							<?php if (count($posts) >= $ig_test_limit): ?>
								<p style="text-align:center;margin:12px 0 0;">
									<a class="button button-secondary" href="<?php echo esc_url(add_query_arg('test_limit_ig', $ig_test_limit + 20, $link_tab_instagram)); ?>">Charger plus d'articles</a>
								</p>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif ($active_network === 'threads'): ?>
					<div class="pks-grid">
						<form method="post" action="options.php" class="pks-card pks-card--accent-blue">
							<div class="pks-card-title">Threads: connexion en 3 etapes</div>
							<?php settings_fields('pkliap'); ?>
							<p class="pks-info" style="margin:-4px 0 12px;">Threads publie sur un profil Threads: il faut un <strong>Threads User ID</strong> et un token Threads avec les permissions <code>threads_basic</code> + <code>threads_content_publish</code>.</p>
							<div class="pks-checkrow" style="margin-bottom:12px;">
								<span class="pks-pill pks-pill--warn">1</span>
								<div>
									<strong>Ouvre Meta Threads</strong>
									<p>Utilise le bouton ci-dessous pour ouvrir le guide de depart Threads, puis genere un token utilisateur Threads avec la permission de publication.</p>
									<p style="margin:8px 0 0;">
										<a class="button button-primary" href="<?php echo esc_url($link_meta_threads_tokens); ?>" target="_blank" rel="noopener">1. Ouvrir le guide token Threads</a>
									</p>
								</div>
							</div>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">Threads User ID</th>
									<td>
										<input class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[threads_user_id]" value="<?php echo esc_attr((string)$opt['threads_user_id']); ?>"/>
										<p class="description" style="margin-top:6px;">Clique ici : <a href="<?php echo esc_url($link_meta_threads_get_started); ?>" target="_blank" rel="noopener"><?php echo esc_html($link_meta_threads_get_started); ?></a></p>
										<p class="description" style="margin-top:6px;">Une fois le token obtenu, demande ton profil Threads avec <code>GET /me?fields=id,username</code>.</p>
										<p class="description" style="margin-top:6px;">Colle ici la valeur : <code>id</code>.</p>
									</td>
								</tr>
								<tr>
									<th scope="row">Threads Access Token</th>
									<td>
										<input class="regular-text" type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[threads_access_token]" value="<?php echo esc_attr((string)$opt['threads_access_token']); ?>"/>
										<p class="description">Clique ici : <a href="<?php echo esc_url($link_meta_threads_tokens); ?>" target="_blank" rel="noopener"><?php echo esc_html($link_meta_threads_tokens); ?></a></p>
										<p class="description" style="margin-top:6px;">Dans Meta, recupere un Threads user access token avec au minimum <code>threads_basic</code> et <code>threads_content_publish</code>, puis colle ici le token.</p>
									</td>
								</tr>
							</table>
							<div class="pks-checkrow" style="margin-top:12px;">
								<span class="pks-pill pks-pill--ok">3</span>
								<div>
									<strong>Tester et valider</strong>
									<p>Quand les deux champs sont remplis, enregistre puis clique sur <strong>Publier maintenant</strong> dans le bloc de test Threads.</p>
								</div>
							</div>
							<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
						</form>

						<form method="post" action="options.php" class="pks-card pks-card--accent-ok">
							<div class="pks-card-title">Publication Threads</div>
							<?php settings_fields('pkliap'); ?>
							<label class="pks-inline"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[threads_enabled]" value="1" <?php checked(1, (int)$opt['threads_enabled']); ?>/> Publier automatiquement sur Threads</label>
							<p class="pks-info" style="margin:8px 0 0;">Publication texte via la Threads API officielle. Limite de texte Threads: 500 caracteres.</p>
							<?php if (!empty($opt['last_threads_error'])): ?>
								<p class="description" style="color:#b32d2e;">Derniere erreur Threads: <?php echo esc_html((string)$opt['last_threads_error']); ?></p>
							<?php endif; ?>
							<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
						</form>

						<div class="pks-card pks-card--wide">
							<div class="pks-card-title">Test Threads</div>
							<p class="pks-info" style="margin:0 0 12px;">Docs: <a href="<?php echo esc_url($link_meta_threads_docs); ?>" target="_blank" rel="noopener">Threads API Posts</a></p>
							<?php
							$threads_test_limit = max(20, absint($_GET['test_limit_threads'] ?? 0) ?: 20);
							$posts = get_posts([
								'post_type' => $opt['post_type_whitelist'],
								'post_status' => 'publish',
								'numberposts' => $threads_test_limit,
								'orderby' => 'date',
								'order' => 'DESC',
							]);
							?>
							<table class="widefat striped" style="width:100%;">
								<thead>
									<tr>
										<th style="width:60px;">ID</th>
										<th style="width:64px;">Image</th>
										<th>Article</th>
										<th style="width:220px;">Statut Threads</th>
										<th style="width:180px;">Action</th>
									</tr>
								</thead>
								<tbody>
								<?php if (!$posts): ?>
									<tr><td colspan="5">Aucun article publie trouve.</td></tr>
								<?php else: ?>
									<?php foreach ($posts as $p): ?>
										<?php
										$threads_shared_at = (int)get_post_meta($p->ID, self::META_THREADS_SHARED_AT, true);
										$threads_post_id = (string)get_post_meta($p->ID, self::META_THREADS_POST_ID, true);
										$threads_status = $threads_shared_at ? ('Partage le ' . esc_html(wp_date('Y-m-d H:i', $threads_shared_at)) . ($threads_post_id ? '<br/><code style="font-size:11px;">' . esc_html($threads_post_id) . '</code>' : '')) : 'Jamais partage';
										$threads_action_url = wp_nonce_url(self::admin_url_action('pkliap_test_post') . '&post_id=' . (int)$p->ID . '&network=threads', 'pkliap_test_post_' . (int)$p->ID);
										$edit_url = get_edit_post_link($p->ID, '');
										$thumb_html = get_the_post_thumbnail($p->ID, [48, 48], ['style' => 'width:48px;height:48px;object-fit:cover;border-radius:4px;']);
										?>
										<tr>
											<td><?php echo (int)$p->ID; ?></td>
											<td><?php echo $thumb_html ?: '<span style="opacity:.6;">-</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											<td>
												<strong><?php echo esc_html($p->post_title ?: '(Sans titre)'); ?></strong>
												<div class="row-actions">
													<span><a href="<?php echo esc_url(get_permalink($p->ID)); ?>" target="_blank" rel="noopener">Voir</a> | </span>
													<span><a href="<?php echo esc_url($edit_url ?: '#'); ?>">Modifier</a></span>
												</div>
											</td>
											<td><?php echo $threads_status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											<td><a class="button button-secondary" href="<?php echo esc_url($threads_action_url); ?>">Publier maintenant</a></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
								</tbody>
							</table>
							<?php if (count($posts) >= $threads_test_limit): ?>
								<p style="text-align:center;margin:12px 0 0;">
									<a class="button button-secondary" href="<?php echo esc_url(add_query_arg('test_limit_threads', $threads_test_limit + 20, $link_tab_threads)); ?>">Charger plus d'articles</a>
								</p>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif ($active_network === 'medium'): ?>
					<div class="pks-grid">
						<form method="post" action="options.php" class="pks-card pks-card--accent-blue">
							<div class="pks-card-title">Medium: connexion</div>
							<?php settings_fields('pkliap'); ?>
							<p class="pks-info" style="margin:-4px 0 12px;">Medium utilise un <strong>integration token</strong>. Le plugin peut détecter le User ID avec ce token, ou tu peux le coller manuellement.</p>
							<div class="pks-checkrow" style="margin-bottom:12px;">
								<span class="pks-pill pks-pill--warn">1</span>
								<div>
									<strong>Récupérer le token</strong>
									<p>Ouvre les réglages Medium, section Integration tokens, puis génère un token pour ce plugin. Medium peut restreindre cette option selon les comptes.</p>
									<p style="margin:8px 0 0;">
										<a class="button button-primary" href="<?php echo esc_url($link_medium_settings); ?>" target="_blank" rel="noopener">Ouvrir Medium Settings</a>
										<a class="button" href="<?php echo esc_url($link_medium_docs); ?>" target="_blank" rel="noopener">Docs API Medium</a>
									</p>
								</div>
							</div>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">Medium Access Token</th>
									<td>
										<input class="regular-text" type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[medium_access_token]" value="<?php echo esc_attr((string)$opt['medium_access_token']); ?>"/>
										<p class="description">Token Medium avec permission de publication. Le plugin appelle <code>/v1/me</code> pour vérifier le compte.</p>
									</td>
								</tr>
								<tr>
									<th scope="row">Medium User ID</th>
									<td>
										<input class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[medium_user_id]" value="<?php echo esc_attr((string)$opt['medium_user_id']); ?>"/>
										<p class="description">Optionnel si le token est valide: le plugin le remplit automatiquement après un test réussi.</p>
									</td>
								</tr>
							</table>
							<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
						</form>

						<form method="post" action="options.php" class="pks-card pks-card--accent-ok">
							<div class="pks-card-title">Publication Medium</div>
							<?php settings_fields('pkliap'); ?>
							<label class="pks-inline"><input type="hidden" name="<?php echo esc_attr(self::OPT_KEY); ?>[medium_enabled]" value="0"/><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[medium_enabled]" value="1" <?php checked(1, (int)$opt['medium_enabled']); ?>/> Publier automatiquement sur Medium</label>
							<p class="pks-info" style="margin:8px 0 12px;">Le post Medium reprend le contenu HTML de l’article WordPress et renseigne l’URL canonique vers l’article original.</p>
							<label>Statut de publication<br/>
								<select name="<?php echo esc_attr(self::OPT_KEY); ?>[medium_publish_status]">
									<option value="public" <?php selected('public', (string)$opt['medium_publish_status']); ?>>Public</option>
									<option value="draft" <?php selected('draft', (string)$opt['medium_publish_status']); ?>>Brouillon</option>
									<option value="unlisted" <?php selected('unlisted', (string)$opt['medium_publish_status']); ?>>Non listé</option>
								</select>
							</label>
							<?php if (!empty($opt['last_medium_error'])): ?>
								<p class="description" style="color:#b32d2e;">Dernière erreur Medium: <?php echo esc_html((string)$opt['last_medium_error']); ?></p>
								<p style="margin:8px 0 0;">
									<a class="button" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_clear_network_error') . '&network=medium', 'pkliap_clear_network_error_medium')); ?>">Effacer cette ancienne erreur</a>
								</p>
							<?php endif; ?>
							<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
						</form>

						<div class="pks-card pks-card--wide">
							<div class="pks-card-title">Test Medium</div>
							<p class="pks-info" style="margin:0 0 12px;">Choisissez un article publié pour créer le post Medium.</p>
							<?php
							$medium_test_limit = max(20, absint($_GET['test_limit_medium'] ?? 0) ?: 20);
							$posts = get_posts([
								'post_type' => $opt['post_type_whitelist'],
								'post_status' => 'publish',
								'numberposts' => $medium_test_limit,
								'orderby' => 'date',
								'order' => 'DESC',
							]);
							?>
							<table class="widefat striped" style="width:100%;">
								<thead>
									<tr>
										<th style="width:60px;">ID</th>
										<th style="width:64px;">Image</th>
										<th>Article</th>
										<th style="width:220px;">Statut Medium</th>
										<th style="width:180px;">Action</th>
									</tr>
								</thead>
								<tbody>
								<?php if (!$posts): ?>
									<tr><td colspan="5">Aucun article publié trouvé.</td></tr>
								<?php else: ?>
									<?php foreach ($posts as $p): ?>
										<?php
										$medium_shared_at = (int)get_post_meta($p->ID, self::META_MEDIUM_SHARED_AT, true);
										$medium_post_id = (string)get_post_meta($p->ID, self::META_MEDIUM_POST_ID, true);
										$medium_post_url = (string)get_post_meta($p->ID, self::META_MEDIUM_POST_URL, true);
										$medium_status = $medium_shared_at ? ('Partagé le ' . esc_html(wp_date('Y-m-d H:i', $medium_shared_at)) . ($medium_post_id ? '<br/><code style="font-size:11px;">' . esc_html($medium_post_id) . '</code>' : '') . ($medium_post_url ? '<br/><a href="' . esc_url($medium_post_url) . '" target="_blank" rel="noopener">Voir sur Medium</a>' : '')) : 'Jamais partagé';
										$medium_action_url = wp_nonce_url(self::admin_url_action('pkliap_test_post') . '&post_id=' . (int)$p->ID . '&network=medium', 'pkliap_test_post_' . (int)$p->ID);
										$edit_url = get_edit_post_link($p->ID, '');
										$thumb_html = get_the_post_thumbnail($p->ID, [48, 48], ['style' => 'width:48px;height:48px;object-fit:cover;border-radius:4px;']);
										?>
										<tr>
											<td><?php echo (int)$p->ID; ?></td>
											<td><?php echo $thumb_html ?: '<span style="opacity:.6;">-</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											<td>
												<strong><?php echo esc_html($p->post_title ?: '(Sans titre)'); ?></strong>
												<div class="row-actions">
													<span><a href="<?php echo esc_url(get_permalink($p->ID)); ?>" target="_blank" rel="noopener">Voir</a> | </span>
													<span><a href="<?php echo esc_url($edit_url ?: '#'); ?>">Modifier</a></span>
												</div>
											</td>
											<td><?php echo $medium_status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											<td><a class="button button-secondary" href="<?php echo esc_url($medium_action_url); ?>">Publier maintenant</a></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
								</tbody>
							</table>
							<?php if (count($posts) >= $medium_test_limit): ?>
								<p style="text-align:center;margin:12px 0 0;">
									<a class="button button-secondary" href="<?php echo esc_url(add_query_arg('test_limit_medium', $medium_test_limit + 20, $link_tab_medium)); ?>">Charger plus d'articles</a>
								</p>
							<?php endif; ?>
						</div>
					</div>
				<?php else: ?>
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
						<div class="pks-card-title">LinkedIn: connexion en 3 étapes</div>
						<?php
						$linkedin_step2_ok = $has_token;
						$linkedin_step3_ok = self::linkedin_account_looks_ready($opt);
						$linkedin_test_msg = trim((string)($opt['last_linkedin_dry_run_message'] ?? ''));
						?>
						<div class="pks-checklist" style="margin:0 0 12px;">
							<div class="pks-checkrow">
								<span class="pks-pill pks-pill--warn">1</span>
								<div>
									<strong>Réinitialiser l’ancienne connexion</strong>
									<p>Efface l’ancien token, l’Author URN et les erreurs LinkedIn stockées.</p>
									<p style="margin:8px 0 0;"><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_linkedin_step1'), 'pkliap_linkedin_step1')); ?>">1. Réinitialiser</a></p>
								</div>
							</div>
							<div class="pks-checkrow">
								<span class="pks-pill <?php echo $linkedin_step2_ok ? 'pks-pill--ok' : 'pks-pill--bad'; ?>"><?php echo $linkedin_step2_ok ? 'OK' : 'KO'; ?></span>
								<div>
									<strong>Reconnecter LinkedIn</strong>
									<p><?php echo $linkedin_step2_ok ? 'Token LinkedIn valide.' : 'Ouvre LinkedIn, autorise l’application, puis revient ici automatiquement.'; ?></p>
									<p style="margin:8px 0 0;"><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_connect'), 'pkliap_connect')); ?>">2. Reconnecter</a></p>
								</div>
							</div>
							<div class="pks-checkrow">
								<span class="pks-pill <?php echo $linkedin_step3_ok ? 'pks-pill--ok' : 'pks-pill--bad'; ?>"><?php echo $linkedin_step3_ok ? 'OK' : 'KO'; ?></span>
								<div>
									<strong>Finaliser + tester sans publier</strong>
									<p><?php echo $linkedin_step3_ok ? esc_html($linkedin_test_msg ?: 'Compte LinkedIn finalisé: token valide + Author URN détecté.') : 'Détecte l’Author URN puis vérifie le compte LinkedIn sans créer de post.'; ?></p>
									<p style="margin:8px 0 0;"><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_linkedin_step3'), 'pkliap_linkedin_step3')); ?>">3. Finaliser + tester</a></p>
								</div>
							</div>
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
						<div class="pks-checksplit pks-publication-split">
							<div class="pks-checklist">
								<div class="pks-checkrow">
									<span class="pks-pill pks-pill--ok">AUTO</span>
									<div>
										<strong>Activer</strong>
										<label class="pks-inline"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[enabled]" value="1" <?php checked(1, (int)$opt['enabled']); ?>/> Publier automatiquement</label>
										<p>Déclenchement lors du passage en statut <code>publish</code>.</p>
									</div>
								</div>
								<div class="pks-checkrow">
									<span class="pks-pill pks-pill--ok">CIBLES</span>
									<div>
										<strong>Types de contenu</strong>
										<?php foreach ($post_types as $pt): ?>
											<label style="display:block;margin:2px 0;">
												<input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[post_type_whitelist][]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $opt['post_type_whitelist'], true)); ?>/>
												<?php echo esc_html($pt->labels->singular_name . ' (' . $pt->name . ')'); ?>
											</label>
										<?php endforeach; ?>
									</div>
								</div>
								<div class="pks-checkrow">
									<span class="pks-pill pks-pill--ok">LIEN</span>
									<div>
										<strong>Lien</strong>
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
									</div>
								</div>
							</div>
							<div class="pks-checklist">
								<div class="pks-checkrow">
									<span class="pks-pill pks-pill--ok">TEXTE</span>
									<div>
										<strong>Texte</strong>
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
											<p class="pks-subtitle" style="margin-top:12px;">Personnalisation</p>
											<label>Préfixe<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[prefix]" value="<?php echo esc_attr($opt['prefix']); ?>"/></label>
											<label>Suffixe<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[suffix]" value="<?php echo esc_attr($opt['suffix']); ?>"/></label>
											<label>Template avancé (optionnel)<br/>
												<textarea class="large-text code" rows="4" name="<?php echo esc_attr(self::OPT_KEY); ?>[text_template]" placeholder="{url}{br}{title}{br2}{excerpt}"><?php echo esc_textarea((string)$opt['text_template']); ?></textarea>
											</label>
											<p class="description" style="margin:4px 0 0;">Variables: <code>{prefix}</code>, <code>{title}</code>, <code>{excerpt}</code>, <code>{url}</code>, <code>{suffix}</code>. Sauts: <code>{br}</code> = nouvelle ligne, <code>{br2}</code> = ligne vide. Compatibilité aussi avec <code>/n</code> et <code>/n/n</code>.</p>
										</div>
									</div>
								</div>
								<div class="pks-checkrow">
									<span class="pks-pill pks-pill--ok">RÈGLES</span>
									<div>
										<strong>Anti-doublon</strong>
										<label class="pks-inline"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[only_once]" value="1" <?php checked(1, (int)$opt['only_once']); ?>/> Publier une seule fois</label>
										<label class="pks-inline"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[share_on_update]" value="1" <?php checked(1, (int)$opt['share_on_update']); ?>/> Republier lors d’une mise à jour</label>
									</div>
								</div>
								<div class="pks-checkrow">
									<span class="pks-pill pks-pill--ok">MEDIA</span>
									<div>
										<strong>Image</strong>
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
									</div>
								</div>
							</div>
						</div>
						<?php submit_button('Enregistrer', 'primary', 'submit', false); ?>
					</form>

					<div class="pks-card pks-card--wide">
						<div class="pks-card-title">Test</div>
						<p class="pks-info" style="margin:0 0 12px;">Choisissez un article publié pour tenter un partage LinkedIn.</p>
						<?php
						$linkedin_test_limit = max(20, absint($_GET['test_limit_linkedin'] ?? 0) ?: 20);
						$posts = get_posts([
							'post_type' => $opt['post_type_whitelist'],
							'post_status' => 'publish',
							'numberposts' => $linkedin_test_limit,
							'orderby' => 'date',
							'order' => 'DESC',
						]);
						?>
						<table class="widefat striped" style="width:100%;">
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
						<?php if (count($posts) >= $linkedin_test_limit): ?>
							<p style="text-align:center;margin:12px 0 0;">
								<a class="button button-secondary" href="<?php echo esc_url(add_query_arg('test_limit_linkedin', $linkedin_test_limit + 20, $link_tab_linkedin)); ?>">Charger plus d'articles</a>
							</p>
						<?php endif; ?>
					</div>

					<div class="pks-card pks-card--accent-warn pks-card--wide">
						<div class="pks-card-title">Debug / Logs</div>
						<?php if (!empty($pending_alerts)): ?>
							<p class="pks-info" style="margin:-4px 0 12px;color:#b45309;">Des alertes actives sont détectées. Corrige la cause puis recharge la page pour les faire disparaître.</p>
						<?php endif; ?>
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
								<tr>
									<th scope="row">Journal interne</th>
									<td>
										<?php
										$debug_log = is_array($opt['debug_log']) ? array_slice(array_reverse($opt['debug_log']), 0, 12) : [];
										?>
										<?php if (!$debug_log): ?>
											<p class="description" style="margin:0;">Aucun événement récent.</p>
										<?php else: ?>
											<div style="max-height:220px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;background:#fff;padding:8px 10px;">
												<?php foreach ($debug_log as $entry): ?>
													<?php
													$ts = isset($entry['ts']) ? (int)$entry['ts'] : 0;
													$msg = isset($entry['message']) ? (string)$entry['message'] : '';
													?>
													<div style="margin:0 0 8px;padding-bottom:8px;border-bottom:1px dashed #e5e7eb;">
														<div style="font-size:11px;opacity:.7;"><?php echo esc_html($ts ? wp_date('Y-m-d H:i:s', $ts) : '-'); ?></div>
														<div style="font-size:12px;white-space:pre-wrap;"><?php echo esc_html($msg); ?></div>
													</div>
												<?php endforeach; ?>
											</div>
										<?php endif; ?>
									</td>
								</tr>
							</table>
							<?php submit_button('Enregistrer', 'secondary', 'submit', false); ?>
						</form>
					</div>
				</div>
				<?php endif; ?>
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
			wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
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
			wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
			exit;
		}

		$code = isset($_GET['code']) ? sanitize_text_field((string)wp_unslash($_GET['code'])) : '';
		if (!$code) {
			self::set_flash('error', 'Code OAuth manquant.');
			wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
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
			wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
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
			wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
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
			'last_share_error' => '',
			'last_share_error_at' => 0,
		]);

		$notice = 'Étape 2 terminée: LinkedIn est reconnecté. Lance maintenant l’étape 3 pour vérifier l’Author URN.';
		$error = '';

		// Auto-détection de l'URN profil (urn:li:person:...) pour éviter une config manuelle.
		$opt_after = self::get_options();
		// Sanity check: si le token n'est pas stocké, on remonte une erreur explicite.
		if (empty($opt_after['access_token'])) {
			self::update_options([
				'last_oauth_error' => 'Token non persisté après OAuth. Suspect: plugin de sécurité/cache, object-cache, ou restriction base de données.',
			]);
			self::set_flash('error', 'Connecté, mais le token n’a pas été persisté. Désactive temporairement Wordfence/cache, puis reconnecte.');
			wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
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
					'last_share_error' => '',
					'last_share_error_at' => 0,
				]);
				$notice = 'Étape 2 terminée: LinkedIn est reconnecté. Author URN détecté automatiquement.';
			}
		}

		if ($error) {
			self::set_flash('error', $error);
		} else {
			self::set_flash('notice', $notice);
		}
		wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
		exit;
	}

	public static function handle_linkedin_step1(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_linkedin_step1');

		self::set_tokens([
			'access_token' => '',
			'access_token_expires_at' => 0,
			'refresh_token' => '',
			'refresh_token_expires_at' => 0,
		]);
		self::update_options([
			'last_linkedin_dry_run_ok' => 0,
			'last_linkedin_dry_run_at' => 0,
			'last_linkedin_dry_run_message' => '',
			'author_urn' => '',
			'last_share_error' => '',
			'last_share_error_at' => 0,
			'last_oauth_error' => '',
			'last_author_detect_error' => '',
		]);
		self::set_flash('notice', 'Étape 1 terminée: ancienne connexion LinkedIn effacée. Clique maintenant sur 2. Reconnecter.');
		wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
		exit;
	}

	public static function handle_linkedin_step3(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_linkedin_step3');
		self::detect_author_and_redirect();
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
		wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
		exit;
	}

	public static function handle_detect_author(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_detect_author');
		self::detect_author_and_redirect();
	}

	private static function detect_author_and_redirect(): void {
		$opt = self::get_options();
		if (empty($opt['access_token'])) {
			self::set_flash('error', 'Non connecté à LinkedIn (token manquant).');
			wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
			exit;
		}

		$me_id = self::linkedin_get_member_id($opt['access_token']);
		if (is_wp_error($me_id)) {
			self::update_options([
				'last_author_detect_error' => $me_id->get_error_message(),
				'last_linkedin_dry_run_ok' => 0,
				'last_linkedin_dry_run_at' => time(),
				'last_linkedin_dry_run_message' => $me_id->get_error_message(),
			]);
			self::set_flash('error', 'Impossible de détecter automatiquement le profil LinkedIn. ' . $me_id->get_error_message());
			wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
			exit;
		}

		self::update_options([
			'author_urn' => 'urn:li:person:' . $me_id,
			'last_author_detect_error' => '',
			'last_share_error' => '',
			'last_share_error_at' => 0,
			'last_linkedin_dry_run_ok' => 1,
			'last_linkedin_dry_run_at' => time(),
			'last_linkedin_dry_run_message' => 'Author URN détecté et test dry-run LinkedIn OK.',
		]);

		self::set_flash('notice', 'Étape 3 terminée: Author URN détecté et test dry-run LinkedIn OK.');
		wp_safe_redirect(self::settings_url(['network' => 'linkedin']));
		exit;
	}

	public static function handle_recheck_connections(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_recheck_connections');

		$opt = self::get_options();
		$messages = [];

		if (!empty($opt['access_token'])) {
			$refresh = self::maybe_refresh_linkedin_token($opt);
			if (is_wp_error($refresh)) {
				self::update_options([
					'last_share_error' => $refresh->get_error_message(),
					'last_share_error_at' => time(),
				]);
				$messages[] = 'LinkedIn: ' . $refresh->get_error_message();
			} else {
				$me_id = self::linkedin_get_member_id(self::get_options()['access_token']);
				if (is_wp_error($me_id)) {
					self::update_options([
						'last_share_error' => $me_id->get_error_message(),
						'last_share_error_at' => time(),
					]);
					$messages[] = 'LinkedIn: ' . $me_id->get_error_message();
				} else {
					self::update_options([
						'last_share_error' => '',
						'last_share_error_at' => 0,
					]);
					$messages[] = 'LinkedIn: OK';
				}
			}
		}

		if (!empty($opt['x_api_key']) && !empty($opt['x_api_secret']) && !empty($opt['x_access_token']) && !empty($opt['x_access_token_secret'])) {
			$x_check = self::x_get_me($opt);
			if (is_wp_error($x_check)) {
				self::update_options([
					'last_x_error' => $x_check->get_error_message(),
					'last_x_error_at' => time(),
				]);
				$messages[] = 'X: ' . $x_check->get_error_message();
			} else {
				self::update_options([
					'last_x_error' => '',
					'last_x_error_at' => 0,
				]);
				$messages[] = 'X: OK';
			}
		}

		$meta_ok = true;
		if (!empty($opt['fb_page_id']) && !empty($opt['fb_access_token'])) {
			$fb_check = self::meta_graph_get('/me', ['fields' => 'id'], (string)$opt['fb_access_token']);
			if (is_wp_error($fb_check)) {
				self::update_options([
					'last_fb_error' => $fb_check->get_error_message(),
					'last_fb_error_at' => time(),
				]);
				$messages[] = 'Facebook: ' . $fb_check->get_error_message();
				$meta_ok = false;
			} else {
				self::update_options([
					'last_fb_error' => '',
					'last_fb_error_at' => 0,
				]);
				$messages[] = 'Facebook: OK';
			}
		}
		if (!empty($opt['ig_user_id']) && !empty($opt['ig_access_token'])) {
			$ig_check = self::meta_graph_get('/' . rawurlencode((string)$opt['ig_user_id']), ['fields' => 'id'], (string)$opt['ig_access_token']);
			if (is_wp_error($ig_check)) {
				self::update_options([
					'last_ig_error' => $ig_check->get_error_message(),
					'last_ig_error_at' => time(),
				]);
				$messages[] = 'Instagram: ' . $ig_check->get_error_message();
				$meta_ok = false;
			} else {
				self::update_options([
					'last_ig_error' => '',
					'last_ig_error_at' => 0,
				]);
				$messages[] = 'Instagram: OK';
			}
		}
		if (!empty($opt['threads_user_id']) && !empty($opt['threads_access_token'])) {
			$threads_check = self::threads_api_get('/me', ['fields' => 'id,username'], (string)$opt['threads_access_token']);
			if (is_wp_error($threads_check)) {
				self::update_options([
					'last_threads_error' => $threads_check->get_error_message(),
					'last_threads_error_at' => time(),
				]);
				$messages[] = 'Threads: ' . $threads_check->get_error_message();
				$meta_ok = false;
			} else {
				self::update_options([
					'last_threads_error' => '',
					'last_threads_error_at' => 0,
				]);
				$messages[] = 'Threads: OK';
			}
		}
		if (!empty($opt['medium_access_token'])) {
			$medium_check = self::medium_get_me((string)$opt['medium_access_token']);
			if (is_wp_error($medium_check)) {
				self::update_options([
					'last_medium_error' => $medium_check->get_error_message(),
					'last_medium_error_at' => time(),
				]);
				$messages[] = 'Medium: ' . $medium_check->get_error_message();
				$meta_ok = false;
			} else {
				$medium_user_id = (string)($medium_check['id'] ?? '');
				self::update_options([
					'medium_user_id' => $medium_user_id ?: (string)$opt['medium_user_id'],
					'last_medium_error' => '',
					'last_medium_error_at' => 0,
				]);
				$messages[] = 'Medium: OK';
			}
		}

		if (!$messages) {
			$messages[] = 'Aucune connexion active à vérifier.';
		}

		self::set_flash($meta_ok ? 'notice' : 'error', implode(' | ', $messages));
		wp_safe_redirect(self::settings_url());
		exit;
	}

	private static function maybe_recheck_network_errors(string $active_network, array $opt): void {
		if ($active_network === 'dashboard') {
			return;
		}
		if ($active_network === 'linkedin' && !empty($opt['access_token'])) {
			$me_id = self::linkedin_get_member_id((string)$opt['access_token']);
			if (is_wp_error($me_id)) {
				self::update_options(['last_share_error' => $me_id->get_error_message(), 'last_share_error_at' => time()]);
			} else {
				self::update_options(['last_share_error' => '', 'last_share_error_at' => 0]);
			}
		}
		if ($active_network === 'x' && !empty($opt['x_access_token'])) {
			$x_check = self::x_get_me($opt);
			if (is_wp_error($x_check)) {
				self::update_options(['last_x_error' => $x_check->get_error_message(), 'last_x_error_at' => time()]);
			} else {
				self::update_options(['last_x_error' => '', 'last_x_error_at' => 0]);
			}
		}
		if ($active_network === 'facebook' && !empty($opt['fb_access_token'])) {
			$fb_check = self::meta_graph_get('/me', ['fields' => 'id'], (string)$opt['fb_access_token']);
			if (is_wp_error($fb_check)) {
				self::update_options(['last_fb_error' => $fb_check->get_error_message(), 'last_fb_error_at' => time()]);
			} else {
				self::update_options(['last_fb_error' => '', 'last_fb_error_at' => 0]);
			}
		}
		if ($active_network === 'instagram' && !empty($opt['ig_access_token'])) {
			$ig_check = self::meta_graph_get('/' . rawurlencode((string)$opt['ig_user_id']), ['fields' => 'id'], (string)$opt['ig_access_token']);
			if (is_wp_error($ig_check)) {
				self::update_options(['last_ig_error' => $ig_check->get_error_message(), 'last_ig_error_at' => time()]);
			} else {
				self::update_options(['last_ig_error' => '', 'last_ig_error_at' => 0]);
			}
		}
		if ($active_network === 'threads' && !empty($opt['threads_access_token'])) {
			$threads_check = self::threads_api_get('/me', ['fields' => 'id,username'], (string)$opt['threads_access_token']);
			if (is_wp_error($threads_check)) {
				self::update_options(['last_threads_error' => $threads_check->get_error_message(), 'last_threads_error_at' => time()]);
			} else {
				self::update_options(['last_threads_error' => '', 'last_threads_error_at' => 0]);
			}
		}
		if ($active_network === 'medium' && !empty($opt['medium_access_token'])) {
			$medium_check = self::medium_get_me((string)$opt['medium_access_token']);
			if (is_wp_error($medium_check)) {
				self::update_options(['last_medium_error' => $medium_check->get_error_message(), 'last_medium_error_at' => time()]);
			} else {
				$medium_user_id = (string)($medium_check['id'] ?? '');
				self::update_options([
					'medium_user_id' => $medium_user_id ?: (string)$opt['medium_user_id'],
					'last_medium_error' => '',
					'last_medium_error_at' => 0,
				]);
			}
		}
	}

	public static function handle_dry_run_connections(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_dry_run_connections');

		$opt = self::get_options();
		$messages = [];

		if (!empty($opt['access_token']) && !empty($opt['client_id']) && !empty($opt['client_secret'])) {
			$refresh = self::maybe_refresh_linkedin_token($opt);
			if (is_wp_error($refresh)) {
				self::update_options([
					'last_share_error' => $refresh->get_error_message(),
					'last_share_error_at' => time(),
				]);
				$messages[] = 'LinkedIn: ' . $refresh->get_error_message();
			} else {
				$me = self::linkedin_get_member_id(self::get_options()['access_token']);
				if (is_wp_error($me)) {
					self::update_options([
						'last_share_error' => $me->get_error_message(),
						'last_share_error_at' => time(),
						'last_linkedin_dry_run_ok' => 0,
						'last_linkedin_dry_run_at' => time(),
						'last_linkedin_dry_run_message' => $me->get_error_message(),
					]);
					$messages[] = 'LinkedIn: ' . $me->get_error_message();
				} else {
					self::update_options([
						'last_share_error' => '',
						'last_share_error_at' => 0,
						'last_linkedin_dry_run_ok' => 1,
						'last_linkedin_dry_run_at' => time(),
						'last_linkedin_dry_run_message' => 'Author URN détecté et test dry-run LinkedIn OK.',
					]);
					$messages[] = 'LinkedIn: auth OK, author OK, dry run OK';
				}
			}
		} else {
			$messages[] = 'LinkedIn: configuration incomplète';
		}

		if (!empty($opt['x_api_key']) && !empty($opt['x_api_secret']) && !empty($opt['x_access_token']) && !empty($opt['x_access_token_secret'])) {
			$x_me = self::x_get_me($opt);
			$messages[] = is_wp_error($x_me) ? ('X: ' . $x_me->get_error_message()) : 'X: auth OK, dry run OK';
		} else {
			$messages[] = 'X: configuration incomplète';
		}

		if (!empty($opt['fb_access_token'])) {
			$fb_me = self::meta_graph_get('/me', ['fields' => 'id'], (string)$opt['fb_access_token']);
			$messages[] = is_wp_error($fb_me) ? ('Facebook: ' . $fb_me->get_error_message()) : 'Facebook: auth OK, dry run OK';
		} else {
			$messages[] = 'Facebook: configuration incomplète';
		}

		if (!empty($opt['ig_access_token'])) {
			$ig_me = self::meta_graph_get('/me', ['fields' => 'id'], (string)$opt['ig_access_token']);
			$messages[] = is_wp_error($ig_me) ? ('Instagram: ' . $ig_me->get_error_message()) : 'Instagram: auth OK, dry run OK';
		} else {
			$messages[] = 'Instagram: configuration incomplète';
		}
		if (!empty($opt['threads_access_token'])) {
			$threads_me = self::threads_api_get('/me', ['fields' => 'id,username'], (string)$opt['threads_access_token']);
			$messages[] = is_wp_error($threads_me) ? ('Threads: ' . $threads_me->get_error_message()) : 'Threads: auth OK, dry run OK';
		} else {
			$messages[] = 'Threads: configuration incomplete';
		}

		self::set_flash('notice', implode(' | ', $messages));
		wp_safe_redirect(self::settings_url());
		exit;
	}

	public static function handle_x_connect(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_x_connect');

		$opt = self::get_options();
		if (empty($opt['x_api_key']) || empty($opt['x_api_secret'])) {
			self::set_flash('error', 'Renseignez API Key / API Key Secret X avant de connecter.');
			wp_safe_redirect(self::settings_url(['network' => 'x']));
			exit;
		}

		$request_token_res = self::x_request_token((string)$opt['x_api_key'], (string)$opt['x_api_secret'], self::admin_url_action('pkliap_x_oauth_callback'));
		if (is_wp_error($request_token_res)) {
			self::update_options([
				'last_x_error' => $request_token_res->get_error_message(),
				'last_x_error_at' => time(),
			]);
			self::set_flash('error', $request_token_res->get_error_message());
			wp_safe_redirect(self::settings_url(['network' => 'x']));
			exit;
		}

		$oauth_token = (string)($request_token_res['oauth_token'] ?? '');
		$oauth_token_secret = (string)($request_token_res['oauth_token_secret'] ?? '');
		if ($oauth_token === '' || $oauth_token_secret === '') {
			self::set_flash('error', 'X OAuth: réponse request_token invalide.');
			wp_safe_redirect(self::settings_url(['network' => 'x']));
			exit;
		}

		$user_id = get_current_user_id();
		set_transient('pkliap_x_req_' . $user_id . '_' . md5($oauth_token), $oauth_token_secret, 10 * MINUTE_IN_SECONDS);
		wp_redirect('https://api.x.com/oauth/authenticate?oauth_token=' . rawurlencode($oauth_token));
		exit;
	}

	public static function handle_x_oauth_callback(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}

		$oauth_token = isset($_GET['oauth_token']) ? sanitize_text_field((string)wp_unslash($_GET['oauth_token'])) : '';
		$oauth_verifier = isset($_GET['oauth_verifier']) ? sanitize_text_field((string)wp_unslash($_GET['oauth_verifier'])) : '';
		if ($oauth_token === '' || $oauth_verifier === '') {
			self::set_flash('error', 'X OAuth: callback incomplet.');
			wp_safe_redirect(self::settings_url(['network' => 'x']));
			exit;
		}

		$user_id = get_current_user_id();
		$request_token_secret = (string)get_transient('pkliap_x_req_' . $user_id . '_' . md5($oauth_token));
		delete_transient('pkliap_x_req_' . $user_id . '_' . md5($oauth_token));
		if ($request_token_secret === '') {
			self::set_flash('error', 'X OAuth: session expirée, reconnecte.');
			wp_safe_redirect(self::settings_url(['network' => 'x']));
			exit;
		}

		$opt = self::get_options();
		$access_res = self::x_access_token(
			(string)$opt['x_api_key'],
			(string)$opt['x_api_secret'],
			$oauth_token,
			$request_token_secret,
			$oauth_verifier
		);
		if (is_wp_error($access_res)) {
			self::update_options([
				'last_x_error' => $access_res->get_error_message(),
				'last_x_error_at' => time(),
			]);
			self::set_flash('error', $access_res->get_error_message());
			wp_safe_redirect(self::settings_url(['network' => 'x']));
			exit;
		}

		self::update_options([
			'x_access_token' => (string)($access_res['oauth_token'] ?? ''),
			'x_access_token_secret' => (string)($access_res['oauth_token_secret'] ?? ''),
			'x_user_id' => (string)($access_res['user_id'] ?? ''),
			'x_screen_name' => (string)($access_res['screen_name'] ?? ''),
			'last_x_error' => '',
			'last_x_error_at' => 0,
		]);
		self::set_flash('notice', 'Connecté à X.');
		wp_safe_redirect(self::settings_url(['network' => 'x']));
		exit;
	}

	public static function handle_x_disconnect(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_x_disconnect');

		self::update_options([
			'x_access_token' => '',
			'x_access_token_secret' => '',
			'x_user_id' => '',
			'x_screen_name' => '',
			'last_x_error' => '',
			'last_x_error_at' => 0,
		]);
		self::set_flash('notice', 'Déconnecté de X.');
		wp_safe_redirect(self::settings_url(['network' => 'x']));
		exit;
	}

	public static function handle_x_check(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_x_check');

		$opt = self::get_options();
		if (empty($opt['x_api_key']) || empty($opt['x_api_secret'])) {
			self::update_options([
				'last_x_check_message' => 'Configuration incomplète: API Key / API Key Secret manquants.',
				'last_x_check_at' => time(),
			]);
			self::set_flash('error', 'X: API Key / API Key Secret manquants.');
			wp_safe_redirect(self::settings_url(['network' => 'x']));
			exit;
		}
		if (empty($opt['x_access_token']) || empty($opt['x_access_token_secret'])) {
			self::update_options([
				'last_x_check_message' => 'Compte non connecté: token utilisateur OAuth 1.0a manquant.',
				'last_x_check_at' => time(),
			]);
			self::set_flash('error', 'X: compte non connecté.');
			wp_safe_redirect(self::settings_url(['network' => 'x']));
			exit;
		}

		$me = self::x_get_me($opt);
		if (is_wp_error($me)) {
			$msg = $me->get_error_message();
			self::update_options([
				'last_x_error' => $msg,
				'last_x_error_at' => time(),
				'last_x_check_message' => self::humanize_share_error('x', $msg),
				'last_x_check_at' => time(),
			]);
			self::set_flash('error', 'X: ' . self::humanize_share_error('x', $msg));
			wp_safe_redirect(self::settings_url(['network' => 'x']));
			exit;
		}

		$screen_name = (string)($opt['x_screen_name'] ?? '');
		if (isset($me['data']['username']) && is_string($me['data']['username']) && $me['data']['username'] !== '') {
			$screen_name = $me['data']['username'];
		}
		$message = 'Authentification OK' . ($screen_name !== '' ? ' pour @' . $screen_name : '') . '. Pour valider la publication réelle, utilise “Publier maintenant” sur un article.';
		self::update_options([
			'last_x_check_message' => $message,
			'last_x_check_at' => time(),
		]);
		self::set_flash('notice', 'X: ' . $message);
		wp_safe_redirect(self::settings_url(['network' => 'x']));
		exit;
	}

	public static function handle_clear_network_error(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		$network = isset($_GET['network']) ? sanitize_key((string)wp_unslash($_GET['network'])) : '';
		check_admin_referer('pkliap_clear_network_error_' . $network);

		$map = [
			'facebook' => ['last_fb_error', 'last_fb_error_at'],
			'instagram' => ['last_ig_error', 'last_ig_error_at'],
			'threads' => ['last_threads_error', 'last_threads_error_at'],
			'medium' => ['last_medium_error', 'last_medium_error_at'],
			'x' => ['last_x_error', 'last_x_error_at'],
			'linkedin' => ['last_share_error', 'last_share_error_at'],
		];
		if (!isset($map[$network])) {
			self::set_flash('error', 'Réseau invalide.');
			wp_safe_redirect(self::settings_url());
			exit;
		}

		self::update_options([
			$map[$network][0] => '',
			$map[$network][1] => 0,
		]);
		self::set_flash('notice', 'Ancienne erreur effacée.');
		wp_safe_redirect(self::settings_url(['network' => $network]));
		exit;
	}

	public static function handle_meta_connect(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_meta_connect');

		$opt = self::get_options();
		$app_id = trim((string)($opt['meta_app_id'] ?? ''));
		$app_secret = trim((string)($opt['meta_app_secret'] ?? ''));

		if ($app_id === '' || $app_secret === '') {
			self::update_options([
				'last_meta_token_error' => 'Meta: App ID et App Secret sont obligatoires.',
				'last_meta_token_at' => time(),
			]);
			wp_safe_redirect(self::settings_url(['network' => 'facebook']));
			exit;
		}

		$redirect_uri = self::admin_url_action('pkliap_meta_oauth_callback');
		$state = wp_generate_password(24, false, false);
		$user_id = get_current_user_id();
		set_transient('pkliap_meta_oauth_state_' . $user_id, $state, 10 * MINUTE_IN_SECONDS);

		$scope = [
			'pages_show_list',
			'pages_read_engagement',
			'pages_manage_posts',
			'instagram_basic',
			'instagram_content_publish',
		];

		$auth_url = 'https://www.facebook.com/v23.0/dialog/oauth?' . http_build_query([
			'client_id' => $app_id,
			'redirect_uri' => $redirect_uri,
			'state' => $state,
			'scope' => implode(',', $scope),
			'response_type' => 'code',
		], '', '&', PHP_QUERY_RFC3986);

		wp_redirect($auth_url);
		exit;
	}

	public static function handle_meta_oauth_callback(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}

		$state = isset($_GET['state']) ? sanitize_text_field((string)wp_unslash($_GET['state'])) : '';
		$user_id = get_current_user_id();
		$expected_state = (string)get_transient('pkliap_meta_oauth_state_' . $user_id);
		delete_transient('pkliap_meta_oauth_state_' . $user_id);

		if (!$state || !$expected_state || !hash_equals($expected_state, $state)) {
			self::update_options([
				'last_meta_token_error' => 'State OAuth Meta invalide. Réessaie la connexion.',
				'last_meta_token_at' => time(),
			]);
			wp_safe_redirect(self::settings_url(['network' => 'facebook']));
			exit;
		}

		if (isset($_GET['error'])) {
			$error_msg = sanitize_text_field((string)wp_unslash($_GET['error']));
			$error_desc = isset($_GET['error_description']) ? sanitize_text_field((string)wp_unslash($_GET['error_description'])) : '';
			self::update_options([
				'last_meta_token_error' => 'Meta OAuth refusé : ' . $error_msg . ($error_desc ? ' — ' . $error_desc : ''),
				'last_meta_token_at' => time(),
			]);
			wp_safe_redirect(self::settings_url(['network' => 'facebook']));
			exit;
		}

		$code = isset($_GET['code']) ? sanitize_text_field((string)wp_unslash($_GET['code'])) : '';
		if (!$code) {
			self::update_options([
				'last_meta_token_error' => 'Meta OAuth: code manquant dans le callback.',
				'last_meta_token_at' => time(),
			]);
			wp_safe_redirect(self::settings_url(['network' => 'facebook']));
			exit;
		}

		$opt = self::get_options();
		$app_id = trim((string)($opt['meta_app_id'] ?? ''));
		$app_secret = trim((string)($opt['meta_app_secret'] ?? ''));
		$redirect_uri = self::admin_url_action('pkliap_meta_oauth_callback');

		$short_res = wp_remote_get(add_query_arg([
			'client_id' => $app_id,
			'redirect_uri' => $redirect_uri,
			'client_secret' => $app_secret,
			'code' => $code,
		], 'https://graph.facebook.com/v23.0/oauth/access_token'), ['timeout' => 45]);

		if (is_wp_error($short_res)) {
			self::update_options([
				'last_meta_token_error' => 'Meta: impossible de récupérer le token court. ' . $short_res->get_error_message(),
				'last_meta_token_at' => time(),
			]);
			wp_safe_redirect(self::settings_url(['network' => 'facebook']));
			exit;
		}

		$short_code = (int)wp_remote_retrieve_response_code($short_res);
		$short_body = json_decode((string)wp_remote_retrieve_body($short_res), true);

		if ($short_code < 200 || $short_code >= 300) {
			$msg = 'Meta: échange code → token court échoué (HTTP ' . $short_code . ').';
			if (is_array($short_body) && !empty($short_body['error']['message'])) {
				$msg .= ' ' . (string)$short_body['error']['message'];
			}
			self::update_options([
				'last_meta_token_error' => $msg,
				'last_meta_token_at' => time(),
			]);
			wp_safe_redirect(self::settings_url(['network' => 'facebook']));
			exit;
		}

		$short_token = (string)($short_body['access_token'] ?? '');
		if ($short_token === '') {
			self::update_options([
				'last_meta_token_error' => 'Meta: réponse token court invalide (access_token manquant).',
				'last_meta_token_at' => time(),
			]);
			wp_safe_redirect(self::settings_url(['network' => 'facebook']));
			exit;
		}

		$exchange = self::meta_exchange_long_lived_token($app_id, $app_secret, $short_token);
		if (is_wp_error($exchange)) {
			self::update_options([
				'last_meta_token_error' => $exchange->get_error_message(),
				'last_meta_token_at' => time(),
			]);
			wp_safe_redirect(self::settings_url(['network' => 'facebook']));
			exit;
		}

		$long_token = (string)($exchange['access_token'] ?? '');
		$expires_in = (int)($exchange['expires_in'] ?? 0);
		$expires_at = $expires_in > 0 ? (time() + $expires_in - 60) : 0;
		$updates = [
			'meta_user_access_token' => $long_token,
			'meta_user_access_token_expires_at' => $expires_at,
			'ig_access_token' => $long_token,
			'last_meta_token_error' => '',
			'last_meta_token_at' => time(),
			'last_fb_error' => '',
			'last_fb_error_at' => 0,
			'last_ig_error' => '',
			'last_ig_error_at' => 0,
		];

		$accounts = self::meta_graph_get('/me/accounts', [
			'fields' => 'id,name,access_token,instagram_business_account{id,username}',
		], $long_token);
		if (!is_wp_error($accounts)) {
			$pages = is_array($accounts['body']['data'] ?? null) ? $accounts['body']['data'] : [];
			$selected = self::select_meta_page($pages, (string)($opt['fb_page_id'] ?? ''));
			if ($selected) {
				if (!empty($selected['id'])) {
					$updates['fb_page_id'] = (string)$selected['id'];
				}
				if (!empty($selected['access_token'])) {
					$updates['fb_access_token'] = (string)$selected['access_token'];
				}
				if (!empty($selected['instagram_business_account']['id'])) {
					$updates['ig_user_id'] = (string)$selected['instagram_business_account']['id'];
				}
			}
		}

		$message = 'Connexion Meta réussie. Token longue durée généré.';
		if ($expires_at > 0) {
			$message .= ' Expire le ' . wp_date('Y-m-d H:i', $expires_at) . '.';
		}
		if (!empty($updates['fb_page_id'])) {
			$message .= ' Page Facebook détectée.';
		}
		if (!empty($updates['ig_user_id'])) {
			$message .= ' Compte Instagram détecté.';
		}
		$updates['last_meta_token_message'] = $message;
		self::update_options($updates);

		wp_safe_redirect(self::settings_url(['network' => 'facebook']));
		exit;
	}

	public static function handle_test_post(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		$post_id = isset($_REQUEST['post_id']) ? (int)$_REQUEST['post_id'] : 0;
		$network = isset($_REQUEST['network']) ? sanitize_key((string)wp_unslash($_REQUEST['network'])) : 'linkedin';
		if (!in_array($network, ['linkedin', 'x', 'facebook', 'instagram', 'threads', 'medium'], true)) {
			$network = 'linkedin';
		}
		if (!$post_id) {
			self::set_flash('error', 'post_id manquant.');
			wp_safe_redirect(self::settings_url(['network' => $network]));
			exit;
		}
		check_admin_referer('pkliap_test_post_' . $post_id);

		try {
			if ($network === 'x') {
				$res = self::share_post_to_x($post_id, true);
			} elseif ($network === 'facebook') {
				$res = self::share_post_to_facebook($post_id, true);
			} elseif ($network === 'instagram') {
				$res = self::share_post_to_instagram($post_id, true);
			} elseif ($network === 'threads') {
				$res = self::share_post_to_threads($post_id, true);
			} elseif ($network === 'medium') {
				$res = self::share_post_to_medium($post_id, true);
			} else {
				$res = self::share_post_to_linkedin($post_id, true);
			}
		} catch (Throwable $e) {
			$msg = 'Exception PHP: ' . get_class($e) . ' - ' . $e->getMessage();
			if ($network === 'x') {
				self::update_options(['last_x_error' => $msg, 'last_x_error_at' => time()]);
			} elseif ($network === 'facebook') {
				self::update_options(['last_fb_error' => $msg, 'last_fb_error_at' => time()]);
			} elseif ($network === 'instagram') {
				self::update_options(['last_ig_error' => $msg, 'last_ig_error_at' => time()]);
			} elseif ($network === 'threads') {
				self::update_options(['last_threads_error' => $msg, 'last_threads_error_at' => time()]);
			} elseif ($network === 'medium') {
				self::update_options(['last_medium_error' => $msg, 'last_medium_error_at' => time()]);
			} else {
				self::update_options(['last_share_error' => $msg, 'last_share_error_at' => time()]);
			}
			self::debug_log_event($msg);
			self::debug_log_event($e->getTraceAsString());
			self::set_flash('error', $msg);
			wp_safe_redirect(self::settings_url(['network' => $network]));
			exit;
		}
		if (is_wp_error($res)) {
			if ($network === 'x') {
				self::update_options(['last_x_error' => $res->get_error_message(), 'last_x_error_at' => time()]);
				self::maybe_notify_admin_failure($network, $post_id, $res->get_error_message());
			} elseif ($network === 'facebook') {
				self::update_options(['last_fb_error' => $res->get_error_message(), 'last_fb_error_at' => time()]);
				self::maybe_notify_admin_failure($network, $post_id, $res->get_error_message());
			} elseif ($network === 'instagram') {
				self::update_options(['last_ig_error' => $res->get_error_message(), 'last_ig_error_at' => time()]);
				self::maybe_notify_admin_failure($network, $post_id, $res->get_error_message());
			} elseif ($network === 'threads') {
				self::update_options(['last_threads_error' => $res->get_error_message(), 'last_threads_error_at' => time()]);
				self::maybe_notify_admin_failure($network, $post_id, $res->get_error_message());
			} elseif ($network === 'medium') {
				self::update_options(['last_medium_error' => $res->get_error_message(), 'last_medium_error_at' => time()]);
				self::maybe_notify_admin_failure($network, $post_id, $res->get_error_message());
			} else {
				self::update_options(['last_share_error' => $res->get_error_message(), 'last_share_error_at' => time()]);
				self::maybe_notify_admin_failure($network, $post_id, $res->get_error_message());
			}
			self::debug_log_event('Test share ' . strtoupper($network) . ' failed for post #' . $post_id . ': ' . $res->get_error_message());
			self::set_flash('error', $res->get_error_message());
			wp_safe_redirect(self::settings_url(['network' => $network]));
			exit;
		}

		if ($network === 'x') {
			self::update_options(['last_x_error' => '', 'last_x_error_at' => 0]);
		} elseif ($network === 'facebook') {
			self::update_options(['last_fb_error' => '', 'last_fb_error_at' => 0]);
		} elseif ($network === 'instagram') {
			self::update_options(['last_ig_error' => '', 'last_ig_error_at' => 0]);
		} elseif ($network === 'threads') {
			self::update_options(['last_threads_error' => '', 'last_threads_error_at' => 0]);
		} elseif ($network === 'medium') {
			self::update_options(['last_medium_error' => '', 'last_medium_error_at' => 0]);
		} else {
			self::update_options(['last_share_error' => '', 'last_share_error_at' => 0]);
		}
		$notice = 'Post envoyé.';
		if ($network === 'x') {
			$notice = 'Post X envoyé.';
		} elseif ($network === 'facebook') {
			$notice = 'Post Facebook envoyé.';
		} elseif ($network === 'instagram') {
			$notice = 'Post Instagram envoyé.';
		} elseif ($network === 'threads') {
			$notice = 'Post Threads envoyé.';
		} elseif ($network === 'medium') {
			$notice = 'Post Medium envoyé.';
		} else {
			$notice = 'Post LinkedIn envoyé.';
		}
		if (is_array($res) && !empty($res['warning'])) {
			$notice .= ' ' . (string)$res['warning'];
			self::debug_log_event('Test share ' . strtoupper($network) . ' warning for post #' . $post_id . ': ' . (string)$res['warning']);
		}
		self::debug_log_event('Test share ' . strtoupper($network) . ' success for post #' . $post_id . '.');
		self::set_flash('notice', $notice);
		wp_safe_redirect(self::settings_url(['network' => $network]));
		exit;
	}

	public static function on_transition_post_status(string $new_status, string $old_status, WP_Post $post): void {
		if ($new_status !== 'publish') {
			return;
		}

		$opt = self::get_options();
		if (empty($opt['enabled']) && empty($opt['x_enabled']) && empty($opt['fb_enabled']) && empty($opt['ig_enabled']) && empty($opt['threads_enabled']) && empty($opt['medium_enabled'])) {
			return;
		}

		if (!in_array($post->post_type, (array)$opt['post_type_whitelist'], true)) {
			return;
		}

		$is_update = ($old_status === 'publish');
		if ($is_update && empty($opt['share_on_update'])) {
			return;
		}

		$linkedin_already = !empty(get_post_meta($post->ID, self::META_SHARED_AT, true));
		$x_already = !empty(get_post_meta($post->ID, self::META_X_SHARED_AT, true));
		$fb_already = !empty(get_post_meta($post->ID, self::META_FB_SHARED_AT, true));
		$ig_already = !empty(get_post_meta($post->ID, self::META_IG_SHARED_AT, true));
		$threads_already = !empty(get_post_meta($post->ID, self::META_THREADS_SHARED_AT, true));
		$medium_already = !empty(get_post_meta($post->ID, self::META_MEDIUM_SHARED_AT, true));
		if (!empty($opt['only_once']) && $linkedin_already && $x_already && $fb_already && $ig_already && $threads_already && $medium_already && empty($opt['share_on_update'])) {
			return;
		}

		// LinkedIn est traité immédiatement pour éviter qu'un WP-Cron en retard bloque l'autopublication.
		if (!empty($opt['enabled']) && (!$linkedin_already || !empty($opt['share_on_update']))) {
			try {
				$linkedin_res = self::share_post_to_linkedin($post->ID, false);
				if (is_wp_error($linkedin_res)) {
					self::handle_share_error('linkedin', $post->ID, $linkedin_res->get_error_message(), 'last_share_error');
					self::maybe_notify_admin_failure('linkedin', $post->ID, $linkedin_res->get_error_message());
				} else {
					self::update_options(['last_share_error' => '', 'last_share_error_at' => 0]);
					self::debug_log_event("Immediate LinkedIn share success for post #{$post->ID}.");
				}
			} catch (Throwable $e) {
				self::handle_share_error('linkedin', $post->ID, $e->getMessage(), 'last_share_error');
				self::maybe_notify_admin_failure('linkedin', $post->ID, $e->getMessage());
			}
		}

		// X est aussi tenté immédiatement: le cron WordPress peut être retardé ou désactivé sur certains hébergements.
		if (!empty($opt['x_enabled']) && (!$x_already || !empty($opt['share_on_update']))) {
			try {
				$x_res = self::share_post_to_x($post->ID, false);
				if (is_wp_error($x_res)) {
					self::handle_share_error('x', $post->ID, $x_res->get_error_message(), 'last_x_error');
					self::maybe_notify_admin_failure('x', $post->ID, $x_res->get_error_message());
				} else {
					self::update_options(['last_x_error' => '', 'last_x_error_at' => 0]);
					self::debug_log_event("Immediate X share success for post #{$post->ID}.");
				}
			} catch (Throwable $e) {
				self::handle_share_error('x', $post->ID, $e->getMessage(), 'last_x_error');
				self::maybe_notify_admin_failure('x', $post->ID, $e->getMessage());
			}
		}

		// Facebook traité immédiatement pour ne pas dépendre uniquement du WP-Cron.
		if (!empty($opt['fb_enabled']) && (!$fb_already || !empty($opt['share_on_update']))) {
			try {
				$fb_res = self::share_post_to_facebook($post->ID, false);
				if (is_wp_error($fb_res)) {
					self::handle_share_error('facebook', $post->ID, $fb_res->get_error_message(), 'last_fb_error');
					self::maybe_notify_admin_failure('facebook', $post->ID, $fb_res->get_error_message());
				} else {
					self::update_options(['last_fb_error' => '', 'last_fb_error_at' => 0]);
					self::debug_log_event("Immediate Facebook share success for post #{$post->ID}.");
				}
			} catch (Throwable $e) {
				self::handle_share_error('facebook', $post->ID, $e->getMessage(), 'last_fb_error');
				self::maybe_notify_admin_failure('facebook', $post->ID, $e->getMessage());
			}
		}

		// Instagram traité immédiatement pour ne pas dépendre uniquement du WP-Cron.
		if (!empty($opt['ig_enabled']) && (!$ig_already || !empty($opt['share_on_update']))) {
			try {
				$ig_res = self::share_post_to_instagram($post->ID, false);
				if (is_wp_error($ig_res)) {
					self::handle_share_error('instagram', $post->ID, $ig_res->get_error_message(), 'last_ig_error');
					self::maybe_notify_admin_failure('instagram', $post->ID, $ig_res->get_error_message());
				} else {
					self::update_options(['last_ig_error' => '', 'last_ig_error_at' => 0]);
					self::debug_log_event("Immediate Instagram share success for post #{$post->ID}.");
				}
			} catch (Throwable $e) {
				self::handle_share_error('instagram', $post->ID, $e->getMessage(), 'last_ig_error');
				self::maybe_notify_admin_failure('instagram', $post->ID, $e->getMessage());
			}
		}

		// Threads traité immédiatement pour ne pas dépendre uniquement du WP-Cron.
		if (!empty($opt['threads_enabled']) && (!$threads_already || !empty($opt['share_on_update']))) {
			try {
				$threads_res = self::share_post_to_threads($post->ID, false);
				if (is_wp_error($threads_res)) {
					self::handle_share_error('threads', $post->ID, $threads_res->get_error_message(), 'last_threads_error');
					self::maybe_notify_admin_failure('threads', $post->ID, $threads_res->get_error_message());
				} else {
					self::update_options(['last_threads_error' => '', 'last_threads_error_at' => 0]);
					self::debug_log_event("Immediate Threads share success for post #{$post->ID}.");
				}
			} catch (Throwable $e) {
				self::handle_share_error('threads', $post->ID, $e->getMessage(), 'last_threads_error');
				self::maybe_notify_admin_failure('threads', $post->ID, $e->getMessage());
			}
		}

		// Medium traité immédiatement pour ne pas dépendre uniquement du WP-Cron.
		if (!empty($opt['medium_enabled']) && (!$medium_already || !empty($opt['share_on_update']))) {
			try {
				$medium_res = self::share_post_to_medium($post->ID, false);
				if (is_wp_error($medium_res)) {
					self::handle_share_error('medium', $post->ID, $medium_res->get_error_message(), 'last_medium_error');
					self::maybe_notify_admin_failure('medium', $post->ID, $medium_res->get_error_message());
				} else {
					self::update_options(['last_medium_error' => '', 'last_medium_error_at' => 0]);
					self::debug_log_event("Immediate Medium share success for post #{$post->ID}.");
				}
			} catch (Throwable $e) {
				self::handle_share_error('medium', $post->ID, $e->getMessage(), 'last_medium_error');
				self::maybe_notify_admin_failure('medium', $post->ID, $e->getMessage());
			}
		}

		// On garde le cron comme secours pour les réseaux qui auraient échoué.
		wp_schedule_single_event(time(), 'pkliap_async_share_task', [$post->ID]);
	}

	/**
	 * Exécute le partage de manière asynchrone (appelé par le CRON)
	 */
	public static function do_async_share(int $post_id): void {
		$opt = self::get_options();
		$networks = [
			'linkedin'  => ['enabled' => !empty($opt['enabled']),    'callback' => 'share_post_to_linkedin',  'err_key' => 'last_share_error'],
			'x'         => ['enabled' => !empty($opt['x_enabled']),  'callback' => 'share_post_to_x',         'err_key' => 'last_x_error'],
			'facebook'  => ['enabled' => !empty($opt['fb_enabled']), 'callback' => 'share_post_to_facebook',  'err_key' => 'last_fb_error'],
			'instagram' => ['enabled' => !empty($opt['ig_enabled']), 'callback' => 'share_post_to_instagram', 'err_key' => 'last_ig_error'],
			'threads'   => ['enabled' => !empty($opt['threads_enabled']), 'callback' => 'share_post_to_threads', 'err_key' => 'last_threads_error'],
			'medium'    => ['enabled' => !empty($opt['medium_enabled']), 'callback' => 'share_post_to_medium', 'err_key' => 'last_medium_error'],
		];

		foreach ($networks as $net => $cfg) {
			if (!$cfg['enabled']) continue;

			try {
				$meta_by_net = [
					'linkedin' => self::META_SHARED_AT,
					'x' => self::META_X_SHARED_AT,
					'facebook' => self::META_FB_SHARED_AT,
					'instagram' => self::META_IG_SHARED_AT,
					'threads' => self::META_THREADS_SHARED_AT,
					'medium' => self::META_MEDIUM_SHARED_AT,
				];
				if (!empty($opt['only_once']) && empty($opt['share_on_update']) && !empty($meta_by_net[$net]) && get_post_meta($post_id, $meta_by_net[$net], true)) {
					self::debug_log_event("Auto share $net skipped for post #$post_id: already shared.");
					continue;
				}

				$res = call_user_func([__CLASS__, $cfg['callback']], $post_id, false);
				if (is_wp_error($res)) {
					self::handle_share_error($net, $post_id, $res->get_error_message(), $cfg['err_key']);
					self::maybe_notify_admin_failure($net, $post_id, $res->get_error_message());
				} else {
					self::update_options([$cfg['err_key'] => '', $cfg['err_key'] . '_at' => 0]);
					self::debug_log_event("Auto share $net success for post #$post_id.");
				}
			} catch (Throwable $e) {
				$msg = $e->getMessage();
				self::handle_share_error($net, $post_id, $msg, $cfg['err_key']);
				self::maybe_notify_admin_failure($net, $post_id, $msg);
			}
		}
	}

	public static function do_retry_pending_shares(): void {
		self::process_pending_shares('all', 10);
	}

	public static function cli_retry_pending_shares(array $args, array $assoc_args): void {
		$network = isset($assoc_args['network']) ? sanitize_key((string)$assoc_args['network']) : 'all';
		$limit = isset($assoc_args['limit']) ? max(1, min(100, (int)$assoc_args['limit'])) : 20;
		$result = self::process_pending_shares($network, $limit);
		WP_CLI::success('Processed ' . (int)$result['processed'] . ' share attempt(s); success=' . (int)$result['success'] . '; failed=' . (int)$result['failed'] . '; skipped=' . (int)$result['skipped'] . '.');
	}

	private static function process_pending_shares(string $network = 'all', int $limit = 20): array {
		$opt = self::get_options();
		$network_map = self::share_network_map($opt);
		if ($network !== 'all') {
			$network_map = isset($network_map[$network]) ? [$network => $network_map[$network]] : [];
		}
		if (!$network_map) {
			return ['processed' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0];
		}

		$post_types = array_values(array_filter((array)($opt['post_type_whitelist'] ?? [])));
		if (!$post_types) {
			$post_types = ['post'];
		}

		$q = new WP_Query([
			'post_type' => $post_types,
			'post_status' => 'publish',
			'fields' => 'ids',
			'posts_per_page' => max(1, min(100, $limit)),
			'orderby' => 'date',
			'order' => 'DESC',
			'no_found_rows' => true,
		]);

		$result = ['processed' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0];
		$post_ids = is_array($q->posts) ? array_map('intval', $q->posts) : [];
		foreach ($post_ids as $post_id) {
			foreach ($network_map as $net => $cfg) {
				if (empty($cfg['enabled'])) {
					$result['skipped']++;
					continue;
				}
				if (!empty($opt['only_once']) && empty($opt['share_on_update']) && get_post_meta($post_id, (string)$cfg['meta'], true)) {
					$result['skipped']++;
					continue;
				}
				$result['processed']++;
				try {
					$res = call_user_func([__CLASS__, (string)$cfg['callback']], $post_id, false);
					if (is_wp_error($res)) {
						self::handle_share_error($net, $post_id, $res->get_error_message(), (string)$cfg['err_key']);
						self::maybe_notify_admin_failure($net, $post_id, $res->get_error_message());
						$result['failed']++;
					} else {
						self::update_options([(string)$cfg['err_key'] => '', (string)$cfg['err_key'] . '_at' => 0]);
						self::debug_log_event("Retry share $net success for post #$post_id.");
						$result['success']++;
					}
				} catch (Throwable $e) {
					self::handle_share_error($net, $post_id, $e->getMessage(), (string)$cfg['err_key']);
					self::maybe_notify_admin_failure($net, $post_id, $e->getMessage());
					$result['failed']++;
				}
			}
		}

		return $result;
	}

	private static function share_network_map(array $opt): array {
		return [
			'linkedin' => ['enabled' => !empty($opt['enabled']), 'callback' => 'share_post_to_linkedin', 'err_key' => 'last_share_error', 'meta' => self::META_SHARED_AT],
			'x' => ['enabled' => !empty($opt['x_enabled']), 'callback' => 'share_post_to_x', 'err_key' => 'last_x_error', 'meta' => self::META_X_SHARED_AT],
			'facebook' => ['enabled' => !empty($opt['fb_enabled']), 'callback' => 'share_post_to_facebook', 'err_key' => 'last_fb_error', 'meta' => self::META_FB_SHARED_AT],
			'instagram' => ['enabled' => !empty($opt['ig_enabled']), 'callback' => 'share_post_to_instagram', 'err_key' => 'last_ig_error', 'meta' => self::META_IG_SHARED_AT],
			'threads' => ['enabled' => !empty($opt['threads_enabled']), 'callback' => 'share_post_to_threads', 'err_key' => 'last_threads_error', 'meta' => self::META_THREADS_SHARED_AT],
			'medium' => ['enabled' => !empty($opt['medium_enabled']), 'callback' => 'share_post_to_medium', 'err_key' => 'last_medium_error', 'meta' => self::META_MEDIUM_SHARED_AT],
		];
	}

	private static function handle_share_error(string $net, int $post_id, string $msg, string $err_key): void {
		self::update_options([
			$err_key => $msg,
			$err_key . '_at' => time(),
		]);
		self::debug_log_event("Auto share $net failed for post #$post_id: $msg");
	}

	private static function collect_pending_alerts(array $opt): array {
		$alerts = [];
		$map = [
			'linkedin' => ['label' => 'LinkedIn', 'error_key' => 'last_share_error', 'time_key' => 'last_share_error_at'],
			'x' => ['label' => 'X', 'error_key' => 'last_x_error', 'time_key' => 'last_x_error_at'],
			'facebook' => ['label' => 'Facebook', 'error_key' => 'last_fb_error', 'time_key' => 'last_fb_error_at'],
			'instagram' => ['label' => 'Instagram', 'error_key' => 'last_ig_error', 'time_key' => 'last_ig_error_at'],
			'threads' => ['label' => 'Threads', 'error_key' => 'last_threads_error', 'time_key' => 'last_threads_error_at'],
			'medium' => ['label' => 'Medium', 'error_key' => 'last_medium_error', 'time_key' => 'last_medium_error_at'],
		];

			foreach ($map as $net => $cfg) {
				$msg = trim((string)($opt[$cfg['error_key']] ?? ''));
				if ($msg === '') {
					continue;
				}
				if ($net === 'linkedin' && self::linkedin_account_looks_ready($opt)) {
					continue;
				}
				$alerts[] = $cfg['label'] . ': ' . self::humanize_share_error($net, $msg);
			}

		return $alerts;
	}

	private static function humanize_share_error(string $net, string $msg): string {
		if ($net === 'linkedin' && stripos($msg, 'HTTP 401') !== false) {
			return 'token LinkedIn expiré, reconnecte le compte.';
		}
		if ($net === 'x' && self::is_x_credits_error($msg)) {
			return 'compte X à court de crédits; il faut ajouter des crédits ou changer de forfait.';
		}
		return $msg;
	}

	private static function is_x_credits_error(string $msg): bool {
		return stripos($msg, 'HTTP 402') !== false
			|| stripos($msg, 'CreditsDepleted') !== false
			|| stripos($msg, 'does not have any credits') !== false
			|| stripos($msg, 'out of PPU credits') !== false;
	}

	private static function linkedin_account_looks_ready(array $opt): bool {
		$expires_at = (int)($opt['access_token_expires_at'] ?? 0);
		return !empty($opt['access_token'])
			&& !empty($opt['author_urn'])
			&& ($expires_at <= 0 || time() < $expires_at);
	}

	private static function build_connection_health(array $opt, bool $linkedin_token_ok, bool $linkedin_token_not_expired, bool $has_author_urn, bool $x_connected, bool $fb_connected, bool $ig_connected, bool $threads_connected, bool $medium_connected): array {
		$health = [];

		$linkedin_error = trim((string)($opt['last_share_error'] ?? ''));
		$linkedin_ok = $linkedin_token_ok && $linkedin_token_not_expired && $has_author_urn;
		$health['linkedin'] = [
			'ok' => $linkedin_ok,
			'message' => $linkedin_ok
				? 'Prêt côté compte LinkedIn: token valide + Author URN renseigné.'
				: ($linkedin_error !== '' ? 'Erreur récente: ' . self::humanize_share_error('linkedin', $linkedin_error) : 'Token manquant/expiré ou Author URN manquant.'),
		];

		$x_error = trim((string)($opt['last_x_error'] ?? ''));
		$x_ok = $x_connected && $x_error === '';
		$x_message = 'Compte non connecté (token manquant).';
		if ($x_ok) {
			$x_message = 'Auth OK: token utilisateur OAuth 1.0a présent.';
		} elseif ($x_error !== '') {
			$x_message = self::is_x_credits_error($x_error)
				? 'Auth probablement OK, mais publication bloquée par crédits X insuffisants.'
				: 'Erreur récente: ' . self::humanize_share_error('x', $x_error);
		}
		$health['x'] = [
			'ok' => $x_ok,
			'message' => $x_message,
		];

		$fb_error = trim((string)($opt['last_fb_error'] ?? ''));
		$fb_ok = $fb_connected && $fb_error === '';
		$health['facebook'] = [
			'ok' => $fb_ok,
			'message' => $fb_ok
				? 'Page ID + Access Token renseignés.'
				: ($fb_error !== '' ? 'Erreur récente: ' . $fb_error : 'Page ID ou Access Token manquant.'),
		];

		$ig_error = trim((string)($opt['last_ig_error'] ?? ''));
		$ig_ok = $ig_connected && $ig_error === '';
		$health['instagram'] = [
			'ok' => $ig_ok,
			'message' => $ig_ok
				? 'User ID + Access Token renseignés.'
				: ($ig_error !== '' ? 'Erreur récente: ' . $ig_error : 'User ID ou Access Token manquant.'),
		];

		$threads_error = trim((string)($opt['last_threads_error'] ?? ''));
		$threads_ok = $threads_connected && $threads_error === '';
		$health['threads'] = [
			'ok' => $threads_ok,
			'message' => $threads_ok
				? 'Threads User ID + Access Token renseignes.'
				: ($threads_error !== '' ? 'Erreur recente: ' . $threads_error : 'Threads User ID ou Access Token manquant.'),
		];

		$medium_error = trim((string)($opt['last_medium_error'] ?? ''));
		$medium_ok = $medium_connected && $medium_error === '';
		$health['medium'] = [
			'ok' => $medium_ok,
			'message' => $medium_ok
				? 'Medium User ID + Access Token renseignés.'
				: ($medium_error !== '' ? 'Erreur récente: ' . $medium_error : 'Medium User ID ou Access Token manquant.'),
		];

		return $health;
	}

	public static function admin_notices(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && strpos((string)$screen->id, 'pk-socialsharing') !== false) {
			return;
		}

		$alerts = self::collect_pending_alerts(self::get_options());
		if (!$alerts) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>WP PK SocialSharing :</strong> ' . esc_html(implode(' ', $alerts)) . '</p></div>';
	}

	public static function admin_bar_menu(WP_Admin_Bar $admin_bar): void {
		if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
			return;
		}

		$alerts = self::collect_pending_alerts(self::get_options());
		if (!$alerts) {
			return;
		}

		$node_id = 'pkliap-alerts';
		$count = count($alerts);
		$alert_text = $count . ' alerte' . ($count > 1 ? 's' : '');
		$label = '<span class="pkliap-adminbar-label">Partages</span><span class="pkliap-badge" aria-hidden="true">' . esc_html((string)$count) . '</span>';
		$admin_bar->add_node([
			'id' => $node_id,
			'title' => $label,
			'href' => self::settings_url(),
			'meta' => [
				'title' => $alert_text . ' SocialSharing: ' . implode(' | ', $alerts),
				'aria-label' => 'SocialSharing: ' . $alert_text,
			],
		]);

		add_action('admin_head', [__CLASS__, 'admin_bar_badge_css']);
		add_action('wp_head', [__CLASS__, 'admin_bar_badge_css']);
	}

	public static function admin_bar_badge_css(): void {
		echo '<style>
			#wp-admin-bar-pkliap-alerts > a.ab-item{
				padding:0 7px !important;
				display:inline-flex !important;
				align-items:center !important;
				justify-content:center !important;
				gap:4px !important;
				height:32px !important;
				line-height:32px !important;
				overflow:hidden !important;
				color:#fff !important;
			}
			#wp-admin-bar-pkliap-alerts > a.ab-item .pkliap-adminbar-label{
				color:#fff !important;
				font-size:12px;
				font-weight:600;
				line-height:1;
				flex:0 0 auto;
			}
			#wp-admin-bar-pkliap-alerts > a.ab-item .pkliap-badge{
				display:inline-flex;
				align-items:center;
				justify-content:center;
				min-width:15px;
				height:15px;
				margin:0 0 0 1px;
				padding:0 4px;
				border-radius:999px;
				background:#ef4444;
				color:#fff;
				font-size:10px;
				font-weight:700;
				line-height:1;
				vertical-align:middle;
				flex:0 0 auto;
			}
		</style>';
	}

	private static function maybe_notify_admin_failure(string $net, int $post_id, string $msg): void {
		$opt = self::get_options();

		$post = get_post($post_id);
		$post_title = $post ? wp_specialchars_decode((string)$post->post_title, ENT_QUOTES) : "(post #$post_id)";
		$post_link = $post ? get_permalink($post_id) : '';

		$hash = md5($net . '|' . $post_id);
		$recent = is_array($opt['last_admin_alert_hash'] ?? null) ? $opt['last_admin_alert_hash'] : [];
		if (!is_array($recent)) {
			$recent = $recent ? [$recent] : [];
		}
		if (in_array($hash, $recent, true)) {
			return;
		}

		$to = get_option('admin_email');
		if (!is_string($to) || $to === '') {
			return;
		}

		$site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		$subject = sprintf('[%s] Partage %s echoue : %s', $site_name, strtoupper($net), $post_title);

		$guidance = '';
		if ($net === 'linkedin' && stripos($msg, '401') !== false) {
			$guidance = "Action: reconnecte ton compte LinkedIn dans les reglages du plugin.";
		} elseif ($net === 'x' && stripos($msg, '402') !== false) {
			$guidance = "Action: les credits X sont epuises. Recharge le compte ou utilise 'Publier via navigateur'.";
		} elseif (($net === 'facebook' || $net === 'instagram') && stripos($msg, '403') !== false) {
			$guidance = "Action: permissions manquantes. Regenere le Page Access Token dans le Graph Explorer avec pages_show_list, pages_read_engagement et pages_manage_posts.";
		} elseif (($net === 'facebook' || $net === 'instagram') && stripos($msg, 'expired') !== false) {
			$guidance = "Action: le token Meta a expire. Regenere un nouveau Page Access Token dans le Graph Explorer (type Page).";
		} elseif ($net === 'threads' && stripos($msg, 'expired') !== false) {
			$guidance = "Action: le token Threads a expire. Regenere-le dans le Graph Explorer.";
		}

		$body = "Une erreur de partage automatique a ete detectee.\n\n";
		$body .= "Reseau : " . strtoupper($net) . "\n";
		$body .= "Article : " . $post_title . "\n";
		if ($post_link) {
			$body .= "Lien : " . $post_link . "\n";
		}
		$body .= "Heure : " . wp_date('Y-m-d H:i:s') . "\n\n";
		$body .= "Erreur : " . $msg . "\n";
		if ($guidance) {
			$body .= "\n" . $guidance . "\n";
		}
		$body .= "\nReglages : " . admin_url('admin.php?page=pk-socialsharing') . "\n";

		if (wp_mail($to, $subject, $body)) {
			$recent[] = $hash;
			if (count($recent) > 50) {
				$recent = array_slice($recent, -50);
			}
			self::update_options([
				'last_admin_alert_at' => time(),
				'last_admin_alert_hash' => $recent,
			]);
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

		$refresh_res = self::maybe_refresh_linkedin_token($opt);
		if (is_wp_error($refresh_res)) {
			return $refresh_res;
		}
		$opt = self::get_options();

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
		if (is_wp_error($res) && strpos($res->get_error_message(), 'HTTP 401') !== false) {
			$refresh_res = self::maybe_refresh_linkedin_token(self::get_options());
			if (!is_wp_error($refresh_res)) {
				$opt = self::get_options();
				$res = self::linkedin_api_post('/v2/ugcPosts', $payload, $opt['access_token'], [
					'X-Restli-Protocol-Version' => '2.0.0',
				]);
			}
		}

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

	/** @return array|WP_Error */
	private static function share_post_to_x(int $post_id, bool $force) {
		$post = get_post($post_id);
		if (!$post || $post->post_status !== 'publish') {
			return new WP_Error('pkliap_invalid_post', 'Article non publié.');
		}

		$opt = self::get_options();
		if (empty($opt['x_enabled']) && !$force) {
			return ['skipped' => true];
		}
		if (empty($opt['x_api_key']) || empty($opt['x_api_secret'])) {
			return new WP_Error('pkliap_x_no_app', 'X: API Key / API Key Secret manquants.');
		}
		if (empty($opt['x_access_token']) || empty($opt['x_access_token_secret'])) {
			return new WP_Error('pkliap_x_no_token', 'X: compte non connecté.');
		}

		if (!$force && !empty($opt['only_once'])) {
			$already = get_post_meta($post_id, self::META_X_SHARED_AT, true);
			if ($already && empty($opt['share_on_update'])) {
				return ['skipped' => true];
			}
		}

		$link = self::get_post_link($post_id, $opt);
		$text = self::build_x_text($post_id, $opt, $link);
		$publish_res = self::x_create_tweet($text, $opt);
		if (is_wp_error($publish_res)) {
			return $publish_res;
		}

		$x_post_id = (string)($publish_res['data']['id'] ?? '');
		update_post_meta($post_id, self::META_X_SHARED_AT, time());
		if ($x_post_id !== '') {
			update_post_meta($post_id, self::META_X_POST_ID, $x_post_id);
		}

		return [
			'post_id' => $x_post_id,
		];
	}

	/** @return array|WP_Error */
	private static function share_post_to_facebook(int $post_id, bool $force) {
		$post = get_post($post_id);
		if (!$post || $post->post_status !== 'publish') {
			return new WP_Error('pkliap_invalid_post', 'Article non publié.');
		}

		$opt = self::get_options();
		if (empty($opt['fb_enabled']) && !$force) {
			return ['skipped' => true];
		}
		if (empty($opt['fb_page_id']) || empty($opt['fb_access_token'])) {
			return new WP_Error('pkliap_fb_config', 'Facebook: Page ID ou Access Token manquant.');
		}

		if (!$force && !empty($opt['only_once'])) {
			$already = get_post_meta($post_id, self::META_FB_SHARED_AT, true);
			if ($already && empty($opt['share_on_update'])) {
				return ['skipped' => true];
			}
		}

		$link = self::get_post_link($post_id, $opt);
		$text = self::build_facebook_text($post_id, $opt, $link);

		$thumb_id = get_post_thumbnail_id($post_id);
		$image_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'full') : '';
		if ($image_url) {
			$res = self::meta_graph_post('/' . rawurlencode((string)$opt['fb_page_id']) . '/photos', [
				'url' => $image_url,
				'caption' => $text,
			], (string)$opt['fb_access_token']);
		} else {
			$payload = ['message' => $text];
			if (self::network_opt_bool($opt, 'fb_include_url', 'include_url')) {
				$payload['link'] = $link;
			}

			$res = self::meta_graph_post('/' . rawurlencode((string)$opt['fb_page_id']) . '/feed', $payload, (string)$opt['fb_access_token']);
		}

		if (is_wp_error($res)) {
			return $res;
		}

		$fb_post_id = (string)($res['body']['post_id'] ?? $res['body']['id'] ?? '');
		if ($fb_post_id === '' && $image_url) {
			$fallback = self::meta_graph_post('/' . rawurlencode((string)$opt['fb_page_id']) . '/feed', [
				'message' => $text,
				'link' => $link,
			], (string)$opt['fb_access_token']);
			if (is_wp_error($fallback)) {
				return $fallback;
			}
			$fb_post_id = (string)($fallback['body']['id'] ?? '');
		}

		update_post_meta($post_id, self::META_FB_SHARED_AT, time());
		if ($fb_post_id !== '') {
			update_post_meta($post_id, self::META_FB_POST_ID, $fb_post_id);
		}

		return [
			'post_id' => $fb_post_id,
		];
	}

	/** @return array|WP_Error */
	private static function share_post_to_instagram(int $post_id, bool $force) {
		$post = get_post($post_id);
		if (!$post || $post->post_status !== 'publish') {
			return new WP_Error('pkliap_invalid_post', 'Article non publié.');
		}

		$opt = self::get_options();
		if (empty($opt['ig_enabled']) && !$force) {
			return ['skipped' => true];
		}
		if (empty($opt['ig_user_id']) || empty($opt['ig_access_token'])) {
			return new WP_Error('pkliap_ig_config', 'Instagram: IG User ID ou Access Token manquant.');
		}

		if (!$force && !empty($opt['only_once'])) {
			$already = get_post_meta($post_id, self::META_IG_SHARED_AT, true);
			if ($already && empty($opt['share_on_update'])) {
				return ['skipped' => true];
			}
		}

		$thumb_id = get_post_thumbnail_id($post_id);
		if (!$thumb_id) {
			return new WP_Error('pkliap_ig_image_required', 'Instagram: image mise en avant obligatoire.');
		}

		$image_url = self::get_instagram_compatible_image_url($thumb_id);
		if (is_wp_error($image_url)) {
			return $image_url;
		}
		if (!$image_url) {
			return new WP_Error('pkliap_ig_image_required', 'Instagram: URL image introuvable.');
		}

		$link = self::get_post_link($post_id, $opt);
		$caption = self::build_instagram_caption($post_id, $opt, $link);

		$create = self::meta_graph_post('/' . rawurlencode((string)$opt['ig_user_id']) . '/media', [
			'image_url' => $image_url,
			'caption' => $caption,
		], (string)$opt['ig_access_token']);
		if (is_wp_error($create)) {
			return $create;
		}
		$creation_id = (string)($create['body']['id'] ?? '');
		if ($creation_id === '') {
			return new WP_Error('pkliap_ig_media_create', 'Instagram: creation_id manquant.');
		}

		$ready = self::wait_for_instagram_container($creation_id, (string)$opt['ig_access_token']);
		if (is_wp_error($ready)) {
			return $ready;
		}

		$publish = self::meta_graph_post('/' . rawurlencode((string)$opt['ig_user_id']) . '/media_publish', [
			'creation_id' => $creation_id,
		], (string)$opt['ig_access_token']);
		if (is_wp_error($publish) && stripos($publish->get_error_message(), 'Media ID is not available') !== false) {
			sleep(3);
			$publish = self::meta_graph_post('/' . rawurlencode((string)$opt['ig_user_id']) . '/media_publish', [
				'creation_id' => $creation_id,
			], (string)$opt['ig_access_token']);
		}
		if (is_wp_error($publish)) {
			return $publish;
		}

		$ig_media_id = (string)($publish['body']['id'] ?? '');
		if ($ig_media_id === '') {
			return new WP_Error('pkliap_ig_publish', 'Instagram: media id manquant.');
		}

		$permalink = '';
		$get = self::meta_graph_get('/' . rawurlencode($ig_media_id), [
			'fields' => 'permalink',
		], (string)$opt['ig_access_token']);
		if (!is_wp_error($get)) {
			$permalink = (string)($get['body']['permalink'] ?? '');
		}

		update_post_meta($post_id, self::META_IG_SHARED_AT, time());
		update_post_meta($post_id, self::META_IG_MEDIA_ID, $ig_media_id);
		if ($permalink !== '') {
			update_post_meta($post_id, self::META_IG_PERMALINK, $permalink);
		}

		return [
			'media_id' => $ig_media_id,
			'permalink' => $permalink,
		];
	}
	/** @return true|WP_Error */
	private static function wait_for_instagram_container(string $creation_id, string $access_token) {
		$last_status = '';
		for ($attempt = 0; $attempt < 6; $attempt++) {
			if ($attempt > 0) {
				sleep(2);
			}

			$status = self::meta_graph_get('/' . rawurlencode($creation_id), [
				'fields' => 'status_code,status',
			], $access_token);
			if (is_wp_error($status)) {
				return $status;
			}

			$body = is_array($status['body'] ?? null) ? $status['body'] : [];
			$status_code = strtoupper((string)($body['status_code'] ?? ''));
			$last_status = $status_code !== '' ? $status_code : (string)($body['status'] ?? '');
			if ($status_code === 'FINISHED' || $status_code === 'PUBLISHED') {
				return true;
			}
			if ($status_code === 'ERROR' || $status_code === 'EXPIRED') {
				return new WP_Error('pkliap_ig_container_not_ready', 'Instagram: container media en erreur (' . $last_status . ').');
			}
		}

		return new WP_Error('pkliap_ig_container_not_ready', 'Instagram: media pas encore prêt côté Meta après attente. Dernier statut: ' . ($last_status ?: 'inconnu') . '.');
	}

	/** @return string|WP_Error */
	private static function get_instagram_compatible_image_url(int $attachment_id) {
		$original_url = wp_get_attachment_image_url($attachment_id, 'full');
		$file = get_attached_file($attachment_id);
		if (!$file || !is_readable($file)) {
			return $original_url ?: new WP_Error('pkliap_ig_image_required', 'Instagram: image source introuvable.');
		}

		$size = @getimagesize($file);
		if (!is_array($size) || empty($size[0]) || empty($size[1])) {
			return $original_url ?: new WP_Error('pkliap_ig_image_required', 'Instagram: dimensions image introuvables.');
		}

		$width = (int)$size[0];
		$height = (int)$size[1];
		$ratio = $width / max(1, $height);
		$min_ratio = 0.8;  // 4:5.
		$max_ratio = 1.91; // 1.91:1.

		if ($ratio >= $min_ratio && $ratio <= $max_ratio && $width <= 1440) {
			return $original_url ?: new WP_Error('pkliap_ig_image_required', 'Instagram: image publique introuvable.');
		}

		$src_x = 0;
		$src_y = 0;
		$src_w = $width;
		$src_h = $height;
		if ($ratio < $min_ratio) {
			$src_h = (int)floor($width / $min_ratio);
			$src_y = max(0, (int)floor(($height - $src_h) / 2));
		} elseif ($ratio > $max_ratio) {
			$src_w = (int)floor($height * $max_ratio);
			$src_x = max(0, (int)floor(($width - $src_w) / 2));
		}

		$cropped_ratio = $src_w / max(1, $src_h);
		$dest_w = min(1440, $src_w);
		$dest_h = max(1, (int)round($dest_w / $cropped_ratio));

		$uploads = wp_upload_dir();
		if (!empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) {
			return new WP_Error('pkliap_ig_image_resize', 'Instagram: dossier uploads indisponible.');
		}

		$out_dir = trailingslashit((string)$uploads['basedir']) . 'pk-socialsharing-instagram';
		if (!wp_mkdir_p($out_dir)) {
			return new WP_Error('pkliap_ig_image_resize', 'Instagram: impossible de créer le dossier image compatible.');
		}

		$stamp = is_readable($file) ? (string)filemtime($file) : '0';
		$hash = substr(md5($file . '|' . $stamp . '|' . $src_x . '|' . $src_y . '|' . $src_w . '|' . $src_h . '|' . $dest_w . '|' . $dest_h), 0, 12);
		$out_file = trailingslashit($out_dir) . 'ig-' . $attachment_id . '-' . $hash . '.jpg';
		$out_url = trailingslashit((string)$uploads['baseurl']) . 'pk-socialsharing-instagram/' . basename($out_file);
		if (is_readable($out_file)) {
			return $out_url;
		}

		if (!function_exists('wp_get_image_editor')) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$editor = wp_get_image_editor($file);
		if (is_wp_error($editor)) {
			return $editor;
		}
		if (method_exists($editor, 'set_quality')) {
			$editor->set_quality(90);
		}

		$crop = $editor->crop($src_x, $src_y, $src_w, $src_h, $dest_w, $dest_h, false);
		if (is_wp_error($crop)) {
			return $crop;
		}
		$saved = $editor->save($out_file, 'image/jpeg');
		if (is_wp_error($saved)) {
			return $saved;
		}

		return $out_url;
	}

	/** @return array|WP_Error */
	private static function share_post_to_threads(int $post_id, bool $force) {
		$post = get_post($post_id);
		if (!$post || $post->post_status !== 'publish') {
			return new WP_Error('pkliap_invalid_post', 'Article non publie.');
		}

		$opt = self::get_options();
		if (empty($opt['threads_enabled']) && !$force) {
			return ['skipped' => true];
		}
		if (empty($opt['threads_user_id']) || empty($opt['threads_access_token'])) {
			return new WP_Error('pkliap_threads_config', 'Threads: User ID ou Access Token manquant.');
		}

		if (!$force && !empty($opt['only_once'])) {
			$already = get_post_meta($post_id, self::META_THREADS_SHARED_AT, true);
			if ($already && empty($opt['share_on_update'])) {
				return ['skipped' => true];
			}
		}

		$link = self::get_post_link($post_id, $opt);
		$text = self::build_threads_text($post_id, $opt, $link);
		$create = self::threads_api_post('/' . rawurlencode((string)$opt['threads_user_id']) . '/threads', [
			'media_type' => 'TEXT',
			'text' => $text,
		], (string)$opt['threads_access_token']);
		if (is_wp_error($create)) {
			return $create;
		}

		$creation_id = (string)($create['body']['id'] ?? '');
		if ($creation_id === '') {
			return new WP_Error('pkliap_threads_create', 'Threads: creation_id manquant.');
		}

		$publish = self::threads_api_post('/' . rawurlencode((string)$opt['threads_user_id']) . '/threads_publish', [
			'creation_id' => $creation_id,
		], (string)$opt['threads_access_token']);
		if (is_wp_error($publish)) {
			return $publish;
		}

		$threads_post_id = (string)($publish['body']['id'] ?? '');
		if ($threads_post_id === '') {
			return new WP_Error('pkliap_threads_publish', 'Threads: post id manquant.');
		}

		$permalink = '';
		$get = self::threads_api_get('/' . rawurlencode($threads_post_id), [
			'fields' => 'permalink',
		], (string)$opt['threads_access_token']);
		if (!is_wp_error($get)) {
			$permalink = (string)($get['body']['permalink'] ?? '');
		}

		update_post_meta($post_id, self::META_THREADS_SHARED_AT, time());
		update_post_meta($post_id, self::META_THREADS_POST_ID, $threads_post_id);
		if ($permalink !== '') {
			update_post_meta($post_id, self::META_THREADS_PERMALINK, $permalink);
		}

		return [
			'post_id' => $threads_post_id,
			'permalink' => $permalink,
		];
	}

	/** @return array|WP_Error */
	private static function share_post_to_medium(int $post_id, bool $force) {
		$post = get_post($post_id);
		if (!$post || $post->post_status !== 'publish') {
			return new WP_Error('pkliap_invalid_post', 'Article non publié.');
		}

		$opt = self::get_options();
		if (empty($opt['medium_enabled']) && !$force) {
			return ['skipped' => true];
		}
		if (empty($opt['medium_access_token'])) {
			return new WP_Error('pkliap_medium_token', 'Medium: Access Token manquant.');
		}

		if (!$force && !empty($opt['only_once'])) {
			$already = get_post_meta($post_id, self::META_MEDIUM_SHARED_AT, true);
			if ($already && empty($opt['share_on_update'])) {
				return ['skipped' => true];
			}
		}

		$user_id = trim((string)($opt['medium_user_id'] ?? ''));
		if ($user_id === '') {
			$me = self::medium_get_me((string)$opt['medium_access_token']);
			if (is_wp_error($me)) {
				return $me;
			}
			$user_id = (string)($me['id'] ?? '');
			if ($user_id !== '') {
				self::update_options(['medium_user_id' => $user_id]);
			}
		}
		if ($user_id === '') {
			return new WP_Error('pkliap_medium_user', 'Medium: impossible de déterminer le User ID.');
		}

		$link = self::get_post_link($post_id, $opt);
		$payload = [
			'title' => wp_strip_all_tags(get_the_title($post_id)),
			'contentFormat' => 'html',
			'content' => self::build_medium_html_content($post_id),
			'canonicalUrl' => $link,
			'tags' => self::medium_post_tags($post_id),
			'publishStatus' => in_array((string)$opt['medium_publish_status'], ['draft', 'public', 'unlisted'], true) ? (string)$opt['medium_publish_status'] : 'public',
		];

		$res = self::medium_api_post('/v1/users/' . rawurlencode($user_id) . '/posts', $payload, (string)$opt['medium_access_token']);
		if (is_wp_error($res)) {
			return $res;
		}

		$body = is_array($res['body'] ?? null) ? $res['body'] : [];
		$data = is_array($body['data'] ?? null) ? $body['data'] : [];
		$medium_post_id = (string)($data['id'] ?? '');
		$medium_post_url = (string)($data['url'] ?? '');

		update_post_meta($post_id, self::META_MEDIUM_SHARED_AT, time());
		if ($medium_post_id !== '') {
			update_post_meta($post_id, self::META_MEDIUM_POST_ID, $medium_post_id);
		}
		if ($medium_post_url !== '') {
			update_post_meta($post_id, self::META_MEDIUM_POST_URL, $medium_post_url);
		}

		return [
			'post_id' => $medium_post_id,
			'url' => $medium_post_url,
		];
	}

	private static function build_medium_html_content(int $post_id): string {
		$post = get_post($post_id);
		if (!$post) {
			return '';
		}
		$content = apply_filters('the_content', $post->post_content);
		$content = is_string($content) ? $content : '';
		$content = trim($content);
		if ($content === '') {
			$excerpt = self::safe_excerpt($post_id, 500);
			$content = $excerpt !== '' ? '<p>' . esc_html($excerpt) . '</p>' : '<p>' . esc_html(wp_strip_all_tags(get_the_title($post_id))) . '</p>';
		}
		return wp_kses_post($content);
	}

	private static function medium_post_tags(int $post_id): array {
		$terms = get_the_terms($post_id, 'post_tag');
		if (!is_array($terms)) {
			return [];
		}

		$tags = [];
		foreach ($terms as $term) {
			if (!isset($term->name)) {
				continue;
			}
			$tag = self::mb_truncate(trim(wp_strip_all_tags((string)$term->name)), 25);
			if ($tag !== '' && !in_array($tag, $tags, true)) {
				$tags[] = $tag;
			}
			if (count($tags) >= 5) {
				break;
			}
		}
		return $tags;
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

	private static function network_tab_status(string $net, bool $enabled, bool $connected, string $error): array {
		return self::network_mode_status($net, $enabled, $connected, $error);
	}

	private static function network_connection_status(string $net, bool $connected, string $error): array {
		$error = trim($error);
		if ($connected && ($error === '' || ($net === 'x' && self::is_x_credits_error($error)))) {
			return ['tone' => 'ok', 'label' => 'Connecté'];
		}
		if ($connected) {
			return ['tone' => 'bad', 'label' => 'Erreur'];
		}
		return ['tone' => 'bad', 'label' => 'Déconnecté'];
	}

	private static function network_mode_status(string $net, bool $enabled, bool $connected, string $error): array {
		$error = trim($error);
		if (!$connected) {
			return ['tone' => 'bad', 'label' => 'Off'];
		}
		if ($net === 'x' && self::is_x_credits_error($error)) {
			return ['tone' => 'warn', 'label' => 'Manuel'];
		}
		if ($error !== '') {
			return ['tone' => 'bad', 'label' => 'Bloqué'];
		}
		if ($enabled) {
			return ['tone' => 'ok', 'label' => 'Auto'];
		}
		return ['tone' => 'warn', 'label' => 'Manuel'];
	}

	private static function network_icon_color(string $key): string {
		$colors = [
			'linkedin' => '#0a66c2',
			'x' => '#111111',
			'facebook' => '#1877f2',
			'instagram' => '#e4405f',
			'threads' => '#111111',
			'medium' => '#00ab6c',
		];
		return $colors[$key] ?? '#64748b';
	}

	private static function build_post_share_status_items(int $post_id): array {
		$linkedin_urn = (string)get_post_meta($post_id, self::META_SHARE_URN, true);
		$x_post_id = (string)get_post_meta($post_id, self::META_X_POST_ID, true);
		$fb_post_id = (string)get_post_meta($post_id, self::META_FB_POST_ID, true);
		$ig_media_id = (string)get_post_meta($post_id, self::META_IG_MEDIA_ID, true);
		$threads_post_id = (string)get_post_meta($post_id, self::META_THREADS_POST_ID, true);
		$medium_post_id = (string)get_post_meta($post_id, self::META_MEDIUM_POST_ID, true);

		return [
			[
				'key' => 'linkedin',
				'label' => 'LinkedIn',
				'color' => '#0a66c2',
				'shared_at' => (int)get_post_meta($post_id, self::META_SHARED_AT, true),
				'id' => $linkedin_urn,
				'url' => self::linkedin_share_url_from_urn($linkedin_urn),
			],
			[
				'key' => 'x',
				'label' => 'X',
				'color' => '#111111',
				'shared_at' => (int)get_post_meta($post_id, self::META_X_SHARED_AT, true),
				'id' => $x_post_id,
				'url' => $x_post_id !== '' ? ('https://x.com/i/web/status/' . rawurlencode($x_post_id)) : '',
			],
			[
				'key' => 'facebook',
				'label' => 'Facebook',
				'color' => '#1877f2',
				'shared_at' => (int)get_post_meta($post_id, self::META_FB_SHARED_AT, true),
				'id' => $fb_post_id,
				'url' => $fb_post_id !== '' ? ('https://www.facebook.com/' . rawurlencode($fb_post_id)) : '',
			],
			[
				'key' => 'instagram',
				'label' => 'Instagram',
				'color' => '#e4405f',
				'shared_at' => (int)get_post_meta($post_id, self::META_IG_SHARED_AT, true),
				'id' => $ig_media_id,
				'url' => (string)get_post_meta($post_id, self::META_IG_PERMALINK, true),
			],
			[
				'key' => 'threads',
				'label' => 'Threads',
				'color' => '#111111',
				'shared_at' => (int)get_post_meta($post_id, self::META_THREADS_SHARED_AT, true),
				'id' => $threads_post_id,
				'url' => (string)get_post_meta($post_id, self::META_THREADS_PERMALINK, true),
			],
			[
				'key' => 'medium',
				'label' => 'Medium',
				'color' => '#00ab6c',
				'shared_at' => (int)get_post_meta($post_id, self::META_MEDIUM_SHARED_AT, true),
				'id' => $medium_post_id,
				'url' => (string)get_post_meta($post_id, self::META_MEDIUM_POST_URL, true),
			],
		];
	}

	private static function social_icon_svg(string $key): string {
		$icons = [
			'linkedin' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.98 3.5C4.98 4.88 3.86 6 2.5 6S0 4.88 0 3.5 1.12 1 2.5 1s2.48 1.12 2.48 2.5zM.5 8h4V23h-4V8zm7.5 0h3.8v2.05h.05c.53-1 1.83-2.05 3.77-2.05 4.03 0 4.78 2.65 4.78 6.1V23h-4v-7.9c0-1.88-.03-4.3-2.62-4.3-2.63 0-3.03 2.05-3.03 4.17V23h-4V8z"/></svg>',
			'x' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14.56 10.16 23.55 0h-2.13l-7.8 8.82L7.39 0H.2l9.43 13.35L.2 24h2.13l8.24-9.31L17.15 24h7.19l-9.78-13.84zm-2.92 3.3-.95-1.33L3.08 1.56h3.29l6.13 8.52.95 1.33 7.98 11.08h-3.29l-6.5-9.03z"/></svg>',
			'facebook' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.96h-1.51c-1.49 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/></svg>',
			'instagram' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.71 3.71 0 0 1-1.38-.9 3.71 3.71 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41 1.27-.06 1.65-.07 4.85-.07zm0-2.16C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.39A5.9 5.9 0 0 0 .63 4.14C.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.39 2.13.67.67 1.34 1.08 2.13 1.39.76.3 1.64.5 2.91.56 1.28.06 1.69.07 4.95.07s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.31 1.46-.72 2.13-1.39.67-.67 1.08-1.34 1.39-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91-.31-.79-.72-1.46-1.39-2.13A5.9 5.9 0 0 0 19.86.63c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32zm0 10.16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm7.85-10.4a1.44 1.44 0 1 1-2.88 0 1.44 1.44 0 0 1 2.88 0z"/></svg>',
			'threads' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17.3 11.14c-.1-.05-.21-.1-.32-.15-.19-3.49-2.1-5.49-5.31-5.51h-.04c-1.92 0-3.52.82-4.5 2.31l1.77 1.21c.73-1.11 1.87-1.34 2.73-1.34h.03c1.06.01 1.86.32 2.38.91.38.44.64 1.04.77 1.79-.95-.16-1.98-.21-3.07-.15-3.07.18-5.04 1.97-4.91 4.46.06 1.26.69 2.34 1.76 3.04.9.59 2.06.88 3.27.81 1.6-.09 2.86-.7 3.75-1.81.68-.84 1.11-1.94 1.3-3.34.77.47 1.34 1.09 1.66 1.83.55 1.25.58 3.3-1.11 4.98-1.48 1.47-3.26 2.11-5.94 2.13-2.98-.02-5.23-.98-6.7-2.85-1.38-1.75-2.1-4.29-2.13-7.55.03-3.26.75-5.8 2.13-7.55 1.47-1.87 3.72-2.83 6.7-2.85 3 .02 5.29.99 6.8 2.89.74.93 1.3 2.1 1.67 3.48l2.08-.56c-.45-1.68-1.15-3.12-2.1-4.31C18.02 1.04 15.17.02 11.5 0h-.01C7.83.02 5 1.04 3.1 3.04 1.41 5.18.55 8.14.52 11.88v.01c.03 3.74.89 6.7 2.58 8.84 1.9 2 4.73 3.02 8.39 3.04h.01c3.24-.02 5.53-.87 7.41-2.73 2.47-2.45 2.4-5.52 1.59-7.37-.58-1.32-1.68-2.41-3.2-3.17zm-5.56 5.19c-1.34.08-2.74-.53-2.81-1.78-.05-.93.66-1.97 2.93-2.1.26-.02.52-.02.77-.02.84 0 1.63.08 2.34.23-.26 3.25-1.79 3.59-3.23 3.67z"/></svg>',
			'medium' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M13.54 12c0 3.67-3.03 6.65-6.77 6.65S0 15.67 0 12s3.03-6.65 6.77-6.65 6.77 2.98 6.77 6.65zm7.43 0c0 3.45-1.52 6.25-3.39 6.25s-3.39-2.8-3.39-6.25 1.52-6.25 3.39-6.25 3.39 2.8 3.39 6.25zM24 12c0 3.09-.53 5.6-1.19 5.6s-1.19-2.51-1.19-5.6.53-5.6 1.19-5.6S24 8.91 24 12z"/></svg>',
		];
		return $icons[$key] ?? '';
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
		return self::build_network_text($post_id, $opt, $link, '', 620, 150);
	}

	private static function build_x_text(int $post_id, array $opt, string $link): string {
		return self::build_network_text($post_id, $opt, $link, 'x_', 280);
	}

	private static function build_x_intent_url(int $post_id, array $opt): string {
		$link = self::get_post_link($post_id, $opt);
		$text = self::build_x_text($post_id, $opt, $link);
		return 'https://x.com/intent/tweet?text=' . rawurlencode($text);
	}

	private static function build_facebook_text(int $post_id, array $opt, string $link): string {
		return self::build_network_text($post_id, $opt, $link, 'fb_', 5000);
	}

	private static function build_instagram_caption(int $post_id, array $opt, string $link): string {
		return self::build_network_text($post_id, $opt, $link, '', 2200);
	}

	private static function build_threads_text(int $post_id, array $opt, string $link): string {
		return self::build_network_text($post_id, $opt, $link, '', 500);
	}

	private static function build_network_text(int $post_id, array $opt, string $link, string $prefix_key, int $limit, int $excerpt_limit = 260): string {
		$title = wp_strip_all_tags(get_the_title($post_id));
		$excerpt = self::safe_excerpt($post_id, $excerpt_limit);
		$template = self::network_opt_string($opt, $prefix_key . 'text_template', 'text_template');

		if (trim($template) !== '') {
			$normalized_template = str_replace(["\r\n", "\r"], "\n", $template);
			$normalized_template = str_replace(['/n/n', '/n'], ["\n\n", "\n"], $normalized_template);
			$normalized_template = str_replace(['{br2}', '{br}'], ["\n\n", "\n"], $normalized_template);
			$tokens = [
				'{prefix}' => self::network_opt_string($opt, $prefix_key . 'prefix', 'prefix'),
				'{title}' => self::network_opt_bool($opt, $prefix_key . 'include_title', 'include_title') ? $title : '',
				'{excerpt}' => self::network_opt_bool($opt, $prefix_key . 'include_excerpt', 'include_excerpt') ? $excerpt : '',
				'{url}' => self::network_opt_bool($opt, $prefix_key . 'include_url', 'include_url') ? $link : '',
				'{suffix}' => self::network_opt_string($opt, $prefix_key . 'suffix', 'suffix'),
			];
			$text = strtr($normalized_template, $tokens);
			$text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
			$text = self::normalize_link_paragraph(trim($text), $link);
			return self::mb_truncate_preserving_link($text, $limit, $link);
		}

		$parts = [];
		$effective_prefix = self::network_opt_string($opt, $prefix_key . 'prefix', 'prefix');
		if ($effective_prefix !== '') {
			$parts[] = $effective_prefix;
		}

		$ordered = self::network_opt_order($opt, $prefix_key . 'content_order', 'content_order');
		$order = array_filter(array_map('trim', explode(',', $ordered)), static fn($v) => $v !== '');
		foreach ($order as $token) {
			if ($token === 'title' && self::network_opt_bool($opt, $prefix_key . 'include_title', 'include_title') && $title !== '') {
				$parts[] = $title;
			}
			if ($token === 'excerpt' && self::network_opt_bool($opt, $prefix_key . 'include_excerpt', 'include_excerpt') && $excerpt !== '') {
				$parts[] = $excerpt;
			}
			if ($token === 'url' && self::network_opt_bool($opt, $prefix_key . 'include_url', 'include_url') && $link !== '') {
				$parts[] = $link;
			}
		}

		$effective_suffix = self::network_opt_string($opt, $prefix_key . 'suffix', 'suffix');
		if ($effective_suffix !== '') {
			$parts[] = $effective_suffix;
		}

		$text = trim(implode("\n\n", array_filter($parts, static fn($p) => (string)$p !== '')));
		$text = self::normalize_link_paragraph($text, $link);
		return self::mb_truncate_preserving_link($text, $limit, $link);
	}

	private static function normalize_link_paragraph(string $text, string $link): string {
		if ($text === '' || $link === '' || strpos($text, $link) === false) {
			return $text;
		}

		$pattern = '/[ \t]*(?:\R[ \t]*)*' . preg_quote($link, '/') . '/u';
		$text = preg_replace($pattern, "\n\n" . $link, $text, 1) ?? $text;
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
		return trim($text);
	}

	private static function mb_truncate_preserving_link(string $text, int $limit, string $link): string {
		if ($limit <= 0 || self::mb_length($text) <= $limit) {
			return self::mb_truncate($text, $limit);
		}
		if ($link === '' || strpos($text, $link) === false) {
			return self::mb_truncate($text, $limit);
		}

		$pos = strpos($text, $link);
		if ($pos === false) {
			return self::mb_truncate($text, $limit);
		}

		$before = trim(substr($text, 0, $pos));
		$after = trim(substr($text, $pos));
		$separator_len = 2;
		$available_before = $limit - self::mb_length($after) - $separator_len;
		if ($available_before < 20) {
			return self::mb_truncate($text, $limit);
		}

		return trim(self::mb_truncate($before, $available_before)) . "\n\n" . $after;
	}

	private static function network_opt_string(array $opt, string $network_key, string $fallback_key): string {
		$network_value = trim((string)($opt[$network_key] ?? ''));
		if ($network_value !== '') {
			return $network_value;
		}
		return trim((string)($opt[$fallback_key] ?? ''));
	}

	private static function network_opt_bool(array $opt, string $network_key, string $fallback_key): bool {
		if (array_key_exists($network_key, $opt)) {
			return !empty($opt[$network_key]);
		}
		return !empty($opt[$fallback_key]);
	}

	private static function network_opt_order(array $opt, string $network_key, string $fallback_key): string {
		$network_value = trim((string)($opt[$network_key] ?? ''));
		if ($network_value !== '') {
			return self::normalize_content_order($network_value);
		}
		return self::normalize_content_order((string)($opt[$fallback_key] ?? 'title,excerpt,url'));
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

	private static function mb_length(string $s): int {
		if (function_exists('mb_strlen')) {
			return (int)mb_strlen($s);
		}
		return strlen($s);
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

	/** @return array|WP_Error */
	private static function x_request_token(string $consumer_key, string $consumer_secret, string $callback_url) {
		$res = self::x_api_request(
			'POST',
			'https://api.x.com/oauth/request_token',
			$consumer_key,
			$consumer_secret,
			'',
			'',
			['oauth_callback' => $callback_url]
		);
		if (is_wp_error($res)) {
			return $res;
		}
		parse_str((string)($res['raw_body'] ?? ''), $data);
		if (!is_array($data) || empty($data['oauth_token']) || empty($data['oauth_token_secret'])) {
			return new WP_Error('pkliap_x_oauth', 'X OAuth: réponse request_token invalide.');
		}
		return $data;
	}

	/** @return array|WP_Error */
	private static function x_access_token(string $consumer_key, string $consumer_secret, string $request_token, string $request_token_secret, string $oauth_verifier) {
		$res = self::x_api_request(
			'POST',
			'https://api.x.com/oauth/access_token',
			$consumer_key,
			$consumer_secret,
			$request_token,
			$request_token_secret,
			['oauth_verifier' => $oauth_verifier]
		);
		if (is_wp_error($res)) {
			return $res;
		}
		parse_str((string)($res['raw_body'] ?? ''), $data);
		if (!is_array($data) || empty($data['oauth_token']) || empty($data['oauth_token_secret'])) {
			return new WP_Error('pkliap_x_oauth', 'X OAuth: réponse access_token invalide.');
		}
		return $data;
	}

	/** @return array|WP_Error */
	private static function x_create_tweet(string $text, array $opt) {
		$payload = ['text' => $text];

		$res = self::x_api_request(
			'POST',
			'https://api.x.com/2/tweets',
			(string)$opt['x_api_key'],
			(string)$opt['x_api_secret'],
			(string)$opt['x_access_token'],
			(string)$opt['x_access_token_secret'],
			[],
			$payload
		);
		if (!is_wp_error($res)) {
			return is_array($res['body']) ? $res['body'] : [];
		}

		// Fallback hôte historique si le domaine api.x.com n'est pas accessible chez certains hébergeurs.
		$res2 = self::x_api_request(
			'POST',
			'https://api.twitter.com/2/tweets',
			(string)$opt['x_api_key'],
			(string)$opt['x_api_secret'],
			(string)$opt['x_access_token'],
			(string)$opt['x_access_token_secret'],
			[],
			$payload
		);
		if (is_wp_error($res2)) {
			return $res2;
		}
		return is_array($res2['body']) ? $res2['body'] : [];
	}

	/** @return array|WP_Error */
	private static function x_get_me(array $opt) {
		$res = self::x_api_request(
			'GET',
			'https://api.x.com/2/users/me',
			(string)$opt['x_api_key'],
			(string)$opt['x_api_secret'],
			(string)$opt['x_access_token'],
			(string)$opt['x_access_token_secret'],
			[]
		);
		if (!is_wp_error($res)) {
			return is_array($res['body']) ? $res['body'] : [];
		}

		$res2 = self::x_api_request(
			'GET',
			'https://api.twitter.com/2/users/me',
			(string)$opt['x_api_key'],
			(string)$opt['x_api_secret'],
			(string)$opt['x_access_token'],
			(string)$opt['x_access_token_secret'],
			[]
		);
		if (!is_wp_error($res2)) {
			return is_array($res2['body']) ? $res2['body'] : [];
		}

		return $res2;
	}

	private static function x_percent_encode(string $value): string {
		return str_replace('%7E', '~', rawurlencode($value));
	}

	private static function x_random_nonce(int $len = 16): string {
		try {
			$bytes = random_bytes($len);
			return bin2hex($bytes);
		} catch (Throwable $e) {
			return wp_generate_password($len * 2, false, false);
		}
	}

	private static function x_build_oauth_signature(string $method, string $url, array $all_params, string $consumer_secret, string $token_secret): string {
		$pairs = [];
		foreach ($all_params as $k => $v) {
			$k = (string)$k;
			$vals = is_array($v) ? $v : [$v];
			foreach ($vals as $vv) {
				$pairs[] = [self::x_percent_encode($k), self::x_percent_encode((string)$vv)];
			}
		}
		usort($pairs, static function (array $a, array $b): int {
			$cmp = strcmp($a[0], $b[0]);
			if ($cmp !== 0) {
				return $cmp;
			}
			return strcmp($a[1], $b[1]);
		});
		$normalized = [];
		foreach ($pairs as $p) {
			$normalized[] = $p[0] . '=' . $p[1];
		}

		$parts = parse_url($url);
		$scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : 'https';
		$host = isset($parts['host']) ? strtolower((string)$parts['host']) : '';
		$path = isset($parts['path']) ? (string)$parts['path'] : '';
		$normalized_url = $scheme . '://' . $host . $path;

		$base_string = strtoupper($method) . '&' . self::x_percent_encode($normalized_url) . '&' . self::x_percent_encode(implode('&', $normalized));
		$signing_key = self::x_percent_encode($consumer_secret) . '&' . self::x_percent_encode($token_secret);
		return base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));
	}

	/** @return array|WP_Error */
	private static function x_api_request(
		string $method,
		string $url,
		string $consumer_key,
		string $consumer_secret,
		string $token,
		string $token_secret,
		array $oauth_extra_params = [],
		array $json_body = []
	) {
		$oauth_params = [
			'oauth_consumer_key' => $consumer_key,
			'oauth_nonce' => self::x_random_nonce(),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp' => (string)time(),
			'oauth_version' => '1.0',
		];
		if ($token !== '') {
			$oauth_params['oauth_token'] = $token;
		}
		foreach ($oauth_extra_params as $k => $v) {
			$oauth_params[(string)$k] = (string)$v;
		}

		$query_params = [];
		$parts = parse_url($url);
		if (isset($parts['query'])) {
			parse_str((string)$parts['query'], $query_params);
		}

		$signature_params = array_merge($query_params, $oauth_params);
		$oauth_params['oauth_signature'] = self::x_build_oauth_signature($method, $url, $signature_params, $consumer_secret, $token_secret);

		$header_parts = [];
		$oauth_header = $oauth_params;
		ksort($oauth_header);
		foreach ($oauth_header as $k => $v) {
			$header_parts[] = self::x_percent_encode((string)$k) . '="' . self::x_percent_encode((string)$v) . '"';
		}

		$args = [
			'method' => strtoupper($method),
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'OAuth ' . implode(', ', $header_parts),
				'Accept' => 'application/json',
			],
		];

		if (!empty($json_body)) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode($json_body);
		}

		$res = wp_remote_request($url, $args);
		if (is_wp_error($res)) {
			return $res;
		}

		$code = (int)wp_remote_retrieve_response_code($res);
		$raw_body = (string)wp_remote_retrieve_body($res);
		$body = json_decode($raw_body, true);

		if ($code < 200 || $code >= 300) {
			$msg = 'Erreur API X (HTTP ' . $code . ').';
			if (is_array($body) && !empty($body['detail'])) {
				$msg .= ' ' . (string)$body['detail'];
			} elseif (is_array($body) && !empty($body['title'])) {
				$msg .= ' ' . (string)$body['title'];
			} elseif ($raw_body !== '') {
				$msg .= ' ' . self::mb_truncate(wp_strip_all_tags($raw_body), 280);
			}
			return new WP_Error('pkliap_x_api_error', $msg);
		}

		return [
			'code' => $code,
			'body' => is_array($body) ? $body : [],
			'raw_body' => $raw_body,
		];
	}

	/** @return array|WP_Error */
	private static function meta_graph_post(string $path, array $payload, string $access_token) {
		$url = 'https://graph.facebook.com/v23.0' . $path;
		$payload['access_token'] = $access_token;

		$res = wp_remote_post($url, [
			'timeout' => 45,
			'body' => $payload,
		]);
		if (is_wp_error($res)) {
			return $res;
		}
		$code = (int)wp_remote_retrieve_response_code($res);
		$raw_body = (string)wp_remote_retrieve_body($res);
		$body = json_decode($raw_body, true);
		if ($code < 200 || $code >= 300) {
			$msg = 'Erreur API Meta (HTTP ' . $code . ').';
			if (is_array($body) && !empty($body['error']['message'])) {
				$msg .= ' ' . (string)$body['error']['message'];
			}
			return new WP_Error('pkliap_meta_api_error', $msg);
		}
		return [
			'code' => $code,
			'body' => is_array($body) ? $body : [],
		];
	}

	/** @return array|WP_Error */
	private static function meta_graph_get(string $path, array $query, string $access_token) {
		$url = 'https://graph.facebook.com/v23.0' . $path;
		$query['access_token'] = $access_token;
		$url = add_query_arg($query, $url);

		$res = wp_remote_get($url, [
			'timeout' => 45,
		]);
		if (is_wp_error($res)) {
			return $res;
		}
		$code = (int)wp_remote_retrieve_response_code($res);
		$raw_body = (string)wp_remote_retrieve_body($res);
		$body = json_decode($raw_body, true);
		if ($code < 200 || $code >= 300) {
			$msg = 'Erreur API Meta (HTTP ' . $code . ').';
			if (is_array($body) && !empty($body['error']['message'])) {
				$msg .= ' ' . (string)$body['error']['message'];
			}
			return new WP_Error('pkliap_meta_api_error', $msg);
		}
		return [
			'code' => $code,
			'body' => is_array($body) ? $body : [],
		];
	}

	/** @return array|WP_Error */
	private static function meta_exchange_long_lived_token(string $app_id, string $app_secret, string $short_token) {
		$url = add_query_arg([
			'grant_type' => 'fb_exchange_token',
			'client_id' => $app_id,
			'client_secret' => $app_secret,
			'fb_exchange_token' => $short_token,
		], 'https://graph.facebook.com/v23.0/oauth/access_token');

		$res = wp_remote_get($url, [
			'timeout' => 45,
		]);
		if (is_wp_error($res)) {
			return $res;
		}
		$code = (int)wp_remote_retrieve_response_code($res);
		$raw_body = (string)wp_remote_retrieve_body($res);
		$body = json_decode($raw_body, true);
		if ($code < 200 || $code >= 300) {
			$msg = 'Meta: échange token longue durée impossible (HTTP ' . $code . ').';
			if (is_array($body) && !empty($body['error']['message'])) {
				$msg .= ' ' . (string)$body['error']['message'];
			}
			return new WP_Error('pkliap_meta_token_exchange', $msg);
		}
		if (!is_array($body) || empty($body['access_token'])) {
			return new WP_Error('pkliap_meta_token_exchange', 'Meta: réponse token longue durée invalide.');
		}
		return $body;
	}

	private static function select_meta_page(array $pages, string $preferred_page_id): array {
		foreach ($pages as $page) {
			if (!is_array($page)) {
				continue;
			}
			if ($preferred_page_id !== '' && (string)($page['id'] ?? '') === $preferred_page_id) {
				return $page;
			}
		}
		foreach ($pages as $page) {
			if (is_array($page) && !empty($page['instagram_business_account']['id'])) {
				return $page;
			}
		}
		foreach ($pages as $page) {
			if (is_array($page)) {
				return $page;
			}
		}
		return [];
	}

	/** @return array|WP_Error */
	private static function threads_api_post(string $path, array $payload, string $access_token) {
		$url = 'https://graph.threads.net/v1.0' . $path;
		$payload['access_token'] = $access_token;

		$res = wp_remote_post($url, [
			'timeout' => 45,
			'body' => $payload,
		]);
		if (is_wp_error($res)) {
			return $res;
		}
		$code = (int)wp_remote_retrieve_response_code($res);
		$raw_body = (string)wp_remote_retrieve_body($res);
		$body = json_decode($raw_body, true);
		if ($code < 200 || $code >= 300) {
			$msg = 'Erreur API Threads (HTTP ' . $code . ').';
			if (is_array($body) && !empty($body['error']['message'])) {
				$msg .= ' ' . (string)$body['error']['message'];
			}
			return new WP_Error('pkliap_threads_api_error', $msg);
		}
		return [
			'code' => $code,
			'body' => is_array($body) ? $body : [],
		];
	}

	/** @return array|WP_Error */
	private static function threads_api_get(string $path, array $query, string $access_token) {
		$url = 'https://graph.threads.net/v1.0' . $path;
		$query['access_token'] = $access_token;
		$url = add_query_arg($query, $url);

		$res = wp_remote_get($url, [
			'timeout' => 45,
		]);
		if (is_wp_error($res)) {
			return $res;
		}
		$code = (int)wp_remote_retrieve_response_code($res);
		$raw_body = (string)wp_remote_retrieve_body($res);
		$body = json_decode($raw_body, true);
		if ($code < 200 || $code >= 300) {
			$msg = 'Erreur API Threads (HTTP ' . $code . ').';
			if (is_array($body) && !empty($body['error']['message'])) {
				$msg .= ' ' . (string)$body['error']['message'];
			}
			return new WP_Error('pkliap_threads_api_error', $msg);
		}
		return [
			'code' => $code,
			'body' => is_array($body) ? $body : [],
		];
	}

	/** @return array|WP_Error */
	private static function medium_get_me(string $access_token) {
		$res = self::medium_api_get('/v1/me', $access_token);
		if (is_wp_error($res)) {
			return $res;
		}
		$body = is_array($res['body'] ?? null) ? $res['body'] : [];
		$data = is_array($body['data'] ?? null) ? $body['data'] : [];
		if (empty($data['id'])) {
			return new WP_Error('pkliap_medium_me', 'Medium: réponse /v1/me invalide.');
		}
		return $data;
	}

	/** @return array|WP_Error */
	private static function medium_api_get(string $path, string $access_token) {
		$url = 'https://api.medium.com' . $path;
		$res = wp_remote_get($url, [
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Accept' => 'application/json',
				'Accept-Charset' => 'utf-8',
			],
		]);
		return self::medium_parse_response($res);
	}

	/** @return array|WP_Error */
	private static function medium_api_post(string $path, array $payload, string $access_token) {
		$url = 'https://api.medium.com' . $path;
		$res = wp_remote_post($url, [
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
				'Accept-Charset' => 'utf-8',
			],
			'body' => wp_json_encode($payload),
		]);
		return self::medium_parse_response($res);
	}

	/** @return array|WP_Error */
	private static function medium_parse_response($res) {
		if (is_wp_error($res)) {
			return $res;
		}
		$code = (int)wp_remote_retrieve_response_code($res);
		$raw_body = (string)wp_remote_retrieve_body($res);
		$body = json_decode($raw_body, true);
		if ($code < 200 || $code >= 300) {
			$msg = 'Erreur API Medium (HTTP ' . $code . ').';
			if (is_array($body) && !empty($body['errors'][0]['message'])) {
				$msg .= ' ' . (string)$body['errors'][0]['message'];
			} elseif ($raw_body !== '') {
				$msg .= ' ' . self::mb_truncate(wp_strip_all_tags($raw_body), 280);
			}
			return new WP_Error('pkliap_medium_api_error', $msg);
		}
		return [
			'code' => $code,
			'body' => is_array($body) ? $body : [],
		];
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

	private static function debug_log_event(string $message): void {
		$opt = self::get_options();
		$log = is_array($opt['debug_log']) ? $opt['debug_log'] : [];
		$log[] = [
			'ts' => time(),
			'message' => $message,
		];
		if (count($log) > 40) {
			$log = array_slice($log, -40);
		}
		self::update_options(['debug_log' => $log]);

		if (!empty($opt['log_enabled'])) {
			error_log('[pkliap] ' . $message);
		}
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
	private static function linkedin_refresh_access_token(string $client_id, string $client_secret, string $refresh_token) {
		$res = wp_remote_post('https://www.linkedin.com/oauth/v2/accessToken', [
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			'body' => [
				'grant_type' => 'refresh_token',
				'refresh_token' => $refresh_token,
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
			$msg = 'Erreur refresh token LinkedIn (HTTP ' . $code_http . ').';
			if (is_array($body) && !empty($body['error_description'])) {
				$msg .= ' ' . (string)$body['error_description'];
			}
			return new WP_Error('pkliap_token_refresh_failed', $msg);
		}

		if (!is_array($body) || empty($body['access_token'])) {
			return new WP_Error('pkliap_token_refresh_failed', 'Réponse refresh token LinkedIn invalide.');
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

	/** @return array|WP_Error */
	private static function maybe_refresh_linkedin_token(array $opt) {
		$access_exp = (int)($opt['access_token_expires_at'] ?? 0);
		$refresh_token = (string)($opt['refresh_token'] ?? '');
		$refresh_exp = (int)($opt['refresh_token_expires_at'] ?? 0);

		if ($access_exp <= 0 || time() < $access_exp) {
			return true;
		}

		if ($refresh_token === '') {
			return new WP_Error('pkliap_no_refresh_token', 'Token LinkedIn expiré et aucun refresh token disponible. Reconnecte le compte.');
		}
		if ($refresh_exp > 0 && time() >= $refresh_exp) {
			return new WP_Error('pkliap_refresh_token_expired', 'Refresh token LinkedIn expiré. Reconnecte le compte.');
		}

		$token = self::linkedin_refresh_access_token((string)$opt['client_id'], (string)$opt['client_secret'], $refresh_token);
		if (is_wp_error($token)) {
			return $token;
		}

		$access_token = (string)($token['access_token'] ?? '');
		$expires_in = (int)($token['expires_in'] ?? 0);
		$new_refresh_token = (string)($token['refresh_token'] ?? $refresh_token);
		$new_refresh_expires_in = (int)($token['refresh_token_expires_in'] ?? 0);

		if ($access_token === '') {
			return new WP_Error('pkliap_token_refresh_failed', 'LinkedIn a renvoyé un access token vide.');
		}

		self::set_tokens([
			'access_token' => $access_token,
			'access_token_expires_at' => $expires_in ? (time() + $expires_in - 60) : 0,
			'refresh_token' => $new_refresh_token,
			'refresh_token_expires_at' => $new_refresh_expires_in ? (time() + $new_refresh_expires_in - 60) : $refresh_exp,
		]);

		self::update_options([
			'last_oauth_at' => time(),
			'last_oauth_error' => '',
		]);

		return true;
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

register_deactivation_hook(__FILE__, ['PKLIAP_Plugin', 'deactivate']);
PKLIAP_Plugin::init();
