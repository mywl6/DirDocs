<?php
/**
 * DirDocs MCP（Model Context Protocol）模块（v1.20.0，由 index.php 加载，勿单独访问）
 *
 * 端点：POST ?a=mcp，JSON-RPC 2.0。走 Streamable HTTP 传输的无状态形态：不建会话
 * （忽略 Mcp-Session-Id）、不开 SSE——tools 一问一答纯 JSON 应答，官方客户端均可直连。
 *
 * 工具 5 个（v1.20.0）：list_docs / read_doc / search_docs（只读）+
 * write_doc（写，默认落草稿）+ get_site_info（辅助）。upload_media 暂缓：
 * 价值最低而攻击面新增最明显，且 SVG 要与 Sec-Fetch-Dest 策略重新对账。
 *
 * 设计红线（与 README「安全设计」逐条对应）：
 *   - 认证前置：无 Bearer token 一律 401，不进入协议处理；token 只存 password_hash
 *     哈希，明文仅在后台生成时展示一次；记录存 _dir/config.php 的 mcp_tokens 键
 *     （与口令哈希/TOTP 同一张表、同一个找回路径）；
 *   - scope 分离：read token 调写工具返回权限错误；write_doc 默认强制 draft: true，
 *     与后台编辑走同一条保存路径（md_doc_save：front-matter 校验/路径与扩展名白名单/
 *     文件名安全比对/原子写/修订 20 版/缓存失效），不新造存储逻辑、不开旁路；
 *   - HTTPS 闸门：请求没有 https 信号且来源非本机/内网时 403。判定不信任
 *     X-Forwarded-Proto（与 v1.6.0 SEO 决策同一红线）；反代 TLS 终止的场景在后台
 *     显式配置 site_url（https://）即放行，本机/局域网 http 访问不受限；
 *   - 双维限流：token+IP，读 600 次/时、写 60 次/时，超限 429；
 *   - 写操作留痕：cache/mcp-log.jsonl（时间/token 名/工具/路径/结果），不记正文；
 *   - 体积闸：单请求 body ≤ 2MB、单文档 ≤ 1MB；
 *   - 错误文案走 md_t() 中英双语。
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}

define('MD_MCP_PROTOCOL', '2025-06-18');            // 实现所依据的 MCP 规范版本
define('MD_MCP_BODY_MAX', 2 * 1024 * 1024);         // 单请求 body 上限
define('MD_MCP_DOC_MAX', 1024 * 1024);              // 单文档写入上限
define('MD_MCP_TOKENS_MAX', 20);                    // token 数量上限
define('MD_MCP_RL_READ', 600);                      // 读取限流：次/小时
define('MD_MCP_RL_WRITE', 60);                      // 写入限流：次/小时
define('MD_MCP_LOG_MAX', 1000);                     // 日志保留行数（超限裁剪）

// ---------------------------------------------------------------- token 体系

/**
 * token 记录归一化（config 写坏不报错）：只保形态合法的条目，scope 收口 read/write，
 * 数量封顶。判定与 md_config_load 对其余键的守卫同型。
 */
function md_mcp_tokens_normalize($list)
{
    $out = array();
    if (!is_array($list)) {
        return $out;
    }
    foreach ($list as $id => $t) {
        if (!is_array($t) || !isset($t['hash']) || !is_string($t['hash']) || $t['hash'] === '') {
            continue;
        }
        $id = preg_match('/^[0-9a-f]{1,16}$/', (string)$id) === 1
            ? (string)$id : substr(md5((string)$id), 0, 8);
        $out[$id] = array(
            'name'    => isset($t['name']) && is_string($t['name']) ? substr($t['name'], 0, 120) : '',
            'hash'    => $t['hash'],
            'scope'   => (isset($t['scope']) && $t['scope'] === 'write') ? 'write' : 'read',
            'created' => isset($t['created']) ? (int)$t['created'] : 0,
            'last'    => isset($t['last']) ? (int)$t['last'] : 0,
        );
        if (count($out) >= MD_MCP_TOKENS_MAX) {
            break;
        }
    }
    return $out;
}

/**
 * 生成一个 token（纯函数，不落盘——落盘由后台页经 md_config_set 完成）。
 * 明文只在返回值里出现一次；记录里存 password_hash 哈希，id 为 8 位 hex。
 */
function md_mcp_token_build($name, $scope)
{
    $name = trim(strip_tags((string)$name));
    if ($name === '') {
        $name = 'token';
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 40, 'UTF-8');
    } else {
        $name = substr($name, 0, 80);
    }
    if ($scope !== 'write') {
        $scope = 'read';                                // 白名单外一律按只读收口
    }
    $plain = 'ddmcp_' . bin2hex(random_bytes(24));
    return array(
        'id'     => bin2hex(random_bytes(4)),
        'plain'  => $plain,
        'record' => array(
            'name'    => $name,
            'hash'    => password_hash($plain, PASSWORD_DEFAULT),
            'scope'   => $scope,
            'created' => time(),
            'last'    => 0,
        ),
    );
}

/** 校验 Bearer 明文：命中返回 array(id/name/scope)，不命中 false。逐条 password_verify。 */
function md_mcp_token_verify($plain, $tokens)
{
    $plain = (string)$plain;
    if ($plain === '' || strlen($plain) > 128) {
        return false;
    }
    foreach ($tokens as $id => $t) {
        if (isset($t['hash']) && is_string($t['hash']) && password_verify($plain, $t['hash'])) {
            return array(
                'id'    => (string)$id,
                'name'  => isset($t['name']) ? (string)$t['name'] : '',
                'scope' => isset($t['scope']) ? (string)$t['scope'] : 'read',
            );
        }
    }
    return false;
}

