<?php
/**
 * DirDocs 前台（页面动作/模板辅助/附件/风险检测） 模块（由 index.php 加载，勿单独访问）
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}

function md_render_toc($toc, $cfg)
{
    if (!$toc) {
        return '';
    }
    // 只收录 h2-h4（h1 是页题，收进来没有导航意义）；深层按最浅可见层级归一化缩进
    $minLevel = 2;
    foreach ($toc as $h) {
        if ($h['level'] >= 2 && $h['level'] < $minLevel) {
            $minLevel = $h['level'];
        }
    }
    $entries = array();
    foreach ($toc as $h) {
        if ($h['level'] < 2 || $h['level'] > 4) {
            continue;
        }
        $entries[] = $h;
    }
    if (!$entries) {
        return '';
    }
    // 第三栏大纲（显示窗口 ≥1280px，宽度以 --toc-width 为基准在 200–320 间自适应）：
    // active 类名约定为 a.on（builtin.js scrollspy 写入）
    $html = '<aside class="doc-toc d-none d-xl-block"><div class="toc-title">' . md_e(md_t('toc_title')) . '</div>'
        . '<nav class="md-toc" aria-label="' . md_e(md_t('toc_aria')) . '"><ul>';
    foreach ($entries as $h) {
        $depth = max(0, min(3, $h['level'] - $minLevel));
        $html .= '<li class="md-toc-l' . $depth . '">'
            . '<a href="#' . md_e($h['id']) . '">' . md_e($h['text']) . '</a></li>';
    }
    $html .= '</ul></nav></aside>';
    return $html;
}

/**
 * 面包屑路径数据（HTML 渲染与 JSON-LD 结构化数据共用的唯一来源）：
 * 返回有序段数组，每段 array('name'=>显示名, 'url'=>相对URL或null)；当前页段 url 为 null。
 */
function md_breadcrumb_data($dirRel, $cfg, $current = '')
{
    $trail = array(array('name' => md_t('home'), 'url' => md_url_for('', 'dir', $cfg)));
    $path = '';
    foreach ($dirRel === '' ? array() : explode('/', $dirRel) as $s) {
        $path = $path === '' ? $s : $path . '/' . $s;
        // 目录段显示名剥 01- 数字前缀，与导航/目录列表保持一致
        $trail[] = array('name' => md_display_name($s, $cfg), 'url' => md_url_for($path, 'dir', $cfg));
    }
    if ($current !== '') {
        $trail[] = array('name' => $current, 'url' => null);
    }
    return $trail;
}

function md_render_breadcrumbs($dirRel, $cfg, $current = '')
{
    // 首页段带 bi-house-door，段间 "/" 分隔；
    // $current 为当前页名（渲染为不可点的当前段，收在 nav 内保证单行流式换行）
    $html = '<nav class="md-crumb" aria-label="' . md_e(md_t('crumb_aria')) . '">'
        . '<span class="md-crumb-item"><a class="md-crumb-link" href="' . md_e(md_url_for('', 'dir', $cfg)) . '">'
        . '<i class="bi bi-house-door" aria-hidden="true"></i><span>' . md_e(md_t('home')) . '</span></a></span>';
    $trail = md_breadcrumb_data($dirRel, $cfg, $current);
    $n = count($trail);
    for ($i = 1; $i < $n; $i++) {
        $html .= '<span class="md-sep">/</span><span class="md-crumb-item">';
        if ($trail[$i]['url'] !== null) {
            $html .= '<a class="md-crumb-link" href="' . md_e($trail[$i]['url']) . '">' . md_e($trail[$i]['name']) . '</a>';
        } else {
            $html .= '<span class="md-crumb-current">' . md_e($trail[$i]['name']) . '</span>';
        }
        $html .= '</span>';
    }
    $html .= '</nav>';
    return $html;
}

function md_render_nav($tree, $cfg, $activeRel)
{
    $html = '<ul class="md-nav" id="md-nav-tree">';
    $root = md_children('', $tree);
    $html .= md_nav_level($root, $tree, $cfg, '', $activeRel);
    $html .= '</ul>';
    return $html;
}

/**
 * 侧栏树标签：README/index/home/首页 → 「首页」；7 天内有更新 → 「NEW」。
 * 只加在侧栏树的文档行上（目录行不加）。
 * 文件名匹配名单是功能约定（与语言设置无关，中英文站惯例都认）；标签文案走 md_t。
 */
function md_tree_tag_html($rel, $mtime)
{
    $base = basename($rel);
    $dot = strrpos($base, '.');
    $stem = $dot !== false ? substr($base, 0, $dot) : $base;
    if (in_array($stem, array('README', 'readme', 'index', 'home', '首页'), true)) {
        return '<span class="tree-tag">' . md_e(md_t('tree_tag_home')) . '</span>';
    }
    if ($mtime > 0 && (time() - $mtime) <= 604800) {
        return '<span class="tree-tag">NEW</span>';
    }
    return '';
}

/** 相对时间：<1h 刚刚、<24h N 小时前、<30d N 天前、否则 Y-m-d。 */
function md_rel_time($mtime)
{
    $diff = time() - (int)$mtime;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 3600) {
        return md_t('just_now');
    }
    if ($diff < 86400) {
        return md_t('hours_ago', array('n' => (int)floor($diff / 3600)));
    }
    if ($diff < 2592000) {
        return md_t('days_ago', array('n' => (int)floor($diff / 86400)));
    }
    return date('Y-m-d', (int)$mtime);
}

// ---------------------------------------------------------------- SEO（canonical / 描述 / sitemap）

/** 目录索引文档名（与 md_show_dir 的首页候选清单保持一致）：这类文档的规范地址是所在目录。 */
function md_is_dir_index_name($rel)
{
    $base = basename($rel);
    $dot = strrpos($base, '.');
    $stem = $dot !== false ? substr($base, 0, $dot) : $base;
    return in_array($stem, array('README', 'readme', 'index', 'home', '首页', '说明'), true);
}

