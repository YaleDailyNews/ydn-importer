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
include('simplemongophp/Db.php');

define( 'MONGODB_NAME', 'ydn_working');
define( 'MONGODB_IP', "mongodb://50.116.62.82");
define( 'IMPORT_DEBUG', true );
define( 'WP_IMPORTING', true );
define( 'EL_BASE_MEDIA_URL', 'http://yaledailynews.media.clients.ellingtoncms.com/');

class YDN_Importer {

  public $current_site;

  function mongo_connect() {
    if (!isset($this->mongo) ) {
      $this->mongo = new Mongo(MONGODB_IP);
      Db::addConnection($this->mongo, MONGODB_NAME); 
    }
  }

  function show_page() {
    if ( ! isset( $_GET["target_site"] ) ):
   ?>Load this page with ?target_site=SLUG to import a specific website. Load with ?target_site=users to import users.<?
    elseif ( $_GET["target_site"] == "users" ):
      $this->import_users();
    else:
      $this->current_site = $_GET["target_site"];
      if ($this->current_site != "weekend" &&
          $this->current_site != "main" &&
          $this->current_site != "cross_campus" &&
          $this->current_site != "magazine"
         ) { die('Invalid site bro.'); }

      $this->start_site_import();
    endif;
      

  }


  function start_site_import() {
    //TODO: SET THE SITE HERE
    $this->mongo_connect();
    $this->import_photos(); 
  }

  function import_photos() {
    $this->mongo_connect();
    $photos = Db::find("photo", array("wp_sites" => $this->current_site), array("limit" => 1) );

    $legacy_base_path = wp_upload_dir();
    $legacy_base_path  = $legacy_base_path["basedir"] . "/legacy/";

    foreach ($photos as $el_photo) {
      $wp_attachment = Array(); //container we'll fill in with data

      #Figure out if the file is in the file system. Fetch relevant file info if so
      #If not, grab it from Ellington
      $photo_path = $legacy_base_path . $el_photo["el_photo"];
      if ( file_exists($photo_path) ) {
        //grab metadata
        printf("good. we found the path");
      } else {
        //attempt to fetch the photo from the Ellington media server
        $el_url = EL_BASE_MEDIA_URL . $el_photo["el_photo"];
        $upload = $this->fetch_remote_file($el_url, $el_photo);
      
        if ( is_wp_error( $upload ) ) {
          printf("Error fetching photo for el_id %d \n", $el_photo["el_id"]);
          continue;
        }

        $photo_path = $upload['file'];
        $wp_attachment['guid'] = $upload['url'];
      }
      #grab filedata
      
      if ( $info = wp_check_filetype( $photo_path ) ) {
        $wp_attachment['post_mime_type'] = $info['type'];
      } else {
        printf("Error fetching photo mime type for el_id %d \n", $el_photo["el_id"]);
        continue;
      }

      #extract photo name for title
      $post_title = basename( $el_photo['el_photo'] );
      $post_title = explode(".",$post_title);
      if ( !isset( $post_title[0] ) ) {
        $post_title = '';
      } else {
        $wp_attachment['post_title']  = $post_title[0];
      }

      #fill in required fields
      $wp_attachment['post_content'] = '';
      $wp_attachment['post_status'] = 'publish';

      $upload_time = strtotime($el_photo['el_pub_date']);
      $wp_attachment['post_date'] = date('Y-m-d H:i:s', $upload_time);
      $wp_attachment['post_date_gmt'] = $wp_attachment['post_date'];

      $wp_attachment['post_excerpt'] = $el_photo['el_caption'];

      #attempt to find an author in the users table 
      $authors = Db::find("wp_user", array("true_user" => "false",
                                           "first_name" => $el_photo["el_photographer_first_name"],
                                           "last_name" => $el_photo["el_photographer_last_name"]),
                                     array("limit" -> 1) );
      

      #insert!
      $wp_attachment_id = wp_insert_attachment( $wp_attachment, $photo_path );
      wp_update_attachment_metadata( $wp_attachment_id, wp_generate_attachment_metadata( $wp_attachment_id, $photo_path ) );

      #if we now have a file, use wp_insert_attachment on it
      #update $photo in mongo with the wp_id

    }
  }

