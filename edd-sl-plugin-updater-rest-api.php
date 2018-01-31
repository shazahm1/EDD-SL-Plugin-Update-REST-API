<?php

/*
Plugin Name: EDD SL Plugin Update REST API
Plugin URI: http://connections-pro.com
Description: Adds a REST API Endpoint to EDD-SL for Plugin Update Request.
Version: 1.0
Author: Steven A. Zahm
Author URI: http://connections-pro.com
License: GPL2
*/

class cnPlugin_Updater_REST_API {

	public function __construct() {

		$this->init();
	}

	/**
	 * Init REST API.
	 *
	 * @access private
	 * @since  8.5.26
	 */
	public function init() {

		// REST API was included starting WordPress 4.4.
		if ( ! class_exists( 'WP_REST_Server' ) ) {
			return;
		}

		// Init REST API routes.
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ), 10 );
	}

	/**
	 * Register REST API routes.
	 *
	 * @access private
	 * @since  8.5.26
	 */
	public function registerRoutes() {

		$controllers = array(
			'CN_Plugin_Updater_Controller',
			'CN_License_Status_Controller',
		);

		foreach ( $controllers as $controller ) {

			$this->$controller = new $controller();
			$this->$controller->register_routes();
		}
	}
}

/**
 * EDD-SL REST API Plugin Updater Controller.
 *
 * @package Connections/Plugin Updater
 * @extends WP_REST_Controller
 */
class CN_Plugin_Updater_Controller extends WP_REST_Controller {

	/**
	 * @since 1.0
	 */
	const VERSION = '1';

	/**
	 * @since 1.0
	 * @var string
	 */
	protected $namespace;

