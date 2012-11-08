<?php

/*
Plugin Name: WordPress TimeStack
Description: WordPress Profiling
Author: Human Made Limited
Version: 0.9
*/

class HM_Time_Stack {

	private static $instance;
	public $stack;
	private $start_time;

	public static function instance() {

		if ( !isset( self::$instance ) )
			self::$instance = new HM_Time_Stack();

		return self::$instance;
	}

	public static function get_data( $clear = false ) {

		if ( function_exists( 'apc_store' ) && apc_store( '__test', '123' ) ) {
			$data = apc_fetch( '_hm_all_stacks' );
		} else {
			$data = wp_cache_get( '_hm_all_stacks' );
		}

		if ( $clear )
			self::set_data( array() );

		return $data;
	}

	public static function set_data( $data ) {

		if ( function_exists( 'apc_store' ) && apc_store( '__test', '123' ) ) {
			return apc_store( '_hm_all_stacks', $data, 60 );
		} else {
			return wp_cache_set( '_hm_all_stacks', $data, null, 60 );
		}
	}

	function __construct() {

		if ( ! empty( $_GET['action'] ) && ( $_GET['action'] == 'hm_display_stacks' || $_GET['action'] == 'hm_get_stacks' ) )
			return;

		if ( defined( 'HM_DEV_TIMESTACK_TRACK_COOKIE_ONLY' ) && HM_DEV_TIMESTACK_TRACK_COOKIE_ONLY == true && empty( $_COOKIE['track_timestack'] ) )
			return;

		global $hm_time_stack_start;

		if( !empty( $hm_time_stack_start ) )
			$this->start_time = $hm_time_stack_start;
		else
			$this->start_time = hm_time_stack_time();

		// store it in object cache for persistant logging
		$t = $this;
		add_action( 'shutdown', function() use ( $t ) {
			$all_stacks = HM_Time_Stack::get_data();

			if ( is_user_logged_in() )
				$user = wp_get_current_user()->display_name;

			else
				$user = 'Anonymouse';

			$all_stacks = array_reverse( (array) $all_stacks );
			$all_stacks[] = array( 'stack' => $t->stack->archive(), 'date' => time(), 'url' => $_SERVER['REQUEST_URI'], 'user' => $user );
			$all_stacks = array_reverse( $all_stacks );

			HM_Time_Stack::set_data( $all_stacks );
		}, 11 );

		$this->stack = new HM_Time_Stack_Operation( 'wp' );
		$this->stack->start_time = $this->start_time;
		$this->stack->start_memory_usage = 0;

		$this->setup_hooks();
	}

	public function get_id() {
		return $_SERVER['REQUEST_URI'] . time();
	}

	public function start_operation( $id, $label = '' ) {

		if ( ! $this->stack )
			return;

		$operation = new HM_Time_Stack_Operation( $id, $label );
		$this->stack->add_operation( $operation );

	}

	public function end_operation( $id ) {
		if ( ! $this->stack )
			return;


		$this->stack->end_operation( $this->stack->get_child_operation_by_id( $id ) );
	}

	public function add_event( $id, $label = '' ) {

		$event = new HM_Time_Stack_Event( $id, $label );
		$this->stack->add_event( $event );
	}