/** 更新最近使用时间：60 秒内不重复落盘（避免每个 API 请求都写一次 config.php）。 */
function md_mcp_token_touch($id)
{
    $cfg = md_config_load();
    if (!isset($cfg['mcp_tokens'][$id]['last'])) {
        return;
    }
    if (time() - (int)$cfg['mcp_tokens'][$id]['last'] < 60) {
        return;
    }
    $tokens = $cfg['mcp_tokens'];
    $tokens[$id]['last'] = time();
    md_config_set(array('mcp_tokens' => $tokens));
}

/** 吊销 token：id 过严格 hex 白名单再 unset。成功 true；id 不存在 false。 */
function md_mcp_token_delete($id)
{
    if (preg_match('/^[0-9a-f]{1,16}$/', (string)$id) !== 1) {
        return false;
    }
    $cfg = md_config_load();
    if (!isset($cfg['mcp_tokens'][$id])) {
        return false;
    }
    $tokens = $cfg['mcp_tokens'];
    unset($tokens[$id]);
    return md_config_set(array('mcp_tokens' => $tokens));
}

// ---------------------------------------------------------------- 限流（token + IP 双维）

function md_mcp_rl_file($base = null)
{
    return ($base !== null ? $base : MD_DIR . '/cache') . '/mcp-rl.json';
}

/**
 * 限流判定（纯核，可测）：按 (token|ip哈希|小时桶) 计数。$kind 'r'/'w'。
 * 返回 array(是否放行, 新桶数组, 建议重试秒数)。超限后重试秒数 = 距下个整点小时。
 */
function md_mcp_rl_decide($buckets, $key, $kind, $now, $limitRead, $limitWrite)
{
    $win = (int)floor($now / 3600);
    $full = $key . '|' . $win;
    $cur = (isset($buckets[$full]) && is_array($buckets[$full])) ? $buckets[$full] : array();
    $n = isset($cur[$kind]) ? (int)$cur[$kind] : 0;
    $limit = ($kind === 'w') ? $limitWrite : $limitRead;
    if ($n >= $limit) {
        $retry = ($win + 1) * 3600 - $now;
        return array(false, $buckets, $retry > 0 ? $retry : 1);
    }
    $cur[$kind] = $n + 1;
    $buckets[$full] = $cur;
    foreach ($buckets as $k => $v) {                    // 只留当前小时桶，文件不膨胀
        if (is_string($k) && substr($k, strrpos($k, '|') + 1) !== (string)$win) {
            unset($buckets[$k]);
        }
    }
    return array(true, $buckets, 0);
}

/**
 * 限流落账：读 cache/mcp-rl.json → 纯核判定 → 写回。返回 array(放行?, 重试秒)。
 * $base 仅供测试注入存储目录。只读安装无处记账即放行——写工具在保存层本来就会被拒，
 * 读不设防可接受。
 */
function md_mcp_rl_hit($tokId, $ipHash, $kind, $base = null)
{
    if (MD_READONLY) {
        return array(true, 0);
    }
    $f = md_mcp_rl_file($base);
    $buckets = array();
    $d = @file_get_contents($f);
    if (is_string($d)) {
        $j = json_decode($d, true);
        if (is_array($j) && isset($j['b']) && is_array($j['b'])) {
            $buckets = $j['b'];
        }
    }
    $r = md_mcp_rl_decide($buckets, (string)$tokId . '|' . (string)$ipHash, $kind,
                          time(), MD_MCP_RL_READ, MD_MCP_RL_WRITE);
    if ($r[0]) {
        $dir = dirname($f);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($f, json_encode(array('b' => $r[1])), LOCK_EX);
    }
    return array($r[0], $r[2]);
}

// ---------------------------------------------------------------- 调用日志

function md_mcp_log_file($base = null)
{
    return ($base !== null ? $base : MD_DIR . '/cache') . '/mcp-log.jsonl';
}

/**
 * 写操作留痕（v1.20.0 红线）：一行一条 JSON（时间/token 名/工具/路径/结果），
 * 不记正文。超过阈值 + 余量时整体裁到最近 MD_MCP_LOG_MAX 行。$base 仅供测试注入。
 */
function md_mcp_log($tokName, $tool, $path, $ok, $base = null)
{
    if (MD_READONLY) {
        return false;
    }
    $line = json_encode(array(
        't'    => date('Y-m-d H:i:s'),
        'tok'  => (string)$tokName,
        'tool' => (string)$tool,
        'path' => (string)$path,
        'ok'   => $ok ? 1 : 0,
    ), JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return false;
    }
    $f = md_mcp_log_file($base);
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (@file_put_contents($f, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        return false;
    }
    $d = @file_get_contents($f);
    if (is_string($d) && substr_count($d, "\n") > MD_MCP_LOG_MAX + 100) {
        $lines = explode("\n", $d);
        $lines = array_slice($lines, count($lines) - MD_MCP_LOG_MAX);
        @file_put_contents($f, implode("\n", $lines), LOCK_EX);
    }
    return true;
}

/** 后台日志展示：读最近 $n 条，最新在前；损坏行跳过。$base 仅供测试注入。 */
function md_mcp_log_tail($n = 20, $base = null)
{
    $d = @file_get_contents(md_mcp_log_file($base));
    if (!is_string($d) || $d === '') {
        return array();
    }
    $lines = explode("\n", trim($d));
    $out = array();
    for ($i = count($lines) - 1; $i >= 0 && count($out) < $n; $i--) {
        if ($lines[$i] === '') {
            continue;
        }
        $j = json_decode($lines[$i], true);
        if (is_array($j) && isset($j['t'], $j['tool'])) {
            $out[] = $j;
        }
    }
    return $out;
}

// ---------------------------------------------------------------- HTTPS 闸门（纯函数，可测）

/**
 * 本机/内网来源判定：回环（IPv4/IPv6）、RFC1918 私网、链路本地。
 * 公网单播返回 false。IPv6 私有地址（fc00::/7）按外网处理——站点鲜见，从严。
 */
function md_mcp_ip_is_local($ip)
{
    $ip = trim((string)$ip);
    if ($ip === '') {
        return false;
    }
    if ($ip === '::1') {
        return true;
    }
    if (md_starts_with($ip, '127.') || md_starts_with($ip, '::ffff:127.')) {
        return true;
    }
    if (preg_match('/^10\./', $ip) === 1 || md_starts_with($ip, '192.168.')
        || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip) === 1) {
        return true;
    }
    if (md_starts_with($ip, '169.254.') || md_starts_with($ip, 'fe80:')) {
        return true;
    }
    return false;
}

