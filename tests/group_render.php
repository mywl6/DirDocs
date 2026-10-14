<?php
/** Markdown 渲染器黄金样本：块级结构 + 安全防线（raw HTML 转义、危险协议丢链接）。 */
mdtest_group('Markdown 渲染器');

// 标题 + 锚点 + TOC
$mdtest_r = md_render("# Hello World\n\n## 中文标题\n\n# Hello World");
mdtest_contains($mdtest_r['html'], '<h1 id="hello-world">Hello World</h1>', 'h1 与 slug 锚点');
mdtest_contains($mdtest_r['html'], '<h2 id="中文标题">中文标题</h2>', '中文锚点保留');
mdtest_contains($mdtest_r['html'], '<h1 id="hello-world-2">', '重复标题锚点去重');
mdtest_eq(3, count($mdtest_r['toc']), 'TOC 收录全部标题');
mdtest_eq('hello-world', $mdtest_r['toc'][0]['id'], 'TOC 条目带 id');

// 行内与块级结构
mdtest_contains(md_render('**粗** *斜* ~~删~~')['html'], '<strong>粗</strong>', '粗体');
mdtest_contains(md_render('~~删~~')['html'], '<del>删</del>', '删除线渲染为 del（v1.12.1 补齐工具栏承诺）');
mdtest_contains(md_render("一行\n\n二行")['html'], '<p>一行</p>', '段落');
mdtest_contains(md_render("- a\n- b")['html'], '<ul><li>a</li><li>b</li></ul>', '无序列表');
mdtest_contains(md_render("1. x\n2. y")['html'], '<ol><li>x</li><li>y</li></ol>', '有序列表');
mdtest_contains(md_render('`code`')['html'], '<code>code</code>', '行内代码');
mdtest_contains(md_render("> 引用")['html'], '<blockquote><p>引用</p></blockquote>', '引用块');
mdtest_contains(md_render("---")['html'], '<hr>', '分隔线');
mdtest_contains(md_render("| a | b |\n| --- | --- |\n| 1 | 2 |")['html'],
    '<th>a</th>', '表格表头');
mdtest_contains(md_render("| a | b |\n| --- | --- |\n| 1 | 2 |")['html'],
    '<td>1</td>', '表格单元格');

// 代码块：语言 class + 内容转义；mermaid 特判容器
mdtest_contains(md_render("```php\n<?php echo 1;\n```")['html'],
    '<pre><code class="language-php">&lt;?php echo 1;</code></pre>', '围栏代码块转义+语言 class');
mdtest_contains(md_render("```mermaid\ngraph LR\nA-->B\n```")['html'],
    '<pre class="mermaid">graph LR' . "\n" . 'A--&gt;B</pre>', 'mermaid 块输出专用容器');

// 安全防线
$mdtest_r = md_render('<script>alert(1)</script>');
mdtest_not_contains($mdtest_r['html'], '<script>', 'raw HTML script 标签不透传');
mdtest_contains($mdtest_r['html'], '&lt;script&gt;', 'raw HTML 转义为可见文本');
mdtest_not_contains(md_render('[点](javascript:alert(1))')['html'], '<a ', 'javascript: 链接丢弃');
mdtest_contains(md_render('[点](javascript:alert(1))')['html'], '点', '危险链接保留文字');
mdtest_not_contains(md_render('![x](javascript:alert(1))')['html'], '<img', '危险图片丢弃');
mdtest_contains(md_render('[点](https://e.com/a)')['html'], '<a href="https://e.com/a"', 'https 链接放行');
mdtest_contains(md_render('![说明](图.png)')['html'], '<img src="图.png"', '相对图片放行');
