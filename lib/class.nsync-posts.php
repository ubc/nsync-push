<?php

class Nsync_Posts {
	static $currently_publishing = false;
	static $current_blog_id = null;
	static $own_post = null;
	static $remote_post = null;
	static $previous_to = null;	//how holds maximum number of other sites posted to to save their post_id
	static $previous_from = null;
	static $new_categories = array();
	static $new_hierarchical_taxonomies = array();
	static $attachments = null;
	static $current_upload = array();
	static $update_post = false;
	static $replacement = array();
	static $current_attach_data = array();
	static $featured_image = false;
	static $custom_fields = null;
	static $last_publish_to = null;	//holds info on last selected site for post meta
	
	public static function save_postdata( $post_id, $post ) {
	
		// verify if this is an auto save routine. 
		// If it is our form has not been submitted, so we dont want to do anything
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		// Check permissions
		if (isset($_POST['post_type']) && 'post' == $_POST['post_type'] && $post->post_type == 'post'  ){
			if ( !current_user_can( 'edit_post', $post_id ) )
			    return;
		} else {
			return;
		}
		// verify this came from the our screen and with proper authorization,
		// because save_post can be triggered at other times
		if (isset($_POST['nsync_noncename']) && !wp_verify_nonce( $_POST['nsync_noncename'], 'nsync' ) ):
			// delete the connection 
			return;
		endif;
		
		
		// don't go into an infinate loop
		if( self::$currently_publishing )
			return;
		
		self::$current_blog_id = get_current_blog_id();
		
		self::$currently_publishing = true;
		
		// where did we previously created or updated a post, used for making sure that we update the same post
		Nsync_Posts::$previous_to = get_post_meta( $post_id, '_nsync-to', false );
		
		
		// OK, we're authenticated: we need to find and save the data
		if ( isset($_POST['nsync_post_to'])) {
			$blogs_to_post_to = $_POST['nsync_post_to'];
			add_post_meta($post_id, '_nsync_last_published_to', $blogs_to_post_to, true) || update_post_meta($post_id, '_nsync_last_published_to', $blogs_to_post_to);
			
		} else {
			// $nsync_plugin = 'nsync'.DIRECTORY_SEPARATOR.'nsync.php';
			
			// if ( is_plugin_active( $nsync_plugin ) ) {	
			delete_post_meta( $post_id, '_nsync_last_published_to' );		
				// add_post_meta($post_id, '_nsync_last_published_to', array(), true) || update_post_meta($post_id, '_nsync_last_published_to', array() );
			// }
			// also need to trash all external posts before returning
			$trash = Nsync_Posts::_trash_posts( array() );
			
			//show message
			if (!empty($trash)) {
				setcookie( "nsync_trash_".$post_id, serialize ($trash['newly_trashed_ids']), time()+60*5 );
			}
			return;
		}
				
		// we are going to remove stuff from here
		Nsync_Posts::clean_post( $post ); // 
		Nsync_Posts::setup_taxonomies( $post_id );
		Nsync_Posts::setup_attachments( $post_id );
     	Nsync_Posts::setup_post_meta( $post_id );
		
		if( is_array( $blogs_to_post_to ) ):
			
			$from = array( 'blog' => self::$current_blog_id, 'post_id' => $post_id );
			$to = array(); // a list of sites and post that we are publishing to
			Nsync_Posts::$last_publish_to = get_post_meta($post_id, '_nsync_last_published_to', true);
			
			//trash posts 
			$trash = Nsync_Posts::_trash_posts();
			
			//add post
			foreach( $blogs_to_post_to as $blog_id ):

				switch_to_blog( $blog_id ); // save the post on the a different site

				//check if trashed, if so, untrash!
				$other_post_id = isset(Nsync_Posts::$previous_to[0][$blog_id]) ? Nsync_Posts::$previous_to[0][$blog_id] : 0;
				if (!empty($other_post_id)) {
					if ( get_post_status($other_post_id) == 'trash') {
						wp_untrash_post($other_post_id);
					}
				}
			
				unset( $nsync_options );
				$nsync_options = get_option( 'nsync_options' );
				// can I accually post there? 
				
				// double check that the current site accually allows you to publish here
				if( in_array( self::$current_blog_id, $nsync_options['active'] ) ):
					// create the new post
					Nsync_Posts::set_post_id( $blog_id );
					Nsync_Posts::replicate_categories( $nsync_options );
					Nsync_Posts::replicate_hierarchical_taxonomies( $nsync_options );
					Nsync_Posts::set_post_status( $nsync_options, $post );
					
					$new_post_id = Nsync_Posts::insert_post( $nsync_options );			
					Nsync_Posts::replicate_post_meta( $new_post_id  );
					Nsync_Posts::replicate_attachments( $nsync_options, $new_post_id );
					
					if( !is_null( $new_post_id ) && $new_post_id != 0 ):
						$to[ $blog_id ] = $new_post_id;
						// update the post 
						// update the to
						add_post_meta( $new_post_id, '_nsync-from', $from, true );
					endif;
					
				endif;
				
				restore_current_blog(); // revet to the current blog 
			endforeach;
			add_settings_error( 'edit_post', 'the-id', 'hello', 'update' );
			Nsync_Posts::update_nsync_to( $post_id, $to );
			setcookie( "nsync_update_".$post_id, serialize ($to), time()+60*5 ) ;
			setcookie( "nsync_trash_".$post_id, serialize ($trash['newly_trashed_ids']), time()+60*5 ) ;
			endif;
			
	}
	
