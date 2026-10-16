<?php
/**
 * DirDocs 后台（鉴权/登录/功能/分发） 模块（由 index.php 加载，勿单独访问）
 *
 * 页面：概览 / 文档（树形目录管理 + 编辑器）/ 附件 / 缓存 / 设置。
 * 56px 毛玻璃顶栏（与前台同语言）+ 桌面左侧竖导航（移动端 Bootstrap offcanvas
 * 抽屉）+ 纸张卡片内容区。v1.7.0 起「分类管理 = 目录管理」：目录 CRUD
 * （dirnew/dirrename/dirdel）与文档复制（copy）都在本模块内；服务端渲染兜底，
 * admin.js 渐进增强（工具栏/AJAX 保存/toast/Modal 确认/树过滤）。
 * 样式：Bootstrap 5.3（CDN，源随全站统一预设）→ builtin.css（共享组件）
 * → admin.css（后台布局）→ 内联主题变量块（md_theme_css 同值双写）；
 * 行为：admin.js（data-confirm 确认 + 引用复制 + 深浅主题 + 编辑器增强）。无任何内联脚本。
 *
 * 安全约定：
 *   - MD_BOOT 闸门；除登录/首设引导/登出外，一切页面过 md_admin_guard()
 *   - 一切 POST 过 md_csrf_guard()；删除类操作另有 data-confirm 弹窗确认
 *   - 路径参数过 md_resolve_path_only / md_resolve；前后缀一律白名单校验
 *   - 目录名单段过 md_safe_dirname；删目录只许 rmdir（非空即拒），不递归
 *   - 密码只存 password_hash，任何响应不回显；错误信息不泄露绝对路径
 *   - 登录防爆破按 IP 记账（_dir/cache/login.json），5 次失败锁 10 分钟
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}

// ---------------------------------------------------------------- 会话 / 登录态 / CSRF

/**
 * AJAX JSON 出口（后台唯一）：过 CSRF/guard 后由各动作调用。
 * 与 md_admin_page 同为「输出即终止」；头带 no-store 与 noindex，与后台页面一致。
 */
function md_admin_json($data)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($data);
    exit;
}

/**
 * [原样保留] 会话启动。
 * cookie path 跟随安装子路径；session 名掺文档根哈希：同一主机多套 DirDocs 互不串号；
 * PHP 7.3+ 用数组签名带 samesite=Lax（7.1/7.2 无数组签名，走位置参数）。
 */
function md_session_start()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $base = md_base_path(md_config_load());
    $path = ($base === '' || $base === false) ? '/' : $base;
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0, 'path' => $path, 'domain' => '',
            'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
        ));
    } else {
        // PHP 7.1/7.2 无数组签名，也无法经此函数设置 SameSite
        session_set_cookie_params(0, $path, '', $https, true);
    }
    session_name('MDADM' . substr(md5(MD_ROOT), 0, 6));
    session_start();
}

/** 登录态判定：前台不 start session，session 未激活时返回 false。 */
function md_is_admin()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    return !empty($_SESSION['md_admin']);
}

/** 登出并清掉会话 cookie。 */
function md_logout()
{
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function md_csrf_token()
{
    if (empty($_SESSION['md_csrf'])) {
        $_SESSION['md_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['md_csrf'];
}

function md_csrf_field()
{
    return '<input type="hidden" name="_token" value="' . md_e(md_csrf_token()) . '">';
}

/** 一切 POST 的总闸：令牌不符直接渲染失败页并终止。 */
function md_csrf_guard()
{
    md_session_start();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = isset($_POST['_token']) ? (string)$_POST['_token'] : '';
    if ($token === '' || !isset($_SESSION['md_csrf']) || !hash_equals($_SESSION['md_csrf'], $token)) {
        md_admin_page(md_t('csrf_fail_title'),
            '<div class="alert alert-danger">' . md_e(md_t('csrf_fail_body')) . '</div>'
            . '<p><a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('')) . '">' . md_e(md_t('admin_back')) . '</a></p>');
        exit;
    }
}

/** 页面守卫：未登录一律渲染登录页并终止（登录/首设/登出页不经过这里）。 */
function md_admin_guard()
{
    md_session_start();
    if (!md_is_admin()) {
        md_admin_login_page();
        exit;
    }
}

/**
 * [签名与行为保留] 风险文件警示（前台 md_pages.php 在每个页面调用）。
 * 非管理员只显示条数（不给攻击者指路）；管理员显示完整清单与处置说明。
 */
function md_risk_html($files, $isAdmin)
{
    if (!$files) {
        return '';
    }
    $count = count($files);
    if (!$isAdmin) {
        return '<div class="alert alert-danger md-warn" role="alert"><strong>' . md_e(md_t('risk_notice_title')) . '</strong>'
            . md_e(md_t('risk_notice_body', array('n' => $count))) . '</div>';
    }
    $html = '<div class="alert alert-danger md-warn" role="alert">'
        . '<strong>' . md_e(md_t('risk_admin_title', array('n' => $count))) . '</strong>'
        . '<p class="md-what">' . md_e(md_t('risk_admin_body')) . '</p><ul>';
    foreach ($files as $f) {
        $html .= '<li>' . md_e($f) . '</li>';
    }
    $html .= '</ul></div>';
    return $html;
}

// ---------------------------------------------------------------- 登录防爆破（按 IP）

/** 防爆破记账文件：{"ips":{"<md5(IP|UA)>":{"fail":n,"until":ts}}} */
function md_login_store_file()
{
    return MD_DIR . '/cache/login.json';
}

/** 当前访问者的记账桶 key：IP + UA 联合哈希。 */
function md_login_key()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    return md5($ip . '|' . $ua);
}

/** 读取全部 IP 桶；文件缺失/损坏一律按空处理。 */
function md_login_buckets()
{
    $f = md_login_store_file();
    if (!is_file($f)) {
        return array('ips' => array());
    }
    $d = md_read_text($f);
    if ($d === false) {
        return array('ips' => array());
    }
    $j = json_decode($d, true);
    if (!is_array($j) || !isset($j['ips']) || !is_array($j['ips'])) {
        return array('ips' => array());
    }
    return array('ips' => $j['ips']);
}

/** 写回桶数据；顺手清理 24h 前的过期锁与空桶，防文件无限膨胀。 */
function md_login_buckets_save($ips)
{
    $f = md_login_store_file();
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $now = time();
    $clean = array();
    foreach ($ips as $k => $b) {
        if (!is_array($b)) {
            continue;
        }
        $fail = isset($b['fail']) ? (int)$b['fail'] : 0;
        $until = isset($b['until']) ? (int)$b['until'] : 0;
        if ($until !== 0 && $until < $now - 86400) {
            continue;                                   // 解锁超过一天的旧桶
        }
        if ($until === 0 && $fail <= 0) {
            continue;                                   // 空桶
        }
        $clean[(string)$k] = array('fail' => $fail, 'until' => $until);
    }
    @file_put_contents($f, json_encode(array('ips' => $clean)), LOCK_EX);
}

/** 取单个桶；结构异常按空桶处理。 */
function md_login_bucket_get($data, $key)
{
    if (isset($data['ips'][$key]) && is_array($data['ips'][$key])) {
        $b = $data['ips'][$key];
        return array(
            'fail'  => isset($b['fail']) ? (int)$b['fail'] : 0,
            'until' => isset($b['until']) ? (int)$b['until'] : 0,
        );
    }
    return array('fail' => 0, 'until' => 0);
}

/** 当前 IP 剩余锁定分钟数（0 = 未锁）。 */
function md_login_lock_minutes()
{
    $b = md_login_bucket_get(md_login_buckets(), md_login_key());
    if ($b['until'] > time()) {
        return (int)ceil(($b['until'] - time()) / 60);
    }
    return 0;
}

/** 记一次失败；返回剩余可尝试次数（本次触发锁定则返回 0）。 */
function md_login_fail()
{
    $key = md_login_key();
    $data = md_login_buckets();
    $b = md_login_bucket_get($data, $key);
    $b['fail']++;
    if ($b['fail'] >= 5) {
        $b['until'] = time() + 600;                     // 5 次失败锁 10 分钟
        $b['fail'] = 0;
    }
    $data['ips'][$key] = $b;
    md_login_buckets_save($data['ips']);
    return $b['until'] > 0 ? 0 : 5 - $b['fail'];
}

/** 登录成功：清掉本 IP 的失败桶，重置会话 ID（防会话固定），写入登录态。 */
function md_login_ok()
{
    $key = md_login_key();
    $data = md_login_buckets();
    if (isset($data['ips'][$key])) {
        unset($data['ips'][$key]);
        md_login_buckets_save($data['ips']);
    }
    unset($_SESSION['md_totp_pending']);                // 两步流程的中间态一并清掉
    $_SESSION['md_admin'] = 1;
    $_SESSION['md_t'] = time();
    session_regenerate_id(true);
}

// ---------------------------------------------------------------- TOTP 两步验证（RFC 6238，纯 PHP）

/**
 * 设计（v1.11.0）：密钥存 config 的 admin_totp（Base32，空 = 未启用），与 admin_pass
 * 同一张表、同一个找回路径（文件权限者可手工删除该键复位）。纯 PHP 实现：
 * hash_hmac('sha1') 是内置的，零依赖；验证窗口 ±1 个周期（±30s，容忍设备时钟漂移）；
 * 会话内记录最近使用的周期数防同一码重放；验证失败计入登录失败桶（与密码共用 5 次锁
 * 10 分钟的防爆破节奏）。启用流程：先生成密钥进会话，用户扫码/手输后提交一个动态码
 * 验明设备可用才落盘——防止抄错密钥把自己锁死。禁用需提供当前动态码（防会话劫持后
 * 直接拆掉两步验证）。
 */

/** 生成 160 位随机密钥的 Base32 编码（32 字符，无填充）。ord(&31) 恰好无模偏差。 */
function md_totp_secret_gen()
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    for ($i = 0; $i < 32; $i++) {
        $out .= $alphabet[ord(random_bytes(1)) & 31];
    }
    return $out;
}

/** Base32（RFC 4648 无填充）解码；非法字符/长度不足返回 false。 */
function md_totp_base32_decode($s)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper((string)$s);
    $bits = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '=' || $c === ' ') {
            continue;                                   // 容忍粘贴带来的填充符与分组空格
        }
        $pos = strpos($alphabet, $c);
        if ($pos === false) {
            return false;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {    // 循环计数（PHP 7.1 兼容，见踩坑 9.1）
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out === '' ? false : $out;
}

/** HOTP（RFC 4226）：8 字节大端计数器 + HMAC-SHA1 + 动态截断，取 6 位十进制。 */
function md_totp_hotp($rawKey, $counter)
{
    $bin = pack('N', 0) . pack('N', $counter);          // 64 位大端（计数器实用上远小于 2^32）
    $h = hash_hmac('sha1', $bin, $rawKey, true);
    $off = ord(substr($h, -1)) & 0x0F;
    $val = ((ord($h[$off]) & 0x7F) << 24)
         | ((ord($h[$off + 1]) & 0xFF) << 16)
         | ((ord($h[$off + 2]) & 0xFF) << 8)
         | (ord($h[$off + 3]) & 0xFF);
    return str_pad((string)($val % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * 校验动态码：±1 周期窗口 + hash_equals 时序安全比较 + 会话级防重放
 * （$_SESSION['md_totp_last'] 记录已消费的周期，更早/相同周期的码一律不再接受）。
 * $consume = false 用于禁用/启用确认之外的只读校验（当前无调用方，留作扩展）。
 */
function md_totp_verify($secret, $code, $consume = true)
{
    $raw = md_totp_base32_decode($secret);
    $code = preg_replace('/[^0-9]/', '', (string)$code);
    if ($raw === false || strlen($code) !== 6) {
        return false;
    }
    $slice = (int)floor(time() / 30);
    $last = isset($_SESSION['md_totp_last']) ? (int)$_SESSION['md_totp_last'] : -1;
    for ($i = 1; $i >= -1; $i--) {
        $c = $slice + $i;
        if ($c <= $last) {
            continue;
        }
        if (hash_equals(md_totp_hotp($raw, $c), $code)) {
            if ($consume) {
                $_SESSION['md_totp_last'] = $c;
            }
            return true;
        }
    }
    return false;
}

/** otpauth:// URI（Google Authenticator 等统一识别）：账号做显示名，站点名做 issuer。 */
function md_totp_uri($secret, $cfg)
{
    $issuer = (string)$cfg['site_title'] !== '' ? (string)$cfg['site_title'] : 'DirDocs';
    return 'otpauth://totp/' . rawurlencode('DirDocs:' . (string)$cfg['admin_user'])
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

// ---------------------------------------------------------------- 修订历史（v1.14.0）

/**
 * 存储形态：_dir/cache/history/<md5(rel)>/ 下 meta.json + 版本文件。
 * 覆盖保存（编辑器）与恢复操作前自动归档当前内容；每篇保留最近 20 版，
 * 单版超过 2MB 不归档（防 cache 目录膨胀失控）；与最近一版同内容跳过（不产生空转版本）。
 * 版本文件是带闸门的 PHP 数据文件（v1.16.0，与 tree.php/search.php 同一防线）：
 * 被绕过程序直接 Web 访问时输出 403 空白——Nginx 共享主机贴不了 deny 规则也不泄露
 * 草稿历史全文；v1.15 及更早的裸 .md 版本文件由 md_history_file_content 兼容直读。
 * 位置取舍：history 放 cache/ 是有意的——后台「清除缓存」只删 tree/search 两文件
 * 不动 history/；但用户手工清空 cache 目录会连带删历史（页面上有提示文案）。
 * $base 参数供测试注入临时目录，线上恒走默认。
 */

function md_history_dir($base = null)
{
    return $base === null ? MD_DIR . '/cache/history' : (string)$base;
}

/** 单文档历史目录：md5(rel) 十六进制目录名，路径拼接无穿越面。 */
function md_history_doc_dir($rel, $base = null)
{
    return md_history_dir($base) . '/' . md5((string)$rel);
}

function md_history_meta($rel, $base = null)
{
    $file = md_history_doc_dir($rel, $base) . '/meta.json';
    $j = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    $versions = is_array($j) && isset($j['versions']) && is_array($j['versions']) ? array_values($j['versions']) : array();
    return array('rel' => (string)$rel, 'versions' => $versions);
}

/**
 * 版本文件闸门包装（纯函数，可测）：首行校验 MD_BOOT，直访 403 空白；
 * 程序侧 include 取回原文。内容经 var_export 成合法 PHP 字符串字面量，注入无门。
 */
function md_history_gate_wrap($content)
{
    return "<?php\n// DirDocs 修订版本数据文件（闸门格式，勿手工编辑）\n"
        . "if (!defined('MD_BOOT')) { http_response_code(403); exit; }\n"
        . 'return ' . var_export((string)$content, true) . ";\n";
}

/** 读版本文件内容：.php 闸门格式 include 取值；v1.15 及更早的裸 .md 直读（升级平滑兼容）。 */
function md_history_file_content($dir, $f)
{
    $path = $dir . '/' . basename((string)$f);
    if (!is_file($path)) {
        return false;
    }
    if (substr((string)$f, -4) === '.php') {
        $c = @include $path;                            // 闸门数据文件：return 内容字符串
        return is_string($c) ? $c : false;
    }
    $c = @file_get_contents($path);
    return $c === false ? false : $c;
}

/** 归档一次内容：成功返回 true；只读模式/空内容/超 2MB/与最新版相同都安静跳过。 */
function md_history_archive($rel, $content, $base = null)
{
    if (MD_READONLY || !is_string($content) || $content === '' || strlen($content) > 2 * 1024 * 1024) {
        return false;
    }
    $dir = md_history_doc_dir($rel, $base);
    $meta = md_history_meta($rel, $base);
    if (!empty($meta['versions'])) {
        $prev = md_history_file_content($dir, $meta['versions'][0]['f']);
        if (is_string($prev) && $prev === $content) {
            return false;
        }
    }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $f = gmdate('Ymd_His') . '-' . substr(md5($content), 0, 8) . '.php';
    if (!md_write_file($dir . '/' . $f, md_history_gate_wrap($content))) {
        return false;
    }
    array_unshift($meta['versions'], array('f' => $f, 't' => time(), 's' => strlen($content)));
    while (count($meta['versions']) > 20) {                 // 只保留最近 20 版，裁掉的连文件一起删
        $old = array_pop($meta['versions']);
        @unlink($dir . '/' . (is_array($old) && isset($old['f']) ? $old['f'] : ''));
    }
    return md_write_file($dir . '/meta.json',
        json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP));
}

/** 版本清单（只含文件仍存在的版本；最新在前）。 */
function md_history_list($rel, $base = null)
{
    $meta = md_history_meta($rel, $base);
    $dir = md_history_doc_dir($rel, $base);
    $out = array();
    foreach ($meta['versions'] as $v) {
        if (is_array($v) && isset($v['f'], $v['t'], $v['s']) && is_file($dir . '/' . $v['f'])) {
            $out[] = array('f' => (string)$v['f'], 't' => (int)$v['t'], 's' => (int)$v['s']);
        }
    }
    return $out;
}

/** 读版本内容：文件名过严格白名单正则（时间戳-8hex + .php/.md）再 basename 收口，杜绝拼接。 */
function md_history_read($rel, $f, $base = null)
{
    if (!is_string($f) || preg_match('/^[0-9]{8}_[0-9]{6}-[0-9a-f]{8}\.(?:php|md)$/', $f) !== 1) {
        return false;
    }
    return md_history_file_content(md_history_doc_dir($rel, $base), $f);
}

function md_history_clear($rel, $base = null)
{
    $dir = md_history_doc_dir($rel, $base);
    if (!is_dir($dir)) {
        return true;
    }
    $items = @scandir($dir);
    if (is_array($items)) {
        foreach ($items as $n) {
            if ($n !== '.' && $n !== '..') {
                @unlink($dir . '/' . $n);
            }
        }
    }
    return @rmdir($dir);
}

/**
 * 行级对比（纯函数）：先裁公共前后缀把 O(n×m) 压到差异核心，再 LCS 回溯。
 * 差异核心任一侧超 $max 行、或输出超 $max*2 行则放弃渲染（too_big），
 * 由页面引导改用「预览」——文档级对比不值得在后台渲染 O(百万) 格。
 */
function md_history_diff_lines($old, $cur, $max = 400)
{
    $a = explode("\n", (string)$old);
    $b = explode("\n", (string)$cur);
    $s = 0;
    while ($s < count($a) && $s < count($b) && $a[$s] === $b[$s]) {
        $s++;
    }
    $e = 0;
    while ($e < count($a) - $s && $e < count($b) - $s
        && $a[count($a) - 1 - $e] === $b[count($b) - 1 - $e]) {
        $e++;
    }
    $ca = array_slice($a, $s, count($a) - $s - $e);
    $cb = array_slice($b, $s, count($b) - $s - $e);
    if (count($ca) > $max || count($cb) > $max) {
        return array('too_big' => true, 'lines' => array());
    }
    $n = count($ca);
    $m = count($cb);
    $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $dp[$i][$j] = $ca[$i] === $cb[$j]
                ? $dp[$i + 1][$j + 1] + 1
                : ($dp[$i + 1][$j] >= $dp[$i][$j + 1] ? $dp[$i + 1][$j] : $dp[$i][$j + 1]);
        }
    }
    $out = array();
    for ($k = 0; $k < $s; $k++) {
        $out[] = array('t' => ' ', 's' => $a[$k]);
    }
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($ca[$i] === $cb[$j]) {
            $out[] = array('t' => ' ', 's' => $ca[$i]);
            $i++;
            $j++;
        } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
            $out[] = array('t' => '-', 's' => $ca[$i]);
            $i++;
        } else {
            $out[] = array('t' => '+', 's' => $cb[$j]);
            $j++;
        }
    }
    while ($i < $n) {
        $out[] = array('t' => '-', 's' => $ca[$i]);
        $i++;
    }
    while ($j < $m) {
        $out[] = array('t' => '+', 's' => $cb[$j]);
        $j++;
    }
    for ($k = count($a) - $e; $k < count($a); $k++) {
        $out[] = array('t' => ' ', 's' => $a[$k]);
    }
    if (count($out) > $max * 2) {
        return array('too_big' => true, 'lines' => array());
    }
    return array('too_big' => false, 'lines' => $out);
}

/**
 * 修订历史页：列表 / 预览 / 与当前对比 / 恢复 / 清空。
 * 恢复前先把当前内容归档一版——恢复本身可回退，不设「不可逆」操作（除清空）。
 */
function md_admin_history($cfg)
{
    md_admin_guard();
    md_csrf_guard();

    $rel = isset($_GET['f']) ? (string)$_GET['f'] : '';
    $rel = str_replace('\\', '/', trim($rel, '/'));
    $abs = $rel === '' ? false : md_resolve($rel, $cfg, 'md');
    if ($abs === false) {
        md_admin_page(md_t('admin_hist_title'),
            '<div class="alert alert-danger">' . md_e(md_t('admin_err_noent_path')) . '</div>'
            . '<p><a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('docs')) . '">'
            . md_e(md_t('admin_back_docs')) . '</a></p>', 'docs');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $hdo = isset($_POST['hist_do']) ? (string)$_POST['hist_do'] : '';
        if ($hdo === 'restore') {
            $content = md_history_read($rel, isset($_POST['v']) ? (string)$_POST['v'] : '');
            $err = '';
            if ($content === false) {
                $err = md_t('admin_hist_err_version');
            } else {
                $cur = md_read_text($abs);
                if (is_string($cur) && $cur !== $content) {
                    md_history_archive($rel, $cur);      // 恢复可回退：当前内容先存一版
                }
                if (!md_write_file($abs, $content)) {
                    $err = md_t('admin_err_save_fail');
                }
            }
            if ($err !== '') {
                md_admin_page(md_t('admin_hist_title'),
                    '<div class="alert alert-danger">' . $err . '</div>'
                    . '<p><a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('history', array('f' => $rel))) . '">'
                    . md_e(md_t('admin_back')) . '</a></p>', 'docs');
            }
            md_tree_cache_clear();
            header('Location: ' . md_admin_url('history', array('f' => $rel, 'saved' => 'restored')));
            exit;
        }
        if ($hdo === 'clear') {
            md_history_clear($rel);
            header('Location: ' . md_admin_url('history', array('f' => $rel, 'saved' => 'cleared')));
            exit;
        }
    }

    $body = '<div class="admin-stack">';
    $saved = isset($_GET['saved']) ? (string)$_GET['saved'] : '';
    $flashMap = array('restored' => 'admin_hist_flash_restored', 'cleared' => 'admin_hist_flash_cleared');
    if (isset($flashMap[$saved])) {
        $body .= '<div class="alert alert-ok">' . md_e(md_t($flashMap[$saved])) . '</div>';
    }

    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_hist_title')) . '</h2></div>'
        . '<div class="admin-card-body">'
        . '<p class="form-hint">' . md_t('admin_hist_of', array('rel' => md_e($rel))) . '</p>'
        . '<p class="form-hint">' . md_t('admin_hist_hint') . '</p>';

    $versions = md_history_list($rel);
    $body .= count($versions) > 0
        ? '<p><form method="post" action="' . md_e(md_admin_url('history', array('f' => $rel))) . '" class="admin-inline-form"'
            . ' data-confirm="' . md_e(md_t('admin_hist_clear_confirm_js', array('n' => count($versions)))) . '">'
            . md_csrf_field()
            . '<input type="hidden" name="hist_do" value="clear">'
            . '<button class="btn btn-danger btn-sm" type="submit">' . md_admin_ico('trash')
            . md_e(md_t('admin_hist_clear')) . '</button></form></p>'
        : '<p class="form-hint">' . md_e(md_t('admin_hist_empty')) . '</p>';

    // 预览 / 对比视图（GET v= 指定版本；GET view=diff 走对比，否则预览）
    $v = isset($_GET['v']) ? (string)$_GET['v'] : '';
    $content = $v !== '' ? md_history_read($rel, $v) : false;
    if ($content !== false) {
        $isDiff = isset($_GET['view']) && $_GET['view'] === 'diff';
        if ($isDiff) {
            $diff = md_history_diff_lines($content, (string)md_read_text($abs));
            $body .= '<h3 class="admin-sub">' . md_e(md_t('admin_hist_diff_caption', array('time' => date('Y-m-d H:i', (int)$_GET['t'])))) . '</h3>';
            if ($diff['too_big']) {
                $body .= '<div class="alert alert-danger">' . md_e(md_t('admin_hist_diff_too_big')) . '</div>';
            } else {
                $body .= '<pre class="md-diff">';
                foreach ($diff['lines'] as $l) {
                    $cls = $l['t'] === '-' ? ' class="d"' : ($l['t'] === '+' ? ' class="a"' : '');
                    $body .= '<span' . $cls . '>' . md_e($l['t'] . ' ' . $l['s']) . '</span>' . "\n";
                }
                $body .= '</pre>';
            }
        } else {
            $show = strlen($content) > 200 * 1024 ? substr($content, 0, 200 * 1024) : $content;
            $body .= '<h3 class="admin-sub">' . md_e(md_t('admin_hist_preview_caption', array('time' => date('Y-m-d H:i', (int)$_GET['t'])))) . '</h3>'
                . '<pre class="md-diff">' . md_e($show) . '</pre>'
                . (strlen($content) > 200 * 1024 ? '<p class="form-hint">' . md_e(md_t('admin_hist_preview_trunc')) . '</p>' : '');
        }
    }

    if (count($versions) > 0) {
        $body .= '<table class="table admin-tbl"><thead><tr>'
            . '<th>' . md_e(md_t('admin_th_time')) . '</th><th>' . md_e(md_t('admin_th_size')) . '</th>'
            . '<th class="admin-col-act">' . md_e(md_t('admin_th_actions')) . '</th></tr></thead><tbody>';
        foreach ($versions as $row) {
            $time = date('Y-m-d H:i', $row['t']);
            $body .= '<tr><td>' . md_e($time) . '</td><td>' . md_e(md_format_size($row['s'])) . '</td>'
                . '<td class="admin-col-act"><span class="admin-row-act">'
                . '<a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('history', array('f' => $rel, 'v' => $row['f'], 't' => $row['t']))) . '">'
                . md_e(md_t('admin_hist_preview')) . '</a>'
                . '<a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('history', array('f' => $rel, 'v' => $row['f'], 't' => $row['t'], 'view' => 'diff'))) . '">'
                . md_e(md_t('admin_hist_diff')) . '</a>'
                . '<form method="post" action="' . md_e(md_admin_url('history', array('f' => $rel))) . '" class="admin-inline-form"'
                . ' data-confirm="' . md_e(md_t('admin_hist_restore_confirm_js', array('path' => $rel, 'time' => $time))) . '">'
                . md_csrf_field()
                . '<input type="hidden" name="hist_do" value="restore">'
                . '<input type="hidden" name="v" value="' . md_e($row['f']) . '">'
                . '<button class="btn btn-ghost btn-sm" type="submit">' . md_e(md_t('admin_hist_restore')) . '</button>'
                . '</form></span></td></tr>';
        }
        $body .= '</tbody></table>';
    }

    $body .= '</div></section>'
        . '<p><a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('docs')) . '">'
        . md_e(md_t('admin_back_docs')) . '</a></p>';
    md_admin_page(md_t('admin_hist_title'), $body, 'docs');
}

// ---------------------------------------------------------------- 资产通道（sys 内置资产 / 主题资产）

/**
 * 内置资产（_dir/assets/）：前后台共享样式/脚本 + 自托管三方库（v1.12.0 起）。
 * 文件名 => Content-Type 显式映射即白名单：加文件必须同步加 MIME，
 * 不存在任何「按后缀猜测」的路径，穿越/伪造在名单一步就 404。
 */
function md_send_sys_asset($f)
{
    static $allow = array(
        'builtin.css'                        => 'text/css; charset=utf-8',
        'builtin.js'                         => 'application/javascript; charset=utf-8',
        'theme-init.js'                      => 'application/javascript; charset=utf-8',
        'admin.css'                          => 'text/css; charset=utf-8',
        'admin.js'                           => 'application/javascript; charset=utf-8',
        'vendor/bootstrap.min.css'           => 'text/css; charset=utf-8',
        'vendor/bootstrap.bundle.min.js'     => 'application/javascript; charset=utf-8',
        'vendor/bootstrap-icons.min.css'     => 'text/css; charset=utf-8',
        'vendor/fonts/bootstrap-icons.woff2' => 'font/woff2',
        'vendor/fonts/bootstrap-icons.woff'  => 'font/woff',
        'vendor/mermaid.min.js'              => 'application/javascript; charset=utf-8',
        'vendor/qrcode.min.js'               => 'application/javascript; charset=utf-8',
    );
    if (!isset($allow[$f])) {
        md_abort(404);
    }
    $base = MD_DIR . '/assets';
    $abs = realpath($base . '/' . $f);
    $baseReal = realpath($base);
    if ($abs === false || $baseReal === false) {
        md_abort(404);
    }
    $abs = str_replace('\\', '/', $abs);
    $baseReal = str_replace('\\', '/', $baseReal);
    if (!md_starts_with($abs, $baseReal . '/')) {
        md_abort(404);
    }
    $size = @filesize($abs);
    $mtime = @filemtime($abs);
    $etag = '"' . md5($abs . '|' . $mtime . '|' . $size) . '"';

    header('X-Content-Type-Options: nosniff');
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=31536000');   // 内置资产可长缓存
    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
    if ($ifNoneMatch !== '' && strpos($ifNoneMatch, $etag) !== false) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $allow[$f]);
    header('Content-Length: ' . $size);
    readfile($abs);
    exit;
}

