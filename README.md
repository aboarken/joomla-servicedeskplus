# Joomla ServiceDesk Plus (com_servicedeskplus)

**Bring ManageEngine ServiceDesk Plus directly into your Joomla website.**

`com_servicedeskplus` is a Joomla component designed to integrate [ManageEngine ServiceDesk Plus](https://www.manageengine.com/products/service-desk/) with Joomla, providing users with a centralized IT Service Management (ITSM) portal through their existing Joomla accounts.

The component connects to the ServiceDesk Plus REST API, allowing users to access IT services, manage support requests, review approvals, and view assigned assets without needing to access the ServiceDesk Plus interface directly.

## Overview

The goal of this project is to provide a seamless self-service experience for employees and end users while keeping ManageEngine ServiceDesk Plus as the central system for IT service management.

By integrating Joomla with ServiceDesk Plus, organizations can offer a unified portal for IT support, service requests, asset visibility, and other IT-related services.

## Features

### Dashboard
- **Employee Information:** a panel with the user's department, job title, employee ID and name (each label above its value), from their own ServiceDesk Plus record; empty fields are left out, and the Joomla name is used when ServiceDesk Plus has none.
- **Summary cards:** open requests, on-hold requests, waiting for my approval, loans due within 30 days.
- **Charts** of the user's requests, in the same grid as the My Requests, My Approvals and My Loans cards below them, two cards per row (one per row on small screens), made from the requests already read for the figures (no extra ServiceDesk Plus calls), each with a legend or labels and a tooltip on hover (screen readers get the figures as a table):
  - *Open Requests by Category:* a donut of the user's open and on-hold requests over every category (most requests first, then *No category*), with the total in the middle and a legend of names, counts and shares; neighbouring slices always have different colours.
  - *Open Requests by Age:* a donut of open and on-hold requests created under 1 week, 1–4 weeks, 1–3 months and over 3 months ago, from light gold (newest) to deep red (oldest), with the total in the middle.
  - *Open Requests by Priority:* a bar graph, one bar per priority with its count; a priority always keeps its place (Low, Normal, Medium, High, Urgent, Critical, others alphabetically) and its colour.
- **My Requests block:** Open, On Hold, Resolved and Closed tiles, each with its incident and service request count. Resolved and closed requests are counted by ServiceDesk Plus without reading them.
- **My Requests, My Approvals, My Loans** cards, after the charts in the same two-per-row grid:
  - *My Requests:* open and on-hold service requests and incidents with totals. Only open and on-hold requests are read from ServiceDesk Plus (up to 500 of each), so the Dashboard stays fast for users with many closed requests.
  - *My Approvals:* waiting approvals by type, approved by me and rejected by me (decisions made through the portal).
  - *My Loans:* on loan, due within 30 days (including overdue), overdue, returned.
- Coloured cards, panels and counts (green for good, amber and red for what needs attention), styled by the component so the Dashboard looks the same on any site template. All other portal pages use the same design.
- List filters apply as soon as an option is chosen; the search is applied with Enter (no Search button).
- Every figure opens its page with the matching filter. Each part is shown only to users with the permission for its page.

### Service Requests
- View and track submitted service requests (**My Requests**), filtered to **Open** requests by default, with search, *Request type* filter (incident or service), status filter and sorting.
- Submit new service requests with the **Request Form** menu item (page title **New Request**).
- Create requests using only a subject and description, with optional attachments.
- View request details: status, priority, urgency, technician, technician group, category, subcategory, item, dates, approval status, description, resolution, the **approvals** of the request when it has any (level, approver, status, sent and decision dates, comments) and the **conversation** (e-mails and notes visible to the requester).

### Attachments
- Allowed types: JPG, PNG, PDF, Excel (.xlsx) and Word (.docx); the real file content is checked, not just the extension.
- At most **3 files** per request, **1 MB per file** (3 MB in total).
- The limits are enforced on the server; the form only checks early for convenience.

### IT Support
- Access the user's support requests.
- View request progress and updates.
- Review available request actions.
- Access relevant IT service information.

### Approvals
- View pending approvals for **requests, changes, releases and purchase orders** in one list (ServiceDesk Plus has no approvals for problems), and the decisions made through the portal. The list works like My Requests: search, *Show* (waiting for my approval, approved by me, rejected by me, all my decisions), type, sort and pages.
- Review approval details, including the **attachments** of the request, change or release (images and PDF open in a popup, other files are downloaded; the portal checks in ServiceDesk Plus that the user is an approver of that record first). For a purchase order the page also shows its details (vendor, requested by, dates, items with quantities and prices, totals) and its attachments.
- Approve or reject from Joomla, for example while away from the company network.

ServiceDesk Plus only accepts an approval decision from the assigned approver, while the portal talks to ServiceDesk Plus with a single API technician key. To let approvers decide from Joomla anyway, enter the API technician's e-mail under **Integration Settings → Approvals → API technician e-mail**. When ServiceDesk Plus refuses a decision, the portal adds the API technician as approver on the same level, records the decision under that account with the real approver's name in the comment and in a private request note, and removes the approver's pending entry. In ServiceDesk Plus the decision therefore appears under the API technician. Approval levels that need all approvers still wait for the others. The API technician's role needs permission to edit and manage approvals in each module used (requests, changes, releases, purchase orders). Turn off both "Auto-approve…" options under ServiceDesk Plus **Admin → Advanced Portal Settings → Approval**: otherwise ServiceDesk Plus can approve the API technician's added entry by itself, even when the user rejected. Closed records cannot be decided from the portal, and purchase requests have no API.

### Announcements
- The **Announcements** menu item lists ServiceDesk Plus announcements in a table like My Requests: ID, subject, type, priority, start date and end date (the announcement's page also shows who posted it). Click the ID or subject to open an announcement. Search (ID, subject or text), filter by type, sort (newest, oldest, ending soonest, subject) and pages of 25.
- Users need the **View announcements** permission (*System → Global Configuration → ServiceDesk Plus → Permissions*, or the component's *Options*). It is not granted by default.
- Only announcements inside their display period (from / to date) appear.
- **Technicians** (users whose ServiceDesk Plus record is a technician) see all of them, including internal announcements for the team.
- **Requesters** see public announcements; announcements limited to user groups only when their ServiceDesk Plus record lists one of those groups. Internal (technician-only) announcements are hidden from requesters.
- Each time the list is loaded, a line in `administrator/logs/com_servicedeskplus.php` tells how many announcements were read, shown and hidden (and why).

### Assets & Equipment
- View assets associated with the user.
- Access assigned equipment information.
- View equipment and loan records in read-only mode.
- *My Loans* works like My Requests: search, *Show* (on loan, due within 30 days, overdue, returned), with a card per choice and its total above the list, sort and pages. *Returned* lists the user's returned loans from ServiceDesk Plus's loan history (`/api/v3/asset_loans`): one row per asset with its name, type, model and serial number, then loan start, due date, return date (*Late* when after the due date) and comments.
- A loan's page shows type, product, serial number, state, purchase date, warranty expiry date, department, the user it is loaned to, loan start and due date.

### Site templates
The portal pages follow the installed site template; they are not tied to Cassiopeia. They are styled by the component's own `sdp-*` classes in `media/com_servicedeskplus/css/portal.css`, so they look the same on any template; the few Bootstrap class names still used (headings, spinner, screen-reader text) get zero-specificity fallbacks, so the template's own styles always win. Page layouts (`tmpl`) and the shared layouts (`layouts/pagination.php`, `layouts/conversation.php`) can be overridden in the template (*System → Site Templates → Create Overrides*; layouts under `html/layouts/com_servicedeskplus`).

**Note:** Equipment and loan information is provided for viewing only. Submitting loan requests, requesting loan extensions, and requesting equipment returns are not part of this component.

## Integration

The component integrates directly with the ManageEngine ServiceDesk Plus REST API.

**Integration workflow:**

1. A user signs in to the Joomla website.
2. The component identifies the corresponding ServiceDesk Plus requester using the user's email address.
3. The component communicates with ServiceDesk Plus through its REST API.
4. Relevant information is retrieved and displayed in the Joomla portal.
5. User-submitted requests and supported actions are processed through ServiceDesk Plus.

Joomla acts as the user-facing portal, while ServiceDesk Plus remains the primary system responsible for IT service management and request processing.

## Requirements

| Requirement | Version / Details |
|---|---|
| Joomla | 6.0 or later |
| PHP | 8.3 or later, with the OpenSSL (AES-256-GCM) and cURL extensions |
| ManageEngine ServiceDesk Plus | REST API access |
| ServiceDesk Plus deployment | On-Premises |
| Database | Supported Joomla database engine |

## Installation

1. Download `com_servicedeskplus.zip` from the latest release on the [Releases page](https://github.com/aboarken/joomla-servicedeskplus/releases).
2. Log in to your Joomla Administrator.
3. Navigate to **System → Install → Extensions**.
4. Upload the component installation package.
5. Complete the installation.
6. Open the ServiceDesk Plus component from the Joomla Administrator.

### Updating

From version 1.7.0, Joomla finds new versions by itself: open **System → Update → Extensions** (or **System → Update Sites** to check that "ServiceDesk Plus" is enabled), then select the component and choose **Update**. Settings and user mappings are kept.

Sites on 1.6.0 or older have no update server yet: install 1.7.0 once by uploading `com_servicedeskplus.zip`, and later updates arrive through Joomla.

You can always upgrade by installing a newer package over the existing one.

### Menu items

Create front-end pages with **Menus → Add New Menu Item → ServiceDesk Plus**: *Dashboard*, *My Requests*, *Request Form*, *My Loans*, *My Approvals* and *Announcements*. The menu item title is shown as the page heading and browser title; the menu item's *Page Display* settings (*Browser Page Title*, *Show Page Heading*, *Page Heading*) are respected.

## Configuration

After installation, configure the integration using the component's administration interface.

The configuration includes:

- ServiceDesk Plus server URL.
- REST API authentication credentials.
- API connectivity settings.
- Joomla user-to-ServiceDesk Plus requester mapping.
- Component access permissions.

### Cache

The Dashboard and the lists (My Requests, My Approvals, My Loans, Announcements) open at once: when a user's results are older than the cache time, the page shows their last results (up to 7 days old, marked *Updating…*) and refreshes itself in the background. Pages of one record (a request, a loan, an approval, an announcement) always read ServiceDesk Plus again after the cache time, so a record that is no longer the user's is never shown from an older copy. A user's cached results are deleted when their mapping is suspended, changed or matched again, and all cached results are deleted when the ServiceDesk Plus address changes; at most 150 entries are kept per user. Results are stored per user in the database table `#__sdp_cache`, so they survive logout; they are only shown to the same user and deleted after 7 days. *Integration Settings → Limits → Cache time (minutes)* sets how long each user's ServiceDesk Plus results (requests, approvals, loans, announcements and the Dashboard figures) are kept, 0 to 60 minutes; default 5. A user's own new requests and approval decisions refresh their lists at once. With 0, every page reads ServiceDesk Plus again; the Dashboard then makes many calls (approvals are found by scanning requests, changes, releases and purchase orders) and loads slowly.

### Speed

Pages read ServiceDesk Plus when their cached results have expired (see *Cache*). Within one page load the connection to ServiceDesk Plus is opened once and reused, and where many records are read (the approval check of My Approvals and the Dashboard) up to *Integration Settings → Limits → Parallel API calls* reads are sent at once (default 6). The log `administrator/logs/com_servicedeskplus.php` shows the time of every call (DNS, connect, TLS, server) and one summary line per page load; *Find Endpoints → Run Diagnostics* has a *Timing* section. Unticking approval modules you do not use (*Integration Settings → Approvals*) also saves calls.

### User Mapping

The component uses email addresses to associate Joomla users with their corresponding ServiceDesk Plus requesters.

For successful integration, the Joomla user's email address must match the email address registered in ServiceDesk Plus. The first time a user opens the portal, the ServiceDesk Plus user with that address is found and the user is matched at once. *Integration Settings → Users → New users* chooses:

- **Approve automatically** (default): first-visit matches work at once.
- **Wait for administrator approval:** the match is stored as **Waiting for approval**; *User Mappings* shows how many users wait, with a link to them. Check each e-mail, then select the users and click **Confirm**. Choose this where users can change their Joomla e-mail freely: someone could enter a colleague's address before their first visit.

Either way, a user whose Joomla e-mail changes later is suspended until an administrator approves it. Users still waiting when you switch to *Approve automatically* are matched on their next visit, after a fresh check that ServiceDesk Plus still has their address.

### API Connectivity

The Joomla server must be able to communicate with the ServiceDesk Plus server through the REST API.

For on-premises deployments, ensure that the required network connectivity, firewall rules, and API permissions are configured.

### Find Endpoints

*Components → ServiceDesk Plus → Find Endpoints* (Super User only) helps when a page shows nothing or an error. **Find Endpoints** (on the page or in the toolbar) runs every check at once and shows: warnings (http address, certificate check off, no API technician e-mail, automatic matching of new users), a **Server** card (address, certificate, connect via, timeouts, response time), an **API key** card (key accepted, requests the API technician sees, approval settings, how new users are matched), the **endpoints the portal uses** with their result (Allowed, Refused, Not on this build) and time, and, with a requester's e-mail, whether that person can be matched and how many requests, assets and loans they have. The same result is a text report to copy and send to support. Under **Advanced**, the other known REST API v3 endpoints show whether they answer, and **Check an Endpoint** shows the fields any `/api/v3` path returns. Only field paths, kinds of values, counts and status texts are shown, never personal values; only reading requests are sent, at most 60 runs and checks per hour, each recorded in the audit log.

## Notifications

ServiceDesk Plus remains responsible for request-related notifications.

The component does not generate separate Joomla email notifications for submitted requests. Notification management remains centralized within ManageEngine ServiceDesk Plus.

## Security & Permissions

The component is designed to work with Joomla's access control system (ACL).

Administrators can manage access permissions for the component and its available functionality (*Components → ServiceDesk Plus → Options → Permissions*). The portal's own permissions (view requests, create requests, view and decide approvals, view loans, view announcements) are **not granted to any group after installation**, except Super Users: grant them to the user groups that should use each page.

To report a security problem, see [SECURITY.md](SECURITY.md) (privately, through the repository's **Security** tab; never in a public issue). The release workflow's actions are pinned to exact commits and kept up to date by Dependabot (`.github/dependabot.yml`); changes to the workflow, the build scripts, the update server files and the manifest are reviewed by the code owner (`.github/CODEOWNERS`).

API credentials should be stored securely and should never be exposed to frontend users or committed to the source repository.

- The API key is stored encrypted and never shown again. It must be entered again whenever the ServiceDesk Plus address, *Connect via*, the certificate pin or CA file changes, or the certificate check is turned off, so the stored key cannot be sent to another server.
- Users are found in ServiceDesk Plus by their Joomla e-mail. By default a first-visit match works at once; with *Integration Settings → Users → New users: Wait for administrator approval* **every new match waits for an administrator** (Joomla lets users change their own e-mail without verifying it, so an address alone does not prove who someone is): the user sees "…waiting for an administrator to approve it" until an administrator confirms the mapping under *User Mappings* (filter *Waiting for approval*, select, **Confirm**; several at once). **A user whose Joomla e-mail changes is always suspended** until an administrator checks it. If several ServiceDesk Plus users share an e-mail, an administrator chooses. Mappings an administrator makes with *New* are active at once. The API technician (*Integration Settings → Approvals → API technician e-mail*) is never linked to a portal user, by e-mail or by an administrator.
- Matching trusts the e-mail of each Joomla account. If your site allows self-registration, keep *Users → Options → New User Account Activation* on **Self** or **Administrator**, so nobody can register with another person's address.
- Every page and action checks login, the feature's permission and the user's own ServiceDesk Plus records; actions need a POST with Joomla's form token.

## Project Structure

The repository follows the Joomla component architecture.

```text
com_servicedeskplus/
├── administrator/
│   ├── forms/
│   ├── language/
│   ├── services/
│   ├── sql/
│   ├── src/
│   ├── tmpl/
│   ├── access.xml
│   └── config.xml
├── media/
│   ├── css/
│   ├── js/
│   └── joomla.asset.json
├── site/
│   ├── language/
│   ├── layouts/
│   ├── src/
│   └── tmpl/
├── .github/
│   ├── CODEOWNERS
│   ├── dependabot.yml
│   └── workflows/release.yml
├── build/
│   ├── build-package.sh
│   ├── make-changelog-xml.py
│   ├── make-update-xml.py
│   └── verify-package.py
├── docs/release-notes/
├── CHANGELOG.md
├── LICENSE
├── README.md
├── SECURITY.md
├── script.php
└── servicedeskplus.xml
```

## Development

This project is developed as a Joomla component using Joomla's component architecture and PHP.

Contributions, suggestions, bug reports, and feature requests are welcome.

To contribute:

1. Fork the repository.
2. Create a feature branch.
3. Implement your changes.
4. Test your changes in a Joomla development environment.
5. Submit a pull request.

### Building the installation package

```bash
build/build-package.sh
```

This writes `dist/com_servicedeskplus-<version>.zip`, taking the version from `servicedeskplus.xml`. Check a package with `build/verify-package.py <zip> [version]`.

### Releases

Versions follow [Semantic Versioning](https://semver.org/) and are set in `servicedeskplus.xml`. Each version has an update file in `administrator/sql/updates/mysql/` and `administrator/sql/updates/postgresql/` (a comment-only file when the schema does not change), release notes in `docs/release-notes/<version>.md` and an entry in [CHANGELOG.md](CHANGELOG.md).

The **Release package** GitHub Actions workflow (`.github/workflows/release.yml`) builds and checks the package for every pull request (with read access only) and, in a separate job that runs no build script, attaches **`com_servicedeskplus.zip`** and the Joomla update file **`updates.xml`** (made by `build/make-update-xml.py`) to each release. Joomla sites read `releases/latest/download/updates.xml`, so publishing a release is all it takes to offer an update:

- **From the Actions tab:** run *Release package* on `main` (other branches only build). It creates the release `v<version>` with the release notes and the package. If that release already exists the run stops, unless *Replace the files of an existing release* is ticked.
- **From the Releases page:** publish a release tagged `v<version>` (it must match the manifest version); the workflow then attaches the package.

The latest package can always be downloaded from `https://github.com/aboarken/joomla-servicedeskplus/releases/latest/download/com_servicedeskplus.zip`.

## Roadmap

The project aims to provide an increasingly complete self-service portal for ManageEngine ServiceDesk Plus.

Future improvements may include:

- Expanded request management capabilities.
- Improved dashboard and reporting experience.
- Enhanced approval workflows.
- Additional asset management capabilities.
- Improved API integration and error handling.
- Continued compatibility with future Joomla releases.

## License

This project is licensed under the GNU General Public License version 2 or later (GPL-2.0-or-later). See the [LICENSE](LICENSE) file for details.

## Links

- **GitHub Repository:** https://github.com/aboarken/joomla-servicedeskplus
- **ManageEngine ServiceDesk Plus:** https://www.manageengine.com/products/service-desk/
- **Joomla:** https://www.joomla.org/
- **ServiceDesk Plus On-Premises API Documentation:** https://servicedeskplus.docs.buildwithfern.com/servicedesk-plus-on-premise

---

**Developed for organizations that want to make IT services more accessible through Joomla and ManageEngine ServiceDesk Plus.**
