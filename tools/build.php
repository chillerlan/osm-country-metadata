<?php
/**
 * build.php
 *
 * @created      15.07.2024
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2024 smiley
 * @license      MIT
 *
 * @noinspection PhpUndefinedConstantInspection
 */

use chillerlan\HTTP\CurlClient;
use chillerlan\HTTP\CurlMultiClient;
use chillerlan\HTTP\HTTPOptions;
use chillerlan\HTTP\MultiResponseHandlerInterface;
use chillerlan\HTTP\Psr7\HTTPFactory;
use chillerlan\HTTP\Utils\MessageUtil;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

require_once __DIR__.'/../vendor/autoload.php';


// create some directories
$directories = [
	'BUILDDIR'           => __DIR__.'/../.build',
	'BUILDDIR_RELATIONS' => __DIR__.'/../.build/relations',
	'SRCDIR'             => __DIR__.'/../src',
];

foreach($directories as $const => $dir){

	if(!is_dir($dir)){
		mkdir(directory: $dir, recursive: true);
	}

	define($const, realpath($dir));
}

const FILE_RELATIONS = SRCDIR.'/osm-country-relation-ids.json';
const FILE_METADATA  = SRCDIR.'/osm-country-metadata.json';


// invoke http client
$httpOptions = new HTTPOptions(['ca_info' => __DIR__.'/cacert.pem', 'sleep' => 250000]);
$factory     = new HTTPFactory;
$http        = new CurlClient($factory, $httpOptions);


// invoke logger
$formatter   = (new LineFormatter(null, 'Y-m-d H:i:s', true, true))->setJsonPrettyPrint(true);
$logHandler  = (new StreamHandler('php://stdout', LogLevel::INFO))->setFormatter($formatter);
$logger      = new Logger('log', [$logHandler]);


// https://wiki.openstreetmap.org/wiki/API_v0.6#Read:_GET_/api/0.6/[node|way|relation]/#id
const RELATION_API = 'https://api.openstreetmap.org/api/0.6/relation/%s.json';
// https://wiki.openstreetmap.org/wiki/Overpass_API
const OVERPASS_API = 'https://overpass-api.de/api/interpreter';
// https://community.openstreetmap.org/t/overpass-query-to-get-the-id-of-country-boundaries/4873/2
const ID_QUERY     = '[out:json];(relation["type"="boundary"]["boundary"="administrative"]["ISO3166-1"];);out ids;';


// fetch the relation IDs via the Overpass API
$payload  = $factory->createStream(ID_QUERY);
$request  = $factory->createRequest('POST', OVERPASS_API)->withBody($payload);
$response = $http->sendRequest($request);

if($response->getStatusCode() !== 200){
	throw new Exception('error while fetching from the Overpass API');
}

$json = MessageUtil::decodeJSON($response, true);
$IDs  = array_column($json['elements'], 'id');

// add missing IDs
$IDs[] = 2186646; // ATA
$IDs[] = 1703814; // PSE


// invoke a response handler for a curl_multi request
$responseHandler = new class ($logger) implements MultiResponseHandlerInterface{

	private array $relations = [];

	public function __construct(
		private LoggerInterface $logger,
	){}

	public function getRelations():array{
		ksort($this->relations);

		return $this->relations;
	}

	public function handleResponse(
		ResponseInterface $response,
		RequestInterface $request,
		int $id,
		array|null $curl_info,
	):RequestInterface|null{

		// return the failed request back to the stack
		if($response->getStatusCode() !== 200){
			$this->logger->warning(sprintf('response error [%s] (returned to stack)', $request->getUri()));

			return $request;
		}

		$content = MessageUtil::getContents($response);

		// content was empty for some reason, no further action
		if($content === ''){
			$this->logger->warning(sprintf('empty response [%s]', $request->getUri()));

			return null;
		}

		$json        = json_decode($content);
		$relation_id = $json->elements[0]->id ?? '';

		// something went horribly wrong (probably not recoverable)
		if(empty($relation_id)){
			$this->logger->warning(sprintf('invalid response [%s]', $request->getUri()));

			return null;
		}

		$this->logger->info(sprintf('received data for ID %s', $relation_id));

		// save the response
		$this->relations[$json->elements[0]->tags->{'ISO3166-1:alpha3'}] = $relation_id;

		file_put_contents(sprintf(BUILDDIR_RELATIONS.'/%s.json', $relation_id), $content);

		// response ok, nothing to return
		return null;
	}

};


