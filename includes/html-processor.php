<?php
/**
 * HTML processor: reads the template, rewrites links, cleans scripts, builds exchange pages
 */

$_cachedHtml = null;

function getBaseHtml() {
    global $_cachedHtml;
    if ($_cachedHtml !== null) return $_cachedHtml;

    $templateFile = __DIR__ . '/../template/bestchange.html';
    if (!file_exists($templateFile)) {
        http_response_code(500);
        die('Template not found: ' . $templateFile);
    }
    $raw = @file_get_contents($templateFile);
    if ($raw === false || strlen($raw) < 1000) {
        http_response_code(500);
        die('Cannot read template');
    }
    $raw = cleanupScripts($raw);
    $raw = rewriteLinks($raw);
    $_cachedHtml = $raw;
    return $_cachedHtml;
}

/**
 * Remove all analytics, tracking, and problematic JS scripts.
 * Keep only: the data arrays (ds_list, cu_list, av_list etc.) and the session/config vars.
 * Inject clean replacement functions for sidebar interactivity.
 */
function cleanupScripts($html) {
    // 1) Find the first <script (analytics) -- it starts after meta tags
    //    We want to remove everything from the first <script> up to but NOT including
    //    the script that contains "var ds_list"
    $dataScriptMarker = 'var ds_list = new Array(';
    $dataScriptPos = strpos($html, $dataScriptMarker);
    if ($dataScriptPos === false) return $html; // safety

    // Find the <script tag that contains ds_list
    $dataScriptTagStart = strrpos(substr($html, 0, $dataScriptPos), '<script');
    if ($dataScriptTagStart === false) return $html;

    // Find the very first <script in the document (analytics)
    $firstScript = strpos($html, '<script');
    if ($firstScript === false || $firstScript >= $dataScriptTagStart) return $html;

    // Remove everything from the first <script> up to the ds_list <script>
    // This removes: TMR analytics, Google GTM, all the app JS (show_info, update_rates etc.), lang var, hcaptcha/raven
    $html = substr($html, 0, $firstScript) . substr($html, $dataScriptTagStart);

    // 2) Now find and remove the hcaptcha/raven script block that may be between
    //    the end of the big app script (</script>) and the ds_list script
    // Actually, we already removed it above since it was before ds_list.

    // 3) Find the </script> that closes the ds_list block and inject our clean functions after it
    $dsListScriptEnd = strpos($html, '</script>', strpos($html, $dataScriptMarker));
    if ($dsListScriptEnd !== false) {
        $insertPos = $dsListScriptEnd + 9; // after </script>
        $html = substr($html, 0, $insertPos) . "\n" . getCleanAppScript() . "\n" . substr($html, $insertPos);
    }

    // 4) Remove any remaining tracking/counter scripts at the end of body
    // Remove <noscript> blocks (tracking pixels, counters)
    $html = preg_replace('#<noscript>\s*<div>.*?</div>\s*</noscript>#s', '', $html);
    $html = preg_replace('#<noscript>.*?</noscript>#s', '', $html);

    // 5) Remove leftover inline <script> blocks with tracking signatures
    $trackingSignatures = '_tmr|top-fwz|top\.mail\.ru|mail\.ru/counter|mail\.ru/retarget|TMRCounter'
        . '|googletagmanager|google-analytics|analytics\.google|gtag\s*\('
        . '|GoogleAnalyticsObject|metrik|yaCounter|Ya\._metrika|mc\.yandex|ym\s*\('
        . '|fbq\s*\(|connect\.facebook|VK\.Retarget|hotjar|clarity\.ms'
        . '|sentry|bugsnag|amplitude|mixpanel|segment\.com|sendBeacon|fingerprint'
        . '|adsbygoogle|googlesyndication|doubleclick|clicky|openstat|tns-counter|adfox';
    $html = preg_replace('#<script[^>]*>(?=(?:(?!</script>).)*(?:' . $trackingSignatures . ')).*?</script>#si', '', $html);

    // 6) Remove external script tags loading tracking domains
    $extTrackDomains = 'googletagmanager\.com|google-analytics\.com|analytics\.google\.com'
        . '|mc\.yandex\.ru|top-fwz1\.mail\.ru|top\.mail\.ru|ad\.mail\.ru'
        . '|connect\.facebook\.net|hotjar\.com|clarity\.ms'
        . '|cdn\.amplitude\.com|cdn\.segment\.com|sentry\.io'
        . '|pagead2\.googlesyndication\.com|stats\.g\.doubleclick\.net|counter\.yadro\.ru';
    $html = preg_replace('#<script[^>]*src=["\'](?:https?:)?//(?:' . $extTrackDomains . ')[^"\']*["\'][^>]*>\s*</script>#i', '', $html);

    // 7) Remove tracking meta tags
    $html = preg_replace('#<meta\s+name="apple-itunes-app"[^>]*>#i', '', $html);
    $html = preg_replace('#<meta\s+name="google-site-verification"[^>]*>#i', '', $html);
    $html = preg_replace('#<meta\s+name="yandex-verification"[^>]*>#i', '', $html);
    $html = preg_replace('#<meta\s+name="msvalidate\.01"[^>]*>#i', '', $html);
    $html = preg_replace('#<meta\s+name="facebook-domain-verification"[^>]*>#i', '', $html);
    $html = preg_replace('#<meta\s+property="fb:app_id"[^>]*>#i', '', $html);

    // 8) Remove tracking link tags (preconnect/dns-prefetch to tracking domains)
    $html = preg_replace('#<link[^>]*(?:googletagmanager|google-analytics|mc\.yandex|mail\.ru|facebook\.net|hotjar|clarity\.ms)[^>]*>#i', '', $html);

    // 9) Remove yandex-tableau-widget and manifest referencing bestchange
    $html = preg_replace('#<link\s+rel="yandex-tableau-widget"[^>]*>#i', '', $html);
    $html = preg_replace('#<link\s+rel="manifest"[^>]*bestchange[^>]*>#i', '', $html);

    // 10) Remove 1x1 tracking pixel images
    $html = preg_replace('#<img[^>]*(?:width=["\']1["\']|height=["\']1["\'])[^>]*(?:mail\.ru|yandex|counter|pixel|track)[^>]*/?\s*>#i', '', $html);

    // 11) Remove hreflang/alternate links pointing to bestchange.com/bestchange.ru
    $html = preg_replace('#<link\s+rel="alternate"[^>]*bestchange\.[a-z]+[^>]*>#i', '', $html);
    $html = preg_replace('#<link\s+rel="canonical"[^>]*bestchange\.[a-z]+[^>]*>#i', '', $html);

    // 12) Remove "English" footer links that point to bestchange.com
    $html = preg_replace('#<a[^>]*href="https?://[^"]*bestchange\.com[^"]*"[^>]*>[^<]*English[^<]*</a>\s*\|?#i', '', $html);

    return $html;
}

