# vendor/ — 自托管三方前端库（v1.12.0 起）

DirDocs 运行时**零外部请求**：本目录是程序依赖的全部三方前端库，随升级
（覆盖 `_dir/assets/`）一起更新。文件经 `?a=asset&t=_sys&f=vendor/...` 通道输出，
白名单见 `md_send_sys_asset()`——加文件必须同步加 MIME 映射。

## 来源与版本

| 文件 | 上游（npmmirror 即 npm 同内容镜像） | 原始校验和见下表 |
|---|---|---|
| `bootstrap.min.css` | bootstrap@5.3.3 `dist/css/bootstrap.min.css` | 原样 |
| `bootstrap.bundle.min.js` | bootstrap@5.3.3 `dist/js/bootstrap.bundle.min.js` | 剥尾部 `//# sourceMappingURL` 注释 |
| `bootstrap-icons.min.css` | bootstrap-icons@1.11.3 `font/bootstrap-icons.min.css` | 字体 URL 改写（见下） |
| `fonts/bootstrap-icons.woff2` / `.woff` | bootstrap-icons@1.11.3 `font/fonts/` | 原样 |
| `mermaid.min.js` | mermaid@10.9.1 `dist/mermaid.min.js`（UMD，自包含） | 原样 |
| `qrcode.min.js` | qrcodejs@1.0.0 | 原样 |

改写说明：`bootstrap-icons.min.css` 内 `url("fonts/…?hash")` 是相对路径，
资产通道输出的 CSS 相对解析不出正确地址，已改写为
`url("?a=asset&t=_sys&f=vendor/fonts/…")`（仅查询串，任意模式下都成立）；
同时去掉 `?hash`（通道自带 ETag/长缓存）。除此与上游逐字节一致。

## SHA-256（入库时实测，升级换版本时重算）

```
3c8f27e6009ccfd710a905e6dcf12d0ee3c6f2ac7da05b0572d3e0d12e736fc8  bootstrap.min.css
073254afbfc06331b8b548b7fc0532b4ffe2cfdd588368dcc338e7abd50810e1  bootstrap.bundle.min.js
5b0ded163b49efe4df9f4824b9bc5125166173dec16d272757c6fcbd55281f57  bootstrap-icons.min.css
476adf42b40325098fcfa8b36ab3e769186bb4f6ce6a249753e2e1a9c22bf99e  fonts/bootstrap-icons.woff2
bb1de989b83970f6f4e54de1cd974c5cba55b73582da5e1b225a6d0edf029483  fonts/bootstrap-icons.woff
61b335a46df05a7ce1c98378f60e5f3e77a7fb608a1056997e8a649304a936d6  mermaid.min.js
c541ef06327885a8415bca8df6071e14189b4855336def4f36db54bde8484f36  qrcode.min.js
```

注意：`bootstrap-icons.min.css` 的校验和对应的是**改写后**的文件；其余为原样文件
（`bootstrap.bundle.min.js` 为剥注释后）。与上游比对时请先复现同样改写。
