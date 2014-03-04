#!/usr/bin/php

<?php
/*
 * This file is a grabber which downloads data from Schedules Direct's JSON service.
 * Robert Kulagowski
 * grabber@schedulesdirect.org
 *
 */

/*
 * We need a bit of memory to process schedules, so request it right at the beginning.
 */

ini_set("memory_limit", "512M");

require_once "vendor/autoload.php";
require_once "functions.php";
use Guzzle\Http\Client;

$isBeta = TRUE;
$debug = TRUE;
$quiet = FALSE;
$sdStatus = "";
$printTimeStamp = TRUE;
$scriptVersion = "0.09";
$scriptDate = "2014-03-04";
$maxProgramsToGet = 2000;
$errorWarning = FALSE;

$agentString = "mfdb-json.php developer grabber v$scriptVersion/$scriptDate";

date_default_timezone_set(@date_default_timezone_get());
//date_default_timezone_set("UTC");
$date = new DateTime();
$todayDate = $date->format("Y-m-d");
$fh_log = fopen("$todayDate.log", "a");

printMSG("$agentString\n");
$dlSchedTempDir = tempdir();
printMSG("Temp directory for Schedules is $dlSchedTempDir\n");
$dlProgramTempDir = tempdir();
printMSG("Temp directory for Programs is $dlProgramTempDir\n");

$jsonProgramstoRetrieve = array();
$peopleCache = array();

$dbuser = "mythtv";
$dbpassword = "mythtv";
$dbhost = "localhost";
$db = "mythconverg";

$longoptions = array("beta::", "dbhost::", "dbpassword::", "dbuser::", "debug::", "help::", "max::");
$options = getopt("h::", $longoptions);

foreach ($options as $k => $v)
{
    switch ($k)
    {
        case "beta":
            $isBeta = TRUE;
            break;
        case "debug":
            $debug = TRUE;
            break;
        case "help":
        case "h":
            print "The following options are available:\n";
            print "--beta\n";
            print "--help\t\t(this text)\n";
            print "--dbhost=\texample: --host=192.168.10.10 (Default:$dbhost)\n";
            print "--dbuser=\tUsername to connect to MythTV database (Default:$dbuser)\n";
            print "--dbpassword=\tPassword to access MythtTV database (Default:$dbpassword)\n";
            print "--max\t\tMaximum number of programs to retrieve per request. (Default:$maxProgramsToGet)\n";
            exit;
        case "dbhost":
            $dbhost = $v;
            break;
        case "dbpassword":
            $dbpassword = $v;
            break;
        case "dbuser":
            $dbuser = $v;
            break;
        case "max":
            $maxProgramsToGet = $v;
            break;
    }
}

printMSG("Connecting to MythTV database.\n");
try
{
    $dbh = new PDO("mysql:host=$dbhost;dbname=$db;charset=utf8", $dbuser, $dbpassword,
        array(PDO::ATTR_PERSISTENT => true));
    $dbh->exec("SET CHARACTER SET utf8");
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
} catch (PDOException $e)
{
    print "Exception with PDO: " . $e->getMessage() . "\n";
    exit;
}

if ($isBeta)
{
    # Test server. Things may be broken there.
    $baseurl = "https://json.schedulesdirect.org/20131021/";
    printMSG("Using beta server.\n");
    # API must match server version.
    $api = 20131021;
}
else
{
    $baseurl = "https://data2.schedulesdirect.org";
    printMSG("Using production server.\n");
    $api = 20130709;
}

$client = new Guzzle\Http\Client($baseurl);
$client->setUserAgent($agentString);

$stmt = $dbh->prepare("SELECT userid,password FROM videosource WHERE xmltvgrabber='schedulesdirect2'
AND password != '' LIMIT 1");
$stmt->execute();
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!count($result))
{
    printMSG("Fatal Error: Could not read userid and password for schedulesdirect2 grabber from videosource.\n");
    printMSG("Did you run the sd-utility.php program yet?\n");
    exit;
}

$sdUsername = $result[0]["userid"];
$sdPassword = sha1($result[0]["password"]);

$globalStartTime = time();
$globalStartDate = new DateTime();

printMSG("Retrieving list of channels to download.\n");
$stmt = $dbh->prepare("SELECT DISTINCT(xmltvid) FROM channel WHERE visible=TRUE AND xmltvid != ''");
$stmt->execute();
$stationIDs = $stmt->fetchAll(PDO::FETCH_COLUMN);

printMSG("Logging into Schedules Direct.\n");
$token = getToken($sdUsername, $sdPassword);
printMSG("Retrieving server status message.\n");

$response = updateStatus();

if ($response == "No new data on server.")
{
    $statusMessage = "No new programs to retrieve.";
}

if ($token != "ERROR" AND $response != "ERROR")
{
    $jsonProgramstoRetrieve = getSchedules($stationIDs);
}
else
{
    print "Error connecting to Schedules Direct.\n";
    $statusMessage = "Error connecting to Schedules Direct.";
}

if (count($jsonProgramstoRetrieve))
{
    insertJSON($jsonProgramstoRetrieve);
    insertSchedule();
    $statusMessage = "Successful.";
}
else
{
    $statusMessage = "No new programs to retrieve.";
}

printMSG("Status:$statusMessage\n");