	public static function clean_post( $post ) {
		
		// remove unwanted things
		unset(
			$post->ID,
			$post->comment_status, 
			$post->ping_status,
			$post->to_ping,
			$post->pinged,
			$post->guid,
			$post->filter,
			$post->ancestors
		);
		
		self::$remote_post = $post;
	}
	public static function clean_attachment( $attachment ) {	
		unset(
			$attachment->ID,
			$attachment->comment_status, 
			$attachment->ping_status,
			$attachment->to_ping,
			$attachment->pinged,
			$attachment->guid,
			$attachment->filter,
			$attachment->post_type
		);
		return $attachment;
	}
	
	public static function path_to_file( $attachment_guid, $upload ) {
		
		$stack = explode( 'files', $attachment_guid  );
		$filename = array_pop( $stack );
	
		return $upload["basedir"].$filename;
	}
	
	public static function copy_file( $attachment_guid ) {
		
		$current_file 	= Nsync_Posts::path_to_file( $attachment_guid, self::$current_upload );
		$new_file 		= Nsync_Posts::path_to_file( $attachment_guid, wp_upload_dir() );
		
		if( file_exists($current_file) && is_file($current_file) ):
			// copy the file
			if( copy( $current_file, $new_file ) )
				return $new_file;
			else
				return false;
		endif;
		return false;
	}
	public static function setup_taxonomies( $post_id ) {
	
		$taxonomies = apply_filters( 'nsync_setup_taxonomies', array( 'category', 'post_tag' , 'post_format' ) );
		
		
		/* $args= array(
		  'public'   => true,
		  '_builtin' => false,
		  'show_ui'  => true,
		  'object_type' => array('post')
		);  
		$tax = get_taxonomies('','names'); 
		var_dump($tax); */
		
		$terms = wp_get_object_terms( $post_id, $taxonomies  );
		$new_terms = array();
		
		foreach( $terms as $term ):
			
			if($term->taxonomy == 'category'):
				self::$new_categories[] = $term; // categories neews to have 
			elseif( is_taxonomy_hierarchical( $term->taxonomy ) ):
				self::$new_hierarchical_taxonomies[$term->taxonomy][] = $term->name;
			else:
				$new_terms[$term->taxonomy][] = $term->name;
			endif;
		endforeach;
	
		
		self::$remote_post->tax_input = $new_terms;
	}
	
