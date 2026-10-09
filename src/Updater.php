<?php
/**
 * Updates for a WordPress plugin or theme from the releases of a GitHub repository.
 *
 * @package Webdados\GitHubUpdates
 */

namespace Webdados\GitHubUpdates;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds Plugin Update Checker for one plugin or theme, with the access token read at runtime.
 *
 * Three ways to give it the token, so it never has to be in the code:
 * - token_option: the name of an option holding it (an ACF options page field is stored as
 *   "options_{field name}", a Settings API field under its own name). Values saved to it are
 *   checked, and anything that does not look like a GitHub token keeps the saved one.
 * - token: a string or a callable returning one (a constant, an array option, a filter...).
 * - neither: the repository is public and no token is used.
 *
 * When a token is expected but empty, no update checker is built (a private repository
 * answers 404 to anonymous requests) and an error is shown on every wp-admin screen. Projects
 * sharing one token_option share one notice, even across different versions of this package.
 */
class Updater {

	/**
	 * Global shared by every copy and version of this class, so one notice lists them all. Never rename it.
	 */
	const MISSING_TOKEN_GLOBAL = 'webdados_github_updates_missing_token';

	/**
	 * What a GitHub token looks like: fine-grained (github_pat_) or classic (ghp_).
	 */
	const TOKEN_PATTERN = '/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/';

	/**
	 * Text domain.
	 */
	const TEXT_DOMAIN = 'wp-github-updates';

	/**
	 * Configuration.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * The update checker, when one was built.
	 *
	 * @var \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\UpdateChecker|\YahnisElsts\PluginUpdateChecker\v5p7\Theme\UpdateChecker|null
	 */
	private $update_checker = null;

	/**
	 * Constructor. Call it when the plugin or theme loads, not on a later hook.
	 *
	 * @param array $config {
	 *     Configuration.
	 *
	 *     @type string          $repository     GitHub repository URL. Required.
	 *     @type string          $file           Main plugin file, or the theme's functions.php. Required.
	 *     @type string          $slug           Plugin or theme slug. Default: worked out by Plugin Update Checker.
	 *     @type string          $name           What stops getting updates, for the notice (e.g. "The Acme plugin"). Default: the slug.
	 *     @type string          $token_option   Option holding the token. Also validates what is saved to it.
	 *     @type string|callable $token          The token, or a callable returning it. Used when token_option is empty.
	 *     @type string          $settings_url   Where the token is set, relative to wp-admin, linked from the notice.
	 *     @type string          $settings_name  Name of that screen, for the link.
	 *     @type bool|string     $release_assets Download the release's attached zip (true), the asset matching this regex, or the source zip (false). Default true.
	 *     @type string          $capability     Who sees the missing token notice. Default "manage_options".
	 *     @type bool|callable   $prereleases    Also offer GitHub pre-releases (true), or a callable returning whether to. Default false.
	 * }
	 */
	public function __construct( $config ) {
		$this->config = wp_parse_args(
			$config,
			array(
				'repository'     => '',
				'file'           => '',
				'slug'           => '',
				'name'           => '',
				'token_option'   => '',
				'token'          => '',
				'settings_url'   => '',
				'settings_name'  => '',
				'release_assets' => true,
				'capability'     => 'manage_options',
				'prereleases'    => false,
			)
		);
		if ( '' === $this->config['name'] ) {
			$this->config['name'] = '' !== $this->config['slug'] ? $this->config['slug'] : basename( dirname( $this->config['file'] ) );
		}
		if ( '' !== $this->config['token_option'] ) {
			add_filter( 'pre_update_option_' . $this->config['token_option'], array( $this, 'keep_token_unless_valid' ), 10, 2 );
		}
		if ( ! $this->expects_token() ) {
			$this->build_update_checker( '' );
			return;
		}
		$token = $this->get_token();
		if ( '' !== $token ) {
			$this->build_update_checker( $token );
		} else {
			$this->register_missing_token();
		}
	}

	/**
	 * The update checker, to customise it further, or null when it was not built for lack of a token.
	 *
	 * @return \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\UpdateChecker|\YahnisElsts\PluginUpdateChecker\v5p7\Theme\UpdateChecker|null
	 */
	public function get_update_checker() {
		return $this->update_checker;
	}

	/**
	 * Whether a token is expected, i.e. the repository is private.
	 *
	 * @return bool
	 */
	private function expects_token() {
		return '' !== $this->config['token_option'] || '' !== $this->config['token'];
	}