/** 主题/内置资产输出（t=_sys 走内置，其余只认 _dir/themes/<t>/assets/ 下的真实文件）。 */
function md_send_asset($cfg)
{
    $t = isset($_GET['t']) ? $_GET['t'] : '';
    $f = isset($_GET['f']) ? $_GET['f'] : '';

    // 程序内置资产（builtin.css / admin.css / admin.js）：t = _sys
    if ($t === '_sys') {
        md_send_sys_asset($f);
    }

    if (!preg_match('/^[a-z0-9_-]{1,32}$/', $t) || $f === '') {
        md_abort(404);
    }
    $f = str_replace('\\', '/', $f);
    foreach (explode('/', $f) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') {
            md_abort(404);
        }
    }
    $ext = md_ext($f);
    $allow = array('css', 'js', 'woff', 'woff2', 'ttf', 'otf', 'eot',
                   'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico');
    if (!in_array($ext, $allow, true)) {
        md_abort(404);
    }

    // 只允许 _dir/themes/<t>/assets/ 下的真实文件
    $base = MD_DIR . '/themes/' . $t . '/assets';
    $abs = realpath($base . '/' . $f);
    $baseReal = realpath($base);
    if ($abs === false || $baseReal === false || is_link($base . '/' . $f)) {
        md_abort(404);
    }
    $abs = str_replace('\\', '/', $abs);
    $baseReal = str_replace('\\', '/', $baseReal);
    if (!md_starts_with($abs, $baseReal . '/')) {
        md_abort(404);
    }
    $mimes = array(
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    );
    $mtime = @filemtime($abs);
    $size = @filesize($abs);
    $etag = '"' . md5($abs . '|' . $mtime . '|' . $size) . '"';

    md_security_headers();
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=86400');
    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
    if ($ifNoneMatch !== '' && strpos($ifNoneMatch, $etag) !== false) {
        http_response_code(304);
        exit;
    }
    if ($ext === 'svg' && isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'image') {
        // 与前台附件通道同一策略：<img> 上下文放行内联（图片态 SVG 脚本不执行 + CSP 双保险），
        // 其余上下文强制下载（堵诱导导航 XSS）；主题图标才能在 Markdown 里正常显示
        header("Content-Security-Policy: script-src 'none'; object-src 'none'");
        header('Content-Disposition: inline; filename="' . md_e(basename($abs)) . '"');
    } elseif ($ext === 'svg') {
        header('Content-Disposition: attachment; filename="' . md_e(basename($abs)) . '"');
    }
    header('Content-Type: ' . (isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream'));
    header('Content-Length: ' . $size);
    readfile($abs);
    exit;
}

// ---------------------------------------------------------------- 配置写入 / 路径与主题工具

/** 配置落盘：白名单合并 + 归一化 + 临时文件原子替换。写完即 md_config_reset()。 */
function md_config_set($patch)
{
    if (MD_READONLY) {
        return false;
    }
    $allow = array('site_title', 'site_desc', 'theme', 'mode', 'url_mode', 'home', 'language',
                   'ext_md', 'ext_attach', 'hidden_prefix', 'cache',
                   'show_attach', 'order_prefix', 'admin_pass', 'admin_user', 'admin_totp',
                   'base_path', 'site_url', 'risk_ignore',
                   'comments', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass',
                   'smtp_from', 'smtp_secure', 'notify_email', 'update_check');
    $cfg = md_config_load();
    foreach ($patch as $k => $v) {
        if (in_array($k, $allow, true)) {
            $cfg[$k] = $v;
        }
    }
    // 列表型配置归一化为小写非空字符串数组
    foreach (array('ext_md', 'ext_attach', 'hidden_prefix') as $k) {
        if (is_array($cfg[$k])) {
            $cfg[$k] = array_values(array_filter(array_map(function ($x) {
                return strtolower(trim((string)$x));
            }, $cfg[$k]), function ($x) {
                return $x !== '';
            }));
        }
    }
    // risk_ignore 归一化：反斜杠归一化、去空白与首尾斜杠；路径大小写敏感（不 lowercase）。
    // 原始行以 / 结尾 = 目录豁免，存储时保留一个尾斜杠作为语义标记；拒绝空段/./..；去重；上限 100 条
    if (!is_array($cfg['risk_ignore'])) {
        $cfg['risk_ignore'] = array();
    }
    $riSeen = array();
    $riClean = array();
    foreach ($cfg['risk_ignore'] as $riRaw) {
        $riLine = str_replace('\\', '/', trim((string)$riRaw));
        $riIsDir = (substr($riLine, -1) === '/');
        $riPath = trim($riLine, '/');
        if ($riPath === '' || $riPath === '.') {
            continue;
        }
        $riBad = false;
        foreach (explode('/', $riPath) as $riSeg) {
            if ($riSeg === '' || $riSeg === '.' || $riSeg === '..') {
                $riBad = true;
                break;
            }
        }
        // 去重键用最终存储值（含尾斜杠标记）：`目录`（文件）与 `目录/`（整目录）语义不同，
        // 不能互相顶掉——否则先写的文件条目会吞掉后写的目录豁免
        $riVal = $riIsDir ? $riPath . '/' : $riPath;
        if ($riBad || isset($riSeen[$riVal])) {
            continue;
        }
        $riSeen[$riVal] = true;
        $riClean[] = $riVal;
        if (count($riClean) >= 100) {
            break;
        }
    }
    $cfg['risk_ignore'] = $riClean;
    // base_path 只允许空或以 / 开头且不以 / 结尾（与 md_base_path_normalize 同一形态，
    // 写入侧就收口，坏值不落到 config.php）
    $cfg['base_path'] = md_base_path_normalize($cfg['base_path']);
    // site_url：空串合法；没写协议前缀的（docs.example.com）补 https://；去掉尾斜杠
    $cfg['site_url'] = trim((string)$cfg['site_url']);
    if ($cfg['site_url'] !== '' && !preg_match('#^https?://#i', $cfg['site_url'])) {
        $cfg['site_url'] = 'https://' . $cfg['site_url'];
    }
    $cfg['site_url'] = rtrim($cfg['site_url'], '/');
    if (!in_array($cfg['url_mode'], array('simple', 'pretty'), true)) {
        $cfg['url_mode'] = 'simple';
    }

    $body = "<?php\n// DirDocs 站点配置（由后台生成，可手工编辑）\nreturn "
        . var_export($cfg, true) . ";\n";
    $file = MD_DIR . '/config.php';
    $tmp = MD_DIR . '/config.tmp.php';
    if (!is_dir(MD_DIR)) {
        @mkdir(MD_DIR, 0755, true);
    }
    if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    @chmod($file, 0644);
    md_config_reset();                                  // 同请求内立即可见新配置
    return true;
}

/** 路径合法性（只校验形态，不要求文件存在）：拒绝空段/./.. 与 _dir。 */
function md_resolve_path_only($rel)
{
    $rel = str_replace('\\', '/', (string)$rel);
    if ($rel === '') {
        return false;
    }
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') {
            return false;
        }
    }
    if ($rel === MD_DIRNAME || md_starts_with($rel, MD_DIRNAME . '/')) {
        return false;
    }
    return $rel;
}

/** 可用主题清单（主题包：_dir/themes/<名称>/theme.php，或含 view.php 的整页模板覆盖）。 */
function md_available_themes()
{
    return array_keys(md_theme_list());
}

/** 登录账号合法性：1~64 字符，禁空白与路径/HTML 敏感字符。 */
function md_admin_user_valid($user)
{
    if (!is_string($user) || $user === '' || strlen($user) > 64) {
        return false;
    }
    return preg_match('/^[^\s\/\\\\<>"\']{1,64}$/u', $user) === 1;
}

// ---------------------------------------------------------------- UI 基件（图标 / URL / 布局）

/** 图标（Bootstrap Icons 字体，随全站统一 CDN 源加载；跟随 currentColor）。 */
function md_admin_ico($name)
{
    static $icons = array(
        'home'    => 'house-door',
        'doc'     => 'file-earmark-text',
        'chat'    => 'chat-square-text',
        'image'   => 'images',
        'db'      => 'database',
        'gear'    => 'gear',
        'trash'   => 'trash',
        'pencil'  => 'pencil',
        'plus'    => 'plus-lg',
        'folder'  => 'folder',
        'folder-plus' => 'folder-plus',
        'move'    => 'arrows-move',
        'copy'    => 'files',
        'history' => 'clock-history',
        'eye'     => 'eye',
        'search'  => 'search',
        'save'    => 'save',
        'list'    => 'list-ul',
        'close'   => 'x-lg',
        'ok'      => 'check2',
        'refresh' => 'arrow-clockwise',
        'github'  => 'github',
    );
    if (!isset($icons[$name])) {
        return '';
    }
    return '<i class="bi bi-' . $icons[$name] . ' ad-ico" aria-hidden="true"></i>';
}

/** 后台 URL：simple 模式 ?a=admin&do=…，pretty 模式 ?_a=admin&do=…。
 * 顺手收敛 base_path 自动检测在 Windows 下可能给出的反斜杠与重复斜杠。 */