/** 规范地址（相对 URL）：根目录页在 simple 模式下用「/」形态而非丑陋的 ?p=。 */
function md_canonical_for($rel, $type, $cfg)
{
    if ($rel === '' && $type === 'dir') {
        return md_base_path($cfg) . '/';
    }
    return md_url_for($rel, $type, $cfg);
}

/**
 * 每页 meta description：取渲染后正文的首段纯文本前 ~150 字。
 * 无 mbstring 时按字节截并回退到 UTF-8 序列边界，避免截出半个字符
 * （htmlspecialchars 遇非法 UTF-8 会返回空串，描述会整个消失）。
 */
function md_meta_description($contentHtml, $limit = 150)
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($contentHtml)));
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
    // 逐字符复制（UTF-8 续字节跟随首字节），天然不会截出半个字符
    $out = '';
    $n = 0;
    $len = strlen($text);
    for ($i = 0; $i < $len && $n < $limit; $n++) {
        $out .= $text[$i];
        $i++;
        while ($i < $len && (ord($text[$i]) & 0xC0) === 0x80) {
            $out .= $text[$i];
            $i++;
        }
    }
    return $out;
}

/**
 * 文章元信息条（面包屑后、正文前）：「约 N 分钟阅读 · 最后更新于 X」。
 * N = ceil(strip_tags 正文长度 / 400)（无 mbstring 时按字节数 / 1200），至少 1；
 * X = 文档 mtime 相对时间：优先按 docRel / 去后缀形态查树扫描结果，回退 @filemtime($abs)。
 */
function md_bylines_html($docRel, $contentHtml, $abs = '')
{
    $text = strip_tags($contentHtml);
    if (function_exists('mb_strlen')) {
        $mins = (int)ceil(mb_strlen($text, 'UTF-8') / 400);
    } else {
        $mins = (int)ceil(strlen($text) / 1200);
    }
    if ($mins < 1) {
        $mins = 1;
    }
    $mtime = 0;
    $treeMd = isset($GLOBALS['md_tree']['md']) ? $GLOBALS['md_tree']['md'] : array();
    if (isset($treeMd[$docRel]['mtime'])) {
        $mtime = (int)$treeMd[$docRel]['mtime'];
    } else {
        // doc 路由的 docRel 不带 .md 后缀，树键带后缀：按去后缀形态补一次匹配
        foreach ($treeMd as $rel => $info) {
            if (md_strip_doc_ext($rel, md_config_load()) === $docRel) {
                $mtime = (int)$info['mtime'];
                break;
            }
        }
    }
    if ($mtime === 0 && $abs !== '') {
        $ft = @filemtime($abs);
        if ($ft !== false) {
            $mtime = (int)$ft;
        }
    }
    $html = '<div class="article-bylines">'
        . '<span><i class="bi bi-clock" aria-hidden="true"></i>' . md_e(md_t('reading_time', array('n' => $mins))) . '</span>';
    if ($mtime > 0) {
        $html .= '<span><i class="bi bi-arrow-repeat" aria-hidden="true"></i>'
            . md_e(md_t('last_updated', array('time' => md_rel_time($mtime)))) . '</span>';
    }
    $html .= '</div>';
    return $html;
}

/** 反馈胶囊（pager 之前）：点击行为全部在 builtin.js（本地记忆，可取消）。 */
function md_feedback_html($docRel)
{
    return '<div class="md-feedback" data-doc-rel="' . md_e($docRel) . '">'
        . '<span class="md-feedback-q">' . md_e(md_t('feedback_q')) . '</span>'
        . '<div class="feedback-pill">'
        . '<button type="button" class="feedback-btn" data-md-feedback="up" title="' . md_e(md_t('feedback_up')) . '" aria-label="' . md_e(md_t('feedback_up')) . '"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i></button>'
        . '<span class="feedback-sep" aria-hidden="true">|</span>'
        . '<button type="button" class="feedback-btn" data-md-feedback="down" title="' . md_e(md_t('feedback_down')) . '" aria-label="' . md_e(md_t('feedback_down')) . '"><i class="bi bi-hand-thumbs-down" aria-hidden="true"></i></button>'
        . '</div></div>';
}

function md_nav_level($items, $tree, $cfg, $prefix, $activeRel)
{
    $html = '';
    // 目录在前、文档在后；两组各自已由 md_children 按数字序+名称排好，直接遍历
    foreach ($items['dir'] as $d) {
        $sub = md_children($d['rel'], $tree);
        $hasChild = $sub['dir'] || $sub['md'];
        // 只展开当前路径分支（activeRel 的祖先链），其余目录默认收起
        $open = ($activeRel !== '' && md_starts_with($activeRel, $d['rel'] . '/')) ? ' open' : '';
        // 空目录不给折叠箭头（折叠无意义）
        $emptyCls = $hasChild ? '' : ' is-empty';
        $html .= '<li class="tree-node md-dir"><details' . $open . '><summary class="tree-link tree-folder' . $emptyCls . '">'
            . '<span class="tree-row-main"><i class="bi bi-chevron-right folder-toggle" aria-hidden="true"></i>'
            . '<i class="bi bi-folder2 md-folder-ico" aria-hidden="true"></i>'
            . '<span class="tree-row-text">' . md_e(md_display_name($d['name'], $cfg)) . '</span></span>'
            . '</summary>';
        if ($hasChild) {
            $html .= '<ul>' . md_nav_level($sub, $tree, $cfg, $d['rel'], $activeRel) . '</ul>';
        }
        $html .= '</details></li>';
    }
    foreach ($items['md'] as $m) {
        // activeRel 可能不带 .md 后缀（doc 路由形态），两种形态都认作当前文档
        $isActive = ($m['rel'] === $activeRel
            || md_strip_doc_ext($m['rel'], $cfg) === md_strip_doc_ext($activeRel, $cfg));
        // 站内文档 URL 统一不带 .md 后缀
        $docRel = md_strip_doc_ext($m['rel'], $cfg);
        $mtime = isset($m['mtime']) ? (int)$m['mtime'] : 0;
        $html .= '<li class="tree-node"><a class="tree-link' . ($isActive ? ' active' : '') . '"'
            . ($isActive ? ' aria-current="page"' : '') . ' href="' . md_e(md_url_for($docRel, 'doc', $cfg)) . '">'
            . '<span class="tree-row-main"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>'
            . '<span class="tree-row-text">' . md_e(md_doc_label($m['rel'], $cfg)) . '</span></span>'
            . md_tree_tag_html($m['rel'], $mtime)
            . '</a></li>';
    }
    return $html;
}

