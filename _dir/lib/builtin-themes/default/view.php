<?php
/* DirDocs 默认主题 · 整页模板（view.php）
 * ------------------------------------------------------------
 * 主题即代码（WordPress/Typecho 模式）：安装主题 = 安装代码，请只安装可信来源。
 * 本文件由 md_page() 在定义完上下文变量后 include，可用变量见开发文档「主题包规范」：
 *   $cfg $ctx $theme $mode $lang $home $searchUrl $recentUrl $q $tocHtml $cdn
 *   $mermaidUrl $hasAdmin $headAssets $themeUrl $adminUrl
 *   $themeOptions（主题选项取值，键见 theme.php 的 options 声明；也可用 md_theme_option($theme,'键')）
 *   以及常量 MD_VERSION；
 *   助手函数 md_e/md_t/md_url_for 可用（界面文案一律经 md_t 取词，勿写死文案）。
 *   <html lang> 输出 $lang；<html> 带 md_i18n_js_attr() 的 data-md-i18n 属性（JS 文案词典）。
 *   <head> 里输出 $ctx['html_title'] 与 $ctx['head_meta']（SEO 标签由 md_page 统一组装）。
 *
 * 【内置包】同一份内容出现在两处：
 *   _dir/lib/builtin-themes/default/view.php   权威副本（读模板只看这里，随 _dir/lib/ 升级）
 *   _dir/themes/default/view.php               运行期镜像（后台动作自动同步，给人看的）
 * 改任何一份都会在下一次打开后台时被权威副本覆盖回来。想改版式请整套复制到
 * _dir/themes/<你自己的名字>/ 再改（自建主题永不被程序动），别改 default/wiki 这两个名字。
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    exit('DirDocs');
}
?>
<!DOCTYPE html>
<html lang="<?php echo md_e($lang); ?>"<?php echo md_i18n_js_attr(); ?> data-bs-theme="<?php echo $mode === 'dark' ? 'dark' : ($mode === 'light' ? 'light' : 'auto'); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $ctx['html_title']; ?></title>
<?php echo $ctx['head_meta']; ?>
<?php echo $headAssets; ?>
</head>
<body<?php echo $mermaidUrl !== '' ? ' data-mermaid="' . md_e($mermaidUrl) . '"' : ''; ?>>
<a class="md-skip" href="#md-content"><?php echo md_e(md_t('skip_content')); ?></a>
<div id="scrollProgress" aria-hidden="true"></div>

<!-- 顶部导航条：56px 毛玻璃 sticky -->
<header class="top-navbar d-flex align-items-center justify-content-between px-3 px-md-4">
  <div class="d-flex align-items-center gap-2 gap-md-3 md-topnav-left">
    <button class="md-iconbtn d-lg-none" type="button" data-bs-toggle="offcanvas"
            data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="<?php echo md_e(md_t('open_nav_aria')); ?>" aria-expanded="false">
      <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <a class="brand-text d-flex align-items-center gap-2" href="<?php echo md_e($home); ?>">
      <span class="md-brand-label"><?php echo md_e($cfg['site_title']); ?></span>
      <span class="brand-version"><?php echo md_e(MD_VERSION); ?></span>
    </a>
  </div>
  <div class="d-flex align-items-center gap-1 gap-md-2">
    <!-- 搜索：<576px 也不许消失（CSS 缩窄为 flex:1）；GET name=q，Ctrl/Cmd+K 与 / 聚焦 -->
    <form class="md-searchbox input-group input-group-sm" role="search" method="get" action="<?php echo md_e($searchUrl); ?>">
      <span class="input-group-text md-search-ico"><i class="bi bi-search" aria-hidden="true"></i></span>
      <input type="search" class="form-control" name="q" placeholder="<?php echo md_e(md_t('search_ph')); ?>"
             value="<?php echo md_e($q); ?>" aria-label="<?php echo md_e(md_t('search_aria')); ?>">
    </form>
    <button type="button" class="md-iconbtn" data-md-toggle="theme" title="<?php echo md_e(md_t('theme_toggle')); ?>" aria-label="<?php echo md_e(md_t('theme_toggle')); ?>">
      <i class="bi bi-sun-fill md-ico-light" aria-hidden="true"></i><i class="bi bi-moon-stars-fill md-ico-dark" aria-hidden="true"></i>
    </button>
  </div>
</header>

<!-- 移动端侧栏抽屉：树由 builtin.js 从桌面树克隆，开合交给 Bootstrap offcanvas -->
<div class="offcanvas offcanvas-start md-offcanvas" tabindex="-1" id="mobileSidebar" aria-label="<?php echo md_e(md_t('doc_nav_aria')); ?>">
  <div class="offcanvas-header">
    <h6 class="offcanvas-title sidebar-section-title"><?php echo md_e(md_t('doc_list')); ?></h6>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?php echo md_e(md_t('close_aria')); ?>"></button>
  </div>
  <div class="offcanvas-body pt-0" id="mobile-tree-target"></div>
</div>

<!-- 移动端悬浮大纲按钮：点击弹出本页标题大纲（≥992px 由 CSS 隐藏） -->
<button type="button" class="md-fab d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#mobileToc"
        aria-label="<?php echo md_e(md_t('toc_title')); ?>" title="<?php echo md_e(md_t('toc_title')); ?>">
  <i class="bi bi-list-ul" aria-hidden="true"></i>
</button>

<!-- 移动端本页大纲弹层：内容由 builtin.js 从桌面 TOC 克隆（保留 .md-toc 类，滚动高亮联动） -->
<div class="offcanvas offcanvas-bottom md-tocsheet" tabindex="-1" id="mobileToc" aria-label="<?php echo md_e(md_t('toc_title')); ?>">
  <div class="offcanvas-header">
    <h6 class="offcanvas-title sidebar-section-title"><?php echo md_e(md_t('toc_title')); ?></h6>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?php echo md_e(md_t('close_aria')); ?>"></button>
  </div>
  <div class="offcanvas-body pt-0" id="mobile-toc-target"></div>
</div>

<div class="md-workspace d-flex justify-content-between align-items-start">
  <!-- 桌面侧栏目录树 -->
  <aside class="doc-sidebar d-none d-lg-block" id="md-side">
    <?php if ($cfg['site_desc'] !== ''): ?><p class="md-sitedesc"><?php echo md_e($cfg['site_desc']); ?></p><?php endif; ?>
    <a class="md-recent-link" href="<?php echo md_e($recentUrl); ?>"><i class="bi bi-clock-history" aria-hidden="true"></i><?php echo md_e(md_t('recent_title')); ?></a>
    <div class="sidebar-section-title"><?php echo md_e(md_t('doc_list')); ?></div>
    <?php echo $ctx['nav_html']; ?>
  </aside>

  <!-- 中间：独立浮起的纸张正文区 -->
  <main class="md-main flex-grow-1 d-flex justify-content-center px-2 px-md-3" id="md-content">
    <div class="md-stage-col">
    <?php echo isset($ctx['risk_html']) ? $ctx['risk_html'] : ''; ?>
    <?php if (!empty($themeOptions['notice'])): /* 站点公告：主题选项（后台「主题」页填写），留空不显示 */ ?>
    <div class="alert alert-warn md-notice" role="note"><?php echo nl2br(md_e($themeOptions['notice'])); ?></div>
    <?php endif; ?>
    <div class="doc-stage">
    <?php echo $ctx['breadcrumbs']; ?>
    <?php echo isset($ctx['bylines_html']) ? $ctx['bylines_html'] : ''; ?>
      <article class="md-doc">
      <?php echo $ctx['content_html']; ?>
      </article>
      <?php echo isset($ctx['feedback_html']) ? $ctx['feedback_html'] : ''; ?>
      <?php echo isset($ctx['comments_html']) ? $ctx['comments_html'] : ''; ?>
      <?php echo isset($ctx['pager_html']) ? $ctx['pager_html'] : ''; ?>
      <?php if (!empty($ctx['attach_html'])): ?>
      <section class="md-attach"><h2><?php echo md_e(md_t('attachments')); ?></h2><?php echo $ctx['attach_html']; ?></section>
      <?php endif; ?>
      <footer class="md-footer-meta">
        <span>Powered by <a href="<?php echo md_e(md_project_url()); ?>" target="_blank" rel="noopener" title="<?php echo md_e(md_t('powered_by_title')); ?>"><i class="bi bi-github" aria-hidden="true"></i>DirDocs <?php echo md_e(MD_VERSION); ?></a></span>
        <?php if (!empty($themeOptions['footer_note'])): /* 页脚补充信息：主题选项，可填备案号等 */ ?>
        <span class="md-footer-note"><?php echo md_e($themeOptions['footer_note']); ?></span>
        <?php endif; ?>
        <span class="md-footer-links">
          <?php if ($hasAdmin): ?>
            <?php if (!empty($ctx['edit_url'])): ?><a href="<?php echo md_e($ctx['edit_url']); ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i><?php echo md_e(md_t('edit_this_page')); ?></a><?php endif; ?>
            <a href="<?php echo md_e(md_admin_url('')); ?>"><i class="bi bi-gear" aria-hidden="true"></i><?php echo md_e(md_t('admin_link')); ?></a>
          <?php endif; ?>
        </span>
      </footer>
    </div>
    </div>
  </main>
  <?php echo $tocHtml; ?>
</div>
<!-- Bootstrap bundle 先于 builtin.js（同为 defer，按文档序执行；offcanvas/collapse 依赖它） -->
<script src="<?php echo md_e($cdn['js']); ?>" defer></script>
<script src="<?php echo md_e(md_url_for('builtin.js', 'sys', $cfg) . '&v=' . rawurlencode(MD_VERSION)); ?>" defer></script>
</body>
</html>