	/**
	 * The token, from the option, or from the token argument.
	 *
	 * @return string
	 */
	private function get_token() {
		if ( '' !== $this->config['token_option'] ) {
			$token = get_option( $this->config['token_option'], '' );
		} elseif ( is_callable( $this->config['token'] ) ) {
			$token = call_user_func( $this->config['token'] );
		} else {
			$token = $this->config['token'];
		}
		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Build the update checker, from GitHub releases.
	 *
	 * @param string $token GitHub access token, empty for a public repository.
	 * @return void
	 */
	private function build_update_checker( $token ) {
		$this->update_checker = PucFactory::buildUpdateChecker(
			$this->config['repository'],
			$this->config['file'],
			$this->config['slug']
		);
		if ( false !== $this->config['release_assets'] ) {
			if ( is_string( $this->config['release_assets'] ) ) {
				$this->update_checker->getVcsApi()->enableReleaseAssets( $this->config['release_assets'] );
			} else {
				$this->update_checker->getVcsApi()->enableReleaseAssets();
			}
		}
		if ( $this->wants_prereleases() ) {
			$vcs_api = $this->update_checker->getVcsApi();
			// The release filter is not in every VCS API PUC supports (Bitbucket has no releases).
			if ( method_exists( $vcs_api, 'setReleaseFilter' ) ) {
				// RELEASE_FILTER_ALL read through the API's own class, so no v5pN namespace is named here.
				$vcs_api->setReleaseFilter(
					'__return_true',
					constant( get_class( $vcs_api ) . '::RELEASE_FILTER_ALL' )
				);
			}
		}
		if ( '' !== $token ) {
			$this->update_checker->setAuthentication( $token );
		}
	}

	/**
	 * Whether pre-releases are offered, from the prereleases setting or the callable in it.
	 *
	 * @return bool
	 */
	private function wants_prereleases() {
		if ( is_callable( $this->config['prereleases'] ) ) {
			return (bool) call_user_func( $this->config['prereleases'] );
		}
		return true === $this->config['prereleases'];
	}

	/**
	 * Keep the saved token unless the new value looks like a GitHub token.
	 *
	 * Runs on every update_option() of token_option, whatever saves it (ACF, the Settings API,
	 * WP-CLI). Mostly against browsers filling a password field with the user's WordPress
	 * password, which would be saved as the token and stop updates without any sign. An empty
	 * value keeps the token too: clearing it on purpose is delete_option().
	 *
	 * @param mixed $value     New value.
	 * @param mixed $old_value Saved value.
	 * @return mixed
	 */
	public function keep_token_unless_valid( $value, $old_value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( self::TOKEN_PATTERN, $value ) ) {
			return $value;
		}
		return $old_value;
	}

	/**
	 * Add this project to the missing token notice, and hook the notice once for every copy of this class.
	 *
	 * @return void
	 */
	private function register_missing_token() {
		if ( ! isset( $GLOBALS[ self::MISSING_TOKEN_GLOBAL ] ) ) {
			$GLOBALS[ self::MISSING_TOKEN_GLOBAL ] = array();
			add_action( 'admin_notices', array( $this, 'output_missing_token_notice' ) );
		}
		// Grouped by where the token comes from, so projects sharing one get one notice.
		$key = '' !== $this->config['token_option'] ? $this->config['token_option'] : 'token:' . $this->config['file'];
		if ( ! isset( $GLOBALS[ self::MISSING_TOKEN_GLOBAL ][ $key ] ) ) {
			$GLOBALS[ self::MISSING_TOKEN_GLOBAL ][ $key ] = array(
				'names'         => array(),
				'settings_url'  => $this->config['settings_url'],
				'settings_name' => $this->config['settings_name'],
				'capability'    => $this->config['capability'],
			);
		}
		$GLOBALS[ self::MISSING_TOKEN_GLOBAL ][ $key ]['names'][] = $this->config['name'];
	}

	/**
	 * Output the missing token notice, one per token source, on every wp-admin screen.
	 *
	 * @return void
	 */
	public function output_missing_token_notice() {
		if ( empty( $GLOBALS[ self::MISSING_TOKEN_GLOBAL ] ) ) {
			return;
		}
		$this->load_textdomain();
		foreach ( $GLOBALS[ self::MISSING_TOKEN_GLOBAL ] as $missing ) {
			// Entries added by copies of this class older than the capability setting.
			$capability = isset( $missing['capability'] ) ? $missing['capability'] : 'manage_options';
			if ( ! current_user_can( $capability ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined
				continue;
			}
			$message = sprintf(
				/* translators: %s: list of what is not getting updates, e.g. "The Acme plugin and the Acme theme" */
				_n(
					'%s is not getting updates because its GitHub access token is not set.',
					'%s are not getting updates because their GitHub access token is not set.',
					count( $missing['names'] ),
					'wp-github-updates'
				),
				ucfirst( wp_sprintf( '%l', $missing['names'] ) )
			);
			$link = '';
			if ( '' !== $missing['settings_url'] ) {
				$link = ' ' . sprintf(
					/* translators: %s: link to the screen where the token is set */
					__( 'Set it in %s.', 'wp-github-updates' ),
					sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( admin_url( $missing['settings_url'] ) ),
						esc_html( '' !== $missing['settings_name'] ? $missing['settings_name'] : __( 'the settings', 'wp-github-updates' ) )
					)
				);
			}
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s%3$s</p></div>',
				esc_html__( 'No updates:', 'wp-github-updates' ),
				esc_html( $message ),
				wp_kses( $link, array( 'a' => array( 'href' => array() ) ) )
			);
		}
	}

	/**
	 * Load the package's own translations, from languages/ next to src/.
	 *
	 * @return void
	 */
	private function load_textdomain() {
		if ( is_textdomain_loaded( self::TEXT_DOMAIN ) ) {
			return;
		}
		load_textdomain( self::TEXT_DOMAIN, dirname( __DIR__ ) . '/languages/' . self::TEXT_DOMAIN . '-' . determine_locale() . '.mo' );
	}
}