	public static function setup_attachments( $post_id ) {
		$args = array(
			'numberposts' => -1,
			'order'=> 'DESC',
			'post_parent' => $post_id,
			'post_type' => 'attachment'
		);

	self::$attachments = Nsync_Posts::get_attachments( $post_id );  //returns Array ( [$image_ID]... 
	self::$current_upload = wp_upload_dir();
	self::$featured_image = get_post_thumbnail_id( $post_id  );
	
	$featured_image_not_found = true;
	
	foreach( self::$attachments as $attach ):
		self::$current_attach_data[$attach->ID] = wp_get_attachment_metadata( $attach->ID );
		
		if( self::$featured_image == $attach->ID )
			$featured_image_not_found = false;
 	endforeach;
 	
 	if( $featured_image_not_found &&  self::$featured_image ):
 		self::$current_attach_data[self::$featured_image] = wp_get_attachment_metadata( self::$featured_image );
 		self::$attachments[] = get_post( self::$featured_image );
	endif;
	 
	
	
	}
	
	
	
	public static function get_attachments( $post_id ) {
		$args = array(
			'numberposts' => -1,
			'order'=> 'DESC',
			'post_parent' => $post_id,
			'post_type' => 'attachment'
		);

		return get_children( $args );  //returns Array ( [$image_ID]... 
	}
	
	public static function set_post_id( $blog_id ) {
		
		if( isset( self::$previous_to[0][$blog_id] ) ):
			self::$remote_post->ID = (int)self::$previous_to[0][$blog_id]; // we are updating the post 
			self::$update_post = true;
		else:
			unset( self::$remote_post->ID ); // this is going to be a brand new post 
			self::$update_post = false;
		endif;
	}
	
	public static function replicate_categories( $nsync_options ) {
		$new_category_ids = array();	//to hold ids of newly create categories!
		$existing_category_slugs = array();	//array of destination slugs and term_ids to compare against
		$current_blogs_category = get_categories(array('hide_empty'=> 0));
		$include_new_cats_tags = isset($nsync_options['include_new_cats_tags'])? $nsync_options['include_new_cats_tags'] : false;
		
		//create term_id => name array
		foreach ($current_blogs_category as $cat) {
			$existing_category_slugs[$cat->term_id] = $cat->slug;
		}
		
		foreach( self::$new_categories as $new_category ):
			if (!isset($include_new_cats_tags) || empty($include_new_cats_tags)) {
				$new_category_ids[] = wp_create_category( $new_category->name );
			} else {
				//only allow categories to match if they exist in destination's blog
				$found_cat_id = array_search($new_category->slug, $existing_category_slugs);
				if ($found_cat_id) {
					$new_categoriy_ids[] = $found_cat_id;
				}
			}
		endforeach;
		
		// add the post to a specific category
		if( isset( $nsync_options['category'] ) 
			&& $nsync_options['category'] != '-1' 
			&& in_array( $nsync_options['category'], $new_category_ids ) ): 
			$new_categoriy_ds[] = $nsync_options['category'];
			
		endif;
		
		if( !empty( $new_category_ids ) ):
			self::$remote_post->post_category = $new_category_ids;
		endif;
	}
	
	/**
	 * 
	 * @param unknown $nsync_options
	 * @TODO might need to update this function for heirarchical stuff.... but not sure.
	 */
	public static function replicate_hierarchical_taxonomies( $nsync_options ) {
		$new_taxonomy = array();
					
		foreach( self::$new_hierarchical_taxonomies as $taxonomy => $terms ):
			foreach( $terms as $term ):
				$new_term = wp_create_term( $term, $taxonomy );
			$new_taxonomy[$taxonomy] = $new_term["term_id"];
			endforeach;
		endforeach;
		
		self::$remote_post->tax_input = array_merge( self::$remote_post->tax_input, $new_taxonomy );
	}
	
	public static function setup_post_meta( $post_id ) {
		// post meta should be duplicated. 
		// $meta = get_post_meta( $post_id );
		
		
		$fields = get_post_custom( $post_id );
		
		$exclude = array( '_nsync-to', 'nsync-from' );
		foreach( $fields as $key => $values ):
				
			if( $key[0] != '_' && !in_array( $key, $exclude ) )
				self::$custom_fields[$key]  = $values;
		endforeach;
	}
	
