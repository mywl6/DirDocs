<?php
/**
 * DirDocs 引擎（文本/路径/URL/渲染/搜索/文件名） 模块（由 index.php 加载，勿单独访问）
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}

function md_e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function md_read_text($path)
{
    $d = @file_get_contents($path);
    if ($d === false) {
        return false;
    }
    // 统一剥 UTF-8 BOM：Windows 记事本默认带 BOM，不剥会导致 front-matter 判定失败
    if (substr($d, 0, 3) === "\xEF\xBB\xBF") {
        $d = substr($d, 3);
    }
    return $d;
}

function md_starts_with($haystack, $needle)
{
    return strpos($haystack, $needle) === 0;
}

function md_ends_with($haystack, $needle)
{
    $n = strlen($needle);
    if ($n === 0) {
        return true;
    }
    return substr($haystack, -$n) === $needle;
}

function md_contains($haystack, $needle)
{
    return strpos($haystack, $needle) !== false;
}

function md_ext($path)
{
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

function md_resolve($rel, $cfg, $kind = 'md')
{
    $rel = str_replace('\\', '/', (string)$rel);
    $rel = rawurldecode($rel);

    $segs = explode('/', $rel);
    foreach ($segs as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') {
            return false;
        }
    }
    if ($rel === MD_DIRNAME || md_starts_with($rel, MD_DIRNAME . '/')) {
        return false;                                   // 系统目录一律拒绝
    }

    if ($kind === 'md') {
        // 文档：缺省补 .md，也允许用户直接写全后缀
        $base = $rel;
        $ext = md_ext($rel);
        $whitelist = $cfg['ext_md'];
        if (!in_array($ext, $whitelist, true)) {
            $cand = array();
            foreach ($whitelist as $e) {
                $cand[] = $base . '.' . $e;
            }
        } else {
            $cand = array($base);
        }
    } else {
        $cand = array($rel);
        $whitelist = $cfg['ext_attach'];
    }

    $root = str_replace('\\', '/', MD_ROOT);
    foreach ($cand as $c) {
        $full = realpath($root . '/' . $c);
        if ($full === false) {
            continue;
        }
        $full = str_replace('\\', '/', $full);
        if (!md_starts_with($full, $root . '/')) {
            continue;                                   // 前缀必须带分隔符
        }
        if (is_link($root . '/' . $c)) {
            continue;                                   // 符号链接不跟随（按文档根绝对路径查，不依赖 CWD）
        }
        if (!in_array(md_ext($full), $whitelist, true)) {
            continue;
        }
        return $full;
    }
    return false;
}

function md_rel($abs)
{
    $abs = str_replace('\\', '/', $abs);
    $root = str_replace('\\', '/', MD_ROOT);
    if (!md_starts_with($abs, $root . '/')) {
        return false;
    }
    return substr($abs, strlen($root) + 1);
}

function md_base_path($cfg)
{
    if (isset($cfg['base_path']) && $cfg['base_path'] !== '') {
        return rtrim($cfg['base_path'], '/');
    }
    // 自动检测：SCRIPT_NAME 是 PHP 入口相对 Web 根的路径。
    // dirname 在 Windows 上可能返回反斜杠（根安装时恰为 '\'），须归一化回 /，
    // 否则 pretty 模式拼出 "\/xxx"，浏览器把 "\/" 当 "//"（协议相对 URL），
    // 后面的路径就成了主机名（样式丢失、请求打到 xn-- punycode 域名）。
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    $script = str_replace('\\', '/', $script);
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '.' || $dir === '/' || $dir === '') {
        return '';
    }
    return $dir;
}

function md_url_encode($rel)
{
    $segs = explode('/', str_replace('\\', '/', $rel));
    $out = array();
    foreach ($segs as $s) {
        $out[] = rawurlencode($s);
    }
    return implode('/', $out);
}

function md_url_for($rel, $type = 'doc', $cfg = null)
{
    static $cfgStatic = null;
    if ($cfg === null) {
        if ($cfgStatic === null) {
            $cfgStatic = md_config_load();
        }
        $cfg = $cfgStatic;
    }
    $base = md_base_path($cfg);
    $enc = md_url_encode($rel);

    if ($cfg['url_mode'] === 'pretty' && $base !== false) {
        switch ($type) {
            case 'file':
                // SPEC 4.4 定案：附件（含 svg/md/txt 这类"可执行内容"）一律走程序附件通道，
                // pretty 模式同样不输出静态直连路径——直出绕开扩展名白名单与 Disposition 策略。
                // ETag/304/Range 在 md_send_file 内实现，性能损耗仅每附件一次 PHP 引导
                return $base . '/?a=file&p=' . $enc;
            case 'doc':
            case 'dir':
                $url = $base . '/' . $enc;
                if ($type === 'dir' && $rel !== '' && !md_ends_with($url, '/')) {
                    $url .= '/';
                }
                return $url;
            case 'asset':
                return $base . '/_theme/' . $enc;
            case 'sys':
                return $base . '/?a=asset&t=_sys&f=' . md_url_encode($rel);
            case 'admin':
                return $base . '/?_a=' . $enc;
            case 'search':
                return $base . '/?q=' . $enc;
            case 'recent':
                return $base . '/?a=recent';
        }
    }
    switch ($type) {
        case 'doc':
        case 'dir':
            return '?p=' . $enc;
        case 'file':
            return '?a=file&p=' . $enc;
        case 'asset':
            return '?a=asset&t=' . $enc;
        case 'sys':
            return '?a=asset&t=_sys&f=' . md_url_encode($rel);
        case 'admin':
            return '?a=' . $enc;
        case 'search':
            return '?q=' . $enc;
        case 'recent':
            return '?a=recent';
    }
    return '?p=' . $enc;
}

function md_strip_doc_ext($rel, $cfg)
{
    $ext = md_ext($rel);
    if ($ext !== '' && in_array($ext, $cfg['ext_md'], true)) {
        return substr($rel, 0, -(strlen($ext) + 1));
    }
    return $rel;
}

/**
 * 站点绝对地址前缀（scheme://host，无尾斜杠）：canonical / OG / sitemap 的绝对 URL 都由它拼。
 * 优先 site_url 配置；留空按当前请求推断。推断不出（CLI、异常 Host 头）返回 ''，
 * 调用方据此降级为不输出绝对地址标签。刻意不信任 X-Forwarded-Proto——
 * 它是可伪造头，进 canonical 会被用于缓存投毒（安全 > 使用方便）。
 */
