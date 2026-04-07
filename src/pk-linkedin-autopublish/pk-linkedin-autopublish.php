<?php
/**
 * Plugin Name: PK LinkedIn Auto Publish
 * Description: Publie automatiquement vos nouveaux articles sur LinkedIn (image mise en avant + extrait + lien).
 * Version: 0.02
 * Author: PK
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
	exit;
}

final class PKLIAP_Plugin {
	const OPT_KEY = 'pkliap_options';
	const META_SHARED_AT = '_pkliap_shared_at';
	const META_SHARE_URN = '_pkliap_linkedin_urn';

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'admin_menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);

		add_action('admin_post_pkliap_connect', [__CLASS__, 'handle_connect']);
		add_action('admin_post_pkliap_oauth_callback', [__CLASS__, 'handle_oauth_callback']);
		add_action('admin_post_pkliap_disconnect', [__CLASS__, 'handle_disconnect']);
		add_action('admin_post_pkliap_test_post', [__CLASS__, 'handle_test_post']);

		add_action('transition_post_status', [__CLASS__, 'on_transition_post_status'], 10, 3);
	}

	public static function defaults(): array {
		return [
			'enabled' => 0,
			'client_id' => '',
			'client_secret' => '',
			'redirect_uri' => '',
			'author_urn' => '',
			'access_token' => '',
			'access_token_expires_at' => 0,
			'refresh_token' => '',
			'refresh_token_expires_at' => 0,
			'linkedin_version' => gmdate('Ym'),
			'visibility' => 'PUBLIC',
			'prefix' => '',
			'suffix' => '',
			'use_wp_shortlink' => 1,
			'post_type_whitelist' => ['post'],
			'share_on_update' => 0,
			'only_once' => 1,
			'append_utm' => 0,
			'utm_source' => 'linkedin',
			'utm_medium' => 'social',
			'utm_campaign' => 'autopublish',
		];
	}

	public static function get_options(): array {
		$stored = get_option(self::OPT_KEY, []);
		if (!is_array($stored)) {
			$stored = [];
		}
		return array_merge(self::defaults(), $stored);
	}

	public static function update_options(array $new): void {
		update_option(self::OPT_KEY, array_merge(self::get_options(), $new));
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

		$out = [];
		$out['enabled'] = empty($value['enabled']) ? 0 : 1;
		$out['client_id'] = sanitize_text_field((string)($value['client_id'] ?? ''));
		$out['client_secret'] = sanitize_text_field((string)($value['client_secret'] ?? ''));
		$out['redirect_uri'] = esc_url_raw((string)($value['redirect_uri'] ?? ''));
		$out['author_urn'] = sanitize_text_field((string)($value['author_urn'] ?? ''));
		$out['linkedin_version'] = preg_match('/^[0-9]{6}$/', (string)($value['linkedin_version'] ?? '')) ? (string)$value['linkedin_version'] : $defaults['linkedin_version'];
		$out['visibility'] = in_array((string)($value['visibility'] ?? ''), ['PUBLIC', 'CONNECTIONS', 'LOGGED_IN'], true) ? (string)$value['visibility'] : $defaults['visibility'];
		$out['prefix'] = sanitize_text_field((string)($value['prefix'] ?? ''));
		$out['suffix'] = sanitize_text_field((string)($value['suffix'] ?? ''));
		$out['use_wp_shortlink'] = empty($value['use_wp_shortlink']) ? 0 : 1;
		$out['share_on_update'] = empty($value['share_on_update']) ? 0 : 1;
		$out['only_once'] = empty($value['only_once']) ? 0 : 1;
		$out['append_utm'] = empty($value['append_utm']) ? 0 : 1;
		$out['utm_source'] = sanitize_text_field((string)($value['utm_source'] ?? $defaults['utm_source']));
		$out['utm_medium'] = sanitize_text_field((string)($value['utm_medium'] ?? $defaults['utm_medium']));
		$out['utm_campaign'] = sanitize_text_field((string)($value['utm_campaign'] ?? $defaults['utm_campaign']));

		$post_types = array_filter(array_map('sanitize_key', (array)($value['post_type_whitelist'] ?? $defaults['post_type_whitelist'])));
		$out['post_type_whitelist'] = $post_types ? array_values(array_unique($post_types)) : $defaults['post_type_whitelist'];

		// Ne pas écraser les tokens lors d'un enregistrement de la page.
		foreach (['access_token', 'access_token_expires_at', 'refresh_token', 'refresh_token_expires_at'] as $k) {
			$out[$k] = self::get_options()[$k];
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
		$has_token = !empty($opt['access_token']) && (!empty($opt['access_token_expires_at']) ? (time() < (int)$opt['access_token_expires_at']) : true);

		$post_types = get_post_types(['public' => true], 'objects');

		$link_apps = 'https://www.linkedin.com/developers/apps';
		$link_docs_oauth = 'https://learn.microsoft.com/linkedin/shared/authentication/authorization-code-flow';
		$link_docs_ugc = 'https://learn.microsoft.com/linkedin/marketing/community-management/shares/ugc-post-api';
		$link_docs_assets = 'https://learn.microsoft.com/linkedin/marketing/integrations/community-management/shares/vector-asset-api';

		?>
		<div class="wrap">
			<h1>WP PK SocialSharing</h1>
			<p>Publication automatique sur LinkedIn lors de la mise en ligne d’un article (image mise en avant + extrait + lien).</p>

			<?php if (!empty($_GET['pkliap_notice'])): ?>
				<div class="notice notice-info"><p><?php echo esc_html((string)wp_unslash($_GET['pkliap_notice'])); ?></p></div>
			<?php endif; ?>
			<?php if (!empty($_GET['pkliap_error'])): ?>
				<div class="notice notice-error"><p><?php echo esc_html((string)wp_unslash($_GET['pkliap_error'])); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields('pkliap'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Activer</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[enabled]" value="1" <?php checked(1, (int)$opt['enabled']); ?>/> Publier automatiquement</label>
							<p class="description">Quand activé, le plugin tente de poster sur LinkedIn au moment où un contenu passe en statut <code>publish</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Client ID</th>
						<td>
							<input class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[client_id]" value="<?php echo esc_attr($opt['client_id']); ?>"/>
							<p class="description">
								Où le trouver : dans votre app LinkedIn (tableau de bord) → “Auth” / “OAuth settings”.
								Créez/ouvrez votre app ici : <a href="<?php echo esc_url($link_apps); ?>" target="_blank" rel="noopener">LinkedIn Developers – My Apps</a>.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Client Secret</th>
						<td>
							<input class="regular-text" type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[client_secret]" value="<?php echo esc_attr($opt['client_secret']); ?>"/>
							<p class="description">
								Où le trouver : même endroit que le Client ID, sur la page de votre app LinkedIn.
								Si vous ne le voyez pas, LinkedIn propose souvent un bouton “Generate/Regenerate secret”.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Redirect URI</th>
						<td>
							<input class="regular-text" type="url" name="<?php echo esc_attr(self::OPT_KEY); ?>[redirect_uri]" value="<?php echo esc_attr($opt['redirect_uri']); ?>"/>
							<p class="description">
								Étape obligatoire : copiez-collez cette URL dans votre app LinkedIn → “OAuth 2.0 settings” → “Authorized redirect URLs”.
								Recommandé : <code><?php echo esc_html(self::admin_url_action('pkliap_oauth_callback')); ?></code>
							</p>
							<p class="description">
								Aide : <a href="<?php echo esc_url($link_docs_oauth); ?>" target="_blank" rel="noopener">Doc LinkedIn OAuth (Authorization Code Flow)</a>.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Author URN</th>
						<td>
							<input class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[author_urn]" value="<?php echo esc_attr($opt['author_urn']); ?>"/>
							<p class="description">
								C’est “qui poste” : votre profil (<code>urn:li:person:…</code>) ou une Page (<code>urn:li:organization:…</code>).
								Si vous publiez en tant que Page, l’app doit avoir les permissions LinkedIn pour les organisations.
							</p>
							<p class="description">
								Astuces pour le récupérer :
								- Profil : via les outils/API LinkedIn (endpoint <code>me</code>) ou via votre outil d’intégration existant.
								- Page : depuis l’admin de la Page + API organisations (dépend des permissions).
								Doc : <a href="<?php echo esc_url($link_docs_ugc); ?>" target="_blank" rel="noopener">UGC Post API</a>.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">LinkedIn-Version</th>
						<td>
							<input class="small-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[linkedin_version]" value="<?php echo esc_attr($opt['linkedin_version']); ?>"/>
							<p class="description">
								Format <code>YYYYMM</code> (ex: <code>202603</code>). Utilisé pour l’API Assets (<code>/rest</code>) lors de l’upload d’images.
								Doc : <a href="<?php echo esc_url($link_docs_assets); ?>" target="_blank" rel="noopener">Asset / registerUpload</a>.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Visibilité</th>
						<td>
							<select name="<?php echo esc_attr(self::OPT_KEY); ?>[visibility]">
								<option value="PUBLIC" <?php selected('PUBLIC', $opt['visibility']); ?>>Public</option>
								<option value="LOGGED_IN" <?php selected('LOGGED_IN', $opt['visibility']); ?>>Connectés</option>
								<option value="CONNECTIONS" <?php selected('CONNECTIONS', $opt['visibility']); ?>>Relations</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Types de contenu</th>
						<td>
							<p class="description">Cochez uniquement les types à partager (recommandé : <code>post</code>).</p>
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
							<label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[use_wp_shortlink]" value="1" <?php checked(1, (int)$opt['use_wp_shortlink']); ?>/> Utiliser le shortlink WordPress (si dispo)</label><br/>
							<label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[append_utm]" value="1" <?php checked(1, (int)$opt['append_utm']); ?>/> Ajouter des paramètres UTM</label>
							<p class="description">
								UTM: source <input class="small-text" name="<?php echo esc_attr(self::OPT_KEY); ?>[utm_source]" value="<?php echo esc_attr($opt['utm_source']); ?>"/> medium <input class="small-text" name="<?php echo esc_attr(self::OPT_KEY); ?>[utm_medium]" value="<?php echo esc_attr($opt['utm_medium']); ?>"/> campaign <input class="small-text" name="<?php echo esc_attr(self::OPT_KEY); ?>[utm_campaign]" value="<?php echo esc_attr($opt['utm_campaign']); ?>"/>
							</p>
							<p class="description">Si votre site a déjà un “lien court” (shortlink), cochez l’option pour l’utiliser automatiquement.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Texte</th>
						<td>
							<p class="description">Le contenu final est tronqué côté plugin pour rester raisonnable, LinkedIn peut aussi appliquer ses propres limites.</p>
							<label>Préfixe<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[prefix]" value="<?php echo esc_attr($opt['prefix']); ?>"/></label><br/>
							<label>Suffixe<br/><input class="large-text" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[suffix]" value="<?php echo esc_attr($opt['suffix']); ?>"/></label>
						</td>
					</tr>
					<tr>
						<th scope="row">Anti-doublon</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[only_once]" value="1" <?php checked(1, (int)$opt['only_once']); ?>/> Publier une seule fois par article</label><br/>
							<label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[share_on_update]" value="1" <?php checked(1, (int)$opt['share_on_update']); ?>/> Republier lors d’une mise à jour (si déjà partagé)</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr/>
			<h2>Connexion LinkedIn</h2>
			<p>Statut : <?php echo $has_token ? '<strong>Connecté</strong>' : '<strong>Non connecté</strong>'; ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_connect'), 'pkliap_connect')); ?>">Connecter / Reconnecter</a>
				<a class="button" href="<?php echo esc_url(wp_nonce_url(self::admin_url_action('pkliap_disconnect'), 'pkliap_disconnect')); ?>">Déconnecter</a>
			</p>

			<h2>Test</h2>
			<form method="post" action="<?php echo esc_url(self::admin_url_action('pkliap_test_post')); ?>">
				<?php wp_nonce_field('pkliap_test_post'); ?>
				<p class="description">Choisissez un article publié pour tenter un partage LinkedIn.</p>
				<select name="post_id">
					<?php
					$posts = get_posts([
						'post_type' => $opt['post_type_whitelist'],
						'post_status' => 'publish',
						'numberposts' => 20,
						'orderby' => 'date',
						'order' => 'DESC',
					]);
					foreach ($posts as $p) {
						echo '<option value="' . esc_attr((string)$p->ID) . '">' . esc_html($p->post_title . ' (#' . $p->ID . ')') . '</option>';
					}
					?>
				</select>
				<?php submit_button('Publier maintenant', 'secondary', 'submit', false); ?>
			</form>
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
			wp_safe_redirect(self::settings_url(['pkliap_error' => 'Renseignez Client ID / Client Secret avant de connecter.']));
			exit;
		}

		$redirect_uri = $opt['redirect_uri'] ?: self::admin_url_action('pkliap_oauth_callback');

		$state = wp_generate_password(24, false, false);
		update_option('pkliap_oauth_state', $state, false);

		$scope = [
			'r_liteprofile',
			'r_emailaddress',
			'w_member_social',
			'w_organization_social',
			'r_organization_social',
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
		$expected_state = (string)get_option('pkliap_oauth_state', '');
		delete_option('pkliap_oauth_state');

		if (!$state || !$expected_state || !hash_equals($expected_state, $state)) {
			wp_safe_redirect(self::settings_url(['pkliap_error' => 'State OAuth invalide.']));
			exit;
		}

		$code = isset($_GET['code']) ? sanitize_text_field((string)wp_unslash($_GET['code'])) : '';
		if (!$code) {
			wp_safe_redirect(self::settings_url(['pkliap_error' => 'Code OAuth manquant.']));
			exit;
		}

		$opt = self::get_options();
		$redirect_uri = $opt['redirect_uri'] ?: self::admin_url_action('pkliap_oauth_callback');

		$token = self::linkedin_exchange_code_for_token($opt['client_id'], $opt['client_secret'], $redirect_uri, $code);
		if (is_wp_error($token)) {
			wp_safe_redirect(self::settings_url(['pkliap_error' => $token->get_error_message()]));
			exit;
		}

		$access_token = (string)($token['access_token'] ?? '');
		$expires_in = (int)($token['expires_in'] ?? 0);
		$refresh_token = (string)($token['refresh_token'] ?? '');
		$refresh_expires_in = (int)($token['refresh_token_expires_in'] ?? 0);

		if (!$access_token) {
			wp_safe_redirect(self::settings_url(['pkliap_error' => 'Réponse token invalide (access_token manquant).']));
			exit;
		}

		self::update_options([
			'access_token' => $access_token,
			'access_token_expires_at' => $expires_in ? (time() + $expires_in - 60) : 0,
			'refresh_token' => $refresh_token,
			'refresh_token_expires_at' => $refresh_expires_in ? (time() + $refresh_expires_in - 60) : 0,
		]);

		wp_safe_redirect(self::settings_url(['pkliap_notice' => 'Connecté à LinkedIn.']));
		exit;
	}

	public static function handle_disconnect(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_disconnect');

		self::update_options([
			'access_token' => '',
			'access_token_expires_at' => 0,
			'refresh_token' => '',
			'refresh_token_expires_at' => 0,
		]);

		wp_safe_redirect(self::settings_url(['pkliap_notice' => 'Déconnecté.']));
		exit;
	}

	public static function handle_test_post(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
		check_admin_referer('pkliap_test_post');

		$post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
		if (!$post_id) {
			wp_safe_redirect(self::settings_url(['pkliap_error' => 'post_id manquant.']));
			exit;
		}

		$res = self::share_post_to_linkedin($post_id, true);
		if (is_wp_error($res)) {
			wp_safe_redirect(self::settings_url(['pkliap_error' => $res->get_error_message()]));
			exit;
		}

		wp_safe_redirect(self::settings_url(['pkliap_notice' => 'Post LinkedIn envoyé.']));
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
		$res = self::share_post_to_linkedin($post->ID, false);
		if (is_wp_error($res)) {
			error_log('[pkliap] LinkedIn share failed for post #' . $post->ID . ': ' . $res->get_error_message());
		}
	}

	private static function share_post_to_linkedin(int $post_id, bool $force): array|WP_Error {
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

		$asset_urn = '';
		$thumb_id = get_post_thumbnail_id($post_id);
		if ($thumb_id) {
			$asset_urn_res = self::upload_featured_image($thumb_id, $opt);
			if (is_wp_error($asset_urn_res)) {
				return $asset_urn_res;
			}
			$asset_urn = (string)$asset_urn_res;
		}

		$payload = [
			'author' => $opt['author_urn'],
			'lifecycleState' => 'PUBLISHED',
			'specificContent' => [
				'com.linkedin.ugc.ShareContent' => [
					'shareCommentary' => [
						'text' => $text,
					],
					'shareMediaCategory' => $asset_urn ? 'IMAGE' : 'NONE',
				],
			],
			'visibility' => [
				'com.linkedin.ugc.MemberNetworkVisibility' => $opt['visibility'],
			],
		];

		if ($asset_urn) {
			$payload['specificContent']['com.linkedin.ugc.ShareContent']['media'] = [[
				'status' => 'READY',
				'description' => ['text' => self::safe_excerpt($post_id, 200)],
				'media' => $asset_urn,
				'title' => ['text' => get_the_title($post_id)],
				'originalUrl' => $link,
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
		];
	}

	private static function settings_url(array $args = []): string {
		$url = admin_url('admin.php?page=pk-socialsharing');
		if ($args) {
			$url = add_query_arg($args, $url);
		}
		return $url;
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

		$parts = [];
		if ($opt['prefix']) {
			$parts[] = $opt['prefix'];
		}
		$parts[] = $title;
		if ($excerpt) {
			$parts[] = $excerpt;
		}
		$parts[] = $link;
		if ($opt['suffix']) {
			$parts[] = $opt['suffix'];
		}

		$text = trim(implode("\n\n", array_filter($parts, static fn($p) => (string)$p !== '')));
		return self::mb_truncate($text, 2800);
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

	private static function upload_featured_image(int $attachment_id, array $opt): string|WP_Error {
		$file = get_attached_file($attachment_id);
		if (!$file || !file_exists($file)) {
			return new WP_Error('pkliap_no_image', 'Image mise en avant introuvable.');
		}

		$mime = get_post_mime_type($attachment_id);
		if (!$mime) {
			$mime = 'image/jpeg';
		}

		$register_payload = [
			'registerUploadRequest' => [
				'owner' => $opt['author_urn'],
				'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
				'serviceRelationships' => [[
					'identifier' => 'urn:li:userGeneratedContent',
					'relationshipType' => 'OWNER',
				]],
				'supportedUploadMechanism' => ['SYNCHRONOUS_UPLOAD'],
			],
		];

		$register = self::linkedin_api_post('/rest/assets?action=registerUpload', $register_payload, $opt['access_token'], [
			'Linkedin-Version' => $opt['linkedin_version'],
			'X-Restli-Protocol-Version' => '2.0.0',
		]);

		if (is_wp_error($register)) {
			return $register;
		}

		$asset = (string)($register['body']['value']['asset'] ?? '');
		$upload_url = (string)($register['body']['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'] ?? '');
		if (!$asset || !$upload_url) {
			return new WP_Error('pkliap_register_upload_failed', 'Impossible de récupérer asset/uploadUrl depuis registerUpload.');
		}

		$put = wp_remote_request($upload_url, [
			'method' => 'PUT',
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'Bearer ' . $opt['access_token'],
				'Content-Type' => $mime,
			],
			'body' => file_get_contents($file),
		]);

		if (is_wp_error($put)) {
			return $put;
		}

		$code = (int)wp_remote_retrieve_response_code($put);
		if ($code < 200 || $code >= 300) {
			return new WP_Error('pkliap_upload_failed', 'Upload image LinkedIn échoué (HTTP ' . $code . ').');
		}

		return $asset;
	}

	private static function linkedin_exchange_code_for_token(string $client_id, string $client_secret, string $redirect_uri, string $code): array|WP_Error {
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

	private static function linkedin_api_post(string $path, array $payload, string $access_token, array $extra_headers = []): array|WP_Error {
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
}

PKLIAP_Plugin::init();