	public static function set_post_status( $nsync_options, $post ) {
		
		// overwrite the post status 
		if( isset( $nsync_options['post_status'] ) && $nsync_options['post_status'] != '0' )
			self::$remote_post->post_status = $nsync_options['post_status'];
		else
			self::$remote_post->post_status = $post->post_status;
	}
	
	public static function insert_post( $nsync_options ) {
		
		/*
		echo "<pre>";
		var_dump(self::$remote_post->tax_input);
		echo "</pre>";
		*/
		do_action( 'nsync_before_insert' );
		if( isset( $nsync_options['force_user'] ) &&  $nsync_options['force_user'] ) {
			if( user_can( self::$remote_post->post_author, 'publish_post' ) ) {
				return wp_insert_post( self::$remote_post );
			} else {
				return null;
			}
		} else {	//for the case where we don't set force_user, so anyone can post!
			return wp_insert_post( self::$remote_post );
		}
	}
	
	public static function replicate_post_meta( $new_post_id ) {
		
		if( is_array(self::$custom_fields) ):
		foreach( self::$custom_fields as $key => $values ):
			// delete all the keys first.
			delete_post_meta( $new_post_id, $key );
			
			// re-add them
			foreach( $values as $value ):
				update_post_meta( $new_post_id, $key, $value );
			endforeach;
		endforeach;
		endif;
	
	}
	
	public static function replicate_attachments( $nsync_options, $new_post_id ) {
		
		if( isset( $nsync_options['duplicate_files'] ) &&  $nsync_options['duplicate_files'] ):
			// lets clean up the attacments first though
			if( self::$update_post ){
				$delete_attachements = Nsync_Posts::get_attachments( $post_id );
				if( is_array( $delete_attachements ) ):
					foreach( $delete_attachements as $delete )
						wp_delete_attachment( $delete->ID, true );
				endif;
			}
			
			foreach( self::$attachments as $attachment):
				
				$current_attachment_id = $attachment->ID;
				// copy over the file 
				$filename = Nsync_Posts::copy_file( $attachment->guid );
				$attachment = Nsync_Posts::clean_attachment( $attachment );
				$attach_id = wp_insert_attachment( $attachment, $filename, $new_post_id );
	 			$attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
	 			
	  			wp_update_attachment_metadata( $attach_id,  $attach_data );
	  			Nsync_Posts::update_content( $attach_data, self::$current_attach_data[$current_attachment_id], $new_post_id );
				
				
				// lets set the post_thumbnail 
				if( self::$featured_image == $current_attachment_id )
					set_post_thumbnail( $new_post_id, $attach_id );
				
			endforeach;
		
		endif;
		
	}
	
	public static function update_content( $attach_data, $current_attach_data, $new_post_id ) {
		
		// return;
		$current_url = self::$current_upload['baseurl'];
		
		$remote_url = get_site_url().'/files';
		
		// set to empty array so that we don't worry about anything
		self::$replacement = array();
		self::$replacement['current'] = array();
		self::$replacement['remote'] = array();
		
		self::$replacement['current'][]   = $current_url.'/'.$attach_data['file'];
		self::$replacement['remote'][] = $remote_url.'/'.$attach_data['file'];
		
		if( is_array($current_attach_data['sizes']) ):
			foreach( $current_attach_data['sizes'] as $size => $data ):
			
				if( $attach_data['sizes'][$size]['file'] ):
					self::$replacement['current'][]   = $current_url.'/'.$data['file'];
					self::$replacement['remote'][] = $remote_url.'/'.$attach_data['sizes'][$size]['file'];
				endif;
			endforeach;
		endif;
		// if( self::$replacement['current'] ) not sure if this was supposed to be deleted. 
		
		// wp_delete_post( $postid, true );
		// replace all the string
		$update['ID'] = $new_post_id;
  		$update['post_content'] = str_replace ( self::$replacement['current'] , self::$replacement['remote'] , self::$remote_post->post_content  );
  		if( $update['post_content'] != self::$remote_post->post_content ):
  			
  			// lets erase the last revision because we are about to creat a new one. :$
  			$revisions = wp_get_post_revisions( $new_post_id );
  			$last_one = array_slice($revisions, 0, 3);
  			wp_delete_post( $last_one->ID , true ); 
			
			wp_update_post( $update );
		endif;
		
	}
	