/**
 * Clean replacement script with only the essential sidebar functions
 */
function getCleanAppScript() {
    return '<script type="text/javascript">
// Global state for sidebar currency selection
var lc_curr = 0;
var rc_curr = 0;
// Essential functions for sidebar currency selection
function nodeById(id) { return document.getElementById(id); }
function eventPush(obj, event, handler) {
  if (obj.addEventListener) obj.addEventListener(event, handler, false);
  else if (obj.attachEvent) obj.attachEvent("on" + event, handler);
}
function setCookie(name, value) {
  var d = new Date(); d.setTime(d.getTime() + 365*24*60*60*1000);
  document.cookie = name + "=" + value + ";expires=" + d.toUTCString() + ";path=/";
}
function getCookie(name) {
  var n = name + "=", ca = document.cookie.split(";");
  for (var i = 0; i < ca.length; i++) {
    var c = ca[i]; while (c.charAt(0) == " ") c = c.substring(1);
    if (c.indexOf(n) == 0) return c.substring(n.length, c.length);
  }
  return "";
}
function addClass(o, c) { if (o && !o.className.match(new RegExp("(\\\\s|^)" + c + "(\\\\s|$)"))) o.className += " " + c; }
function removeClass(o, c) { if (o) o.className = o.className.replace(new RegExp("(\\\\s|^)" + c + "(\\\\s|$)"), " ").trim(); }
function id2pos(number) {
  for (var i = 0; i < ds_list.length; i++) if (ds_list[i] == number) return i;
  return -1;
}
function goto_list(from, to) {
  var f = from ? from : lc_curr;
  var t = to ? to : rc_curr;
  if (f == 0 || t == 0) return;
  var fp = id2pos(f), tp = id2pos(t);
  if (fp < 0 || tp < 0) return;
  window.location.href = "/" + cu_list[fp] + "-to-" + cu_list[tp];
}
function mark_selected(id, direct) {
  var pos = id2pos(id);
  if (pos < 0) return;
  var el = nodeById("d" + direct + id);
  if (el) el.className = "c" + direct;
}
function mark_unav(id, direct) {
  var id_pos = id2pos(id);
  if (id_pos < 0) return;
  for (var i = 0; i < ds_list.length; i++) {
    var aEl = nodeById((direct == "rc" ? "alc" : "arc") + ds_list[i]);
    if (aEl) {
      aEl.className = direct == "rc"
        ? (av_list[i].substring(id_pos, id_pos + 1) == "0" ? "unav" : "")
        : (av_list[id_pos].substring(i, i + 1) == "0" ? "unav" : "");
    }
  }
}
function clk(id, direct, nofollow) {
  var adirect = direct == "rc" ? "lc" : "rc";
  eval("var old_curr = " + direct + "_curr");
  if (old_curr == id) return false;
  // If both already selected and clicking on the same currency in opposite column, reset it
  if (lc_curr != 0 && rc_curr != 0 && ((direct == "lc" && rc_curr == id) || (direct == "rc" && lc_curr == id))) {
    eval(adirect + "_curr = 0");
    var item = nodeById("d" + adirect + id);
    if (item) item.className = adirect;
  }
  var b = (old_curr > 0 && old_curr != id);
  eval(direct + "_curr = " + id);
  if (b) {
    var item2 = nodeById("d" + direct + old_curr);
    if (item2) item2.className = direct;
  }
  mark_selected(id, direct);
  mark_unav(id, direct);
  // navigate only when both selected
  if (lc_curr != 0 && rc_curr != 0 && !nofollow) goto_list();
  return false;
}
function reverse_direct() {
  var temp = lc_curr;
  if (rc_curr > 0) clk(rc_curr, "lc", true);
  if (temp > 0) clk(temp, "rc");
  return false;
}
function sel_change(direct, locate) {
  var adirect = direct == "rc" ? "lc" : "rc";
  var ds = document.getElementById("currency_" + direct);
  var ads = document.getElementById("currency_" + adirect);
  if (!ds || !ads) return;
  if (locate) {
    eval(direct + "_curr = ds.value");
    if (lc_curr != 0 && rc_curr != 0) goto_list();
  }
  var idp = id2pos(ds.value);
  for (var i = 0; i < ads.options.length; i++) {
    ads.options[i].style.color = direct == "rc"
      ? (av_list[i].substring(idp, idp + 1) == "0" ? "#aaa" : "#222")
      : (av_list[idp].substring(i, i + 1) == "0" ? "#aaa" : "#222");
  }
  return false;
}
function corr_tab(id, direct) {
  if (id != 0) {
    for (var i = 0; i < ds_list.length; i++) {
      if (ds_list[i] != id) {
        var el = nodeById("d" + direct + ds_list[i]);
        if (el) el.className = direct;
      }
    }
    mark_selected(id, direct);
    if (direct == "lc" || (direct == "rc" && lc_curr == 0)) mark_unav(id, direct);
  }
}
// Tab switching (called by onmousedown="change_ctab(\'tab\')" in HTML)
var curr_expanded = false;
function change_ctab(tabName) {
  var tabs = ["tab", "list", "top"];
  for (var i = 0; i < tabs.length; i++) {
    var el = document.getElementById("curr_" + tabs[i]);
    var tl = document.getElementById("tab_" + tabs[i]);
    if (el) { if (tabs[i] === tabName) { el.style.display = ""; removeClass(el, "hide"); } else { el.style.display = "none"; addClass(el, "hide"); } }
    if (tl) { tabs[i] === tabName ? addClass(tl, "active") : removeClass(tl, "active"); }
  }
  setCookie("ct", tabName);
}
// Show/hide full currency list (called by onclick="return switch_curr_list()" on the arrow button)
function switch_curr_list() {
  var rows = document.querySelectorAll("#curr_tab_c tr.hide");
  var btn = document.getElementById("tab_show_button");
  if (!curr_expanded) {
    // Expand: show all hidden rows
    var allRows = document.querySelectorAll("#curr_tab_c tbody tr");
    for (var i = 0; i < allRows.length; i++) { removeClass(allRows[i], "hide"); }
    curr_expanded = true;
    if (btn) addClass(btn, "down");
  } else {
    // Collapse: re-hide rows that had "hide" originally
    // We just reload since original classes are lost; simpler: just toggle state
    // Mark non-essential rows as hidden again
    location.reload();
  }
  return false;
}
// "Найти лучший курс" button in Список tab
function list_clk() {
  var lc = document.getElementById("currency_lc");
  var rc = document.getElementById("currency_rc");
  if (lc && rc && lc.value && rc.value) {
    lc_curr = parseInt(lc.value);
    rc_curr = parseInt(rc.value);
    goto_list();
  }
  return false;
}
// Sync dropdown select with tab selection
function corr_list(id, direct) {
  if (id != 0) {
    var sel = document.getElementById("currency_" + direct);
    if (sel) { sel.value = id; sel_change(direct, false); }
  }
}
// Stubs for functions called inline in the HTML template
function make_tablink(direct) { /* handled by existing onclick handlers */ }
function set_search_field(direct) { /* search not implemented in clone */ }
// Init on load
(function() {
  corr_tab(lc_curr, "lc");
  corr_tab(rc_curr, "rc");
})();
</script>';
}

