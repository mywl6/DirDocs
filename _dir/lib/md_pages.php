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
    // 标题行只留标签；active 类名约定为 a.on（builtin.js scrollspy 写入）
    $html = '<aside class="doc-toc d-none d-xl-block"><div class="toc-head">'
        . '<div class="toc-title">' . md_e(md_t('toc_title')) . '</div></div>'
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
    $ap = md_alias_parse($stem);                        // {{别名}} 不参与首页/README 判定
    if (in_array($ap['name'], array('README', 'readme', 'index', 'home', '首页'), true)) {
        return '<span class="tree-tag tree-tag-home">' . md_e(md_t('tree_tag_home')) . '</span>';
    }
    if ($mtime > 0 && (time() - $mtime) <= 604800) {
        return '<span class="tree-tag tree-tag-new">NEW</span>';
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
    $ap = md_alias_parse($stem);                        // README{{home}}.md 仍是目录索引文档
    return in_array($ap['name'], array('README', 'readme', 'index', 'home', '首页', '说明'), true);
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
    // 渲染器把文档里的原始 HTML 块转义输出（&lt;div …&gt;），只剥标签会把标签源码当正文
    // 抓进描述（v1.17.1 修复）：先实体解码还原成真标签，再一并剥掉
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($contentHtml, ENT_QUOTES, 'UTF-8'))));
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

/**
 * 反馈胶囊（pager 之前）：本地记忆 + 匿名计数上报（builtin.js 接管，上报失败静默回落本地）。
 * data-fb-token 是双提交 CSRF 令牌（值同时落在 HttpOnly Cookie，POST 时 hash_equals 比对）；
 * 只读模式没有端点，胶囊整个不渲染——「看起来能投票实际收不到」是误导性 UX。
 */
