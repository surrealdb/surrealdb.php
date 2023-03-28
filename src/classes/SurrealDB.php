<?php 
namespace surreal\classes;

require __DIR__."/../interfaces/ISurrealDB.php";
require __DIR__."/WebService.php";

use surreal\interfaces\ISurrealDB;
use surreal\WebService\WebService;
class  SurrealDB implements ISurrealDB
{
    private $hostname;
    private $port;
    private $username;
    private $password;
    private $db;
    private $ns;
    private $isSSH;
    
    public function getHostname()
    {
        return $this->hostname;
    }

    public function getPort()
    {
        return $this->port;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function getDb()
    {
        return $this->db;
    }

    public function getNs()
    {
        return $this->ns;
    }
    
    public function getIsSSH()
    {
        return $this->isSSH;
    }

    public function __construct($params)
    {
        try{
    
            if(!isset($params['hostname']))
            {
                throw new \Exception('Hostname is required');
            }

            if(!isset($params['port'])){
                throw new \Exception('Port is required');
            }

            if(!isset($params['username']))
            {
                throw new \Exception('Username is required');
            }

            if(!isset($params['password']))
            {
                throw new \Exception('Password is required');
            }

            if(!isset($params['db']))
            {
                throw new \Exception('Database is required');
            }

            if(!isset($params['ns']))
            {
                throw new \Exception('Namespace is required');
            }

            if(!isset($params['isSSH']))
            {
                throw new \Exception('isSSH is required');
            }

            $this->hostname = $params['hostname'];
            $this->port = $params['port'];
            $this->username = $params['username'];
            $this->password = $params['password'];
            $this->ns = $params['ns'];
            $this->db = $params['db'];
            $this->isSSH = $params['isSSH'];
        }catch(\Exception $e){
            var_dump($e->getMessage());
            return $e->getMessage();
        }
    }

    public function querySql($connection, $query)
    {
        try{
            $webService = new WebService($connection);
            //Open connection | CURL
            $webService->open("sql");
            //Method POSTS
            $webService->setMethod("POST");
            //Set DATA: query
            $ns = "USE NS ".$connection->getNs().";";
            $webService->setOptions(CURLOPT_POSTFIELDS,$ns.$query);
            //Recieve response
            $response = $webService->getResponse();
            $webService->close();
            if(empty($response))
            {
                throw new \Exception('Response CURL is empty, please try again.');
            }
            return json_encode($response);
        }catch(\Exception $e){
            var_dump($e->getMessage());
            return $e->getMessage();
        }
    }
    
    

}

?>