<?php
/**
 * Looks up user information from various public sources based on the supplied email.
 * Before using this module, you must add your own keys to config.php
 *  
 * The main entry point is the find_by_email() function. This calls the various services
 * that offer a mapping between an email address and user information, and returns an
 * array containing all the information found.
 *
 * The result is an associative array, with a string representing the service's name
 * pointing to an array of user information for each service. The user information is
 * itself an associative array, consisting of the following information, with each
 * item possibly an empty string if the service doesn't supply it:
 *
 * user_id: A numerical value identifying the user on the service
 * user_name: A human-readable identifier for the user, eg "petewarden"
 * display_name: The full name of the user, eg "Pete Warden"
 * portrait_url: A URL pointing to a photo of the user on the service
 * location: An unnormalized location string from the service, eg "Boulder, CO"
 *
 * Some services like Rapleaf, Friendfeed and DandyID return information on multiple
 * other services for a user. These appear in the results, but are overwritten if the
 * services themselves return information first.
 *
 * Licensed under the 2-clause (eg no advertising requirement) BSD license,
 * making it easy to reuse for commercial or GPL projects:
 
 (c) Pete Warden <pete@petewarden.com> http://petewarden.typepad.com/ Jan 8th 2010
 
 Redistribution and use in source and binary forms, with or without modification, are
 permitted provided that the following conditions are met:

   1. Redistributions of source code must retain the above copyright notice, this 
      list of conditions and the following disclaimer.
   2. Redistributions in binary form must reproduce the above copyright notice, this 
      list of conditions and the following disclaimer in the documentation and/or 
      other materials provided with the distribution.
   3. The name of the author may not be used to endorse or promote products derived 
      from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR 
PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, 
WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) 
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.

 */

require_once ('./http.php');
require_once ('./yosdk/lib/Yahoo.inc');  
require_once ('./config.php');

/**
 * Searches through public sources for user information, given an email address.
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 * @return array : of user info arrays, containing service_name, display_name,
 * user_name, user_id, portrait_url and location for any found users. If no users 
 * were found, the result will be an empty array, and any entry may also be an
 * empty string
 */
function find_by_email($email) {

	// These are ordered by confidence, so that the direct lookups are first and the 
	// conglomerators (dandyid etc) are later on. Later lookups won't overwrite the
	// earlier results, since the directs usually have more information
	$find_functions = array(
		'gravatar_find_by_email',
		'flickr_find_by_email',
		'yahoo_find_by_email',
		'fortythreethings_find_by_email',
		'vimeo_find_by_email',
		'amazon_find_by_email',
		'brightkite_find_by_email',
		'aim_find_by_email',
//		'skype_find_by_email',
		'friendfeed_find_by_email',
		'google_find_by_email',
		'rapleaf_find_by_email',
		'dandyid_find_by_email',
	);
	
	$result = array();
	foreach ($find_functions as $current_function) {
		$current_info = $current_function($email);
		if ($current_info!=null) {
			$result = array_merge($current_info, $result);
		}
	}
	
	// Reverse the results so they're in order of confidence
	$result = array_reverse($result, true);

	return $result;
}

/**
 * Calls the Gravatar API to get the portrait url for this email address
 *
 * @param string $email The email address of the user
 */
function gravatar_find_by_email($email) {
	
	$portrait_url = 'http://www.gravatar.com/avatar.php';
	$portrait_url .= '?gravatar_id='.urlencode(md5($email));
	$portrait_url .= '&default=404&size=40';
	
	if (!does_url_exist($portrait_url)) {
		return null;
	}

	$result = array(
		'gravatar' => array(
			'user_id' => '',
			'user_name' => '',
			'display_name' => '',
			'portrait_url' => $portrait_url,
			'location' => '',
		),
	);
	
	return $result;

}