function md_feedback_html($docRel)
{
    if (MD_READONLY) {
        return '';
    }
    $tk = md_feedback_token();
    return '<div class="md-feedback" data-doc-rel="' . md_e($docRel) . '"'
        . ' data-fb-url="?a=feedback" data-fb-token="' . md_e($tk) . '">'
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
        // 目录页把当前目录本身标成 active（祖先只展开，不抢高亮）
        $currentCls = ($activeRel !== '' && $d['rel'] . '/' === $activeRel) ? ' active' : '';
        $folderName = md_display_name($d['name'], $cfg);
        // 折叠箭头放行尾：与文档行的图标槽对齐，长标题也不会被箭头挤压
        $html .= '<li class="tree-node md-dir"><details' . $open . '><summary class="tree-link tree-folder' . $emptyCls . $currentCls . '"'
            . ' title="' . md_e($folderName) . '">'
            . '<span class="tree-row-main"><span class="tree-row-text">' . md_e($folderName) . '</span></span>'
            . '<i class="bi bi-chevron-right folder-toggle" aria-hidden="true"></i>'
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
        $label = md_doc_label($m['rel'], $cfg);
        $html .= '<li class="tree-node"><a class="tree-link' . ($isActive ? ' active' : '') . '"'
            . ($isActive ? ' aria-current="page"' : '') . ' href="' . md_e(md_url_for($docRel, 'doc', $cfg)) . '"'
            . ' title="' . md_e($label) . '">'
            . '<span class="tree-row-main"><span class="tree-row-text">' . md_e($label) . '</span></span>'
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
    // 回退名：去后缀 + 去 {{别名}}（别名是地址，不进标题/面包屑）
    $base = basename($rel);
    $cfg = md_config_load();
    $ext = md_ext($base);
    if ($ext !== '' && in_array($ext, $cfg['ext_md'], true)) {
        $base = substr($base, 0, -(strlen($ext) + 1));
    }
    $apName = md_alias_parse($base);
    $title = $apName['name'];
    $treeMd = isset($GLOBALS['md_tree']['md']) ? $GLOBALS['md_tree']['md'] : array();
    $hit = false;
    if (isset($treeMd[$rel])) {
        $hit = $treeMd[$rel];                           // 带 .md 后缀的 rel（导航/搜索路径）
    } else {
        // doc 路由的 $rel 不带后缀，树键带后缀：补白名单后缀再查一次，
        // 否则 front-matter/H1 标题在面包屑和 <title> 里永远不生效
        foreach ($cfg['ext_md'] as $e) {
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
            foreach ($cfg['ext_md'] as $e) {
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
 * 让「01-简介.md」在无标题时显示成「简介」。{{别名}} 永远不进显示名。
 */
function md_doc_label($rel, $cfg)
{
    $stem = basename($rel, '.' . md_ext($rel));
    $plain = md_display_name($stem, $cfg);
    $title = md_title_of($rel);
    if ($title !== '' && $title !== $plain && $title !== md_alias_parse($stem)['name']) {
        return $title;
    }
    return $plain;
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
            $ap = md_alias_parse(basename($m['rel'], '.' . md_ext($m['rel'])));
            if ($ap['name'] === $name) {
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
    // 目录页把当前目录当 active 传入并补尾斜杠：侧栏会展开整条祖先分支
    $ctx['nav_html'] = md_render_nav($tree, $cfg, $dirRel === '' ? '' : $dirRel . '/');
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
            $shownPath = md_alias_url($r['rel'], 'doc', $cfg);
            if ($shownPath === false) {
                $shownPath = $r['rel'];
            }
            $html .= '<li><h3><a href="' . md_e($url) . '">'
                . md_search_highlight(md_e($r['title']), $q) . '</a></h3>'
                . '<p class="md-snippet">' . md_search_highlight(md_e($r['snippet']), $q) . '</p>'
                . '<p class="md-path">' . md_e($shownPath) . '</p></li>';
        }
        $html .= '</ul>';
    }
    $ctx['content_html'] = $html;
    md_page($ctx);
}

/** 有更新的月份清单（纯函数，可测）：非草稿文档 mtime 的 Y-m 去重集合，新的月份在前。 */
function md_recent_months(array $items)
{
    $out = array();
    foreach ($items as $info) {
        if (!empty($info['draft'])) {
            continue;
        }
        $m = date('Y-m', (int)$info['mtime']);
        $out[$m] = true;
    }
    $months = array_keys($out);
    rsort($months);
    return $months;
}

function md_show_recent($tree, $cfg)
{
    // 按月浏览（v1.16.0）：?a=recent&ym=YYYY-MM 是同一份数据的月度切片；非法 ym 一律视为全部
    $ym = isset($_GET['ym']) ? (string)$_GET['ym'] : '';
    if (preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $ym) !== 1) {
        $ym = '';
    }
    $title = $ym !== '' ? md_t('recent_ym_title', array('ym' => $ym)) : md_t('recent_title');
    $ctx = array();
    $ctx['cfg'] = $cfg;
    $ctx['page_title'] = $title;
    $ctx['breadcrumbs'] = md_render_breadcrumbs('', $cfg, $title);
    $ctx['nav_html'] = md_render_nav($tree, $cfg, '');
    $ctx['toc_html'] = '';
    $ctx['risk_html'] = md_risk_html(md_exec_risk_files(MD_ROOT, $cfg), md_is_admin());
    $ctx['seo_robots'] = 'noindex, follow';              // 排序随时变的工具页不进索引

    $items = isset($tree['md']) ? $tree['md'] : array();
    uasort($items, function ($a, $b) {
        return ($b['mtime'] - $a['mtime']);
    });
    $months = md_recent_months($items);

    $html = '<h1>' . md_e($title) . '</h1>';

    // 月份导航：全部 + 有更新的月份（最多展示最近 12 个月，当前项实心不可点）。
    // 全部视图同样要列出月份链接——只给「相邻月」会让全部视图无路可走（v1.16.0 浏览器实测抓到）
    if (count($months) > 1 || $ym !== '') {
        $html .= '<nav class="md-ymnav" aria-label="' . md_e(md_t('recent_ym_nav_aria')) . '">';
        $html .= $ym === ''
            ? '<span class="md-ym-on">' . md_e(md_t('recent_ym_all')) . '</span>'
            : '<a href="?a=recent">' . md_e(md_t('recent_ym_all')) . '</a>';
        foreach (array_slice($months, 0, 12) as $m) {
            if ($m === $ym) {
                $html .= '<span class="md-ym-on">' . md_e($m) . '</span>';
            } else {
                $html .= '<a href="?a=recent&amp;ym=' . md_e($m) . '">' . md_e($m) . '</a>';
            }
        }
        $html .= '</nav>';
    }

    $limit = 50;
    $shown = 0;
    $total = 0;
    foreach ($items as $rel => $info) {
        if (!empty($info['draft']) || ($ym !== '' && date('Y-m', (int)$info['mtime']) !== $ym)) {
            continue;
        }
        $total++;
    }
    if ($total === 0) {
        $html .= '<p><em>' . md_e($ym !== '' ? md_t('recent_ym_empty', array('ym' => $ym)) : md_t('recent_empty')) . '</em></p>';
    } else {
        $html .= '<ul class="md-recent">';
        foreach ($items as $rel => $info) {
            if (!empty($info['draft']) || ($ym !== '' && date('Y-m', (int)$info['mtime']) !== $ym)) {
                continue;                           // 草稿不进最近更新（v1.15.0）
            }
            $url = md_url_for(md_strip_doc_ext($rel, $cfg), 'doc', $cfg);
            $mtime = (int)$info['mtime'];
            $shownPath = md_alias_url($rel, 'doc', $cfg);
            if ($shownPath === false) {
                $shownPath = $rel;
            }
            $html .= '<li><span class="md-rdate">' . md_e(date('Y-m-d H:i', $mtime)) . '</span>'
                . '<a href="' . md_e($url) . '">' . md_e(md_title_of($rel)) . '</a>'
                . '<span class="md-rpath">' . md_e($shownPath) . '</span></li>';
            $shown++;
            if ($shown >= $limit) {
                break;
            }
        }
        $html .= '</ul>';
        if ($shown < $total) {
            $html .= '<p class="md-hint">' . md_e(md_t('recent_limit', array('n' => $limit, 'total' => $total))) . '</p>';
        }
    }
    $ctx['content_html'] = $html;
    md_page($ctx);
}

/**
 * 整页 404（v1.22.0）：走主题模板，保留顶栏搜索、侧边栏目录树与「最近更新」，
 * 让走错地址的访客还有路可走（旧版只有一行「页面不存在」，等于把入口关死）。
 *
 * 只用于前台文档路由；附件通道（?a=file）与后台/资产通道的 404 仍走 md_abort 的
 * 极简页——那些场景不该把整站导航回吐给探测者。
 * 状态码必须在 md_page() 之前设置：模板只负责渲染，不再改响应码。
 */
function md_show_404($tree, $cfg, $reqPath = '')
{
    http_response_code(404);

    $ctx = array();
    $ctx['cfg'] = $cfg;
    $ctx['page_title'] = '404';
    $ctx['breadcrumbs'] = md_render_breadcrumbs('', $cfg, '404');
    $ctx['nav_html'] = md_render_nav($tree, $cfg, '');
    $ctx['toc_html'] = '';
    $ctx['risk_html'] = md_risk_html(md_exec_risk_files(MD_ROOT, $cfg), md_is_admin());
    $ctx['seo_robots'] = 'noindex, follow';             // 404 不进索引，但仍允许跟随站内链接
    $ctx['seo_type'] = 'website';

    $html = '<h1 class="md-404-title">404</h1>'
        . '<p class="md-lead">' . md_e(md_t('err_404')) . '</p>';
    $reqPath = trim(str_replace('\\', '/', (string)$reqPath), '/');
    if ($reqPath !== '') {
        $html .= '<p class="md-hint">' . md_e(md_t('err_404_path', array('path' => $reqPath))) . '</p>';
    }
    // 站内搜索：移动端侧栏收在抽屉里，正文里的搜索框是 404 页最直接的出路
    $html .= '<form class="md-searchbox md-404-search" role="search" method="get" action="'
        . md_e(md_url_for('', 'search', $cfg)) . '">'
        . '<i class="bi bi-search md-search-ico" aria-hidden="true"></i>'
        . '<input type="search" class="form-control" name="q" placeholder="' . md_e(md_t('search_ph_simple')) . '"'
        . ' aria-label="' . md_e(md_t('search_aria')) . '">'
        . '<button class="btn btn-primary" type="submit">' . md_e(md_t('search_page_title')) . '</button>'
        . '</form>'
        . '<p class="md-hint">' . md_e(md_t('err_404_tip')) . '</p>';

    // 最近更新的 6 篇：从侧栏之外再给一条「马上能读点什么」的路
    $items = isset($tree['md']) ? $tree['md'] : array();
    uasort($items, function ($a, $b) {
        return (int)$b['mtime'] - (int)$a['mtime'];
    });
    $recent = '';
    $n = 0;
    foreach ($items as $rel => $info) {
        if (!empty($info['draft']) || md_is_dir_index_name($rel)) {
            continue;
        }
        $recent .= '<li><span class="md-rdate">' . md_e(md_rel_time((int)$info['mtime'])) . '</span>'
            . '<a href="' . md_e(md_url_for(md_strip_doc_ext($rel, $cfg), 'doc', $cfg)) . '">'
            . md_e(md_title_of($rel)) . '</a></li>';
        $n++;
        if ($n >= 6) {
            break;
        }
    }
    if ($recent !== '') {
        $html .= '<section class="md-404-recent"><h2>' . md_e(md_t('recent_title')) . '</h2>'
            . '<ul class="md-recent">' . $recent . '</ul></section>';
    }

    $html .= '<p class="md-404-acts">'
        . '<a class="btn btn-primary" href="' . md_e(md_url_for('', 'dir', $cfg)) . '">'
        . '<i class="bi bi-house-door" aria-hidden="true"></i>' . md_e(md_t('back_home')) . '</a>'
        . '<a class="btn btn-outline-primary" href="' . md_e(md_url_for('', 'recent', $cfg)) . '">'
        . '<i class="bi bi-clock-history" aria-hidden="true"></i>' . md_e(md_t('recent_title')) . '</a>'
        . '</p>';
    $ctx['content_html'] = $html;
    md_page($ctx);
}function md_dir_list_html($children, $cfg)
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
        $stem = basename($m['rel'], '.' . md_ext($m['rel']));
        $fileName = md_alias_parse($stem)['name'] . '.' . md_ext($m['rel']);
        $html .= '<li><span class="md-ico">' . md_ico_file() . '</span><a href="'
            . md_e(md_url_for(md_strip_doc_ext($m['rel'], $cfg), 'doc', $cfg)) . '">'
            . md_e(md_doc_label($m['rel'], $cfg)) . '</a><span class="md-meta">'
            . md_e($fileName) . '</span></li>';
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
    $videoExt = array('mp4', 'webm');
    $videos = '';
    $files = '';
    foreach ($children['attach'] as $a) {
        $url = md_url_for($a['rel'], 'file', $cfg);
        $name = $a['name'];
        $ext = md_ext($name);
        if (in_array($ext, $videoExt, true)) {
            // 视频附件：直接给原生播放器（?a=file 已支持 Range，可拖进度条）
            $videos .= '<figure class="md-video-card">'
                . '<video class="md-video" controls preload="metadata" playsinline src="' . md_e($url)
                . '" title="' . md_e($name) . '"></video>'
                . '<figcaption><span class="md-video-name">' . md_e($name) . '</span>'
                . '<span class="md-size">' . md_e(md_format_size($a['size'])) . '</span></figcaption>'
                . '</figure>';
        } elseif (in_array($ext, $imgExt, true)) {
            // 图片附件：缩略图预览，点击看原图
            $files .= '<li class="md-attach-img"><a href="' . md_e($url) . '" title="' . md_e($name) . '">'
                . '<img src="' . md_e($url) . '" alt="' . md_e($name) . '" loading="lazy">'
                . '</a><span class="md-size">' . md_e($name) . ' · ' . md_e(md_format_size($a['size'])) . '</span></li>';
        } else {
            // 非图片/视频附件：文件小图标，名称/大小交给卡片布局
            $files .= '<li><span class="md-ico">' . md_ico_file() . '</span><a href="' . md_e($url) . '">'
                . md_e($name) . '</a>'
                . '<span class="md-size">' . md_e(md_format_size($a['size'])) . '</span></li>';
        }
    }
    $out = '';
    if ($videos !== '') {
        $out .= '<div class="md-videos">' . $videos . '</div>';
    }
    if ($files !== '') {
        $out .= '<ul>' . $files . '</ul>';
    }
    return $out;
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
        $right = '<a class="md-pager-next" href="'
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
        md_show_404($tree, $cfg, $docRel);
    }
    $text = md_read_text($abs);
    if ($text === false) {
        md_show_404($tree, $cfg, $docRel);
    }
    $meta = md_front_matter($text);
    if (!empty($meta['draft']) && !md_is_admin()) {
        // 草稿对访客不可见；管理员登录态可预览（v1.15.0）。404 用整页模板，别把访客堵死
        md_show_404($tree, $cfg, $docRel);
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
    // 评论区块（v1.17.0）：md_feedback_resolve_rel 兼作树键解析——草稿/已删文档返回 false 整块不渲染，
    // 评论面与可见面/投票面口径一致
    $ctx['comments_html'] = md_cmt_html(md_feedback_resolve_rel($docRel, $tree, $cfg), $cfg);
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
            if (md_ends_with($name, '.cmt.php')) {
                continue;                               // 评论数据文件（v1.17.0）：MD_BOOT 执行锁自防护，非用户上传
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
        // 生成器溯源（v1.18.0）：Atom 规范给 generator 定义了 uri 属性，专为此用；
        // 只影响订阅源的元数据，不影响条目与阅读器行为
        . '  <generator uri="' . md_e(md_project_url()) . '">DirDocs ' . md_e(MD_VERSION) . "</generator>\n"
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

// ---------------------------------------------------------------- 反馈计数（v1.16.0）
//
// 前台「这篇文档有帮助吗」胶囊的服务端接线：匿名 POST 计数，目的只有一个——
// 让后台看得见哪些文档差评多（= 下一批该修订的文档清单）。
// 隐私边界：IP 只以 md5 哈希进当日限流记账，原文不落盘；计数文件只存聚合数与投票者哈希。

/** 计数文件：{docs:{<树键rel>:{u:n,d:n,v:{<投票者哈希>:'u'|'d'}}}}；v 是对账账本（取消/切换用）。 */
function md_feedback_store_file()
{
    return MD_DIR . '/cache/feedback.json';
}

/** 当日限流记账：{day:'YYYY-MM-DD',ips:{<投票者哈希>:n}}；只保留当天，文件自然不会膨胀。 */
function md_feedback_ip_file()
{
    return MD_DIR . '/cache/feedback_ip.json';
}

/** 投票者哈希：IP 原文绝不入盘。 */
function md_feedback_voter()
{
    return md5(isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
}

/**
 * 双提交 CSRF 令牌：值同时落在 HttpOnly Cookie 与胶囊 data 属性，POST 时 hash_equals 比对。
 * 复用搜索排序 Cookie 的 header() 写法——setcookie 数组签名是 PHP 7.3+，这里要兼容 7.1。
 */
function md_feedback_token()
{
    $tk = isset($_COOKIE['dd_fbt']) && is_string($_COOKIE['dd_fbt']) ? $_COOKIE['dd_fbt'] : '';
    if (preg_match('/^[0-9a-f]{32}$/', $tk) !== 1) {
        $tk = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            $base = md_base_path(md_config_load());
            $path = ($base === '' || $base === false) ? '/' : rtrim($base, '/') . '/';
            header('Set-Cookie: dd_fbt=' . $tk . '; Max-Age=31536000; Expires='
                . gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000) . '; Path=' . $path
                . '; HttpOnly; SameSite=Lax', false);
        }
    }
    return $tk;
}

function md_feedback_load()
{
    $f = md_feedback_store_file();
    $d = is_file($f) ? json_decode((string)md_read_text($f), true) : null;
    return is_array($d) && isset($d['docs']) && is_array($d['docs'])
        ? $d : array('docs' => array());
}

function md_feedback_save(array $data)
{
    return md_write_file(md_feedback_store_file(),
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP));
}

function md_feedback_ip_load()
{
    $f = md_feedback_ip_file();
    $d = is_file($f) ? json_decode((string)md_read_text($f), true) : null;
    return is_array($d) && isset($d['ips']) && is_array($d['ips'])
        ? array('day' => isset($d['day']) ? (string)$d['day'] : '', 'ips' => $d['ips'])
        : array('day' => '', 'ips' => array());
}

function md_feedback_ip_save(array $data)
{
    return md_write_file(md_feedback_ip_file(),
        json_encode($data, JSON_UNESCAPED_UNICODE));
}

/**
 * 投票对账（纯函数，可测）：按投票者账本先撤销旧票再记新票，切换/取消都守恒。
 * $vcap 是单文档投票者账本上限：超出后仍计数但不记账（该投票者的后续取消无法对账，
 * 极热文档可接受微量通胀）——防的是账本无限膨胀，不是准确性。
 * 返回新的 docs 数组；输入原数组不被修改。
 */
function md_feedback_apply(array $docs, $rel, $state, $voter, $vcap = 2000)
{
    if (!in_array($state, array('up', 'down', ''), true)) {
        return $docs;
    }
    $cur = isset($docs[$rel]) && is_array($docs[$rel]) ? $docs[$rel] : array();
    $u = isset($cur['u']) ? (int)$cur['u'] : 0;
    $d = isset($cur['d']) ? (int)$cur['d'] : 0;
    $v = isset($cur['v']) && is_array($cur['v']) ? $cur['v'] : array();
    $old = isset($v[$voter]) && in_array($v[$voter], array('up', 'down'), true) ? $v[$voter] : '';
    if ($old === 'up' && $u > 0) {
        $u--;
    }
    if ($old === 'down' && $d > 0) {
        $d--;
    }
    if ($state === 'up') {
        $u++;
    } elseif ($state === 'down') {
        $d++;
    }
    if ($state === '') {
        unset($v[$voter]);
    } elseif (isset($v[$voter]) || count($v) < $vcap) {
        $v[$voter] = $state;
    }
    $docs[$rel] = array('u' => $u, 'd' => $d, 'v' => $v);
    return $docs;
}

/** 当日限流（纯函数，可测）：跨天自动清零；达到 $max 当日拒绝。返回 array(是否放行, 新数据)。 */
function md_feedback_daily_hit(array $data, $voter, $today, $max = 60)
{
    $day = isset($data['day']) ? (string)$data['day'] : '';
    $ips = isset($data['ips']) && is_array($data['ips']) ? $data['ips'] : array();
    if ($day !== (string)$today) {
        $ips = array();
    }
    $n = isset($ips[$voter]) ? (int)$ips[$voter] : 0;
    if ($n >= $max) {
        return array(false, array('day' => (string)$today, 'ips' => $ips));
    }
    $ips[$voter] = $n + 1;
    return array(true, array('day' => (string)$today, 'ips' => $ips));
}

/**
 * rel → 树键解析（纯函数，可测）：胶囊里的 docRel 不带后缀，按白名单后缀补全后查树；
 * 文档不存在或为草稿一律拒绝（草稿对访客不可见，投票面必须同样不可见）。返回树键或 false。
 */
function md_feedback_resolve_rel($rel, $tree, $cfg)
{
    $rel = str_replace('\\', '/', trim((string)$rel, '/'));
    if ($rel === '' || md_starts_with($rel, '_dir')) {
        return false;
    }
    $treeMd = isset($tree['md']) && is_array($tree['md']) ? $tree['md'] : array();
    $candidates = in_array(md_ext($rel), $cfg['ext_md'], true) ? array($rel) : array();
    foreach ($cfg['ext_md'] as $e) {
        $candidates[] = $rel . '.' . $e;
    }
    foreach ($candidates as $c) {
        if (isset($treeMd[$c])) {
            return empty($treeMd[$c]['draft']) ? $c : false;
        }
    }
    return false;
}

/**
 * 反馈投票端点：POST ?a=feedback，参数 p=<docRel> v=up|down|（空=取消）t=<双提交令牌>。
 * JSON 应答；前端失败一律静默回落本地记忆（内网/断网环境胶囊照常工作，只是不入库）。
 */
function md_send_feedback($tree, $cfg)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $md_fb_fail = function ($code) {
        http_response_code($code);
        echo '{"ok":false}';
        exit;
    };
    if (MD_READONLY || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $md_fb_fail(405);
    }
    $state = isset($_POST['v']) ? (string)$_POST['v'] : '';
    if ($state !== 'up' && $state !== 'down' && $state !== '') {
        $md_fb_fail(400);
    }
    $tk = isset($_POST['t']) ? (string)$_POST['t'] : '';
    $ck = isset($_COOKIE['dd_fbt']) && is_string($_COOKIE['dd_fbt']) ? $_COOKIE['dd_fbt'] : '';
    if ($tk === '' || $ck === '' || !hash_equals($ck, $tk)) {
        $md_fb_fail(403);
    }
    $key = md_feedback_resolve_rel(isset($_POST['p']) ? (string)$_POST['p'] : '', $tree, $cfg);
    if ($key === false) {
        $md_fb_fail(404);
    }
    $voter = md_feedback_voter();
    list($ok, $ipData) = md_feedback_daily_hit(md_feedback_ip_load(), $voter, date('Y-m-d'));
    if (!$ok) {
        $md_fb_fail(429);
    }
    $data = md_feedback_load();
    $docs = md_feedback_apply($data['docs'], $key, $state, $voter);
    // 顺手剪枝：文档已删除/更名后旧条目不再保留（键即树键，直查即判）
    $treeMd = isset($tree['md']) && is_array($tree['md']) ? $tree['md'] : array();
    $data['docs'] = array();
    foreach ($docs as $rel => $c) {
        if (isset($treeMd[$rel]) && ((isset($c['u']) && (int)$c['u'] > 0) || (isset($c['d']) && (int)$c['d'] > 0))) {
            $data['docs'][$rel] = $c;
        }
    }
    md_feedback_save($data);
    md_feedback_ip_save($ipData);
    echo '{"ok":true}';
    exit;
}

