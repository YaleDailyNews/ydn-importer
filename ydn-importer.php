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
define( 'EL_META_PREFIX', 'ydn_legacy_');

class YDN_Importer {

  public $current_site;

  function mongo_connect() {
    if (!isset($this->mongo) ) {
      $this->mongo = new Mongo(MONGODB_IP);
      Db::addConnection($this->mongo, MONGODB_NAME); 
    }
  }

  function show_page() {
    set_time_limit(0);
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
         ) { die('Invalid site, bro.'); }

      $this->start_site_import();
    endif;
      

  }


  function start_site_import() {
    //TODO: SET THE SITE HERE

    #first set some variables
    $legacy_photo_prefix = "/legacy/media/";
    $wp_upload_dir = wp_upload_dir();
    $this->legacy_media_base_path = $wp_upload_dir["basedir"] . $legacy_photo_prefix;
    $this->wp_media_base_url =  $wp_upload_dir["baseurl"] . $legacy_photo_prefix;

    #next run the tasks
    //the ordering of these tasks is NOT arbitrary. Think about cascading dependencies etc very carefully
    $this->mongo_connect();
   # $this->import_galleries();
    $this->import_videos();
   # $this->import_photos(); 

  }

  function import_photos() {
    $this->mongo_connect();
    $photos = Db::find("photo", array("wp_sites" => $this->current_site), array("limit" => 100) );
    foreach ($photos as $el_photo) {
      $this->import_specific_photo( $el_photo );
    }
  }

  function import_specific_photo( $el_photo, $wp_attachment_parent = 0 ) {
    /* specify some defaults for $el_photo */
    $el_photo_defaults = Array(
      'el_photo' => '',
      'el_id' => -1,
      'el_pub_date' => '',
      'el_caption' => '');
    $el_photo = wp_parse_args($el_photo, $el_photo_defaults);

    $wp_attachment = Array(); //container we'll fill in with data

    #Figure out if the file is in the file system. Fetch relevant file info if so
    #If not, grab it from Ellington
    $photo_path = $this->legacy_media_base_path . $el_photo["el_photo"];
    if ( Db::count("media_ents", array("path" => $el_photo["el_photo"]) ) == 1 ) {
      //grab metadata
      $wp_attachment['guid'] = $this->wp_media_base_url . $el_photo["el_photo"];
    } else {
      //attempt to fetch the photo from the Ellington media server
      $el_url = EL_BASE_MEDIA_URL . $el_photo["el_photo"];
      $upload = $this->fetch_remote_file($el_url, $el_photo);
    
      if ( is_wp_error( $upload ) ) {
        printf("Error fetching photo for el_id %d <br>", $el_photo["el_id"]);
        return new WP_Error("import_specific_photo_error", "Error importing photo" );
      }

      $photo_path = $upload['file'];
      $wp_attachment['guid'] = $upload['url'];
    }
    #grab filedata
    
    if ( $info = wp_check_filetype( $photo_path ) ) {
      $wp_attachment['post_mime_type'] = $info['type'];
    } else {
      printf("Error fetching photo mime type for el_id %d <br>", $el_photo["el_id"]);
      return new WP_Error("import_specific_photo_error", "Error importing photo" );
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
    $first_name =  array_key_exists("el_photographer_first_name", $el_photo) ? trim($el_photo["el_photographer_first_name"]) : "";
    $last_name =  array_key_exists("el_photographer_last_name", $el_photo)  ? trim($el_photo["el_photographer_last_name"]) : "";
    $authors = Db::find("wp_user", array("true_user" => false,
                                         "first_name" => $first_name, 
                                         "last_name" =>  $last_name),
                                   array("limit" => 1) );
    if ($authors->count() == 1) {
      $author = $authors->getNext();
      $wp_attachment['post_author'] = $author["wp_id"];
    }
    #insert!
    $wp_attachment_id = wp_insert_attachment( $wp_attachment, $photo_path, $wp_attachment_parent );
    #wp_update_attachment_metadata( $wp_attachment_id, wp_generate_attachment_metadata( $wp_attachment_id, $photo_path ) ); #can't actually read the files

    #if there's no author assigned but there's a one off byline, create it as a meta field
    if (array_key_exists("el_one_off_photographer", $el_photo) && !array_key_exists("post_author", $wp_attachment) ) {
      update_post_meta($wp_attachment_id, MEDIA_CREDIT_POSTMETA_KEY, $el_photo["el_one_off_photographer"] );
    }

    #record the old ellington ID just in case anything ever goes wrong
    update_post_meta( $wp_attachment_id, EL_META_PREFIX . "id", $el_photo["el_id"] );
    
    #send the WP_ID back to mongo
    $el_photo["wp_id"] = $wp_attachment_id;
    Db::save("photo",$el_photo);

    return $wp_attachment_id;
  }

  function generate_wp_username($first_name, $last_name ) {
    $user_login = sprintf("%s %s",$first_name, $last_name);
    $user_login = preg_replace('/\s+/','',$user_login); //get rid of any spaces
    $user_login = iconv("UTF-8","ASCII//TRANSLIT", $user_login); //get rid of any weird non ascii characters if possible
    $user_login = strtolower($user_login); //downcase it

    return $user_login;
  }

  function import_users() {
    $this->mongo_connect();
    $m_users = Db::find("wp_user",array("true_user" => false), array());
    $default_password = wp_generate_password(50,true,true);
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

    
        $wp_user["user_login"] = generate_wp_username($m_user["first_name"], $m_user["last_name"]);

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

      return $wp_id;
    }
  } 

  function import_galleries() {
    #creates the galleries in this site AND imports the photos associated with them.  This will potentially create duplicates
    #in the database, but the problem should be relatively localized.  Run this before import_photos so that we don't accidentally
    #overwrite the wp_ids destined to be linked into the story import
    $this->mongo_connect();

    $showcase_default_opts = Array( "source" => "upload",
                                "source_gallery" => "",
                                "flickr_id" => "",
                                "gallery_layout" => "",
                                "enable_lightbox" => "on",
                                "show_slideshow" => "off",
                                "dim_x" => "",
                                "dim_y" => "",
                                "show_thumb_caption" => "off",
                                "slider_animation" => "fase",
                                "slider_direction" => "horizontal", 
                                "slider_animate_duration" => "300",
                                "slider_slideshow" => "off", 
                                "slider_slideshow_speed" => "7000",
                                "slider_direction_nav" => "on",
                                "slider_control_nav" => "on",
                                "slider_keyboard_nav" => "on", 
                                "slider_prev_text" => "Previous",
                                "slider_next_text" => "Next",
                                "slider_pause_play" => "off",
                                "slider_pause_text" => "Pause",
                                "slider_play_text" => "Play",
                                "slider_random" => "off", 
                                "slider_start_slide" => "0", 
                                "slider_pause_action" => "on",
                                "slider_pause_hover" => "off"
                              );
   $galleries = Db::find("gallery", array("wp_sites" => $this->current_site), array("limit" => 1) );
   foreach ($galleries as $gallery) {
     #first create the showcase post in the WP databse
     $wp_gallery = Array( "post_title" => $gallery["el_name"],
                          "post_status" => "publish",
                          "post_type" => "showcase_gallery",
                        );
     $creation_time = strtotime($gallery['el_creation_date']);
     $wp_gallery['post_date'] = date('Y-m-d H:i:s', $creation_time);
     $wp_gallery["post_date_gmt"] = $wp_gallery["post_date"];

     $wp_gallery_id = wp_insert_post( $wp_gallery );
     update_post_meta( $wp_gallery_id, 'showcase_settings', $showcase_default_opts );

     #save that new ID in the mongo set
     $gallery["wp_id"] = $wp_gallery_id;
     Db::save("gallery",$gallery);

     #loop through the member photos and import them into the gallery 
     $gallery_photos = Db::find("galleryphoto", array("el_gallery_id" => $gallery["el_id"] ), array() );
     foreach ($gallery_photos as $gallery_photo) {
         //for some reason, the $gallery_photo object isn't actually the photo, but contains a pointer to it.
         //find the real record.
        $photo_record = Db::find("photo", array("el_id" => $gallery_photo["el_photo_id"] ), array("limit" => 1) ); 
        $photo_record = $photo_record->getNext();

        #now actually import and wire everything up
        $gallery_photo_id = $this->import_specific_photo($photo_record, $wp_gallery_id);

        if ( is_wp_error( $gallery_photo_id ) ) { continue; }

        $meta = wp_get_attachment_metadata($gallery_photo_id);
        $meta['wp_showcase'] = array ( "caption" => $photo_record["el_caption"],
                                       "alt" => $photo_record["el_caption"],
                                       "link" => "" );

        wp_update_attachment_metadata( $gallery_photo_id , $meta );
      }
    }

  }

  function import_videos() {
    $videos = Db::find("video", array("wp_site" => $this->current_site ), array("limit" => 1) );
    foreach ( $videos as $el_video ) {
      printf("this is run\n");
      $creation_time = strtotime($el_video["el_creation_date"]);

      #first insert the video post
      $wp_video = array(
         'comment_status' => 'open',
         'post_content' => sprintf("%s\n\n%s", $el_video["el_url"], $el_video["el_caption"]),
         'post_title' => $el_video["el_title"],
         'post_date' => date('Y-m-d H:i:s', $creation_time),
         'post_date_gmt' => date('Y-m-d H:i:s', $creation_time),
         'post_status' => 'publish',
         'post_type' => 'video'
       );

      $wp_post_id = wp_insert_post($wp_video);
    }

  }

  function register_author_for_post($post_id, $author_username) {

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