function md_site_origin($cfg)
{
    $u = isset($cfg['site_url']) ? trim((string)$cfg['site_url']) : '';
    if ($u !== '') {
        return rtrim($u, '/');
    }
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    if (!$https && isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        $https = true;
    }
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
    // Host 头是外部输入：只放行域名字符/IPv4/带端口的 [IPv6]，其余一律不采信
    if ($host === '' || !preg_match('/^[a-zA-Z0-9._-]+(:[0-9]+)?$|^\[[0-9a-fA-F:]+\](:[0-9]+)?$/', $host)) {
        return '';
    }
    return ($https ? 'https://' : 'http://') . $host;
}

/** 绝对 URL 拼接：simple 模式的相对地址以 ? 开头，须补「/」避免 host 后裸跟查询串。 */
function md_url_abs($origin, $rel)
{
    if ($origin === '' || $rel === '') {
        return '';
    }
    return $origin . ($rel[0] === '?' ? '/' . $rel : $rel);
}

function md_is_hidden($name, $cfg)
{
    foreach ($cfg['hidden_prefix'] as $pre) {
        if ($pre !== '' && md_starts_with($name, $pre)) {
            return true;
        }
    }
    return false;
}

/**
 * 扫描期元数据：只读文件头 8KB 提取标题与排序，代价远小于读全文。
 * front-matter title/order 优先，退而取正文第一个 H1；order_prefix 数字前缀作次级排序键。
 */
function md_scan_head_meta($abs, $name, $cfg)
{
    $meta = array('title' => '', 'order' => null, 'sort' => null, 'draft' => false);
    $head = @file_get_contents($abs, false, null, 0, 8192);
    if ($head !== false) {
        if (substr($head, 0, 3) === "\xEF\xBB\xBF") {
            $head = substr($head, 3);
        }
        $fm = md_front_matter($head);
        $meta['title'] = $fm['title'];
        $meta['order'] = $fm['order'];
        $meta['draft'] = !empty($fm['draft']);
        if ($meta['title'] === '') {
            $body = md_strip_front_matter($head);
            if (preg_match('/^\s{0,3}#\s+(.+)$/m', $body, $m) === 1) {
                $meta['title'] = trim($m[1]);
            }
        }
    }
    if ($meta['order'] !== null) {
        $meta['sort'] = $meta['order'];
    } elseif (!empty($cfg['order_prefix'])) {
        $p = md_split_order_prefix($name);
        $meta['sort'] = $p[0];
    }
    return $meta;
}

/** 识别 01- / 02_ / 3. 数字前缀；返回 array(序号或null, 去前缀名)。后跟数字的前缀不算（如 2.0 版本）。 */
function md_split_order_prefix($name)
{
    if (preg_match('/^(\d{1,3})[-_.\s]+(.*)$/u', $name, $m) && $m[2] !== '' && !preg_match('/^\d/', $m[2])) {
        return array((int)$m[1], $m[2]);
    }
    return array(null, $name);
}

/** 导航/列表显示名：order_prefix 开启时剥掉数字前缀。 */
function md_display_name($name, $cfg)
{
    if (empty($cfg['order_prefix'])) {
        return $name;
    }
    $r = md_split_order_prefix($name);
    return $r[0] === null ? $name : $r[1];
}

function md_scan($dir, $cfg, $prefix = '', &$out = array())
{
    $items = @scandir($dir);
    if ($items === false) {
        return $out;
    }
    $limit = 2000;
    $count = 0;
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (md_is_hidden($name, $cfg)) {
            continue;
        }
        $full = $dir . '/' . $name;
        if (is_link($full)) {
            continue;                                   // 不跟随符号链接
        }
        $rel = $prefix === '' ? $name : $prefix . '/' . $name;
        if (is_dir($full)) {
            $p = !empty($cfg['order_prefix']) ? md_split_order_prefix($name) : array(null, $name);
            $out['dir'][$rel] = array('name' => $name, 'rel' => $rel, 'mtime' => @filemtime($full), 'sort' => $p[0]);
            md_scan($full, $cfg, $rel, $out);
        } else {
            $ext = md_ext($name);
            if (in_array($ext, $cfg['ext_md'], true)) {
                $type = 'md';
            } elseif (in_array($ext, $cfg['ext_attach'], true)) {
                $type = 'attach';
            } else {
                continue;                               // 非白名单不进文档树
            }
            $item = array(
                'name'  => $name,
                'rel'   => $rel,
                'mtime' => @filemtime($full),
                'size'  => @filesize($full),
            );
            if ($type === 'md') {
                $meta = md_scan_head_meta($full, $name, $cfg);
                $item['title'] = $meta['title'];
                $item['order'] = $meta['order'];
                $item['sort']   = $meta['sort'];
                if (!empty($meta['draft'])) {
                    $item['draft'] = true;
                }
            }
            $out[$type][$rel] = $item;
        }
        $count++;
        if ($count > $limit) {
            break;                                      // 大目录保护
        }
    }
    return $out;
}

/**
 * 直接子项收集。$includeDrafts=false（前台默认）：draft 文档从导航树/目录页/首页选择
 * 中整体消失；后台文档管理传 true 照常列出并打草稿标记。
 */
