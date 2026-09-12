<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-content-draft-media.php';

/**
 * Controlled refresh for already-created TOBB AI drafts.
 *
 * Reuses the existing candidate/post pair, re-enriches the official source,
 * sends the full source text through the current Sektorel Ajanda editorial
 * policy, and updates only WordPress drafts. It never creates or publishes a
 * new post.
 */
class Sektorel_Content_AI_Draft_Reprocessor {

    const AJAX_ACTION = 'sektorel_content_ai_reprocess_tobb_drafts';
    const MAX_BATCH = 5;
    const MAX_OUTPUT_TOKENS = 2200;
    const REQUEST_TIMEOUT = 45;
    const HISTORY_LIMIT = 20;

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_reprocess' ) );
    }

    public static function ajax_reprocess() {
        check_ajax_referer( Sektorel_Content_AI_Draft_Processor::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
        if ( '' === self::api_key() ) {
            wp_send_json_error( array( 'message' => 'OpenAI API anahtarı tanımlı değil.' ) );
        }

        if ( class_exists( 'Sektorel_Core_Settings' ) ) {
            $budget = Sektorel_Core_Settings::budget_status();
            if ( ! empty( $budget['blocked'] ) ) {
                wp_send_json_error(
                    array(
                        'message' => sprintf(
                            'Aylık AI maliyet sınırı doldu. Harcama: $%s / Limit: $%s.',
                            number_format( (float) $budget['spent'], 4, '.', '' ),
                            number_format( (float) $budget['budget'], 2, '.', '' )
                        ),
                    ),
                    429
                );
            }
        }

        $daily_limit = self::daily_limit();
        $daily_used = self::daily_ai_request_count();
        $remaining_daily = max( 0, $daily_limit - $daily_used );
        if ( $remaining_daily <= 0 ) {
            wp_send_json_error( array( 'message' => 'Günlük AI işlem limiti doldu.' ), 429 );
        }

        global $wpdb;
        $table = Sektorel_Content_Candidates::table_name();
        $month_start = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp', true ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE source_key IN ( 'tobb_news', 'tobb_announcements' )
                   AND status = %s
                   AND ai_status = 'done'
                   AND draft_post_id > 0
                   AND processed_at IS NOT NULL
                   AND processed_at >= %s
                 ORDER BY processed_at DESC, id DESC
                 LIMIT 12",
                Sektorel_Content_Candidates::STATUS_PROCESSED,
                $month_start
            ),
            ARRAY_A
        );

        $limit = min( self::MAX_BATCH, $remaining_daily );
        $reprocessed = 0;
        $skipped = 0;
        $errors = 0;
        $images_added = 0;
        $image_errors = 0;
        $attempted = 0;
        $batch_cost = 0.0;
        $messages = array();
        $current_model = self::model();

        foreach ( (array) $rows as $row ) {
            if ( $attempted >= $limit ) {
                break;
            }

            $candidate_id = absint( $row['id'] ?? 0 );
            $post_id = absint( $row['draft_post_id'] ?? 0 );
            if ( ! $candidate_id || ! $post_id ) {
                $skipped++;
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post || 'post' !== $post->post_type || 'draft' !== $post->post_status ) {
                $skipped++;
                $messages[] = sprintf( '#%d atlandı: yalnız draft durumundaki yazılar yeniden işlenir.', $post_id );
                continue;
            }

            if ( absint( get_post_meta( $post_id, '_sektorel_content_candidate_id', true ) ) !== $candidate_id ) {
                $skipped++;
                $messages[] = sprintf( '#%d atlandı: candidate/post bağı doğrulanamadı.', $post_id );
                continue;
            }

            $previous_model = sanitize_text_field( $row['ai_model'] ?? '' );
            if ( $previous_model && $previous_model !== $current_model ) {
                $skipped++;
                $messages[] = sprintf( '#%d atlandı: önceki AI modeli (%s) ile aktif model (%s) farklı.', $post_id, $previous_model, $current_model );
                continue;
            }

            if ( class_exists( 'Sektorel_Core_Settings' ) ) {
                $budget = Sektorel_Core_Settings::budget_status();
                if ( ! empty( $budget['blocked'] ) ) {
                    $messages[] = 'Aylık AI maliyet sınırına ulaşıldığı için batch durduruldu.';
                    break;
                }
            }

            $enriched = Sektorel_Content_Detail_Extractor::enrich_candidate( $row );
            if ( is_wp_error( $enriched ) ) {
                $errors++;
                $messages[] = sprintf( '#%d kaynak zenginleştirme hatası: %s', $post_id, $enriched->get_error_message() );
                continue;
            }

            $source_text = self::trim_text( $enriched['extracted_text'] ?? '', 24000 );
            if ( mb_strlen( $source_text ) < 80 ) {
                $errors++;
                $messages[] = sprintf( '#%d atlandı: tam kaynak metni AI için yetersiz.', $post_id );
                continue;
            }

            $source_hash = hash( 'sha256', $source_text );
            $fresh_evidence = self::decode_json_array( $enriched['evidence_json'] ?? '' );
            if ( self::has_successful_source_hash( $fresh_evidence, $source_hash ) ) {
                $skipped++;
                $messages[] = sprintf( '#%d zaten bu kaynak sürümünden yeniden işlendi; tekrar AI harcaması yapılmadı.', $post_id );
                continue;
            }

            $attempted++;
            $result = self::request_editorial_draft( $enriched );
            if ( is_wp_error( $result ) ) {
                $errors++;
                self::record_reprocess_attempt(
                    $candidate_id,
                    $post_id,
                    $source_hash,
                    array(),
                    'error',
                    $result->get_error_code(),
                    $result->get_error_message()
                );
                $messages[] = sprintf( '#%d AI hatası: %s', $post_id, $result->get_error_message() );
                continue;
            }

            $usage = $result['usage'];
            $request_cost = class_exists( 'Sektorel_Core_Settings' )
                ? Sektorel_Core_Settings::estimate_cost( $current_model, absint( $usage['input_tokens'] ?? 0 ), absint( $usage['output_tokens'] ?? 0 ) )
                : 0.0;
            $batch_cost += $request_cost;

            $updated = self::update_existing_draft( $post_id, $candidate_id, $enriched, $result['article'] );
            if ( is_wp_error( $updated ) ) {
                $errors++;
                self::record_reprocess_attempt(
                    $candidate_id,
                    $post_id,
                    $source_hash,
                    $usage,
                    'error',
                    $updated->get_error_code(),
                    $updated->get_error_message()
                );
                $messages[] = sprintf( '#%d güncellenemedi: %s', $post_id, $updated->get_error_message() );
                continue;
            }

            self::apply_rank_math_meta( $post_id, $result['article'] );
            $media = Sektorel_Content_Draft_Media::attach_featured_image( $post_id, $result['article'] );
            $media_status = 'ready';
            $media_error = '';
            if ( is_wp_error( $media ) ) {
                $image_errors++;
                $media_status = 'error';
                $media_error = $media->get_error_message();
                update_post_meta( $post_id, '_sektorel_content_media_status', 'error' );
                update_post_meta( $post_id, '_sektorel_content_media_error', $media_error );
            } else {
                delete_post_meta( $post_id, '_sektorel_content_media_error' );
                self::apply_rank_math_image_meta( $post_id, absint( $media['attachment_id'] ?? 0 ) );
                if ( 'created' === ( $media['status'] ?? '' ) ) {
                    $images_added++;
                }
            }

            self::record_reprocess_attempt(
                $candidate_id,
                $post_id,
                $source_hash,
                $usage,
                'success',
                $media_status,
                $media_error
            );

            $fresh = Sektorel_Content_Candidates::get( $candidate_id );
            if ( is_array( $fresh ) ) {
                update_post_meta( $post_id, '_sektorel_content_evidence', self::decode_json_array( $fresh['evidence_json'] ?? '' ) );
            }
            update_post_meta( $post_id, '_sektorel_content_reprocessed_at', current_time( 'mysql', true ) );
            update_post_meta( $post_id, '_sektorel_content_reprocess_source_hash', $source_hash );
            update_post_meta( $post_id, '_sektorel_content_ai_model', $current_model );

            $reprocessed++;
            $provider = sanitize_key( (string) get_post_meta( $post_id, '_sektorel_media_provider', true ) );
            $message = sprintf( '#%d tam kaynaktan yeniden işlendi', $post_id );
            if ( $provider ) {
                $message .= '; featured image: ' . $provider;
            }
            if ( $media_error ) {
                $message .= '; görsel hatası: ' . $media_error;
            }
            $messages[] = $message . '.';
        }

        wp_send_json_success( array(
            'attempted'      => $attempted,
            'reprocessed'    => $reprocessed,
            'skipped'        => $skipped,
            'errors'         => $errors,
            'images_added'   => $images_added,
            'image_errors'   => $image_errors,
            'batch_cost'     => round( $batch_cost, 6 ),
            'daily_limit'    => $daily_limit,
            'daily_used'     => self::daily_ai_request_count(),
            'messages'       => $messages,
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

        $prompt = "Sektörel Ajanda için Türkçe ekonomi/iş dünyası editörüsün. YALNIZ verilen resmi kaynak metnindeki doğrulanabilir bilgileri kullan. Kaynakta olmayan kişi, rakam, tarih, kurum, alıntı, bağlam veya neden-sonuç ilişkisi uydurma. Belirsiz bilgiyi kesinleştirme. Kaynak metnini kopyalama; anlamı koruyarak özgün, profesyonel haber dilinde yeniden yaz. Sansasyonel dil kullanma. Başlık kısa, açıklayıcı ve clickbait olmayan bir haber başlığı olsun. İçerik HTML olsun ve yalnız p, h2, ul, li, strong etiketlerini kullan. Kaynak URL'sini içerik gövdesine ekleme; sistem meta olarak saklayacak. category_slug yalnız allowed_category_slugs listesinden bir değer olmalı; liste boşsa boş string döndür. tags en fazla 8 kısa Türkçe etiket olsun. seo_description 140-160 karakter aralığında doğal Türkçe meta açıklaması olsun. focus_keyword haberi en iyi tanımlayan tek bir kısa anahtar kelime/kelime grubu olsun. image_query Pexels fallback için 2-5 kelimelik somut bir İngilizce görsel sorgusu olsun. Yalnız geçerli JSON döndür; markdown/code fence kullanma. JSON şeması: {\"title\":\"...\",\"excerpt\":\"...\",\"content_html\":\"...\",\"category_slug\":\"...\",\"tags\":[\"...\"],\"seo_description\":\"...\",\"focus_keyword\":\"...\",\"image_query\":\"...\"}.\n\nKAYNAK VERİ:\n" . wp_json_encode( $source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

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
        $focus_keyword = sanitize_text_field( $article['focus_keyword'] ?? '' );
        $image_query = sanitize_text_field( $article['image_query'] ?? '' );
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
        if ( '' === $focus_keyword && ! empty( $tags ) ) {
            $focus_keyword = $tags[0];
        }
        if ( '' === $image_query ) {
            $image_query = $focus_keyword ?: implode( ' ', array_slice( $tags, 0, 3 ) );
        }

        return array(
            'title'           => mb_substr( $title, 0, 220 ),
            'excerpt'         => mb_substr( $excerpt, 0, 500 ),
            'content_html'    => $content,
            'category_slug'   => $category,
            'tags'            => $tags,
            'seo_description' => mb_substr( $seo_description, 0, 170 ),
            'focus_keyword'   => mb_substr( $focus_keyword, 0, 120 ),
            'image_query'     => mb_substr( $image_query, 0, 120 ),
        );
    }

    private static function update_existing_draft( $post_id, $candidate_id, $row, $article ) {
        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type || 'draft' !== $post->post_status ) {
            return new WP_Error( 'content_ai_reprocess_not_draft', 'Yazı artık draft durumunda değil.' );
        }
        if ( absint( get_post_meta( $post_id, '_sektorel_content_candidate_id', true ) ) !== absint( $candidate_id ) ) {
            return new WP_Error( 'content_ai_reprocess_candidate_mismatch', 'Candidate/post bağı doğrulanamadı.' );
        }

        $updated = wp_update_post(
            wp_slash(
                array(
                    'ID'           => $post_id,
                    'post_title'   => $article['title'],
                    'post_excerpt' => $article['excerpt'],
                    'post_content' => $article['content_html'],
                )
            ),
            true
        );
        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        if ( ! empty( $article['category_slug'] ) ) {
            $term = get_term_by( 'slug', $article['category_slug'], 'category' );
            if ( $term && ! is_wp_error( $term ) ) {
                wp_set_post_categories( $post_id, array( (int) $term->term_id ), false );
            }
        }
        wp_set_post_tags( $post_id, (array) ( $article['tags'] ?? array() ), false );

        $source_url = trim( (string) ( ! empty( $row['canonical_url'] ) ? $row['canonical_url'] : ( $row['source_url'] ?? '' ) ) );
        update_post_meta( $post_id, '_sektorel_content_source_key', sanitize_key( $row['source_key'] ?? '' ) );
        update_post_meta( $post_id, '_sektorel_content_source_url', esc_url_raw( $source_url ) );
        update_post_meta( $post_id, '_sektorel_content_source_title', sanitize_text_field( $row['title'] ?? '' ) );
        update_post_meta( $post_id, '_sektorel_content_published_at', sanitize_text_field( $row['published_at'] ?? '' ) );
        update_post_meta( $post_id, '_sektorel_content_generated_at', current_time( 'mysql', true ) );

        return true;
    }

    private static function apply_rank_math_meta( $post_id, $article ) {
        $title = sanitize_text_field( $article['title'] ?? get_the_title( $post_id ) );
        $description = sanitize_text_field( $article['seo_description'] ?? '' );
        if ( '' === $description ) {
            $description = sanitize_text_field( get_post_field( 'post_excerpt', $post_id ) );
        }
        $focus_keyword = sanitize_text_field( $article['focus_keyword'] ?? '' );
        if ( '' === $focus_keyword ) {
            $tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
            if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
                $focus_keyword = sanitize_text_field( $tags[0] );
            }
        }

        update_post_meta( $post_id, 'rank_math_title', mb_substr( $title, 0, 220 ) );
        if ( $description ) {
            update_post_meta( $post_id, 'rank_math_description', mb_substr( $description, 0, 170 ) );
        }
        if ( $focus_keyword ) {
            update_post_meta( $post_id, 'rank_math_focus_keyword', mb_substr( $focus_keyword, 0, 120 ) );
        }
        update_post_meta( $post_id, 'rank_math_facebook_title', mb_substr( $title, 0, 220 ) );
        update_post_meta( $post_id, 'rank_math_facebook_description', mb_substr( $description, 0, 170 ) );
        update_post_meta( $post_id, 'rank_math_twitter_title', mb_substr( $title, 0, 220 ) );
        update_post_meta( $post_id, 'rank_math_twitter_description', mb_substr( $description, 0, 170 ) );
        update_post_meta( $post_id, 'rank_math_twitter_use_facebook', 'on' );
    }

    private static function apply_rank_math_image_meta( $post_id, $attachment_id ) {
        if ( ! $attachment_id ) {
            return;
        }
        $url = wp_get_attachment_image_url( $attachment_id, 'full' );
        if ( ! $url ) {
            return;
        }
        update_post_meta( $post_id, 'rank_math_facebook_image', esc_url_raw( $url ) );
        update_post_meta( $post_id, 'rank_math_facebook_image_id', $attachment_id );
        update_post_meta( $post_id, 'rank_math_twitter_image', esc_url_raw( $url ) );
        update_post_meta( $post_id, 'rank_math_twitter_image_id', $attachment_id );
    }

    private static function record_reprocess_attempt( $candidate_id, $post_id, $source_hash, $usage, $status, $code = '', $message = '' ) {
        global $wpdb;

        $candidate = Sektorel_Content_Candidates::get( $candidate_id );
        if ( ! is_array( $candidate ) ) {
            return;
        }

        $evidence = self::decode_json_array( $candidate['evidence_json'] ?? '' );
        $history = isset( $evidence['ai_reprocess_history'] ) && is_array( $evidence['ai_reprocess_history'] )
            ? array_values( $evidence['ai_reprocess_history'] )
            : array();

        $input_tokens = absint( $usage['input_tokens'] ?? 0 );
        $output_tokens = absint( $usage['output_tokens'] ?? 0 );
        $model = self::model();
        $request_cost = class_exists( 'Sektorel_Core_Settings' )
            ? Sektorel_Core_Settings::estimate_cost( $model, $input_tokens, $output_tokens )
            : 0.0;

        $history[] = array(
            'status'         => sanitize_key( $status ),
            'post_id'        => absint( $post_id ),
            'model'          => $model,
            'source_hash'    => sanitize_text_field( $source_hash ),
            'requested_at'   => gmdate( 'c' ),
            'input_tokens'   => $input_tokens,
            'output_tokens'  => $output_tokens,
            'estimated_cost' => $request_cost,
            'code'           => sanitize_key( $code ),
            'message'        => sanitize_textarea_field( $message ),
        );
        if ( count( $history ) > self::HISTORY_LIMIT ) {
            $history = array_slice( $history, -self::HISTORY_LIMIT );
        }
        $evidence['ai_reprocess_history'] = $history;

        $total_input = absint( $candidate['ai_input_tokens'] ?? 0 ) + $input_tokens;
        $total_output = absint( $candidate['ai_output_tokens'] ?? 0 ) + $output_tokens;
        $total_cost = class_exists( 'Sektorel_Core_Settings' )
            ? Sektorel_Core_Settings::estimate_cost( $model, $total_input, $total_output )
            : (float) ( $candidate['ai_estimated_cost'] ?? 0 ) + $request_cost;

        $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'ai_model'          => $model,
                'ai_input_tokens'   => $total_input,
                'ai_output_tokens'  => $total_output,
                'ai_estimated_cost' => $total_cost,
                'evidence_json'     => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'        => current_time( 'mysql', true ),
            ),
            array( 'id' => absint( $candidate_id ) ),
            array( '%s', '%d', '%d', '%f', '%s', '%s' ),
            array( '%d' )
        );
    }

    private static function has_successful_source_hash( $evidence, $source_hash ) {
        $history = isset( $evidence['ai_reprocess_history'] ) && is_array( $evidence['ai_reprocess_history'] )
            ? $evidence['ai_reprocess_history']
            : array();
        foreach ( $history as $entry ) {
            if ( ! is_array( $entry ) || 'success' !== ( $entry['status'] ?? '' ) ) {
                continue;
            }
            $previous_hash = (string) ( $entry['source_hash'] ?? '' );
            if ( $previous_hash && hash_equals( $previous_hash, $source_hash ) ) {
                return true;
            }
        }
        return false;
    }

    private static function daily_ai_request_count() {
        $base = 0;
        if ( class_exists( 'Sektorel_Core_Settings' ) ) {
            $summary = Sektorel_Core_Settings::usage_summary( 'day' );
            $base = absint( $summary['requests'] ?? 0 );
        }
        return $base + self::reprocess_request_count_since( gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp', true ) ) );
    }

    private static function reprocess_request_count_since( $from ) {
        global $wpdb;
        $table = Sektorel_Content_Candidates::table_name();
        $rows = $wpdb->get_col(
            "SELECT evidence_json FROM {$table}
             WHERE source_key IN ( 'tobb_news', 'tobb_announcements' )
               AND evidence_json LIKE '%\"ai_reprocess_history\"%'"
        );

        $count = 0;
        $from_ts = strtotime( (string) $from . ' UTC' );
        foreach ( (array) $rows as $json ) {
            $evidence = self::decode_json_array( $json );
            foreach ( (array) ( $evidence['ai_reprocess_history'] ?? array() ) as $entry ) {
                $requested = strtotime( (string) ( $entry['requested_at'] ?? '' ) );
                if ( $requested && $requested >= $from_ts ) {
                    $count++;
                }
            }
        }
        return $count;
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

    private static function daily_limit() {
        return class_exists( 'Sektorel_Core_Settings' )
            ? Sektorel_Core_Settings::effective_daily_limit()
            : 20;
    }

    private static function api_key() {
        if ( defined( 'SEKTOREL_OPENAI_API_KEY' ) ) {
            return trim( (string) SEKTOREL_OPENAI_API_KEY );
        }
        return class_exists( 'Sektorel_Core_Settings' )
            ? trim( (string) Sektorel_Core_Settings::secret( 'openai_api_key' ) )
            : '';
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
