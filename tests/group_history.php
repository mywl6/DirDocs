<?php
/** 修订历史：归档/去重/裁剪/读取白名单/清空 + 行级对比（临时目录注入，不碰真实 _dir/cache）。 */
mdtest_group('修订历史');

$mdtest_base = sys_get_temp_dir() . '/ddtest_hist_' . getmypid();
if (is_dir($mdtest_base)) {
    foreach (@scandir($mdtest_base) ?: array() as $n) {
        if ($n !== '.' && $n !== '..') {
            @unlink($mdtest_base . '/' . $n);
        }
    }
    @rmdir($mdtest_base);
}
@mkdir($mdtest_base, 0777, true);

mdtest_true(md_history_archive('a.md', "v1\n", $mdtest_base) !== false, '归档第一版');
mdtest_eq(1, count(md_history_list('a.md', $mdtest_base)), '清单有一条');
mdtest_true(md_history_archive('a.md', "v1\n", $mdtest_base) === false, '与最新版相同安静跳过');
mdtest_eq(1, count(md_history_list('a.md', $mdtest_base)), '相同内容不产生新版本');
mdtest_true(md_history_archive('a.md', '', $mdtest_base) === false, '空内容不归档');

mdtest_true(md_history_archive('a.md', "v2\n", $mdtest_base) !== false, '归档第二版');
$mdtest_list = md_history_list('a.md', $mdtest_base);
mdtest_eq(2, count($mdtest_list), '两版入列');
mdtest_eq("v2\n", md_history_read('a.md', $mdtest_list[0]['f'], $mdtest_base), '最新版在前且可读回');
mdtest_eq("v1\n", md_history_read('a.md', $mdtest_list[1]['f'], $mdtest_base), '旧版可读回');
mdtest_false(md_history_read('a.md', '../meta.json', $mdtest_base), '版本名穿越拒绝');
mdtest_false(md_history_read('a.md', 'not-a-version.md', $mdtest_base), '非版本名格式拒绝');

for ($mdtest_i = 0; $mdtest_i < 25; $mdtest_i++) {
    md_history_archive('a.md', "n{$mdtest_i}\n", $mdtest_base);
}
mdtest_eq(20, count(md_history_list('a.md', $mdtest_base)), '上限 20 版裁剪最旧');
$mdtest_list = md_history_list('a.md', $mdtest_base);
mdtest_eq("n24\n", md_history_read('a.md', $mdtest_list[0]['f'], $mdtest_base), '裁剪后最新版仍在');

mdtest_true(md_history_clear('a.md', $mdtest_base), '清空历史');
mdtest_same(array(), md_history_list('a.md', $mdtest_base), '清空后清单为空');
mdtest_false(md_history_read('a.md', $mdtest_list[0]['f'], $mdtest_base), '清空后版本不可读');

// 行级对比：裁剪 + 标记 + too_big 护栏
$mdtest_d = md_history_diff_lines("a\nb\nc", "a\nX\nc");
mdtest_true($mdtest_d['too_big'] === false, '小差异可渲染');
mdtest_eq(4, count($mdtest_d['lines']), '公共前后缀裁剪后 4 行（上下文+差异+上下文）');
mdtest_eq('-', $mdtest_d['lines'][1]['t'], '旧独有行标 -');
mdtest_eq('b', $mdtest_d['lines'][1]['s'], '旧独有行内容');
mdtest_eq('+', $mdtest_d['lines'][2]['t'], '新独有行标 +');
mdtest_eq('X', $mdtest_d['lines'][2]['s'], '新独有行内容');
mdtest_same(array('too_big' => true, 'lines' => array()),
    md_history_diff_lines(str_repeat("x\n", 500), str_repeat("y\n", 500)), '超限放弃渲染');
mdtest_eq(' ', md_history_diff_lines("same\n", "same\n")['lines'][0]['t'], '相同内容全为上下文');

@rmdir($mdtest_base);
