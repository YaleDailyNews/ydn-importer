<?php
/** Display verbose errors */
include('simplemongophp/Db.php');

define( 'MONGODB_NAME', 'ydn_working');
define( 'MONGODB_IP', "mongodb://50.116.62.82");
define( 'IMPORT_DEBUG', true );
define( 'WP_IMPORTING', true );
define( 'EL_BASE_MEDIA_URL', 'http://yaledailynews.media.clients.ellingtoncms.com/');
define( 'EL_META_PREFIX', 'ydn_legacy_');
define( 'SAVEQUERIES', 'false');
define( 'DEFAULT_AUTHOR_ID', 2);

              

class YDN_Importer {

  public $current_site;

  
  function mongo_connect() {
    if (!isset($this->mongo) ) {
      $this->mongo = new Mongo(MONGODB_IP);
      Db::addConnection($this->mongo, MONGODB_NAME); 
    }
  }

  function __construct($target) {
    set_time_limit(0);
    $this->sites_array = array( "main" => 1,
              "cross-campus" => 2,
              "weekend" => 3,
              "magazine" => 4);


    if ( $target == "users" ):
      $this->import_users();
    elseif ( $target == "usersp" ):
      $this->propagate_users(); 
    else:
      if(!array_key_exists($target,$this->sites_array)) {
        die("invalid site\n");
      }

      $this->current_site = $target;
      $this->set_blog($this->sites_array[$target]);
      $this->start_site_import();
      $this->import_cleanup();
    endif;

  }


  function start_site_import() {
    #first set some variables
    $legacy_photo_prefix = "/legacy/media/";
    $wp_upload_dir = wp_upload_dir();
    $this->legacy_media_base_path = $wp_upload_dir["basedir"] . $legacy_photo_prefix;
    $this->wp_media_base_url =  $wp_upload_dir["baseurl"] . $legacy_photo_prefix;

    #next run the tasks
    //the ordering of these tasks is NOT arbitrary. Think about cascading dependencies etc very carefully
    $this->mongo_connect();
    #$this->import_galleries();
    $this->import_videos();
    #$this->import_photos(); 
    #$this->import_stories(); 

  }

  function import_cleanup() {
        wp_cache_flush();
        foreach ( get_taxonomies() as $tax ) {
          delete_option( "{$tax}_children" );
          _get_term_hierarchy( $tax );
        }
  }

  function import_photos() {
    $this->mongo_connect();
    $photos = Db::find("photo", array("wp_sites" => $this->current_site), array() );
    foreach ($photos as $el_photo) {
      $this->import_specific_photo( $el_photo );
    }
  }

  /* Imports the el_photo into the wp_database, returning the WP_id associated with the new photo.
   * If there's a WP_id already associated with the image, then just return that */
  function import_specific_photo( $el_photo, $wp_attachment_parent = 0 ) {
    wp_cache_flush();
    printf("Beginning import of %s\n",$el_photo["el_photo"]);
    if(array_key_exists("wp_id", $el_photo) && !empty($el_photo["wp_id"]) ) {
      //we've already imported this! don't do it again
      return $el_photo["wp_id"];
    }

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
        printf("Error fetching photo for el_id %d \n>", $el_photo["el_id"]);
        return new WP_Error("import_specific_photo_error", "Error importing photo" );
      }

