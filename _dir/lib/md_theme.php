<?php
/**
 * DirDocs 主题（静态资源/CSS 变量/页面模板/用户主题） 模块（由 index.php 加载，勿单独访问）
 *
 * v2「高级技术文档主题」：前台基于 Bootstrap 5.3 + Bootstrap Icons（v1.12.0 起全资源
 * 自托管于 _dir/assets/vendor/，零外部请求），builtin.css 是 Bootstrap 之上的 DirDocs 覆盖层。
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}

// ---------------------------------------------------------------- 全站静态资源（自托管）

/**
 * 前台/后台共用静态资源清单（v1.12.0 起全资源自托管，运行时零外部请求）：
 * Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 + mermaid 10.9.1 + qrcodejs 1.0.0，
 * 全部内置在 _dir/assets/vendor/（来源、改写与校验和见 vendor/README.md），
 * 经 ?a=asset&t=_sys 通道按显式文件名白名单输出（md_send_sys_asset）。
 * 函数名与返回键位（key/label/css/js/icons/mermaid/qrcode）沿用 v1.11 CDN 预设时代的
 * 主题契约不变——第三方模板（$cdn / $mermaidUrl / md_cdn_active）对这次变更无感知。
 * $cfg 参数保留只为调用点签名兼容，已不参与任何选择逻辑。
 */
function md_cdn_active($cfg = null)
{
    $v = rawurlencode(MD_VERSION);
    return array(
        'key'     => 'local',
        'label'   => '自托管（_dir/assets/vendor/）',
        'css'     => md_url_for('vendor/bootstrap.min.css', 'sys', $cfg) . '&v=' . $v,
        'js'      => md_url_for('vendor/bootstrap.bundle.min.js', 'sys', $cfg) . '&v=' . $v,
        'icons'   => md_url_for('vendor/bootstrap-icons.min.css', 'sys', $cfg) . '&v=' . $v,
        'mermaid' => md_url_for('vendor/mermaid.min.js', 'sys', $cfg) . '&v=' . $v,
        'qrcode'  => md_url_for('vendor/qrcode.min.js', 'sys', $cfg) . '&v=' . $v,
    );
}

// ---------------------------------------------------------------- 主题包系统

/**
 * 主题模型（2026-10-14 定版）：
 *   主题 = _dir/themes/<名称>/theme.php 完整风格包（name + 浅色 vars + 深色 vars-dark，
 *          可选 view.php 整页模板覆盖、assets/ 主题资产），用户可自建风格完全不同的主题；
 *   显示模式 = config.mode（auto/light/dark），与主题解耦——任何主题都自带深浅两套色板。
 * 内置 default / wiki 两个包：可写时自动播种到 themes/（升级会覆盖内置包），
 * 不可写时使用下面硬编码的同份色板兜底（零配置保证不变）。
 */
