<?php
// Добавление пользовательского интервала cron
add_filter('cron_schedules', function ($schedules) {
    for ($i = 1; $i <= 60; $i++) {
        $schedules["every_{$i}_minutes"] = [
            'interval' => $i * 60,
            'display' => sprintf(__('Every %d Minutes'), $i),
        ];
    }
    return $schedules;
});

// Мониторинг группы Facebook
function fgp_monitor_facebook_group() {
    if (!get_option('fgp_enable_import')) {
        fgp_log('Import is disabled.');
        return;
    }

    $api_token = get_option('fgp_api_token');
    $group_id = get_option('fgp_group_id');
    $post_limit = absint(get_option('fgp_post_limit', 10)); // Лимит постов (по умолчанию 10)

    if (!$api_token || !$group_id || !$post_limit) {
        fgp_log('Error: Missing API Token, Group ID, or Post Limit.');
        return;
    }

    $fb_api = new FacebookAPI($api_token, $group_id);
    $posts = $fb_api->getGroupPosts($post_limit);

    if (is_wp_error($posts)) {
        fgp_log('Error fetching posts from Facebook: ' . $posts->get_error_message());
        return;
    }

    if (empty($posts)) {
        fgp_log('No new posts found in the Facebook group.');
        return;
    }

    foreach ($posts as $post) {
        // Проверяем, существует ли уже пост на сайте (включая черновики)
        $existing_post = get_posts([
            'meta_key' => 'facebook_post_id',
            'meta_value' => $post['id'],
            'post_type' => 'post',
            'numberposts' => 1,
            'post_status' => ['publish', 'draft'], // Ищем как опубликованные, так и черновики
        ]);

        if (!empty($existing_post)) {
            fgp_log("Post with ID {$post['id']} already exists.");
            continue;
        }

        // Ограничиваем заголовок до 80 символов
        $title = mb_substr($post['message'] ?: __('New Post', 'facebook-group-parser'), 0, 80);

        // Создаем новое объявление как черновик
        $new_post = [
            'post_title' => $title,
            'post_content' => $post['message'] ?: '',
            'post_status' => 'draft', // Черновик
            'post_type' => 'post',
        ];

        $post_id = wp_insert_post($new_post);
        update_post_meta($post_id, 'facebook_post_id', $post['id']);

        // Логирование создания поста
        fgp_log("Created post with ID {$post_id} from Facebook post ID {$post['id']}.");

        // Назначаем подкатегорию на основе ключевых слов
        $categories = fgp_determine_subcategory_from_message($post['message']);
        if (!empty($categories)) {
            wp_set_object_terms($post_id, $categories, 'category');
        } else {
            fgp_log("No category matched for post ID {$post['id']}.");
        }

        // Обработка медиафайлов
        if (!empty($post['image_url'])) {
            // Загружаем главное изображение (из picture)
            $main_image_url = fgp_clean_image_url($post['image_url']);
            if (fgp_is_valid_image_url($main_image_url)) {
                $attachment_id = media_sideload_image($main_image_url, $post_id, '', 'id');
                if (!is_wp_error($attachment_id)) {
                    set_post_thumbnail($post_id, $attachment_id); // Устанавливаем миниатюру
                } else {
                    fgp_log("Error downloading main image for post ID {$post['id']}: " . $attachment_id->get_error_message());
                }
            } else {
                fgp_log("Invalid main image URL for post ID {$post['id']}: {$main_image_url}");
            }
        }

        if (!empty($post['images'])) {
            // Загружаем дополнительные изображения (из attachments)
            foreach ($post['images'] as $index => $image_url) {
                if ($index >= 10) break; // Ограничиваем количество изображений до 10

                $clean_image_url = fgp_clean_image_url($image_url);
                if (fgp_is_valid_image_url($clean_image_url)) {
                    $attachment_id = media_sideload_image($clean_image_url, $post_id, '', 'id');
                    if (!is_wp_error($attachment_id)) {
                        $image_html = sprintf('<img class="wp-image-%d" src="%s" alt="%s">', $attachment_id, $clean_image_url, esc_attr($post['message']));
                        $current_content = get_post_field('post_content', $post_id);
                        wp_update_post([
                            'ID' => $post_id,
                            'post_content' => $image_html . "\n\n" . $current_content,
                        ]);
                    } else {
                        fgp_log("Error downloading additional image for post ID {$post['id']}: " . $attachment_id->get_error_message());
                    }
                } else {
                    fgp_log("Invalid additional image URL for post ID {$post['id']}: {$clean_image_url}");
                }
            }
        }

        if (!empty($post['video_url'])) {
            // Если есть видео, добавляем его вместо изображений
            $video_html = sprintf('<video controls><source src="%s" type="video/mp4"></video>', $post['video_url']);
            $current_content = get_post_field('post_content', $post_id);
            wp_update_post([
                'ID' => $post_id,
                'post_content' => $video_html . "\n\n" . $current_content,
            ]);
        }

        // Импорт комментариев (если включено)
        if (get_option('fgp_import_comments')) {
            if (!empty($post['comments'])) {
                foreach ($post['comments'] as $comment) {
                    $comment_data = [
                        'comment_post_ID' => $post_id,
                        'comment_author' => $comment['from']['name'] ?? 'Unknown',
                        'comment_author_email' => 'no-reply@example.com',
                        'comment_content' => $comment['message'] ?? '',
                        'comment_date' => date('Y-m-d H:i:s', strtotime($comment['created_time'])),
                        'comment_approved' => 1, // Одобренный комментарий
                    ];
                    wp_insert_comment($comment_data);
                }
            } else {
                fgp_log("No comments found for post ID {$post['id']}.");
            }
        }
    }
}

