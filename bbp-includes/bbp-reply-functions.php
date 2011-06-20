<?php

/**
 * bbPress Reply Functions
 *
 * @package bbPress
 * @subpackage Functions
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * Update the reply with its forum id it is in
 *
 * @since bbPress (r2855)
 *
 * @param int $reply_id Optional. Reply id to update
 * @param int $forum_id Optional. Forum id
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_get_forum_id() To get the forum id
 * @uses get_post_ancestors() To get the reply's forum
 * @uses get_post_field() To get the post type of the post
 * @uses update_post_meta() To update the reply forum id meta
 * @uses apply_filters() Calls 'bbp_update_reply_forum_id' with the forum id
 *                        and reply id
 * @return bool Reply's forum id
 */
function bbp_update_reply_forum_id( $reply_id = 0, $forum_id = 0 ) {

	// Validation
	$reply_id = bbp_get_reply_id( $reply_id );
	$forum_id = bbp_get_forum_id( $forum_id );

	// If no forum_id was passed, walk up ancestors and look for forum type
	if ( empty( $forum_id ) ) {

		// Get ancestors
		$ancestors = get_post_ancestors( $reply_id );

		// Loop through ancestors
		foreach ( $ancestors as $ancestor ) {

			// Get first parent that is a forum
			if ( get_post_field( 'post_type', $ancestor ) == bbp_get_forum_post_type() ) {
				$forum_id = $ancestor;

				// Found a forum, so exit the loop and continue
				continue;
			}
		}
	}

	// Update the forum ID
	bbp_update_forum_id( $reply_id, $forum_id );

	return apply_filters( 'bbp_update_reply_forum_id', (int) $forum_id, $reply_id );
}

/**
 * Update the reply with its topic id it is in
 *
 * @since bbPress (r2855)
 *
 * @param int $reply_id Optional. Reply id to update
 * @param int $topic_id Optional. Topic id
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_get_topic_id() To get the topic id
 * @uses get_post_ancestors() To get the reply's topic
 * @uses get_post_field() To get the post type of the post
 * @uses update_post_meta() To update the reply topic id meta
 * @uses apply_filters() Calls 'bbp_update_reply_topic_id' with the topic id
 *                        and reply id
 * @return bool Reply's topic id
 */
function bbp_update_reply_topic_id( $reply_id = 0, $topic_id = 0 ) {

	// Validation
	$reply_id = bbp_get_reply_id( $reply_id );
	$topic_id = bbp_get_topic_id( $topic_id );

	// If no topic_id was passed, walk up ancestors and look for topic type
	if ( empty( $topic_id ) ) {

		// Get ancestors
		$ancestors = get_post_ancestors( $reply_id );

		// Loop through ancestors
		foreach ( $ancestors as $ancestor ) {

			// Get first parent that is a forum
			if ( get_post_field( 'post_type', $ancestor ) == bbp_get_topic_post_type() ) {
				$topic_id = $ancestor;

				// Found a forum, so exit the loop and continue
				continue;
			}
		}
	}

	// Update the topic ID
	bbp_update_topic_id( $reply_id, $topic_id );

	return apply_filters( 'bbp_update_reply_topic_id', (int) $topic_id, $reply_id );
}

/** Post Form Handlers ********************************************************/

/**
 * Handles the front end reply submission
 *
 * @since bbPress (r2574)
 *
 * @uses bbPress:errors::add() To log various error messages
 * @uses check_admin_referer() To verify the nonce and check the referer
 * @uses bbp_is_anonymous() To check if an anonymous post is being made
 * @uses current_user_can() To check if the current user can publish replies
 * @uses bbp_get_current_user_id() To get the current user id
 * @uses bbp_filter_anonymous_post_data() To filter anonymous data
 * @uses bbp_set_current_anonymous_user_data() To set the anonymous user
 *                                                cookies
 * @uses is_wp_error() To check if the value retrieved is a {@link WP_Error}
 * @uses remove_filter() To remove 'wp_filter_kses' filters if needed
 * @uses esc_attr() For sanitization
 * @uses bbp_check_for_flood() To check for flooding
 * @uses bbp_check_for_duplicate() To check for duplicates
 * @uses apply_filters() Calls 'bbp_new_reply_pre_title' with the title
 * @uses apply_filters() Calls 'bbp_new_reply_pre_content' with the content
 * @uses bbp_get_reply_post_type() To get the reply post type
 * @uses wp_set_post_terms() To set the topic tags
 * @uses bbPress::errors::get_error_codes() To get the {@link WP_Error} errors
 * @uses wp_insert_post() To insert the reply
 * @uses do_action() Calls 'bbp_new_reply' with the reply id, topic id, forum
 *                    id, anonymous data and reply author
 * @uses bbp_get_reply_url() To get the paginated url to the reply
 * @uses wp_safe_redirect() To redirect to the reply url
 * @uses bbPress::errors::get_error_message() To get the {@link WP_Error} error
 *                                              message
 */
