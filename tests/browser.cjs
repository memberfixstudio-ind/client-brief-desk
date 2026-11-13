// Disposable seeded WordPress site only. Credentials and host come from the environment.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.CBD_PLAYWRIGHT || 'playwright');
(async () => {
 const fixture = JSON.parse(fs.readFileSync(process.env.CBD_FIXTURE, 'utf8'));
 const base = process.env.CBD_BASE || 'http://127.0.0.1:9423';
 const out = process.env.CBD_ARTIFACT_DIR || '/tmp/client-brief-desk-browser';
 fs.mkdirSync(out, { recursive: true });
 const browser = await chromium.launch({ headless: true, executablePath: process.env.CBD_CHROME || undefined });
 const errors=[];
 const context = async () => {
  const ctx = await browser.newContext({ viewport: { width:1440, height:1100 } });
  ctx.setDefaultTimeout(30000);
  await ctx.route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
  ctx.on('page', p=>{ p.on('pageerror', e=>errors.push(e.message)); p.on('dialog',d=>d.accept()); });
  return ctx;
 };
 async function login(ctx,user,password) {
  const p=await ctx.newPage(); await p.goto(base+'/wp-login.php');
  await p.locator('#user_login').fill(user); await p.locator('#user_pass').fill(password);
  await p.locator('#wp-submit').click(); await p.waitForURL(url=>!url.pathname.endsWith('/wp-login.php'));
  assert.ok((await ctx.cookies()).some(c=>c.name.startsWith('wordpress_logged_in_')), 'Authenticated WordPress session'); return p;
 }
 async function loaded(p) { await p.waitForFunction(()=>document.getElementById('cbd-list')?.getAttribute('aria-busy')==='false'); }
 async function open(p,title) { await loaded(p); await p.getByRole('button',{name:title,exact:true}).click(); await p.locator('#cbd-panel .cbd-detail-meta').waitFor(); }
 async function notice(p,text) { await p.waitForFunction(t=>document.getElementById('cbd-panel-notice').textContent.includes(t),text); }
 async function confirm(p,first,second,result) { await p.getByRole('button',{name:first,exact:true}).click(); await p.getByRole('button',{name:second,exact:true}).click(); await notice(p,result); }
 async function api(p,endpoint,method='GET',data) { return p.evaluate(async ({endpoint,method,data})=>{const r=await fetch(clientBriefDesk.root+endpoint,{method,headers:{'X-WP-Nonce':clientBriefDesk.nonce,'Content-Type':'application/json'},...(data?{body:JSON.stringify(data)}:{})});return {status:r.status,data:await r.json()};},{endpoint,method,data}); }
 try {
  const anon=await context(); const anonymous=await anon.newPage(); await anonymous.goto(fixture.portal);
  assert.equal(await anonymous.getByRole('link',{name:'Log in to view your briefs'}).count(),1);
  assert.equal(await anonymous.locator('#cbd-app').count(),0,'Anonymous page contains no client application');
  const adminContext=await context(); const admin=await login(adminContext,process.env.CBD_USER||'admin',process.env.CBD_PASSWORD);
  await admin.goto(base+'/wp-admin/admin.php?page=client-brief-desk'); await loaded(admin);
  await admin.locator('#cbd-search').fill('Cedar'); await admin.locator('#cbd-filters').getByRole('button',{name:'Search',exact:true}).click(); await loaded(admin);
  await admin.screenshot({path:path.join(out,'dashboard.png')});
  await open(admin,'Cedar · Website refresh'); await admin.screenshot({path:path.join(out,'review.png')}); await admin.locator('#cbd-close').click();
  const title='Browser workflow '+Date.now();
  await admin.getByRole('button',{name:'New brief',exact:true}).click();
  await admin.locator('#cbd-title').fill(title); await admin.locator('#cbd-client-search').fill(fixture.client_login);
  await admin.locator('input[name=client_id][value="'+fixture.client_id+'"]').check();
  await admin.locator('#cbd-preset option[value=service]').waitFor({state:'attached'}); await admin.locator('#cbd-preset').selectOption('service');
  assert.equal(await admin.locator('[data-question=label]').count(),4,'Preset populates four questions');
  await admin.getByRole('button',{name:'Create brief',exact:true}).click(); await notice(admin,'Brief created.');
  const id=Number((await admin.locator('#cbd-panel-kicker').innerText()).match(/#(\d+)/)[1]);
  await admin.locator('#cbd-internal-note').fill('PRIVATE STAFF TEST NOTE'); await admin.getByRole('button',{name:'Save internal note',exact:true}).click(); await notice(admin,'Internal note saved');
  await admin.locator('#cbd-close').click();
  const clientContext=await context(); const client=await login(clientContext,fixture.client_login,fixture.client_password);
  await client.goto(fixture.portal); await open(client,title);
  assert.equal(await client.locator('#cbd-internal-note').count(),0);
  assert.ok(!(await client.locator('#cbd-panel').innerText()).includes('PRIVATE STAFF TEST NOTE'));
  await client.locator('#cbd-answer-q1').fill('An approachable website for our independent plant shop.');
  await client.getByRole('button',{name:'Save answers',exact:true}).click(); await notice(client,'Answers saved');
  await client.getByRole('button',{name:'Submit for review',exact:true}).click();
  assert.equal(await client.locator('#cbd-answer-q2').getAttribute('aria-invalid'),'true','Required answers block incomplete submission');
  assert.equal((await api(client,'briefs/'+id)).data.status,'open');
  const peer=await clientContext.newPage(); await peer.goto(fixture.portal); await open(peer,title);
  await client.locator('#cbd-answer-q1').fill('Current server answer from the first tab.');
  await client.getByRole('button',{name:'Save answers',exact:true}).click(); await notice(client,'Answers saved');
  await peer.locator('#cbd-answer-q1').fill('My retained answer from the second tab.');
  await peer.getByRole('button',{name:'Save answers',exact:true}).click(); await peer.locator('#cbd-conflict').waitFor({state:'visible'});
  assert.equal(await peer.locator('#cbd-answer-q1').inputValue(),'My retained answer from the second tab.');
  await peer.locator('#cbd-reload').click(); await notice(peer,'Your draft is preserved');
  assert.equal(await peer.locator('#cbd-answer-q1').inputValue(),'My retained answer from the second tab.');
  assert.ok((await peer.locator('.cbd-comparison').innerText()).includes('Current server answer from the first tab.'));
  for(let i=2;i<=4;i++) await peer.locator('#cbd-answer-q'+i).fill(['','', 'Help customers find workshops and plan a visit.','Launch before our winter programme.','Public references will follow after review.'][i]);
  await peer.setViewportSize({width:390,height:844});
  assert.equal(await peer.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  assert.equal(await peer.locator('#cbd-panel').evaluate(n=>n.scrollWidth<=n.clientWidth),true,'Dialog fits 390px');
  await peer.locator('#cbd-panel').evaluate(n=>n.scrollTop=0); await peer.screenshot({path:path.join(out,'client-mobile.png')});
  await confirm(peer,'Submit for review','Confirm submission','Submitted for review');
  assert.equal(await peer.locator('#cbd-answer-q1').count(),0,'Submitted answers become read-only');
  assert.ok(!JSON.stringify((await api(peer,'briefs/'+id+'/export')).data).includes('PRIVATE STAFF TEST NOTE'));
  const denied=await peer.evaluate(async()=>{const r=await fetch(clientBriefDesk.root+'briefs');return r.status;});
  assert.ok(denied>=400,'Cookie request without REST nonce denied');
  await admin.locator('#cbd-search').fill(title); await admin.locator('#cbd-filters').getByRole('button',{name:'Search',exact:true}).click(); await open(admin,title);
  await admin.locator('#cbd-feedback').fill('Please confirm the workshop launch date.');
  await confirm(admin,'Request changes','Confirm changes request','Changes requested');
  await peer.getByRole('button',{name:'Load latest',exact:true}).click(); await notice(peer,'Latest version loaded.');
  assert.ok((await peer.locator('.cbd-feedback').innerText()).includes('confirm the workshop launch date'));
  await peer.locator('#cbd-answer-q3').fill('Launch confirmed for 1 December.');
  await confirm(peer,'Submit for review','Confirm submission','Submitted for review');
  await admin.getByRole('button',{name:'Load latest',exact:true}).click(); await notice(admin,'Latest version loaded.');
  await confirm(admin,'Approve','Confirm approval','Brief approved');
  const approved=(await api(admin,'briefs/'+id)).data;
  assert.equal(approved.status,'approved');
  const approvals=approved.events.filter(e=>e.action==='approve');
  assert.equal(approvals.length,1); assert.equal(approvals[0].snapshot.answers.q3,'Launch confirmed for 1 December.');
  const download=admin.waitForEvent('download'); await admin.getByRole('button',{name:'Export JSON',exact:true}).click();
  await (await download).saveAs(path.join(out,'export.json')); assert.equal(JSON.parse(fs.readFileSync(path.join(out,'export.json'))).brief.id,id);
  await admin.locator('#cbd-close').click(); await admin.setViewportSize({width:390,height:844});
  assert.equal(await admin.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Staff workspace fits mobile');
  assert.deepEqual(errors,[],'No JavaScript runtime errors');
  console.log('BROWSER PASS: anonymous gate, real client assignment, presets, incomplete save, required validation, two-tab conflict recovery, private note isolation, submission/revision/approval snapshots, nonce denial, JSON download and 390px staff/client layouts.');
 } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
