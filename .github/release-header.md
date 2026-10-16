> **部署**：下载下方 Assets 的 zip，解压后把内容（`index.php` + `_dir/`）上传到任意 PHP 7.1 ~ 8.4 主机目录，访问即得文档站；示例文档可整体替换为你自己的 Markdown 目录。
> **升级**：用包内 `_dir/lib/` 与 `_dir/assets/` 覆盖旧目录即可（程序版本号在 `_dir/lib/version.php`，随 lib 一起更新）。站点数据（`_dir/config.php`、`_dir/cache/`）不用动，自建主题也不用动；内置主题 default/wiki 由程序按 `_dir/lib/` 里的权威副本自动同步，不需要你手工覆盖 `_dir/themes/`。
>
> Deploy: extract the zip onto any PHP 7.1–8.4 host — `index.php` + `_dir/` is the whole install.
> Upgrade: overwrite `_dir/lib/` and `_dir/assets/`; site data and your own themes are untouched, and the built-in themes sync themselves from `_dir/lib/`.

---