/**
 * Calls the Flickr API to get information about the user associated with this
 * email address. For more details on the call, see
 * http://www.flickr.com/services/api/flickr.people.findByEmail.html
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function flickr_find_by_email($email) {
	
	// Call the Flickr API to get the user ID for the given email address
	$find_user_url = 'http://api.flickr.com/services/rest/?method=flickr.people.findByEmail&api_key=';
	$find_user_url .= FLICKR_API_KEY_PUBLIC;
	$find_user_url .= '&find_email='.urlencode($email);

	$find_user_result = http_request($find_user_url);
	if ( !did_http_succeed($find_user_result) ) {
		return null;
	}
	
	// Now we have the result, parse the XML to extract the Flickr ID for this user
	$find_user_xml_string = $find_user_result['body'];
	
	$find_user_parser = xml_parser_create();
	xml_parse_into_struct($find_user_parser, $find_user_xml_string, $vals, $index);
	xml_parser_free($find_user_parser);
	
	$user_id = get_first_xml_attribute('user', 'id', $index, $vals);
	
	// With the user id figured out, we can ask for more information on the user, including an avatar
	$user_info_url = 'http://api.flickr.com/services/rest/?method=flickr.people.getInfo&api_key=';
	$user_info_url .= FLICKR_API_KEY_PUBLIC;
	$user_info_url .= '&user_id='.urlencode($user_id);

	$user_info_result = http_request($user_info_url);
	if ( !did_http_succeed($user_info_result) ) {
		return null;
	}

	// Take the XML string containing all the user's information and pull it into a PHP array as the final result
	$user_info_xml_string = $user_info_result['body'];

	$user_info_parser = xml_parser_create();
	xml_parse_into_struct($user_info_parser, $user_info_xml_string, $vals, $index);
	xml_parser_free($user_info_parser);

	$icon_server = get_first_xml_attribute('person', 'iconserver', $index, $vals, 0);
	$icon_farm = get_first_xml_attribute('person', 'iconfarm', $index, $vals, 0);
	
	// We don't get a full URL for the portrait (aka 'buddy icon'), but there's a recipe to construct it given some of the attributes.
	// For the details see http://www.flickr.com/services/api/misc.buddyicons.html
	if ($icon_server>0) {
		$portrait_url = 'http://farm'.$icon_farm.'.static.flickr.com/'.$icon_server.'/buddyicons/'.urlencode($user_id).'.jpg';
	} else {
		$portrait_url = '';
	}

	// If we can't find a username, then the user wasn't found
	$user_name = get_first_xml_value('username', $index, $vals, null);
	if ($user_name==null)
		return null;
	$display_name = get_first_xml_value('realname', $index, $vals);
	$location = get_first_xml_value('location', $index, $vals);

	$result = array(
		'flickr' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);
	
	return $result;
}

/**
 * Calls the Yahoo social directory API to get information about the user associated 
 * with this email address. For more details on the call, see
 * http://query.yahooapis.com/v1/yql?q=select%20*%20from%20social.profile%20where%20guid%20in%20(select%20guid%20from%20yahoo.identity%20where%20yid%3D'petercwarden')&format=xml
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function yahoo_find_by_email($email) {
	
	// Check to see if it's a Yahoo email address. If not, we can't get any information on it.
	$email_parts = split('@', $email);
	if ( !isset($email_parts[1]) || ($email_parts[1]!='yahoo.com') ) {
		return null;
	}
	
	// The Yahoo user name is just the first part of the email address
	$user_name = $email_parts[0];

	$yahoo_app = new YahooApplication(YAHOO_API_KEY_APP, YAHOO_API_KEY_SHARED);  
	if ($yahoo_app == NULL) {  
		error_log("Couldn't create Yahoo application");
		return null;
	}
	
	// Run a YQL query to pull the user information for the given email address
	$query = "select * from social.profile where guid in (select guid from yahoo.identity where yid='$user_name')";
	$response = $yahoo_app->query($query);

	if ( !isset($response->query->results) ) {
		return null;
	}

	$results_object = $response->query->results;
	$profile = $results_object->profile;
	
	$user_id = $profile->guid;
	$display_name = '';
	$location = $profile->location;
	$portrait_url = $profile->image->imageUrl;
	
	// Check for an unset portrait. There's no guarantee that this path will remain the same, but
	// so far it seems to have been stable.
	if ( $portrait_url=='http://l.yimg.com/us.yimg.com/i/identity/nopic_192.gif' ) {
		$portrait_url = '';
	}
	
	$result = array(
		'yahoo' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);
	
	return $result;
}

/**
 * Calls the 43Things API to get information about the user associated with this
 * email address. For more details on the call, see
 * http://www.43things.com/about/view/web_service_methods_people#search_people_by_email
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function fortythreethings_find_by_email($email) {
	
	// Call the 43things API to get the user information for the given email address
	$user_info_url = 'http://www.43things.com/service/search_people_by_email?';
	$user_info_url .= 'api_key='.urlencode(FORTYTHREETHINGS_API_KEY);
	$user_info_url .= '&q='.urlencode($email);

	$user_info_result = http_request($user_info_url);	
	if ( !did_http_succeed($user_info_result) ) {
		return null;
	}

	// Take the XML string containing all the user's information and pull it into a PHP array as the final result
	$user_info_xml_string = $user_info_result['body'];

	$user_info_parser = xml_parser_create();
	xml_parse_into_struct($user_info_parser, $user_info_xml_string, $vals, $index);
	xml_parser_free($user_info_parser);

	// If we can't find a username, then the user wasn't found
	$user_name = get_first_xml_value('username', $index, $vals, null);
	if ($user_name==null)
		return null;
	
	$user_id = '';
	$display_name = get_first_xml_value('name', $index, $vals);
	$location = '';
	$portrait_url = get_first_xml_value('profile_image_url', $index, $vals);

	$result = array(
		'43things' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);
	
	return $result;
}

/**
 * Calls the Vimeo API to get information about the user associated with this
 * email address. For more details on the call, see
 * http://www.vimeo.com/api/docs/methods/vimeo.people.findByEmail
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function vimeo_find_by_email($email) {
	
	// Call the Vimeo API to get the user ID for the given email address
	$find_user_url = 'http://vimeo.com/api/rest/v2?method=vimeo.people.findByEmail&api_key=';
	$find_user_url .= VIMEO_API_KEY_PUBLIC;
	$find_user_url .= '&find_email='.urlencode($email);

	$find_user_result = http_request($find_user_url);
	if ( !did_http_succeed($find_user_result) ) {
		return null;
	}
	
	// Now we have the result, parse the XML to extract the Flickr ID for this user
	$find_user_xml_string = $find_user_result['body'];
	
	$find_user_parser = xml_parser_create();
	xml_parse_into_struct($find_user_parser, $find_user_xml_string, $vals, $index);
	xml_parser_free($find_user_parser);
	
	$user_id = get_first_xml_attribute('user', 'id', $index, $vals);
	
	// With the user id figured out, we can ask for more information on the user
	$user_info_url = 'http://vimeo.com/api/rest/v2?method=vimeo.people.getInfo&api_key=';
	$user_info_url .= VIMEO_API_KEY_PUBLIC;
	$user_info_url .= '&user_id='.urlencode($user_id);

	$user_info_result = http_request($user_info_url);
	if ( !did_http_succeed($user_info_result) ) {
		return null;
	}

	// Take the XML string containing all the user's information and pull it into a PHP array as the final result
	$user_info_xml_string = $user_info_result['body'];

	$user_info_parser = xml_parser_create();
	xml_parse_into_struct($user_info_parser, $user_info_xml_string, $vals, $index);
	xml_parser_free($user_info_parser);

	// If we can't find a username, then the user wasn't found
	$user_name = get_first_xml_value('username', $index, $vals, null);
	if ($user_name==null)
		return null;
	$display_name = get_first_xml_value('displayname', $index, $vals);
	$location = get_first_xml_value('location', $index, $vals);

	// Vimeo requires us to make a separate call to fetch the user's portraits
	$get_portraits_url = 'http://vimeo.com/api/rest/v2?method=vimeo.people.getPortraitUrls&api_key=';
	$get_portraits_url .= VIMEO_API_KEY_PUBLIC;
	$get_portraits_url .= '&user_id='.urlencode($user_id);

	$get_portraits_result = http_request($get_portraits_url);
	if ( !did_http_succeed($get_portraits_result) ) {
		return null;
	}	

	$get_portraits_xml_string = $get_portraits_result['body'];

	$get_portraits_parser = xml_parser_create();
	xml_parse_into_struct($get_portraits_parser, $get_portraits_xml_string, $vals, $index);
	xml_parser_free($get_portraits_parser);

	$portrait_url = get_first_xml_value('portrait', $index, $vals);
	// Check for an unset portrait. There's no guarantee that this path will remain the same
	if ( $portrait_url == 'http://bitcast.vimeo.com/vimeo/portraits/defaults/d.30.jpg' ) {
		$portrait_url = '';
	}

	$result = array(
		'vimeo' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);
	
	return $result;
}

/**
 * Calls the Amazon API to get information about the user associated with this
 * email address. For more details on the call, see
 * http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/CustomerContentSearch.html
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function amazon_find_by_email($email) {

	$find_user_url = 'http://ecs.amazonaws.com/onca/xml?Service=AWSECommerceService&Operation=CustomerContentSearch';
	$find_user_url .= '&Email='.urlencode($email);
	$signed_find_user_url = get_signed_amazon_api_url($find_user_url);

	$find_user_result = http_request($signed_find_user_url);
	if ( !did_http_succeed($find_user_result) ) {
		return null;
	}
	
	$find_user_xml_string = $find_user_result['body'];
	
	$find_user_parser = xml_parser_create();
	xml_parse_into_struct($find_user_parser, $find_user_xml_string, $vals, $index);
	xml_parser_free($find_user_parser);

	$user_id = get_first_xml_value('customerid', $index, $vals, null);
	if ($user_id==null)
		return null;

	$user_info_url = 'http://ecs.amazonaws.com/onca/xml?Service=AWSECommerceService&Operation=CustomerContentLookup';
	$user_info_url .= '&CustomerId='.urlencode($user_id);
	$signed_user_info_url = get_signed_amazon_api_url($user_info_url);

	$user_info_result = http_request($signed_user_info_url);
	if ( !did_http_succeed($user_info_result) ) {
		return null;
	}

	// Take the XML string containing all the user's information and pull it into a PHP array as the final result
	$user_info_xml_string = $user_info_result['body'];

	$user_info_parser = xml_parser_create();
	xml_parse_into_struct($user_info_parser, $user_info_xml_string, $vals, $index);
	xml_parser_free($user_info_parser);
	
	$user_name = get_first_xml_value('nickname', $index, $vals);
	$display_name = '';
	$location = get_first_xml_value('userdefinedlocation', $index, $vals);
	$portrait_url = '';
	
	// It's possible to get the user's portrait by fetching the HTML of their profile page 
	// (it's always of the form http://www.amazon.com/gp/pdp/profile/ <user_id> ), but I'm
	// not sure we want to get into scraping...

	$result = array(
		'amazon' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);
	
	return $result;
}

/**
 * Calls the Friendfeed API to get information about the user associated with this
 * email address. For more details on the call, see
 * http://code.google.com/p/friendfeed-api/wiki/ApiDocumentation#/NICKNAME/picture_-_Get_a_user%27s_profile_picture
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function friendfeed_find_by_email($email) {

	$find_user_url = 'http://friendfeed.com/api/feed/user?emails='.urlencode($email);

	$find_user_result = http_request($find_user_url);
	if ( !did_http_succeed($find_user_result) ) {
		return null;
	}
	
	$find_user_json_string = $find_user_result['body'];
	$find_user_object = json_decode($find_user_json_string, true);
	
	if (!isset($find_user_object['entries'][0])) {
		return null;
	}

	$first_entry = $find_user_object['entries'][0];
	$first_entry_user = $first_entry['user'];
	
	$user_name = $first_entry_user['nickname'];
	$user_id = $first_entry_user['id'];
	
	$get_profile_url = 'http://friendfeed.com/api/user/'.urlencode($user_name).'/profile';

	$get_profile_result = http_request($get_profile_url);
	if ( !did_http_succeed($get_profile_result) ) {
		return null;
	}
	
	$get_profile_json_string = $get_profile_result['body'];
	$get_profile_object = json_decode($get_profile_json_string, true);

	$display_name = $get_profile_object['name'];
	$portrait_url = 'http://friendfeed.com/'.urlencode($user_name).'/picture?size=medium';
	$location = '';

	$result = array(
		'friendfeed' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);
	
	// Friendfeed returns a list of associated services, so add any that we
	// recognize to the results too
	if (isset($get_profile_object['services']))
	{
		$all_services = $get_profile_object['services'];
		foreach ($all_services as $service)
		{
			$service_name = $service['id'];
			if (isset($service['username'])) {
				$user_name = $service['username'];
			} else {
				$user_name = '';
			}
			if (isset($service['profileUrl'])) {
				$profile_url = $service['profileUrl'];
			} else {
				$profile_url = '';
			}
			$user_id = '';
			
			$service_result = get_profile_for_service($service_name, $user_name, $user_id, $profile_url);
			if (isset($service_result)) {
				$result[$service_name] = $service_result;
			}
		
		}
	
	}
	
	return $result;
}

/**
 * Calls the Brightkite API to get information about the user associated with this
 * email address. For more details on the call, see
 * http://groups.google.com/group/brightkite-api/web/rest-api
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function brightkite_find_by_email($email) {

	$user_info_url = 'http://brightkite.com/people/search.xml?query='.urlencode($email);

	$user_info_result = http_request($user_info_url);
	if ( !did_http_succeed($user_info_result) ) {
		return null;
	}

	// Take the XML string containing all the user's information and pull it into a PHP array as the final result
	$user_info_xml_string = $user_info_result['body'];

	$user_info_parser = xml_parser_create();
	xml_parse_into_struct($user_info_parser, $user_info_xml_string, $vals, $index);
	xml_parser_free($user_info_parser);
	
	// If we can't find a username, then the user wasn't found
	$user_name = get_first_xml_value('login', $index, $vals, null);
	if ($user_name==null)
		return null;
	$display_name = get_first_xml_value('fullname', $index, $vals);
	$location = get_first_xml_value('display_location', $index, $vals);
	$portrait_url = get_first_xml_value('small_avatar_url', $index, $vals);
	if ($portrait_url=='http://brightkite.com/images/default_user_avatar_small.png') {
		$portrait_url = '';
	}
	
	$user_id = '';

	$result = array(
		'brightkite' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);
	
	return $result;
}

/**
 * Calls the DandyID API to get information about the user associated with this
 * email address. For more details on the call, see
 * http://www.dandyid.org/api/documentation/return_profile
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function dandyid_find_by_email($email) {

	$user_info_url = 'http://www.dandyId.org/api/return_profile/';
	$user_info_url .= DANDYID_API_KEY_PUBLIC;
	$user_info_url .= '/'.urlencode($email).'/public';
	
	$user_info_result = http_request($user_info_url);
	if ( !did_http_succeed($user_info_result) ) {
		return null;
	}

	// Take the XML string containing all the user's information and pull it into a PHP array as the final result
	$user_info_xml_string = $user_info_result['body'];

	$user_info_parser = xml_parser_create();
	xml_parse_into_struct($user_info_parser, $user_info_xml_string, $vals, $index);
	xml_parser_free($user_info_parser);
	
	// If we can't find a username, then the user wasn't found
	$user_id = get_first_xml_value('userid', $index, $vals, null);
	if ($user_id==null)
		return null;
	$user_name = get_first_xml_value('nickname', $index, $vals);

	$first_name = get_first_xml_value('firstname', $index, $vals);
	$last_name = get_first_xml_value('lastname', $index, $vals);
	$display_name = "$first_name $last_name";

	$street = get_first_xml_value('street', $index, $vals);
	$city = get_first_xml_value('city', $index, $vals);
	$region = get_first_xml_value('region', $index, $vals);
	$country = get_first_xml_value('region', $index, $vals);

	$location = '';
	if ($street!='')
		$location .= $street.', ';
	if ($city!='')
		$location .= $city.', ';
	if ($region!='')
		$location .= $region.', ';
	if ($country!='')
		$location .= $country;

	$portrait_url = '';

	$result = array(
		'dandyid' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);

	$all_services_url = 'http://www.dandyId.org/api/return_services/';
	$all_services_url .= DANDYID_API_KEY_PUBLIC;
	$all_services_url .= '/'.urlencode($email).'/public';

	$all_services_result = http_request($all_services_url);
	if ( !did_http_succeed($all_services_result) ) {
		return null;
	}

	// Take the XML string containing all the user's information and pull it into a PHP array as the final result
	$all_services_object = convert_xml_string_to_array($all_services_result['body']);

	// Add any services we recognize to the result
	$all_services = $all_services_object['services']['service'];
	foreach ($all_services as $service) {
		$service_name = $service['svcId'];
		if (isset($service['usrSvcId'])) {
			$user_name = $service['usrSvcId'];
		} else {
			$user_name = '';
		}
		$user_id = '';
		if (isset($service['url'])) {
			$profile_url = $service['url'];
		} else {
			$profile_url = '';
		}

		$service_result = get_profile_for_service($service_name, $user_name, $user_id, $profile_url);
		if (isset($service_result)) {
			$result[$service_name] = $service_result;
		}	
	}
	
	return $result;	
}

/**
 * Calls the Rapleaf API to get information about the user associated with this
 * email address. For more details on the call, see
 * http://www.rapleaf.com/apidoc/v2/person
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function rapleaf_find_by_email($email) {

	$user_info_url = 'http://api.rapleaf.com/v2/person/';
	$user_info_url .= urlencode($email);
	$user_info_url .= '?api_key='.RAPLEAF_API_KEY;
	
	$user_info_result = http_request($user_info_url);
	if ( !did_http_succeed($user_info_result) ) {
		return null;
	}
	
	// Take the XML string containing all the user's information and pull it into a PHP array as the final result
	$user_info_xml_string = $user_info_result['body'];

	$user_info_object = convert_xml_string_to_array($user_info_xml_string);

	if (!isset($user_info_object['person']['@attributes']['id']))
		return null;

	$user_id = $user_info_object['person']['@attributes']['id'];
	$user_name = '';
	
	if (isset($user_info_object['person']['basics']['name'])) {
		$display_name = $user_info_object['person']['basics']['name'];
	} else {
		$display_name = '';
	}
	if (isset($user_info_object['person']['basics']['location'])) {
		$location = $user_info_object['person']['basics']['location'];
	} else {
		$location = '';
	}
	
	$portrait_url = '';

	$result = array(
		'rapleaf' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);

	$all_services = $user_info_object['person']['memberships']['primary']['membership'];
	foreach ($all_services as $service) {
		
		$service_url = $service['@attributes']['site'];
		if (preg_match('/(.*)\.com/', $service_url, $matches)) {
			$service_name = $matches[1];
		} else {
			$service_name = $service_url;
		}
		
		$user_name = '';
		$user_id = '';

		if (!isset($service['@attributes']['profile_url']))
			continue;
	
		$profile_url = $service['@attributes']['profile_url'];
		
		$service_result = get_profile_for_service($service_name, $user_name, $user_id, $profile_url);
		if (isset($service_result)) {
		
			// If Rapleaf has given us an image URL, and there's none found otherwise, use theirs
			if (($service_result['portrait_url']=='') && 
				(isset($service['@attributes']['image_url']))) {
				$service_result['portrait_url'] = $service['@attributes']['image_url'];
			}
		
			$result[$service_name] = $service_result;
		}	
	}
	
	return $result;	
}

/**
 * Calls the AIM API to get information about the user associated with this
 * email address. For more details on the call, see
 * http://dev.aol.com/aim/web/serverapi_reference#getPresence
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function aim_find_by_email($email) {

	$user_info_url = 'http://api.oscar.aol.com/presence/get?f=json';
	$user_info_url .= '&k='.AIM_API_KEY;
	$user_info_url .= '&t='.urlencode($email).'&emailLookup=1&notFound=1';
	
	$user_info_result = http_request($user_info_url);
	if ( !did_http_succeed($user_info_result) ) {
		return null;
	}

	// Take the JSON string containing all the user's information and pull it into a PHP array as the final result
	$user_info_json_string = $user_info_result['body'];

	$user_info_object = json_decode($user_info_json_string, true);
	
	if (!isset($user_info_object['response']['data']['users'][0])) {
		return null;
	}
	
	$user_data = $user_info_object['response']['data']['users'][0];
	if (!isset($user_data['buddyIcon'])) {
		return null;
	}
	$portrait_url = $user_data['buddyIcon'];
	$user_name = $user_data['aimId'];
	$user_id = '';
	$location = '';
	$display_name = '';

	$result = array(
		'aim' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);
	
	return $result;	
}

/**
 * Calls the Skype API to get information about the user associated with this
 * email address. For more details on the call, see
 * https://developer.skype.com/Docs/ApiDoc/SEARCH_USERS
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function skype_find_by_email($email) {

	$skype_command = 'export DISPLAY=:5.0; /usr/bin/php /vol/bin/skype/fetchbyemail.php '.escapeshellcmd($email).' 2>&1';
	$skype_result_string = shell_exec($skype_command);
	if ($skype_result_string=='None found')
		return null;

	$skype_results = explode(',', $skype_result_string);

	$user_name = $skype_results[0];
	if ($user_name=='')
		return null;
		
	$portrait_url = '';
	$user_id = '';
	$location = '';
	$display_name = '';

	$result = array(
		'skype' => array(
			'user_id' => $user_id,
			'user_name' => $user_name,
			'display_name' => $display_name,
			'portrait_url' => $portrait_url,
			'location' => $location,
		),
	);
	
	return $result;	
}

/**
 * Calls the Google API to get information about the user associated with this
 * email address. For more details on the call, see
 * http://code.google.com/apis/socialgraph/docs/
 *
 * @since Unknown
 *
 * @param string $email The email address of the user
 */
