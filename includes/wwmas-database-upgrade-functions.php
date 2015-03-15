<?php

function wwmas_do_database_upgrade() {
	$type_map = array(
		'Element_Textbox' => 'text',
		'Element_Email' => 'email',
		'Element_Number' => 'number',
		'Element_Select' => 'dropdown',
		'Element_Radio' => 'radio',
		'Element_Checkbox' => 'checkbox',
		'Element_Textarea' => 'textarea',
	);


	$old_surveys = get_option( 'wwm_awesome_surveys', array() );
	if ( is_array( $old_surveys ) ) {
		for ( $num_surveys = 0; $num_surveys < count( $old_surveys['surveys'] ); $num_surveys++ ) {
			$existing_elements = $elements_to_render = json_decode( $old_surveys['surveys'][ $num_surveys ]['form'], true );
				//need to map the old type to the new type
				foreach ( $existing_elements as $element_key => $element_value ) {
					$existing_elements[ $element_key ]['type'] = $type_map[ $element_value['type'] ];
				}
			$elements = json_encode( $existing_elements );
			$post = array(
				'post_content' => '',
				'post_excerpt' => $old_surveys['surveys'][ $num_surveys ]['thank_you'],
				'post_type' => 'awesome-surveys',
				'post_title' => $old_surveys['surveys'][ $num_surveys ]['name'],
				'post_status' => 'publish',
				);
			$survey_id = wp_insert_post( $post );
			if ( ! empty( $survey_id ) ) {
				echo 'updating post ' . $survey_id . '<br>';
				$args = array( 'survey_id' => $survey_id );
				$post_content = wwmas_post_content_generator( $args, $elements_to_render );
				$post = array(
					'ID' => $survey_id,
					'post_content' => $post_content,
					);
				wp_update_post( $post );
				$post_metas = array(
					'existing_elements' => $elements,
					'num_responses' => ( isset( $old_surveys['surveys'][ $num_surveys ]['num_responses'] ) ) ? $old_surveys['surveys'][ $num_surveys ]['num_responses'] : false,
					);
				foreach ( $post_metas as $meta_key => $meta_value ) {
					update_post_meta( $survey_id, $meta_key, $meta_value );
				}
				if ( ! isset( $old_surveys['surveys'][ $num_surveys ]['num_responses'] ) ) {
					continue;
				}
				$responses = array();
				$answers = wp_list_pluck( $old_surveys['surveys'][ $num_surveys ]['responses'], 'answers' );
				$responses = array(
					'survey_id' => $survey_id,
					);
				foreach ( $answers as $question_key => $array ) {
					foreach ( $array as $respondent_key => $answer ) {
						$responses[ $respondent_key ]['mykey'] = $respondent_key;
						if ( is_array( $answer ) ) {
						if ( 'checkbox' == $existing_elements[ $question_key ]['type'] ) {
							$possible_answers = $old_surveys['surveys'][ $num_surveys ]['responses'][ $question_key ]['answers'];
							$checkbox_answers = array();
							foreach ( $possible_answers as $checkbox_answer_key => $possible_answer ) {
								if ( in_array( $respondent_key, $possible_answer ) ) {
									$checkbox_answers[] = $checkbox_answer_key;
								}
							}
							$responses[ $respondent_key ][ $question_key ] = $checkbox_answers;
						} else {
								$possible_answers = $old_surveys['surveys'][ $num_surveys ]['responses'][ $question_key ]['answers'];
								foreach ( $possible_answers as $possible_answer_key => $possible_answer ) {
									if ( in_array( $respondent_key, $possible_answer ) ) {
										$responses[ $respondent_key ][ $question_key ] = $possible_answer_key;
										continue;
									}
								}
						}
					} else {
							$responses[ $respondent_key ][ $question_key ] = $answer;
						}
					}
				}
			}
			foreach ( $responses as $response ) {
				/*
				debug: todo - what if auth method is 'logged in?'
				respondent keys are different then and shouldn't be
				incremented.
				 */
				$respondent_key = $response['mykey'] + 1;
				unset( $response['mykey'] );
				wwmas_process_response( $survey_id, $response, $respondent_key );
			}
		}
	}
}

