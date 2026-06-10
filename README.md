# Personal Outlook Calendars Read
Pull multiple personal Outlook calendars via MS Graph REST API

## Background
I have several separate calendars in Outlook to maintain personal and family events. For years, I used a messy custom script to pull each calendar's ICS file, parse it (!), and use the event data for my personal wiki and other applications. This was extraordinarily cumbersome and bug-ridden, but I did it because at the time it was easier than trying to make sense of MS Azure.

Azure is still convoluted for simple personal use, but I navigated that and finally rewrote my apps to use the Graph [CalendarView](https://learn.microsoft.com/en-us/graph/api/calendar-list-calendarview) API. The goal was to more reliably get at the same data - multiple calendars that I could pull for a given timeframe, combine and sort, then use for different purposes.

## What You'll Need
- a web host
- PHP
- Outlook data and an Azure app to allow read access

## My Approach
### Azure
I'll detail these steps because they eluded me for a long time, and are actually simpler than ancitipated:
1. Go to [portal.azure.com](https://portal.azure.com), All services, App registration, New registration
2. Name it, and select Personal Microsoft accounts only
3. Set the redirect URI (localhost is fine for these purposes), and register. Save your client Id.
4. In your app, left nav to Manage-API Permissions, Microsoft Graph, Delegated, and add `Calendars.Read`
5. Left nav to Manage-Authentication, Settings, and select Allow public client flows (Without this, I was getting an error: `The client application must be marked as 'mobile.'`)

### OAuth
Next, Graph requires standard OAuth implementation, but there are some initial one-time steps. Rather than build a login page I used a device code flow - this was also ideal for later script (re-)use. See `token_init.php`. Edit as appropriate, then run:
1. `php token_init.php auth`, which displays an authorization URL, plus a unique code. This will authorize access to the new app.
2. `php token_init.php fetch`, which makes an OAuth call for an access_token and makes an initial `calendarView` API call.

After this, only a refresh token is necessary for any subsequent calls. Take note of file paths, since the token is stored in the filesystem. Adjust this as desired.

## Usage
The main work uses the refresh token only, and is in `calendarView-batch_token-refresh.php`. See inline comments for details, but some high-level points to mention:
- I have multiple Outlook calendars, so rather than make separate curl calls for each, Graph supports a single `$batch` operation
- When defining query parameters, note the syntax difference between regular parameters like `startDateTime` and `endDateTime` versus [OData Query Parameters](https://learn.microsoft.com/en-us/graph/query-parameters?tabs=http) like `$select` and `$top` (with the dollar sign).
- I pull all the calendars, combine them, sort the events, and output to a single JSON file. I can then use this JSON file in different ways depending on what I'm doing across other personal applications (wiki, [family event planner](https://github.com/sahearn/Printable-Weekly-Agenda), etc.)
- Graph returns all dates and times as UTC, even if I enter events in Outlook as `America/New York`. So I need to switch to local TZ for any date math or output.

### Some Notes on Overall Logic
The Graph CalendarView API is clean, and way better than trying to make sense of an ICS file - with one exception. Here are the scenarios I accounted for:
- single event, with a specific time (e.g. Team Meeting on 2026-06-03 at 13:00:00 UTC)
  - `start/dateTime` and `end/dateTime` have actual values in UTC
- single event, all day (e.g. Moving Day on 2026-05-23)
  - `isAllday` = true, `start/dateTime` = actual date and 00:00:00 UTC, 'end/dateTime` = **start+1** and 00:00:00 UTC
- repeating event, with a specific time (e.g. Weekly Meeting on Fridays at 14:00:00 UTC)
  - `type` = occurence, but otherwise identical to a single timed event
  - `start/dateTime` and `end/dateTime` have actual values in UTC
- repeating event, all day (e.g. Reminder to Pay Bill on the 1st of every month)
  - `type` = occurence, but otherwise identical to a single all day event
  - `isAllday` = true, `start/dateTime` = actual date and 00:00:00 UTC
- multi-day event, all day (e.g. Vacation from 2026-05-10 to 2026-05-17)
  - `type` = singleInstance, `isAllDay` = true, `start/dateTime` = actual dates and 00:00:00 UTC
 
That last scenario added some extra work. Example, from above: an event from 2026-05-10 to 2026-05-17, but the API call boundaries are 2026-05-16 to 2026-05-18, overlapping the event. What if the event both starts and ends outside of the API timeframe? What if the event both starts and ends inside of the API timeframe? Fortunately, if the event appears in the response, the API knows it occurs *somewhere* in my timeframe - I just need to figure out when. Ideally when I output all this, my personal preference is to have the event appear on every respective day (e.g. 5/16: Vacation, 5/17: Vacation, 5/18: Vacation).

My first approach was to iterate the sorted events. But that resulted in a lot of date math iterations to account for the executed timeframe. I settled on the current approach, which is roughly as follows:
- start with the `startDateTime` from the API call, and iterate one day at a time
- loop the sorted events and match event day to iterated date
- if event is timed, save; if single day event (start=day, end=day+1), save
  - if multi-day event, check if iterated date occurs between event timespan, save
- drop all matched events to a new array, and encode to JSON

## To-Dos
- batch response has status per calendar (`$response['status']`) which is worth checking prior to processing

## Result Usage
I use this in two places: my [family event planner](https://github.com/sahearn/Printable-Weekly-Agenda), and my personal (private) daily-use wiki. Since the planner needs events both before and after "today", my API window is somewhat large. That means for my wiki, I need to trim out any events before "today" and beyond a set threshold (currently 5 days). This code is in `wiki-snippet.php`. Disregard the JS which is unrelated and used to toggle some event visibility on page load.