function google_find_by_email($email) {

	$user_info_url = 'http://socialgraph.apis.google.com/lookup';
	$user_info_url .= '?q='.urlencode('mailto:'.$email);
	$user_info_url .= '&fme=1&edi=1&edo=1&pretty=1&sgn=1&callback=';

	$user_info_result = http_request($user_info_url);
	if ( !did_http_succeed($user_info_result) ) {
		return null;
	}

	// Take the JSON string containing all the user's information and pull it into a PHP array as the final result
	$user_info_json_string = $user_info_result['body'];

	$user_info_object = json_decode($user_info_json_string, true);
	if (!isset($user_info_object['nodes'])) {
		return null;
	}

	$all_nodes = $user_info_object['nodes'];

	$result = null;
	foreach ($all_nodes as $current_id => $node)
	{
		if (!isset($node['attributes'])) {
			continue;
		}
		
		$attributes = $node['attributes'];
		if (!isset($attributes['photo'])) {
			continue;
		}
		
		$portrait_url = $attributes['photo'];
		if (isset($attributes['fn'])) {
			$display_name = $attributes['fn'];
		} else {
			$display_name = '';
		}
		
		$user_name = '';
		$user_id = '';
		$location = '';
	
		$result = array(
			'google' => array(
				'user_id' => $user_id,
				'user_name' => $user_name,
				'display_name' => $display_name,
				'portrait_url' => $portrait_url,
				'location' => $location,
			),
		);
		
		break;
	}
	
	return $result;
}

