<?php
/**
 * 发布校验和生成（开发专用工具，部署形态不含本目录）。
 *
 * 跑法：php tools/make_checksums.php —— 在仓库根生成 checksums.txt，
 * 覆盖发布范围（index.php + _dir/lib/** + _dir/assets/**，含 vendor）；
 * 用户数据（config.php / cache / themes）不在发布范围内，故不收录。
 * 校验：sha256sum -c checksums.txt（Git Bash / Linux 均可用）。
 */
if (PHP_SAPI !== 'cli') {
    exit("make_checksums: 仅限 CLI 运行\n");
}

$root = dirname(__DIR__);
$targets = array_merge(
    array($root . '/index.php'),
    glob($root . '/_dir/lib/*.php'),
    glob($root . '/_dir/lib/lang/*.php'),
    glob($root . '/_dir/assets/*'),
    glob($root . '/_dir/assets/vendor/*'),
    glob($root . '/_dir/assets/vendor/fonts/*')
);

$lines = array();
foreach ($targets as $path) {
    if (!is_string($path) || !is_file($path)) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $sum = hash_file('sha256', $path);
    if ($sum === false) {
        exit("make_checksums: 无法读取 {$rel}\n");
    }
    $lines[] = $sum . '  ' . $rel;
}
sort($lines);

$out = implode("\n", $lines) . "\n";
if (@file_put_contents($root . '/checksums.txt', $out, LOCK_EX) === false) {
    exit("make_checksums: 写入 checksums.txt 失败\n");
}
echo 'checksums.txt 已生成（' . count($lines) . " 个文件）\n";
