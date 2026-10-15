/* DirDocs 前台行为脚本（原生 JS；运行于 Bootstrap 5.3 之上，bundle 由模板先引入）
 *
 * 页面模板不含任何内联 <script>（CSP 才能收紧到 script-src 'self'），
 * 全部前台行为都收在这里：
 *   1. 阅读进度条（scroll + rAF 节流）
 *   2. 主题双态切换（light ↔ dark，写 localStorage.dd-theme，跟随 data-bs-theme）
 *   3. 移动抽屉树克隆（桌面树复制进 offcanvas，开合交给 Bootstrap offcanvas）
 *   4. TOC 滚动高亮（IntersectionObserver，回退 scroll+getBoundingClientRect）
 *   5. code-deck 升级：头栏（语言 + 复制）+ 行号列
 *   6. h2 标题锚点注入 + 点击复制完整链接
 *   7. 反馈胶囊（localStorage 按 docRel 记忆，可取消；匿名计数上报 ?a=feedback，失败静默）
 *   8. 快捷键：Ctrl/Cmd+K 或 / 聚焦搜索
 *   9. mermaid 按需加载（深浅跟随 data-bs-theme）
 *  10. 站外链接：新窗口 + noopener/noreferrer + 外链小图标
 *  11. 图片灯箱（lightbox）：正文图片与附件缩略图页内预览，不再新开窗口
 * 每个功能独立 try/catch：任何一步失败都不允许把页面搞挂。
 */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;

  /* i18n：界面文案词典由 PHP 注入 <html data-md-i18n="JSON">（md_i18n_js_attr）。
   * 属性缺失（用户自改的旧模板）时回落各自的中文兜底，行为与 v1.9 前一致。 */
  var I18N = {};
  try { I18N = JSON.parse(root.getAttribute('data-md-i18n') || '{}') || {}; } catch (e) { I18N = {}; }
  function mdT(key, fb) {
    var v = I18N[key];
    return (typeof v === 'string' && v !== '') ? v : fb;
  }

  /** 从事件目标向上找匹配选择器的祖先（事件委托用） */
  function closestBy(node, attr, val) {
    while (node && node !== doc) {
      if (node.getAttribute && node.getAttribute(attr) === val) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  /** 剪贴板写入：clipboard API 优先，失败回退隐藏 textarea + execCommand */
  function copyText(text, done) {
    var fallback = function () {
      try {
        var ta = doc.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        doc.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = doc.execCommand('copy'); } catch (e2) { ok = false; }
        doc.body.removeChild(ta);
        done(ok);
      } catch (e) {
        done(false);
      }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, fallback);
    } else {
      fallback();
    }
  }

  /* ---------------- 1. 阅读进度条（fixed top:var(--nav-h)，2px，accent） ---------------- */
  (function progressBar() {
    var bar = doc.getElementById('scrollProgress');
    if (!bar) return;
    var ticking = false;
    function update() {
      ticking = false;
      try {
        var de = doc.documentElement;
        var total = de.scrollHeight - de.clientHeight;
        var pct = total > 0 ? (window.pageYOffset || de.scrollTop || 0) / total * 100 : 0;
        if (pct < 0) pct = 0;
        if (pct > 100) pct = 100;
        bar.style.width = pct + '%';
      } catch (e) { /* 忽略 */ }
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      if (window.requestAnimationFrame) window.requestAnimationFrame(update);
      else update();
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    update();
  })();

  /* ---------------- 2. 主题双态切换（light ↔ dark） ----------------
   * 初始值由 theme-init.js 决定（存储优先 → 站点配置 → 系统偏好），
   * 这里只负责按钮：点击后取反并写存储；图标显隐由 CSS 按 data-bs-theme 控制。 */
  (function themeToggle() {
    function paint() {
      var dark = root.getAttribute('data-bs-theme') === 'dark';
      var btn = doc.querySelector('[data-md-toggle="theme"]');
      if (btn) {
        var label = dark ? mdT('js_theme_dark', '主题：深色（点击切换浅色）') : mdT('js_theme_light', '主题：浅色（点击切换深色）');
        btn.setAttribute('title', label);
        btn.setAttribute('aria-label', label);
      }
    }
    paint();
    doc.addEventListener('click', function (ev) {
      try {
        var t = closestBy(ev.target, 'data-md-toggle', 'theme');
        if (!t) return;
        var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('dd-theme', next); } catch (e) { /* 写不进就只切本次会话 */ }
        paint();
      } catch (e) { /* 忽略 */ }
    });
  })();

  /* ---------------- 3. 移动抽屉树：桌面树克隆进 offcanvas ----------------
   * 结构无 id（目录折叠用原生 details），克隆即用；开合/返回由 Bootstrap JS 接管。 */
  (function mobileTree() {
    try {
      var src = doc.getElementById('md-nav-tree');
      var dst = doc.getElementById('mobile-tree-target');
      if (!src || !dst) return;
      var clone = src.cloneNode(true);
      if (clone.id) clone.removeAttribute('id');
      clone.setAttribute('aria-label', mdT('js_doc_nav_aria', '文档目录'));
      dst.appendChild(clone);
    } catch (e) { /* 克隆失败只影响抽屉内容，不影响页面 */ }
  })();

  /* ---------------- 3b. 移动端本页大纲弹层（FAB → offcanvas-bottom） ----------------
   * 把桌面 TOC 克隆进底部弹层并保留 .md-toc 类（滚动高亮会一并联动）；
   * 无大纲的页面自动隐藏 FAB。必须在 scrollspy 初始化之前执行。 */
  (function tocSheet() {
    try {
      var target = doc.getElementById('mobile-toc-target');
      var fab = doc.querySelector('.md-fab');
      var src = doc.querySelector('.md-toc');
      if (!target || !fab) return;
      if (!src || !src.querySelectorAll('a[href^="#"]').length) {
        // 内联 !important：压过样式表里 992–1279px 的 FAB 补位规则（同权重 !important）
        fab.style.setProperty('display', 'none', 'important');
        return;
      }
      var clone = doc.createElement('nav');
      clone.className = 'md-toc';                      // 保类名：scrollspy 高亮两份同步
      clone.setAttribute('aria-label', mdT('js_toc_mobile_aria', '本页大纲（移动端）'));
      clone.innerHTML = src.innerHTML;                 // 服务端渲染结构，无用户原始 HTML
      target.appendChild(clone);
      // 点大纲项：页面跳锚点后自动收起弹层
      clone.addEventListener('click', function (ev) {
        var node = ev.target;
        while (node && node !== clone && node.tagName !== 'A') node = node.parentNode;
        if (!node || node.tagName !== 'A') return;
        try {
          var panel = doc.getElementById('mobileToc');
          if (panel && window.bootstrap && bootstrap.Offcanvas) {
            bootstrap.Offcanvas.getOrCreateInstance(panel).hide();
          }
        } catch (e) { /* bootstrap 未就绪时仅跳转不收起 */ }
      });
    } catch (e) { /* 弹层不可用只影响移动端大纲，不影响页面 */ }
  })();

  /* ---------------- 4. TOC 滚动高亮 + 进度导轨（.md-toc a.on） ---------------- */
  (function scrollspy() {
    var scopes = doc.querySelectorAll('.md-toc');
    if (!scopes.length) return;
    var links = [];
    for (var s = 0; s < scopes.length; s++) {
      var ls = scopes[s].querySelectorAll('a[href^="#"]');
      for (var j = 0; j < ls.length; j++) links.push(ls[j]);
    }
    if (!links.length) return;

    var items = [];
    var dItems = [];                                   // 桌面侧栏的条目（进度导轨用）
    for (var i = 0; i < links.length; i++) {
      try {
        var href = links[i].getAttribute('href') || '';
        var id = decodeURIComponent(href.slice(1));
        var h = id !== '' ? doc.getElementById(id) : null;
        if (h) {
          var entry = { id: id, el: h, link: links[i] };
          items.push(entry);
          if (links[i].closest && links[i].closest('.doc-toc')) dItems.push(entry);
        }
      } catch (e) { /* 单条解析失败跳过 */ }
    }
    if (!items.length) return;

    /* 桌面侧栏：注入连续进度导轨（1px 发丝线上的 2px accent 段随阅读伸长）
       与「回到顶部」。移动弹层的克隆发生在本模块之前，天然不会带上这两样。 */
    var rail = null;
    var deskNav = null;
    for (var d = 0; d < scopes.length; d++) {
      if (scopes[d].closest && scopes[d].closest('.doc-toc')) { deskNav = scopes[d]; break; }
    }
    if (deskNav && dItems.length) {
      try {
        var tul = deskNav.querySelector('ul');
        if (tul) {
          tul.insertAdjacentHTML('beforeend', '<span class="md-toc-rail" aria-hidden="true"></span>');
          rail = tul.querySelector('.md-toc-rail');
        }
        var toTop = doc.createElement('a');
        toTop.href = '#';
        toTop.className = 'md-toc-top';
        toTop.innerHTML = '<i class="bi bi-arrow-up-short" aria-hidden="true"></i>' + mdT('js_back_top', '回到顶部');
        toTop.addEventListener('click', function (ev) {
          ev.preventDefault();
          try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e2) { window.scrollTo(0, 0); }
        });
        deskNav.parentNode.appendChild(toTop);
      } catch (e) { /* 导轨注入失败只影响装饰，不影响高亮 */ }
    }

    var current = '';
    function paintRail() {
      if (!rail || !dItems.length) return;
      var base = dItems[0].link.offsetTop;
      var end = base;
      for (var i = 0; i < dItems.length; i++) {
        if (dItems[i].id === current) {
          end = dItems[i].link.offsetTop + dItems[i].link.offsetHeight;
          break;
        }
      }
      rail.style.top = base + 'px';
      rail.style.height = Math.max(2, end - base) + 'px';
    }
    function paint() {
      for (var i = 0; i < items.length; i++) {
        if (items[i].id === current) items[i].link.classList.add('on');
        else items[i].link.classList.remove('on');
      }
      paintRail();
    }
    // 视口顶栏之下、最靠上的已越过标题视为当前；一个都没有则取第一项
    function pick() {
      var best = '';
      var mark = 88;
      for (var i = 0; i < items.length; i++) {
        if (items[i].el.getBoundingClientRect().top <= mark) best = items[i].id;
        else break;                                   // 文档序遍历，后面只会更靠下
      }
      if (best === '' && items.length) best = items[0].id;
      return best;
    }
    function set(id) {
      if (id !== current) { current = id; paint(); }
    }

    /* 滚动方案（唯一路径）：曾用 IntersectionObserver 优先，但实测部分内嵌
       WebView 的 IO 完全不派发回调（spy 永远停在第一项）；scroll+节流在所有
       环境行为一致，100ms 节流成本可忽略，故统一走这里。 */
    var timer = null;
    var onScroll = function () {
      if (timer) return;
      timer = setTimeout(function () {
        timer = null;
        set(pick());
      }, 100);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    onScroll();
  })();

  /* ---------------- 5. code-deck：头栏（语言 + 复制）+ 行号 ----------------
   * 把 pre>code 包进 .code-deck：header(语言名 + Copy) + .code-body-wrapper
   * (.code-line-numbers 右对齐/禁选 + pre)。行号按 code.textContent 行数建 span；
   * 空行也编号；mermaid 与已包装块跳过。JS 失效时代码块仍是恒暗圆角块（CSS 兜底）。 */
  (function codeDecks() {
    var codes;
    try {
      codes = doc.querySelectorAll('pre > code');
    } catch (e) {
      return;
    }
    for (var i = 0; i < codes.length; i++) {
      try {
        var code = codes[i];
        var pre = code.parentNode;
        if (!pre || pre.tagName !== 'PRE') continue;
        if (pre.classList.contains('mermaid')) continue;         // 图表块不包装
        if (pre.parentNode && pre.parentNode.classList &&
            (pre.parentNode.classList.contains('code-deck') ||
             pre.parentNode.classList.contains('code-body-wrapper'))) continue; // 防重复包装

        var m = /language-([\w+#.-]+)/.exec(code.className);
        var deck = doc.createElement('div');
        deck.className = 'code-deck';

        // 头栏：左侧语言名，右侧复制按钮
        var header = doc.createElement('div');
        header.className = 'code-deck-header';
        var lang = doc.createElement('span');
        lang.className = 'md-deck-lang';
        lang.textContent = (m && m[1]) ? m[1] : 'text';

        var btn = doc.createElement('button');
        btn.type = 'button';
        btn.className = 'md-copy';
        btn.setAttribute('aria-label', mdT('js_copy_code_aria', '复制代码'));
        var ico = doc.createElement('i');
        ico.className = 'bi bi-copy';
        ico.setAttribute('aria-hidden', 'true');
        var txt = doc.createElement('span');
        txt.textContent = mdT('js_copy', '复制');
        btn.appendChild(ico);
        btn.appendChild(txt);

        (function (code, txt, btn) {
          btn.addEventListener('click', function () {
            copyText(code.textContent || '', function (ok) {
              txt.textContent = ok ? mdT('js_copied', '已复制') : mdT('js_copy_fail', '复制失败');
              btn.classList.toggle('ok', !!ok);
              setTimeout(function () {
                txt.textContent = mdT('js_copy', '复制');
                btn.classList.remove('ok');
              }, 2000);
            });
          });
        })(code, txt, btn);

        header.appendChild(lang);
        header.appendChild(btn);

        // 行号列：textContent 行数逐行建 span（空行也编号）
        var wrapper = doc.createElement('div');
        wrapper.className = 'code-body-wrapper';
        var gutter = doc.createElement('div');
        gutter.className = 'code-line-numbers';
        gutter.setAttribute('aria-hidden', 'true');
        var lineCount = (code.textContent || '').split('\n').length;
        for (var n = 1; n <= lineCount; n++) {
          var s = doc.createElement('span');
          s.textContent = String(n);
          gutter.appendChild(s);
        }

        wrapper.appendChild(gutter);
        deck.appendChild(header);
        deck.appendChild(wrapper);
        // 先把 deck 插入文档，再把 pre 移入 wrapper（顺序反了会插进游离节点，代码块整体消失）
        pre.parentNode.insertBefore(deck, pre);
        wrapper.appendChild(pre);
      } catch (e) { /* 单个代码块失败不影响其余 */ }
    }
  })();

  /* ---------------- 6. h2 标题锚点（hover 显现，点击复制完整锚点 URL） ---------------- */
  (function headerAnchors() {
    var heads;
    try {
      heads = doc.querySelectorAll('.md-doc h2[id]');
    } catch (e) {
      return;
    }
    for (var i = 0; i < heads.length; i++) {
      (function (h) {
        var a = doc.createElement('a');
        a.className = 'header-anchor';
        a.href = '#' + h.id;
        a.setAttribute('title', mdT('js_copy_link', '复制段落链接'));
        a.setAttribute('aria-label', mdT('js_copy_link', '复制段落链接'));
        var ico = doc.createElement('i');
        ico.className = 'bi bi-link-45deg';
        ico.setAttribute('aria-hidden', 'true');
        a.appendChild(ico);
        a.addEventListener('click', function () {
          // 完整锚点 URL = 去掉旧锚点的地址 + #id；默认跳转保留（滚动归位无害）
          var base = location.href.split('#')[0];
          copyText(base + '#' + h.id, function (ok) {
            if (!ok) return;
            ico.className = 'bi bi-check2';
            a.classList.add('ok');
            a.setAttribute('title', mdT('js_copied', '已复制'));
            setTimeout(function () {
              ico.className = 'bi bi-link-45deg';
              a.classList.remove('ok');
              a.setAttribute('title', mdT('js_copy_link', '复制段落链接'));
            }, 1600);
          });
        });
        h.appendChild(a);
      })(heads[i]);
    }
  })();

  /* ---------------- 7. 反馈胶囊（赞/踩：本地即时记忆 + 匿名计数上报，上报失败静默回落本地） ---------------- */
  (function feedback() {
    var box = doc.querySelector('.md-feedback[data-doc-rel]');
    if (!box) return;
    var rel = box.getAttribute('data-doc-rel');
    if (!rel) return;
    var key = 'dd-fb:' + rel;
    var url = box.getAttribute('data-fb-url') || '';
    var tok = box.getAttribute('data-fb-token') || '';
    var btns = {
      up: box.querySelector('[data-md-feedback="up"]'),
      down: box.querySelector('[data-md-feedback="down"]')
    };
    if (!btns.up || !btns.down) return;

    function read() {
      try {
        var v = localStorage.getItem(key);
        return (v === 'up' || v === 'down') ? v : '';
      } catch (e) {
        return '';
      }
    }
    function paint(state) {
      var upIco = btns.up.querySelector('.bi');
      var downIco = btns.down.querySelector('.bi');
      if (upIco) upIco.className = state === 'up' ? 'bi bi-hand-thumbs-up-fill' : 'bi bi-hand-thumbs-up';
      if (downIco) downIco.className = state === 'down' ? 'bi bi-hand-thumbs-down-fill' : 'bi bi-hand-thumbs-down';
      btns.up.classList.toggle('is-up-on', state === 'up');
      btns.down.classList.toggle('is-down-on', state === 'down');
      btns.up.setAttribute('aria-pressed', state === 'up' ? 'true' : 'false');
      btns.down.setAttribute('aria-pressed', state === 'down' ? 'true' : 'false');
    }
    /** 匿名计数上报：服务器按 IP 限流聚合，失败（断网/只读/限流）不影响本地记忆。 */
    function report(v) {
      if (!url || !tok || typeof XMLHttpRequest === 'undefined') return;
      try {
        var x = new XMLHttpRequest();
        x.open('POST', url, true);
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        x.send('p=' + encodeURIComponent(rel) + '&v=' + encodeURIComponent(v) + '&t=' + encodeURIComponent(tok));
      } catch (e) { /* 静默：上报是渐进增强 */ }
    }
    paint(read());

    box.addEventListener('click', function (ev) {
      try {
        var b = closestBy(ev.target, 'data-md-feedback', 'up') || closestBy(ev.target, 'data-md-feedback', 'down');
        if (!b) return;
        var v = b.getAttribute('data-md-feedback');
        var cur = read();
        var next = cur === v ? '' : v;                // 再点一次取消；点另一个则切换
        try {
          if (next) localStorage.setItem(key, next);
          else localStorage.removeItem(key);
        } catch (e) { /* 存不进就只切本次会话 */ }
        paint(next);
        report(next);
      } catch (e) { /* 忽略 */ }
    });
  })();

  /* ---------------- 8. 快捷键：/ 聚焦侧栏搜索框（Ctrl+K 已让位给模块 11 弹窗，v1.15.0） ---------------- */
  (function searchShortcut() {
    doc.addEventListener('keydown', function (ev) {
      try {
        var k = (ev.key || '').toLowerCase();
        var isSlash = ev.key === '/' && !ev.ctrlKey && !ev.metaKey && !ev.altKey;
        if (!isSlash) return;
        var t = ev.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
        var box = doc.querySelector('.md-searchbox input');
        if (!box) return;
        ev.preventDefault();
        box.focus();
        box.select();
      } catch (e) { /* 忽略 */ }
    });
  })();

  /* ---------------- 9. mermaid 按需加载（深浅跟随 data-bs-theme） ---------------- */
  (function mermaidBoot() {
    var url = doc.body ? doc.body.getAttribute('data-mermaid') : '';
    if (!url) return;
    var pres;
    try {
      pres = doc.querySelectorAll('pre.mermaid');
    } catch (e) {
      return;
    }
    if (!pres.length) return;

    function failAll() {
      for (var i = 0; i < pres.length; i++) {
        try {
          if (!pres[i].querySelector('.mermaid-box')) {
            pres[i].innerHTML = '<div class="mermaid-box"><pre class="mermaid-err"></pre></div>';
            pres[i].querySelector('.mermaid-err').textContent = mdT('js_chart_fail', '图表渲染失败');
          }
        } catch (e) { /* 忽略 */ }
      }
    }

    var script = doc.createElement('script');
    script.src = url;
    script.async = true;
    script.onerror = failAll;                          // 加载失败/图表库被删（slim 安装）：明确报错而非静默卡源码
    script.onload = function () {
      try {
        if (typeof mermaid === 'undefined') throw new Error('mermaid missing');
        var dark = root.getAttribute('data-bs-theme') === 'dark';
        mermaid.initialize({
          startOnLoad: false,
          theme: dark ? 'dark' : 'default',
          securityLevel: 'strict'
        });
        for (var i = 0; i < pres.length; i++) {
          (function (el, id) {
            var src = el.textContent;
            el.textContent = '';
            var box = doc.createElement('div');
            box.className = 'mermaid-box';
            el.appendChild(box);
            var fail = function () {
              box.innerHTML = '<pre class="mermaid-err"></pre>';
              box.querySelector('.mermaid-err').textContent = mdT('js_chart_fail', '图表渲染失败');
            };
            try {
              Promise.resolve(mermaid.render('mmd-' + id, src)).then(function (r) {
                box.innerHTML = r.svg;
              })['catch'](fail);
            } catch (e) {
              fail();
            }
          })(pres[i], i);
        }
      } catch (e) {
        failAll();
      }
    };
    script.onerror = failAll;
    try {
      doc.head.appendChild(script);
    } catch (e) { /* 忽略 */ }
  })();

  /* ---------------- 10. 站外链接：新窗口 + noopener + 外链小图标 ----------------
   * 文档站惯例（Docusaurus/VitePress 均自动处理）：正文里的 http(s) 站外链接
   * 一律新窗口打开并补 rel，防止阅读上下文丢失；尾部补一个小箭头图标提示离站。
   * 图片链接不加图标（图片走 11 号灯箱，图标会破坏观感）。 */
  (function externalLinks() {
    var links;
    try {
      links = doc.querySelectorAll('.md-doc a[href]');
    } catch (e) {
      return;
    }
    for (var i = 0; i < links.length; i++) {
      try {
        var a = links[i];
        var href = a.getAttribute('href') || '';
        if (!/^https?:\/\//i.test(href)) continue;        // 只处理绝对 http(s) 外链
        var probe = doc.createElement('a');
        probe.href = href;                                // 借 <a> 解析 host（无需正则拼 URL）
        if (probe.host === location.host) continue;       // 站内链接（含 ?a=file 附件）不动
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
        if (a.querySelector('img')) continue;             // 图片外链不加图标
        var ico = doc.createElement('i');
        ico.className = 'bi bi-box-arrow-up-right md-ext-ico';
        ico.setAttribute('aria-hidden', 'true');
        a.appendChild(ico);
      } catch (e) { /* 单条失败不影响其余链接 */ }
    }
  })();

  /* ---------------- 11. 图片灯箱（lightbox） ----------------
   * 正文内图片（.md-doc img）与附件图片缩略图（.md-attach-img 里的链接）点击
   * 一律不开新窗口/不跳转，改为页内弹层预览：
   *   - 深色遮罩 + 居中大图（CSS 限 92vw/86vh），底部显示 alt/文件名 caption
   *   - 点击遮罩/图片外区域、右上角关闭按钮、ESC 三种方式关闭
   *   - 加载失败显示占位提示；灯箱内禁用拖拽默认行为
   *   - role="dialog" + aria-modal，打开时焦点移入、关闭还原，body 加 .md-lock 滚动锁
   *   - 链接包图片（[![](…)](…)）时 preventDefault 阻断外层跳转，仍开灯箱
   * DOM 懒创建（首次打开才插 body）：用户主题模板无需任何改动。
   * 图片 src 直接复用现有 ?a=file URL（服务端对 png/jpg 等是 inline 输出）。 */
  (function lightbox() {
    var box = null, img = null, cap = null, err = null;
    var lastFocus = null;
    var isOpen = false;

    /** 懒创建弹层 DOM 并挂好自身的事件（只建一次） */
    function ensureDom() {
      if (box) return;
      box = doc.createElement('div');
      box.className = 'md-lightbox';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-label', mdT('js_preview_title', '图片预览'));
      box.setAttribute('aria-hidden', 'true');

      var closeBtn = doc.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'md-lightbox-close';
      closeBtn.setAttribute('aria-label', mdT('js_preview_close', '关闭预览'));
      var ci = doc.createElement('i');
      ci.className = 'bi bi-x-lg';
      ci.setAttribute('aria-hidden', 'true');
      closeBtn.appendChild(ci);

      var fig = doc.createElement('figure');
      fig.className = 'md-lightbox-body';
      img = doc.createElement('img');
      img.className = 'md-lightbox-img';
      img.alt = '';
      img.draggable = false;
      cap = doc.createElement('figcaption');
      cap.className = 'md-lightbox-cap';
      err = doc.createElement('div');
      err.className = 'md-lightbox-err';
      err.textContent = mdT('js_img_fail', '图片加载失败，请稍后重试');
      err.style.display = 'none';
      fig.appendChild(img);
      fig.appendChild(cap);
      fig.appendChild(err);

      box.appendChild(closeBtn);
      box.appendChild(fig);
      doc.body.appendChild(box);

      // 关闭按钮 / 遮罩点击（点图片本身不关）；closeBtn 的点击冒泡到这里也走 close，幂等
      box.addEventListener('click', function (ev) {
        if (ev.target === img || (img.contains && img.contains(ev.target))) return;
        close();
      });
      // 灯箱内禁用拖拽默认（避免把大图拖出页面）
      img.addEventListener('dragstart', function (ev) { ev.preventDefault(); });
      // 加载成功/失败的状态切换
      img.addEventListener('load', function () {
        if (!isOpen) return;
        img.style.display = '';
        err.style.display = 'none';
      });
      img.addEventListener('error', function () {
        if (!isOpen) return;
        img.style.display = 'none';
        err.style.display = '';
        cap.style.display = 'none';
      });

      // 键盘：ESC 关闭；Tab 留在弹层内（弹层里唯一可聚焦元素是关闭按钮）
      doc.addEventListener('keydown', function (ev) {
        if (!isOpen) return;
        if (ev.key === 'Escape' || ev.key === 'Esc') {
          ev.preventDefault();
          close();
        } else if (ev.key === 'Tab') {
          ev.preventDefault();
        }
      });
    }

    function open(src, caption) {
      ensureDom();
      lastFocus = doc.activeElement;
      isOpen = true;
      img.style.display = '';
      err.style.display = 'none';
      var t = caption || '';
      cap.textContent = t;
      cap.style.display = t ? '' : 'none';
      img.alt = t || mdT('js_preview_img', '预览图片');
      img.src = src;
      box.classList.add('open');
      box.setAttribute('aria-hidden', 'false');
      doc.body.classList.add('md-lock');                 // 滚动锁（CSS 定义 .md-lock）
      try { box.querySelector('.md-lightbox-close').focus(); } catch (e) { /* 忽略 */ }
    }

    function close() {
      if (!isOpen) return;
      isOpen = false;
      box.classList.remove('open');
      box.setAttribute('aria-hidden', 'true');
      doc.body.classList.remove('md-lock');
      try { if (lastFocus && lastFocus.focus) lastFocus.focus(); } catch (e) { /* 忽略 */ }
    }

    // 事件委托：拦截正文图片与附件缩略图的点击（冒泡阶段，stopPropagation 断掉外层 <a>）
    doc.addEventListener('click', function (ev) {
      try {
        var t = ev.target;
        if (!t || !t.closest || (box && box.contains(t))) return;  // 灯箱内部由上面的监听处理
        var hit = null;
        var attachA = t.closest('.md-attach-img a[href]');         // 附件缩略图：链接优先
        var inlineImg = t.closest('.md-doc img');                  // 正文图片（含链接包图片）
        if (attachA) {
          var th = attachA.querySelector('img');
          hit = {
            src: attachA.getAttribute('href'),
            cap: attachA.getAttribute('title') || (th ? (th.getAttribute('alt') || '') : '')
          };
        } else if (inlineImg) {
          hit = { src: inlineImg.getAttribute('src'), cap: inlineImg.getAttribute('alt') || '' };
        }
        if (!hit || !hit.src) return;
        ev.preventDefault();       // 普通图片无副作用；链接包图片时阻断跳转/新窗口
        ev.stopPropagation();
        open(hit.src, hit.cap);
      } catch (e) { /* 忽略 */ }
    });
  })();

  /* ---------------- 11. Ctrl/Cmd+K 快速搜索弹窗（v1.15.0，数据源 ?a=suggest，复用全文索引） ---------------- */
  (function kbdSearch() {
    function t(k, fb) { return (typeof mdT === 'function') ? mdT(k, fb) : fb; }
    var box = null, input = null, list = null, items = [], sel = -1, timer = null, seq = 0;

    function close() {
      if (box && box.parentNode) { box.parentNode.removeChild(box); }
      box = null; input = null; list = null; items = []; sel = -1;
    }
    function mark(i) {
      sel = i;
      var as = list ? list.querySelectorAll('.md-kbd-item') : [];
      for (var k = 0; k < as.length; k++) { as[k].className = 'md-kbd-item' + (k === i ? ' on' : ''); }
    }
    function renderRows(rs) {
      items = rs; sel = -1;
      list.innerHTML = '';
      if (!rs.length) {
        var e = doc.createElement('div');
        e.className = 'md-kbd-empty';
        e.textContent = t('js_kbd_empty', '没有匹配的文档');
        list.appendChild(e);
        return;
      }
      rs.forEach(function (r, i) {
        var a = doc.createElement('a');
        a.className = 'md-kbd-item';
        a.href = r.u;
        a.textContent = r.t;
        a.addEventListener('mousemove', function () { mark(i); });
        list.appendChild(a);
      });
    }
    function fetch(q, mySeq) {
      var x = new XMLHttpRequest();
      x.open('GET', '?a=suggest&q=' + encodeURIComponent(q), true);
      x.onreadystatechange = function () {
        if (x.readyState !== 4 || mySeq !== seq) return;
        var rs = [];
        try { rs = JSON.parse(x.responseText) || []; } catch (e) { /* 静默：弹窗是渐进增强 */ }
        renderRows(rs);
      };
      x.send();
    }
    function openPalette() {
      if (box) { input.focus(); return; }
      box = doc.createElement('div');
      box.className = 'md-kbd';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      var ph = t('js_kbd_ph', '搜索文档…');
      box.innerHTML = '<div class="md-kbd-card"><input class="md-kbd-input" type="search" placeholder="'
        + ph + '" aria-label="' + ph + '"><div class="md-kbd-list"></div>'
        + '<div class="md-kbd-hint">Enter ' + t('js_kbd_open', '打开') + ' · Esc '
        + t('js_kbd_close', '关闭') + '</div></div>';
      doc.body.appendChild(box);
      input = box.querySelector('.md-kbd-input');
      list = box.querySelector('.md-kbd-list');
      box.addEventListener('mousedown', function (ev) { if (ev.target === box) { close(); } });
      input.addEventListener('input', function () {
        var q = input.value.replace(/^\s+|\s+$/g, '');
        if (timer) { clearTimeout(timer); timer = null; }
        if (q === '') { renderRows([]); return; }
        timer = setTimeout(function () { seq++; fetch(q, seq); }, 200);
      });
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { close(); return; }
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
          ev.preventDefault();
          if (!items.length) return;
          var d = ev.key === 'ArrowDown' ? 1 : -1;
          mark((sel + d + items.length) % items.length);
        } else if (ev.key === 'Enter') {
          if (sel >= 0 && items[sel]) { location.href = items[sel].u; }
        }
      });
      renderRows([]);
      input.focus();
    }
    doc.addEventListener('keydown', function (ev) {
      var k = (ev.key || '').toLowerCase();
      if ((ev.ctrlKey || ev.metaKey) && k === 'k') {
        var tg = ev.target;
        if (tg && (tg.tagName === 'INPUT' || tg.tagName === 'TEXTAREA' || tg.isContentEditable) && !box) return;
        ev.preventDefault();
        if (box) { close(); } else { openPalette(); }
      }
    });
  })();

  /* ---------------- 12. 评论（v1.17.0）：回复切换 + XHR 提交；无 JS 走原生 POST + 回跳闪信 ---------------- */
  (function comments() {
    var form = doc.querySelector('.md-cmt-form');
    if (!form) return;
    var rtInput = form.querySelector('[data-cmt-rt]');
    var chip = form.querySelector('.md-cmt-replying');
    var chipName = chip ? chip.querySelector('[data-cmt-rt-name]') : null;

    function climb(node, attr) {
      while (node && node !== doc) {
        if (node.getAttribute && node.getAttribute(attr)) return node;
        node = node.parentNode;
      }
      return null;
    }
    function setReply(id, name) {
      if (!rtInput) return;
      rtInput.value = id > 0 ? String(id) : '0';
      if (chip) {
        if (id > 0) {
          chip.hidden = false;
          if (chipName) chipName.textContent = (form.getAttribute('data-cmt-replying') || '') + name;
        } else {
          chip.hidden = true;
          if (chipName) chipName.textContent = '';
        }
      }
    }
    // 回复按钮散布在评论列表里：事件委托，向上找 data-cmt-reply
    doc.addEventListener('click', function (ev) {
      var b = climb(ev.target, 'data-cmt-reply');
      if (b) {
        setReply(parseInt(b.getAttribute('data-cmt-reply'), 10) || 0, b.getAttribute('data-cmt-author') || '');
        var body = form.querySelector('[name="body"]');
        if (body) body.focus();
        return;
      }
      if (climb(ev.target, 'data-cmt-rt-cancel')) setReply(0, '');
    });

    form.addEventListener('submit', function (ev) {
      if (typeof XMLHttpRequest === 'undefined') return;      // 原生表单提交兜底
      ev.preventDefault();
      var flash = form.querySelector('.md-cmt-flash');
      var btn = form.querySelector('[type="submit"]');
      var btnText = btn ? btn.textContent : '';
      function say(cls, text) {
        if (!flash) return;
        flash.hidden = false;
        flash.className = 'md-cmt-flash ' + cls;
        flash.textContent = text;
      }
      if (btn) {
        btn.disabled = true;
        if (form.getAttribute('data-cmt-busy')) btn.textContent = form.getAttribute('data-cmt-busy');
      }
      try {
        var x = new XMLHttpRequest();
        x.open('POST', form.getAttribute('data-cmt-url') || form.getAttribute('action'), true);
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        x.onreadystatechange = function () {
          if (x.readyState !== 4) return;
          if (btn) { btn.disabled = false; btn.textContent = btnText; }
          var okFlag = false;
          try { okFlag = JSON.parse(x.responseText).ok === true; } catch (e) { /* 非 JSON 按失败 */ }
          if (okFlag) {
            say('is-ok', form.getAttribute('data-cmt-ok') || '');
            var body = form.querySelector('[name="body"]');
            if (body) body.value = '';
            setReply(0, '');
          } else {
            say('is-err', x.status === 429
              ? (form.getAttribute('data-cmt-rate') || form.getAttribute('data-cmt-err') || '')
              : (form.getAttribute('data-cmt-err') || ''));
          }
        };
        var parts = [];
        var fields = ['p', 't', 'ts', 'hp', 'rt', 'author', 'email', 'body'];
        for (var i = 0; i < fields.length; i++) {
          var el = form.elements[fields[i]];
          if (el) parts.push(encodeURIComponent(fields[i]) + '=' + encodeURIComponent(el.value));
        }
        x.send(parts.join('&'));
      } catch (e) {
        if (btn) { btn.disabled = false; btn.textContent = btnText; }
        say('is-err', form.getAttribute('data-cmt-err') || '');
      }
    });
  })();
})();
