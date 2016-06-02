<?php

$fileName = isset($argv[1])? $argv[1] : "/temp/wiki.txt";
echo "Trying to import from file: " . $fileName ."\n";
if (!file_exists($fileName)) die("File dies not exist: " . $fileName . "\n");

// ----------------

define('ROOT_PATH', getcwd() . '/');
define('SHARED_PATH', ROOT_PATH . '../Shared/');
define('VENDOR_PATH', SHARED_PATH . './vendor/');

require SHARED_PATH . 'config.inc.php';
require SHARED_PATH . 'helpers.php';
require VENDOR_PATH . 'autoload.php';
require VENDOR_PATH . 'sergeytsalkov/meekrodb/db.class.php';
require VENDOR_PATH . 'parsecsv/php-parsecsv/parsecsv.lib.php';

use \YaLinqo\Enumerable;

set_error_handler("exceptionErrorHandler", E_ALL);

$database = new MeekroDB(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT, DB_CHARSET);
$database->throw_exception_on_error = true;
$database->error_handler = false;

// Full page source of http://wiki.eurofurence.org/doku.php?id=ef22:it:mobileapp:coninfo
$wikiText = file_get_contents($fileName);

$regexParseContent = "/<WRAP[^>]*>PARSE_START<\/WRAP>(.*)<WRAP[^>]*>PARSE_END<\/WRAP>/si";
$regexGroup = "/====([^=]+)====(.+?)((?=====)|$)/siu";
$regexEntry = "/===([^=]+)===.+?<WRAP box>(.+?)<\/WRAP>/si";

preg_match($regexParseContent, $wikiText, $matches);
$wikiTextToParse = trim($matches[1]);

preg_match_all($regexGroup, $wikiTextToParse, $groupMatches);

$position = 0;
$groupIds = array();

try {
    
    $database->startTransaction();
    $database->query("DELETE FROM Info");
    $database->query("DELETE FROM InfoGroup");
    
    foreach($groupMatches[1] as $id => $group)  {

        $groupId = GUID();
        $parts = explode("|", trim($group),2);
        
        echo sprintf("Importing Group %s\n", trim($parts[0]));
        
        $database->insert("InfoGroup", array(
                "Id" => $groupId,
                "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                "IsDeleted" => "0",
                "Name" => trim($parts[0]),
                "Description" => trim($parts[1]),
                "Position" => $position 
        ));
        
        $position++;
        preg_match_all($regexEntry, $groupMatches[2][$id], $entryMatches);
        $epos = 0;
        foreach($entryMatches[1] as $entryId => $entry)  {
            echo sprintf("  Importing Entry %s\n", trim($entry));
            
            $database->insert("Info", array(
                    "Id" => GUID(),
                    "InfoGroupId" => $groupId,
                    "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                    "IsDeleted" => "0",
                    "Title" => trim($entry),
                    "Text" => trim($entryMatches[2][$entryId]),
                    "Position" => $epos
            ));
            
            $epos++;
        }
    }

    echo "Commiting changes to database\n";
    $database->commit();
    
} catch (Exception $e) {
    
    var_dump($e->getMessage());
    
    echo "Rolling back changes to database\n";
    $database->rollback();
    
}

?>