<?php

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