/**
 * Some services like Friendfeed, DandyID and Rapleaf return a list of external
 * accounts that the user is signed up for. This function looks at the information
 * and returns a profile object for any that are recognized.
 *
 * @param string $service_name The identifier for the service, eg 'facebook', 'twitter, etc
 * @param string $user_name Either a known username or '' if it's not supplied
 * @param string $user_id Either a numerical identifier for the user on this service, or '' if not known
 * @param string $profile_url The location of the web page for this user. This may be parsed to extract 
 * usernames or ids if they're not supplied and it's a known service
 * @return array If the information is recognized, a profile array, or null if not found
 */
function get_profile_for_service($service_name, $user_name, $user_id, $profile_url) {

	$result = null;
	switch ($service_name) {
	
		case 'twitter': {
			if ($user_name!='') {
			
				$portrait_url = 'http://overtar.appspot.com/';
				$portrait_url .= urlencode($user_name.'@twitter');
				$result = array(
					'user_id' => '',
					'user_name' => $user_name,
					'display_name' => '',
					'portrait_url' => $portrait_url,
					'location' => '',
				);
			}
		} break;

		case 'facebook': {
			// If we weren't given a user name or ID, try to parse the profile URL to extract them
			if (($user_name=='') && ($user_id=='')) {
				if (preg_match('/id=([0-9]+)/', $profile_url, $matches)) {
					$user_id = $matches[1];
				}
			}

			if (($user_name!='') || ($user_id!='')) {
			
				// DandyID passes the Facebook user ID as the name, so try to fix that
				if (is_numeric($user_name)) {
					$user_id = $user_name;
					$user_name = '';
				}
			
				$portrait_url = 'http://overtar.appspot.com/';
				if ($user_name!='') {
					$portrait_url .= urlencode($user_name.'@facebook');
				} else {
					$portrait_url .= urlencode('#'.$user_id.'@facebook');				
				}
				$portrait_url .= '/default/404';
				
				if (!does_url_exist($portrait_url)) {
					$portrait_url = '';
				}

				$result = array(
					'user_id' => $user_id,
					'user_name' => $user_name,
					'display_name' => '',
					'portrait_url' => $portrait_url,
					'location' => '',
				);
			}
		} break;

		case 'linkedin': {
			// If we weren't given a user name or ID, try to parse the profile URL to extract them
			if (($user_name=='') && ($user_id=='')) {
				if (preg_match('@linkedin.com/in/(.+)@', $profile_url, $matches)) {
					$user_name = $matches[1];
				}
			}

			if (($user_name!='') || ($user_id!='')) {
			
				$result = array(
					'user_id' => $user_id,
					'user_name' => $user_name,
					'display_name' => '',
					'portrait_url' => '',
					'location' => '',
				);
			}
		} break;

		case 'delicious': {
			if ($user_name!='') {
			
				$result = array(
					'user_id' => '',
					'user_name' => $user_name,
					'display_name' => '',
					'portrait_url' => '',
					'location' => '',
				);
			}
		} break;

		case 'intensedebate': {
			if ($user_name!='') {
			
				$result = array(
					'user_id' => '',
					'user_name' => $user_name,
					'display_name' => '',
					'portrait_url' => '',
					'location' => '',
				);
			}
		} break;

		case 'disqus': {
			if ($user_name!='') {
			
				$result = array(
					'user_id' => '',
					'user_name' => $user_name,
					'display_name' => '',
					'portrait_url' => '',
					'location' => '',
				);
			}
		} break;

		case 'digg': {
			if ($user_name!='') {
			
				$portrait_url = 'http://overtar.appspot.com/';
				$portrait_url .= urlencode($user_name.'@digg').'/default/404';

				if (!does_url_exist($portrait_url)) {
					$portrait_url = '';
				}

				$result = array(
					'user_id' => '',
					'user_name' => $user_name,
					'display_name' => '',
					'portrait_url' => $portrait_url,
					'location' => '',
				);
			}
		} break;

		case 'aim': {
			if ($user_name!='') {
			
				$result = array(
					'user_id' => '',
					'user_name' => $user_name,
					'display_name' => '',
					'portrait_url' => '',
					'location' => '',
				);
			}
		} break;

		case 'myspace': {
			// If we weren't given a user name or ID, try to parse the profile URL to extract them
			if (($user_name=='') && ($user_id=='')) {
				if (preg_match('/friendid=([0-9]+)/', $profile_url, $matches)) {
					$user_id = $matches[1];
				}
			}

			if (($user_name!='') || ($user_id!='')) {

				$result = array(
					'user_id' => $user_id,
					'user_name' => $user_name,
					'display_name' => '',
					'portrait_url' => '',
					'location' => '',
				);
			}
		} break;
	}
	
	return $result;
}

