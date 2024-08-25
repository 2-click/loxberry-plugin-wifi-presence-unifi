<?php
require_once("loxberry_system.php");
require_once("loxberry_io.php");
require_once("loxberry_log.php");
require_once("loxberry_json.php");
require_once("phpMQTT/phpMQTT.php");
define ("CacheFile", LBPDATADIR."/cookies.json"); #todo actually use that 


//Start logging
$log = LBLog::newLog(["name" => "Process.php"]);
LOGSTART("Script called.");

//Decide for and run function
$requestedAction = "";
if(isset($_POST["action"])){
	LOGINF("Started from HTTP.");
	$requestedAction = $_POST["action"];
}
if(isset($argv)){
	LOGINF("Started from Cron.");
	$requestedAction = $argv[1];
}

# todo implement a status action
switch ($requestedAction){
	case "poll":
		pollUnifi();
		LOGEND("Processing finished.");
		break;
	case "getconfigasjson":
		LOGTITLE("getconfigasjson");
		getconfigasjson(true);
		LOGEND("Processing finished.");
		break;
	case "savejsonasconfig":
		LOGTITLE("savejsonasconfig");
		savejsonasconfig($_POST["configToSave"]);
		LOGEND("Processing finished.");
		break;
	default:
		http_response_code(404);
		notify(LBPCONFIGDIR, "wifi-presence-unifi", "process.php has been called without parameter.", "error");
		LOGERR("No action has been requested");
		break;
}

//Function definitions
function getconfigasjson($output = false){
	LOGINF("Switched to getconfigasjson");
	
	//Get Config
	$config = new LBJSON(LBPCONFIGDIR."/config.json");
	LOGDEB("Retrieved config:".json_encode($config));
	
	if($output){
		echo json_encode($config->slave); 
		return;
	}else{
		return $config;
	}
}

function savejsonasconfig($config){
	LOGINF("Switched to savejsonasconfig");
	
	if(!isset($config) || $config == "" || $config == null || $config == "null"){
		http_response_code(404);
		notify(LBPCONFIGDIR, "tibber2mqtt", "Saveconfig has been called without valid config.", "error");
		LOGERR("Saveconfig has been called without valid config.");
		return;
	}
	
	LOGDEB("Config to save:".$config);
	
	 // Todo: transform mac addresses from line by line to json list

	//Get Config
	$config = getconfigasjson();
	
	// Change a value 
	$config->slave = json_decode($config);
	
	LOGDEB("Updated config object:".json_encode($config));
	
	// Write all changes
	$config->write();
	
	//End in same way as ajax-generic of LB3
	echo json_encode($config->slave); 
	return;
}


function getAccessToken($username, $password, $url)
{
    $ch = curl_init();
    $postData = json_encode([
        'username' => $username,
        'password' => $password,
    ]);

    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_URL, $url . '/api/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    

    $cookieFile = tempnam(sys_get_temp_dir(), 'cookie');
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	LOGDEB("Data retrieved from API:".json_encode($response));
	
    if ($response === false || $httpCode != 200) {
        curl_close($ch);
        throw new Exception("Could not login into API", 503);
    }

    curl_close($ch);

    return $cookieFile;
}

function getSites($cookieFile, $url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_URL, $url . '/api/self/sites');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);



    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	LOGDEB("Data retrieved from API:".json_encode($response));
    if ($response === false || $httpCode != 200) {
        curl_close($ch);
        throw new Exception("Could not fetch API", 503);
    }

    curl_close($ch);

    $sites = json_decode($response);

    return $sites->data;
}

function getClients($cookieFile, $url, $siteName)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_URL, $url . '/api/s/' . $siteName . '/stat/sta/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);



    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	LOGDEB("Data retrieved from API:".json_encode($response));
    if ($response === false || $httpCode != 200) {
        curl_close($ch);
        throw new Exception("Could not fetch API", 503);
    }

    curl_close($ch);

    $clients = json_decode($response);

    return $clients->data;
}

// *****************************************************
// 
// Helper function to retrieve device information from unifi client list
//
// *****************************************************
function checkClients($clients, $mac_addresses_of_interest)
{

}

// *****************************************************
// 
// Fetch online client devices and send status to MQTT
//
// *****************************************************
function pollUnifi(){	
	LOGINF("Switched to pollUnifi");
	LOGTITLE("pollunifi");
	
	//Get Config
	$config = getconfigasjson();
	$config = $config->slave;
	
	if(!isset($config->Main->username) OR $config->Main->token == "" OR !isset($config->Main->password) OR $config->Main->password == ""){
		//Abort, as creds not available.
		http_response_code(404);
		notify(LBPCONFIGDIR, "wifi-presence-unifi", "No credentials saved in settings.", "error");
		LOGERR("No credentials saved in settings.");
		return;
	}

	if(!isset($config->Main->sitename) OR $config->Main->sitename == ""){
		//Abort, as site name not available.
		http_response_code(404);
		notify(LBPCONFIGDIR, "wifi-presence-unifi", "No site name saved in settings.", "error");
		LOGERR("No site name saved in settings.");
		return;
	}
	

	try {
		// Prepare MQTT
		// Get the MQTT Gateway connection details from LoxBerry
		$creds = mqtt_connectiondetails();
		// Create MQTT client
		$client_id = uniqid(gethostname()."_client");
		// Send data via MQTT
		$mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'],  $creds['brokerport'], $client_id);
		if(!$mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'])){
			http_response_code(404);
			notify(LBPCONFIGDIR, "wifi-presence-unifi", "wifi-presence-unifi Plugin: MQTT connection failed", "error");
			LOGERR("MQTT connection failed");
			return;
		}

		// Prepare UniFi API
		$URL = $config->URL;
		$cookieJar = getAccessToken($config->username, $config->password, $URL);

		// Get all clients
		$clients = getClients($cookieJar, $URL, $config->sitename);
		// Start looping through interesting mac addresses and gather information
		foreach ($config->macaddresses as $mac) {
			$deviceFound = false;
			foreach ($clients as $client) {
				if ($client->mac === $mac) {
					$deviceFound = true;
					if ($client->uptime > 1) {
						$online = $true;
					} else {
						$online = $false;
					}
					break; // Stop searching once a matching MAC is found
				}
			}
			if (!$deviceFound) {
				$online = $false;
			}
			

			if ($online === $true) {
				$mqtt->publish("wifi-presence-unifi/clients/" . $mac . "/online", $true, 0, 1); #todo check if $true is good or if we need to send 1
			} else {
				$mqtt->publish("wifi-presence-unifi/clients/" . $mac . "/online", $false, 0, 1); #todo check if $false is good or if we need to send 0
			}
		} 
		$mqtt->close();

	} catch (Exception $e) {
		echo "Error: " . $e->getMessage();
	}

	


}

?>