function md_children($dirRel, $tree, $includeDrafts = false)
{
    $out = array('dir' => array(), 'md' => array(), 'attach' => array());
    $needle = $dirRel === '' ? '' : $dirRel . '/';
    foreach (array('dir', 'md', 'attach') as $type) {
        if (empty($tree[$type])) {
            continue;
        }
        foreach ($tree[$type] as $rel => $info) {
            if ($type === 'md' && empty($includeDrafts) && !empty($info['draft'])) {
                continue;
            }
            if ($needle !== '') {
                if (!md_starts_with($rel, $needle)) {
                    continue;
                }
                $rest = substr($rel, strlen($needle));
            } else {
                $rest = $rel;
            }
            if ($rest === '' || md_contains($rest, '/')) {
                continue;                               // 只取直接子项
            }
            $out[$type][] = $info;
        }
        md_sort_items($out[$type]);
    }
    return $out;
}

/**
 * 名称比较（目录树/搜索平分/后台列表的统一入口）。intl 扩展可用时走 Collator：
 * 中文按拼音（locale zh）、西文按字母，修复 strcasecmp 按 UTF-8 字节排导致
 * 中文文档树乱序（字节序≈Unicode 码点序≈部首序，不是用户期望的拼音序）。
 * intl 不保证装机（廉价虚拟主机常见缺失），这是可选增强：缺失/构造失败/比较出错
 * 一律回退 strcasecmp 字节序（与旧版一致，不劣于现状）。Collator 实例请求内
 * 静态缓存一次——构造开销大，不能进排序回调。
 */
function md_str_compare($a, $b)
{
    static $col = null;
    static $usable = null;
    if ($usable === null) {
        $usable = false;
        if (class_exists('Collator')) {
            $c = @new Collator('zh');
            if (is_object($c)) {
                $col = $c;
                $usable = true;
            }
        }
    }
    if ($usable) {
        $r = $col->compare((string)$a, (string)$b);
        if ($r !== false) {
            return $r;
        }
    }
    return strcasecmp((string)$a, (string)$b);
}

/** 统一排序：数字排序键（front-matter order 或 01- 前缀）优先，其次名称（md_str_compare）。 */
function md_sort_items(&$items, $cfg = null)
{
    usort($items, function ($a, $b) {
        $sa = isset($a['sort']) && $a['sort'] !== null ? (int)$a['sort'] : null;
        $sb = isset($b['sort']) && $b['sort'] !== null ? (int)$b['sort'] : null;
        if ($sa !== null || $sb !== null) {
            if ($sa === null) {
                return 1;                               // 无排序键的排后面
            }
            if ($sb === null) {
                return -1;
            }
            if ($sa !== $sb) {
                return $sa < $sb ? -1 : 1;
            }
        }
        return md_str_compare($a['name'], $b['name']);
    });
    return $items;
}

function md_front_matter($text)
{
    $meta = array('title' => '', 'order' => null, 'draft' => false);
    if (!md_starts_with($text, "---\n") && !md_starts_with($text, "---\r\n")) {
        return $meta;
    }
    if (preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n/s', $text, $m) !== 1) {
        return $meta;
    }
    $body = $m[1];
    foreach (preg_split('/\r?\n/', $body) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false || $pos === 0) {
            continue;
        }
        $key = strtolower(trim(substr($line, 0, $pos)));
        $val = trim(substr($line, $pos + 1));
        $val = trim($val, "\"'");
        if ($key === 'title') {
            $meta['title'] = $val;
        } elseif ($key === 'order' && is_numeric($val)) {
            $meta['order'] = (int)$val;
        } elseif ($key === 'draft') {
            // 草稿位（v1.15.0）：真值写法白名单，其余一律视为未草稿（防误写 draft: 0 意外隐藏）
            $meta['draft'] = in_array(strtolower($val), array('true', '1', 'yes', 'on'), true);
        }
    }
    return $meta;
}

function md_strip_front_matter($text)
{
    if (preg_match('/\A---\r?\n.*?\r?\n---\r?\n/s', $text, $m) === 1) {
        return substr($text, strlen($m[0]));
    }
    return $text;
}

function md_safe_url($url)
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    // 去掉控制字符与零宽字符后再判断协议（零宽按 UTF-8 字节序列替换，
    // 不用 /u 正则——输入不保证合法 UTF-8，/u 会整体返回 NULL）
    $probe = str_replace(array("\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xEF\xBB\xBF"), '', $url);
    $probe = preg_replace('/[\x00-\x20\x7f]/', '', $probe);
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $probe, $m) === 1) {
        $scheme = strtolower($m[0]);
        $allow = array('http:', 'https:', 'mailto:');
        if (!in_array($scheme, $allow, true)) {
            return '';
        }
    }
    // 其余（相对路径、#锚点）一律放行
    return $url;
}

function md_inline($html)
{
    // 行内代码先占位，避免内部内容被后续规则处理
    $codes = array();
    $html = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . $m[1] . '</code>';
        return "\x00CB" . (count($codes) - 1) . "\x00";
    }, $html);

    // 图片 ![alt](src "title")；src 支持 <...> 角括号包裹（文件名可含空格）。
    // 注意：进入本函数前文本已经 htmlspecialchars 转义，尖括号是 &lt; &gt; 形态。
    $html = preg_replace_callback(
        '/!\[([^\]]*)\]\(\s*(?:&lt;((?:(?!&gt;).)*)&gt;|([^)\s]+))(?:\s+&quot;([^)]*)&quot;)?\s*\)/',
        function ($m) {
            $alt = $m[1];
            $title = isset($m[4]) ? $m[4] : '';
            // 未参与匹配的捕获组 PHP 会给空串而非未设置，故用非空判断选分支
            $src = htmlspecialchars_decode(($m[2] !== '' ? $m[2] : $m[3]), ENT_QUOTES);
            $safe = md_safe_url($src);
            if ($safe === '') {
                return $alt;
            }
            $t = $title !== '' ? ' title="' . $title . '"' : '';
            return '<img src="' . md_e($safe) . '" alt="' . $alt . '"' . $t . ' loading="lazy">';
        },
        $html
    );

    // 链接 [text](href)；href 同样支持 &lt;...&gt; 角括号形式（见上图注：文本已转义）
    $html = preg_replace_callback(
        '/\[([^\]]+)\]\(\s*(?:&lt;((?:(?!&gt;).)*)&gt;|([^)\s]*))(?:\s+&quot;([^)]*)&quot;)?\s*\)/',
        function ($m) {
            $text = $m[1];
            $title = isset($m[4]) ? $m[4] : '';
            $href = htmlspecialchars_decode(($m[2] !== '' ? $m[2] : $m[3]), ENT_QUOTES);
            $safe = md_safe_url($href);
            if ($safe === '') {
                return $text;                          // 危险协议丢掉链接保留文字
            }
            $t = $title !== '' ? ' title="' . $title . '"' : '';
            if (md_starts_with($safe, 'http') || md_starts_with($safe, '//') || md_starts_with($safe, 'mailto:')) {
                return '<a href="' . md_e($safe) . '" rel="noopener" target="_blank"' . $t . '>' . $text . '</a>';
            }
            return '<a href="' . md_e($safe) . '"' . $t . '>' . $text . '</a>';
        },
        $html
    );

    // 粗体 / 斜体 / 删除线（~~ 与后台工具栏的插入格式对应，v1.12.1 补渲染规则）
    $html = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $html);
    $html = preg_replace('/~~([^~\n]+)~~/', '<del>$1</del>', $html);

    // 还原行内代码
    $html = preg_replace_callback('/\x00CB(\d+)\x00/', function ($m) use (&$codes) {
        return isset($codes[(int)$m[1]]) ? $codes[(int)$m[1]] : '';
    }, $html);

    return $html;
}

