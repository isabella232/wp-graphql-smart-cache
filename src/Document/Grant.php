<?php
/**
 * Content
 *
 * @package Wp_Graphql_Persisted_Queries
 */

namespace WPGraphQL\PersistedQueries\Document;

use WPGraphQL\PersistedQueries\Admin\Settings;
use WPGraphQL\PersistedQueries\Document;
use WPGraphQL\PersistedQueries\ValidationRules\AllowDenyQueryDocument;
use GraphQL\Server\RequestError;

class Grant {

	const TAXONOMY_NAME = 'graphql_document_grant';

	// The string value used for the individual saved query
	const ALLOW                = 'allow';
	const DENY                 = 'deny';
	const USE_DEFAULT          = '';
	const NOT_SELECTED_DEFAULT = self::USE_DEFAULT;

	// The string value stored for the global admin setting
	const GLOBAL_ALLOWED = 'only_allowed';
	const GLOBAL_DENIED  = 'some_denied';
	const GLOBAL_PUBLIC  = 'public';
	const GLOBAL_DEFAULT = self::GLOBAL_PUBLIC; // The global admin setting default

	const GLOBAL_SETTING_NAME = 'grant_mode';

	public function init() {
		register_taxonomy(
			self::TAXONOMY_NAME,
			Document::TYPE_NAME,
			[
				'description'        => __( 'Allow/Deny access grant for a saved GraphQL query document', 'wp-graphql-persisted-queries' ),
				'labels'             => [
					'name' => __( 'Allow/Deny', 'wp-graphql-persisted-queries' ),
				],
				'hierarchical'       => false,
				'show_admin_column'  => true,
				'show_in_menu'       => Settings::show_in_admin(),
				'show_in_quick_edit' => false,
				'meta_box_cb'        => [ $this, 'admin_input_box_cb' ],
				'show_in_graphql'    => false, // false because we register a field with different name
			]
		);

		add_action(
			'graphql_register_types',
			function () {
				$register_type_name = ucfirst( Document::GRAPHQL_NAME );
				$config             = [
					'type'        => 'String',
					'description' => __( 'Allow, deny or default access grant for specific query', 'wp-graphql-persisted-queries' ),
				];

				register_graphql_field( 'Create' . $register_type_name . 'Input', 'grant', $config );
				register_graphql_field( 'Update' . $register_type_name . 'Input', 'grant', $config );

				$config['resolve'] = function ( \WPGraphQL\Model\Post $post, $args, $context, $info ) {
					return self::getQueryGrantSetting( $post->ID );
				};
				register_graphql_field( $register_type_name, 'grant', $config );
			}
		);

		// Add to the wpgraphql server validation rules.
		// This filter allows us to add our validation rule to check a query for allow/deny access.
		add_filter( 'graphql_validation_rules', [ $this, 'add_validation_rules_cb' ], 10, 2 );

		add_action( sprintf( 'save_post_%s', Document::TYPE_NAME ), [ $this, 'save_cb' ] );

		add_filter( 'graphql_mutation_input', [ $this, 'graphql_mutation_filter' ], 10, 4 );
		add_action( 'graphql_mutation_response', [ $this, 'graphql_mutation_insert' ], 10, 6 );
	}

	// This runs on post create/update
	// Check the grant allow/deny value is within limits
	public function graphql_mutation_filter( $input, $context, $info, $mutation_name ) {
		if ( ! in_array( $mutation_name, [ 'createGraphqlDocument', 'updateGraphqlDocument' ], true ) ) {
			return $input;
		}

		if ( ! isset( $input['grant'] ) ) {
			return $input;
		}

		if ( ! in_array( $input['grant'], [ self::ALLOW, self::DENY, self::USE_DEFAULT ], true ) ) {
			// Translators: The placeholder is the input allow/deny value
			throw new RequestError( sprintf( __( 'Invalid value for allow/deny grant: "%s"', 'wp-graphql-persisted-queries' ), $input['grant'] ) );
		}

		return $input;
	}