function md_admin_url($do = '', $extra = array())
{
    $cfg = md_config_load();
    $u = md_url_for('admin', 'admin', $cfg);
    $u = str_replace('\\', '/', (string)$u);
    $u = preg_replace('#/{2,}#', '/', $u);
    if ($do !== '') {
        $u .= (strpos($u, '?') === false ? '?' : '&') . 'do=' . rawurlencode($do);
    }
    foreach ($extra as $k => $v) {
        $u .= (strpos($u, '?') === false ? '?' : '&') . rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    return $u;
}

/** 站点首页 URL（「查看站点 / 返回站点」用）：同上做一次反斜杠/重复斜杠收敛。 */
function md_admin_site_url($cfg)
{
    $u = md_url_for('', 'dir', $cfg);
    $u = str_replace('\\', '/', (string)$u);
    return preg_replace('#/{2,}#', '/', $u);
}

function md_admin_nav_items()
{
    return array(
        ''         => array('admin_nav_overview', 'home'),
        'docs'     => array('admin_nav_docs', 'doc'),
        'comments' => array('admin_nav_comments', 'chat'),
        'attach'   => array('admin_nav_attach', 'image'),
        'cache'    => array('admin_nav_cache', 'db'),
        'config'   => array('admin_nav_settings', 'gear'),
    );
}

function md_admin_nav_html($current)
{
    $html = '<nav class="admin-nav" aria-label="' . md_e(md_t('admin_nav_aria')) . '">';
    foreach (md_admin_nav_items() as $k => $it) {
        $on = ($k === $current) ? ' on' : '';
        $html .= '<a class="admin-nav-link' . $on . '" href="' . md_e(md_admin_url($k)) . '">'
            . md_admin_ico($it[1]) . '<span>' . md_e(md_t($it[0])) . '</span></a>';
    }
    $html .= '</nav>';
    return $html;
}

/**
 * 页面 <head>：防闪烁脚本（同步，早于样式表求值）+ Bootstrap CSS/图标字体
 * （自托管，经 md_cdn_active 取本地 vendor URL）+ builtin.css（共享组件）
 * + admin.css（后台布局）+ 主题变量内联块（调 md_theme_css，与前台逐字同值）。
 * 深浅色统一由 <html data-bs-theme> 驱动；后台服务端恒输出 light，
 * theme-init.js 再按 localStorage.dd-theme / 系统偏好落定。
 */
function md_admin_html_head($title, $cfg)
{
    md_security_headers();                              // 基础安全头（CSP 随后被本页策略覆盖）
    // 与前台同一套 CSP：v1.12.0 起全资源自托管，策略为纯 'self'（见 md_theme_csp()）
    header('Content-Security-Policy: ' . md_theme_csp($cfg));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');                  // 后台页面不进任何缓存
    header('X-Robots-Tag: noindex, nofollow');          // 后台整体排除出搜索引擎（含登录页）
    $v = rawurlencode(MD_VERSION);
    $cdn = md_cdn_active($cfg);
    return '<!DOCTYPE html><html lang="' . md_e(md_lang_current()) . '"' . md_i18n_js_attr() . ' data-bs-theme="light"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . md_e($title) . md_e(md_t('admin_title_suffix')) . '</title>'
        . '<script src="' . md_e(md_url_for('theme-init.js', 'sys', $cfg) . '&v=' . $v) . '"></script>'
        . '<link rel="stylesheet" href="' . md_e($cdn['css']) . '">'
        . '<link rel="stylesheet" href="' . md_e($cdn['icons']) . '">'
        . '<link rel="stylesheet" href="' . md_e(md_url_for('builtin.css', 'sys', $cfg) . '&v=' . $v) . '">'
        // 后台专属资源带程序版本号：升级后浏览器立刻拉新（内置资产通道是长缓存）
        . '<link rel="stylesheet" href="' . md_e(md_url_for('admin.css', 'sys', $cfg) . '&v=' . $v) . '">'
        . '<style>' . md_theme_css('default') . '</style>'
        . '</head>';
}

/** 页尾脚本：Bootstrap bundle（offcanvas 组件）+ 可选附加库 + 后台行为脚本，同为 defer 按文档序执行。
 * $extra 插在 admin.js 之前（如 TOTP 设置页的 qrcode 库），保证行为脚本执行时库已就位。 */
function md_admin_body_scripts($cfg, $extra = array())
{
    $cdn = md_cdn_active($cfg);
    $html = '<script src="' . md_e($cdn['js']) . '" defer></script>';
    foreach ($extra as $u) {
        $html .= '<script src="' . md_e((string)$u) . '" defer></script>';
    }
    return $html . '<script src="' . md_e(md_url_for('admin.js', 'sys', $cfg) . '&v=' . rawurlencode(MD_VERSION)) . '" defer></script>';
}

/** 深浅主题双态切换按钮（顶栏/登录页共用；行为在 admin.js，与前台 builtin.js 同机制）。 */
function md_admin_theme_btn()
{
    return '<button type="button" class="md-iconbtn" data-md-toggle="theme" title="' . md_e(md_t('theme_toggle')) . '" aria-label="' . md_e(md_t('theme_toggle')) . '">'
        . '<i class="bi bi-sun-fill md-ico-light" aria-hidden="true"></i>'
        . '<i class="bi bi-moon-stars-fill md-ico-dark" aria-hidden="true"></i></button>';
}

/** 登录 / 首设引导：冷灰底居中一张纸张卡，右上角主题切换，无导航。输出即终止。 */
function md_admin_auth_page($title, $inner)
{
    $cfg = md_config_load();
    echo md_admin_html_head($title, $cfg);
    echo '<body class="admin-body">'
        . '<div class="admin-auth-theme">' . md_admin_theme_btn() . '</div>'
        . '<div class="admin-auth"><div class="admin-auth-card">'
        . '<div class="admin-auth-head">'
        . '<p class="admin-auth-brand">DirDocs<span class="brand-version">' . md_e(MD_VERSION) . '</span></p>'
        . '<p class="admin-auth-sub">' . md_e(md_t('admin_brand')) . '</p>'
        . '</div>'
        . $inner
        . '</div>'
        . '<p class="admin-auth-foot"><a href="' . md_e(md_admin_site_url($cfg)) . '">' . md_e(md_t('admin_back_site')) . '</a></p>'
        . '</div>'
        . md_admin_body_scripts($cfg)
        . '</body></html>';
    exit;
}

/**
 * 后台页面骨架：56px 毛玻璃顶栏（品牌 + 站点/主题/登出）+ 左侧竖导航
 * （<992px 收进 Bootstrap offcanvas 抽屉，同一份导航 HTML 渲染两处）+ 内容纸张区。
 * 输出即终止。未登录（如 CSRF 中间页）退化为极简纸卡。
 */
function md_admin_page($title, $body, $navKey = '', $extraScripts = array())
{
    $cfg = md_config_load();
    echo md_admin_html_head($title, $cfg);
    if (!md_is_admin()) {
        echo '<body class="admin-body"><div class="admin-plain"><h1>' . md_e($title) . '</h1>'
            . $body . '</div>' . md_admin_body_scripts($cfg, $extraScripts) . '</body></html>';
        exit;
    }
    $nav = md_admin_nav_html($navKey);
    echo '<body class="admin-body">';
    echo '<a class="md-skip" href="#admin-content">' . md_e(md_t('skip_content')) . '</a>';
    echo '<header class="top-navbar admin-topbar d-flex align-items-center justify-content-between px-3 px-md-4">'
        . '<div class="d-flex align-items-center gap-2 md-topnav-left">'
        . '<button class="md-iconbtn d-lg-none" type="button" data-bs-toggle="offcanvas"'
        . ' data-bs-target="#adminMobileNav" aria-controls="adminMobileNav" aria-label="' . md_e(md_t('admin_open_nav')) . '" aria-expanded="false">'
        . '<i class="bi bi-list" aria-hidden="true"></i></button>'
        . '<a class="brand-text d-flex align-items-center gap-2" href="' . md_e(md_admin_url('')) . '">'
        . '<span class="md-brand-label">' . md_e($cfg['site_title']) . '</span>'
        . '<span class="admin-brand-tag">' . md_e(md_t('admin_brand')) . '</span>'
        . '<span class="brand-version">' . md_e(MD_VERSION) . '</span>'
        . '</a></div>'
        . '<div class="d-flex align-items-center gap-1">'
        . '<a class="md-iconbtn" href="' . md_e(md_admin_site_url($cfg)) . '" target="_blank" rel="noopener"'
        . ' title="' . md_e(md_t('admin_view_site')) . '" aria-label="' . md_e(md_t('admin_view_site')) . '"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>'
        . md_admin_theme_btn()
        . '<a class="md-iconbtn" href="' . md_e(md_admin_url('logout')) . '" title="' . md_e(md_t('admin_logout')) . '" aria-label="' . md_e(md_t('admin_logout')) . '">'
        . '<i class="bi bi-box-arrow-right" aria-hidden="true"></i></a>'
        . '</div></header>';
    // 移动端导航抽屉：开合交给 Bootstrap offcanvas，无需 admin.js 参与
    echo '<div class="offcanvas offcanvas-start md-offcanvas" tabindex="-1" id="adminMobileNav" aria-label="' . md_e(md_t('admin_nav_aria')) . '">'
        . '<div class="offcanvas-header"><h6 class="offcanvas-title sidebar-section-title">' . md_e(md_t('admin_nav_aria')) . '</h6>'
        . '<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="' . md_e(md_t('close_aria')) . '"></button></div>'
        . '<div class="offcanvas-body pt-0">' . $nav . '</div></div>';
    echo '<div class="admin-shell d-flex align-items-start">'
        . '<aside class="admin-side d-none d-lg-block">' . $nav . '</aside>'
        . '<main class="admin-main flex-grow-1 d-flex justify-content-center px-2 px-md-3" id="admin-content">'
        . '<div class="admin-stage">'
        . '<header class="admin-head"><h1>' . md_e($title) . '</h1></header>'
        . $body
        . '<footer class="admin-foot">DirDocs ' . md_e(MD_VERSION) . ' · PHP ' . md_e(PHP_VERSION)
        . ' · <a class="admin-ext" href="' . md_e(md_project_url()) . '" target="_blank" rel="noopener"'
        . ' title="' . md_e(md_t('powered_by_title')) . '">' . md_e(md_t('admin_foot_project')) . '</a></footer>'
        . '</div></main></div>';
    echo md_admin_body_scripts($cfg, $extraScripts);
    echo '</body></html>';
    exit;
}

// ---------------------------------------------------------------- 登录 / 登出 / 首次引导

/** 登录页（账号 + 密码）。$error 非空时先渲染警示框。 */
function md_admin_login_page($error = '')
{
    md_session_start();
    $body = '';
    if ($error !== '') {
        $body .= '<div class="alert alert-danger" role="alert">' . md_e($error) . '</div>';
    }
    $body .= '<form method="post" action="' . md_e(md_admin_url('login')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="do" value="login">'
        . '<div class="field"><label for="ad-user">' . md_e(md_t('admin_user_label')) . '</label>'
        . '<input class="input" type="text" id="ad-user" name="user" autocomplete="username" autofocus required></div>'
        . '<div class="field"><label for="ad-pass">' . md_e(md_t('admin_pw_label')) . '</label>'
        . '<input class="input" type="password" id="ad-pass" name="pw" autocomplete="current-password" required></div>'
        . '<button class="btn btn-primary admin-auth-submit" type="submit">' . md_e(md_t('admin_login_btn')) . '</button>'
        . '</form>';
    md_admin_auth_page(md_t('admin_login_title'), $body);
}

/** 登录提交：CSRF → IP 锁 → 账号（大小写不敏感）+ 密码 → 重置会话。 */
function md_admin_login_submit($cfg)
{
    $token = isset($_POST['_token']) ? (string)$_POST['_token'] : '';
    if ($token === '' || !isset($_SESSION['md_csrf']) || !hash_equals($_SESSION['md_csrf'], $token)) {
        md_admin_login_page(md_t('admin_err_token'));
    }
    $lockMin = md_login_lock_minutes();
    if ($lockMin > 0) {
        md_admin_login_page(md_t('admin_err_locked_wait', array('n' => $lockMin)));
    }
    $user = isset($_POST['user']) ? (string)$_POST['user'] : '';
    $pw = isset($_POST['pw']) ? (string)$_POST['pw'] : '';
    $userOk = hash_equals(strtolower((string)$cfg['admin_user']), strtolower($user));
    // 账号与密码任一不对都报同一条错误，不提示具体哪个错
    if (!$userOk || !password_verify($pw, (string)$cfg['admin_pass'])) {
        $left = md_login_fail();
        if ($left <= 0) {
            md_admin_login_page(md_t('admin_err_locked'));
        }
        md_admin_login_page(md_t('admin_err_creds', array('n' => $left)));
    }
    if ((string)$cfg['admin_totp'] !== '') {
        // 第一因子通过：会话记 pending 态进第二页，此时 md_is_admin() 仍为 false，
        // 后台一切页面/POST 依旧不可达（guard 在前），拿不到任何未授权资源；
        // 顺手换会话 ID——两步边界各换一次，会话固定无处挂靠
        session_regenerate_id(true);
        $_SESSION['md_totp_pending'] = 1;
        header('Location: ' . md_admin_url('totp'));
        exit;
    }
    md_login_ok();
    header('Location: ' . md_admin_url(''));
    exit;
}

/**
 * TOTP 登录第二步页（?a=admin&do=totp）：只对 pending 会话可达，
 * 其余一律弹回登录页（不泄露"该站是否启用了两步验证"之外的信息）。
 */
function md_admin_totp_page($error = '')
{
    md_session_start();
    if (empty($_SESSION['md_totp_pending'])) {
        header('Location: ' . md_admin_url(''));
        exit;
    }
    $body = '';
    if ($error !== '') {
        $body .= '<div class="alert alert-danger" role="alert">' . md_e($error) . '</div>';
    }
    $body .= '<form method="post" action="' . md_e(md_admin_url('totp')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="do" value="totp">'
        . '<div class="field"><label for="ad-totp">' . md_e(md_t('admin_totp_code_label')) . '</label>'
        . '<input class="input admin-totp-input" type="text" id="ad-totp" name="code"'
        . ' inputmode="numeric" autocomplete="one-time-code" maxlength="12" autofocus required></div>'
        . '<p class="form-hint">' . md_e(md_t('admin_totp_login_hint')) . '</p>'
        . '<button class="btn btn-primary admin-auth-submit" type="submit">' . md_e(md_t('admin_totp_login_btn')) . '</button>'
        . '</form>'
        . '<p class="admin-auth-alt"><a href="' . md_e(md_admin_url('logout')) . '">' . md_e(md_t('admin_totp_back_login')) . '</a></p>';
    md_admin_auth_page(md_t('admin_totp_login_title'), $body);
}

/** TOTP 第二步提交：CSRF → IP 锁 → 动态码（±1 周期 + 防重放）→ 重置会话进后台。 */
function md_admin_totp_submit($cfg)
{
    md_session_start();
    $token = isset($_POST['_token']) ? (string)$_POST['_token'] : '';
    if ($token === '' || !isset($_SESSION['md_csrf']) || !hash_equals($_SESSION['md_csrf'], $token)) {
        md_admin_totp_page(md_t('admin_err_token'));
    }
    if (empty($_SESSION['md_totp_pending'])) {
        header('Location: ' . md_admin_url(''));
        exit;
    }
    if ((string)$cfg['admin_totp'] === '') {
        // 启用期间被（文件权限者）复位：清中间态回登录页，不死循环
        unset($_SESSION['md_totp_pending']);
        header('Location: ' . md_admin_url(''));
        exit;
    }
    $lockMin = md_login_lock_minutes();
    if ($lockMin > 0) {
        md_admin_totp_page(md_t('admin_err_locked_wait', array('n' => $lockMin)));
    }
    $code = isset($_POST['code']) ? (string)$_POST['code'] : '';
    if (!md_totp_verify((string)$cfg['admin_totp'], $code)) {
        $left = md_login_fail();                        // 与密码共用同一失败桶
        if ($left <= 0) {
            md_admin_totp_page(md_t('admin_err_locked'));
        }
        md_admin_totp_page(md_t('admin_totp_err_creds', array('n' => $left)));
    }
    md_login_ok();
    header('Location: ' . md_admin_url(''));
    exit;
}

/** 首次使用引导：admin_pass 为空时这是后台唯一的页面。设置「账号 + 新密码 + 再输一次」。 */
function md_admin_setup($cfg)
{
    md_session_start();
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do']) && $_POST['do'] === 'setup') {
        md_csrf_guard();                                // 只校验 CSRF，不要求已登录
        $user = isset($_POST['user']) ? trim((string)$_POST['user']) : '';
        $pw = isset($_POST['pw']) ? (string)$_POST['pw'] : '';
        $pw2 = isset($_POST['pw2']) ? (string)$_POST['pw2'] : '';
        if ($user === '') {
            $user = 'admin';                            // 账号留空用默认
        }
        if (MD_READONLY) {
            $error = md_t('admin_err_readonly_setup');
        } elseif (!md_admin_user_valid($user)) {
            $error = md_t('admin_err_user_format');
        } elseif (strlen($pw) < 8) {
            $error = md_t('admin_err_pw_short');
        } elseif ($pw !== $pw2) {
            $error = md_t('admin_err_pw_mismatch');
        } elseif (md_config_set(array(
            'admin_user' => $user,
            'admin_pass' => password_hash($pw, PASSWORD_DEFAULT),
        ))) {
            md_login_ok();
            header('Location: ' . md_admin_url(''));
            exit;
        } else {
            $error = md_t('admin_err_config_write');
        }
    }
    $body = '';
    if ($error !== '') {
        $body .= '<div class="alert alert-danger" role="alert">' . md_e($error) . '</div>';
    } else {
        $body .= '<div class="alert alert-warn" role="alert">' . md_e(md_t('admin_setup_intro')) . '</div>';
    }
    $body .= '<form method="post" action="' . md_e(md_admin_url('')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="do" value="setup">'
        . '<div class="field"><label for="su-user">' . md_e(md_t('admin_user_label')) . '</label>'
        . '<input class="input" type="text" id="su-user" name="user" value="'
        . md_e(isset($_POST['user']) ? (string)$_POST['user'] : '') . '" placeholder="admin" autocomplete="username"></div>'
        . '<p class="form-hint">' . md_e(md_t('admin_setup_user_hint')) . '</p>'
        . '<div class="field"><label for="su-pw">' . md_e(md_t('admin_setup_pw_label')) . '</label>'
        . '<input class="input" type="password" id="su-pw" name="pw" minlength="8" required autocomplete="new-password"></div>'
        . '<div class="field"><label for="su-pw2">' . md_e(md_t('admin_setup_pw2_label')) . '</label>'
        . '<input class="input" type="password" id="su-pw2" name="pw2" minlength="8" required autocomplete="new-password"></div>'
        . '<button class="btn btn-primary admin-auth-submit" type="submit">' . md_e(md_t('admin_setup_submit')) . '</button>'
        . '</form>';
    md_admin_auth_page(md_t('admin_setup_title'), $body);
}

// ---------------------------------------------------------------- 概览

function md_admin_home($tree, $cfg)
{
    md_admin_guard();
    md_csrf_guard();

    $body = '<div class="admin-stack">';

    // 安全：风险文件检测（本程序的核心安全设计——唯一执行面是 index.php）
    $risk = md_exec_risk_files(MD_ROOT, $cfg);
    if ($risk) {
        $body .= md_risk_html($risk, true);
    } else {
        $body .= '<div class="alert alert-ok">' . md_e(md_t('admin_risk_ok')) . '</div>';
    }

    // 环境：mermaid 图表库缺失（slim 安装删掉省 3.2MB）时提示后果，需要时按 vendor/README.md 恢复
    if (!is_file(MD_DIR . '/assets/vendor/mermaid.min.js')) {
        $body .= '<div class="alert alert-warn">' . md_e(md_t('admin_hard_mermaid_missing')) . '</div>';
    }

    // 站点信息
    $cacheState = md_t('admin_cache_off');
    if (MD_READONLY) {
        $cacheState = md_t('admin_cache_readonly');
    } elseif (!empty($cfg['cache'])) {
        $c = md_tree_cache_read($cfg);
        $cacheState = is_array($c)
            ? md_t('admin_cache_ok', array('time' => date('Y-m-d H:i', (int)$c['built'])))
            : md_t('admin_cache_stale');
    }
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_site_info')) . '</h2></div>'
        . '<div class="admin-wrap"><table class="table admin-kv"><tbody>'
        . '<tr><th>' . md_e(md_t('admin_kv_site_title')) . '</th><td>' . md_e($cfg['site_title']) . '</td></tr>'
        . '<tr><th>' . md_e(md_t('admin_kv_docs')) . '</th><td>' . md_e(md_t('unit_docs', array('n' => count($tree['md'])))) . '</td></tr>'
        . '<tr><th>' . md_e(md_t('admin_kv_attach')) . '</th><td>' . md_e(md_t('unit_attach', array('n' => count($tree['attach'])))) . '</td></tr>'
        . '<tr><th>' . md_e(md_t('admin_kv_dirs')) . '</th><td>' . md_e(md_t('unit_dirs', array('n' => count($tree['dir'])))) . '</td></tr>'
        . '<tr><th>' . md_e(md_t('admin_kv_url_mode')) . '</th><td><code>' . md_e($cfg['url_mode']) . '</code></td></tr>'
        . '<tr><th>' . md_e(md_t('admin_kv_theme')) . '</th><td><code>' . md_e($cfg['theme']) . '</code></td></tr>'
        . '<tr><th>' . md_e(md_t('admin_kv_cache')) . '</th><td>' . $cacheState . '</td></tr>'
        . '<tr><th>' . md_e(md_t('admin_kv_php')) . '</th><td>' . md_e(PHP_VERSION) . '</td></tr>'
        // 徽标只在开关开启时出现：关掉检查后不该继续拿旧结论提示「有新版本」
        // （上次结果仍留在设置卡里，并标为已过期，供站长复查）
        . '<tr><th>' . md_e(md_t('admin_kv_version')) . '</th><td>' . md_e(MD_VERSION)
        . (!empty($cfg['update_check']) ? md_update_badge(md_update_auto($cfg)) : '') . '</td></tr>'
        . '<tr><th>' . md_e(md_t('admin_kv_writable')) . '</th><td>' . md_e($cfg['read_only']
            ? md_t('admin_writable_no')
            : md_t('admin_writable_yes')) . '</td></tr>'
        // 项目主页：装机信息面板里给站长一条通往仓库的明路（与页脚同一个地址出处）
        . '<tr><th>' . md_e(md_t('admin_kv_project')) . '</th><td>'
        . '<a class="admin-ext" href="' . md_e(md_project_url()) . '" target="_blank" rel="noopener">'
        . md_admin_ico('github') . md_e(md_project_repo()) . '</a></td></tr>'
        . '</tbody></table></div></section>';

    // 最近更新 10 条
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('recent_title')) . '</h2></div>';
    if (empty($tree['md'])) {
        $body .= '<p class="admin-empty">' . md_e(md_t('admin_recent_empty')) . '</p>';
    } else {
        $items = $tree['md'];
        uasort($items, function ($a, $b) {
            return (int)$b['mtime'] - (int)$a['mtime'];
        });
        $body .= '<div class="admin-wrap"><table class="table admin-recent"><thead><tr>'
            . '<th>' . md_e(md_t('admin_th_path')) . '</th><th>' . md_e(md_t('admin_th_mtime')) . '</th><th class="admin-col-act">' . md_e(md_t('admin_th_actions')) . '</th>'
            . '</tr></thead><tbody>';
        $n = 0;
        foreach ($items as $rel => $info) {
            $mtime = isset($info['mtime']) ? (int)$info['mtime'] : 0;
            $view = md_url_for(md_strip_doc_ext($rel, $cfg), 'doc', $cfg);
            $body .= '<tr><td><span class="admin-path">' . md_e($rel) . '</span></td>'
                . '<td class="admin-dim admin-nowrap">' . ($mtime > 0 ? md_e(date('Y-m-d H:i', $mtime)) : '—') . '</td>'
                . '<td class="admin-col-act"><span class="admin-actions">'
                . '<a class="btn btn-ghost btn-sm" href="' . md_e($view) . '" target="_blank" rel="noopener">' . md_e(md_t('admin_view')) . '</a>'
                . '<a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('edit', array('f' => $rel))) . '">'
                . md_admin_ico('pencil') . md_e(md_t('admin_edit')) . '</a></span></td></tr>';
            $n++;
            if ($n >= 10) {
                break;
            }
        }
        $body .= '</tbody></table></div>';
    }
    $body .= '</section></div>';
    md_admin_page(md_t('admin_nav_overview'), $body, '');
}

// ---------------------------------------------------------------- 文档管理（列表·树）

/**
 * 文档管理页：树形目录 + 文档列表（目录即分类）。
 * 数据全部来自 $tree（md_scan 已按前台同款排序产出），标题取 md_scan_head_meta
 * 采集的 front-matter title / 首个 H1；目录可折叠，默认只展开含文档的分支。
 * 操作：编辑 / 重命名·移动 / 复制 / 删除（POST + data-confirm）/ 查看前台 /
 *       在此目录新建文档。顶部工具条：前端实时过滤 + 新建文档 + 新建目录。
 */
function md_admin_docs($tree, $cfg)
{
    md_admin_guard();
    md_csrf_guard();

    $ro = MD_READONLY;
    $body = '<div class="admin-stack">';

    // 操作回执（只认固定键，不回显用户输入）
    $saved = isset($_GET['saved']) ? (string)$_GET['saved'] : '';
    $err = isset($_GET['err']) ? (string)$_GET['err'] : '';
    $okMap = array(
        'renamed'  => 'admin_ok_renamed',
        'deleted'  => 'admin_ok_deleted',
        'copied'   => 'admin_ok_copied',
        'mkdirent' => 'admin_ok_mkdirent',
        'rn_dir'   => 'admin_ok_rn_dir',
        'rm_dir'   => 'admin_ok_rm_dir',
    );
    $errMap = array(
        'badpath'   => 'admin_err_badpath',
        'noent'     => 'admin_err_noent_doc',
        'protected' => 'admin_err_protected',
        'failed'    => 'admin_err_failed',
        'direxists' => 'admin_err_direxists',
        'dirnotempty' => 'admin_err_dirnotempty',
    );
    if (isset($okMap[$saved])) {
        $body .= '<div class="alert alert-ok">' . md_e(md_t($okMap[$saved])) . '</div>';
    }
    if (isset($errMap[$err])) {
        $body .= '<div class="alert alert-danger">' . md_e(md_t($errMap[$err])) . '</div>';
    }
    if ($ro) {
        $body .= '<div class="alert alert-warn">' . md_e(md_t('admin_readonly_warn')) . '</div>';
    }

    $mdCount = isset($tree['md']) ? count($tree['md']) : 0;
    $dirCount = isset($tree['dir']) ? count($tree['dir']) : 0;

    // ---- 顶部工具条 ----
    $body .= '<div class="admin-toolbar">'
        . '<div class="admin-toolbar-search">'
        . md_admin_ico('search')
        . '<input class="input" type="search" id="docFilter" placeholder="' . md_e(md_t('admin_filter_ph')) . '"'
        . ' aria-label="' . md_e(md_t('admin_filter_aria')) . '" autocomplete="off">'
        . '</div>'
        . '<div class="admin-toolbar-acts">'
        . '<a class="btn btn-primary btn-sm" href="' . md_e(md_admin_url('new')) . '"'
        . ($ro ? ' aria-disabled="true"' : '') . '>' . md_admin_ico('plus') . md_e(md_t('admin_new_doc')) . '</a>'
        . '<a class="btn btn-sm" href="' . md_e(md_admin_url('dirnew')) . '"'
        . ($ro ? ' aria-disabled="true"' : '') . '>' . md_admin_ico('folder-plus') . md_e(md_t('admin_new_dir')) . '</a>'
        . '</div></div>';

    // ---- 树形列表 ----
    $body .= '<section class="card"><div class="admin-card-head"><h2>'
        . md_e(md_t('admin_docs_count', array('docs' => $mdCount, 'dirs' => $dirCount))) . '</h2></div>';
    if ($mdCount === 0 && $dirCount === 0) {
        $body .= '<div class="admin-card-body"><p class="admin-empty">' . md_e(md_t('admin_docs_empty_title')) . '</p>'
            . '<p>' . md_e(md_t('admin_docs_empty_body')) . '</p></div>';
    } else {
        $body .= '<div class="admin-card-body admin-card-body-tight">'
            . '<div class="admin-tree" id="docTree">'
            . md_admin_docs_level($tree, $cfg, '', 0)
            . '</div>'
            . '<p class="admin-tree-empty" id="docTreeEmpty" hidden>' . md_e(md_t('admin_filter_empty')) . '</p>'
            . '</div>';
    }
    $body .= '<div class="admin-card-foot"><p class="form-hint admin-hint-flat">'
        . md_e(md_t('admin_docs_hint'))
        . '</p></div>'
        . '</section></div>';
    md_admin_page(md_t('admin_docs_title'), $body, 'docs');
}

/**
 * 树形列表的一层：目录（可折叠，默认展开）在前、文档在后，顺序沿用前台
 * md_children 的「数字序 + 名称」排序。$depth 只用于缩进计算。
 */
function md_admin_docs_level($tree, $cfg, $dirRel, $depth)
{
    $children = md_children($dirRel, $tree, true);      // 后台可见草稿（打标记，v1.15.0）
    if (!$children['dir'] && !$children['md']) {
        return '';
    }
    $ro = MD_READONLY;
    $html = '';
    foreach ($children['dir'] as $d) {
        $rel = $d['rel'];
        $sub = md_children($rel, $tree, true);
        $hasChild = (bool)($sub['dir'] || $sub['md']);
        $label = md_display_name($d['name'], $cfg);
        $mtime = isset($d['mtime']) ? (int)$d['mtime'] : 0;
        $html .= '<details class="admin-tree-dir" open data-dir="' . md_e($rel) . '">'
            . '<summary class="admin-tree-row admin-tree-dirrow">'
            . '<span class="admin-tree-caret"><i class="bi bi-chevron-right" aria-hidden="true"></i></span>'
            . md_admin_ico('folder')
            . '<span class="admin-tree-name">' . md_e($label) . '</span>'
            . '<span class="admin-tree-meta">'
            . '<span class="admin-tree-path">' . md_e($rel) . '/</span>'
            . ($mtime > 0 ? '<span class="admin-dim admin-tree-time">' . md_e(date('Y-m-d H:i', $mtime)) . '</span>' : '')
            . '<span class="admin-actions admin-tree-acts">'
            . '<a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('new', array('dir' => $rel))) . '"'
            . ' title="' . md_e(md_t('admin_tip_new_here')) . '">' . md_admin_ico('plus') . md_e(md_t('admin_new')) . '</a>'
            . '<a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('dirrename', array('f' => $rel))) . '"'
            . ' title="' . md_e(md_t('admin_tip_dir_rename')) . '">' . md_admin_ico('move') . md_e(md_t('admin_rename')) . '</a>'
            . '<a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('dirdel', array('f' => $rel))) . '"'
            . ' title="' . md_e(md_t('admin_tip_dir_del')) . '">' . md_admin_ico('trash') . md_e(md_t('admin_delete')) . '</a>'
            . '</span></span></summary>';
        if ($hasChild) {
            $html .= '<div class="admin-tree-sub">' . md_admin_docs_level($tree, $cfg, $rel, $depth + 1) . '</div>';
        }
        $html .= '</details>';
    }
    foreach ($children['md'] as $m) {
        $rel = $m['rel'];
        $title = isset($m['title']) ? (string)$m['title'] : '';
        if ($title === '') {
            $title = md_display_name(basename($rel, '.' . md_ext($rel)), $cfg);
        }
        $mtime = isset($m['mtime']) ? (int)$m['mtime'] : 0;
        $view = md_url_for(md_strip_doc_ext($rel, $cfg), 'doc', $cfg);
        $isIndex = in_array(basename($rel, '.' . md_ext($rel)), array('README', 'readme', 'index', 'home', '首页'), true);
        $isNew = ($mtime > 0 && (time() - $mtime) <= 604800);
        $isDraft = !empty($m['draft']);
        // 标题包一层链接（前台查看）：桌面是行内「查看」按钮，窄屏该按钮隐藏，
        // 标题成为进前台的入口（JS 不可用时 <a> 本身就可点）。
        $html .= '<div class="admin-tree-row admin-tree-doc" data-search="' . md_e($rel . ' ' . $title) . '">'
            . '<span class="admin-tree-caret admin-tree-caret-off"></span>'
            . md_admin_ico('doc')
            . '<a class="admin-tree-name" href="' . md_e($view) . '" target="_blank" rel="noopener"'
            . ' title="' . md_e(md_t('admin_tip_view_doc', array('path' => $rel))) . '">' . md_e($title) . '</a>'
            . ($isIndex ? '<span class="admin-tree-tag">' . md_e(md_t('tree_tag_home')) . '</span>' : '')
            . ($isNew ? '<span class="admin-tree-tag admin-tree-tag-new">NEW</span>' : '')
            . ($isDraft ? '<span class="admin-tree-tag admin-tree-tag-draft">' . md_e(md_t('tree_tag_draft')) . '</span>' : '')
            . '<span class="admin-tree-meta">'
            . '<span class="admin-tree-path">' . md_e($rel) . '</span>'
            . ($mtime > 0 ? '<span class="admin-dim admin-tree-time">' . md_e(date('Y-m-d H:i', $mtime)) . '</span>' : '')
            . '<span class="admin-actions admin-tree-acts">'
            . '<a class="btn btn-ghost btn-sm admin-tree-view" href="' . md_e($view) . '" target="_blank" rel="noopener"'
            . ' title="' . md_e(md_t('admin_tip_view')) . '">' . md_admin_ico('eye') . md_e(md_t('admin_view')) . '</a>'
            . '<a class="btn btn-primary btn-sm" href="' . md_e(md_admin_url('edit', array('f' => $rel))) . '"'
            . ' title="' . md_e(md_t('admin_tip_edit')) . '">' . md_admin_ico('pencil') . md_e(md_t('admin_edit')) . '</a>'
            . '<a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('rename', array('f' => $rel))) . '"'
            . ' title="' . md_e(md_t('admin_tip_rename')) . '">' . md_admin_ico('move') . md_e(md_t('admin_rename')) . '</a>'
            . '<a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('copy', array('f' => $rel))) . '"'
            . ' title="' . md_e(md_t('admin_tip_copy')) . '">' . md_admin_ico('copy') . md_e(md_t('admin_copy')) . '</a>'
            . '<a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('history', array('f' => $rel))) . '"'
            . ' title="' . md_e(md_t('admin_tip_history')) . '">' . md_admin_ico('history') . md_e(md_t('admin_hist_title')) . '</a>'
            . '<form method="post" action="' . md_e(md_admin_url('delete')) . '" class="admin-inline-form"'
            . ' data-confirm="' . md_e(md_t('admin_confirm_delete', array('path' => $rel))) . '">'
            . md_csrf_field()
            . '<input type="hidden" name="f" value="' . md_e($rel) . '">'
            . '<button class="btn btn-danger btn-sm" type="submit">' . md_admin_ico('trash') . md_e(md_t('admin_delete')) . '</button>'
            . '</form></span></span></div>';
    }
    return $html;
}

/** 文档管理旧列表（已被树形列表取代，保留函数名供旧调用方兼容，内部转调树形渲染）。 */
function md_admin_docs_flat($tree, $cfg)
{
    md_admin_docs($tree, $cfg);
}

/**
 * 编辑器：标题 + 目录下拉 + 文件名 + textarea。
 * - 保存时把（目录,文件名）两字段归一化成完整相对路径 f，与旧 ?f= 直接提交全路径兼容；
 * - 标题非空 → 合并/生成 front-matter title（md_admin_title_save）；
 * - 提交带 ajax=1 时返回 JSON（供 admin.js 渐进增强），否则照旧 302 回跳（PRG 兜底）。
 */
function md_admin_doc_edit($cfg)
{
    md_admin_guard();
    md_csrf_guard();

    // 路径归一：POST 优先（表单提交），GET 兜底（旧书签/列表链接）
    $rel = '';
    if (isset($_POST['f'])) {
        $rel = (string)$_POST['f'];
    } elseif (isset($_GET['f'])) {
        $rel = (string)$_GET['f'];
    }
    $rel = str_replace('\\', '/', trim($rel, '/'));
    $ajax = (isset($_POST['ajax']) && $_POST['ajax'] === '1');
    $isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

    // 打开编辑器时的初始目录/文件名拆分（GET 场景；POST 失败重显时用提交值，见下）
    $dir0 = '';
    $base0 = '';
    if ($rel !== '') {
        $d = dirname($rel);
        $dir0 = ($d === '.' || $d === '/') ? '' : $d;
        $base0 = basename($rel);
    }
    // 「在此目录新建」入口：?dir=xxx
    if ($rel === '' && isset($_GET['dir'])) {
        $dir0 = str_replace('\\', '/', trim((string)$_GET['dir'], '/'));
        if (md_resolve_path_only($dir0) === false || ($dir0 !== '' && !is_dir(MD_ROOT . '/' . $dir0))) {
            $dir0 = '';
        }
    }

    if ($isPost) {
        $content = isset($_POST['content']) ? (string)$_POST['content'] : '';
        $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
        // 目录 + 文件名双字段优先；任一提交了就由它们拼出完整路径
        if (isset($_POST['dir'], $_POST['base'])) {
            $d = str_replace('\\', '/', trim((string)$_POST['dir'], '/'));
            $b = trim((string)$_POST['base']);
            if ($d !== '' || $b !== '') {
                $rel = ($d === '' ? '' : $d . '/') . ltrim($b, '/');
                $rel = str_replace('\\', '/', trim($rel, '/'));
            }
        }
        $content = md_admin_title_save($content, $title);

        $err = '';
        // front-matter 简单校验：有开头 --- 就必须有闭合 ---，否则整篇会被当成正文
        if ((md_starts_with($content, "---\n") || md_starts_with($content, "---\r\n"))
            && preg_match('/\A---\r?\n.*?\r?\n---\r?\n/s', $content) !== 1) {
            $err = md_t('admin_err_fm_unclosed');
        }
        if ($err === '') {
            $pathOk = md_resolve_path_only($rel);
            if ($pathOk === false) {
                $err = md_t('admin_err_path_bad');
            } elseif (!in_array(md_ext($rel), $cfg['ext_md'], true)) {
                $err = md_t('admin_err_ext', array('list' => implode(' / ', $cfg['ext_md'])));
            } else {
                $abs = md_resolve($rel, $cfg, 'md');
                if ($abs === false) {
                    // 已有文档解析失败 → 按新文件处理：父目录必须存在，文件名必须合法
                    $dirPart = dirname($rel);
                    $dirPart = ($dirPart === '.' || $dirPart === '/') ? '' : $dirPart;
                    $base = basename($rel);
                    $safe = md_safe_name($base);
                    if ($dirPart !== '' && !is_dir(MD_ROOT . '/' . $dirPart)) {
                        $err = md_t('admin_err_parent_missing');
                    } elseif ($safe === false || $safe !== $base) {
                        $err = md_t('admin_err_badname', array('name' => md_e($base)));
                    } else {
                        $abs = MD_ROOT . '/' . $rel;
                    }
                }
                if ($err === '') {
                    if (is_file($abs)) {
                        $histOld = md_read_text($abs);
                        if (is_string($histOld) && $histOld !== $content) {
                            md_history_archive($rel, $histOld);   // 覆盖保存前归档当前内容（v1.14.0）
                        }
                    }
                    if (md_write_file($abs, $content)) {
                        md_tree_cache_clear();
                        if ($ajax) {
                            md_admin_json(array('ok' => 1, 'msg' => md_t('admin_saved'), 'rel' => $rel,
                                'edit' => md_admin_url('edit', array('f' => $rel))));
                        }
                        header('Location: ' . md_admin_url('edit', array('f' => $rel, 'saved' => '1')));
                        exit;
                    }
                    $err = md_t('admin_err_save_fail');
                }
            }
        }
        // 保存失败：AJAX 走 JSON，普通表单重显表单（保留用户已填内容）
        if ($ajax) {
            md_admin_json(array('ok' => 0, 'msg' => trim(strip_tags($err))));
        }
        md_admin_page(md_t('admin_edit_title'),
            '<div class="alert alert-danger">' . $err . '</div>'
            . md_admin_editor_form($rel, $content, $title, $cfg),
            'docs');
    }

    // GET：打开编辑器
    $content = '';
    $title = '';
    $savedTip = '';
    if ($rel !== '') {
        $abs = md_resolve($rel, $cfg, 'md');
        if ($abs !== false) {
            $c = md_read_text($abs);
            if ($c === false) {
                md_admin_page(md_t('admin_edit_title'), '<div class="alert alert-danger">' . md_e(md_t('admin_err_read_fail')) . '</div>'
                    . '<p><a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('docs')) . '">' . md_e(md_t('admin_back_docs')) . '</a></p>',
                    'docs');
            }
            $content = $c;
            $fm = md_front_matter($c);
            $title = $fm['title'];
        } else {
            // 不存在：校验能否作为新文档路径
            $dirPart = dirname($rel);
            $dirPart = ($dirPart === '.' || $dirPart === '/') ? '' : $dirPart;
            if (md_resolve_path_only($rel) === false || !in_array(md_ext($rel), $cfg['ext_md'], true)
                || ($dirPart !== '' && !is_dir(MD_ROOT . '/' . $dirPart))) {
                md_admin_page(md_t('admin_edit_title'), '<div class="alert alert-danger">' . md_e(md_t('admin_err_noent_path')) . '</div>'
                    . '<p><a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('docs')) . '">' . md_e(md_t('admin_back_docs')) . '</a></p>',
                    'docs');
            }
        }
    }
    if (isset($_GET['saved'])) {
        $savedTip = '<div class="alert alert-ok">' . md_e(md_t('admin_saved_tip')) . '</div>';
    }
    md_admin_page($rel === '' ? md_t('admin_new_doc') : md_t('admin_edit_rel', array('rel' => $rel)),
        $savedTip . md_admin_editor_form($rel, $content, $title, $cfg, $dir0, $base0), 'docs');
}

/**
 * front-matter 合并：标题非空 → 写入/更新 title；留空 → 原样返回。
 * 无 front-matter 且标题非空时生成标准头（\n 结尾，保持与 md_front_matter 解析一致）。
 * 已有 front-matter 时逐行改写 title 行（没有则插到首行），其余键值原样保留。
 */
function md_admin_title_save($content, $title)
{
    $title = trim((string)$title);
    if ($title === '') {
        return $content;
    }
    // 标题里不让出现换行/冒号等会破坏 front-matter 的字符
    $title = str_replace(array("\r", "\n", ':'), array('', '', ' '), $title);
    $title = trim($title);
    if ($title === '') {
        return $content;
    }
    $hasFm = (md_starts_with($content, "---\n") || md_starts_with($content, "---\r\n"));
    if (!$hasFm) {
        return "---\ntitle: " . $title . "\n---\n" . $content;
    }
    if (preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n/s', $content, $m) !== 1) {
        return $content;                                  // front-matter 未闭合，交给保存校验去报错
    }
    $fmLines = preg_split('/\r?\n/', $m[1]);
    $out = array();
    $done = false;
    foreach ($fmLines as $line) {
        $pos = strpos($line, ':');
        if (!$done && $pos !== false && strtolower(trim(substr($line, 0, $pos))) === 'title') {
            $out[] = 'title: ' . $title;
            $done = true;
            continue;
        }
        $out[] = $line;
    }
    if (!$done) {
        array_unshift($out, 'title: ' . $title);
    }
    return "---\n" . implode("\n", $out) . "\n---\n" . substr($content, strlen($m[0]));
}

/** 全站目录 <option> 列表（含根目录），树形缩进；$sel 为当前选中目录相对路径。 */
function md_admin_dir_options($tree, $sel)
{
    $dirs = isset($tree['dir']) ? $tree['dir'] : array();
    $paths = array_keys($dirs);
    usort($paths, 'md_str_compare');
    $html = '<option value="">' . md_e(md_t('admin_root_dir')) . '</option>';
    foreach ($paths as $rel) {
        $depth = substr_count($rel, '/');
        $pad = $depth > 0 ? str_repeat('—', $depth) . ' ' : '';
        $selAttr = ($rel === $sel) ? ' selected' : '';
        $html .= '<option value="' . md_e($rel) . '"' . $selAttr . '>' . md_e($pad . $rel) . '</option>';
    }
    return $html;
}

/**
 * 编辑器表单：标题 + 目录下拉 + 文件名 + 路径预览 + 工具栏挂载点 + textarea。
 * $dir0/$base0 非空时预选目录/文件名（「在此目录新建」?dir= 入口）；
 * name="f" 隐藏域由 admin.js 双向同步写入（无 JS 时提交 dir+base 两字段，
 * 服务端同样能拼出路径，两条路都通）。
 */
function md_admin_editor_form($rel, $content, $title, $cfg, $dir0 = '', $base0 = '')
{
    $dirSel = '';
    $baseVal = '';
    if ($rel !== '') {
        $d = dirname($rel);
        $dirSel = ($d === '.' || $d === '/') ? '' : $d;
        $baseVal = basename($rel);
    } else {
        // 新建：外部传入的预选目录/文件名（?dir= 入口）优先
        if ($dir0 !== '') { $dirSel = $dir0; }
        if ($base0 !== '') { $baseVal = $base0; }
    }
    $tree = md_tree_load($cfg);
    $isNew = ($rel === '');
    $extList = implode(' / ', $cfg['ext_md']);
    // 路径预览：优先用已拼出的完整路径，其次目录+文件名
    $preview = $rel !== '' ? $rel : (($dirSel === '' ? '' : $dirSel . '/') . $baseVal);
    if ($preview === '') { $preview = md_t('js_preview_empty'); }
    return '<form method="post" action="' . md_e(md_admin_url('edit')) . '" class="admin-editor-form"'
        . ' id="docEditForm" data-editor-form="1">'
        . md_csrf_field()
        . '<input type="hidden" name="f" id="ed-f" value="' . md_e($rel) . '">'
        . '<section class="card admin-editor-card"><div class="admin-card-head"><h2>'
        . md_e($isNew ? md_t('admin_new_doc') : md_t('admin_edit')) . '</h2>'
        . '<span class="admin-editor-dirty" id="ed-dirty" hidden>' . md_e(md_t('admin_dirty')) . '</span></div>'
        . '<div class="admin-card-body">'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="ed-title">' . md_e(md_t('admin_title_label')) . '</label>'
        . '<input class="input" type="text" id="ed-title" name="title" value="' . md_e($title) . '"'
        . ' placeholder="' . md_e(md_t('admin_title_ph')) . '" maxlength="120">'
        . '<p class="form-hint">' . md_e(md_t('admin_title_hint')) . '</p></div>'
        . '<div class="field"><label for="ed-dir">' . md_e(md_t('admin_dir_label')) . '</label>'
        . '<select class="select" id="ed-dir" name="dir">' . md_admin_dir_options($tree, $dirSel) . '</select>'
        . '<p class="form-hint">' . md_e(md_t('admin_dir_hint')) . '</p></div>'
        . '<div class="field"><label for="ed-base">' . md_e(md_t('admin_filename_label')) . '</label>'
        . '<input class="input" type="text" id="ed-base" name="base" value="' . md_e($baseVal) . '"'
        . ' placeholder="' . md_e(md_t('admin_filename_ph')) . '" required></div>'
        . '</div>'
        . '<p class="form-hint admin-editor-prevwrap">' . md_e(md_t('admin_full_path')) . '<code class="admin-path" id="ed-preview">'
        . md_e($preview) . '</code>'
        . md_e(md_t('admin_ext_hint', array('list' => $extList))) . '</p>'
        . '</div>'
        // 工具栏（纯展示壳，行为在 admin.js；无 JS 时藏起来不影响 textarea 使用）
        . '<div class="admin-md-bar" id="edToolbar" hidden>'
        . '<button type="button" class="md-tb" data-md="bold" title="' . md_e(md_t('admin_tb_bold')) . '"><b>B</b></button>'
        . '<button type="button" class="md-tb" data-md="italic" title="' . md_e(md_t('admin_tb_italic')) . '"><i>I</i></button>'
        . '<button type="button" class="md-tb" data-md="strike" title="' . md_e(md_t('admin_tb_strike')) . '"><s>S</s></button>'
        . '<button type="button" class="md-tb" data-md="code" title="' . md_e(md_t('admin_tb_code')) . '">&lt;/&gt;</button>'
        . '<span class="md-tb-sep"></span>'
        . '<button type="button" class="md-tb" data-md="h1" title="' . md_e(md_t('admin_tb_h1')) . '">H1</button>'
        . '<button type="button" class="md-tb" data-md="h2" title="二级标题">H2</button>'
        . '<button type="button" class="md-tb" data-md="h3" title="' . md_e(md_t('admin_tb_h3')) . '">H3</button>'
        . '<span class="md-tb-sep"></span>'
        . '<button type="button" class="md-tb" data-md="ul" title="' . md_e(md_t('admin_tb_ul')) . '">• ' . md_e(md_t('admin_tb_list_t')) . '</button>'
        . '<button type="button" class="md-tb" data-md="ol" title="' . md_e(md_t('admin_tb_ol')) . '">1. ' . md_e(md_t('admin_tb_list_t')) . '</button>'
        . '<button type="button" class="md-tb" data-md="task" title="' . md_e(md_t('admin_tb_task')) . '">☑ ' . md_e(md_t('admin_tb_task_t')) . '</button>'
        . '<button type="button" class="md-tb" data-md="quote" title="' . md_e(md_t('admin_tb_quote')) . '">❝ ' . md_e(md_t('admin_tb_quote_t')) . '</button>'
        . '<span class="md-tb-sep"></span>'
        . '<button type="button" class="md-tb" data-md="link" title="' . md_e(md_t('admin_tb_link')) . '">' . md_e(md_t('admin_tb_link_t')) . '</button>'
        . '<button type="button" class="md-tb" data-md="image" title="' . md_e(md_t('admin_tb_image')) . '">' . md_e(md_t('admin_tb_image_t')) . '</button>'
        . '<button type="button" class="md-tb" data-md="table" title="' . md_e(md_t('admin_tb_table')) . '">' . md_e(md_t('admin_tb_table_t')) . '</button>'
        . '<button type="button" class="md-tb" data-md="codeblock" title="' . md_e(md_t('admin_tb_codeblock')) . '">{ }</button>'
        . '<button type="button" class="md-tb" data-md="hr" title="' . md_e(md_t('admin_tb_hr')) . '">―</button>'
        . '</div>'
        . '<textarea class="textarea admin-editor" name="content" id="ed-content" spellcheck="false"'
        . ' placeholder="' . md_e(md_t('admin_editor_ph')) . '">' . md_e($content) . '</textarea>'
        . '<div class="admin-md-status" id="edStatus" hidden>'
        . '<span id="edStat-words">' . md_e(md_t('js_stat_words', array('n' => 0))) . '</span><span id="edStat-lines">' . md_e(md_t('js_stat_lines', array('n' => 1))) . '</span>'
        . '<span id="edStat-save" class="admin-md-saveok"></span>'
        . '</div>'
        . '<div class="admin-actions-row admin-editor-actions">'
        . '<button class="btn btn-primary" type="submit" id="edSaveBtn">' . md_admin_ico('save') . md_e(md_t('admin_save')) . '</button>'
        . '<a class="btn btn-ghost" href="' . md_e(md_admin_url('docs')) . '">' . md_admin_ico('list') . md_e(md_t('admin_back_list')) . '</a>'
        . ($isNew
            ? '<a class="btn btn-ghost" href="' . md_e(md_admin_url('new')) . '">' . md_admin_ico('plus') . md_e(md_t('admin_new_another')) . '</a>'
            : '<a class="btn btn-ghost" href="' . md_e(md_url_for(md_strip_doc_ext($rel, $cfg), 'doc', $cfg))
                . '" target="_blank" rel="noopener">' . md_admin_ico('eye') . md_e(md_t('admin_view')) . '</a>')
        . '</div></section></form>';
}

/** 重命名·移动：GET 出表单，POST 执行。新路径用「目录 + 文件名」双字段提交（兼容旧 to 全路径）。 */
function md_admin_doc_rename($cfg)
{
    md_admin_guard();
    md_csrf_guard();

    $old = '';
    if (isset($_POST['f'])) {
        $old = (string)$_POST['f'];
    } elseif (isset($_GET['f'])) {
        $old = (string)$_GET['f'];
    }
    $old = str_replace('\\', '/', trim($old, '/'));
    if (md_resolve_path_only($old) === false) {
        header('Location: ' . md_admin_url('docs', array('err' => 'badpath')));
        exit;
    }
    $oldAbs = md_resolve($old, $cfg, 'md');
    if ($oldAbs === false) {
        header('Location: ' . md_admin_url('docs', array('err' => 'noent')));
        exit;
    }

    // 目标路径：优先 dir+base 双字段，其次 to 全路径（旧表单/书签）
    $toDir = dirname($old);
    $toDir = ($toDir === '.' || $toDir === '/') ? '' : $toDir;
    $toBase = basename($old);
    $err = '';
    $to = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['dir']) || isset($_POST['base'])) {
            $d = str_replace('\\', '/', trim(isset($_POST['dir']) ? (string)$_POST['dir'] : '', '/'));
            $b = trim(isset($_POST['base']) ? (string)$_POST['base'] : '');
            $to = ($d === '' ? '' : $d . '/') . ltrim($b, '/');
            $toDir = $d;
            $toBase = $b;
        } else {
            $to = isset($_POST['to']) ? str_replace('\\', '/', trim((string)$_POST['to'], '/')) : '';
            if ($to !== '') {
                $d = dirname($to);
                $toDir = ($d === '.' || $d === '/') ? '' : $d;
                $toBase = basename($to);
            }
        }
        $to = str_replace('\\', '/', trim($to, '/'));
        $toOk = md_resolve_path_only($to);
        if ($toOk === false || !in_array(md_ext($to), $cfg['ext_md'], true)) {
            $err = md_t('admin_err_newpath', array('list' => implode(' / ', $cfg['ext_md'])));
        } elseif (strcasecmp($to, $old) === 0) {
            header('Location: ' . md_admin_url('docs', array('saved' => 'renamed')));   // 原样，视作成功
            exit;
        } elseif (file_exists(MD_ROOT . '/' . $to)) {
            $err = md_t('admin_err_exists_path', array('path' => md_e($to)));
        } else {
            $parent = dirname($to);
            $parent = ($parent === '.' || $parent === '/') ? '' : $parent;
            $safe = md_safe_name(basename($to));
            if ($parent !== '' && !is_dir(MD_ROOT . '/' . $parent)) {
                $err = md_t('admin_err_parent_missing_dir', array('path' => md_e($parent)));
            } elseif ($safe === false || $safe !== basename($to)) {
                $err = md_t('admin_err_target_badname', array('name' => md_e(basename($to))));
            } elseif (@rename($oldAbs, MD_ROOT . '/' . $to)) {
                @rename(MD_ROOT . '/' . $old . '.cmt.php', MD_ROOT . '/' . $to . '.cmt.php');   // 评论文件跟移（v1.17.0）
                md_tree_cache_clear();
                header('Location: ' . md_admin_url('docs', array('saved' => 'renamed')));
                exit;
            } else {
                $err = md_t('admin_err_rename_fail');
            }
        }
    }

    $tree = md_tree_load($cfg);
    $body = '<div class="admin-stack">';
    if ($err !== '') {
        $body .= '<div class="alert alert-danger">' . $err . '</div>';
    }
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_rename_title')) . '</h2></div>'
        . '<div class="admin-card-body"><form method="post" action="' . md_e(md_admin_url('rename')) . '"'
        . ' class="admin-rename-form" data-rename-form="1">'
        . md_csrf_field()
        . '<input type="hidden" name="f" value="' . md_e($old) . '">'
        . '<div class="field"><label>' . md_e(md_t('admin_old_path')) . '</label>'
        . '<p class="admin-path admin-rename-old">' . md_e($old) . '</p></div>'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="rn-dir">' . md_e(md_t('admin_target_dir')) . '</label>'
        . '<select class="select" id="rn-dir" name="dir">' . md_admin_dir_options($tree, $toDir) . '</select></div>'
        . '<div class="field"><label for="rn-base">' . md_e(md_t('admin_filename_label')) . '</label>'
        . '<input class="input" type="text" id="rn-base" name="base" value="' . md_e($toBase) . '" required></div>'
        . '</div>'
        . '<p class="form-hint">' . md_e(md_t('admin_rename_hint')) . '</p>'
        . '<p class="form-hint">' . md_e(md_t('admin_new_path')) . '<code class="admin-path" id="rn-preview">' . md_e($old) . '</code></p>'
        . '<div class="admin-actions-row">'
        . '<button class="btn btn-primary" type="submit">' . md_e(md_t('admin_rename_submit')) . '</button>'
        . '<a class="btn btn-ghost" href="' . md_e(md_admin_url('docs')) . '">' . md_e(md_t('admin_back_list')) . '</a>'
        . '</div></form></div></section></div>';
    md_admin_page(md_t('admin_rename_page', array('rel' => $old)), $body, 'docs');
}

