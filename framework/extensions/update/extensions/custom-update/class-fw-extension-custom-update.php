<?php defined( 'FW' ) or die();

/**
 * Custom server Update
 *
 * Add {'remote' => 'your_url'} to your manifest and this extension will handle it
 */
class FW_Extension_Custom_Update extends FW_Ext_Update_Service {

	/**
	 * How long to cache server responses
	 * @var int seconds
	 */
	private $transient_expiration = DAY_IN_SECONDS;

	private $download_timeout = 300;

	/**
	 * Used when there is internet connection problems
	 * To prevent site being blocked on every refresh, this fake version will be cached in the transient
	 * @var string
	 */
	private $fake_latest_version = '0.0.0';

	/**
	 * @internal
	 */
	protected function _init() {}

	/**
	 * @param $force_check
	 * @param $set - manifest settings.
	 *
	 * @return mixed|string|WP_Error
	 */
	private function get_latest_version( $force_check, $set ) {

		$transient_name = 'fw_ext_upd_gh_fw';
		$url            = $set['source'];

		if ( $force_check ) {
			delete_site_transient( $transient_name );

			$cache = array();
		} else {
			$cache = ( $c = get_site_transient( $transient_name ) ) && $c !== false ? $c : array();

			if ( isset( $cache[ $url ] ) ) {
				return $cache[ $url ];
			}
		}

		$latest_version = $this->fetch_latest_version( $set );

		if ( empty( $latest_version ) ) {
			return new WP_Error( 'fw_ext_update_failed_fetch_latest_version', sprintf( esc_html__( 'Empty version for extension: %s', 'fw' ), $set['name'] ) );
		}

		if ( is_wp_error( $latest_version ) ) {
			// Cache fake version to prevent requests to yourserver on every refresh.
			$cache = array_merge( $cache, array( $url => $this->fake_latest_version ) );

			// Show the error to the user because it is not visible elsewhere.
			FW_Flash_Messages::add( 'fw_ext_custom_update_error', $latest_version->get_error_message(), 'error' );

		} else {
			$cache = array_merge( $cache, array( $url => $latest_version ) );
		}

		set_site_transient( $transient_name, $cache, $this->transient_expiration );

		return $latest_version;
	}

	/**
	 * @param $set
	 *
	 * @return array|string|WP_Error
	 */
	private function fetch_latest_version( $set ) {
		/**
		 * If at least one request failed, do not do any other requests, to prevent site being blocked on every refresh.
		 * This may happen on localhost when develop your theme and you have no internet connection.
		 * Then this method will return a fake '0.0.0' version, it will be cached by the transient
		 * and will not bother you until the transient will expire, then a new request will be made.
		 * @var bool
		 */
		static $no_internet_connection = false;

		if ( $no_internet_connection ) {
			return $this->fake_latest_version;
		}

		$request = wp_remote_request(
			apply_filters( 'fw_custom_url_versions', $set['source'], $set ),
			array(
				'method'  => isset( $set['method'] ) ? $set['method'] : 'GET',
				'timeout' => $this->download_timeout,
				'body'    => $set
			)
		);

		if ( is_wp_error( $request ) ) {
			if ( $request->get_error_code() === 'http_request_failed' ) {
				$no_internet_connection = true;
			}

			return $request;
		}

		if ( ! ( $version = wp_remote_retrieve_body( $request ) ) || is_wp_error( $version ) ) {
			return ! $version ? new WP_Error( sprintf( __( 'Empty version for extension: %s', 'fw' ), $set['name'] ) ) : $version;
		}

		return $version;
	}

