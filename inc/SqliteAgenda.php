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

require_once "agenda.php";
use Monolog\Logger;
use Sabre\VObject;


class SqliteAgenda extends Agenda {
    private $path;

    public function __construct(ICalDAVClient $caldav_client, object $api, array $agenda_args, DateTimeImmutable $now) {
        $this->path = $agenda_args["path"];
        parent::__construct($agenda_args["db_table_prefix"], new Logger('sqliteAgenda'), $caldav_client, $api, $now);
    }
    
    protected function openDB() {
        try{
            $pdo = new PDO("sqlite:$this->path");
            $pdo->exec("PRAGMA foreign_keys = ON;"); // needed for ON DELETE CASCADE
            return $pdo;
        } catch(Exception $e) {
            echo "Can't reach SQLite database: ".$e->getMessage();
            die(1);
        }
    }

    protected function insertIgnorePrefix() {
        return "INSERT OR IGNORE";
    }

    protected function defaultCharsetSqlString() {
        return "";
    }

    protected function autoIncrementSqlString() {
        return "AUTOINCREMENT";
    }
}