	public static function update_nsync_to( $post_id, $to ) {
		// update the to			
		if( self::$previous_to == null ) 
			add_post_meta( $post_id, '_nsync-to', $to, true );  
		else
			update_post_meta( $post_id, '_nsync-to', $to, self::$previous_to );
	}
	
	public static function update_message( $messages ) {
		global $post;
		
		if( !empty( $_COOKIE['nsync_update_'.$post->ID] )  ):
			// setcookie("nsync_update", null, time()-3600);
			$cookie = unserialize ( $_COOKIE['nsync_update_'.$post->ID] );
			foreach( $cookie as $blog_id => $post_id ):
				$bloginfo = get_blog_details( array( 'blog_id' => $blog_id ) );
				$end[] = '<em>'.$bloginfo->blogname.'</em> <a href="'.esc_url( $bloginfo->siteurl ).'/?p='.$post_id.'">view post</a>';
			endforeach;
			setcookie( 'nsync_update_'.$post->ID, null, time()-3600 );
			$end = " " . implode( ", ",  $end );
		
			$messages['post'][1] .= '. - Also updated post on '. $end;
			$messages['post'][4] .= '. - Also updated post on '. $end;
			$messages['post'][6] .= '. - Also published post on '. $end;
			$messages['post'][7] .= '. - Also saved on '. $end;
			$messages['post'][8] .= '. - Also submitted posted on '. $end;
			$messages['post'][9] .= '. - Also scheduled the post on '. $end;
			$messages['post'][10] .= '. - Also updated draft on '. $end;
		endif;
		
		//add trash messages!
		if( !empty( $_COOKIE['nsync_trash_'.$post->ID] )  ) {
			// setcookie("nsync_update", null, time()-3600);
			$cookie = unserialize ( $_COOKIE['nsync_trash_'.$post->ID] );
			foreach( $cookie as $blog_id => $post_id ):
				$bloginfo_trash = get_blog_details( array( 'blog_id' => $blog_id ) );
				$trash_end[] = '<em>'.$bloginfo_trash->blogname.'</em> <a href="'.esc_url( $bloginfo_trash->siteurl ).'/?p='.$post_id.'">view post</a>';
			endforeach;
			setcookie( 'nsync_trash_'.$post->ID, null, time()-3600 );
			
			if (!empty($trash_end)) {
				$end = " " . implode( ", ",  $trash_end );
			
				$messages['post'][1] .= '. - Also trashed post on '. $end;
				$messages['post'][4] .= '. - Also trashed post on '. $end;
				$messages['post'][6] .= '. - Also trashed post on '. $end;
				$messages['post'][7] .= '. - Also trashed on '. $end;
				$messages['post'][8] .= '. - Also trashed posted on '. $end;
				$messages['post'][9] .= '. - Also trashed the post on '. $end;
				$messages['post'][10] .= '. - Also trashed draft on '. $end;
			}
		}
		
		return $messages;
	}
	
	public static function trash_or_untrash_post( $post_id ) {
		
		if( self::$currently_publishing )
			return;
		
		//updated section due to deprication of wp_get_single_post()
		$blogversion = get_bloginfo('version');
		$post = null;
		if (version_compare($blogversion, '3.5', '<=')) {
			$post = wp_get_single_post($post_id, ARRAY_A);
		} else {
			$post = get_post($post_id);
		}
		
		if( !$post )
			return; 
		
		// lets see if we have any post that this is pushing to 
		// where did we previously created or updated a post… used for making sure that we update the same post
		$previous_to = get_post_meta( $post_id, '_nsync-to', true);
		if( empty( $previous_to ) )
			return;
		
		self::$currently_publishing = true;
		self::$current_blog_id = get_current_blog_id();
		
		foreach( $previous_to as $blog_id => $to_post_id ):
			switch_to_blog( $blog_id ); // save the post on the a different site
				
				if ( $post['post_status'] != 'trash' ):  
					wp_trash_post( $to_post_id );
				else: // lets untrash it
					wp_untrash_post( $to_post_id );
				endif;
			
			restore_current_blog(); // revet to the current blog 
		endforeach;
		
	}
	
