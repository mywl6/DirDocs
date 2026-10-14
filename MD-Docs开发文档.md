# MD Docs 开发文档（完整版）

> 版本：v1.3　日期：2026-10-13
> 2026-10-13 一次修订：执行隔离改由代码保证，删除 nginx/Apache 隔离规则设计（4.5）
> 2026-10-13 二次修订：部署形态改为零配置——`index.php` 放哪，哪就是文档根，无安装向导、无路径配置（2.1）
> 2026-10-13 三次修订：PHP 目标从 8.0+ 放宽为 7.1 ~ 8.4 全兼容（廉价虚拟主机大量停在 7.x）
> 定位：把一个普通文件夹（Markdown 文档 + 子文件夹 + 附件）一键变成在线文档网站。
> 形态：单文件入口 PHP 程序 + `_dir/` 系统目录（业务代码在 `_dir/lib/`），拷进文档根目录即可运行。
> 核心场景：AI 开发的文档即时展示与分享；团队/个人轻量知识库。

---

## 0. 项目命名（待定）

候选名（按推荐排序）：

| 候选 | 含义 | 特点 |
|---|---|---|
| **DirDocs** | 目录即文档 | 直白好记，域名/包名大概率可注册 |
| **Foldoc** | Folder + Doc | 短，但发音一般 |
| **DocNest** | 文档巢（嵌套目录） | 体现层级结构 |
| **PlainMD** | 朴素 Markdown | 强调"纯文件、无数据库"哲学 |
| **DocSoil / 文档土壤** | 文档生长在文件夹里 | 中文场景有画面感 |

暂定：**DirDocs**（README 标题、目录 `_dir` 与该命名呼应）。

---

## 1. 产品定位

### 1.1 一句话描述

把装着 Markdown 的文件夹变成在线文档站：文件夹 = 目录层级，`.md` = 页面，同目录附件直接显示，拷进任何 PHP 主机的任何目录即可运行。

### 1.2 目标用户

- 用 AI 写项目文档的开发者（AI 产出 md → 即刻可阅读、可分享）；
- 需要内网知识库但不想部署 Confluence/BookStack 的小团队；
- 只有廉价 PHP 虚拟主机的个人/小企业。

### 1.3 与竞品的差异

| 方案 | 部署 | 数据 | 问题 |
| --- | --- | --- | --- |
| BookStack / DokuWiki | 重（DB、依赖、配置） | 数据库 | 运维成本高 |
| Docsify / MkDocs | 需构建或引入 JS | 文件 | 非纯服务端，SEO/直链弱 |
| **本产品** | **拷进目录即用，零配置** | **纯文件树** | **无后台重功能（克制）** |

### 1.4 产品哲学（三根支柱，任何功能提议先用这三条过筛）

| 支柱 | 含义 | 落地判据 |
|---|---|---|
| **部署方便** | 一个文件拷进目录就能跑 | 无安装向导、无数据库、无 composer、无服务器规则依赖；放根目录或任意子目录皆可 |
| **安全** | 塞进来的文件不会被执行、不会被利用 | 唯一执行面是 `index.php`；所有用户内容经白名单与转义后才输出（4.5） |
| **使用方便** | 文件即内容，所见即所得 | 磁盘上 Typora/VS Code 能预览的链接，网页上同样可点；相对路径不断链 |

三条冲突时的裁决顺序：**安全 > 部署方便 > 使用方便**。
（例：附件走 PHP 输出而非服务器直出，牺牲一点性能，换来白名单可控——这是安全优先；
  文档根取 `index.php` 所在目录而非外部配置，牺牲灵活性，换来零配置——这是部署优先。）

功能判据：**只做搜索、设置、编辑、上传四件事**，不做用户系统、不做流程审批；
任何"要装依赖 / 要改服务器配置 / 要教用户配路径"的方案，一律否决。

---

## 2. 技术栈与运行环境

- PHP **7.1 ~ 8.4 全兼容**（一次编写，全版本可跑——廉价虚拟主机大量停在 7.x，
  放弃它们等于放弃目标用户；写法上避开 8.0+ 独有函数，如 `str_starts_with`
  用 `strpos() === 0` 等价实现，见第 7 节）；
- 依赖：**零依赖**（内置轻量 md 解析器，CommonMark 常用子集；不引入 `league/commonmark`，理由见 4.6）；
- Web 服务器：Apache / Nginx + PHP-FPM / 内置 `php -S`；
- 数据库：**无**；
- 存储：文件系统本身。

### 2.1 部署形态（2026-10-13 三次修订：入口恒定，业务代码随 `_dir/lib/` 升级）

**原则：`index.php` 与 `_dir/` 放在同一个目录，该目录即文档根（`MD_ROOT = index.php 所在目录`）。
没有安装向导、没有路径配置、没有数据库——放在哪里，哪里就是文档站。**

```text
情形 A：放在 Web 根目录 → 整个 Web 根就是文档目录
public_html/
├── index.php        ← 程序入口（恒定不变，只做 boot/加载/路由分发）
├── _dir/            ← 系统目录：程序代码 + 用户数据 + 缓存 + 主题
│   ├── lib/         ← 业务代码（升级时覆盖）
│   │   ├── md_engine.php   # 引擎：文本/路径/URL/渲染/搜索/文件名
│   │   ├── md_i18n.php     # 多语言：界面语言解析/md_t 取词/JS 文案词典
│   │   ├── md_pages.php    # 前台：页面动作/模板辅助/附件/风险检测
│   │   ├── md_theme.php    # 主题：CSS 变量/页面模板/用户主题
│   │   ├── md_admin.php    # 后台：鉴权/界面/四功能/分发
│   │   └── lang/           # 语言包（zh-CN 源语言 + en，随升级覆盖）
│   ├── assets/      ← 程序资产（升级时覆盖）
│   │   └── builtin.css     # 内置基础样式
│   ├── config.php   ← 用户数据：站点配置（升级不覆盖）
│   ├── cache/       ← 用户数据：索引缓存（可随时删）
│   └── themes/      ← 用户数据：自定义主题（升级不覆盖）
├── 项目A/
│   ├── 说明.md
│   └── 架构图.png    # 附件：与 md 同目录，磁盘上 Typora/VS Code 仍可预览
└── 项目B/...

情形 B：放在任意子目录 → 该子目录就是文档根，与上层目录无关
public_html/
└── docs/            ← 这一层就是文档根
    ├── index.php
    ├── _dir/
    ├── 项目A/
    └── 项目B/
```

- 首次访问自动生成 `_dir/`（config、缓存都在里面，无任何交互式安装步骤）；
- **升级 = 覆盖 `_dir/lib/` 与 `_dir/assets/`，`index.php` 永不变**；
  `config.php`、`cache/`、`themes/` 是用户数据，升级不覆盖；
- 文档根就是 `__DIR__`，**程序不接收任何外部传入的"根路径"配置**，
  也就不存在指错根、指向 `/`、指向系统目录的可能；
- `_dir/` 不可写（只读文件系统/权限不足）时降级只读模式：无缓存、无后台、无上传，前端正常展示；
- 程序自身只占两个名字：`index.php` 与 `_dir/`，其余全部是用户内容；
- `_dir/lib/*.php` 第一行是 `MD_BOOT` 闸门：被 Web 直接访问时常量未定义，输出 403 退出——
  纯代码方案，不依赖任何服务器规则。实测直接请求四个 lib 全部 403。

---

## 3. 目录结构规范

```text
文档根（= index.php 所在目录，任意层级）/
├── index.php              # 入口：boot 常量 + 加载器 + 路由分发（~120 行，恒定）
├── _dir/                  # 系统目录：不进文档树、lib 直接访问 403
│   ├── lib/               # 业务代码（升级覆盖）
│   │   ├── md_engine.php  # 引擎（文本/路径/URL/渲染/搜索/文件名/config）
│   │   ├── md_pages.php   # 前台（页面动作/模板辅助/附件/风险检测）
│   │   ├── md_theme.php   # 主题（CSS 变量/页面模板/用户主题）
│   │   └── md_admin.php   # 后台（鉴权/界面/四功能/分发）
│   ├── assets/            # 程序资产（升级覆盖：builtin.css、builtin.js、theme-init.js、admin.css、admin.js
│   │                      #   + vendor/ 自托管三方库：Bootstrap 5.3.3 / Bootstrap Icons 1.11.3 / mermaid 10.9.1 / qrcodejs 1.0.0，零外联）
│   ├── config.php         # 站点配置（用户数据，升级不覆盖）
│   ├── cache/tree.php     # 目录树索引缓存
│   ├── cache/search.php   # 全文搜索索引
│   ├── cache/login.json   # 登录失败计数
│   └── themes/            # 用户自定义主题（default/wiki/dark 内置在 lib/md_theme.php）
├── 项目A/
│   ├── 说明.md
│   └── 架构图.png         # 附件：与 md 同目录
└── 项目B/...
```

注：原设计的 `_dir/admin/` 已取消——后台模块全部由 `index.php` 统一调度（见 4.8），
`_dir/` 只放数据，不放代码。

### 3.1 命名约定

- 系统目录 `_dir/`：随文档目录走，排序靠前，默认隐藏；
- 隐藏规则（分两级，避免误伤用户目录）：
  - 一级：`.` 开头的文件/目录一律隐藏；
  - 二级：`_dir` 特判隐藏（系统目录）；其余 `_` 开头目录**可见**，
    允许用户使用 `_attachments`、`_posts` 这类常见目录（config 的 `hidden_prefix`
    仅保留 `.` 与 `_dir` 两项，不再整类屏蔽 `_` 前缀）；
- 排序：字典序（中文按 UTF-8 字节序，非拼音序——v1.0 不接 intl `Collator`，
  廉价主机常缺 intl；需拼音序时后置为可选增强）；
  支持 `01-`、`02-` 数字前缀；文件头 front-matter 里可指定 `order`（M1 即支持）；
- 首页：默认取排序第一的 md，或 config 指定 `home`；
- 两种模式下的目录页地址：pretty 为 `/项目A/`，simple 为 `?p=项目A`（带不带尾斜杠都归一化）。

---

## 4. 核心模块设计

### 4.1 路由（双模式，后台可切换）

程序支持两种路由模式，config 中 `url_mode` 切换，后台"站点设置"里一键切换并自动生成对应的伪静态规则：

**模式 1：简单模式（默认，url_mode = simple）**

- 路由：查询串路由：`index.php?p=项目A/说明`（虚拟主机 100% 兼容，零配置）；
- `p` 为空 → 首页；
- `?a=admin*` 系列进入后台动作，`?a=file&p=...` 输出附件，`?a=asset&t=..&f=..` 输出主题资产；
  内部动作**只走查询串**，不占用顶层路径名（避免与用户文档里的 `admin`、`file` 目录撞名）；
- 此模式不依赖任何服务器重写规则，任何环境开箱即用，作为兜底模式永远存在。

**模式 2：高级模式（伪静态，url_mode = pretty）**

- URL 与目录结构一一对应，完美体现路径层级：
  `https://site.com/项目A/说明`、`https://site.com/项目A/子目录/文档名`；
- 目录页访问 `https://site.com/项目A/` 自动显示该目录（目录首页 md 或目录索引页）；
- 支持部署在子目录/子子目录：程序自动检测自身所在路径（`SCRIPT_NAME` / `REQUEST_URI` 比对），站内链接全部基于检测出的 base path 生成，伪静态规则按 base path 生成——装在 `docs.site.com/a/b/` 下，规则自动适配；
- 实现方式：所有不存在的路径重写到 `index.php`，程序从 `REQUEST_URI` 去掉 base path 后得到 `p`，再走与简单模式**完全相同**的解析逻辑——两种模式只是"入口取参方式"不同，核心解析层一套代码，避免分叉；
- 后台提供"伪静态规则生成器"：根据当前安装路径（含子目录）输出对应 rewrite 规则代码块，用户复制到自己服务器配置即可：
  - Nginx：`try_files $uri /index.php$is_args$args;`（注意**不能**带 `$uri/`——目录 URL 必须落到 index.php 由程序渲染，否则 autoindex 关闭时会 403；子目录时前缀相应路径）；
  - Apache：根目录 `.htaccess` 只含 `RewriteEngine on` + 重写规则（纯路由用途，**不含**任何 PHP 执行隔离规则——隔离由 4.5 的代码白名单保证，不依赖 `.htaccess`）；
  - 内置服务器（php -S）：提示用 router.php 启动命令；
- 检测与回退：不依赖"重写标记"自动检测（nginx 不给标记，Apache 也不保证）。改为：
  切到 pretty 后，请求一个只存在于 pretty 模式的 URL，程序自检 404/200 并给出后台提示；
- 附件 URL 在两种模式下都由程序输出且**都走 `?a=file` 程序附件通道**（v1.10.1 起 pretty
  同样不再输出静态直连路径——直连路径会被 Web 服务器静态直出，绕开扩展名白名单与
  Disposition 策略，`/x.svg` 会被服务器内联执行脚本）；
- 直接访问 `/项目A/说明.md`：Web 服务器会把它当静态文件直出（绕过白名单）——
  所以 pretty 模式的重写规则分两段（见 4.1 的 `try_files` 写法）：第一段处理不存在的路径，
  第二段把 `svg|md|markdown|txt` 四类"可执行内容"后缀**即使真实文件存在也强制进 index.php**
  （Disposition 由程序判定，惰性的图片/视频保持静态直出）；
  文档访问统一去掉 `.md` 后缀（这是约定，不是缺陷）；
- 直接访问 `/项目A/架构图.png`：png/webp/gif/webm 等惰性类型静态直出（不吃 PHP 性能）；
  `svg`/`md`/`txt` 直出被第二段规则拦进附件通道（MIME 正确、按请求上下文决定 inline/attachment）；
- **模式名冲突**：见上文"内部动作只走查询串"，pretty 模式不占顶层路径名，从根上避免冲突。

**URL 生成原则**：程序内所有链接（侧边栏、面包屑、附件引用）统一走一个 `url_for($path, $type)` 函数，该函数根据当前 `url_mode` 和 base path 输出对应格式，禁止各处拼接 URL。

### 4.2 安全的路径解析（最高优先级）

**唯一原则：程序只认 `MD_ROOT`（= `index.php` 所在目录）这一个根，任何输入都不得把根挪动或越过它。**

