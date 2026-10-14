<?php
/** 搜索结果排序：相关度（默认，历史行为）与时间序（新→旧）的双平局规则。 */
mdtest_group('搜索结果排序');

$mdtest_rs = array(
    array('rel' => 'a.md', 'title' => 'a', 'score' => 3, 'mtime' => 100),
    array('rel' => 'b.md', 'title' => 'b', 'score' => 5, 'mtime' => 50),
    array('rel' => 'c.md', 'title' => 'c', 'score' => 5, 'mtime' => 50),
);
md_search_sort_results($mdtest_rs, 'score');
mdtest_eq('b.md', $mdtest_rs[0]['rel'], '相关度：高分在前');
mdtest_eq('c.md', $mdtest_rs[1]['rel'], '相关度平局按名称');
mdtest_eq('a.md', $mdtest_rs[2]['rel'], '相关度：低分在后');

md_search_sort_results($mdtest_rs, 'time');
mdtest_eq('a.md', $mdtest_rs[0]['rel'], '时间：mtime 最新的在前');
mdtest_eq('b.md', $mdtest_rs[1]['rel'], '时间平局按相关度分');
mdtest_eq('c.md', $mdtest_rs[2]['rel'], '时间+分数双平局按名称');

$mdtest_rs2 = array(
    array('rel' => 'z.md', 'title' => 'z', 'score' => 1, 'mtime' => 7),
    array('rel' => 'm.md', 'title' => 'm', 'score' => 1, 'mtime' => 7),
);
md_search_sort_results($mdtest_rs2, 'time');
mdtest_eq('m.md', $mdtest_rs2[0]['rel'], '全平局按名称升序');

// 偏好解析：URL 参数 > Cookie > 默认 score，白名单外一律视为未提供
mdtest_eq('time', md_search_sort_resolve('time', 'score'), 'URL 参数优先于 Cookie');
mdtest_eq('score', md_search_sort_resolve('', 'score'), '无参数时用 Cookie 偏好');
mdtest_eq('score', md_search_sort_resolve('', ''), '都无时默认相关度');
mdtest_eq('time', md_search_sort_resolve('xxx', 'time'), '非法参数跳过用 Cookie');
mdtest_eq('score', md_search_sort_resolve('xxx', 'yyy'), '双非法回落默认');
