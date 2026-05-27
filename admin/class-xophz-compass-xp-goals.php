<?php

/**
 * The admin-specific functionality for XP Goals and Evaluation Engine.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */

class Xophz_Compass_Xp_Goals {

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'init' => 'register_xp_goal_cpt',
    'add_meta_boxes' => 'add_goal_meta_boxes',
    'save_post_xp_goal' => ['save_goal_meta', 10, 3],
    'xophz_compass_record_action' => ['evaluate_goals', 20, 3],
    'rest_api_init' => 'register_goal_rest_routes',
    'wp_loaded' => 'register_dynamic_hooks',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
   * Register the xp_goal Custom Post Type.
   */
  public function register_xp_goal_cpt() {
    $labels = array(
      'name'               => __( 'XP Goals', 'post type general name', 'xophz-compass-xp' ),
      'singular_name'      => __( 'XP Goal', 'post type singular name', 'xophz-compass-xp' ),
      'menu_name'          => __( 'XP Goals', 'admin menu', 'xophz-compass-xp' ),
      'name_admin_bar'     => __( 'XP Goal', 'add new on admin bar', 'xophz-compass-xp' ),
      'add_new'            => __( 'Add New', 'xp_goal', 'xophz-compass-xp' ),
      'add_new_item'       => __( 'Add New XP Goal', 'xophz-compass-xp' ),
      'new_item'           => __( 'New XP Goal', 'xophz-compass-xp' ),
      'edit_item'          => __( 'Edit XP Goal', 'xophz-compass-xp' ),
      'view_item'          => __( 'View XP Goal', 'xophz-compass-xp' ),
      'all_items'          => __( 'All XP Goals', 'xophz-compass-xp' ),
      'search_items'       => __( 'Search XP Goals', 'xophz-compass-xp' ),
      'not_found'          => __( 'No XP Goals found.', 'xophz-compass-xp' ),
      'not_found_in_trash' => __( 'No XP Goals found in Trash.', 'xophz-compass-xp' )
    );

    $args = array(
      'labels'             => $labels,
      'description'        => __( 'A robust gamification goal with criteria and rewards.', 'xophz-compass-xp' ),
      'public'             => false,
      'publicly_queryable' => false,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'show_in_rest'       => true,
      'query_var'          => true,
      'capability_type'    => 'post',
      'has_archive'        => false,
      'hierarchical'       => false,
      'menu_position'      => null,
      'menu_icon'          => 'dashicons-flag',
      'supports'           => array( 'title', 'editor' )
    );

    register_post_type( 'xp_goal', $args );
  }

