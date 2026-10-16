<?php
/**
 * DirDocs 主题（静态资源/CSS 变量/页面模板/用户主题） 模块（由 index.php 加载，勿单独访问）
 *
 * v2「高级技术文档主题」：前台基于 Bootstrap 5.3 + Bootstrap Icons（v1.12.0 起全资源
 * 自托管于 _dir/assets/vendor/，零外部请求），builtin.css 是 Bootstrap 之上的 DirDocs 覆盖层。
 * v1.19.0 起主题可声明「主题选项」（theme.php 的 options）：站长在后台「主题」页填写，
 * 值可映射到 CSS 变量（浅/深两套）或由 view.php 经 md_theme_option() 读取。
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}

/** 单个主题可声明的选项条数上限（后台表单与配置体积都要收口）。 */
if (!defined('MD_THEME_OPTIONS_MAX')) {
    define('MD_THEME_OPTIONS_MAX', 40);
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
 * 主题模型（2026-10-14 定版；2026-10-16 内置包改程序侧权威副本）：
 *   主题 = _dir/themes/<名称>/theme.php 完整风格包（name + 浅色 vars + 深色 vars-dark，
 *          可选 view.php 整页模板覆盖、assets/ 主题资产），用户可自建风格完全不同的主题；
 *   显示模式 = config.mode（auto/light/dark），与主题解耦——任何主题都自带深浅两套色板。
 * 内置 default / wiki 两个包放在 _dir/lib/builtin-themes/（随 lib 升级覆盖）：升级只覆盖
 * _dir/lib/ 就会带来新版版式与配色，_dir/themes/ 下同名的残留副本一律不再读取。
 * 本函数里的同份色板是「lib 负载缺失/不可读」时的兜底（零配置保证不变），
 * 与程序侧包文件的一致性由测试断言守住（tests/group_theme.php）。
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
    // 内置包不走 manifest_check（它只作用于 themes/ 下的用户包），选项声明在这里就归一化，
    // 保证「内置兜底」与「播种出来的包文件」形态一致
    $default['options'] = md_theme_options_check(md_theme_builtin_options_default());
    $wiki['options'] = $default['options'];
    return array('default' => $default, 'wiki' => $wiki);
}

/**
 * 内置主题（default / wiki）声明的选项：既是开箱可用的设置项，也是主题作者的活样例——
 * 六种类型各一条（选择/颜色/开关/数值/文本/多行文本），演示 css / css_rgb / css_tpl 三种映射。
 * 全部留空即「跟随主题色板」，站长不填任何一项时行为与旧版完全一致。
 */
function md_theme_builtin_options_default()
{
    return array(
        // 选择：影响整站圆角（含卡片与按钮），映射一组变量
        array(
            'key' => 'radius', 'type' => 'select', 'label' => '圆角风格', 'default' => '',
            'hint' => '留空跟随主题默认（12px）',
            'choices' => array(
                '' => array('label' => '跟随主题'),
                'none' => array('label' => '直角（无圆角）', 'css' => array(
                    '--radius' => '0px', '--radius-sm' => '0px',
                    '--bs-border-radius' => '0px', '--bs-border-radius-sm' => '0px')),
                'small' => array('label' => '小圆角', 'css' => array(
                    '--radius' => '8px', '--radius-sm' => '6px',
                    '--bs-border-radius' => '8px', '--bs-border-radius-sm' => '6px')),
                'large' => array('label' => '大圆角', 'css' => array(
                    '--radius' => '18px', '--radius-sm' => '12px',
                    '--bs-border-radius' => '18px', '--bs-border-radius-sm' => '12px')),
            ),
        ),
        // 颜色：主色，同时写色值、RGB 三元组与弱化底色（演示三种变量形态）
        array(
            'key' => 'accent', 'type' => 'color', 'label' => '主题主色', 'default' => '',
            'hint' => '链接、按钮与强调色；留空用主题自带主色',
            'css' => array('--accent', '--bs-primary', '--bs-link-color'),
            'css_rgb' => array('--bs-primary-rgb', '--bs-link-color-rgb'),
            'css_tpl' => array('--accent-weak' => 'rgba({rgb},.12)', '--mark-bg' => 'rgba({rgb},.22)'),
        ),
        // 开关：打开即写入 on 的值，关闭时不写（跟随主题色板）
        array(
            'key' => 'flat', 'type' => 'toggle', 'label' => '扁平化（去掉卡片阴影）', 'default' => false,
            'hint' => '关闭时跟随主题自带的阴影效果',
            'css' => array('--shadow', '--paper-shadow'), 'on' => 'none',
        ),
        // 数值：正文栏宽，带单位（px）与范围
        array(
            'key' => 'content_w', 'type' => 'number', 'label' => '正文栏宽', 'default' => '',
            'hint' => '留空用主题默认（860）；680~1440，宽屏可调大',
            'min' => '680', 'max' => '1440', 'step' => '20',
            'css' => array('--content-w'), 'unit' => 'px',
        ),
        // 文本：页脚备案/版权信息（view.php 经 md_theme_option 读取）
        array(
            'key' => 'footer_note', 'type' => 'text', 'label' => '页脚补充信息', 'default' => '',
            'hint' => '显示在页脚版权行旁，如备案号；留空不显示',
        ),
        // 多行文本：站点公告（同上，模板读取）
        array(
            'key' => 'notice', 'type' => 'textarea', 'label' => '站点公告', 'default' => '',
            'hint' => '显示在首页/文档页顶部；留空不显示',
        ),
    );
}