  function import_users() {
    $this->mongo_connect();
    $m_users = Db::find("wp_user",array(), array("limit" => 3000));
    $default_password = wp_generate_password(100,true,true);
    foreach ($m_users as $m_user) {
      $wp_user = array();
      if ( $m_user["true_user"] == 1 ) {
        //import procedure for users from our current users database
        $wp_user["user_login"] = $m_user["user_login"];
        $wp_user["user_email"] = $m_user["user_email"];
        $wp_user["first_name"] = $m_user["first_name"];
        $wp_user["last_name"] = $m_user["last_name"];
        $wp_user["user_registered"] = $m_user["user_registered"];
        $wp_user["role"] = "subscriber";
      } else {
        //build users for the authors
        $wp_user["display_name"] = sprintf("%s %s",$m_user["first_name"], $m_user["last_name"]);

        $user_login = $wp_user["display_name"];
        $user_login = preg_replace('/\s+/','',$user_login); //get rid of any spaces
        $user_login = iconv("UTF-8","ASCII//TRANSLIT", $user_login); //get rid of any weird non ascii characters if possible
        $user_login = strtolower($user_login); //downcase it
        $wp_user["user_login"] = $user_login;

        $wp_user["first_name"] = $m_user["first_name"];
        $wp_user["last_name"] = $m_user["last_name"];

        $wp_user["role"] = "author";
        printf("working on %s <br>",$user_login);
      }

      //these passwords wont be used until the user logs in, triggering their legacy password to be converted
      //into one of these
      $wp_user["user_pass"] = $default_password; 

      //both types of users have to get inserted into the WP database and then send their
      //ID back to mongo
      if ( username_exists( $wp_user["user_login"] )) { 
        printf("Error creating user (name collision):%s<br>", $wp_user['user_login']);
        continue;
      }

      $wp_id = wp_insert_user($wp_user);

      //add password meta if necessary
      if ($m_user["true_user"] && $m_user["legacy_password"] != "" ) {
        add_user_meta($wp_id, "ydn_legacy_password", $m_user["legacy_password"], true);
      }

      //send ID back to mongo
      $m_user["wp_id"] = $wp_id;
      Db::save("wp_user",$m_user);
    }
  } 

	/**
	 * Attempt to download a remote file attachment
	 *
	 * @param string $url URL of item to fetch
	 * @param array $post Attachment details
	 * @return array|WP_Error Local file location details on success, WP_Error otherwise
	 */
	function fetch_remote_file( $url, $mongo_rec ) {
		// extract the file name and extension from the url
		$file_name =  basename( $url );

		// get placeholder file in the upload dir with a unique, sanitized filename
    $upload_date = date("Y/m", strtotime($mongo_rec['el_pub_date']));
		$upload = wp_upload_bits( $file_name, 0, '', $upload_date);
		if ( $upload['error'] )
			return new WP_Error( 'upload_dir_error', $upload['error'] );

		// fetch the remote url and write it to the placeholder file
		$headers = wp_get_http( $url, $upload['file'] );

		// request failed
		if ( ! $headers ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Remote server did not respond', 'wordpress-importer') );
		}

		// make sure the fetch was successful
		if ( $headers['response'] != '200' ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', sprintf( __('Remote server returned error response %1$d %2$s', 'wordpress-importer'), esc_html($headers['response']), get_status_header_desc($headers['response']) ) );
		}

		$filesize = filesize( $upload['file'] );

		if ( isset( $headers['content-length'] ) && $filesize != $headers['content-length'] ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Remote file is incorrect size', 'wordpress-importer') );
		}

		if ( 0 == $filesize ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Zero size file downloaded', 'wordpress-importer') );
		}

		return $upload;
	}

 
};


function ydn_importer_init() {
    $GLOBALS['ydn_importer'] = new YDN_Importer();

    add_menu_page( "YDN Importer", "YDN Importer", "update_core", 
                   "ydn-importer", array($GLOBALS['ydn_importer'], 'show_page' ) );
}

add_action( 'admin_menu', 'ydn_importer_init');
?>
