<?php
/**
 * DirDocs 入口（最终形态）
 *
 * 部署形态：index.php 放在哪个目录，哪个目录就是文档根。
 * 零配置、无数据库；兼容 PHP 7.1 ~ 8.4。
 *
 * 本文件只做三件事：
 *   1. boot：定义常量（MD_ROOT / MD_DIR / MD_BOOT / MD_READONLY）
 *   2. load：加载 _dir/lib/ 下的五个模块（带 MD_BOOT 闸门）
 *   3. route：按 action/query 分发到各模块的入口函数
 *
 * 业务代码全在 _dir/lib/：
 *   md_engine.php  引擎（文本/路径/URL/渲染/搜索/文件名）
 *   md_i18n.php    多语言（界面语言解析/md_t 取词/JS 文案词典）
 *   md_pages.php   前台（页面动作/模板辅助/附件/风险检测）
 *   md_theme.php   主题（CSS 变量/页面模板/用户主题）
 *   md_admin.php   后台（鉴权/界面/四功能/分发）
 * 内置样式与行为脚本在 _dir/assets/（builtin.css / builtin.js / theme-init.js，后台另有 admin.css / admin.js），
 * 三方前端库（Bootstrap/图标/mermaid/qrcode）自托管在 _dir/assets/vendor/，运行时零外部请求。
 * 语言包在 _dir/lib/lang/（内置，随升级覆盖）。
 *
 * 升级方式：覆盖 _dir/lib/ 与 _dir/assets/ 即可，index.php 极少变（路由调整时随版更新）。
 * 用户数据（_dir/config.php、_dir/cache/、_dir/themes/）不被覆盖。
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');   // 错误进日志，不回显（避免路径/结构泄露）

// ---------------------------------------------------------------- boot

define('MD_VERSION', '1.17.0');
define('MD_ROOT', realpath(__DIR__));
define('MD_DIR', MD_ROOT . '/_dir');
define('MD_DIRNAME', '_dir');
define('MD_LIB', MD_DIR . '/lib');

if (MD_ROOT === false) {
    http_response_code(500);
    exit('DirDocs: cannot resolve document root.');
}

/**
 * _dir 可写性：不存在时先尝试创建（父目录可写就能建），建不起来才算只读。
 * 只读 = 无缓存、无后台、无上传。
 */
if (!is_dir(MD_DIR)) {
    @mkdir(MD_DIR, 0755, true);
}
define('MD_READONLY', !is_dir(MD_DIR) || !is_writable(MD_DIR));

// _dir 防护（v1.16.0）：Apache 由 _dir/.htaccess 整目录拒绝直访（缺失时自动补种，老安装
// 升级即生效）。资产/附件全部经程序通道输出，_dir 不需要任何静态直访；Nginx 没有目录级
// 配置文件，对应规则在后台「设置 → 服务器加固」卡按安装路径生成。
if (!MD_READONLY && !is_file(MD_DIR . '/.htaccess')) {
    @file_put_contents(MD_DIR . '/.htaccess',
        "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
}

// mbstring 扩展不保证存在（引擎内各处均有降级路径），boot 这行不能裸调
if (function_exists('mb_internal_encoding')) { mb_internal_encoding('UTF-8'); }
if (function_exists('mb_language')) { mb_language('uni'); }

// ---------------------------------------------------------------- load

// 闸门常量：lib 文件被 Web 直接访问时此常量不存在，文件头会拒绝执行
define('MD_BOOT', 1);

$md_libs = array('md_engine.php', 'md_i18n.php', 'md_pages.php', 'md_theme.php', 'md_admin.php');
foreach ($md_libs as $lib) {
    $path = MD_LIB . '/' . $lib;
    if (!is_file($path)) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        exit('DirDocs: missing module ' . md_e_basename($lib) . '。请确认 _dir/lib/ 目录完整。');
    }
    require_once $path;
}

/** 仅用于启动期的安全文件名回显（此时 md_e 尚未加载）。 */
function md_e_basename($s)
{
    return htmlspecialchars(basename((string)$s), ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------- route

$MD_CFG = md_config_load();

$action = md_action();
if ($action === 'file') {
    $p = isset($_GET['p']) ? $_GET['p'] : '';
    md_send_file($p, $MD_CFG);
}

if ($action === 'asset') {
    md_send_asset($MD_CFG);                             // 主题/内置资产输出
}

if ($action === 'admin') {
    md_admin_dispatch($MD_CFG);
}

// SEO：?a=sitemap 动态输出 sitemap.xml（规范地址/canonical 与 lastmod 一并处理）
if ($action === 'sitemap') {
    md_send_sitemap($MD_CFG);
}

// 订阅：?a=rss 动态输出 Atom 源（最近更新的 20 篇，树缓存 + lastmod 构建）
if ($action === 'rss') {
    md_send_rss($MD_CFG);
}

$p = md_route_p();

// pretty 模式：/_theme/<主题>/<文件> 即主题资产通道（simple 模式走 ?a=asset，不经过这里）。
// t/f 已由 md_route_p 按段解码；md_send_asset 自带主题名/后缀/穿越校验，非法形态一律 404。
if (md_starts_with($p, '_theme/')) {
    $rest = substr($p, strlen('_theme/'));
    $slash = strpos($rest, '/');
    if ($slash === false) {
        md_abort(404);                                  // 只有主题名没有文件段
    }
    $_GET['t'] = substr($rest, 0, $slash);
    $_GET['f'] = substr($rest, $slash + 1);
    md_send_asset($MD_CFG);
}

$tree = md_tree_load($MD_CFG);

// 反馈投票（v1.16.0）：POST ?a=feedback 匿名计数入 cache/feedback.json（限流 + 双提交令牌），
// JSON 应答。必须在 ?q= 搜索页拦截之前——自带新 action 要抢在搜索页前面分发（v1.15.1 教训）
if ($action === 'feedback') {
    md_send_feedback($tree, $MD_CFG);
}

// 访客评论（v1.17.0）：POST ?a=comment 写入文档旁 <文档>.cmt.php（先审后显，限流 + 双提交令牌
// + 时间陷阱 + 蜜罐），JSON 或 303 回跳应答。与 feedback 同批：抢在搜索页拦截之前分发
if ($action === 'comment') {
    md_send_comment($tree, $MD_CFG);
}

// 搜索建议：?a=suggest 输出 JSON（Ctrl+K 快速搜索弹窗的数据源，GET 只读）。
// 必须在 ?q= 搜索页拦截之前——建议 URL 自带 q 参数，放后面会被搜索页抢先渲染成 HTML
if ($action === 'suggest') {
    md_send_suggest($MD_CFG);
}

// 最近更新视图：?a=recent
if ($action === 'recent') {
    md_show_recent($tree, $MD_CFG);
}

// 搜索：?q= 存在且不带 ?p= 时进结果页（带 p 即为打开文档顺带高亮，否则搜索结果永远回不到文档）
if (!isset($_GET['p']) && isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
    md_show_search($tree, $MD_CFG);
}

if ($p === '') {
    md_show_dir('', $tree, $MD_CFG);
}

// 先按文档解析；失败再按目录解析
$abs = md_resolve($p, $MD_CFG, 'md');
if ($abs !== false) {
    md_show_doc($p, $tree, $MD_CFG);
}

$absDir = realpath(MD_ROOT . '/' . $p);
if ($absDir !== false && is_dir($absDir)
    && md_starts_with(str_replace('\\', '/', $absDir), str_replace('\\', '/', MD_ROOT) . '/')) {
    md_show_dir($p, $tree, $MD_CFG);
}

md_abort(404);
