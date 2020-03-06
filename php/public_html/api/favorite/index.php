<?php

require_once dirname(__DIR__, 4) . "/vendor/autoload.php";
require_once dirname(__DIR__, 3) . "/Classes/autoload.php";
require_once("/etc/apache2/capstone-mysql/Secrets.php");
require_once dirname(__DIR__, 3) . "/lib/xsrf.php";
require_once dirname(__DIR__, 3) . "/lib/jwt.php";
require_once dirname(__DIR__, 3) . "/lib/uuid.php";


use \FindMeBeer\FindMeBeer\Favorite;

/**
 * Api for the Favorite class
 *
 * @author Patrick Leyba <pleyba4@cnm.edu>
 */

//verify the session, start if not active
if(session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

//prepare an empty reply
$reply = new stdClass();
$reply->status = 200;
$reply->data = null;

try {

	$secrets = new \Secrets("/etc/apache2/capstone-mysql/beerme.ini");
	$pdo = $secrets->getPdoObject();

	//determine which HTTP method was used
	$method = $_SERVER["HTTP_X_HTTP_METHOD"] ?? $_SERVER["REQUEST_METHOD"];


	//sanitize the search parameters
	$favoriteBeerId = $id = filter_input(INPUT_GET, "favoriteBeerId", FILTER_SANITIZE_STRING,FILTER_FLAG_NO_ENCODE_QUOTES);
	$favoriteUserId = $id = filter_input(INPUT_GET, "favoriteUserId", FILTER_SANITIZE_STRING,FILTER_FLAG_NO_ENCODE_QUOTES);

	if($method === "GET") {
		//set XSRF cookie
		setXsrfCookie();

		//gets  a specific like associated based on its composite key
		if ($favoriteBeerId !== null && $favoriteUserId !== null) {
			$favorite = Favorite::getFavoriteByFavoriteBeerIdAndFavoriteUserId($pdo, $favoriteBeerId, $favoriteUserId);


			if($favorite!== null) {
				$reply->data = $favorite;
			}
			//if none of the search parameters are met throw an exception
		} else if(empty($favoriteBeerId) === false) {
			$reply->data = Favorite::getFavoriteByFavoriteBeerId($pdo, $favoriteBeerId)->toArray();
			//get all the likes associated with the beerId
		} else if(empty($favoriteUserId) === false) {
			$reply->data = Favorite::getFavoriteByFavoriteUserId($pdo, $favoriteUserId)->toArray();
		} else {
			throw new InvalidArgumentException("incorrect search parameters ", 404);
		}

	} else if($method === "POST" || $method === "PUT") {

		//decode the response from the front end
		$requestContent = file_get_contents("php://input");
		$requestObject = json_decode($requestContent);

		if(empty($requestObject->favoriteBeerId) === true) {
			throw (new \InvalidArgumentException("No Beer linked to the Favorite", 405));
		}

		if(empty($requestObject->favoriteUserId) === true) {
			throw (new \InvalidArgumentException("No User linked to the Favorite", 405));
		}


		if($method === "POST") {

			//enforce that the end user has an XSRF token.
			verifyXsrf();

			//enforce the end user has a JWT token
			//validateJwtHeader();

			// enforce the user is signed in
			if(empty($_SESSION["beer"]) === true) {
				throw(new \InvalidArgumentException("you must be logged in too favorite beer", 403));
			}

			validateJwtHeader();

			$favorite = new Favorite($_SESSION["beer"]->getFavoriteUserId(), $requestObject->favoriteUserId);
			$favorite->insert($pdo);
			$reply->message = "favorite beer successful";


		} else if($method === "PUT") {

			//enforce the end user has a XSRF token.
			verifyXsrf();

			//enforce the end user has a JWT token
			validateJwtHeader();

			//grab the like by its composite key
			$favorite = Favorite::getFavoriteByFavoriteBeerIdAndFavoriteUserId($pdo, $requestObject->favoriteBeerId, $requestObject->favoriteUserId);
			if($favorite === null) {
				throw (new RuntimeException("Like does not exist"));
			}

			//enforce the user is signed in and only trying to edit their own like
			if(empty($_SESSION["beer"]) === true || $_SESSION["beer"]->getBeerId()->toString() !== $favorite->getFavoriteBeerId()->toString()) {
				throw(new \InvalidArgumentException("You are not allowed to delete this beer", 403));
			}

			//validateJwtHeader();

			//preform the actual delete
			$favorite->delete($pdo);

			//update the message
			$reply->message = "Favorite successfully deleted";
		}

		// if any other HTTP request is sent throw an exception
	} else {
		throw new \InvalidArgumentException("invalid http request", 400);
	}
	//catch any exceptions that is thrown and update the reply status and message
} catch(\Exception | \TypeError $exception) {
	$reply->status = $exception->getCode();
	$reply->message = $exception->getMessage();
}

header("Content-type: application/json");
if($reply->data === null) {
	unset($reply->data);
}

// encode and return reply to front end caller
echo json_encode($reply);