// ================================================================= 评论（v1.17.0）
//
// 数据放文档旁：<文档名>.cmt.php，首行是 PHP 执行锁（MD_BOOT 闸门），锁后是 JSON——
// 站点能跑就等于 PHP 能执行，这把锁不依赖任何服务器配置（config.php / cache/*.php 同惯例）。
// 写路径全程 flock 持锁事务（锁文件在 _dir/cache/cmt_locks/，按文档哈希分锁），数据文件经
// md_write_file 原子替换，无锁读者永远不会读到半截文件。评论一律先进 pending，后台「评论」
// 页批准后才对访客可见；MD_READONLY 全链路关闭。

/** 执行锁门行：直接 HTTP 访问时 MD_BOOT 未定义，PHP 执行即 403 退出；读取方按本行长度截断。 */
function md_cmt_gate()
{
    return '<?php /* DirDocs comment store; direct web access is denied. */'
        . ' if (!defined("MD_BOOT")) { http_response_code(403); exit; } ?>';
}

function md_cmt_file($rel)
{
    return MD_ROOT . '/' . $rel . '.cmt.php';
}

function md_cmt_fresh()
{
    return array('next_id' => 1, 'comments' => array());
}

/**
 * 解析评论数据（纯函数，可测）：门行不符或 JSON 坏一律 null——渲染按空数据处理，
 * 写入（事务）拒绝覆盖，宁可停摆也不吞用户数据。
 */
