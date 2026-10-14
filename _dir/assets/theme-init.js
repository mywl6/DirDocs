/* DirDocs 防闪烁脚本：首帧渲染前确定主题并写到 <html data-bs-theme>。
 * 以同步 <script> 放在 <head> 最前（早于样式表求值）；文件必须保持极小、无依赖。
 * 双态取值 light|dark，优先级：
 *   1. localStorage.dd-theme 里的显式选择
 *   2. 服务端输出的 data-bs-theme="dark"（站点配置暗色）保持
 *   3. 跟随系统 prefers-color-scheme
 * （双态统一走 Bootstrap 原生的 data-bs-theme 属性，不再使用 data-theme。）
 */
(function () {
  try {
    var t = localStorage.getItem('dd-theme');
    if (t !== 'light' && t !== 'dark') {
      t = document.documentElement.getAttribute('data-bs-theme') === 'dark'
        ? 'dark'
        : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    }
    document.documentElement.setAttribute('data-bs-theme', t);
  } catch (e) {
    /* 隐私模式等场景读不到 localStorage：保持服务端输出的 data-bs-theme 不动 */
  }
})();
