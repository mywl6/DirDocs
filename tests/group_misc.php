<?php
/** 杂项纯函数：文件名清洗、主题变量/清单校验、front matter、默认配置、字符串助手。 */
mdtest_group('杂项纯函数');

// md_safe_name：Windows 陷阱与字符集白名单
mdtest_false(md_safe_name('con.md'), 'Windows 保留名 con.md');
mdtest_false(md_safe_name('COM1'), 'Windows 保留名 com1');
mdtest_false(md_safe_name('lpt9.txt'), 'Windows 保留名 lpt9');
mdtest_false(md_safe_name('name?.md'), '非法字符 ?');
mdtest_eq('b.md', md_safe_name('a/b.md'), '路径只取最后一段（basename）');
mdtest_false(md_safe_name('..'), '相对段 ..');
mdtest_false(md_safe_name('   '), '空白清洗后为空');
mdtest_false(md_safe_name(str_repeat('a', 129) . '.md'), '超长文件名（>128）');
mdtest_true(md_safe_name(str_repeat('a', 120) . '.md') !== false, '合法长度文件名');
mdtest_eq('normal.md', md_safe_name(' normal.md '), '首尾空白清洗');
mdtest_eq('ab.md', md_safe_name("a\x01b.md"), '控制字符剔除');
mdtest_true(md_safe_name('名称(1).md') !== false, '中文名与括号放行');

// 主题变量白名单（CSS 注入防线）：禁分号/花括号/冒号（冒号堵 url() 与伪协议）
mdtest_true(md_theme_var_ok('#fff'), '色值放行');
mdtest_true(md_theme_var_ok('0 12px 30px rgba(0,0,0,.04)'), '阴影复合值放行');
mdtest_true(md_theme_var_ok('"Segoe UI",sans-serif'), '字体栈放行');
mdtest_false(md_theme_var_ok('red;x'), '分号拒绝');
mdtest_false(md_theme_var_ok('a:b'), '冒号拒绝');
mdtest_false(md_theme_var_ok('a{b'), '花括号拒绝');
mdtest_false(md_theme_var_ok(str_repeat('a', 301)), '超长拒绝');

// 主题清单校验
$mdtest_ok = md_theme_manifest_check(array('name' => 'T', 'vars' => array('--accent' => '#2563eb')));
mdtest_true(is_array($mdtest_ok) && isset($mdtest_ok['vars']['--accent']), '合法清单通过并归一化');
mdtest_false(md_theme_manifest_check(array('name' => 'T')), '缺 vars 整体拒绝');
$mdtest_bad = md_theme_manifest_check(array('name' => 'T', 'vars' => array('--accent' => '#fff', 'evil' => 'red')));
mdtest_true(is_array($mdtest_bad) && !isset($mdtest_bad['vars']['evil']), '非法键名剔除不整包拒绝');

// front matter
$mdtest_fm = md_front_matter("---\ntitle: 我的文档\norder: 3\n---\n正文");
mdtest_eq('我的文档', $mdtest_fm['title'], 'front matter 取 title');
mdtest_same(3, $mdtest_fm['order'], 'front matter 取 order');
$mdtest_fm2 = md_front_matter('无 front matter');
mdtest_eq('', $mdtest_fm2['title'], '无 front matter：title 空');
mdtest_true($mdtest_fm2['order'] === null, '无 front matter：order null');
mdtest_eq('正文', md_strip_front_matter("---\ntitle: T\n---\n正文"), '剥离 front matter');
mdtest_eq('原样', md_strip_front_matter('原样'), '无 front matter 原样返回');

// 默认配置（只读默认值数组，不碰真实 config.php）
$mdtest_d = md_config_defaults();
mdtest_true(isset($mdtest_d['site_title'], $mdtest_d['ext_md'], $mdtest_d['hidden_prefix'],
    $mdtest_d['admin_totp'], $mdtest_d['language'], $mdtest_d['cdn']), '默认配置键完整（含历史兼容键）');
mdtest_true(in_array('_dir', $mdtest_d['hidden_prefix'], true), 'hidden_prefix 含 _dir');
mdtest_same(array('md', 'markdown', 'txt'), $mdtest_d['ext_md'], 'ext_md 默认白名单');
mdtest_eq('', $mdtest_d['admin_totp'], 'TOTP 默认未启用');

// 字符串助手
mdtest_true(md_starts_with('abc', 'ab'), 'starts_with 命中');
mdtest_false(md_starts_with('abc', 'bc'), 'starts_with 未命中');
mdtest_true(md_ends_with('abc', 'bc'), 'ends_with 命中');
mdtest_true(md_contains('abc', 'b'), 'contains 命中');
