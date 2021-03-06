<?php
/**
 * This is an HTTP client class for Cloud Files.  It uses PHP's cURL module
 * to handle the actual HTTP request/response.  This is NOT a generic HTTP
 * client class and is only used to abstract out the HTTP communication for
 * the PHP Cloud Files API.
 *
 * This module was designed to re-use existing HTTP(S) connections between
 * subsequent operations.  For example, performing multiple PUT operations
 * will re-use the same connection.
 *
 * This modules also provides support for streaming content into and out
 * of Cloud Files.  The majority (all?) of the PHP HTTP client modules expect
 * to read the server's response into a string variable.  This will not work
 * with large files without killing your server.  Methods like,
 * get_object_to_stream() and put_object() take an open filehandle
 * argument for streaming data out of or into Cloud Files.
 *
 * Requres PHP 5.x (for Exceptions and OO syntax)
 *
 * See COPYING for license information.
 *
 * @author Eric "EJ" Johnson <ej@racklabs.com>
 * @copyright Copyright (c) 2008, Rackspace US, Inc.
 * @package php-cloudfiles-http
 */

/**
 */
require_once("cloudfiles_exceptions.php");

define("PHP_CF_VERSION", "1.3.0");
define("USER_AGENT", sprintf("PHP-CloudFiles/%s", PHP_CF_VERSION));
define("ACCOUNT_CONTAINER_COUNT", "X-Account-Container-Count");
define("ACCOUNT_BYTES_USED", "X-Account-Bytes-Used");
define("CONTAINER_OBJ_COUNT", "X-Container-Object-Count");
define("CONTAINER_BYTES_USED", "X-Container-Bytes-Used");
define("METADATA_HEADER", "X-Object-Meta-");
define("CDN_URI", "X-CDN-URI");
define("CDN_ENABLED", "X-CDN-Enabled");
define("CDN_TTL", "X-TTL");
define("CDNM_URL", "X-CDN-Management-Url");
define("STORAGE_URL", "X-Storage-Url");
define("AUTH_TOKEN", "X-Auth-Token");
define("AUTH_USER_HEADER", "X-Auth-User");
define("AUTH_KEY_HEADER", "X-Auth-Key");
define("AUTH_USER_HEADER_LEGACY", "X-Storage-User");
define("AUTH_KEY_HEADER_LEGACY", "X-Storage-Pass");
define("AUTH_TOKEN_LEGACY", "X-Storage-Token");

/**
 * HTTP/cURL wrapper for Cloud Files
 *
 * This class should not be used directly.  It's only purpose is to abstract
 * out the HTTP communication from the main API.
 *
 * @package php-cloudfiles-http
 */
class CF_Http
{
    private $error_str;
    private $dbug;
    private $cabundle_path;
    private $api_version;

    # Authentication instance variables
    #
    private $storage_url;
    private $cdnm_url;
    private $auth_token;

    # Request/response variables
    #
    private $response_status;
    private $response_reason;
    private $connections;

    # Variables used for content/header callbacks
    #
    private $_user_read_progress_callback_func;
    private $_user_write_progress_callback_func;
    private $_write_callback_type;
    private $_text_list;
    private $_account_container_count;
    private $_account_bytes_used;
    private $_container_object_count;
    private $_container_bytes_used;
    private $_obj_etag;
    private $_obj_last_modified;
    private $_obj_content_type;
    private $_obj_content_length;
    private $_obj_metadata;
    private $_obj_write_resource;
    private $_obj_write_string;
    private $_cdn_enabled;
    private $_cdn_uri;
    private $_cdn_ttl;

    function __construct($api_version)
    {
        $this->dbug = False;
        $this->cabundle_path = NULL;
        $this->api_version = $api_version;
        $this->error_str = NULL;

        $this->storage_url = NULL;
        $this->cdnm_url = NULL;
        $this->auth_token = NULL;

        $this->response_status = NULL;
        $this->response_reason = NULL;

        # Curl connections array - since there is no way to "re-set" the
        # connection paramaters for a cURL handle, we keep an array of
        # the unique use-cases and funnel all of those same type
        # requests through the appropriate curl connection.
        #
        $this->connections = array(
            "GET_CALL"  => NULL, # GET objects/containers/lists
            "PUT_OBJ"   => NULL, # PUT object
            "HEAD"      => NULL, # HEAD requests
            "PUT_CONT"  => NULL, # PUT container
            "DEL_POST"  => NULL, # DELETE containers/objects, POST objects
        );

        $this->_user_read_progress_callback_func = NULL;
        $this->_user_write_progress_callback_func = NULL;
        $this->_write_callback_type = NULL;
        $this->_text_list = array();
        $this->_account_container_count = 0;
        $this->_account_bytes_used = 0;
        $this->_container_object_count = 0;
        $this->_container_bytes_used = 0;
        $this->_obj_write_resource = NULL;
        $this->_obj_write_string = "";
        $this->_obj_etag = NULL;
        $this->_obj_last_modified = NULL;
        $this->_obj_content_type = NULL;
        $this->_obj_content_length = NULL;
        $this->_obj_metadata = array();
        $this->_cdn_enabled = NULL;
        $this->_cdn_uri = NULL;
        $this->_cdn_ttl = NULL;
    }