/**
 * Returns JS that obfuscates the page DOM on every load:
 * - Injects random CSS classes into every element
 * - Adds random data-* attributes
 * - Inserts invisible <span> elements with random content
 * - Appends a hidden <div> block with random paragraphs
 * This makes every page load produce a unique HTML fingerprint.
 */
function getObfuscationScript() {
    // Generate a few random strings server-side for the hidden block
    $randParagraphs = '';
    for ($i = 0; $i < mt_rand(3, 6); $i++) {
        $words = [];
        for ($w = 0; $w < mt_rand(5, 15); $w++) {
            $len = mt_rand(3, 10);
            $word = '';
            for ($c = 0; $c < $len; $c++) {
                $word .= chr(mt_rand(97, 122));
            }
            $words[] = $word;
        }
        $randParagraphs .= '<p>' . implode(' ', $words) . '</p>';
    }
    $randId = '';
    for ($i = 0; $i < 8; $i++) $randId .= chr(mt_rand(97, 122));

    return '<div id="' . $randId . '" style="position:absolute;left:-9999px;top:-9999px;width:0;height:0;overflow:hidden;opacity:0;pointer-events:none" aria-hidden="true">' . $randParagraphs . '</div>
<script>
(function(){
  var chars="abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
  function rc(n){var s="";n=n||(Math.floor(Math.random()*6)+5);for(var i=0;i<n;i++)s+=chars.charAt(Math.floor(Math.random()*chars.length));return s;}
  function ra(){return"data-"+rc(4);}

  // 1. Add random classes to all elements with existing classes
  var els=document.querySelectorAll("[class]");
  for(var i=0;i<els.length;i++){
    var el=els[i];
    try{
      var orig=Array.from(el.classList);
      var nc=[];
      for(var j=0;j<orig.length;j++){
        nc.push(rc());
        nc.push(orig[j]);
        if(Math.random()>0.5)nc.push(rc());
      }
      nc.push(rc());
      el.className=nc.join(" ");
    }catch(e){}
  }

  // 2. Add random data-* attributes to ~30% of all elements
  var all=document.querySelectorAll("div,td,tr,span,a,p,li,ul,h1,h2,h3,h4,table,form,input");
  for(var k=0;k<all.length;k++){
    if(Math.random()<0.3){
      try{all[k].setAttribute(ra(),rc(Math.floor(Math.random()*8)+3));}catch(e){}
    }
  }

  // 3. Insert invisible spans with random text into some text nodes
  var targets=document.querySelectorAll("p,td,li,h1,h2,h3,h4,div.smalltext");
  for(var m=0;m<targets.length;m++){
    if(Math.random()<0.15){
      try{
        var sp=document.createElement("span");
        sp.style.cssText="position:absolute;left:-9999px;width:0;height:0;overflow:hidden;opacity:0";
        sp.textContent=rc(Math.floor(Math.random()*12)+4);
        sp.setAttribute("aria-hidden","true");
        targets[m].appendChild(sp);
      }catch(e){}
    }
  }
})();
</script>';
}

