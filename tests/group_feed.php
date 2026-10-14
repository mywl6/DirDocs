<?php
/** RSS/Atom：条目收集（降序/上限/目录索引剔除/标题回落）与 XML 构建（合法、转义、绝对链接）。 */
mdtest_group('RSS/Atom 订阅');

$mdtest_cfg = array('site_title' => 'T<T>', 'site_desc' => 'd&d', 'url_mode' => 'pretty',
                    'base_path' => '', 'ext_md' => array('md'), 'order_prefix' => true);
$mdtest_tree = array('md' => array(
    'b.md'          => array('title' => 'B 文档', 'mtime' => 200),
    'a.md'          => array('title' => '', 'mtime' => 300),
    'sub/README.md' => array('title' => '目录索引', 'mtime' => 999),
    'old.md'        => array('title' => '老', 'mtime' => 100),
));

$mdtest_entries = md_rss_entries($mdtest_tree, $mdtest_cfg);
mdtest_eq(3, count($mdtest_entries), '目录索引文档不单列条目');
mdtest_eq('a.md', $mdtest_entries[0]['rel'], 'mtime 降序（新的在前）');
mdtest_eq('b.md', $mdtest_entries[1]['rel'], 'mtime 降序（次序稳定）');
mdtest_eq('a', $mdtest_entries[0]['title'], '空 title 回落显示名');

$mdtest_many = array('md' => array());
for ($mdtest_i = 1; $mdtest_i <= 30; $mdtest_i++) {
    $mdtest_many['md']['d' . $mdtest_i . '.md'] = array('title' => 't', 'mtime' => $mdtest_i);
}
mdtest_eq(20, count(md_rss_entries($mdtest_many, $mdtest_cfg)), '条目上限 20 篇');

$mdtest_xml = md_rss_xml($mdtest_cfg, 'http://x.test', $mdtest_entries);
mdtest_true(simplexml_load_string($mdtest_xml) !== false, '输出为合法 XML');
mdtest_same(1, substr_count($mdtest_xml, '<?xml'), 'XML 声明只出现一次');
mdtest_contains($mdtest_xml, 'xmlns="http://www.w3.org/2005/Atom"', 'Atom 1.0 命名空间');
mdtest_contains($mdtest_xml, '<title>T&lt;T&gt;</title>', '站点标题 XML 转义');
mdtest_contains($mdtest_xml, 'd&amp;d', '副标题 XML 转义');
mdtest_contains($mdtest_xml, 'href="http://x.test/a"', '条目链接为绝对地址且 canonical 去后缀');
mdtest_contains($mdtest_xml, '<title>B 文档</title>', '条目标题来自树缓存');
mdtest_contains($mdtest_xml, '<updated>1970-01-01T00:05:00Z', '条目 updated 用 RFC 3339 UTC');
mdtest_true(strpos($mdtest_xml, 'rel="self"') !== false && strpos($mdtest_xml, '?a=rss') !== false,
    'self 链接指向 ?a=rss');