/** 色板值白名单：颜色/数值/字体栈够用；禁分号花括号冒号（CSS 注入与 url() 一律进不来）。 */
function md_theme_var_ok($v)
{
    return is_string($v) && $v !== '' && strlen($v) <= 300
        && preg_match('/^[#A-Za-z0-9 .,()%\\-\'"]+$/', $v) === 1;
}

// ---------------------------------------------------------------- 主题选项（v1.19.0）

/**
 * 主题选项模型：主题包在 theme.php 里声明 options（数组，每条一个选项），
 * 站长在后台「主题」页填写，取值按主题分别存进 config.theme_options[主题名][键]。
 *
 * 声明字段：
 *   key     选项键（小写字母/数字/下划线/连字符，1~32 位；存储与模板读取都用它）
 *   type    text | textarea | select | toggle | color | number
 *   label   显示名（≤60 字节，缺省用键名）
 *   hint    说明文字（≤200 字节，可省）
 *   default 默认值（按类型校验，非法回落到该类型的中性默认）
 *   css     该选项值写入的 CSS 变量名（字符串或数组）：浅色块生效，深色块同样写入
 *   css_dark 深色块单独写入的变量名（给了就以它为准，浅色块仍用 css）
 *   css_rgb 仅 color：把该色的 RGB 三元组（如 37,99,235）写入这些变量
 *           （Bootstrap 的 --bs-primary-rgb / --bs-link-color-rgb 需要这个形态）
 *   css_tpl 仅 color：变量名 => 值模板（{value} 原值 / {hex} 色值 / {rgb} 三元组），
 *           用于 rgba({rgb},.12) 这类派生值
 *   select 专属：choices（值 => 显示名，或 值 => array('label'=>..,'css'=>..)）；
 *                css 可写「变量名列表 + 单值」或直接写「变量名 => 值」映射
 *   number 专属：min / max / step / unit（单位仅用于显示与 CSS 输出）
 *   toggle 专属：on / off（开关为真/假时写入 css 变量的值；未给 off 则关掉时不写、沿用主题色板）
 *
 * 取值语义：空串 = 未设置 = 不覆盖主题色板（模板读取时回落声明默认）；toggle 恒存布尔。
 * 安全：所有值在读取时按声明重新校验；写进 CSS 前再过一遍 md_theme_var_ok（禁分号/花括号/冒号）。
 */
function md_theme_option_types()
{
    return array('text', 'textarea', 'select', 'toggle', 'color', 'number');
}

/** 变量名列表归一化（css / css_dark / css_rgb 共用）：非法项剔除。 */
function md_theme_opt_var_list($v)
{
    $out = array();
    foreach ((array)$v as $name) {
        if (is_string($name) && preg_match('/^--[a-z0-9-]{1,40}$/i', $name) === 1) {
            $out[] = $name;
        }
    }
    return $out;
}

