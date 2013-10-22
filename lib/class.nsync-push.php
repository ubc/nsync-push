<?php 


class Nsync_Push {
	// 
	static $settings = array();
	static $default_settings = array();
	static $currently_publishing = false;
	static $post_from = array();
	static $previous_from = null;
	
	public static function admin_init() {
		self::$settings = get_option( 'nsync_options' );
		self::$default_settings = get_option('nsync_default');
		
		// register scipts and styles
		register_setting(
		'writing', // settings page
		'nsync_default', // option name
		array( 'Nsync_Push', 'validate') // validation callback
		);
		
		add_settings_field(
		'nsync_default', // id
		'Default Network Sites Published To', // setting title
		array( 'Nsync_Push', 'add_network_sites'), // display callback
		'writing', // settings page
		'remote_publishing' // settings section
		);
		
		// register scipts and styles
		wp_register_script( 'nsync-post-to', NSYNC_PUSH_DIR_URL."/js/post-to.js", array( 'jquery', 'hoverIntent' ), '1.0', true );
		wp_register_style( 'nsync-post-to', NSYNC_PUSH_DIR_URL."/css/post-to.css", null, '1.0', 'screen' );
		
	}
	
	/* SETTINGS */
	public static function add_network_sites() {
		global $current_user;
		$post_to = get_option( 'nsync_post_to' );
		$current_blog_id = get_current_blog_id();
		if (empty($post_to)) {
			$post_to = array();
		}

		?>
		<div id="select-default-site">
		<?php
		if (!empty($post_to)) {
			foreach ($post_to as $blog_id) {
				$blog = get_blog_details($blog_id);
				$is_checked = false;
				if (!empty(Nsync_Push::$default_settings['sites']) ) {
					$is_checked = in_array($blog_id, Nsync_Push::$default_settings['sites']);
				}
			?>
				<label>
					<input name="nsync_default[sites][]" type="checkbox" value="<?php echo esc_attr( $blog_id); ?>" <?php checked($is_checked);?>/> <?php echo $blog->blogname;?> <small><?php echo $blog->siteurl;?></small>
				</label><br />
			<?php 
			}
		} else {
			echo 'No sites have allowed you to publish to their site.';
		}
		?>
		</div>
		<?php 
	}
	
	public static function post_to_script_n_style() {
		
		wp_enqueue_script( 'nsync-post-to' );
		wp_enqueue_style( 'nsync-post-to' );
		
	}
	
	
	/* POST SIDE */
	public static function user_select_site() {
		global $post;
		$post_to = get_option( 'nsync_post_to' );
		$current_blog_id = get_current_blog_id();
		$last_published_to = get_post_meta($post->ID, '_nsync_last_published_to', true);

		// lets make sure that the profile 
		if( !defined( 'NSYNC_DIR_PATH' ) ):
			self::$post_from = get_post_meta( $post->ID, '_nsync-from', true);
		
			if( !empty(self::$post_from) ) :
				$bloginfo = get_blog_details( array( 'blog_id' => self::$post_from['blog'] ) ); 
				$post_from_url = esc_url($bloginfo->siteurl) . '/?p=' . self::$post_from['post_id'];	
			?>
				<div class="misc-pub-section" id="shell-site-to-post">Originally posted on:
					<em><?php echo $bloginfo->blogname; ?></em> <a href="<?php echo $post_from_url; ?>">view post</a>
				</div>
			<?php
			endif;
		endif;

		if( empty(self::$post_from) ):

			// change this line if you also want to effect pages or other post types. 
			if( is_array( $post_to ) && $post->post_type == 'post' && !empty($post_to) ):
				
				// double check if this  really the case
				$new_post_to = array();
				
				$previous_to = get_post_meta( $post->ID, '_nsync-to', false );
				$site_display = null;
				foreach( $post_to as $blog_id ): 
						switch_to_blog( $blog_id );
						$option =	get_option( 'nsync_options' );
						
						$skip = true;
						
						if( is_array($option['active']) && in_array( $current_blog_id, $option['active'] ) ):
							$new_post_to[] = $blog_id;
							$skip = false;
						endif;
						
						restore_current_blog();
						
						if( !$skip ):
							$blog = get_blog_details( array( 'blog_id' => $blog_id ) );
							$blogs[] = $blog;
							$site_display[] = $blog->blogname;
						endif;	
				endforeach;
				$diff = array_diff ( $new_post_to , $post_to );
				
				if( ! empty( $diff ) ):
					update_option( 'nsync_post_to', $new_post_to );
				endif;
				
				if( is_array($blogs) ):
					$default_sites = get_option('nsync_default');
					//make sure that it doesn't break other things
				?>
				<div class="misc-pub-section" id="shell-site-to-post">
					
					<label >Also publish to:</label>
					
					<span id="site-display"> <?php echo ( is_array( $site_display ) ? '<strong>'.implode( $site_display, ",") . '</strong>': " (select a site)"); ?></span>
					
					<div id="site-to-post" class="hide-if-js">
						<?php 
							foreach( $blogs as $blog ):
							$is_checked = false;
							//ok, if new post determine by if new post or not
							if (Nsync_Push::_is_edit_or_new_post('new')) {
								if (!empty($default_sites)) {
									$is_checked = in_array($blog->blog_id, $default_sites['sites']);
								}
							} else if (Nsync_Push::_is_edit_or_new_post('edit')) {
								if (!empty($last_published_to)) {
									$is_checked = in_array($blog->blog_id, $last_published_to);
								}
							}
						?>
						<label><input type="checkbox" name="nsync_post_to[]" value="<?php echo esc_attr($blog->blog_id); ?>" <?php echo checked( $is_checked ); ?> alt="<?php echo esc_attr( $blog->blogname);?>" /> <?php echo $blog->blogname;?> <small><?php echo $blog->siteurl;?></small></label><br>
						<?php endforeach; ?>
						<span id='goto_nsync_settings'><a href="<?php echo admin_url().'options-writing.php'; ?>">Settings</a></span>
						<br>
					</div>
					
				</div>
				<?php 
			    wp_nonce_field( 'nsync' , 'nsync_noncename' , false );
			    endif; // end of blogs check
			endif;
		endif;
	}
	
	public static function validate($input) {
		$default_sites = array();
		if (isset($input['sites']) && is_array($input['sites'])) {
			//check for unique ids to potentially save checks
			$default_sites['sites'] = array_unique($input['sites']);
		} else {
			return $input;
		}

		//check that the ids are from the list in 'nsync_post_to'
		$post_to = get_option('nsync_post_to');
		$default_sites['sites'] = array_intersect($post_to, $default_sites['sites']);
		
		Nsync_Push::$default_settings = $default_sites;
		
		return $default_sites;
	}
	
	/**
	 * determines whether it is a new post or editing post
	 * 
	 * @param string $type valid values are "edit","new"
	 * @return int
	 */
	private static function _is_edit_or_new_post($type) {
		$current_url = parse_url($_SERVER['REQUEST_URI']);
		switch ($type) {
			case 'edit':
				return preg_match('/post\.php$/', $current_url['path']);
				break;
			case 'new':
				return preg_match('/post-new\.php$/', $current_url['path']);
				break;
		}
		return 0;
	}	
}

