<?php

/** Verify real cookie-authenticated responses do not populate the public cache. */
class AuthenticatedRequestCacheCest {
	protected $draft_post_id;
	protected $draft_post_title;
	protected $test_run_id;
	protected $admin_id;
	protected $admin_username;

	public function _before( AcceptanceTester $I ) {
		$this->test_run_id = uniqid( 'test_', true );
		$this->draft_post_title = 'Secret Draft ' . $this->test_run_id;
		$this->draft_post_id = null;
		$this->admin_username = getenv( 'TEST_SITE_ADMIN_USERNAME' );
		$I->assertNotEmpty( $this->admin_username, 'The CI fixture must declare its administrator username' );
		$this->admin_id = $I->grabUserIdFromDatabase( $this->admin_username );
		$I->assertGreaterThan( 0, $this->admin_id );
	}

	public function _after( AcceptanceTester $I ) {
		if ( $this->draft_post_id ) {
			$I->dontHavePostInDatabase( [ 'ID' => $this->draft_post_id ] );
		}
		$I->dontHaveOptionInDatabase( 'graphql_cache_section' );
	}

	/** Obtain a native, session-bound nonce without disabling WPGraphQL's CSRF checks. */
	private function authenticateAdmin( AcceptanceTester $I ): string {
		$I->loginAsAdmin();
		$I->amOnPage( '/wp-admin/admin-ajax.php?action=rest-nonce' );
		$nonce = trim( $I->grabPageSource() );
		$I->assertMatchesRegularExpression( '/\A[a-f0-9]{10}\z/', $nonce, 'Logged-in admin must receive a WordPress REST nonce' );
		return $nonce;
	}

	private function assertAuthenticatedViewer( AcceptanceTester $I, array $response ): void {
		$I->assertArrayNotHasKey( 'errors', $response, 'Authenticated request must execute without GraphQL errors' );
		$I->assertArrayHasKey( 'data', $response );
		$I->assertArrayHasKey( 'viewer', $response['data'] );
		$I->assertIsArray( $response['data']['viewer'], 'Cookie and nonce must establish a real viewer' );
		$I->assertEquals( $this->admin_id, $response['data']['viewer']['databaseId'] );
		$I->assertSame( $this->admin_username, $response['data']['viewer']['username'] );
	}

	public function testDraftContentDoesNotLeakToPublicUsers( AcceptanceTester $I ) {
		$I->haveOptionInDatabase( 'graphql_cache_section', [ 'cache_toggle' => 'on' ] );
		$this->draft_post_id = $I->havePostInDatabase( [
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => $this->draft_post_title,
			'post_content' => 'Secret content',
		] );
		$operation_name = 'TestDraft_' . str_replace( '.', '_', $this->test_run_id );
		$query = "query {$operation_name} { viewer { databaseId username } posts(where: {status: DRAFT}) { nodes { title status } } }";
		$nonce = $this->authenticateAdmin( $I );
		$graphql_url = '/graphql?' . http_build_query( [ 'query' => $query, '_wpnonce' => $nonce ] );

		// Both authenticated requests must see the draft and bypass cache.
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$I->amOnPage( $graphql_url );
			$response = json_decode( $I->grabPageSource(), true );
			$I->assertIsArray( $response );
			$this->assertAuthenticatedViewer( $I, $response );
			$I->assertArrayHasKey( 'posts', $response['data'] );
			$posts = $response['data']['posts']['nodes'];
			$I->assertContains( $this->draft_post_title, array_column( $posts, 'title' ), "Authenticated request {$attempt} must see the draft" );
			$I->assertEquals( [], $response['extensions']['graphqlSmartCache']['graphqlObjectCache'] ?? [], "Authenticated request {$attempt} must bypass cache" );
		}

		// REST has a separate PhpBrowser client: no admin cookie and no nonce.
		$I->deleteHeader( 'Authorization' );
		$I->deleteHeader( 'X-WP-Nonce' );
		$I->sendGet( 'graphql', [ 'query' => $query ] );
		$I->seeResponseCodeIs( 200 );
		$public = json_decode( $I->grabResponse(), true );
		$I->assertIsArray( $public );
		$I->assertArrayNotHasKey( 'errors', $public );
		$I->assertArrayHasKey( 'data', $public );
		$I->assertArrayHasKey( 'viewer', $public['data'] );
		$I->assertNull( $public['data']['viewer'], 'Public request must be anonymous' );
		$I->assertArrayHasKey( 'posts', $public['data'] );
		$public_posts = $public['data']['posts']['nodes'];
		$I->assertNotContains( $this->draft_post_title, array_column( $public_posts, 'title' ), 'Public response must not expose the admin draft' );
		$I->assertNotContains( 'draft', array_map( 'strtolower', array_column( $public_posts, 'status' ) ) );
		$I->assertEquals( [], $public['extensions']['graphqlSmartCache']['graphqlObjectCache'] ?? [], 'Admin requests must not have populated a public cache entry' );
	}

	public function testPublicRequestsAreCached( AcceptanceTester $I ) {
		$I->haveOptionInDatabase( 'graphql_cache_section', [ 'cache_toggle' => 'on' ] );
		$operation_name = 'TestPublic_' . str_replace( '.', '_', $this->test_run_id );
		$query = "query {$operation_name} { __typename viewer { databaseId username } }";
		$I->deleteHeader( 'Authorization' );
		$I->deleteHeader( 'X-WP-Nonce' );

		// Use the separate public client for both requests and assert its identity.
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$I->sendGet( 'graphql', [ 'query' => $query ] );
			$I->seeResponseCodeIs( 200 );
			$response = json_decode( $I->grabResponse(), true );
			$I->assertIsArray( $response );
			$I->assertArrayNotHasKey( 'errors', $response );
			$I->assertArrayHasKey( 'data', $response );
			$I->assertSame( 'RootQuery', $response['data']['__typename'] );
			$I->assertArrayHasKey( 'viewer', $response['data'] );
			$I->assertNull( $response['data']['viewer'], 'Public cache requests must be anonymous' );
			$cache = $response['extensions']['graphqlSmartCache']['graphqlObjectCache'] ?? [];
			if ( 1 === $attempt ) {
				$I->assertEquals( [], $cache, 'First public request must execute' );
			} else {
				$I->assertSame( 'This response was not executed at run-time but has been returned from the GraphQL Object Cache', $cache['message'] ?? null );
			}
		}

		// The same query, with a genuine admin cookie and nonce, must bypass that cache.
		$nonce = $this->authenticateAdmin( $I );
		$I->amOnPage( '/graphql?' . http_build_query( [ 'query' => $query, '_wpnonce' => $nonce ] ) );
		$authenticated = json_decode( $I->grabPageSource(), true );
		$I->assertIsArray( $authenticated );
		$this->assertAuthenticatedViewer( $I, $authenticated );
		$I->assertSame( 'RootQuery', $authenticated['data']['__typename'] );
		$I->assertEquals( [], $authenticated['extensions']['graphqlSmartCache']['graphqlObjectCache'] ?? [], 'Admin must bypass the existing anonymous cache entry' );
	}
}