function getInteractiveScript() {
    return '<script>
(function() {
  // Override goto_list and openDocument for local routing
  var _orig_goto = typeof goto_list === "function" ? goto_list : null;
  window.goto_list = function(from, to) {
    var f = from ? from : lc_curr;
    var t = to ? to : rc_curr;
    if (f == 0 || t == 0) return;
    var fp = id2pos(f), tp = id2pos(t);
    if (fp < 0 || tp < 0) return;
    window.location.href = "/" + cu_list[fp] + "-to-" + cu_list[tp];
  };
  window.openDocument = function(url) {
    url = url.replace(/\\.html$/, "");
    url = url.replace(/^https?:\\/\\/www\\.bestchange\\.ru/, "");
    if (url.indexOf("/") !== 0) url = "/" + url;
    window.location.href = url;
  };
  // Clock
  function tick() {
    var dd = document.querySelector("#headinfo .localdate");
    if (dd) dd.textContent = new Date().toLocaleTimeString("ru-RU");
  }
  tick(); setInterval(tick, 1000);
})();
</script>';
}

function rewriteLinks($html) {
    // First: neutralize sidebar currency links so they don't navigate on click
    // These links have onclick="return clk(...)" which handles selection logic
    // The href must be "#" so clicking doesn't navigate before both currencies are chosen
    $html = preg_replace(
        '/href="https:\/\/www\.bestchange\.ru\/[^"]*\.html"(\s+id="a[lr]c\d+")/',
        'href="#"$1',
        $html
    );

    // Rewrite form actions (Список tab)
    $html = str_replace('action="https://www.bestchange.ru/index.php"', 'action="/"', $html);

    // Then rewrite remaining bestchange.ru links to local
    $html = str_replace('https://www.bestchange.ru/', '/', $html);
    // Also rewrite bestchange.com links
    $html = str_replace('https://www.bestchange.com/', '/', $html);

    // Remove hreflang/alternate links pointing to bestchange domains
    $html = preg_replace('#<link\s+rel="alternate"[^>]*bestchange\.[a-z]+[^>]*>#i', '', $html);
    // Remove "English" footer link pointing to bestchange.com
    $html = preg_replace('#<a[^>]*href="https?://[^"]*bestchange\.com[^"]*"[^>]*>[^<]*English[^<]*</a>\s*\|?#i', '', $html);

    $html = preg_replace('/href="\/([^"]+)\.html"/', 'href="/$1"', $html);
    return $html;
}