/**
 * A utility function for a common case of XML parsing. It returns the value for
 * the first occurrence of the given tag name. It requires the parsing arrays
 * returned from xml_parse_into_struct()
 *
 * @since Unknown
 *
 * @param string $tagname The name of the tag to search for
 * @param array $index The indices of all the tags parsed into an array by xml_parse_into_struct
 * @param array $vals The values of all the tags parsed into an array by xml_parse_into_struct
 * @param string $default If the tag isn't found, this value is returned instead. Defaults to an empty string
 * @return string The value of the first tag, or the default 
 */
function get_first_xml_value($tagname, $index, $vals, $default = '')
{
	$upper_tagname = strtoupper($tagname);
	if ( !isset($index[$upper_tagname]) || !isset($index[$upper_tagname][0]) )
		return $default;
	
	$tag_index = $index[$upper_tagname][0];
	if ( isset($vals[$tag_index]['value']) ) {
		$value = $vals[$tag_index]['value'];
	} else {
		$value = $default;
	}
	
	return $value;
}

/**
 * A utility function for a common case of XML parsing. It returns the value for
 * the given attribute of the first occurrence of the given tag name. It requires 
 * the parsing arrays returned from xml_parse_into_struct()
 *
 * @since Unknown
 *
 * @param string $tagname The name of the tag to search for
 * @param string $attribute_name The name of the attribute within the tag to search for
 * @param array $index The indices of all the tags parsed into an array by xml_parse_into_struct
 * @param array $vals The values of all the tags parsed into an array by xml_parse_into_struct
 * @param string $default If the tag isn't found, this value is returned instead. Defaults to an empty string
 * @return string The value of the first tag, or the default 
 */