```php
// 伪代码（PHP 7.1 ~ 8.4 全兼容）
function md_resolve($p, $whitelist) {
    $root = MD_ROOT;                            // = realpath(__DIR__)，进程内常量，不可被输入改变
    $p = str_replace('\\', '/', $p);            // 输入归一化：Windows 分隔符
    foreach (explode('/', $p) as $seg) {        // 逐段拒绝穿越
        if ($seg === '' || $seg === '.' || $seg === '..') return false;
    }
    if ($p === '_dir' || strpos($p, '_dir/') === 0) return false;  // 系统目录一律拒绝
    $target = realpath($root . '/' . $p . '.md'); // 或附件真实路径
    if ($target === false) return false;
    $target = str_replace('\\', '/', $target);  // realpath 归一化
    $rootR = str_replace('\\', '/', $root);
    // 前缀必须带分隔符：/www/docs 不得匹配 /www/docs-evil
    if (strpos($target, $rootR . '/') !== 0) return false;
    if (!in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), $whitelist, true)) return false;
    return $target;                             // 此后一切文件操作只允许用这个返回值
}
```

补充约束：

- `MD_ROOT` 取 `realpath(__DIR__)`，在入口处定义一次，**任何用户输入都不能影响它**；
- `md_resolve` 是**唯一**被允许从输入构造文件路径的函数；渲染、附件、上传、搜索全部复用；
- 输出与 URL 生成一律用根内相对路径，绝不把绝对路径暴露到前端；
- 符号链接默认不跟随（`is_link` 直接拒绝），防止借链接跳出根；
- Windows 下中文/空格文件名：输入按 UTF-8 处理，全程不做 iconv/mb 转换，仅做大小写折叠比较；
- 所有 403/404 走同一出口，不区分"越界"与"不存在"，避免把目录结构漏给探测者。

### 4.3 目录树扫描与缓存

- 递归扫描（跳过 `.` 开头目录与 `_dir` 系统目录），产出索引：
  `相对路径 => [type: dir|md|attach, title, mtime]`；
- 缓存为 PHP 数组文件 `_dir/cache/tree.php`（`var_export`，include 即得，免 JSON 解析）；
- 失效策略：**按文件 mtime 增量比对**（不能用"根目录 mtime 变化"——编辑 md 只改文件 mtime，
  父目录 mtime 不变，会导致标题、搜索索引、最近更新全是旧数据）；
  扫描时记录每个文件与目录的 mtime，逐项比对，只重建变化项；
  提供 config 开关 `cache => on/off`（文档量 < 数千文件时可直接关闭缓存现扫）；
- 大目录保护：单目录文件数超阈值（如 2000）只取前 N 个并标记截断；
- 可见性：非白名单后缀的文件默认**不进文档树**（与 M2"不可见"验收一致），
  但保留"显示全部文件"的调试开关供管理员排查。

### 4.4 附件处理（核心设计）

- **位置**：与引用它的 md 同目录，程序不做任何移动；
- **白名单**（config 可配，默认）：
图片：`png jpg jpeg gif webp svg ico`
文档：`pdf md txt`
音视频/压缩：`mp4 webm zip`
- 展示：图片直接在页面预览；其他类型显示类型图标 + 大小 + 下载链接；
- **输出原则**：附件 URL 是 `/项目A/架构图.png`（pretty）或 `?a=file&p=项目A/架构图.png`（simple），
  两种模式都进 `index.php`，由程序校验白名单后输出正确 MIME 与字节流。
  Web 服务器**不直出**附件——直出会把 `.php` 伪装附件、`.svg` 脚本等内容暴露执行面，
  而程序输出可以统一做白名单、MIME 修正与安全头（见 4.5）；
- **引用写法**：`![图](架构图.png)`、`[下载](规格.pdf)`。相对路径在 PHP 渲染期统一改写为
  绝对附件 URL，不使用 `<base>`（会破坏页内锚点），也不交给前端 JS 处理；
- 附件通道必须：`realpath` 校验 → 后缀白名单 → 目录树内可见性校验 → 流式输出；
- `svg` 虽在图片白名单内，但按"可执行内容"对待：输出时强制
  `Content-Disposition: attachment` + CSP，避免同源脚本执行；
- 4.4 白名单与第 5 节 `ext_attach` 保持同一份数据，以第 5 节为准。

### 4.5 安全执行隔离（命门，由代码保证）

文档目录是"任何人可能塞文件"的地方，必须保证塞进来的文件**不会被执行**。
本项目的答案分两层，都不依赖 nginx / Apache / `.htaccess`：

1. **执行面收缩**：根目录里唯一被服务器当 PHP 执行的是 `index.php`；`_dir/lib/*.php`
   第一行是 `MD_BOOT` 闸门——被 Web 直接访问时常量未定义，输出 403 退出，**一行代码都不执行**；
   `_dir/config.php`、`cache/*.php` 写成 `return [...]` 形式，被直接访问时无任何输出；
   用户内容（md、附件）一律只读、绝不 include；
2. **代码白名单兜底**（三层，缺一不可）：
  - 渲染/输出任何文件前校验后缀白名单（md 走 `ext_md`，附件走 `ext_attach`）；
  - **绝不 `include`/`require` 文档目录内的任何文件**（用户主题 `view.php` 走 4.7 的白名单 + token 检查）；
  - 用户上传的文件名经 `md_safe_name()` 清洗，`.php`/`.phtml`/`.phar` 等可执行后缀永远进不了白名单；
- **`_dir/` 保护**：`_dir` 下的 PHP 文件（config.php、cache/tree.php）虽可被 Web 直接访问，
  但内容均为 `return [...]` 无输出；`view.php` 主题文件与 `login.json` 一律拒绝
  （在路由层直接拒绝 `_dir` 开头的 `p`，见 4.2；`.json` 本就不在 `ext_attach` 白名单内）；
- 输出内容前统一下发安全响应头：`X-Content-Type-Options: nosniff`、
  `Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self'`——
  由 `md_theme_csp()` 输出；v1.12.0 起全资源自托管（`_dir/assets/vendor/`），无外部源
  需要放行，策略为纯本站 `'self'`（403/404 中止页走静态 `md_security_headers()`）。
  mermaid 运行时 v1.12.0 起回归本地 vendor 按需加载（CDN 政策全史：v1.0 内置 →
  v1.1.1 放宽 → v1.9.0 CDN 化 → v1.12.0 收回，见 v1.9.0 / v1.12.0 小节）。

### 4.6 Markdown 渲染

- 唯一实现：内置轻量解析器（自研，支持 CommonMark 常用子集：标题、列表、引用、代码块、表格、链接、图片、粗斜体、分隔线）。
  **不引入 `league/commonmark`**——依赖自动探测会导致装与不装行为不一致、无法复现测试，
  与"拷进去就能跑"的定位冲突（v1.0 决策）；
- 解析器安全（威胁模型见 4.5，默认假设"任何文件都可能由不可信的人放入"）：
  - **raw HTML 一律转义输出**，不做"可信 HTML"模式（v1.0 不做，需要时用白名单标签子集）；
  - 链接/图片协议白名单：`http`、`https`、`mailto`、相对路径；拒绝 `javascript:`、`data:`、
    `vbscript:`、`file:` 及一切大小写/空白混淆写法（`java\tscript:`、`JaVaScript:`）；
  - 代码块内容经 `htmlspecialchars` 后再包 `<pre><code>`，且必须带 `language-xxx` class；
- 标题锚点：自动生成 id（ASCII 化 + 去重），用于页内 TOC；
- 扩展语法（可后置）：`[[相对路径]]` 站内 wiki 链接、mermaid 代码块原样输出 + 前端渲染
  （运行时从 `_dir/assets/vendor/` 按需加载，见 4.5 与 v1.12.0）。

### 4.7 主题系统

- 主题 = `_dir/themes/<名>/view.php` + `assets/`；**默认三套（default/wiki/dark）以字符串
  内置在 `index.php` 里**，`_dir/themes` 只存放用户自定义主题——这样"升级覆盖 index.php"
  不会与"`_dir` 永不被覆盖"冲突；
- view.php 只输出约定变量，不执行业务逻辑：

```php
<?php /* 可用变量：$site_title $page_title $nav_html $toc_html
         $content_html $breadcrumbs $theme_url $admin_url $is_admin */ ?>
```

- 用户主题的 `view.php` 会被 include，属于"执行用户代码"的入口，因此必须：
  - 只放行 `_dir/themes/<名>/view.php` 一个文件名，用白名单目录名（`[a-z0-9_-]`）匹配；
  - include 前 `md_read_text` 读入 + 静态检查（禁 `<?php` 之外的 `?>` 后输出、禁止出现
    `eval|exec|system|passthru|shell_exec|proc_open|include|require|$_GET|$_POST|$_FILES|$_SERVER`
    等 token），不符则拒绝加载并回退内置主题；
  - 约定变量全部以字符串形式传入，主题内不再接触任何文件系统；
- `assets/` 由程序读取输出（与附件同一通道思路：不经 Web 服务器直出，URL 走
  `?a=asset&t=<主题>&f=<文件>`；pretty 模式下为 `/_theme/<主题>/<文件>`，同样进 index.php）；
- 前端零框架、零构建，原生 CSS 变量换肤，移动端自适应。

### 4.8 后台（克制原则：只做 4 件事）

1. **站点设置**：标题、副标题、主题、白名单后缀、首页指向、是否显示附件区、路由模式切换
   （simple / pretty，切到 pretty 时页面内直接展示当前安装路径对应的伪静态规则代码块，一键复制）；
   存储：写回 `_dir/config.php`（var_export，原子写入：先写临时文件再 rename）；
2. **在线编辑**：textarea 编辑/新建 md，保存前做简单 front-matter 校验（front-matter 从 M1 起就支持，
   不后置到 M6）；
   大编辑引导用户用本地编辑器（后台文案明示本产品的哲学）；
3. **附件上传**：上传到**当前 md 同目录**，文件名冲突自动加序号（`图片 1.png`）；
   保存后插入引用的位置：
   - textarea 带选区（上传前记录 `selectionStart/selectionEnd` 并随上传请求一起提交）→ 插在光标处；
   - 无选区信息（如拖拽/接口调用）→ 追加到 md 末尾；
   - 上传是"先传文件、后插引用"两步，第二步失败时保留已上传文件并在后台提示，不假装成功；
4. **缓存管理**：重建目录树索引、查看统计（文件数、附件数、最近变更）。

- 认证：单管理员，`password_hash` 存 config，session 鉴权；
  登录成功后 `session_regenerate_id(true)`；失败计数落盘在 `_dir/cache/login.json`，
  5 次锁定 10 分钟（`_dir` 只读时该降级为"不锁定 + 后台明示"，不做静默失效）；
- 每个后台动作必须带 CSRF token（session 内随机值，表单/上传/删除全部校验，只接受 POST）；
- 后台本身也走 `index.php?a=admin*`，不新增入口文件。

### 4.9 全文搜索（第一版之后的第一优先级）

- 索引：扫描时提取每个 md 的标题与正文，生成 `path => 文本` 索引（缓存中）；
- 实现：PHP 端 `mb_stripos` 粗匹配即可（文档量小时足够），高亮命中片段；
- 索引随 4.3 的按文件 mtime 增量失效一起更新（编辑 md 后搜索结果必须同步，不能只有缓存重建才更新）；
- 后续可升级：前端 pagefind.js 静态索引，无服务端负担。

---

## 5. 配置项（config.php 完整清单）

```php
<?php return [
    'site_title'   => '我的文档',     // 站点标题
    'site_desc'    => '',             // 副标题/描述
    'site_url'     => '',             // 站点绝对地址（https://docs.example.com）：sitemap/canonical 用，留空自动推断
    'theme'        => 'default',      // 主题名（default/wiki/dark 内置，其余取 _dir/themes）
    'language'     => 'auto',         // 界面语言：auto 按浏览器 Accept-Language / zh-CN / en（只翻界面不翻内容）
    'url_mode'     => 'simple',       // 路由模式：simple 查询串 / pretty 伪静态
    'base_path'    => '',             // 安装子目录（自动检测，可手动锁定）
    'home'         => '',             // 首页文档相对路径，空=自动
    'ext_md'       => ['md', 'markdown', 'txt'],
    'ext_attach'   => ['png','jpg','jpeg','gif','webp','svg','ico',
                       'pdf','zip','mp4','webm'],
    'hidden_prefix'=> ['.', '_dir'],  // 隐藏：'.' 全部隐藏；'_dir' 特判（其余 '_' 开头目录可见）
    'cache'        => true,           // 目录树缓存开关
    'show_attach'  => true,           // 是否展示附件区
    'admin_pass'   => '$2y$...',      // password_hash 结果（空=未设置，访问后台时要求先设置）
    'order_prefix' => true,           // 识别 01- 数字前缀排序
    'read_only'    => false,          // 只读模式（_dir 不可写时自动置 true）
];
```

配置读写约束：

- 文档根 = `index.php` 所在目录，**没有任何路径类配置**（取消 `doc_root`/`web_layout`）——
  指错根、指向 `/`、指向系统目录的风险随之消失；
- `_dir/config.php` 是唯一数据文件，升级不覆盖；`_dir/cache/` 与 `_dir/themes/` 可随时删除重建；
- 缺省值集中在 `index.php` 的 `md_config_defaults()`，config 缺项用默认补，保证升级后旧配置可用。

---

## 6. 开发里程碑

