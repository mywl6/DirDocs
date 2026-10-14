<?php
/**
 * DirDocs 多语言（i18n）模块（由 index.php 加载，勿单独访问）
 *
 * v1.10.0 起界面文案支持中文（zh-CN）与英文（en）。设计取舍：
 *   - 零依赖：语言包是纯 PHP 数组文件（_dir/lib/lang/<语言>.php，return array 形态，
 *     与 config.php / theme.php 同模式），不用 gettext/.po；
 *   - 只翻界面 chrome，不翻译文档内容，不改 URL 结构（无 /en/ 前缀）；
 *   - 语言由 config.language 决定：auto = 按请求 Accept-Language 自动（与 cdn=auto 同一先例），
 *     站长可强制指定；同一请求内语言恒定，因此任何缓存都无需区分语言；
 *   - JS 文案经 md_i18n_js_attr() 注入 <html data-md-i18n='JSON'> 属性（无内联脚本，
 *     CSP 不变），builtin.js / admin.js 侧读取并对缺失键回落自身中文兜底。
 *
 * 语言包键名约定（平铺 snake_case）：
 *   前台通用键无前缀；后台键 admin_ 前缀；供 JS 使用的键一律 js_ 前缀
 *   （js_ 键会被 md_i18n_js_attr() 自动整体导出，新增 JS 文案按此前缀命名即可）。
 *   英文包中需要区分单复数的条目用 array('one'=>.., 'other'=>..) 形态（按 vars.n 取舍）。
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}

/** 支持的语言白名单（键 = 语言包文件名 = <html lang> 值）。 */
function md_lang_supported()
{
    return array('zh-CN', 'en');
}

/**
 * 当前请求的界面语言：config.language 为 auto 时按 Accept-Language 判定
 * （含 zh 即中文，否则英文，q 值细节不细分——与 md_cdn_active 同一取舍）。
 * 请求内 static 缓存：一次请求语言恒定。
 */
function md_lang_current()
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $cfg = md_config_load();
    $lang = isset($cfg['language']) && is_string($cfg['language']) ? $cfg['language'] : 'auto';
    if (!in_array($lang, md_lang_supported(), true)) {
        $accept = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string)$_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        $lang = stripos($accept, 'zh') !== false ? 'zh-CN' : 'en';
    }
    return $lang;
}

/**
 * 读语言包：_dir/lib/lang/<语言>.php（return array 形态的数据文件）。
 * 语言名先过白名单正则再拼路径（杜绝配置被写坏后的路径穿越）；包缺失/损坏回落中文源语言。
 */
function md_lang_pack($lang)
{
    static $cache = array();
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }
    $pack = null;
    if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', (string)$lang) === 1) {
        $file = MD_LIB . '/lang/' . $lang . '.php';
        if (is_file($file)) {
            $loaded = @include $file;                       // 语言包数据文件（return array）
            if (is_array($loaded)) {
                $pack = $loaded;
            }
        }
    }
    if (!is_array($pack)) {
        if ($lang !== 'zh-CN') {
            return md_lang_pack('zh-CN');                   // 源语言包是最后兜底
        }
        $pack = array();
    }
    $cache[$lang] = $pack;
    return $pack;
}

/**
 * 取词条：当前语言包 → 中文源语言包 → 键名本身。$vars 做 {name} 占位替换；
 * 词条为数组（英文复数）时按 $vars['n'] 是否为 1 取 one/other 分支。
 * 返回未转义原文，调用方按输出上下文自行 md_e（与现有文案用法一致）。
 */
function md_t($key, $vars = array())
{
    static $zh = null;
    if ($zh === null) {
        $zh = md_lang_pack('zh-CN');
    }
    $lang = md_lang_current();
    $pack = $lang === 'zh-CN' ? $zh : md_lang_pack($lang);
    $v = isset($pack[$key]) ? $pack[$key] : (isset($zh[$key]) ? $zh[$key] : $key);
    if (is_array($v)) {
        $one = isset($v['one']) ? (string)$v['one'] : '';
        $other = isset($v['other']) ? (string)$v['other'] : $one;
        $v = (isset($vars['n']) && (int)$vars['n'] === 1 && $one !== '') ? $one : $other;
        if ($v === '') {
            return (string)$key;
        }
    }
    $v = (string)$v;
    if ($vars) {
        $map = array();
        foreach ($vars as $k => $val) {
            $map['{' . $k . '}'] = (string)$val;
        }
        $v = strtr($v, $map);
    }
    return $v;
}

/**
 * JS 文案词典属性：把语言包里全部 js_ 前缀词条打成 JSON，转义后输出为
 * <html> 上的 data-md-i18n="..." 属性（script type=json 之外的 CSP 安全通道）。
 * 前台主题模板与后台页面的 <html> 各自输出一次。
 * 复数条目（one/other 数组）展平成 <key>_one / <key>_other 两个字符串键，
 * 配合 admin.js 的 mdTN(key, n, ...) 按 n 取分支；zh 包建议同样用数组形态
 * （两分支同文），保证 JS 侧 _one/_other 键恒存在。
 */
function md_i18n_js_attr()
{
    $pack = md_lang_pack(md_lang_current());
    $dict = array();
    foreach ($pack as $k => $v) {
        if (strncmp((string)$k, 'js_', 3) !== 0) {
            continue;
        }
        if (is_string($v)) {
            $dict[(string)$k] = $v;
        } elseif (is_array($v)) {
            if (isset($v['one']) && is_string($v['one'])) {
                $dict[(string)$k . '_one'] = $v['one'];
            }
            if (isset($v['other']) && is_string($v['other'])) {
                $dict[(string)$k . '_other'] = $v['other'];
            }
        }
    }
    if (!$dict) {
        return '';
    }
    $json = json_encode($dict, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    if ($json === false) {
        return '';
    }
    return ' data-md-i18n="' . md_e($json) . '"';
}