function md_title_of($rel)
{
    static $cache = array();
    if (isset($cache[$rel])) {
        return $cache[$rel];
    }
    $title = basename($rel);
    $treeMd = isset($GLOBALS['md_tree']['md']) ? $GLOBALS['md_tree']['md'] : array();
    $hit = false;
    if (isset($treeMd[$rel])) {
        $hit = $treeMd[$rel];                           // 带 .md 后缀的 rel（导航/搜索路径）
    } else {
        // doc 路由的 $rel 不带后缀，树键带后缀：补白名单后缀再查一次，
        // 否则 front-matter/H1 标题在面包屑和 <title> 里永远不生效
        foreach (md_config_load()['ext_md'] as $e) {
            if (isset($treeMd[$rel . '.' . $e])) {
                $hit = $treeMd[$rel . '.' . $e];
                break;
            }
        }
    }
    if ($hit !== false) {
        if (isset($hit['title']) && $hit['title'] !== '') {
            $title = $hit['title'];
        }
    } else {
        $abs = MD_ROOT . '/' . $rel;
        $text = md_read_text($abs);
        if ($text === false) {
            // 无后缀形态也补一次直读
            foreach (md_config_load()['ext_md'] as $e) {
                $text = md_read_text(MD_ROOT . '/' . $rel . '.' . $e);
                if ($text !== false) {
                    break;
                }
            }
        }
        if ($text !== false) {
            $meta = md_front_matter($text);
            if ($meta['title'] !== '') {
                $title = $meta['title'];
            } else {
                $body = md_strip_front_matter($text);
                if (preg_match('/^\s{0,3}#\s+(.+)$/m', $body, $m) === 1) {
                    $title = trim($m[1]);
                }
            }
        }
    }
    $cache[$rel] = $title;
    return $title;
}

/**
 * 导航/目录列表里的文档显示名：front-matter/H1 标题优先；
 * 没有标题（md_title_of 回退为文件名）时按 01- 前缀剥除呈现，
 * 让「01-简介.md」在无标题时显示成「简介」。
 */
function md_doc_label($rel, $cfg)
{
    $title = md_title_of($rel);
    if ($title !== '' && $title !== basename($rel)) {
        return $title;
    }
    return md_display_name(basename($rel, '.' . md_ext($rel)), $cfg);
}

/** 目录列表的小图标（内联 SVG，自托管，跟随 currentColor）。 */
function md_ico_dir()
{
    return '<svg class="md-svg" viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">'
        . '<path d="M1.75 1A1.75 1.75 0 0 0 0 2.75v10.5C0 14.216.784 15 1.75 15h12.5A1.75 1.75 0 0 0 16 13.25v-8.5A1.75 1.75 0 0 0 14.25 3H7.5a.25.25 0 0 1-.2-.1l-.9-1.2C6.07 1.26 5.55 1 5 1H1.75Z"/></svg>';
}

/** 文档/附件的小图标（内联 SVG）。 */
function md_ico_file()
{
    return '<svg class="md-svg" viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">'
        . '<path d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0 1 13.25 16h-9.5A1.75 1.75 0 0 1 2 14.25Zm1.75-.25a.25.25 0 0 0-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25V6h-2.75A1.75 1.75 0 0 1 9 4.25V1.5Zm6.75.062V4.25c0 .138.112.25.25.25h2.688l-.011-.013-2.914-2.914-.013-.011Z"/></svg>';
}