function md_theme_builtin_manifests()
{
    $font = '-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif';
    $mono = 'ui-monospace,SFMono-Regular,"Roboto Mono","Cascadia Code",Consolas,monospace';
    $layout = array(
        '--radius' => '12px', '--radius-sm' => '8px', '--font' => $font, '--mono' => $mono,
    );
    $base = array(
        '--bg' => '#f8fafc', '--surface' => '#ffffff', '--elevated' => '#f1f5f9',
        '--border' => '#e2e8f0', '--border-subtle' => '#f1f5f9', '--border-strong' => 'rgba(15,23,42,.16)',
        '--text' => '#0f172a', '--text2' => '#64748b', '--text3' => '#94a3b8',
        '--code-bg' => '#0f172a', '--code-fg' => '#e2e8f0', '--code-gutter' => '#334155',
        '--danger' => '#dc2626', '--danger-weak' => 'rgba(220,38,38,.07)',
        '--ok' => '#059669', '--ok-weak' => 'rgba(5,150,105,.08)',
        '--warn' => '#d97706', '--warn-weak' => 'rgba(217,119,6,.08)',
    );
    $default = array(
        'name' => '默认主题',
        'dark' => false,
        'vars' => array_merge($base, $layout, array(
            '--accent' => '#2563eb', '--accent-weak' => 'rgba(37,99,235,.08)',
            '--mark-bg' => 'rgba(37,99,235,.12)',
            '--shadow' => '0 1px 3px rgba(0,0,0,.03),0 12px 30px -6px rgba(15,23,42,.04)',
            '--paper-shadow' => '0 1px 3px rgba(0,0,0,.03),0 12px 30px -6px rgba(15,23,42,.04)',
            '--topbar-bg' => 'rgba(248,250,252,.85)',
        )),
        'vars-dark' => array_merge($base, $layout, array(
            '--bg' => '#07090e', '--surface' => '#0e121e', '--elevated' => '#141a29',
            '--border' => '#1e293b', '--border-subtle' => '#141a29', '--border-strong' => 'rgba(148,163,184,.28)',
            '--text' => '#f1f5f9', '--text2' => '#94a3b8', '--text3' => '#6b7280',
            '--accent' => '#3b82f6', '--accent-weak' => 'rgba(59,130,246,.14)',
            '--mark-bg' => 'rgba(59,130,246,.22)',
            '--code-bg' => '#05070c', '--code-fg' => '#cbd5e1', '--code-gutter' => '#475569',
            '--danger' => '#f87171', '--danger-weak' => 'rgba(248,113,113,.12)',
            '--ok' => '#34d399', '--ok-weak' => 'rgba(52,211,153,.12)',
            '--warn' => '#fbbf24', '--warn-weak' => 'rgba(251,191,36,.12)',
            '--shadow' => '0 4px 24px -2px rgba(0,0,0,.5)',
            '--paper-shadow' => '0 4px 24px -2px rgba(0,0,0,.5)',
            '--topbar-bg' => 'rgba(7,9,14,.8)',
        )),
    );
    $wiki = $default;
    $wiki['name'] = 'Wiki（青绿）';
    $wiki['vars'] = array_merge($default['vars'], array(
        '--accent' => '#0f766e', '--accent-weak' => 'rgba(15,118,110,.08)',
        '--bs-primary' => '#0f766e', '--bs-primary-rgb' => '15,118,110',
        '--bs-link-color' => '#0f766e', '--bs-link-color-rgb' => '15,118,110',
        '--bs-link-hover-color' => '#0d5f59',
    ));
    $wiki['vars-dark'] = array_merge($default['vars-dark'], array(
        '--accent' => '#5eead4', '--accent-weak' => 'rgba(94,234,212,.1)',
        '--bs-primary' => '#5eead4', '--bs-primary-rgb' => '94,234,212',
        '--bs-link-color' => '#5eead4', '--bs-link-color-rgb' => '94,234,212',
        '--bs-link-hover-color' => '#8ef5e4',
    ));
    return array('default' => $default, 'wiki' => $wiki);
}

/** 色板值白名单：颜色/数值/字体栈够用；禁分号花括号冒号（CSS 注入与 url() 一律进不来）。 */
function md_theme_var_ok($v)
{
    return is_string($v) && $v !== '' && strlen($v) <= 300
        && preg_match('/^[#A-Za-z0-9 .,()%\\-\'"]+$/', $v) === 1;
}

/** 校验并归一化主题包数据；任何不合法整体拒绝（返回 false）。 */
function md_theme_manifest_check($m)
{
    if (!is_array($m) || !isset($m['vars']) || !is_array($m['vars'])) {
        return false;
    }
    $name = isset($m['name']) && is_string($m['name']) ? trim($m['name']) : '';
    if ($name === '' || strlen($name) > 40) {
        $name = false;
    }
    $clean = array('name' => $name, 'dark' => !empty($m['dark']), 'vars' => array(), 'vars-dark' => array());
    foreach (array('vars', 'vars-dark') as $g) {
        $src = isset($m[$g]) && is_array($m[$g]) ? $m[$g] : array();
        foreach ($src as $k => $v) {
            if (is_string($k) && preg_match('/^--[a-z0-9-]{1,40}$/i', $k) === 1 && md_theme_var_ok($v)) {
                $clean[$g][$k] = $v;
            }
        }
        if (!$clean[$g]) {
            // vars 必填；vars-dark 允许为空（深浅同色的主题），自动沿用浅色变量
            if ($g === 'vars') {
                return false;
            }
            $clean[$g] = $clean['vars'];
        }
    }
    return $clean;
}