$globalStartTime = date("Y-m-d H:i:s", $globalStartTime);
$globalEndTime = date("Y-m-d H:i:s");

printMSG("Global. Start Time:$globalStartTime\n");
printMSG("Global. End Time:$globalEndTime\n");
$globalSinceStart = $globalStartDate->diff(new DateTime());
if ($globalSinceStart->h)
{
    printMSG($globalSinceStart->h . " hour ");
}
printMSG($globalSinceStart->i . " minutes " . $globalSinceStart->s . " seconds.\n");

printMSG("Updating status.\n");

$stmt = $dbh->prepare("UPDATE settings SET data=:data WHERE value='mythfilldatabaseLastRunStart' AND hostname IS NULL");
$stmt->execute(array("data" => $globalStartTime));

$stmt = $dbh->prepare("UPDATE settings SET data=:data WHERE value='mythfilldatabaseLastRunEnd' AND hostname IS NULL");
$stmt->execute(array("data" => $globalEndTime));

$stmt = $dbh->prepare("UPDATE settings SET data=:data WHERE value='mythfilldatabaseLastRunStatus' AND hostname IS NULL");
$stmt->execute(array("data" => $statusMessage));

printMSG("Done.\n");

if ($errorWarning)
{
    printMSG("NOTE! Errors encountered during processing. Check logs.\n");
}

exit;