function get_first_xml_attribute($tagname, $attribute_name, $index, $vals, $default = '')
{
	$upper_tagname = strtoupper($tagname);
	if ( !isset($index[$upper_tagname]) || !isset($index[$upper_tagname][0]) )
		return $default;
	
	$tag_index = $index[$upper_tagname][0];
	if ( !isset($vals[$tag_index]['attributes']) )
		return $default;

	$attributes = $vals[$tag_index]['attributes'];
	
	$upper_attribute_name = strtoupper($attribute_name);
	if ( !isset($attributes[$upper_attribute_name]) )
		return $default;

	$attribute_value = $attributes[$upper_attribute_name];
	
	return $attribute_value;
}

/**
 * A utility function to make sure that the API call succeeded
 *
 * @since Unknown
 *
 * @param object $http_result The result from the Http request call
 * @return boolean Whether the call succeeded 
 */
function did_http_succeed($http_result)
{
	if ( http_is_error($http_result) )
		return false;

	$user_info_code = $http_result['code'];
	if ( $user_info_code!=200 ) {
		$user_info_message = $http_result['message'];
		error_log("API call failed with code $user_info_code and message '$user_info_message'");
		error_log(print_r($http_result, true));
		return false;
	}
	
	return true;
}

/**
 * A utility function to sign an Amazon REST API call
 * See http://www.a2sdeveloper.com/page-rest-authentication-for-php4.html
 *
 * @since Unknown
 *
 * @param string $input_url The original URL for the API call
 * @return string The input URL with the timestamp and signature parameters added 
 */
