<?php
/** 草稿位：front matter 解析、children 过滤（前台隐/后台显）、RSS 条目排除。 */
mdtest_group('草稿位（draft）');

// front matter 解析：真值白名单，其余一律视为未草稿
mdtest_true(md_front_matter("---\ntitle: T\ndraft: true\n---\n")['draft'] === true, 'draft: true 识别');
mdtest_true(md_front_matter("---\ntitle: T\ndraft: 1\n---\n")['draft'] === true, 'draft: 1 识别');
mdtest_true(md_front_matter("---\ntitle: T\ndraft: yes\n---\n")['draft'] === true, 'draft: yes 识别');
mdtest_true(md_front_matter("---\ntitle: T\ndraft: false\n---\n")['draft'] === false, 'draft: false 不隐藏');
mdtest_true(md_front_matter("---\ntitle: T\ndraft: 0\n---\n")['draft'] === false, 'draft: 0 不隐藏（防误写意外隐藏）');
mdtest_true(md_front_matter("---\ntitle: T\n---\n")['draft'] === false, '无 draft 键不隐藏');

// children 过滤：前台默认隐草稿，后台显式要草稿
$mdtest_tree = array('md' => array(
    '可见.md'  => array('name' => '可见', 'sort' => null, 'draft' => false, 'mtime' => 1),
    '草稿.md'  => array('name' => '草稿', 'sort' => null, 'draft' => true, 'mtime' => 2),
));
mdtest_eq(1, count(md_children('', $mdtest_tree)['md']), '前台 children 隐藏草稿');
mdtest_eq('可见', md_children('', $mdtest_tree)['md'][0]['name'], '前台 children 只剩正常文档');
mdtest_eq(2, count(md_children('', $mdtest_tree, true)['md']), '后台 children 含草稿');

// RSS：草稿不进条目
$mdtest_entries = md_rss_entries($mdtest_tree, array('ext_md' => array('md'), 'order_prefix' => true));
mdtest_eq(1, count($mdtest_entries), 'RSS 不列草稿');
mdtest_eq('可见', $mdtest_entries[0]['title'], 'RSS 只剩正常文档');