function md_slug($text, &$used)
{
    $id = strtolower(trim($text));
    $id = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $id);
    $id = preg_replace('/[\s_]+/u', '-', $id);
    $id = trim($id, '-');
    if ($id === '') {
        $id = 'section';
    }
    $base = $id;
    $i = 2;
    while (isset($used[$id])) {
        $id = $base . '-' . $i;
        $i++;
    }
    $used[$id] = true;
    return $id;
}

function md_render($text, $cfg = null)
{
    if ($cfg === null) {
        $cfg = md_config_load();
    }
    $text = str_replace(array("\r\n", "\r"), "\n", $text);
    $text = str_replace("\t", '    ', $text);
    $lines = explode("\n", $text);

    $out = array();
    $slugs = array();
    $toc = array();

    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];

        // 代码块 ``` / ~~~
        if (preg_match('/^\s{0,3}(`{3,}|~{3,})(.*)$/', $line, $m) === 1) {
            $fence = $m[1];
            $lang = trim($m[2]);
            $buf = array();
            $i++;
            while ($i < $n) {
                if (preg_match('/^\s{0,3}' . preg_quote($fence[0], '/') . '{3,}\s*$/', $lines[$i]) === 1) {
                    $i++;
                    break;
                }
                $buf[] = $lines[$i];
                $i++;
            }
            $code = htmlspecialchars(implode("\n", $buf), ENT_QUOTES, 'UTF-8');
            $cls = $lang !== '' ? ' class="language-' . md_e($lang) . '"' : '';
            if ($lang === 'mermaid') {
                // mermaid 块：输出容器，前端从本地 vendor 通道按需加载渲染（v1.12.0 回归自托管，见 SPEC 4.5）
                $out[] = '<pre class="mermaid">' . $code . '</pre>';
            } else {
                $out[] = '<pre><code' . $cls . '>' . $code . '</code></pre>';
            }
            continue;
        }

        // 空行
        if (trim($line) === '') {
            $i++;
            continue;
        }

        // 标题
        if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m) === 1) {
            $level = strlen($m[1]);
            $raw = $m[2];
            $plain = strip_tags($raw);
            $slug = md_slug($plain, $slugs);
            $toc[] = array('level' => $level, 'text' => $plain, 'id' => $slug);
            $out[] = '<h' . $level . ' id="' . md_e($slug) . '">' . md_inline(md_e($raw)) . '</h' . $level . '>';
            $i++;
            continue;
        }

        // 分隔线
        if (preg_match('/^\s{0,3}([-*_])\s*(\1\s*){2,}$/', $line) === 1) {
            $out[] = '<hr>';
            $i++;
            continue;
        }

        // 引用块
        if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m) === 1) {
            $buf = array();
            while ($i < $n && preg_match('/^\s{0,3}>\s?(.*)$/', $lines[$i], $mm) === 1) {
                $buf[] = $mm[1];
                $i++;
            }
            $out[] = '<blockquote>' . md_render_blocks($buf) . '</blockquote>';
            continue;
        }

        // 表格（| a | b |）
        if (md_starts_with(ltrim($line), '|') && ($i + 1) < $n
            && preg_match('/^\s{0,3}\|?[\s:\-|]+\|?\s*$/', $lines[$i + 1]) === 1
            && md_contains($lines[$i + 1], '-')) {
            $header = md_split_row($lines[$i]);
            $i += 2;
            $rows = array();
            while ($i < $n && md_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
                $rows[] = md_split_row($lines[$i]);
                $i++;
            }
            $html = '<table><thead><tr>';
            foreach ($header as $cell) {
                $html .= '<th>' . md_inline(md_e(trim($cell))) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . md_inline(md_e(trim($cell))) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $out[] = $html;
            continue;
        }

        // 列表（无序 / 有序）
        if (preg_match('/^\s{0,3}([-*+]|\d+[.)])\s+(.*)$/', $line, $m) === 1) {
            $ordered = ctype_digit(substr($m[1], 0, 1));
            $items = array();
            while ($i < $n) {
                if (preg_match('/^\s{0,3}([-*+]|\d+[.)])\s+(.*)$/', $lines[$i], $mm) === 1) {
                    $items[] = $mm[2];
                    $i++;
                } elseif (preg_match('/^\s{2,}\S/', $lines[$i]) === 1 && $items) {
                    // 续行：并入上一条
                    $items[count($items) - 1] .= ' ' . trim($lines[$i]);
                    $i++;
                } else {
                    break;
                }
            }
            $tag = $ordered ? 'ol' : 'ul';
            $html = '<' . $tag . '>';
            foreach ($items as $it) {
                $html .= '<li>' . md_inline(md_e(trim($it))) . '</li>';
            }
            $html .= '</' . $tag . '>';
            $out[] = $html;
            continue;
        }

        // 普通段落：吃到空行或下一个块级元素
        $buf = array();
        while ($i < $n && trim($lines[$i]) !== ''
            && preg_match('/^\s{0,3}(#{1,6}\s|>|`{3,}|~{3,}|[-*+]\s|\d+[.)]\s)/', $lines[$i]) !== 1
            && !md_starts_with(ltrim($lines[$i]), '|')) {
            $buf[] = trim($lines[$i]);
            $i++;
        }
        if (!$buf) {
            $i++;
            continue;
        }
        $out[] = '<p>' . md_inline(md_e(implode("\n", $buf))) . '</p>';
    }

    $html = implode("\n", $out);
    return array('html' => $html, 'toc' => $toc);
}