// Функция очистки URL изображений
function fgp_clean_image_url($url) {
    // Удаляем все параметры после "?" в URL
    $parsed_url = parse_url($url);
    return $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'];
}

// Проверка валидности URL изображения
function fgp_is_valid_image_url($url) {
    $response = wp_remote_head($url);
    if (is_wp_error($response)) {
        fgp_log("Error checking image URL validity: " . $response->get_error_message());
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $content_type = wp_remote_retrieve_header($response, 'content-type');
    return $status_code === 200 && strpos($content_type, 'image/jpeg') !== false;
}

// Планирование задачи cron
function fgp_schedule_cron() {
    $interval = get_option('fgp_cron_interval', 5) * 60;

    if (!wp_next_scheduled('fgp_monitor_facebook_group')) {
        wp_schedule_event(time(), "every_{$interval}_minutes", 'fgp_monitor_facebook_group');
    } else {
        wp_clear_scheduled_hook('fgp_monitor_facebook_group');
        wp_schedule_event(time(), "every_{$interval}_minutes", 'fgp_monitor_facebook_group');
    }
}
add_action('init', 'fgp_schedule_cron');
add_action('fgp_monitor_facebook_group', 'fgp_monitor_facebook_group');

// Определение подкатегории на основе ключевых слов
function fgp_determine_subcategory_from_message($message) {
    $keywords = get_option('fgp_category_keywords', []);
    foreach ($keywords as $category => $subcategories) {
        foreach ($subcategories as $subcategory => $words) {
            foreach ($words as $word) {
                if (stripos($message, $word) !== false) {
                    return [$subcategory]; // Возвращаем подкатегорию
                }
            }
        }
    }
    return [];
}

// Сохранение ключевых слов для категорий
function fgp_save_category_keywords() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['fgp_category_keywords'])) {
        $keywords = [];
        foreach ($_POST['fgp_category_keywords'] as $category => $subcategories) {
            foreach ($subcategories as $subcategory => $data) {
                if (!empty($data['keywords'])) {
                    $keywords[$data['category']][$data['subcategory']] = array_map('trim', explode(',', $data['keywords']));
                }
            }
        }
        update_option('fgp_category_keywords', $keywords);
    }
}
add_action('admin_init', 'fgp_save_category_keywords');

// Вывод таблицы для настройки ключевых слов
function fgp_render_keyword_settings_table() {
    $keywords = get_option('fgp_category_keywords', []);
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Category', 'facebook-group-parser'); ?></th>
                <th><?php _e('Subcategory', 'facebook-group-parser'); ?></th>
                <th><?php _e('Keywords', 'facebook-group-parser'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($keywords)): ?>
                <?php foreach ($keywords as $category => $subcategories): ?>
                    <?php foreach ($subcategories as $subcategory => $words): ?>
                        <tr>
                            <td><input type="text" name="fgp_category_keywords[<?php echo esc_attr($category); ?>][<?php echo esc_attr($subcategory); ?>][category]" value="<?php echo esc_attr($category); ?>" readonly /></td>
                            <td><input type="text" name="fgp_category_keywords[<?php echo esc_attr($category); ?>][<?php echo esc_attr($subcategory); ?>][subcategory]" value="<?php echo esc_attr($subcategory); ?>" /></td>
                            <td><input type="text" name="fgp_category_keywords[<?php echo esc_attr($category); ?>][<?php echo esc_attr($subcategory); ?>][keywords]" value="<?php echo esc_attr(implode(',', $words)); ?>" placeholder="phone, smartphone" /></td>
                            <td><button type="button" class="fgp-remove-row button"><?php _e('Remove', 'facebook-group-parser'); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <tr class="fgp-new-row-template" style="display: none;">
                <td><input type="text" name="fgp_category_keywords[new][new][category]" value="New Category" /></td>
                <td><input type="text" name="fgp_category_keywords[new][new][subcategory]" value="New Subcategory" /></td>
                <td><input type="text" name="fgp_category_keywords[new][new][keywords]" placeholder="phone, smartphone" /></td>
                <td><button type="button" class="fgp-remove-row button"><?php _e('Remove', 'facebook-group-parser'); ?></button></td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">
                    <button type="button" class="fgp-add-row button"><?php _e('Add New Category', 'facebook-group-parser'); ?></button>
                </td>
            </tr>
        </tfoot>
    </table>
    <?php
}