function wwmas_post_content_generator( $args = array(), $elements = array() ) {

		if ( ! class_exists( 'Form' ) ) {
			include_once( WWM_AWESOME_SURVEYS_PATH . 'includes/PFBC/Form.php' );
			include_once( WWM_AWESOME_SURVEYS_PATH . 'includes/PFBC/Overrides.php' );
		}
		$nonce = 'answer_survey_nonce';
		$has_options = array( 'Element_Select', 'Element_Checkbox', 'Element_Radio' );
		$form_output = new FormOverrides();
		$form_output->configure( array( 'class' => 'answer-survey pure-form pure-form-stacked', 'action' => $_SERVER['REQUEST_URI'], ) );
		$form_output->addElement( new Element_HTML( '<div class="overlay"><span class="preloader"></span></div>') );
		$questions_count = 0;
		foreach ( $elements as $element ) {
			$method = $element['type'];
			$atts = $rules = $options = array();
			if ( 'Element_Select' == $method ) {
				$options[''] = __( 'make a selection...', 'awesome-surveys' );
			}
			if ( isset( $element['validation']['rules'] ) ) {
				foreach ( $element['validation']['rules'] as $key => $value ) {
					if ( '' != $value ) {
						$rules['data-rule-' . $key] = $value;
					}
				}
			}
			if ( in_array( $method, $has_options ) ) {
				$atts = array_merge( $atts, $rules );
				if ( isset( $element['default'] ) ) {
					$atts['value'] = $element['default'];
				}
				if ( isset( $element['validation']['required'] ) && false != $element['validation']['required'] ) {
					$atts['required'] = 'required';
				}
				foreach ( $element['value'] as $key => $value ) {
					/**
						* append :pfbc to the key so that pfbc doesn't freak out
						* about numerically keyed arrays.
						*/
					$options[$value . ':pfbc'] = stripslashes( $element['label'][$key] );
				}
			} else {
				$options = array_merge( $options, $rules );
				if ( isset( $element['default'] ) ) {
					$options['value'] = $element['default'];
				}
				if ( isset( $element['validation']['required'] ) && false != $element['validation']['required'] ) {
					$options['required'] = 'required';
				}
			}
			$form_output->addElement( new $method( stripslashes( $element['name'] ), 'question[' . $questions_count . ']', $options, $atts ) );
			$questions_count++;
		}
		$form_output->addElement( new Element_Hidden( 'answer_survey_nonce', $nonce ) );
		$form_output->addElement( new Element_Hidden( 'survey_id', '', array( 'value' => $args['survey_id'], ) ) );
		$form_output->addElement( new Element_Hidden( 'action', 'answer-survey' ) );
		$form_output->addElement( new Element_Button( __( 'Submit Response', 'awesome-surveys' ), 'submit', array( 'class' => 'button-primary', 'disabled' => 'disabled' ) ) );
		return $form_output->render( true );
}

function wwmas_process_response( $survey_id, $response, $respondent_key ) {

	$post = get_post( $survey_id, 'OBJECT', 'display' );
	$saved_answers = get_post_meta( $survey_id, '_response', false );
	$existing_elements = json_decode( get_post_meta( $survey_id, 'existing_elements', true ), true );
	$responses = array();
	if ( empty( $existing_elements ) || is_null( $existing_elements ) ) {
		return false;
	}

	$multi_responses = array();
	foreach ( $existing_elements as $key => $question ) {
		$type = $question['type'];
		if ( 'checkbox' === $type && isset( $response[ $key ] ) ) {//the answers are an array
			$radio_answers = array();
			foreach ( $question['value'] as $multi_response_key => $otter_response ) {
				if ( isset( $response[ $key ][ $multi_response_key ] ) ) {
					$radio_answers[] = absint( $otter_response );
					//error_log( print_r( $radio_answers, true ) );
				}
			}
			$responses[ $respondent_key ][ $key ] = $radio_answers;
		} elseif( isset( $response[ $key ] ) && '' !== $response[ $key ] ) {
			$responses[ $respondent_key ][ $key ] = $response[ $key ];
		}
	}
	if ( ! empty( $responses ) ) {
		error_log( "setting post meta _response\n" . print_r( $responses, true ) );
		add_post_meta( $survey_id, '_response', $responses, false );
		//update_post_meta( $survey_id, 'num_responses', $num_responses );
	}

	if ( ! empty( $multi_responses ) ) {
		foreach ( $multi_responses as $key => $value ) {
			foreach ( $value as $answer_key => $answer_value ) {
				$count = get_post_meta( $survey_id, '_response_' . $key . '_' . $answer_key, true ) + 1;
				update_post_meta( $survey_id, '_response_' . $key . '_' . $answer_key, $count );
			}
		}
	}
return true;
}