function get_signed_amazon_api_url($input_url)
{
	$url_parts = parse_url($input_url);

	$url_query = $url_parts['query'];
	
	$parameters = array();
	parse_str($url_query, $parameters);
	$parameters['Timestamp'] = gmdate("Y-m-d\TH:i:s\Z"); 
	$parameters['Version'] = '2009-03-01';
	$parameters['AWSAccessKeyId'] = AMAZON_API_KEY_PUBLIC;

	ksort($parameters);

	$encoded_parameters = array(); 
	foreach ($parameters as $parameter=>$value) { 
		$parameter = str_replace('_', '.', $parameter); 
		$parameter = str_replace('%7E', '~', rawurlencode($parameter)); 
		$value = str_replace('%7E', '~', rawurlencode($value)); 
		$encoded_parameters[] = $parameter . '=' . $value; 
	} 
	$encoded_string = implode('&', $encoded_parameters);

	$signature_string = 'GET' . chr(10) . $url_parts['host'] . chr(10) . $url_parts['path'] . chr(10) . $encoded_string;

	$signature = urlencode(base64_encode(hash_hmac("sha256", $signature_string, AMAZON_API_KEY_SECRET, True)));
 
	$result = 'http://' . $url_parts['host'] . $url_parts['path'] . '?' . $encoded_string . '&Signature=' . $signature; 

	return $result;
}