function md_cmt_parse($raw)
{
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $gate = md_cmt_gate();
    if (substr($raw, 0, strlen($gate)) !== $gate) {
        return null;
    }
    $d = json_decode(substr($raw, strlen($gate)), true);
    if (!is_array($d) || !isset($d['comments']) || !is_array($d['comments'])) {
        return null;
    }
    $d['next_id'] = isset($d['next_id']) ? (int)$d['next_id'] : 1;
    return $d;
}

/** 渲染侧读取：文件缺失/损坏按空数据处理（只读不写，无破坏面）。 */
function md_cmt_load($rel)
{
    $raw = @file_get_contents(md_cmt_file($rel));
    if ($raw === false) {
        return md_cmt_fresh();
    }
    $d = md_cmt_parse($raw);
    return is_array($d) ? $d : md_cmt_fresh();
}

/**
 * 持锁事务：$fn(数据) 返回新数据数组则原子写回，返回非数组则放弃修改（返回 true 仍算成功）。
 * 锁文件放 _dir/cache/cmt_locks/<md5(rel)>.lock——文档目录只留 .cmt.php 一个文件（数据随文档走），
 * 锁属可再生基础设施归 _dir。$root 可注入（测试用）。
 */
function md_cmt_txn($rel, $fn, $root = null)
{
    $base = $root === null ? MD_ROOT : $root;
    $lockDir = ($root === null ? MD_DIR : $root . '/_dir') . '/cache/cmt_locks';
    if (!is_dir($lockDir) && !@mkdir($lockDir, 0755, true)) {
        return false;
    }
    $fp = @fopen($lockDir . '/' . md5($rel) . '.lock', 'c');
    if ($fp === false) {
        return false;
    }
    $done = false;
    if (flock($fp, LOCK_EX)) {
        $raw = @file_get_contents($base . '/' . $rel . '.cmt.php');
        if ($raw === false) {
            $data = md_cmt_fresh();
        } else {
            $data = md_cmt_parse($raw);
        }
        if (!is_array($data)) {
            $done = false;                          // 文件存在但损坏：拒绝盲写覆盖
        } else {
            $new = call_user_func($fn, $data);
            if (is_array($new)) {
                $done = md_write_file($base . '/' . $rel . '.cmt.php',
                    md_cmt_gate() . json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $done = true;                       // 无修改视作事务成功
            }
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $done;
}

/** 字符长度（mbstring 不保证存在，引擎同款降级思路）。 */
function md_cmt_strlen($s)
{
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($s, 'UTF-8');
    }
    $n = preg_match_all('/./us', $s);
    return $n === false ? strlen($s) : (int)$n;
}

/** 按字符截断（通知邮件预览用）。 */
function md_cmt_cut($s, $n)
{
    if (md_cmt_strlen($s) <= $n) {
        return $s;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $n, 'UTF-8') . '…';
    }
    return substr($s, 0, $n * 2) . '…';
}

/**
 * 评论字段校验（纯函数，可测）：返回规范化数组或 false。邮箱选填；
 * UTF-8 不合法直接拒；正文剥掉控制字符（换行/制表除外），上限 2000 字。
 */
function md_cmt_validate($author, $email, $body)
{
    $author = trim((string)$author);
    $email = trim((string)$email);
    $body = trim((string)$body);
    foreach (array($author, $email, $body) as $v) {
        if ($v !== '' && !preg_match('//u', $v)) {
            return false;
        }
    }
    $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body);
    $author = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $author);
    if ($author === '' || md_cmt_strlen($author) > 40) {
        return false;
    }
    if ($body === '' || md_cmt_strlen($body) > 2000) {
        return false;
    }
    if ($email !== ''
        && (md_cmt_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        return false;
    }
    return array('author' => $author, 'email' => $email, 'body' => $body);
}

