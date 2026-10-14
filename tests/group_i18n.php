<?php
/** i18n：双语包键位对账、取词回落链、复数分支、占位替换、JS 词典导出。 */
mdtest_group('i18n 双语包');

$mdtest_zh = md_lang_pack('zh-CN');
$mdtest_en = md_lang_pack('en');
mdtest_eq(array(), array_values(array_diff(array_keys($mdtest_zh), array_keys($mdtest_en))),
    'zh 有 en 无的键为空（双向对账）');
mdtest_eq(array(), array_values(array_diff(array_keys($mdtest_en), array_keys($mdtest_zh))),
    'en 有 zh 无的键为空（双向对账）');

// CLI 无 Accept-Language、config.language=auto → 当前语言 en；md_t 取词走包
mdtest_eq('en', md_lang_current(), 'CLI 环境语言落 en');
mdtest_eq('Home', md_t('home'), '当前语言取词');
mdtest_eq('1 doc', md_t('unit_docs', array('n' => 1)), '复数 one 分支');
mdtest_eq('5 docs', md_t('unit_docs', array('n' => 5)), '复数 other 分支');
mdtest_contains(md_t('recent_limit', array('n' => 5, 'total' => 42)), '5', '占位替换 {n}');
mdtest_contains(md_t('recent_limit', array('n' => 5, 'total' => 42)), '42', '占位替换 {total}');
mdtest_eq('mdtest_no_such_key', md_t('mdtest_no_such_key'), '缺键回落键名本身');

// 包回落：非法语言名（过正则但无文件）回落中文源语言包
mdtest_same($mdtest_zh, md_lang_pack('zz'), '缺失语言包回落 zh-CN');

// JS 词典：js_ 前缀导出、复数条目展平为 _one/_other
$mdtest_attr = md_i18n_js_attr();
mdtest_contains($mdtest_attr, 'data-md-i18n="', 'JS 词典属性输出');
mdtest_contains($mdtest_attr, 'js_stat_words_one', 'js_ 复数键展平导出');
mdtest_not_contains($mdtest_attr, 'admin_st_title_label', '非 js_ 键不进词典');
