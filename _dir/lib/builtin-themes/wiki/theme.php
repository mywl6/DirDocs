<?php
// DirDocs 内置主题包：Wiki（青绿）（程序文件，随 _dir/lib/ 升级覆盖）
// ------------------------------------------------------------
// 同一份内容出现在两处：
//   _dir/lib/builtin-themes/wiki/theme.php   权威副本（升级随 _dir/lib/ 走，读包只看这里）
//   _dir/themes/wiki/theme.php               运行期镜像（后台动作自动同步，给人看的）
// 改任何一份都会在下一次打开后台时被权威副本覆盖回来。要改内置主题请整套复制到
// _dir/themes/<你自己的名字>/ 再改——那个目录是自建主题，永不被程序动。
// 结构：name 显示名 / dark 默认深色优先 / vars 浅色变量 / vars-dark 深色变量 / options 主题选项声明
// 本文件与 md_theme_builtin_manifests() 里的内置色板必须一致（测试断言守住）。
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}
return array(
  'name' => 'Wiki（青绿）',
  'dark' => false,
  'vars' => array(
    '--bg' => '#f9fafb',
    '--surface' => '#ffffff',
    '--elevated' => '#f5f6f7',
    '--border' => '#e1e5ee',
    '--border-subtle' => '#eef0f4',
    '--border-strong' => 'rgba(15,17,21,.18)',
    '--text' => '#0f1115',
    '--text2' => '#61666b',
    '--text3' => '#8e939b',
    '--code-bg' => '#1e1e1e',
    '--code-fg' => '#e6edf3',
    '--code-gutter' => '#6e7681',
    '--danger' => '#d92d20',
    '--danger-weak' => 'rgba(217,45,32,.08)',
    '--ok' => '#079455',
    '--ok-weak' => 'rgba(7,148,85,.08)',
    '--warn' => '#b54708',
    '--warn-weak' => 'rgba(181,71,8,.09)',
    '--radius' => '8px',
    '--radius-sm' => '6px',
    '--font' => 'Inter,-apple-system,BlinkMacSystemFont,"Segoe UI Variable","Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei","Noto Sans SC",sans-serif',
    '--mono' => 'ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace',
    '--accent' => '#0f766e',
    '--accent-weak' => 'rgba(15,118,110,.1)',
    '--mark-bg' => 'rgba(15,118,110,.14)',
    '--shadow' => '0 1px 2px rgba(15,17,21,.04),0 8px 24px -12px rgba(15,17,21,.16)',
    '--paper-shadow' => '0 1px 2px rgba(15,17,21,.04),0 8px 24px -12px rgba(15,17,21,.16)',
    '--topbar-bg' => '#ffffff',
    '--bs-primary' => '#0f766e',
    '--bs-primary-rgb' => '15,118,110',
    '--bs-link-color' => '#0f766e',
    '--bs-link-color-rgb' => '15,118,110',
    '--bs-link-hover-color' => '#0d5f59',
  ),
  'vars-dark' => array(
    '--bg' => '#1b1b1c',
    '--surface' => '#1b1b1c',
    '--elevated' => '#2c2c2e',
    '--border' => '#353638',
    '--border-subtle' => '#2a2a2c',
    '--border-strong' => 'rgba(233,236,242,.22)',
    '--text' => '#e9ecf2',
    '--text2' => '#adb2b8',
    '--text3' => '#7d8288',
    '--code-bg' => '#1e1e1e',
    '--code-fg' => '#d4d4d4',
    '--code-gutter' => '#6e7681',
    '--danger' => '#f97066',
    '--danger-weak' => 'rgba(249,112,102,.12)',
    '--ok' => '#47cd89',
    '--ok-weak' => 'rgba(71,205,137,.12)',
    '--warn' => '#fdb022',
    '--warn-weak' => 'rgba(253,176,34,.12)',
    '--radius' => '8px',
    '--radius-sm' => '6px',
    '--font' => 'Inter,-apple-system,BlinkMacSystemFont,"Segoe UI Variable","Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei","Noto Sans SC",sans-serif',
    '--mono' => 'ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace',
    '--accent' => '#5eead4',
    '--accent-weak' => 'rgba(94,234,212,.14)',
    '--mark-bg' => 'rgba(94,234,212,.22)',
    '--shadow' => '0 8px 24px -12px rgba(0,0,0,.7)',
    '--paper-shadow' => '0 8px 24px -12px rgba(0,0,0,.7)',
    '--topbar-bg' => '#232324',
    '--bs-primary' => '#5eead4',
    '--bs-primary-rgb' => '94,234,212',
    '--bs-link-color' => '#5eead4',
    '--bs-link-color-rgb' => '94,234,212',
    '--bs-link-hover-color' => '#8ef5e4',
  ),
  // 主题选项：与 default 同套声明（站长取值按主题分别保存，换主题不丢）。
  // 类型与映射字段说明见 _dir/lib/md_theme.php「主题选项模型」。
  'options' => array(
    array(
      'key' => 'radius', 'type' => 'select', 'label' => '圆角风格', 'default' => '',
      'hint' => '留空跟随主题默认（8px）',
      'choices' => array(
        '' => array('label' => '跟随主题'),
        'none' => array('label' => '直角（无圆角）', 'css' => array(
          '--radius' => '0px', '--radius-sm' => '0px',
          '--bs-border-radius' => '0px', '--bs-border-radius-sm' => '0px')),
        'small' => array('label' => '小圆角', 'css' => array(
          '--radius' => '8px', '--radius-sm' => '6px',
          '--bs-border-radius' => '8px', '--bs-border-radius-sm' => '6px')),
        'large' => array('label' => '大圆角', 'css' => array(
          '--radius' => '18px', '--radius-sm' => '12px',
          '--bs-border-radius' => '18px', '--bs-border-radius-sm' => '12px')),
      ),
    ),
    array(
      'key' => 'accent', 'type' => 'color', 'label' => '主题主色', 'default' => '',
      'hint' => '链接、按钮与强调色；留空用主题自带主色',
      'css' => array('--accent', '--bs-primary', '--bs-link-color'),
      'css_rgb' => array('--bs-primary-rgb', '--bs-link-color-rgb'),
      'css_tpl' => array('--accent-weak' => 'rgba({rgb},.12)', '--mark-bg' => 'rgba({rgb},.22)'),
    ),
    array(
      'key' => 'flat', 'type' => 'toggle', 'label' => '扁平化（去掉卡片阴影）', 'default' => false,
      'hint' => '关闭时跟随主题自带的阴影效果',
      'css' => array('--shadow', '--paper-shadow'), 'on' => 'none',
    ),
    array(
      'key' => 'content_w', 'type' => 'number', 'label' => '正文栏宽', 'default' => '',
      'hint' => '留空用主题默认（860）；680~1440，宽屏可调大',
      'min' => '680', 'max' => '1440', 'step' => '20',
      'css' => array('--content-w'), 'unit' => 'px',
    ),
    array(
      'key' => 'footer_note', 'type' => 'text', 'label' => '页脚补充信息', 'default' => '',
      'hint' => '显示在页脚版权行旁，如备案号；留空不显示',
    ),
    array(
      'key' => 'notice', 'type' => 'textarea', 'label' => '站点公告', 'default' => '',
      'hint' => '显示在首页/文档页顶部；留空不显示',
    ),
  ),
);