/** 回复目标检查（纯函数，可测）：0 = 顶层；否则必须是本篇内已通过的评论 id。 */
function md_cmt_reply_ok(array $data, $rt)
{
    $rt = (int)$rt;
    if ($rt <= 0) {
        return true;
    }
    foreach ($data['comments'] as $c) {
        if ((int)$c['id'] === $rt && isset($c['status']) && $c['status'] === 'approved') {
            return true;
        }
    }
    return false;
}

/** 追加（纯函数，可测）：分配自增 id，pending 入队。$cap 防单文档无限膨胀。 */
function md_cmt_add(array $data, array $cmt, $cap = 1000)
{
    if (count($data['comments']) >= (int)$cap) {
        return null;
    }
    $cmt['id'] = (int)$data['next_id'];
    $data['next_id'] = (int)$data['next_id'] + 1;
    $data['comments'][] = $cmt;
    return $data;
}

/** 状态变更（纯函数，可测）：目标不存在原样返回。 */
function md_cmt_set_status(array $data, $id, $status)
{
    foreach ($data['comments'] as $i => $c) {
        if ((int)$c['id'] === (int)$id) {
            $data['comments'][$i]['status'] = (string)$status;
            return $data;
        }
    }
    return $data;
}

/** 删除（纯函数，可测）：目标不存在原样返回。 */
function md_cmt_remove(array $data, $id)
{
    foreach ($data['comments'] as $i => $c) {
        if ((int)$c['id'] === (int)$id) {
            array_splice($data['comments'], $i, 1);
            return $data;
        }
    }
    return $data;
}

