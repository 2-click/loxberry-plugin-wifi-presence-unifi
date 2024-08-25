<?php

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

    if ($response === false || $httpCode != 200) {
        curl_close($ch);
        throw new Exception("Could not fetch API", 503);
    }

    curl_close($ch);

    $clients = json_decode($response);

    return $clients->data;
}

function checkClients($clients)
{
    $mac_addresses_of_interest = [
    'a4:cf:99:2b:87:d9', // MAC address 1
    '00:11:32:8f:b5:5c', // MAC address 2
    'aa:bb:cc:dd:ee:ff', // Example MAC address 3
    ];

   foreach ($mac_addresses_of_interest as $mac) {
    $deviceFound = false;
    foreach ($clients as $client) {
        if ($client->mac === $mac) {
            $deviceFound = true;
            if ($client->uptime > 1) {
                echo "Device with MAC $mac is online.\n";
            } else {
                echo "Device with MAC $mac is offline.\n";
            }
            break; // Stop searching once a matching MAC is found
        }
    }
    if (!$deviceFound) {
        echo "Device with MAC $mac is offline (no device found).\n";
    }
} 



}
// Example usage:
try {
    $URL = 'https://192.168.1.2:8443';
    $cookieJar = getAccessToken('loxberry', 'Loxberry123!', $URL, false);
    //$sites = getSites($cookieJar, $URL, false);
    $clients = getClients($cookieJar, $URL, "default");

    //echo "Clients:\n";
    //print_r($clients);
    checkClients($clients);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}