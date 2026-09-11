<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin-triggered Ready Candidate -> source enrichment -> AI editorial draft.
 * Never publishes posts automatically.
 */
class Sektorel_Content_AI_Draft_Processor {

    const NONCE_ACTION = 'sektorel_content_ai_draft';
    const BATCH_SIZE = 5;
    const DAILY_LIMIT_DEFAULT = 20;
    const MAX_OUTPUT_TOKENS = 2200;
    const REQUEST_TIMEOUT = 45;

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'wp_ajax_sektorel_content_ai_draft_batch', array( __CLASS__, 'ajax_process_batch' ) );
    }

    public static function nonce() {
        return wp_create_nonce( self::NONCE_ACTION );
    }

    public static function is_enabled() {
        return '' !== self::api_key();
    }

    public static function ajax_process_batch() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
        if ( ! self::is_enabled() ) {
            wp_send_json_error( array( 'message' => 'OpenAI API anahtarı tanımlı değil.' ) );
        }

        $remaining_daily = max( 0, self::daily_limit() - self::daily_processed_count() );
        if ( $remaining_daily <= 0 ) {
            wp_send_json_success( array(
                'processed' => 0,
                'drafted'   => 0,
                'errors'    => 0,
                'skipped'   => 0,
                'done'      => true,
                'daily_limit_reached' => true,
                'messages'  => array( 'Günlük AI draft limiti doldu.' ),
            ) );
        }

        global $wpdb;
        $table = Sektorel_Content_Candidates::table_name();
        $limit = min( self::BATCH_SIZE, $remaining_daily );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = %s
                   AND ai_status IN ( 'pending', 'error' )
                   AND draft_post_id = 0
                 ORDER BY published_at DESC, id ASC
                 LIMIT %d",
                Sektorel_Content_Candidates::STATUS_READY,
                $limit
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            wp_send_json_success( array(
                'processed' => 0,
                'drafted'   => 0,
                'errors'    => 0,
                'skipped'   => 0,
                'done'      => true,
                'messages'  => array( 'Taslak üretilecek ready candidate kalmadı.' ),
            ) );
        }

        $drafted = 0;
        $errors = 0;
        $skipped = 0;
        $messages = array();

        foreach ( $rows as $row ) {
            $candidate_id = absint( $row['id'] ?? 0 );
            if ( ! $candidate_id ) {
                continue;
            }

            self::mark_ai_status( $candidate_id, 'processing' );

            $enriched = Sektorel_Content_Detail_Extractor::enrich_candidate( $row );
            if ( is_wp_error( $enriched ) ) {
                $errors++;
                self::mark_ai_error( $candidate_id, $enriched->get_error_code(), $enriched->get_error_message() );
                $messages[] = 'Hata #' . $candidate_id . ': ' . $enriched->get_error_message();
                continue;
            }

            $existing_post = self::find_existing_draft( $candidate_id );
            if ( $existing_post ) {
                $skipped++;
                self::mark_processed( $candidate_id, $existing_post, array(), array(), 'existing_draft' );
                $messages[] = '#' . $candidate_id . ' zaten draft #' . $existing_post . ' ile eşleşti.';
                continue;
            }

            $result = self::request_editorial_draft( $enriched );
            if ( is_wp_error( $result ) ) {
                $errors++;
                self::mark_ai_error( $candidate_id, $result->get_error_code(), $result->get_error_message() );
                $messages[] = 'Hata #' . $candidate_id . ': ' . $result->get_error_message();
                continue;
            }

            $post_id = self::create_draft_post( $enriched, $result['article'] );
            if ( is_wp_error( $post_id ) ) {
                $errors++;
                self::mark_ai_error( $candidate_id, $post_id->get_error_code(), $post_id->get_error_message() );
                $messages[] = 'Hata #' . $candidate_id . ': ' . $post_id->get_error_message();
                continue;
            }

            self::mark_processed( $candidate_id, $post_id, $result['usage'], $result['article'], 'created' );
            $drafted++;
            $messages[] = sprintf( '#%d → draft #%d oluşturuldu: %s', $candidate_id, $post_id, get_the_title( $post_id ) );
        }

        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE status = %s AND ai_status IN ( 'pending', 'error' ) AND draft_post_id = 0",
                Sektorel_Content_Candidates::STATUS_READY
            )
        );

        wp_send_json_success( array(
            'processed' => count( $rows ),
            'drafted'   => $drafted,
            'errors'    => $errors,
            'skipped'   => $skipped,
            'remaining' => $remaining,
            'done'      => 0 === $remaining || self::daily_processed_count() >= self::daily_limit(),
            'daily_limit' => self::daily_limit(),
            'daily_used'  => self::daily_processed_count(),
            'messages'  => $messages,
        ) );
    }

    private static function request_editorial_draft( $row ) {
        $key = self::api_key();
        if ( ! $key ) {
            return new WP_Error( 'content_ai_disabled', 'OpenAI API anahtarı tanımlı değil.' );
        }

        $source_text = self::trim_text( $row['extracted_text'] ?? '', 24000 );
        if ( mb_strlen( $source_text ) < 80 ) {
            return new WP_Error( 'content_ai_source_too_short', 'AI için yeterli kaynak metni yok.' );
        }

        $normalized = self::decode_json_array( $row['normalized_payload'] ?? '' );
        $categories = array_values( array_filter( array_map( 'sanitize_title', (array) ( $normalized['suggested_category_slugs'] ?? array() ) ) ) );
        $source_url = trim( (string) ( ! empty( $row['canonical_url'] ) ? $row['canonical_url'] : ( $row['source_url'] ?? '' ) ) );

        $source = array(
            'candidate_id' => absint( $row['id'] ?? 0 ),
            'source_key'   => sanitize_key( $row['source_key'] ?? '' ),
            'source_url'   => esc_url_raw( $source_url ),
            'source_title' => sanitize_text_field( $row['title'] ?? '' ),
            'published_at' => sanitize_text_field( $row['published_at'] ?? '' ),
            'allowed_category_slugs' => $categories,
            'source_text'  => $source_text,
        );

        $prompt = "Sektörel Ajanda için Türkçe ekonomi/iş dünyası editörüsün. YALNIZ verilen resmi kaynak metnindeki doğrulanabilir bilgileri kullan. Kaynakta olmayan kişi, rakam, tarih, kurum, alıntı, bağlam veya neden-sonuç ilişkisi uydurma. Belirsiz bilgiyi kesinleştirme. Kaynak metnini kopyalama; anlamı koruyarak özgün, profesyonel haber dilinde yeniden yaz. Sansasyonel dil kullanma. Başlık kısa, açıklayıcı ve clickbait olmayan bir haber başlığı olsun. İçerik HTML olsun ve yalnız p, h2, ul, li, strong etiketlerini kullan. Kaynak URL'sini içerik gövdesine ekleme; sistem meta olarak saklayacak. category_slug yalnız allowed_category_slugs listesinden bir değer olmalı; liste boşsa boş string döndür. tags en fazla 8 kısa Türkçe etiket olsun. seo_description 140-160 karakter aralığında doğal Türkçe meta açıklaması olsun. Yalnız geçerli JSON döndür; markdown/code fence kullanma. JSON şeması: {\"title\":\"...\",\"excerpt\":\"...\",\"content_html\":\"...\",\"category_slug\":\"...\",\"tags\":[\"...\"],\"seo_description\":\"...\"}.\n\nKAYNAK VERİ:\n" . wp_json_encode( $source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            array(
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'model'             => self::model(),
                    'input'             => $prompt,
                    'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
                ) ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'content_ai_request_error', $response->get_error_message() );
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        $json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            $message = 'OpenAI isteği başarısız oldu (HTTP ' . $code . ').';
            if ( is_array( $json ) && ! empty( $json['error']['message'] ) ) {
                $message .= ' ' . sanitize_text_field( $json['error']['message'] );
            }
            return new WP_Error( 'content_ai_http_error', $message );
        }

        $text = self::response_text( $json );
        $text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( (string) $text ) );
        $article = json_decode( $text, true );
        if ( ! is_array( $article ) ) {
            return new WP_Error( 'content_ai_invalid_json', 'AI geçerli editoryal JSON döndürmedi.' );
        }

        $validated = self::validate_article( $article, $categories );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $usage = is_array( $json['usage'] ?? null ) ? $json['usage'] : array();
        return array(
            'article' => $validated,
            'usage'   => array(
                'input_tokens'  => absint( $usage['input_tokens'] ?? 0 ),
                'output_tokens' => absint( $usage['output_tokens'] ?? 0 ),
            ),
        );
    }

    private static function validate_article( $article, $allowed_categories ) {
        $title = sanitize_text_field( $article['title'] ?? '' );
        $excerpt = sanitize_textarea_field( $article['excerpt'] ?? '' );
        $content = wp_kses_post( (string) ( $article['content_html'] ?? '' ) );
        $category = sanitize_title( $article['category_slug'] ?? '' );
        $seo_description = sanitize_text_field( $article['seo_description'] ?? '' );
        $tags = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $article['tags'] ?? array() ) ) ) ) );
        $tags = array_slice( $tags, 0, 8 );

        if ( mb_strlen( $title ) < 10 || mb_strlen( wp_strip_all_tags( $content ) ) < 180 ) {
            return new WP_Error( 'content_ai_output_too_short', 'AI çıktısı başlık veya haber gövdesi açısından yetersiz.' );
        }
        if ( $category && ! in_array( $category, (array) $allowed_categories, true ) ) {
            return new WP_Error( 'content_ai_invalid_category', 'AI izin verilen kategori dışında seçim yaptı.' );
        }
        if ( $category && ! get_term_by( 'slug', $category, 'category' ) ) {
            return new WP_Error( 'content_ai_category_missing', 'AI kategorisi WordPress üzerinde bulunamadı.' );
        }

        return array(
            'title'           => mb_substr( $title, 0, 220 ),
            'excerpt'         => mb_substr( $excerpt, 0, 500 ),
            'content_html'    => $content,
            'category_slug'   => $category,
            'tags'            => $tags,
            'seo_description' => mb_substr( $seo_description, 0, 170 ),
        );
    }

    private static function create_draft_post( $row, $article ) {
        $candidate_id = absint( $row['id'] ?? 0 );
        if ( ! $candidate_id ) {
            return new WP_Error( 'content_ai_invalid_candidate', 'Candidate ID bulunamadı.' );
        }

        $existing = self::find_existing_draft( $candidate_id );
        if ( $existing ) {
            return $existing;
        }

        $postarr = array(
            'post_type'    => 'post',
            'post_status'  => 'draft',
            'post_title'   => $article['title'],
            'post_excerpt' => $article['excerpt'],
            'post_content' => $article['content_html'],
        );

        $post_id = wp_insert_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( ! empty( $article['category_slug'] ) ) {
            $term = get_term_by( 'slug', $article['category_slug'], 'category' );
            if ( $term && ! is_wp_error( $term ) ) {
                wp_set_post_categories( $post_id, array( (int) $term->term_id ), false );
            }
        }
        if ( ! empty( $article['tags'] ) ) {
            wp_set_post_tags( $post_id, $article['tags'], false );
        }

        $source_url = trim( (string) ( ! empty( $row['canonical_url'] ) ? $row['canonical_url'] : ( $row['source_url'] ?? '' ) ) );
        update_post_meta( $post_id, '_sektorel_content_candidate_id', $candidate_id );
        update_post_meta( $post_id, '_sektorel_content_source_key', sanitize_key( $row['source_key'] ?? '' ) );
        update_post_meta( $post_id, '_sektorel_content_source_url', esc_url_raw( $source_url ) );
        update_post_meta( $post_id, '_sektorel_content_source_title', sanitize_text_field( $row['title'] ?? '' ) );
        update_post_meta( $post_id, '_sektorel_content_published_at', sanitize_text_field( $row['published_at'] ?? '' ) );
        update_post_meta( $post_id, '_sektorel_content_ai_model', self::model() );
        update_post_meta( $post_id, '_sektorel_content_generated_at', current_time( 'mysql', true ) );
        update_post_meta( $post_id, '_sektorel_content_evidence', self::decode_json_array( $row['evidence_json'] ?? '' ) );
        if ( ! empty( $article['seo_description'] ) ) {
            update_post_meta( $post_id, 'rank_math_description', $article['seo_description'] );
        }

        return absint( $post_id );
    }

    private static function find_existing_draft( $candidate_id ) {
        $posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => array( 'draft', 'pending', 'publish', 'future', 'private' ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => '_sektorel_content_candidate_id',
            'meta_value'     => absint( $candidate_id ),
        ) );
        return $posts ? absint( $posts[0] ) : 0;
    }

    private static function mark_processed( $candidate_id, $post_id, $usage, $article, $mode ) {
        global $wpdb;
        $candidate = Sektorel_Content_Candidates::get( $candidate_id );
        $evidence = self::decode_json_array( $candidate['evidence_json'] ?? '' );
        $evidence['ai_draft'] = array(
            'status'        => 'created',
            'mode'          => sanitize_key( $mode ),
            'post_id'       => absint( $post_id ),
            'model'         => self::model(),
            'created_at'    => gmdate( 'c' ),
            'input_tokens'  => absint( $usage['input_tokens'] ?? 0 ),
            'output_tokens' => absint( $usage['output_tokens'] ?? 0 ),
        );
        if ( ! empty( $article['category_slug'] ) ) {
            $evidence['ai_draft']['category_slug'] = sanitize_title( $article['category_slug'] );
        }

        $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'status'           => Sektorel_Content_Candidates::STATUS_PROCESSED,
                'ai_status'        => 'done',
                'ai_model'         => self::model(),
                'ai_input_tokens'  => absint( $usage['input_tokens'] ?? 0 ),
                'ai_output_tokens' => absint( $usage['output_tokens'] ?? 0 ),
                'draft_post_id'    => absint( $post_id ),
                'evidence_json'    => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'error_code'       => '',
                'error_message'    => '',
                'processed_at'     => current_time( 'mysql', true ),
                'updated_at'       => current_time( 'mysql', true ),
            ),
            array( 'id' => absint( $candidate_id ) ),
            array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    private static function mark_ai_status( $candidate_id, $status ) {
        global $wpdb;
        $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'ai_status' => sanitize_key( $status ),
                'updated_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => absint( $candidate_id ) ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    private static function mark_ai_error( $candidate_id, $code, $message ) {
        global $wpdb;
        $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'ai_status'     => 'error',
                'error_code'    => sanitize_key( $code ),
                'error_message' => sanitize_textarea_field( $message ),
                'updated_at'    => current_time( 'mysql', true ),
            ),
            array( 'id' => absint( $candidate_id ) ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    private static function response_text( $json ) {
        if ( ! empty( $json['output_text'] ) && is_string( $json['output_text'] ) ) {
            return $json['output_text'];
        }
        $text = '';
        foreach ( isset( $json['output'] ) && is_array( $json['output'] ) ? $json['output'] : array() as $out ) {
            foreach ( isset( $out['content'] ) && is_array( $out['content'] ) ? $out['content'] : array() as $content ) {
                if ( ! empty( $content['text'] ) && is_string( $content['text'] ) ) {
                    $text .= $content['text'];
                }
            }
        }
        return $text;
    }

    private static function daily_processed_count() {
        global $wpdb;
        $table = Sektorel_Content_Candidates::table_name();
        $today = gmdate( 'Y-m-d 00:00:00' );
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE ai_status = 'done' AND processed_at >= %s",
                $today
            )
        );
    }

    private static function daily_limit() {
        return class_exists( 'Sektorel_Core_Settings' )
            ? Sektorel_Core_Settings::effective_daily_limit()
            : self::DAILY_LIMIT_DEFAULT;
    }

    private static function api_key() {
        return defined( 'SEKTOREL_OPENAI_API_KEY' ) ? trim( (string) SEKTOREL_OPENAI_API_KEY ) : '';
    }

    private static function model() {
        return class_exists( 'Sektorel_Core_Settings' )
            ? Sektorel_Core_Settings::effective_model()
            : 'gpt-5-mini';
    }

    private static function trim_text( $value, $limit ) {
        $value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );
        return mb_substr( $value, 0, max( 1, absint( $limit ) ) );
    }

    private static function decode_json_array( $value ) {
        if ( ! $value ) {
            return array();
        }
        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