function md_split_row($line)
{
    $line = trim(trim($line), '|');
    $cells = explode('|', $line);
    return $cells;
}

function md_render_blocks($lines)
{
    $r = md_render(implode("\n", $lines));
    return $r['html'];
}

function md_rewrite_links($html, $docRel, $cfg)
{
    $dirRel = dirname($docRel);
    $dirRel = ($dirRel === '.' || $dirRel === '/') ? '' : $dirRel;

    return preg_replace_callback(
        '/(href|src)="([^"]*)"/i',
        function ($m) use ($dirRel, $cfg) {
            $attr = strtolower($m[1]);
            $url = htmlspecialchars_decode($m[2], ENT_QUOTES);

            // 外部、协议、锚点、绝对路径一律不动
            if ($url === '' || md_starts_with($url, '#') || md_starts_with($url, '/')
                || md_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) === 1) {
                return $m[0];
            }

        // 拆出 #锚点：先编码路径部分，锚点原样接回（否则 # 会被整段编码导致跳转失效）
        $frag = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $frag = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }
        // 段级 rawurldecode：用户写 %20 或裸空格两种形态都要能落到真实文件（先解码再统一编码）
        $segs = explode('/', $url);
        foreach ($segs as $k => $s) {
            $segs[$k] = rawurldecode($s);
        }
        $target = $dirRel === '' ? implode('/', $segs) : $dirRel . '/' . implode('/', $segs);
        $target = md_normalize_rel($target);
        if ($target === false) {
            return $attr . '="#"';                 // 解析不出安全路径：不给链接
        }
        $fragOut = $frag !== '' ? '#' . str_replace('%2F', '/', rawurlencode(substr($frag, 1))) : '';

        $pathOnly = parse_url($target, PHP_URL_PATH);
        $ext = md_ext($pathOnly === null ? '' : $pathOnly);
        if (in_array($ext, $cfg['ext_md'], true)) {
            $target = substr($target, 0, -(strlen($ext) + 1));
            return $attr . '="' . md_e(md_url_for($target, 'doc', $cfg) . $fragOut) . '"';
        }
        if (in_array($ext, $cfg['ext_attach'], true)) {
            return $attr . '="' . md_e(md_url_for($target, 'file', $cfg) . $fragOut) . '"';
        }
            // 未知后缀（html/php 等）：不给可点链接，只留文字，避免变成可利用的出口
            return $attr . '="#"';
        },
        $html
    );
}

function md_normalize_rel($rel)
{
    $rel = str_replace('\\', '/', $rel);
    $segs = explode('/', $rel);
    $out = array();
    foreach ($segs as $s) {
        if ($s === '' || $s === '.') {
            continue;
        }
        if ($s === '..') {
            if (!$out) {
                return false;
            }
            array_pop($out);
            continue;
        }
        $out[] = $s;
    }
    return implode('/', $out);
}

function md_safe_name($name)
{
    $name = str_replace('\\', '/', (string)$name);
    $name = basename($name);                       // 只取最后一段
    $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name);   // 控制字符
    $name = trim($name, " \t.");                   // 尾部和首部的点/空格（Windows 陷阱）
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }
    // Windows 保留名（含 con.txt 这类形式）
    $base = pathinfo($name, PATHINFO_FILENAME);
    $reserved = array('con', 'prn', 'aux', 'nul',
                      'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
                      'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9');
    if (in_array(strtolower($base), $reserved, true)) {
        return false;
    }
    if (strlen($name) > 128) {
        return false;
    }
    // 只保留安全字符集：字母数字中文点下划线连字符空格括号
    if (preg_match('/^[A-Za-z0-9._\-\s\x{4e00}-\x{9fa5}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}()\[\]（）【】#&+@,;=~\'"!%]+$/u', $name) !== 1) {
        return false;
    }
    return $name;
}

function md_safe_dirname($name)
{
    $safe = md_safe_name($name);
    if ($safe === false) {
        return false;
    }
    if (strpos($safe, '/') !== false) {
        return false;
    }
    return $safe;
}

function md_unique_name($dir, $name)
{
    if (!file_exists($dir . '/' . $name)) {
        return $name;
    }
    $ext = '';
    $base = $name;
    $dot = strrpos($name, '.');
    if ($dot !== false && $dot > 0) {
        $base = substr($name, 0, $dot);
        $ext = substr($name, $dot);
    }
    for ($i = 1; $i < 1000; $i++) {
        $cand = $base . ' ' . $i . $ext;
        if (!file_exists($dir . '/' . $cand)) {
            return $cand;
        }
    }
    return false;
}

function md_write_file($abs, $content)
{
    if (MD_READONLY) {
        return false;
    }
    $tmp = $abs . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $abs)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function md_search_index_file()
{
    return MD_DIR . '/cache/search.php';
}

function md_search_extract($text)
{
    $text = md_strip_front_matter($text);
    $text = preg_replace('/```.*?```/s', ' ', $text);       // 代码块内容不参与搜索
    $text = preg_replace('/`[^`]*`/', ' ', $text);          // 行内代码
    $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text);  // 图片
    $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text); // 链接留文字
    $text = preg_replace('/[#*_>~|]/', ' ', $text);         // 常见标记符
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function md_search_index_load()
{
    $f = md_search_index_file();
    if (!is_file($f)) {
        return array('mtime' => array(), 'text' => array());
    }
    $d = @include $f;
    if (!is_array($d) || !isset($d['mtime'], $d['text']) || !is_array($d['mtime']) || !is_array($d['text'])) {
        return array('mtime' => array(), 'text' => array());
    }
    return $d;
}