	private function setup_hooks() {

		// global adding from actions
		add_action( 'start_operation', function( $id, $label = '' ) {

			HM_Time_Stack::instance()->start_operation( $id, $label );

		}, 10, 2 );

		add_action( 'end_operation', function( $id, $args = array() ) {

			if ( HM_Time_Stack::instance()->stack->get_child_operation_by_id( $id ) ) {
				HM_Time_Stack::instance()->stack->get_child_operation_by_id( $id )->vars = $args;
				HM_Time_Stack::instance()->end_operation( $id );
			}

		}, 10, 2 );

		add_action( 'add_event', function( $id, $label = '' ) {

			HM_Time_Stack::instance()->add_event( $id, $label );

		}, 10, 1 );

		add_action( 'log', function( $data ) {
			if ( is_scalar( $data ) )
				do_action( 'add_event', $data );
			else
				do_action( 'add_event', print_r( $data, true ) );
		} );

		add_action( 'init', function() {
			HM_Time_Stack::instance()->start_operation( 'hook: init' );
		}, 0 );

		add_action( 'init', function() {
			HM_Time_Stack::instance()->end_operation( 'hook: init' );
		}, 999999 );

		add_action( 'parse_query', function( $wp_query ) {

			$query = is_string( $wp_query->query ) ? $wp_query->query : json_encode( $wp_query->query );
			global $wp_the_query;

			if ( $wp_the_query == $wp_query ) {
				$name = 'Main WP Query';
			}

			else {
				$trace = debug_backtrace();

				if ( isset( $trace[6]['function'] ) && isset( $trace[7]['file'] ) )
					$name = $trace[6]['function'] . ' - ' . $trace[7]['file'] . '[' . $trace[7]['line'] . ']';
				else
					$name = 'WP_Query';
			}

			HM_Time_Stack::instance()->start_operation( 'wp_query::' . spl_object_hash( $wp_query ), 'WP_Query - ' . $name );

			$wp_query->query_vars['suppress_filters'] = false;
		}, 1 );

		add_action( 'the_posts', function( $posts, $wp_query ) {
			HM_Time_Stack::instance()->end_operation( 'wp_query::' . spl_object_hash( $wp_query ) );
			return $posts;
		}, 99, 2 );

		add_action( 'shutdown', function() {

			HM_Time_Stack::instance()->add_event( 'shutdown' );

			HM_Time_Stack::instance()->end_operation( 'wp' );
		} );

		add_action( 'template_redirect', function() {

			HM_Time_Stack::instance()->add_event( 'template_redirect' );

		}, 1 );

		add_action( 'wp_head', function() {

			HM_Time_Stack::instance()->add_event( 'wp_head' );

		}, 1 );

		add_action( 'loop_start', function( $wp_query ) {

			$query = is_string( $wp_query->query ) ? $wp_query->query : json_encode( $wp_query->query );
			HM_Time_Stack::instance()->add_event( 'the_loop::' . spl_object_hash( $wp_query ), 'Loop Start' );

		}, 1 );

		add_action( 'loop_end', function( $wp_query ) {

			$query = is_string( $wp_query->query ) ? $wp_query->query : json_encode( $wp_query->query );
			HM_Time_Stack::instance()->add_event( 'the_loop::' . spl_object_hash( $wp_query ), 'Loop End' );

		}, 1 );

		add_action( 'get_sidebar', function( $name ) {

			HM_Time_Stack::instance()->add_event( 'get_sidebar', 'get_sidebar - ' . $name );

		}, 1 );

		add_action( 'wp_footer', function() {

			HM_Time_Stack::instance()->add_event( 'wp_footer' );

		}, 1 );

		// hooks for remote rewuest, (but hacky)
		add_filter( 'https_ssl_verify', function( $var ) {

			do_action( 'start_operation', 'Remote Request' );
			return $var;

		} );

		add_action( 'http_api_debug', function( $response, $type, $class, $args, $url) {
			do_action( 'end_operation', 'Remote Request', array( 'url' => $url ) );
		}, 10, 5 );


		add_action( 'plugins_loaded', function() {
			do_action( 'add_event', 'loaded plugins' );
		}, 9999999 );


	}

	public function get_start_time() {
		return $this->start_time;
	}
}

class HM_Time_Stack_Operation {

	public $start_time;
	public $end_time;
	public $duration;
	public $id;
	public $label;
	public $is_open;
	private $open_operation;
	public $children;
	public $peak_memory_usage;
	public $start_memory_usage;
	public $end_memory_usage;
	public $start_query_count;
	public $end_query_count;
	public $query_count;
	public $vars;
	public $time;

	public function __construct( $id, $label = '' ) {

		$this->children = array();
		$this->id = $id;
		$this->start_time = hm_time_stack_time();

		if ( $id !== 'wp' )
			$this->time = round( hm_time_stack_time() - HM_Time_Stack::instance()->get_start_time(), 3 );

		$this->label = $label;
		$this->is_open = true;
		$this->start_memory_usage = memory_get_peak_usage();

		global $wpdb;
		$this->start_query_count = $wpdb->num_queries;

		if ( ! defined( 'SAVEQUERIES' ) )
			define( 'SAVEQUERIES', true );

		$this->_start_query_log_count = count( $wpdb->queries );
	}

	public function end() {
		$this->end_time = hm_time_stack_time();
		$this->end_memory_usage = memory_get_peak_usage();
		$this->peak_memory_usage = round( ( $this->end_memory_usage - $this->start_memory_usage ) / 1024 / 1024, 4 );
		$this->duration = round( $this->end_time - $this->start_time, 4 );
		$this->is_open = false;

		global $wpdb;
		$this->end_query_count = $wpdb->num_queries;
		$this->query_count = $this->end_query_count - $this->start_query_count;

		if ( $wpdb->queries )
		$this->queries = array_splice( $wpdb->queries, $this->_start_query_log_count );

		else
			$this->queries = array();
	}

