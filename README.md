# VetraDRR — Integrated Disaster Response & Early Warning Platform

A PHP + MySQL prototype inspired by the supplied dark/orange SaaS dashboard reference.

## What the system does
VetraDRR is a local disaster-response dashboard for civilians and response administrators. It brings emergency reports, household vulnerability, evacuation-center capacity, relief inventory, and communication into one PHP application backed by MySQL.

- **Civilians** can create an account, submit an SOS with a danger level, written situation report, optional photo, and GPS coordinates, then review their recent reports and chat with the response team.
- **Administrators** can review incoming SOS reports, assign a responder and team, update report status and escalation level, and view mapped household risk and evacuation-center capacity. A priority queue highlights danger-zone households with additional vulnerability needs.
- **Both roles** can view the map and current weather summary and use the command chat. The admin view also includes the SMS console and relief inventory ledger; inventory quantities and recent receipts are shown for transparency.

The dashboard combines locally stored records with external map tiles, a current-conditions feed from Open-Meteo, and a link to Zoom Earth. SMS reports can be submitted by an external GSM modem or SMS bridge and are stored with parsed fields and the original message. The supplied database includes sample accounts and response data for demonstration.

This is a prototype, not an official warning or emergency-dispatch service. Weather and sample records may not reflect local conditions, and sending an SOS or receiving an SMS does not guarantee that responders will be notified. Verify alerts with official local authorities.

## Features and dashboard sections

The dashboard changes according to the signed-in role. Civilians get tools for requesting help and viewing their own submissions; administrators get response-management tools and operational summaries.

### Shared sections
- **Overview:** The dashboard landing area introduces the response workspace and provides shortcuts to the map and weather context.
- **Map and location preview:** A Leaflet map displays the civilian's latest SOS coordinates, or can use browser GPS for a current-location preview. The admin map plots registered households by risk level and evacuation centers by location. Its Zoom Earth link opens satellite, radar, wind, and storm-track context in a separate tab. Map tiles come from OpenStreetMap.
- **Weather and situation:** Shows a current-conditions summary, temperature, rainfall, and wind information from Open-Meteo, with a simple watch message based on the returned weather values. This is general feed context, not an official local warning.
- **Command chat:** Signed-in civilians and administrators can send messages and view the latest shared messages. The dashboard refreshes the chat and live weather display periodically.

### Civilian sections
- **Emergency request / SOS:** Submit a written situation report and select a danger level. The form can include a photo and GPS coordinates when location access is available. Submissions are stored as civilian reports and appear in the user's recent SOS list.
- **Recent SOS reports:** Shows the signed-in civilian's latest submissions, including their report text, coordinates, status, and any uploaded photo.
- **Location tools:** Browser GPS can center the map preview and provide coordinates for the SOS form. Location sharing depends on the user's browser permission.

### Administrator sections
- **Live SOS alert feed:** Displays recent civilian reports with danger level, description, location, and status. Administrators can set a report's status, escalation level, assigned responder, and response team.
- **Evacuation capacity matrix:** Summarizes registered evacuation centers, current occupancy, capacity, and the stored center status. Progress bars make high occupancy easier to spot.
- **Priority household queue:** Lists registered households ordered by risk. Households marked Danger receive higher priority, and those with recorded vulnerability needs (such as infants, seniors, disability, or pregnancy) are highlighted as urgent.
- **SMS incident console:** Shows recent SMS reports and provides a form for logging an inbound message. The `sms_ingest.php` endpoint accepts `sender` and `message`, stores the original text, and extracts basic fields such as citizen name, location, and stated need.
- **Relief and donation ledger:** Summarizes received, distributed, and remaining inventory quantities and displays recent recorded receipts. Administrators can add or delete inventory entries; other signed-in users can view the ledger. This prototype tracks inventory and displays receipts but does not provide a full distribution workflow.

### Data and presentation
- **MySQL demo data:** `database.sql` creates the users, household, evacuation-center, SOS, SMS, chat, inventory, and receipt tables and inserts example records.
- **Responsive command-center UI:** The pages use a dark dashboard layout styled with custom CSS and Tailwind CSS.
- **Live dashboard data:** The authenticated `api.php` endpoint supplies dashboard data, weather information, chat messages, and administrator report updates to the front end.

## Install in XAMPP
1. Copy this project folder into `C:\xampp\htdocs\` (for example, as `VetraDRR`).
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin:
   `http://localhost/phpmyadmin`
4. Import `database.sql`.
5. If your MySQL root account has a password, edit `config.php`.
6. Open the project folder in your browser, for example:
   `http://localhost/VetraDRR/`

## Demo login
- Admin: `admin@gmail.com` / `admin123`
- Civilian: `civilian@gmail.com` / `civil123`

These credentials are for local demonstration only. Change the admin password before deploying this prototype outside a trusted local environment.

## SMS integration
The endpoint is `sms_ingest.php` in the project root (for example, `http://localhost/VetraDRR/sms_ingest.php`).

POST:
- `sender`
- `message`

Example:
`SOS Maria Santos | Purok 1 | MEDICAL: insulin`

A GSM modem/Android SMS bridge can be configured to POST inbound messages to this endpoint. The prototype stores the raw message plus parsed fields in `sms_reports`.

## Maps
The dashboard previews the civilian's most recent SOS GPS location (or the current location when GPS is used) on a Leaflet map. The button below the preview opens Zoom Earth's live satellite, radar, wind and storm-track map centered on that location:
https://zoom.earth/places/philippines/

Zoom Earth blocks embedding on other sites, so it opens in a new tab from the dashboard. For real emergency deployment, connect verified official warning feeds and local LGU data.

## Important production upgrades
- Authenticate LGU/barangay roles.
- Use HTTPS and CSRF protection.
- Validate every coordinate and inbound SMS.
- Add rate limiting and audit logs.
- Integrate official PAGASA/NDRRMC/LGU feeds.
- Add actual GSM gateway credentials.
- Add offline service worker / local cache.
- Add encrypted household PII and strict retention rules.
- Add QR evacuation check-in.
- Add role-based public transparency views.
