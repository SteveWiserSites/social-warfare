<?php

/**
 * Facebook
 *
 * Class to add a Facebook share button to the available buttons
 *
 * @package   SocialWarfare\Functions\Social-Networks
 * @copyright Copyright (c) 2018, Warfare Plugins, LLC
 * @license   GPL-3.0+
 * @since     1.0.0 | Unknown     | CREATED
 * @since     2.2.4 | 02 MAY 2017 | Refactored functions & updated docblocking
 * @since     3.0.0 | 05 APR 2018 | Rebuilt into a class-based system.
 * @since     3.6.0 | 22 APR 2018 | Removed all Javascript related functions for
 *                                  fetching share counts. This includes:
 *                                      register_cache_processes()
 *                                      add_facebook_footer_hook()
 *                                      print_facebook_script()
 *                                      facebook_shares_update()
 *                                 Shares are now fetched using the same two
 *                                 method process that are used by all other
 *                                 social networks in the plugin.
 *
 */
class SWP_Facebook extends SWP_Social_Network {


	/**
	 * The Magic __construct Method
	 *
	 * This method is used to instantiate the social network object. It does three things.
	 * First it sets the object properties for each network. Then it adds this object to
	 * the globally accessible swp_social_networks array. Finally, it fetches the active
	 * state (does the user have this button turned on?) so that it can be accessed directly
	 * within the object.
	 *
	 * @since  3.0.0 | 06 APR 2018 | Created
	 * @param  none
	 * @return none
	 * @access public
	 *
	 */
	public function __construct() {

		// Update the class properties for this network
		$this->name           = __( 'Facebook','social-warfare' );
		$this->cta            = __( 'Share','social-warfare' );
		$this->key            = 'facebook';
		$this->default        = 'true';


		/**
		 * This will add the authentication module to the options page so that
		 * we can fetch share counts through the authenticated API.
		 *
		 */
		$auth_helper = new SWP_Auth_Helper( $this->key );
		add_filter( 'swp_authorizations', array( $auth_helper, 'add_to_authorizations' ) );


		/**
		 * This will check to see if the user has connected Social Warfare with
		 * Facebook using the oAuth authentication. If so, we'll use the offical
		 * authentication API to fetch share counts. If not, we'll use the open,
		 * unauthenticated API.
		 *
		 */
		$this->access_token = $auth_helper->get_access_token();

		// This is the link that is clicked on to share an article to their network.
		$this->base_share_url = 'https://www.facebook.com/share.php?u=';

		$this->init_social_network();
		$this->register_ajax_cache_callbacks();
		$this->display_access_token_notices();
	}


	/**
	 * Generate the API Share Count Request URL
	 *
	 * @since  1.0.0 | 06 APR 2018 | Created
	 * @since  3.6.0 | 22 APR 2019 | Updated API to v3.2.
	 * @since  4.0.1 | 02 APR 2020 | Added access_token based API call.
	 * @access public
	 * @param  string $url The permalink of the page or post for which to fetch share counts
	 * @return string $request_url The complete URL to be used to access share counts via the API
	 *
	 */
	public function get_api_link( $url ) {


		/**
		 * This will check to see if the user has connected Social Warfare with
		 * Facebook using the oAuth authentication. If so, we'll use the offical
		 * authentication API to fetch share counts. If not, we'll use the open,
		 * unauthenticated API, but we'll do so via the frontend JavaScript call
		 * later on via a different function.
		 *
		 */
		$Authentication_Helper = new SWP_Auth_Helper( $this->key );
		$access_token          = $Authentication_Helper->get_access_token();

		// Check if they have a token and it's not expired.
		if( false !== empty( $access_token ) && 'expired' !== $access_token ) {
			return 'https://graph.facebook.com/v7.0/?id='.$url.'&fields=engagement&access_token=' . $Authentication_Helper->get_access_token();
		}

		// Return 0 as no server side check will be done. We'll check via JS later.
		return 0;
	}


