<?php

class EasyCurl {

    //request variables
    private $url;
    private $http_method = 'GET';
    private $post_params = array();
    private $request_body = NULL; //In some cases a service may want an entire post body sent
    private $request = NULL; //The overall raw request that went through
    private $headers = array();
    private $curl_opts = array(); //Array that allows you to pass in custom curlopts
    private $default_curl_opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_FOLLOWLOCATION => true,
        CURLINFO_HEADER_OUT => true,
        CURLOPT_HEADER => true
    );
    private $final_curl_opts; //The final array of all options that were sent through with the request. Used to recreate the curl object.
    
    //Vars about the response
    private $response_body = NULL;
    private $response_headers = array();
    private $response_status_code = NULL;
    private $rate_limit_remaining = NULL;
    private $curl_info = NULL;
    private $file_info = NULL;
    
    //Internal variables
    private $vendor_id;
    private $log_id;
    private $data;


    private $download_dir = '/tmp';
    
    // cURL object
    private $ch;
    
    public function __construct($url=NULL,$client_key=NULL) {

        if($url) {
            $this->set_url($url);
        }
        
        if($client_key) {
            $this->set_client_key($client_key);
        } 
        
    }
    
    /**
     * Actually fire off the curl request and get a response back.
     */
    public function execute() {
        $this->prepare_request();
        return $this->send_request();
    }

    /**
     * Downloads a file at a given URL, returns array with specific info about the file.
     *
     * @access public
     * @return array
     */
    public function downloadFile($url=NULL, $chunked=true) {
        if($url) {
            $this->set_url($url);
        }
        
        if(!$this->url) {
            throw new Exception("No URL provided to download_file.");
        }

        $sanitized_url = trim(preg_replace('/\?.*/', '', $this->url));
        $extension = strtolower(array_pop(explode('.',$sanitized_url)));
        if(strstr($extension,':')) {
            $expl = explode(':',$extension);
            $extension = $expl[0];
        }
        
        $extension = ($extension && preg_match("#^[A-Za-z0-9]{1,3}$#", $extension)) ? $extension : 'tmp';
        $local_path = $this->download_dir . '/' . md5($url . uniqid()) . '.' . $extension;

        try {
            if ($chunked) {
                $res = $this->copyfile_chunked($this->url, $local_path);
            } else {
                $res = $this->copyfile_simple($this->url, $local_path);
            }
            
            $file_size = $res['file_size'];
            $content_type = trim($res['content_type']);
            
            if(!$file_size || !is_readable($local_path)) {
                throw new Exception("Could not download file.");
            }
            
            $this->file_info = array('path'=>$local_path,'content_type'=>$content_type,'file_size'=>$file_size);
            return $this->file_info;
        
        } catch(Exception $e) {
            throw new Exception("Error downloading file: " . $e->getMessage(), $e->getCode());
        }
    }
    
    
    /**
     * Chainable
     */

    public function setClientKey($client_key) {
        $this->client_key = $client_key;
        return $this;
    }
    
    public function getClientKey() {
        return $this->client_key;
    }
    

    /**
     * Chainable
     */
    public function setUrl($url) {
        $this->url = $url;
        return $this;
    }

    public function getUrl() {
        return $this->url;
    }
    
    /**
     * Chainable
     */
    public function setHttpMethod($http_method) {
        $http_method = strtoupper($http_method);
        if(in_array($http_method,array('GET','POST','PUT','DELETE'))) {
            $this->http_method = $http_method;
        }
        return $this;
    }
    
    public function getHttpMethod() {
        return $this->http_method;
    }
    
    /**
     * Chainable
     */
    public function setPostParams($params) {
        if(!is_array($params)) {
            throw new Exception("Invalid params passed into setPostParams");
        }
        
        foreach($params as $k=>$v) {
            $this->post_params[$k] = $v;
        }
        
        return $this;
    }

    /**
     * Chainable
     * Takes an array of header strings - because headers aren't necessary k=>v pairs,
     * instead, just pass in an array of strings, each representing the full header
     * @param  String $body
     * @return Object 
     * 
     */
    public function setHeaders($headers) {
        if(!is_array($headers)) {
            throw new Exception("Invalid params passed into setHeaders");
        }
        
        foreach($headers as $v) {
            $this->headers[] = $v;
        }
        
        return $this;
    }
    
    public function getHeaders() {    
        return $this->headers;
    }
    
    /**
     * Chainable
     * This is useful in case an API requires you to pass in an entire request body (like JSON, etc)
     * In most cases, you won't need it and should instead just rely on post params
     * @param  String $body
     * @return Object 
     *
     */
    public function setRequestBody($body) {
        $this->request_body = $body;
        return $this;
    }

    public function getRequestBody() {
        return $this->request_body;
    }

    public function getCurlHandle() {
        return $this->ch;
    }
    
    public function getCurlInfo() {
        return $this->curl_info;
    }
    
    public function getResponseStatusCode() {
        return $this->response_status_code;
    }
    
    public function getResponseHeaders() {
        return $this->response_headers;
    }

    public function getResponseBody() {
        return $this->response_body;
    }
    
    public function getFileInfo() {
        return $this->file_info;
    }
    
    /**
     * Allows you to pass in custom CURL opts by k=>v
     * @param  String $option_name
     * @param  String option_value
     */
    public function setCurlOpt($option_name,$option_value) {
        if(empty($option_name) || is_null($option_value)) {
            throw new Exception("Invalid values passed into set_curl_opt");
        }
        
        if($option_name == CURLOPT_HTTPHEADER) {
            if(!is_array($option_value)) {
                $option_value = array($option_value);
            }
            $this->set_headers($option_value);
        } else {
            $this->curl_opts[$option_name] = $option_value;
        }
    
        return $this;
    }
    
    /**
     * Allows you to pass in an array of curl options. Like 
     *   array(
     *     'opt_name'=>'opt_value',
     *     'opt_name_2'=>'opt_value_2'
     *   )
     *
     * @param  Array $opts_array
     * @param  Object
     */
    public function setCurlOpts($opts_array) {
        if(!is_array($opts_array)) {
            throw new Exception("You must pass an array into setCurlOpts");
        }
        
        try {    
            foreach($opts_array as $opt_name=>$opt_value) {
                $this->setCurlOpt($opt_name,$opt_value);
            }
        } catch(Exception $e) {
            throw $e;
        }
        
        return $this;
    }

    public function getCurlOpts() {
        return $this->final_curl_opts;
    }

    /**
     * private internal function. Wraps curl_setopt, puts everything in there into an internal array which we will save
     * so we can recreate the ch object easily.
     * @param  String $name
     * @param  String $value
     * @return Array
     */
    private function setOpt($name,$value) {
        if(!is_resource($this->ch)) {
            throw new Exception("Cannot setOpt!");
        }
    
        curl_setopt($this->ch,$name,$value);
        $this->final_curl_opts[$name] = $value;
    }
    
    /**
     * Build up our curl object ($this->ch) to the point where it's ready to be fired off.
     */
    private function prepareRequest() {
        if(empty($this->url)) {
            throw new Exception("No URL provided!");
        }
    
        $this->ch = curl_init();
        $this->setopt(CURLOPT_URL,$this->url);
        $this->setopt(CURLOPT_CUSTOMREQUEST,$this->http_method);
    
        if($this->http_method != 'GET') {
            if($this->http_method == 'POST') {
                $this->setopt(CURLOPT_POST,1);
            }
            
            if($this->request_body) {
                $this->setopt(CURLOPT_POSTFIELDS,$this->request_body);
                $this->set_headers(array('Content-Length: ' . strlen($this->request_body)));
            } elseif(!empty($this->post_params)) {
                $post_items = array();
                foreach($this->post_params as $k=>$v) {
                    $post_items[] = urlencode($k) . '=' . urlencode($v);
                }
                
                $this->request_body = implode('&',$post_items);
                $this->setopt(CURLOPT_POSTFIELDS,$this->request_body);
                $this->set_headers(array('Content-Length: ' . strlen($this->request_body)));
            }
        }
        
        foreach($this->default_curl_opts as $k=>$v) {
            $this->setopt($k,$v);
        }
        
        foreach($this->curl_opts as $k=>$v) {
            $this->setopt($k,$v);
        }

        if(!empty($this->headers)) {
            $this->setopt(CURLOPT_HTTPHEADER,$this->headers);
        }
    }    
    
    /**
     * Fire in the hole! Send the request, return the value, but also keep track of status code, logging and other such data points.
     * @return string The result
     */
    private function sendRequest() {
        if(!is_resource($this->ch)) {
            throw new Exception("No valid CURL object.");
        }
        
        $this->response_body = curl_exec($this->ch);
        $this->curl_info = curl_getinfo($this->ch);
        $this->response_status_code = $this->curl_info['http_code'];

        $header_size = $this->curl_info['header_size'];
        $response_headers_str = substr($this->response_body, 0, $header_size);
        $this->response_headers = $this->parse_response_headers($response_headers_str);
        $this->response_body = substr($this->response_body, $header_size);
        
        $log_priority_level = ($this->response_status_code == '200' && !empty($this->response_body)) ? LOG_INFO : LOG_ERR;
        $log_message = ($this->response_status_code == '200' && !empty($this->response_body)) ? "API call success: " . $this->url : "API call failure: " . $this->url;
        

        return $this->response_body;
    }

    /**
     * Given a string of headers, explode on ": " to create array of header key value pairs
     * @param  String $response_headers_str
     * @return Array
     */
    private function parseResponseHeaders($response_headers_str) {
        $lines = explode("\n",$response_headers_str);
        $header_arr = array();
        foreach($lines as $line) {
           $kv = explode(': ', $line, 2);
           if(count($kv) < 2) {
                continue;
           }
           $header_arr[$kv[0]] = $kv[1];
        }
        return $header_arr;
    }

    
    /**
     * Just a simple wrapper to upload a file.
     * @param  String $remote_url
     * @return Array
     */
    public static function uploadFile($remote_url,$field_name,$local_path) {
        $post_fields = array();
        $post_fields[$field_name] = '@' . $local_path;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remote_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        
        $ret = curl_exec($ch);
        return $ret;
    }


}
