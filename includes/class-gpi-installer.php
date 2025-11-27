<?php
/**
 * GitHub Plugin Installer main class.
 *
 * @package GitHubPluginInstaller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main installer controller.
 */
class GPI_Installer {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'github-plugin-installer';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	private $nonce_action = 'gpi_install_plugin';

	/**
	 * Option name for repository mappings.
	 *
	 * @var string
	 */
	private $option_key = 'gpi_repo_map';

	/**
	 * Initialise hooks.
	 *
	 * @return void
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_post_gpi_install', array( $this, 'handle_install_request' ) );
			add_action( 'admin_post_gpi_save_token', array( $this, 'handle_token_save' ) );
			add_action( 'admin_post_gpi_update', array( $this, 'handle_update_request' ) );
			add_filter( 'plugin_action_links', array( $this, 'add_quick_update_action' ), 10, 4 );
		}
	}

	/**
	 * Register the admin page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_plugins_page(
			__( 'GitHub Plugin Installer', 'github-plugin-installer' ),
			__( 'GitHub Installer', 'github-plugin-installer' ),
			'install_plugins',
			$this->page_slug,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin interface.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		$nonce   = wp_create_nonce( $this->nonce_action );
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
		$token   = get_option( 'gpi_github_token', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Install Plugin from GitHub', 'github-plugin-installer' ); ?></h1>
			<?php if ( $status && $message ) : ?>
				<div class="<?php echo esc_attr( $this->get_notice_class( $status ) ); ?>">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'GitHub Access Token (optional)', 'github-plugin-installer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'gpi_save_token', '_gpi_token_nonce', true, true ); ?>
				<input type="hidden" name="action" value="gpi_save_token">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="gpi_token"><?php esc_html_e( 'Personal access token', 'github-plugin-installer' ); ?></label>
							</th>
							<td>
								<input type="password" class="regular-text" id="gpi_token" name="gpi_token" value="<?php echo esc_attr( $token ); ?>">
								<p class="description">
									<?php
									printf(
										/* translators: %s: GitHub documentation URL. */
										wp_kses(
											__( 'Needed only for private repositories or higher rate limits. <a href="%s" target="_blank" rel="noopener noreferrer">How to create a token</a>. Leave blank and save to remove.', 'github-plugin-installer' ),
											array(
												'a' => array(
													'href'   => array(),
													'target' => array(),
													'rel'    => array(),
												),
											)
										),
										esc_url( 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/creating-a-personal-access-token' )
									);
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Token', 'github-plugin-installer' ) ); ?>
			</form>

