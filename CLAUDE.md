# WP GitHub Updates: AI context

Composer library (`webdados/wp-github-updates`, namespace `Webdados\GitHubUpdates`) that builds Plugin Update Checker (PUC, a Composer dependency, `^5.0`) for a WordPress plugin or theme, reading the GitHub token at runtime instead of from the code. Public repo, GPL-2.0-or-later. See README.md for usage.

## Rules

- **Backwards compatible within 1.x.** Several plugins on one site can ship different versions, and the first autoloader wins, so a config key is never removed or changed in meaning
- **`Updater::MISSING_TOKEN_GLOBAL` (`webdados_github_updates_missing_token`) is never renamed**, and its entry shape (`names`, `settings_url`, `settings_name`, optional `capability`) only gains optional keys: every copy and version of the class (including the hand-copied `Github_Updates` class that older Bioage plugin and theme releases still carry) shares it to merge the notice, and the first one to register renders it
- Use PUC only through `YahnisElsts\PluginUpdateChecker\v5\PucFactory`, never a `v5pN` namespace
- Nothing ACF-specific or project-specific: no field hiding, no settings screens
- WordPress coding standards (`composer lint`), except PSR-4 file names. PHP 7.4+
- Strings: text domain `wp-github-updates`. After changing one, update `languages/` (`.pot`, `pt_PT.po`, `.mo` and `.l10n.php`), Portuguese in AO90 European spelling
- Commit messages: short subject line, no body
