<?php 


class Nsync_Push {
	// 
	static $settings = array();
	static $currently_publishing = false;
	static $post_from = array();
	static $previous_from = null;
	
	public static function admin_init() {
		//
		self::$settings = get_option( 'nsync_options' );
		
		// register scipts and styles
		
		wp_register_script( 'nsync-post-to', NSYNC_PUSH_DIR_URL."/js/post-to.js", array( 'jquery', 'hoverIntent' ), '1.0', true );
		wp_register_style( 'nsync-post-to', NSYNC_PUSH_DIR_URL."/css/post-to.css", null, '1.0', 'screen' );
		
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
		
		// lets make sure that the profile 
		if( !defined( 'NSYNC_DIR_PATH' ) ):
			self::$post_from = get_post_meta( $post->ID, '_nsync-from', true);
		
			if( !empty(self::$post_from) ) :
				$bloginfo = get_blog_details( array( 'blog_id' => self::$post_from['blog'] ) ); ?>
				<div class="misc-pub-section" id="shell-site-to-post">Originally posted on:
					<em><?php echo $bloginfo->blogname; ?></em> <a href="<?php echo esc_url( $bloginfo->siteurl ); ?>'/?p=<?php self::$post_from['post_id']; ?>">view post</a>
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
							
							if( isset( $previous_to[0][$blog_id] ) )
								$site_diplay[] = $blog->blogname;
						endif;	
				endforeach;
				$diff = array_diff ( $new_post_to , $post_to );
				
				if( ! empty( $diff ) ):
					update_option( 'nsync_post_to', $new_post_to );
				endif;
				
				if( is_array($blogs) ):
				?>
				<div class="misc-pub-section" id="shell-site-to-post">
					
					<label >Also publish to:</label>
					
					<span id="site-display"> <?php echo ( is_array( $site_diplay ) ? '<strong>'. implode( $site_diplay, ",") . '</strong>': " (select a site)"); ?></span>
					
					<div id="site-to-post" class="hide-if-js">
						<?php foreach( $blogs as $blog ): ?>
						<label><input type="checkbox" name="nsync_post_to[]" value="<?php echo esc_attr($blog->blog_id); ?>" <?php echo checked( (bool)$previous_to[0][ $blog->blog_id] ); ?> alt="<?php echo esc_attr( $blog->blogname);?>" /> <?php echo $blog->blogname;?> <small><?php echo $blog->siteurl;?></small></label>
						<?php endforeach; ?>
					</div>
				</div>
				<?php 
			    wp_nonce_field( 'nsync' , 'nsync_noncename' , false );
			    endif; // end of blogs check
			endif;
		endif;
	}
}

