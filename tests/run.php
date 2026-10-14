<?php
/**
 * DirDocs 测试套件入口（开发专用，部署形态只有 index.php + _dir，本目录不随部署出去）
 *
 * 跑法：php tests/run.php —— 全绿退出码 0，任一失败非 0。
 * 设计约束（详见 MD-Docs开发文档.md 第 12 节）：
 *   - 零依赖：不引 PHPUnit/composer，断言助手就是下面这几个函数；
 *   - 引导方式：本文件自行 define boot 常量后 require 五个 lib（它们只是函数定义
 *     + MD_BOOT 闸门，不会执行路由），绝不 include index.php（会分发请求并 exit）；
 *   - 只测纯函数：不碰 _dir/config.php / cache / themes 的写路径；
 *     TOTP 验证读写 $_SESSION 超全局，CLI 下不调 session_start、直接当普通数组伪造。
 */
error_reporting(E_ALL);
if (PHP_SAPI !== 'cli') {
    exit("tests: 仅限 CLI 运行\n");
}

define('MD_BOOT', 1);
define('MD_VERSION', 'test');
define('MD_ROOT', realpath(__DIR__ . '/..'));
define('MD_DIR', MD_ROOT . '/_dir');
define('MD_DIRNAME', '_dir');
define('MD_LIB', MD_DIR . '/lib');
define('MD_READONLY', false);
// md_base_path 在 base_path 未显式配置时按 SCRIPT_NAME 自动探测——CLI 下应视作根安装
$_SERVER['SCRIPT_NAME'] = '/index.php';

$GLOBALS['MDTEST'] = array('pass' => 0, 'fail' => 0, 'list' => array(), 'group' => '');

function mdtest_group($name)
{
    $GLOBALS['MDTEST']['group'] = $name;
    echo "\n== " . $name . " ==\n";
}

function mdtest_result($ok, $name, $expect = '', $actual = '')
{
    if ($ok === true) {
        $GLOBALS['MDTEST']['pass']++;
        echo '  PASS  ' . $name . "\n";
        return;
    }
    $GLOBALS['MDTEST']['fail']++;
    $GLOBALS['MDTEST']['list'][] = $GLOBALS['MDTEST']['group'] . ' / ' . $name;
    echo "  FAIL  " . $name . "\n"
        . '        期望: ' . var_export($expect, true) . "\n"
        . '        实际: ' . var_export($actual, true) . "\n";
}

function mdtest_eq($expect, $actual, $name)
{
    mdtest_result($expect === $actual, $name, $expect, $actual);
}

function mdtest_same($expect, $actual, $name)
{
    mdtest_result($expect == $actual, $name, $expect, $actual);
}

function mdtest_true($actual, $name)
{
    mdtest_result($actual === true, $name, true, $actual);
}

function mdtest_false($actual, $name)
{
    mdtest_result($actual === false, $name, false, $actual);
}

function mdtest_contains($haystack, $needle, $name)
{
    mdtest_result(strpos((string)$haystack, (string)$needle) !== false, $name, '含 ' . $needle, $haystack);
}

function mdtest_not_contains($haystack, $needle, $name)
{
    mdtest_result(strpos((string)$haystack, (string)$needle) === false, $name, '不含 ' . $needle, $haystack);
}

require MD_LIB . '/md_engine.php';
require MD_LIB . '/md_i18n.php';
require MD_LIB . '/md_pages.php';
require MD_LIB . '/md_theme.php';
require MD_LIB . '/md_admin.php';

echo 'DirDocs 测试套件（PHP ' . PHP_VERSION
    . '，intl Collator=' . (class_exists('Collator') ? '有' : '无') . '）' . "\n";

foreach (array('group_totp.php', 'group_url.php', 'group_sort.php',
               'group_render.php', 'group_i18n.php', 'group_misc.php',
               'group_feed.php', 'group_history.php', 'group_draft.php',
               'group_search.php') as $mdtest_file) {
    require __DIR__ . '/' . $mdtest_file;
}

echo "\n==== 汇总：通过 " . $GLOBALS['MDTEST']['pass']
    . ' / 失败 ' . $GLOBALS['MDTEST']['fail'] . " ====\n";
if ($GLOBALS['MDTEST']['fail'] > 0) {
    echo "失败断言:\n";
    foreach ($GLOBALS['MDTEST']['list'] as $mdtest_name) {
        echo '  - ' . $mdtest_name . "\n";
    }
    exit(1);
}
exit(0);