	public static function posts_display_sync( $actions, $post ) {
		
		//use last publish to, but massage array to minimize code change
		self::$previous_to = get_post_meta( $post->ID, '_nsync-to', true );
		self::$last_publish_to = get_post_meta( $post->ID, '_nsync_last_published_to', true);
		if (empty(self::$last_publish_to) || empty(self::$previous_to)) {
			return $actions;
		}

		$post_check = array();
		foreach (self::$last_publish_to as $blog_id) {
			$post_check[$blog_id] = self::$previous_to[$blog_id];
		}

		if( !empty( $post_check ) && is_array( $post_check ) ):
			
			foreach( $post_check as $blog_id => $post_id ):
				$bloginfo = get_blog_details( array( 'blog_id' => $blog_id ) );
				$end[] = '<em>'.$bloginfo->blogname.'</em> <a href="'.esc_url( $bloginfo->siteurl ).'/?p='.$post_id.'">view post</a>';
			endforeach;
			
			$end = " " . implode( ", ",  $end );
	
			$actions['sync'] = "Also posted to: ".$end;
		endif;
		if( !defined( 'NSYNC_BASENAME') ):
			// do this if nsync is not present
			unset($end);
			self::$previous_from = get_post_meta( $post->ID, '_nsync-from', true );
			
			if( !empty( self::$previous_from ) && is_array( self::$previous_from ) ):
				$bloginfo = get_blog_details( array( 'blog_id' => self::$previous_from['blog'] ) );
				$end = '<em>'.$bloginfo->blogname.'</em> <a href="'.esc_url( $bloginfo->siteurl ).'/?p='.self::$previous_from['post_id'].'">view post</a>';
				
				$actions['sync'] = "Originally posted on: ".$end;
			endif;
		endif;
		
		return $actions;
	}
	
	/**
	 * modifies single posts to add source template before or after posts
	 * 
	 * @param unknown $content
	 * @return Ambigous <string, unknown>
	 */
	public static function nsync_post_edit_single_post($content) {
		unset( $nsync_options );
		$nsync_options = get_option( 'nsync_options' );

		$return_content = '';
		if (is_single()) {
			$pre_content = '';
			$post_content = '';
			if (isset($nsync_options['source_before']) && $nsync_options['source_before']) {
				$pre_content = Nsync_Posts::process_source_template();
			} 
			if (isset($nsync_options['source_after']) && $nsync_options['source_after']) {
				$post_content = Nsync_Posts::process_source_template();
			} 
			$return_content = $pre_content.$content.$post_content;
		} else {
			$return_content = $content;
		}
		return $return_content;
	}
	
	/**
	 * private function to convert special tags in the source tempate into actual values
	 * - valid values: 
	 *  - - {site permalink}
	 *  - - {post permalink}
	 *  - - {post date}
	 *  - - {post title}
	 *  - - {post author}
	 *  - - {site name}
	 * 
	 * @return string
	 */
	private static function process_source_template() {
		global $post;

		$nsync_options = get_option( 'nsync_options' );
		self::$previous_from = get_post_meta($post->ID, '_nsync-from', true);
		$return_value = $blogname = $post_url = $site_url = '';

		if( !empty( self::$previous_from ) && is_array( self::$previous_from ) ) {
			$bloginfo = get_blog_details(array('blog_id' => self::$previous_from['blog']));
			$blogname = $bloginfo->blogname;
			$post_url = esc_url($bloginfo->siteurl).'/?p='.self::$previous_from['post_id'];
			$site_url = esc_url($bloginfo->siteurl);
		}
		$template = (isset($nsync_options['source_template'])? $nsync_options['source_template'] : "source: <a href='{post permalink}'>{post title}</a>");
		
		//do the {site permalink}
		if (!empty($site_url) && !empty($blogname)) {
			$template = str_ireplace("{site permalink}", $site_url, $template);
		}
		//do the {post permalink}
		if (!empty($post_url) && !empty($post)) {
			$template = str_ireplace("{post permalink}", $post_url, $template);
		}
		//do the {post date}, {post title}, {post author}
		if (!empty($post)) {
	 		$template = str_ireplace("{post date}", $post->post_date, $template);
	 		$template = str_ireplace("{post title}", $post->post_title, $template);
	 		$template = str_ireplace("{post author}", $post->post_author, $template);
		}
		//do the {site name}
		if (!empty($blogname)) {
 			$template = str_ireplace("{site name}", $blogname, $template);
		}
 		
 		return $template;
	}
	