function bbp_new_reply_handler() {

	// Only proceed if POST is a new reply
	if ( 'POST' == strtoupper( $_SERVER['REQUEST_METHOD'] ) && !empty( $_POST['action'] ) && ( 'bbp-new-reply' === $_POST['action'] ) ) {
		global $bbp;

		// Nonce check
		check_admin_referer( 'bbp-new-reply' );

		// Define local variable(s)
		$topic_id = $forum_id = $reply_author = $anonymous_data = 0;
		$reply_title = $reply_content = $terms = '';

		/** Reply Author ******************************************************/

		// User is anonymous
		if ( bbp_is_anonymous() ) {

			// Filter anonymous data
			$anonymous_data = bbp_filter_anonymous_post_data();

			// Anonymous data checks out, so set cookies, etc...
			if ( !empty( $anonymous_data ) && is_array( $anonymous_data ) ) {
				bbp_set_current_anonymous_user_data( $anonymous_data );
			}

		// User is logged in
		} else {

			// User cannot create replies
			if ( !current_user_can( 'publish_replies' ) ) {
				$bbp->errors->add( 'bbp_reply_permissions', __( '<strong>ERROR</strong>: You do not have permission to reply.', 'bbpress' ) );
			}

			// Reply author is current user
			$reply_author = bbp_get_current_user_id();

		}

		/** Topic ID **********************************************************/

		// Handle Topic ID to append reply to
		if ( isset( $_POST['bbp_topic_id'] ) && ( !$topic_id = (int) $_POST['bbp_topic_id'] ) )
			$bbp->errors->add( 'bbp_reply_topic_id', __( '<strong>ERROR</strong>: Topic ID is missing.', 'bbpress' ) );

		/** Forum ID **********************************************************/

		// Handle Forum ID to adjust counts of
		if ( isset( $_POST['bbp_forum_id'] ) && ( !$forum_id = (int) $_POST['bbp_forum_id'] ) )
			$bbp->errors->add( 'bbp_reply_forum_id', __( '<strong>ERROR</strong>: Forum ID is missing.', 'bbpress' ) );

		/** Unfiltered HTML ***************************************************/

		// Remove wp_filter_kses filters from title and content for capable users and if the nonce is verified
		if ( current_user_can( 'unfiltered_html' ) && !empty( $_POST['_bbp_unfiltered_html_reply'] ) && wp_create_nonce( 'bbp-unfiltered-html-reply_' . $topic_id ) == $_POST['_bbp_unfiltered_html_reply'] ) {
			remove_filter( 'bbp_new_reply_pre_title',   'wp_filter_kses' );
			remove_filter( 'bbp_new_reply_pre_content', 'wp_filter_kses' );
		}

		/** Reply Title *******************************************************/

		if ( !empty( $_POST['bbp_reply_title'] ) )
			$reply_title = esc_attr( strip_tags( $_POST['bbp_reply_title'] ) );

		// Filter and sanitize
		$reply_title = apply_filters( 'bbp_new_reply_pre_title', $reply_title );

		// No reply title
		if ( empty( $reply_title ) )
			$bbp->errors->add( 'bbp_reply_title', __( '<strong>ERROR</strong>: Your reply needs a title.', 'bbpress' ) );

		/** Reply Content *****************************************************/

		if ( !empty( $_POST['bbp_reply_content'] ) )
			$reply_content = $_POST['bbp_reply_content'];

		// Filter and sanitize
		$reply_content = apply_filters( 'bbp_new_reply_pre_content', $reply_content );

		// No reply content
		if ( empty( $reply_content ) )
			$bbp->errors->add( 'bbp_reply_content', __( '<strong>ERROR</strong>: Your reply cannot be empty.', 'bbpress' ) );

		/** Reply Flooding ****************************************************/

		if ( !bbp_check_for_flood( $anonymous_data, $reply_author ) )
			$bbp->errors->add( 'bbp_reply_flood', __( '<strong>ERROR</strong>: Slow down; you move too fast.', 'bbpress' ) );

		/** Reply Duplicate ***************************************************/

		if ( !bbp_check_for_duplicate( array( 'post_type' => bbp_get_reply_post_type(), 'post_author' => $reply_author, 'post_content' => $reply_content, 'post_parent' => $topic_id, 'anonymous_data' => $anonymous_data ) ) )
			$bbp->errors->add( 'bbp_reply_duplicate', __( '<strong>ERROR</strong>: Duplicate reply detected; it looks as though you&#8217;ve already said that!', 'bbpress' ) );

		/** Topic Tags ********************************************************/

		if ( !empty( $_POST['bbp_topic_tags'] ) )
			$terms = esc_attr( strip_tags( $_POST['bbp_topic_tags'] ) );

		/** Additional Actions (Before Save) **********************************/

		do_action( 'bbp_new_reply_pre_extras' );

		/** No Errors *********************************************************/

		// Handle insertion into posts table
		if ( !is_wp_error( $bbp->errors ) || !$bbp->errors->get_error_codes() ) {

			/** Create new reply **********************************************/

			// Add the content of the form to $post as an array
			$reply_data = array(
				'post_author'  => $reply_author,
				'post_title'   => $reply_title,
				'post_content' => $reply_content,
				'post_parent'  => $topic_id,
				'post_status'  => 'publish',
				'post_type'    => bbp_get_reply_post_type()
			);

			// Just in time manipulation of reply data before being created
			$reply_data = apply_filters( 'bbp_new_reply_pre_insert', $reply_data );

			// Insert reply
			$reply_id = wp_insert_post( $reply_data );

			/** No Errors *****************************************************/

			// Check for missing reply_id or error
			if ( !empty( $reply_id ) && !is_wp_error( $reply_id ) ) {

				/** Topic Tags ************************************************/

				// Just in time manipulation of reply terms before being edited
				$terms = apply_filters( 'bbp_new_reply_pre_set_terms', $terms, $topic_id, $reply_id );

				// Insert terms
				$terms = wp_set_post_terms( $topic_id, $terms, $bbp->topic_tag_id, false );

				// Term error
				if ( is_wp_error( $terms ) )
					$bbp->errors->add( 'bbp_reply_tags', __( '<strong>ERROR</strong>: There was some problem adding the tags to the topic.', 'bbpress' ) );

				/** Trash Check ***********************************************/

				// If this reply starts as trash, add it to pre_trashed_replies
				// for the topic, so it is properly restored.
				if ( bbp_is_topic_trash( $topic_id ) || ( $reply_data['post_status'] == $bbp->trash_status_id ) ) {

					// Trash the reply
					wp_trash_post( $reply_id );

					// Get pre_trashed_replies for topic
					$pre_trashed_replies = get_post_meta( $topic_id, '_bbp_pre_trashed_replies', true );

					// Add this reply to the end of the existing replies
					$pre_trashed_replies[] = $reply_id;

					// Update the pre_trashed_reply post meta
					update_post_meta( $topic_id, '_bbp_pre_trashed_replies', $pre_trashed_replies );
				}

				/** Spam Check ************************************************/
				
				// If reply or topic are spam, officially spam this reply
				if ( bbp_is_topic_spam( $topic_id ) || ( $reply_data['post_status'] == $bbp->spam_status_id ) )
					add_post_meta( $reply_id, '_bbp_spam_meta_status', 'publish' );

				/** Update counts, etc... *************************************/

				do_action( 'bbp_new_reply', $reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author );

				/** Redirect **************************************************/

				// Redirect to
				$redirect_to = !empty( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : '';

				// Get the reply URL
				$reply_url = bbp_get_reply_url( $reply_id, $redirect_to );

				// Allow to be filtered
				$reply_url = apply_filters( 'bbp_new_reply_redirect_to', $reply_url, $redirect_to );

				/** Successful Save *******************************************/

				// Redirect back to new reply
				wp_safe_redirect( $reply_url );

				// For good measure
				exit();

			/** Errors ********************************************************/

			} else {
				$append_error = ( is_wp_error( $reply_id ) && $reply_id->get_error_message() ) ? $reply_id->get_error_message() . ' ' : '';
				$bbp->errors->add( 'bbp_reply_error', __( '<strong>ERROR</strong>: The following problem(s) have been found with your reply:' . $append_error . 'Please try again.', 'bbpress' ) );
			}
		}
	}
}

/**
 * Handles the front end edit reply submission
 *
 * @uses bbPress:errors::add() To log various error messages
 * @uses bbp_get_reply() To get the reply
 * @uses check_admin_referer() To verify the nonce and check the referer
 * @uses bbp_is_reply_anonymous() To check if the reply was by an anonymous user
 * @uses current_user_can() To check if the current user can edit that reply
 * @uses bbp_filter_anonymous_post_data() To filter anonymous data
 * @uses is_wp_error() To check if the value retrieved is a {@link WP_Error}
 * @uses remove_filter() To remove 'wp_filter_kses' filters if needed
 * @uses esc_attr() For sanitization
 * @uses apply_filters() Calls 'bbp_edit_reply_pre_title' with the title and
 *                       reply id
 * @uses apply_filters() Calls 'bbp_edit_reply_pre_content' with the content
 *                        reply id
 * @uses wp_set_post_terms() To set the topic tags
 * @uses bbPress::errors::get_error_codes() To get the {@link WP_Error} errors
 * @uses wp_save_post_revision() To save a reply revision
 * @uses bbp_update_topic_revision_log() To update the reply revision log
 * @uses wp_update_post() To update the reply
 * @uses bbp_get_reply_topic_id() To get the reply topic id
 * @uses bbp_get_topic_forum_id() To get the topic forum id
 * @uses do_action() Calls 'bbp_edit_reply' with the reply id, topic id, forum
 *                    id, anonymous data, reply author and bool true (for edit)
 * @uses bbp_get_reply_url() To get the paginated url to the reply
 * @uses wp_safe_redirect() To redirect to the reply url
 * @uses bbPress::errors::get_error_message() To get the {@link WP_Error} error
 *                                             message
 */
function bbp_edit_reply_handler() {

	// Only proceed if POST is an reply request
	if ( 'POST' == strtoupper( $_SERVER['REQUEST_METHOD'] ) && !empty( $_POST['action'] ) && ( 'bbp-edit-reply' === $_POST['action'] ) ) {
		global $bbp;

		// Define local variable(s)
		$reply = $reply_id = $topic_id = $forum_id = $anonymous_data = 0;
		$reply_title = $reply_content = $reply_edit_reason = $terms = '';

		/** Reply *************************************************************/

		// Reply id was not passed
		if ( empty( $_POST['bbp_reply_id'] ) )
			$bbp->errors->add( 'bbp_edit_reply_id', __( '<strong>ERROR</strong>: Reply ID not found.', 'bbpress' ) );

		// Reply id was passed
		elseif ( is_numeric( $_POST['bbp_reply_id'] ) )
			$reply_id = (int) $_POST['bbp_reply_id'];

		// Reply does not exist
		if ( !$reply = bbp_get_reply( $reply_id ) ) {
			$bbp->errors->add( 'bbp_edit_reply_not_found', __( '<strong>ERROR</strong>: The reply you want to edit was not found.', 'bbpress' ) );

		// Reply exists
		} else {

			// Nonce check
			check_admin_referer( 'bbp-edit-reply_' . $reply_id );

			// Check users ability to create new reply
			if ( !bbp_is_reply_anonymous( $reply_id ) ) {

				// User cannot edit this reply
				if ( !current_user_can( 'edit_reply', $reply_id ) ) {
					$bbp->errors->add( 'bbp_edit_reply_permissions', __( '<strong>ERROR</strong>: You do not have permission to edit that reply.', 'bbpress' ) );
				}

			// It is an anonymous post
			} else {

				// Filter anonymous data
				$anonymous_data = bbp_filter_anonymous_post_data( array(), true );
			}
		}

		// Remove wp_filter_kses filters from title and content for capable users and if the nonce is verified
		if ( current_user_can( 'unfiltered_html' ) && !empty( $_POST['_bbp_unfiltered_html_reply'] ) && wp_create_nonce( 'bbp-unfiltered-html-reply_' . $reply_id ) == $_POST['_bbp_unfiltered_html_reply'] ) {
			remove_filter( 'bbp_edit_reply_pre_title',   'wp_filter_kses' );
			remove_filter( 'bbp_edit_reply_pre_content', 'wp_filter_kses' );
		}

		/** Reply Topic *******************************************************/

		$topic_id = bbp_get_reply_topic_id( $reply_id );

		/** Reply Forum *******************************************************/

		$forum_id = bbp_get_topic_forum_id( $topic_id );

		// Forum exists
		if ( !empty( $forum_id ) && ( $forum_id != $reply->post_parent ) ) {

			// Forum is a category
			if ( bbp_is_forum_category( $forum_id ) )
				$bbp->errors->add( 'bbp_edit_reply_forum_category', __( '<strong>ERROR</strong>: This forum is a category. No topics or replies can be created in it.', 'bbpress' ) );

			// Forum is closed and user cannot access
			if ( bbp_is_forum_closed( $forum_id ) && !current_user_can( 'edit_forum', $forum_id ) )
				$bbp->errors->add( 'bbp_edit_reply_forum_closed', __( '<strong>ERROR</strong>: This forum has been closed to new topics and replies.', 'bbpress' ) );

			// Forum is private and user cannot access
			if ( bbp_is_forum_private( $forum_id ) && !current_user_can( 'read_private_forums' ) )
				$bbp->errors->add( 'bbp_edit_reply_forum_private', __( '<strong>ERROR</strong>: This forum is private and you do not have the capability to read or create new replies in it.', 'bbpress' ) );

			// Forum is hidden and user cannot access
			if ( bbp_is_forum_hidden( $forum_id ) && !current_user_can( 'read_hidden_forums' ) )
				$bbp->errors->add( 'bbp_edit_reply_forum_hidden', __( '<strong>ERROR</strong>: This forum is hidden and you do not have the capability to read or create new replies in it.', 'bbpress' ) );
		}

		/** Reply Title *******************************************************/

		if ( !empty( $_POST['bbp_reply_title'] ) )
			$reply_title = esc_attr( strip_tags( $_POST['bbp_reply_title'] ) );

		// Filter and sanitize
		$reply_title = apply_filters( 'bbp_edit_reply_pre_title', $reply_title, $reply_id );

		/** Reply Content *****************************************************/

		if ( !empty( $_POST['bbp_reply_content'] ) )
			$reply_content = $_POST['bbp_reply_content'];

		// Filter and sanitize
		$reply_content = apply_filters( 'bbp_edit_reply_pre_content', $reply_content, $reply_id );

		// No reply content
		if ( empty( $reply_content ) )
			$bbp->errors->add( 'bbp_edit_reply_content', __( '<strong>ERROR</strong>: Your reply cannot be empty.', 'bbpress' ) );

		/** Topic Tags ********************************************************/

		if ( !empty( $_POST['bbp_topic_tags'] ) )
			$terms = esc_attr( strip_tags( $_POST['bbp_topic_tags'] ) );

		/** Additional Actions (Before Save) **********************************/

		do_action( 'bbp_edit_reply_pre_extras', $reply_id );

		/** No Errors *********************************************************/

		// Handle insertion into posts table
		if ( !is_wp_error( $bbp->errors ) || !$bbp->errors->get_error_codes() ) {

			// Add the content of the form to $post as an array
			$reply_data = array(
				'ID'           => $reply_id,
				'post_title'   => $reply_title,
				'post_content' => $reply_content
			);

			// Just in time manipulation of reply data before being edited
			$reply_data = apply_filters( 'bbp_edit_reply_pre_insert', $reply_data );

			// Insert reply
			$reply_id = wp_update_post( $reply_data );

			/** Topic Tags ************************************************/

			// Just in time manipulation of reply terms before being edited
			$terms = apply_filters( 'bbp_edit_reply_pre_set_terms', $terms, $topic_id, $reply_id );

			// Insert terms
			$terms = wp_set_post_terms( $topic_id, $terms, $bbp->topic_tag_id, false );

			// Term error
			if ( is_wp_error( $terms ) )
				$bbp->errors->add( 'bbp_reply_tags', __( '<strong>ERROR</strong>: There was some problem adding the tags to the topic.', 'bbpress' ) );

			/** Revisions *****************************************************/

			// Revision Reason
			if ( !empty( $_POST['bbp_reply_edit_reason'] ) )
				$reply_edit_reason = esc_attr( strip_tags( $_POST['bbp_reply_edit_reason'] ) );

			// Update revision log
			if ( !empty( $_POST['bbp_log_reply_edit'] ) && ( 1 == $_POST['bbp_log_reply_edit'] ) && ( $revision_id = wp_save_post_revision( $reply_id ) ) ) {
				bbp_update_reply_revision_log( array(
					'reply_id'    => $reply_id,
					'revision_id' => $revision_id,
					'author_id'   => bbp_get_current_user_id(),
					'reason'      => $reply_edit_reason
				) );
			}

			/** No Errors *****************************************************/

			if ( !empty( $reply_id ) && !is_wp_error( $reply_id ) ) {

				// Update counts, etc...
				do_action( 'bbp_edit_reply', $reply_id, $topic_id, $forum_id, $anonymous_data, $reply->post_author , true /* Is edit */ );

				/** Additional Actions (After Save) ***************************/

				do_action( 'bbp_edit_reply_post_extras', $reply_id );

				/** Redirect **************************************************/

				// Redirect to
				$redirect_to = !empty( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : '';

				// Get the reply URL
				$reply_url = bbp_get_reply_url( $reply_id, $redirect_to );

				// Allow to be filtered
				$reply_url = apply_filters( 'bbp_edit_reply_redirect_to', $reply_url, $redirect_to );

				/** Successful Edit *******************************************/

				// Redirect back to new reply
				wp_safe_redirect( $reply_url );

				// For good measure
				exit();

			/** Errors ********************************************************/

			} else {
				$append_error = ( is_wp_error( $reply_id ) && $reply_id->get_error_message() ) ? $reply_id->get_error_message() . ' ' : '';
				$bbp->errors->add( 'bbp_reply_error', __( '<strong>ERROR</strong>: The following problem(s) have been found with your reply:' . $append_error . 'Please try again.', 'bbpress' ) );
			}
		}
	}
}

/**
 * Handle all the extra meta stuff from posting a new reply or editing a reply
 *
 * @param int $reply_id Optional. Reply id
 * @param int $topic_id Optional. Topic id
 * @param int $forum_id Optional. Forum id
 * @param bool|array $anonymous_data Optional. If it is an array, it is
 *                    extracted and anonymous user info is saved
 * @param int $author_id Author id
 * @param bool $is_edit Optional. Is the post being edited? Defaults to false.
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_get_topic_id() To get the topic id
 * @uses bbp_get_forum_id() To get the forum id
 * @uses bbp_get_current_user_id() To get the current user id
 * @uses bbp_get_reply_topic_id() To get the reply topic id
 * @uses bbp_get_topic_forum_id() To get the topic forum id
 * @uses update_post_meta() To update the reply metas
 * @uses set_transient() To update the flood check transient for the ip
 * @uses update_user_meta() To update the last posted meta for the user
 * @uses bbp_is_subscriptions_active() To check if the subscriptions feature is
 *                                      activated or not
 * @uses bbp_is_user_subscribed() To check if the user is subscribed
 * @uses bbp_remove_user_subscription() To remove the user's subscription
 * @uses bbp_add_user_subscription() To add the user's subscription
 * @uses bbp_update_reply_forum_id() To update the reply forum id
 * @uses bbp_update_reply_topic_id() To update the reply topic id
 * @uses bbp_update_reply_walker() To update the reply's ancestors' counts
 */
function bbp_update_reply( $reply_id = 0, $topic_id = 0, $forum_id = 0, $anonymous_data = false, $author_id = 0, $is_edit = false ) {

	// Validate the ID's passed from 'bbp_new_reply' action
	$reply_id = bbp_get_reply_id( $reply_id );
	$topic_id = bbp_get_topic_id( $topic_id );
	$forum_id = bbp_get_forum_id( $forum_id );

	// Check author_id
	if ( empty( $author_id ) )
		$author_id = bbp_get_current_user_id();

	// Check topic_id
	if ( empty( $topic_id ) )
		$topic_id = bbp_get_reply_topic_id( $reply_id );

	// Check forum_id
	if ( !empty( $topic_id ) && empty( $forum_id ) )
		$forum_id = bbp_get_topic_forum_id( $topic_id );

	// If anonymous post, store name, email, website and ip in post_meta.
	// It expects anonymous_data to be sanitized.
	// Check bbp_filter_anonymous_post_data() for sanitization.
	if ( !empty( $anonymous_data ) && is_array( $anonymous_data ) ) {
		extract( $anonymous_data );

		update_post_meta( $reply_id, '_bbp_anonymous_name',  $bbp_anonymous_name,  false );
		update_post_meta( $reply_id, '_bbp_anonymous_email', $bbp_anonymous_email, false );

		// Set transient for throttle check (only on new, not edit)
		if ( empty( $is_edit ) )
			set_transient( '_bbp_' . bbp_current_author_ip() . '_last_posted', time() );

		// Website is optional
		if ( !empty( $bbp_anonymous_website ) )
			update_post_meta( $reply_id, '_bbp_anonymous_website', $bbp_anonymous_website, false );

	} else {
		if ( empty( $is_edit ) && !current_user_can( 'throttle' ) )
			update_user_meta( $author_id, '_bbp_last_posted', time() );
	}

	// Handle Subscription Checkbox
	if ( bbp_is_subscriptions_active() && !empty( $author_id ) ) {
		$subscribed = bbp_is_user_subscribed( $author_id, $topic_id );
		$subscheck  = ( !empty( $_POST['bbp_topic_subscription'] ) && ( 'bbp_subscribe' == $_POST['bbp_topic_subscription'] ) ) ? true : false;

		// Subscribed and unsubscribing
		if ( true == $subscribed && false == $subscheck )
			bbp_remove_user_subscription( $author_id, $topic_id );

		// Subscribing
		elseif ( false == $subscribed && true == $subscheck )
			bbp_add_user_subscription( $author_id, $topic_id );
	}

	// Reply meta relating to reply position in tree
	bbp_update_reply_forum_id( $reply_id, $forum_id );
	bbp_update_reply_topic_id( $reply_id, $topic_id );

	// Update associated topic values if this is a new reply
	if ( empty( $is_edit ) ) {

		// Update poster IP if not editing
		update_post_meta( $reply_id, '_bbp_author_ip', bbp_current_author_ip(), false );

		// Last active time
		$last_active_time = current_time( 'mysql' );

		// Walk up ancestors and do the dirty work
		bbp_update_reply_walker( $reply_id, $last_active_time, $forum_id, $topic_id, false );
	}
}

/**
 * Walk up the ancestor tree from the current reply, and update all the counts
 *
 * @since bbPress (r2884)
 *
 * @param int $reply_id Optional. Reply id
 * @param string $last_active_time Optional. Last active time
 * @param int $forum_id Optional. Forum id
 * @param int $topic_id Optional. Topic id
 * @param bool $refresh If set to true, unsets all the previous parameters.
 *                       Defaults to true
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_get_reply_topic_id() To get the reply topic id
 * @uses bbp_get_reply_forum_id() To get the reply forum id
 * @uses get_post_ancestors() To get the ancestors of the reply
 * @uses bbp_is_reply() To check if the ancestor is a reply
 * @uses bbp_is_topic() To check if the ancestor is a topic
 * @uses bbp_update_topic_last_reply_id() To update the topic last reply id
 * @uses bbp_update_topic_last_active_id() To update the topic last active id
 * @uses bbp_get_topic_last_active_id() To get the topic last active id
 * @uses get_post_field() To get the post date of the last active id
 * @uses bbp_update_topic_last_active_time() To update the last active topic meta
 * @uses bbp_update_topic_voice_count() To update the topic voice count
 * @uses bbp_update_topic_reply_count() To update the topic reply count
 * @uses bbp_update_topic_hidden_reply_count() To update the topic hidden reply
 *                                              count
 * @uses bbp_is_forum() To check if the ancestor is a forum
 * @uses bbp_update_forum_last_topic_id() To update the last topic id forum meta
 * @uses bbp_update_forum_last_reply_id() To update the last reply id forum meta
 * @uses bbp_update_forum_last_active_id() To update the forum last active id
 * @uses bbp_get_forum_last_active_id() To get the forum last active id
 * @uses bbp_update_forum_last_active_time() To update the forum last active time
 * @uses bbp_update_forum_reply_count() To update the forum reply count
 */
function bbp_update_reply_walker( $reply_id, $last_active_time = '', $forum_id = 0, $topic_id = 0, $refresh = true ) {
	global $bbp;

	// Verify the reply ID
	if ( $reply_id = bbp_get_reply_id( $reply_id ) ) {

		// Get the topic ID if none was passed
		if ( empty( $topic_id ) )
			$topic_id = bbp_get_reply_topic_id( $reply_id );

		// Get the forum ID if none was passed
		if ( empty( $forum_id ) )
			$forum_id = bbp_get_reply_forum_id( $reply_id );
	}

	// Set the active_id based on topic_id/reply_id
	$active_id = empty( $reply_id ) ? $topic_id : $reply_id;

	// Setup ancestors array to walk up
	$ancestors = array_values( array_unique( array_merge( array( $topic_id, $forum_id ), get_post_ancestors( $topic_id ) ) ) );

	// If we want a full refresh, unset any of the possibly passed variables
	if ( true == $refresh )
		$forum_id = $topic_id = $reply_id = $active_id = $last_active_time = 0;

	// Walk up ancestors
	foreach ( $ancestors as $ancestor ) {

		// Reply meta relating to most recent reply
		if ( bbp_is_reply( $ancestor ) ) {
			// @todo - hierarchical replies

		// Topic meta relating to most recent reply
		} elseif ( bbp_is_topic( $ancestor ) ) {

			// Last reply and active ID's
			bbp_update_topic_last_reply_id  ( $ancestor, $reply_id  );
			bbp_update_topic_last_active_id ( $ancestor, $active_id );

			// Get the last active time if none was passed
			if ( empty( $last_active_time ) )
				$topic_last_active_time = get_post_field( 'post_date', bbp_get_topic_last_active_id( $ancestor ) );
			else
				$topic_last_active_time = $last_active_time;

			bbp_update_topic_last_active_time  ( $ancestor, $topic_last_active_time );

			// Counts
			bbp_update_topic_voice_count       ( $ancestor );
			bbp_update_topic_reply_count       ( $ancestor );
			bbp_update_topic_hidden_reply_count( $ancestor );

		// Forum meta relating to most recent topic
		} elseif ( bbp_is_forum( $ancestor ) ) {

			// Last topic and reply ID's
			bbp_update_forum_last_topic_id( $ancestor, $topic_id );
			bbp_update_forum_last_reply_id( $ancestor, $reply_id );

			// Last Active
			bbp_update_forum_last_active_id( $ancestor, $active_id );

			if ( empty( $last_active_time ) )
				$forum_last_active_time = get_post_field( 'post_date', bbp_get_forum_last_active_id( $ancestor ) );
			else
				$forum_last_active_time = $last_active_time;

			bbp_update_forum_last_active_time( $ancestor, $forum_last_active_time );

			// Counts
			bbp_update_forum_reply_count( $ancestor );
		}
	}
}

/**
 * Update the revision log of the reply
 *
 * @since bbPress (r2782)
 *
 * @param mixed $args Supports these args:
 *  - reply_id: reply id
 *  - author_id: Author id
 *  - reason: Reason for editing
 *  - revision_id: Revision id
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_get_user_id() To get the user id
 * @uses bbp_format_revision_reason() To format the reason
 * @uses bbp_get_reply_raw_revision_log() To get the raw reply revision log
 * @uses update_post_meta() To update the reply revision log meta
 * @return mixed False on failure, true on success
 */
function bbp_update_reply_revision_log( $args = '' ) {
	$defaults = array (
		'reason'      => '',
		'reply_id'    => 0,
		'author_id'   => 0,
		'revision_id' => 0
	);

	$r = wp_parse_args( $args, $defaults );
	extract( $r );

	// Populate the variables
	$reason      = bbp_format_revision_reason( $reason );
	$reply_id    = bbp_get_reply_id( $reply_id );
	$author_id   = bbp_get_user_id ( $author_id, false, true );
	$revision_id = (int) $revision_id;

	// Get the logs and append the new one to those
	$revision_log               = bbp_get_reply_raw_revision_log( $reply_id );
	$revision_log[$revision_id] = array( 'author' => $author_id, 'reason' => $reason );

	// Finally, update
	update_post_meta( $reply_id, '_bbp_revision_log', $revision_log );

	return apply_filters( 'bbp_update_reply_revision_log', $revision_log, $reply_id );
}

/** Reply Actions *************************************************************/

/**
 * Handles the front end spamming/unspamming and trashing/untrashing/deleting of
 * replies
 *
 * @since bbPress (r2740)
 *
 * @uses bbp_get_reply() To get the reply
 * @uses current_user_can() To check if the user is capable of editing or
 *                           deleting the reply
 * @uses check_ajax_referer() To verify the nonce and check the referer
 * @uses bbp_get_reply_post_type() To get the reply post type
 * @uses bbp_is_reply_spam() To check if the reply is marked as spam
 * @uses bbp_spam_reply() To make the reply as spam
 * @uses bbp_unspam_reply() To unmark the reply as spam
 * @uses wp_trash_post() To trash the reply
 * @uses wp_untrash_post() To untrash the reply
 * @uses wp_delete_post() To delete the reply
 * @uses do_action() Calls 'bbp_toggle_reply_handler' with success, post data
 *                    and action
 * @uses bbp_get_reply_url() To get the reply url
 * @uses add_query_arg() To add custom args to the reply url
 * @uses wp_redirect() To redirect to the reply
 * @uses bbPress::errors:add() To log the error messages
 */
function bbp_toggle_reply_handler() {

	// Only proceed if GET is a reply toggle action
	if ( 'GET' == strtoupper( $_SERVER['REQUEST_METHOD'] ) && !empty( $_GET['reply_id'] ) && !empty( $_GET['action'] ) && in_array( $_GET['action'], array( 'bbp_toggle_reply_spam', 'bbp_toggle_reply_trash' ) ) ) {
		global $bbp;

		$action    = $_GET['action'];            // What action is taking place?
		$reply_id  = (int) $_GET['reply_id'];    // What's the reply id?
		$success   = false;                      // Flag
		$post_data = array( 'ID' => $reply_id ); // Prelim array

		// Make sure reply exists
		if ( !$reply = bbp_get_reply( $reply_id ) )
			return;

		// What is the user doing here?
		if ( !current_user_can( 'edit_reply', $reply->ID ) || ( 'bbp_toggle_reply_trash' == $action && !current_user_can( 'delete_reply', $reply->ID ) ) ) {
			$bbp->errors->add( 'bbp_toggle_reply_permission', __( '<strong>ERROR:</strong> You do not have the permission to do that!', 'bbpress' ) );
			return;
		}

		// What action are we trying to perform?
		switch ( $action ) {

			// Toggle spam
			case 'bbp_toggle_reply_spam' :
				check_ajax_referer( 'spam-reply_' . $reply_id );

				$is_spam = bbp_is_reply_spam( $reply_id );
				$success = $is_spam ? bbp_unspam_reply( $reply_id ) : bbp_spam_reply( $reply_id );
				$failure = $is_spam ? __( '<strong>ERROR</strong>: There was a problem unmarking the reply as spam!', 'bbpress' ) : __( '<strong>ERROR</strong>: There was a problem marking the reply as spam!', 'bbpress' );

				break;

			// Toggle trash
			case 'bbp_toggle_reply_trash' :

				$sub_action = in_array( $_GET['sub_action'], array( 'trash', 'untrash', 'delete' ) ) ? $_GET['sub_action'] : false;

				if ( empty( $sub_action ) )
					break;

				switch ( $sub_action ) {
					case 'trash':
						check_ajax_referer( 'trash-' . bbp_get_reply_post_type() . '_' . $reply_id );

						$success = wp_trash_post( $reply_id );
						$failure = __( '<strong>ERROR</strong>: There was a problem trashing the reply!', 'bbpress' );

						break;

					case 'untrash':
						check_ajax_referer( 'untrash-' . bbp_get_reply_post_type() . '_' . $reply_id );

						$success = wp_untrash_post( $reply_id );
						$failure = __( '<strong>ERROR</strong>: There was a problem untrashing the reply!', 'bbpress' );

						break;

					case 'delete':
						check_ajax_referer( 'delete-' . bbp_get_reply_post_type() . '_' . $reply_id );

						$success = wp_delete_post( $reply_id );
						$failure = __( '<strong>ERROR</strong>: There was a problem deleting the reply!', 'bbpress' );

						break;
				}

				break;
		}

		// Do additional reply toggle actions
		do_action( 'bbp_toggle_reply_handler', $success, $post_data, $action );

		// No errors
		if ( ( false != $success ) && !is_wp_error( $success ) ) {

			// Redirect back to the reply
			$redirect = bbp_get_reply_url( $reply_id );
			wp_redirect( $redirect );

			// For good measure
			exit();

		// Handle errors
		} else {
			$bbp->errors->add( 'bbp_toggle_reply', $failure );
		}
	}
}

/** Reply Actions *************************************************************/

/**
 * Marks a reply as spam
 *
 * @since bbPress (r2740)
 *
 * @param int $reply_id Reply id
 * @uses wp_get_single_post() To get the reply
 * @uses do_action() Calls 'bbp_spam_reply' with the reply ID
 * @uses add_post_meta() To add the previous status to a meta
 * @uses wp_insert_post() To insert the updated post
 * @uses do_action() Calls 'bbp_spammed_reply' with the reply ID
 * @return mixed False or {@link WP_Error} on failure, reply id on success
 */
function bbp_spam_reply( $reply_id = 0 ) {
	global $bbp;

	// Get reply
	if ( !$reply = wp_get_single_post( $reply_id, ARRAY_A ) )
		return $reply;

	// Bail if already spam
	if ( $reply['post_status'] == $bbp->spam_status_id )
		return false;

	// Execute pre spam code
	do_action( 'bbp_spam_reply', $reply_id );

	// Add the original post status as post meta for future restoration
	add_post_meta( $reply_id, '_bbp_spam_meta_status', $reply['post_status'] );

	// Set post status to spam
	$reply['post_status'] = $bbp->spam_status_id;

	// No revisions
	remove_action( 'pre_post_update', 'wp_save_post_revision' );

	// Update the reply
	$reply_id = wp_insert_post( $reply );

	// Execute post spam code
	do_action( 'bbp_spammed_reply', $reply_id );

	// Return reply_id
	return $reply_id;
}

/**
 * Unspams a reply
 *
 * @since bbPress (r2740)
 *
 * @param int $reply_id Reply id
 * @uses wp_get_single_post() To get the reply
 * @uses do_action() Calls 'bbp_unspam_reply' with the reply ID
 * @uses get_post_meta() To get the previous status meta
 * @uses delete_post_meta() To delete the previous status meta
 * @uses wp_insert_post() To insert the updated post
 * @uses do_action() Calls 'bbp_unspammed_reply' with the reply ID
 * @return mixed False or {@link WP_Error} on failure, reply id on success
 */
function bbp_unspam_reply( $reply_id = 0 ) {
	global $bbp;

	// Get reply
	if ( !$reply = wp_get_single_post( $reply_id, ARRAY_A ) )
		return $reply;

	// Bail if already not spam
	if ( $reply['post_status'] != $bbp->spam_status_id )
		return false;

	// Execute pre unspam code
	do_action( 'bbp_unspam_reply', $reply_id );

	// Get pre spam status
	$reply_status         = get_post_meta( $reply_id, '_bbp_spam_meta_status', true );
	
	// Set post status to pre spam
	$reply['post_status'] = $reply_status;

	// Delete pre spam meta
	delete_post_meta( $reply_id, '_bbp_spam_meta_status' );

	// No revisions
	remove_action( 'pre_post_update', 'wp_save_post_revision' );

	// Update the reply
	$reply_id = wp_insert_post( $reply );

	// Execute post unspam code
	do_action( 'bbp_unspammed_reply', $reply_id );

	// Return reply_id
	return $reply_id;
}

/** Before Delete/Trash/Untrash ***********************************************/

/**
 * Called before deleting a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_delete_reply' with the reply id
 */
function bbp_delete_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || !bbp_is_reply( $reply_id ) )
		return false;

	do_action( 'bbp_delete_reply', $reply_id );
}

