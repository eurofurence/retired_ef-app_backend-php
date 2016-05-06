<?

define('ROOT_PATH', getcwd() . '/');
define('SHARED_PATH', ROOT_PATH . '../Shared/');
define('VENDOR_PATH', SHARED_PATH . './vendor/');

require SHARED_PATH . 'config.inc.php';
require SHARED_PATH . 'helpers.php';
require VENDOR_PATH . 'autoload.php';
require VENDOR_PATH . 'sergeytsalkov/meekrodb/db.class.php';
require VENDOR_PATH . 'parsecsv/php-parsecsv/parsecsv.lib.php';

use \YaLinqo\Enumerable;

$db = new MeekroDB(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT, DB_CHARSET);

$zipArchiveLocation = "/temp/EF21.zip";

$zipContentsQueryable = getZipContentsAsQueryable($zipArchiveLocation);
$csvEntry = $zipContentsQueryable->where(function($v) { return endsWith($v["name"], "csv"); })->single();

$csvData = getZipContentOfFile($zipArchiveLocation, $csvEntry['name']);
while(ord($csvData[0]) > 127) $csvData = substr($csvData, 1);

$csvParser = new parseCSV();
$csvParser->delimiter = ";";
$csvContentsQueryable =  from($csvParser->parse_string($csvData));

$existingRecordsQueryable = from($db->query("SELECT * FROM dealerentry"));

$oldRecords = $existingRecordsQueryable
    ->where(function($row) use ($csvContentsQueryable) { return $csvContentsQueryable->all('$v["Reg No."] != '.$row['RegistrationNumber']); })
    ->toArray();

print_r($oldRecords);

?>