function replaceTableBody($html, $newTbody) {
    $marker = 'id="content_table"';
    $tablePos = strpos($html, $marker);
    if ($tablePos === false) return $html;
    $tbodyOpen = strpos($html, '<tbody>', $tablePos);
    if ($tbodyOpen === false) return $html;
    $tbodyClose = strpos($html, '</tbody>', $tbodyOpen);
    if ($tbodyClose === false) return $html;
    return substr($html, 0, $tbodyOpen) . "<tbody>\n" . $newTbody . "\n</tbody>" . substr($html, $tbodyClose + 8);
}

function replaceSmallText($html, $newContent) {
    $marker = 'id="small_text"';
    $pos = strpos($html, $marker);
    if ($pos === false) return $html;
    $tagClose = strpos($html, '>', $pos);
    if ($tagClose === false) return $html;
    $innerStart = $tagClose + 1;
    $depth = 1; $i = $innerStart; $len = strlen($html);
    while ($i < $len && $depth > 0) {
        $nextOpen = strpos($html, '<div', $i);
        $nextClose = strpos($html, '</div>', $i);
        if ($nextClose === false) break;
        if ($nextOpen !== false && $nextOpen < $nextClose) { $depth++; $i = $nextOpen + 4; }
        else { $depth--; if ($depth === 0) return substr($html, 0, $innerStart) . "\n" . $newContent . "\n" . substr($html, $nextClose); $i = $nextClose + 6; }
    }
    return $html;
}

function buildRatesTable($fromId, $toId) {
    $from = getCurrency($fromId);
    $to = getCurrency($toId);
    if (!$from || !$to) return '';
    $rates = generateRates($fromId, $toId);
    $rows = '';
    foreach ($rates as $i => $rate) {
        $altClass = ($i % 2 === 1) ? ' class="alt"' : '';
        $give = formatNumber($rate['give']);
        $receive = formatNumber($rate['receive']);
        $reserve = formatNumber($rate['reserve']);
        $fT = htmlspecialchars($from['ticker']);
        $tT = htmlspecialchars($to['ticker']);
        $exName = htmlspecialchars($rate['exchangerName']);
        $reviews = (int)$rate['reviews'];
        $linkUrl = '#';
        if (!empty($rate['linkUrl'])) $linkUrl = htmlspecialchars($rate['linkUrl']);
        $rows .= '<tr' . $altClass . '>'
            . '<td class="ir"><span class="io" id="io' . $i . '"></span></td>'
            . '<td class="bj"><div class="pa"><a rel="nofollow" target="_blank" href="' . $linkUrl . '"></a><div class="pc"><div class="ca" translate="no">' . $exName . '</div></div></div></td>'
            . '<td class="bi"><div class="fs">' . $give . ' <small translate="no">' . $fT . '</small></div></td>'
            . '<td class="bi">' . $receive . ' <small translate="no">' . $tT . '</small></td>'
            . '<td class="ar arp">' . $reserve . '</td>'
            . '<td class="rw"><a href="#" class="rwan">' . $reviews . '</a></td>'
            . "</tr>\n";
    }
    return $rows;
}

