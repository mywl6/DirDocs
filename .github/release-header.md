> **部署**：下载下方 Assets 的 `DirDocs-<版本>.zip`，解压后把内容（`index.php` + `_dir/`）上传到任意 PHP 7.1 ~ 8.4 主机目录，访问即得文档站；示例文档可整体替换为你自己的 Markdown 目录。
> **升级**：下载 `DirDocs-<版本>-update.zip`（覆盖升级包），解压后把 `index.php`、`_dir/lib/`、`_dir/assets/` 覆盖到安装目录即可（程序版本号在 `_dir/lib/version.php`，随 lib 一起更新）。包内不含站点数据（`_dir/config.php`、`_dir/cache/`、`_dir/themes/`、你的文档与附件），不会覆盖任何用户数据；内置主题 default/wiki 由程序按 `_dir/lib/` 里的权威副本自动同步，不需要你手工覆盖 `_dir/themes/`。
>
> Deploy: extract `DirDocs-<version>.zip` onto any PHP 7.1–8.4 host — `index.php` + `_dir/` is the whole install.
> Upgrade: extract `DirDocs-<version>-update.zip` and overwrite `index.php`, `_dir/lib/` and `_dir/assets/` in your install. The package contains no site data (`_dir/config.php`, `_dir/cache/`, `_dir/themes/`, your docs and attachments), so nothing of yours is overwritten; the built-in themes sync themselves from `_dir/lib/`.

---