/**
 * 单值校验与归一化：返回归一化值，非法返回 false。
 * 空串一律合法（= 未设置 = 跟随主题默认），toggle 归一为布尔。
 */
function md_theme_option_value($decl, $raw)
{
    $type = isset($decl['type']) ? (string)$decl['type'] : '';
    if ($type === 'toggle') {
        return is_bool($raw) ? $raw : !empty($raw);
    }
    if (!is_scalar($raw)) {
        return false;
    }
    $v = (string)$raw;
    if ($type === 'textarea') {
        $v = trim(str_replace(array("\r\n", "\r"), "\n", $v));
        if (strlen($v) > 2000) {
            return false;
        }
        // 控制字符剔除例外：换行与制表符保留（多行说明文字要用）
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1 ? false : $v;
    }
    if ($type === 'text') {
        $v = trim(str_replace(array("\r", "\n"), ' ', $v));
        if (strlen($v) > 200) {
            return false;
        }
        return preg_match('/[\x00-\x1F\x7F]/', $v) === 1 ? false : $v;
    }
    if ($type === 'color') {
        $v = strtolower(trim($v));
        if ($v === '') {
            return '';
        }
        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v) === 1 ? $v : false;
    }
    if ($type === 'number') {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $v) !== 1) {
            return false;
        }
        $n = (float)$v;
        if (isset($decl['min']) && $decl['min'] !== '' && $n < (float)$decl['min']) {
            return false;
        }
        if (isset($decl['max']) && $decl['max'] !== '' && $n > (float)$decl['max']) {
            return false;
        }
        return ($n == (int)$n) ? (string)(int)$n : rtrim(rtrim(sprintf('%.4F', $n), '0'), '.');
    }
    if ($type === 'select') {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        return isset($decl['choices'][$v]) ? $v : false;
    }
    return false;
}

/** #abc / #aabbcc → '170,187,204'（RGB 伴生变量用）；形态不对返回空串。 */
function md_theme_hex_rgb($hex)
{
    $h = ltrim(strtolower((string)$hex), '#');
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    if (strlen($h) !== 6 || preg_match('/^[0-9a-f]{6}$/', $h) !== 1) {
        return '';
    }
    return hexdec(substr($h, 0, 2)) . ',' . hexdec(substr($h, 2, 2)) . ',' . hexdec(substr($h, 4, 2));
}