/** 读主题包：themes/<名称>/theme.php 优先（校验通过才用），否则内置兜底。 */
function md_theme_manifest($theme)
{
    static $cache = array();
    $theme = preg_match('/^[a-z0-9_-]{1,32}$/', (string)$theme) === 1 ? (string)$theme : 'default';
    if (isset($cache[$theme])) {
        return $cache[$theme];
    }
    $builtins = md_theme_builtin_manifests();
    $m = false;
    $file = MD_DIR . '/themes/' . $theme . '/theme.php';
    if (is_file($file)) {
        $loaded = @include $file;                        // 主题包数据文件（return array）
        $m = md_theme_manifest_check($loaded);
    }
    if ($m !== false) {
        $cache[$theme] = $m;                             // 只缓存成功加载：包文件修好当请求即可重读
        return $m;
    }
    return isset($builtins[$theme]) ? $builtins[$theme] : $builtins['default'];
}

/** 主题是否存在（内置包 or 目录里有 theme.php / view.php）。 */
function md_theme_exists($theme)
{
    if (!preg_match('/^[a-z0-9_-]{1,32}$/', (string)$theme)) {
        return false;
    }
    if (array_key_exists($theme, md_theme_builtin_manifests())) {
        return true;
    }
    $dir = MD_DIR . '/themes/' . $theme;
    return is_file($dir . '/theme.php') || is_file($dir . '/view.php');
}

/** 可选主题清单：array(目录名 => 显示名)，供后台下拉与前台校验。 */
function md_theme_list()
{
    md_theme_seed();
    $out = array();
    foreach (md_theme_builtin_manifests() as $k => $m) {
        $out[$k] = isset($m['name']) && is_string($m['name']) && $m['name'] !== '' ? $m['name'] : $k;
    }
    $dir = MD_DIR . '/themes';
    if (is_dir($dir)) {
        $items = @scandir($dir);
        if (is_array($items)) {
            foreach ($items as $n) {
                if ($n === '.' || $n === '..' || isset($out[$n]) || !is_dir($dir . '/' . $n)) {
                    continue;
                }
                if (is_file($dir . '/' . $n . '/theme.php') || is_file($dir . '/' . $n . '/view.php')) {
                    $label = $n;                          // 兜底：目录名（绝不是内置清单的「默认主题」）
                    if (is_file($dir . '/' . $n . '/theme.php')) {
                        $m = md_theme_manifest($n);       // 包文件存在才取其显示名；
                        if (isset($m['name']) && is_string($m['name']) && $m['name'] !== '') {
                            $label = $m['name'];          // 校验失败/无 name 时保持目录名，避免重名「默认主题」
                        }
                    }
                    $out[$n] = $label;
                }
            }
        }
    }
    return $out;
}

/** 内置主题播种：可写且缺失时把 default / wiki 写成真实主题包（升级覆盖，自建不动）。 */
function md_theme_seed()
{
    static $done = false;
    if ($done || MD_READONLY) {
        return;
    }
    $done = true;
    $dir = MD_DIR . '/themes';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    foreach (md_theme_builtin_manifests() as $k => $m) {
        $file = $dir . '/' . $k . '/theme.php';
        if (!is_file($file)) {
            @mkdir($dir . '/' . $k, 0755, true);
            $body = "<?php\n// DirDocs 内置主题包（随程序升级覆盖本目录；自建主题请使用其他目录名）\n"
                . "// 结构：name 显示名 / dark 默认深色优先 / vars 浅色变量 / vars-dark 深色变量\n"
                . "return " . var_export($m, true) . ";\n";
            md_write_file($file, $body);
        }
    }
}

/** 旧配置迁移：theme=dark 是历史遗留（当时深色算一个主题），换算成 主题=default + 模式=dark。 */
function md_theme_resolve($cfg)
{
    $theme = isset($cfg['theme']) && is_string($cfg['theme']) && $cfg['theme'] !== '' ? $cfg['theme'] : 'default';
    $mode = isset($cfg['mode']) && is_string($cfg['mode']) ? $cfg['mode'] : '';
    if ($mode !== 'auto' && $mode !== 'light' && $mode !== 'dark') {
        $mode = 'auto';
    }
    if ($theme === 'dark') {
        $theme = 'default';
        if ($mode === 'auto') {
            $mode = 'dark';
        }
    }
    if (!md_theme_exists($theme)) {
        $theme = 'default';
    }
    return array('theme' => $theme, 'mode' => $mode);
}

// ---------------------------------------------------------------- CSS 变量

