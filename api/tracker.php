<?php
/**
 * Analytics tracking script (v3.7) – served at /analytics/SITE-XXXXXXXX.js (site key embedded) and, for older
 * installations, at /t.js with data-site="KEY". Cache 1 h, ~2 KB, async, never blocks the page.
 *
 * What it sends to /collect (text/plain JSON, sendBeacon → fetch keepalive fallback):
 *   view   – one per page (also on SPA route changes: pushState / replaceState / popstate)
 *   leave  – time on page in seconds when the page is hidden / unloaded / the SPA route changes
 *   event  – window.omTrack('event', name[, value]) for buttons, downloads, CTAs … (counted against the plan's event quota)
 * Cookie-less: an anonymous id lives in localStorage (device), a session id in sessionStorage. Failed beacons are
 * queued in localStorage (max 20, 24 h) and flushed on the next page load or when the browser comes back online.
 * data-respect-dnt="1" skips visitors with Do-Not-Track; data-endpoint overrides the collect URL.
 */
require_once __DIR__ . '/../includes/init.php';
// The front controller started a CRM session before this script runs – discard it for anonymous requests so the visitor's
// browser never gets a cookie from the tracker, and replace the session cache limiter's no-cache headers with real caching.
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user_id'])) { $_SESSION = []; session_destroy(); header_remove('Set-Cookie'); }
header_remove('Pragma');
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
$endpoint = BASE_URL . '/collect';
$siteKey = preg_replace('~[^A-Za-z0-9-]~', '', (string) ($_GET['site'] ?? get('site', '')));
?>
(function(){var s=document.currentScript||(function(){var a=document.getElementsByTagName('script');return a[a.length-1]})();
var k=<?= json_encode($siteKey) ?>||(s&&s.getAttribute('data-site'))||'';if(!k)return;
var ep=(s&&s.getAttribute('data-endpoint'))||<?= json_encode($endpoint) ?>;
if(s&&s.getAttribute('data-respect-dnt')==='1'&&(navigator.doNotTrack==='1'||window.doNotTrack==='1'))return;
if(/bot|crawl|spider|headless|lighthouse/i.test(navigator.userAgent))return;
function uid(){try{if(window.crypto&&crypto.randomUUID)return crypto.randomUUID().replace(/-/g,'')}catch(e){}return Math.random().toString(36).slice(2)+Date.now().toString(36)+Math.random().toString(36).slice(2)}
function id(st,key){try{var v=st.getItem(key);if(!v){v=uid();st.setItem(key,v)}return v}catch(e){return ''}}
var vid=id(window.localStorage,'om_vid'),sid=id(window.sessionStorage,'om_sid'),first=0;try{if(!localStorage.getItem('om_seen')){first=1;localStorage.setItem('om_seen','1')}}catch(e){}
var Q='om_q';function qget(){try{return JSON.parse(localStorage.getItem(Q)||'[]')}catch(e){return []}}function qset(a){try{localStorage.setItem(Q,JSON.stringify(a.slice(-20)))}catch(e){}}
function post(b,onfail){try{if(navigator.sendBeacon&&navigator.sendBeacon(ep,new Blob([b],{type:'text/plain'})))return true}catch(e){}
try{fetch(ep,{method:'POST',body:b,keepalive:true,mode:'no-cors',headers:{'Content-Type':'text/plain'}}).catch(function(){if(onfail)onfail()});return true}catch(e){if(onfail)onfail();return false}}
function send(d,q){d.k=k;d.v=vid;d.s=sid;d.ts=Date.now();var b=JSON.stringify(d);post(b,function(){if(q!==false){var a=qget();a.push(b);qset(a)}})}
function flush(){var a=qget();if(!a.length)return;qset([]);var now=Date.now();for(var i=0;i<a.length;i++){try{var o=JSON.parse(a[i]);if(now-(o.ts||0)>86400000)continue;o.q=1;post(JSON.stringify(o),null)}catch(e){}}}
var cur='',t0=0,pv='';function tz(){try{return Intl.DateTimeFormat().resolvedOptions().timeZone||''}catch(e){return ''}}
function leave(){if(!cur||!t0)return;var d=Math.round((Date.now()-t0)/1000);t0=0;if(d<1||d>7200)return;send({e:'leave',u:cur,d:d,p:pv},false)}
function view(){var p=location.href;if(p===cur)return;leave();cur=p;t0=Date.now();pv=uid().slice(0,16);
send({e:'view',u:p,t:document.title||'',r:document.referrer||'',w:screen&&screen.width||0,h:screen&&screen.height||0,l:navigator.language||'',z:tz(),n:first,p:pv});first=0}
function ready(){flush();view()}
if(document.prerendering){document.addEventListener('prerenderingchange',ready,{once:true})}else{ready()}
var ps=history.pushState,rs=history.replaceState;history.pushState=function(){ps.apply(this,arguments);setTimeout(view,60)};history.replaceState=function(){rs.apply(this,arguments);setTimeout(view,60)};
window.addEventListener('popstate',function(){setTimeout(view,60)});
document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')leave();else if(cur&&!t0)t0=Date.now()});
window.addEventListener('pagehide',leave);window.addEventListener('online',flush);
window.omTrack=function(a,b,c){if(a==='event'&&b){send({e:'event',u:location.href,n:String(b).slice(0,40),x:c==null?'':String(c).slice(0,80)})}else{view()}};
})();