/** 单条选项声明的校验与归一化；非法返回 false（整条丢弃，不影响主题包其他部分）。 */
function md_theme_option_check($o)
{
    if (!is_array($o)) {
        return false;
    }
    $key = isset($o['key']) && is_string($o['key']) ? (string)$o['key'] : '';
    if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $key) !== 1) {
        return false;
    }
    $type = isset($o['type']) && is_string($o['type']) ? strtolower((string)$o['type']) : '';
    if (!in_array($type, md_theme_option_types(), true)) {
        return false;
    }
    $label = isset($o['label']) && is_string($o['label']) ? trim((string)$o['label']) : '';
    if ($label === '') {
        $label = $key;                                  // 缺显示名用键名兜底，不整条丢弃
    } elseif (strlen($label) > 60) {
        $label = $key;
    }
    $hint = isset($o['hint']) && is_string($o['hint']) ? trim((string)$o['hint']) : '';
    if (strlen($hint) > 200) {
        $hint = '';
    }
    $c = array(
        'key' => $key, 'type' => $type, 'label' => $label, 'hint' => $hint, 'default' => '',
        'css' => md_theme_opt_var_list(isset($o['css']) ? $o['css'] : array()),
        // css_dark 显式声明过（哪怕是空数组）= 深色块单独处理；没声明 = 深色块沿用 css。
        // 「声明但是空数组」是主题作者的出口：某项只该影响浅色，深色仍用主题色板。
        'css_dark' => md_theme_opt_var_list(isset($o['css_dark']) ? $o['css_dark'] : array()),
        'css_dark_set' => array_key_exists('css_dark', $o),
        'css_rgb' => md_theme_opt_var_list(isset($o['css_rgb']) ? $o['css_rgb'] : array()),
        'css_tpl' => array(),
    );
    // css_tpl：变量名 => 值模板（{value} 原值 / {hex} 色值补全 / {rgb} 三元组）。
    // 用于颜色选项的「弱化底色」形态（rgba(r,g,b,.12)）——单靠明文映射表达不了。
    if (isset($o['css_tpl']) && is_array($o['css_tpl'])) {
        foreach ($o['css_tpl'] as $tplName => $tpl) {
            if (!is_string($tplName) || preg_match('/^--[a-z0-9-]{1,40}$/i', $tplName) !== 1
                || !is_string($tpl) || strlen($tpl) > 120) {
                continue;
            }
            $c['css_tpl'][$tplName] = $tpl;
            if (count($c['css_tpl']) >= 8) {
                break;
            }
        }
    }
    if ($type === 'toggle') {
        $c['default'] = !empty($o['default']);
        foreach (array('on', 'off') as $k) {
            $c[$k] = (isset($o[$k]) && is_string($o[$k]) && md_theme_var_ok($o[$k])) ? (string)$o[$k] : '';
        }
        return $c;
    }
    if ($type === 'select') {
        $choices = array();
        $cssMap = array();
        if (!isset($o['choices']) || !is_array($o['choices']) || !$o['choices']) {
            return false;                               // 下拉没有可选项 → 整条丢弃
        }
        foreach ($o['choices'] as $ck => $cl) {
            // 允许空值键：'' = 「跟随主题」这一分支（下拉留空即不覆盖色板）
            if (!is_string($ck) || ($ck !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $ck) !== 1)) {
                continue;
            }
            $lab = '';
            $css = '';
            if (is_string($cl)) {
                $lab = trim($cl);
            } elseif (is_array($cl)) {
                $lab = isset($cl['label']) && is_string($cl['label']) ? trim($cl['label']) : '';
                if (isset($cl['css'])) {
                    if (is_string($cl['css']) && md_theme_var_ok($cl['css'])) {
                        $css = (string)$cl['css'];          // 形态一：一个值，写进声明的 css 变量
                    } elseif (is_array($cl['css'])) {
                        $map = array();                     // 形态二：变量 => 值（各分支写各自的变量）
                        foreach ($cl['css'] as $mvName => $mv) {
                            if (is_string($mvName) && preg_match('/^--[a-z0-9-]{1,40}$/i', $mvName) === 1
                                && is_string($mv) && md_theme_var_ok($mv)) {
                                $map[$mvName] = $mv;
                            }
                        }
                        if ($map) {
                            $css = $map;
                        }
                    }
                }
            }
            if ($lab === '' || strlen($lab) > 40) {
                $lab = ($ck === '') ? '—' : $ck;
            }
            $choices[$ck] = $lab;
            if ($css !== '' && $css !== array()) {
                $cssMap[$ck] = $css;
            }
            if (count($choices) >= 12) {
                break;                                  // 单条选项最多 12 个分支
            }
        }
        if (!$choices) {
            return false;
        }
        $keys = array_keys($choices);
        $c['choices'] = $choices;
        $c['css_map'] = $cssMap;
        $c['default'] = (isset($o['default']) && is_string($o['default']) && isset($choices[$o['default']]))
            ? (string)$o['default'] : (string)$keys[0];
        return $c;
    }
    if ($type === 'number') {
        foreach (array('min', 'max') as $k) {
            $c[$k] = (isset($o[$k]) && is_numeric($o[$k])) ? (string)(0 + $o[$k]) : '';
        }
        $c['step'] = (isset($o['step']) && is_numeric($o['step']) && $o['step'] > 0)
            ? (string)(0 + $o['step']) : '1';
        $c['unit'] = (isset($o['unit']) && is_string($o['unit'])
            && preg_match('/^[a-z%]{1,6}$/i', $o['unit']) === 1) ? (string)$o['unit'] : '';
        // 最小值大于最大值时交换，避免声明笔误把选项卡死
        if ($c['min'] !== '' && $c['max'] !== '' && (float)$c['min'] > (float)$c['max']) {
            $swap = $c['min'];
            $c['min'] = $c['max'];
            $c['max'] = $swap;
        }
        $c['default'] = md_theme_option_value($c, isset($o['default']) ? $o['default'] : '');
        if ($c['default'] === false) {
            $c['default'] = '';
        }
        return $c;
    }
    // text / textarea / color：默认值同样过校验，非法回落空串（= 未设置）
    $dv = md_theme_option_value($c, isset($o['default']) ? $o['default'] : '');
    $c['default'] = ($dv === false) ? '' : $dv;
    return $c;
}

