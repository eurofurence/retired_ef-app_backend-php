<?php
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


$fileName = isset($argv[1])? $argv[1] : "/temp/efschedule.csv";
Log::info("Trying to import from file: " . $fileName);
if (!file_exists($fileName)) { Log::error("File dies not exist: " . $fileName); die(); };

$database = new MeekroDB(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT, DB_CHARSET);
$database->throw_exception_on_error = true;
$database->error_handler = false;

$csv = new parseCSV($fileName);
$eventQuery = Enumerable::from($csv->data);

function fixConferenceDayName($name) {
    $name = str_replace("Mon - ", "", $name);
    $name = str_replace("Tue - ", "", $name);
    $name = str_replace("Wed - ", "", $name);
    $name = str_replace("Thu - ", "", $name);
    $name = str_replace("Fri - ", "", $name);
    $name = str_replace("Sat - ", "", $name);
    $name = str_replace("Sun - ", "", $name);
    return $name;
}

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
        Log::info("  Id=".$result["Id"].", [".$eKey."] changed to: ".$result[$eKey]);
        $isModified = true;
    }
    
    if ($isModified) {
        $result["LastChangeDateTimeUtc"] = DB::sqleval("utc_timestamp()");
        Log::info("  Touching LastChangeDateTimeUtc of Id=".$result["Id"]);
    } else {
        //Log::info( "  No changes on Id=".$result["Id"]);
    }
    
    if (!$isModified) return null;
    return $result;
}

