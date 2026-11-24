#!/usr/bin/env python3
"""End-to-end checks of com_servicedeskplus in a real Joomla site against tests/EndToEnd/fake-sdp.php.

Needs (see tests/EndToEnd/README.md):
  - a Joomla 6 site at JOOMLA_URL with the component installed and Super User JOOMLA_ADMIN / JOOMLA_PASS
    whose e-mail is admin@example.com;
  - a second Super User "other" (e-mail other@example.com, same password) and a Registered user "plain";
  - the fake ServiceDesk Plus at SDP_URL, with its call log at FAKE_SDP_LOG.

Usage: python3 -I tests/EndToEnd/run.py     (exit code 0 = every check passed)
"""
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('JOOMLA_URL', 'http://127.0.0.1:8088')
SDP = os.environ.get('SDP_URL', 'http://127.0.0.1:8099')
SDP_LOG = os.environ.get('FAKE_SDP_LOG', '/tmp/fake-sdp.log')
ADMIN = os.environ.get('JOOMLA_ADMIN', 'admin')
PASSWORD = os.environ.get('JOOMLA_PASS', '')  # the test users' password; never a real account
KEY = 'ABCDEF01-2345-6789-ABCD-EF0123456789'
OPTION = 'index.php?option=com_servicedeskplus'

results = []


def check(name, ok, detail=''):
    results.append((name, bool(ok)))
    print(('PASS  ' if ok else 'FAIL  ') + name + ('' if ok else '   <- ' + str(detail)[:300]))


class Browser:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, url, data=None):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        try:
            r = self.opener.open(BASE + url, data=body, timeout=120)
            return r.status, r.read().decode('utf-8', 'replace'), r.geturl(), r.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), url, e.headers

    @staticmethod
    def token(html):
        m = re.search(r'name="([a-f0-9]{32})" value="1"', html)
        return m.group(1) if m else ''

    def login_admin(self, user, password=PASSWORD):
        _, html, _, _ = self.request('/administrator/index.php')
        return self.request('/administrator/index.php', {'username': user, 'passwd': password, 'option': 'com_login', 'task': 'login', self.token(html): '1'})

    def login_site(self, user, password=PASSWORD):
        _, html, _, _ = self.request('/index.php?option=com_users&view=login')
        return self.request('/index.php?option=com_users&task=user.login', {'username': user, 'password': password, self.token(html): '1'})


def php_problems(html):
    """PHP notices and untranslated language keys in a page."""
    found = re.findall(r'(?:<b>)?(?:Warning|Notice|Deprecated|Fatal error)(?:</b>)?:\s+[^<]{0,160}', html)
    found += re.findall(r'COM_SERVICEDESKPLUS_[A-Z0-9_]+', html)
    return sorted(set(found))


def text(html):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html))


def sdp_calls():
    if not os.path.isfile(SDP_LOG):
        return []
    with open(SDP_LOG, encoding='utf-8') as fh:
        return [json.loads(line) for line in fh if line.strip()]


def sdp_reset():
    urllib.request.urlopen(SDP + '/__reset', timeout=30).read()