/** 配置里的 theme_options 归一化：只保形态合法的层（主题名/键名/标量值，条数封顶）。 */
function md_theme_options_normalize($raw)
{
    if (!is_array($raw)) {
        return array();
    }
    $out = array();
    foreach ($raw as $theme => $vals) {
        if (!is_string($theme) || preg_match('/^[a-z0-9_-]{1,32}$/', $theme) !== 1 || !is_array($vals)) {
            continue;
        }
        $clean = array();
        foreach ($vals as $k => $v) {
            if (!is_string($k) || preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $k) !== 1) {
                continue;
            }
            if (is_bool($v) || is_int($v) || is_float($v)) {
                $clean[$k] = $v;
            } elseif (is_string($v) && strlen($v) <= 2000) {
                $clean[$k] = $v;
            } else {
                continue;                               // 数组/对象一律剔除（配置里不该有嵌套值）
            }
            if (count($clean) >= MD_THEME_OPTIONS_MAX) {
                break;
            }
        }
        if ($clean) {
            $out[$theme] = $clean;
        }
    }
    return $out;
}

/**
 * 某主题的全部选项取值：声明默认 + 配置存储（存储值过声明校验，非法回落默认）。
 * 键集合恒等于主题声明的键集合——模板可以放心按声明键取值。
 * 请求内缓存放在 $GLOBALS（而非 static）：配置写入后由 md_theme_options_clear_cache 清掉。
 */
function md_theme_options($theme)
{
    $theme = preg_match('/^[a-z0-9_-]{1,32}$/', (string)$theme) === 1 ? (string)$theme : 'default';
    if (!isset($GLOBALS['md_theme_opt_cache'])) {
        $GLOBALS['md_theme_opt_cache'] = array();
    }
    if (isset($GLOBALS['md_theme_opt_cache'][$theme])) {
        return $GLOBALS['md_theme_opt_cache'][$theme];
    }
    $m = md_theme_manifest($theme);
    $cfg = md_config_load();
    $stored = (isset($cfg['theme_options'][$theme]) && is_array($cfg['theme_options'][$theme]))
        ? $cfg['theme_options'][$theme] : array();
    $out = array();
    foreach ($m['options'] as $key => $decl) {
        $v = null;
        if (array_key_exists($key, $stored)) {
            $chk = md_theme_option_value($decl, $stored[$key]);
            // 校验失败用 false 当哨兵，但 toggle 的合法值本身就是布尔——它的 false
            // 表示「站长关掉了」，不是「值非法」（不区分的话，声明 default=true 的开关
            // 一旦被关就会静默回弹成开）。布尔类型不走哨兵判断。
            if ($decl['type'] === 'toggle' || $chk !== false) {
                $v = $chk;
            }
        }
        $out[$key] = ($v === null) ? $decl['default'] : $v;
    }
    $GLOBALS['md_theme_opt_cache'][$theme] = $out;
    return $out;
}

/** 清掉本次请求内选项取值的缓存（配置写入后调用，保证同请求内立即看到新值）。 */
function md_theme_options_clear_cache()
{
    $GLOBALS['md_theme_opt_cache'] = array();
}

/** 单个选项取值（主题模板用）：$key 未声明时返回 $fallback。 */
function md_theme_option($theme, $key, $fallback = '')
{
    $all = md_theme_options($theme);
    return array_key_exists($key, $all) ? $all[$key] : $fallback;
}

/**
 * 主题选项产生的 CSS 变量（$mode = light|dark）：变量名 => 值，已过 var 白名单。
 * 未设置（空串）的选项不产生变量；toggle 关且未声明 off 时同样不产生。
 * 变量集：深色块默认沿用 css（色变了深浅两边都该变），声明过 css_dark 时只写它。
 * select 分支的 css 支持两种形态：单个值（写进声明的 css 变量）或 变量=>值 映射。
 */
