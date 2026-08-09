<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Source_Import_Header_Fix {

    const NONCE_ACTION       = 'sektorel_event_source_import';
    const MAX_FILE_SIZE      = 5242880;
    const MAX_XML_ENTRY_SIZE = 10485760;
    const XLSX_NS            = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public static function init() {
        remove_action(
            'wp_ajax_sektorel_event_source_prepare_import',
            array( 'Sektorel_Event_Source_Importer_Fixed', 'ajax_prepare_import' )
        );

        add_action(
            'wp_ajax_sektorel_event_source_prepare_import',
            array( __CLASS__, 'ajax_prepare_import' )
        );
    }

    public static function ajax_prepare_import() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'], $_FILES['file']['name'] ) ) {
            wp_send_json_error( array( 'message' => 'Dosya alınamadı.' ) );
        }

        $file  = $_FILES['file'];
        $error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

        if ( UPLOAD_ERR_OK !== $error ) {
            wp_send_json_error( array( 'message' => 'Dosya yükleme hatası: ' . $error ) );
        }

        $size = isset( $file['size'] ) ? (int) $file['size'] : 0;
        if ( $size <= 0 || $size > self::MAX_FILE_SIZE ) {
            wp_send_json_error( array( 'message' => 'Dosya boş veya 5 MB sınırını aşıyor.' ) );
        }

        $name      = sanitize_file_name( wp_unslash( $file['name'] ) );
        $extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Yalnızca .xlsx ve .csv dosyaları desteklenir.' ) );
        }

        $tmp_name = (string) $file['tmp_name'];
        $rows     = 'xlsx' === $extension ? self::parse_xlsx( $tmp_name ) : self::parse_csv( $tmp_name );

        if ( is_wp_error( $rows ) ) {
            wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
        }

        $normalized = self::normalize_rows( $rows );
        if ( is_wp_error( $normalized ) ) {
            wp_send_json_error( array( 'message' => $normalized->get_error_message() ) );
        }

        if ( empty( $normalized ) ) {
            wp_send_json_error( array( 'message' => 'Dosyada veri satırı bulunamadı.' ) );
        }

        $user_id = get_current_user_id();
        $token   = strtolower( wp_generate_password( 24, false, false ) );
        $key     = 'sektorel_src_imp_' . absint( $user_id ) . '_' . sanitize_key( $token );

        set_transient( $key, $normalized, HOUR_IN_SECONDS );

        wp_send_json_success(
            array(
                'token' => $token,
                'total' => count( $normalized ),
            )
        );
    }

    private static function parse_csv( $path ) {
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'csv_open_failed', 'CSV dosyası açılamadı.' );
        }

        $first_line = fgets( $handle );
        if ( false === $first_line ) {
            fclose( $handle );
            return new WP_Error( 'csv_empty', 'CSV dosyası boş.' );
        }

        $delimiter = self::detect_delimiter( $first_line );
        rewind( $handle );

        $rows = array();
        while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            $rows[] = array_map(
                static function ( $value ) {
                    return is_string( $value ) ? trim( $value ) : $value;
                },
                $row
            );
        }

        fclose( $handle );
        return $rows;
    }

    private static function detect_delimiter( $line ) {
        $candidates = array( ',', ';', "\t" );
        $best       = ',';
        $best_count = -1;

        foreach ( $candidates as $candidate ) {
            $count = substr_count( $line, $candidate );
            if ( $count > $best_count ) {
                $best       = $candidate;
                $best_count = $count;
            }
        }

        return $best;
    }

    private static function parse_xlsx( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'xlsx_zip_missing', 'Sunucuda ZIP desteği bulunmadığı için XLSX okunamıyor. Dosyayı CSV olarak kaydedip tekrar yükleyin.' );
        }

        if ( ! function_exists( 'simplexml_load_string' ) ) {
            return new WP_Error( 'xlsx_xml_missing', 'Sunucuda SimpleXML desteği bulunmadığı için XLSX okunamıyor. Dosyayı CSV olarak kaydedip tekrar yükleyin.' );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            return new WP_Error( 'xlsx_open_failed', 'XLSX dosyası açılamadı.' );
        }

        $sheet_stat = $zip->statName( 'xl/worksheets/sheet1.xml' );
        if ( ! is_array( $sheet_stat ) || empty( $sheet_stat['size'] ) || (int) $sheet_stat['size'] > self::MAX_XML_ENTRY_SIZE ) {
            $zip->close();
            return new WP_Error( 'xlsx_sheet_invalid', 'XLSX çalışma sayfası bulunamadı veya güvenli boyut sınırını aşıyor.' );
        }

        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        if ( false === $sheet_xml ) {
            $zip->close();
            return new WP_Error( 'xlsx_sheet_missing', 'XLSX içindeki ilk çalışma sayfası bulunamadı.' );
        }

        $shared_strings = array();
        $shared_stat    = $zip->statName( 'xl/sharedStrings.xml' );

        if ( is_array( $shared_stat ) && ! empty( $shared_stat['size'] ) ) {
            if ( (int) $shared_stat['size'] > self::MAX_XML_ENTRY_SIZE ) {
                $zip->close();
                return new WP_Error( 'xlsx_strings_too_large', 'XLSX metin tablosu güvenli boyut sınırını aşıyor.' );
            }

            $shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
            if ( false !== $shared_xml ) {
                $shared_strings = self::parse_shared_strings( $shared_xml );
            }
        }

        $zip->close();

        if ( is_wp_error( $shared_strings ) ) {
            return $shared_strings;
        }

        $xml = self::load_xml( $sheet_xml );
        if ( is_wp_error( $xml ) ) {
            return $xml;
        }

        $xml->registerXPathNamespace( 'x', self::XLSX_NS );
        $row_nodes = $xml->xpath( '//x:sheetData/x:row' );

        if ( ! is_array( $row_nodes ) || empty( $row_nodes ) ) {
            return new WP_Error( 'xlsx_rows_missing', 'XLSX içinde satır bulunamadı.' );
        }

        $rows = array();

        foreach ( $row_nodes as $row_node ) {
            $row_node->registerXPathNamespace( 'x', self::XLSX_NS );
            $cells = $row_node->xpath( './x:c' );

            if ( ! is_array( $cells ) || empty( $cells ) ) {
                continue;
            }

            $values = array();

            foreach ( $cells as $cell ) {
                $attributes = $cell->attributes();
                $reference  = isset( $attributes['r'] ) ? (string) $attributes['r'] : '';
                $cell_type  = isset( $attributes['t'] ) ? (string) $attributes['t'] : '';
                $column     = self::column_index_from_reference( $reference );

                if ( $column < 0 ) {
                    continue;
                }

                $cell->registerXPathNamespace( 'x', self::XLSX_NS );
                $value = '';

                if ( 'inlineStr' === $cell_type ) {
                    $text_nodes = $cell->xpath( './/x:t' );
                    if ( is_array( $text_nodes ) ) {
                        foreach ( $text_nodes as $text_node ) {
                            $value .= (string) $text_node;
                        }
                    }
                } else {
                    $value_nodes = $cell->xpath( './x:v' );
                    $raw = ( is_array( $value_nodes ) && isset( $value_nodes[0] ) ) ? (string) $value_nodes[0] : '';

                    if ( 's' === $cell_type && '' !== $raw ) {
                        $index = (int) $raw;
                        $value = isset( $shared_strings[ $index ] ) ? $shared_strings[ $index ] : '';
                    } else {
                        $value = $raw;
                    }
                }

                $values[ $column ] = trim( (string) $value );
            }

            if ( empty( $values ) ) {
                continue;
            }

            $max_column = max( array_keys( $values ) );
            $dense      = array_fill( 0, $max_column + 1, '' );

            foreach ( $values as $column => $value ) {
                $dense[ $column ] = $value;
            }

            $rows[] = $dense;
        }

        return $rows;
    }

    private static function parse_shared_strings( $xml_string ) {
        $xml = self::load_xml( $xml_string );
        if ( is_wp_error( $xml ) ) {
            return $xml;
        }

        $xml->registerXPathNamespace( 'x', self::XLSX_NS );
        $items = $xml->xpath( '//x:si' );

        if ( ! is_array( $items ) ) {
            return array();
        }

        $strings = array();

        foreach ( $items as $item ) {
            $item->registerXPathNamespace( 'x', self::XLSX_NS );
            $text  = '';
            $nodes = $item->xpath( './/x:t' );

            if ( is_array( $nodes ) ) {
                foreach ( $nodes as $node ) {
                    $text .= (string) $node;
                }
            }

            $strings[] = $text;
        }

        return $strings;
    }

    private static function load_xml( $xml_string ) {
        $previous = libxml_use_internal_errors( true );
        $xml      = simplexml_load_string( $xml_string, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( false === $xml ) {
            return new WP_Error( 'xlsx_xml_invalid', 'XLSX XML verisi okunamadı.' );
        }

        return $xml;
    }

    private static function column_index_from_reference( $reference ) {
        if ( ! preg_match( '/^([A-Z]+)/i', $reference, $matches ) ) {
            return -1;
        }

        $letters = strtoupper( $matches[1] );
        $index   = 0;
        $length  = strlen( $letters );

        for ( $i = 0; $i < $length; $i++ ) {
            $index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - 64 );
        }

        return $index - 1;
    }

    private static function normalize_rows( $rows ) {
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return new WP_Error( 'source_rows_empty', 'Dosya satırları okunamadı.' );
        }

        $header_row_index = null;
        $title_index      = null;
        $url_index        = null;
        $type_index       = null;
        $scan_limit       = min( 20, count( $rows ) );

        for ( $row_index = 0; $row_index < $scan_limit; $row_index++ ) {
            $headers = array_map( array( __CLASS__, 'normalize_header' ), array_values( $rows[ $row_index ] ) );

            $candidate_title = self::find_header_index(
                $headers,
                array( 'etkinlik ismi', 'etkinlik adı', 'etkinlik adi', 'kaynak', 'başlık', 'baslik' )
            );

            if ( null === $candidate_title ) {
                continue;
            }

            $header_row_index = $row_index;
            $title_index      = $candidate_title;
            $url_index        = self::find_header_index( $headers, array( 'web sitesi', 'website', 'url', 'kaynak url', 'web site' ) );
            $type_index       = self::find_header_index( $headers, array( 'türü', 'turu', 'tür', 'tur', 'etkinlik türü', 'etkinlik turu' ) );
            break;
        }

        if ( null === $header_row_index || null === $title_index ) {
            $preview = array();
            foreach ( array_slice( $rows, 0, 3 ) as $preview_row ) {
                $preview[] = implode( ' | ', array_map( 'strval', array_slice( array_values( $preview_row ), 0, 6 ) ) );
            }

            return new WP_Error(
                'source_header_missing',
                'Etkinlik İsmi başlığı bulunamadı. Okunan ilk satırlar: ' . implode( ' / ', $preview )
            );
        }

        $normalized = array();

        foreach ( array_slice( $rows, $header_row_index + 1 ) as $row ) {
            $title = isset( $row[ $title_index ] ) ? sanitize_text_field( (string) $row[ $title_index ] ) : '';
            if ( ! $title ) {
                continue;
            }

            $raw_url     = null !== $url_index && isset( $row[ $url_index ] ) ? (string) $row[ $url_index ] : '';
            $source_type = null !== $type_index && isset( $row[ $type_index ] ) ? sanitize_text_field( (string) $row[ $type_index ] ) : '';

            $normalized[] = array(
                'title'       => $title,
                'source_url'  => self::normalize_source_url( $raw_url ),
                'source_type' => $source_type,
            );
        }

        return $normalized;
    }

    private static function normalize_header( $value ) {
        $value = trim( (string) $value );
        $value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
        $value = preg_replace( '/\s+/u', ' ', $value );

        // Locale-independent Turkish normalization. This intentionally happens
        // before strtolower/mb_strtolower so capital dotted İ is deterministic.
        $value = strtr(
            $value,
            array(
                'İ' => 'I',
                'ı' => 'i',
                'Ş' => 'S',
                'ş' => 's',
                'Ğ' => 'G',
                'ğ' => 'g',
                'Ü' => 'U',
                'ü' => 'u',
                'Ö' => 'O',
                'ö' => 'o',
                'Ç' => 'C',
                'ç' => 'c',
            )
        );

        return strtolower( $value );
    }

    private static function find_header_index( $headers, $aliases ) {
        foreach ( $aliases as $alias ) {
            $index = array_search( self::normalize_header( $alias ), $headers, true );
            if ( false !== $index ) {
                return $index;
            }
        }

        return null;
    }

    private static function normalize_source_url( $url ) {
        $url = trim( (string) $url );

        if ( ! $url || in_array( $url, array( '-', '–', '—', '−' ), true ) ) {
            return '';
        }

        if ( ! preg_match( '#^https?://#i', $url ) ) {
            $url = 'https://' . ltrim( $url, '/' );
        }

        return esc_url_raw( $url, array( 'http', 'https' ) );
    }
}
