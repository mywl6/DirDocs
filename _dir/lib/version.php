<?php
/**
 * 程序版本（权威源，return array 形态的数据文件，由 index.php 读取）
 *
 * 单一出处：升级时覆盖 _dir/lib/ 即可，版本号随 lib 一起走——不必再手工改 index.php，
 * 资产 URL 的 &v= 缓存失效、目录树缓存指纹、后台版本显示、新版本检查的比较基准
 * 全部跟着这个文件变。index.php 里仍有一份兜底串（本文件缺失/形态非法时用）。
 *
 * 形态约定：'version' 必须匹配 /^\d+\.\d+(\.\d+)?$/，否则被忽略并回落到兜底串。
 */
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}
return array(
    'version' => '1.20.0',
);