	/**
	 * The parse_api_response() method parses the raw response from the API and
	 * returns the share count as an integer.
	 *
	 * In the case here for Facebook, it will json_decode the response and then
	 * look for and return the $response->og_object->engagement->count property.
	 *
	 * @since  1.0.0 | 06 APR 2018 | Created
	 * @since  3.6.0 | 22 APR 2019 | Updated to parse API v.3.2.
	 * @since  4.0.0 | 03 DEC 2019 | Updated to parse API v.3.2 without token.
	 * @since  4.1.0 | 18 APR 2020 | Updated to parse API v.6.0.
	 * @access public
	 * @param  string  $response The raw response returned from the API request
	 * @return integer The number of shares reported from the API
	 *
	 */
	public function parse_api_response( $response ) {

		// Parse the response into a generic PHP object.
		$response = json_decode( $response );

		// Parse the response to get integers.
		if( !empty( $response->og_object ) && !empty( $response->og_object->engagement ) ) {
			return $response->og_object->engagement->count;
		}



		if( !empty( $response->error ) && $response->error->code == 190 ) {
			SWP_Credential_Helper::store_data('facebook', 'access_token', 'expired' );
			return 0;
		}


		if( !empty( $response->engagement ) ) {
			$activity =
			$response->engagement->reaction_count +
			$response->engagement->comment_count +
			$response->engagement->share_count;
			return $activity;
		}

		// Return 0 if no valid counts were able to be extracted.
		return 0;
	}


	/**
	 * ATTENTION! ATTENTION! ATTENTION! ATTENTION! ATTENTION! ATTENTION! ATTENTION!
	 *
	 * All of the methods below this point are used for the client-side,
	 * Javascript share count fetching. Since Facebook has implemented some
	 * rather rigerous rate limits on their non-authenticated API, many
	 * server-side use cases are reaching these rate limits somewhat rapidly and
	 * spend as much time "down" as they do "up". This results in huge delays to
	 * getting share count numbers.
	 *
	 * As such, we have moved the share counts to the client side and we fetch
	 * those counts via javascript/jQuery. Now, instead of having 100 API hits
	 * being counted against the server's IP address, it will be counted against
	 * 100 different client/browser IP addresses. This should provide a virtually
	 * unlimited access to the non-authenticated API.
	 *
	 * You will also notice that these processes are conditonal on the plugin not
	 * being connected to Facebook. If the user has connected the plugin to Facebook,
	 * then we will simply use the authenticated API instead from the server.
	 *
	 */


	/**
	 * Register Cache Processes
	 *
	 * This method registered the processes that will need to be run during the
	 * cache rebuild process. The new caching class (codenames neo-advanced cache
	 * method) allows us to hook in functions that will run during the cache
	 * rebuild process by hooking into the swp_cache_rebuild hook.
	 *
	 * @since  3.1.0 | 26 JUN 2018 | Created
	 * @param  void
	 * @return void
	 *
	 */
	private function register_ajax_cache_callbacks() {
		if( false === $this->is_active() || ( false !== empty( $this->access_token ) && 'expired' !== $this->access_token ) ) {
			return;
		}

		add_action( 'swp_cache_rebuild', array( $this, 'add_facebook_footer_hook' ), 10, 1 );
		add_action( 'wp_ajax_swp_facebook_shares_update', array( $this, 'facebook_shares_update' ) );
		add_action( 'wp_ajax_nopriv_swp_facebook_shares_update', array( $this, 'facebook_shares_update' ) );
	}


	/**
	 * A function to add the Facebook updater to the footer hook.
	 *
	 * This is a standalone method because we only want to hook into the footer
	 * and display the script during the cache rebuild process.
	 *
	 * @since  3.1.0 | 25 JUN 2018 | Created
	 * @param  void
	 * @return void
	 *
	 */
	public function add_facebook_footer_hook( $post_id ) {
        $this->post_id = $post_id;
		add_action( 'swp_footer_scripts', array( $this, 'print_facebook_script' ) );
	}


