<?php
/**
 * Plugin Name: ALT Auto & Fixer
 * Description: Автоматически ставит ALT у новых изображений, массово заполняет ALT в медиатеке, чинит ALT в контенте (Гутенберг и классический), и гарантирует ALT на рендере.
 * Version: 1.2.1
 * Author: 7ON
 */

if ( ! defined('ABSPATH') ) exit;

/** ===================== Helpers ===================== */
function tfc_get_alt_by_attachment( $att_id ){
    if ( ! $att_id ) return '';
    $alt = get_post_meta($att_id, '_wp_attachment_image_alt', true);
    if ( is_string($alt) && trim($alt) !== '' ) return sanitize_text_field($alt);

    $title = get_the_title($att_id);
    if ( is_string($title) && trim($title) !== '' ) return sanitize_text_field($title);

    $file = get_attached_file($att_id);
    if ( $file ) {
        $base = wp_basename($file);
        $base = preg_replace('/\.[^.]+$/', '', $base);
        $base = str_replace(['-', '_'], ' ', $base);
        return sanitize_text_field( ucwords( preg_replace('/\s+/', ' ', trim($base)) ) );
    }
    return '';
}

/** ===================== 1) Новые загрузки ===================== */
add_action('add_attachment', function($post_id){
    if ( ! wp_attachment_is_image($post_id) ) return;
    $current = get_post_meta($post_id, '_wp_attachment_image_alt', true);
    if ( is_string($current) && trim($current) !== '' ) return;
    $alt = tfc_get_alt_by_attachment($post_id);
    if ( $alt !== '' ) update_post_meta($post_id, '_wp_attachment_image_alt', $alt);
});

add_action('save_post_attachment', function($post_id){
    if ( ! wp_attachment_is_image($post_id) ) return;
    $current = get_post_meta($post_id, '_wp_attachment_image_alt', true);
    if ( is_string($current) && trim($current) !== '' ) return;
    $alt = tfc_get_alt_by_attachment($post_id);
    if ( $alt !== '' ) update_post_meta($post_id, '_wp_attachment_image_alt', $alt);
}, 10, 1);