function md_format_size($bytes)
{
    if (!is_numeric($bytes)) {
        return '';
    }
    $units = array('B', 'KB', 'MB', 'GB');
    $i = 0;
    while ($bytes >= 1024 && $i < 3) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

function md_route_p()
{
    $p = isset($_GET['p']) ? $_GET['p'] : '';
    if ($p === '') {
        // pretty 模式：从 REQUEST_URI 去掉 base path 得到 p
        // （仅在 ?p= 为空时启用，保证 simple 模式查询串永远优先）
        if (isset($_SERVER['REQUEST_URI'])) {
            $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (is_string($uri)) {
                $cfg = md_config_load();
                $base = md_base_path($cfg);
                $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
                $scriptDir = rtrim(dirname($script), '/');
                if ($base !== '' && md_starts_with($uri, $base)) {
                    $p = substr($uri, strlen($base));
                } elseif ($scriptDir !== '' && $scriptDir !== '.' && md_starts_with($uri, $scriptDir)) {
                    $p = substr($uri, strlen($scriptDir));
                } elseif ($cfg['url_mode'] === 'pretty') {
                    // 根目录安装：base 与 scriptDir 均为空，URI 即文档路径
                    $p = $uri;
                }
                // URI 形态是百分号编码：按段解码成文件系统裸路径，否则标题/树/canonical
                // 全部拿到编码形态双重出错（$_GET['p'] 由 PHP 自动解码，不走这段）。
                // 解码后出现 '..' 也无碍：md_resolve 逐段拒绝
                $segs = explode('/', $p);
                foreach ($segs as $k => $s) {
                    $segs[$k] = rawurldecode($s);
                }
                $p = implode('/', $segs);
                $p = trim($p, '/');
                // 入口自身不是文档：/index.php 与 /index.php/PATH_INFO 归一为剩余路径
                //（根目录与子目录两种安装形态都在这里收口，/ 或 /sub/ 视为首页）
                if ($p === 'index.php' || md_starts_with($p, 'index.php/')) {
                    $p = substr($p, 9);
                }
                // 去掉 .md 后缀（用户可能直接带后缀访问）
                $ext = md_ext($p);
                if ($ext !== '' && in_array($ext, $cfg['ext_md'], true)) {
                    $p = substr($p, 0, -(strlen($ext) + 1));
                }
                // _theme/ 前缀原样保留：入口据此分发主题资产通道（index.php 路由）
            }
        }
    }
    $p = str_replace('\\', '/', $p);
    $p = trim($p, '/');
    return $p;
}

function md_action()
{
    $a = isset($_GET['a']) ? $_GET['a'] : '';
    if ($a === '' && isset($_GET['_a'])) {
        $a = $_GET['_a'];                              // pretty 模式的后台占位
    }
    return $a;
}

/** 首页文档解析：home 配置（仅根目录）→ README → index → home → 首页 → 说明；没有则返回 ''。 */
function md_home_doc_rel($children, $cfg, $useHomeCfg = true)
{
    if ($useHomeCfg && $cfg['home'] !== '') {
        foreach (isset($children['md']) ? $children['md'] : array() as $m) {
            if ($m['rel'] === $cfg['home']) {
                return $m['rel'];
            }
        }
    }
    foreach (array('README', 'readme', 'index', 'home', '首页', '说明') as $name) {
        foreach (isset($children['md']) ? $children['md'] : array() as $m) {
            if (basename($m['rel'], '.' . md_ext($m['rel'])) === $name) {
                return $m['rel'];
            }
        }
    }
    return '';
}

function md_show_dir($dirRel, $tree, $cfg)
{
    $children = md_children($dirRel, $tree);

    // 目录首页：优先 home 配置 / README / index，否则渲染索引列表
    $homeFile = md_home_doc_rel($children, $cfg, $dirRel === '');
    if ($homeFile !== null && $homeFile !== '') {
        return md_show_doc($homeFile, $tree, $cfg, $dirRel);
    }

    $ctx = array();
    $ctx['cfg'] = $cfg;
    $ctx['page_title'] = $dirRel === '' ? $cfg['site_title'] : md_display_name(basename($dirRel), $cfg);
    $ctx['breadcrumbs'] = md_render_breadcrumbs($dirRel, $cfg);
    $ctx['nav_html'] = md_render_nav($tree, $cfg, '');
    $ctx['toc_html'] = '';
    $ctx['risk_html'] = md_risk_html(md_exec_risk_files(MD_ROOT, $cfg), md_is_admin());
    $ctx['seo_canonical'] = md_canonical_for($dirRel, 'dir', $cfg);
    $ctx['seo_type'] = 'website';
    $ctx['seo_crumbs'] = md_breadcrumb_data($dirRel, $cfg, $ctx['page_title']);

    $html = '';
    if ($dirRel === '') {
        $html .= '<h1>' . md_e($cfg['site_title']) . '</h1>';
        if ($cfg['site_desc'] !== '') {
            $html .= '<p class="md-lead">' . md_e($cfg['site_desc']) . '</p>';
        }
    } else {
        $html .= '<h1>' . md_e(md_display_name(basename($dirRel), $cfg)) . '</h1>';
    }
    $html .= md_dir_list_html($children, $cfg);
    $ctx['content_html'] = $html;

    $ctx['attach_html'] = md_attach_html($children, $cfg);
    md_page($ctx);
}

function md_show_search($tree, $cfg)
{
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $ctx = array();
    $ctx['cfg'] = $cfg;
    $ctx['page_title'] = md_t('search_q_title', array('q' => $q));
    $ctx['breadcrumbs'] = md_render_breadcrumbs('', $cfg, md_t('search_page_title'));
    $ctx['nav_html'] = md_render_nav($tree, $cfg, '');
    $ctx['toc_html'] = '';
    $ctx['risk_html'] = md_risk_html(md_exec_risk_files(MD_ROOT, $cfg), md_is_admin());
    $ctx['seo_robots'] = 'noindex, follow';              // 搜索结果页不进索引

    if ($q === '') {
        $ctx['content_html'] = '<h1>' . md_e(md_t('search_page_title')) . '</h1><p><em>' . md_e(md_t('search_empty_q')) . '</em></p>';
        md_page($ctx);
    }

    $t0 = microtime(true);
    // 排序（v1.15.2）：URL 显式参数 > 访客 Cookie 偏好 > 默认相关度。显式点选即写下偏好
    // （Cookie 一年、HttpOnly、SameSite=Lax、路径限定安装子目录）——选择权在每个访客，
    // 无后台全局配置；Cookie 只影响搜索排序，无安全语义
    $sortParam = isset($_GET['sort']) ? (string)$_GET['sort'] : '';
    $sortCookie = isset($_COOKIE['dd_search_sort']) ? (string)$_COOKIE['dd_search_sort'] : '';
    $sort = md_search_sort_resolve($sortParam, $sortCookie);
    if ($sortParam === 'time' || $sortParam === 'score') {
        $base = md_base_path($cfg);
        $ckPath = ($base === '' || $base === false) ? '/' : rtrim($base, '/') . '/';
        header('Set-Cookie: dd_search_sort=' . $sort . '; Max-Age=31536000; Expires='
            . gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000) . '; Path=' . $ckPath
            . '; HttpOnly; SameSite=Lax', false);
    }
    $results = md_search($q, $cfg, 50, $sort);
    $ms = round((microtime(true) - $t0) * 1000);

    $html = '<h1>' . md_e(md_t('search_q_title', array('q' => $q))) . '</h1>';
    $count = count($results);
    if ($count === 0) {
        $html .= '<p><em>' . md_e(md_t('search_no_results', array('q' => $q))) . '</em></p>';
        if (!function_exists('mb_stripos')) {
            $html .= '<p class="md-hint">' . md_e(md_t('search_mbstring_hint')) . '</p>';
        }
    } else {
        $html .= '<p class="md-hint">' . md_e(md_t('search_found', array('n' => $count, 'time' => $ms))) . '</p>';
        // 排序切换条：两个形态都带显式 sort 参数（点了就记住）；当前形态纯文本
        $html .= '<p class="md-hint md-sort-switch">' . md_e(md_t('search_sort_label'))
            . ($sort === 'time'
                ? '<span>' . md_e(md_t('search_sort_time')) . '</span>'
                : '<a href="?q=' . rawurlencode($q) . '&amp;sort=time">' . md_e(md_t('search_sort_time')) . '</a>')
            . ' · '
            . ($sort === 'score'
                ? '<span>' . md_e(md_t('search_sort_score')) . '</span>'
                : '<a href="?q=' . rawurlencode($q) . '&amp;sort=score">' . md_e(md_t('search_sort_score')) . '</a>')
            . '</p>';
        $html .= '<ul class="md-results">';
        foreach ($results as $r) {
            $url = md_url_for(md_strip_doc_ext($r['rel'], $cfg), 'doc', $cfg);
            // 结果链接带 q，打开后正文高亮
            $url .= (strpos($url, '?') !== false ? '&' : '?') . 'q=' . rawurlencode($q);
            $html .= '<li><h3><a href="' . md_e($url) . '">'
                . md_search_highlight(md_e($r['title']), $q) . '</a></h3>'
                . '<p class="md-snippet">' . md_search_highlight(md_e($r['snippet']), $q) . '</p>'
                . '<p class="md-path">' . md_e($r['rel']) . '</p></li>';
        }
        $html .= '</ul>';
    }
    $ctx['content_html'] = $html;
    md_page($ctx);
}

