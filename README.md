# easy-curl-client
A PHP client and wrapper for native PHP cURL.

When working on several PHP projects without a framework, it is often the case that you will find the server-side codebase riddled with variations of:

```
    $ch = curl_init();  
 
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_HEADER, false); 
    curl_setopt($ch, CURLOPT_POST, count($post_data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);    
 
    $res=curl_exec($ch);

    curl_close($ch);
    
```

Enter: PHP easyCurl, which allows cURL to be done in an object oriented way, and use chainable, more readable and consistant, less repetitive code. For example:

```
    $curl_client = new EasyCurl();
    $res = $curl_client->setUrl($url)
          ->setHttpMethod('POST')
          ->setPostParams($post_data)
          ->execute();

```