	/**
	 * @param array  $set - manifest keys.
	 * @param string $version Requested version to download
	 * @param string $wp_filesystem_download_directory Allocated temporary empty directory
	 * @param string $title Used in messages
	 *
	 * @return string|WP_Error Path to the downloaded directory
	 */
	private function download( $set, $version, $wp_filesystem_download_directory, $title ) {

		$error_id = 'fw_ext_update_custom_download_zip';
		$request = wp_remote_request(
			apply_filters( 'fw_custom_url_zip', esc_url( "{$set['source']}.{$version}.zip" ), $set ),
			array(
				'method'  => isset( $set['method'] ) ? $set['method'] : 'GET',
				'timeout' => $this->download_timeout,
				'body'    => $set
			)
		);

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		if ( ! ( $body = wp_remote_retrieve_body( $request ) ) || is_wp_error( $body ) ) {
			return ! $body ? new WP_Error( $error_id, sprintf( esc_html__( 'Empty zip body for item: %s', 'fw' ), $title ) ) : $body;
		}

		/** @var WP_Filesystem_Base $wp_filesystem */
		global $wp_filesystem;

		$zip_path = $wp_filesystem_download_directory . '/temp.zip';

		// save zip to file
		if ( ! $wp_filesystem->put_contents( $zip_path, $body ) ) {
			return new WP_Error( $error_id, sprintf( esc_html__( 'Cannot save %s zip.', 'fw' ), $title ) );
		}

		$unzip_result = unzip_file( FW_WP_Filesystem::filesystem_path_to_real_path( $zip_path ), $wp_filesystem_download_directory );

		if ( is_wp_error( $unzip_result ) ) {
			return $unzip_result;
		}

		// remove zip file
		if ( ! $wp_filesystem->delete( $zip_path, false, 'f' ) ) {
			return new WP_Error( $error_id, sprintf( esc_html__( 'Cannot remove %s zip.', 'fw' ), $title ) );
		}

		$unzipped_dir_files = $wp_filesystem->dirlist( $wp_filesystem_download_directory );

		if ( ! $unzipped_dir_files ) {
			return new WP_Error( $error_id, esc_html__( 'Cannot access the unzipped directory files.', 'fw' ) );
		}

		/**
		 * get first found directory
		 * (if everything worked well, there should be only one directory)
		 */
		foreach ( $unzipped_dir_files as $file ) {
			if ( $file['type'] == 'd' ) {
				return $wp_filesystem_download_directory . '/' . $file['name'];
			}
		}

		return new WP_Error( $error_id, sprintf( esc_html__( 'The unzipped %s directory not found.', 'fw' ), $title ) );
	}

	/**
	 * {@inheritdoc}
	 * @internal
	 */
	public function _get_framework_latest_version( $force_check ) {
		return false;
	}

	/**
	 * {@inheritdoc}
	 * @internal
	 */
	public function _get_theme_latest_version( $force_check ) {

		$manifest = $this->get_clean_theme_manifest();

		if ( empty( $manifest['remote'] ) ) {
			return false;
		}

		return $this->get_latest_version( $force_check, $manifest );
	}

	public function get_clean_theme_manifest() {

		if ( ! ( $manifest_file = fw_get_template_customizations_directory( '/theme/manifest.php' ) ) || ! is_file( $manifest_file ) ) {
			return array();
		}

		include $manifest_file;

		return isset( $manifest ) ? $manifest : array();
	}

	/**
	 * {@inheritdoc}
	 * @internal
	 */
	public function _download_theme( $version, $wp_filesystem_download_directory ) {
		return $this->download( $this->get_clean_theme_manifest(), $version, $wp_filesystem_download_directory, esc_html__( 'Theme', 'fw' ) );
	}

	/**
	 * {@inheritdoc}
	 * @internal
	 */
	public function _get_extension_latest_version( FW_Extension $extension, $force_check ) {

		if ( ! $extension->manifest->get( 'remote' ) || $extension->manifest->get( 'plugin' ) ) {
			return false;
		}

		return $this->get_latest_version( $force_check, $extension->manifest );
	}

	/**
	 * {@inheritdoc}
	 * @internal
	 */
	public function _download_extension( FW_Extension $extension, $version, $wp_filesystem_download_directory ) {
		return $this->download( $extension->manifest, $version, $wp_filesystem_download_directory, sprintf( esc_html__( '%s extension', 'fw' ), $extension->manifest->get_name() ) );
	}
}