function md_search_index_save($index)
{
    if (MD_READONLY) {
        return false;
    }
    $f = md_search_index_file();
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $body = "<?php\n// DirDocs 全文搜索索引（自动生成，可删除）\nreturn "
        . var_export($index, true) . ";\n";
    return md_write_file($f, $body);
}

function md_search_index_build($cfg, $tree = null)
{
    if ($tree === null) {
        $tree = md_tree_load($cfg);
    }
    $index = md_search_index_load();
    $dirty = false;

    foreach ($tree['md'] as $rel => $info) {
        if (!empty($info['draft'])) {
            // 草稿不进全文索引（搜索/建议均不可见）；此前已索引的条目清掉
            if (isset($index['text'][$rel])) {
                unset($index['text'][$rel], $index['mtime'][$rel]);
                $dirty = true;
            }
            continue;
        }
        $mtime = isset($info['mtime']) ? (int)$info['mtime'] : 0;
        if (isset($index['mtime'][$rel]) && (int)$index['mtime'][$rel] === $mtime
            && isset($index['text'][$rel])) {
            continue;                                   // 未变化，跳过
        }
        $abs = md_resolve($rel, $cfg, 'md');
        if ($abs === false) {
            continue;
        }
        $text = md_read_text($abs);
        if ($text === false) {
            continue;
        }
        $meta = md_front_matter($text);
        $title = $meta['title'] !== '' ? $meta['title'] : md_title_of($rel);
        $index['text'][$rel] = $title . ' ' . md_search_extract($text);
        $index['mtime'][$rel] = $mtime;
        $dirty = true;
    }

    // 已删除文件的索引项清掉
    foreach ($index['mtime'] as $rel => $m) {
        if (!isset($tree['md'][$rel])) {
            unset($index['mtime'][$rel], $index['text'][$rel]);
            $dirty = true;
        }
    }

    // 无变化不落盘：搜索是读路径，别每次查询都写一次文件
    if ($dirty || !is_file(md_search_index_file())) {
        md_search_index_save($index);
    }
    return $index;
}

function md_search_ready($cfg)
{
    if (MD_READONLY) {
        return false;                                   // 只读模式不建索引
    }
    return is_file(md_search_index_file());
}

function md_search($query, $cfg, $limit = 50, $sort = 'score')
{
    $query = trim((string)$query);
    if ($query === '') {
        return array();
    }
    $index = md_search_index_build($cfg);
    if (!$index['text']) {
        return array();
    }

    $useMb = function_exists('mb_stripos');
    $q = $query;
    $results = array();

    foreach ($index['text'] as $rel => $text) {
        $title = md_title_of($rel);
        if ($useMb) {
            $posTitle = mb_stripos($title, $q, 0, 'UTF-8');
            $posBody = mb_stripos($text, $q, 0, 'UTF-8');
        } else {
            $posTitle = stripos($title, $q);
            $posBody = stripos($text, $q);
        }
        if ($posTitle === false && $posBody === false) {
            continue;
        }
        // 命中次数（mb_substr_count 是 PHP 8.0+，用循环实现保证 7.1 可用）
        $hits = 0;
        $lowerText = $useMb ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $lowerQ = $useMb ? mb_strtolower($q, 'UTF-8') : strtolower($q);
        $offset = 0;
        while (($pos = ($useMb ? mb_stripos($lowerText, $lowerQ, $offset, 'UTF-8') : strpos($lowerText, $lowerQ, $offset))) !== false) {
            $hits++;
            $offset = $pos + max(1, $useMb ? mb_strlen($lowerQ, 'UTF-8') : strlen($lowerQ));
            if ($hits > 500) {
                break;                                  // 防极端长文拖慢
            }
        }
        $score = $hits + ($posTitle !== false ? 100 : 0);
        $results[] = array(
            'rel'   => $rel,
            'title' => $title,
            'score' => $score,
            'mtime' => isset($index['mtime'][$rel]) ? (int)$index['mtime'][$rel] : 0,
            'snippet' => md_search_snippet($text, $q, $useMb),
        );
    }

    md_search_sort_results($results, $sort);
    return array_slice($results, 0, $limit);
}

/** 排序解析（纯函数，v1.15.2）：URL 参数 > 访客 Cookie 偏好 > 默认 score，三者都过白名单。 */
function md_search_sort_resolve($param, $cookie)
{
    foreach (array($param, $cookie) as $v) {
        if ($v === 'time' || $v === 'score') {
            return $v;
        }
    }
    return 'score';
}

/**
 * 结果排序（纯函数，v1.15.1）：score = 相关度分降序、名称次序平局（v1.7 起的原行为）；
 * time = 文档 mtime 新→旧、分数与名称次序双平局。mtime 本就在索引里，时间序零额外成本。
 */
function md_search_sort_results(&$results, $sort)
{
    if ($sort === 'time') {
        usort($results, function ($a, $b) {
            if ($a['mtime'] !== $b['mtime']) {
                return $a['mtime'] > $b['mtime'] ? -1 : 1;
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] - $a['score'];
            }
            return md_str_compare($a['rel'], $b['rel']);
        });
        return $results;
    }
    usort($results, function ($a, $b) {
        if ($a['score'] === $b['score']) {
            return md_str_compare($a['rel'], $b['rel']);
        }
        return $b['score'] - $a['score'];
    });
    return $results;
}

function md_search_snippet($text, $q, $useMb, $len = 120)
{
    if ($useMb) {
        $pos = mb_stripos($text, $q, 0, 'UTF-8');
        $pos = $pos === false ? 0 : $pos;
        $start = max(0, $pos - 40);
        $snippet = mb_substr($text, $start, $len, 'UTF-8');
    } else {
        $pos = stripos($text, $q);
        $pos = $pos === false ? 0 : $pos;
        $start = max(0, $pos - 40);
        $snippet = substr($text, $start, $len);
    }
    $snippet = trim($snippet);
    if ($start > 0) {
        $snippet = '…' . $snippet;
    }
    if (($useMb ? mb_strlen($text, 'UTF-8') : strlen($text)) > $start + $len) {
        $snippet .= '…';
    }
    return $snippet;
}