try {
    
    $database->startTransaction();

    /* Import Conference Tracks */
    Log::info("Importing Conference Tracks");

    $importConferenceTracks = Enumerable::from($eventQuery
        ->select('$v["conference_track"]')
        ->distinct()
        ->toList()
    );

    $importConferenceRooms = Enumerable::from($eventQuery
        ->select('$v["conference_room"]')
        ->distinct()
        ->toList()
    );
        
    $dbConferenceTracks = Enumerable::from($database->query("SELECT * FROM EventConferenceTrack"));

    $importConferenceTracks->each(function($iItem) use ($dbConferenceTracks, $database) {
        $dbItem = $dbConferenceTracks->where(function($a) use ($iItem) { return $a["Name"] == $iItem; })->singleOrDefault();
        $patchedItem = patch(array("Name" => $iItem), $dbItem, array("Name" => "Name"));
        
        if ($patchedItem) {
            if (!$dbItem) {
                $database->insert("EventConferenceTrack", $patchedItem);
            } else {
                $database->update("EventConferenceTrack", $patchedItem, "Id=%s", $dbItem["Id"]);
            } 
        }
    });

    $dbConferenceTracks
        ->where(function($a) use($importConferenceTracks) { 
                return 
                    $a["IsDeleted"] == 0 &&
                    $importConferenceTracks->where(function($b) use ($a) { return $b == $a["Name"]; })->count() == 0;		
            })
        ->each(function($a) use ($database) {
            $database->update("EventConferenceTrack", array(
                    "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                    "IsDeleted" => 1
            ), "Id=%s", $a["Id"]);
        });
        
    $dbConferenceTracks = Enumerable::from($database->query("SELECT * FROM EventConferenceTrack WHERE IsDeleted = 0"));
        
        
    Log::info("Importing Conference Rooms");
    
    $dbConferenceRooms = Enumerable::from($database->query("SELECT * FROM EventConferenceRoom"));

    $importConferenceRooms->each(function($iItem) use ($dbConferenceRooms, $database) {
        $dbItem = $dbConferenceRooms->where(function($a) use ($iItem) { return $a["Name"] == $iItem; })->singleOrDefault();
        $patchedItem = patch(array("Name" => $iItem), $dbItem, array("Name" => "Name"));

        if ($patchedItem) {
            if (!$dbItem) {
                $database->insert("EventConferenceRoom", $patchedItem);
            } else {
                $database->update("EventConferenceRoom", $patchedItem, "Id=%s", $dbItem["Id"]);
            } 
        }
    });

    $dbConferenceRooms->where(function($a) use($importConferenceRooms) {
        return
        $a["IsDeleted"] == 0 &&
        $importConferenceRooms->where(function($b) use ($a) { return $b == $a["Name"]; })->count() == 0;
    })
    ->each(function($a) use ($database) {
        $database->update("EventConferenceRoom", array(
                "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                "IsDeleted" => 1
        ), "Id=%s", $a["Id"]);
    });
    
    $dbConferenceRooms = Enumerable::from($database->query("SELECT * FROM EventConferenceRoom WHERE IsDeleted = 0"));
        

    /* Events */
    Log::info("Importing Conference Days");

    $importConferenceDays = Enumerable::from($eventQuery
            ->select(function($a) { return $a["conference_day"]; })
            ->distinct()
            ->select(function($a) use ($eventQuery) { 
                    return $eventQuery->where(function($b) use ($a) { return $b["conference_day"] == $a; })->first();
                })
            ->select(function($a) { return array("day" => $a["conference_day"], "name" => $a["conference_day_name"]); })
            ->toList()
        );

    $dbConferenceDays = Enumerable::from($database->query("SELECT * FROM EventConferenceDay"));  


    $importConferenceDays->each(function($iItem) use ($dbConferenceDays, $database) {
        $date = date_format(date_create($iItem["day"]), "Y-m-d");
        $iItem["date"] = $date;
        $iItem["name"] = fixConferenceDayName($iItem["name"]);

        
        $dbItem = $dbConferenceDays->where(function($a) use ($iItem) { return $a["Date"] == $iItem["date"]; })->singleOrDefault();
        
        $patchedItem = patch($iItem, $dbItem, array("date" => "Date", "name" => "Name"));

        if ($patchedItem) {
            if (!$dbItem) {
                $database->insert("EventConferenceDay", $patchedItem);
            } else {
                $database->update("EventConferenceDay", $patchedItem, "Id=%s", $dbItem["Id"]);
            } 
        }
    });

    $dbConferenceDays = Enumerable::from($database->query("SELECT * FROM EventConferenceDay WHERE IsDeleted = 0"));  

    /* Events */
    Log::info("Importing Events");

    $dbEntries= Enumerable::from($database->query("SELECT * FROM EventEntry WHERE IsDeleted = 0"));
    $importEntries = Enumerable::from($eventQuery->toList());

    $importEntries->each(function($iItem) use ($dbEntries, $database, $dbConferenceTracks, $dbConferenceDays, $dbConferenceRooms) {
        
        $dbItem = $dbEntries->where(function($a) use ($iItem) { return $a["SourceEventId"] == $iItem["event_id"]; })->singleOrDefault();
        $iItem["conference_day_name"] = fixConferenceDayName($iItem["conference_day_name"]);

        $conferenceTrack = $dbConferenceTracks->where(function($a) use ($iItem) { return $a["Name"] == $iItem["conference_track"]; })->singleOrDefault();
        $conferenceDay = $dbConferenceDays->where(function($a) use ($iItem) { return $a["Name"] == $iItem["conference_day_name"]; })->singleOrDefault();
        $conferenceRoom = $dbConferenceRooms->where(function($a) use ($iItem) { return $a["Name"] == $iItem["conference_room"]; })->singleOrDefault();
        
        $iItem["conference_track_id"] = isset($conferenceTrack) ? $conferenceTrack["Id"] : "";
        $iItem["conference_day_id"] = isset($conferenceDay) ? $conferenceDay["Id"] : "";
        $iItem["conference_room_id"] = isset($conferenceRoom) ? $conferenceRoom["Id"] : "";
        
        $parts = explode("–", $iItem["title"]);
        $iItem["title"] = $parts[0];
        $iItem["subtitle"] = sizeof($parts) == 2 ? trim($parts[1]) : "";
        $iItem["is_deviating_from_conbook"] = 0;
        
        
        $patchedItem = patch($iItem, $dbItem, array(
            "event_id" => "SourceEventId",
            "slug" => "Slug",
            "conference_track_id" => "ConferenceTrackId",
            "conference_day_id" => "ConferenceDayId",
            "conference_room_id" => "ConferenceRoomId",
            "title" => "Title",
            "subtitle" => "SubTitle",
            "abstract" => "Abstract",
            "description" => "Description",
            "start_time" => "StartTime",
            "end_time" => "EndTime",
            "duration" => "Duration",
            "pannel_hosts" => "PanelHosts",
            "is_deviating_from_conbook" => "IsDeviatingFromConBook"
        ));
            
        if ($patchedItem) {
            if (!$dbItem) {
                $database->insert("EventEntry", $patchedItem);
            } else {
                $database->update("EventEntry", $patchedItem, "Id=%s", $dbItem["Id"]);
            } 
        }
    });

    Log::info("Commiting changes to database");
    $database->commit();
    
} catch (Exception $e) {
    
    var_dump($e->getMessage());
    
    Log::error("Rolling back changes to database");
    $database->rollback();

}

?>
