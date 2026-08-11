<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic TOBB -> Sektörel Ajanda taxonomy mapping.
 *
 * - TOBB topic codes are mapped only to existing `sector` terms selected by an admin.
 * - TOBB cities are auto-matched to existing `location` terms by normalized exact name,
 *   with an optional explicit override when no unique exact match exists.
 * - No taxonomy term is ever created automatically.
 * - When a TOBB candidate resolves to an event, the mapped terms are appended and the
 *   event type is set to `fuar`.
 */
class Sektorel_Event_Source_TOBB_Taxonomy {

    const NONCE_ACTION        = 'sektorel_tobb_taxonomy_mapping';
    const SECTOR_MAP_OPTION   = 'sektorel_tobb_sector_map_v1';
    const LOCATION_MAP_OPTION = 'sektorel_tobb_location_map_v1';
    const ADAPTER             = 'tobb_fair_calendar';

    /**
     * Official TOBB Fuarcılık "Konu Grup Başlıkları" list.
     * Source: https://fuarlar.tobb.org.tr/Sayfa/konugrupbasligi
     */
    const TOPIC_GROUPS = array(
        1  => 'Ağaç Endüstrisi, Orman Ürünleri',
        2  => 'Altın, Mücevherat, Saat',
        3  => 'Ambalaj, Etiket',
        4  => 'Av, Silah, Doğa Sporları',
        5  => 'Bahçe, Bahçe Mobilyaları, Peyzaj, Çiçekçilik, Süs Bitkileri, Evcil Hayvanlar',
        6  => 'Balıkçılık, Su Ürünleri',
        7  => 'Bebek, Çocuk İhtiyaçları',
        8  => 'Bilgisayar, Bilgi Teknolojileri, Telekomünikasyon',
        9  => 'Çevre, Geri Dönüşüm, Atık Yönetimi, Su Teknolojileri, Belediye, Kent Mobilyaları',
        10 => 'Denizcilik, Yelkenli ve Motorlu Deniz Araçları ve Su Sporları',
        11 => 'Deri Teknolojileri, Deri Ürünleri, Deri Konfeksiyon, Ayakkabı',
        12 => 'Doğal Ürünler, Sağlıklı Yaşam',
        13 => 'Eğitim, Eğitim Ekipmanları ve Teknolojileri',
        14 => 'Elektrik, Elektronik, Aydınlatma, Otomasyon',
        15 => 'Gayrimenkul',
        16 => 'Enerji',
        17 => 'Ev Elektroniği, Elektrikli Ev Eşyaları, Dayanıklı Tüketim Malları',
        18 => 'Ev Tekstili, Halı',
        19 => 'Gıda, Gıda İşleme, İçecek, Teknoloji ve Endüstrileri',
        20 => 'Güvenlik, Yangın',
        21 => 'Hazır Giyim, Moda, Kumaş, Konfeksiyon Yan Sanayii',
        22 => 'Hediyelik Eşya, El Sanatları',
        23 => 'Isıtma, Soğutma, Havalandırma, Doğalgaz ve Sistemleri',
        24 => 'İnşaat Malzemeleri, Banyo, Mutfak, Seramik, Nalburiye, Hırdavat, Tesisat',
        25 => 'İş ve İnşaat Makineleri',
        26 => 'Kalite Kontrol ve Teknolojileri',
        27 => 'Kırtasiye, Büro Malzemeleri',
        28 => 'Kimya, Kimya Sanayii, Kimyasal Ürünler',
        29 => 'Kitap, Süreli Yayın',
        30 => 'Kozmetik, Güzellik, Estetik, Kişisel Bakım',
        31 => 'Lojistik, Taşımacılık, Depolama, İstifleme',
        32 => 'Maden, Madencilik, Doğal Taşlar, Mermer',
        33 => 'Matbaa Makinaları, Kağıt ve Teknolojileri',
        34 => 'Metal İşleme, Kesme, Kaynak, Akışkan, Döküm, Kalıp, Yan Sanayiler',
        35 => 'Mobilya, Mobilya Yan Sanayii',
        36 => 'Otel, Otel Ekipmanları, Restoran, Havuz, Endüstriyel Temizlik, Bakım/Onarım',
        37 => 'Otomobil, Ticari Araç, Motosiklet, Aksesuar, Otomotiv Yan Sanayii, Garaj Ekipmanları, Akaryakıt İstasyonları',
        38 => 'Pazarlama, Reklamcılık, Bayilik, Halkla İlişkiler, Promosyon, Tasarım, İnsan Kaynakları',
        39 => 'Perakendecilik, Mağaza Ekipmanları',
        40 => 'Sanat',
        41 => 'Plastik, Kauçuk ve Endüstrileri',
        42 => 'Tıp, Tıbbi Cihazlar, Laboratuvar, Diş Hekimliği, Eczacılık, Optik',
        43 => 'Savunma Sanayii ve Askeri Havacılık',
        44 => 'Sivil Havacılık',
        45 => 'Spor Malzemeleri',
        46 => 'Tarım, Seracılık, Hayvancılık ve Teknolojileri',
        47 => 'Tekstil, Konfeksiyon, Örgü, Nakış Makine ve Aksesuarları, İplik',
        48 => 'Turizm',
        49 => 'Unlu Mamuller ve Teknolojileri, Dondurma, Pasta, Şekerleme, Değirmen Makineleri',
        50 => 'Zücaciye, Porselen, Seramik',
        60 => 'Diğer',
        62 => 'Genel',
    );

