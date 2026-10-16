#!/usr/bin/env node
// Demo pre-flight: exercises everything a live demo depends on, end to end,
// and cleans up after itself. Run it before going on stage:
//
//   cd <site root> && node <path to>/preflight.js
//
// Needs DDEV up, a public URL that reaches the site (a tunnel or similar),
// Ollama running, and Playwright with a Chromium (set PLAYWRIGHT_PATH to the
// playwright package directory if it is not resolvable from here).
//
// Site-specific values (publicUrl, localUrl) are not in this file. Put them
// in <site root>/demo/preflight.local.json or the PREFLIGHT_PUBLIC_URL and
// PREFLIGHT_LOCAL_URL environment variables. The questions and expected
// answers are in preflight.config.json beside this file; the local file may
// override them too. AIM_SITE_ROOT overrides the site root (default: the
// current directory).
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const path = require('path');

const root = path.resolve(process.env.AIM_SITE_ROOT || process.cwd());
const readJson = (file) => (fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {});
const cfg = Object.assign({
  chatQuestion: 'Who founded NeuralPulse Systems?',
  chatExpect: 'Thorne',
  mcpQuestion: 'Who founded NeuralPulse Systems?',
  mcpExpect: 'Thorne',
  offTopic: 'quantum chromodynamics lattice gauge theory',
}, readJson(path.join(__dirname, 'preflight.config.json')),
readJson(path.join(root, 'demo', 'preflight.local.json')),
process.env.PREFLIGHT_PUBLIC_URL ? { publicUrl: process.env.PREFLIGHT_PUBLIC_URL } : {},
process.env.PREFLIGHT_LOCAL_URL ? { localUrl: process.env.PREFLIGHT_LOCAL_URL } : {});
if (!cfg.publicUrl || !cfg.localUrl) {
  console.error('Set publicUrl and localUrl in demo/preflight.local.json (or PREFLIGHT_PUBLIC_URL / PREFLIGHT_LOCAL_URL).');
  process.exit(2);
}
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');

const CANARY = 'ZZPREFLIGHT';
const CALLBACK = 'http://127.0.0.1:8765/cb';
const results = [];
const b64 = (buf) => buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const drush = (...args) => execFileSync('ddev', ['drush', ...args], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
const php = (code) => drush('php:eval', code);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function check(name, fn) {
  const t = Date.now();
  try {
    const detail = await fn();
    results.push({ name, ok: true });
    console.log(`PASS  ${name} (${((Date.now() - t) / 1000).toFixed(1)}s)${detail ? ' - ' + detail : ''}`);
  }
  catch (e) {
    results.push({ name, ok: false });
    console.log(`FAIL  ${name} (${((Date.now() - t) / 1000).toFixed(1)}s) - ${String(e.message || e).split('\n')[0].slice(0, 220)}`);
  }
}

async function getStatus(url) {
  const r = await fetch(url, { redirect: 'follow', signal: AbortSignal.timeout(20000) });
  return r.status;
}

// One MCP session over the public URL, JSON-RPC over Streamable HTTP.
function mcpClient(token) {
  let sid = null; let id = 0;
  return async function rpc(method, params, notify) {
    const headers = { 'content-type': 'application/json', accept: 'application/json, text/event-stream', authorization: 'Bearer ' + token, 'MCP-Protocol-Version': '2025-06-18' };
    if (sid) headers['Mcp-Session-Id'] = sid;
    const body = { jsonrpc: '2.0', method, params };
    if (!notify) body.id = ++id;
    const r = await fetch(cfg.publicUrl + '/mcp', { method: 'POST', headers, body: JSON.stringify(body), signal: AbortSignal.timeout(60000) });
    if (r.headers.get('mcp-session-id')) sid = r.headers.get('mcp-session-id');
    const text = await r.text();
    if (notify) return {};
    if ((r.headers.get('content-type') || '').includes('event-stream')) {
      const last = text.split('\n').filter((l) => l.startsWith('data:')).map((l) => l.slice(5).trim()).pop();
      return last ? JSON.parse(last) : { raw: text };
    }
    try { return JSON.parse(text); } catch { throw new Error(`HTTP ${r.status}: ${text.slice(0, 160)}`); }
  };
}

const toolText = (j) => {
  if (j.error) throw new Error('MCP error: ' + JSON.stringify(j.error).slice(0, 200));
  if (j.result && j.result.isError) throw new Error('tool error: ' + JSON.stringify(j.result.content).slice(0, 200));
  return ((j.result && j.result.content) || []).map((c) => c.text).join('\n');
};

async function oauthToken(browser) {
  const reg = await (await fetch(cfg.publicUrl + '/oauth/register', {
    method: 'POST', headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ client_name: 'preflight', redirect_uris: [CALLBACK], grant_types: ['authorization_code', 'refresh_token'], response_types: ['code'], token_endpoint_auth_method: 'client_secret_post', scope: 'aim:recall aim:remember' }),
  })).json();
  if (!reg.client_id) throw new Error('client registration failed: ' + JSON.stringify(reg).slice(0, 160));
  const verifier = b64(crypto.randomBytes(32));
  const challenge = b64(crypto.createHash('sha256').update(verifier).digest());
  let redirected = null;
  const srv = http.createServer((req, res) => { redirected = 'http://127.0.0.1:8765' + req.url; res.end('ok'); }).listen(8765, '127.0.0.1');
  const ctx = await browser.newContext();
  try {
    const page = await ctx.newPage();
    const link = drush('uli', '--uri=' + cfg.publicUrl, '--no-browser').split('\n').pop();
    await page.goto(link);
    const login = page.locator('input#edit-submit');
    if (await login.count()) { await login.first().click(); await page.waitForLoadState('networkidle'); }
    await page.goto(cfg.publicUrl + '/oauth/authorize?' + new URLSearchParams({
      response_type: 'code', client_id: reg.client_id, redirect_uri: CALLBACK, scope: 'aim:recall aim:remember', state: 'pf',
      code_challenge: challenge, code_challenge_method: 'S256', resource: cfg.publicUrl + '/',
    }).toString()).catch(() => {});
    const allow = page.getByRole('button', { name: 'Allow' });
    if (await allow.count()) { await allow.click(); }
    for (let i = 0; i < 20 && !redirected; i++) await sleep(250);
  }
  finally { await ctx.close(); srv.close(); }
  if (!redirected) throw new Error('no OAuth redirect captured (consent screen missing?)');
  const code = new URL(redirected).searchParams.get('code');
  if (!code) throw new Error('no authorization code in redirect: ' + redirected.slice(0, 120));
  const tok = await (await fetch(cfg.publicUrl + '/oauth/token', {
    method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ grant_type: 'authorization_code', code, redirect_uri: CALLBACK, client_id: reg.client_id, client_secret: reg.client_secret, code_verifier: verifier }),
  })).json();
  if (!tok.access_token) throw new Error('token exchange failed: ' + JSON.stringify(tok).slice(0, 160));
  return tok.access_token;
}

