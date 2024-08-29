<?php
/**
 * Unit tests covering WP_REST_Template_Autosaves_Controller functionality.
 *
 * @package WordPress
 * @subpackage REST API
 *
 * @group restapi
 */
class Tests_REST_wpRestTemplateAutosavesController extends WP_Test_REST_Controller_Testcase {

	/**
	 * @var string
	 */
	const TEST_THEME = 'block-theme';

	/**
	 * @var string
	 */
	const TEMPLATE_NAME = 'my_template';

	/**
	 * @var string
	 */
	const TEMPLATE_PART_NAME = 'my_template_part';

	/**
	 * @var string
	 */
	const TEMPLATE_POST_TYPE = 'wp_template';

	/**
	 * @var string
	 */
	const TEMPLATE_PART_POST_TYPE = 'wp_template_part';

	/**
	 * Admin user ID.
	 *
	 * @since 6.4.0
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Contributor user ID.
	 *
	 * @since 6.4.0
	 *
	 * @var int
	 */
	private static $contributor_id;

	/**
	 * Template post.
	 *
	 * @since 6.4.0
	 *
	 * @var WP_Post
	 */
	private static $template_post;

	/**
	 * Template part post.
	 *
	 * @since 6.7.0
	 *
	 * @var WP_Post
	 */
	private static $template_part_post;

