<?php
/**
 * @group dependencies
 * @group scripts
 * @covers ::wp_enqueue_script
 * @covers ::wp_register_script
 * @covers ::wp_print_scripts
 * @covers ::wp_script_add_data
 * @covers ::wp_add_inline_script
 * @covers ::wp_set_script_translations
 */
class Tests_Dependencies_Scripts extends WP_UnitTestCase {
	protected $old_wp_scripts;

	protected $wp_scripts_print_translations_output;

	/**
	 * Stores a string reference to a default scripts directory name, utilised by certain tests.
	 *
	 * @var string
	 */
	protected $default_scripts_dir = '/directory/';

	public function set_up() {
		parent::set_up();
		$this->old_wp_scripts = isset( $GLOBALS['wp_scripts'] ) ? $GLOBALS['wp_scripts'] : null;
		remove_action( 'wp_default_scripts', 'wp_default_scripts' );
		remove_action( 'wp_default_scripts', 'wp_default_packages' );
		$GLOBALS['wp_scripts']                  = new WP_Scripts();
		$GLOBALS['wp_scripts']->default_version = get_bloginfo( 'version' );

		$this->wp_scripts_print_translations_output  = <<<JS
<script type='text/javascript' id='__HANDLE__-js-translations'>
( function( domain, translations ) {
	var localeData = translations.locale_data[ domain ] || translations.locale_data.messages;
	localeData[""].domain = domain;
	wp.i18n.setLocaleData( localeData, domain );
} )( "__DOMAIN__", __JSON_TRANSLATIONS__ );
</script>
JS;
		$this->wp_scripts_print_translations_output .= "\n";
	}

	public function tear_down() {
		$GLOBALS['wp_scripts'] = $this->old_wp_scripts;
		add_action( 'wp_default_scripts', 'wp_default_scripts' );
		parent::tear_down();
	}