function getSchedules($stationIDs)
{
    global $client;
    global $dbh;
    global $token;
    global $dlProgramTempDir;
    global $dlSchedTempDir;
    global $maxProgramsToGet;

    $dbProgramCache = array();

    $downloadedStationIDs = array();
    $serverScheduleMD5 = array();

    printMSG("Sending schedule request.\n");

    $body["request"] = $stationIDs;

    try {
        $response = $client->post("schedules", array("token" => $token, "Accept-Encoding" => "deflate,gzip"),
            json_encode($body))->send();
    } catch (Guzzle\Http\Exception\BadResponseException $e) {
        if ($e->getCode() == 400)
        {
            return("ERROR");
        }
    }

    $resBody = $response->getBody();

    /*
     * Keep a copy for troubleshooting.
     */

    printMSG("Writing to $dlSchedTempDir/schedule.json\n");

    file_put_contents("$dlSchedTempDir/schedule.json", $resBody);

    $f = file("$dlSchedTempDir/schedule.json", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($f as $json)
    {
        $item = json_decode($json, TRUE);
        $stationID = $item["stationID"];
        $downloadedStationIDs[] = $stationID;

        printMSG("Parsing schedule for stationID:$stationID\n");

        foreach ($item["programs"] as $programData)
        {
            $serverScheduleMD5[$programData["md5"]] = $programData["programID"];
        }
    }

    printMSG("There are " . count($serverScheduleMD5) . " programIDs in the upcoming schedule.\n");
    printMSG("Retrieving existing MD5 values.\n");

    /*
     * We're going to figure out which programIDs we need to download.
     */

    $stmt = $dbh->prepare("SELECT md5, programID FROM SDprogramCache");
    $stmt->execute();
    $dbProgramCache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $jsonProgramstoRetrieve = array_diff_key($serverScheduleMD5, $dbProgramCache);

    $toRetrieveTotal = count($jsonProgramstoRetrieve);

    /*
     * Now we've got an array of programIDs that we need to download in $toRetrieve,
     * either because we didn't have them, or they have different md5's.
     */

    printMSG("Need to download $toRetrieveTotal new or updated programs.\n");

    if ($toRetrieveTotal > 10000)
    {
        printMSG("Requesting more than 10000 programs. Please be patient.\n");
    }

    printMSG("Maximum programs we're downloading per call: $maxProgramsToGet\n");

    if (count($jsonProgramstoRetrieve))
    {
        $totalChunks = intval($toRetrieveTotal / $maxProgramsToGet);

        $counter = 0;

        for ($i = 0; $i <= $totalChunks; $i++)
        {
            printMSG("Retrieving chunk " . ($i + 1) . " of " . ($totalChunks + 1) . ".\n");
            $startOffset = $i * $maxProgramsToGet;
            $chunk = array_slice($jsonProgramstoRetrieve, $startOffset, $maxProgramsToGet);

            $body["request"] = $chunk;

            $counter += count($chunk);

            $request = $client->post("programs", array("token" => $token, "Accept-Encoding" => "deflate,gzip"),
                json_encode($body));
            $response = $request->send();

            $resBody = $response->getBody();

            file_put_contents("$dlProgramTempDir/programs." . substr("00$i", -2) . ".json", $resBody);
        }
    }

    return ($jsonProgramstoRetrieve);
}

function insertJSON(array $jsonProgramstoRetrieve)
{
    global $dbh;
    global $dlProgramTempDir;
    global $debug;

    $insertJSON = $dbh->prepare("INSERT INTO SDprogramCache(programID,md5,json)
            VALUES (:programID,:md5,:json)
            ON DUPLICATE KEY UPDATE md5=:md5, json=:json");

    $insertPersonSD = $dbh->prepare("INSERT INTO SDpeople(personID,name) VALUES(:personID, :name)
        ON DUPLICATE KEY UPDATE name=:name");
    $insertPersonMyth = $dbh->prepare("INSERT INTO people(name) VALUES(:name)");

    $insertCreditSD = $dbh->prepare("INSERT INTO SDcredits(personID,programID,role)
    VALUES(:personID,:pid,:role)");

    $insertProgramGenresSD = $dbh->prepare("INSERT INTO SDprogramgenres(programID,relevance,genre)
    VALUES(:pid,:relevance,:genre) ON DUPLICATE KEY UPDATE genre=:genre");

    $getPeople = $dbh->prepare("SELECT name,person FROM people");
    $getPeople->execute();
    $peopleCacheMyth = $getPeople->fetchAll(PDO::FETCH_KEY_PAIR);

    $getPeople = $dbh->prepare("SELECT personID,name FROM SDpeople");
    $getPeople->execute();
    $peopleCacheSD = $getPeople->fetchAll(PDO::FETCH_KEY_PAIR);

    $getCredits = $dbh->prepare("SELECT CONCAT(personID,'-',programID,'-',role) FROM SDcredits");
    $getCredits->execute();
    $creditCache = $getCredits->fetchAll(PDO::FETCH_COLUMN);

    $creditCache = array_flip($creditCache);

    $counter = 0;
    $total = count($jsonProgramstoRetrieve);
    printMSG("Performing inserts of JSON data.\n");

    $dbh->beginTransaction();

    foreach (glob("$dlProgramTempDir/*.json") as $jsonFileToProcess)
    {
        $a = file($jsonFileToProcess);

        while (list($key, $item) = each($a))
        {
            $counter++;
            if ($counter % 100 == 0)
            {
                printMSG("$counter / $total             \r");
                $dbh->commit();
                $dbh->beginTransaction();
            }

            $jsonProgram = json_decode($item, TRUE);

            $pid = $jsonProgram["programID"];
            $md5 = $jsonProgram["md5"];

            if (json_last_error())
            {
                printMSG("*** ERROR: JSON decode error $dlProgramTempDir/$jsonFileToProcess - $pid\n");
                printMSG(print_r($item, TRUE));
                continue;
            }

            $insertJSON->execute(array("programID" => $pid, "md5" => $md5,
                                       "json"      => $item));

            $skipPersonID = FALSE;

            if (isset($jsonProgram["genres"]))
            {
                $relevance = 0; // We're going to assume that at some point we might do something with relevance.
                foreach ($jsonProgram["genres"] as $g)
                {
                    switch ($g)
                    {
                        case "Adults only":
                        case "Erotic":
                            $skipPersonID = TRUE;
                            break;
                    }
                    $insertProgramGenresSD->execute(array("pid"       => $pid,
                                                          "relevance" => ++$relevance, "genre" => $g));
                }
            }

            if ($skipPersonID == FALSE)
            {
                if (isset($jsonProgram["cast"]))
                {
                    foreach ($jsonProgram["cast"] as $credit)
                    {
                        $role = $credit["role"];
                        if (!isset($credit["personId"]))
                        {
                            printMSG("$jsonFileToProcess:$pid does not have a personId.\n");
                            continue;
                        }
                        $personID = $credit["personId"];

                        $name = $credit["name"];

                        if (!isset($peopleCacheSD[$personID]) OR $peopleCacheSD[$personID] != $name)
                        {
                            $insertPersonSD->execute(array("personID" => (int)$personID, "name" => $name));
                        }

                        if (!isset($peopleCacheMyth[$name]))
                        {
                            $insertPersonMyth->execute(array("name" => $name));
                            $id = $dbh->lastInsertId();
                            $peopleCacheMyth[$name] = $id;
                        }

                        if (!isset($creditCache["$personID-$pid-$role"]))
                        {
                            $insertCreditSD->execute(array("personID" => (int)$personID, "pid" => $pid,
                                                           "role"     => $role));
                            $creditCache["$personID-$pid-$role"] = 1;
                        }
                    }
                }

                if (isset($jsonProgram["crew"]))
                {
                    foreach ($jsonProgram["crew"] as $credit)
                    {
                        $role = $credit["role"];
                        if (!isset($credit["personId"]))
                        {
                            printMSG("$jsonFileToProcess:$pid does not have a personId.\n");
                            continue;
                        }
                        $personID = $credit["personId"];
                        $name = $credit["name"];

                        if (!isset($peopleCacheSD[$personID]) OR $peopleCacheSD[$personID] != $name)
                        {
                            $insertPersonSD->execute(array("personID" => (int)$personID, "name" => $name));
                        }

                        if (!isset($peopleCacheMyth[$name]))
                        {
                            $insertPersonMyth->execute(array("name" => $name));
                            $id = $dbh->lastInsertId();
                            $peopleCacheMyth[$name] = $id;
                        }

                        if (!isset($creditCache["$personID-$pid-$role"]))
                        {
                            $insertCreditSD->execute(array("personID" => (int)$personID, "pid" => $pid,
                                                           "role"     => $role));
                            $creditCache["$personID-$pid-$role"] = 1;
                        }
                    }
                }
            }

            if (isset($jsonProgram["genres"]))
            {
                foreach ($jsonProgram["genres"] as $relevance => $genre)
                {

                }
            }

            if ($debug == FALSE)
            {
                unlink("$dlProgramTempDir/$jsonFileToProcess");
            }
        }
    }

    if ($debug == FALSE)
    {
        unlink("$dlProgramTempDir/serverID.txt");
        unlink("$dlProgramTempDir/programs.json.zip");
        rmdir("$dlProgramTempDir");
    }

    $dbh->commit();

    printMSG("Completed local database program updates.\n");
}

function insertSchedule()
{
    global $dbh;
    global $dlSchedTempDir;
    global $peopleCache;
    global $debug;
    global $errorWarning;

    if (!count($peopleCache))
    {
        /*
         * People cache array is empty, so read it in.
         */
        $getPeople = $dbh->prepare("SELECT name,person FROM people");
        $getPeople->execute();
        $peopleCache = $getPeople->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    $roleTable = array();

    printMSG("Inserting schedules.\n");

    $dbh->exec("DROP TABLE IF EXISTS t_SDschedule");
    $dbh->exec("CREATE TABLE t_SDschedule LIKE SDschedule");

    $dbh->exec("DROP TABLE IF EXISTS t_program");
    $dbh->exec("CREATE TABLE t_program LIKE program");

    $dbh->exec("DROP TABLE IF EXISTS t_credits");
    $dbh->exec("CREATE TABLE t_credits LIKE credits");

    $dbh->exec("DROP TABLE IF EXISTS t_programrating");
    $dbh->exec("CREATE TABLE t_programrating LIKE programrating");

    $insertScheduleSD = $dbh->prepare("INSERT IGNORE INTO t_SDschedule(stationID,programID,md5,air_datetime,duration,
    previouslyshown,closecaptioned,partnumber,parttotal,first,last,dvs,new,educational,hdtv,3d,letterbox,stereo,
    dolby,dubbed,dubLanguage,subtitled,subtitleLanguage,sap,sapLanguage,programLanguage,tvRatingSystem,tvRating,
    dialogRating,languageRating,sexualContentRating,violenceRating,fvRating)
    VALUES(:stationID,:programID,:md5,:air_datetime,:duration,
    :previouslyshown,:closecaptioned,:partnumber,:parttotal,:first,:last,:dvs,:new,:educational,:hdtv,:3d,
    :letterbox,:stereo,:dolby,:dubbed,:dubLanguage,:subtitled,:subtitleLanguage,:sap,:sapLanguage,:programLanguage,
    :ratingSystem,:tvRating,:dialogRating,:languageRating,:sexualContentRating,:violenceRating,:fvRating)");

    $insertSchedule = $dbh->prepare("INSERT INTO t_program(chanid,starttime,endtime,title,subtitle,description,
    category,category_type,airdate,stars,previouslyshown,stereo,subtitled,hdtv,closecaptioned,partnumber,parttotal,
    seriesid,originalairdate,showtype,colorcode,syndicatedepisodenumber,programid,generic,listingsource,first,last,
    audioprop,subtitletypes,videoprop,season,episode)

    VALUES(:chanid,:starttime,:endtime,:title,:subtitle,:description,:category,:category_type,:airdate,:stars,
    :previouslyshown,:stereo,:subtitled,:hdtv,:closecaptioned,:partnumber,:parttotal,
    :seriesid,:originalairdate,:showtype,:colorcode,:syndicatedepisodenumber,:programid,:generic,:listingsource,
    :first,:last,:audioprop,:subtitletypes,:videoprop,:season,:episode)");

    $insertCreditMyth = $dbh->prepare("INSERT INTO t_credits(person,chanid,starttime,role)
    VALUES(:person,:chanid,:starttime,:role)");

    $insertProgramRatingMyth = $dbh->prepare("INSERT INTO t_programrating(chanid,starttime,system,rating)
    VALUES(:chanid,:starttime,:system,:rating)");

    $getExistingChannels = $dbh->prepare("SELECT chanid,sourceid,xmltvid FROM channel WHERE visible=1
    AND xmltvid != '' ORDER BY xmltvid");
    $getExistingChannels->execute();
    $existingChannels = $getExistingChannels->fetchAll(PDO::FETCH_ASSOC);

    $getProgramInformation = $dbh->prepare("SELECT json FROM SDprogramCache WHERE programID=:pid");

    $jsonSchedule = array();

    foreach (glob("$dlSchedTempDir/*.json") as $jsonFileToProcess)
    {
        $scheduleTemp = file($jsonFileToProcess);
    }

    /*
     * Move the schedule into an associative array so that we can process the items per stationID. We're going to
     * decode this once so that we're not doing it over and over again. May increase memory foootprint though.
     */

    $scheduleJSON = array();
    while (list($key, $item) = each($scheduleTemp))
    {
        $tempJSON = json_decode($item, TRUE);
        $stationID = $tempJSON["stationID"];
        $scheduleJSON[$stationID] = $tempJSON["programs"];
    }

    /*
     * Now that we're done, reset the array to empty.
     */
    $scheduleTemp = array();

    while (list($key, $item) = each($existingChannels))
    {
        $chanID = $item["chanid"];
        $sourceID = $item["sourceid"];
        $stationID = $item["xmltvid"];
        printMSG("Inserting schedule for chanid:$chanID sourceid:$sourceID xmltvid:$stationID\n");

        $dbh->beginTransaction();

        while (list($dummy, $schedule) = each($scheduleJSON[$stationID]))
        {
            /*
             * Pre-declare what we'll be using to quiet warning about unused variables.
             */

            $isNew = FALSE;
            $previouslyshown = FALSE;

            $title = "";
            $ratingSystem = "";
            $rating = "";
            $movieYear = "";
            $starRating = 0;
            $colorCode = "";
            $syndicatedEpisodeNumber = "";
            $showType = "";
            $oad = NULL;
            $audioprop = "";
            $subtitleTypes = "";
            $videoProperties = "";
            $partNumber = 0;
            $numberOfParts = 0;
            $season = 0;
            $episode = 0;

            /*
             * The various audio properties
             */
            $isClosedCaption = FALSE;
            $dvs = FALSE;
            $dolbyType = NULL;
            $dubbed = FALSE;
            $isStereo = FALSE;
            $isSubtitled = FALSE;
            $isSurround = FALSE;

            /*
             * Video properties
             */
            $is3d = FALSE;
            $isEnhancedResolution = FALSE; // Better than SD, not as good as HD
            $isHDTV = FALSE;
            $isLetterboxed = FALSE;
            $isSDTV = FALSE;

            /*
             * Optional booleans, some of which aren't used by MythTV
             */

            $cableInTheClassroom = FALSE; // Not used by MythTV
            $catchupProgram = FALSE; // Used in the UK; indicates that a program is available online
            $continuedProgram = FALSE; // Continued from a previous broadcast
            $isEducational = FALSE;
            $joinedInProgress = FALSE;
            $leftInProgress = FALSE;
            $isPremiere = FALSE; // First showing of a movie or TV series
            $programBreak = FALSE; // Program stops and will continue later; typically only found in UK
            $repeat = FALSE;
            $isSigned = FALSE; // supplemented with a person signing for the hearing impaired
            $subjectToBlackout = FALSE;
            $timeApproximate = FALSE;
            $isPremiereOrFinale = FALSE; // Season Premiere | Season Finale | Series Premiere | Series Finale
            $liveOrTapeDelay = NULL;

            /*
             * These are updated in another part of mfdb?
             */
            $isFirst = 0;
            $isLast = 0;

            $programID = $schedule["programID"];
            $getProgramInformation->execute(array("pid" => $programID));
            $pj = $getProgramInformation->fetchColumn();
            $programJSON = json_decode($pj, TRUE);

            if (json_last_error())
            {
                printMSG("Error retrieving / decoding $programID from local database. Raw data was:\n");
                printMSG(print_r($pj, TRUE) . "\n");
                $errorWarning = TRUE;
                continue;
            }

            $md5 = $schedule["md5"];
            $air_datetime = $schedule["airDateTime"];
            $duration = $schedule["duration"];

            $programStartTimeMyth = str_replace("T", " ", $air_datetime);
            $programStartTimeMyth = rtrim($programStartTimeMyth, "Z");
            $programEndTimeMyth = gmdate("Y-m-d H:i:s", strtotime("$air_datetime + $duration seconds"));

            if (isset($schedule["audioProperties"]))
            {
                foreach ($schedule["audioProperties"] as $ap)
                {
                    if ($ap == "cc")
                    {
                        $isClosedCaption = TRUE;
                    }

                    if ($ap == "dvs")
                    {
                        $dvs = TRUE;
                    }

                    if ($ap == "Dolby")
                    {
                        $dolbyType = "dolby";
                    }

                    if ($ap == "DD")
                    {
                        $dolbyType = "Dolby Digital";
                    }

                    if ($ap == "DD 5.1")
                    {
                        $dolbyType = "Dolby Digital 5.1";
                    }

                    if ($ap == "dubbed")
                    {
                        $dubbed = TRUE;
                    }

                    if ($ap == "stereo")
                    {
                        $isStereo = TRUE;
                    }

                    if ($ap == "subtitled")
                    {
                        $isSubtitled = TRUE;
                    }

                    if ($ap == "surround")
                    {
                        $isSurround = TRUE;
                    }
                }
            }

            if (isset($schedule["videoProperties"]))
            {
                foreach ($schedule["videoProperties"] as $vp)
                {
                    if ($vp == "3d")
                    {
                        $is3d = TRUE;
                    }

                    if ($vp == "enhanced")
                    {
                        $isEnhancedResolution = TRUE;
                    }

                    if ($vp == "hdtv")
                    {
                        $isHDTV = TRUE;
                    }

                    if ($vp == "letterbox")
                    {
                        $isLetterboxed = TRUE;
                    }

                    if ($vp == "sdtv")
                    {
                        $isSDTV = TRUE; //Not used in MythTV
                    }
                }
            }

            if (isset($schedule["isPremiereOrFinale"]))
            {
                switch ($schedule["isPremiereOrFinale"])
                {
                    case "Series Premiere":
                    case "Season Premiere":
                        $isFirst = TRUE;
                        break;
                    case "Series Finale":
                    case "Season Finale":
                        $isLast = TRUE;
                        break;
                }
            }
            else
            {
                $isFirst = FALSE;
                $isLast = FALSE;
            }

            if (isset($schedule["ratings"]))
            {
                foreach ($schedule["ratings"] as $r)
                {
                    if ($r["body"] == "USA Parental Rating")
                    {
                        $ratingSystem = "V-CHIP";
                        $rating = $r["code"];
                    }
                }
            }

            /*
             * Boolean types
             */

            if (isset($schedule["new"]))
            {
                $isNew = TRUE;
                $previouslyshown = FALSE;
            }
            else
            {
                $isNew = FALSE;
                $previouslyshown = TRUE;
            }

            /*
             * Shouldn't be "new" and "repeat"
             */

            if (isset($schedule["repeat"]))
            {
                if ($isNew)
                {
                    printMSG("*** WARNING sid:$stationID pid:$programID has 'new' and 'repeat' set. Open SD ticket:\n");
                    printMSG(print_r($schedule, TRUE) . "\n");
                    $errorWarning = TRUE;
                    $tt = fgets(STDIN);
                }
                else
                {
                    $isNew = FALSE;
                    $previouslyshown = TRUE;
                }
            }

            if (isset($schedule["cableInTheClassroom"]))
            {
                $cableInTheClassroom = TRUE;
            }

            if (isset($schedule["catchup"]))
            {
                $catchupProgram = TRUE;
            }

            if (isset($schedule["continued"]))
            {
                $continuedProgram = TRUE;
            }

            if (isset($schedule["educational"]))
            {
                $isEducational = TRUE;
            }

            if (isset($schedule["joinedInProgress"]))
            {
                $joinedInProgress = TRUE;
            }

            if (isset($schedule["leftInProgress"]))
            {
                $leftInProgress = TRUE;
            }

            if (isset($schedule["premiere"]))
            {
                $isPremiere = TRUE;
            }

            if (isset($schedule["programBreak"]))
            {
                $programBreak = TRUE;
            }

            if (isset($schedule["signed"]))
            {
                $isSigned = TRUE;
            }

            if (isset($schedule["subjectToBlackout"]))
            {
                $subjectToBlackout = TRUE;
            }

            if (isset($schedule["timeApproximate"]))
            {
                $timeApproximate = TRUE;
            }

            if (isset($schedule["liveTapeDelay"]))
            {
                $liveOrTapeDelay = $schedule["liveTapeDelay"];
            }

            $title = $programJSON["titles"]["title120"];

            if ($title == NULL OR $title == "")
            {
                printMSG("FATAL ERROR: Empty title? $programID\n");
                exit;
            }

            if (isset($programJSON["episodeTitle150"]))
            {
                $subTitle = $programJSON["episodeTitle150"];
            }
            else
            {
                $subTitle = "";
            }

            if (isset($programJSON["descriptions"]["description1000"]))
            {
                $description = $programJSON["descriptions"]["description1000"];
            }
            else
            {
                $description = "";
            }

            if (isset($programJSON["genres"]))
            {
                $category = $programJSON["genres"][0];
            }
            else
            {
                $category = "";
            }

            if (isset($programJSON["metadata"]))
            {
                foreach ($programJSON["metadata"] as $md)
                {
                    if (isset($md["Tribune"]))
                    {
                        $season = $md["Tribune"]["season"];
                        $episode = $md["Tribune"]["episode"];
                    }
                }
            }

            $isGeneric = FALSE;
            $seriesID = "";
            $type = strtolower(substr($programID, 0, 2));
            switch ($type)
            {
                case "sh":
                    $categoryType = "series";
                    $isGeneric = TRUE;
                    $seriesID = "EP" . substr($programID, 2, 8);
                    break;
                case "ep":
                    $categoryType = "tvshow";
                    $seriesID = substr($programID, 0, 10);
                    break;
                case "mv":
                    $categoryType = "movie";
                    break;
                case "sp":
                    $categoryType = "sports";
                    break;
                default:
                    printMSG("FATAL ERROR: $programID has unknown type.\n");
                    exit;
                    break;
            }

            if ($type == "mv" AND isset($programJSON["movie"]))
            {
                if (isset($programJSON["movie"]["year"]))
                {
                    $movieYear = $programJSON["movie"]["year"];
                }

                /*
                 * MythTV uses a system where 4 stars would be a "1.0".
                 */

                if (isset($programJSON["movie"]["starRating"]))
                {
                    $starRating = (.5 * substr_count($programJSON["movie"]["starRating"], "*")) +
                        (.125 * substr_count($programJSON["movie"]["starRating"], "+"));
                }

                if (isset($programJSON["movie"]["mpaaRating"]))
                {
                    $ratingSystem = "MPAA";
                    $rating = $programJSON["movie"]["mpaaRating"];
                }
            }

            if (isset($programJSON["colorCode"]))
            {
                $colorCode = $programJSON["colorCode"];
            }

            if (isset($programJSON["syndicatedEpisodeNumber"]))
            {
                $syndicatedEpisodeNumber = $programJSON["syndicatedEpisodeNumber"];
            }

            if ($isStereo)
            {
                $audioprop = "STEREO";
            }

            if ($dolbyType)
            {
                $audioprop = "DOLBY";
            }

            if ($isSurround)
            {
                $audioprop = "SURROUND";
            }

            if (isset($programJSON["showType"]))
            {
                $showType = $programJSON["showType"];
            }

            if (isset($programJSON["originalAirDate"]))
            {
                $oad = $programJSON["originalAirDate"];
            }

            if ($isLetterboxed)
            {
                $videoProperties = "WIDESCREEN";
            }

            if ($isHDTV)
            {
                $videoProperties = "HDTV";
            }

            if ($isSigned)
            {
                $subtitleTypes = "SIGNED";
            }

            try
            {
                $insertSchedule->execute(array(
                    "chanid"                  => $chanID,
                    "starttime"               => $programStartTimeMyth,
                    "endtime"                 => $programEndTimeMyth,
                    "title"                   => $title,
                    "subtitle"                => $subTitle,
                    "description"             => $description,
                    "category"                => $category,
                    "category_type"           => $categoryType,
                    "airdate"                 => $movieYear,
                    "stars"                   => $starRating,
                    "previouslyshown"         => $previouslyshown,
                    "stereo"                  => $isStereo,
                    "subtitled"               => $isSubtitled,
                    "hdtv"                    => $isHDTV,
                    "closecaptioned"          => $isClosedCaption,
                    "partnumber"              => $partNumber,
                    "parttotal"               => $numberOfParts,
                    "seriesid"                => $seriesID,
                    "originalairdate"         => $oad,
                    "showtype"                => $showType,
                    "colorcode"               => $colorCode,
                    "syndicatedepisodenumber" => $syndicatedEpisodeNumber,
                    "programid"               => $programID,
                    "generic"                 => $isGeneric,
                    "listingsource"           => $sourceID,
                    "first"                   => $isFirst,
                    "last"                    => $isLast,
                    "audioprop"               => $audioprop,
                    "subtitletypes"           => $subtitleTypes,
                    "videoprop"               => $videoProperties,
                    "season"                  => $season,
                    "episode"                 => $episode
                ));
            } catch (PDOException $e)
            {
                print "Exception: " . $e->getMessage();
                $debug = TRUE;
                var_dump($programJSON);
            }

            /* Bypass the SD-specific insert for now; might be easier to just parse the JSON from the programCache.
                        try
                        {
                            $insertScheduleSD->execute(array(
                                "stationID"           => $stationID,
                                "programID"           => $programID,
                                "md5"                 => $md5,
                                "air_datetime"        => $air_datetime,
                                "duration"            => $duration,
                                "previouslyshown"     => $previouslyshown,
                                "closecaptioned"      => $isClosedCaption,
                                "partnumber"          => $partNumber,
                                "parttotal"           => $numberOfParts,
                                "first"               => $isFirst,
                                "last"                => $isLast,
                                "dvs"                 => $dvs,
                                "new"                 => $isNew,
                                "educational"         => $isEducational,
                                "hdtv"                => $isHDTV,
                                "3d"                  => $is3d,
                                "letterbox"           => $isLetterboxed,
                                "stereo"              => $isStereo,
                                "dolby"               => $dolbyType,
                                "dubbed"              => $dubbed,
                                "dubLanguage"         => $dubbedLanguage,
                                "subtitled"           => $isSubtitled,
                                "subtitleLanguage"    => $subtitledLanguage,
                                "sap"                 => $sap,
                                "sapLanguage"         => $sapLanguage,
                                "programLanguage"     => $programLanguage,
                                "ratingSystem"        => $ratingSystem,
                                "tvRating"            => $rating,
                                "dialogRating"        => $dialogRating,
                                "languageRating"      => $languageRating,
                                "sexualContentRating" => $sexRating,
                                "violenceRating"      => $violenceRating,
                                "fvRating"            => $fvRating));
                        } catch (PDOException $e)
                        {
                            print "Exception: " . $e->getMessage();
                            $debug = TRUE;
                            var_dump($programJSON);
                        }
            */
            if (isset($programJSON["castAndCrew"]))
            {
                foreach ($programJSON["castAndCrew"] as $credit)
                {
                    $role = strtolower($credit["role"]);
                    /*
                     * MythTV has hardcoded maps of roles because it uses a set during the create table.
                     */
                    switch ($role)
                    {
                        case "executive producer":
                            $role = "executive_producer";
                            break;
                        case "guest star":
                            $role = "guest_star";
                            break;
                        case "musical guest":
                            $role = "musical_guest";
                            break;
                    }

                    $roleTable[$role] = 1;

                    try
                    {
                        $insertCreditMyth->execute(array("person"    => $peopleCache[$credit["name"]],
                                                         "chanid"    => $chanID,
                                                         "starttime" => $programStartTimeMyth,
                                                         "role"      => $role));
                    } catch (PDOException $e)
                    {
                        print "Exception: " . $e->getMessage();
                        $debug = TRUE;
                        var_dump($programJSON);
                    }
                }
            }

            if ($ratingSystem != "")
            {
                try
                {
                    $insertProgramRatingMyth->execute(array("chanid"    => $chanID,
                                                            "starttime" => $programStartTimeMyth,
                                                            "system"    => $ratingSystem, "rating" => $rating));
                } catch (PDOException $e)
                {
                    print "Exception: " . $e->getMessage();
                    $debug = TRUE;
                }
            }
        }
        $dbh->commit();
    }

    /* These don't seem to be in the On data:
    if (isset($v["dubbedLanguage"]))
    {
        $dubbedLanguage = $v["dubbedLanguage"];
    }
    else
    {
        $dubbedLanguage = NULL;
    }


    if (isset($v["subtitledLanguage"]))
    {
        $subtitledLanguage = $v["subtitledLanguage"];
    }
    else
    {
        $subtitledLanguage = NULL;
    }

    if (isset($v["partNumber"]))
            {
                $partNumber = $v["partNumber"];
            }
            else
            {
                $partNumber = 0;
            }

            if (isset($v["numberOfParts"]))
            {
                $numberOfParts = $v["numberOfParts"];
            }
            else
            {
                $numberOfParts = 0;
            }

    if (isset($v["sapLanguage"]))
            {
                $sapLanguage = $v["sapLanguage"];
            }
            else
            {
                $sapLanguage = NULL;
            }

if (isset($v["sap"]))
            {
                $sap = TRUE;
            }
            else
            {
                $sap = FALSE;
            }


            if (isset($v["programLanguage"]))
            {
                $programLanguage = $v["programLanguage"];
            }
            else
            {
                $programLanguage = NULL;
            }


            if (isset($v["hasDialogRating"]))
            {
                $dialogRating = TRUE;
            }
            else
            {
                $dialogRating = FALSE;
            }

            if (isset($v["hasLanguageRating"]))
            {
                $languageRating = TRUE;
            }
            else
            {
                $languageRating = FALSE;
            }

            if (isset($v["hasSexRating"]))
            {
                $sexRating = TRUE;
            }
            else
            {
                $sexRating = FALSE;
            }

            if (isset($v["hasViolenceRating"]))
            {
                $violenceRating = TRUE;
            }
            else
            {
                $violenceRating = FALSE;
            }

            if (isset($v["hasFantasyViolenceRating"]))
            {
                $fvRating = TRUE;
            }
            else
            {
                $fvRating = FALSE;
            }


*/


    /*
     * If users start to complain about errors on the insert, it's probably due to a new role type.
     */

    if ($debug)
    {
        print "Role table:\n";
        var_dump($roleTable);
    }

    printMSG("Done inserting schedules.\n");
    $dbh->exec("DROP TABLE SDschedule");
    $dbh->exec("RENAME TABLE t_SDschedule TO SDschedule");

    $dbh->exec("DROP TABLE program");
    $dbh->exec("RENAME TABLE t_program TO program");

    $dbh->exec("DROP TABLE credits");
    $dbh->exec("RENAME TABLE t_credits TO credits");

    $dbh->exec("DROP TABLE programrating");
    $dbh->exec("RENAME TABLE t_programrating TO programrating");
}

function tempdir()
{
    $tempfile = tempnam(sys_get_temp_dir(), "mfdb");
    if (file_exists($tempfile))
    {
        unlink($tempfile);
    }
    mkdir($tempfile);
    if (is_dir($tempfile))
    {
        return $tempfile;
    }
}

function printMSG($str)
{
    global $fh_log;
    global $quiet;
    global $printTimeStamp;

    if ($printTimeStamp)
    {
        $str = date("H:i:s") . ":$str";
    }

    if (!$quiet)
    {
        print "$str";
    }

    $str = str_replace("\r", "\n", $str);
    fwrite($fh_log, $str);
}

function updateStatus()
{
    global $token;
    global $dbh;

    $res = getStatus();

    $updateLocalMessageTable = $dbh->prepare("INSERT INTO SDMessages(id,date,message,type)
    VALUES(:id,:date,:message,:type) ON DUPLICATE KEY UPDATE message=:message,date=:date,type=:type");

    if ($res["code"] == 0)
    {
        $expires = $res["account"]["expires"];
        $maxLineups = $res["account"]["maxLineups"];
        $nextConnectTime = $res["account"]["nextSuggestedConnectTime"];

        foreach ($res["account"]["messages"] as $a)
        {
            $msgID = $a["msgID"];
            $msgDate = $a["date"];
            $message = $a["message"];
            printMSG("MessageID:$msgID : $msgDate : $message\n");
            $updateLocalMessageTable->execute(array("id"   => $msgID, "date" => $msgDate, "message" => $message,
                                                    "type" => "U"));
        }
    }
    else
    {
        printMSG("Received error response from server!\n");
        printMSG("ServerID: " . $res["serverID"] . "\n");
        printMSG("Message: " . $res["message"] . "\n");
        printMSG("\nFATAL ERROR. Terminating execution.\n");

        return ("ERROR");
    }

    printMSG("Server: " . $res["serverID"] . "\n");
    printMSG("Last data refresh: " . $res["lastDataUpdate"] . "\n");
    printMSG("Account expires: $expires\n");
    printMSG("Max number of lineups for your account: $maxLineups\n");
    printMSG("Next suggested connect time: $nextConnectTime\n");

    $stmt = $dbh->prepare("UPDATE settings SET data=:data WHERE value='MythFillSuggestedRunTime' AND hostname IS NULL");
    $stmt->execute(array("data" => $nextConnectTime));

    $stmt = $dbh->prepare("UPDATE settings SET data=:data WHERE value='DataDirectMessage' AND hostname IS NULL");
    $stmt->execute(array("data" => "Your subscription expires on $expires."));

    $stmt = $dbh->prepare("SELECT data FROM settings WHERE value='SchedulesDirectLastUpdate'");
    $result = $stmt->fetchColumn();
    $getLastUpdate = $result[0];

    if ($res["lastDataUpdate"] == $getLastUpdate)
    {
        return ("No new data on server.");
    }
    else
    {
        $stmt = $dbh->prepare("UPDATE settings SET data=:data WHERE value='SchedulesDirectLastUpdate'
        AND hostname IS NULL");
        $stmt->execute(array("data" => $res["lastDataUpdate"]));
    }

    return ("");
}

?>