function buildMainPage() {
    $html = getBaseHtml();
    // Inject script that makes all exchanger links on the main page open my exchangers
    $myScript = getMainPageExchangerScript();
    $html = str_replace('</body>', getInteractiveScript() . "\n" . $myScript . "\n" . getObfuscationScript() . "\n</body>", $html);
    return $html;
}

/**
 * Returns JS that rewires ALL exchanger links on the main page to my exchangers.
 * This way clicking any exchanger row opens one of my exchanger sites.
 */
function getMainPageExchangerScript() {
    $myEx = loadMyExchangers();
    if (empty($myEx)) return '';

    $urls = [];
    foreach ($myEx as $ex) {
        $urls[] = '"https://' . addslashes($ex['domain']) . '"';
    }
    $urlsJs = implode(',', $urls);

    return '<script>
(function() {
  var myUrls = [' . $urlsJs . '];
  if (!myUrls.length) return;
  // Rewrite all exchanger links in the main rates table
  var links = document.querySelectorAll("#content_table a[rel=nofollow], #content_table .pa a, #content_table td.bj a");
  for (var i = 0; i < links.length; i++) {
    links[i].href = myUrls[i % myUrls.length];
    links[i].target = "_blank";
  }
  // Also intercept clicks on exchanger name rows
  document.getElementById("content_table").addEventListener("click", function(e) {
    var row = e.target.closest("tr");
    if (!row) return;
    var idx = Array.prototype.indexOf.call(row.parentNode.children, row);
    var url = myUrls[idx % myUrls.length];
    if (url) window.open(url, "_blank");
  });
})();
</script>';
}

/**
 * Serve a static HTML page (contacts, faq, list, partner, report, wiki/help).
 * Reads the saved HTML file, rewrites links, and returns it.
 */
