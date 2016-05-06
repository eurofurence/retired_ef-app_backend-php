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

?>