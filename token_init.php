<?php
$clientId = 'YOUR_CLIENT_ID';
$tenantId = 'consumers';
$scope    = 'Calendars.Read offline_access';

$step = $argv[1] ?? 'auth';

if ($step === 'auth') {
    // Request device code
    $ch = curl_init('https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/devicecode');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['client_id' => $clientId, 'scope' => $scope]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    file_put_contents('device_code.txt', $response['device_code']);
    echo $response['message'] . "\n";
    echo "\nWhen done, run: php script.php fetch\n";

} elseif ($step === 'fetch') {
    // Poll for token
    $deviceCode = file_get_contents('device_code.txt');
    $token = null;

    while (true) {
        sleep(5);
        $ch = curl_init('https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'   => $clientId,
            'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $token = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($token['access_token'])) break;
        echo "Waiting...\n";
    }

    file_put_contents('refresh_token.txt', $token['refresh_token']);
    unlink('device_code.txt');

    // Fetch events
    $params = http_build_query([
        'startDateTime' => '2026-06-01T00:00:00Z',
        'endDateTime'   => '2026-06-30T23:59:59Z',
        '$select'       => 'subject,start,end',
    ]);

    $ch = curl_init('https://graph.microsoft.com/v1.0/me/calendarView?' . $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token['access_token']]);
    $events = json_decode(curl_exec($ch), true);
    curl_close($ch);

    print_r($events['value']);
}
?>
