/* DirDocs 后台行为脚本（经 ?a=asset&t=_sys&f=admin.js 通道输出）
 * 渐进增强：脚本不可用时全部功能照常（服务端表单照常提交），本文件只做体验增强。
 * 模块划分（各自独立 try/catch，单个模块报错不影响其他模块）：
 *   1. [data-confirm] 表单：提交前二次确认（有 Bootstrap 时用 Modal，否则原生 confirm）
 *   2. [data-copy] 按钮：把属性值复制到剪贴板，按钮文案暂变「已复制」
 *   3. [data-md-toggle="theme"]：深浅主题切换（与前台 builtin.js 同机制）
 *   4. mdToast：右下角轻提示（保存结果等）
 *   5. mdToolbar：Markdown 编辑器工具条（包裹/插入语法）、Tab 缩进、Ctrl+S/B/I/K
 *   6. mdSave：编辑器 AJAX 保存（fetch + FormData，带 ajax=1），未保存标记 + 离开拦截
 *   7. mdPathSync：目录下拉 + 文件名 ↔ 完整路径预览的双向同步（编辑器与重命名页）
 *   8. mdTreeFilter：文档树的前端实时过滤（纯 DOM，不发请求）
 */
(function () {
  "use strict";

  /* i18n：文案词典由 PHP 注入 <html data-md-i18n="JSON">（md_i18n_js_attr，后台在
   * md_admin_html_head 输出）。属性缺失时回落各自中文兜底（旧自改模板行为不变）。 */
  var I18N = {};
  try { I18N = JSON.parse(document.documentElement.getAttribute("data-md-i18n") || "{}") || {}; } catch (e) { I18N = {}; }
  function mdT(key, fb) {
    var v = I18N[key];
    return (typeof v === "string" && v !== "") ? v : fb;
  }
  function mdTN(key, n, fbOne, fbOther) {   // 复数条目：词典按 _one/_other 后缀展平导出
    var v = I18N[key + (n === 1 ? "_one" : "_other")];
    var t = (typeof v === "string" && v !== "") ? v : (n === 1 ? fbOne : fbOther);
    return String(t).replace("{n}", n);
  }

  function on(node, type, fn) {
    if (node && node.addEventListener) { node.addEventListener(type, fn, false); }
  }
  function qs(root, sel) { return root.querySelector(sel); }
  function qsa(root, sel) {
    return Array.prototype.slice.call(root.querySelectorAll(sel));
  }
  function esc(s) {
    return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;")
      .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }
  // 事件委托的祖先匹配（不依赖 Element.closest，老浏览器同样可用）
  function closestBy(node, attr, val) {
    var t = node;
    while (t && t !== document) {
      if (t.nodeType === 1 && t.getAttribute(attr) !== null
        && (val === undefined || t.getAttribute(attr) === val)) { return t; }
      t = t.parentNode;
    }
    return null;
  }

  // ---- 4. Toast：右下角轻提示 ----
  var toastBox = null;
  function ensureToastBox() {
    if (toastBox) { return toastBox; }
    toastBox = document.createElement("div");
    toastBox.className = "admin-toast-box";
    toastBox.setAttribute("role", "status");
    toastBox.setAttribute("aria-live", "polite");
    document.body.appendChild(toastBox);
    return toastBox;
  }
  function toast(msg, kind) {
    try {
      var box = ensureToastBox();
      var el = document.createElement("div");
      el.className = "admin-toast" + (kind === "err" ? " admin-toast-err" : "");
      el.innerHTML = '<i class="bi bi-' + (kind === "err" ? "exclamation-circle" : "check-circle")
        + '" aria-hidden="true"></i><span>' + esc(msg) + "</span>";
      box.appendChild(el);
      window.setTimeout(function () {
        if (el.parentNode) { el.parentNode.removeChild(el); }
      }, 3200);
    } catch (e) { /* 提示失败不影响主流程 */ }
  }

  // ---- 1. data-confirm：提交前二次确认 ----
  // Bootstrap bundle 已加载（defer）时用 Modal；未加载/失败则降级原生 confirm，
  // 两条路都不放行未确认的破坏性提交。
  function nativeConfirm(msg) { return window.confirm(msg); }
  function modalConfirm(msg) {
    try {
      if (typeof window.bootstrap === "undefined" || !window.bootstrap.Modal) { return null; }
      var host = document.createElement("div");
      host.className = "admin-modal-host";
      host.innerHTML =
        '<div class="modal fade" tabindex="-1" role="dialog" aria-modal="true">' +
        '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
        '<div class="modal-header"><h5 class="modal-title">' + mdT("js_confirm_title", "请确认") + '</h5>' +
        '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' + mdT("js_confirm_cancel", "取消") + '"></button></div>' +
        '<div class="modal-body"><p class="admin-modal-msg"></p></div>' +
        '<div class="modal-footer">' +
        '<button type="button" class="btn btn-ghost btn-sm" data-bs-dismiss="modal">' + mdT("js_confirm_cancel", "取消") + '</button>' +
        '<button type="button" class="btn btn-danger btn-sm" data-ok="1">' + mdT("js_confirm_ok", "确认") + '</button>' +
        '</div></div></div></div>';
      document.body.appendChild(host);
      qs(host, ".admin-modal-msg").textContent = msg;
      var m = new window.bootstrap.Modal(qs(host, ".modal"));
      var done = false;
      var p = new Promise(function (resolve) {
        on(qs(host, "[data-ok]"), "click", function () { done = true; resolve(true); m.hide(); });
        on(qs(host, ".modal"), "hidden.bs.modal", function () {
          resolve(done);
          if (host.parentNode) { host.parentNode.removeChild(host); }
        });
      });
      m.show();
      return p;
    } catch (e) { return null; }
  }
  on(document, "submit", function (ev) {
    var f = ev.target;
    if (!f || f.nodeType !== 1) { return; }
    var msg = f.getAttribute("data-confirm");
    if (msg === null) { return; }
    var r = modalConfirm(msg);
    if (r === null) {
      if (!nativeConfirm(msg)) { ev.preventDefault(); }
      return;
    }
    ev.preventDefault();
    r.then(function (ok) {
      if (ok) {
        // 确认后真实提交：先摘掉 data-confirm，避免再次弹窗
        f.removeAttribute("data-confirm");
        if (typeof f.requestSubmit === "function") { f.requestSubmit(); }
        else { f.submit(); }
      }
    });
  }, false);

  // ---- 2. data-copy：复制引用 ----
  function fallbackCopy(text) {
    var ta = document.createElement("textarea");
    ta.value = text;
    ta.setAttribute("readonly", "");
    ta.style.position = "fixed";
    ta.style.left = "-9999px";
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }
  function copyText(text, btn) {
    var done = function () {
      if (btn.getAttribute("data-busy") === "1") { return; }
      var old = btn.textContent;
      btn.setAttribute("data-busy", "1");
      btn.textContent = mdT("js_copied", "已复制");
      window.setTimeout(function () {
        btn.textContent = old;
        btn.removeAttribute("data-busy");
      }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () {
        fallbackCopy(text);
        done();
      });
    } else {
      fallbackCopy(text);
      done();
    }
  }
  on(document, "click", function (ev) {
    var btn = closestBy(ev.target, "data-copy");
    if (btn) {
      copyText(btn.getAttribute("data-copy"), btn);
      return;
    }
    // ---- 3. 主题双态切换 ----
    var tgl = closestBy(ev.target, "data-md-toggle", "theme");
    if (tgl) {
      var next = document.documentElement.getAttribute("data-bs-theme") === "dark" ? "light" : "dark";
      document.documentElement.setAttribute("data-bs-theme", next);
      try { localStorage.setItem("dd-theme", next); } catch (e) { /* 写不进就只切本次会话 */ }
    }
  }, false);

  // ---- 8. 文档树实时过滤 ----
  (function treeFilter() {
    var input = document.getElementById("docFilter");
    var tree = document.getElementById("docTree");
    if (!input || !tree) { return; }
    var emptyTip = document.getElementById("docTreeEmpty");
    on(input, "input", function () {
      var q = input.value.trim().toLowerCase();
      var rows = qsa(tree, ".admin-tree-doc");
      var visible = 0;
      rows.forEach(function (row) {
        var hay = (row.getAttribute("data-search") || row.textContent || "").toLowerCase();
        var show = (q === "" || hay.indexOf(q) !== -1);
        row.hidden = !show;
        if (show) { visible++; }
      });
      // 命中文档的祖先目录自动展开并显示，未命中的目录收起
      qsa(tree, ".admin-tree-dir").forEach(function (d) {
        if (q === "") {
          d.hidden = false;
          if (d.hasAttribute("open")) { d.open = true; }
          return;
        }
        var hit = qsa(d, ".admin-tree-doc").some(function (r) { return !r.hidden; });
        d.hidden = !hit;
        if (hit) { d.open = true; }
      });
      if (emptyTip) { emptyTip.hidden = !(q !== "" && visible === 0); }
    });
  })();

  // ---- 7. 路径预览 ↔（目录 + 文件名）双向同步 ----
  (function pathSync() {
    function bind(ids) {
      var dir = document.getElementById(ids.dir);
      var base = document.getElementById(ids.base);
      var prev = document.getElementById(ids.preview);
      var full = document.getElementById(ids.full);
      if (!dir || !base || !prev) { return; }
      var compose = function () {
        var d = dir.value.replace(/\\/g, "/").replace(/^\/+|\/+$/g, "");
        var b = base.value.replace(/^\/+/, "");
        var rel = (d === "" ? "" : d + "/") + b;
        prev.textContent = rel === "" ? mdT("js_preview_empty", "（根目录）/文件名") : rel;
        if (full) { full.value = rel; }
      };
      on(dir, "input", compose);
      on(dir, "change", compose);
      on(base, "input", compose);
      on(base, "change", compose);
      compose();
    }
    bind({ dir: "ed-dir", base: "ed-base", preview: "ed-preview", full: "ed-f" });
    bind({ dir: "rn-dir", base: "rn-base", preview: "rn-preview", full: null });
  })();

  // ---- 5 + 6. 编辑器：工具条 / 快捷键 / AJAX 保存 ----
  (function editor() {
    var form = document.getElementById("docEditForm");
    var ta = document.getElementById("ed-content");
    if (!form || !ta) { return; }

    var toolbar = document.getElementById("edToolbar");
    var dirtyMark = document.getElementById("ed-dirty");
    var statWords = document.getElementById("edStat-words");
    var statLines = document.getElementById("edStat-lines");
    var statSave = document.getElementById("edStat-save");
    var statusBar = document.getElementById("edStatus");
    if (toolbar) { toolbar.hidden = false; }
    if (statusBar) { statusBar.hidden = false; }

    var dirty = false;
    var savedSnapshot = ta.value;

    function setDirty(v) {
      dirty = v;
      if (dirtyMark) { dirtyMark.hidden = !v; }
    }
    function markEdited() {
      setDirty(ta.value !== savedSnapshot);
      refreshStats();
    }
    function refreshStats() {
      var v = ta.value;
      if (statWords) {
        var chars = v.length;
        var words = v.replace(/\s+/g, "").length;
        statWords.textContent = mdTN("js_stat_words", words, "{n} 字", "{n} 字")
          + " / " + mdTN("js_stat_chars", chars, "{n} 字符", "{n} 字符");
      }
      if (statLines) {
        statLines.textContent = mdTN("js_stat_lines", v.split("\n").length, "{n} 行", "{n} 行");
      }
    }
    on(ta, "input", function () {
      setDirty(ta.value !== savedSnapshot);
      refreshStats();
    });
    refreshStats();

    // 离开拦截：仅在有未保存改动时
    on(window, "beforeunload", function (e) {
      if (!dirty) { return; }
      e.preventDefault();
      e.returnValue = "";
    });

    // ---- 文本包裹/插入 ----
    // 统一走「整篇替换 + 事后设选区」：点击工具条按钮时 mousedown 已把焦点移出 textarea，
    // 部分内核下 execCommand('insertText') 用的是按钮侧的光标而非我们预设的选区，
    // 会插到错误位置。整篇替换在所有浏览器行为一致（代价：失去浏览器原生撤销栈，
    // 编辑器自带 dirty 标记与 Ctrl+Z 之外的保存保障）。
    function splice(text, from, to, selFrom, selTo) {
      var v = ta.value;
      ta.value = v.slice(0, from) + text + v.slice(to);
      if (selFrom !== undefined) {
        ta.selectionStart = selFrom;
        ta.selectionEnd = (selTo === undefined ? selFrom : selTo);
      }
      markEdited();
    }
    function surround(before, after, placeholder) {
      ta.focus();
      var s = ta.selectionStart, e = ta.selectionEnd;
      var sel = ta.value.slice(s, e);
      var body = sel !== "" ? sel : (placeholder || "");
      var text = before + body + (after === undefined ? before : after);
      if (sel === "") {
        splice(text, s, e, s + before.length, s + before.length + body.length);
      } else {
        splice(text, s, e);
      }
    }
    function prefixLines(prefix, ordered) {
      ta.focus();
      var s = ta.selectionStart, e = ta.selectionEnd;
      var v = ta.value;
      // 从首个选中行行首，到末个选中行行尾（无选区即光标所在行）
      var ls = v.lastIndexOf("\n", s - 1) + 1;
      var nl = v.indexOf("\n", e);
      var le = (nl === -1) ? v.length : nl;
      if (e > s && nl !== -1 && nl < e) { le = nl; }
      var block = v.slice(ls, le);
      var lines = block.split("\n");
      // 全部行都已带该前缀 → 再点一次是「取消前缀」
      var marker = ordered ? /^\d+\.\s/ : new RegExp("^" + prefix.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "\\s?");
      var allHave = lines.length > 0 && lines.every(function (l) {
        return l === "" || marker.test(l);
      });
      var out = lines.map(function (l, i) {
        if (allHave) {
          return ordered ? l.replace(/^\d+\.\s+/, "") : l.replace(marker, "");
        }
        return ordered ? (i + 1) + ". " + l : prefix + " " + l;
      }).join("\n");
      // 整篇替换（选区外的部分原样拼回），不依赖浏览器选区语义：
      // 某些内核里 mousedown 已把焦点移走，execCommand 用的是 button 的光标而非
      // 我们预设的 textarea 选区，会插到错误位置。整篇替换 + 事后设选区最稳。
      splice(out, ls, le, ls, ls + out.length);
    }
    function insertBlock(text) {
      ta.focus();
      var s = ta.selectionStart, e = ta.selectionEnd;
      var pad = (s > 0 && ta.value[s - 1] !== "\n") ? "\n" : "";
      var full = pad + text;
      splice(full, s, e, s + full.length);
    }
    var acts = {
      bold: function () { surround("**", "**", mdT("js_ph_bold", "加粗文字")); },
      italic: function () { surround("*", "*", mdT("js_ph_italic", "斜体文字")); },
      strike: function () { surround("~~", "~~", mdT("js_ph_strike", "删除线")); },
      code: function () { surround("`", "`", "code"); },
      h1: function () { prefixLines("#", false); },
      h2: function () { prefixLines("##", false); },
      h3: function () { prefixLines("###", false); },
      ul: function () { prefixLines("-", false); },
      ol: function () { prefixLines("1.", true); },
      task: function () { prefixLines("- [ ]", false); },
      quote: function () { prefixLines(">", false); },
      link: function () {
        ta.focus();
        var s = ta.selectionStart, e = ta.selectionEnd;
        var sel = ta.value.slice(s, e);
        if (sel !== "") {
          surround("[", "](https://)", sel);
          // 选中 URL 部分，方便直接粘贴覆盖
          var urlStart = s + 1 + sel.length + 2;      // "[" + 文本 + "]("
          ta.selectionStart = urlStart;
          ta.selectionEnd = urlStart + "https://".length;
        } else {
          surround("[", "](https://)", mdT("js_ph_link", "链接文字"));
        }
      },
      image: function () {
        ta.focus();
        var s = ta.selectionStart, e = ta.selectionEnd;
        var sel = ta.value.slice(s, e);
        if (sel !== "") {
          surround("![", "](" + mdT("js_ph_image_file", "图片.png") + ")", sel);
        } else {
          surround("![", "](" + mdT("js_ph_image_file", "图片.png") + ")", mdT("js_ph_img_alt", "图片说明"));
        }
      },
      table: function () {
        insertBlock(mdT("js_ph_table", "| 列1 | 列2 | 列3 |\n| --- | --- | --- |\n|  |  |  |\n"));
      },
      codeblock: function () {
        insertBlock(mdT("js_ph_codeblock", "```\n代码\n```\n"));
      },
      hr: function () { insertBlock("\n---\n"); }
    };

    // 工具条点击（事件委托，含动态渲染的按钮）
    if (toolbar) {
      on(toolbar, "click", function (ev) {
        var btn = closestBy(ev.target, "data-md");
        if (!btn) { return; }
        var act = acts[btn.getAttribute("data-md")];
        if (act) {
          act();
          setDirty(ta.value !== savedSnapshot);
          refreshStats();
        }
      });
    }

    // 快捷键：Ctrl/Cmd+S 保存、B 粗体、I 斜体、K 链接；Tab/Shift+Tab 缩进
    // （键盘路径焦点就在 textarea 上，execCommand 选区语义可靠，保留原生撤销栈）
    function insertTextAt(text, s, e) {
      if (document.execCommand) {
        try {
          if (document.execCommand("insertText", false, text)) { return; }
        } catch (err) { /* 兜底 */ }
      }
      ta.setRangeText(text, s, e, "end");
    }
    on(ta, "keydown", function (ev) {
      var mod = ev.ctrlKey || ev.metaKey;
      if (mod && !ev.shiftKey && !ev.altKey) {
        var k = (ev.key || "").toLowerCase();
        if (k === "s") {
          ev.preventDefault();
          save();
          return;
        }
        if (k === "b") { ev.preventDefault(); acts.bold(); return; }
        if (k === "i") { ev.preventDefault(); acts.italic(); return; }
        if (k === "k") { ev.preventDefault(); acts.link(); return; }
      }
      if (ev.key === "Tab") {
        ev.preventDefault();
        var s = ta.selectionStart, e = ta.selectionEnd;
        var v = ta.value;
        if (s === e && !ev.shiftKey) {
          insertTextAt("    ", s, e);
        } else {
          // 多行批量缩进/反缩进（行首 4 空格）
          var ls = v.lastIndexOf("\n", s - 1) + 1;
          var le = v.indexOf("\n", e);
          if (le === -1) { le = v.length; }
          var block = v.slice(ls, le);
          var lines = block.split("\n").map(function (l) {
            if (ev.shiftKey) { return l.replace(/^ {1,4}/, ""); }
            return "    " + l;
          });
          insertTextAt(lines.join("\n"), ls, le);
        }
        markEdited();
      }
    });

    // ---- AJAX 保存（渐进增强）----
    function save() {
      var btn = document.getElementById("edSaveBtn");
      if (btn) {
        btn.setAttribute("data-busy", "1");
        btn.disabled = true;
      }
      var fd = new FormData(form);
      fd.set("ajax", "1");
      fetch(form.action, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.ok) {
            savedSnapshot = ta.value;
            setDirty(false);
            if (statSave) {
              statSave.textContent = mdT("js_saved_at", "已保存 {time}").replace("{time}", new Date().toLocaleTimeString());
            }
            toast(mdT("js_saved", "已保存"));
            // 新建文档首次保存后，同步隐藏域里的完整路径（后续保存/书签都指向已存在文件）
            if (data.rel) {
              var f = document.getElementById("ed-f");
              if (f) { f.value = data.rel; }
            }
          } else {
            toast((data && data.msg) ? data.msg : mdT("js_save_fail", "保存失败"), "err");
          }
        })
        .catch(function () {
          // 网络/服务端异常：退回整页提交，保证内容不丢
          toast(mdT("js_save_req_fail", "保存请求失败，改为整页提交"), "err");
          form.submit();
        })
        .then(function () {
          if (btn) {
            btn.removeAttribute("data-busy");
            btn.disabled = false;
          }
        });
    }
    // 表单提交统一走 save()（无 fetch 的老浏览器直接放行整页提交）
    on(form, "submit", function (ev) {
      if (!window.fetch || !window.FormData) { return; }
      ev.preventDefault();
      save();
    }, false);
  })();

  // ---- 10. 主题选项的颜色取色器（v1.19.0）：把选到的色写回同组的文本框。
  // 文本框才是真实值（可留空 = 跟随主题色板），取色器只做输入辅助——
  // 无 JS 时文本框照常手填，功能不降级。
  try {
    qsa(document, "input[type=color][data-md-color]").forEach(function (sw) {
      var box = document.getElementById(sw.getAttribute("data-md-color"));
      if (!box) { return; }
      on(sw, "input", function () {
        box.value = sw.value;                         // #rrggbb 形态，服务端按声明校验
      });
    });
  } catch (e) { /* 取色器失败不影响手填 */
  }

  // ---- 9. TOTP 二维码（设置页启用确认步）：URI 从 data-totp-qr 读，
  // CDN 库没加载成功时这里什么都不做——服务端恒显示密钥文本，手输照样能绑
  try {
    qsa(document, "[data-totp-qr]").forEach(function (el) {
      var uri = el.getAttribute("data-totp-qr");
      if (!uri || typeof window.QRCode !== "function") { return; }
      el.innerHTML = "";
      new window.QRCode(el, { text: uri, width: 168, height: 168, correctLevel: window.QRCode.CorrectLevel.M });
    });
  } catch (e) { /* 二维码失败不影响手输密钥 */
  }
})();