function md_show_recent($tree, $cfg)
{
    $ctx = array();
    $ctx['cfg'] = $cfg;
    $ctx['page_title'] = md_t('recent_title');
    $ctx['breadcrumbs'] = md_render_breadcrumbs('', $cfg, md_t('recent_title'));
    $ctx['nav_html'] = md_render_nav($tree, $cfg, '');
    $ctx['toc_html'] = '';
    $ctx['risk_html'] = md_risk_html(md_exec_risk_files(MD_ROOT, $cfg), md_is_admin());
    $ctx['seo_robots'] = 'noindex, follow';              // 排序随时变的工具页不进索引

    $items = isset($tree['md']) ? $tree['md'] : array();
    uasort($items, function ($a, $b) {
        return ($b['mtime'] - $a['mtime']);
    });

    $limit = 50;
    $html = '<h1>' . md_e(md_t('recent_title')) . '</h1>';
    if (!$items) {
        $html .= '<p><em>' . md_e(md_t('recent_empty')) . '</em></p>';
    } else {
        $html .= '<ul class="md-recent">';
        $n = 0;
        foreach ($items as $rel => $info) {
            if (!empty($info['draft'])) {
                continue;                           // 草稿不进最近更新（v1.15.0）
            }
            $url = md_url_for(md_strip_doc_ext($rel, $cfg), 'doc', $cfg);
            $mtime = (int)$info['mtime'];
            $html .= '<li><span class="md-rdate">' . md_e(date('Y-m-d H:i', $mtime)) . '</span>'
                . '<a href="' . md_e($url) . '">' . md_e(md_title_of($rel)) . '</a>'
                . '<span class="md-rpath">' . md_e($rel) . '</span></li>';
            $n++;
            if ($n >= $limit) {
                break;
            }
        }
        $html .= '</ul>';
        if ($n < count($items)) {
            $html .= '<p class="md-hint">' . md_e(md_t('recent_limit', array('n' => $limit, 'total' => count($items)))) . '</p>';
        }
    }
    $ctx['content_html'] = $html;
    md_page($ctx);
}

function md_dir_list_html($children, $cfg)
{
    if (!$children['dir'] && !$children['md']) {
        return '<p class="md-empty"><em>' . md_e(md_t('dir_empty')) . '</em></p>';
    }
    $html = '<ul class="md-dirlist">';
    foreach ($children['dir'] as $d) {
        $html .= '<li><span class="md-ico">' . md_ico_dir() . '</span>'
            . '<a href="' . md_e(md_url_for($d['rel'], 'dir', $cfg)) . '">'
            . md_e(md_display_name($d['name'], $cfg)) . '</a>'
            . '<span class="md-meta">' . md_e(md_t('dir_meta')) . '</span></li>';
    }
    foreach ($children['md'] as $m) {
        $html .= '<li><span class="md-ico">' . md_ico_file() . '</span><a href="'
            . md_e(md_url_for(md_strip_doc_ext($m['rel'], $cfg), 'doc', $cfg)) . '">'
            . md_e(md_doc_label($m['rel'], $cfg)) . '</a><span class="md-meta">'
            . md_e(basename($m['rel'])) . '</span></li>';
    }
    $html .= '</ul>';
    return $html;
}

function md_attach_html($children, $cfg)
{
    if (!$cfg['show_attach'] || !$children['attach']) {
        return '';
    }
    $imgExt = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'ico');
    $html = '<ul>';
    foreach ($children['attach'] as $a) {
        $url = md_url_for($a['rel'], 'file', $cfg);
        $name = $a['name'];
        $ext = md_ext($name);
        if (in_array($ext, $imgExt, true)) {
            // 图片附件：缩略图预览，点击看原图
            $html .= '<li class="md-attach-img"><a href="' . md_e($url) . '" title="' . md_e($name) . '">'
                . '<img src="' . md_e($url) . '" alt="' . md_e($name) . '" loading="lazy">'
                . '</a><span class="md-size">' . md_e($name) . ' · ' . md_e(md_format_size($a['size'])) . '</span></li>';
        } else {
            // 非图片附件：文件小图标，名称/大小交给卡片布局
            $html .= '<li><span class="md-ico">' . md_ico_file() . '</span><a href="' . md_e($url) . '">'
                . md_e($name) . '</a>'
                . '<span class="md-size">' . md_e(md_format_size($a['size'])) . '</span></li>';
        }
    }
    $html .= '</ul>';
    return $html;
}

