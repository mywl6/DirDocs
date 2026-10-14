<?php
/** TOTP：RFC 4226 官方向量 + Base32 + 验证窗口/防重放/容错（动态窗口断言带 30s 边界重试）。 */
mdtest_group('TOTP（RFC 6238）');

// RFC 4226 Appendix D 官方向量：raw secret = ASCII "12345678901234567890"
$mdtest_rfc = array(755224, 287082, 359152, 969429, 338314, 254676, 287922, 162583, 399871, 520489);
foreach ($mdtest_rfc as $mdtest_c => $mdtest_want) {
    mdtest_eq((string)$mdtest_want, md_totp_hotp('12345678901234567890', $mdtest_c),
        'RFC 4226 向量 counter=' . $mdtest_c);
}

// Base32（RFC 4648 无填充）：标准密钥解码 + 容错
mdtest_eq('12345678901234567890', md_totp_base32_decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
    'Base32 解码 RFC 6238 标准密钥');
mdtest_false(md_totp_base32_decode('AB!'), 'Base32 非法字符返回 false');
mdtest_false(md_totp_base32_decode('A'), 'Base32 不足 8 位返回 false');
mdtest_eq('abc', md_totp_base32_decode('MFRGG==='), 'Base32 容忍填充符（RFC 4648 向量 abc）');

// 密钥生成：160 位 → 32 字符 Base32 → 解出 20 字节
$mdtest_secret = md_totp_secret_gen();
mdtest_eq(32, strlen($mdtest_secret), 'secret_gen 产出 32 字符');
$mdtest_raw = md_totp_base32_decode($mdtest_secret);
mdtest_true(is_string($mdtest_raw) && strlen($mdtest_raw) === 20, 'secret_gen 解出 20 字节 raw key');

/**
 * 动态窗口断言：time() 不可注入，期望码按调用瞬间的时间片现算。
 * 整组断言必须在同一个 30s 周期内完成，跨边界则返回 null 由外层重试
 * （撞边界概率 ≈ 断言耗时/30s，重试三次后仍失败按真失败报告）。
 *
 * @return array|null array(array(bool 通过与否, string 断言名), ...)；跨边界 null
 */
function mdtest_totp_window($mdtest_secret, $mdtest_raw)
{
    $mdtest_s0 = (int)floor(time() / 30);
    $mdtest_out = array();

    $_SESSION = array();
    $mdtest_out[] = array(md_totp_verify($mdtest_secret, md_totp_hotp($mdtest_raw, $mdtest_s0 - 2)) === false,
        '窗口外（-2 周期）拒绝');
    $mdtest_out[] = array(md_totp_verify($mdtest_secret, md_totp_hotp($mdtest_raw, $mdtest_s0 + 2)) === false,
        '窗口外（+2 周期）拒绝');

    $_SESSION = array();   // 新会话：先吃掉 -1 周期
    $mdtest_out[] = array(md_totp_verify($mdtest_secret, md_totp_hotp($mdtest_raw, $mdtest_s0 - 1)) === true,
        '窗口下沿（-1 周期）通过');
    $mdtest_out[] = array(md_totp_verify($mdtest_secret, md_totp_hotp($mdtest_raw, $mdtest_s0 - 1)) === false,
        '同码立即重放被拒（会话防重放）');
    $mdtest_out[] = array(md_totp_verify($mdtest_secret, md_totp_hotp($mdtest_raw, $mdtest_s0 - 2)) === false,
        '已消费更早周期的一律不再接受');

    $_SESSION = array();
    $mdtest_out[] = array(md_totp_verify($mdtest_secret, md_totp_hotp($mdtest_raw, $mdtest_s0 + 1)) === true,
        '窗口上沿（+1 周期）通过');

    $_SESSION = array();
    $mdtest_out[] = array(md_totp_verify($mdtest_secret, md_totp_hotp($mdtest_raw, $mdtest_s0)) === true,
        '当前周期通过');

    $_SESSION = array();
    $mdtest_ok = md_totp_verify($mdtest_secret, md_totp_hotp($mdtest_raw, $mdtest_s0), false);
    $mdtest_out[] = array($mdtest_ok === true && !isset($_SESSION['md_totp_last']),
        'consume=false 只读校验：通过且不写防重放水位');

    return ((int)floor(time() / 30)) === $mdtest_s0 ? $mdtest_out : null;
}

$mdtest_tuples = null;
for ($mdtest_try = 0; $mdtest_try < 3 && $mdtest_tuples === null; $mdtest_try++) {
    $mdtest_tuples = mdtest_totp_window($mdtest_secret, $mdtest_raw);
}
if ($mdtest_tuples === null) {
    mdtest_false(true, 'TOTP 动态窗口断言（连续三次跨越 30s 边界，概率≈0，请重跑）');
} else {
    foreach ($mdtest_tuples as $mdtest_t) {
        mdtest_result($mdtest_t[0] === true, $mdtest_t[1]);
    }
}

// 非法输入容错
$_SESSION = array();
mdtest_false(md_totp_verify($mdtest_secret, 'abcdef'), '非数字输入拒绝');
mdtest_false(md_totp_verify($mdtest_secret, '123456789012'), '超长数字拒绝');
mdtest_false(md_totp_verify('1', '123456'), '非法 Base32 密钥拒绝');
mdtest_false(md_totp_verify('', '123456'), '空密钥拒绝');
