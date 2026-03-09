# AI Image to WordPress 插件

[English](README.md)

这是一个基于 OpenRouter 的 WordPress AI 生图插件：在后台生成图片、做本地压缩优化、自动应用 SEO 文件名与可读 Alt 文本、上传到媒体库并返回最终图片 URL。

## SEO 关键词

WordPress AI 生图插件、OpenRouter WordPress 插件、Gemini 生图、图片压缩、自动 Alt 标签、SEO 文件名、特色图生成、图生图

## 核心功能

- 在 WordPress 后台直接输入提示词生成图片
- 支持图生图（可选择媒体库图片或外部图片 URL 作为源图）
- 支持 OpenRouter 模型（默认 `google/gemini-3.1-flash-image-preview`）
- 支持低成本元数据小模型，自动生成更自然的文件名/标题/Alt（默认 `google/gemini-2.5-flash-lite-preview-09-2025`）
- 上传前本地优化：
  - 目标体积（KB）
  - 最大宽度与最小宽度下限
  - JPEG 质量循环压缩
- 提示词缓存（同参数命中时复用已有媒体，节省 API）
- 相似图片去重（感知哈希，上传前拦截重复图）
- 批量生图（一次 1-6 张新图；该次任务自动关闭缓存/去重复用）
- 多 Prompt 批量模式（每行一个 prompt，每行生成一张图）
- CSV Prompt 导入（按行导入到 Batch Prompts）
- SEO 文件名：`{brand}-{keywords}.jpg`（不强制时间戳）
- Alt/Title 通过 AI 元数据改写（失败自动回退）
- 上传到媒体库并返回 URL + 附件 ID
- 多入口可用：
  - `媒体 -> AI Generate`
  - 媒体库列表页快捷按钮
  - 媒体行操作 `AI Edit`
  - 文章/页面编辑侧边栏快捷入口
  - 顶部 Admin Bar 快捷入口

## 安装步骤

1. 将插件目录上传到：
   - `wp-content/plugins/ai-image-to-wordpress`
2. 在 WordPress 插件页启用 **AI Image to WordPress**。
3. 打开 `媒体 -> AI Generate`。
4. 填写并保存 OpenRouter API Key。
5. 开始生成并上传图片。

## 快速使用

1. 输入提示词。
2. 可选：从媒体库选择源图，或粘贴外部图片 URL 做图生图。
3. 如需多个 prompt，可在 `Batch Prompts` 每行填一个，或点击 `Import Prompts CSV` 导入（会覆盖 Batch Count）。
4. 如是单 prompt 多变体，则使用 `Batch Count`（1-6）。
5. 选择使用场景：`content`、`featured`、`hero`。
6. 点击 **Generate and Upload**。
7. 复制返回 URL（可多条），用于文章、页面或特色图。

## 兼容性

- 推荐 WordPress 6.x
- 推荐 PHP 8.0+
- 兼容媒体库常规流程与页面构建器（含 Elementor）
- 与 FileBird 设计上可共存：插件新增独立入口，不替换媒体库主界面

## 验证命令

```bash
php -l ai-image-to-wordpress.php
node -e "new Function(require('fs').readFileSync('assets/admin.js','utf8'))"
```

## 安全说明

- OpenRouter Key 存在 WordPress options（仅管理员可配置）。
- 请使用 HTTPS，并确保后台权限安全。
- 保持 WordPress 与插件更新。

## 许可证

GPL-2.0+