      $photo_path = $upload['file'];
      $wp_attachment['guid'] = $upload['url'];
    }
    #grab filedata
    
    if ( $info = wp_check_filetype( $photo_path ) ) {
      $wp_attachment['post_mime_type'] = $info['type'];
    } else {
      printf("Error fetching photo mime type for el_id %d \n", $el_photo["el_id"]);
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

    printf("Imported %s as %d\n",$el_photo["el_photo"], $wp_attachment_id);
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
    $default_password = wp_hash_password(wp_generate_password(50,true,true));

    //used for adding user to all the blogs
    $site_domain = get_blog_details(1);
    $site_domain = $site_domain->domain;

    foreach ($m_users as $m_user) {
      wp_cache_flush();

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

    
        $wp_user["user_login"] = $this->generate_wp_username($m_user["first_name"], $m_user["last_name"]);

        $wp_user["first_name"] = $m_user["first_name"];
        $wp_user["last_name"] = $m_user["last_name"];

        $wp_user["role"] = "author";
        printf("working on %s \n",$wp_user["user_login"]);
      }

      //these passwords wont be used until the user logs in, triggering their legacy password to be converted
      //into one of these
      $wp_user["user_pass"] = $default_password; 

      //both types of users have to get inserted into the WP database and then send their
      //ID back to mongo
      $old_user = get_user_by('login', $wp_user["user_login"]);
      
      if ($old_user && $wp_user["user_login"] != "yaledailynews") { 
        //perform an update if we have better info
        if ( (empty($old_user->first_name) ||  empty($old_user->last_name)) &&
             (!empty($wp_user["first_name"]) && !empty($wp_user["last_name"]))  ) {
           //if one of f/last name currently in the DB are problematic AND
           //both the first/last name fields in the current record are good,
           //then import
          $wp_user["ID"] = $old_user->ID;
         } else {
               continue;
         }

      }

      //perform a regular insert
      $wp_id = wp_insert_user($wp_user);

      //add password meta if necessary (don't do in update case; true users will never be updated)
      if ($m_user["true_user"] && $m_user["legacy_password"] != "" ) {
        add_user_meta($wp_id, "ydn_legacy_password", $m_user["legacy_password"], true);
      }

      //send ID back to mongo
      $m_user["wp_id"] = $wp_id;


      Db::save("wp_user",$m_user);
    }
  } 

  //loops through WP_user, adding all the users to every blog in the network
  function propagate_users() {
    $this->mongo_connect();
    $m_users = Db::find("wp_user",array("true_user" => false), array());
    for($blog_id = 2; $blog_id <= count($this->sites_array); $blog_id++) {
      printf("Starting site %d\n", $blog_id);
      switch_to_blog($blog_id);
      wp_cache_flush();
      foreach($m_users as $m_user) {
        add_user_to_blog($blog_id, $m_user["wp_id"], "author");
      }
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
   $galleries = Db::find("gallery", array("wp_sites" => $this->current_site), array() );
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

  /***
   * Adds $authors to $post_id in the coauthors_plus taxonomy.
   *
   * $authors is an array of first and last name pairs.  These
   * will be converted into usernames and run through the importer. 
   *
   */
  function register_authors_for_post($post_id, $authors) {
    global $coauthors_plus;
    $coauthors = Array();

    if (empty($authors)) { 
      //default to whatever acct is specified
      wp_update_post(array("post_author" => DEFAULT_AUTHOR_ID, "ID" => $post_id));
      return;
    }
  
    foreach($authors as $author) {
      //generate_wp_username accepts firstname/lastname, but then just concatenates
      $name =  sanitize_user($this->generate_wp_username($author['first_name'],$author['last_name'])); 
      $coauthors[] = $name;
    }

    //coauthors requires an author to be set
    $author = $coauthors[0];
    if( $author ) {
      $author_data = get_user_by( 'login', $author );
      wp_update_post(array("post_author" => $author_data->ID, "ID" => $post_id));
    }
    $coauthors_plus->add_coauthors($post_id, $coauthors);
  }


  function import_videos() {
    $videos = Db::find("video", array("wp_site" => $this->current_site ), array() );
    foreach ( $videos as $el_video ) {
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
      $this->register_authors_for_post($wp_post_id, $el_video["computed_bylines"]);

      if (array_key_exists('wp_categories', $el_video) && !empty($el_video['wp_categories'])) {
        $this->register_categories_for_post($wp_post_id, $el_video['wp_categories']);
      }


     #save that new ID in the mongo set
     $el_video["wp_id"] = $wp_post_id;
     Db::save("video",$el_video);
    }


  }

  /**
   * Imports stories for current site
   */
  function import_stories() {
    $stories = Db::find("story", array("wp_site" => $this->current_site, "el_status" => "1"), array() ); 
    $stories->timeout(0);
    $stories->immortal(true);
    foreach ($stories as $el_story) {
      wp_cache_flush();
      printf("Importing ID %d\n",$el_story["el_id"]);
      $pub_time = strtotime($el_story["el_pub_date"]);
      $update_time = strtotime($el_story["el_update_date"]);
      if ($pub_time >= $update_time) {
        $creation_time = $pub_time; 
      } else {
        $creation_time = $update_time;
      }

      $wp_story = array(
        'comment_status' => 'open',
        'post_content' => $el_story['el_story'],
        'post_date' => date('Y-m-d H:i:s', $creation_time),
        'post_date_gmt' => date('Y-m-d H:i:s', $creation_time),
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_title' => $el_story['el_headline'],
        );

      $this->replace_slideshow_ids($wp_story['post_content']);
      $this->replace_photo_inlines($wp_story['post_content']);

      $wp_post_id = wp_insert_post($wp_story);
      $this->register_authors_for_post($wp_post_id, $el_story["computed_bylines"]);

      if (array_key_exists( 'el_one_off_byline', $el_story) && !empty($el_story['el_one_off_byline'])) {
        #import the one off byline text into the custom field
        add_post_meta($wp_post_id, 'ydn_reporter_type', $el_story['el_one_off_byline'], false);
      }

      if (array_key_exists( 'el_lead_photo_original_id', $el_story) && !empty($el_story['el_lead_photo_original_id'])) {
        $lead_photo = Db::find("photo", array("el_id" => $el_story['el_lead_photo_original_id']), array('limit'=>1) );
        $lead_photo = $lead_photo->getNext();
        if (!empty($lead_photo)) {
          add_post_meta($wp_post_id, '_thumbnail_id',$lead_photo["wp_id"]);
        }
      }

      if (array_key_exists('wp_categories', $el_story) && !empty($el_story['wp_categories'])) {
        $this->register_categories_for_post($wp_post_id, $el_story['wp_categories']);
      }

      if(in_category("staff-columns",$wp_post_id) && array_key_exists("el_subhead",$el_story) && !empty($el_story["el_subhead"])) {
        add_post_meta($wp_post_id, 'ydn_opinion_column', $el_story["el_subhead"]);
      }


      #save that new ID in the mongo set
      $el_story["wp_id"] = $wp_post_id;
      $el_story["wp_url"] = get_permalink($wp_post_id);
      Db::save("story",$el_story);

    }
  }

  /**
   * Replaces ellington IDs in showcase tags with their real equivalents
   *
   * Takes a reference to content so that the replacements can happen against the 
   * real variable
   */
  function replace_slideshow_ids(&$content) {
    $matches = array();
    preg_match_all("[showcase el_id=\"([0-9]+)\"]", $content, $matches);
    if (array_key_exists(1,$matches)) { //1 will be filled with the old IDs if they exist
        $old_text = $matches[0]; //these are the full strings that we matched
        $ids = $matches[1]; //these are the old IDS
        for($index = 0; $index < count($ids); $index++) {
          //lookup the WP id based on the ellington ID
          $gallery = Db::find("gallery", array("el_id" => $ids[$index]), array('limit'=>1) );
          $gallery = $gallery->getNext();
        
          if($gallery) {
            $target = sprintf('/%s/',$old_text[$index]);
            $replacement = sprintf('showcase id="%s"', $gallery["wp_id"]);
            $content = preg_replace($target, $replacement, $content);
          }
        }
    }
  }

  function replace_photo_inlines(&$content) {
     
    $matches = array();
    preg_match_all("/ydn-legacy-photo-inline el_id=\"([0-9]+)\"/", $content, $matches);
    if (array_key_exists(1,$matches)) { //1 will be filled with the old IDs if they exist
        $old_text = $matches[0]; //these are the full strings that we matched
        $ids = $matches[1]; //these are the old IDS
        for($index = 0; $index < count($ids); $index++) {
          //lookup the WP id based on the ellington ID
          $photo = Db::find("photo", array("el_id" => $ids[$index]), array('limit'=>1) );
          $photo = $photo->getNext();
        
          if($photo) {
            $target = sprintf('/%s/',$old_text[$index]);
            $replacement = sprintf('ydn-legacy-photo-inline id="%s"', $photo["wp_id"]);
            $content = preg_replace($target, $replacement, $content);
          }
        }
    }
  }
 
  /**
   * Adds comments for a $post_id
   * 
   * Only adds comments that are both public and visible.
   */

  function add_comments_for_story($post_id) {
    // get all comments from mongo database where :el_object_pk == $post_id
    // 	
  }

  /***
   * Adds $categories to $post_id.
   *
   * $categories are tuples from the data processing script 
   *
   */
  function register_categories_for_post($post_id, $categories) {
    if (!is_array($categories) || empty($categories)) {
      return;
    }

    //categories is a bad name--it can be a category or a tag
    foreach($categories as $obj) {
      if(count($obj) != 3) {
        //malformatted entry
        var_dump($obj);
        printf("malformatted tag. wtf? (see above)\n");
        continue;
      }

      $type = $obj[0];
      $name = $obj[1];
      $site = $obj[2];

      if($site != $this->current_site) { printf("site mismatch"); continue; } 

      if($type == "cat")  {
        $this->register_category_for_post($post_id, $name);
      } else if ($type == "tag") { 
        //register if necessary, add to post
        wp_set_post_tags($post_id, array($name), true);
      } else {
        continue;
      }
    }
  }
  

  //adds name to post id. if name is a hierarchical category (separated by ":")
  //then it builds the parent as well
  function register_category_for_post($post_id, $name) {
      //do the formatting, register if necessary, add to post
        $exploded_name = explode(":", $name);

        //setup the $parent pointer
        if(count($exploded_name) > 1) {
          //make sure it's parent is good, before adding the child
          $parent = $exploded_name[0];
          $parent = $this->get_cat_id($parent, null);
          $name = $exploded_name[1]; //the prefix is the parent, index 1 is the rest of the name
        } else {
          $parent = null;
        }

        //at this point, we're either sure the parent is setup properly,
        //or it's not hierarchical
        $final_terms = array($this->get_cat_id($name, $parent)); //this is the list of terms that will be set at the end

        //loop through current terms, adding them to our final_terms list if they're not uncategorized
        foreach(wp_get_object_terms($post_id, 'category') as $cur_term) {
          if($cur_term->term_id != 1) {
            //if it's not uncategorized keep it
            $final_terms[] = $cur_term->term_id;
          }
        }

        $final_terms = array_map('intval', $final_terms);
        $final_terms = array_unique($final_terms);
        $result = wp_set_object_terms($post_id, $final_terms, 'category'); 
        if(is_wp_error($result)) {
          printf("Error adding category %s to %d\n",$post_id,$name);
        }
  }


  //If the specified category exists, it returns its ID
  //Otherwise, create the category and return its IDs (doesn't create any hierarchy values etc)
  function get_cat_id($name, $parent) {
        $term = term_exists($name, 'category');
        if ($term == 0 || $term == null) {
          //need to create the term
          $term_opts = array('description' => '',
                             'slug' => sanitize_title($name) );
          if($parent != null) { $term_opts['parent'] = $parent; }
          $term =  wp_insert_term( $name, 'category', $term_opts);
        } 
        
        return $term['term_id'];
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

  /*
   * Stolen from the WP_Import class
   */
  function set_blog( $blog_id ) {
		if ( is_numeric( $blog_id ) ) {
			$blog_id = (int) $blog_id;
		} else {
			$blog = 'http://' . preg_replace( '#^https?://#', '', $blog_id );
			if ( ( !$parsed = parse_url( $blog ) ) || empty( $parsed['host'] ) ) {
				fwrite( STDERR, "Error: can not determine blog_id from $blog_id\n" );
				exit();
			}
			if ( empty( $parsed['path'] ) )
				$parsed['path'] = '/';
			$blog = get_blog_details( array( 'domain' => $parsed['host'], 'path' => $parsed['path'] ) );
			if ( !$blog ) {
				fwrite( STDERR, "Error: Could not find blog\n" );
				exit();
			}
			$blog_id = (int) $blog->blog_id;
			// Restore global $current_blog
			global $current_blog;
			$current_blog = $blog;
		}

		if ( function_exists( 'is_multisite' ) ) {
			if ( is_multisite() )
				switch_to_blog( $blog_id );
		}

		return $blog_id;
	}

 
};

?>
