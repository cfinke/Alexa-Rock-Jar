<?php

require "./config.php";

/**
 * @see https://github.com/cfinke/amazon-alexa-php
 */
require "./lib/amazon-alexa-php/src/Request/Request.php";
require "./lib/amazon-alexa-php/src/Request/Application.php";
require "./lib/amazon-alexa-php/src/Request/Certificate.php";
require "./lib/amazon-alexa-php/src/Request/IntentRequest.php";
require "./lib/amazon-alexa-php/src/Request/LaunchRequest.php";
require "./lib/amazon-alexa-php/src/Request/Session.php";
require "./lib/amazon-alexa-php/src/Request/SessionEndedRequest.php";
require "./lib/amazon-alexa-php/src/Request/User.php";
require "./lib/amazon-alexa-php/src/Response/Response.php";
require "./lib/amazon-alexa-php/src/Response/OutputSpeech.php";
require "./lib/amazon-alexa-php/src/Response/Card.php";
require "./lib/amazon-alexa-php/src/Response/Reprompt.php";

ob_start();

$raw_request = file_get_contents( "php://input" );

try {
	$alexa = new \Alexa\Request\Request( $raw_request, APPLICATION_ID );
	
	// Generate the right type of Request object
	$request = $alexa->fromData();

	$response = new \Alexa\Response\Response;
	
	// By default, always end the session unless there's a reason not to.
	$response->shouldEndSession = true;

	if ( 'LaunchRequest' === $request->data['request']['type'] ) {
		// Just opening the skill ("Open Rock Jar") responds with the instructions.
		handleIntent( $request, $response, 'AMAZON.HelpIntent' );
	}
	else {
		handleIntent( $request, $response, $request->intentName );
	}

	// A quirk of the library -- you need to call respond() to set up the final internal data for the response, but this has no output.
	$response->respond();

	echo json_encode( $response->render() );
} catch ( Exception $e ) {
	var_dump( $e );
	header( "HTTP/1.1 400 Bad Request" );
	exit;
}

$output = ob_get_clean();

ob_end_flush();

header( 'Content-Type: application/json' );
echo $output;
exit;

function state_file( $user_id ) {
	$state_dir = dirname( __FILE__ ) . "/state";
	
	$state_file = $state_dir . "/" . $user_id;
	
	touch( $state_file );
	
	if ( realpath( $state_file ) != $state_file ) {
		// Possible path traversal.
		return false;
	}
	
	return $state_file;
}

/**
 * Save the state of the session so that intents that rely on the previous response can function.
 *
 * @param string $session_id
 * @param mixed $state
 */
function save_state( $user_id, $state ) {
	$state_file = state_file( $user_id );

	if ( ! $state_file ) {
		return false;
	}
	
	if ( ! $state ) {
		if ( file_exists( $state_file ) ) {
			unlink( $state_file );
		}
	}
	else {
		file_put_contents( $state_file, json_encode( $state ) );
	}
}

/**
 * Get the current state of the session.
 *
 * @param string $session_id
 * @return object
 */
function get_state( $user_id ) {
	$state_file = state_file( $user_id );

	if ( ! $state_file ) {
		return new stdClass();
	}
	
	if ( ! file_exists( $state_file ) ) {
		return new stdClass();
	}

	return (object) json_decode( file_get_contents( $state_file ) );
}

/** 
 * Given an intent, handle all processing and response generation.
 * This is split up because one intent can lead into another; for example,
 * moderating a comment immediately launches the next step of the NewComments
 * intent.
 *
 * @param object $request The Request.
 * @param object $response The Response.
 * @param string $intent The intent to handle, regardless of $request->intentName
 */