function buildStaticPage($filePath) {
    if (!file_exists($filePath)) return null;
    $html = @file_get_contents($filePath);
    if ($html === false || strlen($html) < 100) return null;

    // 1. Neutralize sidebar currency links (same as rewriteLinks)
    $html = preg_replace(
        '/href="https:\/\/www\.bestchange\.ru\/[^"]*\.html"(\s+id="a[lr]c\d+")/',
        'href="#"$1',
        $html
    );

    // 2. Rewrite form actions
    $html = str_replace('action="https://www.bestchange.ru/index.php"', 'action="/"', $html);

    // 3. Rewrite bestchange.ru and bestchange.com links to local
    $html = str_replace('https://www.bestchange.ru/', '/', $html);
    $html = str_replace('https://www.bestchange.com/', '/', $html);

    // 4. Strip .html from href links
    $html = preg_replace('/href="\/([^"]+)\.html"/', 'href="/$1"', $html);

    // 5. Patch JS openDocument to strip .html from URLs
    $html = str_replace(
        'function openDocument(url, new_window) {',
        'function openDocument(url, new_window) { url = url.replace(/\\.html$/, "").replace(/\\.html\\?/, "?");'
    , $html);

    // 6. Patch JS-generated href links: replace '.html"' with '"' in JS string concatenations
    //    e.g.: + '.html"' -> + '"'   and  + '.html">' -> + '">'
    $html = str_replace("+ '.html\"", "+ '\"", $html);
    $html = str_replace("+ '.html'", "+ ''", $html);

    // 7. Fix relative index.html links -> "#" (tabs etc. use onclick, href is just fallback)
    $html = str_replace('href="index.html"', 'href="#"', $html);

    // 8. Remove mobile app banner meta tag
    $html = preg_replace('#<meta\s+name="apple-itunes-app"[^>]*>#i', '', $html);

    // 9. Deep tracking/analytics/spyware removal
    // 9a. Remove <noscript> blocks with tracking pixels
    $html = preg_replace('#<noscript>\s*<div>.*?</div>\s*</noscript>#s', '', $html);
    $html = preg_replace('#<noscript>.*?</noscript>#s', '', $html);

    // 9b. Remove ALL <script> blocks that contain tracking/analytics signatures
    $trackingPatterns = [
        '_tmr',                    // Top.Mail.ru tracker
        'top-fwz',                 // Top.Mail.ru CDN
        'top\.mail\.ru',           // Mail.ru counter
        'mail\.ru/counter',        // Mail.ru counter
        'mail\.ru/retarget',       // Mail.ru retargeting pixel
        'TMRCounter',              // Mail.ru counter class
        'googletagmanager',        // Google Tag Manager
        'google-analytics',        // Google Analytics
        'analytics\.google',       // Google Analytics endpoint
        'gtag\s*\(',               // Google gtag()
        'G-[A-Z0-9]+',            // GA4 measurement IDs
        'GT-[A-Z0-9]+',           // GTM container IDs
        'GoogleAnalyticsObject',   // Classic GA
        'metrik',                  // Yandex.Metrika
        'yaCounter',               // Yandex counter
        'Ya\._metrika',            // Yandex metrika object
        'mc\.yandex',              // Yandex Metrika CDN
        'ym\s*\(',                 // Yandex Metrika ym() call
        'fbq\s*\(',               // Facebook Pixel
        'connect\.facebook',       // Facebook SDK
        'VK\.Retarget',            // VK retargeting
        'vk\.com/js/api',          // VK API
        'top100',                  // Rambler Top100
        'rambler.*top',            // Rambler top counter
        'liveinternet',            // LiveInternet counter
        'hotjar',                  // Hotjar
        'clarity\.ms',             // Microsoft Clarity
        'sentry\.io',              // Sentry error tracking
        'bugsnag',                 // Bugsnag
        'amplitude',               // Amplitude analytics
        'mixpanel',                // Mixpanel
        'segment\.com',            // Segment
        'plausible',               // Plausible
        'beacon',                  // Generic beacon
        'sendBeacon',              // Navigator sendBeacon
        'fingerprint',             // Fingerprinting
        'hcaptcha',                // hCaptcha (not needed)
        'raven',                   // Sentry Raven.js
        'adsbygoogle',             // Google Ads
        'googlesyndication',       // Google Ads CDN
        'doubleclick',             // Google DoubleClick
        'clicky',                  // Clicky analytics
        'openstat',                // Openstat counter
        'tns-counter',             // TNS counter
        'adfox',                   // AdFox
    ];
    $trackingRegex = implode('|', $trackingPatterns);
    // Remove script blocks containing any tracking pattern
    $html = preg_replace('#<script[^>]*>(?=(?:(?!</script>).)*(?:' . $trackingRegex . ')).*?</script>#si', '', $html);

    // 9c. Remove external script tags loading tracking domains
    $trackingDomains = [
        'googletagmanager\.com',
        'google-analytics\.com',
        'analytics\.google\.com',
        'mc\.yandex\.ru',
        'top-fwz1\.mail\.ru',
        'top\.mail\.ru',
        'connect\.facebook\.net',
        'vk\.com/js',
        'hotjar\.com',
        'clarity\.ms',
        'cdn\.amplitude\.com',
        'cdn\.segment\.com',
        'sentry\.io',
        'pagead2\.googlesyndication\.com',
        'stats\.g\.doubleclick\.net',
        'counter\.yadro\.ru',
        'ad\.mail\.ru',
    ];
    $domainsRegex = implode('|', $trackingDomains);
    $html = preg_replace('#<script[^>]*src=["\'](?:https?:)?//(?:' . $domainsRegex . ')[^"\']*["\'][^>]*>\s*</script>#i', '', $html);

    // 9d. Remove tracking meta tags
    $html = preg_replace('#<meta\s+name="google-site-verification"[^>]*>#i', '', $html);
    $html = preg_replace('#<meta\s+name="yandex-verification"[^>]*>#i', '', $html);
    $html = preg_replace('#<meta\s+name="msvalidate\.01"[^>]*>#i', '', $html);
    $html = preg_replace('#<meta\s+name="facebook-domain-verification"[^>]*>#i', '', $html);
    $html = preg_replace('#<meta\s+property="fb:app_id"[^>]*>#i', '', $html);

    // 9e. Remove tracking link tags (preconnect/dns-prefetch to tracking domains)
    $html = preg_replace('#<link[^>]*(?:googletagmanager|google-analytics|mc\.yandex|mail\.ru|facebook\.net|hotjar|clarity\.ms)[^>]*>#i', '', $html);

    // 9f. Remove yandex-tableau-widget and manifest links (leak domain info)
    $html = preg_replace('#<link\s+rel="yandex-tableau-widget"[^>]*>#i', '', $html);
    $html = preg_replace('#<link\s+rel="manifest"[^>]*bestchange[^>]*>#i', '', $html);

    // 9g. Remove 1x1 tracking pixel images
    $html = preg_replace('#<img[^>]*(?:width=["\']1["\']|height=["\']1["\'])[^>]*(?:mail\.ru|yandex|counter|pixel|track)[^>]*/?\s*>#i', '', $html);
    $html = preg_replace('#<img[^>]*(?:mail\.ru|yandex|counter|pixel|track)[^>]*(?:width=["\']1["\']|height=["\']1["\'])[^>]*/?\s*>#i', '', $html);

    // 9h. Remove any remaining inline event handlers that call tracking (onclick="ym(...)")
    $html = preg_replace('#\s+on\w+="[^"]*(?:gtag|ym|fbq|_tmr|yaCounter)[^"]*"#i', '', $html);

    // 9i. Remove hreflang/alternate/canonical links pointing to bestchange.com/bestchange.ru
    $html = preg_replace('#<link\s+rel="alternate"[^>]*bestchange\.[a-z]+[^>]*>#i', '', $html);
    $html = preg_replace('#<link\s+rel="canonical"[^>]*bestchange\.[a-z]+[^>]*>#i', '', $html);

    // 9j. Remove "English" footer links that point to bestchange.com
    $html = preg_replace('#<a[^>]*href="https?://[^"]*bestchange\.com[^"]*"[^>]*>[^<]*English[^<]*</a>\s*\|?#i', '', $html);

    // 10. Add obfuscation script
    $html = str_replace('</body>', getObfuscationScript() . "\n</body>", $html);

    return $html;
}