| 里程碑 | 内容 | 验收标准 |
| --- | --- | --- |
| M1 内核 | 扫描树、路由（url_for 双模式）、md 渲染（含安全白名单）、侧边栏、路径安全、front-matter（title/order） | 拷进任意目录即跑；穿越测试全过（含 `..`、`%2e%2e`、`\`、`docs-evil` 前缀、symlink） |
| M2 附件 | 白名单、附件区展示、图片预览、附件走 PHP 输出 | 放入 `x.php`/`x.svg`/`x.html` 均不可执行、不可绕过白名单；`.md` 直链不泄露原文 |
| M3 主题 | 主题约定、三套内置主题、CSS 变量、用户主题安全加载 | 新增主题 = 新增一个目录；含危险 token 的主题被拒绝并回退 |
| M4 后台 | 登录（含锁定/session 重建）、CSRF、设置、md 编辑、附件上传（同目录+光标插入） | 网页可完整管理一个站点；无 token 的 POST 全部 403 |
| M5 搜索 | 全文搜索 + 高亮 | 中文搜索正常；编辑 md 后立即搜到新内容 |
| M6 打磨 | TOC、mermaid、最近更新视图 | 可作为 v1.0 发布 |

每个里程碑的验收都必须覆盖三条支柱（缺一不算过）：

- **部署方便**：不引入 composer 依赖、不要求修改服务器配置、不增加配置项；
- **安全**：新增的输入面（参数、上传、文件名）都有白名单或转义，输出无原始 HTML 泄漏；
- **使用方便**：磁盘上的相对链接在网页上可点；不新增需要用户记忆的规则。

已从范围移除：**Git 提交视图**（需 `proc_open`，与第 7 节"不依赖可能被禁用的函数"冲突，
且多数目标环境无 git；如确需，单独放在 v1.x 可选模块并显式探测函数可用性）。

### 6.1 开发顺序建议

先做 M1+M2（这是产品成立的最小闭环），跑通后立刻真实使用一周，再决定 M4 后台的细节——用出来的需求才是真需求。

---

## 7. 编码规范（给 AI 结对编程的"宪法"）

1. 模块组织：`index.php` 只做入口（boot 常量 → 加载 `_dir/lib/` → 路由分发），
   业务代码按职责分四个模块，函数式分层——`boot → route → action → render`，
   无类自动加载、无命名空间（或仅一个全局前缀 `md_` 的函数命名约定）；
   模块边界：engine（被所有模块依赖的底层）/ pages（前台）/ theme（主题）/ admin（后台）；
   每个 lib 文件第一行必须是 `MD_BOOT` 闸门（被 Web 直接访问时 403 退出）；
2. 零警告：所有用户输入（`$_GET/$_POST/$_FILES`）必须先校验后使用；文件操作后检查返回值；
3. 编码：全链路 UTF-8；文件系统操作注意 Windows 下中文文件名（本地开发跨平台时尤其测试）；
4. 输出：HTML 一律 `htmlspecialchars`；header 前无输出（注意 BOM）；
5. 兼容目标：**PHP 7.1 ~ 8.4 全兼容**。禁用 8.0+ 独有函数与语法：
   - `str_starts_with`/`str_ends_with`/`str_contains` → `strpos(...) !== false` / `strpos(...) === 0`；
   - `match`、命名参数、构造器提升、`?->`、`readonly`、枚举 → 一律不用；
   - 类型声明可用但只用于注释性标注，不依赖 strict_types 行为；
   不依赖 `proc_open`、`exec`、`shell_exec`、`symlink` 等可能被禁用的函数；
6. 隐式硬依赖清单（部署说明里必须写明）：`mbstring`（搜索/长度判断用 `mb_stripos`/`mb_strlen`）、
   `json`（登录锁定计数）、`session`。缺 `mbstring` 时降级为 `stripos`（中文分词质量下降，
   后台给出提示，不做静默失败）；
7. 文件名安全（上传/新建/重命名统一走 `md_safe_name()`）：
   - 去路径（只取 basename 归一化后的最后一段）、拒绝 `.`/`..`/空段；
   - 拒绝 Windows 保留名（`con prn aux nul com1-9 lpt1-9`，含扩展名形式）；
   - 拒绝以点或空格结尾、去除控制字符、限制长度（整路径 ≤ 240 字节）；
   - 保留中文与空格，但 `url_for` 生成 URL 时**逐段** `rawurlencode`，`#`、`?`、`%`、`&` 必须编码；
8. 安全红线（review checklist）：

- [ ] 所有路径经 `md_resolve` 校验且落在 `MD_ROOT` 内
- [ ] 所有输出经过白名单或 `htmlspecialchars`
- [ ] `.md` 与附件后缀不进 Web 服务器静态直出（走程序输出，见 4.1/4.4）
- [ ] 绝不 `include`/`require` 文档目录内文件（用户主题 `view.php` 走 4.7 的白名单+token 检查）
- [ ] 后台全部动作有鉴权 + CSRF token
- [ ] 上传文件名过滤（`md_safe_name` + 后缀白名单 + MIME 与后缀一致性检查）
- [ ] 链接/图片协议白名单（拒绝 `javascript:`/`data:` 等，见 4.6）

---

## 8. 与 AI 协作开发的方式（本地开发流程）

1. 本文件（SPEC）放在项目根，每次会话开场提供给 AI；
2. 参考代码策略：如需参考彩虹外链 / dirlist 的风格，只喂**代表性片段**（路由分发、配置读写、文件系统操作），并声明"参考风格与兼容性写法，不复制业务逻辑与素材"；
3. 每个功能先改 SPEC 再写代码，防止范围蔓延；
4. 踩坑记录追加到本文档第 9 节，逐次累积；
5. 开发期先以 **simple 模式 + `php -S`** 跑通（`php -S 127.0.0.1:8080` 已能用 `?p=`），
   pretty 模式的伪静态规则只在真实 Nginx/Apache 上验收——避免一开始就被重写规则卡住。

---

## 9. 踩坑记录（开发中持续补充）

> 2026-10-13 立项。
> 2026-10-13 第一轮评审：执行隔离不再依赖 nginx/Apache `.htaccess`，改为代码白名单保证（4.5）。
> 2026-10-13 第二轮评审：取消安装向导与 `doc_root` 配置，文档根 = `index.php` 所在目录（2.1）；
> 仓库根的 `.htaccess` / `nginx.htaccess`（均为 0 字节占位）与"唯一入口"形态不符，**已删除**，
> 伪静态规则改为纯路由用途、由后台生成供用户自行粘贴。
> 2026-10-13 第三轮评审：PHP 目标从 8.0+ 放宽为 7.1~8.4（廉价虚拟主机大量停在 7.x）。
>
> **M1 开发实测踩坑（`index.php` 完成后补充）：**
>
> 1. **页面函数必须自己收尾**：`md_page()` 输出 HTML 后没有 `exit`，入口继续往下跑，
>    页面末尾又吐出一个 404 页（一个响应两个 `<title>`）。所有"输出整页"的出口都要
>    以 `exit` 结束，不能依赖调用方不再执行。
> 2. **附件白名单漏了 md/txt**：SPEC 4.4 的白名单含 `md`/`txt`，代码默认值却漏了，
>    导致"下载源文件"这种基础诉求 404。白名单两处（SPEC 与代码默认值）必须逐项对齐。
> 3. **相对链接改写必须做词法归一化**：`项目B/x.md` 里写 `../说明.md`，直接字符串拼接会
>    得到 `项目B/../说明.md`，URL 里带着 `..`（难看且危险）。已加 `md_normalize_rel()`
>    纯词法消解 `.`/`..`，越界即弃链（输出 `#`）。注意：归一化只发生在渲染期拼 URL 时，
>    `md_resolve` 仍然拒绝任何含 `..` 的输入，两层各管一段。
> 4. **站内文档 URL 统一不带 .md**：侧边栏/目录列表/正文链接三处生成点一开始都带后缀，
>    已统一走 `md_strip_doc_ext()` 后再交给 `url_for`。用户直接带后缀访问也接受
>    （路由层剥），但生成的链接一律无后缀。
> 5. **PHP 函数表不会因"看起来都定义过"而齐全**：重构时误删了 `md_is_hidden()` 导致
>    整站 500。改完必须 `php -l` + 打一遍全站回归（本次回归覆盖：首页/目录页/文档页/
>    附件/404/穿越 6 类共 11 条用例），不能只看语法检查。
> 6. **目录页优先渲染"首页文档"而非索引列表**：`md_show_dir` 找到 README/index 就直接渲染文档，
>    空目录才显示列表——这与用户"放个 README 就是首页"的直觉一致，但也意味着目录页
>    不显示附件区以外的东西，属预期行为，写在此备查。
>
> **M2/M3 开发实测踩坑（附件与主题完成后补充）：**
>
> 7. **"塞进来的文件不会被执行"在代码层只能做到一半**：实测把 `evil.php`/`evil.html` 放进
>    文档目录后直接请求，Apache/Nginx/php -S **都会按自己的 handler 执行/渲染它们**——
>    这是服务器行为，程序白名单管不到。已确认的边界：
>    - 程序通道（`?a=file`、文档路由、树扫描）全部拒绝，文件不可见、不可下载；
>    - 但**静态直出**绕开程序，唯一彻底的解法是文档目录放 Web 根之外（与零配置冲突，见 2.1 取舍）；
>    - 落地措施：`md_exec_risk_files()` 扫描危险后缀并在页面顶部出警示条（未登录只报数量，
>    已登录列清单——清单本身就是信息泄露，不能给匿名访问者）。程序自身的 `index.php` 与
>    `_dir/` 是白名单内已知项，不算风险、不出现在警示里。
> 8. **PHP 注释里不能出现 `<?php` / `?>` 字样**：写"主题必须用 `<?php ... ?>` 包住"的注释
>    直接把后面的代码变成 HTML 输出，`php -l` 报"Unclosed '{'"且行号指向几百行外。
>    注释里提到 PHP 标签必须拆字（如"php 开/闭标记"）。这个坑连踩两次，记第三次警醒。
> 9. **主题 token 扫描会误杀 CSS 字体栈**：`-apple-system,BlinkMacSystemFont` 里的
>    "system" 命中 `system()` 函数黑名单，合法主题被拒。token 扫描是纯文本匹配，
>    误杀无法完全避免（宁可误杀的取舍不变），但写示例主题时要避开这些子串。
> 10. **用户主题必须"纯 php 单块"**：主题文件只允许一个 php 开标签、块外无输出
>    （闭标记可省略，省略后只允许空白）。这样 include 进来的行为完全可预测，
>    不存在"标记外裸写 HTML"这种无法静态分析的形态。