/** 复制文档：md_unique_name 防冲突，复制后跳编辑器（PRG）。 */
function md_admin_doc_copy($cfg)
{
    md_admin_guard();
    md_csrf_guard();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . md_admin_url('docs'));
        exit;
    }
    $rel = str_replace('\\', '/', trim(isset($_POST['f']) ? (string)$_POST['f'] : '', '/'));
    if (md_resolve_path_only($rel) === false) {
        header('Location: ' . md_admin_url('docs', array('err' => 'badpath')));
        exit;
    }
    $abs = md_resolve($rel, $cfg, 'md');
    if ($abs === false) {
        header('Location: ' . md_admin_url('docs', array('err' => 'noent')));
        exit;
    }
    $dirPart = dirname($rel);
    $dirPart = ($dirPart === '.' || $dirPart === '/') ? '' : $dirPart;
    $absDir = MD_ROOT . ($dirPart === '' ? '' : '/' . $dirPart);
    $newName = md_unique_name($absDir, basename($rel));
    if ($newName === false) {
        header('Location: ' . md_admin_url('docs', array('err' => 'failed')));
        exit;
    }
    if (!@copy($abs, $absDir . '/' . $newName)) {
        header('Location: ' . md_admin_url('docs', array('err' => 'failed')));
        exit;
    }
    @chmod($absDir . '/' . $newName, 0644);
    $newRel = ($dirPart === '' ? '' : $dirPart . '/') . $newName;
    md_tree_cache_clear();
    header('Location: ' . md_admin_url('edit', array('f' => $newRel)));
    exit;
}

