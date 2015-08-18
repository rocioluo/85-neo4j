<?php
use Silex\Application;
use Symfony\Component\HttpFoundation\Request,
    Symfony\Component\HttpFoundation\JsonResponse,
    Symfony\Component\Yaml\Yaml;
use Neoxygen\NeoClient\ClientBuilder;

require __DIR__.'/vendor/autoload.php';

$app = new Application();

if (false !== getenv('GRAPHSTORY_URL')) {
    $cnx = parse_url(getenv('GRAPHSTORY_URL'));
} else {
    $config = Yaml::parse(file_get_contents(__DIR__.'/config/config.yml'));
    $cnx = parse_url($config['neo4j_url']);
}

$neo4j = ClientBuilder::create()
    ->addConnection('default', $cnx['scheme'], $cnx['host'], $cnx['port'], true, $cnx['user'], $cnx['pass'])
    ->setAutoFormatResponse(true)
    ->setDefaultTimeout(20)
    ->build();

$app->get('/', function () {
    return file_get_contents(__DIR__.'/static/index.html');
});

$app->get('/graph', function (Request $request) use ($neo4j) {
    $limit = $request->get('limit', 50);
    $params = ['limit' => $limit];
    $q = 'MATCH (m:Movie)<-[r:ACTED_IN]-(p:Person) RETURN m,r,p LIMIT {limit}';
    $result = $neo4j->sendCypherQuery($q, $params)->getResult();

    $nodes = [];
    $edges = [];
    $nodesPositions = [];

    $i = 0;
    foreach ($result->getNodes() as $node){
        $prop = ($node->getLabel() === 'Movie') ? 'title' : 'name';
        $nodes[] = [
            'title' => $node->getProperty($prop),
            'label' => $node->getLabel()
        ];
        $nodesPositions[$node->getId()] = $i;
        $i++;
    }

    foreach ($result->getRelationships() as $rel){
        $edges[] = [
            'source' => $nodesPositions[$rel->getStartNode()->getId()],
            'target' => $nodesPositions[$rel->getEndNode()->getId()]
        ];
    }

    $data = [
        'nodes' => $nodes,
        'links' => $edges
    ];

    $response = new JsonResponse();
    $response->setData($data);

    return $response;
});

$app->get('/search', function (Request $request) use ($neo4j) {
    $searchTerm = $request->get('q');
    $term = '(?i).*'.$searchTerm.'.*';
    $query = 'MATCH (m:Movie) WHERE m.title =~ {term} RETURN m';
    $params = ['term' => $term];

    $result = $neo4j->sendCypherQuery($query, $params)->getResult();
    $movies = [];
    foreach ($result->getNodes() as $movie){
        $movies[] = ['movie' => $movie->getProperties()];
    }

    $response = new JsonResponse();
    $response->setData($movies);

    return $response;
});

$app->get('/movie/{title}', function ($title) use ($neo4j) {
    $q = 'MATCH (m:Movie) WHERE m.title = {title} OPTIONAL MATCH p=(m)<-[r]-(a:Person) RETURN m,p';
    $params = ['title' => $title];

    $result = $neo4j->sendCypherQuery($q, $params)->getResult();

    $movie = $result->getSingleNodeByLabel('Movie');
    $mov = [
        'title' => $movie->getProperty('title'),
        'cast' => []
        ];

    foreach ($movie->getInboundRelationships() as $rel){
        $actor = $rel->getStartNode()->getProperty('name');
        $relType = explode('_', strtolower($rel->getType()));
        $job = $relType[0];
        $cast = [
            'job' => $job,
            'name' => $actor
        ];
        if (array_key_exists('roles', $rel->getProperties())){
            $cast['role'] = implode(',', $rel->getProperties()['roles']);
        } else {
            $cast['role'] = null;
        }
        $mov['cast'][] = $cast;
    }

    $response = new JsonResponse();
    $response->setData($mov);

    return $response;
});

$app->get('/import', function () use ($app, $neo4j) {
    $q = trim(file_get_contents(__DIR__.'/static/movies.cypher'));
    $neo4j->sendCypherQuery($q);

    return $app->redirect('/');
});

$app->get('/reset', function() use ($app, $neo4j) {
    $q = 'MATCH (n) OPTIONAL MATCH (n)-[r]-() DELETE r,n';
    $neo4j->sendCypherQuery($q);

    return $app->redirect('/import');

});

$app->run();