// invoke curl_multi and fetch relation data from the OSM API
$httpMultiClient = new CurlMultiClient($responseHandler, $factory, $httpOptions);

foreach($IDs as $id){
	$httpMultiClient->addRequest($factory->createRequest('GET', sprintf(RELATION_API, $id)));
}

$httpMultiClient->process();

// write the relations map
$relations    = $responseHandler->getRelations();
$relationData = str_replace('    ', "\t", json_encode($relations, JSON_PRETTY_PRINT));

file_put_contents(FILE_RELATIONS, $relationData);


// build the data

// 'int_name', 'loc_name', 'long_name', 'official_language',  'coat_of_arms',
const TAGS_MAIN = [
	'name', 'official_name', 'default_language', 'flag','ISO3166-2', 'timezone', 'currency', 'wikidata', 'wikipedia',
];

const TAGS_LANG = [
	'alt_name', 'alt_official_name', 'alt_short_name', 'long_name', 'name', 'official_name', 'old_name',
	'old_official_name', 'old_short_name', 'short_name', 'wikipedia',
];

const TAGS_UNSET = [
	'ISO3166-1', 'boundary', 'capital', 'capital_city', 'type', 'url', 'website',
];

$metadata  = [];

foreach($relations as $code => $relation_id){
	// fetch the relation data from the build-dir
	$json = json_decode(file_get_contents(sprintf(BUILDDIR.'/relations/%s.json', $relation_id)), true);
	$data = $json['elements'][0];

	ksort($data['tags']);

	$metadata[$code]['relation_id'] = $relation_id;

	// root element items
	foreach(TAGS_MAIN as $t){
		$metadata[$code][$t] = $data['tags'][$t] ?? null;
		unset($data['tags'][$t]);
	}

	$metadata[$code]['capital'] = $data['tags']['capital'] ?? $data['tags']['capital_city'] ?? null;
	$metadata[$code]['website'] = $data['tags']['website'] ?? $data['tags']['url'] ?? null;

	foreach(TAGS_UNSET as $u){
		unset($data['tags'][$u]);
	}

	// add language/translations
	foreach($data['tags'] as $k => $v){

		// special treatment
		if(str_starts_with(strtolower($k), 'iso3166-1:')){
			$metadata[$code]['ISO3166-1'][str_ireplace('ISO3166-1:', '', $k)] = strtoupper($v);

			unset($data['tags'][$k]);
			continue;
		}

		if(str_starts_with(strtolower($k), 'name:un:')){
			$metadata[$code]['lang']['name:UN'][str_ireplace('name:UN:', '', $k)] = $v;

			unset($data['tags'][$k]);
			continue;
		}

		foreach(TAGS_LANG as $n){

			if(str_starts_with(strtolower($k), $n.':')){

				$metadata[$code]['lang'][$n][str_ireplace($n.':', '', $k)] = $v;

				unset($data['tags'][$k]);
			}

		}

	}

	// add the remaining tags
	$metadata[$code]['tags'] = $data['tags'];

	$logger->info(sprintf('processed: [%s] %s', $code, $metadata[$code]['name']));
}


// write output
ksort($metadata);

foreach($metadata as &$arr){
	ksort($arr);
}

$metadata = str_replace('    ', "\t", json_encode($metadata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

file_put_contents(FILE_METADATA, $metadata);