/** 上一篇/下一篇：同目录内按排序取相邻文档，不跨目录（原型式翻页导流）。 */
function md_pager_html($children, $docRel, $cfg)
{
    $flat = array();                                    // doc=去后缀形态（与路由一致），rel=真实文件
    foreach ($children['md'] as $m) {
        $flat[] = array('rel' => $m['rel'], 'doc' => md_strip_doc_ext($m['rel'], $cfg));
    }
    $idx = count($flat) ? array_search($docRel, array_column($flat, 'doc'), true) : false;
    if ($idx === false || count($flat) < 2) {
        return '';
    }
    // 左侧：上一篇（没有则「← 已是第一篇」小字）；右侧：下一篇 outline 按钮（没有则小字）
    $i = $idx - 1;
    if ($i < 0) {
        $left = '<span class="md-pager-off">← ' . md_e(md_t('pager_first')) . '</span>';
    } else {
        $left = '<a class="md-pager-prev" href="' . md_e(md_url_for($flat[$i]['doc'], 'doc', $cfg)) . '">'
            . '<i class="bi bi-arrow-left" aria-hidden="true"></i><span>'
            . md_e(md_t('pager_prev', array('title' => md_title_of($flat[$i]['rel'])))) . '</span></a>';
    }
    $i = $idx + 1;
    if ($i >= count($flat)) {
        $right = '<span class="md-pager-off">' . md_e(md_t('pager_last')) . ' →</span>';
    } else {
        $right = '<a class="btn btn-outline-primary btn-sm rounded-3 px-3 py-2 md-pager-next" href="'
            . md_e(md_url_for($flat[$i]['doc'], 'doc', $cfg)) . '">'
            . '<span>' . md_e(md_t('pager_next', array('title' => md_title_of($flat[$i]['rel'])))) . '</span>'
            . '<i class="bi bi-arrow-right" aria-hidden="true"></i></a>';
    }
    return '<nav class="md-pager" aria-label="' . md_e(md_t('pager_aria')) . '">' . $left . $right . '</nav>';
}

function md_show_doc($docRel, $tree, $cfg, $forceDirRel = null)
{
    $abs = md_resolve($docRel, $cfg, 'md');
    if ($abs === false) {
        md_abort(404);
    }
    $text = md_read_text($abs);
    if ($text === false) {
        md_abort(404);
    }
    $meta = md_front_matter($text);
    if (!empty($meta['draft']) && !md_is_admin()) {
        md_abort(404);                              // 草稿对访客不可见；管理员登录态可预览（v1.15.0）
    }
    $body = md_strip_front_matter($text);
    $r = md_render($body, $cfg);
    $html = md_rewrite_links($r['html'], $docRel, $cfg);

    // 搜索词高亮：命中本篇时把正文与标题里的关键词包上 <mark>
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    if ($q !== '' && strlen($q) <= 64) {
        $html = md_highlight_headings($html, $q);
        $html = md_highlight_in_html($html, $q);
    }

    $dirRel = $forceDirRel !== null ? $forceDirRel : dirname($docRel);
    if ($dirRel === '.') {
        $dirRel = '';
    }
    $children = md_children($dirRel, $tree);

    $ctx = array();
    $ctx['cfg'] = $cfg;
    $ctx['mermaid'] = (strpos($html, '<pre class="mermaid">') !== false);
    $ctx['page_title'] = $meta['title'] !== '' ? $meta['title'] : md_title_of($docRel);
    $ctx['breadcrumbs'] = md_render_breadcrumbs($dirRel, $cfg, $ctx['page_title']);
    $ctx['nav_html'] = md_render_nav($tree, $cfg, $docRel);
    $ctx['toc_html'] = md_render_toc($r['toc'], $cfg);
    $ctx['content_html'] = $html;
    $ctx['bylines_html'] = md_bylines_html($docRel, $html, $abs);    // 阅读时长 + 相对更新时间
    $ctx['feedback_html'] = md_feedback_html($docRel);               // 反馈胶囊（builtin.js 接管）
    $ctx['edit_url'] = md_admin_url('edit', array('f' => $docRel));  // 「编辑此页」（admin_pass 非空才显示）
    $ctx['pager_html'] = md_pager_html($children, $docRel, $cfg);
    $ctx['attach_html'] = md_attach_html($children, $cfg);
    $ctx['risk_html'] = md_risk_html(md_exec_risk_files(MD_ROOT, $cfg), md_is_admin());
    // SEO：目录索引形态（README 等经目录路由渲染）的规范地址取所在目录，
    // 避免「/」与「?p=readme」两种 URL 互抢；普通文档取自身 doc 地址（剥掉可能带上的 .md 后缀）
    $ctx['seo_canonical'] = ($forceDirRel !== null || md_is_dir_index_name($docRel))
        ? md_canonical_for($dirRel, 'dir', $cfg)
        : md_canonical_for(md_strip_doc_ext($docRel, $cfg), 'doc', $cfg);
    $ctx['seo_desc'] = md_meta_description($html);
    $ctx['seo_type'] = 'article';
    $ctx['seo_crumbs'] = md_breadcrumb_data($dirRel, $cfg, $ctx['page_title']);
    md_page($ctx);
}

