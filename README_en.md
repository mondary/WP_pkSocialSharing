# WP PK SocialSharing

![Project icon](icon.png)

[🇬🇧 EN](README_en.md) · [🇫🇷 FR](README.md)

✨ WordPress plugin to automatically or manually share posts to LinkedIn, X, Facebook, Instagram, Threads, and Medium.

## ✅ Features

- Automatic sharing when a post reaches the `publish` status.
- Manual sharing from the admin to test or retry a specific network.
- Supported networks: LinkedIn, X, Facebook, Instagram, Threads, and Medium.
- Dashboard with scheduled/published posts, share status, and social post links.
- “Shares” column in the WordPress posts list with grey or active network icons.
- Featured image support for Facebook and Instagram when the network allows it.
- WP-Cron retry every 5 minutes for pending shares.
- WP-CLI fallback to retry shares without relying on WP-Cron.
- Centralized Meta setup: OAuth connection, long-lived token, Facebook Page and Instagram account detection.
- X publishing through the API or browser fallback when API credits are not available.

## 🧠 Usage

1. Install and activate the WordPress plugin.
2. Go to `WP Admin > WP PK SocialSharing`.
3. Configure the networks you need in their tabs.
4. Publish or schedule a WordPress post.
5. Check the status from the dashboard or from the “Shares” column in the posts list.

Automatic sharing runs when the post switches to `publish`. Already-published posts can be shared from the test blocks or retried with WP-CLI.

## ⚙️ Settings

- `Dashboard`: overview of scheduled/published posts and per-network status.
- `Day`: daily publication tracking.
- `LinkedIn`: LinkedIn OAuth, profile or organization Author URN, visibility, message format.
- `X (Twitter)`: API keys, automatic mode when API credits are available, manual browser mode otherwise.
- `Facebook`: Page ID, Page Access Token, Page publishing with featured image when available.
- `Instagram`: IG User ID, Access Token, publishing through the Instagram Graph API.
- `Threads`: Threads User ID, Access Token, publishing through the Threads API.
- `Medium`: Integration token, User ID, `public`, `draft`, or `unlisted` status.
- `Meta`: App ID, App Secret, and OAuth connection to obtain a long-lived token and configure Facebook/Instagram cleanly.
- `Content types`: post types allowed for automatic sharing.

## 🧾 Commands

Retry pending shares:

```bash
wp pksocialsharing retry --network=x --limit=20
```

Examples:

```bash
wp pksocialsharing retry --network=facebook --limit=10
wp pksocialsharing retry --network=instagram --limit=10
wp pksocialsharing retry --limit=50
```

REST sync route used by the local workflow when the plugin is already installed:

```text
/wp-json/pksocialsharing/v1/sync-plugin
```

## 📦 Build & Package

There is no required JavaScript or CSS build step.

The plugin source lives in:

```text
src/pk-linkedin-autopublish/
```

The folder name remains `pk-linkedin-autopublish` for compatibility with existing installs, but the plugin shown in WordPress is now named `WP PK SocialSharing`.

## 🧪 Installation

Manual installation:

1. Copy `src/pk-linkedin-autopublish/` to `wp-content/plugins/pk-linkedin-autopublish/`.
2. Activate `WP PK SocialSharing` from `Plugins`.
3. Open `WP Admin > WP PK SocialSharing`.
4. Configure the networks you want to use.

Live update:

- The repository includes a REST sync workflow to push plugin files without generating a zip.
- After an update, verify the version shown in WordPress and test a manual share on the modified network.

## 🧾 Changelog

- `1.1.1`: renamed documentation/plugin to `WP PK SocialSharing`, added FR/EN README, clarified Meta guide.
- `1.1.0`: long-lived Meta OAuth connection, automatic Facebook/Instagram detection, dashboard and share-status column.
- `0.91`: converted a short Graph Explorer token into a long-lived token.
- `0.90`: simplified admin bar with text label and badge.
- `0.80`: added the “Shares” column in the posts list.
- `0.78`: Facebook now publishes the featured image through the photos endpoint.
- `0.74`: added Medium, WP-Cron retry, and WP-CLI command.
- `0.73`: immediate publishing for Facebook, Instagram, and Threads.

## 🔗 Links

- FR README: [README.md](README.md)
- Plugin source: [src/pk-linkedin-autopublish](src/pk-linkedin-autopublish)