    private static $location_terms_cache = null;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 46 );
        add_action( 'admin_post_sektorel_save_tobb_taxonomy_mapping', array( __CLASS__, 'handle_save' ) );

        // Run after cross-source evidence resolution so a merged candidate applies
        // taxonomy to the final primary event rather than a temporary duplicate.
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_apply_on_resolution' ), 160, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_apply_on_resolution' ), 160, 4 );

        add_action( 'add_meta_boxes_event_candidate', array( __CLASS__, 'add_candidate_meta_box' ), 100, 1 );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            'TOBB Taxonomy Eşlemeleri',
            'TOBB Eşlemeleri',
            'manage_options',
            'sektorel-tobb-taxonomy-mapping',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        $usage         = self::candidate_usage();
        $sector_map    = self::sector_map();
        $location_map  = self::location_map();
        $sector_terms  = self::terms_for_select( 'sector' );
        $location_terms = self::terms_for_select( 'location' );
        $saved         = ! empty( $_GET['tobb_mapping_saved'] );

        if ( $saved ) {
            echo '<div class="notice notice-success is-dismissible"><p>TOBB taxonomy eşlemeleri kaydedildi.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>TOBB Taxonomy Eşlemeleri</h1>
            <p>TOBB konu kodlarını mevcut <strong>Sektör</strong> terimlerine, TOBB şehirlerini ise mevcut <strong>Lokasyon</strong> terimlerine bağlar. Bu ekran yeni taxonomy terimi oluşturmaz.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="sektorel_save_tobb_taxonomy_mapping" />
                <?php wp_nonce_field( self::NONCE_ACTION, 'sektorel_tobb_mapping_nonce' ); ?>

                <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
                    <h2 style="margin-top:0;">Sektör Eşlemeleri</h2>
                    <p class="description">Yalnızca mevcut TOBB adaylarında kullanılan konu kodları gösterilir. Bir kod eşlenmemişse event'e sektör atanmaz ve adayda "eşlenmedi" olarak kalır.</p>

                    <?php if ( empty( $usage['topics'] ) ) : ?>
                        <p>Henüz TOBB adayı bulunamadı.</p>
                    <?php else : ?>
                        <table class="widefat striped" style="margin-top:14px;">
                            <thead>
                                <tr>
                                    <th style="width:70px;">Kod</th>
                                    <th>TOBB Konu Grup Başlığı</th>
                                    <th style="width:90px;">Kullanım</th>
                                    <th>Örnek Fuar</th>
                                    <th style="width:300px;">Sektörel Ajanda Sektörü</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $usage['topics'] as $code => $item ) : ?>
                                    <?php $selected_sector = isset( $sector_map[ $code ] ) ? absint( $sector_map[ $code ] ) : 0; ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( (string) $code ); ?></strong></td>
                                        <td><?php echo esc_html( isset( self::TOPIC_GROUPS[ $code ] ) ? self::TOPIC_GROUPS[ $code ] : 'Bilinmeyen TOBB kodu' ); ?></td>
                                        <td><?php echo esc_html( (string) $item['count'] ); ?></td>
                                        <td><?php echo esc_html( $item['sample'] ); ?></td>
                                        <td>
                                            <select name="sector_map[<?php echo esc_attr( (string) $code ); ?>]" style="width:100%;max-width:100%;">
                                                <option value="0">— Eşleme yok —</option>
                                                <?php foreach ( $sector_terms as $term_id => $label ) : ?>
                                                    <option value="<?php echo esc_attr( (string) $term_id ); ?>" <?php selected( $selected_sector, $term_id ); ?>><?php echo esc_html( $label ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
                    <h2 style="margin-top:0;">Şehir / Lokasyon Eşlemeleri</h2>
                    <p class="description">Sistem önce normalize edilmiş tam isim eşleşmesi yapar (ör. İSTANBUL → İstanbul). Otomatik eşleşme yoksa veya yanlışsa aşağıdan mevcut bir lokasyon seçebilirsin.</p>

                    <?php if ( empty( $usage['cities'] ) ) : ?>
                        <p>Henüz TOBB şehir verisi bulunamadı.</p>
                    <?php else : ?>
                        <table class="widefat striped" style="margin-top:14px;">
                            <thead>
                                <tr>
                                    <th style="width:180px;">TOBB Şehri</th>
                                    <th style="width:90px;">Kullanım</th>
                                    <th>Otomatik Eşleşme</th>
                                    <th style="width:340px;">Manuel Override</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $usage['cities'] as $city_key => $item ) : ?>
                                    <?php
                                    $auto_term = self::auto_location_term( $item['name'] );
                                    $selected_location = isset( $location_map[ $city_key ] ) ? absint( $location_map[ $city_key ] ) : 0;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $item['name'] ); ?></strong></td>
                                        <td><?php echo esc_html( (string) $item['count'] ); ?></td>
                                        <td>
                                            <?php if ( $auto_term ) : ?>
                                                <span style="color:#008a20;font-weight:600;">✓ <?php echo esc_html( self::term_label( $auto_term ) ); ?></span>
                                            <?php else : ?>
                                                <span style="color:#b32d2e;font-weight:600;">Eşleşme yok / belirsiz</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <input type="hidden" name="city_keys[]" value="<?php echo esc_attr( $city_key ); ?>" />
                                            <select name="location_map[<?php echo esc_attr( $city_key ); ?>]" style="width:100%;max-width:100%;">
                                                <option value="0">— Otomatik eşleşmeyi kullan —</option>
                                                <?php foreach ( $location_terms as $term_id => $label ) : ?>
                                                    <option value="<?php echo esc_attr( (string) $term_id ); ?>" <?php selected( $selected_location, $term_id ); ?>><?php echo esc_html( $label ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-hero">Eşlemeleri Kaydet</button>
                    <a class="button button-secondary" href="https://fuarlar.tobb.org.tr/Sayfa/konugrupbasligi" target="_blank" rel="noopener noreferrer" style="margin-left:8px;">TOBB Konu Kodlarını Aç</a>
                </p>
            </form>
        </div>
        <?php
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        check_admin_referer( self::NONCE_ACTION, 'sektorel_tobb_mapping_nonce' );

        $sector_map = array();
        $submitted_sector_map = isset( $_POST['sector_map'] ) && is_array( $_POST['sector_map'] )
            ? wp_unslash( $_POST['sector_map'] )
            : array();

        foreach ( $submitted_sector_map as $raw_code => $raw_term_id ) {
            $code    = absint( $raw_code );
            $term_id = absint( $raw_term_id );

            if ( ! $code || ! isset( self::TOPIC_GROUPS[ $code ] ) || ! $term_id ) {
                continue;
            }

            $term = get_term( $term_id, 'sector' );
            if ( $term && ! is_wp_error( $term ) ) {
                $sector_map[ $code ] = $term_id;
            }
        }

        update_option( self::SECTOR_MAP_OPTION, $sector_map, false );

        $location_map = array();
        $submitted_location_map = isset( $_POST['location_map'] ) && is_array( $_POST['location_map'] )
            ? wp_unslash( $_POST['location_map'] )
            : array();
        $city_keys = isset( $_POST['city_keys'] ) && is_array( $_POST['city_keys'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['city_keys'] ) )
            : array();

        foreach ( array_unique( $city_keys ) as $city_key ) {
            if ( ! $city_key ) {
                continue;
            }

            $term_id = isset( $submitted_location_map[ $city_key ] ) ? absint( $submitted_location_map[ $city_key ] ) : 0;
            if ( ! $term_id ) {
                continue;
            }

            $term = get_term( $term_id, 'location' );
            if ( $term && ! is_wp_error( $term ) ) {
                $location_map[ $city_key ] = $term_id;
            }
        }

        update_option( self::LOCATION_MAP_OPTION, $location_map, false );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'post_type'          => 'event',
                    'page'               => 'sektorel-tobb-taxonomy-mapping',
                    'tobb_mapping_saved' => 1,
                ),
                admin_url( 'edit.php' )
            )
        );
        exit;
    }

    public static function maybe_apply_on_resolution( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( 'imported_event_id' !== $meta_key || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        $candidate_id = absint( $object_id );
        if ( self::ADAPTER !== (string) get_post_meta( $candidate_id, 'source_adapter', true ) ) {
            return;
        }

        // Re-read the current value. Cross-source evidence may have rewritten the
        // imported_event_id earlier in the same meta hook to an existing primary event.
        $event_id = absint( get_post_meta( $candidate_id, 'imported_event_id', true ) );
        if ( ! $event_id || 'event' !== get_post_type( $event_id ) || 'trash' === get_post_status( $event_id ) ) {
            return;
        }

        self::apply_candidate_to_event( $candidate_id, $event_id );
    }

    public static function apply_candidate_to_event( $candidate_id, $event_id ) {
        $candidate_id = absint( $candidate_id );
        $event_id     = absint( $event_id );

        if ( ! $candidate_id || ! $event_id || self::ADAPTER !== (string) get_post_meta( $candidate_id, 'source_adapter', true ) ) {
            return new WP_Error( 'tobb_taxonomy_invalid', 'TOBB taxonomy eşlemesi için geçersiz aday veya event.' );
        }

        update_post_meta( $event_id, 'event_type', 'fuar' );

        $sector_ids     = self::mapped_sector_ids_for_candidate( $candidate_id );
        $unmapped_codes = self::unmapped_topic_codes_for_candidate( $candidate_id );

        update_post_meta( $candidate_id, 'tobb_sector_term_ids', $sector_ids );
        update_post_meta( $candidate_id, 'tobb_unmapped_topic_codes', $unmapped_codes );

        if ( $sector_ids ) {
            wp_set_object_terms( $event_id, $sector_ids, 'sector', true );
        }

        $city = trim( (string) get_post_meta( $candidate_id, 'tobb_city', true ) );
        $location_term = self::location_term_for_city( $city );

        if ( $location_term ) {
            update_post_meta( $candidate_id, 'tobb_location_term_id', absint( $location_term->term_id ) );
            delete_post_meta( $candidate_id, 'tobb_location_mapping_status' );
            wp_set_object_terms( $event_id, array( absint( $location_term->term_id ) ), 'location', true );
        } else {
            delete_post_meta( $candidate_id, 'tobb_location_term_id' );
            update_post_meta( $candidate_id, 'tobb_location_mapping_status', 'unmapped' );
        }

        update_post_meta( $candidate_id, 'tobb_taxonomy_applied_at', current_time( 'mysql' ) );
        update_post_meta( $event_id, 'tobb_taxonomy_applied_at', current_time( 'mysql' ) );

        return array(
            'sector_ids'       => $sector_ids,
            'unmapped_codes'   => $unmapped_codes,
            'location_term_id' => $location_term ? absint( $location_term->term_id ) : 0,
        );
    }

    public static function add_candidate_meta_box( $post ) {
        if ( ! $post || self::ADAPTER !== (string) get_post_meta( $post->ID, 'source_adapter', true ) ) {
            return;
        }

        add_meta_box(
            'sektorel_tobb_taxonomy_status',
            'TOBB Taxonomy Eşlemesi',
            array( __CLASS__, 'render_candidate_meta_box' ),
            'event_candidate',
            'side',
            'default'
        );
    }

    public static function render_candidate_meta_box( $post ) {
        $codes      = self::candidate_topic_codes( $post->ID );
        $sector_map = self::sector_map();

        echo '<p><strong>Sektörler</strong></p>';
        if ( ! $codes ) {
            echo '<p class="description">TOBB konu kodu yok.</p>';
        } else {
            echo '<ul style="margin-top:0;">';
            foreach ( $codes as $code ) {
                $label   = isset( self::TOPIC_GROUPS[ $code ] ) ? self::TOPIC_GROUPS[ $code ] : 'Bilinmeyen TOBB kodu';
                $term_id = isset( $sector_map[ $code ] ) ? absint( $sector_map[ $code ] ) : 0;
                $term    = $term_id ? get_term( $term_id, 'sector' ) : null;

                echo '<li><strong>' . esc_html( (string) $code ) . '</strong> — ' . esc_html( $label ) . '<br>';
                if ( $term && ! is_wp_error( $term ) ) {
                    echo '<span style="color:#008a20;">→ ' . esc_html( self::term_label( $term ) ) . '</span>';
                } else {
                    echo '<span style="color:#b32d2e;">→ Eşlenmedi</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        $city = trim( (string) get_post_meta( $post->ID, 'tobb_city', true ) );
        echo '<p><strong>Lokasyon</strong></p>';
        if ( ! $city ) {
            echo '<p class="description">TOBB şehir verisi yok.</p>';
        } else {
            $term = self::location_term_for_city( $city );
            echo '<p style="margin-top:0;">' . esc_html( $city ) . '<br>';
            if ( $term ) {
                echo '<span style="color:#008a20;">→ ' . esc_html( self::term_label( $term ) ) . '</span>';
            } else {
                echo '<span style="color:#b32d2e;">→ Eşlenmedi</span>';
            }
            echo '</p>';
        }

        echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=event&page=sektorel-tobb-taxonomy-mapping' ) ) . '">Eşlemeleri yönet</a></p>';
    }

    private static function candidate_usage() {
        $ids = get_posts(
            array(
                'post_type'      => 'event_candidate',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_key'       => 'source_adapter',
                'meta_value'     => self::ADAPTER,
                'orderby'        => 'ID',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            )
        );

        $topics = array();
        $cities = array();

        if ( $ids ) {
            update_meta_cache( 'post', $ids );
        }

        foreach ( $ids as $candidate_id ) {
            $title = get_the_title( $candidate_id );

            foreach ( self::candidate_topic_codes( $candidate_id ) as $code ) {
                if ( ! isset( $topics[ $code ] ) ) {
                    $topics[ $code ] = array( 'count' => 0, 'sample' => $title );
                }
                $topics[ $code ]['count']++;
            }

            $city = trim( (string) get_post_meta( $candidate_id, 'tobb_city', true ) );
            if ( $city ) {
                $city_key = self::normalize_key( $city );
                if ( $city_key ) {
                    if ( ! isset( $cities[ $city_key ] ) ) {
                        $cities[ $city_key ] = array( 'name' => $city, 'count' => 0 );
                    }
                    $cities[ $city_key ]['count']++;
                }
            }
        }

        ksort( $topics, SORT_NUMERIC );
        uasort(
            $cities,
            static function( $a, $b ) {
                return strcasecmp( $a['name'], $b['name'] );
            }
        );

        return array(
            'topics' => $topics,
            'cities' => $cities,
        );
    }

    private static function mapped_sector_ids_for_candidate( $candidate_id ) {
        $map = self::sector_map();
        $ids = array();

        foreach ( self::candidate_topic_codes( $candidate_id ) as $code ) {
            if ( empty( $map[ $code ] ) ) {
                continue;
            }

            $term_id = absint( $map[ $code ] );
            $term    = get_term( $term_id, 'sector' );
            if ( $term && ! is_wp_error( $term ) ) {
                $ids[] = $term_id;
            }
        }

        return array_values( array_unique( array_filter( $ids ) ) );
    }

    private static function unmapped_topic_codes_for_candidate( $candidate_id ) {
        $map      = self::sector_map();
        $unmapped = array();

        foreach ( self::candidate_topic_codes( $candidate_id ) as $code ) {
            $term_id = isset( $map[ $code ] ) ? absint( $map[ $code ] ) : 0;
            $term    = $term_id ? get_term( $term_id, 'sector' ) : null;
            if ( ! $term || is_wp_error( $term ) ) {
                $unmapped[] = $code;
            }
        }

        return array_values( array_unique( $unmapped ) );
    }

    private static function candidate_topic_codes( $candidate_id ) {
        $codes = array();
        foreach ( array( 'tobb_topic_1', 'tobb_topic_2', 'tobb_topic_3' ) as $key ) {
            $code = absint( get_post_meta( $candidate_id, $key, true ) );
            if ( $code > 0 ) {
                $codes[] = $code;
            }
        }
        return array_values( array_unique( $codes ) );
    }

    private static function location_term_for_city( $city ) {
        $city_key = self::normalize_key( $city );
        if ( ! $city_key ) {
            return null;
        }

        $map = self::location_map();
        if ( ! empty( $map[ $city_key ] ) ) {
            $term = get_term( absint( $map[ $city_key ] ), 'location' );
            if ( $term && ! is_wp_error( $term ) ) {
                return $term;
            }
        }

        return self::auto_location_term( $city );
    }

    private static function auto_location_term( $city ) {
        $city_key = self::normalize_key( $city );
        if ( ! $city_key ) {
            return null;
        }

        $matches = array();
        foreach ( self::location_terms() as $term ) {
            if ( self::normalize_key( $term->name ) === $city_key ) {
                $matches[] = $term;
            }
        }

        return 1 === count( $matches ) ? $matches[0] : null;
    }

    private static function location_terms() {
        if ( null !== self::$location_terms_cache ) {
            return self::$location_terms_cache;
        }

        $terms = get_terms(
            array(
                'taxonomy'   => 'location',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );

        self::$location_terms_cache = is_wp_error( $terms ) ? array() : $terms;
        return self::$location_terms_cache;
    }

    private static function terms_for_select( $taxonomy ) {
        $terms = 'location' === $taxonomy
            ? self::location_terms()
            : get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                )
            );

        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
            return array();
        }

        $items = array();
        foreach ( $terms as $term ) {
            $items[ absint( $term->term_id ) ] = self::term_label( $term );
        }
        return $items;
    }

    private static function term_label( $term ) {
        if ( ! $term || is_wp_error( $term ) ) {
            return '';
        }

        $parts     = array( $term->name );
        $parent_id = absint( $term->parent );
        $guard     = 0;

        while ( $parent_id && $guard < 5 ) {
            $parent = get_term( $parent_id, $term->taxonomy );
            if ( ! $parent || is_wp_error( $parent ) ) {
                break;
            }
            array_unshift( $parts, $parent->name );
            $parent_id = absint( $parent->parent );
            $guard++;
        }

        return implode( ' › ', $parts );
    }

    private static function sector_map() {
        $map = get_option( self::SECTOR_MAP_OPTION, array() );
        return is_array( $map ) ? $map : array();
    }

    private static function location_map() {
        $map = get_option( self::LOCATION_MAP_OPTION, array() );
        return is_array( $map ) ? $map : array();
    }

    private static function normalize_key( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = remove_accents( $value );
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        $value = preg_replace( '/[^a-z0-9]+/i', '', $value );
        return sanitize_key( (string) $value );
    }
}