function md_theme_option_vars($theme, $mode = 'light')
{
    $m = md_theme_manifest($theme);
    if (empty($m['options'])) {
        return array();
    }
    $vals = md_theme_options($theme);
    $dark = ($mode === 'dark');
    $out = array();
    foreach ($m['options'] as $key => $decl) {
        $v = isset($vals[$key]) ? $vals[$key] : null;
        if ($v === null || $v === '') {
            continue;
        }
        $list = $decl['css'];
        if ($dark && !empty($decl['css_dark_set'])) {
            $list = $decl['css_dark'];                  // 显式声明过：深色块只写它（空数组 = 深色不变）
        }
        $raw = '';
        $map = array();                                 // select 分支的变量=>值映射（优先于 css 列表）
        if ($decl['type'] === 'toggle') {
            $raw = $v ? (isset($decl['on']) ? $decl['on'] : '') : (isset($decl['off']) ? $decl['off'] : '');
        } elseif ($decl['type'] === 'number') {
            $raw = $v . (isset($decl['unit']) ? $decl['unit'] : '');
        } elseif ($decl['type'] === 'select') {
            $branch = isset($decl['css_map'][$v]) ? $decl['css_map'][$v] : '';
            if (is_array($branch)) {
                $map = $branch;
            } else {
                $raw = (string)$branch;
            }
        } else {
            $raw = (string)$v;
        }
        foreach ($map as $name => $mv) {
            $out[$name] = $mv;
        }
        if ($raw === '' || !md_theme_var_ok($raw)) {
            if (!$map) {
                continue;
            }
        } elseif ($list) {
            foreach ($list as $name) {
                $out[$name] = $raw;
            }
        }
        // 颜色的派生变量：css_rgb 写三元组，css_tpl 比值模板（{value}/{hex}/{rgb} 三占位）
        if ($decl['type'] === 'color') {
            $rgb = md_theme_hex_rgb($raw);
            if ($rgb !== '') {
                foreach ($decl['css_rgb'] as $name) {
                    $out[$name] = $rgb;
                }
                foreach ($decl['css_tpl'] as $name => $tpl) {
                    $rule = strtr($tpl, array('{value}' => $raw, '{hex}' => $raw, '{rgb}' => $rgb));
                    if (md_theme_var_ok($rule)) {
                        $out[$name] = $rule;
                    }
                }
            }
        }
    }
    return $out;
}

/** 选项声明整表校验：键 => 归一化声明；非法条目丢弃，重复键取先声明的一条。 */
function md_theme_options_check($raw)
{
    $out = array();
    if (!is_array($raw)) {
        return $out;
    }
    foreach ($raw as $o) {
        $c = md_theme_option_check($o);
        if ($c !== false && !isset($out[$c['key']])) {
            $out[$c['key']] = $c;
            if (count($out) >= MD_THEME_OPTIONS_MAX) {
                break;                                  // 声明条数上限：后台表单与配置体积都要收口
            }
        }
    }
    return $out;
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
    $clean = array('name' => $name, 'dark' => !empty($m['dark']), 'vars' => array(), 'vars-dark' => array(),
                   'options' => array());
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
    // 选项声明（v1.19.0）：非法条目单独丢弃，不影响整包（与变量同取舍）
    $clean['options'] = md_theme_options_check(isset($m['options']) ? $m['options'] : array());
    return $clean;
}

/**
 * 内置包的程序侧目录（权威副本）：_dir/lib/builtin-themes/<名称>/theme.php(+view.php)。
 * 随 _dir/lib/ 一起升级，因此升级只覆盖 lib 即可拿到新版内置主题——
 * _dir/themes/ 只用来放用户自建主题（同名目录会被忽略，避免读到旧副本）。
 */
function md_theme_builtin_dir($theme)
{
    $theme = (string)$theme;
    if (preg_match('/^[a-z0-9_-]{1,32}$/', $theme) !== 1) {
        return false;
    }
    return MD_LIB . '/builtin-themes/' . $theme;
}

/** 内置包源文件路径（不存在返回 false）：$file = 'theme.php' | 'view.php'。 */
function md_theme_builtin_file($theme, $file)
{
    $dir = md_theme_builtin_dir($theme);
    if ($dir === false || !in_array($file, array('theme.php', 'view.php'), true)) {
        return false;
    }
    $path = $dir . '/' . $file;
    return is_file($path) && !is_link($path) ? $path : false;
}

