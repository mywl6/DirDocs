<?php
/**
 * DirDocs 配置模板（v1.27.1）——全量配置键与默认值参考，本文件永远不会被程序读取。
 *
 * 用法（无后台版）：
 *   无后台版安装包内已经带了一份本模板的副本，名为 _dir/config.php——直接打开
 *   _dir/config.php，按需取消注释并改值即可；没有取消注释的键一律取这里所示的默认值。
 * 用法（完整版）：
 *   完整版由后台「设置」页写配置，不需要手改；想手改或对照默认值时，可把本文件
 *   复制为 _dir/config.php 再编辑（后台保存后会按整份配置重写，注释会丢失，属正常）。
 *
 * 几条规则：
 *   - 只写想改的键，其余省略；键写坏/类型不对时程序按默认值兜底，不会白屏。
 *   - 升级包（update.zip）只覆盖 index.php + _dir/lib + _dir/assets，永远不含
 *     config.php，你的配置不会被升级覆盖。
 *   - 无后台版是纯只读站：标注【仅完整版】的键即使写了也不生效（对应功能不存在）。
 *
 * 配置文件位置：<站点根>/_dir/config.php，内容必须 return 一个数组。
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}
return array(
    // ---------------------------------------------------------------- 站点内容
    // 'site_title'    => '我的文档',            // 站点标题：首页大标题、页面标题后缀、RSS/Atom 标题
    // 'site_desc'     => '',                    // 站点副标题：显示在首页标题下方
    // 'home'          => '',                    // 首页文档覆盖：填文档相对路径（如 'README.md'），留空按目录约定
    // 'language'      => 'auto',                // 界面语言：auto（跟浏览器 Accept-Language）/ 'zh-CN' / 'en'；只影响程序界面，不翻译文档
    // 'site_url'      => '',                    // 站点绝对地址（如 'https://docs.example.com'，无尾斜杠）：sitemap/canonical 用；留空按请求推断，反代/多域名场景建议显式填写

    // ---------------------------------------------------------------- 显示与主题
    // 'theme'         => 'default',             // 主题名：内置 default / wiki，或你放进 _dir/themes/<名称>/ 的自建主题
    // 'mode'          => 'auto',                // 亮暗模式：auto（跟随系统）/ 'light' / 'dark'
    // 'theme_options' => array(),               // 主题选项：array(主题名 => array(选项键 => 值))，键位由主题包 options 声明决定
    // 'show_attach'   => true,                  // 文档详情页是否显示「附件」区（同目录的非 md 文件）
    // 'order_prefix'  => true,                  // 文件名数字前缀排序：true 时 '01-简介.md' 按 1 排序、前缀不显示
    // 'html_mode'     => 'standard',            // Markdown 里原生 HTML 的白名单档位：standard（常用标签 + video/audio）/ 'off'（全部按字面显示）/ 'loose'（追加 style/id/class）；script/iframe 等底线标签任何档位不放行
    // 'url_mode'      => 'simple',              // URL 形态：simple（?p=文档路径）/ 'pretty'（路径式，需要服务器 rewrite 规则，见 nginx.htaccess 示例）
    // 'base_path'     => '',                    // 子目录部署前缀：留空自动探测；显式填写如 '/docs'（必须以 / 开头、无尾斜杠）

    // ---------------------------------------------------------------- 文件识别与缓存
    // 'ext_md'        => array('md', 'markdown', 'txt'),   // 按 Markdown 渲染并进目录树的扩展名
    // 'ext_attach'    => array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico',
    //                          'pdf', 'zip', 'mp4', 'webm', 'md', 'markdown', 'txt'), // 附件下载白名单（md/txt 可下载但不进附件区）
    // 'hidden_prefix' => array('.', '_dir'),    // 目录树中隐藏的条目前缀（以这些开头的文件/目录不显示、不可访问）
    // 'cache'         => true,                  // 目录树与搜索索引缓存（存 _dir/cache/）；false 每次请求重建，仅排障时用

    // ---------------------------------------------------------------- 安全与警示
    // 'risk_ignore'   => array(),               // 风险文件警示豁免：精确路径豁免单个文件，以 / 结尾豁免整个目录；只关警示，不影响输出白名单

    // ================================================================
    // 以下键只在完整版生效；无后台版是纯只读站，写了也不起作用。
    // ================================================================

    // ---------------------------------------------------------------- 后台账号（完整版）
    // 'admin_user'    => 'admin',               // 后台登录名（不区分大小写）
    // 'admin_pass'    => '',                    // 后台密码的 password_hash() 哈希；空 = 首次访问 ?a=admin 走安装向导设置
    // 'admin_totp'    => '',                    // 两步验证密钥（RFC 6238，Base32 无填充）；空 = 未启用。找回方式：删除本行

    // ---------------------------------------------------------------- 访客评论与邮件（完整版）
    // 'comments'      => true,                  // 访客评论总开关：false 时评论端点 404、区块不渲染；评论先审后显，在后台「评论」页批准
    // 'smtp_host'     => '',                    // SMTP 主机；留空 = 邮件通知完全休眠（零外联）
    // 'smtp_port'     => '465',                 // SMTP 端口
    // 'smtp_user'     => '',                    // SMTP 用户名
    // 'smtp_pass'     => '',                    // SMTP 密码
    // 'smtp_from'     => '',                    // 发件人地址
    // 'smtp_secure'   => '',                    // 加密方式：''（STARTTLS 自动升级）/ 'ssl'（隐式 TLS，常配 465）/ 'none'（仅内网中继）
    // 'notify_email'  => '',                    // 新评论通知收件地址；留空 = 不发通知

    // ---------------------------------------------------------------- MCP 与版本检查（完整版）
    // 'mcp_tokens'    => array(),               // MCP API 令牌：array(id => array(name/hash/scope/created/last))；hash 为 password_hash 结果；空数组 = 端点全拒
    // 'update_check'  => false,                 // 新版本检查：false = 不发任何出站请求；后台显式开启后每天至多一次访问 api.github.com
    // 'read_only'     => false,                 // MCP 只读模式：true 时拒绝一切写入类 API 调用（后台编辑不受影响）

    // ---------------------------------------------------------------- 已废弃（仅为兼容旧配置保留，无需设置）
    // 'admin_cdn'     => 'auto',
    // 'cdn'           => 'auto',
);