function md_send_file($rel, $cfg)
{
    $abs = md_resolve($rel, $cfg, 'attach');
    if ($abs === false) {
        md_abort(404);
    }
    $ext = md_ext($abs);
    $mimes = array(
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon', 'pdf' => 'application/pdf', 'zip' => 'application/zip',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'md' => 'text/markdown',
        'txt' => 'text/plain; charset=utf-8', 'markdown' => 'text/markdown',
    );
    $mime = isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream';

    $mtime = @filemtime($abs);
    $size = @filesize($abs);
    $etag = '"' . md5($abs . '|' . $mtime . '|' . $size) . '"';

    md_security_headers();
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Cache-Control: private, max-age=3600');
    header('Accept-Ranges: bytes');

    // 条件请求：命中则 304，省流量
    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
    if ($ifNoneMatch !== '' && strpos($ifNoneMatch, $etag) !== false) {
        http_response_code(304);
        exit;
    }

    // RFC 5987：ASCII 回退名 + UTF-8 真名，中文文件名不再裸放 header
    $ascii = preg_replace('/[^A-Za-z0-9._\- ]/', '_', basename($abs));
    if (trim($ascii, '. ') === '') {
        $ascii = 'download.' . $ext;
    }
    $dispName = 'filename="' . str_replace('"', '', $ascii) . '"'
        . "; filename*=UTF-8''" . rawurlencode(basename($abs));
    if ($ext === 'svg' && isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'image') {
        // Markdown ![](x.svg) 的 <img> 上下文：SVG 以图片模式加载时脚本本就不执行
        //（所有浏览器把 image 态 SVG 当惰性图），再加 script-src 'none' 双保险。
        // 其余一切上下文（直接开链接/新标签/iframe/object——诱导导航 XSS 的全部通道）
        // 仍强制下载：SVG 永远不能以文档形态进浏览器，安全不变量保持。
        header("Content-Security-Policy: script-src 'none'; object-src 'none'");
        header('Content-Disposition: inline; ' . $dispName);
    } elseif ($ext === 'svg' || $ext === 'md' || $ext === 'markdown' || $ext === 'txt') {
        header('Content-Disposition: attachment; ' . $dispName);
    }
    header('Content-Type: ' . $mime);

    // Range（单区间）：视频/音频拖进度条必需，Safari 尤其挑剔
    $range = isset($_SERVER['HTTP_RANGE']) ? trim($_SERVER['HTTP_RANGE']) : '';
    if ($range !== '' && strpos($range, ',') === false
        && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $rm) === 1
        && ($rm[1] !== '' || $rm[2] !== '')) {
        if ($rm[1] === '') {
            $start = max(0, $size - (int)$rm[2]);       // 后缀式 bytes=-N
            $end = $size - 1;
        } else {
            $start = (int)$rm[1];
            $end = ($rm[2] === '' || (int)$rm[2] >= $size) ? $size - 1 : (int)$rm[2];
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . ($end - $start + 1));
        $fp = @fopen($abs, 'rb');
        if ($fp === false) {
            exit;
        }
        fseek($fp, $start);
        $left = $end - $start + 1;
        while ($left > 0) {
            $chunk = fread($fp, min(8192, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
        }
        fclose($fp);
        exit;
    }

    header('Content-Length: ' . $size);
    readfile($abs);
    exit;
}

/**
 * 风险警示豁免判定：cfg['risk_ignore'] 是后台登记的相对路径清单。
 * 精确匹配豁免单个文件；以 / 结尾的条目按目录前缀豁免整个子树。
 * 只关闭警示——被豁免的文件照样不进文档树，不会被本程序输出。
 */
function md_risk_ignored($rel, $cfg)
{
    if (empty($cfg['risk_ignore']) || !is_array($cfg['risk_ignore'])) {
        return false;
    }
    foreach ($cfg['risk_ignore'] as $p) {
        $p = (string)$p;
        if ($p === $rel) {
            return true;
        }
        if (substr($p, -1) === '/' && strpos($rel, $p) === 0) {
            return true;
        }
    }
    return false;
}

function md_exec_risk_files($root, $cfg, $prefix = '', &$out = array())
{
    static $dangerExt = null;
    if ($dangerExt === null) {
        $dangerExt = array('php', 'php3', 'php4', 'php5', 'php7', 'phps', 'phtml', 'phar',
                           'html', 'htm', 'shtml', 'htaccess');
    }
    $items = @scandir($root);
    if ($items === false) {
        return $out;
    }
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = $root . '/' . $name;
        if (is_link($full)) {
            continue;
        }
        $rel = $prefix === '' ? $name : $prefix . '/' . $name;
        if (is_dir($full)) {
            if (in_array($name, array('node_modules', 'vendor', '.git'), true)) {
                continue;                               // 常见大目录，跳过
            }
            if ($rel === MD_DIRNAME) {
                continue;                               // 系统目录：程序自身文件，不算用户风险
            }
            md_exec_risk_files($full, $cfg, $rel, $out);
        } else {
            if ($prefix === '' && $name === 'index.php') {
                continue;                               // 程序入口，唯一执行面
            }
            $ext = md_ext($name);
            if (in_array($ext, $dangerExt, true) && !md_risk_ignored($rel, $cfg)) {
                $out[] = $rel;
                if (count($out) >= 20) {
                    return $out;                        // 最多列 20 个
                }
            }
        }
    }
    return $out;
}

/**
 * ?a=sitemap：动态输出 sitemap.xml。规范地址与页面 <link rel="canonical"> 同一套规则
 * （索引名文档并入所在目录，根目录归「/」），lastmod 取文档 mtime。
 * 推断不出站点绝对地址（site_url 未填且 Host 异常）时输出空 urlset——sitemap 协议只认绝对 URL。
 */
