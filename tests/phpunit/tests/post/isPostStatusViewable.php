<?php

/**
 * @group post
 */
class Tests_Post_IsPostStatusViewable extends WP_UnitTestCase {

	/**
	 * Remove the test status from the global when finished.
	 *
	 * @global $wp_post_statuses
	 */
	static function wpTearDownAfterClass() {
		global $wp_post_statuses;
		unset( $wp_post_statuses['wp_tests_ps'] );
	}

	public function test_should_return_false_for_non_publicly_queryable_types() {
		register_post_status(
			'wp_tests_ps',
			array(
				'publicly_queryable' => false,
				'_builtin'           => false,
				'public'             => true,
			)
		);

		$this->assertFalse( is_post_status_viewable( 'wp_tests_ps' ) );
	}

	public function test_should_return_true_for_publicly_queryable_types() {
		register_post_status(
			'wp_tests_ps',
			array(
				'publicly_queryable' => true,
				'_builtin'           => false,
				'public'             => false,
			)
		);

		$this->assertTrue( is_post_status_viewable( 'wp_tests_ps' ) );
	}

	public function test_should_return_false_for_builtin_nonpublic_types() {
		register_post_status(
			'wp_tests_ps',
			array(
				'publicly_queryable' => false,
				'_builtin'           => true,
				'public'             => false,
			)
		);

		$this->assertFalse( is_post_status_viewable( 'wp_tests_ps' ) );
	}

	public function test_should_return_false_for_nonbuiltin_public_types() {
		register_post_status(
			'wp_tests_ps',
			array(
				'publicly_queryable' => false,
				'_builtin'           => false,
				'public'             => true,
			)
		);

		$this->assertFalse( is_post_status_viewable( 'wp_tests_ps' ) );
	}

	public function test_should_return_true_for_builtin_public_types() {
		register_post_status(
			'wp_tests_ps',
			array(
				'publicly_queryable' => false,
				'_builtin'           => true,
				'public'             => true,
			)
		);

		$this->assertTrue( is_post_status_viewable( 'wp_tests_ps' ) );
	}

	public function test_published_should_be_viewable() {
		$this->assertTrue( is_post_status_viewable( 'publish' ) );
	}

	public function test_should_accept_post_status_obj_and_name() {
		register_post_status(
			'wp_tests_ps',
			array(
				'publicly_queryable' => true,
				'_builtin'           => false,
				'public'             => false,
			)
		);

		$this->assertTrue( is_post_status_viewable( 'wp_tests_ps' ) );
		$this->assertTrue( is_post_status_viewable( get_post_status_object( 'wp_tests_ps' ) ) );
	}

	public function test_should_return_false_for_bad_post_type_name() {
		$this->assertFalse( is_post_status_viewable( 'foo' ) );
	}
}
