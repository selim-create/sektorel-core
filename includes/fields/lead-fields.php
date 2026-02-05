<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Lead_Fields {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post', array( __CLASS__, 'save_post' ) );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ) );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_lead_details',
            'Talep ve İlan Detayları',
            array( __CLASS__, 'render_metabox' ),
            'lead',
            'normal',
            'high'
        );
    }

    public static function render_metabox( $post ) {
        wp_nonce_field( 'sektorel_lead_save', 'sektorel_lead_nonce' );

        // Helper function to get meta values
        $val = function($key) use ($post) {
            return get_post_meta( $post->ID, $key, true );
        };

        ?>
        <style>
            .sektorel-panel { margin-top: 10px; }
            .sektorel-section-title { 
                font-size: 14px; 
                font-weight: 700; 
                border-bottom: 1px solid #ddd; 
                padding: 10px 0 5px 0; 
                margin: 20px 0 15px 0; 
                color: #2c3338;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .sektorel-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
            .sektorel-field { margin-bottom: 15px; }
            .sektorel-field label { display: block; font-weight: 600; margin-bottom: 5px; color: #444; }
            .sektorel-field input[type="text"], 
            .sektorel-field input[type="number"], 
            .sektorel-field input[type="date"], 
            .sektorel-field select,
            .sektorel-field textarea { width: 100%; }
            .description { color: #666; font-style: italic; font-size: 12px; margin-top: 3px; }
        </style>

        <div class="sektorel-panel">
            
            <!-- Temel Bilgiler -->
            <div class="sektorel-row">
                <div class="sektorel-field">
                    <label for="lead_type">Talep Tipi</label>
                    <select id="lead_type" name="lead_type">
                        <option value="alim" <?php selected($val('lead_type'), 'alim'); ?>>Alım Talebi</option>
                        <option value="hizmet" <?php selected($val('lead_type'), 'hizmet'); ?>>Hizmet Talebi</option>
                        <option value="bayilik" <?php selected($val('lead_type'), 'bayilik'); ?>>Bayilik Verme</option>
                        <option value="ortaklik" <?php selected($val('lead_type'), 'ortaklik'); ?>>Çözüm Ortaklığı</option>
                        <option value="satis" <?php selected($val('lead_type'), 'satis'); ?>>Satış İlanı</option>
                    </select>
                </div>
                <div class="sektorel-field">
                    <label for="status">İlan Durumu</label>
                    <select id="status" name="status">
                        <option value="pending" <?php selected($val('status'), 'pending'); ?>>Onay Bekliyor</option>
                        <option value="active" <?php selected($val('status'), 'active'); ?>>Aktif</option>
                        <option value="closed" <?php selected($val('status'), 'closed'); ?>>Kapandı / Tamamlandı</option>
                        <option value="expired" <?php selected($val('status'), 'expired'); ?>>Süresi Doldu</option>
                    </select>
                </div>
            </div>

            <!-- Bütçe ve Zaman -->
            <div class="sektorel-section-title">Bütçe ve Süreç</div>
            <div class="sektorel-row">
                <div class="sektorel-field">
                    <label for="budget_string">Bütçe Bilgisi</label>
                    <input type="text" id="budget_string" name="budget_string" value="<?php echo esc_attr($val('budget_string')); ?>" placeholder="Örn: Teklif Usulü, 50.000 TL" />
                </div>
                <div class="sektorel-field">
                    <label for="expiry_date">Son Geçerlilik Tarihi</label>
                    <input type="date" id="expiry_date" name="expiry_date" value="<?php echo esc_attr($val('expiry_date')); ?>" />
                </div>
            </div>

            <!-- Lokasyon ve Detay -->
            <div class="sektorel-section-title">Lokasyon ve Detaylar</div>
            <div class="sektorel-field">
                <label for="delivery_location">Teslimat / Hizmet Yeri</label>
                <input type="text" id="delivery_location" name="delivery_location" value="<?php echo esc_attr($val('delivery_location')); ?>" placeholder="Örn: Torbalı OSB, İzmir" />
            </div>
            
            <div class="sektorel-field">
                <label for="attachment">Dosya / Görsel Eki (URL)</label>
                <input type="text" id="attachment" name="attachment" value="<?php echo esc_attr($val('attachment')); ?>" />
                <p class="description">Teknik şartname veya ürün görselinin URL adresi.</p>
            </div>

            <!-- Görünürlük Ayarları -->
            <div class="sektorel-section-title">Görünürlük ve İstatistikler</div>
            <div class="sektorel-row">
                <div class="sektorel-field">
                    <label for="is_hidden_name">
                        <input type="checkbox" id="is_hidden_name" name="is_hidden_name" value="1" <?php checked($val('is_hidden_name'), 1); ?> />
                        Gizli Firma (İsim Gösterilmez)
                    </label>
                </div>
                <div class="sektorel-field">
                    <label for="is_premium">
                        <input type="checkbox" id="is_premium" name="is_premium" value="1" <?php checked($val('is_premium'), 1); ?> />
                        Vitrin (Premium) İlanı
                    </label>
                </div>
            </div>

            <div class="sektorel-row">
                <div class="sektorel-field">
                    <label for="view_count">Görüntülenme Sayısı</label>
                    <input type="number" id="view_count" name="view_count" value="<?php echo esc_attr($val('view_count')); ?>" />
                </div>
                <div class="sektorel-field">
                    <label for="offer_count">Gelen Teklif Sayısı</label>
                    <input type="number" id="offer_count" name="offer_count" value="<?php echo esc_attr($val('offer_count')); ?>" />
                </div>
            </div>

        </div>
        <?php
    }

    public static function save_post( $post_id ) {
        if ( ! isset( $_POST['sektorel_lead_nonce'] ) || ! wp_verify_nonce( $_POST['sektorel_lead_nonce'], 'sektorel_lead_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Checkboxlar için özel işlem
        $checkboxes = ['is_hidden_name', 'is_premium'];
        foreach ($checkboxes as $box) {
            $val = isset($_POST[$box]) ? 1 : 0;
            update_post_meta($post_id, $box, $val);
        }

        // Metin alanları
        $fields = ['lead_type', 'status', 'budget_string', 'expiry_date', 'delivery_location', 'attachment', 'view_count', 'offer_count'];
        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, $field, sanitize_text_field( $_POST[ $field ] ) );
            }
        }
    }

    public static function register_graphql_fields() {
        register_graphql_field( 'Lead', 'leadDetails', array(
            'type' => 'LeadDetails',
            'resolve' => function( $post ) {
                $id = $post->ID;
                return array(
                    'leadType'         => get_post_meta( $id, 'lead_type', true ),
                    'status'           => get_post_meta( $id, 'status', true ),
                    'budgetString'     => get_post_meta( $id, 'budget_string', true ),
                    'expiryDate'       => get_post_meta( $id, 'expiry_date', true ),
                    'deliveryLocation' => get_post_meta( $id, 'delivery_location', true ),
                    'attachment'       => get_post_meta( $id, 'attachment', true ),
                    'isHiddenName'     => get_post_meta( $id, 'is_hidden_name', true ) === '1',
                    'isPremium'        => get_post_meta( $id, 'is_premium', true ) === '1',
                    'viewCount'        => (int) get_post_meta( $id, 'view_count', true ),
                    'offerCount'       => (int) get_post_meta( $id, 'offer_count', true ),
                );
            }
        ));

        register_graphql_object_type( 'LeadDetails', array(
            'fields' => array(
                'leadType'         => array( 'type' => 'String' ),
                'status'           => array( 'type' => 'String' ),
                'budgetString'     => array( 'type' => 'String' ),
                'expiryDate'       => array( 'type' => 'String' ),
                'deliveryLocation' => array( 'type' => 'String' ),
                'attachment'       => array( 'type' => 'String' ),
                'isHiddenName'     => array( 'type' => 'Boolean' ),
                'isPremium'        => array( 'type' => 'Boolean' ),
                'viewCount'        => array( 'type' => 'Integer' ),
                'offerCount'       => array( 'type' => 'Integer' ),
            ),
        ));
    }
}