			<hr>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( $this->nonce_action, '_wpnonce', true, true ); ?>
				<input type="hidden" name="action" value="gpi_install">

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="gpi_repo"><?php esc_html_e( 'Repository (owner/repo)', 'github-plugin-installer' ); ?></label>
							</th>
							<td>
								<input type="text" class="regular-text" id="gpi_repo" name="repo" required placeholder="owner/repository">
								<p class="description"><?php esc_html_e( 'Example: wordpress/wordpress-importer', 'github-plugin-installer' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gpi_ref"><?php esc_html_e( 'Branch, tag, or commit', 'github-plugin-installer' ); ?></label>
							</th>
							<td>
								<input type="text" class="regular-text" id="gpi_ref" name="ref" placeholder="main">
								<p class="description"><?php esc_html_e( 'Defaults to the repository default branch when empty.', 'github-plugin-installer' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Install Plugin', 'github-plugin-installer' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle installation POST.
	 *
	 * @return void
	 */
	public function handle_install_request() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'github-plugin-installer' ) );
		}

		check_admin_referer( $this->nonce_action );

		$repo = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$ref  = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';

		if ( empty( $repo ) ) {
			$this->redirect_with_message( 'invalid_repo', __( 'Repository field cannot be empty.', 'github-plugin-installer' ) );
		}

		$parsed = $this->parse_repository_input( $repo );

		if ( is_wp_error( $parsed ) ) {
			$this->redirect_with_message( 'invalid_repo', $parsed->get_error_message() );
		}

		list( $owner, $repository ) = $parsed;

		$result = $this->install_from_github( $owner, $repository, $ref );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'error', $result->get_error_message() );
		}

		$this->save_repository_mapping( $result, $owner, $repository );

		/* translators: %s: plugin folder name. */
		$message = sprintf( __( 'Plugin installed successfully into folder %s.', 'github-plugin-installer' ), $result );
		$this->redirect_with_message( 'success', $message );
	}

	/**
	 * Install plugin from GitHub archive.
	 *
	 * @param string $owner GitHub username or organization.
	 * @param string $repo Repository name.
	 * @param string $ref Branch, tag, or commit hash.
	 *
	 * @return string|\WP_Error Installed plugin folder name or error.
	 */
	private function install_from_github( $owner, $repo, $ref, $overwrite = false ) {
		$destination  = WP_PLUGIN_DIR;
		$target_path  = trailingslashit( $destination ) . $repo;
		$existing_dir = $this->plugin_directory_exists( $repo );

		if ( $existing_dir && ! $overwrite ) {
			return new WP_Error(
				'folder_exists',
				sprintf(
					/* translators: %s: plugin folder name. */
					__( 'The folder %s already exists. Plugin appears to be installed.', 'github-plugin-installer' ),
					$repo
				)
			);
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		if ( ! $overwrite ) {
			foreach ( $plugins as $path => $data ) {
				if ( 0 === strpos( $path, trailingslashit( $repo ) ) ) {
					return new WP_Error(
						'plugin_registered',
						sprintf(
							/* translators: %s: plugin folder name. */
							__( 'Plugin %s is already registered in WordPress.', 'github-plugin-installer' ),
							$repo
						)
					);
				}
			}
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'missing_zip', __( 'PHP ZipArchive extension is required.', 'github-plugin-installer' ) );
		}

		$url = $this->build_archive_url( $owner, $repo, $ref );

		$args = apply_filters(
			'gpi_github_request_args',
			array(
				'user-agent' => 'WordPress-GitHub-Installer',
				'timeout'    => 60,
			),
			$owner,
			$repo,
			$ref
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'github_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'GitHub returned HTTP %d. Make sure the repository exists and is public.', 'github-plugin-installer' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'empty_body', __( 'GitHub response is empty.', 'github-plugin-installer' ) );
		}

		$tmp_file = wp_tempnam( $repo . '.zip' );
		if ( ! $tmp_file ) {
			return new WP_Error( 'tmp_error', __( 'Could not create a temporary file.', 'github-plugin-installer' ) );
		}

		file_put_contents( $tmp_file, $body );

		if ( ! file_exists( $tmp_file ) ) {
			return new WP_Error( 'download_failed', __( 'Failed to persist the downloaded archive.', 'github-plugin-installer' ) );
		}

		$existing_dirs = $this->list_plugin_directories( $destination );

		// Prepare WP_Filesystem.
		$credentials = request_filesystem_credentials( admin_url( 'plugin-install.php' ), '', false, $destination );

		if ( false === $credentials ) {
			unlink( $tmp_file );
			return new WP_Error( 'fs_credentials', __( 'Filesystem credentials are required to install plugins.', 'github-plugin-installer' ) );
		}

		if ( ! WP_Filesystem( $credentials ) ) {
			unlink( $tmp_file );
			return new WP_Error( 'fs_init', __( 'Unable to initialize WordPress filesystem.', 'github-plugin-installer' ) );
		}

		global $wp_filesystem;

		$unzip = unzip_file( $tmp_file, $destination );

		unlink( $tmp_file );

		if ( is_wp_error( $unzip ) ) {
			return $unzip;
		}

		$after_dirs = $this->list_plugin_directories( $destination );

		$created_dirs = array_values( array_diff( $after_dirs, $existing_dirs ) );

		$extracted_folder = $this->detect_extracted_folder( $created_dirs, $owner, $repo );

		if ( is_wp_error( $extracted_folder ) ) {
			return $extracted_folder;
		}

		if ( $wp_filesystem->exists( $target_path ) ) {
			if ( $overwrite ) {
				$wp_filesystem->delete( $target_path, true );
			} else {
				$wp_filesystem->delete( trailingslashit( $destination ) . $extracted_folder, true );

				return new WP_Error(
					'folder_exists',
					sprintf(
						/* translators: %s: plugin folder name. */
						__( 'The folder %s already exists.', 'github-plugin-installer' ),
						$repo
					)
				);
			}
		}

		$wp_filesystem->move( trailingslashit( $destination ) . $extracted_folder, $target_path );

		return $repo;
	}

	/**
	 * Build GitHub archive URL.
	 *
	 * @param string $owner Owner name.
	 * @param string $repo Repo.
	 * @param string $ref Reference.
	 *
	 * @return string
	 */
	private function build_archive_url( $owner, $repo, $ref ) {
		$ref = $ref ? $ref : 'HEAD';
		return sprintf( 'https://api.github.com/repos/%1$s/%2$s/zipball/%3$s', rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $ref ) );
	}

	/**
	 * Find folder created by unzip operation.
	 *
	 * @param string $destination Destination path.
	 * @param string $owner Owner to match.
	 * @param string $repo Repo to match.
	 *
	 * @return string|\WP_Error
	 */
	private function detect_extracted_folder( $created_dirs, $owner, $repo ) {
		if ( empty( $created_dirs ) ) {
			return new WP_Error( 'folder_not_found', __( 'Could not determine the extracted folder name.', 'github-plugin-installer' ) );
		}

		$pattern = sprintf( '/%s.*%s/i', preg_quote( $owner, '/' ), preg_quote( $repo, '/' ) );

		foreach ( $created_dirs as $dir ) {
			if ( preg_match( $pattern, $dir ) ) {
				return $dir;
			}
		}

		// Fall back to the first created directory.
		return $created_dirs[0];
	}

	/**
	 * List directories in plugin folder.
	 *
	 * @param string $destination Path to plugins directory.
	 *
	 * @return array
	 */
	private function list_plugin_directories( $destination ) {
		$directories = array();
		$items       = @scandir( $destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $items ) {
			return $directories;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			if ( is_dir( trailingslashit( $destination ) . $item ) ) {
				$directories[] = $item;
			}
		}

		return $directories;
	}

	/**
	 * Check if plugin directory already exists.
	 *
	 * @param string $repo Repository (plugin) slug.
	 *
	 * @return bool
	 */
	private function plugin_directory_exists( $repo ) {
		return is_dir( trailingslashit( WP_PLUGIN_DIR ) . $repo );
	}

	/**
	 * Normalize repository input into owner/repo pair.
	 *
	 * @param string $input Raw repository input.
	 *
	 * @return array|\WP_Error
	 */
	private function parse_repository_input( $input ) {
		$input = trim( $input );

		if ( '' === $input ) {
			return new WP_Error( 'empty_repo', __( 'Repository value is empty.', 'github-plugin-installer' ) );
		}

		// Remove .git suffix if present with or without trailing slash.
		$input = preg_replace( '#(\.git)?/?$#', '', $input );

		// Handle common Git URL formats.
		$patterns = array(
			'#^git@github\.com:(?P<owner>[^/]+)/(?P<repo>[^/]+)$#i',
			'#^https?://github\.com/(?P<owner>[^/]+)/(?P<repo>[^/]+)$#i',
			'#^(?P<owner>[^/]+)/(?P<repo>[^/]+)$#',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $input, $matches ) ) {
				$owner = sanitize_key( $matches['owner'] );
				$repo  = sanitize_key( $matches['repo'] );

				if ( empty( $owner ) || empty( $repo ) ) {
					break;
				}

				return array( $owner, $repo );
			}
		}

		return new WP_Error(
			'invalid_repo_format',
			__( 'Unable to parse repository. Use owner/repo or a GitHub URL.', 'github-plugin-installer' )
		);
	}

	/**
	 * Persist mapping between plugin slug and GitHub repository.
	 *
	 * @param string $slug  Plugin slug/folder.
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 *
	 * @return void
	 */
	private function save_repository_mapping( $slug, $owner, $repo ) {
		$map           = get_option( $this->option_key, array() );
		$map[ $slug ]  = array(
			'owner' => $owner,
			'repo'  => $repo,
		);

		update_option( $this->option_key, $map, false );
	}

	/**
	 * Retrieve repository mapping for given plugin slug.
	 *
	 * @param string $slug Plugin slug.
	 *
	 * @return array|null
	 */
	private function get_repository_mapping( $slug ) {
		$map = get_option( $this->option_key, array() );

		if ( isset( $map[ $slug ] ) && isset( $map[ $slug ]['owner'], $map[ $slug ]['repo'] ) ) {
			return $map[ $slug ];
		}

		return null;
	}

	/**
	 * Handle update request from plugins page.
	 *
	 * @return void
	 */
	public function handle_update_request() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to update plugins.', 'github-plugin-installer' ) );
		}

		$plugin = isset( $_GET['plugin'] ) ? sanitize_key( wp_unslash( $_GET['plugin'] ) ) : '';

		if ( empty( $plugin ) ) {
			$this->redirect_with_message( 'error', __( 'Missing plugin identifier.', 'github-plugin-installer' ), false );
		}

		check_admin_referer( 'gpi_update_plugin_' . $plugin );

		$mapping = $this->get_repository_mapping( $plugin );

		if ( ! $mapping ) {
			$this->redirect_with_message(
				'error',
				__( 'No GitHub repository is associated with this plugin. Reinstall via GitHub Installer to set it up.', 'github-plugin-installer' ),
				false
			);
		}

		$ref = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '';

		$result = $this->install_from_github( $mapping['owner'], $mapping['repo'], $ref, true );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'error', $result->get_error_message(), false );
		}

		/* translators: %s: plugin folder name. */
		$message = sprintf( __( 'Plugin %s updated from GitHub.', 'github-plugin-installer' ), $plugin );
		$this->redirect_with_message( 'success', $message, false );
	}

	/**
	 * Add quick update action link on plugins screen.
	 *
	 * @param array  $actions     Existing action links.
	 * @param string $plugin_file Plugin file path.
	 * @param array  $plugin_data Plugin data.
	 * @param string $context     Context.
	 *
	 * @return array
	 */
	public function add_quick_update_action( $actions, $plugin_file, $plugin_data, $context ) {
		$slug = $this->extract_plugin_slug( $plugin_file );

		if ( ! $slug ) {
			return $actions;
		}

		$mapping = $this->get_repository_mapping( $slug );

		if ( ! $mapping ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'gpi_update',
					'plugin' => $slug,
				),
				admin_url( 'admin-post.php' )
			),
			'gpi_update_plugin_' . $slug
		);

		$actions['gpi_update'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Update from GitHub', 'github-plugin-installer' )
		);

		return $actions;
	}

	/**
	 * Extract plugin slug from plugin file reference.
	 *
	 * @param string $plugin_file Plugin file path.
	 *
	 * @return string|false
	 */
	private function extract_plugin_slug( $plugin_file ) {
		if ( false !== strpos( $plugin_file, '/' ) ) {
			$parts = explode( '/', $plugin_file );
			return sanitize_key( $parts[0] );
		}

		// Single file plugin.
		$slug = basename( $plugin_file, '.php' );

		return $slug ? sanitize_key( $slug ) : false;
	}

	/**
	 * Redirect to the admin page with a message.
	 *
	 * @param string $status Message status.
	 * @param string $message Message text.
	 *
	 * @return void
	 */
	private function redirect_with_message( $status, $message, $include_page = true ) {
		$args = array(
			'status'  => $status,
			'message' => rawurlencode( $message ),
		);

		if ( $include_page ) {
			$args['page'] = $this->page_slug;
		}

		$redirect_url = add_query_arg( $args, admin_url( 'plugins.php' ) );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle saving of GitHub token.
	 *
	 * @return void
	 */
	public function handle_token_save() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this setting.', 'github-plugin-installer' ) );
		}

		check_admin_referer( 'gpi_save_token', '_gpi_token_nonce' );

		$token = isset( $_POST['gpi_token'] ) ? sanitize_text_field( wp_unslash( $_POST['gpi_token'] ) ) : '';

		if ( empty( $token ) ) {
			delete_option( 'gpi_github_token' );
			$this->redirect_with_message( 'success', __( 'GitHub token removed.', 'github-plugin-installer' ) );
		}

		update_option( 'gpi_github_token', $token );
		$this->redirect_with_message( 'success', __( 'GitHub token saved.', 'github-plugin-installer' ) );
	}

	/**
	 * Map status to WordPress notice classes.
	 *
	 * @param string $status Status from query string.
	 *
	 * @return string
	 */
	private function get_notice_class( $status ) {
		switch ( $status ) {
			case 'success':
				return 'notice notice-success';
			case 'error':
				return 'notice notice-error';
			default:
				return 'notice notice-info';
		}
	}
}

