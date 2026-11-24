# End-to-end tests

These tests run the component in a real Joomla 6 site against a small stand-in for ServiceDesk Plus (`fake-sdp.php`). They check what the unit tests cannot: installation, pages, permissions, form tokens and what is really sent to ServiceDesk Plus.

They are not part of the release workflow, because they need a Joomla site and a database. Run them before a release that changes controllers, templates, SQL or the install script.

## What you need

- A Joomla 6 test site with the component installed. Never use a live site: the tests save settings and create users' data.
- In *Global Configuration*: **Search Engine Friendly URLs off**. For PHP's built-in web server, also set `$live_site` in `configuration.php` to the site address; without it Joomla doubles the path in redirects.
- Three users with the same password:

  | Username | E-mail | Group |
  |---|---|---|
  | `admin` | `admin@example.com` | Super Users |
  | `other` | `other@example.com` | Super Users |
  | `plain` | `plain@example.com` | Manager |

## Run

Start the fake ServiceDesk Plus:

```bash
FAKE_SDP_LOG=/tmp/fake-sdp.log php -S 127.0.0.1:8099 tests/EndToEnd/fake-sdp.php
```

Run the checks (in another terminal):

```bash
JOOMLA_URL=http://127.0.0.1:8088 JOOMLA_PASS='your test password' FAKE_SDP_LOG=/tmp/fake-sdp.log python3 -I tests/EndToEnd/run.py
```

The exit code is 0 when every check passes.

Earlier runs leave audit rows, which the hourly limits count. To run again within the hour, give the script a command that empties the component's tables in `E2E_RESET_CMD`, for example:

```bash
export E2E_RESET_CMD="psql joomla -qc 'TRUNCATE jos_sdp_audit, jos_sdp_user_map, jos_sdp_cache'"
```

## What is checked

- **Administrator:**
  - guests and users without *Configure* are refused;
  - every task needs the form token;
  - the API key is never sent back to the form;
  - the address cannot be changed without the key;
  - Find Endpoints sends only GET requests, and its report holds no personal values;
  - an oversized answer is cut off.
- **Site:**
  - guests are sent to the login form, and users without the permission get 403;
  - every user sees only their own requests, loans and approvals, also when ids in the address are changed;
  - text from ServiceDesk Plus is escaped;
  - internal notes and technician-only announcements are hidden.
- **Approvals and new requests:**
  - nothing is sent without the form token;
  - another approver's approval cannot be decided;
  - a form sent twice acts once;
  - the hourly limit holds;
  - comments and descriptions are sent as text.
- **ServiceDesk Plus:** every call carries the API key, and only the expected writes are sent.