/** 在已转义的 HTML 上做关键词高亮前，先把 HTML 实体（&amp; 等）占位保护，避免 mark 劈进实体中间。 */
function md_highlight_entities_guard($escapedHtml, &$store)
{
    return preg_replace_callback('/&[#a-zA-Z0-9]+;/', function ($m) use (&$store) {
        $store[] = $m[0];
        return "\x00E" . (count($store) - 1) . "\x00";
    }, $escapedHtml);
}

function md_highlight_entities_restore($html, &$store)
{
    return preg_replace_callback('/\x00E(\d+)\x00/', function ($m) use (&$store) {
        return isset($store[(int)$m[1]]) ? $store[(int)$m[1]] : '';
    }, $html);
}

function md_search_highlight($escapedHtml, $q)
{
    $eq = md_e($q);
    if ($eq === '') {
        return $escapedHtml;
    }
    $ent = array();
    $html = md_highlight_entities_guard($escapedHtml, $ent);
    $pattern = '/' . preg_quote($eq, '/') . '/i';
    $html = preg_replace($pattern, '<mark>' . $eq . '</mark>', $html);
    return md_highlight_entities_restore($html, $ent);
}

function md_highlight_in_html($html, $q)
{
    $eq = md_e($q);
    if ($eq === '' || $html === '') {
        return $html;
    }
    $tags = array();
    // 保护标签、注释与 HTML 实体
    $tokenized = preg_replace_callback('/<[^>]*>/', function ($m) use (&$tags) {
        $tags[] = $m[0];
        return "\x00T" . (count($tags) - 1) . "\x00";
    }, $html);

    $ent = array();
    $tokenized = md_highlight_entities_guard($tokenized, $ent);
    $pattern = '/' . preg_quote($eq, '/') . '/i';
    $tokenized = preg_replace($pattern, '<mark>' . $eq . '</mark>', $tokenized);
    $tokenized = md_highlight_entities_restore($tokenized, $ent);

    // 还原标签
    $tokenized = preg_replace_callback('/\x00T(\d+)\x00/', function ($m) use (&$tags) {
        return isset($tags[(int)$m[1]]) ? $tags[(int)$m[1]] : '';
    }, $tokenized);

    return $tokenized;
}

function md_highlight_headings($html, $q)
{
    $eq = md_e($q);
    if ($eq === '') {
        return $html;
    }
    $pattern = '/' . preg_quote($eq, '/') . '/i';
    return preg_replace_callback('/(<h[1-6][^>]*>)(.*?)(<\/h[1-6]>)/is', function ($m) use ($eq, $pattern) {
        $ent = array();
        $inner = md_highlight_entities_guard($m[2], $ent);
        $inner = preg_replace($pattern, '<mark>' . $eq . '</mark>', $inner);
        return $m[1] . md_highlight_entities_restore($inner, $ent) . $m[3];
    }, $html);
}

// ---------------------------------------------------------------- 基础（boot 依赖）

// ---------------------------------------------------------------- 目录树缓存

function md_tree_cache_file()
{
    return MD_DIR . '/cache/tree.php';
}

function md_tree_cache_clear()
{
    @unlink(md_tree_cache_file());
}

/** 缓存指纹：白名单/排序/版本任一变化即整体失效。 */
function md_tree_fingerprint($cfg)
{
    return md5(serialize(array($cfg['ext_md'], $cfg['ext_attach'], $cfg['hidden_prefix'],
                               !empty($cfg['order_prefix']), MD_VERSION)));
}

function md_tree_cache_read($cfg)
{
    $f = md_tree_cache_file();
    if (!is_file($f)) {
        return null;
    }
    $d = @include $f;
    if (!is_array($d) || !isset($d['v'], $d['fp'], $d['built'], $d['tree']) || !is_array($d['tree'])) {
        return null;
    }
    if ((int)$d['v'] !== 3 || $d['fp'] !== md_tree_fingerprint($cfg)) {
        return null;
    }
    return $d;
}

function md_tree_cache_write($tree, $cfg)
{
    if (MD_READONLY) {
        return false;
    }
    $f = md_tree_cache_file();
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $body = "<?php\n// DirDocs 目录树索引缓存（自动生成，可删除）\nreturn "
        . var_export(array('v' => 3, 'fp' => md_tree_fingerprint($cfg), 'built' => time(), 'tree' => $tree), true) . ";\n";
    return md_write_file($f, $body);
}

/**
 * 前台统一入口：优先读缓存（60 秒新鲜期，后台写操作会主动清缓存），
 * 过期/失效则现扫并回写。同时把树挂到全局，md_title_of 等可直接取扫描期元数据。
 */
function md_tree_load($cfg)
{
    $useCache = !empty($cfg['cache']) && !MD_READONLY;
    $tree = null;
    if ($useCache) {
        $c = md_tree_cache_read($cfg);
        if ($c !== null && (time() - (int)$c['built']) <= 60) {
            $tree = $c['tree'];
        }
    }
    if ($tree === null) {
        $tree = md_scan(MD_ROOT, $cfg);
        if ($useCache) {
            md_tree_cache_write($tree, $cfg);
        }
    }
    $GLOBALS['md_tree'] = $tree;
    return $tree;
}

// ---------------------------------------------------------------- 基础（boot 依赖）

