<?php
/** URL 与协议白名单：md_safe_url 放行/拒绝、md_url_for 双模式形态、md_url_encode、md_ext。 */
mdtest_group('URL 与协议白名单');

// 放行：http/https/mailto 与相对路径（原样返回）
foreach (array('https://example.com/a?b=c', 'http://x.cn', 'mailto:a@b.cn',
               'docs/a.md', '../up.md', '#top', '/abs.md', '图.png') as $mdtest_u) {
    mdtest_eq($mdtest_u, md_safe_url($mdtest_u), '放行 ' . $mdtest_u);
}

// 拒绝：危险协议与混淆写法一律返回空串（渲染层丢链接留文字）
foreach (array('javascript:alert(1)',
               'JaVaScRiPt:alert(1)',
               ' javascript:alert(1)',
               'data:text/html;base64,AA',
               'vbscript:x',
               'file:///c:/windows',
               "java\tscript:alert(1)",
               "java\nscript:alert(1)",
               "java\xE2\x80\x8Bscript:alert(1)",
               "java\xEF\xBB\xBFscript:alert(1)",
               "\x00javascript:x",
               'jscript:x') as $mdtest_u) {
    mdtest_same('', md_safe_url($mdtest_u), '拒绝 ' . var_export($mdtest_u, true));
}

// md_url_for：simple / pretty 两种模式（base_path 显式置空，不依赖 SERVER 环境变量）
$mdtest_simple = array('url_mode' => 'simple', 'base_path' => '');
$mdtest_pretty = array('url_mode' => 'pretty', 'base_path' => '');
mdtest_eq('?p=a.md', md_url_for('a.md', 'doc', $mdtest_simple), 'simple doc');
mdtest_eq('?p=a%20b.md', md_url_for('a b.md', 'doc', $mdtest_simple), 'simple doc 空格编码');
mdtest_eq('?a=file&p=a%20b.md', md_url_for('a b.md', 'file', $mdtest_simple), 'simple file 附件通道');
mdtest_eq('?a=asset&t=_sys&f=vendor/x.js', md_url_for('vendor/x.js', 'sys', $mdtest_simple), 'simple sys 资产');
mdtest_eq('?a=recent', md_url_for('', 'recent', $mdtest_simple), 'simple recent');
mdtest_eq('?q=%E8%AF%8D', md_url_for('词', 'search', $mdtest_simple), 'simple search');
mdtest_eq('/a.md', md_url_for('a.md', 'doc', $mdtest_pretty), 'pretty doc');
mdtest_eq('/%E7%9B%AE%E5%BD%95/', md_url_for('目录', 'dir', $mdtest_pretty), 'pretty 目录补尾斜杠');
mdtest_eq('/?a=asset&t=_sys&f=vendor/x.js', md_url_for('vendor/x.js', 'sys', $mdtest_pretty),
    'pretty sys 资产锚定根路径');

// md_url_encode：逐段编码、斜杠保留
mdtest_eq('a%20b/%E4%B8%AD%E6%96%87.md', md_url_encode('a b/中文.md'), 'md_url_encode 段编码');

// md_ext：小写化
mdtest_eq('png', md_ext('X.Y.PNG'), 'md_ext 小写化');
mdtest_eq('', md_ext('noext'), 'md_ext 无后缀');