/** 展示集（纯函数，可测）：仅 approved，追加序即时间序。 */
function md_cmt_visible(array $data)
{
    $out = array();
    foreach ($data['comments'] as $c) {
        if (isset($c['status']) && $c['status'] === 'approved') {
            $out[] = $c;
        }
    }
    return $out;
}

/** 评论当日限流账本（与投票账本分离：阈值不同、写入频度不同，不互相争文件）。 */
function md_cmt_ip_file()
{
    return MD_DIR . '/cache/cmt_ip.json';
}

function md_cmt_ip_load()
{
    $f = md_cmt_ip_file();
    $d = is_file($f) ? json_decode((string)md_read_text($f), true) : null;
    return is_array($d) && isset($d['ips']) && is_array($d['ips'])
        ? array('day' => isset($d['day']) ? (string)$d['day'] : '', 'ips' => $d['ips'])
        : array('day' => '', 'ips' => array());
}

function md_cmt_ip_save(array $data)
{
    return md_write_file(md_cmt_ip_file(), json_encode($data, JSON_UNESCAPED_UNICODE));
}

/** 无 JS 提交的回跳地址：闪信码 + 锚点；p 无法解析时回首页。 */
function md_cmt_back_url($p, $tree, $cfg, $flash)
{
    $key = md_feedback_resolve_rel($p, $tree, $cfg);
    $u = $key === false ? md_url_for('', 'dir', $cfg)
        : md_url_for(md_strip_doc_ext($key, $cfg), 'doc', $cfg);
    return $u . (strpos($u, '?') !== false ? '&' : '?') . 'cmt=' . rawurlencode((string)$flash) . '#md-comments';
}

/**
 * 评论区块（pager 之前）：先审后显，只列 approved。$key 为树键（带后缀），由
 * md_show_doc 用 md_feedback_resolve_rel 解析（草稿/不存在 → false → 整块不渲染，
 * 投票面与评论面口径一致）。无 JS 走原生表单提交 + 端点 303 回跳闪信；JS 增强在 builtin.js。
 */
function md_cmt_html($key, $cfg)
{
    if ($key === false || empty($cfg['comments']) || MD_READONLY) {
        return '';
    }
    $data = md_cmt_load($key);
    $list = md_cmt_visible($data);
    $rel = md_strip_doc_ext($key, $cfg);
    $byId = array();
    foreach ($list as $c) {
        $byId[(int)$c['id']] = $c;
    }
    $flashInner = '';
    $flashCls = '';
    $g = isset($_GET['cmt']) && is_string($_GET['cmt']) ? $_GET['cmt'] : '';
    if ($g === '1') {
        $flashInner = md_e(md_t('cmt_ok'));
        $flashCls = ' is-ok';
    } elseif ($g === '2' || $g === '3') {
        $flashInner = md_e($g === '3' ? md_t('cmt_err_rate') : md_t('cmt_err'));
        $flashCls = ' is-err';
    }
    $items = '';
    foreach ($list as $c) {
        $rt = (int)$c['reply_to'];
        $isReply = $rt > 0 && isset($byId[$rt]);
        $author = (string)$c['author'];
        $first = function_exists('mb_substr') ? mb_substr($author, 0, 1, 'UTF-8') : substr($author, 0, 1);
        $items .= '<li class="md-cmt-item' . ($isReply ? ' is-reply' : '') . '">'
            . '<span class="md-cmt-avatar" aria-hidden="true">' . md_e($first) . '</span>'
            . '<div class="md-cmt-main">'
            . '<div class="md-cmt-meta"><b>' . md_e($author) . '</b>'
            . '<time>' . md_e(md_rel_time((int)$c['time'])) . '</time>'
            . ($isReply ? '<span class="md-cmt-reply-tag">' . md_e(md_t('cmt_replying',
                array('name' => (string)$byId[$rt]['author']))) . '</span>' : '')
            . '</div>'
            . '<p class="md-cmt-body">' . md_e((string)$c['body']) . '</p>'
            . '<button type="button" class="md-cmt-act" data-cmt-reply="' . (int)$c['id'] . '"'
            . ' data-cmt-author="' . md_e($author) . '">' . md_e(md_t('cmt_reply_btn')) . '</button>'
            . '</div></li>';
    }
    if ($items === '') {
        $items = '<li class="md-cmt-empty">' . md_e(md_t('cmt_empty')) . '</li>';
    }
    return '<section class="md-comments" id="md-comments">'
        . '<h2>' . md_e(md_t('cmt_title')) . ' <span class="md-cmt-count">'
        . md_e(md_t('cmt_count', array('n' => count($list)))) . '</span></h2>'
        . '<ol class="md-cmt-list">' . $items . '</ol>'
        . '<form class="md-cmt-form" method="post" action="?a=comment" data-cmt-url="?a=comment"'
        . ' data-cmt-ok="' . md_e(md_t('cmt_ok')) . '"'
        . ' data-cmt-err="' . md_e(md_t('cmt_err')) . '"'
        . ' data-cmt-rate="' . md_e(md_t('cmt_err_rate')) . '"'
        . ' data-cmt-busy="' . md_e(md_t('cmt_submitting')) . '"'
        . ' data-cmt-replying="' . md_e(md_t('cmt_replying', array('name' => ''))) . '">'
        // 闪信容器恒在（JS 提交的行内反馈要往里写），无回跳码时隐藏
        . '<div class="md-cmt-flash' . $flashCls . '"' . ($flashInner === '' ? ' hidden' : '') . '>'
        . $flashInner . '</div>'
        . '<input type="hidden" name="p" value="' . md_e($rel) . '">'
        . '<input type="hidden" name="t" value="' . md_e(md_feedback_token()) . '">'
        . '<input type="hidden" name="ts" value="' . time() . '">'
        . '<input type="hidden" name="rt" value="0" data-cmt-rt>'
        . '<input type="text" name="hp" value="" class="md-cmt-hp" tabindex="-1" autocomplete="off" aria-hidden="true">'
        . '<span class="md-cmt-replying" hidden><span data-cmt-rt-name></span>'
        . ' <button type="button" class="md-cmt-act" data-cmt-rt-cancel>' . md_e(md_t('cmt_reply_cancel')) . '</button></span>'
        . '<div class="md-cmt-row">'
        . '<label>' . md_e(md_t('cmt_name'))
        . '<input type="text" name="author" required maxlength="40" placeholder="' . md_e(md_t('cmt_name_ph')) . '"></label>'
        . '<label>' . md_e(md_t('cmt_email'))
        . '<input type="email" name="email" maxlength="254"></label>'
        . '</div>'
        . '<label>' . md_e(md_t('cmt_body'))
        . '<textarea name="body" rows="4" required maxlength="2000" placeholder="' . md_e(md_t('cmt_body_ph')) . '"></textarea></label>'
        . '<button type="submit" class="md-cmt-submit">' . md_e(md_t('cmt_submit')) . '</button>'
        . '<p class="md-cmt-note">' . md_e(md_t('cmt_pending_note')) . ' ' . md_e(md_t('cmt_email_hint')) . '</p>'
        . '</form></section>';
}