function md_config_defaults()
{
    return array(
        'site_title'    => '我的文档',
        'site_desc'     => '',
        'admin_user'    => 'admin',
        'theme'         => 'default',
        'mode'          => 'auto',
        'url_mode'      => 'simple',
        'base_path'     => '',
        'home'          => '',
        'ext_md'        => array('md', 'markdown', 'txt'),
        // 附件白名单与 SPEC 4.4 对齐：md/txt 可下载但不进附件区（附件区只列非 md）
        'ext_attach'    => array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico',
                                 'pdf', 'zip', 'mp4', 'webm', 'md', 'markdown', 'txt'),
        'hidden_prefix' => array('.', '_dir'),
        'cache'         => true,
        'show_attach'   => true,
        'admin_pass'    => '',
        // TOTP 两步验证密钥（RFC 6238，Base32 无填充）：空 = 未启用。详见 md_admin.php
        'admin_totp'    => '',
        'order_prefix'  => true,
        'read_only'     => false,
        // 旧版 CDN 源选择键（v1.9.0 全站 CDN 化引入，v1.12.0 资源回归全自托管后两键已不读）：
        // 保留键位只为兼容旧配置文件经 array_merge 不报错，后台 UI 与保存通道均已移除
        'admin_cdn'     => 'auto',
        'cdn'           => 'auto',
        // 界面语言：auto = 按浏览器 Accept-Language 自动（zh-CN/en），可强制指定 zh-CN 或 en。
        // 只影响程序界面文案，不翻译文档内容、不改 URL 结构（见 md_i18n.php）
        'language'      => 'auto',
        // 站点绝对地址（https://docs.example.com，无尾斜杠）：sitemap/canonical 用。
        // 留空按当前请求自动推断；反代/多域名场景自动推断可能失真，建议显式填写
        'site_url'      => '',
        // 风险文件警示豁免（后台登记的相对路径）：精确匹配豁免单个文件，
        // 以 / 结尾豁免整个目录。只关闭警示，不影响程序自身的输出白名单
        'risk_ignore'   => array(),
        // 访客评论（v1.17.0）：以 <文档名>.cmt.php 形式存放在文档旁（首行 PHP 执行锁），
        // 先审后显，后台「评论」页批准。false 整站关闭评论面（端点 404、区块不渲染）
        'comments'      => true,
        // 邮件通知（随包休眠）：smtp_host 留空 = 不发任何信、零外联。secure 取值
        // ''（STARTTLS 自动升级）/ 'ssl'（隐式 TLS，常配 465）/ 'none'（仅内网中继）
        'smtp_host'     => '',
        'smtp_port'     => '465',
        'smtp_user'     => '',
        'smtp_pass'     => '',
        'smtp_from'     => '',
        'smtp_secure'   => '',
        'notify_email'  => '',
    );
}

function md_config_load()
{
    if (isset($GLOBALS['md_cfg_cache'])) {
        return $GLOBALS['md_cfg_cache'];                // 请求内静态缓存：省去重复 include 解析
    }
    $cfg = md_config_defaults();
    $file = MD_DIR . '/config.php';
    if (is_file($file)) {
        $loaded = @include $file;           // 内容形如 <?php return [...]
        if (is_array($loaded)) {
            $cfg = array_merge($cfg, $loaded);
        }
    }
    // 归一化：所有列表型配置都必须是数组，防止配置被写坏后类型混乱
    foreach (array('ext_md', 'ext_attach', 'hidden_prefix') as $k) {
        if (!is_array($cfg[$k]) || !$cfg[$k]) {
            $cfg[$k] = md_config_defaults()[$k];
        }
    }
    // risk_ignore 允许为空数组（空 = 不豁免任何文件），只做类型守卫，不走上面的"空回退默认"
    if (!isset($cfg['risk_ignore']) || !is_array($cfg['risk_ignore'])) {
        $cfg['risk_ignore'] = array();
    }
    // 界面语言白名单：非法值回落 auto（按浏览器自动），防止配置写坏后 md_lang_pack 拼错路径
    if (!isset($cfg['language']) || !in_array($cfg['language'], md_lang_supported(), true)) {
        $cfg['language'] = 'auto';
    }
    // 评论与邮件通知（v1.17.0）：布尔与字符串守卫，配置写坏也不影响主流程
    if (!isset($cfg['comments'])) {
        $cfg['comments'] = true;
    }
    foreach (array('smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_secure', 'notify_email') as $k) {
        if (!isset($cfg[$k]) || !is_string($cfg[$k])) {
            $cfg[$k] = md_config_defaults()[$k];
        }
    }
    if (!in_array($cfg['smtp_secure'], array('', 'ssl', 'none', 'auto'), true)) {
        $cfg['smtp_secure'] = '';
    }
    if (MD_READONLY) {
        $cfg['read_only'] = true;
    }
    $GLOBALS['md_cfg_cache'] = $cfg;
    return $cfg;
}

/** 写配置后调用：让下一次 md_config_load 重新读文件（同请求内可见新值）。 */
function md_config_reset()
{
    unset($GLOBALS['md_cfg_cache']);
}

function md_security_headers()
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    // style-src 需要 unsafe-inline：主题 CSS 变量与 md_render 的行内样式（TOC 缩进等）在用。
    // script-src 允许 'self'（本函数只服务 403/404 中止页，页面正文走 md_theme_csp 的
    // 按预设动态 CSP）+ 'unsafe-inline'——中止页有行内样式/脚本，内容完全由程序生成、
    // 不含任何用户数据，unsafe-inline 在这里不扩大 XSS 面。如需更严策略，可改为 nonce。
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
    header('Referrer-Policy: same-origin');
}

function md_abort($code)
{
    http_response_code($code);
    md_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="' . md_e(md_lang_current()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $code . '</title>'
        . '<style>body{font:15px/1.6 system-ui,sans-serif;color:#333;max-width:46em;margin:12vh auto;padding:0 1.2em}'
        . 'code{background:#f4f4f4;padding:.15em .4em;border-radius:3px}</style></head><body>';
    if ($code === 404) {
        echo '<h1>404</h1><p>' . md_e(md_t('err_404')) . '</p><p><a href="' . md_e(md_url_for('')) . '">' . md_e(md_t('back_home')) . '</a></p>';
    } else {
        echo '<h1>' . $code . '</h1><p>' . md_e(md_t('err_403')) . '</p>';
    }
    echo '</body></html>';
    exit;
}
