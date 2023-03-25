<?php
namespace surreal\interfaces;

interface IWebService{

    public function open($endPoint);
    public function close();
    public function setOptions($option, $value);
    public function setMethod($method);
    public function getResponse();

}