> **M4 后台实测踩坑（`dirdocs.cc` 真机验证后补充）：**
>
> 11. **表单的 `do` 动作必须同时出现在 POST 字段里，不能只放在 URL 参数**：
>     登录/编辑/上传三个表单最初只把 `do=login|edit|upload` 写在 `action="?a=admin&do=..."`
>     里，而 dispatch 检查的是 `isset($_POST["do"])`——结果 curl 回归全过（curl 测试脚本
>     手工补了 `do` 字段），**真实浏览器提交全部静默失败**：登录后跳回登录页且无错误提示。
>     这个 bug 只有真机浏览器测试能抓到。规则：凡走 `$_POST["do"]` 分发的表单，
>     必须同时 `<input type="hidden" name="do" value="...">`，URL 参数只作兜底。
> 12. **`is_file()` 有 stat 缓存，测试脚本连查两次会误报**：clear 缓存后立即 `is_file()`
>     仍返回 true，加 `clearstatcache(true, $path)` 后才正确。这是测试代码的坑，
>     不是产品代码的坑，但会让人误判产品有 bug——记录备查。
> 13. **编辑器对"文件不存在"的路径要区分两种意图**：`?a=admin&do=edit&f=新路径.md`
>     既可能是"想编辑但不小心打错"，也可能是"想新建"。判据是父目录是否存在 +
>     路径形态合法 + 后缀在白名单——三者都满足就当新建（进入空表单），否则才报错。
>     否则用户从文档列表点"编辑"进不存在的文件只会看到一句"文档不存在"，
>     而新建文档必须绕道 `?a=admin&do=new` 再手填完整路径，体验断裂。
>
> **M5 搜索实测踩坑（补充）：**
>
> 14. **`mb_substr_count()` 是 PHP 8.0+ 才有**：写搜索命中计数时顺手用了它，
>     本地 8.4 回归全过、线上 PHP 7.3 直接 fatal。7.1 兼容目标的含义是
>     **每个新函数都要查最低版本**，不能凭手感。已改为循环计数。
> 15. **秒级 mtime 是增量失效的精度上限**：测试里同一秒内改文件再改回，
>     索引判定"未变化"不重建。真实使用不会一秒内改回，但测试脚本必须
>     `touch($file, time() + 2)` 造出新 mtime，否则会误判产品有 bug。
>     产品侧不改（提精度要存内容 hash，成本不值）。
> 16. **搜索结果页与文档路由的优先级**：`?q=` 与 `?p=` 同时存在时，
>     q 优先（用户意图是搜索）；文档页再从 q 取词高亮。搜索框在侧边栏，
>     移动端移到顶栏——同一套 CSS 断点控制显隐。
>
> **M6 打磨实测记录：**
>
> 17. **mermaid 运行时用 npm 包内置，不走 CDN**：从 npm registry 拉
>     \`mermaid@10.9.1\` 的 UMD 构建（\`dist/mermaid.min.js\`，3.3MB 自包含），
>     放到 \`_dir/assets/\`，经独立的 \`?a=asset&t=_sys&f=...\` 通道输出
>     （白名单只放行 \`mermaid.min.js\` 一个文件名）。\`_sys\` 这个名字用户主题
>     永远注册不了（主题名正则不允许下划线开头），通道天然隔离。
>     内网/离线环境可用；首次访问不带 mermaid 块的页面不加载这 3.3MB。
> 18. **mermaid 渲染是异步的，测试必须等**：\`mermaid.render()\` 返回 Promise，
>     页面加载后立即查 \`.mermaid-box svg\` 会得到 0。等 3-4 秒或用
>     \`evaluate\` 查 \`pre.mermaid svg\` 才准。另外渲染失败时 mermaid 会把
>     CSS 塞进 \`pre\` 的 textContent，下次再渲染同一容器必失败——
>     所以脚本每次取完 \`textContent\` 后立刻清空容器。
> 19. **TOC 只收 h2-h4 且层级按最浅可见层级归一化**：h1 是页题，收进来没有
>     导航意义；若文档从 h3 开始，缩进以 h3 为 l0 基准，不会出现一级全是
>     空档。滚动高亮用 scroll 事件 + 100ms 节流，把视口内最靠上的标题
>     对应项标 `on`（左侧竖线 + 加粗）。
>     移动端移到顶栏——同一套 CSS 断点控制显隐。
>
> **M6.5 架构重构记录（单文件 3102 行 → 入口 + 四模块）：**
>
> 20. **入口恒定，升级只覆盖 `_dir/lib/` 与 `_dir/assets/`**：用户明确要求"升级只覆盖
>     `_dir`、index 不变"。落地形态：`index.php` 123 行只做 boot 常量 + 加载器 +
>     路由分发；业务代码拆到 `_dir/lib/` 四模块（engine 1017 行 / pages 502 行 /
>     theme 263 行 / admin 1025 行）；内置 CSS 外置为 `_dir/assets/builtin.css`
>     （md_theme_base_css 改为读文件 + static 缓存）。
> 21. **`MD_BOOT` 闸门是"代码进 Web 根"的安全前提**：`_dir/lib/*.php` 第一行
>     `if (!defined('MD_BOOT')) { http_response_code(403); exit; }`——被 Web 直接
>     访问时常量未定义，一行都不执行。实测四个 lib 直接请求全部 403。
>     这是纯代码方案，把"服务器规则依赖"转成"常量存在性检查"，不依赖任何 .htaccess。
> 22. **用户数据与程序资产在 `_dir/` 内分区**：`lib/`+`assets/` 随升级覆盖，
>     `config.php`/`cache/`/`themes/` 是用户数据升级不覆盖——分界线写在目录名上，
>     升级脚本只要按名单覆盖两个子目录，不会碰用户数据。
> 23. **重构用"函数边界表"驱动，不手工剪切**：先脚本提取全部 105 个函数的起止行，
>     声明式分配到四个模块，校验"无重复无遗漏"后才生成文件。手工剪 3000 行必错，
>     脚本化 + 校验器是关键。拆完三套回归（M4 21 条 + M5 13 条 + M6 22 条）全过才算数。
>
> **M6.9 后台 Bootstrap 5 化记录（2026-10-13 晚）：**
>
> 24. **CDN 预设系统**：后台 UI 的 Bootstrap 资源走 CDN，预设注册表
>     （`md_cdn_presets()`）内置 6 个源：BootCDN / StaticFile / 字节跳动静态库
>     （三个国内）+ jsDelivr / unpkg（国际）+ offline（离线紧凑回退）。
>     `admin_cdn = auto` 时按 `Accept-Language` 含 zh 选国内源。全部预设经实测可达；
>     换预设只改 config 一行，CSP 头按当前预设动态放行对应 host。
> 25. **CSP 必须按预设动态生成**：后台页面原来复用前台的 `md_security_headers()`，
>     里面 script-src 只有 self/unsafe-inline——CDN 的 bootstrap.bundle.js 会被挡。
>     现在 `md_admin_page()` 自己算 CSP：把当前预设的 css/js/icons 三个 host
>     加进 style-src/script-src。这是"CDN 方案"的必备伴生改动，漏了它页面能看不能动。
> 26. **包体收益**：内置 CSS（builtin.css 6.5KB）+ 后台样式（原来 3.5KB PHP 字符串）
>     全部换成 CDN 的 Bootstrap 43KB（gzip 后约 8KB）+ 本地只留 15 行紧凑补充样式。
>     换来完整的设计系统（卡片/表单/表格/徽标/图标/深浅色适配），
>     后台所有旧 CSS 类（md-card/md-msg/md-tbl/md-row/md-hint）删除，改用 BS 类。

---

## 10. 发布 checklist（v1.0）

- [ ] 装在根目录、装在 `/docs/`、装在 `/a/b/` 三种位置，Apache / Nginx / php -S 均验证通过
- [ ] 目录穿越测试通过（`..`、`%2e%2e`、反斜杠、`docs-evil` 前缀、symlink、超长路径）
- [ ] 用户文件不可执行测试通过（`x.php`/`x.svg`/`x.html` 放文档目录，确认不执行、不出现在树里）
- [ ] `.md`/附件后缀不被 Web 服务器静态直出（直接请求 `/项目A/说明.md` 走程序处理）
- [ ] 中文文件名 + 空格 + 特殊字符（`#?%&`）测试通过
- [ ] 三套默认主题可切换；含危险 token 的用户主题被拒绝
- [ ] 后台四功能可用；无 CSRF token 的 POST 全部 403
- [ ] README（产品故事：AI 文档的展示层 + 部署形态图示）、LICENSE（建议 MIT）、截图
---

## 11. v1.1 变更记录（2026-10-13 大重构）

### 修复
- **带空格文件名无法嵌图**：`md_inline` 图片/链接正则新增 `<...>` 角括号 URL 语法
  （注意进入 md_inline 前文本已转义，匹配的是 `&lt;`/`&gt;` 形态；未参与匹配的
  捕获组 PHP 会给空串，选分支要用非空判断不能用 isset）；`md_rewrite_links`
  改为先按段 rawurldecode 再编码（`%20` 双编码修复），`#锚点` 拆出单独编码接回。
- **搜索结果点不回文档**：index.php 路由改为「不带 ?p= 时 ?q= 才算搜索」，
  否则结果链接 `?p=...&q=...` 永远回落搜索页，正文高亮从未生效过。
- **UTF-8 BOM**：`md_read_text` 统一剥 BOM，带 BOM 文件的 front-matter 恢复解析。
- **`md_resolve` 的 `is_link($c)`** 检查的是相对 CWD 的路径，改为按文档根绝对路径。
- **多站点 session 串号**：cookie path 跟随 base_path，session 名掺 MD_ROOT 哈希，
  7.3+ 加 SameSite=Lax。
- **搜索高亮劈坏 HTML 实体**：三处高亮函数高亮前先保护 `&xxx;` 实体。
- **Content-Disposition** 文件名改 RFC 5987（ASCII 回退 + filename*=UTF-8''）。

### 新增/接线
- **目录树缓存真正启用**：`md_tree_load()` 统一入口（v3 指纹 + 60 秒新鲜期，
  写操作主动清缓存），缓存内容含扫描期元数据（title/order/sort，只读每篇文件头 8KB）。
- **排序**：front-matter `order` 与 `01-` 数字前缀真正生效（`md_split_order_prefix`，
  后跟数字不算前缀），导航/目录/面包屑显示名剥前缀；`md_children` 统一排序。
- **附件下载支持 Range（单区间）**，视频可拖进度条。
- **后台改账号+密码登录**（`admin_user` 配置，默认 admin，大小写不敏感），
  防爆破改按 IP（login.json 存分 IP 桶，5 次锁 10 分钟）。
- **后台新功能**：文档重命名/删除、附件管理页（分组列表/多文件上传/复制引用/删除）、
  修改账号、概览页（PHP 版本/缓存状态/风险清单）。引用写法教学区分含空格（角括号）。

### 前台/后台重做
- **彻底移除 Bootstrap 与 CDN 预设**，全自托管设计系统（builtin.css 内 token +
  组件类，后台复用）；CSP 收紧为 `script-src 'self'`（行为脚本全部外置
  builtin.js / admin.js，theme-init.js 同步防 FOUC），页面零内联脚本。
- 2026 简约风：毛玻璃 sticky 顶栏、暗色三态切换（auto/light/dark + localStorage）、
  右栏 TOC + scrollspy、代码复制按钮 + 语言角标、移动端抽屉侧栏、打印样式。
- 配置静态缓存（`md_config_reset` 失效）；搜索索引无变化不落盘。

### 已知取舍
- CSP `img-src 'self' data:` 仍拦外链图片（SPEC 4.x 既定）。
- 树缓存 60 秒 TTL：后台写操作即时清缓存，FTP 等外部改动最多延迟 60 秒可见。
- `admin_cdn` 配置键遗留不读（UI 已无 CDN 预设）。

### v1.1b 前台重写（同日，参照用户 demo「Elegant & Minimal」）
- **色板改白纸墨迹单色系**：accent=墨色（浅 #111827 / 深 #f9fafb），深色为
  Linear 级深空炭灰（#090a0f 系），token 增加 --elevated/--text3/--code-bg/
  --code-fg/--mark-bg/--border-strong；选中态=微底色+实心小圆点。
- **CDN 政策放宽（用户明确允许）**：仅引 Bootstrap Icons 字体（1.11.3），
  按 Accept-Language 自动选 BootCDN（国内）/jsDelivr（国际），两源都写进
  CSP style-src/font-src；图标纯装饰，字体缺失功能不受影响（功能图标仍为
  内联 SVG）。Bootstrap CSS/JS 仍不引——设计系统自绘。
- 暗房代码块：JS 把 pre>code 包进 .code-deck（头栏=语言名+复制按钮），
  任何主题下恒暗；JS 失效退化为恒暗圆角块。
- 前台内置资产 URL 追加 &v=MD_VERSION（一年强缓存通道，升级后必须换 URL
  才能拉新——复用后台 Agent 的同一结论）。
- 新增上一篇/下一篇翻页卡（同目录排序相邻文档，md_pager_html；注意路由的
  $docRel 无后缀，匹配树 rel 前必须 md_strip_doc_ext）。
- 搜索：顶栏改 Stripe 式胶囊（图标+输入+Ctrl K 角标），Ctrl/Cmd+K 与 /
  聚焦；侧栏搜索移除。侧栏改「最近更新 + 目录标签 + 虚线导轨树」。
- 断点对齐 Bootstrap：侧栏抽屉 <992px，TOC ≥1280px。

### v1.1.1 前台 v2「高级技术文档主题」（同日深夜，用户原型驱动 + CDN 政策再放宽）
- **CDN 政策终版**：用户明确「Bootstrap CSS/JS 都可以引，且前后台共用一个 CDN 源」。
  config 新增 `cdn` 键（auto = 按语言自动选），`md_cdn_presets()/md_cdn_active()`
  6 预设（bootcdn / npmmirror 阿里 / staticfile / baomitu / jsdelivr / unpkg），
  每预设存完整三件 URL（ali 的 /files/dist/ 形态特殊，不搞拼接）。后台设置页下拉
  统一管理；CSP 按当前预设主机动态生成（style/script/font 三处）。
  注：清华 TUNA 不镜像 bootstrap 库，预设列表未含；如有具体源可加。
- 前台构建于 Bootstrap 5.3 之上：builtin.css 变成「BS 之上的覆盖层」，模板可用
  BS 工具类；主题属性统一为 data-bs-theme（light/dark 双态，theme-init.js：
  localStorage > 站点配置 dark > 系统偏好）。
- 新增：纸张卡正文 .doc-stage（860px 浮起卡片）、阅读进度条、h2 锚点复制、
  代码块行号（.code-line-numbers）、bylines 元信息（阅读时长 + mtime 相对时间）、
  树标签（README/index=首页；mtime≤7天=NEW）、反馈胶囊（localStorage 记忆）、
  编辑此页链接（admin_pass 非空时）。版本徽标 1.1.1。
- CSS 坑：`.md-doc pre` 与 `.code-deck pre` 同特异性(0,1,1)且后者在前会反盖——
  deck 规则用 `.md-doc .code-deck pre` 提特异性。

### v1.2.0 阅读体验专项（2026-10-14，用户反馈驱动：字号偏大间距偏宽 + 图片要弹窗预览）
- **调研结论**（github-markdown-css 5.8.1 / VitePress vp-doc.css / Infima 官方源码实测，
  MDN 与中文社区为离线共识）：移动端正文就是 16px（不缩水）、行高 1.5-1.7
  （中文社区 1.7-1.8）；无任何一家用负 letter-spacing；h1 24-32 / h2 20-24 /
  h3 17-20；移动正文区左右 padding 16-20px；代码 13-14px。
- **排版阶梯落地**：正文桌面 16px/1.75、移动 15px/1.72；h1 28/24、h2 22/20
  （上距 2.2em/2em、下 0.6em/0.5em）、h3 18/17；段落/列表下距 1em；
  .doc-stage 移动 padding 1.25rem 1.1rem；**全站负字距归零**（西文习惯，中文忌）。
- **图片 lightbox**：正文图与附件缩略图点击一律页内弹窗（深遮罩+blur、92vw/86vh、
  caption=alt/文件名、ESC/遮罩/按钮关闭、焦点圈定+滚动锁 md-lock、禁拖拽），
  链接包图片 stopPropagation 不再跳外站。关闭采用隐藏不销毁（visibility:hidden
  + opacity 0，留过渡）。
- C 项打磨：外链自动新窗+图标、表格/代码横向滚动渐隐暗示（local/scroll 双背景法）、
  overflow-wrap:anywhere 防长词撑爆、标题 scroll-margin=顶栏+0.75rem、移动端
  h2 锚点常显加大触达、夜间图片 brightness(.88)、打印样式复核。
- 版本 1.2.0。教训复利：**改资产必升 MD_VERSION**（一年强缓存通道）。

### v1.2.1 两个小修（同日用户反馈）
1. **面包屑末段显示文件名而非标题**：doc 路由的 docRel 不带 .md 后缀，
   md_title_of 查树（键带后缀）永远失配 → front-matter/H1 标题在面包屑与
   <title> 从未生效。修复：查树/直读都按 ext_md 白名单补后缀再试一次。
   顺带消除「子目录/细节」式误读（移动端换行把末两段挤同行的观感问题，
   现在末段是完整标题「细节文档」）。
2. **抽屉关闭 × 贴标题**：Bootstrap 5.3.3 官方 .offcanvas-header 只有
   display:flex + align-items:center，没有 justify-content:space-between
   （各 CDN 官方包一致，不是坏包）。在自己覆盖层补 justify-content。

### v1.2.2 移动端悬浮目录按钮（用户建议）
右下角圆形 FAB（bi-list-ul 图标，52px，accent 底白字，safe-area 适配），
点击展开 offcanvas 目录抽屉——纯 data-bs-toggle 触发零 JS；z-index 1030
低于遮罩 1045（抽屉打开时被自然盖住），≥992px 由媒体查询 + d-lg-none 双保险隐藏。

### v1.2.3 修正：FAB 改为「本页大纲」弹层（用户澄清：是文章目录不是侧边栏）
FAB 点击弹出 offcanvas-bottom 底部弹层展示当前页标题大纲：builtin.js 把桌面
TOC 克隆进弹层并保留 .md-toc 类（scrollspy 同时高亮桌面栏与弹层两份）；点大纲项
跳锚点后自动收起（getOrCreateInstance().hide()）；无大纲页面自动隐藏 FAB。
坑：克隆体带 .md-toc 类会被桌面的 .md-toc{display:none} 命中，弹层内须
display:block 解锁；克隆须在 scrollspy 初始化之前注入。

### v1.2.5 两个体验修复（用户 1080p 实测反馈）
1. **DevTools 刷 CSP 违规**：bootstrap 的 min 文件带 sourceMappingURL 注释，
   开着开发者工具时浏览器会去拉 CDN 的 .map，被 default-src 拦截刷屏。
   CSP 增加 connect-src 'self' + CDN 主机（md_theme_csp）。仅影响开发者体验，
   不开 DevTools 的访客永远不会请求 .map。
2. **Windows 1080p 侧栏文字发虚**：body 上的 -webkit-font-smoothing:antialiased
   （+ text-rendering:optimizeLegibility）在 Windows 强制灰阶抗锯齿、关掉
   ClearType 亚像素渲染，非高分屏小字整体发虚——这是 mac 导向的设置。
   已移除交给系统默认渲染；侧栏树字号 .84rem → .865rem（.tree-link 与
   .md-nav 两处，漏一处会被子规则反盖）。

### v1.3.2 右栏大纲精修（用户建议：让本页大纲更舒服实用）
- **连续进度导轨**：大纲整条 1px 发丝线（替代每项独立竖线），JS 注入 2px
  accent 进度段随阅读向下伸长——一眼看出读到全文哪个位置；active 项 accent
  加粗即可，不再依赖竖线。
- **层级分层**：h2 项 .8125rem/--text2；h3/h4 项 .75rem/--text3（缩小降灰），
  阅读动线更清晰。
- **回到顶部**：大纲底部常驻 .md-toc-top（bi-arrow-up-short），平滑回顶；
  只注入桌面侧栏（移动弹层克隆发生在注入前，天然干净）。
- **spy 改造**：移除 IntersectionObserver 优先路径，统一 scroll+100ms 节流
  （IAB WebView 实测 IO 与 scroll 事件均不派发；scroll 方案在所有环境一致）。
  调试手段：临时 window.__mdSpy 暴露 pick/current（已移除）。
- **并发会话坑**：两个 php -S 绑同一 8123 端口（Windows 允许重复绑定，请求
  随机分发到新旧进程）+ opcache/缓存叠加 = 版本号忽新忽旧。规则：起服前先
  taskkill 全部 php.exe；开发期资产失效靠 bump MD_VERSION。

### v1.4.0 主题系统重构：主题包 与 显示模式 解耦（用户架构反馈定版）
- **概念修正**：原 default/wiki/dark 三选一其实是「一套主题的三种模式」——
  auto/light/dark 是显示模式不是主题。现拆为两个独立配置：
  `theme`（风格包）+ `mode`（auto/light/dark），任何主题包自带深浅两套色板。
- **主题包**：`_dir/themes/<名称>/theme.php` = name + dark(默认深浅倾向) +
  vars(浅色全套变量) + vars-dark(深色全套)；可选 view.php 整页模板覆盖（原机制）、
  assets/ 资产（原通道）。用户建目录写 theme.php 即可获得一个风格完全不同的主题，
  出现在后台主题下拉（显示名取 manifest.name）。
- **内置播种**：default / wiki 两个包随程序播种到 themes/（升级覆盖内置包目录，
  自建目录不受影响）；MD_READONLY 或包损坏时回退内置硬编码色板（零配置不变）。
- **安全**：theme.php 是数据文件，manifest 校验（--xxx 键格式 + 值字符白名单，
  禁分号/花括号/冒号 → CSS 注入与 url() 进不来），不合法整体拒绝回退默认；
  失败结果不进请求内缓存（修好文件当请求即可重读）。
- **旧配置迁移**：config theme=dark → 自动换算 主题=default + 模式=dark。
- 后台设置页拆成「主题（风格包）」+「显示模式」两个下拉；config 新增 mode 键。
- 主题包文件清单见 _dir/themes/default/theme.php（wiki 同构）。

### v1.5.0 主题系统终版：完整主题包（WordPress/Typecho 模式）
用户裁决：主题不是换配色——一个主题=整套表现层（模板+样式+资产），换主题换整个版式。

**主题包结构** `_dir/themes/<名称>/`：
- `theme.php` 主题清单：name / dark（深浅倾向）/ vars + vars-dark 两套完整变量
  （数据文件，manifest 校验：键 `--[a-z0-9-]`，值字符白名单，禁分号花括号冒号）。
  纯色板包 = 只有 theme.php：共用 default 的版式，配色经 $headAssets 生效。
- `view.php` 整页模板（主题即代码）：md_page 变为调度器——发安全头、解析主题/模式、
  准备上下文（$cfg $ctx $theme $mode $home $searchUrl $recentUrl $q $tocHtml $cdn
  $mermaidUrl $hasAdmin $headAssets $themeUrl $adminUrl + MD_VERSION）后 include。
  **模板验证器（token 黑名单+单开标签）已删除**：装主题=装代码，只装可信来源
  （能往 themes/ 写文件的人本就能改任意 PHP，原沙箱是伪防线）。view.php 建议带头部守卫。
- `assets/` 静态资产：?a=asset&t=<名称>&f=<文件>（原有通道）。

**内置包**：`default`（三栏纸张版式完整包：theme.php + view.php）、`wiki`（纯色板包，
演示共用版式换配色）、`ocean`（示例：完全独立版式的完整包，单栏居中深海风）。
升级覆盖规则更新：**default 为内置包随升级覆盖；wiki/ocean 及用户自建目录不受影响**
（ocean 虽内置但定位是示例，用户可改可删）。

**调度器**：md_page = 发安全头 → md_theme_resolve（主题+模式解耦，theme=dark 自动迁移）
→ 解析模板（自身 view.php → default view.php → md_page_fallback 应急简页）→
extract($ctx, EXTR_SKIP) 注入上下文 → include → exit。
显示模式（auto/light/dark）与主题完全独立，独立配置键 config.mode。

### v1.5.1 修复：主题下拉出现两个「默认主题」
根因链：ocean 包的 vars-dark 为空数组（深浅同色主题的合法形态）→ manifest 校验
要求 vars-dark 非空 → 整包被拒 → md_theme_list 标签兜底取了内置清单的显示名
「默认主题」→ 下拉出现两个同名项（选第二个实际仍是 default 色板）。
修复：① vars-dark 允许为空，自动沿用 vars（深浅同色主题合法）；② 清单标签兜底
改为目录名（且仅当包文件存在时才取 manifest 显示名）。
教训：**校验失败必须让错误显形（标签=目录名），不能静默回退成一个看似合法的
默认值**——静默回退把配置错误伪装成了重复数据。

### v1.6.0 SEO：canonical / 每页描述 / sitemap / noindex（2026-10-14）
DirDocs 是服务端渲染纯 HTML，SEO 底子天然好；本版补齐缺的头部信号与站点地图。

- **每页 title 去重**：页面标题与站点名相同（如首页）不再拼「A · A」。
  `md_page()` 统一组装 `$ctx['html_title']`（已转义），主题模板只负责输出。
- **每页 meta description**：取渲染后正文首段纯文本前 150 字（`md_meta_description`，
  无 mbstring 时逐字符复制保证不截断 UTF-8），取不到回退 site_desc；不再是全站一句。
- **canonical**：`<link rel="canonical">` 统一由 `md_page()` 按 `$ctx['seo_canonical']` 输出，
  绝对地址 = `md_site_origin()`（site_url 配置优先，否则按请求推断；**刻意不信任
  X-Forwarded-Proto**——可伪造头进 canonical 是缓存投毒面，安全 > 使用方便）。
  规范地址规则（`md_canonical_for`）：索引名文档（README/index/首页/说明）规范地址取
  所在目录、根目录归「/」——同一内容不再有 `?p=readme` 与 `/` 两种 URL 互抢；
  simple 模式根目录用「/」形态而非 `?p=`。
- **noindex**：搜索结果页与最近更新页 `<meta name="robots" content="noindex, follow">`；
  后台所有页面加 `X-Robots-Tag: noindex, nofollow` 响应头（含登录页）。
- **Open Graph / Twitter card**：og:site_name / og:type（文档 article）/ og:title /
  og:description / og:url + twitter:card=summary。
- **JSON-LD 面包屑**：`md_render_breadcrumbs` 重构为「数据（`md_breadcrumb_data`）+
  渲染」两层，JSON-LD BreadcrumbList 与页面可见面包屑同源；当前页段以 canonical 收尾。
- **sitemap**：`?a=sitemap` 动态输出 sitemap.xml（`md_send_sitemap`），lastmod 取文档
  mtime；规范地址与 canonical 同一套规则（首页归「/」、索引名文档并入目录、去重）。
  **index.php 自 v1.0 以来第一次加路由**（3 行）——升级故事从「index.php 永不变」
  修订为「index.php 极少变，业务仍在 _dir/lib/」。
- **后台 · 基本设置新增「SEO 设置」**：唯一字段 `site_url`（站点绝对地址，留空自动），
  config 白名单/归一化（无协议补 https://、去尾斜杠）；表单内直接展示当前
  sitemap 绝对地址。host 推断只放行域名字符/IPv4/带端口的 [IPv6]，Host 头异常
  （CLI/恶意头）时全站省略绝对地址标签、sitemap 输出空 urlset。
- **主题包契约更新**：view.php 应输出 `$ctx['html_title']` 与 `$ctx['head_meta']`，
  不再自行拼 title/description；旧主题不受影响（只是拿不到新标签）。

### v1.6.1 修复：pretty 模式三处路由缺陷（启用伪静态前必修）
用户准备在 dirdocs.cc（根目录安装）开伪静态，实测排查发现 pretty 模式存在三个
互相叠加的缺陷，全部落在路由层，simple 模式不受影响：
1. **根目录安装下全站 URL 落首页**：`md_route_p()` 的 URI 解析只有「base 非空」与
   「scriptDir 非空」两个分支，根目录安装两者皆为空 → `$p` 恒为空，任何伪静态
   地址都渲染首页。补第三分支：`url_mode === 'pretty'` 时 URI 即文档路径，
   `/index.php` 直连与 `/index.php/PATH_INFO` 形态归一为剩余路径。
2. **`/_theme/<主题>/<文件>` 无人分发**：pretty 模式主题资产 URL 是路径形态，但
   `md_send_asset` 只认 `?a=asset&t=&f=`，原代码甚至把 `_theme/` 前缀重置成空
   （资产请求返回首页 HTML，ocean 等自带 assets 的主题包样式全挂；default/wiki
   走 `_sys` 查询串通道幸免）。修法：md_route_p 保留 `_theme/` 前缀原样返回，
   index.php 在树加载前按前缀翻译成 t/f 并分发 `md_send_asset`（其自带主题名/
   后缀/穿越校验，非法形态 404）。
3. **URI 百分号编码未解码**：pretty 的 `$p` 是编码形态（`%E9%A1%B9...`），而
   simple 的 `$_GET['p']` 已由 PHP 解码——导致标题/树匹配/canonical 全部双重
   出错。修法：URI 分支结果按段 `rawurldecode`；解码出 `..` 无碍，md_resolve
   逐段拒绝。
教训：**pretty 模式此前从未在根目录安装被完整跑过**——三个缺陷相互掩盖
（落首页 bug 让 2/3 根本没机会暴露）。多形态功能必须对每种「安装形态 × 路由
模式」组合至少跑通一条文档页。

### v1.6.2 右栏大纲自适应 + 防侵入正文（2026-10-14，用户反馈驱动）
三栏版式的右栏大纲此前 `flex:0 0 var(--toc-width)` 死锁 220px，与侧栏（同为
`flex-shrink:0`）一起把 1200–1387px 视口的正文压到 663–829px——窄屏下变成
大纲优先而非正文优先；宽屏（≥1440）富余空间又全堆在正文与大纲之间，大纲永远
220px。参考 VitePress/GitHub 的三栏做法，改为「正文永远优先于大纲」：
1. **宽度弹性化**：新增 token `--toc-max:320px`（builtin.css `:root`/dark 两块 +
   `md_theme_css` 的 `$layout` 串三处同步，保持逐字一致契约，主题包可覆盖）；
   `.doc-toc` 改 `flex:1 1 var(--toc-width)` + `min-width:calc(var(--toc-width) - 20px)`
   + `max-width:var(--toc-max)`——220 起步，有富余长到 320 封顶，紧张时缩到 200 让位。
2. **防侵入阈值 1280px**：大纲显示窗口从 `d-xl-block`（≥1200，v1.1b 文档写的
   ≥1280 但代码一直是 ≥1200，本次纠正到位）改为 ≥1280（VitePress 同款）；
   1200–1279.98 用 `display:none!important` 压过 Bootstrap 工具类（同权重
   !important、源序在后取胜，标记与 md_pages.php 均不用动）。
3. **补 992–1199 死区**：该区间原本既无桌面大纲也无 FAB（`d-lg-none` ≥992 隐藏），
   是无入口盲区；现在 992–1279.98 用 `display:inline-flex!important` 让 FAB 补位
   （底部弹层在任何宽度都可用），FAB 可见性恰与桌面大纲互补。
4. **配套坑（必改）**：无大纲页面 builtin.js 用普通内联 `style.display='none'`
   隐藏 FAB，而样式表 `!important` 按 CSS 级联规则会压过普通内联样式——
   992–1279 的补位规则会误点亮无大纲页面的 FAB。修法：改
   `style.setProperty('display','none','important')`（内联 !important 才压得住
   样式表 !important），已在浏览器实测两种写法的计算值验证。
取舍：1280–1387 正文仍允许轻微流动（763–860px），这是「大纲在就允许流动」的
基准做法（VitePress 1280 / GitHub 1280 档）；要「正文永不偏离 860」可提阈值到
1400（Bootstrap xxl），代价是常见 1280/1366 笔记本失去第三栏。超宽屏（2560+）
正文两侧留白属「纸张居中」设计使然，如需收拢可给 `.md-workspace` 设 max-width
居中（GitHub docs 风格），会改变侧栏贴边的视觉身份，未采纳。


### v1.7.0 后台文档管理重做：目录（分类）管理 + Typecho 式编辑器（2026-10-14，用户反馈驱动）
诉求原话：「把现在的能应急用变成好用能用高频用，弄成类似 typecho 的编辑一样，
文章文档管理分类管理等等」。先过产品哲学三根支柱的筛子，结论：

**裁决：分类管理 = 目录管理，不引入数据库/分类实体。**
文件树即分类是「文件即内容，所见即所得」的自然延伸（磁盘上的文件夹结构就是
栏目结构），零配置不破坏、零新增执行面；引入分类表/多对多关系等于往纯文件
产品里塞数据库，违背「部署方便」与「只做搜索/设置/编辑/上传四件事」的克制原则。
同理否决：拖拽排序（改文件名，破坏 order 语义）、回收站/版本历史（本地文件即
真相，与 Git/FTP 分工）、Markdown 编辑器库（CDN 依赖，后台会残废）。

1. **文档管理页（do=docs）重做为树**：目录在前文档在后，顺序沿用前台
   md_children 的「数字序+名称」；`<details>` 折叠、默认展开；标题列取
   md_scan_head_meta 已采集的 front-matter title / 首个 H1（零新增扫描成本）；
   首页/NEW 徽标；顶部工具条 = 前端实时过滤（纯 DOM 不过服务端）+ 新建文档 +
   新建目录；行内操作 = 查看/编辑/改名/复制/删除，目录行 = 在此新建/改名/删除。
2. **目录 CRUD（新增 do 分支）**：`dirnew`（单段目录名 + 父目录下拉，重名直接拒
   ——目录是分类，名字必须可控，不像附件自动加序号）、`dirrename`（改名单段=
   改名，换父目录=移动整棵子树，内部文档附件一起走）、`dirdel`（只许删空目录，
   rmdir 非空即拒——防误删整棵内容树）。全部复用 md_safe_dirname /
   md_resolve_path_only / md_tree_cache_clear，写完照旧清缓存。
3. **编辑器升级**：标题字段（保存时合并/生成 front-matter title，见
   md_admin_title_save——无 FM 且标题非空才生成标准头，有 FM 逐行改写 title 行，
   其余键值原样保留）；「目录下拉 + 文件名」双字段取代手打全路径（服务端把
   dir+base 归一成 f，旧 ?f= 全路径提交与旧书签全部兼容）；Markdown 工具条
   （粗体/斜体/删除线/行内代码/H1-H3/列表/任务/引用/链接/图片/表格/代码块/
   分隔线，选中包裹、无选中插占位符并选中，execCommand('insertText') 保撤销栈，
   setRangeText 兜底）；Ctrl/Cmd+S 保存、Ctrl+B/I/K、Tab/Shift+Tab 多行批量
   缩进；beforeunload 离开拦截（仅在有改动时）；状态栏（字数/字符/行数/保存时间）。
4. **AJAX 保存（渐进增强，服务端永远兜底）**：表单提交带 ajax=1，md_admin_doc_edit
   识别后走 md_admin_json 返回 {ok,msg,rel}，否则照旧 302 回跳（PRG）；
   fetch 失败自动退回整页提交保内容不丢。CSRF/白名单/路径校验一条都没少走。
   新增复制文档 do=copy（md_unique_name 防冲突，复制后跳编辑器）。
5. **前端全部落在 admin.js/admin.css（零内联脚本，CSP 不放宽）**：admin.js 由
   3 个行为扩到 8 个模块（toast/Bootstrap Modal 确认框替代原生 confirm——无
   Bootstrap 时降级原生 confirm，data-confirm 语义不变）；admin.css 新增目录树、
   工具条、状态栏、toast、Modal 皮肤、过滤框，颜色一律引用现有 token。
6. **兼容**：所有旧 URL（do=edit&f=xxx、do=rename&f=xxx）语义不变；升级仍只覆盖
   _dir/lib/ 与 _dir/assets/，index.php 本次仅 bump 版本号。

已知取舍（写此备查）：移动文档/目录不自动改写文档内相对路径链接（提示用户自行
检查）；删目录不递归；过滤只匹配已加载的树（前端行为，大站点可接受）；
编辑器仍是 textarea 不做分屏预览与图片粘贴上传（列入后续可选）。

### v1.7.9 移动端适配（2026-10-14，用户反馈：「移动端体验不是很好，很多内容都挤在一起」）
桌面信息密度按「一行一条」设计，窄屏全挤成一行。逐页在 390px 视口实测后重排：
1. **文档树改两行结构**：第一行 图标+标题（标题改为整行可点的前台链接，弥补窄屏
   隐藏的「查看」按钮）+ 徽标；第二行 meta 区内 路径+时间同行、操作组独占一行
   （4 个按钮放不下时自然换行，不再横向溢出）。操作按钮在窄屏常显——
   不依赖 `(hover:none)`（部分平板/安卓上报 hover:hover 却无鼠标），按宽度强制。
2. **编辑器**：标题/目录/文件名三列 grid 降为单列；Markdown 工具条改横向滚动
   （16 个按钮换行会占掉半个屏幕，且隐藏滚动条保持干净）；底部「保存/返回」
   操作栏吸底（毛玻璃 + safe-area-inset），textarea 预留底部内边距不被遮；
   textarea 最小高 55vh→48vh。
3. **工具条**：搜索框独占一行，「新建文档/新建目录」两个主按钮 grid 各占半行、
   38px 高（触控友好）。
4. **其余**：顶栏收起「管理后台」标签与版本号；表格字号压到 .8rem、附件表
   「复制引用/删除」允许换行；toast 改底部通栏并避开吸底栏；登录卡片占满宽。
5. 全页面 390px 视口零溢出回归（除附件表在 `.admin-wrap` 内横向滚动，属预期）。

### v1.8.5 表格移动端专项（2026-10-14，用户反馈：「很多表格对移动端都不是很友好」）
前台 Markdown 表格与后台四张表逐页在 390px 视口实测，两类病灶、分别修：
**前台（builtin.css）**：表格原本靠 `display:block + overflow:auto` 横滚不溢出，
但「不溢出」不等于能读——4 列表每列只剩 ~80px，长文本被切成每行一两个字。
1. 表头取消大写 + .06em 字距（小屏上「里程碑」被撑成 3 行），改常规大小写；
2. 单元格允许 `word-break:break-word` / `overflow-wrap:anywhere`；
   行内代码原本 `white-space:nowrap` 会把列撑宽导致整表横滚，改可断行；
3. 表格 min-width 240px：2 列时每列至少 ~100px，避免「方案|数据」这类极窄列；
4. 首列 min-width 5.5em——宽表第一列多为名称/行首，窄了最难读。
**后台（admin.css）**：概览「最近更新」与「附件」两张表在窄屏被三/四列挤压，
路径列只剩 114px、附件文件名列只剩 33px（长文件名竖排成每行 3-4 字）。
1. 两表窄屏卡片化（`display:grid`，表头隐藏）：文件名/路径独占一行可完整换行，
   元信息（大小/时间）一行、操作一行——与文档树、最近更新同一种移动端语言；
2. 附件行内「大小」靠左、「时间」靠右（`justify-self`），不白占宽度；
3. 后台表头同样取消大写+字距；时间列 nowrap 防抖动；
4. 表格容器右缘渐隐提示可横滚（local/scroll 双层背景法，前台同款）。
取舍：卡片化后窄屏看不到列表头，靠字段自解释（路径/时间/操作都是常见字段）；
桌面端（≥768px）仍是标准 table 布局，未受影响（已回归验证列宽与表头）。

### v1.8.6 风险文件警示豁免清单（2026-10-14，用户提议：后台加白名单设置）
背景：官方 Apache 伪静态部署本来就要在根目录放纯 RewriteRule 的 `.htaccess`（4.1），
风险扫描（4.5 配套警示）会永久命中它——警示在"受支持的部署"里常驻，等于训练管理员无视警示
（狼来了效应本身就是安全伤害）。当日早些时候评估的定论是"不给 `.htaccess` 默认白名单"，
本版给出折中：**默认仍然全量警示，管理员在后台显式登记后才豁免**——豁免是知情确认动作，
不是静音按钮；安全边界（程序自身输出白名单）一字不动。
- 配置键 `risk_ignore`（config.php，数组）：每条一个相对路径。精确匹配豁免单个文件；
  **以 / 结尾豁免整个子树**（`工具/`）。路径大小写敏感（不 lowercase，与 ext 列表不同）。
- 归一化（md_config_set）：反斜杠归一化、去空白与首尾斜杠；拒绝空段/`.`/`..` 段；
  去重；上限 100 条。**去重键必须用最终存储值**（含尾斜杠标记）——`目录`（文件）与
  `目录/`（整目录）语义不同，用中间值去重会让文件条目吞掉目录豁免（实测踩过）。
- md_config_load 单独类型守卫（不走"空数组回退默认"循环——空清单 = 不豁免任何文件，合法）。
- 接线：md_risk_ignored()（md_pages.php）+ md_exec_risk_files 标记时过滤——豁免条目
  不进列表、不计数，前台非管理员条数与管理员清单自动一致；仪表盘"安全检查"同函数同受益。
- 后台 UI：设置页「风险文件警示豁免」textarea（每行一条，placeholder 带示例）；
  警示文案追加指引（登记后不再提示）。**不做警示卡片上一键"忽略"按钮**——静音一个
  安全发现应当是设置页里的郑重动作，不是顺手一击。
- 验证：CLI 13 项断言（匹配语义 8 + 扫描过滤 2 + 归一化落盘回读 1 + 坏类型守卫 1 +
  set 返回值 1）全过；php -S 实机冒烟——豁免前前台 `md-warn` 块 1 个，登记
  `.htaccess`/`nginx.htaccess` 后 0 个。MD_VERSION → 1.8.6。

### v1.9.0 mermaid 运行时 CDN 化（2026-10-14，用户拍板：按 Bootstrap 同款逻辑、可选源）
删除 `_dir/assets/mermaid.min.js`（mermaid 10.9.1 UMD，3.2MB，约占分发包九成体积），
mermaid 接入 v1.1.1 的全站统一 CDN 源体系（`config.cdn` 六预设 + auto），后台设置一处选源、
Bootstrap/图标库/mermaid 三类资产同源。
- **政策依据**：SPEC 4.5 原"内置禁 CDN，否则内网场景直接失效"的内网论据在 v1.1.1 后已不成立——
  Bootstrap 本就纯 CDN 无本地回退，断网时页面早已裸奔；mermaid 挂掉降级为"图表渲染失败"占位框
  （builtin.js mermaidBoot 全防御：body[data-mermaid] 传 URL、动态注入 script、onerror/onload
  双兜底、逐图 try/catch、securityLevel strict），同一级别的内容可见降级。本地化只剩成本没有收益。
- **预设表**：每预设新增 `mermaid` 键（钉 10.9.1，与本地包同版本）。URL 形态两系：
  cdnjs 系（bootcdn/staticfile）`/ajax/libs/mermaid/10.9.1/mermaid.min.js`；
  npm 系（ali/jsdelivr/unpkg）`mermaid@10.9.1/dist/mermaid.min.js`。
  **baomitu（字节）全版本不收录 mermaid（实测 8.x/9.x/10.x/11.x 全 404）**，
  该键特判指向其镜像上游 cdnjs 官方 `cdnjs.cloudflare.com`（选中 baomitu 时 CSP 多放行这一个 host）。
- **CSP**：`md_theme_csp()` 的 host 提取列表纳入 `$cdn['mermaid']`——同一预设主机时无变化，
  baomitu 时正确多放行 cdnjs.cloudflare.com（script-src/connect-src 同步）。
- **接线**：`md_theme.php` 页面调度器 `$mermaidUrl = $cdn['mermaid']`（懒加载保留：
  仅含 mermaid 块的页面才输出 `data-mermaid`，无图页面一个字节都不引）；
  `_sys` 资产白名单移除 mermaid.min.js（旧 `?a=asset&t=_sys&f=mermaid.min.js` 从此 404）；
  后台 CDN 下拉文案改「Bootstrap / 图标库 / mermaid」；SPEC 4.5/4.6 与目录树说明同步修订。
- **升级行为**：`MD_VERSION` → 1.9.0。老页面的本地资产 URL 会随页面重新渲染自然消失（页面不缓存）；
  浏览器若缓存了个别旧页，重新拉取后即指向 CDN。
- **验证**：六预设 URL 逐一 HEAD——bootcdn/staticfile/ali/unpkg 200 且 cdnjs 系验过 UMD 头字节，
  baomitu 确认无货走特判，jsdelivr 本机网络不通但与 unpkg 同一 npm 包路径（同构造）；
  php -S 实机——含 mermaid 块的页面 `body[data-mermaid]` 指向 bootcdn CDN URL、旧 _sys 通道 404。
- **内网部署的正解**（备忘）：将来加第七个「离线/本地」预设，把 BS+mermaid 一起本地化，
  不给 mermaid 单开本地后门（那会让资产政策分裂）。

### v1.10.0 界面 i18n：中英双语（2026-10-14，用户拍板：前台+后台一次做完、配置+浏览器自动）

全站约 365 条界面文案（前台 ~120 + 后台 ~245，含 JS 侧 36 条）收进语言包，界面语言可选。
**只翻界面 chrome，不翻译文档内容，不改 URL 结构**（无 /en/ 前缀、无 hreflang）。

- **新模块**：`_dir/lib/md_i18n.php`（第五个 lib 模块）+ `_dir/lib/lang/zh-CN.php`（源语言）
  与 `en.php`。零依赖取舍：语言包是纯 PHP 数组数据文件（return array，同 config.php 模式），
  不用 gettext/.po。两包键集经脚本对账完全一致（365 键）。
- **语言机制**：`config.language` ∈ auto / zh-CN / en；auto 按 `Accept-Language` 含 zh 判定
  （与 `md_cdn_active` 同一先例与取舍，q 值不细分）。请求内 static 缓存——同请求语言恒定，
  因此 tree/search 缓存与指纹**无需**区分语言。语言包文件名先过白名单正则再拼路径（防配置写坏
  导致路径穿越），包损坏回落 zh-CN 源语言。
- **`md_t($key, $vars)`**：当前包 → zh-CN 包 → 键名 三级回落；`{name}` 占位 strtr 替换；
  词条为数组（`one`/`other`）时按 `vars.n === 1` 取分支（英文复数：找到 N 篇 / N 小时前 /
  还可尝试 N 次 / 风险警示 N 个文件等）。键名约定：前台无前缀 / 后台 `admin_` / JS 专用 `js_`。
- **JS 文案通道**：`md_i18n_js_attr()` 把全部 `js_` 键（复数条目展平成 `<key>_one/_other`）
  json_encode 后放进 `<html data-md-i18n="...">` 属性——CSP 不变（无内联脚本，无 script[type=json]
  争议）。builtin.js / admin.js 顶部 `mdT(key, 中文兜底)` / `mdTN(key, n, ...)` 读取，
  **属性缺失时回落 JS 内中文兜底**：用户自改的旧主题模板不认识 data-md-i18n 也照常工作（优雅降级）。
- **主题契约追加**（`md_page` 注入 + 主题包规范注释同步）：新变量 `$lang`；新助手 `md_t` /
  `md_i18n_js_attr()`；模板职责：`<html lang>` 输出 `$lang`、`<html>` 带 data-md-i18n 属性。
  default 与 ocean 两个内置模板已改；用户自定义模板不更新也不报错（文案维持原样）。
- **功能与文案解耦**（不是翻译、无条件生效）：目录索引/首页候选文件名白名单在 README/readme/
  index/首页/说明 基础上增加 `home`（英文站惯例，`md_is_dir_index_name` / `md_home_doc_rel` /
  树标签 stem 名单三处同步）；树标签「首页」文案走 `md_t('tree_tag_home')`。
- **明确不翻**（数据还是数据）：主题包显示名（默认主题/Wiki（青绿）——播种的用户数据文件）、
  `site_title` 默认值「我的文档」（站长数据）、语言下拉里的「简体中文」（语言自名）。
- **后台设置页**：新增「界面语言」下拉（自动（跟随浏览器）/简体中文/English），`md_config_set`
  白名单 + `md_config_load` 归一化（非法值回落 auto）双侧把关。
- **CSP / 安全**：零改动（JSON 走属性通道）；README 安全章节措辞红线未触碰。
- **升级行为**：`MD_VERSION` → 1.10.0（JS 文案改动随 &v= 刷客户端长缓存；语言包随
  `_dir/lib/` 覆盖升级）。启动期错误信息（index.php 缺模块提示）保持原样——lib 加载期 i18n
  尚未就绪，不值得为它引入依赖。
- **验证**：php -l / node --check 全绿；key 三向对账（zh↔en↔代码引用）零缺失零多余；
  残留中文扫描仅剩注释与计划内字符串；php -S 实机——首页/搜索/404/后台登录页 Accept-Language
  双语切换正确（html lang、data-md-i18n JSON、英文复数形态），CLI 模拟登录态渲染后台五页
  全部 lang=en 且除用户数据外零中文。

### v1.10.1 中文拼音排序 + SVG 内联与附件通道收权（2026-10-14）

- **中文按拼音排序**：新增 `md_str_compare()` 统一比较入口——intl 扩展可用时走
  `Collator`（locale `zh`），目录树/搜索平分/后台目录下拉/附件分组/附件表五处排序点全部
  按拼音序（西文名排中文前，与 Windows 资源管理器中文惯例一致），修复 `strcasecmp` 按
  UTF-8 字节（≈码点序≈部首序）导致的中文文档树乱序。intl 不保证装机（廉价虚拟主机常见
  缺失）：缺失/构造失败/比较出错一律回退旧字节序——可选增强，零依赖不破。实例请求内
  static 缓存（构造开销大不能进回调）；`01-` 前缀与 front-matter order 排序键优先级不变。
- **SVG `<img>` 内联**：Markdown `![](x.svg)` 曾因附件通道强制 attachment 而图挂。
  现按 `Sec-Fetch-Dest: image` 判别请求上下文：图片上下文放行 `inline`（所有浏览器把
  image 态 SVG 当惰性图，脚本本就不执行，另加 `script-src 'none'; object-src 'none'`
  CSP 双保险），document/iframe/empty/无 Sec-Fetch 头（老浏览器）仍强制下载——
  "SVG 永远不能以文档形态进浏览器"的安全不变量保持，诱导导航 XSS 面不变。
  前台附件通道与主题资产通道（`/_theme/...svg`）同策略。
- **附件通道收权（对齐 SPEC 4.1/4.4）**：pretty 模式附件 URL 此前是静态直连路径
  （`/项目A/架构图.png`），`try_files $uri` 真实文件优先命中 = 绕开扩展名白名单与
  Disposition 策略，直接访问 `/x.svg` 被 nginx 静态内联**执行脚本**——SPEC 说的
  "附件必须进 index.php"在 pretty 模式实际不成立。现 `md_url_for('file')` 两种模式统一
  输出 `?a=file&p=`；伪静态规则生成器（后台设置页）与 `nginx.htaccess` 样例追加第二段：
  `svg|md|markdown|txt` 四类"可执行内容"后缀即使真实文件存在也不直出（nginx 加 regex
  location 强制落 index.php，Apache 加先行 RewriteRule），图片/视频等惰性类型保持静态
  直出。已部署老站点升级后站内 URL 全部走程序通道（行为即刻修复），手改过规则的老部署
  直连路径暴露面与旧版持平（不更糟）。
- **已知取舍**：pretty 模式附件每请求多一次 PHP 引导（ETag/304/单区间 Range 程序内
  已实现，SPEC 2.1 明文接受此代价）；`robots.txt`/`favicon.ico` 等程序不管的文件在新
  规则下仍可静态直出（不在四类后缀内）。
- **验证**：php -l 全绿；Collator 排序单测（拼音序断言 + 排序键回归 + 字节序对照）通过；
  curl 分发矩阵——`Sec-Fetch-Dest: image`→inline+CSP、document/iframe/empty/无头→
  attachment、md 附件与 png 不受影响、中文路径 RFC 3986 编码 + Range 206 全过；
  浏览器实测 `![](x.svg)` 内联显示成功且 SVG 内 `alert(1)` 未执行。
- **升级行为**：`MD_VERSION` → 1.10.1（后台 css/js 带 &v= 的长缓存自动刷新）。

### v1.11.0 后台 TOTP 两步验证（2026-10-14，用户拍板：安全支柱性价比最高的单因子升级）

- **纯 PHP 实现（RFC 6238/4226，零依赖）**：`md_admin.php` 新增 TOTP 区段——
  `md_totp_secret_gen()`（160 位随机 → Base32 32 字符，`ord(random_bytes)&31` 无模偏差）、
  `md_totp_base32_decode()`（容忍小写/空格/填充符，循环计数写法守 PHP 7.1 底线）、
  `md_totp_hotp()`（`pack('N',0).pack('N',n)` 拼 64 位大端，不碰 PHP 7.1 无保证的写法；
  `hash_hmac('sha1')` 是内置的）、`md_totp_verify()`（±1 周期窗口 + `hash_equals` +
  会话内 `md_totp_last` 防重放）与 `md_totp_uri()`。正确性由 RFC 4226 Appendix D
  官方 10 向量锚定（密钥 `"1234…"` → `755224…520489`）。
- **存储与找回**：密钥存 config 的 `admin_totp`（空 = 未启用），`md_config_set` 白名单
  + `md_config_defaults` 双侧登记。与 admin_pass 同一张表、同一条找回路径——
  验证器丢失 = 文件权限者删 config.php 该行复位（设置页与 README 都写明）。
- **登录两步流**：密码因子通过后 `session_regenerate_id` + 会话记 `md_totp_pending`
  → `?a=admin&do=totp` 验证码页（`autocomplete="one-time-code"`）→ 动态码通过才
  `md_login_ok()`。pending 态 `md_is_admin()` 恒为 false，guard 之前无法触达任何后台
  资源；启用期间密钥被文件权限者复位时清中间态回登录页，不死循环。分发器里 POST
  分支必须先于 GET 分支——表单 action 带 `do=totp`，GET 分支先拦会把提交渲染成空页
  （集成测试抓到的真 bug）。**动态码失败与密码共用同一 IP 失败桶**（5 次锁 10 分钟）。
- **设置页启停（两段式）**：启用 = `totp_setup`（密钥只进会话，同时清防重放计数）
  → 出二维码 + 手输密钥 → `totp_enable` 提交一个有效动态码验明设备可用才落盘
  （防止扫码失败把自己锁死）；`totp_cancel` 随时可放弃。禁用 = `totp_disable`
  必须提供当前动态码（防会话劫持后一步拆掉第二因子）。**已启用时 setup/enable 一律
  拒绝**——换验证器必须走「验当前码解除 → 验新码启用」两段，每段都有因子把关，
  杜绝劫持会话直接换绑自己的设备。
- **二维码**：CDN 预设表六源全部追加 `qrcode` 键（qrcodejs 1.0.0；baomitu 指向
  cdnjs 上游——与 mermaid 同款特判），`md_theme_csp` host 收集同步加该键（当前六源
  与 Bootstrap 同 host，CSP 面实际不变）。`md_admin_page`/`md_admin_body_scripts`
  加 `$extraScripts` 参数（插在 admin.js 之前，defer 文档序保证库先执行）；设置页
  确认步输出 `<div class="admin-qr" data-totp-qr="otpauth://…">`，admin.js 模块 9
  渲染，**CDN 不可达时 JS 什么都不做——服务端恒显示密钥文本，手输绑定是完整兜底**
  （渐进增强与全站一致）。二维码容器恒白底，深色模式下照样可扫。
- **i18n**：23 个 `admin_totp_*`/`admin_flash_totp_*` 新键，zh-CN/en 双包对账一致
  （388 键），与代码引用三向对账零缺失零多余。
- **明确不做**：备份码（v2 再议，找回路径已够用）；邮件/通知类恢复（零依赖红线）；
  TOTP 不改变既有会话有效期。
- **验证**：php -l / node --check 全绿；RFC 向量 + 窗口/重放/容错 14 项单测全过；
  curl 集成——未完成两步登录访问 totp 页回登录页、密码正确 302 进验证码页、错码
  计数提示、正码进后台、**登录刚消费的码立刻拿去解绑被防重放拒绝**、已启用时
  totp_setup 直接换绑被拒、+1 周期码正常解绑回落单因子、启停全程 config 落盘正确；
  浏览器实测——设置页二维码真实渲染（qrcodejs CDN 加载 + 168×168 位图 + 分组密钥），
  取消后回到未启用态。测试全程备份并原样还原了用户 config.php 与 login.json。
- **升级行为**：`MD_VERSION` → 1.11.0（admin.js/admin.css 变更随 &v= 刷长缓存）。

### v1.12.0 全资源自托管：CDN 全面移除（2026-10-14，用户拍板：纯本地零依赖）

v1.1.1 放宽、v1.9.0 扩大的 CDN 政策整体收回——前后台不再向任何第三方域发出请求，
「自托管」从 README 措辞回到事实。评审依据成立：隐私（访问者 IP 暴露给 CDN 方，
与自托管叙事冲突）+ 供应链（CDN 被劫持 = 全站 XSS——CSP 放行的源本身不可信时
白名单形同虚设，SRI 只防篡改不防源作恶）。v1.9.0 时代「CSP 含 CDN 域禁写零第三方」
的 README 措辞红线随本次移除一并解除。

- **vendor 目录**：`_dir/assets/vendor/` 内置 Bootstrap 5.3.3（css + js bundle）、
  Bootstrap Icons 1.11.3（css + woff2/woff）、mermaid 10.9.1（UMD 3.3MB，回归 v1.0
  同款形态）、qrcodejs 1.0.0——版本与 v1.11 预设表逐字节同源（npmmirror 拉，
  jsDelivr 在当前网络不可达），来源/改写/SHA-256 记录在 `vendor/README.md`。
  包体 +3.9MB 是「纯本地」的直接代价，换断网/内网可用与零供应链面；
  mermaid 仍按需加载（无图表页面不产生这 3.3MB 请求），常规页面零感知。
- **`md_cdn_active()` 保名换芯**：六源预设表删除，函数改返回本地
  `?a=asset&t=_sys&f=vendor/...` URL（带 `&v=` 版本号刷长缓存）。函数名与返回键位
  （key/label/css/js/icons/mermaid/qrcode）刻意不变——主题契约（`$cdn` /
  `$mermaidUrl` / `md_cdn_active`）与全部调用点（前后台 head、页脚 bundle、
  TOTP 二维码脚本）零改动、对这次变更无感知。`$cfg` 参数保留为签名兼容。
- **_sys 通道白名单改 MIME 映射**：`md_send_sys_asset` 从「文件名数组 + 按后缀
  二分 MIME」改为「文件名 => Content-Type 显式映射」——vendor 引入字体文件后
  按后缀猜不再够用；新文件必须显式登记，不登记即 404（白名单纪律不降级）。
- **vendor 文件后处理**（仅两处，均记录在 vendor/README.md）：
  `bootstrap-icons.min.css` 的 `url("fonts/…?hash")` 相对路径在查询串路由下解析
  不出正确地址，改写为 `url("?a=asset&t=_sys&f=vendor/fonts/…")`（以 `?` 开头的
  相对引用只替换查询串，simple/pretty 两模式通用；去 hash，通道自带 ETag）；
  `bootstrap.bundle.min.js` 剥掉尾部 `sourceMappingURL` 注释（无 .map 可拉，
  防 DevTools 404 噪音）。其余与上游逐字节一致。
- **CSP 退化为纯 'self'**：`md_theme_csp` 不再按预设拼 host，静态输出五段
  （style-src 保留 unsafe-inline 仅供主题变量块；connect-src 留 'self'），前后台同款。
- **设置页 CDN 下拉删除**：`config.cdn` / `admin_cdn` 成为不读的历史键（defaults
  保留键位，旧 config 经 array_merge 照常加载），保存通道三处（`$_POST['cdn']`、
  预设校验、`md_config_set` 白名单）同步移除；语言包删 `admin_cdn_auto` /
  `admin_st_cdn_label` 两键（388 → 386，双语对账一致）。
- **验证**：php -l 全绿；残留清查零命中（`md_cdn_presets` 零引用、代码零 CDN URL、
  `admin_cdn` 仅存 defaults 兼容键位）；curl 实测 9 个 _sys 资产（vendor 七文件 +
  builtin.css + theme-init.js）全部 200 且 MIME 正确，白名单外 `vendor/README.md`
  与 `../` 穿越均 404；php -S + 浏览器实测——前台/含 mermaid 页/后台登录/概览/
  设置页 CSP 响应头均为纯 'self'，`performance` 资源条目零外域，mermaid SVG
  真实渲染、bootstrap-icons 字体 loaded（相对路径改写生效）、后台 22 个图标节点
  正常、设置页 CDN 下拉消失。
- **升级行为**：`MD_VERSION` → 1.12.0（&v= 变更使浏览器弃用旧缓存 URL，
  统一改拉本地 vendor）。

### v1.12.1 渲染/安全两补丁 + Vary + 发布校验和（2026-10-14，测试套件首次逮到的真问题）

- **删除线渲染补齐**：后台工具栏一直有「删除线」按钮（插入 `~~文字~~`），md_inline
  却没有对应规则——工具栏承诺了渲染器没有的功能（第 12 节已知问题）。
  补 `~~…~~` → `<del>`（与粗/斜体同区、行内代码占位还原之前）。
- **零宽字符补剥**：README 声称协议判断前"剥掉控制字符与零宽字符"，`md_safe_url`
  实际只剥 `\x00-\x20\x7f`——零宽（U+200B/200C/200D/U+FEFF）可把 `javascript:`
  拆成"相对路径"放行。按 UTF-8 字节序列 `str_replace` 补剥（刻意不用 /u 正则：
  输入不保证合法 UTF-8，/u 会整体返回 NULL，NULL 再过 preg_match 反而放行）。
  README 措辞自此与实现一致。
- **Vary: Accept-Language**：`language=auto` 时 md_page 给全部前台 HTML 页下发该头，
  提示共享缓存（CDN/反代）按语言区分缓存（外部评审 i18n × HTTP 缓存交互项）。
  固定语言不发（页面与请求头无关，白发伤缓存命中）；后台 no-store 不涉及。
  伪静态规则卡不再加运维提示——头已由程序自动下发，比规则卡文案更根治。
- **发布校验和**：`php tools/make_checksums.php` 在仓库根生成 checksums.txt
  （21 个文件：index.php + _dir/lib/** + _dir/assets/** 含 vendor；用户数据
  不在发布范围故不收录），`sha256sum -c` 可验。
- **验证**：测试套件 139 断言全绿（del 断言翻转 + 零宽拒绝 2 例新增）；
  php -S + curl 实测首页 `Vary: Accept-Language`；sha256sum -c 21 OK。
- **升级行为**：`MD_VERSION` → 1.12.1。

### v1.13.0 RSS/Atom 订阅（2026-10-14，外部评审功能清单第一名：树缓存 + lastmod 现成，生成成本几乎为零）

- **路由与格式**：`?a=rss` 输出 Atom 1.0（`application/atom+xml`），最近更新的 20 篇，
  `Cache-Control: max-age=600`；与 sitemap 同款零新缓存——直接吃 `md_tree_load`。
  两种路由模式下都走查询串（与 `?a=sitemap`/`?a=recent` 同一先例，不占顶层路径名）。
- **纯函数拆分**：`md_rss_entries`（条目收集：mtime 降序、上限 20、目录索引文档不单列、
  空 title 回落显示名、同刻按路径升序稳定输出）与 `md_rss_xml`（构建：全经 md_e 转义、
  md_url_abs 绝对地址、canonical 去后缀与 sitemap 循环同一做法——这个"去后缀在调用方"
  的约定首轮实现就踩了一次，测试抓出）都可离线单测；`md_send_rss` 只做取数输出。
- **取舍与 sitemap 对齐**：推断不出站点绝对地址时输出空壳 feed——Atom 链接语义要求
  绝对地址，相对链接的订阅源是废的，宁缺勿假。
- **订阅发现**：md_page 组装 head_meta 时恒输出
  `<link rel="alternate" type="application/atom+xml">`，阅读器/浏览器可直接发现；
  后台页面不输出（noindex 区域）。
- **集成实测逮到的 bug**：XML 声明在 sender 与 builder 各 echo 一次、实机输出重复两份
  ——php -S + curl 冒烟抓到，收权进 builder，测试加「声明只出现一次」断言锁死。
- **验证**：测试套件 153 断言全绿（新增 feed 组 14 断言：合法 XML/转义/绝对链接/
  上限/排序/目录索引剔除）；php -S + curl 实测 200 + MIME 正确、真实文档条目、
  首页发现链接在位。
- **升级行为**：`MD_VERSION` → 1.13.0。

### v1.14.0 修订历史（2026-10-14，外部评审：文件驱动系统做版本历史有独特优势——保存即原子写之前先复制一份）

- **存储形态**：`_dir/cache/history/<md5(rel)>/`（目录名哈希化，路径拼接无穿越面）
  下 `meta.json`（rel + versions 清单：文件名/时间/大小）+ 版本文件
  `Ymd_His-<md5前8>.md`。触发点两处：编辑器**覆盖保存前**归档当前内容（内容有变才存，
  与最新版相同安静跳过，不产生空转版本）；**恢复操作前**也先归档当前内容——恢复本身
  可回退，全功能只有一个不可逆操作（清空，带确认）。
- **上限与护栏**：每篇最近 20 版（裁剪连文件一起删）；单版超 2MB 不归档（防 cache
  目录膨胀失控）；只读模式整体不工作。版本读取文件名过严格白名单正则
  （`^[0-9]{8}_[0-9]{6}-[0-9a-f]{8}\.md$`）再 basename 收口。
- **位置取舍**：放 cache/ 是评审原案——后台「清除缓存」只删 tree.php/search.php
  两个文件、天然不碰 history/；但用户手工清空 cache 目录或删除文档会连带删历史，
  页面提示文案写明。重命名/移动后历史留在旧键下（v1 已知限制，随文档说明）。
- **对比算法**：行级 LCS，先裁公共前后缀把 O(n×m) 压到差异核心；差异核心任一侧
  超 400 行或输出超 800 行放弃渲染（too_big），页面引导改用「预览」——文档级对比
  不值得在后台渲染百万级格。预览超 200KB 截断显示。
- **后台接线**：`?a=admin&do=history&f=<rel>`；文档管理行操作加「修订历史」按钮
  （bi-clock-history 图标）；列表（时间/大小）+ 每版「预览 / 与当前对比 / 恢复此版」
  （POST 全过 CSRF，JS 确认文案带路径与时间）；admin.css 加对比块样式
  （删除行红底/新增行绿底，走全局 danger/ok 变量深浅色自适应）。
- **i18n**：18 个 `admin_hist_*`/`admin_tip_history` 新键，zh-CN/en 双包对账一致
  （404 键），测试套件双语对账断言自动覆盖。
- **验证**：测试套件 177 断言全绿（新增 history 组 24 断言：归档/去重/裁剪/读回/
  穿越拒绝/清空/LCS 对比与护栏，全部在注入的临时目录跑、不碰真实 _dir/cache）；
  php -l 全绿；php -S 冒烟——未登录访问 history 路由正确回落登录页（非 404，分发已接线）。
- **升级行为**：`MD_VERSION` → 1.14.0（admin.css 变更随 &v= 刷长缓存）。

### v1.15.0 草稿位 + Ctrl+K 弹窗 + 打印补漏（2026-10-14，外部评审功能清单收尾三件）

- **draft 草稿位**：front matter 写 `draft: true`（真值白名单 true/1/yes/on，防误写
  `draft: 0` 意外隐藏）即对访客完全隐藏——直链 404（`md_show_doc` 读自身 front matter
  判定，连树都不用查，管理员登录态可预览）、导航树/目录页/首页选择不出现（`md_children`
  加 `$includeDrafts` 参数：前台默认滤掉，后台文档管理传 true 照常列出并打琥珀色
  「草稿」标记）、搜索不命中（索引构建时排除，转草稿时清掉旧索引条目）、sitemap /
  RSS / 最近更新不出现。运行时冒烟用临时草稿走全链路验证（直链 404 + 五处 0 命中），
  测完即删。
- **集成冒烟逮到两个真 bug**：① `md_scan_head_meta` 返回了 draft 但 `md_scan`
  组装树条目时没带——flag 从未到达任何消费方，树缓存也一直是无 draft 的旧形态
  （开发期被旧缓存掩盖，清缓存重扫后才暴露；升级方无此问题——MD_VERSION 在指纹里，
  1.15.0 自动触发全量重建）；② `?a=suggest&q=` 被路由顺序坑了——建议 URL 自带
  `q=`，分发放在 `?q=` 搜索页拦截之后就被抢先渲染成 HTML 搜索页，suggest 分发前移
  并加注释钉死这个顺序约束。
- **Ctrl+K 快速搜索弹窗**：新端点 `?a=suggest&q=`（复用全文索引，前 8 条
  `{t: 标题, u: 链接}`，JSON、noindex、no-store、GET 只读无 CSRF 面）+
  builtin.js 模块 11（防抖 200ms、↑↓ 导航、Enter 打开、Esc/点遮罩关闭、
  竞态序号防旧响应覆盖、失败静默——弹窗是渐进增强）；模块 8 的 Ctrl+K 收权让位，
  `/` 聚焦侧栏搜索框保留。样式纯 CSS 变量（.md-kbd*），文案走 js_ 键 + mdT 兜底
  （4 新键，双语对账）。浏览器实测：唤起、建议渲染、方向键高亮、文案本地化全过。
- **打印样式补漏**：v1 起就有 @media print 块，本轮补三处——`.md-fab`/页脚/
  `.md-tocsheet`/`.md-kbd` 打印时隐藏；`pre/table/blockquote/img` 防 `page-break-inside`。
- **验证**：测试套件 188 断言全绿（新增 draft 组 11 断言）；php -l / node --check
  全绿；临时草稿全链路冒烟 + 浏览器弹窗实测；checksums.txt 重算（21 文件）。
- **升级行为**：`MD_VERSION` → 1.15.0（builtin.css/js 变更随 &v= 刷长缓存；
  树缓存随版本指纹自动重建，草稿标记全量生效）。

### v1.15.1 搜索结果按时间排序（2026-10-14，用户提议：高频使用且难度不高）

- **双模式切换**：搜索结果页排序条（`排序：按时间（新→旧）· 按相关度`），当前形态
  纯文本、另一形态链接（保留 q；`?q=` 相对引用任意深度可用）；`?sort=time|score`
  白名单外回落 score。**默认保持相关度**（命中次数 + 标题命中加成，v1.7 起的既有
  评分行为），切时间序是显式动作——要不要把默认翻成时间序留给用户拍板，一行的事。
- **零新索引成本**：mtime 本就在搜索索引里（index['mtime']，建索引时顺手存的设计
  在 v1.5 就有），结果行补带 mtime + 排序抽成纯函数 `md_search_sort_results`
  （score：分数降序/名称平局；time：mtime 新→旧/分数/名称双平局），可离线单测。
- **suggest 弹窗走时间序**：Ctrl+K 快速跳转场景"最近写的那篇优先"，与搜索页默认的
  相关度序互补——同一份索引，两种消费姿势。
- **验证**：测试套件 195 断言全绿（新增排序组 7 断言：两种序的主序与三级平局）；
  curl 实测——中文排序条渲染、sort=time 当前态切换、非法 sort 回落、suggest 首条
  为最近更新文档；checksums.txt 重算。
- **升级行为**：`MD_VERSION` → 1.15.1。

### v1.15.2 搜索排序偏好交给访客（2026-10-14，用户拍板：不加后台配置，选择权在前端交给每个用户）

- **机制**：URL 显式 `sort=time|score` 即"写下偏好"——服务端落 Cookie
  `dd_search_sort`（一年、HttpOnly、SameSite=Lax、Path 限定安装子目录），之后
  无参数搜索默认跟随访客上次选择；解析优先级 URL 参数 > Cookie > 默认相关度
  （`md_search_sort_resolve` 纯函数，白名单外一律视为未提供，Cookie 无安全语义）。
  切换条两个形态**都带显式参数**（v1.15.1 的"切回相关度"是无参数链接，在偏好
  记忆下会又被 Cookie 拉回去——必须显式覆盖才算"选择"）。
- **为什么 Cookie 不用 localStorage**：排序在服务端渲染（结果列表 + 计数 +
  耗时都是 PHP 出），客户端记忆要嘛整页重排（无数据）、要嘛重定向（闪烁）；
  手写 `Set-Cookie` 头让 PHP 7.1 也能带 SameSite（数组签名是 7.3+，同
  md_session_start 的分版本处理思路）。
- **验证**：测试套件 200 断言全绿（偏好解析 5 断言；其中一条断言期望值笔误
  被套件当场抓住——实现行为对、期望写反，修正断言）；curl 实测五连——
  显式 time 落 Set-Cookie、带 Cookie 无参数跟随时间态、切回 score 落新 Cookie、
  无 Cookie 默认相关度、非法 Cookie 回落默认。
- **升级行为**：`MD_VERSION` → 1.15.2。

## 12. 测试套件（2026-10-14，随外部评审"测试瓶颈"意见引入）

- **跑法**：`php tests/run.php` 一条命令，全绿退出码 0。当前 137 断言，六组：
  TOTP（RFC 4226 官方向量 + 窗口/防重放/容错）、URL 与协议白名单、
  排序（Collator/回退双路径）、Markdown 渲染器黄金样本、i18n 双包对账、
  杂项纯函数（md_safe_name/主题清单校验/front matter/默认配置）。
- **设计约束**：
  - 零依赖红线不破例：不引 PHPUnit/composer，断言助手就是 run.php 里几个函数；
  - 引导方式：runner 自行 define 全套 boot 常量（含 index.php 才定义的
    `MD_READONLY`，漏了会在 md_config_load 炸）后 require 五个 lib——它们只是
    函数定义 + MD_BOOT 闸门，不会执行路由；**绝不 include index.php**（会分发并 exit）；
    CLI 下 `$_SERVER['SCRIPT_NAME']` 是 `tests/run.php`，会把 pretty 模式的
    base path 探测成 `tests/`——runner 里显式置 `/index.php` 视作根安装；
  - 只测纯函数，绝不碰 `_dir/config.php`、cache、themes 的写路径；
    TOTP 验证读写 `$_SESSION` 超全局，CLI 下不调 session_start、直接当普通数组伪造；
  - `time()` 不可注入：TOTP 窗口断言的期望码按调用瞬间时间片现算，整组断言
    必须在同一个 30s 周期内完成，跨边界返回 null 由外层重试（撞边界概率≈耗时/30s）；
  - `md_str_compare` 的 Collator 路径与字节序回退断言按 `class_exists('Collator')`
    环境二选一（用「中/安」这对字区分：拼音序中>安，字节序恰好相反）；
  - tests/ 在仓库根、不在 _dir——部署形态只有 index.php + _dir，测试不随部署出去；
    页面"安全警示"横幅把 tests/ 列为可执行文件是预期现象，不要为此改扫描逻辑。
- **已知问题（由本套件发现，均已于 v1.12.1 修复、断言同步翻转）**：
  - 后台编辑器工具栏有「删除线」按钮（`admin_tb_strike`，插入 `~~文字~~`），
    但 md_inline 没有对应渲染规则，`~~x~~` 原样输出——工具栏承诺了渲染器没有的功能；
  - README「安全设计」称链接协议判断"判断前先剥掉控制字符与零宽字符"，
    `md_safe_url` 实际只剥 `\x00-\x20\x7f`（控制字符+空格），零宽字符
    （U+200B 等）未剥——混淆注入面比文档声称的窄了一道，属文档夸大。
