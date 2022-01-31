<?php

use Monolog\Logger;
use Sabre\VObject;

class MySQLAgenda extends Agenda {
    private $db_name;
    private $host;
    private $username;
    private $password;
    
    public function __construct(ICalDAVClient $caldav_client, object $api, array $agenda_args, DateTimeImmutable $now) {
            $this->db_name = $agenda_args["db_name"];
            $this->host = $agenda_args["db_host"];
            $this->username = $agenda_args["db_username"];
            $this->password = $agenda_args["db_password"];
            parent::__construct($agenda_args["db_table_prefix"], new Logger('MySQLAgenda'), $caldav_client, $api, $now);
        }
    
    protected function openDB() {
        try{
            return new PDO("mysql:host=$this->host;dbname=$this->db_name",
                           $this->username,
                           $this->password);
        } catch(Exception $e) {
            echo "Can't reach MySQL like database: ".$e->getMessage();
            die(1);
        }
    }

    protected function insertIgnorePrefix() {
        return "INSERT IGNORE";
    }

    protected function defaultCharsetSqlString() {
        return "DEFAULT CHARSET=utf8mb4";
    }

    protected function autoIncrementSqlString() {
        return "AUTO_INCREMENT";
    }
}
