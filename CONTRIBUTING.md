# 参与贡献

感谢关注 DirDocs！Issue 与 PR 用中文或英文都可以。

## 开始之前：这个项目在坚持什么

DirDocs 刻意做减法，三条产品支柱的优先级固定为：**安全 > 部署方便 > 使用方便**。
由此派生出几条评审时不可协商的红线：

- **零依赖、零外联**：不引入 Composer 包、前端库、CDN、字体、统计脚本；
  唯一例外是站长显式配置 SMTP 后的邮件通知
- **零数据库、零构建**：不引入数据库，不要求任何构建步骤
- **部署即复制**：保持「上传 `index.php` 与 `_dir/` 即得文档站、覆盖 `_dir/lib/` 与
  `_dir/assets/` 即升级」
- **PHP 7.1 ~ 8.4 全兼容**：不使用 7.2+ 的语法与函数，注意跨版本差异
  （箭头函数 7.4+、命名参数 8.0+、readonly 8.1+ 等）
- **数据即文件**：文档、评论、配置都以普通文件存放，随目录走、随备份走

与红线冲突的改动会被婉拒——这不针对任何具体方案，而是产品定位本身。

## 报告 Bug

用 [Bug 反馈模板](https://github.com/mywl6/DirDocs/issues/new?template=bug_report.yml)提交，
尽量给出最小复现环境。**安全漏洞请勿公开提交**，见 [SECURITY.md](SECURITY.md)。

## 建议功能

先开 [功能建议](https://github.com/mywl6/DirDocs/issues/new?template=feature_request.yml)讲清
场景与痛点，讨论达成共识后再动手，避免方向性返工。

## 提交 PR

1. Fork 并新建分支，一个 PR 只做一件事
2. 自测：至少在 PHP 7.1.x 与一个 8.x 环境各跑一遍核心流程
   （浏览、搜索、后台登录与设置、评论）
3. 填写 PR 模板中的自查清单；用户可见变更记入 `CHANGELOG.md` 的 Unreleased 段
4. 本地起服务：在部署目录内执行 `php -S 127.0.0.1:8080`（pretty 伪静态需 Apache / nginx，
   内置服务器下用原生 URL 即可测全功能）

## 许可证

提交即表示同意以 [AGPL-3.0](LICENSE) 授权你的贡献。
