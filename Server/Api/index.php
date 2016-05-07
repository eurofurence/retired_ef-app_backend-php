<?php

define('ROOT_PATH', getcwd() . '/');
define('SHARED_PATH', ROOT_PATH . '../Shared/');
define('VENDOR_PATH', SHARED_PATH . './vendor/');

require SHARED_PATH . 'config.inc.php';
require VENDOR_PATH . 'autoload.php';
require VENDOR_PATH . 'sergeytsalkov/meekrodb/db.class.php';

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Slim\Http\Stream;

$endpointDatabase = new MeekroDB(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT, DB_CHARSET);
$endpointDatabase->param_char = "##";
$endpointConfiguration = $endpointDatabase->query("SELECT * FROM EndpointConfiguration");
$endpointEntities = $endpointDatabase->query("SELECT * FROM EndpointEntity");

$app = new \Slim\App([
    'settings' => [
        'displayErrorDetails' => true
    ]
]);

// Register Endpoint Metadata
$app->get('/Endpoint', function (Request $request, Response $response, $args) use ($endpointConfiguration, $endpointEntities, $endpointDatabase) {
	foreach($endpointEntities as $id => $entity) {
		$row = $endpointDatabase->queryFirstRow("SELECT DATE_FORMAT(MAX(LastChangeDateTimeUtc), '%Y-%m-%dT%TZ') AS LastChangeDateTimeUtc, COUNT(*) AS Count FROM " . $entity["TableName"]);
		$endpointEntities[$id]["LastChangeDateTimeUtc"] = $row["LastChangeDateTimeUtc"];
		$endpointEntities[$id]["Count"] = $row["Count"];
	}

	return $response->withJson(
			array(
					"CurrentDateTimeUtc" => gmdate("Y-m-d\TH:i:s\Z"),
					"Configuration" => $endpointConfiguration,
					"Entities" => $endpointEntities
			)
		);
});


// Register all Endpoints for Table Enumeration & Indexer
foreach($endpointEntities as $id => $entity) {
	$app->get('/' . $entity["Name"], 
		function (Request $request, Response $response) use ($endpointDatabase, $entity) {
			$fields = preg_replace("(date:([^$^,]+))", "DATE_FORMAT(\\1,'%Y-%m-%dT%TZ') AS \\1", $entity["SelectFields"]);
			$queryBase = "SELECT " . $fields . " FROM ". $entity["TableName"] ." tbl ";
			
			$since = isset($_GET["since"]) ? $_GET["since"] : null;
			$since = substr($since, 0, strlen($since) - (strtoupper($since[strlen($since)-1]) == "Z" ? 1 : 0));
			
			if ($since && strtotime($since)) {
				$queryBase .= " WHERE tbl.LastChangeDateTimeUtc >= ##t ";
				return $response->withJson($endpointDatabase->query($queryBase, $since));
			} else {
				$queryBase .= " WHERE IsDeleted = 0";
				return $response->withJson($endpointDatabase->query($queryBase));
			}
		}
	);
	
	$app->get('/' . $entity["Name"] . "/{Id}", 
		function (Request $request, Response $response) use ($endpointDatabase, $entity) {
			return $response->withJson(
					$endpointDatabase->queryFirstRow("SELECT * FROM " . $entity["TableName"] ." WHERE Id = ##s",  $request->getAttribute('id'))
				);
		}
	);
}

// Register Image Server
$app->get('/ImageData/{Id}', function (Request $request, Response $response) use ($endpointDatabase) {
	$row = $endpointDatabase->queryFirstRow("SELECT * FROM ImageData WHERE Id = ##s",  $request->getAttribute('Id'));
	return $response->write($row["Data"])->withHeader("Content-Type", $row["MimeType"]);
});

$app->run();

?>