/**
 * HTTPS 闸门判定（纯函数）：任一 https 信号成立即放行——
 *   PHP 侧 HTTPS=on、监听端口 443、站长显式配置 site_url 为 https://（反代 TLS 终止
 *   时 PHP 侧看不到 https，站长配置是唯一权威信号——X-Forwarded-Proto 可被请求方
 *   伪造，不采信，与 v1.6.0 canonical 决策同一红线）。
 *   都没有时，本机/内网来源放行（开发与局域网部署形态），公网明文 http 一律 403。
 */
function md_mcp_https_decide($server, $siteUrl)
{
    $https = isset($server['HTTPS']) && $server['HTTPS'] !== '' && $server['HTTPS'] !== 'off';
    if (!$https && isset($server['SERVER_PORT']) && (int)$server['SERVER_PORT'] === 443) {
        $https = true;
    }
    if ($https) {
        return true;
    }
    if (stripos((string)$siteUrl, 'https://') === 0) {
        return true;
    }
    $ip = isset($server['REMOTE_ADDR']) ? (string)$server['REMOTE_ADDR'] : '';
    return md_mcp_ip_is_local($ip);
}

// ---------------------------------------------------------------- front-matter 合并（纯函数，可测）

/**
 * write_doc 的 front-matter 合并：
 *   - $patch（array 键 => 标量）：已有 FM 的改写对应行/追加新键，无 FM 的生成标准头；
 *   - draft 决策（优先级从高到低）：publish=true → 不写 draft 键（发布意图最高）；
 *     patch 显式带 draft → 按其真值（false = 删除键）；否则强制 draft: true（AI 写入默认草稿）；
 *   - 值只接受标量：bool 转 true/false，其余压成单行；非法键（空/带冒号）丢弃；
 *   - 内容以 --- 开头但 FM 未闭合时原样返回，交由 md_doc_save 的校验报错（AI 能看到真实原因）。
 */
function md_mcp_fm_merge($content, $patch, $publish)
{
    $content = (string)$content;
    $kv = array();
    if (is_array($patch)) {
        foreach ($patch as $k => $v) {
            $k = strtolower(trim((string)$k));
            if ($k === '' || strpos($k, ':') !== false || !is_scalar($v)) {
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            } else {
                $v = trim(str_replace(array("\r", "\n"), ' ', (string)$v));
            }
            if ($v !== '') {
                $kv[$k] = $v;
            }
        }
    }
    $draftLine = 'draft: true';
    if (isset($kv['draft'])) {
        $d = in_array(strtolower($kv['draft']), array('true', '1', 'yes', 'on'), true);
        unset($kv['draft']);
        $draftLine = $d ? 'draft: true' : '';
    }
    if ($publish) {
        $draftLine = '';
    }

    $re = '/\A---\r?\n(.*?)\r?\n---(?:\r?\n|$)/s';
    if (md_starts_with($content, "---\n") || md_starts_with($content, "---\r\n")) {
        if (preg_match($re, $content, $m) !== 1) {
            return $content;                            // 未闭合 FM：原样返回，保存层报错
        }
        $out = array();
        foreach (preg_split('/\r?\n/', $m[1]) as $ln) {
            $pos = strpos($ln, ':');
            $key = $pos === false ? '' : strtolower(trim(substr($ln, 0, $pos)));
            if ($key === 'draft') {
                continue;                               // draft 由本函数统一裁决，剥掉旧行防重复
            }
            if ($key !== '' && array_key_exists($key, $kv)) {
                $out[] = $key . ': ' . $kv[$key];
                unset($kv[$key]);
            } else {
                $out[] = $ln;
            }
        }
        foreach ($kv as $k => $v) {
            $out[] = $k . ': ' . $v;
        }
        if ($draftLine !== '') {
            $out[] = $draftLine;
        }
        return "---\n" . implode("\n", $out) . "\n---\n" . substr($content, strlen($m[0]));
    }

    $lines = array();
    foreach ($kv as $k => $v) {
        $lines[] = $k . ': ' . $v;
    }
    if ($draftLine !== '') {
        $lines[] = $draftLine;
    }
    if (!$lines) {
        return $content;
    }
    return "---\n" . implode("\n", $lines) . "\n---\n\n" . $content;
}

/** 写入路径归一（纯函数）：反斜杠归一、剥首尾斜杠；无后缀自动补 .md。安全校验在 md_doc_save。 */
function md_mcp_norm_path($path)
{
    $p = str_replace('\\', '/', trim((string)$path));
    $p = trim($p, '/');
    if ($p === '' || md_ext($p) === '') {
        $p .= '.md';
    }
    return $p;
}

// ---------------------------------------------------------------- JSON-RPC 2.0 应答

function md_mcp_rpc_result($id, $result)
{
    return array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result);
}