function buildExchangePage($fromId, $toId, $fromSlug = null, $toSlug = null) {
    $from = getCurrency($fromId);
    $to = getCurrency($toId);
    if (!$from || !$to) return null;
    $fName = htmlspecialchars($fromSlug ? slugToDisplayName($fromSlug) : $from['name']);
    $tName = htmlspecialchars($toSlug ? slugToDisplayName($toSlug) : $to['name']);

    // Generate rates ONCE
    $rates = generateRates($fromId, $toId);
    $cnt = count($rates);

    // Build table rows from rates
    $rows = '';
    foreach ($rates as $i => $rate) {
        $altClass = ($i % 2 === 1) ? ' class="alt"' : '';
        $give = formatNumber($rate['give']);
        $receive = formatNumber($rate['receive']);
        $reserve = formatNumber($rate['reserve']);
        $fT = htmlspecialchars($from['ticker']);
        $tT = htmlspecialchars($to['ticker']);
        $exName = htmlspecialchars($rate['exchangerName']);
        $reviews = abs((int)$rate['reviews']);
        $linkUrl = !empty($rate['linkUrl']) ? htmlspecialchars($rate['linkUrl']) : '#';
        $rows .= '<tr' . $altClass . '>'
            . '<td class="ir"><span class="io" id="io' . $i . '"></span></td>'
            . '<td class="bj"><div class="pa"><a rel="nofollow" target="_blank" href="' . $linkUrl . '"></a><div class="pc"><div class="ca" translate="no">' . $exName . '</div></div></div></td>'
            . '<td class="bi"><div class="fs">' . $give . ' <small translate="no">' . $fT . '</small></div></td>'
            . '<td class="bi">' . $receive . ' <small translate="no">' . $tT . '</small></td>'
            . '<td class="ar arp">' . $reserve . '</td>'
            . '<td class="rw"><a href="#" class="rwan">' . $reviews . '</a></td>'
            . "</tr>\n";
    }

    $html = getBaseHtml();

    // Set lc_curr and rc_curr BEFORE the init runs, so corr_tab highlights the selected currencies
    // Replace the default "var lc_curr = 0; var rc_curr = 0;" with the actual currency IDs
    $html = str_replace('var lc_curr = 0;' . "\n" . 'var rc_curr = 0;',
                         'var lc_curr = ' . (int)$fromId . ';' . "\n" . 'var rc_curr = ' . (int)$toId . ';',
                         $html);

    $html = replaceTableBody($html, $rows);
    $html = preg_replace('#<title>[^<]*</title>#', '<title>Обмен ' . $fName . ' на ' . $tName . ' | BestChange</title>', $html, 1);
    $newIntro = '<h1>Обмен ' . $fName . ' на ' . $tName . '</h1>'
        . '<p>Лучшие курсы обмена ' . $fName . ' (' . htmlspecialchars($from['ticker']) . ') на '
        . $tName . ' (' . htmlspecialchars($to['ticker']) . ') от ' . $cnt . ' проверенных обменников.</p>';
    $html = replaceSmallText($html, $newIntro);
    $html = str_replace('</body>', getInteractiveScript() . "\n" . getObfuscationScript() . "\n</body>", $html);
    return $html;
}