async function askWidget(page, question) {
  const chat = page.locator('deep-chat').first();
  const input = chat.locator('#text-input');
  await input.click();
  await page.keyboard.type(question);
  await page.keyboard.press('Enter');
  const t = Date.now();
  await page.waitForFunction(() => {
    const dc = document.querySelector('deep-chat');
    const b = dc && dc.shadowRoot && [...dc.shadowRoot.querySelectorAll('.message-bubble')];
    if (!b || b.length < 3) return false;
    const txt = b[b.length - 1].innerText.trim();
    return txt && !/^thinking/i.test(txt);
  }, null, { timeout: 90000 });
  await sleep(600);
  const bubbles = await chat.locator('.message-bubble').allInnerTexts();
  return { text: bubbles[bubbles.length - 1].replace(/\s+/g, ' ').trim(), secs: (Date.now() - t) / 1000 };
}

(async () => {
  // Facts created during this run get an ID above this, however the
  // assistant words them, so cleanup does not depend on matching text.
  const startMax = Number(drush('sql:query', 'SELECT COALESCE(MAX(id), 0) FROM aim_fact'));
  const startCount = Number(drush('sql:query', 'SELECT COUNT(*) FROM aim_fact'));
  const browser = await chromium.launch();
  try {
    await check('site up (local)', async () => {
      const s = execFileSync('curl', ['-sk', '-m', '20', '-o', '/dev/null', '-w', '%{http_code}', cfg.localUrl + '/'], { encoding: 'utf8' });
      if (s !== '200') throw new Error('HTTP ' + s);
    });
    await check('site up (public funnel URL)', async () => {
      const s = await getStatus(cfg.publicUrl + '/');
      if (s !== 200) throw new Error('HTTP ' + s);
      return cfg.publicUrl;
    });
    await check('Ollama embeddings model present', async () => {
      const j = await (await fetch('http://localhost:11434/api/tags', { signal: AbortSignal.timeout(5000) })).json();
      if (!(j.models || []).some((m) => /nomic-embed-text/.test(m.name))) throw new Error('nomic-embed-text not found');
    });
    await check('vector index fully indexed, no orphans', async () => {
      const out = drush('sql:query', "SELECT (SELECT COUNT(*) FROM aim_fact WHERE retired IS NULL AND text IS NOT NULL), (SELECT COUNT(*) FROM aim_fact_vectors), (SELECT COUNT(*) FROM aim_fact_vectors v LEFT JOIN aim_fact f ON v.drupal_entity_id = CONCAT('entity:aim_fact/', f.id, ':en') WHERE f.id IS NULL), (SELECT COUNT(*) FROM aim_fact WHERE source LIKE 'zz%' OR text LIKE '%ZZ%')");
      const [live, rows, orphans, tests] = out.split(/\s+/).map(Number);
      if (live !== rows) throw new Error(`${live} live facts but ${rows} vector rows (run: ddev drush sapi-i aim_vector_index)`);
      if (orphans) throw new Error(orphans + ' orphan vector rows');
      if (tests) throw new Error(tests + ' leftover test facts (ZZ...)');
      return `${live} live facts`;
    });

    let rpc = null;
    await check('OAuth: register, consent, PKCE token', async () => {
      const token = await oauthToken(browser);
      rpc = mcpClient(token);
      const init = await rpc('initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'preflight', version: '1' } });
      if (!init.result) throw new Error('initialize failed: ' + JSON.stringify(init).slice(0, 160));
      await rpc('notifications/initialized', {}, true);
    });
    if (rpc) {
      await check('MCP: both tools listed', async () => {
        const names = ((await rpc('tools/list', {})).result.tools || []).map((t) => t.name);
        for (const n of ['tool_api__aim_recall', 'tool_api__aim_remember']) if (!names.includes(n)) throw new Error(n + ' missing; have ' + names.join(', '));
        return names.join(', ');
      });
      await check('MCP: recall answers an on-topic question', async () => {
        const t = toolText(await rpc('tools/call', { name: 'tool_api__aim_recall', arguments: { text: cfg.mcpQuestion, scope: 'site', limit: 3 } }));
        if (!new RegExp(cfg.mcpExpect, 'i').test(t)) throw new Error('expected /' + cfg.mcpExpect + '/ in: ' + t.replace(/\s+/g, ' ').slice(0, 160));
      });
      await check('MCP: role, user and case scopes recall', async () => {
        const ask = async (args, expect) => {
          const t = toolText(await rpc('tools/call', { name: 'tool_api__aim_recall', arguments: { limit: 3, ...args } }));
          if (!expect.test(t)) throw new Error(`${args.scope}: expected ${expect} in: ` + t.replace(/\s+/g, ' ').slice(0, 140));
        };
        await ask({ text: 'What must editors do before publishing an image?', scope: 'role', subject: 'content_editor' }, /alt text/i);
        await ask({ text: 'How does Sam like answers to be formatted?', scope: 'user' }, /bullet/i);
        await ask({ text: 'When does the events calendar launch?', scope: 'case' }, /20 October/i);
      });
      await check('MCP: recall abstains on an off-topic question', async () => {
        const t = toolText(await rpc('tools/call', { name: 'tool_api__aim_recall', arguments: { text: cfg.offTopic, scope: 'site', limit: 3 } }));
        if (!/No relevant facts/i.test(t)) throw new Error('returned facts for an off-topic query: ' + t.replace(/\s+/g, ' ').slice(0, 160));
      });
      await check('MCP: remember, then searchable', async () => {
        toolText(await rpc('tools/call', { name: 'tool_api__aim_remember', arguments: { text: `${CANARY} the pre-flight canary heron is called Mabel.`, scope: 'site' } }));
        const t0 = Date.now();
        for (let i = 0; i < 40; i++) {
          const t = toolText(await rpc('tools/call', { name: 'tool_api__aim_recall', arguments: { text: `${CANARY} what is the pre-flight canary heron called?`, scope: 'site', limit: 3 } }));
          if (/Mabel/.test(t)) return `searchable after ${((Date.now() - t0) / 1000).toFixed(1)}s`;
          await sleep(500);
        }
        throw new Error('not searchable after 20s (is index_directly on? ddev drush sapi-i aim_vector_index)');
      });
    }

    await check('literals: exact value by viewer, via the lookup tool', async () => {
      const out = php(`
        $reader = \\Drupal::service("literals.reader");
        $anon = new \\Drupal\\Core\\Session\\AnonymousUserSession();
        $admin = \\Drupal\\user\\Entity\\User::load(1);
        \\Drupal::currentUser()->setAccount($admin);
        $tool = \\Drupal::service("plugin.manager.tool")->createInstance("aim_literal");
        $tool->setInputValue("question", "What is the library main phone number?");
        $v = $tool->execute()->getResult()->getContextValues();
        echo json_encode(["phone" => $reader->read("main_phone", $anon), "staffAnon" => $reader->read("staff_line", $anon), "staffAdmin" => $reader->read("staff_line", $admin), "tool" => $v["value"] ?? ""]);`);
      const r = JSON.parse(out.split('\n').pop());
      if (!r.phone || r.tool !== r.phone) throw new Error('tool returned "' + r.tool + '", expected ' + r.phone);
      if (r.staffAnon !== null) throw new Error('anonymous can read the staff line: ' + r.staffAnon);
      if (!r.staffAdmin) throw new Error('admin cannot read the staff line');
      return 'tool gave ' + r.tool + '; staff line hidden from anonymous, visible to admin';
    });

    await check('chat widget: hidden and API closed for anonymous', async () => {
      const home = await (await fetch(cfg.publicUrl + '/')).text();
      if (home.includes('block-olivero-aimdemochat')) throw new Error('widget block still renders for anonymous visitors');
      const r = await fetch(cfg.publicUrl + '/api/deepchat', { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{}' });
      if (r.status !== 403) throw new Error(`/api/deepchat returned ${r.status} for anonymous, expected 403`);
      return 'block hidden, API 403';
    });

    await check('chat widget: answers from memory', async () => {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      try {
        const page = await ctx.newPage();
        // The widget is for logged-in users only, so sign in first.
        await page.goto(drush('uli', '--uri=' + cfg.publicUrl, '--no-browser').split('\n').pop());
        const login = page.locator('input#edit-submit');
        if (await login.count()) { await login.first().click(); await page.waitForLoadState('networkidle'); }
        await page.goto(cfg.publicUrl + '/', { waitUntil: 'networkidle' });
        await page.locator('.ai-deepchat--header').first().click();
        await sleep(1200);
        const a = await askWidget(page, cfg.chatQuestion);
        if (!new RegExp(cfg.chatExpect, 'i').test(a.text)) throw new Error('expected /' + cfg.chatExpect + '/ in: ' + a.text.slice(0, 160));
        // No junk token here: the assistant drops one as filler, and the
        // grounded check (ADR-0037) then rightly marks the saved fact as
        // not what the visitor said, so it is untrusted under enforce and
        // recall skips it. The made-up name is unique on its own, and its
        // "zz" still trips the leftover-facts check if cleanup fails.
        const b = await askWidget(page, 'Please remember that the library\'s story-time mascot is a fox named Fizzwick.');
        const c = await askWidget(page, 'What is the library\'s story-time mascot called?');
        if (!/Fizzwick/i.test(c.text)) throw new Error(`write then read failed; second answer: ${c.text.slice(0, 140)}`);
        return `answer ${a.secs.toFixed(1)}s, save ${b.secs.toFixed(1)}s, recall-after-save ${c.secs.toFixed(1)}s`;
      }
      finally { await ctx.close(); }
    });
  }
  finally {
    await browser.close();
    try {
      const out = php(`
        $m = \\Drupal::entityTypeManager();
        $fs = $m->getStorage("aim_fact"); $ids = $fs->getQuery()->accessCheck(FALSE)->condition("id", ${startMax}, ">")->execute(); $fs->delete($fs->loadMultiple($ids));
        $cs = $m->getStorage("consumer"); $ts = $m->getStorage("oauth2_token"); $n = 0;
        foreach ($cs->loadByProperties(["label" => "preflight"]) as $c) { $t = $ts->getQuery()->accessCheck(FALSE)->condition("client", $c->id())->execute(); $ts->delete($ts->loadMultiple($t)); $c->delete(); $n++; }
        \\Drupal::database()->query("DELETE FROM {queue} WHERE name = 'aim_consolidate'");
        echo count($ids) . " canary facts and " . $n . " OAuth clients removed";`);
      console.log('cleanup: ' + out.split('\n').pop());
      drush('sapi-i', 'aim_vector_index');
      const endCount = Number(drush('sql:query', 'SELECT COUNT(*) FROM aim_fact'));
      if (endCount !== startCount) { console.log(`WARNING: memory has ${endCount} facts, was ${startCount} before the run`); results.push({ name: 'memory restored', ok: false }); }
    }
    catch (e) { console.log('cleanup FAILED: ' + String(e.message).split('\n')[0]); }
  }
  const failed = results.filter((r) => !r.ok).length;
  console.log(failed ? `\n${failed} of ${results.length} checks FAILED` : `\nAll ${results.length} checks passed - good to go.`);
  process.exit(failed ? 1 : 0);
})();
