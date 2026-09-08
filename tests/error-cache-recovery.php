<?php
/** Run with wp eval-file; all test cache entries and schema changes are process-local. */

use WPGraphQL\SmartCache\Cache\Query;
use WPGraphQL\SmartCache\Cache\Results;
use WPGraphQL\SmartCache\Storage\Ephemeral;

Query::$storage = new Ephemeral('isolated_error_cache_test');
$calls = 0;
add_action('graphql_register_types', function () use (&$calls) {
    register_graphql_field('RootQuery', 'cacheRecoveryProbe', array(
        'type' => 'String',
        'resolve' => function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \GraphQL\Error\UserError('Synthetic temporary failure');
            }
            return 'recovered';
        },
    ));
});
$request = array('query' => 'query CacheRecoveryProbe { cacheRecoveryProbe }', 'operationName' => 'CacheRecoveryProbe');
$first = graphql($request);
$second = graphql($request);
$third = graphql($request);
if (empty($first['errors']) || ($second['data']['cacheRecoveryProbe'] ?? null) !== 'recovered'
    || ($third['data']['cacheRecoveryProbe'] ?? null) !== 'recovered' || $calls !== 2) {
    throw new \RuntimeException('Transient failure recovery or healthy result caching failed');
}
$cache = new Results();
$key = $cache->the_results_key(null, $request['query'], null, $request['operationName']);
Query::$storage->set($key, array('data' => null, 'errors' => array(array('message' => 'Legacy cached failure'))), 1800);
$legacy = graphql($request);
if (($legacy['data']['cacheRecoveryProbe'] ?? null) !== 'recovered' || $calls !== 3) {
    throw new \RuntimeException('Legacy cached error did not recover');
}
foreach (array(array('data' => null), array('invalid' => true), array('data' => array('cacheRecoveryProbe' => 'partial'), 'errors' => array(array('message' => 'Partial error')))) as $bad) {
    Query::$storage->set($key, $bad, 1800);
    $result = graphql($request);
    if (($result['data']['cacheRecoveryProbe'] ?? null) !== 'recovered') {
        throw new \RuntimeException('Malformed or partial cached response survived');
    }
}
echo json_encode(array('transient_retry' => 'pass', 'healthy_cache_hit' => 'pass', 'legacy_error_eviction' => 'pass', 'malformed_partial_eviction' => 'pass', 'resolver_calls' => $calls)) . PHP_EOL;
