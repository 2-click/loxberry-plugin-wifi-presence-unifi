<?php
require_once("loxberry_system.php");
require_once("loxberry_io.php");
require_once("loxberry_log.php");
require_once("loxberry_json.php");
require_once("phpMQTT/phpMQTT.php");
define ("CookieFile", LBPDATADIR."/cookies"); #todo actually use that 


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
	LOGDEB("Retrieved backend config: ".json_encode($config));
	
	if($output){
		echo json_encode($config->slave); 
		return;
	}else{
		return $config;
	}
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
	LOGDEB("Data retrieved from unifi API: ".json_encode($response));
	
    if ($response === false || $httpCode != 200) {
        curl_close($ch);
		notify(LBPCONFIGDIR, "wifi-presence-unifi", "wifi-presence-unifi Plugin: Login to unifi failed", "error");
		LOGERR("Login to unifi failed (username: " . $username . ")");
		die();
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
	LOGDEB("Data retrieved from unifi API: ".json_encode($response));
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
		notify(LBPCONFIGDIR, "wifi-presence-unifi", "wifi-presence-unifi Plugin: fetching unifi clients failed", "error");
		LOGERR("Fetching unifi clients failed");
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
	LOGINF("Starting action poll");
	LOGTITLE("pollunifi");
	
	//Get Config
	$config = getconfigasjson();
	$config = $config->slave;
	
	if(!isset($config->Main->username) OR $config->Main->username == "" OR !isset($config->Main->password) OR $config->Main->password == ""){
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
		$cookieJar = getAccessToken($config->Main->username, $config->Main->password, $config->Main->url);

		// Get all clients
		$clients = getClients($cookieJar, $config->Main->url, $config->Main->sitename);

		if (is_array($clients)) {
			LOGINF("Received ". count($clients) . " clients from unifi");
		}
		

		// Start looping through interesting mac addresses and gather information
		foreach ($config->Main->macaddresses as $mac) {
			LOGINF("Searching ". $mac. " in unifi API results");
			$deviceFound = false;
			foreach ($clients as $client) {
				if ($client->mac === $mac) {
					$deviceFound = true; //We found the mac address in the unifi results
					$foundClient = $client; // For later use
					if ($client->uptime > 1) {
						$online = true;
					} else {
						$online = false;
					}
					break; // Stop searching once a matching MAC is found
				}
			}
			if (!$deviceFound) {
				$online = false;
				LOGINF("Client ". $mac. " not found in unifi API results, forcing status offline");
			}
			


			//prepare some variables for mqtt transmission
			$mqttFriendlyMac = str_replace(':', '-', $mac);
			if ($foundClient->powersave_enabled) {
				$mqttFriendlyPowersaveEnabled = 1;
			} else {
				$mqttFriendlyPowersaveEnabled = 0;
			}
			if ($foundClient->ap_mac !== null) {
				$mqttFriendlyApMac = str_replace(':', '-', $foundClient->ap_mac);
			} else {
				$apMac = "";
			}
			if ($foundClient->disconnect_timestamp !== null) {
				$mqttFriendlyLastDisconnectAgo = time() - $foundClient->disconnect_timestamp;
			} else {
				$mqttFriendlyLastDisconnectAgo = -1;
			}

			if ($foundClient->last_seen !== null) {
				$mqttFriendlyLastSeenAgo = time() - $foundClient->last_seen;
			} else {
				$mqttFriendlyLastSeenAgo = -1;
			}

			if ($foundClient->uptime !== null) {
				$mqttFriendlyUptime = $foundClient->uptime;
			} else {
				$mqttFriendlyUptime = -1;
			}
			
			if ($foundClient->assoc_time !== null) {
				$mqttFriendlyAssocTimeAgo = time() - $foundClient->assoc_time;
			} else {
				$mqttFriendlyAssocTimeAgo = -1;
			}
			
			if ($foundClient->latest_assoc_time !== null) {
				$mqttFriendlyLatestAssocTimeAgo = time() - $foundClient->latest_assoc_time;
			} else {
				$mqttFriendlyLatestAssocTimeAgo = -1;
			}

			if ($foundClient->_uptime_by_uap !== null) {
				$mqttFriendlyUptimeByUAP = $foundClient->_uptime_by_uap;
			} else {
				$mqttFriendlyUptimeByUAP = -1;
			}
			
			

			//MQTT transmission
			if ($online === true) {
				LOGINF("Client ". $mac. " is online");
				$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/online", 1, 0, 1);
			} else {
				LOGINF("Client ". $mac. " is offline");
				$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/online", 0, 0, 1); 
			}

			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/powersave_enabled", $mqttFriendlyPowersaveEnabled, 0, 1); // This is either 0 or 1
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/ap_mac", $mqttFriendlyApMac, 0, 1); // This is a MAC
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/disconnect_ago", $mqttFriendlyLastDisconnectAgo, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/last_seen_ago", $mqttFriendlyLastSeenAgo, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/uptime", $mqttFriendlyUptime, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/uptime_by_uap", $mqttFriendlyUptimeByUAP, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/assoc_time_ago", $mqttFriendlyAssocTimeAgo, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/latest_assoctime_ago", $mqttFriendlyLatestAssocTimeAgo, 0, 1); //These are seconds

		} 
		$mqtt->close();

	} catch (Exception $e) {
		LOGERR($e->getMessage());
	}

	


}

?>