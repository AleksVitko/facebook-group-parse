<?php
class FacebookAPI {
    private $api_token;
    private $group_id;

    public function __construct($api_token, $group_id) {
        $this->api_token = $api_token;
        $this->group_id = $group_id;
    }

    // Получение постов из группы с ограничением по количеству
    public function getGroupPosts($limit = 10) {
        if (!$this->api_token) {
            return new WP_Error('missing_api_token', __('Access Token is required.', 'facebook-group-parser'));
        }

        $url = "https://graph.facebook.com/v16.0/{$this->group_id}/feed?access_token={$this->api_token}&fields=id,message,picture,attachments{media},comments{message,from,created_time},created_time&limit={$limit}";

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['error'])) {
            return new WP_Error('facebook_api_error', $data['error']['message']);
        }

        $posts = $data['data'] ?? [];

        foreach ($posts as &$post) {
            $post['image_url'] = $post['picture'] ?? ''; // Главное изображение
            $post['images'] = []; // Дополнительные изображения
            $post['video_url'] = ''; // Поле для видео
            $post['comments'] = []; // Комментарии

            if (!empty($post['attachments']['data'])) {
                foreach ($post['attachments']['data'] as $attachment) {
                    if (!empty($attachment['media']['image']['src'])) {
                        $post['images'][] = $attachment['media']['image']['src'];
                    } elseif (!empty($attachment['media']['playable_url'])) {
                        $post['video_url'] = $attachment['media']['playable_url']; // URL видео
                    }
                }
            }

            if (!empty($post['comments']['data'])) {
                $post['comments'] = $post['comments']['data'];
            }
        }

        return $posts;
    }
}