<?php
namespace surreal\WebService;
require __DIR__."/../interfaces/IWebService.php";
use surreal\interfaces\IWebService;

class WebService implements IWebService{

    private $curl;
    private $surrealObject;

    public function __construct($connection){
        try{
            if(empty($connection))
            {
                throw new \Exception('SurrealDB is not defined');
            }
            $this->surrealObject = $connection;
        }catch(\Exception $e){
            var_dump($e->getMessage());
            return $e->getMessage();
        }
    }

    public function open($endPoint)
    {
        try{
            if(empty($endPoint))
            {
                throw new \Exception('EndPoint is not empty');
            }
            $modelConnection = $this->surrealObject;
            $protocol = "http";
            $useSSLTLS = "N";
            if($modelConnection->getIsSSL())
            {
                $useSSLTLS = "S";
                $protocol = "https";
            }
            $initialUrl = $protocol."://".$modelConnection->getHostname() . ':' . $modelConnection->getPort() . '/'. $endPoint;
            $curl = curl_init($initialUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);//Return response 
            //TLS/SSL 
            if($useSSLTLS == "S")
            {
                curl_setopt($curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_MAX_TLSv1_1); 
            }
            //Authentication
            curl_setopt($curl,CURLOPT_USERPWD, $modelConnection->getUsername().":".$modelConnection->getPassword());
            //Setings Namespaces, databases and accept response JSON
            curl_setopt($curl,CURLOPT_HTTPHEADER, array('Accept: application/json',
                                                        "Content-Type: text/plain",
                                                        "NS: ".$modelConnection->getNs(),
                                                        "DB: ".$modelConnection->getDb()));
            $this->curl = $curl;
        }catch(\Exception $e){
            var_dump($e->getMessage());
            return $e->getMessage();
        }
    }

    public function close()
    {
        try{
            if(empty($this->curl))
            {
                throw new \Exception('CURL is closed');
            }
            curl_close($this->curl);
            $this->curl = null;
            $this->surrealObject = null;
        }catch(\Exception $e){
            var_dump($e->getMessage());
            return $e->getMessage();
        }
    }

    public function getResponse()
    {
        $curl = $this->curl;
        $response = curl_exec($curl);
        return $response;
    }

    public function setMethod($method)
    {
        try{
            if(empty($method))
            {
                throw new \Exception('Method is required');
            }
            $curl = $this->curl;
            curl_setopt($curl,CURLOPT_CUSTOMREQUEST, $method) ;
            $this->curl = $curl;
        }catch(\Exception $e){
            var_dump($e->getMessage());
            return $e->getMessage();
        }
    }

    public function setOptions($option,$value)
    {
        try{
            if(empty($option))
            {
                throw new \Exception('Option is required');
            }

            if(empty($value))
            {
                throw new \Exception('Value is required');
            }

            $curl = $this->curl;
            curl_setopt($curl,$option, $value);
            $this->curl = $curl;
        }catch(\Exception $e){
            var_dump($e->getMessage());
            return $e->getMessage();
        }
    }

    
    
}