<?php
/*
Plugin Name:YDN Importer
Plugin URI: http://yaledailynews.com
Description: Import posts, pages, comments, custom fields, categories, tags and more from a WordPress export file.
Author: Michael DiScala
Author URI: http://michaeldiscala.com/
Version: 0.1
*/

/** Display verbose errors */
define( 'IMPORT_DEBUG', true );
define( 'WP_IMPORTING', true );

class YDN_Importer {
  function show_page() {
    echo "YAY";
  } 
 
};

function ydn_importer_init() {
    $GLOBALS['ydn_importer'] = new YDN_Importer();

    add_menu_page( "YDN Importer", "YDN Importer", "update_core", 
                   "ydn-importer", array($GLOBALS['ydn_importer'], 'show_page' ) );
}

add_action( 'admin_menu', 'ydn_importer_init');
?>