  /**
   * Scan active goals for wp_hook requirements and attach dynamic listeners
   */
  public function register_dynamic_hooks() {
    $goals = get_posts( array(
      'post_type'      => 'xp_goal',
      'post_status'    => 'publish',
      'posts_per_page' => -1
    ) );

    $registered_hooks = array();

    foreach ( $goals as $goal ) {
      $config_json = get_post_meta( $goal->ID, '_xp_goal_config', true );
      if ( empty( $config_json ) ) continue;

      $config = json_decode( $config_json, true );
      $reqs = $config['requirements'] ?? [];

      foreach ( $reqs as $req ) {
        if ( ( $req['rule'] ?? '' ) === 'wp_hook' ) {
          $hook_name = $req['hook_name'] ?? '';
          
          if ( ! empty( $hook_name ) && ! in_array( $hook_name, $registered_hooks ) ) {
            add_action( $hook_name, function( ...$args ) use ( $hook_name ) {
              $user_id = get_current_user_id();

              // Special handling for known hooks to extract User ID if not logged in
              if ( $hook_name === 'user_register' && isset( $args[0] ) ) {
                $user_id = $args[0];
              } else if ( $hook_name === 'woocommerce_payment_complete' && isset( $args[0] ) && function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $args[0] );
                if ( $order ) {
                  $user_id = $order->get_user_id();
                }
              }

              if ( $user_id ) {
                $payload = array( 'hook_args' => $args );
                $this->evaluate_goals( 'wp_hook:' . $hook_name, $user_id, $payload );
              }
            }, 10, 10 );
            
            $registered_hooks[] = $hook_name;
          }
        }
      }
    }
  }

  public function register_goal_rest_routes() {
    register_rest_route( 'xp/v1', '/goals', array(
      array(
        'methods'  => 'GET',
        'callback' => array( $this, 'rest_get_goals' ),
        'permission_callback' => function() { return is_user_logged_in(); },
      ),
      array(
        'methods'  => 'POST',
        'callback' => array( $this, 'rest_create_goal' ),
        'permission_callback' => function() { return is_user_logged_in() && current_user_can('manage_options'); },
      )
    ));

    register_rest_route( 'xp/v1', '/goals/(?P<id>\d+)', array(
      'methods'  => 'POST',
      'callback' => array( $this, 'rest_update_goal' ),
      'permission_callback' => function() { return is_user_logged_in() && current_user_can('manage_options'); },
    ));
  }

  public function rest_get_goals() {
    $goals = get_posts( array(
      'post_type'      => 'xp_goal',
      'posts_per_page' => -1,
      'post_status'    => array('publish', 'draft'),
    ));

    $formatted = array();
    foreach ( $goals as $g ) {
      $config_json = get_post_meta( $g->ID, '_goal_config', true );
      $config = empty($config_json) ? array('requirements'=>array(), 'outcomes'=>array()) : json_decode($config_json, true);
      
      $formatted[] = array(
        'id'          => $g->ID,
        'title'       => $g->post_title,
        'status'      => $g->post_status,
        'config'      => $config
      );
    }
    return rest_ensure_response( $formatted );
  }

  public function rest_create_goal( $request ) {
    $title = sanitize_text_field( $request->get_param( 'title' ) ?: 'New Goal' );
    $status = sanitize_text_field( $request->get_param( 'status' ) ?: 'publish' );
    $config = $request->get_param( 'config' );

    $post_id = wp_insert_post( array(
      'post_title'  => $title,
      'post_type'   => 'xp_goal',
      'post_status' => $status
    ) );

    if ( is_wp_error( $post_id ) ) {
      return new WP_Error( 'create_failed', 'Failed to create goal', array( 'status' => 500 ) );
    }

    if ( ! empty( $config ) && is_array( $config ) ) {
      update_post_meta( $post_id, '_goal_config', wp_json_encode( $config ) );
    }

    return rest_ensure_response( array(
      'id' => $post_id,
      'title' => $title,
      'status' => $status,
      'config' => $config
    ) );
  }

  public function rest_update_goal( $request ) {
    $post_id = (int) $request->get_param( 'id' );
    $post = get_post( $post_id );

    if ( ! $post || $post->post_type !== 'xp_goal' ) {
      return new WP_Error( 'not_found', 'Goal not found', array( 'status' => 404 ) );
    }

    $title = $request->get_param( 'title' );
    $status = $request->get_param( 'status' );
    $config = $request->get_param( 'config' );

    $update_args = array(
      'ID' => $post_id
    );

    if ( $title !== null ) $update_args['post_title'] = sanitize_text_field( $title );
    if ( $status !== null ) $update_args['post_status'] = sanitize_text_field( $status );

    if ( count( $update_args ) > 1 ) {
      wp_update_post( $update_args );
    }

    if ( $config !== null && is_array( $config ) ) {
      update_post_meta( $post_id, '_goal_config', wp_json_encode( $config ) );
    }

    // Return updated data
    $updated_config_json = get_post_meta( $post_id, '_goal_config', true );
    return rest_ensure_response( array(
      'id' => $post_id,
      'title' => get_the_title( $post_id ),
      'status' => get_post_status( $post_id ),
      'config' => json_decode( $updated_config_json, true )
    ) );
  }

  public function add_goal_meta_boxes() {
    add_meta_box(
      'xp_goal_config_box',
      __( 'Goal Configuration (JSON)', 'xophz-compass-xp' ),
      [ $this, 'render_goal_config_box' ],
      'xp_goal',
      'normal',
      'high'
    );
  }

  public function render_goal_config_box( $post ) {
    wp_nonce_field( 'xp_goal_config_save', 'xp_goal_config_nonce' );

    $config_json = get_post_meta( $post->ID, '_goal_config', true );
    if ( empty($config_json) ) {
      $default_config = [
        'frequency' => 'one_time',
        'requirements' => [
          [
            'rule' => 'metric_threshold',
            'action_slug' => 'example_action',
            'operator' => '>=',
            'target' => 5
          ]
        ],
        'outcomes' => [
          [
            'type' => 'points',
            'amount' => 100,
            'point_type' => 'xp'
          ]
        ]
      ];
      $config_json = wp_json_encode($default_config, JSON_PRETTY_PRINT);
    } else if ( is_array($config_json) ) {
       $config_json = wp_json_encode($config_json, JSON_PRETTY_PRINT);
    }
    ?>
    <p>Define the Requirements and Outcomes for this goal.</p>
    <textarea id="xp_goal_config" name="xp_goal_config" rows="15" style="width: 100%; font-family: monospace; background: #1e1e1e; color: #a6e22e; padding: 10px; border-radius: 4px;"><?php echo esc_textarea( $config_json ); ?></textarea>
    <?php
  }

  public function save_goal_meta( $post_id, $post, $update ) {
    if ( ! isset( $_POST['xp_goal_config_nonce'] ) || ! wp_verify_nonce( $_POST['xp_goal_config_nonce'], 'xp_goal_config_save' ) ) {
      return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

    if ( isset( $_POST['xp_goal_config'] ) ) {
      $json = wp_unslash( $_POST['xp_goal_config'] );
      // Validate JSON
      $decoded = json_decode( $json, true );
      if ( json_last_error() === JSON_ERROR_NONE ) {
        // We store it as a json string for easy editing
        update_post_meta( $post_id, '_goal_config', $json );
      }
    }
  }

  /**
   * The Goals Evaluation Engine.
   * Runs whenever an xp_log is recorded.
   */
  public function evaluate_goals( $action_name, $user_id, $payload = [] ) {
    if ( ! $user_id ) return;

    // 1. Maintain the running metric counts
    $metric_key = sanitize_text_field( $action_name );
    
    // Increment the base count
    $current_count = (float) get_user_meta( $user_id, '_xp_metric_' . $metric_key, true );
    $new_count = $current_count + 1;
    update_user_meta( $user_id, '_xp_metric_' . $metric_key, $new_count );

    // Keep running sums of any numeric payload fields
    foreach ( $payload as $key => $value ) {
      if ( is_numeric( $value ) ) {
        $safe_key = sanitize_key( $key );
        $current_sum = (float) get_user_meta( $user_id, '_xp_metric_' . $metric_key . '_sum_' . $safe_key, true );
        update_user_meta( $user_id, '_xp_metric_' . $metric_key . '_sum_' . $safe_key, $current_sum + (float) $value );
      }
    }

    // 2. Fetch all active goals
    $goals = get_posts( array(
      'post_type'      => 'xp_goal',
      'posts_per_page' => -1,
      'post_status'    => 'publish',
    ));

    if ( empty( $goals ) ) return;

    $unlocked_goals_json = get_user_meta( $user_id, '_xp_won_goals', true );
    $unlocked_goals = empty( $unlocked_goals_json ) ? array() : json_decode( $unlocked_goals_json, true );

    $current_time = current_time('timestamp');

    foreach ( $goals as $goal ) {
      $config_json = get_post_meta( $goal->ID, '_goal_config', true );
      $config = json_decode( $config_json, true );
      if ( !is_array($config) ) continue;

      $frequency = $config['frequency'] ?? 'one_time';
      
      // Frequency Check
      if ( $frequency === 'one_time' ) {
        if ( in_array( $goal->ID, $unlocked_goals ) ) continue;
      } else if ( $frequency !== 'unlimited' ) {
        $last_won = (int) get_user_meta( $user_id, '_xp_goal_last_won_' . $goal->ID, true );
        if ( $last_won > 0 ) {
          $time_diff = $current_time - $last_won;
          
          if ( $frequency === 'daily' && $time_diff < DAY_IN_SECONDS ) continue;
          if ( $frequency === 'weekly' && $time_diff < WEEK_IN_SECONDS ) continue;
          if ( $frequency === 'monthly' && $time_diff < (DAY_IN_SECONDS * 30) ) continue;
        }
      }

      // Evaluate Requirements
      $requirements_met = $this->evaluate_requirements( $config['requirements'] ?? [], $user_id, $action_name, $new_count, $payload );

      if ( $requirements_met ) {
        // Goal Won!
        if ( ! in_array( $goal->ID, $unlocked_goals ) ) {
          $unlocked_goals[] = $goal->ID;
          update_user_meta( $user_id, '_xp_won_goals', wp_json_encode( $unlocked_goals ) );
        }
        
        update_user_meta( $user_id, '_xp_goal_last_won_' . $goal->ID, $current_time );

        // Process Outcomes
        $this->process_outcomes( $config['outcomes'] ?? [], $user_id );

        // Fire Goal Won Hook
        do_action( 'xophz_compass_goal_won', $user_id, $goal->ID, $goal->post_title );
      }
    }
  }

  private function evaluate_requirements( $requirements_array, $user_id, $last_action_slug, $last_action_count, $payload = [] ) {
    if ( empty($requirements_array) ) return false;

    // For a simple 'All' check, every requirement must return true.
    foreach ( $requirements_array as $req ) {
      $rule = $req['rule'] ?? '';
      
      if ( $rule === 'specific_event' ) {
        $action_slug = $req['action_slug'] ?? '';
        if ( $last_action_slug !== $action_slug ) {
          return false;
        }
      } 
      else if ( $rule === 'metric_threshold' ) {
        $action_slug = $req['action_slug'] ?? '';
        $target = (float) ($req['target'] ?? 0);
        $op = $req['operator'] ?? '>=';
        
        $metric_type = $req['metric_type'] ?? 'count'; // count | sum | single
        $schema_key = sanitize_key( $req['schema_key'] ?? '' );
        
        $value_to_check = 0;

        if ( $metric_type === 'single' ) {
          // Can only evaluate single-event criteria if this is the action that just fired
          if ( $last_action_slug !== $action_slug ) return false;
          if ( !isset( $payload[ $schema_key ] ) ) return false;
          $value_to_check = (float) $payload[ $schema_key ];
        } 
        else if ( $metric_type === 'sum' && !empty($schema_key) ) {
          // If this is the action that just fired, the sum is already updated in the DB from step 1
          $value_to_check = (float) get_user_meta( $user_id, '_xp_metric_' . $action_slug . '_sum_' . $schema_key, true );
        }
        else {
          // Default: count occurrences
          $value_to_check = ($action_slug === $last_action_slug) ? $last_action_count : (float) get_user_meta( $user_id, '_xp_metric_' . $action_slug, true );
        }

        $passed = false;
        if ( $op === '>=' ) $passed = $value_to_check >= $target;
        if ( $op === '=' || $op === '==' ) $passed = $value_to_check == $target;
        if ( $op === '<=' ) $passed = $value_to_check <= $target;
        if ( $op === '>' ) $passed = $value_to_check > $target;
        if ( $op === '<' ) $passed = $value_to_check < $target;

        if ( !$passed ) return false;
      }
      else if ( $rule === 'has_points' ) {
        $point_type = $req['point_type'] ?? 'xp';
        $target = (float) ($req['target'] ?? 0);
        $op = $req['operator'] ?? '>=';

        $current = (float) get_user_meta( $user_id, "_xp_total_{$point_type}", true );

        $passed = false;
        if ( $op === '>=' ) $passed = $current >= $target;
        if ( $op === '=' || $op === '==' ) $passed = $current == $target;
        if ( $op === '<=' ) $passed = $current <= $target;
        if ( $op === '>' ) $passed = $current > $target;
        if ( $op === '<' ) $passed = $current < $target;

        if ( !$passed ) return false;
      }
      else if ( $rule === 'goals_completed' ) {
        // Milestone logic: Check if specific goals are completed
        $target_goals = $req['target_goals'] ?? []; // Array of Goal IDs
        $target_count = (int) ($req['target_count'] ?? count($target_goals));
        
        $unlocked_goals_json = get_user_meta( $user_id, '_xp_won_goals', true );
        $unlocked_goals = empty( $unlocked_goals_json ) ? array() : json_decode( $unlocked_goals_json, true );
        
        $matched_count = 0;
        foreach ( $target_goals as $tid ) {
          if ( in_array( (int) $tid, $unlocked_goals ) ) {
            $matched_count++;
          }
        }
        
        if ( $matched_count < $target_count ) {
          return false;
        }
      }
      else if ( $rule === 'wp_hook' ) {
        // Did the system hook just fire?
        $hook_name = $req['hook_name'] ?? '';
        
        // This is evaluated by passing the hook name as the action_name to evaluate_goals
        // e.g. evaluate_goals('wp_hook:user_register', user_id)
        if ( $last_action_slug !== 'wp_hook:' . $hook_name ) {
          return false;
        }
      }
      else {
        // Unsupported requirement fails by default
        return false;
      }
    }

    return true; // All requirements passed
  }

  private function process_outcomes( $outcomes_array, $user_id ) {
    foreach ( $outcomes_array as $outcome ) {
      $type = $outcome['type'] ?? '';

      if ( $type === 'points' ) {
        $amount = (int) ($outcome['amount'] ?? 0);
        $point_type = $outcome['point_type'] ?? 'xp'; // 'xp', 'ap', 'gp'
        
        if ( $amount > 0 ) {
          $current = (int) get_user_meta( $user_id, "_xp_total_{$point_type}", true );
          update_user_meta( $user_id, "_xp_total_{$point_type}", $current + $amount );
        }
      }
      else if ( $type === 'badge_unlock' ) {
        $badge_id = (int) ($outcome['badge_id'] ?? 0);
        if ( $badge_id > 0 ) {
          $unlocked_json = get_user_meta( $user_id, '_xp_unlocked_badges', true );
          $unlocked = empty( $unlocked_json ) ? array() : json_decode( $unlocked_json, true );
          
          if ( !in_array( $badge_id, $unlocked ) ) {
            $unlocked[] = $badge_id;
            update_user_meta( $user_id, '_xp_unlocked_badges', wp_json_encode( $unlocked ) );
            do_action( 'xophz_compass_badge_unlocked', $user_id, $badge_id, get_the_title($badge_id) );
          }
        }
      }
    }
  }

}
