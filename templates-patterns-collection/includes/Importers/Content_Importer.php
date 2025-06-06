<?php
/**
 * Content Import Handling
 *
 * @package    templates-patterns-collection
 */

namespace TIOB\Importers;

use TIOB\Admin;
use TIOB\Importers\Cleanup\Active_State;
use TIOB\Importers\Helpers\Helper;
use TIOB\Importers\Helpers\Importer_Alterator;
use TIOB\Logger;
use TIOB\Importers\WP\WP_Import;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Content_Importer
 *
 * @package templates-patterns-collection
 */
class Content_Importer {
	use Helper;

	private $logger = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->load_importer();
		$this->logger = Logger::get_instance();
	}

	/**
	 * Import Remote XML file.
	 *
	 * @param WP_REST_Request $request the async request.
	 *
	 * @return WP_REST_Response
	 */
	public function import_remote_xml( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->logger->log( 'No manage_options permissions' );

			return new WP_REST_Response(
				array(
					'data'    => 'ti__ob_permission_err_1',
					'success' => false,
				)
			);
		}

		do_action( 'themeisle_ob_before_xml_import' );

		$body = $request->get_json_params();

		$content_file_url = $body['contentFile'];
		$page_builder     = isset( $body['editor'] ) ? $body['editor'] : '';

		if ( empty( $content_file_url ) ) {
			$this->logger->log( "No content file to import at url {$content_file_url}" );

			return new WP_REST_Response(
				array(
					'data'    => 'ti__ob_remote_err_1',
					'success' => false,
				)
			);
		}

		if ( ! isset( $body['source'] ) || empty( $body['source'] ) ) {
			$this->logger->log( 'No source defined for the import.' );

			return new WP_REST_Response(
				array(
					'data'    => 'ti__ob_remote_err_2',
					'success' => false,
				)
			);
		}

		if ( ! isset( $body['demoSlug'] ) ) {
			$body['demoSlug'] = 'neve';
		}

		set_time_limit( 0 );
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		require_once( ABSPATH . 'wp-admin/includes/media.php' );

		if ( $body['source'] === 'remote' ) {
			$this->logger->log( 'Saving remote XML', 'progress' );

			$request_args = array(
				'headers' => array(
					'User-Agent' => 'WordPress/' . md5( get_site_url() ),
					'Origin'     => get_site_url(),
				),
			);

			if ( defined( 'TPC_REPLACE_API_SRC' ) && TPC_REPLACE_API_SRC === true ) {
				$api_src          = defined( 'TPC_API_SRC' ) && ! empty( TPC_API_SRC ) ? TPC_API_SRC : Admin::API;
				$content_file_url = str_replace( Admin::API, $api_src, $content_file_url );
			}
			$response_file = wp_remote_get( add_query_arg( 'key', apply_filters( 'product_neve_license_key', 'free' ), $content_file_url ), $request_args );

			if ( is_wp_error( $response_file ) ) {
				$this->logger->log( "Error saving the remote file:  {$response_file->get_error_message()}.", 'success' );
			}
			$content_file_path = $this->save_xhr_return_path( wp_remote_retrieve_body( $response_file ) );
			$this->logger->log( "Saved remote XML at path {$content_file_path}.", 'success' );
		} else {
			$this->logger->log( 'Using local XML.', 'success' );
			$content_file_path = $content_file_url;
		}

		$this->logger->log( 'Starting content import...', 'progress' );
		$import_status = $this->import_file( $content_file_path, $body, $page_builder );

		if ( is_wp_error( $import_status ) ) {
			$this->logger->log( "Import crashed with message: {$import_status->get_error_message()}" );

			return new WP_REST_Response(
				array(
					'data'    => $import_status,
					'success' => false,
				)
			);
		}

		if ( $body['source'] === 'remote' ) {
			unlink( $content_file_path );
		}

		do_action( 'themeisle_ob_after_xml_import' );

		$this->logger->log( 'Busting elementor cache', 'progress' );
		$this->maybe_bust_elementor_cache();

		$this->logger->log( 'Busting woo cache', 'progress' );
		$this->maybe_rebuild_woo_product();

		// Set front page.
		if ( isset( $body['frontPage'] ) ) {
			$frontpage_id = $this->setup_front_page( $body['frontPage'], $body['demoSlug'] );
		}
		do_action( 'themeisle_ob_after_front_page_setup' );

		// Set shop pages.
		if ( isset( $body['shopPages'] ) ) {
			$this->setup_shop_pages( $body['shopPages'], $body['demoSlug'] );
		}
		do_action( 'themeisle_ob_after_shop_pages_setup' );

		// Set payment forms.
		if ( isset( $body['paymentForms'] ) ) {
			$this->setup_payment_forms( $body['paymentForms'] );
		}
		do_action( 'themeisle_ob_after_payment_forms_setup' );

		// Set Masteriyo data.
		if ( isset( $body['masteriyoData'] ) ) {
			$this->setup_masteriyo( $body['masteriyoData'] );
		}
		do_action( 'themeisle_ob_after_masteriyo_setup' );

		if ( empty( $frontpage_id ) ) {
			$this->logger->log( 'No front page ID.' );
		}

		return new WP_REST_Response(
			array(
				'success'      => true,
				'frontpage_id' => $frontpage_id,
			)
		);
	}

	/**
	 * Save remote XML file and return the file path.
	 *
	 * @param string $content the content.
	 *
	 * @return string
	 */
	public function save_xhr_return_path( $content ) {
		$wp_upload_dir = wp_upload_dir( null, false );
		$file_path     = $wp_upload_dir['basedir'] . '/themeisle-demo-import.xml';
		require_once( ABSPATH . '/wp-admin/includes/file.php' );
		global $wp_filesystem;
		WP_Filesystem();
		$wp_filesystem->put_contents( $file_path, $content );

		return $file_path;
	}

	/**
	 * Set up front page options by `post_name`.
	 *
	 * @param array $args the front page array.
	 * @param string $demo_slug the importing demo slug.
	 *
	 * @return int|void
	 */
	public function setup_front_page( $args, $demo_slug ) {
		$front_page_options = array();
		if ( ! is_array( $args ) ) {
			return;
		}
		if ( empty( $args['front_page'] ) && empty( $args['blog_page'] ) ) {
			$this->logger->log( 'No front page to set up.', 'success' );

			return null;
		}

		$front_page_options['show_on_front'] = get_option( 'show_on_front' );
		update_option( 'show_on_front', 'page' );

		if ( isset( $args['front_page'] ) && $args['front_page'] !== null ) {
			$front_page_obj = get_page_by_path( $this->cleanup_page_slug( $args['front_page'], $demo_slug ) );
			if ( isset( $front_page_obj->ID ) ) {
				$front_page_options['page_on_front'] = get_option( 'page_on_front' );
				update_option( 'page_on_front', $front_page_obj->ID );
			}
		}

		if ( isset( $args['blog_page'] ) && $args['blog_page'] !== null ) {
			$blog_page_obj = get_page_by_path( $this->cleanup_page_slug( $args['blog_page'], $demo_slug ) );
			if ( isset( $blog_page_obj->ID ) ) {
				$front_page_options['page_for_posts'] = get_option( 'page_for_posts' );
				update_option( 'page_for_posts', $blog_page_obj->ID );
			}
		}

		do_action( 'themeisle_cl_add_property_state', Active_State::FRONT_PAGE_NSP, $front_page_options );

		if ( isset( $front_page_obj->ID ) ) {
			$this->logger->log( "Front page set up with id: {$front_page_obj->ID}.", 'success' );

			return $front_page_obj->ID;
		}
	}

	/**
	 * Set up shop pages options by `post_name`.
	 *
	 * @param array $pages the shop pages array.
	 * @param string $demo_slug the demo slug.
	 */
	public function setup_shop_pages( $pages, $demo_slug ) {
		$this->logger->log( 'Setting up shop page.', 'progress' );
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->logger->log( 'No WooCommerce.', 'success' );

			return;
		}
		if ( ! is_array( $pages ) ) {
			$this->logger->log( 'No Shop Pages.', 'success' );

			return;
		}
		$shop_page_options = array();
		foreach ( $pages as $option_id => $slug ) {
			if ( ! empty( $slug ) ) {
				$page_object = get_page_by_path( $this->cleanup_page_slug( $slug, $demo_slug ) );
				if ( isset( $page_object->ID ) ) {
					$shop_page_options[ $option_id ] = get_option( $option_id );
					update_option( $option_id, $page_object->ID );
				}
			}
		}
		do_action( 'themeisle_cl_add_property_state', Active_State::SHOP_PAGE_NSP, $shop_page_options );
		$this->logger->log( 'Shop pages set up.', 'success' );
	}

	public function setup_payment_forms( $forms ) {
		$this->logger->log( 'Setting up payment forms.', 'progress' );
		if ( ! class_exists( 'MM_WPFS_Database' ) ) {
			$this->logger->log( 'No WP Full Stripe.', 'success' );
			return;
		}

		if ( ! is_array( $forms ) ) {
			$this->logger->log( 'No Payment Forms.', 'success' );
			return;
		}

		$db = new \MM_WPFS_Database();

		$payment_form_options = array();
		foreach ( $forms as $key => $form ) {
			if ( ! in_array( $form['type'], array( 'payment', 'subscription', 'donation' ) ) || ! in_array( $form['layout'], array( 'inline', 'checkout' ) ) ) {
				continue;
			}

			$check  = 'get' . ucfirst( $form['layout'] ) . ucfirst( $form['type'] ) . 'FormByName';
			$insert = 'insert' . ucfirst( $form['layout'] ) . ucfirst( $form['type'] ) . 'Form';

			if ( method_exists( $db, $check ) ) {
				$existing_form = $db->$check( $form['name'] );
				if ( $existing_form ) {
					$this->logger->log( "Form {$form['name']} already exists.", 'success' );
					continue;
				}
			}

			if ( method_exists( $db, $insert ) ) {
				$form['data'] = array_filter(
					$form['data'],
					function ( $key ) {
						return strpos( $key, 'FormID' ) === false;
					},
					ARRAY_FILTER_USE_KEY
				);

				$db->$insert( $form['data'] );

				$payment_form_options[ $form['data']['name'] ] = array(
					'layout' => $form['layout'],
					'type'   => $form['type'],
				);
			} else {
				$this->logger->log( "Method {$insert} does not exist.", 'error' );
			}
		}

		do_action( 'themeisle_cl_add_property_state', Active_State::PAYMENT_FORM_NSP, $payment_form_options );

		$this->logger->log( 'Payment forms set up.', 'success' );
	}

	/**
	 * Set up Masteriyo data.
	 *
	 * @param array $data the masteriyo data.
	 */
	public function setup_masteriyo( $data ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			$this->logger->log( 'No Masteriyo data.', 'success' );
			return;
		}

		$this->logger->log( 'Setting up Masteriyo data.', 'progress' );

		if ( ! function_exists( 'masteriyo_set_setting' ) ) {
			$this->logger->log( 'Masteriyo not installed.', 'success' );
			return;
		}

		if ( isset( $data['settings'] ) ) {
			foreach ( $data['settings'] as $key => $value ) {
				masteriyo_set_setting( $key, $value );
			}
		}

		$this->logger->log( 'Masteriyo data set up.', 'success' );
	}

	/**
	 * Maybe bust cache for elementor plugin.
	 */
	public function maybe_bust_elementor_cache() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		if ( null === \Elementor\Plugin::instance()->files_manager ) {
			return;
		}
		\Elementor\Plugin::instance()->files_manager->clear_cache();
	}

	/**
	 * Update products to rebuild missing data not set on import.
	 *
	 * @return void
	 */
	public function maybe_rebuild_woo_product() {
		if ( ! class_exists( '\WC_Product' ) ) {
			return;
		}

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$results = new \WP_Query( $args );
		if ( empty( $results->posts ) ) {
			return;
		}
		foreach ( $results->posts as $post_id ) {
			$product_object = new \WC_Product( $post_id );
			$product_object->save();
		}
	}

	/**
	 * Import file
	 *
	 * @param string $file_path the file path to import.
	 * @param array $req_body the request body to be passed to the alterator.
	 * @param string $builder the page builder used.
	 *
	 * @return WP_Error|true
	 */
	public function import_file( $file_path, $req_body = array(), $builder = '' ) {
		if ( empty( $file_path ) || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'ti__ob_content_err_1', 'No content file' );
		}

		$alterator = new Importer_Alterator( $req_body );
		$importer  = new WP_Import( $builder );

		return $importer->import( $file_path );
	}

	/**
	 * Load the importer.
	 */
	private function load_importer() {
		if ( ! class_exists( '\WP_Importer' ) ) {
			$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
			if ( file_exists( $class_wp_importer ) && is_readable( $class_wp_importer ) ) {
				require $class_wp_importer;
				return false;
			}
			return new WP_Error( 'WP_Importer Core class doesn\'t exist.' );
		}
	}
}
