<?php
/* DirDocs 示例主题包「海洋」· 整页模板
 * ------------------------------------------------------------
 * 演示「完整主题切换」：与 default 完全不同的版式——
 * 单栏居中、无侧边栏、无 Bootstrap、大纲收进顶部 <details>、深海底色。
 * 主题即代码：安装主题 = 安装代码，请只安装可信来源。
 * 可用变量：$cfg $ctx $theme $mode $lang $home $searchUrl $recentUrl $q $tocHtml
 *           $cdn $mermaidUrl $hasAdmin $headAssets $themeUrl $adminUrl，常量 MD_VERSION；
 *           助手 md_e/md_t 可用（界面文案经 md_t 取词）。本模板不加载 builtin.js，
 *           无需输出 data-md-i18n 属性（那 JS 文案词典只服务 builtin.js）。
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    exit('DirDocs');
}
$m = md_theme_manifest($theme);                          // 本包色板（theme.php 的 vars）
$varsCss = '';
foreach ($m['vars'] as $k => $v) {
    $varsCss .= $k . ':' . $v . ';';
}
?>
<!DOCTYPE html>
<html lang="<?php echo md_e($lang); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $ctx['html_title']; ?></title>
<?php echo $ctx['head_meta']; ?>
<style>
:root{<?php echo $varsCss; ?>color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:
  radial-gradient(1200px 500px at 20% -10%, rgba(79,209,197,.14), transparent 60%),
  radial-gradient(900px 420px at 90% 0%, rgba(59,130,246,.12), transparent 55%),
  var(--bg);
  color:var(--text);font:15.5px/1.85 var(--font);letter-spacing:.01em}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
.wrap{max-width:820px;margin:0 auto;padding:2.2rem 1.2rem 4rem}
.ocean-top{display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding:.9rem 1.1rem;border:1px solid var(--border);border-radius:var(--radius);
  background:var(--surface);margin-bottom:1.6rem}
.ocean-brand{font-weight:700;letter-spacing:.02em}
.ocean-brand small{display:block;font-weight:400;color:var(--text3);font-size:.72rem;letter-spacing:.08em}
.ocean-top nav{display:flex;gap:1rem;font-size:.85rem}
.ocean-top nav a{color:var(--text2)}
.ocean-top nav a:hover{color:var(--accent)}
details.ocean-toc{border:1px solid var(--border);border-radius:var(--radius);
  background:var(--surface);margin:0 0 1.8rem}
details.ocean-toc summary{cursor:pointer;padding:.75rem 1rem;font-weight:600;color:var(--accent);
  list-style:none}
details.ocean-toc summary::marker{content:""}
details.ocean-toc[open] summary{border-bottom:1px solid var(--border-subtle)}
details.ocean-toc ul{list-style:none;margin:0;padding:.4rem 0}
details.ocean-toc li a{display:block;padding:.42rem 1.1rem;color:var(--text2)}
details.ocean-toc li a:hover{background:var(--accent-weak);color:var(--text);text-decoration:none}
.ocean-crumb{font-size:.8rem;color:var(--text3);margin-bottom:1rem}
h1{font-size:2rem;line-height:1.35;margin:.2em 0 .6em;letter-spacing:.01em}
.md-doc{word-wrap:break-word}
.md-doc h2{font-size:1.3rem;margin:2.2em 0 .8em;padding-bottom:.45rem;
  border-bottom:1px solid var(--border-subtle)}
.md-doc a{color:var(--accent)}
.md-doc img{max-width:100%;border-radius:8px}
.md-doc pre{background:var(--code-bg);color:var(--code-fg);padding:1rem 1.1rem;
  border-radius:8px;overflow:auto;font-family:var(--mono);font-size:.86rem;line-height:1.6}
.md-doc code{font-family:var(--mono);font-size:.88em;background:var(--accent-weak);
  color:var(--accent);padding:2px 5px;border-radius:4px}
.md-doc pre code{background:none;color:inherit;padding:0}
.md-doc blockquote{margin:1.4em 0;padding:.9rem 1.1rem;border-left:3px solid var(--accent);
  background:rgba(255,255,255,.04);border-radius:0 8px 8px 0;color:var(--text2)}
.md-doc table{width:100%;border-collapse:collapse;margin:1.4em 0;font-size:.9em}
.md-doc th,.md-doc td{padding:.5rem .75rem;border-bottom:1px solid var(--border);text-align:left}
.ocean-foot{margin-top:3rem;padding-top:1.2rem;border-top:1px solid var(--border);
  display:flex;justify-content:space-between;flex-wrap:wrap;gap:.6rem;
  color:var(--text3);font-size:.78rem}
</style>
</head>
<body>
<div class="wrap">
  <div class="ocean-top">
    <a class="ocean-brand" href="<?php echo md_e($home); ?>"><?php echo md_e($cfg['site_title']); ?>
      <small>DIRDOCS · OCEAN THEME</small></a>
    <nav>
      <a href="<?php echo md_e($recentUrl); ?>"><?php echo md_e(md_t('recent_title')); ?></a>
      <a href="<?php echo md_e($adminUrl); ?>"><?php echo md_e(md_t('admin_link')); ?></a>
    </nav>
  </div>

  <form method="get" action="<?php echo md_e($searchUrl); ?>"
        style="display:flex;gap:.5rem;margin-bottom:1.6rem">
    <input type="search" name="q" value="<?php echo md_e($q); ?>" placeholder="<?php echo md_e(md_t('search_ph_simple')); ?>"
           style="flex:1;padding:.5rem .8rem;border:1px solid var(--border);border-radius:var(--radius);
                  background:var(--surface);color:var(--text);font:inherit">
    <button style="padding:.5rem .9rem;border:0;border-radius:var(--radius);
                   background:var(--accent);color:#08203a;font-weight:600;cursor:pointer"><?php echo md_e(md_t('search_btn')); ?></button>
  </form>

  <?php if ($tocHtml !== ''): ?>
  <details class="ocean-toc" open>
    <summary><?php echo md_e(md_t('toc_title')); ?></summary>
    <?php echo $tocHtml; ?>
  </details>
  <?php endif; ?>

  <div class="ocean-crumb"><?php echo $ctx['breadcrumbs']; ?></div>
  <h1><?php echo md_e($ctx['page_title']); ?></h1>
  <?php echo isset($ctx['risk_html']) ? $ctx['risk_html'] : ''; ?>
  <div class="md-doc"><?php echo $ctx['content_html']; ?></div>
  <?php echo isset($ctx['feedback_html']) ? $ctx['feedback_html'] : ''; ?>
  <?php echo isset($ctx['comments_html']) ? $ctx['comments_html'] : ''; ?>
  <?php echo isset($ctx['pager_html']) ? $ctx['pager_html'] : ''; ?>
  <?php if (!empty($ctx['attach_html'])): ?>
  <section style="margin-top:2.4rem">
    <h2 style="font-size:1.05rem"><?php echo md_e(md_t('attachments')); ?></h2>
    <?php echo $ctx['attach_html']; ?>
  </section>
  <?php endif; ?>

  <div class="ocean-foot">
    <span>Powered by DirDocs <?php echo md_e(MD_VERSION); ?> · <?php echo md_e(md_t('ocean_theme_label')); ?></span>
    <span><?php echo md_e(md_t('ocean_theme_dir')); ?><code>_dir/themes/<?php echo md_e($theme); ?></code></span>
  </div>
</div>
</body>
</html>