/** ===================== 2) Гарантия ALT на рендере ===================== */
/* Gutenberg core/image */
add_filter('render_block', function($content, $block){
    if ( empty($block['blockName']) || $block['blockName'] !== 'core/image' ) return $content;
    $att_id = isset($block['attrs']['id']) ? (int)$block['attrs']['id'] : 0;
    if ( ! $att_id ) return $content;

    $alt = tfc_get_alt_by_attachment($att_id);
    if ( $alt === '' ) return $content;

    // Если alt уже непустой — не трогаем
    if ( preg_match('~\balt\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $content, $m) ) {
        $val = '';
        if (isset($m[2]) && $m[2] !== '') { $val = $m[2]; }
        elseif (isset($m[3]) && $m[3] !== '') { $val = $m[3]; }
        elseif (isset($m[4])) { $val = $m[4]; }
        if ( trim($val) !== '' ) return $content;

        // alt пустой: alt="" или alt='' → заменим
        return preg_replace("~\balt\s*=\s*(\"\"|'')~", ' alt="'.esc_attr($alt).'"', $content, 1);
    }

    // alt отсутствует — добавим в первую <img>
    return preg_replace('~<img\b~i', '<img alt="'.esc_attr($alt).'"', $content, 1);
}, 10, 2);

/* Классический контент: <img class="wp-image-123"> */
add_filter('the_content', function($html){
    $new = preg_replace_callback('~<img\b[^>]*>~i', function($m){
        $tag = $m[0];

        // Уже есть непустой alt?
        if ( preg_match('~\balt\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $tag, $mm) ) {
            $val = '';
            if (isset($mm[2]) && $mm[2] !== '') { $val = $mm[2]; }
            elseif (isset($mm[3]) && $mm[3] !== '') { $val = $mm[3]; }
            elseif (isset($mm[4])) { $val = $mm[4]; }
            if ( trim(html_entity_decode($val)) !== '' ) return $tag;
        }

        // Пытаемся извлечь ID вложения из класса wp-image-XXX
        if ( preg_match('~wp-image-(\d+)~', $tag, $idm) ) {
            $alt = tfc_get_alt_by_attachment( (int)$idm[1] );
            if ( $alt !== '' ) {
                // alt="" или alt='' → заполним
                if ( preg_match("~\balt\s*=\s*(\"\"|'')~", $tag) ) {
                    return preg_replace("~\balt\s*=\s*(\"\"|'')~", ' alt="'.esc_attr($alt).'"', $tag, 1);
                }
                // атрибут alt отсутствует — вставим перед '>'
                if ( stripos($tag, ' alt=') === false ) {
                    return preg_replace('~>$~', ' alt="'.esc_attr($alt).'">', $tag, 1);
                }
            }
        }

        return $tag;
    }, $html);

    return $new === null ? $html : $new;
}, 12);

/** ===================== 3) Инструменты: массовые операции ===================== */
add_action('admin_menu', function(){
    add_management_page(
        'Проставить ALT из Title',
        'Проставить ALT из Title',
        'manage_options',
        'tfc-fill-alts',
        'tfc_fill_alts_page'
    );
});

function tfc_fill_alts_page(){
    if ( ! current_user_can('manage_options') ) { wp_die('Forbidden'); }

    $done = null; $updated = 0; $skipped = 0;
    $content_fixed = null; $posts_updated = 0; $posts_skipped = 0; $imgs_replaced = 0;

    // 3a) Медиатека: проставить пустые ALT
    if ( isset($_POST['tfc_fill_alts_run']) && check_admin_referer('tfc_fill_alts_nonce') ) {
        $per_page = 500; $paged = 1;
        while ( true ) {
            $q = new WP_Query([
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'post_status'    => 'inherit',
                'fields'         => 'ids',
                'posts_per_page' => $per_page,
                'paged'          => $paged,
            ]);
            if ( ! $q->have_posts() ) break;

            foreach ( $q->posts as $id ) {
                $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
                if ( is_string($alt) && trim($alt) !== '' ) { $skipped++; continue; }

                $value = tfc_get_alt_by_attachment($id);
                if ( $value !== '' ) {
                    update_post_meta($id, '_wp_attachment_image_alt', $value);
                    $updated++;
                } else {
                    $skipped++;
                }
            }
            $paged++;
        }
        $done = true;
    }

    // 3b) Контент: вписать отсутствующие ALT в HTML постов/страниц
    if ( isset($_POST['tfc_fix_content_run']) && check_admin_referer('tfc_fill_alts_nonce') ) {
        $content_fixed = true;
        $public_types = get_post_types(['public' => true], 'names');
        $per_page = 100; $paged = 1;

        while ( true ) {
            $q = new WP_Query([
                'post_type'      => array_values($public_types),
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => $per_page,
                'paged'          => $paged,
            ]);
            if ( ! $q->have_posts() ) break;

            foreach ( $q->posts as $pid ) {
                $post = get_post($pid);
                if ( ! $post ) { $posts_skipped++; continue; }

                list($new_content, $count, $changed) = tfc_fix_alts_in_post_content( $post->post_content );
                if ( $changed ) {
                    wp_update_post(['ID' => $pid, 'post_content' => $new_content]);
                    $posts_updated++;
                    $imgs_replaced += $count;
                } else {
                    $posts_skipped++;
                }
            }
            $paged++;
        }
    }

    ?>
    <div class="wrap">
        <h1>Проставить ALT из Title</h1>
        <p>• Новые изображения получают ALT автоматически при загрузке.<br>• Кнопка ниже заполнит пустые ALT в медиабиблиотеке из заголовков/имён файлов.<br>• Вторая кнопка обновит HTML контента постов/страниц, добавив отсутствующие ALT.</p>

        <?php if ($done): ?>
            <div class="notice notice-success"><p><strong>Медиабиблиотека:</strong> обновлено: <strong><?php echo (int)$updated; ?></strong>, пропущено: <strong><?php echo (int)$skipped; ?></strong>.</p></div>
        <?php endif; ?>
        <form method="post" style="margin-bottom:16px;">
            <?php wp_nonce_field('tfc_fill_alts_nonce'); ?>
            <p><button class="button button-primary" name="tfc_fill_alts_run" value="1">Заполнить ALT в медиабиблиотеке</button></p>
        </form>

        <hr>
        <h2>Починить ALT в контенте (Гутенберг/классический)</h2>
        <p>Добавит отсутствующие <code>alt</code> в HTML постов/страниц на основе заголовков вложений. Уже заполненные alt не трогаем.</p>
        <?php if ($content_fixed): ?>
            <div class="notice notice-success"><p><strong>Контент:</strong> обновлено записей: <strong><?php echo (int)$posts_updated; ?></strong>; пропущено: <strong><?php echo (int)$posts_skipped; ?></strong>. Заменено IMG-тегов: <strong><?php echo (int)$imgs_replaced; ?></strong>.</p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('tfc_fill_alts_nonce'); ?>
            <p><button class="button" name="tfc_fix_content_run" value="1">Починить ALT в контенте</button></p>
        </form>

        <p style="opacity:.7">Существующие непустые ALT нигде не перезаписываются.</p>
    </div>
    <?php
}

/** ===================== 4) Утилита для правки контента ===================== */
function tfc_fix_alts_in_post_content( $content ){
    $imgs = 0; $changed = false;

    $new = preg_replace_callback('~<img\b[^>]*>~i', function($m) use (&$imgs, &$changed){
        $tag = $m[0];

        // Если уже есть непустой alt — оставляем
        if ( preg_match('~\balt\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $tag, $mm) ) {
            $val = '';
            if (isset($mm[2]) && $mm[2] !== '') { $val = $mm[2]; }
            elseif (isset($mm[3]) && $mm[3] !== '') { $val = $mm[3]; }
            elseif (isset($mm[4])) { $val = $mm[4]; }
            if ( trim(html_entity_decode($val)) !== '' ) return $tag;
        }

        if ( preg_match('~wp-image-(\d+)~', $tag, $idm) ) {
            $alt = tfc_get_alt_by_attachment( (int)$idm[1] );
            if ( $alt !== '' ) {
                // alt="" или alt='' → заполним
                if ( preg_match("~\balt\s*=\s*(\"\"|'')~", $tag) ) {
                    $tag = preg_replace("~\balt\s*=\s*(\"\"|'')~", ' alt="'.esc_attr($alt).'"', $tag, 1);
                } elseif ( stripos($tag, ' alt=') === false ) {
                    // атрибут alt отсутствует — добавим
                    $tag = preg_replace('~>$~', ' alt="'.esc_attr($alt).'">', $tag, 1);
                }
                $imgs++; $changed = true;
            }
        }

        return $tag;
    }, $content);

    return [$new === null ? $content : $new, $imgs, $changed];
}