	public static function nsync_post_link( $url, $post, $leavename ) {	
		//check if it's a post from nsync
		$nsync_options = get_option( 'nsync_options' );
		$link_to_source = (isset($nsync_options['link_to_source'])? $nsync_options['link_to_source'] : 0 );
		if (empty($link_to_source) || is_admin()) {
			return $url;
		}
		
		//ok, it's a post, so make permalink show link to original source or current page in blog
		$post_meta = get_post_meta( $post->ID, '_nsync-from', true );
		if (!empty($post_meta) && is_array($post_meta)) {
			$bloginfo = get_blog_details(array('blog_id' => $post_meta['blog']));
			$post_url = esc_url($bloginfo->siteurl).'/?p='.$post_meta['post_id'];
			return $post_url;
		}

		return $url;
	}
	
	public static function nsync_shortlink() {
		global $post;

		$nsync_options = get_option( 'nsync_options' );
		$link_to_source = (isset($nsync_options['link_to_source'])? $nsync_options['link_to_source'] : 0 );
		if (!empty($link_to_source) && is_single($post)) {
			remove_action('wp_head', 'wp_shortlink_wp_head', 10, 1);
			?>
			<link rel="shortlink" href="<?php echo Nsync_Posts::nsync_post_link(get_permalink($post->ID), $post, '')?>">
			 <?php 
		}
	}

	/**
	 * private trashing function on a per post basis in
	 * @param string $force_all
	 */
	private static function _trash_posts($last_post_to = null) {
		//trash posts
		$last_publish_to = (!is_null($last_post_to) ? $last_post_to : Nsync_Posts::$last_publish_to);
		$previous_to_blog_ids = !empty(Nsync_Posts::$previous_to[0]) ? array_keys(Nsync_Posts::$previous_to[0]) : array();
		$trash_blog_ids = array_diff($previous_to_blog_ids, $last_publish_to);
		$trashed_already_ids = array();	//used to store previous trashed ids
		$newly_trashed_ids = array();	//store newly trashed ids, this and $trashed_already_ids used for messages.
		
		foreach ($trash_blog_ids as $blog_id) {
			switch_to_blog($blog_id);
		
			//to be trashed on remote site as
			$other_post_id = isset(Nsync_Posts::$previous_to[0][$blog_id]) ? Nsync_Posts::$previous_to[0][$blog_id] : 0;
			if (!empty($other_post_id)) {
 				if (get_post_status($other_post_id) != 'trash') {
					wp_trash_post($other_post_id);
					$newly_trashed_ids[$blog_id] = $other_post_id;
				} else {
					$trashed_already_ids[$blog_id] = $other_post_id;
				}
			}
		
			restore_current_blog();
		}
		
		//massage return array to look like previous_to format array(blog_id => post_id, ...)
		$formatted_trashed_array = array();
		foreach ($trash_blog_ids as $blog_id) {
			$formatted_trashed_array['all_ids'][$blog_id] = Nsync_Posts::$previous_to[0][$blog_id];
		}
		//adding in detailed meta
		$formatted_trashed_array['alrady_trashed_ids'] = $trashed_already_ids;
		$formatted_trashed_array['newly_trashed_ids'] = $newly_trashed_ids;

		return $formatted_trashed_array;
	}
}