function md_mcp_rpc_error($id, $code, $message, $data = null)
{
    $e = array('code' => (int)$code, 'message' => (string)$message);
    if ($data !== null) {
        $e['data'] = $data;
    }
    return array('jsonrpc' => '2.0', 'id' => $id, 'error' => $e);
}

/** 输出 JSON 并终止（HTTP 层错误也带 JSON-RPC 形态的 body，便于客户端诊断）。 */
function md_mcp_send($http, $payload)
{
    http_response_code((int)$http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/** tools/call 结果包裹：业务负载序列化为 JSON 字符串进 content[0].text（MCP 约定形态）。 */
function md_mcp_tool_result($payload, $isError = false)
{
    $text = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    if ($text === false) {
        $text = '{}';
    }
    $r = array('content' => array(array('type' => 'text', 'text' => $text)));
    if ($isError) {
        $r['isError'] = true;
    }
    return $r;
}

// ---------------------------------------------------------------- 工具定义

/** 工具清单（描述面向 AI，用英文；inputSchema 为 JSON Schema 的最小子集）。 */
function md_mcp_tools()
{
    return array(
        array(
            'name' => 'list_docs',
            'description' => 'List all documents on this DirDocs site as a flat tree: paths, titles, '
                . 'modification times and draft flags, plus the folder structure. Drafts are included '
                . 'and flagged. Optionally limit to a subdirectory with dir.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'dir' => array('type' => 'string',
                        'description' => 'Optional directory prefix relative to the doc root, e.g. "guides".'),
                ),
                'additionalProperties' => false,
            ),
        ),
        array(
            'name' => 'read_doc',
            'description' => "Read a single markdown document's full content, including its front matter "
                . 'and draft flag. Use the exact path returned by list_docs or write_doc.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'path' => array('type' => 'string',
                        'description' => 'Document path relative to the doc root, e.g. "guides/intro" (the .md extension is optional).'),
                ),
                'required' => array('path'),
                'additionalProperties' => false,
            ),
        ),
        array(
            'name' => 'search_docs',
            'description' => "Full-text search across published documents (same engine as the site's "
                . 'Ctrl+K search). Drafts are not indexed. Returns paths, titles and snippets.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'q' => array('type' => 'string', 'description' => 'Search keywords.'),
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50,
                        'description' => 'Max results to return (default 10).'),
                ),
                'required' => array('q'),
                'additionalProperties' => false,
            ),
        ),
        array(
            'name' => 'write_doc',
            'description' => 'Create or overwrite a markdown document. Saved as DRAFT (hidden from '
                . "navigation/search/RSS) until reviewed and published in the admin, unless publish=true "
                . 'or front_matter explicitly sets draft. Previous versions are kept automatically '
                . '(last 20 revisions). Missing parent directories are created. Returns the document URL.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'path' => array('type' => 'string',
                        'description' => 'Target path relative to the doc root. The .md extension is appended when missing.'),
                    'content' => array('type' => 'string',
                        'description' => 'Markdown content of the document (max 1MB).'),
                    'front_matter' => array('type' => 'object',
                        'additionalProperties' => array('type' => array('string', 'number', 'boolean')),
                        'description' => 'Optional key/value map merged into the front matter (title, order, ...).'),
                    'publish' => array('type' => 'boolean',
                        'description' => 'Set true to publish immediately instead of saving as draft (default false).'),
                ),
                'required' => array('path', 'content'),
                'additionalProperties' => false,
            ),
        ),
        array(
            'name' => 'get_site_info',
            'description' => 'Get site name, language, version, document counts, read-only status '
                . "and the current token's scope. Call this first to learn about the site.",
            'inputSchema' => array(
                'type' => 'object',
                // 空 properties 必须 cast 成 object：空 PHP 数组会编码成 []，
                // 客户端按 JSON Schema 校验 properties 为 record 时会拒收（ping 同款坑）
                'properties' => (object)array(),
                'additionalProperties' => false,
            ),
        ),
    );
}

// ---------------------------------------------------------------- 工具实现

function md_mcp_call_list_docs($args, $cfg)
{
    $dir = '';
    if (isset($args['dir']) && is_string($args['dir'])) {
        $dir = trim(str_replace('\\', '/', $args['dir']), '/');
    }
    if ($dir !== '' && md_resolve_path_only($dir) === false) {
        return md_mcp_tool_result(array('error' => md_t('mcp_err_dir_bad', array('dir' => $dir))), true);
    }
    $tree = md_tree_load($cfg);
    $needle = $dir === '' ? '' : $dir . '/';
    $docs = array();
    foreach ($tree['md'] as $rel => $info) {
        if ($needle !== '' && !md_starts_with($rel, $needle)) {
            continue;
        }
        $mtime = isset($info['mtime']) ? (int)$info['mtime'] : 0;
        $docs[] = array(
            'path'    => $rel,
            'title'   => isset($info['title']) ? (string)$info['title'] : '',
            'mtime'   => $mtime,
            'updated' => $mtime > 0 ? date('c', $mtime) : '',
            'draft'   => !empty($info['draft']),
        );
    }
    usort($docs, function ($a, $b) {
        return strcmp($a['path'], $b['path']);
    });
    $dirs = array();
    foreach ($tree['dir'] as $rel => $info) {
        if ($needle === '' || md_starts_with($rel, $needle)) {
            $dirs[] = $rel;
        }
    }
    sort($dirs);
    return md_mcp_tool_result(array('dir' => $dir, 'count' => count($docs), 'dirs' => $dirs, 'docs' => $docs));
}

