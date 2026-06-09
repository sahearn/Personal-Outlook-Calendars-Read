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