	/**
	 * Create fake data before our tests run.
	 *
	 * @param WP_UnitTest_Factory $factory Helper that lets us create fake data.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$contributor_id = $factory->user->create(
			array(
				'role' => 'contributor',
			)
		);

		self::$admin_id = $factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( self::$admin_id );

		// Set up template post.
		self::$template_post = $factory->post->create_and_get(
			array(
				'post_type'    => self::TEMPLATE_POST_TYPE,
				'post_name'    => self::TEMPLATE_NAME,
				'post_title'   => 'My template',
				'post_content' => 'Content',
				'post_excerpt' => 'Description of my template',
				'tax_input'    => array(
					'wp_theme' => array(
						self::TEST_THEME,
					),
				),
			)
		);
		wp_set_post_terms( self::$template_post->ID, self::TEST_THEME, 'wp_theme' );

		// Set up template post.
		self::$template_part_post = $factory->post->create_and_get(
			array(
				'post_type'    => self::TEMPLATE_PART_POST_TYPE,
				'post_name'    => self::TEMPLATE_PART_NAME,
				'post_title'   => 'My template part',
				'post_content' => 'Content',
				'post_excerpt' => 'Description of my template part',
				'tax_input'    => array(
					'wp_theme' => array(
						self::TEST_THEME,
					),
					'wp_template_part_area' => array(
						WP_TEMPLATE_PART_AREA_HEADER,
					),
				),
			)
		);
		wp_set_post_terms( self::$template_part_post->ID, self::TEST_THEME, 'wp_theme' );
		wp_set_post_terms( self::$template_part_post->ID, WP_TEMPLATE_PART_AREA_HEADER, 'wp_template_part_area' );
	}

	/**
	 * @covers WP_REST_Template_Autosaves_Controller::register_routes
	 * @ticket 56922
	 */
	public function test_register_routes() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey(
			'/wp/v2/templates/(?P<id>([^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)[\/\w%-]+)/autosaves',
			$routes,
			'Template autosaves route does not exist.'
		);
		$this->assertArrayHasKey(
			'/wp/v2/templates/(?P<parent>([^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)[\/\w%-]+)/autosaves/(?P<id>[\d]+)',
			$routes,
			'Single template autosave based on the given ID route does not exist.'
		);
		$this->assertArrayHasKey(
			'/wp/v2/template-parts/(?P<id>([^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)[\/\w%-]+)/autosaves',
			$routes,
			'Template part autosaves route does not exist.'
		);
		$this->assertArrayHasKey(
			'/wp/v2/template-parts/(?P<parent>([^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)[\/\w%-]+)/autosaves/(?P<id>[\d]+)',
			$routes,
			'Single template part autosave based on the given ID route does not exist.'
		);
	}

	/**
	 * @covers WP_REST_Template_Autosaves_Controller::get_context_param
	 * @ticket 56922
	 */
	public function test_context_param() {
		// Collection.
		$request  = new WP_REST_Request( 'OPTIONS', '/wp/v2/templates/' . self::TEST_THEME . '/' . self::TEMPLATE_NAME . '/autosaves' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		// Collection.
		$this->assertCount(
			2,
			$data['endpoints'],
			'Failed to assert that the collection autosave endpoints count is 2.'
		);
		$this->assertSame(
			'view',
			$data['endpoints'][0]['args']['context']['default'],
			'Failed to assert that the default context for the GET collection endpoint is "view".'
		);
		$this->assertSame(
			array( 'view', 'embed', 'edit' ),
			$data['endpoints'][0]['args']['context']['enum'],
			"Failed to assert that the enum values for the GET collection endpoint are 'view', 'embed', and 'edit'."
		);

		// Single.
		$request  = new WP_REST_Request( 'OPTIONS', '/wp/v2/templates/' . self::TEST_THEME . '/' . self::TEMPLATE_NAME . '/autosaves/1' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertCount(
			1,
			$data['endpoints'],
			'Failed to assert that the single autosave endpoints count is 1.'
		);
		$this->assertSame(
			'view',
			$data['endpoints'][0]['args']['context']['default'],
			'Failed to assert that the default context for the single autosave endpoint is "view".'
		);
		$this->assertSame(
			array( 'view', 'embed', 'edit' ),
			$data['endpoints'][0]['args']['context']['enum'],
			"Failed to assert that the enum values for the single autosave endpoint are 'view', 'embed', and 'edit'."
		);
	}

	/**
	 * @coversNothing
	 * @ticket 56922
	 */
	public function test_get_items() {
		// A proper data provider cannot be used because this method's signature must match the parent method.
		// Therefore, actual tests are performed in the test_get_items_with_data method.
		$this->assertTrue( true );
	}

	/**
	 * @dataProvider data_get_items_with_data
	 * @covers WP_REST_Template_Autosaves_Controller::get_items
	 * @ticket 56922
	 *
	 * @param WP_Post $parent_post_property_name A class property name that contains the parent post object.
	 * @param string $rest_base                  Base part of the REST API endpoint to test.
	 * @param string $template_id				 Template ID to use in the test.
	 */
	public function test_get_items_with_data( $parent_post_property_name, $rest_base, $template_id ) {
		wp_set_current_user( self::$admin_id );
		// Cannot access this property in the data provider because it is not initialized at the time of execution.
		$parent_post      = self::$$parent_post_property_name;
		$autosave_post_id = wp_create_post_autosave(
			array(
				'post_content' => 'Autosave content.',
				'post_ID'      => $parent_post->ID,
				'post_type'    => $parent_post->post_type,
			)
		);

		$request   = new WP_REST_Request(
			'GET',
			'/wp/v2/' . $rest_base . '/' . self::TEST_THEME . '/' . self::TEMPLATE_NAME . '/autosaves'
		);
		$response  = rest_get_server()->dispatch( $request );
		$autosaves = $response->get_data();
		$this->assertSame( 200, $response->get_status(), 'Response is expected to have a status code of 200.' );

		$this->assertCount(
			1,
			$autosaves,
			'Failed asserting that the response data contains exactly 1 item.'
		);

		$this->assertSame(
			$autosave_post_id,
			$autosaves[0]['wp_id'],
			'Failed asserting that the ID of the autosave matches the expected autosave post ID.'
		);
		$this->assertSame(
			self::$template_post->ID,
			$autosaves[0]['parent'],
			'Failed asserting that the parent ID of the autosave matches the template post ID.'
		);
		$this->assertSame(
			'Autosave content.',
			$autosaves[0]['content']['raw'],
			'Failed asserting that the content of the autosave is "Autosave content.".'
		);
	}

	/**
	 * Data provider for test_get_items_with_data.
	 *
	 * @return array
	 */
	public function data_get_items_with_data() {
		return array(
			'templates'      => array( 'template_post', 'templates', self::TEST_THEME . '/' . self::TEMPLATE_NAME ),
			'template parts' => array( 'template_part_post', 'template-parts', self::TEST_THEME . '/' . self::TEMPLATE_PART_NAME ),
		);
	}

	/**
	 * @coversNothing
	 * @ticket 56922
	 */
	public function test_get_item() {
		// A proper data provider cannot be used because this method's signature must match the parent method.
		// Therefore, actual tests are performed in the test_get_item_with_data method.
		$this->assertTrue( true );
	}

	/**
	 * @dataProvider data_get_item_with_data
	 * @covers WP_REST_Template_Autosaves_Controller::get_item
	 * @ticket 56922
	 *
	 * @param WP_Post $parent_post_property_name A class property name that contains the parent post object.
	 * @param string $rest_base                  Base part of the REST API endpoint to test.
	 * @param string $template_id                Template ID to use in the test.
	 */
	public function test_get_item_with_data( $parent_post_property_name, $rest_base, $template_id ) {
		wp_set_current_user( self::$admin_id );

		$parent_post      = self::$$parent_post_property_name;

		$autosave_post_id = wp_create_post_autosave(
			array(
				'post_content' => 'Autosave content.',
				'post_ID'      => $parent_post->ID,
				'post_type'    => $parent_post->post_type,
			)
		);

		$request  = new WP_REST_Request( 'GET', '/wp/v2/' . $rest_base . '/' . $template_id . '/autosaves/' . $autosave_post_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'Response is expected to have a status code of 200.' );
		$autosave = $response->get_data();

		$this->assertIsArray( $autosave, 'Failed asserting that the autosave is an array.' );
		$this->assertSame(
			$autosave_post_id,
			$autosave['wp_id'],
			"Failed asserting that the autosave id is the same as $autosave_post_id."
		);
		$this->assertSame(
			$parent_post->ID,
			$autosave['parent'],
			sprintf(
				'Failed asserting that the parent id of the autosave is the same as %s.',
				$parent_post->ID
			)
		);
	}

	/**
	 * Data provider for test_get_item_with_data.
	 *
	 * @return array
	 */
	public function data_get_item_with_data() {
		return array(
			'templates'      => array( 'template_post', 'templates', self::TEST_THEME . '/' . self::TEMPLATE_NAME ),
			'template parts' => array( 'template_part_post', 'template-parts', self::TEST_THEME . '/' . self::TEMPLATE_PART_NAME ),
		);
	}

	/**
	 * @covers WP_REST_Template_Autosaves_Controller::prepare_item_for_response
	 * @ticket 56922
	 */
	public function test_prepare_item() {
		wp_set_current_user( self::$admin_id );
		$autosave_post_id = wp_create_post_autosave(
			array(
				'post_content' => 'Autosave content.',
				'post_ID'      => self::$template_post->ID,
				'post_type'    => self::TEMPLATE_POST_TYPE,
			)
		);
		$autosave_db_post = get_post( $autosave_post_id );
		$template_id      = self::TEST_THEME . '//' . self::TEMPLATE_NAME;
		$request          = new WP_REST_Request( 'GET', '/wp/v2/templates/' . $template_id . '/autosaves/' . $autosave_db_post->ID );
		$controller       = new WP_REST_Template_Autosaves_Controller( self::TEMPLATE_POST_TYPE );
		$response         = $controller->prepare_item_for_response( $autosave_db_post, $request );
		$this->assertInstanceOf(
			WP_REST_Response::class,
			$response,
			'Failed asserting that the response object is an instance of WP_REST_Response.'
		);

		$autosave = $response->get_data();
		$this->assertIsArray( $autosave, 'Failed asserting that the autosave is an array.' );
		$this->assertSame(
			$autosave_db_post->ID,
			$autosave['wp_id'],
			"Failed asserting that the autosave id is the same as $autosave_db_post->ID."
		);
		$this->assertSame(
			self::$template_post->ID,
			$autosave['parent'],
			sprintf(
				'Failed asserting that the parent id of the autosave is the same as %s.',
				self::$template_post->ID
			)
		);

		$links = $response->get_links();
		$this->assertIsArray( $links, 'Failed asserting that the links are an array.' );

		$this->assertStringEndsWith(
			$template_id . '/autosaves/' . $autosave_db_post->ID,
			$links['self'][0]['href'],
			"Failed asserting that the self link ends with $template_id . '/autosaves/' . $autosave_db_post->ID."
		);

		$this->assertStringEndsWith(
			$template_id,
			$links['parent'][0]['href'],
			"Failed asserting that the parent link ends with %$template_id."
		);
	}

	/**
	 * @covers WP_REST_Template_Autosaves_Controller::get_item_schema
	 * @ticket 56922
	 */
	public function test_get_item_schema() {
		$request  = new WP_REST_Request( 'OPTIONS', '/wp/v2/templates/' . self::TEST_THEME . '/' . self::TEMPLATE_NAME . '/autosaves' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$properties = $data['schema']['properties'];

		$this->assertCount( 18, $properties );
		$this->assertArrayHasKey( 'id', $properties, 'ID key should exist in properties.' );
		$this->assertArrayHasKey( 'slug', $properties, 'Slug key should exist in properties.' );
		$this->assertArrayHasKey( 'theme', $properties, 'Theme key should exist in properties.' );
		$this->assertArrayHasKey( 'source', $properties, 'Source key should exist in properties.' );
		$this->assertArrayHasKey( 'origin', $properties, 'Origin key should exist in properties.' );
		$this->assertArrayHasKey( 'content', $properties, 'Content key should exist in properties.' );
		$this->assertArrayHasKey( 'title', $properties, 'Title key should exist in properties.' );
		$this->assertArrayHasKey( 'description', $properties, 'description key should exist in properties.' );
		$this->assertArrayHasKey( 'status', $properties, 'status key should exist in properties.' );
		$this->assertArrayHasKey( 'wp_id', $properties, 'wp_id key should exist in properties.' );
		$this->assertArrayHasKey( 'has_theme_file', $properties, 'has_theme_file key should exist in properties.' );
		$this->assertArrayHasKey( 'author', $properties, 'author key should exist in properties.' );
		$this->assertArrayHasKey( 'modified', $properties, 'modified key should exist in properties.' );
		$this->assertArrayHasKey( 'is_custom', $properties, 'is_custom key should exist in properties.' );
		$this->assertArrayHasKey( 'parent', $properties, 'Parent key should exist in properties.' );
		$this->assertArrayHasKey( 'author_text', $properties, 'author_text key should exist in properties.' );
		$this->assertArrayHasKey( 'original_source', $properties, 'original_source key should exist in properties.' );
	}

	/**
	 * @coversNothing
	 * @ticket 56922
	 */
	public function test_create_item() {
		// A proper data provider cannot be used because this method's signature must match the parent method.
		// Therefore, actual tests are performed in the test_get_item_with_data method.
		$this->assertTrue( true );
	}

	/**
	 * @dataProvider data_create_item_with_data
	 * @covers WP_REST_Template_Autosaves_Controller::create_item
	 * @ticket 56922
	 *
	 * @param string $rest_base   Base part of the REST API endpoint to test.
	 * @param string $template_id Template ID to use in the test.
	 */
	public function test_create_item_with_data( $rest_base, $template_id ) {
		wp_set_current_user( self::$admin_id );

		$request     = new WP_REST_Request( 'POST', '/wp/v2/' . $rest_base . '/' . $template_id . '/autosaves' );
		$request->add_header( 'Content-Type', 'application/x-www-form-urlencoded' );

		$request_parameters = array(
			'title'   => 'Post Title',
			'content' => 'Post content',
			'excerpt' => 'Post excerpt',
			'name'    => 'test',
			'id'      => $template_id,
		);

		$request->set_body_params( $request_parameters );
		$response = rest_get_server()->dispatch( $request );

		$this->assertNotWPError( $response, 'The response from this request should not return a WP_Error object.' );
		$response = rest_ensure_response( $response );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'content', $data, 'Response should contain a key called content.' );
		$this->assertSame( $request_parameters['content'], $data['content']['raw'], 'Response data should match for field content.' );

		$this->assertArrayHasKey( 'title', $data, 'Response should contain a key called title.' );
		$this->assertSame( $request_parameters['title'], $data['title']['raw'], 'Response data should match for field title.' );
	}

	/**
	 * Data provider for test_get_item_with_data.
	 *
	 * @return array
	 */
	public function data_create_item_with_data() {
		return array(
			'templates'      => array( 'templates', self::TEST_THEME . '/' . self::TEMPLATE_NAME ),
			'template parts' => array( 'template-parts', self::TEST_THEME . '/' . self::TEMPLATE_PART_NAME ),
		);
	}

	/**
	 * @covers WP_REST_Template_Autosaves_Controller::delete_item
	 * @ticket 56922
	 */
	public function test_create_item_incorrect_permission() {
		wp_set_current_user( self::$contributor_id );
		$template_id = self::TEST_THEME . '/' . self::TEMPLATE_NAME;
		$request     = new WP_REST_Request( 'POST', '/wp/v2/templates/' . $template_id . '/autosaves' );
		$response    = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_manage_templates', $response, WP_Http::FORBIDDEN );
	}

	/**
	 * @covers WP_REST_Template_Autosaves_Controller::delete_item
	 * @ticket 56922
	 */
	public function test_create_item_no_permission() {
		wp_set_current_user( 0 );
		$template_id = self::TEST_THEME . '/' . self::TEMPLATE_NAME;
		$request     = new WP_REST_Request( 'POST', '/wp/v2/templates/' . $template_id . '/autosaves' );
		$response    = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_manage_templates', $response, WP_Http::UNAUTHORIZED );
	}

	/**
	 * @coversNothing
	 * @ticket 56922
	 */
	public function test_update_item() {
		$this->markTestSkipped(
			sprintf(
				"The '%s' controller doesn't currently support the ability to update template autosaves.",
				WP_REST_Template_Autosaves_Controller::class
			)
		);
	}

	/**
	 * @coversNothing
	 * @ticket 56922
	 */
	public function test_delete_item() {
		$this->markTestSkipped(
			sprintf(
				"The '%s' controller doesn't currently support the ability to delete template autosaves.",
				WP_REST_Template_Autosaves_Controller::class
			)
		);
	}
}