function md_mcp_call_read_doc($args, $cfg)
{
    $path = isset($args['path']) && is_string($args['path']) ? trim($args['path']) : '';
    if ($path === '') {
        return md_mcp_tool_result(array('error' => md_t('mcp_err_param', array('name' => 'path'))), true);
    }
    $abs = md_resolve($path, $cfg, 'md');
    if ($abs === false) {
        return md_mcp_tool_result(array('error' => md_t('mcp_err_404', array('path' => $path))), true);
    }
    $c = md_read_text($abs);
    if ($c === false) {
        return md_mcp_tool_result(array('error' => md_t('mcp_err_404', array('path' => $path))), true);
    }
    $rel = md_rel($abs);
    $tree = md_tree_load($cfg);
    $info = isset($tree['md'][$rel]) ? $tree['md'][$rel] : array();
    $mtime = isset($info['mtime']) ? (int)$info['mtime'] : (@filemtime($abs) ?: 0);
    return md_mcp_tool_result(array(
        'path'    => $rel,
        'title'   => isset($info['title']) ? (string)$info['title'] : '',
        'draft'   => !empty($info['draft']),
        'mtime'   => $mtime,
        'updated' => $mtime > 0 ? date('c', $mtime) : '',
        'content' => $c,
    ));
}

function md_mcp_call_search_docs($args, $cfg)
{
    $q = isset($args['q']) && is_string($args['q']) ? trim($args['q']) : '';
    if ($q === '') {
        return md_mcp_tool_result(array('error' => md_t('mcp_err_q')), true);
    }
    $limit = isset($args['limit']) ? (int)$args['limit'] : 10;
    if ($limit < 1) {
        $limit = 10;
    }
    if ($limit > 50) {
        $limit = 50;
    }
    $rs = md_search($q, $cfg, $limit);
    $out = array();
    foreach ($rs as $r) {
        $out[] = array(
            'path'    => $r['rel'],
            'title'   => $r['title'],
            'snippet' => $r['snippet'],
            'score'   => (int)$r['score'],
            'updated' => !empty($r['mtime']) ? date('c', (int)$r['mtime']) : '',
        );
    }
    return md_mcp_tool_result(array('q' => $q, 'count' => count($out), 'results' => $out));
}

function md_mcp_call_write_doc($args, $cfg, $tok)
{
    if (!empty($cfg['read_only']) || MD_READONLY) {
        return md_mcp_tool_result(array('error' => md_t('mcp_err_readonly')), true);
    }
    $path = isset($args['path']) && is_string($args['path']) ? trim($args['path']) : '';
    $content = isset($args['content']) && is_string($args['content']) ? $args['content'] : null;
    if ($path === '' || $content === null) {
        return md_mcp_tool_result(array('error' => md_t('mcp_err_param',
            array('name' => $path === '' ? 'path' : 'content'))), true);
    }
    if (strlen($content) > MD_MCP_DOC_MAX) {
        return md_mcp_tool_result(array('error' => md_t('mcp_err_doc_too_big')), true);
    }
    $rel = md_mcp_norm_path($path);
    $fm = isset($args['front_matter']) && is_array($args['front_matter']) ? $args['front_matter'] : array();
    $publish = !empty($args['publish']);
    $content = md_mcp_fm_merge($content, $fm, $publish);
    $r = md_doc_save($rel, $content, $cfg, true);
    if (!$r[0]) {
        md_mcp_log($tok['name'], 'write_doc', $rel, false);
        return md_mcp_tool_result(array('error' => trim(strip_tags($r[1])), 'path' => $rel), true);
    }
    $rel = $r[1];
    $fmNow = md_front_matter($content);
    $draft = !empty($fmNow['draft']);
    $url = md_url_abs(md_site_origin($cfg), md_url_for($rel, 'doc', $cfg));
    md_mcp_log($tok['name'], 'write_doc', $rel, true);
    return md_mcp_tool_result(array(
        'ok'      => true,
        'path'    => $rel,
        'draft'   => $draft,
        'url'     => $url,
        'bytes'   => strlen($content),
        'message' => $draft ? md_t('mcp_note_draft') : md_t('mcp_note_published'),
    ));
}

function md_mcp_call_site_info($args, $cfg, $tok)
{
    $tree = md_tree_load($cfg);
    $drafts = 0;
    foreach ($tree['md'] as $info) {
        if (!empty($info['draft'])) {
            $drafts++;
        }
    }
    $names = array();
    foreach (md_mcp_tools() as $d) {
        $names[] = $d['name'];
    }
    return md_mcp_tool_result(array(
        'name'        => (string)$cfg['site_title'],
        'description' => (string)$cfg['site_desc'],
        'version'     => MD_VERSION,
        'language'    => md_lang_current(),
        'url_mode'    => (string)$cfg['url_mode'],
        'base_path'   => md_base_path($cfg),
        'read_only'   => !empty($cfg['read_only']) || MD_READONLY,
        'docs'        => count($tree['md']),
        'drafts'      => $drafts,
        'token'       => array('name' => $tok['name'], 'scope' => $tok['scope']),
        'tools'       => $names,
    ));
}

// ---------------------------------------------------------------- 协议分发

/**
 * 单请求处理（initialize / ping / tools/list / tools/call；未知方法 -32601）。
 * 通知（无 id）按 Streamable HTTP 约定回 202 无 body。本函数必然 exit。
 */
