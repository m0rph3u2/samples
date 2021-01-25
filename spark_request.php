<?php

class Spark {

    public function apache_spark_request($posts, $debug=false)
    {
        foreach($this->container->getParameter('reporting')['apache_spark_credentials'] as $key => $val){
            $posts[$key] = $val;
        };

        // DEBUGGING INFO
        if(isset($posts['query'])){
            $posts['query'] .= isset($_SERVER['PHP_ENV']) ? "\n--{$_SERVER['PHP_ENV']}" : "";
            $posts['query'] .= isset($_SERVER['REQUEST_URI']) ? "\n--{$_SERVER['REQUEST_URI']}" : "";
        };

        $csv_string = \Tools\Helpers::curl(SELF::API_URL, $posts, $debug);

		$response_code = \Tools\Helpers::$curl_info['http_code'];
		if($response_code!==200){
			$error_details = stripos($csv_string,'Job aborted')!==false ? $csv_string : 'Possible wrong query.';
			return [
				'error'=> "Database error. HTTP Code $response_code.",
				'error_details'=> \Tools\Helpers::showDebugInfo()?$csv_string:$error_details,
				'debug_info'=> \Tools\Helpers::showDebugInfo()?"\nQUERY: ".$posts['query']:'',
				'data'=>[],
			];
		};

		$this->modelDbAdserver->ping();
		\Tools\Helpers::$curl_info = '';
        return $csv_string;
    }
    
}