/**
 * Called before trashing a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_trash_reply' with the reply id
 */
function bbp_trash_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || !bbp_is_reply( $reply_id ) )
		return false;

	do_action( 'bbp_trash_reply', $reply_id );
}

/**
 * Called before untrashing (restoring) a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_unstrash_reply' with the reply id
 */
function bbp_untrash_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || !bbp_is_reply( $reply_id ) )
		return false;

	do_action( 'bbp_untrash_reply', $reply_id );
}

/** After Delete/Trash/Untrash ************************************************/

/**
 * Called after deleting a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_deleted_reply' with the reply id
 */
function bbp_deleted_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || !bbp_is_reply( $reply_id ) )
		return false;

	do_action( 'bbp_deleted_reply', $reply_id );
}

/**
 * Called after trashing a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_trashed_reply' with the reply id
 */
function bbp_trashed_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || !bbp_is_reply( $reply_id ) )
		return false;

	do_action( 'bbp_trashed_reply', $reply_id );
}

/**
 * Called after untrashing (restoring) a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_untrashed_reply' with the reply id
 */
function bbp_untrashed_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || !bbp_is_reply( $reply_id ) )
		return false;

	do_action( 'bbp_untrashed_reply', $reply_id );
}

/** Feeds *********************************************************************/

