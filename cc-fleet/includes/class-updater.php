<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub: the CC Fleet plugin and the Community Connect theme.
 *
 * Each GitHub release carries two zips, named with their version:
 *   cc-fleet-0.8.2.zip            (folder cc-fleet/ inside)
 *   community-connect-1.0.2.zip   (folder community-connect/ inside)
 * WordPress then shows "Update available" for whichever is newer than what's installed,
 * and Update (or auto-updates) installs it the normal way.
 *
 * Settings: Fleet Manager → Updates (repository, and an access token for a private repository).
 */
class CC_Fleet_Updater {

	const OPT_REPO  = 'cc_fleet_gh_repo';
	const OPT_TOKEN = 'cc_fleet_gh_token';
	const CACHE     = 'cc_fleet_gh_release';
	const PLUGIN    = 'cc-fleet/cc-fleet.php';
	const THEME     = 'community-connect';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'plugins' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( __CLASS__, 'themes' ) );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_download' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
			add_action( 'admin_post_cc_fleet_updates', array( __CLASS__, 'handle' ) );
		}
	}

	public static function repo() {
		$r = trim( (string) get_option( self::OPT_REPO, '' ) );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $r ) ? $r : '';
	}

	private static function headers() {
		$h     = array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'cc-fleet-updater' );
		$token = (string) get_option( self::OPT_TOKEN, '' );
		if ( '' !== $token ) {
			$h['Authorization'] = 'Bearer ' . $token;
		}
		return $h;
	}

	/**
	 * The latest release, reduced to what we need. Cached for 6 hours (errors for 30 minutes).
	 *
	 * @return array{tag:string,url:string,notes:string,published:string,assets:array}|array{error:string}
	 */
	public static function release( $force = false ) {
		$repo = self::repo();
		if ( ! $repo ) {
			return array( 'error' => 'No repository set.' );
		}
		if ( ! $force ) {
			$c = get_site_transient( self::CACHE );
			if ( is_array( $c ) ) {
				return $c;
			}
		}
		$res  = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/latest', array( 'timeout' => 12, 'headers' => self::headers() ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$msg = is_wp_error( $res ) ? $res->get_error_message() : ( 404 === $code ? 'Repository or release not found (a private repository needs a token).' : 'GitHub answered ' . $code . '.' );
			$out = array( 'error' => $msg );
			set_site_transient( self::CACHE, $out, 30 * MINUTE_IN_SECONDS );
			return $out;
		}
		$j      = json_decode( wp_remote_retrieve_body( $res ), true );
		$assets = array();
		foreach ( (array) ( $j['assets'] ?? array() ) as $a ) {
			if ( preg_match( '/^(cc-fleet|community-connect)-(\d+(?:\.\d+)+)\.zip$/', (string) $a['name'], $m ) ) {
				$assets[ $m[1] ] = array(
					'version' => $m[2],
					// Private repositories need the API address (with the token); public ones can use the plain download.
					'package' => get_option( self::OPT_TOKEN ) ? $a['url'] : $a['browser_download_url'],
				);
			}
		}
		$out = array(
			'tag'       => (string) ( $j['tag_name'] ?? '' ),
			'url'       => (string) ( $j['html_url'] ?? '' ),
			'notes'     => (string) ( $j['body'] ?? '' ),
			'published' => (string) ( $j['published_at'] ?? '' ),
			'assets'    => $assets,
		);
		set_site_transient( self::CACHE, $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	private static function asset( $which ) {
		$r = self::release();
		return $r['assets'][ $which ] ?? null;
	}

	public static function plugins( $t ) {
		if ( ! is_object( $t ) || ! self::repo() ) {
			return $t;
		}
		$a = self::asset( 'cc-fleet' );
		if ( $a ) {
			$item = (object) array(
				'id'          => self::PLUGIN,
				'slug'        => 'cc-fleet',
				'plugin'      => self::PLUGIN,
				'new_version' => $a['version'],
				'url'         => 'https://github.com/' . self::repo(),
				'package'     => $a['package'],
				'icons'       => array(),
				'tested'      => get_bloginfo( 'version' ),
			);
			if ( version_compare( $a['version'], CC_FLEET_VERSION, '>' ) ) {
				$t->response[ self::PLUGIN ] = $item;
			} else {
				$t->no_update[ self::PLUGIN ] = $item; // Lets the auto-update toggle appear.
			}
		}
		return $t;
	}

	public static function themes( $t ) {
		if ( ! is_object( $t ) || ! self::repo() ) {
			return $t;
		}
		$a     = self::asset( 'community-connect' );
		$theme = wp_get_theme( self::THEME );
		if ( $a && $theme->exists() ) {
			$item = array(
				'theme'       => self::THEME,
				'new_version' => $a['version'],
				'url'         => 'https://github.com/' . self::repo(),
				'package'     => $a['package'],
			);
			if ( version_compare( $a['version'], $theme->get( 'Version' ), '>' ) ) {
				$t->response[ self::THEME ] = $item;
			} else {
				$t->no_update[ self::THEME ] = $item;
			}
		}
		return $t;
	}

	/** Adds the token (and the binary Accept header) when WordPress downloads a release asset of a private repository. */
	public static function auth_download( $args, $url ) {
		$repo = self::repo();
		if ( $repo && 0 === strpos( $url, 'https://api.github.com/repos/' . $repo . '/releases/assets/' ) ) {
			$args['headers']           = array_merge( (array) ( $args['headers'] ?? array() ), self::headers(), array( 'Accept' => 'application/octet-stream' ) );
			$args['reject_unsafe_urls'] = false;
		}
		return $args;
	}

	/** Makes sure the unpacked folder is called cc-fleet / community-connect, whatever the zip's top folder was. */
	public static function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		$want = '';
		if ( ( $extra['plugin'] ?? '' ) === self::PLUGIN ) {
			$want = 'cc-fleet';
		} elseif ( ( $extra['theme'] ?? '' ) === self::THEME ) {
			$want = self::THEME;
		}
		if ( ! $want || basename( untrailingslashit( $source ) ) === $want || ! $wp_filesystem ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . $want . '/';
		return $wp_filesystem->move( $source, $target, true ) ? $target : $source;
	}

	/** "View details" in the Plugins list: the release notes. */
	public static function details( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || 'cc-fleet' !== ( $args->slug ?? '' ) || ! self::repo() ) {
			return $res;
		}
		$r = self::release();
		$a = $r['assets']['cc-fleet'] ?? null;
		if ( ! $a ) {
			return $res;
		}
		return (object) array(
			'name'          => 'CC Fleet',
			'slug'          => 'cc-fleet',
			'version'       => $a['version'],
			'author'        => 'Community Connect NPC',
			'homepage'      => $r['url'],
			'download_link' => $a['package'],
			'last_updated'  => $r['published'],
			'sections'      => array( 'changelog' => wpautop( esc_html( $r['notes'] ?: 'Release ' . $r['tag'] ) ) ),
		);
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	/* ---------------- Settings page: Fleet Manager → Updates ---------------- */

	public static function menu() {
		add_submenu_page( 'cc-fleet', 'Updates', 'Updates', 'manage_options', 'cc-fleet-updates', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$repo  = (string) get_option( self::OPT_REPO, '' );
		$has   = '' !== (string) get_option( self::OPT_TOKEN, '' );
		$r     = $repo ? self::release() : null;
		$theme = wp_get_theme( self::THEME );
		echo '<div class="wrap"><h1>Updates from GitHub</h1>';
		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>Saved and checked.</p></div>';
		}
		echo '<p>New versions of the CC Fleet plugin and the Community Connect theme come from GitHub releases. When one is newer, it shows under Plugins and Appearance → Themes with an Update button, like any other plugin.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cc_fleet_updates' );
		echo '<input type="hidden" name="action" value="cc_fleet_updates"><table class="form-table" role="presentation">';
		printf( '<tr><th><label for="cc-repo">Repository</label></th><td><input id="cc-repo" name="repo" class="regular-text" value="%s" placeholder="owner/repository"><p class="description">For example <code>communityconnect/cc-website</code>.</p></td></tr>', esc_attr( $repo ) );
		printf( '<tr><th><label for="cc-token">Access token</label></th><td><input id="cc-token" name="token" type="password" class="regular-text" autocomplete="new-password" placeholder="%s"><p class="description">Only for a private repository: a GitHub fine-grained token with read-only <em>Contents</em> access to this one repository. Leave empty to keep the saved one.%s</p></td></tr>',
			$has ? '•••••••• saved' : 'not needed for a public repository',
			$has ? ' <label><input type="checkbox" name="clear_token" value="1"> Remove the saved token</label>' : '' );
		echo '</table>';
		submit_button( 'Save and check now' );
		echo '</form>';
		if ( $r ) {
			echo '<h2>Status</h2>';
			if ( isset( $r['error'] ) ) {
				echo '<p><strong>Could not reach the release:</strong> ' . esc_html( $r['error'] ) . '</p>';
			} else {
				$p = $r['assets']['cc-fleet']['version'] ?? '—';
				$t = $r['assets']['community-connect']['version'] ?? '—';
				echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th></th><th>Installed</th><th>Latest on GitHub</th></tr></thead><tbody>';
				printf( '<tr><td>CC Fleet plugin</td><td>%s</td><td>%s</td></tr>', esc_html( CC_FLEET_VERSION ), esc_html( $p ) );
				printf( '<tr><td>Community Connect theme</td><td>%s</td><td>%s</td></tr>', esc_html( $theme->exists() ? $theme->get( 'Version' ) : 'not installed' ), esc_html( $t ) );
				echo '</tbody></table>';
				printf( '<p>Release <a href="%s" target="_blank" rel="noopener">%s</a>. Install updates on the <a href="%s">Updates</a> screen, or turn on auto-updates under Plugins and Themes.</p>', esc_url( $r['url'] ), esc_html( $r['tag'] ), esc_url( admin_url( 'update-core.php' ) ) );
			}
		}
		echo '</div>';
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_updates' );
		$repo = trim( sanitize_text_field( wp_unslash( $_POST['repo'] ?? '' ) ) );
		$repo = preg_replace( '#^https?://github\.com/#', '', rtrim( $repo, '/' ) );
		$repo = preg_replace( '#\.git$#', '', $repo );
		update_option( self::OPT_REPO, $repo, false );
		$token = trim( (string) wp_unslash( $_POST['token'] ?? '' ) );
		if ( ! empty( $_POST['clear_token'] ) ) {
			delete_option( self::OPT_TOKEN );
		} elseif ( '' !== $token ) {
			update_option( self::OPT_TOKEN, preg_replace( '/[^A-Za-z0-9_]/', '', $token ), false );
		}
		self::flush();
		self::release( true );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		wp_update_plugins();
		wp_update_themes();
		wp_safe_redirect( admin_url( 'admin.php?page=cc-fleet-updates&done=1' ) );
		exit;
	}
}
