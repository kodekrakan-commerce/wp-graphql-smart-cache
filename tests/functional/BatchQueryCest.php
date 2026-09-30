<?php
/**
 * Test batch request returns cached results.
 * Verify the result nodes are saved to the collection map memory/transients.
 */
class BatchQueryCest {
	protected $query_alias;
	public function _before( FunctionalTester $I ) {
		// Create/save persisted query for the query and query id
		// The uniqid means it's different between test runs, in case something fails and is stuck in database.
		$operation_name = uniqid( "savedquery_posts_" );
		$query_string = sprintf( "query %s { posts { nodes { id title } } }", $operation_name );
		$this->query_alias = hash( 'sha256', $query_string );

		$I->sendPost('graphql', [
			'query' => $query_string,
			'queryId' =>$this->query_alias
		] );

		$I->dontSeeResponseJsonMatchesJsonPath( '$.errors' );
		$registration = json_decode( $I->grabResponse(), true );
		$I->assertIsArray( $registration['data']['posts']['nodes'] );
		$I->seeTermInDatabase( [ 'name' => $this->query_alias ] );

		// Create a published post for our queries
		$I->havePostInDatabase( [
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'foo',
			'post_content' => 'foo bar. biz bang.',
			'post_name'    => 'foo-slug',
		] );

		// Enable the local cache transient cache for these tests
		$I->haveOptionInDatabase( 'graphql_cache_section', [ 'cache_toggle' => 'on' ] );
	}

	public function _after( FunctionalTester $I ) {
		// Make sure that is gone.
		$I->dontHavePostInDatabase(['post_title' => 'foo']);

		// clean up and persisted queries terms in the taxonomy
		$I->dontHavePostInDatabase( [ 'post_type' => 'graphql_document' ] );
		$I->dontHaveTermInDatabase( [ 'taxonomy' => 'graphql_query_alias'] );

		$I->dontHaveOptionInDatabase( 'graphql_cache_section'  );

	}

	public function testBatchQueryIsCached( FunctionalTester $I ) {
		// Test saved/persisted query.
		$query_string = sprintf( "query Unregistered_%s { posts { nodes { uri id databaseId } } }", $this->query_alias );

		// Initial queries should not come from cache.
		// Use individual queries here as an example that they are the same as when batched.
		$I->sendGet('graphql', [ 'queryId' => $this->query_alias ] );
		$I->seeResponseContainsJson( [
			'extensions' => [
				'graphqlSmartCache' => [
					'graphqlObjectCache' => []
				]
			]
		]);
		$I->sendGet('graphql', [ 'query' => $query_string ] );
		$I->seeResponseContainsJson( [
			'extensions' => [
				'graphqlSmartCache' => [
					'graphqlObjectCache' => []
				]
			]
		]);

		// Batch queries, reusing query id to prove caching works
		$query = 
			[
				[	"queryId" => $this->query_alias ],
				[	"query" => $query_string ],
			]
		;

		$I->sendPost('graphql', $query );

		$response = json_decode( $I->grabResponse(), 1 );
		codecept_debug( $response );
		$I->assertEquals( "This response was not executed at run-time but has been returned from the GraphQL Object Cache", $response[0]['extensions']['graphqlSmartCache']['graphqlObjectCache']['message'] );
		$I->assertEquals( "This response was not executed at run-time but has been returned from the GraphQL Object Cache", $response[1]['extensions']['graphqlSmartCache']['graphqlObjectCache']['message'] );
	}
}