// ---------------------------------------------------------------- 目录管理（分类）

/**
 * 新建目录：单段目录名 + 父目录下拉。md_safe_dirname 已拒保留名/非法字符/含斜杠；
 * 重名直接拒（不自动加序号——目录是分类，名字必须可控）。
 */
function md_admin_dir_new($cfg)
{
    md_admin_guard();
    md_csrf_guard();

    $err = '';
    $parent = '';
    $name = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $parent = str_replace('\\', '/', trim(isset($_POST['dir']) ? (string)$_POST['dir'] : '', '/'));
        $name = trim(isset($_POST['name']) ? (string)$_POST['name'] : '');
        if ($parent !== '' && md_resolve_path_only($parent) === false) {
            $err = md_t('admin_err_parent_bad');
        } elseif ($parent !== '' && !is_dir(MD_ROOT . '/' . $parent)) {
            $err = md_t('admin_err_parent_missing_dir', array('path' => md_e($parent)));
        } elseif (md_safe_dirname($name) !== $name || $name === '') {
            $err = md_t('admin_err_dirname');
        } elseif (file_exists(MD_ROOT . ($parent === '' ? '' : '/' . $parent) . '/' . $name)) {
            $err = md_t('admin_err_exists_same');
        } elseif (@mkdir(MD_ROOT . ($parent === '' ? '' : '/' . $parent) . '/' . $name, 0755)) {
            md_tree_cache_clear();
            header('Location: ' . md_admin_url('docs', array('saved' => 'mkdirent')));
            exit;
        } else {
            $err = md_t('admin_err_create_fail');
        }
    }

    $tree = md_tree_load($cfg);
    $body = '<div class="admin-stack">';
    if ($err !== '') {
        $body .= '<div class="alert alert-danger">' . $err . '</div>';
    }
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_new_dir')) . '</h2></div>'
        . '<div class="admin-card-body"><form method="post" action="' . md_e(md_admin_url('dirnew')) . '">'
        . md_csrf_field()
        . '<div class="admin-grid">'
        . '<div class="field"><label for="dn-parent">' . md_e(md_t('admin_parent_dir')) . '</label>'
        . '<select class="select" id="dn-parent" name="dir">' . md_admin_dir_options($tree, $parent) . '</select></div>'
        . '<div class="field"><label for="dn-name">' . md_e(md_t('admin_dirname_label')) . '</label>'
        . '<input class="input" type="text" id="dn-name" name="name" value="' . md_e($name) . '"'
        . ' placeholder="' . md_e(md_t('admin_dirname_ph')) . '" required maxlength="128"></div>'
        . '</div>'
        . '<p class="form-hint">' . md_e(md_t('admin_dirnew_hint')) . '</p>'
        . '<div class="admin-actions-row">'
        . '<button class="btn btn-primary" type="submit">' . md_admin_ico('folder-plus') . md_e(md_t('admin_create_dir')) . '</button>'
        . '<a class="btn btn-ghost" href="' . md_e(md_admin_url('docs')) . '">' . md_e(md_t('admin_back_list')) . '</a>'
        . '</div></form></div></section></div>';
    md_admin_page(md_t('admin_new_dir'), $body, 'docs');
}

/**
 * 重命名 / 移动目录：改名单段即可「改名」，换父目录即可「移动整棵子树」
 * （内部文档与附件一起走，rename() 原生语义）。
 */
function md_admin_dir_rename($cfg)
{
    md_admin_guard();
    md_csrf_guard();

    $old = '';
    if (isset($_POST['f'])) {
        $old = (string)$_POST['f'];
    } elseif (isset($_GET['f'])) {
        $old = (string)$_GET['f'];
    }
    $old = str_replace('\\', '/', trim($old, '/'));
    if (md_resolve_path_only($old) === false || !is_dir(MD_ROOT . '/' . $old)) {
        header('Location: ' . md_admin_url('docs', array('err' => 'noent')));
        exit;
    }

    $err = '';
    $parent = dirname($old);
    $parent = ($parent === '.' || $parent === '/') ? '' : $parent;
    $name = basename($old);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $parent = str_replace('\\', '/', trim(isset($_POST['dir']) ? (string)$_POST['dir'] : '', '/'));
        $name = trim(isset($_POST['name']) ? (string)$_POST['name'] : '');
        $to = ($parent === '' ? '' : $parent . '/') . $name;
        $to = str_replace('\\', '/', trim($to, '/'));
        if ($parent !== '' && (!md_resolve_path_only($parent) || !is_dir(MD_ROOT . '/' . $parent))) {
            $err = md_t('admin_err_target_parent_missing', array('path' => md_e($parent)));
        } elseif (md_safe_dirname($name) !== $name || $name === '') {
            $err = md_t('admin_err_dirname');
        } elseif ($parent !== '' && (md_starts_with($parent . '/', $old . '/'))) {
            $err = md_t('admin_err_dir_into_self');
        } elseif (strcasecmp($to, $old) === 0) {
            header('Location: ' . md_admin_url('docs', array('saved' => 'rn_dir')));
            exit;
        } elseif (file_exists(MD_ROOT . '/' . $to)) {
            $err = md_t('admin_err_target_exists', array('path' => md_e($to)));
        } elseif (@rename(MD_ROOT . '/' . $old, MD_ROOT . '/' . $to)) {
            md_tree_cache_clear();
            header('Location: ' . md_admin_url('docs', array('saved' => 'rn_dir')));
            exit;
        } else {
            $err = md_t('admin_err_rename_fail');
        }
    }

    $tree = md_tree_load($cfg);
    $body = '<div class="admin-stack">';
    if ($err !== '') {
        $body .= '<div class="alert alert-danger">' . $err . '</div>';
    }
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_dir_rename_title')) . '</h2></div>'
        . '<div class="admin-card-body"><form method="post" action="' . md_e(md_admin_url('dirrename')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="f" value="' . md_e($old) . '">'
        . '<div class="field"><label>' . md_e(md_t('admin_old_dir')) . '</label>'
        . '<p class="admin-path admin-rename-old">' . md_e($old) . '/</p></div>'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="dr-parent">' . md_e(md_t('admin_target_parent')) . '</label>'
        . '<select class="select" id="dr-parent" name="dir">' . md_admin_dir_options($tree, $parent) . '</select></div>'
        . '<div class="field"><label for="dr-name">' . md_e(md_t('admin_dirname_label')) . '</label>'
        . '<input class="input" type="text" id="dr-name" name="name" value="' . md_e($name) . '"'
        . ' required maxlength="128"></div>'
        . '</div>'
        . '<p class="form-hint">' . md_e(md_t('admin_dir_rename_hint')) . '</p>'
        . '<div class="admin-actions-row">'
        . '<button class="btn btn-primary" type="submit">' . md_e(md_t('admin_rename_submit')) . '</button>'
        . '<a class="btn btn-ghost" href="' . md_e(md_admin_url('docs')) . '">' . md_e(md_t('admin_back_list')) . '</a>'
        . '</div></form></div></section></div>';
    md_admin_page(md_t('admin_dir_rename_page', array('rel' => $old)), $body, 'docs');
}

/** 删除目录：只许删空目录（rmdir 非空即失败），不递归——防误删整棵子树。 */
function md_admin_dir_del($cfg)
{
    md_admin_guard();
    md_csrf_guard();

    $old = '';
    if (isset($_POST['f'])) {
        $old = (string)$_POST['f'];
    } elseif (isset($_GET['f'])) {
        $old = (string)$_GET['f'];
    }
    $old = str_replace('\\', '/', trim($old, '/'));
    if (md_resolve_path_only($old) === false || !is_dir(MD_ROOT . '/' . $old)) {
        header('Location: ' . md_admin_url('docs', array('err' => 'noent')));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (@rmdir(MD_ROOT . '/' . $old)) {
            md_tree_cache_clear();
            header('Location: ' . md_admin_url('docs', array('saved' => 'rm_dir')));
            exit;
        }
        header('Location: ' . md_admin_url('docs', array('err' => 'dirnotempty')));
        exit;
    }

    $body = '<div class="admin-stack">'
        . '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_dir_del_title')) . '</h2></div>'
        . '<div class="admin-card-body">'
        . '<p>' . md_t('admin_dir_del_q', array('path' => md_e($old) . '/')) . '</p>'
        . '<p class="form-hint">' . md_e(md_t('admin_dir_del_hint')) . '</p>'
        . '<form method="post" action="' . md_e(md_admin_url('dirdel')) . '" data-confirm="' . md_e(md_t('admin_dir_del_confirm_js', array('path' => $old))) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="f" value="' . md_e($old) . '">'
        . '<div class="admin-actions-row">'
        . '<button class="btn btn-danger" type="submit">' . md_admin_ico('trash') . md_e(md_t('admin_confirm_del_btn')) . '</button>'
        . '<a class="btn btn-ghost" href="' . md_e(md_admin_url('docs')) . '">' . md_e(md_t('admin_back_list')) . '</a>'
        . '</div></form></div></section></div>';
    md_admin_page(md_t('admin_dir_del_page', array('rel' => $old)), $body, 'docs');
}
/** 删除：POST + CSRF + data-confirm 二次确认。绝不许删 index.php 与 _dir 内文件。 */
function md_admin_doc_delete($cfg)
{
    md_admin_guard();
    md_csrf_guard();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . md_admin_url('docs'));
        exit;
    }
    $rel = str_replace('\\', '/', trim(isset($_POST['f']) ? (string)$_POST['f'] : '', '/'));
    if (md_resolve_path_only($rel) === false) {
        header('Location: ' . md_admin_url('docs', array('err' => 'badpath')));
        exit;
    }
    if (strtolower($rel) === 'index.php') {
        header('Location: ' . md_admin_url('docs', array('err' => 'protected')));
        exit;
    }
    $abs = md_resolve($rel, $cfg, 'md');
    if ($abs === false) {
        header('Location: ' . md_admin_url('docs', array('err' => 'noent')));
        exit;
    }
    if (!@unlink($abs)) {
        header('Location: ' . md_admin_url('docs', array('err' => 'failed')));
        exit;
    }
    @unlink(MD_ROOT . '/' . $rel . '.cmt.php');     // 评论文件随文档删除（v1.17.0）
    md_tree_cache_clear();
    header('Location: ' . md_admin_url('docs', array('saved' => 'deleted')));
    exit;
}

// ---------------------------------------------------------------- 评论管理（v1.17.0）

/** 收集全站评论文件：递归内容目录（跳过隐藏/系统/常见大目录），返回文档 rel 列表（已剥 .cmt.php）。 */
function md_admin_cmt_files($root, $prefix = '', &$out = array())
{
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
            if ($name[0] === '.' || $name === MD_DIRNAME
                || in_array($name, array('node_modules', 'vendor'), true)) {
                continue;                           // 隐藏目录 / _dir / 常见大目录
            }
            md_admin_cmt_files($full, $rel, $out);
        } elseif (md_ends_with($name, '.cmt.php')) {
            $out[] = substr($rel, 0, -8);
        }
    }
    return $out;
}

/** 一条评论的管理操作按钮（批准/删除）：独立小表单，CSRF 与全站后台同规。 */
function md_admin_cmt_btns($act, $rel, $id, $label, $ico, $cls)
{
    return '<form method="post" action="' . md_e(md_admin_url('comments')) . '" class="d-inline-block">'
        . md_csrf_field()
        . '<input type="hidden" name="act" value="' . md_e($act) . '">'
        . '<input type="hidden" name="d" value="' . md_e($rel) . '">'
        . '<input type="hidden" name="id" value="' . (int)$id . '">'
        . '<button class="btn ' . $cls . ' btn-sm" type="submit">' . md_admin_ico($ico) . md_e($label) . '</button>'
        . '</form>';
}

/**
 * 评论管理页：待审队列（批准/删除）+ 已通过（按文档折叠，可删）+ 孤儿文件兜底
 * （FTP 直接删 .md 不会带走旁边的 .cmt.php，这里对照文档树清理）。批准一条回复时
 * 触发「通知被回复者」邮件（未配 SMTP 则静默跳过）。
 */
