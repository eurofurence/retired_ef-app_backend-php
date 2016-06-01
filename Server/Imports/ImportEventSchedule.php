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

$database = new MeekroDB(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT, DB_CHARSET);
$database->throw_exception_on_error = true;
$database->error_handler = false;

$csv = new parseCSV('/temp/efschedule.csv');
$eventQuery = Enumerable::from($csv->data);

try {
    
    $database->startTransaction();

    /* Import Conference Tracks */
    echo "Importing Conference Tracks\n";

    $importConferenceTracks = Enumerable::from($eventQuery
        ->select(function($a) { return $a["conference_track"]; })
        ->distinct()
        ->toList()
    );

    $importConferenceRooms = Enumerable::from($eventQuery
        ->select(function($a) { return $a["conference_room"]; })
        ->distinct()
        ->toList()
    );
        
    $dbConferenceTracks = Enumerable::from($database->query("SELECT * FROM EventConferenceTrack WHERE IsDeleted = 0"));
    $dbConferenceRooms = Enumerable::from($database->query("SELECT * FROM EventConferenceRoom WHERE IsDeleted = 0"));

    $importConferenceTracks->each(function($iItem) use ($dbConferenceTracks, $database) {
        $dbItem = $dbConferenceTracks->where(function($a) use ($iItem) { return $a["Name"] == $iItem; })->singleOrDefault();
        
        if (!$dbItem) {
            echo("  Importing " . $iItem . "\n");
            
            $database->insert("EventConferenceTrack", array(
                "Id" => $database->sqleval("uuid()"),
                "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                "IsDeleted" => "0",
                "Name" => $iItem			
            ));
        } else {
            echo "  Existing: " . $iItem ." (" .$dbItem["Id"].")\n";
            
            if ($dbItem["IsDeleted"] == 1) {
                $database->update("EventConferenceTrack", array(
                        "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                        "IsDeleted" => 0
                ), "Id=%s", $dbItem["Id"]);
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
        
        
    echo "\n\nImporting Conference Rooms\n";

    $importConferenceRooms->each(function($iItem) use ($dbConferenceRooms, $database) {
        $dbItem = $dbConferenceRooms->where(function($a) use ($iItem) { return $a["Name"] == $iItem; })->singleOrDefault();

        if (!$dbItem) {
            echo("  Importing " . $iItem . "\n");

            $database->insert("EventConferenceRoom", array(
                    "Id" => $database->sqleval("uuid()"),
                    "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                    "IsDeleted" => "0",
                    "Name" => $iItem
            ));
        } else {
            echo "  Existing: " . $iItem ." (" .$dbItem["Id"].")\n";

            if ($dbItem["IsDeleted"] == 1) {
                $database->update("EventConferenceRoom", array(
                        "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                        "IsDeleted" => 0
                ), "Id=%s", $dbItem["Id"]);
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
        
        
    echo "\n\n";

    /* Events */
    echo "Importing Conference Days\n";

    $importConferenceDays = Enumerable::from($eventQuery
            ->select(function($a) { return $a["conference_day"]; })
            ->distinct()
            ->select(function($a) use ($eventQuery) { 
                    return $eventQuery->where(function($b) use ($a) { return $b["conference_day"] == $a; })->first();
                })
            ->select(function($a) { return array("day" => $a["conference_day"], "name" => $a["conference_day_name"]); })
            ->toList()
        );

    $dbConferenceDays = Enumerable::from($database->query("SELECT * FROM EventConferenceDay WHERE IsDeleted = 0"));  


    $importConferenceDays->each(function($iItem) use ($dbConferenceDays, $database) {
        $date = date_format(date_create($iItem["day"]), "Y-m-d");
        $dbItem = $dbConferenceDays->where(function($a) use ($date) { return $a["Date"] == $date; })->singleOrDefault();

        if (!$dbItem) {
            echo("  Importing " . $date . "\n");
            
            $database->insert("EventConferenceDay", array(
                    "Id" => $database->sqleval("uuid()"),
                    "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                    "IsDeleted" => "0",
                    "Date" => $date,
                    "Name" => $iItem["name"]
            ));
        } else {
            echo "  Existing: " . $date ." (" .$dbItem["Id"].")\n";
        }
    });


    /* Events */
    echo "\n\nImporting Events\n";

    $dbEntries= Enumerable::from($database->query("SELECT * FROM EventEntry WHERE IsDeleted = 0"));
    $importEntries = Enumerable::from($eventQuery->toList());

    $importEntries->each(function($iItem) use ($dbEntries, $database, $dbConferenceTracks, $dbConferenceDays, $dbConferenceRooms) {
        
        $dbItem = $dbEntries->where(function($a) use ($iItem) { return $a["SourceEventId"] == $iItem["event_id"]; })->singleOrDefault();

        if (!$dbItem) {
            echo("  Importing " . $iItem["event_id"] . "\n");

            
            $dbConferenceTrack = $dbConferenceTracks->where(function($a) use ($iItem) { return $a["Name"] == $iItem["conference_track"]; })->singleOrDefault();
            $dbConferenceDay = $dbConferenceDays->where(function($a) use ($iItem) { return $a["Name"] == $iItem["conference_day_name"]; })->singleOrDefault();
            $dbConferenceRoom = $dbConferenceRooms->where(function($a) use ($iItem) { return $a["Name"] == $iItem["conference_room"]; })->singleOrDefault();
            
            $database->insert("EventEntry", array(
                    "Id" => $database->sqleval("uuid()"),
                    "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                    "IsDeleted" => "0",
                    "SourceEventId" => $iItem["event_id"],
                    "Slug" => $iItem["slug"],
                    "ConferenceTrackId" => $dbConferenceTrack["Id"],
                    "Title" => $iItem["title"],
                    "ConferenceDayId" => $dbConferenceDay["Id"],
                    "Abstract" => $iItem["abstract"],
                    "Description" => $iItem["description"],
                    "StartTime" => $iItem["start_time"],
                    "EndTime" => $iItem["end_time"],
                    "Duration" => $iItem["duration"],
                    "ConferenceRoomId" => $dbConferenceRoom["Id"],
                    "PanelHosts" => $iItem["pannel_hosts"]
            ));
        } else {
            echo "  Existing: " .  $iItem["event_id"] ."\n";
        }
    });

    echo "Commiting changes to database\n";
    $database->commit();
    
} catch (Exception $e) {
    
    var_dump($e->getMessage());
    
    echo "Rolling back changes to database\n";
    $database->rollback();

}

?>