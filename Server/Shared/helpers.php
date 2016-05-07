<?php

function startsWith($haystack, $needle)
{
     $length = strlen($needle);
     return (substr($haystack, 0, $length) === $needle);
}

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }

    return (substr($haystack, -$length) === $needle);
}

function getZipContentsAsQueryable($zipFilePath) {
    $zip = new ZipArchive();
    $zip->open($zipFilePath);
    $zipContents = [];

    for ($i=0; $i<$zip->numFiles;$i++) {
        $zipContents[] = $zip->statIndex($i);
    }
    
    return from($zipContents);
}

function getZipContentOfFile($zipFilePath, $name) {
    $zip = new ZipArchive();
    $zip->open($zipFilePath);
    
    $fp = $zip->getStream($name);
    $contents = '';
    while (!feof($fp)) {
        $contents .= fread($fp, 1024);
    }

    fclose($fp);

    return $contents;
}

function exceptionErrorHandler($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
}

function GUID()
{
	if (function_exists('com_create_guid') === true)
	{
		return trim(com_create_guid(), '{}');
	}

	return strtolower(sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535)));
}

function insertOrUpdateImageByTitle($database, $imageData, $title){
    $imageInfo = getimagesizefromstring($imageData);
    $existingImageRow = $database->queryFirstRow("SELECT * FROM image WHERE Title=%s", $title);
    $imageId = $existingImageRow ? $existingImageRow["Id"] : GUID();
    
    $row = array(
        "Id" => $imageId,
        "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
        "IsDeleted" => "0",
        "Title" => $title,
        "Height" => $imageInfo[1],
        "Width" => $imageInfo[0],
        "MimeType" => $imageInfo['mime'],
        "FileSizeInBytes" => strlen($imageData),
        "Url" => sprintf("{Endpoint}/ImageData/%s", $imageId)
    );
    
    if (!$existingImageRow) {
        $database->insert("imagedata", array("Id" => $row["Id"], "Data" => $imageData));
        $database->insert("image", $row);
    } else {
        $existingDataRow = $database->queryFirstRow("SELECT * FROM imagedata WHERE Id=%s", $imageId);

        if ($existingDataRow["Data"] != $imageData) {
            $database->update("imagedata", array("Id" => $row["Id"], "Data" => $imageData), "Id=%s", $imageId);
            $database->update("image", $row, "Id=%s", $imageId);
        }
    }

    return $imageId;
}

function deleteImageByTitle($database, $title) {
    $row = $database->queryFirstRow("SELECT * FROM image WHERE Title=%s", $title);
    if (!$row) return;
    
    $database->update("image", array(
        "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
        "IsDeleted" => 1), "Id=%s", $row["Id"]);
    $database->query("DELETE FROM imagedata WHERE Id=%s", $row["Id"]);        
}

?>