	/**
	 * Output the AJAX/JS for updating Facebook share counts.
	 *
	 * @since  3.1.0 | 25 JUN 2018 | Created
	 * @param  void
	 * @return void Output is printed directly to the screen.
	 *
	 */
	public function print_facebook_script( $info ) {

		if ( true === SWP_Utility::get_option( 'recover_shares' ) ) {
			$alternateURL = SWP_Permalink::get_alt_permalink( $this->post_id );
		} else {
			$alternateURL = false;
		}

		$info['footer_output'] .= PHP_EOL .  '
			document.addEventListener("DOMContentLoaded", function() {
				var swpButtonsExist = document.getElementsByClassName( "swp_social_panel" ).length > 0;
				if (swpButtonsExist) {
					swp_admin_ajax = "' . admin_url( 'admin-ajax.php' ) . '";
					swp_post_id=' . (int) $this->post_id . ';
					swp_post_url= "' . get_permalink() . '";
					swp_post_recovery_url = "' . $alternateURL . '";
					socialWarfare.fetchFacebookShares();
				}
			});
		';

		return $info;
	}


	/**
	 * Process the Facebook shares response via admin-ajax.php.
	 *
	 * The object will be instantiated by the Cache_Loader class and it will
	 * then call this method from there.
	 *
	 * @since  3.1.0 | 25 JUN 2018 | Created
	 * @param  void
	 * @return void
	 *
	 */
	public function facebook_shares_update() {
		global $swp_user_options;

		if (!is_numeric( $_POST['share_counts'] ) || !is_numeric( $_POST['post_id'] ) ) {
			echo 'Invalid data types sent to the server. No information processed.';
			wp_die();
		}

		$activity = (int) $_POST['share_counts'];
		$post_id  = (int) $_POST['post_id'];

		$previous_activity = get_post_meta( $post_id, '_facebook_shares', true );

		if ( $activity >= $previous_activity ) {
			echo 'Facebook Shares Updated: ' . $activity;

			delete_post_meta( $post_id, '_facebook_shares' );
			update_post_meta( $post_id, '_facebook_shares', $activity );
			$this->update_total_counts( $post_id );
		} else {
			echo "Facebook share counts not updated. New counts ($activity) is not higher than previously saved counts ($previous_activity)";
		}

		wp_die();
	}

	public function display_access_token_notices() {
		$Authentication_Helper = new SWP_Auth_Helper( $this->key );
		$is_notice_needed      = false;

		// If there is no token.
		if( false === $Authentication_Helper->get_access_token() ) {
			$is_notice_needed = true;
			$notice_key       = 'facebook_not_authenticated';
			$notice_message   = '<b>Notice: Facebook is not authenticated with Social Warfare.</b> We\'ve added the ability to authenticate and connect Social Warfare with Facebook. This allows us access to their official API which we use for collecting more accurate share counts. Just go to the Social Warfare Option Page, select the "Social Identity" tab, then scoll down to the "Social Network Connections" section and get yourself set up now!';
		}

		// If the token is expired.
		if( 'expired' === $Authentication_Helper->get_access_token() ) {
			$is_notice_needed = true;
			$notice_key       = 'fb_token_expired_' . date('MY') ;
			$notice_message   = '<b>Notice: Social Warfare\'s connection with Facebook has expired!</b> This happens by Facebook\'s design every couple of months. To give our plugin access to the most accurate, reliable and up-to-date data that we\'ll use to populate your share counts, just go to the Social Warfare Option Page, select the "Social Identity" tab, then scoll down to the "Social Network Collections" section and get yourself set up now!<br /><br />P.S. We do NOT collect any of your data from the API to our servers or share it with any third parties. Absolutely None.';
		}

		if( true === $is_notice_needed ) {
			new SWP_Notice( $notice_key, $notice_message );
		}
	}

}