function md_admin_comments($tree, $cfg)
{
    md_admin_guard();
    md_csrf_guard();

    $treeMd = isset($tree['md']) && is_array($tree['md']) ? $tree['md'] : array();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = isset($_POST['act']) ? (string)$_POST['act'] : '';
        if ($act === 'approve' || $act === 'del') {
            $rel = str_replace('\\', '/', trim(isset($_POST['d']) && is_string($_POST['d']) ? $_POST['d'] : '', '/'));
            $id = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;
            if (isset($treeMd[$rel]) && $id > 0) {
                $done = md_cmt_txn($rel, function (array $data) use ($act, $id) {
                    return $act === 'del'
                        ? md_cmt_remove($data, $id)
                        : md_cmt_set_status($data, $id, 'approved');
                });
                if ($done && $act === 'approve') {
                    md_cmt_notify_reply_maybe($cfg, $rel, $id);
                }
            }
        } elseif ($act === 'orphan_del') {
            $rel = str_replace('\\', '/', trim(isset($_POST['d']) && is_string($_POST['d']) ? $_POST['d'] : '', '/'));
            // 三重保险：后缀恰好 .cmt.php、落点仍在文档根内、当前树里已无对应文档
            if (md_ends_with($rel, '.cmt.php') && !isset($treeMd[substr($rel, 0, -8)])
                && md_starts_with(str_replace('\\', '/', dirname(MD_ROOT . '/' . $rel)) . '/',
                    str_replace('\\', '/', MD_ROOT) . '/')) {
                @unlink(MD_ROOT . '/' . $rel);
            }
        }
        header('Location: ' . md_admin_url('comments', array('saved' => 'cmt')));
        exit;
    }

    $pending = array();
    $approved = array();
    foreach ($treeMd as $rel => $info) {
        if (!is_file(md_cmt_file($rel))) {
            continue;
        }
        $data = md_cmt_load($rel);
        $title = isset($info['title']) && $info['title'] !== '' ? $info['title'] : md_title_of($rel);
        foreach ($data['comments'] as $c) {
            if (!isset($c['status'])) {
                continue;
            }
            if ($c['status'] === 'pending') {
                $pending[] = array('rel' => $rel, 'title' => $title, 'c' => $c);
            } elseif ($c['status'] === 'approved') {
                if (!isset($approved[$rel])) {
                    $approved[$rel] = array('title' => $title, 'items' => array());
                }
                $approved[$rel]['items'][] = $c;
            }
        }
    }
    $orphans = array();
    foreach (md_admin_cmt_files(MD_ROOT) as $rel) {
        if (!isset($treeMd[$rel])) {
            $orphans[] = $rel;
        }
    }

    $body = '<div class="admin-stack">';
    $saved = isset($_GET['saved']) ? (string)$_GET['saved'] : '';
    if ($saved === 'cmt') {
        $body .= '<div class="alert alert-ok">' . md_e(md_t('admin_cmt_flash_ok')) . '</div>';
    }

    // ---- 待审队列 ----
    $body .= '<section class="card"><div class="admin-card-head"><h2>'
        . md_t('admin_cmt_pending', array('n' => count($pending))) . '</h2></div><div class="admin-card-body">';
    if (count($pending) === 0) {
        $body .= '<p class="text-muted mb-0">' . md_e(md_t('admin_cmt_none_pending')) . '</p>';
    } else {
        foreach ($pending as $i => $p) {
            $c = $p['c'];
            $docUrl = md_url_for(md_strip_doc_ext($p['rel'], $cfg), 'doc', $cfg);
            $body .= '<div class="border-bottom pb-3 mb-3' . ($i === count($pending) - 1 ? ' border-bottom-0 mb-0 pb-0' : '') . '">'
                . '<div class="small text-muted mb-1">'
                . '<span class="badge badge-secondary">' . md_e(date('Y-m-d H:i', (int)$c['time'])) . '</span> '
                . '<b class="text-body">' . md_e((string)$c['author']) . '</b>'
                . (isset($c['email']) && (string)$c['email'] !== '' ? ' &lt;' . md_e((string)$c['email']) . '&gt;' : '')
                . ((int)$c['reply_to'] > 0 ? ' · ' . md_e(md_t('cmt_replying', array('name' => '#' . (int)$c['reply_to']))) : '')
                . '</div>'
                . '<p class="mb-2" style="white-space:pre-wrap;word-break:break-word">' . md_e((string)$c['body']) . '</p>'
                . '<div class="d-flex flex-wrap align-items-center justify-content-between">'
                . '<div class="small text-muted">'
                . md_e(md_t('admin_cmt_doc')) . ' <a href="' . md_e($docUrl) . '" target="_blank" rel="noopener">'
                . md_e($p['title']) . '</a></div>'
                . '<div>' . md_admin_cmt_btns('approve', $p['rel'], (int)$c['id'], md_t('admin_cmt_approve'), 'ok', 'btn-primary')
                . ' ' . md_admin_cmt_btns('del', $p['rel'], (int)$c['id'], md_t('admin_cmt_delete'), 'trash', 'btn-outline-danger')
                . '</div></div></div>';
        }
    }
    $body .= '</div></section>';

    // ---- 已通过（按文档折叠） ----
    $approvedTotal = 0;
    foreach ($approved as $a) {
        $approvedTotal += count($a['items']);
    }
    $body .= '<section class="card"><div class="admin-card-head"><h2>'
        . md_t('admin_cmt_approved', array('n' => $approvedTotal)) . '</h2></div><div class="admin-card-body">';
    if (count($approved) === 0) {
        $body .= '<p class="text-muted mb-0">' . md_e(md_t('admin_cmt_none_all')) . '</p>';
    } else {
        foreach ($approved as $rel => $a) {
            $docUrl = md_url_for(md_strip_doc_ext($rel, $cfg), 'doc', $cfg);
            $body .= '<details class="mb-2"><summary class="small">'
                . '<a href="' . md_e($docUrl) . '" target="_blank" rel="noopener">' . md_e($a['title']) . '</a> '
                . md_e(md_t('admin_cmt_doc_n', array('n' => count($a['items']))))
                . '</summary>';
            foreach ($a['items'] as $c) {
                $body .= '<div class="d-flex align-items-start border-bottom py-2">'
                    . '<div class="flex-grow-1 mr-2">'
                    . '<div class="small text-muted"><b class="text-body">' . md_e((string)$c['author']) . '</b> · '
                    . md_e(date('Y-m-d H:i', (int)$c['time']))
                    . ((int)$c['reply_to'] > 0 ? ' · ' . md_e(md_t('cmt_replying', array('name' => '#' . (int)$c['reply_to']))) : '')
                    . '</div>'
                    . '<div class="small" style="white-space:pre-wrap;word-break:break-word">' . md_e((string)$c['body']) . '</div>'
                    . '</div>'
                    . md_admin_cmt_btns('del', $rel, (int)$c['id'], md_t('admin_cmt_delete'), 'trash', 'btn-outline-danger')
                    . '</div>';
            }
            $body .= '</details>';
        }
    }
    $body .= '</div></section>';

    // ---- 孤儿评论文件 ----
    if (count($orphans) > 0) {
        $body .= '<section class="card"><div class="admin-card-head"><h2>'
            . md_e(md_t('admin_cmt_orphans')) . '</h2></div><div class="admin-card-body">'
            . '<p class="small text-muted">' . md_e(md_t('admin_cmt_orphan_hint')) . '</p>';
        foreach ($orphans as $rel) {
            $body .= '<div class="d-flex align-items-center justify-content-between border-bottom py-2">'
                . '<code class="small">' . md_e($rel . '.cmt.php') . '</code>'
                . '<form method="post" action="' . md_e(md_admin_url('comments')) . '" class="d-inline-block">'
                . md_csrf_field()
                . '<input type="hidden" name="act" value="orphan_del">'
                . '<input type="hidden" name="d" value="' . md_e($rel . '.cmt.php') . '">'
                . '<button class="btn btn-outline-danger btn-sm" type="submit">'
                . md_admin_ico('trash') . md_e(md_t('admin_cmt_delfile')) . '</button></form>'
                . '</div>';
        }
        $body .= '</div></section>';
    }

    $body .= '</div>';
    md_admin_page(md_t('admin_cmt_title'), $body, 'comments');
}

// ---------------------------------------------------------------- 附件管理

/** 引用写法：含空格必须用 <...> 角括号（裸空格渲染不出来），不含空格用普通形式。 */
function md_attach_ref($name)
{
    if (strpos($name, ' ') !== false) {
        return '![说明](<' . $name . '>)';
    }
    return '![说明](' . $name . ')';
}

/** 附件页：顶部多文件上传 + 全站附件按目录分组列表（删除 + 复制引用）。 */
function md_admin_attach($tree, $cfg)
{
    md_admin_guard();
    md_csrf_guard();

    $body = '<div class="admin-stack">';
    $changed = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do']) && $_POST['do'] === 'upload') {
        $r = md_admin_attach_upload($cfg);
        $body .= $r['html'];
        $changed = $r['changed'];
    }

    // 上传卡
    $dirVal = isset($_POST['dir']) ? (string)$_POST['dir'] : '';
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_attach_upload_title')) . '</h2></div>'
        . '<div class="admin-card-body"><form method="post" action="' . md_e(md_admin_url('attach')) . '"'
        . ' enctype="multipart/form-data">'
        . md_csrf_field()
        . '<input type="hidden" name="do" value="upload">'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="up-dir">' . md_e(md_t('admin_up_dir_label')) . '</label>'
        . '<input class="input" type="text" id="up-dir" name="dir" value="' . md_e($dirVal) . '" placeholder="' . md_e(md_t('admin_up_dir_ph')) . '"></div>'
        . '<div class="field"><label for="up-files">' . md_e(md_t('admin_up_files_label')) . '</label>'
        . '<input class="input" type="file" id="up-files" name="files[]" multiple required></div>'
        . '</div>'
        . '<p class="form-hint">' . md_e(md_t('admin_up_hint', array('list' => implode(' / ', $cfg['ext_attach'])))) . '</p>'
        . '<div class="admin-actions-row"><button class="btn btn-primary" type="submit">' . md_e(md_t('admin_upload_btn')) . '</button></div>'
        . '</form></div></section>';

    // 引用写法说明（含空格的文件名是刚修过的渲染语法，必须教角括号形式）
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_ref_title')) . '</h2></div>'
        . '<div class="admin-card-body">'
        . '<p>' . md_e(md_t('admin_ref_intro')) . '</p>'
        . '<ul>'
        . '<li>' . md_t('admin_ref_plain') . '</li>'
        . '<li>' . md_t('admin_ref_bracket') . '</li>'
        . '</ul>'
        . '<p class="form-hint">' . md_e(md_t('admin_ref_hint')) . '</p>'
        . '</div></section>';

    // 列表（按目录分组）
    if ($changed) {
        $tree = md_tree_load($cfg);                     // 上传后清了缓存，重扫拿最新列表
    }
    if (empty($tree['attach'])) {
        $body .= '<section class="card"><p class="admin-empty">' . md_e(md_t('admin_attach_empty')) . '</p></section>';
    } else {
        $groups = array();
        foreach ($tree['attach'] as $rel => $info) {
            $d = dirname($rel);
            $d = ($d === '.' || $d === '\\') ? '' : $d;
            $groups[$d][$rel] = $info;
        }
        uksort($groups, 'md_str_compare');
        $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_attach_count', array('n' => count($tree['attach'])))) . '</h2></div>'
            . '<div class="admin-card-body admin-card-body-tight">';
        foreach ($groups as $dirRel => $items) {
            $body .= '<h3 class="admin-group-title">' . md_e($dirRel === '' ? md_t('admin_root_dir') : $dirRel . '/') . '</h3>'
                . '<div class="admin-wrap"><table class="table admin-attach"><thead><tr>'
                . '<th>' . md_e(md_t('admin_filename_label')) . '</th><th>' . md_e(md_t('admin_th_size')) . '</th><th>' . md_e(md_t('admin_th_mtime')) . '</th><th class="admin-col-act">' . md_e(md_t('admin_th_actions')) . '</th>'
                . '</tr></thead><tbody>';
            $names = array_keys($items);
            usort($names, 'md_str_compare');
            foreach ($names as $rel) {
                $info = $items[$rel];
                $ref = md_attach_ref(isset($info['name']) ? $info['name'] : basename($rel));
                $mtime = isset($info['mtime']) ? (int)$info['mtime'] : 0;
                $size = isset($info['size']) ? $info['size'] : 0;
                $body .= '<tr><td><span class="admin-path">' . md_e($rel) . '</span></td>'
                    . '<td class="admin-dim admin-nowrap">' . md_e(md_format_size($size)) . '</td>'
                    . '<td class="admin-dim admin-nowrap">' . ($mtime > 0 ? md_e(date('Y-m-d H:i', $mtime)) : '—') . '</td>'
                . '<td class="admin-col-act"><span class="admin-actions">'
                . '<button type="button" class="btn btn-ghost btn-sm" data-copy="' . md_e($ref) . '">' . md_e(md_t('admin_copy_ref')) . '</button>'
                . '</span><form method="post" action="' . md_e(md_admin_url('attachdel')) . '" class="admin-inline-form"'
                . ' data-confirm="' . md_e(md_t('admin_confirm_del_attach', array('path' => $rel))) . '">'
                . md_csrf_field()
                . '<input type="hidden" name="f" value="' . md_e($rel) . '">'
                . '<button class="btn btn-danger btn-sm" type="submit">' . md_admin_ico('trash') . md_e(md_t('admin_delete')) . '</button>'
                . '</form></td></tr>';
            }
            $body .= '</tbody></table></div>';
        }
        $body .= '</div></section>';
    }
    $body .= '</div>';
    md_admin_page(md_t('admin_attach_title'), $body, 'attach');
}

/** 多文件上传：逐个处理、逐个报成功/失败。返回 array('html'=>回执, 'changed'=>是否有成功)。 */
function md_admin_attach_upload($cfg)
{
    if (MD_READONLY) {
        return array('html' => '<div class="alert alert-danger">' . md_e(md_t('admin_up_err_readonly')) . '</div>', 'changed' => false);
    }
    $dirRel = str_replace('\\', '/', trim(isset($_POST['dir']) ? (string)$_POST['dir'] : '', '/'));
    if ($dirRel !== '' && md_resolve_path_only($dirRel) === false) {
        return array('html' => '<div class="alert alert-danger">' . md_e(md_t('admin_up_err_dir_bad')) . '</div>', 'changed' => false);
    }
    $absDir = MD_ROOT . ($dirRel === '' ? '' : '/' . $dirRel);
    if (!is_dir($absDir)) {
        return array('html' => '<div class="alert alert-danger">' . md_e(md_t('admin_up_err_dir_missing', array('path' => $dirRel))) . '</div>',
                     'changed' => false);
    }
    if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'])) {
        return array('html' => '<div class="alert alert-warn">' . md_e(md_t('admin_up_err_nofiles')) . '</div>', 'changed' => false);
    }

    $oks = array();
    $errs = array();
    foreach ($_FILES['files']['name'] as $i => $orig) {
        $orig = (string)$orig;
        $err = isset($_FILES['files']['error'][$i]) ? (int)$_FILES['files']['error'][$i] : UPLOAD_ERR_NO_FILE;
        $tmp = isset($_FILES['files']['tmp_name'][$i]) ? (string)$_FILES['files']['tmp_name'][$i] : '';
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;                                   // 空选择框，不算失败
        }
        if ($err !== UPLOAD_ERR_OK) {
            $errs[] = md_e($orig) . md_t('admin_up_err_code', array('code' => $err));
            continue;
        }
        $safe = md_safe_name($orig);
        if ($safe === false) {
            $errs[] = md_e($orig) . md_t('admin_up_err_badname');
            continue;
        }
        if (!in_array(md_ext($safe), $cfg['ext_attach'], true)) {
            $errs[] = md_e($safe) . md_t('admin_up_err_ext');
            continue;
        }
        $name = md_unique_name($absDir, $safe);
        if ($name === false) {
            $errs[] = md_e($safe) . md_t('admin_up_err_unique');
            continue;
        }
        if (!@move_uploaded_file($tmp, $absDir . '/' . $name)) {
            $errs[] = md_e($name) . md_t('admin_up_err_save');
            continue;
        }
        @chmod($absDir . '/' . $name, 0644);
        $oks[] = array(
            'rel'  => $dirRel === '' ? $name : $dirRel . '/' . $name,
            'ref'  => md_attach_ref($name),
        );
    }
    if ($oks) {
        md_tree_cache_clear();
    }
    $html = '';
    if ($oks) {
        $html .= '<div class="alert alert-ok"><strong>' . md_e(md_t('admin_up_ok', array('n' => count($oks)))) . '</strong><ul class="admin-up-list">';
        foreach ($oks as $o) {
            $html .= '<li><code>' . md_e($o['rel']) . '</code>　' . md_e(md_t('admin_up_ref_label')) . '<code>' . md_e($o['ref']) . '</code> '
                . '<button type="button" class="btn btn-ghost btn-sm" data-copy="' . md_e($o['ref']) . '">' . md_e(md_t('admin_copy')) . '</button></li>';
        }
        $html .= '</ul></div>';
    }
    if ($errs) {
        $html .= '<div class="alert alert-danger"><strong>' . md_e(md_t('admin_up_fail', array('n' => count($errs)))) . '</strong><ul class="admin-up-list">';
        foreach ($errs as $e) {
            $html .= '<li>' . $e . '</li>';
        }
        $html .= '</ul></div>';
    }
    return array('html' => $html, 'changed' => (bool)$oks);
}

/** 附件删除：md_resolve(attach) 已含白名单/根内/symlink 校验。 */
function md_admin_attach_delete($cfg)
{
    md_admin_guard();
    md_csrf_guard();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . md_admin_url('attach'));
        exit;
    }
    $rel = str_replace('\\', '/', trim(isset($_POST['f']) ? (string)$_POST['f'] : '', '/'));
    $abs = md_resolve($rel, $cfg, 'attach');
    if ($abs === false || !@unlink($abs)) {
        header('Location: ' . md_admin_url('attach', array('err' => 'failed')));
        exit;
    }
    md_tree_cache_clear();
    header('Location: ' . md_admin_url('attach', array('saved' => 'deleted')));
    exit;
}

// ---------------------------------------------------------------- 缓存管理