/**
 * 评论端点：POST ?a=comment，参数 p=<docRel> author/email/body rt=<回复id>，
 * t=<双提交令牌> ts=<渲染时间> hp=<蜜罐，必须为空>。JS 请求回 JSON；原生表单提交 303 回跳
 * （cmt=1 通过 / 2 失败 / 3 限流）。机器痕迹（蜜罐被填 / 渲染后 2 秒内提交 / 未来时间戳）
 * 假装成功不写入——不给探测信号，真正的门是先审后显。
 */
function md_send_comment($tree, $cfg)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $ps = isset($_POST['p']) && is_string($_POST['p']) ? $_POST['p'] : '';
    $md_cmt_ok = function () use ($tree, $cfg, $ajax, $ps) {
        if ($ajax) {
            echo '{"ok":true}';
        } else {
            header('Location: ' . md_cmt_back_url($ps, $tree, $cfg, '1'));
        }
        exit;
    };
    $md_cmt_fail = function ($code, $flash = '2') use ($tree, $cfg, $ajax, $ps) {
        if ($ajax) {
            http_response_code($code);
            echo '{"ok":false}';
        } else {
            header('Location: ' . md_cmt_back_url($ps, $tree, $cfg, $flash));
        }
        exit;
    };
    if (MD_READONLY || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $md_cmt_fail(405);
    }
    $tk = isset($_POST['t']) && is_string($_POST['t']) ? $_POST['t'] : '';
    $ck = isset($_COOKIE['dd_fbt']) && is_string($_COOKIE['dd_fbt']) ? $_COOKIE['dd_fbt'] : '';
    if ($tk === '' || $ck === '' || !hash_equals($ck, $tk)) {
        $md_cmt_fail(403);
    }
    $hp = isset($_POST['hp']) && is_string($_POST['hp']) ? $_POST['hp'] : '';
    $ts = isset($_POST['ts']) && is_string($_POST['ts']) ? $_POST['ts'] : '';
    if ($hp !== '' || !ctype_digit($ts) || (time() - (int)$ts) < 2 || (int)$ts > time() + 60) {
        $md_cmt_ok();
    }
    $key = md_feedback_resolve_rel($ps, $tree, $cfg);
    if ($key === false) {
        $md_cmt_fail(404);
    }
    $v = md_cmt_validate(
        isset($_POST['author']) && is_string($_POST['author']) ? $_POST['author'] : '',
        isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '',
        isset($_POST['body']) && is_string($_POST['body']) ? $_POST['body'] : ''
    );
    if ($v === false) {
        $md_cmt_fail(400);
    }
    // 当日限流（独立账本，10/天，比投票严）：只统计过了字段校验的提交
    list($ok, $ipData) = md_feedback_daily_hit(md_cmt_ip_load(), md_feedback_voter(), date('Y-m-d'), 10);
    if (!$ok) {
        $md_cmt_fail(429, '3');
    }
    md_cmt_ip_save($ipData);
    $rt = isset($_POST['rt']) && is_string($_POST['rt']) && ctype_digit($_POST['rt']) ? (int)$_POST['rt'] : 0;
    $done = md_cmt_txn($key, function (array $data) use ($v, $rt) {
        if (!md_cmt_reply_ok($data, $rt)) {
            return null;                            // 回复目标消失/未过审：放弃本次
        }
        return md_cmt_add($data, array(
            'author'   => $v['author'],
            'email'    => $v['email'],
            'body'     => $v['body'],
            'time'     => time(),
            'reply_to' => $rt,
            'status'   => 'pending',
            'ip_hash'  => md_feedback_voter(),      // 审计用，同反馈哈希纪律，永不输出
        ));
    });
    if (!$done) {
        $md_cmt_fail(500);                          // 满容量/盘坏/文件损坏：并入通用失败
    }
    md_cmt_notify_new($cfg, $key, $v);              // 静默：通知失败不影响提交
    $md_cmt_ok();
}

// ---------------------------------------------------------------- 邮件通知（v1.17.0，随包休眠）
//
// PHP mail() 在廉价虚拟主机上基本不可靠，这里内置极简 SMTP 客户端（AUTH LOGIN，
// 隐式 TLS / STARTTLS / 明文三通道，纯文本 + base64 正文）。smtp_host 留空 = 整体休眠，
// 零开销零外联；发信失败一律静默——评论入库是主事务，通知只是尽力而为。

/** 通知开关（纯函数，可测）：评论开启 + SMTP 主机 + 合法收件邮箱三者齐备。 */
function md_cmt_notify_enabled($cfg)
{
    return !empty($cfg['comments'])
        && trim((string)(isset($cfg['smtp_host']) ? $cfg['smtp_host'] : '')) !== ''
        && filter_var(trim((string)(isset($cfg['notify_email']) ? $cfg['notify_email'] : '')), FILTER_VALIDATE_EMAIL) !== false;
}

/** RFC 2047 主题编码（纯函数，可测）：UTF-8 → =?UTF-8?B?..?=。 */
function md_mail_subject($s)
{
    return '=?UTF-8?B?' . base64_encode((string)$s) . '?=';
}

/** 文档绝对地址（邮件里用）：origin + 文档 URL + 锚点；推断不出 origin 则空。 */
function md_cmt_doc_url($cfg, $key)
{
    $origin = md_site_origin($cfg);
    if ($origin === '') {
        return '';
    }
    return md_url_abs($origin, md_url_for(md_strip_doc_ext($key, $cfg), 'doc', $cfg) . '#md-comments');
}

/**
 * 极简 SMTP 发信：不引依赖，连接/会话超时 8 秒，任何一步应答不符即放弃返回 false。
 * smtp_secure：''（及老配置 'auto'）= 先明文连、服务器支持 STARTTLS 即升级；
 * 'ssl' = 隐式 TLS（常配 465）；'none' = 全程明文（仅内网中继）。
 */