	/**
	 * Test versioning
	 *
	 * @ticket 11315
	 */
	public function test_wp_enqueue_script() {
		global $wp_version;

		wp_enqueue_script( 'no-deps-no-version', 'example.com', array() );
		wp_enqueue_script( 'empty-deps-no-version', 'example.com' );
		wp_enqueue_script( 'empty-deps-version', 'example.com', array(), 1.2 );
		wp_enqueue_script( 'empty-deps-null-version', 'example.com', array(), null );

		$expected  = "<script type='text/javascript' src='http://example.com?ver={$wp_version}' id='no-deps-no-version-js'></script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com?ver={$wp_version}' id='empty-deps-no-version-js'></script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com?ver=1.2' id='empty-deps-version-js'></script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='empty-deps-null-version-js'></script>\n";

		$this->assertSame( $expected, get_echo( 'wp_print_scripts' ) );

		// No scripts left to print.
		$this->assertSame( '', get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * Gets delayed strategies as a data provider.
	 *
	 * @return array[] Delayed strategies.
	 */
	public function data_provider_delayed_strategies() {
		return array(
			'defer' => array( 'defer' ),
			'async' => array( 'async' ),
		);
	}

	/**
	 * Data provider for test_print_delayed_inline_script_loader_timing.
	 *
	 * @return array[]
	 */
	public function data_to_test_print_delayed_inline_script_loader_timing() {
		/**
		 * Enqueue script with defer strategy.
		 *
		 * @param bool $in_footer In footer.
		 */
		$enqueue_script    = static function ( $in_footer = false ) {
			wp_enqueue_script(
				$in_footer ? 'foo-footer' : 'foo-head',
				sprintf( 'https://example.com/%s.js', $in_footer ? 'foo-footer' : 'foo-head' ),
				array(),
				null,
				array(
					'in_footer' => $in_footer,
					'strategy'  => 'defer',
				)
			);
		};

		/**
		 * Add inline after script.
		 *
		 * @param string $handle Handle.
		 */
		$add_inline_script = static function ( $handle ) {
			wp_add_inline_script(
				$handle,
				"/*{$handle}-after*/"
			);
		};

		return array(
			'no_delayed_inline_scripts'            => array(
				'set_up'          => static function () use ( $enqueue_script ) {
					$enqueue_script( false );
					$enqueue_script( true );
				},
				'expected_head'   => <<<HTML
<script id="foo-head-js" src="https://example.com/foo-head.js" type="text/javascript" defer></script>
HTML
				,
				'expected_torso'  => '',
				'expected_footer' => <<<HTML
<script id="foo-footer-js" src="https://example.com/foo-footer.js" type="text/javascript" defer></script>
HTML
				,
			),
			'delayed_inline_script_in_head_only'   => array(
				'set_up'          => static function () use ( $enqueue_script, $add_inline_script ) {
					$enqueue_script( false );
					$add_inline_script( 'foo-head' );
				},
				'expected_head'   => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="foo-head-js" src="https://example.com/foo-head.js" type="text/javascript" defer></script>
<script id="foo-head-js-after" type="text/plain">
/*foo-head-after*/
</script>
HTML
				,
				'expected_torso'  => '',
				'expected_footer' => '',
			),
			'delayed_inline_script_in_footer_only' => array(
				'set_up'          => static function () use ( $enqueue_script, $add_inline_script ) {
					$enqueue_script( true );
					$add_inline_script( 'foo-footer' );
				},
				'expected_head'   => $this->get_delayed_inline_script_loader_script_tag(), // TODO: This script is getting output even though it isn't needed yet.
				'expected_torso'  => '',
				'expected_footer' => <<<HTML
<script id="foo-footer-js" src="https://example.com/foo-footer.js" type="text/javascript" defer></script>
<script id="foo-footer-js-after" type="text/plain">
/*foo-footer-after*/
</script>
HTML,
			),
			'delayed_inline_script_in_both_head_and_footer' => array(
				'set_up'          => static function () use ( $enqueue_script, $add_inline_script ) {
					foreach ( array( false, true ) as $in_footer ) {
						$enqueue_script( $in_footer );
						$add_inline_script( $in_footer ? 'foo-footer' : 'foo-head' );
					}
				},
				'expected_head'   => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="foo-head-js" src="https://example.com/foo-head.js" type="text/javascript" defer></script>
<script id="foo-head-js-after" type="text/plain">
/*foo-head-after*/
</script>
HTML
				,
				'expected_torso'  => '',
				'expected_footer' => <<<HTML
<script id="foo-footer-js" src="https://example.com/foo-footer.js" type="text/javascript" defer></script>
<script id="foo-footer-js-after" type="text/plain">
/*foo-footer-after*/
</script>
HTML
				,
			),
			'delayed_inline_script_enqueued_in_torso_for_footer' => array(
				'set_up'          => static function () use ( $enqueue_script, $add_inline_script ) {
					add_action(
						'test_torso',
						static function () use ( $enqueue_script, $add_inline_script ) {
							$enqueue_script( true );
							$add_inline_script( 'foo-footer' );
						}
					);
				},
				'expected_head'   => '',
				'expected_torso'  => '',
				'expected_footer' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="foo-footer-js" src="https://example.com/foo-footer.js" type="text/javascript" defer></script>
<script id="foo-footer-js-after" type="text/plain">
/*foo-footer-after*/
</script>
HTML
			,
			),
			'delayed_inline_printed_in_torso'      => array(
				'set_up'          => static function () use ( $enqueue_script, $add_inline_script ) {
					add_action(
						'test_torso',
						static function () use ( $enqueue_script, $add_inline_script ) {
							wp_register_script( 'foo-torso', 'https://example.com/foo-torso.js', array(), null, array( 'strategy' => 'defer' ) );
							$add_inline_script( 'foo-torso' );
							wp_print_scripts( array( 'foo-torso' ) );
						}
					);
				},
				'expected_head'   => '',
				'expected_torso'  => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="foo-torso-js" src="https://example.com/foo-torso.js" type="text/javascript" defer></script>
<script id="foo-torso-js-after" type="text/plain">
/*foo-torso-after*/
</script>
HTML
				,
				'expected_footer' => '',
			),
		);
	}

	/**
	 * Tests that wp_print_delayed_inline_script_loader() is output before the first delayed inline script and not
	 * duplicated in header and footer.
	 *
	 * @covers ::wp_print_delayed_inline_script_loader
	 * @covers WP_Scripts::print_delayed_inline_script_loader
	 *
	 * @dataProvider data_to_test_print_delayed_inline_script_loader_timing
	 * @param callable $set_up          Set up.
	 * @param string   $expected_head   Expected head.
	 * @param string   $expected_torso  Expected torso.
	 * @param string   $expected_footer Expected footer.
	 */
	public function test_print_delayed_inline_script_loader_timing( $set_up, $expected_head, $expected_torso, $expected_footer ) {
		$set_up();

		// Note that test_head, test_enqueue_scripts, and test_footer are used instead of their wp_* actions to avoid triggering core actions.
		add_action(
			'test_head',
			static function () {
				do_action( 'test_enqueue_scripts' );
			},
			1 // Priority corresponds to wp_head in default-filters.php.
		);
		add_action( 'test_head', 'wp_print_head_scripts', 9 ); // Priority corresponds to wp_head in default-filters.php.
		add_action( 'test_footer', 'wp_print_footer_scripts', 20 ); // Priority corresponds to wp_footer in default-filters.php.

		$actual_head   = get_echo( 'do_action', array( 'test_head' ) );
		$actual_torso  = get_echo( 'do_action', array( 'test_torso' ) );
		$actual_footer = get_echo( 'do_action', array( 'test_footer' ) );

		$delayed_script_count = substr_count( $actual_head . $actual_torso . $actual_footer, $this->get_delayed_inline_script_loader_script_tag() );
		if ( wp_scripts()->has_delayed_inline_script() ) {
			$this->assertSame( 1, $delayed_script_count, 'Expected delayed inline script to occur exactly once.' );
		} else {
			$this->assertSame( 0, $delayed_script_count, 'Expected delayed inline script to not occur since no delayed inline scripts.' );
		}

		$this->assertLessThanOrEqual( 1, $delayed_script_count, 'Expected delayed-inline-script-loader to occur at most once.' );

		$this->assertEqualMarkup( $actual_head, $expected_head, 'Expected head to match.' );
		$this->assertEqualMarkup( $actual_torso, $expected_torso, 'Expected torso to match.' );
		$this->assertEqualMarkup( $actual_footer, $expected_footer, 'Expected footer to match.' );
	}

	/**
	 * Test inline scripts in the `after` position with delayed main script.
	 *
	 * If the main script with delayed loading strategy has an `after` inline script,
	 * the inline script should not be affected.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_inline_script_tag
	 * @covers ::wp_print_delayed_inline_script_loader
	 * @covers WP_Scripts::print_delayed_inline_script_loader
	 * @covers ::wp_add_inline_script
	 * @covers ::wp_enqueue_script
	 *
	 * @dataProvider data_provider_delayed_strategies
	 * @param string $strategy Strategy.
	 */
	public function test_after_inline_script_with_delayed_main_script( $strategy ) {
		unregister_all_script_handles();
		wp_enqueue_script( 'ms-isa-1', 'http://example.org/ms-isa-1.js', array(), null, compact( 'strategy' ) );
		wp_add_inline_script( 'ms-isa-1', 'console.log("after one");', 'after' );
		$output    = get_echo( 'wp_print_scripts' );
		$expected  = $this->get_delayed_inline_script_loader_script_tag();
		$expected .= "<script type='text/javascript' src='http://example.org/ms-isa-1.js' id='ms-isa-1-js' {$strategy}></script>\n";
		$expected .= wp_get_inline_script_tag(
			"console.log(\"after one\");\n",
			array(
				'id'   => 'ms-isa-1-js-after',
				'type' => 'text/plain',
			)
		);
		$this->assertSame( $expected, $output, 'Inline scripts in the "after" position, that are attached to a deferred main script, are failing to print/execute.' );
	}

	/**
	 * Test inline scripts in the `after` position with blocking main script.
	 *
	 * If a main script with a `blocking` strategy has an `after` inline script,
	 * the inline script should be rendered as type='text/javascript'.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_inline_script_tag
	 * @covers ::wp_add_inline_script
	 * @covers ::wp_enqueue_script
	 */
	public function test_after_inline_script_with_blocking_main_script() {
		unregister_all_script_handles();
		wp_enqueue_script( 'ms-insa-3', 'http://example.org/ms-insa-3.js', array(), null );
		wp_add_inline_script( 'ms-insa-3', 'console.log("after one");', 'after' );
		$output = get_echo( 'wp_print_scripts' );

		$expected  = "<script type='text/javascript' src='http://example.org/ms-insa-3.js' id='ms-insa-3-js'></script>\n";
		$expected .= wp_get_inline_script_tag(
			"console.log(\"after one\");\n",
			array(
				'id' => 'ms-insa-3-js-after',
			)
		);

		$this->assertSame( $expected, $output, 'Inline scripts in the "after" position, that are attached to a blocking main script, are failing to print/execute.' );
	}

	/**
	 * Test `before` inline scripts attached to delayed main scripts.
	 *
	 * If the main script has a `before` inline script, all dependents still be delayed.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_inline_script_tag
	 * @covers ::wp_add_inline_script
	 * @covers ::wp_enqueue_script
	 *
	 * @dataProvider data_provider_delayed_strategies
	 * @param string $strategy
	 */
	public function test_before_inline_scripts_with_delayed_main_script( $strategy ) {
		unregister_all_script_handles();
		wp_enqueue_script( 'ds-i1-1', 'http://example.org/ds-i1-1.js', array(), null, compact( 'strategy' ) );
		wp_add_inline_script( 'ds-i1-1', 'console.log("before first");', 'before' );
		wp_enqueue_script( 'ds-i1-2', 'http://example.org/ds-i1-2.js', array(), null, compact( 'strategy' ) );
		wp_enqueue_script( 'ds-i1-3', 'http://example.org/ds-i1-3.js', array(), null, compact( 'strategy' ) );
		wp_enqueue_script( 'ms-i1-1', 'http://example.org/ms-i1-1.js', array( 'ds-i1-1', 'ds-i1-2', 'ds-i1-3' ), null, compact( 'strategy' ) );
		wp_add_inline_script( 'ms-i1-1', 'console.log("before last");', 'before' );
		$output = get_echo( 'wp_print_scripts' );

		$expected  = $this->get_delayed_inline_script_loader_script_tag();
		$expected .= wp_get_inline_script_tag(
			"console.log(\"before first\");\n",
			array(
				'id' => 'ds-i1-1-js-before',
			)
		);
		$expected .= "<script type='text/javascript' src='http://example.org/ds-i1-1.js' id='ds-i1-1-js' {$strategy}></script>\n";
		$expected .= "<script type='text/javascript' src='http://example.org/ds-i1-2.js' id='ds-i1-2-js' {$strategy}></script>\n";
		$expected .= "<script type='text/javascript' src='http://example.org/ds-i1-3.js' id='ds-i1-3-js' {$strategy}></script>\n";
		$expected .= wp_get_inline_script_tag(
			"console.log(\"before last\");\n",
			array(
				'id'           => 'ms-i1-1-js-before',
				'type'         => 'text/plain',
				'data-wp-deps' => 'ds-i1-1,ds-i1-2,ds-i1-3',
			)
		);
		$expected .= "<script type='text/javascript' src='http://example.org/ms-i1-1.js' id='ms-i1-1-js' {$strategy}></script>\n";

		$this->assertSame( $expected, $output, 'Inline scripts in the "before" position, that are attached to a deferred main script, are failing to print/execute.' );
	}

	/**
	 * Test valid async loading strategy case.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_eligible_loading_strategy
	 * @covers ::wp_enqueue_script
	 */
	public function test_loading_strategy_with_valid_async_registration() {
		// No dependents, No dependencies then async.
		wp_enqueue_script( 'main-script-a1', '/main-script-a1.js', array(), null, array( 'strategy' => 'async' ) );
		$output   = get_echo( 'wp_print_scripts' );
		$expected = "<script type='text/javascript' src='/main-script-a1.js' id='main-script-a1-js' async></script>\n";
		$this->assertSame( $expected, $output, 'Scripts enqueued with an async loading strategy are failing to have the async attribute applied to the script handle when being printed.' );
	}

	/**
	 * Test delayed dependent with blocking dependency.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_eligible_loading_strategy
	 * @covers ::wp_enqueue_script
	 *
	 * @dataProvider data_provider_delayed_strategies
	 * @param string $strategy Strategy.
	 */
	public function test_delayed_dependent_with_blocking_dependency( $strategy ) {
		wp_enqueue_script( 'dependency-script-a2', '/dependency-script-a2.js', array(), null );
		wp_enqueue_script( 'main-script-a2', '/main-script-a2.js', array( 'dependency-script-a2' ), null, compact( 'strategy' ) );
		$output   = get_echo( 'wp_print_scripts' );
		$expected = "<script type='text/javascript' src='/main-script-a2.js' id='main-script-a2-js' {$strategy}></script>";
		$this->assertStringContainsString( $expected, $output, 'Dependents of a blocking dependency are free to have any strategy.' );
	}

	/**
	 * Test delayed dependency with blocking dependent.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_eligible_loading_strategy
	 * @covers ::wp_enqueue_script
	 *
	 * @dataProvider data_provider_delayed_strategies
	 * @param string $strategy Strategy.
	 */
	public function test_blocking_dependent_with_delayed_dependency( $strategy ) {
		wp_enqueue_script( 'main-script-a3', '/main-script-a3.js', array(), null, compact( 'strategy' ) );
		wp_enqueue_script( 'dependent-script-a3', '/dependent-script-a3.js', array( 'main-script-a3' ), null );
		$output   = get_echo( 'wp_print_scripts' );
		$expected = "<script type='text/javascript' src='/main-script-a3.js' id='main-script-a3-js'></script>";
		$this->assertStringContainsString( $expected, $output, 'Blocking dependents must force delayed dependencies to become blocking.' );
	}

	/**
	 * Test delayed dependency with non enqueued blocking dependent.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_eligible_loading_strategy
	 * @covers ::wp_enqueue_script
	 *
	 * @dataProvider data_provider_delayed_strategies
	 * @param string $strategy Strategy.
	 */
	public function test_delayed_dependent_with_blocking_dependency_not_enqueued( $strategy ) {
		wp_enqueue_script( 'main-script-a4', '/main-script-a4.js', array(), null, compact( 'strategy' ) );
		// This dependent is registered but not enqueued, so it should not factor into the eligible loading strategy.
		wp_register_script( 'dependent-script-a4', '/dependent-script-a4.js', array( 'main-script-a4' ), null );
		$output   = get_echo( 'wp_print_scripts' );
		$expected = "<script type='text/javascript' src='/main-script-a4.js' id='main-script-a4-js' {$strategy}></script>";
		$this->assertStringContainsString( $expected, $output, 'Only enqueued dependents should affect the eligible strategy.' );
	}

	/**
	 * Enqueue test script with before/after inline scripts.
	 *
	 * @param string   $handle    Dependency handle to enqueue.
	 * @param string   $strategy  Strategy to use for dependency.
	 * @param string[] $deps      Dependencies for the script.
	 * @param bool     $in_footer Whether to print the script in the footer.
	 */
	protected function enqueue_test_script( $handle, $strategy, $deps = array(), $in_footer = false ) {
		wp_enqueue_script(
			$handle,
			add_query_arg(
				array(
					'script_event_log' => "$handle: script",
				),
				'https://example.com/external.js'
			),
			$deps,
			null
		);
		if ( 'blocking' !== $strategy ) {
			wp_script_add_data( $handle, 'strategy', $strategy );
		}
	}

	/**
	 * Adds test inline script.
	 *
	 * @param string $handle   Dependency handle to enqueue.
	 * @param string $position Position.
	 */
	protected function add_test_inline_script( $handle, $position ) {
		wp_add_inline_script( $handle, sprintf( 'scriptEventLog.push( %s )', wp_json_encode( "{$handle}: {$position} inline" ) ), $position );
	}

	/**
	 * Data provider to test various strategy dependency chains.
	 *
	 * @return array[]
	 */
	public function data_provider_to_test_various_strategy_dependency_chains() {
		return array(
			'async-dependent-with-one-blocking-dependency' => array(
				'set_up'          => function () {
					$handle1 = 'blocking-not-async-without-dependency';
					$handle2 = 'async-with-blocking-dependency';
					$this->enqueue_test_script( $handle1, 'blocking', array() );
					$this->enqueue_test_script( $handle2, 'async', array( $handle1 ) );
					foreach ( array( $handle1, $handle2 ) as $handle ) {
						$this->add_test_inline_script( $handle, 'before' );
						$this->add_test_inline_script( $handle, 'after' );
					}
				},
				'expected_markup' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="blocking-not-async-without-dependency-js-before" type="text/javascript">
scriptEventLog.push( "blocking-not-async-without-dependency: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=blocking-not-async-without-dependency:%20script' id='blocking-not-async-without-dependency-js'></script>
<script id="blocking-not-async-without-dependency-js-after" type="text/javascript">
scriptEventLog.push( "blocking-not-async-without-dependency: after inline" )
</script>
<script id="async-with-blocking-dependency-js-before" type="text/javascript">
scriptEventLog.push( "async-with-blocking-dependency: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=async-with-blocking-dependency:%20script' id='async-with-blocking-dependency-js' async></script>
<script id="async-with-blocking-dependency-js-after" type="text/plain" data-wp-deps="blocking-not-async-without-dependency">
scriptEventLog.push( "async-with-blocking-dependency: after inline" )
</script>
HTML
				,
				/*
				 * Note: The above comma must be on its own line in PHP<7.3 and not after the `HTML` identifier
				 * terminating the heredoc. Otherwise, a syntax error is raised with the line number being wildly wrong:
				 *
				 * PHP Parse error:  syntax error, unexpected '' (T_ENCAPSED_AND_WHITESPACE), expecting '-' or identifier (T_STRING) or variable (T_VARIABLE) or number (T_NUM_STRING)
				 */
			),
			'async-with-async-dependencies'                => array(
				'set_up'          => function () {
					$handle1 = 'async-no-dependency';
					$handle2 = 'async-one-async-dependency';
					$handle3 = 'async-two-async-dependencies';
					$this->enqueue_test_script( $handle1, 'async', array() );
					$this->enqueue_test_script( $handle2, 'async', array( $handle1 ) );
					$this->enqueue_test_script( $handle3, 'async', array( $handle1, $handle2 ) );
					foreach ( array( $handle1, $handle2, $handle3 ) as $handle ) {
						$this->add_test_inline_script( $handle, 'before' );
						$this->add_test_inline_script( $handle, 'after' );
					}
				},
				'expected_markup' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="async-no-dependency-js-before" type="text/javascript">
scriptEventLog.push( "async-no-dependency: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=async-no-dependency:%20script' id='async-no-dependency-js' async></script>
<script id="async-no-dependency-js-after" type="text/plain">
scriptEventLog.push( "async-no-dependency: after inline" )
</script>
<script id="async-one-async-dependency-js-before" type="text/plain" data-wp-deps="async-no-dependency">
scriptEventLog.push( "async-one-async-dependency: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=async-one-async-dependency:%20script' id='async-one-async-dependency-js' async></script>
<script id="async-one-async-dependency-js-after" type="text/plain" data-wp-deps="async-no-dependency">
scriptEventLog.push( "async-one-async-dependency: after inline" )
</script>
<script id="async-two-async-dependencies-js-before" type="text/plain" data-wp-deps="async-no-dependency,async-one-async-dependency">
scriptEventLog.push( "async-two-async-dependencies: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=async-two-async-dependencies:%20script' id='async-two-async-dependencies-js' async></script>
<script id="async-two-async-dependencies-js-after" type="text/plain" data-wp-deps="async-no-dependency,async-one-async-dependency">
scriptEventLog.push( "async-two-async-dependencies: after inline" )
</script>
HTML
				,
			),
			'async-with-blocking-dependency'               => array(
				'set_up'          => function () {
					$handle1 = 'async-with-blocking-dependent';
					$handle2 = 'blocking-dependent-of-async';
					$this->enqueue_test_script( $handle1, 'async', array() );
					$this->enqueue_test_script( $handle2, 'blocking', array( $handle1 ) );
					foreach ( array( $handle1, $handle2 ) as $handle ) {
						$this->add_test_inline_script( $handle, 'before' );
						$this->add_test_inline_script( $handle, 'after' );
					}
				},
				'expected_markup' => <<<HTML
<script id="async-with-blocking-dependent-js-before" type="text/javascript">
scriptEventLog.push( "async-with-blocking-dependent: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=async-with-blocking-dependent:%20script' id='async-with-blocking-dependent-js'></script>
<script id="async-with-blocking-dependent-js-after" type="text/javascript">
scriptEventLog.push( "async-with-blocking-dependent: after inline" )
</script>
<script id="blocking-dependent-of-async-js-before" type="text/javascript">
scriptEventLog.push( "blocking-dependent-of-async: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=blocking-dependent-of-async:%20script' id='blocking-dependent-of-async-js'></script>
<script id="blocking-dependent-of-async-js-after" type="text/javascript">
scriptEventLog.push( "blocking-dependent-of-async: after inline" )
</script>
HTML
				,
			),
			'defer-with-async-dependency'                  => array(
				'set_up'          => function () {
					$handle1 = 'async-with-defer-dependent';
					$handle2 = 'defer-dependent-of-async';
					$this->enqueue_test_script( $handle1, 'async', array() );
					$this->enqueue_test_script( $handle2, 'defer', array( $handle1 ) );
					foreach ( array( $handle1, $handle2 ) as $handle ) {
						$this->add_test_inline_script( $handle, 'before' );
						$this->add_test_inline_script( $handle, 'after' );
					}
				},
				'expected_markup' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="async-with-defer-dependent-js-before" type="text/javascript">
scriptEventLog.push( "async-with-defer-dependent: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=async-with-defer-dependent:%20script' id='async-with-defer-dependent-js' defer></script>
<script id="async-with-defer-dependent-js-after" type="text/plain">
scriptEventLog.push( "async-with-defer-dependent: after inline" )
</script>
<script id="defer-dependent-of-async-js-before" type="text/plain" data-wp-deps="async-with-defer-dependent">
scriptEventLog.push( "defer-dependent-of-async: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-dependent-of-async:%20script' id='defer-dependent-of-async-js' defer></script>
<script id="defer-dependent-of-async-js-after" type="text/plain" data-wp-deps="async-with-defer-dependent">
scriptEventLog.push( "defer-dependent-of-async: after inline" )
</script>
HTML
				,
			),
			'blocking-bundle-of-none-with-inline-scripts-and-defer-dependent' => array(
				'set_up'          => function () {
					$handle1 = 'blocking-bundle-of-none';
					$handle2 = 'defer-dependent-of-blocking-bundle-of-none';

					// Note that jQuery is registered like this.
					wp_register_script( $handle1, false, array(), null );
					$this->add_test_inline_script( $handle1, 'before' );
					$this->add_test_inline_script( $handle1, 'after' );

					// Note: the before script for this will be blocking because the dependency is blocking.
					$this->enqueue_test_script( $handle2, 'defer', array( $handle1 ) );
					$this->add_test_inline_script( $handle2, 'before' );
					$this->add_test_inline_script( $handle2, 'after' );
				},
				'expected_markup' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="blocking-bundle-of-none-js-before" type="text/javascript">
scriptEventLog.push( "blocking-bundle-of-none: before inline" )
</script>
<script id="blocking-bundle-of-none-js-after" type="text/javascript">
scriptEventLog.push( "blocking-bundle-of-none: after inline" )
</script>
<script id="defer-dependent-of-blocking-bundle-of-none-js-before" type="text/javascript">
scriptEventLog.push( "defer-dependent-of-blocking-bundle-of-none: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-dependent-of-blocking-bundle-of-none:%20script' id='defer-dependent-of-blocking-bundle-of-none-js' defer></script>
<script id="defer-dependent-of-blocking-bundle-of-none-js-after" type="text/plain" data-wp-deps="blocking-bundle-of-none">
scriptEventLog.push( "defer-dependent-of-blocking-bundle-of-none: after inline" )
</script>
HTML
				,
			),
			'blocking-bundle-of-two-with-defer-dependent'  => array(
				'set_up'          => function () {
					$handle1 = 'blocking-bundle-of-two';
					$handle2 = 'blocking-bundle-member-one';
					$handle3 = 'blocking-bundle-member-two';
					$handle4 = 'defer-dependent-of-blocking-bundle-of-two';

					wp_register_script( $handle1, false, array(), null );
					$this->enqueue_test_script( $handle2, 'blocking', array( $handle1 ) );
					$this->enqueue_test_script( $handle3, 'blocking', array( $handle1 ) );
					$this->enqueue_test_script( $handle4, 'defer', array( $handle1 ) );

					foreach ( array( $handle2, $handle3, $handle4 ) as $handle ) {
						$this->add_test_inline_script( $handle, 'before' );
						$this->add_test_inline_script( $handle, 'after' );
					}
				},
				'expected_markup' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="blocking-bundle-member-one-js-before" type="text/javascript">
scriptEventLog.push( "blocking-bundle-member-one: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=blocking-bundle-member-one:%20script' id='blocking-bundle-member-one-js'></script>
<script id="blocking-bundle-member-one-js-after" type="text/javascript">
scriptEventLog.push( "blocking-bundle-member-one: after inline" )
</script>
<script id="blocking-bundle-member-two-js-before" type="text/javascript">
scriptEventLog.push( "blocking-bundle-member-two: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=blocking-bundle-member-two:%20script' id='blocking-bundle-member-two-js'></script>
<script id="blocking-bundle-member-two-js-after" type="text/javascript">
scriptEventLog.push( "blocking-bundle-member-two: after inline" )
</script>
<script id="defer-dependent-of-blocking-bundle-of-two-js-before" type="text/javascript">
scriptEventLog.push( "defer-dependent-of-blocking-bundle-of-two: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-dependent-of-blocking-bundle-of-two:%20script' id='defer-dependent-of-blocking-bundle-of-two-js' defer></script>
<script id="defer-dependent-of-blocking-bundle-of-two-js-after" type="text/plain" data-wp-deps="blocking-bundle-of-two">
scriptEventLog.push( "defer-dependent-of-blocking-bundle-of-two: after inline" )
</script>
HTML
				,
			),
			'defer-bundle-of-none-with-inline-scripts-and-defer-dependents' => array(
				'set_up'          => function () {
					$handle1 = 'defer-bundle-of-none';
					$handle2 = 'defer-dependent-of-defer-bundle-of-none';

					// The eligible loading strategy for this will be forced to be blocking when rendered since $src = false.
					wp_register_script( $handle1, false, array(), null );
					wp_scripts()->registered[ $handle1 ]->extra['strategy'] = 'defer'; // Bypass wp_script_add_data() which should no-op with _doing_it_wrong() because of $src=false.
					$this->add_test_inline_script( $handle1, 'before' );
					$this->add_test_inline_script( $handle1, 'after' );

					// Note: the before script for this will be blocking because the dependency is blocking.
					$this->enqueue_test_script( $handle2, 'defer', array( $handle1 ) );
					$this->add_test_inline_script( $handle2, 'before' );
					$this->add_test_inline_script( $handle2, 'after' );
				},
				'expected_markup' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="defer-bundle-of-none-js-before" type="text/javascript">
scriptEventLog.push( "defer-bundle-of-none: before inline" )
</script>
<script id="defer-bundle-of-none-js-after" type="text/javascript">
scriptEventLog.push( "defer-bundle-of-none: after inline" )
</script>
<script id="defer-dependent-of-defer-bundle-of-none-js-before" type="text/javascript">
scriptEventLog.push( "defer-dependent-of-defer-bundle-of-none: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-dependent-of-defer-bundle-of-none:%20script' id='defer-dependent-of-defer-bundle-of-none-js' defer></script>
<script id="defer-dependent-of-defer-bundle-of-none-js-after" type="text/plain" data-wp-deps="defer-bundle-of-none">
scriptEventLog.push( "defer-dependent-of-defer-bundle-of-none: after inline" )
</script>
HTML
				,
			),
			'defer-dependent-with-blocking-and-defer-dependencies' => array(
				'set_up'          => function () {
					$handle1 = 'blocking-dependency-with-defer-following-dependency';
					$handle2 = 'defer-dependency-with-blocking-preceding-dependency';
					$handle3 = 'defer-dependent-of-blocking-and-defer-dependencies';
					$this->enqueue_test_script( $handle1, 'blocking', array() );
					$this->enqueue_test_script( $handle2, 'defer', array() );
					$this->enqueue_test_script( $handle3, 'defer', array( $handle1, $handle2 ) );

					foreach ( array( $handle1, $handle2, $handle3 ) as $dep ) {
						$this->add_test_inline_script( $dep, 'before' );
						$this->add_test_inline_script( $dep, 'after' );
					}
				},
				'expected_markup' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="blocking-dependency-with-defer-following-dependency-js-before" type="text/javascript">
scriptEventLog.push( "blocking-dependency-with-defer-following-dependency: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=blocking-dependency-with-defer-following-dependency:%20script' id='blocking-dependency-with-defer-following-dependency-js'></script>
<script id="blocking-dependency-with-defer-following-dependency-js-after" type="text/javascript">
scriptEventLog.push( "blocking-dependency-with-defer-following-dependency: after inline" )
</script>
<script id="defer-dependency-with-blocking-preceding-dependency-js-before" type="text/javascript">
scriptEventLog.push( "defer-dependency-with-blocking-preceding-dependency: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-dependency-with-blocking-preceding-dependency:%20script' id='defer-dependency-with-blocking-preceding-dependency-js' defer></script>
<script id="defer-dependency-with-blocking-preceding-dependency-js-after" type="text/plain">
scriptEventLog.push( "defer-dependency-with-blocking-preceding-dependency: after inline" )
</script>
<script id="defer-dependent-of-blocking-and-defer-dependencies-js-before" type="text/plain" data-wp-deps="blocking-dependency-with-defer-following-dependency,defer-dependency-with-blocking-preceding-dependency">
scriptEventLog.push( "defer-dependent-of-blocking-and-defer-dependencies: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-dependent-of-blocking-and-defer-dependencies:%20script' id='defer-dependent-of-blocking-and-defer-dependencies-js' defer></script>
<script id="defer-dependent-of-blocking-and-defer-dependencies-js-after" type="text/plain" data-wp-deps="blocking-dependency-with-defer-following-dependency,defer-dependency-with-blocking-preceding-dependency">
scriptEventLog.push( "defer-dependent-of-blocking-and-defer-dependencies: after inline" )
</script>
HTML
				,
			),
			'defer-dependent-with-defer-and-blocking-dependencies' => array(
				'set_up'          => function () {
					$handle1 = 'defer-dependency-with-blocking-following-dependency';
					$handle2 = 'blocking-dependency-with-defer-preceding-dependency';
					$handle3 = 'defer-dependent-of-defer-and-blocking-dependencies';
					$this->enqueue_test_script( $handle1, 'defer', array() );
					$this->enqueue_test_script( $handle2, 'blocking', array() );
					$this->enqueue_test_script( $handle3, 'defer', array( $handle1, $handle2 ) );

					foreach ( array( $handle1, $handle2, $handle3 ) as $dep ) {
						$this->add_test_inline_script( $dep, 'before' );
						$this->add_test_inline_script( $dep, 'after' );
					}
				},
				'expected_markup' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="defer-dependency-with-blocking-following-dependency-js-before" type="text/javascript">
scriptEventLog.push( "defer-dependency-with-blocking-following-dependency: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-dependency-with-blocking-following-dependency:%20script' id='defer-dependency-with-blocking-following-dependency-js' defer></script>
<script id="defer-dependency-with-blocking-following-dependency-js-after" type="text/plain">
scriptEventLog.push( "defer-dependency-with-blocking-following-dependency: after inline" )
</script>
<script id="blocking-dependency-with-defer-preceding-dependency-js-before" type="text/javascript">
scriptEventLog.push( "blocking-dependency-with-defer-preceding-dependency: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=blocking-dependency-with-defer-preceding-dependency:%20script' id='blocking-dependency-with-defer-preceding-dependency-js'></script>
<script id="blocking-dependency-with-defer-preceding-dependency-js-after" type="text/javascript">
scriptEventLog.push( "blocking-dependency-with-defer-preceding-dependency: after inline" )
</script>
<script id="defer-dependent-of-defer-and-blocking-dependencies-js-before" type="text/plain" data-wp-deps="defer-dependency-with-blocking-following-dependency,blocking-dependency-with-defer-preceding-dependency">
scriptEventLog.push( "defer-dependent-of-defer-and-blocking-dependencies: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-dependent-of-defer-and-blocking-dependencies:%20script' id='defer-dependent-of-defer-and-blocking-dependencies-js' defer></script>
<script id="defer-dependent-of-defer-and-blocking-dependencies-js-after" type="text/plain" data-wp-deps="defer-dependency-with-blocking-following-dependency,blocking-dependency-with-defer-preceding-dependency">
scriptEventLog.push( "defer-dependent-of-defer-and-blocking-dependencies: after inline" )
</script>
HTML
				,
			),
			'async-with-defer-dependency'                  => array(
				'set_up'          => function () {
					$handle1 = 'defer-with-async-dependent';
					$handle2 = 'async-dependent-of-defer';
					$this->enqueue_test_script( $handle1, 'defer', array() );
					$this->enqueue_test_script( $handle2, 'async', array( $handle1 ) );
					foreach ( array( $handle1, $handle2 ) as $handle ) {
						$this->add_test_inline_script( $handle, 'before' );
						$this->add_test_inline_script( $handle, 'after' );
					}
				},
				'expected_markup' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script id="defer-with-async-dependent-js-before" type="text/javascript">
scriptEventLog.push( "defer-with-async-dependent: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-with-async-dependent:%20script' id='defer-with-async-dependent-js' defer></script>
<script id="defer-with-async-dependent-js-after" type="text/plain">
scriptEventLog.push( "defer-with-async-dependent: after inline" )
</script>
<script id="async-dependent-of-defer-js-before" type="text/plain" data-wp-deps="defer-with-async-dependent">
scriptEventLog.push( "async-dependent-of-defer: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=async-dependent-of-defer:%20script' id='async-dependent-of-defer-js' async></script>
<script id="async-dependent-of-defer-js-after" type="text/plain" data-wp-deps="defer-with-async-dependent">
scriptEventLog.push( "async-dependent-of-defer: after inline" )
</script>
HTML
				,
			),
			'defer-with-before-inline-script'              => array(
				'set_up'          => function () {
					// Note this should NOT result in no delayed-inline-script-loader script being added.
					$handle = 'defer-with-before-inline';
					$this->enqueue_test_script( $handle, 'defer', array() );
					$this->add_test_inline_script( $handle, 'before' );
				},
				'expected_markup' => <<<HTML
<script id="defer-with-before-inline-js-before" type="text/javascript">
scriptEventLog.push( "defer-with-before-inline: before inline" )
</script>
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-with-before-inline:%20script' id='defer-with-before-inline-js' defer></script>
HTML
				,
			),
			'defer-with-after-inline-script'               => array(
				'set_up'          => function () {
					// Note this SHOULD result in delayed-inline-script-loader script being added.
					$handle = 'defer-with-after-inline';
					$this->enqueue_test_script( $handle, 'defer', array() );
					$this->add_test_inline_script( $handle, 'after' );
				},
				'expected_markup' => $this->get_delayed_inline_script_loader_script_tag() . <<<HTML
<script type='text/javascript' src='https://example.com/external.js?script_event_log=defer-with-after-inline:%20script' id='defer-with-after-inline-js' defer></script>
<script id="defer-with-after-inline-js-after" type="text/plain">
scriptEventLog.push( "defer-with-after-inline: after inline" )
</script>
HTML
				,
			),
		);
	}

	/**
	 * Test various strategy dependency chains.
	 *
	 * @covers ::wp_enqueue_script()
	 * @covers ::wp_add_inline_script()
	 * @covers ::wp_print_scripts()
	 * @covers WP_Scripts::should_delay_inline_script
	 * @covers WP_Scripts::get_inline_script_tag
	 * @covers WP_Scripts::has_delayed_inline_script
	 *
	 * @dataProvider data_provider_to_test_various_strategy_dependency_chains
	 * @param callable $set_up          Set up.
	 * @param string   $expected_markup Expected markup.
	 */
	public function test_various_strategy_dependency_chains( $set_up, $expected_markup ) {
		$set_up();
		$actual_markup = get_echo( 'wp_print_scripts' );
		$this->assertEqualMarkup( trim( $expected_markup ), trim( $actual_markup ), "Actual markup:\n{$actual_markup}" );
	}

	/**
	 * Test defer strategy when there are no dependents and no dependencies.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_eligible_loading_strategy
	 * @covers ::wp_enqueue_script
	 */
	public function test_loading_strategy_with_defer_having_no_dependents_nor_dependencies() {
		wp_enqueue_script( 'main-script-d1', 'http://example.com/main-script-d1.js', array(), null, array( 'strategy' => 'defer' ) );
		$output   = get_echo( 'wp_print_scripts' );
		$expected = "<script type='text/javascript' src='http://example.com/main-script-d1.js' id='main-script-d1-js' defer></script>\n";
		$this->assertStringContainsString( $expected, $output, 'Expected defer, as there is no dependent or dependency' );
	}

	/**
	 * Test that main script is defer and all dependencies are either defer/blocking.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_eligible_loading_strategy
	 * @covers ::wp_enqueue_script
	 */
	public function test_loading_strategy_with_defer_dependent_and_varied_dependencies() {
		wp_enqueue_script( 'dependency-script-d2-1', 'http://example.com/dependency-script-d2-1.js', array(), null, array( 'strategy' => 'defer' ) );
		wp_enqueue_script( 'dependency-script-d2-2', 'http://example.com/dependency-script-d2-2.js', array(), null );
		wp_enqueue_script( 'dependency-script-d2-3', 'http://example.com/dependency-script-d2-3.js', array( 'dependency-script-d2-2' ), null, array( 'strategy' => 'defer' ) );
		wp_enqueue_script( 'main-script-d2', 'http://example.com/main-script-d2.js', array( 'dependency-script-d2-1', 'dependency-script-d2-3' ), null, array( 'strategy' => 'defer' ) );
		$output   = get_echo( 'wp_print_scripts' );
		$expected = "<script type='text/javascript' src='http://example.com/main-script-d2.js' id='main-script-d2-js' defer></script>\n";
		$this->assertStringContainsString( $expected, $output, 'Expected defer, as all dependencies are either deferred or blocking' );
	}

	/**
	 * Test that dependency remains defer when it has defer dependents.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_eligible_loading_strategy
	 * @covers ::wp_enqueue_script
	 */
	public function test_loading_strategy_with_all_defer_dependencies() {
		wp_enqueue_script( 'main-script-d3', 'http://example.com/main-script-d3.js', array(), null, array( 'strategy' => 'defer' ) );
		wp_enqueue_script( 'dependent-script-d3-1', 'http://example.com/dependent-script-d3-1.js', array( 'main-script-d3' ), null, array( 'strategy' => 'defer' ) );
		wp_enqueue_script( 'dependent-script-d3-2', 'http://example.com/dependent-script-d3-2.js', array( 'dependent-script-d3-1' ), null, array( 'strategy' => 'defer' ) );
		wp_enqueue_script( 'dependent-script-d3-3', 'http://example.com/dependent-script-d3-3.js', array( 'dependent-script-d3-2' ), null, array( 'strategy' => 'defer' ) );
		$output   = get_echo( 'wp_print_scripts' );
		$expected = "<script type='text/javascript' src='http://example.com/main-script-d3.js' id='main-script-d3-js' defer></script>\n";
		$this->assertStringContainsString( $expected, $output, 'Expected defer, as all dependents have defer loading strategy' );
	}

	/**
	 * Test valid defer loading with async dependent.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_eligible_loading_strategy
	 * @covers ::wp_enqueue_script
	 */
	public function test_defer_with_async_dependent() {
		// case with one async dependent.
		wp_enqueue_script( 'main-script-d4', '/main-script-d4.js', array(), null, array( 'strategy' => 'defer' ) );
		wp_enqueue_script( 'dependent-script-d4-1', '/dependent-script-d4-1.js', array( 'main-script-d4' ), null, array( 'strategy' => 'defer' ) );
		wp_enqueue_script( 'dependent-script-d4-2', '/dependent-script-d4-2.js', array( 'dependent-script-d4-1' ), null, array( 'strategy' => 'async' ) );
		wp_enqueue_script( 'dependent-script-d4-3', '/dependent-script-d4-3.js', array( 'dependent-script-d4-2' ), null, array( 'strategy' => 'defer' ) );
		$output    = get_echo( 'wp_print_scripts' );
		$expected  = "<script type='text/javascript' src='/main-script-d4.js' id='main-script-d4-js' defer></script>\n";
		$expected .= "<script type='text/javascript' src='/dependent-script-d4-1.js' id='dependent-script-d4-1-js' defer></script>\n";
		$expected .= "<script type='text/javascript' src='/dependent-script-d4-2.js' id='dependent-script-d4-2-js' defer></script>\n";
		$expected .= "<script type='text/javascript' src='/dependent-script-d4-3.js' id='dependent-script-d4-3-js' defer></script>\n";

		$this->assertSame( $expected, $output, 'Scripts registered as defer but that have dependents that are async are expected to have said dependents deferred.' );
	}

	/**
	 * Test invalid defer loading strategy case.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_eligible_loading_strategy
	 * @covers ::wp_enqueue_script
	 */
	public function test_loading_strategy_with_invalid_defer_registration() {
		// Main script is defer and all dependent are not defer. Then main script will have blocking(or no) strategy.
		wp_enqueue_script( 'main-script-d4', '/main-script-d4.js', array(), null, array( 'strategy' => 'defer' ) );
		wp_enqueue_script( 'dependent-script-d4-1', '/dependent-script-d4-1.js', array( 'main-script-d4' ), null, array( 'strategy' => 'defer' ) );
		wp_enqueue_script( 'dependent-script-d4-2', '/dependent-script-d4-2.js', array( 'dependent-script-d4-1' ), null );
		wp_enqueue_script( 'dependent-script-d4-3', '/dependent-script-d4-3.js', array( 'dependent-script-d4-2' ), null, array( 'strategy' => 'defer' ) );
		$output   = get_echo( 'wp_print_scripts' );
		$expected = "<script type='text/javascript' src='/main-script-d4.js' id='main-script-d4-js'></script>\n";
		$this->assertStringContainsString( $expected, $output, 'Scripts registered as defer but that have all dependents with no strategy, should become blocking (no strategy).' );
	}

	/**
	 * Test valid blocking loading strategy cases.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers WP_Scripts::get_eligible_loading_strategy
	 * @covers ::wp_enqueue_script
	 */
	public function test_loading_strategy_with_valid_blocking_registration() {
		wp_enqueue_script( 'main-script-b1', '/main-script-b1.js', array(), null );
		$output   = get_echo( 'wp_print_scripts' );
		$expected = "<script type='text/javascript' src='/main-script-b1.js' id='main-script-b1-js'></script>\n";
		$this->assertSame( $expected, $output, 'Scripts registered with a "blocking" strategy, and who have no dependencies, should have no loading strategy attributes printed.' );

		// strategy args not set.
		wp_enqueue_script( 'main-script-b2', '/main-script-b2.js', array(), null, array() );
		$output   = get_echo( 'wp_print_scripts' );
		$expected = "<script type='text/javascript' src='/main-script-b2.js' id='main-script-b2-js'></script>\n";
		$this->assertSame( $expected, $output, 'Scripts registered with no strategy assigned, and who have no dependencies, should have no loading strategy attributes printed.' );
	}

	/**
	 * Test that scripts registered for the head do indeed end up there.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers ::wp_enqueue_script
	 * @covers ::wp_register_script
	 */
	public function test_scripts_targeting_head() {
		wp_register_script( 'header-old', '/header-old.js', array(), null, false );
		wp_register_script( 'header-new', '/header-new.js', array( 'header-old' ), null, array( 'in_footer' => false ) );
		wp_enqueue_script( 'enqueue-header-old', '/enqueue-header-old.js', array( 'header-new' ), null, false );
		wp_enqueue_script( 'enqueue-header-new', '/enqueue-header-new.js', array( 'enqueue-header-old' ), null, array( 'in_footer' => false ) );

		$actual_header = get_echo( 'wp_print_head_scripts' );
		$actual_footer = get_echo( 'wp_print_scripts' );

		$expected_header  = "<script type='text/javascript' src='/header-old.js' id='header-old-js'></script>\n";
		$expected_header .= "<script type='text/javascript' src='/header-new.js' id='header-new-js'></script>\n";
		$expected_header .= "<script type='text/javascript' src='/enqueue-header-old.js' id='enqueue-header-old-js'></script>\n";
		$expected_header .= "<script type='text/javascript' src='/enqueue-header-new.js' id='enqueue-header-new-js'></script>\n";

		$this->assertSame( $expected_header, $actual_header, 'Scripts registered/enqueued using the older $in_footer parameter or the newer $args parameter should have the same outcome.' );
		$this->assertEmpty( $actual_footer, 'Expected footer to be empty since all scripts were for head.' );
	}

	/**
	 * Test that scripts registered for the footer do indeed end up there.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers ::wp_enqueue_script
	 * @covers ::wp_register_script
	 */
	public function test_scripts_targeting_footer() {
		wp_register_script( 'footer-old', '/footer-old.js', array(), null, true );
		wp_register_script( 'footer-new', '/footer-new.js', array( 'footer-old' ), null, array( 'in_footer' => true ) );
		wp_enqueue_script( 'enqueue-footer-old', '/enqueue-footer-old.js', array( 'footer-new' ), null, true );
		wp_enqueue_script( 'enqueue-footer-new', '/enqueue-footer-new.js', array( 'enqueue-footer-old' ), null, array( 'in_footer' => true ) );

		$actual_header = get_echo( 'wp_print_head_scripts' );
		$actual_footer = get_echo( 'wp_print_scripts' );

		$expected_footer  = "<script type='text/javascript' src='/footer-old.js' id='footer-old-js'></script>\n";
		$expected_footer .= "<script type='text/javascript' src='/footer-new.js' id='footer-new-js'></script>\n";
		$expected_footer .= "<script type='text/javascript' src='/enqueue-footer-old.js' id='enqueue-footer-old-js'></script>\n";
		$expected_footer .= "<script type='text/javascript' src='/enqueue-footer-new.js' id='enqueue-footer-new-js'></script>\n";

		$this->assertEmpty( $actual_header, 'Expected header to be empty since all scripts targeted footer.' );
		$this->assertSame( $expected_footer, $actual_footer, 'Scripts registered/enqueued using the older $in_footer parameter or the newer $args parameter should have the same outcome.' );
	}

	/**
	 * Data provider for test_setting_in_footer_and_strategy.
	 *
	 * @return array[]
	 */
	public function get_data_for_test_setting_in_footer_and_strategy() {
		return array(
			// Passing in_footer and strategy via args array.
			'async_footer_in_args_array'    => array(
				'set_up'   => static function ( $handle ) {
					$args = array(
						'in_footer' => true,
						'strategy'  => 'async',
					);
					wp_enqueue_script( $handle, '/footer-async.js', array(), null, $args );
				},
				'group'    => 1,
				'strategy' => 'async',
			),

			// Passing in_footer=true but no strategy.
			'blocking_footer_in_args_array' => array(
				'set_up'   => static function ( $handle ) {
					wp_register_script( $handle, '/defaults.js', array(), null, array( 'in_footer' => true ) );
				},
				'group'    => 1,
				'strategy' => false,
			),

			// Passing async strategy in script args array.
			'async_in_args_array'           => array(
				'set_up'   => static function ( $handle ) {
					wp_register_script( $handle, '/defaults.js', array(), null, array( 'strategy' => 'async' ) );
				},
				'group'    => false,
				'strategy' => 'async',
			),

			// Passing empty array as 5th arg.
			'empty_args_array'              => array(
				'set_up'   => static function ( $handle ) {
					wp_register_script( $handle, '/defaults.js', array(), null, array() );
				},
				'group'    => false,
				'strategy' => false,
			),

			// Passing no value as 5th arg.
			'undefined_args_param'          => array(
				'set_up'   => static function ( $handle ) {
					wp_register_script( $handle, '/defaults.js', array(), null );
				},
				'group'    => false,
				'strategy' => false,
			),

			// Test backward compatibility, passing $in_footer=true as 5th arg.
			'passing_bool_as_args_param'    => array(
				'set_up'   => static function ( $handle ) {
					wp_enqueue_script( $handle, '/footer-async.js', array(), null, true );
				},
				'group'    => 1,
				'strategy' => false,
			),

			// Test backward compatibility, passing $in_footer=true as 5th arg and setting strategy via wp_script_add_data().
			'bool_as_args_and_add_data'     => array(
				'set_up'   => static function ( $handle ) {
					wp_register_script( $handle, '/footer-async.js', array(), null, true );
					wp_script_add_data( $handle, 'strategy', 'defer' );
				},
				'group'    => 1,
				'strategy' => 'defer',
			),
		);
	}

	/**
	 * Test setting in_footer and strategy.
	 *
	 * @dataProvider get_data_for_test_setting_in_footer_and_strategy
	 * @ticket 12009
	 * @covers ::wp_register_script
	 * @covers ::wp_enqueue_script
	 * @covers ::wp_script_add_data
	 *
	 * @param callable     $set_up            Set up.
	 * @param int|false    $expected_group    Expected group.
	 * @param string|false $expected_strategy Expected strategy.
	 */
	public function test_setting_in_footer_and_strategy( $set_up, $expected_group, $expected_strategy ) {
		$handle = 'foo';
		$set_up( $handle );
		$this->assertSame( $expected_group, wp_scripts()->get_data( $handle, 'group' ) );
		$this->assertSame( $expected_strategy, wp_scripts()->get_data( $handle, 'strategy' ) );
	}

	/**
	 * Test script strategy doing it wrong when calling wp_register_script().
	 *
	 * For an invalid strategy defined during script registration, default to a blocking strategy.
	 *
	 * @covers WP_Scripts::add_data
	 * @covers ::wp_register_script
	 * @covers ::wp_enqueue_script
	 * @ticket 12009
	 *
	 * @expectedIncorrectUsage WP_Scripts::add_data
	 */
	public function test_script_strategy_doing_it_wrong_via_register() {
		wp_register_script( 'invalid-strategy', '/defaults.js', array(), null, array( 'strategy' => 'random-strategy' ) );
		wp_enqueue_script( 'invalid-strategy' );

		$this->assertSame(
			"<script type='text/javascript' src='/defaults.js' id='invalid-strategy-js'></script>\n",
			get_echo( 'wp_print_scripts' )
		);
	}

	/**
	 * Test script strategy doing it wrong when calling wp_script_add_data().
	 *
	 * For an invalid strategy defined during script registration, default to a blocking strategy.
	 *
	 * @covers WP_Scripts::add_data
	 * @covers ::wp_script_add_data
	 * @covers ::wp_register_script
	 * @covers ::wp_enqueue_script
	 * @ticket 12009
	 *
	 * @expectedIncorrectUsage WP_Scripts::add_data
	 */
	public function test_script_strategy_doing_it_wrong_via_add_data() {
		wp_register_script( 'invalid-strategy', '/defaults.js', array(), null );
		wp_script_add_data( 'invalid-strategy', 'strategy', 'random-strategy' );
		wp_enqueue_script( 'invalid-strategy' );

		$this->assertSame(
			"<script type='text/javascript' src='/defaults.js' id='invalid-strategy-js'></script>\n",
			get_echo( 'wp_print_scripts' )
		);
	}

	/**
	 * Test script strategy doing it wrong when calling wp_enqueue_script().
	 *
	 * For an invalid strategy defined during script registration, default to a blocking strategy.
	 *
	 * @covers WP_Scripts::add_data
	 * @covers ::wp_enqueue_script
	 * @ticket 12009
	 *
	 * @expectedIncorrectUsage WP_Scripts::add_data
	 */
	public function test_script_strategy_doing_it_wrong_via_enqueue() {
		wp_enqueue_script( 'invalid-strategy', '/defaults.js', array(), null, array( 'strategy' => 'random-strategy' ) );

		$this->assertSame(
			"<script type='text/javascript' src='/defaults.js' id='invalid-strategy-js'></script>\n",
			get_echo( 'wp_print_scripts' )
		);
	}

	/**
	 * Test script concatenation with deferred main script.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers ::wp_enqueue_script
	 * @covers ::wp_register_script
	 */
	public function test_concatenate_with_defer_strategy() {
		global $wp_scripts, $concatenate_scripts, $wp_version;

		$old_value           = $concatenate_scripts;
		$concatenate_scripts = true;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( $this->default_scripts_dir );

		wp_register_script( 'one-concat-dep', $this->default_scripts_dir . 'script.js' );
		wp_register_script( 'two-concat-dep', $this->default_scripts_dir . 'script.js' );
		wp_register_script( 'three-concat-dep', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'main-defer-script', '/main-script.js', array( 'one-concat-dep', 'two-concat-dep', 'three-concat-dep' ), null, array( 'strategy' => 'defer' ) );

		wp_print_scripts();
		$print_scripts = get_echo( '_print_scripts' );

		// Reset global before asserting.
		$concatenate_scripts = $old_value;

		$expected  = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=one-concat-dep,two-concat-dep,three-concat-dep&amp;ver={$wp_version}'></script>\n";
		$expected .= "<script type='text/javascript' src='/main-script.js' id='main-defer-script-js' defer></script>\n";

		$this->assertSame( $expected, $print_scripts, 'Scripts are being incorrectly concatenated when a main script is registered with a "defer" loading strategy. Deferred scripts should not be part of the script concat loading query.' );
	}

	/**
	 * Test script concatenation with `async` main script.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers ::wp_enqueue_script
	 * @covers ::wp_register_script
	 */
	public function test_concatenate_with_async_strategy() {
		global $wp_scripts, $concatenate_scripts, $wp_version;

		$old_value           = $concatenate_scripts;
		$concatenate_scripts = true;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( $this->default_scripts_dir );

		wp_enqueue_script( 'one-concat-dep-1', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'two-concat-dep-1', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'three-concat-dep-1', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'main-async-script-1', '/main-script.js', array(), null, array( 'strategy' => 'async' ) );

		wp_print_scripts();
		$print_scripts = get_echo( '_print_scripts' );

		// Reset global before asserting.
		$concatenate_scripts = $old_value;

		$expected  = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=one-concat-dep-1,two-concat-dep-1,three-concat-dep-1&amp;ver={$wp_version}'></script>\n";
		$expected .= "<script type='text/javascript' src='/main-script.js' id='main-async-script-1-js' async></script>\n";

		$this->assertSame( $expected, $print_scripts, 'Scripts are being incorrectly concatenated when a main script is registered with an "async" loading strategy. Async scripts should not be part of the script concat loading query.' );
	}

	/**
	 * Test script concatenation with blocking scripts before and after a `defer` script.
	 *
	 * @ticket 12009
	 *
	 * @covers WP_Scripts::do_item
	 * @covers ::wp_enqueue_script
	 * @covers ::wp_register_script
	 */
	public function test_concatenate_with_blocking_script_before_and_after_script_with_defer_strategy() {
		global $wp_scripts, $concatenate_scripts, $wp_version;

		$old_value           = $concatenate_scripts;
		$concatenate_scripts = true;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( $this->default_scripts_dir );

		wp_enqueue_script( 'one-concat-dep-2', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'two-concat-dep-2', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'three-concat-dep-2', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'deferred-script-2', '/main-script.js', array(), null, array( 'strategy' => 'defer' ) );
		wp_enqueue_script( 'four-concat-dep-2', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'five-concat-dep-2', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'six-concat-dep-2', $this->default_scripts_dir . 'script.js' );

		wp_print_scripts();
		$print_scripts = get_echo( '_print_scripts' );

		// Reset global before asserting.
		$concatenate_scripts = $old_value;

		$expected  = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=one-concat-dep-2,two-concat-dep-2,three-concat-dep-2,four-concat-dep-2,five-concat-dep-2,six-concat-dep-2&amp;ver={$wp_version}'></script>\n";
		$expected .= "<script type='text/javascript' src='/main-script.js' id='deferred-script-2-js' defer></script>\n";

		$this->assertSame( $expected, $print_scripts, 'Scripts are being incorrectly concatenated when a main script is registered as deferred after other blocking scripts are registered. Deferred scripts should not be part of the script concat loader query string. ' );
	}

	/**
	 * @ticket 42804
	 */
	public function test_wp_enqueue_script_with_html5_support_does_not_contain_type_attribute() {
		global $wp_version;
		add_theme_support( 'html5', array( 'script' ) );

		$GLOBALS['wp_scripts']                  = new WP_Scripts();
		$GLOBALS['wp_scripts']->default_version = get_bloginfo( 'version' );

		wp_enqueue_script( 'empty-deps-no-version', 'example.com' );

		$expected = "<script src='http://example.com?ver={$wp_version}' id='empty-deps-no-version-js'></script>\n";

		$this->assertSame( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * Test the different protocol references in wp_enqueue_script
	 *
	 * @global WP_Scripts $wp_scripts
	 * @ticket 16560
	 */
	public function test_protocols() {
		// Init.
		global $wp_scripts, $wp_version;
		$base_url_backup      = $wp_scripts->base_url;
		$wp_scripts->base_url = 'http://example.com/wordpress';
		$expected             = '';

		// Try with an HTTP reference.
		wp_enqueue_script( 'jquery-http', 'http://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js' );
		$expected .= "<script type='text/javascript' src='http://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js?ver={$wp_version}' id='jquery-http-js'></script>\n";

		// Try with an HTTPS reference.
		wp_enqueue_script( 'jquery-https', 'https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js' );
		$expected .= "<script type='text/javascript' src='https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js?ver={$wp_version}' id='jquery-https-js'></script>\n";

		// Try with an automatic protocol reference (//).
		wp_enqueue_script( 'jquery-doubleslash', '//ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js' );
		$expected .= "<script type='text/javascript' src='//ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js?ver={$wp_version}' id='jquery-doubleslash-js'></script>\n";

		// Try with a local resource and an automatic protocol reference (//).
		$url = '//my_plugin/script.js';
		wp_enqueue_script( 'plugin-script', $url );
		$expected .= "<script type='text/javascript' src='$url?ver={$wp_version}' id='plugin-script-js'></script>\n";

		// Try with a bad protocol.
		wp_enqueue_script( 'jquery-ftp', 'ftp://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js' );
		$expected .= "<script type='text/javascript' src='{$wp_scripts->base_url}ftp://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js?ver={$wp_version}' id='jquery-ftp-js'></script>\n";

		// Go!
		$this->assertSame( $expected, get_echo( 'wp_print_scripts' ) );

		// No scripts left to print.
		$this->assertSame( '', get_echo( 'wp_print_scripts' ) );

		// Cleanup.
		$wp_scripts->base_url = $base_url_backup;
	}

	/**
	 * Test script concatenation.
	 */
	public function test_script_concatenation() {
		global $wp_scripts, $wp_version;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( $this->default_scripts_dir );

		wp_enqueue_script( 'one', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'two', $this->default_scripts_dir . 'script.js' );
		wp_enqueue_script( 'three', $this->default_scripts_dir . 'script.js' );

		wp_print_scripts();
		$print_scripts = get_echo( '_print_scripts' );

		$expected = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=one,two,three&amp;ver={$wp_version}'></script>\n";

		$this->assertSame( $expected, $print_scripts );
	}

	/**
	 * Testing `wp_script_add_data` with the data key.
	 *
	 * @ticket 16024
	 */
	public function test_wp_script_add_data_with_data_key() {
		// Enqueue and add data.
		wp_enqueue_script( 'test-only-data', 'example.com', array(), null );
		wp_script_add_data( 'test-only-data', 'data', 'testing' );
		$expected  = "<script type='text/javascript' id='test-only-data-js-extra'>\n/* <![CDATA[ */\ntesting\n/* ]]> */\n</script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-only-data-js'></script>\n";

		// Go!
		$this->assertSame( $expected, get_echo( 'wp_print_scripts' ) );

		// No scripts left to print.
		$this->assertSame( '', get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * Testing `wp_script_add_data` with the conditional key.
	 *
	 * @ticket 16024
	 */
	public function test_wp_script_add_data_with_conditional_key() {
		// Enqueue and add conditional comments.
		wp_enqueue_script( 'test-only-conditional', 'example.com', array(), null );
		wp_script_add_data( 'test-only-conditional', 'conditional', 'gt IE 7' );
		$expected = "<!--[if gt IE 7]>\n<script type='text/javascript' src='http://example.com' id='test-only-conditional-js'></script>\n<![endif]-->\n";

		// Go!
		$this->assertSame( $expected, get_echo( 'wp_print_scripts' ) );

		// No scripts left to print.
		$this->assertSame( '', get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * Testing `wp_script_add_data` with both the data & conditional keys.
	 *
	 * @ticket 16024
	 */
	public function test_wp_script_add_data_with_data_and_conditional_keys() {
		// Enqueue and add data plus conditional comments for both.
		wp_enqueue_script( 'test-conditional-with-data', 'example.com', array(), null );
		wp_script_add_data( 'test-conditional-with-data', 'data', 'testing' );
		wp_script_add_data( 'test-conditional-with-data', 'conditional', 'lt IE 9' );
		$expected  = "<!--[if lt IE 9]>\n<script type='text/javascript' id='test-conditional-with-data-js-extra'>\n/* <![CDATA[ */\ntesting\n/* ]]> */\n</script>\n<![endif]-->\n";
		$expected .= "<!--[if lt IE 9]>\n<script type='text/javascript' src='http://example.com' id='test-conditional-with-data-js'></script>\n<![endif]-->\n";

		// Go!
		$this->assertSame( $expected, get_echo( 'wp_print_scripts' ) );

		// No scripts left to print.
		$this->assertSame( '', get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * Testing `wp_script_add_data` with an anvalid key.
	 *
	 * @ticket 16024
	 */
	public function test_wp_script_add_data_with_invalid_key() {
		// Enqueue and add an invalid key.
		wp_enqueue_script( 'test-invalid', 'example.com', array(), null );
		wp_script_add_data( 'test-invalid', 'invalid', 'testing' );
		$expected = "<script type='text/javascript' src='http://example.com' id='test-invalid-js'></script>\n";

		// Go!
		$this->assertSame( $expected, get_echo( 'wp_print_scripts' ) );

		// No scripts left to print.
		$this->assertSame( '', get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * Testing 'wp_register_script' return boolean success/failure value.
	 *
	 * @ticket 31126
	 */
	public function test_wp_register_script() {
		$this->assertTrue( wp_register_script( 'duplicate-handler', 'http://example.com' ) );
		$this->assertFalse( wp_register_script( 'duplicate-handler', 'http://example.com' ) );
	}

	/**
	 * @ticket 35229
	 */
	public function test_wp_register_script_with_handle_without_source() {
		$expected  = "<script type='text/javascript' src='http://example.com?ver=1' id='handle-one-js'></script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com?ver=2' id='handle-two-js'></script>\n";

		wp_register_script( 'handle-one', 'http://example.com', array(), 1 );
		wp_register_script( 'handle-two', 'http://example.com', array(), 2 );
		wp_register_script( 'handle-three', false, array( 'handle-one', 'handle-two' ) );

		wp_enqueue_script( 'handle-three' );

		$this->assertSame( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 35643
	 */
	public function test_wp_enqueue_script_footer_alias() {
		wp_register_script( 'foo', false, array( 'bar', 'baz' ), '1.0', true );
		wp_register_script( 'bar', home_url( 'bar.js' ), array(), '1.0', true );
		wp_register_script( 'baz', home_url( 'baz.js' ), array(), '1.0', true );

		wp_enqueue_script( 'foo' );

		$header = get_echo( 'wp_print_head_scripts' );
		$footer = get_echo( 'wp_print_footer_scripts' );

		$this->assertEmpty( $header );
		$this->assertStringContainsString( home_url( 'bar.js' ), $footer );
		$this->assertStringContainsString( home_url( 'baz.js' ), $footer );
	}

	/**
	 * Test mismatch of groups in dependencies outputs all scripts in right order.
	 *
	 * @ticket 35873
	 *
	 * @covers WP_Dependencies::add
	 * @covers WP_Dependencies::enqueue
	 * @covers WP_Dependencies::do_items
	 */
	public function test_group_mismatch_in_deps() {
		$scripts = new WP_Scripts();
		$scripts->add( 'one', 'one', array(), 'v1', 1 );
		$scripts->add( 'two', 'two', array( 'one' ) );
		$scripts->add( 'three', 'three', array( 'two' ), 'v1', 1 );

		$scripts->enqueue( array( 'three' ) );

		$this->expectOutputRegex( '/^(?:<script[^>]+><\/script>\\n){7}$/' );

		$scripts->do_items( false, 0 );
		$this->assertContains( 'one', $scripts->done );
		$this->assertContains( 'two', $scripts->done );
		$this->assertNotContains( 'three', $scripts->done );

		$scripts->do_items( false, 1 );
		$this->assertContains( 'one', $scripts->done );
		$this->assertContains( 'two', $scripts->done );
		$this->assertContains( 'three', $scripts->done );

		$scripts = new WP_Scripts();
		$scripts->add( 'one', 'one', array(), 'v1', 1 );
		$scripts->add( 'two', 'two', array( 'one' ), 'v1', 1 );
		$scripts->add( 'three', 'three', array( 'one' ) );
		$scripts->add( 'four', 'four', array( 'two', 'three' ), 'v1', 1 );

		$scripts->enqueue( array( 'four' ) );

		$scripts->do_items( false, 0 );
		$this->assertContains( 'one', $scripts->done );
		$this->assertNotContains( 'two', $scripts->done );
		$this->assertContains( 'three', $scripts->done );
		$this->assertNotContains( 'four', $scripts->done );

		$scripts->do_items( false, 1 );
		$this->assertContains( 'one', $scripts->done );
		$this->assertContains( 'two', $scripts->done );
		$this->assertContains( 'three', $scripts->done );
		$this->assertContains( 'four', $scripts->done );
	}

	/**
	 * @ticket 35873
	 */
	public function test_wp_register_script_with_dependencies_in_head_and_footer() {
		wp_register_script( 'parent', '/parent.js', array( 'child-head' ), null, true );            // In footer.
		wp_register_script( 'child-head', '/child-head.js', array( 'child-footer' ), null, false ); // In head.
		wp_register_script( 'child-footer', '/child-footer.js', array(), null, true );              // In footer.

		wp_enqueue_script( 'parent' );

		$header = get_echo( 'wp_print_head_scripts' );
		$footer = get_echo( 'wp_print_footer_scripts' );

		$expected_header  = "<script type='text/javascript' src='/child-footer.js' id='child-footer-js'></script>\n";
		$expected_header .= "<script type='text/javascript' src='/child-head.js' id='child-head-js'></script>\n";
		$expected_footer  = "<script type='text/javascript' src='/parent.js' id='parent-js'></script>\n";

		$this->assertSame( $expected_header, $header, 'Expected same header markup.' );
		$this->assertSame( $expected_footer, $footer, 'Expected same footer markup.' );
	}

	/**
	 * @ticket 35956
	 */
	public function test_wp_register_script_with_dependencies_in_head_and_footer_in_reversed_order() {
		wp_register_script( 'child-head', '/child-head.js', array(), null, false );                      // In head.
		wp_register_script( 'child-footer', '/child-footer.js', array(), null, true );                   // In footer.
		wp_register_script( 'parent', '/parent.js', array( 'child-head', 'child-footer' ), null, true ); // In footer.

		wp_enqueue_script( 'parent' );

		$header = get_echo( 'wp_print_head_scripts' );
		$footer = get_echo( 'wp_print_footer_scripts' );

		$expected_header  = "<script type='text/javascript' src='/child-head.js' id='child-head-js'></script>\n";
		$expected_footer  = "<script type='text/javascript' src='/child-footer.js' id='child-footer-js'></script>\n";
		$expected_footer .= "<script type='text/javascript' src='/parent.js' id='parent-js'></script>\n";

		$this->assertSame( $expected_header, $header, 'Expected same header markup.' );
		$this->assertSame( $expected_footer, $footer, 'Expected same footer markup.' );
	}

	/**
	 * @ticket 35956
	 */
	public function test_wp_register_script_with_dependencies_in_head_and_footer_in_reversed_order_and_two_parent_scripts() {
		wp_register_script( 'grandchild-head', '/grandchild-head.js', array(), null, false );             // In head.
		wp_register_script( 'child-head', '/child-head.js', array(), null, false );                       // In head.
		wp_register_script( 'child-footer', '/child-footer.js', array( 'grandchild-head' ), null, true ); // In footer.
		wp_register_script( 'child2-head', '/child2-head.js', array(), null, false );                     // In head.
		wp_register_script( 'child2-footer', '/child2-footer.js', array(), null, true );                  // In footer.
		wp_register_script( 'parent-footer', '/parent-footer.js', array( 'child-head', 'child-footer', 'child2-head', 'child2-footer' ), null, true ); // In footer.
		wp_register_script( 'parent-header', '/parent-header.js', array( 'child-head' ), null, false );   // In head.

		wp_enqueue_script( 'parent-footer' );
		wp_enqueue_script( 'parent-header' );

		$header = get_echo( 'wp_print_head_scripts' );
		$footer = get_echo( 'wp_print_footer_scripts' );

		$expected_header  = "<script type='text/javascript' src='/child-head.js' id='child-head-js'></script>\n";
		$expected_header .= "<script type='text/javascript' src='/grandchild-head.js' id='grandchild-head-js'></script>\n";
		$expected_header .= "<script type='text/javascript' src='/child2-head.js' id='child2-head-js'></script>\n";
		$expected_header .= "<script type='text/javascript' src='/parent-header.js' id='parent-header-js'></script>\n";

		$expected_footer  = "<script type='text/javascript' src='/child-footer.js' id='child-footer-js'></script>\n";
		$expected_footer .= "<script type='text/javascript' src='/child2-footer.js' id='child2-footer-js'></script>\n";
		$expected_footer .= "<script type='text/javascript' src='/parent-footer.js' id='parent-footer-js'></script>\n";

		$this->assertSame( $expected_header, $header, 'Expected same header markup.' );
		$this->assertSame( $expected_footer, $footer, 'Expected same footer markup.' );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_returns_bool() {
		$this->assertFalse( wp_add_inline_script( 'test-example', 'console.log("before");', 'before' ) );
		wp_enqueue_script( 'test-example', 'example.com', array(), null );
		$this->assertTrue( wp_add_inline_script( 'test-example', 'console.log("before");', 'before' ) );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_unknown_handle() {
		$this->assertFalse( wp_add_inline_script( 'test-invalid', 'console.log("before");', 'before' ) );
		$this->assertSame( '', get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_before() {
		wp_enqueue_script( 'test-example', 'example.com', array(), null );
		wp_add_inline_script( 'test-example', 'console.log("before");', 'before' );

		$expected  = "<script type='text/javascript' id='test-example-js-before'>\nconsole.log(\"before\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_after() {
		wp_enqueue_script( 'test-example', 'example.com', array(), null );
		wp_add_inline_script( 'test-example', 'console.log("after");' );

		$expected  = "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-after'>\nconsole.log(\"after\");\n</script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_before_and_after() {
		wp_enqueue_script( 'test-example', 'example.com', array(), null );
		wp_add_inline_script( 'test-example', 'console.log("before");', 'before' );
		wp_add_inline_script( 'test-example', 'console.log("after");' );

		$expected  = "<script type='text/javascript' id='test-example-js-before'>\nconsole.log(\"before\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-after'>\nconsole.log(\"after\");\n</script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 44551
	 */
	public function test_wp_add_inline_script_before_for_handle_without_source() {
		wp_register_script( 'test-example', '' );
		wp_enqueue_script( 'test-example' );
		wp_add_inline_script( 'test-example', 'console.log("before");', 'before' );

		$expected = "<script type='text/javascript' id='test-example-js-before'>\nconsole.log(\"before\");\n</script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 44551
	 */
	public function test_wp_add_inline_script_after_for_handle_without_source() {
		wp_register_script( 'test-example', '' );
		wp_enqueue_script( 'test-example' );
		wp_add_inline_script( 'test-example', 'console.log("after");' );

		$expected = "<script type='text/javascript' id='test-example-js-after'>\nconsole.log(\"after\");\n</script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 44551
	 */
	public function test_wp_add_inline_script_before_and_after_for_handle_without_source() {
		wp_register_script( 'test-example', '' );
		wp_enqueue_script( 'test-example' );
		wp_add_inline_script( 'test-example', 'console.log("before");', 'before' );
		wp_add_inline_script( 'test-example', 'console.log("after");' );

		$expected  = "<script type='text/javascript' id='test-example-js-before'>\nconsole.log(\"before\");\n</script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-after'>\nconsole.log(\"after\");\n</script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_multiple() {
		wp_enqueue_script( 'test-example', 'example.com', array(), null );
		wp_add_inline_script( 'test-example', 'console.log("before");', 'before' );
		wp_add_inline_script( 'test-example', 'console.log("before");', 'before' );
		wp_add_inline_script( 'test-example', 'console.log("after");' );
		wp_add_inline_script( 'test-example', 'console.log("after");' );

		$expected  = "<script type='text/javascript' id='test-example-js-before'>\nconsole.log(\"before\");\nconsole.log(\"before\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-after'>\nconsole.log(\"after\");\nconsole.log(\"after\");\n</script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_localized_data_is_added_first() {
		wp_enqueue_script( 'test-example', 'example.com', array(), null );
		wp_localize_script( 'test-example', 'testExample', array( 'foo' => 'bar' ) );
		wp_add_inline_script( 'test-example', 'console.log("before");', 'before' );
		wp_add_inline_script( 'test-example', 'console.log("after");' );

		$expected  = "<script type='text/javascript' id='test-example-js-extra'>\n/* <![CDATA[ */\nvar testExample = {\"foo\":\"bar\"};\n/* ]]> */\n</script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-before'>\nconsole.log(\"before\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-after'>\nconsole.log(\"after\");\n</script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_before_with_concat() {
		global $wp_scripts, $wp_version;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( $this->default_scripts_dir );

		wp_enqueue_script( 'one', $this->default_scripts_dir . 'one.js' );
		wp_enqueue_script( 'two', $this->default_scripts_dir . 'two.js' );
		wp_enqueue_script( 'three', $this->default_scripts_dir . 'three.js' );

		wp_add_inline_script( 'one', 'console.log("before one");', 'before' );
		wp_add_inline_script( 'two', 'console.log("before two");', 'before' );

		$expected  = "<script type='text/javascript' id='one-js-before'>\nconsole.log(\"before one\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='{$this->default_scripts_dir}one.js?ver={$wp_version}' id='one-js'></script>\n";
		$expected .= "<script type='text/javascript' id='two-js-before'>\nconsole.log(\"before two\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='{$this->default_scripts_dir}two.js?ver={$wp_version}' id='two-js'></script>\n";
		$expected .= "<script type='text/javascript' src='{$this->default_scripts_dir}three.js?ver={$wp_version}' id='three-js'></script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_before_with_concat2() {
		global $wp_scripts, $wp_version;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( $this->default_scripts_dir );

		wp_enqueue_script( 'one', $this->default_scripts_dir . 'one.js' );
		wp_enqueue_script( 'two', $this->default_scripts_dir . 'two.js' );
		wp_enqueue_script( 'three', $this->default_scripts_dir . 'three.js' );

		wp_add_inline_script( 'one', 'console.log("before one");', 'before' );

		$expected  = "<script type='text/javascript' id='one-js-before'>\nconsole.log(\"before one\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='{$this->default_scripts_dir}one.js?ver={$wp_version}' id='one-js'></script>\n";
		$expected .= "<script type='text/javascript' src='{$this->default_scripts_dir}two.js?ver={$wp_version}' id='two-js'></script>\n";
		$expected .= "<script type='text/javascript' src='{$this->default_scripts_dir}three.js?ver={$wp_version}' id='three-js'></script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_after_with_concat() {
		global $wp_scripts, $wp_version;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( $this->default_scripts_dir );

		wp_enqueue_script( 'one', $this->default_scripts_dir . 'one.js' );
		wp_enqueue_script( 'two', $this->default_scripts_dir . 'two.js' );
		wp_enqueue_script( 'three', $this->default_scripts_dir . 'three.js' );
		wp_enqueue_script( 'four', $this->default_scripts_dir . 'four.js' );

		wp_add_inline_script( 'two', 'console.log("after two");' );
		wp_add_inline_script( 'three', 'console.log("after three");' );

		$expected  = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=one&amp;ver={$wp_version}'></script>\n";
		$expected .= "<script type='text/javascript' src='{$this->default_scripts_dir}two.js?ver={$wp_version}' id='two-js'></script>\n";
		$expected .= "<script type='text/javascript' id='two-js-after'>\nconsole.log(\"after two\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='{$this->default_scripts_dir}three.js?ver={$wp_version}' id='three-js'></script>\n";
		$expected .= "<script type='text/javascript' id='three-js-after'>\nconsole.log(\"after three\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='{$this->default_scripts_dir}four.js?ver={$wp_version}' id='four-js'></script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 14853
	 */
	public function test_wp_add_inline_script_after_and_before_with_concat_and_conditional() {
		global $wp_scripts;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( '/wp-admin/js/', '/wp-includes/js/' ); // Default dirs as in wp-includes/script-loader.php.

		$expected_localized  = "<!--[if gte IE 9]>\n";
		$expected_localized .= "<script type='text/javascript' id='test-example-js-extra'>\n/* <![CDATA[ */\nvar testExample = {\"foo\":\"bar\"};\n/* ]]> */\n</script>\n";
		$expected_localized .= "<![endif]-->\n";

		$expected  = "<!--[if gte IE 9]>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-before'>\nconsole.log(\"before\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-after'>\nconsole.log(\"after\");\n</script>\n";
		$expected .= "<![endif]-->\n";

		wp_enqueue_script( 'test-example', 'example.com', array(), null );
		wp_localize_script( 'test-example', 'testExample', array( 'foo' => 'bar' ) );
		wp_add_inline_script( 'test-example', 'console.log("before");', 'before' );
		wp_add_inline_script( 'test-example', 'console.log("after");' );
		wp_script_add_data( 'test-example', 'conditional', 'gte IE 9' );

		$this->assertSame( $expected_localized, get_echo( 'wp_print_scripts' ) );
		$this->assertEqualMarkup( $expected, $wp_scripts->print_html );
		$this->assertTrue( $wp_scripts->do_concat );
	}

	/**
	 * @ticket 36392
	 */
	public function test_wp_add_inline_script_after_with_concat_and_core_dependency() {
		global $wp_scripts, $wp_version;

		wp_default_scripts( $wp_scripts );

		$wp_scripts->base_url  = '';
		$wp_scripts->do_concat = true;

		$expected  = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=jquery-core,jquery-migrate&amp;ver={$wp_version}'></script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-after'>\nconsole.log(\"after\");\n</script>\n";

		wp_enqueue_script( 'test-example', 'http://example.com', array( 'jquery' ), null );
		wp_add_inline_script( 'test-example', 'console.log("after");' );

		wp_print_scripts();
		$print_scripts = get_echo( '_print_scripts' );

		$this->assertEqualMarkup( $expected, $print_scripts );
	}

	/**
	 * @ticket 36392
	 */
	public function test_wp_add_inline_script_after_with_concat_and_conditional_and_core_dependency() {
		global $wp_scripts, $wp_version;

		wp_default_scripts( $wp_scripts );

		$wp_scripts->base_url  = '';
		$wp_scripts->do_concat = true;

		$expected  = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=jquery-core,jquery-migrate&amp;ver={$wp_version}'></script>\n";
		$expected .= "<!--[if gte IE 9]>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-after'>\nconsole.log(\"after\");\n</script>\n";
		$expected .= "<![endif]-->\n";

		wp_enqueue_script( 'test-example', 'http://example.com', array( 'jquery' ), null );
		wp_add_inline_script( 'test-example', 'console.log("after");' );
		wp_script_add_data( 'test-example', 'conditional', 'gte IE 9' );

		wp_print_scripts();
		$print_scripts = get_echo( '_print_scripts' );

		$this->assertEqualMarkup( $expected, $print_scripts );
	}

	/**
	 * @ticket 36392
	 */
	public function test_wp_add_inline_script_before_with_concat_and_core_dependency() {
		global $wp_scripts, $wp_version;

		wp_default_scripts( $wp_scripts );
		wp_default_packages( $wp_scripts );

		$wp_scripts->base_url  = '';
		$wp_scripts->do_concat = true;

		$expected  = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=jquery-core,jquery-migrate&amp;ver={$wp_version}'></script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-before'>\nconsole.log(\"before\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";

		wp_enqueue_script( 'test-example', 'http://example.com', array( 'jquery' ), null );
		wp_add_inline_script( 'test-example', 'console.log("before");', 'before' );

		wp_print_scripts();
		$print_scripts = get_echo( '_print_scripts' );

		$this->assertEqualMarkup( $expected, $print_scripts );
	}

	/**
	 * @ticket 36392
	 */
	public function test_wp_add_inline_script_before_after_concat_with_core_dependency() {
		global $wp_scripts, $wp_version;

		wp_default_scripts( $wp_scripts );
		wp_default_packages( $wp_scripts );

		$wp_scripts->base_url  = '';
		$wp_scripts->do_concat = true;

		$expected  = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=jquery-core,jquery-migrate,wp-polyfill-inert,regenerator-runtime,wp-polyfill,wp-dom-ready,wp-hooks&amp;ver={$wp_version}'></script>\n";
		$expected .= "<script type='text/javascript' id='test-example-js-before'>\nconsole.log(\"before\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";
		$expected .= "<script type='text/javascript' src='/wp-includes/js/dist/i18n.min.js' id='wp-i18n-js'></script>\n";
		$expected .= "<script type='text/javascript' id='wp-i18n-js-after'>\n";
		$expected .= "wp.i18n.setLocaleData( { 'text direction\u0004ltr': [ 'ltr' ] } );\n";
		$expected .= "</script>\n";
		$expected .= "<script type='text/javascript' src='/wp-includes/js/dist/a11y.min.js' id='wp-a11y-js'></script>\n";
		$expected .= "<script type='text/javascript' src='http://example2.com' id='test-example2-js'></script>\n";
		$expected .= "<script type='text/javascript' id='test-example2-js-after'>\nconsole.log(\"after\");\n</script>\n";

		wp_enqueue_script( 'test-example', 'http://example.com', array( 'jquery' ), null );
		wp_add_inline_script( 'test-example', 'console.log("before");', 'before' );
		wp_enqueue_script( 'test-example2', 'http://example2.com', array( 'wp-a11y' ), null );
		wp_add_inline_script( 'test-example2', 'console.log("after");', 'after' );

		// Effectively ignore the output until retrieving it later via `getActualOutput()`.
		$this->expectOutputRegex( '`.`' );

		wp_print_scripts();
		_print_scripts();
		$print_scripts = $this->getActualOutput();

		/*
		 * We've replaced wp-a11y.js with @wordpress/a11y package (see #45066),
		 * and `wp-polyfill` is now a dependency of the packaged wp-a11y.
		 * The packaged scripts contain various version numbers, which are not exposed,
		 * so we will remove all version args from the output.
		 */
		$print_scripts = preg_replace(
			'~js\?ver=([^"\']*)~', // Matches `js?ver=X.X.X` and everything to single or double quote.
			'js',                  // The replacement, `js` without the version arg.
			$print_scripts         // Printed scripts.
		);

		$this->assertEqualMarkup( $expected, $print_scripts );
	}

	/**
	 * @ticket 36392
	 */
	public function test_wp_add_inline_script_customize_dependency() {
		global $wp_scripts;

		wp_default_scripts( $wp_scripts );
		wp_default_packages( $wp_scripts );

		$wp_scripts->base_url  = '';
		$wp_scripts->do_concat = true;

		$expected_tail  = "<script type='text/javascript' src='/customize-dependency.js' id='customize-dependency-js'></script>\n";
		$expected_tail .= "<script type='text/javascript' id='customize-dependency-js-after'>\n";
		$expected_tail .= "tryCustomizeDependency()\n";
		$expected_tail .= "</script>\n";

		$handle = 'customize-dependency';
		wp_enqueue_script( $handle, '/customize-dependency.js', array( 'customize-controls' ), null );
		wp_add_inline_script( $handle, 'tryCustomizeDependency()' );

		// Effectively ignore the output until retrieving it later via `getActualOutput()`.
		$this->expectOutputRegex( '`.`' );

		wp_print_scripts();
		_print_scripts();
		$print_scripts = $this->getActualOutput();

		$tail = substr( $print_scripts, strrpos( $print_scripts, "<script type='text/javascript' src='/customize-dependency.js' id='customize-dependency-js'>" ) );
		$this->assertEqualMarkup( $expected_tail, $tail );
	}

	/**
	 * @ticket 36392
	 */
	public function test_wp_add_inline_script_after_for_core_scripts_with_concat_is_limited_and_falls_back_to_no_concat() {
		global $wp_scripts, $wp_version;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( '/wp-admin/js/', '/wp-includes/js/' ); // Default dirs as in wp-includes/script-loader.php.

		wp_enqueue_script( 'one', '/wp-includes/js/script.js' );
		wp_enqueue_script( 'two', '/wp-includes/js/script2.js', array( 'one' ) );
		wp_add_inline_script( 'one', 'console.log("after one");', 'after' );
		wp_enqueue_script( 'three', '/wp-includes/js/script3.js' );
		wp_enqueue_script( 'four', '/wp-includes/js/script4.js' );

		$expected  = "<script type='text/javascript' src='/wp-includes/js/script.js?ver={$wp_version}' id='one-js'></script>\n";
		$expected .= "<script type='text/javascript' id='one-js-after'>\nconsole.log(\"after one\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='/wp-includes/js/script2.js?ver={$wp_version}' id='two-js'></script>\n";
		$expected .= "<script type='text/javascript' src='/wp-includes/js/script3.js?ver={$wp_version}' id='three-js'></script>\n";
		$expected .= "<script type='text/javascript' src='/wp-includes/js/script4.js?ver={$wp_version}' id='four-js'></script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 36392
	 */
	public function test_wp_add_inline_script_before_third_core_script_prints_two_concat_scripts() {
		global $wp_scripts, $wp_version;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( '/wp-admin/js/', '/wp-includes/js/' ); // Default dirs as in wp-includes/script-loader.php.

		wp_enqueue_script( 'one', '/wp-includes/js/script.js' );
		wp_enqueue_script( 'two', '/wp-includes/js/script2.js', array( 'one' ) );
		wp_enqueue_script( 'three', '/wp-includes/js/script3.js' );
		wp_add_inline_script( 'three', 'console.log("before three");', 'before' );
		wp_enqueue_script( 'four', '/wp-includes/js/script4.js' );

		$expected  = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=one,two&amp;ver={$wp_version}'></script>\n";
		$expected .= "<script type='text/javascript' id='three-js-before'>\nconsole.log(\"before three\");\n</script>\n";
		$expected .= "<script type='text/javascript' src='/wp-includes/js/script3.js?ver={$wp_version}' id='three-js'></script>\n";
		$expected .= "<script type='text/javascript' src='/wp-includes/js/script4.js?ver={$wp_version}' id='four-js'></script>\n";

		$this->assertEqualMarkup( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * Data provider to test get_inline_script_data and get_inline_script_tag.
	 *
	 * @return array[]
	 */
	public function data_provider_to_test_get_inline_script() {
		return array(
			'before-blocking' => array(
				'position'       => 'before',
				'inline_scripts' => array(
					'/*before foo 1*/',
				),
				'delayed'        => false,
				'expected_data'  => '/*before foo 1*/',
				'expected_tag'   => "<script id='foo-js-before' type='text/javascript'>\n/*before foo 1*/\n</script>\n",
			),
			'after-blocking'  => array(
				'position'       => 'after',
				'inline_scripts' => array(
					'/*after foo 1*/',
					'/*after foo 2*/',
				),
				'delayed'        => false,
				'expected_data'  => "/*after foo 1*/\n/*after foo 2*/",
				'expected_tag'   => "<script id='foo-js-after' type='text/javascript'>\n/*after foo 1*/\n/*after foo 2*/\n</script>\n",
			),
			'before-delayed'  => array(
				'position'       => 'before',
				'inline_scripts' => array(
					'/*before foo 1*/',
				),
				'delayed'        => true,
				'expected_data'  => '/*before foo 1*/',
				'expected_tag'   => "<script id='foo-js-before' type='text/plain' data-wp-deps='dep'>\n/*before foo 1*/\n</script>\n",
			),
			'after-delayed'   => array(
				'position'       => 'after',
				'inline_scripts' => array(
					'/*after foo 1*/',
					'/*after foo 2*/',
				),
				'delayed'        => true,
				'expected_data'  => "/*after foo 1*/\n/*after foo 2*/",
				'expected_tag'   => "<script id='foo-js-after' type='text/plain' data-wp-deps='dep'>\n/*after foo 1*/\n/*after foo 2*/\n</script>\n",
			),
		);
	}

	/**
	 * Test getting inline scripts.
	 *
	 * @covers WP_Scripts::get_inline_script_data
	 * @covers WP_Scripts::get_inline_script_tag
	 * @covers WP_Scripts::print_inline_script
	 * @expectedDeprecated WP_Scripts::print_inline_script
	 *
	 * @dataProvider data_provider_to_test_get_inline_script
	 * @param string   $position       Position.
	 * @param string[] $inline_scripts Inline scripts.
	 * @param bool     $delayed        Delayed.
	 * @param string   $expected_data  Expected data.
	 * @param string   $expected_tag   Expected tag.
	 */
	public function test_get_inline_script( $position, $inline_scripts, $delayed, $expected_data, $expected_tag ) {
		global $wp_scripts;

		$deps = array();
		if ( $delayed ) {
			$wp_scripts->add( 'dep', 'https://example.com/dependency.js', array(), false ); // TODO: Cannot pass strategy to $args e.g. array( 'strategy' => 'defer' )
			$wp_scripts->add_data( 'dep', 'strategy', 'defer' );
			$deps[] = 'dep';
		}

		$handle = 'foo';
		$wp_scripts->add( $handle, 'https://example.com/foo.js', $deps );
		if ( $delayed ) {
			$wp_scripts->add_data( $handle, 'strategy', 'defer' );
		}

		$this->assertSame( '', $wp_scripts->get_inline_script_data( $handle, $position ) );
		$this->assertSame( '', $wp_scripts->get_inline_script_tag( $handle, $position ) );
		$this->assertFalse( $wp_scripts->print_inline_script( $handle, $position, false ) );
		ob_start();
		$output = $wp_scripts->print_inline_script( $handle, $position, true );
		$this->assertSame( '', ob_get_clean() );
		$this->assertFalse( $output );

		foreach ( $inline_scripts as $inline_script ) {
			$wp_scripts->add_inline_script( $handle, $inline_script, $position );
		}

		$this->assertSame( $expected_data, $wp_scripts->get_inline_script_data( $handle, $position ) );
		$this->assertSame( $expected_data, $wp_scripts->print_inline_script( $handle, $position, false ) );
		$this->assertEqualMarkup(
			$expected_tag,
			$wp_scripts->get_inline_script_tag( $handle, $position )
		);
		ob_start();
		$output = $wp_scripts->print_inline_script( $handle, $position, true );
		$this->assertEqualMarkup( $expected_tag, ob_get_clean() );
		$this->assertEqualMarkup( $expected_tag, $output );
	}

	/**
	 * @ticket 45103
	 */
	public function test_wp_set_script_translations() {
		wp_register_script( 'wp-i18n', '/wp-includes/js/dist/wp-i18n.js', array(), null );
		wp_enqueue_script( 'test-example', '/wp-includes/js/script.js', array(), null );
		wp_set_script_translations( 'test-example', 'default', DIR_TESTDATA . '/languages' );

		$expected  = "<script type='text/javascript' src='/wp-includes/js/dist/wp-i18n.js' id='wp-i18n-js'></script>\n";
		$expected .= str_replace(
			array(
				'__DOMAIN__',
				'__HANDLE__',
				'__JSON_TRANSLATIONS__',
			),
			array(
				'default',
				'test-example',
				file_get_contents( DIR_TESTDATA . '/languages/en_US-813e104eb47e13dd4cc5af844c618754.json' ),
			),
			$this->wp_scripts_print_translations_output
		);
		$expected .= "<script type='text/javascript' src='/wp-includes/js/script.js' id='test-example-js'></script>\n";

		$this->assertSameIgnoreEOL( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 45103
	 */
	public function test_wp_set_script_translations_for_plugin() {
		wp_register_script( 'wp-i18n', '/wp-includes/js/dist/wp-i18n.js', array(), null );
		wp_enqueue_script( 'plugin-example', '/wp-content/plugins/my-plugin/js/script.js', array(), null );
		wp_set_script_translations( 'plugin-example', 'internationalized-plugin', DIR_TESTDATA . '/languages/plugins' );

		$expected  = "<script type='text/javascript' src='/wp-includes/js/dist/wp-i18n.js' id='wp-i18n-js'></script>\n";
		$expected .= str_replace(
			array(
				'__DOMAIN__',
				'__HANDLE__',
				'__JSON_TRANSLATIONS__',
			),
			array(
				'internationalized-plugin',
				'plugin-example',
				file_get_contents( DIR_TESTDATA . '/languages/plugins/internationalized-plugin-en_US-2f86cb96a0233e7cb3b6f03ad573be0b.json' ),
			),
			$this->wp_scripts_print_translations_output
		);
		$expected .= "<script type='text/javascript' src='/wp-content/plugins/my-plugin/js/script.js' id='plugin-example-js'></script>\n";

		$this->assertSameIgnoreEOL( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 45103
	 */
	public function test_wp_set_script_translations_for_theme() {
		wp_register_script( 'wp-i18n', '/wp-includes/js/dist/wp-i18n.js', array(), null );
		wp_enqueue_script( 'theme-example', '/wp-content/themes/my-theme/js/script.js', array(), null );
		wp_set_script_translations( 'theme-example', 'internationalized-theme', DIR_TESTDATA . '/languages/themes' );

		$expected  = "<script type='text/javascript' src='/wp-includes/js/dist/wp-i18n.js' id='wp-i18n-js'></script>\n";
		$expected .= str_replace(
			array(
				'__DOMAIN__',
				'__HANDLE__',
				'__JSON_TRANSLATIONS__',
			),
			array(
				'internationalized-theme',
				'theme-example',
				file_get_contents( DIR_TESTDATA . '/languages/themes/internationalized-theme-en_US-2f86cb96a0233e7cb3b6f03ad573be0b.json' ),
			),
			$this->wp_scripts_print_translations_output
		);
		$expected .= "<script type='text/javascript' src='/wp-content/themes/my-theme/js/script.js' id='theme-example-js'></script>\n";

		$this->assertSameIgnoreEOL( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 45103
	 */
	public function test_wp_set_script_translations_with_handle_file() {
		wp_register_script( 'wp-i18n', '/wp-includes/js/dist/wp-i18n.js', array(), null );
		wp_enqueue_script( 'script-handle', '/wp-admin/js/script.js', array(), null );
		wp_set_script_translations( 'script-handle', 'admin', DIR_TESTDATA . '/languages/' );

		$expected  = "<script type='text/javascript' src='/wp-includes/js/dist/wp-i18n.js' id='wp-i18n-js'></script>\n";
		$expected .= str_replace(
			array(
				'__DOMAIN__',
				'__HANDLE__',
				'__JSON_TRANSLATIONS__',
			),
			array(
				'admin',
				'script-handle',
				file_get_contents( DIR_TESTDATA . '/languages/admin-en_US-script-handle.json' ),
			),
			$this->wp_scripts_print_translations_output
		);
		$expected .= "<script type='text/javascript' src='/wp-admin/js/script.js' id='script-handle-js'></script>\n";

		$this->assertSameIgnoreEOL( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 45103
	 */
	public function test_wp_set_script_translations_i18n_dependency() {
		global $wp_scripts;

		wp_register_script( 'wp-i18n', '/wp-includes/js/dist/wp-i18n.js', array(), null );
		wp_enqueue_script( 'test-example', '/wp-includes/js/script.js', array(), null );
		wp_set_script_translations( 'test-example', 'default', DIR_TESTDATA . '/languages/' );

		$script = $wp_scripts->registered['test-example'];

		$this->assertContains( 'wp-i18n', $script->deps );
	}

	/**
	 * @ticket 45103
	 * @ticket 55250
	 */
	public function test_wp_set_script_translations_when_translation_file_does_not_exist() {
		wp_register_script( 'wp-i18n', '/wp-includes/js/dist/wp-i18n.js', array(), null );
		wp_enqueue_script( 'test-example', '/wp-admin/js/script.js', array(), null );
		wp_set_script_translations( 'test-example', 'admin', DIR_TESTDATA . '/languages/' );

		$expected  = "<script type='text/javascript' src='/wp-includes/js/dist/wp-i18n.js' id='wp-i18n-js'></script>\n";
		$expected .= "<script type='text/javascript' src='/wp-admin/js/script.js' id='test-example-js'></script>\n";

		$this->assertSameIgnoreEOL( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 45103
	 */
	public function test_wp_set_script_translations_after_register() {
		wp_register_script( 'wp-i18n', '/wp-includes/js/dist/wp-i18n.js', array(), null );
		wp_register_script( 'test-example', '/wp-includes/js/script.js', array(), null );
		wp_set_script_translations( 'test-example', 'default', DIR_TESTDATA . '/languages' );

		wp_enqueue_script( 'test-example' );

		$expected  = "<script type='text/javascript' src='/wp-includes/js/dist/wp-i18n.js' id='wp-i18n-js'></script>\n";
		$expected .= str_replace(
			array(
				'__DOMAIN__',
				'__HANDLE__',
				'__JSON_TRANSLATIONS__',
			),
			array(
				'default',
				'test-example',
				file_get_contents( DIR_TESTDATA . '/languages/en_US-813e104eb47e13dd4cc5af844c618754.json' ),
			),
			$this->wp_scripts_print_translations_output
		);
		$expected .= "<script type='text/javascript' src='/wp-includes/js/script.js' id='test-example-js'></script>\n";

		$this->assertSameIgnoreEOL( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * @ticket 45103
	 */
	public function test_wp_set_script_translations_dependency() {
		wp_register_script( 'wp-i18n', '/wp-includes/js/dist/wp-i18n.js', array(), null );
		wp_register_script( 'test-dependency', '/wp-includes/js/script.js', array(), null );
		wp_set_script_translations( 'test-dependency', 'default', DIR_TESTDATA . '/languages' );

		wp_enqueue_script( 'test-example', '/wp-includes/js/script2.js', array( 'test-dependency' ), null );

		$expected  = "<script type='text/javascript' src='/wp-includes/js/dist/wp-i18n.js' id='wp-i18n-js'></script>\n";
		$expected .= str_replace(
			array(
				'__DOMAIN__',
				'__HANDLE__',
				'__JSON_TRANSLATIONS__',
			),
			array(
				'default',
				'test-dependency',
				file_get_contents( DIR_TESTDATA . '/languages/en_US-813e104eb47e13dd4cc5af844c618754.json' ),
			),
			$this->wp_scripts_print_translations_output
		);
		$expected .= "<script type='text/javascript' src='/wp-includes/js/script.js' id='test-dependency-js'></script>\n";
		$expected .= "<script type='text/javascript' src='/wp-includes/js/script2.js' id='test-example-js'></script>\n";

		$this->assertSameIgnoreEOL( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * Testing `wp_enqueue_code_editor` with file path.
	 *
	 * @ticket 41871
	 * @covers ::wp_enqueue_code_editor
	 */
	public function test_wp_enqueue_code_editor_when_php_file_will_be_passed() {
		$real_file              = WP_PLUGIN_DIR . '/hello.php';
		$wp_enqueue_code_editor = wp_enqueue_code_editor( array( 'file' => $real_file ) );
		$this->assertNonEmptyMultidimensionalArray( $wp_enqueue_code_editor );

		$this->assertSameSets( array( 'codemirror', 'csslint', 'jshint', 'htmlhint' ), array_keys( $wp_enqueue_code_editor ) );
		$this->assertSameSets(
			array(
				'autoCloseBrackets',
				'autoCloseTags',
				'continueComments',
				'direction',
				'extraKeys',
				'indentUnit',
				'indentWithTabs',
				'inputStyle',
				'lineNumbers',
				'lineWrapping',
				'matchBrackets',
				'matchTags',
				'mode',
				'styleActiveLine',
				'gutters',
			),
			array_keys( $wp_enqueue_code_editor['codemirror'] )
		);
		$this->assertEmpty( $wp_enqueue_code_editor['codemirror']['gutters'] );

		$this->assertSameSets(
			array(
				'errors',
				'box-model',
				'display-property-grouping',
				'duplicate-properties',
				'known-properties',
				'outline-none',
			),
			array_keys( $wp_enqueue_code_editor['csslint'] )
		);

		$this->assertSameSets(
			array(
				'boss',
				'curly',
				'eqeqeq',
				'eqnull',
				'es3',
				'expr',
				'immed',
				'noarg',
				'nonbsp',
				'onevar',
				'quotmark',
				'trailing',
				'undef',
				'unused',
				'browser',
				'globals',
			),
			array_keys( $wp_enqueue_code_editor['jshint'] )
		);

		$this->assertSameSets(
			array(
				'tagname-lowercase',
				'attr-lowercase',
				'attr-value-double-quotes',
				'doctype-first',
				'tag-pair',
				'spec-char-escape',
				'id-unique',
				'src-not-empty',
				'attr-no-duplication',
				'alt-require',
				'space-tab-mixed-disabled',
				'attr-unsafe-chars',
			),
			array_keys( $wp_enqueue_code_editor['htmlhint'] )
		);
	}

	/**
	 * Testing `wp_enqueue_code_editor` with `compact`.
	 *
	 * @ticket 41871
	 * @covers ::wp_enqueue_code_editor
	 */
	public function test_wp_enqueue_code_editor_when_generated_array_by_compact_will_be_passed() {
		$file                   = '';
		$wp_enqueue_code_editor = wp_enqueue_code_editor( compact( 'file' ) );
		$this->assertNonEmptyMultidimensionalArray( $wp_enqueue_code_editor );

		$this->assertSameSets( array( 'codemirror', 'csslint', 'jshint', 'htmlhint' ), array_keys( $wp_enqueue_code_editor ) );
		$this->assertSameSets(
			array(
				'continueComments',
				'direction',
				'extraKeys',
				'indentUnit',
				'indentWithTabs',
				'inputStyle',
				'lineNumbers',
				'lineWrapping',
				'mode',
				'styleActiveLine',
				'gutters',
			),
			array_keys( $wp_enqueue_code_editor['codemirror'] )
		);
		$this->assertEmpty( $wp_enqueue_code_editor['codemirror']['gutters'] );

		$this->assertSameSets(
			array(
				'errors',
				'box-model',
				'display-property-grouping',
				'duplicate-properties',
				'known-properties',
				'outline-none',
			),
			array_keys( $wp_enqueue_code_editor['csslint'] )
		);

		$this->assertSameSets(
			array(
				'boss',
				'curly',
				'eqeqeq',
				'eqnull',
				'es3',
				'expr',
				'immed',
				'noarg',
				'nonbsp',
				'onevar',
				'quotmark',
				'trailing',
				'undef',
				'unused',
				'browser',
				'globals',
			),
			array_keys( $wp_enqueue_code_editor['jshint'] )
		);

		$this->assertSameSets(
			array(
				'tagname-lowercase',
				'attr-lowercase',
				'attr-value-double-quotes',
				'doctype-first',
				'tag-pair',
				'spec-char-escape',
				'id-unique',
				'src-not-empty',
				'attr-no-duplication',
				'alt-require',
				'space-tab-mixed-disabled',
				'attr-unsafe-chars',
			),
			array_keys( $wp_enqueue_code_editor['htmlhint'] )
		);
	}

	/**
	 * Testing `wp_enqueue_code_editor` with `array_merge`.
	 *
	 * @ticket 41871
	 * @covers ::wp_enqueue_code_editor
	 */
	public function test_wp_enqueue_code_editor_when_generated_array_by_array_merge_will_be_passed() {
		$wp_enqueue_code_editor = wp_enqueue_code_editor(
			array_merge(
				array(
					'type'       => 'text/css',
					'codemirror' => array(
						'indentUnit' => 2,
						'tabSize'    => 2,
					),
				),
				array()
			)
		);

		$this->assertNonEmptyMultidimensionalArray( $wp_enqueue_code_editor );

		$this->assertSameSets( array( 'codemirror', 'csslint', 'jshint', 'htmlhint' ), array_keys( $wp_enqueue_code_editor ) );
		$this->assertSameSets(
			array(
				'autoCloseBrackets',
				'continueComments',
				'direction',
				'extraKeys',
				'gutters',
				'indentUnit',
				'indentWithTabs',
				'inputStyle',
				'lineNumbers',
				'lineWrapping',
				'lint',
				'matchBrackets',
				'mode',
				'styleActiveLine',
				'tabSize',
			),
			array_keys( $wp_enqueue_code_editor['codemirror'] )
		);

		$this->assertSameSets(
			array(
				'errors',
				'box-model',
				'display-property-grouping',
				'duplicate-properties',
				'known-properties',
				'outline-none',
			),
			array_keys( $wp_enqueue_code_editor['csslint'] )
		);

		$this->assertSameSets(
			array(
				'boss',
				'curly',
				'eqeqeq',
				'eqnull',
				'es3',
				'expr',
				'immed',
				'noarg',
				'nonbsp',
				'onevar',
				'quotmark',
				'trailing',
				'undef',
				'unused',
				'browser',
				'globals',
			),
			array_keys( $wp_enqueue_code_editor['jshint'] )
		);

		$this->assertSameSets(
			array(
				'tagname-lowercase',
				'attr-lowercase',
				'attr-value-double-quotes',
				'doctype-first',
				'tag-pair',
				'spec-char-escape',
				'id-unique',
				'src-not-empty',
				'attr-no-duplication',
				'alt-require',
				'space-tab-mixed-disabled',
				'attr-unsafe-chars',
			),
			array_keys( $wp_enqueue_code_editor['htmlhint'] )
		);
	}

	/**
	 * Testing `wp_enqueue_code_editor` with `array`.
	 *
	 * @ticket 41871
	 * @covers ::wp_enqueue_code_editor
	 */
	public function test_wp_enqueue_code_editor_when_simple_array_will_be_passed() {
		$wp_enqueue_code_editor = wp_enqueue_code_editor(
			array(
				'type'       => 'text/css',
				'codemirror' => array(
					'indentUnit' => 2,
					'tabSize'    => 2,
				),
			)
		);

		$this->assertNonEmptyMultidimensionalArray( $wp_enqueue_code_editor );

		$this->assertSameSets( array( 'codemirror', 'csslint', 'jshint', 'htmlhint' ), array_keys( $wp_enqueue_code_editor ) );
		$this->assertSameSets(
			array(
				'autoCloseBrackets',
				'continueComments',
				'direction',
				'extraKeys',
				'gutters',
				'indentUnit',
				'indentWithTabs',
				'inputStyle',
				'lineNumbers',
				'lineWrapping',
				'lint',
				'matchBrackets',
				'mode',
				'styleActiveLine',
				'tabSize',
			),
			array_keys( $wp_enqueue_code_editor['codemirror'] )
		);

		$this->assertSameSets(
			array(
				'errors',
				'box-model',
				'display-property-grouping',
				'duplicate-properties',
				'known-properties',
				'outline-none',
			),
			array_keys( $wp_enqueue_code_editor['csslint'] )
		);

		$this->assertSameSets(
			array(
				'boss',
				'curly',
				'eqeqeq',
				'eqnull',
				'es3',
				'expr',
				'immed',
				'noarg',
				'nonbsp',
				'onevar',
				'quotmark',
				'trailing',
				'undef',
				'unused',
				'browser',
				'globals',
			),
			array_keys( $wp_enqueue_code_editor['jshint'] )
		);

		$this->assertSameSets(
			array(
				'tagname-lowercase',
				'attr-lowercase',
				'attr-value-double-quotes',
				'doctype-first',
				'tag-pair',
				'spec-char-escape',
				'id-unique',
				'src-not-empty',
				'attr-no-duplication',
				'alt-require',
				'space-tab-mixed-disabled',
				'attr-unsafe-chars',
			),
			array_keys( $wp_enqueue_code_editor['htmlhint'] )
		);
	}

	/**
	 * @ticket 52534
	 * @covers ::wp_localize_script
	 *
	 * @dataProvider data_wp_localize_script_data_formats
	 *
	 * @param mixed  $l10n_data Localization data passed to wp_localize_script().
	 * @param string $expected  Expected transformation of localization data.
	 */
	public function test_wp_localize_script_data_formats( $l10n_data, $expected ) {
		if ( ! is_array( $l10n_data ) ) {
			$this->setExpectedIncorrectUsage( 'WP_Scripts::localize' );
		}

		wp_enqueue_script( 'test-example', 'example.com', array(), null );
		wp_localize_script( 'test-example', 'testExample', $l10n_data );

		$expected  = "<script type='text/javascript' id='test-example-js-extra'>\n/* <![CDATA[ */\nvar testExample = {$expected};\n/* ]]> */\n</script>\n";
		$expected .= "<script type='text/javascript' src='http://example.com' id='test-example-js'></script>\n";

		$this->assertSame( $expected, get_echo( 'wp_print_scripts' ) );
	}

	/**
	 * Data provider for test_wp_localize_script_data_formats().
	 *
	 * @return array[] {
	 *     Array of arguments for test.
	 *
	 *     @type mixed  $l10n_data Localization data passed to wp_localize_script().
	 *     @type string $expected  Expected transformation of localization data.
	 * }
	 */
	public function data_wp_localize_script_data_formats() {
		return array(
			// Officially supported formats.
			array( array( 'array value, no key' ), '["array value, no key"]' ),
			array( array( 'foo' => 'bar' ), '{"foo":"bar"}' ),
			array( array( 'foo' => array( 'bar' => 'foobar' ) ), '{"foo":{"bar":"foobar"}}' ),
			array( array( 'foo' => 6.6 ), '{"foo":"6.6"}' ),
			array( array( 'foo' => 6 ), '{"foo":"6"}' ),
			array( array(), '[]' ),

			// Unofficially supported format.
			array( 'string', '"string"' ),

			// Unsupported formats.
			array( 1.5, '1.5' ),
			array( 1, '1' ),
			array( false, '[""]' ),
			array( null, 'null' ),
		);
	}

	/**
	 * @ticket 55628
	 * @covers ::wp_set_script_translations
	 */
	public function test_wp_external_wp_i18n_print_order() {
		global $wp_scripts, $wp_version;

		$wp_scripts->do_concat    = true;
		$wp_scripts->default_dirs = array( '/default/' );

		// wp-i18n script in a non-default directory.
		wp_register_script( 'wp-i18n', '/plugins/wp-i18n.js', array(), null );
		// Script in default dir that's going to be concatenated.
		wp_enqueue_script( 'jquery-core', '/default/jquery-core.js', array(), null );
		// Script in default dir that depends on wp-i18n.
		wp_enqueue_script( 'common', '/default/common.js', array(), null );
		wp_set_script_translations( 'common' );

		$print_scripts = get_echo(
			static function() {
				wp_print_scripts();
				_print_scripts();
			}
		);

		// The non-default script should end concatenation and maintain order.
		$expected  = "<script type='text/javascript' src='/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=jquery-core&amp;ver={$wp_version}'></script>\n";
		$expected .= "<script type='text/javascript' src='/plugins/wp-i18n.js' id='wp-i18n-js'></script>\n";
		$expected .= "<script type='text/javascript' src='/default/common.js' id='common-js'></script>\n";

		$this->assertSame( $expected, $print_scripts );
	}

	/**
	 * Gets the script tag for the delayed inline script loader.
	 *
	 * @return string Script tag.
	 */
	protected function get_delayed_inline_script_loader_script_tag() {
		/*
		 * Ensure the built delayed inline script loader file exists
		 * when the test suite is run from the 'src' directory.
		 *
		 * Note this should no longer be needed as of https://core.trac.wordpress.org/ticket/57844.
		 */
		$build_path = ABSPATH . WPINC . '/js/wp-delayed-inline-script-loader' . wp_scripts_get_suffix() . '.js';

		if ( ! file_exists( $build_path ) ) {
			$src_path = ABSPATH . 'js/_enqueues/lib/delayed-inline-script-loader.js';

			$file_contents = file_get_contents( $src_path );

			self::touch( $build_path );
			file_put_contents(
				$build_path,
				$file_contents
			);
		}

		return wp_get_inline_script_tag(
			file_get_contents( $build_path ),
			array( 'id' => 'wp-delayed-inline-script-loader' )
		);
	}

	/**
	 * Parse an HTML markup fragment.
	 *
	 * @param string $markup Markup.
	 * @return DOMElement Body element wrapping supplied markup fragment.
	 */
	protected function parse_markup_fragment( $markup ) {
		$dom = new DOMDocument();
		$dom->loadHTML(
			"<!DOCTYPE html><html><head><meta charset=utf8></head><body>{$markup}</body></html>"
		);

		/** @var DOMElement $body */
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		// Trim whitespace nodes added before/after which can be added when parsing.
		foreach ( array( $body->firstChild, $body->lastChild ) as $node ) {
			if ( $node instanceof DOMText && '' === trim( $node->data ) ) {
				$body->removeChild( $node );
			}
		}

		return $body;
	}

	/**
	 * Assert markup is equal.
	 *
	 * @param string $expected Expected markup.
	 * @param string $actual   Actual markup.
	 * @param string $message  Message.
	 */
	protected function assertEqualMarkup( $expected, $actual, $message = '' ) {
		$this->assertEquals(
			$this->parse_markup_fragment( $expected ),
			$this->parse_markup_fragment( $actual ),
			$message
		);
	}
}
