// Manual retest of role-granularity scopes over MCP (OAuth 2.1 PKCE).
// Run on a dev copy or with throwaway data only: see ../DEVELOPING.md,
// "Role scopes for non-admin users". Steps: (1) drush php:script
// role-scope-setup.php; (2) also grant the temp roles 'grant simple_oauth
// codes', set each t_* user's password to 'Tpw-9x!zq', and seed user-scope
// facts for uids 6, 7, 9; (3) node role-scope-retest.mjs [a|b|c|d|e|f];
// (4) delete the t_* users, roles, scopes, facts and the t_client consumer.
// The uids (6 alice, 7 bob, 8 manager, 9 carol) and base URL are hardcoded.
import crypto from 'node:crypto';
process.env.NODE_TLS_REJECT_UNAUTHORIZED='0';
const B='https://aim.ddev.site', RU='http://127.0.0.1:9/cb';
const b64=b=>b.toString('base64url');
class Jar{c={};add(r){for(const s of r.headers.getSetCookie()){const [kv]=s.split(';');const i=kv.indexOf('=');this.c[kv.slice(0,i)]=kv.slice(i+1);}}h(){return Object.entries(this.c).map(([k,v])=>k+'='+v).join('; ');}}
async function req(jar,url,o={}){const r=await fetch(url,{redirect:'manual',...o,headers:{...(o.headers||{}),cookie:jar.h()}});jar.add(r);return r;}
async function login(name){const j=new Jar();let r=await req(j,B+'/user/login');const h=await r.text();
 const fid=h.match(/name="form_build_id" value="([^"]+)"/)[1];
 r=await req(j,B+'/user/login',{method:'POST',headers:{'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams({name,pass:'Tpw-9x!zq',form_build_id:fid,form_id:'user_login_form',op:'Log in'})});
 if(r.status!==303&&r.status!==302)throw new Error('login '+name+' '+r.status);return j;}
async function register(){const r=await fetch(B+'/oauth/register',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({client_name:'t_client',redirect_uris:[RU],token_endpoint_auth_method:'none',grant_types:['authorization_code','refresh_token'],response_types:['code'],scope:process.env.REGSCOPE||'aim:recall aim:remember t:t_staff t:t_manager'})});const t=await r.text();if(r.status>=300)throw new Error('register '+r.status+t);return JSON.parse(t);}
async function token(user,client,scope){
 const j=await login(user);const ver=b64(crypto.randomBytes(32));const ch=b64(crypto.createHash('sha256').update(ver).digest());
 const q=new URLSearchParams({response_type:'code',client_id:client,redirect_uri:RU,scope,state:'s',code_challenge:ch,code_challenge_method:'S256'});
 let r=await req(j,B+'/oauth/authorize?'+q);
 let loc=r.headers.get('location');let body='';
 if(r.status===200){body=await r.text();
  const form=body.match(/<form[^>]*oauth[^>]*>[\s\S]*?<\/form>/)?.[0]||body;
  const f={};for(const m of form.matchAll(/<input[^>]*>/g)){const n=m[0].match(/name="([^"]+)"/)?.[1];const v=m[0].match(/value="([^"]*)"/)?.[1]??'';const t=m[0].match(/type="([^"]+)"/)?.[1];if(n&&t!=='submit')f[n]=v;}
  const sub=[...form.matchAll(/<input[^>]*type="submit"[^>]*>/g)].map(m=>m[0]).find(s=>/allow|grant|approve|yes/i.test(s));
  const sn=sub?.match(/name="([^"]+)"/)?.[1]||'op',sv=sub?.match(/value="([^"]*)"/)?.[1]||'Allow';f[sn]=sv;
  r=await req(j,B+'/oauth/authorize?'+q,{method:'POST',headers:{'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams(f)});loc=r.headers.get('location');}
 if(!loc||!loc.startsWith(RU))return{error:'authorize '+r.status+' '+(loc||body.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').slice(0,300))};
 const u=new URL(loc);if(u.searchParams.get('error'))return{error:'authorize '+u.searchParams.get('error')+' '+u.searchParams.get('error_description')};
 const tr=await fetch(B+'/oauth/token',{method:'POST',headers:{'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams({grant_type:'authorization_code',code:u.searchParams.get('code'),client_id:client,redirect_uri:RU,code_verifier:ver})});
 const tj=await tr.json();return tj.access_token?{tok:tj.access_token,scope:tj.scope}:{error:'token '+JSON.stringify(tj)};}
let sid=1;
async function mcp(tok,method,params,session){const r=await fetch(B+'/mcp',{method:'POST',headers:{'content-type':'application/json',accept:'application/json, text/event-stream',authorization:'Bearer '+tok,...(session?{'mcp-session-id':session}:{})},body:JSON.stringify({jsonrpc:'2.0',id:sid++,method,params})});
 const t=await r.text();let j;try{j=JSON.parse(t.startsWith('event')||t.includes('\ndata:')?t.split('\n').find(l=>l.startsWith('data:')).slice(5):t)}catch{j=t.slice(0,200)}return{status:r.status,sess:r.headers.get('mcp-session-id'),j};}
async function run(label,user,client,scope,calls){
 const t=await token(user,client,scope);if(t.error){console.log(label,'=> AUTH FAIL',t.error);return;}
 const i=await mcp(t.tok,'initialize',{protocolVersion:'2025-03-26',capabilities:{},clientInfo:{name:'t',version:'1'}});
 console.log(label,'| token scope:',t.scope,'| initialize:',i.status,i.j?.error?JSON.stringify(i.j.error):'ok');
 if(i.status!==200)return;
 await mcp(t.tok,'notifications/initialized',{},i.sess).catch(()=>{});
 for(const [name,args] of calls){const c=await mcp(t.tok,'tools/call',{name,arguments:args},i.sess);
  const txt=c.j?.result?.content?.map(x=>x.text).join(' || ')??JSON.stringify(c.j?.error??c.j);console.log('   ',name,JSON.stringify(args).slice(0,60),'->',c.status,String(txt).replace(/\s+/g,' ').slice(0,700));}}
const cl=(await register());console.log('client',cl.client_id,'scope:',cl.scope);
const C=cl.client_id; const R=u=>['tool_api__aim_recall',{text:'favourite zzq',scope:'user',user:String(u)}]; const rec=[R(6),R(7),R(9)];
const rem=u=>[['tool_api__aim_remember',{facts:[{text:'T-test fact from '+u+' zzq',scope:'user',subject:String({t_alice:6,t_bob:7,t_mgr:8}[u])}]}]];
const only=process.argv[2];
const M={
 a:()=>run('A alice role+recall+remember','t_alice',C,'t:t_staff aim:recall aim:remember',[...rec,...rem('t_alice')]),
 b:()=>run('B mgr role+recall (matrix)','t_mgr',C,'t:t_manager aim:recall',rec),
 c:()=>run('C carol (no role) role scope','t_carol',C,'t:t_staff aim:recall',rec),
 d:()=>run('D alice role scope only (no aim scope)','t_alice',C,'t:t_staff',rec),
 e:()=>run('E alice aim scopes only (old behaviour)','t_alice',C,'aim:recall aim:remember',[...rec,...rem('t_alice')]),
 f:()=>run('F bob role+recall (peer, shared-role)','t_bob',C,'t:t_staff aim:recall',rec),
};
for(const k of Object.keys(M))if(!only||only===k)await M[k]();
