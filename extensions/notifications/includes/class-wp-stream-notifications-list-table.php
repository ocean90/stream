<?php

class WP_Stream_Notifications_List_Table {

	/**
	 * Hold Singleton instance
	 *
	 * @var string
	 */
	public static $instance;

	/**
	 * Return active instance of this class, create one if it doesn't exist
	 *
	 * @return WP_Stream_Notifications_List_Table
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct( $args = array() ) {
		add_filter( 'manage_stream-notification_posts_columns', array( $this, 'column_heads' ) );
		add_action( 'manage_stream-notification_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_action( 'manage_edit-stream-notification_sortable_columns', array( $this, 'column_sortable' ) );
		add_filter( 'request', array( $this, 'column_sortable_request' ) );

		// Add inline row actions
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );

		// Add bulk actions
		add_action( 'admin_head', array( $this, 'scripts' ) );
		// Parse actions
		add_action( 'load-edit.php', array( $this, 'actions' ) );
	}

	/**
	 * @filter manage_posts_columns
	 *
	 * @param $cols
	 *
	 * @return array
	 */
	public function column_heads( $cols ) {
		$new = array(
			'type' => esc_html__( 'Type', 'stream' ),
			'occ'  => esc_html__( 'Occurrences', 'stream' ),
		);

		$cols = array_merge( array_splice( $cols, 0, 2 ), $new, array_splice( $cols, 0 ) );

		return $cols;
	}

	/**
	 * @action manage_posts_custom_column
	 *
	 * @param $name
	 * @param $post_id
	 */
	public function column_content( $name, $post_id ) {
		if ( 'type' === $name ) {
			echo esc_html( $this->get_rule_alert_types( $post_id ) );
		} elseif ( 'occ' === $name ) {
			echo absint( ( $occ = get_post_meta( $post_id, 'occurrences', true ) ) ? $occ : 0 );
		}
	}

	/**
	 * @filter manage_posts_sortable_columns
	 *
	 * @param $sortable
	 *
	 * @return array
	 */
	public function column_sortable( $sortable ) {
		return $sortable + array(
			'occ' => array( 'occurrences', true ),
		);
	}

	/**
	 * @filter request
	 *
	 * @param array $vars
	 *
	 * @return mixed
	 */
	public function column_sortable_request( $vars ) {
		if ( ! is_admin() ) {
			return $vars;
		}

		if ( isset( $vars['orderby'] ) && 'occurrences' === $vars['orderby'] ) {
			$vars['orderby']    = 'meta_value_num';
			$vars['meta_key']   = 'occurrences';
			$vars['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => 'occurrences',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => 'occurrences',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		return $vars;
	}

	/**
	 * Retrieve rule alert types' labels
	 *
	 * @param $post_id
	 *
	 * @return string
	 */
	function get_rule_alert_types( $post_id ) {
		$alerts = get_post_meta( $post_id, 'alerts', true );

		if ( empty( $alerts ) ) {
			return esc_html__( 'N/A', 'stream' );
		} else {
			$types  = wp_list_pluck( $alerts, 'type' );
			$titles = wp_list_pluck(
				array_intersect_key(
					WP_Stream_Notifications::$adapters,
					array_flip( $types )
				),
				'title'
			);

			return implode( ', ', $titles );
		}
	}

	/**
	 * @filter post_row_actions
	 *
	 * @param $actions
	 *
	 * @return array
	 */
	public function row_actions( $actions ) {
		global $typenow;

		if ( WP_Stream_Notifications_Post_Type::POSTTYPE !== $typenow ) {
			return $actions;
		}

		unset( $actions['view'] );
		unset( $actions['inline hide-if-no-js'] );

		global $post;

		$new = array();

		if ( 'trash' !== $post->post_status ) {
			$published = ( 'publish' === $post->post_status );

			$url = wp_nonce_url(
				add_query_arg(
					array(
						'post_type' => WP_Stream_Notifications_Post_Type::POSTTYPE,
						'action'    => $published ? 'unpublish' : 'publish',
						'id'        => $post->ID,
					),
					admin_url( 'edit.php' )
				)
			);

			$new['publish'] = sprintf(
				'<a href="%s">%s</a>',
				$url,
				$published ? esc_html__( 'Deactivate', 'stream' ) : esc_html__( 'Activate', 'stream' )
			);
		}

		return array_merge( $new, $actions );
	}

	/**
	 * @action load-edit.php
	 */
	public function actions() {
		$action    = wp_stream_filter_input( INPUT_GET, 'action' );
		$post_type = wp_stream_filter_input( INPUT_GET, 'post_type' );

		if ( empty( $action ) || WP_Stream_Notifications_Post_Type::POSTTYPE !== $post_type ) {
			return;
		}

		$post    = wp_stream_filter_input( INPUT_GET, 'post' );
		$id      = wp_stream_filter_input( INPUT_GET, 'id' );
		$request = $post ? is_array( $post ) ? $post : explode( ',', $post ) : ( $id ? array( $id ) : array() );
		$ids     = array_map( 'absint', $request );

		if ( empty( $ids ) ) {
			return;
		}

		if ( in_array( $action, array( 'publish', 'unpublish' ) ) ) {
			$status = ( 'publish' === $action ) ? 'publish' : 'draft';

			foreach ( $ids as $id ) {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => $status,
					)
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'updated' => count( $ids ),
					),
					remove_query_arg(
						array( 'action', 'action2', 'id', 'ids', 'post', '_wp_http_referer', 'post_status', 'mode', 'm' )
					)
				)
			);

			exit; // Without this, the page displays the weird 'Are you sure you want this?'
		}
	}

	/**
	 * @filter admin_head
	 */
	public function scripts() {
		if ( 'edit-' . WP_Stream_Notifications_Post_Type::POSTTYPE !== get_current_screen()->id ) {
			return;
		}

		$bulk_actions = array(
			'bulkActions' => array(
				'publish'   => esc_html__( 'Activate', 'stream' ),
				'unpublish' => esc_html__( 'Deactivate', 'stream' ),
			),
		);

		if ( 'trash' === wp_stream_filter_input( INPUT_GET, 'post_status' ) ) {
			$bulk_actions = array();
		}

		wp_enqueue_script( 'stream-notifications-list-actions', WP_STREAM_NOTIFICATIONS_URL . 'ui/js/list.js', array( 'jquery', 'underscore' ), WP_STREAM::VERSION );

		wp_localize_script( 'stream-notifications-list-actions', 'stream_notifications_options', $bulk_actions );
	}

}
