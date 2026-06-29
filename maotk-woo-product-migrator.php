<?php
/**
 * Plugin Name: MaoTK Woo Product Migrator
 * Plugin URI: https://www.maotk.com
 * Description: Queue-based WooCommerce product export and import tool with one-product-at-a-time processing, local uploads support, retryable failures, and safe image renaming.
 * Version: 0.1.0
 * Author: Mao TK 出海猫
 * Author URI: https://www.maotk.com
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * Text Domain: maotk-woo-product-migrator
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MaoTK_Woo_Product_Migrator {
    const VERSION = '0.1.0';
    const SCHEMA = 'maotk-wpm-product-v1';
    const CRON_HOOK = 'maotk_wpm_daily_cleanup';
    const NONCE_ACTION = 'maotk_wpm_admin';

    private static $instance = null;
    private $jobs_table;
    private $items_table;
    private $logs_table;
    private $media_table;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        global $wpdb;

        $this->jobs_table = $wpdb->prefix . 'maotk_wpm_jobs';
        $this->items_table = $wpdb->prefix . 'maotk_wpm_items';
        $this->logs_table = $wpdb->prefix . 'maotk_wpm_logs';
        $this->media_table = $wpdb->prefix . 'maotk_wpm_media_map';

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_maotk_wpm_create_export_job', array($this, 'ajax_create_export_job'));
        add_action('wp_ajax_maotk_wpm_create_import_job_path', array($this, 'ajax_create_import_job_path'));
        add_action('wp_ajax_maotk_wpm_process_job', array($this, 'ajax_process_job'));
        add_action('wp_ajax_maotk_wpm_retry_failed', array($this, 'ajax_retry_failed'));
        add_action('wp_ajax_maotk_wpm_cleanup_job', array($this, 'ajax_cleanup_job'));
        add_action('admin_post_maotk_wpm_upload_package', array($this, 'handle_upload_package'));
        add_action('admin_post_maotk_wpm_download_package', array($this, 'download_package'));
        add_action('admin_post_maotk_wpm_download_failures', array($this, 'download_failures'));
        add_action(self::CRON_HOOK, array($this, 'cleanup_expired_data'));
    }

    public static function activate() {
        self::instance()->create_tables();
        self::instance()->ensure_storage();

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    private function capability() {
        return current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
    }

    public function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql_jobs = "CREATE TABLE {$this->jobs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_type varchar(20) NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'queued',
            title varchar(191) NOT NULL DEFAULT '',
            options longtext NULL,
            stats longtext NULL,
            package_path text NULL,
            work_dir text NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY job_type (job_type),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_items = "CREATE TABLE {$this->items_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            old_product_id bigint(20) unsigned NULL,
            new_product_id bigint(20) unsigned NULL,
            product_type varchar(40) NULL,
            title text NULL,
            sku varchar(191) NULL,
            old_url text NULL,
            payload_path text NULL,
            status varchar(30) NOT NULL DEFAULT 'queued',
            stage varchar(80) NULL,
            error_message text NULL,
            retry_count int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY old_product_id (old_product_id),
            KEY new_product_id (new_product_id),
            KEY status (status),
            KEY sku (sku)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$this->logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            item_id bigint(20) unsigned NULL,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY item_id (item_id),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_media = "CREATE TABLE {$this->media_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            source_key varchar(191) NOT NULL,
            source_url text NULL,
            relative_path text NULL,
            new_attachment_id bigint(20) unsigned NULL,
            new_file text NULL,
            status varchar(30) NOT NULL DEFAULT 'success',
            error_message text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_source (job_id, source_key),
            KEY new_attachment_id (new_attachment_id),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta($sql_jobs);
        dbDelta($sql_items);
        dbDelta($sql_logs);
        dbDelta($sql_media);
    }

    private function default_settings() {
        return array(
            'success_log_days' => 7,
            'failure_log_days' => 30,
            'package_days' => 7,
            'tmp_hours' => 24,
            'max_image_mb' => 25,
            'same_filename' => 'rename',
        );
    }

    private function get_settings() {
        $settings = get_option('maotk_wpm_settings', array());
        return wp_parse_args(is_array($settings) ? $settings : array(), $this->default_settings());
    }

    private function now() {
        return current_time('mysql');
    }

    private function wc_active() {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    private function ensure_storage() {
        $root = $this->storage_root();
        wp_mkdir_p($root);
        wp_mkdir_p($root . '/exports');
        wp_mkdir_p($root . '/imports');
        wp_mkdir_p($root . '/tmp');

        $deny = $root . '/.htaccess';
        if (!file_exists($deny)) {
            file_put_contents($deny, "Deny from all\n");
        }

        $index = $root . '/index.html';
        if (!file_exists($index)) {
            file_put_contents($index, '');
        }

        return $root;
    }

    private function storage_root() {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'maotk-woo-product-migrator';
    }

    public function admin_menu() {
        add_menu_page(
            'MaoTK Woo Product Migrator',
            'MaoTK 产品迁移',
            $this->capability(),
            'maotk-woo-product-migrator',
            array($this, 'render_admin_page'),
            'dashicons-migrate',
            56
        );
    }

    public function enqueue_admin_assets($hook) {
        if ('toplevel_page_maotk-woo-product-migrator' !== $hook) {
            return;
        }

        $base_url = plugin_dir_url(__FILE__);

        wp_enqueue_style(
            'maotk-wpm-admin',
            $base_url . 'assets/admin.css',
            array(),
            self::VERSION
        );

        wp_enqueue_script(
            'maotk-wpm-admin',
            $base_url . 'assets/admin.js',
            array('jquery'),
            self::VERSION,
            true
        );

        wp_localize_script('maotk-wpm-admin', 'MaoTKWPM', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'pageUrl' => admin_url('admin.php?page=maotk-woo-product-migrator'),
        ));
    }

    public function render_admin_page() {
        if (!current_user_can($this->capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'maotk-woo-product-migrator'));
        }

        $this->ensure_storage();
        $jobs = $this->get_recent_jobs();
        $settings = $this->get_settings();
        $message = isset($_GET['maotk_wpm_message']) ? sanitize_text_field(wp_unslash($_GET['maotk_wpm_message'])) : '';
        $job_id = isset($_GET['maotk_wpm_job_id']) ? absint($_GET['maotk_wpm_job_id']) : 0;
        ?>
        <div class="wrap maotk-wpm">
            <h1>MaoTK Woo Product Migrator</h1>
            <p class="maotk-wpm-subtitle">WooCommerce 产品安全迁移工具：一次只处理一个产品，失败跳过，支持本地 uploads 图片、同名随机重命名和失败项重试。</p>

            <?php if (!$this->wc_active()) : ?>
                <div class="notice notice-error"><p>未检测到 WooCommerce。插件可以打开后台，但导出/导入需要先启用 WooCommerce。</p></div>
            <?php endif; ?>

            <?php if ($message) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($message); ?><?php echo $job_id ? ' #' . esc_html($job_id) : ''; ?></p>
                </div>
            <?php endif; ?>

            <div class="maotk-wpm-grid">
                <section class="maotk-wpm-card">
                    <h2>导出产品</h2>
                    <p>选择数量只是任务总量；实际执行永远是一个产品完成后再处理下一个。</p>
                    <form id="maotk-wpm-export-form">
                        <div class="maotk-wpm-presets" data-target="#maotk-wpm-export-limit">
                            <?php foreach (array(50, 100, 250, 300, 350, 400, 450, 500, 550, 600, 650, 700, 750, 800, 850, 900, 950, 1000) as $preset) : ?>
                                <button type="button" class="button maotk-wpm-preset" data-value="<?php echo esc_attr($preset); ?>"><?php echo esc_html($preset); ?></button>
                            <?php endforeach; ?>
                            <button type="button" class="button maotk-wpm-preset" data-value="0">全部导出</button>
                        </div>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="maotk-wpm-export-limit">自定义数量</label></th>
                                <td><input type="number" min="0" id="maotk-wpm-export-limit" name="limit" value="50" class="small-text"> <span class="description">0 表示全部。</span></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="maotk-wpm-export-offset">起始偏移</label></th>
                                <td><input type="number" min="0" id="maotk-wpm-export-offset" name="offset" value="0" class="small-text"> <span class="description">大站分段导出时使用，例如先导 0-499，再从 500 开始。</span></td>
                            </tr>
                            <tr>
                                <th scope="row">自定义字段</th>
                                <td>
                                    <select name="meta_mode">
                                        <option value="safe">安全导出常见自定义字段</option>
                                        <option value="none">不导出自定义字段</option>
                                        <option value="all">尽量导出全部自定义字段</option>
                                        <option value="whitelist">只导出指定字段</option>
                                    </select>
                                    <input type="text" name="meta_keys" class="regular-text" placeholder="_yoast_wpseo_title,rank_math_title,my_field">
                                </td>
                            </tr>
                        </table>
                        <p><button type="submit" class="button button-primary">创建导出任务</button></p>
                    </form>
                </section>

                <section class="maotk-wpm-card">
                    <h2>导入产品</h2>
                    <p>导入包可以上传，也可以填写服务器上的导出包路径。图片优先从旧站 uploads 本地目录读取。</p>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field(self::NONCE_ACTION, 'maotk_wpm_nonce'); ?>
                        <input type="hidden" name="action" value="maotk_wpm_upload_package">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="maotk-wpm-package-upload">上传导出包</label></th>
                                <td><input type="file" id="maotk-wpm-package-upload" name="package" accept=".zip,.jsonl,.json"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="maotk-wpm-source-uploads-upload">旧站 uploads 目录</label></th>
                                <td><input type="text" id="maotk-wpm-source-uploads-upload" name="source_uploads_path" class="large-text" placeholder="/www/wwwroot/old-site/wp-content/uploads"></td>
                            </tr>
                            <tr>
                                <th scope="row">重复产品</th>
                                <td><?php $this->render_duplicate_controls(); ?></td>
                            </tr>
                        </table>
                        <p><button type="submit" class="button button-primary">上传并创建导入任务</button></p>
                    </form>

                    <hr>

                    <form id="maotk-wpm-import-path-form">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="maotk-wpm-package-path">服务器导出包路径</label></th>
                                <td><input type="text" id="maotk-wpm-package-path" name="package_path" class="large-text" placeholder="/www/wwwroot/migration/package.zip"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="maotk-wpm-source-uploads-path">旧站 uploads 目录</label></th>
                                <td><input type="text" id="maotk-wpm-source-uploads-path" name="source_uploads_path" class="large-text" placeholder="/www/wwwroot/old-site/wp-content/uploads"></td>
                            </tr>
                            <tr>
                                <th scope="row">重复产品</th>
                                <td><?php $this->render_duplicate_controls('path_'); ?></td>
                            </tr>
                        </table>
                        <p><button type="submit" class="button button-primary">用服务器路径创建导入任务</button></p>
                    </form>
                </section>
            </div>

            <section class="maotk-wpm-card maotk-wpm-wide">
                <h2>当前执行</h2>
                <div id="maotk-wpm-runner" class="maotk-wpm-runner">
                    <div class="maotk-wpm-progress"><span style="width:0%"></span></div>
                    <p class="maotk-wpm-runner-text">尚未运行任务。</p>
                </div>
            </section>

            <section class="maotk-wpm-card maotk-wpm-wide">
                <h2>历史任务</h2>
                <?php $this->render_jobs_table($jobs); ?>
            </section>

            <section class="maotk-wpm-card maotk-wpm-wide">
                <h2>清理策略</h2>
                <p>默认会删除临时分片、过期导出包和旧日志。失败项在重试成功后会自动从失败队列里消失。</p>
                <ul class="maotk-wpm-cleanup-list">
                    <li>成功日志保留：<?php echo esc_html((int) $settings['success_log_days']); ?> 天</li>
                    <li>失败日志保留：<?php echo esc_html((int) $settings['failure_log_days']); ?> 天</li>
                    <li>导出包保留：<?php echo esc_html((int) $settings['package_days']); ?> 天</li>
                    <li>中断临时文件保留：<?php echo esc_html((int) $settings['tmp_hours']); ?> 小时</li>
                </ul>
            </section>
        </div>
        <?php
    }

    private function render_duplicate_controls($prefix = '') {
        ?>
        <select name="<?php echo esc_attr($prefix); ?>match_by">
            <option value="sku">按 SKU 匹配</option>
            <option value="slug">按 slug 匹配</option>
            <option value="title">按标题匹配</option>
        </select>
        <select name="<?php echo esc_attr($prefix); ?>duplicate_strategy">
            <option value="skip">已有产品则跳过</option>
            <option value="price_stock">已有产品只更新价格和库存</option>
            <option value="update_all">已有产品更新全部字段</option>
            <option value="create_new">已有产品也创建新产品</option>
        </select>
        <select name="<?php echo esc_attr($prefix); ?>same_filename">
            <option value="rename">同名图片自动加 mig-随机数字</option>
            <option value="reuse">尝试复用已有同名图片</option>
            <option value="fail">同名图片直接标记失败</option>
        </select>
        <?php
    }

    private function render_jobs_table($jobs) {
        if (!$jobs) {
            echo '<p>暂无迁移任务。</p>';
            return;
        }

        ?>
        <table class="widefat striped maotk-wpm-jobs">
            <thead>
            <tr>
                <th>ID</th>
                <th>类型</th>
                <th>状态</th>
                <th>进度</th>
                <th>成功/失败/跳过</th>
                <th>创建时间</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job) : $stats = $this->decode_json($job->stats); ?>
                <tr data-job-id="<?php echo esc_attr($job->id); ?>">
                    <td>#<?php echo esc_html($job->id); ?></td>
                    <td><?php echo esc_html('export' === $job->job_type ? '导出' : '导入'); ?></td>
                    <td><?php echo esc_html($job->status); ?></td>
                    <td><?php echo esc_html($this->progress_text($stats)); ?></td>
                    <td><?php echo esc_html((int) ($stats['success'] ?? 0)); ?> / <?php echo esc_html((int) ($stats['failed'] ?? 0)); ?> / <?php echo esc_html((int) ($stats['skipped'] ?? 0)); ?></td>
                    <td><?php echo esc_html($job->created_at); ?></td>
                    <td class="maotk-wpm-actions">
                        <?php if (in_array($job->status, array('queued', 'running', 'paused', 'complete_with_errors'), true)) : ?>
                            <button type="button" class="button maotk-wpm-run-job" data-job-id="<?php echo esc_attr($job->id); ?>">继续/运行</button>
                        <?php endif; ?>
                        <?php if ((int) ($stats['failed'] ?? 0) > 0) : ?>
                            <button type="button" class="button maotk-wpm-retry-job" data-job-id="<?php echo esc_attr($job->id); ?>">只重试失败项</button>
                            <a class="button" href="<?php echo esc_url($this->admin_download_url('maotk_wpm_download_failures', $job->id)); ?>">下载失败日志</a>
                        <?php endif; ?>
                        <?php if (!empty($job->package_path)) : ?>
                            <a class="button" href="<?php echo esc_url($this->admin_download_url('maotk_wpm_download_package', $job->id)); ?>">下载导出包</a>
                        <?php endif; ?>
                        <button type="button" class="button maotk-wpm-clean-job" data-job-id="<?php echo esc_attr($job->id); ?>">清理临时文件</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function admin_download_url($action, $job_id) {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . rawurlencode($action) . '&job_id=' . absint($job_id)),
            self::NONCE_ACTION,
            'maotk_wpm_nonce'
        );
    }

    private function progress_text($stats) {
        $total = (int) ($stats['total'] ?? 0);
        $done = (int) ($stats['success'] ?? 0) + (int) ($stats['failed'] ?? 0) + (int) ($stats['skipped'] ?? 0);

        if (!$total) {
            return '0 / 0';
        }

        return $done . ' / ' . $total . ' (' . round(($done / $total) * 100) . '%)';
    }

    private function get_recent_jobs() {
        global $wpdb;

        return $wpdb->get_results("SELECT * FROM {$this->jobs_table} ORDER BY id DESC LIMIT 30");
    }

    private function verify_ajax() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can($this->capability())) {
            wp_send_json_error(array('message' => '权限不足。'), 403);
        }

        if (!$this->wc_active()) {
            wp_send_json_error(array('message' => 'WooCommerce 未启用。'), 400);
        }
    }

    public function ajax_create_export_job() {
        $this->verify_ajax();

        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $meta_mode = isset($_POST['meta_mode']) ? sanitize_key(wp_unslash($_POST['meta_mode'])) : 'safe';
        $meta_keys = isset($_POST['meta_keys']) ? sanitize_text_field(wp_unslash($_POST['meta_keys'])) : '';

        $ids = get_posts(array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'posts_per_page' => $limit ? $limit : -1,
            'offset' => $offset,
            'no_found_rows' => true,
        ));

        if (!$ids) {
            wp_send_json_error(array('message' => '没有找到可导出的产品。'), 400);
        }

        $options = array(
            'limit' => $limit,
            'offset' => $offset,
            'meta_mode' => in_array($meta_mode, array('safe', 'none', 'all', 'whitelist'), true) ? $meta_mode : 'safe',
            'meta_keys' => $this->split_keys($meta_keys),
            'source_site' => home_url('/'),
        );

        $job_id = $this->insert_job('export', 'Export products', $options, count($ids));
        $work_dir = $this->prepare_job_dir($job_id, 'exports');
        $this->update_job($job_id, array('work_dir' => $work_dir));

        foreach ($ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $this->insert_item($job_id, array(
                'old_product_id' => $product_id,
                'product_type' => $product->get_type(),
                'title' => $product->get_name(),
                'sku' => $product->get_sku(),
                'old_url' => get_permalink($product_id),
            ));
        }

        $this->log($job_id, null, 'info', '导出任务已创建。', array('total' => count($ids)));
        wp_send_json_success($this->job_response($job_id, '导出任务已创建。'));
    }

    public function ajax_create_import_job_path() {
        $this->verify_ajax();

        $package_path = isset($_POST['package_path']) ? sanitize_text_field(wp_unslash($_POST['package_path'])) : '';
        if (!$package_path) {
            wp_send_json_error(array('message' => '请填写服务器导出包路径。'), 400);
        }

        $options = $this->import_options_from_request($_POST, 'path_');
        $result = $this->create_import_job_from_path($package_path, $options);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }

        wp_send_json_success($this->job_response($result, '导入任务已创建。'));
    }

    public function handle_upload_package() {
        if (!current_user_can($this->capability())) {
            wp_die('权限不足。');
        }

        check_admin_referer(self::NONCE_ACTION, 'maotk_wpm_nonce');

        if (!$this->wc_active()) {
            wp_die('WooCommerce 未启用。');
        }

        if (empty($_FILES['package']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('maotk_wpm_message', rawurlencode('没有选择导入包。'), admin_url('admin.php?page=maotk-woo-product-migrator')));
            exit;
        }

        $this->ensure_storage();
        $filename = sanitize_file_name($_FILES['package']['name']);
        $target = trailingslashit($this->storage_root()) . 'imports/upload-' . time() . '-' . wp_rand(100000, 999999) . '-' . $filename;

        if (!move_uploaded_file($_FILES['package']['tmp_name'], $target)) {
            wp_die('导入包上传失败。');
        }

        $options = $this->import_options_from_request($_POST, '');
        $job_id = $this->create_import_job_from_path($target, $options);

        if (is_wp_error($job_id)) {
            wp_die(esc_html($job_id->get_error_message()));
        }

        wp_safe_redirect(add_query_arg(array(
            'maotk_wpm_message' => rawurlencode('导入任务已创建'),
            'maotk_wpm_job_id' => $job_id,
        ), admin_url('admin.php?page=maotk-woo-product-migrator')));
        exit;
    }

    private function import_options_from_request($request, $prefix) {
        $settings = $this->get_settings();

        return array(
            'source_uploads_path' => isset($request['source_uploads_path']) ? sanitize_text_field(wp_unslash($request['source_uploads_path'])) : '',
            'match_by' => isset($request[$prefix . 'match_by']) ? sanitize_key(wp_unslash($request[$prefix . 'match_by'])) : 'sku',
            'duplicate_strategy' => isset($request[$prefix . 'duplicate_strategy']) ? sanitize_key(wp_unslash($request[$prefix . 'duplicate_strategy'])) : 'skip',
            'same_filename' => isset($request[$prefix . 'same_filename']) ? sanitize_key(wp_unslash($request[$prefix . 'same_filename'])) : $settings['same_filename'],
            'allow_url_fallback' => false,
            'max_image_mb' => (int) $settings['max_image_mb'],
        );
    }

    private function create_import_job_from_path($package_path, $options) {
        $package_path = wp_normalize_path($package_path);

        if (!file_exists($package_path)) {
            return new WP_Error('maotk_wpm_missing_package', '导入包路径不存在：' . $package_path);
        }

        $job_id = $this->insert_job('import', 'Import products', $options, 0);
        $work_dir = $this->prepare_job_dir($job_id, 'imports');
        $this->update_job($job_id, array('work_dir' => $work_dir));

        $source_dir = $this->prepare_import_package($package_path, $work_dir);
        if (is_wp_error($source_dir)) {
            $this->mark_job_failed($job_id, $source_dir->get_error_message());
            return $source_dir;
        }

        $products_file = trailingslashit($source_dir) . 'products.jsonl';
        if (!file_exists($products_file)) {
            $this->mark_job_failed($job_id, '导入包缺少 products.jsonl。');
            return new WP_Error('maotk_wpm_bad_package', '导入包缺少 products.jsonl。');
        }

        $count = $this->split_import_products($job_id, $products_file, $work_dir);
        if (is_wp_error($count)) {
            $this->mark_job_failed($job_id, $count->get_error_message());
            return $count;
        }

        $options['package_dir'] = $source_dir;
        $this->update_job($job_id, array(
            'options' => wp_json_encode($options),
            'stats' => wp_json_encode($this->base_stats((int) $count)),
        ));
        $this->log($job_id, null, 'info', '导入任务已创建。', array('total' => (int) $count));

        return $job_id;
    }

    private function prepare_import_package($package_path, $work_dir) {
        if (is_dir($package_path)) {
            return trailingslashit($package_path);
        }

        $ext = strtolower(pathinfo($package_path, PATHINFO_EXTENSION));

        if ('jsonl' === $ext) {
            copy($package_path, trailingslashit($work_dir) . 'products.jsonl');
            return trailingslashit($work_dir);
        }

        if ('zip' !== $ext) {
            return new WP_Error('maotk_wpm_bad_package_type', '目前支持 .zip 或 .jsonl 导入包。');
        }

        $extract_dir = trailingslashit($work_dir) . 'package';
        wp_mkdir_p($extract_dir);

        $unzipped = $this->unzip_package($package_path, $extract_dir);
        if (is_wp_error($unzipped)) {
            return $unzipped;
        }

        return trailingslashit($extract_dir);
    }

    private function unzip_package($zip_path, $target_dir) {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if (true !== $zip->open($zip_path)) {
                return new WP_Error('maotk_wpm_zip_open', '无法打开 zip 导入包。');
            }
            $zip->extractTo($target_dir);
            $zip->close();
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        $archive = new PclZip($zip_path);
        $result = $archive->extract(PCLZIP_OPT_PATH, $target_dir);

        if (0 === $result) {
            return new WP_Error('maotk_wpm_zip_extract', '无法解压导入包：' . $archive->errorInfo(true));
        }

        return true;
    }

    private function split_import_products($job_id, $products_file, $work_dir) {
        $handle = fopen($products_file, 'r');
        if (!$handle) {
            return new WP_Error('maotk_wpm_read_package', '无法读取 products.jsonl。');
        }

        $payload_dir = trailingslashit($work_dir) . 'payloads';
        wp_mkdir_p($payload_dir);

        $count = 0;
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $payload = json_decode($line, true);
            if (!is_array($payload)) {
                continue;
            }

            $old_id = (int) ($payload['old_id'] ?? 0);
            $safe_id = $old_id ? $old_id : ($count + 1);
            $payload_path = trailingslashit($payload_dir) . 'product-' . $safe_id . '-' . wp_rand(100000, 999999) . '.json';
            file_put_contents($payload_path, wp_json_encode($payload));

            $this->insert_item($job_id, array(
                'old_product_id' => $old_id,
                'product_type' => sanitize_text_field($payload['type'] ?? ''),
                'title' => sanitize_text_field($payload['name'] ?? ''),
                'sku' => sanitize_text_field($payload['sku'] ?? ''),
                'old_url' => esc_url_raw($payload['old_url'] ?? ''),
                'payload_path' => $payload_path,
            ));

            $count++;
        }

        fclose($handle);

        return $count;
    }

    public function ajax_process_job() {
        $this->verify_ajax();

        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) {
            wp_send_json_error(array('message' => '缺少任务 ID。'), 400);
        }

        $lock_key = 'maotk_wpm_job_lock_' . $job_id;
        if (get_transient($lock_key)) {
            wp_send_json_success($this->job_response($job_id, '任务正在执行上一件产品，请稍后。'));
        }

        set_transient($lock_key, time(), 60);

        try {
            $result = $this->process_one_item($job_id);
            delete_transient($lock_key);
            wp_send_json_success($result);
        } catch (Throwable $e) {
            delete_transient($lock_key);
            $this->log($job_id, null, 'error', $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()), 500);
        }
    }

    private function process_one_item($job_id) {
        global $wpdb;

        $job = $this->get_job($job_id);
        if (!$job) {
            return array('message' => '任务不存在。', 'done' => true);
        }

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->items_table} WHERE job_id = %d AND status IN ('queued','retrying') ORDER BY id ASC LIMIT 1",
            $job_id
        ));

        if (!$item) {
            $this->finalize_job($job);
            return $this->job_response($job_id, '任务已完成。', true);
        }

        $this->update_job($job_id, array('status' => 'running'));
        $this->update_item($item->id, array(
            'status' => 'running',
            'stage' => 'starting',
            'error_message' => null,
        ));

        if ('export' === $job->job_type) {
            $this->process_export_item($job, $item);
        } else {
            $this->process_import_item($job, $item);
        }

        return $this->job_response($job_id, '已处理一个产品。');
    }

    private function process_export_item($job, $item) {
        $product = wc_get_product((int) $item->old_product_id);

        if (!$product) {
            $this->fail_item($job->id, $item, 'export_lookup', '产品不存在或无法读取。');
            return;
        }

        try {
            $this->update_item($item->id, array('stage' => 'serializing'));
            $options = $this->decode_json($job->options);
            $payload = $this->serialize_product($product, $options);
            $line = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!$line) {
                throw new RuntimeException('产品 JSON 编码失败。');
            }

            $products_file = trailingslashit($job->work_dir) . 'products.jsonl';
            file_put_contents($products_file, $line . "\n", FILE_APPEND | LOCK_EX);

            $this->update_item($item->id, array(
                'status' => 'success',
                'stage' => 'done',
                'error_message' => null,
            ));
            $this->increment_stat($job->id, 'success');
        } catch (Throwable $e) {
            $this->fail_item($job->id, $item, 'export', $e->getMessage());
        }
    }

    private function process_import_item($job, $item) {
        if (!$item->payload_path || !file_exists($item->payload_path)) {
            $this->fail_item($job->id, $item, 'payload', '产品数据文件不存在。');
            return;
        }

        $payload = json_decode(file_get_contents($item->payload_path), true);
        if (!is_array($payload)) {
            $this->fail_item($job->id, $item, 'payload', '产品数据 JSON 无法解析。');
            return;
        }

        try {
            $this->update_item($item->id, array('stage' => 'importing'));
            $new_id = $this->import_product_payload($payload, $job, $item);

            if ('skipped' === $new_id) {
                $this->update_item($item->id, array('status' => 'skipped', 'stage' => 'duplicate'));
                $this->increment_stat($job->id, 'skipped');
                return;
            }

            $this->update_item($item->id, array(
                'status' => 'success',
                'stage' => 'done',
                'new_product_id' => (int) $new_id,
                'error_message' => null,
            ));
            $this->increment_stat($job->id, 'success');
        } catch (Throwable $e) {
            $this->fail_item($job->id, $item, 'import', $e->getMessage());
        }
    }

    private function import_product_payload($payload, $job, $item) {
        $options = $this->decode_json($job->options);
        $match_by = $options['match_by'] ?? 'sku';
        $strategy = $options['duplicate_strategy'] ?? 'skip';
        $existing_id = $this->find_existing_product($payload, $match_by);

        if ($existing_id && 'skip' === $strategy) {
            return 'skipped';
        }

        if ($existing_id && 'price_stock' === $strategy) {
            $product = wc_get_product($existing_id);
            if (!$product) {
                throw new RuntimeException('匹配到已有产品但无法读取。');
            }
            $this->apply_price_stock($product, $payload);
            $product->save();
            return $existing_id;
        }

        if ($existing_id && 'update_all' === $strategy) {
            $product = wc_get_product($existing_id);
        } else {
            $product = $this->new_product_by_type($payload['type'] ?? 'simple');
        }

        if (!$product) {
            throw new RuntimeException('无法创建产品对象。');
        }

        $this->apply_product_fields($product, $payload, !$existing_id || 'create_new' === $strategy);
        $this->apply_product_terms($product, $payload);
        $this->apply_product_attributes($product, $payload);
        $product_id = $product->save();

        $this->apply_product_images($product, $payload, $job, $product_id);
        $this->apply_custom_meta($product_id, $payload['custom_meta'] ?? array());
        update_post_meta($product_id, '_maotk_wpm_old_product_id', (int) ($payload['old_id'] ?? 0));
        update_post_meta($product_id, '_maotk_wpm_source_url', esc_url_raw($payload['old_url'] ?? ''));

        if ('variable' === ($payload['type'] ?? '') && !empty($payload['variations'])) {
            $this->import_variations($product_id, $payload['variations'], $job);
        }

        return $product_id;
    }

    private function new_product_by_type($type) {
        switch ($type) {
            case 'variable':
                return class_exists('WC_Product_Variable') ? new WC_Product_Variable() : null;
            case 'external':
                return class_exists('WC_Product_External') ? new WC_Product_External() : null;
            case 'grouped':
                return class_exists('WC_Product_Grouped') ? new WC_Product_Grouped() : null;
            default:
                return class_exists('WC_Product_Simple') ? new WC_Product_Simple() : null;
        }
    }

    private function find_existing_product($payload, $match_by) {
        global $wpdb;

        if ('sku' === $match_by && !empty($payload['sku']) && function_exists('wc_get_product_id_by_sku')) {
            return (int) wc_get_product_id_by_sku($payload['sku']);
        }

        if ('slug' === $match_by && !empty($payload['slug'])) {
            $post = get_page_by_path(sanitize_title($payload['slug']), OBJECT, 'product');
            return $post ? (int) $post->ID : 0;
        }

        if ('title' === $match_by && !empty($payload['name'])) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title = %s ORDER BY ID ASC LIMIT 1",
                $payload['name']
            ));
        }

        return 0;
    }

    private function apply_product_fields($product, $payload, $set_slug) {
        $product->set_name(wp_strip_all_tags($payload['name'] ?? 'Untitled product'));

        if ($set_slug && !empty($payload['slug'])) {
            $product->set_slug(sanitize_title($payload['slug']));
        }

        if (!empty($payload['status'])) {
            $product->set_status(sanitize_key($payload['status']));
        }

        $product->set_description(wp_kses_post($payload['description'] ?? ''));
        $product->set_short_description(wp_kses_post($payload['short_description'] ?? ''));

        if (!empty($payload['sku'])) {
            try {
                $product->set_sku(wc_clean($payload['sku']));
            } catch (Exception $e) {
                $this->log(0, null, 'warning', 'SKU 设置失败：' . $e->getMessage());
            }
        }

        $this->apply_price_stock($product, $payload);

        if (method_exists($product, 'set_catalog_visibility') && !empty($payload['catalog_visibility'])) {
            $product->set_catalog_visibility($payload['catalog_visibility']);
        }

        if (method_exists($product, 'set_featured')) {
            $product->set_featured(!empty($payload['featured']));
        }

        if (method_exists($product, 'set_virtual')) {
            $product->set_virtual(!empty($payload['virtual']));
        }

        if (method_exists($product, 'set_sold_individually')) {
            $product->set_sold_individually(!empty($payload['sold_individually']));
        }

        if (method_exists($product, 'set_reviews_allowed')) {
            $product->set_reviews_allowed(!empty($payload['reviews_allowed']));
        }

        if (method_exists($product, 'set_purchase_note')) {
            $product->set_purchase_note(wp_kses_post($payload['purchase_note'] ?? ''));
        }

        if (method_exists($product, 'set_menu_order')) {
            $product->set_menu_order((int) ($payload['menu_order'] ?? 0));
        }

        $product->set_weight(wc_clean($payload['weight'] ?? ''));
        $product->set_length(wc_clean($payload['length'] ?? ''));
        $product->set_width(wc_clean($payload['width'] ?? ''));
        $product->set_height(wc_clean($payload['height'] ?? ''));

        if ($product instanceof WC_Product_External) {
            $product->set_product_url(esc_url_raw($payload['external_url'] ?? ''));
            $product->set_button_text(sanitize_text_field($payload['button_text'] ?? ''));
        }
    }

    private function apply_price_stock($product, $payload) {
        if (method_exists($product, 'set_regular_price')) {
            $product->set_regular_price(wc_format_decimal($payload['regular_price'] ?? ''));
        }
        if (method_exists($product, 'set_sale_price')) {
            $product->set_sale_price(wc_format_decimal($payload['sale_price'] ?? ''));
        }
        if (method_exists($product, 'set_manage_stock')) {
            $product->set_manage_stock(!empty($payload['manage_stock']));
        }
        if (method_exists($product, 'set_stock_quantity') && isset($payload['stock_quantity']) && '' !== $payload['stock_quantity']) {
            $product->set_stock_quantity((float) $payload['stock_quantity']);
        }
        if (method_exists($product, 'set_stock_status') && !empty($payload['stock_status'])) {
            $product->set_stock_status(sanitize_key($payload['stock_status']));
        }
        if (method_exists($product, 'set_backorders') && !empty($payload['backorders'])) {
            $product->set_backorders(sanitize_key($payload['backorders']));
        }
    }

    private function apply_product_terms($product, $payload) {
        $category_ids = array();
        foreach (($payload['categories'] ?? array()) as $term) {
            $id = $this->ensure_hierarchical_term($term, 'product_cat');
            if ($id) {
                $category_ids[] = $id;
            }
        }
        if ($category_ids && method_exists($product, 'set_category_ids')) {
            $product->set_category_ids(array_values(array_unique($category_ids)));
        }

        $tag_ids = array();
        foreach (($payload['tags'] ?? array()) as $term) {
            $id = $this->ensure_flat_term($term['name'] ?? '', 'product_tag', $term['slug'] ?? '');
            if ($id) {
                $tag_ids[] = $id;
            }
        }
        if ($tag_ids && method_exists($product, 'set_tag_ids')) {
            $product->set_tag_ids(array_values(array_unique($tag_ids)));
        }
    }

    private function apply_product_attributes($product, $payload) {
        if (empty($payload['attributes']) || !class_exists('WC_Product_Attribute')) {
            return;
        }

        $attributes = array();
        foreach ($payload['attributes'] as $attr) {
            $attribute = new WC_Product_Attribute();
            $name = sanitize_text_field($attr['name'] ?? '');
            if (!$name) {
                continue;
            }

            if (!empty($attr['is_taxonomy'])) {
                $taxonomy = $this->ensure_attribute_taxonomy($name, $attr['label'] ?? $name);
                if (!$taxonomy) {
                    continue;
                }

                $term_ids = array();
                foreach (($attr['terms'] ?? array()) as $term) {
                    $term_id = $this->ensure_flat_term($term['name'] ?? '', $taxonomy, $term['slug'] ?? '');
                    if ($term_id) {
                        $term_ids[] = $term_id;
                    }
                }

                $attribute->set_id((int) wc_attribute_taxonomy_id_by_name($taxonomy));
                $attribute->set_name($taxonomy);
                $attribute->set_options($term_ids);
            } else {
                $attribute->set_id(0);
                $attribute->set_name(sanitize_text_field($attr['label'] ?? $name));
                $attribute->set_options(array_map('sanitize_text_field', (array) ($attr['options'] ?? array())));
            }

            $attribute->set_position((int) ($attr['position'] ?? 0));
            $attribute->set_visible(!empty($attr['visible']));
            $attribute->set_variation(!empty($attr['variation']));
            $attributes[] = $attribute;
        }

        $product->set_attributes($attributes);

        if (!empty($payload['default_attributes']) && method_exists($product, 'set_default_attributes')) {
            $product->set_default_attributes((array) $payload['default_attributes']);
        }
    }

    private function ensure_attribute_taxonomy($name, $label) {
        $taxonomy = 0 === strpos($name, 'pa_') ? sanitize_title($name) : wc_attribute_taxonomy_name($name);
        $slug = 0 === strpos($taxonomy, 'pa_') ? substr($taxonomy, 3) : $taxonomy;

        if (!taxonomy_exists($taxonomy) && function_exists('wc_create_attribute')) {
            $attribute_id = wc_attribute_taxonomy_id_by_name($taxonomy);
            if (!$attribute_id) {
                wc_create_attribute(array(
                    'name' => sanitize_text_field($label ?: $slug),
                    'slug' => sanitize_title($slug),
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false,
                ));
                delete_transient('wc_attribute_taxonomies');
            }
        }

        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, array('product'), array(
                'hierarchical' => false,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            ));
        }

        return taxonomy_exists($taxonomy) ? $taxonomy : '';
    }

    private function ensure_hierarchical_term($payload, $taxonomy) {
        if (empty($payload['name'])) {
            return 0;
        }

        $parent = 0;
        foreach ((array) ($payload['ancestors'] ?? array()) as $ancestor) {
            $parent = $this->ensure_single_term($ancestor['name'] ?? '', $taxonomy, $ancestor['slug'] ?? '', $parent);
        }

        return $this->ensure_single_term($payload['name'], $taxonomy, $payload['slug'] ?? '', $parent);
    }

    private function ensure_flat_term($name, $taxonomy, $slug = '') {
        return $this->ensure_single_term($name, $taxonomy, $slug, 0);
    }

    private function ensure_single_term($name, $taxonomy, $slug = '', $parent = 0) {
        $name = sanitize_text_field($name);
        if (!$name || !taxonomy_exists($taxonomy)) {
            return 0;
        }

        $existing = $slug ? get_term_by('slug', sanitize_title($slug), $taxonomy) : get_term_by('name', $name, $taxonomy);
        if ($existing && !is_wp_error($existing)) {
            return (int) $existing->term_id;
        }

        $result = wp_insert_term($name, $taxonomy, array(
            'slug' => $slug ? sanitize_title($slug) : '',
            'parent' => (int) $parent,
        ));

        if (is_wp_error($result)) {
            $existing = get_term_by('name', $name, $taxonomy);
            return $existing ? (int) $existing->term_id : 0;
        }

        return (int) $result['term_id'];
    }

    private function apply_product_images($product, $payload, $job, $product_id) {
        if (!empty($payload['featured_image'])) {
            $image_id = $this->import_image($payload['featured_image'], $product_id, $job);
            if ($image_id && !is_wp_error($image_id)) {
                $product->set_image_id($image_id);
            }
        }

        $gallery_ids = array();
        foreach (($payload['gallery_images'] ?? array()) as $image) {
            $image_id = $this->import_image($image, $product_id, $job);
            if ($image_id && !is_wp_error($image_id)) {
                $gallery_ids[] = $image_id;
            }
        }

        if ($gallery_ids) {
            $product->set_gallery_image_ids(array_values(array_unique($gallery_ids)));
        }

        $product->save();
    }

    private function import_image($image, $post_id, $job) {
        if (empty($image) || !is_array($image)) {
            return 0;
        }

        $source_key = $this->image_source_key($image);
        if (!$source_key) {
            return 0;
        }

        $mapped = $this->get_mapped_attachment((int) $job->id, $source_key);
        if ($mapped) {
            return $mapped;
        }

        $options = $this->decode_json($job->options);
        $resolved = $this->resolve_source_image($image, $options);
        if (is_wp_error($resolved)) {
            $this->insert_media_map($job->id, $source_key, $image, 0, '', 'failed', $resolved->get_error_message());
            return $resolved;
        }

        $max_bytes = max(1, (int) ($options['max_image_mb'] ?? 25)) * MB_IN_BYTES;
        if (filesize($resolved) > $max_bytes) {
            @unlink($resolved);
            $error = new WP_Error('maotk_wpm_image_too_large', '图片超过大小限制：' . basename($resolved));
            $this->insert_media_map($job->id, $source_key, $image, 0, '', 'failed', $error->get_error_message());
            return $error;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $filename = $this->target_image_filename($image, $options);
        if (is_wp_error($filename)) {
            if ('maotk_wpm_reuse_signal' === $filename->get_error_code()) {
                $reuse_id = absint(str_replace('reuse:', '', $filename->get_error_message()));
                @unlink($resolved);
                if ($reuse_id) {
                    $this->insert_media_map($job->id, $source_key, $image, $reuse_id, get_post_meta($reuse_id, '_wp_attached_file', true), 'success', '');
                    return $reuse_id;
                }
            }
            @unlink($resolved);
            $this->insert_media_map($job->id, $source_key, $image, 0, '', 'failed', $filename->get_error_message());
            return $filename;
        }

        $file_array = array(
            'name' => $filename,
            'tmp_name' => $resolved,
        );

        $attachment_id = media_handle_sideload($file_array, $post_id, sanitize_text_field($image['title'] ?? ''));
        if (is_wp_error($attachment_id)) {
            @unlink($resolved);
            $this->insert_media_map($job->id, $source_key, $image, 0, '', 'failed', $attachment_id->get_error_message());
            return $attachment_id;
        }

        if (!empty($image['alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($image['alt']));
        }

        wp_update_post(array(
            'ID' => $attachment_id,
            'post_title' => sanitize_text_field($image['title'] ?? pathinfo($filename, PATHINFO_FILENAME)),
            'post_excerpt' => sanitize_text_field($image['caption'] ?? ''),
            'post_content' => wp_kses_post($image['description'] ?? ''),
        ));

        update_post_meta($attachment_id, '_maotk_wpm_source_key', $source_key);
        update_post_meta($attachment_id, '_maotk_wpm_source_relative_path', sanitize_text_field($image['relative_path'] ?? ''));
        update_post_meta($attachment_id, '_maotk_wpm_source_url', esc_url_raw($image['url'] ?? ''));

        $new_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $this->insert_media_map($job->id, $source_key, $image, $attachment_id, $new_file, 'success', '');

        return (int) $attachment_id;
    }

    private function resolve_source_image($image, $options) {
        $relative = isset($image['relative_path']) ? ltrim(wp_normalize_path($image['relative_path']), '/') : '';
        $source_uploads = isset($options['source_uploads_path']) ? rtrim(wp_normalize_path($options['source_uploads_path']), '/') : '';
        $package_dir = isset($options['package_dir']) ? rtrim(wp_normalize_path($options['package_dir']), '/') : '';

        $candidates = array();
        if ($source_uploads && $relative) {
            $candidates[] = $source_uploads . '/' . $relative;
        }
        if ($package_dir && $relative) {
            $candidates[] = $package_dir . '/images/' . $relative;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $tmp = wp_tempnam(basename($candidate));
                if (!$tmp || !copy($candidate, $tmp)) {
                    return new WP_Error('maotk_wpm_image_copy', '无法复制本地图片：' . $candidate);
                }
                return $tmp;
            }
        }

        if (!empty($options['allow_url_fallback']) && !empty($image['url'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $tmp = download_url(esc_url_raw($image['url']), 60);
            if (is_wp_error($tmp)) {
                return $tmp;
            }
            return $tmp;
        }

        return new WP_Error('maotk_wpm_image_missing', '图片未找到：' . ($relative ?: ($image['url'] ?? 'unknown')));
    }

    private function target_image_filename($image, $options) {
        $relative = $image['relative_path'] ?? '';
        $url = $image['url'] ?? '';
        $filename = $relative ? basename($relative) : basename(parse_url($url, PHP_URL_PATH));
        $filename = sanitize_file_name($filename ?: ('maotk-image-' . wp_rand(100000, 999999) . '.jpg'));
        $strategy = $options['same_filename'] ?? 'rename';

        $existing_id = $this->find_attachment_by_filename($filename);

        if ($existing_id && 'reuse' === $strategy) {
            return new WP_Error('maotk_wpm_reuse_signal', 'reuse:' . $existing_id);
        }

        if ($existing_id && 'fail' === $strategy) {
            return new WP_Error('maotk_wpm_same_filename', '新站已存在同名图片：' . $filename);
        }

        if ($existing_id && 'rename' === $strategy) {
            $info = pathinfo($filename);
            $base = $info['filename'] ?? 'image';
            $ext = isset($info['extension']) ? '.' . strtolower($info['extension']) : '';
            $filename = sanitize_file_name($base . '-mig-' . wp_rand(100000, 999999) . $ext);
        }

        return $filename;
    }

    private function find_attachment_by_filename($filename) {
        global $wpdb;

        $filename = sanitize_file_name($filename);
        if (!$filename) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id ASC LIMIT 1",
            '%' . $wpdb->esc_like('/' . $filename)
        ));
    }

    private function get_mapped_attachment($job_id, $source_key) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT new_attachment_id FROM {$this->media_table} WHERE job_id = %d AND source_key = %s AND status = 'success' LIMIT 1",
            $job_id,
            $source_key
        ));
    }

    private function insert_media_map($job_id, $source_key, $image, $attachment_id, $new_file, $status, $error) {
        global $wpdb;

        $wpdb->replace($this->media_table, array(
            'job_id' => (int) $job_id,
            'source_key' => (string) $source_key,
            'source_url' => esc_url_raw($image['url'] ?? ''),
            'relative_path' => sanitize_text_field($image['relative_path'] ?? ''),
            'new_attachment_id' => (int) $attachment_id,
            'new_file' => sanitize_text_field($new_file),
            'status' => sanitize_key($status),
            'error_message' => $error,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ));
    }

    private function image_source_key($image) {
        if (!empty($image['relative_path'])) {
            return sanitize_text_field(wp_normalize_path($image['relative_path']));
        }
        if (!empty($image['url'])) {
            return md5(esc_url_raw($image['url']));
        }
        if (!empty($image['old_attachment_id'])) {
            return 'attachment-' . absint($image['old_attachment_id']);
        }
        return '';
    }

    private function import_variations($parent_id, $variations, $job) {
        foreach ($variations as $variation_payload) {
            $variation_id = 0;
            if (!empty($variation_payload['sku']) && function_exists('wc_get_product_id_by_sku')) {
                $variation_id = (int) wc_get_product_id_by_sku($variation_payload['sku']);
            }

            $variation = $variation_id ? wc_get_product($variation_id) : new WC_Product_Variation();
            if (!$variation) {
                continue;
            }

            $variation->set_parent_id($parent_id);
            $variation->set_status(sanitize_key($variation_payload['status'] ?? 'publish'));
            if (!empty($variation_payload['sku'])) {
                try {
                    $variation->set_sku(wc_clean($variation_payload['sku']));
                } catch (Exception $e) {
                    // Duplicate variation SKUs are recorded on the parent import log, not fatal.
                }
            }

            $this->apply_price_stock($variation, $variation_payload);
            $variation->set_weight(wc_clean($variation_payload['weight'] ?? ''));
            $variation->set_length(wc_clean($variation_payload['length'] ?? ''));
            $variation->set_width(wc_clean($variation_payload['width'] ?? ''));
            $variation->set_height(wc_clean($variation_payload['height'] ?? ''));
            $variation->set_attributes((array) ($variation_payload['attributes'] ?? array()));
            $new_variation_id = $variation->save();

            if (!empty($variation_payload['image'])) {
                $image_id = $this->import_image($variation_payload['image'], $new_variation_id, $job);
                if ($image_id && !is_wp_error($image_id)) {
                    $variation->set_image_id($image_id);
                    $variation->save();
                }
            }

            update_post_meta($new_variation_id, '_maotk_wpm_old_variation_id', (int) ($variation_payload['old_id'] ?? 0));
            $this->apply_custom_meta($new_variation_id, $variation_payload['custom_meta'] ?? array());
        }
    }

    private function apply_custom_meta($post_id, $meta) {
        if (!$post_id || !is_array($meta)) {
            return;
        }

        foreach ($meta as $key => $values) {
            $key = sanitize_key($key);
            if (!$key || in_array($key, $this->reserved_meta_keys(), true)) {
                continue;
            }

            delete_post_meta($post_id, $key);
            foreach ((array) $values as $value) {
                add_post_meta($post_id, $key, $value, false);
            }
        }
    }

    private function serialize_product($product, $options) {
        $id = $product->get_id();
        $payload = array(
            'schema' => self::SCHEMA,
            'source_site' => home_url('/'),
            'old_id' => $id,
            'old_url' => get_permalink($id),
            'type' => $product->get_type(),
            'name' => $product->get_name('edit'),
            'slug' => $product->get_slug('edit'),
            'status' => $product->get_status('edit'),
            'description' => $product->get_description('edit'),
            'short_description' => $product->get_short_description('edit'),
            'sku' => $product->get_sku('edit'),
            'regular_price' => $product->get_regular_price('edit'),
            'sale_price' => $product->get_sale_price('edit'),
            'price' => $product->get_price('edit'),
            'manage_stock' => $product->get_manage_stock('edit'),
            'stock_quantity' => $product->get_stock_quantity('edit'),
            'stock_status' => $product->get_stock_status('edit'),
            'backorders' => $product->get_backorders('edit'),
            'sold_individually' => $product->get_sold_individually('edit'),
            'weight' => $product->get_weight('edit'),
            'length' => $product->get_length('edit'),
            'width' => $product->get_width('edit'),
            'height' => $product->get_height('edit'),
            'catalog_visibility' => method_exists($product, 'get_catalog_visibility') ? $product->get_catalog_visibility('edit') : 'visible',
            'featured' => method_exists($product, 'get_featured') ? $product->get_featured('edit') : false,
            'virtual' => method_exists($product, 'get_virtual') ? $product->get_virtual('edit') : false,
            'reviews_allowed' => method_exists($product, 'get_reviews_allowed') ? $product->get_reviews_allowed('edit') : true,
            'purchase_note' => method_exists($product, 'get_purchase_note') ? $product->get_purchase_note('edit') : '',
            'menu_order' => method_exists($product, 'get_menu_order') ? $product->get_menu_order('edit') : 0,
            'external_url' => method_exists($product, 'get_product_url') ? $product->get_product_url('edit') : '',
            'button_text' => method_exists($product, 'get_button_text') ? $product->get_button_text('edit') : '',
            'categories' => $this->term_payloads($id, 'product_cat'),
            'tags' => $this->term_payloads($id, 'product_tag'),
            'attributes' => $this->attribute_payloads($product),
            'default_attributes' => method_exists($product, 'get_default_attributes') ? $product->get_default_attributes('edit') : array(),
            'featured_image' => $this->image_payload($product->get_image_id('edit')),
            'gallery_images' => array(),
            'custom_meta' => $this->collect_custom_meta($id, $options),
            'variations' => array(),
            'exported_at' => gmdate('c'),
        );

        foreach ($product->get_gallery_image_ids('edit') as $image_id) {
            $image = $this->image_payload($image_id);
            if ($image) {
                $payload['gallery_images'][] = $image;
            }
        }

        if ($product instanceof WC_Product_Variable) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $payload['variations'][] = $this->serialize_variation($variation, $options);
                }
            }
        }

        return $payload;
    }

    private function serialize_variation($variation, $options) {
        $id = $variation->get_id();
        return array(
            'old_id' => $id,
            'status' => $variation->get_status('edit'),
            'sku' => $variation->get_sku('edit'),
            'regular_price' => $variation->get_regular_price('edit'),
            'sale_price' => $variation->get_sale_price('edit'),
            'price' => $variation->get_price('edit'),
            'manage_stock' => $variation->get_manage_stock('edit'),
            'stock_quantity' => $variation->get_stock_quantity('edit'),
            'stock_status' => $variation->get_stock_status('edit'),
            'backorders' => $variation->get_backorders('edit'),
            'weight' => $variation->get_weight('edit'),
            'length' => $variation->get_length('edit'),
            'width' => $variation->get_width('edit'),
            'height' => $variation->get_height('edit'),
            'attributes' => $variation->get_attributes('edit'),
            'image' => $this->image_payload($variation->get_image_id('edit')),
            'custom_meta' => $this->collect_custom_meta($id, $options),
        );
    }

    private function image_payload($attachment_id) {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id) {
            return null;
        }

        $url = wp_get_attachment_url($attachment_id);
        $relative = get_post_meta($attachment_id, '_wp_attached_file', true);
        $post = get_post($attachment_id);

        return array(
            'old_attachment_id' => $attachment_id,
            'url' => $url ? esc_url_raw($url) : '',
            'relative_path' => $relative ? wp_normalize_path($relative) : '',
            'filename' => $relative ? basename($relative) : basename((string) parse_url((string) $url, PHP_URL_PATH)),
            'title' => $post ? $post->post_title : '',
            'caption' => $post ? $post->post_excerpt : '',
            'description' => $post ? $post->post_content : '',
            'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'mime_type' => get_post_mime_type($attachment_id),
        );
    }

    private function term_payloads($post_id, $taxonomy) {
        $terms = wp_get_post_terms($post_id, $taxonomy);
        if (is_wp_error($terms) || !$terms) {
            return array();
        }

        $payloads = array();
        foreach ($terms as $term) {
            $ancestors = array_reverse(get_ancestors($term->term_id, $taxonomy, 'taxonomy'));
            $ancestor_payloads = array();
            foreach ($ancestors as $ancestor_id) {
                $ancestor = get_term($ancestor_id, $taxonomy);
                if ($ancestor && !is_wp_error($ancestor)) {
                    $ancestor_payloads[] = array('name' => $ancestor->name, 'slug' => $ancestor->slug);
                }
            }

            $payloads[] = array(
                'name' => $term->name,
                'slug' => $term->slug,
                'ancestors' => $ancestor_payloads,
            );
        }

        return $payloads;
    }

    private function attribute_payloads($product) {
        $payloads = array();

        foreach ($product->get_attributes() as $attribute) {
            if (!is_a($attribute, 'WC_Product_Attribute')) {
                continue;
            }

            $name = $attribute->get_name();
            $item = array(
                'name' => $name,
                'label' => wc_attribute_label($name),
                'is_taxonomy' => $attribute->is_taxonomy(),
                'position' => $attribute->get_position(),
                'visible' => $attribute->get_visible(),
                'variation' => $attribute->get_variation(),
                'options' => array(),
                'terms' => array(),
            );

            if ($attribute->is_taxonomy()) {
                foreach ($attribute->get_terms() as $term) {
                    $item['terms'][] = array(
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }
            } else {
                $item['options'] = array_values(array_map('strval', $attribute->get_options()));
            }

            $payloads[] = $item;
        }

        return $payloads;
    }

    private function collect_custom_meta($post_id, $options) {
        $mode = $options['meta_mode'] ?? 'safe';
        if ('none' === $mode) {
            return array();
        }

        $whitelist = array_map('sanitize_key', (array) ($options['meta_keys'] ?? array()));
        $meta = get_post_meta($post_id);
        $result = array();

        foreach ($meta as $key => $values) {
            if ('whitelist' === $mode && !in_array($key, $whitelist, true)) {
                continue;
            }

            if ('all' !== $mode && 'whitelist' !== $mode && $this->is_reserved_or_internal_meta($key)) {
                continue;
            }

            if (in_array($key, $this->reserved_meta_keys(), true)) {
                continue;
            }

            $result[$key] = array_map('maybe_unserialize', $values);
        }

        return $result;
    }

    private function is_reserved_or_internal_meta($key) {
        if (in_array($key, $this->reserved_meta_keys(), true)) {
            return true;
        }

        $safe_prefixes = array('_yoast_', 'rank_math_', '_rank_math_', '_aioseo_', '_seopress_', 'acf_', '_acf_', 'maotk_', '_maotk_');
        foreach ($safe_prefixes as $prefix) {
            if (0 === strpos($key, $prefix)) {
                return false;
            }
        }

        return 0 === strpos($key, '_');
    }

    private function reserved_meta_keys() {
        return array(
            '_edit_lock',
            '_edit_last',
            '_thumbnail_id',
            '_product_image_gallery',
            '_product_attributes',
            '_sku',
            '_price',
            '_regular_price',
            '_sale_price',
            '_stock',
            '_stock_status',
            '_manage_stock',
            '_children',
            '_wc_average_rating',
            '_wc_review_count',
            '_wp_old_slug',
        );
    }

    public function ajax_retry_failed() {
        $this->verify_ajax();
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) {
            wp_send_json_error(array('message' => '缺少任务 ID。'), 400);
        }

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->items_table} SET status = 'retrying', retry_count = retry_count + 1, error_message = NULL, stage = 'retrying', updated_at = %s WHERE job_id = %d AND status = 'failed'",
            $this->now(),
            $job_id
        ));

        $this->recalculate_stats($job_id);
        $this->update_job($job_id, array('status' => 'queued'));
        $this->log($job_id, null, 'info', '失败项已重新加入队列。');

        wp_send_json_success($this->job_response($job_id, '失败项已重新加入队列。'));
    }

    public function ajax_cleanup_job() {
        $this->verify_ajax();
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) {
            wp_send_json_error(array('message' => '缺少任务 ID。'), 400);
        }

        $job = $this->get_job($job_id);
        if (!$job) {
            wp_send_json_error(array('message' => '任务不存在。'), 404);
        }

        $this->delete_path($job->work_dir);
        $this->update_job($job_id, array('work_dir' => ''));
        $this->log($job_id, null, 'info', '临时文件已清理。');

        wp_send_json_success($this->job_response($job_id, '临时文件已清理。'));
    }

    private function finalize_job($job) {
        if ('export' === $job->job_type) {
            $this->finalize_export_job($job);
        } else {
            $stats = $this->recalculate_stats($job->id);
            $status = !empty($stats['failed']) ? 'complete_with_errors' : 'complete';
            $this->update_job($job->id, array('status' => $status, 'completed_at' => $this->now()));
        }
    }

    private function finalize_export_job($job) {
        $stats = $this->recalculate_stats($job->id);
        $work_dir = trailingslashit($job->work_dir);
        wp_mkdir_p($work_dir);

        $manifest = array(
            'schema' => self::SCHEMA,
            'plugin' => 'MaoTK Woo Product Migrator',
            'version' => self::VERSION,
            'source_site' => home_url('/'),
            'exported_at' => gmdate('c'),
            'job_id' => (int) $job->id,
            'stats' => $stats,
        );

        file_put_contents($work_dir . 'manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->write_failures_csv($job->id, $work_dir . 'failures.csv');

        $package_path = $work_dir . 'maotk-products-job-' . (int) $job->id . '.zip';
        $zip_result = $this->make_zip($work_dir, $package_path, array('manifest.json', 'products.jsonl', 'failures.csv'));
        if (is_wp_error($zip_result)) {
            $this->update_job($job->id, array(
                'status' => 'complete_with_errors',
                'last_error' => $zip_result->get_error_message(),
                'completed_at' => $this->now(),
            ));
            return;
        }

        $status = !empty($stats['failed']) ? 'complete_with_errors' : 'complete';
        $this->update_job($job->id, array(
            'status' => $status,
            'package_path' => $package_path,
            'completed_at' => $this->now(),
        ));
    }

    private function make_zip($dir, $zip_path, $files) {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if (true !== $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                return new WP_Error('maotk_wpm_zip_create', '无法创建导出 zip。');
            }
            foreach ($files as $file) {
                $path = trailingslashit($dir) . $file;
                if (file_exists($path)) {
                    $zip->addFile($path, $file);
                }
            }
            $zip->close();
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        $paths = array();
        foreach ($files as $file) {
            $path = trailingslashit($dir) . $file;
            if (file_exists($path)) {
                $paths[] = $path;
            }
        }

        $archive = new PclZip($zip_path);
        $result = $archive->create($paths, PCLZIP_OPT_REMOVE_PATH, $dir);
        if (0 === $result) {
            return new WP_Error('maotk_wpm_zip_create', '无法创建导出 zip：' . $archive->errorInfo(true));
        }

        return true;
    }

    private function write_failures_csv($job_id, $path) {
        global $wpdb;

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT old_product_id, title, sku, old_url, stage, error_message, retry_count FROM {$this->items_table} WHERE job_id = %d AND status = 'failed' ORDER BY id ASC",
            $job_id
        ));

        $handle = fopen($path, 'w');
        if (!$handle) {
            return;
        }

        fputcsv($handle, array('old_product_id', 'title', 'sku', 'old_url', 'stage', 'error_message', 'retry_count'));
        foreach ($items as $item) {
            fputcsv($handle, array($item->old_product_id, $item->title, $item->sku, $item->old_url, $item->stage, $item->error_message, $item->retry_count));
        }

        fclose($handle);
    }

    public function download_package() {
        $job = $this->download_guard();
        if (empty($job->package_path) || !file_exists($job->package_path)) {
            wp_die('导出包不存在或已被清理。');
        }

        $this->stream_file($job->package_path, basename($job->package_path), 'application/zip');
    }

    public function download_failures() {
        $job = $this->download_guard();
        $tmp = trailingslashit($this->storage_root()) . 'tmp/failures-job-' . (int) $job->id . '-' . wp_rand(100000, 999999) . '.csv';
        wp_mkdir_p(dirname($tmp));
        $this->write_failures_csv($job->id, $tmp);
        $this->stream_file($tmp, 'failures-job-' . (int) $job->id . '.csv', 'text/csv', true);
    }

    private function download_guard() {
        if (!current_user_can($this->capability())) {
            wp_die('权限不足。');
        }
        check_admin_referer(self::NONCE_ACTION, 'maotk_wpm_nonce');
        $job_id = isset($_GET['job_id']) ? absint($_GET['job_id']) : 0;
        $job = $this->get_job($job_id);
        if (!$job) {
            wp_die('任务不存在。');
        }
        return $job;
    }

    private function stream_file($path, $filename, $content_type, $delete_after = false) {
        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        if ($delete_after) {
            @unlink($path);
        }
        exit;
    }

    private function insert_job($type, $title, $options, $total) {
        global $wpdb;

        $wpdb->insert($this->jobs_table, array(
            'job_type' => sanitize_key($type),
            'status' => 'queued',
            'title' => sanitize_text_field($title),
            'options' => wp_json_encode($options),
            'stats' => wp_json_encode($this->base_stats($total)),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ));

        return (int) $wpdb->insert_id;
    }

    private function base_stats($total) {
        return array(
            'total' => (int) $total,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        );
    }

    private function insert_item($job_id, $data) {
        global $wpdb;

        $wpdb->insert($this->items_table, wp_parse_args($data, array(
            'job_id' => (int) $job_id,
            'old_product_id' => null,
            'new_product_id' => null,
            'product_type' => '',
            'title' => '',
            'sku' => '',
            'old_url' => '',
            'payload_path' => '',
            'status' => 'queued',
            'stage' => '',
            'error_message' => '',
            'retry_count' => 0,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        )));

        return (int) $wpdb->insert_id;
    }

    private function get_job($job_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->jobs_table} WHERE id = %d", $job_id));
    }

    private function update_job($job_id, $data) {
        global $wpdb;
        $data['updated_at'] = $this->now();
        $wpdb->update($this->jobs_table, $data, array('id' => (int) $job_id));
    }

    private function update_item($item_id, $data) {
        global $wpdb;
        $data['updated_at'] = $this->now();
        $wpdb->update($this->items_table, $data, array('id' => (int) $item_id));
    }

    private function increment_stat($job_id, $key) {
        $job = $this->get_job($job_id);
        $stats = $this->decode_json($job->stats);
        $stats[$key] = (int) ($stats[$key] ?? 0) + 1;
        $this->update_job($job_id, array('stats' => wp_json_encode($stats)));
    }

    private function recalculate_stats($job_id) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS count FROM {$this->items_table} WHERE job_id = %d GROUP BY status",
            $job_id
        ));

        $stats = $this->base_stats(0);
        foreach ($rows as $row) {
            $stats['total'] += (int) $row->count;
            if (isset($stats[$row->status])) {
                $stats[$row->status] = (int) $row->count;
            }
        }

        $this->update_job($job_id, array('stats' => wp_json_encode($stats)));
        return $stats;
    }

    private function fail_item($job_id, $item, $stage, $message) {
        $this->update_item($item->id, array(
            'status' => 'failed',
            'stage' => sanitize_key($stage),
            'error_message' => $message,
        ));
        $this->increment_stat($job_id, 'failed');
        $this->log($job_id, $item->id, 'error', $message, array(
            'old_product_id' => $item->old_product_id,
            'old_url' => $item->old_url,
            'title' => $item->title,
            'sku' => $item->sku,
            'stage' => $stage,
        ));
    }

    private function mark_job_failed($job_id, $message) {
        $this->update_job($job_id, array('status' => 'failed', 'last_error' => $message, 'completed_at' => $this->now()));
        $this->log($job_id, null, 'error', $message);
    }

    private function log($job_id, $item_id, $level, $message, $context = array()) {
        global $wpdb;

        if (!$job_id) {
            return;
        }

        $wpdb->insert($this->logs_table, array(
            'job_id' => (int) $job_id,
            'item_id' => $item_id ? (int) $item_id : null,
            'level' => sanitize_key($level),
            'message' => $message,
            'context' => $context ? wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $this->now(),
        ));
    }

    private function prepare_job_dir($job_id, $bucket) {
        $dir = trailingslashit($this->storage_root()) . trim($bucket, '/') . '/job-' . (int) $job_id;
        wp_mkdir_p($dir);
        return wp_normalize_path($dir);
    }

    private function job_response($job_id, $message, $done = false) {
        $job = $this->get_job($job_id);
        $stats = $job ? $this->decode_json($job->stats) : array();
        $total = (int) ($stats['total'] ?? 0);
        $complete = (int) ($stats['success'] ?? 0) + (int) ($stats['failed'] ?? 0) + (int) ($stats['skipped'] ?? 0);

        return array(
            'message' => $message,
            'jobId' => (int) $job_id,
            'status' => $job ? $job->status : '',
            'stats' => $stats,
            'progress' => $total ? round(($complete / $total) * 100, 2) : 0,
            'done' => $done || ($job && in_array($job->status, array('complete', 'complete_with_errors', 'failed'), true)),
        );
    }

    private function decode_json($json) {
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : array();
    }

    private function split_keys($text) {
        if (!$text) {
            return array();
        }

        $keys = preg_split('/[\s,]+/', $text);
        return array_values(array_filter(array_map('sanitize_key', $keys)));
    }

    public function cleanup_expired_data() {
        $settings = $this->get_settings();
        $root = $this->storage_root();

        $this->delete_old_dirs($root . '/tmp', (int) $settings['tmp_hours'] * HOUR_IN_SECONDS);
        $this->delete_old_dirs($root . '/exports', (int) $settings['package_days'] * DAY_IN_SECONDS);

        global $wpdb;
        $success_cutoff = gmdate('Y-m-d H:i:s', time() - ((int) $settings['success_log_days'] * DAY_IN_SECONDS));
        $failure_cutoff = gmdate('Y-m-d H:i:s', time() - ((int) $settings['failure_log_days'] * DAY_IN_SECONDS));

        $wpdb->query($wpdb->prepare("DELETE FROM {$this->logs_table} WHERE level != 'error' AND created_at < %s", $success_cutoff));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->logs_table} WHERE level = 'error' AND created_at < %s", $failure_cutoff));
    }

    private function delete_old_dirs($path, $max_age) {
        if (!is_dir($path)) {
            return;
        }

        $items = glob(trailingslashit($path) . '*');
        if (!$items) {
            return;
        }

        foreach ($items as $item) {
            if (time() - filemtime($item) > $max_age) {
                $this->delete_path($item);
            }
        }
    }

    private function delete_path($path) {
        if (!$path || !file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }

        @rmdir($path);
    }
}

register_activation_hook(__FILE__, array('MaoTK_Woo_Product_Migrator', 'activate'));
register_deactivation_hook(__FILE__, array('MaoTK_Woo_Product_Migrator', 'deactivate'));
MaoTK_Woo_Product_Migrator::instance();