function md_theme_css($theme)
{
    md_theme_seed();
    $m = md_theme_manifest($theme);
    $layout = '--nav-h:56px;--sidebar-width:275px;--toc-width:220px;--toc-max:320px;--content-w:860px;';
    $emit = function ($vars) { $s = ''; foreach ($vars as $k => $v) { $s .= $k . ':' . $v . ';'; } return $s; };
    // 设计系统契约 token（与后台共用同一套变量名，builtin.css 内有逐字一致的兜底定义，
    // 本内联块位于 builtin.css 之后：同值覆写保证主题包生效）。
    // 深浅色统一由 <html data-bs-theme="light|dark|auto"> 驱动（Bootstrap 原生属性 + theme-init.js）。
    $css = ':root{' . $layout . $emit($m['vars']) . 'color-scheme:light}';
    $css .= '[data-bs-theme="dark"]{' . $layout . $emit($m['vars-dark']) . 'color-scheme:dark}';
    return $css;
}

// ---------------------------------------------------------------- head 资源 / CSP

/**
 * 前台 <head> 资源：防闪烁脚本（同步）+ Bootstrap CSS + 图标字体（自托管，经 md_cdn_active）
 * + builtin.css（覆盖层）+ 主题变量（内联）。顺序与原型一致。
 * builtin.css / theme-init.js 经 ?a=asset&t=_sys 通道输出，URL 带 &v= 版本号（长缓存失效用）。
 */
function md_theme_head_assets($cfg, $theme)
{
    $v = rawurlencode(MD_VERSION);
    $cdn = md_cdn_active($cfg);
    $out = '<script src="' . md_e(md_url_for('theme-init.js', 'sys', $cfg) . '&v=' . $v) . '"></script>' . "\n";
    $out .= '<link rel="stylesheet" href="' . md_e($cdn['css']) . '">' . "\n";
    $out .= '<link rel="stylesheet" href="' . md_e($cdn['icons']) . '">' . "\n";
    $out .= '<link rel="stylesheet" href="' . md_e(md_url_for('builtin.css', 'sys', $cfg) . '&v=' . $v) . '">' . "\n";
    $out .= '<style>' . md_theme_css($theme) . '</style>';
    return $out;
}

/**
 * 前台/后台共用 CSP（v1.12.0 起全资源自托管，不再有外部源需要放行，策略退化为纯 'self'）。
 * 页面模板无任何内联 <script>（行为全在 builtin.js 与 bootstrap bundle）；style-src
 * 保留 unsafe-inline 仅为主题变量块。外链图片被拦是既定取舍（img-src 仅 self/data:）。
 */
function md_theme_csp($cfg = null)
{
    return "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
        . "font-src 'self'; script-src 'self'; connect-src 'self'; "
        . "form-action 'self'; base-uri 'self'";
}

// ---------------------------------------------------------------- 页面调度器（主题包规范）

/**
 * 主题包规范（WordPress/Typecho 模式，2026-10-14 定版）：
 *   _dir/themes/<名称>/
 *     theme.php   主题清单（return array）：name 显示名 / dark 深浅倾向 /
 *                 vars + vars-dark 两套完整变量（数据文件，经 manifest 校验）
 *     view.php    整页模板：主题即代码（安装主题 = 安装代码，只装可信来源）。
 *                 md_page 注入上下文变量后 include，模板负责输出完整 HTML。
 *                 可用变量：$cfg $ctx $theme $mode $lang $home $searchUrl $recentUrl $q
 *                 $tocHtml $cdn $mermaidUrl $hasAdmin $headAssets $themeUrl $adminUrl，
 *                 常量 MD_VERSION，助手 md_e / md_t / md_lang_current / md_i18n_js_attr /
 *                 md_url_for / md_admin_url / md_cdn_active。
 *                 i18n 契约：<html lang> 输出 $lang；<html> 标签带 md_i18n_js_attr()
 *                 输出的 data-md-i18n 属性（builtin.js 的 JS 文案词典，缺失时 JS 回落
 *                 自带中文）；界面文案一律经 md_t() 取词，不要在模板里写死文案。
 *                 SEO 契约：<head> 里输出 $ctx['html_title']（已转义的整页标题）与
 *                 $ctx['head_meta']（description/canonical/robots/OG/JSON-LD，由 md_page
 *                 统一组装；数据来自 $ctx['seo_canonical'|'seo_desc'|'seo_robots'|'seo_type'|
 *                 'seo_crumbs']，由各视图填充），模板不要自己拼 title 与 description。
 *     assets/     主题静态资产：?a=asset&t=<名称>&f=<文件> 通道输出
 * 模板解析：主题自带 view.php 优先；纯色板包（只有 theme.php）回退 default 的模板，
 * 色板经 $headAssets 生效。default 也缺失时走应急简页保内容可读。
 */