	public function add_operation( $operation ) {

		if ( ! empty( $this->open_operation ) ) {
			$this->open_operation->add_operation( $operation );
		}

		else {
			$this->children[] = $operation;

			$this->open_operation = $operation;
		}
	}

	public function add_event( $event ) {

		if ( ! empty( $this->open_operation ) ) {
			$this->open_operation->add_event( $event );
		} else {
			$this->children[] = $event;
		}
	}

	public function end_operation( $operation ) {

		if ( ! empty( $this->open_operation ) ) {

			if ( $this->open_operation == $operation ) {
				$this->open_operation->end();
				$this->open_operation = null;
			}
			else {
				$this->open_operation->end_operation( $operation );

			}

		}

		if ( $operation === $this ) {

			$this->end();

		}
	}

	public function get_child_operation_by_id( $id ) {

		$return = null;

		foreach ( $this->children as $child ) {
			if ( $operation = $child->get_child_operation_by_id( $id ) ) {
				$return = $operation;
				break;
			}
		}

		if ( $this->is_open && $this->id == $id )
			$return = $this;

		return $return;
	}

	public function archive() {

		$archive = (object) array();

		$archive->type 			= get_class($this);
		$archive->queries 		= ! empty( $this->queries ) ? $this->queries : array();
		$archive->query_time 	= $this->get_query_time();
		$archive->is_open 		= $this->is_open;
		$archive->duration 		= $this->duration;
		$archive->memory_usage	= $this->peak_memory_usage;
		$archive->label			= $this->label ? $this->label : $this->id;
		$archive->vars			= $this->vars;
		$archive->time 			= $this->time;

		foreach ( $this->children as $operation )
			$archive->children[] = $operation->archive();

		//special case for "wp"
		if ( $this->id === 'wp' ) {
			$archive->request = array(
				'user_agent',
			);
		}

		return $archive;
	}

	private function get_query_time() {

		$query_time = 0;

		if ( !empty( $this->queries ) )
			foreach ( (array) $this->queries as $q )
				$query_time += $q[1];

	}

	public function _print() {

		$query_time = 0;

		if ( !empty( $this->queries ) )
			foreach ( (array) $this->queries as $q )
				$query_time += $q[1];
		?>
		<li class="operation">
			<span class="title">
				operation: <strong><?php echo $this->label ? $this->label : $this->id ?></strong>
				<span class="querie-count"><?php echo $this->query_count ?> Queries [<?php echo $query_time ?>]</span>
				<span class="memory-usage"><?php echo $this->peak_memory_usage ?>MB</span>
				<span class="duration"><?php echo $this->duration ?></span>
			</span>

			<?php if ( $this->vars ) : ?>
				<?php print_r( $this->vars ) ?>
			<?php endif; ?>


			<?php if ( $this->is_open ) : ?>
				Warning: Not Ended;
			<?php endif; ?>

			<ul>
			<?php foreach( $this->children as $operation ) : ?>

				<?php $operation->_print(); ?>

			<?php endforeach; ?>
			</ul>
		</li>
		<?php

	}

}

class HM_Time_Stack_Event extends HM_Time_Stack_Operation {

	public $id;
	public $time;

	function __construct( $id, $label = '' ) {

		$this->id = $id;
		$this->time = round( hm_time_stack_time() - HM_Time_Stack::instance()->get_start_time(), 3 );
		$this->children = array();
		$this->label = $label;
		$this->peak_memory_usage = round( memory_get_peak_usage() / 1024 / 1024, 3 );
	}

	function _print() {

		?>
		<li class="event">
			<?php echo $this->label ? $this->label : $this->id ?>

			<span class="memory-usage"><?php echo $this->peak_memory_usage ?>MB</span>

			<span class="duration"><?php echo $this->time ?> in
		</li>
		<?php

	}

	function archive() {

		$archive = parent::archive();

		unset( $archive->duration );
		$archive->time = $this->time;

		return $archive;
	}

}

function hm_time_stack_time() {

	$time = explode( ' ', microtime() );
	$time = $time[1] + $time[0];
	return $time;
}

HM_Time_Stack::instance();

// show persistant stacks
if ( isset( $_GET['action'] ) && $_GET['action'] == 'hm_get_stacks' ) {

    echo $_GET['jsoncallback'] . '(' . json_encode( HM_Time_Stack::get_data( true ) ) . ')';
    exit;
}

