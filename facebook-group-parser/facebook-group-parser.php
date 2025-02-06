<?php
/**
 * Plugin Name: Facebook Group Parser
 * Description: Парсер контента из группы Facebook для импорта на сайт.
 * Version: 1.0
 * Author: Alexanlr Vitko
 */

// Запрет прямого вызова файла
if (!defined('ABSPATH')) {
    exit;
}

// Подключение необходимых классов и функций
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/facebook-api.php';

// Инициализация плагина
function fgp_init() {
    // Регистрация настроек плагина
    register_setting('fgp_settings', 'fgp_api_token');
    register_setting('fgp_settings', 'fgp_group_id');
    register_setting('fgp_settings', 'fgp_enable_import');
    register_setting('fgp_settings', 'fgp_import_comments');
    register_setting('fgp_settings', 'fgp_cron_interval');
    register_setting('fgp_settings', 'fgp_post_limit'); // Лимит постов за один раз

    // Добавление страницы настроек
    add_action('admin_menu', 'fgp_add_admin_page');
}
add_action('init', 'fgp_init');

// Добавление страницы настроек в админке
function fgp_add_admin_page() {
    add_options_page(
        'Настройки Facebook Group Parser',
        'Facebook Group Parser',
        'manage_options',
        'facebook-group-parser',
        'fgp_render_admin_page'
    );
}

// Вывод страницы настроек
function fgp_render_admin_page() {
    ?>
    <div class="wrap">
        <h1>Настройки Facebook Group Parser</h1>
        <form method="post" action="options.php">
            <?php settings_fields('fgp_settings'); ?>
            <?php do_settings_sections('facebook-group-parser'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Access Token:</th>
                    <td>
                        <input type="text" name="fgp_api_token" value="<?php echo esc_attr(get_option('fgp_api_token')); ?>" />
                        <p class="description"><?php _e('Токен доступа для работы с Facebook API.', 'facebook-group-parser'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Group ID:</th>
                    <td>
                        <input type="text" name="fgp_group_id" value="<?php echo esc_attr(get_option('fgp_group_id')); ?>" />
                        <p class="description"><?php _e('ID группы Facebook, откуда будут импортироваться посты.', 'facebook-group-parser'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Вкл/Выкл Import:</th>
                    <td>
                        <input type="checkbox" name="fgp_enable_import" value="1" <?php checked(get_option('fgp_enable_import'), '1'); ?> />
                        <p class="description"><?php _e('Включите эту опцию, чтобы начать импорт постов с Facebook.', 'facebook-group-parser'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Импортировать комментарии?:</th>
                    <td>
                        <input type="checkbox" name="fgp_import_comments" value="1" <?php checked(get_option('fgp_import_comments'), '1'); ?> />
                        <p class="description"><?php _e('Если включено, комментарии к постам также будут импортированы.', 'facebook-group-parser'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Задание Cron (минуты):</th>
                    <td>
                        <input type="number" name="fgp_cron_interval" value="<?php echo esc_attr(get_option('fgp_cron_interval', 5)); ?>" min="1" />
                        <p class="description"><?php _e('Укажите интервал проверки группы Facebook в минутах (например, 5 минут).', 'facebook-group-parser'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Лимит постов за один раз:</th>
                    <td>
                        <input type="number" name="fgp_post_limit" value="<?php echo esc_attr(get_option('fgp_post_limit', 10)); ?>" min="1" max="30" />
                        <p class="description"><?php _e('Укажите максимальное количество постов, которое будет импортироваться за один запуск cron (от 1 до 30).', 'facebook-group-parser'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <!-- Добавляем таблицу для настройки ключевых слов -->
        <h2><?php _e('Keyword Settings', 'facebook-group-parser'); ?></h2>
        <?php if (function_exists('fgp_render_keyword_settings_table')) { ?>
            <?php fgp_render_keyword_settings_table(); ?>
        <?php } else { ?>
            <p><?php _e('Keyword settings table is not available. Please ensure all plugin files are properly included.', 'facebook-group-parser'); ?></p>
        <?php } ?>
    </div>
    <?php
}

// Логирование ошибок
function fgp_log($message) {
    if (!defined('FGP_LOG_FILE')) {
        define('FGP_LOG_FILE', WP_CONTENT_DIR . '/fgp-log.txt');
    }
    file_put_contents(FGP_LOG_FILE, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

// Добавление JavaScript для управления строками
function fgp_enqueue_scripts() {
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tableBody = document.querySelector('.wp-list-table tbody');
            const newRowTemplate = document.querySelector('.fgp-new-row-template');

            if (!newRowTemplate) return;

            // Добавление новой строки
            document.querySelector('.fgp-add-row').addEventListener('click', function () {
                const newRow = newRowTemplate.cloneNode(true);
                newRow.style.display = 'table-row';
                tableBody.appendChild(newRow);
            });

            // Удаление строки
            tableBody.addEventListener('click', function (event) {
                if (event.target.classList.contains('fgp-remove-row')) {
                    event.target.closest('tr').remove();
                }
            });
        });
    </script>
    <?php
}
add_action('admin_footer', 'fgp_enqueue_scripts');