function md_smtp_send($cfg, $to, $subject, $body)
{
    $host = trim((string)(isset($cfg['smtp_host']) ? $cfg['smtp_host'] : ''));
    if ($host === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    $port = (int)(isset($cfg['smtp_port']) ? $cfg['smtp_port'] : 0);
    if ($port <= 0 || $port > 65535) {
        $port = 465;
    }
    $secure = (string)(isset($cfg['smtp_secure']) ? $cfg['smtp_secure'] : '');
    if ($secure === 'auto') {
        $secure = '';
    }
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port,
        $errno, $errstr, 8);
    if ($fp === false) {
        return false;
    }
    stream_set_timeout($fp, 8);

    /** 读一个完整应答块：SMTP 多行应答以「第 4 字符为空格」的行收尾。 */
    $readAll = function () use ($fp) {
        $out = '';
        for ($i = 0; $i < 60; $i++) {
            $line = fgets($fp, 1024);
            if ($line === false || $line === '') {
                break;
            }
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };

    $resp = $readAll();
    if (strpos($resp, '220') !== 0) {
        fclose($fp);
        return false;
    }
    // EHLO 名用站点主机名（去 scheme/路径/端口），推导不出退回 localhost
    $heloHost = preg_replace('/^[a-z][a-z0-9+.-]*:\/\//i', '', md_site_origin($cfg));
    $heloHost = trim(preg_replace('/[\/:\?#].*$/', '', (string)$heloHost));
    if ($heloHost === '') {
        $heloHost = 'localhost';
    }
    fwrite($fp, 'EHLO ' . $heloHost . "\r\n");
    $caps = $readAll();
    if (strpos($caps, '250') !== 0) {
        fwrite($fp, 'HELO ' . $heloHost . "\r\n");
        $caps = $readAll();
        if (strpos($caps, '250') !== 0) {
            fclose($fp);
            return false;
        }
    }
    if ($secure === '' && preg_match('/^250[- ]STARTTLS/m', $caps) === 1) {
        fwrite($fp, "STARTTLS\r\n");
        if (strpos($readAll(), '220') !== 0
            || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        fwrite($fp, 'EHLO ' . $heloHost . "\r\n");
        if (strpos($readAll(), '250') !== 0) {
            fclose($fp);
            return false;
        }
    }
    $user = trim((string)(isset($cfg['smtp_user']) ? $cfg['smtp_user'] : ''));
    if ($user !== '') {
        fwrite($fp, "AUTH LOGIN\r\n");
        if (strpos($readAll(), '334') !== 0) {
            fclose($fp);
            return false;
        }
        fwrite($fp, base64_encode($user) . "\r\n");
        if (strpos($readAll(), '334') !== 0) {
            fclose($fp);
            return false;
        }
        fwrite($fp, base64_encode((string)(isset($cfg['smtp_pass']) ? $cfg['smtp_pass'] : '')) . "\r\n");
        if (strpos($readAll(), '235') !== 0) {
            fclose($fp);
            return false;
        }
    }
    $from = trim((string)(isset($cfg['smtp_from']) ? $cfg['smtp_from'] : ''));
    if ($from === '') {
        $from = $user;
    }
    if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
        fclose($fp);
        return false;
    }
    fwrite($fp, 'MAIL FROM:<' . $from . ">\r\n");
    if (strpos($readAll(), '250') !== 0) {
        fclose($fp);
        return false;
    }
    fwrite($fp, 'RCPT TO:<' . $to . ">\r\n");
    $rcpt = $readAll();
    if (strpos($rcpt, '250') !== 0 && strpos($rcpt, '251') !== 0) {
        fclose($fp);
        return false;
    }
    fwrite($fp, "DATA\r\n");
    if (strpos($readAll(), '354') !== 0) {
        fclose($fp);
        return false;
    }
    $mail = 'From: =?UTF-8?B?' . base64_encode((string)$cfg['site_title']) . '?= <' . $from . ">\r\n"
        . 'To: <' . $to . ">\r\n"
        . 'Subject: ' . md_mail_subject($subject) . "\r\n"
        . 'Date: ' . date('D, d M Y H:i:s O') . "\r\n"
        . 'Message-ID: <' . md5($to . microtime()) . "@dirdocs>\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode((string)$body));
    // 点 stuffing（行首点转义）：信封指令与自产报头都不会以点开头，统一过一遍最稳
    fwrite($fp, preg_replace('/^\\./m', '..', $mail) . "\r\n.\r\n");
    $sent = strpos($readAll(), '250') === 0;
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $sent;
}

/** 新评论待审 → 站长邮箱（静默）。 */
function md_cmt_notify_new($cfg, $key, array $v)
{
    if (!md_cmt_notify_enabled($cfg)) {
        return;
    }
    $url = md_cmt_doc_url($cfg, $key);
    $args = array(
        'site'   => (string)$cfg['site_title'],
        'doc'    => md_title_of($key),
        'author' => $v['author'],
        'body'   => md_cmt_cut($v['body'], 400),
        'url'    => $url !== '' ? $url : md_url_for(md_strip_doc_ext($key, $cfg), 'doc', $cfg),
    );
    @md_smtp_send($cfg, trim((string)$cfg['notify_email']),
        md_t('mail_cmt_subject', $args), md_t('mail_cmt_body', $args));
}

/** 回复被批准 → 被回复的评论作者（留了合法邮箱才发，静默）。 */
function md_cmt_notify_reply_maybe($cfg, $key, $id)
{
    if (!md_cmt_notify_enabled($cfg)) {
        return;
    }
    $data = md_cmt_load($key);
    $target = null;
    foreach ($data['comments'] as $c) {
        if ((int)$c['id'] === (int)$id) {
            $target = $c;
            break;
        }
    }
    if ($target === null || (int)$target['reply_to'] <= 0
        || !isset($target['status']) || $target['status'] !== 'approved') {
        return;
    }
    $parent = null;
    foreach ($data['comments'] as $c) {
        if ((int)$c['id'] === (int)$target['reply_to']
            && isset($c['status']) && $c['status'] === 'approved'
            && isset($c['email']) && filter_var((string)$c['email'], FILTER_VALIDATE_EMAIL) !== false) {
            $parent = $c;
            break;
        }
    }
    if ($parent === null) {
        return;
    }
    $url = md_cmt_doc_url($cfg, $key);
    $args = array(
        'site'   => (string)$cfg['site_title'],
        'doc'    => md_title_of($key),
        'author' => (string)$target['author'],
        'body'   => md_cmt_cut((string)$target['body'], 400),
        'url'    => $url !== '' ? $url : md_url_for(md_strip_doc_ext($key, $cfg), 'doc', $cfg),
    );
    @md_smtp_send($cfg, (string)$parent['email'],
        md_t('mail_reply_subject', $args), md_t('mail_reply_body', $args));
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
