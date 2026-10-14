<?php
/** 排序：数字排序键优先 + intl Collator 拼音序 / 字节序回退（断言随环境二选一）。 */
mdtest_group('排序（Collator 与回退）');

$mdtest_items = array(
    array('name' => 'c', 'sort' => 2),
    array('name' => 'a', 'sort' => 1),
    array('name' => 'b', 'sort' => null),
);
md_sort_items($mdtest_items);
mdtest_eq('a', $mdtest_items[0]['name'], '排序键优先（order=1 最前）');
mdtest_eq('c', $mdtest_items[1]['name'], '排序键优先（order=2 次之）');
mdtest_eq('b', $mdtest_items[2]['name'], '无排序键排最后');

mdtest_true(md_str_compare('a', 'b') < 0, 'ASCII 升序');
mdtest_true(md_str_compare('b', 'a') > 0, 'ASCII 降序反向');

if (class_exists('Collator')) {
    // Collator('zh') 按拼音：「安」(an) 排「中」(zhong) 之前；
    // 而 UTF-8 字节序恰好相反（中 U+4E2D < 安 U+5B89）——用这对字区分两条路径
    mdtest_true(md_str_compare('中', '安') > 0, '拼音序生效：中 > 安（intl Collator）');
} else {
    mdtest_true(md_str_compare('中', '安') < 0, '无 intl 回退字节序：中 < 安（不劣于旧版）');
}
