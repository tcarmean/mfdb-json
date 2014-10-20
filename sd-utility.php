#!/usr/bin/php

<?php
/*
sd-utility.php. This file is a utility program which performs the necessary setup for using the
Schedules Direct API and the native MythTV tables.
Copyright (C) 2012-2014  Robert Kulagowski grabber@schedulesdirect.org

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

$isBeta = TRUE;
$debug = FALSE;
$done = FALSE;
$isMythTV = TRUE;
$skipChannelLogo = FALSE;
$schedulesDirectLineups = array();
$sdStatus = "";
$username = "";
$usernameFromDB = "";
$password = "";
$passwordFromDB = "";
$passwordHash = "";
$dbWithoutMythtv = FALSE;
$useServiceAPI = FALSE;
$lineupArray = array();
$force = FALSE;
$printFancyTable = TRUE;
$printCountries = FALSE;
$justExtract = FALSE;
$dbHostSD = "localhost";

$availableCountries = array(
    "North America" => array(
        "United States" => "USA",
        "Canada"        => "CAN",
        "Mexico"        => "MEX"),
    "Europe"        => array(
        "Denmark"       => "DNK",
        "Finland"       => "FIN",
        "Great Britain" => "GBR",
        "Sweden"        => "SWE"),
    "Latin America" => array(
        "Argentina"  => "ARG",
        "Belize"     => "BLZ",
        "Brazil"     => "BRA",
        "Chile"      => "CHL",
        "Columbia"   => "COL",
        "Costa Rica" => "CRI",
        "Ecuador"    => "ECU",
        "Guatemala"  => "GTM",
        "Guyana"     => "GUY",
        "Honduras"   => "HND",
        "Panama"     => "PAN",
        "Peru"       => "PER",
        "Uruguay"    => "URY",
        "Venezuela"  => "VEN"),
    "Caribbean"     => array(
        "Anguila"                      => "AIA",
        "Antigua/Barbuda"              => "ATG",
        "Aruba"                        => "ABW",
        "Bahamas"                      => "BHS",
        "Barbados"                     => "BRB",
        "Bermuda"                      => "BMU",
        "Bonaire, Saba, St. Eustatius" => "BES",
        "British Virgin Islands"       => "VGB",
        "Cayman Islands"               => "CYM",
        "Curacao"                      => "CUW",
        "Dominica"                     => "DMA",
        "Dominican Republic"           => "DOM",
        "Grenada"                      => "GRD",
        "Jamaica"                      => "JAM",
        "Puerto Rico"                  => "PRI",
        "Saint Martin"                 => "MAF",
        "Saint Vincent / Grenadines"   => "VCT",
        "St. Kitts and Nevis"          => "KNA",
        "St. Lucia"                    => "LCA",
        "Trinidad and Tobago"          => "TTO",
        "Turks and Caicos"             => "TCA"));

require_once "vendor/autoload.php";
require_once "functions.php";
use Guzzle\Http\Client;

$baseurl = getBaseURL($isBeta);
$channelLogoDirectory = getChannelLogoDirectory();

$agentString = "sd-utility.php utility program API:$api v$scriptVersion/$scriptDate";

$updatedLineupsToRefresh = array();
$needToStoreLogin = FALSE;

$tz = "UTC";

date_default_timezone_set($tz);
$date = new DateTime();
$todayDate = $date->format("Y-m-d");

$fh_log = fopen("$todayDate.log", "a");
$fh_error = fopen("$todayDate.debug.log", "a");

$helpText = <<< eol
The following options are available:
--countries\tThe list of countries that have data.
--debug\t\tEnable debugging. (Default: FALSE)
--dbname=\tMySQL database name for MythTV. (Default: mythconverg)
--dbuser=\tUsername for database access for MythTV. (Default: mythtv)
--dbpassword=\tPassword for database access for MythTV. (Default: mythtv)
--dbhost=\tMySQL database hostname for MythTV. (Default: localhost)
--dbhostsd=\tMySQL database hostname for SchedulesDirect JSON data. (Default: localhost)
--extract\tDon't do anything but extract data from the table for QAM/ATSC. (Default: FALSE)
--help\t\t(this text)
--host=\t\tIP address of the MythTV backend. (Default: localhost)
--logo=\t\tDirectory where channel logos are stored (Default: $channelLogoDirectory)
--nomyth\tDon't execute any MythTV specific functions. (Default: FALSE)
--skiplogo\tDon't download channel logos.
--username=\tSchedules Direct username.
--password=\tSchedules Direct password.
--usedb\t\tUse a database to store data, even if you're not running MythTV. (Default: FALSE)
--timezone=\tSet the timezone for log file timestamps. See http://www.php.net/manual/en/timezones.php (Default:$tz)
--version\tPrint version information and exit.
eol;

$longoptions = array("countries", "debug", "extract", "force", "help", "host::", "dbname::", "dbuser::",
                     "dbpassword::", "dbhost::", "dbhostsd::", "logo::", "notfancy", "nomyth", "skiplogo",
                     "username::", "password::",
                     "timezone::", "usedb", "version", "x");

$options = getopt("h::", $longoptions);
foreach ($options as $k => $v)
{
    $k = strtolower($k);
    switch ($k)
    {
        case "countries":
            $printCountries = TRUE;
            break;
        case "debug":
            $debug = TRUE;
            break;
        case "help":
        case "h":
            print "$agentString\n\n";
            print "$helpText\n";
            exit;
            break;
        case "dbname":
            $dbName = $v;
            break;
        case "dbuser":
            $dbUser = $v;
            break;
        case "dbpassword":
            $dbPassword = $v;
            break;
        case "dbhost":
            $dbHost = $v;
            break;
        case "dbhostsd":
            $dbHostSD = $v;
            break;
        case "extract":
            $justExtract = TRUE;
            break;
        case "force":
            $force = TRUE;
            break;
        case "host":
            $host = $v;
            break;
        case "logo":
            $channelLogoDirectory = $v;
            break;
        case "nomyth":
            $isMythTV = FALSE;
            break;
        case "notfancy":
            $printFancyTable = FALSE;
            break;
        case "skiplogo":
            $skipChannelLogo = TRUE;
            break;
        case "username":
            $username = $v;
            break;
        case "password":
            $password = $v;
            $passwordHash = sha1($v);
            break;
        case "timezone":
            date_default_timezone_set($v);
            break;
        case "usedb":
            $dbWithoutMythtv = TRUE;
            break;
        case "version":
            print "$agentString\n\n";
            exit;
            break;
        case "x":
            $force = TRUE;
            break;
    }
}

if ($knownToBeBroken AND !$force)
{
    print "This version is known to be broken and --x not specified. Exiting.\n";
    exit;
}

if ($channelLogoDirectory == "UNKNOWN" AND $skipChannelLogo === FALSE)
{
    print "Can't determine directory for station logos. Please specify using --logo or use --skiplogo\n";
    exit;
}

if ($printCountries)
{
    printListOfAvailableCountries($printFancyTable);
    exit;
}

print "Using timezone $tz\n";
print "$agentString\n";

if ($isMythTV)
{
    if (!isset($dbHost) AND !isset($dbName) AND !isset($dbUser) and !isset($dbPassword))
    {
        list($dbHost, $dbName, $dbUser, $dbPassword) = getLoginFromFiles();
        if ($dbHost == "NONE")
        {
            $dbUser = "mythtv";
            $dbPassword = "mythtv";
            $dbHost = "localhost";
            $dbName = "mythconverg";
            $host = "localhost";
        }
    }
}

if (!isset($dbHost))
{
    $dbHost = "localhost";
}

if (!isset($dbName))
{
    $dbName = "mythconverg";
}

if (!isset($dbUser))
{
    $dbUser = "mythtv";
}

if (!isset($dbPassword))
{
    $dbPassword = "mythtv";
}

if (!isset($host))
{
    $host = "localhost";
}

if ($isMythTV OR $dbWithoutMythtv)
{
    print "Connecting to Schedules Direct database.\n";
    $dbhSD = new PDO("sqlite:schedulesdirect.db");
    $dbhSD->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    try
    {
        $stmt = $dbhSD->prepare("SELECT * FROM settings");
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e)
    {
        if ($e->getCode() == "HY000")
        {
            print "Creating initial database.\n";
            createDatabase();
        }
        else
        {
            print "Got error connecting to database.\n";
            print "Code: " . $e->getCode() . "\n";
            print "Message: " . $e->getMessage() . "\n";
            exit;
        }
    }

    if ($isMythTV)
    {
        print "Connecting to MythTV database.\n";
        try
        {
            $dbh = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPassword);
            $dbh->exec("SET CHARACTER SET utf8");
            $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e)
        {
            if ($e->getCode() == 2002)
            {
                print "Could not connect to database:\n" . $e->getMessage() . "\n";
                print "If you're running the grabber as standalone, use --nomyth\n";
                exit;
            }
            else
            {
                print "Got error connecting to database.\n";
                print "Code: " . $e->getCode() . "\n";
                print "Message: " . $e->getMessage() . "\n";
                exit;
            }
        }
    }

    if (!$justExtract)
    {
        checkDatabase();
    }
    else
    {
        if (!isset($dbh))
        {
            print "Don't have dbh. Exiting.\n";
            exit;
        }

        if (!isset($dbhSD))
        {
            print "Don't have dbhSD. Exiting.\n";
            exit;
        }

        displayLocalVideoSources();
        $sourceIDtoExtract = readline("Which sourceid do you want to extract:>");
        if ($sourceIDtoExtract != "")
        {
            extractData($sourceIDtoExtract);
        }
        exit;
    }
}

if ($skipChannelLogo === FALSE)
{
    if (!file_exists($channelLogoDirectory))
    {
        $result = @mkdir($channelLogoDirectory);

        if ($result === FALSE)
        {
            print "Could not create $channelLogoDirectory\n";
            print "Use --logo to specify directory, or --skiplogo to bypass channel logos.\n";
            exit;
        }
    }
}

$client = new Guzzle\Http\Client($baseurl);
$client->setUserAgent($agentString);

print "Checking to see if we're running the latest client.\n";

$serverVersion = checkForClientUpdate($client);

if ($serverVersion == "ERROR")
{
    print "Received error response from server. Exiting.\n";
    exit;
}

if ($serverVersion != $scriptVersion)
{
    print "***Version mismatch.***\n";
    print "Server version: $serverVersion\n";
    print "Our version: $scriptVersion\n";
    if (!$force)
    {
        print "Exiting. Do you need to run 'git pull' to refresh?\n";
        print "Restart script with --x to ignore mismatch.\n";
        exit;
    }
    else
    {
        print "Continuing because of --x force parameter.\n";
    }
}

if ($isMythTV)
{
    $useServiceAPI = checkForServiceAPI();
}

if ($isMythTV OR $dbWithoutMythtv)
{
    $userLoginInformation = settingSD("SchedulesDirectLogin");

    if ($userLoginInformation !== FALSE)
    {
        $responseJSON = json_decode($userLoginInformation, TRUE);
        $usernameFromDB = $responseJSON["username"];
        $passwordFromDB = $responseJSON["password"];
    }
}
else
{
    if (file_exists("sd.json.conf"))
    {
        $userLoginInformation = file("sd.json.conf");
        $responseJSON = json_decode($userLoginInformation[0], TRUE);
        $usernameFromDB = $responseJSON["username"];
        $passwordFromDB = $responseJSON["password"];
    }
}

if ($username == "")
{
    if ($usernameFromDB == "")
    {
        $username = readline("Schedules Direct username:");
        $needToStoreLogin = TRUE;
    }
    else
    {
        $username = $usernameFromDB;
    }
}
else
{
    $needToStoreLogin = TRUE;
}

if ($password == "")
{
    if ($passwordFromDB == "")
    {
        $password = readline("Schedules Direct password:");
        $passwordHash = sha1($password);
        $needToStoreLogin = TRUE;
    }
    else
    {
        $password = $passwordFromDB;
        $passwordHash = sha1($password);
    }
}
else
{
    $passwordHash = sha1($password);
    $needToStoreLogin = TRUE;
}

print "Logging into Schedules Direct.\n";
$token = getToken($username, $passwordHash);

if ($token == "ERROR")
{
    printMSG("Got error when attempting to retrieve token from Schedules Direct.");
    printMSG("Check if you entered username/password incorrectly.");
    exit;
}

if ($needToStoreLogin)
{
    $userInformation["username"] = $username;
    $userInformation["password"] = $password;

    $credentials = json_encode($userInformation);

    if ($isMythTV OR $dbWithoutMythtv)
    {
        settingSD("SchedulesDirectLogin", $credentials);

        $stmt = $dbh->prepare("UPDATE videosource SET userid=:username,
    password=:password WHERE xmltvgrabber='schedulesdirect2'");
        $stmt->execute(array("username" => $username, "password" => $password));
    }
    else
    {
        $fh = fopen("sd.json.conf", "w");
        fwrite($fh, "$credentials\n");
        fclose($fh);
    }
}

while (!$done)
{
    $sdStatus = getStatus();

    if ($sdStatus == "ERROR")
    {
        printMSG("Received error from Schedules Direct. Exiting.");
        exit;
    }

    printStatus($sdStatus);

    if ($isMythTV)
    {
        displayLocalVideoSources();
    }

    print "\nSchedules Direct functions:\n";
    print "1 Add a known lineupID to your account at Schedules Direct\n";
    print "2 Search for a lineup to add to your account at Schedules Direct\n";
    print "3 Delete a lineup from your account at Schedules Direct\n";
    print "4 Refresh the local lineup cache\n";
    print "5 Acknowledge a message\n";
    print "6 Print a channel table for a lineup\n";

    if ($isMythTV)
    {
        print "\nMythTV functions:\n";
        print "A to Add a new videosource to MythTV\n";
        print "D to Delete a videosource in MythTV\n";
        print "E to Extract Antenna / QAM / DVB scan from MythTV to send to Schedules Direct\n";
        print "L to Link a videosource to a lineup at Schedules Direct\n";
        print "U to update a videosource by downloading from Schedules Direct\n";
    }

    print "Q to Quit\n";

    $response = strtoupper(readline(">"));

    switch ($response)
    {
        case "1":
            $lineup = readline("Lineup to add>");
            directAddLineup($lineup);
            break;
        case "2":
            addLineupsToSchedulesDirect();
            break;
        case "3":
            deleteLineupFromSchedulesDirect();
            break;
        case "4":
            $lineupArray = getSchedulesDirectLineups();

            if (count($lineupArray))
            {
                foreach ($lineupArray as $v)
                {
                    $lineupToRefresh[$v["lineup"]] = 1;
                }
                updateLocalLineupCache($lineupToRefresh);
            }
            break;
        case "5":
            deleteMessageFromSchedulesDirect();
            break;
        case "6":
            printLineup();
            break;
        case "A":
            print "Adding new videosource\n\n";
            $newName = readline("Name:>");
            if ($newName != "")
            {
                $stmt = $dbh->prepare("INSERT INTO videosource(name,userid,password,xmltvgrabber)
                        VALUES(:name,:userid,:password,'schedulesdirect2')");
                try
                {
                    $stmt->execute(array("name"     => $newName, "userid" => $username,
                                         "password" => $password));
                } catch (PDOException $e)
                {
                    if ($e->getCode() == 23000)
                    {
                        print "\n\n";
                        print "*************************************************************\n";
                        print "\n\n";
                        print "Duplicate video source name.\n";
                        print "\n\n";
                        print "*************************************************************\n";
                    }
                }
            }
            break;
        case "D":
            $toDelete = readline("Delete sourceid:>");
            $stmt = $dbh->prepare("DELETE FROM videosource WHERE sourceid=:sid");
            $stmt->execute(array("sid" => $toDelete));
            $stmt = $dbh->prepare("DELETE FROM channel WHERE sourceid=:sid");
            $stmt->execute(array("sid" => $toDelete));
            break;
        case "E":
            $sourceIDtoExtract = readline("Which sourceid do you want to extract:>");
            if ($sourceIDtoExtract != "")
            {
                extractData($sourceIDtoExtract);
            }
            break;
        case "L":
            print "Linking Schedules Direct lineup to sourceid\n\n";
            linkSchedulesDirectLineup();
            break;
        case "U":
            $lineup = getLineupFromNumber(strtoupper(readline("Schedules Direct lineup (# or lineup):>")));
            if ($lineup != "")
            {
                updateChannelTable($lineup);
            }
            break;
        case "Q":
        default:
            $done = TRUE;
            break;
    }
}

exit;

function updateChannelTable($lineup)
{
    global $dbh;
    global $dbhSD;
    global $skipChannelLogo;

    $transport = "";

    $stmt = $dbh->prepare("SELECT sourceid FROM videosource WHERE lineupid=:lineup");
    $stmt->execute(array("lineup" => $lineup));
    $sID = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($sID) == 0)
    {
        print "ERROR: Can't update channel table; lineup not associated with a videosource.\n";

        return;
    }

    $stmt = $dbhSD->prepare("SELECT json FROM lineupCache WHERE lineup=:lineup");
    $stmt->execute(array("lineup" => $lineup));
    $json = json_decode($stmt->fetchColumn(), TRUE);

    $modified = $json["metadata"]["modified"];

    print "Updating channel table for lineup:$lineup\n";

    foreach ($sID as $sourceID)
    {
        if ($json["metadata"]["transport"] == "Antenna")
        {
            /*
             * For antenna lineups, we're not going to delete the existing channel table or dtv_multiplex; we're still
             * going to use the scan, but use the atsc major and minor to correlate what we've scanned with what's in the
             * lineup file.
             */

            /*
             * TODO Check if we need to fix chanid for Antenna lineups.
             */

            $transport = "Antenna";
            $updateChannelTableATSC = $dbh->prepare("UPDATE channel SET channum=:channum,
    xmltvid=:sid, useonairguide=0 WHERE atsc_major_chan=:atscMajor AND atsc_minor_chan=:atscMinor");

            $updateChannelTableAnalog = $dbh->prepare("UPDATE channel SET channum=:channum,
    xmltvid=:sid, useonairguide=0 WHERE atsc_major_chan=0 AND atsc_minor_chan=0 AND freqID=:freqID");
        }

        if ($json["metadata"]["transport"] == "Cable")
        {
            $transport = "Cable";
            $stmt = $dbh->prepare("DELETE FROM channel WHERE sourceid=:sourceid");
            $stmt->execute(array("sourceid" => $sourceID));
        }

        if ($json["metadata"]["transport"] == "QAM")
        {
            $transport = "QAM";
        }

        if ($json["metadata"]["transport"] == "Satellite")
        {
            $transport = "Satellite";
        }

        if ($transport != "QAM")
        {
            foreach ($json["map"] as $mapArray)
            {
                $stationID = $mapArray["stationID"];

                if ($transport == "Antenna")
                {
                    if (array_key_exists("uhfVhf", $mapArray))
                    {
                        $freqid = $mapArray["uhfVhf"];
                    }
                    else
                    {
                        $freqid = "";
                    }

                    if (isset($mapArray["atscMajor"]))
                    {
                        $atscMajor = $mapArray["atscMajor"];
                        $atscMinor = $mapArray["atscMinor"];
                        $channum = "$atscMajor.$atscMinor";

                        $updateChannelTableATSC->execute(array("channum"   => $channum, "sid" => $stationID,
                                                               "atscMajor" => $atscMajor,
                                                               "atscMinor" => $atscMinor));
                    }
                    else
                    {
                        $channum = $freqid;
                        $updateChannelTableAnalog->execute(array("channum" => ltrim($channum, "0"), "sid" => $stationID,
                                                                 "freqID"  => $freqid));
                    }
                }

                if ($transport == "IP")
                {
                    /*
                     * Nothing yet.
                     */
                }

                if ($transport == "Cable")
                {
                    $channum = $mapArray["channel"];
                    $stmt = $dbh->prepare(
                        "INSERT INTO channel(chanid,channum,freqid,sourceid,xmltvid,mplexid,serviceid,atsc_major_chan)
                         VALUES(:chanid,:channum,:freqid,:sourceid,:xmltvid,:mplexid,:serviceid,:atsc_major_chan)");

                    try
                    {
                        $stmt->execute(array("chanid"          => (int)($sourceID * 1000) + (int)$channum,
                                             "channum"         => ltrim($channum, "0"),
                                             "freqid"          => (int)$channum,
                                             "sourceid"        => $sourceID,
                                             "xmltvid"         => $stationID,
                                             "mplexid"         => 32767,
                                             "serviceid"       => 0,
                                             "atsc_major_chan" => $channum));
                    } catch (PDOException $e)
                    {
                        if ($e->getCode() == 23000)
                        {
                            print "\n\n";
                            print "*************************************************************\n";
                            print "\n\n";
                            print "Error inserting data. Duplicate channel number exists?\n";
                            print "Send email to grabber@schedulesdirect.org with the following:\n\n";
                            print "Duplicate channel error.\n";
                            print "Transport: $transport\n";
                            print "Lineup: $lineup\n";
                            print "Channum: $channum\n";
                            print "stationID: $stationID\n";
                            print "\n\n";
                            print "*************************************************************\n";
                        }
                    }
                }

                if ($transport == "Satellite")
                {
                    $channum = $mapArray["channel"];
                    $stmt = $dbh->prepare(
                        "INSERT INTO channel(chanid,channum,freqid,sourceid,xmltvid,mplexid,serviceid,atsc_major_chan)
                         VALUES(:chanid,:channum,:freqid,:sourceid,:xmltvid,:mplexid,:serviceid,:atsc_major_chan)");

                    try
                    {
                        $stmt->execute(array("chanid"          => (int)($sourceID * 1000) + (int)$channum,
                                             "channum"         => ltrim($channum, "0"),
                                             "freqid"          => (int)$channum,
                                             "sourceid"        => $sourceID,
                                             "xmltvid"         => $stationID,
                                             "mplexid"         => 32767,
                                             "serviceid"       => 0,
                                             "atsc_major_chan" => $channum));
                    } catch (PDOException $e)
                    {
                        if ($e->getCode() == 23000)
                        {
                            print "\n\n";
                            print "*************************************************************\n";
                            print "\n\n";
                            print "Error inserting data. Duplicate channel number exists?\n";
                            print "Send email to grabber@schedulesdirect.org with the following:\n\n";
                            print "Duplicate channel error.\n";
                            print "Transport: $transport\n";
                            print "Lineup: $lineup\n";
                            print "Channum: $channum\n";
                            print "stationID: $stationID\n";
                            print "\n\n";
                            print "*************************************************************\n";
                        }
                    }
                }
            }
        }
        else
        {
            /*
             * It's QAM. We're going to run a match based on what the user had already scanned to avoid the manual
             * matching step of correlating stationIDs.
             */

            print "You can:\n";
            print "1. Exit this program and run a QAM scan using mythtv-setup then use the QAM lineup to populate stationIDs.\n";
            print "2. Use the QAM lineup information directly. (Default)\n";
            $useScan = readline("Which do you want to do? (1 or 2)>");
            if ($useScan == "" OR $useScan == "2")
            {
                $useScan = FALSE;
            }
            else
            {
                $useScan = TRUE;
            }

            if (count($json["qamMappings"]) > 1)
            {
                /*
                 * TODO: Work on this some more. Kludgey.
                 */
                print "Found more than one QAM mapping for your headend.\n";
                foreach ($json["qamMappings"] as $m)
                {
                    print "Mapping: $m\n";
                }
                $mapToUse = readline("Which map do you want to use>");
            }
            else
            {
                $mapToUse = "1";
            }

            if ($useScan)
            {
                $stmt = $dbh->prepare("SELECT mplexid, frequency FROM dtv_multiplex WHERE modulation='qam_256'");
                $stmt->execute();
                $qamFrequencies = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                $stmt = $dbh->prepare("SELECT * FROM channel WHERE sourceid=:sid");
                $stmt->execute(array("sid" => $sourceID));
                $existingChannelNumbers = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $updateChannelTableQAM = $dbh->prepare("UPDATE channel SET xmltvid=:stationID WHERE
                mplexid=:mplexid AND serviceid=:serviceid");

                $map = array();

                foreach ($json["map"][$mapToUse] as $foo)
                {
                    $map["{$foo["frequency"]}-{$foo["serviceID"]}"] = $foo["stationID"];
                }

                foreach ($existingChannelNumbers as $foo)
                {
                    $toFind = "{$qamFrequencies[$foo["mplexid"]]}-{$foo["serviceid"]}";

                    if (array_key_exists($toFind, $map))
                    {
                        $updateChannelTableQAM->execute(array("stationID" => $map[$toFind],
                                                              "mplexid"   => $foo["mplexid"],
                                                              "serviceid" => $foo["serviceID"]));
                    }
                }

                print "Done updating QAM scan with stationIDs.\n";
            }
            else
            {
                /*
                 * The user has chosen to not run a QAM scan and just use the values that we're supplying.
                 * Work-in-progress.
                 */

                print "Inserting QAM data into tables.\n";

                $insertDTVMultiplex = $dbh->prepare
                ("INSERT INTO dtv_multiplex
        (sourceid,frequency,symbolrate,polarity,modulation,visible,constellation,hierarchy,mod_sys,rolloff,sistandard)
        VALUES
        (:sourceid,:freq,0,'v',:modulation,1,:modulation,'a','UNDEFINED','0.35','atsc')");

                $channelInsert = $dbh->prepare("INSERT INTO channel(chanid,channum,freqid,sourceid,xmltvid,tvformat,visible,mplexid,serviceid)
                     VALUES(:chanid,:channum,:freqid,:sourceid,:xmltvid,'ATSC','1',:mplexid,:serviceid)");

                $stmt = $dbh->prepare("SELECT mplexid, frequency FROM dtv_multiplex WHERE modulation='qam_256'
                AND sourceid=:sourceid");
                $stmt->execute(array("sourceid" => $sourceID));
                $dtvMultiplex = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                foreach ($json["map"][$mapToUse] as $mapArray)
                {
                    $virtualChannel = $mapArray["virtualChannel"]; // "Channel 127"

                    if (isset($mapArray["channel"]))
                    {
                        $channel = $mapArray["channel"]; // "54-18" or whatever.
                    }
                    else
                    {
                        $channel = $virtualChannel;
                    }

                    $modulation = $mapArray["modulation"];
                    $frequency = $mapArray["frequency"];
                    $serviceID = $mapArray["serviceID"];
                    $stationID = $mapArray["stationID"];

                    /*
                     * Because multiple programs may end up on a single frequency, we only want to insert once,
                     * but we want to track the mplexid assigned when we do the insert,
                     * because that might be used more than once.
                     */
                    if (!isset($dtvMultiplex[$frequency]))
                    {
                        $insertDTVMultiplex->execute(array("sourceid"   => $sourceID,
                                                           "freq"       => $frequency,
                                                           "modulation" => $modulation));
                        $dtvMultiplex[$frequency] = $dbh->lastInsertId();
                    }

                    /*
                     * In order to insert a unique channel ID, we need to make sure that "39_1" and "39_2" map to two
                     * different values. Old code resulted in 39_1 -> 39, then 39_2 had a collision because it also
                     * turned into "39"
                     */

                    $strippedChannel = str_replace(array("-", "_"), "", $channel);

                    try
                    {
                        $channelInsert->execute(array("chanid"    => (int)($sourceID * 1000) + (int)$strippedChannel,
                                                      "channum"   => $channel,
                                                      "freqid"    => $virtualChannel,
                                                      "sourceid"  => $sourceID,
                                                      "xmltvid"   => $stationID,
                                                      "mplexid"   => $dtvMultiplex[$frequency],
                                                      "serviceid" => $serviceID));
                    } catch (PDOException $e)
                    {
                        if ($e->getCode() == 23000)
                        {
                            print "\n\n";
                            print "*************************************************************\n";
                            print "\n\n";
                            print "Error inserting data. Duplicate channel number exists?\n";
                            print "Send email to grabber@schedulesdirect.org with the following:\n\n";
                            print "Duplicate channel error.\n";
                            print "Transport: $transport\n";
                            print "Lineup: $lineup\n";
                            print "Channum: $channel\n";
                            print "Virtual: $virtualChannel\n";
                            print "stationID: $stationID\n";
                            print "\n\n";
                            print "*************************************************************\n";
                        }
                    }
                }
                print "Done inserting QAM tuning information directly into tables.\n";
            }
        }

        /*
         * Now that we have basic information in the database, we can start filling in other things, like
         * callsigns, etc.
         */

        $stmt = $dbh->prepare("UPDATE channel SET name=:name, callsign=:callsign WHERE xmltvid=:stationID");

        foreach ($json["stations"] as $stationArray)
        {
            $stationID = $stationArray["stationID"];
            $callsign = $stationArray["callsign"];

            if (array_key_exists("name", $stationArray))
            {
                /*
                 * Not all stations have names, so don't try to insert a name if that field isn't included.
                 */
                $name = $stationArray["name"];
            }
            else
            {
                $name = "";
            }

            if (array_key_exists("logo", $stationArray))
            {
                if ($skipChannelLogo === FALSE)
                {
                    checkForChannelIcon($stationID, $stationArray["logo"]);
                }
            }

            $stmt->execute(array("name" => $name, "callsign" => $callsign, "stationID" => $stationID));
        }

        $lineupLastModifiedJSON = settingSD("localLineupLastModified");
        $lineupLastModifiedArray = array();

        if (count($lineupLastModifiedJSON))
        {
            $lineupLastModifiedArray = json_decode($lineupLastModifiedJSON, TRUE);
        }

        $lineupLastModifiedArray[$lineup] = $modified;

        settingSD("localLineupLastModified", json_encode($lineupLastModifiedArray));

        /*
         * Set the startchan to a non-bogus value.
         */
        $getChanNum = $dbh->prepare("SELECT channum FROM channel WHERE sourceid=:sourceid
    ORDER BY CAST(channum AS SIGNED) LIMIT 1");

        foreach ($sID as $updateSourceID)
        {
            $getChanNum->execute(array("sourceid" => $updateSourceID));
            $result = $getChanNum->fetchColumn();

            if ($result != "")
            {
                $startChan = $result;
                $setStartChannel = $dbh->prepare("UPDATE cardinput SET startchan=:startChan WHERE sourceid=:sourceid");
                $setStartChannel->execute(array("sourceid" => $updateSourceID, "startChan" => $startChan));
            }
        }
    }
}

function linkSchedulesDirectLineup()
{
    global $dbh;
    global $dbhSD;

    $sid = readline("MythTV sourceid:>");

    if ($sid == "")
    {
        return;
    }

    $lineup = getLineupFromNumber(strtoupper(readline("Schedules Direct lineup (# or lineup):>")));

    if ($lineup == "")
    {
        return;
    }

    $stmt = $dbhSD->prepare("SELECT json FROM lineupCache WHERE lineup=:lineup");
    $stmt->execute(array("lineup" => $lineup));
    $response = json_decode($stmt->fetchColumn(), TRUE);

    if (!count($response)) // We've already decoded the JSON.
    {
        print "Fatal Error in Link SchedulesDirect Lineup.\n";
        print "No JSON stored in lineupCache?\n";
        print "lineup:$lineup\n";
        exit;
    }

    if ($response == "[]")
    {
        print "Fatal Error in Link SchedulesDirect Lineup.\n";
        print "Empty JSON stored in lineupCache?\n";
        print "lineup:$lineup\n";
        exit;
    }

    $stmt = $dbh->prepare("UPDATE videosource SET lineupid=:lineup WHERE sourceid=:sid");
    $stmt->execute(array("lineup" => $lineup, "sid" => $sid));
}

function printLineup()
{
    global $dbhSD;

    /*
     * First we want to get the lineup that we're interested in.
     */

    $lineup = getLineupFromNumber(strtoupper(readline("Lineup to print (# or lineup):>")));

    if ($lineup == "")
    {
        return;
    }

    $stmt = $dbhSD->prepare("SELECT json FROM lineupCache WHERE lineup=:lineup");
    $stmt->execute(array("lineup" => $lineup));
    $response = json_decode($stmt->fetchColumn(), TRUE);

    if (!count($response))
    {
        return;
    }

    print "\n";

    $chanMap = array();
    $stationMap = array();

    if ($response["metadata"]["transport"] == "Antenna")
    {
        foreach ($response["map"] as $v)
        {
            if (isset($v["atscMajor"]))
            {
                $chanMap[$v["stationID"]] = "{$v["atscMajor"]}.{$v["atscMinor"]}";
            }
            elseif (array_key_exists("uhfVhf", $v))
            {
                $chanMap[$v["stationID"]] = $v["uhfVhf"];
            }
            else
            {
                $chanMap[$v["stationID"]] = 0; //Not sure what the correct thing to use here is at this time.
            }
        }
    }
    else
    {
        foreach ($response["map"] as $v)
        {
            $chanMap[$v["stationID"]] = $v["channel"];
        }
    }

    foreach ($response["stations"] as $v)
    {
        if (array_key_exists("affiliate", $v))
        {
            $stationMap[$v["stationID"]] = "{$v["callsign"]} ({$v["affiliate"]})";
        }
        else
        {
            $stationMap[$v["stationID"]] = "{$v["callsign"]}";
        }
    }

    asort($chanMap, SORT_NATURAL);

    $stationData = new Zend\Text\Table\Table(array('columnWidths' => array(8, 50, 10)));
    $stationData->appendRow(array("Channel", "Callsign", "stationID"));

    foreach ($chanMap as $stationID => $channel)
    {
        $stationData->appendRow(array((string)$channel, $stationMap[$stationID], (string)$stationID));
    }

    print $stationData;
}

function addLineupsToSchedulesDirect()
{
    global $client;
    global $debug;
    global $token;

    $sdLineupArray = array();

    $arrayCountriesWithOnePostalCode = array(
        "BLZ" => "BZ",
        "COL" => "CO",
        "GUY" => "GY",
        "HND" => "HN",
        "PAN" => "PA",
        "AIA" => "AI-2640",
        "ATG" => "AG",
        "ABW" => "AW",
        "BHS" => "BS",
        "BES" => "BQ",
        "CUW" => "CW",
        "DMA" => "DM",
        "GRD" => "GD",
        "MAF" => "97150",
        "KNA" => "KN",
        "LCA" => "LC",
        "TTO" => "TT",
        "TCA" => "TKCA1ZZ");

    $done = FALSE;
    $country = "";

    while (!$done)
    {
        print "Three-character ISO-3166-1 alpha3 country code (? to list available countries):";
        $country = strtoupper(readline(">"));

        if ($country == "")
        {
            return;
        }

        if ($country == "?")
        {
            printListOfAvailableCountries(TRUE);
        }
        else
        {
            $done = TRUE;
        }
    }

    if (isset($arrayCountriesWithOnePostalCode[$country]))
    {
        print "Only one valid postal code for this country: {$arrayCountriesWithOnePostalCode[$country]}\n";
        $postalcode = $arrayCountriesWithOnePostalCode[$country];
    }
    else
    {
        print "Enter postal code:";
        $postalcode = strtoupper(readline(">"));

        if ($postalcode == "")
        {
            return;
        }
    }

    try
    {
        $response = $client->get("headends", array(), array(
            "query"   => array("country" => $country, "postalcode" => $postalcode),
            "headers" => array("token" => $token)))->send();
    } catch (Guzzle\Http\Exception\BadResponseException $e)
    {
        $s = json_decode($e->getResponse()->getBody(TRUE), TRUE);
        print "********************************************\n";
        print "\tError response from server:\n";
        print "\tCode: {$s["code"]}\n";
        print "\tMessage: {$s["message"]}\n";
        print "\tServer: {$s["serverID"]}\n";
        print "********************************************\n";

        return;
    }

    $res = $response->json();

    if ($debug)
    {
        debugMSG("addLineupsToSchedulesDirect:Response:$res");
        debugMSG("Raw headers:\n" . $response->getRawHeaders());
    }

    $counter = "0";

    foreach ($res as $he => $details)
    {
        print "\nheadend: $he\n";
        print "location: {$details["location"]}\n";
        foreach ($details["lineups"] as $v)
        {
            $counter++;
            $name = $v["name"];
            $lineup = end(explode("/", $v["uri"]));
            $sdLineupArray[$counter] = $lineup;
            print "\t#$counter:\n";
            print "\tname: $name\n";
            print "\tLineup: $lineup\n";
        }
    }

    print "\n\n";
    $lineup = readline("Lineup to add (# or lineup)>");

    if ($lineup == "")
    {
        return;
    }

    if (strlen($lineup) < 3)
    {
        $lineup = $sdLineupArray[$lineup];
    }
    else
    {
        $lineup = strtoupper($lineup);
    }

    if ($lineup != "USA-C-BAND-DEFAULT" AND substr_count($lineup, "-") != 2)
    {
        print "Did not see at least two hyphens in lineup; did you enter it correctly?\n";

        return;
    }

    print "Sending request to server.\n";
    $lineup = str_replace(" ", "", $lineup);

    try
    {
        $response = $client->put("lineups/$lineup", array("token" => $token), array())->send();
    } catch (Guzzle\Http\Exception\BadResponseException $e)
    {
        $s = json_decode($e->getResponse()->getBody(TRUE), TRUE);
        print "********************************************\n";
        print "\tError response from server:\n";
        print "\tCode: {$s["code"]}\n";
        print "\tMessage: {$s["message"]}\n";
        print "\tServer: {$s["serverID"]}\n";
        print "********************************************\n";

        return;
    }

    $res = $response->json();

    if ($debug)
    {
        debugMSG("addLineupsToSchedulesDirect:Response:$res");
        debugMSG("Raw headers:\n" . $response->getRawHeaders());
    }

    print "Message from server: {$res["message"]}\n";
}

function directAddLineup($lineup)
{
    global $debug;
    global $client;
    global $token;

    if ($lineup != "USA-C-BAND-DEFAULT" AND substr_count($lineup, "-") != 2)
    {
        print "Did not see at least two hyphens in lineup; did you enter it correctly?\n";

        return;
    }

    print "Sending request to server.\n";
    $lineup = str_replace(" ", "", $lineup);

    try
    {
        $response = $client->put("lineups/$lineup", array("token" => $token), array())->send();
    } catch (Guzzle\Http\Exception\BadResponseException $e)
    {
        $s = json_decode($e->getResponse()->getBody(TRUE), TRUE);
        print "********************************************\n";
        print "\tError response from server:\n";
        print "\tCode: {$s["code"]}\n";
        print "\tMessage: {$s["message"]}\n";
        print "\tServer: {$s["serverID"]}\n";
        print "********************************************\n";

        return;
    }

    $res = $response->json();

    if ($debug)
    {
        debugMSG("addLineupsToSchedulesDirect:Response:$res");
        debugMSG("Raw headers:\n" . $response->getRawHeaders());
    }

    print "Message from server: {$res["message"]}\n";
}

function deleteLineupFromSchedulesDirect()
{
    global $dbh;
    global $dbhSD;
    global $debug;
    global $client;
    global $token;
    global $updatedLineupsToRefresh;

    $deleteFromLocalCache = $dbhSD->prepare("DELETE FROM lineupCache WHERE lineup=:lineup");
    $removeFromVideosource = $dbh->prepare("UPDATE videosource SET lineupid='' WHERE lineupid=:lineup");

    $toDelete = getLineupFromNumber(strtoupper(readline("Lineup to Delete (# or lineup):>")));

    if ($toDelete == "")
    {
        return;
    }

    try
    {
        $response = $client->delete("lineups/$toDelete", array("token" => $token), array())->send();
    } catch (Guzzle\Http\Exception\BadResponseException $e)
    {
        $s = json_decode($e->getResponse()->getBody(TRUE), TRUE);
        print "********************************************\n";
        print "\tError response from server:\n";
        print "\tCode: {$s["code"]}\n";
        print "\tMessage: {$s["message"]}\n";
        print "\tServer: {$s["serverID"]}\n";
        print "********************************************\n";

        return;
    }

    $res = $response->json();

    if ($debug)
    {
        debugMSG("deleteLineupFromSchedulesDirect:Response:$res");
        debugMSG("Raw headers:\n" . $response->getRawHeaders());
    }

    print "Message from server: {$res["message"]}\n";
    unset ($updatedLineupsToRefresh[$toDelete]);
    $deleteFromLocalCache->execute(array("lineup" => $toDelete));
    $removeFromVideosource->execute(array("lineup" => $toDelete));
}

function deleteMessageFromSchedulesDirect()
{
    global $client;
    global $debug;
    global $token;

    $toDelete = readline("MessageID to acknowledge:>");

    try
    {
        $response = $client->delete("messages/$toDelete", array("token" => $token), array())->send();
    } catch (Guzzle\Http\Exception\BadResponseException $e)
    {
        $s = json_decode($e->getResponse()->getBody(TRUE), TRUE);
        print "********************************************\n";
        print "\tError response from server:\n";
        print "\tCode: {$s["code"]}\n";
        print "\tMessage: {$s["message"]}\n";
        print "\tServer: {$s["serverID"]}\n";
        print "********************************************\n";

        return;
    }

    $res = $response->json();

    if ($debug)
    {
        print "\n\n******************************************\n";
        print "Raw headers:\n";
        print $response->getRawHeaders();
        print "******************************************\n";
        print "deleteMessageFromSchedulesDirect:Response:$res\n";
        print "******************************************\n";
    }

    print "Message from server: {$res["message"]}\n";
    print "Successfully deleted message.\n";
}

function getLineup($lineupToGet)
{
    global $client;
    global $debug;
    global $token;

    print "Retrieving lineup $lineupToGet from Schedules Direct.\n";

    try
    {
        $response = $client->get("lineups/$lineupToGet", array("token" => $token), array())->send();
    } catch (Guzzle\Http\Exception\BadResponseException $e)
    {
        $s = json_decode($e->getResponse()->getBody(TRUE), TRUE);

        print "********************************************\n";
        print "\tError response from server:\n";
        print "\tCode: {$s["code"]}\n";
        print "\tMessage: {$s["message"]}\n";
        print "\tServer: {$s["serverID"]}\n";
        print "********************************************\n";

        return "";
    }

    try
    {
        $res = $response->json();
    } catch (Guzzle\Common\Exception\RuntimeException $e)
    {
        /*
         * Probably couldn't decode the JSON.
         */
        $message = $e->getMessage();
        print "Received error: $message\n";

        return "";
    }

    if ($debug)
    {
        print "\n\n******************************************\n";
        print "Raw headers:\n";
        print $response->getRawHeaders();
        print "******************************************\n";
        print "getLineup:Response:\n";
        var_dump($res);
        print "\n\n";
        print "******************************************\n";
    }

    return $res;
}

function updateLocalLineupCache($updatedLineupsToRefresh)
{
    global $dbhSD;

    print "Checking for updated lineups from Schedules Direct.\n";

    foreach ($updatedLineupsToRefresh as $k => $v)
    {
        $res = array();
        $res = getLineup($k);

        if (isset($res["code"]))
        {
            print "\n\n-----\nERROR: Bad response from Schedules Direct.\n";
            print $res["message"] . "\n\n-----\n";
            exit;
        }

        /*
         * Store a copy of the data that we just downloaded into the cache.
         */
        $stmt = $dbhSD->prepare("INSERT OR IGNORE INTO lineupCache(lineup,json,modified)
        VALUES(:lineup,:json,:modified)");
        $stmt->execute(array("lineup" => $k, "modified" => $updatedLineupsToRefresh[$k],
                             "json"   => json_encode($res)));

        $stmt = $dbhSD->prepare("UPDATE lineupCache SET json=:json,modified=:modified WHERE lineup=:lineup");
        $stmt->execute(array("lineup" => $k, "modified" => $updatedLineupsToRefresh[$k],
                             "json"   => json_encode($res)));

        unset ($updatedLineupsToRefresh[$k]);
    }
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
        print "tempdir is $tempfile\n";

        return $tempfile;
    }
}

function displayLocalVideoSources()
{
    global $dbh;

    $stmt = $dbh->prepare("SELECT sourceid,name,lineupid FROM videosource ORDER BY sourceid");
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($result))
    {
        print "\nMythTV local videosources:\n";
        foreach ($result as $v)
        {
            print "sourceid: " . $v["sourceid"] . "\tname: " . $v["name"] . "\tSchedules Direct lineup: " .
                $v["lineupid"] . "\n";
        }
    }
    else
    {
        print "\nWARNING: *** No videosources configured in MythTV. ***\n";
    }
}

function getSchedulesDirectLineups()
{
    global $sdStatus;
    $schedulesDirectLineups = array();

    $counter = "0";

    foreach ($sdStatus["lineups"] as $hv)
    {
        $counter++;
        $schedulesDirectLineups[$counter] = array("lineup" => $hv["ID"], "modified" => $hv["modified"]);
    }

    return ($schedulesDirectLineups);
}

function checkDatabase()
{
    global $dbh;

    $schemaVersion = settingSD("SchedulesDirectJSONschemaVersion");

    if ($schemaVersion === FALSE)
    {
        print "Copying existing data from MythTV\n";

        $lineupsMyth = setting("localLineupLastModified");

        if ($lineupsMyth != FALSE)
        {
            settingSD("localLineupLastModified", $lineupsMyth);
        }

        $login = setting("SchedulesDirectLogin");

        if ($login != FALSE)
        {
            settingSD("SchedulesDirectLogin", $login);
        }

        $dbh->exec("DELETE IGNORE FROM settings WHERE value='schedulesdirectLogin'");
        $dbh->exec("DELETE IGNORE FROM settings WHERE value='SchedulesDirectLogin'");
        $dbh->exec("DELETE IGNORE FROM settings WHERE value='localLineupLastModified'");
        $dbh->exec("DELETE IGNORE FROM settings WHERE value='SchedulesDirectLastUpdate'");
        $dbh->exec("DELETE IGNORE FROM settings WHERE value='SchedulesDirectJSONschemaVersion'");

        $schemaVersion = 1;
        settingSD("SchedulesDirectJSONschemaVersion", "1");
    }
}

function createDatabase()
{
    global $dbhSD;

    print "Creating settings table.\n";
    $dbhSD->exec(
        "CREATE TABLE settings (
                    keyColumn TEXT NOT NULL UNIQUE,
                    valueColumn TEXT NOT NULL
                    )");

    print "Creating Schedules Direct tables.\n";

    $dbhSD->exec("CREATE TABLE messages (
row INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  id char(22) NOT NULL UNIQUE, -- Required to ACK a message from the server.
  date char(20) DEFAULT NULL,
  message varchar(512) DEFAULT NULL,
  type char(1) DEFAULT NULL, -- Message type. G-global, S-service status, U-user specific
  modified TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL)");

    $dbhSD->exec("CREATE TABLE credits (
    personID INTEGER,
      programID varchar(64) NOT NULL,
      role varchar(100) DEFAULT NULL)");

    $dbhSD->exec("CREATE INDEX programID ON credits (programID)");
    $dbhSD->exec("CREATE UNIQUE INDEX person_pid_role ON credits (personID,programID,role)");

    $dbhSD->exec("CREATE TABLE lineupCache (
    row INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL UNIQUE,
      lineup varchar(50) NOT NULL,
      -- md5 char(22) NOT NULL,
      modified char(20) DEFAULT '1970-01-01T00:00:00Z',
      json TEXT
      )");

    $dbhSD->exec("CREATE UNIQUE INDEX lineup ON lineupCache (lineup)");

    $dbhSD->exec("CREATE TABLE SDpeople (
    personID INTEGER PRIMARY KEY,
      name varchar(128)
      )");

    $dbhSD->exec("CREATE TABLE programCache (
    row INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL UNIQUE,
      programID varchar(64) NOT NULL UNIQUE,
      md5 char(22) NOT NULL,
      modified TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
      json TEXT NOT NULL
      )");

    $dbhSD->exec("CREATE TABLE programGenres (
    programID varchar(64) PRIMARY KEY NOT NULL,
      relevance char(1) NOT NULL DEFAULT '0',
      genre varchar(30) NOT NULL)");

    $dbhSD->exec("CREATE INDEX genre ON programGenres (genre)");
    $dbhSD->exec("CREATE UNIQUE INDEX pid_relevance ON programGenres (programID,relevance)");

    $dbhSD->exec("CREATE TABLE programRating (
    programID varchar(64) PRIMARY KEY NOT NULL,
      system varchar(30) NOT NULL,
      rating varchar(16) DEFAULT NULL)");

    $dbhSD->exec("CREATE TABLE schedule (
      stationID varchar(12) NOT NULL UNIQUE,
      md5 char(22) NOT NULL)");

    $dbhSD->exec("CREATE INDEX md5 ON schedule (md5)");

    $dbhSD->exec("CREATE TABLE imageCache (
    row INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL UNIQUE,
      item varchar(128) NOT NULL,
      md5 char(22) NOT NULL,
      height varchar(128) NOT NULL,
      width varchar(128) NOT NULL,
      type char(1) NOT NULL -- COMMENT 'L-Channel Logo'
    )");

    $dbhSD->exec("CREATE UNIQUE INDEX id ON imageCache (item,height,width)");
    $dbhSD->exec("CREATE INDEX type ON imageCache (type)");
}


function checkForChannelIcon($stationID, $data)
{
    global $dbh;
    global $dbhSD;
    global $channelLogoDirectory;

    $a = explode("/", $data["URL"]);
    $iconFileName = end($a);

    $md5 = $data["md5"];
    $height = $data["height"];
    $width = $data["width"];

    $updateChannelTable = $dbh->prepare("UPDATE channel SET icon=:icon WHERE xmltvid=:stationID");

    $stmt = $dbhSD->prepare("SELECT md5 FROM imageCache WHERE item=:item AND height=:height AND width=:width");
    $stmt->execute(array("item" => $iconFileName, "height" => $height, "width" => $width));

    $result = $stmt->fetchColumn();

    if ($result === FALSE OR $result != $md5)
    {
        /*
         * We don't already have this icon, or it's different, so it will have to be fetched.
         */

        printMSG("Fetching logo $iconFileName for station $stationID");

        $success = @file_put_contents("$channelLogoDirectory/$iconFileName", file_get_contents($data["URL"]));

        if ($success === FALSE)
        {
            printMSG("Check permissions: could not write to $channelLogoDirectory\n");

            return;
        }

        $insertImageCache = $dbhSD->prepare("INSERT OR IGNORE INTO imageCache(item,height,width,md5,type)
        VALUES(:item,:height,:width,:md5,'L')");
        $insertImageCache->execute(array("item" => $iconFileName, "height" => $height, "width" => $width,
                                           "md5"  => $md5));

        $updateImageCache = $dbhSD->prepare("UPDATE imageCache SET md5=:md5 WHERE item=:item AND height=:height AND width=:width");

        $updateImageCache->execute(array("item" => $iconFileName, "height" => $height, "width" => $width,
                                           "md5"  => $md5));

        $updateChannelTable->execute(array("icon" => $iconFileName, "stationID" => $stationID));
    }
}

function extractData($sourceIDtoExtract)
{
    /*
     * Pulls data from the scanned table and preps it for sending to Schedules Direct.
     */

    global $dbh;
    global $todayDate;

    $extractArray = array();
    $extractChannel = array();
    $extractMultiplex = array();

    $stmt = $dbh->prepare("SELECT lineupid from videosource where sourceid=:sid");
    $stmt->execute(array("sid" => $sourceIDtoExtract));
    $lineupName = $stmt->fetchColumn();

    $stmt = $dbh->prepare("SELECT channum, freqid, callsign, name, xmltvid, mplexid, serviceid FROM channel where sourceid=:sid");
    $stmt->execute(array("sid" => $sourceIDtoExtract));
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $getDTVMultiplex = $dbh->prepare("SELECT transportid, frequency, modulation FROM dtv_multiplex WHERE mplexid=:mplexid");

    if (count($result) == 0)
    {
        print "Channel table is empty; nothing to do.\n";

        return;
    }

    $fhExtract = fopen("$lineupName.extract.conf", "w");

    foreach ($result as $v)
    {
        $getDTVMultiplex->execute(array("mplexid" => $v["mplexid"]));
        $dtv = $getDTVMultiplex->fetchAll(PDO::FETCH_ASSOC);

        $extractChannel[] = array("channel"        => $v["channum"],
                                  "virtualChannel" => $v["freqid"],
                                  "callsign"       => $v["callsign"],
                                  "name"           => $v["name"],
                                  "mplexID"        => $v["mplexid"],
                                  "stationID"      => $v["xmltvid"],
                                  "serviceID"      => $v["serviceid"]);

        $extractMultiplex[$v["mplexid"]] = array("transportID" => $dtv[0]["transportid"],
                                                 "frequency"   => $dtv[0]["frequency"],
                                                 "modulation"  => $dtv[0]["modulation"]);
    }

    $extractArray["version"] = "0.06";
    $extractArray["date"] = $todayDate;
    $extractArray["lineup"] = $lineupName;
    $extractArray["channel"] = $extractChannel;
    $extractArray["multiplex"] = $extractMultiplex;

    $json = json_encode($extractArray);

    fwrite($fhExtract, "$json\n");

    /*
     * TODO: send json automatically.
     */

    fclose($fhExtract);

    print "Please send the $lineupName.extract.conf to qam-info@schedulesdirect.org\n";
}

function getLineupFromNumber($numberOrLineup)
{
    global $lineupArray;

    if (strlen($numberOrLineup) < 3)
    {
        $foo = (int)$numberOrLineup;

        if (!array_key_exists($foo, $lineupArray))
        {
            return "";
        }
        else
        {
            return $lineupArray[$numberOrLineup]["lineup"];
        }
    }
    else
    {
        return ($numberOrLineup); //We're actually just returning the name of the array.
    }
}

function printListOfAvailableCountries($fancyTable)
{
    global $availableCountries;

    foreach ($availableCountries as $region => $data)
    {
        print "\nRegion:$region\n";

        $countryWidth = 0;
        foreach ($data as $country => $tla)
        {
            if (strlen($country) > $countryWidth)
            {
                $countryWidth = strlen($country);
            }
        }

        if ($fancyTable)
        {
            $countryList = new Zend\Text\Table\Table(array('columnWidths' => array($countryWidth + 2, 18)));
            $countryList->appendRow(array("Country", "Three-letter code"));

            foreach ($data as $country => $tla)
            {
                $countryList->appendRow(array($country, $tla));
            }
            print $countryList;
        }
        else
        {
            foreach ($data as $country => $tla)
            {
                print "$country\n";
            }
        }
    }
}

function getChannelLogoDirectory()
{
    if (is_dir(getenv("HOME") . "/.mythtv/channels"))
    {
        return (getenv("HOME") . "/.mythtv/channels");
    }

    if (is_dir("/home/mythtv/.mythtv/channels"))
    {
        return ("/home/mythtv/.mythtv/channels");
    }

    return ("UNKNOWN");

}

?>