    function ssl_use_cabundle($path=NULL)
    {
        if ($path) {
            $this->cabundle_path = $path;
        } else {
            $this->cabundle_path = dirname(__FILE__) . "/share/cacert.pem";
        }
        if (!file_exists($this->cabundle_path)) {
            throw new IOException("Could not use CA bundle: "
                . $this->cabundle_path);
        }
        return;
    }

    private function _set_connections_cainfo()
    {
        if ($this->cabundle_path) {
            foreach ($this->connections as $k => $v) {
                if (!is_null($v)) {
                    curl_setopt($this->connections[$k], CURLOPT_SSL_VERIFYPEER,
                        True);
                    curl_setopt($this->connections[$k], CURLOPT_CAINFO,
                        $this->cabundle_path);
                }
            }
        }
        return;
    }

    # Uses separate cURL connection to authenticate
    #
    function authenticate($user, $pass, $acct=NULL, $host=NULL)
    {
        $path = array();
        if (isset($acct) || isset($host)) {
            $headers = array(
                sprintf("%s: %s", AUTH_USER_HEADER_LEGACY, $user),
                sprintf("%s: %s", AUTH_KEY_HEADER_LEGACY, $pass),
                );
            $path[] = $host;
            $path[] = rawurlencode(sprintf("v%d",$this->api_version));
            $path[] = rawurlencode($acct);
        } else {
            $headers = array(
                sprintf("%s: %s", AUTH_USER_HEADER, $user),
                sprintf("%s: %s", AUTH_KEY_HEADER, $pass),
                );
            $path[] = "https://api.mosso.com";
        }
        $path[] = "auth";
        $url = implode("/", $path);

        $curl_ch = curl_init();
        if ($this->cabundle_path) {
            curl_setopt($curl_ch, CURLOPT_SSL_VERIFYPEER, True);
            curl_setopt($curl_ch, CURLOPT_CAINFO,
                $this->cabundle_path);
        }
        curl_setopt($curl_ch, CURLOPT_VERBOSE, $this->dbug);
        curl_setopt($curl_ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl_ch, CURLOPT_MAXREDIRS, 4);
        curl_setopt($curl_ch, CURLOPT_HEADER, 0);
        curl_setopt($curl_ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl_ch, CURLOPT_USERAGENT, USER_AGENT);
        curl_setopt($curl_ch, CURLOPT_HEADERFUNCTION,array(&$this,'_auth_hdr_cb'));
        curl_setopt($curl_ch, CURLOPT_URL, $url);
        curl_exec($curl_ch);
        curl_close($curl_ch);

        return array($this->response_status, $this->response_reason,
            $this->storage_url, $this->cdnm_url, $this->auth_token);
    }

    # (CDN) GET /v1/Account
    #
    function list_cdn_containers()
    {
        $conn_type = "GET_CALL";
        $url_path = $this->_make_path("CDN", $container_name);

        $this->_write_callback_type = "TEXT_LIST";
        $return_code = $this->_send_request($conn_type, $url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            array(0,$this->error_str,array());
        }
        if ($return_code == 401) {
            return array($return_code,"Unauthorized",array());
        }
        if ($return_code == 404) {
            return array($return_code,"Account not found.",array());
        }
        if ($return_code == 204) {
            return array($return_code,"Account has no CDN enabled Containers.",
                array());
        }
        if ($return_code == 200) {
            return array($return_code,$this->response_reason,$this->_text_list);
        }
        $this->error_str = "Unexpected HTTP response: ".$this->response_reason;
        return array($return_code,$this->error_str,array());
    }