function md_mcp_handle($req, $tok, $ipHash, $cfg)
{
    $isNotif = !array_key_exists('id', $req);
    $id = isset($req['id']) ? $req['id'] : null;
    $method = isset($req['method']) && is_string($req['method']) ? $req['method'] : '';

    if ($isNotif) {
        http_response_code(202);
        exit;
    }
    switch ($method) {
        case 'initialize':
            $pv = MD_MCP_PROTOCOL;
            if (isset($req['params']['protocolVersion'])
                && is_string($req['params']['protocolVersion'])
                && in_array($req['params']['protocolVersion'], array('2025-03-26', '2025-06-18'), true)) {
                $pv = $req['params']['protocolVersion'];   // 客户端版本受支持则原样回（规范要求）
            }
            md_mcp_send(200, md_mcp_rpc_result($id, array(
                'protocolVersion' => $pv,
                'capabilities'    => array('tools' => array('listChanged' => false)),
                'serverInfo'      => array('name' => 'dirdocs', 'version' => MD_VERSION),
                'instructions'    => 'This is a DirDocs site: a file-based Markdown knowledge base. '
                    . 'list_docs/read_doc/search_docs explore it; write_doc saves AI-generated pages '
                    . 'as drafts (draft: true) that the site owner reviews and publishes in the admin. '
                    . 'Prefer write_doc over inventing file-system side effects.',
            )));
            break;
        case 'ping':
            md_mcp_send(200, md_mcp_rpc_result($id, (object)array()));
            break;
        case 'tools/list':
            md_mcp_send(200, md_mcp_rpc_result($id, array('tools' => md_mcp_tools())));
            break;
        case 'tools/call':
            md_mcp_tool_dispatch($req, $tok, $ipHash, $cfg);
            break;
        default:
            md_mcp_send(200, md_mcp_rpc_error($id, -32601, md_t('mcp_err_method_not_found')));
    }
}

/** tools/call：scope 校验 → 限流 → 按名分发。业务错误一律 isError:true（AI 可读）。 */
function md_mcp_tool_dispatch($req, $tok, $ipHash, $cfg)
{
    $id = isset($req['id']) ? $req['id'] : null;
    $params = (isset($req['params']) && is_array($req['params'])) ? $req['params'] : array();
    $name = (isset($params['name']) && is_string($params['name'])) ? $params['name'] : '';
    $args = (isset($params['arguments']) && is_array($params['arguments'])) ? $params['arguments'] : array();

    $known = false;
    $needsWrite = false;
    foreach (md_mcp_tools() as $d) {
        if ($d['name'] === $name) {
            $known = true;
            $needsWrite = ($name === 'write_doc');
            break;
        }
    }
    if (!$known) {
        md_mcp_send(200, md_mcp_rpc_error($id, -32602, md_t('mcp_err_unknown_tool', array('name' => $name))));
    }
    if ($needsWrite && $tok['scope'] !== 'write') {
        md_mcp_log($tok['name'], $name, '', false);
        md_mcp_send(200, md_mcp_rpc_result($id,
            md_mcp_tool_result(array('error' => md_t('mcp_err_scope')), true)));
    }
    // 限流：一切调用计读桶，写工具加计写桶；触发即 429 + 日志留痕
    $rl = md_mcp_rl_hit($tok['id'], $ipHash, 'r');
    if (!$rl[0]) {
        md_mcp_log($tok['name'], $name, '', false);
        header('Retry-After: ' . (int)$rl[1]);
        md_mcp_send(429, md_mcp_rpc_error($id, -32002, md_t('mcp_err_rate'), array('retry_after' => (int)$rl[1])));
    }
    if ($needsWrite) {
        $rl = md_mcp_rl_hit($tok['id'], $ipHash, 'w');
        if (!$rl[0]) {
            md_mcp_log($tok['name'], $name, '', false);
            header('Retry-After: ' . (int)$rl[1]);
            md_mcp_send(429, md_mcp_rpc_error($id, -32002, md_t('mcp_err_rate'), array('retry_after' => (int)$rl[1])));
        }
    }
    switch ($name) {
        case 'list_docs':
            $out = md_mcp_call_list_docs($args, $cfg);
            break;
        case 'read_doc':
            $out = md_mcp_call_read_doc($args, $cfg);
            break;
        case 'search_docs':
            $out = md_mcp_call_search_docs($args, $cfg);
            break;
        case 'write_doc':
            $out = md_mcp_call_write_doc($args, $cfg, $tok);
            break;
        case 'get_site_info':
            $out = md_mcp_call_site_info($args, $cfg, $tok);
            break;
        default:
            $out = md_mcp_tool_result(array('error' => md_t('mcp_err_unknown_tool', array('name' => $name))), true);
    }
    md_mcp_send(200, md_mcp_rpc_result($id, $out));
}

/**
 * 端点入口（?a=mcp，index.php 分发）：HTTP 层闸门顺序固定——
 * 方法 → HTTPS → 体积 → 认证 → 协议解析 → 分发。
 */
function md_mcp_endpoint($cfg)
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        md_mcp_send(405, md_mcp_rpc_error(null, -32600, md_t('mcp_err_method_http')));
    }
    $siteUrl = isset($cfg['site_url']) ? (string)$cfg['site_url'] : '';
    if (!md_mcp_https_decide($_SERVER, $siteUrl)) {
        md_mcp_send(403, md_mcp_rpc_error(null, -32600, md_t('mcp_err_https')));
    }
    if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > MD_MCP_BODY_MAX) {
        md_mcp_send(413, md_mcp_rpc_error(null, -32600, md_t('mcp_err_body')));
    }
    // 认证前置：无 token 不进入协议处理（含解析）。Apache CGI/FPM 场景头可能被剥进 REDIRECT_*
    $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? trim((string)$_SERVER['HTTP_AUTHORIZATION']) : '';
    if ($auth === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }
    $tok = false;
    if (stripos($auth, 'bearer ') === 0) {
        $tok = md_mcp_token_verify(trim(substr($auth, 7)), $cfg['mcp_tokens']);
    }
    if ($tok === false) {
        header('WWW-Authenticate: Bearer realm="dirdocs-mcp"');
        md_mcp_send(401, md_mcp_rpc_error(null, -32001, md_t('mcp_err_auth')));
    }
    md_mcp_token_touch($tok['id']);
    $ipHash = md5(isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');

    $body = @file_get_contents('php://input', false, null, 0, MD_MCP_BODY_MAX + 1);
    if ($body === false || strlen($body) > MD_MCP_BODY_MAX) {
        md_mcp_send(413, md_mcp_rpc_error(null, -32600, md_t('mcp_err_body')));
    }
    $req = json_decode($body, true);
    if (!is_array($req)) {
        md_mcp_send(400, md_mcp_rpc_error(null, -32700, md_t('mcp_err_parse')));
    }
    if (isset($req[0])) {
        md_mcp_send(400, md_mcp_rpc_error(null, -32600, md_t('mcp_err_batch')));   // 批处理已被 MCP 2025-06-18 移除
    }
    if (!isset($req['jsonrpc']) || $req['jsonrpc'] !== '2.0'
        || !isset($req['method']) || !is_string($req['method'])) {
        md_mcp_send(400, md_mcp_rpc_error(isset($req['id']) ? $req['id'] : null, -32600, md_t('mcp_err_rpc')));
    }
    md_mcp_handle($req, $tok, $ipHash, $cfg);
}