function md_send_sitemap($cfg)
{
    $origin = md_site_origin($cfg);
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: max-age=600');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    if ($origin === '') {
        echo "</urlset>\n";
        exit;
    }
    $tree = md_tree_load($cfg);

    $entries = array();
    $bump = function ($rel, $type, $mtime) use (&$entries, $origin, $cfg) {
        $u = md_url_abs($origin, md_canonical_for($rel, $type, $cfg));
        $mtime = (int)$mtime;
        if (!isset($entries[$u]) || $mtime > $entries[$u]) {
            $entries[$u] = $mtime;
        }
    };

    $homeRel = md_home_doc_rel(md_children('', $tree), $cfg);
    $homeM = $homeRel !== '' && isset($tree['md'][$homeRel]) ? $tree['md'][$homeRel]['mtime'] : 0;
    $bump('', 'dir', $homeM);                            // 首页永远第一项

    foreach (isset($tree['md']) ? $tree['md'] : array() as $rel => $info) {
        if (!empty($info['draft'])) {
            continue;                               // 草稿不进 sitemap（v1.15.0）
        }
        $isDir = md_is_dir_index_name($rel);
        $d = dirname(str_replace('\\', '/', $rel));
        $canonRel = $isDir ? ($d === '.' ? '' : $d) : md_strip_doc_ext($rel, $cfg);
        $bump($canonRel, $isDir ? 'dir' : 'doc', isset($info['mtime']) ? $info['mtime'] : 0);
    }

    foreach ($entries as $u => $mtime) {
        echo "  <url>\n    <loc>" . md_e($u) . "</loc>\n";
        if ($mtime > 0) {
            echo '    <lastmod>' . date('Y-m-d', $mtime) . "</lastmod>\n";
        }
        echo "  </url>\n";
    }
    echo "</urlset>\n";
    exit;
}

// ---------------------------------------------------------------- RSS/Atom 订阅（v1.13.0）

/**
 * 条目收集（纯函数，树由调用方传入）：全部文档按 mtime 降序取前 $limit 篇。
 * 目录索引文档（README.md / index.md）不单列——它在 canonical 语义里代表所在目录。
 * 同刻文档按路径升序保证输出稳定（缓存友好）。
 */
function md_rss_entries($tree, $cfg, $limit = 20)
{
    $items = array();
    foreach (isset($tree['md']) ? $tree['md'] : array() as $rel => $info) {
        if (md_is_dir_index_name($rel) || !empty($info['draft'])) {
            continue;
        }
        $items[] = array(
            'rel'   => (string)$rel,
            'title' => isset($info['title']) && (string)$info['title'] !== ''
                ? (string)$info['title']
                : md_display_name(md_strip_doc_ext((string)$rel, $cfg), $cfg),
            'mtime' => isset($info['mtime']) ? (int)$info['mtime'] : 0,
        );
    }
    usort($items, function ($a, $b) {
        if ($a['mtime'] !== $b['mtime']) {
            return $a['mtime'] > $b['mtime'] ? -1 : 1;
        }
        return strcmp($a['rel'], $b['rel']);
    });
    return array_slice($items, 0, $limit);
}

/** Atom 1.0 构建（纯函数）：转义与绝对地址全部经 md_e / md_url_abs；$origin 为空不进本函数。 */
function md_rss_xml($cfg, $origin, array $entries)
{
    $homeUrl = md_url_abs($origin, md_canonical_for('', 'dir', $cfg));
    $selfUrl = md_url_abs($origin, '?a=rss');
    $title = (string)$cfg['site_title'] !== '' ? (string)$cfg['site_title'] : 'Feed';
    $desc = isset($cfg['site_desc']) ? (string)$cfg['site_desc'] : '';
    $updated = 0;
    foreach ($entries as $e) {
        if ($e['mtime'] > $updated) {
            $updated = $e['mtime'];
        }
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n"
        . '  <title>' . md_e($title) . "</title>\n"
        . ($desc !== '' ? '  <subtitle>' . md_e($desc) . "</subtitle>\n" : '')
        . '  <id>' . md_e($homeUrl) . "</id>\n"
        . '  <link rel="alternate" href="' . md_e($homeUrl) . "\"/>\n"
        . '  <link rel="self" href="' . md_e($selfUrl) . "\"/>\n"
        . '  <updated>' . gmdate('Y-m-d\TH:i:s\Z', $updated > 0 ? $updated : time()) . "</updated>\n";
    foreach ($entries as $e) {
        $link = md_url_abs($origin, md_canonical_for(md_strip_doc_ext($e['rel'], $cfg), 'doc', $cfg));
        $xml .= "  <entry>\n"
            . '    <title>' . md_e($e['title']) . "</title>\n"
            . '    <id>' . md_e($link) . "</id>\n"
            . '    <link href="' . md_e($link) . "\"/>\n"
            . '    <updated>' . gmdate('Y-m-d\TH:i:s\Z', $e['mtime'] > 0 ? $e['mtime'] : time()) . "</updated>\n"
            . "  </entry>\n";
    }
    return $xml . "</feed>\n";
}

/** 订阅输出：与 sitemap 同一取舍——Atom 链接语义要求绝对地址，推断不出 origin 只输出空壳。 */
function md_send_rss($cfg)
{
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/atom+xml; charset=utf-8');
    header('Cache-Control: max-age=600');
    $origin = md_site_origin($cfg);
    if ($origin === '') {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n</feed>\n";
        exit;
    }
    echo md_rss_xml($cfg, $origin, md_rss_entries(md_tree_load($cfg), $cfg));
    exit;
}

/**
 * 搜索建议 JSON（v1.15.0，Ctrl+K 弹窗数据源）：?a=suggest&q=<关键词>。
 * 复用全文索引（草稿天然不可见），前 8 条 {t: 标题, u: 链接}；GET 只读无态，无 CSRF 面。
 */
function md_send_suggest($cfg)
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $q = isset($_GET['q']) ? (string)$_GET['q'] : '';
    $out = array();
    if (trim($q) !== '') {
        // 快速跳转场景新文档优先（v1.15.1）：时间序，与搜索页默认的相关度序互补
        foreach (md_search($q, $cfg, 8, 'time') as $r) {
            if (!isset($r['rel']) || (string)$r['rel'] === '') {
                continue;
            }
            $out[] = array(
                't' => (string)$r['title'],
                'u' => md_url_for(md_strip_doc_ext((string)$r['rel'], $cfg), 'doc', $cfg),
            );
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
