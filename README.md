# WP GitHub Updates

Updates for WordPress plugins and themes from GitHub releases, private or public, with the access token kept out of the code. Built on [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), which Composer installs with it.

## Install

```
composer require webdados/wp-github-updates
```

`vendor/` must ship in the release zip, so keep it out of `.distignore`. Build it with `composer install --no-dev --optimize-autoloader` and commit it, so the zip holds exactly the `vendor/` you tested (or run that command in the release workflow instead, with `vendor/` gitignored). To move to a newer release of this package, run `composer update webdados/wp-github-updates --no-dev --optimize-autoloader` and commit `vendor/` and `composer.lock` together.

With a committed `vendor/`, leave Composer out of Dependabot: its pull requests update `composer.lock` but not `vendor/`, so after merging one the zip would still ship the old code. If you keep it, rebuild and commit `vendor/` after every Dependabot merge.

Then load it from the main plugin file or the theme's `functions.php`:

```php
require __DIR__ . '/vendor/autoload.php';
```

Create the updater when the plugin or theme loads, not on a later hook.

## Private repository, token in an option

```php
new \Webdados\GitHubUpdates\Updater(
	array(
		'repository'    => 'https://github.com/acme/acme-plugin',
		'file'          => __FILE__,
		'slug'          => 'acme-plugin',
		'name'          => 'The Acme plugin',
		'token_option'  => 'acme_github_token',
		'settings_url'  => 'admin.php?page=acme-settings',
		'settings_name' => 'Acme settings',
	)
);
```

`token_option` is any option: a Settings API field (stored under its own name), an ACF options page field (stored as `options_{field name}`), or anything else saved with `update_option()`. It is read with `get_option()`, so it works before ACF loads, and a theme can read the token its companion plugin stores, even while that plugin is inactive.

Whatever is saved to that option is checked: a value that does not look like a GitHub token (`github_pat_...` or `ghp_...`) keeps the saved one. That stops a browser filling the password field with the user's WordPress password, which would otherwise stop updates without any sign. An empty value also keeps the token; to remove it on purpose, use `delete_option()`.

Protecting the settings field itself (who can see and change it) is up to the project, since it depends on how the settings screen is built.

## Private repository, token from somewhere else

`token` takes the token itself or a callable returning it, e.g. a constant or a value inside an array option:

```php
'token' => function () {
	return defined( 'ACME_GITHUB_TOKEN' ) ? ACME_GITHUB_TOKEN : '';
},
```

It is called when the updater is created, so it must work that early. Nothing is validated in this case.

## Public repository

Leave out both `token_option` and `token`. No token is sent and no notice is shown.

Note that GitHub allows 60 unauthenticated API requests per hour per IP address. Plugin Update Checker checks every 12 hours per site, which is fine for most hosting, but many sites on one shared IP can hit the limit.

## Pre-releases

GitHub pre-releases are skipped by default. `prereleases` offers them too, as a bool or a callable returning one, read when the updater is created. To give betas to local and staging sites only:

```php
'prereleases' => 'production' !== wp_get_environment_type(),
```

The package never decides this by itself, so a site only installs betas when its plugin or theme asks for them.

How the environment check behaves:

- On a local site `wp_get_environment_type()` returns `local`, so it sees betas.
- When `WP_ENVIRONMENT_TYPE` is not set, WordPress returns `production`. Production is safe by default, as long as nobody sets it to something else there.
- A staging copy only sees betas if its `wp-config.php` sets `WP_ENVIRONMENT_TYPE` to `staging`. A copy made with Softaculous does not do that by itself.

Version numbers: the beta's plugin or theme header must carry the same suffix as its tag (`1.2.0-beta.1`). WordPress compares that as lower than `1.2.0`, so the stable release replaces the beta on the next update check.

Which release is offered: with pre-releases on, the 20 most recent releases are read and the newest one created wins, not the highest version. That only matters if tags are pushed out of version order. Drafts are always skipped.

## Without a token

When a token is expected but empty, no update checker is built (a private repository answers 404 to anonymous requests) and an error is shown on every wp-admin screen to users with `capability` (default `manage_options`), linking to `settings_url` when given:

> **No updates:** The Acme plugin and the Acme theme are not getting updates because their GitHub access token is not set. Set it in Acme settings.

Projects sharing one `token_option` share one notice, even when they ship different versions of this package. An invalid or expired token is not detected: Plugin Update Checker just finds no update.

## All options

| Option | Default | |
|---|---|---|
| `repository` | | GitHub repository URL. Required |
| `file` | | Main plugin file, or the theme's `functions.php`. Required |
| `slug` | worked out by Plugin Update Checker | Plugin or theme slug |
| `name` | the slug | What stops getting updates, in the notice. Written in the site's language, it is placed in a translated sentence |
| `token_option` | | Option holding the token |
| `token` | | Token, or callable returning it, used when `token_option` is empty |
| `settings_url` | | Where the token is set, relative to wp-admin |
| `settings_name` | "the settings" | Text of that link |
| `release_assets` | `true` | `true` downloads the zip attached to the release, a regex picks one asset, `false` uses GitHub's source zip |
| `capability` | `manage_options` | Who sees the notice |
| `prereleases` | `false` | `true`, or a callable returning `true`, also offers GitHub pre-releases |

`get_update_checker()` returns the Plugin Update Checker instance for further customisation, or `null` when it was not built.

## The token

Use a fine-grained personal access token with **Contents: Read-only** on the repositories it updates, and nothing else. Every site that has it can read those repositories, so treat it as known to everyone with admin access to those sites, and rotate it if one of them should no longer have it.

Not for plugins hosted on WordPress.org, whose guidelines do not allow updates from anywhere else.

## Several plugins with different versions of this package

The first autoloader to load `Webdados\GitHubUpdates\Updater` wins, so every plugin on the site uses that version. The configuration array stays backwards compatible across 1.x releases for this reason. Plugin Update Checker itself handles several versions side by side.

## Translations

English and European Portuguese (`pt_PT`) are included in `languages/`, loaded from the package's own folder.

## License

GPL-2.0-or-later.
