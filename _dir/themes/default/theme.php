<?php
// DirDocs 内置主题包：默认主题（程序文件，随 _dir/lib/ 升级覆盖）
// ------------------------------------------------------------
// 同一份内容出现在两处：
//   _dir/lib/builtin-themes/default/theme.php   权威副本（升级随 _dir/lib/ 走，读包只看这里）
//   _dir/themes/default/theme.php               运行期镜像（后台动作自动同步，给人看的）
// 改任何一份都会在下一次打开后台时被权威副本覆盖回来。要改内置主题请整套复制到
// _dir/themes/<你自己的名字>/ 再改——那个目录是自建主题，永不被程序动。
// 结构：name 显示名 / dark 默认深色优先 / vars 浅色变量 / vars-dark 深色变量
//       options 主题选项声明（六种类型示例：select/color/toggle/number/text/textarea）
// 本文件与 md_theme_builtin_manifests() 里的内置色板必须一致（测试断言守住）。
if (!defined('MD_BOOT')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1>';
    exit;
}
return array(
  'name' => '默认主题',
  'dark' => false,
  'vars' =>
  array (
    '--bg' => '#f8fafc',
    '--surface' => '#ffffff',
    '--elevated' => '#f1f5f9',
    '--border' => '#e2e8f0',
    '--border-subtle' => '#f1f5f9',
    '--border-strong' => 'rgba(15,23,42,.16)',
    '--text' => '#0f172a',
    '--text2' => '#64748b',
    '--text3' => '#94a3b8',
    '--code-bg' => '#0f172a',
    '--code-fg' => '#e2e8f0',
    '--code-gutter' => '#334155',
    '--danger' => '#dc2626',
    '--danger-weak' => 'rgba(220,38,38,.07)',
    '--ok' => '#059669',
    '--ok-weak' => 'rgba(5,150,105,.08)',
    '--warn' => '#d97706',
    '--warn-weak' => 'rgba(217,119,6,.08)',
    '--radius' => '12px',
    '--radius-sm' => '8px',
    '--font' => '-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif',
    '--mono' => 'ui-monospace,SFMono-Regular,"Roboto Mono","Cascadia Code",Consolas,monospace',
    '--accent' => '#2563eb',
    '--accent-weak' => 'rgba(37,99,235,.08)',
    '--mark-bg' => 'rgba(37,99,235,.12)',
    '--shadow' => '0 1px 3px rgba(0,0,0,.03),0 12px 30px -6px rgba(15,23,42,.04)',
    '--paper-shadow' => '0 1px 3px rgba(0,0,0,.03),0 12px 30px -6px rgba(15,23,42,.04)',
    '--topbar-bg' => 'rgba(248,250,252,.85)',
  ),
  'vars-dark' =>
  array (
    '--bg' => '#07090e',
    '--surface' => '#0e121e',
    '--elevated' => '#141a29',
    '--border' => '#1e293b',
    '--border-subtle' => '#141a29',
    '--border-strong' => 'rgba(148,163,184,.28)',
    '--text' => '#f1f5f9',
    '--text2' => '#94a3b8',
    '--text3' => '#6b7280',
    '--code-bg' => '#05070c',
    '--code-fg' => '#cbd5e1',
    '--code-gutter' => '#475569',
    '--danger' => '#f87171',
    '--danger-weak' => 'rgba(248,113,113,.12)',
    '--ok' => '#34d399',
    '--ok-weak' => 'rgba(52,211,153,.12)',
    '--warn' => '#fbbf24',
    '--warn-weak' => 'rgba(251,191,36,.12)',
    '--radius' => '12px',
    '--radius-sm' => '8px',
    '--font' => '-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif',
    '--mono' => 'ui-monospace,SFMono-Regular,"Roboto Mono","Cascadia Code",Consolas,monospace',
    '--accent' => '#3b82f6',
    '--accent-weak' => 'rgba(59,130,246,.14)',
    '--mark-bg' => 'rgba(59,130,246,.22)',
    '--shadow' => '0 4px 24px -2px rgba(0,0,0,.5)',
    '--paper-shadow' => '0 4px 24px -2px rgba(0,0,0,.5)',
    '--topbar-bg' => 'rgba(7,9,14,.8)',
  ),
  // 主题选项：站长在后台「主题」页填写，取值存进 config.php（theme_options），
  // 留空 = 跟随上面的色板。类型与映射字段说明见 _dir/lib/md_theme.php。
  'options' => array(
    array(
      'key' => 'radius', 'type' => 'select', 'label' => '圆角风格', 'default' => '',
      'hint' => '留空跟随主题默认（12px）',
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