	/**
	 * @since 1.0
	 */
	public function __construct() {

		$this->namespace = 'cn-plugin/v' . self::VERSION;
	}

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0
	 */
	public function register_routes() {

		register_rest_route(
			$this->namespace,
			'/update-check',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_items' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/info',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_item' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Get a collection of plugin update info.
	 *
	 * @since 1.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {

		// Ensure EDD-SL exists before proceeding.
		if ( ! function_exists( 'edd_software_licensing' ) ) {

			return new WP_Error( 'edd_sl_not_found', 'EDD-SL not found.', $request );
		}

		//if ( ! isset( $request['action'] ) ) {
		//
		//	return new WP_Error( 'no_action', 'Request requires the action parameter `info` or `check-update`.', $request );
		//}

		$response = array();

		if ( isset( $request['plugins'] ) ) {

			$plugins = cnFormatting::maybeJSONdecode( $request['plugins'] );

			if ( is_array( $plugins ) ) {

				foreach ( $plugins as $basename => $plugin ) {

					$plugin['url'] = isset( $request['url'] ) ? $request['url'] : '';

					$data = $this->prepare_item_for_response( $plugin, $request );

					if ( ! is_wp_error( $data ) ) {

						$response[] = $this->prepare_response_for_collection( $data );
					}
				}
			}

		}

		$response = rest_ensure_response( $response );

		return $response;
	}

	/**
	 * Get one item from the collection
	 *
	 * @since 1.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_item( $request ) {

		// Ensure EDD-SL exists before proceeding.
		if ( ! function_exists( 'edd_software_licensing' ) ) {

			return new WP_Error( 'edd_sl_not_found', 'EDD-SL not found.', $request );
		}

		if ( ! isset( $request['action'] ) ) {

			return new WP_Error( 'no_action', 'Request requires the action parameter `info` or `check-update`.', $request );
		}

		$response = array();

		if ( isset( $request['plugins'] ) ) {

			$plugin = cnFormatting::maybeJSONdecode( $request['plugins'] );

			$plugin['url'] = isset( $request['url'] ) ? $request['url'] : '';

			$data = $this->prepare_item_for_response( $plugin, $request );

			if ( ! is_wp_error( $data ) ) {

				$response = $data;
			}

		}

		return rest_ensure_response( $response );
	}

	/**
	 * Prepare the item for the REST response
	 *
	 * Based on @see edd_software_licensing::get_latest_version_remote()
	 *
	 * @since 1.0
	 *
	 * @param mixed           $item    WordPress representation of the item.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return mixed
	 */
	public function prepare_item_for_response( $item, $request ) {

		$edd_sl = edd_software_licensing();

		$url       = isset( $item['url'] )       ? sanitize_text_field( $item['url'] )       : FALSE;
		$license   = isset( $item['license'] )   ? sanitize_text_field( $item['license'] )   : FALSE;
		$slug      = isset( $item['slug'] )      ? sanitize_text_field( $item['slug'] )      : FALSE;
		$item_id   = isset( $item['item_id'] )   ? absint( $item['item_id'] )                : FALSE;
		$item_name = isset( $item['item_name'] ) ? sanitize_text_field( $item['item_name'] ) : FALSE;
		$beta      = isset( $item['beta'] )      ? (bool) $item['beta']                      : FALSE;

		if ( empty( $item_id ) && empty( $item_name ) && ( ! defined( 'EDD_BYPASS_NAME_CHECK' ) || ! EDD_BYPASS_NAME_CHECK ) ) {

			return new WP_Error( 'item_id_or_name_not_provided', 'Item name or ID is required.', $item );
		}

		if ( empty( $item_id ) ) {

			if ( empty( $license ) && empty( $item_name ) ) {

				return new WP_Error( 'item_license_or_name_not_provided', 'Item licensee or name is required.', $item );
			}

			if ( empty( $license ) ) {

				$item_id = $edd_sl->get_download_id_by_name( $item_name );

			} else {

				$item_id = $edd_sl->get_download_id_by_license( $license );

				// Requested item name does not match the requested license.
				if ( ( ! defined( 'EDD_BYPASS_NAME_CHECK' ) || ! EDD_BYPASS_NAME_CHECK ) && ! $edd_sl->check_item_name( $item_id, $item_name ) ) {

					//return new WP_Error( 'item_name_mismatch', 'License entered is not for this item.', $item );
					$item_id = $edd_sl->get_download_id_by_name( $item_name );
				}
			}

		}

		$download = new EDD_SL_Download( $item_id );

		if ( ! $download ) {

			return new WP_Error(
				'item_not_found',
				sprintf( 'Requested item does not match a valid %s', edd_get_label_singular() ),
				$item );
		}

		$stable_version = $version = $edd_sl->get_latest_version( $item_id );
		$slug           = ! empty( $slug ) ? $slug : $download->post_name;
		$description    = ! empty( $download->post_excerpt ) ? $download->post_excerpt : $download->post_content;
		$changelog      = get_post_meta( $item_id, '_edd_sl_changelog', TRUE );

		$beta_enabled  = (bool) get_post_meta( $item_id, '_edd_sl_beta_enabled', TRUE );
		$download_beta = FALSE;

		if ( $beta && $beta_enabled ) {

			$version_beta = $edd_sl->get_beta_download_version( $item_id );

			if ( version_compare( $version_beta, $stable_version, '>' ) ) {

				$changelog     = get_post_meta( $item_id, '_edd_sl_beta_changelog', TRUE );
				$version       = $version_beta;
				$download_beta = TRUE;
			}
		}

		$data = array(
			'id'            => $item_id,
			'slug'          => $slug,
			'plugin'        => $item['basename'],
			'new_version'   => $version,
			'url'           => esc_url( get_permalink( $item_id ) ),
			'package'       => $edd_sl->get_encoded_download_package_url( $item_id, $license, $url, $download_beta ),
		);

		if ( 'info' === $request['action'] ) {

			$info = array(
				'name'          => $download->post_title,
				'last_updated'  => $download->post_modified,
				'homepage'      => get_permalink( $item_id ),
				'download_link' => $data['package'],
				'sections'      => serialize(
					array(
						'description' => wpautop( strip_shortcodes( strip_tags( $description, '<p><li><ul><ol><strong><a><em><span><br>' ) ) ),
						'changelog'   => wpautop( strip_tags( stripslashes( $changelog ), '<p><li><ul><ol><strong><a><em><span><br>' ) ),
					)
				),
				'banners' => serialize(
					array(
						'high' => get_post_meta( $item_id, '_edd_readme_plugin_banner_high', true ),
						'low'  => get_post_meta( $item_id, '_edd_readme_plugin_banner_low', true )
					)
				)
			);

			$data = array_merge( $data, $info );
		}

		$response = apply_filters( 'edd_sl_license_response', $data, $download, $download_beta );
		$response = rest_ensure_response( $response );

		return rest_ensure_response( $response );
	}

	/**
	 * Get the query params for collections
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function get_collection_params() {

		$query_params = array();

		$query_params['url']    = array(
			'description'        => 'The site requesting a plugin license check.',
			'type'               => 'string',
			'validate_callback'  => 'rest_validate_request_arg',
		);

		return $query_params;
	}
}

/**
 * EDD-SL REST API License Status Controller.
 *
 * @package Connections/Plugin Updater
 * @extends WP_REST_Controller
 */
class CN_License_Status_Controller extends WP_REST_Controller {

	/**
	 * @since 1.0
	 */
	const VERSION = '1';

	/**
	 * @since 1.0
	 * @var string
	 */
	protected $namespace;

	/**
	 * @since 1.0
	 */
	public function __construct() {

		$this->namespace = 'cn-plugin/v' . self::VERSION;
	}

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0
	 */
	public function register_routes() {

		register_rest_route(
			$this->namespace,
			'/status',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_items' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/item-status',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_item' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Get a collection of plugin status info.
	 *
	 * @since 1.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {

		// Ensure EDD-SL exists before proceeding.
		if ( ! function_exists( 'edd_software_licensing' ) ) {

			return new WP_Error( 'edd_sl_not_found', 'EDD-SL not found.', $request );
		}

		$response = array();

		if ( isset( $request['plugins'] ) ) {

			$plugins = cnFormatting::maybeJSONdecode( $request['plugins'] );

			if ( is_array( $plugins ) ) {

				foreach ( $plugins as $basename => $plugin ) {

					$data = $this->prepare_item_for_response( $plugin, $request );

					if ( ! is_wp_error( $data ) ) {

						$response[] = $this->prepare_response_for_collection( $data );
					}
				}
			}

		}

		$response = rest_ensure_response( $response );

		return $response;
	}

	/**
	 * Get one item from the collection
	 *
	 * @since 1.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_item( $request ) {

		// Ensure EDD-SL exists before proceeding.
		if ( ! function_exists( 'edd_software_licensing' ) ) {

			return new WP_Error( 'edd_sl_not_found', 'EDD-SL not found.', $request );
		}

		if ( ! isset( $request['action'] ) ) {

			return new WP_Error( 'no_action', 'Request requires the action parameter `status`.', $request );
		}

		$response = array();

		if ( isset( $request['plugins'] ) ) {

			$plugin = cnFormatting::maybeJSONdecode( $request['plugins'] );

			$data = $this->prepare_item_for_response( $plugin, $request );

			if ( ! is_wp_error( $data ) ) {

				$response = $data;
			}

		}

		return rest_ensure_response( $response );
	}

	/**
	 * Prepare the item for the REST response
	 *
	 * Based on @see edd_software_licensing::remote_license_check()
	 *
	 * @since 1.0
	 *
	 * @param mixed           $item    WordPress representation of the item.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return mixed
	 */
	public function prepare_item_for_response( $item, $request ) {

		$edd_sl = edd_software_licensing();

		$slug        = isset( $item['slug'] ) ? sanitize_text_field( $item['slug'] ) : FALSE;
		$item_id     = ! empty( $item['item_id'] ) ? absint( $item['item_id'] ) : FALSE;
		$item_name   = ! empty( $item['item_name'] ) ? rawurldecode( $item['item_name'] ) : FALSE;
		$license     = urldecode( $item['license'] );
		$url         = isset( $item['url'] ) ? urldecode( $item['url'] ) : '';
		$license_id  = $edd_sl->get_license_by_key( $license );
		$expires     = $edd_sl->get_license_expiration( $license_id );
		$payment_id  = get_post_meta( $license_id, '_edd_sl_payment_id', TRUE );
		$download_id = get_post_meta( $license_id, '_edd_sl_download_id', TRUE );
		$customer_id = edd_get_payment_customer_id( $payment_id );

		if ( empty( $item_id ) && empty( $item_name ) && ( ! defined( 'EDD_BYPASS_NAME_CHECK' ) || ! EDD_BYPASS_NAME_CHECK ) ) {

			return new WP_Error( 'item_id_or_name_not_provided', 'Item name or ID is required.', $item );
		}

		if ( empty( $item_id ) ) {

			if ( empty( $license ) && empty( $item_name ) ) {

				return new WP_Error( 'item_license_or_name_not_provided', 'Item licensee or name is required.', $item );
			}

			if ( empty( $license ) ) {

				$item_id = $edd_sl->get_download_id_by_name( $item_name );

			} else {

				$item_id = $edd_sl->get_download_id_by_license( $license );

				// Requested item name does not match the requested license.
				if ( ( ! defined( 'EDD_BYPASS_NAME_CHECK' ) || ! EDD_BYPASS_NAME_CHECK ) && ! $edd_sl->check_item_name( $item_id, $item_name ) ) {

					//return new WP_Error( 'item_name_mismatch', 'License entered is not for this item.', $item );
					$item_id = $edd_sl->get_download_id_by_name( $item_name );
				}
			}

		}

		$args = array(
			'item_id'   => $item_id,
			'item_name' => $item_name,
			'key'       => $license,
			'url'       => $url,
		);

		$result = $edd_sl->check_license( $args );

		$license_limit = $edd_sl->get_license_limit( $download_id, $license_id );
		$site_count    = $edd_sl->get_site_count( $license_id );

		$download = new EDD_SL_Download( $item_id );

		if ( ! $download ) {

			return new WP_Error(
				'item_not_found',
				sprintf( 'Requested item does not match a valid %s', edd_get_label_singular() ),
				$item );
		}

		$customer = new EDD_Customer( $customer_id );

		$data = apply_filters(
			'edd_remote_license_check_response',
			array(
				'success'          => (bool) $result,
				'license'          => $result,
				'item_id'          => $item_id,
				'item_name'        => $download->post_title,
				'slug'             => ! empty( $slug ) ? $slug : $download->post_name,
				'plugin'           => $item['basename'],
				'expires'          => is_numeric( $expires ) ? date( 'Y-m-d H:i:s', $expires ) : $expires,
				'payment_id'       => $payment_id,
				'customer_name'    => ! empty( $customer->name ) ? $customer->name : '',
				'customer_email'   => ! empty( $customer->email ) ? $customer->email : '',
				'license_limit'    => $license_limit,
				'site_count'       => $site_count,
				'activations_left' => $license_limit > 0 ? $license_limit - $site_count : 'unlimited',
			),
			$args,
			$license_id
		);

		$response = rest_ensure_response( $data );

		return rest_ensure_response( $response );
	}
}

/**
 * Callback for the `plugins_loaded` action.
 *
 * Load on priority 11 so we know both Connections and EDD-SL are loaded.
 *
 * Init the API.
 *
 * @since 1.0
 */
function cnPlugin_Updater_REST_API() {

	new cnPlugin_Updater_REST_API();
}
add_action( 'plugins_loaded', 'cnPlugin_Updater_REST_API', 11 );