// ---------------------------------------------------------------- 后台「API 令牌」页

/** 生成 token（POST）：名字与 scope 收口后落 config，渲染页面并一次性展示明文。 */
function md_admin_mcp_new($cfg)
{
    $scope = (isset($_POST['scope']) && $_POST['scope'] === 'write') ? 'write' : 'read';
    $name = isset($_POST['name']) ? (string)$_POST['name'] : '';
    $tokens = md_mcp_tokens_normalize($cfg['mcp_tokens']);
    if (count($tokens) >= MD_MCP_TOKENS_MAX) {
        md_admin_mcp_page($cfg, array('err' => md_t('admin_mcp_full')));
    }
    $t = md_mcp_token_build($name, $scope);
    while (isset($tokens[$t['id']])) {                  // 8 hex 撞号（概率可忽略）：换一个
        $t = md_mcp_token_build($name, $scope);
    }
    $tokens[$t['id']] = $t['record'];
    if (!md_config_set(array('mcp_tokens' => $tokens))) {
        md_admin_mcp_page($cfg, array('err' => md_t('admin_err_save_fail')));
    }
    // 传刚落盘的新配置渲染（$cfg 还是分发时的旧快照，令牌清单会少一条）
    md_admin_mcp_page(md_config_load(), array('fresh' => $t['plain'], 'name' => $t['record']['name']));
}

/** 吊销 token（POST）：吊销后直接重渲染页面（不重定向——Location 目标不引入任何变量）。 */
function md_admin_mcp_del($cfg)
{
    $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
    if (!md_mcp_token_delete($id)) {
        md_admin_mcp_page($cfg, array('err' => md_t('admin_mcp_revfail')));
    }
    md_admin_mcp_page(md_config_load(), array('ok' => md_t('admin_mcp_revoked')));
}