	// This runs on post create/update
	// Check the grant allow/deny value is within limits
	public function graphql_mutation_insert( $post_object, $filtered_input, $input, $context, $info, $mutation_name ) {
		if ( ! in_array( $mutation_name, [ 'createGraphqlDocument', 'updateGraphqlDocument' ], true ) ) {
			return;
		}

		if ( ! isset( $filtered_input['grant'] ) || ! isset( $post_object['postObjectId'] ) ) {
			return;
		}

		$this->save( $post_object['postObjectId'], $filtered_input['grant'] );
	}

	/**
	* Draw the input field for the post edit
	*/
	public function admin_input_box_cb( $post ) {
		wp_nonce_field( 'graphql_query_grant', 'savedquery_grant_noncename' );

		$value = $this->getQueryGrantSetting( $post->ID );
		$html  = sprintf(
			'<input type="radio" id="graphql_query_grant_allow" name="graphql_query_grant" value="%s" %s>',
			self::ALLOW,
			checked( $value, self::ALLOW, false )
		);
		$html .= '<label for="graphql_query_grant_allow">Allowed</label><br >';
		$html .= sprintf(
			'<input type="radio" id="graphql_query_grant_deny" name="graphql_query_grant" value="%s" %s>',
			self::DENY,
			checked( $value, self::DENY, false )
		);
		$html .= '<label for="graphql_query_grant_deny">Deny</label><br >';
		$html .= sprintf(
			'<input type="radio" id="graphql_query_grant_default" name="graphql_query_grant" value="%s" %s>',
			self::USE_DEFAULT,
			checked( $value, self::USE_DEFAULT, false )
		);
		$html .= '<label for="graphql_query_grant_default">Use global default</label><br >';
		echo wp_kses(
			$html,
			[
				'input' => [
					'type'    => true,
					'id'      => true,
					'name'    => true,
					'value'   => true,
					'checked' => true,
				],
				'br'    => true,
			]
		);
	}

	/**
	 * Look up the allow/deny grant setting for a post
	 *
	 * @param int  The post id
	 */
	public static function getQueryGrantSetting( $post_id ) {
		$item = get_the_terms( $post_id, self::TAXONOMY_NAME );
		return ! is_wp_error( $item ) && isset( $item[0]->name ) ? $item[0]->name : self::NOT_SELECTED_DEFAULT;
	}

	/**
	 * Use during processing of submitted form if value of selected input field is selected.
	 * And return value of the taxonomy.
	 *
	 * @param string The input form value
	 * @return string The string value used to save as the taxonomy value
	 */
	public function the_selection( $value ) {
		if ( in_array(
			$value,
			[
				self::ALLOW,
				self::DENY,
				self::USE_DEFAULT,
			],
			true
		) ) {
			return $value;
		}

		return self::USE_DEFAULT;
	}

	/**
	* When a post is saved, sanitize and store the data.
	*/
	public function save_cb( $post_id ) {
		if ( empty( $_POST ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['post_type'] ) || Document::TYPE_NAME !== $_POST['post_type'] ) {
			return;
		}

		if ( ! isset( $_REQUEST['savedquery_grant_noncename'] ) ) {
			return;
		}

		// phpcs:ignore
		if ( ! wp_verify_nonce( $_REQUEST['savedquery_grant_noncename'], 'graphql_query_grant' ) ) {
			return;
		}

		if ( ! isset( $_POST['graphql_query_grant'] ) ) {
			return;
		}

		$data = $this->the_selection( sanitize_text_field( wp_unslash( $_POST['graphql_query_grant'] ) ) );

		// Save the data
		$this->save( $post_id, $data );
	}

	public function save( $post_id, $grant ) {
		return wp_set_post_terms( $post_id, $grant, self::TAXONOMY_NAME );
	}

	/**
	 * Use graphql-php built in validation rules when a query is being requested.
	 * This allows the query to check access grant rules (allow/deny) and return correct error if needed.
	 */
	public function add_validation_rules_cb( $validation_rules, $request ) {
		// Check the grant mode. If public for all, don't add this rule.
		$setting = get_graphql_setting( self::GLOBAL_SETTING_NAME, self::GLOBAL_DEFAULT, 'graphql_persisted_queries_section' );
		if ( self::GLOBAL_PUBLIC !== $setting ) {
			$validation_rules['allow_deny_query_document'] = new AllowDenyQueryDocument( $setting );
		}

		return $validation_rules;
	}
}