/**
 * This is a utility function that takes an XML string and returns the contents
 * as a native PHP array, to make it easier to work with.
 * The main work is done by the recursive function convert_xml_element_to_array()
 *
 * @param string $xml_string The contents of an XML file
 * @return array All the tags in the XML converted into a nested associative array
 */
function convert_xml_string_to_array($xml_string) {

	$xml_root_element = simplexml_load_string($xml_string);
	
	$result = convert_xml_element_to_array($xml_root_element);
	
	return $result;
}

/**
 * This is a utility function that takes a simpleXMLElement object and returns the contents
 * as a nested associative PHP array, to make it easier to work with. It's called recursively
 * to deal with an entire document. Adapted from
 * http://www.ibm.com/developerworks/xml/library/x-xml2jsonphp/
 *
 * @param string $xml_element A single element, possibly containing children
 * @param int $recursion_depth The depth of the call stack
 * @return array All the tags in the XML element converted into an associative array
 */
function convert_xml_element_to_array($xml_element, &$recursion_depth=0) { 

	// If we're getting too deep, bail out
	if ($recursion_depth > 512) {
		return(null);
	}
	
	if (!is_string($xml_element) && 
        !is_array($xml_element) &&
        (get_class($xml_element) == 'SimpleXMLElement')) {
		$xml_element_copy = $xml_element;
		$xml_element = get_object_vars($xml_element);
	}

	if (is_array($xml_element)) {

		$result_array = array();
		if (count($xml_element) <= 0) {
			return (trim(strval($xml_element_copy)));
		}

		foreach($xml_element as $key=>$value) {

			$recursion_depth++; 
			$result_array[$key] = 
			convert_xml_element_to_array($value, $recursion_depth);
			$recursion_depth--;
		}


		if ($recursion_depth == 0) {
			$temp_array = $result_array;
			$result_array = array(
				$xml_element_copy->getName() => $temp_array,
			);
		}

		return ($result_array);

	} else {
		return (trim(strval($xml_element)));
	}
}

/**
 * This is a utility function to check whether a URL is reachable
 *
 * @param string $url The address to check
 * @return boolean Whether the URL could be reached
 */
function does_url_exist($url) {
				
	$url_result = http_request($url, true);
	
	if ( http_is_error($url_result) )
		return false;

	$status_code = $url_result['code'];
	return ( $status_code==200 );
}

?>
