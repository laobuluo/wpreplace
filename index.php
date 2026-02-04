<?php
/**
Plugin Name: WPReplace批量替换插件
Plugin URI: https://www.lezaiyun.com/804.html
Description: 实现可视化替换文章内容、标题，评论昵称和评论内容字符。公众号：老蒋朋友圈。
Version: 7.2
Author: 老蒋和他的小伙伴
Author URI: https://www.lezaiyun.com
*/

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

define('WPReplace_INDEXFILE', 'wpreplace/index.php');
define('WPReplace_VERSION', '7.1');
define('WPReplace_PATH', plugin_dir_path(__FILE__));

// 在插件加载时就定义表名
global $wpdb;
define('WPReplace_HISTORY_TABLE', $wpdb->prefix . 'wpreplace_history');
define('WPReplace_BACKUP_TABLE', $wpdb->prefix . 'wpreplace_backup');

class WPReplace {
    private static $instance = null;
    private $table_history;
    private $table_backup;
    private $initialized = false;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        try {
            $this->table_history = WPReplace_HISTORY_TABLE;
            $this->table_backup = WPReplace_BACKUP_TABLE;
            
            // 添加菜单和设置
            add_action('admin_menu', array($this, 'add_setting_page'));
            add_action('admin_init', array($this, 'admin_init'));
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_links'));
            
            // 添加激活钩子
            register_activation_hook(__FILE__, array('WPReplace', 'activate_plugin'));
            register_deactivation_hook(__FILE__, array('WPReplace', 'deactivate_plugin'));

            // 添加管理员通知
            add_action('admin_notices', array($this, 'admin_notices'));
            
            $this->initialized = true;
        } catch (Exception $e) {
            error_log('WPReplace Plugin Initialization Error: ' . $e->getMessage());
        }
    }

    public function is_initialized() {
        return $this->initialized;
    }

    public function admin_init() {
        try {
            // 注册设置
            register_setting('wpreplace_options', 'wpreplace_options');
            
            // 添加样式
            wp_enqueue_style('wp-admin');

            // 检查并尝试修复数据表
            $tables_exist = $this->check_tables_exist();
            if (!$tables_exist['all_exist']) {
                add_action('admin_notices', function() {
                    if (!current_user_can('manage_options')) {
                        return;
                    }
                    echo '<div class="notice notice-warning is-dismissible">';
                    echo '<p>WPReplace插件数据表不完整，请访问设置页面进行修复。</p>';
                    echo '</div>';
                });
            }
        } catch (Exception $e) {
            error_log('WPReplace Plugin Admin Init Error: ' . $e->getMessage());
        }
    }

    public function admin_notices() {
        try {
            if (!current_user_can('manage_options')) {
                return;
            }

            // 只在插件页面和设置页面显示
            $current_screen = get_current_screen();
            if (!$current_screen || !in_array($current_screen->id, ['plugins', 'tools_page_wpreplace-settings', 'settings_page_wpreplace-settings'])) {
                return;
            }

            // 检查数据表
            $tables_exist = $this->check_tables_exist();
            if (!$tables_exist['all_exist']) {
                $missing_tables = [];
                if (!$tables_exist['history']) $missing_tables[] = '历史记录表';
                if (!$tables_exist['backup']) $missing_tables[] = '备份表';
                
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>WPReplace插件提示：</strong> 以下数据表缺失：' . implode('、', $missing_tables) . '</p>';
                echo '<p>请尝试以下步骤：</p>';
                echo '<ol>';
                echo '<li>停用并重新激活插件</li>';
                echo '<li>如果问题仍然存在，请点击设置页面中的"手动修复数据表"按钮</li>';
                echo '<li>确保数据库用户具有创建表的权限</li>';
                echo '</ol>';
                echo '</div>';
            }
        } catch (Exception $e) {
            error_log('WPReplace Plugin Admin Notices Error: ' . $e->getMessage());
        }
    }

    public static function activate_plugin() {
        global $wpdb;
        
        try {
            if (!function_exists('get_plugin_data')) {
                require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }
            
            // 基本检查
            if (version_compare(PHP_VERSION, '8.0', '<')) {
                throw new Exception('此插件需要PHP 8.0或更高版本');
            }
            
            if (!$wpdb || !$wpdb->check_connection(false)) {
                throw new Exception('无法连接到数据库，请检查数据库配置');
            }
            
            // 创建实例并强制创建表
            $instance = self::get_instance();
            if (!$instance->is_initialized()) {
                throw new Exception('插件初始化失败');
            }
            
            if (!$instance->force_create_tables()) {
                throw new Exception('创建数据表失败，请检查数据库权限');
            }
            
            // 更新版本信息
            update_option('wpreplace_version', WPReplace_VERSION);
            update_option('wpreplace_db_version', WPReplace_VERSION);
            
        } catch (Exception $e) {
            error_log('WPReplace Plugin Activation Error: ' . $e->getMessage());
            
            // 停用插件
            if (function_exists('deactivate_plugins')) {
                deactivate_plugins(plugin_basename(__FILE__));
            }
            
            wp_die(
                '插件激活失败。<br><br>' . 
                esc_html($e->getMessage()) . '<br><br>' .
                '请检查以下几点：<br>' .
                '1. PHP版本是否为8.0或更高<br>' .
                '2. 数据库用户是否有创建表的权限<br>' .
                '3. 数据库连接是否正常<br>' .
                '4. WordPress是否有写入权限<br><br>' .
                '<a href="' . admin_url('plugins.php') . '">返回插件页面</a>',
                '插件激活错误',
                array('back_link' => true)
            );
        }
    }

    public static function deactivate_plugin() {
        try {
            wp_clear_scheduled_hook('wpreplace_cleanup_old_backups');
        } catch (Exception $e) {
            error_log('WPReplace Plugin Deactivation Error: ' . $e->getMessage());
        }
    }

    /**
     * 添加插件设置链接
     */
    public function add_plugin_links($links) {
        $settings_link = '<a href="' . admin_url('tools.php?page=wpreplace-settings') . '">设置</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function add_setting_page() {
        // 在工具菜单下添加
        add_management_page(
            'WPReplace批量替换', 
            'WPReplace批量替换', 
            'manage_options', 
            'wpreplace-settings', 
            array($this, 'render_setting_page')
        );

        // 在设置菜单下也添加一个入口
        add_options_page(
            'WPReplace批量替换', 
            'WPReplace批量替换', 
            'manage_options', 
            'wpreplace-settings', 
            array($this, 'render_setting_page')
        );
    }

    public function render_setting_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient privileges!');
        }

        // 处理表单提交
        if (isset($_POST['submit']) && check_admin_referer('wpreplace_action')) {
            $original_content = sanitize_text_field(wp_unslash($_POST['originalContent']));
            $new_content = sanitize_text_field(wp_unslash($_POST['newContent']));
            $replace_selector = absint($_POST['replaceSelector']);
            $is_regex = isset($_POST['useRegex']) && $_POST['useRegex'] == '1';

            $result = $this->perform_replace($original_content, $new_content, $replace_selector, $is_regex);
            
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>成功替换了 ' . intval($result) . ' 处内容。</p></div>';
            }
        }

        // 声明全局变量
        global $wpdb;

        // 添加手动修复按钮的处理
        if (isset($_POST['force_repair_tables']) && check_admin_referer('wpreplace_force_repair')) {
            try {
                self::activate_plugin();
                echo '<div class="notice notice-success"><p>数据表修复完成。</p></div>';
            } catch (Exception $e) {
                echo '<div class="notice notice-error"><p>修复失败：' . esc_html($e->getMessage()) . '</p></div>';
            }
        }

        // 检查数据表是否存在
        $history_exists = $wpdb->get_var("SHOW TABLES LIKE '" . $this->table_history . "'") === $this->table_history;
        $backup_exists = $wpdb->get_var("SHOW TABLES LIKE '" . $this->table_backup . "'") === $this->table_backup;

        if (!$history_exists || !$backup_exists) {
            echo '<div class="notice notice-error"><p>数据表不存在。请尝试手动修复。</p>';
            echo '<form method="post" action="">';
            wp_nonce_field('wpreplace_force_repair');
            echo '<p><button type="submit" name="force_repair_tables" class="button button-primary">手动修复数据表</button></p>';
            echo '</form></div>';
            return;
        }

        // 检查是否需要修复数据表
        if (isset($_POST['repair_tables']) && check_admin_referer('wpreplace_repair_tables')) {
            $check_result = $this->check_and_repair_tables();
            if ($check_result['status']) {
                echo '<div class="notice notice-success"><p>数据表检查完成，未发现问题。</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>发现以下问题：<br>' . 
                     implode('<br>', $check_result['messages']) . '</p></div>';
            }
        }

        // 检查数据表状态
        $check_result = $this->check_and_repair_tables();
        if (!$check_result['status']) {
            echo '<div class="notice notice-error"><p>数据表存在问题：<br>' . 
                 implode('<br>', $check_result['messages']) . 
                 '</p><form method="post" action="">' .
                 wp_nonce_field('wpreplace_repair_tables', '_wpnonce', true, false) .
                 '<p><button type="submit" name="repair_tables" class="button button-primary">尝试修复数据表</button></p>' .
                 '</form></div>';
        }

        $message = '';
        $message_type = 'success';

        // 处理还原操作
        if (isset($_GET['action']) && $_GET['action'] === 'restore' && isset($_GET['history_id'])) {
            check_admin_referer('restore_history');
            
            $history_id = absint($_GET['history_id']);
            $result = $this->restore_from_backup($history_id);
            
            if (is_wp_error($result)) {
                $message = '还原失败：' . $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = sprintf('成功还原了 %d 处内容。', $result);
            }
        }

        // 处理删除操作
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['history_id'])) {
            check_admin_referer('delete_history');
            
            $history_id = absint($_GET['history_id']);
            $result = $this->delete_history($history_id);
            
            if (is_wp_error($result)) {
                $message = '删除失败：' . $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = '历史记录已成功删除。';
                $message_type = 'success';
            }
        }

        // 显示消息
        if ($message) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p><strong>%s</strong></p></div>',
                esc_attr($message_type),
                esc_html($message)
            );
        }

        // 尝试获取历史记录
        $history_items = [];
        try {
            $history_items = $wpdb->get_results(
                "SELECT * FROM " . $this->table_history . " ORDER BY created_at DESC LIMIT 10"
            );
        } catch (Exception $e) {
            error_log("WPReplace Plugin: Error fetching history - " . $e->getMessage());
        }
        ?>
        <div class="wrap">
            <h1>WPReplace批量替换插件</h1>
            <p>替换内容设置，建议提前备份数据。<a href="https://www.lezaiyun.com/804.html" target="_blank">插件介绍</a>（关注公众号：<span style="color: red;">老蒋朋友圈</span>）</p>
            <style>
                .wpreplace-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                    margin-top: 20px;
                    padding: 20px;
                    width: 100%;
                    box-sizing: border-box;
                }
                .wpreplace-card h2.title {
                    margin-top: 0;
                    padding: 0 0 15px;
                    border-bottom: 1px solid #eee;
                }
                .wpreplace-card .inside {
                    padding: 10px 0;
                }
            </style>
            
            <div class="wpreplace-card">
                <h2 class="title">执行替换</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('wpreplace_action'); ?>
                    
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="originalContent">目标内容</label></th>
                            <td>
                                <input type="text" id="originalContent" name="originalContent" class="regular-text" 
                                    value="<?php echo isset($_POST['originalContent']) ? esc_attr(wp_unslash($_POST['originalContent'])) : ''; ?>" required />
                                <p class="description">输入需要替换的目标内容</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="newContent">替换内容</label></th>
                            <td>
                                <input type="text" id="newContent" name="newContent" class="regular-text" 
                                    value="<?php echo isset($_POST['newContent']) ? esc_attr(wp_unslash($_POST['newContent'])) : ''; ?>" />
                                <p class="description">输入替换后的内容，留空表示删除目标内容</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="replaceSelector">选择器</label></th>
                            <td>
                                <select name="replaceSelector" id="replaceSelector" class="regular-text">
                                    <option value="1" <?php selected(isset($_POST['replaceSelector']) ? $_POST['replaceSelector'] : '', '1'); ?>>文章内容文字/字符替换</option>
                                    <option value="2" <?php selected(isset($_POST['replaceSelector']) ? $_POST['replaceSelector'] : '', '2'); ?>>文章标题/字符替换</option>
                                    <option value="3" <?php selected(isset($_POST['replaceSelector']) ? $_POST['replaceSelector'] : '', '3'); ?>>评论用户昵称/内容字符替换</option>
                                    <option value="4" <?php selected(isset($_POST['replaceSelector']) ? $_POST['replaceSelector'] : '', '4'); ?>>评论用户邮箱/网址替换</option>
                                    <option value="5" <?php selected(isset($_POST['replaceSelector']) ? $_POST['replaceSelector'] : '', '5'); ?>>文章摘要批量替换</option>
                                    <option value="6" <?php selected(isset($_POST['replaceSelector']) ? $_POST['replaceSelector'] : '', '6'); ?>>标签/TAGS批量替换</option>
                                </select>
                                <p class="description">选择需要执行替换的内容类型</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">高级选项</th>
                            <td>
                                <label for="useRegex">
                                    <input type="checkbox" name="useRegex" id="useRegex" value="1" 
                                        <?php checked(isset($_POST['useRegex']) && $_POST['useRegex'] == '1'); ?> />
                                    启用正则表达式模式
                                </label>
                                <p class="description">使用正则表达式进行更复杂的匹配和替换</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <?php submit_button('预览替换', 'secondary', 'preview', false); ?>
                        <?php submit_button('执行替换', 'primary', 'submit', false); ?>
                    </p>
                </form>
            </div>

            <?php if (isset($_POST['preview'])): ?>
            <div class="wpreplace-card">
                <h2 class="title">替换预览</h2>
                <?php
                $preview_data = $this->preview_replace(
                    sanitize_text_field(wp_unslash($_POST['originalContent'])),
                    sanitize_text_field(wp_unslash($_POST['newContent'])),
                    absint($_POST['replaceSelector']),
                    isset($_POST['useRegex']) && $_POST['useRegex'] == '1'
                );
                
                if (empty($preview_data)): ?>
                    <p>没有找到匹配的内容</p>
                <?php else: ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>原内容</th>
                                <th>替换后内容</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_data as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item['id']); ?></td>
                                <td><?php echo esc_html($item['old_content']); ?></td>
                                <td><?php echo esc_html($item['new_content']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description">注意：这只显示前10条匹配结果的预览</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="wpreplace-card">
                <h2 class="title">使用示例</h2>
                <div class="inside">
                    <h3>1. 普通文本替换</h3>
                    <table class="widefat" style="margin-bottom: 20px;">
                        <thead>
                            <tr>
                                <th>目标内容</th>
                                <th>替换内容</th>
                                <th>说明</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Hello</td>
                                <td>你好</td>
                                <td>简单的文本替换，将"Hello"替换为"你好"</td>
                            </tr>
                            <tr>
                                <td>WordPress</td>
                                <td>WP</td>
                                <td>将所有"WordPress"替换为"WP"</td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>2. 正则表达式替换示例</h3>
                    <p style="color:#666;margin-bottom:10px;">注意：使用正则表达式时需要勾选"启用正则表达式模式"</p>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>目标内容（正则）</th>
                                <th>替换内容</th>
                                <th>说明</th>
                                <th>示例</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>\b\d{11}\b</code></td>
                                <td>***********</td>
                                <td>替换11位手机号为星号</td>
                                <td>13812345678 → ***********</td>
                            </tr>
                            <tr>
                                <td><code>http://[^\s<>"]+</code></td>
                                <td>https://$0</td>
                                <td>将http链接转换为https<br>($0表示匹配的完整URL)</td>
                                <td>http://example.com → https://example.com</td>
                            </tr>
                            <tr>
                                <td><code>\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b</code></td>
                                <td>[邮箱已隐藏]</td>
                                <td>替换邮箱地址为提示文本</td>
                                <td>user@example.com → [邮箱已隐藏]</td>
                            </tr>
                            <tr>
                                <td><code>\[([^\]]+)\]\(([^\)]+)\)</code></td>
                                <td>&lt;a href="$2"&gt;$1&lt;/a&gt;</td>
                                <td>将Markdown链接转换为HTML链接<br>($1是链接文本，$2是URL)</td>
                                <td>[链接](http://example.com) → &lt;a href="http://example.com"&gt;链接&lt;/a&gt;</td>
                            </tr>
                            <tr>
                                <td><code>(\d+)\.(\d{2})</code></td>
                                <td>¥$1.$2</td>
                                <td>为价格添加货币符号<br>($1是整数部分，$2是小数部分)</td>
                                <td>99.99 → ¥99.99</td>
                            </tr>
                            <tr>
                                <td><code>\b(19|20)\d{2}[- /.](0[1-9]|1[012])[- /.](0[1-9]|[12][0-9]|3[01])\b</code></td>
                                <td>[$0]</td>
                                <td>为日期添加方括号</td>
                                <td>2024-03-15 → [2024-03-15]</td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>3. 正则表达式常用语法说明</h3>
                    <table class="widefat" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th>符号</th>
                                <th>说明</th>
                                <th>示例</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>\b</code></td>
                                <td>单词边界</td>
                                <td>\bword\b 匹配独立的"word"</td>
                            </tr>
                            <tr>
                                <td><code>\d</code></td>
                                <td>任意数字</td>
                                <td>\d{3} 匹配3个数字</td>
                            </tr>
                            <tr>
                                <td><code>[]</code></td>
                                <td>字符集合</td>
                                <td>[abc] 匹配a、b或c</td>
                            </tr>
                            <tr>
                                <td><code>+</code></td>
                                <td>一个或多个</td>
                                <td>\d+ 匹配1个或多个数字</td>
                            </tr>
                            <tr>
                                <td><code>*</code></td>
                                <td>零个或多个</td>
                                <td>\d* 匹配0个或多个数字</td>
                            </tr>
                            <tr>
                                <td><code>{n}</code></td>
                                <td>精确匹配n次</td>
                                <td>\d{4} 匹配4个数字</td>
                            </tr>
                            <tr>
                                <td><code>$1,$2...</code></td>
                                <td>捕获组引用</td>
                                <td>用于替换内容中引用匹配的组</td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>4. 使用建议</h3>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li>使用正则表达式前，强烈建议先使用"预览替换"功能查看效果</li>
                        <li>复杂的正则表达式建议先在在线工具（如 regex101.com）中测试</li>
                        <li>对于重要内容的替换，建议先进行数据库备份</li>
                        <li>对于批量替换，建议先在小范围内测试效果</li>
                        <li>使用捕获组($1,$2)时，确保正则表达式中包含相应的括号()</li>
                    </ul>
                </div>
            </div>

            <div class="wpreplace-card">
                <h2 class="title">替换历史</h2>
                <?php if (empty($history_items)): ?>
                    <p>暂无替换历史记录</p>
                <?php else: ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>替换类型</th>
                                <th>原内容</th>
                                <th>新内容</th>
                                <th>影响数量</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history_items as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item->created_at); ?></td>
                                <td><?php echo esc_html($item->replace_type); ?></td>
                                <td><?php echo esc_html($item->original_content); ?></td>
                                <td><?php echo esc_html($item->new_content); ?></td>
                                <td><?php echo esc_html($item->affected_count); ?></td>
                                <td>
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array(
                                        'action' => 'restore',
                                        'history_id' => $item->id
                                    ), admin_url('tools.php?page=wpreplace-settings')), 'restore_history'); ?>"
                                    class="button button-small">还原</a>
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array(
                                        'action' => 'delete',
                                        'history_id' => $item->id
                                    ), admin_url('tools.php?page=wpreplace-settings')), 'delete_history'); ?>"
                                    class="button button-small" style="margin-left: 5px; color: #a00;" 
                                    onclick="return confirm('确定要删除这条历史记录吗？此操作不可撤销。');">删除</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="wpreplace-card">
                <h2 class="title">注意事项</h2>
                <div class="inside">
                    <ol>
                        <li>不熟悉的用户建议备份数据库，确保错误后可以恢复</li>
                        <li>根据需要替换对象在选择器选择对象</li>
                        <li>替换操作不可撤销，请谨慎操作</li>
                        <li>建议先在测试环境中进行替换操作</li>
                        <li>使用正则表达式时请确保语法正确</li>
                    </ol>
                </div>
            </div>

            <div class="wpreplace-card">
                <h2>数据库状态</h2>
                <table class="widefat" style="margin-top: 20px;">
                    <thead><tr><th>检查项</th><th>状态</th></tr></thead>
                    <tbody>
                        <?php
                        $history_exists = $wpdb->get_var("SHOW TABLES LIKE '" . $this->table_history . "'") === $this->table_history;
                        $backup_exists = $wpdb->get_var("SHOW TABLES LIKE '" . $this->table_backup . "'") === $this->table_backup;
                        
                        echo '<tr>';
                        echo '<td>历史记录表</td>';
                        echo '<td>' . ($history_exists ? '<span style="color:green;">✓ 正常</span>' : '<span style="color:red;">✗ 不存在</span>') . '</td>';
                        echo '</tr>';
                        
                        echo '<tr>';
                        echo '<td>备份表</td>';
                        echo '<td>' . ($backup_exists ? '<span style="color:green;">✓ 正常</span>' : '<span style="color:red;">✗ 不存在</span>') . '</td>';
                        echo '</tr>';
                        
                        if ($history_exists && $backup_exists) {
                            $history_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_history}");
                            $backup_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_backup}");
                            
                            echo '<tr>';
                            echo '<td>历史记录数量</td>';
                            echo '<td>' . number_format($history_count) . ' 条</td>';
                            echo '</tr>';
                            
                            echo '<tr>';
                            echo '<td>备份记录数量</td>';
                            echo '<td>' . number_format($backup_count) . ' 条</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p><img width="150" height="150" src="<?php echo plugins_url('/images/wechat.png', __FILE__); ?>" alt="扫码关注公众号" /></p>
        <?php
    }

    private function backup_content($original_content, $new_content, $replace_selector) {
        global $wpdb;
        $backup_data = array();
        
        try {
            switch (intval($replace_selector)) {
                case 1: // 文章内容
                    $posts = $wpdb->get_results($wpdb->prepare(
                        "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s",
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    foreach ($posts as $post) {
                        $backup_data[] = array(
                            'table_name' => $wpdb->posts,
                            'record_id' => $post->ID,
                            'field_name' => 'post_content',
                            'old_value' => $post->post_content
                        );
                    }
                    break;
                case 2: // 文章标题
                    $posts = $wpdb->get_results($wpdb->prepare(
                        "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_title LIKE %s",
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    foreach ($posts as $post) {
                        $backup_data[] = array(
                            'table_name' => $wpdb->posts,
                            'record_id' => $post->ID,
                            'field_name' => 'post_title',
                            'old_value' => $post->post_title
                        );
                    }
                    break;
                case 3: // 评论用户昵称和内容
                    $comments = $wpdb->get_results($wpdb->prepare(
                        "SELECT comment_ID, comment_author, comment_content FROM {$wpdb->comments} WHERE comment_author LIKE %s OR comment_content LIKE %s",
                        '%' . $wpdb->esc_like($original_content) . '%',
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    foreach ($comments as $comment) {
                        $backup_data[] = array(
                            'table_name' => $wpdb->comments,
                            'record_id' => $comment->comment_ID,
                            'field_name' => 'comment_author',
                            'old_value' => $comment->comment_author
                        );
                        $backup_data[] = array(
                            'table_name' => $wpdb->comments,
                            'record_id' => $comment->comment_ID,
                            'field_name' => 'comment_content',
                            'old_value' => $comment->comment_content
                        );
                    }
                    break;
                case 4: // 评论用户邮箱和网址
                    $comments = $wpdb->get_results($wpdb->prepare(
                        "SELECT comment_ID, comment_author_email, comment_author_url FROM {$wpdb->comments} WHERE comment_author_email LIKE %s OR comment_author_url LIKE %s",
                        '%' . $wpdb->esc_like($original_content) . '%',
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    foreach ($comments as $comment) {
                        $backup_data[] = array(
                            'table_name' => $wpdb->comments,
                            'record_id' => $comment->comment_ID,
                            'field_name' => 'comment_author_email',
                            'old_value' => $comment->comment_author_email
                        );
                        $backup_data[] = array(
                            'table_name' => $wpdb->comments,
                            'record_id' => $comment->comment_ID,
                            'field_name' => 'comment_author_url',
                            'old_value' => $comment->comment_author_url
                        );
                    }
                    break;
                case 5: // 文章摘要
                    $posts = $wpdb->get_results($wpdb->prepare(
                        "SELECT ID, post_excerpt FROM {$wpdb->posts} WHERE post_excerpt LIKE %s",
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    foreach ($posts as $post) {
                        $backup_data[] = array(
                            'table_name' => $wpdb->posts,
                            'record_id' => $post->ID,
                            'field_name' => 'post_excerpt',
                            'old_value' => $post->post_excerpt
                        );
                    }
                    break;
                case 6: // 标签
                    $terms = $wpdb->get_results($wpdb->prepare(
                        "SELECT term_id, name FROM {$wpdb->terms} WHERE name LIKE %s",
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    foreach ($terms as $term) {
                        $backup_data[] = array(
                            'table_name' => $wpdb->terms,
                            'record_id' => $term->term_id,
                            'field_name' => 'name',
                            'old_value' => $term->name
                        );
                    }
                    break;
            }
            
            return $backup_data;
        } catch (Exception $e) {
            error_log('WPReplace Plugin - Backup Content Error: ' . $e->getMessage());
            return array();
        }
    }

    private function preview_replace($original_content, $new_content, $replace_selector, $is_regex = false) {
        global $wpdb;
        $preview_data = array();
        
        switch (intval($replace_selector)) {
            case 1: // 文章内容
                $query = $is_regex 
                    ? "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content REGEXP %s LIMIT 10"
                    : "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 10";
                
                $search_term = $is_regex ? $original_content : '%' . $wpdb->esc_like($original_content) . '%';
                $posts = $wpdb->get_results($wpdb->prepare($query, $search_term));
                
                foreach ($posts as $post) {
                    $new_content_preview = $is_regex
                        ? preg_replace("/$original_content/", $new_content, $post->post_content)
                        : str_replace($original_content, $new_content, $post->post_content);
                    
                    $preview_data[] = array(
                        'id' => $post->ID,
                        'old_content' => $post->post_content,
                        'new_content' => $new_content_preview
                    );
                }
                break;
            case 2: // 文章标题
                $posts = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_title LIKE %s LIMIT 10",
                    '%' . $wpdb->esc_like($original_content) . '%'
                ));
                foreach ($posts as $post) {
                    $new_content_preview = $is_regex
                        ? preg_replace("/$original_content/", $new_content, $post->post_title)
                        : str_replace($original_content, $new_content, $post->post_title);
                    
                    $preview_data[] = array(
                        'id' => $post->ID,
                        'old_content' => $post->post_title,
                        'new_content' => $new_content_preview
                    );
                }
                break;
            case 3: // 评论用户昵称和内容
                $comments = $wpdb->get_results($wpdb->prepare(
                    "SELECT comment_ID, comment_author, comment_content FROM {$wpdb->comments} WHERE comment_author LIKE %s OR comment_content LIKE %s LIMIT 10",
                    '%' . $wpdb->esc_like($original_content) . '%',
                    '%' . $wpdb->esc_like($original_content) . '%'
                ));
                foreach ($comments as $comment) {
                    $new_content_preview = $is_regex
                        ? preg_replace("/$original_content/", $new_content, $comment->comment_author)
                        : str_replace($original_content, $new_content, $comment->comment_author);
                    
                    $preview_data[] = array(
                        'id' => $comment->comment_ID,
                        'old_content' => $comment->comment_author,
                        'new_content' => $new_content_preview
                    );
                }
                break;
            case 4: // 评论用户邮箱和网址
                $comments = $wpdb->get_results($wpdb->prepare(
                    "SELECT comment_ID, comment_author_email, comment_author_url FROM {$wpdb->comments} WHERE comment_author_email LIKE %s OR comment_author_url LIKE %s LIMIT 10",
                    '%' . $wpdb->esc_like($original_content) . '%',
                    '%' . $wpdb->esc_like($original_content) . '%'
                ));
                foreach ($comments as $comment) {
                    $new_content_preview = $is_regex
                        ? preg_replace("/$original_content/", $new_content, $comment->comment_author_email)
                        : str_replace($original_content, $new_content, $comment->comment_author_email);
                    
                    $preview_data[] = array(
                        'id' => $comment->comment_ID,
                        'old_content' => $comment->comment_author_email,
                        'new_content' => $new_content_preview
                    );
                }
                break;
            case 5: // 文章摘要
                $posts = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID, post_excerpt FROM {$wpdb->posts} WHERE post_excerpt LIKE %s LIMIT 10",
                    '%' . $wpdb->esc_like($original_content) . '%'
                ));
                foreach ($posts as $post) {
                    $new_content_preview = $is_regex
                        ? preg_replace("/$original_content/", $new_content, $post->post_excerpt)
                        : str_replace($original_content, $new_content, $post->post_excerpt);
                    
                    $preview_data[] = array(
                        'id' => $post->ID,
                        'old_content' => $post->post_excerpt,
                        'new_content' => $new_content_preview
                    );
                }
                break;
            case 6: // 标签
                $terms = $wpdb->get_results($wpdb->prepare(
                    "SELECT term_id, name FROM {$wpdb->terms} WHERE name LIKE %s LIMIT 10",
                    '%' . $wpdb->esc_like($original_content) . '%'
                ));
                foreach ($terms as $term) {
                    $new_content_preview = $is_regex
                        ? preg_replace("/$original_content/", $new_content, $term->name)
                        : str_replace($original_content, $new_content, $term->name);
                    
                    $preview_data[] = array(
                        'id' => $term->term_id,
                        'old_content' => $term->name,
                        'new_content' => $new_content_preview
                    );
                }
                break;
        }
        
        return $preview_data;
    }

    private function perform_replace($original_content, $new_content, $replace_selector, $is_regex = false) {
        global $wpdb;
        
        try {
            // 开始事务
            $wpdb->query('START TRANSACTION');
            
            // 先进行备份
            $backup_data = $this->backup_content($original_content, $new_content, $replace_selector);
            
            $affected_count = 0;
            
            switch (intval($replace_selector)) {
                case 1: // 文章内容文字/字符替换
                    if ($is_regex) {
                        $posts = $wpdb->get_results($wpdb->prepare(
                            "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content REGEXP %s",
                            $original_content
                        ));
                        
                        foreach ($posts as $post) {
                            $new_content_value = preg_replace("/{$original_content}/u", $new_content, $post->post_content);
                            if ($new_content_value !== $post->post_content) {
                                $wpdb->update(
                                    $wpdb->posts,
                                    array('post_content' => $new_content_value),
                                    array('ID' => $post->ID)
                                );
                                $affected_count++;
                            }
                        }
                    } else {
                        // 使用BINARY关键字确保区分大小写
                        $affected_count = $wpdb->query($wpdb->prepare(
                            "UPDATE {$wpdb->posts} SET post_content = REPLACE(BINARY post_content, BINARY %s, %s) 
                             WHERE BINARY post_content LIKE BINARY %s",
                            $original_content,
                            $new_content,
                            '%' . $wpdb->esc_like($original_content) . '%'
                        ));
                    }
                    break;
                    
                case 2: // 文章标题/字符替换
                    $affected_count = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->posts} SET post_title = REPLACE(BINARY post_title, BINARY %s, %s) 
                         WHERE BINARY post_title LIKE BINARY %s",
                        $original_content,
                        $new_content,
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    break;
                    
                case 3: // 评论用户昵称/内容字符替换
                    $affected_count = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->comments} 
                         SET comment_author = REPLACE(BINARY comment_author, BINARY %s, %s),
                             comment_content = REPLACE(BINARY comment_content, BINARY %s, %s)
                         WHERE BINARY comment_author LIKE BINARY %s OR BINARY comment_content LIKE BINARY %s",
                        $original_content, $new_content,
                        $original_content, $new_content,
                        '%' . $wpdb->esc_like($original_content) . '%',
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    break;
                    
                case 4: // 评论用户邮箱和网址替换
                    $affected_count = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->comments} 
                         SET comment_author_email = REPLACE(BINARY comment_author_email, BINARY %s, %s),
                             comment_author_url = REPLACE(BINARY comment_author_url, BINARY %s, %s)
                         WHERE BINARY comment_author_email LIKE BINARY %s OR BINARY comment_author_url LIKE BINARY %s",
                        $original_content, $new_content,
                        $original_content, $new_content,
                        '%' . $wpdb->esc_like($original_content) . '%',
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    break;
                    
                case 5: // 文章摘要内容替换
                    $affected_count = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->posts} SET post_excerpt = REPLACE(BINARY post_excerpt, BINARY %s, %s) 
                         WHERE BINARY post_excerpt LIKE BINARY %s",
                        $original_content,
                        $new_content,
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    break;
                    
                case 6: // 替换标签
                    $affected_count = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->terms} SET name = REPLACE(BINARY name, BINARY %s, %s) 
                         WHERE BINARY name LIKE BINARY %s",
                        $original_content,
                        $new_content,
                        '%' . $wpdb->esc_like($original_content) . '%'
                    ));
                    break;
            }
            
            // 即使没有影响任何内容，也不要回滚事务
            // 保存历史记录
            if ($affected_count > 0) {
                $history_id = $this->save_history($original_content, $new_content, $replace_selector, $affected_count, $is_regex);
                
                // 保存备份数据
                if (!empty($backup_data)) {
                    $this->save_backup($history_id, $backup_data);
                }
            }
            
            // 提交事务
            $wpdb->query('COMMIT');
            
            // 清理缓存
            wp_cache_flush();
            
            return $affected_count;
            
        } catch (Exception $e) {
            // 发生错误时回滚事务
            $wpdb->query('ROLLBACK');
            error_log('WPReplace Plugin - Replace Error: ' . $e->getMessage());
            return new WP_Error('replace_error', $e->getMessage());
        }
    }

    private function save_history($original_content, $new_content, $replace_selector, $affected_count, $is_regex = false) {
        global $wpdb;
        
        try {
            $result = $wpdb->insert(
                $this->table_history,
                array(
                    'original_content' => $original_content,
                    'new_content' => $new_content,
                    'replace_type' => $this->get_replace_type_name($replace_selector),
                    'affected_count' => intval($affected_count),
                    'user_id' => get_current_user_id(),
                    'is_regex' => $is_regex ? 1 : 0,
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%d', '%d', '%d', '%s')
            );
            
            if ($result === false) {
                throw new Exception('插入历史记录失败: ' . $wpdb->last_error);
            }
            
            return $wpdb->insert_id;
        } catch (Exception $e) {
            error_log('WPReplace Plugin - Save History Error: ' . $e->getMessage());
            return false;
        }
    }

    private function save_backup($history_id, $backup_data) {
        global $wpdb;
        
        try {
            foreach ($backup_data as $data) {
                $result = $wpdb->insert(
                    $this->table_backup,
                    array(
                        'history_id' => $history_id,
                        'table_name' => $data['table_name'],
                        'record_id' => $data['record_id'],
                        'field_name' => $data['field_name'],
                        'old_value' => $data['old_value'],
                        'created_at' => current_time('mysql')
                    ),
                    array('%d', '%s', '%d', '%s', '%s', '%s')
                );
                
                if ($result === false) {
                    throw new Exception('保存备份数据失败: ' . $wpdb->last_error);
                }
            }
            return true;
        } catch (Exception $e) {
            error_log('WPReplace Plugin - Save Backup Error: ' . $e->getMessage());
            return false;
        }
    }

    private function get_replace_type_name($replace_selector) {
        $types = array(
            1 => '文章内容',
            2 => '文章标题',
            3 => '评论用户和内容',
            4 => '评论邮箱和网址',
            5 => '文章摘要',
            6 => '标签'
        );
        return isset($types[$replace_selector]) ? $types[$replace_selector] : '未知';
    }

    private function restore_from_backup($history_id) {
        global $wpdb;
        
        // 开始事务
        $wpdb->query('START TRANSACTION');
        
        try {
            // 获取备份数据
            $backups = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_backup} WHERE history_id = %d",
                $history_id
            ));
            
            if (empty($backups)) {
                return new WP_Error('no_backup', '没有找到对应的备份数据');
            }
            
            $restored_count = 0;
            
            foreach ($backups as $backup) {
                $wpdb->update(
                    $backup->table_name,
                    array($backup->field_name => $backup->old_value),
                    array('ID' => $backup->record_id)
                );
                $restored_count++;
            }
            
            // 提交事务
            $wpdb->query('COMMIT');
            return $restored_count;
            
        } catch (Exception $e) {
            // 发生错误时回滚事务
            $wpdb->query('ROLLBACK');
            return new WP_Error('restore_error', $e->getMessage());
        }
    }

    // 添加定期清理旧备份的方法
    public function cleanup_old_backups() {
        global $wpdb;
        
        // 删除30天前的备份和历史记录
        $date = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $wpdb->query($wpdb->prepare(
            "DELETE b, h FROM {$this->table_backup} b 
            JOIN {$this->table_history} h ON b.history_id = h.id 
            WHERE h.created_at < %s",
            $date
        ));
    }

    /**
     * 检查并尝试修复数据表
     * @return array 包含状态和消息的数组
     */
    private function check_and_repair_tables() {
        global $wpdb;
        $results = array(
            'status' => true,
            'messages' => array()
        );

        // 检查历史记录表
        $history_exists = $wpdb->get_var("SHOW TABLES LIKE '" . $this->table_history . "'") === $this->table_history;
        // 检查备份表
        $backup_exists = $wpdb->get_var("SHOW TABLES LIKE '" . $this->table_backup . "'") === $this->table_backup;

        if (!$history_exists || !$backup_exists) {
            // 尝试重新创建表
            self::activate_plugin();
            
            // 再次检查表是否创建成功
            $history_exists = $wpdb->get_var("SHOW TABLES LIKE '" . $this->table_history . "'") === $this->table_history;
            $backup_exists = $wpdb->get_var("SHOW TABLES LIKE '" . $this->table_backup . "'") === $this->table_backup;
            
            if (!$history_exists) {
                $results['status'] = false;
                $results['messages'][] = '历史记录表创建失败，请检查数据库权限。';
            }
            if (!$backup_exists) {
                $results['status'] = false;
                $results['messages'][] = '备份表创建失败，请检查数据库权限。';
            }
        }

        // 检查表结构
        if ($history_exists) {
            $history_columns = $wpdb->get_col("DESCRIBE {$this->table_history}");
            $expected_history_columns = array('id', 'original_content', 'new_content', 'replace_type', 
                                           'affected_count', 'created_at', 'user_id', 'is_regex');
            $missing_columns = array_diff($expected_history_columns, $history_columns);
            
            if (!empty($missing_columns)) {
                $results['status'] = false;
                $results['messages'][] = '历史记录表结构不完整，缺少以下字段：' . implode(', ', $missing_columns);
            }
        }

        if ($backup_exists) {
            $backup_columns = $wpdb->get_col("DESCRIBE {$this->table_backup}");
            $expected_backup_columns = array('id', 'history_id', 'table_name', 'record_id', 
                                          'field_name', 'old_value', 'created_at');
            $missing_columns = array_diff($expected_backup_columns, $backup_columns);
            
            if (!empty($missing_columns)) {
                $results['status'] = false;
                $results['messages'][] = '备份表结构不完整，缺少以下字段：' . implode(', ', $missing_columns);
            }
        }

        return $results;
    }

    /**
     * 检查数据表是否存在
     */
    private function check_tables_exist() {
        global $wpdb;
        
        try {
            $history_exists = $wpdb->get_var("SHOW TABLES LIKE '" . $this->table_history . "'") === $this->table_history;
            $backup_exists = $wpdb->get_var("SHOW TABLES LIKE '" . $this->table_backup . "'") === $this->table_backup;
            
            return [
                'history' => $history_exists,
                'backup' => $backup_exists,
                'all_exist' => $history_exists && $backup_exists
            ];
        } catch (Exception $e) {
            error_log('WPReplace Plugin - Check Tables Error: ' . $e->getMessage());
            return [
                'history' => false,
                'backup' => false,
                'all_exist' => false
            ];
        }
    }

    /**
     * 强制创建数据表
     */
    private function force_create_tables() {
        global $wpdb;
        
        try {
            $wpdb->hide_errors();
            $charset_collate = $wpdb->get_charset_collate();
            
            // 删除可能存在的损坏表
            $wpdb->query("DROP TABLE IF EXISTS {$this->table_history}");
            $wpdb->query("DROP TABLE IF EXISTS {$this->table_backup}");
            
            // 创建历史记录表
            $sql1 = "CREATE TABLE {$this->table_history} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                original_content text NOT NULL,
                new_content text NOT NULL,
                replace_type varchar(50) NOT NULL,
                affected_count int(11) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_id bigint(20) NOT NULL,
                is_regex tinyint(1) NOT NULL DEFAULT 0,
                PRIMARY KEY  (id)
            ) $charset_collate;";
            
            // 创建备份表
            $sql2 = "CREATE TABLE {$this->table_backup} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                history_id bigint(20) NOT NULL,
                table_name varchar(50) NOT NULL,
                record_id bigint(20) NOT NULL,
                field_name varchar(50) NOT NULL,
                old_value text NOT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY history_id (history_id)
            ) $charset_collate;";

            // 记录SQL语句用于调试
            error_log('WPReplace Plugin - Creating history table with SQL: ' . $sql1);
            error_log('WPReplace Plugin - Creating backup table with SQL: ' . $sql2);
            
            // 执行创建表操作
            $result1 = $wpdb->query($sql1);
            if ($result1 === false) {
                throw new Exception('创建历史记录表失败: ' . $wpdb->last_error);
            }
            
            $result2 = $wpdb->query($sql2);
            if ($result2 === false) {
                throw new Exception('创建备份表失败: ' . $wpdb->last_error);
            }
            
            // 验证表是否创建成功
            $tables_exist = $this->check_tables_exist();
            if (!$tables_exist['all_exist']) {
                $missing = [];
                if (!$tables_exist['history']) $missing[] = '历史记录表';
                if (!$tables_exist['backup']) $missing[] = '备份表';
                throw new Exception('表创建后验证失败，缺失: ' . implode(', ', $missing));
            }
            
            error_log('WPReplace Plugin - Tables created successfully');
            return true;
            
        } catch (Exception $e) {
            error_log('WPReplace Plugin - Force Create Tables Error: ' . $e->getMessage());
            if ($wpdb->last_error) {
                error_log('WPReplace Plugin - MySQL Error: ' . $wpdb->last_error);
            }
            return false;
        }
    }

    /**
     * 删除历史记录及相关备份
     */
    private function delete_history($history_id) {
        global $wpdb;
        
        try {
            // 开始事务
            $wpdb->query('START TRANSACTION');
            
            // 删除备份数据
            $wpdb->delete(
                $this->table_backup,
                array('history_id' => $history_id),
                array('%d')
            );
            
            // 删除历史记录
            $wpdb->delete(
                $this->table_history,
                array('id' => $history_id),
                array('%d')
            );
            
            // 提交事务
            $wpdb->query('COMMIT');
            return true;
            
        } catch (Exception $e) {
            // 发生错误时回滚事务
            $wpdb->query('ROLLBACK');
            error_log('WPReplace Plugin - Delete History Error: ' . $e->getMessage());
            return new WP_Error('delete_error', $e->getMessage());
        }
    }
}

// 初始化插件
function wpreplace_init() {
    return WPReplace::get_instance();
}

// 在 WordPress 初始化时启动插件
add_action('init', 'wpreplace_init');
?>
