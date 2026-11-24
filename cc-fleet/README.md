# CC Fleet — Community Connect bike fleet plugin

Version 0.1.1 · WordPress 6.4+ · PHP 8.0+ (8.2 or 8.3 recommended)

One source of truth for the fleet. Loans drive everything: the public availability
calendar, each bike's live status on the website, reminder emails and the crew's calendar feed.

## What it does

| Area | What you get |
|---|---|
| **Bikes** | Post type at `/fleet/` and `/fleet/cc-12/`. Every column of the FLEET tab as fields. Public vs internal fields (owner, wear, notes, replace/monitor never shown publicly). State: active / workshop / retired. "Show on website" switch. |
| **Loans** | requested → approved → out → returned (or declined / cancelled). Double-booking blocked; same-day handoff allowed; optional buffer days. Overdue = still out after its end date (keeps the bike blocked). |
| **Riders** | The Community Connectors list: name, email, phone, Instagram, height, rating, notes. Matched by email. Private. |
| **Admin** | Fleet Manager menu: Dashboard (to review, overdue, handoffs, out now), Calendar (all bikes × 6 weeks, click a day to book), Loans, Bikes, Riders, Import, Settings. "Fleet Manager" role for crew who shouldn't be full admins. |
| **Website** | Shortcodes: `[cc_fleet]`, `[cc_bike_request bike="CC-12"]`, `[cc_bike_request bike="any"]`, `[cc_bike_calendar bike="CC-12"]`, `[cc_fleet_stat key="loans_this_year"]`. Single bike pages get spec + calendar + request form automatically. |
| **Emails** | Request received (rider) · new request (crew) · approved with handoff contact · declined · cancelled · thanks on return · return reminder · daily crew digest. |
| **Calendar feed** | Private iCal URL for Google/Apple Calendar (Settings). Whole fleet or one bike. |
| **API** | `GET /wp-json/cc/v1/bikes`, `/bikes/CC-12`, `/bikes/CC-12/availability`, `/available?start=&end=&size=`, `/fleet-status`, `POST /requests`. Admin: `/admin/loans`, `/admin/loans/{id}/status`. |
| **Privacy** | Hooks into Tools → Export / Erase Personal Data. Erasing anonymises the rider and keeps the loan dates for fleet history. |
| **Import** | Bikes from the FLEET tab CSV (re-import updates in place, matched on Bike ID). Loans CSV from the old app. A copy of the FLEET tab (20 Nov 2026) is bundled. |

## Install

1. WordPress → Plugins → Add New → Upload Plugin → `cc-fleet.zip` → Activate.
2. Fleet Manager → Import → tick "Use the copy bundled with the plugin" → Import bikes.
   The 10 fleet bikes (CC-03…CC-13 minus CC-10) are published; CC-01, CC-02, CC-10 and CC-14 come in as "backend only": the crew can book, service and track them, riders cannot see or request them.
3. Fleet Manager → Settings: crew emails, agreement and privacy URLs, check the size/height table.
4. Settings → Permalinks → Save (once), so `/fleet/` works.
5. Add a featured image to each bike.

## Daily jobs (important on shared hosting)

WordPress only runs scheduled jobs when someone visits the site. Add a real cron job in the
Host Europe panel that runs every 15 minutes:

    wget -q -O /dev/null "https://community-connect.cc/wp-cron.php?doing_wp_cron"

and add to `wp-config.php`:

    define( 'DISABLE_WP_CRON', true );

The daily job (07:00) marks approved loans as out on their start day, sends return reminders
and emails the crew digest. You can also run it from the Dashboard.

## Email delivery

Install an SMTP plugin (e.g. WP Mail SMTP) and send through a real mailbox
(e.g. contact@community-connect.cc) so confirmations don't land in spam.

## Notes for the theme

- Styles are structural and use CSS variables (`--cc-ink`, `--cc-paper`, `--cc-pink`, …). Override them in the child theme.
- To design the single bike page yourself, add `add_filter( 'cc_fleet_auto_single', '__return_false' );`
  and place the shortcodes in your template.
- Fleet grids refresh their status pills from the API after load, so full-page caching is safe.
  Don't cache `/wp-json/cc/v1/*` (responses already send `no-store`).

## 0.19.7: Find a bike for an event

- Every dated event (and each date of a series) has a "Find a bike" button: it checks the fleet for pick-up two days before to the day after, scrolls to the fleet and shows which bikes are free.
- Tapping a free bike opens its page with those dates picked in the calendar and the event chosen in the request form (racing terms shown for races).