/** 页面主体：接入方式 + 客户端配置示例 + 生成表单 + 令牌清单 + 调用日志。 */
function md_admin_mcp_page($cfg, $flash = array())
{
    $origin = md_site_origin($cfg);
    $endpoint = md_url_abs($origin, md_base_path($cfg) . '/?a=mcp');
    $snippet = "{\n"
        . '  "mcpServers": {' . "\n"
        . '    "dirdocs": {' . "\n"
        . '      "type": "http",' . "\n"
        . '      "url": "' . $endpoint . '",' . "\n"
        . '      "headers": { "Authorization": "Bearer <' . md_t('admin_mcp_snippet_token') . '>" }' . "\n"
        . "    }\n"
        . "  }\n"
        . '}';

    $body = '';
    if (!empty($flash['fresh'])) {
        $body .= '<div class="alert alert-ok" role="alert"><strong>' . md_e(md_t('admin_mcp_fresh')) . '</strong>'
            . '<div class="admin-mcp-token"><code>' . md_e($flash['fresh']) . '</code></div>'
            . '<p class="mb-0">' . md_e(md_t('admin_mcp_fresh_warn')) . '</p></div>';
    }
    if (!empty($flash['ok'])) {
        $body .= '<div class="alert alert-ok" role="alert">' . md_e($flash['ok']) . '</div>';
    }
    if (!empty($flash['err'])) {
        $body .= '<div class="alert alert-danger" role="alert">' . md_e($flash['err']) . '</div>';
    }

    $body .= '<div class="card admin-card mb-4"><div class="card-body">'
        . '<h2 class="h5">' . md_admin_ico('key') . ' ' . md_e(md_t('admin_mcp_howto')) . '</h2>'
        . '<p class="mb-2">' . md_e(md_t('admin_mcp_intro')) . '</p>'
        . '<p class="mb-2"><strong>' . md_e(md_t('admin_mcp_endpoint')) . '</strong><br>'
        . '<code>' . md_e($endpoint !== '' ? $endpoint : '/?a=mcp') . '</code></p>'
        . '<p class="mb-2"><strong>' . md_e(md_t('admin_mcp_snippet')) . '</strong></p>'
        . '<pre class="admin-mcp-pre"><code>' . md_e($snippet) . '</code></pre>'
        . '<p class="mb-0 text-muted small">' . md_e(md_t('admin_mcp_limit_note')) . '</p>'
        . '</div></div>';

    $body .= '<div class="card admin-card mb-4"><div class="card-body">'
        . '<h2 class="h5">' . md_admin_ico('plus') . ' ' . md_e(md_t('admin_mcp_new')) . '</h2>'
        . '<form method="post" action="' . md_e(md_admin_url('mcp')) . '">'
        . md_csrf_field()
        . '<input type="hidden" name="do" value="mcptok_new">'
        . '<div class="row g-3">'
        . '<div class="col-md-5"><div class="field"><label for="mcp-name">' . md_e(md_t('admin_mcp_name')) . '</label>'
        . '<input class="input" type="text" id="mcp-name" name="name" maxlength="40" placeholder="' . md_e(md_t('admin_mcp_name_ph')) . '"></div></div>'
        . '<div class="col-md-7"><span class="field"><label>' . md_e(md_t('admin_mcp_scope')) . '</label></span>'
        . '<div class="form-check"><input class="form-check-input" type="radio" name="scope" id="mcp-scope-r" value="read" checked>'
        . '<label class="form-check-label" for="mcp-scope-r">' . md_e(md_t('admin_mcp_scope_r')) . '</label></div>'
        . '<div class="form-check"><input class="form-check-input" type="radio" name="scope" id="mcp-scope-w" value="write">'
        . '<label class="form-check-label" for="mcp-scope-w">' . md_e(md_t('admin_mcp_scope_w')) . '</label></div></div>'
        . '</div>'
        . '<button class="btn btn-primary btn-sm mt-3" type="submit">' . md_e(md_t('admin_mcp_create')) . '</button>'
        . '</form></div></div>';

    $tokens = md_mcp_tokens_normalize($cfg['mcp_tokens']);
    $rows = '';
    foreach ($tokens as $id => $t) {
        $scopeTxt = ($t['scope'] === 'write') ? md_t('admin_mcp_scope_short_w') : md_t('admin_mcp_scope_short_r');
        $rows .= '<tr>'
            . '<td>' . md_e($t['name'] !== '' ? $t['name'] : md_t('admin_mcp_nameless')) . '</td>'
            . '<td><span class="badge ' . ($t['scope'] === 'write' ? 'text-bg-warning' : 'text-bg-secondary') . '">'
            . md_e($scopeTxt) . '</span></td>'
            . '<td>' . md_e($t['created'] > 0 ? date('Y-m-d', $t['created']) : '—') . '</td>'
            . '<td>' . md_e($t['last'] > 0 ? date('Y-m-d H:i', $t['last']) : md_t('admin_mcp_never')) . '</td>'
            . '<td><form method="post" action="' . md_e(md_admin_url('mcp')) . '" class="d-inline">'
            . md_csrf_field()
            . '<input type="hidden" name="do" value="mcptok_del">'
            . '<input type="hidden" name="id" value="' . md_e($id) . '">'
            . '<button class="btn btn-ghost btn-sm" type="submit">' . md_e(md_t('admin_mcp_revoke')) . '</button>'
            . '</form></td></tr>';
    }
    $body .= '<div class="card admin-card mb-4"><div class="card-body">'
        . '<h2 class="h5">' . md_admin_ico('list') . ' ' . md_e(md_t('admin_mcp_list')) . '</h2>';
    if ($rows === '') {
        $body .= '<p class="text-muted mb-0">' . md_e(md_t('admin_mcp_none')) . '</p>';
    } else {
        $body .= '<div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr>'
            . '<th>' . md_e(md_t('admin_mcp_col_name')) . '</th>'
            . '<th>' . md_e(md_t('admin_mcp_col_scope')) . '</th>'
            . '<th>' . md_e(md_t('admin_mcp_col_created')) . '</th>'
            . '<th>' . md_e(md_t('admin_mcp_col_last')) . '</th>'
            . '<th>' . md_e(md_t('admin_mcp_col_act')) . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
    $body .= '</div></div>';

    $log = md_mcp_log_tail(20);
    $body .= '<div class="card admin-card"><div class="card-body">'
        . '<h2 class="h5">' . md_admin_ico('history') . ' ' . md_e(md_t('admin_mcp_log')) . '</h2>';
    if (!$log) {
        $body .= '<p class="text-muted mb-0">' . md_e(md_t('admin_mcp_log_none')) . '</p>';
    } else {
        $lr = '';
        foreach ($log as $l) {
            $lr .= '<tr>'
                . '<td class="text-nowrap">' . md_e((string)$l['t']) . '</td>'
                . '<td>' . md_e(isset($l['tok']) ? (string)$l['tok'] : '') . '</td>'
                . '<td><code>' . md_e((string)$l['tool']) . '</code></td>'
                . '<td>' . md_e(isset($l['path']) ? (string)$l['path'] : '') . '</td>'
                . '<td>' . (!empty($l['ok'])
                    ? '<span class="badge text-bg-success">' . md_e(md_t('admin_mcp_log_ok')) . '</span>'
                    : '<span class="badge text-bg-danger">' . md_e(md_t('admin_mcp_log_fail')) . '</span>') . '</td>'
                . '</tr>';
        }
        $body .= '<div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr>'
            . '<th>' . md_e(md_t('admin_mcp_col_time')) . '</th>'
            . '<th>' . md_e(md_t('admin_mcp_col_name')) . '</th>'
            . '<th>' . md_e(md_t('admin_mcp_col_tool')) . '</th>'
            . '<th>' . md_e(md_t('admin_mcp_col_path')) . '</th>'
            . '<th>' . md_e(md_t('admin_mcp_col_result')) . '</th>'
            . '</tr></thead><tbody>' . $lr . '</tbody></table></div>';
    }
    $body .= '</div></div>';

    md_admin_page(md_t('admin_mcp_title'), $body, 'mcp');
}

/** 后台分发入口：POST 子动作先行（生成/吊销），其余渲染页面。 */
function md_admin_mcp($cfg)
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do'])) {
        md_csrf_guard();
        if ($_POST['do'] === 'mcptok_new') {
            md_admin_mcp_new($cfg);                     // 内部渲染页面并 exit
        }
        if ($_POST['do'] === 'mcptok_del') {
            md_admin_mcp_del($cfg);                     // 同上
        }
    }
    md_admin_mcp_page($cfg);
}