function handleIntent( &$request, &$response, $intent ) {
	$user_id = $request->data['session']['user']['userId'];
	$state = get_state( $user_id );

	if ( ! $request->sesssion->new ) {
		switch ( $intent ) {
			case 'AMAZON.StopIntent':
			case 'AMAZON.CancelIntent':
				return;
			break;
		}
	}

	switch ( $intent ) {
		case 'AddRock':
			$rock_count = 1;
		case 'AddRocks':
			$name = $request->getSlot( 'Name' );
			$name = normalize_name( $name );

			if ( ! $name ) {
				$response->addOutput( "I'm sorry, I couldn't understand whose rock jar you wanted to add rocks to." );
				break;
			}

			if ( $request->getSlot( 'Number' ) ) {
				if ( ! is_numeric( $request->getSlot( 'Number' ) ) ) {
					$response->addOutput( "I'm sorry, I couldn't tell how many rocks you wanted to add to " . $name . "'s jar." );
					break;
				}
				
				$rock_count = (int) $request->getSlot( 'Number' );
			}
			
			if ( ! isset( $state->jars ) ) {
				$state->jars = new stdClass();
			}
			
			if ( ! isset( $state->jars->{ $name } ) ) {
				$state->jars->{ $name } = 0;
			}
			
			$state->jars->{ $name } += $rock_count;
			
			$response->addOutput( "Ok. I've added " . $rock_count . " rock" . ( $rock_count == 1 ? '' : 's' ) . " to " . $name . "'s jar. " . $name . " now has " . $state->jars->{ $name } . " rock" . ( $state->jars->{ $name } == 1 ? '' : 's' ) . "." );
			
			$state->last_response = $response;
			save_state( $user_id, $state );
		break;
		case 'SubtractRock':
			$rock_count = 1;
		case 'SubtractRocks':
			$name = $request->getSlot( 'Name' );
			$name = normalize_name( $name );
			
			if ( ! $name ) {
				$response->addOutput( "I'm sorry, I couldn't understand whose rock jar to remove rocks from." );
				break;
			}

			if ( $request->getSlot( 'Number' ) ) {
				if ( ! is_numeric( $request->getSlot( 'Number' ) ) ) {
					$response->addOutput( "I'm sorry, I couldn't tell how many rocks you wanted to take out of " . $name . "'s jar." );
					break;
				}
				
				$rock_count = (int) $request->getSlot( 'Number' );
			}
			
			if ( ! isset( $state->jars ) ) {
				$state->jars = new stdClass();
			}
			
			if ( ! isset( $state->jars->{ $name } ) ) {
				$state->jars->{ $name } = 0;
			}
			
			$state->jars->{ $name } -= $rock_count;

			$response->addOutput( "Ok. I've removed " . $rock_count . " rock" . ( $rock_count == 1 ? '' : 's' ) . " from " . $name . "'s jar. " . $name . " now has " . $state->jars->{ $name } . " rock" . ( $state->jars->{ $name } == 1 ? '' : 's' ) . "." );

			$state->last_response = $response;
			save_state( $user_id, $state );
		break;
		case 'CountRocks':
			if ( ! isset( $state->jars ) ) {
				$state->jars = new stdClass();
			}
			
			$name = $request->getSlot( 'Name' );
			$name = normalize_name( $name );
			
			if ( ! $name ) {
				$response->addOutput( "I'm sorry, I couldn't understand whose rock jar you wanted to check." );
				break;
			}
			
			if ( ! isset( $state->jars->{ $name } ) ) {
				$state->jars->{ $name } = 0;
			}
			
			$state->last_response = $response;
			
			$response->addOutput( $name . " has " . $state->jars->{ $name } . " rock" . ( $state->jars->{ $name } == 1 ? '' : 's' ) . "." );
			
			$response->addCardTitle( $name . "'s Rock Jar" );
			$response->addCardTitle( $name . "'s jar contains " . $state->jars->{ $name } . " rock" . ( $state->jars->{ $name } == 1 ? '' : 's' ) . "." );
			
			$state->last_response = $response;
			save_state( $user_id, $state );
		break;
		case 'EmptyJar':
			if ( ! isset( $state->jars ) ) {
				$state->jars = new stdClass();
			}
			
			$name = $request->getSlot( 'Name' );
			$name = normalize_name( $name );
			
			if ( ! isset( $state->jars->{ $name } ) ) {
				$state->jars->{ $name } = 0;
			}
			
			$rock_count = $state->jars->{ $name };
			$state->jars->{ $name } = 0;

			if ( $rock_count < 0 ) {
				$response->addOutput( $name . " already has negative rocks." );
			}
			else if ( $rock_count === 0 ) {
				$response->addOutput( $name . "'s jar is already empty." );
			}
			else if ( $rock_count === 1 ) {
				$response->addOutput( "Ok, I've taken the only rock out of " . $name . "'s jar." );
			}
			else {
				$response->addOutput( "Ok. I've taken all " . $rock_count . " rocks out of " . $name . "'s jar." );
			}
			
			$state->last_response = $response;
			save_state( $user_id, $state );
		break;
		case 'AMAZON.HelpIntent':
			$response->addOutput( "A rock jar is a method of tracking children's behavior." );
			$response->addOutput( "Here are some things you can say:" );
			$response->addOutput( "Give Gabriel one rock." );
			$response->addOutput( "How many rocks does Gabriel have?" );
			$response->addOutput( "Take a rock out of Gabriel's jar." );
			$response->addOutput( "Now, what would you like to do?" );
			
			$response->addCardTitle( "Using Rock Jar" );
			$response->addCardOutput( "A rock jar is a method of tracking children's behavior. Rocks are given for good behavior and taken away for bad behavior. When the jar is full (or has a pre-determined number of rocks 3), the child can exchange the rocks for a reward." );
			$response->addCardOutput( "Try these example phrases:" );
			$response->addCardOutput( "Alexa, tell Rock Jar to give Jim a rock." );
			$response->addCardOutput( "Alexa, ask Rock Jar how many rocks Susan has." );
			$response->addCardOutput( "Alexa, tell Rock Jar to take all of the rocks out of Alex's jar." );
			
			$response->shouldEndSession = false;
		break;
		case 'AMAZON.RepeatIntent':
			if ( ! $state || ! $state->last_response ) {
				$response->addOutput( "I'm sorry, I don't know what to repeat." );
			}
			else {
				save_state( $user_id, $state );
				$response->shouldEndSession = false;
				$response->output = $state->last_response->output;
				$response->shouldEndSession = false;
			}
		break;
	}
}

function normalize_name( $name ) {
	$name = preg_replace( "/\'s$/i", "", $name );

	return $name;
}