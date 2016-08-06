<?php
define('ROOT_PATH', getcwd() . '/');
define('SHARED_PATH', ROOT_PATH . '../Shared/');
define('VENDOR_PATH', SHARED_PATH . './vendor/');

require SHARED_PATH . 'config.inc.php';
require SHARED_PATH . 'helpers.php';
require VENDOR_PATH . 'autoload.php';
require VENDOR_PATH . 'sergeytsalkov/meekrodb/db.class.php';
require VENDOR_PATH . 'erusev/parsedown/Parsedown.php';

set_error_handler("exceptionErrorHandler", E_ALL);

$database = new MeekroDB(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT, DB_CHARSET);
$database->throw_exception_on_error = true;
$database->error_handler = false;

$parsedown = new Parsedown();

function patch($importEntity, $existingEntity, $mapping) {
    $result = array();
    $isModified = false;

    if (isset($existingEntity)) {
        $result["Id"] = $existingEntity["Id"];
        if ($existingEntity["IsDeleted"] == 1) $isModified = true;
        //Log::info("  Patching existing id=".$result["Id"]);
    } else {
        $isModified = true;
        $result["Id"] = GUID();
        Log::info("  Creating new id=".$result["Id"]);
    }
    $result["IsDeleted"] = 0;


    foreach($mapping as $iKey => $eKey) {
        if (isset($existingEntity) && isset($existingEntity[$eKey]) && $existingEntity[$eKey] == $importEntity[$iKey]) continue;
        $result[$eKey] = $importEntity[$iKey];
        @Log::info("  Id=".$result["Id"].", [".$eKey."] changed to: ".$result[$eKey]);
        $isModified = true;
    }

    if ($isModified) {
        $result["LastChangeDateTimeUtc"] = DB::sqleval("utc_timestamp()");
        Log::info("  Touching LastChangeDateTimeUtc of Id=".$result["Id"]);
    } else {
        Log::info( "  No changes on Id=".$result["Id"]);
    }

    if (!$isModified) return null;
    return $result;
}

$news = json_decode(file_get_contents('http://6al.de/efsched/getconnews'));

Log::info("Importing ConNews");
try {
    
    $database->startTransaction();

    foreach($news as $entry) {
	Log::info(sprintf("Id: %s, Type: %s -> %s", $entry->id, $entry->news->type, $entry->news->title));

	$dbItem = @$database->query("SELECT * FROM Announcement WHERE ExternalId=%s", "connews:".$entry->id)[0];
	if ($dbItem) {
		$dbItem["ValidFromDateTimeUtc"] = new DateTime($dbItem["ValidFromDateTimeUtc"]);
		$dbItem["ValidUntilDateTimeUtc"] = new DateTime($dbItem["ValidUntilDateTimeUtc"]);
	}
	
	if ($entry->news->type == "new" || $entry->news->type == "reschedule") {
		$entry->news->valid_until = $entry->date + (60 * 60 * 48);
	}

	$sourceItem = array(
		"ExternalId" => "connews:".$entry->id,
		"ValidFrom" => DateTime::createFromFormat( 'U', $entry->date ),
		"ValidUntil" => DateTime::createFromFormat( 'U', $entry->news->valid_until ),
		"Area" => ucwords($entry->news->type),
		"Author" => isset($entry->news->department) ? ucwords($entry->news->department) : "Eurofurence",
		"Title" => $entry->news->title,
		"Content" => strip_tags($parsedown->text($entry->news->message))
	);



	$patchedItem = patch($sourceItem, $dbItem, array(
		"ExternalId" => "ExternalId",
		"ValidFrom" => "ValidFromDateTimeUtc",
		"ValidUntil" => "ValidUntilDateTimeUtc",
		"Area" => "Area",
		"Author" => "Author",
		"Title" => "Title",
		"Content" => "Content"
	)); 

	if ($patchedItem) {
            if (!$dbItem) {
                $database->insert("Announcement", $patchedItem);
            } else {
                $database->update("Announcement", $patchedItem, "Id=%s", $dbItem["Id"]);
            }
	}

    } 

    $database->commit();
    
} catch (Exception $e) {
    
    var_dump($e->getMessage());
    
    Log::error("Rolling back changes to database");
    $database->rollback();
    
}

?>
