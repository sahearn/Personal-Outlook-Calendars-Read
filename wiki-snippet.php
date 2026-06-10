<div id="calendar_block">
    <div id="cb_left">
        <div class="cb_header">Calendar
        <?php
        $filename = '/path/to/calendarView-batch.json';
        if (file_exists($filename)) {
            date_default_timezone_set('America/New_York');
            print '&nbsp; <span style="font-size:0.8em;color:#999;">' . "As of " . date ("n-j-Y, g:i a", filemtime($filename)) . '</span>';
        }
        ?>
        </div>
<?php
$calArrayAll = json_decode(file_get_contents('/path/to/calendarView-batch.json'), true);

$now = new DateTimeImmutable('now midnight', new DateTimeZone('America/New_York'));
$tomorrow = $now->modify('+1 day midnight');
// pull script gets 9 days for weekly planner; i only want 5 here
$inFiveDays = $now->modify('+5 days midnight');

foreach ($calArrayAll as $date => $events) {

    $runningDate = new DateTime($date, new DateTimeZone('America/New_York'));

    if ($runningDate < $now) {
        continue;
    }

    if ($runningDate > $inFiveDays) {
        break;
    }
    
    if ($runningDate->format('Y-m-d') == $now->format('Y-m-d')) {
        $displayDate = 'Today';
    } else if ($runningDate->format('Y-m-d') == $tomorrow->format('Y-m-d')) {
        $displayDate = 'Tomorrow';
    } else {
        $displayDate = $runningDate->format('l, F j');
    }
    print "\t\t" . '<div class="cb_event_date">' . $displayDate . '</div>' . "\n";

    print "\t\t" . '<div class="cb_events_block">' . "\n";
    foreach ($events as $event) {
        switch($event['calendarId']) {
            case 'H':
                $whichCal = 'Holidays';
                break;
            case 'W':
                $whichCal = 'Work';
                break;
            default:
                $whichCal = 'Events';
                break;
        }

        // for work cal toggle
        $hideOrShow = "block";

        if ($event['allDay']) {
            $displayTime = 'All Day';
        } else {
            $displayTime = new DateTime($event['time'], new DateTimeZone('America/New_York'));
            $displayTime = $displayTime->format('g:i a');
        }

        print "\t\t\t" . '<div class="cb_event_block event_block-' . $whichCal . '" style="display:' . $hideOrShow . ';">' . "\n";
        print "\t\t\t\t" . '<span class="which-' . $whichCal . '">&nbsp;&nbsp;</span> &nbsp;';
        print '<span class="cb_event_time">' . $displayTime . '</span>: ';
        print '<span class="cb_event_desc">' . $event['title'] . '</span>' . "\n";
        print "\t\t\t" . '</div>' . "\n";
    }
    print "\t\t" . '</div>' . "\n";
}

?>
    </div>
</div>
