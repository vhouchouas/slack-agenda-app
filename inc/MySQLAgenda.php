<?php
/*
Copyright (C) 2022 Zero Waste Paris

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

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