function md_theme_view_file($theme)
{
    if (!preg_match('/^[a-z0-9_-]{1,32}$/', (string)$theme)) {
        return false;
    }
    $file = MD_DIR . '/themes/' . $theme . '/view.php';
    return !is_file($file) || is_link($file) ? false : $file;
}

/** 紧急兜底视图：主题包被删且 default 也不可用时保证内容仍可读（无样式极简页）。 */
function md_page_fallback($theme, $cfg, $ctx)
{
    $title = isset($ctx['html_title']) ? $ctx['html_title'] : md_e((string)$ctx['page_title']);
    echo '<!DOCTYPE html><html lang="' . md_e(md_lang_current()) . '" data-bs-theme="light"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $title . '</title>'
        . (isset($ctx['head_meta']) ? $ctx['head_meta'] : '')
        . '<div style="max-width:46em;margin:6vh auto;padding:0 1.2em;font:16px/1.75 system-ui,sans-serif">'
        . (isset($ctx['risk_html']) ? $ctx['risk_html'] : '')
        . (isset($ctx['breadcrumbs']) ? $ctx['breadcrumbs'] : '')
        . '<h1>' . md_e($ctx['page_title']) . '</h1>'
        . '<div>' . $ctx['content_html'] . '</div>'
        . (isset($ctx['attach_html']) ? $ctx['attach_html'] : '')
        . '<p style="color:#888">' . md_e(md_t('fallback_missing_view', array('theme' => $theme)))
        . ' <a href="' . md_e(md_url_for('', 'dir', $cfg)) . '">' . md_e(md_t('back_home')) . '</a></p></div>';
    exit;
}

