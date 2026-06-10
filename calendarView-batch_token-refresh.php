<?php

// to-do: batch response has status per calendar ($response['status']) - I should check this

// calendar ids
// any calendar added here needs to be added to curl batch request and flatten_events() function
$calIdEvents = '[events id]';
$calIdHols   = '[holidays id]';
$calIdWork   = '[work id]';

// this is a big window to pull events, but it's used for weekly planner.
// other use like wiki will skip before and after desired timeframe
$daysBehind = 14;
$daysToAdd  = 15;

$now            = new DateTimeImmutable('00:00:00', new DateTimeZone('America/New_York')); // or "now midnight"
$nowMinus14     = $now->modify("-$daysBehind days");
$startDateTime  = $nowMinus14->format(DateTime::ATOM);  // (e.g., 2026-06-08T13:35:07Z if UTC)
$nowPlusDays    = $now->modify("+$daysToAdd days");     // double quotes to read variable
$endDateTime    = $nowPlusDays->format(DateTime::ATOM);

// MS Graph OAuth
$clientId = '[app client id]';
$tenantId = 'consumers';

$refreshToken = file_get_contents('/path/to/refresh_token.txt');

$ch = curl_init('https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id'     => $clientId,
    'grant_type'    => 'refresh_token',
    'refresh_token' => $refreshToken,
    'scope'         => 'Calendars.Read offline_access',
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$token = json_decode(curl_exec($ch), true);
curl_close($ch);

// Save the new refresh token (they rotate on each use)
file_put_contents('/path/to/refresh_token.txt', $token['refresh_token']);

// API Call, calendars as batch instead of individual curls
// $select: https://learn.microsoft.com/en-us/graph/api/resources/event
$params = http_build_query([
    'startDateTime' => $startDateTime,
    'endDateTime'   => $endDateTime,
    '$select'       => 'subject,start,end,isAllDay,originalStart,recurrence,type', // (or omit $select for "all" - big response!)
    '$top'           => 100,
]);

$batch = [
    'requests' => [
        ['id' => '1', 'method' => 'GET', 'url' => '/me/calendars/' . $calIdEvents . '/calendarView?' . $params],
        ['id' => '2', 'method' => 'GET', 'url' => '/me/calendars/' . $calIdHols   . '/calendarView?' . $params],
        ['id' => '3', 'method' => 'GET', 'url' => '/me/calendars/' . $calIdWork   . '/calendarView?' . $params],
    ]
];

$ch = curl_init('https://graph.microsoft.com/v1.0/$batch');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($batch));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token['access_token'],
    'Content-Type: application/json',
]);
$result = json_decode(curl_exec($ch), true);
curl_close($ch);

// show errors, if applicable
if (!isset($result['responses'])) {
    echo "Batch call failed:\n";
    print_r($result);
    return;
}

// batch result is a multi-layer nested array, by calendar id, in the order they were
// sent in batch. but i prefer all events at same level so i can sort them
$calArrayAll = flatten_events($result);

// for debugging
// file_put_contents('/path/to/batch-all.json', json_encode($calArrayAll, JSON_PRETTY_PRINT));

// sort by event start
usort($calArrayAll, function($a, $b) {
    return $a['start']['dateTime'] <=> $b['start']['dateTime'];
});

// for debugging
// file_put_contents('/path/to/batch-all-sorted.json', json_encode($calArrayAll, JSON_PRETTY_PRINT));

// there are different ways to do this, and i've tried them. the cleanest way for me is to 
// iterate my timeframe of calendar days and find matching events. not scalable, but for 
// personal use (<20 events/week) it's ideal
$finalResult = [];
for ($i = 0; $i < $daysBehind + $daysToAdd; $i++) {
    $workingDate = $nowMinus14->modify("+$i days");

    // find events for current day in loop
    $ids = getIndicesByStartDateTimeSubstring($calArrayAll, $workingDate->format('Y-m-d'));

    if (!empty($ids)) {
        $dateKey = $workingDate->format('Y-m-d');

        foreach ($ids as $index) {
            $allDay = '';
            $time   = '';
            if ($calArrayAll[$index]['isAllDay']) {
                $allDay = true;
                $time = null;
            } else {
                $localStartDateTime = new DateTime($calArrayAll[$index]['start']['dateTime'], new DateTimeZone('UTC'));
                $localStartDateTime->setTimezone(new DateTimeZone('America/New_York'));
                $allDay = false;
                $time = $localStartDateTime->format('H:i:s');
            }

            $event = [
                'calendarId' => $calArrayAll[$index]['calendarId'],
                'title'      => $calArrayAll[$index]['subject'],
                'allDay'     => (bool) $allDay,
                'time'       => $time,
            ];

            $finalResult[$dateKey][] = $event;
        }
    }
}

// i use this in multiple places so write out to json for later
file_put_contents('/path/to/calendarView-batch.json', json_encode($finalResult, JSON_PRETTY_PRINT));


// flatten events to one array level, and insert calendarId per event for later use
function flatten_events($result) {
    // order matters, per batch request above
    $calendarNames = [0 => 'E', 1 => 'H', 2 => 'W'];
    $flat = [];

    foreach ($result['responses'] as $response) {
        $calIndex = (int)$response['id'] - 1; // ids are 1-based
        $calName  = $calendarNames[$calIndex] ?? $calIndex;

        foreach ($response['body']['value'] as $event) {
            $event['calendarId'] = $calName;
            $flat[] = $event;
        }
    }

    return $flat;
}

// a lot of additional processing here is really only necessary to accommodate multi-day 
// (not recurring) events. unlike recurring, the API doesn't expand these out, and i want to 
// see them each day
function getIndicesByStartDateTimeSubstring(array $events, string $searchString): array {
    $matchedIndices = [];

    foreach ($events as $index => $event) {
        if ($event['isAllDay']) {
            // all day event, including multi-day span events
            // don't convert from UTC, because that will fall back to 'yesterday'

            $eventStartDate  = new DateTime($event['start']['dateTime'], new DateTimeZone('UTC'));
            $eventEndDate    = new DateTime($event['end']['dateTime'], new DateTimeZone('UTC'));
            $searchDateAsUTC = new DateTime($searchString, new DateTimeZone('UTC'));

            $interval = $eventStartDate->diff($eventEndDate);
            if ($interval->days > 1) {
                // multi-day event
                if (isDateBetween($searchDateAsUTC, $eventStartDate, $eventEndDate)) {
                    $matchedIndices[] = $index;
                }
            } else {
                // one day event (api expands recurring events)
                if (str_contains($eventStartDate->format('Y-m-d'), $searchString)) {
                    $matchedIndices[] = $index;
                }                
            }

        } else {
            // timed event  (api expands recurring events)
            // convert from UTC to local

            $localStartDateTime = new DateTime($event['start']['dateTime'], new DateTimeZone('UTC'));
            $localStartDateTime->setTimezone(new DateTimeZone('America/New_York'));
            if (str_contains($localStartDateTime->format('Y-m-d'), $searchString)) {
                $matchedIndices[] = $index;
            }
        }
    }

    return $matchedIndices;
}

function isDateBetween(DateTimeInterface $target, DateTimeInterface $start, DateTimeInterface $end): bool {
    return $target >= $start && $target < $end;
}

?>