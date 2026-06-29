# MaoTK Woo Product Migrator

作者：Mao TK 出海猫  
官网：https://www.maotk.com

这是一个 WooCommerce 产品迁移插件，适合“新站已有内容，只想从旧站导入产品”的场景。插件不会做整站覆盖，而是通过任务队列一个产品一个产品地导出/导入。

## 核心功能

- 导出 WooCommerce 产品数据，支持 50、100、250、300 到 1000、全部导出，也支持自定义数量。
- 不管导出多少个，实际执行永远一次只处理一个产品。
- 导入时同样一次只导入一个产品，失败跳过，后续产品继续。
- 失败记录包含旧产品 ID、旧产品链接、标题、SKU、失败阶段、失败原因。
- 支持只重试失败项，不重复处理成功产品。
- 支持本地旧站 `wp-content/uploads` 目录识别，优先从本地图片备份读取。
- 新站已有同名图片时，默认自动改名为 `原文件名-mig-随机数字.扩展名`，不覆盖新站文件。
- 支持按 SKU、slug、标题判断重复产品。
- 支持已有产品跳过、只更新价格库存、更新全部字段、或创建新产品。
- 导出包生成后包含 `manifest.json`、`products.jsonl`、`failures.csv`。
- 临时文件和日志有自动清理策略。

## 推荐迁移流程

完整图文操作流程见：[使用教程](docs/USAGE.md)。

1. 旧站或临时恢复站安装并启用本插件。
2. 在后台进入 `MaoTK 产品迁移`。
3. 先导出 50 个产品测试。
4. 下载导出包。
5. 新站安装并启用本插件。
6. 新站上传导出包，填写旧站 `uploads` 目录路径，例如：

   ```text
   /www/wwwroot/old-site/wp-content/uploads
   ```

7. 先导入测试包，检查产品、分类、属性、主图、相册图。
8. 确认正常后再导出/导入 500、1000 或全部产品。

## 图片处理逻辑

导出时插件记录图片 URL 和相对路径，例如：

```text
2024/05/sofa.jpg
```

导入时如果填写了旧站 uploads 目录，插件会优先读取：

```text
/www/wwwroot/old-site/wp-content/uploads/2024/05/sofa.jpg
```

这样不会占用旧站带宽，也不怕旧站防盗链或 WAF。

## 注意事项

- 导入前请先备份新站文件和数据库。
- 插件依赖 WooCommerce 标准产品结构，不负责复制旧主题的页面设计。
- 复杂主题字段、ACF 字段、SEO 字段可以通过自定义字段导出模式迁移，但建议先小批量测试。
- 如果旧站没有本地 uploads 备份，第一版默认不会强制从 URL 下载图片，避免被防护和带宽限制卡死。

## 文件结构

```text
maotk-woo-product-migrator/
  maotk-woo-product-migrator.php
  assets/
    admin.css
    admin.js
  README.md
```