## 0.19.6: Fleet photos

- Kettensäge 56 (CC-12) and 58 (CC-13) had each other's photo; swapped.

## 0.19.5: Wording

- Numbers: "Connectors on our bikes" now reads "Riders on our bikes".

## 0.19.4: Road or gravel on every event

- Each event row starts with a filled Road / Gravel / MTB pill (from its categories); Road and Gravel filters added.
- Events sort by their start date, including ones shown with a text date (e.g. "date TBC").

## 0.19.3: Next 5 events

- The events list shows the next 5 by date; "Show all N events" folds out the rest. Works with the category filters.

## 0.19.2: Reminders in the loan history

- The daily return reminder now shows in the loan's History ("email sent reminder"), so you can see when it went out.

## 0.19.1: Return reminders for imported loans

- The import marked every imported loan as already reminded, so riders still out on a bike never got their "due back" email. Now only loans that had already ended are quietened; a one-off migration un-quietens running ones that never had a reminder.
- A reminder that was missed (e.g. the 07:00 run didn't happen) still goes out the next morning, as long as the bike isn't due back yet.
- Dashboard: new "Due back soon" panel (today to two days ahead) with a "Send the reminder now" button.

## 0.19.0: Pick-up on the confirmation

- The confirmation screen has a Pick-up box: from the rider before (name, until when, number for the crew), or at an address with a contact (name and number) and optional time. Saved on the loan and shown in the email’s details and first step.
- Suggested: the rider before when there is one; our base (Settings: “Our base”, default 22 Hilyard Street, Woodstock, 7925) when the loan starts more than 7 days out and nobody has the bike before. Sending with an empty address is stopped.
- Return reminders use our base when no drop-off place is set.

## 0.18.0: Confirm other dates

- The “Email to the rider” screen for a confirmation has a Dates box: confirm fewer (or other) days than the rider asked for. The loan and calendar update (with the usual clash and free-day checks), the email’s details, calendar link and return date follow, the crew’s text is kept, and when it’s shorter a note in the pink box says so (editable).

## 0.2.0: Workshop, Stock, Events, Notes

Everything the old Vercel app did now lives under Fleet Manager:

- **Workshop**: scheduled and completed jobs per bike (type, date, cost, technician). A job that is due takes the bike off the website; "Done" puts it back.
- **Stock**: items with quantity, minimum and colour tag; one-click −1 / +1 (logged with who did it); low items are counted in the menu and shown on the dashboard.
- **Events**: list and month view; status follows the dates; loans can be linked to an event (on the loan, or found from the loan's plan) so "bikes attended" counts itself. Events also appear as a row on the fleet calendar. Tick "Show on the website" and use `[cc_events]` (or `[cc_events show="past" limit="3"]`) to list them on a page.
- **Notes**: crew notes with archive.
- **Dashboard**: loans per month, next events, workshop queue, low stock.
- **Import**: one CSV per section (Import page → "Workshop, stock, events, notes").

## 0.3.0: Designed emails

All loan emails now use the Community Connect design (600px, email-safe tables, PNG logo, plain-text copy attached): request received, confirmed (with handoff, calendar link and bike page), declined (with free alternatives and the next free date), cancelled, returned, return reminder (next rider or drop-off place), crew new-request alert and the crew daily digest (now with workshop jobs due and low stock).
Settings: handoff note, drop-off place, Instagram handle, and Email style (designed or plain text) with a **Preview the emails** link.

## 0.3.1: Bike history and changed dates

- Bike page: "Changing parts?" box (date, kind of work, by, cost, note). Saving compares every part field and logs what changed from what to what, with that date; it also goes into the workshop log and updates "Last upgraded". The new **History** box lists all of it, newest first. Untick "Add to the workshop log" for typo fixes.
- Loan page: **Change the dates** (+1 day, +3 days, +1 week, ends today, or any dates), with a note on what was agreed, a clash check and an optional "new dates" email to the rider. Overdue loans extend from today.

## 0.17.2: Brevo check

- Fleet Manager → Newsletter shows whether Brevo answers (list name and contacts), tested on save and every week. The weekly check also keeps the API key in use, as Brevo retires keys after 90 idle days.

## 0.17.1: Event series fold up

- Events named like a series (“FAR Inverted #1”, “#2”…) show as one row on the home page with the date range, and “All 8 dates” opens the list.

## 0.17.0: Newsletter (theme 1.5.0)

- A newsletter sign-up on the home page (and `[cc_newsletter]` anywhere): email, name, interests and a consent tick. A confirmation email makes it a double opt-in; every email has an unsubscribe link.
- Fleet Manager → Newsletter (admins): the list, CSV export, delete a subscriber, and an optional Brevo API key + list ID: confirmed subscribers are copied into that Brevo list and unsubscribes passed on.
- GA4 (after consent): `sign_up` with method newsletter.

## 0.16.2: Rides from Strava

- FAR Inverted counts as a race: borrowing a bike for it needs the racing terms.
- The Gravel group ride, the Weekend Ender and Social weekends 2027 are off the calendar again: their dates come from the Strava club’s group events (Fleet Manager → Connections), which are now labelled “Group ride”.
- Strava events taken off the club calendar no longer show on the home page or in the request form.

## 0.16.1: FAR Inverted goes weekly

- FAR Inverted is every Tuesday from 19 Jan to 9 Mar 2027 (eight rides). The 29 Dec and 12 Jan rides are cancelled, the others renumbered, the missing Tuesdays added (once, on update). Theme 1.4.1 updates the Rides card.

## 0.16.0: Regular rides (theme 1.4.0: the Rides section)

- Events get **Regular ride: how it happens** (e.g. “Every Friday · sunrise”). Filled in, the ride stays listed on the website and in the request form from its Date until “Until” (or for good), shown with that text instead of a date, and at the top of the list.
- New event categories: **Group ride** and **Mountain bike**, with a “Group rides” filter on the home page.
- Our rides are added to the calendar once: the Gravel group ride, the Weekend Ender, Social weekends 2027 and the six FAR Inverted rides (29 Dec 2026 → 9 Mar 2027). After that they’re normal events: edit or cancel them under Events.
- Theme 1.4.0: a **Rides** section on the home page (and in the menu) explaining the four kinds of rides.

## 0.15.0: Bike IDs stay internal

- Riders never see a Bike ID (CC-12) any more. Fleet cards (home page and `[cc_fleet]`) show the Standert logo where the ID was; bike pages show the logo above the model and size.
- Everywhere else riders see the bike's public name: model, size and its own name, e.g. "Erdgeschoss 56 · Pang! Pink" (page titles, breadcrumbs, the sticky bar, "Request this bike", form messages, every rider email and the rider's data export).
- Bike pages move from /fleet/cc-12/ to /fleet/erdgeschoss-56/. The old addresses redirect.
- The request form and calendar use the bike's internal number instead of the ID. Shortcodes still accept `bike="CC-12"`.
- Fleet Manager, crew emails, the calendar feed and the API keep the Bike ID.

## 0.14.0: Waiting list

- **Joining.** When a rider picks a bike that's booked, the form now offers "Join the waiting list for these dates". The crew can also move a request onto the list (the "Waiting list" button shows on requests whose bike is taken). A place on the list is a loan with status *Waiting list*; it never blocks the calendar.
- **A spot opens** when a loan is cancelled, returned early or shortened, or a bike comes back from the workshop. The crew gets "Spot open" with everyone who fits, in the suggested order: event riders first, then by the day they asked, then riders who haven't borrowed in 3 months, then the exact time. Left out: dates that don't fit (with the free day), a height outside the bike's range, a bike overdue right now.
- **Offers.** "Offer" (dashboard → Waiting list, or the loan page) holds the dates and emails the rider a yes/no link. With review on, the crew checks the email first and the clock starts when it goes out. No answer within 24 hours, or a no: it moves to the next rider by itself and the crew gets a one-line note. Starting within 48 hours: "Offer to the top 3 at once", first yes wins. The rider links open a page with one button, so mail scanners can't answer for anyone.
- **Can't make it?** The confirmation email has a button to cancel early, which frees the bike for the list.
- **Closing.** On the start day, anyone still waiting gets "Not this time" with the bike's next free date.
- Seven new emails, all on the email preview page. Settings: hours to answer (24), short notice (48 hours), riders offered at short notice (3). An hourly job handles the timers.
- Tests: `tests/waitlist-test.php` (run after `smoke-test.php`).

## 0.3.2: Long-term riders

- Bike page → Website box → **Long-term rider** + **Since**. From that date the bike is blocked on every calendar with no end date (fleet calendar shows an ochre bar, the public calendar shows it booked, booking it is refused with a clear reason unless you save anyway). Set it back to None to free the bike.
- Re-importing the parts list records each changed part in the bike's History ("Updated from the parts list"). Bundled copy updated to the 20 Nov 2026 16:55 sheet (CC-01 tyres, CC-02 size/groupset/brakes/chainring, CC-03 full spec).

## 0.12.0: Event categories (theme 1.1.0: the deal and the agreement in one)
- Events have several categories: Race, Social ride, Overnight bikepacking, Bikepacking trip, Gravel, Road, Getaway, Other (checkboxes in Fleet Manager → Events). Existing events are converted on update (Gravel → Race + Gravel, Group ride → Social ride + Gravel).
- Racing terms only for events marked Race (and “Another race”); “Another ride or event (not a race)” on the request form.
- The website's events list shows every public event with its categories and filter buttons (Races, Social rides, Overnight bikepacking…).
- Theme 1.1.0: “The deal” and the borrowing agreement are one section (who pays, then the agreement fold-outs, version 4); the menu has one link.

## 0.11.0: Usual loan length
- New setting “Usual loan (days)”, 7. Shown on the request form, the bike page and the fleet heading; the borrowing agreement (now version 3) says loans are usually up to 7 days and exceptions are made when there's a need.
- Picking a longer range shows a friendly note asking for the reason in the plan (not blocked; the hard limit stays “Longest loan”), and the request is marked for the crew.

## 0.10.2: Fleet grid spacing
- The bike cards line up with the size finder and filters (inset on phone and desktop), rows have equal heights and the box is closed at the bottom. Theme 1.0.2: the finder, filters and date boxes keep their spacing.

## 0.10.1: Fix
- Expense amounts like “1,529.30” or “R 2 210,00” are read correctly (thousands separators).

## 0.10.0: Expenses
- Fleet Manager → Expenses: every invoice with date, supplier, number, what for, kind, bikes, amount and VAT, paid or unpaid (when, by whom), and any part a rider pays back.
- Invoice files are stored privately (outside the Media Library, blocked from the web) and open only for the crew.
- Drop invoices: PDFs, photos or a whole WhatsApp chat export (.zip). Files are matched to their expense by invoice number; new ones become expenses to fill in.
- Totals for the year, still to pay, by supplier, by kind and by bike; CSV download for the bookkeeper. Linked bikes show their invoices in their history; the dashboard lists unpaid invoices.

## 0.9.1: Fix
- Workshop jobs and stock items saved with an empty Cost / Quantity field were silently dropped on MySQL (strict mode). Empty now means 0, and a failed save shows an error instead of “Saved”.

## 0.9.0: Wheels & tyres, drivetrain care, tyre pressure
- Fleet Manager → Wheels & tyres: every wheelset (WS-xx) and set of tyres (TY-xx) is a part with its place (a bike or spare), a Damaged tick with a note, and its own history. Created automatically from the bikes on update.
- Bike editor: Wheelset and Tyres are drop-downs. A part can only be on one bike: taking it from another bike puts that bike “In the workshop” with a job to fit a replacement, and both bikes get a history entry.
- Drivetrain care box per bike: cleaned, lubed/waxed, checked, chain-wear reading; a check is due after every returned loan and every 30 days, lube every 21; replacing a chain takes it out of Stock. Dashboard: “Drivetrain due” and “Wheels & tyres”.
- Tyre pressure: optional weight on the request form and the rider; the confirmation email, the loan page and the crew handoff check show a starting front/rear pressure for that rider on that bike's tyres.

## 0.8.2: Updates from GitHub
- Fleet Manager → Updates: point the site at the GitHub repository (plus a read-only token if it's private). New releases of the plugin and the Community Connect theme then show under Plugins and Themes with an Update button, and work with auto-updates.

## 0.8.1: Bike photos, new bike page, faster images
- Photos box in the bike editor: add several photos from the Media Library, drag to reorder; the first one leads the slider on the bike page and shows on the fleet cards.
- Optional Tag per bike (e.g. "Repainted 2026"), shown as a pink label.
- Bike page as in the Mobile 2027 mockup: photo slider, Quick specs, size grid with "What's my size?", build grouped into Groupset / Wheels / Tyres / Cockpit / Saddle (tap for details), pink booked days, sticky bar with a thumbnail.
- Home page photos ship as WebP (about 40% lighter) so Cloudflare can cache them.
- Changes in the bike boxes always enable Save in the editor.

## 0.8.0: Works with the Community Connect theme
- New shortcodes for the theme's editable home page: [cc_home_fleet], [cc_how_loop], [cc_events_list], [cc_numbers], [cc_who_pays]. They keep the live parts (bikes, availability, events, numbers) inside sections you edit in Appearance → Editor.
- When the Community Connect theme is active, the plugin's own landing template stands down and the theme draws the page.
- Bike pages in the Mobile 2027 layout: breadcrumb, size grid across the fleet, build list, availability and a sticky request bar on phones.

## 0.7.0: Home page in the Mobile 2027 look

- The home page follows the "Community Connect — Mobile 2027" design (Standert on phones): compact header with menu, photo slides, size finder with filter chips and a two-column bike grid, the Fleet 2027 banner, how it works (loop animation + step cards), events, numbers, the deal game, the any-bike form, the agreement as an accordion, a black footer with fold-out links.
- Photos ship with the plugin (assets/landing/img). A bike with a featured image in WordPress shows that photo; the others show their colour with the size until photos come.
- Each event shows its own year.

## 0.6.1: Fix

- Updating from 0.4/0.5 crashed the site: the home page was created before WordPress had finished loading. It's now created a moment later in the same request.

## 0.6.0: One site, events 2027, racing terms, raffle bikes

- **Home page in WordPress.** A full-page template, *CC Landing*, with the hero animation (the CC icon rides the six steps), how it works, the live fleet with a height slider and date check, an "any bike" request form, events, the deal game and the agreement. The page "Borrow a bike" is created and made the home page if the site still showed latest posts. Built in the 2027 style tile: Inter Tight, EB Garamond, IBM Plex Mono, pill buttons, hairline tiles, Paper/Ink/Sand/Pink/Ochre/Cream.
- **Bookable until 31 December of next year** (Settings → Bookable until, empty = automatic). Bike calendars load the whole window and stop there.
- **Events on the request form.** Riders pick an event (or "Another race"); the racing terms appear and must be ticked. Settings → Racing terms holds the wording. Crew emails show the event; the confirmation repeats the terms.
- **Raffle bikes.** On an event's page: *Raffle bikes* → tick bikes → *Hold for the raffle*. Held bikes block the calendar and show as "Raffle bike" to riders. Add the winner as the rider on the loan, then *Give it to the winner*; or *Release the bike*. The morning digest lists raffle bikes without a winner a week out.
- The agreement link follows the home page (`/#agreement`) unless you set one.

## 0.5.0: Check emails before they go, handoff check, one free day

- **Review rider emails before sending** (Settings, on by default). Approve, decline, cancel, return and date changes open an "Email to the rider" screen: change the subject, headline and text, add a note in a pink box, look at the preview, then *Send to rider* or *Don't email*. Anything you leave waiting shows under **Emails waiting for you** on the dashboard.
- **Return handoff** card on each approved or running loan: where, when and a note. The return reminder (and the confirmation) include it.
- **Read or edit the reminder** ahead of time from the loan page. *Save for the reminder* and the daily job sends your version instead of the standard one.
- **Handoff check for the crew**: the morning before reminders go out, the crew list gets one email with each bike, the current and next rider, phone numbers, WhatsApp buttons and the agreed handoff.
- **One free day between loans**: free days default to 1 and same-day handoffs are off. Updating from 0.4 sets this on your site and points the agreement link to community-connect.cc/#agreement if it was empty.
- "Next rider" and "handoff from" only count loans within the free days + 2, so a booking weeks later isn't treated as the next handoff.

## 0.4.0: Riders, required fields, WhatsApp

- Request form: every field is required (Instagram accepts "none"). WhatsApp number with a country-code picker (South Africa first), stored as +27…; email checked for common typos and for a domain that takes mail (only when the server's DNS works). Fixed sideways scrolling on narrow phones.
- Riders are one record per person: requests reuse the same record (email, then Instagram, then phone + name). Loan form: rider dropdown with a filter box, or "+ New rider". Riders page: "+ Add rider" (no email needed), WhatsApp status (not checked / yes / no), click-to-chat links.
- On upgrade, existing phone numbers are rewritten as +country numbers (no code given = South Africa); unreadable ones are left as they were.
- WhatsApp: set the crew's number in Settings. Riders then get "Say hi on WhatsApp" after requesting (and in the received email), pre-filled with their request number; the crew alert has "Chat on WhatsApp" to the rider. Automatic confirmation needs the WhatsApp Business API (not set up).