    # (CDN) POST /v1/Account/Container
    #
    function update_cdn_container($container_name, $ttl=86400)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }
        $url_path = $this->_make_path("CDN", $container_name);
        $hdrs = array(
            CDN_ENABLED => "True",
            CDN_TTL => $ttl,
            );
        $return_code = $this->_send_request("DEL_POST",$url_path,$hdrs,"POST");
        if ($return_code == 401) {
            $this->error_str = "Unauthorized";
            return array($return_code, $this->error_str, NULL);
        }
        if ($return_code == 404) {
            $this->error_str = "Container not found.";
            return array($return_code, $this->error_str, NULL);
        }
        if ($return_code != 202) {
            $this->error_str="Unexpected HTTP response: ".$this->response_reason;
            return array($return_code, $this->error_str, NULL);
        }
        return array($return_code, "Accepted", $this->_cdn_uri);

    }

    # (CDN) PUT /v1/Account/Container
    #
    function add_cdn_container($container_name, $ttl=86400)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }
        $url_path = $this->_make_path("CDN", $container_name);
        $hdrs = array(
            CDN_ENABLED => "True",
            CDN_TTL => $ttl,
            );
        $return_code = $this->_send_request("PUT_CONT", $url_path, $hdrs);
        if ($return_code == 401) {
            $this->error_str = "Unauthorized";
            return array($return_code,$this->response_reason,False);
        }
        if (!in_array($return_code, array(201,202))) {
            $this->error_str="Unexpected HTTP response: ".$this->response_reason;
            return array($return_code,$this->response_reason,False);
        }
        return array($return_code,$this->response_reason,$this->_cdn_uri);
    }

    # (CDN) POST /v1/Account/Container
    #
    function remove_cdn_container($container_name)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }
        $url_path = $this->_make_path("CDN", $container_name);
        $hdrs = array(CDN_ENABLED => "False");
        $return_code = $this->_send_request("DEL_POST",$url_path,$hdrs,"POST");
        if ($return_code == 401) {
            $this->error_str = "Unauthorized";
            return array($return_code, $this->error_str);
        }
        if ($return_code == 404) {
            $this->error_str = "Container not found.";
            return array($return_code, $this->error_str);
        }
        if ($return_code != 202) {
            $this->error_str="Unexpected HTTP response: ".$this->response_reason;
            return array($return_code, $this->error_str);
        }
        return array($return_code, "Accepted");
    }

    # (CDN) HEAD /v1/Account
    #
    function head_cdn_container($container_name)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }
        $conn_type = "HEAD";
        $url_path = $this->_make_path("CDN", $container_name);
        $return_code = $this->_send_request($conn_type, $url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            array(0,$this->error_str,NULL,NULL,NULL);
        }
        if ($return_code == 401) {
            return array($return_code,"Unauthorized",NULL,NULL,NULL);
        }
        if ($return_code == 404) {
            return array($return_code,"Account not found.",NULL,NULL,NULL);
        }
        if ($return_code == 204) {
            return array($return_code,$this->response_reason,
                $this->_cdn_enabled, $this->_cdn_uri, $this->_cdn_ttl);
        }
        return array($return_code,$this->response_reason,NULL,NULL,NULL);
    }

    # GET /v1/Account
    #
    function list_containers($limit=0, $marker=NULL)
    {
        $conn_type = "GET_CALL";
        $url_path = $this->_make_path();

        $limit = intval($limit);
        $params = array();
        if ($limit > 0) {
            $params[] = "limit=$limit";
        }
        if ($marker) {
            $params[] = "marker=".rawurlencode($marker);
        }
        if (!empty($params)) {
            $url_path .= "?" . implode("&", $params);
        }

        $this->_write_callback_type = "TEXT_LIST";
        $return_code = $this->_send_request($conn_type, $url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain valid HTTP response.";
            return array(0,$this->error_str,array());
        }
        if ($return_code == 204) {
            return array($return_code, "Account has no containers.", array());
        }
        if ($return_code == 404) {
            $this->error_str = "Invalid account name for authentication token.";
            return array($return_code,$this->error_str,array());
        }
        if ($return_code == 200) {
            return array($return_code, $this->response_reason, $this->_text_list);
        }
        $this->error_str = "Unexpected HTTP response: ".$this->response_reason;
        return array($return_code,$this->error_str,array());
    }

    # GET /v1/Account?format=json
    #
    function list_containers_info($limit=0, $marker=NULL)
    {
        $conn_type = "GET_CALL";
        $url_path = $this->_make_path() . "?format=json";

        $limit = intval($limit);
        $params = array();
        if ($limit > 0) {
            $params[] = "limit=$limit";
        }
        if ($marker) {
            $params[] = "marker=".rawurlencode($marker);
        }
        if (!empty($params)) {
            $url_path .= "&" . implode("&", $params);
        }

        $this->_write_callback_type = "OBJECT_STRING";
        $return_code = $this->_send_request($conn_type, $url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain valid HTTP response.";
            return array(0,$this->error_str,array());
        }
        if ($return_code == 204) {
            return array($return_code, "Account has no containers.", array());
        }
        if ($return_code == 404) {
            $this->error_str = "Invalid account name for authentication token.";
            return array($return_code,$this->error_str,array());
        }
        if ($return_code == 200) {
            $json_body = json_decode($this->_obj_write_string, True);
            return array($return_code, $this->response_reason, $json_body);
        }
        $this->error_str = "Unexpected HTTP response: ".$this->response_reason;
        return array($return_code,$this->error_str,array());
    }

    # HEAD /v1/Account
    #
    function head_account()
    {
        $conn_type = "HEAD";

        $url_path = $this->_make_path();
        $return_code = $this->_send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            array(0,$this->error_str,0,0);
        }
        if ($return_code == 404) {
            return array($return_code,"Account not found.",0,0);
        }
        if ($return_code == 204) {
            return array($return_code,$this->response_reason,
                $this->_account_container_count, $this->_account_bytes_used);
        }
        return array($return_code,$this->response_reason,0,0);
    }

    # PUT /v1/Account/Container
    #
    function create_container($container_name)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }

        $url_path = $this->_make_path("STORAGE", $container_name);
        $return_code = $this->_send_request("PUT_CONT",$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        return $return_code;
    }

    # DELETE /v1/Account/Container
    #
    function delete_container($container_name)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }

        $url_path = $this->_make_path("STORAGE", $container_name);
        $return_code = $this->_send_request("DEL_POST",$url_path,array(),"DELETE");

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
        }
        if ($return_code == 409) {
            $this->error_str = "Container must be empty prior to removing it.";
        }
        if ($return_code == 404) {
            $this->error_str = "Specified container did not exist to delete.";
        }
        if ($return_code != 204) {
            $this->error_str = "Unexpected HTTP return code: $return_code.";
        }
        return $return_code;
    }

    # GET /v1/Account/Container
    #
    function list_objects($cname,$limit=0,$marker=NULL,$prefix=NULL,$path=NULL)
    {
        if (!$cname) {
            $this->error_str = "Container name not set.";
            return array(0, $this->error_str, array());
        }

        $url_path = $this->_make_path("STORAGE", $cname);

        $limit = intval($limit);
        $params = array();
        if ($limit > 0) {
            $params[] = "limit=$limit";
        }
        if ($marker) {
            $params[] = "marker=".rawurlencode($marker);
        }
        if ($prefix) {
            $params[] = "prefix=".rawurlencode($prefix);
        }
        if ($path) {
            $params[] = "path=".rawurlencode($path);
        }
        if (!empty($params)) {
            $url_path .= "?" . implode("&", $params);
        }
 
        $conn_type = "GET_CALL";
        $this->_write_callback_type = "TEXT_LIST";
        $return_code = $this->_send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array(0,$this->error_str,array());
        }
        if ($return_code == 204) {
            $this->error_str = "Container has no Objects.";
            return array($return_code,$this->error_str,array());
        }
        if ($return_code == 404) {
            $this->error_str = "Container has no Objects.";
            return array($return_code,$this->error_str,array());
        }
        if ($return_code == 200) {
            return array($return_code,$this->response_reason, $this->_text_list);
        }
        $this->error_str = "Unexpected HTTP response code: $return_code";
        return array(0,$this->error_str,array());
    }

    # GET /v1/Account/Container?format=json
    #
    function get_objects($cname,$limit=0,$marker=NULL,$prefix=NULL,$path=NULL)
    {
        if (!$cname) {
            $this->error_str = "Container name not set.";
            return array(0, $this->error_str, array());
        }

        $url_path = $this->_make_path("STORAGE", $cname);

        $limit = intval($limit);
        $params = array();
        $params[] = "format=json";
        if ($limit > 0) {
            $params[] = "limit=$limit";
        }
        if ($marker) {
            $params[] = "marker=".rawurlencode($marker);
        }
        if ($prefix) {
            $params[] = "prefix=".rawurlencode($prefix);
        }
        if ($path) {
            $params[] = "path=".rawurlencode($path);
        }
        if (!empty($params)) {
            $url_path .= "?" . implode("&", $params);
        }
 
        $conn_type = "GET_CALL";
        $this->_write_callback_type = "OBJECT_STRING";
        $return_code = $this->_send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array(0,$this->error_str,array());
        }
        if ($return_code == 204) {
            $this->error_str = "Container has no Objects.";
            return array($return_code,$this->error_str,array());
        }
        if ($return_code == 404) {
            $this->error_str = "Container has no Objects.";
            return array($return_code,$this->error_str,array());
        }
        if ($return_code == 200) {
            $json_body = json_decode($this->_obj_write_string, True);
            return array($return_code,$this->response_reason, $json_body);
        }
        $this->error_str = "Unexpected HTTP response code: $return_code";
        return array(0,$this->error_str,array());
    }


    # HEAD /v1/Account/Container
    #
    function head_container($container_name)
    {
        if (!$container_name) {
            $this->error_str = "Container name not set.";
            return False;
        }

        $conn_type = "HEAD";

        $url_path = $this->_make_path("STORAGE", $container_name);
        $return_code = $this->_send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            array(0,$this->error_str,0,0);
        }
        if ($return_code == 404) {
            return array($return_code,"Container not found.",0,0);
        }
        if ($return_code == 204) {
            return array($return_code,$this->response_reason,
                $this->_container_object_count, $this->_container_bytes_used);
        }
        return array($return_code,$this->response_reason,0,0);
    }

    # GET /v1/Account/Container/Object
    #
    function get_object_to_string(&$obj, $hdrs=array())
    {
        if (!is_object($obj) || get_class($obj) != "CF_Object") {
            throw new SyntaxException(
                "Method argument is not a valid CF_Object.");
        }

        $conn_type = "GET_CALL";

        $url_path = $this->_make_path("STORAGE", $obj->container->name,$obj->name);
        $this->_write_callback_type = "OBJECT_STRING";
        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array($return_code0,$this->error_str,NULL);
        }
        if ($return_code == 404) {
            $this->error_str = "Object not found.";
            return array($return_code0,$this->error_str,NULL);
        }
        if (($return_code < 200) || ($return_code > 299
                && $return_code != 412 && $return_code != 304)) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
            return array($return_code,$this->error_str,NULL);
        }
        return array($return_code,$this->response_reason, $this->_obj_write_string);
    }

    # GET /v1/Account/Container/Object
    #
    function get_object_to_stream(&$obj, &$resource=NULL, $hdrs=array())
    {
        if (!is_object($obj) || get_class($obj) != "CF_Object") {
            throw new SyntaxException(
                "Method argument is not a valid CF_Object.");
        }
        if (!is_resource($resource)) {
            throw new SyntaxException(
                "Resource argument not a valid PHP resource.");
        }

        $conn_type = "GET_CALL";

        $url_path = $this->_make_path("STORAGE", $obj->container->name,$obj->name);
        $this->_obj_write_resource = $resource;
        $this->_write_callback_type = "OBJECT_STREAM";
        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array($return_code,$this->error_str);
        }
        if ($return_code == 404) {
            $this->error_str = "Object not found.";
            return array($return_code,$this->error_str);
        }
        if (($return_code < 200) || ($return_code > 299
                && $return_code != 412 && $return_code != 304)) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
            return array($return_code,$this->error_str);
        }
        return array($return_code,$this->response_reason);
    }

    # PUT /v1/Account/Container/Object
    #
    function put_object(&$obj, &$fp)
    {
        if (!is_object($obj) || get_class($obj) != "CF_Object") {
            throw new SyntaxException(
                "Method argument is not a valid CF_Object.");
        }
        if (!is_resource($fp)) {
            throw new SyntaxException(
                "File pointer argument is not a valid resource.");
        }

        $conn_type = "PUT_OBJ";
        $url_path = $this->_make_path("STORAGE", $obj->container->name,$obj->name);

        $hdrs = $this->_metadata_headers($obj);
        if (isset($etag)) {
            $hdrs[] = "ETag: " . $etag;
        }
        if (!$obj->content_type) {
            $hdrs[] = "Content-Type: application/octet-stream";
        } else {
            $hdrs[] = "Content-Type: " . $obj->content_type;
        }

        $this->_init($conn_type);
        curl_setopt($this->connections[$conn_type],
                CURLOPT_INFILE, $fp);
        if (!$obj->content_length) {
            # We don't know the Content-Length, so assumed "chunked" PUT
            #
            curl_setopt($this->connections[$conn_type], CURLOPT_UPLOAD, True);
            $hdrs[] = 'Transfer-Encoding: chunked';
        } else {
            # We know the Content-Length, so use regular transfer
            #
            curl_setopt($this->connections[$conn_type],
                    CURLOPT_INFILESIZE, $obj->content_length);
        }

        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array(0,$this->error_str,NULL);
        }
        if ($return_code == 412) {
            $this->error_str = "Missing Content-Type header";
            return array(0,$this->error_str,NULL);
        }
        if ($return_code == 422) {
            $this->error_str = "Derived and computed checksums do not match.";
            return array(0,$this->error_str,NULL);
        }
        if ($return_code != 201) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
            return array(0,$this->error_str,NULL);
        }
        return array($return_code,$this->response_reason,$this->_obj_etag);
    }

    # POST /v1/Account/Container/Object
    #
    function update_object(&$obj)
    {
        if (!is_object($obj) || get_class($obj) != "CF_Object") {
            throw new SyntaxException(
                "Method argument is not a valid CF_Object.");
        }

        if (!is_array($obj->metadata) || empty($obj->metadata)) {
            $this->error_str = "Metadata array is empty.";
            return 0;
        }

        $url_path = $this->_make_path("STORAGE", $obj->container->name,$obj->name);

        $hdrs = $this->_metadata_headers($obj);
        $return_code = $this->_send_request("DEL_POST",$url_path,$hdrs,"POST");
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return 0;
        }
        if ($return_code == 404) {
            $this->error_str = "Account, Container, or Object not found.";
        }
        if ($return_code != 202) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
        }
        return $return_code;
    }

    # HEAD /v1/Account/Container/Object
    #
    function head_object(&$obj)
    {
        if (!is_object($obj) || get_class($obj) != "CF_Object") {
            throw new SyntaxException(
                "Method argument is not a valid CF_Object.");
        }

        $conn_type = "HEAD";

        $url_path = $this->_make_path("STORAGE", $obj->container->name,$obj->name);
        $return_code = $this->_send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array(0, $this->error_str." ".$this->response_reason,
                NULL, NULL, NULL, NULL, array());
        }

        if ($return_code == 404) {
            return array($return_code, $this->response_reason,
                NULL, NULL, NULL, NULL, array());
        }
        if ($return_code == 204) {
            return array($return_code,$this->response_reason,
                $this->_obj_etag,
                $this->_obj_last_modified,
                $this->_obj_content_type,
                $this->_obj_content_length,
                $this->_obj_metadata);
        }
        $this->error_str = "Unexpected HTTP return code: $return_code";
        return array($return_code, $this->error_str." ".$this->response_reason,
                NULL, NULL, NULL, NULL, array());
    }

    # DELETE /v1/Account/Container/Object
    #
    function delete_object($container_name, $object_name)
    {
        if (!$container_name || !$object_name) {
            $this->error_str = "Container or Object name not set.";
            return 0;
        }

        $url_path = $this->_make_path("STORAGE", $container_name,$object_name);
        $return_code = $this->_send_request("DEL_POST",$url_path,NULL,"DELETE");
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return 0;
        }
        if ($return_code == 404) {
            $this->error_str = "Specified container did not exist to delete.";
        }
        if ($return_code != 204) {
            $this->error_str = "Unexpected HTTP return code: $return_code.";
        }
        return $return_code;
    }

    function get_error()
    {
        return $this->error_str;
    }

    function setDebug($bool)
    {
        $this->dbug = $bool;
        foreach ($this->connections as $k => $v) {
            if (!is_null($v)) {
                curl_setopt($this->connections[$k], CURLOPT_VERBOSE, $this->dbug);
            }
        }
    }

    function getCDNMUrl()
    {
        return $this->cdnm_url;
    }

    function getStorageUrl()
    {
        return $this->storage_url;
    }

    function getAuthToken()
    {
        return $this->auth_token;
    }

    function setCFAuth($cfs_auth)
    {
        $this->storage_url = $cfs_auth->storage_url;
        $this->auth_token = $cfs_auth->auth_token;
        $this->cdnm_url = $cfs_auth->cdnm_url;
    }

    function setReadProgressFunc($func_name)
    {
        $this->_user_read_progress_callback_func = $func_name;
    }

    function setWriteProgressFunc($func_name)
    {
        $this->_user_write_progress_callback_func = $func_name;
    }

    private function _header_cb($ch, $header)
    {
        preg_match("/^HTTP\/1\.[01] (\d{3}) (.*)/", $header, $matches);
        if (isset($matches[1])) {
            $this->response_status = $matches[1];
        }
        if (isset($matches[2])) {
            $this->response_reason = $matches[2];
        }
        if (stripos($header, CDN_ENABLED) === 0) {
            $val = trim(substr($header, strlen(CDN_ENABLED)+1));
            if (strtolower($val) == "true") {
                $this->_cdn_enabled = True;
            } elseif (strtolower($val) == "false") {
                $this->_cdn_enabled = False;
            } else {
                $this->_cdn_enabled = NULL;
            }
            return strlen($header);
        }
        if (stripos($header, CDN_URI) === 0) {
            $this->_cdn_uri = trim(substr($header, strlen(CDN_URI)+1));
            return strlen($header);
        }
        if (stripos($header, CDN_TTL) === 0) {
            $this->_cdn_ttl = trim(substr($header, strlen(CDN_TTL)+1))+0;
            return strlen($header);
        }
        if (stripos($header, ACCOUNT_CONTAINER_COUNT) === 0) {
            $this->_account_container_count = trim(substr($header,
                    strlen(ACCOUNT_CONTAINER_COUNT)+1))+0;
            return strlen($header);
        }
        if (stripos($header, ACCOUNT_BYTES_USED) === 0) {
            $this->_account_bytes_used = trim(substr($header,
                    strlen(ACCOUNT_BYTES_USED)+1))+0;
            return strlen($header);
        }
        if (stripos($header, CONTAINER_OBJ_COUNT) === 0) {
            $this->_container_object_count = trim(substr($header,
                    strlen(CONTAINER_OBJ_COUNT)+1))+0;
            return strlen($header);
        }
        if (stripos($header, CONTAINER_BYTES_USED) === 0) {
            $this->_container_bytes_used = trim(substr($header,
                    strlen(CONTAINER_BYTES_USED)+1))+0;
            return strlen($header);
        }
        if (stripos($header, METADATA_HEADER) === 0) {
            $temp = substr($header, strlen(METADATA_HEADER));
            $parts = explode(":", $temp);
            $this->_obj_metadata[strtolower($parts[0])] = trim($parts[1]);
            return strlen($header);
        }
        if (stripos($header, "ETag") === 0) {
            $parts = explode(":", $header);
            $this->_obj_etag = trim($parts[1]);
            return strlen($header);
        }
        if (stripos($header, "Last-Modified") === 0) {
            $parts = explode(":", $header);
            $this->_obj_last_modified = trim($parts[1]);
            return strlen($header);
        }
        if (stripos($header, "Content-Type") === 0) {
            $parts = explode(":", $header);
            $this->_obj_content_type = trim($parts[1]);
            return strlen($header);
        }
        if (stripos($header, "Content-Length") === 0) {
            $parts = explode(":", $header);
            $this->_obj_content_length = trim($parts[1])+0;
            return strlen($header);
        }
        return strlen($header);
    }

    private function _read_cb($ch, $fd, $length)
    {
        $data = fread($fd, $length);
        $len = strlen($data);
        if (isset($this->_user_write_progress_callback_func)) {
            call_user_func($this->_user_write_progress_callback_func, $len);
        }
        return $data;
    }

    private function _write_cb($ch, $data)
    {
        $amount = strlen($data);
        switch ($this->_write_callback_type) {
        case "TEXT_LIST":
            $this->_text_list[] = rtrim($data, "\r\n\0\x0B"); # keep tab,space
            break;
        case "OBJECT_STREAM":
            $written = 0;
            while ($written < strlen($data)) {
                $written += fwrite($this->_obj_write_resource, $data-$written);
            }
            if ($written > strlen($data)) {
                throw new IOException(
                    "Wrote more data to client than we should have?!");
            }
            break;
        case "OBJECT_STRING":
            $this->_obj_write_string .= $data;
            break;
        }
        if (isset($this->_user_read_progress_callback_func)) {
            call_user_func($this->_user_read_progress_callback_func,strlen($data));
        }
        return strlen($data);
    }

    private function _auth_hdr_cb($ch, $header)
    {
        preg_match("/^HTTP\/1\.[01] (\d{3}) (.*)/", $header, $matches);
        if (isset($matches[1])) {
            $this->response_status = $matches[1];
        }
        if (isset($matches[2])) {
            $this->response_reason = $matches[2];
        }
        if (stripos($header, STORAGE_URL) === 0) {
            $this->storage_url = trim(substr($header, strlen(STORAGE_URL)+1));
        }
        if (stripos($header, CDNM_URL) === 0) {
            $this->cdnm_url = trim(substr($header, strlen(CDNM_URL)+1));
        }
        if (stripos($header, AUTH_TOKEN) === 0) {
            $this->auth_token = trim(substr($header, strlen(AUTH_TOKEN)+1));
        }
        if (stripos($header, AUTH_TOKEN_LEGACY) === 0) {
            $this->auth_token = trim(substr($header,strlen(AUTH_TOKEN_LEGACY)+1));
        }
        return strlen($header);
    }

    private function _make_headers($hdrs=NULL)
    {
        $new_headers = array();
        $has_stoken = False;
        $has_uagent = False;
        if (is_array($hdrs)) {
            foreach ($hdrs as $h => $v) {
                if (is_int($h)) {
                    $parts = explode(":", $v);
                    $header = $parts[0];
                    $value = trim($parts[1]);
                } else {
                    $header = $h;
                    $value = trim($v);
                }

                if (stripos($header, AUTH_TOKEN) === 0) {
                    $has_stoken = True;
                }
                if (stripos($header, "user-agent") === 0) {
                    $has_uagent = True;
                }
                $new_headers[] = $header . ": " . $value;
            }
        }
        if (!$has_stoken) {
            $new_headers[] = AUTH_TOKEN . ": " . $this->auth_token;
        }
        if (!$has_uagent) {
            $new_headers[] = "User-Agent: " . USER_AGENT;
        }
        return $new_headers;
    }

    private function _init($conn_type, $force_new=False)
    {
        if (!array_key_exists($conn_type, $this->connections)) {
            $this->error_str = "Invalid CURL_XXX connection type";
            return False;
        }

        if (is_null($this->connections[$conn_type]) || $force_new) {
            $ch = curl_init();
        } else {
            return;
        }

        $this->_set_connections_cainfo();

        if ($this->dbug) { curl_setopt($ch, CURLOPT_VERBOSE, 1); }

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(&$this, '_header_cb'));

        if ($conn_type == "GET_CALL") {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, array(&$this, '_write_cb'));
        }

        if ($conn_type == "PUT_OBJ") {
            curl_setopt($ch, CURLOPT_PUT, 1);
            curl_setopt($ch, CURLOPT_READFUNCTION, array(&$this, '_read_cb'));
        }
        if ($conn_type == "HEAD") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "HEAD");
            curl_setopt($ch, CURLOPT_NOBODY, 1);
        }
        if ($conn_type == "PUT_CONT") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_INFILESIZE, 0);
        }
        if ($conn_type == "DEL_POST") {
            curl_setopt($ch, CURLOPT_NOBODY, 1);
        }
        $this->connections[$conn_type] = $ch;
        return;
    }

    private function _reset_callback_vars()
    {
        $this->_text_list = array();
        $this->_account_container_count = 0;
        $this->_account_bytes_used = 0;
        $this->_container_object_count = 0;
        $this->_container_bytes_used = 0;
        $this->_obj_etag = NULL;
        $this->_obj_last_modified = NULL;
        $this->_obj_content_type = NULL;
        $this->_obj_content_length = NULL;
        $this->_obj_metadata = array();
        $this->_obj_write_string = "";
        $this->_cdn_enabled = NULL;
        $this->_cdn_uri = NULL;
        $this->_cdn_ttl = NULL;
        $this->response_status = 0;
        $this->response_reason = "";
    }

    private function _make_path($t="STORAGE",$c=NULL,$o=NULL)
    {
        $path = array();
        switch ($t) {
        case "STORAGE":
            $path[] = $this->storage_url; break;
        case "CDN":
            $path[] = $this->cdnm_url; break;
        }
        if ($c) {
            $path[] = rawurlencode($c);
        }
        if ($o) {
            # mimic Python's urllib.quote() feature of a "safe" '/' character
            #
            $path[] = str_replace("%2F","/",rawurlencode($o));
        }
        return implode("/",$path);
    }

    private function _metadata_headers(&$obj)
    {
        $hdrs = array();
        foreach ($obj->metadata as $k => $v) {
            if (strpos($k,":") !== False || strpos($v,":") !== False) {
                throw new SyntaxException(
                    "Metadata cannot contain a ':' character.");
            }
            $k = strtolower(trim($k));
            $key = sprintf("%s%s", METADATA_HEADER, $k);
            if (!array_key_exists($key, $hdrs)) {
                if (strlen($k) > 128 || strlen($v) > 256) {
                    $this->error_str = "Metadata key or value exceeds ";
                    $this->error_str .= "maximum length: ($k: $v)";
                    return 0;
                }
                $hdrs[] = sprintf("%s%s: %s", METADATA_HEADER, $k, trim($v));
            }
        }
        return $hdrs;
    }

    private function _send_request($conn_type, $url_path, $hdrs=NULL, $method="GET")
    {
        $this->_init($conn_type);
        $this->_reset_callback_vars();

        $headers = $this->_make_headers($hdrs);

        switch ($method) {
        case "DELETE":
            curl_setopt($this->connections[$conn_type],
                CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
        case "POST":
            curl_setopt($this->connections[$conn_type],
                CURLOPT_CUSTOMREQUEST, "POST");
        default:
            break;
        }

        curl_setopt($this->connections[$conn_type],
            CURLOPT_HTTPHEADER, $headers);

        curl_setopt($this->connections[$conn_type],
            CURLOPT_URL, $url_path);

        if (!curl_exec($this->connections[$conn_type])) {
            $this->error_str = "(curl error: "
                . curl_errno($this->connections[$conn_type]) . ") ";
            $this->error_str .= curl_error($this->connections[$conn_type]);
            return False;
        }
        return curl_getinfo($this->connections[$conn_type], CURLINFO_HTTP_CODE);
    }
}

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
