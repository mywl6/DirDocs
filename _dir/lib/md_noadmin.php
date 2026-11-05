<?php
/**
 * 无后台版前台 stub（v1.27.0）——完整版与无后台版共用同一份 index.php，
 * 由 index.php 探测 _dir/lib/md_admin.php 是否在盘上决定加载本文件还是 md_admin.php。
 *
 * 无后台版 = 纯只读站：后台、MCP、评论、反馈通道全部不存在（分发器侧 MD_ADMIN 门），
 * 文档与附件只能经文件系统（FTP）管理，设置纯靠手编 _dir/config.php。
 * 本文件只补前台代码路径仍会调用的三个「后台 API」缺口，签名与 md_admin.php 保持一致：
 *
 *   md_is_admin()   草稿对所有人 404、登录态相关渲染全部按访客处理
 *   md_admin_url()  「编辑此页」等后台链接地址恒为空串（模板侧 !empty 判断不渲染）
 *   md_risk_html()  风险警示横幅不渲染（横幅是给管理员的处置提示，无后台无人能处置；
 *                   md_exec_risk_files 在无后台版直接返回空数组，也不做扫描 IO）
 *
 * 本文件随两个发行包一起分发；完整版加载 md_admin.php 时本文件不加载（空壳无害），
 * 保持「构建差异只有 md_admin.php/md_mcp.php/admin.css/admin.js 四个文件」这一个维度。
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}

/** 无后台版恒为访客：草稿不可见、无任何登录态（前台全程不开启 session）。 */
function md_is_admin()
{
    return false;
}

/** 无后台版没有后台地址：返回空串，模板/上下文按空值跳过渲染。 */
function md_admin_url($do = '', $extra = array())
{
    return '';
}

/** 无后台版不渲染风险警示：返回空串（调用点在 md_pages.php 各页面组装处）。 */
function md_risk_html($files, $isAdmin)
{
    return '';
}