function md_admin_cache($cfg)
{
    md_admin_guard();
    md_csrf_guard();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = isset($_POST['cache_do']) ? (string)$_POST['cache_do'] : '';
        if ($act === 'rebuild') {
            md_tree_cache_clear();
            $tree = md_tree_load($cfg);
            md_search_index_build($cfg, $tree);
            header('Location: ' . md_admin_url('cache', array('saved' => 'rebuild')));
            exit;
        }
        if ($act === 'clear') {
            md_tree_cache_clear();
            @unlink(md_search_index_file());            // 两者都可自动重建，放心删
            header('Location: ' . md_admin_url('cache', array('saved' => 'clear')));
            exit;
        }
        if ($act === 'feedback_clear') {
            @unlink(md_feedback_store_file());          // 投票计数与限流记账都可再生，直接删
            @unlink(md_feedback_ip_file());
            header('Location: ' . md_admin_url('cache', array('saved' => 'fbclear')));
            exit;
        }
    }

    $body = '<div class="admin-stack">';
    $saved = isset($_GET['saved']) ? (string)$_GET['saved'] : '';
    $flash = array(
        'rebuild' => 'admin_cache_flash_rebuild',
        'clear'   => 'admin_cache_flash_clear',
        'fbclear' => 'admin_fb_flash_clear',
    );
    if (isset($flash[$saved])) {
        $body .= '<div class="alert alert-ok">' . md_e(md_t($flash[$saved])) . '</div>';
    }

    // 目录树缓存状态
    $c = md_tree_cache_read($cfg);
    if (is_array($c)) {
        $treeState = md_t('admin_cache_state_ok', array(
            'time' => date('Y-m-d H:i', (int)$c['built']),
            'v'    => isset($c['v']) ? (int)$c['v'] : 0,
        ));
    } elseif (is_file(md_tree_cache_file())) {
        $treeState = md_t('admin_cache_state_stale');
    } else {
        $treeState = md_t('admin_cache_state_none');
    }

    // 搜索索引状态
    $idx = md_search_index_load();
    $idxState = is_file(md_search_index_file())
        ? md_t('admin_idx_ok', array('n' => count($idx['mtime'])))
        : md_t('admin_idx_none');

    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_cache_state_title')) . '</h2></div>'
        . '<div class="admin-wrap"><table class="table admin-kv"><tbody>'
        . '<tr><th>' . md_e(md_t('admin_kv_cache')) . '</th><td>' . $treeState . '</td></tr>'
        . '<tr><th>' . md_e(md_t('admin_kv_index')) . '</th><td>' . $idxState . '</td></tr>'
        . '<tr><th>' . md_e(md_t('admin_kv_cache_switch')) . '</th><td>' . ((!empty($cfg['cache']) && !MD_READONLY)
            ? md_e(md_t('admin_cache_on'))
            : (MD_READONLY ? md_e(md_t('admin_cache_readonly')) : md_e(md_t('admin_cache_disabled')))) . '</td></tr>'
        . '</tbody></table></div>'
        . '<div class="admin-card-body"><div class="admin-actions-row admin-actions-row-tight">'
        . '<form method="post" action="' . md_e(md_admin_url('cache')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="cache_do" value="rebuild">'
        . '<button class="btn btn-primary btn-sm" type="submit">' . md_e(md_t('admin_cache_rebuild_btn')) . '</button></form>'
        . '<form method="post" action="' . md_e(md_admin_url('cache')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="cache_do" value="clear">'
        . '<button class="btn btn-danger btn-sm" type="submit">' . md_e(md_t('admin_cache_clear_btn')) . '</button></form>'
        . '</div>'
        . '<p class="form-hint">' . md_e(md_t('admin_cache_hint')) . '</p></div>'
        . '</section>';

    // ---- 文档反馈（v1.16.0）：前台匿名投票的聚合计数。差评多的排前 = 优先修订清单 ----
    $tree = md_tree_load($cfg);                         // 反馈卡需要文档标题与存在性校验
    $fbRows = array();
    foreach (md_feedback_load()['docs'] as $fbRel => $fbC) {
        if (!isset($tree['md'][$fbRel])) {
            continue;                                   // 文档已删：不计入（下次任何投票都会顺带剪除）
        }
        $fbU = isset($fbC['u']) ? (int)$fbC['u'] : 0;
        $fbD = isset($fbC['d']) ? (int)$fbC['d'] : 0;
        if ($fbU === 0 && $fbD === 0) {
            continue;
        }
        $fbRows[] = array('rel' => $fbRel, 'u' => $fbU, 'd' => $fbD);
    }
    usort($fbRows, function ($a, $b) {
        if ($a['d'] !== $b['d']) {
            return $a['d'] > $b['d'] ? -1 : 1;          // 需改进多者优先——修订价值排序
        }
        if ($a['u'] !== $b['u']) {
            return $a['u'] > $b['u'] ? -1 : 1;
        }
        return strcmp($a['rel'], $b['rel']);
    });
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_fb_title')) . '</h2></div>'
        . '<div class="admin-card-body"><p class="form-hint">' . md_t('admin_fb_hint') . '</p>';
    if (!$fbRows) {
        $body .= '<p class="admin-empty">' . md_e(md_t('admin_fb_empty')) . '</p></div></section>';
    } else {
        $body .= '<div class="admin-wrap"><table class="table admin-recent"><thead><tr>'
            . '<th>' . md_e(md_t('admin_fb_doc')) . '</th>'
            . '<th>' . md_e(md_t('admin_fb_up')) . '</th>'
            . '<th>' . md_e(md_t('admin_fb_down')) . '</th>'
            . '<th class="admin-col-act">' . md_e(md_t('admin_th_actions')) . '</th></tr></thead><tbody>';
        foreach (array_slice($fbRows, 0, 10) as $fbR) {
            $fbTitle = isset($tree['md'][$fbR['rel']]['title']) && (string)$tree['md'][$fbR['rel']]['title'] !== ''
                ? (string)$tree['md'][$fbR['rel']]['title']
                : md_display_name(md_strip_doc_ext($fbR['rel'], $cfg), $cfg);
            $body .= '<tr><td><a href="' . md_e(md_url_for(md_strip_doc_ext($fbR['rel'], $cfg), 'doc', $cfg))
                . '" target="_blank" rel="noopener">' . md_e($fbTitle) . '</a>'
                . '<div class="admin-dim admin-path">' . md_e($fbR['rel']) . '</div></td>'
                . '<td class="admin-nowrap">' . (int)$fbR['u'] . '</td>'
                . '<td class="admin-nowrap">' . (int)$fbR['d'] . '</td>'
                . '<td class="admin-col-act"><span class="admin-actions">'
                . '<a class="btn btn-ghost btn-sm" href="' . md_e(md_admin_url('edit', array('f' => $fbR['rel']))) . '">'
                . md_admin_ico('pencil') . md_e(md_t('admin_edit')) . '</a></span></td></tr>';
        }
        $body .= '</tbody></table></div>'
            . '<div class="admin-actions-row admin-actions-row-tight">'
            . '<form method="post" action="' . md_e(md_admin_url('cache')) . '"'
            . ' data-confirm="' . md_e(md_t('admin_fb_clear_confirm_js')) . '">'
            . md_csrf_field()
            . '<input type="hidden" name="cache_do" value="feedback_clear">'
            . '<button class="btn btn-danger btn-sm" type="submit">' . md_e(md_t('admin_fb_clear')) . '</button></form>'
            . '</div></section>';
    }
    $body .= '</div>';
    md_admin_page(md_t('admin_cache_title'), $body, 'cache');
}

// ---------------------------------------------------------------- 站点设置

function md_admin_settings($cfg)
{
    md_admin_guard();
    md_csrf_guard();

    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = isset($_POST['set_do']) ? (string)$_POST['set_do'] : '';
        if ($act === 'site') {
            $patch = array(
                'site_title'    => isset($_POST['site_title']) ? trim((string)$_POST['site_title']) : '',
                'site_desc'     => isset($_POST['site_desc']) ? trim((string)$_POST['site_desc']) : '',
                'theme'         => isset($_POST['theme']) ? (string)$_POST['theme'] : 'default',
                'mode'          => isset($_POST['mode']) ? (string)$_POST['mode'] : 'auto',
                'language'      => isset($_POST['language']) ? (string)$_POST['language'] : 'auto',
                'url_mode'      => isset($_POST['url_mode']) ? (string)$_POST['url_mode'] : 'simple',
                'base_path'     => isset($_POST['base_path']) ? (string)$_POST['base_path'] : '',
                'site_url'      => isset($_POST['site_url']) ? (string)$_POST['site_url'] : '',
                'home'          => isset($_POST['home']) ? trim((string)$_POST['home']) : '',
                'show_attach'   => !empty($_POST['show_attach']),
                'order_prefix'  => !empty($_POST['order_prefix']),
                'cache'         => !empty($_POST['cache']),
                'ext_md'        => isset($_POST['ext_md']) ? explode(',', (string)$_POST['ext_md']) : array(),
                'ext_attach'    => isset($_POST['ext_attach']) ? explode(',', (string)$_POST['ext_attach']) : array(),
                'hidden_prefix' => isset($_POST['hidden_prefix']) ? explode(',', (string)$_POST['hidden_prefix']) : array(),
                'risk_ignore'   => isset($_POST['risk_ignore'])
                    ? preg_split('/\r\n|\r|\n/', (string)$_POST['risk_ignore']) : array(),
            );
            if ($patch['site_title'] === '') {
                $patch['site_title'] = '我的文档';
            }
            if (!in_array($patch['theme'], md_available_themes(), true)) {
                $patch['theme'] = 'default';
            }
            if (!in_array($patch['mode'], array('auto', 'light', 'dark'), true)) {
                $patch['mode'] = 'auto';
            }
            if (!in_array($patch['language'], md_lang_supported(), true)) {
                $patch['language'] = 'auto';
            }
            if (md_config_set($patch)) {
                md_tree_cache_clear();                  // 白名单/排序变了，指纹自动失效；顺手清一次
                header('Location: ' . md_admin_url('config', array('saved' => 'site')));
                exit;
            }
            $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
        } elseif ($act === 'user') {
            $old = isset($_POST['old']) ? (string)$_POST['old'] : '';
            $user = isset($_POST['user']) ? trim((string)$_POST['user']) : '';
            if (!password_verify($old, (string)$cfg['admin_pass'])) {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_oldpw_user')) . '</div>';
            } elseif (!md_admin_user_valid($user)) {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_newuser')) . '</div>';
            } elseif (md_config_set(array('admin_user' => $user))) {
                header('Location: ' . md_admin_url('config', array('saved' => 'user')));
                exit;
            } else {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
            }
        } elseif ($act === 'pass') {
            $old = isset($_POST['old']) ? (string)$_POST['old'] : '';
            $new = isset($_POST['new']) ? (string)$_POST['new'] : '';
            $new2 = isset($_POST['new2']) ? (string)$_POST['new2'] : '';
            if (!password_verify($old, (string)$cfg['admin_pass'])) {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_oldpw_pass')) . '</div>';
            } elseif (strlen($new) < 8) {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_newpw_short')) . '</div>';
            } elseif ($new !== $new2) {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_newpw_mismatch')) . '</div>';
            } elseif (md_config_set(array('admin_pass' => password_hash($new, PASSWORD_DEFAULT)))) {
                header('Location: ' . md_admin_url('config', array('saved' => 'pass')));
                exit;
            } else {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
            }
        } elseif ($act === 'cmt') {
            // 评论与通知（v1.17.0）：SMTP 密码留空 = 保留已存值（表单回显不回传明文）
            $patch = array(
                'comments'     => !empty($_POST['comments']),
                'notify_email' => isset($_POST['notify_email']) ? trim((string)$_POST['notify_email']) : '',
                'smtp_host'    => isset($_POST['smtp_host']) ? trim((string)$_POST['smtp_host']) : '',
                'smtp_port'    => isset($_POST['smtp_port']) ? trim((string)$_POST['smtp_port']) : '465',
                'smtp_user'    => isset($_POST['smtp_user']) ? trim((string)$_POST['smtp_user']) : '',
                'smtp_pass'    => isset($_POST['smtp_pass']) ? (string)$_POST['smtp_pass'] : '',
                'smtp_from'    => isset($_POST['smtp_from']) ? trim((string)$_POST['smtp_from']) : '',
                'smtp_secure'  => isset($_POST['smtp_secure']) ? (string)$_POST['smtp_secure'] : '',
            );
            if (!in_array($patch['smtp_secure'], array('', 'ssl', 'none'), true)) {
                $patch['smtp_secure'] = '';
            }
            if ($patch['smtp_pass'] === '') {
                unset($patch['smtp_pass']);
            }
            if (md_config_set($patch)) {
                header('Location: ' . md_admin_url('config', array('saved' => 'cmt')));
                exit;
            }
            $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
        } elseif ($act === 'totp_setup') {
            // 第一步：生成密钥只进会话——用户提交一个有效动态码验明设备可用后才落盘，
            // 防止扫码失败/抄错密钥把自己锁死在门外。已启用时拒绝直接换绑：
            // 换密钥必须走「验当前码解除 → 验新码启用」两段，每段都有因子把关
            if (MD_READONLY) {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
            } elseif ((string)$cfg['admin_totp'] !== '') {
                unset($_SESSION['md_totp_new']);
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_totp_err_already')) . '</div>';
            } else {
                $_SESSION['md_totp_new'] = md_totp_secret_gen();
                unset($_SESSION['md_totp_last']);       // 新密钥的防重放计数从零开始
            }
        } elseif ($act === 'totp_enable') {
            // 第二步：动态码对了才写入 config
            $new = isset($_SESSION['md_totp_new']) ? (string)$_SESSION['md_totp_new'] : '';
            $code = isset($_POST['code']) ? (string)$_POST['code'] : '';
            if ((string)$cfg['admin_totp'] !== '') {
                unset($_SESSION['md_totp_new']);        // 期间他处已启用：丢弃残留密钥，防覆盖
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_totp_err_already')) . '</div>';
            } elseif ($new === '') {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_totp_err_session')) . '</div>';
            } elseif (!md_totp_verify($new, $code)) {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_totp_err_code')) . '</div>';
            } elseif (md_config_set(array('admin_totp' => $new))) {
                unset($_SESSION['md_totp_new']);
                header('Location: ' . md_admin_url('config', array('saved' => 'totp_on')));
                exit;
            } else {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
            }
        } elseif ($act === 'totp_cancel') {
            unset($_SESSION['md_totp_new']);
        } elseif ($act === 'totp_disable') {
            // 禁用必须提供当前动态码：会话被劫持时攻击者无法一步拆掉第二因子
            $code = isset($_POST['code']) ? (string)$_POST['code'] : '';
            if ((string)$cfg['admin_totp'] === '') {
                header('Location: ' . md_admin_url('config', array('saved' => 'totp_off')));
                exit;
            } elseif (!md_totp_verify((string)$cfg['admin_totp'], $code)) {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_totp_err_code')) . '</div>';
            } elseif (md_config_set(array('admin_totp' => ''))) {
                header('Location: ' . md_admin_url('config', array('saved' => 'totp_off')));
                exit;
            } else {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
            }
        } elseif ($act === 'upd') {
            // 版本检查（v1.18.0）：勾选框 = 允许每日自动检查（默认关，零出站）
            if (md_config_set(array('update_check' => !empty($_POST['update_check'])))) {
                header('Location: ' . md_admin_url('config', array('saved' => 'upd')));
                exit;
            }
            $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
        } elseif ($act === 'upd_now') {
            // 「立即检查」：与开关同一表单，按钮只在开关已开时渲染。出站授权以开关为唯一凭据，
            // 关着收到这个 POST（伪造/手工构造）也只落开关、不联网——零出站不留例外通道
            $updOn = !empty($_POST['update_check']);
            if (MD_READONLY) {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
            } elseif (!$updOn) {
                if (md_config_set(array('update_check' => false))) {
                    header('Location: ' . md_admin_url('config', array('saved' => 'upd')));
                    exit;
                }
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
            } elseif (!md_config_set(array('update_check' => true))) {
                $msg = '<div class="alert alert-danger">' . md_e(md_t('admin_err_ro_save')) . '</div>';
            } else {
                $st = md_update_check(true);
                header('Location: ' . md_admin_url('config', array('saved' => $st['ok'] ? 'upd_ok' : 'upd_fail')));
                exit;
            }
        }
    }

    $body = '<div class="admin-stack">' . $msg;
    $saved = isset($_GET['saved']) ? (string)$_GET['saved'] : '';
    $flash = array('site' => 'admin_flash_site', 'user' => 'admin_flash_user', 'pass' => 'admin_flash_pass',
                   'totp_on' => 'admin_flash_totp_on', 'totp_off' => 'admin_flash_totp_off',
                   'cmt' => 'admin_flash_cmt', 'upd' => 'admin_flash_upd',
                   'upd_ok' => 'admin_flash_upd_ok');
    // 失败提示不能走绿色成功样式（upd_fail 是「检查失败」，用警示色）
    $flashWarn = array('upd_fail' => 'admin_flash_upd_fail');
    if (isset($flash[$saved])) {
        $body .= '<div class="alert alert-ok">' . md_e(md_t($flash[$saved])) . '</div>';
    } elseif (isset($flashWarn[$saved])) {
        $body .= '<div class="alert alert-warn">' . md_e(md_t($flashWarn[$saved])) . '</div>';
    }

    // ---- 基本设置 + 白名单（一个表单） ----
    // 主题下拉用主题包显示名（md_theme_list：目录名 => 显示名）
    $themeOpts = '';
    foreach (md_theme_list() as $tDir => $tLabel) {
        $themeOpts .= '<option value="' . md_e($tDir) . '"' . ($tDir === $cfg['theme'] ? ' selected' : '') . '>'
            . md_e($tLabel) . '</option>';
    }
    // 显示模式与主题解耦：主题=风格包，模式=深浅（auto 跟随系统）
    $mode = md_theme_resolve($cfg);
    $modeCur = $mode['mode'];
    $modeOpts = '<option value="auto"' . ($modeCur === 'auto' ? ' selected' : '') . '>' . md_e(md_t('admin_mode_auto')) . '</option>'
        . '<option value="light"' . ($modeCur === 'light' ? ' selected' : '') . '>' . md_e(md_t('admin_mode_light')) . '</option>'
        . '<option value="dark"' . ($modeCur === 'dark' ? ' selected' : '') . '>' . md_e(md_t('admin_mode_dark')) . '</option>';
    // 界面语言：auto 按浏览器 Accept-Language 自动，可强制指定（只翻界面 chrome，不翻译文档内容）
    $langCur = isset($cfg['language']) ? (string)$cfg['language'] : 'auto';
    $langOpts = '<option value="auto"' . ($langCur === 'auto' ? ' selected' : '') . '>' . md_e(md_t('admin_lang_auto')) . '</option>'
        . '<option value="zh-CN"' . ($langCur === 'zh-CN' ? ' selected' : '') . '>简体中文</option>'
        . '<option value="en"' . ($langCur === 'en' ? ' selected' : '') . '>English</option>';
    // sitemap 展示地址：能拼出绝对地址就展示绝对形态（site_url 或按请求推断）
    $suOrigin = md_site_origin($cfg);
    $sitemapAbs = $suOrigin !== '' ? $suOrigin . md_base_path($cfg) . '/?a=sitemap' : '?a=sitemap';
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_settings_basic')) . '</h2></div>'
        . '<div class="admin-card-body"><form method="post" action="' . md_e(md_admin_url('config')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="set_do" value="site">'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="st">' . md_e(md_t('admin_st_title_label')) . '</label>'
        . '<input class="input" type="text" id="st" name="site_title" value="' . md_e($cfg['site_title']) . '"></div>'
        . '<div class="field"><label for="sd">' . md_e(md_t('admin_st_desc_label')) . '</label>'
        . '<input class="input" type="text" id="sd" name="site_desc" value="' . md_e($cfg['site_desc']) . '"></div>'
        . '<div class="field"><label for="th">' . md_e(md_t('admin_st_theme_label')) . '</label>'
        . '<select class="select" id="th" name="theme">' . $themeOpts . '</select>'
        . '<div class="form-hint">' . md_e(md_t('admin_st_theme_hint')) . '</div></div>'
        . '<div class="field"><label for="mode">' . md_e(md_t('admin_st_mode_label')) . '</label>'
        . '<select class="select" id="mode" name="mode">' . $modeOpts . '</select>'
        . '<div class="form-hint">' . md_e(md_t('admin_st_mode_hint')) . '</div></div>'
        . '<div class="field"><label for="lang">' . md_e(md_t('admin_lang_label')) . '</label>'
        . '<select class="select" id="lang" name="language">' . $langOpts . '</select>'
        . '<div class="form-hint">' . md_e(md_t('admin_lang_hint')) . '</div></div>'
        . '<div class="field"><label for="um">' . md_e(md_t('admin_st_urlmode_label')) . '</label>'
        . '<select class="select" id="um" name="url_mode">'
        . '<option value="simple"' . ($cfg['url_mode'] === 'simple' ? ' selected' : '') . '>' . md_e(md_t('admin_url_simple')) . '</option>'
        . '<option value="pretty"' . ($cfg['url_mode'] === 'pretty' ? ' selected' : '') . '>' . md_e(md_t('admin_url_pretty')) . '</option>'
        . '</select></div>'
        . '<div class="field"><label for="bp">' . md_e(md_t('admin_st_basepath_label')) . '</label>'
        . '<input class="input" type="text" id="bp" name="base_path" value="' . md_e($cfg['base_path']) . '" placeholder="/docs"></div>'
        . '<div class="field"><label for="hm">' . md_e(md_t('admin_st_home_label')) . '</label>'
        . '<input class="input" type="text" id="hm" name="home" value="' . md_e($cfg['home']) . '" placeholder="' . md_e(md_t('admin_st_home_ph')) . '"></div>'
        . '</div>'
        . '<div class="admin-checks">'
        . '<label><input type="checkbox" id="sa" name="show_attach" value="1"'
        . (!empty($cfg['show_attach']) ? ' checked' : '') . '>' . md_e(md_t('admin_st_show_attach')) . '</label>'
        . '<label><input type="checkbox" id="op" name="order_prefix" value="1"'
        . (!empty($cfg['order_prefix']) ? ' checked' : '') . '>' . md_e(md_t('admin_st_order_prefix')) . '</label>'
        . '<label><input type="checkbox" id="ca" name="cache" value="1"'
        . (!empty($cfg['cache']) ? ' checked' : '') . '>' . md_e(md_t('admin_st_cache')) . '</label>'
        . '</div>'
        . '<h3 class="admin-sub">' . md_e(md_t('admin_st_seo')) . '</h3>'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="su">' . md_e(md_t('admin_st_siteurl_label')) . '</label>'
        . '<input class="input" type="text" id="su" name="site_url" value="' . md_e($cfg['site_url']) . '"'
        . ' placeholder="https://docs.example.com"></div>'
        . '</div>'
        . '<p class="form-hint">' . md_t('admin_st_seo_hint', array(
            'link' => '<a href="' . md_e($sitemapAbs) . '" target="_blank" rel="noopener">' . md_e($sitemapAbs) . '</a>',
        )) . '</p>'
        . '<h3 class="admin-sub">' . md_e(md_t('admin_st_whitelist')) . '</h3>'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="em">' . md_e(md_t('admin_st_extmd_label')) . '</label>'
        . '<input class="input" type="text" id="em" name="ext_md" value="' . md_e(implode(',', $cfg['ext_md'])) . '"></div>'
        . '<div class="field"><label for="ea">' . md_e(md_t('admin_st_extattach_label')) . '</label>'
        . '<input class="input" type="text" id="ea" name="ext_attach" value="' . md_e(implode(',', $cfg['ext_attach'])) . '"></div>'
        . '<div class="field"><label for="hp">' . md_e(md_t('admin_st_hidden_label')) . '</label>'
        . '<input class="input" type="text" id="hp" name="hidden_prefix" value="' . md_e(implode(',', $cfg['hidden_prefix'])) . '"></div>'
        . '</div>'
        . '<h3 class="admin-sub">' . md_e(md_t('admin_st_ri')) . '</h3>'
        . '<div class="field"><label for="ri">' . md_e(md_t('admin_st_ri_label')) . '</label>'
        . '<textarea class="textarea" id="ri" name="risk_ignore" rows="4" spellcheck="false"'
        . ' placeholder="' . md_e(md_t('admin_st_ri_ph')) . '">' . md_e(implode("\n", $cfg['risk_ignore'])) . '</textarea>'
        . '<div class="form-hint">' . md_e(md_t('admin_st_ri_hint')) . '</div></div>'
        . '<div class="admin-actions-row"><button class="btn btn-primary" type="submit">' . md_e(md_t('admin_save_settings')) . '</button></div>'
        . '</form></div></section>';

    // ---- 评论与通知卡（v1.17.0）：随包休眠——SMTP 主机不填则零外联，一切照旧 ----
    $secOpts = '<option value=""' . ((string)$cfg['smtp_secure'] === '' ? ' selected' : '') . '>' . md_e(md_t('admin_smtp_auto')) . '</option>'
        . '<option value="ssl"' . ((string)$cfg['smtp_secure'] === 'ssl' ? ' selected' : '') . '>' . md_e(md_t('admin_smtp_ssl')) . '</option>'
        . '<option value="none"' . ((string)$cfg['smtp_secure'] === 'none' ? ' selected' : '') . '>' . md_e(md_t('admin_smtp_none')) . '</option>';
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_st_cmt_title')) . '</h2></div>'
        . '<div class="admin-card-body"><form method="post" action="' . md_e(md_admin_url('config')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="set_do" value="cmt">'
        . '<div class="admin-checks mb-2">'
        . '<label><input type="checkbox" name="comments" value="1"' . (!empty($cfg['comments']) ? ' checked' : '') . '>'
        . md_e(md_t('admin_kv_comments')) . '</label>'
        . '</div>'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="cne">' . md_e(md_t('admin_kv_notify')) . '</label>'
        . '<input class="input" type="email" id="cne" name="notify_email" value="' . md_e($cfg['notify_email']) . '">'
        . '<div class="form-hint">' . md_e(md_t('admin_kv_notify_hint')) . '</div></div>'
        . '</div>'
        . '<h3 class="admin-sub">' . md_e(md_t('admin_st_cmt_smtp')) . '</h3>'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="csh">' . md_e(md_t('admin_kv_smtp_host')) . '</label>'
        . '<input class="input" type="text" id="csh" name="smtp_host" value="' . md_e($cfg['smtp_host']) . '" placeholder="smtp.example.com"></div>'
        . '<div class="field"><label for="csport">' . md_e(md_t('admin_kv_smtp_port')) . '</label>'
        . '<input class="input" type="text" id="csport" name="smtp_port" value="' . md_e($cfg['smtp_port']) . '"></div>'
        . '<div class="field"><label for="csu">' . md_e(md_t('admin_kv_smtp_user')) . '</label>'
        . '<input class="input" type="text" id="csu" name="smtp_user" value="' . md_e($cfg['smtp_user']) . '" autocomplete="off"></div>'
        . '<div class="field"><label for="cspp">' . md_e(md_t('admin_kv_smtp_pass')) . '</label>'
        . '<input class="input" type="password" id="cspp" name="smtp_pass" value="" autocomplete="new-password">'
        . '<div class="form-hint">' . md_e(md_t('admin_kv_smtp_pass_keephint')) . '</div></div>'
        . '<div class="field"><label for="csf">' . md_e(md_t('admin_kv_smtp_from')) . '</label>'
        . '<input class="input" type="email" id="csf" name="smtp_from" value="' . md_e($cfg['smtp_from']) . '"></div>'
        . '<div class="field"><label for="cssec">' . md_e(md_t('admin_kv_smtp_secure')) . '</label>'
        . '<select class="select" id="cssec" name="smtp_secure">' . $secOpts . '</select></div>'
        . '</div>'
        . '<p class="form-hint">' . md_e(md_t('admin_st_cmt_hint')) . '</p>'
        . '<div class="admin-actions-row"><button class="btn btn-primary" type="submit">' . md_e(md_t('admin_save_settings')) . '</button></div>'
        . '</form></div></section>';

    // ---- pretty 模式伪静态规则卡（沿用含 $uri/ 坑提示的旧文案） ----
    if ($cfg['url_mode'] === 'pretty') {
        // 第二段规则是安全边界：svg/md/markdown/txt 这类"可执行内容"后缀即使真实文件
        // 存在也不许 Web 服务器直出（直出 = 绕开扩展名白名单 + SVG 脚本同源执行），
        // 必须落进 index.php 由程序判定。图片/视频等惰性类型保持静态直出不吃性能。
        $rules = "# Nginx\nlocation / {\n    try_files \$uri /index.php\$is_args\$args;\n}\n"
            . "# 附件后缀不直出，交 index.php 判定（svg/md/txt 可执行内容防绕过）\n"
            . "location ~* \\\\.(svg|md|markdown|txt)\$ {\n    try_files /nonexistent /index.php\$is_args\$args;\n}\n\n"
            . "# Apache .htaccess\nRewriteEngine On\n"
            . "RewriteRule \\\\.(svg|md|markdown|txt)\$ index.php [QSA,L,NC]\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^ index.php [QSA,L]";
        $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_pretty_title')) . '</h2></div>'
            . '<div class="admin-card-body">'
            . '<p>' . md_t('admin_pretty_hint') . '</p>'
            . '<pre class="admin-code">' . md_e($rules) . '</pre>'
            . '</div></section>';
    }

    // ---- 服务器加固卡（v1.16.0）：_dir 整目录拒绝直访。两种路由模式都需要（不只 pretty）：
    // 程序唯一执行面是 index.php，_dir 里的缓存/修订历史/配置不应被 Web 服务器直接吐出。
    // Apache 已由随程序自动生成/补种的 _dir/.htaccess 覆盖；这里给 Nginx 一条可直接粘贴的规则 ----
    $hardBase = md_base_path($cfg);
    $hardPrefix = (is_string($hardBase) && $hardBase !== '' && $hardBase !== false) ? rtrim($hardBase, '/') : '';
    $hardNginx = '# Nginx：_dir 目录整体禁止直访（与路由模式无关；安装子目录时按实际前缀调整）' . "\n"
        . 'location ^~ ' . $hardPrefix . '/_dir/ {' . "\n    return 404;\n}";
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_hard_title')) . '</h2></div>'
        . '<div class="admin-card-body">'
        . '<p>' . md_t('admin_hard_hint') . '</p>'
        . (is_file(MD_DIR . '/.htaccess')
            ? '<p class="form-hint">' . md_t('admin_hard_htaccess_ok') . '</p>'
            : '<div class="alert alert-warn">' . md_e(md_t('admin_hard_htaccess_ro')) . '</div>')
        . '<pre class="admin-code">' . md_e($hardNginx) . '</pre>'
        . '</div></section>';

    // ---- 新版本检查卡（v1.18.0）：随包休眠——勾选后才联网，出站面只有 GitHub Releases 一处 ----
    $updOn = !empty($cfg['update_check']);
    $updState = md_update_display($cfg);                // 开启时顺带过新鲜期；关闭/只读只读缓存
    if ($updState['checked'] === 0) {
        $updStatus = md_t('admin_upd_never');
    } elseif (!$updState['ok'] || $updState['latest'] === '') {
        $updStatus = md_t('admin_upd_failed');           // 含手工改坏缓存（ok=true 但无版本号）
    } elseif (md_update_is_newer($updState['latest'], MD_VERSION)) {
        $updStatus = md_t('admin_upd_found', array('v' => 'v' . $updState['latest']));
    } else {
        $updStatus = md_t('admin_upd_uptodate');
    }
    // 关闭开关时缓存不会自动刷新，久放的结果加一句「已过期」——不然半年前的结论会被当成现状
    if ($updState['checked'] > 0 && !md_update_fresh($updState)) {
        $updStatus .= md_t('admin_upd_stale');
    }
    $ep = md_update_endpoint();
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_upd_title')) . '</h2></div>'
        . '<div class="admin-card-body"><form method="post" action="' . md_e(md_admin_url('config')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="set_do" value="upd">'
        . '<div class="admin-checks mb-2">'
        . '<label><input type="checkbox" name="update_check" value="1"' . ($updOn ? ' checked' : '') . '>'
        . md_e(md_t('admin_upd_on')) . '</label>'
        . '</div>'
        . '<p class="form-hint">' . md_t('admin_upd_hint') . '</p>'
        . '<div class="admin-wrap"><table class="table admin-kv"><tbody>'
        . '<tr><th>' . md_e(md_t('admin_upd_kv_current')) . '</th><td><code>' . md_e(MD_VERSION) . '</code></td></tr>'
        . '<tr><th>' . md_e(md_t('admin_upd_kv_latest')) . '</th><td>' . md_e($updStatus) . '</td></tr>'
        . '<tr><th>' . md_e(md_t('admin_upd_checked_at')) . '</th><td>'
        . ($updState['checked'] > 0 ? md_e(date('Y-m-d H:i', $updState['checked'])) : '—') . '</td></tr>'
        . '</tbody></table></div>'
        . '<div class="admin-actions-row">'
        . '<button class="btn btn-primary" type="submit">' . md_e(md_t('admin_save_settings')) . '</button>'
        . (($updOn && !MD_READONLY)
            ? '<button class="btn btn-ghost" type="submit" name="set_do" value="upd_now">'
                . md_admin_ico('refresh') . md_e(md_t('admin_upd_now_btn')) . '</button>'
            : '')
        . '<a class="btn btn-ghost" href="' . md_e($ep['page']) . '" target="_blank" rel="noopener">'
        . md_e(md_t('admin_upd_page_btn')) . '</a>'
        . '</div>'
        . '</form></div></section>';

    // ---- 修改账号 ----
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_user_card')) . '</h2></div>'
        . '<div class="admin-card-body"><form method="post" action="' . md_e(md_admin_url('config')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="set_do" value="user">'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="cu-user">' . md_e(md_t('admin_newuser_label')) . '</label>'
        . '<input class="input" type="text" id="cu-user" name="user" value="' . md_e((string)$cfg['admin_user']) . '" required></div>'
        . '<div class="field"><label for="cu-old">' . md_e(md_t('admin_oldpw_verify')) . '</label>'
        . '<input class="input" type="password" id="cu-old" name="old" required autocomplete="current-password"></div>'
        . '</div>'
        . '<p class="form-hint">' . md_e(md_t('admin_user_case_hint')) . '</p>'
        . '<div class="admin-actions-row"><button class="btn btn-primary" type="submit">' . md_e(md_t('admin_user_card')) . '</button></div>'
        . '</form></div></section>';

    // ---- 修改密码 ----
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_pw_card')) . '</h2></div>'
        . '<div class="admin-card-body"><form method="post" action="' . md_e(md_admin_url('config')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="set_do" value="pass">'
        . '<div class="admin-grid">'
        . '<div class="field"><label for="cp-old">' . md_e(md_t('admin_oldpw_label')) . '</label>'
        . '<input class="input" type="password" id="cp-old" name="old" required autocomplete="current-password"></div>'
        . '<div class="field"><label for="cp-new">' . md_e(md_t('admin_setup_pw_label')) . '</label>'
        . '<input class="input" type="password" id="cp-new" name="new" minlength="8" required autocomplete="new-password"></div>'
        . '<div class="field"><label for="cp-new2">' . md_e(md_t('admin_setup_pw2_label')) . '</label>'
        . '<input class="input" type="password" id="cp-new2" name="new2" minlength="8" required autocomplete="new-password"></div>'
        . '</div>'
        . '<div class="admin-actions-row"><button class="btn btn-primary" type="submit">' . md_e(md_t('admin_pw_card')) . '</button></div>'
        . '</form></div></section>';

    // ---- 两步验证（TOTP）：启用两段式（会话密钥 + 动态码确认），禁用要动态码 ----
    $totpOn = (string)$cfg['admin_totp'] !== '';
    $totpNew = isset($_SESSION['md_totp_new']) ? (string)$_SESSION['md_totp_new'] : '';
    $totpScripts = array();
    $body .= '<section class="card"><div class="admin-card-head"><h2>' . md_e(md_t('admin_totp_card')) . '</h2></div>'
        . '<div class="admin-card-body">';
    if ($totpOn) {
        $body .= '<p><strong>' . md_e(md_t('admin_totp_status_on')) . '</strong></p>'
            . '<p class="form-hint">' . md_e(md_t('admin_totp_recovery_hint')) . '</p>'
            . '<form method="post" action="' . md_e(md_admin_url('config')) . '">'
            . md_csrf_field()
            . '<input type="hidden" name="set_do" value="totp_disable">'
            . '<div class="admin-grid">'
            . '<div class="field"><label for="td-code">' . md_e(md_t('admin_totp_code_label')) . '</label>'
            . '<input class="input admin-totp-input" type="text" id="td-code" name="code"'
            . ' inputmode="numeric" autocomplete="one-time-code" maxlength="12" required></div>'
            . '</div>'
            . '<div class="admin-actions-row"><button class="btn btn-danger" type="submit">' . md_e(md_t('admin_totp_disable_btn')) . '</button></div>'
            . '</form>';
    } elseif ($totpNew !== '') {
        $totpScripts = array(md_cdn_active($cfg)['qrcode']);
        $body .= '<p class="form-hint">' . md_e(md_t('admin_totp_setup_intro')) . '</p>'
            . '<div class="admin-qr" data-totp-qr="' . md_e(md_totp_uri($totpNew, $cfg)) . '"'
            . ' role="img" aria-label="' . md_e(md_t('admin_totp_qr_aria')) . '"></div>'
            . '<div class="field"><label>' . md_e(md_t('admin_totp_secret_label')) . '</label>'
            . '<code class="admin-code-inline">' . md_e(trim(chunk_split($totpNew, 4, ' '))) . '</code>'
            . '<div class="form-hint">' . md_e(md_t('admin_totp_secret_hint')) . '</div></div>'
            . '<form method="post" action="' . md_e(md_admin_url('config')) . '">'
            . md_csrf_field()
            . '<input type="hidden" name="set_do" value="totp_enable">'
            . '<div class="admin-grid">'
            . '<div class="field"><label for="te-code">' . md_e(md_t('admin_totp_code_label')) . '</label>'
            . '<input class="input admin-totp-input" type="text" id="te-code" name="code"'
            . ' inputmode="numeric" autocomplete="one-time-code" maxlength="12" required></div>'
            . '</div>'
            . '<div class="admin-actions-row">'
            . '<button class="btn btn-primary" type="submit">' . md_e(md_t('admin_totp_confirm_btn')) . '</button> '
            . '<button class="btn btn-ghost" type="submit" name="set_do" value="totp_cancel">' . md_e(md_t('admin_totp_cancel_btn')) . '</button>'
            . '</div>'
            . '</form>';
    } else {
        $body .= '<p class="form-hint">' . md_e(md_t('admin_totp_off_hint')) . '</p>'
            . '<form method="post" action="' . md_e(md_admin_url('config')) . '">'
            . md_csrf_field()
            . '<input type="hidden" name="set_do" value="totp_setup">'
            . '<div class="admin-actions-row"><button class="btn btn-primary" type="submit">' . md_e(md_t('admin_totp_enable_btn')) . '</button></div>'
            . '</form>';
    }
    $body .= '</div></section>';

    $body .= '</div>';
    md_admin_page(md_t('admin_nav_settings'), $body, 'config', $totpScripts);
}

// ---------------------------------------------------------------- 版本检查（v1.18.0）

/**
 * 新版本检查（默认关闭，后台显式开启后才联网）。
 *
 * 出站面只有一处：GET api.github.com/repos/<仓库>/releases/latest 读 tag_name
 * （仓库取自 md_project_repo()），
 * 请求头只有固定的 User-Agent 与 Accept，不带任何站点/用户数据，无查询串、无 cookie。
 * 结果落 _dir/cache/update.json：成功 24 小时、失败 6 小时后才重试——每个安装每天至多
 * 一次出站请求，其余都是本地文件读（这也是它敢在页面渲染期同步调用的原因）。
 * 联网失败一律安静降级为「检查失败」，绝不阻断后台任何功能；纯内网部署保持关闭即可。
 *
 * 端点写死在程序里、不接受任何外部输入；「查看发行版」链接也由本地常量拼出，
 * 不采用应答里的 url 字段——应答内容只用来取版本号，不参与任何输出。
 */

/**
 * 检查目标（API / 人读的发行页）：仓库取自 md_project_repo()，全程序唯一出处。
 * 两条地址都由本地常量拼出，不采用应答里的任何 url 字段。
 */
function md_update_endpoint()
{
    return array(
        'api'  => 'https://api.github.com/repos/' . md_project_repo() . '/releases/latest',
        'page' => md_project_url('releases/latest'),
    );
}

/** 检查结果缓存文件；$base 供测试注入临时目录，线上恒走默认。 */
function md_update_cache_file($base = null)
{
    return ($base === null ? MD_DIR . '/cache' : (string)$base) . '/update.json';
}

/** 读缓存：checked=0 表示从未检查过（与「检查失败」是两回事）。 */
function md_update_state($base = null)
{
    $f = md_update_cache_file($base);
    $d = is_file($f) ? json_decode((string)md_read_text($f), true) : null;
    if (!is_array($d) || !isset($d['checked'])) {
        return array('ok' => false, 'latest' => '', 'checked' => 0);
    }
    return array(
        'ok'      => !empty($d['ok']),
        'latest'  => isset($d['latest']) ? (string)$d['latest'] : '',
        'checked' => (int)$d['checked'],
    );
}

/** 写缓存（原子替换）：只存三样，应答原文绝不落盘。 */
function md_update_save(array $state, $base = null)
{
    if (MD_READONLY) {
        return false;
    }
    $dir = $base === null ? MD_DIR . '/cache' : (string)$base;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return md_write_file($dir . '/update.json', json_encode(array(
        'ok'      => !empty($state['ok']),
        'latest'  => (string)$state['latest'],
        'checked' => (int)$state['checked'],
    )));
}

/** 解析 Release 应答（纯函数，可测）：取 tag_name 并只认 1.2 / 1.2.3 形态。
 *  预发布（1.18.0-rc1）、草稿、异常应答一律返回空串——不把预发布当正式版提示。 */
function md_update_parse($body)
{
    $d = json_decode((string)$body, true);
    if (!is_array($d) || !isset($d['tag_name'])) {
        return '';
    }
    if (preg_match('/^v?(\d+\.\d+(?:\.\d+)?)$/', trim((string)$d['tag_name']), $m) !== 1) {
        return '';
    }
    return $m[1];
}

/** 版本比较（纯函数，可测）：$latest 是否严格新于 $current。
 *  任何一边不是版本号（如测试期的 MD_VERSION='test'）都返回 false——不漏报也不误报。 */
function md_update_is_newer($latest, $current)
{
    $re = '/^\d+(?:\.\d+){1,3}$/';
    if (preg_match($re, (string)$latest) !== 1 || preg_match($re, (string)$current) !== 1) {
        return false;
    }
    return version_compare((string)$latest, (string)$current, '>');
}

/**
 * 出站 GET：curl 优先，缺失时回落 allow_url_fopen 流封装，两条通道都带超时上限。
 * 只接受 HTTP 200；证书校验保持默认开启，4xx/5xx 与网络错误一律返回空串。
 * $api 供测试指向本地桩服务，线上恒走 md_update_endpoint()。
 */
function md_update_fetch($api)
{
    $headers = array('Accept: application/vnd.github+json', 'User-Agent: DirDocs/' . MD_VERSION);
    if (function_exists('curl_init')) {
        $ch = curl_init((string)$api);
        if ($ch === false) {
            return '';
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);   // 默认即开，写出来防后人误关
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body === false || $code !== 200) ? '' : (string)$body;
    }
    if (!ini_get('allow_url_fopen')) {
        return '';                                      // 两条通道都没有：安静失败
    }
    $ctx = stream_context_create(array('http' => array(
        'method'  => 'GET',
        'header'  => implode("\r\n", $headers) . "\r\n",
        'timeout' => 6,
        // 保持默认的「状态码非 2xx 即失败」：4xx/5xx 的正文（含限流说明）不参与解析
        'ignore_errors' => false,
    )));
    $body = @file_get_contents((string)$api, false, $ctx);
    return $body === false ? '' : (string)$body;
}

/** 缓存是否仍在新鲜期内：成功 24h、失败 6h（失败也要退避，不然每次都白等一遍网络超时）。 */
function md_update_fresh($state)
{
    if (!is_array($state) || $state['checked'] <= 0) {
        return false;
    }
    return (time() - (int)$state['checked']) < (!empty($state['ok']) ? 86400 : 21600);
}

/**
 * 检查并更新缓存。新鲜期内直接回缓存，$force 忽略新鲜期。
 * 返回 array(ok, latest, checked)；ok=false 表示本次没取到版本号。
 * $api / $base 供测试注入，线上恒走默认端点与 _dir/cache/。
 */
function md_update_check($force = false, $api = null, $base = null)
{
    $state = md_update_state($base);
    if (!$force && md_update_fresh($state)) {
        return $state;
    }
    $ep = md_update_endpoint();
    $latest = md_update_parse(md_update_fetch($api === null ? $ep['api'] : (string)$api));
    $new = array('ok' => $latest !== '', 'latest' => $latest, 'checked' => time());
    md_update_save($new, $base);
    return $new;
}

/**
 * 页面调用入口（会过新鲜期、可能发一次出站请求）：未开启返回 null（连缓存都不读）；
 * 只读模式只回显已存结果、绝不发请求。会话级节流是缓存写失败时的兜底——否则磁盘满 /
 * 权限抖动会让后台每次加载都在网络超时上等一遍。
 */
function md_update_auto($cfg)
{
    if (empty($cfg['update_check'])) {
        return null;
    }
    if (MD_READONLY) {
        $s = md_update_state();
        return $s['checked'] > 0 ? $s : null;
    }
    $now = time();
    $gated = (session_status() === PHP_SESSION_ACTIVE) && isset($_SESSION['md_upd_gate']);
    if ($gated && (int)$_SESSION['md_upd_gate'] > $now - 3600) {
        return md_update_state();                       // 本会话一小时内已试过，不再联网
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['md_upd_gate'] = $now;
    }
    return md_update_check();
}

/**
 * 设置卡用状态：开启时走 md_update_auto（可能顺带刷新一次），关闭/只读时只读本地缓存。
 * 显示与联网是两件事——不联网也把上次结果摆出来，由页面标注是否过期。
 */
function md_update_display($cfg)
{
    $auto = md_update_auto($cfg);
    return is_array($auto) ? $auto : md_update_state();
}

/** 概览「程序版本」行的补充标记：仅在有更新版本时输出一枚可点徽标，其余情况空串。
 *  $current 供测试注入（测试期 MD_VERSION 是 'test'，不参与版本比较）。 */
function md_update_badge($state, $current = null)
{
    if (!is_array($state) || empty($state['ok']) || $state['latest'] === ''
        || !md_update_is_newer($state['latest'], $current === null ? MD_VERSION : $current)) {
        return '';
    }
    $ep = md_update_endpoint();
    return ' <a class="badge admin-upd-badge" href="' . md_e($ep['page']) . '" target="_blank" rel="noopener">'
        . md_e(md_t('admin_upd_new', array('v' => 'v' . $state['latest']))) . '</a>';
}

// ---------------------------------------------------------------- 分发

/**
 * 后台总入口（index.php 以 md_admin_dispatch($MD_CFG) 调用）。
 * 出口顺序：登出（不要求登录态，防探测）→ 首设引导（admin_pass 为空时）→ 登录 → 各功能页。
 */
function md_admin_dispatch($cfg)
{
    md_session_start();

    // 登出：无需登录态（防止用登出接口探测谁在线），完成后回登录页
    if (isset($_GET['do']) && $_GET['do'] === 'logout') {
        md_logout();
        header('Location: ' . md_admin_url(''));
        exit;
    }

    // 首次使用：未设密码时引导设置账号密码（此时后台只有这一个页面）
    if ((string)$cfg['admin_pass'] === '') {
        md_admin_setup($cfg);                           // 内部处理 GET/POST，必然 exit
    }

    // 登录提交（未登录也可达）
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do']) && $_POST['do'] === 'login') {
        md_admin_login_submit($cfg);
    }

    // TOTP 第二步（登录流程的两步验证，与登录同级：未登录也可达，但只对 pending 会话有内容）。
    // POST 分支必须在前——表单 action 带 do=totp，GET 分支若先拦会把提交渲染成空页
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do']) && $_POST['do'] === 'totp') {
        md_admin_totp_submit($cfg);
    }
    if (isset($_GET['do']) && $_GET['do'] === 'totp') {
        md_admin_totp_page();
    }

    // 以下一切页面都要求已登录
    md_admin_guard();

    $tree = md_tree_load($cfg);
    $do = isset($_GET['do']) ? (string)$_GET['do'] : '';
    switch ($do) {
        case '':
            md_admin_home($tree, $cfg);
            break;
        case 'docs':
            md_admin_docs($tree, $cfg);
            break;
        case 'comments':
            md_admin_comments($tree, $cfg);
            break;
        case 'new':
        case 'edit':
            md_admin_doc_edit($cfg);
            break;
        case 'rename':
            md_admin_doc_rename($cfg);
            break;
        case 'copy':
            md_admin_doc_copy($cfg);
            break;
        case 'history':
            md_admin_history($cfg);
            break;
        case 'delete':
            md_admin_doc_delete($cfg);
            break;
        case 'dirnew':
            md_admin_dir_new($cfg);
            break;
        case 'dirrename':
            md_admin_dir_rename($cfg);
            break;
        case 'dirdel':
            md_admin_dir_del($cfg);
            break;
        case 'attach':
            md_admin_attach($tree, $cfg);
            break;
        case 'attachdel':
            md_admin_attach_delete($cfg);
            break;
        case 'cache':
            md_admin_cache($cfg);
            break;
        case 'config':
            md_admin_settings($cfg);
            break;
        default:
            md_abort(404);
    }
}

