# AI Image to WordPress Plugin

[中文说明](README.zh-CN.md)

WordPress plugin for AI image generation with OpenRouter. It creates images, compresses them for web performance, applies SEO-friendly filenames, fills readable Alt text, uploads to Media Library, and returns the final image URL.

## SEO Keywords

WordPress AI image generator, OpenRouter WordPress plugin, Gemini image generation, image compression plugin, automatic Alt text, SEO filename, featured image generator, image-to-image editing

## Features

- Generate images from prompt directly in WordPress admin
- Optional image-to-image editing using an existing Media Library image
- OpenRouter model support (default: `google/gemini-3.1-flash-image-preview`)
- Local optimization before upload:
  - target size (KB)
  - max width and min width floor
  - JPEG quality loop
- SEO filename format: `{site}-{keywords}-{timestamp}.jpg`
- Readable Alt text (manual or auto-generated from prompt)
- Upload into Media Library and return URL + attachment ID
- Admin entry points:
  - `Media -> AI Generate`
  - Media list quick button
  - Media row action `AI Edit`
  - Post/Page editor metabox quick link
  - Admin bar quick link

## Installation

1. Upload folder to:
   - `wp-content/plugins/ai-image-to-wordpress`
2. Activate **AI Image to WordPress** in WordPress Plugins.
3. Open `Media -> AI Generate`.
4. Save your OpenRouter API key.
5. Generate and upload images.

## Quick Start

1. Enter prompt.
2. Optional: choose source image for image-to-image.
3. Choose usage profile: `content`, `featured`, or `hero`.
4. Click **Generate and Upload**.
5. Copy generated image URL and use in posts/pages.

## Compatibility

- WordPress: 6.x recommended
- PHP: 8.0+ recommended
- Works with Media Library workflows and page builders (including Elementor)
- Designed to coexist with FileBird because it adds its own generator page/actions instead of replacing media UI

## Verification

```bash
php -l ai-image-to-wordpress.php
node -e "new Function(require('fs').readFileSync('assets/admin.js','utf8'))"
```

## Security Notes

- OpenRouter key is stored in WordPress options (admin only).
- Use HTTPS and strong admin access controls.
- Keep plugin and WordPress updated.

## Author

- GitHub: [qipihen](https://github.com/qipihen)

## License

GPL-2.0+