def main():
    if PASSWORD == '':
        print('Set JOOMLA_PASS to the password of the test users (see tests/EndToEnd/README.md).')
        return 2

    sdp_reset()

    # Earlier runs leave audit rows (the hourly limits count them), mappings and cached results:
    # E2E_RESET_CMD is a shell command that empties the component's tables, e.g.
    #   psql ... -c 'TRUNCATE jos_sdp_audit, jos_sdp_user_map, jos_sdp_cache'
    if os.environ.get('E2E_RESET_CMD'):
        subprocess.run(os.environ['E2E_RESET_CMD'], shell=True, check=True, stdout=subprocess.DEVNULL)

    # --- Administrator: access control -------------------------------------------------------
    guest = Browser()
    status, html, url, _ = guest.request('/administrator/' + OPTION + '&view=integrationsettings')
    check('admin: a guest gets the login form, not the settings', 'mod-login-username' in html and 'jform[base_url]' not in html)

    status, html, _, _ = guest.request('/administrator/' + OPTION, {'task': 'settings.save', 'jform[base_url]': 'http://evil.example'})
    check('admin: a guest cannot save settings', 'mod-login-username' in html or status in (403, 303))

    admin = Browser()
    admin.login_admin(ADMIN)

    for view, title in [('integrationsettings', 'Integration Settings'), ('usermappings', 'User Mappings'), ('endpoints', 'Find Endpoints')]:
        status, html, _, _ = admin.request('/administrator/' + OPTION + '&view=' + view)
        check(f'admin: {view} loads without PHP notices or untranslated keys', status == 200 and title in html and not php_problems(html), php_problems(html) or status)

    status, _, _, _ = admin.request('/administrator/' + OPTION + '&view=nosuchview')
    check('admin: an unknown view is a 404', status == 404, status)

    status, html, _, _ = admin.request('/administrator/' + OPTION + '&task=settings.display')
    check('admin: the inherited display task is refused', status == 404, status)

    # --- Administrator: CSRF and saving the settings -----------------------------------------
    settings = {
        'jform[base_url]': SDP, 'jform[connect_via]': '', 'jform[service_key]': KEY, 'jform[verify_ssl]': '1', 'jform[ca_bundle]': '',
        'jform[ssl_pin]': '', 'jform[timeout]': '20', 'jform[connect_timeout]': '5', 'jform[request_statuses]': 'Open, Onhold, Resolved, Closed',
        'jform[approval_account_email]': 'api@example.com', 'jform[approval_modules][]': ['request'], 'jform[auto_approve_matches]': '1',
        'jform[cache_minutes]': '0', 'jform[parallel_requests]': '4', 'jform[rate_create_per_hour]': '3', 'jform[rate_decide_per_hour]': '30',
    }
    forged = dict(settings, task='settings.save')
    forged['jform[base_url]'] = 'http://forged.example.com'
    admin.request('/administrator/' + OPTION, forged)
    _, page, _, _ = admin.request('/administrator/' + OPTION + '&view=integrationsettings')
    check('admin: saving settings without the form token is refused', 'forged.example.com' not in page, 'base URL was stored')

    token = admin.token(page)
    status, html, _, _ = admin.request('/administrator/' + OPTION, dict(settings, task='settings.save', **{token: '1'}))
    _, page, _, _ = admin.request('/administrator/' + OPTION + '&view=integrationsettings')
    check('admin: settings are saved with the token', SDP in page and not php_problems(page), text(html)[:200])
    check('admin: the API key is never sent back to the form', KEY not in page)

    token = admin.token(page)
    status, html, _, _ = admin.request('/administrator/' + OPTION, {'task': 'settings.test', token: '1'})
    check('admin: Test connection reaches the fake ServiceDesk Plus', any(c['path'] == '/api/v3/requests' and c['key_ok'] for c in sdp_calls()), text(html)[:200])

    # Changing the address without the key must be refused (the stored key can't be redirected).
    moved = dict(settings, task='settings.save', **{token: '1'})
    moved['jform[base_url]'] = 'http://127.0.0.1:9'
    moved['jform[service_key]'] = ''
    admin.request('/administrator/' + OPTION, moved)
    # The form shows what was typed again, so the stored address is checked by using it.
    _, page, _, _ = admin.request('/administrator/' + OPTION + '&view=integrationsettings')
    token = admin.token(page)
    before = len(sdp_calls())
    admin.request('/administrator/' + OPTION, {'task': 'settings.test', token: '1'})
    check('admin: the address cannot be changed without entering the key again', len(sdp_calls()) > before)

    # --- Administrator: Find Endpoints --------------------------------------------------------
    _, page, _, _ = admin.request('/administrator/' + OPTION + '&view=endpoints')
    token = admin.token(page)
    status, html, _, _ = admin.request('/administrator/' + OPTION + '&view=endpoints', {'task': 'endpoints.run', 'email': 'admin@example.com', token: '1'})
    check('admin: Find Endpoints runs and shows the cards', 'Endpoints the portal uses' in html and '/api/v3/asset_loans' in html and not php_problems(html), php_problems(html) or status)
    check('admin: Find Endpoints sends only GET requests', all(c['method'] == 'GET' for c in sdp_calls()))
    check('admin: the report holds no names or e-mails', 'Ali Requester' not in html and 'SECRET-SALARY-DATA' not in html and 'other@example.com' not in html)

    status, body, _, headers = admin.request('/administrator/' + OPTION, {'task': 'endpoints.download', token: '1'})
    check('admin: Download report sends a text file', status == 200 and 'attachment' in (headers.get('Content-Disposition') or '') and body.startswith('ServiceDesk Plus portal diagnostics'), headers.get('Content-Disposition'))

    status, body, _, headers = admin.request('/administrator/' + OPTION, {'task': 'endpoints.download'})
    check('admin: Download report needs the form token', not body.startswith('ServiceDesk Plus portal diagnostics'))

    _, page, _, _ = admin.request('/administrator/' + OPTION + '&view=endpoints')
    status, html, _, _ = admin.request('/administrator/' + OPTION + '&view=endpoints', {'task': 'endpoints.check', 'path': '/api/v3/huge_list', 'rows': '10', admin.token(page): '1'})
    check('admin: an answer of 24 MB is cut off, the page still works', status == 200 and 'Find Endpoints' in html and not php_problems(html) and '/api/v3/huge_list:' not in html, php_problems(html) or status)

    status, html, _, _ = admin.request('/administrator/' + OPTION + '&view=endpoints', {'task': 'endpoints.check', 'path': 'https://evil.example.com/api/v3/requests', 'rows': '10', admin.token(page): '1'})
    check('admin: Check an Endpoint refuses addresses outside the API', 'must look like /api/v3/requests' in html, text(html)[:200])

    # A manager without "Configure" may not open the component at all (Dispatcher).
    plain_admin = Browser()
    plain_admin.login_admin('plain')
    status, html, _, _ = plain_admin.request('/administrator/' + OPTION + '&view=integrationsettings')
    check('admin: a user without Configure is refused', 'jform[base_url]' not in html)

    # --- Site: guests and users without permission ---------------------------------------------
    status, html, url, _ = guest.request('/' + OPTION + '&view=requests')
    check('site: a guest is sent to the login form', 'com_users' in url or 'login' in html.lower() and 'Laptop' not in html, url)

    plain = Browser()
    plain.login_site('plain')
    status, html, _, _ = plain.request('/' + OPTION + '&view=requests')
    check('site: a user without the permission gets 403', status == 403 and 'Laptop' not in html, status)

    # --- Site: the requester (Joomla "admin" = ServiceDesk Plus user 501) -----------------------
    ali = Browser()
    ali.login_site(ADMIN)

    status, html, _, _ = ali.request('/' + OPTION + '&view=requests')
    check('site: My Requests lists the user\'s own requests', status == 200 and 'Laptop' in html and not php_problems(html), php_problems(html) or status)
    check('site: My Requests never lists another user\'s requests', 'Private request of Mona' not in html and 'Budget approval of Mona' not in html)
    check('site: a subject with markup is escaped', 'Laptop &lt;b&gt;broken&lt;/b&gt;' in html and 'Laptop <b>broken</b>' not in html)

    status, html, _, _ = ali.request('/' + OPTION + '&view=request&id=100')
    check('site: the request page shows the description as text', status == 200 and 'dark' in html and '<script>alert(1)</script>' not in html and not php_problems(html), php_problems(html) or status)
    check('site: the conversation shows notes for the requester only', 'VISIBLE-NOTE' in html and 'INTERNAL-TECH-NOTE' not in html)

    status, html, _, _ = ali.request('/' + OPTION + '&view=request&id=300')
    check('site: another user\'s request is a 404 (no IDOR)', status == 404 and 'SECRET-SALARY-DATA' not in html, status)

    status, html, _, _ = ali.request('/' + OPTION + '&view=request&id=0100')
    check('site: an id with a leading zero is refused', status == 404, status)

    for view in ['dashboard', 'assets', 'announcements', 'approvals', 'requestform']:
        status, html, _, _ = ali.request('/' + OPTION + '&view=' + view)
        check(f'site: {view} loads without PHP notices or untranslated keys', status == 200 and not php_problems(html), php_problems(html) or status)

        if view == 'assets':
            check('site: My Loans shows only the user\'s own assets', 'LAPTOP-ALI' in html and 'LAPTOP-MONA' not in html)
        if view == 'announcements':
            check('site: announcements hide technician-only ones and escape markup', 'Maintenance on Friday' in html and 'TECHNICIANS-ONLY-NOTE' not in html and 'onerror=alert(2)>' not in html)
        if view == 'approvals':
            check('site: My Approvals shows the approval waiting for the user', 'Budget approval of Mona' in html and 'Private request of Mona' not in html)
        if view == 'dashboard':
            check('site: no style attributes on the dashboard (strict CSP)', ' style="' not in html.split('com-servicedeskplus', 1)[-1])

    status, html, _, _ = ali.request('/' + OPTION + '&view=assets&show=previous')
    check('site: returned loans show only the user\'s own', status == 200 and 'LAPTOP-MONA' not in html and not php_problems(html), php_problems(html) or status)

    # --- Site: approvals ------------------------------------------------------------------------
    detail = '/' + OPTION + '&view=approval&id=3&request_id=200&level_id=20'
    status, html, _, _ = ali.request(detail)
    check('site: the approver can open the approval', status == 200 and 'Budget approval of Mona' in html and not php_problems(html), php_problems(html) or status)

    status, other_html, _, _ = ali.request('/' + OPTION + '&view=approval&id=4&request_id=300&level_id=30')
    check('site: an approval of another approver is a 404 (no IDOR)', status == 404 and 'Private request of Mona' not in other_html, status)

    token = ali.token(html)
    submission = re.search(r'name="submission" value="([a-f0-9]{32})"', html)
    decide = {'task': 'approval.decide', 'request_id': '200', 'level_id': '20', 'approval_id': '3', 'decision': 'approve', 'comment': 'OK <b>fine</b> & "done"', 'submission': submission.group(1) if submission else ''}

    before = len(sdp_calls())
    ali.request('/' + OPTION, decide)
    check('site: a decision without the form token changes nothing', not any(c['method'] == 'PUT' for c in sdp_calls()[before:]))

    forged = dict(decide, request_id='300', level_id='30', approval_id='4', **{token: '1'})
    ali.request('/' + OPTION, forged)
    check('site: deciding another approver\'s approval sends nothing to ServiceDesk Plus', not any(c['method'] == 'PUT' for c in sdp_calls()[before:]))

    status, html, _, _ = ali.request(detail)
    token = ali.token(html)
    submission = re.search(r'name="submission" value="([a-f0-9]{32})"', html)
    decide['submission'] = submission.group(1) if submission else ''
    ali.request('/' + OPTION, dict(decide, **{token: '1'}))
    puts = [c for c in sdp_calls()[before:] if c['method'] == 'PUT']
    check('site: the approver\'s decision is sent once', len(puts) == 1 and puts[0]['path'].startswith('/api/v3/requests/200/approval_levels/20/approvals/3/'), [p['path'] for p in puts])
    check('site: the comment is sent as text', puts and '&lt;b&gt;fine&lt;/b&gt; &amp; &quot;done&quot;' in puts[0]['input'].get('approval', {}).get('comments', ''), puts[0]['input'] if puts else '')

    ali.request('/' + OPTION, dict(decide, **{token: '1'}))
    check('site: sending the same form again does not decide twice', len([c for c in sdp_calls()[before:] if c['method'] == 'PUT']) == 1)

    # --- Site: creating requests ------------------------------------------------------------------
    def form():
        _, page, _, _ = ali.request('/' + OPTION + '&view=requestform')
        sub = re.search(r'name="submission" value="([a-f0-9]{32})"', page)
        return ali.token(page), sub.group(1) if sub else ''

    token, submission = form()
    create = {'task': 'request.create', 'subject': 'Printer <script>x</script>', 'description': 'It is\nbroken <img src=x>', 'submission': submission}
    before = len(sdp_calls())
    ali.request('/' + OPTION, create)
    check('site: a request without the form token is not created', not any(c['method'] == 'POST' for c in sdp_calls()[before:]))

    ali.request('/' + OPTION, dict(create, **{token: '1'}))
    posts = [c for c in sdp_calls()[before:] if c['method'] == 'POST' and c['path'] == '/api/v3/requests']
    sent = posts[0]['input'].get('request', {}) if posts else {}
    check('site: a request is created for the logged-in user only', len(posts) == 1 and str(sent.get('requester', {}).get('id')) == '501', sent)
    check('site: the description is escaped before it is sent as HTML', '&lt;img src=x&gt;' in sent.get('description', '') and '<img' not in sent.get('description', ''), sent.get('description'))

    ali.request('/' + OPTION, dict(create, **{token: '1'}))
    check('site: the same form sent twice creates one request', len([c for c in sdp_calls()[before:] if c['method'] == 'POST' and c['path'] == '/api/v3/requests']) == 1)

    for _ in range(4):
        token, submission = form()
        ali.request('/' + OPTION, dict(create, submission=submission, **{token: '1'}))
    created = len([c for c in sdp_calls()[before:] if c['method'] == 'POST' and c['path'] == '/api/v3/requests'])
    check('site: the hourly limit on new requests holds (3 per hour here)', created == 3, created)

    # --- Site: the second user sees only their own records ------------------------------------------
    mona = Browser()
    mona.login_site('other')
    status, html, _, _ = mona.request('/' + OPTION + '&view=requests')
    check('site: the second user sees their own requests only', 'Private request of Mona' in html and 'Laptop' not in html, status)
    status, html, _, _ = mona.request('/' + OPTION + '&view=request&id=100')
    check('site: the second user cannot open the first user\'s request', status == 404, status)

    # --- Every call carried the key; nothing was written except what the tests asked for --------------
    calls = sdp_calls()
    check('ServiceDesk Plus: every call carried the API key', all(c['key_ok'] for c in calls))
    writes = sorted({c['method'] + ' ' + re.sub(r'/[0-9]+', '/{id}', c['path']) for c in calls if c['method'] != 'GET'})
    check('ServiceDesk Plus: only the expected writes were sent', all(w.startswith(('PUT /api/v3/requests/{id}/approval_levels/{id}/approvals/{id}/', 'POST /api/v3/requests')) for w in writes), writes)

    failed = [name for name, ok in results if not ok]
    print(f'\n{len(results) - len(failed)} of {len(results)} checks passed')
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