/** 读主题包：内置包（程序侧权威副本）优先，用户自建包取 _dir/themes/，都不可用时用硬编码色板。 */
function md_theme_manifest($theme)
{
    static $cache = array();
    $theme = preg_match('/^[a-z0-9_-]{1,32}$/', (string)$theme) === 1 ? (string)$theme : 'default';
    if (isset($cache[$theme])) {
        return $cache[$theme];
    }
    $builtins = md_theme_builtin_manifests();
    if (isset($builtins[$theme])) {
        // 内置包：从 _dir/lib/builtin-themes/ 读（权威副本，随 lib 升级覆盖）。
        // _dir/themes/<内置名>/ 是给站长看的可读镜像，读包一律以程序侧为准——
        // 镜像滞后（只读安装写不进）或残留旧版时，色板与选项声明不会跟着变旧。
        $src = md_theme_builtin_file($theme, 'theme.php');
        $m = $src !== false ? md_theme_manifest_check(@include $src) : false;
        if ($m === false) {
            $m = $builtins[$theme];                      // lib 负载缺失/被改坏时用硬编码色板兜底
        }
    } else {
        $m = false;
        $file = MD_DIR . '/themes/' . $theme . '/theme.php';
        if (is_file($file)) {
            $m = md_theme_manifest_check(@include $file);   // 用户自建包数据文件（return array）
        }
        if ($m === false) {
            $m = $builtins['default'];                   // 自建包读坏/被删：回落默认色板（前台仍可读）
        }
    }
    $cache[$theme] = $m;                                 // 只缓存成功加载：包文件修好当请求即可重读
    return $m;
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

/**
 * 内置主题同步：把程序侧权威副本（_dir/lib/builtin-themes/）镜像到 _dir/themes/。
 *
 * 语义（2026-10-16 用户拍板「改成覆盖」）：
 *   - **镜像跟着 lib 走**：逐文件比对内容，只要负载与镜像不同就写过去——
 *     升级只覆盖 `_dir/lib/` 也能拿到新版内置版式与配色，不需要再手工覆盖 themes/；
 *   - **镜像不是编辑点**：直接改 `_dir/themes/default/`（或 wiki/）下的文件，下一次
 *     请求就会被负载覆盖回来（提示语同理写在镜像文件头）。要改内置主题请整套复制到
 *     另一个目录名——那是自建主题，永不被动；
 *   - **负载里没有的文件要清掉**：wiki 是纯色板包（没有 view.php），镜像里若残留旧副本
 *     会被当成「自带模板」用，必须删除；
 *   - **只动 default / wiki 两个目录名**：其余目录是用户自建主题，一律不碰。
 *
 * 实现上刻意不记版本号：比对内容比记版本更准（同版本内改了 lib 也能生效，不会因为
 * 版本号没变而漏掉），也少了一个运行期文件。内容一致时一次写盘都不发生，所以
 * 每次请求调用它只是几次文件读取。
 */
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
        if (!is_dir($dir . '/' . $k)) {
            @mkdir($dir . '/' . $k, 0755, true);
        }
        foreach (array('theme.php', 'view.php') as $f) {
            $srcPath = md_theme_builtin_file($k, $f);
            $dstPath = $dir . '/' . $k . '/' . $f;
            if ($srcPath === false) {
                @unlink($dstPath);                       // 负载里没有：镜像旧副本必须清掉
                continue;
            }
            $src = md_read_text($srcPath);
            if ($src === false) {
                continue;                                // 源文件读不出来：不动镜像（宁可用旧的）
            }
            if (is_file($dstPath) && md_read_text($dstPath) === $src) {
                continue;                                // 内容一致：一次写盘都不发生
            }
            md_write_file($dstPath, $src);
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

/**
 * 主题 CSS 变量块：布局常量 + 主题包色板 + 站长填的主题选项（后写覆盖前写）。
 * 深浅两块各写一次——选项声明给出了 css_dark 的，深色块用它，否则沿用同一变量名。
 * $theme 为 '' 时不读主题选项（后台管理页画板等「只看色板」的场景用）。
 * 注意：这里不做镜像同步（那是后台动作的事，见 md_admin_dispatch）——前台渲染只读不写。
 */
function md_theme_css($theme, $withOptions = true)
{
    $m = md_theme_manifest($theme);
    $layout = '--nav-h:56px;--sidebar-width:275px;--toc-width:220px;--toc-max:320px;--content-w:860px;';
    $emit = function ($vars) { $s = ''; foreach ($vars as $k => $v) { $s .= $k . ':' . $v . ';'; } return $s; };
    // 设计系统契约 token（与后台共用同一套变量名，builtin.css 内有逐字一致的兜底定义，
    // 本内联块位于 builtin.css 之后：同值覆写保证主题包生效）。
    // 深浅色统一由 <html data-bs-theme="light|dark|auto"> 驱动（Bootstrap 原生属性 + theme-init.js）。
    $light = $m['vars'];
    $dark = $m['vars-dark'];
    if ($withOptions && $theme !== '' && !empty($m['options'])) {
        // 选项值只覆盖自己声明的变量：值为空串的选项不产生变量，主题色板原样生效
        $light = array_merge($light, md_theme_option_vars($theme, 'light'));
        $dark = array_merge($dark, md_theme_option_vars($theme, 'dark'));
    }
    $css = ':root{' . $layout . $emit($light) . 'color-scheme:light}';
    $css .= '[data-bs-theme="dark"]{' . $layout . $emit($dark) . 'color-scheme:dark}';
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
 * 主题包规范（WordPress/Typecho 模式，2026-10-14 定版；v1.19.0 追加 options）：
 *   _dir/themes/<名称>/
 *     theme.php   主题清单（return array）：name 显示名 / dark 深浅倾向 /
 *                 vars + vars-dark 两套完整变量（数据文件，经 manifest 校验）/
 *                 options 主题选项声明（可选，站长在后台「主题」页填写，取值存进
 *                 config.theme_options[主题名]；见上方「主题选项模型」）。
 *                 选项值写 CSS 变量时由 md_theme_css 合并进内联变量块，无需模板参与。
 *     view.php    整页模板：主题即代码（安装主题 = 安装代码，只装可信来源）。
 *                 md_page 注入上下文变量后 include，模板负责输出完整 HTML。
 *                 可用变量：$cfg $ctx $theme $mode $lang $home $searchUrl $recentUrl $q
 *                 $tocHtml $cdn $mermaidUrl $hasAdmin $headAssets $themeUrl $adminUrl
 *                 $themeOptions（v1.19.0：当前主题选项取值，键集合见主题包 options 声明），
 *                 常量 MD_VERSION，助手 md_e / md_t / md_lang_current / md_i18n_js_attr /
 *                 md_url_for / md_admin_url / md_cdn_active / md_project_url（项目主页）/
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
/**
 * 主题的整页模板路径（false = 该主题没有自带模板，由调度器回退 default）。
 * 内置包取程序侧权威副本（_dir/lib/builtin-themes/）：前台渲染不写盘，且升级只覆盖
 * _dir/lib/ 就立刻生效，不受 _dir/themes/ 镜像是否新鲜、是否可写的影响。
 * 用户自建包取 _dir/themes/<名>/view.php。
 */
function md_theme_view_file($theme)
{
    if (!preg_match('/^[a-z0-9_-]{1,32}$/', (string)$theme)) {
        return false;
    }
    if (array_key_exists((string)$theme, md_theme_builtin_manifests())) {
        return md_theme_builtin_file($theme, 'view.php');  // 纯色板内置包（wiki）无 view.php → false
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
    // 主题选项取值（v1.19.0）：键集合 = 当前主题声明的选项，值已过声明校验。
    // 模板可直接读 $themeOptions['键']，或用 md_theme_option($theme, '键')。
    $themeOptions = md_theme_options($theme);
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
    // 生成器声明（v1.18.0）：页面不可见，给源码查看者与自动化工具一条溯源线索；
    // 站外地址只作为标识出现，不是资源引用，CSP / 零外联策略都不受影响
    $meta .= '<meta name="generator" content="DirDocs ' . md_e(MD_VERSION) . ' (' . md_e(md_project_url()) . ')">' . "\n";
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