/** 页面调度器：解析主题与模式 → 准备模板上下文 → include 主题模板。 */
function md_page($ctx)
{
    $cfg = $ctx['cfg'];
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Security-Policy: ' . md_theme_csp($cfg));
    header('Referrer-Policy: same-origin');
    header('Content-Type: text/html; charset=utf-8');
    // 界面语言 auto 时页面 HTML 随请求 Accept-Language 变化：提示共享缓存
    // （CDN/反代）按该头区分缓存，避免不同语言访问者命中同一份缓存页（v1.12.1）
    if ($cfg['language'] === 'auto') {
        header('Vary: Accept-Language');
    }

    // 主题（风格包）与显示模式（auto/light/dark）解耦；旧配置 theme=dark 自动迁移
    $resolved = md_theme_resolve($cfg);
    $theme = $resolved['theme'];
    $mode = $resolved['mode'];

    $view = md_theme_view_file($theme);
    if ($view === false && $theme !== 'default') {
        $view = md_theme_view_file('default');           // 纯色板包共用 default 版式
    }

    // 模板上下文契约（view.php 可用变量，详见上方主题包规范）
    $lang = md_lang_current();                           // <html lang> 与界面文案同一语言
    $home = md_url_for('', 'dir', $cfg);
    $searchUrl = md_url_for('', 'search', $cfg);
    $recentUrl = md_url_for('', 'recent', $cfg);
    $q = isset($_GET['q']) ? (string)$_GET['q'] : '';
    $tocHtml = isset($ctx['toc_html']) ? $ctx['toc_html'] : '';
    $cdn = md_cdn_active($cfg);                          // head 链接与页脚 bundle 共用一次解析
    $mermaidUrl = empty($ctx['mermaid']) ? '' : $cdn['mermaid'];   // 本地 vendor 按需加载（v1.12.0 回归自托管）
    // slim 安装（v1.16.0）：删掉 mermaid.min.js 省 3.2MB——文件缺失时不发加载点，
    // 图表块保持源码形态（内置样式本来就是代码块），其余功能零影响
    if ($mermaidUrl !== '' && !is_file(MD_DIR . '/assets/vendor/mermaid.min.js')) {
        $mermaidUrl = '';
    }
    $hasAdmin = $cfg['admin_pass'] !== '';
    $headAssets = md_theme_head_assets($cfg, $theme);    // 防闪烁脚本 + CDN + builtin.css + 主题变量
    $themeUrl = '?a=asset&t=' . rawurlencode($theme);    // 主题资产基址（拼 f=<assets/ 下文件>）
    $adminUrl = '?a=admin';

    // ---- SEO 头：全站统一在这里组装，主题模板只输出 $ctx['html_title'] / $ctx['head_meta'] ----
    $siteTitle = (string)$cfg['site_title'];
    $pageTitle = (string)$ctx['page_title'];
    // 首页等页面标题与站点名相同时不再拼后缀（避免「DirDocs · DirDocs」）
    $ctx['html_title'] = md_e($pageTitle === $siteTitle ? $siteTitle : $pageTitle . ' · ' . $siteTitle);

    $origin = md_site_origin($cfg);                      // 推断不出（Host 异常/CLI）则省略绝对地址标签
    $desc = isset($ctx['seo_desc']) && $ctx['seo_desc'] !== '' ? (string)$ctx['seo_desc'] : (string)$cfg['site_desc'];
    $meta = '';
    if ($desc !== '') {
        $meta .= '<meta name="description" content="' . md_e($desc) . "\">\n";
    }
    if (!empty($ctx['seo_robots'])) {
        $meta .= '<meta name="robots" content="' . md_e((string)$ctx['seo_robots']) . "\">\n";
    }
    // RSS/Atom 订阅发现（v1.13.0）：?a=rss 与路由模式无关，两种模式下都走查询串
    $meta .= '<link rel="alternate" type="application/atom+xml" title="' . md_e($siteTitle)
        . '" href="?a=rss">' . "\n";
    $canonAbs = '';
    if (isset($ctx['seo_canonical']) && (string)$ctx['seo_canonical'] !== '' && $origin !== '') {
        $canonAbs = md_url_abs($origin, (string)$ctx['seo_canonical']);
        $meta .= '<link rel="canonical" href="' . md_e($canonAbs) . "\">\n";
    }
    if ($desc !== '' || $pageTitle !== '') {
        $meta .= '<meta property="og:site_name" content="' . md_e($siteTitle) . "\">\n"
            . '<meta property="og:type" content="' . md_e(isset($ctx['seo_type']) ? (string)$ctx['seo_type'] : 'website') . "\">\n"
            . '<meta property="og:title" content="' . md_e($pageTitle) . "\">\n"
            . '<meta name="twitter:card" content="summary">' . "\n";
        if ($desc !== '') {
            $meta .= '<meta property="og:description" content="' . md_e($desc) . "\">\n";
        }
        if ($canonAbs !== '') {
            $meta .= '<meta property="og:url" content="' . md_e($canonAbs) . "\">\n";
        }
    }
    // JSON-LD 面包屑：与页面可见面包屑同一份路径数据（seo_crumbs），当前页取 canonical 收尾；
    // 只有「首页 + 至少一层目录」才值得输出（单级面包屑与页面本身重复）
    if ($origin !== '' && !empty($ctx['seo_crumbs']) && is_array($ctx['seo_crumbs'])) {
        $items = array();
        $pos = 1;
        foreach ($ctx['seo_crumbs'] as $c) {
            if (!is_array($c) || empty($c['url'])) {
                continue;
            }
            $items[] = array('@type' => 'ListItem', 'position' => $pos++,
                             'name' => (string)$c['name'], 'item' => md_url_abs($origin, (string)$c['url']));
        }
        if (count($items) >= 2) {
            if ($canonAbs !== '') {
                $items[] = array('@type' => 'ListItem', 'position' => $pos,
                                 'name' => $pageTitle, 'item' => $canonAbs);
            }
            $json = json_encode(array('@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
                                      'itemListElement' => $items),
                                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
            if ($json !== false) {
                $meta .= '<script type="application/ld+json">' . $json . "</script>\n";
            }
        }
    }
    $ctx['head_meta'] = $meta;

    if ($view !== false) {
        extract($ctx, EXTR_SKIP);                        // 注入页面上下文（SKIP 防覆盖保护性变量）
        include $view;                                   // 主题即代码：模板输出完整页面
        exit;
    }

    md_page_fallback($theme, $cfg, $ctx);                // default 也缺失时保内容可读
}