/**
 * Output an RSS2 feed of replies, based on the query passed.
 *
 * @since bbPress (r3171)
 *
 * @global bbPress $bbp
 *
 * @uses bbp_is_topic()
 * @uses bbp_user_can_view_forum()
 * @uses bbp_get_topic_forum_id()
 * @uses bbp_show_load_topic()
 * @uses bbp_topic_permalink()
 * @uses bbp_topic_title()
 * @uses bbp_get_topic_reply_count()
 * @uses bbp_topic_content()
 * @uses bbp_has_replies()
 * @uses bbp_replies()
 * @uses bbp_the_reply()
 * @uses bbp_reply_url()
 * @uses bbp_reply_title()
 * @uses bbp_reply_content()
 * @uses get_wp_title_rss()
 * @uses get_option()
 * @uses bloginfo_rss
 * @uses self_link()
 * @uses the_author()
 * @uses get_post_time()
 * @uses rss_enclosure()
 * @uses do_action()
 * @uses apply_filters()
 *
 * @param array $replies_query
 */
function bbp_display_replies_feed_rss2( $replies_query = array() ) {
	global $bbp;

	// User cannot access forum this topic is in
	if ( bbp_is_topic() && !bbp_user_can_view_forum( array( 'forum_id' => bbp_get_topic_forum_id() ) ) )
		return;

	// Adjust the title based on context
	if ( bbp_is_topic() && bbp_user_can_view_forum( array( 'forum_id' => bbp_get_topic_forum_id() ) ) )
		$title = apply_filters( 'wp_title_rss', get_wp_title_rss( ' &#187; ' ) );
	elseif ( !bbp_show_lead_topic() )
		$title = ' &#187; ' .  __( 'All Posts',   'bbpress' );
	else
		$title = ' &#187; ' .  __( 'All Replies', 'bbpress' );

	// Display the feed
	header( 'Content-Type: text/xml; charset=' . get_option( 'blog_charset' ), true );
	header( 'Status: 200 OK' );
	echo '<?xml version="1.0" encoding="' . get_option( 'blog_charset' ) . '"?' . '>'; ?>

	<rss version="2.0"
		xmlns:content="http://purl.org/rss/1.0/modules/content/"
		xmlns:wfw="http://wellformedweb.org/CommentAPI/"
		xmlns:dc="http://purl.org/dc/elements/1.1/"
		xmlns:atom="http://www.w3.org/2005/Atom"

		<?php do_action( 'bbp_feed' ); ?>
	>

	<channel>
		<title><?php bloginfo_rss('name'); echo $title; ?></title>
		<atom:link href="<?php self_link(); ?>" rel="self" type="application/rss+xml" />
		<link><?php self_link(); ?></link>
		<description><?php //?></description>
		<pubDate><?php echo mysql2date( 'D, d M Y H:i:s O', '', false ); ?></pubDate>
		<generator>http://bbpress.org/?v=<?php echo $bbp->version; ?></generator>
		<language><?php echo get_option( 'rss_language' ); ?></language>

		<?php do_action( 'bbp_feed_head' ); ?>

		<?php if ( bbp_is_topic() ) : ?>
			<?php if ( bbp_user_can_view_forum( array( 'forum_id' => bbp_get_topic_forum_id() ) ) ) : ?>
				<?php if ( bbp_show_lead_topic() ) : ?>

					<item>
						<guid><?php bbp_topic_permalink(); ?></guid>
						<title><![CDATA[<?php bbp_topic_title(); ?>]]></title>
						<link><?php bbp_topic_permalink(); ?></link>
						<pubDate><?php echo mysql2date( 'D, d M Y H:i:s +0000', get_post_time( 'Y-m-d H:i:s', true ), false ); ?></pubDate>
						<dc:creator><?php the_author(); ?></dc:creator>

						<description>
							<![CDATA[
							<p><?php printf( __( 'Replies: %s', 'bbpress' ), bbp_get_topic_reply_count() ); ?></p>
							<?php bbp_topic_content(); ?>
							]]>
						</description>

						<?php rss_enclosure(); ?>

						<?php do_action( 'bbp_feed_item' ); ?>

					</item>

				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( bbp_has_replies( $replies_query ) ) : ?>
			<?php while ( bbp_replies() ) : bbp_the_reply(); ?>

				<item>
					<guid><?php bbp_reply_url(); ?></guid>
					<title><![CDATA[<?php bbp_reply_title(); ?>]]></title>
					<link><?php bbp_reply_url(); ?></link>
					<pubDate><?php echo mysql2date( 'D, d M Y H:i:s +0000', get_post_time( 'Y-m-d H:i:s', true ), false ); ?></pubDate>
					<dc:creator><?php the_author() ?></dc:creator>

					<description>
						<![CDATA[
						<?php bbp_reply_content(); ?>
						]]>
					</description>

					<?php rss_enclosure(); ?>

					<?php do_action( 'bbp_feed_item' ); ?>

				</item>

			<?php endwhile; ?>
		<?php endif; ?>

		<?php do_action( 'bbp_feed_footer' ); ?>

	</channel>
	</rss>

<?php

	// We're done here
	exit();
}

?>
